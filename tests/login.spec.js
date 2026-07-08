// @ts-check
/**
 * Specs de login (Fase M.4 §4.3): render, senha errada, redirect e
 * rate limiting (opt-in — bloqueia o IP por 15 min).
 */
const { test, expect, CREDS, hasCreds, loginViaUI, BASE_URL } = require('./fixtures/auth');

test.describe('Login', () => {
    test('página de login renderiza com formulário', async ({ page }) => {
        await page.goto('/login');
        await expect(page.locator('h1')).toContainText('Entrar');
        await expect(page.locator('#email')).toBeVisible();
        await expect(page.locator('#password')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });

    test.describe('com credenciais', () => {
        test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

        test('senha errada exibe erro e não autentica', async ({ page }) => {
            await page.goto('/login');
            await page.fill('#email', CREDS.email);
            await page.fill('#password', 'senha-incorreta-' + Date.now());
            await page.click('button[type="submit"]');
            await expect(page.locator('.alert-error')).toBeVisible();
            await expect(page).toHaveURL(/\/login/);
        });

        test('login correto redireciona para / com sidebar', async ({ page }) => {
            await loginViaUI(page, CREDS.email, CREDS.password);
            await expect(page.locator('.sidebar-brand-name')).toHaveText('JIMI');
            await expect(page.locator('.main-header')).toBeVisible();
        });

        test('parâmetro redirect é respeitado (path local)', async ({ page }) => {
            await page.goto('/login?redirect=/ativos');
            await page.fill('#email', CREDS.email);
            await page.fill('#password', CREDS.password);
            await Promise.all([
                page.waitForURL('**/ativos', { timeout: 15000 }),
                page.click('button[type="submit"]'),
            ]);
        });

        test('open redirect é bloqueado (R05)', async ({ page }) => {
            await page.goto('/login?redirect=//evil.example.com');
            await page.fill('#email', CREDS.email);
            await page.fill('#password', CREDS.password);
            await page.click('button[type="submit"]');
            await page.waitForURL((url) => !url.pathname.startsWith('/login'));
            expect(new URL(page.url()).origin).toBe(new URL(BASE_URL).origin);
        });
    });

    test.describe('rate limiting', () => {
        // DESTRUTIVO: 5 falhas bloqueiam o IP por 15 minutos (login_log).
        test.skip(process.env.RATE_LIMIT_TEST !== '1', 'opt-in: RATE_LIMIT_TEST=1');

        test('6ª tentativa é bloqueada', async ({ page }) => {
            for (let i = 0; i < 5; i++) {
                await page.goto('/login');
                await page.fill('#email', 'ratelimit-e2e@invalido.local');
                await page.fill('#password', 'errada-' + i);
                await page.click('button[type="submit"]');
                await expect(page.locator('.alert-error')).toBeVisible();
            }
            await page.goto('/login');
            await page.fill('#email', 'ratelimit-e2e@invalido.local');
            await page.fill('#password', 'errada-final');
            await page.click('button[type="submit"]');
            await expect(page.locator('.alert-error')).toContainText('Muitas tentativas');
        });
    });
});
