<?php
/**
 * JIMI Webhook System — Gestão de Usuários v4.0.0
 * Rota: /usuarios
 *
 * Evolução YUV: abas Minha Empresa/Meus Clientes, permission_group_id,
 * user_type, photo_url.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/password_reset.php';
require_admin();

$db = Database::getInstance()->getConnection();
$currentUser = get_jimi_user();
$isReseller = ($currentUser['user_type'] ?? '') === 'revendedor';
$error = null;
$success = null;
// Usuário cujo e-mail de senha temporária falhou: a tela mostra o contador de
// 30 s e reposta o reenvio sozinha (ver o bloco de retry no fim do arquivo).
$retryUserId = null;

$tab = $_GET['tab'] ?? 'empresa';

// ── POST ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    // RBAC ação fina (v4.2.0 — Fase B2)
    require_permission('usuarios', ($action === 'toggle' || $id > 0) ? 'edit' : 'create');

    if ($action === 'resend') {
        // Reenvio manual (botão da lista) e retentativa automática caem aqui.
        // `issue_temp_password()` gera OUTRA senha: a anterior nunca foi
        // gravada (envio falhou) ou já não serve — não existe "reenviar a
        // mesma", porque o banco só guarda o bcrypt dela.
        $r = issue_temp_password($id, false);
        if ($r['ok']) {
            $success = 'Senha temporária enviada para ' . $r['email'] . '.';
        } else {
            $error = 'Não foi possível enviar: ' . $r['error'];
            $retryUserId = $id;
        }
    } elseif ($action === 'toggle') {
        if ($id === (int)$currentUser['id']) {
            $error = 'Você não pode desativar seu próprio usuário.';
        } else {
            $newStatus = (int)($_POST['is_active'] ?? 0);
            $stmt = $db->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            $success = $newStatus ? 'Usuário ativado.' : 'Usuário desativado.';
        }
    } elseif ($action === 'save') {
        $name       = trim($_POST['name'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $password   = $_POST['password'] ?? '';
        $role       = $_POST['role'] ?? 'operator';
        $userType   = $_POST['user_type'] ?? 'cliente';
        $customerId = (int)($_POST['customer_id'] ?? 0);
        $pgId       = !empty($_POST['permission_group_id']) ? (int)$_POST['permission_group_id'] : null;
        $photoUrl   = trim($_POST['photo_url'] ?? '');

        $validRoles = ['admin', 'operator', 'viewer'];
        if (!in_array($role, $validRoles)) $role = 'operator';

        if (!$name || !$email || !$customerId) {
            $error = 'Nome, e-mail e cliente são obrigatórios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'E-mail inválido.';
        } elseif ($password !== '' && strlen($password) < 6) {
            // v4.13.21 — em branco deixou de ser erro na criação: passou a
            // significar "gere e mande por e-mail". O mínimo só vale para quem
            // digitou alguma coisa.
            $error = 'A senha deve ter no mínimo 6 caracteres.';
        } else {
            try {
                $db->beginTransaction();
                if ($id > 0) {
                    $sql = "UPDATE users SET name=?, email=?, role=?, user_type=?, permission_group_id=?, photo_url=?";
                    $params = [$name, $email, $role, $userType, $pgId, $photoUrl ?: null];
                    if ($password !== '') {
                        // Senha definida à mão pelo admin encerra a pendência de
                        // troca: sem limpar a flag, o usuário para quem o admin
                        // acabou de definir uma senha ainda cairia em
                        // /trocar-senha no acesso seguinte, sem explicação.
                        $sql = "UPDATE users SET name=?, email=?, role=?, user_type=?, permission_group_id=?, photo_url=?, password_hash=?, must_change_password=0, temp_password_expires_at=NULL";
                        $params[] = password_hash($password, PASSWORD_BCRYPT);
                    }
                    $sql .= " WHERE id=?";
                    $params[] = $id;
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $userId = $id;
                } else {
                    // Senha em branco = o sistema gera e envia. O INSERT ainda
                    // precisa de ALGUM hash (`password_hash` é NOT NULL): entra
                    // um valor aleatório de 32 bytes que ninguém — nem o admin
                    // — conhece, substituído por `issue_temp_password()` quando
                    // o e-mail sai. Se o envio falhar, o usuário fica com esse
                    // hash impossível e o selo "senha não entregue" na lista,
                    // que é exatamente o que o botão Reenviar resolve.
                    $enviarTemp  = ($password === '');
                    $hashInicial = $enviarTemp
                        ? password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT)
                        : password_hash($password, PASSWORD_BCRYPT);
                    $stmt = $db->prepare("INSERT INTO users (email, password_hash, name, role, user_type, permission_group_id, photo_url, must_change_password) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->execute([$email, $hashInicial, $name, $role, $userType, $pgId, $photoUrl ?: null, $enviarTemp ? 1 : 0]);
                    $userId = (int)$db->lastInsertId();
                }

                $stmt = $db->prepare("DELETE FROM customer_users WHERE user_id = ?");
                $stmt->execute([$userId]);
                $stmt = $db->prepare("INSERT INTO customer_users (customer_id, user_id, role) VALUES (?, ?, ?)");
                $stmt->execute([$customerId, $userId, $role]);

                $db->commit();

                // Fora da transação de propósito: `issue_temp_password()` abre a
                // dela, e o PDO não aninha. O usuário já está gravado quando o
                // e-mail é tentado — é o que torna o reenvio possível.
                if ($id === 0 && !empty($enviarTemp)) {
                    $r = issue_temp_password($userId, true);
                    if ($r['ok']) {
                        $success = 'Usuário criado. Senha temporária enviada para ' . $email . '.';
                    } else {
                        $error = 'Usuário criado, mas o e-mail com a senha temporária não saiu: ' . $r['error'];
                        $retryUserId = $userId;
                    }
                } else {
                    $success = $id > 0 ? 'Usuário atualizado.' : 'Usuário criado.';
                }
            } catch (PDOException $e) {
                $db->rollBack();
                $error = ($e->getCode() === '23000') ? 'E-mail já existe.' : 'Erro: ' . $e->getMessage();
            }
        }
    }
}

// ── List (Fase C: busca + export) ────────────────────────────
$q = trim($_GET['q'] ?? '');
$typeWhere = $tab === 'clientes' ? "(u.user_type = 'cliente' OR u.user_type IS NULL)" : "u.user_type = 'revendedor'";
$listParams = [];
if ($q !== '') {
    $typeWhere .= " AND (u.name LIKE :q1 OR u.email LIKE :q2)";
    $listParams[':q1'] = "%$q%";
    $listParams[':q2'] = "%$q%";
}
$listStmt = $db->prepare("
    SELECT u.*, GROUP_CONCAT(c.name SEPARATOR ', ') AS customer_names
    FROM users u
    LEFT JOIN customer_users cu ON cu.user_id = u.id
    LEFT JOIN customers c ON c.id = cu.customer_id
    WHERE $typeWhere
    GROUP BY u.id ORDER BY u.name
");
$listStmt->execute($listParams);
$users = $listStmt->fetchAll();

$customers = report_customer_options($db);
$permGroups = [];
try {
    $permGroups = $db->query("SELECT id, name, user_type FROM permission_groups ORDER BY name")->fetchAll();
} catch (Exception $e) {}
$pgNames = array_column($permGroups, 'name', 'id');
$roleLabels = ['admin' => 'Administrador', 'operator' => 'Operador', 'viewer' => 'Visualizador'];

// Export síncrono (Fase C)
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('usuarios', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expRows = [];
    foreach ($users as $u) {
        $expRows[] = [
            $u['name'] ?? '—',
            $u['email'] ?? '—',
            $roleLabels[$u['role'] ?? ''] ?? ($u['role'] ?? '—'),
            $u['user_type'] === 'revendedor' ? 'Revendedor' : 'Cliente',
            $pgNames[$u['permission_group_id'] ?? 0] ?? '—',
            $u['customer_names'] ?? '—',
            $u['is_active'] ? 'Ativo' : 'Inativo',
        ];
    }
    stream_export($export, 'usuarios_' . $tab,
        ['Nome', 'E-mail', 'Papel', 'Tipo', 'Grupo de Permissão', 'Cliente(s)', 'Status'],
        $expRows, 'Usuários — ' . ($tab === 'clientes' ? 'Meus Clientes' : 'Minha Empresa'));
}

$editUser = null; $editCustomerId = null;
if (!empty($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch();
    if ($editUser) {
        $stmt = $db->prepare("SELECT customer_id FROM customer_users WHERE user_id = ? LIMIT 1");
        $stmt->execute([$editUser['id']]);
        $editCustomerId = $stmt->fetchColumn();
    }
}

$page_title = 'Usuários';
$current_route = 'usuarios';
include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($error): ?>
<div class="card mb-16" style="border-color:#fce4eb;background:#fef2f5;color:var(--error);font-size:13px"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="card mb-16" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<?php if ($retryUserId): ?>
<!-- Retentativa automática do e-mail (v4.13.21).
     🔴 O temporizador roda no NAVEGADOR, não no PHP: um sleep(30) no request
     prenderia um worker do PHP-FPM por meio minuto — os mesmos workers que
     atendem os webhooks das câmeras — e deixaria a tela do admin pendurada.
     Uma tentativa só; falhando de novo, fica o botão Reenviar da lista, porque
     insistir em laço vira fila de e-mails duplicados quando o problema é do
     outro lado. -->
<div class="card mb-16" id="retry-box" style="border-color:#ffe0b2;background:#fff8ec;color:#8a5a00;font-size:13px">
    Nova tentativa de envio em <strong id="retry-count">30</strong>s…
    <button type="button" id="retry-cancel" class="btn btn-outline btn-sm" style="margin-left:8px;padding:2px 10px;font-size:12px">Cancelar</button>
</div>
<form method="post" id="retry-form" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="resend">
    <input type="hidden" name="id" value="<?= (int)$retryUserId ?>">
</form>
<script>
(function () {
    var restam = 30, box = document.getElementById('retry-count'), timer;
    timer = setInterval(function () {
        restam--;
        if (box) box.textContent = restam;
        if (restam <= 0) {
            clearInterval(timer);
            document.getElementById('retry-form').submit();
        }
    }, 1000);
    document.getElementById('retry-cancel').addEventListener('click', function () {
        clearInterval(timer);
        document.getElementById('retry-box').textContent = 'Retentativa cancelada — use o botão Reenviar na lista.';
    });
})();
</script>
<?php endif; ?>

<!-- Tabs + busca + export (Fase C) -->
<div class="flex-between" style="margin-bottom:16px;gap:8px;flex-wrap:wrap;">
    <div class="flex" style="gap:0;">
        <a href="?tab=empresa" class="btn btn-sm <?= $tab==='empresa'?'btn-primary':'btn-outline' ?>" style="border-radius:var(--radius-pill) 0 0 var(--radius-pill);">Minha Empresa</a>
        <a href="?tab=clientes" class="btn btn-sm <?= $tab==='clientes'?'btn-primary':'btn-outline' ?>" style="border-radius:0 var(--radius-pill) var(--radius-pill) 0;">Meus Clientes</a>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <form method="GET" style="display:flex;gap:6px;">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Pesquisar nome ou e-mail..."
                   style="padding:8px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:220px;">
            <button type="submit" class="btn btn-outline btn-sm">Pesquisar</button>
        </form>
        <?php $expQ = $_GET; unset($expQ['export']); $expBase = http_build_query($expQ); ?>
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 400px;gap:16px">
    <!-- Lista -->
    <div class="table-wrap">
        <table>
            <thead><tr><th></th><th>Nome</th><th>E-mail</th><th>Papel</th><th>Grupo Perm.</th><th>Cliente(s)</th><th>Status</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td>
                        <div style="width:28px;height:28px;border-radius:50%;background:var(--primary-soft);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;">
                            <?= strtoupper(substr($u['name'] ?? 'U', 0, 1)) ?>
                        </div>
                    </td>
                    <td style="font-weight:500;color:var(--ink);"><?= htmlspecialchars($u['name']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <?= $u['user_type'] === 'revendedor' ? '<span class="badge badge-primary">Revendedor</span>' : htmlspecialchars($roleLabels[$u['role']] ?? $u['role']) ?>
                    </td>
                    <td><?= htmlspecialchars($pgNames[$u['permission_group_id'] ?? 0] ?? '—') ?></td>
                    <td><?= htmlspecialchars($u['customer_names'] ?? '—') ?></td>
                    <td>
                        <?= $u['is_active'] ? '<span class="badge badge-success">Ativo</span>' : '<span class="badge">Inativo</span>' ?>
                        <?php if (!empty($u['must_change_password'])): ?>
                            <?php if (empty($u['temp_password_sent_at'])): ?>
                                <!-- must_change_password=1 + sent_at NULL: o usuário existe e
                                     NINGUÉM sabe a senha dele. É o estado derivado que a
                                     migração v4.13.21 deixou de gravar numa quarta coluna. -->
                                <span class="badge" style="background:#fff1e0;color:#8a5a00;" title="O e-mail com a senha temporária não foi entregue">senha não entregue</span>
                            <?php else: ?>
                                <span class="badge" style="background:#eef0f3;color:#5b616e;" title="Enviada em <?= htmlspecialchars(fmt_brt($u['temp_password_sent_at'])) ?> — troca pendente no primeiro acesso">senha temporária</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;">
                            <a href="?edit=<?= $u['id'] ?>&tab=<?= $tab ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">Editar</a>
                            <?php if (!empty($u['is_active'])): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('Gerar e enviar uma NOVA senha temporária para <?= htmlspecialchars($u['email'], ENT_QUOTES) ?>? A senha atual deixa de valer.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="resend">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;" title="Gera outra senha temporária e envia por e-mail">Reenviar senha</button>
                            </form>
                            <?php endif; ?>
                            <?php if ((int)$u['id'] !== (int)$currentUser['id']): ?>
                            <form method="post" style="display:inline" onsubmit="return confirm('<?= $u['is_active']?'Desativar':'Ativar' ?>?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="is_active" value="<?= $u['is_active']?0:1 ?>">
                                <button class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;color:<?= $u['is_active']?'var(--error)':'var(--success)' ?>;"><?= $u['is_active']?'Desativar':'Ativar' ?></button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr><td colspan="8"><div class="empty-state"><h3>Nenhum usuário</h3></div></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Form -->
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px"><?= $editUser ? 'Editar Usuário' : 'Novo Usuário' ?></h4>
        <form method="post">
            <input type="hidden" name="action" value="save">
            <?= csrf_field() ?>
            <?php if ($editUser): ?><input type="hidden" name="id" value="<?= $editUser['id'] ?>"><?php endif; ?>
            <div class="form-group"><label>Nome *</label><input type="text" name="name" required value="<?= htmlspecialchars($editUser['name'] ?? '') ?>"></div>
            <div class="form-group"><label>E-mail *</label><input type="email" name="email" required value="<?= htmlspecialchars($editUser['email'] ?? '') ?>"></div>
            <div class="form-group">
                <label>Senha <?= $editUser ? '(deixe em branco para manter)' : '(opcional)' ?></label>
                <input type="password" name="password" minlength="6" autocomplete="new-password">
                <?php if (!$editUser): ?>
                <div style="font-size:11px;color:var(--muted);margin-top:6px;line-height:1.5">
                    Em branco, o sistema gera uma senha temporária de 6 caracteres, envia para o e-mail acima
                    e exige a troca no primeiro acesso. Preenchendo, a senha vale direto e nada é enviado.
                </div>
                <?php endif; ?>
            </div>
            <div class="form-row">
                <div class="form-group"><label>Papel *</label>
                    <select name="role" required><?php foreach ($roleLabels as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= ($editUser['role']??'operator')===$v?'selected':'' ?>><?= $l ?></option>
                    <?php endforeach; ?></select>
                </div>
                <div class="form-group"><label>Tipo</label>
                    <select name="user_type">
                        <option value="cliente" <?= ($editUser['user_type']??'cliente')==='cliente'?'selected':'' ?>>Cliente</option>
                        <option value="revendedor" <?= ($editUser['user_type']??'')==='revendedor'?'selected':'' ?>>Revendedor</option>
                    </select>
                </div>
            </div>
            <div class="form-group"><label>Cliente *</label>
                <select name="customer_id" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (string)$editCustomerId===(string)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>Grupo de Permissão</label>
                <select name="permission_group_id">
                    <option value="">— Nenhum —</option>
                    <?php foreach ($permGroups as $pg): ?>
                    <option value="<?= $pg['id'] ?>" <?= ($editUser['permission_group_id']??'')==$pg['id']?'selected':'' ?>><?= htmlspecialchars($pg['name']) ?> (<?= $pg['user_type'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>URL da Foto</label><input type="text" name="photo_url" value="<?= htmlspecialchars($editUser['photo_url'] ?? '') ?>" placeholder="https://..."></div>
            <div class="flex-between mt-16">
                <?php if ($editUser): ?><a href="?tab=<?= $tab ?>" class="btn btn-outline btn-sm">Cancelar</a><?php endif; ?>
                <button type="submit" class="btn btn-primary"><?= $editUser?'Salvar':'Criar Usuário' ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
