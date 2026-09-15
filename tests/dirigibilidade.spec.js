// @ts-check
/**
 * Alertas Videomonitoramento × Alarmes Dirigibilidade (v4.21.0).
 *
 * A tela de dirigibilidade reúne os eventos de condução de câmera E de
 * rastreador, e por decisão do dono do produto não tem NENHUMA função de
 * vídeo. A de videomonitoramento mantém a coluna Vídeo.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

test.describe('Relatórios de alarmes divididos (v4.21.0)', () => {
    test('menu Relatórios traz as duas telas com os rótulos novos', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        const menu = authedPage.locator('#sidebar');
        await expect(menu.locator('a[href="/relatorios/alarmes"]')).toContainText('Alertas Videomonitoramento');
        await expect(menu.locator('a[href="/relatorios/dirigibilidade"]')).toContainText('Alarmes Dirigibilidade');
    });

    test('Alertas Videomonitoramento mantém a coluna Vídeo', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        await expect(authedPage.locator('h2', { hasText: 'Alertas Videomonitoramento' })).toHaveCount(1);
        await expect(authedPage.locator('table thead th', { hasText: 'Vídeo' })).toHaveCount(1);
    });

    test('Alarmes Dirigibilidade não tem nenhuma função de vídeo', async ({ authedPage }) => {
        const resp = await authedPage.goto('/relatorios/dirigibilidade');
        expect(resp?.status()).toBe(200);
        await expect(authedPage.locator('h2', { hasText: 'Alarmes Dirigibilidade' })).toHaveCount(1);
        await expect(authedPage.locator('table thead th', { hasText: 'Vídeo' })).toHaveCount(0);
        await expect(authedPage.locator('#video-modal')).toHaveCount(0);
        await expect(authedPage.getByText('Pedir vídeo')).toHaveCount(0);
    });
});
