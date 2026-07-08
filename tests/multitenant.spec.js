// @ts-check
/**
 * Spec de isolamento multi-tenant (Fase M.4 §4.7):
 * Cliente A não pode ver os devices do Cliente B (e vice-versa).
 *
 * Requer dois usuários de clientes DIFERENTES:
 *   TEST_EMAIL/TEST_PASSWORD (cliente A) e TEST_EMAIL_B/TEST_PASSWORD_B (cliente B).
 */
const { test, expect, hasCreds, hasCredsB, CREDS, CREDS_B, loginViaUI, BASE_URL } = require('./fixtures/auth');

test.skip(!hasCreds() || !hasCredsB(), 'defina TEST_EMAIL(_B) e TEST_PASSWORD(_B) de clientes distintos');

/**
 * Loga com as credenciais e devolve o conjunto de IMEIs visíveis via /camerasdata.
 * @param {import('@playwright/test').Browser} browser
 * @param {{email: string, password: string}} creds
 * @returns {Promise<Set<string>>}
 */
async function imeisVisiveis(browser, creds) {
    const context = await browser.newContext({ baseURL: BASE_URL });
    const page = await context.newPage();
    await loginViaUI(page, creds.email, creds.password);
    const resp = await page.request.get('/camerasdata');
    expect(resp.ok(), '/camerasdata deve responder 200 autenticado').toBeTruthy();
    const text = await resp.text();
    const imeis = new Set([...text.matchAll(/"imei"\s*:\s*"?(\d{10,20})"?/g)].map((m) => m[1]));
    await context.close();
    return imeis;
}

test('cliente A e cliente B não compartilham devices', async ({ browser }) => {
    const imeisA = await imeisVisiveis(browser, CREDS);
    const imeisB = await imeisVisiveis(browser, CREDS_B);

    const vazamento = [...imeisA].filter((imei) => imeisB.has(imei));
    expect(vazamento, `IMEIs visíveis para ambos os clientes: ${vazamento.join(', ')}`).toHaveLength(0);
});

test('sem autenticação, /camerasdata não expõe dados', async ({ request }) => {
    const resp = await request.get(BASE_URL + '/camerasdata');
    const text = await resp.text();
    expect(text).not.toMatch(/"imei"\s*:\s*"?\d{10,20}/);
});
