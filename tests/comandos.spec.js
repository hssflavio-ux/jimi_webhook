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

test.describe('Comandos — o CHECK# e as sintaxes do JC371 (v4.9.40)', () => {

    test('🔴 CHECK# não trava a seleção, CHECKVIDEO# trava', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);

        const chk = cat.find(c => c.s === 'CHECK#');
        expect(chk, 'CHECK# precisa estar no catálogo da tela').toBeTruthy();

        // A exceção manual: medido respondendo em JC400AD, JC371 e JC182. Uma
        // regeneração do catálogo por script a desfaz em silêncio, e o sintoma
        // seria este — equipamento desabilitado numa consulta de LEITURA.
        await authedPage.selectOption('#cmd-sel', 'T:CHECK#');
        const presos = await authedPage.$$eval('.dev-row',
            rows => rows.filter(r => r.querySelector('.dev-chk').disabled)
                        .map(r => r.dataset.modelo));
        expect(presos, 'CHECK# é leitura e vale na linha JC inteira').toEqual([]);

        // ⚠️ O contraste é o ponto: mesma planilha, mesma família, e o
        // CHECKVIDEO# NÃO vale na linha JC400. Se os dois soltassem a trava,
        // o teste acima passaria por vacuidade — a trava estaria quebrada.
        const cv = cat.find(c => c.s === 'CHECKVIDEO#');
        expect(cv, 'CHECKVIDEO# precisa estar no catálogo').toBeTruthy();
        expect(cv.u, 'CHECKVIDEO# não é universal').toBeFalsy();

        const modelos = await authedPage.$$eval('.dev-row',
            rows => [...new Set(rows.map(r => r.dataset.modelo))]);
        test.skip(!modelos.some(m => m && m !== 'JC371'),
                  'este cliente só tem JC371; a trava não tem o que segurar');

        await authedPage.selectOption('#cmd-sel', 'T:CHECKVIDEO#');
        const estado = await authedPage.$$eval('.dev-row', rows => rows.map(r => ({
            modelo: r.dataset.modelo,
            desabilitado: r.querySelector('.dev-chk').disabled,
        })));
        for (const d of estado) {
            expect(d.desabilitado, `${d.modelo} para CHECKVIDEO#`).toBe(d.modelo !== 'JC371');
        }
        await expect(authedPage.locator('#lock-note')).toBeVisible();
    });

    test('CHECK# é oferecido como leitura, com a procedência à vista', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const chk = cat.find(c => c.s === 'CHECK#');
        expect(chk.q, 'CHECK# é consulta de si mesmo').toBe('CHECK#');
        expect(chk.qr, 'a procedência é medição em câmera real').toContain('medido');

        await authedPage.selectOption('#cmd-sel', 'T:CHECK#');
        // Sendo consulta sem parâmetro, não pode pedir campo nenhum ao operador
        await expect(authedPage.locator('.p-in')).toHaveCount(0);
        const preview = await authedPage.locator('#p-preview').textContent();
        expect(preview.trim()).toBe('CHECK#');
    });

    test('🔴 as duas aridades do TIMER convivem, e a nova fica presa ao JC371', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);

        // Comparar por nome-base esconderia isto: são duas entradas do MESMO
        // comando, com números de campos diferentes.
        const timers = cat.filter(c => c.c === 'TIMER');
        expect(timers.length, 'TIMER tem duas sintaxes catalogadas').toBeGreaterThanOrEqual(2);

        const umCampo = timers.find(c => c.p.length === 1);
        const doisCampos = timers.find(c => c.p.length === 2);
        expect(umCampo, 'a sintaxe de um campo, da planilha do JC371').toBeTruthy();
        expect(doisCampos, 'a sintaxe de dois campos, universal').toBeTruthy();
        expect(umCampo.u, 'a variante nasce presa ao modelo da planilha').toBeFalsy();
        expect(doisCampos.u, 'a antiga continua universal').toBeTruthy();

        // Só a primeira sintaxe carrega a consulta — senão a tela ofereceria
        // dois botões idênticos de "ler o valor atual".
        expect(umCampo.q, 'variante de aridade não duplica a consulta').toBeFalsy();

        await authedPage.selectOption('#cmd-sel', 'T:' + umCampo.s);
        await expect(authedPage.locator('.p-in')).toHaveCount(1);
        await authedPage.locator('.p-in').first().fill('20');
        expect((await authedPage.locator('#p-preview').textContent()).trim()).toBe('TIMER,20#');
    });

    test('as 18 sintaxes da planilha do JC371 chegaram à tela', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const esperadas = [
            'CHECK#', 'CHECKVIDEO#', 'STATUSVIDEO#', 'SENSORSET,A,B,C,D#',
            'SHUTDOWNTIME,A#', 'VIDEORSL_SUB,A,B,C,D,E#', 'VIDETIMEZONE,A,B,C#',
            'KEYFUN,A,B#', 'APN,A,B,C,D#', 'SERVER,A,B,C,D,E,F#', 'BCD,A,B#',
            'LOG,ALL#', 'RECORDAUDIO,A,B#', 'RECORDAUDIO_SUB,A,B#',
            'RATATION,A,B,C,D#', 'PICTIMER,A,B,C,D#', 'TIMER,A#', 'ANGLEREP,A#',
        ];
        const presentes = new Set(cat.map(c => c.s));
        expect(esperadas.filter(s => !presentes.has(s))).toEqual([]);

        // A tela agrupa por categoria pelo mapa de rótulos; categoria fora do
        // mapa cairia no valor cru (`manutencao` em vez de "Manutenção e
        // diagnóstico"). Toda entrada nova precisa cair num grupo conhecido.
        const rotulos = await authedPage.evaluate(() => Object.keys(window.ROTCAT || {}));
        const forasteiras = [...new Set(cat.map(c => c.k))].filter(k => !rotulos.includes(k));
        expect(forasteiras, 'categoria sem rótulo na tela').toEqual([]);
    });

    test('LOG,ALL# é comando pronto, não um campo para preencher', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        // `ALL` é palavra literal do comando, não placeholder — pedir que o
        // operador a digite seria o erro que `template: false` evita.
        await authedPage.selectOption('#cmd-sel', 'T:LOG,ALL#');
        await expect(authedPage.locator('.p-in')).toHaveCount(0);
        expect((await authedPage.locator('#p-preview').textContent()).trim()).toBe('LOG,ALL#');
    });
});

