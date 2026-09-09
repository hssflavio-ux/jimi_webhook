<?php
/**
 * JIMI Webhook System — Despacho de comando ao IoT Hub (v4.9.13)
 *
 * Ponto ÚNICO da chamada HTTP ao `tracker-instruction-server` e da leitura da
 * resposta dele. Usado por:
 *
 *   - `handlers/sendcommand.php`        — comando pedido pela tela
 *   - `scripts/param_sync_worker.php`   — leitura automática de parâmetros
 *
 * Nasceu de uma extração, não de um começo do zero: o worker precisava do mesmo
 * despacho e copiá-lo seria repetir o erro que este repositório já pagou três
 * vezes — `scripts/worker.php` imprimindo código cru de alarme por meses porque
 * tinha uma cópia divergente da resolução de nome; o `alarm_label_sql()` só
 * virou ponto único depois disso.
 *
 * O que está aqui é o que é IGUAL para qualquer chamador: montar o payload,
 * chamar, e traduzir a resposta em desfecho. O que é específico da tela
 * (validação de proNo, escopo multi-tenant, injeção de credenciais de FTP)
 * continua em `sendcommand.php`, porque worker nenhum precisa disso.
 *
 * @package JimiWebhook
 */

require_once __DIR__ . '/../core/Logger.php';

/**
 * Envia um comando ao IoT Hub e interpreta a resposta.
 *
 * ⚠️ `serverFlagId` NÃO é chave de correlação aqui, ao contrário do que a doc
 * oficial define: nesta instalação ele é o SELETOR DE GATEWAY — 0 para JT/T
 * (porta 21122), 1 para JIMI (21100). Trocar isso mexe no despacho para veículo
 * real; ver a nota em `pushinstructresponse.php`.
 *
 * @param string      $imei
 * @param int         $proNo
 * @param string      $cmdContent  Já montado e canonicalizado pelo chamador
 * @param int         $serverFlagId 0 = JT/T, 1 = JIMI
 * @param string|null $origem      Prefixo do requestId ('dash', 'paramsync'…)
 * @returns array{
 *     status: string, raw: string|null, http_code: int, request_id: string,
 *     hub_code: mixed, hub_msg: string, content: string|null,
 *     device_code: string|null, result_msg: string, endpoint: string
 * }
 *   status: 'executed' (device respondeu) | 'sent' (fila offline) | 'failed'
 *
 * 🔴 CORRIGIDO (09/09/2026) — a nota que existia aqui dizia que o payload
 * precisava de `offlineFlag=true` para o hub cachear o comando offline, com
 * base na doc oficial (§1.16) e numa fila vazia medida em 07/09/2026. **O
 * teste decisivo do `docs/FILA_OFFLINE_COMANDOS.md` §2 (rodado em produção,
 * equipamento `865478070649936`, offline há 19 dias) derrubou essa hipótese:
 * o comando foi CACHEADO e apareceu em `queryOfflineInstruct` imediatamente
 * após o envio, SEM `offlineFlag` no payload — e mandar o flag não mudou nada
 * observável.** O payload aqui continua sem `offlineFlag` de propósito: não há
 * evidência de que ele faça diferença nesta instalação, e não vale correr o
 * risco que a doc descreve (fila entregue em massa na reconexão) por um
 * parâmetro sem efeito medido. A causa real dos 97 comandos presos em `sent`
 * sem aparecer na fila (medição de 07/09) é a OUTRA hipótese do documento:
 * a fila TEM validade curta (o `_time_out` que ela devolve) e expira muito
 * antes de um equipamento offline há semanas reconectar — não que nunca
 * tenha sido cacheada. Ver **`docs/FILA_OFFLINE_COMANDOS.md`** e
 * `iothub_query_offline_instruct()` logo abaixo, que fecha essa lacuna
 * consultando a fila em vez de supor o que aconteceu com o comando.
 */
function iothub_send_instruct(string $imei, int $proNo, string $cmdContent,
                              int $serverFlagId = 0, ?string $origem = 'dash'): array
{
    $url   = getenv('IOTHUB_COMMAND_URL') ?: 'http://localhost:10088/api/device/sendInstruct';
    $token = getenv('IOTHUB_API_TOKEN') ?: '123';

    $requestId = $origem . '_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);

    $postFields = http_build_query([
        'imei'         => $imei,
        'cmdContent'   => $cmdContent,
        'serverFlagId' => $serverFlagId,
        'proNo'        => $proNo,
        'platform'     => 'web',
        'requestId'    => $requestId,
        'cmdType'      => 'normallns',
        'token'        => $token,
    ]);

    // TIMEOUT 35s (era 15s): quando o device demora ou está offline, o
    // tracker-instruction-server SEGURA a resposta HTTP por até 30s
    // ("processSendInstruct await timeout") antes de dizer que o comando virou
    // fila offline. Com 15s o PHP abortava no meio da espera e o comando era
    // marcado "failed" mesmo tendo sido aceito e enfileirado.
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 35,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw       = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $out = [
        'status'      => 'failed',
        'raw'         => $raw ?: null,
        'http_code'   => $httpCode,
        'request_id'  => $requestId,
        'hub_code'    => -1,
        'hub_msg'     => 'Sem resposta',
        'content'     => null,
        'device_code' => null,
        'result_msg'  => 'Falha desconhecida',
        'endpoint'    => $url,
    ];

    if ($curlError || $httpCode === 0) {
        // Timeout ≠ inacessível: o IoTHub recebeu o comando mas não respondeu a
        // tempo (device lento). O comando pode ter sido enfileirado offline.
        $out['result_msg'] = ($curlErrno === CURLE_OPERATION_TIMEDOUT)
            ? 'IoTHub não respondeu a tempo — se o dispositivo estiver offline, o '
              . 'comando foi enfileirado e será entregue na reconexão. Detalhe: ' . $curlError
            : 'IoTHub inacessível — verifique se tracker-instruction-server está UP. '
              . 'Detalhe: ' . ($curlError ?: "HTTP code=$httpCode");
        return $out;
    }

    $j = $raw ? (json_decode($raw, true) ?? []) : [];

    // Variações de chave entre versões do IoTHub: code/msg, resultCode/resultMsg.
    $out['hub_code'] = $j['code'] ?? $j['resultCode'] ?? -1;
    $out['hub_msg']  = $j['msg']  ?? $j['message'] ?? $j['resultMsg']
                     ?? ('code=' . $out['hub_code'] . ' (sem msg)');
    $out['content']     = $j['data']['_content'] ?? null;
    $out['device_code'] = isset($j['data']['_code']) ? (string)$j['data']['_code'] : null;

    if ($out['hub_code'] === 0) {
        // Device respondeu SINCRONAMENTE (online) → `_content` presente. Sem ele
        // o comando virou fila offline (`_code` 600) e a resposta chega depois
        // pelo callback em /pushinstructresponse.
        if ($out['content'] !== null && $out['content'] !== '') {
            $out['status']     = 'executed';
            $out['result_msg'] = 'Dispositivo respondeu: ' . $out['content'];
        } else {
            $out['status']     = 'sent';
            $out['result_msg'] = $out['hub_msg'] ?: 'Comando aceito pelo IoTHub';
        }
    } else {
        $out['result_msg'] = "IoTHub rejeitou o comando (code={$out['hub_code']}): {$out['hub_msg']}";
    }

    return $out;
}

