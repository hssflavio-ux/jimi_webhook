// @ts-check
/**
 * Central de Ajuda:
 * - v4.22.0: todo link do índice lateral leva a uma âncora que existe na
 *   página — cobre seção liberada e stub bloqueado.
 * - v4.23.0: card de abertura por perfil e seção "Meu acesso".
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

test('wiki: todo link do índice tem âncora correspondente', async ({ authedPage }) => {
    await authedPage.goto('/wiki');
    const hrefs = await authedPage.$$eval('#wikiToc a', (as) => as.map((a) => a.getAttribute('href') || ''));
    expect(hrefs.length).toBeGreaterThan(10);
    for (const h of hrefs) {
        expect(await authedPage.locator(h).count(), `âncora ${h}`).toBe(1);
    }
});

test('wiki: mostra o card do perfil e o "Meu acesso"', async ({ authedPage }) => {
    await authedPage.goto('/wiki');
    await expect(authedPage.locator('.wiki-card')).toContainText('Seu perfil:');
    await expect(authedPage.locator('#meu-acesso')).toHaveCount(1);
});
