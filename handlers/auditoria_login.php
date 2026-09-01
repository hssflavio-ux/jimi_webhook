<?php
/**
 * bycamera — Relatório de Login e Sessão v4.15.1
 * Rota: /auditoria/login
 *
 * Fatia de `/auditoria` (handlers/auditoria.php) focada em sessão: login
 * (sucesso e falha, via `login_log` no `UNION` de `audit_union_sql()`),
 * logout, troca de cliente ativo e início/fim de impersonação
 * (`session.*`/`customer.*` em `audit_log`, gravados por
 * `includes/auth.php` e `handlers/customer_switch.php`/`clientes.php`).
 *
 * Mesma permissão da tela-mãe (`'auditoria'`, não `'relatorios'`).
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

$filtroUser   = trim((string)($_GET['user_id'] ?? ''));
$dateFrom     = $_GET['date_from'] ?? brt_today();
$dateTo       = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo);
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 50;

// Login (sucesso/falha), logout e troca/impersonação de cliente. NÃO inclui
// 'session.login_failed' bem-sucedido nenhum caso à parte — está tudo na
// mesma categoria "sessão", diferente de /auditoria/negados que filtra por
// STATUS (falha), não por assunto.
$where  = ["t.created_at BETWEEN :df AND :dt",
           "(t.action IN ('session.login','session.login_failed','session.logout') OR t.action LIKE 'customer.%')"];
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
$whereSql = 'WHERE ' . implode(' AND ', $where);

$unionSql = audit_union_sql($db);

$stmtUsers = $scopeCust !== null
    ? $db->prepare("
        SELECT DISTINCT u.id, u.name FROM users u
        JOIN customer_users cu ON cu.user_id = u.id
        WHERE cu.customer_id = :cid ORDER BY u.name
      ")
    : $db->prepare("SELECT id, name FROM users ORDER BY name");
$stmtUsers->execute($scopeCust !== null ? [':cid' => $scopeCust] : []);
$usuarios = $stmtUsers->fetchAll(PDO::FETCH_ASSOC);

// ── Export síncrono ──────────────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('auditoria', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expStmt = $db->prepare("
        SELECT t.* FROM ($unionSql) t
        $whereSql
        ORDER BY t.created_at DESC, t.id DESC
        LIMIT " . SYNC_EXPORT_MAX_ROWS);
    $expStmt->execute($params);
    $statusLabels = ['success' => 'Sucesso', 'denied' => 'Falhou'];
    $expRows = [];
    foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $expRows[] = [
            fmt_brt($r['created_at'], 'd/m/Y H:i:s'),
            trim(($r['actor_name'] ?? '') . ' ' . ($r['actor_email'] ? '(' . $r['actor_email'] . ')' : '')) ?: '—',
            $r['customer_name'] ?? '—',
            $r['action'],
            $statusLabels[$r['status']] ?? $r['status'],
        ];
    }
    stream_export($export, 'auditoria_login_sessao',
        ['Quando', 'Autor', 'Cliente', 'Ação', 'Status'],
        $expRows, 'Relatório de Login e Sessão',
        report_period_label($dateFrom, $dateTo),
        [1.1, 1.8, 1.4, 1.3, 0.9]);
}

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
    $migIndisp = true;
    Logger::warning('auditoria_login: audit_log indisponível', ['erro' => $e->getMessage()]);
}

$page_title    = 'Auditoria — Login e Sessão';
$current_route = 'auditoria';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="mb-16" style="display:flex;gap:4px;border-bottom:1px solid var(--hairline);">
    <a href="/auditoria" style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1px;">Tudo</a>
    <a href="/auditoria/negados" style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1px;">Acessos Negados</a>
    <a href="/auditoria/cadastro" style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1px;">Alterações de Cadastro</a>
    <a href="/auditoria/login" style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:var(--brand);border-bottom:2px solid var(--brand);margin-bottom:-1px;">Login e Sessão</a>
</div>

<?php if (!empty($migIndisp)): ?>
<div class="card mb-16" style="border-left:3px solid #b3261e;background:#fdecea;">
    <div style="font-size:13px;color:#7a1a12;line-height:1.6;">
        <strong>A migração v4.15.0 não foi aplicada.</strong>
        A tabela <code>audit_log</code> não existe, então não há nada para mostrar ainda.
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
    <?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
    <div class="flex-between mb-16">
        <h2 style="font-size:16px;font-weight:600;color:var(--ink);">Login e Sessão</h2>
        <div style="display:flex;gap:8px;align-items:center;">
            <span style="font-size:12px;color:var(--muted);"><?= $totalRows ?> registro(s)</span>
            <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
            <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
            <a href="?<?= $expBase ?>&export=csv" class="btn btn-outline btn-sm">Exportar CSV</a>
        </div>
    </div>
    <div style="overflow:auto;">
    <table class="table">
        <thead>
            <tr>
                <th>Quando</th>
                <th>Autor</th>
                <?php if ($mostrarCliente): ?><th>Cliente</th><?php endif; ?>
                <th>Ação</th>
                <th>Status</th>
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
                    <span class="badge badge-<?= $r['status'] === 'success' ? 'success' : ($r['status'] === 'denied' ? 'error' : 'neutral') ?>">
                        <?= htmlspecialchars($r['status']) ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $mostrarCliente ? 5 : 4 ?>" style="text-align:center;color:var(--muted);padding:24px;">
                Nenhum evento de login/sessão no período/filtro.
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
