<?php
/**
 * JIMI IoT Hub - Push Instruct Response Handler
 * Endpoint: /pushinstructresponse
 * Versão: 2.0.0 (Alinhado com spec oficial - Seção 1.16)
 * Referência: Seção 1.16 - Respostas de Comandos Assíncronos/Offline
 */
define('HANDLER_NAME', 'pushinstructresponse');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushInstructResponseHandler extends WebhookHandler {

    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    protected function processItem($item) {
        // msgType no nível do request: 1=async, 2=offline
        $msgType = $this->requestMeta['msgType'] ?? null;

        // Estrutura documentada: { code, msg, data: { _code, _imei, _content, _msg, _serverFlagId } }
        $code   = $item['code']   ?? null;
        $msg    = $item['msg']    ?? null;
        $data   = $item['data']   ?? [];

        // Extrair campos do objeto data (documentado) com fallback para estrutura antiga
        $imei       = $data['_imei']    ?? $item['deviceImei'] ?? $item['imei'] ?? null;
        $content    = $data['_content'] ?? $item['content']   ?? $item['instructContent'] ?? null;
        $response   = $data['_msg']     ?? $item['resultContent'] ?? $item['response'] ?? $msg;
        $serverFlagId = $data['_serverFlagId'] ?? $item['serverFlagId'] ?? null;
        $instructId = $data['_code']    ?? $item['instructId'] ?? $item['commandId'] ?? $item['msgId'] ?? null;

        if (!$imei) {
            Logger::warning('InstructResponse ignorado: IMEI ausente', [
                'source' => $this->handlerName, 'keys' => array_keys($item)
            ]);
            return false;
        }

        $status   = ($code === 0 || $code === '0') ? 'success' : 'failed';
        $execTime = sanitize_date($item['updateTime'] ?? $item['executeTime'] ?? $item['time'] ?? null);

        try {
            // 1. Inserir na tabela de respostas
            $stmt = $this->db->prepare("
                INSERT INTO command_responses 
                (imei, instruct_id, msg_type, command_content, response_content, 
                 status, server_flag_id, remark, execute_time, server_time, created_at)
                VALUES 
                (:imei, :iid, :mtype, :cmd, :resp,
                 :status, :sfid, :remark, :etime, NOW(), NOW())
            ");
            $stmt->execute([
                ':imei'   => $imei,
                ':iid'    => substr((string)$instructId, 0, 50),
                ':mtype'  => $msgType,
                ':cmd'    => substr((string)$content, 0, 250),
                ':resp'   => substr((string)$response, 0, 65000),
                ':status' => $status,
                ':sfid'   => $serverFlagId ? substr((string)$serverFlagId, 0, 20) : null,
                ':remark' => substr((string)$msg, 0, 250),
                ':etime'  => $execTime
            ]);

            // 2. Atualizar tabela commands se existir comando pendente
            $this->updatePendingCommand($imei, $status, $response);

            Logger::info('InstructResponse registrado', [
                'source' => $this->handlerName, 'imei' => $imei,
                'status' => $status, 'msg_type' => $msgType
            ]);

            return true;

        } catch (PDOException $e) {
            Logger::error('Erro SQL InstructResponse: ' . $e->getMessage(), [
                'source' => $this->handlerName, 'imei' => $imei
            ]);
            return false;
        }
    }

    /**
     * Atualiza o comando pendente correspondente na tabela commands
     */
    private function updatePendingCommand($imei, $status, $response) {
        try {
            $stmt = $this->db->prepare("
                SELECT id FROM commands 
                WHERE imei = :imei AND status IN ('pending', 'sent', 'queued') 
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([':imei' => $imei]);
            $existing = $stmt->fetch();

            if ($existing) {
                $cmdStatus = ($status === 'success') ? 'executed' : 'failed';
                $stmt = $this->db->prepare("
                    UPDATE commands SET status = :status, response_time = NOW(), 
                    response_payload = :payload, updated_at = NOW() WHERE id = :id
                ");
                $stmt->execute([
                    ':status' => $cmdStatus,
                    ':payload' => is_array($response) ? json_encode($response) : (string)$response,
                    ':id' => $existing['id']
                ]);
            }
        } catch (Exception $e) {
            // Silencioso - a resposta já foi salva em command_responses
        }
    }
}

$handler = new PushInstructResponseHandler();
$handler->handle();
