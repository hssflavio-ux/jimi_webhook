// @ts-check
/**
 * Spec de regressão do vídeo ao vivo por PROTOCOLO e do mosaico de canais.
 *
 * O defeito original (até a v4.9.27): a tela mandava `proNo 37121` — o comando
 * do JT/T 1078 — em TODO equipamento, inclusive nas câmeras JIMI, que não o
 * entendem. No banco de produção isso aparecia limpo: todo 37121 para JC400AD
 * ficava `sent` (device nunca respondeu), enquanto para JC371/JC181 ficava
 * `executed`. E a URL do player também era só a do JT/T (`/<canal>/<imei>.flv`),
 * enquanto a JIMI publica em `/live/<canal-base-0>/<imei>.flv`.
 *
 * Medido em 18/08/2026 numa JC400AD real: `RTMP,ON,INOUT` registrou
 * `live/0/<imei>` e `live/1/<imei>` no media server, `/live/0/<imei>.flv`
 * devolveu 200 com assinatura FLV, e `/1/<imei>.flv` não devolveu nada.
 *
 * 🔴 A v4.17.14 abriu UM PLAYER POR CANAL, e a MEDIÇÃO ACIMA É A REGRA DO
 * MOSAICO: como o `INOUT` registra os dois canais de uma vez, a JIMI leva **um
 * comando só** — mandar `RTMP,ON,OUT` e depois `RTMP,ON,IN` reconfigura o push
 * e derruba o primeiro canal, sem erro nenhum. A JT/T leva um `37121` por
 * canal, e **serializado**: a câmera não responde ao segundo pedido enquanto
 * processa o primeiro (a mesma lição que o `37381` do playback já pagou).
 *
 * Aqui não há câmera: o que se trava é a DECISÃO da tela — quantos comandos ela
 * monta, quais, e quais URLs ela pede, para cada protocolo. É onde o defeito
 * morava, e onde o mosaico pode reintroduzi-lo.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/**
 * Põe a tela num estado conhecido: protocolo, quantidade de canais e mosaico
 * remontado a partir deles. Sem remontar, `canais` fica com o que o
 * equipamento REAL da lista trouxe, e o teste mediria outra coisa.
 */
async function prepararTela(page, proto, cams, imei = '860000000000001') {
    await page.evaluate(([p, n, i]) => {
        // @ts-ignore — variáveis globais da tela
        selProto = p; maxCams = n; selImei = i;
        // @ts-ignore
        marcarTodosOsCanais(); renderChannels(); montarMosaico();
    }, [proto, cams, imei]);
}

/**
 * Intercepta `/sendcommand`, devolve `code` e registra o que saiu — inclusive
 * a CONCORRÊNCIA máxima, que é como se prova a serialização.
 */
async function espionarComandos(page, code = 0) {
    /** @type {{enviados: any[], concorrenciaMax: number}} */
    const espiao = { enviados: [], concorrenciaMax: 0 };
    let emVoo = 0;
    await page.route('**/sendcommand', async (route) => {
        emVoo++;
        espiao.concorrenciaMax = Math.max(espiao.concorrenciaMax, emVoo);
        espiao.enviados.push(JSON.parse(route.request().postData() || '{}'));
        // Uma pausa curta é o que dá chance de dois pedidos se sobreporem —
        // sem ela, um despacho em paralelo passaria por serializado.
        await new Promise((r) => setTimeout(r, 120));
        emVoo--;
        await route.fulfill({ status: 200, contentType: 'application/json',
                              body: JSON.stringify({ code }) });
    });
    return espiao;
}

