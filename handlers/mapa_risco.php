<?php
/**
 * bycamera — Mapa de Risco ADAS/DMS v4.20.0
 * Rota: /mapa-risco — permissão da tela BI (router.php, $screenByHandler)
 *
 * Fonte: risk_events e risk_exposure, gravadas por scripts/risk_builder.php.
 * As regras (peso, faixas, grade de 1 km, jornada contínua) moram em
 * includes/risk_map.php; esta tela só agrega e desenha.
 *
 * Índice = Σ pesos ÷ horas em movimento. A exposição mínima (RISK_MIN_*) tira
 * do ranking quem tem pouco tempo de estrada: uma célula com 6 min de
 * exposição e 1 alerta daria 10 pontos por hora e iria sozinha para o topo.
 * Alerta cuja ocorrência foi marcada como falso positivo não entra em número
 * nenhum.
 *
 * Cada aba monta suas tabelas numa estrutura só (mr_table), e a tela e a
 * exportação leem essa MESMA estrutura — tela e arquivo não divergem em ordem
 * nem em coluna (a lição de /video/downloads, v4.17.19).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
require_once __DIR__ . '/../includes/geocode.php';
require_once __DIR__ . '/../includes/risk_map.php';
require_once __DIR__ . '/../includes/vehicle_icons.php';
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('mapa_risco', '/mapa-risco');

const MR_TABS = [
    'onde'      => 'Onde',
    'quando'    => 'Quando',
    'jornada'   => 'Jornada',
    'quem'      => 'Quem',
    'tendencia' => 'Tendência',
];

/** Colunas de métrica comuns a toda tabela da tela e da exportação. */
const MR_COLS = ['Alertas', 'Pontos', 'Horas dirigidas', 'km', 'Índice (pts/h)', 'Pts/100 km'];

/** Cores das faixas do mapa — as mesmas do risco em /bi. */
const MR_COLORS = ['baixo' => '#0052ff', 'medio' => '#f4b000', 'alto' => '#cf202f', 'insuficiente' => '#9ca3af'];

/** Quantos locais a tabela mostra na tela (a exportação traz todos). */
const MR_TOP_LOCAIS = 20;

