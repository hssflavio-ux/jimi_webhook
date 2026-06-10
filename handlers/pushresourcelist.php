<?php
/**
 * JIMI IoT Hub - Handler de Lista de Recursos
 * Endpoint: /pushresourcelist
 * Versão: 2.0.0 (Estratégia de Nomenclatura Automática)
 *
 * CORREÇÕES:
 * 1. Gera nomes de arquivos sintéticos (REC_IMEI_CH_DATA.ts) quando o dispositivo 
 *    envia apenas segmentos de tempo (comum em novos firmwares).
 * 2. Mantém a robustez de inserção da versão anterior.
 */

define('HANDLER_NAME', 'pushresourcelist');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushResourceListHandler extends WebhookHandler {

    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    protected function processItem($item) {
        $item = normalize_data($item);

        // 1. Decodificação de Payload (String JSON dentro de POST)
        if (isset($item['data_list']) && is_string($item['data_list'])) {
            $decodedList = json_decode($item['data_list'], true);
            if (is_array($decodedList)) {
                $firstPayload = current($decodedList);
                $item = array_merge($item, $firstPayload);
                if (isset($firstPayload['msg'])) {
                    $item['msg'] = $firstPayload['msg'];
                }
            }
        }

        // 2. Extração de IMEI
        $imei = $item['deviceImei'] ?? $item['imei'] ?? $item['msg']['deviceImei'] ?? null;
        if (!$imei) {
            Logger::warning('IMEI ausente', ['keys' => array_keys($item)]);
            return false;
        }

        // 3. Extração da Lista
        $resourceList = [];
        if (isset($item['msg']['resourceList'])) $resourceList = $item['msg']['resourceList'];
        elseif (isset($item['resourceList'])) $resourceList = $item['resourceList'];
        elseif (isset($item['msg']['fileList'])) $resourceList = $item['msg']['fileList'];
        elseif (isset($item['fileList'])) $resourceList = $item['fileList'];
        elseif (isset($item['msg']['file'])) $resourceList = [$item['msg']];

        if (empty($resourceList) || !is_array($resourceList)) {
            Logger::info('Nenhuma lista de mídia encontrada', ['imei' => $imei]);
            return true; 
        }

        // 4. Processamento com Geração de Nomes
        $inserted = 0;
        $duplicates = 0;
        $errors = 0;

        // Prepara query fora do loop
        try {
            $sql = "INSERT INTO resource_lists (
                        imei, resource_type, file_name, file_size, 
                        start_time, end_time, channel_id, alarm_type, 
                        created_at
                    ) VALUES (
                        :imei, :res_type, :fname, :fsize,
                        :start, :end, :chan, :alarm,
                        NOW()
                    )";
            $stmt = $this->db->prepare($sql);
            
            $globalType = $item['msg']['resourceType'] ?? $item['resourceType'] ?? null;

            foreach ($resourceList as $file) {
                $f = array_change_key_case((array)$file, CASE_LOWER);

                // --- EXTRAÇÃO DE DADOS ---
                $rTypeVal = $f['resourcetype'] ?? $globalType;
                $resType  = $this->mapResourceType($rTypeVal);

                $startTime = $this->cleanDate($f['begintime'] ?? $f['starttime'] ?? $f['gpstime'] ?? null);
                $endTime   = $this->cleanDate($f['endtime'] ?? null);

                $fileSize  = intval($f['filesize'] ?? $f['size'] ?? 0);
                $channelId = intval($f['channel'] ?? $f['channelid'] ?? 0);
                $alarmType = substr($f['alarmflag'] ?? $f['alarmtype'] ?? $f['alerttype'] ?? '', 0, 49);

                // --- ESTRATÉGIA DE NOME DE ARQUIVO (A CORREÇÃO) ---
                $fileName = $f['filename'] ?? $f['name'] ?? $f['file'] ?? $f['path'] ?? null;

                // Se não vier nome, geramos um nome padrão baseado no tempo
                if (!$fileName) {
                    if ($startTime) {
                        // Formato: REC_IMEI_CH_ANOMESDIAHORAMINSEGUNDO.ts
                        $timeTag = date('YmdHis', strtotime($startTime));
                        $fileName = "REC_{$imei}_{$channelId}_{$timeTag}.ts";
                    } else {
                        // Sem nome e sem hora? Ignora.
                        continue;
                    }
                }

                try {
                    $stmt->execute([
                        ':imei'     => $imei,
                        ':res_type' => $resType,
                        ':fname'    => $fileName,
                        ':fsize'    => $fileSize,
                        ':start'    => $startTime,
                        ':end'      => $endTime,
                        ':chan'     => $channelId,
                        ':alarm'    => $alarmType
                    ]);
                    $inserted++;

                } catch (PDOException $ex) {
                    // 23000 = Duplicidade (Já existe esse arquivo/horário)
                    if ($ex->getCode() == '23000') {
                        $duplicates++;
                    } else {
                        $errors++;
                        Logger::error("FALHA SQL", ['file' => $fileName, 'err' => $ex->getMessage()]);
                    }
                }
            }

            Logger::info("Sincronização Mídia", [
                'imei' => $imei,
                'total' => count($resourceList),
                'gravados' => $inserted,
                'duplicados' => $duplicates
            ]);
            
            return true;

        } catch (Exception $e) {
            Logger::error("Erro Geral", ['msg' => $e->getMessage()]);
            return false;
        }
    }

    private function cleanDate($dateInput) {
        if (empty($dateInput)) return null;
        if (is_numeric($dateInput)) {
            if (strlen((string)$dateInput) > 11) $dateInput = $dateInput / 1000;
            return date('Y-m-d H:i:s', (int)$dateInput);
        }
        $ts = strtotime($dateInput);
        return ($ts && $ts > 0) ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function mapResourceType($val) {
        $map = [0 => 'image', 1 => 'audio', 2 => 'video', 3 => 'video'];
        return $map[$val] ?? 'other';
    }
}

$handler = new PushResourceListHandler();
$handler->handle();