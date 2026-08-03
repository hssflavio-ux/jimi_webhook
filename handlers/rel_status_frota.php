<?php
/**
 * JIMI Webhook System — Status da Frota v4.6.0
 * Rota: /relatorios/status-frota
 *
 * Sumário do estado CORRENTE de cada equipamento ativo: quantos em movimento,
 * ociosos, parados e sem comunicação — com percentual e drill-down por estado.
 *
 * Diferente dos outros quatro relatórios da fase, este não olha um período: é
 * uma foto do agora. Por isso o estado não é lido cru do segmento aberto e sim
 * resolvido por resolve_current_state() (includes/fleet_state.php): um veículo
 * que parou de reportar às 3h da manhã tem segmento aberto em `movimento`, e
 * mostrar "em movimento" às 10h seria mentira. Entre duas rodadas do cron a
 * verdade muda sem que nenhum dado novo entre no banco, então a conta do
 * silêncio tem de ser feita na leitura.
 *
 * A soma dos quatro estados é sempre o total de equipamentos ATIVOS do cliente:
 * a lista parte de `devices`, não dos segmentos, e todo equipamento recebe
 * exatamente um estado (sem segmento algum → offline).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
require_once __DIR__ . '/../includes/geocode.php';   // endereço no lugar de lat/lng
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('rel_status_frota', '/relatorios/status-frota');

require_once __DIR__ . '/../includes/fleet_state.php';

/** Teto de equipamentos classificados em memória por requisição. */
const FLEET_STATUS_MAX_DEVICES = 5000;

$page_title    = 'Status da Frota';
$current_route = 'rel_status_frota';

$db         = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user       = get_jimi_user();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$filterCust  = $_GET['customer_id'] ?? null;
$filterImei  = trim($_GET['imei'] ?? '');
$filterState = isset(FLEET_STATE_LABELS[$_GET['state'] ?? '']) ? $_GET['state'] : '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 25;

$where  = 'WHERE d.is_active = 1';
$params = [];
// Escopo multi-tenant centralizado (v4.7.3) — ver report_customer_scope()
$scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);
if ($scopeCust !== null) {
    $where .= ' AND d.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}
if ($filterImei !== '') {
    $where .= ' AND (d.imei LIKE :imei OR d.device_name LIKE :imei2)';
    $params[':imei']  = "%$filterImei%";
    $params[':imei2'] = "%$filterImei%";
}

/**
 * Monta a consulta da frota, com ou sem a tabela de segmentos.
 *
 * As duas variantes existem escritas por extenso de propósito: a versão sem
 * segmentos é o caminho de degradação quando a migração v4.6.0 ainda não foi
 * aplicada, e derivá-la por str_replace sobre a outra quebraria no dia em que
 * alguém reindentasse o SQL.
 *
 * O segmento aberto é único por equipamento (o state_builder mantém no máximo
 * um com `ended_at IS NULL`). O LEFT JOIN é sobre essa condição para que
 * equipamento sem segmento algum continue na lista — e caia em offline.
 *
 * @param bool   $withSegments Incluir o JOIN em device_state_segments
 * @param string $where        Cláusula WHERE já montada
 * @returns string SQL
 */
function fleet_status_sql(bool $withSegments, string $where): string
{
    $segCols = $withSegments
        ? "s.state AS seg_state, s.started_at AS seg_started_at"
        : "NULL AS seg_state, NULL AS seg_started_at";
    $segJoin = $withSegments
        ? "LEFT JOIN device_state_segments s ON s.imei = d.imei AND s.ended_at IS NULL"
        : "";

    return "
        SELECT d.imei, d.device_name, d.customer_id, d.speed_limit_kmh,
               COALESCE(c.name, '—') AS customer_name,
               c.default_speed_limit_kmh,
               ds.last_gps_time, ds.last_latitude, ds.last_longitude, ds.last_speed,
               $segCols
        FROM devices d
        LEFT JOIN customers c ON c.id = d.customer_id
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        $segJoin
        $where
        ORDER BY d.device_name, d.imei
        LIMIT " . FLEET_STATUS_MAX_DEVICES;
}

$tableMissing = false;
$fleet = [];
try {
    $stmt = $db->prepare(fleet_status_sql(true, $where));
    $stmt->execute($params);
    $fleet = $stmt->fetchAll();
} catch (Throwable $e) {
    // Sem a tabela de segmentos a tela ainda tem valor: a frota é classificada
    // só pelo silêncio de comunicação. Degradar é melhor do que devolver uma
    // página em branco.
    $tableMissing = true;
    try {
        $stmt = $db->prepare(fleet_status_sql(false, $where));
        $stmt->execute($params);
        $fleet = $stmt->fetchAll();
    } catch (Throwable $e2) {
        $fleet = [];
    }
}

// ── Classificação do estado corrente ───────────────────────────
$nowUtc = gmdate('Y-m-d H:i:s');
$counts = ['movimento' => 0, 'ocioso' => 0, 'parado' => 0, 'offline' => 0];
$classified = [];

foreach ($fleet as $row) {
    $state = resolve_current_state($row['seg_state'] ?? null, $row['last_gps_time'] ?? null, $nowUtc);
    $counts[$state]++;

    // Tempo no estado: se o silêncio derrubou o veículo para offline, o que
    // interessa é há quanto tempo ele está calado, não quando o último
    // segmento começou.
    if ($state === 'offline') {
        $sinceRef = $row['last_gps_time'] ?? null;
    } else {
        $sinceRef = $row['seg_started_at'] ?? $row['last_gps_time'] ?? null;
    }
    $row['current_state'] = $state;
    $row['since']         = $sinceRef;
    $row['in_state_s']    = $sinceRef ? max(0, strtotime($nowUtc) - strtotime($sinceRef)) : null;
    $row['limit_kmh']     = resolve_speed_limit($row['speed_limit_kmh'], $row['default_speed_limit_kmh']);
    $classified[] = $row;
}

$totalDevices = count($classified);

// Drill-down: o filtro de estado é aplicado depois da classificação porque o
// estado corrente não existe como coluna — é derivado.
$filtered = $filterState !== ''
    ? array_values(array_filter($classified, fn($r) => $r['current_state'] === $filterState))
    : $classified;

$totalRows  = count($filtered);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$rows       = array_slice($filtered, ($page - 1) * $perPage, $perPage);

// ── Export síncrono ────────────────────────────────────────────
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';

    $expRows = [];
    $srcExp = array_slice($filtered, 0, SYNC_EXPORT_MAX_ROWS);
    $geoExp = geocode_map_rows($srcExp, 'last_latitude', 'last_longitude', 2000);
    foreach ($srcExp as $r) {
        $expRows[] = [
            $r['device_name'] ?? $r['imei'],
            $r['imei'],
            $r['customer_name'],
            fleet_state_label($r['current_state']),
            fmt_duration($r['in_state_s']),
            fmt_brt($r['last_gps_time'], 'd/m/Y H:i:s'),
            $r['last_speed'] !== null ? number_format((float)$r['last_speed'], 1, ',', '') : '—',
            (int)$r['limit_kmh'],
            geocode_cell($geoExp, $r['last_latitude'], $r['last_longitude']),
        ];
    }
    stream_export($export, 'status_frota',
        ['Equipamento', 'IMEI', 'Cliente', 'Estado', 'Tempo no estado', 'Última posição',
         'Velocidade (km/h)', 'Limite (km/h)', 'Endereço'],
        $expRows, 'Status da Frota',
        'Foto de ' . fmt_brt($nowUtc, 'd/m/Y H:i:s') . ' (BRT)'
        . ($filterState !== '' ? ' — ' . fleet_state_label($filterState) : ''));
}

