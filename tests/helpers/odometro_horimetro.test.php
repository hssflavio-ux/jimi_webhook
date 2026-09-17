<?php
/**
 * Hodômetro (odometer_km/odometer_delta_km) e horímetro calculado
 * (ignition_seconds_in_window) — includes/functions.php, sem banco.
 *
 * Trava as regras que não aparecem em tela nenhuma se quebrarem:
 *   - gps_data.mileage chega em METROS nos dois protocolos (medido em
 *     produção, 15/09/2026 — ver memória hodometro-bateria-medicao-producao);
 *     exibir cru como km mostra o valor 1000x maior que o real.
 *   - hodômetro não regride: delta negativo (reset de sensor, leituras fora
 *     de ordem) vira "sem dado", nunca um KM negativo na tela.
 *   - horímetro calculado soma só os estados de ignição ligada
 *     (movimento/ocioso — ver includes/fleet_state.php), nunca parado/offline,
 *     e o segmento pertence à janela do seu PRÓPRIO started_at (mesma regra
 *     de fechamento diário que trip_builder.php já usa).
 *   - viagem ainda em curso (`trips.ended_at IS NULL`, coluna nullable) não
 *     pode lançar: `rel_deslocamento.php` passa `$r['ended_at']` direto pra
 *     cá, e o `catch (Exception $e)` dos chamadores não pega `TypeError`.
 *   - odometer_delta_from_points() (v4.21.7, base de trip_builder.php/
 *     state_builder.php/rel_deslocamento_rota.php/rel_deslocamento_replay.php
 *     para o cálculo de deslocamento por hodômetro em vez de GPS): descarta
 *     leitura zerada, usa a primeira e a última leitura VÁLIDA na ordem dos
 *     pontos (não a de menor/maior índice), e nunca cai para GPS.
 *
 * Uso:
 *   php tests/helpers/odometro_horimetro.test.php
 */

require_once __DIR__ . '/../../includes/functions.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-66s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

echo "== odometer_km (conversão de escala) ==\n";
checa('null entra, null sai',                    null, odometer_km(null));
checa('0 m = 0.0 km (conversão pura, não filtra)', 0.0, odometer_km(0));
checa('1000 m = 1.0 km',                         1.0, odometer_km(1000));
checa('23.201.000 m = 23201.0 km (JC181 real)',  23201.0, odometer_km(23201000));
checa('1549 m arredonda para 1.5 km',            1.5, odometer_km(1549));
checa('1551 m arredonda para 1.6 km',            1.6, odometer_km(1551));
checa('aceita string numérica (PDO)',            2.0, odometer_km('2000'));

echo "== odometer_delta_km (delta entre duas leituras) ==\n";
checa('primeira leitura ausente = sem dado',     null, odometer_delta_km(null, 5000));
checa('última leitura ausente = sem dado',       null, odometer_delta_km(1000, null));
checa('delta normal (1000→5000 m = 4.0 km)',     4.0, odometer_delta_km(1000, 5000));
checa('delta zero é válido (equipamento parado)', 0.0, odometer_delta_km(3000, 3000));
checa('delta negativo (reset/fora de ordem) = sem dado', null, odometer_delta_km(5000, 1000));

echo "== odometer_delta_from_points (varredura de pontos, v4.21.7) ==\n";
checa('array vazio = sem dado',
    null, odometer_delta_from_points([]));
checa('nenhum ponto com mileage válido = sem dado',
    null, odometer_delta_from_points([['mileage' => 0], ['mileage' => 0]]));
checa('chave mileage ausente = sem dado',
    null, odometer_delta_from_points([['speed' => 10], ['speed' => 20]]));
checa('primeira e última leitura válida (ignora zeros nas pontas)',
    4.0, odometer_delta_from_points([
        ['mileage' => 0], ['mileage' => 1000], ['mileage' => 3000], ['mileage' => 5000], ['mileage' => 0],
    ]));
checa('um único ponto válido = delta 0 (mesma leitura como início e fim)',
    0.0, odometer_delta_from_points([['mileage' => 0], ['mileage' => 7000], ['mileage' => 0]]));
checa('leitura fora de ordem (última < primeira) = sem dado, nunca km negativo',
    null, odometer_delta_from_points([['mileage' => 5000], ['mileage' => 1000]]));

echo "== ignition_seconds_in_window (horímetro calculado) ==\n";
$segs = [
    ['imei' => 'A', 'state' => 'parado',    'started_at' => '2026-09-15 00:00:00', 'duration_s' => 3600],
    ['imei' => 'A', 'state' => 'movimento', 'started_at' => '2026-09-15 01:00:00', 'duration_s' => 1800],
    ['imei' => 'A', 'state' => 'ocioso',    'started_at' => '2026-09-15 01:30:00', 'duration_s' => 600],
    ['imei' => 'A', 'state' => 'offline',   'started_at' => '2026-09-15 01:40:00', 'duration_s' => 900],
    ['imei' => 'B', 'state' => 'movimento', 'started_at' => '2026-09-15 01:00:00', 'duration_s' => 5000],
];

checa('array vazio soma 0',                      0, ignition_seconds_in_window([], 'A', '2026-09-15 00:00:00', '2026-09-16 00:00:00'));
checa('soma só movimento+ocioso do imei pedido', 2400, ignition_seconds_in_window($segs, 'A', '2026-09-15 00:00:00', '2026-09-16 00:00:00'));
checa('exclui parado e offline',                 2400, ignition_seconds_in_window($segs, 'A', '2026-09-15 00:00:00', '2026-09-16 00:00:00'));
checa('exclui imei de outro equipamento',        2400, ignition_seconds_in_window($segs, 'A', '2026-09-15 00:00:00', '2026-09-16 00:00:00'));
checa('janela exclui segmento antes do from',    600, ignition_seconds_in_window($segs, 'A', '2026-09-15 01:15:00', '2026-09-16 00:00:00'));
checa('until é exclusivo (started_at == until não entra)', 0, ignition_seconds_in_window($segs, 'A', '2026-09-15 00:00:00', '2026-09-15 01:00:00'));
checa('from é inclusivo (started_at == from entra)', 1800, ignition_seconds_in_window($segs, 'A', '2026-09-15 01:00:00', '2026-09-15 01:30:00'));
checa('imei sem nenhum segmento no estado soma 0', 5000, ignition_seconds_in_window($segs, 'B', '2026-09-15 00:00:00', '2026-09-16 00:00:00'));
checa('until NULL (viagem em curso) soma até agora, sem TypeError', 2400, ignition_seconds_in_window($segs, 'A', '2026-09-15 00:00:00', null));

printf("\n%d de %d verificações OK\n", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
