<?php
/**
 * JIMI Webhook System — Relatório de Alarmes v4.0.0
 * Rota: /relatorios/alarmes
 *
 * Filtros: Cliente, Placa, Filial, Tipo de Alarme, Status, Período.
 * Grade: Placa, Data/Hora, Nome do Alarme, Status, Velocidade, Endereço, Mapa
 * — com mapa embutido opcional (marcador por linha da página).
 * Paginação server-side, volume alto (~448 alarmes/dia).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/report_templates.php';
require_once __DIR__ . '/../includes/geocode.php';   // endereço no lugar de lat/lng
require_once __DIR__ . '/../includes/media.php';     // coluna Vídeo (v4.9.8)
// Salvar/aplicar/excluir modelo — antes de qualquer saída (as três ações redirecionam)
handle_template_actions('rel_alarmes', '/relatorios/alarmes');

$page_title = 'Relatório de Alarmes';
$current_route = 'rel_alarmes';
$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$dateFrom    = $_GET['date_from'] ?? brt_today();
$dateTo      = $_GET['date_to'] ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo); // teto global 31 dias
$filterCust  = $_GET['customer_id'] ?? null;
$filterImei  = $_GET['imei'] ?? null;
// Multiselect de tipos (chips, CSV) + retrocompat com o antigo campo texto alarm_type
$filterTypes = array_values(array_filter(array_map('trim', explode(',', $_GET['alarm_types'] ?? ''))));
$filterType  = $_GET['alarm_type'] ?? null;
$filterBranch = $_GET['branch_id'] ?? null;
$filterStatus = $_GET['alarm_status'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// Nome do alarme resolvido na LEITURA (v4.9.0) — a razão de existir e as
// armadilhas estão em alarm_label_sql() (includes/functions.php), que é o
// ponto único compartilhado com o relatório agendado do scripts/worker.php.
['joins' => $alarmNameJoins, 'expr' => $alarmNameExpr, 'diag' => $alarmDiagExpr] = alarm_label_sql();

// ── Eventos de diagnóstico (v4.9.9) ─────────────────────────────────────────
//
// Por padrão a grade mostra só o que é ALARME: o que o veículo diz ao operador.
// Handshake de upload, sono/despertar do equipamento e defeito de hardware
// saem — eram 5.073 das 5.112 linhas do homolog, ou seja, o relatório era
// 99,2% ruído de infraestrutura e 39 linhas de conteúdo.
//
// O modo diagnóstico é RESTRITO AO ADMINISTRADOR, por decisão de produto.
// A checagem é `role === 'admin'` estrito e feita aqui, no servidor: o `$isAdmin`
// das telas de relatório inclui `revendedor` (ele existe para escolher cliente,
// não para ver infraestrutura), e um parâmetro de URL não é permissão.
$podeVerDiagnostico = ($user['role'] ?? '') === 'admin';
$verDiagnostico = $podeVerDiagnostico && !empty($_GET['diagnostico']);

// Ordenação: whitelist de colunas + default crescente por data/hora
// `device_name` é coluna de devices e `alarm_name` agora é expressão, por isso
// os dois têm forma própria no ORDER BY — os demais são colunas de alarms.
$validSorts = ['alarm_time', 'alarm_name', 'device_name'];
[$sort, $order] = report_sort_params($validSorts, 'alarm_time', 'ASC');
$orderBy = match ($sort) {
    'device_name' => "d.device_name $order",
    'alarm_name'  => "alarm_label $order",
    default       => "a.$sort $order",
};

// Placas do cliente — o filtro virou seleção de placa (era caixa de texto de
// IMEI). Carregado antes do export porque este roda antes da grade.
$devices = [];
try {
    $dvStmt = $db->prepare("SELECT imei, device_name FROM devices WHERE customer_id = :cid AND is_active = 1 ORDER BY device_name");
    $dvStmt->execute([':cid' => $customerId]);
    $devices = $dvStmt->fetchAll();
} catch (Throwable $e) {}

$where = 'WHERE a.alarm_time BETWEEN :df AND :dt';
[$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo); // dias BRT → janela UTC
$params = [':df' => $utcFrom, ':dt' => $utcTo];

// Escopo multi-tenant centralizado (v4.7.3) — ver report_customer_scope().
// Para não-admin o ?customer_id da URL é ignorado; antes ele era obedecido.
$scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);
if ($scopeCust !== null) {
    // Fase 2 do fluxo chip→câmera→veículo: escopo pelo dono GRAVADO no
    // alarme (snapshot do momento em que chegou), não pelo dono ATUAL da
    // câmera — senão transferir uma câmera de cliente reatribuiria
    // retroativamente todo o histórico de alarmes dela.
    $where .= ' AND a.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}
// Filtro por PLACA (o campo é `imei` na URL por retrocompatibilidade com
// links e modelos salvos, mas o que o usuário escolhe é a placa).
if ($filterImei) {
    $where .= ' AND a.imei = :imei';
    $params[':imei'] = $filterImei;
}
// O filtro casa contra o nome RESOLVIDO, não contra a coluna crua: senão o
// alarme que a tela mostra como "Colisão do Veículo" (resolvido do catálogo)
// sumiria ao marcar esse chip, porque em `alarms.alarm_name` ele ainda está
// gravado como "Código 1046 (JTT)".
if ($filterTypes) {
    $ph = [];
    foreach ($filterTypes as $i => $t) {
        $ph[] = ":at$i";
        $params[":at$i"] = $t;
    }
    $where .= " AND ($alarmNameExpr) IN (" . implode(',', $ph) . ')';
} elseif ($filterType) {
    $where .= " AND (a.alarm_type = :atype OR ($alarmNameExpr) LIKE :aname)";
    $params[':atype'] = $filterType;
    $params[':aname'] = "%$filterType%";
}
if ($filterBranch) {
    $where .= ' AND d.branch_id = :bid';
    $params[':bid'] = (int)$filterBranch;
}
if ($filterStatus) {
    $where .= ' AND a.status = :st';
    $params[':st'] = $filterStatus;
}
// Diagnóstico: fora por padrão; SÓ diagnóstico quando o admin liga o modo —
// senão a lista continuaria dominada pelos 5.073 eventos técnicos e o modo
// não serviria para conferir nada.
$where .= $verDiagnostico
    ? " AND ($alarmDiagExpr) = 1"
    : " AND ($alarmDiagExpr) = 0";

// Export síncrono (padrão YUV §9.2): mesma query da grade, sem paginação
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('relatorios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expStmt = $db->prepare("
        SELECT a.imei, $alarmNameExpr AS alarm_label, a.alarm_time, a.status,
               a.speed, a.latitude, a.longitude,
               COALESCE(d.device_name, a.imei) AS device_name
        FROM alarms a
        LEFT JOIN devices d ON d.imei = a.imei
        $alarmNameJoins
        $where
        ORDER BY $orderBy
        LIMIT " . SYNC_EXPORT_MAX_ROWS);
    $expStmt->execute($params);
    // fetchAll ANTES do laço: o endereço é resolvido em UM lote paralelo.
    // Resolver dentro do while faria uma chamada HTTP por linha (v4.8.0).
    $src = $expStmt->fetchAll();
    $geo = geocode_map_rows($src);
    $expRows = [];
    $statusLabels = ['active' => 'Ativo', 'resolved' => 'Resolvido'];
    foreach ($src as $r) {
        $expRows[] = [
            $r['device_name'],
            fmt_brt($r['alarm_time'], 'd/m/Y H:i:s'),
            $r['alarm_label'] ?: '—',
            $statusLabels[$r['status']] ?? $r['status'],
            $r['speed'] !== null ? number_format((float)$r['speed'], 1) : '—',
            geocode_cell($geo, $r['latitude'], $r['longitude']),
            export_map_link($r['latitude'], $r['longitude']),
        ];
    }
    // Placa no subtítulo, como no de Posições: o PDF circula fora da tela
    $placaSel = 'Todas as placas';
    if ($filterImei) {
        $ps = $db->prepare("SELECT COALESCE(device_name, imei) FROM devices WHERE imei = ?");
        $ps->execute([$filterImei]);
        $placaSel = 'Placa: ' . ($ps->fetchColumn() ?: $filterImei);
    }
    stream_export($export, 'relatorio_alarmes',
        ['Placa', 'Data/Hora', 'Nome do Alarme', 'Status', 'Velocidade (km/h)', 'Endereço', 'Mapa'],
        $expRows, 'Relatório de Alarmes',
        "$placaSel  |  " . report_period_label($dateFrom, $dateTo),
        // Endereço e nome do alarme são as duas colunas longas; as demais são
        // curtas e fixas (placa, data, status, velocidade, rótulo do mapa).
        [1.0, 1.35, 2.4, 0.8, 0.9, 3.2, 0.6]);
}

// Count
$countStmt = $db->prepare("
    SELECT COUNT(*) FROM alarms a
    LEFT JOIN devices d ON d.imei = a.imei
    $alarmNameJoins
    $where
");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

// Data
$dataStmt = $db->prepare("
    SELECT a.id, a.imei, $alarmNameExpr AS alarm_label, a.alarm_time,
           a.status, a.speed, a.latitude, a.longitude,
           a.file_url, a.file_type,
           COALESCE(d.device_name, a.imei) AS device_name
    FROM alarms a
    LEFT JOIN devices d ON d.imei = a.imei
    $alarmNameJoins
    $where
    ORDER BY $orderBy
    LIMIT $perPage OFFSET $offset
");
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll();

// Pontos do mapa embutido. O balão leva a PLACA e a data/hora do alarme: aqui
// cada marcador pode ser de um veículo diferente, ao contrário do relatório de
// Posições, onde a placa é a mesma em todos os pontos.
$mapPoints = [];
foreach ($rows as $r) {
    if ($r['latitude'] && $r['longitude'] && (float)$r['latitude'] != 0.0 && (float)$r['longitude'] != 0.0) {
        $mapPoints[] = [
            'lat'   => (float)$r['latitude'],
            'lng'   => (float)$r['longitude'],
            'placa' => $r['device_name'],
            'when'  => fmt_brt($r['alarm_time'], 'd/m/Y H:i:s'),
            'nome'  => $r['alarm_label'] ?: '—',
        ];
    }
}

// Endereços da página, em um lote (v4.8.0). Com o cache quente pelo
// geocode_worker isto é uma consulta ao banco — 25 linhas em ~1,2 ms.
$geoPagina = geocode_map_rows($rows);

// Dropdowns
$customers = report_customer_options($db);

// Tipos de alarme oferecidos no filtro: SOMENTE DMS e ADAS (v4.8.3).
//
// Duas mudanças em uma. A lista vinha de `SELECT DISTINCT alarm_name FROM
// alarms` — ou seja, do que por acaso já tinha acontecido —, então trazia
// rótulos de infraestrutura ("Falha no Armazenamento", "Perda de Sinal de
// Vídeo"), as variantes "Fim de Alarme: …" e os "Código NNNN (JTT)" dos
// códigos ainda não cadastrados. Agora vem de `alarm_types`, que é o catálogo
// canônico, restrita às categorias DMS e ADAS: o núcleo do produto é a
// ocorrência de comportamento do motorista, e é isso que se filtra aqui.
//
// Consequência a conhecer: um tipo DMS/ADAS que nunca ocorreu também aparece
// (a lista descreve o catálogo, não o histórico) — o que é o comportamento
// desejado num filtro, senão só se pode filtrar o que já se sabe existir.
$types = $db->query(
    "SELECT DISTINCT alarm_name_pt AS alarm_name
       FROM alarm_types
      WHERE category IN ('DMS','ADAS')
      ORDER BY alarm_name_pt"
)->fetchAll();

$branchList = [];
try {
    $branchList = $db->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();
} catch (Exception $e) {}

// Coluna Vídeo (v4.9.8): o anexo do evento que o device declarou no próprio
// push do alarme. Resolvido pela EXTENSÃO — `alarms.file_type` está NULL em
// todo anexo `.ts` gravado antes desta versão (ver includes/media.php).
$temTs = false;
foreach ($rows as $r) {
    // media_pick(): com dois arquivos no campo, a extensão tem de sair do
    // arquivo que será REALMENTE tocado, não da string inteira.
    if (!empty($r['file_url']) && media_available($r['file_url'])
        && media_is_ts(media_pick($r['file_url']))) { $temTs = true; break; }
}

$extra_head = '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>#map-container{height:400px;border-radius:var(--radius-lg);border:1px solid var(--hairline);margin-bottom:16px;display:none;}</style>';
// mpegts.js só quando alguma linha da página for `.ts` — remuxa TS→fMP4 em JS,
// que é a única forma de o navegador tocar a gravação da câmera.
if ($temTs) {
    $extra_head .= '<script src="https://cdn.jsdelivr.net/npm/mpegts.js@1.7.3/dist/mpegts.js"></script>';
}
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Relatório de Alarmes</h2>
    <div style="display:flex;gap:8px;">
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <?php if (report_has_query()) echo report_back_button('/relatorios/alarmes'); ?>
    </div>
</div>

<?php render_template_bar('rel_alarmes', '/relatorios/alarmes'); ?>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <?php if ($isAdmin): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select name="customer_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterCust == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Placa</label>
            <select name="imei" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:160px;">
                <option value="">Todas</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= htmlspecialchars($d['imei']) ?>" <?= $filterImei === $d['imei'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars(placa_do_device($d['device_name'], $d['imei'])) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($branchList): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Filial</label>
            <select name="branch_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todas</option>
                <?php foreach ($branchList as $b): ?>
                <option value="<?= $b['id'] ?>" <?= $filterBranch == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <?php
            $msel_id = 'alarmtypes';
            $msel_label = 'Tipos de Alarme';
            $msel_param = 'alarm_types';
            $msel_options = array_column($types, 'alarm_name');
            $msel_selected = $filterTypes;
            $msel_vazio = 'Todos os tipos';
            include __DIR__ . '/../web/components/select_multi.php';
            ?>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Status</label>
            <select name="alarm_status" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Ativo</option>
                <option value="resolved" <?= $filterStatus === 'resolved' ? 'selected' : '' ?>>Resolvido</option>
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
        <?php if ($mapPoints): ?>
        <button type="button" class="btn btn-outline btn-sm" onclick="toggleMap()">Ver no Mapa</button>
        <?php endif; ?>
        <?php if ($podeVerDiagnostico): ?>
        <?php // Só o administrador vê este controle — e o servidor confere de novo,
              // porque esconder o botão não é autorização. ?>
        <label style="font-size:12px;display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--muted);padding-bottom:2px;">
            <input type="checkbox" name="diagnostico" value="1" style="width:auto;"
                   <?= $verDiagnostico ? 'checked' : '' ?> onchange="this.form.submit()">
            Eventos de diagnóstico
        </label>
        <?php endif; ?>
    </form>
</div>

<?php if ($mapPoints): ?>
<div id="map-container"></div>
<?php endif; ?>

<?php if ($rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid #f5a623;font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias: <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<?php if ($verDiagnostico): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid var(--primary);font-size:13px;color:var(--muted);">
    <strong style="color:var(--ink);">Modo diagnóstico.</strong>
    Esta lista mostra <strong>apenas</strong> eventos técnicos do equipamento — handshake de upload de vídeo,
    entrada e saída de repouso, e defeitos de hardware (câmera, armazenamento, sinal de vídeo).
    Eles não são comportamento do motorista e não geram ocorrência. Desmarque a caixa para voltar aos alarmes.
</div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th><?= report_sort_link('device_name', 'Placa', $sort, $order) ?></th>
                <th><?= report_sort_link('alarm_time', 'Data/Hora', $sort, $order) ?></th>
                <th><?= report_sort_link('alarm_name', 'Nome do Alarme', $sort, $order) ?></th>
                <th>Status</th>
                <th>Velocidade</th>
                <th>Endereço</th>
                <th>Mapa</th>
                <th>Vídeo</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted);">Nenhum alarme encontrado</td></tr>
            <?php else: ?>
            <?php foreach ($rows as $r):
                $hasCoords = $r['latitude'] && $r['longitude'] && $r['latitude'] != 0 && $r['longitude'] != 0;
                // 🔴 TER `file_url` NÃO É TER O VÍDEO. O nome do arquivo é anunciado
                // pela câmera no push do alarme; o arquivo em si sobe depois, por
                // outro caminho (attachment server / FTP), e pode simplesmente não
                // chegar. Em produção, 81 dos 106 alarmes com `file_url` — 76% —
                // apontavam para arquivo inexistente (18/08/2026). A tela oferecia
                // "Ver Vídeo" nos 106, e nos 81 o clique abria um player que nunca
                // carregava: nenhuma mensagem, nenhum erro, nada.
                //
                // `media_available()` já existia para exatamente isto e era usada
                // só pelo dashboard de ocorrências. Agora o relatório distingue os
                // três estados: sem mídia, mídia disponível, mídia não recebida.
                //
                // 🔴 Restrito a 'video' até 25/08/2026 — mas o anexo de alarme JT/T
                // (VIDEOUPLOAD) pode chegar como FOTO por canal, não só vídeo
                // (medido em produção: dois `.jpg`, um por câmera, pro mesmo
                // alarme). Com o filtro só em 'video' a linha caía no `—` mesmo
                // com o arquivo íntegro no disco. `image` entra na mesma condição.
                $midiaKind = media_kind($r['file_url'], $r['file_type']);
                $temVideo  = !empty($r['file_url']) && in_array($midiaKind, ['video', 'image'], true);

                // ── Player duplo (26/08/2026) ────────────────────────────────
                // `VIDEOUPLOAD` agora pede vídeo+foto dos canais 1 E 2 juntos
                // (docs/COMANDOS_128_CONSULTA.md §9.9): o alarme pode ter até 4
                // arquivos no mesmo `file_url`. `media_channel_files()` separa
                // por canal; monta-se um payload com o que existir e está no
                // disco (vídeo tem preferência sobre foto no MESMO canal).
                $porCanal = media_channel_files($r['file_url']);
                $midiaPorCanal = [];
                foreach ([1, 2] as $canal) {
                    foreach (['video', 'image'] as $kind) {
                        $nome = $porCanal[$kind][$canal] ?? null;
                        if ($nome === null || !media_available($nome)) continue;
                        if (isset($midiaPorCanal[$canal]) && $midiaPorCanal[$canal]['kind'] === 'video') continue;
                        $midiaPorCanal[$canal] = [
                            'url'  => media_play_url($nome),
                            'ts'   => media_is_ts($nome),
                            'kind' => $kind,
                            'nome' => basename($nome),
                        ];
                    }
                }
                // Retrocompat: arquivo sem canal reconhecível no nome (formato
                // antigo) cai no player único de sempre, no slot 1.
                if (!$midiaPorCanal && $temVideo && media_available($r['file_url'])) {
                    $arqUnico = media_pick($r['file_url']);
                    $midiaPorCanal[1] = [
                        'url' => media_play_url($r['file_url']), 'ts' => media_is_ts($arqUnico),
                        'kind' => $midiaKind, 'nome' => basename($arqUnico),
                    ];
                }
                $videoOk = !empty($midiaPorCanal);
            ?>
            <tr>
                <td class="text-mono"><?= htmlspecialchars($r['device_name']) ?></td>
                <td class="text-mono"><?= fmt_brt($r['alarm_time'], 'd/m/Y H:i:s') ?></td>
                <td><?= htmlspecialchars($r['alarm_label'] ?: '—') ?></td>
                <td>
                    <?php if ($r['status'] === 'active'): ?>
                    <span class="badge badge-warning">Ativo</span>
                    <?php elseif ($r['status'] === 'resolved'): ?>
                    <span class="badge badge-success">Resolvido</span>
                    <?php else: ?>
                    <span class="badge"><?= htmlspecialchars($r['status']) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $r['speed'] !== null ? number_format((float)$r['speed'], 1) . ' km/h' : '—' ?></td>
                <td class="cell-endereco"><?= htmlspecialchars(geocode_cell($geoPagina, $r['latitude'], $r['longitude'])) ?></td>
                <td>
                    <?php if ($hasCoords): ?>
                    <a href="https://www.openstreetmap.org/?mlat=<?= $r['latitude'] ?>&mlon=<?= $r['longitude'] ?>&zoom=16"
                       target="_blank" class="badge badge-primary">Ver Mapa</a>
                    <?php else: echo '—'; endif; ?>
                </td>
                <td>
                    <?php if ($videoOk): ?>
                    <button type="button" class="badge badge-primary" style="border:0;cursor:pointer;"
                            onclick="abrirVideo(this)"
                            data-media="<?= htmlspecialchars(json_encode($midiaPorCanal, JSON_UNESCAPED_SLASHES)) ?>"
                            data-titulo="<?= htmlspecialchars(($r['device_name'] ?? '') . ' · ' . ($r['alarm_label'] ?: '—') . ' · ' . fmt_brt($r['alarm_time'], 'd/m/Y H:i:s')) ?>">
                        <?= count($midiaPorCanal) > 1 ? '&#9654; Ver Vídeo' : ($midiaKind === 'image' ? '&#128247; Ver Foto' : '&#9654; Ver Vídeo') ?>
                    </button>
                    <?php elseif ($temVideo): ?>
                    <button type="button" class="badge" style="border:0;cursor:pointer;"
                            id="rv-<?= (int)$r['id'] ?>" onclick="pedirVideo(<?= (int)$r['id'] ?>)"
                            title="A câmera anunciou o arquivo &quot;<?= htmlspecialchars(basename(media_pick($r['file_url']))) ?>&quot; neste alarme, mas ele não chegou ao servidor. Clique para pedir o vídeo de novo — a câmera regenera o trecho a partir do cartão.">
                        &#8635; Pedir vídeo
                    </button>
                    <?php else: echo '—'; endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, 'alarmes') ?>

<!-- ── Modal do vídeo do alarme (v4.9.8; player duplo 26/08/2026) ───────────
     O player é MONTADO no clique, não uma vez por linha: 25 elementos <video>
     com `preload="metadata"` numa página abrem 25 conexões só para exibir a
     grade. Aqui só o alarme aberto carrega bytes.
     Dois painéis (canal 1 / canal 2) lado a lado — VIDEOUPLOAD agora pede os
     dois canais juntos (§9.9), e o objetivo é rodar os dois AO MESMO TEMPO,
     não alternar entre eles. Painel sem mídia para aquele canal fica oculto. -->
<div id="video-modal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(10,11,13,.62);align-items:center;justify-content:center;padding:24px;">
    <div class="card" style="max-width:1160px;width:100%;padding:16px 18px;">
        <div class="flex-between mb-12" style="gap:12px;">
            <h3 id="video-modal-titulo" style="font-size:14px;font-weight:600;color:var(--ink);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></h3>
            <button type="button" class="btn btn-outline btn-sm" style="flex-shrink:0;" onclick="fecharVideo()">Fechar</button>
        </div>
        <div id="video-modal-paineis" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
            <div class="video-modal-canal" data-canal="1">
                <div class="flex-between" style="margin-bottom:6px;">
                    <span style="font-size:11px;font-weight:600;color:var(--muted);">CANAL 1</span>
                    <a class="video-modal-baixar btn btn-outline btn-sm" style="padding:3px 10px;font-size:11px;" download>Baixar</a>
                </div>
                <div class="video-modal-player"></div>
                <span class="video-modal-arquivo text-mono" style="display:block;margin-top:4px;font-size:11px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
            </div>
            <div class="video-modal-canal" data-canal="2">
                <div class="flex-between" style="margin-bottom:6px;">
                    <span style="font-size:11px;font-weight:600;color:var(--muted);">CANAL 2</span>
                    <a class="video-modal-baixar btn btn-outline btn-sm" style="padding:3px 10px;font-size:11px;" download>Baixar</a>
                </div>
                <div class="video-modal-player"></div>
                <span class="video-modal-arquivo text-mono" style="display:block;margin-top:4px;font-size:11px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></span>
            </div>
        </div>
    </div>
</div>
<style>
@media (max-width: 720px) {
    #video-modal-paineis { grid-template-columns: 1fr; }
}
</style>

<?php
// Emite o CSS/JS do player mesmo sem nenhum bloco renderizado no HTML — aqui
// quem monta o player é o JS do modal.
require_once __DIR__ . '/../web/components/video_player_assets.php';
?>
<script>
function abrirVideo(btn) {
    var m = document.getElementById('video-modal');
    document.getElementById('video-modal-titulo').textContent = btn.dataset.titulo || 'Vídeo do alarme';
    var midia = {};
    try { midia = JSON.parse(btn.dataset.media || '{}'); } catch (e) {}

    document.querySelectorAll('.video-modal-canal').forEach(function (painel) {
        var canal = painel.dataset.canal;
        var item  = midia[canal];
        var player = painel.querySelector('.video-modal-player');
        var dl     = painel.querySelector('.video-modal-baixar');
        var nomeEl = painel.querySelector('.video-modal-arquivo');
        if (!item) {
            painel.style.display = 'none';
            bcPlayer.destruir(player.querySelector('.bc-player'));
            player.innerHTML = '';
            return;
        }
        painel.style.display = '';
        dl.href = item.url;
        dl.setAttribute('download', item.nome || '');
        nomeEl.textContent = item.nome || '';
        bcPlayer.montar(player, item.url, !!item.ts, 380, item.kind);
    });

    // Um canal só (JC182, ou legado sem canal reconhecível): ocupa a largura toda.
    var visiveis = Object.keys(midia).length;
    document.getElementById('video-modal-paineis').style.gridTemplateColumns = visiveis > 1 ? '1fr 1fr' : '1fr';

    m.style.display = 'flex';
}

function fecharVideo() {
    var m = document.getElementById('video-modal');
    // Destruir, e não apenas esconder: um <video> oculto continua baixando.
    // Dois painéis agora — os dois precisam ser desmontados.
    m.querySelectorAll('.video-modal-player').forEach(function (player) {
        bcPlayer.destruir(player.querySelector('.bc-player'));
        player.innerHTML = '';
    });
    m.style.display = 'none';
}

/**
 * Pede à câmera o vídeo de um alarme que ficou sem ele.
 *
 * O reenvio NÃO é instantâneo: a câmera regenera o trecho a partir do cartão e
 * sobe depois. Quem religa o arquivo ao alarme é o `match_pending_video()`, no
 * webhook do "Upload de Vídeo Concluído" — por isso a tela só confirma o pedido
 * e pede para recarregar mais tarde, em vez de fingir que já tem o vídeo.
 */
