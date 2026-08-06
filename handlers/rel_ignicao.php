<?php
/**
 * JIMI Webhook System — Relatório de Ignição v4.6.0
 * Rota: /relatorios/ignicao
 *
 * Acionamentos de ignição (ligada / desligada) derivados de
 * device_state_segments com função de janela.
 *
 * Uma transição de ignição é a fronteira entre dois segmentos cujo estado do
 * MOTOR difere: `parado` é motor desligado, `movimento` e `ocioso` são motor
 * ligado. A troca movimento↔ocioso não é acionamento de ignição — o motor
 * seguiu ligado, o veículo só parou de andar.
 *
 * Segmentos `offline` são EXCLUÍDOS da janela de propósito. Durante o silêncio
 * do rastreador não se sabe o que a ignição fez; comparar o último estado
 * conhecido com o próximo estado conhecido é a única leitura defensável.
 * Incluir o offline inventaria dois acionamentos (desligou ao sumir, ligou ao
 * voltar) que ninguém observou.
 *
 * Os dados vêm do scripts/state_builder.php (cron a cada 15 min).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/geocode.php';   // endereço no lugar de lat/lng
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('rel_ignicao', '/relatorios/ignicao');

require_once __DIR__ . '/../includes/fleet_state.php';

$page_title    = 'Relatório de Ignição';
$current_route = 'rel_ignicao';

$db         = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user       = get_jimi_user();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$dateFrom = $_GET['date_from'] ?? brt_today();
$dateTo   = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo);

$filterCust  = $_GET['customer_id'] ?? null;
$filterImei  = trim($_GET['imei'] ?? '');
$filterEvent = in_array($_GET['event'] ?? '', ['ligada', 'desligada'], true) ? $_GET['event'] : '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 25;

// A chave 'imei' continua na URL (links e modelos salvos a carregam), mas
// ordena pela PLACA, que é o que a coluna exibe.
$validSorts = ['started_at', 'imei'];
[$sort, $order] = report_sort_params($validSorts, 'started_at', 'ASC');
$orderBy = $sort === 'imei' ? "device_label $order" : "x.$sort $order";

[$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo);

// A janela interna começa 2 dias antes do filtro para que o LAG do primeiro
// segmento do período tenha um anterior de verdade. Sem essa folga, a primeira
// transição do período seria perdida sempre que o segmento anterior a ela
// tivesse começado antes da meia-noite do dia inicial — o caso comum de
// veículo que passou a noite desligado.
$wideFrom = gmdate('Y-m-d H:i:s', strtotime($utcFrom . ' -2 days'));

$innerWhere  = "WHERE s.state <> 'offline' AND s.started_at BETWEEN :wf AND :dt";
$params = [':wf' => $wideFrom, ':dt' => $utcTo, ':df2' => $utcFrom, ':dt2' => $utcTo];

// Escopo multi-tenant centralizado (v4.7.3) — ver report_customer_scope().
// Resolvido UMA vez e reusado nas duas consultas desta tela (a da grade e a
// do tempo agregado, mais abaixo): se divergirem, os dois números da tela
// deixam de fechar, que é justamente o teste de aceite da segmentação.
$scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);
if ($scopeCust !== null) {
    $innerWhere .= ' AND s.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}
// Igualdade, não LIKE: a placa virou seleção (v4.9.0)
if ($filterImei !== '') {
    $innerWhere .= ' AND s.imei = :imei';
    $params[':imei'] = $filterImei;
}

// Uma transição é o INÍCIO do segmento cujo estado de motor difere do anterior.
// `state = 'parado'` é o predicado "motor desligado"; comparar os dois lados com
// <> equivale a um XOR e ignora movimento↔ocioso.
// Sem JOIN em `customers`: a coluna Cliente saiu da grade e dos exports
// (v4.9.0) — a tela já roda dentro de um cliente (o seletor do topo e o filtro
// deste formulário), e repetir o mesmo nome em toda linha só gastava largura.
$sqlInner = "
    SELECT s.imei, s.customer_id, s.state, s.started_at, s.ended_at, s.duration_s,
           s.start_lat, s.start_lng, s.distance_km, s.max_speed,
           COALESCE(d.device_name, s.imei) AS device_label,
           LAG(s.state) OVER w AS prev_state
    FROM device_state_segments s
    LEFT JOIN devices d ON d.imei = s.imei
    $innerWhere
    WINDOW w AS (PARTITION BY s.imei ORDER BY s.started_at)";

$sqlTrans = "
    SELECT t.*,
           CASE WHEN t.state = 'parado' THEN 'desligada' ELSE 'ligada' END AS event_type,
           COALESCE(t.duration_s, TIMESTAMPDIFF(SECOND, t.started_at, UTC_TIMESTAMP())) AS dur_s
    FROM ($sqlInner) t
    WHERE t.prev_state IS NOT NULL
      AND (t.state = 'parado') <> (t.prev_state = 'parado')
      AND t.started_at BETWEEN :df2 AND :dt2";

if ($filterEvent !== '') {
    $sqlTrans .= $filterEvent === 'desligada'
        ? " AND t.state = 'parado'"
        : " AND t.state <> 'parado'";
}

// ── Export síncrono ────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';

    $expRows = [];
    try {
        $stmt = $db->prepare("SELECT * FROM ($sqlTrans) x ORDER BY $orderBy LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $stmt->execute($params);
        // fetchAll antes do laço: endereço resolvido em UM lote paralelo
        $src = $stmt->fetchAll();
        $geoExp = geocode_map_rows($src, 'start_lat', 'start_lng', 2000);
        foreach ($src as $r) {
            $expRows[] = [
                $r['device_label'],
                fmt_brt($r['started_at'], 'd/m/Y H:i:s'),
                $r['event_type'] === 'ligada' ? 'Ignição ligada' : 'Ignição desligada',
                fmt_duration((int)$r['dur_s']),
                geocode_cell($geoExp, $r['start_lat'], $r['start_lng']),
                export_map_link($r['start_lat'], $r['start_lng']),
            ];
        }
    } catch (Throwable $e) { /* tabela ausente → export vazio */ }

    // Mesma ordem da grade: a PLACA é a primeira coluna — é por ela que o
    // leitor procura a linha. IMEI e Cliente saíram (v4.9.0).
    stream_export($export, 'relatorio_ignicao',
        ['Placa', 'Data/Hora', 'Evento', 'Permanência no estado', 'Endereço', 'Mapa'],
        $expRows, 'Relatório de Ignição', report_period_label($dateFrom, $dateTo),
        [1.0, 1.35, 1.2, 1.2, 3.4, 0.6]);
}

