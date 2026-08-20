<?php
/**
 * JIMI Webhook System — Vídeo Playback v4.9.34
 * Rota: /video/playback
 *
 * A tela é uma só; os DOIS protocolos chegam nela por caminhos opostos, e a
 * linha do tempo é a mesma tabela (`resource_lists`) nos dois casos.
 *
 * Fluxo JT/T (JC450/JC181/JC182/JC371) — pergunta e resposta:
 *   1. [Requisitar] → proNo 37381 (0x9205, consulta de gravações no cartão).
 *      A janela beginTime/endTime é GMT-0 compacta (yyMMddHHmmss) e NÃO pode
 *      cruzar o dia — o período é fatiado em segmentos por dia UTC.
 *      A câmera responde de forma assíncrona via /pushresourcelist.
 *   2. [Extrair] → proNo 37382 ("FTP file upload command") com a janela exata
 *      da gravação; a CÂMERA sobe o arquivo por FTP para o destino configurado
 *      em VIDEO_FTP_* e o IoTHub avisa por /pushftpfileupload → media_files.
 *
 * Fluxo JIMI (JC400D/AD) — a câmera é quem fala, e em hora LOCAL:
 *   1. [Requisitar] → `FILELIST,<url>` (grava o endereço) **e** `FILELIST` nu
 *      (dispara). São dois comandos: o primeiro sozinho não sobe nada. A
 *      câmera então faz POST da lista INTEIRA do cartão em /filelist/{imei},
 *      que a interpreta para `resource_lists` (v4.9.34). Não há janela de
 *      datas no comando — o filtro da tela vale só na exibição.
 *   2. [Extrair] → `HVIDEO,<carimbo>,<câmera>` (proNo 128), montado a partir do
 *      nome que veio na lista. 🔴 NÃO é o 37382: aquele é JT/T, e mandá-lo para
 *      uma câmera JIMI dá "enviado com sucesso" e arquivo nenhum.
 *
 * Em ambos: item "no cartão" vira "disponível" quando o arquivo chega, e aí
 * toca inline / baixa.
 *
 * ⚠️ O instante de um arquivo é o carimbo do NOME quando ele tem um — ver a
 * nota na unificação, mais abaixo. `event_time` de um bloco extraído por
 * `HVIDEO` é a hora em que o UPLOAD terminou.
 *
 * ⚠️ Até a v4.9.0 o [Extrair] do JT/T mandava **34818**, que a doc chama de
 * "Multimedia data retrieval" — uma CONSULTA da família de fotos do JT/T 808. O
 * IoTHub aceitava, marcava `executed`, e arquivo nenhum aparecia: em três dias
 * de homologação, /pushfileupload e /pushftpfileupload não foram chamados uma
 * vez. O mesmo erro, no dialeto errado, é o que o [Extrair] fazia com as JIMI.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/filelist.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();

// Mídia servida por /midia, na NOSSA origem — não mais direto pelo
// FILE_STORAGE_URL (porta 23010). Aquele endpoint não manda CORS (e o player
// de MPEG-TS precisa buscar os bytes por `fetch`), não aceita Range (sem o
// qual não há como arrastar a barra) e ainda responde
// `Content-Disposition: attachment`. Ver o cabeçalho de handlers/midia.php.
$fileStorageUrl = '/midia?f=';

// ── Escopo multi-tenant (v4.9.23) ──────────────────────────────────────────
// Antes a tela era presa ao cliente da sessão. O `?customer_id` passa por
// `report_customer_scope()`: para quem não é admin o parâmetro é ignorado, não
// validado (CLAUDE.md).
$user        = get_jimi_user();
$isAdmin     = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$filtroCust  = $_GET['customer_id'] ?? null;
$scopeCust   = report_customer_scope($filtroCust, $isAdmin, $customerId);
$customers   = $isAdmin ? report_customer_options($db) : [];
$scopeSql    = $scopeCust !== null ? ' AND d.customer_id = :cid' : '';
$scopeParams = $scopeCust !== null ? [':cid' => $scopeCust] : [];
$mostrarCliente = ($scopeCust === null);

// 🔴 `is_active = 1` pelo mesmo motivo do vídeo ao vivo: playback também PEDE
// gravação ao equipamento, então depende dele responder. Equipamento dado baixa
// no cadastro não deve aparecer aqui.
$devices = $db->prepare("
    SELECT d.imei, d.device_name, dm.model_name, dm.protocol,
           COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) AS camera_count,
           COALESCE(cu.name, '—') AS customer_name
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN customers cu ON cu.id = d.customer_id
    WHERE d.is_active = 1 {$scopeSql}
    ORDER BY cu.name, d.device_name ASC
");
$devices->execute($scopeParams);
$devices = $devices->fetchAll();

// IMEI do GET só vale se pertencer ao cliente da sessão (multi-tenant)
$selImei   = $_GET['imei'] ?? '';
$selDevice = null;
foreach ($devices as $d) {
    if ($d['imei'] === $selImei) { $selDevice = $d; break; }
}
if (!$selDevice && !empty($devices)) { $selDevice = $devices[0]; $selImei = $selDevice['imei']; }
if (!$selDevice) $selImei = '';

$selCam      = $selDevice ? max(1, (int)($selDevice['camera_count'] ?? 1)) : 1;
$selProtocol = $selDevice['protocol'] ?? 'JTT';
$selChannel  = (int)($_GET['channel'] ?? 1);
if ($selChannel < 1 || $selChannel > $selCam) $selChannel = 1;
$dateFrom   = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 day'));
$dateTo     = $_GET['date_to'] ?? date('Y-m-d');
$requested  = !empty($_GET['request']);

/**
 * Teto de gravações "no cartão" exibidas de uma vez.
 *
 * Era 300, herdado do JT/T. Uma câmera JIMI grava um bloco por minuto: 300
 * itens são 5 h de um canal, e o filtro padrão da tela pede DOIS DIAS. 500
 * cobrem um turno inteiro (8 h 20 de gravação contínua) e mantêm a página em
 * ~600 KB — no teto, cada item custa ~1,2 KB de HTML.
 */
const PB_LIMITE_CARTAO = 500;

