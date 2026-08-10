<?php
/**
 * JIMI Webhook System — Comandos v4.9.7
 * Rota: /comandos
 *
 * Envio de comandos com a lista SENSÍVEL AO MODELO do equipamento.
 *
 * O catálogo (`includes/command_catalog.php`) é gerado da wiki Foco na Via e
 * diz, por comando, quais modelos o documentam. Daí saem as duas regras da
 * tela:
 *
 *   1. **Trava de modelo.** Marcar um JC371 desabilita os equipamentos de outro
 *      modelo, porque um comando específico (`DMSSP`, `EVENTALERT`…) só existe
 *      naquele modelo e mandá-lo para outro devolve "comando não suportado" —
 *      um erro que só aparece minutos depois, no callback.
 *   2. **Exceção do proNo 128 universal.** Comando presente em 5+ das 6 páginas
 *      da wiki (`STATUS#`, `VERSION#`, `REBOOT#`, `SERVER`…) é o núcleo comum do
 *      protocolo de texto: com um desses escolhido a trava solta e dá para
 *      mandar para a frota inteira de uma vez.
 *
 * O envio em lote NÃO virou um endpoint novo: o frontend chama `/sendcommand`
 * uma vez por equipamento. Assim a checagem de posse por IMEI, o log e o
 * registro em `commands` continuam exatamente como estavam — um endpoint de
 * lote teria de reimplementar tudo isso e é caminho crítico de despacho.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/command_response.php';
// A permissão de tela é aplicada pelo router (`$screenByHandler`); repetir aqui
// só duplicaria a checagem.
require_login();

$db          = Database::getInstance()->getConnection();
$customer_id = get_customer_id();

/** Data/hora curta em BRT para as grades desta tela. */
function fmt_brt_cmd($dt) { return fmt_brt($dt, 'd/m H:i:s'); }

$vsc    = video_stream_config();
$fsUrl  = getenv('FILE_STORAGE_URL') ?: 'http://localhost:23010/download/';
$fsHost = parse_url($fsUrl, PHP_URL_HOST) ?: 'localhost';
$fsPort = parse_url($fsUrl, PHP_URL_PORT) ?: 23010;

// ── Equipamentos do cliente ────────────────────────────────────────────────
$devices = $db->prepare("
    SELECT d.imei,
           COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           COALESCE(dm.protocol, 'JIMI') AS protocol,
           COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) AS camera_count
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = :cid AND d.is_active = 1
    ORDER BY d.device_name
");
$devices->execute([':cid' => $customer_id]);
$devices = $devices->fetchAll(PDO::FETCH_ASSOC);

// ── Catálogo de comandos de texto (proNo 128) ──────────────────────────────
$catalogo = require __DIR__ . '/../includes/command_catalog.php';

$rotuloCat = [
    'ia' => 'Inteligência artificial (DMS/ADAS)', 'video' => 'Vídeo e gravação',
    'rede' => 'Rede e servidor', 'posicao' => 'Posição e telemetria',
    'audio' => 'Áudio e voz', 'energia' => 'Energia e relé',
    'alarme' => 'Alarmes e eventos', 'manutencao' => 'Manutenção e diagnóstico',
    'outros' => 'Outros',
];

// Enxuga o catálogo para o JS: só o que a tela usa.
$catJs = [];
foreach ($catalogo as $syn => $d) {
    $catJs[] = [
        's' => $syn, 'c' => $d['cmd'], 'n' => $d['nome'],
        'd' => $d['desc'], 'k' => $d['categoria'],
        'm' => $d['modelos'], 'u' => (bool)$d['universal'], 't' => (bool)$d['template'],
        'p' => array_map(fn($p) => ['p' => $p['p'], 'd' => $p['desc'],
                                    'f' => $p['format'], 'v' => $p['default']], $d['params']),
        'e' => array_map(fn($e) => ['c' => $e['cmd'], 'd' => $e['desc']], $d['exemplos']),
    ];
}

