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

            // Anexos de alarme JT/T chegam nomeados {imei}_{alarmLabel}_{xy}.ext
            // (doc §1.8): x = canal, y = sequência do arquivo. O label liga o
            // arquivo ao alarme dono (alarms.alarm_label) e daí à ocorrência.
            $alarmLabel  = null;
            $fileChannel = $channel;
            if (preg_match('/^\d{15,17}_([0-9A-Fa-f]{16,40})_(\d)(\d+)\.[A-Za-z0-9]+$/', $fileName, $m)) {
                $alarmLabel = $m[1];
                if ($fileChannel === null && (int)$m[2] > 0) {
                    $fileChannel = (int)$m[2];
                }
            }

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
                    ':ch'    => $fileChannel,
                    ':ds'    => $status,
                    ':raw'   => json_encode($item, JSON_UNESCAPED_UNICODE)
                ]);
                $mediaId = (int)$this->db->lastInsertId();
                $savedCount++;

                // Vincular mídia à ocorrência: 1º pelo alarmLabel (preciso);
                // fallback: janela ±3 min do último alarme do mesmo IMEI
                if ($mediaId > 0 && $result === 'SUCCESS') {
                    $linked = null;
                    if ($alarmLabel) {
                        $linked = link_upload_by_alarm_label($this->db, $imei, $alarmLabel, $mediaId, $fileType);
                    }
                    if ($linked === null && $eventTime) {
                        link_upload_to_occurrence($this->db, $imei, $eventTime, $mediaId);
                    }
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
