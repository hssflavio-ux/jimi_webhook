<?php
/**
 * JIMI Webhook System — Relatório de Deslocamento v4.3.0
 * Rota: /relatorios/deslocamento
 *
 * Duas modalidades (toggle no filtro):
 *   - viagens: uma linha por deslocamento (intervalo ignição lig→desl) — tabela trips.
 *   - diario:  fechamento por dia (BRT): primeira ignição ligada → última desligada,
 *              agregando jornada, tempo em movimento, KM, vel. máx e alarmes.
 *
 * Filtro: Ativos + Período (teto global de 31 dias) + faixa horária opcional
 * + [Gerar] + Export. Cada linha tem link "Ver rota" para o mapa do percurso
 * (/relatorios/deslocamento/rota) — que exige login, por isso o PDF e o XLSX
 * levam no lugar dois links de OSM (partida e chegada), que abrem para quem
 * recebeu o arquivo sem ter conta no sistema.
 *
 * Distância, Hodômetro (os dois lidos do sensor real, gps_data.mileage — v4.21.7,
 * pedido do dono do produto: todo cálculo de deslocamento passou a se basear
 * no hodômetro, nunca em GPS) e Horímetro (tempo de ignição ligada,
 * CALCULADO — soma de device_state_segments, nunca o hardware do
 * equipamento) nas duas modalidades, com totalizador de período inteiro no
 * rodapé (não só a página atual) — ver ignition_seconds_in_window()/
 * odometer_km()/odometer_delta_km() em includes/functions.php e os helpers
 * desloc_*() logo abaixo.
 *
 * Distância = hodômetro final menos hodômetro inicial do trecho (viagem ou
 * dia) — MAX(mileage)-MIN(mileage) na janela, `desloc_*_by_day()`/
 * `$hodDeltaSubquery` abaixo. Hodômetro = leitura ABSOLUTA do contador no FIM
 * do trecho — MAX(mileage) sozinho, `desloc_odometer_end_by_day()`/
 * `$hodEndSubquery`. As duas saem "—" para equipamentos que não populam
 * leitura real (ver memória hodometro-bateria-medicao-producao) — SEM cair
 * para cálculo por GPS. `trips.distance_km`/`device_state_segments.distance_km`
 * gravados ANTES desta versão continuam com o valor antigo (Haversine, não
 * hodômetro); esta tela nunca lê essas colunas para Distância/Hodômetro —
 * sempre recalcula ao vivo a partir de gps_data.mileage, então isso não
 * afeta o que é exibido aqui, nem para viagem antiga nem nova.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('rel_deslocamento', '/relatorios/deslocamento');

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$mode       = ($_GET['mode'] ?? 'viagens') === 'diario' ? 'diario' : 'viagens';
$selImei    = $_GET['imei'] ?? '';
$filterCust = $_GET['customer_id'] ?? null;
$dateFrom = $_GET['date_from'] ?? brt_today('Y-m-d', '-7 days');
$dateTo   = $_GET['date_to'] ?? brt_today();
$timeFrom = $_GET['time_from'] ?? '';
$timeTo   = $_GET['time_to'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$generated = !empty($_GET['gerar']);

[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo);

// Ordenação por modalidade; ambas abrem crescente por data/hora
[$sort, $order] = $mode === 'diario'
    ? report_sort_params(['dia'], 'dia', 'ASC')
    : report_sort_params(['started_at', 'ended_at', 'distance_km', 'max_speed'], 'started_at', 'ASC');

$rows = [];
$totalRows = 0;
$totalPages = 1;

// Escopo multi-tenant centralizado — ver report_customer_scope(). Resolvido
// AQUI porque é ele quem decide de qual cliente vêm as placas do seletor: a
// lista carregada com o cliente da SESSÃO ignorava o filtro da tela, e o
// admin que trocasse de cliente no formulário continuava vendo as placas do
// anterior. Para não-admin o `?customer_id=` é ignorado (não validado)
// dentro da própria função.
$scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);

// Lista de placas — carregada AQUI e não depois da grade, porque o export
// síncrono roda antes e precisa dela para montar o subtítulo do PDF.
$devices   = report_device_options($db, $scopeCust);
$customers = $isAdmin ? report_customer_options($db) : [];

/**
 * Subtítulo do export: o FILTRO aplicado, não só o período.
 *
 * Um PDF de relatório circula solto — vai por e-mail, é impresso, é anexado a
 * processo. Sem dizer de qual placa e de qual período ele trata, o documento
 * não se sustenta fora da tela que o gerou.
 *
 * @param string $selImei  IMEI selecionado ('' = todos)
 * @param array  $devices  Lista imei/device_name do cliente
 * @param string $dateFrom Dia inicial (BRT)
 * @param string $dateTo   Dia final (BRT)
 * @param string $mode     'viagens' | 'diario'
 * @param string $timeFrom Hora inicial do filtro ('H:i'; vazio = 00:00)
 * @param string $timeTo   Hora final do filtro ('H:i'; vazio = 23:59)
 * @returns string
 */
