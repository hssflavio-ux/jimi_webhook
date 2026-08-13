<?php
/**
 * JIMI Webhook System — Parâmetros de configuração das câmeras JT/T (v4.9.12)
 *
 * Ponto ÚNICO de leitura do `_content` que os comandos `33028` (todos) e
 * `33030` (específicos) devolvem. Chamado de dois lugares:
 *
 *   1. `sendcommand.php`         — device online, resposta síncrona
 *   2. `pushinstructresponse.php` — device offline, callback
 *
 * Mora aqui, e não em um dos dois, pela lição do `alarm_label_sql()`: cópias
 * divergem, e a divergência não aparece — o `scripts/worker.php` imprimiu
 * código cru por meses enquanto a tela mostrava nome.
 *
 * ⚠️ ESTE ARQUIVO SEGUE O QUE A CÂMERA MANDA, NÃO O QUE A DOC DESCREVE.
 * As três divergências foram medidas em equipamento real em 12/08/2026 e estão
 * registradas em `PROJETO_PARAMETROS.md` §2. Resumo, porque quem mexer aqui
 * precisa saber antes de "corrigir" o parser para a doc:
 *
 *   a) o campo de contagem chama `paramCount`; a doc diz `totalNum`, que
 *      NENHUM device mandou;
 *   b) os parâmetros de vídeo NÃO vêm na chave `119`, e sim num bloco
 *      `paramId`/`channelCount`/`channel_1..N`;
 *   c) `paramCount` não é o número de chaves de topo (o JC371 declara 87 e
 *      entrega 46), então ele NÃO serve para validar "recebi tudo?".
 *
 * @package JimiWebhook
 */

/**
 * Posições do CSV de vídeo POR CANAL (doc, Tabela 2.3.9.2.3).
 * A primeira posição é o número do canal; as outras 11 são a configuração.
 */
const DEVICE_PARAM_CANAL_CAMPOS = [
    'canal', 'rt_encoding', 'rt_resolucao', 'rt_keyframe', 'rt_fps', 'rt_bitrate',
    'gv_encoding', 'gv_resolucao', 'gv_keyframe', 'gv_fps', 'gv_bitrate', 'osd',
];

/**
 * Posições do CSV de áudio/vídeo GERAL (doc, Tabela 2.3.9.2.1) — parâmetro 117.
 * Não tem número de canal; a última posição é a saída de áudio.
 */
const DEVICE_PARAM_AV_CAMPOS = [
    'rt_encoding', 'rt_resolucao', 'rt_keyframe', 'rt_fps', 'rt_bitrate',
    'gv_encoding', 'gv_resolucao', 'gv_keyframe', 'gv_fps', 'gv_bitrate',
    'osd', 'audio',
];

/** Enumerações compartilhadas pelos campos do CSV de vídeo. */
const DEVICE_PARAM_ENCODING = [0 => 'CBR', 1 => 'VBR', 2 => 'ABR'];
const DEVICE_PARAM_RESOLUCAO = [
    0 => 'QCIF', 1 => 'CIF', 2 => 'WCIF', 3 => 'D1', 4 => 'WD1', 5 => '720P', 6 => '1080P',
];
/** Bits do OSD (legenda sobreposta ao vídeo). */
const DEVICE_PARAM_OSD_BITS = [
    0 => 'Data e hora', 1 => 'Placa', 2 => 'Canal', 3 => 'Coordenadas',
    4 => 'Velocidade do tacógrafo', 5 => 'Velocidade por satélite', 6 => 'Tempo de condução',
];

/**
 * Chaves do `_content` que NÃO são parâmetros — são metadados do envelope.
 * `paramId` e `channelCount` descrevem o bloco de vídeo; `paramCount` é a
 * contagem que o device declara.
 */
const DEVICE_PARAM_META = ['paramCount', 'totalNum', 'paramId', 'channelCount'];

/**
 * Lê o `_content` de um 33028/33030 e devolve as entradas normalizadas.
 *
 * Nada aqui rejeita conteúdo parcial ou desconhecido de propósito: parâmetro
 * fora do catálogo tem de APARECER (com nome genérico), do mesmo jeito que
 * alarme fora do catálogo aparece como `Código NNNN (JTT)` em vez de sumir.
 *
 * @param  string $content `_content` cru, como veio do gateway
 * @returns array{
 *     ok: bool,
 *     erro: string|null,
 *     raw: string,
 *     param_count: int|null,
 *     entradas: array<int, array{param_no:int, channel:int, raw:string, json:array|null}>
 * }
 */
