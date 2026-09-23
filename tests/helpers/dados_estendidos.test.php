<?php
/**
 * gps_mode_label()/post_type_label()/parse_extension_content()/
 * extension_content_render() (includes/gps_extras.php) — sem banco.
 *
 * Os dois payloads de parse_extension_content() são os exemplos REAIS da
 * doc oficial (§1.15 Push Extension Data, conferida ao vivo em 22/09/2026),
 * não inventados — `content` não é JSON válido (chave inteira sem aspas).
 *
 * Uso:
 *   php tests/helpers/dados_estendidos.test.php
 */

require_once __DIR__ . '/../../includes/gps_extras.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-70s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

echo "== gps_mode_label() / post_type_label() (tabela oficial §1.3) ==\n";
checa('gpsMode 0 = Tempo real',        'Tempo real', gps_mode_label(0));
checa('gpsMode 1 = Reenvio',           'Reenvio',    gps_mode_label(1));
checa('gpsMode string "1" (formato PDO)', 'Reenvio', gps_mode_label('1'));
checa('gpsMode fora da tabela = —',    '—',          gps_mode_label(9));
checa('gpsMode null = —',              '—',          gps_mode_label(null));

checa('postType 1 = GPS',              'GPS',  post_type_label(1));
checa('postType 2 = LBS',              'LBS',  post_type_label(2));
checa('postType 3 = WiFi',             'WiFi', post_type_label(3));
checa('postType fora da tabela = —',   '—',    post_type_label(0));

echo "== post_method_label() — motivo da transmissão (tabela oficial §1.3, hex → inteiro) ==\n";
// Os 16 valores da doc (0x00–0x0F), conferidos ao vivo em 23/09/2026. O device
// manda INTEIRO; a doc publica em hex — a conversão é o próprio `(int)`.
$esperadoMotivos = [
    0  => 'Envio por intervalo de tempo',
    1  => 'Envio por intervalo de distância',
    2  => 'Envio por ponto de inflexão',
    3  => 'Envio por mudança de status do ACC',
    4  => 'Reenvio do último ponto GPS ao voltar a ficar parado',
    5  => 'Envio do último ponto válido ao recuperar a rede',
    6  => 'Atualização de efemérides com envio forçado de GPS',
    7  => 'Envio por acionamento da tecla lateral',
    8  => 'Envio após ligar o equipamento',
    9  => 'Envio por comando GPSON',
    10 => 'Envio da última posição com o equipamento parado (hora atualizada)',
    11 => 'Envio após consulta de dados WiFi',
    12 => 'Envio por comando LJDW (localizar imediatamente)',
    13 => 'Envio da última posição com o equipamento parado',
    14 => 'Envio Gpsdup (periódico com o equipamento parado)',
    15 => 'Envio após sair do modo de rastreamento',
];
foreach ($esperadoMotivos as $cod => $nome) {
    checa("postMethod $cod (0x" . strtoupper(dechex($cod)) . ")", "$cod — $nome", post_method_label($cod));
}
checa('a tabela tem exatamente 16 entradas (0x00–0x0F)', 16, count(POST_METHOD_LABELS));
checa('chave hex da doc = inteiro do device (0x0A == 10)', true, isset(POST_METHOD_LABELS[10]) && POST_METHOD_LABELS[10] === POST_METHOD_LABELS[0x0A]);
checa('string "3" (formato PDO) casa como inteiro', '3 — Envio por mudança de status do ACC', post_method_label('3'));
checa('27 (medido em produção, fora da tabela) NÃO ganha nome inventado', '27 — Sem descrição do fabricante', post_method_label(27));
checa('28 idem', '28 — Sem descrição do fabricante', post_method_label(28));
checa('post_method_name de valor fora da tabela = null', null, post_method_name(27));
checa('postMethod null = —', '—', post_method_label(null));
checa('postMethod vazio = —', '—', post_method_label(''));

echo "== parse_extension_content() — payloads REAIS da doc oficial ==\n";
// extensionId 8197, exemplo oficial: token=...&data_list=[{...,"content":"{8193:23.4}",...}]
checa('8197 — {8193:23.4} vira [8193=>23.4]', [8193 => 23.4], parse_extension_content('{8193:23.4}'));

// extensionId 8199, exemplo oficial (chaves 1 e 2, valores base64 entre aspas)
$content8199 = '{1: "OzYwMDc2NDMxMDA1MDYxNTc4MTQ9MTUwNjE5ODAwOTA0PT8=" ,2: "KlJGVjAxMTAwLjAwOTgNCg=="}';
checa('8199 — duas chaves com valor base64 entre aspas', [
    1 => 'OzYwMDc2NDMxMDA1MDYxNTc4MTQ9MTUwNjE5ODAwOTA0PT8=',
    2 => 'KlJGVjAxMTAwLjAwOTgNCg==',
], parse_extension_content($content8199));

echo "== parse_extension_content() — casos de borda ==\n";
checa('content null = array vazio',      [], parse_extension_content(null));
checa('content vazio = array vazio',     [], parse_extension_content(''));
checa('content sem par chave:valor = array vazio', [], parse_extension_content('sem formato nenhum'));
checa('inteiro negativo é aceito',       [-1 => -5], parse_extension_content('{-1:-5}'));
checa('múltiplos pares numéricos, com espaço', [1 => 2, 3 => 4], parse_extension_content('{1: 2, 3: 4}'));

echo "== extension_id_label() ==\n";
checa('8197 = Status do terminal',  'Status do terminal',           extension_id_label(8197));
checa('8199 = Leitor serial',       'Leitor serial (pass-through)', extension_id_label(8199));
checa('id desconhecido não inventa rótulo', 'Extensão 9999',        extension_id_label(9999));

echo "== extension_content_render() ==\n";
$render8197 = extension_content_render(8197, [8193 => 23.4]);
checa('8193 vira "Tensão externa" com unidade V', [['label' => 'Tensão externa', 'value' => '23.4 V']], $render8197);

$renderCarga = extension_content_render(8197, [4 => 1]);
checa('key 4 = 1 decodifica para "Carregando" (map)', [['label' => 'Status de carga', 'value' => 'Carregando']], $renderCarga);

$renderHdop = extension_content_render(8197, [7 => 12]);
checa('key 7 (HDOP) divide por 10: 12 -> 1.2', [['label' => 'HDOP', 'value' => '1.2']], $renderHdop);

$renderIccid = extension_content_render(8197, [5 => str_repeat('A', 100)]);
// mb_strimwidth($v, 0, 80, '…') limita a LARGURA TOTAL (conteúdo + reticência) a 80.
checa('key 5 (ICCID/BCD) trunca em 80 chars (largura total, com reticência), sem decodificar', 80, mb_strlen($renderIccid[0]['value']));

$renderChaveNova = extension_content_render(8197, [999 => 'x']);
checa('chave fora da tabela oficial não inventa rótulo', [['label' => 'Chave 999', 'value' => 'x']], $renderChaveNova);

printf("\n%d de %d verificações OK\n", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
