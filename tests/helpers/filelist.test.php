<?php
/**
 * Leitura da lista de gravações do cartão (FILELIST) — fixada no corpo REAL.
 *
 * Os trechos abaixo saíram da captura de produção
 * `logs/filelist/864993060392306_20260820_013707.body.raw` (400AD_3, 20/08/2026,
 * 78.590 bytes, 3.021 nomes). Nada aqui é sintético: os intervalos estranhos
 * (18 s, 14 s), a ordem embaralhada dos canais e a vírgula final são o que a
 * câmera manda de verdade.
 *
 * O que este teste protege:
 *
 *   🔴 O FUSO. O carimbo do nome é hora LOCAL da câmera (UTC−3), e essa é a
 *      única parte desta leitura que falha em SILÊNCIO: com a conversão errada
 *      a lista aparece na tela com datas plausíveis, três horas fora do lugar.
 *      O valor esperado foi medido contra os anexos de alarme das três câmeras
 *      JIMI (mesmo carimbo no nome, `alarms.alarm_time` conhecido, offset +3 h
 *      em 29 de 29 amostras).
 *
 *   • A vírgula final, que produz um nome vazio — e que NÃO é erro de formato.
 *   • O bloco de um minuto, e o corte quando o seguinte começa antes.
 *   • O `HVIDEO` montado a partir do nome: é a string do próprio equipamento
 *      devolvida a ele, sem conversão pelo meio.
 *
 * Uso (não precisa de banco):
 *   php tests/helpers/filelist.test.php
 */

require_once __DIR__ . '/../../includes/filelist.php';
require_once __DIR__ . '/../../includes/functions.php';   // filelist_url_base()

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-62s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

// ── Fixture: início real da lista (canais intercalados, blocos de 60 s) ─────
$inicio = '2026_08_16_05_33_58_01.ts,2026_08_16_05_34_58_02.ts,2026_08_16_05_34_58_01.ts,'
        . '2026_08_16_05_35_58_02.ts,2026_08_16_05_35_58_01.ts,2026_08_16_05_36_58_02.ts,'
        . '2026_08_16_05_36_58_01.ts';

// ── Fixture: trecho real com blocos CURTOS (18 s e 14 s) ────────────────────
$curtos = '2026_08_17_11_03_22_01.ts,2026_08_17_11_03_40_01.ts,2026_08_17_11_03_54_01.ts,'
        . '2026_08_17_11_04_12_01.ts';

// ── Fixture: fim real da lista — repare na VÍRGULA FINAL ────────────────────
$fim = '2026_08_19_22_05_46_02.ts,2026_08_19_22_05_46_01.ts,'
     . '2026_08_19_22_06_46_01.ts,2026_08_19_22_06_46_02.ts,';

$corpoReal = '{"imei":"864993060392306","fileNameList":"' . $inicio . ',' . $fim . '"}';

echo "── formato do corpo\n";
$r = filelist_parse($corpoReal);
checa('reconhece o envelope JSON', 'json', $r['formato']);
checa('lê o IMEI do corpo', '864993060392306', $r['imei']);
checa('a vírgula final vira UM nome vazio', 1, $r['vazios']);
checa('vazio final não conta como nome', 11, $r['total_nomes']);
checa('nenhum nome real é descartado', 11, $r['validos']);
checa('nada fora do padrão', 0, count($r['invalidos']));

echo "\n── 🔴 fuso: o carimbo do nome é hora LOCAL da câmera (UTC−3)\n";
checa('05:33:58 no cartão = 08:33:58 UTC', '2026-08-16 08:33:58', $r['entradas'][0]['start_utc']);
checa('nome preservado como veio', '2026_08_16_05_33_58_01.ts', $r['entradas'][0]['file_name']);
checa('sufixo _01 é o canal 1 (frontal)', 1, $r['entradas'][0]['channel']);
$ultima = end($r['entradas']);
checa('22:06:46 do dia 19 = 01:06:46 UTC do dia 20', '2026-08-20 01:06:46', $ultima['start_utc']);

