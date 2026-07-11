<?php
/**
 * JIMI IoT Hub - Handler de Envio de Comandos
 * Endpoint: /sendcommand  (via .htaccess → handlers/sendcommand.php)
 * Versão: 2.0.0
 * Data:   2026-02-24
 *
 * Proxy entre o Dashboard (AJAX) e a API interna do IoTHub.
 * Suporta comandos JIMI (proNo 128) e JT/T (proNo 37121, 37377, 37381, 37382, 33283, 33536).
 *
 * ┌─────────────────────────────────────────────────────────────────────────────┐
 * │ BUGS CORRIGIDOS em relação à versão anterior (v1.x)                        │
 * ├─────┬───────────────────────────────────────────────────────────────────────┤
 * │ #1  │ Campo 'deviceImei' → 'imei'                                           │
 * │     │ A doc oficial (test.html §1.2.2 e §2.2.2) usa 'imei'. O IoTHub       │
 * │     │ rejeitava silenciosamente qualquer payload com 'deviceImei'.          │
 * ├─────┼───────────────────────────────────────────────────────────────────────┤
 * │ #2  │ Porta 9080 → 10088                                                    │
 * │     │ 9080  = tracker-dvr-api  → queries históricas / MongoDB               │
 * │     │ 10088 = tracker-instruction-server → ENVIO de comandos aos devices    │
 * │     │ Usar 9080 para enviar comandos nunca funcionou.                        │
 * ├─────┼───────────────────────────────────────────────────────────────────────┤
 * │ #3  │ Campos obrigatórios ausentes no payload:                              │
 * │     │   serverFlagId  (ex: 0 para JTT, 1 para JIMI)                        │
 * │     │   cmdType       = 'normallns' (fixo conforme docs)                    │
 * │     │   token         = token interno IoTHub (padrão '123')                 │
 * ├─────┼───────────────────────────────────────────────────────────────────────┤
 * │ #4  │ Logger não capturava rawResp do IoTHub — diagnóstico impossível       │
 * │     │ Agora loga iothub_url, http_code, iothub_resp (300 chars), etc.       │
 * ├─────┼───────────────────────────────────────────────────────────────────────┤
 * │ #5  │ iothubCode=-1 genérico mascarava o código real de rejeição do IoTHub  │
 * │     │ Agora o código real é exposto no log e na resposta ao dashboard.      │
 * └─────┴───────────────────────────────────────────────────────────────────────┘
 *
 * Referência oficial:
 *   https://docs.jimicloud.com/test/test.html
 *   Payload completo (seção 1.2.2 / 2.2.2 — curl de referência):
 *     imei, cmdContent, serverFlagId, proNo, platform, requestId, cmdType, token
 */

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/auth.php';

// ── Apenas POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => 405, 'msg' => 'Method Not Allowed']);
    exit;
}

// ── Autorização: sessão de dashboard obrigatória (R02 — antes bastava o token
// compartilhado WEBHOOK_TOKEN, que permitia enviar comandos para qualquer IMEI
// de qualquer cliente; o header X-Dashboard-Token legado é ignorado) ──────────
require_ajax_session();
$customerId = (int)get_customer_id();
if (!$customerId) {
    http_response_code(403);
    echo json_encode(['code' => 403, 'msg' => 'Contexto de cliente não definido']);
    exit;
}

// ── Parâmetros de entrada ─────────────────────────────────────────────────────
// Aceita tanto form-urlencoded ($_POST) quanto JSON (php://input) do novo frontend
$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $rawBody = file_get_contents('php://input');
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) $input = $decoded;
} else {
    $input = $_POST;
}

$imei       = trim($input['imei']       ?? '');
$cmdContent = trim($input['content']    ?? $input['cmdContent'] ?? '');
$proNo      = intval($input['proNo']    ?? 128);

if (!$imei || !$cmdContent) {
    http_response_code(400);
    echo json_encode(['code' => 400, 'msg' => 'Parâmetros obrigatórios: imei, cmdContent']);
    exit;
}

