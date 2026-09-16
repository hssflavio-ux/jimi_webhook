<?php
/**
 * "Desatualizado" (is_device_outdated()/device_outdated_sql(),
 * includes/fleet_state.php) — sem banco.
 *
 * Decisão do dono do produto (15/09/2026): equipamento com ignição LIGADA
 * conta como desatualizado depois de OUTDATED_IGNITION_ON_MINUTES (5) sem dar
 * sinal; DESLIGADA (ou sem leitura de ignição), depois de
 * OUTDATED_IGNITION_OFF_MINUTES (30). "Dar sinal" é o mesmo ÚLTIMO SINAL de
 * device_last_seen_sql() (GREATEST de last_communication/last_gps_time/
 * last_heartbeat_time/last_event_time) — nunca só last_gps_time, que foi
 * exatamente o bug reportado: equipamento comunicando (heartbeat) mas sem fix
 * de GPS aparecia como desatualizado.
 *
 * Uso:
 *   php tests/helpers/device_outdated.test.php
 */

require_once __DIR__ . '/../../includes/fleet_state.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-70s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

const AGORA = '2026-09-15 12:00:00';

function ha(int $minutos): string {
    return date('Y-m-d H:i:s', strtotime(AGORA) - $minutos * 60);
}

echo "== is_device_outdated() ==\n";
checa('nunca deu sinal (null) + ignição ligada = desatualizado',    true,  is_device_outdated(null, 1, AGORA));
checa('nunca deu sinal (null) + ignição desligada = desatualizado', true,  is_device_outdated(null, 0, AGORA));

checa('ligada, 4 min = em dia',                  false, is_device_outdated(ha(4), 1, AGORA));
checa('ligada, exatamente 5 min = em dia (limite inclusive)', false, is_device_outdated(ha(5), 1, AGORA));
checa('ligada, 6 min = desatualizado',           true,  is_device_outdated(ha(6), 1, AGORA));

checa('desligada, 29 min = em dia',              false, is_device_outdated(ha(29), 0, AGORA));
checa('desligada, exatamente 30 min = em dia (limite inclusive)', false, is_device_outdated(ha(30), 0, AGORA));
checa('desligada, 31 min = desatualizado',       true,  is_device_outdated(ha(31), 0, AGORA));

checa('ignição desconhecida (null) usa limite de desligada: 10 min = em dia', false, is_device_outdated(ha(10), null, AGORA));
checa('ignição desconhecida (null) usa limite de desligada: 31 min = desatualizado', true, is_device_outdated(ha(31), null, AGORA));

checa('aceita "1" em string (formato PDO)',      true,  is_device_outdated(ha(6), '1', AGORA));
checa('comunicou agora mesmo (0 min) = em dia',  false, is_device_outdated(ha(0), 0, AGORA));

echo "== device_outdated_sql() (verificação estrutural, sem banco) ==\n";
$sql = device_outdated_sql('d', 'ds');
checa('usa o último sinal (device_last_seen_sql), não só last_gps_time', true, str_contains($sql, 'GREATEST'));
checa('referencia o limiar de ignição ligada',    true, str_contains($sql, (string)OUTDATED_IGNITION_ON_MINUTES));
checa('referencia o limiar de ignição desligada', true, str_contains($sql, (string)OUTDATED_IGNITION_OFF_MINUTES));
checa('trata ignição NULL como desligada',        true, str_contains($sql, 'IS NULL'));

printf("\n%d de %d verificações OK\n", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
