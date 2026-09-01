<?php
/**
 * JIMI Webhook System — Manutenção Preventiva v4.10.1
 * Endpoint: /manutencoes
 *
 * Duas abas: Manutenção (lembretes por métrica — odômetro/horas de
 * ignição/horímetro/data) e Documentos (vencimento de CNH/toxicológico do
 * motorista). Ver docs/PLANO_IMPLEMENTACAO_v4.10.md, item 3.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/maintenance.php';
require_login();

$db = Database::getInstance()->getConnection();
$user = get_jimi_user();
$customer_id = get_customer_id();
$is_admin = ($user['role'] ?? '') === 'admin';
$tab = ($_GET['tab'] ?? 'manutencao') === 'documentos' ? 'documentos' : 'manutencao';

/**
 * Normaliza a lista de e-mails do formulário (mesma regra de
 * config_notificacoes.php: até 3, sem duplicata, só endereço válido).
 *
 * @param string $raw Texto separado por vírgula, ponto-e-vírgula ou quebra
 * @returns array
 */
function parse_maintenance_emails(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $out[] = $p;
        }
    }
    return array_slice(array_values(array_unique($out)), 0, 3);
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_reminder') {
        $id = (int)($_POST['id'] ?? 0);
        require_permission('manutencoes', $id > 0 ? 'edit' : 'create');

        $name    = trim($_POST['name'] ?? '');
        $metric  = $_POST['metric'] ?? '';
        $metric  = array_key_exists($metric, MAINTENANCE_METRIC_LABELS) ? $metric : null;
        $imei    = trim($_POST['imei'] ?? '') ?: null;
        $driverId = (int)($_POST['driver_id'] ?? 0) ?: null;
        $intervalKm    = trim($_POST['interval_km'] ?? '') !== '' ? (float)$_POST['interval_km'] : null;
        $intervalHours = trim($_POST['interval_hours'] ?? '') !== '' ? (float)$_POST['interval_hours'] : null;
        $dueDate = trim($_POST['due_date'] ?? '') ?: null;
        $notifyBell  = !empty($_POST['notify_bell']) ? 1 : 0;
        $notifyEmail = !empty($_POST['notify_email']) ? 1 : 0;
        $emails = parse_maintenance_emails($_POST['emails'] ?? '');
        $isActive = !empty($_POST['is_active']) ? 1 : 0;

        $owner_id = resolve_owner_customer_id($_POST['customer_id'] ?? null, $is_admin, $customer_id);

        if ($name === '' || $metric === null) {
            $error = 'Preencha o nome e a métrica do lembrete.';
        } elseif (in_array($metric, ['odometro', 'horas_ignicao', 'horimetro'], true) && !$imei) {
            $error = 'Esta métrica precisa de um ativo (veículo) vinculado.';
        } elseif ($metric === 'data' && !$dueDate) {
            $error = 'Informe a data de vencimento.';
        } elseif ($notifyEmail && empty($emails)) {
            $error = 'Para notificar por e-mail, informe ao menos um endereço válido.';
        } elseif ($id === 0 && $owner_id === null) {
            $error = 'Selecione o cliente do lembrete. Sua sessão está sem cliente definido.';
        } else {
            $emailsJson = $emails ? json_encode($emails, JSON_UNESCAPED_UNICODE) : null;

            // Baseline do vencimento: `maintenance_reminder_progress()` exige
            // `last_done_km`/`last_done_hours` gravados — nunca os deriva do
            // valor ATUAL (senão o vencimento "persegue" o odômetro/horímetro
            // e o item nunca vence). Sem nenhum serviço anterior conhecido, a
            // criação do lembrete assume "serviço feito agora": o baseline é o
            // valor lido neste instante. Só grava quando ainda não existe um
            // (edição de item já em andamento não pode resetar o baseline).
            $lastDoneKm = null;
            $lastDoneHours = null;
            if ($id === 0) {
                if ($metric === 'odometro' && $imei) {
                    $lastDoneKm = latest_odometer($db, $imei);
                } elseif ($metric === 'horimetro' && $imei) {
                    $stmt = $db->prepare("SELECT engine_hours FROM devices WHERE imei = ?");
                    $stmt->execute([$imei]);
                    $v = $stmt->fetchColumn();
                    $lastDoneHours = ($v !== false && $v !== null) ? (float)$v : null;
                }
            }

            try {
                $auditAfter = ['name' => $name, 'metric' => $metric, 'imei' => $imei, 'interval_km' => $intervalKm,
                    'interval_hours' => $intervalHours, 'due_date' => $dueDate, 'is_active' => $isActive];
                if ($id > 0) {
                    $beforeSel = $db->prepare("SELECT name, metric, imei, interval_km, interval_hours, due_date, is_active FROM maintenance_reminders WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?'));
                    $beforeParams = $is_admin ? [$id] : [$id, $customer_id];
                    $beforeSel->execute($beforeParams);
                    $beforeRow = $beforeSel->fetch(PDO::FETCH_ASSOC) ?: null;

                    $sql = "UPDATE maintenance_reminders SET
                                name=?, metric=?, imei=?, driver_id=?, interval_km=?, interval_hours=?,
                                due_date=?, notify_bell=?, notify_email=?, emails=?, is_active=?
                            WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?');
                    $params = [$name, $metric, $imei, $driverId, $intervalKm, $intervalHours,
                               $dueDate, $notifyBell, $notifyEmail, $emailsJson, $isActive, $id];
                    if (!$is_admin) $params[] = $customer_id;
                    $db->prepare($sql)->execute($params);
                    if ($beforeRow) {
                        audit_log('maintenance_reminder.update', 'maintenance_reminder', $id, $beforeRow, $auditAfter);
                    }
                    $success = 'Lembrete atualizado.';
                } else {
                    $db->prepare("
                        INSERT INTO maintenance_reminders
                            (customer_id, imei, driver_id, name, metric, interval_km, interval_hours,
                             due_date, last_done_km, last_done_hours, last_done_at,
                             notify_bell, notify_email, emails, is_active, created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$owner_id, $imei, $driverId, $name, $metric, $intervalKm, $intervalHours,
                                  $dueDate, $lastDoneKm, $lastDoneHours, ($lastDoneKm !== null || $lastDoneHours !== null) ? gmdate('Y-m-d H:i:s') : null,
                                  $notifyBell, $notifyEmail, $emailsJson, $isActive, $_SESSION['user_id']]);
                    audit_log('maintenance_reminder.create', 'maintenance_reminder', (int)$db->lastInsertId(), null, $auditAfter);
                    $success = 'Lembrete criado com sucesso.';
                }
            } catch (PDOException $e) {
                $error = 'Erro ao salvar lembrete: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_reminder') {
        $id = (int)($_POST['id'] ?? 0);
        require_permission('manutencoes', 'delete');
        $beforeSel = $db->prepare("SELECT name, metric, imei, customer_id FROM maintenance_reminders WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?'));
        $beforeParams = $is_admin ? [$id] : [$id, $customer_id];
        $beforeSel->execute($beforeParams);
        $beforeRow = $beforeSel->fetch(PDO::FETCH_ASSOC) ?: null;

        $sql = "DELETE FROM maintenance_reminders WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?');
        $params = [$id];
        if (!$is_admin) $params[] = $customer_id;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if ($beforeRow && $stmt->rowCount() > 0) {
            audit_log('maintenance_reminder.delete', 'maintenance_reminder', $id, $beforeRow, null);
        }
        $success = 'Lembrete removido.';
    } elseif ($action === 'complete_reminder') {
        $id = (int)($_POST['id'] ?? 0);
        require_permission('manutencoes', 'edit');
        $sql = "SELECT * FROM maintenance_reminders WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?');
        $params = [$id];
        if (!$is_admin) $params[] = $customer_id;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $reminder = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($reminder) {
            $nowUtc = gmdate('Y-m-d H:i:s');
            if ($reminder['metric'] === 'odometro') {
                $baseline = $reminder['imei'] ? latest_odometer($db, $reminder['imei']) : null;
                $db->prepare("UPDATE maintenance_reminders SET last_done_km=?, last_done_at=?, last_notified_at=NULL WHERE id=?")
                   ->execute([$baseline, $nowUtc, $id]);
            } elseif ($reminder['metric'] === 'horimetro') {
                $stmt2 = $db->prepare("SELECT engine_hours FROM devices WHERE imei=?");
                $stmt2->execute([$reminder['imei']]);
                $baseline = $stmt2->fetchColumn();
                $db->prepare("UPDATE maintenance_reminders SET last_done_hours=?, last_done_at=?, last_notified_at=NULL WHERE id=?")
                   ->execute([$baseline !== false ? $baseline : null, $nowUtc, $id]);
            } elseif ($reminder['metric'] === 'horas_ignicao') {
                // A referência do "desde a última" passa a ser AGORA — o acumulado zera.
                $db->prepare("UPDATE maintenance_reminders SET last_done_at=?, last_notified_at=NULL WHERE id=?")
                   ->execute([$nowUtc, $id]);
            } else {
                // 'data' é vencimento único: "concluído" desativa o lembrete em vez
                // de recalcular uma próxima data que ninguém informou.
                $db->prepare("UPDATE maintenance_reminders SET is_active=0, last_done_at=? WHERE id=?")
                   ->execute([$nowUtc, $id]);
            }
            audit_log('maintenance_reminder.complete', 'maintenance_reminder', $id,
                ['last_done_at' => $reminder['last_done_at']], ['last_done_at' => $nowUtc, 'metric' => $reminder['metric']]);
            $success = 'Lembrete marcado como concluído.';
        }
    } elseif ($action === 'save_driver_reminders') {
        $driverId = (int)($_POST['driver_id'] ?? 0);
        require_permission('manutencoes', 'edit');
        $remindCnh = !empty($_POST['remind_cnh']) ? 1 : 0;
        $remindTox = !empty($_POST['remind_tox']) ? 1 : 0;
        $beforeSel = $db->prepare("SELECT remind_cnh, remind_tox FROM drivers WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?'));
        $beforeSel->execute($is_admin ? [$driverId] : [$driverId, $customer_id]);
        $beforeRow = $beforeSel->fetch(PDO::FETCH_ASSOC) ?: null;

        $sql = "UPDATE drivers SET remind_cnh=?, remind_tox=? WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?');
        $params = [$remindCnh, $remindTox, $driverId];
        if (!$is_admin) $params[] = $customer_id;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        if ($beforeRow && $stmt->rowCount() > 0) {
            audit_log('driver.reminder_prefs_update', 'driver', $driverId, $beforeRow, ['remind_cnh' => $remindCnh, 'remind_tox' => $remindTox]);
        }
        $success = 'Preferência de lembrete atualizada.';
    }
}

// ── Dados de apoio (selects do formulário) ──────────────────────────────────
$devWhere = $is_admin ? '1=1' : 'customer_id = :cid';
$devParams = $is_admin ? [] : [':cid' => $customer_id];
$devices = $db->prepare("SELECT imei, device_name FROM devices WHERE $devWhere AND is_active=1 ORDER BY device_name");
$devices->execute($devParams);
$devices = $devices->fetchAll(PDO::FETCH_ASSOC);

$drvWhere = $is_admin ? '1=1' : 'customer_id = :cid';
$drivers = $db->prepare("SELECT id, name FROM drivers WHERE $drvWhere AND is_active=1 ORDER BY name");
$drivers->execute($devParams);
$drivers = $drivers->fetchAll(PDO::FETCH_ASSOC);
$driversById = array_column($drivers, 'name', 'id');

$page_title = 'Manutenção';
$current_route = 'manutencoes';
include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($error): ?>
<div class="card mb-16" style="border-color:#fce4eb;background:#fef2f5;color:var(--error);font-size:13px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="mb-16" style="display:flex;gap:8px;border-bottom:1px solid var(--hairline);">
    <a href="?tab=manutencao" style="padding:10px 4px;font-size:13px;font-weight:600;text-decoration:none;
       color:<?= $tab === 'manutencao' ? 'var(--primary)' : 'var(--muted)' ?>;
       border-bottom:2px solid <?= $tab === 'manutencao' ? 'var(--primary)' : 'transparent' ?>;margin-bottom:-1px;">Manutenção</a>
    <a href="?tab=documentos" style="padding:10px 4px;font-size:13px;font-weight:600;text-decoration:none;
       color:<?= $tab === 'documentos' ? 'var(--primary)' : 'var(--muted)' ?>;
       border-bottom:2px solid <?= $tab === 'documentos' ? 'var(--primary)' : 'transparent' ?>;margin-bottom:-1px;">Documentos</a>
</div>

<?php if ($tab === 'manutencao'):
    $where = $is_admin ? '1=1' : 'r.customer_id = :cid';
    $params = $is_admin ? [] : [':cid' => $customer_id];
    $stmt = $db->prepare("
        SELECT r.*, d.device_name, dr.name AS driver_name
        FROM maintenance_reminders r
        LEFT JOIN devices d ON d.imei = r.imei
        LEFT JOIN drivers dr ON dr.id = r.driver_id
        WHERE $where
        ORDER BY r.is_active DESC, r.name
    ");
    $stmt->execute($params);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $editReminder = null;
    if (!empty($_GET['edit'])) {
        $stmt = $db->prepare("SELECT * FROM maintenance_reminders WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $editReminder = $stmt->fetch(PDO::FETCH_ASSOC);
    }
?>
<div style="display:grid;grid-template-columns:1fr 380px;gap:16px">
    <div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nome</th><th>Vínculo</th><th>Métrica</th><th>Atual</th><th>Vencimento</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($reminders as $r):
                    $progress = maintenance_reminder_progress($db, $r);
                    $vinculo = $r['device_name'] ? placa_do_device($r['device_name'], $r['imei'])
                             : ($r['driver_name'] ?? '—');
                ?>
                <tr style="<?= $r['is_active'] ? '' : 'opacity:.5' ?>">
                    <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= htmlspecialchars($vinculo) ?></td>
                    <td><?= htmlspecialchars(MAINTENANCE_METRIC_LABELS[$r['metric']] ?? $r['metric']) ?></td>
                    <td class="text-mono" style="font-size:12px;"><?= htmlspecialchars($progress['current_label']) ?></td>
                    <td class="text-mono" style="font-size:12px;"><?= htmlspecialchars($progress['due_label']) ?></td>
                    <td>
                        <span class="badge" style="background:<?= MAINTENANCE_STATUS_COLORS[$progress['status']] ?>1a;color:<?= MAINTENANCE_STATUS_COLORS[$progress['status']] ?>">
                            <?= htmlspecialchars(MAINTENANCE_STATUS_LABELS[$progress['status']]) ?>
                        </span>
                    </td>
                    <td style="white-space:nowrap;">
                        <a href="?tab=manutencao&edit=<?= $r['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="complete_reminder">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-outline btn-sm">Registrar concluído</button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Remover este lembrete?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_reminder">
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-outline btn-sm" style="color:var(--error)">Remover</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($reminders)): ?>
                <tr><td colspan="7"><div class="empty-state"><h3>Nenhum lembrete</h3><p>Cadastre o primeiro lembrete de manutenção.</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </div>

    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">
            <?= $editReminder ? 'Editar Lembrete' : 'Novo Lembrete' ?>
        </h4>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_reminder">
            <?php if ($editReminder): ?><input type="hidden" name="id" value="<?= $editReminder['id'] ?>"><?php endif; ?>
            <?php if ($is_admin): ?>
            <div class="form-group">
                <label>Cliente <?= $editReminder ? '' : '*' ?></label>
                <select name="customer_id">
                    <?php foreach (report_customer_options($db) as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (($editReminder['customer_id'] ?? $customer_id) == $c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label>Nome *</label>
                <input type="text" name="name" required value="<?= htmlspecialchars($editReminder['name'] ?? '') ?>" placeholder="Ex: Troca de óleo">
            </div>
            <div class="form-group">
                <label>Métrica *</label>
                <select name="metric" id="mr-metric" onchange="mrToggleFields()">
                    <option value="">Selecione</option>
                    <?php foreach (MAINTENANCE_METRIC_LABELS as $mk => $ml): ?>
                    <option value="<?= $mk ?>" <?= (($editReminder['metric'] ?? '') === $mk) ? 'selected' : '' ?>><?= $ml ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="mr-field-imei">
                <label>Ativo (veículo)</label>
                <select name="imei">
                    <option value="">— Selecione —</option>
                    <?php foreach ($devices as $d): ?>
                    <option value="<?= htmlspecialchars($d['imei']) ?>" <?= (($editReminder['imei'] ?? '') === $d['imei']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars(placa_do_device($d['device_name'], $d['imei'])) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Motorista (opcional)</label>
                <select name="driver_id">
                    <option value="">— Nenhum —</option>
                    <?php foreach ($drivers as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= ((int)($editReminder['driver_id'] ?? 0) === (int)$d['id']) ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="mr-field-km">
                <label>Intervalo (km)</label>
                <input type="number" step="0.1" name="interval_km" value="<?= htmlspecialchars($editReminder['interval_km'] ?? '') ?>" placeholder="Ex: 10000">
            </div>
            <div class="form-group" id="mr-field-hours">
                <label>Intervalo (horas)</label>
                <input type="number" step="0.1" name="interval_hours" value="<?= htmlspecialchars($editReminder['interval_hours'] ?? '') ?>" placeholder="Ex: 250">
            </div>
            <div class="form-group" id="mr-field-date">
                <label>Data de Vencimento</label>
                <input type="date" name="due_date" value="<?= htmlspecialchars($editReminder['due_date'] ?? '') ?>">
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="notify_bell" id="mr-bell" value="1" <?= (!isset($editReminder) || !empty($editReminder['notify_bell'])) ? 'checked' : '' ?> style="width:auto">
                <label for="mr-bell" style="margin:0;text-transform:none;font-size:14px;cursor:pointer">Notificar pelo sino</label>
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="notify_email" id="mr-email" value="1" <?= !empty($editReminder['notify_email']) ? 'checked' : '' ?> style="width:auto" onchange="document.getElementById('mr-field-emails').style.display=this.checked?'':'none'">
                <label for="mr-email" style="margin:0;text-transform:none;font-size:14px;cursor:pointer">Notificar por e-mail</label>
            </div>
            <div class="form-group" id="mr-field-emails" style="display:<?= !empty($editReminder['notify_email']) ? '' : 'none' ?>">
                <label>E-mails (até 3, separados por vírgula)</label>
                <input type="text" name="emails" value="<?= htmlspecialchars(implode(', ', json_decode((string)($editReminder['emails'] ?? '[]'), true) ?: [])) ?>">
            </div>
            <div class="form-group" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="is_active" id="mr-active" value="1" <?= (!isset($editReminder) || ($editReminder['is_active'] ?? 1)) ? 'checked' : '' ?> style="width:auto">
                <label for="mr-active" style="margin:0;text-transform:none;font-size:14px;cursor:pointer">Ativo</label>
            </div>
            <div class="flex-between mt-16">
                <?php if ($editReminder): ?>
                <a href="?tab=manutencao" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editReminder ? 'Salvar' : 'Criar Lembrete' ?></button>
            </div>
        </form>
    </div>
</div>
<script>
function mrToggleFields() {
    var metric = document.getElementById('mr-metric').value;
    document.getElementById('mr-field-imei').style.display = ['odometro','horas_ignicao','horimetro'].includes(metric) ? '' : 'none';
    document.getElementById('mr-field-km').style.display = metric === 'odometro' ? '' : 'none';
    document.getElementById('mr-field-hours').style.display = (metric === 'horas_ignicao' || metric === 'horimetro') ? '' : 'none';
    document.getElementById('mr-field-date').style.display = metric === 'data' ? '' : 'none';
}
mrToggleFields();
</script>

<?php else: // ── Aba Documentos ──────────────────────────────────────────
    $today = date('Y-m-d');
    $drvWhere2 = $is_admin ? '1=1' : 'customer_id = :cid';
    $stmt = $db->prepare("SELECT * FROM drivers WHERE $drvWhere2 AND is_active=1 ORDER BY name");
    $stmt->execute($devParams);
    $allDrivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card mb-16" style="font-size:13px;color:var(--muted)">
    O sino avisa quando o vencimento estiver a até <?= MAINTENANCE_DUE_DAYS ?> dias — a data em si é cadastrada na tela de <a href="/motoristas">Motoristas</a>; aqui só se liga ou desliga o lembrete.
</div>
<div class="table-wrap">
    <table>
        <thead><tr><th>Motorista</th><th>CNH Expira</th><th style="width:120px">Lembrete CNH</th><th>Toxicológico Expira</th><th style="width:120px">Lembrete Tox.</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($allDrivers as $d):
                $cnhExpired = $d['cnh_expires_at'] && $d['cnh_expires_at'] < $today;
                $toxExpired = $d['tox_exam_expires_at'] && $d['tox_exam_expires_at'] < $today;
            ?>
            <tr>
                <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($d['name']) ?></td>
                <td style="<?= $cnhExpired ? 'color:var(--error);font-weight:600' : '' ?>">
                    <?= $d['cnh_expires_at'] ? date('d/m/Y', strtotime($d['cnh_expires_at'])) : '—' ?>
                    <?php if ($cnhExpired): ?><span class="badge badge-error" style="margin-left:4px">Vencida</span><?php endif; ?>
                </td>
                <td>
                    <form method="post" style="display:flex;align-items:center;gap:6px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_driver_reminders">
                        <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                        <input type="checkbox" name="remind_cnh" value="1" onchange="this.form.requestSubmit()" <?= $d['remind_cnh'] ? 'checked' : '' ?>>
                        <?php if ($d['remind_tox']): ?><input type="hidden" name="remind_tox" value="1"><?php endif; ?>
                    </form>
                </td>
                <td style="<?= $toxExpired ? 'color:var(--error);font-weight:600' : '' ?>">
                    <?= $d['tox_exam_expires_at'] ? date('d/m/Y', strtotime($d['tox_exam_expires_at'])) : '—' ?>
                    <?php if ($toxExpired): ?><span class="badge badge-error" style="margin-left:4px">Vencido</span><?php endif; ?>
                </td>
                <td>
                    <form method="post" style="display:flex;align-items:center;gap:6px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="save_driver_reminders">
                        <input type="hidden" name="driver_id" value="<?= $d['id'] ?>">
                        <?php if ($d['remind_cnh']): ?><input type="hidden" name="remind_cnh" value="1"><?php endif; ?>
                        <input type="checkbox" name="remind_tox" value="1" onchange="this.form.requestSubmit()" <?= $d['remind_tox'] ? 'checked' : '' ?>>
                    </form>
                </td>
                <td></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($allDrivers)): ?>
            <tr><td colspan="6"><div class="empty-state"><h3>Nenhum motorista</h3><p>Cadastre motoristas em <a href="/motoristas">/motoristas</a> primeiro.</p></div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