// A virada de dia é onde uma conversão "por subtração de string" quebraria.
$virada = filelist_parse('{"imei":"1","fileNameList":"2026_08_18_23_59_36_01.ts"}');
checa('23:59:36 local vira 02:59:36 UTC do dia seguinte',
      '2026-08-19 02:59:36', $virada['entradas'][0]['start_utc']);

echo "\n── duração do bloco: um minuto (doc A010), cortado pelo bloco seguinte\n";
checa('bloco normal dura 60 s', '2026-08-16 08:34:58', $r['entradas'][0]['end_utc']);
$c = filelist_parse('{"imei":"1","fileNameList":"' . $curtos . '"}');
checa('bloco cortado em 18 s não invade o seguinte',
      '2026-08-17 14:03:40', $c['entradas'][0]['end_utc']);
checa('bloco cortado em 14 s idem', '2026-08-17 14:03:54', $c['entradas'][1]['end_utc']);
checa('o ÚLTIMO bloco vale o minuto cheio (não há seguinte que o desminta)',
      '2026-08-17 14:05:12', $c['entradas'][3]['end_utc']);

echo "\n── ordenação: a lista NÃO chega ordenada, e o fim depende da ordem\n";
// Os mesmos dois arquivos em ordem invertida têm de produzir o mesmo resultado.
$ordA = filelist_parse('{"imei":"1","fileNameList":"2026_08_16_10_00_00_01.ts,2026_08_16_10_00_30_01.ts"}');
$ordB = filelist_parse('{"imei":"1","fileNameList":"2026_08_16_10_00_30_01.ts,2026_08_16_10_00_00_01.ts"}');
checa('ordem invertida dá o mesmo fim do 1º bloco',
      $ordA['entradas'][0]['end_utc'], $ordB['entradas'][0]['end_utc']);
checa('e o fim é o início do seguinte (30 s)',
      '2026-08-16 13:00:30', $ordB['entradas'][0]['end_utc']);

echo "\n── canais são independentes: um NÃO corta o bloco do outro\n";
$dois = filelist_parse('{"imei":"1","fileNameList":"2026_08_16_10_00_00_01.ts,2026_08_16_10_00_20_02.ts"}');
checa('o bloco do canal 1 continua com 60 s', '2026-08-16 13:01:00', $dois['entradas'][0]['end_utc']);

echo "\n── nomes que NÃO são gravação do cartão\n";
checa('hora 24 é recusada (a própria doc traz 2020_01_01_24_05_06)',
      null, filelist_ler_nome('2020_01_01_24_05_06_1.ts'));
checa('31 de fevereiro é recusado', null, filelist_ler_nome('2026_02_31_10_00_00_01.ts'));
checa('sem o sufixo de canal é recusado', null, filelist_ler_nome('2026_08_16_05_33_58.ts'));
checa('canal 0 é recusado', null, filelist_ler_nome('2026_08_16_05_33_58_00.ts'));
checa('nome vazio é recusado', null, filelist_ler_nome(''));
checa('anexo de evento não é entrada de lista',
      null, filelist_ler_nome('EVENT_864993060392306_00000000_2026_08_20_08_47_42_I_15.ts'));

$sujo = filelist_parse('{"imei":"1","fileNameList":"lixo.txt,2026_08_16_05_33_58_01.ts"}');
checa('nome estranho não derruba a lista', 1, $sujo['validos']);
checa('e fica registrado para diagnóstico', ['lixo.txt'], $sujo['invalidos']);