function desloc_subtitulo(string $selImei, array $devices, string $dateFrom, string $dateTo, string $mode, string $timeFrom = '', string $timeTo = ''): string
{
    $placa = 'Todas as placas';
    if ($selImei !== '') {
        foreach ($devices as $d) {
            if ($d['imei'] === $selImei) {
                $placa = 'Placa: ' . ($d['device_name'] ?: $selImei);
                break;
            }
        }
        if ($placa === 'Todas as placas') $placa = 'Placa: ' . $selImei;
    }
    $modo = $mode === 'diario' ? ' — fechamento diário' : '';
    // Período em DD/MM/AAAA com a hora sempre explícita — ver report_period_label()
    return $placa . '  |  ' . report_period_label($dateFrom, $dateTo, $timeFrom, $timeTo) . $modo;
}

/**
 * Segmentos de ignição (`device_state_segments`) de uma lista de IMEIs, no
 * período do filtro — UMA busca para o relatório inteiro (grade + export +
 * totalizador), reaproveitada por `ignition_seconds_in_window()`
 * (includes/functions.php) por viagem ou por dia.
 *
 * Tabela pequena (dezenas de segmentos por dia por equipamento): buscar tudo
 * de uma vez é mais barato que uma subquery correlacionada por linha, e evita
 * depender de `device_state_segments.customer_id` — que é o dono ATUAL no
 * momento em que o cron rodou, não o snapshot histórico do evento (mesma
 * armadilha que o CLAUDE.md documenta para gps_data/alarms na Fase 2). Os
 * IMEIs já vêm escopados de `trips.imei`, que é confiável.
 *
 * @param PDO    $db
 * @param array  $imeis   IMEIs já escopados (de trips, nunca um JOIN novo)
 * @param string $utcFrom
 * @param string $utcTo
 * @returns array Linhas (imei, state, started_at, duration_s)
 */
function desloc_state_segments(PDO $db, array $imeis, string $utcFrom, string $utcTo): array
{
    if (!$imeis) return [];
    $ph = implode(',', array_fill(0, count($imeis), '?'));
    $stmt = $db->prepare("
        SELECT imei, state, started_at, duration_s
        FROM device_state_segments
        WHERE imei IN ($ph) AND ended_at IS NOT NULL AND started_at BETWEEN ? AND ?
    ");
    $stmt->execute(array_merge($imeis, [$utcFrom, $utcTo]));
    return $stmt->fetchAll();
}

/**
 * Delta de hodômetro (bruto, metros) por (imei, dia BRT) — usado no modo
 * "fechamento diário". `MAX(mileage) - MIN(mileage)` em vez de
 * primeira/última por horário: o odômetro só cresce, então sob leitura
 * normal dá o mesmo resultado com uma agregação só. `mileage > 0` descarta
 * os equipamentos que sempre mandam a leitura zerada (ver
 * odometer_km() em includes/functions.php).
 *
 * @param PDO    $db
 * @param array  $imeis   IMEIs já escopados (de trips, nunca um JOIN novo)
 * @param string $utcFrom
 * @param string $utcTo
 * @returns array<string,mixed> chave "IMEI|YYYY-MM-DD" => delta bruto (metros), só dias com leitura válida
 */
function desloc_odometer_by_day(PDO $db, array $imeis, string $utcFrom, string $utcTo): array
{
    if (!$imeis) return [];
    $ph = implode(',', array_fill(0, count($imeis), '?'));
    $stmt = $db->prepare("
        SELECT imei, DATE(CONVERT_TZ(gps_time, '+00:00', '-03:00')) AS dia,
               MAX(mileage) - MIN(mileage) AS hod_delta
        FROM gps_data
        WHERE imei IN ($ph) AND gps_time BETWEEN ? AND ? AND mileage > 0
        GROUP BY imei, dia
    ");
    $stmt->execute(array_merge($imeis, [$utcFrom, $utcTo]));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['imei'] . '|' . $r['dia']] = $r['hod_delta'];
    }
    return $out;
}

