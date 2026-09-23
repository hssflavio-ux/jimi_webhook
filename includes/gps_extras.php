<?php
/**
 * Rótulos e parser dos campos de "Transmissão GPS" (gps_data.gps_mode/post_type/
 * post_method) e da Extensão do Terminal (device_events, §1.15 Push Extension
 * Data — extensionId 8197/8199), usados pela tela /dados-estendidos.
 *
 * gpsMode e postType têm tabela de valores oficial
 * (https://docs.jimicloud.com/integration/integration.html §1.3 e §1.15,
 * conferidos ao vivo em 22/09/2026); postMethod NÃO tem — só aparece em
 * exemplo de payload, com 8 valores observados em produção sem legenda
 * nenhuma (CHANGELOG 4.17.11). Nenhum rótulo foi inventado para ele.
 */

/** gpsMode (§1.3 Push GPS Data) — tabela oficial. */
const GPS_MODE_LABELS = [
    0 => 'Tempo real',
    1 => 'Reenvio',
];

/**
 * @param int|string|null $mode
 * @returns string Rótulo, ou '—' se o valor não constar da tabela oficial.
 */
function gps_mode_label($mode): string
{
    if ($mode === null || $mode === '') return '—';
    return GPS_MODE_LABELS[(int)$mode] ?? '—';
}

/** postType (§1.3 Push GPS Data) — tabela oficial. */
const POST_TYPE_LABELS = [
    1 => 'GPS',
    2 => 'LBS',
    3 => 'WiFi',
];

/**
 * @param int|string|null $type
 * @returns string Rótulo, ou '—' se o valor não constar da tabela oficial.
 */
function post_type_label($type): string
{
    if ($type === null || $type === '') return '—';
    return POST_TYPE_LABELS[(int)$type] ?? '—';
}

/** extensionId (§1.15 Push Extension Data) — os dois únicos documentados. */
const EXTENSION_ID_LABELS = [
    8197 => 'Status do terminal',
    8199 => 'Leitor serial (pass-through)',
];

/**
 * @param int|string|null $extensionId
 * @returns string Rótulo, ou "Extensão {id}" se não documentado.
 */
function extension_id_label($extensionId): string
{
    $id = (int)$extensionId;
    return EXTENSION_ID_LABELS[$id] ?? "Extensão {$id}";
}

/**
 * Tabela oficial de `key` dentro do `content` de cada extensionId. `map`
 * decodifica um enum; `divide` aplica um fator (HDOP vem ×10); `unit` só
 * anexa o sufixo; `raw` marca campo que esta v1 NÃO decodifica (ICCID em
 * BCD, dado de cartão em base64) — mostrado truncado, nunca com rótulo
 * inventado. Fonte: doc oficial, conferida ao vivo em 22/09/2026.
 */
const EXTENSION_KEY_LABELS = [
    8197 => [
        1    => ['label' => 'Tensão', 'unit' => 'V'],
        2    => ['label' => 'Tráfego diário', 'unit' => 'KB'],
        3    => ['label' => 'Bateria', 'unit' => '%'],
        4    => ['label' => 'Status de carga', 'map' => [0 => 'Não carregando', 1 => 'Carregando']],
        5    => ['label' => 'ICCID (BCD)', 'raw' => true, 'truncate' => 80],
        6    => ['label' => 'Rede', 'map' => [0 => 'Sem rede', 1 => 'WiFi', 2 => 'GSM', 3 => 'WCDMA', 4 => 'LTE']],
        7    => ['label' => 'HDOP', 'divide' => 10],
        8193 => ['label' => 'Tensão externa', 'unit' => 'V'],
    ],
    8199 => [
        1 => ['label' => 'Dados do leitor serial (base64)', 'raw' => true, 'truncate' => 60],
    ],
];

/**
 * Faz o parse do `content` de `device_events`/pushTerminalTransInfo — NÃO é
 * JSON válido (chaves inteiras sem aspas: `{8193:23.4}`,
 * `{1:"base64...",2:"base64..."}`), então `json_decode()` falha nele.
 * Formato real, medido nos dois exemplos da doc oficial (§1.15): pares
 * `chave:valor` separados por vírgula, chave sempre inteira sem aspas,
 * valor OU numérico cru OU string entre aspas duplas (sem aspas escapadas
 * dentro — é sempre base64, que não usa `"`).
 *
 * Nunca lança: `content` ausente, vazio ou fora do formato esperado
 * devolve array vazio — quem chama decide o que mostrar para "sem dado".
 *
 * @param string|null $raw Valor cru do campo `content`
 * @returns array<int,int|float|string> Mapa key(int) => valor decodificado
 */
function parse_extension_content(?string $raw): array
{
    if ($raw === null || trim($raw) === '') return [];

    $result = [];
    // Casa cada par "chave:valor" independente de vírgula/espaço em volta —
    // mais robusto que explode(',') porque não depende de contar chaves.
    if (!preg_match_all('/(-?\d+)\s*:\s*("(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?)/', $raw, $matches, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($matches as $m) {
        $key = (int)$m[1];
        $val = $m[2];
        if ($val !== '' && $val[0] === '"') {
            $result[$key] = stripslashes(substr($val, 1, -1));
        } elseif (strpos($val, '.') !== false) {
            $result[$key] = (float)$val;
        } else {
            $result[$key] = (int)$val;
        }
    }
    return $result;
}

/**
 * Monta os pares [rótulo, valor formatado] prontos para a tela, a partir do
 * mapa decodificado por parse_extension_content(). Chave sem entrada em
 * EXTENSION_KEY_LABELS (extensionId novo, ou key fora da tabela oficial)
 * cai no fallback "Chave N" com o valor cru — nunca inventa rótulo.
 *
 * @param int   $extensionId
 * @param array $parsed Saída de parse_extension_content()
 * @returns array<int,array{label:string,value:string}>
 */
function extension_content_render(int $extensionId, array $parsed): array
{
    $labels = EXTENSION_KEY_LABELS[$extensionId] ?? [];
    $out = [];
    foreach ($parsed as $key => $value) {
        $meta = $labels[$key] ?? null;
        if ($meta === null) {
            $out[] = ['label' => "Chave {$key}", 'value' => (string)$value];
            continue;
        }
        if (isset($meta['map'])) {
            $out[] = ['label' => $meta['label'], 'value' => $meta['map'][(int)$value] ?? "Valor {$value} (não mapeado)"];
        } elseif (isset($meta['divide'])) {
            $out[] = ['label' => $meta['label'], 'value' => number_format(((float)$value) / $meta['divide'], 1)];
        } elseif (!empty($meta['raw'])) {
            $out[] = ['label' => $meta['label'], 'value' => mb_strimwidth((string)$value, 0, $meta['truncate'] ?? 80, '…')];
        } elseif (isset($meta['unit'])) {
            $out[] = ['label' => $meta['label'], 'value' => $value . ' ' . $meta['unit']];
        } else {
            $out[] = ['label' => $meta['label'], 'value' => (string)$value];
        }
    }
    return $out;
}