const MR_MESES = [1 => 'jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

$page_title    = 'Mapa de Risco';
$current_route = 'mapa_risco';

$db         = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user       = get_jimi_user();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$tab = (string)($_GET['aba'] ?? 'onde');
if (!isset(MR_TABS[$tab])) {
    $tab = 'onde';
}

[$dateFrom, $dateTo, $rangeClamped] = risk_clamp_range(
    (string)($_GET['date_from'] ?? brt_today('Y-m-d', '-' . (RISK_DEFAULT_DAYS - 1) . ' days')),
    (string)($_GET['date_to'] ?? brt_today())
);

$filterCust = $_GET['customer_id'] ?? null;
$F = [
    // Escopo multi-tenant centralizado — ver report_customer_scope()
    'cust'    => report_customer_scope($filterCust, $isAdmin, $customerId),
    'vehicle' => max(0, (int)($_GET['vehicle_id'] ?? 0)),
    'vtype'   => (string)($_GET['vehicle_type'] ?? ''),
    'driver'  => (string)($_GET['driver_id'] ?? ''),   // '' todos · 'nd' não identificado · id
    'group'   => (string)($_GET['risk_group'] ?? ''),
];
if (!isset(VEHICLE_ICONS[$F['vtype']])) {
    $F['vtype'] = '';
}
if (!isset(RISK_GROUP_LABELS[$F['group']])) {
    $F['group'] = '';
}
if ($F['driver'] !== 'nd' && !ctype_digit($F['driver'])) {
    $F['driver'] = '';
}

$todayBrt = brt_today();
$span     = mr_span($dateFrom, $dateTo);
$prevTo   = mr_shift($dateFrom, -1);
$prevFrom = mr_shift($prevTo, -($span - 1));

$tableMissing = false;
$buildError   = false;
$tables       = [];
$total        = mr_zero();
$prev         = mr_zero();
$coverage     = ['alerts' => 0, 'with_driver' => 0];
$mapCells     = [];
$grid         = [];
$gridMax      = 0.0;
$charts       = [];
$reincidencia = [];
$mom          = null;

try {
    $db->query("SELECT 1 FROM risk_events LIMIT 1");
    $db->query("SELECT 1 FROM risk_exposure LIMIT 1");
} catch (Throwable $e) {
    $tableMissing = true;
}

if (!$tableMissing) {
    try {
        $total = mr_agg($db, $F, $dateFrom, $dateTo, "'t'", "'t'")['t'] ?? mr_zero();
        $prev  = mr_agg($db, $F, $prevFrom, $prevTo, "'t'", "'t'")['t'] ?? mr_zero();

        [$w, $p] = mr_where('e', true, $F, $dateFrom, $dateTo);
        $stmt = $db->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(e.driver_id IS NOT NULL), 0) AS d
                              FROM risk_events e WHERE $w");
        $stmt->execute($p);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        $coverage = ['alerts' => (int)$c['n'], 'with_driver' => (int)$c['d']];

        switch ($tab) {
            case 'onde':
                $cellDimE = "CONCAT(e.cell_y, ':', e.cell_x)";
                $cellDimX = "CONCAT(x.cell_y, ':', x.cell_x)";
                $cells = mr_agg($db, $F, $dateFrom, $dateTo, $cellDimE, $cellDimX, 'AND e.cell_y IS NOT NULL');
                $tops  = mr_top_groups($db, $F, $dateFrom, $dateTo, $cellDimE, 'AND e.cell_y IS NOT NULL');

                $withAlerts = [];
                $eligible   = [];
                foreach ($cells as $k => $r) {
                    if ($k === '' || $r['alerts'] === 0) {
                        continue;
                    }
                    $withAlerts[$k] = $r;
                    if ($r['driving_s'] >= RISK_MIN_CELL_S && $r['alerts'] >= RISK_MIN_CELL_ALERTS) {
                        $eligible[$k] = mr_index($r);
                    }
                }
                $cuts = mr_terciles(array_values($eligible));

                // Endereço das células mais carregadas primeiro: o geocode tem teto por lote.
                uasort($withAlerts, fn($a, $b) => $b['pts'] <=> $a['pts']);
                $geoRows = [];
                foreach (array_slice(array_keys($withAlerts), 0, 200) as $k) {
                    [$lat, $lng] = mr_cell_center_key($k);
                    $geoRows[] = ['lat' => $lat, 'lng' => $lng];
                }
                $geo = $geoRows ? geocode_map_rows($geoRows, 'lat', 'lng', 200) : [];

                foreach ($withAlerts as $k => $r) {
                    [$y, $x]     = array_map('intval', explode(':', $k));
                    [$lat, $lng] = risk_cell_center($y, $x);
                    $ok          = isset($eligible[$k]);
                    $mapCells[]  = [
                        'k'    => $k,
                        'b'    => risk_cell_bounds($y, $x),
                        'c'    => mr_color(mr_index($r), $ok, $cuts),
                        'ok'   => $ok,
                        'addr' => geocode_cell($geo, $lat, $lng),
                        'a'    => $r['alerts'],
                        'p'    => $r['pts'],
                        'h'    => mr_hours($r['driving_s']),
                        'i'    => mr_num(mr_index($r)),
                        'top'  => $tops[$k] ?? '',
                    ];
                }

                arsort($eligible);
                $rows = [];
                foreach (array_keys($eligible) as $k) {
                    [$lat, $lng] = mr_cell_center_key($k);
                    $rows[] = [
                        'cells' => array_merge([geocode_cell($geo, $lat, $lng)], mr_cells($cells[$k]), [$tops[$k] ?? '']),
                        'lat'   => $lat,
                        'lng'   => $lng,
                        'cell'  => $k,
                    ];
                }
                $tables[] = mr_table('Locais mais críticos',
                    array_merge(['Local'], MR_COLS, ['Comportamentos principais']), $rows,
                    sprintf('Entram no ranking as células de 1 km com pelo menos %d min de exposição e %d alertas no período.',
                        RISK_MIN_CELL_S / 60, RISK_MIN_CELL_ALERTS));
                break;

            case 'quando':
                $agg = mr_agg($db, $F, $dateFrom, $dateTo, 'e.day_period',
                    "CASE WHEN x.brt_hour < 6 THEN 'madrugada' WHEN x.brt_hour < 12 THEN 'manha' WHEN x.brt_hour < 18 THEN 'tarde' ELSE 'noite' END");
                $rows = [];
                foreach (RISK_DAY_PERIOD_LABELS as $key => $label) {
                    $rows[] = ['cells' => array_merge([$label], mr_cells($agg[$key] ?? mr_zero()))];
                }
                $tables[] = mr_table('Faixa do dia', array_merge(['Faixa do dia'], MR_COLS), $rows);
                $charts['periodo'] = mr_chart(array_values(RISK_DAY_PERIOD_LABELS), array_keys(RISK_DAY_PERIOD_LABELS), $agg);

                $agg = mr_agg($db, $F, $dateFrom, $dateTo, 'e.brt_weekday', 'DAYOFWEEK(x.brt_date)');
                $rows = [];
                foreach (RISK_WEEKDAY_LABELS as $key => $label) {
                    $rows[] = ['cells' => array_merge([$label], mr_cells($agg[(string)$key] ?? mr_zero()))];
                }
                $tables[] = mr_table('Dia da semana', array_merge(['Dia da semana'], MR_COLS), $rows);

                $gridAgg = mr_agg($db, $F, $dateFrom, $dateTo,
                    "CONCAT(e.brt_weekday, ':', e.brt_hour)", "CONCAT(DAYOFWEEK(x.brt_date), ':', x.brt_hour)");
                foreach (RISK_WEEKDAY_LABELS as $wd => $label) {
                    for ($h = 0; $h < 24; $h++) {
                        $cell = $gridAgg["$wd:$h"] ?? mr_zero();
                        $grid[$wd][$h] = $cell;
                        if ($cell['driving_s'] >= RISK_MIN_CELL_S) {
                            $gridMax = max($gridMax, (float)mr_index($cell));
                        }
                    }
                }
                break;

            case 'jornada':
                $dim  = 'COALESCE(e.continuous_band, 0)';
                $agg  = mr_agg($db, $F, $dateFrom, $dateTo, $dim, 'x.continuous_band');
                $tops = mr_top_groups($db, $F, $dateFrom, $dateTo, $dim);
                $labels = RISK_CONTINUOUS_BAND_LABELS + [0 => RISK_NO_GPS_LABEL];
                $rows = [];
                foreach ($labels as $band => $label) {
                    $rows[] = ['cells' => array_merge([$label], mr_cells($agg[(string)$band] ?? mr_zero()), [$tops[(string)$band] ?? ''])];
                }
                $tables[] = mr_table('Direção contínua no momento do alerta',
                    array_merge(['Direção contínua'], MR_COLS, ['Comportamentos principais']), $rows,
                    'Direção contínua = tempo com ignição ligada desde a última parada de 30 min ou mais com ignição desligada. '
                    . 'Falta de sinal não interrompe a contagem. "Sem GPS": não havia posição antes do alerta.');
                $charts['jornada'] = mr_chart(array_values(RISK_CONTINUOUS_BAND_LABELS), array_keys(RISK_CONTINUOUS_BAND_LABELS), $agg);

                $dim  = 'COALESCE(e.speed_band, 0)';
                $agg  = mr_agg($db, $F, $dateFrom, $dateTo, $dim, 'x.speed_band');
                $tops = mr_top_groups($db, $F, $dateFrom, $dateTo, $dim);
                $labels = RISK_SPEED_BAND_LABELS + [0 => 'Sem velocidade'];
                $rows = [];
                foreach ($labels as $band => $label) {
                    $rows[] = ['cells' => array_merge([$label], mr_cells($agg[(string)$band] ?? mr_zero()), [$tops[(string)$band] ?? ''])];
                }
                $tables[] = mr_table('Velocidade no momento do alerta',
                    array_merge(['Velocidade'], MR_COLS, ['Comportamentos principais']), $rows,
                    'O índice de cada faixa usa as horas dirigidas NAQUELA faixa — por isso faixas raras podem ter índice alto com poucos alertas.');
                $charts['velocidade'] = mr_chart(array_values(RISK_SPEED_BAND_LABELS), array_keys(RISK_SPEED_BAND_LABELS), $agg);
                break;

            case 'quem':
                $cols = array_merge(MR_COLS, ['Situação']);

                $veh     = mr_agg($db, $F, $dateFrom, $dateTo, 'e.vehicle_id', 'x.vehicle_id');
                $vehInfo = mr_vehicle_info($db, array_keys($veh));
                $tables[] = mr_table('Veículos', array_merge(['Veículo'], $cols),
                    mr_ranked_rows($veh, fn($k) => $vehInfo[(int)$k]['plate'] ?? "Veículo #$k", RISK_MIN_ENTITY_S),
                    sprintf('Entram no ranking os veículos com pelo menos %d h de exposição no período.', RISK_MIN_ENTITY_S / 3600));

                $porTipo = [];
                foreach ($veh as $k => $r) {
                    $tipo = (string)($vehInfo[(int)$k]['vehicle_type'] ?? '');
                    $acc  = $porTipo[$tipo] ?? mr_zero();
                    foreach (['pts', 'alerts', 'driving_s', 'idle_s', 'km'] as $m) {
                        $acc[$m] += $r[$m];
                    }
                    $porTipo[$tipo] = $acc;
                }
                $tables[] = mr_table('Tipo de veículo', array_merge(['Tipo de veículo'], $cols),
                    mr_ranked_rows($porTipo, fn($k) => vehicle_type_label($k === '' ? null : $k), RISK_MIN_ENTITY_S));

                $drv     = mr_agg($db, $F, $dateFrom, $dateTo, 'COALESCE(e.driver_id, 0)', 'x.driver_id');
                $drvInfo = mr_driver_names($db, array_keys($drv));
                $tables[] = mr_table('Motoristas', array_merge(['Motorista'], $cols),
                    mr_ranked_rows($drv, fn($k) => (int)$k === 0 ? 'Não identificado' : ($drvInfo[(int)$k] ?? "Motorista #$k"), RISK_MIN_ENTITY_S),
                    sprintf('%s dos alertas do período têm motorista identificado — o restante aparece como "Não identificado".',
                        $coverage['alerts'] > 0 ? number_format($coverage['with_driver'] * 100 / $coverage['alerts'], 1, ',', '.') . '%' : '0%'));

                $grp  = mr_agg($db, $F, $dateFrom, $dateTo, 'e.risk_group', null);
                $rows = [];
                uasort($grp, fn($a, $b) => $b['pts'] <=> $a['pts']);
                foreach ($grp as $g => $r) {
                    // Denominador de cada comportamento: todas as horas dirigidas do recorte.
                    $r['driving_s'] = $total['driving_s'];
                    $r['idle_s']    = $total['idle_s'];
                    $r['km']        = $total['km'];
                    $rows[] = ['cells' => array_merge([risk_group_label($g)], mr_cells($r), [''])];
                }
                $tables[] = mr_table('Comportamentos', array_merge(['Comportamento'], $cols), $rows,
                    'O índice de cada comportamento é calculado sobre o total de horas dirigidas do recorte.');

                [$w, $p] = mr_where('e', true, $F, $dateFrom, $dateTo);
                $p[':minrep'] = RISK_REINCIDENCE_MIN;
                $stmt = $db->prepare("
                    SELECT e.vehicle_id, COALESCE(e.driver_id, 0) AS driver, e.risk_group,
                           COUNT(*) AS n, SUM(e.weight) AS pts, MIN(e.alarm_time) AS primeiro, MAX(e.alarm_time) AS ultimo
                    FROM risk_events e
                    WHERE $w
                    GROUP BY e.vehicle_id, COALESCE(e.driver_id, 0), e.risk_group
                    HAVING COUNT(*) >= :minrep
                    ORDER BY n DESC, pts DESC
                    LIMIT 50");
                $stmt->execute($p);
                $reincidencia = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $vehInfo += mr_vehicle_info($db, array_column($reincidencia, 'vehicle_id'));
                $drvInfo += mr_driver_names($db, array_column($reincidencia, 'driver'));
                foreach ($reincidencia as &$rr) {
                    $rr['veiculo']   = $vehInfo[(int)$rr['vehicle_id']]['plate'] ?? ('Veículo #' . $rr['vehicle_id']);
                    $rr['motorista'] = (int)$rr['driver'] === 0 ? 'Não identificado' : ($drvInfo[(int)$rr['driver']] ?? ('Motorista #' . $rr['driver']));
                }
                unset($rr);
                break;

            case 'tendencia':
                // Semanas de segunda a domingo; as últimas 12, incluindo a corrente.
                $monday = mr_shift($todayBrt, -((int)gmdate('N', strtotime($todayBrt . ' 00:00:00 UTC')) - 1));
                $wFrom  = mr_shift($monday, -77);
                $aggW   = mr_agg($db, $F, $wFrom, $todayBrt,
                    'DATE_SUB(e.brt_date, INTERVAL WEEKDAY(e.brt_date) DAY)', 'DATE_SUB(x.brt_date, INTERVAL WEEKDAY(x.brt_date) DAY)');
                $rows = [];
                $labels = [];
                $keys = [];
                for ($i = 0; $i < 12; $i++) {
                    $wk       = mr_shift($wFrom, 7 * $i);
                    $label    = 'Semana de ' . gmdate('d/m', strtotime($wk . ' 00:00:00 UTC'));
                    $labels[] = $label;
                    $keys[]   = $wk;
                    $rows[]   = ['cells' => array_merge([$label], mr_cells($aggW[$wk] ?? mr_zero()))];
                }
                $tables[] = mr_table('Semanas', array_merge(['Período'], MR_COLS), $rows);
                $charts['semanas'] = mr_chart($labels, $keys, $aggW);

                $utc     = new DateTimeZone('UTC');
                $mFirst  = substr($todayBrt, 0, 8) . '01';
                $mFrom   = (new DateTime($mFirst . ' 00:00:00', $utc))->modify('-11 months')->format('Y-m-d');
                $aggM    = mr_agg($db, $F, $mFrom, $todayBrt, "DATE_FORMAT(e.brt_date, '%Y-%m')", "DATE_FORMAT(x.brt_date, '%Y-%m')");
                $rows = [];
                $labels = [];
                $keys = [];
                for ($i = 0; $i < 12; $i++) {
                    $month    = (new DateTime($mFrom . ' 00:00:00', $utc))->modify("+$i months");
                    $key      = $month->format('Y-m');
                    $label    = MR_MESES[(int)$month->format('n')] . '/' . $month->format('Y');
                    $labels[] = $label;
                    $keys[]   = $key;
                    $rows[]   = ['cells' => array_merge([$label], mr_cells($aggM[$key] ?? mr_zero()))];
                }
                $tables[] = mr_table('Meses', array_merge(['Período'], MR_COLS), $rows);
                $charts['meses'] = mr_chart($labels, $keys, $aggM);

                // Mês atual contra o anterior nos MESMOS dias corridos: comparar o
                // mês fechado com o parcial faria todo começo de mês parecer melhora.
                $day       = (int)substr($todayBrt, 8, 2);
                $prevFirst = (new DateTime($mFirst . ' 00:00:00', $utc))->modify('-1 month');
                $prevEnd   = mr_shift($prevFirst->format('Y-m-d'), min($day, (int)$prevFirst->format('t')) - 1);
                $mom = [
                    'days' => $day,
                    'cur'  => mr_agg($db, $F, $mFirst, $todayBrt, "'t'", "'t'")['t'] ?? mr_zero(),
                    'prev' => mr_agg($db, $F, $prevFirst->format('Y-m-d'), $prevEnd, "'t'", "'t'")['t'] ?? mr_zero(),
                    'curLabel'  => MR_MESES[(int)substr($todayBrt, 5, 2)] . '/' . substr($todayBrt, 0, 4),
                    'prevLabel' => MR_MESES[(int)$prevFirst->format('n')] . '/' . $prevFirst->format('Y'),
                ];
                break;
        }
    } catch (Throwable $e) {
        $buildError = true;
        if (class_exists('Logger')) {
            Logger::error('Mapa de Risco: falha ao montar a análise', ['aba' => $tab, 'error' => $e->getMessage()]);
        } else {
            error_log('Mapa de Risco: falha ao montar a análise — ' . $e->getMessage());
        }
    }
}

// ── Exportação: as MESMAS tabelas da aba ───────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('bi', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';

    $single     = count($tables) === 1;
    $expHeaders = [];
    $expRows    = [];
    foreach ($tables as $t) {
        if (!$expHeaders) {
            $expHeaders = $single ? $t['headers'] : array_merge(['Recorte', 'Item'], array_slice($t['headers'], 1));
            if ($tab === 'onde') {
                $expHeaders[] = 'Mapa';
            }
        }
        foreach ($t['rows'] as $r) {
            $line = $single ? $r['cells'] : array_merge([$t['title']], $r['cells']);
            if ($tab === 'onde') {
                $line[] = export_map_link($r['lat'], $r['lng']);
            }
            $expRows[] = $line;
            if (count($expRows) >= SYNC_EXPORT_MAX_ROWS) {
                break 2;
            }
        }
    }
    if (!$expHeaders) {
        $expHeaders = array_merge(['Recorte', 'Item'], MR_COLS);
    }
    $subtitle = $tab === 'tendencia'
        ? 'Últimas 12 semanas e últimos 12 meses'
        : report_period_label($dateFrom, $dateTo);
    stream_export($export, 'mapa_risco_' . $tab, $expHeaders, $expRows, 'Mapa de Risco — ' . MR_TABS[$tab], $subtitle);
}

