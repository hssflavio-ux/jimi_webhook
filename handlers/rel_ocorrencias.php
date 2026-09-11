<?php
/**
 * JIMI Webhook System — Relatório de Ocorrências v4.0.0
 * Rota: /relatorios/ocorrencias
 *
 * Versão histórica/auditável do dashboard DMS.
 * Filtros: Clientes, Placa, Tipo de Alarme, Motoristas,
 *          Falso positivo, Risco, Status, Período.
 * Grade: Cliente, Placa, Motorista, Tipo de Alarme, Último alarme em,
 *        Qtd, Risco, Falso positivo, Situação.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
require_once __DIR__ . '/../includes/csrf.php';
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('rel_ocorrencias', '/relatorios/ocorrencias');

$page_title = 'Relatório de Ocorrências';
$current_route = 'rel_ocorrencias';
$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
// Descarte em massa (v4.15.x): exclusivo do admin de PLATAFORMA — não do
// revendedor, que $isAdmin acima também cobre. Mesmo teste de require_admin().
$isSuperAdmin = ($user['role'] ?? '') === 'admin';

$dateFrom  = $_GET['date_from'] ?? brt_today('Y-m-d', '-7 days');
$dateTo    = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo); // teto global 31 dias
$filterCust   = $_GET['customer_id'] ?? null;
$filterImei  = $_GET['imei'] ?? null;
$filterType  = $_GET['alarm_type'] ?? null;
$filterStatus = $_GET['status'] ?? null;
$filterFP    = $_GET['false_positive'] ?? null;
$filterRisk  = $_GET['risk'] ?? null;
$filterDriver = $_GET['driver_id'] ?? null;   // B4: filtro de Motorista (YUV)
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

// Ordenação: whitelist de colunas + default crescente por data/hora.
// A chave 'imei' continua na URL (links e modelos salvos a carregam), mas o
// ORDER BY é pela PLACA — que é o que a coluna passou a exibir.
[$sort, $order] = report_sort_params(['last_alarm_at', 'imei', 'alarm_count'], 'last_alarm_at', 'ASC');
$orderBy = $sort === 'imei' ? "device_label $order" : "o.$sort $order";

$where = 'WHERE o.last_alarm_at BETWEEN :df AND :dt';
[$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo); // dias BRT → janela UTC
$params = [':df' => $utcFrom, ':dt' => $utcTo];

// Escopo multi-tenant centralizado (v4.7.3) — ver report_customer_scope()
$scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);
if ($scopeCust !== null) {
    $where .= ' AND o.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}

// Placas do MESMO escopo, para o filtro (era caixa de texto de IMEI): listar
// mais do que o filtro consulta é o vazamento que report_customer_options()
// existe para não repetir.
$devices = report_device_options($db, $scopeCust);
// Igualdade, não LIKE: o valor agora vem de um <select> de placas, não de uma
// caixa de busca. Com LIKE, escolher a placa cujo IMEI é sufixo do IMEI de
// outra traria as duas.
if ($filterImei) {
    $where .= ' AND o.imei = :imei';
    $params[':imei'] = $filterImei;
}
if ($filterType) {
    $where .= ' AND o.alarm_type = :atype';
    $params[':atype'] = $filterType;
}
if ($filterStatus) {
    $where .= ' AND o.status = :st';
    $params[':st'] = $filterStatus;
}
if ($filterFP !== null && $filterFP !== '') {
    $where .= ' AND o.false_positive = :fp';
    $params[':fp'] = (int)$filterFP;
}
if ($filterRisk) {
    $where .= ' AND o.risk = :risk';
    $params[':risk'] = $filterRisk;
}
if ($filterDriver) {
    $where .= ' AND o.driver_id = :did';
    $params[':did'] = (int)$filterDriver;
}

// Descarte em massa: quantas ocorrências do filtro atual ainda podem ser
// descartadas (exclui as já terminais, pra não sobrescrever tratativa feita).
$discardEligibleCount = 0;
if ($isSuperAdmin) {
    try {
        $deStmt = $db->prepare("SELECT COUNT(*) FROM occurrences o $where AND o.status NOT IN ('resolvida','descartada')");
        $deStmt->execute($params);
        $discardEligibleCount = (int)$deStmt->fetchColumn();
    } catch (Exception $e) {}
}

// POST do descarte em massa. A query string carrega os MESMOS filtros que a
// grade usa (o form aponta pra ?<filtros>&bulk_discard=1), então $where/
// $params acima já refletem exatamente o conjunto a descartar — não há
// parsing duplicado de filtro entre a leitura (GET) e a ação (POST).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['bulk_discard'])) {
    if (!$isSuperAdmin) {
        http_response_code(403);
        exit('Ação restrita ao administrador do sistema.');
    }
    csrf_verify();
    $updStmt = $db->prepare(
        "UPDATE occurrences o
         SET o.status = 'descartada', o.treated_by = :uid, o.treated_at = NOW(),
             o.treatment_notes = 'Descarte em massa (filtro do relatório de ocorrências)'
         $where AND o.status NOT IN ('resolvida','descartada')"
    );
    $updStmt->execute(array_merge($params, [':uid' => $user['id'] ?? null]));
    $backQ = $_GET;
    unset($backQ['bulk_discard']);
    $backQ['bulk_discarded'] = $updStmt->rowCount();
    header('Location: /relatorios/ocorrencias?' . http_build_query($backQ));
    exit;
}

// Export síncrono (padrão YUV §9.2): mesma query da grade, sem paginação
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $statusLabels = ['aguardando'=>'Aguardando','em_tratativa'=>'Em Tratativa','resolvida'=>'Resolvida','descartada'=>'Descartada'];
    $expRows = [];
    try {
        // ⚠️ FILIAL saiu inteira na v4.17.20 — a coluna do export, o filtro do
        // formulário e o `LEFT JOIN branches` (que só existia para a coluna).
        // Decisão do dono do produto: *"não estamos usando esse cadastro no
        // sistema no momento"*, e a medição concorda — 0 filiais cadastradas,
        // 0 de 349 ocorrências com `branch_id`.
        //
        // 🔴 A coluna nunca existiu na TELA — só no arquivo. Era a mesma
        // divergência tela↔export que a v4.17.19 fechou em /video/downloads:
        // quem conferia um contra o outro achava uma coluna a mais no PDF.
        // Agora as duas listas têm a mesma sequência (o export não tem só a
        // coluna "Ação", que é botão).
        $expStmt = $db->prepare("
            SELECT o.*, c.name as customer_name, COALESCE(dr.name, '—') as driver_name,
                   COALESCE(dv.device_name, o.imei) AS device_label
            FROM occurrences o
            LEFT JOIN customers c ON c.id = o.customer_id
            LEFT JOIN drivers dr ON dr.id = o.driver_id
            LEFT JOIN devices dv ON dv.imei = o.imei
            $where
            ORDER BY $orderBy
            LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($params);
        while ($r = $expStmt->fetch()) {
            $expRows[] = [
                $r['customer_name'],
                $r['device_label'],
                $r['driver_name'],
                $r['alarm_type'],
                fmt_brt($r['last_alarm_at']),
                (int)$r['alarm_count'],
                occurrence_risk_label($r['risk']),
                $r['false_positive'] ? 'Sim' : 'Não',
                $statusLabels[$r['status']] ?? $r['status'],
            ];
        }
    } catch (Exception $e) { /* tabela v4 ausente → export vazio */ }
    stream_export($export, 'relatorio_ocorrencias',
        ['Cliente', 'Placa', 'Motorista', 'Alarme', 'Último Alarme em', 'Qtd. Alarmes', 'Risco', 'Falso Positivo', 'Situação'],
        $expRows, 'Relatório de Ocorrências', report_period_label($dateFrom, $dateTo));
}