test.describe('Comandos — placeholder é campo em branco, não formato (v4.9.40)', () => {

    /** Marca o primeiro equipamento habilitado, para o botão poder ficar pronto. */
    async function marcarUm(page) {
        const chk = page.locator('.dev-row .dev-chk:not([disabled])').first();
        await chk.check();
    }

    test('🔴 valor legítimo de UMA LETRA não é lido como placeholder', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const vtz = cat.find(c => c.s === 'VIDETIMEZONE,A,B,C#');
        expect(vtz, 'a entrada da planilha JC371 A006').toBeTruthy();

        // A guarda antiga casava `,[A-Z],` no texto montado e recusava o `W` —
        // oeste de GMT, o valor do exemplo OFICIAL do próprio comando.
        await authedPage.selectOption('#cmd-sel', 'T:VIDETIMEZONE,A,B,C#');
        const campos = authedPage.locator('.p-in');
        await expect(campos).toHaveCount(3);
        await campos.nth(0).fill('W');
        await campos.nth(1).fill('3');
        await campos.nth(2).fill('0');

        expect((await authedPage.locator('#p-preview').textContent()).trim())
            .toBe('VIDETIMEZONE,W,3,0#');

        // A pergunta exata, no ponto exato: para a tela, este `W` esta
        // preenchido. E o predicado que a guarda antiga nao tinha.
        const bloqueia = await authedPage.evaluate(() => faltaParametro());
        expect(bloqueia, 'W e valor, nao placeholder').toBe(false);

        await marcarUm(authedPage);
        const btn = authedPage.locator('#btn-enviar');
        await expect(btn).toBeEnabled();
        await expect(btn).toContainText('Enviar para');
    });

    test('🔴 campo em branco desabilita o botão, não só recusa depois do clique', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const comParam = cat.find(c => c.t && c.p && c.p.length >= 2);
        test.skip(!comParam, 'catálogo sem comando com 2+ parâmetros');

        await authedPage.selectOption('#cmd-sel', 'T:' + comParam.s);
        await authedPage.locator('.p-in').first().fill('1');   // demais em branco
        await marcarUm(authedPage);

        const btn = authedPage.locator('#btn-enviar');
        await expect(btn).toBeDisabled();
        await expect(btn).toContainText('Preencha');
    });

    test('comando pronto com valores literais é enviável', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        const vtz = cat.find(c => c.s === 'VIDEOTIMEZONE,W,3,0#');
        expect(vtz, 'a entrada cuja CHAVE é o exemplo oficial').toBeTruthy();
        // 🔴 Estava marcada como template sem nenhum parâmetro declarado: a tela
        // não desenhava campo, o preview ficava com a string crua e a guarda por
        // formato recusava o `W`. Comando impossível de enviar pela tela.
        expect(vtz.t, 'não é molde, é comando pronto').toBeFalsy();

        await authedPage.selectOption('#cmd-sel', 'T:VIDEOTIMEZONE,W,3,0#');
        await expect(authedPage.locator('.p-in')).toHaveCount(0);
        expect((await authedPage.locator('#p-preview').textContent()).trim())
            .toBe('VIDEOTIMEZONE,W,3,0#');

        await marcarUm(authedPage);
        await expect(authedPage.locator('#btn-enviar')).toBeEnabled();
    });

    test('nenhum comando do catálogo pede campo que a tela não desenha', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const cat = await catalogo(authedPage);
        // ⚠️ `template: true` com `params: []` é a combinação que criava o
        // buraco: a tela não desenha campo, o preview sai com o placeholder cru
        // e o operador manda a letra para o equipamento. Eram sete entradas.
        const mudas = cat.filter(c => c.t && (!c.p || c.p.length === 0)).map(c => c.s);
        expect(mudas).toEqual([]);
    });
});
