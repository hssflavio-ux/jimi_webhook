// @ts-check
/**
 * Spec de regressão do playback JIMI — o que a tela DESPACHA.
 *
 * Dois defeitos moram aqui, e os dois falham do mesmo jeito: o comando sai, o
 * IoT Hub responde `code:0`, a tela diz "consultando"/"solicitado", e nada
 * acontece. Nenhum deles aparece em log.
 *
 *   1. **A listagem precisa de DOIS comandos.** `FILELIST,<url>` só grava o
 *      endereço no equipamento (planilha A006); quem manda subir é o `FILELIST`
 *      NU (A007). Até a v4.9.33 esta tela mandava só o primeiro. Nos dados de
 *      produção: sete `FILELIST,<url>` entre 14:54 e 15:22 de 19/08/2026, zero
 *      capturas; o `FILELIST` nu de 15:00:19 produziu a captura de 15:00:19.
 *
 *   2. **[Extrair] mandava 37382 para câmera JIMI.** É o "FTP file upload
 *      command" do JT/T — a JIMI não o conhece. O comando dela é
 *      `HVIDEO,<carimbo>,<câmera>` no proNo 128, com o carimbo na hora LOCAL da
 *      câmera, exatamente como ele veio no nome do arquivo.
 *
 * Aqui não há câmera: o que se trava é a DECISÃO da tela — quais comandos ela
 * monta, em que ordem, e o que ela NÃO manda. É onde os dois defeitos moravam.
 * A conversão de fuso propriamente dita é travada em
 * `tests/helpers/filelist.test.php`, contra a captura real.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

const IMEI_FAKE = '860000000000009';

/**
 * Garante que o equipamento existe na lista da tela, marcado como JIMI.
 *
 * Não é enfeite: `pbSendCmd()` decide o GATEWAY (`serverFlagId` 1 = JIMI,
 * 0 = JT/T) lendo o `data-proto` da `<option>` correspondente ao IMEI. Um IMEI
 * que não está na lista cai no fallback e o comando sai pela porta do JT/T —
 * foi exatamente o que este spec pegou na primeira execução. Injetar a opção
 * reproduz o estado real da página (o IMEI despachado é sempre um dos
 * renderizados) sem exigir que o ambiente tenha uma câmera JIMI cadastrada.
 */
