<?php
/**
 * bycamera — Auditoria de ações de usuário v4.15.0
 * Rota: /auditoria
 *
 * Consulta somente-leitura de `audit_log` (mysql/migration_v4.15.0.sql):
 * quem fez o quê, quando, em qual registro, com o antes/depois quando a ação
 * tinha. Nada nesta tela escreve em `audit_log` — a escrita é sempre
 * `includes/audit.php`, chamada de dentro de cada handler mutante e de
 * `require_admin()`/`require_permission()` (negação de acesso).
 *
 * Unifica `audit_log` com `login_log` (tentativas de login, que já existiam
 * antes desta feature e não foram duplicadas aqui — ver includes/auth.php)
 * via `UNION ALL`, numa subquery com colunas normalizadas. `login_log` não
 * tem `user_id`/`customer_id`/`entity_type` (NULL nas três) — o efeito
 * colateral é ÚTIL: filtrar por usuário, cliente ou entidade exclui essas
 * linhas automaticamente (`NULL = :param` nunca é verdadeiro em SQL), sem
 * precisar de lógica condicional em PHP para "não faz sentido aplicar este
 * filtro em login".
 *
 * Escopo multi-tenant: `report_customer_scope()`/`report_customer_options()`
 * (includes/functions.php), o MESMO ponto único que toda tela de relatório
 * usa — para não reinventar a distinção null=sem restrição / []=revendedor
 * sem cliente (CLAUDE.md).
 */

require_once __DIR__ . '/../includes/auth.php';
require_permission('auditoria', 'view');

$db          = Database::getInstance()->getConnection();
$customerId  = get_customer_id();
$user        = get_jimi_user();
$isAdmin     = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$filtroCust  = $_GET['customer_id'] ?? null;
$scopeCust   = report_customer_scope($filtroCust, $isAdmin, $customerId);
$customers   = $isAdmin ? report_customer_options($db) : [];
$mostrarCliente = ($scopeCust === null);

// ── Filtros ───────────────────────────────────────────────────────────────
$filtroUser   = trim((string)($_GET['user_id'] ?? ''));
$filtroAction = trim((string)($_GET['action'] ?? ''));
$filtroEntity = trim((string)($_GET['entity_type'] ?? ''));
$dateFrom     = $_GET['date_from'] ?? brt_today();
$dateTo       = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo); // teto de 31 dias — auditoria não varre a base inteira
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;

$where  = ['t.created_at BETWEEN :df AND :dt'];
$params = [];
[$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo);
$params[':df'] = $utcFrom;
$params[':dt'] = $utcTo;

