<?php
/**
 * JIMI Webhook System — Relatório de Desatualizados v4.21.1
 * Rota: /relatorios/desatualizados
 *
 * Filtro: Cliente.
 * "Desatualizado" (decisão do dono do produto, 15/09/2026) é booleano, não
 * mais uma faixa de dias por última POSIÇÃO: é "não comunicou dentro da
 * tolerância da ignição atual" (is_device_outdated()/device_outdated_sql(),
 * includes/fleet_state.php) — 5 min com ignição ligada, 30 min desligada.
 * Um equipamento sem fix de GPS mas comunicando normalmente (heartbeat) não
 * conta mais como desatualizado; é exatamente o caso que motivou a mudança.
 *
 * Resumo em 2 grupos: Desatualizados / Em dia. Grade da frota completa (com
 * mapa embutido e export, mostrando os dois grupos) + Detalhes/Export
 * próprios só para o grupo Desatualizados — "Em dia" não tem drill-down.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fleet_state.php'; // device_outdated_sql(), device_last_seen_sql()
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('rel_desatualizados', '/relatorios/desatualizados');

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$filterCust = $_GET['customer_id'] ?? null;
$detailBucket = $_GET['bucket'] ?? null;

// Colunas computadas reaproveitadas por toda consulta deste arquivo (bucket,
// export, grade total e detalhe): último SINAL por qualquer via — não só GPS
// — e se esse sinal está fora da tolerância. `$lastSeenExpr` nunca é NULL
// (COALESCE p/ 1970 dentro de device_last_seen_sql()); `$neverSeenExpr` é
// quem checa "nunca deu sinal" de verdade, sem depender de comparar contra a
// sentinela de 1970.
$lastSeenExpr = device_last_seen_sql('d', 'ds');
$neverSeenExpr = "(d.last_communication IS NULL AND ds.last_gps_time IS NULL
                   AND ds.last_heartbeat_time IS NULL AND ds.last_event_time IS NULL)";

// Ordenação: pelo último sinal, default crescente (mais desatualizado no
// topo). Usa a EXPRESSÃO, não um alias de SELECT: nem toda consulta abaixo
// declara o mesmo alias, e a expressão em si nunca é NULL, então não precisa
// mais do tratamento especial de NULL que a bucketização por last_gps_time
// exigia.
[$sort, $order] = report_sort_params(['last_seen'], 'last_seen', 'ASC');
$detailOrderBy = "ORDER BY $lastSeenExpr $order";

// Câmera desativada nunca comunica de novo — sem este filtro ela fica PARA
// SEMPRE em "Desatualizados", inflando um relatório que existe para apontar
// problema na frota ATIVA, não equipamento baixado.
$where = 'WHERE d.is_active = 1';
$params = [];
// Escopo multi-tenant centralizado (v4.7.3) — ver report_customer_scope()
$scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);
if ($scopeCust !== null) {
    $where .= ' AND d.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}

// "Desatualizado" é booleano desde 15/09/2026 — ver o comentário de cabeçalho
// do arquivo. Só o grupo 'desatualizado' tem drill-down (Detalhes/Export);
// 'emdia' existe para o card de resumo e a grade completa.
$outdatedCond = device_outdated_sql('d', 'ds');
$buckets = [
    'desatualizado' => ['label' => 'Desatualizados', 'cond' => $outdatedCond],
    'emdia'         => ['label' => 'Em dia',          'cond' => "NOT $outdatedCond"],
];

$bucketCounts = [];
$total = 0;
try {
    foreach ($buckets as $key => $b) {
        $full = $where ? "$where AND {$b['cond']}" : "WHERE {$b['cond']}";
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM devices d
            LEFT JOIN customers c ON c.id = d.customer_id
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            $full");
        $stmt->execute($params);
        $bucketCounts[$key] = (int)$stmt->fetchColumn();
        $total += $bucketCounts[$key];
    }
} catch (Exception $e) {
    $bucketCounts = array_fill_keys(array_keys($buckets), 0);
}

// ── Export síncrono da FROTA COMPLETA (v4.9.0) ──────────────────
// A tela só sabia exportar a faixa aberta no drill-down; a grade principal —
// a frota inteira ordenada por tempo sem comunicar, que é a resposta que se
// leva para a reunião — não tinha PDF nem planilha. O `!$detailBucket` separa
// os dois: com uma faixa aberta, quem manda é o export da faixa, logo abaixo.
$export = $_GET['export'] ?? '';
if (!$detailBucket && in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/geocode.php';
    require_once __DIR__ . '/../includes/export_helper.php';

    $expRows = [];
    try {
        $expStmt = $db->prepare("
            SELECT d.imei, d.device_name, ds.last_gps_time, ds.last_latitude, ds.last_longitude,
                   ds.last_acc_status, $outdatedCond AS is_outdated, $neverSeenExpr AS never_seen,
                   TIMESTAMPDIFF(MINUTE, $lastSeenExpr, UTC_TIMESTAMP()) AS mins_since,
                   TIMESTAMPDIFF(MINUTE, ds.last_gps_time, UTC_TIMESTAMP()) AS gps_mins_since
            FROM devices d
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            " . ($where ?: '') . "
            ORDER BY $lastSeenExpr $order
            LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($params);
        // fetchAll antes do laço: endereço resolvido em UM lote paralelo
        $src = $expStmt->fetchAll();
        $geoExp = geocode_map_rows($src, 'last_latitude', 'last_longitude', 2000);
        foreach ($src as $r) {
            $mins = $r['never_seen'] ? null : (int)$r['mins_since'];
            $gpsMins = $r['last_gps_time'] === null ? null : (int)$r['gps_mins_since'];
            $expRows[] = [
                $r['device_name'] ?: $r['imei'],
                (int)$r['is_outdated'] === 1 ? 'Desatualizado' : 'Em dia',
                tempo_sem_transmitir($mins),
                $r['last_gps_time'] ? fmt_brt($r['last_gps_time'], 'd/m/Y H:i:s') : '—',
                geocode_cell($geoExp, $r['last_latitude'], $r['last_longitude']),
                export_map_link($r['last_latitude'], $r['last_longitude']),
                $r['last_acc_status'] === null ? '—' : ((int)$r['last_acc_status'] === 1 ? 'Ligada' : 'Desligada'),
                // Posição GPS é um sinal PRÓPRIO, independente do Status
                // (Desatualizado/Em dia): um equipamento pode comunicar
                // normalmente sem conseguir fix de GPS (ver cabeçalho do
                // arquivo). Mesmo limiar de 30 min que já existia aqui.
                ($gpsMins !== null && $gpsMins <= 30) ? 'Válido' : 'Sem sinal',
            ];
        }
    } catch (Throwable $e) { /* tabelas ausentes → export vazio */ }

    stream_export($export, 'relatorio_desatualizados',
        ['Placa', 'Status', 'Sem comunicar há', 'Data/Hora', 'Endereço', 'Mapa', 'Ignição', 'Posição GPS'],
        $expRows, 'Relatório de Desatualizados — Frota completa',
        'Foto de ' . fmt_brt(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i:s') . ' (BRT)',
        // Endereço é a coluna longa; o resto é curto e de largura previsível.
        [1.0, 0.9, 1.2, 1.35, 3.6, 0.6, 0.8, 0.9]);
}

