<?php
/**
 * Canal de SMS (Allcance) — normalização de número e leitura do webhook.
 *
 * As duas coisas que este teste protege são silenciosas quando quebram:
 *
 *  1. **O NÚMERO.** `sim_cards.msisdn` é texto livre e a API aceita QUALQUER
 *     string sem reclamar — cobra o crédito e a mensagem não chega. Não há
 *     erro em log nem na tela; o operador só descobre porque o equipamento
 *     nunca respondeu. Cada forma que existe no cadastro real está fixada aqui.
 *
 *  2. **O CASAMENTO DO RETORNO.** O webhook da Allcance é da CONTA INTEIRA, não
 *     da nossa aplicação. Item sem referência nossa tem de ser descartado —
 *     casar por número solto atribuiria a resposta de um SMS ao comando errado.
 *
 * Uso (não precisa de banco):
 *   php tests/helpers/sms_webhook.test.php
 */

require_once __DIR__ . '/../../includes/sms_gateway.php';
require_once __DIR__ . '/../../includes/sms_inbound.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-58s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

// ════════════════════════════════════════════════════════════════════════════
echo "\n── Normalização de MSISDN ──\n";
// O formato que a API aceita é DDD + assinante, SEM código de país — os
// exemplos da própria doc são `37999368807` e `3132311301`.

checa('já normalizado (móvel, 11 díg.)', '37999368807', sms_normalizar_msisdn('37999368807'));
checa('já normalizado (fixo, 10 díg.)',  '3132311301',  sms_normalizar_msisdn('3132311301'));
checa('com +55 e espaços',               '37999368807', sms_normalizar_msisdn('+55 37 99936-8807'));
checa('com 55 colado',                   '37999368807', sms_normalizar_msisdn('5537999368807'));
checa('com parênteses e hífen',          '37999368807', sms_normalizar_msisdn('(37) 99936-8807'));
checa('fixo com +55',                    '3132311301',  sms_normalizar_msisdn('+55 31 3231-1301'));
checa('com ponto e barra',               '37999368807', sms_normalizar_msisdn('37/99936.8807'));

// 🔴 A armadilha do "55": DDD 55 é Caxias do Sul. `5599999999` tem 10 dígitos
// e é um fixo do DDD 55 — cortar o prefixo deixaria 8 dígitos, que não é
// número válido. Só cortamos quando o RESTO fica com 10 ou 11.
checa('DDD 55 (Caxias) preservado',      '5599999999',  sms_normalizar_msisdn('5599999999'));
checa('DDD 55 móvel preservado',         '55999999999', sms_normalizar_msisdn('55999999999'));
// Com +55 explícito na frente do DDD 55, aí sim são 13 dígitos e o país sai.
checa('+55 na frente do DDD 55',         '55999999999', sms_normalizar_msisdn('+55 55 99999-9999'));

echo "\n  (recusas — o handler tem de reportar, não enviar)\n";
checa('vazio',                 null, sms_normalizar_msisdn(''));
checa('null',                  null, sms_normalizar_msisdn(null));
checa('só texto',              null, sms_normalizar_msisdn('sem numero'));
checa('curto demais',          null, sms_normalizar_msisdn('99936-8807'));
checa('longo demais',          null, sms_normalizar_msisdn('123456789012345'));
checa('DDD inválido (< 11)',   null, sms_normalizar_msisdn('0199999999'));
checa('ICCID no campo errado', null, sms_normalizar_msisdn('89550534120012345678'));

// ════════════════════════════════════════════════════════════════════════════
echo "\n── Leitura do payload do webhook ──\n";
// Payload real da doc: mistura uma RESPOSTA do destinatário (status "recebido"
// com `mensagem`) e um status de ENTREGA puro, no mesmo array.

$payload = json_decode('{
  "messages": [
    { "numero":"37999368807", "status":"recebido", "mensagem":"sim, tenho interesse",
      "data_envio":"2025-04-01 11:00:11", "data_entrega":null,
      "referencia_campanha":"", "referencia_numero":"" },
    { "data_entrega":"2025-04-05 16:42:12", "data_envio":"2025-04-05 16:35:33",
      "hash":null, "numero":"37999368807", "status":"entregue celular",
      "referencia_campanha":"EXEMPLO-ACOMPANHAMENTO",
      "referencia_numero":"EXEMPLO-REFERENCIA-NUMERO-2" }
  ],
  "total": 8
}', true);

$itens = sms_webhook_itens($payload);
checa('extrai os 2 itens de "messages"', 2, count($itens));

