<?php
/**
 * bycamera — Senha temporária por e-mail v4.13.21
 *
 * Ponto ÚNICO dos dois fluxos que entregam senha por e-mail:
 *   • cadastro de usuário sem senha digitada (`/usuarios`)
 *   • recuperação de acesso (`/esqueci-senha`, rota pública)
 *
 * 🔴 A ORDEM É "envia primeiro, grava depois", e ela não é detalhe de estilo.
 * Gravar o hash antes do envio significa que um SMTP fora do ar, ou uma queda
 * de internet no meio do POST, MATA a senha que o usuário estava usando: ele
 * fica sem a antiga (já sobrescrita) e sem a nova (que não chegou). Enviando
 * primeiro, falha de envio = nada mudou no banco, e o usuário continua
 * entrando com a senha de sempre. É por isso que `issue_temp_password()` só
 * chama o UPDATE dentro do ramo de sucesso.
 *
 * ⚠️ O e-mail é a ÚNICA cópia legível da senha — o banco guarda só o bcrypt.
 * Não existe "ver a senha temporária" em tela nenhuma; perdeu-se o e-mail,
 * gera-se outra (botão Reenviar em `/usuarios`, ou o próprio /esqueci-senha).
 * Nunca registre o valor gerado no log: `Logger` grava em arquivo de texto que
 * fica no servidor por LOG_RETENTION_DAYS.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/../core/Logger.php';

/** Validade da senha temporária, em horas (decisão do dono do produto, v4.13.21). */
if (!defined('TEMP_PASSWORD_TTL_HOURS')) define('TEMP_PASSWORD_TTL_HOURS', 24);

/**
 * Alfabeto da senha temporária: A–Z e 2–9, SEM `I`, `O`, `0` e `1`.
 *
 * A pessoa vai DIGITAR isto lendo de um e-mail — o zero lido como "ó" e o um
 * lido como "i" viram chamado de suporte, não tentativa de login. Sobram 32
 * símbolos: 32^6 ≈ 1,07 bilhão de combinações, o que com prazo de 24 h e o
 * rate limit de 5 falhas por IP em 15 min (login_user) é folgado.
 */
if (!defined('TEMP_PASSWORD_ALPHABET')) define('TEMP_PASSWORD_ALPHABET', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789');

/**
 * Gera uma senha temporária alfanumérica.
 *
 * @param int $len Comprimento (padrão 6, o pedido do dono do produto)
 * @returns string
 */
function generate_temp_password(int $len = 6): string
{
    $alfabeto = TEMP_PASSWORD_ALPHABET;
    $max = strlen($alfabeto) - 1;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alfabeto[random_int(0, $max)];
    }
    return $out;
}

/**
 * Monta o corpo HTML do e-mail da senha temporária.
 *
 * @param string $nome     Nome do destinatário
 * @param string $senha    Senha temporária em claro
 * @param bool   $novoUser true = e-mail de boas-vindas; false = recuperação
 * @returns string HTML
 */