$customers = [];
$vehicles  = [];
$drivers   = [];
try {
    $customers = report_customer_options($db);
    $vehicles  = mr_vehicle_options($db, $F['cust']);
    $drivers   = mr_driver_options($db, $F['cust']);
} catch (Throwable $e) {}

require_once __DIR__ . '/../web/components/map_assets.php';
$extra_head = '<style>
.mr-tabs{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid var(--hairline);margin-bottom:20px;}
.mr-tabs a{padding:10px 16px;font-size:13px;font-weight:500;color:var(--muted);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-1px;}
.mr-tabs a:hover{color:var(--ink);}
.mr-tabs a.ativa{color:var(--primary);border-bottom-color:var(--primary);}
#risk-map{height:520px;border-radius:var(--radius-lg);border:1px solid var(--hairline);}
.mr-legenda{display:flex;flex-wrap:wrap;gap:14px;font-size:12px;color:var(--muted);margin-top:10px;}
.mr-legenda span{display:inline-flex;align-items:center;gap:6px;}
.mr-legenda i{display:inline-block;width:12px;height:12px;border-radius:3px;}
.mr-grid{border-collapse:collapse;font-size:11px;}
.mr-grid th,.mr-grid td{padding:4px 6px;text-align:center;border:1px solid var(--hairline);min-width:26px;}
.mr-grid th{color:var(--muted);font-weight:600;}
.chart-box{position:relative;height:280px;}
.mr-nota{font-size:12px;color:var(--muted);margin-top:8px;}
</style>';
if ($tab === 'onde') {
    $extra_head = BC_MAP_ASSETS_HTML . $extra_head;
} else {
    $extra_head .= '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';
}

