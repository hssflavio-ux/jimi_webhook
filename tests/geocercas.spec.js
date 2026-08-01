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

    // A exclusão é POST com CSRF desde a v4.7.2 — era `<a href="?action=excluir">`,
    // fora do alcance do csrf_verify(), que não lê da query string. O seletor
    // mudou de `a` para `button` por causa disso.
    test('excluir cerca', async ({ authedPage }) => {
        await authedPage.goto('/geocercas');
        const row = authedPage.locator('tr', { hasText: nomeEditado });
        await expect(row).toBeVisible();
        authedPage.once('dialog', (dialog) => dialog.accept());
        await row.locator('button:has-text("Excluir")').click();
        await expect(authedPage.locator('table')).not.toContainText(nomeEditado);
    });

    // Guarda de regressão da v4.7.2: a exclusão não pode voltar a ser alcançável
    // por GET. Um `<img src="/geocercas?action=excluir&id=N">` em qualquer página
    // que um usuário logado abrisse apagaria a cerca e, por CASCATA, todo o
    // histórico de eventos dela — o navegador manda o cookie de sessão sozinho.
    test('exclusão NÃO é acionável por GET', async ({ authedPage }) => {
        // Cria uma cerca só para esta asserção — desenhando no mapa, que é
        // como o usuário cria (os campos de geometria são ocultos)
        const alvo = `Cerca CSRF ${Date.now()}`;
        await authedPage.goto('/geocercas?action=nova');
        await authedPage.fill('input[name="name"]', alvo);
        await authedPage.fill('input[name="radius_m"]', '300');
        const map = authedPage.locator('#fenceMap');
        await expect(map).toBeVisible();
        await map.click({ position: { x: 250, y: 200 } });
        await expect(authedPage.locator('#centerLat')).not.toHaveValue('');
        await authedPage.click('button[type="submit"]:has-text("Salvar Geocerca")');
        await expect(authedPage.locator('table')).toContainText(alvo);

        // Descobre o id pelo formulário de exclusão da própria linha
        const id = await authedPage.locator('tr', { hasText: alvo })
            .locator('input[name="id"]').inputValue();

        // A tentativa por GET tem de ser inócua
        await authedPage.goto(`/geocercas?action=excluir&id=${id}`);
        await authedPage.goto('/geocercas');
        await expect(authedPage.locator('table')).toContainText(alvo);

        // Limpa: exclui de verdade, pelo caminho suportado
        authedPage.once('dialog', (dialog) => dialog.accept());
        await authedPage.locator('tr', { hasText: alvo })
            .locator('button:has-text("Excluir")').click();
        await expect(authedPage.locator('table')).not.toContainText(alvo);
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
        // page.goto() numa URL que dispara download falha com "Download is
        // starting" — o navegador nunca navega. A requisição tem de ser feita
        // pelo contexto (que já carrega o cookie de sessão).
        const response = await authedPage.request.get('/relatorios/geocercas?export=csv');
        expect(response.status()).toBeLessThan(400);
        expect(response.headers()['content-type']).toMatch(/csv/i);
    });
});
