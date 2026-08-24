<?php
/**
 * JIMI Webhook System — Replay do Deslocamento v4.10.2
 * Rota: /relatorios/deslocamento/replay?trip_id={id}
 *
 * Item 6 do docs/PLANO_IMPLEMENTACAO_v4.10.md: reprodução no tempo de uma
 * viagem já registrada (`trips`) — marcador se move pelo percurso, com
 * play/pause, velocidade (0.5×/1×/2×/4×) e uma linha do tempo que aceita
 * clique (seek), roda do mouse (zoom) e arraste (pan), no mesmo idioma da
 * barra de `handlers/video_playback.php`.
 *
 * Ao contrário de `rel_deslocamento_rota.php`, só existe modalidade por
 * VIAGEM (uma linha de `trips`) — o fechamento diário pode agregar várias
 * viagens com buracos entre elas, e "reproduzir" um buraco não tem sentido.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/vehicle_icons.php'; // marcador móvel = mesmo ícone do /rastreamento
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();

$tripId = (int)($_GET['trip_id'] ?? 0);
$error = '';
$imei = '';
$vehicleType = null;
$deviceName = '';

$sql = "SELECT t.*, COALESCE(d.device_name, t.imei) AS device_name, d.vehicle_type
        FROM trips t LEFT JOIN devices d ON d.imei = t.imei
        WHERE t.id = :id" . ($customerId ? " AND t.customer_id = :cid" : "");
$stmt = $db->prepare($sql);
$p = [':id' => $tripId];
if ($customerId) $p[':cid'] = $customerId;
try {
    $stmt->execute($p);
    $trip = $stmt->fetch();
} catch (Exception $e) {
    $trip = false;
    $error = 'Erro ao carregar a viagem.';
}

if (!$trip && !$error) {
    $error = 'Viagem não encontrada.';
} elseif ($trip) {
    $imei = $trip['imei'];
    $vehicleType = $trip['vehicle_type'];
    $deviceName = $trip['device_name'];
    $utcFrom = $trip['started_at'];
    $utcTo = $trip['ended_at'] ?: $trip['started_at'];
    $summary = [
        'distance_km' => (float)$trip['distance_km'],
        'max_speed'   => (float)$trip['max_speed'],
        'alarm_count' => (int)$trip['alarm_count'],
    ];
}

$points = [];
$occPins = [];
$totalPoints = 0;
$sampled = false;

if (!$error) {
    // Mesma consulta e amostragem de rel_deslocamento_rota.php:109-125 — o
    // teto de ~3000 pontos existe para o navegador, não para o replay, mas
    // reaproveitar a mesma regra evita duas respostas para "quantos pontos
    // uma viagem tem" nas duas telas.
    $stmt = $db->prepare("
        SELECT latitude, longitude, speed, acc, mileage, gps_time
        FROM gps_data
        WHERE imei = :imei AND gps_time BETWEEN :df AND :dt
          AND latitude IS NOT NULL AND latitude <> 0
        ORDER BY gps_time ASC
        LIMIT 20000");
    $stmt->execute([':imei' => $imei, ':df' => $utcFrom, ':dt' => $utcTo]);
    $raw = $stmt->fetchAll();
    $totalPoints = count($raw);

    $maxPts = 3000;
    $step = $totalPoints > $maxPts ? (int)ceil($totalPoints / $maxPts) : 1;
    $sampled = $step > 1;
    foreach ($raw as $i => $r) {
        if ($i % $step !== 0 && $i !== $totalPoints - 1) continue;
        $points[] = [
            'lat'     => (float)$r['latitude'],
            'lng'     => (float)$r['longitude'],
            'ts'      => strtotime($r['gps_time'] . ' UTC'),
            't'       => fmt_brt($r['gps_time'], 'd/m/Y H:i:s'),
            's'       => $r['speed'] !== null ? (float)$r['speed'] : null,
            'acc'     => (int)$r['acc'],
            'mileage' => $r['mileage'] !== null ? (float)$r['mileage'] : null,
        ];
    }

    // Overlay de ocorrências — mesma consulta de rel_deslocamento_rota.php,
    // sem o passo de "anexar ao ponto mais próximo": aqui elas só marcam o
    // mapa, o playhead não precisa saber delas.
    $sql = "SELECT o.id, o.alarm_type, o.risk, o.first_alarm_at,
                   a.latitude AS lat, a.longitude AS lng, a.alarm_time
            FROM occurrences o
            LEFT JOIN occurrence_events oe ON oe.occurrence_id = o.id
            LEFT JOIN alarms a ON a.id = oe.alarm_id AND a.latitude IS NOT NULL AND a.latitude <> 0
            WHERE o.imei = :imei AND o.first_alarm_at BETWEEN :df AND :dt"
            . ($customerId ? " AND o.customer_id = :cid" : "") . "
            ORDER BY o.id, a.alarm_time";
    $stmt = $db->prepare($sql);
    $p2 = [':imei' => $imei, ':df' => $utcFrom, ':dt' => $utcTo];
    if ($customerId) $p2[':cid'] = $customerId;
    $stmt->execute($p2);
    $occs = [];
    while ($r = $stmt->fetch()) {
        $id = (int)$r['id'];
        if (isset($occs[$id]) && $occs[$id]['lat'] !== null) continue;
        $when = $r['alarm_time'] ?: $r['first_alarm_at'];
        $occs[$id] = [
            'lat' => $r['lat'] !== null ? (float)$r['lat'] : null,
            'lng' => $r['lng'] !== null ? (float)$r['lng'] : null,
            'ts'  => strtotime($when . ' UTC'),
            'label' => htmlspecialchars($r['alarm_type']) . ' — ' . fmt_brt($when, 'd/m/Y H:i:s'),
        ];
    }
    foreach ($occs as $o) {
        if ($o['lat'] !== null) {
            $occPins[] = $o;
        }
    }

    if (empty($points)) {
        $error = 'Nenhuma posição GPS registrada na janela desta viagem.';
    }
}

$page_title = 'Replay do Deslocamento';
$current_route = 'rel_deslocamento';
$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
#replay-map{height:calc(100vh - 380px);min-height:360px;border-radius:var(--radius-lg);border:1px solid var(--hairline);}
.rp-kpi{font-family:\'JetBrains Mono\',monospace;font-size:15px;font-weight:600;color:var(--ink);}
.rp-kpi-label{font-size:10px;font-weight:600;text-transform:uppercase;color:var(--muted);}
.rp-vehicle-pin{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;
  border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);background:#0052ff;}
#rp-controls{display:flex;align-items:center;gap:10px;padding:10px 14px;}
#rp-controls select{padding:6px 8px;font-size:12px;border:1px solid var(--hairline);border-radius:var(--radius-sm);}
#rp-timeline-wrap{position:relative;padding:0 14px 12px;}
#rp-svg{width:100%;height:64px;display:block;cursor:pointer;touch-action:none;user-select:none;}
#rp-svg.arrastando{cursor:grabbing;}
#rp-svg .rp-lane{fill:var(--canvas-soft);}
#rp-svg .rp-speed{fill:var(--primary-soft);stroke:var(--primary);stroke-width:1;opacity:.8;}
#rp-svg .rp-tick{stroke:var(--hairline);stroke-width:1;}
#rp-svg .rp-tick-label{font-size:9px;fill:var(--muted);font-family:\'JetBrains Mono\',monospace;}
#rp-svg .rp-playhead{stroke:#e5484d;stroke-width:2;pointer-events:none;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Replay do Deslocamento</h2>
    <div style="display:flex;align-items:center;gap:12px;">
        <?php if (!$error): ?>
        <span style="font-size:13px;color:var(--muted);">
            <?= htmlspecialchars($deviceName) ?> · <span class="text-mono"><?= htmlspecialchars($imei) ?></span>
            · <?= fmt_brt($utcFrom) ?> → <?= fmt_brt($utcTo) ?>
        </span>
        <?php endif; ?>
        <a href="/relatorios/deslocamento/rota?trip_id=<?= (int)$tripId ?>" class="btn btn-outline btn-sm">Ver rota (estática)</a>
        <?= report_back_button('/relatorios/deslocamento', 'Voltar ao relatório') ?>
    </div>
</div>

<?php if ($error): ?>
<div class="card" style="padding:32px;text-align:center;color:var(--muted);"><?= htmlspecialchars($error) ?></div>
<?php else: ?>

<div class="card mb-16" style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:28px;">
    <div><div class="rp-kpi-label">Distância</div><div class="rp-kpi"><?= number_format($summary['distance_km'], 1) ?> km</div></div>
    <div><div class="rp-kpi-label">Vel. Máx</div><div class="rp-kpi"><?= number_format($summary['max_speed'], 1) ?> km/h</div></div>
    <div><div class="rp-kpi-label">Alarmes</div><div class="rp-kpi"><?= $summary['alarm_count'] ?></div></div>
    <div><div class="rp-kpi-label">Posições</div><div class="rp-kpi"><?= $totalPoints ?><?= $sampled ? ' <span style="font-size:10px;color:var(--muted);">(amostrado)</span>' : '' ?></div></div>
    <div><div class="rp-kpi-label">Hora atual</div><div class="rp-kpi" id="rp-readout-time">—</div></div>
    <div><div class="rp-kpi-label">Velocidade</div><div class="rp-kpi" id="rp-readout-speed">—</div></div>
    <div><div class="rp-kpi-label">Percorrido</div><div class="rp-kpi" id="rp-readout-dist">—</div></div>
</div>

<div class="card mb-16" style="padding:0;overflow:hidden;">
    <div id="rp-controls">
        <button type="button" class="btn btn-primary btn-sm" id="rp-btn-play" style="min-width:84px;">▶ Play</button>
        <button type="button" class="btn btn-outline btn-sm" id="rp-btn-restart">Reiniciar</button>
        <select id="rp-speed">
            <option value="0.5">0.5×</option>
            <option value="1" selected>1×</option>
            <option value="2">2×</option>
            <option value="4">4×</option>
        </select>
        <button type="button" class="btn btn-outline btn-sm" id="rp-btn-fit" style="margin-left:auto;">Ver viagem inteira</button>
    </div>
    <div id="rp-timeline-wrap">
        <svg id="rp-svg"></svg>
    </div>
</div>

<div id="replay-map"></div>

<script>
var RP_ICON = <?= json_encode(vehicle_icons_js_catalog(), JSON_UNESCAPED_SLASHES) ?>;
var RP = {
    points: <?= json_encode(array_map(fn($pt) => ['lat'=>$pt['lat'],'lng'=>$pt['lng'],'ts'=>$pt['ts'],'t'=>$pt['t'],'s'=>$pt['s'],'mileage'=>$pt['mileage']], $points)) ?>,
    occPins: <?= json_encode($occPins) ?>,
    vehicleType: <?= json_encode($vehicleType) ?>,
    janela: [<?= (int)$points[0]['ts'] ?>, <?= (int)$points[count($points)-1]['ts'] ?>],
    vista: null,
    now: <?= (int)$points[0]['ts'] ?>,
    playing: false,
    speedMult: 1,
    raf: null,
    lastFrame: null
};
RP.vista = RP.janela.slice();

// ── Mapa ─────────────────────────────────────────────────────────────────
var map = L.map('replay-map', { preferCanvas: true });
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OSM' }).addTo(map);
var latlngs = RP.points.map(function (p) { return [p.lat, p.lng]; });
L.polyline(latlngs, { color: '#0052ff', weight: 3, opacity: 0.35 }).addTo(map);
RP.occPins.forEach(function (o) {
    L.circleMarker([o.lat, o.lng], { radius: 7, color: '#c47d0e', fillColor: '#f5a623', fillOpacity: 0.9, weight: 2 })
        .addTo(map).bindPopup('<strong>Ocorrência:</strong><br>' + o.label);
});
map.fitBounds(latlngs, { padding: [30, 30] });

function rpVehicleIconHtml() {
    var icon = RP_ICON[RP.vehicleType];
    if (!icon) return '';
    var attrs = icon.stroke
        ? 'fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        : 'fill="#fff"';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' + attrs + '>' + icon.paths + '</svg>';
}
var marker = L.marker(latlngs[0], {
    icon: L.divIcon({
        className: 'vehicle-pin-wrap',
        html: '<div class="rp-vehicle-pin">' + rpVehicleIconHtml() + '</div>',
        iconSize: [26, 26], iconAnchor: [13, 13]
    }),
    zIndexOffset: 1000
}).addTo(map);

/**
 * Posição interpolada em `t` (epoch) entre os dois pontos que o cercam.
 *
 * Busca linear: `RP.points` tem no máx. ~3000 itens (mesmo teto de
 * rel_deslocamento_rota.php), então o custo por frame é desprezível — não
 * compensa a complexidade de uma busca binária para este tamanho.
 *
 * @param {number} t Epoch (segundos)
 * @returns {{lat:number,lng:number,speed:number|null,mileage:number|null}}
 */
