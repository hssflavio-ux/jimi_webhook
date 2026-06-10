<?php
/**
 * JIMI IoT Hub - Push FTP File Upload Handler
 * Endpoint: /pushftpfileupload
 * Versão: 2.0.0 (Alinhado com spec oficial - Seção 1.12)
 * Referência: Seção 1.12 - Push FTP Upload Result
 */
define('HANDLER_NAME', 'pushftpfileupload');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushFtpFileUploadHandler extends WebhookHandler {

    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    protected function processItem($item) {
        $imei = $item['deviceImei'] ?? $item['imei'] ?? null;
        if (!$imei) {
            Logger::warning('FTP Upload ignorado: IMEI ausente', [
                'source' => $this->handlerName, 'keys' => array_keys($item)
            ]);
            return false;
        }

        // Campos documentados: result (0=Success, 1=Fail), instructionID, gateTime
        $result        = $item['result'] ?? null;
        $instructionID = $item['instructionID'] ?? $item['instructionId'] ?? null;
        $rawTime       = $item['gateTime'] ?? $item['ftpUploadTime'] ?? $item['uploadTime'] ?? null;
        $eventTime     = sanitize_date($rawTime);

        // Fallback: firmwares antigos podem enviar fileUrl/filePath
        $fileName = $item['fileName'] ?? $item['file'] ?? $instructionID ?? 'unknown';
        $fileUrl  = $item['fileUrl'] ?? $item['filePath'] ?? $item['url'] ?? $fileName;
        $fileSize = $item['fileSize'] ?? $item['size'] ?? 0;
        $fileType = detect_media_type($fileName);

        try {
            $stmt = $this->db->prepare("
                INSERT INTO media_files 
                (imei, file_name, file_type, file_size, file_url, source_type, event_time, raw_data)
                VALUES (:imei, :fname, :ftype, :fsize, :url, 'pushftpfileupload', :etime, :raw)
            ");
            $stmt->execute([
                ':imei'  => $imei,
                ':fname' => $fileName,
                ':ftype' => $fileType,
                ':fsize' => $fileSize,
                ':url'   => $fileUrl,
                ':etime' => $eventTime,
                ':raw'   => json_encode($item, JSON_UNESCAPED_UNICODE)
            ]);

            Logger::info('FTP Upload registrado', [
                'source' => $this->handlerName, 'imei' => $imei,
                'file' => $fileName, 'instruction_id' => $instructionID, 'result' => $result
            ]);

            return true;

        } catch (PDOException $e) {
            Logger::error('Erro SQL PushFTP: ' . $e->getMessage(), [
                'source' => $this->handlerName, 'imei' => $imei
            ]);
            return false;
        }
    }
}

$handler = new PushFtpFileUploadHandler();
$handler->handle();
