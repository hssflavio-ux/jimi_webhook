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

// CSRF: endpoint autenticado por cookie → exige token (header X-CSRF-Token nos
// fetches do dashboard; ver web/layout_base.php que expõe window.CSRF_TOKEN)
require_once __DIR__ . '/../includes/csrf.php';
csrf_verify();

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

// ⚠️ O 33028 é a ÚNICA exceção legítima ao cmdContent obrigatório: a doc manda
// o campo VAZIO ("Consultar todos os parâmetros" não tem argumento), e foi
// assim, com o campo vazio, que o JC371 respondeu a configuração inteira na
// sonda de 12/08/2026. Sem esta ressalva a consulta era recusada aqui, três
// linhas antes da normalização que existe justamente para montá-la — que é o
// que o primeiro teste ponta a ponta pegou.
if (!$imei || ($cmdContent === '' && $proNo !== 33028)) {
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
$proNosConhecidos = [128, 37121, 37377, 37381, 37382, 37384, 33283, 33536, 33027, 33028, 33029, 33030, 33031, 34817, 34818];
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

// ── Família de parâmetros (33027/33028/33030): a forma é montada AQUI ────────
//
// 🔴 As três formas são DIFERENTES, e a implementação anterior errou nas três
// (PROJETO_PARAMETROS.md §3.1): mandava `{"paramId":1,"paramValue":"x"}` no
// 33027, `{}` no 33028 e `{"paramIds":[44,45]}` no 33030. O gateway ACEITA
// qualquer um deles — devolve `code:0` — e o device ignora. Ou seja: o defeito
// se apresentava como "mandei e não aconteceu nada", sem erro em lugar nenhum.
//
// A normalização mora no servidor, e não só no JavaScript da tela, porque é o
// ponto por onde TODO comando passa: `/comandos`, `/config-dispositivos`, a aba
// do ativo e o worker de sincronização usam este mesmo handler.
$paramSpec = null;
if (in_array($proNo, [33027, 33028, 33030], true)) {
    require_once __DIR__ . '/../includes/device_params.php';

    $entrada = json_decode($cmdContent, true);
    if (!is_array($entrada)) $entrada = [];

    if ($proNo === 33028) {
        $cmdContent = build_param_cmd_content(33028, []);

    } elseif ($proNo === 33030) {
        // Aceita `{"44":"","45":""}`, `[44,45]` e o `{"paramIds":[…]}` antigo.
        $lista = $entrada['paramIds'] ?? $entrada;
        $cmdContent = build_param_cmd_content(33030, is_array($lista) ? $lista : []);

    } else { // 33027
        // Compatibilidade com o formato errado que as telas mandavam: convertê-lo
        // é melhor do que deixar passar, porque quem manda `paramId/paramValue`
        // hoje recebe "sucesso" e nenhum efeito.
        if (isset($entrada['paramId'])) {
            $entrada = [(int)$entrada['paramId'] => (string)($entrada['paramValue'] ?? '')];
            Logger::warning('sendcommand: 33027 no formato antigo (paramId/paramValue) convertido', [
                'imei' => $imei,
            ]);
        }
        $cmdContent = build_param_cmd_content(33027, $entrada);

        // Escrever parâmetro sem dizer QUAL é comando sem efeito: recusa alto.
        if ($cmdContent === '{}') {
            http_response_code(400);
            echo json_encode(['code' => 400,
                'msg' => 'proNo 33027 exige ao menos um parâmetro no formato {"<numero>":"<valor>"}']);
            exit;
        }
    }
    $paramSpec = $proNo;
}

// Para comandos JT/T (proNo ≠ 128): cmdContent DEVE ser JSON válido
// Re-serializa para garantir formato canônico sem espaços antes de enviar ao IoTHub.
// ⚠️ O 33028 é a exceção: a doc manda `cmdContent` VAZIO, e vazio não é JSON
// válido. Foi confirmado em câmera real — com o campo vazio o JC371 respondeu
// a configuração inteira.
if ($proNo !== 128 && $cmdContent !== '') {
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

// ── proNo 37382: credenciais de FTP entram AQUI, no servidor (v4.9.1) ─────────
//
// 37382 é o "FTP file upload command": ele manda a CÂMERA abrir uma conexão FTP
// e subir a gravação. O payload leva serverAddress/ftpPort/userName/password —
// e é por isso que ele não pode ser montado no navegador: a senha do FTP
// apareceria no código-fonte da página para qualquer usuário logado.
//
// O cliente manda só canal e janela de tempo; o que vier dele nestes cinco
// campos é DESCARTADO, não completado — senão bastaria forjar o POST para
// mandar a câmera despejar vídeo num FTP de terceiro.
//
// Sem configuração o comando é RECUSADO com mensagem explícita. Disparar 37382
// com credencial vazia é o pior dos mundos: o IoTHub aceita, marca `executed`,
// a câmera falha no login e ninguém fica sabendo — que é exatamente como o
// fluxo antigo (com o proNo errado) se comportava.
if ($proNo === 37382) {
    $ftp = [
        'serverAddress' => trim((string)(getenv('VIDEO_FTP_HOST') ?: '')),
        'ftpPort'       => (int)(getenv('VIDEO_FTP_PORT') ?: 0),
        'userName'      => trim((string)(getenv('VIDEO_FTP_USER') ?: '')),
        'password'      => (string)(getenv('VIDEO_FTP_PASS') ?: ''),
        'fileUploadPath'=> trim((string)(getenv('VIDEO_FTP_PATH') ?: '/')),
    ];
    if ($ftp['serverAddress'] === '' || $ftp['userName'] === '' || $ftp['password'] === '' || $ftp['ftpPort'] <= 0) {
        http_response_code(503);
        Logger::error('sendcommand: 37382 sem destino FTP configurado', [
            'imei' => $imei,
            'faltando' => array_keys(array_filter([
                'VIDEO_FTP_HOST' => $ftp['serverAddress'] === '',
                'VIDEO_FTP_PORT' => $ftp['ftpPort'] <= 0,
                'VIDEO_FTP_USER' => $ftp['userName'] === '',
                'VIDEO_FTP_PASS' => $ftp['password'] === '',
            ])),
        ]);
        echo json_encode([
            'code' => 503,
            'msg'  => 'Extração de vídeo indisponível: destino FTP não configurado no servidor '
                    . '(VIDEO_FTP_HOST/PORT/USER/PASS no .env).',
        ]);
        exit;
    }

    $conteudo = json_decode($cmdContent, true) ?: [];

    // ── `condition` e `instructionID` NÃO são opcionais (medido em campo) ────
    //
    // Sem os dois, a câmera **não confirma o comando**: o gateway devolve
    // `_code 600 / "request timeout"` e passa a responder `302 Device busy` aos
    // comandos seguintes. Com eles, a mesma câmera respondeu `ok` na hora.
    // Foi assim que este bloco nasceu — não por leitura da doc, mas porque o
    // 37382 "aceito" nunca produzia arquivo.
    //
    //   condition  → máscara de rede autorizada para o download:
    //                bit0 WiFi, bit1 LAN, bit2 3G/4G. O default 7 libera as
    //                três. Num rastreador veicular a única que existe é a 4G,
    //                e deixá-la de fora faz a câmera aceitar e nunca baixar.
    //                ⚠️ Vídeo por 4G consome franquia do SIM — quem quiser
    //                restringir a WiFi usa VIDEO_FTP_CONDITION=1.
    //   instructionID → a doc o define como a chave de correspondência entre o
    //                comando e a resposta, e é ele que volta no
    //                /pushftpfileupload. É o que liga o arquivo ao pedido.
    $conteudo['condition'] = (int)(getenv('VIDEO_FTP_CONDITION') ?: 7);
    if (empty($conteudo['instructionID'])) {
        $conteudo['instructionID'] = 'ext' . date('YmdHis') . substr(bin2hex(random_bytes(4)), 0, 6);
    }
    $instructionId37382 = (string)$conteudo['instructionID'];

    $cmdContent = json_encode(array_merge($conteudo, $ftp),
                              JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
//
// A senha do FTP do 37382 NÃO é gravada (v4.9.1). `commands.command_content`
// aparece inteiro na tela de Comandos e nos exports; guardar a credencial ali
// seria trocar o vazamento pelo JavaScript por um vazamento pelo histórico —
// que ainda por cima é permanente. O resto do payload fica, porque é o que
// permite auditar qual janela foi pedida.
$cmdParaGravar = $cmdContent;
if ($proNo === 37382) {
    $tmp = json_decode($cmdContent, true);
    if (is_array($tmp) && isset($tmp['password'])) {
        $tmp['password'] = '***';
        $cmdParaGravar = json_encode($tmp, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

$insertedId = null;
try {
    $db   = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        INSERT INTO commands
            (imei, command_content, command_type, status, operator,
             api_type, pro_no, request_id, server_flag_id, response_payload, response_time,
             created_at, updated_at)
        VALUES
            (:imei, :cmd, 'request', :status, 'dashboard',
             :api_type, :prono, :rid, :sfid, :resp, :rtime, NOW(), NOW())
    ");
    $stmt->execute([
        ':imei'     => $imei,
        // 33028 manda cmdContent vazio; gravar '' deixaria a tela de Comandos
        // com uma linha em branco e a correlação do callback sem nada para
        // casar. O rótulo diz o que foi pedido.
        ':cmd'      => ($cmdParaGravar === '' && $proNo === 33028) ? '(consulta de parâmetros)' : $cmdParaGravar,
        ':status'   => $dbStatus,
        ':api_type' => ($proNo === 128) ? 'instruct' : "jtt_{$proNo}",
        // v4.9.12: coluna própria. O proNo já vivia dentro de `api_type`
        // ('jtt_33028'), mas decidir fluxo por substring de string é o tipo de
        // coisa que quebra calada quando alguém muda o prefixo.
        ':prono'    => $proNo,
        // v4.8.9: os dois identificadores mandados ao IoTHub passam a ser
        // gravados. Sem isso não havia como ligar esta linha ao rastro que o
        // IoTHub tem do mesmo envio — a doc oficial dá ao requestId exatamente
        // essa função ("troubleshooting and log tracing").
        ':rid'      => $requestId,
        ':sfid'     => (string)$serverFlagId,
        ':resp'     => $rawResp ?: null,   // Guarda rawResp completo para auditoria
        ':rtime'    => ($dbStatus === 'executed') ? date('Y-m-d H:i:s') : null,
    ]);
    $insertedId = $db->lastInsertId();

    // ── Leitura de parâmetros: a resposta SÍNCRONA já traz tudo ──────────────
    //
    // Para device online o `_content` do 33028/33030 é a configuração inteira,
    // e ela chega aqui, nesta mesma requisição — não precisa esperar callback.
    // Isso já era verdade antes da v4.9.12; o que faltava era alguém LER.
    //
    // Falha aqui não derruba o comando: o `rawResp` completo já está gravado em
    // `commands.response_payload`, então o dado não se perde e o snapshot pode
    // ser refeito. Mas o erro é LOGADO — foi `catch` silencioso neste mesmo
    // arquivo que escondeu o "Invalid JSON text" por meses (v4.8.9).
    if ($paramSpec !== null && $paramSpec !== 33027 && !empty($syncContent)) {
        try {
            $parsed = parse_param_content((string)$syncContent);
            if ($parsed['ok']) {
                $gravados = upsert_device_params(
                    $db, $imei, $parsed, $paramSpec, (string)$paramSpec, (int)$insertedId
                );
                // Só a leitura COMPLETA marca o device como sincronizado. Um
                // 33030 traz um punhado de parâmetros, e chamar isso de
                // "sincronizado" faria o worker parar de buscar o resto — o
                // device ficaria com três valores lidos e 43 nunca lidos, sem
                // nada na tela denunciando. (Medido: um 33030 de 3 parâmetros
                // carimbou `params_synced_at` antes desta guarda existir.)
                if ($paramSpec === 33028) {
                    $db->prepare("UPDATE devices SET params_synced_at = NOW(),
                                         params_sync_tries = 0, params_sync_next = NULL
                                   WHERE imei = :imei")->execute([':imei' => $imei]);
                }
                Logger::info('sendcommand: parâmetros lidos e gravados', [
                    'imei' => $imei, 'proNo' => $paramSpec,
                    'gravados' => $gravados, 'param_count' => $parsed['param_count'],
                ]);
            } else {
                Logger::warning('sendcommand: _content de parâmetros não parseou', [
                    'imei' => $imei, 'proNo' => $paramSpec, 'erro' => $parsed['erro'],
                ]);
            }
        } catch (Throwable $e) {
            Logger::error('sendcommand: falha ao gravar parâmetros', [
                'imei' => $imei, 'erro' => $e->getMessage(),
            ]);
        }
    }

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

    // ── Fila visível da extração de vídeo (v4.9.1) ───────────────────────────
    //
    // O pedido de extração vira uma linha `solicitado` em `media_files`. Sem
    // ela, /video/downloads — que lista `media_files` — ficava VAZIA entre o
    // clique em [Extrair] e a chegada do arquivo, que leva minutos: o usuário
    // não tinha como saber se o pedido tinha saído. O enum `download_status`
    // já previa esse estado; ninguém o escrevia.
    //
    // `file_name` guarda o `instructionID` entre colchetes: é a chave que a doc
    // define para casar comando e resposta, e é ela que volta no
    // /pushftpfileupload. Sem isso a correlação era "o pedido pendente mais
    // antigo do mesmo IMEI e canal" — e ela ERRA: no teste em campo, um pedido
    // que falhou por timeout fechou com o resultado de outro, enviado depois.
    //
    // `event_time` recebe o INÍCIO da janela pedida (BCD yyMMddHHmmss em GMT-0,
    // como o device fala).
    if ($proNo === 37382 && $dbStatus !== 'failed') {
        try {
            $c   = json_decode($cmdContent, true) ?: [];
            $ini = (string)($c['beginTime'] ?? '');
            $ts  = preg_match('/^\d{12}$/', $ini)
                 ? DateTime::createFromFormat('ymdHis', $ini, new DateTimeZone('UTC'))
                 : false;
            $db->prepare("
                INSERT INTO media_files
                    (imei, file_name, file_type, file_size, file_url, source_type,
                     event_time, channel, download_status, raw_data)
                VALUES (:imei, :fname, 'video', 0, NULL, 'extracao_37382',
                        :etime, :ch, 'solicitado', :raw)
            ")->execute([
                ':imei'  => $imei,
                ':fname' => 'Extração CH' . (int)($c['channel'] ?? 0)
                          . ' — aguardando a câmera [' . ($instructionId37382 ?? '') . ']',
                ':etime' => $ts ? $ts->format('Y-m-d H:i:s') : null,
                ':ch'    => (int)($c['channel'] ?? 0) ?: null,
                ':raw'   => $cmdParaGravar,   // sem a senha, como em `commands`
            ]);
        } catch (Throwable $e) {
            // A fila é conforto de tela: o comando já saiu, e falhar aqui não
            // pode transformar uma extração bem-sucedida em erro para o usuário.
            Logger::warning('sendcommand: extração 37382 sem linha na fila', [
                'imei' => $imei, 'error' => $e->getMessage(),
            ]);
        }
    }

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