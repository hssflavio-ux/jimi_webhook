// @ts-check
/**
 * Spec da tela de Comandos por SMS (v4.14.0; UI de parâmetro único v4.17.25).
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
 *  4. **Nada é enviado sem seleção de equipamento.** O botão é a única barreira
 *     antes de gastar dinheiro.
 *
 * 🔴 v4.17.25 — o catálogo é UNIFICADO por nome de comando (decisão do dono do
 * produto: cada nome vira 1 linha na tela, com 1 campo de texto livre para os
 * parâmetros, em vez de N campos estruturados por variante de aridade/modelo).
 * `window.CATALOGO_SMS` deixou de ter `.s`/`.p`/`.t` por entrada — o que
 * sobrou é `.c` (nome), `.e` (exemplos concatenados das variantes) e `.q`
 * (consulta, quando alguma variante a documenta).
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
        // (o catálogo exposto não carrega mais a chave crua por comando desde
        // a unificação por nome — checa nome e exemplos, que sobrevivem.)
        expect(cat.some(c => /666666/.test(c.c) || (c.e || []).some(e => /666666/.test(e.c)))).toBe(false);

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

        const universal = cat.findIndex(c => c.u);
        test.skip(universal < 0, 'sem comando universal no catálogo');

        await authedPage.selectOption('#f-cmd', String(universal));

        const reabilitados = await authedPage.$$eval('tr.linha-bloqueada',
            rows => rows.filter(r => !r.querySelector('.sel-dev').disabled).length);
        expect(reabilitados, 'comando universal reabilitou equipamento sem número').toBe(0);
    });

    test('campo de parâmetros em branco monta a consulta exata e conta os caracteres', async ({ authedPage }) => {
        await authedPage.goto('/comandos-sms');
        const cat = await catalogo(authedPage);

        // Comando universal com forma de consulta catalogada: campo em branco
        // tem de virar exatamente `atual.q`, sem transformação nenhuma.
        const idx = cat.findIndex(c => c.u && c.q);
        test.skip(idx < 0, 'sem comando universal com consulta catalogada');

        await authedPage.selectOption('#f-cmd', String(idx));
        const preview = await authedPage.inputValue('#f-preview');

        expect(preview).toBe(cat[idx].q);
        expect(preview).not.toContain('666666');
        await expect(authedPage.locator('#preview-aviso')).toContainText(/caracteres/);
    });

    test('o botão só libera com equipamento marcado e um preview válido montado', async ({ authedPage }) => {
        await authedPage.goto('/comandos-sms');
        const btn = authedPage.locator('#btn-enviar');

        // Sem comando escolhido: bloqueado.
        await expect(btn).toBeDisabled();

        const cat = await catalogo(authedPage);
        const idx = cat.findIndex(c => c.u);
        test.skip(idx < 0, 'sem comando universal no catálogo');
        await authedPage.selectOption('#f-cmd', String(idx));

        // Com comando mas sem equipamento: ainda bloqueado.
        await expect(btn).toBeDisabled();

        const livre = authedPage.locator('.sel-dev:not([disabled])').first();
        test.skip(await livre.count() === 0, 'nenhum equipamento habilitado no escopo');
        await livre.check();
        // Digita algo no campo único de parâmetros — livre escolha do operador
        // (v4.17.25), sem campos estruturados por comando.
        await authedPage.fill('#f-params-livre', '1');

        // Agora sim — e o resumo tem de dizer quanto vai custar.
        await expect(btn).toBeEnabled();
        await expect(authedPage.locator('#sel-resumo')).toContainText(/crédito/);
    });

    test('comando sem consulta catalogada e campo vazio mantém o botão bloqueado', async ({ authedPage }) => {
        // Sem uma forma de consulta conhecida, mandar o comando nu custaria um
        // crédito de SMS só para descobrir que ele não faz nada — a tela
        // bloqueia em vez de adivinhar.
        await authedPage.goto('/comandos-sms');
        const cat = await catalogo(authedPage);

        const idx = cat.findIndex(c => c.u && !c.q);
        test.skip(idx < 0, 'sem comando universal sem consulta catalogada');

        await authedPage.selectOption('#f-cmd', String(idx));
        const livre = authedPage.locator('.sel-dev:not([disabled])').first();
        test.skip(await livre.count() === 0, 'nenhum equipamento habilitado');
        await livre.check();

        await expect(authedPage.locator('#btn-enviar')).toBeDisabled();
        await expect(authedPage.locator('#preview-aviso')).toContainText(/Sem forma de consulta conhecida/);
    });
});