require_once __DIR__ . '/../web/layout_base.php';

$idx     = mr_index($total);
$idxPrev = mr_index($prev);
$varPct  = ($idx !== null && $idxPrev !== null && $idxPrev > 0) ? ($idx - $idxPrev) * 100 / $idxPrev : null;
$expQ    = $_GET;
unset($expQ['export']);
$expBase = htmlspecialchars(http_build_query($expQ));
?>

<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Mapa de Risco</h2>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&amp;export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&amp;export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?php if (report_has_query()) echo report_back_button('/mapa-risco'); ?>
    </div>
</div>

<?php if ($tableMissing): ?>
<div class="card mb-16" style="padding:12px 16px;border-left:3px solid var(--error);">
    <div style="font-size:13px;color:var(--muted);">
        <strong>Tabelas do Mapa de Risco indisponíveis.</strong> Aplique a migração <code>v4.20.0</code>
        e rode <code>php scripts/risk_builder.php --desde=AAAA-MM-DD</code>.
    </div>
</div>
<?php elseif ($buildError): ?>
<div class="card mb-16" style="padding:12px 16px;border-left:3px solid var(--error);">
    <div style="font-size:13px;color:var(--muted);">
        <strong>Não foi possível montar esta análise.</strong> O erro foi registrado no log do sistema.
    </div>
</div>
<?php endif; ?>

<?php render_template_bar('mapa_risco', '/mapa-risco'); ?>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <input type="hidden" name="aba" value="<?= htmlspecialchars($tab) ?>">
        <?php if ($isAdmin): ?>
        <div>
            <label class="filtro-rotulo">Cliente</label>
            <select name="customer_id" class="filtro-campo">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$filterCust === (string)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label class="filtro-rotulo">Veículo</label>
            <select name="vehicle_id" class="filtro-campo">
                <option value="">Todos</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= (int)$v['id'] ?>" <?= $F['vehicle'] === (int)$v['id'] ? 'selected' : '' ?>><?= htmlspecialchars($v['plate']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="filtro-rotulo">Tipo de veículo</label>
            <select name="vehicle_type" class="filtro-campo">
                <option value="">Todos</option>
                <?php foreach (array_keys(VEHICLE_ICONS) as $vt): ?>
                <option value="<?= htmlspecialchars($vt) ?>" <?= $F['vtype'] === $vt ? 'selected' : '' ?>><?= htmlspecialchars(vehicle_type_label($vt)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="filtro-rotulo">Motorista</label>
            <select name="driver_id" class="filtro-campo">
                <option value="">Todos</option>
                <option value="nd" <?= $F['driver'] === 'nd' ? 'selected' : '' ?>>Não identificado</option>
                <?php foreach ($drivers as $d): ?>
                <option value="<?= (int)$d['id'] ?>" <?= $F['driver'] === (string)$d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="filtro-rotulo">Comportamento</label>
            <select name="risk_group" class="filtro-campo">
                <option value="">Todos</option>
                <?php foreach (RISK_GROUP_LABELS as $g => $label): ?>
                <option value="<?= htmlspecialchars($g) ?>" <?= $F['group'] === $g ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="filtro-rotulo">Período (máx. <?= RISK_MAX_DAYS ?> dias)</label>
            <div style="display:flex;gap:4px;">
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="filtro-campo" style="width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="filtro-campo" style="width:130px;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<?php if ($rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid var(--warning);font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= RISK_MAX_DAYS ?> dias:
    <?= htmlspecialchars(gmdate('d/m/Y', strtotime($dateFrom . ' 00:00:00 UTC'))) ?> a <?= htmlspecialchars(gmdate('d/m/Y', strtotime($dateTo . ' 00:00:00 UTC'))) ?>.
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:12px;">
    <div class="card" style="padding:14px 18px;">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);">Índice de risco (pts/h)</div>
        <div class="text-mono" style="font-size:22px;font-weight:500;color:var(--ink);margin-top:4px;"><?= mr_num($idx) ?></div>
        <div style="font-size:12px;margin-top:2px;color:<?= $varPct === null ? 'var(--muted)' : ($varPct > 0 ? 'var(--error)' : 'var(--success)') ?>;">
            <?= $varPct === null ? 'sem base no período anterior' : (($varPct > 0 ? '+' : '−') . number_format(abs($varPct), 0, ',', '.') . '% vs período anterior') ?>
        </div>
    </div>
    <?php
    $kpiCards = [
        ['Alertas contados', number_format($total['alerts'], 0, ',', '.')],
        ['Horas dirigidas',  mr_hours($total['driving_s']) . ' h'],
        ['km percorridos',   mr_num($total['km'], 0)],
        ['Com motorista identificado', $coverage['alerts'] > 0
            ? number_format($coverage['with_driver'] * 100 / $coverage['alerts'], 1, ',', '.') . '%' : '—'],
    ];
    foreach ($kpiCards as [$label, $value]):
    ?>
    <div class="card" style="padding:14px 18px;">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);"><?= $label ?></div>
        <div class="text-mono" style="font-size:22px;font-weight:500;color:var(--ink);margin-top:4px;"><?= htmlspecialchars($value) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($dateTo >= mr_shift($todayBrt, -6)): ?>
