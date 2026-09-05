<?php
/**
 * JIMI Webhook System — Rastreamento v4.17.6
 * Rota: /rastreamento
 *
 * Mapa ao vivo. UMA coluna de navegação (Cliente em cima, Ativos embaixo) +
 * mapa Leaflet. Auto-refresh 30s.
 *
 * Cada ativo tem uma caixa de seleção que decide se ele aparece no mapa — a
 * lista é o filtro do mapa, não só um índice para centralizar nele.
 *
 * Sob a placa, cada linha traz IGN (ON/OFF) e o horário da última posição —
 * dado de operação no lugar do IMEI, que é número de cadastro. Os três sinais
 * da linha (bolinha, IGN, horário) vêm todos de `device_statistics`, da MESMA
 * leitura, de propósito: ver o comentário no bloco da lista.
 *
 * 🔴 Até a v4.17.3 esta tela aceitava `?customer_id=` CRU e o usava direto nas
 * duas consultas, sem passar por `report_customer_scope()`. Medido: um
 * operador do cliente 2 (`user_type='cliente'`, `role='operator'`) abrindo
 * `/rastreamento?customer_id=1` recebia os **28 veículos do cliente 1** ao
 * vivo no mapa — placa, posição, velocidade e ignição — e a coluna "Clientes"
 * ainda listava o NOME de todos os clientes da base para ele, porque
 * `report_customer_options()` devolve tudo para quem não é revendedor. Era o
 * vazamento que o CLAUDE.md descreve em "Toda tela nova que aceite
 * `?customer_id` TEM de passar por ela".
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fleet_state.php';
require_once __DIR__ . '/../includes/vehicle_icons.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

// Escopo multi-tenant pelo ponto único. Para não-admin o `?customer_id=` é
// ignorado (não validado) e o escopo vira o da sessão; sem cliente resolvido
// vira 0, que não casa com nada — falha fechada. `null` só acontece para admin
// de plataforma e significa "todos os clientes", que num mapa AO VIVO é uma
// visão legítima (a frota inteira), não uma brecha.
$filterCust = $_GET['customer_id'] ?? null;
$scopeCust  = report_customer_scope($filterCust, $isAdmin, $customerId);
$customers  = $isAdmin ? report_customer_options($db) : [];
$nowUtc     = gmdate('Y-m-d H:i:s');

// Predicado de cliente das duas consultas — montado uma vez para as duas não
// poderem divergir (a lista da esquerda e os pinos do mapa têm de ser o MESMO
// conjunto, senão o operador desmarca um veículo que nem está na lista).
$custSql    = $scopeCust !== null ? ' AND d.customer_id = :cid' : '';
$custParams = $scopeCust !== null ? [':cid' => $scopeCust] : [];

// Estado calculado com includes/fleet_state.php::resolve_live_state() — a
// partir do ÚLTIMO PONTO (device_statistics), não do segmento aberto. Esta
// tela é AO VIVO (auto-refresh 30s) e mistura Estado com Ignição/Velocidade
// no mesmo balão; resolve_current_state() (usada pelos relatórios batch)
// lê o segmento, que só é regravado a cada 15 min pelo cron
// state_builder.php — ver o comentário de resolve_live_state() no motivo.
$devices = [];
try {
    $devStmt = $db->prepare("
        SELECT d.imei, d.device_name, d.vehicle_type,
               dm.model_name,
               ds.last_gps_time, ds.last_acc_status AS ignition, ds.last_speed AS speed,
               ds.last_latitude, ds.last_longitude
        FROM devices d
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        WHERE d.is_active = 1" . $custSql . "
        ORDER BY d.device_name ASC
    ");
    $devStmt->execute($custParams);
    foreach ($devStmt->fetchAll() as $row) {
        $row['current_state'] = resolve_live_state($row['last_gps_time'] ?? null, $row['ignition'] ?? null, $row['speed'] ?? null, $nowUtc);
        $row['is_online'] = $row['current_state'] !== 'offline';
        // Sem coordenada não há o que mostrar no mapa: a caixa de seleção
        // desse ativo nasce desabilitada, em vez de marcada e sem efeito.
        $row['has_pos'] = !empty($row['last_latitude']) && !empty($row['last_longitude'])
                          && (float)$row['last_latitude'] != 0.0;
        $devices[] = $row;
    }
    usort($devices, function ($a, $b) {
        return ($b['is_online'] <=> $a['is_online']) ?: strcmp($a['device_name'] ?? '', $b['device_name'] ?? '');
    });
} catch (Exception $e) {}

// Latest positions — device_statistics (cache já mantido pelos workers de
// ingestão) em vez de reabrir gps_data por device a cada request.
$positions = [];
try {
    $posStmt = $db->prepare("
        SELECT d.imei, COALESCE(d.device_name, d.imei) AS device_name, d.vehicle_type, d.speed_limit_kmh,
               c.default_speed_limit_kmh,
               ds.last_latitude AS latitude, ds.last_longitude AS longitude, ds.last_speed AS speed,
               ds.last_gps_time AS gps_time, ds.last_acc_status AS ignition
        FROM devices d
        LEFT JOIN customers c ON c.id = d.customer_id
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        WHERE d.is_active = 1" . $custSql . "
    ");
    $posStmt->execute($custParams);
    foreach ($posStmt->fetchAll() as $row) {
        $state = resolve_live_state($row['gps_time'] ?? null, $row['ignition'] ?? null, $row['speed'] ?? null, $nowUtc);
        $limit = resolve_speed_limit($row['speed_limit_kmh'], $row['default_speed_limit_kmh']);
        // "excesso" sobrepõe movimento/ocioso — nunca offline/parado, que já
        // não têm velocidade real associada.
        if ($state !== 'offline' && (int)$row['ignition'] === 1 && (float)$row['speed'] > $limit) {
            $state = 'excesso';
        }
        $row['state'] = $state;
        $positions[] = $row;
    }
} catch (Exception $e) {}

// D2 (v4.2.0 — YUV): modo AJAX para auto-refresh sem reload
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 0, 'positions' => array_map(function ($p) {
        return ['imei' => $p['imei'], 'lat' => (float)$p['latitude'], 'lng' => (float)$p['longitude'],
                'name' => $p['device_name'], 'speed' => (float)$p['speed'], 'ignition' => $p['ignition'],
                'state' => $p['state'], 'vehicleType' => $p['vehicle_type'],
                'online' => $p['state'] !== 'offline', 'time' => fmt_brt($p['gps_time'], 'd/m/Y H:i:s', ''),
                // Campo à parte, não uma troca de formato do `time`: o balão do
                // mapa mostra a data cheia com segundos, e a linha da lista tem
                // 244 px úteis dentro dos 300 px da coluna. Um formato só
                // obrigaria uma das duas a ficar errada.
                'timeShort' => fmt_brt($p['gps_time'], 'd/m H:i', '—')];
    }, $positions)], JSON_UNESCAPED_UNICODE);
    exit;
}

$page_title = 'Rastreamento';
$current_route = 'rastreamento';
require_once __DIR__ . '/../web/components/map_assets.php';
$extra_head = BC_MAP_ASSETS_HTML . '
<style>
#tracking-map{height:calc(100vh - 140px);border-radius:var(--radius-lg);border:1px solid var(--hairline);}
.map-wrap{padding:4px;position:relative;}
.device-list-item{padding:9px 8px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:9px;transition:background .1s;}
.device-list-item:hover{background:var(--canvas-soft);}
.device-list-item.selected{background:var(--primary-soft);border-left:3px solid var(--primary);}
.device-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.device-dot.online{background:var(--success);}
.device-dot.offline{background:var(--muted-soft);}
.left-panel{max-height:calc(100vh - 140px);overflow-y:auto;}
.panel-label{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;color:var(--muted);padding:8px 4px 6px;}
/* Ativo oculto do mapa continua na lista, apagado — some do mapa, não da
   navegação: quem escondeu precisa achá-lo de volta para reexibir. */
