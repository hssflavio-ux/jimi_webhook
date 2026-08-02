<?php
/**
 * JIMI Webhook System — Servidor de E-mail (SMTP) v4.4.1
 * Rota: /config-smtp
 *
 * Cadastro das credenciais do servidor SMTP externo usado pelo motor de
 * notificações. Dois escopos:
 *   - GLOBAL   (customer_id NULL) — vale para toda a plataforma. Só admin.
 *   - CLIENTE  (customer_id = N)  — sobrepõe a global para aquele cliente,
 *                                   permitindo enviar do próprio domínio.
 *
 * A senha é gravada cifrada (AES-256-GCM) e NUNCA volta para o navegador:
 * o campo aparece vazio e, se ficar vazio ao salvar, a senha atual é
 * preservada.
 *
 * POST action=salvar  → grava as credenciais
 * POST action=testar  → envia um e-mail de teste com o que está gravado
 * POST action=excluir → remove a configuração do escopo
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/mailer.php';
require_login();
require_permission('config-smtp', 'view');

$db         = Database::getInstance()->getConnection();
$user       = get_jimi_user();
$customerId = get_customer_id();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

// Escopo em edição: admin pode alternar entre global e o cliente da sessão
$scope = $_GET['scope'] ?? ($isAdmin ? 'global' : 'cliente');
if (!$isAdmin) {
    $scope = 'cliente';
}
$scopeCustomerId = ($scope === 'global') ? null : $customerId;

$message = '';
$messageType = '';
$tableMissing = false;

/**
 * Lê a configuração exata de um escopo (sem herança) — a tela edita uma
 * linha específica, diferente de mail_config(), que resolve a precedência.
 *
 * @param PDO      $db  Conexão ativa
 * @param int|null $cid Escopo (null = global)
 * @returns array|null
 */
function smtp_row_for_scope(PDO $db, ?int $cid): ?array
{
    $sql = $cid === null
        ? "SELECT * FROM smtp_settings WHERE customer_id IS NULL LIMIT 1"
        : "SELECT * FROM smtp_settings WHERE customer_id = :cid LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute($cid === null ? [] : [':cid' => $cid]);
    return $stmt->fetch() ?: null;
}

