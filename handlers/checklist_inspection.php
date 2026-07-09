<?php
/**
 * JIMI Webhook System — Checklist Inspection v4.0.0
 * Rota: /checklist/inspecao
 *
 * Preenchimento de checklist de inspeção veicular.
 * Seleciona checklist, dispositivo e preenche os itens configurados.
 * Armazena respostas em checklist_responses (JSON).
 * Histórico à direita.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();

$message = '';
$messageType = '';
$selectedCfgId = (int)($_GET['config_id'] ?? 0);
$selectedImei  = $_GET['imei'] ?? '';

// ── POST: Submit inspection ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['config_id'])) {
    csrf_verify();
    $cfgId  = (int)$_POST['config_id'];
    $imei   = $_POST['imei'] ?? '';
    $driverId = !empty($_POST['driver_id']) ? (int)$_POST['driver_id'] : null;
    $notes  = trim($_POST['notes'] ?? '');
    $answers = $_POST['answers'] ?? [];

    if ($cfgId && $imei) {
        try {
            $stmt = $db->prepare("INSERT INTO checklist_responses (config_id, device_imei, driver_id, answers, notes) VALUES (:cfg, :imei, :did, :ans, :notes)");
            $stmt->execute([
                ':cfg'   => $cfgId,
                ':imei'  => $imei,
                ':did'   => $driverId,
                ':ans'   => json_encode($answers, JSON_UNESCAPED_UNICODE),
                ':notes' => $notes ?: null,
            ]);
            $message = 'Inspeção registrada com sucesso.';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = 'Erro: ' . $e->getMessage();
            $messageType = 'error';
        }
    } else {
        $message = 'Selecione um checklist e um dispositivo.';
        $messageType = 'error';
    }
}

// ── Available checklists ───────────────────────────────────
$checklists = [];
try {
    $cfgStmt = $db->prepare("
        SELECT cc.*, (SELECT COUNT(*) FROM checklist_items WHERE config_id = cc.id) as item_count
        FROM checklist_configs cc
        WHERE cc.is_active = 1 AND (cc.customer_id IS NULL OR cc.customer_id = :cid)
        ORDER BY cc.name
    ");
    $cfgStmt->execute([':cid' => $customerId]);
    $checklists = $cfgStmt->fetchAll();
} catch (Exception $e) {}

// ── Devices ───────────────────────────────────────────────
$devStmt = $db->prepare("SELECT imei, COALESCE(device_name, imei) as label FROM devices WHERE customer_id = :cid AND is_active = 1 ORDER BY label");
$devStmt->execute([':cid' => $customerId ?? 1]);
$devices = $devStmt->fetchAll();

// ── Drivers ──────────────────────────────────────────────
$drivers = [];
try {
    $drvStmt = $db->prepare("SELECT id, name FROM drivers WHERE customer_id = :cid AND is_active = 1 ORDER BY name");
    $drvStmt->execute([':cid' => $customerId ?? 1]);
    $drivers = $drvStmt->fetchAll();
} catch (Exception $e) {}

// ── Load selected checklist items ─────────────────────────
$items = [];
if ($selectedCfgId) {
    try {
        $itemStmt = $db->prepare("SELECT * FROM checklist_items WHERE config_id = :cid ORDER BY sort_order");
        $itemStmt->execute([':cid' => $selectedCfgId]);
        $items = $itemStmt->fetchAll();
    } catch (Exception $e) {}
}

// ── History ──────────────────────────────────────────────
$history = [];
try {
    $histStmt = $db->prepare("
        SELECT cr.*, cc.name as checklist_name
        FROM checklist_responses cr
        JOIN checklist_configs cc ON cc.id = cr.config_id
        WHERE cc.customer_id = :cid OR cc.customer_id IS NULL
        ORDER BY cr.inspected_at DESC LIMIT 20
    ");
    $histStmt->execute([':cid' => $customerId]);
    $history = $histStmt->fetchAll();
} catch (Exception $e) {}

$page_title = 'Inspeção Veicular';
$current_route = 'checklist';
$extra_head = '<style>
.inspection-form{display:flex;gap:20px;}
.inspection-left{flex:1;min-width:0;}
.inspection-right{width:340px;flex-shrink:0;}
.inspection-item{padding:12px;margin-bottom:8px;border:1px solid var(--hairline);border-radius:var(--radius-sm);background:var(--surface);}
.inspection-item .item-question{font-size:13px;font-weight:500;color:var(--ink);margin-bottom:6px;}
.inspection-item .item-required{color:var(--error);margin-left:4px;}
@media(max-width:768px){.inspection-form{flex-direction:column;}.inspection-right{width:100%;}}
</style>';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($message): ?>
<div class="toast toast-<?= $messageType ?> toast-show" style="position:fixed;bottom:24px;right:24px;z-index:9999;">
    <?= htmlspecialchars($message) ?>
</div>
<script>setTimeout(function(){var t=document.querySelector('.toast');if(t)t.style.display='none';},4000);</script>
<?php endif; ?>

<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Inspeção Veicular</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">Preencha o checklist de inspeção para um dispositivo</p>
    </div>
    <a href="/checklist" class="btn btn-outline btn-sm">Gerenciar Checklists</a>
</div>

<div class="inspection-form mb-24">
    <!-- ═══════ LEFT: Selection + Form ═══════ -->
    <div class="inspection-left">
        <form method="GET" class="card" style="padding:16px;margin-bottom:16px;">
            <input type="hidden" name="action" value="">
            <div style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:10px;">
                <div>
                    <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Checklist</label>
                    <select name="config_id" onchange="this.form.submit()" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:180px;">
                        <option value="">— Selecione —</option>
                        <?php foreach ($checklists as $cl): ?>
                        <option value="<?= $cl['id'] ?>" <?= $selectedCfgId==$cl['id']?'selected':'' ?>><?= htmlspecialchars($cl['name']) ?> (<?= $cl['item_count'] ?> itens)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Dispositivo</label>
                    <select name="imei" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:180px;">
                        <option value="">— Selecione —</option>
                        <?php foreach ($devices as $d): ?>
                        <option value="<?= $d['imei'] ?>" <?= $selectedImei==$d['imei']?'selected':'' ?>><?= htmlspecialchars($d['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-outline btn-sm">Carregar</button>
            </div>
        </form>

        <?php if ($selectedCfgId && $selectedImei && !empty($items)): ?>
        <form method="POST" class="card" style="padding:16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="config_id" value="<?= $selectedCfgId ?>">
            <input type="hidden" name="imei" value="<?= htmlspecialchars($selectedImei) ?>">

            <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:4px;">Itens da Inspeção</h4>
            <p class="text-muted" style="font-size:11px;margin-bottom:12px;">
                Dispositivo: <span class="text-mono"><?= htmlspecialchars($selectedImei) ?></span>
            </p>

            <?php foreach ($items as $item): ?>
            <div class="inspection-item">
                <div class="item-question">
                    <?= ($item['sort_order'] ?? '') ?: '' ?>. <?= htmlspecialchars($item['question']) ?>
                    <?php if ($item['is_required']): ?><span class="item-required">*</span><?php endif; ?>
                </div>
                <?php
                $name = 'answers[' . $item['id'] . ']';
                $req = $item['is_required'] ? 'required' : '';
                switch ($item['value_type']):
                    case 'boolean': ?>
                    <label style="display:flex;gap:20px;font-size:13px;">
                        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
                            <input type="radio" name="<?= $name ?>" value="1" <?= $req ?> style="width:auto;"> Sim
                        </label>
                        <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
                            <input type="radio" name="<?= $name ?>" value="0" <?= $req ?> style="width:auto;"> Não
                        </label>
                    </label>
                    <?php break;
                    case 'text': ?>
                    <input type="text" name="<?= $name ?>" <?= $req ?> placeholder="Resposta..." style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <?php break;
                    case 'photo': ?>
                    <input type="file" name="<?= $name ?>" accept="image/*" <?= $req ?> style="font-size:13px;">
                    <span style="font-size:10px;color:var(--muted);">Upload de foto (será implementado em versão futura)</span>
                    <?php break;
                    case 'number': ?>
                    <input type="number" name="<?= $name ?>" <?= $req ?> placeholder="Valor numérico..." style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:200px;">
                    <?php break;
                endswitch; ?>
            </div>
            <?php endforeach; ?>

            <div class="form-group" style="margin-top:12px;">
                <label style="font-size:12px;color:var(--muted);">Motorista</label>
                <select name="driver_id" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <option value="">— Não atribuído —</option>
                    <?php foreach ($drivers as $dr): ?>
                    <option value="<?= $dr['id'] ?>"><?= htmlspecialchars($dr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label style="font-size:12px;color:var(--muted);">Observações</label>
                <textarea name="notes" rows="2" placeholder="Anotações da inspeção..." style="width:100%;"></textarea>
            </div>

            <button type="submit" class="btn btn-primary">Registrar Inspeção</button>
        </form>
        <?php elseif ($selectedCfgId && $selectedImei && empty($items)): ?>
        <div class="card" style="padding:32px;text-align:center;">
            <p class="text-muted">Este checklist não possui itens configurados.</p>
            <a href="/checklist?action=editar&id=<?= $selectedCfgId ?>" class="btn btn-outline btn-sm" style="margin-top:8px;">Adicionar Itens</a>
        </div>
        <?php elseif (!$selectedCfgId || !$selectedImei): ?>
        <div class="card" style="padding:40px;text-align:center;">
            <h4 style="font-size:16px;color:var(--muted);">Selecione um checklist e um dispositivo acima</h4>
            <p style="font-size:13px;color:var(--muted-soft);margin-top:4px;">Os itens do checklist serão exibidos para preenchimento.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══════ RIGHT: History ═══════ -->
    <div class="inspection-right">
        <div class="card" style="padding:16px;">
            <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px;">Inspeções Recentes</h4>
            <?php if (empty($history)): ?>
            <p class="text-muted" style="font-size:12px;">Nenhuma inspeção registrada.</p>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php foreach ($history as $h):
                    $answers = json_decode($h['answers'] ?? '{}', true);
                    $total = count($answers);
                    $passed = count(array_filter($answers, fn($v) => $v == '1'));
                ?>
                <div style="padding:10px;border:1px solid var(--hairline-soft);border-radius:var(--radius-sm);font-size:12px;">
                    <div style="font-weight:500;color:var(--ink);"><?= htmlspecialchars($h['checklist_name']) ?></div>
                    <div style="color:var(--muted);margin-top:2px;">
                        <span class="text-mono"><?= htmlspecialchars($h['device_imei']) ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-top:4px;">
                        <span style="color:var(--success);"><?= $passed ?>/<?= $total ?> OK</span>
                        <span style="color:var(--muted-soft);"><?= fmt_brt($h['inspected_at']) ?></span>
                    </div>
                    <?php if ($h['notes']): ?>
                    <div style="margin-top:4px;font-size:11px;color:var(--muted-soft);"><?= htmlspecialchars(mb_strimwidth($h['notes'], 0, 80, '…')) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