<p class="mr-nota" style="margin-bottom:16px;">
    Os últimos 7 dias ainda podem mudar: a câmera descarrega alertas e posições guardados sem sinal até dias depois.
</p>
<?php endif; ?>

<nav class="mr-tabs">
    <?php foreach (MR_TABS as $key => $label):
        $q = $_GET;
        unset($q['export']);
        $q['aba'] = $key;
    ?>
    <a href="?<?= htmlspecialchars(http_build_query($q)) ?>" data-aba="<?= $key ?>" class="<?= $key === $tab ? 'ativa' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'onde'): ?>
<div class="card mb-24" style="padding:16px 20px;">
    <?php if ($mapCells): ?>
    <div id="risk-map"></div>
    <div class="mr-legenda">
        <span><i style="background:<?= MR_COLORS['alto'] ?>"></i>Terço mais crítico</span>
        <span><i style="background:<?= MR_COLORS['medio'] ?>"></i>Terço intermediário</span>
        <span><i style="background:<?= MR_COLORS['baixo'] ?>"></i>Terço menos crítico</span>
        <span><i style="background:<?= MR_COLORS['insuficiente'] ?>"></i>Pouca exposição (fora do ranking)</span>
    </div>
    <?php else: ?>
    <div class="empty-state" style="padding:48px 24px;">
        <h3 style="font-size:16px;">Sem alertas com localização no período</h3>
    </div>
    <?php endif; ?>
</div>
<?php $t = $tables[0] ?? null; if ($t): ?>
<div class="card mb-24" style="padding:16px 20px;">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);margin-bottom:10px;"><?= htmlspecialchars($t['title']) ?></h3>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <?php foreach ($t['headers'] as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?>
                <th>Mapa</th>
            </tr></thead>
            <tbody>
                <?php if (!$t['rows']): ?>
                <tr><td colspan="<?= count($t['headers']) + 1 ?>" style="text-align:center;padding:28px;color:var(--muted);">Exposição insuficiente para ranquear locais neste período</td></tr>
                <?php else: foreach (array_slice($t['rows'], 0, MR_TOP_LOCAIS) as $r): ?>
                <tr>
                    <?php foreach ($r['cells'] as $i => $v): ?>
                    <td class="<?= $i > 0 && $i <= count(MR_COLS) ? 'text-mono' : '' ?>"><?= htmlspecialchars((string)$v) ?></td>
                    <?php endforeach; ?>
                    <td><a href="#risk-map" data-cell="<?= htmlspecialchars($r['cell']) ?>" class="badge badge-primary">Ver no mapa</a></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <p class="mr-nota">
        <?= htmlspecialchars($t['note']) ?>
        <?php if (count($t['rows']) > MR_TOP_LOCAIS): ?>
        Aparecem os <?= MR_TOP_LOCAIS ?> primeiros de <?= count($t['rows']) ?>; a exportação traz todos.
        <?php endif; ?>
    </p>
</div>
<?php endif; ?>

<?php elseif ($tab === 'quando'): ?>
<div class="card mb-24" style="padding:16px 20px;">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);">Índice por faixa do dia</h3>
    <div class="chart-box"><canvas id="mr-chart-periodo"></canvas></div>
</div>
<?php foreach ($tables as $t) mr_render_table($t); ?>
<div class="card mb-24" style="padding:16px 20px;overflow-x:auto;">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);margin-bottom:10px;">Alertas por hora e dia da semana</h3>
    <table class="mr-grid">
        <thead><tr><th></th><?php for ($h = 0; $h < 24; $h++): ?><th><?= $h ?></th><?php endfor; ?></tr></thead>
        <tbody>
            <?php foreach (RISK_WEEKDAY_LABELS as $wd => $label): ?>
            <tr>
                <th><?= $label ?></th>
                <?php for ($h = 0; $h < 24; $h++):
                    $cell  = $grid[$wd][$h] ?? mr_zero();
                    $ci    = $cell['driving_s'] >= RISK_MIN_CELL_S ? mr_index($cell) : null;
                    $alpha = ($ci !== null && $gridMax > 0) ? max(0.08, $ci / $gridMax) : 0;
                    $title = sprintf('%s, %d h: %d alerta(s), %s h dirigidas, índice %s',
                        $label, $h, $cell['alerts'], mr_hours($cell['driving_s']), $ci === null ? 'com pouca exposição' : mr_num($ci));
                ?>
                <td title="<?= htmlspecialchars($title) ?>" style="background:rgba(207,32,47,<?= number_format($alpha, 2, '.', '') ?>);color:<?= $alpha > 0.55 ? '#fff' : 'var(--ink)' ?>;">
                    <?= $cell['alerts'] > 0 ? $cell['alerts'] : '' ?>
                </td>
                <?php endfor; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p class="mr-nota">O número é a quantidade de alertas; a cor, o índice — só onde houve pelo menos <?= RISK_MIN_CELL_S / 60 ?> min de direção naquele horário.</p>
</div>

<?php elseif ($tab === 'jornada'): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:24px;">
    <div class="card" style="padding:16px 20px;">
        <h3 style="font-size:15px;font-weight:600;color:var(--ink);">Índice por direção contínua</h3>
        <div class="chart-box"><canvas id="mr-chart-jornada"></canvas></div>
    </div>
    <div class="card" style="padding:16px 20px;">
        <h3 style="font-size:15px;font-weight:600;color:var(--ink);">Índice por velocidade</h3>
        <div class="chart-box"><canvas id="mr-chart-velocidade"></canvas></div>
    </div>
</div>
<?php foreach ($tables as $t) mr_render_table($t); ?>