// Validação de IMEI — 15 a 17 dígitos numéricos
if (!preg_match('/^\d{15,17}$/', $imei)) {
    http_response_code(400);
    echo json_encode(['code' => 400, 'msg' => 'IMEI inválido (esperado 15–17 dígitos numéricos)']);
    exit;
}

// Multi-tenant: o IMEI deve pertencer ao cliente da sessão (R02)
try {
    $db  = Database::getInstance()->getConnection();
    $own = $db->prepare("SELECT 1 FROM devices WHERE imei = :imei AND customer_id = :cid AND is_active = 1");
    $own->execute([':imei' => $imei, ':cid' => $customerId]);
    if (!$own->fetchColumn()) {
        http_response_code(403);
        Logger::warning('sendcommand: IMEI não pertence ao cliente da sessão', [
            'imei'        => $imei,
            'customer_id' => $customerId,
        ]);
        echo json_encode(['code' => 403, 'msg' => 'Dispositivo não pertence ao cliente atual']);
        exit;
    }
} catch (Exception $e) {
    Logger::error('sendcommand: falha ao validar posse do IMEI', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['code' => 500, 'msg' => 'Erro interno']);
    exit;
}

// Validação de proNo — deve ser inteiro positivo conhecido (R03: whitelist bloqueante)
$proNosConhecidos = [128, 37121, 37377, 37381, 37382, 33283, 33536, 33027, 33028, 33029, 33030, 33031, 34817, 34818];
if (!in_array($proNo, $proNosConhecidos, true)) {
    http_response_code(400);
    Logger::warning('sendcommand: proNo desconhecido bloqueado', [
        'imei'  => $imei,
        'proNo' => $proNo,
    ]);
    echo json_encode([
        'code' => 400,
        'msg'  => 'proNo desconhecido: ' . $proNo . '. Permitidos: ' . implode(', ', $proNosConhecidos),
    ]);
    exit;
}

// Para comandos JT/T (proNo ≠ 128): cmdContent DEVE ser JSON válido
// Re-serializa para garantir formato canônico sem espaços antes de enviar ao IoTHub
if ($proNo !== 128) {
    $decodedJson = json_decode($cmdContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'code' => 400,
            'msg'  => 'Para proNo ' . $proNo . ', cmdContent deve ser JSON válido. '
                    . 'Erro: ' . json_last_error_msg(),
        ]);
        exit;
    }
    // Serialização canônica: sem espaços, sem escapes desnecessários
    $canonical = json_encode($decodedJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_decode(assoc) converte {} em array vazio e o re-encode viraria "[]";
    // preserva o objeto vazio quando o original era um objeto JSON
    if ($canonical === '[]' && $cmdContent[0] === '{') {
        $canonical = '{}';
    }
    $cmdContent = $canonical;
}

// ── Configuração do endpoint de comando do IoTHub ─────────────────────────────
//
// BUG #2 CORRIGIDO:
//   PORTA CORRETA: 10088 → tracker-instruction-server (envio de comandos)
//   PORTA ERRADA:  9080  → tracker-dvr-api (apenas queries históricas)
//
// Configurável via variável de ambiente IOTHUB_COMMAND_URL para flexibilidade
// em ambientes com múltiplos servidores ou portas customizadas.
$iothubUrl = getenv('IOTHUB_COMMAND_URL') ?: 'http://localhost:10088/api/device/sendInstruct';

// Token interno da API do IoTHub (não confundir com o token do webhook DataPush)
// Padrão '123' conforme todos os exemplos curl da documentação oficial
$iothubApiToken = getenv('IOTHUB_API_TOKEN') ?: '123';

// BUG #3 CORRIGIDO: serverFlagId vem do POST (JS diferencia por protocolo)
//   serverFlagId=1 → gateway JIMI (porta 21100) → JC400 series
//   serverFlagId=0 → gateway JT/T (porta 21122) → JC450/JC181 series
$serverFlagId = isset($input['serverFlagId'])
    ? intval($input['serverFlagId'])
    : intval(getenv('IOTHUB_SERVER_FLAG_ID') ?: '0');