// Count
try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM occurrences o $where");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));
    $offset = ($page - 1) * $perPage;

    // Data
    // ⚠️ `branch_name` era selecionado aqui e NUNCA desenhado — a grade não tem
    // coluna Filial desde sempre. Saiu na v4.17.20 junto com o JOIN, para que a
    // consulta da tela e a do export continuem simétricas: elas divergirem em
    // silêncio é como a coluna a mais no PDF sobreviveu.
    $dataStmt = $db->prepare("
        SELECT o.*, c.name as customer_name, COALESCE(dr.name, '—') as driver_name,
               COALESCE(dv.device_name, o.imei) AS device_label
        FROM occurrences o
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN drivers dr ON dr.id = o.driver_id
        LEFT JOIN devices dv ON dv.imei = o.imei
        $where
        ORDER BY $orderBy
        LIMIT $perPage OFFSET $offset
    ");
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll();
} catch (Exception $e) {
    $totalRows = 0; $totalPages = 1; $rows = [];
}

// Dropdowns
$customers = report_customer_options($db);

try {
    $typeStmt = $db->query("SELECT DISTINCT alarm_type FROM occurrences ORDER BY alarm_type");
    $alarmTypes = $typeStmt->fetchAll();
} catch (Exception $e) {
    $alarmTypes = [];
}

