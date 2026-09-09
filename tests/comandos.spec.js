// @ts-check
/**
 * Spec da tela de Comandos (v4.17.28 — lista única por nome, parametrização livre).
 *
 * 🔴 A tela deixou de travar por MODELO (decisão do dono do produto, mesma
 * linha do `/comandos-sms` v4.17.25): uma linha por NOME de comando
 * (`command_catalog_merge_by_name()`), 1 campo de texto livre para os
 * parâmetros, e nenhum equipamento fica desabilitado — quem opera esta tela
 * conhece a sintaxe de cada modelo. `window.CATALOGO` deixou de ter `.s`
 * (sintaxe exata) / `.p` (campos estruturados) / `.t` (template) por entrada
 * — o que sobrou é `.c` (nome, único), `.m` (união de modelos), `.f`
 * (famílias), `.e` (no máximo 1 exemplo por família) e `.q`/`.qm` (consulta).
 *
 * As asserções cobrem o oposto do que a versão anterior testava: nenhum
 * checkbox fica desabilitado, e o aviso de compatibilidade é só informativo.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** Lê o catálogo que a página embute, sem depender da grade renderizada. */
async function catalogo(page) {
    return await page.evaluate(() => window.CATALOGO || []);
}

test.describe('Comandos — lista única, sem trava de modelo', () => {

    test('a página carrega o catálogo unificado e a lista de equipamentos', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        expect(cat.length).toBeGreaterThan(50);          // catálogo inteiro por NOME, não um punhado curado
        expect(await authedPage.locator('.dev-row').count()).toBeGreaterThan(0);

        // Nome único por comando — a unificação por nome não pode duplicar linha.
        const nomes = cat.map((c) => c.c);
        expect(new Set(nomes).size).toBe(nomes.length);

        // Toda sintaxe é a forma de PLATAFORMA — a de SMS levaria a senha 666666.
        expect(cat.some((c) => /666666/.test(c.c) || (c.e || []).some((e) => /666666/.test(e.c)))).toBe(false);
    });

    test('🔴 nenhum equipamento fica desabilitado ao escolher um comando específico de modelo', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const naoUniversal = cat.find((c) => !c.u && c.m.length);
        test.skip(!naoUniversal, 'catálogo sem comando não-universal');

        await authedPage.selectOption('#cmd-sel', 'T:' + naoUniversal.c);

        const desabilitados = await authedPage.$$eval('.dev-row .dev-chk',
            (chks) => chks.filter((c) => c.disabled).length);
        expect(desabilitados, 'a tela não trava mais por modelo (v4.17.28)').toBe(0);
    });

    test('equipamento de modelo não documentado gera aviso informativo, não bloqueio', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const modelos = await authedPage.$$eval('.dev-row', (rows) =>
            [...new Set(rows.map((r) => r.dataset.modelo))]);

        const alvo = cat.find((c) => !c.u && c.m.length && modelos.some((m) => !c.m.includes(m)));
        test.skip(!alvo, 'este cliente não tem modelo fora da documentação de nenhum comando');

        await authedPage.selectOption('#cmd-sel', 'T:' + alvo.c);
        await expect(authedPage.locator('#lock-note')).toBeVisible();
        await expect(authedPage.locator('#lock-note')).toContainText('envio continua liberado');

        const desabilitados = await authedPage.$$eval('.dev-row .dev-chk',
            (chks) => chks.filter((c) => c.disabled).length);
        expect(desabilitados).toBe(0);
    });

    test('campo de parâmetros em branco monta a consulta exata, quando o catálogo souber uma', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const comConsulta = cat.find((c) => c.q);
        test.skip(!comConsulta, 'catálogo sem comando com consulta catalogada');

        await authedPage.selectOption('#cmd-sel', 'T:' + comConsulta.c);
        const preview = (await authedPage.locator('#p-preview').textContent()).trim();
        expect(preview).toBe(comConsulta.q);
    });

    test('parâmetros digitados livremente montam <NOME>,<texto>#', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const alvo = cat.find((c) => c.c === 'TIMER') || cat[0];

        await authedPage.selectOption('#cmd-sel', 'T:' + alvo.c);
        await authedPage.fill('#p-params-livre', '20');
        const preview = (await authedPage.locator('#p-preview').textContent()).trim();
        expect(preview).toBe(alvo.c + ',20#');
    });

    test('colar o exemplo inteiro no campo de parâmetros não duplica o nome do comando', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const comExemplo = cat.find((c) => c.e && c.e.length);
        test.skip(!comExemplo, 'catálogo sem exemplo catalogado');

        await authedPage.selectOption('#cmd-sel', 'T:' + comExemplo.c);
        await authedPage.fill('#p-params-livre', comExemplo.e[0].c);   // exemplo inteiro, com nome e #
        const preview = (await authedPage.locator('#p-preview').textContent()).trim();
        const vezes = (preview.match(new RegExp(comExemplo.c, 'g')) || []).length;
        expect(vezes, 'nome do comando duplicado ao colar o exemplo inteiro').toBe(1);
        expect(preview).toMatch(/#$/);
    });

    test('sem forma de consulta conhecida, campo vazio bloqueia o envio com o motivo', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const semConsulta = cat.find((c) => !c.q);
        test.skip(!semConsulta, 'catálogo sem comando sem consulta catalogada');

        await authedPage.selectOption('#cmd-sel', 'T:' + semConsulta.c);
        await authedPage.locator('.dev-row .dev-chk').first().check();

        await expect(authedPage.locator('#p-preview-erro')).toContainText(/Sem forma de consulta conhecida/);
        await expect(authedPage.locator('#btn-enviar')).toBeDisabled();
    });

    test('🔴 os filtros do histórico seguem o padrão visual do sistema', async ({ authedPage }) => {
        // O defeito relatado: as listas suspensas do histórico ficavam com a
        // borda PADRÃO DO NAVEGADOR — cinza, raio próprio, diferente do
        // hairline do sistema e diferente entre Chrome e Firefox. A causa era
        // um estilo inline que definia padding e fonte e ESQUECIA a borda.
        //
        // Comparar cada campo contra o vizinho é o que pega isso: um valor fixo
        // de cor no teste envelheceria junto com o tema, mas "todos iguais" vale
        // para sempre.
        await authedPage.goto('/comandos');
        await expect(authedPage.locator('#hist-imei')).toBeVisible();

        const estilos = await authedPage.evaluate(() =>
            ['#hist-cust', '#hist-imei', '#hist-desf', '#hist-de', '#hist-ate']
                .map((sel) => {
                    const el = document.querySelector(sel);
                    if (!el) return sel + ': AUSENTE';
                    const cs = getComputedStyle(el);
                    return [cs.borderTopWidth, cs.borderTopStyle, cs.borderTopColor,
                            cs.borderTopLeftRadius].join(' ');
                }));

        expect(estilos.filter((e) => String(e).indexOf('AUSENTE') > -1),
            'todo campo do filtro tem de existir').toEqual([]);
        expect(new Set(estilos).size,
            'todos os campos do filtro compartilham a MESMA borda — `' + estilos.join(' | ') + '`').toBe(1);
        expect(estilos[0], 'e a borda não pode ser a de nenhum lado zerada').not.toMatch(/^0px|none/);
    });

    test('o equipamento é escolhido por lista suspensa, não por botões', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        // A nuvem de chips oferecia multisseleção que ninguém pediu, em um
        // controle que não se parecia com nenhum outro filtro da tela.
        await expect(authedPage.locator('select#hist-imei')).toHaveCount(1);
        await expect(authedPage.locator('#cmddev, [id^="cmddev"]')).toHaveCount(0);
        // O parâmetro da URL não mudou: link antigo continua filtrando.
        expect(await authedPage.locator('#hist-imei').getAttribute('name')).toBe('imei');
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

test.describe('Comandos — merge por nome e exemplos por família (v4.17.28)', () => {

    test('TIMER vira uma linha só, com os modelos das duas aridades antigas unidos', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const timers = cat.filter((c) => c.c === 'TIMER');
        expect(timers.length, 'TIMER não pode duplicar linha depois do merge por nome').toBe(1);
        expect(timers[0].m.length, 'modelos das duas aridades antigas devem estar unidos').toBeGreaterThan(0);
    });

    test('exemplos nunca repetem o mesmo rótulo de família para o mesmo comando', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        for (const c of cat) {
            if (!c.e || c.e.length < 2) continue;
            const fams = c.e.map((e) => e.l).filter(Boolean);
            expect(new Set(fams).size, `${c.c}: exemplos repetem rótulo de família`).toBe(fams.length);
        }
    });

    test('os nomes da planilha do JC371 continuam presentes na lista unificada', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const esperados = [
            'CHECK', 'CHECKVIDEO', 'STATUSVIDEO', 'SENSORSET', 'SHUTDOWNTIME',
            'VIDEORSL_SUB', 'VIDETIMEZONE', 'KEYFUN', 'APN', 'SERVER', 'BCD',
            'LOG', 'RECORDAUDIO', 'RECORDAUDIO_SUB', 'RATATION', 'PICTIMER',
            'TIMER', 'ANGLEREP',
        ];
        const presentes = new Set(cat.map((c) => c.c));
        expect(esperados.filter((n) => !presentes.has(n))).toEqual([]);

        // A tela agrupa por categoria pelo mapa de rótulos; categoria fora do
        // mapa cairia no valor cru (`manutencao` em vez de "Manutenção e
        // diagnóstico"). Toda entrada nova precisa cair num grupo conhecido.
        const rotulos = await authedPage.evaluate(() => Object.keys(window.ROTCAT || {}));
        const forasteiras = [...new Set(cat.map((c) => c.k))].filter((k) => !rotulos.includes(k));
        expect(forasteiras, 'categoria sem rótulo na tela').toEqual([]);
    });

    test('CHECK continua oferecido como leitura', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const chk = cat.find((c) => c.c === 'CHECK');
        expect(chk, 'CHECK precisa estar na lista unificada').toBeTruthy();
        expect(chk.q, 'CHECK é consulta de si mesmo').toBe('CHECK#');

        await authedPage.selectOption('#cmd-sel', 'T:CHECK');
        expect((await authedPage.locator('#p-preview').textContent()).trim()).toBe('CHECK#');
    });

    test('LOG,ALL# é digitado no campo de parâmetros, não mais um campo estruturado', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        await authedPage.selectOption('#cmd-sel', 'T:LOG');
        await authedPage.fill('#p-params-livre', 'ALL');
        expect((await authedPage.locator('#p-preview').textContent()).trim()).toBe('LOG,ALL#');
    });
});