function rpInterpolar(t) {
    var pts = RP.points;
    if (t <= pts[0].ts) return { lat: pts[0].lat, lng: pts[0].lng, speed: pts[0].s, mileage: pts[0].mileage };
    if (t >= pts[pts.length - 1].ts) {
        var last = pts[pts.length - 1];
        return { lat: last.lat, lng: last.lng, speed: last.s, mileage: last.mileage };
    }
    for (var i = 0; i < pts.length - 1; i++) {
        var a = pts[i], b = pts[i + 1];
        if (t >= a.ts && t <= b.ts) {
            var span = Math.max(1, b.ts - a.ts);
            var frac = (t - a.ts) / span;
            return {
                lat: a.lat + (b.lat - a.lat) * frac,
                lng: a.lng + (b.lng - a.lng) * frac,
                speed: a.s,
                mileage: a.mileage
            };
        }
    }
    return { lat: pts[0].lat, lng: pts[0].lng, speed: pts[0].s, mileage: pts[0].mileage };
}

function rpFmtHora(ts) {
    var d = new Date(ts * 1000);
    return d.toLocaleString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'America/Sao_Paulo' });
}

var mileage0 = RP.points[0].mileage;
function rpAtualizarLeitura() {
    var pos = rpInterpolar(RP.now);
    marker.setLatLng([pos.lat, pos.lng]);
    document.getElementById('rp-readout-time').textContent = rpFmtHora(RP.now);
    document.getElementById('rp-readout-speed').textContent = pos.speed !== null ? pos.speed.toFixed(0) + ' km/h' : '—';
    document.getElementById('rp-readout-dist').textContent =
        (pos.mileage !== null && mileage0 !== null) ? (pos.mileage - mileage0).toFixed(1) + ' km' : '—';
}

