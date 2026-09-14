<?php
/**
 * bycamera — Mapa de Risco ADAS/DMS: regras de cálculo (v4.20.0)
 * Arquivo: includes/risk_map.php
 *
 * Funções PURAS (sem banco), usadas pelo cron `scripts/risk_builder.php` para
 * gravar `risk_events`/`risk_exposure` e pela tela `/mapa-risco` para rotular.
 * Separadas do builder para serem testadas sem MySQL:
 *   php tests/helpers/risk_map.test.php
 *
 * ── Índice de risco ────────────────────────────────────────────────────────
 * Σ pesos ÷ horas em movimento. A contagem bruta mostraria onde a frota mais
 * anda, não onde é mais perigoso: um veículo que roda 10 h tem mais alertas do
 * que um que roda 1 h, e uma avenida por onde passa a frota inteira tem mais
 * alertas do que uma rua pouco usada.
 *
 * ── Direção contínua (decisão do dono do produto, 13/09/2026) ──────────────
 * Soma todo intervalo que começa com ignição LIGADA — movimento e ociosidade,
 * inclusive lacunas sem sinal: a câmera guarda as posições e as descarrega
 * depois, então falta de sinal não é pausa. Zera só ao fim de uma sequência de
 * ignição DESLIGADA de 30 min ou mais. A exposição (o denominador), ao
 * contrário, só conta intervalos de até 30 min: o tempo sem sinal soma na
 * jornada, mas não dilui o índice.
 *
 * ── Horário ────────────────────────────────────────────────────────────────
 * Deslocamento fixo de −3 h, o mesmo `CONVERT_TZ(…, '+00:00', '-03:00')` das
 * consultas do sistema (o Brasil não tem horário de verão desde 2019). Montar
 * um DateTimeZone por ponto pesaria no reprocessamento de meses de GPS.
 */

require_once __DIR__ . '/functions.php';    // haversine_km()
require_once __DIR__ . '/fleet_state.php';  // STOP_SPEED_KMH

/** Peso de cada nível de risco do perfil de ocorrências. */
const RISK_WEIGHTS = ['baixo' => 1, 'medio' => 3, 'alto' => 5];

/** Tipo sem parâmetro no perfil do cliente entra como médio. */
const RISK_DEFAULT_LEVEL = 'medio';

/** Valor de alarm_types.risk_group para o que fica fora do mapa. */
const RISK_GROUP_EXCLUDED = 'excluido';

/** Ignição desligada por este tempo zera a direção contínua. */
const RISK_PAUSE_S = 1800;

/** Intervalo entre pontos acima disto não conta como exposição. */
const RISK_EXPOSURE_MAX_GAP_S = 1800;

/** Exposição mínima para uma célula entrar no ranking de locais. */
const RISK_MIN_CELL_S = 1800;
const RISK_MIN_CELL_ALERTS = 3;

/** Exposição mínima para veículo ou motorista entrar no ranking. */
const RISK_MIN_ENTITY_S = 18000;

/** Repetições do mesmo comportamento no período para contar como reincidência. */
const RISK_REINCIDENCE_MIN = 3;

/** Período da tela. */
const RISK_MAX_DAYS = 90;
const RISK_DEFAULT_DAYS = 30;

/** Lado da célula em graus de latitude (~1 km). */
const RISK_CELL_DEG = 0.009;

/** BRT = UTC − 3 h. */
const RISK_BRT_OFFSET_S = -10800;

/** Comportamento (alarm_types.risk_group) → rótulo da tela. */
const RISK_GROUP_LABELS = [
    'colisao_frontal'         => 'Colisão frontal',
    'colisao_pedestre'        => 'Colisão com pedestre',
    'distancia_insegura'      => 'Distância insegura',
    'saida_faixa'             => 'Saída de faixa',
    'mudanca_faixa_frequente' => 'Mudança de faixa frequente',
    'excesso_placa'           => 'Excesso em placa de trânsito',
    'obstaculo_frente'        => 'Obstáculo à frente',
    'uso_celular'             => 'Uso de celular',
    'fadiga'                  => 'Fadiga',
    'bocejo'                  => 'Bocejo',
    'piscadas_frequentes'     => 'Piscadas frequentes',
    'distracao'               => 'Distração',
    'cinto'                   => 'Cinto não afivelado',
    'fumando'                 => 'Fumando',
    'bebendo_comendo'         => 'Bebendo ou comendo',
    'maos_fora_volante'       => 'Mãos fora do volante',
    'motorista_nao_detectado' => 'Motorista não detectado',
    'conducao_prolongada'     => 'Condução prolongada',
];

