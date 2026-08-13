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