// Item 1: resposta do destinatário, mas SEM referência → tem de ser descartado.
$i0 = sms_classificar_item($payload['messages'][0]);
checa('[1] é resposta do equipamento',       true, $i0['e_resposta']);
checa('[1] texto da resposta preservado',    'sim, tenho interesse', $i0['resposta']);
checa('[1] sem referência → não casável',    null, $i0['referencia']);

// Item 2: status de entrega, com referência.
$i1 = sms_classificar_item($payload['messages'][1]);
checa('[2] NÃO é resposta',                  false, $i1['e_resposta']);
checa('[2] status cru, minúsculo',           'entregue celular', $i1['status']);
checa('[2] referência do número',            'EXEMPLO-REFERENCIA-NUMERO-2', $i1['referencia']);
checa('[2] data de entrega',                 '2025-04-05 16:42:12', $i1['entregue_em']);

echo "\n  (a distinção que dá valor ao canal)\n";
// 🔑 "recebido" SOZINHO é status de entrega (confirmação de recebimento).
// "recebido" COM `mensagem` é a RESPOSTA do equipamento ao comando. Tratar os
// dois igual faria a tela mostrar "Recebido" e jogar fora o que a câmera disse.
$so_status = sms_classificar_item(['numero'=>'37999368807','status'=>'recebido','referencia_numero'=>'abc']);
checa('"recebido" sem mensagem = só status', false, $so_status['e_resposta']);

$com_resp = sms_classificar_item(['numero'=>'37999368807','status'=>'recebido',
                                  'mensagem'=>'[STATUS] OK','referencia_numero'=>'abc']);
checa('"recebido" com mensagem = resposta',  true, $com_resp['e_resposta']);
checa('resposta do device preservada',       '[STATUS] OK', $com_resp['resposta']);

// Mensagem em branco (string vazia ou só espaço) não é resposta.
$vazia = sms_classificar_item(['status'=>'recebido','mensagem'=>'   ','referencia_numero'=>'abc']);
checa('mensagem só com espaços não é resposta', false, $vazia['e_resposta']);

echo "\n  (normalização do status)\n";
// A doc publica a tabela em Maiúsculas num lugar e minúsculas noutro. Gravamos
// sempre minúsculo, senão a mesma entrega vira dois valores distintos na coluna.
$maiusc = sms_classificar_item(['status'=>'Entregue Celular','referencia_numero'=>'abc']);
checa('status vem de "Entregue Celular" minúsculo', 'entregue celular', $maiusc['status']);

echo "\n  (envelope malformado — não pode estourar)\n";
checa('sem "messages"',        [], sms_webhook_itens(['total' => 0]));
checa('"messages" não-array',  [], sms_webhook_itens(['messages' => 'x']));
checa('payload vazio',         [], sms_webhook_itens([]));

// ════════════════════════════════════════════════════════════════════════════
echo "\n── Rótulo de exibição ──\n";
// A tradução é na EXIBIÇÃO; a coluna guarda o texto cru do provedor.
checa('entregue celular → ok',   'ok',    sms_status_label('entregue celular')['nivel']);
checa('lista negra → erro',      'erro',  sms_status_label('lista negra')['nivel']);
checa('enviado → aguardando',    'aguardando', sms_status_label('enviado')['nivel']);
checa('vazio → aguardando',      'aguardando', sms_status_label(null)['nivel']);
// Status novo do provedor não pode virar "—": tem de aparecer como veio.
checa('status desconhecido volta como veio', 'coisa nova', sms_status_label('coisa nova')['rotulo']);

// ════════════════════════════════════════════════════════════════════════════
echo "\n── Guarda de tamanho do SMS ──\n";
// Acima de 160 a operadora PARTE a mensagem e o equipamento recebe meio comando.
checa('comando típico cabe', true,  mb_strlen('SERVER,1,bycamera.ia.br,21100,0#') <= SMS_MAX_CHARS);
checa('limite é 160',        160,   SMS_MAX_CHARS);

// ════════════════════════════════════════════════════════════════════════════
echo "\n── Referência de correlação ──\n";
$r1 = sms_nova_referencia();
$r2 = sms_nova_referencia();
checa('32 hex',        32, strlen($r1));
checa('só hex',        1,  preg_match('/^[0-9a-f]{32}$/', $r1));
checa('duas diferem',  false, $r1 === $r2);
checa('cabe na coluna varchar(40)', true, strlen($r1) <= 40);

printf("\n%s — %d de %d checagens passaram\n",
    $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