test.describe('Vídeo ao vivo — ramificação por protocolo', () => {
    test.beforeEach(async ({ authedPage }) => {
        await authedPage.goto('/video/aovivo');
        await expect(authedPage.locator('#dev-sel')).toBeVisible();
    });

    test('🔴 o script da tela carrega sem erro e expõe as funções do mosaico', async ({ authedPage }) => {
        /** @type {string[]} */
        const erros = [];
        authedPage.on('pageerror', (e) => erros.push('pageerror: ' + e.message));
        authedPage.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });
        await authedPage.reload();
        await expect(authedPage.locator('#dev-sel')).toBeVisible();

        const vivas = await authedPage.evaluate(() => [
            // @ts-ignore — funções globais da tela
            typeof startLive, typeof stopPlayer, typeof montarMosaico, typeof toggleCanal,
            // @ts-ignore
            typeof canaisAtivos, typeof tetoDeCanais, typeof modoJimi, typeof urlDoStream,
            // @ts-ignore
            typeof pararCanal, typeof toggleFoco, typeof reatarPlayer,
        ]);
        expect(erros, 'nenhum erro de console ao abrir a tela').toEqual([]);
        expect(vivas.every((t) => t === 'function'),
            'toda função global da tela precisa existir — `' + vivas.join(',') + '`').toBeTruthy();
    });

    test('a tela expõe o protocolo de cada equipamento', async ({ authedPage }) => {
        const protos = await authedPage.locator('#dev-sel option').evaluateAll((opts) =>
            opts.map((o) => /** @type {HTMLOptionElement} */ (o).dataset.proto));
        expect(protos.length, 'precisa de ao menos um equipamento na lista').toBeGreaterThan(0);
        // O atributo tem de existir SEMPRE — é dele que sai a decisão. Vazio é
        // permitido e significa "equipamento sem modelo cadastrado", caso real
        // (1 de 11 em produção, 18/08/2026) tratado pela guarda do startLive().
        expect(protos.every((p) => p !== undefined),
            'todo <option> tem de trazer o atributo data-proto').toBeTruthy();
    });

    test('protocolo desconhecido RECUSA em vez de adivinhar', async ({ authedPage }) => {
        const espiao = await espionarComandos(authedPage);
        await prepararTela(authedPage, '', 2, '860000000000003');
        await authedPage.evaluate(() => {
            // @ts-ignore
            startLive();
        });
        await expect(authedPage.locator('#stream-bar-text'))
            .toContainText(/sem modelo cadastrado/i);
        expect(espiao.enviados, 'nenhum comando pode sair sem protocolo conhecido').toHaveLength(0);
    });

    test('JIMI: URL usa live/ com canal em base ZERO', async ({ authedPage }) => {
        await prepararTela(authedPage, 'JIMI', 2);
        // @ts-ignore
        expect(await authedPage.evaluate(() => urlDoStream(1)))
            .toContain('/live/0/860000000000001.flv');
        // @ts-ignore
        expect(await authedPage.evaluate(() => urlDoStream(2)))
            .toContain('/live/1/860000000000001.flv');
    });

    test('JT/T: URL mantém o canal em base UM, sem live/', async ({ authedPage }) => {
        await prepararTela(authedPage, 'JTT', 2);
        // @ts-ignore
        const url = await authedPage.evaluate(() => urlDoStream(1));
        expect(url).toContain('/1/860000000000001.flv');
        expect(url, 'JT/T não publica sob live/').not.toContain('/live/');
    });

    test('JIMI: CH1 é a câmera OUT (frontal) e CH2 a IN (cabine)', async ({ authedPage }) => {
        // @ts-ignore
        expect(await authedPage.evaluate(() => cameraJimi(1))).toBe('OUT');
        // @ts-ignore
        expect(await authedPage.evaluate(() => cameraJimi(2))).toBe('IN');
    });
});