async function comEquipamentoJimi(page, imei) {
    await page.evaluate((im) => {
        const sel = /** @type {HTMLSelectElement} */ (document.getElementById('pb-imei'));
        let opt = Array.from(sel.options).find((o) => o.value === im);
        if (!opt) {
            opt = document.createElement('option');
            opt.value = im;
            opt.textContent = 'JIMI de teste';
            sel.appendChild(opt);
        }
        opt.dataset.proto = 'JIMI';
        opt.dataset.cam = '2';
        sel.value = im;
        // @ts-ignore — o [Extrair] despacha para o equipamento da tela
        selImei = im;
    }, imei);
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

test.describe('Playback JIMI — despacho de comandos', () => {
    test.beforeEach(async ({ authedPage }) => {
        // Diálogos do próprio código (alert de recusa) não podem travar a suíte.
        authedPage.on('dialog', (d) => d.dismiss().catch(() => {}));
        await authedPage.goto('/video/playback');
        await expect(authedPage.locator('#pb-imei')).toBeVisible();
    });

    test('a tela expõe as funções de despacho do JIMI', async ({ authedPage }) => {
        // Se alguém remover uma delas, o botão vira `onclick` para função
        // inexistente — que no navegador é erro de console e clique morto.
        const existem = await authedPage.evaluate(() => ({
            // @ts-ignore — funções globais da tela
            requisitar: typeof pbRequestJimi === 'function',
            // @ts-ignore
            extrair: typeof requestExtractJimi === 'function',
            // @ts-ignore
            base: typeof filelistBase === 'string' && filelistBase.indexOf('/filelist/') > -1,
        }));
        expect(existem.requisitar, 'pbRequestJimi() precisa existir').toBeTruthy();
        expect(existem.extrair, 'requestExtractJimi() precisa existir').toBeTruthy();
        expect(existem.base, 'filelistBase precisa apontar para /filelist/').toBeTruthy();
    });

    test('🔴 requisitar manda os DOIS comandos, e o nu por ÚLTIMO', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage);
        await comEquipamentoJimi(authedPage, IMEI_FAKE);

        await authedPage.evaluate((imei) => new Promise((ok) => {
            // @ts-ignore — função global da tela
            pbRequestJimi(imei, () => ok(true));
        }), IMEI_FAKE);

        expect(enviados, 'dois comandos: configurar o endereço e disparar').toHaveLength(2);

        expect(enviados[0].proNo, 'comando de texto = proNo 128').toBe(128);
        expect(enviados[0].serverFlagId, 'gateway JIMI = serverFlagId 1').toBe(1);
        expect(enviados[0].content).toMatch(/^FILELIST,https?:\/\/.+\/filelist\/860000000000009$/);

        expect(enviados[1].proNo).toBe(128);
        expect(enviados[1].serverFlagId).toBe(1);
        expect(enviados[1].content, 'a forma NUA é o que dispara o upload').toBe('FILELIST');
    });

    test('endereço recusado NÃO dispara o upload', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage, { aceitar: false });
        await comEquipamentoJimi(authedPage, IMEI_FAKE);

        await authedPage.evaluate((imei) => new Promise((ok) => {
            // @ts-ignore
            pbRequestJimi(imei, () => ok(true));
        }), IMEI_FAKE);

        expect(enviados, 'só a tentativa de configurar o endereço').toHaveLength(1);
        expect(enviados[0].content).toContain('FILELIST,');
        expect(enviados.some((e) => e.content === 'FILELIST'),
            'sem endereço aceito, mandar a câmera subir 78 KB é subir para lugar nenhum')
            .toBeFalsy();
    });

    test('🔴 [Extrair] do JIMI manda HVIDEO no proNo 128, nunca 37382', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage);
        await comEquipamentoJimi(authedPage, IMEI_FAKE);

        await authedPage.evaluate(() => {
            const btn = document.createElement('button');
            document.body.appendChild(btn);
            // @ts-ignore — o comando vem PRONTO do servidor
            requestExtractJimi(new Event('click'), btn, 'HVIDEO,2026_08_16_05_33_58,1');
        });
        await expect.poll(() => enviados.length).toBe(1);

        expect(enviados[0].proNo, '37382 é JT/T — a JIMI não o conhece').toBe(128);
        expect(enviados[0].serverFlagId).toBe(1);
        // A string vai INTACTA: o carimbo é a hora local da câmera, e qualquer
        // conversão pelo caminho faz a câmera procurar um bloco três horas fora
        // — e responder "não existe", não "fuso errado".
        expect(enviados[0].content).toBe('HVIDEO,2026_08_16_05_33_58,1');
    });

    test('JT/T continua no 37381, sem FILELIST', async ({ authedPage }) => {
        // Guarda do outro lado: a ramificação por protocolo não pode ter
        // trocado o dialeto da família que já funcionava.
        const enviados = await capturarEnvios(authedPage);
        const temJtt = await authedPage.locator('#pb-imei option[data-proto="JTT"]').count();
        test.skip(temJtt === 0, 'nenhum equipamento JT/T cadastrado neste ambiente');

        await authedPage.locator('#pb-imei option[data-proto="JTT"]').first()
            .evaluate((o) => {
                const sel = /** @type {HTMLSelectElement} */ (o.parentElement);
                sel.value = /** @type {HTMLOptionElement} */ (o).value;
            });
        await authedPage.evaluate(() => {
            // @ts-ignore — a decisão por protocolo mora aqui
            onSubmitRequest(new Event('submit'));
        });
        await expect.poll(() => enviados.length).toBeGreaterThan(0);

        expect(enviados.every((e) => e.proNo === 37381),
            'JT/T lista o cartão pelo 37381').toBeTruthy();
        expect(enviados.some((e) => String(e.content).indexOf('FILELIST') > -1),
            'FILELIST é comando JIMI').toBeFalsy();
    });
});
