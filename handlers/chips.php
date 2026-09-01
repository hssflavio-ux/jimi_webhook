<?php
/**
 * JIMI Webhook System — Gestão de Chips SIM v4.0.0
 * Endpoint: /chips
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$db = Database::getInstance()->getConnection();
$user = get_jimi_user();
$customer_id = get_customer_id();
$is_admin = ($user['role'] ?? '') === 'admin';
$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action  = $_POST['action'] ?? '';
    $id      = (int)($_POST['id'] ?? 0);
    // RBAC ação fina (v4.2.0 — Fase B2)
    require_permission('chips', $action === 'delete' ? 'delete' : ($id > 0 ? 'edit' : 'create'));

    if ($action === 'delete' && $id > 0) {
        // v4.15.0 — o "antes" é lido IMEDIATAMENTE antes do DELETE: depois a
        // linha não existe mais para consultar.
        $beforeDel = $db->prepare("SELECT carrier, msisdn, iccid, imei, customer_id, is_active FROM sim_cards WHERE id = ?");
        $beforeDel->execute([$id]);
        $beforeRow = $beforeDel->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("DELETE FROM sim_cards WHERE id = ?" . ($is_admin ? '' : ' AND customer_id = ?'));
        $params = [$id];
        if (!$is_admin) $params[] = $customer_id;
        $stmt->execute($params);
        // rowCount()>0 evita logar um DELETE "fantasma" quando o `AND
        // customer_id=?` de escopo bloqueou silenciosamente a exclusão de um
        // chip de outro cliente.
        if ($beforeRow && $stmt->rowCount() > 0) {
            audit_log('sim_card.delete', 'sim_card', $id, $beforeRow, null);
        }
        $success = 'Chip removido.';
    } elseif ($action === 'save') {
        $carrier = trim($_POST['carrier'] ?? '');
        $msisdn  = trim($_POST['msisdn'] ?? '');
        $iccid   = trim($_POST['iccid'] ?? '');
        $active  = isset($_POST['is_active']) ? 1 : 0;

        // `sim_cards.customer_id` é nullable: sessão sem cliente gravava chip órfão
        // com "criado com sucesso" — mesmo defeito do cadastro de equipamento.
        $owner_id = resolve_owner_customer_id($_POST['customer_id'] ?? null, $is_admin, $customer_id);

        // O vínculo chip↔câmera só se mexe pelo cadastro da CÂMERA
        // (/equipamentos, `link_sim_card_to_device()`) — nunca daqui. Por
        // isso o `imei` atual vem do BANCO, não de um campo do formulário: o
        // chip não tem mais como propor a própria vinculação.
        $currentImei = null;
        if ($id > 0) {
            $chk = $db->prepare("SELECT imei FROM sim_cards WHERE id = ?");
            $chk->execute([$id]);
            $currentImei = $chk->fetchColumn() ?: null;
        }

        if (empty($carrier) && empty($msisdn) && empty($iccid)) {
            $error = 'Preencha ao menos um campo (Operadora, Número ou ICCID).';
        } elseif ($id === 0 && $owner_id === null) {
            $error = 'Selecione o cliente do chip. Sua sessão está sem cliente definido — salvar assim deixaria o chip sem vínculo e invisível na lista.';
        } elseif ($active === 0 && $currentImei) {
            // Fluxo correto: chip só desativa livre. O operador precisa
            // desvincular primeiro, em /equipamentos, para não perder de
            // vista que a câmera ficou sem chip.
            $error = 'Este chip está vinculado à câmera de IMEI ' . $currentImei . '. Desvincule em /equipamentos (edite a câmera e selecione "Nenhum" em Chip) antes de desativar.';
        } else {
            try {
                if ($id > 0) {
                    $beforeUpd = $db->prepare("SELECT carrier, msisdn, iccid, is_active FROM sim_cards WHERE id = ?");
                    $beforeUpd->execute([$id]);
                    $beforeRow = $beforeUpd->fetch(PDO::FETCH_ASSOC);

                    // `imei` NUNCA entra no SET — preserva o vínculo como está.
                    $sql = "UPDATE sim_cards SET carrier=?, msisdn=?, iccid=?, is_active=? WHERE id=?" . ($is_admin ? '' : ' AND customer_id=?');
                    $params = [$carrier, $msisdn, $iccid, $active, $id];
                    if (!$is_admin) $params[] = $customer_id;
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    if ($beforeRow && $stmt->rowCount() > 0) {
                        audit_log('sim_card.update', 'sim_card', $id, $beforeRow,
                            ['carrier' => $carrier, 'msisdn' => $msisdn, 'iccid' => $iccid, 'is_active' => $active]);
                    }
                    $success = 'Chip atualizado.';
                } else {
                    // Chip novo nasce sempre livre — vincular é ação do
                    // cadastro de equipamento, nunca deste formulário.
                    $stmt = $db->prepare("INSERT INTO sim_cards (customer_id, carrier, msisdn, iccid, is_active) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$owner_id, $carrier, $msisdn, $iccid, $active]);
                    audit_log('sim_card.create', 'sim_card', (int)$db->lastInsertId(), null,
                        ['customer_id' => $owner_id, 'carrier' => $carrier, 'msisdn' => $msisdn, 'iccid' => $iccid, 'is_active' => $active]);
                    $success = 'Chip criado com sucesso.';
                }
            } catch (PDOException $e) {
                $error = 'Erro ao salvar chip: ' . $e->getMessage();
            }
        }
    }
}

// Fase C (padrão CRUD YUV §9.1): busca + paginação + export
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$params = [];
$where = $is_admin ? '1=1' : "s.customer_id = :cid";
if (!$is_admin) $params[':cid'] = $customer_id;
if ($q !== '') {
    $where .= " AND (s.carrier LIKE :q1 OR s.msisdn LIKE :q2 OR s.iccid LIKE :q3 OR s.imei LIKE :q4)";
    foreach (['q1', 'q2', 'q3', 'q4'] as $k) $params[":$k"] = "%$q%";
}

// Export síncrono
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('chips', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expRows = [];
    try {
        $expStmt = $db->prepare("
            SELECT s.*, d.device_name FROM sim_cards s
            LEFT JOIN devices d ON d.imei = s.imei
            WHERE $where ORDER BY s.created_at DESC
            LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($params);
        while ($c = $expStmt->fetch(PDO::FETCH_ASSOC)) {
            $expRows[] = [
                $c['carrier'] ?? '—', $c['msisdn'] ?? '—', $c['iccid'] ?? '—',
                $c['imei'] ?? '—', $c['device_name'] ?? '—',
                $c['is_active'] ? 'Ativo' : 'Inativo',
            ];
        }
    } catch (Exception $e) {}
    stream_export($export, 'chips',
        ['Operadora', 'Número (MSISDN)', 'ICCID', 'IMEI', 'Equipamento', 'Status'],
        $expRows, 'Chips (SIM)');
}

$sim_cards = [];
$totalRows = 0;
try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM sim_cards s WHERE $where");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $simCardsStmt = $db->prepare("
        SELECT s.*, d.device_name
        FROM sim_cards s
        LEFT JOIN devices d ON d.imei = s.imei
        WHERE $where
        ORDER BY s.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $simCardsStmt->execute($params);
    $sim_cards = $simCardsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$editChip = null;
if (!empty($_GET['edit'])) {
    // Vínculo é só de LEITURA aqui — quem muda é /equipamentos. O join
    // traz o nome da câmera só pra exibir, nunca pra reoferecer escolha.
    $stmt = $db->prepare("
        SELECT s.*, d.device_name
        FROM sim_cards s
        LEFT JOIN devices d ON d.imei = s.imei
        WHERE s.id = ?
    ");
    $stmt->execute([(int)$_GET['edit']]);
    $editChip = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page_title    = 'Chips';
$current_route = 'chips';
include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($error): ?>
<div class="card mb-16" style="border-color:#fce4eb;background:#fef2f5;color:var(--error);font-size:13px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php $expQ = $_GET; unset($expQ['page'], $expQ['export']); $expBase = http_build_query($expQ); ?>
<div style="display:grid;grid-template-columns:1fr 380px;gap:16px">
    <div>
    <div class="flex-between mb-12" style="gap:8px;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:6px;">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Pesquisar operadora, número, ICCID, IMEI..."
                   style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:280px;">
            <button type="submit" class="btn btn-outline btn-sm">Pesquisar</button>
        </form>
        <div style="display:flex;gap:6px;">
            <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
            <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Operadora</th><th>Número (MSISDN)</th><th>ICCID</th><th>IMEI (vinculado)</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($sim_cards as $c): ?>
                <tr>
                    <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($c['carrier'] ?? '-') ?></td>
                    <td class="text-mono"><?= htmlspecialchars($c['msisdn'] ?? '-') ?></td>
                    <td class="text-mono"><?= htmlspecialchars($c['iccid'] ?? '-') ?></td>
                    <td class="text-mono">
                        <?php if (!empty($c['imei'])): ?>
                        <a href="/ativos/<?= urlencode($c['imei']) ?>" style="color:var(--primary);text-decoration:none"><?= htmlspecialchars($c['imei']) ?></a>
                        <span style="font-size:11px;color:var(--muted);display:block"><?= htmlspecialchars($c['device_name'] ?? '') ?></span>
                        <?php else: ?>
                        -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['is_active']): ?><span class="badge badge-success">Ativo</span>
                        <?php else: ?><span class="badge badge-error">Inativo</span><?php endif; ?>
                    </td>
                    <td>
                        <a href="?edit=<?= $c['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Remover este chip?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button class="btn btn-outline btn-sm" style="color:var(--error)">Remover</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sim_cards)): ?>
                <tr><td colspan="6"><div class="empty-state"><h3>Nenhum chip</h3><p><?= $q !== '' ? 'Nenhum resultado para a busca.' : 'Cadastre um chip SIM para começar.' ?></p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="flex-between mt-12" style="font-size:13px;color:var(--muted);">
        <span>Página <?= $page ?> de <?= $totalPages ?> (<?= $totalRows ?> chips)</span>
        <div style="display:flex;gap:4px;">
            <?php if ($page > 1): ?><a href="?<?= $expBase ?>&page=<?= $page-1 ?>" class="btn btn-outline btn-sm">&laquo;</a><?php endif; ?>
            <?php if ($page < $totalPages): ?><a href="?<?= $expBase ?>&page=<?= $page+1 ?>" class="btn btn-outline btn-sm">&raquo;</a><?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    </div>

    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">
            <?= $editChip ? 'Editar Chip' : 'Novo Chip' ?>
        </h4>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editChip): ?><input type="hidden" name="id" value="<?= $editChip['id'] ?>"><?php endif; ?>
            <div class="form-group">
                <label>Operadora</label>
                <input type="text" name="carrier" value="<?= htmlspecialchars($editChip['carrier'] ?? '') ?>" placeholder="Vivo, Claro, TIM...">
            </div>
            <div class="form-group">
                <label>Número (MSISDN)</label>
                <input type="text" name="msisdn" value="<?= htmlspecialchars($editChip['msisdn'] ?? '') ?>" placeholder="5511999999999">
            </div>
            <div class="form-group">
                <label>ICCID</label>
                <input type="text" name="iccid" value="<?= htmlspecialchars($editChip['iccid'] ?? '') ?>" placeholder="8955...">
            </div>
            <?php if ($editChip): ?>
            <div class="form-group">
                <label>Câmera vinculada</label>
                <?php if (!empty($editChip['imei'])): ?>
                <input type="text" readonly style="background:var(--canvas-soft)"
                       value="<?= htmlspecialchars($editChip['imei']) ?><?= $editChip['device_name'] ? ' — ' . htmlspecialchars($editChip['device_name']) : '' ?>">
                <small style="display:block;margin-top:4px;font-size:11px;color:var(--muted);line-height:1.45">
                    Só de leitura aqui. Para trocar ou desvincular, edite a câmera em
                    <a href="/equipamentos?action=editar&imei=<?= urlencode($editChip['imei']) ?>">Equipamentos</a>.
                </small>
                <?php else: ?>
                <input type="text" readonly style="background:var(--canvas-soft)" value="Nenhuma — chip livre">
                <small style="display:block;margin-top:4px;font-size:11px;color:var(--muted);line-height:1.45">
                    Para vincular, escolha este chip ao cadastrar ou editar uma câmera em
                    <a href="/equipamentos">Equipamentos</a>.
                </small>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="form-group" style="display:flex;align-items:center;gap:8px">
                <input type="checkbox" name="is_active" id="chip-active" value="1" <?= (!isset($editChip) || ($editChip['is_active'] ?? 1)) ? 'checked' : '' ?> style="width:auto">
                <label for="chip-active" style="margin:0;text-transform:none;font-size:14px;cursor:pointer">Ativo</label>
            </div>
            <div class="flex-between mt-16">
                <?php if ($editChip): ?>
                <a href="?" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editChip ? 'Salvar' : 'Criar Chip' ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
