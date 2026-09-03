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
 *
 * 🔴 v4.14.1 — begin/end de um item do 37381 é a hora LOCAL da câmera
 * (UTC−3), NÃO GMT 0. `cleanDate()` tratava como já-UTC — a MESMA suposição
 * que a doc dava para o corpo do webhook em tempo real (gps_data, alarms),
 * mas que não vale para o carimbo de uma GRAVAÇÃO no cartão (ver o mesmo
 * achado, já corrigido, em `includes/filelist.php` para o FILELIST da JIMI).
 *
 * Medido em produção em 31/08/2026, 865478070654829 (JC371, veículo em
 * movimento): pedido um 37381 da janela real das últimas 4h (UTC de
 * verdade), o bloco mais recente devolvido tinha end_time 3h00m04s ATRÁS do
 * `NOW()` do servidor — se o campo já fosse UTC, o bloco mais recente de um
 * equipamento gravando AGORA estaria a poucos segundos do NOW(), não a 3h.
 * `cleanDate()` agora soma `FILELIST_OFFSET_SEGUNDOS` (a mesma constante do
 * FILELIST) antes de gravar — os dois protocolos passam a guardar
 * `resource_lists.start_time`/`end_time` em UTC de verdade, e a exibição em
 * `video_playback.php` (`pbLocal()`, que já fazia essa conversão para os
 * blocos JIMI) passa a acertar os dois.
 */

define('HANDLER_NAME', 'pushresourcelist');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';
require_once __DIR__ . '/../includes/filelist.php';   // FILELIST_OFFSET_SEGUNDOS

class PushResourceListHandler extends WebhookHandler {

    // O push da lista de recursos (doc §1.11: {imei, totalNum, instructionID,
    // resourceList}) pode chegar como objeto único, sem envelope data_list —
    // sem esta flag o corpo seria descartado como "empty data".
    protected $allowSingleObjectPayload = true;

    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    protected function processItem($item) {

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
            // totalNum=0 = câmera respondeu "nada gravado na janela"; sem a chave
            // resourceList = formato inesperado — as keys diagnosticam qual caso é
            Logger::info('Nenhuma lista de mídia encontrada', [
                'imei' => $imei,
                'totalNum' => $item['totalNum'] ?? $item['msg']['totalNum'] ?? null,
                'keys' => array_keys($item)
            ]);
            return true;
        }

        // 4. Processamento com Geração de Nomes
        $inserted = 0;
        $duplicates = 0;
        $errors = 0;

        // Instante ÚNICO desta listagem — ver a nota no execute() abaixo.
        $capturaEm = gmdate('Y-m-d H:i:s');

        // Prepara query fora do loop
        try {
            // ⚠️ v4.9.17 — `captured_at` é o que dá VALIDADE à lista, e por isso
            // o ON DUPLICATE é obrigatório: antes, arquivo já conhecido caía no
            // catch de duplicidade e era só contado. Agora, uma listagem nova
            // que reporta o MESMO arquivo tem de renovar a marca de tempo —
            // senão o arquivo continuaria "vencido" logo depois de a câmera
            // acabar de confirmar que ele existe.
            //
            // Sem VALUES() nem alias de linha: os dois têm ressalvas de versão
            // no MySQL 8. Repetir o placeholder com outro nome funciona em
            // qualquer versão e não depende de EMULATE_PREPARES.
            $sql = "INSERT INTO resource_lists (
                        imei, resource_type, file_name, file_size,
                        start_time, end_time, channel_id, alarm_type,
                        created_at, captured_at
                    ) VALUES (
                        :imei, :res_type, :fname, :fsize,
                        :start, :end, :chan, :alarm,
                        NOW(), :captured
                    )
                    ON DUPLICATE KEY UPDATE
                        captured_at   = :captured2,
                        resource_type = :res_type2,
                        file_size     = :fsize2,
                        start_time    = :start2,
                        end_time      = :end2,
                        alarm_type    = :alarm2";
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
                    // Uma listagem = UM instante. `$capturaEm` é calculado fora
                    // do laço (mais abaixo, no início do processItem) para que
                    // todos os arquivos da mesma resposta compartilhem a marca
                    // — se cada linha usasse NOW(), uma lista de 800 arquivos
                    // ficaria espalhada por segundos e a "idade da captura"
                    // dependeria de qual linha se olhasse.
                    $stmt->execute([
                        ':imei'      => $imei,
                        ':res_type'  => $resType,
                        ':fname'     => $fileName,
                        ':fsize'     => $fileSize,
                        ':start'     => $startTime,
                        ':end'       => $endTime,
                        ':chan'      => $channelId,
                        ':alarm'     => $alarmType,
                        ':captured'  => $capturaEm,
                        ':captured2' => $capturaEm,
                        ':res_type2' => $resType,
                        ':fsize2'    => $fileSize,
                        ':start2'    => $startTime,
                        ':end2'      => $endTime,
                        ':alarm2'    => $alarmType,
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
                'duplicados' => $duplicates,
                'erros' => $errors
            ]);
            
            return true;

        } catch (Exception $e) {
            Logger::error("Erro Geral", ['msg' => $e->getMessage()]);
            return false;
        }
    }

    private function cleanDate($dateInput) {
        if (empty($dateInput)) return null;

        $ts = null;
        if (is_numeric($dateInput)) {
            $v = $dateInput;
            if (strlen((string)$v) > 11) $v = $v / 1000;
            $ts = (int)$v;
        } else {
            $dt = date_create((string)$dateInput, new DateTimeZone('UTC'));
            $ts = $dt ? $dt->getTimestamp() : null;
        }
        if ($ts === null) return null;

        // 🔴 Hora LOCAL da câmera (UTC−3), não GMT 0 — ver a nota medida no
        // cabeçalho do arquivo. `FILELIST_OFFSET_SEGUNDOS` é a MESMA
        // constante que `includes/filelist.php` usa para o carimbo do nome
        // de arquivo da JIMI; a regra tem uma casa só.
        return gmdate('Y-m-d H:i:s', $ts + FILELIST_OFFSET_SEGUNDOS);
    }

    private function mapResourceType($val) {
        // Semântica do 0x1205 (doc §1.11 / JT/T 1078): 0=áudio e vídeo,
        // 1=áudio, 2=vídeo, 3=vídeo ou áudio e vídeo. (0 NÃO é imagem — esse
        // é o código do multimídia 0x0800, que não passa por este push.)
        $map = [0 => 'video', 1 => 'audio', 2 => 'video', 3 => 'video'];
        return $map[$val] ?? 'other';
    }
}

$handler = new PushResourceListHandler();
$handler->handle();