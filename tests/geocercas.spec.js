// @ts-check
/**
 * Spec de Geocercas (v4.5.0 — Fase 2 do PLANO_IMPLEMENTACAO_v4.4-v4.7).
 *
 * Cobre o ciclo completo: desenhar no mapa → salvar → aparecer na grade →
 * abrir o relatório → excluir. O desenho é feito clicando no mapa Leaflet,
 * que é como o usuário realmente cria a cerca — preencher os campos ocultos
 * por JS testaria o formulário, não a ferramenta.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

test.describe.serial('CRUD Geocercas', () => {
    const nome = `Cerca E2E ${Date.now()}`;
    const nomeEditado = `${nome} (editada)`;

    test('criar cerca circular desenhando no mapa', async ({ authedPage }) => {
        await authedPage.goto('/geocercas?action=nova');
        await authedPage.fill('input[name="name"]', nome);
        await authedPage.fill('input[name="radius_m"]', '350');

        // Clique no centro do mapa define o centro da cerca
        const map = authedPage.locator('#fenceMap');
        await expect(map).toBeVisible();
        await map.click({ position: { x: 250, y: 200 } });

        // O clique tem de ter preenchido os campos ocultos de geometria
        await expect(authedPage.locator('#centerLat')).not.toHaveValue('');
        await expect(authedPage.locator('#centerLng')).not.toHaveValue('');

        // Sem equipamento vinculado a cerca não é avaliada — vincula o 1º
        const firstDevice = authedPage.locator('input[name="imeis[]"]').first();
        if (await firstDevice.count()) await firstDevice.check();

        await authedPage.click('button[type="submit"]:has-text("Salvar Geocerca")');
        await expect(authedPage.locator('table')).toContainText(nome);
    });

    test('cerca aparece na grade com a geometria correta', async ({ authedPage }) => {
        await authedPage.goto('/geocercas');
        const row = authedPage.locator('tr', { hasText: nome });
        await expect(row).toBeVisible();
        await expect(row).toContainText('Círculo');
        await expect(row).toContainText('350 m');
    });

    test('editar cerca', async ({ authedPage }) => {
        await authedPage.goto('/geocercas');
        await authedPage.locator('tr', { hasText: nome }).locator('a:has-text("Editar")').click();
        await expect(authedPage).toHaveURL(/action=editar&id=\d+/);
        await authedPage.fill('input[name="name"]', nomeEditado);
        await authedPage.click('button[type="submit"]:has-text("Salvar Geocerca")');
        await expect(authedPage.locator('table')).toContainText(nomeEditado);
    });

    test('geometria inválida é recusada', async ({ authedPage }) => {
        await authedPage.goto('/geocercas?action=nova');
        await authedPage.fill('input[name="name"]', `${nome} (sem geometria)`);
        // Polígono sem nenhum vértice marcado
        await authedPage.check('input[name="shape"][value="poligono"]');
        await authedPage.click('button[type="submit"]:has-text("Salvar Geocerca")');
        await expect(authedPage.locator('.toast')).toContainText('3 pontos');
    });

    test('excluir cerca', async ({ authedPage }) => {
        await authedPage.goto('/geocercas');
        const row = authedPage.locator('tr', { hasText: nomeEditado });
        await expect(row).toBeVisible();
        authedPage.once('dialog', (dialog) => dialog.accept());
        await row.locator('a:has-text("Excluir")').click();
        await expect(authedPage.locator('table')).not.toContainText(nomeEditado);
    });
});

test.describe('Relatório de Geocercas', () => {
    test('modalidade de eventos renderiza com KPIs', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/geocercas');
        await expect(authedPage.locator('h2')).toContainText('Relatório de Geocercas');
        await expect(authedPage.locator('body')).toContainText('Entradas');
        await expect(authedPage.locator('body')).toContainText('Saídas');
        await expect(authedPage.locator('table')).toBeVisible();
    });

    test('modalidade de permanência troca as colunas', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/geocercas?view=permanencia');
        await expect(authedPage.locator('thead')).toContainText('Permanência');
        await expect(authedPage.locator('thead')).toContainText('Entrada');
        await expect(authedPage.locator('thead')).toContainText('Saída');
        const body = await authedPage.locator('body').innerText();
        expect(body).not.toMatch(/Fatal error|Parse error|Uncaught (Error|Exception)/);
    });

    test('exporta em CSV', async ({ authedPage }) => {
        const response = await authedPage.goto('/relatorios/geocercas?export=csv');
        expect(response.status()).toBeLessThan(400);
    });
});