// ── Linha do tempo (SVG) — mesma mecânica de zoom/pan/click de
// handlers/video_playback.php (wheel + pointerdown/move/up + `alvoDown` para
// distinguir clique de arraste sob pointer capture), adaptada para uma única
// faixa com sparkline de velocidade em vez de blocos de vídeo por canal.
var RP_MARGEM_ESQ = 8, RP_MARGEM_DIR = 8, RP_ALTURA = 64;

function rpX(t) {
    var r = document.getElementById('rp-svg').getBoundingClientRect();
    var largura = Math.max(1, r.width - RP_MARGEM_ESQ - RP_MARGEM_DIR);
    var frac = (t - RP.vista[0]) / Math.max(1, RP.vista[1] - RP.vista[0]);
    return RP_MARGEM_ESQ + Math.max(0, Math.min(1, frac)) * largura;
}
function rpTDeX(x) {
    var r = document.getElementById('rp-svg').getBoundingClientRect();
    var largura = Math.max(1, r.width - RP_MARGEM_ESQ - RP_MARGEM_DIR);
    var frac = (x - RP_MARGEM_ESQ) / largura;
    return RP.vista[0] + Math.max(0, Math.min(1, frac)) * (RP.vista[1] - RP.vista[0]);
}

function rpAplicarVista(a, b) {
    var minSpan = 10; // não faz sentido dar zoom além de 10s de janela
    var maxSpan = RP.janela[1] - RP.janela[0];
    var span = Math.round(Math.max(minSpan, Math.min(Math.max(minSpan, maxSpan), b - a)));
    var meio = (a + b) / 2;
    a = Math.round(meio - span / 2); b = a + span;
    if (a < RP.janela[0]) { a = RP.janela[0]; b = a + span; }
    if (b > RP.janela[1]) { b = RP.janela[1]; a = b - span; }
    RP.vista = [a, b];
    rpDesenharTimeline();
}
function rpZoom(fator, ancora) {
    var a = RP.vista[0], b = RP.vista[1];
    if (ancora === undefined) ancora = (a + b) / 2;
    var novo = (b - a) / fator;
    var p = (ancora - a) / Math.max(1, b - a);
    rpAplicarVista(ancora - novo * p, ancora + novo * (1 - p));
}