if ($scopeCust !== null) {
    $where[] = 't.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}
if ($filtroUser !== '' && ctype_digit($filtroUser)) {
    $where[] = 't.user_id = :uid';
    $params[':uid'] = (int)$filtroUser;
}
if ($filtroAction !== '') {
    $where[] = 't.action LIKE :action';
    $params[':action'] = '%' . $filtroAction . '%';
}
if ($filtroEntity !== '') {
    $where[] = 't.entity_type = :etype';
    $params[':etype'] = $filtroEntity;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// `audit_log` + `login_log` (tentativas de login) numa forma só, com colunas
// normalizadas — ver a nota no cabeçalho do arquivo sobre o efeito colateral
// útil do NULL em login_log.customer_id/user_id/entity_type.
//
// `commands`/`sms_commands` entram como branches CONDICIONAIS (checadas por
// INFORMATION_SCHEMA, não por try/catch): sem isso, uma instalação em que a
// migração v4.14.0 (que cria sms_commands) ainda não rodou perderia a tela
// de auditoria INTEIRA por causa de uma tabela periférica — o que o
// audit_log já garante (negação de acesso, CRUD) não pode depender do que é
// só um extra de leitura.
$existSql = "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('commands','sms_commands')";
$existentes = [];
try {
    $existentes = $db->query($existSql)->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) { /* segue sem os branches extras */ }

$unionParts = ["
    SELECT al.id AS id, 'audit_log' AS src, al.user_id, al.actor_name, al.actor_email,
           al.customer_id, cu.name AS customer_name, al.action, al.entity_type, al.entity_id,
           al.before_data, al.after_data, al.status, al.created_at
      FROM audit_log al
      LEFT JOIN customers cu ON cu.id = al.customer_id
", "
    SELECT ll.id AS id, 'login_log' AS src, NULL AS user_id, NULL AS actor_name, ll.email AS actor_email,
           NULL AS customer_id, NULL AS customer_name,
           IF(ll.success = 1, 'session.login', 'session.login_failed') AS action,
           NULL AS entity_type, NULL AS entity_id, NULL AS before_data, NULL AS after_data,
           IF(ll.success = 1, 'success', 'denied') AS status, ll.created_at
      FROM login_log ll
"];

if (in_array('commands', $existentes, true)) {
    // `commands` (proNo 128/JT/T via IoT Hub) NUNCA teve customer_id
    // acrescentado (conferido nas migrações) — só imei. NULL aqui, de
    // propósito: juntar pelo dono ATUAL de devices.customer_id reabriria a
    // classe de bug que a Fase 2 fechou (câmera trocada de cliente
    // reatribuindo retroativamente o histórico). Efeito: aparece só na
    // visão sem filtro de cliente (admin), nunca filtrado por um cliente
    // específico — melhor não mostrar do que mostrar errado.
    $unionParts[] = "
    SELECT c.id AS id, 'commands' AS src, NULL AS user_id, c.operator AS actor_name, NULL AS actor_email,
           NULL AS customer_id, NULL AS customer_name,
           'command.dispatch' AS action, 'device' AS entity_type, NULL AS entity_id,
           NULL AS before_data, JSON_OBJECT('imei', c.imei, 'pro_no', c.pro_no) AS after_data,
           CASE c.status WHEN 'executed' THEN 'success' WHEN 'failed' THEN 'error' ELSE 'aguardando' END AS status,
           c.created_at
      FROM commands c
    ";
}
if (in_array('sms_commands', $existentes, true)) {
    // `sms_commands.customer_id` É snapshot (resolve_installation_for_imei,
    // regra da Fase 2) — seguro filtrar por cliente aqui, ao contrário de
    // `commands` acima.
    $unionParts[] = "
    SELECT sc.id AS id, 'sms_commands' AS src, NULL AS user_id, sc.operator AS actor_name, NULL AS actor_email,
           sc.customer_id, cu2.name AS customer_name,
           'sms_command.dispatch' AS action, 'device' AS entity_type, NULL AS entity_id,
           NULL AS before_data, JSON_OBJECT('imei', sc.imei, 'command_content', sc.command_content) AS after_data,
           CASE sc.status_envio WHEN 'enviado' THEN 'success' ELSE 'error' END AS status,
           sc.created_at
      FROM sms_commands sc
      LEFT JOIN customers cu2 ON cu2.id = sc.customer_id
    ";
}
$unionSql = implode(' UNION ALL ', $unionParts);

// Lista de usuários pro filtro — só do escopo visível (mesma disciplina de
// report_customer_options(): não vazar nome de usuário de outro tenant).
$stmtUsers = $scopeCust !== null
    ? $db->prepare("
        SELECT DISTINCT u.id, u.name FROM users u
        JOIN customer_users cu ON cu.user_id = u.id
        WHERE cu.customer_id = :cid ORDER BY u.name
      ")
    : $db->prepare("SELECT id, name FROM users ORDER BY name");
$stmtUsers->execute($scopeCust !== null ? [':cid' => $scopeCust] : []);
$usuarios = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

$totalRows  = 0;
$totalPages = 1;
$rows       = [];

try {
    $stmtCount = $db->prepare("SELECT COUNT(*) FROM ($unionSql) t $whereSql");
    $stmtCount->execute($params);
    $totalRows  = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $page       = min($page, $totalPages);
    $offset     = ($page - 1) * $perPage;

    $stmt = $db->prepare("
        SELECT t.* FROM ($unionSql) t
        $whereSql
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT :lim OFFSET :off
    ");
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tabela ausente = migração v4.15.0 não aplicada. Explica em vez de dar
    // erro de SQL cru (a lição do /usuarios na v4.13.21).
    $migIndisp = true;
    Logger::warning('auditoria: audit_log indisponível', ['erro' => $e->getMessage()]);
}

$page_title    = 'Auditoria';
$current_route = 'auditoria';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if (!empty($migIndisp)): ?>
<div class="card mb-16" style="border-left:3px solid #b3261e;background:#fdecea;">
    <div style="font-size:13px;color:#7a1a12;line-height:1.6;">
        <strong>A migração v4.15.0 não foi aplicada.</strong>
        A tabela <code>audit_log</code> não existe, então não há nada para mostrar ainda.
        Rode <code>./scripts/deploy.sh --force</code> mais uma vez, ou aplique
        <code>mysql/migration_v4.15.0.sql</code> à mão.
    </div>
</div>
<?php endif; ?>

<div class="card mb-16">
    <form method="get" class="form-row" style="flex-wrap:wrap;align-items:flex-end;gap:12px;">
        <?php if ($isAdmin && $customers): ?>
        <div class="form-group" style="margin:0;">
            <label>Cliente</label>
            <select name="customer_id">
                <option value="">Todos os clientes</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group" style="margin:0;">
            <label>Usuário</label>
            <select name="user_id">
                <option value="">Todos</option>
                <?php foreach ($usuarios as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $filtroUser === (string)$u['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label>Ação contém</label>
            <input type="text" name="action" value="<?= htmlspecialchars($filtroAction) ?>" placeholder="ex.: chip.delete">
        </div>
        <div class="form-group" style="margin:0;">
            <label>Entidade</label>
            <input type="text" name="entity_type" value="<?= htmlspecialchars($filtroEntity) ?>" placeholder="ex.: sim_card">
        </div>
        <div class="form-group" style="margin:0;">
            <label>De</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label>Até</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <button type="submit" class="btn btn-primary">Filtrar</button>
    </form>
    <?php if (!empty($rangeClamped)): ?>
        <div style="font-size:12px;color:#a97a00;margin-top:8px;">Período ajustado — teto de 31 dias por consulta.</div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="flex-between mb-16">
        <h2 style="font-size:16px;font-weight:600;color:var(--ink);">Ações registradas</h2>
        <span style="font-size:12px;color:var(--muted);"><?= $totalRows ?> registro(s)</span>
    </div>
    <div style="overflow:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Quando</th>
                <th>Autor</th>
                <?php if ($mostrarCliente): ?><th>Cliente</th><?php endif; ?>
                <th>Ação</th>
                <th>Entidade</th>
                <th>Status</th>
                <th>Detalhe</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td style="font-size:12px;white-space:nowrap;"><?= fmt_brt($r['created_at']) ?></td>
                <td style="font-size:12px;">
                    <?= htmlspecialchars($r['actor_name'] ?? '—') ?>
                    <div class="text-mono" style="font-size:11px;color:var(--muted);"><?= htmlspecialchars($r['actor_email'] ?? '') ?></div>
                </td>
                <?php if ($mostrarCliente): ?>
                <td style="font-size:12px;"><?= htmlspecialchars($r['customer_name'] ?? '—') ?></td>
                <?php endif; ?>
                <td class="text-mono" style="font-size:12px;"><?= htmlspecialchars($r['action']) ?></td>
                <td style="font-size:12px;">
                    <?php if ($r['entity_type']): ?>
                        <?= htmlspecialchars($r['entity_type']) ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td style="font-size:12px;">
                    <span class="badge badge-<?= $r['status'] === 'success' ? 'success' : ($r['status'] === 'denied' ? 'error' : 'neutral') ?>">
                        <?= htmlspecialchars($r['status']) ?>
                    </span>
                </td>
                <td style="font-size:11px;max-width:320px;">
                    <?php if ($r['before_data'] || $r['after_data']): ?>
                        <details>
                            <summary style="cursor:pointer;color:var(--muted);">ver</summary>
                            <?php if ($r['before_data']): ?>
                                <div><strong>antes:</strong> <span class="text-mono"><?= htmlspecialchars($r['before_data']) ?></span></div>
                            <?php endif; ?>
                            <?php if ($r['after_data']): ?>
                                <div><strong>depois:</strong> <span class="text-mono"><?= htmlspecialchars($r['after_data']) ?></span></div>
                            <?php endif; ?>
                        </details>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $mostrarCliente ? 7 : 6 ?>" style="text-align:center;color:var(--muted);padding:24px;">
                Nenhuma ação registrada no período/filtro.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="mt-16" style="display:flex;gap:8px;align-items:center;justify-content:center;font-size:12px;">
        <?php
        $qs = $_GET;
        for ($p = 1; $p <= $totalPages; $p++):
            $qs['page'] = $p;
            $active = $p === $page;
        ?>
            <a href="?<?= htmlspecialchars(http_build_query($qs)) ?>"
               style="padding:4px 8px;<?= $active ? 'font-weight:600;color:var(--brand);' : 'color:var(--muted);' ?>">
               <?= $p ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