function parse_param_content(string $content): array
{
    $vazio = ['ok' => false, 'erro' => null, 'raw' => $content,
              'param_count' => null, 'entradas' => []];

    $content = trim($content);
    if ($content === '') {
        $vazio['erro'] = 'conteúdo vazio';
        return $vazio;
    }

    $j = json_decode($content, true);
    if (!is_array($j)) {
        // Callback truncado cai aqui. Até a v4.9.11 isso era o caso NORMAL —
        // `command_content` era varchar(250) e cortava o JSON no meio.
        $vazio['erro'] = 'JSON inválido: ' . json_last_error_msg();
        return $vazio;
    }

    // `paramCount` é o nome real; `totalNum` fica aceito porque é o que a doc
    // publica e um firmware futuro pode alinhar-se a ela.
    $paramCount = null;
    foreach (['paramCount', 'totalNum'] as $k) {
        if (isset($j[$k]) && is_numeric($j[$k])) { $paramCount = (int)$j[$k]; break; }
    }

    $entradas = [];

    // ── Bloco de vídeo por canal ────────────────────────────────────────────
    // `paramId` diz a qual parâmetro o bloco pertence (medido: sempre "119").
    // Sem ele, assume 119 — é o único que a doc define como "por canal".
    $paramIdCanal = isset($j['paramId']) && is_numeric($j['paramId']) ? (int)$j['paramId'] : 119;
    foreach ($j as $chave => $valor) {
        if (!preg_match('/^channel_(\d+)$/', (string)$chave, $m)) continue;
        $canal = (int)$m[1];
        $raw   = (string)$valor;
        $entradas[] = [
            'param_no' => $paramIdCanal,
            'channel'  => $canal > 0 ? $canal : 1,
            'raw'      => $raw,
            'json'     => expand_param_csv($paramIdCanal, $raw),
        ];
    }

    // ── Parâmetros numéricos ────────────────────────────────────────────────
    foreach ($j as $chave => $valor) {
        if (in_array((string)$chave, DEVICE_PARAM_META, true)) continue;
        if (!preg_match('/^\d+$/', (string)$chave)) continue;

        // ⚠️ O valor NÃO é sempre string: o `132` mediu como número e o `91`
        // como decimal em string ("0.0"). Um cast cego para string de um bool
        // ou null viraria "1"/"" silenciosamente — daí o tratamento explícito.
        if (is_bool($valor))      $raw = $valor ? '1' : '0';
        elseif ($valor === null)  $raw = '';
        elseif (is_scalar($valor)) $raw = (string)$valor;
        else                       $raw = json_encode($valor, JSON_UNESCAPED_UNICODE);

        $no = (int)$chave;
        $entradas[] = [
            'param_no' => $no,
            'channel'  => 0,
            'raw'      => $raw,
            'json'     => expand_param_csv($no, $raw),
        ];
    }

    return [
        'ok'          => true,
        'erro'        => null,
        // O bruto viaja junto para o snapshot: quem grava não deve precisar
        // segurar uma segunda variável só para isso, e foi justamente perder o
        // payload bruto que criou o defeito que a v4.9.11 consertou.
        'raw'         => $content,
        'param_count' => $paramCount,
        'entradas'    => $entradas,
    ];
}

/**
 * Expande o CSV posicional dos parâmetros de vídeo em campos nomeados.
 *
 * O `channel_2` = "2,0,0,0,0,0,0,0,0,0,0,0" não diz nada a ninguém; expandido,
 * vira resolução, bitrate e OSD — que é o que se compara entre câmeras.
 *
 * @param  int    $paramNo Número do parâmetro (117 = geral, 119 = por canal)
 * @param  string $raw     Valor cru
 * @returns array|null     Campos nomeados, ou null quando não é CSV de vídeo
 */
