<?php
/**
 * bycamera — Comandos por SMS v4.14.0
 * Rota: /comandos-sms
 *
 * O MESMO catálogo de comandos de texto do /comandos, despachado pela rede da
 * operadora em vez do IoT Hub. É o canal de RESGATE: quando a câmera não fala
 * com o Hub — APN errado, `SERVER` apontando para o lugar errado, equipamento
 * mudo — o SMS chega por um caminho independente.
 *
 * 🔑 O texto é IDÊNTICO ao da plataforma (`CMD,A,B#`). Nenhuma conversão para a
 * forma `CMD#666666#…` da wiki. Por isso `command_catalog.php` é reusado
 * inteiro: um catálogo paralelo divergiria na primeira alteração.
 *
 * ── AS TRÊS REGRAS DA TELA ──────────────────────────────────────────────────
 *
 *  1. **Trava de modelo**, herdada do /comandos: comando específico de um
 *     modelo desabilita os outros equipamentos. Comando `universal` solta a
 *     trava. Aqui a trava importa MAIS que no /comandos: por SMS não há
 *     callback do gateway dizendo "comando não suportado" — o equipamento
 *     simplesmente ignora, e o crédito já foi gasto.
 *
 *  2. **Chip sem número aparece, desabilitado, COM O MOTIVO.** O destino é
 *     estritamente `sim_cards.msisdn` (decisão do dono do produto). Esconder o
 *     equipamento faria a lista mentir por omissão — o operador procuraria o
 *     veículo e concluiria que ele não existe. Com o motivo escrito e o link
 *     para /chips, o defeito vira tarefa.
 *
 *  3. **O saldo é lido a cada abertura da tela** (pedido do dono do produto).
 *     Saldo indisponível não bloqueia o envio: quem recusa de verdade é o 406
 *     da API, e travar a tela por uma consulta que falhou esconderia um canal
 *     que talvez estivesse funcionando.
 *
 * ⚠️ Cada SMS CUSTA. É a diferença operacional para o /comandos, e é por isso
 * que a tela mostra o saldo, o custo do disparo em lote (1 crédito por
 * equipamento marcado) e pede confirmação.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/fleet_state.php';
require_once __DIR__ . '/../includes/sms_gateway.php';
// A permissão de tela é aplicada pelo router ($screenByHandler).
require_login();

$db          = Database::getInstance()->getConnection();
$customer_id = get_customer_id();
$user        = get_jimi_user();
$isAdmin     = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

// ── Escopo multi-tenant ─────────────────────────────────────────────────────
// O `?customer_id` passa OBRIGATORIAMENTE por report_customer_scope(): para
// não-admin o parâmetro é IGNORADO, não validado.
$filtroCust = $_GET['customer_id'] ?? null;
$scopeCust  = report_customer_scope($filtroCust, $isAdmin, $customer_id);
$customers  = $isAdmin ? report_customer_options($db) : [];

$scopeSql    = '';
$scopeParams = [];
if ($scopeCust !== null) {
    $scopeSql = ' AND d.customer_id = :scope_cid';
    $scopeParams[':scope_cid'] = $scopeCust;
} elseif ($isAdmin) {
    // Revendedor sem cliente atribuído ([]) é o OPOSTO de admin de plataforma
    // (null). Tratar os dois como "sem restrição" vazaria a base inteira.
    $escopo = reseller_scope_ids();
    if ($escopo !== null) {
        if (!$escopo) {
            $scopeSql = ' AND 1 = 0';
        } else {
            $ph = [];
            foreach ($escopo as $i => $cid) { $ph[] = ":rc$i"; $scopeParams[":rc$i"] = $cid; }
            $scopeSql = ' AND d.customer_id IN (' . implode(',', $ph) . ')';
        }
    }
}

// ── Saldo (a cada abertura) ─────────────────────────────────────────────────
$saldo = sms_saldo($db);

// ── Equipamentos + o número do chip ─────────────────────────────────────────
// O LEFT JOIN em sim_cards é o que permite mostrar o equipamento SEM número em
// vez de sumir com ele. `d.customer_id` aqui é legítimo: a pergunta é "quem tem
// a câmera HOJE", que é exatamente o que essa coluna responde — diferente de
// consulta histórica, onde o dono tem de vir do snapshot.
$stmt = $db->prepare("
    SELECT d.imei,
           COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name,
           COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           COALESCE(dm.protocol, 'JIMI') AS protocol,
           s.msisdn,
           v.plate,
           " . device_last_seen_sql() . " AS last_communication,
           TIMESTAMPDIFF(MINUTE, " . device_last_seen_sql() . ", UTC_TIMESTAMP()) AS mudo_min,
           COALESCE(cu.name, '—') AS customer_name
      FROM devices d
      LEFT JOIN device_models dm     ON d.device_model_id = dm.id
      LEFT JOIN device_statistics ds ON ds.imei = d.imei
      LEFT JOIN sim_cards s          ON s.imei = d.imei
      LEFT JOIN device_installations di ON di.device_id = d.id AND di.removed_at IS NULL
      LEFT JOIN vehicles v           ON v.id = di.vehicle_id
      LEFT JOIN customers cu         ON cu.id = d.customer_id
     WHERE d.is_active = 1 {$scopeSql}
     ORDER BY cu.name, d.device_name
");
$stmt->execute($scopeParams);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mostrarCliente = ($scopeCust === null);

$semNumero = 0;
foreach ($devices as &$d) {
    $d['presenca']   = device_presence(isset($d['mudo_min']) ? (int)$d['mudo_min'] : null);
    $d['msisdn_ok']  = sms_normalizar_msisdn($d['msisdn']);
    // O motivo é escrito aqui, uma vez, para a tela não repetir a regra.
    if ($d['msisdn_ok'] === null) {
        $semNumero++;
        $d['motivo_bloqueio'] = trim((string)$d['msisdn']) === ''
            ? 'chip sem número cadastrado'
            : 'número do chip inválido: ' . $d['msisdn'];
    } else {
        $d['motivo_bloqueio'] = null;
    }
}
unset($d);

// ── Catálogo (o MESMO do /comandos) ─────────────────────────────────────────
$catalogo = require __DIR__ . '/../includes/command_catalog.php';

$rotuloCat = [
    'ia' => 'Inteligência artificial (DMS/ADAS)', 'video' => 'Vídeo e gravação',
    'rede' => 'Rede e servidor', 'posicao' => 'Posição e telemetria',
    'audio' => 'Áudio e voz', 'energia' => 'Energia e relé',
    'alarme' => 'Alarmes e eventos', 'manutencao' => 'Manutenção e diagnóstico',
    'outros' => 'Outros',
];

$catJs = [];
foreach ($catalogo as $syn => $dd) {
    $catJs[] = [
        's' => $syn, 'c' => $dd['cmd'], 'n' => $dd['nome'],
        'd' => $dd['desc'], 'k' => $dd['categoria'],
        'm' => $dd['modelos'], 'u' => (bool)$dd['universal'], 't' => (bool)$dd['template'],
        'q' => $dd['consulta'] ?? null, 'qm' => $dd['consulta_modelos'] ?? [],
        'p' => array_map(fn($p) => ['p' => $p['p'], 'd' => $p['desc'],
                                    'f' => $p['format'], 'v' => $p['default']], $dd['params']),
        'e' => array_map(fn($e) => ['c' => $e['cmd'], 'd' => $e['desc']], $dd['exemplos']),
    ];
}

// ── Histórico ───────────────────────────────────────────────────────────────
// 🔴 Lê o dono pelo SNAPSHOT (sms_commands.customer_id), NÃO por JOIN em
// devices.customer_id: a câmera pode ter trocado de cliente depois do envio, e
// o JOIN reatribuiria retroativamente o histórico inteiro (regra da Fase 2).
$histSql    = "SELECT sc.*, COALESCE(NULLIF(d.device_name,''), sc.imei) AS device_name
                 FROM sms_commands sc
                 LEFT JOIN devices d ON d.imei = sc.imei
                WHERE 1 = 1";
$histParams = [];

if ($scopeCust !== null) {
    $histSql .= " AND sc.customer_id = :hcid";
    $histParams[':hcid'] = $scopeCust;
} elseif ($isAdmin) {
    $escopo = reseller_scope_ids();
    if ($escopo !== null) {
        if (!$escopo) {
            $histSql .= " AND 1 = 0";
        } else {
            $ph = [];
            foreach ($escopo as $i => $cid) { $ph[] = ":hrc$i"; $histParams[":hrc$i"] = $cid; }
            $histSql .= " AND sc.customer_id IN (" . implode(',', $ph) . ")";
        }
    }
}

$filtroImei = trim((string)($_GET['imei'] ?? ''));
if ($filtroImei !== '' && in_array($filtroImei, array_column($devices, 'imei'), true)) {
    $histSql .= " AND sc.imei = :himei";
    $histParams[':himei'] = $filtroImei;
}

$historico   = [];
$histIndisp  = false;
try {
    $h = $db->prepare($histSql . " ORDER BY sc.created_at DESC LIMIT 100");
    $h->execute($histParams);
    $historico = $h->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tabela ausente = migração v4.14.0 não aplicada. A tela explica, em vez de
    // dar erro de SQL cru como o /usuarios deu na v4.13.21.
    $histIndisp = true;
    Logger::warning('comandos-sms: sms_commands indisponível', ['erro' => $e->getMessage()]);
}

$page_title = 'Comandos por SMS';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($histIndisp): ?>
<div class="card mb-16" style="border-left:3px solid #b3261e;background:#fdecea;">
    <div style="font-size:13px;color:#7a1a12;line-height:1.6;">
        <strong>A migração v4.14.0 não foi aplicada.</strong>
        A tabela <code>sms_commands</code> não existe, então nada pode ser enviado nem registrado.
        Rode <code>./scripts/deploy.sh --force</code> mais uma vez, ou aplique
        <code>mysql/migration_v4.14.0.sql</code> à mão.
    </div>
</div>
<?php endif; ?>

<!-- ── Saldo ───────────────────────────────────────────────────────────── -->
<div class="card mb-16">
    <div class="flex-between" style="flex-wrap:wrap;gap:12px;">
        <div>
            <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;">
                Saldo — SMS transacional
            </div>
            <?php if ($saldo['ok']): ?>
                <div class="text-mono" style="font-size:28px;font-weight:600;color:var(--ink);line-height:1.2;">
                    <?= number_format((int)$saldo['saldo'], 0, ',', '.') ?>
                </div>
                <div style="font-size:12px;color:var(--muted);">
                    consultado agora · 1 crédito por equipamento a cada disparo
                </div>
            <?php else: ?>
                <div style="font-size:15px;color:#b3261e;font-weight:600;">saldo indisponível</div>
                <div style="font-size:12px;color:var(--muted);max-width:520px;">
                    <?= htmlspecialchars((string)$saldo['erro']) ?>
                    <?php if ($isAdmin): ?>
                        — ajuste em <a href="/config-sms">Cadastros › SMS (Allcance)</a>.
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <div style="text-align:right;font-size:12px;color:var(--muted);line-height:1.7;">
            <div><strong style="color:var(--ink);"><?= count($devices) ?></strong> equipamentos no escopo</div>
            <?php if ($semNumero > 0): ?>
            <div style="color:#a97a00;">
                <strong><?= $semNumero ?></strong> sem número de chip —
                <a href="/chips">corrigir</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($isAdmin && $customers): ?>
<div class="card mb-16">
    <form method="get" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
        <div class="form-group" style="margin:0;">
            <label>Cliente</label>
            <select name="customer_id" onchange="this.form.submit()">
                <option value="">Todos os clientes</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card mb-16">
    <div style="font-size:12px;color:var(--muted);line-height:1.6;">
        <strong style="color:var(--ink);">Canal de resgate.</strong>
        O SMS chega pela rede da operadora, um caminho independente do IoT Hub — é o que
        permite alcançar uma câmera que parou de falar com a plataforma (APN ou
        <code>SERVER</code> errados). O texto do comando é o mesmo do
        <a href="/comandos">envio normal</a>.
        <strong style="color:var(--ink);">Cada disparo consome 1 crédito por equipamento</strong>,
        e a resposta do equipamento (quando ele responde) chega pelo webhook e aparece no histórico abaixo.
    </div>
</div>

<!-- ── Montagem do comando ─────────────────────────────────────────────── -->
<div class="card mb-16">
    <h2 style="font-size:16px;font-weight:600;color:var(--ink);" class="mb-16">Comando</h2>

    <div class="form-row">
        <div class="form-group" style="flex:1;">
            <label>Categoria</label>
            <select id="f-cat">
                <option value="">Todas</option>
                <?php foreach ($rotuloCat as $k => $lbl): ?>
                <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex:2;">
            <label>Comando</label>
            <select id="f-cmd"><option value="">Selecione…</option></select>
        </div>
    </div>

    <div id="cmd-desc" style="font-size:12px;color:var(--muted);margin:-8px 0 16px;"></div>
    <div id="cmd-params"></div>

    <div class="form-group">
        <label>Texto que será enviado por SMS</label>
        <input type="text" id="f-preview" class="text-mono" readonly
               style="background:var(--surface-2);font-weight:600;">
        <div id="preview-aviso" style="font-size:12px;margin-top:4px;"></div>
    </div>
</div>

<!-- ── Equipamentos ────────────────────────────────────────────────────── -->
<div class="card mb-16">
    <div class="flex-between mb-16">
        <h2 style="font-size:16px;font-weight:600;color:var(--ink);">Equipamentos</h2>
        <span id="sel-resumo" style="font-size:12px;color:var(--muted);"></span>
    </div>

    <div style="max-height:420px;overflow:auto;">
    <table class="table">
        <thead>
            <tr>
                <th style="width:36px;"><input type="checkbox" id="sel-todos"></th>
                <th>Equipamento</th>
                <?php if ($mostrarCliente): ?><th>Cliente</th><?php endif; ?>
                <th>Modelo</th>
                <th>Número do chip</th>
                <th>Contato</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($devices as $d): ?>
            <tr data-modelo="<?= htmlspecialchars($d['model_display']) ?>"
                data-imei="<?= htmlspecialchars($d['imei']) ?>"
                class="<?= $d['msisdn_ok'] === null ? 'linha-bloqueada' : '' ?>">
                <td>
                    <input type="checkbox" class="sel-dev"
                           value="<?= htmlspecialchars($d['imei']) ?>"
                           <?= $d['msisdn_ok'] === null ? 'disabled' : '' ?>>
                </td>
                <td>
                    <div style="font-weight:500;"><?= htmlspecialchars($d['device_name']) ?></div>
                    <div class="text-mono" style="font-size:11px;color:var(--muted);">
                        <?= htmlspecialchars($d['imei']) ?>
                        <?php if (!empty($d['plate'])): ?>
                            · <?= htmlspecialchars($d['plate']) ?>
                        <?php endif; ?>
                    </div>
                </td>
                <?php if ($mostrarCliente): ?>
                <td style="font-size:12px;"><?= htmlspecialchars($d['customer_name']) ?></td>
                <?php endif; ?>
                <td style="font-size:12px;"><?= htmlspecialchars($d['model_display']) ?></td>
                <td>
                    <?php if ($d['msisdn_ok'] !== null): ?>
                        <span class="text-mono" style="font-size:12px;"><?= htmlspecialchars($d['msisdn_ok']) ?></span>
                    <?php else: ?>
                        <span style="font-size:12px;color:#a97a00;">
                            <?= htmlspecialchars($d['motivo_bloqueio']) ?> —
                            <a href="/chips">cadastrar</a>
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge badge-<?= htmlspecialchars($d['presenca']['nivel']) ?>">
                        <?= htmlspecialchars($d['presenca']['rotulo']) ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$devices): ?>
            <tr><td colspan="<?= $mostrarCliente ? 6 : 5 ?>" style="text-align:center;color:var(--muted);padding:24px;">
                Nenhum equipamento no escopo.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="mt-16" style="display:flex;gap:12px;align-items:center;">
        <button type="button" id="btn-enviar" class="btn btn-primary" disabled>Enviar por SMS</button>
        <span id="envio-status" style="font-size:12px;color:var(--muted);"></span>
    </div>
</div>

<!-- ── Histórico ───────────────────────────────────────────────────────── -->
<div class="card">
    <h2 style="font-size:16px;font-weight:600;color:var(--ink);" class="mb-16">
        Últimos envios por SMS
    </h2>

    <table class="table">
        <thead>
            <tr>
                <th>Quando</th>
                <th>Equipamento</th>
                <th>Comando</th>
                <th>Envio</th>
                <th>Entrega</th>
                <th>Resposta do equipamento</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($historico as $h): ?>
            <?php $st = sms_status_label($h['status_entrega']); ?>
            <tr>
                <td style="font-size:12px;white-space:nowrap;"><?= fmt_brt($h['created_at']) ?></td>
                <td>
                    <div style="font-size:12px;"><?= htmlspecialchars($h['device_name']) ?></div>
                    <div class="text-mono" style="font-size:11px;color:var(--muted);">
                        <?= htmlspecialchars((string)$h['msisdn']) ?>
                    </div>
                </td>
                <td class="text-mono" style="font-size:12px;"><?= htmlspecialchars($h['command_content']) ?></td>
                <td style="font-size:12px;">
                    <?php if ($h['status_envio'] === 'enviado'): ?>
                        <span class="badge badge-success">aceito</span>
                    <?php elseif ($h['status_envio'] === 'sem_saldo'): ?>
                        <span class="badge badge-error">sem saldo</span>
                    <?php elseif ($h['status_envio'] === 'sem_msisdn'): ?>
                        <span class="badge badge-error">sem número</span>
                    <?php else: ?>
                        <span class="badge badge-error">falhou</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:12px;">
                    <span class="badge badge-<?= htmlspecialchars($st['nivel']) ?>">
                        <?= htmlspecialchars($st['rotulo']) ?>
                    </span>
                </td>
                <td class="text-mono" style="font-size:12px;max-width:280px;word-break:break-word;">
                    <?php if (!empty($h['resposta_texto'])): ?>
                        <?= htmlspecialchars($h['resposta_texto']) ?>
                        <div style="font-size:11px;color:var(--muted);" class="text-mono">
                            <?= fmt_brt($h['resposta_em']) ?>
                        </div>
                    <?php else: ?>
                        <span style="color:var(--muted);">—</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$historico): ?>
            <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px;">
                Nenhum comando enviado por SMS ainda.
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<style>
.linha-bloqueada { opacity:.55; }
.linha-bloqueada td { background:var(--surface-2); }
tr.modelo-travado { opacity:.35; }
tr.modelo-travado input { pointer-events:none; }
</style>

<script>
// Exposto em `window` de propósito: é o que permite ao spec E2E afirmar sobre o
// catálogo REAL que a tela carregou, em vez de sobre a grade renderizada.
const CAT   = <?= json_encode($catJs, JSON_UNESCAPED_UNICODE) ?>;
const LIMITE_SMS = <?= SMS_MAX_CHARS ?>;
window.CATALOGO_SMS = CAT;
window.LIMITE_SMS   = LIMITE_SMS;

const elCat = document.getElementById('f-cat');
const elCmd = document.getElementById('f-cmd');
const elPrev = document.getElementById('f-preview');
const elAviso = document.getElementById('preview-aviso');
const elParams = document.getElementById('cmd-params');
const elDesc = document.getElementById('cmd-desc');
const elBtn = document.getElementById('btn-enviar');
const elResumo = document.getElementById('sel-resumo');
const elStatus = document.getElementById('envio-status');

let atual = null;

function popularComandos() {
  const k = elCat.value;
  elCmd.innerHTML = '<option value="">Selecione…</option>';
  CAT.filter(c => !k || c.k === k)
     .sort((a,b) => a.c.localeCompare(b.c))
     .forEach((c,i) => {
       const o = document.createElement('option');
       o.value = CAT.indexOf(c);
       o.textContent = c.c + ' — ' + c.n;
       elCmd.appendChild(o);
     });
  atual = null; elParams.innerHTML=''; elDesc.textContent='';
  montar();
}

function escolher() {
  atual = elCmd.value === '' ? null : CAT[+elCmd.value];
  elParams.innerHTML = '';
  elDesc.textContent = atual ? (atual.d || '') : '';

  if (atual && atual.p.length) {
    const row = document.createElement('div');
    row.className = 'form-row';
    atual.p.forEach(p => {
      const g = document.createElement('div');
      g.className = 'form-group';
      g.innerHTML = '<label>' + p.p + (p.d ? ' — ' + p.d : '') + '</label>'
                  + '<input type="text" class="p-in" data-p="' + p.p + '" value="'
                  + (p.v ? String(p.v).replace(/"/g,'&quot;') : '') + '">';
      row.appendChild(g);
    });
    elParams.appendChild(row);
    elParams.querySelectorAll('.p-in').forEach(i => i.addEventListener('input', montar));
  }
  aplicarTravaModelo();
  montar();
}

/**
 * Monta a string exata. A guarda de "falta parâmetro" pergunta pelos CAMPOS,
 * nunca pela aparência do resultado: um valor de UMA LETRA (VIDEOTIMEZONE,W,…)
 * é indistinguível de um placeholder de uma letra, e a guarda por formato
 * recusava o exemplo oficial do próprio comando.
 */
