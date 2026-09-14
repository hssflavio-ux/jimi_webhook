<?php
/**
 * Regras do Mapa de Risco (includes/risk_map.php) — sem banco.
 *
 * Trava as decisões do dono do produto (13/09/2026) que não aparecem em tela
 * nenhuma se quebrarem: a tela continua desenhando, só que com números
 * errados.
 *   - Falta de sinal NÃO é pausa: lacuna com ignição ligada soma na jornada.
 *   - Só ignição desligada por 30 min ou mais zera a jornada.
 *   - Lacuna maior que 30 min não entra na exposição (não dilui o índice).
 *   - Faixas e grade de 1 km com as bordas exatas.
 *   - Cada comportamento do rótulo existe na migração e vice-versa.
 *
 * Uso:
 *   php tests/helpers/risk_map.test.php
 */

require_once __DIR__ . '/../../includes/risk_map.php';

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

$T0 = risk_utc_ts('2026-09-10 12:00:00');               // 09:00 BRT
[, $DAY_END] = risk_brt_day_bounds('2026-09-10');
$SEM_CARRY = ['continuous_s' => null, 'off_since' => null, 'last_t' => null, 'last_acc' => null];

function pt(int $t, int $acc, float $speed = 40.0): array {
    global $T0;
    return ['t' => $T0 + $t, 'acc' => $acc, 'speed' => $speed,
            'lat' => -19.92, 'lng' => -43.94, 'driver_id' => 0, 'customer_id' => 1];
}

function soma(array $res, string $campo): int {
    return (int)array_sum(array_column($res['exposure'], $campo));
}

echo "== Faixas ==\n";
checa('jornada 1800 s = até 30 min',            1, risk_continuous_band(1800));
checa('jornada 1801 s = 30 min–1 h',            2, risk_continuous_band(1801));
checa('jornada 3600 s = 30 min–1 h',            2, risk_continuous_band(3600));
checa('jornada 7200 s = 1–2 h',                 3, risk_continuous_band(7200));
checa('jornada 14400 s = 2–4 h',                4, risk_continuous_band(14400));
checa('jornada 14401 s = acima de 4 h',         5, risk_continuous_band(14401));
checa('jornada NULL = Sem GPS',                 null, risk_continuous_band(null));
checa('rótulo de jornada NULL',                 'Sem GPS', risk_band_label(null, RISK_CONTINUOUS_BAND_LABELS));
checa('velocidade 20 = faixa 1',                1, risk_speed_band(20.0));
checa('velocidade 20,1 = faixa 2',              2, risk_speed_band(20.1));
checa('velocidade 90 = faixa 3',                3, risk_speed_band(90.0));
checa('velocidade 90,5 = faixa 4',              4, risk_speed_band(90.5));
checa('velocidade NULL = sem faixa',            null, risk_speed_band(null));
checa('0 h BRT = madrugada',                    'madrugada', risk_day_period(0));
checa('5 h BRT = madrugada',                    'madrugada', risk_day_period(5));
checa('6 h BRT = manhã',                        'manha', risk_day_period(6));
checa('12 h BRT = tarde',                       'tarde', risk_day_period(12));
checa('17 h BRT = tarde',                       'tarde', risk_day_period(17));
checa('18 h BRT = noite',                       'noite', risk_day_period(18));

echo "== Pesos e perfil ==\n";
checa('peso alto',                              5, risk_weight('alto'));
checa('peso baixo',                             1, risk_weight('baixo'));
checa('peso desconhecido = médio',              3, risk_weight('xyz'));
checa('peso NULL = médio',                      3, risk_weight(null));
$params  = ['DMS: Chamada Telefônica' => 'alto', '265-2' => 'baixo', '265' => 'medio', 'DMS' => 'baixo'];
$catalog = ['alarm_name_pt' => 'DMS: Chamada Telefônica', 'alarm_name_en' => 'Phone call',
            'alarm_code' => '265-2', 'base_code' => '265', 'category' => 'DMS'];
checa('nome PT vence código e categoria',       'alto', risk_resolve_level($params, $catalog));
checa('sem nome, vence o código composto',      'baixo', risk_resolve_level($params, ['alarm_name_pt' => 'Outro'] + $catalog));
checa('sem composto, vence o código base',      'medio', risk_resolve_level(['265' => 'medio', 'DMS' => 'baixo'], $catalog));
checa('só a categoria casa',                    'baixo', risk_resolve_level(['DMS' => 'baixo'], $catalog));
checa('nome EN casa',                           'alto', risk_resolve_level(['Phone call' => 'alto'], $catalog));
checa('nada casa = médio',                      'medio', risk_resolve_level([], $catalog));
checa('valor inválido no perfil é ignorado',    'baixo', risk_resolve_level(['DMS: Chamada Telefônica' => '??', 'DMS' => 'baixo'], $catalog));

