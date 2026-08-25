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

// ── Equipamentos ────────────────────────────────────────────────────────────
// Sem filtro de protocolo: proNo 128 é canal do IoT Hub (a mesma via de
// STATUS#/CHECK#/UPDATE), funciona em câmera JT/T e JIMI igual — não é o
// wire JT/T 808 (ADR-001).
$devices = $db->prepare("
    SELECT d.imei, COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           TIMESTAMPDIFF(MINUTE, " . device_last_seen_sql() . ", UTC_TIMESTAMP()) AS mudo_min
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN device_statistics ds ON ds.imei = d.imei
    WHERE d.is_active = 1 {$scopeSql}
    ORDER BY d.device_name
");
$devices->execute($scopeParams);
$devices = $devices->fetchAll(PDO::FETCH_ASSOC);
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

$page_title = 'Configurações IA';
$current_route = 'configuracoes-ia';
$extra_head = '<style>
.ia-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:12px;}
.ia-cell{border:1px solid var(--hairline,#e5e7eb);border-radius:10px;padding:14px 16px;background:var(--surface,#fff);}
.ia-cell:hover{box-shadow:0 1px 3px rgba(0,0,0,.08);}
.ia-head{display:flex;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:2px;}
.ia-name{font-size:13px;font-weight:600;color:var(--ink);line-height:1.3;}
.ia-syn{font-size:10px;color:var(--muted);font-family:"JetBrains Mono",monospace;flex-shrink:0;}
.ia-desc{font-size:11px;color:var(--muted);margin-bottom:8px;line-height:1.4;}
.ia-known{font-size:11px;background:var(--canvas-soft,#f4f5f7);border-radius:6px;padding:6px 8px;margin-bottom:8px;}
.ia-known .mono{font-family:"JetBrains Mono",monospace;word-break:break-all;}
.ia-param{margin-bottom:8px;}
.ia-param-top{display:flex;align-items:center;gap:6px;margin-bottom:3px;}
.ia-tag{font-family:"JetBrains Mono",monospace;font-size:10px;font-weight:600;color:#fff;background:var(--primary,#0052ff);border-radius:4px;padding:1px 6px;}
.ia-param-desc{font-size:11px;color:var(--ink);}
.ia-param input{width:100%;padding:6px 8px;border:1px solid var(--hairline,#e5e7eb);border-radius:6px;font-family:"JetBrains Mono",monospace;font-size:12px;}
.ia-mask{font-size:10px;color:var(--muted);margin-top:3px;line-height:1.35;}
.ia-acts{display:flex;gap:6px;justify-content:flex-end;margin-top:10px;}
.ia-preview{font-family:"JetBrains Mono",monospace;font-size:11px;color:var(--muted);margin-top:6px;word-break:break-all;}
.ia-result{font-size:11px;margin-top:6px;}
.ia-cell-par{grid-column:span 2;}
.ia-par-body{display:grid;grid-template-columns:1fr 1fr;gap:0 24px;}
.ia-sub-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:2px;}
.ia-sub-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:var(--primary,#0052ff);}
.ia-sub:last-child{border-left:1px solid var(--hairline,#e5e7eb);padding-left:24px;}
@media (max-width:700px){
    .ia-par-body{grid-template-columns:1fr;}
    .ia-sub:last-child{border-left:none;padding-left:0;border-top:1px solid var(--hairline,#e5e7eb);padding-top:14px;margin-top:14px;}
}
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

<div id="ia-vazio" class="card" style="padding:32px;text-align:center;color:var(--muted);">
    Selecione um equipamento para ver os comandos de IA do modelo dele.
</div>
<div id="ia-sem-comando" class="card" style="padding:32px;text-align:center;color:var(--muted);display:none;">
    O catálogo não documenta comando de ADAS/DMS/velocidade para este modelo.
</div>

<div id="ia-grid" class="ia-grid"></div>

<script>
var CATALOGO_IA = <?= json_encode($catJs, JSON_UNESCAPED_UNICODE) ?>;
var ESTADO_IA = <?= json_encode($estado, JSON_UNESCAPED_UNICODE) ?>;

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
 */
function iaLerTudo() {
    var sel = document.getElementById('ia-device');
    var imei = sel.value;
    if (!imei) return;
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

    var i = 0;
    var CADENCIA_MS = 2500;
    var passo = function () {
        if (i >= fila.length) {
            status.textContent = 'Concluído — ' + fila.length + ' comando(s) disparado(s). As respostas continuam chegando nos cartões.';
            btn.disabled = false;
            return;
        }
        var c = fila[i];
        status.textContent = 'Lendo ' + (i + 1) + ' de ' + fila.length + ': ' + c.x.n + ' (' + c.x.q + ')…';
        iaEnviar(imei, c.x.q, c.result);
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
 */
function iaEnviar(imei, conteudo, result) {
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
            iaAcompanhar(j.command_id, result);
        } else {
            result.innerHTML = '<span style="color:var(--error)">' + iaEsc((j && j.msg) || 'falhou') + '</span>';
        }
    })
    .catch(function () {
        result.innerHTML = '<span style="color:var(--error)">erro de rede</span>';
    });
}

function iaAcompanhar(id, result) {
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
                    if (c.nivel !== 'aguardando') return;
                }
                if (++t < 12) { setTimeout(tick, t < 8 ? 3000 : 10000); return; }
                result.innerHTML = '<span style="color:var(--muted)">na fila — a resposta aparece quando o equipamento reconectar (recarregue a tela mais tarde)</span>';
            })
            .catch(function () {
                if (++t < 12) setTimeout(tick, 3000);
            });
    };
    tick();
}
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