// requestId único para rastreamento ponta-a-ponta (dashboard ↔ IoTHub ↔ device)
$requestId = 'dash_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);

// ── Payload completo para a API interna do IoTHub ─────────────────────────────
//
// BUG #1 CORRIGIDO: 'deviceImei' → 'imei'
// BUG #3 CORRIGIDO: serverFlagId, cmdType, token — campos obrigatórios adicionados
//
// Referência: https://docs.jimicloud.com/test/test.html §1.2.2 e §2.2.2
// curl --data-urlencode 'imei=...' 'serverFlagId=...' 'cmdType=normallns' 'token=...'
$postFields = http_build_query([
    'imei'         => $imei,            // BUG #1: era 'deviceImei'
    'cmdContent'   => $cmdContent,
    'serverFlagId' => $serverFlagId,    // BUG #3: campo obrigatório ausente
    'proNo'        => $proNo,
    'platform'     => 'web',
    'requestId'    => $requestId,
    'cmdType'      => 'normallns',      // BUG #3: campo obrigatório ausente
    'token'        => $iothubApiToken,  // BUG #3: token API interna ausente
]);

// ── Chamada cURL ao IoTHub ────────────────────────────────────────────────────
//
// TIMEOUT 35s (era 15s): quando o device demora/está offline, o
// tracker-instruction-server SEGURA a resposta HTTP por até 30s
// ("processSendInstruct await timeout") antes de responder que o comando
// virou fila offline. Com 15s o PHP abortava no meio da espera e o comando
// era marcado "failed" mesmo tendo sido aceito e enfileirado pelo IoTHub.
$ch = curl_init($iothubUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $postFields,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 35,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);

$rawResp   = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrno = curl_errno($ch);
$curlError = curl_error($ch);
curl_close($ch);

// ── Interpretação da resposta do IoTHub ──────────────────────────────────────
$iothubResp = [];
$iothubCode = -1;
$iothubMsg  = 'Sem resposta';
$dbStatus   = 'failed';
$resultMsg  = 'Falha desconhecida';

if ($curlError || $httpCode === 0) {
    $dbStatus = 'failed';
    if ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
        // Timeout ≠ inacessível: o IoTHub recebeu o comando mas não respondeu
        // a tempo (device lento). O comando pode ter sido enfileirado offline.
        $resultMsg = 'IoTHub não respondeu a tempo — se o dispositivo estiver '
                   . 'offline, o comando foi enfileirado e será entregue na '
                   . 'reconexão. Detalhe: ' . $curlError;
    } else {
        // Falha de conectividade real (container down, porta fechada)
        $resultMsg = 'IoTHub inacessível — verifique se tracker-instruction-server está UP. '
                   . 'Detalhe: ' . ($curlError ?: "HTTP code=$httpCode");
    }

} else {
    // IoTHub respondeu com HTTP 200 — decodifica o body JSON
    if ($rawResp) {
        $iothubResp = json_decode($rawResp, true) ?? [];
    }

    // Suporte a variações de chave entre versões do IoTHub:
    //   Versões mais antigas: code/msg
    //   Versões mais novas:   resultCode/resultMsg ou code/message
    $iothubCode = $iothubResp['code']       ?? $iothubResp['resultCode'] ?? -1;
    $iothubMsg  = $iothubResp['msg']        ?? $iothubResp['message']
                ?? $iothubResp['resultMsg'] ?? "code={$iothubCode} (sem msg)";

    if ($iothubCode === 0) {
        // Sucesso: IoTHub aceitou o comando.
        // Se o device respondeu SINCRONAMENTE (online), data._content traz a
        // resposta → status 'executed' já aqui, senão o polling do dashboard
        // nunca sai de 'sent' e termina em falso "timeout/fila offline".
        // Se virou fila offline (data._code=600), fica 'sent' aguardando o
        // callback em /pushinstructresponse.
        $syncContent = $iothubResp['data']['_content'] ?? null;
        if ($syncContent !== null && $syncContent !== '') {
            $dbStatus  = 'executed';
            $resultMsg = 'Dispositivo respondeu: ' . $syncContent;
        } else {
            $dbStatus  = 'sent';
            $resultMsg = $iothubMsg ?: 'Comando aceito pelo IoTHub';
        }

    } else {
        // BUG #5 CORRIGIDO: agora exibe o código real em vez de -1 genérico
        $dbStatus  = 'failed';
        $resultMsg = "IoTHub rejeitou o comando (code={$iothubCode}): {$iothubMsg}";
    }
}

