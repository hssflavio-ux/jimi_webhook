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
