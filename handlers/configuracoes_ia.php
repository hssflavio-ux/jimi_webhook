<?php
/**
 * bycamera — Configurações IA (ADAS/DMS/velocidade) v1.0
 * Endpoint: /configuracoes-ia
 *
 * Tela dedicada aos comandos de configuração de ADAS, DMS e velocidade
 * (proNo 128, catálogo próprio em `includes/ia_config_catalog.php`,
 * reprocessado direto das planilhas oficiais do fabricante — não é o mesmo
 * catálogo de `handlers/comandos.php`, de onde esses comandos foram
 * retirados). Layout de quadros (`.param-cell`), como a aba de parâmetros de
 * `/ativos/{imei}?tab=parametros` — cada parâmetro mostra sua máscara/
 * formato como tag de auxílio.
 *
 * 🔒 ADMINISTRADOR APENAS, mesma razão de `handlers/parametros.php`: escreve
 * configuração em equipamento em operação, e `can()` é permissivo por
 * omissão para quem não tem grupo — não dá pra confiar nela aqui.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fleet_state.php';  // presença: ponto único de "está online?"
require_admin();

$db = Database::getInstance()->getConnection();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$customerId = get_customer_id();

$filtroCust = $_GET['customer_id'] ?? null;
$scopeCust = report_customer_scope($filtroCust, $isAdmin, $customerId);
$scopeSql = $scopeCust !== null ? ' AND d.customer_id = :cid' : '';
$scopeParams = $scopeCust !== null ? [':cid' => $scopeCust] : [];

require_once __DIR__ . '/../includes/ia_config_snapshot.php';

/**
 * Confere se o IMEI está no escopo deste usuário e devolve o modelo — mesma
 * trava de `$scopeSql`/`$scopeParams` usados na lista de equipamentos abaixo,
 * repetida aqui porque as duas ações a seguir respondem ANTES do HTML e não
 * dependem de montar `$devices` inteiro.
 */
