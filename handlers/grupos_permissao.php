<?php
/**
 * JIMI Webhook System — Grupos de Permissão v4.0.0
 * Endpoint: /grupos-permissao
 *
 * CRUD para permission_groups com matriz de permissões tela × ação.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$db = Database::getInstance()->getConnection();
$user = get_jimi_user();
$error   = null;
$success = null;

$actions = ['view' => 'Ver', 'create' => 'Criar', 'edit' => 'Editar', 'delete' => 'Excluir', 'export' => 'Exportar'];

$screens = [
    'resumo'                => 'Resumo',
    'rastreamento'          => 'Rastreamento',
    'bi'                    => 'BI',
    'ocorrencias_dashboard' => 'Dashboard (Ocorrências)',
    'comandos'              => 'Comandos',
    'exportar'              => 'Exportar',
    'video_aovivo'          => 'Vídeo — Ao Vivo',
    'video_playback'        => 'Vídeo — Playback',
    'video_downloads'       => 'Vídeo — Downloads',
    'relatorios'            => 'Relatórios',
    'ativos'                => 'Ativos',
    'chips'                 => 'Chips',
    'clientes'              => 'Clientes',
    'equipamentos'          => 'Equipamentos',
    'grupos-permissao'      => 'Grupos de Permissão',
    'motoristas'            => 'Motoristas',
    'config-ocorrencias'    => 'Config. Ocorrências',
    'usuarios'              => 'Usuários',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if ($action === 'delete' && $id > 0) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE permission_group_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $error = 'Não é possível excluir: há usuários vinculados a este grupo.';
        } else {
            $stmt = $db->prepare("DELETE FROM permission_groups WHERE id = ?");
            $stmt->execute([$id]);
            $success = 'Grupo de permissão removido.';
        }
    } elseif ($action === 'save') {
        $name      = trim($_POST['name'] ?? '');
        $user_type = $_POST['user_type'] ?? 'cliente';

        if (empty($name)) {
            $error = 'Nome do grupo é obrigatório.';
        } elseif (!in_array($user_type, ['revendedor', 'cliente'], true)) {
            $error = 'Tipo de usuário inválido.';
        } else {
            $permissions = [];
            foreach ($screens as $screenKey => $screenLabel) {
                foreach ($actions as $actionKey => $actionLabel) {
                    $fieldName = "perm_{$screenKey}_{$actionKey}";
                    if (!empty($_POST[$fieldName])) {
                        $permissions[$screenKey][] = $actionKey;
                    }
                }
                if (isset($permissions[$screenKey])) {
                    sort($permissions[$screenKey]);
                }
            }

            try {
                $permissionsJson = !empty($permissions) ? json_encode($permissions, JSON_UNESCAPED_UNICODE) : null;

                if ($id > 0) {
                    $stmt = $db->prepare("UPDATE permission_groups SET name=?, user_type=?, permissions=? WHERE id=?");
                    $stmt->execute([$name, $user_type, $permissionsJson, $id]);
                    $success = 'Grupo de permissão atualizado.';
                } else {
                    $stmt = $db->prepare("INSERT INTO permission_groups (name, user_type, permissions) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $user_type, $permissionsJson]);
                    $success = 'Grupo de permissão criado com sucesso.';
                }
            } catch (PDOException $e) {
                $error = 'Erro ao salvar grupo: ' . $e->getMessage();
            }
        }
    }
}

$groups = [];
try {
    $groups = $db->query("
        SELECT pg.*, COUNT(u.id) AS user_count
        FROM permission_groups pg
        LEFT JOIN users u ON u.permission_group_id = pg.id
        GROUP BY pg.id
        ORDER BY pg.user_type, pg.name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$editGroup = null;
$editPermissions = [];
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM permission_groups WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editGroup = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editGroup && !empty($editGroup['permissions'])) {
        $decoded = json_decode($editGroup['permissions'], true);
        if (is_array($decoded)) $editPermissions = $decoded;
    }
}

function has_perm($editPermissions, $screen, $action) {
    return isset($editPermissions[$screen]) && in_array($action, $editPermissions[$screen], true);
}

$page_title    = 'Grupos de Permissão';
$current_route = 'grupos-permissao';
include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($error): ?>
<div class="card mb-16" style="border-color:#fce4eb;background:#fef2f5;color:var(--error);font-size:13px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 540px;gap:16px">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Nome</th><th>Tipo de Usuário</th><th>Qtd. de Usuários</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($groups as $g): ?>
                <tr>
                    <td style="font-weight:500;color:var(--ink)"><?= htmlspecialchars($g['name']) ?></td>
                    <td>
                        <?php if ($g['user_type'] === 'revendedor'): ?>
                        <span class="badge badge-info">Revendedor</span>
                        <?php else: ?>
                        <span class="badge" style="background:var(--surface-strong);color:var(--body)">Cliente</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-mono"><?= (int)($g['user_count'] ?? 0) ?></td>
                    <td>
                        <a href="?edit=<?= $g['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('Remover este grupo de permissão?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $g['id'] ?>">
                            <button class="btn btn-outline btn-sm" style="color:var(--error)">Remover</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($groups)): ?>
                <tr><td colspan="4"><div class="empty-state"><h3>Nenhum grupo</h3><p>Crie um grupo de permissão para começar.</p></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">
            <?= $editGroup ? 'Editar Grupo' : 'Novo Grupo' ?>
        </h4>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editGroup): ?><input type="hidden" name="id" value="<?= $editGroup['id'] ?>"><?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label>Nome *</label>
                    <input type="text" name="name" required value="<?= htmlspecialchars($editGroup['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Tipo *</label>
                    <select name="user_type" required>
                        <option value="cliente" <?= (($editGroup['user_type'] ?? 'cliente') === 'cliente') ? 'selected' : '' ?>>Cliente</option>
                        <option value="revendedor" <?= (($editGroup['user_type'] ?? '') === 'revendedor') ? 'selected' : '' ?>>Revendedor</option>
                    </select>
                </div>
            </div>

            <div class="mb-16">
                <div style="font-size:11px;color:var(--muted);margin-bottom:4px">
                    Administradores Revendedor têm acesso total automaticamente.
                </div>
            </div>

            <div class="mb-16" style="font-size:12px;font-weight:600;color:var(--ink);text-transform:uppercase;letter-spacing:0.5px">Permissões por Tela</div>

            <div style="overflow-x:auto;border:1px solid var(--hairline);border-radius:var(--radius-md)">
                <table style="font-size:11px;margin:0">
                    <thead>
                        <tr style="background:var(--canvas-soft)">
                            <th style="text-align:left;min-width:180px">Tela</th>
                            <?php foreach ($actions as $aKey => $aLabel): ?>
                            <th style="text-align:center;padding:6px 4px;width:52px"><?= $aLabel ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($screens as $screenKey => $screenLabel): ?>
                        <tr>
                            <td style="padding:5px 8px;color:var(--ink);font-weight:500"><?= $screenLabel ?></td>
                            <?php foreach ($actions as $aKey => $aLabel): ?>
                            <td style="text-align:center;padding:3px">
                                <input type="checkbox"
                                       name="perm_<?= $screenKey ?>_<?= $aKey ?>"
                                       value="1"
                                       <?= has_perm($editPermissions, $screenKey, $aKey) ? 'checked' : '' ?>
                                       style="width:auto;accent-color:var(--primary)">
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex-between mt-16">
                <?php if ($editGroup): ?>
                <a href="?" class="btn btn-outline btn-sm">Cancelar</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editGroup ? 'Salvar' : 'Criar Grupo' ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