function expand_param_csv(int $paramNo, string $raw): ?array
{
    if ($paramNo !== 117 && $paramNo !== 119) return null;
    if (strpos($raw, ',') === false) return null;

    $campos = $paramNo === 119 ? DEVICE_PARAM_CANAL_CAMPOS : DEVICE_PARAM_AV_CAMPOS;
    $partes = array_map('trim', explode(',', $raw));

    $out = [];
    foreach ($campos as $i => $nome) {
        // Firmware que mandar menos posições não pode derrubar a leitura: o
        // que veio é gravado, o que faltou fica ausente do JSON.
        if (!array_key_exists($i, $partes)) break;
        $out[$nome] = is_numeric($partes[$i]) ? (int)$partes[$i] : $partes[$i];
    }
    return $out ?: null;
}

/**
 * Grava um snapshot bruto e atualiza o estado atual de `device_params`.
 *
 * 🔴 NUNCA APAGA o parâmetro ausente da resposta. Ausência não é
 * "desconfigurado": o JC371 medido declarou 87 parâmetros e entregou 46, e o
 * `94` (ângulo de capotamento) é DOCUMENTADO e não veio. Um `DELETE` do que
 * não veio apagaria configuração real a cada leitura parcial.
 *
 * @param  PDO      $db
 * @param  string   $imei
 * @param  array    $parsed    Retorno de parse_param_content()
 * @param  int      $proNo     33028 | 33030
 * @param  string   $source    '33028' | '33030' | '33027-echo'
 * @param  int|null $commandId Linha de `commands`, quando houver
 * @returns int                Quantas entradas foram gravadas
 */
function upsert_device_params(PDO $db, string $imei, array $parsed, int $proNo,
                              string $source, ?int $commandId = null): int
{
    if (empty($parsed['ok']) || empty($parsed['entradas'])) return 0;

    $entradas = $parsed['entradas'];

    $snap = $db->prepare(
        "INSERT INTO device_param_snapshots
             (imei, pro_no, content_raw, param_count, parsed_count, command_id)
         VALUES (:imei, :pro, :raw, :pc, :parsed, :cid)"
    );
    $snap->execute([
        ':imei'   => $imei,
        ':pro'    => $proNo,
        ':raw'    => $parsed['raw'] ?? '',
        ':pc'     => $parsed['param_count'],
        ':parsed' => count($entradas),
        ':cid'    => $commandId,
    ]);

    $up = $db->prepare(
        "INSERT INTO device_params
             (imei, param_no, channel, value_raw, value_json, read_at, source)
         VALUES (:imei, :no, :ch, :raw, :json, NOW(), :src)
         ON DUPLICATE KEY UPDATE
             value_raw  = VALUES(value_raw),
             value_json = VALUES(value_json),
             read_at    = VALUES(read_at),
             source     = VALUES(source)"
    );

    $n = 0;
    foreach ($entradas as $e) {
        $up->execute([
            ':imei' => $imei,
            ':no'   => $e['param_no'],
            ':ch'   => $e['channel'],
            ':raw'  => mb_substr($e['raw'], 0, 255),
            ':json' => $e['json'] !== null ? json_encode($e['json'], JSON_UNESCAPED_UNICODE) : null,
            ':src'  => $source,
        ]);
        $n++;
    }
    return $n;
}

/**
 * Catálogo inteiro, indexado por `param_no`, em uma consulta.
 *
 * @param  PDO $db
 * @returns array<int, array>
 */
function param_catalog(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $rows = $db->query(
            "SELECT param_no, name_pt, unit, value_kind, enum_json, grupo, writable, is_secret, doc_ref
               FROM device_param_catalog"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $cache = [];
    }
    $cache = [];
    foreach ($rows as $r) $cache[(int)$r['param_no']] = $r;
    return $cache;
}

/**
 * Rótulo do parâmetro — nunca vazio.
 *
 * Parâmetro fora do catálogo devolve `Parâmetro NNN`, e isso é deliberado:
 * mostrar o número é honesto, inventar um nome quebraria junção mais tarde.
 * É a mesma decisão do `Código NNNN (JTT)` dos alarmes.
 *
 * @param  array $catalogo Retorno de param_catalog()
 * @param  int   $no
 * @returns string
 */
function param_label(array $catalogo, int $no): string
{
    return $catalogo[$no]['name_pt'] ?? ('Parâmetro ' . $no);
}

/**
 * Formata o valor para leitura humana, conforme o tipo do catálogo.
 *
 * Devolve o valor CRU quando não sabe formatar — a tela nunca fica em branco
 * por causa de um tipo que ninguém previu.
 *
 * @param  array  $catalogo Retorno de param_catalog()
 * @param  int    $no
 * @param  string $raw
 * @param  bool   $revelarSegredo Admin pode ver credencial
 * @returns string
 */
