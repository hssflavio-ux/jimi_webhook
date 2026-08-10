// @ts-check
/**
 * Spec da tela de Comandos (v4.9.7).
 *
 * Cobre as duas regras que definem a tela: a lista de comandos é sensível ao
 * MODELO do equipamento, e o núcleo universal do proNo 128 é a exceção que
 * solta a trava.
 *
 * As asserções são feitas sobre o estado REAL dos checkboxes depois de o JS
 * rodar, não sobre a presença de texto: "o aviso de trava apareceu" passaria
 * mesmo se os equipamentos continuassem clicáveis — e é justamente o clique
 * indevido que esta tela existe para impedir.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** Lê o catálogo que a página embute, sem depender da grade renderizada. */
async function catalogo(page) {
    return await page.evaluate(() => window.CATALOGO || []);
}

test.describe('Comandos — lista sensível ao modelo', () => {

    test('a página carrega o catálogo e a lista de equipamentos', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        expect(cat.length).toBeGreaterThan(50);          // catálogo da wiki, não um punhado curado
        expect(await authedPage.locator('.dev-row').count()).toBeGreaterThan(0);
        // Toda sintaxe é a forma de PLATAFORMA — a de SMS levaria a senha 666666
        expect(cat.some(c => /666666/.test(c.s))).toBe(false);
    });

    test('comando específico de um modelo desabilita os equipamentos dos outros', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);

        // Modelos presentes na tela deste cliente
        const modelos = await authedPage.$$eval('.dev-row', rows =>
            [...new Set(rows.map(r => r.dataset.modelo))]);
        // Um comando não-universal que cubra ao menos um dos modelos da tela
        const alvo = cat.find(c => !c.u && c.m.some(m => modelos.includes(m))
                                 && modelos.some(m => !c.m.includes(m)));
        test.skip(!alvo, 'este cliente não tem modelos suficientes para exercitar a trava');

        await authedPage.selectOption('#cmd-sel', 'T:' + alvo.s);

        const estado = await authedPage.$$eval('.dev-row', rows => rows.map(r => ({
            modelo: r.dataset.modelo,
            desabilitado: r.querySelector('.dev-chk').disabled,
        })));
        for (const d of estado) {
            expect(d.desabilitado, `${d.modelo} para ${alvo.c}`).toBe(!alvo.m.includes(d.modelo));
        }
        await expect(authedPage.locator('#lock-note')).toBeVisible();
    });

    test('comando universal do proNo 128 libera todos os equipamentos', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const univ = cat.find(c => c.u);
        expect(univ, 'catálogo precisa ter ao menos um comando universal').toBeTruthy();

        await authedPage.selectOption('#cmd-sel', 'T:' + univ.s);
        const desabilitados = await authedPage.$$eval('.dev-row',
            rows => rows.filter(r => r.querySelector('.dev-chk').disabled).length);
        expect(desabilitados).toBe(0);
    });

    test('parâmetros viram campos e o preview monta a string final', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const comParam = cat.find(c => c.p && c.p.length >= 2 && c.t);
        test.skip(!comParam, 'catálogo sem comando com 2+ parâmetros');

        await authedPage.selectOption('#cmd-sel', 'T:' + comParam.s);
        const campos = authedPage.locator('.p-in');
        await expect(campos).toHaveCount(comParam.p.length);

        // O formato aceito precisa estar na tela — é o "padrão a ser seguido"
        await expect(authedPage.locator('#p-params')).toContainText(comParam.p[0].p);

        for (let i = 0; i < comParam.p.length; i++) await campos.nth(i).fill(String(i + 1));
        const preview = await authedPage.locator('#p-preview').textContent();
        expect(preview).toMatch(/#$/);
        expect(preview).not.toMatch(/,P\d|,[A-Z](,|#)/);   // nenhum placeholder sobrando
        expect(preview.startsWith(comParam.c)).toBe(true);
    });

    test('parâmetro em branco bloqueia o envio em vez de mandar placeholder', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const comParam = cat.find(c => c.p && c.p.length >= 2 && c.t);
        test.skip(!comParam, 'catálogo sem comando com 2+ parâmetros');

        await authedPage.selectOption('#cmd-sel', 'T:' + comParam.s);
        await authedPage.locator('.p-in').first().fill('1');   // demais em branco
        const preview = await authedPage.locator('#p-preview').textContent();
        expect(preview).toMatch(/P\d|[A-Z](,|#)/);             // placeholder permanece visível
    });

    test('histórico traz desfecho interpretado, não o status cru', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const linhas = await authedPage.evaluate(() => window.LINHAS || []);
        test.skip(linhas.length === 0, 'sem histórico de comandos neste cliente');

        for (const l of linhas.slice(0, 20)) {
            expect(['ok', 'aguardando', 'erro', 'neutro']).toContain(l.desfecho.nivel);
            expect(l.desfecho.titulo).not.toMatch(/successful response|Device busy|request timeout/i);
        }
    });
});
