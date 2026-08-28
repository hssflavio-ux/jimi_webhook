// @ts-check
/**
 * Specs do fluxo de senha temporária (v4.13.21).
 *
 * ⚠️ O que NÃO está aqui, e por quê:
 *
 * 1. Pedir recuperação para um e-mail que EXISTE. O fluxo funciona: geraria
 *    uma senha nova e invalidaria a senha do usuário de teste — derrubando
 *    `TEST_PASSWORD` e, com ela, todos os outros 40 specs da suíte. A prova de
 *    que a resposta é idêntica nos dois casos está na leitura de
 *    `handlers/esqueci_senha.php` (uma única constante MSG_NEUTRA, atribuída
 *    fora de qualquer ramo que consulte o banco), não num teste que se
 *    autodestrói.
 *
 * 2. A trava de `require_password_change_gate()` com a flag LIGADA. Exige um
 *    usuário semeado com `must_change_password=1`, e criá-lo pela tela dispara
 *    e-mail de verdade para um endereço inventado. Fica para verificação
 *    manual no homolog, registrada no CHANGELOG da v4.13.21.
 */
const { test, expect, CREDS, hasCreds, loginViaUI } = require('./fixtures/auth');

test.describe('Senha temporária — recuperação de acesso', () => {
    test('/login oferece o caminho de recuperação', async ({ page }) => {
        await page.goto('/login');
        const link = page.locator('a[href="/esqueci-senha"]');
        await expect(link).toBeVisible();
        await expect(link).toContainText(/esqueci/i);
    });

    test('/esqueci-senha renderiza o formulário público', async ({ page }) => {
        await page.goto('/esqueci-senha');
        await expect(page.locator('h1')).toContainText('Recuperar acesso');
        await expect(page.locator('#email')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
        // Rota pública: não pode ter caído no login.
        await expect(page).toHaveURL(/\/esqueci-senha/);
    });

    test('e-mail inexistente recebe a MESMA resposta neutra, sem vazar existência', async ({ page }) => {
        await page.goto('/esqueci-senha');
        await page.fill('#email', `nao-existe-${Date.now()}@exemplo.invalid`);
        await page.click('button[type="submit"]');

        const alerta = page.locator('.alert-success');
        await expect(alerta).toBeVisible();
        await expect(alerta).toContainText(/senha temporária/i);
        // O limite por e-mail é silencioso (senão revelaria que a conta
        // existe), então a mensagem PRECISA explicar o "pedi e não chegou" —
        // sem isso, quem pede duas vezes seguidas conclui que está quebrado.
        // Medido com o dono do produto em 28/08/2026.
        await expect(alerta).toContainText(/5 minutos/i);

        // 🔴 O coração do teste: a tela pública não pode virar um verificador de
        // quem tem conta no sistema.
        const corpo = (await page.locator('body').innerText()).toLowerCase();
        expect(corpo).not.toContain('não encontrado');
        expect(corpo).not.toContain('nao encontrado');
        expect(corpo).not.toContain('não existe');
        expect(corpo).not.toContain('não cadastrado');
    });

    test('/trocar-senha sem sessão manda para o login', async ({ page }) => {
        await page.goto('/trocar-senha');
        await expect(page).toHaveURL(/\/login/);
    });

    test.describe('com credenciais', () => {
        test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

        test('usuário SEM senha temporária não fica preso em /trocar-senha', async ({ page }) => {
            await loginViaUI(page, CREDS.email, CREDS.password);
            // A trava vale só para quem tem a flag; para os demais a tela
            // redireciona para /perfil, onde a troca voluntária exige a senha atual.
            await page.goto('/trocar-senha');
            await expect(page).toHaveURL(/\/perfil/);
            await expect(page.locator('input[name="current_password"]')).toBeVisible();
        });

        test('login normal não é desviado pela trava', async ({ page }) => {
            await loginViaUI(page, CREDS.email, CREDS.password);
            await page.goto('/ativos');
            await expect(page).toHaveURL(/\/ativos/);
        });
    });
});