const RISK_DAY_PERIOD_LABELS = [
    'madrugada' => 'Madrugada (0–6 h)',
    'manha'     => 'Manhã (6–12 h)',
    'tarde'     => 'Tarde (12–18 h)',
    'noite'     => 'Noite (18–24 h)',
];

const RISK_SPEED_BAND_LABELS = [
    1 => 'Até 20 km/h',
    2 => '20–60 km/h',
    3 => '60–90 km/h',
    4 => 'Acima de 90 km/h',
];

const RISK_CONTINUOUS_BAND_LABELS = [
    1 => 'Até 30 min',
    2 => '30 min–1 h',
    3 => '1–2 h',
    4 => '2–4 h',
    5 => 'Acima de 4 h',
];

/** Rótulo de quem não tem ponto de GPS antes do alerta. */
const RISK_NO_GPS_LABEL = 'Sem GPS';

const RISK_WEEKDAY_LABELS = [1 => 'Dom', 2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb'];

/**
 * Peso de um nível de risco.
 *
 * @param string|null $level baixo|medio|alto
 * @returns int 1, 3 ou 5; nível desconhecido vale como médio
 */
function risk_weight(?string $level): int
{
    return RISK_WEIGHTS[$level ?? ''] ?? RISK_WEIGHTS[RISK_DEFAULT_LEVEL];
}

/**
 * Rótulo de um comportamento.
 *
 * @param string|null $group Valor de risk_group
 * @returns string
 */
function risk_group_label(?string $group): string
{
    return RISK_GROUP_LABELS[$group ?? ''] ?? (($group ?? '') !== '' ? $group : '—');
}

/**
 * Faixa do dia de uma hora BRT.
 *
 * @param int $brtHour 0–23
 * @returns string madrugada|manha|tarde|noite
 */
function risk_day_period(int $brtHour): string
{
    if ($brtHour < 6)  return 'madrugada';
    if ($brtHour < 12) return 'manha';
    if ($brtHour < 18) return 'tarde';
    return 'noite';
}

/**
 * Faixa de velocidade.
 *
 * @param float|null $kmh
 * @returns int|null 1 (≤20) · 2 (≤60) · 3 (≤90) · 4 (>90); null sem velocidade
 */
function risk_speed_band(?float $kmh): ?int
{
    if ($kmh === null) return null;
    if ($kmh <= 20) return 1;
    if ($kmh <= 60) return 2;
    if ($kmh <= 90) return 3;
    return 4;
}

/**
 * Faixa de direção contínua.
 *
 * @param int|null $seconds
 * @returns int|null 1 (≤30 min) · 2 (≤1 h) · 3 (≤2 h) · 4 (≤4 h) · 5 (>4 h); null = Sem GPS
 */
function risk_continuous_band(?int $seconds): ?int
{
    if ($seconds === null) return null;
    if ($seconds <= 1800)  return 1;
    if ($seconds <= 3600)  return 2;
    if ($seconds <= 7200)  return 3;
    if ($seconds <= 14400) return 4;
    return 5;
}

/**
 * Rótulo de uma faixa, com o texto de ausência para NULL.
 *
 * @param int|null $band
 * @param array    $labels RISK_SPEED_BAND_LABELS ou RISK_CONTINUOUS_BAND_LABELS
 * @returns string
 */
function risk_band_label(?int $band, array $labels): string
{
    return $band === null ? RISK_NO_GPS_LABEL : ($labels[$band] ?? (string)$band);
}

/**
 * Período da tela: datas invertidas são trocadas e o fim é encurtado ao teto.
 *
 * Teto próprio, e não o clamp_report_range() de 31 dias dos relatórios: com o
 * volume de hoje (26 células de 1 km com 10 alertas ou mais em 30 dias) o
 * ranking de locais precisa de janelas mais longas para formar padrão.
 *
 * @param string $dateFrom 'Y-m-d' (BRT)
 * @param string $dateTo   'Y-m-d' (BRT)
 * @param int    $maxDays  Teto em dias corridos, contando os dois extremos
 * @returns array{0:string,1:string,2:bool} [início, fim, foi_ajustado]
 */
function risk_clamp_range(string $dateFrom, string $dateTo, int $maxDays = RISK_MAX_DAYS): array
{
    $valid = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d . ' 00:00:00 UTC') !== false;
    if (!$valid($dateFrom) || !$valid($dateTo)) {
        $today = gmdate('Y-m-d', time() + RISK_BRT_OFFSET_S);
        return [gmdate('Y-m-d', strtotime($today . ' 00:00:00 UTC') - (RISK_DEFAULT_DAYS - 1) * 86400), $today, true];
    }
    $clamped = false;
    if ($dateTo < $dateFrom) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        $clamped = true;
    }
    $maxTo = gmdate('Y-m-d', strtotime($dateFrom . ' 00:00:00 UTC') + ($maxDays - 1) * 86400);
    if ($dateTo > $maxTo) {
        $dateTo  = $maxTo;
        $clamped = true;
    }
    return [$dateFrom, $dateTo, $clamped];
}

