// @ts-check
/**
 * Fixture de autenticação (Fase M.4).
 *
 * Faz login via UI uma única vez por worker e compartilha o contexto
 * autenticado (cookie jimi_token) entre os testes via `authedPage`.
 */
const base = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8000';

const CREDS = {
    email: process.env.TEST_EMAIL || '',
    password: process.env.TEST_PASSWORD || '',
};
const CREDS_B = {
    email: process.env.TEST_EMAIL_B || '',
    password: process.env.TEST_PASSWORD_B || '',
};

const hasCreds = () => Boolean(CREDS.email && CREDS.password);
const hasCredsB = () => Boolean(CREDS_B.email && CREDS_B.password);

/**
 * Login via formulário /login. Lança erro se as credenciais forem recusadas.
 * @param {import('@playwright/test').Page} page
 * @param {string} email
 * @param {string} password
 */
async function loginViaUI(page, email, password) {
    await page.goto(BASE_URL + '/login');
    await page.fill('#email', email);
    await page.fill('#password', password);
    await Promise.all([
        page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);
}

const test = base.test.extend({
    /** Contexto autenticado, criado uma vez por worker. */
    authedContext: [async ({ browser }, use) => {
        if (!hasCreds()) {
            throw new Error('authedContext requer TEST_EMAIL/TEST_PASSWORD — proteja o spec com test.skip(!hasCreds())');
        }
        const context = await browser.newContext({ baseURL: BASE_URL });
        const page = await context.newPage();
        await loginViaUI(page, CREDS.email, CREDS.password);
        await page.close();
        await use(context);
        await context.close();
    }, { scope: 'worker' }],

    /** Página nova dentro do contexto autenticado. */
    authedPage: async ({ authedContext }, use) => {
        const page = await authedContext.newPage();
        await use(page);
        await page.close();
    },
});

module.exports = {
    test,
    expect: base.expect,
    BASE_URL,
    CREDS,
    CREDS_B,
    hasCreds,
    hasCredsB,
    loginViaUI,
};