$customers = [];
try {
    $customers = report_customer_options($db);
} catch (Throwable $e) {}

require_once __DIR__ . '/../web/layout_base.php';
?>

<?php
$expQ = $_GET;
unset($expQ['page'], $expQ['export']);
$expBase = http_build_query($expQ);
// Base para os links dos cartões: preserva os filtros e troca só o estado
$cardQ = $_GET;
unset($cardQ['page'], $cardQ['export'], $cardQ['state']);
$cardBase = http_build_query($cardQ);
?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Status da Frota</h2>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?php if (report_has_query()) echo report_back_button('/relatorios/status-frota'); ?>
    </div>
</div>

<?php if ($tableMissing): ?>
<div class="card mb-16" style="padding:12px 16px;border-left:3px solid #f5a623;">
    <div style="font-size:13px;color:var(--muted);">
        <strong>Tabela de segmentos indisponível</strong> — a frota está sendo classificada apenas
        pelo silêncio de comunicação. Aplique a migração <code>v4.6.0</code> e rode
        <code>php scripts/state_builder.php 1</code> para distinguir movimento, ociosidade e parada.
    </div>
</div>
<?php endif; ?>

<?php render_template_bar('rel_status_frota', '/relatorios/status-frota'); ?>

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
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Placa</label>
            <input type="text" name="imei" value="<?= htmlspecialchars($filterImei) ?>" placeholder="IMEI ou nome..."
                   style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:170px;">
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Estado</label>
            <select name="state" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <?php foreach (FLEET_STATE_LABELS as $sk => $sl): ?>
                <option value="<?= $sk ?>" <?= $filterState === $sk ? 'selected' : '' ?>><?= htmlspecialchars($sl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:16px;">
    <?php foreach (FLEET_STATE_LABELS as $sk => $sl):
        $n   = $counts[$sk];
        $pct = $totalDevices > 0 ? ($n / $totalDevices * 100) : 0;
        $active = $filterState === $sk;
    ?>
    <a href="?<?= $cardBase ?><?= $cardBase ? '&' : '' ?>state=<?= $sk ?>" class="card" style="padding:14px 18px;text-decoration:none;display:block;border-left:3px solid <?= fleet_state_color($sk) ?>;<?= $active ? 'box-shadow:0 0 0 2px var(--brand);' : '' ?>">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);"><?= htmlspecialchars($sl) ?></div>
        <div class="text-mono" style="font-size:26px;font-weight:500;color:var(--ink);margin-top:4px;"><?= $n ?></div>
        <div class="text-mono text-muted" style="font-size:11px;"><?= number_format($pct, 1, ',', '.') ?>% da frota</div>
    </a>
    <?php endforeach; ?>
    <div class="card" style="padding:14px 18px;">
        <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);">Frota ativa</div>
        <div class="text-mono" style="font-size:26px;font-weight:500;color:var(--ink);margin-top:4px;"><?= $totalDevices ?></div>
        <div class="text-mono text-muted" style="font-size:11px;">soma dos 4 estados</div>
    </div>
