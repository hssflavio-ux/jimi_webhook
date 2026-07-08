<?php
/**
 * JIMI IoT Hub - Push File Upload Handler
 * Endpoint: /pushfileupload
 * Versão: 2.0.0 (Alinhado com spec oficial - Seção 1.8)
 * Referência: Seção 1.8 - File Upload Notification from storage service
 */
define('HANDLER_NAME', 'pushfileupload');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';
require_once __DIR__ . '/../includes/occurrence_engine.php';

class PushFileUploadHandler extends WebhookHandler {

    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    protected function processItem($item) {
        $imei = $item['deviceImei'] ?? $item['imei'] ?? null;
        if (!$imei) {
            Logger::warning('FileUpload ignorado: IMEI ausente', [
                'source' => $this->handlerName, 'keys' => array_keys($item)
            ]);
            return false;
        }

        // Campos documentados: fileName, gateTime, result (SUCCESS/FAILURE)
        $fileNameRaw = $item['fileName'] ?? $item['file'] ?? null;
        $result      = $item['result'] ?? 'UNKNOWN';
        $rawTime     = $item['gateTime'] ?? $item['uploadTime'] ?? $item['time'] ?? null;
        $eventTime   = sanitize_date($rawTime);
        $channel     = $item['channel'] ?? $item['chnNo'] ?? $item['channelNumber'] ?? null;

        // fileName pode ser lista separada por ponto-e-vírgula
        $fileNames = $fileNameRaw ? explode(';', $fileNameRaw) : ['unknown'];

        $savedCount = 0;
        foreach ($fileNames as $fileName) {
            $fileName = trim($fileName);
            if (empty($fileName)) continue;

            $fileType = detect_media_type($fileName);

            try {
                $stmt = $this->db->prepare("
                    INSERT INTO media_files 
                    (imei, file_name, file_type, file_url, source_type, event_time, channel, download_status, raw_data)
                    VALUES (:imei, :fname, :ftype, :url, 'pushfileupload', :etime, :ch, :ds, :raw)
                ");
                $status = ($result === 'SUCCESS') ? 'disponivel' : 'erro';
                $stmt->execute([
                    ':imei'  => $imei,
                    ':fname' => $fileName,
                    ':ftype' => $fileType,
                    ':url'   => $fileName,
                    ':etime' => $eventTime,
                    ':ch'    => $channel,
                    ':ds'    => $status,
                    ':raw'   => json_encode($item, JSON_UNESCAPED_UNICODE)
                ]);
                $mediaId = (int)$this->db->lastInsertId();
                $savedCount++;

                // Vincular mídia a ocorrências abertas do mesmo IMEI (±3 min)
                if ($mediaId > 0 && $eventTime && $result === 'SUCCESS') {
                    link_upload_to_occurrence($this->db, $imei, $eventTime, $mediaId);
                }
            } catch (PDOException $e) {
                Logger::error('Erro SQL FileUpload: ' . $e->getMessage(), [
                    'source' => $this->handlerName, 'imei' => $imei, 'file' => $fileName
                ]);
            }
        }

        Logger::info('FileUpload processado', [
            'source' => $this->handlerName, 'imei' => $imei,
            'files' => $savedCount, 'result' => $result
        ]);

        return $savedCount > 0;
    }
}

$handler = new PushFileUploadHandler();
$handler->handle();