/**
 * Timestamp Unix de um datetime UTC do banco.
 *
 * @param string $utcDatetime 'Y-m-d H:i:s'
 * @returns int
 */
function risk_utc_ts(string $utcDatetime): int
{
    return (int)strtotime($utcDatetime . ' UTC');
}

/**
 * Data, hora e dia da semana BRT de um instante UTC.
 *
 * @param int $utcTs Timestamp Unix
 * @returns array{date:string,hour:int,weekday:int} weekday 1 = domingo … 7 = sábado
 */
function risk_brt_parts(int $utcTs): array
{
    $brt = $utcTs + RISK_BRT_OFFSET_S;
    return [
        'date'    => gmdate('Y-m-d', $brt),
        'hour'    => (int)gmdate('G', $brt),
        'weekday' => (int)gmdate('w', $brt) + 1,
    ];
}

/**
 * Janela UTC de um dia BRT, meio-aberta: [início, fim).
 *
 * @param string $brtDate 'Y-m-d'
 * @returns array{0:int,1:int} Timestamps Unix
 */
function risk_brt_day_bounds(string $brtDate): array
{
    $start = (int)strtotime($brtDate . ' 00:00:00 UTC') - RISK_BRT_OFFSET_S;
    return [$start, $start + 86400];
}

/**
 * Largura da célula em graus de longitude para uma linha da grade.
 *
 * Um grau de longitude encolhe com o cosseno da latitude: largura fixa em graus
 * daria 1,0 km em Roraima e 1,2 km no Rio Grande do Sul, e as células do Sul
 * teriam 20% mais área para juntar alertas.
 *
 * @param int $cellY Linha da grade
 * @returns float
 */
function risk_cell_width_deg(int $cellY): float
{
    $centerLat = ($cellY + 0.5) * RISK_CELL_DEG;
    return RISK_CELL_DEG / max(0.01, cos(deg2rad($centerLat)));
}

/**
 * Célula de 1 km de uma coordenada.
 *
 * @param float $lat
 * @param float $lng
 * @returns array{0:int,1:int} [cell_y, cell_x]
 */
function risk_cell(float $lat, float $lng): array
{
    $y = (int)floor($lat / RISK_CELL_DEG);
    $x = (int)floor($lng / risk_cell_width_deg($y));
    return [$y, $x];
}

/**
 * Limites de uma célula, na ordem que o L.rectangle do Leaflet espera.
 *
 * @param int $cellY
 * @param int $cellX
 * @returns array{0:float,1:float,2:float,3:float} [sul, oeste, norte, leste]
 */
function risk_cell_bounds(int $cellY, int $cellX): array
{
    $w = risk_cell_width_deg($cellY);
    return [$cellY * RISK_CELL_DEG, $cellX * $w, ($cellY + 1) * RISK_CELL_DEG, ($cellX + 1) * $w];
}

