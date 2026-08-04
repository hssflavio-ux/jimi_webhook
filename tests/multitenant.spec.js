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

/**
 * Guarda de não-vacuidade, e ela não é decorativa.
 *
 * `imeisVisiveis()` identifica IMEI por REGEX DE DÍGITOS. Enquanto o cliente B
 * teve só devices de IMEI alfanumérico, o conjunto dele voltava VAZIO — e
 * "A e B não compartilham devices" passava sozinho, porque dois conjuntos
 * vazios nunca se intersectam. O spec dizia "verde" sem ter testado nada.
 *
 * `tests/helpers/seed_tenants.php` garante um IMEI de 15 dígitos em cada
 * cliente; esta função garante que alguém percebeu se isso deixar de valer.
 *
 * @param {Set<string>} imeis
 * @param {string} quem
 */
function exigeDevices(imeis, quem) {
    expect(imeis.size,
        `${quem} não enxerga NENHUM IMEI de 15 dígitos — rode "php tests/helpers/seed_tenants.php aplicar". `
        + 'Sem isso as asserções de isolamento passam por vacuidade.')
        .toBeGreaterThan(0);
}

test('cliente A e cliente B não compartilham devices', async ({ browser }) => {
    const imeisA = await imeisVisiveis(browser, CREDS);
    const imeisB = await imeisVisiveis(browser, CREDS_B);

    exigeDevices(imeisA, 'cliente A');
    exigeDevices(imeisB, 'cliente B');

    const vazamento = [...imeisA].filter((imei) => imeisB.has(imei));
    expect(vazamento, `IMEIs visíveis para ambos os clientes: ${vazamento.join(', ')}`).toHaveLength(0);
});

test('sem autenticação, /camerasdata não expõe dados', async ({ request }) => {
    const resp = await request.get(BASE_URL + '/camerasdata');
    const text = await resp.text();
    expect(text).not.toMatch(/"imei"\s*:\s*"?\d{10,20}/);
});

/**
 * Regressão da v4.7.3 — escalada de tenant por query string.
 *
 * Nove telas obedeciam a `?customer_id=N` vindo de QUALQUER usuário, não só do
 * admin: o parâmetro que existia para o admin escolher um cliente era, na
 * prática, um seletor livre de tenant. Um `operator` do cliente B lia alarmes,
 * equipamentos e status de frota do cliente A só mudando a URL.
 *
 * ⚠️ Este spec (e todo este arquivo) **pula** sem TEST_EMAIL_B/TEST_PASSWORD_B.
 * Foi exatamente por isso que a falha sobreviveu: o arquivo existe desde a Fase
 * M.4, mas o segundo cliente nunca foi provisionado, então ele nunca rodou.
 * Provisionar esse usuário vale mais do que qualquer teste novo escrito aqui.
 */
test('?customer_id na URL não dá acesso a outro cliente', async ({ browser }) => {
    // 4 telas × 3 ids = 12 relatórios completos, sequenciais (a suíte roda com
    // 1 worker porque o servidor embutido do PHP é single-thread). Não cabe no
    // timeout global de 45 s, e estourá-lo é pior do que parece: o teste termina
    // em "Test ended" no meio do laço, que se lê como falha de aplicação quando
    // é só orçamento de tempo — e, pior, deixaria de exercitar as telas do fim.
    test.setTimeout(180000);

    const imeisB = await imeisVisiveis(browser, CREDS_B);
    exigeDevices(imeisB, 'cliente B');

    const context = await browser.newContext({ baseURL: BASE_URL });
    const page = await context.newPage();
    await loginViaUI(page, CREDS_B.email, CREDS_B.password);

    // Varre os ids de cliente plausíveis; nenhum pode revelar IMEI que não seja
    // do próprio cliente B.
    const telas = [
        '/relatorios/alarmes',
        '/relatorios/desatualizados',
        '/relatorios/status-frota',
        '/equipamentos',
    ];
    for (const rota of telas) {
        for (const cid of [1, 2, 3]) {
            const resp = await page.request.get(`${rota}?customer_id=${cid}`);
            const html = await resp.text();

            // Sem esta guarda, uma tela quebrada passaria por não conter IMEI
            expect(html.length, `${rota} devolveu página vazia — asserção seria vácua`)
                .toBeGreaterThan(500);

            const vistos = [...html.matchAll(/\b(\d{15})\b/g)].map((m) => m[1]);
            const alheios = vistos.filter((imei) => !imeisB.has(imei));
            expect(alheios, `${rota}?customer_id=${cid} expôs IMEI de outro cliente: ${alheios.join(', ')}`)
                .toHaveLength(0);
        }
    }
    await context.close();
});
