<?php
/**
 * Leitura da resposta de comando — fixada nos payloads REAIS de produção.
 *
 * As três respostas abaixo foram capturadas de `commands.response_payload` em
 * produção (15/08/2026, comandos #178, #179 e #180). Elas existem aqui porque
 * a tela de comandos mostrava "Executado" e nunca o que o equipamento
 * respondeu: o texto do device vem em **`data._content`**, e o extrator lia
 * só `data._msg` — que nesses casos é `null` ou é apenas o ACK do gateway.
 *
 * O que este teste protege: que a resposta do EQUIPAMENTO chegue à tela, sem
 * perder a classificação do desfecho, que vem do gateway.
 *
 * Uso (não precisa de banco):
 *   php tests/helpers/command_response.test.php
 */

require_once __DIR__ . '/../../includes/command_response.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-56s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

// ── Fixture #178: FILELIST — o gateway não diz nada (`_msg` null) e a resposta
//    do equipamento é literalmente "OK!" em `_content` ───────────────────────
$f178 = '{"msg":"success","code":0,"data":{"_msg":null,"_code":"100",'
      . '"_imei":"862798051583785","_content":"OK!","_serverFlagId":1}}';

// ── Fixture #179: consulta de parâmetros — `_msg` traz só o ACK do gateway e a
//    resposta de verdade (os parâmetros lidos) está em `_content` ────────────
$f179 = '{"msg":"success","code":0,"data":{"_msg":"Command communication successful response",'
      . '"_code":"100","_imei":"860112070347838","_proNo":260,'
      . '"_content":"{\"paramCount\":\"6\",\"1\":\"180\",\"19\":\"186.248.143.197\",\"24\":\"21122\"}",'
      . '"_serverFlagId":0}}';

// ── Fixture #180: device offline — `_content` vem VAZIO e o que interessa está
//    em `_msg`. É o caso que proíbe "preferir _content" sem checar se tem algo.
$f180 = '{"msg":"The device is offline or timed out, and the command is converted to an offline command",'
      . '"code":0,"data":{"_msg":"Device not online","_code":"300","_imei":"869058070151343",'
      . '"_type":"normallns","_proNo":33028,"_content":"","_language":"zh","_time_out":60,'
      . '"_serverFlagId":0,"_route_client_time":1786752551418}}';

echo "── extração do conteúdo do equipamento (data._content)\n";
$e178 = command_response_extract($f178);
checa('#178 FILELIST devolve o "OK!" do equipamento', 'OK!', $e178['conteudo']);

$e179 = command_response_extract($f179);
checa('#179 devolve os parâmetros lidos, não o ACK',
      '{"paramCount":"6","1":"180","19":"186.248.143.197","24":"21122"}', $e179['conteudo']);
checa('#179 preserva o texto do gateway em `texto`',
      'Command communication successful response', $e179['texto']);

$e180 = command_response_extract($f180);
checa('#180 offline: `_content` vazio não vira conteúdo', '', $e180['conteudo']);
checa('#180 offline: o texto útil continua vindo de `_msg`', 'Device not online', $e180['texto']);

echo "\n── desfecho: classifica pela palavra do equipamento, cai no gateway\n";
$d178 = command_response_interpret($e178['texto'], $e178['codigo'], $e178['conteudo']);
checa('#178 desfecho é Executado', 'Executado', $d178['titulo']);
checa('#178 detalhe mostra a resposta do equipamento', 'OK!', $d178['detalhe']);

$d179 = command_response_interpret($e179['texto'], $e179['codigo'], $e179['conteudo']);
checa('#179 desfecho é Executado (ACK do gateway)', 'Executado', $d179['titulo']);
checa('#179 detalhe mostra os parâmetros lidos',
      '{"paramCount":"6","1":"180","19":"186.248.143.197","24":"21122"}', $d179['detalhe']);

$d180 = command_response_interpret($e180['texto'], $e180['codigo'], $e180['conteudo']);
checa('#180 desfecho é Equipamento offline', 'Equipamento offline', $d180['titulo']);

// Erro relatado pelo PRÓPRIO equipamento, com o gateway dizendo "success":
// a palavra do device tem de vencer, senão a tela diz "Executado" para um
// comando que falhou no equipamento.
$fErr = '{"msg":"success","code":0,"data":{"_msg":null,"_code":"100","_content":"Device busy"}}';
$eErr = command_response_extract($fErr);
$dErr = command_response_interpret($eErr['texto'], $eErr['codigo'], $eErr['conteudo']);
checa('device diz "Device busy" com gateway em success', 'Equipamento ocupado', $dErr['titulo']);