function temp_password_email_body(string $nome, string $senha, bool $novoUser): string
{
    $base = rtrim((string)(getenv('APP_URL') ?: ''), '/');
    $titulo = $novoUser ? 'Seu acesso ao bycamera' : 'Recuperação de acesso';
    $intro  = $novoUser
        ? 'Sua conta foi criada. Use a senha temporária abaixo para entrar — o sistema vai pedir uma senha nova assim que você acessar.'
        : 'Recebemos um pedido de recuperação de acesso. Use a senha temporária abaixo para entrar — o sistema vai pedir uma senha nova assim que você acessar.';

    // Sem APP_URL o botão viraria href relativo, que não abre em caixa de
    // entrada nenhuma (mesma lição do worker.php, v4.7.2): omite o botão e
    // instrui por texto.
    $botao = $base !== ''
        ? '<p style="margin:24px 0"><a href="' . htmlspecialchars($base . '/login', ENT_QUOTES) . '" style="background:#0052ff;color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:100px;font-weight:600;display:inline-block">Entrar no sistema</a></p>'
        : '<p style="margin:24px 0;color:#5b616e">Acesse o sistema pelo endereço de costume para entrar.</p>';

    $horas = (int)TEMP_PASSWORD_TTL_HOURS;

    // 🔴 O rodapé do e-mail de RECUPERAÇÃO não pode dizer "se não foi você,
    // ignore: sua senha anterior continua valendo" — que é o texto padrão
    // desse tipo de mensagem e estaria MENTINDO aqui. Neste desenho a
    // temporária É a senha (não há token à parte), então ela substitui a
    // anterior no instante em que o e-mail sai: quem lê a mensagem já está
    // sem a senha antiga, e mandá-lo ignorá-la o deixa trancado do lado de
    // fora sem entender por quê.
    $rodape = $novoUser
        ? 'Se você não esperava este e-mail, avise o administrador do sistema antes de entrar.'
        : 'Se não foi você quem pediu: a senha anterior desta conta já não vale. Entre com a temporária acima e defina uma nova — e avise o administrador do sistema.';

    return '<div style="font-family:Inter,Arial,sans-serif;color:#5b616e;font-size:15px;line-height:1.6;max-width:520px">'
        . '<h2 style="color:#0a0b0d;font-size:22px;font-weight:400;margin:0 0 16px">' . htmlspecialchars($titulo) . '</h2>'
        . '<p style="margin:0 0 16px">Olá, ' . htmlspecialchars($nome) . '.</p>'
        . '<p style="margin:0 0 20px">' . $intro . '</p>'
        . '<div style="background:#f7f7f7;border:1px solid #dee1e6;border-radius:12px;padding:20px;text-align:center">'
        . '<div style="font-size:12px;font-weight:600;color:#7c828a;letter-spacing:.4px;margin-bottom:8px">SENHA TEMPORÁRIA</div>'
        . '<div style="font-family:\'JetBrains Mono\',Consolas,monospace;font-size:30px;font-weight:600;color:#0a0b0d;letter-spacing:6px">' . htmlspecialchars($senha) . '</div>'
        . '</div>'
        . $botao
        . '<p style="margin:0 0 8px;font-size:13px;color:#7c828a">Esta senha vale por ' . $horas . ' horas e serve só para esta entrada: ao acessar, o sistema pede a senha que passará a ser a sua.</p>'
        . '<p style="margin:0;font-size:13px;color:#7c828a">' . $rodape . '</p>'
        . '</div>';
}

/**
 * Gera, ENVIA e (só se o envio der certo) grava uma senha temporária.
 *
 * Efeitos no sucesso, todos dentro da mesma transação:
 *   • `users.password_hash` passa a ser o bcrypt da temporária
 *   • `must_change_password = 1`, com prazo de TEMP_PASSWORD_TTL_HOURS
 *   • `temp_password_sent_at = NOW()`
 *   • as sessões abertas daquele usuário são apagadas — quem pede recuperação
 *     perdeu o acesso; a sessão de quem estiver com o equipamento dele tem de
 *     cair junto, senão o "esqueci a senha" não expulsa ninguém.
 *
 * @param int  $userId    users.id
 * @param bool $novoUser  true = e-mail de boas-vindas (cadastro); false = recuperação
 * @returns array{ok:bool,error:?string,email:?string} `ok=false` deixa o banco intocado
 */
