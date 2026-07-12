<?php
/**
 * JIMI Webhook System — Configurações de Ocorrências v4.0.0
 * Rota: /config-ocorrencias
 *
 * CRUD para perfis de configuração de ocorrência (occurrence_configs)
 * com parâmetros dinâmicos por tipo de alarme.
 *
 * GET sem action: lista os perfis
 * GET ?action=novo: formulário de criação
 * GET ?action=editar&id=N: formulário de edição
 * POST: salva/cria perfil + parâmetros
 * GET ?action=excluir&id=N: exclui perfil (soft, se sem clientes vinculados)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$db = Database::getInstance()->getConnection();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin';
$userType = $user['user_type'] ?? 'cliente';

$message = '';
$messageType = '';

// ── POST: Salvar/Criar perfil ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $configId = !empty($_POST['config_id']) ? (int)$_POST['config_id'] : null;
    // RBAC ação fina (v4.2.0 — Fase B2)
    require_permission('config-ocorrencias', $configId ? 'edit' : 'create');
    $name = trim($_POST['name'] ?? '');
    $isDefault = !empty($_POST['is_default']) ? 1 : 0;

    if (empty($name)) {
        $message = 'Nome do perfil é obrigatório.';
        $messageType = 'error';
    } else {
        try {
            $db->beginTransaction();

            if ($configId) {
                $stmt = $db->prepare("UPDATE occurrence_configs SET name = :name, is_default = :def WHERE id = :id");
                $stmt->execute([':name' => $name, ':def' => $isDefault, ':id' => $configId]);
            } else {
                $stmt = $db->prepare("INSERT INTO occurrence_configs (name, is_default) VALUES (:name, :def)");
                $stmt->execute([':name' => $name, ':def' => $isDefault]);
                $configId = (int)$db->lastInsertId();
            }

            if ($isDefault) {
                $stmt = $db->prepare("UPDATE occurrence_configs SET is_default = 0 WHERE id != :id");
                $stmt->execute([':id' => $configId]);
            }

            $stmt = $db->prepare("DELETE FROM occurrence_config_params WHERE config_id = :cid");
            $stmt->execute([':cid' => $configId]);

            $paramTypes = $_POST['param_type'] ?? [];
            $paramGen   = $_POST['param_generates'] ?? [];
            $paramRisk  = $_POST['param_risk'] ?? [];
            $paramThr   = $_POST['param_threshold'] ?? [];

            $ins = $db->prepare(
                "INSERT INTO occurrence_config_params (config_id, alarm_type, generates_occurrence, risk, threshold)
                 VALUES (:cid, :atype, :gen, :risk, :thr)"
            );

            foreach ($paramTypes as $i => $ptype) {
                $ptype = trim($ptype);
                if ($ptype === '') continue;
                $ins->execute([
                    ':cid'   => $configId,
                    ':atype' => $ptype,
                    ':gen'   => !empty($paramGen[$i]) ? 1 : 0,
                    ':risk'  => $paramRisk[$i] ?? 'baixo',
                    ':thr'   => ($paramThr[$i] !== '' && $paramThr[$i] !== null) ? (int)$paramThr[$i] : null,
                ]);
            }

            $db->commit();
            $message = $configId ? 'Perfil salvo com sucesso.' : 'Perfil criado com sucesso.';
            $messageType = 'success';
        } catch (Exception $e) {
            $db->rollBack();
            $message = 'Erro ao salvar: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// ── GET: Excluir ───────────────────────────────────────────────
$action = $_GET['action'] ?? '';
if ($action === 'excluir' && !empty($_GET['id'])) {
    // RBAC ação fina (v4.2.0 — Fase B2)
    require_permission('config-ocorrencias', 'delete');
    $delId = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM customers WHERE occurrence_config_id = :id");
    $stmt->execute([':id' => $delId]);
    $linked = (int)$stmt->fetch()['cnt'];

    if ($linked > 0) {
        $message = "Não é possível excluir: $linked cliente(s) vinculado(s).";
        $messageType = 'error';
    } else {
        $stmt = $db->prepare("DELETE FROM occurrence_config_params WHERE config_id = :id");
        $stmt->execute([':id' => $delId]);
        $stmt = $db->prepare("DELETE FROM occurrence_configs WHERE id = :id");
        $stmt->execute([':id' => $delId]);
        $message = 'Perfil excluído.';
        $messageType = 'success';
    }
}

// ── GET: Editar ────────────────────────────────────────────────
$editConfig = null;
$editParams = [];
if ($action === 'editar' && !empty($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM occurrence_configs WHERE id = :id");
    $stmt->execute([':id' => (int)$_GET['id']]);
    $editConfig = $stmt->fetch();
    if ($editConfig) {
        $stmt = $db->prepare("SELECT * FROM occurrence_config_params WHERE config_id = :cid ORDER BY id");
        $stmt->execute([':cid' => $editConfig['id']]);
        $editParams = $stmt->fetchAll();
    }
}

// ── Lista de perfis ────────────────────────────────────────────
$configs = [];
try {
    $stmt = $db->query(
        "SELECT oc.*,
                (SELECT COUNT(*) FROM customers WHERE occurrence_config_id = oc.id) as customer_count
         FROM occurrence_configs oc
         ORDER BY oc.is_default DESC, oc.name ASC"
    );
    $configs = $stmt->fetchAll();
} catch (Exception $e) {}

// ── Tipos de alarme para dropdown ──────────────────────────────
$stmt = $db->query(
    "SELECT alarm_name_pt, alarm_code, protocol, category
     FROM alarm_types
     WHERE category IN ('DMS','ADAS','Driving','Accident','Security','Emergency')
        OR severity IN ('high','critical')
     ORDER BY category, alarm_name_pt"
);
$alarmTypes = $stmt->fetchAll();

$page_title = 'Configurações de Ocorrências';
$current_route = 'config-ocorrencias';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($message): ?>
<div class="toast toast-<?= $messageType ?> toast-show" style="position:fixed;bottom:24px;right:24px;z-index:9999;">
    <?= htmlspecialchars($message) ?>
</div>
<script>setTimeout(function(){var t=document.querySelector('.toast');if(t)t.style.display='none';},4000);</script>
<?php endif; ?>

<?php if ($action === 'novo' || $action === 'editar'): ?>
<div class="card" style="max-width:900px;">
    <div class="flex-between mb-24">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">
            <?= $editConfig ? 'Editar Perfil' : 'Novo Perfil de Ocorrência' ?>
        </h2>
        <a href="/config-ocorrencias" class="btn btn-outline btn-sm">Voltar</a>
    </div>

    <form method="POST">
        <?= csrf_field() ?>
        <?php if ($editConfig): ?>
        <input type="hidden" name="config_id" value="<?= $editConfig['id'] ?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>Nome do Perfil</label>
                <input type="text" name="name" value="<?= htmlspecialchars($editConfig['name'] ?? '') ?>" required
                       placeholder="Ex: Perfil Rigoroso">
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_default" value="1" <?= ($editConfig['is_default'] ?? 0) ? 'checked' : '' ?>
                           style="width:auto;">
                    Perfil Padrão do Sistema
                </label>
            </div>
        </div>

        <h3 style="font-size:14px;font-weight:600;color:var(--ink);margin:20px 0 12px;">Parâmetros por Tipo de Alarme</h3>

        <div id="params-container">
            <?php
            $rowCount = 0;
            $paramRows = $editParams ?: [['alarm_type'=>'', 'generates_occurrence'=>1, 'risk'=>'baixo', 'threshold'=>10]];
            foreach ($paramRows as $p):
            ?>
            <div class="param-row" style="display:flex;gap:8px;align-items:flex-start;margin-bottom:10px;flex-wrap:wrap;padding:10px;background:var(--canvas-soft);border-radius:var(--radius-sm);">
                <div style="flex:1;min-width:180px;">
                    <select name="param_type[]" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);"
                            onchange="this.style.color = this.value ? 'var(--ink)' : 'var(--muted)'">
                        <option value="">— Selecione o alarme —</option>
                        <?php
                        $lastCat = '';
                        foreach ($alarmTypes as $at):
                            if ($at['category'] !== $lastCat):
                                if ($lastCat !== '') echo '</optgroup>';
                                $lastCat = $at['category'];
                                echo '<optgroup label="' . htmlspecialchars($at['category']) . '">';
                            endif;
                            $selected = ($p['alarm_type'] === $at['alarm_name_pt']) ? 'selected' : '';
                        ?>
                        <option value="<?= htmlspecialchars($at['alarm_name_pt']) ?>" <?= $selected ?>>
                            <?= htmlspecialchars($at['alarm_name_pt']) ?> (<?= $at['alarm_code'] ?>)
                        </option>
                        <?php endforeach; if ($lastCat !== '') echo '</optgroup>'; ?>
                    </select>
                </div>
                <div style="display:flex;align-items:center;gap:4px;min-width:110px;padding-top:6px;">
                    <label style="font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap;">
                        <input type="hidden" name="param_generates[]" value="0">
                        <input type="checkbox" name="param_generates[]" value="1" <?= !empty($p['generates_occurrence']) ? 'checked' : '' ?> style="width:auto;">
                        Gera?
                    </label>
                </div>
                <div style="min-width:100px;">
                    <select name="param_risk[]" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                        <option value="baixo" <?= ($p['risk'] ?? '') === 'baixo' ? 'selected' : '' ?>>Baixo</option>
                        <option value="medio" <?= ($p['risk'] ?? '') === 'medio' ? 'selected' : '' ?>>Médio</option>
                        <option value="alto"  <?= ($p['risk'] ?? '') === 'alto' ? 'selected' : '' ?>>Alto</option>
                    </select>
                </div>
                <div style="min-width:80px;">
                    <input type="number" name="param_threshold[]" value="<?= $p['threshold'] ?? '10' ?>"
                           min="1" max="1440" placeholder="Min"
                           style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                </div>
                <button type="button" onclick="removeParamRow(this)" title="Remover"
                        style="padding:6px 10px;font-size:16px;line-height:1;border:1px solid var(--hairline);border-radius:var(--radius-sm);background:var(--surface);color:var(--error);cursor:pointer;margin-top:2px;">
                    &times;
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <button type="button" onclick="addParamRow()" class="btn btn-outline btn-sm mt-16">
            + Adicionar Tipo de Alarme
        </button>

        <div class="mt-24">
            <button type="submit" class="btn btn-primary">Salvar Perfil</button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Lista de perfis -->
<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Perfis de Ocorrência</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">
            Defina quais alarmes geram ocorrências e com qual nível de risco.
        </p>
    </div>
    <a href="?action=novo" class="btn btn-primary btn-sm">+ Novo Perfil</a>
</div>

<div class="mb-12">
    <input type="text" placeholder="Pesquisar perfil..." oninput="yuvTableFilter(this, 'occ-table')"
           style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:260px;">
</div>
<div class="table-wrap" id="occ-table">
    <table>
        <thead>
            <tr>
                <th>Nome</th>
                <th>Padrão</th>
                <th>Qtd. Parâmetros</th>
                <th>Clientes Vinculados</th>
                <th style="text-align:center;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($configs)): ?>
            <tr>
                <td colspan="5" style="text-align:center;padding:32px;color:var(--muted);">
                    Nenhum perfil cadastrado.
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($configs as $cfg):
                $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM occurrence_config_params WHERE config_id = :cid");
                $stmt->execute([':cid' => $cfg['id']]);
                $paramCount = (int)$stmt->fetch()['cnt'];
            ?>
            <tr>
                <td style="font-weight:500;"><?= htmlspecialchars($cfg['name']) ?></td>
                <td>
                    <?php if ($cfg['is_default']): ?>
                    <span class="badge badge-primary">Padrão</span>
                    <?php else: ?>
                    <span class="badge">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $paramCount ?></td>
                <td><?= (int)($cfg['customer_count'] ?? 0) ?></td>
                <td style="text-align:center;">
                    <div style="display:flex;gap:4px;justify-content:center;">
                        <a href="?action=editar&id=<?= $cfg['id'] ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">Editar</a>
                        <a href="?action=excluir&id=<?= $cfg['id'] ?>"
                           onclick="return confirm('Excluir este perfil?')"
                           class="btn btn-outline btn-sm"
                           style="padding:4px 10px;font-size:12px;color:var(--error);">Excluir</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
var paramRowTemplate = null;

function addParamRow() {
    var container = document.getElementById('params-container');
    if (!container) return;

    var lastRow = container.querySelector('.param-row:last-child');
    var clone = lastRow ? lastRow.cloneNode(true) : createTemplateRow();

    var selects = clone.querySelectorAll('select');
    selects.forEach(function(s) { s.selectedIndex = 0; });
    var inputs = clone.querySelectorAll('input[type="number"]');
    inputs.forEach(function(i) { i.value = '10'; });
    var checks = clone.querySelectorAll('input[type="checkbox"]');
    checks.forEach(function(c) { c.checked = true; });

    container.appendChild(clone);
}

function createTemplateRow() {
    var div = document.createElement('div');
    div.className = 'param-row';
    div.style.cssText = 'display:flex;gap:8px;align-items:flex-start;margin-bottom:10px;flex-wrap:wrap;padding:10px;background:var(--canvas-soft);border-radius:var(--radius-sm);';

    div.innerHTML =
        '<div style="flex:1;min-width:180px;">' +
        '  <select name="param_type[]" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">' +
        '    <option value="">— Selecione o alarme —</option>' +
        document.querySelector('#params-container select') ? document.querySelector('#params-container select').innerHTML.split('<option value="">')[1] || '' : '' +
        '  </select>' +
        '</div>' +
        '<div style="display:flex;align-items:center;gap:4px;min-width:110px;padding-top:6px;">' +
        '  <label style="font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap;">' +
        '    <input type="hidden" name="param_generates[]" value="0">' +
        '    <input type="checkbox" name="param_generates[]" value="1" checked style="width:auto;"> Gera?' +
        '  </label>' +
        '</div>' +
        '<div style="min-width:100px;">' +
        '  <select name="param_risk[]" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">' +
        '    <option value="baixo">Baixo</option>' +
        '    <option value="medio">Médio</option>' +
        '    <option value="alto">Alto</option>' +
        '  </select>' +
        '</div>' +
        '<div style="min-width:80px;">' +
        '  <input type="number" name="param_threshold[]" value="10" min="1" max="1440" placeholder="Min" style="width:100%;padding:8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">' +
        '</div>' +
        '<button type="button" onclick="removeParamRow(this)" title="Remover" style="padding:6px 10px;font-size:16px;line-height:1;border:1px solid var(--hairline);border-radius:var(--radius-sm);background:var(--surface);color:var(--error);cursor:pointer;margin-top:2px;">&times;</button>';

    return div;
}

function removeParamRow(btn) {
    var row = btn.closest('.param-row');
    if (!row) return;
    var container = document.getElementById('params-container');
    var rows = container.querySelectorAll('.param-row');
    if (rows.length <= 1) {
        row.querySelector('select').selectedIndex = 0;
        row.querySelector('input[type="number"]').value = '10';
        return;
    }
    row.remove();
}
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