echo "\n── regressões: formas antigas continuam lidas\n";
$eTxt = command_response_extract('request timeout');
checa('texto cru continua sendo lido', 'request timeout', $eTxt['texto']);
checa('texto cru não inventa conteúdo de device', '', $eTxt['conteudo']);

$eKv = command_response_extract('"ext Battery:12.1V; GPRS:Link Up"');
$dKv = command_response_interpret($eKv['texto'], $eKv['codigo'], $eKv['conteudo']);
checa('resposta de status vira "Dados recebidos"', 'Dados recebidos', $dKv['titulo']);
checa('pares chave:valor continuam quebrados',
      ['Battery' => '12.1V', 'GPRS' => 'Link Up'], command_response_kv($dKv['detalhe']));

$eVazio = command_response_extract(null);
checa('payload nulo devolve vazio', '', $eVazio['texto']);
checa('payload nulo devolve conteúdo vazio', '', $eVazio['conteudo']);

// ── Os QUATRO dialetos de "não suportado" (v4.9.25) ────────────────────────
//
// 🔴 Cada firmware da linha JC recusa com uma frase diferente, e o
// classificador conhecia só duas. As outras duas caíam em `neutro`
// ("Resposta do equipamento") e chegavam à tela em estilo de DADO — a mesma
// família do defeito que a v4.9.20 corrigiu, e do jeito mais enganoso: o
// operador lê "Resposta do equipamento: Time Out!" e conclui que o comando
// rodou. Medidos em câmera real, 16/08/2026.
//
// O `Time Out!` do EQUIPAMENTO precisa continuar distinto do `request timeout`
// do GATEWAY: são causas diferentes e a dica que o operador precisa é outra.
echo "\n── Dialetos de recusa (medidos em 4 câmeras, 16/08/2026) ──\n";
$dialetos = [
    ['Not support!',                        'JC181',        'erro', 'Comando não suportado'],
    ['instruction error!',                  'JC182',        'erro', 'Recusado pelo equipamento'],
    ['Time Out!',                           'JC371',        'erro', 'Equipamento não atendeu o comando'],
    ['<SPEED#>Command was not recognized!', 'JC371 JMBS',   'erro', 'Comando não suportado'],
    ['Error:Number of parameters errors!',  'todos',        'erro', 'Parâmetro inválido'],
];
foreach ($dialetos as [$resp, $quem, $nivel, $titulo]) {
    $r = command_response_interpret('Command communication successful response', '100', $resp);
    checa("[$quem] " . substr($resp, 0, 30) . ' → nível', $nivel, $r['nivel']);
    checa("[$quem] " . substr($resp, 0, 30) . ' → título', $titulo, $r['titulo']);
}

// O timeout do GATEWAY não pode ser confundido com o do equipamento.
$rg = command_response_interpret('The device is offline or timed out', '600', 'request timeout');
checa('🔴 `request timeout` do gateway continua distinto', 'Sem resposta no prazo', $rg['titulo']);

// E resposta de CONSULTA não pode virar recusa: é o dado que fomos buscar.
foreach ([
    'Currently use APN:allcombl.br,allcom,allcom,IP' => 'APN# no JC371',
    'SPEED:OFF,0,110,10'                             => 'SPEED# no JC181',
    'MILE#,0'                                        => 'MILE# no JC182',
    'SERVER,0,186.248.143.197,21122,0'               => 'SERVER# no JC181',
] as $resp => $quem) {
    $r = command_response_interpret('Command communication successful response', '100', $resp);
    checa("[$quem] consulta é dado, não recusa", 'ok', $r['nivel']);
}

// ── Invariantes do catálogo: a forma de consulta (v4.9.25) ─────────────────
echo "\n── Catálogo: forma de consulta ──\n";
$cat = require __DIR__ . '/../../includes/command_catalog.php';

