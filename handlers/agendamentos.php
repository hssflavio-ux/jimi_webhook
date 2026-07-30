<?php
/**
 * JIMI Webhook System — Agendamentos de Relatório v4.7.0
 * Rota: /agendamentos
 *
 * CRUD dos relatórios recorrentes por e-mail + histórico das execuções.
 *
 * O envio propriamente dito não acontece aqui: `scripts/schedule_dispatcher.php`
 * (cron de hora em hora) enfileira o job quando `next_run_at` vence, e o
 * `scripts/worker.php` gera o arquivo e manda o e-mail. Esta tela só configura
 * e presta contas.
 *
 * ── Fuso ────────────────────────────────────────────────────────────────────
 * A hora que o usuário digita é BRT. O `next_run_at` gravado é UTC, calculado
 * por `schedule_next_run()` (includes/schedule.php) — a MESMA função que o
 * dispatcher usa, para que o "próximo envio" exibido não possa divergir do que
 * vai realmente acontecer.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/mailer.php'; // mail_is_configured()

$page_title    = 'Agendamentos';
$current_route = 'agendamentos';

$db         = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user       = get_jimi_user();

$message     = '';
$messageType = '';
$tableMissing = false;

// Retorno do Post/Redirect/Get (mesmo padrão de /geocercas)
const SCHEDULE_FLASH = [
    'criado'     => ['Agendamento criado.', 'success'],
    'atualizado' => ['Agendamento atualizado.', 'success'],
    'excluido'   => ['Agendamento excluído.', 'success'],
    'ativado'    => ['Agendamento reativado. O contador de falhas foi zerado.', 'success'],
    'desativado' => ['Agendamento desativado.', 'success'],
];
if (!empty($_GET['msg']) && isset(SCHEDULE_FLASH[$_GET['msg']])) {
    [$message, $messageType] = SCHEDULE_FLASH[$_GET['msg']];
}

/**
 * Confere se o agendamento pertence ao cliente em contexto.
 *
 * @param PDO      $db  Conexão ativa
 * @param int      $id  report_schedules.id
 * @param int|null $cid Cliente do contexto
 * @returns array|null Linha, ou null se fora de escopo
 */
function schedule_owned(PDO $db, int $id, ?int $cid): ?array
{
    $stmt = $db->prepare("SELECT * FROM report_schedules WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($cid !== null && (int)$row['customer_id'] !== (int)$cid) {
        return null;
    }
    return $row;
}

