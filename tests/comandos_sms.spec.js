// @ts-check
/**
 * Spec da tela de Comandos por SMS (v4.14.0).
 *
 * Cobre o que distingue este canal do /comandos, e cada asserção existe por um
 * modo de falha concreto:
 *
 *  1. **O texto é a forma de PLATAFORMA.** Se alguém "consertar" o catálogo para
 *     a forma de SMS da wiki (`CMD#666666#A#B`), o equipamento recusa TODOS os
 *     comandos — e o crédito é gasto do mesmo jeito.
 *
 *  2. **Equipamento sem número aparece, desabilitado, com o motivo.** Escondê-lo
 *     faria a lista mentir por omissão: o operador procuraria o veículo e
 *     concluiria que ele não existe.
 *
 *  3. **A trava de modelo pesa mais aqui.** Por SMS não há callback dizendo
 *     "comando não suportado" — o equipamento ignora e o crédito já foi gasto.
 *
 *  4. **Nada é enviado sem seleção e sem parâmetro preenchido.** O botão é a
 *     única barreira antes de gastar dinheiro.
 *
 * ⚠️ Nenhum teste aqui dispara SMS de verdade: todos param no estado do botão e
 * do preview. Enviar consumiria crédito real a cada execução da suíte.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** Lê o catálogo que a página embute, sem depender da grade renderizada. */
async function catalogo(page) {
    return await page.evaluate(() => window.CATALOGO_SMS || []);
}