.device-list-item.hidden-on-map{opacity:.45;}
/* Pin do veículo (item 5, v4.10) — o SVG fica branco, quem muda por estado é
   o fundo do círculo. leaflet-div-icon tem fundo/borda padrão que precisam
   ser zerados aqui. */
.leaflet-div-icon.vehicle-pin-wrap{background:transparent;border:none;}
.vehicle-pin{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;
             border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);}
#fleet-legend{position:absolute;bottom:24px;left:16px;z-index:1000;background:var(--canvas);
              border:1px solid var(--hairline);border-radius:var(--radius-sm);padding:8px 12px;
              font-size:11px;color:var(--body);box-shadow:0 1px 4px rgba(0,0,0,.15);}
#fleet-legend .legend-row{display:flex;align-items:center;gap:6px;padding:2px 0;white-space:nowrap;}
#fleet-legend .legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:0;height:calc(100vh - 110px);">
    <!-- Navegação: Cliente em cima, Ativos embaixo, na MESMA coluna -->
    <div class="left-panel" style="border-right:1px solid var(--hairline);padding:8px;">

        <?php if ($isAdmin): ?>
        <div class="panel-label">Cliente</div>
        <select id="customer-select" onchange="selectCustomer(this.value)"
                style="width:100%;padding:7px 8px;font-size:12px;border:1px solid var(--hairline);border-radius:var(--radius-sm);margin-bottom:14px;background:var(--canvas);color:var(--ink);">
            <option value="">Todos os clientes</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (string)$filterCust === (string)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <?php /* Contador em LINHA PRÓPRIA, não ao lado do rótulo: o texto cresce
                ("4 de 5 no mapa · 22 sem posição") e, dividindo os 300 px com o
                rótulo, quebrava "Ativos no mapa" no meio. */ ?>
        <div class="panel-label" style="padding-bottom:2px;">Ativos no mapa</div>
        <div id="visible-count" style="font-size:11px;color:var(--muted);padding:0 4px 6px;"></div>

        <?php /* A lista É o filtro do mapa: a caixa decide se o pino aparece,
                e o resto da linha continua centralizando o mapa no veículo. */ ?>
        <?php /* `justify-content` explícito: `.btn` é `inline-flex` + `align-items`
                e NÃO centraliza na horizontal — com `flex:1` esticando o botão, o
                rótulo encosta na esquerda e os dois passam a parecer campos de
                texto, não botões. */ ?>
        <div style="display:flex;gap:6px;margin-bottom:8px;">
            <button type="button" class="btn btn-outline btn-sm" style="flex:1;justify-content:center;padding:5px 0;font-size:11px;" onclick="setAllVisible(true)">Todos</button>
            <button type="button" class="btn btn-outline btn-sm" style="flex:1;justify-content:center;padding:5px 0;font-size:11px;" onclick="setAllVisible(false)">Nenhum</button>
        </div>
        <input type="text" id="device-search" placeholder="Buscar ativo..." oninput="filterDevices()"
               style="width:100%;padding:6px 8px;font-size:12px;border:1px solid var(--hairline);border-radius:var(--radius-sm);margin-bottom:8px;">

        <div id="device-list">
            <?php foreach ($devices as $d): ?>
            <div class="device-list-item" data-imei="<?= htmlspecialchars($d['imei']) ?>" data-name="<?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?>">
                <input type="checkbox" class="device-toggle" <?= $d['has_pos'] ? 'checked' : 'disabled' ?>
                       onchange="toggleDevice('<?= htmlspecialchars($d['imei'], ENT_QUOTES) ?>', this.checked)"
                       onclick="event.stopPropagation()"
                       title="<?= $d['has_pos'] ? 'Exibir no mapa' : 'Sem posição conhecida — nada a exibir' ?>"
                       style="width:14px;height:14px;flex-shrink:0;cursor:<?= $d['has_pos'] ? 'pointer' : 'not-allowed' ?>;accent-color:var(--primary);">
                <div class="device-dot <?= $d['is_online']?'online':'offline' ?>"></div>
                <div style="flex:1;min-width:0;cursor:pointer;" onclick="selectDevice('<?= htmlspecialchars($d['imei'], ENT_QUOTES) ?>')">
                    <div style="font-size:12px;font-weight:500;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?>
                    </div>
                    <?php /* Ignição + horário da última posição no lugar do IMEI
                            (v4.17.6). O IMEI é número de cadastro, não informação
                            de operação: quem olha o mapa quer saber se o motor
                            está ligado e quando o veículo falou pela última vez.
                            Ele continua no `data-imei` e `filterDevices()` continua
                            achando por ele — saiu da vista, não da busca.

                            ⚠️ O horário é o da ÚLTIMA POSIÇÃO
                            (`device_statistics.last_gps_time`), a MESMA leitura de
                            onde vêm o IGN ao lado e a bolinha online/offline à
                            esquerda — NÃO `devices.last_communication`, que conta
                            também heartbeat e evento. As duas fontes na mesma
                            linha produziriam "IGN: ON" carimbado com um instante
                            em que não houve leitura de ignição nenhuma, e a
                            bolinha (offline por `last_gps_time`) contradiria o
                            horário ao lado dela — a mesma classe de contradição
                            que o CLAUDE.md descreve nos "dois online" do produto. */ ?>
                    <?php $ign = $d['ignition'] === null ? '—' : ((int)$d['ignition'] === 1 ? 'ON' : 'OFF'); ?>
                    <div class="device-meta text-mono" style="font-size:10px;color:var(--muted);"
                         title="Ignição e horário da última posição recebida">
                        IGN: <span class="ign-val" style="<?= $ign === 'ON' ? 'color:var(--ink);font-weight:600;' : '' ?>"><?= $ign ?></span>
                        · <span class="last-seen"><?= htmlspecialchars(fmt_brt($d['last_gps_time'], 'd/m H:i', '—')) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$devices): ?>
            <div style="padding:14px 4px;font-size:12px;color:var(--muted);">Nenhum ativo neste escopo.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Mapa -->
    <div class="map-wrap">
        <div id="tracking-map"></div>
        <div id="fleet-legend">
            <?php
            $legendStates = ['movimento', 'ocioso', 'parado', 'excesso', 'offline'];
            $legendColors = array_merge(FLEET_STATE_COLORS, ['excesso' => '#f4b000']);
            $legendLabels = array_merge(FLEET_STATE_LABELS, ['excesso' => 'Excesso de velocidade']);
            foreach ($legendStates as $lst):
            ?>
            <div class="legend-row">
                <span class="legend-dot" style="background:<?= htmlspecialchars($legendColors[$lst]) ?>"></span>
                <span><?= htmlspecialchars($legendLabels[$lst]) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
