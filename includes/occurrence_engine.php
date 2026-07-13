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
    // em câmera JT/T → agenda solicitação de upload da multimídia armazenada
    // (proNo 34818). O despacho HTTP acontece FORA da transação do webhook,
    // via flush_pending_video_requests() no fim do pushalarm.php.
    if ($occId && $mediaId === null) {
        queue_event_video_request($db, $imei, $alarmTime, $occId);
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
 * Vincula um upload de mídia (arquivo de vídeo) a uma ocorrência aberta
 * para o mesmo IMEI, dentro de uma janela de ±3 minutos.
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
 * Agenda a solicitação automática do vídeo do evento (proNo 34818 = 0x8802,
 * multimídia armazenada) para o device de uma ocorrência recém-criada.
 *
 * Apenas AGENDA (fila em memória do request): o despacho HTTP ao IoTHub segura
 * a resposta por até 35s e não pode rodar dentro da transação do webhook —
 * quem envia é flush_pending_video_requests(), chamado pós-commit.
 *
 * Elegibilidade: device com modelo de protocolo JTT e camera_count >= 1
 * (34818 é instrução JT/T — devices JIMI ficam de fora, ADR-001).
 * Kill-switch: AUTO_VIDEO_REQUEST=0 no .env.
 *
 * @param PDO    $db           Conexão ativa
 * @param string $imei         IMEI do device
 * @param string $alarmTime    Hora do alarme (UTC, formato Y-m-d H:i:s)
 * @param int    $occurrenceId Ocorrência que motivou a solicitação (para log)
 * @returns void
 */
function queue_event_video_request(PDO $db, string $imei, string $alarmTime, int $occurrenceId): void
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

    try {
        $t = new DateTime($alarmTime, new DateTimeZone('UTC'));
    } catch (Exception $e) {
        return;
    }

    // Janela do evento em GMT-0 no formato compacto JT/T (yyMMddHHmmss) —
    // mesma convenção validada em video_playback.php
    $win   = max(10, (int)(getenv('AUTO_VIDEO_WINDOW_SECS') ?: 60));
    $begin = (clone $t)->modify("-{$win} seconds")->format('ymdHis');
    $end   = (clone $t)->modify("+{$win} seconds")->format('ymdHis');

    // 0 = todos os canais (a mídia chega com o canal real no pushfileupload,
    // que é o que a tela de playback usa para filtrar)
    $channel = (int)(getenv('AUTO_VIDEO_CHANNEL') ?: 0);

    // channel + channelId: firmwares/exemplos divergem no nome do campo
    // (video_playback usa channel; presets 37381/37382 usam channelId).
    // O IoTHub ignora chaves extras — enviar ambos cobre as duas variantes.
    $content = json_encode([
        'mediaType' => 2,
        'channel'   => $channel,
        'channelId' => $channel,
        'eventCode' => 0,
        'beginTime' => $begin,
        'endTime'   => $end,
    ], JSON_UNESCAPED_SLASHES);

    $GLOBALS['_pending_video_requests'][] = [
        'imei'          => $imei,
        'content'       => $content,
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
 * Guarda anti-rajada: no máximo 1 solicitação automática por device a cada
 * 2 minutos (rajadas de alarmes de tipos diferentes criam várias ocorrências
 * com janelas de vídeo sobrepostas — uma solicitação cobre todas).
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
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM commands
                 WHERE imei = :imei
                   AND operator = 'auto_video'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)"
            );
            $stmt->execute([':imei' => $req['imei']]);
            if ((int)$stmt->fetchColumn() > 0) {
                Logger::info('Auto-vídeo: pulado (anti-rajada 2 min)', [
                    'imei' => $req['imei'], 'occurrence_id' => $req['occurrence_id'],
                ]);
                continue;
            }

            $result = iothub_dispatch_command($req['imei'], 34818, $req['content'], [
                'operator' => 'auto_video',
                'timeout'  => (int)(getenv('AUTO_VIDEO_TIMEOUT') ?: 35),
            ]);

            Logger::info('Auto-vídeo: 34818 despachado', [
                'imei'          => $req['imei'],
                'occurrence_id' => $req['occurrence_id'],
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