// 🔴 A trava que importa: comando DESTRUTIVO nunca pode ganhar um botão de
// "ler valor atual". `REBOOT#`, `FORMAT#` e `RESET#` têm forma nua — ela
// simplesmente não é uma pergunta. Um dia alguém regenera o catálogo da wiki
// por script e essa distinção some sozinha se não estiver travada aqui.
$destrutivos = ['REBOOT','RESTORE','RELAY','FORMAT','RESET','RESTART','UPDATE'];
$violam = [];
foreach ($cat as $syn => $d) {
    if (!empty($d['consulta']) && in_array(strtoupper($d['cmd']), $destrutivos, true)) $violam[] = $d['cmd'];
}
checa('🔴 nenhum comando destrutivo oferece consulta', [], array_values(array_unique($violam)));

// A consulta é sempre a forma NUA do próprio comando — nunca um setter.
$formaErrada = [];
foreach ($cat as $syn => $d) {
    if (empty($d['consulta'])) continue;
    if ($d['consulta'] !== strtoupper($d['cmd']) . '#') $formaErrada[] = $d['cmd'] . '=>' . $d['consulta'];
}
checa('consulta é sempre `CMD#`', [], $formaErrada);

// Um comando com várias sintaxes (a família EVENTSET) não pode oferecer N
// botões idênticos: só a primeira entrada carrega a consulta.
$porCmd = [];
foreach ($cat as $d) if (!empty($d['consulta'])) $porCmd[$d['cmd']] = ($porCmd[$d['cmd']] ?? 0) + 1;
checa('nenhuma consulta duplicada por comando', [], array_keys(array_filter($porCmd, fn($n) => $n > 1)));

// Toda consulta declara ONDE vale e DE ONDE veio — sem procedência, `medido`
// e `wiki` viram a mesma coisa, e não são.
$semProc = [];
foreach ($cat as $d) {
    if (empty($d['consulta'])) continue;
    if (empty($d['consulta_modelos']) || empty($d['consulta_ref'])) $semProc[] = $d['cmd'];
}
checa('toda consulta tem modelos e procedência', [], $semProc);

// Os quatro comandos que a sonda mediu respondendo têm de estar lá.
foreach (['APN','SERVER','TIMER','ANGLEREP'] as $c) {
    $tem = false;
    foreach ($cat as $d) if ($d['cmd'] === $c && !empty($d['consulta'])) $tem = true;
    checa("`$c#` catalogado como consulta", true, $tem);
}


// ── Invariantes vindos da planilha oficial JIMI (v4.9.27) ──────────────────
echo "\n── Catálogo: planilha JIMI JC400/JC261 ──\n";

// 🔴 `Picture` e `Video` são os DOIS comandos do proNo 128 em que a CAIXA
// importa: a planilha (A012/A013) avisa, nas duas linhas, "the 'P' need
// uppercase letter and others need Lowercase letters". Todo o resto do catálogo
// é maiúsculo, então um `strtoupper()` distraído — ou uma regeneração por
// script — normaliza estes dois junto, e o equipamento passa a recusar sem
// nenhum erro do nosso lado.
$caixaExata  = ['Picture', 'Video'];
$caixaErrada = [];
foreach ($cat as $syn => $d) {
    foreach ($caixaExata as $exato) {
        if (strcasecmp($d['cmd'], $exato) === 0 && $d['cmd'] !== $exato
            && array_intersect($d['modelos'], ['JC400AD', 'JC400D'])) {
            $caixaErrada[] = $syn . ' (cmd=' . $d['cmd'] . ', esperado ' . $exato . ')';
        }
    }
}
checa('🔴 Picture/Video preservam a caixa exata da planilha', [], $caixaErrada);

// A sintaxe gravada também não pode ter subido para maiúsculas.
$sintaxeErrada = [];
foreach ($cat as $syn => $d) {
    if (in_array($d['cmd'], $caixaExata, true) && strpos($syn, $d['cmd']) !== 0) {
        $sintaxeErrada[] = $syn;
    }
}
checa('🔴 a sintaxe começa pelo comando na caixa correta', [], $sintaxeErrada);

// 🔴 `FILELIST` precisa das DUAS formas, e é a lição mais cara desta rodada.
// `FILELIST,<A>` (A006) apenas CONFIGURA o endereço de destino; quem manda o
// equipamento subir a lista é a forma NUA `FILELIST` (A007). Enquanto o
// catálogo teve só a primeira, a tela só sabia configurar: dez comandos foram
// disparados em 17–18/08/2026 e nenhuma lista subiu. Com a forma nua, as três
// câmeras responderam e chamaram de volta em segundos.
$formasFilelist = [];
foreach ($cat as $syn => $d) {
    if (strcasecmp($d['cmd'], 'FILELIST') === 0) $formasFilelist[] = $syn;
}
sort($formasFilelist);
checa('🔴 FILELIST tem a forma de configurar E a de disparar',
      ['FILELIST', 'FILELIST,A#'], $formasFilelist);