test.describe('Comandos por SMS', () => {

    test('a tela abre com catálogo, saldo e lista de equipamentos', async ({ authedPage }) => {
        await authedPage.goto('/comandos-sms');

        const cat = await catalogo(authedPage);
        expect(cat.length).toBeGreaterThan(50);   // é o catálogo inteiro, não um subconjunto

        // 🔴 A regra que define o canal: forma de PLATAFORMA, nunca a de SMS da
        // wiki. Uma única sintaxe com 666666 aqui quebra todos os envios.
        expect(cat.some(c => /666666/.test(c.s))).toBe(false);

        // O saldo é consultado a cada abertura — ou o número, ou o motivo de
        // não ter vindo. O que não pode é a área ficar muda.
        const bloco = authedPage.locator('.card').first();
        await expect(bloco).toContainText(/Saldo|saldo/);
    });

    test('equipamento sem número de chip aparece desabilitado E com o motivo', async ({ authedPage }) => {
        await authedPage.goto('/comandos-sms');

        const bloqueadas = authedPage.locator('tr.linha-bloqueada');
        const n = await bloqueadas.count();

        for (let i = 0; i < n; i++) {
            const tr = bloqueadas.nth(i);
            // Desabilitado de verdade — não apenas "parece" apagado.
            await expect(tr.locator('input.sel-dev')).toBeDisabled();
            // E o motivo tem de estar escrito, com a saída (link para /chips).
            await expect(tr).toContainText(/sem número cadastrado|número do chip inválido/);
            await expect(tr.locator('a[href="/chips"]')).toHaveCount(1);
        }
    });

    test('a trava de modelo desabilita equipamentos de outro modelo', async ({ authedPage }) => {
        await authedPage.goto('/comandos-sms');

        const modelos = await authedPage.$$eval('tbody tr[data-imei]', rows =>
            [...new Set(rows.map(r => r.dataset.modelo))]);
        test.skip(modelos.length < 2, 'precisa de ao menos dois modelos no escopo');

        const cat = await catalogo(authedPage);
        // Um comando NÃO universal, preso a um modelo presente na tela.
        const idx = cat.findIndex(c => !c.u && c.m.length && c.m.some(m => modelos.includes(m)));
        test.skip(idx < 0, 'nenhum comando específico de modelo aplicável');

        await authedPage.selectOption('#f-cmd', String(idx));

        const alvo = cat[idx].m;
        const estado = await authedPage.$$eval('tbody tr[data-imei]', rows =>
            rows.map(r => ({
                modelo: r.dataset.modelo,
                travado: r.classList.contains('modelo-travado'),
                semNumero: r.classList.contains('linha-bloqueada'),
                desabilitado: r.querySelector('.sel-dev').disabled,
            })));

        for (const e of estado) {
            if (!alvo.includes(e.modelo)) {
                expect(e.travado, `${e.modelo} devia estar travado`).toBe(true);
                expect(e.desabilitado).toBe(true);
            } else if (!e.semNumero) {
                // Modelo compatível E com número → tem de continuar clicável.
                expect(e.desabilitado, `${e.modelo} não devia estar travado`).toBe(false);
            }
        }
    });

    test('a trava de modelo não reabilita quem está sem número de chip', async ({ authedPage }) => {
        // Dois motivos independentes de bloqueio; um não pode apagar o outro.
        await authedPage.goto('/comandos-sms');
        const cat = await catalogo(authedPage);

        const universal = cat.findIndex(c => c.u && !c.p.length);
        test.skip(universal < 0, 'sem comando universal sem parâmetros');

        await authedPage.selectOption('#f-cmd', String(universal));

        const reabilitados = await authedPage.$$eval('tr.linha-bloqueada',
            rows => rows.filter(r => !r.querySelector('.sel-dev').disabled).length);
        expect(reabilitados, 'comando universal reabilitou equipamento sem número').toBe(0);
    });

    test('o preview mostra a string exata e conta os caracteres', async ({ authedPage }) => {
        await authedPage.goto('/comandos-sms');
        const cat = await catalogo(authedPage);

        const idx = cat.findIndex(c => c.u && !c.p.length);
        test.skip(idx < 0, 'sem comando universal sem parâmetros');

        await authedPage.selectOption('#f-cmd', String(idx));
        const preview = await authedPage.inputValue('#f-preview');

        // É a sintaxe do catálogo, sem transformação nenhuma.
        expect(preview).toBe(cat[idx].s);
        expect(preview).not.toContain('666666');
        await expect(authedPage.locator('#preview-aviso')).toContainText(/caracteres/);
    });

    test('o botão só libera com equipamento marcado e parâmetros preenchidos', async ({ authedPage }) => {
        await authedPage.goto('/comandos-sms');
        const btn = authedPage.locator('#btn-enviar');

        // Sem comando escolhido: bloqueado.
        await expect(btn).toBeDisabled();

        const cat = await catalogo(authedPage);
        const idx = cat.findIndex(c => c.u && !c.p.length);
        test.skip(idx < 0, 'sem comando universal sem parâmetros');
        await authedPage.selectOption('#f-cmd', String(idx));

        // Com comando mas sem equipamento: ainda bloqueado.
        await expect(btn).toBeDisabled();

        const livre = authedPage.locator('.sel-dev:not([disabled])').first();
        test.skip(await livre.count() === 0, 'nenhum equipamento habilitado no escopo');
        await livre.check();

        // Agora sim — e o resumo tem de dizer quanto vai custar.
        await expect(btn).toBeEnabled();
        await expect(authedPage.locator('#sel-resumo')).toContainText(/crédito/);
    });

    test('comando com parâmetro em branco mantém o botão bloqueado', async ({ authedPage }) => {
        // A guarda pergunta pelos CAMPOS, não pela aparência da string: um valor
        // de UMA LETRA é indistinguível de um placeholder de uma letra, e a
        // guarda por formato recusava o exemplo oficial do próprio comando.
        await authedPage.goto('/comandos-sms');
        const cat = await catalogo(authedPage);

        const idx = cat.findIndex(c => c.u && c.p.length && c.p.some(p => !p.v));
        test.skip(idx < 0, 'sem comando universal com parâmetro sem default');

        await authedPage.selectOption('#f-cmd', String(idx));
        const livre = authedPage.locator('.sel-dev:not([disabled])').first();
        test.skip(await livre.count() === 0, 'nenhum equipamento habilitado');
        await livre.check();

        // Esvazia o primeiro parâmetro.
        await authedPage.locator('.p-in').first().fill('');
        await expect(authedPage.locator('#btn-enviar')).toBeDisabled();
        await expect(authedPage.locator('#preview-aviso')).toContainText(/Preencha/);
    });
});