echo "== Horário BRT ==\n";
checa('02:59:59 UTC ainda é o dia anterior',    '2026-09-12', risk_brt_parts(risk_utc_ts('2026-09-13 02:59:59'))['date']);
checa('02:59:59 UTC = 23 h BRT',                23, risk_brt_parts(risk_utc_ts('2026-09-13 02:59:59'))['hour']);
checa('03:00:00 UTC abre o dia BRT',            '2026-09-13', risk_brt_parts(risk_utc_ts('2026-09-13 03:00:00'))['date']);
checa('13/09/2026 é domingo (1)',               1, risk_brt_parts(risk_utc_ts('2026-09-13 15:00:00'))['weekday']);
checa('início do dia BRT em UTC',               risk_utc_ts('2026-09-13 03:00:00'), risk_brt_day_bounds('2026-09-13')[0]);
checa('fim do dia BRT em UTC (exclusivo)',      risk_utc_ts('2026-09-14 03:00:00'), risk_brt_day_bounds('2026-09-13')[1]);

echo "== Grade de 1 km ==\n";
foreach ([['Norte (lat 2,5)', 2.5, -60.7], ['Sul (lat −30)', -30.0, -51.2], ['BH', -19.92, -43.94]] as [$nome, $lat, $lng]) {
    [$y, $x] = risk_cell($lat, $lng);
    [$s, $w, $n, $e] = risk_cell_bounds($y, $x);
    [$clat, $clng] = risk_cell_center($y, $x);
    checa("$nome: ponto dentro da célula", true, $lat >= $s && $lat < $n && $lng >= $w && $lng < $e);
    checa("$nome: largura ~1 km",          true, abs(haversine_km($clat, $w, $clat, $e) - 1.0) < 0.02);
    checa("$nome: altura ~1 km",           true, abs(haversine_km($s, $clng, $n, $clng) - 1.0) < 0.02);
}

echo "== Jornada e exposição ==\n";

// 1. 2 h dirigindo, com parada ociosa (ignição ligada) de 10 min no meio: não zera.
$pts = [];
for ($t = 0; $t <= 7200; $t += 300) {
    $pts[] = pt($t, 1, in_array($t, [3600, 3900], true) ? 0.0 : 40.0);
}
$r = risk_walk_points($pts, $SEM_CARRY, [['id' => 1, 't' => $T0 + 7200]], $DAY_END);
checa('2 h com ocioso de 10 min: jornada 7200 s', 7200, $r['alert_continuous'][1]);
checa('2 h com ocioso: 600 s ociosos',            600, soma($r, 'idle_s'));
checa('2 h com ocioso: 6600 s em movimento',      6600, soma($r, 'driving_s'));

// 2. Ignição desligada por 29 min não zera; por 30 min zera.
$base = [];
for ($t = 0; $t <= 3300; $t += 300) $base[] = pt($t, 1);
$pts = array_merge($base, [pt(3600, 0), pt(5340, 1), pt(5640, 1), pt(5940, 1), pt(6240, 1)]);
$r = risk_walk_points($pts, $SEM_CARRY, [['id' => 1, 't' => $T0 + 6240]], $DAY_END);
checa('desligada 29 min: jornada continua (4500 s)', 4500, $r['alert_continuous'][1]);
$pts = array_merge($base, [pt(3600, 0), pt(5400, 1), pt(5700, 1), pt(6000, 1), pt(6300, 1)]);
$r = risk_walk_points($pts, $SEM_CARRY, [['id' => 1, 't' => $T0 + 6300]], $DAY_END);
checa('desligada 30 min: jornada zera (900 s)',      900, $r['alert_continuous'][1]);

// 3. Lacuna de 2 h com ignição ligada: soma na jornada, fora da exposição.
$pts = [pt(0, 1), pt(300, 1), pt(7500, 1), pt(7800, 1)];
$r = risk_walk_points($pts, $SEM_CARRY, [['id' => 1, 't' => $T0 + 7800]], $DAY_END);
checa('lacuna de 2 h ligada: jornada 7800 s',        7800, $r['alert_continuous'][1]);
checa('lacuna de 2 h ligada: exposição só 600 s',    600, soma($r, 'driving_s'));

// 4. Lacuna que COMEÇA com ignição desligada e dura 30 min ou mais: zera.
$pts = [pt(0, 1), pt(600, 1), pt(1200, 1), pt(1800, 0), pt(5400, 1), pt(6000, 1)];
$r = risk_walk_points($pts, $SEM_CARRY, [['id' => 1, 't' => $T0 + 6000]], $DAY_END);
checa('lacuna desligada de 1 h: jornada zera (600 s)', 600, $r['alert_continuous'][1]);

// 5. Alerta entre pontos, antes do primeiro ponto e com ignição desligada.
$r = risk_walk_points([pt(0, 1), pt(600, 1)], $SEM_CARRY, [['id' => 1, 't' => $T0 + 300]], $DAY_END);
checa('alerta entre dois pontos: 300 s',             300, $r['alert_continuous'][1]);
$carry = ['continuous_s' => 1000, 'off_since' => null, 'last_t' => $T0 - 600, 'last_acc' => 1];
$r = risk_walk_points([pt(600, 1)], $carry, [['id' => 1, 't' => $T0]], $DAY_END);
checa('alerta antes do 1º ponto, com dia anterior',  1600, $r['alert_continuous'][1]);
checa('intervalo do dia anterior não vira exposição', 0, soma($r, 'driving_s'));
$r = risk_walk_points([pt(600, 1)], $SEM_CARRY, [['id' => 1, 't' => $T0]], $DAY_END);
checa('alerta antes do 1º ponto, sem histórico: NULL', null, $r['alert_continuous'][1]);
$r = risk_walk_points([pt(0, 1), pt(600, 0)], $SEM_CARRY,
    [['id' => 1, 't' => $T0 + 1200], ['id' => 2, 't' => $T0 + 2400]], $DAY_END);