// A família ADAS é o núcleo do produto e vive na JC400AD — a "JC261" da
// planilha. Faltava inteira até a v4.9.27 porque o nome de fábrica não bate com
// o nosso, e o cruzamento por nome de modelo não a alcançava.
$adas = [];
foreach ($cat as $syn => $d) {
    if (preg_match('/^ADAS(SW|SEP|PI|VI|SP|SEN|VSP)$/', $d['cmd'])) $adas[] = $d['cmd'];
}
sort($adas);
checa('família ADASxx presente para a JC400AD',
      ['ADASPI', 'ADASSEN', 'ADASSEP', 'ADASSP', 'ADASSW', 'ADASVI', 'ADASVSP'], $adas);



// 🔴 `RTMP` não leva duração. O `<C>` da planilha (A014) só existe em firmware
// V4.3+ e não governa o stream: o doc oficial de "pull live stream" manda
// `RTMP,ON,INOUT` e o stream cai sozinho ~20 s após o último leitor sair. Quem
// tem tempo é `Video,<câmera>,<segundos>` — CAPTURA de clipe. A v4.9.27 trocou
// os dois, e a tela de vídeo ao vivo depende dessa forma de 2 parâmetros.
$formasRtmp = [];
foreach ($cat as $syn => $d) {
    if (strcasecmp($d['cmd'], 'RTMP') === 0) $formasRtmp[] = $syn;
}
checa('🔴 RTMP é ON/OFF + câmera, sem duração', ['RTMP,A,B#'], $formasRtmp);

// `Video` continua com o tempo — é o par que se confundiu.
$videoTemTempo = false;
foreach ($cat as $syn => $d) {
    if ($d['cmd'] === 'Video' && count($d['params']) === 2) $videoTemTempo = true;
}
checa('Video mantém o parâmetro de duração', true, $videoTemTempo);


// ── Invariantes do firmware (v4.9.32) ──────────────────────────────────────
echo "\n── Catálogo: UPDATE e a leitura do firmware ──\n";
require_once __DIR__ . '/../../includes/firmware.php';

// 🔴 A trava do `UPDATE` em JC371 era artefato da FONTE, não do protocolo: só
// a página do JC371 documenta o comando, e a derivação "universal = 5+ das 6
// páginas" o deixava travado num modelo só. O comando vale para a linha JC
// inteira — o que muda é a URL do pacote. Uma regeneração do catálogo a partir
// da wiki desfaz isso em silêncio, e o sintoma é uma frota inteira que não
// pode ser atualizada pela tela. Daí o invariante.
$upd = null;
foreach ($cat as $syn => $d) if ($d['cmd'] === 'UPDATE') { $upd = $d; break; }
checa('UPDATE existe no catálogo', true, $upd !== null);
checa('🔴 UPDATE não trava a seleção por modelo', true, (bool)($upd['universal'] ?? false));
$modsUpd = $upd['modelos'] ?? [];
sort($modsUpd);
checa('UPDATE cobre a linha JC inteira',
      ['JC181', 'JC182', 'JC371', 'JC400AD', 'JC400D', 'JC450'], $modsUpd);
// P1 sem descrição deixava na tela um campo em branco que não dizia o que
// espera — e o que ele espera é a URL do pacote DO MODELO.
checa('UPDATE descreve o parâmetro P1', true,
      trim($upd['params'][0]['desc'] ?? '') !== '');

// A contagem do cabeçalho envelhecia em silêncio: dizia 219/143 quando o array
// já tinha 220/144. Comentário que descreve o arquivo é conferível — confira.
$distintos = [];
$universais = [];
foreach ($cat as $d) { $distintos[$d['cmd']] = 1; if ($d['universal']) $universais[$d['cmd']] = 1; }
preg_match('/Total: (\d+) entradas \/ (\d+) comandos distintos \((\d+) universais\)/',
           file_get_contents(__DIR__ . '/../../includes/command_catalog.php'), $m);