var FLEET_STATE_COLORS = <?= json_encode(array_merge(FLEET_STATE_COLORS, ['excesso' => '#f4b000'])) ?>;
var FLEET_STATE_LABELS = <?= json_encode(array_merge(FLEET_STATE_LABELS, ['excesso' => 'Excesso de velocidade'])) ?>;
var VEHICLE_ICONS = <?= json_encode(vehicle_icons_js_catalog(), JSON_UNESCAPED_SLASHES) ?>;

var mapData = <?= json_encode(array_map(function($p) {
    return ['imei'=>$p['imei'],'lat'=>(float)$p['latitude'],'lng'=>(float)$p['longitude'],
            'state'=>$p['state'],'vehicleType'=>$p['vehicle_type'],
            'name'=>$p['device_name'],'speed'=>(float)$p['speed'],'ignition'=>$p['ignition'],
            'online'=>$p['state'] !== 'offline','time'=>fmt_brt($p['gps_time'], 'd/m/Y H:i:s', '')];
}, $positions)) ?>;

var map = L.map('tracking-map');
bcMapBaseLayers(map);
var markers = {};
var bounds = [];

// Balão identifica o veículo pela PLACA (p.name = devices.device_name), nunca
// pelo IMEI: quem opera a frota reconhece a placa, não o número do rastreador.
function popupHtml(p) {
    var stateLabel = FLEET_STATE_LABELS[p.state] || '';
    return '<b>Placa: ' + (p.name || p.imei) + '</b><br>Estado: ' + stateLabel +
           '<br>Vel: ' + (p.speed||0) + ' km/h<br>Ignição: ' + (p.ignition==1?'Ligada':'Desligada') + '<br>' + (p.time||'');
}