/**
 * Consulta a fila de comando offline do hub (§2.21, `queryOfflineInstruct`) —
 * SÓ LEITURA, não despacha nada e não muda estado nenhum no equipamento.
 *
 * Ponto único de `scripts/offline_instruct_poll.php` e de qualquer tela que
 * queira mostrar o estado REAL da fila em vez de supor "foi enfileirado e
 * será entregue" (a frase que `docs/FILA_OFFLINE_COMANDOS.md` já registrou
 * como não confirmada até 09/09/2026).
 *
 * 🔴 Teste decisivo (09/09/2026, produção, `865478070649936` offline há 19
 * dias): o comando aparece na fila IMEDIATAMENTE após o envio, mesmo sem
 * `offlineFlag`. A pergunta em aberto não é mais "foi cacheado?" — é "ainda
 * está cacheado?", porque a fila tem validade curta (o `_time_out` que ela
 * devolve) e comandos presos há dias já devem ter expirado dela mesmo tendo
 * sido aceitos no início.
 *
 * ⚠️ `data` desta chamada vem como STRING JSON dentro do JSON do envelope
 * (`{"code":0,"data":"{\"_content\":...}"}`), diferente de `iothub_send_instruct()`
 * onde `data` já é objeto — precisa de um segundo `json_decode`.
 *
 * A resposta NÃO identifica um comando por id, só por `_content` (o texto) e
 * `_route_client_time` — quem chama casa isso contra `commands` por IMEI e
 * conteúdo mais recente, o mesmo tipo de correlação heurística já usada para
 * as respostas "sem comando correlacionado" em `handlers/comandos.php`.
 *
 * @param string $imei
 * @returns array{
 *     encontrado: bool, erro: string|null, content: string|null,
 *     time_out: int|null, raw: string|null, hub_code: mixed, hub_msg: string
 * }
 */
function iothub_query_offline_instruct(string $imei): array
{
    $sendUrl = getenv('IOTHUB_COMMAND_URL') ?: 'http://localhost:10088/api/device/sendInstruct';
    $url     = preg_replace('#/sendInstruct$#', '/queryOfflineInstruct', $sendUrl);
    $token   = getenv('IOTHUB_API_TOKEN') ?: '123';

    $out = [
        'encontrado' => false, 'erro' => null, 'content' => null,
        'time_out' => null, 'raw' => null, 'hub_code' => -1, 'hub_msg' => 'Sem resposta',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        // ⚠️ Divergência da doc (registrada em docs/FILA_OFFLINE_COMANDOS.md
        // §4): `cmdType` é marcado como opcional, mas sem ele o hub devolve
        // HTTP 500. `normallns` é o mesmo valor que `iothub_send_instruct()` usa.
        CURLOPT_POSTFIELDS     => http_build_query([
            'deviceImei' => $imei, 'cmdType' => 'normallns', 'token' => $token,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw       = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $out['raw'] = $raw ?: null;

    if ($curlError || $httpCode === 0) {
        $out['erro'] = 'IoTHub inacessível: ' . ($curlError ?: "HTTP code=$httpCode");
        return $out;
    }

    $j = $raw ? (json_decode($raw, true) ?? []) : [];
    $out['hub_code'] = $j['code'] ?? -1;
    $out['hub_msg']  = $j['msg'] ?? ('code=' . $out['hub_code']);

    // `code:20002` ("could not be found") é o caso normal de "nada na fila" —
    // não é erro de transporte, é resposta válida dizendo "não achei".
    if ((int)$out['hub_code'] !== 0) {
        return $out;
    }

    $data = $j['data'] ?? null;
    if (is_string($data)) $data = json_decode($data, true);
    if (!is_array($data)) {
        $out['erro'] = 'Resposta do hub sem `data` reconhecível';
        return $out;
    }

    $out['encontrado'] = true;
    $out['content']    = isset($data['_content']) ? (string)$data['_content'] : null;
    $out['time_out']   = isset($data['_time_out']) ? (int)$data['_time_out'] : null;
    return $out;
}