function montar() {
  if (!atual) { elPrev.value=''; elAviso.textContent=''; atualizarBotao(); return; }

  // Substituição POSICIONAL, idêntica à de comandos.php (montarComando): quebra
  // por vírgula e troca os tokens que são placeholder (P1..Pn ou letra única
  // maiúscula), na ordem dos campos. Nada de regex sobre o texto inteiro — o
  // nome do parâmetro pode aparecer noutro lugar da string.
  const ins = elParams.querySelectorAll('.p-in');
  const vals = Array.prototype.map.call(ins, i => i.value.trim());
  const faltando = [];
  atual.p.forEach((p, i) => { if (!vals[i]) faltando.push(p.p); });

  let s;
  if (!ins.length) {
    s = atual.s;
  } else {
    const corpo = atual.s.replace(/#$/, '');
    let idx = 0;
    s = corpo.split(',').map((t, pos) => {
      if (pos === 0) return t;
      if (/^(P\d+|[A-Z])$/.test(t)) {
        const v = vals[idx]; idx++;
        return (v !== undefined && v !== '') ? v : t;
      }
      return t;
    }).join(',') + '#';
  }

  elPrev.value = s;

  if (faltando.length) {
    elAviso.innerHTML = '<span style="color:#a97a00;">Preencha: ' + faltando.join(', ') + '</span>';
  } else if (s.length > LIMITE_SMS) {
    elAviso.innerHTML = '<span style="color:#b3261e;">' + s.length + ' caracteres — acima do limite de '
      + LIMITE_SMS + '. A operadora partiria a mensagem e o equipamento receberia meio comando.</span>';
  } else {
    elAviso.innerHTML = '<span style="color:var(--muted);">' + s.length + '/' + LIMITE_SMS + ' caracteres</span>';
  }
  atualizarBotao();
}

/**
 * Trava de modelo. Por SMS ela pesa MAIS que no /comandos: não há callback do
 * gateway dizendo "comando não suportado" — o equipamento ignora e o crédito
 * já foi gasto.
 */
function aplicarTravaModelo() {
  document.querySelectorAll('tbody tr[data-imei]').forEach(tr => {
    const cb = tr.querySelector('.sel-dev');
    if (!cb) return;

    // Dois motivos INDEPENDENTES de bloqueio, e um não pode apagar o outro.
    // A classe `linha-bloqueada` (do servidor) é a fonte única de "sem número";
    // não se lê o atributo `disabled` do próprio checkbox, que esta função
    // escreve — isso faria o estado depender da ordem das chamadas.
    const semNumero = tr.classList.contains('linha-bloqueada');
    const travado = !!(atual && !atual.u && atual.m.length && !atual.m.includes(tr.dataset.modelo));

    tr.classList.toggle('modelo-travado', travado);
    cb.disabled = travado || semNumero;
    if (cb.disabled) cb.checked = false;
  });
  atualizarBotao();
}

function selecionados() {
  return [...document.querySelectorAll('.sel-dev:checked')].map(c => c.value);
}

function atualizarBotao() {
  const n = selecionados().length;
  const s = elPrev.value;
  // Mesma leitura posicional de montar() — um só jeito de perguntar "falta
  // campo?", senão as duas respostas podem divergir e o botão libera um envio
  // que sai com o placeholder cru para o equipamento.
  const faltaParam = atual
    ? [...elParams.querySelectorAll('.p-in')].some(i => i.value.trim() === '')
    : true;
  const ok = n > 0 && atual && !faltaParam && s.length > 0 && s.length <= LIMITE_SMS;
  elBtn.disabled = !ok;
  elResumo.textContent = n === 0 ? 'nenhum selecionado'
    : n + (n === 1 ? ' selecionado · 1 crédito' : ' selecionados · ' + n + ' créditos');
}

elCat.addEventListener('change', popularComandos);
elCmd.addEventListener('change', escolher);
document.getElementById('sel-todos').addEventListener('change', e => {
  document.querySelectorAll('.sel-dev:not([disabled])').forEach(c => c.checked = e.target.checked);
  atualizarBotao();
});
document.addEventListener('change', e => { if (e.target.classList.contains('sel-dev')) atualizarBotao(); });

elBtn.addEventListener('click', async () => {
  const imeis = selecionados();
  const texto = elPrev.value;
  if (!imeis.length || !texto) return;

  if (!confirm('Enviar "' + texto + '" por SMS para ' + imeis.length
      + ' equipamento(s)?\n\nIsso consome ' + imeis.length + ' crédito(s) e não pode ser cancelado.')) return;

  elBtn.disabled = true;
  let ok = 0, falhou = 0;
  const erros = [];

  // Uma chamada por equipamento — o mesmo desenho do /comandos: a checagem de
  // posse, o log e o registro por linha continuam num caminho só.
  for (let i = 0; i < imeis.length; i++) {
    elStatus.textContent = 'Enviando ' + (i+1) + ' de ' + imeis.length + '…';
    try {
      const r = await fetch('/sendsms', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify({ imei: imeis[i], comando: texto })
      });
      const j = await r.json();
      if (j.ok) ok++; else { falhou++; erros.push(imeis[i] + ': ' + (j.erro || 'falha')); }
    } catch (e) {
      falhou++; erros.push(imeis[i] + ': ' + e.message);
    }
  }

  elStatus.textContent = ok + ' enviado(s)' + (falhou ? ', ' + falhou + ' com erro' : '') + '.';
  if (erros.length) alert('Não enviados:\n\n' + erros.join('\n'));
  setTimeout(() => location.reload(), 1200);
});

popularComandos();
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
