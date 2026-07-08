// @ts-check
/**
 * Spec CRUD de Motoristas (Fase M.4 §4.5): criar → listar → editar → remover.
 * Usa nome único por execução para não colidir com dados reais.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

test.describe.serial('CRUD Motoristas', () => {
    const nome = `Motorista E2E ${Date.now()}`;
    const nomeEditado = `${nome} (editado)`;

    test('criar motorista', async ({ authedPage }) => {
        await authedPage.goto('/motoristas');
        await authedPage.fill('input[name="name"]', nome);
        await authedPage.fill('input[name="cnh_number"]', '12345678900');
        await authedPage.selectOption('select[name="cnh_category"]', { index: 1 });
        await authedPage.click('form:has(input[name="action"][value="save"]) button[type="submit"]');
        await expect(authedPage.locator('table')).toContainText(nome);
    });

    test('editar motorista', async ({ authedPage }) => {
        await authedPage.goto('/motoristas');
        const row = authedPage.locator('tr', { hasText: nome });
        await row.locator('a:has-text("Editar")').click();
        await expect(authedPage).toHaveURL(/edit=\d+/);
        await authedPage.fill('input[name="name"]', nomeEditado);
        await authedPage.click('form:has(input[name="action"][value="save"]) button[type="submit"]');
        await expect(authedPage.locator('table')).toContainText(nomeEditado);
    });

    test('remover motorista', async ({ authedPage }) => {
        await authedPage.goto('/motoristas');
        const row = authedPage.locator('tr', { hasText: nomeEditado });
        await expect(row).toBeVisible();
        authedPage.once('dialog', (dialog) => dialog.accept());
        await row.locator('form:has(input[name="action"][value="delete"]) button, form:has(input[name="action"][value="delete"]) [type="submit"]').first().click();
        await expect(authedPage.locator('table')).not.toContainText(nomeEditado);
    });
});
