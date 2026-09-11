<?php
/**
 * JIMI Webhook System — Backfill de driver_sessions v4.19.0
 * Script: scripts/backfill_driver_sessions.php
 *
 * Reconstrói `driver_sessions` a partir dos alarmes AFIS (JT/T alertType 6,
 * "Face Recognition Success") já gravados ANTES de a tabela existir — sem
 * isto, o "motorista atual" só apareceria certo depois do PRÓXIMO
 * reconhecimento real de cada veículo. Roda MANUALMENTE, UMA VEZ, depois do
 * segundo deploy (quando a migração v4.19.0 já aplicou) — não entra no
 * deploy.sh nem vira cron.
 *
 * 🔴 É APROXIMAÇÃO PARA DADO HISTÓRICO, documentada como tal. A fonte de
 * verdade daqui pra frente é sempre o mecanismo em tempo real
 * (driver_session_recognize()/driver_session_handle_acc_reading(), chamados
 * por pushalarm.php/pushgps.php/pushhb.php — ver includes/functions.php).
 * Este script nunca roda de novo sozinho depois disso.
 *
 * IDEMPOTENTE: só processa veículos que ainda NÃO têm nenhuma linha em
 * `driver_sessions` — seguro rodar mais de uma vez, não duplica.
 *
 * Uso: php scripts/backfill_driver_sessions.php [dias=2]
 *
 * Algoritmo, por veículo:
 *   1. Agrupa os alarmes AFIS (`alarm_type='6'`, `msg_class=1`, `driver_id`
 *      já resolvido) em "corridas" consecutivas do MESMO motorista.
 *   2. Dentro de cada corrida, qualquer segmento `parado` de
 *      `device_state_segments` (mesmo IMEI — já calculado pelo cron
 *      state_builder.php; `parado` é o proxy existente de ACC OFF) que
 *      comece estritamente entre o primeiro e o último alarme da corrida
 *      fecha uma sub-sessão ali (`end_reason='ign_off'`) — a próxima
 *      confirmação do MESMO motorista na mesma corrida abre uma sub-sessão
 *      nova (mesmo motorista, sessão diferente, fiel à regra "até a
 *      ignição desligar").
 *   3. Entre corridas de motoristas diferentes, a sub-sessão final de uma
 *      corrida fecha no primeiro alarme da corrida seguinte
 *      (`end_reason='driver_changed'`).
 *   4. Para a ÚLTIMA sub-sessão de cada veículo: se
 *      `device_statistics.last_acc_status` do IMEI está em 1 AGORA e não há
 *      segmento `parado` aberto cobrindo o presente, a sessão fica ABERTA
 *      (`ended_at NULL`) — vira a sessão corrente de verdade. Caso
 *      contrário, fecha com `end_reason='ign_off'` no início do segmento
 *      `parado` aberto (ou, na ausência de um, no horário do último alarme
 *      — pior caso, aproximação documentada).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php'; // resolve_driver_by_identifier()

$db = Database::getInstance()->getConnection();
$db->exec("SET time_zone='+00:00'");

$days = (isset($argv[1]) && (int)$argv[1] > 0) ? (int)$argv[1] : 2;
$since = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

echo "Backfill driver_sessions — janela: últimos $days dia(s), desde $since UTC\n";

// Só veículos que ainda não têm NENHUMA sessão — idempotência.
$vehicles = $db->prepare("
    SELECT DISTINCT a.vehicle_id, a.imei
    FROM alarms a
    WHERE a.msg_class = 1 AND a.alarm_type = '6' AND a.vehicle_id IS NOT NULL
      AND a.driver_id IS NOT NULL
      AND a.alarm_time >= :since
      AND NOT EXISTS (SELECT 1 FROM driver_sessions ds WHERE ds.vehicle_id = a.vehicle_id)
");
$vehicles->execute([':since' => $since]);
$vehicles = $vehicles->fetchAll(PDO::FETCH_ASSOC);

$sessionsCreated = 0;
$vehiclesProcessed = 0;
$alarmsSkipped = 0;

foreach ($vehicles as $v) {
    $vehicleId = (int)$v['vehicle_id'];
    $imei = $v['imei'];

    // 🔴 `alarms.driver_id` NÃO é garantidamente um `drivers.id` válido: é o
    // que a câmera mandou, cru, no momento em que o alarme chegou — pode ser
    // de ANTES desta versão existir (string do `identifier`, nunca resolvida)
    // ou de DEPOIS (já resolvido por pushalarm.php). As duas formas convivem
    // na mesma coluna VARCHAR sem distinção visível. Resolver aqui, com a
    // MESMA função que o caminho em tempo real usa, é o que evita o que
    // aconteceu na primeira tentativa: inserir um `driver_id` que não bate
    // com nenhum `drivers.id` e estourar a FK de `driver_sessions`.
    $alarmStmt = $db->prepare("
        SELECT driver_id AS identifier_raw, driver_name, alarm_time, customer_id
        FROM alarms
        WHERE vehicle_id = ? AND msg_class = 1 AND alarm_type = '6'
          AND driver_id IS NOT NULL AND driver_id <> '' AND alarm_time >= ?
        ORDER BY alarm_time ASC
    ");
    $alarmStmt->execute([$vehicleId, $since]);
    $rawRows = $alarmStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rawRows) continue;

    $driverCache = []; // identifier bruto => drivers.id resolvido (ou null)
    $rows = [];
    foreach ($rawRows as $rr) {
        $raw = $rr['identifier_raw'];
        if (!array_key_exists($raw, $driverCache)) {
            $driverCache[$raw] = resolve_driver_by_identifier($db, $raw, $rr['driver_name']);
        }
        $resolved = $driverCache[$raw];
        if ($resolved === null) {
            // Sem cadastro em /motoristas com este identifier — não é erro
            // deste script, é o mesmo "não cria motorista sozinho" de
            // resolve_driver_by_identifier(). Conta e segue.
            $alarmsSkipped++;
            continue;
        }
        $rows[] = ['driver_id' => $resolved, 'alarm_time' => $rr['alarm_time'], 'customer_id' => $rr['customer_id']];
    }
    if (!$rows) continue;

    $stopStmt = $db->prepare("
        SELECT started_at, ended_at FROM device_state_segments
        WHERE imei = ? AND state = 'parado' AND started_at >= ?
        ORDER BY started_at ASC
    ");
    $stopStmt->execute([$imei, $since]);
    $stopSegs = $stopStmt->fetchAll(PDO::FETCH_ASSOC);

    // Agrupa em corridas consecutivas do mesmo motorista.
    $runs = [];
    $cur = null;
    foreach ($rows as $r) {
        $did = (int)$r['driver_id'];
        if ($cur && $cur['driver_id'] === $did) {
            $cur['last'] = $r['alarm_time'];
        } else {
            if ($cur) $runs[] = $cur;
            $cur = [
                'driver_id'   => $did,
                'customer_id' => $r['customer_id'],
                'first'       => $r['alarm_time'],
                'last'        => $r['alarm_time'],
            ];
        }
    }
    if ($cur) $runs[] = $cur;

    for ($i = 0; $i < count($runs); $i++) {
        $run = $runs[$i];
        $isLastRun = ($i === count($runs) - 1);
        $nextRunFirst = $isLastRun ? null : $runs[$i + 1]['first'];

        // Fatia a corrida em sub-sessões nos segmentos `parado` que caem
        // ESTRITAMENTE entre o primeiro e o último alarme desta corrida.
        $segStart = $run['first'];
        foreach ($stopSegs as $seg) {
            if ($seg['started_at'] > $segStart && $seg['started_at'] < $run['last']) {
                insertSession($db, $vehicleId, $run['customer_id'], $run['driver_id'], $imei,
                    $segStart, $seg['started_at'], $seg['started_at'], 'ign_off');
                $sessionsCreated++;
                $segStart = $seg['started_at'];
            }
        }

        if (!$isLastRun) {
            // Fecha na próxima corrida (motorista diferente).
            insertSession($db, $vehicleId, $run['customer_id'], $run['driver_id'], $imei,
                $segStart, $run['last'], $nextRunFirst, 'driver_changed');
            $sessionsCreated++;
            continue;
        }

        // Última sub-sessão do veículo: decide se fica ABERTA (sessão
        // corrente de verdade) ou se fecha por falta de evidência de que a
        // ignição segue ligada.
        $accStmt = $db->prepare("SELECT last_acc_status FROM device_statistics WHERE imei = ?");
        $accStmt->execute([$imei]);
        $isOn = (int)$accStmt->fetchColumn() === 1;

        $openStop = null;
        foreach ($stopSegs as $seg) {
            if ($seg['ended_at'] === null && $seg['started_at'] >= $segStart) {
                $openStop = $seg['started_at'];
                break;
            }
        }

        if ($isOn && $openStop === null) {
            insertSession($db, $vehicleId, $run['customer_id'], $run['driver_id'], $imei,
                $segStart, $run['last'], null, null); // aberta — vira a sessão corrente
        } else {
            $closeAt = $openStop ?? $run['last'];
            insertSession($db, $vehicleId, $run['customer_id'], $run['driver_id'], $imei,
                $segStart, $run['last'], $closeAt, 'ign_off');
        }
        $sessionsCreated++;
    }

    $vehiclesProcessed++;
}

echo "Backfill concluído: $sessionsCreated sessão(ões) criada(s) para $vehiclesProcessed veículo(s).\n";
if ($alarmsSkipped > 0) {
    echo "$alarmsSkipped alarme(s) AFIS ignorado(s) — identifier sem motorista cadastrado em /motoristas (ver STATUS.md).\n";
}

function insertSession(PDO $db, int $vehicleId, int $customerId, int $driverId, string $imei,
                        string $startedAt, string $lastConfirmedAt, ?string $endedAt, ?string $reason): void {
    $db->prepare("
        INSERT INTO driver_sessions (vehicle_id, customer_id, driver_id, imei, started_at, last_confirmed_at, ended_at, end_reason)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$vehicleId, $customerId, $driverId, $imei, $startedAt, $lastConfirmedAt, $endedAt, $reason]);
}
