<?php
/**
 * JIMI Webhook System — Checklist de Inspeção v4.0.0
 * Rota: /checklist (fase futura)
 *
 * CRUD de checklists de inspeção veicular.
 * Grade: nome, cliente, itens, status.
 * Form: nome + itens dinâmicos (pergunta, tipo, obrigatório).
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$message = '';
$messageType = '';

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $cfgId = !empty($_POST['config_id']) ? (int)$_POST['config_id'] : null;
    $name  = trim($_POST['name'] ?? '');
    $cust  = !empty($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;

    if (empty($name)) {
        $message = 'Nome do checklist é obrigatório.';
        $messageType = 'error';
    } else {
        try {
            $db->beginTransaction();
            if ($cfgId) {
                $stmt = $db->prepare("UPDATE checklist_configs SET name=?, customer_id=? WHERE id=?");
                $stmt->execute([$name, $cust, $cfgId]);
            } else {
                $stmt = $db->prepare("INSERT INTO checklist_configs (name, customer_id) VALUES (?, ?)");
                $stmt->execute([$name, $cust]);
                $cfgId = (int)$db->lastInsertId();
            }

            $stmt = $db->prepare("DELETE FROM checklist_items WHERE config_id = ?");
            $stmt->execute([$cfgId]);

            $questions = $_POST['item_question'] ?? [];
            $types     = $_POST['item_type'] ?? [];
            $required  = $_POST['item_required'] ?? [];

            $ins = $db->prepare("INSERT INTO checklist_items (config_id, question, sort_order, value_type, is_required) VALUES (?, ?, ?, ?, ?)");
            foreach ($questions as $i => $q) {
                $q = trim($q);
                if ($q === '') continue;
                $ins->execute([
                    $cfgId, $q, $i + 1,
                    $types[$i] ?? 'boolean',
                    !empty($required[$i]) ? 1 : 0,
                ]);
            }

            $db->commit();
            $message = 'Checklist salvo.';
            $messageType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $message = 'Erro: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// ── Delete ─────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'excluir' && !empty($_GET['id'])) {
    $stmt = $db->prepare("DELETE FROM checklist_items WHERE config_id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $stmt = $db->prepare("DELETE FROM checklist_configs WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $message = 'Checklist excluído.';
    $messageType = 'success';
}

// ── List ──────────────────────────────────────────────────────
$configs = [];
try {
    $configs = $db->query("
        SELECT cc.*, COALESCE(c.name, 'Global') as customer_name,
               (SELECT COUNT(*) FROM checklist_items WHERE config_id = cc.id) as item_count
        FROM checklist_configs cc
        LEFT JOIN customers c ON c.id = cc.customer_id
        WHERE cc.is_active = 1
        ORDER BY cc.name
    ")->fetchAll();
} catch (Exception $e) {}

$customers = $db->query("SELECT id, name FROM customers WHERE is_active=1 ORDER BY name")->fetchAll();

// Edit mode
$editCfg = null;
$editItems = [];
if (($_GET['action'] ?? '') === 'editar' && !empty($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM checklist_configs WHERE id = ?");
    $stmt->execute([(int)$_GET['id']]);
    $editCfg = $stmt->fetch();
    if ($editCfg) {
        $stmt = $db->prepare("SELECT * FROM checklist_items WHERE config_id = ? ORDER BY sort_order");
        $stmt->execute([$editCfg['id']]);
        $editItems = $stmt->fetchAll();
    }
}

$isForm = in_array($_GET['action'] ?? '', ['novo', 'editar']);

$page_title = 'Checklist';
$current_route = 'checklist';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($message): ?>
<div class="toast toast-<?= $messageType ?> toast-show" style="position:fixed;bottom:24px;right:24px;z-index:9999;">
    <?= htmlspecialchars($message) ?>
</div>
<script>setTimeout(function(){var t=document.querySelector('.toast');if(t)t.style.display='none';},4000);</script>
<?php endif; ?>

<?php if ($isForm): ?>
<div class="card" style="max-width:800px;">
    <div class="flex-between mb-24">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);"><?= $editCfg ? 'Editar' : 'Novo' ?> Checklist</h2>
        <a href="/checklist" class="btn btn-outline btn-sm">Voltar</a>
    </div>
    <form method="POST">
        <?= csrf_field() ?>
        <?php if ($editCfg): ?><input type="hidden" name="config_id" value="<?= $editCfg['id'] ?>"><?php endif; ?>
        <div class="form-row">
            <div class="form-group"><label>Nome *</label><input type="text" name="name" required value="<?= htmlspecialchars($editCfg['name'] ?? '') ?>"></div>
            <div class="form-group"><label>Cliente</label>
                <select name="customer_id">
                    <option value="">— Global (template) —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($editCfg['customer_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <h3 style="font-size:14px;font-weight:600;color:var(--ink);margin:20px 0 10px;">Itens do Checklist</h3>
        <div id="items-container">
            <?php
            $rows = $editItems ?: [['question'=>'','value_type'=>'boolean','is_required'=>1]];
            foreach ($rows as $it):
            ?>
            <div class="item-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
                <input type="text" name="item_question[]" value="<?= htmlspecialchars($it['question'] ?? '') ?>"
                       placeholder="Pergunta..." style="flex:1;min-width:200px;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <select name="item_type[]" style="padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <option value="boolean" <?= ($it['value_type']??'')==='boolean'?'selected':'' ?>>Sim/Não</option>
                    <option value="text" <?= ($it['value_type']??'')==='text'?'selected':'' ?>>Texto</option>
                    <option value="photo" <?= ($it['value_type']??'')==='photo'?'selected':'' ?>>Foto</option>
                    <option value="number" <?= ($it['value_type']??'')==='number'?'selected':'' ?>>Número</option>
                </select>
                <label style="font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap;">
                    <input type="checkbox" name="item_required[]" value="1" <?= !empty($it['is_required'])?'checked':'' ?> style="width:auto;"> Obrigatório
                </label>
                <button type="button" onclick="removeItem(this)" title="Remover" style="padding:6px 10px;font-size:16px;line-height:1;border:1px solid var(--hairline);border-radius:var(--radius-sm);background:var(--surface);color:var(--error);cursor:pointer;">&times;</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" onclick="addItem()" class="btn btn-outline btn-sm mt-16">+ Adicionar Item</button>
        <div class="mt-24"><button type="submit" class="btn btn-primary">Salvar Checklist</button></div>
    </form>
</div>
<?php else: ?>
<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Checklists de Inspeção</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">Inspeções veiculares com perguntas configuráveis.</p>
    </div>
    <a href="?action=novo" class="btn btn-primary btn-sm">+ Novo Checklist</a>
    <a href="/checklist/inspecao" class="btn btn-outline btn-sm" style="color:var(--primary);">Preencher Inspeção</a>
</div>
<div class="table-wrap">
    <table>
        <thead><tr><th>Nome</th><th>Cliente</th><th>Itens</th><th style="text-align:center;">Ações</th></tr></thead>
        <tbody>
            <?php if (empty($configs)): ?>
            <tr><td colspan="4" style="text-align:center;padding:32px;color:var(--muted);">Nenhum checklist.</td></tr>
            <?php else: foreach ($configs as $c): ?>
            <tr>
                <td style="font-weight:500;"><?= htmlspecialchars($c['name']) ?></td>
                <td><?= htmlspecialchars($c['customer_name']) ?></td>
                <td><?= $c['item_count'] ?></td>
                <td style="text-align:center;">
                    <a href="?action=editar&id=<?= $c['id'] ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">Editar</a>
                    <a href="?action=excluir&id=<?= $c['id'] ?>" onclick="return confirm('Excluir?')" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;color:var(--error);">Excluir</a>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
function addItem() {
    var row = document.querySelector('#items-container .item-row:last-child');
    if (!row) return;
    var clone = row.cloneNode(true);
    clone.querySelector('input[type="text"]').value = '';
    var checks = clone.querySelectorAll('input[type="checkbox"]');
    checks.forEach(function(c) { c.checked = true; });
    document.getElementById('items-container').appendChild(clone);
}
function removeItem(btn) {
    var rows = document.querySelectorAll('#items-container .item-row');
    if (rows.length <= 1) {
        rows[0].querySelector('input[type="text"]').value = '';
        return;
    }
    btn.closest('.item-row').remove();
}
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
