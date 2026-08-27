<?php
/**
 * JIMI Webhook System — Relatório de Desatualizados v4.0.0
 * Rota: /relatorios/desatualizados
 *
 * Filtro: Cliente.
 * Resumo em faixas: <24h, >1d, >7d, >30d, Nunca posicionados.
 * Grade da frota completa (com mapa embutido e export) + cada faixa com
 * Detalhes e Export próprios.
 */

require_once __DIR__ . '/../includes/auth.php';
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

// Ordenação do detalhe: só por data/hora da última posição; default crescente
// (mais desatualizado no topo). "Nunca posicionados" (NULL) acompanha o extremo
// mais antigo — primeiro em ASC, último em DESC.
[$sort, $order] = report_sort_params(['last_gps_time'], 'last_gps_time', 'ASC');
$nullsOrder = $order === 'ASC' ? 'DESC' : 'ASC';
$detailOrderBy = "ORDER BY ds.last_gps_time IS NULL $nullsOrder, ds.last_gps_time $order";

// Câmera desativada nunca posiciona de novo — sem este filtro ela fica
// PARA SEMPRE na faixa "Nunca posicionados"/">30 dias", inflando um relatório
// que existe para apontar problema na frota ATIVA, não equipamento baixado.
$where = 'WHERE d.is_active = 1';
$params = [];
// Escopo multi-tenant centralizado (v4.7.3) — ver report_customer_scope()
$scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);
if ($scopeCust !== null) {
    $where .= ' AND d.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}

// Bucketização — última posição vem de device_statistics.last_gps_time
// (devices.last_position_at não existe no schema; ver Fase M.2)
$buckets = [
    'lt24h'  => ['label' => 'Menos de 24 horas', 'cond' => 'TIMESTAMPDIFF(HOUR, ds.last_gps_time, NOW()) BETWEEN 0 AND 23'],
    'gt1d'   => ['label' => 'Mais de 1 dia',     'cond' => 'TIMESTAMPDIFF(DAY, ds.last_gps_time, NOW()) BETWEEN 1 AND 6'],
    'gt7d'   => ['label' => 'Mais de 7 dias',    'cond' => 'TIMESTAMPDIFF(DAY, ds.last_gps_time, NOW()) BETWEEN 7 AND 29'],
    'gt30d'  => ['label' => 'Mais de 30 dias',   'cond' => 'TIMESTAMPDIFF(DAY, ds.last_gps_time, NOW()) >= 30'],
    'never'  => ['label' => 'Nunca posicionados', 'cond' => 'ds.last_gps_time IS NULL'],
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
// a frota inteira ordenada por tempo sem transmitir, que é a resposta que se
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
                   ds.last_acc_status,
                   TIMESTAMPDIFF(MINUTE, ds.last_gps_time, UTC_TIMESTAMP()) AS mins_since
            FROM devices d
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            " . ($where ?: '') . "
            ORDER BY ds.last_gps_time IS NULL " . ($order === 'ASC' ? 'ASC' : 'DESC') . ",
                     ds.last_gps_time " . ($order === 'ASC' ? 'DESC' : 'ASC') . "
            LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($params);
        // fetchAll antes do laço: endereço resolvido em UM lote paralelo
        $src = $expStmt->fetchAll();
        $geoExp = geocode_map_rows($src, 'last_latitude', 'last_longitude', 2000);
        foreach ($src as $r) {
            $mins = $r['last_gps_time'] === null ? null : (int)$r['mins_since'];
            $expRows[] = [
                $r['device_name'] ?: $r['imei'],
                tempo_sem_transmitir($mins),
                $r['last_gps_time'] ? fmt_brt($r['last_gps_time'], 'd/m/Y H:i:s') : '—',
                geocode_cell($geoExp, $r['last_latitude'], $r['last_longitude']),
                export_map_link($r['last_latitude'], $r['last_longitude']),
                $r['last_acc_status'] === null ? '—' : ((int)$r['last_acc_status'] === 1 ? 'Ligada' : 'Desligada'),
                // Mesmo critério da grade: sem posição há mais de 30 min o GPS
                // não é "válido", é silêncio.
                ($mins !== null && $mins <= 30) ? 'Válido' : 'Sem sinal',
            ];
        }
    } catch (Throwable $e) { /* tabelas ausentes → export vazio */ }

    stream_export($export, 'relatorio_desatualizados',
        ['Placa', 'Sem transmitir há', 'Data/Hora', 'Endereço', 'Mapa', 'Ignição', 'Status do GPS'],
        $expRows, 'Relatório de Desatualizados — Frota completa',
        'Foto de ' . fmt_brt(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i:s') . ' (BRT)',
        // Endereço é a coluna longa; o resto é curto e de largura previsível.
        [1.0, 1.2, 1.35, 3.6, 0.6, 0.8, 0.9]);
}

// ── Grade TOTAL (v4.8.0) ────────────────────────────────────────
// Antes esta tela só tinha as faixas e o drill-down por faixa: para ver a frota
// inteira ordenada por tempo sem transmitir era preciso abrir uma faixa de cada
// vez e comparar de cabeça. A grade abaixo responde a pergunta direta — "quem
// está calado, do menos para o mais" — e a coluna é reordenável.
//
// Ordena pelo TEMPO SEM TRANSMITIR, não pela data: são a mesma informação
// invertida, e o usuário pensa em "há quantos dias", não em "desde quando".
// `last_gps_time IS NULL` (nunca posicionou) vai para o extremo de MAIS tempo,
// que é onde pertence — nunca transmitir é o pior caso, não a ausência de caso.
$totalRows = [];
try {
    $tStmt = $db->prepare("
        SELECT d.imei, d.device_name, ds.last_gps_time, ds.last_latitude, ds.last_longitude,
               ds.last_acc_status,
               COALESCE(c.name, '—') AS customer_name,
               TIMESTAMPDIFF(MINUTE, ds.last_gps_time, UTC_TIMESTAMP()) AS mins_since
        FROM devices d
        LEFT JOIN customers c ON c.id = d.customer_id
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        " . ($where ?: '') . "
        ORDER BY ds.last_gps_time IS NULL " . ($order === 'ASC' ? 'ASC' : 'DESC') . ",
                 ds.last_gps_time " . ($order === 'ASC' ? 'DESC' : 'ASC') . "
        LIMIT 1000");
    $tStmt->execute($params);
    $totalRows = $tStmt->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../includes/geocode.php';
$geoTotal = $totalRows ? geocode_map_rows($totalRows, 'last_latitude', 'last_longitude') : [];

/**
 * "há 3 dias", "há 5 h", "há 12 min" — ou 'Nunca'.
 *
 * @param int|null $mins Minutos desde a última posição (null = nunca)
 * @returns string
 */
function tempo_sem_transmitir(?int $mins): string
{
    if ($mins === null) return 'Nunca transmitiu';
    if ($mins < 60)     return 'há ' . $mins . ' min';
    if ($mins < 1440)   return 'há ' . intdiv($mins, 60) . ' h';
    return 'há ' . intdiv($mins, 1440) . ' dia(s)';
}

$detailRows = [];
if ($detailBucket && isset($buckets[$detailBucket])) {
    try {
        $b = $buckets[$detailBucket];
        $full = $where ? "$where AND {$b['cond']}" : "WHERE {$b['cond']}";
        $stmt = $db->prepare("
            SELECT d.imei, d.device_name, ds.last_gps_time AS last_position_at, d.last_communication,
                   COALESCE(c.name, '—') as customer_name,
                   TIMESTAMPDIFF(HOUR, ds.last_gps_time, NOW()) as hours_since,
                   COALESCE(dm.model_name, '—') as model_name
            FROM devices d
            LEFT JOIN customers c ON c.id = d.customer_id
            LEFT JOIN device_models dm ON d.device_model_id = dm.id
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            $full
            $detailOrderBy
            LIMIT 200
        ");
        $stmt->execute($params);
        $detailRows = $stmt->fetchAll();
    } catch (Exception $e) {}
}

// Export síncrono da faixa selecionada (padrão YUV: cada faixa com Detalhes + Export)
$export = $_GET['export'] ?? '';
if ($detailBucket && isset($buckets[$detailBucket]) && in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expRows = [];
    try {
        $b = $buckets[$detailBucket];
        $full = $where ? "$where AND {$b['cond']}" : "WHERE {$b['cond']}";
        $expStmt = $db->prepare("
            SELECT d.imei, d.device_name, ds.last_gps_time AS last_position_at,
                   COALESCE(c.name, '—') as customer_name,
                   TIMESTAMPDIFF(HOUR, ds.last_gps_time, NOW()) as hours_since,
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
                $d['last_position_at'] ? fmt_brt($d['last_position_at']) : 'Nunca',
                $d['hours_since'] !== null ? (int)$d['hours_since'] : '—',
            ];
        }
    } catch (Exception $e) { /* tabelas ausentes → export vazio */ }
    stream_export($export, 'desatualizados_' . $detailBucket,
        ['IMEI', 'Nome', 'Modelo', 'Cliente', 'Última Posição', 'Horas Desde'],
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
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:180px;">
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

<!-- Distribution bars -->
<div class="kpi-grid">
    <?php foreach ($buckets as $key => $b):
        $count = $bucketCounts[$key];
        $pct = $total > 0 ? round($count / $total * 100, 1) : 0;
        $colors = ['lt24h'=>'var(--success)','gt1d'=>'var(--primary)','gt7d'=>'var(--warning)','gt30d'=>'#f4b000','never'=>'var(--error)'];
    ?>
    <div class="kpi-item" style="cursor:pointer;" onclick="location.href='?bucket=<?= $key ?><?= $filterCust ? '&customer_id='.$filterCust : '' ?>'">
        <div class="kpi-item-label"><?= $b['label'] ?></div>
        <div class="kpi-item-value" style="color:<?= $colors[$key] ?? 'var(--ink)' ?>;font-size:24px;"><?= $count ?></div>
        <div class="kpi-item-delta"><?= $pct ?>% do total</div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($total > 0): ?>
<div class="card mb-16" style="padding:12px 16px;">
    <div style="display:flex;height:10px;border-radius:5px;overflow:hidden;margin-bottom:6px;">
        <?php foreach ($buckets as $key => $b):
            $pct = $total > 0 ? round($bucketCounts[$key] / $total * 100, 1) : 0;
            $colors = ['lt24h'=>'var(--success)','gt1d'=>'var(--primary)','gt7d'=>'var(--warning)','gt30d'=>'#f4b000','never'=>'var(--error)'];
        ?>
        <div style="width:<?= $pct ?>%;background:<?= $colors[$key] ?? 'var(--muted)' ?>;" title="<?= $b['label'] ?>: <?= $bucketCounts[$key] ?>"></div>
        <?php endforeach; ?>
    </div>
    <div style="font-size:11px;color:var(--muted);">Total: <?= $total ?> dispositivos</div>
</div>
<?php endif; ?>

<!-- Grade total: a frota inteira ordenada por tempo sem transmitir (v4.8.0) -->
<div class="flex-between mb-12">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);">
        Frota completa
        <span style="font-size:12px;color:var(--muted);font-weight:400;">(<?= count($totalRows) ?>)</span>
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
                <th><?= report_sort_link('last_gps_time', 'Sem transmitir há', $sort, $order) ?></th>
                <th>Data/Hora</th>
                <th>Endereço</th>
                <th>Mapa</th>
                <th>Ignição</th>
                <th>Status do GPS</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($totalRows)): ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted);">Nenhum equipamento encontrado</td></tr>
            <?php else: foreach ($totalRows as $r):
                $mins = $r['last_gps_time'] === null ? null : (int)$r['mins_since'];
                $temCoord = !empty($r['last_latitude']) && (float)$r['last_latitude'] != 0.0;
                // Sem posição há mais de 30 min o GPS não é "válido", é silêncio
                $gpsOk = $mins !== null && $mins <= 30;
            ?>
            <tr>
                <td class="text-mono"><?= htmlspecialchars($r['device_name'] ?: $r['imei']) ?></td>
                <td><?= $mins === null
                        ? '<span class="badge" style="color:var(--error);">Nunca transmitiu</span>'
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

<?php if ($detailBucket): ?>
<div class="flex-between mb-12">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);">
        Detalhes: <?= $buckets[$detailBucket]['label'] ?>
        <span style="font-size:12px;color:var(--muted);font-weight:400;">(<?= count($detailRows) ?>)</span>
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
        <thead><tr><th>IMEI</th><th>Nome</th><th>Modelo</th><th>Cliente</th><th><?= report_sort_link('last_gps_time', 'Última Posição', $sort, $order) ?></th><th>Horas Desde</th></tr></thead>
        <tbody>
            <?php if (empty($detailRows)): ?>
            <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted);">Nenhum dispositivo nesta faixa</td></tr>
            <?php else: foreach ($detailRows as $d): ?>
            <tr>
                <td><span class="text-mono"><?= htmlspecialchars($d['imei']) ?></span></td>
                <td><?= htmlspecialchars($d['device_name'] ?? '—') ?></td>
                <td><?= htmlspecialchars($d['model_name']) ?></td>
                <td><?= htmlspecialchars($d['customer_name']) ?></td>
                <td><?= $d['last_position_at'] ? fmt_brt($d['last_position_at']) : '<span class="badge badge-error">Nunca</span>' ?></td>
                <td><?= $d['hours_since'] !== null ? number_format($d['hours_since'], 0) . 'h' : '—' ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
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