// ── Comandos JT/T estruturados (proNo próprio, só para modelos JT/T) ───────
$jttCmds = [
    ['pro' => 37121, 'n' => 'Vídeo ao vivo',       'k' => 'video',
     'c' => json_encode(['dataType'=>0,'codeStreamType'=>0,'channel'=>'1','videoIP'=>$vsc['ingest_ip'],'videoPort'=>$vsc['ingest_port']])],
    ['pro' => 37377, 'n' => 'Playback de gravação', 'k' => 'video',
     'c' => json_encode(['channel'=>1,'channelId'=>1,'beginTime'=>'','endTime'=>'','videoIP'=>$vsc['ingest_ip'],'videoPort'=>$vsc['playback_port']])],
    ['pro' => 37381, 'n' => 'Lista de gravações',   'k' => 'video',
     'c' => json_encode(['channel'=>1,'beginTime'=>'','endTime'=>'','alarmFlag'=>0,'resourceType'=>0,'codeType'=>0,'storageType'=>0])],
    ['pro' => 37382, 'n' => 'Upload FTP da gravação','k' => 'video',
     'c' => '{"serverAddress":"","ftpPort":21,"userName":"","password":"","fileUploadPath":"/","channel":1,"beginTime":"","endTime":"","alarmFlag":0,"resourceType":0,"codeType":0,"storageType":0,"condition":7,"instructionID":""}'],
    ['pro' => 33536, 'n' => 'TTS (mensagem de voz)','k' => 'audio', 'c' => '{"text":"","volume":5}'],
    ['pro' => 34817, 'n' => 'Foto',                 'k' => 'video', 'c' => '{"channelId":1,"photoType":0}'],
    ['pro' => 33283, 'n' => 'Confirmar alarme',     'k' => 'alarme', 'c' => '{"alarmSerialNo":0}'],
    ['pro' => 33028, 'n' => 'Consultar parâmetros', 'k' => 'manutencao', 'c' => '{}'],
    ['pro' => 33031, 'n' => 'Info do dispositivo',  'k' => 'manutencao', 'c' => '{}'],
];