echo "\n── corpo em formatos que não são o medido\n";
checa('corpo vazio não explode', 0, filelist_parse('')['validos']);
checa('corpo vazio é rotulado', 'vazio', filelist_parse('')['formato']);
checa('JSON sem a lista é rotulado', 'json_sem_lista', filelist_parse('{"imei":"1"}')['formato']);
$txt = filelist_parse("2026_08_16_05_33_58_01.ts\n2026_08_16_05_34_58_01.ts\n");
checa('TXT cru (formato antigo suposto) ainda é lido', 2, $txt['validos']);
checa('e é rotulado como texto', 'texto', $txt['formato']);
$arr = filelist_parse('{"imei":"1","fileNameList":["2026_08_16_05_33_58_01.ts"]}');
checa('lista como array JSON também é lida', 1, $arr['validos']);

echo "\n── corpo patológico não vira array de milhões de elementos\n";
// O endpoint é aberto (a câmera não autentica) e aceita 8 MB de corpo. Sem o
// `limit` do preg_split, um corpo só de vírgulas viraria o array ANTES de
// qualquer validação. Com ele o excedente fica no último elemento e morre como
// nome inválido.
$patologico = filelist_parse('{"imei":"1","fileNameList":"' . str_repeat(',', 60000) . '"}');
checa('nenhum nome válido sai de um corpo só de vírgulas', 0, $patologico['validos']);
checa('o corte respeita o teto', true, $patologico['vazios'] <= FILELIST_MAX_NOMES + 1);

echo "\n── nome repetido na mesma lista não vira duas gravações\n";
$rep = filelist_parse('{"imei":"1","fileNameList":"2026_08_16_05_33_58_01.ts,2026_08_16_05_33_58_01.ts"}');
checa('colapsa em uma entrada', 1, $rep['validos']);

echo "\n── sessões: 3.021 blocos de 1 min são 47 vezes que o veículo rodou\n";
// Trecho REAL do início da lista: 27 blocos contíguos, depois um buraco de
// 1 h 11 min (a 400AD_3 parou às 05:59 e voltou às 07:11, hora local).
$corrida = filelist_parse('{"imei":"1","fileNameList":"'
    . '2026_08_16_05_57_58_01.ts,2026_08_16_05_58_58_01.ts,2026_08_16_05_59_58_01.ts,'
    . '2026_08_16_07_11_12_01.ts,2026_08_16_07_12_03_01.ts"}');
$ses = filelist_sessoes($corrida['entradas']);
checa('o buraco parte em duas sessões', 2, count($ses[1]));
checa('a primeira termina no fim do último bloco dela',
      '2026-08-16 09:00:58', $ses[1][0]['fim']);
checa('e conta os blocos que a formaram', 3, $ses[1][0]['blocos']);
checa('a segunda começa no bloco depois do buraco',
      '2026-08-16 10:11:12', $ses[1][1]['inicio']);

// Blocos separados por MENOS que a folga continuam na mesma sessão — é o caso
// dos cortes de 14 s e 18 s que a câmera faz ao trocar de estado.
$semCorte = filelist_sessoes(filelist_parse('{"imei":"1","fileNameList":"' . $curtos . '"}')['entradas']);
checa('corte curto NÃO abre sessão nova', 1, count($semCorte[1]));

echo "\n── sessões: um canal não contamina o outro\n";
$doisCanais = filelist_sessoes(filelist_parse(
    '{"imei":"1","fileNameList":"2026_08_16_10_00_00_01.ts,2026_08_16_18_00_00_02.ts"}')['entradas']);
checa('cada canal tem a sua', [1, 1], [count($doisCanais[1]), count($doisCanais[2])]);
checa('e nas horas certas', '2026-08-16 21:00:00', $doisCanais[2][0]['inicio']);