// ── Persistência na tabela `commands` ────────────────────────────────────────
$insertedId = null;
try {
    $db   = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        INSERT INTO commands
            (imei, command_content, command_type, status, operator,
             api_type, response_payload, response_time, created_at, updated_at)
        VALUES
            (:imei, :cmd, 'request', :status, 'dashboard',
             :api_type, :resp, :rtime, NOW(), NOW())
    ");
    $stmt->execute([
        ':imei'     => $imei,
        ':cmd'      => $cmdContent,
        ':status'   => $dbStatus,
        ':api_type' => ($proNo === 128) ? 'instruct' : "jtt_{$proNo}",
        ':resp'     => $rawResp ?: null,   // Guarda rawResp completo para auditoria
        ':rtime'    => ($dbStatus === 'executed') ? date('Y-m-d H:i:s') : null,
    ]);
    $insertedId = $db->lastInsertId();

    // BUG #4 CORRIGIDO: agora loga rawResp, iothub_url e iothub_code reais
    Logger::info('sendcommand: comando registrado', [
        'imei'         => $imei,
        'proNo'        => $proNo,
        'serverFlagId' => $serverFlagId,
        'status'       => $dbStatus,
        'iothub_code'  => $iothubCode,
        'iothub_msg'   => $iothubMsg,
        'command_id'   => $insertedId,
        'http_code'    => $httpCode,
        'iothub_url'   => $iothubUrl,
        'iothub_resp'  => substr((string)($rawResp ?: ''), 0, 300),
        'curl_error'   => $curlError ?: null,
        'request_id'   => $requestId,
    ]);

} catch (Exception $e) {
    // Falha no banco não deve impedir a resposta ao dashboard
    Logger::error('sendcommand: falha ao gravar no banco', [
        'error' => $e->getMessage(),
        'imei'  => $imei,
        'proNo' => $proNo,
    ]);
}

// ── Resposta JSON ao Dashboard ────────────────────────────────────────────────
//
// O campo 'endpoint' é usado pelo JS do dashboard para confirmar
// que a porta 10088 está sendo usada (não a 9080 — bug anterior).
//
// O campo 'iothub_code' permite ao dashboard diferenciar:
//   0  = sucesso
//   -1 = IoTHub inacessível
//   outros = erro específico retornado pelo IoTHub
// Fila offline: IoTHub aceitou mas o device não estava conectado
// (data._code=600) — o comando só será entregue na reconexão. Fluxos de
// tempo real (ex.: vídeo ao vivo) usam isso para não esperar um stream
// que não vai começar.
$offlineQueued = ($iothubCode === 0)
    && (int)($iothubResp['data']['_code'] ?? 0) === 600;

echo json_encode([
    'code'           => ($dbStatus === 'sent') ? 0 : $iothubCode,
    'msg'            => $resultMsg,
    'command_id'     => $insertedId,
    'status'         => $dbStatus,        // sent | executed | failed
    'offline_queued' => $offlineQueued,
    'iothub_code'    => $iothubCode,
    'iothub_msg'     => $iothubMsg,
    'http_status'    => $httpCode,
    'request_id'     => $requestId,
    'endpoint'       => $iothubUrl,     // JS verifica que contém '10088'
    'server_flag'    => $serverFlagId,  // Para debug: confirma qual gateway foi usado
], JSON_UNESCAPED_UNICODE);