function param_format(array $catalogo, int $no, string $raw, bool $revelarSegredo = false): string
{
    $c = $catalogo[$no] ?? null;
    if ($raw === '') return '—';

    if ($c && !empty($c['is_secret']) && !$revelarSegredo) {
        return str_repeat('•', 6);
    }

    $kind = $c['value_kind'] ?? 'text';
    $unit = $c['unit'] ?? null;

    switch ($kind) {
        case 'enum':
            $mapa = $c['enum_json'] ? json_decode($c['enum_json'], true) : null;
            if (is_array($mapa) && array_key_exists($raw, $mapa)) {
                return $mapa[$raw] . ' (' . $raw . ')';
            }
            return $raw;

        case 'int':
        case 'decimal':
            if (!is_numeric($raw)) return $raw;
            // Segundos viram algo legível: 3888000 s não diz nada, 45 dias diz.
            if ($unit === 's' && (float)$raw >= 3600) {
                return $raw . ' s (' . humanize_seconds((int)$raw) . ')';
            }
            return $unit ? ($raw . ' ' . $unit) : $raw;

        case 'bitmask':
            return $raw . (is_numeric($raw) ? ' (0x' . strtoupper(dechex((int)$raw)) . ')' : '');

        case 'csv':
            return $raw;

        default:
            return $raw;
    }
}

/**
 * Segundos em texto curto (a unidade que o protocolo usa não é a que se lê).
 *
 * @param  int $s
 * @returns string
 */
function humanize_seconds(int $s): string
{
    if ($s <= 0) return '0';
    $d = intdiv($s, 86400); $s -= $d * 86400;
    $h = intdiv($s, 3600);  $s -= $h * 3600;
    $m = intdiv($s, 60);
    $partes = [];
    if ($d) $partes[] = $d . 'd';
    if ($h) $partes[] = $h . 'h';
    if ($m) $partes[] = $m . 'min';
    return $partes ? implode(' ', $partes) : '<1min';
}

/**
 * Descreve o CSV de vídeo já expandido, em uma linha.
 *
 * @param  array|null $json Campos de expand_param_csv()
 * @returns string
 */
function param_video_resumo(?array $json): string
{
    if (!$json) return '—';
    $res = DEVICE_PARAM_RESOLUCAO[$json['rt_resolucao'] ?? -1] ?? ('?' . ($json['rt_resolucao'] ?? ''));
    $enc = DEVICE_PARAM_ENCODING[$json['rt_encoding'] ?? -1] ?? '?';
    $fps = $json['rt_fps'] ?? '?';
    $bit = $json['rt_bitrate'] ?? '?';
    return sprintf('%s · %s · %s fps · %s kbps', $res, $enc, $fps, $bit);
}

/**
 * Lista dos rótulos ligados no bitmask de OSD.
 *
 * @param  int|string|null $valor
 * @returns string
 */
function param_osd_labels($valor): string
{
    if (!is_numeric($valor)) return '—';
    $v = (int)$valor;
    if ($v === 0) return 'nenhuma legenda';
    $on = [];
    foreach (DEVICE_PARAM_OSD_BITS as $bit => $nome) {
        if ($v & (1 << $bit)) $on[] = $nome;
    }
    return $on ? implode(', ', $on) : ('bits ' . $v);
}

/**
 * Perfil que vale para um equipamento: o do CLIENTE, senão o padrão do MODELO.
 *
 * @param  PDO      $db
 * @param  int      $modelId
 * @param  int|null $customerId
 * @returns array|null  Linha de `param_profiles`, ou null
 */
