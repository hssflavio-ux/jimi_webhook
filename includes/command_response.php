<?php
/**
 * JIMI Webhook System — Leitura das respostas de comando v4.9.7
 *
 * A resposta que chega do IoT Hub é inútil para quem opera: vem embrulhada em
 * um envelope de gateway e o texto que importa está em inglês técnico
 * (`Device busy (previous command has not returned)`). Este arquivo desembrulha
 * e traduz para uma frase que diz **o que aconteceu e o que fazer**.
 *
 * ⚠️ `commands.status` NÃO é confiável como desfecho. Há linhas no homolog com
 * `status = 'executed'` e resposta `"request timeout"` — o status registra que o
 * callback CHEGOU, não que o device obedeceu. Por isso a tela passou a mostrar o
 * desfecho interpretado da resposta, e não o status cru.
 *
 * Formas de resposta observadas em produção:
 *   1. envelope: {"msg":"success","code":0,"data":{"_msg":"...","_code":"601"}}
 *   2. texto puro: "request timeout"
 *   3. texto com pares: "ext Battery:12.1V; GPRS:Link Up PROBE489 6a72..."
 */

/**
 * Desembrulha a resposta gravada e devolve o texto que interessa.
 *
 * 🔴 São DOIS textos, e confundi-los foi o defeito da tela até 15/08/2026:
 *   • `texto`    — o que o **gateway** diz sobre a entrega (`data._msg`), do
 *                  tipo "Command communication successful response" ou
 *                  "Device not online". Serve para classificar o desfecho.
 *   • `conteudo` — o que o **equipamento** respondeu (`data._content`): `OK!`,
 *                  o JSON dos parâmetros lidos, a linha de status com a tensão
 *                  da bateria. É a resposta que o operador foi buscar.
 *
 * Ler só `_msg` fazia a tela mostrar "Executado" e nada mais — o comando
 * aparecia entregue e a resposta simplesmente não existia na interface. Pior
 * nos casos em que `_msg` vem **`null`** (é o normal nos comandos de texto,
 * proNo 128): aí o `??` caía no `msg` do envelope e a tela exibia `success`,
 * um eco do gateway, enquanto o device tinha respondido `OK!`.
 *
 * ⚠️ `_content` vem como **string vazia** quando o device está offline (não
 * ausente — a chave existe). Por isso a checagem é de "não vazio", nunca `??`:
 * com `??` o vazio vence e a mensagem útil (`Device not online`, que está em
 * `_msg`) se perde. `commandstatus.php` tinha exatamente esse bug.
 *
 * @param string|null $payload Conteúdo de `commands.response_payload`
 * @returns array{texto:string, codigo:string, conteudo:string}
 */
function command_response_extract(?string $payload): array
{
    $vazio = ['texto' => '', 'codigo' => '', 'conteudo' => ''];

    $payload = trim((string)$payload);
    if ($payload === '') return $vazio;

    $j = json_decode($payload, true);
    if (!is_array($j)) {
        // Pode ser uma string JSON ("request timeout") ou texto cru
        return ['texto' => is_string($j) ? $j : $payload, 'codigo' => '', 'conteudo' => ''];
    }

    // Envelope do gateway: o status da entrega está em data._msg / data._code,
    // e a resposta do equipamento em data._content.
    $d = $j['data'] ?? null;
    $conteudo = '';
    if (is_array($d)) {
        foreach (['_content', 'resultContent', 'content'] as $k) {
            if (isset($d[$k]) && is_scalar($d[$k]) && trim((string)$d[$k]) !== '') {
                $conteudo = trim((string)$d[$k]);
                break;
            }
        }
        $texto = trim((string)($d['_msg'] ?? ''));
        $cod   = (string)($d['_code'] ?? '');
        if ($texto !== '' || $conteudo !== '') {
            return ['texto' => $texto, 'codigo' => $cod, 'conteudo' => $conteudo];
        }
    }

    foreach (['resultContent', 'content', 'msg', 'message'] as $k) {
        if (!empty($j[$k]) && is_string($j[$k])) {
            return ['texto' => $j[$k], 'codigo' => (string)($j['code'] ?? ''), 'conteudo' => $conteudo];
        }
    }
    return ['texto' => json_encode($j, JSON_UNESCAPED_UNICODE),
            'codigo' => (string)($j['code'] ?? ''), 'conteudo' => $conteudo];
}