// ── POST: criar / atualizar / excluir / alternar ───────────────
// Excluir e alternar são POST, não link GET: `csrf_verify()` só lê o token de
// $_POST ou do cabeçalho X-CSRF-Token, e uma ação destrutiva alcançável por
// GET é acionável por um simples <img src="..."> em qualquer página que o
// usuário logado abra.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete' || $action === 'toggle') {
        require_permission('agendamentos', $action === 'delete' ? 'delete' : 'edit');
        $id  = (int)($_POST['id'] ?? 0);
        try {
            $row = schedule_owned($db, $id, $customerId);
            if (!$row) {
                throw new RuntimeException('Agendamento fora do seu escopo.');
            }
            if ($action === 'delete') {
                // O histórico sai por ON DELETE CASCADE
                $db->prepare("DELETE FROM report_schedules WHERE id = :id")->execute([':id' => $id]);
                header('Location: /agendamentos?msg=excluido');
                exit;
            }
            $novo = (int)$row['is_active'] === 1 ? 0 : 1;
            // Reativar zera o contador de falhas — senão o agendamento voltaria
            // já na terceira e seria desativado no primeiro tropeço seguinte.
            $next = $novo === 1 ? schedule_next_run($row) : null;
            $db->prepare("UPDATE report_schedules SET is_active = :a, fail_count = 0, next_run_at = :n WHERE id = :id")
               ->execute([':a' => $novo, ':n' => $next, ':id' => $id]);
            header('Location: /agendamentos?msg=' . ($novo === 1 ? 'ativado' : 'desativado'));
            exit;
        } catch (Throwable $e) {
            $message     = 'Erro: ' . $e->getMessage();
            $messageType = 'error';
        }
    } else {

    require_permission('agendamentos', $action === 'create' ? 'create' : 'edit');

    $id         = (int)($_POST['id'] ?? 0);
    $name       = trim($_POST['name'] ?? '');
    $reportType = (string)($_POST['report_type'] ?? 'alarms');
    $format     = in_array($_POST['format'] ?? 'xlsx', ['csv', 'xlsx', 'pdf'], true) ? $_POST['format'] : 'xlsx';
    $frequency  = in_array($_POST['frequency'] ?? 'diaria', ['diaria', 'semanal', 'mensal'], true) ? $_POST['frequency'] : 'diaria';
    $sendHour   = max(0, min(23, (int)($_POST['send_hour'] ?? 7)));
    $sendDow    = $frequency === 'semanal' ? max(1, min(7, (int)($_POST['send_dow'] ?? 1))) : null;
    // Teto de 28: 29/30/31 não existem em todo mês e "pular fevereiro" nunca é
    // o que o usuário quis dizer.
    $sendDom    = $frequency === 'mensal' ? max(1, min(28, (int)($_POST['send_dom'] ?? 1))) : null;
    $recipients = schedule_parse_recipients($_POST['recipients'] ?? '');
    $skipEmpty  = !empty($_POST['skip_if_empty']) ? 1 : 0;
    $isActive   = !empty($_POST['is_active']) ? 1 : 0;

    $errors = [];
    if ($name === '') {
        $errors[] = 'Informe um nome para o agendamento.';
    }
    if (!isset(schedule_report_types()[$reportType])) {
        $errors[] = 'Tipo de relatório inválido.';
    }
    if (!$recipients) {
        $errors[] = 'Informe ao menos um e-mail de destino válido.';
    }
    if ($customerId === null) {
        $errors[] = 'Selecione um cliente no topo antes de criar um agendamento.';
    }

    if ($errors) {
        $message     = implode(' ', $errors);
        $messageType = 'error';
    } else {
        try {
            $sch = [
                'frequency' => $frequency,
                'send_hour' => $sendHour,
                'send_dow'  => $sendDow,
                'send_dom'  => $sendDom,
            ];
            $nextRun = schedule_next_run($sch);

            $fields = [
                ':name'   => $name,
                ':rtype'  => $reportType,
                ':fmt'    => $format,
                ':freq'   => $frequency,
                ':hour'   => $sendHour,
                ':dow'    => $sendDow,
                ':dom'    => $sendDom,
                ':rcp'    => json_encode($recipients, JSON_UNESCAPED_UNICODE),
                ':skip'   => $skipEmpty,
                ':active' => $isActive,
                ':next'   => $nextRun,
            ];

            if ($id > 0) {
                if (!schedule_owned($db, $id, $customerId)) {
                    throw new RuntimeException('Agendamento fora do seu escopo.');
                }
                // fail_count zera na edição: mexer na configuração é a resposta
                // do usuário ao problema que causou as falhas.
                $db->prepare("
                    UPDATE report_schedules SET
                        name = :name, report_type = :rtype, format = :fmt,
                        frequency = :freq, send_hour = :hour, send_dow = :dow, send_dom = :dom,
                        recipients = :rcp, skip_if_empty = :skip, is_active = :active,
                        next_run_at = :next, fail_count = 0
                    WHERE id = :id")
                   ->execute($fields + [':id' => $id]);
                $flash = 'atualizado';
            } else {
                $db->prepare("
                    INSERT INTO report_schedules
                        (customer_id, user_id, name, report_type, format, frequency,
                         send_hour, send_dow, send_dom, recipients, skip_if_empty, is_active, next_run_at)
                    VALUES
                        (:cid, :uid, :name, :rtype, :fmt, :freq,
                         :hour, :dow, :dom, :rcp, :skip, :active, :next)")
                   ->execute($fields + [
                       ':cid' => (int)$customerId,
                       ':uid' => $user['id'] ?? null,
                   ]);
                $flash = 'criado';
            }

            header('Location: /agendamentos?msg=' . $flash);
            exit;
        } catch (Throwable $e) {
            $message     = 'Erro ao salvar: ' . $e->getMessage();
            $messageType = 'error';
        }
    }

    } // fi delete/toggle else
}

$action = $_GET['action'] ?? '';

// ── Edição ─────────────────────────────────────────────────────
$editRow = null;
if ($action === 'editar' && !empty($_GET['id'])) {
    try {
        $editRow = schedule_owned($db, (int)$_GET['id'], $customerId);
        if (!$editRow) {
            $message     = 'Agendamento não encontrado.';
            $messageType = 'error';
        }
    } catch (Throwable $e) {
        $tableMissing = true;
    }
}
$showForm = ($action === 'novo' || $editRow !== null);

// ── Lista + histórico ──────────────────────────────────────────
$schedules = [];
$runs      = [];
try {
    if ($customerId !== null) {
        $stmt = $db->prepare("
            SELECT s.*, u.name AS user_name,
                   (SELECT COUNT(*) FROM report_schedule_runs r WHERE r.schedule_id = s.id) AS run_count
            FROM report_schedules s
            LEFT JOIN users u ON u.id = s.user_id
            WHERE s.customer_id = :cid
            ORDER BY s.is_active DESC, s.next_run_at ASC");
        $stmt->execute([':cid' => $customerId]);
    } else {
        $stmt = $db->query("
            SELECT s.*, u.name AS user_name,
                   (SELECT COUNT(*) FROM report_schedule_runs r WHERE r.schedule_id = s.id) AS run_count
            FROM report_schedules s
            LEFT JOIN users u ON u.id = s.user_id
            ORDER BY s.is_active DESC, s.next_run_at ASC");
    }
    $schedules = $stmt->fetchAll();

    // Histórico recente do cliente — o JOIN é o que mantém o escopo
    $histSql = "
        SELECT r.*, s.name AS schedule_name, s.customer_id
        FROM report_schedule_runs r
        JOIN report_schedules s ON s.id = r.schedule_id";
    if ($customerId !== null) {
        $hist = $db->prepare("$histSql WHERE s.customer_id = :cid ORDER BY r.executed_at DESC LIMIT 30");
        $hist->execute([':cid' => $customerId]);
    } else {
        $hist = $db->query("$histSql ORDER BY r.executed_at DESC LIMIT 30");
    }
    $runs = $hist->fetchAll();
} catch (Throwable $e) {
    $tableMissing = true;
}

$smtpOk    = mail_is_configured($customerId);
$typeList  = schedule_report_types();

require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Agendamentos</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">
            Relatórios que chegam sozinhos por e-mail na frequência escolhida.
        </p>
    </div>
    <?php if (!$showForm): ?>
    <a href="?action=novo" class="btn btn-primary btn-sm">+ Novo Agendamento</a>
    <?php else: ?>
    <a href="/agendamentos" class="btn btn-outline btn-sm">Voltar</a>
    <?php endif; ?>
</div>

<?php if ($tableMissing): ?>
<div class="card mb-16" style="padding:12px 16px;border-left:3px solid var(--error);">
    <div style="font-size:13px;color:var(--muted);">
        <strong>Tabelas de agendamento indisponíveis.</strong> Aplique a migração <code>v4.7.0</code>.
    </div>
</div>
<?php endif; ?>

<?php if (!$smtpOk): ?>
<div class="card mb-16" style="padding:12px 16px;border-left:3px solid #f5a623;">
    <div style="font-size:13px;color:var(--muted);">
        <strong>Nenhum servidor de e-mail cadastrado para este cliente.</strong>
        Os agendamentos serão criados e o arquivo será gerado, mas o envio vai falhar até que
        as credenciais sejam preenchidas em <a href="/config-smtp">Cadastros › Servidor de E-mail</a>.
    </div>
</div>
<?php endif; ?>

<?php if ($showForm): ?>
<?php
$f = $editRow ?: [];
$curRecipients = implode(', ', json_decode((string)($f['recipients'] ?? '[]'), true) ?: []);
$curFreq = $f['frequency'] ?? 'diaria';
?>
<div class="card mb-24" style="padding:20px 24px;">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);margin-bottom:16px;">
        <?= $editRow ? 'Editar Agendamento' : 'Novo Agendamento' ?>
    </h3>
    <form method="POST" id="schedForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
        <?php if ($editRow): ?><input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>"><?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>Nome *</label>
                <input type="text" name="name" required maxlength="150"
                       value="<?= htmlspecialchars((string)($f['name'] ?? '')) ?>"
                       placeholder="Ex.: Excesso de velocidade — semanal">
            </div>
            <div class="form-group">
                <label>Relatório *</label>
                <select name="report_type">
                    <?php foreach ($typeList as $tk => $tl): ?>
                    <option value="<?= $tk ?>" <?= ($f['report_type'] ?? '') === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Formato</label>
                <select name="format">
                    <option value="xlsx" <?= ($f['format'] ?? 'xlsx') === 'xlsx' ? 'selected' : '' ?>>Excel (.xlsx)</option>
                    <option value="csv"  <?= ($f['format'] ?? '') === 'csv'  ? 'selected' : '' ?>>CSV (.csv)</option>
                    <option value="pdf"  <?= ($f['format'] ?? '') === 'pdf'  ? 'selected' : '' ?>>PDF (.pdf)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Frequência</label>
                <select name="frequency" id="freqSel" onchange="syncFreq()">
                    <?php foreach (SCHEDULE_FREQUENCY_LABELS as $fk => $fl): ?>
                    <option value="<?= $fk ?>" <?= $curFreq === $fk ? 'selected' : '' ?>><?= $fl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Hora do envio (BRT)</label>
                <select name="send_hour" class="text-mono">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                    <option value="<?= $h ?>" <?= (int)($f['send_hour'] ?? 7) === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                    <?php endfor; ?>
                </select>
                <small class="text-muted" style="font-size:11px;">Horário de Brasília. O sistema converte para UTC ao agendar.</small>
            </div>
            <div class="form-group" id="dowGroup">
                <label>Dia da semana</label>
                <select name="send_dow">
                    <?php foreach (SCHEDULE_DOW_LABELS as $dk => $dl): ?>
                    <option value="<?= $dk ?>" <?= (int)($f['send_dow'] ?? 1) === $dk ? 'selected' : '' ?>><?= $dl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="domGroup">
                <label>Dia do mês</label>
                <select name="send_dom" class="text-mono">
                    <?php for ($d = 1; $d <= 28; $d++): ?>
                    <option value="<?= $d ?>" <?= (int)($f['send_dom'] ?? 1) === $d ? 'selected' : '' ?>><?= $d ?></option>
                    <?php endfor; ?>
                </select>
                <small class="text-muted" style="font-size:11px;">Até 28 — dia 29/30/31 não existe em todo mês.</small>
            </div>
        </div>

        <div class="form-group">
            <label>Destinatários * (até 3, separados por vírgula)</label>
            <input type="text" name="recipients" required
                   value="<?= htmlspecialchars($curRecipients) ?>"
                   placeholder="fulano@empresa.com.br, gestor@empresa.com.br">
        </div>

        <div class="form-row">
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="skip_if_empty" value="1" style="width:auto;"
                           <?= (int)($f['skip_if_empty'] ?? 0) === 1 ? 'checked' : '' ?>>
                    Não enviar quando não houver registro no período
                </label>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" style="width:auto;"
                           <?= (int)($f['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
                    Agendamento ativo
                </label>
            </div>
        </div>

        <p class="text-muted" style="font-size:11px;">
            O relatório cobre sempre o período fechado anterior: <strong>ontem</strong> na frequência diária,
            <strong>a semana passada</strong> (segunda a domingo) na semanal e <strong>o mês passado</strong> na mensal.
            Arquivo acima de <?= (int)(getenv('MAIL_MAX_ATTACH_MB') ?: 5) ?> MB é enviado como link, não como anexo.
        </p>

        <div class="flex-between mt-16">
            <a href="/agendamentos" class="btn btn-outline btn-sm">Cancelar</a>
            <button type="submit" class="btn btn-primary">Salvar Agendamento</button>
        </div>
    </form>
</div>

<script>
function syncFreq() {
    var f = document.getElementById('freqSel').value;
    document.getElementById('dowGroup').style.display = (f === 'semanal') ? '' : 'none';
    document.getElementById('domGroup').style.display = (f === 'mensal') ? '' : 'none';
}
syncFreq();
</script>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Nome</th>
                <th>Relatório</th>
                <th>Recorrência</th>
                <th>Destinatários</th>
                <th>Próximo envio</th>
                <th>Último envio</th>
                <th>Status</th>
                <th style="text-align:right;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($schedules)): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted);">
                Nenhum agendamento criado
            </td></tr>
            <?php else: foreach ($schedules as $s):
                $rcp = json_decode((string)$s['recipients'], true) ?: [];
                $fails = (int)$s['fail_count'];
            ?>
            <tr>
                <td>
                    <?= htmlspecialchars((string)$s['name']) ?>
                    <?php if ($s['user_name']): ?>
                    <div class="text-muted" style="font-size:11px;">por <?= htmlspecialchars((string)$s['user_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($typeList[$s['report_type']] ?? (string)$s['report_type']) ?>
                    <span class="badge" style="font-size:10px;margin-left:4px;"><?= strtoupper((string)$s['format']) ?></span>
                </td>
                <td style="font-size:12px;"><?= htmlspecialchars(schedule_describe($s)) ?></td>
                <td style="font-size:12px;">
                    <?= htmlspecialchars(implode(', ', array_slice($rcp, 0, 2))) ?><?= count($rcp) > 2 ? ' +' . (count($rcp) - 2) : '' ?>
                </td>
                <td class="text-mono"><?= $s['next_run_at'] ? fmt_brt($s['next_run_at'], 'd/m/Y H:i') : '—' ?></td>
                <td class="text-mono"><?= $s['last_run_at'] ? fmt_brt($s['last_run_at'], 'd/m/Y H:i') : 'Nunca' ?></td>
                <td>
                    <?php if ((int)$s['is_active'] === 1): ?>
                        <span class="badge badge-success">Ativo</span>
                    <?php else: ?>
                        <span class="badge">Inativo</span>
                    <?php endif; ?>
                    <?php if ($fails > 0): ?>
                        <span class="badge badge-warning" title="Falhas consecutivas"><?= $fails ?>/<?= SCHEDULE_MAX_FAILURES ?> falhas</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;white-space:nowrap;">
                    <a href="?action=editar&id=<?= (int)$s['id'] ?>" class="badge badge-primary">Editar</a>
                    <form method="POST" style="display:inline;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="badge" style="border:none;cursor:pointer;font:inherit;">
                            <?= (int)$s['is_active'] === 1 ? 'Desativar' : 'Ativar' ?>
                        </button>
                    </form>
                    <form method="POST" style="display:inline;"
                          onsubmit="return confirm('Excluir este agendamento e todo o seu histórico?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="badge badge-error" style="border:none;cursor:pointer;font:inherit;">Excluir</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<h3 style="font-size:15px;font-weight:600;color:var(--ink);margin:32px 0 12px;">Histórico de execuções</h3>
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Quando</th>
                <th>Agendamento</th>
                <th>Período coberto</th>
                <th>Registros</th>
                <th>Status</th>
                <th>Detalhe</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($runs)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted);">
                Nenhuma execução registrada ainda
            </td></tr>
            <?php else: foreach ($runs as $r):
                $badge = [
                    'enviado'     => 'badge-success',
                    'enfileirado' => 'badge-info',
                    'vazio'       => 'badge',
                    'falhou'      => 'badge-error',
                ][$r['status']] ?? 'badge';
                $label = [
                    'enviado'     => 'Enviado',
                    'enfileirado' => 'Na fila',
                    'vazio'       => 'Vazio (não enviado)',
                    'falhou'      => 'Falhou',
                ][$r['status']] ?? $r['status'];
            ?>
            <tr>
                <td class="text-mono"><?= fmt_brt($r['executed_at'], 'd/m/Y H:i:s') ?></td>
                <td><?= htmlspecialchars((string)$r['schedule_name']) ?></td>
                <td class="text-mono" style="font-size:12px;">
                    <?php if ($r['period_from']): ?>
                        <?= fmt_brt($r['period_from'], 'd/m/Y') ?> – <?= fmt_brt($r['period_to'], 'd/m/Y') ?>
                    <?php else: echo '—'; endif; ?>
                </td>
                <td class="text-mono"><?= $r['row_count'] !== null ? number_format((int)$r['row_count'], 0, ',', '.') : '—' ?></td>
                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($label) ?></span></td>
                <td style="font-size:12px;color:var(--muted);max-width:340px;">
                    <?= $r['error_message'] ? htmlspecialchars(mb_substr((string)$r['error_message'], 0, 160)) : '—' ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<p class="text-muted" style="font-size:11px;margin-top:12px;">
    O disparo é conferido de hora em hora pelo <code>schedule_dispatcher.php</code>, então o envio
    ocorre na hora cheia escolhida. Uma execução fica em <strong>Na fila</strong> até o
    <code>worker.php</code> gerar o arquivo (no máximo um minuto depois).
    <strong><?= SCHEDULE_MAX_FAILURES ?> falhas consecutivas desativam o agendamento</strong> e notificam quem o criou —
    editar ou reativar zera o contador.
</p>

<?php if ($message): ?>
<div class="toast toast-<?= htmlspecialchars($messageType) ?> toast-show" style="position:fixed;bottom:24px;right:24px;z-index:9999;max-width:420px;">
    <?= htmlspecialchars($message) ?>
</div>
<script>setTimeout(function(){var t=document.querySelector('.toast');if(t)t.style.display='none';},6000);</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
