<?php
/**
 * bycamera — Comandos por SMS v4.14.0 (redesenho de UI v4.17.25)
 * Rota: /comandos-sms
 *
 * O MESMO catálogo de comandos de texto do /comandos, despachado pela rede da
 * operadora em vez do IoT Hub — canal de RESGATE para equipamento que parou de
 * falar com o Hub (APN/`SERVER` errados).
 *
 * 🔑 O texto é IDÊNTICO ao da plataforma (`CMD,A,B#`). Nenhuma conversão para a
 * forma `CMD#666666#…` da wiki. Por isso `command_catalog.php` é reusado
 * inteiro: um catálogo paralelo divergiria na primeira alteração.
 *
 * ── AS QUATRO REGRAS DA TELA ────────────────────────────────────────────────
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
 *  4. 🔴 **Catálogo UNIFICADO POR NOME de comando, com 1 campo de texto livre
 *     para os parâmetros** — v4.17.25, decisão EXPLÍCITA do dono do produto,
 *     com o risco registrado antes de implementar. `command_catalog.php` tem
 *     o MESMO nome em entradas de ARIDADE/MODELO diferentes (ex.:
 *     `ANGLEREP,A,B#` universal de 2 campos, `ANGLEREP,A#` só do JC371 com 1,
 *     `ANGLEREP,P1,P2,P3#` só da JM-VL01 com 3). A tela costumava mostrar cada
 *     variante separada, com campos estruturados POR PARÂMETRO — o que
 *     travava a aridade certa por modelo. Agora todo nome vira 1 linha só, e
 *     o operador digita os parâmetros como quiser: **a validação de aridade
 *     por modelo deixou de existir** — mandar o número errado de campos para
 *     um modelo é aceito e mal interpretado pelo equipamento, sem erro nenhum
 *     (mesma classe de risco documentada no CLAUDE.md para BCD/TIMER/SERVER).
 *     A trava de MODELO (não de aridade) continua de pé: `modelos` do
 *     catálogo em JS é a UNIÃO de todas as variantes do nome, então um modelo
 *     que NENHUMA variante documenta continua recusado. Campo vazio → envia a
 *     forma de CONSULTA (`atual.q`) se o catálogo souber uma; sem consulta
 *     conhecida, bloqueia — não existe forma nua pra mandar. Os exemplos de
 *     cada variante (concatenados) são exibidos ao escolher o comando: é a
 *     única bússola de sintaxe que sobra sem os campos estruturados.
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

// ── Histórico: filtros e busca (função pura — reusada no load inicial E no
// fragmento AJAX abaixo, para as duas vias nunca divergirem) ────────────────
function comandos_sms_filtros_hist(array $params): array {
    $imei = trim((string)($params['h_imei'] ?? ''));
    // `imei` (sem prefixo) é compatibilidade com um link direto de fora desta
    // tela (ex.: futura "ver histórico SMS" na página do equipamento).
    if ($imei === '') $imei = trim((string)($params['imei'] ?? ''));
    return [
        'customer_raw' => trim((string)($params['h_customer_id'] ?? '')),
        'imei'         => $imei,
        'de'           => trim((string)($params['h_de'] ?? '')),
        'ate'          => trim((string)($params['h_ate'] ?? '')),
    ];
}

function comandos_sms_buscar_historico(PDO $db, bool $isAdmin, $sessionCustomerId, array $f): array {
    $filtroAtivo = ($f['customer_raw'] !== '' || $f['imei'] !== '' || $f['de'] !== '' || $f['ate'] !== '');

    // 🔴 Mesma regra de escopo do resto da tela (report_customer_scope()):
    // para não-admin o cliente é sempre o da sessão, nunca o que vier do GET.
    $scopeCustHist = report_customer_scope(
        $f['customer_raw'] !== '' ? $f['customer_raw'] : null, $isAdmin, $sessionCustomerId
    );

    // 🔴 Lê o dono pelo SNAPSHOT (sms_commands.customer_id), NÃO por JOIN em
    // devices.customer_id: a câmera pode ter trocado de cliente depois do
    // envio, e o JOIN reatribuiria retroativamente o histórico inteiro.
    $sql    = "SELECT sc.*, COALESCE(NULLIF(d.device_name,''), sc.imei) AS device_name
                 FROM sms_commands sc
                 LEFT JOIN devices d ON d.imei = sc.imei
                WHERE 1 = 1";
    $params = [];

    if ($scopeCustHist !== null) {
        $sql .= " AND sc.customer_id = :hcid";
        $params[':hcid'] = $scopeCustHist;
    } elseif ($isAdmin) {
        $escopo = reseller_scope_ids();
        if ($escopo !== null) {
            if (!$escopo) {
                $sql .= " AND 1 = 0";
            } else {
                $ph = [];
                foreach ($escopo as $i => $cid) { $ph[] = ":hrc$i"; $params[":hrc$i"] = $cid; }
                $sql .= " AND sc.customer_id IN (" . implode(',', $ph) . ")";
            }
        }
    }

    if ($f['imei'] !== '') {
        $sql .= " AND sc.imei = :himei";
        $params[':himei'] = $f['imei'];
    }

    if ($f['de'] !== '' || $f['ate'] !== '') {
        $de  = $f['de']  !== '' ? $f['de']  : $f['ate'];
        $ate = $f['ate'] !== '' ? $f['ate'] : $f['de'];
        [$utcDe, $utcAte] = brt_day_range_to_utc($de, $ate);
        $sql .= " AND sc.created_at BETWEEN :hde AND :hate";
        $params[':hde']  = $utcDe;
        $params[':hate'] = $utcAte;
    }

    // Sem filtro nenhum: só os 10 últimos, como pedido — é a vitrine, não um
    // relatório. Com qualquer filtro ativo, teto mais folgado de resultados.
    $limit = $filtroAtivo ? 100 : 10;

    try {
        $st = $db->prepare($sql . " ORDER BY sc.created_at DESC LIMIT {$limit}");
        $st->execute($params);
        return ['ok' => true, 'linhas' => $st->fetchAll(PDO::FETCH_ASSOC), 'filtro_ativo' => $filtroAtivo];
    } catch (PDOException $e) {
        // Tabela ausente = migração v4.14.0 não aplicada. A tela explica, em
        // vez de dar erro de SQL cru como o /usuarios deu na v4.13.21.
        Logger::warning('comandos-sms: sms_commands indisponível', ['erro' => $e->getMessage()]);
        return ['ok' => false, 'linhas' => [], 'filtro_ativo' => $filtroAtivo];
    }
}

function comandos_sms_renderizar_linha_hist(array $h): string {
    $st = sms_status_label($h['status_entrega']);
    ob_start(); ?>
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
    <?php
    return ob_get_clean();
}

function comandos_sms_opcoes_veiculo(PDO $db, bool $isAdmin, $sessionCustomerId): array {
    try {
        $sql = "SELECT d.imei, COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name, v.plate
                  FROM devices d
                  LEFT JOIN device_installations di ON di.device_id = d.id AND di.removed_at IS NULL
                  LEFT JOIN vehicles v ON v.id = di.vehicle_id
                 WHERE d.is_active = 1";
        $params = [];
        if (!$isAdmin) {
            $sql .= " AND d.customer_id = :cid";
            $params[':cid'] = $sessionCustomerId;
        } else {
            $allowed = reseller_scope_ids();
            if ($allowed !== null) {
                if (!$allowed) return [];
                $ph = [];
                foreach ($allowed as $i => $cid) { $ph[] = ":rc$i"; $params[":rc$i"] = $cid; }
                $sql .= " AND d.customer_id IN (" . implode(',', $ph) . ")";
            }
        }
        $sql .= " ORDER BY device_name";
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
}

// ── Fragmento AJAX do histórico (filtros da caixa "Últimos envios") ────────
// Sai ANTES de qualquer HTML — devolve só as linhas da tabela, para o filtro
// atualizar sem recarregar a página inteira (cliente, veículo e as duas
// datas continuam com o resto da tela como estava).
if (isset($_GET['ajax_hist'])) {
    $f = comandos_sms_filtros_hist($_GET);
    $r = comandos_sms_buscar_historico($db, $isAdmin, $customer_id, $f);
    header('Content-Type: text/html; charset=utf-8');
    if (!$r['ok']) {
        echo '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px;">Histórico indisponível — ver log.</td></tr>';
    } elseif (!$r['linhas']) {
        echo '<tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px;">Nenhum comando encontrado para este filtro.</td></tr>';
    } else {
        foreach ($r['linhas'] as $h) echo comandos_sms_renderizar_linha_hist($h);
    }
    exit;
}

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

// ── Família de cada modelo: câmera x rastreador (v4.16.0) ──────────────────
// 🔴 A MESMA trava do /comandos, e ela precisa existir NAS DUAS TELAS. O
// catálogo é o mesmo; só muda o transporte. Corrigir só o IoT Hub deixaria o
// SMS oferecendo `RECORDSW`/`VOLUME`/`SSID`/`WIFIAP` a um rastreador — o
// defeito inteiro, por um caminho diferente. Ponto único:
// `device_model_families()` / `command_families()` em includes/functions.php.
$familiaPorModelo = device_model_families($db);
$familiaDe = fn(?string $modelo) => $familiaPorModelo[$modelo ?? ''] ?? 'camera';

// ── Catálogo (o MESMO do /comandos) ─────────────────────────────────────────
$catalogo = require __DIR__ . '/../includes/command_catalog.php';

$rotuloCat = [
    'ia' => 'Inteligência artificial (DMS/ADAS)', 'video' => 'Vídeo e gravação',
    'rede' => 'Rede e servidor', 'posicao' => 'Posição e telemetria',
    'audio' => 'Áudio e voz', 'energia' => 'Energia e relé',
    'alarme' => 'Alarmes e eventos', 'manutencao' => 'Manutenção e diagnóstico',
    'outros' => 'Outros',
];

// 🔴 UNIFICAÇÃO POR NOME — decisão do dono do produto (08/09/2026), com o
// risco registrado: o catálogo tem o MESMO nome de comando em entradas de
// ARIDADE e MODELO diferentes (ex.: `ANGLEREP,A,B#` universal de 2 campos,
// `ANGLEREP,A#` só do JC371 com 1 campo, `ANGLEREP,P1,P2,P3#` só da JM-VL01
// com 3). Isto AQUI é o ponto que apaga essa distinção: cada nome vira UMA
// linha na tela, com 1 campo de texto livre para os parâmetros — o operador
// passa a ser responsável por saber a aridade certa para o modelo escolhido
// (os exemplos de cada variante, concatenados abaixo, são a única ajuda que
// sobra). Modelos = UNIÃO de todas as variantes do nome, então a trava
// básica (recusar modelo que NENHUMA variante documenta) continua de pé —
// só a distinção FINA entre variantes do mesmo modelo é que se perde.
$porNome = [];
foreach ($catalogo as $dd) {
    $nome = $dd['cmd'];
    if (!isset($porNome[$nome])) {
        $porNome[$nome] = [
            'cmd' => $nome, 'nome' => $dd['nome'], 'desc' => $dd['desc'],
            'categoria' => $dd['categoria'], 'modelos' => [], 'universal' => false,
            'consulta' => null, 'consulta_modelos' => [], 'exemplos' => [],
        ];
    }
    $ref = &$porNome[$nome];
    $ref['modelos']   = array_values(array_unique(array_merge($ref['modelos'], $dd['modelos'])));
    $ref['universal'] = $ref['universal'] || (bool)$dd['universal'];
    // Descrição mais longa entre as variantes tende a ser a mais completa.
    if (mb_strlen((string)$dd['desc']) > mb_strlen((string)$ref['desc'])) {
        $ref['desc'] = $dd['desc'];
    }
    if (!$ref['consulta'] && !empty($dd['consulta'])) {
        $ref['consulta']          = $dd['consulta'];
        $ref['consulta_modelos']  = $dd['consulta_modelos'] ?? [];
    }
    foreach (($dd['exemplos'] ?? []) as $ex) { $ref['exemplos'][] = $ex; }
    unset($ref);
}
ksort($porNome);

$catJs = [];
foreach ($porNome as $dd) {
    $catJs[] = [
        'c' => $dd['cmd'], 'n' => $dd['nome'], 'd' => $dd['desc'], 'k' => $dd['categoria'],
        'm' => $dd['modelos'], 'u' => (bool)$dd['universal'],
        'fam' => command_families($dd['modelos'], $familiaPorModelo),
        'q' => $dd['consulta'], 'qm' => $dd['consulta_modelos'],
        'e' => array_map(fn($e) => ['c' => $e['cmd'], 'd' => $e['desc']], $dd['exemplos']),
    ];
}

// ── Histórico (load inicial: sem filtro = últimos 10) ──────────────────────
$histFiltrosIniciais = comandos_sms_filtros_hist($_GET);
$histResultado        = comandos_sms_buscar_historico($db, $isAdmin, $customer_id, $histFiltrosIniciais);
$historico            = $histResultado['linhas'];
$histIndisp           = !$histResultado['ok'];

// Opções do filtro "Veículo / equipamento" da caixa de histórico — escopo
// PRÓPRIO (não o do seletor de cliente do topo): a caixa é uma busca
// autônoma, então um admin pode filtrar histórico de outro cliente sem mexer
// no escopo da lista de equipamentos acima.
$histDeviceOptions = comandos_sms_opcoes_veiculo($db, $isAdmin, $customer_id);

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
                    Consultado às <?= fmt_brt(gmdate('Y-m-d H:i:s'), 'H:i \d\o \d\i\a d/m/y') ?>
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

<!-- ── Cliente + Equipamentos ──────────────────────────────────────────── -->
<div class="card mb-16">
    <div class="flex-between mb-16" style="flex-wrap:wrap;gap:12px;align-items:flex-end;">
        <h2 style="font-size:16px;font-weight:600;color:var(--ink);margin:0;">Equipamentos</h2>
        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <?php if ($isAdmin && $customers): ?>
            <form method="get" style="margin:0;">
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
            <?php endif; ?>
            <div class="form-group" style="margin:0;">
                <label>Buscar</label>
                <input type="text" id="f-busca-dev" placeholder="nome, IMEI, placa, modelo…" style="min-width:220px;">
            </div>
        </div>
    </div>

    <div class="flex-between mb-8">
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
        <tbody id="dev-tbody">
        <?php foreach ($devices as $d): ?>
            <tr data-modelo="<?= htmlspecialchars($d['model_display']) ?>"
                data-familia="<?= htmlspecialchars($familiaDe($d['model_display'])) ?>"
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

    <div class="form-group">
        <label>Parâmetros (à sua escolha)</label>
        <input type="text" id="f-params-livre" class="text-mono"
               placeholder="ex.: 1,30 — deixe em branco para consultar, quando disponível">
    </div>
    <div id="cmd-exemplos" class="mb-16"></div>

    <div class="form-group">
        <label>Texto que será enviado por SMS</label>
        <input type="text" id="f-preview" class="text-mono" readonly
               style="background:var(--surface-2);font-weight:600;">
        <div id="preview-aviso" style="font-size:12px;margin-top:4px;"></div>
    </div>
</div>

<!-- ── Histórico ───────────────────────────────────────────────────────── -->
<div class="card">
    <div class="flex-between mb-16" style="flex-wrap:wrap;gap:12px;align-items:flex-end;">
        <h2 style="font-size:16px;font-weight:600;color:var(--ink);margin:0;">
            Últimos envios por SMS
        </h2>
        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
            <?php if ($isAdmin && $customers): ?>
            <div class="form-group" style="margin:0;">
                <label>Cliente</label>
                <select id="h-cliente">
                    <option value="">Todos</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>"
                            <?= $histFiltrosIniciais['customer_raw'] === (string)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group" style="margin:0;">
                <label>Veículo / equipamento</label>
                <select id="h-imei">
                    <option value="">Todos</option>
                    <?php foreach ($histDeviceOptions as $hd): ?>
                    <option value="<?= htmlspecialchars($hd['imei']) ?>"
                            <?= $histFiltrosIniciais['imei'] === $hd['imei'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($hd['device_name']) ?><?= !empty($hd['plate']) ? ' · ' . htmlspecialchars($hd['plate']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="margin:0;">
                <label>De</label>
                <input type="date" id="h-de" value="<?= htmlspecialchars($histFiltrosIniciais['de']) ?>">
            </div>
            <div class="form-group" style="margin:0;">
                <label>Até</label>
                <input type="date" id="h-ate" value="<?= htmlspecialchars($histFiltrosIniciais['ate']) ?>">
            </div>
            <button type="button" id="h-limpar" class="btn btn-outline btn-sm" style="height:38px;">Limpar</button>
        </div>
    </div>
    <div id="hist-info" style="font-size:12px;color:var(--muted);margin:-8px 0 12px;">
        <?= $histResultado['filtro_ativo'] ? '' : 'Sem filtro — mostrando os 10 mais recentes.' ?>
    </div>

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
        <tbody id="hist-body">
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
const elParamsLivre = document.getElementById('f-params-livre');
const elExemplos = document.getElementById('cmd-exemplos');
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
  atual = null; elDesc.textContent=''; elExemplos.innerHTML=''; elParamsLivre.value='';
  montar();
}

function escolher() {
  atual = elCmd.value === '' ? null : CAT[+elCmd.value];
  elDesc.textContent = atual ? (atual.d || '') : '';
  elParamsLivre.value = '';
  elExemplos.innerHTML = '';

  if (atual) {
    // 🔴 Comando UNIFICADO por nome (v4.17.25) — pode reunir variantes de
    // aridade/modelo diferentes que só compartilham o nome (ex.: ANGLEREP
    // universal de 2 campos x ANGLEREP só-JC371 de 1 campo). Sem campos
    // estruturados por comando, os EXEMPLOS são a única bússola de sintaxe
    // que sobra — mostrados sempre que o catálogo tiver algum.
    if (atual.e && atual.e.length) {
      const box = document.createElement('div');
      box.style.cssText = 'font-size:12px;color:var(--muted);';
      box.innerHTML = '<strong style="color:var(--ink);">Exemplos catalogados:</strong><br>'
        + atual.e.map(e => '<span class="text-mono">' + e.c + '</span>' + (e.d ? ' — ' + e.d : '')).join('<br>');
      elExemplos.appendChild(box);
    }

    const nota = document.createElement('div');
    nota.style.cssText = 'font-size:12px;color:var(--muted);margin-top:6px;';
    nota.innerHTML = atual.q
      ? 'Deixe o campo de parâmetros em branco para <strong>consultar</strong> em vez de gravar — envia <span class="text-mono">'
        + atual.q + '</span>' + (atual.qm && atual.qm.length ? ' (' + atual.qm.join(', ') + ')' : '')
      : 'Este comando não tem forma de consulta conhecida — digite os parâmetros para enviar.';
    elExemplos.appendChild(nota);
  }

  aplicarTravaModelo();
  montar();
}

/**
 * Monta a string exata a partir do campo ÚNICO de parâmetros.
 *
 *   Campo em branco    → CONSULTA (`atual.q`), se o catálogo souber uma;
 *                         senão, bloqueia — não existe forma nua conhecida
 *                         desse comando para mandar.
 *   Campo preenchido    → `<CMD>,<o que foi digitado>#`. O operador escreve
 *                         os valores à própria escolha (v4.17.25) — sem
 *                         validação de aridade por campo, porque comandos
 *                         unificados por nome podem ter aridade diferente
 *                         por modelo (ver comentário em escolher()).
 *
 * Tolerante a hábito: se o operador colar o exemplo inteiro (com o nome do
 * comando e/ou o `#` final), a normalização abaixo não duplica nada.
 */
