<?php
/**
 * JIMI Webhook System — Polling de Ocorrências v4.0.0
 * Endpoint: /ocorrenciasdata
 *
 * Retorna JSON com KPIs e grade de ocorrências para o Dashboard DMS.
 * Suporta filtros via GET: status, risk, page, per_page, date_from, date_to.
 * Escopo automático por customer_id da sessão (multi-tenant).
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fleet_state.php'; // device_connectivity_counts()

if (!auth_init()) {
    echo json_encode(['code' => 401, 'message' => 'Não autenticado']);
    exit;
}

$customerId = get_customer_id();
$db = Database::getInstance()->getConnection();

$status    = $_GET['status'] ?? null;
$risk      = $_GET['risk'] ?? null;
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = min(50, max(5, (int)($_GET['per_page'] ?? 20)));
$dateFrom  = $_GET['date_from'] ?? brt_today();
$dateTo    = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo] = clamp_report_range($dateFrom, $dateTo); // teto global 31 dias
$search    = $_GET['search'] ?? '';

try {
    $where = 'WHERE 1=1';
    $params = [];

    if ($customerId) {
        $where .= ' AND o.customer_id = :cid';
        $params[':cid'] = $customerId;
    }
    if ($status) {
        $where .= ' AND o.status = :st';
        $params[':st'] = $status;
    }
    if ($risk) {
        $where .= ' AND o.risk = :risk';
        $params[':risk'] = $risk;
    }
    // 🔴 Busca por PLACA — e por que o predicado é todo em EXISTS (v4.9.8).
    //
    // A caixa de busca desta tela NUNCA devolveu nada. O `$where` é montado uma
    // vez e reaproveitado por TRÊS consultas, e só a última tem os JOINs: as de
    // KPI e de contagem são `FROM occurrences o` puro. Com o termo preenchido, o
    // `dr.name` que estava aqui virava "Unknown column 'dr.name' in 'where
    // clause'" já no KPI — o catch lá embaixo devolve o payload zerado com
    // `code: 0`, e a tela mostra grade vazia e KPIs em zero como se não houvesse
    // ocorrência. Sem erro no log da aplicação, sem erro na tela.
    //
    // Subconsulta correlacionada não depende de JOIN nenhum, então o mesmo
    // `$where` vale nas três. A placa (`devices.device_name`) entra junto porque
    // é o que a grade passou a exibir: procurar pelo que está na tela tem de
    // funcionar.
    if ($search) {
        $where .= ' AND (o.imei LIKE :q
                         OR EXISTS (SELECT 1 FROM drivers drs
                                     WHERE drs.id = o.driver_id AND drs.name LIKE :q2)
                         OR EXISTS (SELECT 1 FROM devices dvs
                                     WHERE dvs.imei = o.imei AND dvs.device_name LIKE :q3))';
        $params[':q'] = "%$search%";
        $params[':q2'] = "%$search%";
        $params[':q3'] = "%$search%";
    }
    // Dias digitados são BRT; colunas do banco são UTC
    [$params[':df'], $params[':dt']] = brt_day_range_to_utc($dateFrom, $dateTo);
    $whereDates = ' AND o.last_alarm_at BETWEEN :df AND :dt';

    // KPIs
    $kpiWhere = $where . $whereDates;
    $kpiStmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN o.status = 'aguardando' THEN 1 ELSE 0 END) as aguardando,
            SUM(CASE WHEN o.status = 'em_tratativa' THEN 1 ELSE 0 END) as em_tratativa,
            SUM(CASE WHEN o.status = 'resolvida' THEN 1 ELSE 0 END) as resolvida,
            SUM(CASE WHEN o.status = 'descartada' THEN 1 ELSE 0 END) as descartada,
            SUM(CASE WHEN o.risk = 'baixo' THEN 1 ELSE 0 END) as risco_baixo,
            SUM(CASE WHEN o.risk = 'medio' THEN 1 ELSE 0 END) as risco_medio,
            SUM(CASE WHEN o.risk = 'alto' THEN 1 ELSE 0 END) as risco_alto
        FROM occurrences o $kpiWhere
    ");
    $kpiStmt->execute($params);
    $kpis = $kpiStmt->fetch();

    // Devices — ponto único (`device_connectivity_counts()`).
    // 🔴 A cópia que morava aqui não filtrava `is_active`, então esta tela
    // somava os equipamentos DESATIVADOS em "Off" e contradizia o contador do
    // topo no mesmo instante: medido em produção 03/09/2026, On 8/Off 2 no
    // cabeçalho contra On 8/Off 7 aqui, com 5 equipamentos desativados.
    $devices = device_connectivity_counts($db, $customerId ? (int)$customerId : null);

    $total = (int)($kpis['total'] ?? 0);
    $riskLow  = $total > 0 ? round((int)($kpis['risco_baixo'] ?? 0) / $total * 100) : 0;
    $riskMed  = $total > 0 ? round((int)($kpis['risco_medio'] ?? 0) / $total * 100) : 0;
    $riskHigh = 100 - $riskLow - $riskMed;
    if ($total === 0) { $riskLow = 0; $riskMed = 0; $riskHigh = 0; }

    // Grid
    $countStmt = $db->prepare("SELECT COUNT(*) FROM occurrences o $where $whereDates");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, ceil($totalRows / $perPage));
    $offset = ($page - 1) * $perPage;

    // `has_media`/`repr_alarm_id`: a grade NUNCA mostrava se a ocorrência tinha
    // vídeo — `has_media` já vinha no payload (só degrau 1, `media_file_id`) mas
    // nenhum front-end a lia, e sem um alarm_id não dava pra oferecer "Pedir
    // vídeo" aqui (só no detalhe). `has_media` agora cobre também o degrau 2
    // (anexo declarado por QUALQUER alarme do grupo, mesma leitura de
    // ocorrencias_dashboard.php) — o degrau 3 (janela ±3min) fica só no
    // detalhe, é caso raro demais pra valer o custo numa consulta de lista.
    $dataStmt = $db->prepare("
        SELECT o.id, o.imei, o.alarm_type, o.risk, o.status, o.false_positive,
               o.first_alarm_at, o.last_alarm_at, o.alarm_count,
               o.driver_id, o.media_file_id,
               COALESCE(c.name, '—') as customer_name,
               COALESCE(dr.name, '—') as driver_name,
               COALESCE(NULLIF(dv.device_name, ''), o.imei) AS device_label,
               (SELECT a2.id FROM occurrence_events oe2 JOIN alarms a2 ON a2.id = oe2.alarm_id
                 WHERE oe2.occurrence_id = o.id ORDER BY a2.alarm_time DESC LIMIT 1) AS repr_alarm_id,
               EXISTS (SELECT 1 FROM occurrence_events oe3 JOIN alarms a3 ON a3.id = oe3.alarm_id
                        WHERE oe3.occurrence_id = o.id AND a3.file_url IS NOT NULL AND a3.file_url <> '') AS has_event_media
        FROM occurrences o
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN drivers dr ON dr.id = o.driver_id
        LEFT JOIN devices dv ON dv.imei = o.imei
        $where $whereDates
        ORDER BY o.last_alarm_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            // `imei` continua no payload (multitenant.spec.js e integrações o
            // usam); a GRADE passou a exibir `device_label`, que é a placa.
            'id' => (int)$r['id'], 'imei' => $r['imei'],
            'device_label' => $r['device_label'], 'customer_name' => $r['customer_name'],
            'driver_name' => $r['driver_name'], 'alarm_type' => $r['alarm_type'],
            'risk' => $r['risk'], 'status' => $r['status'],
            'false_positive' => (bool)$r['false_positive'], 'first_alarm_at' => $r['first_alarm_at'],
            'last_alarm_at' => $r['last_alarm_at'], 'alarm_count' => (int)$r['alarm_count'],
            'has_media' => !empty($r['media_file_id']) || !empty($r['has_event_media']),
            'repr_alarm_id' => $r['repr_alarm_id'] ? (int)$r['repr_alarm_id'] : null,
        ];
    }

    echo json_encode([
        'code' => 0,
        'data' => [
            'kpis' => [
                'total' => (int)($kpis['total'] ?? 0), 'aguardando' => (int)($kpis['aguardando'] ?? 0),
                'em_tratativa' => (int)($kpis['em_tratativa'] ?? 0), 'resolvida' => (int)($kpis['resolvida'] ?? 0),
                'descartada' => (int)($kpis['descartada'] ?? 0),
            ],
            // `active` e o denominador de online+offline (os dois so contam
            // is_active=1); `total` continua sendo a frota cadastrada inteira.
            'devices' => ['online' => (int)($devices['online'] ?? 0), 'offline' => (int)($devices['offline'] ?? 0),
                          'active' => (int)($devices['active'] ?? 0), 'total' => (int)($devices['total'] ?? 0)],
            'risk_distribution' => ['baixo' => $riskLow, 'medio' => $riskMed, 'alto' => $riskHigh],
            'rows' => $data, 'page' => $page, 'total_pages' => $totalPages, 'total_rows' => $totalRows,
        ],
        'message' => 'ok',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('ocorrenciasdata: ' . $e->getMessage());
    echo json_encode([
        'code' => 0,
        'data' => [
            'kpis' => ['total' => 0, 'aguardando' => 0, 'em_tratativa' => 0, 'resolvida' => 0, 'descartada' => 0],
            'devices' => ['online' => 0, 'offline' => 0, 'active' => 0, 'total' => 0],
            'risk_distribution' => ['baixo' => 0, 'medio' => 0, 'alto' => 0],
            'rows' => [], 'page' => 1, 'total_pages' => 1, 'total_rows' => 0,
        ],
        'message' => 'ok',
    ], JSON_UNESCAPED_UNICODE);
}