function pedirVideo(alarmId) {
    var btn = document.getElementById('rv-' + alarmId);
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    var antes = btn.innerHTML;
    btn.innerHTML = 'Pedindo...';

    fetch('/solicitarvideo', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || ''},
        body: JSON.stringify({alarm_id: alarmId})
    }).then(function (r) { return r.json().then(function (d) { return {ok: r.ok, d: d}; }); })
      .then(function (res) {
        if (res.d && res.d.ok) {
            btn.className = 'badge badge-success';
            btn.innerHTML = 'Solicitado';
            btn.title = res.d.msg || 'Vídeo solicitado.';
        } else {
            btn.className = 'badge badge-error';
            btn.innerHTML = 'Não deu';
            btn.title = (res.d && res.d.msg) ? res.d.msg : 'Falha ao solicitar.';
            btn.disabled = false;
            setTimeout(function () { btn.className = 'badge'; btn.innerHTML = antes; }, 6000);
        }
      }).catch(function () {
        btn.className = 'badge badge-error';
        btn.innerHTML = 'Erro de rede';
        btn.disabled = false;
        setTimeout(function () { btn.className = 'badge'; btn.innerHTML = antes; }, 6000);
      });
}

document.getElementById('video-modal').addEventListener('click', function (e) {
    if (e.target === this) fecharVideo();   // clique no fundo fecha
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.getElementById('video-modal').style.display === 'flex') fecharVideo();
});
</script>

<?php if ($mapPoints): ?>
<script>
// Marcadores da PÁGINA corrente — o mapa mostra o mesmo recorte que a grade
var mapData = <?= json_encode($mapPoints, JSON_UNESCAPED_UNICODE) ?>;
var mapInstance = null;
function toggleMap() {
    var container = document.getElementById('map-container');
    if (container.style.display === 'block') { container.style.display = 'none'; return; }
    container.style.display = 'block';
    if (!mapInstance) {
        mapInstance = L.map('map-container');
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution:'&copy; OSM'}).addTo(mapInstance);
        var bounds = [];
        mapData.forEach(function(p) {
            bounds.push([p.lat, p.lng]);
            L.marker([p.lat, p.lng]).addTo(mapInstance)
                .bindPopup('<b>' + p.placa + '</b><br>' + p.when + '<br>' + p.nome);
        });
        if (bounds.length > 0) mapInstance.fitBounds(bounds);
        else mapInstance.setView([-15.78, -47.93], 5);
    }
    setTimeout(function(){ mapInstance.invalidateSize(); }, 100);
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