/**
 * Traduz a resposta para desfecho legível.
 *
 * Casar por trecho, não por igualdade: o gateway varia a frase entre versões
 * ("Command communication successful response" vs "... ack successful response")
 * e uma tabela de igualdade exata envelheceria em silêncio, voltando a mostrar
 * inglês cru — que é o defeito que esta função existe para corrigir.
 *
 * A palavra do EQUIPAMENTO (`$conteudo`) é classificada primeiro, e a do
 * gateway (`$texto`) só entra quando o device não disse nada reconhecível.
 * Sem essa ordem, um `_content` dizendo `Device busy` com o envelope em
 * `success` era anunciado como **Executado** — a tela afirmando o oposto do
 * que o equipamento respondeu. O `detalhe` mostrado é sempre a resposta do
 * device quando ela existe; o ACK do gateway não interessa a quem opera.
 *
 * @param string $texto    Status da entrega, vindo de command_response_extract()
 * @param string $codigo   Código do gateway, quando houver
 * @param string $conteudo Resposta do equipamento (`data._content`)
 * @returns array{nivel:string, titulo:string, detalhe:string, dica:string}
 *          nivel: ok | aguardando | erro | neutro
 */
function command_response_interpret(string $texto, string $codigo = '', string $conteudo = ''): array
{
    $conteudo = trim($conteudo);
    $detalhe  = $conteudo !== '' ? $conteudo : trim($texto);

    if ($detalhe === '') {
        return ['nivel' => 'aguardando', 'titulo' => 'Sem resposta ainda',
                'detalhe' => '', 'dica' => 'O equipamento ainda não respondeu. Comando offline fica em fila até ele se conectar.'];
    }

    $regras = [
        ['successful response|success|ack successful|\bok\b', 'ok', 'Executado',
         'O equipamento confirmou o comando.'],
        ['device busy|previous command has not returned', 'aguardando', 'Equipamento ocupado',
         'Há um comando anterior sem resposta. Espere ele terminar e reenvie — mandar de novo agora costuma receber a mesma recusa.'],
        // 🔴 `Time Out!` (com espaço) é o EQUIPAMENTO recusando, não o gateway.
        // A regra abaixo procurava `timeout` colado e nunca casava com a forma
        // que o JC371 usa — a resposta caía no balde `neutro` ("Resposta do
        // equipamento") e chegava à tela em estilo de DADO. É a mesma família
        // do defeito que a v4.9.20 corrigiu: recusa que parece execução.
        //
        // Medido em 16/08/2026: o JC371 devolve `Time Out!` exatamente para os
        // comandos que ele não implementa (SPEED, FATIGUE, ACCREP, TIMER1) —
        // daí a dica apontar para a lista por modelo. Fica ancorado no início
        // para não engolir o `request timeout` do gateway, que é outra coisa e
        // tem regra própria logo abaixo.
        ['^time\s*out', 'erro', 'Equipamento não atendeu o comando',
         'O equipamento respondeu, mas recusando: é assim que a linha JC371 devolve comando que ela não implementa. Confira a lista por modelo antes de reenviar.'],
        ['request timeout|failed to respond|timeout', 'erro', 'Sem resposta no prazo',
         'O equipamento não respondeu a tempo. Confira se ele está transmitindo; comando enviado com o device offline não é executado.'],
        ['media resource is empty|resource is empty', 'neutro', 'Nenhuma gravação no período',
         'O comando funcionou; o cartão não tem vídeo no intervalo pedido. Tente outro horário.'],
        // `not recognized` é o quarto dialeto de "não suportado". São quatro,
        // um por firmware, e conhecer só dois deixava metade das recusas
        // passando como dado: `Not support!` (JC181) e `instruction error!`
        // (JC182) casavam; `Time Out!` (JC371) e `<CMD#>Command was not
        // recognized!` (JC371 JMBS) não.
        ['not support|unsupported|invalid command|unknown command|not recognized', 'erro', 'Comando não suportado',
         'Este modelo não aceita o comando. Confira a lista por modelo — a tela só libera o que a documentação registra para o equipamento escolhido.'],
        ['param|parameter.*(error|invalid)|invalid param', 'erro', 'Parâmetro inválido',
         'A sintaxe foi recusada. Confira o formato aceito de cada parâmetro no painel do comando.'],
        ['offline|not online', 'aguardando', 'Equipamento offline',
         'O comando fica na fila e é entregue quando o equipamento voltar a se conectar.'],
        ['fail|error|refus', 'erro', 'Recusado pelo equipamento', ''],
    ];

    // Ordem deliberada: primeiro o que o EQUIPAMENTO disse, depois o gateway.
    //
    // ⚠️ As regras acima são frases em prosa e só valem sobre prosa. Resposta
    // estruturada (`{...}` / `[...]`) é PAYLOAD, não recado: o retorno de uma
    // consulta de parâmetros começa com `{"paramCount":...` e a regra de
    // parâmetro casaria em `param`, anunciando "Parâmetro inválido" para uma
    // leitura que deu certo. Payload não é classificado — quem classifica
    // nesse caso é o gateway, e o payload aparece inteiro no detalhe.
    $candidatos = [];
    if ($conteudo !== '' && $conteudo[0] !== '{' && $conteudo[0] !== '[') $candidatos[] = $conteudo;
    if (trim($texto) !== '') $candidatos[] = trim($texto);

    foreach ($candidatos as $cand) {
        $t = mb_strtolower($cand);
        foreach ($regras as [$re, $nivel, $titulo, $dica]) {
            if (preg_match('/' . $re . '/i', $t)) {
                return ['nivel' => $nivel, 'titulo' => $titulo, 'detalhe' => $detalhe, 'dica' => $dica];
            }
        }
    }

    // Resposta de dados do próprio device (ex.: `ext Battery:12.1V; GPRS:Link Up`)
    if (command_response_kv($detalhe)) {
        return ['nivel' => 'ok', 'titulo' => 'Dados recebidos', 'detalhe' => $detalhe, 'dica' => ''];
    }

    return ['nivel' => 'neutro', 'titulo' => 'Resposta do equipamento',
            'detalhe' => $detalhe . ($codigo !== '' ? " (código $codigo)" : ''), 'dica' => ''];
}