// ── POST ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'salvar';

    // Escopo do POST (o GET não sobrevive ao submit)
    $postScope = $_POST['scope'] ?? $scope;
    if (!$isAdmin) {
        $postScope = 'cliente';
    }
    $scope = $postScope;
    $scopeCustomerId = ($scope === 'global') ? null : $customerId;

    if ($scope === 'global' && !$isAdmin) {
        http_response_code(403);
        die('Somente o administrador configura o servidor global.');
    }

    try {
        if ($action === 'salvar') {
            require_permission('config-smtp', 'edit');

            $host      = trim($_POST['host'] ?? '');
            $port      = (int)($_POST['port'] ?? 587);
            $secure    = $_POST['secure'] ?? 'tls';
            $username  = trim($_POST['username'] ?? '');
            $password  = (string)($_POST['password'] ?? '');
            $fromEmail = trim($_POST['from_email'] ?? '');
            $fromName  = trim($_POST['from_name'] ?? 'bycamera');
            $timeout   = max(5, min(120, (int)($_POST['timeout_s'] ?? 20)));
            $isActive  = !empty($_POST['is_active']) ? 1 : 0;

            if (!in_array($secure, ['tls', 'ssl', 'none'], true)) $secure = 'tls';
            if ($port < 1 || $port > 65535) $port = ($secure === 'ssl' ? 465 : 587);

            if ($host === '') {
                throw new InvalidArgumentException('Informe o servidor (host) SMTP.');
            }
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Informe um e-mail remetente válido.');
            }

            $existing = smtp_row_for_scope($db, $scopeCustomerId);

            // Senha em branco = manter a atual (ela nunca é exibida no form)
            $passwordEnc = $existing['password_enc'] ?? null;
            if ($password !== '') {
                $passwordEnc = app_encrypt($password);
                if ($passwordEnc === null) {
                    throw new RuntimeException(
                        'Não foi possível cifrar a senha: defina APP_KEY (ou WEBHOOK_TOKEN) no .env.'
                    );
                }
            }

            if ($existing) {
                $stmt = $db->prepare(
                    "UPDATE smtp_settings
                        SET host = :host, port = :port, secure = :secure, username = :user,
                            password_enc = :pass, from_email = :from, from_name = :fname,
                            timeout_s = :tmo, is_active = :active, updated_by = :uid
                      WHERE id = :id"
                );
                $stmt->execute([
                    ':host' => $host, ':port' => $port, ':secure' => $secure,
                    ':user' => $username ?: null, ':pass' => $passwordEnc,
                    ':from' => $fromEmail, ':fname' => $fromName, ':tmo' => $timeout,
                    ':active' => $isActive, ':uid' => $user['id'] ?? null,
                    ':id' => $existing['id'],
                ]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO smtp_settings
                     (customer_id, host, port, secure, username, password_enc,
                      from_email, from_name, timeout_s, is_active, updated_by)
                     VALUES (:cid, :host, :port, :secure, :user, :pass, :from, :fname, :tmo, :active, :uid)"
                );
                $stmt->execute([
                    ':cid' => $scopeCustomerId, ':host' => $host, ':port' => $port,
                    ':secure' => $secure, ':user' => $username ?: null, ':pass' => $passwordEnc,
                    ':from' => $fromEmail, ':fname' => $fromName, ':tmo' => $timeout,
                    ':active' => $isActive, ':uid' => $user['id'] ?? null,
                ]);
            }

            // O painel "em uso agora" é renderizado nesta mesma request:
            // sem limpar o cache ele mostraria a configuração anterior.
            smtp_settings_cache_clear();
            $message = 'Credenciais salvas. Use "Enviar e-mail de teste" para validar.';
            $messageType = 'success';

        } elseif ($action === 'testar') {
            require_permission('config-smtp', 'edit');

            $testTo = trim($_POST['test_to'] ?? '');
            if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Informe um e-mail de destino válido para o teste.');
            }

            $row = smtp_row_for_scope($db, $scopeCustomerId);
            if (!$row) {
                throw new RuntimeException('Salve as credenciais antes de testar.');
            }

            $html = '<!doctype html><html lang="pt-BR"><body style="font-family:Helvetica,Arial,sans-serif;color:#0a0b0d;">'
                  . '<h2 style="font-size:18px;font-weight:600;">Teste de envio — bycamera</h2>'
                  . '<p style="font-size:14px;line-height:1.6;color:#5b616e;">'
                  . 'Se você está lendo esta mensagem, o servidor SMTP cadastrado está funcionando '
                  . 'e as notificações por e-mail serão entregues.</p>'
                  . '<p style="font-size:12px;color:#8a919e;">Servidor: ' . htmlspecialchars($row['host'])
                  . ':' . (int)$row['port'] . ' (' . htmlspecialchars($row['secure']) . ') — enviado em '
                  . fmt_brt(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i') . ' BRT.</p></body></html>';

            $result = send_mail([$testTo], 'Teste de envio — bycamera', $html, [], $scopeCustomerId);

            $upd = $db->prepare(
                "UPDATE smtp_settings SET last_test_at = NOW(), last_test_ok = :ok, last_test_error = :err WHERE id = :id"
            );
            $upd->execute([
                ':ok'  => $result['ok'] ? 1 : 0,
                ':err' => $result['ok'] ? null : mb_substr((string)$result['error'], 0, 500),
                ':id'  => $row['id'],
            ]);

            if ($result['ok']) {
                $message = "E-mail de teste enviado para $testTo.";
                $messageType = 'success';
            } else {
                $message = 'Falha no envio: ' . $result['error'];
                $messageType = 'error';
            }

        } elseif ($action === 'excluir') {
            require_permission('config-smtp', 'delete');
            $row = smtp_row_for_scope($db, $scopeCustomerId);
            if ($row) {
                $db->prepare("DELETE FROM smtp_settings WHERE id = :id")->execute([':id' => $row['id']]);
                smtp_settings_cache_clear();
                $message = 'Configuração removida.';
                $messageType = 'success';
            }
        }

    } catch (InvalidArgumentException $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    } catch (Exception $e) {
        $message = 'Erro: ' . $e->getMessage();
        $messageType = 'error';
    }
}

// ── Estado atual ───────────────────────────────────────────────
$row = null;
try {
    $row = smtp_row_for_scope($db, $scopeCustomerId);
} catch (Exception $e) {
    $tableMissing = true;
    $message = 'Tabela indisponível — aplique a migração v4.4.1.';
    $messageType = 'error';
}