function issue_temp_password(int $userId, bool $novoUser = false): array
{
    $db = Database::getInstance()->getConnection();

    $stmt = $db->prepare("SELECT id, name, email, is_active FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['ok' => false, 'error' => 'Usuário não encontrado.', 'email' => null];
    }
    if (empty($user['is_active'])) {
        return ['ok' => false, 'error' => 'Usuário inativo — reative antes de enviar a senha.', 'email' => $user['email']];
    }

    // Escopo de SMTP: credenciais do cliente do usuário quando houver, senão as
    // globais (mail_config resolve a precedência sozinha).
    $cStmt = $db->prepare("SELECT customer_id FROM customer_users WHERE user_id = ? ORDER BY id LIMIT 1");
    $cStmt->execute([$userId]);
    $customerId = $cStmt->fetchColumn();
    $customerId = $customerId !== false && $customerId !== null ? (int)$customerId : null;

    $senha   = generate_temp_password();
    $assunto = $novoUser ? 'bycamera — seu acesso ao sistema' : 'bycamera — recuperação de senha';
    $corpo   = temp_password_email_body((string)$user['name'], $senha, $novoUser);

    $envio = send_mail([(string)$user['email']], $assunto, $corpo, [], $customerId);

    if (empty($envio['ok'])) {
        Logger::error('Senha temporária NÃO enviada', [
            'user_id' => $userId,
            'erro'    => $envio['error'] ?? 'desconhecido',
        ]);
        return ['ok' => false, 'error' => $envio['error'] ?? 'Falha no envio do e-mail.', 'email' => $user['email']];
    }

    // Só agora o banco muda — ver o comentário do cabeçalho.
    try {
        $db->beginTransaction();
        $upd = $db->prepare(
            "UPDATE users
                SET password_hash = :hash,
                    must_change_password = 1,
                    temp_password_expires_at = DATE_ADD(NOW(), INTERVAL :ttl HOUR),
                    temp_password_sent_at = NOW()
              WHERE id = :id"
        );
        $upd->execute([
            ':hash' => password_hash($senha, PASSWORD_BCRYPT),
            ':ttl'  => (int)TEMP_PASSWORD_TTL_HOURS,
            ':id'   => $userId,
        ]);

        $db->prepare("DELETE FROM sessions WHERE user_id = ?")->execute([$userId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        Logger::error('Senha temporária enviada mas NÃO gravada', [
            'user_id' => $userId,
            'erro'    => $e->getMessage(),
        ]);
        // O e-mail já saiu com uma senha que o banco não conhece: o usuário vai
        // tentar e falhar. Dizer isso é melhor que dizer "enviado".
        return ['ok' => false, 'error' => 'A senha foi enviada mas não pôde ser gravada. Reenvie.', 'email' => $user['email']];
    }

    Logger::info('Senha temporária enviada', [
        'user_id'  => $userId,
        'contexto' => $novoUser ? 'cadastro' : 'recuperacao',
    ]);

    return ['ok' => true, 'error' => null, 'email' => $user['email']];
}

/**
 * Registra um pedido de recuperação (alimenta o rate limit da rota pública).
 *
 * @param string $email E-mail digitado, exista ele ou não
 * @param string $ip    IP de origem
 * @returns void
 */
function password_reset_log_request(string $email, string $ip): void
{
    try {
        $db = Database::getInstance()->getConnection();
        $db->prepare("INSERT INTO password_reset_log (email, ip_address) VALUES (?, ?)")
           ->execute([mb_substr($email, 0, 255), mb_substr($ip, 0, 45)]);
    } catch (Throwable $e) {
        error_log('password_reset_log_request: ' . $e->getMessage());
    }
}

/**
 * Limite por IP da rota pública `/esqueci-senha`.
 *
 * ⚠️ Só o limite por IP vira mensagem na tela. O limite por E-MAIL
 * (`temp_password_sent_at` recente) é aplicado em silêncio, porque uma
 * mensagem do tipo "já enviamos há pouco para este endereço" confirmaria que o
 * e-mail existe no sistema — exatamente o que a resposta neutra evita.
 *
 * @param string $ip
 * @param int    $max     Pedidos permitidos na janela
 * @param int    $minutos Janela em minutos
 * @returns bool true = pode enviar
 */
function password_reset_ip_allowed(string $ip, int $max = 5, int $minutos = 60): bool
{
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM password_reset_log
              WHERE ip_address = :ip AND created_at > DATE_SUB(NOW(), INTERVAL :min MINUTE)"
        );
        $stmt->execute([':ip' => $ip, ':min' => $minutos]);
        return (int)$stmt->fetchColumn() < $max;
    } catch (Throwable $e) {
        // Sem a tabela (banco antes da migração), não trava o fluxo.
        error_log('password_reset_ip_allowed: ' . $e->getMessage());
        return true;
    }
}