checa('cabeçalho: total de entradas confere', count($cat), (int)($m[1] ?? -1));
checa('cabeçalho: comandos distintos conferem', count($distintos), (int)($m[2] ?? -1));
checa('cabeçalho: universais conferem', count($universais), (int)($m[3] ?? -1));

// ── Leitura da versão de firmware ──────────────────────────────────────────
//
// O formato do retorno do `VERSION#` não é documentado em lugar nenhum — a
// wiki lista o comando, não a resposta. As formas abaixo cobrem o que se sabe:
// as versões observadas em produção (`V1.8.1.2_250904`, `V1.8.0.9_250807`) e o
// eco do comando que alguns firmwares prefixam.
foreach ([
    'V1.8.1.2_250904'                => 'V1.8.1.2_250904',
    '<VERSION#>V1.8.0.9_250807'      => 'V1.8.0.9_250807',
    'Version:V1.8.1.2_250904'        => 'V1.8.1.2_250904',
    'JC400AD_V1.8.1.2_250904'        => 'JC400AD_V1.8.1.2_250904',
    'Firmware: 4.3.2; Hardware: 1.0' => '4.3.2',
] as $resp => $esperado) {
    checa("VERSION# devolve versão em [$resp]", $esperado, firmware_parse_version($resp));
}
checa('resposta sem número de versão não vira firmware', null, firmware_parse_version('OK!'));
checa('eco puro do comando não vira firmware', null, firmware_parse_version('<VERSION#>'));

// 🔴 Recusa NÃO é versão. São quatro dialetos, um por firmware, e é a
// classificação do desfecho — não o formato — que barra os quatro.
foreach (['Time Out!', 'Not support!', 'instruction error!',
          '<VERSION#>Command was not recognized!'] as $recusa) {
    $r = command_response_interpret('', '', $recusa);
    checa("recusa [$recusa] classificada como erro", 'erro', $r['nivel']);
}

// ── A URL que vira `UPDATE,<url>#` ─────────────────────────────────────────
//
// 🔴 Vírgula e `#` são os separadores do proNo 128: uma URL que os contenha
// chega ao equipamento partida, e ele tenta baixar um pedaço de endereço.
checa('URL boa passa', null, firmware_url_problema('https://ota.exemplo.com/JC400AD_V1.8.1.2.bin'));
checa('URL vazia é recusada', true, firmware_url_problema('') !== null);
checa('🔴 URL com vírgula é recusada', true, firmware_url_problema('https://x.com/a,b.bin') !== null);
checa('🔴 URL com # é recusada', true, firmware_url_problema('https://x.com/a.bin#v2') !== null);
checa('URL sem esquema é recusada', true, firmware_url_problema('ota.exemplo.com/a.bin') !== null);
checa('URL com espaço é recusada', true, firmware_url_problema('https://x.com/a b.bin') !== null);
checa('comando montado', 'UPDATE,https://x.com/a.bin#', firmware_update_command('https://x.com/a.bin'));

// A captura só vale para o comando VERSION — `STATUS#` responde
// `Battery:12.1V`, que qualquer heurística de "parece versão" aceitaria.
checa('VERSION# é leitura de firmware', true, firmware_comando_le_versao('VERSION#', 128));
checa('VERSION sem # também', true, firmware_comando_le_versao('VERSION', 128));
checa('STATUS# não é leitura de firmware', false, firmware_comando_le_versao('STATUS#', 128));
checa('comando JT/T não é leitura de firmware', false, firmware_comando_le_versao('VERSION#', 33028));

// ⚠️ Comparação por IGUALDADE, nunca por ordem: não há regra publicada que
// ordene `V1.8.0.9_250807` contra `V4.3.2`.
checa('mesma versão', 'igual', firmware_situacao('V1.8.1.2', 'V1.8.1.2')['estado']);
checa('caixa diferente ainda é a mesma', 'igual', firmware_situacao('v1.8.1.2', 'V1.8.1.2')['estado']);
checa('versão diferente', 'diferente', firmware_situacao('V1.8.0.9', 'V1.8.1.2')['estado']);
checa('sem leitura do equipamento', 'sem_leitura', firmware_situacao(null, 'V1.8.1.2')['estado']);
checa('sem release de referência', 'sem_release', firmware_situacao('V1.8.1.2', null)['estado']);


