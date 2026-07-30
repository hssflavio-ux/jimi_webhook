// @ts-check
/**
 * Spec dos relatórios operacionais (v4.6.0 — Fase 3 do PLANO_IMPLEMENTACAO_v4.4-v4.7).
 *
 * As cinco telas leem o que o scripts/state_builder.php produziu, então a spec
 * não pode assumir que existe dado: um ambiente recém-migrado tem as tabelas
 * vazias até o primeiro cron rodar. O que se verifica aqui é o comportamento
 * que vale com ou sem dado — filtros, ordenação, KPIs, export, escopo — e as
 * invariantes que precisam valer QUANDO há linha na grade.
 *
 * As asserções de segmentação propriamente dita (soma de 86.400 s por dia,
 * contiguidade, dedupe na reexecução) são feitas contra o banco, não pela UI.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** As cinco telas da fase, com o que cada uma tem de mostrar. */
const TELAS = [
    { path: '/relatorios/paradas',      titulo: 'Relatório de Paradas',                unidade: 'paradas' },
    { path: '/relatorios/ociosidade',   titulo: 'Relatório de Ociosidade',             unidade: 'períodos ociosos' },
    { path: '/relatorios/ignicao',      titulo: 'Relatório de Ignição',                unidade: 'acionamentos' },
    { path: '/relatorios/velocidade',   titulo: 'Relatório de Excesso de Velocidade',  unidade: 'infrações' },
    { path: '/relatorios/status-frota', titulo: 'Status da Frota',                     unidade: 'equipamentos' },
];

test.describe('Relatórios operacionais — estrutura das telas', () => {
    for (const { path, titulo } of TELAS) {
        test(`${path} renderiza título, filtros e grade`, async ({ authedPage }) => {
            const resp = await authedPage.goto(path);
            expect(resp.status()).toBeLessThan(500);

            await expect(authedPage.locator('h2')).toContainText(titulo);
            // Barra de filtros com botão Gerar
            await expect(authedPage.locator('form button:has-text("Gerar")')).toBeVisible();
            // Grade sempre presente (com dado ou com a linha de vazio)
            await expect(authedPage.locator('table')).toBeVisible();
            // Botões de export
            await expect(authedPage.locator('a:has-text("Exportar Excel")')).toBeVisible();
            await expect(authedPage.locator('a:has-text("Exportar PDF")')).toBeVisible();

            const body = await authedPage.locator('body').innerText();
            expect(body).not.toMatch(/Fatal error|Parse error|Uncaught (Error|Exception)/);
        });
    }
});

test.describe('Relatórios operacionais — filtros', () => {
    test('paradas: filtro de duração mínima entra na URL e é preservado', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/paradas');
        await authedPage.selectOption('select[name="min_minutes"]', '30');
        await authedPage.click('button:has-text("Gerar")');
        await expect(authedPage).toHaveURL(/min_minutes=30/);
        // O valor escolhido tem de voltar selecionado — filtro que se perde na
        // submissão é o defeito clássico desta tela
        await expect(authedPage.locator('select[name="min_minutes"]')).toHaveValue('30');
    });

    test('velocidade: filtro de excedente mínimo é preservado', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/velocidade');
        await authedPage.selectOption('select[name="min_over"]', '20');
        await authedPage.click('button:has-text("Gerar")');
        await expect(authedPage).toHaveURL(/min_over=20/);
        await expect(authedPage.locator('select[name="min_over"]')).toHaveValue('20');
    });

    test('ignição: filtro de evento é preservado', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/ignicao');
        await authedPage.selectOption('select[name="event"]', 'desligada');
        await authedPage.click('button:has-text("Gerar")');
        await expect(authedPage).toHaveURL(/event=desligada/);
        await expect(authedPage.locator('select[name="event"]')).toHaveValue('desligada');
    });

    test('período acima de 31 dias é ajustado com aviso', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/paradas?date_from=2020-01-01&date_to=2026-12-31');
        await expect(authedPage.locator('body')).toContainText('ajustado para o máximo');
    });

    test('botão Voltar aparece só quando há filtro aplicado', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/paradas');
        await expect(authedPage.locator('a:has-text("Voltar")')).toHaveCount(0);
        await authedPage.goto('/relatorios/paradas?min_minutes=15');
        await expect(authedPage.locator('a:has-text("Voltar")')).toBeVisible();
    });
});

test.describe('Status da Frota', () => {
    test('os quatro estados somam o total da frota ativa', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/status-frota');

        // Os 5 cartões: 4 estados + total. A soma dos 4 tem de dar o 5º —
        // é o critério de aceite da tela, e o que quebra se a classificação
        // deixar algum equipamento sem estado.
        const nums = await authedPage.locator('.card .text-mono').allInnerTexts();
        const inteiros = nums
            .map((t) => t.trim())
            .filter((t) => /^\d+$/.test(t))
            .map(Number);

        expect(inteiros.length).toBeGreaterThanOrEqual(5);
        const [movimento, ocioso, parado, offline, total] = inteiros;
        expect(movimento + ocioso + parado + offline).toBe(total);
    });

    test('cartão de estado filtra a grade (drill-down)', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/status-frota');
        await authedPage.locator('a.card', { hasText: 'Sem comunicação' }).click();
        await expect(authedPage).toHaveURL(/state=offline/);
        await expect(authedPage.locator('select[name="state"]')).toHaveValue('offline');

        // Toda linha da grade filtrada tem de estar no estado escolhido
        const linhas = authedPage.locator('tbody tr');
        const n = await linhas.count();
        for (let i = 0; i < Math.min(n, 5); i++) {
            const txt = await linhas.nth(i).innerText();
            if (txt.includes('Nenhum equipamento')) continue;
            expect(txt).toContain('Sem comunicação');
        }
    });
});

test.describe('Relatórios operacionais — export', () => {
    for (const { path } of TELAS) {
        test(`${path} exporta XLSX`, async ({ authedPage }) => {
            await authedPage.goto(path);
            const download = authedPage.waitForEvent('download', { timeout: 20000 });
            await authedPage.click('a:has-text("Exportar Excel")');
            const file = await download;
            expect(file.suggestedFilename()).toMatch(/\.xlsx$/);
        });
    }
});

test.describe('Relatórios operacionais — coerência entre telas', () => {
    test('desligamentos de ignição batem com o número de paradas', async ({ authedPage }) => {
        // O relatório de Ignição publica os dois números lado a lado
        // justamente para que a divergência salte aos olhos.
        await authedPage.goto('/relatorios/ignicao');
        const body = await authedPage.locator('body').innerText();

        const desligadas = body.match(/Ignições desligadas\s*\n?\s*(\d+)/);
        const paradas = body.match(/há\s+(\d+)\s+período/);

        // Sem dado no período os dois blocos não aparecem — nada a comparar.
        test.skip(!desligadas || !paradas, 'sem dado de segmentação no período');

        // Podem diferir em no máximo 1: o veículo que entrou no período já
        // desligado tem o segmento de parada sem a transição que o abriu.
        const diff = Math.abs(Number(desligadas[1]) - Number(paradas[1]));
        expect(diff).toBeLessThanOrEqual(1);
    });

    test('a sidebar lista as cinco telas novas no grupo Relatórios', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/paradas');
        for (const { path } of TELAS) {
            await expect(authedPage.locator(`.sidebar a[href="${path}"]`)).toHaveCount(1);
        }
    });
});
