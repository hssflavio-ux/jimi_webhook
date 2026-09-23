<?php
/**
 * Rótulos e parser dos campos de "Transmissão GPS" (gps_data.gps_mode/post_type/
 * post_method) e da Extensão do Terminal (device_events, §1.15 Push Extension
 * Data — extensionId 8197/8199), usados pela tela /dados-estendidos.
 *
 * gpsMode, postType e postMethod têm tabela de valores oficial
 * (https://docs.jimicloud.com/integration/integration.html §1.3 e §1.15).
 *
 * 🔴 A tabela do postMethod está DENTRO da célula de descrição do campo, em
 * HEXADECIMAL (`0x00`…`0x0F`), enquanto o device manda o valor como INTEIRO —
 * por isso as chaves de POST_METHOD_LABELS são escritas em hex (espelham a doc
 * linha a linha) e o PHP as compara já como inteiro. Até a v4.24.0 o projeto
 * afirmava que o postMethod "não tinha tabela oficial": a tabela existia, só
 * não estava numa linha própria e passou despercebida (CHANGELOG 4.17.11).
 * Valores fora de 0x00–0x0F (27 e 28 já medidos em produção) continuam sem
 * significado publicado e NÃO recebem nome inventado.
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

/**
 * postMethod (§1.3 Push GPS Data) — "motivo da transmissão": por que o
 * equipamento enviou AQUELA posição. Tabela oficial, tradução fiel de cada
 * linha (o texto original em inglês vai no comentário, para conferência).
 */
const POST_METHOD_LABELS = [
    0x00 => 'Envio por intervalo de tempo',                                       // Upload by time interval
    0x01 => 'Envio por intervalo de distância',                                   // Upload by distance interval
    0x02 => 'Envio por ponto de inflexão',                                        // Inflection point upload
    0x03 => 'Envio por mudança de status do ACC',                                 // Upload by ACC status change
    0x04 => 'Reenvio do último ponto GPS ao voltar a ficar parado',               // Re-upload the last GPS point when back to static
    0x05 => 'Envio do último ponto válido ao recuperar a rede',                   // Upload the last effective point when network recovers
    0x06 => 'Atualização de efemérides com envio forçado de GPS',                 // Update ephemeris and upload GPS data compulsorily
    0x07 => 'Envio por acionamento da tecla lateral',                             // Upload location when side key triggered
    0x08 => 'Envio após ligar o equipamento',                                     // Upload location after power on
    0x09 => 'Envio por comando GPSON',                                            // Upload by command "GPSON"
    0x0A => 'Envio da última posição com o equipamento parado (hora atualizada)', // Upload the last longitude and latitude when device is static; time updated
    0x0B => 'Envio após consulta de dados WiFi',                                  // Upload after WIFI data query
    0x0C => 'Envio por comando LJDW (localizar imediatamente)',                   // upload by command LJDW (locate immediately)
    0x0D => 'Envio da última posição com o equipamento parado',                   // Upload the last longitude and latitude when device is static
    0x0E => 'Envio Gpsdup (periódico com o equipamento parado)',                  // Gpsdup upload (Upload regularly in a static state)
    0x0F => 'Envio após sair do modo de rastreamento',                            // Upload after exit tracking mode
];

/**
 * Nome PT-BR do motivo da transmissão.
 *
 * @param int|string|null $method postMethod como veio do banco (inteiro)
 * @returns string|null Nome, ou null se o valor não consta da tabela oficial
 *                      (ou se não há valor).
 */
function post_method_name($method): ?string
{
    if ($method === null || $method === '') return null;
    return POST_METHOD_LABELS[(int)$method] ?? null;
}

/**
 * "Código — nome" do motivo da transmissão, para exibição em texto puro.
 * O código é sempre o INTEIRO recebido; valor fora da tabela oficial mostra o
 * código com o aviso, nunca um nome inventado.
 *
 * @param int|string|null $method
 * @returns string  '3 — Envio por mudança de status do ACC' | '27 — Sem descrição do fabricante' | '—'
 */
function post_method_label($method): string
{
    if ($method === null || $method === '') return '—';
    return (int)$method . ' — ' . (post_method_name($method) ?? 'Sem descrição do fabricante');
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