// ── v4.9.40: o `CHECK#`, a consulta universal ──────────────────────────────
//
// 🔴 SEGUNDA exceção manual de `universal`, e pela mesma razão da primeira: a
// derivação ("presente em 5+ das 6 páginas da wiki") mede a FONTE, não o
// protocolo. Só a planilha do JC371 documenta o `CHECK`, e ele nasceria travado
// nesse modelo — mas foi medido em produção em 20/08/2026 respondendo em
// JC400AD (dois equipamentos), JC371 e JC182. Uma regeneração do catálogo por
// script desfaz isso em silêncio; é o que estas linhas impedem.
echo "\n── Catálogo: CHECK# e os comandos do JC371 ──\n";

$chk = $cat['CHECK#'] ?? null;
checa('CHECK# existe no catálogo', true, $chk !== null);
checa('🔴 CHECK# não trava a seleção por modelo', true, (bool)($chk['universal'] ?? false));
checa('CHECK# cobre os seis modelos', 6, count($chk['modelos'] ?? []));
checa('CHECK# é consulta de si mesmo', 'CHECK#', $chk['consulta'] ?? null);
checa('CHECK# declara procedência medida', true,
      strpos((string)($chk['consulta_ref'] ?? ''), 'medido') !== false);

// ⚠️ O `CHECKVIDEO#` é o CONTRÁRIO e a distinção é o motivo de a trava existir:
// mesma planilha, mesma família, e relatado do campo como recusado na linha
// JC400 — que a planilha `JC400 & JC261` de fato não lista. Marcá-lo universal
// junto com o `CHECK#` só porque os dois começam igual seria o erro.
checa('CHECKVIDEO# existe', true, isset($cat['CHECKVIDEO#']));
checa('🔴 CHECKVIDEO# fica travado no JC371', false, (bool)($cat['CHECKVIDEO#']['universal'] ?? true));
checa('CHECKVIDEO# só JC371', ['JC371'], array_values($cat['CHECKVIDEO#']['modelos'] ?? []));

// Os sete nomes que faltavam e as onze variantes de aridade, da planilha do
// JC371. Cruzamento por COMANDO:ARIDADE — comparar só o nome-base esconde
// variante faltante de comando que já existe (foi o caso do `FILELIST`).
$daPlanilha371 = [
    'CHECK#', 'CHECKVIDEO#', 'STATUSVIDEO#', 'SENSORSET,A,B,C,D#',
    'SHUTDOWNTIME,A#', 'VIDEORSL_SUB,A,B,C,D,E#', 'VIDETIMEZONE,A,B,C#',
    'KEYFUN,A,B#', 'APN,A,B,C,D#', 'SERVER,A,B,C,D,E,F#', 'BCD,A,B#',
    'LOG,ALL#', 'RECORDAUDIO,A,B#', 'RECORDAUDIO_SUB,A,B#',
    'RATATION,A,B,C,D#', 'PICTIMER,A,B,C,D#', 'TIMER,A#', 'ANGLEREP,A#',
];
$ausentes = array_values(array_filter($daPlanilha371, fn($k) => !isset($cat[$k])));
checa('as 18 sintaxes da planilha JC371 estão no catálogo', [], $ausentes);

// Toda entrada da planilha diz DE ONDE veio — sem isso, a próxima conferência
// não sabe distinguir o que foi medido do que foi suposto.
$semFonte = array_values(array_filter($daPlanilha371,
    fn($k) => trim((string)($cat[$k]['fonte'] ?? '')) === ''));
checa('toda entrada do JC371 declara a fonte', [], $semFonte);

// 🔴 A variante de aridade divide o nome com uma entrada mais antiga. Se
// carregasse `consulta`, a tela ofereceria dois botões idênticos de "consultar
// APN" — é o mesmo invariante já conferido acima, aqui ancorado nos nomes que
// ganharam uma segunda sintaxe.
$variantes = ['KEYFUN,A,B#', 'APN,A,B,C,D#', 'SERVER,A,B,C,D,E,F#', 'BCD,A,B#',
              'LOG,ALL#', 'RECORDAUDIO,A,B#', 'RECORDAUDIO_SUB,A,B#',
              'RATATION,A,B,C,D#', 'PICTIMER,A,B,C,D#', 'TIMER,A#', 'ANGLEREP,A#'];
$comConsulta = array_values(array_filter($variantes, fn($k) => !empty($cat[$k]['consulta'])));
checa('variante de aridade não duplica a consulta', [], $comConsulta);