echo "\n── sessões: linha crua de resource_lists é aceita igual\n";
// A tela passa as colunas do banco direto; exigir conversão no chamador só
// criaria lugar para errar.
$doBanco = filelist_sessoes([
    ['channel_id' => 1, 'start_time' => '2026-08-16 08:33:58', 'end_time' => '2026-08-16 08:34:58'],
    ['channel_id' => 1, 'start_time' => '2026-08-16 08:34:58', 'end_time' => '2026-08-16 08:35:58'],
]);
checa('funde os dois blocos', 1, count($doBanco[1]));
checa('fim = fim do segundo', '2026-08-16 08:35:58', $doBanco[1][0]['fim']);
$semFim = filelist_sessoes([['channel_id' => 2, 'start_time' => '2026-08-16 08:33:58']]);
checa('sem end_time vale o bloco nominal de 60 s',
      '2026-08-16 08:34:58', $semFim[2][0]['fim']);
$fimTorto = filelist_sessoes([['channel_id' => 2, 'start_time' => '2026-08-16 08:33:58',
                              'end_time' => '2026-08-16 07:00:00']]);
checa('fim ANTES do início não encolhe a sessão para trás',
      '2026-08-16 08:34:58', $fimTorto[2][0]['fim']);

echo "\n── 🔴 endereço de upload: loopback nunca serve\n";
// `localhost` para a câmera é ELA MESMA. O fallback antigo devolvia isso, o
// device respondia FILELIST:OK! e o upload morria com `failed!` — medido em
// campo em 20/08/2026. Vazio obriga a tela a recusar, com motivo.
putenv('FILELIST_URL'); putenv('STREAM_URL');
putenv('VIDEO_INGEST_IP=localhost');
checa('localhost é recusado', '', filelist_url_base());
putenv('VIDEO_INGEST_IP=127.0.0.1');
checa('127.0.0.1 é recusado', '', filelist_url_base());
putenv('VIDEO_INGEST_IP');
checa('sem configuração nenhuma é recusado', '', filelist_url_base());
putenv('VIDEO_INGEST_IP=186.248.143.197');
checa('IP alcançável passa', 'http://186.248.143.197/filelist/', filelist_url_base());

echo "\n── HVIDEO: o carimbo volta ao equipamento como ele o mandou\n";
checa('canal 1 → parâmetro B = 1 (Front camera)',
      'HVIDEO,2026_08_16_05_33_58,1', filelist_hvideo_command('2026_08_16_05_33_58_01.ts'));
checa('canal 2 → parâmetro B = 2 (Inward camera)',
      'HVIDEO,2026_08_19_22_06_46,2', filelist_hvideo_command('2026_08_19_22_06_46_02.ts'));
// ⚠️ Sem conversão de fuso: mandar o horário UTC faria a câmera procurar um
// bloco três horas fora — e ela devolveria "não existe", não um erro de fuso.
checa('🔴 o comando NÃO leva a hora UTC', false,
      strpos((string)filelist_hvideo_command('2026_08_16_05_33_58_01.ts'), '08_33_58') !== false);
checa('canal fora de 1/2 não vira comando (param B só admite 1 ou 2)',
      null, filelist_hvideo_command('2026_08_16_05_33_58_03.ts'));
checa('nome de arquivo JT/T não vira comando',
      null, filelist_hvideo_command('869058070151343_A1B2C3D4E5F60718_11.mp4'));

echo "\n── carimbo dos ANEXOS de evento (mesmo relógio, outro formato de nome)\n";
// Medido em produção: este arquivo tem alarms.alarm_time = 2026-08-20 11:47:42.
checa('EVENT_…_2026_08_20_08_47_42_I_15.ts → 11:47:42 UTC',
      '2026-08-20 11:47:42',
      filelist_ts_do_nome_utc('EVENT_864993060392306_00000000_2026_08_20_08_47_42_I_15.ts'));
checa('nome da lista também é lido pelo helper',
      '2026-08-16 08:33:58', filelist_ts_do_nome_utc('2026_08_16_05_33_58_01.ts'));
checa('nome sem carimbo devolve null',
      null, filelist_ts_do_nome_utc('869058070151343_A1B2C3D4E5F60718_11.mp4'));

printf("\n%s — %d de %d checagens passaram\n",
    $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