/**
 * Leitura ABSOLUTA do hodômetro ao FIM de cada (imei, dia BRT) — a maior
 * `mileage` válida (>0) do dia, para a coluna "Hodômetro" (v4.21.7: deixou
 * de mostrar o delta — que virou a coluna "Distância" — e passou a mostrar
 * o valor real do contador no fim do trecho). Mesma janela e mesmo filtro
 * de `desloc_odometer_by_day()`, uma segunda agregação sobre a MESMA
 * consulta seria mais barata, mas manter as duas funções espelhadas evita
 * amarrar quem lê uma a saber que a outra existe.
 *
 * @param PDO    $db
 * @param array  $imeis   IMEIs já escopados (de trips, nunca um JOIN novo)
 * @param string $utcFrom
 * @param string $utcTo
 * @returns array<string,mixed> chave "IMEI|YYYY-MM-DD" => leitura bruta (metros), só dias com leitura válida
 */
function desloc_odometer_end_by_day(PDO $db, array $imeis, string $utcFrom, string $utcTo): array
{
    if (!$imeis) return [];
    $ph = implode(',', array_fill(0, count($imeis), '?'));
    $stmt = $db->prepare("
        SELECT imei, DATE(CONVERT_TZ(gps_time, '+00:00', '-03:00')) AS dia,
               MAX(mileage) AS hod_end
        FROM gps_data
        WHERE imei IN ($ph) AND gps_time BETWEEN ? AND ? AND mileage > 0
        GROUP BY imei, dia
    ");
    $stmt->execute(array_merge($imeis, [$utcFrom, $utcTo]));
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['imei'] . '|' . $r['dia']] = $r['hod_end'];
    }
    return $out;
}

/**
 * Janela UTC [início, fim) de um dia BRT — fim EXCLUSIVO (início do dia
 * seguinte), para casar com o contrato de `ignition_seconds_in_window()`.
 *
 * @param string $dia 'Y-m-d' (dia BRT)
 * @returns array{0:string,1:string} [inícioUtc, fimUtcExclusivo]
 */
function desloc_brt_day_bounds_utc(string $dia): array
{
    [$from] = brt_day_range_to_utc($dia, $dia);
    $nextDia = date('Y-m-d', strtotime($dia . ' +1 day'));
    [$until] = brt_day_range_to_utc($nextDia, $nextDia);
    return [$from, $until];
}

// Fechamento diário: agrega as viagens do dia BRT (primeira ignição ligada →
// última desligada). Viagem que cruza a meia-noite conta inteira no dia em que
// começou. CONVERT_TZ por offset fixo (BRT sem DST), convenção do projeto.
$dailySelect = "
    SELECT t.imei,
           MAX(COALESCE(d.device_name, t.imei)) AS device_name,
           DATE(CONVERT_TZ(t.started_at, '+00:00', '-03:00')) AS dia,
           MIN(t.started_at) AS primeira_on,
           MAX(t.ended_at) AS ultima_off,
           TIMESTAMPDIFF(SECOND, MIN(t.started_at), MAX(t.ended_at)) AS jornada_s,
           SUM(t.duration_s) AS movimento_s,
           SUM(t.distance_km) AS distance_km,
           MAX(t.max_speed) AS max_speed,
           SUM(t.alarm_count) AS alarm_count,
           COUNT(*) AS viagens
    FROM trips t
    LEFT JOIN devices d ON d.imei = t.imei";