// 🔴 E nenhuma delas é universal: mandar a sintaxe de um campo do `TIMER` para
// uma JC400 que espera dois é aceito e mal interpretado, sem erro nenhum. A
// trava por modelo é a única coisa entre o operador e esse silêncio.
$universalDemais = array_values(array_filter($variantes, fn($k) => !empty($cat[$k]['universal'])));
checa('🔴 variante de aridade fica presa ao JC371', [], $universalDemais);

// A contagem por categoria também é comentário, e envelhece igual à outra.
// Categoria fora do mapa de `handlers/comandos.php` cai no rótulo cru.
$porCategoria = [];
foreach ($cat as $d) $porCategoria[$d['categoria']] = ($porCategoria[$d['categoria']] ?? 0) + 1;
ksort($porCategoria);
$linha = [];
foreach ($porCategoria as $k => $n) $linha[] = "$k=$n";
preg_match('/Por categoria: ([^.]+)\./',
           file_get_contents(__DIR__ . '/../../includes/command_catalog.php'), $mc);
checa('cabeçalho: contagem por categoria confere', implode(', ', $linha), trim($mc[1] ?? ''));
$conhecidas = ['ia','video','rede','posicao','audio','energia','alarme','manutencao','outros'];
checa('nenhuma categoria fora do mapa da tela', [],
      array_values(array_diff(array_keys($porCategoria), $conhecidas)));

// ── O que o CHECK# devolve, medido em três modelos ─────────────────────────
//
// Respostas CRUAS (`data._content`) colhidas em produção em 20/08/2026. Não são
// exemplo inventado: cada uma já mostrou um formato que a leitura de pares
// descartava calada.
echo "\n── CHECK#: leitura da resposta medida ──\n";

$chk400 = 'VERSION:KMC28_0_0_STD_JM_C261_V1.8.0.9_250807.1920; C170VERSION:C170_MB_WABL_V2.4.2_240618142753; IMEI:864993060429173; ICCID:89550534010048097267; IMSI:724050109820064; COREKITSW:0; RSERVICE:rtmp://186.248.143.197:1936/live; UPLOAD:http://186.248.143.197:23010/upload; SERVER:0,186.248.143.197,21100; APN:ApnAllcom,allcombl.br,724,05; WIFIAP:ON,864993060429173,60429173; SSID:OFF; VOLUME:2; VOICESW:100; LED:ON; TIMEZONE:-3:00; TIMESYNC:gps';

$kv400 = command_response_kv($chk400);
checa('[JC400AD] a versão sai nomeada', 'KMC28_0_0_STD_JM_C261_V1.8.0.9_250807.1920', $kv400['VERSION'] ?? null);
// 🔑 O endereço de upload é o que falta conferir na 400D, que aceita o
// `FILELIST` e nunca sobe a lista. Nenhuma outra resposta do proNo 128 o diz.
checa('[JC400AD] o endereço de upload sai inteiro', 'http://186.248.143.197:23010/upload', $kv400['UPLOAD'] ?? null);
// A chave NUNCA atravessa um `:` — sem isso `rtmp://ip:1936/live` levaria
// pedaço do valor para dentro do rótulo.
checa('[JC400AD] três dois-pontos e a chave continua certa', 'rtmp://186.248.143.197:1936/live', $kv400['RSERVICE'] ?? null);
checa('[JC400AD] o fuso do equipamento', '-3:00', $kv400['TIMEZONE'] ?? null);
checa('[JC400AD] valor com vírgula fica inteiro', '0,186.248.143.197,21100', $kv400['SERVER'] ?? null);

$chk371 = 'VERSION:C371_0_0_STD_JM_JC371_V1.9.0.2b_260528.0543;MCUVER:C371_1_WAAO_V1.2.5_250120.2111;MODEMVER:EC200AAUHAR01A10M16_01.200.01.200;IMEI:865478070003241;ICCID:89550537010008815663;IMSI:724050103094927;SERVER:0,186.248.143.197,21122;BCD:0;APN:allcombl.br,allcom,allcom;INPUT1:1;INPUT2:1;RELAY1:0;RELAY2:0;EVENTSET,AVD:OFF;ACC:1,0;acc on type:0;EVENTSET,AEPLD:ON,115,120,10;WORKTIMEMAX:30 min;WAKEUP,RTC:0,240;BOOTREASON:4;';

