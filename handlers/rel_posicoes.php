<?php
/**
 * JIMI Webhook System — Relatório de Posições v4.0.0
 * Rota: /relatorios/posicoes
 *
 * Filtro: Ativo + Período + Intervalo + [Gerar] + Export.
 * Grade: Identificador, Endereço (geocodificado), Motorista, Ignição, Sinal, Velocidade, Horário.
 *
 * Lista as transmissões que TÊM coordenada (decisão do dono do produto): é um
 * relatório de POSIÇÕES, e linha sem fixo de GPS não tem posição a mostrar.
 * O mapa do período inteiro foi removido — a visualização é por linha, no link
 * da coluna Mapa. Alarmes ficam no relatório próprio, não entram aqui.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('rel_posicoes', '/relatorios/posicoes');
require_once __DIR__ . '/../includes/geocode.php';

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$selImei    = $_GET['imei'] ?? '';
$filterCust = $_GET['customer_id'] ?? null;
$dateFrom   = $_GET['date_from'] ?? brt_today();
$dateTo     = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo); // teto global 31 dias
$timeFrom   = $_GET['time_from'] ?? '';   // faixa horária opcional (BRT)
$timeTo     = $_GET['time_to'] ?? '';
// 'continua' = data/hora inicial → data/hora final (uma janela só)
// 'diaria'   = a faixa horária repetida em cada dia do intervalo
$timeMode   = ($_GET['time_mode'] ?? 'continua') === 'diaria' ? 'diaria' : 'continua';
$interval   = $_GET['interval'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$generated = !empty($_GET['gerar']);

// Escopo multi-tenant centralizado — ver report_customer_scope().
// Resolvido AQUI, antes de tudo, porque é ele quem decide de qual cliente
// vêm as placas do seletor: a lista carregada com o cliente da SESSÃO
// ignorava o filtro da tela, e o admin que trocasse de cliente no formulário
// continuava vendo as placas do anterior. Para não-admin o `?customer_id=`
// é ignorado (não validado) dentro da própria função.
$scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);

// Lista de placas — carregada AQUI porque o export síncrono roda antes da
// grade e precisa dela para pôr a PLACA (não o IMEI) no subtítulo do PDF.
$devices   = report_device_options($db, $scopeCust);
$customers = $isAdmin ? report_customer_options($db) : [];

// Ordenação: só por data/hora; default crescente (mais antigo no topo)
[$sort, $order] = report_sort_params(['gps_time'], 'gps_time', 'ASC');

$rows = [];
$totalRows = 0;
$totalPages = 1;
$geoCache = [];

if ($generated && $selImei) {
    try {
        // Prefixo g. obrigatório: as queries da grade/export fazem JOIN com devices
        // (imei/id existem nas duas tabelas → coluna ambígua quebrava o relatório)
        // Só transmissão COM coordenada: sem fixo de GPS o device grava 0/NULL,
        // e essa linha não tem posição para mostrar nem para exportar.
        $where = 'WHERE g.imei = :imei AND g.gps_time BETWEEN :df AND :dt'
               . ' AND g.latitude IS NOT NULL AND g.latitude <> 0'
               . ' AND g.longitude IS NOT NULL AND g.longitude <> 0';
        // Dias BRT + faixa horária opcional (contínua ou repetida em cada dia)
        [$utcFrom, $utcTo, $timeSql, $timeParams] =
            report_time_window('g.gps_time', $dateFrom, $dateTo, $timeFrom, $timeTo, $timeMode);
        $where .= $timeSql;
        $params = [':imei' => $selImei, ':df' => $utcFrom, ':dt' => $utcTo] + $timeParams;

        // 🔒 Escopo multi-tenant — faltava aqui: a query só filtrava por
        // `imei`, então trocar o parâmetro `?imei=` na URL mostrava a posição
        // de QUALQUER cliente. Fase 2 do fluxo chip→câmera→veículo: usa o
        // dono GRAVADO no ponto (snapshot do momento), não o atual da câmera.
        // ($scopeCust já foi resolvido lá em cima, junto com a lista de placas)
        if ($scopeCust !== null) {
            $where .= ' AND g.customer_id = :cid';
            $params[':cid'] = $scopeCust;
        }

        if ($interval === 'sampled') {
            $where .= ' AND MOD(g.id, 10) = 0';
        }

        // Export síncrono (padrão YUV §9.2): mesma query da grade, sem paginação
        $export = $_GET['export'] ?? '';
        if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
            require_permission('relatorios', 'export');
            require_once __DIR__ . '/../includes/export_helper.php';
            $expStmt = $db->prepare("
                SELECT g.imei, g.latitude, g.longitude, g.speed, g.gps_time,
                       g.acc AS ignition, g.status AS gps_status, g.gsm_signal,
                       COALESCE(d.device_name, g.imei) as device_name,
                       COALESCE(dg.name, g.driver_name,
                                (SELECT dr.name FROM trips tr JOIN drivers dr ON dr.id = tr.driver_id
                          WHERE tr.imei = g.imei AND g.gps_time >= tr.started_at
                            AND g.gps_time <= COALESCE(tr.ended_at, UTC_TIMESTAMP())
                          ORDER BY tr.started_at DESC LIMIT 1), '—') as driver_name
                FROM gps_data g
                LEFT JOIN devices d ON d.imei = g.imei
                LEFT JOIN drivers dg ON dg.id = g.driver_id
                $where
                ORDER BY g.$sort $order
                LIMIT " . SYNC_EXPORT_MAX_ROWS);
            $expStmt->execute($params);
            // fetchAll antes do laço: endereço resolvido em UM lote paralelo
            $src = $expStmt->fetchAll();
            $geoExp = geocode_map_rows($src, 'latitude', 'longitude', 2000);
            $expRows = [];
            foreach ($src as $r) {
                $expRows[] = [
                    $r['device_name'],
                    $r['driver_name'],
                    fmt_brt($r['gps_time'], 'd/m/Y H:i:s'),
                    geocode_cell($geoExp, $r['latitude'], $r['longitude']),
                    export_map_link($r['latitude'], $r['longitude']),
                    $r['speed'] !== null ? number_format((float)$r['speed'], 1) : '—',
                    $r['ignition'] ? 'Ligada' : 'Desligada',
                    in_array($r['gps_status'], ['A', 'VALID'], true) ? 'Válido' : ($r['gps_status'] ?? '—'),
                ];
            }
            // Subtítulo com a PLACA, não o IMEI: é o identificador que o
            // usuário escolheu no filtro e o que ele reconhece no papel.
            $placaSel = $selImei;
            foreach ($devices as $dv) {
                if ($dv['imei'] === $selImei) { $placaSel = $dv['device_name'] ?: $selImei; break; }
            }
            stream_export($export, 'relatorio_posicoes',
                ['Placa', 'Motorista', 'Data/Hora', 'Endereço', 'Mapa', 'Velocidade (km/h)', 'Ignição', 'Sinal GPS'],
                $expRows, 'Relatório de Posições',
                "Placa: $placaSel  |  " . report_period_label($dateFrom, $dateTo, $timeFrom, $timeTo, $timeMode),
                // Pesos de coluna: o endereço leva ~3,6x uma coluna comum. Com
                // larguras iguais (8 colunas ≈ 96 pt cada) ele saía cortado em
                // metade da rua; o que sobrar agora quebra em linha.
                [1.0, 1.3, 1.35, 3.6, 0.6, 0.9, 0.85, 0.8]);
        }

        $countStmt = $db->prepare("SELECT COUNT(*) FROM gps_data g $where");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();
        $totalPages = max(1, ceil($totalRows / $perPage));
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare("
            SELECT g.id, g.imei, g.latitude, g.longitude, g.speed, g.gps_time,
                   g.acc AS ignition, g.status AS gps_status,
                   COALESCE(d.device_name, g.imei) as device_name,
                   -- Motorista, em três níveis de precedência (v4.8.0):
                   --   1. o que a CÂMERA mandou junto com a posição
                   --   2. o nome cru, quando veio sem cadastro local
                   --   3. o condutor da VIAGEM que contém o ponto (fallback)
                   -- O nível 1 é o que a migração v4.8.0 tornou possível; os
                   -- outros dois mantêm o relatório útil no histórico já
                   -- gravado e nos equipamentos que não enviam o campo.
                   COALESCE(dg.name, g.driver_name,
                            (SELECT dr.name FROM trips tr JOIN drivers dr ON dr.id = tr.driver_id
                          WHERE tr.imei = g.imei AND g.gps_time >= tr.started_at
                            AND g.gps_time <= COALESCE(tr.ended_at, UTC_TIMESTAMP())
                          ORDER BY tr.started_at DESC LIMIT 1), '—') as driver_name
            FROM gps_data g
            LEFT JOIN devices d ON d.imei = g.imei
            LEFT JOIN drivers dg ON dg.id = g.driver_id
            $where
            ORDER BY g.$sort $order
            LIMIT $perPage OFFSET $offset
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Endereço geocodificado. Até a v4.7.x havia um orçamento de apenas
        // 3 resoluções por página, imposto pelo rate limit de 1 req/s do
        // Nominatim PÚBLICO — com o resultado de que a coluna ficava quase
        // sempre vazia e o cache acumulou 82 linhas em meses. Com o Nominatim
        // interno (~450 pts/s) e o cache mantido quente pelo geocode_worker,
        // a página resolve tudo de uma vez.
        $geoCache = geocode_map_rows($rows);
    } catch (Exception $e) {}
}

// ($devices já foi carregado antes do bloco de export)

$page_title = 'Relatório de Posições';
$current_route = 'rel_posicoes';

require_once __DIR__ . '/../web/layout_base.php';
?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Posições</h2>
    <?php if ($generated && $selImei): ?>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?= report_back_button('/relatorios/posicoes') ?>
    </div>
    <?php endif; ?>
</div>

<?php render_template_bar('rel_posicoes', '/relatorios/posicoes'); ?>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <input type="hidden" name="gerar" value="1">
        <?php if ($isAdmin): ?>
        <div>
            <label for="flt-customer_id" style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select id="flt-customer_id" name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:170px;">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= $filterCust == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="flt-imei" style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Placa</label>
            <select id="flt-imei" name="imei" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:180px;">
                <option value="">— Selecione —</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>" <?= $selImei===$d['imei']?'selected':'' ?>><?= htmlspecialchars($d['device_name']??$d['imei']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="flt-interval" style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Intervalo</label>
            <select id="flt-interval" name="interval" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="all" <?= $interval==='all'?'selected':'' ?>>Todas as posições</option>
                <option value="sampled" <?= $interval==='sampled'?'selected':'' ?>>Amostrado (1:10)</option>
            </select>
        </div>
        <div>
            <label for="flt-date_from" style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Período (máx. <?= REPORT_RANGE_MAX_DAYS ?> dias)</label>
            <div style="display:flex;gap:4px;">
                <input type="date" id="flt-date_from" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
                <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:130px;">
            </div>
        </div>
        <div>
            <label for="flt-time_from" style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Faixa horária (opcional)</label>
            <div style="display:flex;gap:4px;">
                <input type="time" id="flt-time_from" name="time_from" value="<?= htmlspecialchars($timeFrom) ?>" title="Hora inicial (BRT) — vazio = 00:00" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:100px;">
                <input type="time" name="time_to" value="<?= htmlspecialchars($timeTo) ?>" title="Hora final (BRT) — vazio = 23:59" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:100px;">
                <select name="time_mode" title="Como aplicar a faixa horária ao período" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:190px;">
                    <option value="continua" <?= $timeMode==='continua'?'selected':'' ?>>Contínua (início → fim)</option>
                    <option value="diaria" <?= $timeMode==='diaria'?'selected':'' ?>>Em cada dia do período</option>
                </select>
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
            <tr><th>Placa</th><th>Motorista</th><th><?= report_sort_link('gps_time', 'Data/Hora', $sort, $order) ?></th><th>Endereço</th><th>Mapa</th><th>Velocidade</th><th>Ignição</th><th>Sinal GPS</th></tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="8"><div class="empty-state"><p><?= $generated ? 'Nenhuma posição encontrada.' : 'Selecione uma placa e clique em Gerar.' ?></p></div></td></tr>
            <?php else: ?>
            <?php foreach ($rows as $r): ?>
            <tr>
                <td class="text-mono"><?= htmlspecialchars($r['device_name']) ?></td>
                <td><?= htmlspecialchars($r['driver_name']) ?></td>
                <td class="text-mono"><?= fmt_brt($r['gps_time'], 'd/m/Y H:i:s') ?></td>
                <td class="cell-endereco">
                    <?php $geoKey = round((float)$r['latitude'], 6) . ',' . round((float)$r['longitude'], 6); ?>
                    <?= isset($geoCache[$geoKey]) ? htmlspecialchars($geoCache[$geoKey]) : '—' ?>
                </td>
                <td>
                    <?php if ((float)$r['latitude'] != 0): ?>
                    <a href="<?= htmlspecialchars(map_link_url($r['latitude'], $r['longitude'])) ?>"
                       target="_blank" class="badge badge-primary">Ver Mapa</a>
                    <?php else: echo '—'; endif; ?>
                </td>
                <td><?= $r['speed'] !== null ? number_format((float)$r['speed'], 1) . ' km/h' : '—' ?></td>
                <td><?= $r['ignition'] ? '<span class="badge badge-success">Ligada</span>' : '<span class="badge">Desligada</span>' ?></td>
                <td><?= in_array($r['gps_status'] ?? '', ['A', 'VALID'], true) ? '<span class="badge badge-success">Válido</span>' : '<span class="badge">' . htmlspecialchars($r['gps_status'] ?? '—') . '</span>' ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, 'posições') ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
