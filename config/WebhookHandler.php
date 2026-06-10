<?php
/**
 * JIMI IoT Hub - Handler Base para Webhooks
 * Versão: 2.0.0
 *
 * Classe abstrata que implementa o fluxo padrão de processamento:
 *   token → validação → HTTP 200 assíncrono → normalize → INSERT → stored proc → commit
 *
 * Fornece: validação de token, idempotência (MD5 hash, janela de 10 min),
 * resposta assíncrona via fastcgi_finish_request(), gerenciamento de transações,
 * logging estruturado e métricas de performance.
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../core/Logger.php';

abstract class WebhookHandler {
    protected $db, $handlerName, $startTime, $validToken;
    protected $requestMeta = [];
    
    public function __construct($handlerName) {
        $this->handlerName = $handlerName;
        $this->startTime = microtime(true);
        $this->validToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';
        
        try {
            $this->db = Database::getInstance()->getConnection();
        } catch (Exception $e) {
            Logger::error('DB Connection Failed', ['source' => $this->handlerName, 'error' => $e->getMessage()]);
            $this->sendError(500, 'Database Connection Error');
        }
    }
    
    public function handle() {
        try {
            $data = get_webhook_data();

            // RAW REQUEST LOGGING v2.0.0
            Logger::debug('RAW_WEBHOOK_DATA', [
                'source' => $this->handlerName,
                'raw_post' => json_encode($_POST, JSON_UNESCAPED_UNICODE),
                'raw_input' => file_get_contents('php://input'),
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown',
                'data_keys' => array_keys($data)
            ]);

            // Hash de idempotência do payload (Prevenção Replay Attacks)
            $payloadHash = md5(json_encode($data['data_list'] ?? []));
            Logger::info('REQUEST RECEIVED', [
                'source' => $this->handlerName,
                'token_valid' => ($data['token'] ?? '') === $this->validToken,
                'data_count' => count($data['data_list'] ?? []),
                'payload_hash' => $payloadHash
            ]);

            if (!$this->validateToken($data['token'] ?? '')) $this->sendError(401, 'Unauthorized');

            // Armazenar metadados do request para acesso pelos handlers filhos (ex: msgType)
            $this->requestMeta = array_diff_key($data, array_flip(['token', 'data_list']));

            $dataList = $data['data_list'] ?? [];
            if (empty($dataList)) {
                $this->sendEarlySuccess('success (empty data)');
                exit;
            }

            $this->validateData($dataList);

            // Resposta assíncrona: libera o cliente HTTP antes do processamento pesado
            $this->sendEarlySuccess('Accepted and Processing', ['queue_hash' => $payloadHash]);

            // --- A PARTIR DAQUI O PHP-FPM PROCESSARÁ EM BACKGROUND ---

            // Checagem de idempotência: rejeita payloads duplicados (replay/retry)
            if ($this->isDuplicateRequest($payloadHash)) {
                Logger::warning('Replay/Idempotent Block', ['source' => $this->handlerName, 'hash' => $payloadHash]);
                exit;
            }

            $this->db->beginTransaction();
            $savedCount = 0;

            foreach ($dataList as $item) {
                try {
                    $item = normalize_data($item);
                    if ($this->processItem($item)) $savedCount++;
                } catch (Exception $e) {
                    Logger::error('Item Processing Error', ['source' => $this->handlerName, 'error' => $e->getMessage()]);
                }
            }

            $this->db->commit();
            $this->logMetrics($savedCount, count($dataList), $payloadHash);

        } catch (Exception $e) {
            if ($this->db && $this->db->inTransaction()) $this->db->rollBack();
            Logger::error('FATAL ERROR', ['source' => $this->handlerName, 'error' => $e->getMessage()]);
            // Client já recebeu HTTP 200 via sendEarlySuccess - logar é crucial
        }
    }
    
    protected function validateToken($token) { return $token === $this->validToken; }
    protected function validateData($dataList) {}
    abstract protected function processItem($item);
    
    protected function sendEarlySuccess($message, $data = null) {
        $response = ['code' => 0, 'message' => $message];
        if ($data !== null) $response['data'] = $data;

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);

        Logger::info('EARLY HTTP RESPONSE DISPATCHED', array_merge(['source' => $this->handlerName], $response));

        // Libera o cliente TCP e continua processamento em background (PHP-FPM)
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
    
    protected function sendError($code, $message, $data = null) {
        $response = ['code' => $code, 'message' => $message];
        if ($data !== null) $response['data'] = $data;
        Logger::error('ERROR RESPONSE', array_merge(['source' => $this->handlerName], $response));
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    protected function logMetrics($saved, $total, $hash = null) {
        $executionTime = round((microtime(true) - $this->startTime) * 1000, 2);
        try {
            $stmt = $this->db->prepare("INSERT INTO request_logs (endpoint, response_code, execution_time, payload_hash) VALUES (:endpoint, :code, :time, :hash)");
            $stmt->execute([':endpoint' => $this->handlerName, ':code' => 0, ':time' => $executionTime, ':hash' => $hash]);
        } catch (Exception $e) {}
        Logger::info('METRICS', ['source' => $this->handlerName, 'saved' => $saved, 'total' => $total, 'execution_time_ms' => $executionTime, 'payload_hash' => $hash]);
    }

    /**
     * Checagem de idempotência: verifica se o payload hash já foi processado nos últimos 10 minutos.
     * Previne Replay Attacks e re-tentativas duplicadas do IoTHub.
     */
    protected function isDuplicateRequest($hash) {
        try {
            $stmt = $this->db->prepare("SELECT COUNT(1) FROM request_logs WHERE payload_hash = :hash AND created_at >= NOW() - INTERVAL 10 MINUTE");
            $stmt->execute([':hash' => $hash]);
            return ($stmt->fetchColumn() > 0);
        } catch (Exception $e) {
            return false;
        }
    }
    
    protected function validateRequired($item, $field, $fieldName = null) {
        if (!isset($item[$field]) || $item[$field] === '' || $item[$field] === null) {
            throw new Exception("Campo obrigatório ausente: " . ($fieldName ?? $field));
        }
        return $item[$field];
    }
    
    protected function callProcedure($procName, $params) {
        try {
            $placeholders = implode(',', array_fill(0, count($params), '?'));
            $stmt = $this->db->prepare("CALL {$procName}({$placeholders})");
            $stmt->execute($params);
            return true;
        } catch (Exception $e) {
            Logger::error("Procedure {$procName} Failed", ['source' => $this->handlerName, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