// ── Histórico, já interpretado do lado do servidor ─────────────────────────
$filtroImei   = trim($_GET['imei'] ?? '');
$filtroDesf   = trim($_GET['desfecho'] ?? '');
$hist = $db->prepare("
    SELECT c.id, c.imei, c.command_content, c.status, c.response_payload,
           c.created_at, c.response_time,
           COALESCE(NULLIF(d.device_name,''), c.imei) AS device_name,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display
    FROM commands c
    JOIN devices d ON c.imei = d.imei
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = :cid
      AND (:imei = '' OR c.imei = :imei2)
    ORDER BY c.created_at DESC LIMIT 200
");
$hist->execute([':cid' => $customer_id, ':imei' => $filtroImei, ':imei2' => $filtroImei]);
$hist = $hist->fetchAll(PDO::FETCH_ASSOC);

$linhas = [];
$resumo = ['ok' => 0, 'aguardando' => 0, 'erro' => 0, 'neutro' => 0];
foreach ($hist as $h) {
    $env  = command_response_extract($h['response_payload']);
    $desf = command_response_interpret($env['texto'], $env['codigo']);
    if ($filtroDesf !== '' && $desf['nivel'] !== $filtroDesf) continue;
    $resumo[$desf['nivel']] = ($resumo[$desf['nivel']] ?? 0) + 1;

    // Tempo até a resposta — o que diz se o device está respondendo rápido
    $espera = '';
    if (!empty($h['response_time']) && !empty($h['created_at'])) {
        $s = strtotime($h['response_time']) - strtotime($h['created_at']);
        if ($s >= 0) $espera = $s < 60 ? "{$s}s" : floor($s / 60) . 'min';
    }

    $linhas[] = [
        'id' => $h['id'], 'imei' => $h['imei'], 'placa' => $h['device_name'],
        'modelo' => $h['model_display'],
        'rotulo' => command_label((string)$h['command_content']),
        'enviado' => (string)$h['command_content'],
        'quando' => fmt_brt_cmd($h['created_at']),
        'espera' => $espera, 'status' => $h['status'], 'desfecho' => $desf,
        'kv' => command_response_kv($desf['detalhe']),
    ];
}

$deviceJson = json_encode($devices, JSON_UNESCAPED_UNICODE);
$page_title    = 'Comandos';
$current_route = 'comandos';

$extra_head = '<style>
.dev-list { max-height:230px; overflow-y:auto; border:1px solid var(--hairline); border-radius:var(--radius-sm); }
.dev-row { display:flex; align-items:center; gap:10px; padding:8px 10px; border-bottom:1px solid var(--hairline-soft); font-size:13px; cursor:pointer; }
.dev-row:last-child { border-bottom:0; }
.dev-row:hover:not(.dev-off) { background:var(--canvas-soft); }
.dev-row.dev-off { opacity:.42; cursor:not-allowed; }
.dev-row input { width:auto; margin:0; }
.dev-model { font-size:11px; color:var(--muted); font-family:"JetBrains Mono",monospace; }
.lock-note { font-size:12px; padding:8px 10px; border-radius:var(--radius-sm); background:#fdf3e8; border:1px solid #fce8d0; color:#8a5a20; margin-top:8px; }
.lock-note.free { background:#f0faf5; border-color:#d4f0e2; color:#0a7a52; }
.param-grid { display:flex; flex-direction:column; gap:10px; }
.param-item { border:1px solid var(--hairline); border-radius:var(--radius-sm); padding:10px; background:var(--canvas-soft); }
.param-head { display:flex; align-items:baseline; gap:8px; margin-bottom:5px; }
.param-tag { font-family:"JetBrains Mono",monospace; font-size:11px; font-weight:700; color:var(--brand); background:#e8f0ff; padding:1px 6px; border-radius:4px; }
.param-desc { font-size:12px; color:var(--ink); font-weight:500; }
.param-fmt { font-size:11px; color:var(--muted); margin-top:4px; line-height:1.5; }
.param-item input { margin-top:6px; font-family:"JetBrains Mono",monospace; font-size:12px; }
.cmd-preview { font-family:"JetBrains Mono",monospace; font-size:13px; background:#0a0b0d; color:#7fe3a8; padding:10px 12px; border-radius:var(--radius-sm); word-break:break-all; }
.ex-chip { display:inline-block; font-family:"JetBrains Mono",monospace; font-size:11px; background:var(--canvas-soft); border:1px solid var(--hairline); border-radius:100px; padding:3px 10px; margin:3px 4px 3px 0; cursor:pointer; }
.ex-chip:hover { border-color:var(--brand); color:var(--brand); }
.res-row { display:flex; gap:8px; align-items:flex-start; padding:6px 0; font-size:12px; border-bottom:1px solid var(--hairline-soft); }
.res-dot { width:8px; height:8px; border-radius:50%; margin-top:5px; flex-shrink:0; }
.dot-ok{background:#0a7a52}.dot-erro{background:#cf2d56}.dot-aguardando{background:#c08532}.dot-neutro{background:#8a919e}
.kv-grid { display:grid; grid-template-columns:auto 1fr; gap:2px 10px; font-size:11px; margin-top:4px; }
.kv-k { color:var(--muted); }
.kv-v { font-family:"JetBrains Mono",monospace; color:var(--ink); }
</style>';

include __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px">

  <!-- ══════════ ENVIO ══════════ -->
  <div class="card">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px">Enviar comando</h4>

    <!-- 1. Equipamentos -->
    <div class="form-group">
      <label style="display:flex;justify-content:space-between;align-items:baseline">
        <span>Equipamentos</span>
        <span style="font-size:11px;font-weight:400;color:var(--muted)">
          <a href="#" onclick="marcarTodos(true);return false">todos compatíveis</a> ·
          <a href="#" onclick="marcarTodos(false);return false">limpar</a>
        </span>
      </label>
      <div class="dev-list" id="dev-list">
        <?php foreach ($devices as $d): ?>
        <label class="dev-row" data-imei="<?= htmlspecialchars($d['imei']) ?>"
               data-modelo="<?= htmlspecialchars($d['model_display']) ?>"
               data-proto="<?= htmlspecialchars($d['protocol']) ?>"
               data-cams="<?= (int)$d['camera_count'] ?>">
          <input type="checkbox" class="dev-chk" value="<?= htmlspecialchars($d['imei']) ?>" onchange="aoMarcarDevice()">
          <span style="flex:1"><?= htmlspecialchars($d['device_name']) ?></span>
          <span class="dev-model"><?= htmlspecialchars($d['model_display']) ?></span>
          <span class="badge" style="font-size:10px"><?= $d['protocol'] === 'JIMI' ? 'JIMI' : 'JT/T' ?></span>
        </label>
        <?php endforeach; ?>
        <?php if (empty($devices)): ?>
        <div style="padding:16px;text-align:center;color:var(--muted);font-size:13px">Nenhum equipamento ativo.</div>
        <?php endif; ?>
      </div>
      <div id="lock-note" class="lock-note" style="display:none"></div>
    </div>

    <!-- 2. Comando -->
    <div class="form-group">
      <label for="cmd-busca">Comando</label>
      <input type="text" id="cmd-busca" placeholder="Buscar por nome ou sintaxe (ex.: volume, DMSSP, reiniciar)"
             oninput="montarListaComandos()" style="margin-bottom:6px">
      <select id="cmd-sel" size="9" onchange="aoEscolherComando()"
              style="width:100%;font-size:13px;font-family:'JetBrains Mono',monospace"></select>
      <div id="cmd-conta" style="font-size:11px;color:var(--muted);margin-top:4px"></div>
    </div>

    <!-- 3. Painel do comando -->
    <div id="cmd-painel" style="display:none">
      <div style="padding:10px;border-left:3px solid var(--brand);background:var(--canvas-soft);border-radius:var(--radius-sm);margin-bottom:12px">
        <div id="p-nome" style="font-size:13px;font-weight:600;color:var(--ink)"></div>
        <div id="p-desc" style="font-size:12px;color:var(--muted);margin-top:3px;line-height:1.5"></div>
        <div id="p-modelos" style="font-size:11px;margin-top:6px"></div>
      </div>

      <div id="p-params-wrap" style="display:none;margin-bottom:12px">
        <label style="display:block;margin-bottom:6px">Parâmetros</label>
        <div class="param-grid" id="p-params"></div>
      </div>

      <div id="p-ex-wrap" style="display:none;margin-bottom:12px">
        <label style="display:block;margin-bottom:4px">Exemplos da documentação <span style="font-weight:400;color:var(--muted);font-size:11px">(clique para preencher)</span></label>
        <div id="p-ex"></div>
      </div>

      <div class="form-group">
        <label>Será enviado</label>
        <div class="cmd-preview" id="p-preview">—</div>
        <label style="display:flex;align-items:center;gap:7px;font-size:12px;margin-top:8px;cursor:pointer;font-weight:400">
          <input type="checkbox" id="p-livre" style="width:auto" onchange="alternarLivre()">
          Editar manualmente (modo livre)
        </label>
        <input type="text" id="p-manual" style="display:none;margin-top:6px;font-family:'JetBrains Mono',monospace"
               oninput="atualizarPreview()" placeholder="Digite o comando exatamente como será enviado">
      </div>
    </div>

    <div id="envio-feedback" style="font-size:13px;margin:8px 0"></div>
    <div id="envio-resultados" style="display:none;margin:10px 0"></div>

    <button type="button" id="btn-enviar" class="btn btn-primary" disabled onclick="enviarLote()">
      Selecione equipamento e comando
    </button>
  </div>

  <!-- ══════════ HISTÓRICO ══════════ -->
  <div class="card">
    <div class="flex-between" style="margin-bottom:10px">
      <h4 style="font-size:14px;font-weight:600;color:var(--ink)">Histórico de envios</h4>
      <span style="font-size:12px;color:var(--muted)"><?= count($linhas) ?> registros</span>
    </div>

    <form method="get" style="display:flex;gap:6px;margin-bottom:10px;flex-wrap:wrap">
      <select name="imei" style="flex:1;min-width:130px;font-size:12px;padding:6px 8px">
        <option value="">Todos os equipamentos</option>
        <?php foreach ($devices as $d): ?>
        <option value="<?= htmlspecialchars($d['imei']) ?>" <?= $filtroImei === $d['imei'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($d['device_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
      <select name="desfecho" style="min-width:120px;font-size:12px;padding:6px 8px">
        <option value="">Todos os desfechos</option>
        <option value="ok"         <?= $filtroDesf==='ok'?'selected':'' ?>>Executado</option>
        <option value="aguardando" <?= $filtroDesf==='aguardando'?'selected':'' ?>>Aguardando</option>
        <option value="erro"       <?= $filtroDesf==='erro'?'selected':'' ?>>Com erro</option>
        <option value="neutro"     <?= $filtroDesf==='neutro'?'selected':'' ?>>Informativo</option>
      </select>
      <button class="btn btn-outline btn-sm" type="submit">Filtrar</button>
    </form>

    <div style="display:flex;gap:12px;font-size:11px;color:var(--muted);margin-bottom:8px">
      <span><span class="res-dot dot-ok" style="display:inline-block"></span> <?= $resumo['ok'] ?> executados</span>
      <span><span class="res-dot dot-aguardando" style="display:inline-block"></span> <?= $resumo['aguardando'] ?> aguardando</span>
      <span><span class="res-dot dot-erro" style="display:inline-block"></span> <?= $resumo['erro'] ?> com erro</span>
    </div>

    <div style="max-height:520px;overflow-y:auto">
      <table style="font-size:12px;width:100%">
        <thead><tr>
          <th>Quando</th><th>Placa</th><th>Comando</th><th>Desfecho</th><th>Espera</th>
        </tr></thead>
        <tbody>
        <?php foreach ($linhas as $l): ?>
          <tr style="cursor:pointer" onclick="verDetalhe(<?= (int)$l['id'] ?>)">
            <td style="white-space:nowrap"><?= htmlspecialchars($l['quando']) ?></td>
            <td>
              <?= htmlspecialchars($l['placa']) ?>
              <div class="dev-model"><?= htmlspecialchars($l['modelo']) ?></div>
            </td>
            <td>
              <?= htmlspecialchars($l['rotulo']) ?>
              <div class="dev-model" style="font-size:10px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($l['enviado']) ?></div>
            </td>
            <td>
              <div style="display:flex;gap:6px;align-items:flex-start">
                <span class="res-dot dot-<?= htmlspecialchars($l['desfecho']['nivel']) ?>" style="margin-top:4px"></span>
                <span><?= htmlspecialchars($l['desfecho']['titulo']) ?></span>
              </div>
              <?php if ($l['kv']): ?>
                <div class="kv-grid">
                  <?php foreach (array_slice($l['kv'], 0, 3) as $k => $v): ?>
                    <span class="kv-k"><?= htmlspecialchars($k) ?></span><span class="kv-v"><?= htmlspecialchars($v) ?></span>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </td>
            <td style="white-space:nowrap;color:var(--muted)"><?= htmlspecialchars($l['espera'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($linhas)): ?>
          <tr><td colspan="5"><div class="empty-state"><p>Nenhum comando no filtro atual.</p></div></td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal detalhe -->
<div id="detail-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;align-items:center;justify-content:center" onclick="this.style.display='none'">
  <div class="card" style="max-width:640px;width:92%;max-height:82vh;overflow-y:auto" onclick="event.stopPropagation()">
    <div class="flex-between" style="margin-bottom:12px">
      <h4 style="font-size:14px;font-weight:600;color:var(--ink)">Detalhe do comando</h4>
      <button class="btn btn-outline btn-sm" onclick="document.getElementById('detail-modal').style.display='none'">Fechar</button>
    </div>
    <div id="detail-content"></div>
  </div>
</div>

<script>
var DEVICES  = <?= $deviceJson ?>;
var CATALOGO = <?= json_encode($catJs, JSON_UNESCAPED_UNICODE) ?>;
var JTT      = <?= json_encode($jttCmds, JSON_UNESCAPED_UNICODE) ?>;
var ROTCAT   = <?= json_encode($rotuloCat, JSON_UNESCAPED_UNICODE) ?>;
var LINHAS   = <?= json_encode($linhas, JSON_UNESCAPED_UNICODE) ?>;

var cmdAtual = null;   // entrada do catálogo escolhida
var proAtual = 128;

/** Modelos atualmente marcados (Set). */
function modelosMarcados() {
    var s = {};
    document.querySelectorAll('.dev-chk:checked').forEach(function (c) {
        s[c.closest('.dev-row').dataset.modelo] = 1;
    });
    return Object.keys(s);
}
function imeisMarcados() {
    return Array.prototype.map.call(document.querySelectorAll('.dev-chk:checked'), function (c) { return c.value; });
}

/**
 * Aplica a trava: com um comando NÃO universal escolhido, só ficam habilitados
 * os equipamentos cujo modelo a documentação cobre. Sem comando escolhido, o
 * primeiro modelo marcado define a trava.
 */
function aplicarTrava() {
    var nota = document.getElementById('lock-note');
    var marcados = modelosMarcados();
    var permitidos = null, motivo = '';

    if (cmdAtual && !cmdAtual.u) {
        permitidos = cmdAtual.m;
        motivo = '<strong>' + cmdAtual.c + '</strong> é documentado só para ' + cmdAtual.m.join(', ') +
                 '. Equipamentos de outro modelo estão desabilitados — enviar para eles devolveria ' +
                 '“comando não suportado” minutos depois, no callback.';
    } else if (cmdAtual && cmdAtual.u) {
        permitidos = null;
        motivo = '<strong>' + cmdAtual.c + '</strong> é do núcleo comum do proNo 128 (documentado em ' +
                 cmdAtual.m.length + ' modelos). Trava liberada: dá para enviar para a frota inteira.';
    } else if (marcados.length === 1) {
        permitidos = null;
        motivo = '';
    }

    document.querySelectorAll('.dev-row').forEach(function (row) {
        var chk = row.querySelector('.dev-chk');
        var ok = !permitidos || permitidos.indexOf(row.dataset.modelo) >= 0;
        chk.disabled = !ok;
        row.classList.toggle('dev-off', !ok);
        if (!ok && chk.checked) chk.checked = false;
    });

    if (motivo) {
        nota.style.display = 'block';
        nota.className = 'lock-note' + (cmdAtual && cmdAtual.u ? ' free' : '');
        nota.innerHTML = motivo;
    } else {
        nota.style.display = 'none';
    }
    atualizarBotao();
}

function aoMarcarDevice() { montarListaComandos(); aplicarTrava(); }

function marcarTodos(v) {
    document.querySelectorAll('.dev-chk').forEach(function (c) { if (!c.disabled) c.checked = v; });
    aoMarcarDevice();
}

/**
 * Lista de comandos filtrada pelos modelos marcados. Comando que nenhum
 * equipamento marcado suporta simplesmente não aparece — mostrar e desabilitar
 * encheria a lista de 119 itens de ruído.
 */
function montarListaComandos() {
    var busca = (document.getElementById('cmd-busca').value || '').toLowerCase().trim();
    var mods  = modelosMarcados();
    var protos = {};
    document.querySelectorAll('.dev-chk:checked').forEach(function (c) {
        protos[c.closest('.dev-row').dataset.proto] = 1;
    });

    var itens = CATALOGO.filter(function (x) {
        if (mods.length && !x.u && !mods.some(function (m) { return x.m.indexOf(m) >= 0; })) return false;
        if (!busca) return true;
        return (x.n + ' ' + x.s + ' ' + x.c + ' ' + x.d).toLowerCase().indexOf(busca) >= 0;
    }).map(function (x) { return { tipo: 'txt', k: x.k, rot: x.n + '  [' + x.s + ']', val: 'T:' + x.s, u: x.u }; });

    // JT/T estruturado só entra se todos os marcados forem JT/T
    var soJtt = mods.length > 0 && !protos['JIMI'];
    if (soJtt || mods.length === 0) {
        JTT.forEach(function (j) {
            if (busca && (j.n + ' ' + j.pro).toLowerCase().indexOf(busca) < 0) return;
            itens.push({ tipo: 'jtt', k: j.k, rot: j.n + '  [proNo ' + j.pro + ']', val: 'J:' + j.pro, u: false });
        });
    }

    var porCat = {};
    itens.forEach(function (i) { (porCat[i.k] = porCat[i.k] || []).push(i); });

    var sel = document.getElementById('cmd-sel');
    var antes = sel.value;
    sel.innerHTML = '';
    Object.keys(porCat).sort().forEach(function (k) {
        var g = document.createElement('optgroup');
        g.label = ROTCAT[k] || k;
        porCat[k].sort(function (a, b) { return a.rot.localeCompare(b.rot); }).forEach(function (i) {
            var o = document.createElement('option');
            o.value = i.val;
            o.textContent = (i.u ? '★ ' : '') + i.rot;
            if (i.u) o.title = 'Universal — vale para todos os modelos (proNo 128)';
            g.appendChild(o);
        });
        sel.appendChild(g);
    });
    if (antes) sel.value = antes;

    document.getElementById('cmd-conta').textContent =
        itens.length + ' comando(s) disponível(is)' +
        (mods.length ? ' para ' + mods.join(', ') : '') + '. ★ = universal (proNo 128).';
}

function aoEscolherComando() {
    var v = document.getElementById('cmd-sel').value;
    var painel = document.getElementById('cmd-painel');
    if (!v) { painel.style.display = 'none'; cmdAtual = null; aplicarTrava(); return; }

    if (v.charAt(0) === 'J') {
        var pro = parseInt(v.slice(2), 10);
        var j = JTT.filter(function (x) { return x.pro === pro; })[0];
        cmdAtual = null; proAtual = pro;
        painel.style.display = 'block';
        document.getElementById('p-nome').textContent = j.n + ' (proNo ' + pro + ')';
        document.getElementById('p-desc').textContent = 'Comando estruturado JT/T 808. O conteúdo é JSON — ajuste os campos no modo livre.';
        document.getElementById('p-modelos').innerHTML = '<span class="badge badge-info">só modelos JT/T</span>';
        document.getElementById('p-params-wrap').style.display = 'none';
        document.getElementById('p-ex-wrap').style.display = 'none';
        document.getElementById('p-livre').checked = true;
        alternarLivre();
        document.getElementById('p-manual').value = j.c;
        atualizarPreview();
        aplicarTrava();
        return;
    }

    var syn = v.slice(2);
    cmdAtual = CATALOGO.filter(function (x) { return x.s === syn; })[0];
    proAtual = 128;
    if (!cmdAtual) { painel.style.display = 'none'; return; }

    painel.style.display = 'block';
    document.getElementById('p-nome').textContent = cmdAtual.n + '  —  ' + cmdAtual.s;
    document.getElementById('p-desc').textContent = cmdAtual.d || '';
    document.getElementById('p-modelos').innerHTML =
        (cmdAtual.u ? '<span class="badge badge-success">universal (proNo 128)</span> ' : '') +
        cmdAtual.m.map(function (m) { return '<span class="badge" style="font-size:10px">' + m + '</span>'; }).join(' ');

    // Campos de parâmetro
    var wrap = document.getElementById('p-params-wrap');
    var box  = document.getElementById('p-params');
    box.innerHTML = '';
    if (cmdAtual.p && cmdAtual.p.length) {
        cmdAtual.p.forEach(function (p, i) {
            var d = document.createElement('div');
            d.className = 'param-item';
            d.innerHTML =
                '<div class="param-head"><span class="param-tag">' + esc(p.p) + '</span>' +
                '<span class="param-desc">' + esc(p.d || '') + '</span></div>' +
                (p.f ? '<div class="param-fmt"><strong>Formato aceito:</strong> ' + esc(p.f) + '</div>' : '') +
                (p.v ? '<div class="param-fmt"><strong>Padrão de fábrica:</strong> ' + esc(p.v) + '</div>' : '') +
                '<input type="text" class="p-in" data-i="' + i + '" oninput="atualizarPreview()" placeholder="valor de ' + esc(p.p) + '">';
            box.appendChild(d);
        });
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
    }

    // Exemplos clicáveis
    var exw = document.getElementById('p-ex-wrap'), exb = document.getElementById('p-ex');
    exb.innerHTML = '';
    if (cmdAtual.e && cmdAtual.e.length) {
        cmdAtual.e.forEach(function (e) {
            var c = document.createElement('span');
            c.className = 'ex-chip';
            c.textContent = e.c;
            c.title = e.d || '';
            c.onclick = function () { usarExemplo(e.c); };
            exb.appendChild(c);
        });
        exw.style.display = 'block';
    } else { exw.style.display = 'none'; }

    document.getElementById('p-livre').checked = false;
    alternarLivre();
    atualizarPreview();
    aplicarTrava();
}

/** Preenche os campos a partir de um exemplo da wiki. */
function usarExemplo(exemplo) {
    var corpo = exemplo.replace(/#$/, '');
    var toks  = corpo.split(',');
    var ins   = document.querySelectorAll('.p-in');
    // O 1º token é o nome do comando; sub-comandos fixos também são pulados,
    // então alinha pelo FIM: os N últimos tokens são os N parâmetros.
    var vals = toks.slice(Math.max(1, toks.length - ins.length));
    ins.forEach(function (inp, i) { inp.value = vals[i] !== undefined ? vals[i] : ''; });
    document.getElementById('p-livre').checked = false;
    alternarLivre();
    atualizarPreview();
}

function alternarLivre() {
    var livre = document.getElementById('p-livre').checked;
    document.getElementById('p-manual').style.display = livre ? 'block' : 'none';
    document.getElementById('p-params-wrap').style.opacity = livre ? .45 : 1;
    if (livre && !document.getElementById('p-manual').value) {
        document.getElementById('p-manual').value = montarComando();
    }
    atualizarPreview();
}

/** Monta a string final substituindo os placeholders pelos valores digitados. */
function montarComando() {
    if (!cmdAtual) return '';
    var corpo = cmdAtual.s.replace(/#$/, '');
    var toks  = corpo.split(',');
    var ins   = document.querySelectorAll('.p-in');
    if (!ins.length) return cmdAtual.s;

    var vals = Array.prototype.map.call(ins, function (i) { return i.value.trim(); });
    var idx  = 0;
    var saida = toks.map(function (t, pos) {
        if (pos === 0) return t;
        // placeholder = P1..Pn ou letra única maiúscula
        if (/^(P\d+|[A-Z])$/.test(t)) { var v = vals[idx]; idx++; return v !== undefined && v !== '' ? v : t; }
        return t;
    });
    return saida.join(',') + '#';
}

function atualizarPreview() {
    var livre = document.getElementById('p-livre').checked;
    var txt = livre ? document.getElementById('p-manual').value : montarComando();
    document.getElementById('p-preview').textContent = txt || '—';
    atualizarBotao();
}

function atualizarBotao() {
    var b = document.getElementById('btn-enviar');
    var n = imeisMarcados().length;
    var txt = document.getElementById('p-preview').textContent;
    var pronto = n > 0 && txt && txt !== '—';
    b.disabled = !pronto;
    b.textContent = !n ? 'Selecione equipamento e comando'
                  : (!pronto ? 'Escolha um comando'
                  : 'Enviar para ' + n + ' equipamento' + (n > 1 ? 's' : ''));
}

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/** Envia um comando por equipamento — /sendcommand continua sendo 1 IMEI por chamada. */
function enviarLote() {
    var imeis = imeisMarcados();
    var livre = document.getElementById('p-livre').checked;
    var conteudo = livre ? document.getElementById('p-manual').value.trim() : montarComando();
    if (!imeis.length || !conteudo) return;

    // Placeholder não substituído é erro de preenchimento, não comando válido
    if (!livre && /(,P\d+|,[A-Z])(,|#)/.test(conteudo)) {
        document.getElementById('envio-feedback').innerHTML =
            '<span style="color:var(--error)">Preencha todos os parâmetros — ainda há placeholders em <code>' + esc(conteudo) + '</code>.</span>';
        return;
    }

    var btn = document.getElementById('btn-enviar');
    btn.disabled = true; btn.textContent = 'Enviando…';
    document.getElementById('envio-feedback').innerHTML = '';
    var cx = document.getElementById('envio-resultados');
    cx.style.display = 'block';
    cx.innerHTML = '<div style="font-size:12px;color:var(--muted);margin-bottom:6px">Resultado por equipamento</div>';

    var nomes = {};
    document.querySelectorAll('.dev-row').forEach(function (r) { nomes[r.dataset.imei] = r.querySelector('span').textContent.trim(); });

    var feitos = 0;
    imeis.forEach(function (imei) {
        var linha = document.createElement('div');
        linha.className = 'res-row';
        linha.innerHTML = '<span class="res-dot dot-aguardando"></span><span style="flex:1">' +
                          esc(nomes[imei] || imei) + '</span><span style="color:var(--muted)">enviando…</span>';
        cx.appendChild(linha);

        fetch('/sendcommand', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
            body: JSON.stringify({
                imei: imei, content: conteudo, proNo: proAtual,
                serverFlagId: proAtual === 128 ? 1 : 0
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            var ok = j && (j.code === 0 || j.code === 200);
            linha.querySelector('.res-dot').className = 'res-dot ' + (ok ? 'dot-ok' : 'dot-erro');
            linha.lastChild.textContent = ok ? ('enfileirado' + (j.command_id ? ' #' + j.command_id : ''))
                                             : (j && j.msg ? j.msg : 'falhou');
            if (ok && j.command_id) acompanhar(j.command_id, linha);
        })
        .catch(function (e) {
            linha.querySelector('.res-dot').className = 'res-dot dot-erro';
            linha.lastChild.textContent = 'erro de rede';
        })
        .finally(function () {
            if (++feitos === imeis.length) { btn.disabled = false; atualizarBotao(); }
        });
    });
}

/** Acompanha a resposta de um comando e escreve o desfecho na linha dele. */
function acompanhar(id, linha) {
    var t = 0;
    var tick = function () {
        fetch('/commandstatus?command_id=' + id)
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.response) {
                    linha.lastChild.textContent = String(j.response).slice(0, 60);
                    linha.querySelector('.res-dot').className = 'res-dot dot-ok';
                    return;
                }
                if (++t < 12) setTimeout(tick, t < 8 ? 3000 : 10000);
                else linha.lastChild.textContent = 'sem resposta (fila offline)';
            })
            .catch(function () {});
    };
    setTimeout(tick, 3000);
}

function verDetalhe(id) {
    var l = LINHAS.filter(function (x) { return x.id === id; })[0];
    if (!l) return;
    var d = l.desfecho;
    var kv = '';
    if (l.kv && Object.keys(l.kv).length) {
        kv = '<div class="kv-grid" style="margin-top:8px">' +
             Object.keys(l.kv).map(function (k) {
                 return '<span class="kv-k">' + esc(k) + '</span><span class="kv-v">' + esc(l.kv[k]) + '</span>';
             }).join('') + '</div>';
    }
    document.getElementById('detail-content').innerHTML =
        '<div style="font-size:12px;line-height:1.9">' +
          '<div><strong>Equipamento:</strong> ' + esc(l.placa) + ' <span class="dev-model">' + esc(l.modelo) + ' · ' + esc(l.imei) + '</span></div>' +
          '<div><strong>Comando:</strong> ' + esc(l.rotulo) + '</div>' +
          '<div><strong>Enviado em:</strong> ' + esc(l.quando) + (l.espera ? ' · respondeu em ' + esc(l.espera) : '') + '</div>' +
          '<div><strong>Status no banco:</strong> <span class="badge">' + esc(l.status) + '</span></div>' +
        '</div>' +
        '<div style="margin-top:10px"><label style="font-size:11px">Conteúdo enviado</label>' +
          '<div class="cmd-preview">' + esc(l.enviado) + '</div></div>' +
        '<div style="margin-top:10px;padding:10px;border-radius:var(--radius-sm);background:var(--canvas-soft)">' +
          '<div style="display:flex;gap:8px;align-items:center"><span class="res-dot dot-' + esc(d.nivel) + '"></span>' +
          '<strong>' + esc(d.titulo) + '</strong></div>' +
          (d.detalhe ? '<div style="font-family:\'JetBrains Mono\',monospace;font-size:11px;margin-top:6px;color:var(--muted);word-break:break-all">' + esc(d.detalhe) + '</div>' : '') +
          kv +
          (d.dica ? '<div style="font-size:12px;margin-top:8px;line-height:1.6">' + esc(d.dica) + '</div>' : '') +
        '</div>';
    document.getElementById('detail-modal').style.display = 'flex';
}

montarListaComandos();
atualizarBotao();
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
