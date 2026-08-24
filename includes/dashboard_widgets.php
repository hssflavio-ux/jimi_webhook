<?php
/**
 * JIMI Webhook System — Catálogo de Widgets do Painel v4.10.3
 * Arquivo: includes/dashboard_widgets.php
 *
 * Item 7 do docs/PLANO_IMPLEMENTACAO_v4.10.md: widgets reaproveitando as
 * MESMAS consultas de `handlers/resumo.php` — não uma chamada a esse arquivo
 * (que mistura dado e HTML numa página inteira, sem funções extraídas para
 * reuso) — para `handlers/painel.php`, a tela nova e paralela ao Resumo.
 * `resumo.php` não é tocado por este arquivo em nenhuma hipótese.
 */

/** Catálogo: chave => rótulo (picker de edição) + tamanho no grid (sm=3/12, md=6/12, lg=12/12). */
const DASHBOARD_WIDGETS = [
    'kpi_devices'      => ['label' => 'Equipamentos',                    'size' => 'sm'],
    'kpi_connectivity' => ['label' => 'Conectividade',                   'size' => 'sm'],
    'kpi_occurrences'  => ['label' => 'Ocorrências',                     'size' => 'sm'],
    'kpi_outdated'     => ['label' => 'Desatualizados',                  'size' => 'sm'],
    'heatmap'          => ['label' => 'Mapa de Posições Recentes',       'size' => 'lg'],
    'speed_dist'       => ['label' => 'Velocidade da Frota',             'size' => 'md'],
    'idle'             => ['label' => 'Ociosidade',                      'size' => 'sm'],
    'model_status'     => ['label' => 'Status de Equipamentos por Modelo','size' => 'md'],
    'ts_alarms'        => ['label' => 'Alarmes (série temporal)',        'size' => 'md'],
    'ts_occurrences'   => ['label' => 'Ocorrências (série temporal)',    'size' => 'md'],
    'top_plates'       => ['label' => 'Top placas com mais alarmes',     'size' => 'md'],
    'top_drivers'      => ['label' => 'Top motoristas com mais alarmes', 'size' => 'md'],
    'reseller_view'     => ['label' => 'Visão por Clientes (revendedor)', 'size' => 'lg', 'reseller_only' => true],
];

/** Layout inicial para quem nunca editou o painel e não há padrão global gravado. */
const DASHBOARD_DEFAULT_LAYOUT = [
    'kpi_devices', 'kpi_connectivity', 'kpi_occurrences', 'kpi_outdated',
    'heatmap', 'speed_dist', 'model_status', 'ts_alarms', 'ts_occurrences',
];

/**
 * Rótulo de um widget do catálogo.
 *
 * @param string $key Chave de DASHBOARD_WIDGETS
 * @returns string
 */
function dashboard_widget_label(string $key): string
{
    return DASHBOARD_WIDGETS[$key]['label'] ?? $key;
}

/**
 * Catálogo pronto para `json_encode()` — usado só pelo picker de edição.
 *
 * @returns array
 */
function dashboard_widget_catalog(): array
{
    return DASHBOARD_WIDGETS;
}

/**
 * Layout efetivo do usuário: o próprio → padrão global (`user_id IS NULL`,
 * único no sistema, ver mysql/migration_v4.10.3.sql) → catálogo hardcoded.
 *
 * @param PDO $db    Conexão ativa
 * @param int $userId `users.id` da sessão
 * @returns string[] Lista ordenada de chaves de DASHBOARD_WIDGETS
 */
function dashboard_resolve_layout(PDO $db, int $userId): array
{
    try {
        $stmt = $db->prepare("SELECT layout FROM dashboard_layouts WHERE user_id = :uid LIMIT 1");
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return dashboard_sanitize_layout(json_decode($row['layout'], true) ?: []);
        }

        $stmt = $db->prepare("SELECT layout FROM dashboard_layouts WHERE user_id IS NULL LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return dashboard_sanitize_layout(json_decode($row['layout'], true) ?: []);
        }
    } catch (Throwable $e) {
        // Tabela ausente (migração não aplicada ainda) — degrada para o catálogo.
    }
    return DASHBOARD_DEFAULT_LAYOUT;
}

