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
require_once __DIR__ . '/../includes/occurrence_engine.php';

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
        $channel       = $item['channel'] ?? $item['chnNo'] ?? $item['channelNumber'] ?? null;

        // Fallback: firmwares antigos podem enviar fileUrl/filePath
        $fileName = $item['fileName'] ?? $item['file'] ?? $instructionID ?? 'unknown';
        $fileUrl  = $item['fileUrl'] ?? $item['filePath'] ?? $item['url'] ?? $fileName;
        $fileSize = $item['fileSize'] ?? $item['size'] ?? 0;
        $fileType = detect_media_type($fileName);

        try {
            $isSuccess = ($result === 0 || strtoupper((string)$result) === 'SUCCESS');
            $downloadStatus = $isSuccess ? 'disponivel' : 'erro';

            // ── Fecha o pedido de extração, em vez de duplicá-lo (v4.9.1) ────
            //
            // `sendcommand.php` grava uma linha `solicitado` quando o 37382 sai.
            // Sem este UPDATE, a chegada do arquivo criaria uma SEGUNDA linha e
            // a fila de /video/downloads ficaria com um "aguardando" eterno ao
            // lado do arquivo pronto — pior do que não ter fila nenhuma.
            //
            // Casa pelo `instructionID`, que a doc define como a chave de
            // correspondência entre comando e resposta — `sendcommand.php` o
            // gera e o guarda entre colchetes no `file_name` do pedido.
            //
            // O fallback ("pendente mais antigo do mesmo IMEI e canal") só vale
            // para firmware que não devolva o instructionID. Ele ERRA quando há
            // mais de um pedido em voo, e errou no teste em campo: um pedido que
            // tinha falhado por timeout foi fechado com o resultado de outro,
            // enviado depois. Por isso ele é o segundo caminho, não o primeiro.
            $mediaId = 0;
            $pendingId = 0;

            if ($instructionID) {
                $q = $this->db->prepare("
                    SELECT id FROM media_files
                     WHERE imei = :imei AND download_status = 'solicitado'
                       AND source_type = 'extracao_37382'
                       AND file_name LIKE :marca
                     ORDER BY id ASC LIMIT 1");
                $q->execute([':imei' => $imei, ':marca' => '%[' . $instructionID . ']%']);
                $pendingId = (int)($q->fetchColumn() ?: 0);
            }

            if ($pendingId === 0) {
                $pend = $this->db->prepare("
                    SELECT id FROM media_files
                    WHERE imei = :imei AND download_status = 'solicitado'
                      AND source_type = 'extracao_37382'
                      AND (:ch IS NULL OR channel IS NULL OR channel = :ch2)
                    ORDER BY id ASC LIMIT 1
                ");
                $pend->execute([':imei' => $imei, ':ch' => $channel, ':ch2' => $channel]);
                $pendingId = (int)($pend->fetchColumn() ?: 0);
                if ($pendingId > 0 && $instructionID) {
                    Logger::warning('FTP Upload casado por ordem, não por instructionID', [
                        'source' => $this->handlerName, 'imei' => $imei,
                        'instruction_id' => $instructionID, 'media_id' => $pendingId,
                    ]);
                }
            }

            // ── O callback NÃO diz qual arquivo é (v4.9.2) ───────────────────
            //
            // Medido com a câmera real: o corpo do /pushftpfileupload traz só
            // `imei`, `result`, `gateTime` e `instructionID`. Sem o nome, o
            // fallback lá em cima gravava o próprio instructionID como
            // `file_name`/`file_url` — e /midia respondia 404, porque no disco
            // o arquivo é `CH1_20260805_234207_W0300_000030.ts`.
            //
            // O nome que a câmera escolhe CODIFICA a janela que pedimos:
            // `CH{canal}_{AAAAMMDD}_{HHMMSS}_…`, com o mesmo carimbo GMT-0 do
            // `beginTime`. E essa janela está guardada no `event_time` do
            // pedido pendente. Daí a resolução por padrão de nome — determinística,
            // sem depender de varrer o diretório por data de modificação.
            if ($pendingId > 0 && empty($item['fileName']) && empty($item['file'])) {
                $achado = $this->resolverArquivoPeloPedido($pendingId);
                if ($achado !== null) {
                    $fileName = $achado['nome'];
                    $fileUrl  = $achado['nome'];
                    $fileType = detect_media_type($fileName);
                    if (!$fileSize) $fileSize = $achado['tamanho'];
                } else {
                    Logger::warning('FTP Upload sem nome de arquivo e sem correspondência no disco', [
                        'source' => $this->handlerName, 'imei' => $imei,
                        'instruction_id' => $instructionID, 'media_id' => $pendingId,
                    ]);
                }
            }

            if ($pendingId > 0) {
                // ⚠️ `event_time` do pedido é PRESERVADO de propósito (v4.9.2).
                //
                // Ele guarda o início da GRAVAÇÃO (a janela que se pediu); o
                // `gateTime` do callback é a hora em que o UPLOAD terminou —
                // hoje, não a data do vídeo. Sobrescrever fazia o arquivo
                // extraído sumir da linha do tempo do dia filtrado, que é
                // exatamente onde o usuário está olhando: /video/playback casa
                // `media_files.event_time` com o período da tela. A hora do
                // upload continua registrada em `raw_data.gateTime`.
                $this->db->prepare("
                    UPDATE media_files
                       SET file_name = :fname, file_type = :ftype, file_size = :fsize,
                           file_url = :url, source_type = 'pushftpfileupload',
                           event_time = COALESCE(event_time, :etime),
                           channel = COALESCE(:ch, channel),
                           download_status = :ds, raw_data = :raw
                     WHERE id = :id
                ")->execute([
                    ':fname' => $fileName, ':ftype' => $fileType, ':fsize' => $fileSize,
                    ':url'   => $fileUrl,  ':etime' => $eventTime, ':ch' => $channel,
                    ':ds'    => $downloadStatus,
                    ':raw'   => json_encode($item, JSON_UNESCAPED_UNICODE),
                    ':id'    => $pendingId,
                ]);
                $mediaId = $pendingId;
            } else {
                // Sem pedido pendente: upload que não nasceu desta tela (console
                // do IoTHub, anexo de alarme). Continua entrando como linha nova.
                // Snapshot do dono no momento do evento (Fase 2 do fluxo
                // chip→câmera→veículo) — ver resolve_installation_for_imei().
                $ownership = resolve_installation_for_imei($this->db, $imei);
                $stmt = $this->db->prepare("
                    INSERT INTO media_files
                    (imei, customer_id, vehicle_id, file_name, file_type, file_size, file_url, source_type, event_time, channel, download_status, raw_data)
                    VALUES (:imei, :cid, :vid, :fname, :ftype, :fsize, :url, 'pushftpfileupload', :etime, :ch, :ds, :raw)
                ");
                $stmt->execute([
                    ':imei'  => $imei,
                    ':cid'   => $ownership['customer_id'],
                    ':vid'   => $ownership['vehicle_id'],
                    ':fname' => $fileName,
                    ':ftype' => $fileType,
                    ':fsize' => $fileSize,
                    ':url'   => $fileUrl,
                    ':etime' => $eventTime,
                    ':ch'    => $channel,
                    ':ds'    => $downloadStatus,
                    ':raw'   => json_encode($item, JSON_UNESCAPED_UNICODE)
                ]);
                $mediaId = (int)$this->db->lastInsertId();
            }
            if ($mediaId > 0 && $eventTime && $downloadStatus === 'disponivel') {
                link_upload_to_occurrence($this->db, $imei, $eventTime, $mediaId);
            }

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

    /**
     * Descobre no disco o arquivo que a câmera acabou de subir para um pedido.
     *
     * A câmera nomeia a gravação com o canal e o INÍCIO da janela pedida
     * (`CH1_20260805_234207_W0300_000030.ts`), no mesmo carimbo GMT-0 que o
     * comando 37382 mandou — e esse instante está no `event_time` do pedido.
     * O sufixo depois do horário varia com o firmware (as gravações de maio não
     * têm o `W0300`), por isso o casamento é por PREFIXO.
     *
     * @param int $pendingId id da linha `solicitado` em media_files
     * @returns array{nome:string,tamanho:int}|null
     */
    private function resolverArquivoPeloPedido(int $pendingId): ?array
    {
        $dir = rtrim((string)(getenv('VIDEO_MEDIA_DIR') ?: '/iothub/dvr-upload/uploadFile'), '/');
        if (!is_dir($dir)) return null;

        $st = $this->db->prepare("SELECT channel, event_time FROM media_files WHERE id = :id");
        $st->execute([':id' => $pendingId]);
        $row = $st->fetch();
        if (!$row || empty($row['event_time'])) return null;

        $ts = strtotime((string)$row['event_time']);
        if ($ts === false) return null;

        $prefixo = sprintf('CH%d_%s_', (int)$row['channel'], gmdate('Ymd_His', $ts));
        $achados = glob($dir . '/' . $prefixo . '*') ?: [];
        if (!$achados) return null;

        // Mais de um casamento só acontece se a mesma janela foi pedida duas
        // vezes; o mais recente é o desta rodada.
        usort($achados, fn($a, $b) => filemtime($b) <=> filemtime($a));
        return ['nome' => basename($achados[0]), 'tamanho' => (int)filesize($achados[0])];
    }
}

$handler = new PushFtpFileUploadHandler();
$handler->handle();
