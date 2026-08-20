// @ts-check
/**
 * Spec de regressão do playback — o que a tela DESPACHA ao equipamento.
 *
 * Três defeitos moram aqui, e os três falham do mesmo jeito: o comando sai, o
 * IoT Hub responde `code:0`, a tela diz algo verde, e nada acontece. Nenhum
 * deles aparece em log.
 *
 *   1. **A listagem precisa de DOIS comandos.** `FILELIST,<url>` só grava o
 *      endereço no equipamento (planilha A006); quem manda subir é o `FILELIST`
 *      NU (A007). Nos dados de produção: sete `FILELIST,<url>` entre 14:54 e
 *      15:22 de 19/08/2026, zero capturas; o nu de 15:00:19 produziu a captura
 *      de 15:00:19.
 *
 *   2. **Comando do protocolo errado.** O [Extrair] mandava `37382` (JT/T) para
 *      câmera JIMI, que não o conhece. Cada família tem o seu dialeto, e o
 *      dialeto errado é aceito pelo gateway e ignorado pelo device.
 *
 *   3. 🔴 **Upload sem pedido.** Subir vídeo gasta franquia do SIM e cria
 *      arquivo no servidor. Clicar numa gravação NÃO pode disparar isso: tem de
 *      abrir a escolha entre ver na câmera (não baixa nada) e subir. Este spec
 *      trava que um clique sozinho não despacha comando nenhum.
 *
 * Aqui não há câmera: o que se trava é a DECISÃO da tela — quais comandos ela
 * monta, em que ordem, e o que ela NÃO manda.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

const IMEI_FAKE = '860000000000009';
const SEED_IMEI = process.env.TEST_IMEI || '';

/**
 * Garante que o equipamento existe na lista da tela, com o protocolo desejado.
 *
 * Não é enfeite: `pbSendCmd()` decide o GATEWAY (`serverFlagId` 1 = JIMI,
 * 0 = JT/T) lendo o `data-proto` da `<option>`. Um IMEI fora da lista cai no
 * fallback e o comando sai pela porta do outro protocolo — em silêncio.
 */
async function comEquipamento(page, imei, proto, cams) {
    await page.evaluate(([im, pr, cm]) => {
        const sel = /** @type {HTMLSelectElement} */ (document.getElementById('pb-imei'));
        let opt = Array.from(sel.options).find((o) => o.value === im);
        if (!opt) {
            opt = document.createElement('option');
            opt.value = im;
            opt.textContent = 'Equipamento de teste';
            sel.appendChild(opt);
        }
        opt.dataset.proto = pr;
        opt.dataset.cam = String(cm);
        sel.value = im;
        // @ts-ignore — as ações despacham para o equipamento da tela
        selImei = im;
    }, [imei, proto, cams]);
}

/** Intercepta /sendcommand e devolve os payloads despachados. */
async function capturarEnvios(page, { aceitar = true } = {}) {
    /** @type {any[]} */
    const enviados = [];
    await page.route('**/sendcommand', async (route) => {
        enviados.push(JSON.parse(route.request().postData() || '{}'));
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify(aceitar ? { code: 0, msg: 'OK!' }
                                         : { code: 500, msg: 'Device not online' }),
        });
    });
    return enviados;
}