/**
 * Centro de uma célula (ponto usado para buscar o endereço).
 *
 * @param int $cellY
 * @param int $cellX
 * @returns array{0:float,1:float} [lat, lng]
 */
function risk_cell_center(int $cellY, int $cellX): array
{
    return [($cellY + 0.5) * RISK_CELL_DEG, ($cellX + 0.5) * risk_cell_width_deg($cellY)];
}

/**
 * Nível de risco de um tipo de alerta no perfil de ocorrências de um cliente.
 *
 * Prioridade FIXA: nome PT do catálogo → nome EN → código composto → código
 * base → categoria → médio. Não reaproveita `get_occurrence_param()`, que
 * escolhe com `LIMIT 1` sem ordem e sem filtrar protocolo: com mais de um
 * parâmetro casando, o mesmo alerta poderia ganhar pesos diferentes a cada
 * reprocessamento.
 *
 * @param array $params     [occurrence_config_params.alarm_type => risk] de UM perfil
 * @param array $catalogRow alarm_name_pt, alarm_name_en, alarm_code, base_code, category
 * @returns string baixo|medio|alto
 */
function risk_resolve_level(array $params, array $catalogRow): string
{
    foreach (['alarm_name_pt', 'alarm_name_en', 'alarm_code', 'base_code', 'category'] as $key) {
        $value = $catalogRow[$key] ?? null;
        if ($value === null || $value === '' || !isset($params[$value])) {
            continue;
        }
        if (isset(RISK_WEIGHTS[$params[$value]])) {
            return $params[$value];
        }
    }
    return RISK_DEFAULT_LEVEL;
}

/**
 * Direção contínua num instante, a partir do último ponto conhecido antes dele.
 *
 * @param array|null $prev     Último ponto até o instante (ou o ponto virtual do dia anterior)
 * @param int|null   $counter  Contador depois desse ponto
 * @param int|null   $offSince Início da sequência de ignição desligada em curso
 * @param int        $ts       Instante (timestamp Unix)
 * @returns int|null null quando não há ponto nenhum antes do instante
 */
function risk_continuous_at(?array $prev, ?int $counter, ?int $offSince, int $ts): ?int
{
    if ($prev === null) {
        return null;
    }
    if ((int)$prev['acc'] === 1) {
        return ($counter ?? 0) + max(0, $ts - (int)$prev['t']);
    }
    if ($offSince !== null && $ts - $offSince >= RISK_PAUSE_S) {
        return 0;
    }
    return $counter ?? 0;
}

/**
 * Soma um intervalo à exposição, no balde do ponto que o inicia.
 *
 * @param array    $exposure Baldes acumulados (por referência)
 * @param array    $from     Ponto que inicia o intervalo
 * @param int|null $counter  Direção contínua no ponto inicial
 * @param int      $dt       Duração do intervalo em segundos
 * @param array    $to       Ponto que fecha o intervalo
 * @returns void
 */
function risk_add_exposure(array &$exposure, array $from, ?int $counter, int $dt, array $to): void
{
    if ($dt <= 0 || $dt > RISK_EXPOSURE_MAX_GAP_S) {
        return;
    }
    $speed = (float)($from['speed'] ?? 0);
    [$cy, $cx] = risk_cell((float)$from['lat'], (float)$from['lng']);
    $parts = risk_brt_parts((int)$from['t']);
    $row = [
        'customer_id'     => (int)($from['customer_id'] ?? 0),
        'brt_date'        => $parts['date'],
        'brt_hour'        => $parts['hour'],
        'cell_y'          => $cy,
        'cell_x'          => $cx,
        'speed_band'      => risk_speed_band($speed),
        'continuous_band' => risk_continuous_band($counter ?? 0),
        'driver_id'       => (int)($from['driver_id'] ?? 0),
    ];
    $key = implode('|', $row);
    if (!isset($exposure[$key])) {
        $exposure[$key] = $row + ['driving_s' => 0, 'idle_s' => 0, 'km' => 0.0];
    }
    if ($speed > STOP_SPEED_KMH) {
        $exposure[$key]['driving_s'] += $dt;
        $exposure[$key]['km'] += haversine_km(
            (float)$from['lat'], (float)$from['lng'], (float)$to['lat'], (float)$to['lng']
        );
    } else {
        $exposure[$key]['idle_s'] += $dt;
    }
}

