// @ts-check
/**
 * Mapa de Risco (/mapa-risco, v4.20.0).
 *
 * O que trava:
 *  - as cinco abas renderizam sem erro de PHP/SQL — cada aba roda consultas
 *    próprias, e uma falha numa não aparece nas outras;
 *  - o teto de 90 dias é aplicado e AVISADO (é teto próprio desta tela, não o
 *    de 31 dias dos relatórios);
 *  - a exportação sai da mesma estrutura de tabelas da tela;
 *  - o item de menu existe.
 *
 * Não cobre: permissão negada a grupo sem `bi` (exige usuário e grupo de teste
 * dedicados) nem os números — esses estão em tests/helpers/risk_map.test.php.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

const ABAS = ['onde', 'quando', 'jornada', 'quem', 'tendencia'];

test.describe('Mapa de Risco', () => {
    for (const aba of ABAS) {
        test(`aba ${aba} renderiza sem erro`, async ({ authedPage: page }) => {
            const resp = await page.goto(`/mapa-risco?aba=${aba}`);
            expect(resp?.status()).toBe(200);
            await expect(page.locator('h2', { hasText: 'Mapa de Risco' })).toBeVisible();
            await expect(page.locator(`.mr-tabs a[data-aba="${aba}"]`)).toHaveClass(/ativa/);
            const html = await page.content();
            expect(html).not.toMatch(/Fatal error|Uncaught|SQLSTATE|Não foi possível montar esta análise/);
        });
    }

    test('período acima de 90 dias é encurtado e avisado', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco?date_from=2026-01-01&date_to=2026-06-30');
        await expect(page.locator('input[name="date_to"]')).toHaveValue('2026-03-31');
        await expect(page.getByText('máximo de 90 dias')).toBeVisible();
    });

    test('aba desconhecida cai em Onde', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco?aba=nada');
        await expect(page.locator('.mr-tabs a[data-aba="onde"]')).toHaveClass(/ativa/);
    });

    test('exportação CSV da aba Quando traz recorte e índice', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco?aba=quando');
        const csv = await page.evaluate(async () => {
            const r = await fetch('/mapa-risco?aba=quando&export=csv');
            return { status: r.status, text: await r.text() };
        });
        expect(csv.status).toBe(200);
        const header = csv.text.replace(/^﻿/, '').split(/\r?\n/)[0];
        expect(header.split(';')[0]).toBe('Recorte');
        expect(header).toContain('Índice (pts/h)');
        // Faixa do dia (4) + dia da semana (7): a tela sempre desenha as 11 linhas.
        const linhas = csv.text.trim().split(/\r?\n/).slice(1);
        expect(linhas.length).toBe(11);
    });

    test('item de menu leva à tela', async ({ authedPage: page }) => {
        await page.goto('/bi');
        await expect(page.locator('a[href="/mapa-risco"]').first()).toBeVisible();
    });
});

/**
 * Filtros de veículo e comportamento (v4.25.0): 1, vários ou todos.
 *
 * ⚠️ Comportamento só é OFERECIDO se já foi recebido — a lista sai de
 * risk_events. Para não depender do que o banco de teste tem, estes testes
 * passam os comportamentos na URL: um comportamento SELECIONADO sempre entra
 * na lista, de modo que há garantidamente itens DMS e ADAS para clicar.
 */
const DMS_ADAS = 'risk_group=fadiga,cinto,uso_celular,distracao,bocejo,fumando,colisao_frontal,saida_faixa';

/** Seções (data-grupo) dos itens marcados no painel de comportamento. */
const gruposMarcados = (page) => page.evaluate(() =>
    [...document.querySelectorAll('#msel-mr-comportamentos .msel-item input:checked')]
        .map((i) => i.closest('.msel-item').dataset.grupo));

