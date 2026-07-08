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

    return create_occurrence($db, $customerId, $branchId, $imei, $driverId, $occType, $risk, $alarmTime, $alarmId, $mediaId);
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