if ($generated) {
    $where = 'WHERE t.started_at BETWEEN :df AND :dt';
    // Dias BRT (+ faixa horária opcional) → janela UTC
    [$utcFrom, $utcTo] = brt_datetime_range_to_utc($dateFrom, $dateTo, $timeFrom, $timeTo);
    $params = [':df' => $utcFrom, ':dt' => $utcTo];

    // Snapshot do dono no momento da viagem (`trips.customer_id`), nunca o
    // dono ATUAL da câmera via JOIN em devices — ver Fase 2 no CLAUDE.md.
    if ($scopeCust !== null) {
        $where .= ' AND t.customer_id = :cid';
        $params[':cid'] = $scopeCust;
    }
    if ($selImei) {
        $where .= ' AND t.imei = :imei';
        $params[':imei'] = $selImei;
    }

    // Base para Hodômetro/Horímetro: IMEIs escopados por trips.imei (nunca um
    // JOIN novo em devices — ver Fase 2 no CLAUDE.md), segmentos de ignição
    // do período inteiro (uma busca só — tabela pequena) e, no modo diário,
    // o delta de hodômetro por dia. +1 dia de folga no fim dos segmentos:
    // uma viagem pode começar dentro do filtro e terminar depois dele.
    // Try/catch próprio (não só o de baixo): tabela `trips` ausente não pode
    // virar fatal aqui, mesma tolerância que o resto do arquivo já tem.
    $scopedImeis = [];
    $stateSegs = [];
    $hodByDay = [];
    $hodEndByDay = [];
    try {
        $imeisStmt = $db->prepare("SELECT DISTINCT t.imei FROM trips t $where");
        $imeisStmt->execute($params);
        $scopedImeis = $imeisStmt->fetchAll(PDO::FETCH_COLUMN);
        $segsUntil = date('Y-m-d H:i:s', strtotime($utcTo) + 86400);
        $stateSegs = desloc_state_segments($db, $scopedImeis, $utcFrom, $segsUntil);
        $hodByDay = $mode === 'diario' ? desloc_odometer_by_day($db, $scopedImeis, $utcFrom, $utcTo) : [];
        $hodEndByDay = $mode === 'diario' ? desloc_odometer_end_by_day($db, $scopedImeis, $utcFrom, $utcTo) : [];
    } catch (Exception $e) { /* tabela trips ausente → Hodômetro/Horímetro saem "—" */ }

    // Precisa de SYNC_EXPORT_MAX_ROWS mesmo fora do export: o totalizador do
    // rodapé reaproveita a MESMA consulta sem paginação do export síncrono
    // (ver $allRows abaixo), para nunca discordar do que o arquivo mostra.
    require_once __DIR__ . '/../includes/export_helper.php';

    // Subquery de hodômetro por viagem: MAX-MIN em vez de primeira/última por
    // horário — o odômetro só cresce, então dá o mesmo resultado com uma
    // agregação só. `mileage > 0` descarta os equipamentos que sempre mandam
    // leitura zerada (ver odometer_km() em includes/functions.php). NULL
    // (nenhuma leitura válida na janela) e 0 (uma leitura só, ou parado com
    // hodômetro real) são resultados DIFERENTES — só o primeiro vira "—".
    $hodDeltaSubquery = "(SELECT MAX(g.mileage) - MIN(g.mileage) FROM gps_data g
        WHERE g.imei = t.imei AND g.gps_time BETWEEN t.started_at AND t.ended_at
          AND g.mileage > 0) AS hod_delta";

    // Leitura ABSOLUTA do hodômetro ao fim da viagem — coluna "Hodômetro"
    // (v4.21.7). Mesma janela e mesmo filtro de $hodDeltaSubquery ("Distância"),
    // só troca MAX-MIN por MAX sozinho.
    $hodEndSubquery = "(SELECT MAX(g.mileage) FROM gps_data g
        WHERE g.imei = t.imei AND g.gps_time BETWEEN t.started_at AND t.ended_at
          AND g.mileage > 0) AS hod_end";

    // Totais do período INTEIRO (não só a página atual): rodam sobre a MESMA
    // consulta sem paginação usada pelo export (teto de SYNC_EXPORT_MAX_ROWS)
    // — corrida uma vez, reaproveitada pelos dois, para o rodapé nunca
    // discordar do arquivo exportado.
    //
    // $totHodKm/$hodTemDado são o total de "Distância" (soma dos deltas de
    // hodômetro do período) — não existe total de "Hodômetro" (leitura
    // ABSOLUTA): somar leituras de contador de viagens/dias diferentes não
    // tem significado, por isso a célula do rodapé daquela coluna é sempre
    // "—", igual a Vel. Máx.
    $allRows = [];
    $totAlarms = 0; $totViagens = 0; $totMovimentoS = 0;
    $totHorimetroS = 0; $totHodKm = 0.0; $hodTemDado = false;
    try {
        if ($mode === 'diario') {
            $allStmt = $db->prepare("$dailySelect $where
                GROUP BY t.imei, dia ORDER BY dia $order, device_name
                LIMIT " . SYNC_EXPORT_MAX_ROWS);
            $allStmt->execute($params);
            $allRows = $allStmt->fetchAll();
            foreach ($allRows as $r) {
                $totAlarms     += (int)($r['alarm_count'] ?? 0);
                $totViagens    += (int)($r['viagens'] ?? 0);
                $totMovimentoS += (int)($r['movimento_s'] ?? 0);
                [$dayFrom, $dayUntil] = desloc_brt_day_bounds_utc($r['dia']);
                $totHorimetroS += ignition_seconds_in_window($stateSegs, $r['imei'], $dayFrom, $dayUntil);
                $hodRaw = $hodByDay[$r['imei'] . '|' . $r['dia']] ?? null;
                if ($hodRaw !== null) { $totHodKm += odometer_km($hodRaw); $hodTemDado = true; }
            }
        } else {
            $allStmt = $db->prepare("
                SELECT t.*, COALESCE(d.device_name, t.imei) as device_name,
                       COALESCE(dr.name, '—') as driver_name,
                       $hodDeltaSubquery,
                       $hodEndSubquery
                FROM trips t
                LEFT JOIN devices d ON d.imei = t.imei
                LEFT JOIN drivers dr ON dr.id = t.driver_id
                $where
                ORDER BY t.$sort $order
                LIMIT " . SYNC_EXPORT_MAX_ROWS);
            $allStmt->execute($params);
            $allRows = $allStmt->fetchAll();
            foreach ($allRows as $r) {
                $totAlarms     += (int)($r['alarm_count'] ?? 0);
                $totHorimetroS += ignition_seconds_in_window($stateSegs, $r['imei'], $r['started_at'], $r['ended_at']);
                if ($r['hod_delta'] !== null) { $totHodKm += odometer_km($r['hod_delta']); $hodTemDado = true; }
            }
            $totViagens = count($allRows);
        }
    } catch (Exception $e) { /* tabela trips ausente → totais zerados */ }

    // Export síncrono (padrão YUV §9.2): reaproveita $allRows acima.
    $export = $_GET['export'] ?? '';
    if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
        require_permission('relatorios', 'export');
        $expRows = [];
        if ($mode === 'diario') {
            foreach ($allRows as $r) {
                [$dayFrom, $dayUntil] = desloc_brt_day_bounds_utc($r['dia']);
                $horimetroS = ignition_seconds_in_window($stateSegs, $r['imei'], $dayFrom, $dayUntil);
                $hodDelta = $hodByDay[$r['imei'] . '|' . $r['dia']] ?? null;
                $hodEnd   = $hodEndByDay[$r['imei'] . '|' . $r['dia']] ?? null;
                $expRows[] = [
                    fmt_brt($r['primeira_on'], 'd/m/Y'),
                    $r['device_name'],
                    fmt_brt($r['primeira_on'], 'd/m/Y H:i'),
                    $r['ultima_off'] ? fmt_brt($r['ultima_off'], 'd/m/Y H:i') : '—',
                    fmt_duration((int)($r['jornada_s'] ?? 0)),
                    fmt_duration($horimetroS),
                    fmt_duration((int)($r['movimento_s'] ?? 0)),
                    $hodDelta !== null ? number_format(odometer_km($hodDelta), 1, ',', '.') : '—',
                    $hodEnd !== null ? number_format(odometer_km($hodEnd), 1, ',', '.') : '—',
                    $r['max_speed'] ? number_format((float)$r['max_speed'], 1) : '—',
                    (int)($r['alarm_count'] ?? 0),
                    (int)$r['viagens'],
                ];
            }
            if (!empty($expRows)) {
                $expRows[] = ['TOTAL DO PERÍODO', '', '', '', '—', fmt_duration($totHorimetroS),
                    fmt_duration($totMovimentoS),
                    $hodTemDado ? number_format($totHodKm, 1, ',', '.') : '—',
                    '—', '—', $totAlarms, $totViagens];
            }
            stream_export($export, 'relatorio_deslocamento_diario',
                ['Dia', 'Placa', 'Primeira Ignição', 'Última Ignição', 'Jornada', 'Horímetro', 'Em Movimento', 'Distância (km)', 'Hodômetro (km)', 'Vel. Máx (km/h)', 'Alarmes', 'Viagens'],
                $expRows, 'Relatório de Deslocamento — Fechamento Diário',
                desloc_subtitulo($selImei, $devices, $dateFrom, $dateTo, $mode, $timeFrom, $timeTo),
                // Data/hora e os cabeçalhos longos pedem mais que as colunas
                // numéricas (jornada, horímetro, km, alarmes, viagens).
                [1.0, 1.0, 1.3, 1.3, 0.9, 0.9, 1.0, 1.0, 1.0, 1.05, 0.8, 0.75]);
        } else {
            foreach ($allRows as $r) {
                $horimetroS = ignition_seconds_in_window($stateSegs, $r['imei'], $r['started_at'], $r['ended_at']);
                $expRows[] = [
                    $r['device_name'],
                    $r['driver_name'],
                    fmt_brt($r['started_at']),
                    $r['ended_at'] ? fmt_brt($r['ended_at']) : '—',
                    fmt_duration((int)($r['duration_s'] ?? 0)),
                    fmt_duration($horimetroS),
                    $r['max_speed'] ? number_format((float)$r['max_speed'], 1) : '—',
                    $r['hod_delta'] !== null ? number_format(odometer_km($r['hod_delta']), 1, ',', '.') : '—',
                    $r['hod_end'] !== null ? number_format(odometer_km($r['hod_end']), 1, ',', '.') : '—',
                    (int)($r['alarm_count'] ?? 0),
                    // Dois PONTOS no OSM no lugar da antiga coluna "Rota"
                    // (v4.9.0). Aquela apontava para /relatorios/deslocamento/rota,
                    // tela nossa e atrás de login: quem recebia o arquivo por
                    // e-mail sem conta no sistema caía na tela de login. E o
                    // OSM público não sabe desenhar um percurso a partir de
                    // uma URL — só aceita marcador (?mlat/?mlon) ou uma rota
                    // RECALCULADA pelo motor de rotas, que não é o caminho
                    // que o veículo fez. Partida e chegada abrem para
                    // qualquer um; o traçado real continua na tela, para
                    // quem tem login.
                    export_map_link($r['start_lat'], $r['start_lng'], 'PARTIDA'),
                    export_map_link($r['end_lat'], $r['end_lng'], 'CHEGADA'),
                ];
            }
            if (!empty($expRows)) {
                $expRows[] = ['TOTAL DO PERÍODO', '', '', '', '—', fmt_duration($totHorimetroS),
                    '—',
                    $hodTemDado ? number_format($totHodKm, 1, ',', '.') : '—',
                    '—', $totAlarms, '', ''];
            }
            stream_export($export, 'relatorio_deslocamento',
                ['Placa', 'Motorista', 'Início', 'Término', 'Duração', 'Horímetro', 'Vel. Máx (km/h)', 'Distância (km)', 'Hodômetro (km)', 'Alarmes', 'Mapa (partida)', 'Mapa (chegada)'],
                $expRows, 'Relatório de Deslocamento',
                desloc_subtitulo($selImei, $devices, $dateFrom, $dateTo, $mode, $timeFrom, $timeTo),
                [1.0, 1.5, 1.3, 1.3, 0.85, 0.85, 1.05, 1.0, 1.0, 0.8, 0.8, 0.85]);
        }
    }

    try {
        if ($mode === 'diario') {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM (
                SELECT 1 FROM trips t $where
                GROUP BY t.imei, DATE(CONVERT_TZ(t.started_at, '+00:00', '-03:00'))) x");
        } else {
            $countStmt = $db->prepare("SELECT COUNT(*) FROM trips t $where");
        }
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;

        if ($mode === 'diario') {
            $stmt = $db->prepare("$dailySelect $where
                GROUP BY t.imei, dia ORDER BY dia $order, device_name
                LIMIT $perPage OFFSET $offset");
        } else {
            $stmt = $db->prepare("
                SELECT t.*, COALESCE(d.device_name, t.imei) as device_name,
                       COALESCE(dr.name, '—') as driver_name,
                       $hodDeltaSubquery,
                       $hodEndSubquery
                FROM trips t
                LEFT JOIN devices d ON d.imei = t.imei
                LEFT JOIN drivers dr ON dr.id = t.driver_id
                $where
                ORDER BY t.$sort $order
                LIMIT $perPage OFFSET $offset
            ");
        }
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        $totalRows = 0; $totalPages = 1; $rows = [];
    }
}

/**
 * Formata segundos como duração legível (ex.: 3h05m).
 *
 * @param int $s Duração em segundos
 * @returns string
 */
function fmt_duration(int $s): string {
    if ($s <= 0) return '—';
    return sprintf('%dh%02dm', floor($s / 3600), floor(($s % 3600) / 60));
}

// ($devices já foi carregado antes do bloco de export)

$page_title = 'Relatório de Deslocamento';
$current_route = 'rel_deslocamento';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php
$expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ);
// Filtro (inclusive página/ordenação) para "Ver rota"/"Replay" devolverem à
// tela com o resultado já gerado, não ao formulário vazio — ver
// rel_deslocamento_rota.php e rel_deslocamento_replay.php.
$backQ = $_GET; unset($backQ['export']);
$returnTo = urlencode('/relatorios/deslocamento?' . http_build_query($backQ));
// O cliente filtrado viaja junto no link: as duas telas de destino escopam
// pelo MESMO report_customer_scope(), e sem este parâmetro elas cairiam no
// cliente da SESSÃO — a linha aparecia na grade e o mapa dizia "não
// encontrada". Vazio quando o filtro está em "Todos" ou o usuário não é admin.
$drillCust = ($scopeCust !== null && $filterCust !== null && $filterCust !== '')
    ? '&customer_id=' . (int)$scopeCust : '';
?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Deslocamento</h2>
    <?php if ($generated): ?>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?= report_back_button('/relatorios/deslocamento') ?>
    </div>
    <?php endif; ?>
</div>

<?php render_template_bar('rel_deslocamento', '/relatorios/deslocamento'); ?>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <input type="hidden" name="gerar" value="1">
        <?php if ($isAdmin): ?>
        <div>
            <label for="flt-customer_id" class="filtro-rotulo">Cliente</label>
            <select id="flt-customer_id" name="customer_id" class="filtro-campo" style="min-width:170px;">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $filterCust == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="flt-mode" class="filtro-rotulo">Modalidade</label>
            <select id="flt-mode" name="mode" class="filtro-campo" style="min-width:170px;">
                <option value="viagens" <?= $mode==='viagens'?'selected':'' ?>>Por deslocamento</option>
                <option value="diario" <?= $mode==='diario'?'selected':'' ?>>Fechamento diário</option>
            </select>
        </div>
        <div>
            <label for="flt-imei" class="filtro-rotulo">Placa</label>
            <select id="flt-imei" name="imei" class="filtro-campo" style="min-width:180px;">
                <option value="">Todas</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>" <?= $selImei===$d['imei']?'selected':'' ?>><?= htmlspecialchars($d['device_name']??$d['imei']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="flt-date_from" class="filtro-rotulo">Período (máx. <?= REPORT_RANGE_MAX_DAYS ?> dias)</label>
            <div style="display:flex;gap:4px;">
                <input type="date" id="flt-date_from" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="filtro-campo" style="width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="filtro-campo" style="width:130px;">
            </div>
        </div>
        <div>
            <label for="flt-time_from" class="filtro-rotulo">Faixa horária (opcional)</label>
            <div style="display:flex;gap:4px;">
                <input type="time" id="flt-time_from" name="time_from" value="<?= htmlspecialchars($timeFrom) ?>" class="filtro-campo" style="width:100px;">
                <input type="time" name="time_to" value="<?= htmlspecialchars($timeTo) ?>" class="filtro-campo" style="width:100px;">
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<?php if ($generated && $rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid var(--warning);font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias: <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <?php if ($mode === 'diario'): ?>
            <tr>
                <th><?= report_sort_link('dia', 'Dia', $sort, $order) ?></th>
                <th>Placa</th>
                <th>Primeira Ignição</th>
                <th>Última Ignição</th>
                <th>Jornada</th>
                <th>Horímetro</th>
                <th>Em Movimento</th>
                <th>Distância</th>
                <th>Hodômetro</th>
                <th>Vel. Máx</th>
                <th>Alarmes</th>
                <th>Viagens</th>
                <th>Rota</th>
            </tr>
            <?php else: ?>
            <tr>
                <th>Placa</th>
                <th>Motorista</th>
                <th><?= report_sort_link('started_at', 'Início', $sort, $order) ?></th>
                <th><?= report_sort_link('ended_at', 'Término', $sort, $order) ?></th>
                <th>Duração</th>
                <th>Horímetro</th>
                <th><?= report_sort_link('max_speed', 'Vel. Máx', $sort, $order, 'DESC') ?></th>
                <th><?= report_sort_link('distance_km', 'Distância', $sort, $order, 'DESC') ?></th>
                <th>Hodômetro</th>
                <th>Alarmes</th>
                <th>Rota</th>
            </tr>
            <?php endif; ?>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="<?= $mode === 'diario' ? 13 : 11 ?>"><div class="empty-state"><p>
                <?= $generated ? 'Nenhuma viagem encontrada no período.' : 'Selecione os filtros e clique em Gerar.' ?>
            </p></div></td></tr>
            <?php elseif ($mode === 'diario'): ?>
            <?php foreach ($rows as $r):
                // Horários do dia em H:i; se a última desligada caiu no dia BRT
                // seguinte (viagem cruzou a meia-noite), mostra a data junto.
                $diaBrt = fmt_brt($r['primeira_on'], 'd/m/Y');
                $offFmt = $r['ultima_off'] && fmt_brt($r['ultima_off'], 'd/m/Y') !== $diaBrt ? 'd/m H:i' : 'H:i';
                [$dayFrom, $dayUntil] = desloc_brt_day_bounds_utc($r['dia']);
                $horimetroS = ignition_seconds_in_window($stateSegs, $r['imei'], $dayFrom, $dayUntil);
                $hodDelta = $hodByDay[$r['imei'] . '|' . $r['dia']] ?? null;
                $hodEnd   = $hodEndByDay[$r['imei'] . '|' . $r['dia']] ?? null;
            ?>
            <tr>
                <td class="text-mono"><?= $diaBrt ?></td>
                <td class="text-mono"><?= htmlspecialchars($r['device_name']) ?></td>
                <td class="text-mono"><?= fmt_brt($r['primeira_on'], 'H:i') ?></td>
                <td class="text-mono"><?= $r['ultima_off'] ? fmt_brt($r['ultima_off'], $offFmt) : '—' ?></td>
                <td class="text-mono"><?= fmt_duration((int)($r['jornada_s'] ?? 0)) ?></td>
                <td class="text-mono"><?= fmt_duration($horimetroS) ?></td>
                <td class="text-mono"><?= fmt_duration((int)($r['movimento_s'] ?? 0)) ?></td>
                <td class="text-mono"><?= $hodDelta !== null ? number_format(odometer_km($hodDelta), 1, ',', '.') . ' km' : '—' ?></td>
                <td class="text-mono"><?= $hodEnd !== null ? number_format(odometer_km($hodEnd), 1, ',', '.') . ' km' : '—' ?></td>
                <td class="text-mono"><?= $r['max_speed'] ? number_format((float)$r['max_speed'], 1) . ' km/h' : '—' ?></td>
                <td class="text-mono"><?= (int)($r['alarm_count'] ?? 0) ?></td>
                <td class="text-mono"><?= (int)$r['viagens'] ?></td>
                <td><a href="/relatorios/deslocamento/rota?imei=<?= urlencode($r['imei']) ?>&dia=<?= urlencode($r['dia']) ?><?= $drillCust ?>&return=<?= $returnTo ?>" class="btn btn-outline btn-sm">Ver rota</a></td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <?php foreach ($rows as $r):
                $horimetroS = ignition_seconds_in_window($stateSegs, $r['imei'], $r['started_at'], $r['ended_at']);
            ?>
            <tr>
                <td class="text-mono"><?= htmlspecialchars($r['device_name']) ?></td>
                <td><?= htmlspecialchars($r['driver_name']) ?></td>
                <td class="text-mono"><?= fmt_brt($r['started_at']) ?><br><span style="font-size:10px;color:var(--muted);"><?= htmlspecialchars(substr($r['start_addr']??'—', 0, 40)) ?></span></td>
                <td class="text-mono"><?= $r['ended_at'] ? fmt_brt($r['ended_at']) : '—' ?><br><span style="font-size:10px;color:var(--muted);"><?= htmlspecialchars(substr($r['end_addr']??'—', 0, 40)) ?></span></td>
                <td class="text-mono"><?= fmt_duration((int)($r['duration_s'] ?? 0)) ?></td>
                <td class="text-mono"><?= fmt_duration($horimetroS) ?></td>
                <td class="text-mono"><?= $r['max_speed'] ? number_format((float)$r['max_speed'], 1) . ' km/h' : '—' ?></td>
                <td class="text-mono"><?= $r['hod_delta'] !== null ? number_format(odometer_km($r['hod_delta']), 1, ',', '.') . ' km' : '—' ?></td>
                <td class="text-mono"><?= $r['hod_end'] !== null ? number_format(odometer_km($r['hod_end']), 1, ',', '.') . ' km' : '—' ?></td>
                <td class="text-mono"><?= (int)($r['alarm_count'] ?? 0) ?></td>
                <td>
                    <a href="/relatorios/deslocamento/rota?trip_id=<?= (int)$r['id'] ?><?= $drillCust ?>&return=<?= $returnTo ?>" class="btn btn-outline btn-sm">Ver rota</a>
                    <a href="/relatorios/deslocamento/replay?trip_id=<?= (int)$r['id'] ?><?= $drillCust ?>&return=<?= $returnTo ?>" class="btn btn-outline btn-sm">Replay</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
        <?php if ($generated && !empty($allRows)): ?>
        <tfoot>
            <?php if ($mode === 'diario'): ?>
            <tr>
                <td colspan="4" style="text-align:right;font-weight:600;">Total do período</td>
                <td class="text-mono" style="font-weight:600;">—</td>
                <td class="text-mono" style="font-weight:600;"><?= fmt_duration($totHorimetroS) ?></td>
                <td class="text-mono" style="font-weight:600;"><?= fmt_duration($totMovimentoS) ?></td>
                <td class="text-mono" style="font-weight:600;"><?= $hodTemDado ? number_format($totHodKm, 1, ',', '.') . ' km' : '—' ?></td>
                <td class="text-mono" style="font-weight:600;">—</td>
                <td class="text-mono" style="font-weight:600;">—</td>
                <td class="text-mono" style="font-weight:600;"><?= $totAlarms ?></td>
                <td class="text-mono" style="font-weight:600;"><?= $totViagens ?></td>
                <td></td>
            </tr>
            <?php else: ?>
            <tr>
                <td colspan="4" style="text-align:right;font-weight:600;">Total do período</td>
                <td class="text-mono" style="font-weight:600;">—</td>
                <td class="text-mono" style="font-weight:600;"><?= fmt_duration($totHorimetroS) ?></td>
                <td class="text-mono" style="font-weight:600;">—</td>
                <td class="text-mono" style="font-weight:600;"><?= $hodTemDado ? number_format($totHodKm, 1, ',', '.') . ' km' : '—' ?></td>
                <td class="text-mono" style="font-weight:600;">—</td>
                <td class="text-mono" style="font-weight:600;"><?= $totAlarms ?></td>
                <td></td>
            </tr>
            <?php endif; ?>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, $mode === 'diario' ? 'dias' : 'viagens') ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
