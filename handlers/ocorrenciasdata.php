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
$dateFrom  = $_GET['date_from'] ?? date('Y-m-d');
$dateTo    = $_GET['date_to'] ?? date('Y-m-d');
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
    if ($search) {
        $where .= ' AND (o.imei LIKE :q OR dr.name LIKE :q2)';
        $params[':q'] = "%$search%";
        $params[':q2'] = "%$search%";
    }
    $params[':df'] = $dateFrom . ' 00:00:00';
    $params[':dt'] = $dateTo . ' 23:59:59';
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

    // Devices
    $devStmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, last_communication, NOW()) <= 5 THEN 1 ELSE 0 END) as online,
            SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, last_communication, NOW()) > 5 THEN 1 ELSE 0 END) as offline
        FROM devices d2 WHERE 1=1 " . ($customerId ? 'AND d2.customer_id = :cid2' : '')
    );
    $devParams = $customerId ? [':cid2' => $customerId] : [];
    $devStmt->execute($devParams);
    $devices = $devStmt->fetch();

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

    $dataStmt = $db->prepare("
        SELECT o.id, o.imei, o.alarm_type, o.risk, o.status, o.false_positive,
               o.first_alarm_at, o.last_alarm_at, o.alarm_count,
               o.driver_id, o.media_file_id,
               COALESCE(c.name, '—') as customer_name,
               COALESCE(dr.name, '—') as driver_name
        FROM occurrences o
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN drivers dr ON dr.id = o.driver_id
        $where $whereDates
        ORDER BY o.last_alarm_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $dataStmt->execute($params);
    $rows = $dataStmt->fetchAll();

    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            'id' => (int)$r['id'], 'imei' => $r['imei'], 'customer_name' => $r['customer_name'],
            'driver_name' => $r['driver_name'], 'alarm_type' => $r['alarm_type'],
            'risk' => $r['risk'], 'status' => $r['status'],
            'false_positive' => (bool)$r['false_positive'], 'first_alarm_at' => $r['first_alarm_at'],
            'last_alarm_at' => $r['last_alarm_at'], 'alarm_count' => (int)$r['alarm_count'],
            'has_media' => !empty($r['media_file_id']),
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
            'devices' => ['online' => (int)($devices['online'] ?? 0), 'offline' => (int)($devices['offline'] ?? 0), 'total' => (int)($devices['total'] ?? 0)],
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
            'devices' => ['online' => 0, 'offline' => 0, 'total' => 0],
            'risk_distribution' => ['baixo' => 0, 'medio' => 0, 'alto' => 0],
            'rows' => [], 'page' => 1, 'total_pages' => 1, 'total_rows' => 0,
        ],
        'message' => 'ok',
    ], JSON_UNESCAPED_UNICODE);
}