/**
 * Whitelist: só chaves que existem no catálogo, sem repetição, na ordem dada.
 *
 * Ponto ÚNICO de validação — chamado tanto na leitura (`dashboard_resolve_layout`,
 * contra o que já está gravado) quanto na escrita (`handlers/dashboarddata.php`,
 * contra o que o usuário acabou de mandar). Uma chave que saiu do catálogo (ex.:
 * widget removido numa versão futura) não pode sobreviver silenciosamente numa
 * das duas pontas e não na outra.
 *
 * @param array $keys Lista bruta (de JSON gravado ou do corpo do POST)
 * @returns string[]
 */
function dashboard_sanitize_layout(array $keys): array
{
    $seen = [];
    $out = [];
    foreach ($keys as $k) {
        if (!is_string($k) || !array_key_exists($k, DASHBOARD_WIDGETS) || isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $out[] = $k;
    }
    return $out;
}

// ── Fontes de dado compartilhadas (memoizadas por request) ─────────────────
// As mesmas quatro consultas de resumo.php alimentam mais de um widget
// (kpi_devices e kpi_connectivity vêm dos MESMOS quatro números) — memoizar
// evita rodar a query de fallback duas vezes quando os dois aparecem juntos
// no layout do usuário, que é o caso mais comum.

function dashboard_device_kpis(PDO $db, int $cid): array
{
    static $cache = [];
    if (isset($cache[$cid])) return $cache[$cid];

    $get = function ($key) use ($db, $cid) {
        try {
            $stmt = $db->prepare("SELECT metric_value FROM metrics_snapshots WHERE customer_id=:cid AND metric_key=:k ORDER BY snapshot_at DESC LIMIT 1");
            $stmt->execute([':cid' => $cid, ':k' => $key]);
            $v = $stmt->fetchColumn();
            return $v !== false ? (int)$v : 0;
        } catch (Throwable $e) { return 0; }
    };
    $total = $get('devices_total'); $active = $get('devices_active');
    $online = $get('devices_online'); $offline = $get('devices_offline');

    if ($total === 0 && $active === 0 && $online === 0 && $offline === 0) {
        try {
            $stmt = $db->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active,
                       SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, last_communication, NOW()) <= 5 THEN 1 ELSE 0 END) as online,
                       SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, last_communication, NOW()) > 5 THEN 1 ELSE 0 END) as offline
                FROM devices WHERE customer_id = :cid
            ");
            $stmt->execute([':cid' => $cid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $total = (int)($r['total'] ?? 0); $active = (int)($r['active'] ?? 0);
            $online = (int)($r['online'] ?? 0); $offline = (int)($r['offline'] ?? 0);
        } catch (Throwable $e) {}
    }
    return $cache[$cid] = ['total' => $total, 'active' => $active, 'online' => $online, 'offline' => $offline];
}

function dashboard_occurrence_kpis(PDO $db, int $cid): array
{
    static $cache = [];
    if (isset($cache[$cid])) return $cache[$cid];
    $total = 0; $waiting = 0;
    try {
        $stmt = $db->prepare("SELECT metric_value FROM metrics_snapshots WHERE customer_id=:cid AND metric_key='occurrences_total' ORDER BY snapshot_at DESC LIMIT 1");
        $stmt->execute([':cid' => $cid]);
        $v = $stmt->fetchColumn();
        if ($v !== false) $total = (int)$v;
        $stmt = $db->prepare("SELECT metric_value FROM metrics_snapshots WHERE customer_id=:cid AND metric_key='occurrences_waiting' ORDER BY snapshot_at DESC LIMIT 1");
        $stmt->execute([':cid' => $cid]);
        $v = $stmt->fetchColumn();
        if ($v !== false) $waiting = (int)$v;
    } catch (Throwable $e) {}
    if ($total === 0 && $waiting === 0) {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='aguardando' THEN 1 ELSE 0 END) as waiting FROM occurrences WHERE customer_id = :cid");
            $stmt->execute([':cid' => $cid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $total = (int)($r['total'] ?? 0); $waiting = (int)($r['waiting'] ?? 0);
        } catch (Throwable $e) {}
    }
    return $cache[$cid] = ['total' => $total, 'waiting' => $waiting];
}

