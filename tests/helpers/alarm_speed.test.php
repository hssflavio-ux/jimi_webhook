<?php
/**
 * Velocidade do alarme (v4.21.8) — `alarm_speed_kmh()` (includes/functions.php)
 * é o ponto único usado por `scripts/risk_builder.php` (Mapa de Risco) E por
 * `handlers/ocorrencias_dashboard.php` (tela de tratativa da ocorrência).
 * Guarda contra os dois voltarem a ter cada um a sua cópia da lógica —
 * mesma classe de bug do contador On/Off que discordava entre telas.
 *
 * Não precisa de banco.
 *
 * Uso: php tests/helpers/alarm_speed.test.php
 */

$raiz   = dirname(__DIR__, 2);
$falhas = 0;

function confere(bool $cond, string $desc): void
{
    global $falhas;
    if ($cond) {
        echo "  OK   $desc\n";
    } else {
        $falhas++;
        echo "  FALHA $desc\n";
    }
}

require_once $raiz . '/includes/functions.php';

// ── 1) alarm_speed_kmh() — casos isolados ───────────────────────────────────
confere(function_exists('alarm_speed_kmh'), 'alarm_speed_kmh() existe em includes/functions.php');

confere(alarm_speed_kmh(['speed' => 46.0, 'car_speed' => null]) === 46.0, 'speed > 0 prevalece');
confere(alarm_speed_kmh(['speed' => 0.0, 'car_speed' => 30.0]) === 30.0, 'speed = 0 cai para car_speed quando presente');
confere(alarm_speed_kmh(['speed' => 0.0, 'car_speed' => null]) === 0.0, 'speed = 0 sem car_speed continua 0.0 (parado é dado válido)');
confere(alarm_speed_kmh(['speed' => null, 'car_speed' => 55.0]) === 55.0, 'speed nulo cai para car_speed');
confere(alarm_speed_kmh(['speed' => null, 'car_speed' => null]) === null, 'sem os dois campos = null');
confere(alarm_speed_kmh(['speed' => '46.00', 'car_speed' => null]) === 46.0, 'string numérica (retorno típico do PDO) convertida para float');
confere(alarm_speed_kmh([]) === null, 'linha sem as chaves não estoura (?? null nos dois acessos)');

// ── 2) scripts/risk_builder.php usa o ponto único, não uma cópia local ──────
$rb = (string)file_get_contents($raiz . '/scripts/risk_builder.php');
confere(!str_contains($rb, 'function rb_alarm_speed('), 'risk_builder.php não tem mais cópia local de rb_alarm_speed()');
confere(str_contains($rb, 'alarm_speed_kmh($a)'), 'risk_builder.php chama o ponto único alarm_speed_kmh()');

// ── 3) handlers/ocorrencias_dashboard.php exibe velocidade pra qualquer alarme
$occ = (string)file_get_contents($raiz . '/handlers/ocorrencias_dashboard.php');
confere(str_contains($occ, 'function occ_event_speed_kmh('), 'occ_event_speed_kmh() existe (combina overspeed + geral)');
confere(str_contains($occ, 'alarm_speed_kmh($ev)'), 'fallback usa o mesmo ponto único do Mapa de Risco, não uma cópia');
confere(str_contains($occ, 'a.car_speed'), 'SELECT dos eventos da ocorrência passou a trazer car_speed');
confere(substr_count($occ, 'occ_event_speed_kmh($ev)') === 2, 'tabela "Alarmes Agrupados" E balão do mapa usam a função combinada (2 usos)');
confere(!str_contains($occ, "'speed' => occ_overspeed_kmh(\$ev)"), 'balão do mapa não ficou preso só ao caso de excesso de velocidade');
confere(!str_contains($occ, '$evSpeed = occ_overspeed_kmh($ev);'), 'tabela não ficou presa só ao caso de excesso de velocidade');

// ── novas seções entram acima desta linha ──

printf("\n%s\n", $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})");
exit($falhas === 0 ? 0 : 1);