// O que o motor REALMENTE usaria para este cliente (herança resolvida)
$effective = mail_config($customerId);
$keySource = app_secret_key_source();

$page_title = 'Servidor de E-mail (SMTP)';
$current_route = 'config-smtp';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($message): ?>
<div class="toast toast-<?= $messageType ?> toast-show" style="position:fixed;bottom:24px;right:24px;z-index:9999;max-width:420px;">
    <?= htmlspecialchars($message) ?>
</div>
<script>setTimeout(function(){var t=document.querySelector('.toast');if(t)t.style.display='none';},7000);</script>
<?php endif; ?>

<?php if ($keySource === 'nenhuma'): ?>
<div class="card mb-16" style="border-left:3px solid var(--error);background:#fdf2f4;">
    <div style="font-size:13px;color:#8c1c28;">
        <strong>Sem chave de cifra.</strong> Defina <code>APP_KEY</code> no <code>.env</code> antes de cadastrar a senha —
        sem ela o sistema não consegue guardar o segredo com segurança.
    </div>
</div>
<?php elseif ($keySource === 'webhook_token'): ?>
<div class="card mb-16" style="border-left:3px solid #a97a00;background:#fdf9ec;">
    <div style="font-size:13px;color:#7a5a00;">
        <strong>A senha será cifrada com o <code>WEBHOOK_TOKEN</code></strong> (não há <code>APP_KEY</code> definida).
        Funciona, mas se o token do webhook for rotacionado a senha gravada deixa de abrir e precisa ser recadastrada.
        Definir um <code>APP_KEY</code> próprio evita esse acoplamento.
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="mb-16" style="display:flex;gap:8px;">
    <a href="?scope=global" class="btn btn-sm <?= $scope === 'global' ? 'btn-primary' : 'btn-outline' ?>">
        Servidor global da plataforma
    </a>
    <a href="?scope=cliente" class="btn btn-sm <?= $scope === 'cliente' ? 'btn-primary' : 'btn-outline' ?>">
        Servidor deste cliente
    </a>
</div>
<?php endif; ?>

<div class="card mb-16">
    <div style="font-size:12px;color:var(--muted);line-height:1.6;">
        <strong style="color:var(--ink);">Em uso agora para este cliente:</strong>
        <?php if ($effective['host'] === ''): ?>
            nenhum servidor configurado — o canal de e-mail das notificações vai falhar
            (sino, pop-up e som seguem funcionando).
        <?php else: ?>
            <span class="text-mono"><?= htmlspecialchars($effective['host']) ?>:<?= (int)$effective['port'] ?></span>
            (<?= htmlspecialchars($effective['secure']) ?>), remetente
            <span class="text-mono"><?= htmlspecialchars($effective['from']) ?></span>
            — origem:
            <?php
            echo $effective['source'] === 'banco:cliente' ? 'credenciais deste cliente'
               : ($effective['source'] === 'banco:global' ? 'servidor global da plataforma'
               : 'variáveis do .env (legado)');
            ?>.
        <?php endif; ?>
    </div>
</div>

