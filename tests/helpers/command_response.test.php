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

printf("\n%s — %d de %d checagens passaram\n",
    $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