// ── Grade + KPIs ───────────────────────────────────────────────
$tableMissing = false;
$rows       = [];
$totalRows  = 0;
$totalPages = 1;
$kpi = ['ligada' => 0, 'desligada' => 0, 'on_s' => 0, 'off_s' => 0, 'devices' => 0, 'paradas' => 0];

try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM ($sqlTrans) c");
    $countStmt->execute($params);
    $totalRows  = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    $offset     = ($page - 1) * $perPage;

    $dataStmt = $db->prepare("SELECT * FROM ($sqlTrans) x ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll();

    $kpiStmt = $db->prepare("
        SELECT SUM(x.event_type = 'ligada')    AS ligada,
               SUM(x.event_type = 'desligada') AS desligada,
               COUNT(DISTINCT x.imei)          AS devices
        FROM ($sqlTrans) x");
    $kpiStmt->execute($params);
    if ($k = $kpiStmt->fetch()) {
        $kpi['ligada']    = (int)$k['ligada'];
        $kpi['desligada'] = (int)$k['desligada'];
        $kpi['devices']   = (int)$k['devices'];
    }

    // Tempo de motor ligado × desligado no período, direto dos segmentos.
    // `paradas` é contado aqui para poder ser comparado, na própria tela, com
    // a contagem de desligamentos: os dois números têm de fechar, e é esse o
    // teste de aceite da segmentação.
    $timeWhere  = 'WHERE s.started_at BETWEEN :df3 AND :dt3';
    $timeParams = [':df3' => $utcFrom, ':dt3' => $utcTo];
    // Mesmo $scopeCust da consulta da grade, de propósito (ver acima)
    if ($scopeCust !== null) {
        $timeWhere .= ' AND s.customer_id = :cid3';
        $timeParams[':cid3'] = $scopeCust;
    }
    if ($filterImei !== '') {
        $timeWhere .= ' AND s.imei LIKE :imei3';
        $timeParams[':imei3'] = "%$filterImei%";
    }

    $timeStmt = $db->prepare("
        SELECT COALESCE(SUM(CASE WHEN s.state IN ('movimento','ocioso')
                   THEN COALESCE(s.duration_s, TIMESTAMPDIFF(SECOND, s.started_at, UTC_TIMESTAMP())) END), 0) AS on_s,
               COALESCE(SUM(CASE WHEN s.state = 'parado'
                   THEN COALESCE(s.duration_s, TIMESTAMPDIFF(SECOND, s.started_at, UTC_TIMESTAMP())) END), 0) AS off_s,
               COALESCE(SUM(s.state = 'parado'), 0) AS paradas
        FROM device_state_segments s
        $timeWhere");
    $timeStmt->execute($timeParams);
    if ($t = $timeStmt->fetch()) {
        $kpi['on_s']    = (int)$t['on_s'];
        $kpi['off_s']   = (int)$t['off_s'];
        $kpi['paradas'] = (int)$t['paradas'];
    }
} catch (Throwable $e) {
    $tableMissing = true;
}

$customers = [];
$devices   = [];
try {
    $customers = report_customer_options($db);
    $devices   = report_device_options($db, $scopeCust);
} catch (Throwable $e) {}

require_once __DIR__ . '/../web/layout_base.php';
?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Ignição</h2>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?php if (report_has_query()) echo report_back_button('/relatorios/ignicao'); ?>
    </div>
</div>

<?php if ($tableMissing): ?>
<div class="card mb-16" style="padding:12px 16px;border-left:3px solid var(--error);">
    <div style="font-size:13px;color:var(--muted);">
        <strong>Tabela de segmentos indisponível.</strong> Aplique a migração <code>v4.6.0</code>
        e rode <code>php scripts/state_builder.php 30</code> para preencher o histórico.
    </div>
</div>
<?php endif; ?>

<?php render_template_bar('rel_ignicao', '/relatorios/ignicao'); ?>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <?php if ($isAdmin): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $filterCust == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Placa</label>
            <?= report_device_select($devices, $filterImei) ?>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Evento</label>
            <select name="event" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="ligada"    <?= $filterEvent === 'ligada' ? 'selected' : '' ?>>Ignição ligada</option>
                <option value="desligada" <?= $filterEvent === 'desligada' ? 'selected' : '' ?>>Ignição desligada</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Período (máx. <?= REPORT_RANGE_MAX_DAYS ?> dias)</label>
            <div style="display:flex;gap:4px;">
                <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<?php if ($rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid #f5a623;font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias:
    <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px;">
    <?php
    $kpiCards = [
        ['Ignições ligadas',    (string)$kpi['ligada']],
        ['Ignições desligadas', (string)$kpi['desligada']],
        ['Motor ligado',        fmt_duration($kpi['on_s'], '0min')],
        ['Motor desligado',     fmt_duration($kpi['off_s'], '0min')],
        ['Equipamentos',        (string)$kpi['devices']],
    ];
    foreach ($kpiCards as [$label, $value]):
    ?>
    <div class="card" style="padding:14px 18px;">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);"><?= $label ?></div>
        <div class="text-mono" style="font-size:22px;font-weight:500;color:var(--ink);margin-top:4px;"><?= htmlspecialchars($value) ?></div>
    </div>
    <?php endforeach; ?>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th><?= report_sort_link('imei', 'Placa', $sort, $order) ?></th>
                <th><?= report_sort_link('started_at', 'Data/Hora', $sort, $order) ?></th>
                <th>Evento</th>
                <th>Permanência no estado</th>
                <th>Mapa</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--muted);">Nenhum acionamento de ignição no período</td></tr>
            <?php else: foreach ($rows as $r):
                $hasCoords = $r['start_lat'] && $r['start_lng']
                          && is_valid_coordinate($r['start_lat'], $r['start_lng']);
            ?>
            <tr>
                <td class="text-mono"><?= htmlspecialchars($r['device_label']) ?></td>
                <td class="text-mono"><?= fmt_brt($r['started_at'], 'd/m/Y H:i:s') ?></td>
                <td>
                    <?php if ($r['event_type'] === 'ligada'): ?>
                        <span class="badge badge-success">Ignição ligada</span>
                    <?php else: ?>
                        <span class="badge">Ignição desligada</span>
                    <?php endif; ?>
                </td>
                <td class="text-mono">
                    <?= htmlspecialchars(fmt_duration((int)$r['dur_s'])) ?>
                    <?php if ($r['ended_at'] === null): ?>
                        <span class="badge badge-info">em curso</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($hasCoords): ?>
                    <a href="https://www.openstreetmap.org/?mlat=<?= $r['start_lat'] ?>&mlon=<?= $r['start_lng'] ?>&zoom=16"
                       target="_blank" class="badge badge-primary">Ver Mapa</a>
                    <?php else: echo '—'; endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, 'acionamentos') ?>

<p class="text-muted" style="font-size:11px;margin-top:8px;">
    “Permanência no estado” é quanto tempo o veículo ficou no estado que o acionamento abriu.
    A troca entre <strong>movimento</strong> e <strong>ocioso</strong> não é acionamento de ignição —
    nos dois o motor está ligado. Períodos sem comunicação são ignorados na comparação: durante o
    silêncio do rastreador não há como afirmar o que a ignição fez.
    <?php if (!$tableMissing && $kpi['paradas'] > 0): ?>
    <br>No período há <strong class="text-mono"><?= (int)$kpi['paradas'] ?></strong> período(s) de ignição
    desligada em <a href="/relatorios/paradas?<?= htmlspecialchars($expBase) ?>">Paradas</a> — número que
    fecha com os desligamentos acima quando o veículo não passou o início do período já desligado.
    <?php endif; ?>
</p>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