// Pin = círculo colorido por estado (FLEET_STATE_COLORS) + ícone branco do
// tipo de veículo (Tabler Icons, ver includes/vehicle_icons.php). Sem tipo
// cadastrado, o pin fica só o círculo — comportamento anterior preservado.
function vehicleIconHtml(type, color) {
    var icon = VEHICLE_ICONS[type];
    if (!icon) return '';
    var attrs = icon.stroke
        ? 'fill="none" stroke="' + color + '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        : 'fill="' + color + '"';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' + attrs + '>' + icon.paths + '</svg>';
}

function pinIcon(state, type) {
    var color = FLEET_STATE_COLORS[state] || '#5b616e';
    var svg = vehicleIconHtml(type, '#fff');
    return L.divIcon({
        className: 'vehicle-pin-wrap',
        html: '<div class="vehicle-pin" style="background:' + color + '">' + svg + '</div>',
        iconSize: [26, 26],
        iconAnchor: [13, 13],
        popupAnchor: [0, -13]
    });
}

// ── Quais ativos aparecem no mapa ─────────────────────────────
// Guardamos os OCULTOS, não os visíveis: veículo cadastrado depois nasce
// aparecendo, que é o padrão que não surpreende. Guardar os visíveis faria
// um ativo novo ficar invisível para sempre para quem já tinha mexido aqui.
// A chave é por escopo de cliente — a escolha feita num cliente não faz
// sentido no outro.
var OCULTOS_KEY = 'bc_rastreamento_ocultos_<?= $scopeCust !== null ? (int)$scopeCust : "todos" ?>';
var ocultos = new Set();
try {
    var salvo = JSON.parse(localStorage.getItem(OCULTOS_KEY) || '[]');
    if (Array.isArray(salvo)) ocultos = new Set(salvo);
} catch (e) { /* aba anônima, storage bloqueado: começa com todos visíveis */ }