test.describe('Playback — despacho de comandos', () => {
    test.beforeEach(async ({ authedPage }) => {
        authedPage.on('dialog', (d) => d.dismiss().catch(() => {}));
        await authedPage.goto('/video/playback');
        await expect(authedPage.locator('#pb-imei')).toBeVisible();
    });

    test('a tela expõe as funções de despacho', async ({ authedPage }) => {
        // Se alguma sumir, o `onclick` chama função inexistente — que no
        // navegador é erro de console e clique morto.
        const existem = await authedPage.evaluate(() => ({
            // @ts-ignore — funções globais da tela
            requisitar: typeof pbRequestJimi, submeter: typeof onSubmitRequest,
            // @ts-ignore
            verCamera: typeof pbVerNaCamera, subir: typeof pbSubirStorage,
            // @ts-ignore
            tocar: typeof pbTocar, acoes: typeof pbAbrirAcoes, enviar: typeof pbSendCmd,
            // @ts-ignore
            base: typeof filelistBase === 'string' && filelistBase.indexOf('/filelist/') > -1,
        }));
        Object.keys(existem).forEach(function (k) {
            if (k === 'base') return;
            expect(existem[k], k + '() precisa existir').toBe('function');
        });
        expect(existem.base, 'filelistBase precisa apontar para /filelist/').toBeTruthy();
    });

    test('🔴 NÃO há seletor de canal na requisição', async ({ authedPage }) => {
        // A resposta do equipamento sempre traz todos os canais: pedir um era
        // filtro de exibição disfarçado de parâmetro, e obrigava a consultar
        // duas vezes o que vem de uma vez só.
        await expect(authedPage.locator('#playback-form select[name="channel"]')).toHaveCount(0);
        await expect(authedPage.locator('#playback-form select[name="imei"]')).toHaveCount(1);
    });

    test('🔴 requisitar manda os DOIS comandos, e o nu por ÚLTIMO', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage);
        await comEquipamento(authedPage, IMEI_FAKE, 'JIMI', 2);

        await authedPage.evaluate((imei) => new Promise((ok) => {
            // @ts-ignore
            pbRequestJimi(imei, () => ok(true));
        }), IMEI_FAKE);

        expect(enviados, 'dois comandos: configurar o endereço e disparar').toHaveLength(2);
        expect(enviados[0].proNo, 'comando de texto = proNo 128').toBe(128);
        expect(enviados[0].serverFlagId, 'gateway JIMI = serverFlagId 1').toBe(1);
        expect(enviados[0].content).toMatch(/^FILELIST,https?:\/\/.+\/filelist\/860000000000009$/);
        expect(enviados[1].content, 'a forma NUA é o que dispara o upload').toBe('FILELIST');
    });

    test('endereço recusado NÃO dispara o upload', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage, { aceitar: false });
        await comEquipamento(authedPage, IMEI_FAKE, 'JIMI', 2);

        await authedPage.evaluate((imei) => new Promise((ok) => {
            // @ts-ignore
            pbRequestJimi(imei, () => ok(true));
        }), IMEI_FAKE);

        expect(enviados, 'só a tentativa de configurar o endereço').toHaveLength(1);
        expect(enviados.some((e) => e.content === 'FILELIST'),
            'sem endereço aceito, mandar a câmera subir 78 KB é subir para lugar nenhum').toBeFalsy();
    });

    test('JT/T: uma requisição cobre TODOS os canais, sem FILELIST', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage);
        await comEquipamento(authedPage, IMEI_FAKE, 'JTT', 3);

        await authedPage.evaluate(() => {
            document.querySelector('input[name=date_from]').value = '2026-08-19';
            document.querySelector('input[name=date_to]').value = '2026-08-19';
            // @ts-ignore — a decisão por protocolo mora aqui
            onSubmitRequest(new Event('submit'));
        });
        await expect.poll(() => enviados.length).toBeGreaterThan(0);

        expect(enviados.every((e) => e.proNo === 37381), 'JT/T lista o cartão pelo 37381').toBeTruthy();
        expect(enviados.some((e) => String(e.content).indexOf('FILELIST') > -1),
            'FILELIST é comando JIMI').toBeFalsy();
        // 🔴 O laço por canal ficou no CÓDIGO porque o 37381 não aceita "todos"
        // — mas a TELA não pergunta canal. É isto que dá ao JT/T a mesma
        // experiência do JIMI, apesar da dinâmica diferente.
        const canais = new Set(enviados.map((e) => JSON.parse(e.content).channel));
        expect([...canais].sort(), 'os três canais do equipamento').toEqual([1, 2, 3]);
    });

    test('🔴 subir para o storage: HVIDEO no JIMI, 37382 no JT/T', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage);

        await comEquipamento(authedPage, IMEI_FAKE, 'JIMI', 2);
        // 1786869238 = 2026-08-16 08:33:58 UTC → 05:33:58 na hora LOCAL da
        // câmera. Não é número inventado: é o PRIMEIRO bloco da captura real da
        // 400AD_3, `2026_08_16_05_33_58_01.ts`. O comando devolve ao
        // equipamento o carimbo dele, sem passar por conversão de fuso.
        await authedPage.evaluate(() => pbSubirStorage(1786869238, 60, 1, null));
        await expect.poll(() => enviados.length).toBe(1);
        expect(enviados[0].proNo, '37382 é JT/T — a JIMI não o conhece').toBe(128);
        expect(enviados[0].serverFlagId).toBe(1);
        expect(enviados[0].content, 'carimbo na hora LOCAL da câmera, sem conversão')
            .toBe('HVIDEO,2026_08_16_05_33_58,1');

        await comEquipamento(authedPage, IMEI_FAKE, 'JTT', 2);
        await authedPage.evaluate(() => pbSubirStorage(1786869238, 60, 2, null));
        await expect.poll(() => enviados.length).toBe(2);
        expect(enviados[1].proNo, 'JT/T sobe por FTP com o 37382').toBe(37382);
        expect(enviados[1].serverFlagId).toBe(0);
        expect(JSON.parse(enviados[1].content).channel).toBe(2);
    });

    test('🔴 ver na câmera NÃO sobe nada: REPLAYLIST no JIMI, 37377 no JT/T', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage);

        await comEquipamento(authedPage, IMEI_FAKE, 'JIMI', 2);
        await authedPage.evaluate(() => pbVerNaCamera(1786869238, 60, 2));
        await expect.poll(() => enviados.length).toBe(1);
        expect(enviados[0].proNo).toBe(128);
        // O nome do bloco, como a câmera o listou — é o que o REPLAYLIST recebe.
        expect(enviados[0].content).toBe('REPLAYLIST,2026_08_16_05_33_58_02.ts');
        expect(String(enviados[0].content).indexOf('HVIDEO'),
            'ver na câmera não pode virar upload').toBe(-1);

        await comEquipamento(authedPage, IMEI_FAKE, 'JTT', 2);
        await authedPage.evaluate(() => pbVerNaCamera(1786869238, 60, 1));
        await expect.poll(() => enviados.length).toBe(2);
        expect(enviados[1].proNo, 'playback do JT/T 1078').toBe(37377);
        expect(enviados[1].proNo, 'não pode ser o 37382, que é upload').not.toBe(37382);
    });

    test('🔴 clicar numa gravação NÃO dispara comando nenhum', async ({ authedPage }) => {
        // A regra do dono do produto: nada sobe para o storage sem pedido
        // explícito. O clique ABRE a escolha; quem despacha é o botão dentro
        // dela. Um clique que já subisse gastaria franquia do SIM por engano.
        test.skip(!SEED_IMEI, 'defina TEST_IMEI para semear uma listagem');
        const enviados = await capturarEnvios(authedPage);

        await authedPage.request.post(`/filelist/${SEED_IMEI}`, {
            headers: { 'Content-Type': 'application/json' },
            data: { imei: SEED_IMEI, fileNameList: '2020_03_01_08_00_00_01.ts,2020_03_01_08_01_00_01.ts,' },
        });
        await authedPage.goto(`/video/playback?imei=${SEED_IMEI}&date_from=2020-03-01&date_to=2020-03-01&request=1`);
        await expect(authedPage.locator('#pb-svg')).toBeVisible();

        await authedPage.evaluate(() => { pbIrPara(PB.blocos[0][0]); for (let i = 0; i < 12; i++) pbZoom(1.8, PB.blocos[0][0]); });
        const bloco = authedPage.locator('rect.pb-bloco').first();
        await expect(bloco).toBeVisible();
        await bloco.click();

        await expect(authedPage.locator('#pb-pop')).toBeVisible();
        expect(enviados, 'o clique só abre a escolha — não despacha').toHaveLength(0);
        await expect(authedPage.locator('#pb-pop')).toContainText('Ver na câmera');
        await expect(authedPage.locator('#pb-pop')).toContainText('Subir para o storage');
    });
});
