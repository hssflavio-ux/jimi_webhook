<?php
/**
 * Motor de Ocorrências v4.0.0
 *
 * Peça central do DMS. Processa um alarme recebido via pushalarm.php
 * e decide se gera ou agrupa uma ocorrência com base no occurrence_config
 * do cliente do device.
 *
 * Chamado dentro de pushalarm.php após o INSERT do alarme.
 *
 * Algoritmo (PROJETO_YUV.md §7.1):
 *   1. Busca o occurrence_config do customer do device (fallback: default)
 *   2. Busca o parâmetro (occurrence_config_param) para o tipo de alarme
 *   3. Se param.generates_occurrence == 0, retorna (não gera ocorrência)
 *   4. Verifica janela de agrupamento (dedup por IMEI+tipo+status 'aguardando')
 *   5. Se existe ocorrência aberta dentro da janela: incrementa contagem e atualiza
 *   6. Senão: cria nova ocorrência com risco do perfil
 *
 * @param array $alarm  Dados do alarme (já normalized + inserted)
 *                       Campos esperados: imei, alarm_type, alarm_time, driver_id (opcional)
 * @return int|null     ID da ocorrência criada/atualizada, ou null se nenhuma
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/iothub_command.php';

define('OCCURRENCE_DEFAULT_WINDOW_MINUTES', 10);

/**
 * Processa um alarme e gera/agrupa ocorrência conforme regras do cliente.
 *
 * @param array $alarm Dados do alarme com chaves: imei, alarm_type, alarm_time
 * @return int|null
 */
function process_alarm_to_occurrence(array $alarm): ?int
{
    if (empty($alarm['imei']) || empty($alarm['alarm_type'])) {
        return null;
    }

    $db = Database::getInstance()->getConnection();

    $imei = $alarm['imei'];
    $alarmType = $alarm['alarm_type'];
    $alarmName = $alarm['alarm_name'] ?? $alarmType;
    $alarmTime = $alarm['alarm_time'] ?? date('Y-m-d H:i:s');

    $configId = get_occurrence_config_for_imei($db, $imei);
    if (!$configId) {
        return null;
    }

    $param = get_occurrence_param($db, $configId, $alarmType, $alarmName);
    if (!$param || empty($param['generates_occurrence'])) {
        return null;
    }

    $risk = $param['risk'] ?? 'baixo';
    $threshold = !empty($param['threshold']) ? (int)$param['threshold'] : OCCURRENCE_DEFAULT_WINDOW_MINUTES;

    $occType = $alarmName;

    $alarmId = $alarm['id'] ?? $alarm['alarm_id'] ?? null;

    $openOccurrence = find_open_occurrence($db, $imei, $occType, $threshold, $alarmTime);
    if ($openOccurrence) {
        update_occurrence($db, (int)$openOccurrence['id'], $alarmTime, $alarmId, $alarm);
        return (int)$openOccurrence['id'];
    }

    $customerId = get_customer_id_for_imei($db, $imei);
    $branchId = get_branch_id_for_imei($db, $imei);
    $driverId = $alarm['driver_id'] ?? null;

    $mediaId = link_media_to_occurrence($db, $imei, $alarmTime, $alarm);

    $occId = create_occurrence($db, $customerId, $branchId, $imei, $driverId, $occType, $risk, $alarmTime, $alarmId, $mediaId);

    // Gatilho automático de vídeo do evento: ocorrência nova sem mídia vinculada
    // em câmera JT/T → agenda o upload do ANEXO do alarme (proNo 37384 =
    // Alarm Attachment Upload, doc §2.20) usando o alarmLabel que veio no push.
    // O despacho HTTP acontece FORA da transação do webhook, via
    // flush_pending_video_requests() no fim do pushalarm.php.
    if ($occId && $mediaId === null) {
        queue_event_video_request($db, $imei, $alarmTime, $occId, $alarm['alarm_label'] ?? null);
    }

    return $occId;
}

function get_occurrence_config_for_imei(PDO $db, string $imei): ?int
{
    $stmt = $db->prepare(
        "SELECT COALESCE(c.occurrence_config_id,
                (SELECT id FROM occurrence_configs WHERE is_default = 1 LIMIT 1)) AS config_id
         FROM devices d
         LEFT JOIN customers c ON c.id = d.customer_id
         WHERE d.imei = :imei"
    );
    $stmt->execute([':imei' => $imei]);
    $row = $stmt->fetch();
    return $row ? (int)$row['config_id'] : null;
}