test.describe('Vídeo ao vivo — mosaico de canais', () => {
    test.beforeEach(async ({ authedPage }) => {
        await authedPage.goto('/video/aovivo');
        await expect(authedPage.locator('#dev-sel')).toBeVisible();
    });

    test('desenha um player por canal do equipamento', async ({ authedPage }) => {
        await prepararTela(authedPage, 'JTT', 3);
        await expect(authedPage.locator('.vid-tile')).toHaveCount(3);
        await expect(authedPage.locator('.vid-tile video')).toHaveCount(3);
        // Cada quadro é identificável: num mosaico, "qual câmera é esta?" é a
        // informação que não pode faltar.
        const chips = await authedPage.locator('.vid-chip').allTextContents();
        expect(chips.length).toBe(3);
        expect(chips.join(' ')).toMatch(/CH1/);
        expect(chips.join(' ')).toMatch(/CH3/);

        await prepararTela(authedPage, 'JTT', 1);
        await expect(authedPage.locator('.vid-tile')).toHaveCount(1);
    });

    test('🔴 câmera JIMI para em 2 quadros mesmo com camera_count maior', async ({ authedPage }) => {
        // `RTMP,ON,<B>` só aceita IN/OUT/INOUT/PIP — não existe pedir um
        // terceiro canal. Um quadro que nunca receberia vídeo é pior que
        // quadro nenhum: parece defeito da câmera.
        await prepararTela(authedPage, 'JIMI', 3);
        await expect(authedPage.locator('.vid-tile')).toHaveCount(2);
        // @ts-ignore
        expect(await authedPage.evaluate(() => canaisAtivos())).toEqual([1, 2]);

        await prepararTela(authedPage, 'JTT', 3);
        await expect(authedPage.locator('.vid-tile'), 'no JT/T o teto não existe').toHaveCount(3);
    });

    test('o último canal marcado não se desmarca', async ({ authedPage }) => {
        await prepararTela(authedPage, 'JTT', 2);
        await authedPage.evaluate(() => {
            // @ts-ignore
            toggleCanal(2);
        });
        await expect(authedPage.locator('.vid-tile')).toHaveCount(1);
        await authedPage.evaluate(() => {
            // @ts-ignore — a tela não pode ficar sem nenhum quadro
            toggleCanal(1);
        });
        await expect(authedPage.locator('.vid-tile')).toHaveCount(1);
    });

    test('🔴 JIMI manda UM comando para os dois canais (INOUT)', async ({ authedPage }) => {
        const espiao = await espionarComandos(authedPage);
        await prepararTela(authedPage, 'JIMI', 2);
        // @ts-ignore
        await authedPage.evaluate(() => startLive());
        await expect.poll(() => espiao.enviados.length).toBeGreaterThan(0);
        await authedPage.waitForTimeout(400);

        expect(espiao.enviados, 'dois RTMP,ON derrubariam o primeiro canal').toHaveLength(1);
        expect(espiao.enviados[0].proNo, 'JIMI usa comando de texto (128)').toBe(128);
        expect(espiao.enviados[0].serverFlagId, 'JIMI é o gateway 1').toBe(1);
        expect(espiao.enviados[0].content).toBe('RTMP,ON,INOUT');
    });

    test('JIMI com um canal só usa OUT ou IN, conforme o marcado', async ({ authedPage }) => {
        const espiao = await espionarComandos(authedPage);
        await prepararTela(authedPage, 'JIMI', 2);
        await authedPage.evaluate(() => {
            // @ts-ignore — deixa só o CH1
            toggleCanal(2); startLive();
        });
        await expect.poll(() => espiao.enviados.length).toBeGreaterThan(0);
        expect(espiao.enviados[0].content).toBe('RTMP,ON,OUT');

        espiao.enviados.length = 0;
        await prepararTela(authedPage, 'JIMI', 2);
        await authedPage.evaluate(() => {
            // @ts-ignore — deixa só o CH2
            toggleCanal(1); startLive();
        });
        await expect.poll(() => espiao.enviados.length).toBeGreaterThan(0);
        expect(espiao.enviados[0].content).toBe('RTMP,ON,IN');
    });

    test('🔴 JT/T manda um 37121 POR CANAL, e serializado', async ({ authedPage }) => {
        const espiao = await espionarComandos(authedPage);
        await prepararTela(authedPage, 'JTT', 3, '860000000000002');
        // @ts-ignore
        await authedPage.evaluate(() => startLive());
        await expect.poll(() => espiao.enviados.length, { timeout: 10000 }).toBe(3);

        expect(espiao.enviados.every((c) => c.proNo === 37121), 'JT/T usa 37121').toBeTruthy();
        expect(espiao.enviados.every((c) => c.serverFlagId === 0), 'JT/T é o gateway 0').toBeTruthy();
        const canais = espiao.enviados.map((c) => JSON.parse(c.content).channel);
        expect(canais, 'um pedido por canal, em ordem').toEqual(['1', '2', '3']);
        // 🔴 A câmera não responde ao segundo pedido enquanto processa o
        // primeiro — em paralelo, o comando volta como falha.
        expect(espiao.concorrenciaMax, 'os pedidos não podem se sobrepor').toBe(1);
    });

    test('recusa do gateway aparece no quadro do canal, não só no rodapé', async ({ authedPage }) => {
        const espiao = await espionarComandos(authedPage, 1);   // code != 0 = recusa
        await prepararTela(authedPage, 'JTT', 2);
        // @ts-ignore
        await authedPage.evaluate(() => startLive());
        await expect.poll(() => espiao.enviados.length, { timeout: 10000 }).toBe(2);
        // Com um mosaico, uma frase única teria de mentir sobre um dos canais.
        await expect(authedPage.locator('#bar-1')).toContainText(/erro/i);
        await expect(authedPage.locator('.vid-chip.erro').first()).toBeVisible();
    });
});