<?php elseif ($tab === 'quem'): ?>
<?php foreach ($tables as $t) mr_render_table($t); ?>
<div class="card mb-24" style="padding:16px 20px;">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);margin-bottom:10px;">Reincidência</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Veículo</th><th>Motorista</th><th>Comportamento</th><th>Vezes</th><th>Pontos</th><th>Primeira</th><th>Última</th></tr></thead>
            <tbody>
                <?php if (!$reincidencia): ?>
                <tr><td colspan="7" style="text-align:center;padding:28px;color:var(--muted);">Nenhum comportamento repetido <?= RISK_REINCIDENCE_MIN ?> vezes ou mais no período</td></tr>
                <?php else: foreach ($reincidencia as $rr): ?>
                <tr>
                    <td class="text-mono"><?= htmlspecialchars($rr['veiculo']) ?></td>
                    <td><?= htmlspecialchars($rr['motorista']) ?></td>
                    <td><?= htmlspecialchars(risk_group_label($rr['risk_group'])) ?></td>
                    <td class="text-mono"><?= (int)$rr['n'] ?></td>
                    <td class="text-mono"><?= (int)$rr['pts'] ?></td>
                    <td class="text-mono"><?= fmt_brt($rr['primeiro'], 'd/m/Y H:i') ?></td>
                    <td class="text-mono"><?= fmt_brt($rr['ultimo'], 'd/m/Y H:i') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <p class="mr-nota">Veículo ou motorista com o mesmo comportamento <?= RISK_REINCIDENCE_MIN ?> vezes ou mais no período.</p>
</div>

<?php elseif ($tab === 'tendencia'): ?>
<?php if ($mom):
    $iCur  = mr_index($mom['cur']);
    $iPrev = mr_index($mom['prev']);
    $vMom  = ($iCur !== null && $iPrev !== null && $iPrev > 0) ? ($iCur - $iPrev) * 100 / $iPrev : null;
?>
<div class="card mb-24" style="padding:16px 20px;">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);">
        Mês a mês — <?= htmlspecialchars($mom['curLabel']) ?> contra <?= htmlspecialchars($mom['prevLabel']) ?>, nos primeiros <?= (int)$mom['days'] ?> dia(s)
    </h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-top:10px;">
        <div><div class="filtro-rotulo">Índice <?= htmlspecialchars($mom['curLabel']) ?></div><div class="text-mono" style="font-size:20px;"><?= mr_num($iCur) ?></div></div>
        <div><div class="filtro-rotulo">Índice <?= htmlspecialchars($mom['prevLabel']) ?></div><div class="text-mono" style="font-size:20px;"><?= mr_num($iPrev) ?></div></div>
        <div><div class="filtro-rotulo">Variação</div>
            <div class="text-mono" style="font-size:20px;color:<?= $vMom === null ? 'var(--muted)' : ($vMom > 0 ? 'var(--error)' : 'var(--success)') ?>;">
                <?= $vMom === null ? '—' : (($vMom > 0 ? '+' : '−') . number_format(abs($vMom), 0, ',', '.') . '%') ?>
            </div>
        </div>
        <div><div class="filtro-rotulo">Alertas</div><div class="text-mono" style="font-size:20px;"><?= (int)$mom['cur']['alerts'] ?> × <?= (int)$mom['prev']['alerts'] ?></div></div>
    </div>
</div>
<?php endif; ?>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:24px;">
    <div class="card" style="padding:16px 20px;">
        <h3 style="font-size:15px;font-weight:600;color:var(--ink);">Índice por semana</h3>
        <div class="chart-box"><canvas id="mr-chart-semanas"></canvas></div>
    </div>
    <div class="card" style="padding:16px 20px;">
        <h3 style="font-size:15px;font-weight:600;color:var(--ink);">Índice por mês</h3>
        <div class="chart-box"><canvas id="mr-chart-meses"></canvas></div>
    </div>
</div>
<p class="mr-nota" style="margin-bottom:16px;">A tendência ignora o filtro de período e respeita os demais filtros.</p>
<?php foreach ($tables as $t) mr_render_table($t); ?>
<?php endif; ?>

<script>
(function () {
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    <?php if ($tab === 'onde' && $mapCells): ?>
    var cells = <?= json_encode($mapCells, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var el = document.getElementById('risk-map');
    var map = L.map(el, { preferCanvas: true });
    bcMapBaseLayers(map);
    var bounds = [];
    var byKey = {};
    cells.forEach(function (c) {
        var rect = L.rectangle([[c.b[0], c.b[1]], [c.b[2], c.b[3]]], {
            color: c.c, weight: 1, fillColor: c.c, fillOpacity: c.ok ? 0.45 : 0.25
        }).addTo(map);
        rect.bindPopup(
            '<strong>' + esc(c.addr) + '</strong><br>' +
            'Alertas: ' + esc(c.a) + ' · Pontos: ' + esc(c.p) + '<br>' +
            'Horas dirigidas: ' + esc(c.h) + ' · Índice: ' + esc(c.i) +
            (c.ok ? '' : ' <em>(pouca exposição)</em>') +
            (c.top ? '<br>' + esc(c.top) : '')
        );
        bounds.push([c.b[0], c.b[1]], [c.b[2], c.b[3]]);
        byKey[c.k] = rect;
    });
    if (bounds.length) {
        map.fitBounds(bounds, { padding: [20, 20] });
    } else {
        map.setView([-15.78, -47.93], 4);
    }
    document.querySelectorAll('[data-cell]').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            var rect = byKey[a.getAttribute('data-cell')];
            if (!rect) return;
            ev.preventDefault();
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            map.fitBounds(rect.getBounds(), { maxZoom: 15 });
            rect.openPopup();
        });
    });
    setTimeout(function () { map.invalidateSize(); }, 200);
    <?php endif; ?>

    <?php if ($charts): ?>
    var charts = <?= json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    Object.keys(charts).forEach(function (key) {
        var canvas = document.getElementById('mr-chart-' + key);
        if (!canvas || typeof Chart === 'undefined') return;
        var line = key === 'semanas' || key === 'meses';
        new Chart(canvas, {
            type: line ? 'line' : 'bar',
            data: {
                labels: charts[key].labels,
                datasets: [{
                    label: 'Índice (pts/h)',
                    data: charts[key].index,
                    backgroundColor: line ? 'rgba(0,82,255,0.12)' : '#0052ff',
                    borderColor: '#0052ff',
                    borderRadius: 4,
                    fill: line,
                    tension: 0.25,
                    spanGaps: true
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { afterLabel: function (ctx) { return 'Alertas: ' + charts[key].alerts[ctx.dataIndex]; } } }
                },
                scales: { y: { beginAtZero: true, grid: { color: '#eef0f3' } }, x: { grid: { display: false } } }
            }
        });
    });
    <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
<?php

/**
 * Filtro comum às duas tabelas do mapa.
 *
 * @param string $alias  e (risk_events) ou x (risk_exposure)
 * @param bool   $events true para risk_events (falso positivo e comportamento só existem lá)
 * @param array  $F      Filtros da tela
 * @param string $from   Dia BRT inicial
 * @param string $to     Dia BRT final
 * @returns array{0:string,1:array} [SQL, parâmetros]
 */