// B4 (YUV): opções do filtro de Motorista.
// ⚠️ O de Filial saiu na v4.17.20 — ver a nota no bloco de exportação.
$driverList = [];
try {
    $drvStmt = $db->prepare("SELECT id, name FROM drivers WHERE is_active=1" . ($isAdmin ? '' : ' AND customer_id = :cid') . " ORDER BY name");
    $drvStmt->execute($isAdmin ? [] : [':cid' => $customerId]);
    $driverList = $drvStmt->fetchAll();
} catch (Exception $e) {}

require_once __DIR__ . '/../web/layout_base.php';
?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export'], $expQ['bulk_discarded']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Ocorrências</h2>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?php if ($isSuperAdmin): ?>
        <form method="POST" action="?<?= $expBase ?>&bulk_discard=1" style="display:inline;"
              onsubmit="return confirm('Descartar as <?= $discardEligibleCount ?> ocorrência(s) do filtro atual que ainda não foram resolvidas/descartadas? Esta ação não pode ser desfeita.');">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-outline btn-sm" style="color:var(--error);" <?= $discardEligibleCount === 0 ? 'disabled' : '' ?>>
                Descartar Todos os Filtrados (<?= $discardEligibleCount ?>)
            </button>
        </form>
        <?php endif; ?>
        <?php if (report_has_query()) echo report_back_button('/relatorios/ocorrencias'); ?>
    </div>
</div>

<?php if (isset($_GET['bulk_discarded'])): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid var(--success);font-size:13px;color:var(--muted);">
    <?= (int)$_GET['bulk_discarded'] ?> ocorrência(s) descartada(s) em massa.
</div>
<?php endif; ?>