$recordings = [];
$cartaoTruncado = false;
$sessoes = [];       // canal => sessões de gravação (barra do período)
$marcas  = [];       // instantes já baixados, marcados sobre a barra
$barraIni = null;    // janela da barra, em epoch UTC
$barraFim = null;
// Defaults ANTES do bloco condicional: o template renderiza sob `$requested`,
// mas a consulta só roda com `$requested && $selImei`. Sem isto, pedir a tela
// sem equipamento selecionado usaria variável indefinida.
$ttl         = resource_list_ttl_minutes();
$capturaInfo = ['ultima' => null, 'minutos' => null];
if ($requested && $selImei) {
    // Dias digitados são BRT; colunas do banco são UTC
    list($utcFrom, $utcTo) = brt_day_range_to_utc($dateFrom, $dateTo);
    $utcTz = new DateTimeZone('UTC');
    $toTs = function ($s) use ($utcTz) {
        if (!$s) return null;
        $dt = date_create($s, $utcTz);
        return $dt ? $dt->getTimestamp() : null;
    };

    // 1) Gravações que a câmera reportou no cartão (37381 → /pushresourcelist)
    //
    // ⚠️ v4.9.17 — SÓ LISTAGEM DENTRO DA VALIDADE. O cartão é buffer circular:
    // até aqui a tela mostrava listas de até 32 dias como se fossem atuais, e o
    // usuário clicava em download de arquivo havia muito sobrescrito. A idade
    // vem junto para a tela poder dizer "capturada há X" em vez de fingir que
    // é agora. ($ttl já vem definido acima, para o template também enxergá-lo.)
    $stmt = $db->prepare("
        SELECT id, resource_type, file_name, file_size, start_time, end_time, channel_id, alarm_type,
               captured_at
        FROM resource_lists
        WHERE imei = :imei
          AND (channel_id = :ch OR channel_id = 0 OR channel_id IS NULL)
          AND start_time <= :dt
          AND COALESCE(end_time, start_time) >= :df
          AND captured_at IS NOT NULL
          AND captured_at >= (NOW() - INTERVAL {$ttl} MINUTE)
        ORDER BY start_time DESC
        LIMIT " . (PB_LIMITE_CARTAO + 1) . "
    ");
    $stmt->execute([':imei' => $selImei, ':ch' => $selChannel, ':df' => $utcFrom, ':dt' => $utcTo]);
    $resources = $stmt->fetchAll();

    // ⚠️ v4.9.34 — TRUNCAGEM VISÍVEL. No JT/T uma listagem traz dezenas de
    // arquivos e o teto nunca aparecia; no JIMI o cartão é picado em blocos de
    // UM MINUTO, e um único dia dá 1.440 por canal. Cortar em silêncio faria a
    // tela mostrar as horas mais recentes e omitir o resto do período pedido
    // sem dizer nada — que é o mesmo modo de falhar que este módulo passou
    // dias caçando. Busca-se um a mais que o teto só para saber que há mais.
    $cartaoTruncado = count($resources) > PB_LIMITE_CARTAO;
    if ($cartaoTruncado) {
        $resources = array_slice($resources, 0, PB_LIMITE_CARTAO);
    }

    // ── Sessões para a barra do período (v4.9.35) ──────────────────────────
    //
    // Consulta PRÓPRIA, de propósito, e diferente da lista acima em dois
    // pontos: pega TODOS os canais (a barra compara um com o outro — dois
    // canais que gravam juntos e aparecem desalinhados são sintoma) e NÃO tem
    // teto, porque a agregação em sessões acontece aqui no servidor e o que vai
    // para o HTML são dezenas de segmentos, não milhares de blocos. É o que faz
    // a barra mostrar o período inteiro mesmo quando a lista está truncada.
    //
    // Só as colunas do desenho: 3.000 linhas de 3 colunas é barato; as mesmas
    // 3.000 com nome de arquivo e resto seria desperdício.
    $stmtSes = $db->prepare("
        SELECT channel_id, start_time, end_time
        FROM resource_lists
        WHERE imei = :imei
          AND start_time <= :dt
          AND COALESCE(end_time, start_time) >= :df
          AND captured_at IS NOT NULL
          AND captured_at >= (NOW() - INTERVAL {$ttl} MINUTE)
        ORDER BY channel_id, start_time
    ");
    $stmtSes->execute([':imei' => $selImei, ':df' => $utcFrom, ':dt' => $utcTo]);
    $sessoes = filelist_sessoes($stmtSes->fetchAll());

    // A barra cobre o PERÍODO PEDIDO, não o intervalo gravado: é assim que o
    // vazio fica visível. Uma barra que se ajustasse às gravações mostraria
    // sempre "cheia" e esconderia justamente o que se quer ver — que dois
    // terços do período não existem no cartão.
    $barraIni = $toTs($utcFrom);
    $barraFim = $toTs($utcTo);

    // Idade da ÚLTIMA listagem deste equipamento, mesmo vencida — é o que
    // permite distinguir "nunca listado" de "listado há 3 h", que para o
    // operador são situações completamente diferentes.
    $stmtCap = $db->prepare("
        SELECT MAX(captured_at) AS ultima,
               TIMESTAMPDIFF(MINUTE, MAX(captured_at), NOW()) AS minutos
          FROM resource_lists WHERE imei = :imei
    ");
    $stmtCap->execute([':imei' => $selImei]);
    $capturaInfo = $stmtCap->fetch(PDO::FETCH_ASSOC) ?: ['ultima' => null, 'minutos' => null];

    // 2) Arquivos já extraídos para o servidor (→ /pushfileupload)
    //
    // ⚠️ v4.9.34 — A JANELA DA CONSULTA É MAIOR QUE A DA TELA, DE PROPÓSITO.
    // `event_time` de um bloco trazido por `HVIDEO` é a hora em que o UPLOAD
    // terminou, não a da gravação — e extrair uma gravação de ontem produz um
    // arquivo carimbado hoje. Com a janela justa, esse arquivo simplesmente não
    // vinha na consulta, e a gravação de origem ficava "No cartão" para sempre
    // mesmo com o vídeo no disco. A margem é de 2 dias (equipamento offline
    // sobe atrasado); o instante REAL é resolvido logo abaixo, pelo nome, e o
    // que cair fora da janela pedida é descartado ali — a tela continua
    // mostrando exatamente o período que o usuário escolheu.
    $margem = 2 * 86400;
    $stmt = $db->prepare("
        SELECT id, file_name, file_url, file_type, file_size, event_time, channel, download_status, created_at
        FROM media_files
        WHERE imei = :imei
          AND (channel = :ch OR channel IS NULL)
          AND event_time BETWEEN :df AND :dt
        ORDER BY event_time DESC
        LIMIT 400
    ");
    $stmt->execute([
        ':imei' => $selImei, ':ch' => $selChannel,
        ':df'   => gmdate('Y-m-d H:i:s', strtotime($utcFrom . ' UTC') - $margem),
        ':dt'   => gmdate('Y-m-d H:i:s', strtotime($utcTo . ' UTC') + $margem),
    ]);
    $mediaFiles = $stmt->fetchAll();

    // 3) Unificação: media_file cujo horário cai na janela da gravação (±120s)
    //    torna aquela gravação reproduzível; os demais entram como itens próprios
    //    (ex.: vídeos de evento extraídos pelo motor de ocorrências).
    //
    // ⚠️ v4.9.34 — O INSTANTE DE UM ARQUIVO É O DO NOME, QUANDO ELE TEM UM.
    // `media_files.event_time` de um bloco trazido por `HVIDEO` é a hora em que
    // o UPLOAD terminou (o equipamento avisa pelo evento `105`), que pode estar
    // horas longe do que está gravado. O nome, esse, carrega o carimbo da
    // gravação. Sem isto o arquivo extraído aparecia como item solto no lugar
    // errado da linha do tempo, e a gravação de origem continuava "No cartão"
    // para sempre. Para anexo de alarme os dois coincidem (medido), e nome de
    // arquivo JT/T não tem carimbo — nesses casos nada muda.
    $instanteDoArquivo = function (array $m) use ($toTs) {
        $doNome = filelist_ts_do_nome_utc((string)($m['file_name'] ?? ''));
        return $toTs($doNome ?: ($m['event_time'] ?? null));
    };

    // 🔴 DUAS PASSADAS, e a primeira é sem tolerância nenhuma (v4.9.36).
    //
    // A folga de ±120 s vinha do JT/T, onde uma gravação dura minutos e o
    // instante do arquivo é aproximado. No JIMI o bloco tem UM MINUTO: ±120 s
    // abrange CINCO blocos, e o vídeo extraído grudava no bloco vizinho — visto
    // na tela, um arquivo de 22:00:46 aparecendo na linha das 22:02:46. Errado
    // em silêncio, que é o pior jeito: a linha existe, tem vídeo, e é o minuto
    // errado.
    //
    // Agora o arquivo cujo instante cai DENTRO da janela da gravação casa
    // primeiro — é o caso do `HVIDEO`, cujo nome traz exatamente o início do
    // bloco. Só o que sobrar disputa a folga antiga.
    $instantes = [];
    foreach ($mediaFiles as $mi => $m) $instantes[$mi] = $instanteDoArquivo($m);

    $mediaUsed = [];
    $casamento = [];
    foreach ([0, 120] as $tolerancia) {
        foreach ($resources as $ri => $r) {
            if (isset($casamento[$ri])) continue;
            $rs = $toTs($r['start_time']);
            if ($rs === null) continue;
            $re = $toTs($r['end_time']) ?: $rs;
            foreach ($instantes as $mi => $mt) {
                if ($mt === null || isset($mediaUsed[$mi])) continue;
                if ($mt >= $rs - $tolerancia && $mt <= $re + $tolerancia) {
                    $casamento[$ri] = $mediaFiles[$mi];
                    $mediaUsed[$mi] = true;
                    break;
                }
            }
        }
    }

    foreach ($resources as $ri => $r) {
        $rs = $toTs($r['start_time']);
        $re = $toTs($r['end_time']) ?: $rs;
        $match = $casamento[$ri] ?? null;
        $recordings[] = [
            'kind'       => $match ? 'available' : 'device',
            'media'      => $match,
            'file_name'  => $match['file_name'] ?? $r['file_name'],
            'file_size'  => (int)($r['file_size'] ?: ($match['file_size'] ?? 0)),
            'time_start' => $r['start_time'],
            'time_end'   => $r['end_time'],
            'channel'    => (int)($r['channel_id'] ?: $selChannel),
            'alarm_type' => $r['alarm_type'],
            // Janela exata da gravação em GMT-0 compacto, para o 37382 do [Extrair]
            'begin_c'    => $rs !== null ? gmdate('ymdHis', $rs) : '',
            'end_c'      => $re !== null ? gmdate('ymdHis', $re) : '',
            // JIMI: o comando que traz ESTE bloco, montado a partir do próprio
            // nome que a câmera mandou na lista. Null para JT/T e para qualquer
            // nome fora do padrão — e sem ele não há botão, que é o certo:
            // 🔴 até a v4.9.33 o [Extrair] mandava 37382 (JT/T) para qualquer
            // item, inclusive de câmera JIMI, que não conhece esse comando.
            'hvideo'     => filelist_hvideo_command((string)$r['file_name']),
        ];
    }
    $janelaIni = $toTs($utcFrom);
    $janelaFim = $toTs($utcTo);
    foreach ($mediaFiles as $mi => $m) {
        if (isset($mediaUsed[$mi])) continue;
        // Arquivo que não casou com gravação nenhuma entra como item próprio
        // (vídeo de evento, por exemplo) — mas só se o instante REAL dele cair
        // no período pedido. É aqui que a margem da consulta acima é desfeita.
        $inst = $instanteDoArquivo($m);
        if ($inst !== null && $janelaIni !== null && $janelaFim !== null
            && ($inst < $janelaIni || $inst > $janelaFim)) {
            continue;
        }
        $recordings[] = [
            'kind'       => 'available',
            'media'      => $m,
            'file_name'  => $m['file_name'],
            'file_size'  => (int)($m['file_size'] ?? 0),
            // Mesma regra da unificação acima: o carimbo do NOME manda, porque
            // `event_time` de um bloco extraído é a hora do upload.
            'time_start' => filelist_ts_do_nome_utc((string)$m['file_name'])
                            ?: ($m['event_time'] ?: $m['created_at']),
            'time_end'   => null,
            'channel'    => (int)($m['channel'] ?: $selChannel),
            'alarm_type' => null,
            'begin_c'    => '',
            'end_c'      => '',
            'hvideo'     => null,
        ];
    }
    // Arquivos já no servidor viram MARCAS na barra — o operador vê de relance
    // o que já foi baixado sem precisar caçar na lista.
    //
    // ⚠️ Consulta PRÓPRIA, e não o `$mediaFiles` acima, porque aquele é filtrado
    // pelo canal SELECIONADO — reaproveitá-lo marcaria só uma das faixas e a
    // outra pareceria "nada baixado". E não dá para simplesmente alargar o de
    // cima: ele alimenta a unificação com as gravações, e um arquivo do CH2
    // casaria com uma gravação do CH1 dentro da janela de ±120 s.
    //
    // Instante pelo NOME, pela mesma razão da unificação: `event_time` de um
    // bloco extraído é a hora do upload, não a da gravação — daí a margem.
    $stmtMarcas = $db->prepare("
        SELECT file_name, event_time, channel
        FROM media_files
        WHERE imei = :imei
          AND event_time BETWEEN :df AND :dt
        ORDER BY event_time DESC
        LIMIT 400
    ");
    $stmtMarcas->execute([
        ':imei' => $selImei,
        ':df'   => gmdate('Y-m-d H:i:s', strtotime($utcFrom . ' UTC') - $margem),
        ':dt'   => gmdate('Y-m-d H:i:s', strtotime($utcTo . ' UTC') + $margem),
    ]);
    foreach ($stmtMarcas->fetchAll() as $m) {
        $t = $instanteDoArquivo($m);
        if ($t === null || $t < $barraIni || $t > $barraFim) continue;
        $marcas[] = ['t' => gmdate('Y-m-d H:i:s', $t), 'canal' => (int)($m['channel'] ?: $selChannel)];
    }

    usort($recordings, function ($a, $b) {
        return strcmp($b['time_start'] ?? '', $a['time_start'] ?? '');
    });
}

$page_title = 'Vídeo Playback';
$current_route = 'video_playback';

// mpegts.js: as gravações do cartão são MPEG-TS (.ts), e NENHUM browser toca
// isso no <video> nativo — Chrome, Firefox e Safari só decodificam MP4/WebM.
// Sem esta biblioteca o player recebia a URL, não reclamava e ficava preto.
// Ela remuxa TS→fMP4 em JavaScript e entrega por Media Source Extensions.
// Vem de CDN como o Leaflet e o Chart.js do resto do sistema (o projeto não
// tem build step — ver CLAUDE.md).
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/mpegts.js@1.7.3/dist/mpegts.js"></script>
<style>
.vid-bg{background:#0a0b0d;border-radius:var(--radius-lg);overflow:hidden;min-height:360px;display:flex;align-items:center;justify-content:center;}
.vid-bg video{width:100%;display:block;max-height:460px;}
/* ── Item da linha do tempo ──────────────────────────────────────────────
   🔴 `min-width:0` + `overflow:hidden` no .tl-meta é a correção do defeito:
   sem eles, um texto `nowrap` transborda do flex e é pintado POR BAIXO do
   botão. Quem encolhe é o texto; o botão e a hora nunca (`flex:0 0 auto`) —
   a ação e a chave de leitura não podem sumir para caber descrição. */
.timeline-item{padding:9px 14px;border-bottom:1px solid var(--hairline-soft);display:flex;align-items:center;gap:10px;transition:background .1s;}
.timeline-item.clicavel{cursor:pointer;}   /* só é ponteiro o que faz algo ao clicar */
.timeline-item:hover{background:var(--canvas-soft);}
.timeline-item.selected{background:var(--primary-soft);box-shadow:inset 3px 0 0 var(--primary);}
.tl-hora{flex:0 0 auto;font-family:"JetBrains Mono",monospace;font-size:12.5px;color:var(--ink);
         font-variant-numeric:tabular-nums;letter-spacing:-.2px;}
.tl-meta{flex:1 1 auto;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
         font-size:11px;color:var(--muted);}
/* Separador de dia: a data é dita UMA vez, e continua visível ao rolar. */
.tl-dia{position:sticky;top:0;z-index:1;background:var(--canvas);padding:6px 14px 4px;
        font-size:10px;font-weight:600;letter-spacing:.4px;text-transform:uppercase;
        color:var(--muted);border-bottom:1px solid var(--hairline);}
.tl-dia + .timeline-item{border-top:0;}
.timeline-dot{width:8px;height:8px;border-radius:50%;background:var(--primary);flex-shrink:0;}
.timeline-dot.on-device{background:var(--muted-soft);}
.pb-badge{flex:0 0 auto;font-size:10px;font-weight:600;text-transform:uppercase;padding:2px 8px;border-radius:100px;white-space:nowrap;}
.pb-badge.available{background:var(--primary-soft);color:var(--primary);}
.pb-badge.on-device{background:var(--canvas-soft);color:var(--muted);border:1px solid var(--hairline);}
.pb-extract{flex:0 0 auto;font-size:11px;padding:4px 10px;white-space:nowrap;}
/* ── Barra do período (v4.9.35) ─────────────────────────────────────────── */
.pb-barra svg{overflow:visible;}
.pb-trilho{fill:var(--canvas-soft);stroke:var(--hairline);stroke-width:.6;}
.pb-trilho.atual{stroke:var(--primary);stroke-opacity:.35;}
.pb-sessao{fill:var(--primary);fill-opacity:.72;cursor:pointer;transition:fill-opacity .1s;}
.pb-sessao:hover{fill-opacity:1;}
.pb-baixado{fill:#0f9d58;}
.pb-grid{stroke:var(--hairline);stroke-width:.6;stroke-dasharray:2 3;}
.pb-eixo{font-family:"JetBrains Mono",monospace;font-size:9px;fill:var(--muted);}
.pb-canal{font-size:9.5px;fill:var(--muted);}
.pb-canal.atual{fill:var(--ink);font-weight:600;}
.pb-leg{display:inline-block;width:9px;height:9px;border-radius:2px;vertical-align:-1px;margin-right:3px;}
.pb-leg-sessao{background:var(--primary);opacity:.72;}
.pb-leg-baixado{background:#0f9d58;}
.timeline-item.alvo{outline:2px solid var(--primary);outline-offset:-2px;}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:1fr 340px;gap:16px;">
    <!-- Player / Preview -->
    <div>
        <div class="vid-bg" id="vid-container">
            <div id="vid-placeholder" style="text-align:center;color:var(--muted-soft);">
                <i style="font-size:48px;display:block;margin-bottom:10px;opacity:.2;">&#9654;</i>
                Selecione equipamento, canal e período e clique em Requisitar
            </div>
            <video id="vid-player" controls playsinline style="display:none;width:100%;max-height:460px;"></video>
        </div>
        <div style="margin-top:10px;display:flex;justify-content:flex-end;">
            <!-- A tela não tinha como baixar: só o player, que nem toca .ts.
                 Quem precisa da prova em vídeo precisa do arquivo na mão. -->
            <a id="pb-download" class="btn btn-outline btn-sm" style="display:none;" target="_blank" download>
                &#8681; Baixar arquivo
            </a>
        </div>

    <?php if ($requested && $barraIni !== null && $barraFim > $barraIni): ?>
    <?php
    // ═══ Barra do período (v4.9.35) ═══════════════════════════════════════
    //
    // 🔴 POR QUE ELA EXISTE. A lista tem 3.021 linhas; a INFORMAÇÃO são ~47
    // sessões por canal. Medido na 400AD_3: 3,7 dias de cartão, 25 h gravadas —
    // ou seja, dois terços do período NÃO existem, e nenhuma lista comunica
    // isso. A barra responde de relance a pergunta que a lista não responde:
    // "a câmera estava gravando às 14h de terça?".
    //
    // ⚠️ SESSÕES, NÃO MINUTOS. Desenhar os 3.021 blocos individuais numa faixa
    // de ~900 px produz uma mancha sólida que MENTE — diria "gravou o tempo
    // todo" exatamente onde há buracos. A fusão é `filelist_sessoes()`.
    //
    // Os dois canais no MESMO eixo de propósito: eles gravam juntos, então
    // desalinhamento entre as duas faixas é sintoma de câmera com problema.
    //
    // SVG inline, sem biblioteca: o projeto não tem build step, e isto é
    // geometria simples. As coordenadas são calculadas aqui, no servidor, num
    // espaço de 1000 unidades que escala com o container.
    $bx0 = 54.0;    $bx1 = 992.0;   $blarg = $bx1 - $bx0;
    $bAlturaLinha = 26; $bGap = 8; $bTopo = 22;   // $bTopo abre espaço para o triângulo da 1ª faixa
    $bCanais = [];
    for ($c = 1; $c <= max(1, $selCam); $c++) $bCanais[] = $c;
    foreach (array_keys($sessoes) as $c) if ($c > 0 && !in_array($c, $bCanais, true)) $bCanais[] = $c;
    sort($bCanais);
    $bAltura = $bTopo + count($bCanais) * ($bAlturaLinha + $bGap) + 22;
    $bSpan   = max(1, $barraFim - $barraIni);
    /** epoch para coordenada X no espaco do SVG */
    $bx = function (int $t) use ($bx0, $blarg, $barraIni, $bSpan) {
        $p = ($t - $barraIni) / $bSpan;
        return $bx0 + max(0.0, min(1.0, $p)) * $blarg;
    };
    // Rótulos de dia: cada meia-noite BRT dentro da janela
    $bDias = [];
    $diaCursor = strtotime(fmt_brt(gmdate('Y-m-d H:i:s', $barraIni), 'Y-m-d') . ' 00:00:00 -03:00');
    while ($diaCursor <= $barraFim) {
        if ($diaCursor >= $barraIni) $bDias[] = $diaCursor;
        $diaCursor = strtotime('+1 day', $diaCursor);
        if (count($bDias) > 40) break;   // janela absurda não vira 400 linhas
    }
    $bTotalSessoes = array_sum(array_map('count', $sessoes));
    $bGravado = 0;
    foreach ($sessoes as $ss) { foreach ($ss as $x) { $bGravado += $x['segundos']; } }
    ?>
    <div class="card pb-barra" style="margin-top:12px;padding:12px 14px;">
        <div style="display:flex;align-items:baseline;justify-content:space-between;gap:10px;margin-bottom:6px;">
            <div style="font-size:12px;font-weight:600;color:var(--ink);">Gravações no cartão</div>
            <div style="font-size:11px;color:var(--muted);">
                <?php if ($bTotalSessoes): ?>
                    <?= (int)$bTotalSessoes ?> sessõe<?= $bTotalSessoes === 1 ? '' : 's' ?>
                    · <?= number_format($bGravado / 3600, 1, ',', '.') ?> h gravadas no período
                <?php else: ?>
                    nada listado neste período
                <?php endif; ?>
            </div>
        </div>

        <svg viewBox="0 0 1000 <?= (int)$bAltura ?>" style="width:100%;height:auto;display:block;"
             role="img" aria-label="Linha do tempo das gravações no cartão, por canal">
            <?php foreach ($bDias as $d): $x = $bx($d); ?>
            <line x1="<?= round($x, 1) ?>" y1="<?= $bTopo - 10 ?>" x2="<?= round($x, 1) ?>"
                  y2="<?= $bAltura - 20 ?>" class="pb-grid" />
            <?php /* `fmt_brt`, não `date()`: o PHP roda em UTC e o rótulo é do dia BRT.
                     Hoje os dois coincidem (meia-noite BRT é 03:00 UTC do MESMO
                     dia), mas depender dessa coincidência é como o resto das
                     datas deste projeto já quebrou antes. */ ?>
            <text x="<?= round($x + 3, 1) ?>" y="<?= $bTopo - 4 ?>" class="pb-eixo"><?= fmt_brt(gmdate('Y-m-d H:i:s', $d), 'd/m') ?></text>
            <?php endforeach; ?>

            <?php foreach ($bCanais as $i => $canal): ?>
            <?php
                $y = $bTopo + $i * ($bAlturaLinha + $bGap);
                $ehAtual = ($canal === $selChannel);
                // CH1 = frontal (OUT), CH2 = interna (IN) — mesmo par do
                // `EVIDEO`/`HVIDEO` e do vídeo ao vivo.
                $rotulo = $canal === 1 ? 'CH1 frontal' : ($canal === 2 ? 'CH2 interna' : 'CH' . $canal);
            ?>
            <text x="0" y="<?= $y + 17 ?>" class="pb-canal <?= $ehAtual ? 'atual' : '' ?>"><?= $rotulo ?></text>
            <rect x="<?= $bx0 ?>" y="<?= $y ?>" width="<?= $blarg ?>" height="<?= $bAlturaLinha ?>"
                  rx="3" class="pb-trilho <?= $ehAtual ? 'atual' : '' ?>" />

            <?php foreach ($sessoes[$canal] ?? [] as $ses): ?>
            <?php
                $t0 = strtotime($ses['inicio'] . ' UTC');
                $t1 = strtotime($ses['fim'] . ' UTC');
                $x0 = $bx($t0);
                // ⚠️ Largura MÍNIMA de propósito: numa janela de 2 dias, um
                // bloco de 1 min mede 0,3 unidade e simplesmente não aparece.
                // Um traço fino visível é mais honesto que uma gravação
                // invisível — a duração exata está no tooltip e na lista.
                $larg = max(1.6, $bx($t1) - $x0);
                $mins = (int)round($ses['segundos'] / 60);
            ?>
            <rect x="<?= round($x0, 2) ?>" y="<?= $y + 3 ?>" width="<?= round($larg, 2) ?>"
                  height="<?= $bAlturaLinha - 6 ?>" rx="1.5" class="pb-sessao"
                  onclick="pbIrParaSessao('<?= $ses['inicio'] ?>', <?= $canal ?>)">
                <title><?= fmt_brt($ses['inicio'], 'd/m H:i') ?> — <?= fmt_brt($ses['fim'], 'H:i') ?> · <?= $mins ?> min · <?= (int)$ses['blocos'] ?> bloco<?= $ses['blocos'] === 1 ? '' : 's' ?></title>
            </rect>
            <?php endforeach; ?>

            <?php foreach ($marcas as $mc): ?>
            <?php if ((int)$mc['canal'] !== $canal) continue; ?>
            <?php $xm = round($bx(strtotime($mc['t'] . ' UTC')), 2); ?>
            <?php /* Triângulo apontando para baixo, e não um traço: num período
                     de dias um instante isolado tem largura ZERO na escala, e o
                     que comunica não é o tamanho da marca e sim para onde ela
                     aponta. */ ?>
            <polygon points="<?= $xm - 3.5 ?>,<?= $y - 6 ?> <?= $xm + 3.5 ?>,<?= $y - 6 ?> <?= $xm ?>,<?= $y + 1 ?>"
                     class="pb-baixado">
                <title>Já no servidor · <?= fmt_brt($mc['t'], 'd/m H:i:s') ?></title>
            </polygon>
            <?php endforeach; ?>
            <?php endforeach; ?>

            <text x="<?= $bx0 ?>" y="<?= $bAltura - 6 ?>" class="pb-eixo"><?= fmt_brt(gmdate('Y-m-d H:i:s', $barraIni), 'd/m H:i') ?></text>
            <text x="<?= $bx1 ?>" y="<?= $bAltura - 6 ?>" class="pb-eixo" text-anchor="end"><?= fmt_brt(gmdate('Y-m-d H:i:s', $barraFim), 'd/m H:i') ?></text>
        </svg>

        <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:10px;color:var(--muted);margin-top:4px;">
            <span><span class="pb-leg pb-leg-sessao"></span> gravação no cartão</span>
            <span><span class="pb-leg pb-leg-baixado"></span> já no servidor</span>
            <span>clique numa faixa para ir até ela na lista</span>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <!-- Filters + Timeline -->
    <div>
        <div class="card" style="margin-bottom:12px;padding:14px 16px;">
            <form method="GET" id="playback-form" style="display:flex;flex-direction:column;gap:10px;" onsubmit="return onSubmitRequest(event)">
                <?php if ($isAdmin): ?>
                <div>
                    <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
                    <?php /* Trocar de cliente recarrega sem `imei`: o equipamento
                             da carteira anterior não existe na nova, e mantê-lo
                             deixaria o formulário apontando para um device que a
                             lista não oferece mais. */ ?>
                    <select id="pb-cust" onchange="location.href='?customer_id='+this.value"
                            style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                        <option value="">Todos os clientes</option>
                        <?php foreach ($customers as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div>
                    <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Equipamento</label>
                    <select name="imei" id="pb-imei" onchange="pbRebuildChannels()" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                        <?php foreach ($devices as $d): ?>
                        <option value="<?= $d['imei'] ?>" data-cam="<?= $d['camera_count']??1 ?>" data-proto="<?= htmlspecialchars($d['protocol'] ?? 'JTT') ?>" <?= $selImei===$d['imei']?'selected':'' ?>>
                            <?= $mostrarCliente ? htmlspecialchars($d['customer_name']) . ' · ' : '' ?><?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?> (<?= htmlspecialchars($d['model_name']??'?') ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($isAdmin): ?>
                <?php /* O form é GET: sem este hidden, submeter o filtro de
                         playback perderia o cliente escolhido e a tela voltaria
                         para "todos" a cada consulta. */ ?>
                <input type="hidden" name="customer_id" value="<?= $scopeCust !== null ? (int)$scopeCust : '' ?>">
                <?php endif; ?>

                <div style="display:flex;gap:8px;">
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Canal</label>
                        <select name="channel" id="pb-channel" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                            <?php for ($c=1;$c<=$selCam;$c++): ?>
                            <option value="<?= $c ?>" <?= $selChannel===$c?'selected':'' ?>>CH<?= $c ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <div style="display:flex;gap:6px;">
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">De</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    </div>
                    <div style="flex:1;">
                        <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Até</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    </div>
                </div>

                <button type="submit" name="request" value="1" class="btn btn-primary btn-sm" style="width:100%;">
                    &#128269; Requisitar Gravações
                </button>
            </form>
        </div>

        <?php if ($requested): ?>
        <div class="card" style="max-height:calc(100vh - 440px);overflow-y:auto;">
            <div style="font-size:12px;font-weight:600;color:var(--ink);padding-bottom:8px;border-bottom:1px solid var(--hairline);margin-bottom:8px;">
                <?= count($recordings) ?> grava<?= count($recordings) !== 1 ? 'ções' : 'ção' ?>
            </div>

            <?php if ($cartaoTruncado): ?>
            <?php /* Dizer que cortou, e onde. Uma lista silenciosamente cortada
                     parece "só isso foi gravado" — e no JIMI, com um bloco por
                     minuto, cortar é o caso NORMAL de um período de dois dias. */ ?>
            <div class="callout" style="font-size:11px;margin-bottom:8px;background:#fdf6e3;border-left:3px solid #b45309;color:#7c4a03">
                O período pedido tem mais gravações do que cabe nesta lista.
                Mostrando as <strong><?= PB_LIMITE_CARTAO ?> mais recentes</strong> —
                estreite o período para ver as anteriores.
            </div>
            <?php endif; ?>

            <?php
            // ── Idade da listagem (v4.9.17) ─────────────────────────────────
            // O cartão é buffer circular; a lista é um RETRATO com validade
            // curta. Dizer a idade é o que separa "informação velha rotulada"
            // (útil) de "informação velha disfarçada de atual" (armadilha) —
            // a segunda é o que esta tela fazia até aqui.
            $capMin     = $capturaInfo['minutos'] ?? null;
            $capVencida = $capturaInfo['ultima'] !== null && $capMin !== null && $capMin > $ttl;
            ?>
            <?php if ($capturaInfo['ultima'] === null): ?>
                <div class="callout info" style="font-size:11px;margin-bottom:8px">
                    Este equipamento <strong>nunca teve o cartão listado</strong>.
                    Use <strong>Consultar gravações</strong> para pedir a lista à câmera.
                </div>
            <?php elseif ($capVencida): ?>
                <div class="callout" style="font-size:11px;margin-bottom:8px;background:#fdf6e3;border-left:3px solid #b45309;color:#7c4a03">
                    A última listagem foi feita
                    <strong>há <?= $capMin >= 1440 ? intdiv($capMin,1440).' dia(s)' : ($capMin >= 60 ? intdiv($capMin,60).' h' : $capMin.' min') ?></strong>
                    e <strong>venceu</strong> (validade: <?= (int)$ttl ?> min).
                    O cartão grava em ciclo — o que estava lá já pode ter sido
                    sobrescrito. <strong>Consulte novamente</strong> antes de baixar.
                </div>
            <?php else: ?>
                <div style="font-size:11px;color:var(--muted);margin-bottom:8px">
                    Listagem de <strong><?= $capMin < 1 ? 'agora' : 'há ' . (int)$capMin . ' min' ?></strong>
                    · vence em <?= max(0, $ttl - (int)$capMin) ?> min
                </div>
            <?php endif; ?>

            <?php if (empty($recordings)): ?>
            <div class="empty-state" style="padding:24px 12px;" id="pb-empty">
                <?php if ($capVencida): ?>
                <p>A listagem anterior venceu.</p>
                <p style="font-size:11px;margin-top:4px;">
                    Ela não é exibida de propósito: ofereceria download de arquivo
                    que provavelmente não está mais no cartão. Peça uma nova.
                </p>
                <?php else: ?>
                <p>Nenhuma gravação encontrada no período.</p>
                <p style="font-size:11px;margin-top:4px;">
                    A câmera responde à consulta em alguns segundos — esta página
                    atualiza sozinha. Se persistir, verifique se o equipamento está
                    online e se há cartão de memória.
                </p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <?php
            // ── Item da linha do tempo (v4.9.36) ───────────────────────────
            //
            // 🔴 O defeito visível era o botão [Extrair] passando por cima do
            // texto: `.timeline-time` tinha `white-space:nowrap` e NENHUM
            // `overflow`, então transbordava do pai e era pintado sob o botão.
            // Clipar resolveria o sintoma — mas o texto que transbordava era
            // justamente o que NÃO precisava estar ali:
            //
            //   • `Gravação CH1` e `· CH1` diziam a MESMA coisa, duas vezes, e
            //     o canal já está fixado no filtro logo acima: em 500 linhas,
            //     500 repetições de um dado que não varia.
            //   • `19/08/2026` se repetia em toda linha; o que varia é a hora.
            //     Vira separador de dia, dito UMA vez.
            //   • O nome do arquivo (`EVENT_..._I_02.ts`) é identificação
            //     técnica, não informação de leitura: foi para o `title`, onde
            //     quem precisa acha e quem não precisa não tropeça.
            //
            // O que sobra é o que o operador procura: HORA, duração, tamanho.
            // A hora ganha `tabular-nums` para as colunas alinharem na vertical
            // — é o que torna uma lista de 500 linhas varrível com o olho.
            $diaAnterior = null;
            ?>
            <?php foreach ($recordings as $rec): ?>
            <?php
                $isAvailable = $rec['kind'] === 'available';
                $media = $rec['media'];
                $durTxt = '';
                if ($rec['time_start'] && $rec['time_end']) {
                    $durSecs = strtotime($rec['time_end']) - strtotime($rec['time_start']);
                    if ($durSecs > 0) {
                        $durTxt = $durSecs >= 60
                            ? floor($durSecs / 60) . ' min' . ($durSecs % 60 ? ' ' . ($durSecs % 60) . ' s' : '')
                            : $durSecs . ' s';
                    }
                }
                $meta = array_filter([
                    $durTxt,
                    $rec['file_size'] ? number_format($rec['file_size'] / 1024 / 1024, 1, ',', '.') . ' MB' : '',
                ]);
                // Nome do arquivo no `title`: é o que liga a linha ao arquivo no
                // disco quando alguém precisa investigar, sem poluir a leitura.
                $titulo = $isAvailable
                    ? ($rec['file_name'] ?? 'Gravação disponível no servidor')
                    : 'Gravação no cartão · CH' . $rec['channel'];
                $dia = $rec['time_start'] ? fmt_brt($rec['time_start'], 'd/m/Y') : '—';
            ?>
            <?php if ($dia !== $diaAnterior): $diaAnterior = $dia; ?>
            <div class="tl-dia"><?= $dia ?></div>
            <?php endif; ?>
            <div class="timeline-item<?= $isAvailable ? ' clicavel' : '' ?>"
                 data-ts="<?= htmlspecialchars((string)$rec['time_start']) ?>"
                 title="<?= htmlspecialchars($titulo) ?>"
                 <?= $isAvailable ? 'onclick="selectRecording(this, ' . htmlspecialchars(json_encode($media)) . ')"' : '' ?>>
                <span class="timeline-dot <?= $isAvailable ? '' : 'on-device' ?>"></span>
                <span class="tl-hora"><?= $rec['time_start'] ? fmt_brt($rec['time_start'], 'H:i:s') : '--:--:--' ?></span>
                <span class="tl-meta"><?= htmlspecialchars(implode(' · ', $meta)) ?></span>
                <?php /* Badge só no estado EXCEPCIONAL. "No cartão" é o estado
                         de 500 linhas em 500: repeti-lo custava um quarto da
                         largura da coluna e esmagava a duração até "1 …" — o
                         mesmo erro de repetir o que não varia. Quem não tem
                         badge tem o botão [Extrair], que já diz o que a linha
                         é; e o estado continua no `title` da linha, para leitor
                         de tela e para quem passa o mouse. */ ?>
                <?php if ($isAvailable): ?>
                <span class="pb-badge available">Disponível</span>
                <?php endif; ?>
                <?php if (!$isAvailable && $selProtocol === 'JIMI' && $rec['hvideo']): ?>
                <?php /* JIMI: `HVIDEO,<carimbo>,<câmera>` — o mesmo comando que
                         o reenvio de vídeo de alarme usa em produção. Não é o
                         37382: aquele é JT/T, e mandá-lo para uma câmera JIMI
                         é o "enviado com sucesso" que nunca produz arquivo. */ ?>
                <button class="btn btn-outline btn-sm pb-extract"
                        title="Pedir este minuto à câmera"
                        onclick="requestExtractJimi(event, this, '<?= htmlspecialchars($rec['hvideo'], ENT_QUOTES) ?>')">
                    &#8681; Extrair
                </button>
                <?php elseif (!$isAvailable && $selProtocol !== 'JIMI' && $rec['begin_c']): ?>
                <button class="btn btn-outline btn-sm pb-extract"
                        title="Pedir esta gravação à câmera"
                        onclick="requestExtract(event, this, <?= $rec['channel'] ?>, '<?= $rec['begin_c'] ?>', '<?= $rec['end_c'] ?>')">
                    &#8681; Extrair
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
var fileStorageUrl = <?= json_encode($fileStorageUrl) ?>;
var selImei = <?= json_encode($selImei) ?>;
var selChannel = <?= $selChannel ?>;
var selProtocol = <?= json_encode($selProtocol) ?>;
// Base do FILELIST (JIMI): montada no SERVIDOR, porque depende de VIDEO_INGEST_IP
// — o endereço que o EQUIPAMENTO alcança, que não é o mesmo que o navegador usa.
var filelistBase = <?= json_encode(filelist_url_base()) ?>;

// Reconstrói as opções de canal conforme o cadastro do equipamento escolhido
// (devices.camera_count, fallback máximo do modelo — via data-cam da option)
function pbRebuildChannels() {
    var sel = document.getElementById('pb-imei');
    if (!sel.options.length || sel.selectedIndex < 0) return;
    var cam = parseInt(sel.options[sel.selectedIndex].dataset.cam) || 1;
    var chSel = document.getElementById('pb-channel');
    var cur = parseInt(chSel.value) || 1;
    if (cur > cam) cur = 1;
    var html = '';
    for (var c = 1; c <= cam; c++) {
        html += '<option value="' + c + '"' + (c === cur ? ' selected' : '') + '>CH' + c + '</option>';
    }
    chSel.innerHTML = html;
}

var pbPlayer = null;   // instância mpegts.js ativa (precisa ser destruída)

/** Encerra o player de TS, se houver. Sem isto cada clique vaza uma instância. */
function pbDestroyPlayer() {
    if (!pbPlayer) return;
    try { pbPlayer.pause(); pbPlayer.unload(); pbPlayer.detachMediaElement(); pbPlayer.destroy(); }
    catch (e) { /* já desmontado */ }
    pbPlayer = null;
}

function selectRecording(el, rec) {
    // Interação do usuário cancela o auto-refresh (não interromper o play)
    if (window.__pbPoll) { clearTimeout(window.__pbPoll); window.__pbPoll = null; }
    document.querySelectorAll('.timeline-item').forEach(function(t) { t.classList.remove('selected'); });
    el.classList.add('selected');

    var v = document.getElementById('vid-player');
    var ph = document.getElementById('vid-placeholder');
    var dl = document.getElementById('pb-download');
    var url = fileStorageUrl + rec.file_url;
    var ehTs = /\.ts(\?|$)/i.test(rec.file_url || '');

    pbDestroyPlayer();
    v.removeAttribute('src');
    v.removeAttribute('poster');

    // Baixar sempre que houver arquivo — inclusive quando o navegador não
    // souber tocar o formato, que é o caso comum aqui (.ts).
    if (dl) {
        dl.href = url;
        dl.style.display = rec.file_url ? '' : 'none';
        dl.setAttribute('download', rec.file_name || '');
    }

    var ehVideo = ehTs || rec.file_type === 'video' || rec.file_type === 'mp4' || rec.file_type === 'flv';

    if (ehVideo && ehTs && window.mpegts && mpegts.isSupported()) {
        // MPEG-TS remuxado para fMP4 em JS (o <video> não decodifica TS)
        ph.style.display = 'none';
        v.style.display = 'block';
        pbPlayer = mpegts.createPlayer({ type: 'mpegts', isLive: false, url: url });
        pbPlayer.attachMediaElement(v);
        pbPlayer.load();
        pbPlayer.play().catch(function() {});
        pbPlayer.on(mpegts.Events.ERROR, function(tipo, detalhe) {
            ph.innerHTML = '<div style="text-align:center;color:var(--muted-soft);padding:16px;">'
                + 'Não foi possível reproduzir aqui (' + tipo + ').<br>'
                + '<span style="font-size:12px;">Use o botão Baixar — o arquivo está íntegro no servidor.</span></div>';
            ph.style.display = '';
            v.style.display = 'none';
        });
    } else if (ehVideo) {
        ph.style.display = 'none';
        v.style.display = 'block';
        v.src = url;
        v.play().catch(function() {});
    } else if (rec.file_type === 'image' || rec.file_type === 'jpg' || rec.file_type === 'png') {
        ph.style.display = 'none';
        v.style.display = 'block';
        v.poster = url;
    } else {
        ph.innerHTML = '<div style="text-align:center;color:var(--muted-soft);"><i style="font-size:36px;display:block;margin-bottom:8px;opacity:.2;">&#128196;</i>' + (rec.file_name || 'Arquivo') + '</div>';
        ph.style.display = '';
        v.style.display = 'none';
    }
}

// Dispara um comando ao device via /sendcommand. O `cb` é opcional: a consulta
// 37381 é fire-and-forget (a resposta vem pelo /pushresourcelist), mas a
// extração 37382 precisa saber se o servidor recusou — sem destino FTP
// configurado ele devolve 503, e o usuário tem de ver isso.
function pbSendCmd(imei, proNo, contentObj, cb) {
    var serverFlagId = (selProtoOf(imei) === 'JIMI') ? 1 : 0;
    fetch('/sendcommand', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
        keepalive: true,
        body: JSON.stringify({
            imei: imei,
            proNo: proNo,
            serverFlagId: serverFlagId,
            // Comando de texto (proNo 128, família JIMI) vai CRU. Passá-lo por
            // JSON.stringify mandaria `"FILELIST,http://..."` com aspas, e a
            // câmera receberia um comando que não existe.
            content: (typeof contentObj === 'string') ? contentObj : JSON.stringify(contentObj)
        })
    }).then(function (r) {
        if (!cb) return;
        return r.json().catch(function () { return {}; }).then(function (j) {
            cb(r.ok && (j.code === 0 || j.code === undefined), j.msg);
        });
    }).catch(function (e) {
        if (cb) cb(false, 'Falha de rede ao falar com o servidor.');
    });
}

// Protocolo do device escolhido no select (data-proto); fallback: o da página
function selProtoOf(imei) {
    var sel = document.getElementById('pb-imei');
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === imei) return sel.options[i].dataset.proto || 'JTT';
    }
    return selProtocol || 'JTT';
}

// Formato de data JT/T: yyMMddHHmmss em GMT 0 (dias digitados são BRT/-03)
function jttUtcCompact(dayStr, endOfDay) {
    var d = new Date(dayStr + 'T' + (endOfDay ? '23:59:59' : '00:00:00') + '-03:00');
    if (isNaN(d.getTime())) return '';
    return fmtCompactUTC(d);
}

function fmtCompactUTC(d) {
    function p(n) { return String(n).padStart(2, '0'); }
    return String(d.getUTCFullYear()).slice(2) + p(d.getUTCMonth() + 1) + p(d.getUTCDate()) +
           p(d.getUTCHours()) + p(d.getUTCMinutes()) + p(d.getUTCSeconds());
}

// A consulta 37381 (0x9205) não aceita janela que cruza o dia (GMT-0):
// fatia o período BRT em segmentos por dia UTC (máx. 15 = ~2 semanas)
function utcDaySegments(fromDay, toDay) {
    var start = new Date(fromDay + 'T00:00:00-03:00');
    var end = new Date(toDay + 'T23:59:59-03:00');
    if (isNaN(start.getTime()) || isNaN(end.getTime()) || end < start) return [];
    var segs = [];
    var cur = start;
    while (cur <= end && segs.length < 15) {
        var dayEnd = new Date(Date.UTC(cur.getUTCFullYear(), cur.getUTCMonth(), cur.getUTCDate(), 23, 59, 59));
        var segEnd = dayEnd < end ? dayEnd : end;
        segs.push([fmtCompactUTC(cur), fmtCompactUTC(segEnd)]);
        cur = new Date(dayEnd.getTime() + 1000);
    }
    return segs;
}

/**
 * Clique numa faixa da barra do período.
 *
 * Mesmo canal → rola a lista até o primeiro bloco daquela sessão e o destaca.
 * Canal DIFERENTE → recarrega com aquele canal, preservando o resto do filtro:
 * a lista é de um canal só, e fingir que dá para navegar sem trocar seria pior
 * que recarregar.
 *
 * @param {string} inicioUtc Início da sessão, `Y-m-d H:i:s` em UTC
 * @param {number} canal     Canal da faixa clicada
 */
function pbIrParaSessao(inicioUtc, canal) {
    if (canal !== selChannel) {
        var p = new URLSearchParams(location.search);
        p.set('channel', String(canal));
        p.set('request', '1');
        p.delete('poll');            // a troca é ação do usuário, não do poll
        location.href = location.pathname + '?' + p.toString();
        return;
    }
    // Interação do usuário cancela o auto-refresh — o mesmo que selectRecording
    // faz, e pela mesma razão: recarregar por baixo de quem está lendo é hostil.
    if (window.__pbPoll) { clearTimeout(window.__pbPoll); window.__pbPoll = null; }

    var itens = document.querySelectorAll('.timeline-item[data-ts]');
    var alvo = null;
    // A lista está em ordem decrescente; o alvo é o item mais ANTIGO que ainda
    // é >= o início da sessão, ou seja, o primeiro bloco dela.
    for (var i = 0; i < itens.length; i++) {
        if ((itens[i].dataset.ts || '') >= inicioUtc) alvo = itens[i];
    }
    if (!alvo) {
        // Sessão fora do que a lista carregou (teto de itens): dizer, em vez de
        // não fazer nada e parecer que o clique quebrou.
        alert('Esta sessão está fora das gravações carregadas na lista. '
            + 'Estreite o período para alcançá-la.');
        return;
    }
    document.querySelectorAll('.timeline-item.alvo').forEach(function (t) { t.classList.remove('alvo'); });
    alvo.classList.add('alvo');
    alvo.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

/**
 * Pede a lista do cartão a uma câmera JIMI.
 *
 * 🔴 SÃO DOIS COMANDOS, e mandar só o primeiro não sobe lista nenhuma.
 * `FILELIST,<url>` (planilha A006) apenas GRAVA o endereço no equipamento; quem
 * dispara o upload é a forma NUA `FILELIST` (A007). Até a v4.9.33 esta tela
 * mandava só o primeiro: o comando voltava `executed`, a tela dizia
 * "consultando" e a câmera não tinha o que fazer. Está nos dados de produção —
 * sete `FILELIST,<url>` entre 14:54 e 15:22 de 19/08 sem uma única captura; o
 * `FILELIST` nu de 15:00:19 gerou a captura de 15:00:19, no mesmo segundo.
 *
 * Em SEQUÊNCIA, não em paralelo: a URL precisa estar gravada antes do disparo.
 * A resposta do JIMI é síncrona, então o callback do primeiro já é a
 * confirmação de que o equipamento aceitou o endereço — e se ele recusar, o
 * segundo NÃO sai: disparar contra endereço que não foi aceito é mandar a
 * câmera subir 78 KB para lugar nenhum.
 *
 * @param {string} imei Equipamento
 * @param {function(boolean,string=):void} [cb] Chamado ao fim (para teste)
 */
function pbRequestJimi(imei, cb) {
    // 🔴 Sem endereço alcançavel pelo EQUIPAMENTO não se manda nada. O que
    // existia antes era pior que o erro: `filelist_url_base()` caía em
    // `localhost`, a câmera respondia `FILELIST:OK!`, guardava o endereço — e
    // o upload morria com `failed!` sem ninguém ligar uma coisa à outra.
    if (!filelistBase) {
        alert('Não há endereço configurado para a câmera enviar a lista. '
            + 'Defina VIDEO_INGEST_IP (ou FILELIST_URL) no .env do servidor: '
            + 'precisa ser um IP que o EQUIPAMENTO alcance, nunca localhost.');
        if (cb) cb(false, 'sem VIDEO_INGEST_IP');
        return;
    }
    pbSendCmd(imei, 128, 'FILELIST,' + filelistBase + imei, function (ok, msg) {
        if (!ok) {
            alert('A câmera não aceitou o endereço da lista: ' + (msg || 'sem resposta.'));
            if (cb) cb(false, msg);
            return;
        }
        pbSendCmd(imei, 128, 'FILELIST', function (ok2, msg2) {
            if (cb) cb(ok2, msg2);
        });
    });
}

function onSubmitRequest(e) {
    var imei = document.getElementById('pb-imei').value;
    var channel = Number(document.querySelector('select[name=channel]').value) || 1;
    var from = document.querySelector('input[name=date_from]').value;
    var to = document.querySelector('input[name=date_to]').value;
    if (!imei || !from || !to) return true;

    if (selProtoOf(imei) === 'JIMI') {
        // ── FILELIST (v4.9.18) ──────────────────────────────────────────────
        //
        // 🔴 Aqui ia **34818** até a v4.9.17, e ele NUNCA funcionou para JIMI.
        // É comando JT/T (0x8802, "multimedia data retrieval"): o IoT Hub
        // aceitava, a tela dizia "consultando", e a câmera nem respondia —
        // os comandos ficavam em `sent`, nunca `executed`. 18 tentativas
        // registradas antes de alguém desconfiar, e `resource_lists` tem
        // 1.321 linhas, ZERO de JIMI.
        //
        // No JIMI a listagem é ao contrário: em vez de responder a uma
        // consulta com janela, a câmera SOBE um TXT com a lista inteira para
        // a URL que mandamos. Por isso não há beginTime/endTime aqui — o
        // comando não aceita intervalo, e o filtro de data continua valendo
        // só na exibição.
        //
        // Comando de TEXTO (proNo 128), não JSON — ver pbRequestJimi().
        pbRequestJimi(imei);
    } else {
        // proNo 37381 (0x9205): lista as gravações do cartão; resposta assíncrona
        // via /pushresourcelist. channel+channelId: exemplos da doc divergem.
        utcDaySegments(from, to).forEach(function(seg, i) {
            pbSendCmd(imei, 37381, {
                channel: channel, channelId: channel,
                beginTime: seg[0], endTime: seg[1],
                alarmFlag: 0, resourceType: 0, codeType: 0, storageType: 0,
                instructionID: 'pb' + Date.now() + '_' + i
            });
        });
    }
    return true;
}

// [Extrair]: proNo 37382 — "FTP file upload command" (v4.9.1).
//
// Era 34818, que a doc oficial chama de "Multimedia data retrieval": uma
// CONSULTA, e da família de fotos do JT/T 808. Ela era aceita pelo IoTHub,
// marcada `executed`, e não produzia arquivo nenhum — nem callback. O comando
// que faz a câmera SUBIR o vídeo é o 37382, e a doc é explícita quanto à
// sequência: 37381 primeiro, para obter beginTime/endTime, depois 37382.
//
// Os campos de FTP (serverAddress/ftpPort/userName/password/fileUploadPath)
// NÃO vão daqui: `sendcommand.php` os injeta no servidor. Mandar a senha do
// FTP pelo JavaScript a exporia no código-fonte da página.
function requestExtract(ev, btn, channel, beginC, endC) {
    ev.stopPropagation();
    btn.disabled = true;
    btn.innerHTML = '&#8230; Enviando';

    pbSendCmd(selImei, 37382, {
        channel: channel, channelId: channel,
        beginTime: beginC, endTime: endC,
        alarmFlag: 0,
        resourceType: 2,   // 2 = vídeo
        codeType: 0,       // 0 = fluxo principal ou secundário
        storageType: 0     // 0 = principal ou de backup
    }, function (ok, msg) {
        // Ao contrário do resto da tela, este retorno é ESPERADO: sem destino
        // FTP configurado o servidor devolve 503, e o usuário precisa ver isso
        // em vez de ficar esperando um arquivo que nunca vem.
        if (ok) {
            btn.innerHTML = '&#10003; Solicitado';
        } else {
            btn.disabled = false;
            btn.innerHTML = '&#8681; Extrair';
            alert(msg || 'Não foi possível solicitar a extração.');
        }
    });
}

/**
 * [Extrair] do JIMI — `HVIDEO,<carimbo>,<câmera>` (proNo 128).
 *
 * O comando vem pronto do servidor (`filelist_hvideo_command()`), montado a
 * partir do nome que a própria câmera mandou na lista: o carimbo volta na hora
 * LOCAL dela, sem conversão pelo caminho. Converter para UTC aqui faria a
 * câmera procurar um bloco três horas fora — e ela responderia "não existe",
 * não "fuso errado".
 *
 * A resposta do equipamento é SÍNCRONA e chega em `msg`: `OK!` quando aceitou,
 * ou o motivo da recusa (há firmware que não aceita as formas longas — foi o
 * que obrigou o reenvio de alarme a tentar `EVIDEO` e cair no `HVIDEO`).
 * O arquivo em si sobe depois, e o equipamento avisa o fim pelo evento `105`.
 */
function requestExtractJimi(ev, btn, comando) {
    ev.stopPropagation();
    btn.disabled = true;
    btn.innerHTML = '&#8230; Enviando';

    pbSendCmd(selImei, 128, comando, function (ok, msg) {
        if (ok) {
            btn.innerHTML = '&#10003; Solicitado';
            btn.title = 'A câmera respondeu: ' + (msg || 'OK') +
                        '. O vídeo aparece aqui quando o upload terminar.';
        } else {
            btn.disabled = false;
            btn.innerHTML = '&#8681; Extrair';
            alert('A câmera recusou o pedido: ' + (msg || 'sem resposta.'));
        }
    });
}

<?php if ($requested): ?>
// A câmera responde ao 37381 em segundos, mas de forma assíncrona: recarrega a
// página algumas vezes (o comando NÃO é reenviado — só o form o dispara)
(function() {
    var params = new URLSearchParams(location.search);
    var poll = parseInt(params.get('poll') || '0');
    if (poll < 6) {
        window.__pbPoll = setTimeout(function() {
            params.set('poll', poll + 1);
            location.replace(location.pathname + '?' + params.toString());
        }, 8000);
    }
})();
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
