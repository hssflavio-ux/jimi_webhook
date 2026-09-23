<?php
/**
 * bycamera — Dados Estendidos v4.24.0
 * Rota: /dados-estendidos
 *
 * Consulta somente-leitura, por dispositivo, de dois conjuntos de dados que
 * já são GRAVADOS pelo webhook mas nunca tinham tela nenhuma (achado ao
 * conferir se `gpsMode`/`postMethod` eram tratados, 22/09/2026):
 *
 *   Aba "Transmissão GPS": `gps_data.gps_mode`/`post_type`/`post_method`
 *   (§1.3 Push GPS Data). `gpsMode` e `postType` têm tabela oficial de
 *   valores (includes/gps_extras.php); `postMethod` NÃO tem — mostrado cru,
 *   sem rótulo inventado (CHANGELOG 4.17.11).
 *
 *   Aba "Extensão do Terminal": `device_events` (só `pushTerminalTransInfo.php`
 *   grava, §1.15 Push Extension Data). O `content` de cada linha é decodificado
 *   por `parse_extension_content()`/`extension_content_render()`
 *   (includes/gps_extras.php) — formato NÃO é JSON válido, ver o cabeçalho
 *   daquele arquivo.
 *
 * Exclusiva do administrador (decisão do dono do produto, 22/09/2026) — mesmo
 * padrão da Auditoria (v4.22.1): `require_admin()` além de
 * `require_permission()`, porque `can()` é permissivo por omissão.
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

$tab   = ($_GET['tab'] ?? 'gps') === 'extensao' ? 'extensao' : 'gps';
$imei  = trim((string)($_GET['imei'] ?? ''));
$imeisVisiveis = array_column($devices, 'imei');
if ($imei !== '' && !in_array($imei, $imeisVisiveis, true)) {
    $imei = ''; // fora do escopo do cliente/revendedor — mesmo tratamento de rel_posicoes.php
}

$dateFrom = $_GET['date_from'] ?? brt_today();
$dateTo   = $_GET['date_to']   ?? brt_today();
[$dateFrom, $dateTo, $rangeClamped] = clamp_report_range($dateFrom, $dateTo);
[$utcFrom, $utcTo] = brt_day_range_to_utc($dateFrom, $dateTo);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$totalRows  = 0;
$totalPages = 1;
$rows       = [];

if ($imei !== '') {
    try {
        if ($tab === 'gps') {
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM gps_data WHERE imei = :imei AND gps_time BETWEEN :df AND :dt");
            $stmtCount->execute([':imei' => $imei, ':df' => $utcFrom, ':dt' => $utcTo]);
            $totalRows  = (int)$stmtCount->fetchColumn();
            $totalPages = max(1, (int)ceil($totalRows / $perPage));
            $page       = min($page, $totalPages);
            $offset     = ($page - 1) * $perPage;

            $stmt = $db->prepare("
                SELECT gps_time, gps_mode, post_type, post_method, speed
                FROM gps_data
                WHERE imei = :imei AND gps_time BETWEEN :df AND :dt
                ORDER BY gps_time DESC
                LIMIT :lim OFFSET :off
            ");
            $stmt->bindValue(':imei', $imei);
            $stmt->bindValue(':df', $utcFrom);
            $stmt->bindValue(':dt', $utcTo);
            $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmtCount = $db->prepare("SELECT COUNT(*) FROM device_events WHERE imei = :imei AND event_time BETWEEN :df AND :dt");
            $stmtCount->execute([':imei' => $imei, ':df' => $utcFrom, ':dt' => $utcTo]);
            $totalRows  = (int)$stmtCount->fetchColumn();
            $totalPages = max(1, (int)ceil($totalRows / $perPage));
            $page       = min($page, $totalPages);
            $offset     = ($page - 1) * $perPage;

            $stmt = $db->prepare("
                SELECT event_time, raw_data
                FROM device_events
                WHERE imei = :imei AND event_time BETWEEN :df AND :dt
                ORDER BY event_time DESC
                LIMIT :lim OFFSET :off
            ");
            $stmt->bindValue(':imei', $imei);
            $stmt->bindValue(':df', $utcFrom);
            $stmt->bindValue(':dt', $utcTo);
            $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decodifica aqui (não no SQL) — raw_data é JSON válido, content
            // dentro dele não é (ver includes/gps_extras.php).
            foreach ($rows as &$r) {
                $payload = json_decode($r['raw_data'], true) ?: [];
                $extId   = (int)($payload['extensionId'] ?? 0);
                $content = $payload['content'] ?? null;
                $r['extension_id'] = $extId;
                $r['decoded'] = $extId > 0
                    ? extension_content_render($extId, parse_extension_content(is_string($content) ? $content : null))
                    : [];
            }
            unset($r);
        }
    } catch (PDOException $e) {
        Logger::warning('dados_estendidos: consulta falhou', ['erro' => $e->getMessage(), 'tab' => $tab]);
    }
}

$page_title    = 'Dados Estendidos';
$current_route = 'dados-estendidos';
require_once __DIR__ . '/../web/layout_base.php';

$qsBase = $_GET;
unset($qsBase['tab'], $qsBase['page']);
?>

<div class="mb-16" style="display:flex;gap:4px;border-bottom:1px solid var(--hairline);">
    <a href="?<?= htmlspecialchars(http_build_query(['tab' => 'gps'] + $qsBase)) ?>"
       style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:<?= $tab === 'gps' ? 'var(--primary)' : 'var(--muted)' ?>;
       border-bottom:2px solid <?= $tab === 'gps' ? 'var(--primary)' : 'transparent' ?>;margin-bottom:-1px;">
       Transmissão GPS
    </a>
    <a href="?<?= htmlspecialchars(http_build_query(['tab' => 'extensao'] + $qsBase)) ?>"
       style="padding:8px 12px;font-size:13px;font-weight:600;text-decoration:none;
       color:<?= $tab === 'extensao' ? 'var(--primary)' : 'var(--muted)' ?>;
       border-bottom:2px solid <?= $tab === 'extensao' ? 'var(--primary)' : 'transparent' ?>;margin-bottom:-1px;">
       Extensão do Terminal
    </a>
</div>

<div class="card mb-16">
    <form method="get" class="form-row" style="flex-wrap:wrap;align-items:flex-end;gap:12px;">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
        <?php if ($isAdmin && $customers): ?>
        <div class="form-group" style="margin:0;">
            <label>Cliente</label>
            <select name="customer_id">
                <option value="">Todos os clientes</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group" style="margin:0;">
            <label>Equipamento</label>
            <select name="imei" required>
                <option value="">Selecione…</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= htmlspecialchars($d['imei']) ?>" <?= $imei === $d['imei'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['device_name'] ?: $d['imei']) ?> (<?= htmlspecialchars($d['imei']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label>De</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
        </div>
        <div class="form-group" style="margin:0;">
            <label>Até</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
        </div>
        <button type="submit" class="btn btn-primary">Filtrar</button>
    </form>
    <?php if (!empty($rangeClamped)): ?>
        <div style="font-size:12px;color:var(--warning-text);margin-top:8px;">Período ajustado — teto de <?= REPORT_RANGE_MAX_DAYS ?> dias por consulta.</div>
    <?php endif; ?>
</div>

<?php if ($imei === ''): ?>
<div class="card">
    <p style="color:var(--muted);font-size:13px;text-align:center;padding:24px;">Selecione um equipamento para ver os dados.</p>
</div>
<?php elseif ($tab === 'gps'): ?>
<div class="card">
    <div class="flex-between mb-16">
        <h2 style="font-size:16px;font-weight:600;color:var(--ink);">Transmissão GPS</h2>
        <span style="font-size:12px;color:var(--muted);"><?= $totalRows ?> registro(s)</span>
    </div>
    <p style="font-size:12px;color:var(--muted);margin-top:-8px;margin-bottom:16px;">
        <strong>Modo</strong> e <strong>Posicionamento</strong> têm tabela oficial do fabricante.
        <strong>Post Method</strong> não tem — o fabricante nunca publicou o significado dos
        valores, só exemplos de payload; mostrado aqui do jeito que chegou, sem legenda inventada.
    </p>
    <div style="overflow:auto;">
    <table class="table">
        <thead><tr><th>Data/Hora</th><th>Modo</th><th>Posicionamento</th><th>Post Method</th><th>Velocidade</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td style="font-size:12px;white-space:nowrap;"><?= fmt_brt($r['gps_time']) ?></td>
                <td style="font-size:12px;">
                    <span class="badge badge-<?= (int)$r['gps_mode'] === 1 ? 'warning' : 'success' ?>"><?= gps_mode_label($r['gps_mode']) ?></span>
                </td>
                <td style="font-size:12px;"><span class="badge badge-info"><?= post_type_label($r['post_type']) ?></span></td>
                <td class="text-mono" style="font-size:12px;" title="Sem tabela oficial de valores">
                    <?= $r['post_method'] === null ? '—' : htmlspecialchars((string)$r['post_method']) ?>
                </td>
                <td class="text-mono" style="font-size:12px;"><?= $r['speed'] === null ? '—' : (int)$r['speed'] . ' km/h' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px;">Nenhuma posição no período.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="flex-between mb-16">
        <h2 style="font-size:16px;font-weight:600;color:var(--ink);">Extensão do Terminal</h2>
        <span style="font-size:12px;color:var(--muted);"><?= $totalRows ?> registro(s)</span>
    </div>
    <p style="font-size:12px;color:var(--muted);margin-top:-8px;margin-bottom:16px;">
        Dado enviado pelo equipamento via §1.15 Push Extension Data
        (<code>/pushTerminalTransInfo</code>) — status do terminal (tensão, bateria, rede…) ou
        leitor serial. ICCID e o conteúdo do leitor serial não são decodificados nesta versão;
        aparecem truncados, sem legenda inventada.
    </p>
    <div style="overflow:auto;">
    <table class="table">
        <thead><tr><th>Data/Hora</th><th>Tipo</th><th>Dados</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td style="font-size:12px;white-space:nowrap;"><?= fmt_brt($r['event_time']) ?></td>
                <td style="font-size:12px;"><span class="badge badge-info"><?= htmlspecialchars(extension_id_label($r['extension_id'])) ?></span></td>
                <td style="font-size:12px;">
                    <?php if ($r['decoded']): ?>
                        <?php foreach ($r['decoded'] as $par): ?>
                            <div><strong><?= htmlspecialchars($par['label']) ?>:</strong> <?= htmlspecialchars($par['value']) ?></div>
                        <?php endforeach; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="3" style="text-align:center;color:var(--muted);padding:24px;">Nenhum evento de extensão no período.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<?php if ($imei !== '' && $totalPages > 1): ?>
<div class="mt-16" style="display:flex;gap:8px;align-items:center;justify-content:center;font-size:12px;">
    <?php
    $qs = $_GET;
    for ($p = 1; $p <= $totalPages; $p++):
        $qs['page'] = $p;
        $active = $p === $page;
    ?>
        <a href="?<?= htmlspecialchars(http_build_query($qs)) ?>"
           style="padding:4px 8px;<?= $active ? 'font-weight:600;color:var(--primary);' : 'color:var(--muted);' ?>">
           <?= $p ?>
        </a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
