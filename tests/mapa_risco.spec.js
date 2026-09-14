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
