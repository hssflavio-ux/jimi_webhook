<?php
/**
 * bycamera — Recuperação de acesso v4.13.21
 * Rota: /esqueci-senha  (PÚBLICA — não chama require_login)
 *
 * O usuário digita o e-mail cadastrado e recebe uma senha temporária de 6
 * caracteres, que o obriga a definir uma nova no primeiro acesso.
 *
 * 🔴 A resposta é SEMPRE a mesma, exista ou não o e-mail. Diferenciar
 * ("e-mail não encontrado" × "enviado") transformaria esta tela pública num
 * verificador de quem tem conta no sistema — dá para varrer uma lista inteira
 * de endereços sem credencial nenhuma. Pelo mesmo motivo, falha de SMTP
 * também devolve a mensagem neutra: o erro vai para o log da aplicação, não
 * para a tela de quem talvez nem seja o dono do endereço.
 *
 * 🔴 Sem CSRF de propósito, como o /login: `csrf_generate()` deriva o token
 * por HMAC do cookie de sessão, e sem sessão ele é aleatório A CADA REQUEST —
 * o campo nunca casaria e o formulário seria impossível de enviar. A proteção
 * aqui é o limite por IP (password_reset_ip_allowed) mais o log do pedido.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/password_reset.php';

/** Resposta única da tela — ver o cabeçalho. */
const MSG_NEUTRA = 'Se este e-mail estiver cadastrado e ativo, enviamos uma senha temporária para ele. Confira a caixa de entrada e o spam.';

/** Janela em que o mesmo e-mail não recebe outra temporária (aplicada em silêncio). */
const REENVIO_MIN = 5;

$auth_error   = null;
$auth_success = null;
$emailDigitado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailDigitado = trim($_POST['email'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!filter_var($emailDigitado, FILTER_VALIDATE_EMAIL)) {
        $auth_error = 'Informe um e-mail válido.';
    } elseif (!password_reset_ip_allowed($ip)) {
        // Este limite PODE virar mensagem: ele fala do IP de quem pediu, não
        // revela nada sobre o e-mail digitado.
        $auth_error = 'Muitos pedidos deste endereço. Tente novamente mais tarde.';
    } else {
        password_reset_log_request($emailDigitado, $ip);

        $userId = null;
        try {
            $db = Database::getInstance()->getConnection();
            // O filtro por `temp_password_sent_at` é o limite POR E-MAIL: quem
            // clica três vezes no botão recebe uma senha só, e as outras duas
            // não invalidam a que já está na caixa de entrada dele.
            $stmt = $db->prepare(
                "SELECT id FROM users
                  WHERE email = :email AND is_active = 1
                    AND (temp_password_sent_at IS NULL
                         OR temp_password_sent_at < DATE_SUB(NOW(), INTERVAL :min MINUTE))
                  LIMIT 1"
            );
            $stmt->execute([':email' => $emailDigitado, ':min' => REENVIO_MIN]);
            $userId = $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) {
            // Banco antes da migração v4.13.21 (coluna ausente): cai no SELECT
            // simples, sem o limite por e-mail.
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
                $stmt->execute([$emailDigitado]);
                $userId = $stmt->fetchColumn() ?: null;
            } catch (Throwable $e2) {
                error_log('esqueci_senha: ' . $e2->getMessage());
            }
        }

        if ($userId) {
            issue_temp_password((int)$userId, false); // erro fica no log, não na tela
        }
        $auth_success = MSG_NEUTRA;
        $emailDigitado = '';
    }
}

ob_start(); ?>
<form method="post" autocomplete="on">
    <div class="fg">
        <label for="email">E-mail cadastrado</label>
        <input type="email" id="email" name="email" required autofocus autocomplete="email"
               value="<?= htmlspecialchars($emailDigitado) ?>">
    </div>
    <p class="hint">Ao pedir, a senha atual da conta deixa de valer — o acesso passa a ser pela temporária que enviarmos.</p>
    <button type="submit" class="btn btn-primary">Enviar senha temporária</button>
</form>
<?php
$auth_body       = ob_get_clean();
$auth_links      = '<a href="/login">Voltar para o login</a>';
$auth_page_title = 'bycamera — Recuperar acesso';
$auth_heading    = 'Recuperar acesso';
$auth_sub        = 'Enviaremos uma senha temporária para o seu e-mail.';
include __DIR__ . '/../web/auth_card_template.php';
