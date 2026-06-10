<?php
/**
 * JIMI IoT Hub - Painel de Controle
 * Endpoint: /dashboard  (via .htaccess → handlers/dashboard.php)
 * Versão: 2.0.0
 *
 * Histórico de correções:
 *   v5.3 — Fix 1: Status API — timezone UTC explícito (comparação correta)
 *           Fix 2: Conversão GMT-3 — DateTime construído com tzUTC antes de converter
 *           Fix 3: Dados para aba Comandos (cmdDevices, commands, dashToken)
 *           Fix 4: Dados para VIDEOUPLOAD em alarmes JTT (msg_class, alarm_label)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

// ── Timezones ─────────────────────────────────────────────────────────────────
// RAIZ DOS BUGS ANTERIORES:
//   - new DateTime($str) sem tz interpretava UTC como GMT-3 (horário do servidor)
//   - A comparação $dtNow vs $dtLast usava timezones diferentes gerando diff de 3h
//     → status sempre OFFLINE e horários 3h errados na tela
$tz_utc = new DateTimeZone('UTC');
$tz_brt = new DateTimeZone('America/Sao_Paulo'); // GMT-3

/**
 * Converte data armazenada em UTC para GMT-3 (exibição).
 * Força interpretação como UTC antes de converter.
 */
function to_gmt3($dateStr, $tz_utc, $tz_brt) {
    if (!$dateStr || $dateStr === '0000-00-00 00:00:00') return '-';
    try {
        $dt = new DateTime($dateStr, $tz_utc); // << força UTC na leitura
        $dt->setTimezone($tz_brt);
        return $dt->format('d/m/Y H:i:s');
    } catch (Exception $e) {
        return $dateStr;
    }
}