<div class="card" style="max-width:760px;">
    <div class="flex-between mb-24">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">
            <?= $scope === 'global' ? 'Servidor global da plataforma' : 'Servidor deste cliente' ?>
        </h2>
        <?php if ($row && !empty($row['last_test_at'])): ?>
        <span class="badge <?= $row['last_test_ok'] ? 'badge-success' : 'badge-error' ?>">
            Último teste: <?= $row['last_test_ok'] ? 'OK' : 'falhou' ?> em <?= fmt_brt($row['last_test_at'], 'd/m/Y H:i') ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if ($row && !$row['last_test_ok'] && !empty($row['last_test_error'])): ?>
    <div class="mb-16" style="font-size:12px;color:var(--error);background:#fdf2f4;padding:10px 12px;border-radius:var(--radius-sm);">
        <strong>Erro do último teste:</strong> <?= htmlspecialchars($row['last_test_error']) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="salvar">
        <input type="hidden" name="scope" value="<?= htmlspecialchars($scope) ?>">

        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label>Servidor (host)</label>
                <input type="text" name="host" required
                       value="<?= htmlspecialchars($row['host'] ?? '') ?>"
                       placeholder="smtp.provedor.com.br">
            </div>
            <div class="form-group">
                <label>Porta</label>
                <input type="number" name="port" min="1" max="65535"
                       value="<?= (int)($row['port'] ?? 587) ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Segurança</label>
                <select name="secure" id="smtp-secure" onchange="smtpSyncPort()"
                        style="width:100%;padding:9px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <option value="tls"  <?= ($row['secure'] ?? 'tls') === 'tls'  ? 'selected' : '' ?>>STARTTLS (porta 587)</option>
                    <option value="ssl"  <?= ($row['secure'] ?? '')    === 'ssl'  ? 'selected' : '' ?>>SSL/TLS implícito (porta 465)</option>
                    <option value="none" <?= ($row['secure'] ?? '')    === 'none' ? 'selected' : '' ?>>Sem criptografia</option>
                </select>
            </div>
            <div class="form-group">
                <label>Timeout (segundos)</label>
                <input type="number" name="timeout_s" min="5" max="120"
                       value="<?= (int)($row['timeout_s'] ?? 20) ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Usuário</label>
                <input type="text" name="username" autocomplete="off"
                       value="<?= htmlspecialchars($row['username'] ?? '') ?>"
                       placeholder="usuario@provedor.com.br">
            </div>
            <div class="form-group">
                <label>Senha</label>
                <input type="password" name="password" autocomplete="new-password"
                       placeholder="<?= !empty($row['password_enc']) ? '•••••••• (mantém a atual)' : 'senha ou chave de API' ?>">
                <p class="text-muted" style="font-size:11px;margin-top:6px;">
                    Guardada cifrada e nunca reexibida. Deixe em branco para manter a senha atual.
                </p>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>E-mail remetente</label>
                <input type="email" name="from_email" required
                       value="<?= htmlspecialchars($row['from_email'] ?? '') ?>"
                       placeholder="nao-responda@suaempresa.com.br">
            </div>
            <div class="form-group">
                <label>Nome do remetente</label>
                <input type="text" name="from_name"
                       value="<?= htmlspecialchars($row['from_name'] ?? 'bycamera') ?>">
            </div>
        </div>

        <div class="form-group">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                <input type="checkbox" name="is_active" value="1" style="width:auto;"
                       <?= (!$row || !empty($row['is_active'])) ? 'checked' : '' ?>>
                Configuração ativa
            </label>
        </div>

        <div class="mt-24" style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary">Salvar credenciais</button>
        </div>
    </form>
</div>

<?php if ($row): ?>
<div class="card mt-16" style="max-width:760px;">
    <h3 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px;">Enviar e-mail de teste</h3>
    <p class="text-muted" style="font-size:12px;margin-bottom:14px;">
        Envia usando exatamente as credenciais gravadas acima. O resultado fica registrado nesta tela.
    </p>
    <form method="POST" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="testar">
        <input type="hidden" name="scope" value="<?= htmlspecialchars($scope) ?>">
        <div class="form-group" style="flex:1;min-width:260px;margin-bottom:0;">
            <label>Enviar para</label>
            <input type="email" name="test_to" required
                   value="<?= htmlspecialchars($user['email'] ?? '') ?>">
        </div>
        <button type="submit" class="btn btn-outline">Enviar e-mail de teste</button>
    </form>
</div>

<div class="card mt-16" style="max-width:760px;border-left:3px solid var(--error);">
    <div class="flex-between" style="flex-wrap:wrap;gap:12px;">
        <div style="font-size:12px;color:var(--muted);">
            Remover a configuração <?= $scope === 'global' ? 'global' : 'deste cliente' ?>.
            <?= $scope === 'cliente' ? 'O cliente volta a usar o servidor global.' : 'A plataforma volta a usar o .env, se houver.' ?>
        </div>
        <form method="POST" onsubmit="return confirm('Remover esta configuração de SMTP?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="excluir">
            <input type="hidden" name="scope" value="<?= htmlspecialchars($scope) ?>">
            <button type="submit" class="btn btn-outline btn-sm" style="color:var(--error);">Remover</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Ajusta a porta ao trocar a segurança, mas só quando ela ainda está num
// valor padrão — não sobrescreve porta customizada digitada pelo usuário.
function smtpSyncPort() {
    var sel = document.getElementById('smtp-secure');
    var port = document.querySelector('input[name="port"]');
    if (!sel || !port) return;
    var defaults = ['25', '465', '587'];
    if (defaults.indexOf(String(port.value)) === -1) return;
    port.value = sel.value === 'ssl' ? '465' : (sel.value === 'none' ? '25' : '587');
}
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