/**
 * Quebra respostas do tipo `Battery:12.1V; GPRS:Link Up` em pares legíveis.
 *
 * O device responde consulta de status assim, numa linha só — a tela mostrava a
 * linha inteira truncada, e o operador não conseguia ler a tensão da bateria
 * sem passar o mouse por cima.
 *
 * ⚠️ A CHAVE NEM SEMPRE É UMA PALAVRA (v4.9.40). O `CHECK#` — a consulta mais
 * completa do proNo 128 — devolve rótulos que a versão anterior desta função
 * descartava calada, e o operador via a linha faltando sem nenhum indício de
 * por quê. Medido em produção em 20/08/2026:
 *
 *   JC371  `EVENTSET,AVD:OFF`  `EVENTSET,AEPLD:ON,115,120,10`  `WAKEUP,RTC:0,240`
 *   JC182  `[AR9150]:C182_0_3_STD_JM_JC182_V2.1.0.0b_260422.0116`
 *
 * Daí a vírgula e os colchetes na classe da chave. Duas coisas continuam de
 * fora, e de propósito: a chave NUNCA atravessa um `:` (é o que mantém
 * `RSERVICE:rtmp://ip:1936/live` com a chave certa mesmo com três deles na
 * linha), e o bloco `bootcase[...]` do JC182, que tem quebra de linha no meio,
 * segue sem casar — é despejo de diagnóstico, não par, e fica melhor cru.
 *
 * @param string $texto
 * @returns array<string,string> Vazio quando não é resposta de pares
 */