function get_occurrence_param(PDO $db, int $configId, string $alarmType, string $alarmName = ''): ?array
{
    $stmt = $db->prepare(
        "SELECT ocp.generates_occurrence, ocp.risk, ocp.threshold
         FROM occurrence_config_params ocp
         LEFT JOIN alarm_types at ON (
            at.alarm_name_pt = ocp.alarm_type
            OR at.alarm_name_en = ocp.alarm_type
            OR at.category = ocp.alarm_type
         )
         WHERE ocp.config_id = :cid
           AND (
               ocp.alarm_type = :atype
               OR ocp.alarm_type = :aname
               OR at.alarm_code = :atype2
           )
         LIMIT 1"
    );
    $stmt->execute([
        ':cid'   => $configId,
        ':atype' => $alarmType,
        ':aname' => $alarmName,
        ':atype2' => $alarmType,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_open_occurrence(PDO $db, string $imei, string $alarmType, int $thresholdMinutes, string $alarmTime): ?array
{
    $stmt = $db->prepare(
        "SELECT id, alarm_count FROM occurrences
         WHERE imei = :imei
           AND alarm_type = :atype
           AND status = 'aguardando'
           AND last_alarm_at >= DATE_SUB(:atime, INTERVAL :tmin MINUTE)
         LIMIT 1"
    );
    $stmt->execute([
        ':imei' => $imei,
        ':atype' => $alarmType,
        ':atime' => $alarmTime,
        ':tmin' => $thresholdMinutes,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function update_occurrence(PDO $db, int $occId, string $alarmTime, $alarmId, array $alarm): void
{
    $stmt = $db->prepare(
        "UPDATE occurrences
         SET alarm_count = alarm_count + 1,
             last_alarm_at = GREATEST(last_alarm_at, :atime)
         WHERE id = :id"
    );
    $stmt->execute([':atime' => $alarmTime, ':id' => $occId]);

    if ($alarmId) {
        $stmt = $db->prepare("INSERT IGNORE INTO occurrence_events (occurrence_id, alarm_id) VALUES (:oid, :aid)");
        $stmt->execute([':oid' => $occId, ':aid' => $alarmId]);
    }
}

function create_occurrence(PDO $db, ?int $customerId, ?int $branchId, string $imei, $driverId, string $alarmType, string $risk, string $alarmTime, $alarmId, $mediaFileId = null): ?int
{
    $stmt = $db->prepare(
        "INSERT INTO occurrences
         (customer_id, branch_id, imei, driver_id, alarm_type, risk,
          status, first_alarm_at, last_alarm_at, alarm_count, media_file_id)
         VALUES
         (:cid, :bid, :imei, :did, :atype, :risk,
          'aguardando', :fat, :lat, 1, :mid)"
    );
    $stmt->execute([
        ':cid'   => $customerId,
        ':bid'   => $branchId,
        ':imei'  => $imei,
        ':did'   => $driverId,
        ':atype' => $alarmType,
        ':risk'  => $risk,
        ':fat'   => $alarmTime,
        ':lat'   => $alarmTime,
        ':mid'   => $mediaFileId,
    ]);
    $occId = (int)$db->lastInsertId();

    if ($alarmId) {
        $stmt = $db->prepare("INSERT IGNORE INTO occurrence_events (occurrence_id, alarm_id) VALUES (:oid, :aid)");
        $stmt->execute([':oid' => $occId, ':aid' => $alarmId]);
    }

    return $occId;
}

/**
 * Tenta vincular um arquivo de mídia (upload de vídeo) a uma ocorrência
 * próxima no tempo (±3 min), para o mesmo IMEI.
 */
function link_media_to_occurrence(PDO $db, string $imei, string $alarmTime, array $alarm): ?int
{
    $fileUrl = $alarm['file_url'] ?? null;
    if (!$fileUrl) {
        return null;
    }

    $stmt = $db->prepare(
        "SELECT id FROM media_files
         WHERE imei = :imei
           AND file_url = :url
         LIMIT 1"
    );
    $stmt->execute([':imei' => $imei, ':url' => $fileUrl]);
    $row = $stmt->fetch();
    return $row ? (int)$row['id'] : null;
}

function get_customer_id_for_imei(PDO $db, string $imei): ?int
{
    $stmt = $db->prepare("SELECT customer_id FROM devices WHERE imei = :imei LIMIT 1");
    $stmt->execute([':imei' => $imei]);
    $row = $stmt->fetch();
    return $row ? ($row['customer_id'] ? (int)$row['customer_id'] : null) : null;
}

function get_branch_id_for_imei(PDO $db, string $imei): ?int
{
    $stmt = $db->prepare("SELECT branch_id FROM devices WHERE imei = :imei LIMIT 1");
    $stmt->execute([':imei' => $imei]);
    $row = $stmt->fetch();
    return $row && $row['branch_id'] ? (int)$row['branch_id'] : null;
}

/**
 * Vincula um upload de mídia à ocorrência DONA do anexo, resolvendo o
 * alarmLabel (extraído do fileName {imei}_{alarmLabel}_{xy}.ext) até o
 * alarme (alarms.alarm_label) e daí à ocorrência via occurrence_events.
 *
 * Preferência de mídia: preenche media_file_id vazio; se a ocorrência já
 * tem uma imagem vinculada e chega o VÍDEO do mesmo anexo, o vídeo assume.
 *
 * @param PDO    $db       Conexão ativa
 * @param string $imei     IMEI do device
 * @param string $label    alarmLabel extraído do nome do arquivo
 * @param int    $mediaId  media_files.id recém-inserido
 * @param string $fileType Tipo detectado ('video', 'image', …)
 * @return int|null ID da ocorrência vinculada, ou null se não resolvida
 */
function link_upload_by_alarm_label(PDO $db, string $imei, string $label, int $mediaId, string $fileType): ?int
{
    $stmt = $db->prepare(
        "SELECT o.id, o.media_file_id, mf.file_type AS linked_type
         FROM alarms a
         JOIN occurrence_events e ON e.alarm_id = a.id
         JOIN occurrences o ON o.id = e.occurrence_id
         LEFT JOIN media_files mf ON mf.id = o.media_file_id
         WHERE a.imei = :imei
           AND a.alarm_label = :label
         ORDER BY o.id DESC
         LIMIT 1"
    );
    $stmt->execute([':imei' => $imei, ':label' => $label]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $occId = (int)$row['id'];
    $shouldLink = $row['media_file_id'] === null
        || ($fileType === 'video' && $row['linked_type'] !== 'video');

    if ($shouldLink) {
        $stmt = $db->prepare("UPDATE occurrences SET media_file_id = :mid WHERE id = :oid");
        $stmt->execute([':mid' => $mediaId, ':oid' => $occId]);
        Logger::info('Mídia vinculada à ocorrência pelo alarmLabel', [
            'imei' => $imei, 'media_id' => $mediaId,
            'occurrence_id' => $occId, 'alarm_label' => $label,
        ]);
    }
    return $occId;
}

/**
 * Vincula um upload de mídia (arquivo de vídeo) a uma ocorrência aberta
 * para o mesmo IMEI, dentro de uma janela de ±3 minutos.
 * Fallback para uploads sem alarmLabel no nome do arquivo.
 * Chamado por pushfileupload / pushftpfileupload após INSERT do media_file.
 */
function link_upload_to_occurrence(PDO $db, string $imei, string $eventTime, int $mediaId): ?int
{
    $stmt = $db->prepare(
        "SELECT id FROM occurrences
         WHERE imei = :imei
           AND status = 'aguardando'
           AND media_file_id IS NULL
           AND last_alarm_at BETWEEN DATE_SUB(:etime, INTERVAL 3 MINUTE)
                                 AND DATE_ADD(:etime, INTERVAL 3 MINUTE)
         ORDER BY last_alarm_at DESC
         LIMIT 1"
    );
    $stmt->execute([':imei' => $imei, ':etime' => $eventTime]);
    $row = $stmt->fetch();

    if ($row) {
        $occId = (int)$row['id'];
        $stmt = $db->prepare("UPDATE occurrences SET media_file_id = :mid WHERE id = :oid");
        $stmt->execute([':mid' => $mediaId, ':oid' => $occId]);
        Logger::info('Mídia vinculada à ocorrência', [
            'imei' => $imei,
            'media_id' => $mediaId,
            'occurrence_id' => $occId,
        ]);
        return $occId;
    }
    return null;
}

/**
 * Agenda a solicitação automática do vídeo do evento para o device de uma
 * ocorrência recém-criada, via proNo 37384 (0x9208, Alarm Attachment Upload).
 *
 * Por que 37384: em câmeras JT/T (JC371/JC450/JC181…) o vídeo do evento
 * DMS/ADAS é um ANEXO do alarme identificado pelo alarmLabel que veio no
 * próprio push (doc "Alarm File Name" + §2.20). O device sobe o(s) arquivo(s)
 * para o attachment server do IoTHub (porta 21188) e o storage notifica via
 * /pushfileupload com fileName {imei}_{alarmLabel}_{xy}.mp4/.jpg.
 * O antigo 34818 (0x8802) apenas CONSULTA a multimídia 808 (fotos do 34817)
 * — em evento DMS retorna mediaItemsNum:0 e nenhum upload acontece.
 *
 * Apenas AGENDA (fila em memória do request): o despacho HTTP ao IoTHub segura
 * a resposta por até 35s e não pode rodar dentro da transação do webhook —
 * quem envia é flush_pending_video_requests(), chamado pós-commit.
 *
 * Elegibilidade: device com modelo de protocolo JTT e camera_count >= 1
 * (37384 é instrução JT/T — devices JIMI sobem o vídeo sozinhos e o alarme
 * já chega com `file`, ADR-001). Requer alarmLabel válido no push.
 * Kill-switch: AUTO_VIDEO_REQUEST=0 no .env.
 * Overrides .env: ATTACH_UPLOAD_IP / ATTACH_UPLOAD_PORT (default: IP de
 * ingest do vídeo — video_stream_config() — e porta 21188).
 *
 * @param PDO         $db           Conexão ativa
 * @param string      $imei         IMEI do device
 * @param string      $alarmTime    Hora do alarme (UTC, formato Y-m-d H:i:s)
 * @param int         $occurrenceId Ocorrência que motivou a solicitação (para log)
 * @param string|null $alarmLabel   alarmLabel do push do alarme (32 hex chars)
 * @returns void
 */
function queue_event_video_request(PDO $db, string $imei, string $alarmTime, int $occurrenceId, ?string $alarmLabel = null): void
{
    // Kill-switch: '0' é falsy em PHP — comparar explicitamente, sem `?:`
    $autoFlag = getenv('AUTO_VIDEO_REQUEST');
    if ($autoFlag !== false && trim($autoFlag) === '0') {
        return;
    }

    try {
        $stmt = $db->prepare(
            "SELECT dm.protocol, dm.camera_count
             FROM devices d
             JOIN device_models dm ON dm.id = d.device_model_id
             WHERE d.imei = :imei
             LIMIT 1"
        );
        $stmt->execute([':imei' => $imei]);
        $model = $stmt->fetch();
    } catch (Exception $e) {
        Logger::error('Auto-vídeo: falha ao consultar modelo do device', [
            'imei' => $imei, 'error' => $e->getMessage(),
        ]);
        return;
    }

    if (!$model || $model['protocol'] !== 'JTT' || (int)$model['camera_count'] < 1) {
        return;
    }

    // Sem alarmLabel não há anexo endereçável — alarmes sem mídia associada
    // (ex.: ignição, ociosidade) caem aqui e não geram solicitação.
    $alarmLabel = trim((string)$alarmLabel);
    if ($alarmLabel === '' || strlen($alarmLabel) < 16 || !ctype_xdigit($alarmLabel)) {
        Logger::info('Auto-vídeo: alarme sem alarmLabel de anexo — solicitação não enviada', [
            'imei' => $imei, 'occurrence_id' => $occurrenceId, 'alarm_label' => $alarmLabel ?: null,
        ]);
        return;
    }

    // alarmNumber (doc §2.20): ascii-hex de [14 últimos dígitos do IMEI] +
    // [cauda do alarmLabel após os 14 hex do terminal-ID: hora compacta +
    // sequência + qtde de anexos + reservado]. Ex. validado na doc §1.13.
    $alarmNumber = bin2hex(substr($imei, -14) . substr($alarmLabel, 14));

    // Endereço do attachment server QUE O DEVICE ALCANÇA (não o do navegador):
    // mesmo IP de ingest do vídeo ao vivo; porta 21188 = jimi-tracker-upload-process
    $vsc  = video_stream_config();
    $ip   = getenv('ATTACH_UPLOAD_IP') ?: $vsc['ingest_ip'];
    $port = (int)(getenv('ATTACH_UPLOAD_PORT') ?: 21188);

    $content = json_encode([
        'serverLen'     => strlen($ip),
        'serverAddress' => $ip,
        'tcpPort'       => $port,
        'udpPort'       => 0,
        'alarmLabel'    => $alarmLabel,
        'alarmNumber'   => $alarmNumber,
    ], JSON_UNESCAPED_SLASHES);

    $GLOBALS['_pending_video_requests'][] = [
        'imei'          => $imei,
        'pro_no'        => 37384,
        'content'       => $content,
        'alarm_label'   => $alarmLabel,
        'occurrence_id' => $occurrenceId,
    ];
}

/**
 * Despacha as solicitações de vídeo agendadas por queue_event_video_request().
 *
 * DEVE ser chamado FORA da transação do webhook (pós handle()/commit) — o
 * IoTHub pode segurar a resposta HTTP por até 35s aguardando o device, e esse
 * tempo não pode estender locks de alarms/occurrences.
 *
 * Guardas anti-rajada:
 *   - dedupe por alarmLabel: o mesmo anexo nunca é solicitado 2x em 10 min
 *     (retransmissão de alarme / agrupamento gera o mesmo label);
 *   - teto de 5 solicitações automáticas por device a cada 2 minutos
 *     (cada anexo é único por alarme — suprimir demais perderia vídeos).
 *
 * @returns void
 */
function flush_pending_video_requests(): void
{
    $pending = $GLOBALS['_pending_video_requests'] ?? [];
    if (empty($pending)) {
        return;
    }
    $GLOBALS['_pending_video_requests'] = [];

    try {
        $db = Database::getInstance()->getConnection();
    } catch (Exception $e) {
        return;
    }

    foreach ($pending as $req) {
        try {
            if (!empty($req['alarm_label'])) {
                $stmt = $db->prepare(
                    "SELECT COUNT(*) FROM commands
                     WHERE imei = :imei
                       AND operator = 'auto_video'
                       AND command_content LIKE :label
                       AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
                );
                $stmt->execute([
                    ':imei'  => $req['imei'],
                    ':label' => '%' . $req['alarm_label'] . '%',
                ]);
                if ((int)$stmt->fetchColumn() > 0) {
                    Logger::info('Auto-vídeo: pulado (anexo já solicitado)', [
                        'imei' => $req['imei'], 'occurrence_id' => $req['occurrence_id'],
                        'alarm_label' => $req['alarm_label'],
                    ]);
                    continue;
                }
            }

            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM commands
                 WHERE imei = :imei
                   AND operator = 'auto_video'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
            );
            $stmt->execute([':imei' => $req['imei']]);
            if ((int)$stmt->fetchColumn() >= 5) {
                Logger::info('Auto-vídeo: pulado (anti-rajada: 5 em 2 min)', [
                    'imei' => $req['imei'], 'occurrence_id' => $req['occurrence_id'],
                ]);
                continue;
            }

            $proNo  = (int)($req['pro_no'] ?? 37384);
            $result = iothub_dispatch_command($req['imei'], $proNo, $req['content'], [
                'operator' => 'auto_video',
                'timeout'  => (int)(getenv('AUTO_VIDEO_TIMEOUT') ?: 35),
            ]);

            Logger::info("Auto-vídeo: {$proNo} despachado", [
                'imei'          => $req['imei'],
                'occurrence_id' => $req['occurrence_id'],
                'alarm_label'   => $req['alarm_label'] ?? null,
                'command_id'    => $result['command_id'],
                'status'        => $result['status'],
                'offline_queued'=> $result['offline_queued'],
            ]);
        } catch (Exception $e) {
            Logger::error('Auto-vídeo: falha no despacho', [
                'imei' => $req['imei'], 'error' => $e->getMessage(),
            ]);
        }
    }
}