test.describe('Mapa de Risco — filtros multisseleção', () => {
    test('veículo e comportamento são listas suspensas múltiplas, com o parâmetro de sempre', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco');
        await expect(page.locator('#msel-mr-veiculos')).toBeVisible();
        await expect(page.locator('#msel-mr-comportamentos')).toBeVisible();
        // O contrato de saída não mudou: mesmo nome de parâmetro, valores por vírgula.
        expect(await page.locator('#msel-mr-veiculos .msel-hidden').getAttribute('name')).toBe('vehicle_id');
        expect(await page.locator('#msel-mr-comportamentos .msel-hidden').getAttribute('name')).toBe('risk_group');
        // E o antigo <select> único não existe mais.
        await expect(page.locator('select[name="vehicle_id"], select[name="risk_group"]')).toHaveCount(0);
    });

    test('a lista de comportamento tem as seções DMS e ADAS', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco?' + DMS_ADAS);
        await page.locator('#msel-mr-comportamentos .msel-botao').click();
        const secoes = await page.locator('#msel-mr-comportamentos .msel-grupo').evaluateAll((els) => els.map((e) => e.dataset.grupo));
        expect(secoes).toEqual(['DMS', 'ADAS']);
    });

    test('"todos" de cada seção marca só ela, e qualquer mistura vale', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco?' + DMS_ADAS);
        const raiz = page.locator('#msel-mr-comportamentos');
        await raiz.locator('.msel-botao').click();
        await raiz.locator('.msel-acoes button', { hasText: /limpar/i }).click();
        expect(await raiz.locator('.msel-hidden').inputValue()).toBe('');
        await expect(raiz.locator('.txt')).toHaveText(/todos/i);

        // Só DMS.
        await raiz.locator('.msel-grupo[data-grupo="DMS"] button', { hasText: /todos/i }).click();
        let g = await gruposMarcados(page);
        expect(g.length, 'marcou algum DMS').toBeGreaterThan(0);
        expect(new Set(g)).toEqual(new Set(['DMS']));

        // DMS + ADAS.
        await raiz.locator('.msel-grupo[data-grupo="ADAS"] button', { hasText: /todos/i }).click();
        g = await gruposMarcados(page);
        expect(new Set(g)).toEqual(new Set(['DMS', 'ADAS']));

        // Tira o DMS: sobra só ADAS.
        await raiz.locator('.msel-grupo[data-grupo="DMS"] button', { hasText: /nenhum/i }).click();
        g = await gruposMarcados(page);
        expect(g.length).toBeGreaterThan(0);
        expect(new Set(g)).toEqual(new Set(['ADAS']));

        // A saída é o hidden, no mesmo parâmetro.
        const valor = (await raiz.locator('.msel-hidden').inputValue()).split(',').filter(Boolean);
        expect(valor).toContain('colisao_frontal');
        expect(valor).not.toContain('fadiga');
    });

    test('marcar item solto de seções diferentes serializa os dois', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco?' + DMS_ADAS);
        const raiz = page.locator('#msel-mr-comportamentos');
        await raiz.locator('.msel-botao').click();
        await raiz.locator('.msel-acoes button', { hasText: /limpar/i }).click();
        await raiz.locator('.msel-item input[value="fadiga"]').check();
        await raiz.locator('.msel-item input[value="colisao_frontal"]').check();
        await expect(raiz.locator('.txt')).toHaveText(/2 selecionados/);
        expect((await raiz.locator('.msel-hidden').inputValue()).split(',').sort()).toEqual(['colisao_frontal', 'fadiga']);
    });

    test('a busca não deixa cabeçalho de seção sozinho, e "todos" respeita a busca', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco?' + DMS_ADAS);   // 8 itens: a busca aparece
        const raiz = page.locator('#msel-mr-comportamentos');
        await raiz.locator('.msel-botao').click();
        await raiz.locator('.msel-acoes button', { hasText: /limpar/i }).click();

        await raiz.locator('.msel-busca').fill('colis');
        await expect(raiz.locator('.msel-grupo[data-grupo="DMS"]')).toBeHidden();
        await expect(raiz.locator('.msel-grupo[data-grupo="ADAS"]')).toBeVisible();

        // Com a busca em curso, o "todos" da seção só marca o que está à vista.
        await raiz.locator('.msel-grupo[data-grupo="ADAS"] button', { hasText: /todos/i }).click();
        expect(await raiz.locator('.msel-hidden').inputValue()).toBe('colisao_frontal');

        await raiz.locator('.msel-busca').fill('');
        await expect(raiz.locator('.msel-grupo[data-grupo="DMS"]')).toBeVisible();
    });

    test('link antigo com valor único continua valendo', async ({ authedPage: page }) => {
        const resp = await page.goto('/mapa-risco?risk_group=fadiga');
        expect(resp?.status()).toBe(200);
        // Um selecionado: o botão diz QUAL, e o hidden leva só ele.
        await expect(page.locator('#msel-mr-comportamentos .txt')).toHaveText('Fadiga');
        expect(await page.locator('#msel-mr-comportamentos .msel-hidden').inputValue()).toBe('fadiga');
    });

    test('valor inválido é ignorado em vez de virar filtro que zera tudo', async ({ authedPage: page }) => {
        const resp = await page.goto('/mapa-risco?risk_group=nao_existe&vehicle_id=abc');
        expect(resp?.status()).toBe(200);
        await expect(page.locator('#msel-mr-comportamentos .txt')).toHaveText(/todos/i);
        await expect(page.locator('#msel-mr-veiculos .txt')).toHaveText(/todos/i);
        expect(await page.content()).not.toMatch(/Fatal error|Uncaught|SQLSTATE|Não foi possível montar esta análise/);
    });

    test('vários veículos: marca, envia o formulário e volta marcado', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco');
        const raiz = page.locator('#msel-mr-veiculos');
        await raiz.locator('.msel-botao').click();
        const itens = raiz.locator('.msel-item input');
        test.skip((await itens.count()) < 2, 'precisa de pelo menos 2 veículos no cadastro');

        const a = await itens.nth(0).getAttribute('value');
        const b = await itens.nth(1).getAttribute('value');
        await itens.nth(0).check();
        await itens.nth(1).check();
        await expect(raiz.locator('.txt')).toHaveText(/2 selecionados/);

        await page.locator('form button[type="submit"]', { hasText: 'Gerar' }).click();
        await page.waitForLoadState('domcontentloaded');

        expect(new URL(page.url()).searchParams.get('vehicle_id')?.split(',').sort()).toEqual([a, b].sort());
        await expect(page.locator('#msel-mr-veiculos .txt')).toHaveText(/2 selecionados/);
        expect(await page.content()).not.toMatch(/Fatal error|Uncaught|SQLSTATE|Não foi possível montar esta análise/);
    });

    for (const aba of ABAS) {
        test(`aba ${aba} renderiza com vários veículos e comportamentos`, async ({ authedPage: page }) => {
            const resp = await page.goto(`/mapa-risco?aba=${aba}&vehicle_id=1,2,3&${DMS_ADAS}`);
            expect(resp?.status()).toBe(200);
            expect(await page.content()).not.toMatch(/Fatal error|Uncaught|SQLSTATE|Não foi possível montar esta análise/);
        });
    }

    test('a exportação respeita os filtros múltiplos', async ({ authedPage: page }) => {
        await page.goto('/mapa-risco?aba=quando');
        const csv = await page.evaluate(async (q) => {
            const r = await fetch('/mapa-risco?aba=quando&export=csv&vehicle_id=1,2&' + q);
            return { status: r.status, text: await r.text() };
        }, DMS_ADAS);
        expect(csv.status).toBe(200);
        expect(csv.text.trim().split(/\r?\n/).length, 'as 11 linhas de sempre').toBe(12);
    });
});
