<?php
/**
 * JIMI Webhook System — State Builder v4.6.0
 * Script: scripts/state_builder.php
 *
 * Cron (a cada 15 minutos) que segmenta gps_data em:
 *   device_state_segments — movimento / ocioso / parado / offline
 *   speeding_events       — corridas acima do limite do equipamento
 *
 * Um worker alimenta cinco relatórios (paradas, ociosidade, ignição,
 * velocidade e status da frota). A alternativa — calcular cada tela na hora da
 * consulta — varreria gps_data cinco vezes com a mesma lógica escrita cinco
 * vezes, e as telas divergiriam na primeira correção aplicada a só uma delas.
 *
 * Uso:
 *   php scripts/state_builder.php        # incremental (cron)
 *   php scripts/state_builder.php 30     # backfill de 30 dias
 *   php scripts/state_builder.php 30 869058070151343   # backfill de 1 device
 *
 * ── A invariante que sustenta tudo ─────────────────────────────────────────
 * Os segmentos de um equipamento são CONTÍGUOS e não se sobrepõem: o
 * `ended_at` de um é exatamente o `started_at` do seguinte. Disso decorre que
 * a soma das durações de um dia fecha em 86.400 s, que é o teste de aceite —
 * e o único capaz de revelar furo de segmentação. Duas regras produzem essa
 * propriedade:
 *
 *   mudança de estado  → fronteira no gps_time do ponto NOVO (o estado antigo
 *                        acaba no instante em que o novo é observado)
 *   buraco de dados    → fronteira no gps_time do ponto ANTERIOR, e um
 *                        segmento `offline` cobre o vão até o ponto seguinte
 *
 * ── Reexecução ────────────────────────────────────────────────────────────
 * O último segmento de cada equipamento fica ABERTO (`ended_at IS NULL`). A
 * rodada seguinte retoma do `started_at` dele e o regrava via
 * ON DUPLICATE KEY UPDATE sobre uk_dss_imei_start — mesmo `started_at`, mesma
 * linha. Rodar duas vezes sobre a mesma janela não duplica nem fragmenta.
 *
 * ── Limitação conhecida ───────────────────────────────────────────────────
 * Ponto que chega ATRASADO com gps_time anterior à marca-d'água não é
 * reprocessado (mesma limitação do trip_builder.php). Para corrigir uma
 * janela histórica, rode o backfill com o IMEI como 2º argumento.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/functions.php';   // haversine_km(), is_valid_coordinate()
require_once __DIR__ . '/../includes/fleet_state.php'; // classify_point() e os limiares

/** Teto de pontos lidos por equipamento por rodada (protege contra backlog). */
const STATE_MAX_POINTS_PER_DEVICE = 50000;

/** Lookback para equipamento ainda sem segmento algum. */
const STATE_DEFAULT_LOOKBACK_DAYS = 1;

$lookbackDays = (isset($argv[1]) && (int)$argv[1] > 0)
    ? (int)$argv[1]
    : STATE_DEFAULT_LOOKBACK_DAYS;

/** Backfill de um equipamento só: `php scripts/state_builder.php 30 <imei>`. */
$onlyImei = isset($argv[2]) && trim($argv[2]) !== '' ? trim($argv[2]) : null;

$db = Database::getInstance()->getConnection();

// Verifica de saída que a migração foi aplicada: sem isso o erro apareceria
// device por device dentro do laço, poluindo o log com 200 linhas iguais.
try {
    $db->query("SELECT 1 FROM device_state_segments LIMIT 1");
    $db->query("SELECT 1 FROM speeding_events LIMIT 1");
} catch (Throwable $e) {
    fwrite(STDERR, "State Builder: tabelas indisponíveis — aplique a migração v4.6.0.\n");
    Logger::error('State Builder: tabelas indisponíveis', ['error' => $e->getMessage()]);
    exit(1);
}

$sql = "
    SELECT d.imei, d.customer_id, d.speed_limit_kmh, c.default_speed_limit_kmh
    FROM devices d
    LEFT JOIN customers c ON c.id = d.customer_id
    WHERE d.is_active = 1";
if ($onlyImei !== null) {
    $sql .= " AND d.imei = :imei";
}
$sql .= " ORDER BY d.imei";

$devStmt = $db->prepare($sql);
$devStmt->execute($onlyImei !== null ? [':imei' => $onlyImei] : []);
$devices = $devStmt->fetchAll();