var rpMaxSpeed = Math.max(1, RP.points.reduce(function (m, p) { return Math.max(m, p.s || 0); }, 0));

function rpDesenharTimeline() {
    var svg = document.getElementById('rp-svg');
    var r = svg.getBoundingClientRect();
    var w = Math.max(1, r.width), h = RP_ALTURA;
    var largura = Math.max(1, w - RP_MARGEM_ESQ - RP_MARGEM_DIR);

    var partes = [];
    partes.push('<rect class="rp-lane" x="0" y="0" width="' + w + '" height="' + h + '" rx="4"></rect>');

    // Sparkline de velocidade — só os pontos dentro da vista, downsample por
    // pixel (um valor por coluna) para não desenhar milhares de vértices.
    var path = 'M ' + RP_MARGEM_ESQ + ' ' + h;
    var passoPx = 2;
    for (var x = 0; x <= largura; x += passoPx) {
        var t = rpTDeX(RP_MARGEM_ESQ + x);
        var pos = rpInterpolar(t);
        var sp = pos.speed || 0;
        var y = h - 4 - (Math.min(sp, rpMaxSpeed) / rpMaxSpeed) * (h - 8);
        path += ' L ' + (RP_MARGEM_ESQ + x) + ' ' + y.toFixed(1);
    }
    path += ' L ' + (RP_MARGEM_ESQ + largura) + ' ' + h + ' Z';
    partes.push('<path class="rp-speed" d="' + path + '"></path>');

    // Marcas de hora — 5 divisões da vista atual
    for (var i = 0; i <= 4; i++) {
        var tt = RP.vista[0] + (RP.vista[1] - RP.vista[0]) * (i / 4);
        var xx = rpX(tt);
        partes.push('<line class="rp-tick" x1="' + xx + '" y1="0" x2="' + xx + '" y2="' + h + '"></line>');
        partes.push('<text class="rp-tick-label" x="' + Math.min(Math.max(xx, RP_MARGEM_ESQ + 14), w - RP_MARGEM_DIR - 30) + '" y="' + (h - 4) + '">' + rpFmtHora(tt) + '</text>');
    }

    if (RP.now >= RP.vista[0] && RP.now <= RP.vista[1]) {
        var px = rpX(RP.now);
        partes.push('<line class="rp-playhead" x1="' + px + '" y1="0" x2="' + px + '" y2="' + h + '"></line>');
    }

    svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
    svg.innerHTML = partes.join('');
}

