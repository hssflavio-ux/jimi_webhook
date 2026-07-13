<?php
/**
 * Despacho interno de instruções ao IoTHub (tracker-instruction-server :10088).
 *
 * Extraído do fluxo validado de handlers/sendcommand.php (v2.0.0) para uso em
 * contextos SEM sessão de dashboard — ex.: gatilho automático de vídeo do
 * motor de ocorrências. Mantém o mesmo contrato do IoTHub (campos imei,
 * cmdContent, serverFlagId, proNo, platform, requestId, cmdType, token) e a
 * mesma semântica de status da tabela `commands`:
 *   executed → device respondeu sincronamente (data._content preenchido)
 *   sent     → IoTHub aceitou (device offline vira fila, callback em
 *              /pushinstructresponse)
 *   failed   → IoTHub rejeitou/inacessível/timeout
 *
 * ATENÇÃO (herdado de sendcommand.php): o IoTHub SEGURA a resposta HTTP por
 * até 30s aguardando o device — timeouts < 35s podem marcar 'failed' um
 * comando que na verdade foi aceito e enfileirado.
 *
 * @see handlers/sendcommand.php (tabela de bugs corrigidos #1–#5)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

/**
 * Envia uma instrução ao IoTHub e registra o resultado em `commands`.
 *
 * NÃO valida sessão/CSRF/posse do IMEI — o chamador é código de servidor
 * (webhook/worker), não o dashboard. Não chamar a partir de input de usuário.
 *
 * @param string $imei       IMEI do device (15–17 dígitos)
 * @param int    $proNo      Número da instrução (ex.: 34818 = 0x8802)
 * @param string $cmdContent JSON canônico do conteúdo (JT/T) ou texto (JIMI 128)
 * @param array  $opts       operator (default 'sistema'), serverFlagId (default
 *                           env IOTHUB_SERVER_FLAG_ID ou 0), timeout em s
 *                           (default 35), request_prefix (default 'auto')
 * @returns array{status:string, command_id:?int, iothub_code:int, msg:string, offline_queued:bool}
 */
function iothub_dispatch_command(string $imei, int $proNo, string $cmdContent, array $opts = []): array
{
    $operator      = $opts['operator'] ?? 'sistema';
    $timeout       = max(5, (int)($opts['timeout'] ?? 35));
    $serverFlagId  = isset($opts['serverFlagId'])
        ? (int)$opts['serverFlagId']
        : (int)(getenv('IOTHUB_SERVER_FLAG_ID') ?: '0');
    $requestPrefix = $opts['request_prefix'] ?? 'auto';

    $iothubUrl      = getenv('IOTHUB_COMMAND_URL') ?: 'http://localhost:10088/api/device/sendInstruct';
    $iothubApiToken = getenv('IOTHUB_API_TOKEN') ?: '123';
    $requestId      = $requestPrefix . '_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);

    $postFields = http_build_query([
        'imei'         => $imei,
        'cmdContent'   => $cmdContent,
        'serverFlagId' => $serverFlagId,
        'proNo'        => $proNo,
        'platform'     => 'web',
        'requestId'    => $requestId,
        'cmdType'      => 'normallns',
        'token'        => $iothubApiToken,
    ]);

    $ch = curl_init($iothubUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $rawResp   = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $iothubResp = [];
    $iothubCode = -1;
    $dbStatus   = 'failed';
    $resultMsg  = 'Falha desconhecida';

    if ($curlError || $httpCode === 0) {
        $resultMsg = ($curlErrno === CURLE_OPERATION_TIMEDOUT)
            ? 'IoTHub não respondeu a tempo — se o device estiver offline, o comando pode ter sido enfileirado. ' . $curlError
            : 'IoTHub inacessível: ' . ($curlError ?: "HTTP code=$httpCode");
    } else {
        if ($rawResp) {
            $iothubResp = json_decode($rawResp, true) ?? [];
        }
        $iothubCode = $iothubResp['code'] ?? $iothubResp['resultCode'] ?? -1;
        $iothubMsg  = $iothubResp['msg'] ?? $iothubResp['message']
                    ?? $iothubResp['resultMsg'] ?? "code={$iothubCode} (sem msg)";

        if ($iothubCode === 0) {
            $syncContent = $iothubResp['data']['_content'] ?? null;
            if ($syncContent !== null && $syncContent !== '') {
                $dbStatus  = 'executed';
                $resultMsg = 'Dispositivo respondeu: ' . $syncContent;
            } else {
                $dbStatus  = 'sent';
                $resultMsg = $iothubMsg ?: 'Comando aceito pelo IoTHub';
            }
        } else {
            $resultMsg = "IoTHub rejeitou o comando (code={$iothubCode}): {$iothubMsg}";
        }
    }

    $insertedId = null;
    try {
        $db   = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO commands
                (imei, command_content, command_type, status, operator,
                 api_type, response_payload, response_time, created_at, updated_at)
            VALUES
                (:imei, :cmd, 'request', :status, :operator,
                 :api_type, :resp, :rtime, NOW(), NOW())
        ");
        $stmt->execute([
            ':imei'     => $imei,
            ':cmd'      => $cmdContent,
            ':status'   => $dbStatus,
            ':operator' => $operator,
            ':api_type' => ($proNo === 128) ? 'instruct' : "jtt_{$proNo}",
            ':resp'     => $rawResp ?: null,
            ':rtime'    => ($dbStatus === 'executed') ? date('Y-m-d H:i:s') : null,
        ]);
        $insertedId = (int)$db->lastInsertId();
    } catch (Exception $e) {
        Logger::error('iothub_dispatch_command: falha ao gravar em commands', [
            'error' => $e->getMessage(), 'imei' => $imei, 'proNo' => $proNo,
        ]);
    }

    Logger::info('iothub_dispatch_command: instrução despachada', [
        'imei'         => $imei,
        'proNo'        => $proNo,
        'operator'     => $operator,
        'serverFlagId' => $serverFlagId,
        'status'       => $dbStatus,
        'iothub_code'  => $iothubCode,
        'command_id'   => $insertedId,
        'http_code'    => $httpCode,
        'request_id'   => $requestId,
        'iothub_resp'  => substr((string)($rawResp ?: ''), 0, 300),
        'curl_error'   => $curlError ?: null,
    ]);

    return [
        'status'         => $dbStatus,
        'command_id'     => $insertedId,
        'iothub_code'    => (int)$iothubCode,
        'msg'            => $resultMsg,
        'offline_queued' => ($iothubCode === 0) && (int)($iothubResp['data']['_code'] ?? 0) === 600,
    ];
}