function montar() {
  if (!atual) { elPrev.value=''; elAviso.textContent=''; atualizarBotao(); return; }

  const texto = elParamsLivre.value.trim();
  const vazio = texto === '';

  let s = null;
  if (vazio) {
    s = atual.q || null;
  } else {
    let corpo = texto.replace(/#$/, '').trim();
    const cUp = atual.c.toUpperCase();
    if (corpo.toUpperCase().startsWith(cUp + ',')) {
      corpo = corpo.slice(atual.c.length + 1);
    } else if (corpo.toUpperCase() === cUp) {
      corpo = '';
    }
    s = corpo === '' ? (atual.c + '#') : (atual.c + ',' + corpo + '#');
  }

  elPrev.value = s || '';

  if (vazio && !atual.q) {
    elAviso.innerHTML = '<span style="color:#b3261e;">Sem forma de consulta conhecida para este comando — digite os parâmetros.</span>';
  } else if (s && s.length > LIMITE_SMS) {
    elAviso.innerHTML = '<span style="color:#b3261e;">' + s.length + ' caracteres — acima do limite de '
      + LIMITE_SMS + '. A operadora partiria a mensagem e o equipamento receberia meio comando.</span>';
  } else if (s) {
    elAviso.innerHTML = '<span style="color:var(--muted);">' + (vazio ? 'consulta · ' : '') + s.length + '/' + LIMITE_SMS + ' caracteres</span>';
  } else {
    elAviso.textContent = '';
  }
  atualizarBotao();
}

elParamsLivre.addEventListener('input', montar);

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
    // v4.16.0 — a trava deixou de ser "universal libera todo mundo". Aceita quem:
    //   1. tem o modelo listado no comando; ou
    //   2. é universal E a FAMÍLIA do equipamento é uma das que o comando
    //      documenta (`fam`, derivada de `m` no PHP).
    // Comando sem modelo declarado continua não travando ninguém, como antes.
    const familia = tr.dataset.familia || 'camera';
    const aceita  = !atual || !atual.m.length
                    || atual.m.includes(tr.dataset.modelo)
                    || (atual.u && (atual.fam || ['camera']).includes(familia));
    const travado = !aceita;

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
  // 🔴 UMA fonte de verdade sobre "está pronto para enviar": montar() já
  // decidiu grava/consulta/bloqueia e só deixa `elPrev.value` não-vazio no
  // caso válido — repetir a leitura dos campos aqui poderia divergir (ex.:
  // "parcial" e "sem consulta catalogada" são dois motivos de bloqueio
  // diferentes, e só montar() sabe distingui-los).
  const ok = n > 0 && atual && s.length > 0 && s.length <= LIMITE_SMS;
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

// ── Busca client-side na lista de equipamentos ──────────────────────────────
// Filtra por texto visível na linha (nome, IMEI, placa, modelo, chip) sem ida
// ao servidor — a lista já está inteira na página, então não há por que.
const elBuscaDev = document.getElementById('f-busca-dev');
if (elBuscaDev) {
  elBuscaDev.addEventListener('input', () => {
    const termo = elBuscaDev.value.trim().toLowerCase();
    document.querySelectorAll('#dev-tbody tr[data-imei]').forEach(tr => {
      const alvo = tr.textContent.toLowerCase();
      tr.style.display = (!termo || alvo.includes(termo)) ? '' : 'none';
    });
  });
}

// ── Filtro do histórico (AJAX — sem recarregar a página) ────────────────────
const elHCliente = document.getElementById('h-cliente');
const elHImei    = document.getElementById('h-imei');
const elHDe      = document.getElementById('h-de');
const elHAte     = document.getElementById('h-ate');
const elHistBody = document.getElementById('hist-body');
const elHistInfo = document.getElementById('hist-info');
const elHLimpar  = document.getElementById('h-limpar');

function historicoFiltroAtivo() {
  return !!((elHCliente && elHCliente.value) || (elHImei && elHImei.value) || elHDe.value || elHAte.value);
}

async function atualizarHistorico() {
  const p = new URLSearchParams();
  if (elHCliente && elHCliente.value) p.set('h_customer_id', elHCliente.value);
  if (elHImei && elHImei.value) p.set('h_imei', elHImei.value);
  if (elHDe.value) p.set('h_de', elHDe.value);
  if (elHAte.value) p.set('h_ate', elHAte.value);
  p.set('ajax_hist', '1');

  elHistInfo.textContent = 'Carregando…';
  try {
    const r = await fetch('/comandos-sms?' + p.toString());
    elHistBody.innerHTML = await r.text();
    elHistInfo.textContent = historicoFiltroAtivo() ? '' : 'Sem filtro — mostrando os 10 mais recentes.';
  } catch (e) {
    elHistInfo.textContent = 'Falha ao carregar o histórico.';
  }
}

[elHCliente, elHImei, elHDe, elHAte].forEach(el => el && el.addEventListener('change', atualizarHistorico));

if (elHLimpar) {
  elHLimpar.addEventListener('click', () => {
    if (elHCliente) elHCliente.value = '';
    if (elHImei) elHImei.value = '';
    elHDe.value = '';
    elHAte.value = '';
    atualizarHistorico();
  });
}
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
