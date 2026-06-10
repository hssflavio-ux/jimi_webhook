<?php
/**
 * Classe responsável por fornecer dados para o Dashboard.
 * v2.0.0 — Legado. O painel canônico agora usa handlers/dashboard.php + web/dashboard_template.php
 */
require_once __DIR__ . '/../config/database.php';

class DashboardData {
    private $db;
    private $tzUTC;
    private $tzBRT;

    public function __construct() {
        $this->db    = Database::getInstance()->getConnection();
        $this->tzUTC = new DateTimeZone('UTC');
        $this->tzBRT = new DateTimeZone('America/Sao_Paulo'); // GMT-3 / BRT
    }

    /**
     * Converte string de data armazenada em UTC para GMT-3 (BRT).
     * RAIZ DO BUG ANTERIOR: new DateTime($str) sem timezone interpretava UTC
     * como horário local (UTC-3), fazendo a conversão errar 3h.
     */
    private function fmtDate($dateString): string {
        if (!$dateString || $dateString === '0000-00-00 00:00:00') return '-';
        try {
            $dt = new DateTime($dateString, $this->tzUTC);
            $dt->setTimezone($this->tzBRT);
            return $dt->format('d/m/Y H:i:s');
        } catch (Exception $e) {
            return $dateString;
        }
    }

    /**
     * Verifica o status da API baseando-se na última comunicação recebida.
     * RAIZ DO BUG ANTERIOR: comparação sem timezone explícito gerava diff de 3h.
     */
    public function getApiStatus(): array {
        try {
            $stmt = $this->db->query("
                SELECT GREATEST(
                    COALESCE((SELECT MAX(last_communication) FROM devices), '2000-01-01 00:00:00'),
                    COALESCE((SELECT MAX(created_at)         FROM alarms),  '2000-01-01 00:00:00')
                ) AS last_hit
            ");
            $lastHit = $stmt->fetchColumn();

            if (!$lastHit) return ['status' => 'OFFLINE', 'class' => 'danger', 'last' => '-'];

            $dtLast  = new DateTime($lastHit,  $this->tzUTC);
            $dtNow   = new DateTime('now',      $this->tzUTC);
            $diffMin = ($dtNow->getTimestamp() - $dtLast->getTimestamp()) / 60;

            if ($diffMin <= 10)  return ['status' => 'ONLINE',  'class' => 'success', 'last' => $this->fmtDate($lastHit)];
            if ($diffMin <= 60)  return ['status' => 'OCIOSO',  'class' => 'warning', 'last' => $this->fmtDate($lastHit)];
            return                      ['status' => 'OFFLINE', 'class' => 'danger',  'last' => $this->fmtDate($lastHit)];

        } catch (Exception $e) {
            return ['status' => 'ERRO DB', 'class' => 'danger', 'last' => $e->getMessage()];
        }
    }

    public function getDevices(): array {
        $stmt = $this->db->query("
            SELECT d.imei, d.device_name, d.last_communication,
                   s.last_latitude, s.last_longitude, s.last_speed, s.last_acc_status
            FROM devices d
            LEFT JOIN device_statistics s ON d.imei = s.imei
            ORDER BY d.last_communication DESC
        ");
        $devices = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $hasGps  = ($row['last_latitude'] && $row['last_longitude']);
            $devices[] = [
                'imei'      => $row['imei'],
                'name'      => $row['device_name'] ?? 'Não identificado',
                'last_comm' => $this->fmtDate($row['last_communication']),
                'speed'     => round($row['last_speed'] ?? 0) . ' km/h',
                'ignition'  => ($row['last_acc_status'] == 1) ? 'Ligada' : 'Desligada',
                'ign_class' => ($row['last_acc_status'] == 1) ? 'success' : 'secondary',
                'map_link'  => $hasGps ? "https://www.google.com/maps?q={$row['last_latitude']},{$row['last_longitude']}" : '#',
                'has_gps'   => $hasGps,
            ];
        }
        return $devices;
    }

    public function getLastAlarms(int $limit = 50): array {
        $sql = "
            SELECT a.id, a.alarm_name, a.alarm_time, a.created_at, a.imei,
                   a.msg_class, a.alarm_label, a.latitude, a.longitude,
                   COALESCE(at.severity, 'info') AS severity
            FROM alarms a
            LEFT JOIN alarm_types at ON (
                (a.msg_class = 1 AND at.protocol = 'JTT' AND at.alarm_code = IF(a.alarm_subtype IS NOT NULL, CONCAT(a.alarm_type,'-',a.alarm_subtype), a.alarm_type))
                OR
                (a.msg_class = 0 AND at.protocol = 'JIMI' AND at.alarm_code = a.alarm_type)
            )
            ORDER BY a.created_at DESC LIMIT :limit
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $alarms = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $alarms[] = [
                'id'          => $row['id'],
                'imei'        => $row['imei'],
                'name'        => $row['alarm_name'] ?? 'Desconhecido',
                'occurred_at' => $this->fmtDate($row['alarm_time']),
                'received_at' => $this->fmtDate($row['created_at']),
                'severity'    => $row['severity'],
                'msg_class'   => (int)$row['msg_class'],
                'alarm_label' => $row['alarm_label'] ?? '',
                'has_coords'  => ($row['latitude'] && $row['longitude']),
                'lat'         => $row['latitude'],
                'lng'         => $row['longitude'],
            ];
        }
        return $alarms;
    }

    public function getDevicesForCommands(): array {
        $stmt = $this->db->query("SELECT imei, device_name, last_communication FROM devices ORDER BY last_communication DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLastCommands(int $limit = 30): array {
        $sql = "SELECT id, imei, command_content, command_type, status, operator, response_payload, created_at, updated_at
                FROM commands ORDER BY created_at DESC LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $cmds = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cmds[] = [
                'id'       => $row['id'],
                'imei'     => $row['imei'],
                'command'  => $row['command_content'],
                'type'     => $row['command_type'],
                'status'   => $row['status'],
                'response' => $row['response_payload'] ? json_decode($row['response_payload'], true) : null,
                'created'  => $this->fmtDate($row['created_at']),
                'updated'  => $this->fmtDate($row['updated_at']),
            ];
        }
        return $cmds;
    }
}