$kv371 = command_response_kv($chk371);
// 🔴 Estas três eram descartadas em silêncio antes da v4.9.40: a chave tem
// VÍRGULA, e a classe de caracteres não a aceitava. O operador via a linha
// faltar sem nenhum indício de por quê.
checa('[JC371] chave com vírgula é lida', 'OFF', $kv371['EVENTSET,AVD'] ?? null);
checa('[JC371] chave com vírgula e valor com vírgula', 'ON,115,120,10', $kv371['EVENTSET,AEPLD'] ?? null);
checa('[JC371] WAKEUP,RTC', '0,240', $kv371['WAKEUP,RTC'] ?? null);
checa('[JC371] chave com espaço continua valendo', '0', $kv371['acc on type'] ?? null);
checa('[JC371] a porta do JC371 é outra', '0,186.248.143.197,21122', $kv371['SERVER'] ?? null);

// O JC182 responde com um bloco de diagnóstico QUEBRADO EM LINHA no meio
// (`bootcase[...]`). Ele não é par e não deve virar par — fica melhor cru.
$chk182 = "IMEI:869058070151343;VERSION:C182_WEBP_VY_1_V1.2.5.2_260422.0924;[AR9150]:C182_0_3_STD_JM_JC182_V2.1.0.0b_260422.0116;SERVER:0,186.248.143.197,21122,1;APN:allcombl.br,allcom,allcom;CSQ:[4G]20;BAT:3.85;EXTV:13.12;ACC:OFF,1,1;bootcase[Start_total:97 normal_power_on:0 Netfail_20_min:45\n];currcase:19:08-20 20:12:42(count:97)(reason:19);curboot:4;Local Timezone:W,3,0;";

$kv182 = command_response_kv($chk182);
checa('[JC182] rótulo entre colchetes é lido', 'C182_0_3_STD_JM_JC182_V2.1.0.0b_260422.0116', $kv182['[AR9150]'] ?? null);
checa('[JC182] valor entre colchetes também', '[4G]20', $kv182['CSQ'] ?? null);
checa('[JC182] o bloco quebrado em linha não vira par', false,
      (bool)array_filter(array_keys($kv182), fn($k) => strpos($k, 'bootcase') !== false));
checa('[JC182] o fuso, na grafia do JC182', 'W,3,0', $kv182['Local Timezone'] ?? null);

// ⚠️ O preço de aceitar vírgula na chave: uma frase comum com vírgula e
// dois-pontos passaria a virar par. Não passa.
checa('frase com vírgula não vira par', [],
      command_response_kv('Device busy, previous command: not returned'));

// ── A versão de firmware que o CHECK# traz de graça ────────────────────────
//
// 🔴 A ligação só é segura porque as DUAS leituras devolvem a MESMA string —
// medido nos dois modelos alcançáveis em 20/08/2026. `/firmwares` compara por
// IGUALDADE; grafias diferentes do mesmo firmware fariam a tela acusar
// "diferente da referência" conforme o comando usado por último.
echo "\n── CHECK# alimenta a leitura de firmware ──\n";

checa('CHECK# é leitura de firmware', true, firmware_comando_le_versao('CHECK#', 128));
checa('CHECK sem # também', true, firmware_comando_le_versao('CHECK', 128));
// ⚠️ `CHECKVIDEO#` NÃO é — e o primeiro token é o que separa os dois.
checa('CHECKVIDEO# não é leitura de firmware', false, firmware_comando_le_versao('CHECKVIDEO#', 128));

checa('[JC400AD] VERSION# e CHECK# dão a MESMA versão',
      firmware_parse_version('[VERSION]KMC28_0_0_STD_JM_C261_V1.8.0.9_250807.1920'),
      firmware_parse_version($chk400));
checa('[JC371] VERSION# e CHECK# dão a MESMA versão',
      firmware_parse_version('[VERSION]C371_0_0_STD_JM_JC371_V1.9.0.2b_260528.0543[MCU][MODEM]EC200AAUHAR01A10M16'),
      firmware_parse_version($chk371));
// ⚠️ No JC182 o `IMEI` vem ANTES da versão e o `[AR9150]` é outro firmware, do
// chip de rádio. Quem vence é o par que se chama VERSION, não a ordem.
checa('[JC182] a versão do equipamento, não a do rádio',
      'C182_WEBP_VY_1_V1.2.5.2_260422.0924', firmware_parse_version($chk182));
printf("\n%s — %d de %d checagens passaram\n",
    $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