</div>

<?php if ($totalDevices > 0): ?>
<div class="card mb-24" style="padding:16px 20px;">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);margin-bottom:8px;">Distribuição</div>
    <div style="display:flex;height:12px;border-radius:100px;overflow:hidden;background:var(--hairline);">
        <?php foreach (FLEET_STATE_LABELS as $sk => $sl):
            $pct = $counts[$sk] / $totalDevices * 100;
            if ($pct <= 0) continue;
        ?>
        <div style="width:<?= $pct ?>%;background:<?= fleet_state_color($sk) ?>;" title="<?= htmlspecialchars($sl) ?>: <?= $counts[$sk] ?>"></div>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:16px;margin-top:10px;">
        <?php foreach (FLEET_STATE_LABELS as $sk => $sl): ?>
        <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);">
            <span style="width:10px;height:10px;border-radius:3px;background:<?= fleet_state_color($sk) ?>;"></span>
            <?= htmlspecialchars($sl) ?>
            <span class="text-mono" style="color:var(--ink);"><?= $counts[$sk] ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Placa</th>
                <th>Cliente</th>
                <th>Estado</th>
                <th>Tempo no estado</th>
                <th>Última posição</th>
                <th>Velocidade</th>
                <th>Mapa</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted);">
                <?= $filterState !== '' ? 'Nenhum equipamento neste estado' : 'Nenhum equipamento ativo' ?>
            </td></tr>
            <?php else: foreach ($rows as $r):
                $hasCoords = $r['last_latitude'] && $r['last_longitude']
                          && is_valid_coordinate($r['last_latitude'], $r['last_longitude']);
            ?>
            <tr>
                <td>
                    <?= htmlspecialchars($r['device_name'] ?: $r['imei']) ?>
                    <div class="text-mono text-muted" style="font-size:11px;"><?= htmlspecialchars($r['imei']) ?></div>
                </td>
                <td><?= htmlspecialchars($r['customer_name']) ?></td>
                <td>
                    <span class="badge" style="background:<?= fleet_state_color($r['current_state']) ?>1a;color:<?= fleet_state_color($r['current_state']) ?>;border:1px solid <?= fleet_state_color($r['current_state']) ?>40;">
                        <?= htmlspecialchars(fleet_state_label($r['current_state'])) ?>
                    </span>
                </td>
                <td class="text-mono"><?= htmlspecialchars(fmt_duration($r['in_state_s'])) ?></td>
                <td class="text-mono"><?= fmt_brt($r['last_gps_time'], 'd/m/Y H:i:s', 'Nunca') ?></td>
                <td class="text-mono"><?= $r['last_speed'] !== null ? number_format((float)$r['last_speed'], 1, ',', '.') . ' km/h' : '—' ?></td>
                <td>
                    <?php if ($hasCoords): ?>
                    <a href="https://www.openstreetmap.org/?mlat=<?= $r['last_latitude'] ?>&mlon=<?= $r['last_longitude'] ?>&zoom=16"
                       target="_blank" class="badge badge-primary">Ver Mapa</a>
                    <?php else: echo '—'; endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, 'equipamentos') ?>

<p class="text-muted" style="font-size:11px;margin-top:8px;">
    Foto de <span class="text-mono"><?= fmt_brt($nowUtc, 'd/m/Y H:i:s') ?></span> (BRT).
    <strong>Sem comunicação</strong> é atribuído a quem não reporta há mais de
    <?= intdiv(OFFLINE_GAP_SECONDS, 60) ?> minutos, qualquer que fosse o estado anterior —
    o último estado conhecido não sobrevive ao silêncio. Os quatro estados somam sempre o total de
    equipamentos ativos: a lista parte do cadastro, não dos segmentos.
    O histórico de cada estado está em
    <a href="/relatorios/paradas">Paradas</a>, <a href="/relatorios/ociosidade">Ociosidade</a> e
    <a href="/relatorios/ignicao">Ignição</a>.
</p>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