function dashboard_outdated_kpis(PDO $db, int $cid): array
{
    static $cache = [];
    if (isset($cache[$cid])) return $cache[$cid];
    $keys = ['outdated_lt7d', 'outdated_gt7d', 'outdated_gt30d', 'outdated_never'];
    $out = [];
    foreach ($keys as $k) {
        try {
            $stmt = $db->prepare("SELECT metric_value FROM metrics_snapshots WHERE customer_id=:cid AND metric_key=:k ORDER BY snapshot_at DESC LIMIT 1");
            $stmt->execute([':cid' => $cid, ':k' => $k]);
            $v = $stmt->fetchColumn();
            $out[$k] = $v !== false ? (int)$v : 0;
        } catch (Throwable $e) { $out[$k] = 0; }
    }
    return $cache[$cid] = $out;
}

function dashboard_speed_dist(PDO $db, int $cid): array
{
    $keys = ['speed_parados' => 0, 'speed_ate20' => 0, 'speed_ate60' => 0, 'speed_acima60' => 0];
    foreach (array_keys($keys) as $k) {
        try {
            $stmt = $db->prepare("SELECT metric_value FROM metrics_snapshots WHERE customer_id=:cid AND metric_key=:k ORDER BY snapshot_at DESC LIMIT 1");
            $stmt->execute([':cid' => $cid, ':k' => $k]);
            $v = $stmt->fetchColumn();
            $keys[$k] = $v !== false ? (int)$v : 0;
        } catch (Throwable $e) {}
    }
    if (array_sum($keys) === 0) {
        try {
            $stmt = $db->prepare("
                SELECT SUM(CASE WHEN speed=0 THEN 1 ELSE 0 END) parados,
                       SUM(CASE WHEN speed>0 AND speed<=20 THEN 1 ELSE 0 END) ate20,
                       SUM(CASE WHEN speed>20 AND speed<=60 THEN 1 ELSE 0 END) ate60,
                       SUM(CASE WHEN speed>60 THEN 1 ELSE 0 END) acima60
                FROM gps_data g
                WHERE g.customer_id=:cid AND g.gps_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND g.acc = 1
            ");
            $stmt->execute([':cid' => $cid]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $keys = ['speed_parados' => (int)($r['parados'] ?? 0), 'speed_ate20' => (int)($r['ate20'] ?? 0),
                     'speed_ate60' => (int)($r['ate60'] ?? 0), 'speed_acima60' => (int)($r['acima60'] ?? 0)];
        } catch (Throwable $e) {}
    }
    return $keys;
}

// ── Render: um <div class="widget-body"> por widget, sem o card ao redor
// (o card e a barra de título/edição são de handlers/painel.php, iguais
// para todos os widgets). Widgets com JS embutem o próprio <script>.

function dashboard_render_kpi_devices(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    $k = dashboard_device_kpis($db, $cid);
    return '<div class="kpi-item-value">' . (int)$k['active'] . '/' . (int)$k['total'] . '</div>'
         . '<div class="kpi-item-delta">ativos</div>';
}
function dashboard_render_kpi_connectivity(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    $k = dashboard_device_kpis($db, $cid);
    return '<div class="kpi-item-value">On <span style="color:var(--success);">' . (int)$k['online']
         . '</span> / Off <span style="color:var(--error);">' . (int)$k['offline'] . '</span></div>';
}
function dashboard_render_kpi_occurrences(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    $k = dashboard_occurrence_kpis($db, $cid);
    return '<div class="kpi-item-value">' . (int)$k['total']
         . ' <span style="font-size:14px;font-weight:400;color:var(--warning);">(' . (int)$k['waiting'] . ' aguardando)</span></div>';
}
function dashboard_render_kpi_outdated(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    $k = dashboard_outdated_kpis($db, $cid);
    $total = $k['outdated_lt7d'] + $k['outdated_gt7d'] + $k['outdated_gt30d'] + $k['outdated_never'];
    return '<div class="kpi-item-value">' . $total . '</div>'
         . '<div class="kpi-item-delta">+7d: ' . $k['outdated_gt7d'] . ' · Nunca: ' . $k['outdated_never'] . '</div>';
}
function dashboard_render_idle(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    $idle = 0;
    try {
        $stmt = $db->prepare("SELECT COUNT(DISTINCT g.imei) FROM gps_data g
                               WHERE g.customer_id=:cid AND g.gps_time >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) AND g.acc=1 AND g.speed=0");
        $stmt->execute([':cid' => $cid]);
        $idle = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    return '<div class="kpi-item-value">' . $idle . '</div>'
         . '<div style="font-size:12px;color:var(--muted);margin-top:4px;">ignição ligada e parado (30 min)</div>';
}

function dashboard_render_speed_dist(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    $s = dashboard_speed_dist($db, $cid);
    $total = array_sum($s);
    if ($total === 0) return '<p class="text-muted" style="font-size:12px;">Sem dados de velocidade recentes.</p>';
    $pParado = round($s['speed_parados'] / $total * 100);
    $p20 = round($s['speed_ate20'] / $total * 100);
    $p60 = round($s['speed_ate60'] / $total * 100);
    $p60p = 100 - $pParado - $p20 - $p60;
    return '<div class="velocity-bar">'
        . '<div style="width:' . $pParado . '%;background:var(--muted-soft);">' . $pParado . '%</div>'
        . '<div style="width:' . $p20 . '%;background:var(--primary);">' . $p20 . '%</div>'
        . '<div style="width:' . $p60 . '%;background:var(--warning);">' . $p60 . '%</div>'
        . '<div style="width:' . $p60p . '%;background:var(--error);">' . $p60p . '%</div></div>'
        . '<div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:4px;">'
        . '<span>■ Parados ' . $s['speed_parados'] . '</span><span>■ ≤20 ' . $s['speed_ate20'] . '</span>'
        . '<span>■ ≤60 ' . $s['speed_ate60'] . '</span><span>■ &gt;60 ' . $s['speed_acima60'] . '</span></div>';
}

function dashboard_render_model_status(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    $rows = [];
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(dm.model_name, d.device_model, '—') as model,
                   SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5 THEN 1 ELSE 0 END) as on_cnt,
                   SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) > 5 OR d.last_communication IS NULL THEN 1 ELSE 0 END) as off_cnt
            FROM devices d LEFT JOIN device_models dm ON dm.id = d.device_model_id
            WHERE d.customer_id = :cid AND d.is_active = 1
            GROUP BY model ORDER BY (on_cnt+off_cnt) DESC LIMIT 6
        ");
        $stmt->execute([':cid' => $cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    if (empty($rows)) return '<p class="text-muted" style="font-size:12px;">Sem equipamentos cadastrados.</p>';
    $html = '';
    foreach ($rows as $r) {
        $t = (int)$r['on_cnt'] + (int)$r['off_cnt'];
        $pct = $t > 0 ? round($r['on_cnt'] / $t * 100) : 0;
        $html .= '<div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">'
            . '<span style="font-size:12px;font-weight:600;color:var(--ink);min-width:80px;">' . htmlspecialchars($r['model']) . '</span>'
            . '<div style="flex:1;height:8px;border-radius:4px;background:var(--surface-strong);overflow:hidden;">'
            . '<div style="width:' . $pct . '%;height:100%;background:var(--success);"></div></div>'
            . '<span style="font-size:11px;color:var(--muted);white-space:nowrap;">'
            . '<span style="color:var(--success);">✓ ' . (int)$r['on_cnt'] . '</span>'
            . '<span style="color:var(--error);margin-left:4px;">✗ ' . (int)$r['off_cnt'] . '</span> · ' . $pct . '%</span></div>';
    }
    return $html;
}

function dashboard_render_heatmap(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    $rows = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT g.imei, g.latitude, g.longitude, g.speed,
                   COALESCE(d.device_name, g.imei) as device_name
            FROM gps_data g LEFT JOIN devices d ON d.imei = g.imei
            WHERE g.customer_id = :cid AND g.latitude != 0 AND g.longitude != 0 AND g.gps_time >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
            ORDER BY g.gps_time DESC LIMIT 500
        ");
        $stmt->execute([':cid' => $cid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $json = json_encode($rows);
    return '<div id="w-heatmap-map" style="height:300px;border-radius:var(--radius-lg);border:1px solid var(--hairline);"></div>
    <script>(function(){
        var data = ' . $json . ';
        var map = L.map("w-heatmap-map");
        L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png",{attribution:"&copy; OSM"}).addTo(map);
        var bounds=[], heatPoints=[];
        data.forEach(function(p){
            var lat=parseFloat(p.latitude), lng=parseFloat(p.longitude);
            if (lat && lng && lat!==0){ bounds.push([lat,lng]); heatPoints.push([lat,lng,0.6]); }
        });
        if (heatPoints.length>0 && typeof L.heatLayer==="function") L.heatLayer(heatPoints,{radius:22,blur:18,maxZoom:15}).addTo(map);
        if (bounds.length>0) map.fitBounds(bounds); else map.setView([-15.78,-47.93],5);
        setTimeout(function(){map.invalidateSize();},200);
    })();</script>';
}

function dashboard_series_window(string $periodo): array
{
    if ($periodo === 'hoje') {
        [$startUtc, ] = brt_day_range_to_utc(brt_today(), brt_today());
        $bucketFmt = "HOUR(CONVERT_TZ(%s, '+00:00', '-03:00'))";
        $labels = [];
        for ($h = 0; $h < 24; $h++) $labels[] = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
    } else {
        $daysBack = $periodo === '7d' ? 6 : 29;
        $firstDay = date('Y-m-d', strtotime(brt_today() . " -$daysBack days"));
        [$startUtc, ] = brt_day_range_to_utc($firstDay, brt_today());
        $bucketFmt = "DATE_FORMAT(CONVERT_TZ(%s, '+00:00', '-03:00'), '%%d/%%m')";
        $labels = [];
        for ($i = $daysBack; $i >= 0; $i--) $labels[] = date('d/m', strtotime(brt_today() . " -$i days"));
    }
    return [$startUtc, $bucketFmt, $labels];
}

function dashboard_render_ts_alarms(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    [$startUtc, $bucketFmt, $labels] = dashboard_series_window($periodo);
    $labelIndex = array_flip($labels);
    $vals = array_fill(0, count($labels), 0);
    ['joins' => $diagJoins, 'diag' => $diagExpr] = alarm_label_sql();
    try {
        $stmt = $db->prepare("
            SELECT " . sprintf($bucketFmt, 'a.alarm_time') . " as bk, COUNT(*) as cnt
            FROM alarms a $diagJoins
            WHERE a.customer_id=:cid AND a.alarm_time >= :ts AND ($diagExpr) = 0 GROUP BY bk
        ");
        $stmt->execute([':cid' => $cid, ':ts' => $startUtc]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bk = $periodo === 'hoje' ? str_pad((string)$r['bk'], 2, '0', STR_PAD_LEFT) : $r['bk'];
            if (isset($labelIndex[$bk])) $vals[$labelIndex[$bk]] = (int)$r['cnt'];
        }
    } catch (Throwable $e) {}
    return '<div class="text-mono" style="font-size:20px;font-weight:600;margin-bottom:6px;">' . array_sum($vals) . '</div>'
        . '<div class="chart-box" style="height:160px;position:relative;"><canvas id="w-chart-alarms"></canvas></div>
        <script>(function(){
            new Chart(document.getElementById("w-chart-alarms"), { type:"bar",
                data:{ labels:' . json_encode($labels) . ', datasets:[{label:"Alarmes",data:' . json_encode($vals) . ',backgroundColor:"rgba(0,82,255,0.7)",borderRadius:4}]},
                options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
                    scales:{x:{ticks:{font:{size:9}},grid:{display:false}},y:{beginAtZero:true,ticks:{font:{size:9}},grid:{color:"#eef0f3"}}}}
            });
        })();</script>';
}

function dashboard_render_ts_occurrences(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    [$startUtc, $bucketFmt, $labels] = dashboard_series_window($periodo);
    $labelIndex = array_flip($labels);
    $vals = array_fill(0, count($labels), 0);
    try {
        $stmt = $db->prepare("
            SELECT " . sprintf($bucketFmt, 'first_alarm_at') . " as bk, COUNT(*) as cnt
            FROM occurrences WHERE customer_id=:cid AND first_alarm_at >= :ts GROUP BY bk
        ");
        $stmt->execute([':cid' => $cid, ':ts' => $startUtc]);
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $bk = $periodo === 'hoje' ? str_pad((string)$r['bk'], 2, '0', STR_PAD_LEFT) : $r['bk'];
            if (isset($labelIndex[$bk])) $vals[$labelIndex[$bk]] = (int)$r['cnt'];
        }
    } catch (Throwable $e) {}
    return '<div class="text-mono" style="font-size:20px;font-weight:600;margin-bottom:6px;">' . array_sum($vals) . '</div>'
        . '<div class="chart-box" style="height:160px;position:relative;"><canvas id="w-chart-occs"></canvas></div>
        <script>(function(){
            new Chart(document.getElementById("w-chart-occs"), { type:"bar",
                data:{ labels:' . json_encode($labels) . ', datasets:[{label:"Ocorrências",data:' . json_encode($vals) . ',backgroundColor:"rgba(244,176,0,0.7)",borderRadius:4}]},
                options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
                    scales:{x:{ticks:{font:{size:9}},grid:{display:false}},y:{beginAtZero:true,ticks:{font:{size:9}},grid:{color:"#eef0f3"}}}}
            });
        })();</script>';
}

function dashboard_render_top_plates(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    [$startUtc, , ] = dashboard_series_window($periodo);
    ['joins' => $diagJoins, 'diag' => $diagExpr] = alarm_label_sql();
    $rows = [];
    try {
        $stmt = $db->prepare("
            SELECT COALESCE(d.device_name, a.imei) as name, COUNT(*) as cnt
            FROM alarms a LEFT JOIN devices d ON d.imei=a.imei $diagJoins
            WHERE a.customer_id=:cid AND a.alarm_time >= :ts AND ($diagExpr) = 0
            GROUP BY a.imei, name ORDER BY cnt DESC LIMIT 3
        ");
        $stmt->execute([':cid' => $cid, ':ts' => $startUtc]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    if (empty($rows)) return '<p class="text-muted" style="font-size:12px;">Nenhum alarme no período.</p>';
    $html = '';
    foreach ($rows as $i => $r) {
        $html .= '<div class="flex-between" style="font-size:13px;padding:5px 0;border-bottom:1px solid var(--hairline-soft);">'
            . '<span><span style="color:var(--muted);font-size:11px;margin-right:6px;">' . ($i + 1) . 'º</span>' . htmlspecialchars($r['name']) . '</span>'
            . '<span class="text-mono" style="font-weight:600;">' . (int)$r['cnt'] . '</span></div>';
    }
    return $html;
}

function dashboard_render_top_drivers(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    [$startUtc, , ] = dashboard_series_window($periodo);
    $faceid = false;
    try {
        $stmt = $db->prepare("SELECT faceid_enabled FROM customers WHERE id=:cid");
        $stmt->execute([':cid' => $cid]);
        $faceid = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {}
    if (!$faceid) {
        return '<div style="text-align:center;padding:16px 8px;color:var(--muted);font-size:12px;">'
             . '<div style="font-size:22px;margin-bottom:6px;">🪪</div>Nenhum alarme por motorista neste período.<br>'
             . 'Habilite o <strong>FaceID</strong> na frota (Cadastros → Clientes) para exibir este ranking.</div>';
    }
    $rows = [];
    try {
        $stmt = $db->prepare("
            SELECT dr.name, COUNT(*) as cnt FROM occurrences o JOIN drivers dr ON dr.id=o.driver_id
            WHERE o.customer_id=:cid AND o.first_alarm_at >= :ts GROUP BY dr.id ORDER BY cnt DESC LIMIT 3
        ");
        $stmt->execute([':cid' => $cid, ':ts' => $startUtc]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    if (empty($rows)) return '<p class="text-muted" style="font-size:12px;">Nenhuma ocorrência atribuída a motorista no período.</p>';
    $html = '';
    foreach ($rows as $i => $r) {
        $html .= '<div class="flex-between" style="font-size:13px;padding:5px 0;border-bottom:1px solid var(--hairline-soft);">'
            . '<span><span style="color:var(--muted);font-size:11px;margin-right:6px;">' . ($i + 1) . 'º</span>' . htmlspecialchars($r['name']) . '</span>'
            . '<span class="text-mono" style="font-weight:600;">' . (int)$r['cnt'] . '</span></div>';
    }
    return $html;
}

function dashboard_render_reseller_view(PDO $db, int $cid, bool $isReseller, string $periodo): string
{
    if (!$isReseller) return '<p class="text-muted" style="font-size:12px;">Disponível só para o perfil revendedor.</p>';
    $axes = [
        ['Top 3 por equipamentos ativos', "SELECT c.name, COUNT(d.id) as cnt FROM customers c LEFT JOIN devices d ON d.customer_id=c.id AND d.is_active=1 WHERE c.is_active=1 GROUP BY c.id ORDER BY cnt DESC LIMIT 3"],
        ['Top 3 por ocorrências', "SELECT c.name, COUNT(o.id) as cnt FROM customers c JOIN occurrences o ON o.customer_id=c.id WHERE c.is_active=1 GROUP BY c.id ORDER BY cnt DESC LIMIT 3"],
        ['Top 3 por desatualizados', "SELECT c.name, COUNT(*) as cnt FROM customers c JOIN devices d ON d.customer_id=c.id AND d.is_active=1 LEFT JOIN device_statistics ds ON ds.imei=d.imei WHERE c.is_active=1 AND (ds.last_gps_time IS NULL OR ds.last_gps_time < DATE_SUB(NOW(), INTERVAL 7 DAY)) GROUP BY c.id ORDER BY cnt DESC LIMIT 3"],
    ];
    $html = '<div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:12px;">';
    foreach ($axes as [$title, $sql]) {
        $rows = [];
        try { $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        $html .= '<div style="padding:12px;border:1px solid var(--hairline-soft);border-radius:var(--radius-sm);">'
            . '<div style="font-size:12px;font-weight:600;color:var(--muted);margin-bottom:8px;">' . htmlspecialchars($title) . '</div>';
        if (empty($rows)) {
            $html .= '<div style="font-size:12px;color:var(--muted);">Sem dados.</div>';
        } else {
            foreach ($rows as $i => $r) {
                $html .= '<div class="flex-between" style="font-size:13px;padding:3px 0;">'
                    . '<span><span style="color:var(--muted);font-size:11px;margin-right:6px;">' . ($i + 1) . 'º</span>' . htmlspecialchars($r['name']) . '</span>'
                    . '<span class="text-mono" style="font-weight:600;">' . (int)$r['cnt'] . '</span></div>';
            }
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/**
 * Despacha para a função de renderização do widget.
 *
 * @param string $key       Chave de DASHBOARD_WIDGETS
 * @param PDO    $db        Conexão ativa
 * @param int    $cid       Cliente da sessão
 * @param bool   $isReseller Perfil revendedor?
 * @param string $periodo   'hoje'|'7d'|'mes' — só usado pelos widgets de série
 * @returns string HTML do corpo do widget (sem o card ao redor)
 */
function render_widget(string $key, PDO $db, int $cid, bool $isReseller, string $periodo = 'hoje'): string
{
    $fn = 'dashboard_render_' . $key;
    if (!function_exists($fn)) {
        return '<p class="text-muted" style="font-size:12px;">Widget desconhecido.</p>';
    }
    return $fn($db, $cid, $isReseller, $periodo);
}
