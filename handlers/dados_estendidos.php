<?php
/**
 * bycamera — Dados Estendidos v4.24.1
 * Rota: /dados-estendidos
 *
 * Consulta somente-leitura, por placa, de dois conjuntos de dados que já são
 * GRAVADOS pelo webhook mas nunca tinham tela nenhuma (achado ao conferir se
 * `gpsMode`/`postMethod` eram tratados, 22/09/2026). Os dois vêm numa lista
 * ÚNICA, ordenada por data/hora — a v4.24.0 os separava em duas abas:
 *
 *   Linha "Posição GPS": `gps_data.gps_mode`/`post_type`/`post_method`/`speed`
 *   (§1.3 Push GPS Data). Os três campos têm tabela oficial de valores
 *   (includes/gps_extras.php); o `postMethod` — o MOTIVO da transmissão — vem
 *   como inteiro e é mostrado "código — nome PT-BR".
 *
 *   Linha de extensão: `device_events` (só `pushTerminalTransInfo.php` grava,
 *   §1.15 Push Extension Data). O `content` de cada linha é decodificado por
 *   `parse_extension_content()`/`extension_content_render()`
 *   (includes/gps_extras.php) — formato NÃO é JSON válido, ver o cabeçalho
 *   daquele arquivo.
 *
 * Exclusiva do administrador (decisão do dono do produto, 22/09/2026) — mesmo
 * padrão da Auditoria (v4.22.1): `require_admin()` além de
 * `require_permission()`, porque `can()` é permissivo por omissão.
 *
 * Visual: barra de filtros no padrão dos relatórios (`.filtro-rotulo` /
 * `.filtro-campo`, campo do veículo = PLACA cujo valor é o IMEI), grade em
 * `.table-wrap` e `report_pagination()` — ver tests/filtros.spec.js.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/gps_extras.php';

require_admin();
require_permission('dados-estendidos', 'view');

$db         = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user       = get_jimi_user();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$filtroCust = $_GET['customer_id'] ?? null;
$scopeCust  = report_customer_scope($filtroCust, $isAdmin, $customerId);
$customers  = $isAdmin ? report_customer_options($db) : [];
$devices    = report_device_options($db, $scopeCust);

$imei  = trim((string)($_GET['imei'] ?? ''));
$imeisVisiveis = array_column($devices, 'imei');
if ($imei !== '' && !in_array($imei, $imeisVisiveis, true)) {
    $imei = ''; // fora do escopo do cliente/revendedor — mesmo tratamento de rel_posicoes.php
}

$dateFrom = $_GET['date_from'] ?? brt_today();
$dateTo   = $_GET['date_to']   ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo);
[$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo);

// Só data/hora ordena; mais recente primeiro (é uma tela de diagnóstico: o que
// o equipamento mandou agora importa mais que o que mandou de manhã).
[$sort, $order] = report_sort_params(['ts'], 'ts', 'DESC');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$totalRows  = 0;
$totalPages = 1;
$rows       = [];

if ($imei !== '') {
    try {
        // Nomes de parâmetro distintos por ramo: o PDO não deixa repetir o mesmo
        // placeholder nomeado na mesma consulta.
        $params = [
            ':g_imei' => $imei, ':g_df' => $utcFrom, ':g_dt' => $utcTo,
            ':e_imei' => $imei, ':e_df' => $utcFrom, ':e_dt' => $utcTo,
        ];

        $stmtCount = $db->prepare("
            SELECT (SELECT COUNT(*) FROM gps_data
                     WHERE imei = :g_imei AND gps_time BETWEEN :g_df AND :g_dt)
                 + (SELECT COUNT(*) FROM device_events
                     WHERE imei = :e_imei AND event_time BETWEEN :e_df AND :e_dt)
        ");
        $stmtCount->execute($params);
        $totalRows  = (int)$stmtCount->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $perPage;

        // Lista única = UNION ALL das duas fontes. Cada ramo se limita a
        // `offset + perPage` linhas ANTES da união: a página pedida só pode
        // conter as primeiras N de cada lado, então o MySQL não precisa juntar
        // (e ordenar) o período inteiro — gps_data chega a milhares de linhas
        // por dia por equipamento. $order vem de report_sort_params() (só
        // ASC/DESC) e $limite de inteiros: interpolar aqui é seguro.
        $limite = $offset + $perPage;
        $stmt = $db->prepare("
            SELECT * FROM (
                (SELECT gps_time AS ts, 'gps' AS src, id AS rid,
                        gps_mode, post_type, post_method, speed,
                        NULL AS raw_data
                   FROM gps_data
                  WHERE imei = :g_imei AND gps_time BETWEEN :g_df AND :g_dt
                  ORDER BY gps_time $order, id $order
                  LIMIT $limite)
                UNION ALL
                (SELECT event_time, 'ext', id,
                        NULL, NULL, NULL, NULL,
                        CAST(raw_data AS CHAR)
                   FROM device_events
                  WHERE imei = :e_imei AND event_time BETWEEN :e_df AND :e_dt
                  ORDER BY event_time $order, id $order
                  LIMIT $limite)
            ) u
            ORDER BY ts $order, src, rid $order
            LIMIT :lim OFFSET :off
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decodifica aqui (não no SQL) — raw_data é JSON válido, content
        // dentro dele não é (ver includes/gps_extras.php).
        foreach ($rows as &$r) {
            if ($r['src'] !== 'ext') continue;
            $payload = json_decode((string)$r['raw_data'], true) ?: [];
            $extId   = (int)($payload['extensionId'] ?? 0);
            $content = $payload['content'] ?? null;
            $r['extension_id'] = $extId;
            $r['decoded'] = $extId > 0
                ? extension_content_render($extId, parse_extension_content(is_string($content) ? $content : null))
                : [];
        }
        unset($r);
    } catch (PDOException $e) {
        Logger::warning('dados_estendidos: consulta falhou', ['erro' => $e->getMessage()]);
    }
}

$page_title    = 'Dados Estendidos';
$current_route = 'dados-estendidos';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Dados Estendidos</h2>
    <?php if ($imei !== ''): ?>
    <div style="display:flex;gap:8px;">
        <?= report_back_button('/dados-estendidos') ?>
    </div>
    <?php endif; ?>
</div>

<div class="card mb-24" style="padding:16px 20px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
        <?php if ($isAdmin && $customers): ?>
        <div>
            <label for="flt-customer_id" class="filtro-rotulo">Cliente</label>
            <select id="flt-customer_id" name="customer_id" class="filtro-campo" style="min-width:170px;">
                <option value="">Todos</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label for="flt-imei" class="filtro-rotulo">Placa</label>
            <select id="flt-imei" name="imei" class="filtro-campo" style="min-width:180px;" required>
                <option value="">— Selecione —</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= htmlspecialchars($d['imei']) ?>" <?= $imei === $d['imei'] ? 'selected' : '' ?>><?= htmlspecialchars(placa_do_device($d['device_name'] ?? null, $d['imei'])) ?></option>
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
        <button type="submit" class="btn btn-primary btn-sm">Gerar</button>
    </form>
</div>

<?php if ($imei !== '' && $rangeClamped): ?>
<div class="card mb-16" style="padding:10px 16px;border-left:3px solid var(--warning);font-size:13px;color:var(--muted);">
    O período foi ajustado para o máximo de <?= REPORT_RANGE_MAX_DAYS ?> dias: <?= htmlspecialchars(date('d/m/Y', strtotime($dateFrom))) ?> a <?= htmlspecialchars(date('d/m/Y', strtotime($dateTo))) ?>.
</div>
<?php endif; ?>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th><?= report_sort_link('ts', 'Data/Hora', $sort, $order, 'DESC') ?></th>
                <th>Tipo</th>
                <th>Motivo da transmissão</th>
                <th>Modo</th>
                <th>Posicionamento</th>
                <th>Velocidade</th>
                <th>Dados da extensão</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="7"><div class="empty-state"><p><?= $imei !== '' ? 'Nenhum registro no período.' : 'Selecione uma placa e clique em Gerar.' ?></p></div></td></tr>
            <?php else: ?>
            <?php foreach ($rows as $r): ?>
            <?php if ($r['src'] === 'gps'): ?>
            <tr>
                <td class="text-mono"><?= fmt_brt($r['ts'], 'd/m/Y H:i:s') ?></td>
                <td style="white-space:nowrap;"><span class="badge badge-primary">Posição GPS</span></td>
                <td>
                    <?php if ($r['post_method'] === null): ?>—
                    <?php else: $nomeMotivo = post_method_name($r['post_method']); ?>
                    <span class="text-mono"><?= (int)$r['post_method'] ?></span> —
                    <span<?= $nomeMotivo === null ? ' title="Código fora da tabela publicada pelo fabricante (0x00–0x0F)" style="color:var(--muted);"' : '' ?>><?= htmlspecialchars($nomeMotivo ?? 'Sem descrição do fabricante') ?></span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap;">
                    <?php if ($r['gps_mode'] === null): ?>—
                    <?php else: ?><span class="badge badge-<?= (int)$r['gps_mode'] === 1 ? 'warning' : 'success' ?>"><?= htmlspecialchars(gps_mode_label($r['gps_mode'])) ?></span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars(post_type_label($r['post_type'])) ?></td>
                <td class="text-mono"><?= $r['speed'] === null ? '—' : number_format((float)$r['speed'], 1) . ' km/h' ?></td>
                <td>—</td>
            </tr>
            <?php else: ?>
            <tr>
                <td class="text-mono"><?= fmt_brt($r['ts'], 'd/m/Y H:i:s') ?></td>
                <td style="white-space:nowrap;"><span class="badge"><?= htmlspecialchars(extension_id_label($r['extension_id'])) ?></span></td>
                <td>—</td>
                <td>—</td>
                <td>—</td>
                <td>—</td>
                <td>
                    <?php if ($r['decoded']): ?>
                        <?php foreach ($r['decoded'] as $par): ?>
                            <div><strong><?= htmlspecialchars($par['label']) ?>:</strong> <?= htmlspecialchars($par['value']) ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, 'registros') ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