function mr_where(string $alias, bool $events, array $F, string $from, string $to): array
{
    $sql    = "$alias.brt_date BETWEEN :df AND :dt";
    $params = [':df' => $from, ':dt' => $to];
    if ($F['cust'] !== null) {
        $sql .= " AND $alias.customer_id = :cid";
        $params[':cid'] = $F['cust'];
    }
    if ($F['vehicle'] > 0) {
        $sql .= " AND $alias.vehicle_id = :vid";
        $params[':vid'] = $F['vehicle'];
    }
    if ($F['vtype'] !== '') {
        $sql .= " AND $alias.vehicle_id IN (SELECT id FROM vehicles WHERE vehicle_type = :vt)";
        $params[':vt'] = $F['vtype'];
    }
    if ($F['driver'] === 'nd') {
        $sql .= $events ? " AND $alias.driver_id IS NULL" : " AND $alias.driver_id = 0";
    } elseif ($F['driver'] !== '') {
        $sql .= " AND $alias.driver_id = :drv";
        $params[':drv'] = (int)$F['driver'];
    }
    if ($events) {
        $sql .= " AND $alias.false_positive = 0";
        if ($F['group'] !== '') {
            $sql .= " AND $alias.risk_group = :rg";
            $params[':rg'] = $F['group'];
        }
    }
    return [$sql, $params];
}

/**
 * @returns array Métricas zeradas
 */
function mr_zero(): array
{
    return ['pts' => 0, 'alerts' => 0, 'driving_s' => 0, 'idle_s' => 0, 'km' => 0.0];
}

/**
 * Pontos (risk_events) e exposição (risk_exposure) agregados pela mesma dimensão.
 *
 * O GROUP BY repete a EXPRESSÃO, nunca o apelido (CLAUDE.md, ONLY_FULL_GROUP_BY).
 *
 * @param PDO         $db
 * @param array       $F      Filtros
 * @param string      $from   Dia BRT inicial
 * @param string      $to     Dia BRT final
 * @param string      $dimE   Dimensão em risk_events (alias e)
 * @param string|null $dimX   Dimensão equivalente em risk_exposure (alias x); null = sem exposição
 * @param string      $extraE Condição extra só para risk_events
 * @returns array<string, array>
 */
function mr_agg(PDO $db, array $F, string $from, string $to, string $dimE, ?string $dimX, string $extraE = ''): array
{
    $out = [];
    [$w, $p] = mr_where('e', true, $F, $from, $to);
    $stmt = $db->prepare("SELECT $dimE AS k, SUM(e.weight) AS pts, COUNT(*) AS alerts
                          FROM risk_events e WHERE $w $extraE GROUP BY $dimE");
    $stmt->execute($p);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(string)$r['k']] = ['pts' => (int)$r['pts'], 'alerts' => (int)$r['alerts']] + mr_zero();
    }
    if ($dimX !== null) {
        [$w, $p] = mr_where('x', false, $F, $from, $to);
        $stmt = $db->prepare("SELECT $dimX AS k, SUM(x.driving_s) AS driving_s, SUM(x.idle_s) AS idle_s, SUM(x.km) AS km
                              FROM risk_exposure x WHERE $w GROUP BY $dimX");
        $stmt->execute($p);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = (string)$r['k'];
            $out[$k] = $out[$k] ?? mr_zero();
            $out[$k]['driving_s'] = (int)$r['driving_s'];
            $out[$k]['idle_s']    = (int)$r['idle_s'];
            $out[$k]['km']        = (float)$r['km'];
        }
    }
    return $out;
}

/**
 * Os comportamentos que mais somam pontos em cada valor de uma dimensão.
 *
 * @param PDO    $db
 * @param array  $F
 * @param string $from
 * @param string $to
 * @param string $dimE   Dimensão em risk_events
 * @param string $extraE Condição extra
 * @param int    $limit
 * @returns array<string,string> valor da dimensão → "Fadiga (12), Distração (5)"
 */
function mr_top_groups(PDO $db, array $F, string $from, string $to, string $dimE, string $extraE = '', int $limit = 3): array
{
    [$w, $p] = mr_where('e', true, $F, $from, $to);
    $stmt = $db->prepare("SELECT $dimE AS k, e.risk_group AS g, COUNT(*) AS n, SUM(e.weight) AS pts
                          FROM risk_events e WHERE $w $extraE GROUP BY $dimE, e.risk_group");
    $stmt->execute($p);
    $by = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $by[(string)$r['k']][] = ['g' => $r['g'], 'n' => (int)$r['n'], 'pts' => (int)$r['pts']];
    }
    $out = [];
    foreach ($by as $k => $list) {
        usort($list, fn($a, $b) => [$b['pts'], $b['n']] <=> [$a['pts'], $a['n']]);
        $out[$k] = implode(', ', array_map(
            fn($x) => risk_group_label($x['g']) . ' (' . $x['n'] . ')',
            array_slice($list, 0, $limit)
        ));
    }
    return $out;
}

/**
 * @param array $r Métricas
 * @returns float|null Pontos por hora em movimento
 */
function mr_index(array $r): ?float
{
    return $r['driving_s'] > 0 ? $r['pts'] * 3600 / $r['driving_s'] : null;
}

/**
 * @param array $r Métricas
 * @returns float|null Pontos por 100 km
 */
function mr_per100(array $r): ?float
{
    return $r['km'] > 0 ? $r['pts'] * 100 / $r['km'] : null;
}

/**
 * @param float|null $v
 * @param int        $dec
 * @returns string Número no formato brasileiro, ou travessão
 */
function mr_num(?float $v, int $dec = 2): string
{
    return $v === null ? '—' : number_format($v, $dec, ',', '.');
}

/**
 * @param int $seconds
 * @returns string Horas com uma casa
 */
function mr_hours(int $seconds): string
{
    return number_format($seconds / 3600, 1, ',', '.');
}

/**
 * As colunas de métrica (MR_COLS) de uma linha.
 *
 * @param array $r Métricas
 * @returns string[]
 */
function mr_cells(array $r): array
{
    return [
        number_format($r['alerts'], 0, ',', '.'),
        number_format($r['pts'], 0, ',', '.'),
        mr_hours($r['driving_s']),
        mr_num($r['km'], 1),
        mr_num(mr_index($r)),
        mr_num(mr_per100($r)),
    ];
}

/**
 * Estrutura única de tabela, lida pela tela E pela exportação.
 *
 * @param string $title
 * @param array  $headers
 * @param array  $rows    [['cells' => [...], …], …]
 * @param string $note
 * @returns array
 */
function mr_table(string $title, array $headers, array $rows, string $note = ''): array
{
    return ['title' => $title, 'headers' => $headers, 'rows' => $rows, 'note' => $note];
}