function persistirOcultos() {
    try { localStorage.setItem(OCULTOS_KEY, JSON.stringify(Array.from(ocultos))); } catch (e) {}
}

function visivel(imei) { return !ocultos.has(imei); }

function aplicarVisibilidade(reenquadrar) {
    var visiveis = [];
    Object.keys(markers).forEach(function(imei) {
        var m = markers[imei];
        if (visivel(imei)) {
            if (!map.hasLayer(m)) m.addTo(map);
            visiveis.push(m.getLatLng());
        } else if (map.hasLayer(m)) {
            map.removeLayer(m);
        }
    });
    document.querySelectorAll('#device-list .device-list-item').forEach(function(el) {
        el.classList.toggle('hidden-on-map', !visivel(el.dataset.imei));
    });
    // "0 de 0 no mapa" ao lado de 20 ativos listados não explica a lacuna: o
    // total do contador são os ativos COM posição conhecida, e os outros nunca
    // vão aparecer no mapa por mais que se marque. Quando existe essa
    // diferença, ela é dita — senão o operador procura um defeito que não há.
    var cnt = document.getElementById('visible-count');
    if (cnt) {
        var comPos = Object.keys(markers).length;
        var semPos = document.querySelectorAll('#device-list .device-toggle[disabled]').length;
        cnt.textContent = visiveis.length + ' de ' + comPos + ' no mapa'
                        + (semPos ? ' · ' + semPos + ' sem posição' : '');
    }
    if (reenquadrar && visiveis.length > 0) map.fitBounds(visiveis, {padding: [30, 30]});
    return visiveis;
}

function toggleDevice(imei, mostrar) {
    if (mostrar) ocultos.delete(imei); else ocultos.add(imei);
    persistirOcultos();
    aplicarVisibilidade(false);
}

function setAllVisible(mostrar) {
    // Só mexe no que a BUSCA está mostrando: com o filtro preenchido,
    // "Nenhum" que apagasse a frota inteira seria uma armadilha silenciosa.
    document.querySelectorAll('#device-list .device-list-item').forEach(function(el) {
        if (el.offsetParent === null) return;              // escondido pela busca
        var cb = el.querySelector('.device-toggle');
        if (!cb || cb.disabled) return;
        cb.checked = mostrar;
        if (mostrar) ocultos.delete(el.dataset.imei); else ocultos.add(el.dataset.imei);
    });
    persistirOcultos();
    aplicarVisibilidade(mostrar);
}

mapData.forEach(function(p) {
    if (p.lat && p.lng && p.lat !== 0) {
        var m = L.marker([p.lat, p.lng], {icon: pinIcon(p.state, p.vehicleType)})
            .bindPopup(popupHtml(p));
        markers[p.imei] = m;
    }
});

// As caixas refletem o que foi guardado ANTES do primeiro enquadramento.
document.querySelectorAll('#device-list .device-toggle').forEach(function(cb) {
    var imei = cb.closest('.device-list-item').dataset.imei;
    if (!cb.disabled) cb.checked = visivel(imei);
});
bounds = aplicarVisibilidade(false);
if (bounds.length > 0) map.fitBounds(bounds, {padding: [30, 30]});
else map.setView([-15.78, -47.93], 5);

function selectDevice(imei) {
    document.querySelectorAll('#device-list .device-list-item').forEach(function(el) { el.classList.remove('selected'); });
    var el = document.querySelector('#device-list [data-imei="' + imei + '"]');
    if (el) el.classList.add('selected');
    var m = markers[imei];
    if (!m) return;
    // Clicar num ativo oculto reexibe: pedir para ver e não ver nada seria
    // um clique que não faz nada, sem dizer por quê.
    if (!visivel(imei)) {
        ocultos.delete(imei);
        persistirOcultos();
        if (el) { var cb = el.querySelector('.device-toggle'); if (cb) cb.checked = true; }
        aplicarVisibilidade(false);
    }
    map.setView(m.getLatLng(), 16);
    m.openPopup();
}