try {
    $db = Database::getInstance()->getConnection();

    // ─────────────────────────────────────────────────────────────────────────
    // 1. STATUS DA API
    // Compara ambos os timestamps como UTC — sem offset implícito do servidor
    // ─────────────────────────────────────────────────────────────────────────
    $stmtStatus = $db->query("
        SELECT GREATEST(
            COALESCE(MAX(last_communication), '2000-01-01 00:00:00'),
            COALESCE((SELECT MAX(created_at) FROM alarms), '2000-01-01 00:00:00')
        ) AS last_hit
        FROM devices
    ");
    $lastHit = $stmtStatus->fetchColumn();

    $apiStatus = ['label' => 'OFFLINE', 'color' => 'danger', 'last' => '-'];

    if ($lastHit) {
        // Ambos UTC → diff real sem desvio de fuso
        $dtLast     = new DateTime($lastHit, $tz_utc);
        $dtNow      = new DateTime('now',    $tz_utc);
        $diffMinutes = ($dtNow->getTimestamp() - $dtLast->getTimestamp()) / 60;

        $apiStatus['last'] = to_gmt3($lastHit, $tz_utc, $tz_brt);

        if ($diffMinutes <= 10) {
            $apiStatus['label'] = 'ONLINE';
            $apiStatus['color'] = 'success';
        } elseif ($diffMinutes <= 60) {
            $apiStatus['label'] = 'OCIOSO';
            $apiStatus['color'] = 'warning';
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. LISTAGEM DE DISPOSITIVOS
    // ─────────────────────────────────────────────────────────────────────────
    $stmtDev = $db->query("
        SELECT
            d.imei,
            d.device_name,
            d.last_communication,
            s.last_latitude,
            s.last_longitude,
            s.last_speed,
            s.last_acc_status
        FROM devices d
        LEFT JOIN device_statistics s ON d.imei = s.imei
        ORDER BY d.last_communication DESC
    ");

    $devices = [];
    while ($row = $stmtDev->fetch(PDO::FETCH_ASSOC)) {
        $hasGps = ($row['last_latitude'] != 0 && $row['last_longitude'] != 0);
        $devices[] = [
            'imei'       => $row['imei'],
            'name'       => $row['device_name'] ?? 'Sem Nome',
            'last_comm'  => to_gmt3($row['last_communication'], $tz_utc, $tz_brt),
            'ign_status' => ($row['last_acc_status'] == 1) ? 'Ligada' : 'Desligada',
            'ign_class'  => ($row['last_acc_status'] == 1) ? 'success' : 'secondary',
            'speed'      => round($row['last_speed'] ?? 0),
            'has_gps'    => $hasGps,
            'map_url'    => $hasGps
                ? "https://www.google.com/maps?q={$row['last_latitude']},{$row['last_longitude']}"
                : '#',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. ÚLTIMOS 50 ALARMES
    //    Inclui msg_class e alarm_label para o botão VIDEOUPLOAD (Fix 4)
    // ─────────────────────────────────────────────────────────────────────────
    $stmtAlarms = $db->query("
        SELECT
            a.id,
            a.alarm_name,
            a.alarm_time,
            a.created_at,
            a.imei,
            a.msg_class,
            a.alarm_label,
            a.latitude,
            a.longitude,
            a.file_url,
            d.device_name,
            COALESCE(at.severity, 'info') AS severity
        FROM alarms a
        LEFT JOIN devices d ON a.imei = d.imei
        LEFT JOIN alarm_types at ON (
            (a.msg_class = 1
                AND at.protocol = 'JTT'
                AND at.alarm_code = IF(a.alarm_subtype IS NOT NULL,
                    CONCAT(a.alarm_type, '-', a.alarm_subtype), a.alarm_type))
            OR
            (a.msg_class = 0
                AND at.protocol = 'JIMI'
                AND at.alarm_code = a.alarm_type)
        )
        ORDER BY a.created_at DESC
        LIMIT 50
    ");

    $alarms = [];
    while ($row = $stmtAlarms->fetch(PDO::FETCH_ASSOC)) {
        $alarms[] = [
            'id'          => (int)$row['id'],
            'name'        => $row['alarm_name'] ?? 'Desconhecido',
            'imei'        => $row['imei'],
            'device_name' => $row['device_name'] ?? $row['imei'],
            'occurred_at' => to_gmt3($row['alarm_time'], $tz_utc, $tz_brt),
            'received_at' => to_gmt3($row['created_at'],  $tz_utc, $tz_brt),
            'severity'    => $row['severity'],
            'msg_class'   => (int)$row['msg_class'],
            'alarm_label' => $row['alarm_label'] ?? '',
            'latitude'    => $row['latitude'],
            'longitude'   => $row['longitude'],
            'file_url'    => $row['file_url'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. DADOS PARA A ABA DE COMANDOS (Fix 3)
    // ─────────────────────────────────────────────────────────────────────────

    // 4a. Lista de dispositivos para o seletor do formulário
    $stmtCmdDev = $db->query("
        SELECT imei, device_name
        FROM devices
        ORDER BY last_communication DESC
    ");
    $cmdDevices = $stmtCmdDev->fetchAll(PDO::FETCH_ASSOC);

    // 4b. Histórico dos últimos 30 comandos enviados
    $stmtCmds = $db->prepare("
        SELECT id, imei, command_content, status, response_payload, created_at
        FROM commands
        ORDER BY created_at DESC
        LIMIT 30
    ");
    $stmtCmds->execute();

    $commands = [];
    while ($row = $stmtCmds->fetch(PDO::FETCH_ASSOC)) {
        $resp = null;
        if ($row['response_payload']) {
            $decoded = json_decode($row['response_payload'], true);
            if (is_array($decoded)) {
                $resp = $decoded['resultContent']
                    ?? $decoded['content']
                    ?? $decoded['msg']
                    ?? $decoded['message']
                    ?? null;
            }
            if (!$resp) $resp = $row['response_payload'];
        }
        $commands[] = [
            'id'      => (int)$row['id'],
            'imei'    => $row['imei'],
            'command' => $row['command_content'],
            'status'  => $row['status'],
            'resp'    => $resp,
            'created' => to_gmt3($row['created_at'], $tz_utc, $tz_brt),
        ];
    }

    // 4c. Token interno para autenticar chamadas AJAX
    $dashToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';

    // 4d. Hora atual do servidor em BRT para o footer
    //     PHP roda em UTC (conforme `date('T')` no servidor).
    //     Convertemos explicitamente para exibir ao operador no fuso correto.
    $serverTimeBrt = (new DateTime('now', $tz_brt))->format('d/m/Y H:i:s');

} catch (Exception $e) {
    Logger::error('dashboard: erro crítico', ['error' => $e->getMessage()]);
    die('Erro no Dashboard: ' . htmlspecialchars($e->getMessage()));
}

// ── Carrega a view ────────────────────────────────────────────────────────────
include __DIR__ . '/../web/dashboard_template.php';