checa('desligada há 10 min: jornada congelada (600 s)', 600, $r['alert_continuous'][1]);
checa('desligada há 30 min: jornada 0',               0, $r['alert_continuous'][2]);

// 6. A jornada atravessa o dia pelo estado passado adiante.
$dia1 = [];
for ($t = 0; $t <= 3000; $t += 600) $dia1[] = pt($t, 1);
$r1 = risk_walk_points($dia1, $SEM_CARRY, [], $DAY_END);
checa('estado do dia: contador 3000 s',              3000, $r1['carry']['continuous_s']);
checa('estado do dia: último ponto',                 $T0 + 3000, $r1['carry']['last_t']);
$r2 = risk_walk_points([pt(3600, 1), pt(4200, 1)], $r1['carry'], [['id' => 1, 't' => $T0 + 4200]], $DAY_END);
checa('dia seguinte herda a jornada (4200 s)',       4200, $r2['alert_continuous'][1]);
$r3 = risk_walk_points([], $r1['carry'], [['id' => 1, 't' => $T0 + 3300]], $DAY_END);
checa('dia sem pontos repassa o estado intacto',     $r1['carry'], $r3['carry']);
checa('dia sem pontos: alerta usa o dia anterior',   3300, $r3['alert_continuous'][1]);

// 7. Pontos com o mesmo instante não geram tempo negativo nem duplicado.
$r = risk_walk_points([pt(0, 1), pt(600, 1), pt(600, 1), pt(1200, 1)], $SEM_CARRY,
    [['id' => 1, 't' => $T0 + 1200]], $DAY_END);
checa('instante repetido: jornada 1200 s',           1200, $r['alert_continuous'][1]);
checa('instante repetido: exposição 1200 s',         1200, soma($r, 'driving_s'));

// 8. O primeiro ponto do dia seguinte fecha o último intervalo do dia.
$r = risk_walk_points([pt(0, 1), pt(600, 1)], $SEM_CARRY, [], $DAY_END, pt(900, 1));
checa('ponto seguinte fecha o intervalo (900 s)',    900, soma($r, 'driving_s'));
checa('ponto seguinte não vira estado do dia',       $T0 + 600, $r['carry']['last_t']);

// 9. Balde de exposição: faixa de jornada é a do ponto que inicia o intervalo.
$pts = [];
for ($t = 0; $t <= 2400; $t += 600) $pts[] = pt($t, 1);
$r = risk_walk_points($pts, $SEM_CARRY, [], $DAY_END);
$porFaixa = [];
foreach ($r['exposure'] as $e) {
    $porFaixa[$e['continuous_band']] = ($porFaixa[$e['continuous_band']] ?? 0) + $e['driving_s'];
}
ksort($porFaixa);
checa('jornada de até 30 min (inclusive) fica na faixa 1', [1 => 2400], $porFaixa);

echo "== Período da tela ==\n";
checa('período dentro do teto fica igual',           ['2026-09-01', '2026-09-10', false], risk_clamp_range('2026-09-01', '2026-09-10'));
checa('datas invertidas são trocadas',               ['2026-09-01', '2026-09-10', true], risk_clamp_range('2026-09-10', '2026-09-01'));
checa('120 dias viram 90 (fim encurtado)',           ['2026-01-01', '2026-03-31', true], risk_clamp_range('2026-01-01', '2026-05-01'));
checa('exatamente 90 dias não é ajustado',           ['2026-01-01', '2026-03-31', false], risk_clamp_range('2026-01-01', '2026-03-31'));
checa('data inválida cai no padrão e avisa',         true, risk_clamp_range('ontem', '2026-09-10')[2]);

echo "== Migração x rótulos ==\n";
$sql = (string)file_get_contents(__DIR__ . '/../../mysql/migration_v4.20.0.sql');
preg_match_all("/SET risk_group = '([a-z_]+)'/", $sql, $m);
$naMigracao = array_values(array_diff(array_unique($m[1]), [RISK_GROUP_EXCLUDED]));
sort($naMigracao);
$rotulos = array_keys(RISK_GROUP_LABELS);
sort($rotulos);
checa('todo comportamento da migração tem rótulo',   [], array_values(array_diff($naMigracao, $rotulos)));
checa('todo rótulo tem tipo na migração',            [], array_values(array_diff($rotulos, $naMigracao)));
checa('migração marca exclusões',                    true, in_array(RISK_GROUP_EXCLUDED, $m[1], true));

printf("\n%d de %d verificações OK\n", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