function ia_device_no_escopo(PDO $db, string $imei, string $scopeSql, array $scopeParams): ?array
{
    $st = $db->prepare("
        SELECT d.imei, COALESCE(NULLIF(dm.model_name,''), NULLIF(d.device_model,''), '-') AS model_display
          FROM devices d
          LEFT JOIN device_models dm ON d.device_model_id = dm.id
         WHERE d.imei = :imei AND d.is_active = 1 {$scopeSql}
    ");
    $st->execute(array_merge([':imei' => $imei], $scopeParams));
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ── Ação: salvar o perfil de leitura completa ("Ler tudo agora" terminou) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_verify(); // responde 403 + exit sozinho se inválido

    $body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];
    if (($body['action'] ?? '') !== 'save_snapshot') {
        http_response_code(400);
        echo json_encode(['code' => 400, 'message' => 'Ação desconhecida.']);
        exit;
    }

    $imei = trim((string)($body['imei'] ?? ''));
    $commandsText = trim((string)($body['commands_text'] ?? ''));
    $totalCatalogo = max(0, (int)($body['total_catalogo'] ?? 0));
    $totalCapturado = max(0, (int)($body['total_capturado'] ?? 0));

    $dev = $imei !== '' ? ia_device_no_escopo($db, $imei, $scopeSql, $scopeParams) : null;
    if (!$dev || $commandsText === '') {
        http_response_code(422);
        echo json_encode(['code' => 422, 'message' => 'Equipamento fora de escopo ou nenhum comando capturado na leitura.']);
        exit;
    }

    try {
        $capturedAtUtc = gmdate('Y-m-d H:i:s');
        ia_snapshot_save(
            $db, $imei, $dev['model_display'], $commandsText,
            $totalCatalogo, $totalCapturado, $capturedAtUtc,
            $user['id'] ?? null, $scopeCust
        );
        echo json_encode(['code' => 0, 'captured_at' => fmt_brt($capturedAtUtc)]);
    } catch (Throwable $e) {
        Logger::error('configuracoes-ia: falha ao salvar perfil de IA', ['imei' => $imei, 'erro' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode(['code' => 500, 'message' => 'Falha ao salvar o perfil — tente novamente.']);
    }
    exit;
}

// ── Ação: baixar writeconfig.txt (só leitura, GET) ─────────────────────────
if (($_GET['action'] ?? '') === 'baixar-txt') {
    $imei = trim((string)($_GET['imei'] ?? ''));
    $dev = $imei !== '' ? ia_device_no_escopo($db, $imei, $scopeSql, $scopeParams) : null;
    if (!$dev) { http_response_code(404); exit('Equipamento não encontrado neste cliente.'); }

    $snap = ia_snapshot_get($db, $imei);
    if (!$snap) { http_response_code(404); exit('Nenhuma leitura completa salva para este equipamento ainda — use "Ler tudo agora" primeiro.'); }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="writeconfig.txt"');
    header('Content-Length: ' . strlen($snap['commands_text']));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $snap['commands_text'];
    exit;
}

// ── Equipamentos ────────────────────────────────────────────────────────────
// Sem filtro de protocolo: proNo 128 é canal do IoT Hub (a mesma via de
// STATUS#/CHECK#/UPDATE), funciona em câmera JT/T e JIMI igual — não é o
// wire JT/T 808 (ADR-001).
$devices = $db->prepare("
    SELECT d.imei, COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) AS camera_count,
           TIMESTAMPDIFF(MINUTE, " . device_last_seen_sql() . ", UTC_TIMESTAMP()) AS mudo_min
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN device_statistics ds ON ds.imei = d.imei
    WHERE d.is_active = 1 {$scopeSql}
    ORDER BY d.device_name
");
$devices->execute($scopeParams);
$devices = $devices->fetchAll(PDO::FETCH_ASSOC);
// v4.16.0 — ADAS/DMS é câmera com IA: um equipamento sem canal nenhum
// (rastreador da linha JM-VL) não tem o que configurar aqui. A tela já
// degradava sozinha ("nenhum comando para este modelo"), mas oferecer o
// aparelho no seletor para depois dizer que não há nada é o estado vazio que
// o operador lê como defeito. Filtro em PHP e por `camera_count`, sem depender
// da coluna `family` (que só existe depois da migração v4.16.0).
$devices = array_values(array_filter($devices, fn($d) => (int)($d['camera_count'] ?? 1) > 0));
// Presença pelo ponto único (`device_presence()`) — mesma leitura de
// handlers/comandos.php. "Ler tudo agora" dispara um comando por consulta do
// catálogo; sem isso o operador só descobre que a câmera está offline depois
// de ver cada cartão preso em "na fila".
foreach ($devices as &$d) { $d['presenca'] = device_presence(isset($d['mudo_min']) ? (int)$d['mudo_min'] : null); }
unset($d);

// ── Catálogo (ver includes/ia_config_catalog.php) ──────────────────────────
$catalogo = require __DIR__ . '/../includes/ia_config_catalog.php';
$catJs = [];
foreach ($catalogo as $syn => $d) {
    $catJs[] = [
        's' => $syn, 'c' => $d['cmd'], 'n' => $d['nome'], 'd' => $d['desc'],
        'm' => $d['modelos'], 'q' => $d['consulta'] ?? null,
        'qr' => $d['consulta_ref'] ?? null,
        'proc' => $d['procedencia'] ?? 'planilha',
        'p' => array_map(fn($p) => ['p' => $p['p'], 'd' => $p['desc'], 'f' => $p['format'], 'v' => $p['default']], $d['params']),
        'e' => array_map(fn($e) => ['c' => $e['cmd'], 'd' => $e['desc']], $d['exemplos']),
    ];
}

// ── Último valor conhecido por equipamento (device_ia_config_state) ───────
// Carregado para todos os equipamentos do escopo de uma vez — a frota deste
// estágio é pequena; se crescer, isto vira uma consulta por equipamento
// selecionado, sob demanda.
$estado = [];
if ($devices) {
    try {
        $imeis = array_column($devices, 'imei');
        $ph = implode(',', array_fill(0, count($imeis), '?'));
        $st = $db->prepare("SELECT imei, cmd_key, last_response, read_at, requested_value, requested_at
                               FROM device_ia_config_state WHERE imei IN ($ph)");
        $st->execute($imeis);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $estado[$r['imei']][$r['cmd_key']] = [
                'v' => $r['last_response'], 'lido' => $r['read_at'] ? fmt_brt($r['read_at']) : null,
                'ped' => $r['requested_value'], 'pedEm' => $r['requested_at'] ? fmt_brt($r['requested_at']) : null,
            ];
        }
    } catch (Throwable $e) {
        // Migração v4.13.0 não aplicada ainda — a tela funciona sem o
        // "último valor conhecido", só sem essa informação extra.
        Logger::warning('configuracoes-ia: device_ia_config_state indisponível', ['erro' => $e->getMessage()]);
    }
}

// ── Perfil de leitura completa por equipamento (device_ia_config_snapshots) ─
// Mesmo espírito do bloco acima: carregado para toda a frota do escopo de
// uma vez. `commands_text` vai junto (não só o resumo) porque é o que a tela
// usa para "Aplicar em outras câmeras" e para montar o link de download —
// sem outro round-trip ao servidor.
$snapshots = [];
if ($devices) {
    try {
        $imeis = array_column($devices, 'imei');
        $ph = implode(',', array_fill(0, count($imeis), '?'));
        $st = $db->prepare("SELECT imei, model_display, captured_at, total_catalogo, total_capturado, commands_text
                               FROM device_ia_config_snapshots WHERE imei IN ($ph)");
        $st->execute($imeis);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $snapshots[$r['imei']] = [
                'modelo' => $r['model_display'],
                'em' => fmt_brt($r['captured_at']),
                'totCat' => (int)$r['total_catalogo'],
                'totCap' => (int)$r['total_capturado'],
                'texto' => $r['commands_text'],
            ];
        }
    } catch (Throwable $e) {
        // Migração v4.18.2 não aplicada ainda — a tela funciona sem perfil
        // salvo, só sem "última leitura completa"/exportação/TXT.
        Logger::warning('configuracoes-ia: device_ia_config_snapshots indisponível', ['erro' => $e->getMessage()]);
    }
}

$page_title = 'Configurações IA';
$current_route = 'configuracoes-ia';
$extra_head = '<style>
.ia-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;grid-auto-flow:dense;}
.ia-cell{border:1px solid var(--hairline);border-radius:var(--radius-lg);padding:16px 18px;background:var(--surface);transition:box-shadow .15s;}
.ia-cell:hover{box-shadow:var(--shadow-soft);}
.ia-head{display:flex;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:2px;}
.ia-name{font-size:13px;font-weight:600;color:var(--ink);line-height:1.3;}
.ia-syn{font-size:10px;color:var(--muted);font-family:"JetBrains Mono",monospace;flex-shrink:0;}
.ia-desc{font-size:11px;color:var(--muted);margin-bottom:8px;line-height:1.4;}
.ia-known{font-size:11px;background:var(--canvas-soft);border-radius:var(--radius-sm);padding:6px 8px;margin-bottom:8px;}
.ia-known .mono{font-family:"JetBrains Mono",monospace;word-break:break-all;}
.ia-param{margin-bottom:8px;}
.ia-param-top{display:flex;align-items:center;gap:6px;margin-bottom:3px;}
.ia-tag{font-family:"JetBrains Mono",monospace;font-size:10px;font-weight:600;color:#fff;background:var(--primary);border-radius:4px;padding:1px 6px;}
.ia-param-desc{font-size:11px;color:var(--ink);}
.ia-param input{width:100%;padding:8px 10px;border:1px solid var(--hairline);border-radius:var(--radius-md);font-family:"JetBrains Mono",monospace;font-size:12px;transition:border-color .15s,box-shadow .15s;}
.ia-param input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 1px var(--primary);}
.ia-mask{font-size:10px;color:var(--muted);margin-top:3px;line-height:1.35;}
.ia-acts{display:flex;gap:6px;justify-content:flex-end;margin-top:10px;}
.ia-preview{font-family:"JetBrains Mono",monospace;font-size:11px;color:var(--muted);margin-top:6px;word-break:break-all;}
.ia-result{font-size:11px;margin-top:6px;}
.ia-cell-par{grid-column:span 2;}
.ia-par-body{display:grid;grid-template-columns:1fr 1fr;gap:0 24px;}
.ia-sub-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:2px;}
.ia-sub-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--primary);}
.ia-sub:last-child{border-left:1px solid var(--hairline);padding-left:24px;}
@media (max-width:700px){
    .ia-par-body{grid-template-columns:1fr;}
    .ia-sub:last-child{border-left:none;padding-left:0;border-top:1px solid var(--hairline);padding-top:14px;margin-top:14px;}
}
.ia-perfil{border:1px solid var(--hairline);border-radius:var(--radius-lg);background:var(--primary-soft);padding:14px 18px;margin-bottom:16px;display:none;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.ia-perfil-info{font-size:12px;color:var(--ink);}
.ia-perfil-info strong{font-weight:600;}
.ia-perfil-acts{display:flex;gap:8px;flex-wrap:wrap;}
.ia-export{border:1px solid var(--hairline);border-radius:var(--radius-lg);background:var(--surface);padding:16px 18px;margin-bottom:16px;display:none;}
.ia-export-title{font-size:13px;font-weight:600;color:var(--ink);margin-bottom:8px;}
.ia-export-list{display:flex;flex-direction:column;gap:6px;max-height:180px;overflow-y:auto;margin-bottom:12px;padding:4px 0;}
.ia-export-list label{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ink);}
.ia-export-preview{font-family:"JetBrains Mono",monospace;font-size:11px;color:var(--muted);background:var(--canvas-soft);border-radius:var(--radius-sm);padding:8px 10px;max-height:140px;overflow-y:auto;white-space:pre-wrap;word-break:break-all;margin-bottom:12px;}
.ia-export-status{font-size:11px;color:var(--muted);margin-top:8px;}
.ia-export-log-line{font-size:11px;color:var(--muted);border-top:1px solid var(--hairline-soft);padding-top:4px;}
.ia-export-log-line .mono{font-family:"JetBrains Mono",monospace;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Configurações IA</h1>
    </div>
</div>

<div class="card mb-16" style="padding:16px 20px;">
    <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;margin-bottom:4px;">Equipamento</label>
            <select id="ia-device" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:320px;" onchange="iaMontarGrade()">
                <option value="">Selecione…</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= htmlspecialchars($d['imei']) ?>" data-modelo="<?= htmlspecialchars($d['model_display']) ?>" data-presenca="<?= htmlspecialchars($d['presenca']['nivel']) ?>" data-presenca-rotulo="<?= htmlspecialchars($d['presenca']['rotulo']) ?>">
                    <?= htmlspecialchars($d['device_name']) ?> — <?= htmlspecialchars($d['model_display']) ?> (<?= htmlspecialchars($d['imei']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button id="ia-ler-tudo-btn" class="btn btn-primary btn-sm" style="display:none;" onclick="iaLerTudo()">Ler tudo agora</button>
    </div>
    <?php if (!$devices): ?>
    <p style="font-size:12px;color:var(--muted);margin:8px 0 0;">Nenhum equipamento neste cliente.</p>
    <?php endif; ?>
    <div id="ia-ler-tudo-status" style="font-size:11px;color:var(--muted);margin-top:8px;display:none;"></div>
</div>

<div id="ia-perfil" class="ia-perfil">
    <div class="ia-perfil-info" id="ia-perfil-info"></div>
    <div class="ia-perfil-acts">
        <a id="ia-perfil-baixar" class="btn btn-outline btn-sm" href="#" download="writeconfig.txt">Baixar writeconfig.txt</a>
        <button id="ia-perfil-exportar-btn" class="btn btn-outline btn-sm" onclick="iaAbrirExport()">Aplicar em outras câmeras deste modelo</button>
    </div>
</div>

<div id="ia-export" class="ia-export">
    <div class="ia-export-title">Aplicar perfil em outras câmeras <span id="ia-export-modelo" style="color:var(--muted);font-weight:400;"></span></div>
    <div class="ia-export-list" id="ia-export-list"></div>
    <div class="ia-export-title" style="margin-top:4px;">Comandos que serão enviados (<span id="ia-export-total"></span>)</div>
    <div class="ia-export-preview" id="ia-export-preview"></div>
    <div class="ia-acts" style="justify-content:flex-start;">
        <button class="btn btn-outline btn-sm" onclick="iaFecharExport()">Cancelar</button>
        <button id="ia-export-enviar-btn" class="btn btn-primary btn-sm" onclick="iaEnviarExport()">Enviar para as câmeras selecionadas</button>
    </div>
    <div class="ia-export-status" id="ia-export-status"></div>
    <div id="ia-export-log" style="margin-top:8px;display:flex;flex-direction:column;gap:4px;max-height:220px;overflow-y:auto;"></div>
</div>

<div id="ia-vazio" class="card" style="padding:32px;text-align:center;color:var(--muted);">
    Selecione o equipamento para verificar e configurar sua IA.
</div>
<div id="ia-sem-comando" class="card" style="padding:32px;text-align:center;color:var(--muted);display:none;">
    O modelo não tem configuração disponível.
</div>

<div id="ia-grid" class="ia-grid"></div>

<script>
var CATALOGO_IA = <?= json_encode($catJs, JSON_UNESCAPED_UNICODE) ?>;
var ESTADO_IA = <?= json_encode($estado, JSON_UNESCAPED_UNICODE) ?>;
var SNAPSHOTS_IA = <?= json_encode($snapshots, JSON_UNESCAPED_UNICODE) ?>; // perfil de leitura completa por imei

var iaCartoesAtuais = [];   // [{x, cel, result}] do equipamento selecionado agora

function iaMontarGrade() {
    var sel = document.getElementById('ia-device');
    var imei = sel.value;
    var grid = document.getElementById('ia-grid');
    grid.innerHTML = '';
    iaCartoesAtuais = [];
    document.getElementById('ia-vazio').style.display = imei ? 'none' : 'block';
    document.getElementById('ia-sem-comando').style.display = 'none';
    document.getElementById('ia-ler-tudo-btn').style.display = 'none';
    document.getElementById('ia-ler-tudo-status').style.display = 'none';
    document.getElementById('ia-export').style.display = 'none';
    iaRenderPerfil(imei);
    if (!imei) return;

    var modelo = sel.selectedOptions[0].dataset.modelo;
    var itens = CATALOGO_IA.filter(function (x) { return x.m.indexOf(modelo) >= 0; });

    if (!itens.length) { document.getElementById('ia-sem-comando').style.display = 'block'; return; }

    // EVENTSET,<código> (sensibilidade) e EVENTALERT,<código> (alerta) são o
    // par que configura o MESMO evento — sempre mexidos juntos na prática.
    // Agrupa os dois num quadro só quando o catálogo tem os dois lados do
    // mesmo código; o que não tem par (ex.: DMSSP, ADASSW, ou um EVENTALERT
    // sem EVENTSET correspondente como ASCE/AFIS) continua com quadro próprio.
    var porCodigo = {};
    var avulsos = [];
    itens.forEach(function (x) {
        var cod = iaCodigoEvento(x);
        if (!cod) { avulsos.push(x); return; }
        porCodigo[cod] = porCodigo[cod] || {};
        if (x.c === 'EVENTSET') porCodigo[cod].set = x; else porCodigo[cod].alert = x;
    });
    var grupos = [];
    Object.keys(porCodigo).forEach(function (cod) {
        var par = porCodigo[cod];
        if (par.set && par.alert) {
            grupos.push({ tipo: 'par', rotulo: iaRotuloEvento(par.set), set: par.set, alert: par.alert });
        } else {
            avulsos.push(par.set || par.alert);
        }
    });
    avulsos.forEach(function (x) { grupos.push({ tipo: 'solo', rotulo: iaRotuloEvento(x), item: x }); });
    grupos.sort(function (a, b) { return a.rotulo.localeCompare(b.rotulo); });

    grupos.forEach(function (g) {
        if (g.tipo === 'par') {
            var card = iaMontarCardPar(imei, g);
            grid.appendChild(card.cel);
            iaCartoesAtuais.push({ x: g.set, cel: card.cel, result: card.resultSet });
            iaCartoesAtuais.push({ x: g.alert, cel: card.cel, result: card.resultAlert });
        } else {
            var card = iaMontarCard(imei, g.item);
            grid.appendChild(card.cel);
            iaCartoesAtuais.push(card);
        }
    });

    var comConsulta = iaCartoesAtuais.filter(function (c) { return c.x.q; });
    document.getElementById('ia-ler-tudo-btn').style.display = comConsulta.length ? '' : 'none';
}

/**
 * Mostra (ou esconde) a faixa "Última leitura completa" acima da grade — lê
 * SNAPSHOTS_IA, que já veio pronto do servidor e é atualizado em memória
 * assim que uma nova leitura completa termina (ver iaSalvarPerfil()), sem
 * precisar recarregar a página.
 */
function iaRenderPerfil(imei) {
    var box = document.getElementById('ia-perfil');
    var snap = imei ? SNAPSHOTS_IA[imei] : null;
    if (!snap) { box.style.display = 'none'; return; }
    document.getElementById('ia-perfil-info').innerHTML =
        'Última leitura completa em <strong>' + iaEsc(snap.em) + '</strong> — ' +
        snap.totCap + ' de ' + snap.totCat + ' comando(s) capturado(s).';
    document.getElementById('ia-perfil-baixar').href = '/configuracoes-ia?action=baixar-txt&imei=' + encodeURIComponent(imei);
    box.style.display = 'flex';
}

/** Código do evento embutido na sintaxe do catálogo (ex.: "EVENTSET,ALDW,P1#"
 *  → "ALDW"), só para EVENTSET/EVENTALERT — é a chave de pareamento. */
function iaCodigoEvento(x) {
    if (x.c !== 'EVENTSET' && x.c !== 'EVENTALERT') return null;
    var partes = x.s.split(',');
    return partes.length > 1 ? partes[1] : null;
}

/** Nome do evento sem o prefixo "Sensibilidade — "/"Alerta — ", capitalizado
 *  para servir de título do quadro combinado (e de chave de ordenação para
 *  os quadros avulsos, que ficam intercalados por assunto). */
function iaRotuloEvento(x) {
    var s = x.n.replace(/^(Sensibilidade|Alerta)\s*—\s*/, '');
    return s.charAt(0).toUpperCase() + s.slice(1);
}

/**
 * Dispara a forma de consulta de cada comando deste modelo, UM DE CADA VEZ,
 * com um intervalo entre os disparos — "em cadência", não em paralelo.
 * Cada leitura usa o mesmo iaEnviar()/iaAcompanhar() dos cartões, então a
 * resposta aparece no card correspondente assim que chegar, e é gravada em
 * device_ia_config_state pelo mesmo caminho de sempre (sendcommand.php /
 * pushinstructresponse.php) — nada de especial acontece aqui além do
 * espaçamento entre os envios.
 *
 * Comando para equipamento offline é fluxo suportado (o IoT Hub enfileira e
 * entrega no reconecte, como em handlers/comandos.php) — mas "Ler tudo agora"
 * dispara um comando por consulta do catálogo de uma vez, então ficar sem
 * saber que a câmera está offline até ver cada cartão preso em "na fila" é
 * pior aqui do que num envio único. Avisa e deixa o operador decidir.
 *
 * Ao fim — depois que TODA resposta chegou ou desistiu (não quando o último
 * comando é só DISPARADO, que acontece bem antes) — v4.18.2 monta o perfil de
 * configuração desta leitura completa e salva (ver iaSalvarPerfil()).
 */
function iaLerTudo() {
    var sel = document.getElementById('ia-device');
    var imei = sel.value;
    if (!imei) return;
    var modelo = sel.selectedOptions[0].dataset.modelo;
    var fila = iaCartoesAtuais.filter(function (c) { return c.x.q; });
    if (!fila.length) return;

    var presenca = sel.selectedOptions[0].dataset.presenca;
    if (presenca !== 'ok') {
        var rotulo = sel.selectedOptions[0].dataset.presencaRotulo || 'sem contato recente';
        if (!confirm('Este equipamento está ' + rotulo + ', não online agora.\n\n' +
            'Os ' + fila.length + ' comando(s) vão para a fila do equipamento e só chegam quando ele reconectar.\n\n' +
            'Continuar mesmo assim?')) return;
    }

    var btn = document.getElementById('ia-ler-tudo-btn');
    var status = document.getElementById('ia-ler-tudo-status');
    btn.disabled = true;
    status.style.display = 'block';

    var respostas = {};       // x.s (chave do catálogo) -> resposta bruta da câmera, ou null
    var pendentes = fila.length;
    var perfilSalvo = false;

    function talvezSalvarPerfil() {
        if (pendentes > 0 || perfilSalvo) return;
        perfilSalvo = true;
        iaSalvarPerfil(imei, modelo, fila, respostas, status);
    }

    var i = 0;
    var CADENCIA_MS = 2500;
    var passo = function () {
        if (i >= fila.length) {
            status.textContent = 'Concluído — ' + fila.length + ' comando(s) disparado(s). Aguardando as últimas respostas para salvar o perfil…';
            btn.disabled = false;
            return;
        }
        var c = fila[i];
        status.textContent = 'Lendo ' + (i + 1) + ' de ' + fila.length + ': ' + c.x.n + ' (' + c.x.q + ')…';
        iaEnviar(imei, c.x.q, c.result, function (resp) {
            respostas[c.x.s] = resp;
            pendentes--;
            talvezSalvarPerfil();
        });
        i++;
        setTimeout(passo, CADENCIA_MS);
    };
    passo();
}

/**
 * Extrai os N valores de parâmetro da resposta bruta da câmera a uma
 * consulta. Medido em campo (ver cabeçalho de includes/ia_config_catalog.php,
 * 25/08/2026): a câmera responde ecoando a própria consulta e anexando o(s)
 * valor(es) ao final, separados por vírgula — consulta "EVENTSET,ALDW#" →
 * resposta "EVENTSET,ALDW#,60". Por isso a extração pega os ÚLTIMOS N tokens
 * separados por vírgula e tira '#'/espaço de cada um — é uma heurística
 * única para toda a tela (não há parser por comando); falha (devolve null,
 * e o comando fica de fora do perfil) se sobrar token vazio ou faltar token.
 */
function iaExtrairValores(resp, n) {
    if (!n) return [];
    if (!resp) return null;
    var toks = String(resp).split(',');
    if (toks.length < n) return null;
    var valores = toks.slice(toks.length - n).map(function (t) { return t.replace(/#/g, '').trim(); });
    if (valores.some(function (v) { return v === ''; })) return null;
    return valores;
}

/** Mesmo que iaMontarComando(), mas a partir de um array de valores prontos
 *  (não de <input> do DOM) — usado para montar o perfil de leitura completa
 *  e para reaplicar um perfil salvo em outra câmera. */
function iaMontarComandoValores(syn, valores) {
    var corpo = syn.replace(/#$/, '');
    var toks = corpo.split(',');
    var idx = 0;
    var faltou = false;
    var saida = toks.map(function (t) {
        if (!/^P\d+$/.test(t)) return t;
        var v = valores[idx] !== undefined ? String(valores[idx]).trim() : '';
        idx++;
        if (v === '') { faltou = true; }
        return v === '' ? t : v;
    });
    if (faltou) return null;
    return saida.join(',') + '#';
}

/**
 * Ao final de "Ler tudo agora": para cada comando lido com sucesso, monta o
 * comando de ESCRITA equivalente a partir da resposta da câmera (mesma
 * sintaxe do catálogo, valor já substituído) e salva o conjunto — "um por
 * linha, sem comentário" — como o perfil desta leitura completa. É esse
 * texto que a faixa "Última leitura completa" usa para exibir a data, para
 * reenviar a outras câmeras do mesmo modelo e para o writeconfig.txt.
 */
function iaSalvarPerfil(imei, modelo, fila, respostas, statusEl) {
    var linhas = [];
    fila.forEach(function (c) {
        var valores = iaExtrairValores(respostas[c.x.s], c.x.p.length);
        if (!valores) return;
        var cmd = iaMontarComandoValores(c.x.s, valores);
        if (cmd) linhas.push(cmd);
    });

    if (!linhas.length) {
        statusEl.textContent = 'Concluído — nenhuma resposta pôde virar comando de configuração; o perfil não foi salvo.';
        return;
    }

    var payload = {
        action: 'save_snapshot', imei: imei,
        commands_text: linhas.join('\n'),
        total_catalogo: fila.length,
        total_capturado: linhas.length,
    };
    fetch('/configuracoes-ia', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify(payload)
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
        if (j && j.code === 0) {
            SNAPSHOTS_IA[imei] = { modelo: modelo, em: j.captured_at, totCat: fila.length, totCap: linhas.length, texto: payload.commands_text };
            var sel = document.getElementById('ia-device');
            if (sel.value === imei) iaRenderPerfil(imei);
            statusEl.textContent = 'Concluído — perfil salvo (' + linhas.length + ' de ' + fila.length + ' comando(s)), leitura completa em ' + j.captured_at + '.';
        } else {
            statusEl.textContent = 'Concluído — leitura terminou, mas o perfil não pôde ser salvo (' + ((j && j.message) || 'erro') + ').';
        }
    })
    .catch(function () {
        statusEl.textContent = 'Concluído — leitura terminou, mas o perfil não pôde ser salvo (erro de rede).';
    });
}

/** Abre o painel de exportação do perfil salvo para outras câmeras do MESMO
 *  modelo (mesmo cliente/escopo — a lista vem só das opções já carregadas em
 *  #ia-device). Mostra a lista de comandos ANTES de enviar, de propósito:
 *  a extração de valor em iaExtrairValores() é uma heurística, não um parser
 *  medido comando a comando — o operador confere antes de disparar. */
function iaAbrirExport() {
    var sel = document.getElementById('ia-device');
    var imei = sel.value;
    var snap = imei ? SNAPSHOTS_IA[imei] : null;
    if (!snap) return;
    var modelo = sel.selectedOptions[0].dataset.modelo;
    var linhas = snap.texto.split('\n').filter(function (l) { return l.trim() !== ''; });

    document.getElementById('ia-export-modelo').textContent = '— ' + modelo;
    document.getElementById('ia-export-total').textContent = linhas.length;
    document.getElementById('ia-export-preview').textContent = linhas.join('\n');

    var list = document.getElementById('ia-export-list');
    list.innerHTML = '';
    var opcoes = Array.prototype.filter.call(sel.options, function (o) {
        return o.value && o.value !== imei && o.dataset.modelo === modelo;
    });
    if (!opcoes.length) {
        list.innerHTML = '<span style="font-size:12px;color:var(--muted);">Nenhuma outra câmera deste modelo neste cliente.</span>';
    } else {
        opcoes.forEach(function (o) {
            var lbl = document.createElement('label');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = o.value;
            cb.className = 'ia-export-check';
            lbl.appendChild(cb);
            lbl.appendChild(document.createTextNode(' ' + o.textContent.trim()));
            list.appendChild(lbl);
        });
    }
    document.getElementById('ia-export-status').textContent = '';
    document.getElementById('ia-export-enviar-btn').disabled = false;
    document.getElementById('ia-export').style.display = 'block';
    document.getElementById('ia-export').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

function iaFecharExport() {
    document.getElementById('ia-export').style.display = 'none';
}

/** Envia, em cadência (mesmo espaçamento de iaLerTudo), cada linha do perfil
 *  salvo para cada câmera-destino marcada — "aplicar ao vivo, com
 *  confirmação" (decisão do dono do produto). Cada envio passa por
 *  iaEnviar()/sendcommand.php normalmente, então fica registrado em
 *  device_ia_config_state do equipamento de destino como qualquer outro
 *  comando aplicado pela tela. */
function iaEnviarExport() {
    var sel = document.getElementById('ia-device');
    var imei = sel.value;
    var snap = imei ? SNAPSHOTS_IA[imei] : null;
    if (!snap) return;

    var alvos = Array.prototype.filter.call(document.querySelectorAll('.ia-export-check'), function (cb) { return cb.checked; })
        .map(function (cb) { return cb.value; });
    if (!alvos.length) { alert('Selecione ao menos uma câmera de destino.'); return; }

    var linhas = snap.texto.split('\n').filter(function (l) { return l.trim() !== ''; });
    if (!confirm('Isso vai enviar ' + linhas.length + ' comando(s) para ' + alvos.length + ' câmera(s) selecionada(s), SOBRESCREVENDO a configuração de IA atual delas.\n\nContinuar?')) return;

    var status = document.getElementById('ia-export-status');
    var log = document.getElementById('ia-export-log');
    log.innerHTML = '';
    var btn = document.getElementById('ia-export-enviar-btn');
    btn.disabled = true;

    var fila = [];
    alvos.forEach(function (alvoImei) {
        linhas.forEach(function (linha) { fila.push({ imei: alvoImei, cmd: linha }); });
    });

    var i = 0;
    var CADENCIA_MS = 2000;
    var passo = function () {
        if (i >= fila.length) {
            status.textContent = 'Concluído — ' + fila.length + ' comando(s) disparado(s) para ' + alvos.length + ' câmera(s). Detalhe de cada envio abaixo.';
            btn.disabled = false;
            return;
        }
        var item = fila[i];
        status.textContent = 'Enviando ' + (i + 1) + ' de ' + fila.length + ' — ' + item.imei + ': ' + item.cmd;

        var linha = document.createElement('div');
        linha.className = 'ia-export-log-line';
        var rotulo = document.createElement('span');
        rotulo.className = 'mono';
        rotulo.textContent = item.imei + ': ' + item.cmd + ' — ';
        var resultSpan = document.createElement('span');
        linha.appendChild(rotulo);
        linha.appendChild(resultSpan);
        log.appendChild(linha);

        iaEnviar(item.imei, item.cmd, resultSpan);
        i++;
        setTimeout(passo, CADENCIA_MS);
    };
    passo();
}

/**
 * Corpo comum de um comando: valor conhecido, campos de parâmetro, preview,
 * resultado e as ações (Ler agora/Aplicar). Devolve os nós já prontos para
 * anexar e as referências (`inputs`/`result`) que os cartões — solo ou
 * combinado — precisam guardar. Extraído para ser reaproveitado pelos dois
 * lados de um quadro combinado (EVENTSET+EVENTALERT), que precisam de duas
 * instâncias independentes de preview/inputs dentro do MESMO elemento pai.
 */
function iaMontarCorpo(imei, x) {
    var frag = document.createDocumentFragment();

    var conhecido = (ESTADO_IA[imei] || {})[x.s];
    if (conhecido && conhecido.v) {
        var kn = document.createElement('div');
        kn.className = 'ia-known';
        kn.innerHTML = 'Última leitura' + (conhecido.lido ? ' em ' + iaEsc(conhecido.lido) : '') +
            ': <span class="mono">' + iaEsc(conhecido.v) + '</span>' +
            (conhecido.ped ? '<br>Pedido pendente: <span class="mono">' + iaEsc(conhecido.ped) + '</span> — só confirma relendo.' : '');
        frag.appendChild(kn);
    }

    var preview = document.createElement('div');
    preview.className = 'ia-preview';

    var inputs = [];
    x.p.forEach(function (p) {
        var box = document.createElement('div');
        box.className = 'ia-param';
        var top = document.createElement('div');
        top.className = 'ia-param-top';
        top.innerHTML = '<span class="ia-tag">' + iaEsc(p.p) + '</span><span class="ia-param-desc">' + iaEsc(p.d || '') + '</span>';
        box.appendChild(top);

        var inp = document.createElement('input');
        inp.type = 'text';
        inp.placeholder = p.v ? ('padrão: ' + p.v) : ('valor de ' + p.p);
        inp.oninput = function () { iaAtualizarPreview(preview, x.s, inputs); };
        box.appendChild(inp);
        inputs.push(inp);

        if (p.f) {
            var mask = document.createElement('div');
            mask.className = 'ia-mask';
            mask.innerHTML = '<strong>Máscara:</strong> ' + iaEsc(p.f);
            box.appendChild(mask);
        }
        frag.appendChild(box);
    });

    frag.appendChild(preview);

    var result = document.createElement('div');
    result.className = 'ia-result';
    frag.appendChild(result);

    var acts = document.createElement('div');
    acts.className = 'ia-acts';
    if (x.q) {
        var btnLer = document.createElement('button');
        btnLer.className = 'btn btn-outline btn-sm';
        btnLer.textContent = 'Ler agora';
        btnLer.title = x.qr === 'medido'
            ? ('Envia ' + x.q + ' — confirmado em equipamento real')
            : ('Envia ' + x.q + ' — forma de consulta ainda NÃO confirmada em equipamento; usar Ler tudo/Ler agora mede se funciona');
        btnLer.onclick = function () { iaEnviar(imei, x.q, result); };
        acts.appendChild(btnLer);
    }
    var btnAplicar = document.createElement('button');
    btnAplicar.className = 'btn btn-primary btn-sm';
    btnAplicar.textContent = 'Aplicar';
    btnAplicar.onclick = function () {
        var cmd = iaMontarComando(x.s, inputs);
        if (cmd === null) { alert('Preencha todos os parâmetros antes de aplicar.'); return; }
        if (!confirm('Enviar para o equipamento agora?\n\n' + cmd)) return;
        iaEnviar(imei, cmd, result);
    };
    acts.appendChild(btnAplicar);
    frag.appendChild(acts);

    iaAtualizarPreview(preview, x.s, inputs);
    return { frag: frag, inputs: inputs, result: result };
}

function iaMontarCard(imei, x) {
    var cel = document.createElement('div');
    cel.className = 'ia-cell';

    var head = document.createElement('div');
    head.className = 'ia-head';
    head.innerHTML = '<span class="ia-name">' + iaEsc(x.n) +
        '</span><span class="ia-syn">' + iaEsc(x.c) + '</span>';
    cel.appendChild(head);

    if (x.d) {
        var desc = document.createElement('div');
        desc.className = 'ia-desc';
        desc.textContent = x.d;
        cel.appendChild(desc);
    }

    var corpo = iaMontarCorpo(imei, x);
    cel.appendChild(corpo.frag);

    return { x: x, cel: cel, result: corpo.result };
}

/**
 * Quadro combinado para um par EVENTSET (sensibilidade) + EVENTALERT
 * (alerta) do mesmo evento — pedido do dono do produto (25/08/2026): os dois
 * são configurados juntos na prática, então separá-los em dois cartões só
 * obrigava a caçar o par certo na grade. Um cabeçalho com o nome do evento,
 * duas colunas (uma por comando) lado a lado — cada uma com seu próprio
 * "Última leitura", campos, preview e botões, exatamente como um cartão
 * solo, só que compartilhando o quadro.
 */
function iaMontarCardPar(imei, g) {
    var cel = document.createElement('div');
    cel.className = 'ia-cell ia-cell-par';

    var head = document.createElement('div');
    head.className = 'ia-head';
    head.innerHTML = '<span class="ia-name">' + iaEsc(g.rotulo) + '</span>';
    cel.appendChild(head);

    var body = document.createElement('div');
    body.className = 'ia-par-body';
    cel.appendChild(body);

    var resultSet, resultAlert;
    [
        { label: 'Sensibilidade', item: g.set },
        { label: 'Alerta', item: g.alert },
    ].forEach(function (parte) {
        var x = parte.item;
        var sub = document.createElement('div');
        sub.className = 'ia-sub';

        var subHead = document.createElement('div');
        subHead.className = 'ia-sub-head';
        subHead.innerHTML = '<span class="ia-sub-label">' + iaEsc(parte.label) + '</span><span class="ia-syn">' + iaEsc(x.c) + '</span>';
        sub.appendChild(subHead);

        if (x.d) {
            var desc = document.createElement('div');
            desc.className = 'ia-desc';
            desc.textContent = x.d;
            sub.appendChild(desc);
        }

        var corpo = iaMontarCorpo(imei, x);
        sub.appendChild(corpo.frag);
        body.appendChild(sub);

        if (parte.label === 'Sensibilidade') resultSet = corpo.result; else resultAlert = corpo.result;
    });

    return { cel: cel, resultSet: resultSet, resultAlert: resultAlert };
}

function iaEsc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/** Monta a string final substituindo P1..Pn pelos valores digitados, na ordem. */
function iaMontarComando(syn, inputs) {
    var corpo = syn.replace(/#$/, '');
    var toks = corpo.split(',');
    var idx = 0;
    var faltou = false;
    var saida = toks.map(function (t, pos) {
        if (pos === 0) return t;
        if (/^P\d+$/.test(t)) {
            var v = (inputs[idx] ? inputs[idx].value.trim() : '');
            idx++;
            if (v === '') { faltou = true; return t; }
            return v;
        }
        return t;
    });
    if (faltou) return null;
    return saida.join(',') + '#';
}

function iaAtualizarPreview(preview, syn, inputs) {
    var cmd = iaMontarComando(syn, inputs);
    preview.textContent = cmd || 'preencha os campos para ver o comando final';
}

/** Envia via /sendcommand (proNo 128) e acompanha em /commandstatus — mesmo
 *  contrato de handlers/comandos.php. O registro do valor lido/aplicado em
 *  device_ia_config_state acontece no SERVIDOR (pushinstructresponse.php),
 *  não aqui — assim a leitura enfileirada (equipamento offline) também fica
 *  registrada quando a resposta chegar, mesmo com esta aba já fechada.
 *
 *  `onResp(respostaBruta)` é opcional — chamado exatamente uma vez, quando o
 *  ciclo termina (resposta chegou, desistiu, ou falhou), com a resposta
 *  bruta da câmera ou `null` se não chegou nenhuma. iaLerTudo() usa isso para
 *  saber quando TODAS as leituras da cadência realmente terminaram (não só
 *  foram disparadas) e montar o perfil (v4.18.2).
 */
function iaEnviar(imei, conteudo, result, onResp) {
    result.innerHTML = '<span style="color:var(--muted)">enviando…</span>';
    fetch('/sendcommand', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify({ imei: imei, content: conteudo, proNo: 128, serverFlagId: 1 })
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
        var ok = j && (j.code === 0 || j.code === 200);
        if (ok && j.command_id) {
            result.innerHTML = '<span style="color:var(--muted)">enfileirado #' + j.command_id + ' — aguardando…</span>';
            iaAcompanhar(j.command_id, result, onResp);
        } else {
            result.innerHTML = '<span style="color:var(--error)">' + iaEsc((j && j.msg) || 'falhou') + '</span>';
            if (onResp) onResp(null);
        }
    })
    .catch(function () {
        result.innerHTML = '<span style="color:var(--error)">erro de rede</span>';
        if (onResp) onResp(null);
    });
}

function iaAcompanhar(id, result, onResp) {
    var t = 0;
    var tick = function () {
        fetch('/commandstatus?command_id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                var c = (j && j.commands && j.commands[0]) || null;
                if (c && (c.response || (c.titulo && c.titulo !== 'Sem resposta ainda'))) {
                    var cor = c.nivel === 'ok' ? 'var(--success)' : (c.nivel === 'erro' ? 'var(--error)' : 'var(--muted)');
                    result.innerHTML = '<span style="color:' + cor + '">' + iaEsc(c.titulo || '') + '</span>' +
                        (c.response ? '<div class="mono" style="word-break:break-all;">' + iaEsc(c.response) + '</div>' : '');
                    if (c.nivel !== 'aguardando') { if (onResp) onResp(c.response || null); return; }
                }
                if (++t < 12) { setTimeout(tick, t < 8 ? 3000 : 10000); return; }
                result.innerHTML = '<span style="color:var(--muted)">na fila — a resposta aparece quando o equipamento reconectar (recarregue a tela mais tarde)</span>';
                if (onResp) onResp(null);
            })
            .catch(function () {
                if (++t < 12) { setTimeout(tick, 3000); return; }
                if (onResp) onResp(null);
            });
    };
    tick();
}
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