function param_profile_for(PDO $db, int $modelId, ?int $customerId): ?array
{
    try {
        // A ordenação faz a sobreposição: o perfil do cliente vem primeiro.
        $st = $db->prepare(
            "SELECT * FROM param_profiles
              WHERE device_model_id = :m AND is_active = 1
                AND (customer_id = :c OR customer_id IS NULL)
              ORDER BY (customer_id IS NULL) ASC LIMIT 1"
        );
        $st->execute([':m' => $modelId, ':c' => $customerId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Diferença entre o que o perfil pede e o que a câmera tem.
 *
 * 🔴 SÓ O DIFF É ESCRITO, e isso não é economia de bytes. Reenviar os 46
 * parâmetros para mudar um significa sobrescrever 45 valores corretos com o que
 * o perfil acha que eles deveriam ser — inclusive os que alguém ajustou de
 * propósito naquele veículo. Um `33027` "completo" é destrutivo por natureza.
 *
 * Parâmetro que o perfil pede e a câmera NUNCA reportou fica de fora, com
 * motivo: pode ser que o modelo não o tenha (o JC181 devolve 6 de 46), e
 * mandá-lo às cegas é escrever no escuro.
 *
 * @param  PDO    $db
 * @param  string $imei
 * @param  int    $profileId
 * @returns array{
 *     alterar: array<int, array{param_no:int, channel:int, de:string, para:string}>,
 *     iguais: int,
 *     sem_leitura: array<int, int>
 * }
 */
function param_profile_diff(PDO $db, string $imei, int $profileId): array
{
    $out = ['alterar' => [], 'iguais' => 0, 'sem_leitura' => []];

    $st = $db->prepare(
        "SELECT ppv.param_no, ppv.channel, ppv.value,
                dp.value_raw, c.writable
           FROM param_profile_values ppv
           LEFT JOIN device_param_catalog c ON c.param_no = ppv.param_no
           LEFT JOIN device_params dp
                  ON dp.imei = :imei AND dp.param_no = ppv.param_no
                 AND dp.channel = ppv.channel
          WHERE ppv.profile_id = :pid
          ORDER BY ppv.param_no, ppv.channel"
    );
    $st->execute([':imei' => $imei, ':pid' => $profileId]);

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        // Guarda dupla: a tela barra na entrada, mas um perfil antigo pode
        // conter parâmetro que deixou de ser gravável.
        if (empty($r['writable'])) continue;

        if ($r['value_raw'] === null) {
            $out['sem_leitura'][] = (int)$r['param_no'];
            continue;
        }
        if ((string)$r['value_raw'] === (string)$r['value']) { $out['iguais']++; continue; }

        $out['alterar'][] = [
            'param_no' => (int)$r['param_no'],
            'channel'  => (int)$r['channel'],
            'de'       => (string)$r['value_raw'],
            'para'     => (string)$r['value'],
        ];
    }
    return $out;
}

/**
 * Registra a INTENÇÃO de escrita antes do despacho.
 *
 * 🔴 A ORDEM IMPORTA E É O CONTRATO DA F3. O valor anterior é gravado **antes**
 * de o comando sair, não depois de a resposta chegar. O dono do produto aceitou
 * o risco de escrever os parâmetros de rede (`19`, `23`, `24`, `25`, `16`-`18`)
 * porque a câmera continua alcançável por SMS — mas o SMS precisa saber PARA
 * ONDE voltar. Se o valor anterior só fosse gravado no sucesso, o caso em que
 * ele é indispensável — a escrita que derrubou a câmera — seria exatamente o
 * caso em que ele não existe.
 *
 * @param  PDO      $db
 * @param  string   $imei
 * @param  array    $mudancas  [['param_no','channel','de','para'], …]
 * @param  string   $origem    'manual' | 'perfil:<id>'
 * @param  int|null $userId
 * @returns array<int,int>     ids de `device_param_writes`
 */
function record_param_intent(PDO $db, string $imei, array $mudancas,
                             string $origem, ?int $userId = null): array
{
    $ins = $db->prepare(
        "INSERT INTO device_param_writes
             (imei, param_no, channel, from_value, to_value, origem, status, user_id)
         VALUES (:imei, :no, :ch, :de, :para, :origem, 'pedido', :uid)"
    );
    $upd = $db->prepare(
        "UPDATE device_params
            SET desired_value = :para, previous_value = :de, desired_at = NOW()
          WHERE imei = :imei AND param_no = :no AND channel = :ch"
    );

    $ids = [];
    foreach ($mudancas as $m) {
        $ins->execute([
            ':imei' => $imei, ':no' => $m['param_no'], ':ch' => $m['channel'],
            ':de' => $m['de'], ':para' => $m['para'], ':origem' => $origem, ':uid' => $userId,
        ]);
        $ids[] = (int)$db->lastInsertId();
        $upd->execute([
            ':para' => $m['para'], ':de' => $m['de'], ':imei' => $imei,
            ':no' => $m['param_no'], ':ch' => $m['channel'],
        ]);
    }
    return $ids;
}

/**
 * Fecha as intenções depois da resposta do device.
 *
 * ⚠️ `aceito` NÃO significa "o valor está aplicado". O `33027` responde `ok` de
 * confirmação de recebimento; o valor real só se conhece relendo (33030). Por
 * isso `device_params.value_raw` **não** é atualizado aqui — quem o atualiza é
 * sempre uma leitura. Escrever o valor desejado como se fosse o lido faria a
 * tela mentir exatamente quando o device recusou em silêncio.
 *
 * @param  PDO   $db
 * @param  array $writeIds
 * @param  bool  $aceito
 * @param  int|null $commandId
 * @returns void
 */
function close_param_intent(PDO $db, array $writeIds, bool $aceito, ?int $commandId = null): void
{
    if (!$writeIds) return;
    $ph = implode(',', array_fill(0, count($writeIds), '?'));
    $st = $db->prepare("UPDATE device_param_writes
                           SET status = ?, command_id = ?
                         WHERE id IN ($ph)");
    $st->execute(array_merge([$aceito ? 'aceito' : 'falhou', $commandId], $writeIds));
}

/**
 * Valor de referência de uma lista: a MODA (o mais comum).
 *
 * Mora aqui, e não no `rel_parametros.php`, porque é a regra que decide o que o
 * relatório chama de "fora do padrão" — e regra de decisão sem teste é como
 * este repositório já perdeu junção por nome três vezes.
 *
 * 🔴 EMPATE DEVOLVE `null`, e isso é a decisão que mais importa. Com dois
 * valores igualmente comuns não existe padrão; eleger um deles faria metade da
 * frota aparecer como divergente por sorteio, e o relatório perderia a
 * credibilidade justamente onde o dado é mais ambíguo.
 *
 * @param  array $vals chave => valor (a chave não é usada, só os valores)
 * @returns array{0:?string,1:int} [moda, quantas vezes] — [null, 0] se empatar
 */
function param_moda(array $vals): array
{
    if (!$vals) return [null, 0];
    $cont = array_count_values(array_map('strval', $vals));
    arsort($cont);
    $chaves = array_keys($cont);
    if (count($chaves) > 1 && $cont[$chaves[0]] === $cont[$chaves[1]]) return [null, 0];
    return [(string)$chaves[0], $cont[$chaves[0]]];
}

/**
 * Monta o `cmdContent` correto para cada proNo da família de parâmetros.
 *
 * ⚠️ AS TRÊS FORMAS SÃO DIFERENTES, e é onde a implementação anterior errou
 * nas três (ver PROJETO_PARAMETROS.md §3.1):
 *
 *   33027 → objeto  {"1":"60","85":"110"}
 *   33028 → VAZIO   (a doc mostra "" no corpo JSON)
 *   33030 → objeto com valores vazios  {"44":"","45":""}
 *
 * O 33030 com objeto de valores vazios foi CONFIRMADO em câmera real: devolveu
 * `{"paramCount":"3","85":"0","44":"0","45":"0"}` para o pedido dos três.
 *
 * @param  int   $proNo
 * @param  array $params Para 33027, mapa no=>valor; para 33030, lista de números
 * @returns string       cmdContent pronto para o gateway
 */
function build_param_cmd_content(int $proNo, array $params): string
{
    if ($proNo === 33028) return '';

    if ($proNo === 33030) {
        // ⚠️ `is_int($k)` NÃO distingue lista de mapa: o PHP converte a chave
        // numérica '85' para int 85 sozinho, então `['85' => '']` tem chave
        // inteira igual a `[44, 45]`. `array_is_list()` é o teste correto —
        // numa lista os números estão nos VALORES, num mapa nas CHAVES.
        $ehLista = array_is_list($params);
        $out = [];
        foreach ($params as $k => $v) {
            $no = $ehLista ? $v : $k;
            if (!is_numeric($no)) continue;
            $out[(string)(int)$no] = '';
        }
        return $out ? json_encode($out, JSON_UNESCAPED_SLASHES) : '{}';
    }

    if ($proNo === 33027) {
        $out = [];
        foreach ($params as $no => $valor) {
            if (!is_numeric($no)) continue;
            $out[(string)(int)$no] = (string)$valor;
        }
        return $out ? json_encode($out, JSON_UNESCAPED_SLASHES) : '{}';
    }

    return '{}';
}
