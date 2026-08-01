<?php
/**
 * JIMI Webhook System — Configurações de Notificação v4.4.0
 * Rota: /config-notificacoes
 *
 * Matriz de regras: tipo de alarme × canais (sino, pop-up, som, e-mail).
 * Uma regra pertence a um cliente; regras globais (customer_id NULL) são
 * o fallback do sistema e só o administrador as edita.
 *
 * GET            → lista as regras do escopo
 * GET ?action=nova / ?action=editar&id=N → formulário
 * GET ?action=excluir&id=N               → exclui
 * POST                                   → salva (CSRF obrigatório)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/mailer.php';
require_login();

$db         = Database::getInstance()->getConnection();
$user       = get_jimi_user();
$customerId = get_customer_id();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$message = '';
$messageType = '';

/**
 * Normaliza a lista de e-mails do formulário: até 3, sem duplicata,
 * descartando o que não for e-mail válido.
 *
 * @param string $raw Texto separado por vírgula, ponto-e-vírgula ou quebra
 * @returns array
 */
function parse_rule_emails(string $raw): array
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

// ── POST: salvar ───────────────────────────────────────────────
// A guarda `action !== 'excluir'` torna este bloco e o de exclusão mutuamente
// exclusivos: sem ela, um POST de exclusão cairia aqui também e tentaria salvar
// uma regra sem nenhum campo preenchido.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'excluir') {
    csrf_verify();
    $ruleId = !empty($_POST['rule_id']) ? (int)$_POST['rule_id'] : null;
    require_permission('config-notificacoes', $ruleId ? 'edit' : 'create');

    $alarmType = trim($_POST['alarm_type'] ?? '');
    $minRisk   = $_POST['min_risk'] ?? '';
    $minRisk   = in_array($minRisk, ['baixo', 'medio', 'alto'], true) ? $minRisk : null;
    $emails    = parse_rule_emails($_POST['emails'] ?? '');

    $notifyBell  = !empty($_POST['notify_bell'])  ? 1 : 0;
    $notifyPopup = !empty($_POST['notify_popup']) ? 1 : 0;
    $notifySound = !empty($_POST['notify_sound']) ? 1 : 0;
    $notifyEmail = !empty($_POST['notify_email']) ? 1 : 0;
    $isActive    = !empty($_POST['is_active'])    ? 1 : 0;

    // Regra global só para admin; usuário comum grava sempre no próprio cliente
    $ruleCustomer = $customerId;
    if ($isAdmin && !empty($_POST['is_global'])) {
        $ruleCustomer = null;
    }

    if ($alarmType === '') {
        $message = 'Selecione o tipo de alarme.';
        $messageType = 'error';
    } elseif ($notifyEmail && empty($emails)) {
        $message = 'Para notificar por e-mail, informe ao menos um endereço válido.';
        $messageType = 'error';
    } else {
        try {
            if ($ruleId) {
                $stmt = $db->prepare(
                    "UPDATE notification_rules
                        SET alarm_type = :atype, min_risk = :risk,
                            notify_bell = :bell, notify_popup = :popup,
                            notify_sound = :sound, notify_email = :email,
                            emails = :emails, is_active = :active
                      WHERE id = :id"
                );
                $stmt->execute([
                    ':atype'  => $alarmType,
                    ':risk'   => $minRisk,
                    ':bell'   => $notifyBell,
                    ':popup'  => $notifyPopup,
                    ':sound'  => $notifySound,
                    ':email'  => $notifyEmail,
                    ':emails' => $emails ? json_encode($emails, JSON_UNESCAPED_UNICODE) : null,
                    ':active' => $isActive,
                    ':id'     => $ruleId,
                ]);
                $message = 'Regra salva.';
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO notification_rules
                     (customer_id, alarm_type, min_risk, notify_bell, notify_popup,
                      notify_sound, notify_email, emails, is_active)
                     VALUES (:cid, :atype, :risk, :bell, :popup, :sound, :email, :emails, :active)"
                );
                $stmt->execute([
                    ':cid'    => $ruleCustomer,
                    ':atype'  => $alarmType,
                    ':risk'   => $minRisk,
                    ':bell'   => $notifyBell,
                    ':popup'  => $notifyPopup,
                    ':sound'  => $notifySound,
                    ':email'  => $notifyEmail,
                    ':emails' => $emails ? json_encode($emails, JSON_UNESCAPED_UNICODE) : null,
                    ':active' => $isActive,
                ]);
                $message = 'Regra criada.';
            }
            $messageType = 'success';
        } catch (Exception $e) {
            // 23000 = violação da UNIQUE (customer_key, alarm_type)
            if ($e->getCode() === '23000') {
                $message = 'Já existe uma regra para este tipo de alarme neste escopo.';
            } else {
                $message = 'Erro ao salvar: ' . $e->getMessage();
            }
            $messageType = 'error';
        }
    }
}