/**
 * Linhas de ranking: quem tem exposição suficiente, por índice; depois quem não
 * tem, por pontos e marcado — aparece, mas não disputa a posição.
 *
 * @param array    $agg    Métricas por chave
 * @param callable $label  Chave → rótulo
 * @param int      $minS   Exposição mínima em segundos
 * @returns array
 */
function mr_ranked_rows(array $agg, callable $label, int $minS): array
{
    $ok  = [];
    $low = [];
    foreach ($agg as $k => $r) {
        if ($r['alerts'] === 0 && $r['driving_s'] === 0) {
            continue;
        }
        if ($r['driving_s'] >= $minS) {
            $ok[$k] = $r;
        } else {
            $low[$k] = $r;
        }
    }
    uasort($ok, fn($a, $b) => [mr_index($b), $b['pts']] <=> [mr_index($a), $a['pts']]);
    uasort($low, fn($a, $b) => $b['pts'] <=> $a['pts']);
    $rows = [];
    foreach ($ok as $k => $r) {
        $rows[] = ['cells' => array_merge([(string)$label((string)$k)], mr_cells($r), [''])];
    }
    foreach ($low as $k => $r) {
        $rows[] = ['cells' => array_merge([(string)$label((string)$k)], mr_cells($r), ['Pouca exposição'])];
    }
    return $rows;
}

/**
 * Tabela genérica da tela.
 *
 * @param array $t mr_table()
 * @returns void
 */
function mr_render_table(array $t): void
{
    $statusCol = array_search('Situação', $t['headers'], true);
    ?>
<div class="card mb-24" style="padding:16px 20px;">
    <h3 style="font-size:15px;font-weight:600;color:var(--ink);margin-bottom:10px;"><?= htmlspecialchars($t['title']) ?></h3>
    <div class="table-wrap">
        <table>
            <thead><tr><?php foreach ($t['headers'] as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
                <?php if (!$t['rows']): ?>
                <tr><td colspan="<?= count($t['headers']) ?>" style="text-align:center;padding:28px;color:var(--muted);">Sem dados no período</td></tr>
                <?php else: foreach ($t['rows'] as $r): ?>
                <tr>
                    <?php foreach ($r['cells'] as $i => $v): ?>
                    <?php if ($i === $statusCol): ?>
                    <td><?= $v !== '' ? '<span class="badge badge-warning">' . htmlspecialchars($v) . '</span>' : '' ?></td>
                    <?php else: ?>
                    <td class="<?= $i > 0 && $i <= count(MR_COLS) ? 'text-mono' : '' ?>"><?= htmlspecialchars((string)$v) ?></td>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($t['note'] !== ''): ?><p class="mr-nota"><?= htmlspecialchars($t['note']) ?></p><?php endif; ?>
</div>
    <?php
}

/**
 * Série de gráfico: índice e alertas na ordem das chaves.
 *
 * @param array $labels
 * @param array $keys
 * @param array $agg
 * @returns array{labels:array,index:array,alerts:array}
 */
function mr_chart(array $labels, array $keys, array $agg): array
{
    $index  = [];
    $alerts = [];
    foreach ($keys as $k) {
        $r        = $agg[(string)$k] ?? mr_zero();
        $i        = mr_index($r);
        $index[]  = $i === null ? null : round($i, 2);
        $alerts[] = $r['alerts'];
    }
    return ['labels' => array_values($labels), 'index' => $index, 'alerts' => $alerts];
}

/**
 * Cortes dos terços do índice entre as células que entram no ranking.
 *
 * @param float[] $values
 * @returns array{0:float,1:float}
 */
function mr_terciles(array $values): array
{
    sort($values);
    $n = count($values);
    if ($n === 0) {
        return [0.0, 0.0];
    }
    return [(float)$values[(int)floor(($n - 1) / 3)], (float)$values[(int)floor(2 * ($n - 1) / 3)]];
}

/**
 * @param float|null $index
 * @param bool       $eligible Tem exposição suficiente
 * @param array      $cuts     mr_terciles()
 * @returns string Cor
 */
function mr_color(?float $index, bool $eligible, array $cuts): string
{
    if (!$eligible || $index === null) {
        return MR_COLORS['insuficiente'];
    }
    if ($index <= $cuts[0]) {
        return MR_COLORS['baixo'];
    }
    return $index <= $cuts[1] ? MR_COLORS['medio'] : MR_COLORS['alto'];
}

/**
 * @param string $key "cell_y:cell_x"
 * @returns array{0:float,1:float} Centro da célula
 */
function mr_cell_center_key(string $key): array
{
    [$y, $x] = array_map('intval', explode(':', $key));
    return risk_cell_center($y, $x);
}

/**
 * @param string $date 'Y-m-d'
 * @param int    $days
 * @returns string
 */
function mr_shift(string $date, int $days): string
{
    return gmdate('Y-m-d', strtotime($date . ' 00:00:00 UTC') + $days * 86400);
}

/**
 * @param string $from
 * @param string $to
 * @returns int Dias corridos, contando os dois extremos
 */
function mr_span(string $from, string $to): int
{
    return intdiv(strtotime($to . ' 00:00:00 UTC') - strtotime($from . ' 00:00:00 UTC'), 86400) + 1;
}

/**
 * @param PDO   $db
 * @param array $ids
 * @returns array<int, array{plate:string,vehicle_type:?string}>
 */
function mr_vehicle_info(PDO $db, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    $out = [];
    foreach ($db->query("SELECT id, plate, vehicle_type FROM vehicles WHERE id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

/**
 * @param PDO   $db
 * @param array $ids
 * @returns array<int,string>
 */
function mr_driver_names(PDO $db, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) {
        return [];
    }
    return array_map('strval', $db->query("SELECT id, name FROM drivers WHERE id IN (" . implode(',', $ids) . ")")->fetchAll(PDO::FETCH_KEY_PAIR));
}

/**
 * Veículos do seletor, no escopo da tela.
 *
 * @param PDO      $db
 * @param int|null $cust null só para admin de plataforma (todos)
 * @returns array
 */
function mr_vehicle_options(PDO $db, ?int $cust): array
{
    if ($cust !== null) {
        $stmt = $db->prepare("SELECT id, plate FROM vehicles WHERE customer_id = :c AND is_active = 1 ORDER BY plate LIMIT 2000");
        $stmt->execute([':c' => $cust]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $db->query("SELECT id, plate FROM vehicles WHERE is_active = 1 ORDER BY plate LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Motoristas do seletor, no escopo da tela.
 *
 * @param PDO      $db
 * @param int|null $cust
 * @returns array
 */
function mr_driver_options(PDO $db, ?int $cust): array
{
    if ($cust !== null) {
        $stmt = $db->prepare("SELECT id, name FROM drivers WHERE customer_id = :c ORDER BY name LIMIT 2000");
        $stmt->execute([':c' => $cust]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $db->query("SELECT id, name FROM drivers ORDER BY name LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC);
}