(function () {
    var svg = document.getElementById('rp-svg');
    var arrastando = false, xIni = 0, vIni = null, moveu = false;

    svg.addEventListener('wheel', function (ev) {
        ev.preventDefault();
        var r = svg.getBoundingClientRect();
        rpZoom(ev.deltaY < 0 ? 1.35 : 1 / 1.35, rpTDeX(ev.clientX - r.left));
    }, { passive: false });

    svg.addEventListener('pointerdown', function (ev) {
        arrastando = true; moveu = false; xIni = ev.clientX; vIni = RP.vista.slice();
        svg.classList.add('arrastando');
        svg.setPointerCapture(ev.pointerId);
    });
    svg.addEventListener('pointermove', function (ev) {
        if (!arrastando) return;
        var r = svg.getBoundingClientRect();
        var pps = Math.max(1, r.width - RP_MARGEM_ESQ - RP_MARGEM_DIR) / Math.max(1, vIni[1] - vIni[0]);
        var dt = Math.round((ev.clientX - xIni) / pps);
        if (Math.abs(ev.clientX - xIni) > 3) moveu = true;
        rpAplicarVista(vIni[0] - dt, vIni[1] - dt);
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (n) {
        svg.addEventListener(n, function () { arrastando = false; svg.classList.remove('arrastando'); });
    });

    svg.addEventListener('click', function (ev) {
        if (moveu) return; // arrastar não é clicar (mesma guarda de video_playback.php)
        var r = svg.getBoundingClientRect();
        RP.now = Math.round(rpTDeX(ev.clientX - r.left));
        rpAtualizarLeitura();
        rpDesenharTimeline();
    });

    var t;
    window.addEventListener('resize', function () { clearTimeout(t); t = setTimeout(rpDesenharTimeline, 120); });
})();

// ── Play/pause/velocidade ────────────────────────────────────────────────
function rpTick(timestamp) {
    if (!RP.playing) return;
    if (RP.lastFrame === null) RP.lastFrame = timestamp;
    var deltaS = (timestamp - RP.lastFrame) / 1000;
    RP.lastFrame = timestamp;
    RP.now += deltaS * RP.speedMult;
    if (RP.now >= RP.janela[1]) {
        RP.now = RP.janela[1];
        RP.playing = false;
        document.getElementById('rp-btn-play').textContent = '▶ Play';
    }
    rpAtualizarLeitura();
    rpDesenharTimeline();
    if (RP.playing) RP.raf = requestAnimationFrame(rpTick);
}
document.getElementById('rp-btn-play').addEventListener('click', function () {
    RP.playing = !RP.playing;
    this.textContent = RP.playing ? '⏸ Pause' : '▶ Play';
    if (RP.playing) {
        if (RP.now >= RP.janela[1]) RP.now = RP.janela[0];
        RP.lastFrame = null;
        RP.raf = requestAnimationFrame(rpTick);
    }
});
document.getElementById('rp-btn-restart').addEventListener('click', function () {
    RP.now = RP.janela[0];
    rpAtualizarLeitura();
    rpDesenharTimeline();
});
document.getElementById('rp-btn-fit').addEventListener('click', function () {
    rpAplicarVista(RP.janela[0], RP.janela[1]);
});
document.getElementById('rp-speed').addEventListener('change', function () {
    RP.speedMult = parseFloat(this.value) || 1;
});

setTimeout(function () { map.invalidateSize(); rpDesenharTimeline(); rpAtualizarLeitura(); }, 300);
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
