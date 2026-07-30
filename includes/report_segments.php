<?php
/**
 * JIMI Webhook System — Relatório de segmentos de estado v4.6.0
 * Arquivo: includes/report_segments.php
 *
 * Corpo comum dos relatórios que são um RECORTE de device_state_segments.
 * Hoje: Paradas (`state='parado'`) e Ociosidade (`state='ocioso'`).
 *
 * Existe pela mesma razão que o state_builder existe: as duas telas são a
 * mesma consulta com um `state` diferente. Escrever dois arquivos de 250
 * linhas quase idênticos garantiria que a primeira correção fosse aplicada a
 * só um deles — que é exatamente o problema que a Fase 3 se propôs a evitar
 * no banco e seria incoerente reintroduzir na camada de exibição.
 *
 * O handler chama render_segment_report() depois de montar a configuração.
 * Segue o molde de handlers/rel_alarmes.php: clamp_report_range(),
 * report_sort_params(), stream_export(), report_pagination(),
 * report_back_button().
 */

require_once __DIR__ . '/fleet_state.php';

/**
 * Renderiza um relatório de segmentos de estado ponta a ponta.
 *
 * Faz tudo: filtros, KPIs, export síncrono, grade paginada e o fechamento do
 * layout. O handler não precisa emitir HTML nenhum.
 *
 * @param array $cfg {
 *     @type string $state     Estado filtrado (parado|ocioso|movimento|offline)
 *     @type string $title     Título da página
 *     @type string $route     Valor de $current_route (item ativo na sidebar)
 *     @type string $path      Caminho da rota, para o botão Voltar
 *     @type string $slug      Base do nome do arquivo exportado
 *     @type string $unit      Substantivo plural das linhas ("paradas")
 *     @type string $emptyText Texto da grade vazia
 *     @type string $help      Nota de rodapé explicando a definição do estado
 *     @type bool   $showDist  Mostrar a coluna de distância percorrida
 * }
 * @returns void
 */