$action = $_GET['action'] ?? '';

// ── POST: excluir ──────────────────────────────────────────────
// Era GET até a v4.7.2 — e `csrf_verify()` não lê da query string, então um
// `<img src="/config-notificacoes?action=excluir&id=N">` numa página qualquer
// apagava a regra de um admin logado sem nenhuma interação dele.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'excluir' && !empty($_POST['id'])) {
    csrf_verify();
    require_permission('config-notificacoes', 'delete');
    try {
        $stmt = $db->prepare("SELECT customer_id FROM notification_rules WHERE id = :id");
        $stmt->execute([':id' => (int)$_POST['id']]);
        $target = $stmt->fetch();

        if (!$target) {
            $message = 'Regra não encontrada.';
            $messageType = 'error';
        } elseif ($target['customer_id'] === null && !$isAdmin) {
            $message = 'Somente o administrador pode excluir uma regra global.';
            $messageType = 'error';
        } elseif ($target['customer_id'] !== null && !$isAdmin && (int)$target['customer_id'] !== (int)$customerId) {
            $message = 'Regra fora do seu escopo.';
            $messageType = 'error';
        } else {
            $db->prepare("DELETE FROM notification_rules WHERE id = :id")->execute([':id' => (int)$_POST['id']]);
            $message = 'Regra excluída.';
            $messageType = 'success';
        }
    } catch (Exception $e) {
        $message = 'Erro ao excluir: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// ── GET: editar ────────────────────────────────────────────────
$editRule = null;
if ($action === 'editar' && !empty($_GET['id'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM notification_rules WHERE id = :id");
        $stmt->execute([':id' => (int)$_GET['id']]);
        $editRule = $stmt->fetch() ?: null;
        if ($editRule && $editRule['customer_id'] !== null
            && !$isAdmin && (int)$editRule['customer_id'] !== (int)$customerId) {
            $editRule = null; // fora do escopo
        }
    } catch (Exception $e) {}
}

// ── Lista ──────────────────────────────────────────────────────
$rules = [];
try {
    if ($isAdmin) {
        $stmt = $db->prepare(
            "SELECT nr.*, c.name AS customer_name
             FROM notification_rules nr
             LEFT JOIN customers c ON c.id = nr.customer_id
             WHERE nr.customer_id IS NULL OR nr.customer_id = :cid
             ORDER BY (nr.customer_id IS NULL) DESC, nr.alarm_type"
        );
        $stmt->execute([':cid' => $customerId]);
    } else {
        $stmt = $db->prepare(
            "SELECT nr.*, c.name AS customer_name
             FROM notification_rules nr
             LEFT JOIN customers c ON c.id = nr.customer_id
             WHERE nr.customer_id = :cid
             ORDER BY nr.alarm_type"
        );
        $stmt->execute([':cid' => $customerId]);
    }
    $rules = $stmt->fetchAll();
} catch (Exception $e) {
    $message = 'Tabela de regras indisponível — aplique a migração v4.4.0.';
    $messageType = 'error';
}

// ── Tipos de alarme e categorias para o seletor ────────────────
$alarmTypes = [];
$categories = [];
try {
    $alarmTypes = $db->query(
        "SELECT alarm_name_pt, alarm_code, category
         FROM alarm_types
         ORDER BY category, alarm_name_pt"
    )->fetchAll();
    $categories = $db->query(
        "SELECT DISTINCT category FROM alarm_types WHERE category IS NOT NULL AND category <> '' ORDER BY category"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

$smtpOk = mail_is_configured($customerId);

$page_title = 'Configurações de Notificação';
$current_route = 'config-notificacoes';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($message): ?>
<div class="toast toast-<?= $messageType ?> toast-show" style="position:fixed;bottom:24px;right:24px;z-index:9999;">
    <?= htmlspecialchars($message) ?>
</div>
<script>setTimeout(function(){var t=document.querySelector('.toast');if(t)t.style.display='none';},4000);</script>
<?php endif; ?>

<?php if (!$smtpOk): ?>
<div class="card mb-16" style="border-left:3px solid #a97a00;background:#fdf9ec;">
    <div style="font-size:13px;color:#7a5a00;">
        <strong>Servidor de e-mail não cadastrado.</strong> As notificações de sino, pop-up e som funcionam
        normalmente, mas o envio por e-mail vai falhar até que as credenciais sejam preenchidas em
        <a href="/config-smtp" style="color:#7a5a00;text-decoration:underline;">Cadastros &rsaquo; Servidor de E-mail</a>.
    </div>
</div>
<?php endif; ?>

<?php if ($action === 'nova' || $editRule): ?>
<div class="card" style="max-width:720px;">
    <div class="flex-between mb-24">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">
            <?= $editRule ? 'Editar Regra' : 'Nova Regra de Notificação' ?>
        </h2>
        <a href="/config-notificacoes" class="btn btn-outline btn-sm">Voltar</a>
    </div>

    <form method="POST">
        <?= csrf_field() ?>
        <?php if ($editRule): ?>
        <input type="hidden" name="rule_id" value="<?= (int)$editRule['id'] ?>">
        <?php endif; ?>

        <div class="form-group">
            <label>Tipo de Alarme</label>
            <select name="alarm_type" required style="width:100%;padding:9px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">— Selecione —</option>
                <?php if ($categories): ?>
                <optgroup label="Categorias inteiras">
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" <?= ($editRule['alarm_type'] ?? '') === $cat ? 'selected' : '' ?>>
                        Todos de <?= htmlspecialchars($cat) ?>
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endif; ?>
                <?php
                $lastCat = '';
                foreach ($alarmTypes as $at):
                    if ($at['category'] !== $lastCat) {
                        if ($lastCat !== '') echo '</optgroup>';
                        $lastCat = (string)$at['category'];
                        echo '<optgroup label="' . htmlspecialchars($lastCat ?: 'Outros') . '">';
                    }
                ?>
                <option value="<?= htmlspecialchars($at['alarm_name_pt']) ?>"
                        <?= ($editRule['alarm_type'] ?? '') === $at['alarm_name_pt'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($at['alarm_name_pt']) ?> (<?= htmlspecialchars($at['alarm_code']) ?>)
                </option>
                <?php endforeach; if ($lastCat !== '') echo '</optgroup>'; ?>
            </select>
            <p class="text-muted" style="font-size:11px;margin-top:6px;">
                Uma categoria cobre todos os alarmes dela — mais simples que cadastrar um por um.
            </p>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Risco mínimo</label>
                <select name="min_risk" style="width:100%;padding:9px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <option value="">Qualquer risco</option>
                    <option value="baixo" <?= ($editRule['min_risk'] ?? '') === 'baixo' ? 'selected' : '' ?>>Baixo ou maior</option>
                    <option value="medio" <?= ($editRule['min_risk'] ?? '') === 'medio' ? 'selected' : '' ?>>Médio ou maior</option>
                    <option value="alto"  <?= ($editRule['min_risk'] ?? '') === 'alto'  ? 'selected' : '' ?>>Somente alto</option>
                </select>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" style="width:auto;"
                           <?= (!$editRule || !empty($editRule['is_active'])) ? 'checked' : '' ?>>
                    Regra ativa
                </label>
            </div>
        </div>

        <h3 style="font-size:14px;font-weight:600;color:var(--ink);margin:20px 0 12px;">Canais</h3>
        <div style="display:flex;flex-wrap:wrap;gap:18px;padding:14px;background:var(--canvas-soft);border-radius:var(--radius-sm);">
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="notify_bell" value="1" style="width:auto;"
                       <?= (!$editRule || !empty($editRule['notify_bell'])) ? 'checked' : '' ?>> Sino
            </label>
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="notify_popup" value="1" style="width:auto;"
                       <?= !empty($editRule['notify_popup']) ? 'checked' : '' ?>> Pop-up em tempo real
            </label>
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="notify_sound" value="1" style="width:auto;"
                       <?= !empty($editRule['notify_sound']) ? 'checked' : '' ?>> Som
            </label>
            <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="notify_email" value="1" style="width:auto;"
                       <?= !empty($editRule['notify_email']) ? 'checked' : '' ?>> E-mail
            </label>
        </div>

        <div class="form-group mt-16">
            <label>Destinatários de e-mail (até 3)</label>
            <input type="text" name="emails"
                   value="<?= htmlspecialchars(implode(', ', json_decode((string)($editRule['emails'] ?? '[]'), true) ?: [])) ?>"
                   placeholder="operacao@empresa.com.br, supervisor@empresa.com.br">
            <p class="text-muted" style="font-size:11px;margin-top:6px;">
                Separe por vírgula. Endereços inválidos são descartados ao salvar.
            </p>
        </div>

        <?php if ($isAdmin && !$editRule): ?>
        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="is_global" value="1" style="width:auto;">
                Regra global (vale para todos os clientes sem regra própria)
            </label>
        </div>
        <?php endif; ?>

        <div class="mt-24">
            <button type="submit" class="btn btn-primary">Salvar Regra</button>
        </div>
    </form>
</div>

<?php else: ?>
<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Regras de Notificação</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">
            Define o que avisa e por qual canal. A regra do cliente tem precedência sobre a global.
        </p>
    </div>
    <a href="?action=nova" class="btn btn-primary btn-sm">+ Nova Regra</a>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Alarme</th>
                <th>Escopo</th>
                <th>Risco mínimo</th>
                <th>Canais</th>
                <th>Destinatários</th>
                <th>Status</th>
                <th style="text-align:center;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rules)): ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted);">
                Nenhuma regra cadastrada.
            </td></tr>
            <?php else: foreach ($rules as $r):
                $isGlobal = $r['customer_id'] === null;
                $mails = json_decode((string)($r['emails'] ?? '[]'), true) ?: [];
                $chans = [];
                if (!empty($r['notify_bell']))  $chans[] = 'Sino';
                if (!empty($r['notify_popup'])) $chans[] = 'Pop-up';
                if (!empty($r['notify_sound'])) $chans[] = 'Som';
                if (!empty($r['notify_email'])) $chans[] = 'E-mail';
            ?>
            <tr>
                <td style="font-weight:500;"><?= htmlspecialchars($r['alarm_type']) ?></td>
                <td>
                    <?php if ($isGlobal): ?>
                        <span class="badge badge-info">Global</span>
                    <?php else: ?>
                        <?= htmlspecialchars($r['customer_name'] ?? '—') ?>
                    <?php endif; ?>
                </td>
                <td><?= $r['min_risk'] ? htmlspecialchars(ucfirst($r['min_risk'])) : 'Qualquer' ?></td>
                <td style="font-size:12px;"><?= $chans ? htmlspecialchars(implode(' · ', $chans)) : '—' ?></td>
                <td style="font-size:12px;color:var(--muted);"><?= $mails ? htmlspecialchars(implode(', ', $mails)) : '—' ?></td>
                <td>
                    <?php if (!empty($r['is_active'])): ?>
                        <span class="badge badge-success">Ativa</span>
                    <?php else: ?>
                        <span class="badge">Inativa</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <?php if ($isGlobal && !$isAdmin): ?>
                        <span class="text-muted" style="font-size:12px;">somente leitura</span>
                    <?php else: ?>
                    <div style="display:flex;gap:4px;justify-content:center;">
                        <a href="?action=editar&id=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm"
                           style="padding:4px 10px;font-size:12px;">Editar</a>
                        <form method="post" action="/config-notificacoes" style="display:inline"
                              onsubmit="return confirm('Excluir esta regra?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm"
                                    style="padding:4px 10px;font-size:12px;color:var(--error);">Excluir</button>
                        </form>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