/**
 * Percorre os pontos de UM veículo num dia BRT: exposição, direção contínua de
 * cada alerta e o estado que passa para o dia seguinte.
 *
 * Cada intervalo entre dois pontos pertence ao ponto que o inicia (célula, hora
 * e faixas dele). O ponto virtual do dia anterior (`$carry`) só alimenta a
 * jornada: a exposição desse intervalo já foi contada no dia em que ele começou.
 *
 * @param array    $points   Pontos do dia, ordenados por instante, sem coordenada
 *                           inválida: t (Unix), acc (0|1), speed, lat, lng,
 *                           driver_id, customer_id
 * @param array    $carry    Estado do dia anterior: continuous_s, off_since,
 *                           last_t, last_acc (tudo NULL quando não há histórico)
 * @param array    $alerts   Alertas do dia: [['id' => alarm_id, 't' => Unix], …]
 * @param int      $dayEndTs Fim do dia BRT (exclusivo)
 * @param array|null $after  Primeiro ponto depois do fim do dia (fecha o último intervalo)
 * @returns array{exposure: array, alert_continuous: array, carry: array}
 */
function risk_walk_points(array $points, array $carry, array $alerts, int $dayEndTs, ?array $after = null): array
{
    $counter  = isset($carry['continuous_s']) ? (int)$carry['continuous_s'] : null;
    $offSince = isset($carry['off_since']) ? (int)$carry['off_since'] : null;
    $prev     = isset($carry['last_t'])
        ? ['t' => (int)$carry['last_t'], 'acc' => (int)($carry['last_acc'] ?? 0), 'virtual' => true]
        : null;
    $prevCounter = $counter;

    usort($alerts, fn($a, $b) => (int)$a['t'] <=> (int)$b['t']);
    $alertCount = count($alerts);
    $ai = 0;

    $exposure  = [];
    $continuos = [];

    foreach ($points as $p) {
        $t = (int)$p['t'];

        // Alerta ANTES deste ponto é resolvido pelo estado do ponto anterior.
        while ($ai < $alertCount && (int)$alerts[$ai]['t'] < $t) {
            $continuos[$alerts[$ai]['id']] = risk_continuous_at($prev, $counter, $offSince, (int)$alerts[$ai]['t']);
            $ai++;
        }

        if ($prev !== null && (int)$prev['acc'] === 1) {
            $dt = max(0, $t - (int)$prev['t']);
            if (empty($prev['virtual']) && (int)$prev['t'] < $dayEndTs) {
                risk_add_exposure($exposure, $prev, $prevCounter, $dt, $p);
            }
            $counter = ($counter ?? 0) + $dt;
        }

        if ((int)$p['acc'] === 1) {
            if ($offSince !== null && $t - $offSince >= RISK_PAUSE_S) {
                $counter = 0;
            }
            $offSince = null;
            $counter  = $counter ?? 0;
        } elseif ($offSince === null) {
            $offSince = $t;
        }

        $prev        = $p;
        $prevCounter = $counter;
    }

    if ($after !== null && $prev !== null && empty($prev['virtual'])
        && (int)$prev['acc'] === 1 && (int)$prev['t'] < $dayEndTs) {
        risk_add_exposure($exposure, $prev, $prevCounter, max(0, (int)$after['t'] - (int)$prev['t']), $after);
    }

    while ($ai < $alertCount) {
        $continuos[$alerts[$ai]['id']] = risk_continuous_at($prev, $counter, $offSince, (int)$alerts[$ai]['t']);
        $ai++;
    }

    foreach ($exposure as &$row) {
        $row['km'] = round($row['km'], 3);
    }
    unset($row);

    return [
        'exposure'         => array_values($exposure),
        'alert_continuous' => $continuos,
        'carry'            => [
            'continuous_s' => $counter,
            'off_since'    => $offSince,
            'last_t'       => $prev !== null ? (int)$prev['t'] : null,
            'last_acc'     => $prev !== null ? (int)$prev['acc'] : null,
        ],
    ];
}
