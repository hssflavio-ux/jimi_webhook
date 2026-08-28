<?php
/**
 * Senha temporária — geração e corpo do e-mail (v4.13.21).
 *
 * O que este teste protege, e que só se vê quebrado tarde demais:
 *
 * 1. O ALFABETO. A senha é lida de um e-mail e digitada à mão; `0`/`O` e
 *    `1`/`I` no meio dela viram chamado de suporte, não login. Uma "melhoria"
 *    futura que troque o alfabeto por `A-Za-z0-9` passa despercebida em
 *    qualquer teste de fluxo — a senha continua funcionando, só que uma parte
 *    dos usuários não consegue digitá-la.
 * 2. O nome do destinatário ESCAPADO no HTML do e-mail. `users.name` é texto
 *    livre digitado no cadastro.
 * 3. O botão que depende de `APP_URL`: sem a variável, um href relativo não
 *    abre em caixa de entrada nenhuma (lição do worker, v4.7.2).
 *
 * Uso (não precisa de banco):
 *   php tests/helpers/temp_password.test.php
 */

require_once __DIR__ . '/../../includes/password_reset.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-62s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

echo "\n── Geração da senha temporária ──\n";

$amostras = [];
for ($i = 0; $i < 2000; $i++) $amostras[] = generate_temp_password();

$tamanhosErrados = array_filter($amostras, fn($s) => strlen($s) !== 6);
checa('sempre 6 caracteres (2000 amostras)', 0, count($tamanhosErrados));

$foraDoAlfabeto = array_filter($amostras, fn($s) => strspn($s, TEMP_PASSWORD_ALPHABET) !== strlen($s));
checa('nenhum caractere fora do alfabeto', 0, count($foraDoAlfabeto));

$confundiveis = array_filter($amostras, fn($s) => strpbrk($s, 'IO01') !== false);
checa('nenhuma senha com I, O, 0 ou 1', 0, count($confundiveis));

checa('alfabeto tem 32 símbolos', 32, strlen(TEMP_PASSWORD_ALPHABET));
// Gerador travado num valor fixo passaria em tudo acima e seria catastrófico.
checa('gerador não é constante', true, count(array_unique($amostras)) > 1900);
checa('comprimento é parametrizável', 8, strlen(generate_temp_password(8)));

echo "\n── Corpo do e-mail ──\n";

putenv('APP_URL=https://bycamera.ia.br');
$html = temp_password_email_body('Fulano de Tal', 'K7M2XP', true);
checa('a senha aparece no corpo', true, strpos($html, 'K7M2XP') !== false);
checa('botão aponta para o login absoluto', true, strpos($html, 'https://bycamera.ia.br/login') !== false);
checa('diz a validade em horas', true, strpos($html, (string)TEMP_PASSWORD_TTL_HOURS . ' horas') !== false);
checa('texto de cadastro fala em conta criada', true, stripos($html, 'conta foi criada') !== false);

$htmlReset = temp_password_email_body('Fulano', 'K7M2XP', false);
checa('texto de recuperação fala em pedido', true, stripos($htmlReset, 'pedido de recuperação') !== false);
checa('recuperação NÃO diz que a conta foi criada', false, stripos($htmlReset, 'conta foi criada') !== false);

// 🔴 O rodapé padrão desse tipo de e-mail ("ignore: sua senha anterior
// continua valendo") seria MENTIRA aqui: a temporária É a senha e substitui a
// antiga no envio. Quem reintroduzir essa frase tranca o usuário do lado de
// fora com uma instrução errada — daí a checagem existir.
checa('recuperação NÃO promete que a senha antiga continua valendo',
      false, stripos($htmlReset, 'anterior continua valendo') !== false);
checa('recuperação avisa que a anterior deixou de valer',
      true, stripos($htmlReset, 'já não vale') !== false);
checa('cadastro não fala de senha anterior (não existe)',
      false, stripos($html, 'senha anterior') !== false);

$htmlXss = temp_password_email_body('<script>alert(1)</script>', 'K7M2XP', true);
checa('nome do destinatário é escapado', false, strpos($htmlXss, '<script>') !== false);

putenv('APP_URL=');
$htmlSemUrl = temp_password_email_body('Fulano', 'K7M2XP', true);
checa('sem APP_URL não sai href relativo', false, strpos($htmlSemUrl, 'href="/login"') !== false);
checa('sem APP_URL instrui por texto', true, stripos($htmlSemUrl, 'endereço de costume') !== false);

printf("\n%s — %d de %d checagens passaram\n",
    $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