$defaultSince   = gmdate('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));
$totalSegments  = 0;
$totalSpeeding  = 0;
$totalPoints    = 0;
$devicesTouched = 0;

foreach ($devices as $dev) {
    $imei  = $dev['imei'];
    $cid   = $dev['customer_id'] !== null ? (int)$dev['customer_id'] : null;
    $limit = resolve_speed_limit($dev['speed_limit_kmh'], $dev['default_speed_limit_kmh']);

    // Marca-d'água de cada máquina. As duas caminham separadas porque um
    // excesso de velocidade pode ter começado antes do segmento em curso.
    $sinceState = watermark($db, 'device_state_segments', $imei) ?? $defaultSince;
    $sinceSpeed = watermark($db, 'speeding_events', $imei) ?? $defaultSince;
    $since = min($sinceState, $sinceSpeed);

    // Lê UMA vez o que interessa às duas máquinas; cada uma descarta o que já
    // avaliou (filtro por marca-d'água própria dentro de cada passada).
    $stmt = $db->prepare("
        SELECT gps_time, latitude, longitude, speed, acc
        FROM gps_data
        WHERE imei = :imei AND gps_time >= :since
        ORDER BY gps_time ASC
        LIMIT " . STATE_MAX_POINTS_PER_DEVICE);
    $stmt->execute([':imei' => $imei, ':since' => $since]);
    $points = $stmt->fetchAll();

    if (count($points) < 1) {
        continue;
    }
    $totalPoints += count($points);
    $devicesTouched++;

    try {
        $db->beginTransaction();
        $totalSegments += buildStateSegments($db, $imei, $cid, $points, $sinceState);
        $totalSpeeding += buildSpeedingEvents($db, $imei, $cid, $points, $sinceSpeed, $limit);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        Logger::error('State Builder: falha ao segmentar equipamento', [
            'imei'  => $imei,
            'error' => $e->getMessage(),
        ]);
    }
}

// "gravações" e não "segmentos": o segmento em curso é reescrito a cada rodada
// (mesma chave), então o número conta escritas, não linhas novas.
echo "State Builder: {$devicesTouched} equipamentos, {$totalPoints} pontos, "
   . "{$totalSegments} gravações de segmento, {$totalSpeeding} eventos de velocidade.\n";

/**
 * De onde retomar a leitura para uma das duas tabelas.
 *
 * Se existe registro ABERTO (`ended_at IS NULL`), retoma do `started_at` dele
 * — o registro será regravado sobre a mesma chave única e nada duplica. Sem
 * aberto, retoma do último `ended_at` fechado, que é a fronteira do próximo
 * segmento. Sem nada, devolve NULL para o chamador aplicar o lookback.
 *
 * @param PDO    $db    Conexão ativa
 * @param string $table device_state_segments|speeding_events
 * @param string $imei  Equipamento
 * @returns string|null Datetime UTC de retomada
 */
function watermark(PDO $db, string $table, string $imei): ?string
{
    $stmt = $db->prepare("
        SELECT started_at FROM `$table`
        WHERE imei = :imei AND ended_at IS NULL
        ORDER BY started_at DESC LIMIT 1");
    $stmt->execute([':imei' => $imei]);
    $open = $stmt->fetchColumn();
    if ($open) {
        return $open;
    }

    $stmt = $db->prepare("SELECT MAX(ended_at) FROM `$table` WHERE imei = :imei");
    $stmt->execute([':imei' => $imei]);
    $closed = $stmt->fetchColumn();

    return $closed ?: null;
}

/**
 * Segmenta os pontos em estados contíguos e persiste.
 *
 * @param PDO      $db     Conexão ativa (em transação)
 * @param string   $imei   Equipamento
 * @param int|null $cid    Cliente do equipamento
 * @param array    $points Pontos ordenados por gps_time ASC
 * @param string   $since  Marca-d'água: pontos anteriores a ela são ignorados
 * @returns int Quantidade de segmentos gravados
 */
function buildStateSegments(PDO $db, string $imei, ?int $cid, array $points, string $since): int
{
    $seg   = null;  // segmento em construção
    $prev  = null;  // ponto anterior aceito
    $saved = 0;

    foreach ($points as $p) {
        if ($p['gps_time'] < $since) {
            continue;
        }
        // Coordenada (0,0) ou fora de faixa não diz nada sobre posição (R06).
        // O ponto é descartado inteiro: usá-lo faria a distância do segmento
        // saltar para milhares de km via golfo da Guiné.
        if (!is_valid_coordinate($p['latitude'], $p['longitude'])) {
            continue;
        }

        $state = classify_point($p['acc'], $p['speed']);

        if ($seg === null) {
            $seg  = openSegment($imei, $cid, $state, $p);
            $prev = $p;
            continue;
        }

        $gap = strtotime($p['gps_time']) - strtotime($prev['gps_time']);

        if ($gap >= OFFLINE_GAP_SECONDS) {
            // Buraco: o estado conhecido acaba no último ponto visto, um
            // segmento offline cobre o vão, e o ponto atual abre o próximo.
            // Fechar no ponto ANTERIOR (e não no atual) é o que impede
            // creditar 6 h de "movimento" a um veículo que ficou sem sinal.
            $saved += closeSegment($db, $seg, $prev['gps_time'], $prev);
            $saved += persistSegment($db, [
                'imei'        => $imei,
                'customer_id' => $cid,
                'state'       => 'offline',
                'started_at'  => $prev['gps_time'],
                'ended_at'    => $p['gps_time'],
                'duration_s'  => $gap,
                'start_lat'   => $prev['latitude'],
                'start_lng'   => $prev['longitude'],
                'end_lat'     => $p['latitude'],
                'end_lng'     => $p['longitude'],
                'distance_km' => 0,
                'max_speed'   => 0,
                'point_count' => 0,
            ]);
            $seg  = openSegment($imei, $cid, $state, $p);
            $prev = $p;
            continue;
        }

        if ($state !== $seg['state']) {
            // Mudança de estado: fronteira no ponto NOVO. O segmento antigo
            // vai até aqui e o novo começa no mesmo instante — é o que mantém
            // a linha do tempo sem vão nem sobreposição.
            $saved += closeSegment($db, $seg, $p['gps_time'], $p);
            $seg  = openSegment($imei, $cid, $state, $p);
            $prev = $p;
            continue;
        }

        // Mesmo estado: acumula.
        $seg['end_lat']      = $p['latitude'];
        $seg['end_lng']      = $p['longitude'];
        $seg['distance_km'] += haversine_km(
            (float)$prev['latitude'], (float)$prev['longitude'],
            (float)$p['latitude'],    (float)$p['longitude']
        );
        $seg['max_speed'] = max($seg['max_speed'], (float)$p['speed']);
        $seg['point_count']++;
        $prev = $p;
    }

    // O último segmento fica ABERTO de propósito: o estado ainda está em
    // curso e fechá-lo agora o fatiaria a cada rodada do cron. A tela de
    // Status da Frota resolve o "agora" na leitura, por resolve_current_state().
    if ($seg !== null) {
        $saved += persistSegment($db, [
            'imei'        => $seg['imei'],
            'customer_id' => $seg['customer_id'],
            'state'       => $seg['state'],
            'started_at'  => $seg['started_at'],
            'ended_at'    => null,
            'duration_s'  => null,
            'start_lat'   => $seg['start_lat'],
            'start_lng'   => $seg['start_lng'],
            'end_lat'     => $seg['end_lat'],
            'end_lng'     => $seg['end_lng'],
            'distance_km' => round($seg['distance_km'], 3),
            'max_speed'   => $seg['max_speed'],
            'point_count' => $seg['point_count'],
        ]);
    }

    return $saved;
}

/**
 * Abre um segmento em memória a partir do ponto que o originou.
 *
 * @param string   $imei  Equipamento
 * @param int|null $cid   Cliente
 * @param string   $state movimento|ocioso|parado
 * @param array    $p     Ponto de gps_data
 * @returns array Segmento em construção
 */
function openSegment(string $imei, ?int $cid, string $state, array $p): array
{
    return [
        'imei'        => $imei,
        'customer_id' => $cid,
        'state'       => $state,
        'started_at'  => $p['gps_time'],
        'start_lat'   => $p['latitude'],
        'start_lng'   => $p['longitude'],
        'end_lat'     => $p['latitude'],
        'end_lng'     => $p['longitude'],
        'distance_km' => 0.0,
        'max_speed'   => (float)$p['speed'],
        'point_count' => 1,
    ];
}

/**
 * Fecha um segmento em `$endTime` e persiste.
 *
 * Segmento de duração ZERO não é gravado. Isso acontece quando um único ponto
 * é seguido por um buraco de dados: o segmento é fechado no próprio ponto que
 * o abriu, e o segmento `offline` que cobre o vão começa no MESMO instante —
 * os dois disputariam a chave (imei, started_at) e o offline sobrescreveria o
 * outro via ON DUPLICATE KEY. O resultado seria o certo por acidente; melhor
 * descartar de propósito. Nada se perde: um instante isolado não sustenta
 * afirmação de duração, e o offline já carrega a coordenada daquele ponto.
 *
 * Só o caminho do buraco produz duração zero. A mudança de estado fecha no
 * gps_time do ponto NOVO, sempre posterior ao início do segmento, e gps_data
 * tem UNIQUE (imei, gps_time).
 *
 * @param PDO    $db      Conexão ativa
 * @param array  $seg     Segmento em construção
 * @param string $endTime UTC da fronteira
 * @param array  $endPt   Ponto que define a coordenada final
 * @returns int 1 se gravou, 0 se foi descartado por duração zero
 */
function closeSegment(PDO $db, array $seg, string $endTime, array $endPt): int
{
    if (strtotime($endTime) <= strtotime($seg['started_at'])) {
        return 0;
    }

    return persistSegment($db, [
        'imei'        => $seg['imei'],
        'customer_id' => $seg['customer_id'],
        'state'       => $seg['state'],
        'started_at'  => $seg['started_at'],
        'ended_at'    => $endTime,
        'duration_s'  => max(0, strtotime($endTime) - strtotime($seg['started_at'])),
        'start_lat'   => $seg['start_lat'],
        'start_lng'   => $seg['start_lng'],
        'end_lat'     => $endPt['latitude'],
        'end_lng'     => $endPt['longitude'],
        'distance_km' => round($seg['distance_km'], 3),
        'max_speed'   => $seg['max_speed'],
        'point_count' => $seg['point_count'],
    ]);
}

/**
 * Grava um segmento.
 *
 * ON DUPLICATE KEY UPDATE sobre uk_dss_imei_start (imei, started_at) é o que
 * permite ao segmento em curso ser reescrito — estendido ou fechado — na
 * rodada seguinte, em vez de virar um segundo registro sobreposto.
 *
 * @param PDO   $db  Conexão ativa
 * @param array $seg Segmento pronto
 * @returns int 1
 */
function persistSegment(PDO $db, array $seg): int
{
    $stmt = $db->prepare("
        INSERT INTO device_state_segments
            (imei, customer_id, state, started_at, ended_at, duration_s,
             start_lat, start_lng, end_lat, end_lng, distance_km, max_speed, point_count)
        VALUES
            (:imei, :cid, :state, :st, :et, :dur,
             :sla, :slg, :ela, :elg, :dist, :ms, :pc)
        ON DUPLICATE KEY UPDATE
            state = VALUES(state), ended_at = VALUES(ended_at), duration_s = VALUES(duration_s),
            end_lat = VALUES(end_lat), end_lng = VALUES(end_lng),
            distance_km = VALUES(distance_km), max_speed = VALUES(max_speed),
            point_count = VALUES(point_count)
    ");
    $stmt->execute([
        ':imei'  => $seg['imei'],
        ':cid'   => $seg['customer_id'],
        ':state' => $seg['state'],
        ':st'    => $seg['started_at'],
        ':et'    => $seg['ended_at'],
        ':dur'   => $seg['duration_s'],
        ':sla'   => $seg['start_lat'],
        ':slg'   => $seg['start_lng'],
        ':ela'   => $seg['end_lat'],
        ':elg'   => $seg['end_lng'],
        ':dist'  => $seg['distance_km'],
        ':ms'    => $seg['max_speed'],
        ':pc'    => $seg['point_count'],
    ]);
    return 1;
}

/**
 * Detecta corridas de pontos acima do limite e persiste como eventos.
 *
 * Fecha a corrida quando a velocidade cai até o limite ou quando há buraco de
 * dados (o silêncio não pode ser contado como excesso). Corrida com menos de
 * MIN_SPEEDING_POINTS pontos é descartada — é spike de GPS, não infração.
 *
 * @param PDO      $db     Conexão ativa (em transação)
 * @param string   $imei   Equipamento
 * @param int|null $cid    Cliente
 * @param array    $points Pontos ordenados por gps_time ASC
 * @param string   $since  Marca-d'água própria desta máquina
 * @param int      $limit  Limite vigente em km/h
 * @returns int Quantidade de eventos gravados
 */
function buildSpeedingEvents(PDO $db, string $imei, ?int $cid, array $points, string $since, int $limit): int
{
    $run   = null;
    $prev  = null;
    $saved = 0;

    foreach ($points as $p) {
        if ($p['gps_time'] < $since) {
            continue;
        }
        if (!is_valid_coordinate($p['latitude'], $p['longitude'])) {
            continue;
        }

        $speed = (float)$p['speed'];
        $over  = $speed > $limit;

        // Buraco de dados encerra a corrida: não se afirma excesso contínuo
        // atravessando meia hora sem dado.
        if ($run !== null && $prev !== null
            && (strtotime($p['gps_time']) - strtotime($prev['gps_time'])) >= OFFLINE_GAP_SECONDS) {
            $saved += persistSpeeding($db, $run, $prev['gps_time'], $limit);
            $run = null;
        }

        if ($over) {
            if ($run === null) {
                $run = [
                    'imei'        => $imei,
                    'customer_id' => $cid,
                    'started_at'  => $p['gps_time'],
                    'start_lat'   => $p['latitude'],
                    'start_lng'   => $p['longitude'],
                    'max_speed'   => $speed,
                    'max_lat'     => $p['latitude'],
                    'max_lng'     => $p['longitude'],
                    'sum_speed'   => $speed,
                    'point_count' => 1,
                ];
            } else {
                if ($speed > $run['max_speed']) {
                    $run['max_speed'] = $speed;
                    $run['max_lat']   = $p['latitude'];
                    $run['max_lng']   = $p['longitude'];
                }
                $run['sum_speed'] += $speed;
                $run['point_count']++;
            }
        } elseif ($run !== null) {
            // Voltou ao limite: a infração acaba neste ponto.
            $saved += persistSpeeding($db, $run, $p['gps_time'], $limit);
            $run = null;
        }

        $prev = $p;
    }

    // Corrida ainda em curso no fim do lote fica ABERTA (mesma mecânica do
    // segmento aberto): a rodada seguinte retoma do started_at e a completa.
    if ($run !== null) {
        $saved += persistSpeeding($db, $run, null, $limit);
    }

    return $saved;
}

/**
 * Grava um evento de excesso de velocidade.
 *
 * @param PDO         $db      Conexão ativa
 * @param array       $run     Corrida acumulada
 * @param string|null $endTime UTC do fim (NULL = ainda em curso)
 * @param int         $limit   Limite apurado
 * @returns int 1 se gravou, 0 se a corrida foi descartada pelo piso de pontos
 */
function persistSpeeding(PDO $db, array $run, ?string $endTime, int $limit): int
{
    if ($run['point_count'] < MIN_SPEEDING_POINTS) {
        return 0;
    }

    $stmt = $db->prepare("
        INSERT INTO speeding_events
            (imei, customer_id, started_at, ended_at, duration_s, max_speed, avg_speed,
             limit_kmh, start_lat, start_lng, max_lat, max_lng, point_count)
        VALUES
            (:imei, :cid, :st, :et, :dur, :ms, :avg,
             :lim, :sla, :slg, :mla, :mlg, :pc)
        ON DUPLICATE KEY UPDATE
            ended_at = VALUES(ended_at), duration_s = VALUES(duration_s),
            max_speed = VALUES(max_speed), avg_speed = VALUES(avg_speed),
            limit_kmh = VALUES(limit_kmh), max_lat = VALUES(max_lat), max_lng = VALUES(max_lng),
            point_count = VALUES(point_count)
    ");
    $stmt->execute([
        ':imei' => $run['imei'],
        ':cid'  => $run['customer_id'],
        ':st'   => $run['started_at'],
        ':et'   => $endTime,
        ':dur'  => $endTime !== null ? max(0, strtotime($endTime) - strtotime($run['started_at'])) : null,
        ':ms'   => $run['max_speed'],
        ':avg'  => round($run['sum_speed'] / max(1, $run['point_count']), 2),
        ':lim'  => $limit,
        ':sla'  => $run['start_lat'],
        ':slg'  => $run['start_lng'],
        ':mla'  => $run['max_lat'],
        ':mlg'  => $run['max_lng'],
        ':pc'   => $run['point_count'],
    ]);
    return 1;
}