function command_response_kv(string $texto): array
{
    $t = preg_replace('/^\s*ext\s+/i', '', trim($texto));
    $out = [];
    foreach (preg_split('/\s*;\s*/', $t) as $parte) {
        if (!preg_match('/^\s*([A-Za-z\[][A-Za-z0-9 _\/\.,\[\]-]{1,28})\s*:\s*(.+?)\s*$/', $parte, $m)) continue;
        $chave = trim($m[1]);
        $valor = trim($m[2]);
        if ($valor === '') continue;
        // Chave com espaço E vírgula é frase, não rótulo — nenhuma das medidas
        // tem as duas coisas (`EVENTSET,AVD` tem vírgula, `Local time` tem
        // espaço). Sem esta linha, "Device busy, previous command: not
        // returned" viraria um par, que é o preço de aceitar vírgula na chave.
        if (strpos($chave, ' ') !== false && strpos($chave, ',') !== false) continue;
        $out[$chave] = $valor;
    }
    // Um par só, com valor longo, quase sempre é frase comum com dois-pontos
    if (count($out) === 1 && mb_strlen(reset($out)) > 40) return [];
    return $out;
}

/**
 * Nome legível do comando a partir do que foi enviado.
 *
 * Para texto (proNo 128) casa contra o catálogo pelo nome do comando; para JT/T
 * usa o proNo. Sem isto o histórico mostrava o JSON cru do payload, que numa
 * coluna de 180 px não diz nada.
 *
 * @param string $conteudo Conteúdo enviado (texto ou JSON)
 * @param int|null $proNo
 * @returns string
 */
function command_label(string $conteudo, ?int $proNo = null): string
{
    static $catalogo = null;
    static $porCmd   = null;
    if ($catalogo === null) {
        $catalogo = @require __DIR__ . '/command_catalog.php';
        if (!is_array($catalogo)) $catalogo = [];
        $porCmd = [];
        foreach ($catalogo as $syn => $d) {
            $porCmd[$d['cmd']] = $porCmd[$d['cmd']] ?? $d['nome'];
        }
    }

    $conteudo = trim($conteudo);

    // JT/T: payload JSON → nome pelo proNo
    $jt = [
        37121 => 'Vídeo ao vivo',      37377 => 'Playback',
        37381 => 'Lista de gravações', 37382 => 'Upload FTP',
        37384 => 'Anexo do alarme',    33283 => 'Confirmar alarme',
        33536 => 'TTS (voz)',          34817 => 'Foto',
        33027 => 'Definir parâmetro',  33028 => 'Consultar parâmetros',
        33031 => 'Info do dispositivo',
    ];
    if ($proNo !== null && $proNo !== 128 && isset($jt[$proNo])) return $jt[$proNo];

    if ($conteudo !== '' && $conteudo[0] === '{') {
        return $proNo ? ('Comando JT/T ' . $proNo) : 'Comando JT/T';
    }

    // Texto: primeiro token antes de vírgula ou #
    // `mb_strtoupper`, não `strtoupper`: o segundo é byte a byte e deixa o
    // acento para trás — o rótulo saía "CONSULTA DE PARâMETROS", com um único
    // caractere minúsculo no meio. Passou despercebido enquanto isso só
    // aparecia numa coluna estreita da tela; agora sai no Excel e no PDF que
    // circulam fora dela.
    $base = mb_strtoupper(preg_split('/[,#]/', $conteudo)[0] ?? '', 'UTF-8');
    if ($base !== '' && isset($porCmd[$base])) return $porCmd[$base];
    return $base !== '' ? $base : $conteudo;
}
