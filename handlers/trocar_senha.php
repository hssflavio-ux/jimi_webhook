<?php
/**
 * bycamera — Troca obrigatória de senha v4.13.21
 * Rota: /trocar-senha
 *
 * Destino de quem entrou com a senha temporária que recebeu por e-mail. Quem
 * prende o usuário aqui é `require_password_change_gate()`
 * (`includes/auth.php`), chamada de dentro do `require_login()` — logo, de
 * TODA página do sistema. Esta é a única tela que a exceção libera, junto com
 * `/logout`.
 *
 * Não pede a senha atual, ao contrário de `/perfil`: a pessoa acabou de
 * prová-la no login, e pedir de novo só faz ela voltar ao e-mail para copiar
 * seis caracteres que o sistema já verificou há dois segundos.
 *
 * Renderiza no cartão de autenticação (sem sidebar) de propósito: enquanto a
 * senha for temporária não existe navegação para lugar nenhum, e um layout com
 * menu convidaria a tentar.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_login();

$user = get_jimi_user();

// Sem pendência não há o que fazer aqui: a troca voluntária é em /perfil, que
// pede a senha atual.
if (!$user || !user_must_change_password()) {
    header('Location: /perfil');
    exit;
}

$db = Database::getInstance()->getConnection();
$auth_error   = null;
$auth_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $nova      = $_POST['new_password'] ?? '';
    $confirma  = $_POST['confirm_password'] ?? '';

    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $hashAtual = $stmt->fetchColumn();

    if (strlen($nova) < 6) {
        $auth_error = 'A nova senha deve ter no mínimo 6 caracteres.';
    } elseif ($nova !== $confirma) {
        $auth_error = 'As senhas não conferem.';
    } elseif ($hashAtual && password_verify($nova, $hashAtual)) {
        // Aceitar a própria temporária como definitiva anularia o fluxo: a
        // senha que circulou por e-mail viraria a senha permanente.
        $auth_error = 'A nova senha não pode ser igual à temporária que você recebeu.';
    } else {
        $upd = $db->prepare(
            "UPDATE users
                SET password_hash = ?,
                    must_change_password = 0,
                    temp_password_expires_at = NULL
              WHERE id = ?"
        );
        $upd->execute([password_hash($nova, PASSWORD_BCRYPT), $user['id']]);

        // A credencial mudou — o token emitido sob a antiga não continua valendo.
        $GLOBALS['_jimi_must_change_pw'] = false;
        rotate_session_token();

        Logger::info('Senha definitiva cadastrada após senha temporária', ['user_id' => (int)$user['id']]);
        // v4.15.0 — SEM before/after (mesma regra de /perfil): nunca gravar
        // hash nem senha.
        audit_log('user.password_change', 'user', (int)$user['id']);
        $auth_success = 'Senha alterada. Bem-vindo!';
    }
}

ob_start(); ?>
<?php if ($auth_success): ?>
    <a href="/" class="btn btn-primary">Ir para o sistema</a>
<?php else: ?>
<form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <div class="fg">
        <label for="new_password">Nova senha</label>
        <input type="password" id="new_password" name="new_password" required minlength="6" autofocus autocomplete="new-password">
    </div>
    <p class="hint">Mínimo de 6 caracteres. Não pode ser a senha temporária que você recebeu por e-mail.</p>
    <div class="fg">
        <label for="confirm_password">Confirmar nova senha</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="6" autocomplete="new-password">
    </div>
    <button type="submit" class="btn btn-primary">Definir senha</button>
</form>
<?php endif; ?>
<?php
$auth_body       = ob_get_clean();
$auth_links      = $auth_success ? '' : '<a href="/logout">Sair</a>';
$auth_page_title = 'bycamera — Definir senha';
$auth_heading    = $auth_success ? 'Tudo certo' : 'Defina sua senha';
$auth_sub        = $auth_success
    ? 'Sua senha definitiva está valendo.'
    : 'Você entrou com uma senha temporária. Escolha a sua para continuar.';
include __DIR__ . '/../web/auth_card_template.php';