// ── Grade TOTAL (v4.8.0) ────────────────────────────────────────
// Antes esta tela só tinha as faixas e o drill-down por faixa: para ver a frota
// inteira ordenada por tempo sem comunicar era preciso abrir uma faixa de cada
// vez e comparar de cabeça. A grade abaixo responde a pergunta direta — "quem
// está calado, do menos para o mais" — e a coluna é reordenável.
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$totalRows = [];
$totalFrota = 0;
$totalFrotaPages = 1;
try {
    $countStmt = $db->prepare("
        SELECT COUNT(*) FROM devices d
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        " . ($where ?: ''));
    $countStmt->execute($params);
    $totalFrota = (int)$countStmt->fetchColumn();
    $totalFrotaPages = max(1, (int)ceil($totalFrota / $perPage));
    $offset = ($page - 1) * $perPage;

    $tStmt = $db->prepare("
        SELECT d.imei, d.device_name, ds.last_gps_time, ds.last_latitude, ds.last_longitude,
               ds.last_acc_status, $outdatedCond AS is_outdated, $neverSeenExpr AS never_seen,
               COALESCE(c.name, '—') AS customer_name,
               TIMESTAMPDIFF(MINUTE, $lastSeenExpr, UTC_TIMESTAMP()) AS mins_since,
               TIMESTAMPDIFF(MINUTE, ds.last_gps_time, UTC_TIMESTAMP()) AS gps_mins_since
        FROM devices d
        LEFT JOIN customers c ON c.id = d.customer_id
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        " . ($where ?: '') . "
        ORDER BY $lastSeenExpr $order
        LIMIT $perPage OFFSET $offset");
    $tStmt->execute($params);
    $totalRows = $tStmt->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/geocode.php';
$geoTotal = $totalRows ? geocode_map_rows($totalRows, 'last_latitude', 'last_longitude') : [];

/**
 * "há 3 dias", "há 5 h", "há 12 min" — ou 'Nunca'.
 *
 * @param int|null $mins Minutos desde o último SINAL (não só posição — ver
 *                        device_last_seen_sql()); null = nunca deu sinal
 * @returns string
 */
function tempo_sem_transmitir(?int $mins): string
{
    if ($mins === null) return 'Nunca comunicou';
    if ($mins < 60)     return 'há ' . $mins . ' min';
    if ($mins < 1440)   return 'há ' . intdiv($mins, 60) . ' h';
    return 'há ' . intdiv($mins, 1440) . ' dia(s)';
}

// Drill-down só existe para o grupo 'desatualizado' — 'emdia' não tem
// Detalhes/Export (ver cabeçalho do arquivo).
$detailRows = [];
$dpage = max(1, (int)($_GET['dpage'] ?? 1));
$totalDetail = 0;
$totalDetailPages = 1;
if ($detailBucket === 'desatualizado') {
    try {
        $full = $where ? "$where AND $outdatedCond" : "WHERE $outdatedCond";

        $countDetail = $db->prepare("
            SELECT COUNT(*) FROM devices d
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            $full");
        $countDetail->execute($params);
        $totalDetail = (int)$countDetail->fetchColumn();
        $totalDetailPages = max(1, (int)ceil($totalDetail / $perPage));
        $doffset = ($dpage - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT d.imei, d.device_name, ds.last_acc_status, $neverSeenExpr AS never_seen,
                   COALESCE(c.name, '—') as customer_name,
                   TIMESTAMPDIFF(MINUTE, $lastSeenExpr, NOW()) as mins_since,
                   COALESCE(dm.model_name, '—') as model_name
            FROM devices d
            LEFT JOIN customers c ON c.id = d.customer_id
            LEFT JOIN device_models dm ON d.device_model_id = dm.id
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            $full
            $detailOrderBy
            LIMIT $perPage OFFSET $doffset
        ");
        $stmt->execute($params);
        $detailRows = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// Export síncrono da faixa selecionada (padrão YUV: cada faixa com Detalhes + Export)
$export = $_GET['export'] ?? '';
if ($detailBucket === 'desatualizado' && in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expRows = [];
    try {
        $full = $where ? "$where AND $outdatedCond" : "WHERE $outdatedCond";
        $expStmt = $db->prepare("
            SELECT d.imei, d.device_name, ds.last_acc_status, $neverSeenExpr AS never_seen,
                   COALESCE(c.name, '—') as customer_name,
                   TIMESTAMPDIFF(MINUTE, $lastSeenExpr, NOW()) as mins_since,
                   COALESCE(dm.model_name, '—') as model_name
            FROM devices d
            LEFT JOIN customers c ON c.id = d.customer_id
            LEFT JOIN device_models dm ON d.device_model_id = dm.id
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            $full
            $detailOrderBy
            LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($params);
        while ($d = $expStmt->fetch()) {
            $expRows[] = [
                $d['imei'],
                $d['device_name'] ?? '—',
                $d['model_name'],
                $d['customer_name'],
                $d['last_acc_status'] === null ? '—' : ((int)$d['last_acc_status'] === 1 ? 'Ligada' : 'Desligada'),
                tempo_sem_transmitir($d['never_seen'] ? null : (int)$d['mins_since']),
            ];
        }
    } catch (Exception $e) { /* tabelas ausentes → export vazio */ }
    stream_export($export, 'desatualizados_' . $detailBucket,
        ['IMEI', 'Nome', 'Modelo', 'Cliente', 'Ignição', 'Sem comunicar há'],
        $expRows, 'Desatualizados — ' . $buckets[$detailBucket]['label']);
}

$customers = report_customer_options($db);

// Pontos do mapa embutido: só quem tem coordenada. O balão leva a PLACA e a
// data/hora da última posição — aqui cada marcador é um veículo diferente, ao
// contrário do relatório de Posições, onde a placa é sempre a mesma.
$mapPoints = [];
foreach ($totalRows as $r) {
    if (!empty($r['last_latitude']) && (float)$r['last_latitude'] != 0.0
        && !empty($r['last_longitude']) && (float)$r['last_longitude'] != 0.0) {
        $mapPoints[] = [
            'lat'   => (float)$r['last_latitude'],
            'lng'   => (float)$r['last_longitude'],
            'placa' => $r['device_name'] ?: $r['imei'],
            'when'  => $r['last_gps_time'] ? fmt_brt($r['last_gps_time'], 'd/m/Y H:i:s') : '—',
        ];
    }
}

$page_title = 'Relatório de Desatualizados';
$current_route = 'rel_desatualizados';
require_once __DIR__ . '/../web/components/map_assets.php';
$extra_head = BC_MAP_ASSETS_HTML . '
<style>#map-container{height:400px;border-radius:var(--radius-lg);border:1px solid var(--hairline);margin-bottom:16px;display:none;}</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php
// O export do topo é sempre o da FROTA COMPLETA: o `bucket` sai da query de
// propósito, senão o botão mudaria de significado assim que uma faixa fosse
// aberta (a faixa tem os botões dela, mais abaixo).
$expQ = $_GET;
unset($expQ['export'], $expQ['bucket']);
$expBaseFrota = http_build_query($expQ);
?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Desatualizados</h2>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBaseFrota ?><?= $expBaseFrota ? '&' : '' ?>export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBaseFrota ?><?= $expBaseFrota ? '&' : '' ?>export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
    </div>
</div>

<?php render_template_bar('rel_desatualizados', '/relatorios/desatualizados'); ?>

<?php if ($isAdmin): ?>
<div class="card mb-24" style="padding:12px 16px;">
    <form method="GET" style="display:flex;align-items:flex-end;gap:10px;">
        <div>
            <label for="flt-customer_id" class="filtro-rotulo">Cliente</label>
            <select id="flt-customer_id" name="customer_id" class="filtro-campo" style="min-width:180px;">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterCust==$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
        <?php if ($filterCust): ?><a href="/relatorios/desatualizados" class="btn btn-outline btn-sm" style="color:var(--muted);">Limpar</a><?php endif; ?>
    </form>
</div>
<?php endif; ?>

<!-- Distribution bars — só 2 grupos desde 15/09/2026 (booleano, não mais
     faixa de dias); só "Desatualizados" tem drill-down. -->
<?php $bucketColors = ['desatualizado' => 'var(--error)', 'emdia' => 'var(--success)']; ?>
<div class="kpi-grid">
    <?php foreach ($buckets as $key => $b):
        $count = $bucketCounts[$key];
        $pct = $total > 0 ? round($count / $total * 100, 1) : 0;
        $clickable = $key === 'desatualizado';
    ?>
    <div class="kpi-item"<?= $clickable ? ' style="cursor:pointer;" onclick="location.href=\'?bucket=' . $key . ($filterCust ? '&customer_id=' . $filterCust : '') . '\'"' : '' ?>>
        <div class="kpi-item-label"><?= $b['label'] ?></div>
        <div class="kpi-item-value" style="color:<?= $bucketColors[$key] ?? 'var(--ink)' ?>;font-size:24px;"><?= $count ?></div>
        <div class="kpi-item-delta"><?= $pct ?>% do total<?= $clickable ? ' — ver detalhes' : '' ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($total > 0): ?>
<div class="card mb-16" style="padding:12px 16px;">
    <div style="display:flex;height:10px;border-radius:5px;overflow:hidden;margin-bottom:6px;">
        <?php foreach ($buckets as $key => $b):
            $pct = $total > 0 ? round($bucketCounts[$key] / $total * 100, 1) : 0;
        ?>
        <div style="width:<?= $pct ?>%;background:<?= $bucketColors[$key] ?? 'var(--muted)' ?>;" title="<?= $b['label'] ?>: <?= $bucketCounts[$key] ?>"></div>
        <?php endforeach; ?>
    </div>
    <div style="font-size:11px;color:var(--muted);">Total: <?= $total ?> dispositivos</div>
</div>
<?php endif; ?>

<!-- Grade total: a frota inteira ordenada por tempo sem comunicar (v4.8.0) -->
<div class="flex-between mb-12">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);">
        Frota completa
        <span style="font-size:12px;color:var(--muted);font-weight:400;">(<?= $totalFrota ?>)</span>
    </h3>
    <?php if ($mapPoints): ?>
    <button type="button" class="btn btn-outline btn-sm" onclick="toggleMap()">Ver no Mapa</button>
    <?php endif; ?>
</div>

<?php if ($mapPoints): ?>
<div id="map-container"></div>
<?php endif; ?>

<div class="table-wrap mb-24">
    <table>
        <thead>
            <tr>
                <th>Placa</th>
                <th>Status</th>
                <th><?= report_sort_link('last_seen', 'Sem comunicar há', $sort, $order) ?></th>
                <th>Data/Hora</th>
                <th>Endereço</th>
                <th>Mapa</th>
                <th>Ignição</th>
                <th>Posição GPS</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($totalRows)): ?>
            <tr><td colspan="8"><div class="empty-state"><p>Nenhum equipamento encontrado.</p></div></td></tr>
            <?php else: foreach ($totalRows as $r):
                $mins = $r['never_seen'] ? null : (int)$r['mins_since'];
                $temCoord = !empty($r['last_latitude']) && (float)$r['last_latitude'] != 0.0;
                // Posição GPS é um sinal PRÓPRIO, independente do Status —
                // ver cabeçalho do arquivo. Mesmo limiar de 30 min de sempre.
                $gpsMins = $r['last_gps_time'] === null ? null : (int)$r['gps_mins_since'];
                $gpsOk = $gpsMins !== null && $gpsMins <= 30;
            ?>
            <tr>
                <td class="text-mono"><?= htmlspecialchars($r['device_name'] ?: $r['imei']) ?></td>
                <td><?= (int)$r['is_outdated'] === 1
                        ? '<span class="badge badge-error">Desatualizado</span>'
                        : '<span class="badge badge-success">Em dia</span>' ?></td>
                <td><?= $mins === null
                        ? '<span class="badge badge-error">Nunca comunicou</span>'
                        : htmlspecialchars(tempo_sem_transmitir($mins)) ?></td>
                <td class="text-mono"><?= $r['last_gps_time'] ? fmt_brt($r['last_gps_time'], 'd/m/Y H:i:s') : '—' ?></td>
                <td class="cell-endereco"><?= htmlspecialchars(geocode_cell($geoTotal, $r['last_latitude'], $r['last_longitude'])) ?></td>
                <td>
                    <?php if ($temCoord): ?>
                    <a href="<?= htmlspecialchars(map_link_url($r['last_latitude'], $r['last_longitude'])) ?>"
                       target="_blank" class="badge badge-primary">Ver Mapa</a>
                    <?php else: echo '—'; endif; ?>
                </td>
                <td><?= $r['last_acc_status'] === null ? '—' : ((int)$r['last_acc_status'] === 1 ? 'Ligada' : 'Desligada') ?></td>
                <td><?= $gpsOk
                        ? '<span class="badge badge-success">Válido</span>'
                        : '<span class="badge badge-warning">Sem sinal</span>' ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?= report_pagination($page, $totalFrotaPages, $totalFrota, 'equipamentos') ?>

<?php if ($detailBucket === 'desatualizado'): ?>
<div class="flex-between mb-12">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);">
        Detalhes: <?= $buckets[$detailBucket]['label'] ?>
        <span style="font-size:12px;color:var(--muted);font-weight:400;">(<?= $totalDetail ?>)</span>
    </h3>
    <div style="display:flex;gap:8px;">
        <?php $expQ = $_GET; unset($expQ['export']); $expBase = http_build_query($expQ); ?>
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?= report_back_button('/relatorios/desatualizados' . ($filterCust ? '?customer_id=' . urlencode($filterCust) : '')) ?>
    </div>
</div>

<div class="table-wrap">
    <table>
        <thead><tr><th>IMEI</th><th>Nome</th><th>Modelo</th><th>Cliente</th><th>Ignição</th><th><?= report_sort_link('last_seen', 'Sem comunicar há', $sort, $order) ?></th></tr></thead>
        <tbody>
            <?php if (empty($detailRows)): ?>
            <tr><td colspan="6"><div class="empty-state"><p>Nenhum dispositivo nesta faixa.</p></div></td></tr>
            <?php else: foreach ($detailRows as $d): ?>
            <tr>
                <td><span class="text-mono"><?= htmlspecialchars($d['imei']) ?></span></td>
                <td><?= htmlspecialchars($d['device_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($d['model_name']) ?></td>
                <td><?= htmlspecialchars($d['customer_name']) ?></td>
                <td><?= $d['last_acc_status'] === null ? '—' : ((int)$d['last_acc_status'] === 1 ? 'Ligada' : 'Desligada') ?></td>
                <td><?= $d['never_seen'] ? '<span class="badge badge-error">Nunca comunicou</span>' : htmlspecialchars(tempo_sem_transmitir((int)$d['mins_since'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?= report_pagination($dpage, $totalDetailPages, $totalDetail, 'dispositivos', 2, 'dpage') ?>
<?php endif; ?>

<?php if ($mapPoints): ?>
<script>
var mapData = <?= json_encode($mapPoints, JSON_UNESCAPED_UNICODE) ?>;
var mapInstance = null;
function toggleMap() {
    var container = document.getElementById('map-container');
    if (container.style.display === 'block') { container.style.display = 'none'; return; }
    container.style.display = 'block';
    if (!mapInstance) {
        mapInstance = L.map('map-container');
        bcMapBaseLayers(mapInstance);
        var bounds = [];
        mapData.forEach(function(p) {
            bounds.push([p.lat, p.lng]);
            L.marker([p.lat, p.lng]).addTo(mapInstance)
                .bindPopup('<b>' + p.placa + '</b><br>' + p.when);
        });
        if (bounds.length > 0) mapInstance.fitBounds(bounds);
        else mapInstance.setView([-15.78, -47.93], 5);
    }
    setTimeout(function(){ mapInstance.invalidateSize(); }, 100);
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