// '' = "Todos os clientes"; sem o parâmetro, report_customer_scope() devolve
// null para admin de plataforma, que é exatamente esse caso.
function selectCustomer(cid) {
    location.href = cid ? ('?customer_id=' + encodeURIComponent(cid)) : '?';
}

function filterDevices() {
    var term = document.getElementById('device-search').value.toLowerCase();
    document.querySelectorAll('#device-list .device-list-item').forEach(function(el) {
        var name = (el.dataset.name||'').toLowerCase();
        var imei = (el.dataset.imei||'').toLowerCase();
        el.style.display = name.indexOf(term) >= 0 || imei.indexOf(term) >= 0 ? '' : 'none';
    });
}

// A linha da lista agora carrega dado AO VIVO (ignição e horário da última
// posição), então o refresh de 30 s tem de atualizá-la junto com os pinos —
// senão a coluna da esquerda congela no estado da carga da página enquanto o
// mapa se move, que é a contradição mais fácil de produzir nesta tela.
function atualizarLinha(p) {
    var el = document.querySelector('#device-list [data-imei="' + p.imei + '"]');
    if (!el) return;

    var ign = el.querySelector('.ign-val');
    if (ign) {
        // `== null` pega null e undefined de uma vez; `Number(0) === 1` é
        // falso, e é o que separa OFF de "sem leitura". Não usar `p.ignition
        // ? …` aqui: 0 é falsy e viraria "—", apagando a diferença entre
        // "motor desligado" e "nunca reportou ignição".
        var v = (p.ignition == null) ? '—' : (Number(p.ignition) === 1 ? 'ON' : 'OFF');
        ign.textContent = v;
        ign.style.color = v === 'ON' ? 'var(--ink)' : '';
        ign.style.fontWeight = v === 'ON' ? '600' : '';
    }

    var ls = el.querySelector('.last-seen');
    if (ls) ls.textContent = p.timeShort || '—';

    var dot = el.querySelector('.device-dot');
    if (dot) { dot.classList.toggle('online', !!p.online); dot.classList.toggle('offline', !p.online); }

    // Ativo que ganhou posição depois da carga da página: sem isto a linha
    // passaria a exibir IGN e horário frescos ao lado de uma caixa ainda
    // desabilitada dizendo "sem posição conhecida" — a linha se contradiria
    // sozinha. O contador de `aplicarVisibilidade()` se recalcula a partir de
    // `[disabled]`, então ele se acerta junto.
    var cb = el.querySelector('.device-toggle');
    if (cb && cb.disabled && p.lat && p.lat !== 0) {
        cb.disabled = false;
        cb.title = 'Exibir no mapa';
        cb.style.cursor = 'pointer';
        cb.checked = visivel(p.imei);
    }
}

setTimeout(function() { map.invalidateSize(); }, 300);

// D2 (v4.2.0 — YUV): auto-refresh 30s sem reload — atualiza pins in-place
setInterval(function() {
    var url = new URL(location.href);
    url.searchParams.set('ajax', '1');
    fetch(url.toString()).then(function(r) { return r.json(); }).then(function(resp) {
        if (!resp || resp.code !== 0) return;
        (resp.positions || []).forEach(function(p) {
            // ANTES do descarte por falta de posição: a linha da lista existe
            // para todo ativo do escopo, inclusive o que ainda não tem ponto,
            // e é justamente nele que o IGN/horário mudando de "—" para um
            // valor real é a informação mais útil da tela.
            atualizarLinha(p);
            if (!p.lat || p.lat === 0) return;
            var m = markers[p.imei];
            if (m) {
                m.setLatLng([p.lat, p.lng]);
                m.setIcon(pinIcon(p.state, p.vehicleType));
                m.setPopupContent(popupHtml(p));
            } else {
                // 🔴 Marcador novo NÃO entra no mapa com `.addTo()` aqui: quem
                // decide é aplicarVisibilidade(). Antes de existir a seleção
                // isso era inofensivo; agora, um `.addTo()` direto faria o
                // ativo que o operador acabou de desmarcar reaparecer sozinho
                // 30 s depois — e o pior é que a caixa continuaria desmarcada,
                // então a tela se contradiria sem ninguém ter feito nada.
                markers[p.imei] = L.marker([p.lat, p.lng], {icon: pinIcon(p.state, p.vehicleType)})
                    .bindPopup(popupHtml(p));
            }
        });
        aplicarVisibilidade(false);
    }).catch(function() {});
}, 30000);
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
