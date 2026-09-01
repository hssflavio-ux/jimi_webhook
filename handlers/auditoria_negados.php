<?php
/**
 * bycamera — Relatório de Acessos Negados v4.15.1
 * Rota: /auditoria/negados
 *
 * Fatia de `/auditoria` (handlers/auditoria.php) focada em `status='denied'`
 * — negação de acesso (403 de `require_admin()`/`require_permission()`, via
 * `audit_log_denied()`) e tentativa de login que falhou (`login_log`, no
 * `UNION` de `audit_union_sql()`). É o relatório de "quem tentou o que não
 * podia", pronto pra auditoria sem montar filtro na tela genérica toda vez.
 *
 * Mesma permissão da tela-mãe (`'auditoria'`, não `'relatorios'`): dado de
 * segurança não fica visível a quem só tem permissão de exportar relatório
 * de frota.
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

// A categoria do relatório: negação de permissão OU login que falhou. Está
// aqui, não no PHP, porque tem de valer também para o export síncrono
// abaixo — a MESMA condição em dois lugares divergiria em silêncio.
$where  = ["t.created_at BETWEEN :df AND :dt", "t.status = 'denied'"];
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

// ── Export síncrono (padrão rel_alarmes.php) ────────────────────────────────
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
    $expRows = [];
    foreach ($expStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $expRows[] = [
            fmt_brt($r['created_at'], 'd/m/Y H:i:s'),
            trim(($r['actor_name'] ?? '') . ' ' . ($r['actor_email'] ? '(' . $r['actor_email'] . ')' : '')) ?: '—',
            $r['customer_name'] ?? '—',
            $r['action'],
            $r['entity_type'] ? $r['entity_type'] . ($r['entity_id'] ? ' #' . $r['entity_id'] : '') : '—',
            $r['after_data'] ?: '—',
        ];
    }
    stream_export($export, 'auditoria_acessos_negados',
        ['Quando', 'Autor', 'Cliente', 'Ação', 'Entidade', 'Detalhe'],
        $expRows, 'Relatório de Acessos Negados',
        report_period_label($dateFrom, $dateTo),
        [1.1, 1.6, 1.4, 1.3, 1.2, 2.2]);
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
    Logger::warning('auditoria_negados: audit_log indisponível', ['erro' => $e->getMessage()]);
}

$page_title    = 'Auditoria — Acessos Negados';
$current_route = 'auditoria';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="mb-16" style="display:flex;gap:4px;border-bottom:1px solid var(--hairline);">
    <a href="/auditoria" style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1px;">Tudo</a>
    <a href="/auditoria/negados" style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:var(--brand);border-bottom:2px solid var(--brand);margin-bottom:-1px;">Acessos Negados</a>
    <a href="/auditoria/cadastro" style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1px;">Alterações de Cadastro</a>
    <a href="/auditoria/login" style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:var(--muted);border-bottom:2px solid transparent;margin-bottom:-1px;">Login e Sessão</a>
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
    <div style="font-size:12px;color:var(--muted);line-height:1.6;">
        Todo <strong style="color:var(--ink);">403</strong> do sistema (tela sem permissão) e toda
        <strong style="color:var(--ink);">tentativa de login que falhou</strong>. Se um número aqui cresce de
        repente, é sinal de tentativa de acesso indevido ou de um grupo de permissão configurado errado.
    </div>
</div>

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
        <h2 style="font-size:16px;font-weight:600;color:var(--ink);">Acessos Negados</h2>
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
                <th>Entidade</th>
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
                <td style="font-size:11px;max-width:280px;" class="text-mono"><?= htmlspecialchars($r['after_data'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= $mostrarCliente ? 6 : 5 ?>" style="text-align:center;color:var(--muted);padding:24px;">
                Nenhum acesso negado no período/filtro.
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