function render_segment_report(array $cfg): void
{
    $db         = Database::getInstance()->getConnection();
    $customerId = get_customer_id();
    $user       = get_jimi_user();
    $isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

    $state     = $cfg['state'];
    $showDist  = !empty($cfg['showDist']);

    $dateFrom = $_GET['date_from'] ?? brt_today();
    $dateTo   = $_GET['date_to'] ?? brt_today();
    [$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo);

    $filterCust = $_GET['customer_id'] ?? null;
    $filterImei = trim($_GET['imei'] ?? '');
    // Duração mínima é o filtro que torna a tela utilizável: uma frota gera
    // centenas de paradas de semáforo por dia, e a pergunta real é sempre
    // "quais passaram de X minutos".
    $minMinutes = max(0, (int)($_GET['min_minutes'] ?? 0));
    $page       = max(1, (int)($_GET['page'] ?? 1));
    $perPage    = 25;

    $validSorts = ['started_at', 'duration_s', 'imei'];
    [$sort, $order] = report_sort_params($validSorts, 'started_at', 'ASC');

    // Segmento em curso (ended_at NULL) tem duração contada até agora. Sem
    // isto, a parada que começou há 4 h e não terminou apareceria com duração
    // vazia — justamente a que mais interessa a quem audita.
    $durExpr = 'COALESCE(s.duration_s, TIMESTAMPDIFF(SECOND, s.started_at, UTC_TIMESTAMP()))';

    [$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo);
    $where  = 'WHERE s.state = :state AND s.started_at BETWEEN :df AND :dt';
    $params = [':state' => $state, ':df' => $utcFrom, ':dt' => $utcTo];

    if (!$isAdmin && !$filterCust) {
        if ($customerId) {
            $where .= ' AND s.customer_id = :cid';
            $params[':cid'] = $customerId;
        }
    } elseif ($filterCust) {
        $where .= ' AND s.customer_id = :fcid';
        $params[':fcid'] = (int)$filterCust;
    }
    if ($filterImei !== '') {
        $where .= ' AND s.imei LIKE :imei';
        $params[':imei'] = "%$filterImei%";
    }
    if ($minMinutes > 0) {
        $where .= " AND $durExpr >= :minsecs";
        $params[':minsecs'] = $minMinutes * 60;
    }

    $from = "
        FROM device_state_segments s
        LEFT JOIN devices d ON d.imei = s.imei
        LEFT JOIN customers c ON c.id = s.customer_id
        $where";

    $selectCols = "
        SELECT s.id, s.imei, s.started_at, s.ended_at, $durExpr AS dur_s,
               s.start_lat, s.start_lng, s.distance_km, s.max_speed, s.point_count,
               COALESCE(d.device_name, s.imei) AS device_label,
               COALESCE(c.name, '—') AS customer_name";

    // ORDER BY por duração precisa usar a expressão, não a coluna: ordenar por
    // duration_s jogaria todo segmento em curso para o fim (NULL).
    $orderBy = $sort === 'duration_s' ? "$durExpr $order" : "s.$sort $order";

    // ── Export síncrono ────────────────────────────────────────
    $export = $_GET['export'] ?? '';
    if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
        require_permission('relatorios', 'export');
        require_once __DIR__ . '/export_helper.php';

        $headers = ['Início', 'Fim', 'Duração', 'Equipamento', 'IMEI', 'Cliente'];
        if ($showDist) {
            $headers[] = 'Distância (km)';
        }
        $headers[] = 'Latitude';
        $headers[] = 'Longitude';

        $expRows = [];
        try {
            $stmt = $db->prepare("$selectCols $from ORDER BY $orderBy LIMIT " . SYNC_EXPORT_MAX_ROWS);
            $stmt->execute($params);
            while ($r = $stmt->fetch()) {
                $row = [
                    fmt_brt($r['started_at'], 'd/m/Y H:i:s'),
                    $r['ended_at'] ? fmt_brt($r['ended_at'], 'd/m/Y H:i:s') : 'Em curso',
                    fmt_duration((int)$r['dur_s']),
                    $r['device_label'],
                    $r['imei'],
                    $r['customer_name'],
                ];
                if ($showDist) {
                    $row[] = number_format((float)($r['distance_km'] ?? 0), 3, ',', '');
                }
                $row[] = $r['start_lat'];
                $row[] = $r['start_lng'];
                $expRows[] = $row;
            }
        } catch (Throwable $e) { /* tabela ausente → export vazio */ }

        stream_export($export, $cfg['slug'], $headers, $expRows,
            $cfg['title'], "Período (BRT): $dateFrom a $dateTo");
    }

    // ── Grade + KPIs ───────────────────────────────────────────
    $tableMissing = false;
    $rows       = [];
    $totalRows  = 0;
    $totalPages = 1;
    $kpi = ['total' => 0, 'sum_s' => 0, 'avg_s' => 0, 'max_s' => 0, 'devices' => 0];

    try {
        $countStmt = $db->prepare("SELECT COUNT(*) $from");
        $countStmt->execute($params);
        $totalRows  = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $offset     = ($page - 1) * $perPage;

        $dataStmt = $db->prepare("$selectCols $from ORDER BY $orderBy LIMIT $perPage OFFSET $offset");
        $dataStmt->execute($params);
        $rows = $dataStmt->fetchAll();

        $kpiStmt = $db->prepare("
            SELECT COUNT(*) AS total, COALESCE(SUM($durExpr),0) AS sum_s,
                   COALESCE(AVG($durExpr),0) AS avg_s, COALESCE(MAX($durExpr),0) AS max_s,
                   COUNT(DISTINCT s.imei) AS devices
            $from");
        $kpiStmt->execute($params);
        if ($k = $kpiStmt->fetch()) {
            $kpi = [
                'total'   => (int)$k['total'],
                'sum_s'   => (int)$k['sum_s'],
                'avg_s'   => (int)$k['avg_s'],
                'max_s'   => (int)$k['max_s'],
                'devices' => (int)$k['devices'],
            ];
        }
    } catch (Throwable $e) {
        $tableMissing = true;
    }

    $customers = [];
    try {
        $customers = $db->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();
    } catch (Throwable $e) {}

    // ── Renderização ───────────────────────────────────────────
    $page_title    = $cfg['title'];
    $current_route = $cfg['route'];
    require_once __DIR__ . '/../web/layout_base.php';

    $expQ = $_GET;
    unset($expQ['page'], $expQ['export']);
    $expBase = http_build_query($expQ);
    ?>

    <div class="flex-between mb-16">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);"><?= htmlspecialchars($cfg['title']) ?></h2>
        <div style="display:flex;gap:8px;">
            <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
            <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
            <?php if (report_has_query()) echo report_back_button($cfg['path']); ?>
        </div>
    </div>

    <?php if ($tableMissing): ?>
    <div class="card mb-16" style="padding:12px 16px;border-left:3px solid var(--error);">
        <div style="font-size:13px;color:var(--muted);">
            <strong>Tabela de segmentos indisponível.</strong> Aplique a migração <code>v4.6.0</code>
            e rode <code>php scripts/state_builder.php 30</code> para preencher o histórico.
        </div>
    </div>
    <?php endif; ?>

    <div class="card mb-24" style="padding:16px 20px;">
        <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
            <?php if ($isAdmin): ?>
            <div>
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
                <select name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <option value="">Todos</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $filterCust == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">IMEI</label>
                <input type="text" name="imei" value="<?= htmlspecialchars($filterImei) ?>" placeholder="Buscar..."
                       style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:140px;">
            </div>
            <div>
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Duração mínima</label>
                <select name="min_minutes" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <?php foreach ([0 => 'Qualquer', 5 => '5 min', 15 => '15 min', 30 => '30 min', 60 => '1 h', 240 => '4 h'] as $mv => $ml): ?>
                    <option value="<?= $mv ?>" <?= $minMinutes === $mv ? 'selected' : '' ?>><?= $ml ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Período (máx. <?= REPORT_RANGE_MAX_DAYS ?> dias)</label>
                <div style="display:flex;gap:4px;">
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
        </form>
    </div>

    <?php if ($rangeClamped): ?>
    <div class="card mb-16" style="padding:10px 16px;border-left:3px solid #f5a623;font-size:13px;color:var(--muted);">
        O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias:
        <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
    </div>
    <?php endif; ?>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:24px;">
        <?php
        $kpiCards = [
            ['Ocorrências',    (string)$kpi['total']],
            ['Tempo total',    fmt_duration($kpi['sum_s'], '0min')],
            ['Duração média',  fmt_duration($kpi['avg_s'], '0min')],
            ['Maior duração',  fmt_duration($kpi['max_s'], '0min')],
            ['Equipamentos',   (string)$kpi['devices']],
        ];
        foreach ($kpiCards as [$label, $value]):
        ?>
        <div class="card" style="padding:14px 18px;">
            <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);"><?= $label ?></div>
            <div class="text-mono" style="font-size:22px;font-weight:500;color:var(--ink);margin-top:4px;"><?= htmlspecialchars($value) ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th><?= report_sort_link('started_at', 'Início', $sort, $order) ?></th>
                    <th>Fim</th>
                    <th><?= report_sort_link('duration_s', 'Duração', $sort, $order) ?></th>
                    <th><?= report_sort_link('imei', 'Equipamento', $sort, $order) ?></th>
                    <th>Cliente</th>
                    <?php if ($showDist): ?><th>Distância</th><?php endif; ?>
                    <th>Mapa</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr><td colspan="<?= $showDist ? 7 : 6 ?>" style="text-align:center;padding:32px;color:var(--muted);"><?= htmlspecialchars($cfg['emptyText']) ?></td></tr>
                <?php else: foreach ($rows as $r):
                    $hasCoords = $r['start_lat'] && $r['start_lng']
                              && is_valid_coordinate($r['start_lat'], $r['start_lng']);
                ?>
                <tr>
                    <td class="text-mono"><?= fmt_brt($r['started_at'], 'd/m/Y H:i:s') ?></td>
                    <td class="text-mono">
                        <?php if ($r['ended_at']): ?>
                            <?= fmt_brt($r['ended_at'], 'd/m/Y H:i:s') ?>
                        <?php else: ?>
                            <span class="badge badge-info">Em curso</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-mono"><?= htmlspecialchars(fmt_duration((int)$r['dur_s'])) ?></td>
                    <td>
                        <?= htmlspecialchars($r['device_label']) ?>
                        <div class="text-mono text-muted" style="font-size:11px;"><?= htmlspecialchars($r['imei']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($r['customer_name']) ?></td>
                    <?php if ($showDist): ?>
                    <td class="text-mono"><?= number_format((float)($r['distance_km'] ?? 0), 2, ',', '.') ?> km</td>
                    <?php endif; ?>
                    <td>
                        <?php if ($hasCoords): ?>
                        <a href="https://www.openstreetmap.org/?mlat=<?= $r['start_lat'] ?>&mlon=<?= $r['start_lng'] ?>&zoom=16"
                           target="_blank" class="badge badge-primary">Ver Mapa</a>
                        <?php else: echo '—'; endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?= report_pagination($page, $totalPages, $totalRows, $cfg['unit']) ?>

    <p class="text-muted" style="font-size:11px;margin-top:8px;">
        <?= htmlspecialchars($cfg['help']) ?>
        O período filtra pelo <strong>início</strong> do evento; um evento que começou dentro do
        período e terminou depois dele aparece como “Em curso”.
    </p>

    <?php
    require_once __DIR__ . '/../web/layout_base_close.php';
}