<?php render_template_bar('rel_ocorrencias', '/relatorios/ocorrencias'); ?>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <?php if ($isAdmin): ?>
        <div>
            <label for="flt-customer_id" class="filtro-rotulo">Cliente</label>
            <select id="flt-customer_id" name="customer_id" class="filtro-campo">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterCust == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="flt-imei" class="filtro-rotulo">Placa</label>
            <?= report_device_select($devices, (string)($filterImei ?? '')) ?>
        </div>
        <div>
            <label for="flt-alarm_type" class="filtro-rotulo">Tipo</label>
            <select id="flt-alarm_type" name="alarm_type" class="filtro-campo">
                <option value="">Todos</option>
                <?php foreach ($alarmTypes as $t): ?>
                <option value="<?= htmlspecialchars($t['alarm_type']) ?>" <?= $filterType === $t['alarm_type'] ? 'selected' : '' ?>><?= htmlspecialchars($t['alarm_type']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="flt-status" class="filtro-rotulo">Status</label>
            <select id="flt-status" name="status" class="filtro-campo">
                <option value="">Todos</option>
                <option value="aguardando" <?= $filterStatus === 'aguardando' ? 'selected' : '' ?>>Aguardando</option>
                <option value="em_tratativa" <?= $filterStatus === 'em_tratativa' ? 'selected' : '' ?>>Em Tratativa</option>
                <option value="resolvida" <?= $filterStatus === 'resolvida' ? 'selected' : '' ?>>Resolvida</option>
                <option value="descartada" <?= $filterStatus === 'descartada' ? 'selected' : '' ?>>Descartada</option>
            </select>
        </div>
        <div>
            <label for="flt-risk" class="filtro-rotulo">Risco</label>
            <select id="flt-risk" name="risk" class="filtro-campo">
                <option value="">Todos</option>
                <option value="baixo" <?= $filterRisk === 'baixo' ? 'selected' : '' ?>>Baixo</option>
                <option value="medio" <?= $filterRisk === 'medio' ? 'selected' : '' ?>>Médio</option>
                <option value="alto" <?= $filterRisk === 'alto' ? 'selected' : '' ?>>Alto</option>
            </select>
        </div>
        <div>
            <label for="flt-false_positive" class="filtro-rotulo">Falso Positivo</label>
            <select id="flt-false_positive" name="false_positive" class="filtro-campo">
                <option value="">Todos</option>
                <option value="1" <?= $filterFP === '1' ? 'selected' : '' ?>>Sim</option>
                <option value="0" <?= $filterFP === '0' ? 'selected' : '' ?>>Não</option>
            </select>
        </div>
        <?php if ($driverList): ?>
        <div>
            <label for="flt-driver_id" class="filtro-rotulo">Motorista</label>
            <select id="flt-driver_id" name="driver_id" class="filtro-campo">
                <option value="">Todos</option>
                <?php foreach ($driverList as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $filterDriver == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="flt-date_from" class="filtro-rotulo">Período (máx. <?= REPORT_RANGE_MAX_DAYS ?> dias)</label>
            <div style="display:flex;gap:4px;">
                <input type="date" id="flt-date_from" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="filtro-campo" style="width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="filtro-campo" style="width:130px;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<?php if ($rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid var(--warning);font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias: <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Cliente</th>
                <th><?= report_sort_link('imei', 'Placa', $sort, $order) ?></th>
                <th>Motorista</th>
                <th>Tipo de Alarme</th>
                <th><?= report_sort_link('last_alarm_at', 'Último Alarme', $sort, $order) ?></th>
                <th><?= report_sort_link('alarm_count', 'Qtd', $sort, $order, 'DESC') ?></th>
                <th>Risco</th>
                <th>Falso Pos.</th>
                <th>Situação</th>
                <th style="text-align:center;">Ação</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="10"><div class="empty-state"><p>Nenhuma ocorrência encontrada.</p></div></td></tr>
            <?php else: ?>
            <?php
            $riskBadges = ['baixo'=>'badge-primary','medio'=>'badge-warning','alto'=>'badge-error'];
            $statusBadges = ['aguardando'=>'badge-warning','em_tratativa'=>'badge-info','resolvida'=>'badge-success','descartada'=>'badge'];
            $statusLabels = ['aguardando'=>'Aguardando','em_tratativa'=>'Em Tratativa','resolvida'=>'Resolvida','descartada'=>'Descartada'];
            foreach ($rows as $r):
            ?>
            <tr>
                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                <td><span class="text-mono"><?= htmlspecialchars($r['device_label']) ?></span></td>
                <td><?= htmlspecialchars($r['driver_name']) ?></td>
                <td><?= htmlspecialchars($r['alarm_type']) ?></td>
                <td class="text-mono"><?= fmt_brt($r['last_alarm_at']) ?></td>
                <td><?= (int)$r['alarm_count'] ?></td>
                <td><span class="badge <?= $riskBadges[$r['risk']] ?? 'badge' ?>"><?= htmlspecialchars(occurrence_risk_label($r['risk'])) ?></span></td>
                <td><?= $r['false_positive'] ? '<span class="badge badge-warning">Sim</span>' : 'Não' ?></td>
                <td><span class="badge <?= $statusBadges[$r['status']] ?? 'badge' ?>"><?= $statusLabels[$r['status']] ?? $r['status'] ?></span></td>
                <td style="text-align:center;">
                    <?php
                    // v4.15.x — leva a URL de volta pra este relatório (com os
                    // MESMOS filtros/página) pro botão "Fechar" da tratativa
                    // não jogar o usuário no dashboard geral. Ver
                    // ocorrencias_dashboard.php ($returnUrl).
                    $returnQ = $_GET; unset($returnQ['bulk_discarded']);
                    $returnUrl = '/relatorios/ocorrencias' . ($returnQ ? '?' . http_build_query($returnQ) : '');
                    ?>
                    <a href="/ocorrencias/dashboard?id=<?= $r['id'] ?>&return=<?= urlencode($returnUrl) ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">Abrir</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, 'ocorrências') ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
