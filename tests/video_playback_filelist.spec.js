// @ts-check
/**
 * Spec de regressão do playback — o que a tela DESPACHA ao equipamento.
 *
 * Três defeitos moram aqui, e os três falham do mesmo jeito: o comando sai, o
 * IoT Hub responde `code:0`, a tela diz algo verde, e nada acontece. Nenhum
 * deles aparece em log.
 *
 *   1. **Requisitar manda o `FILELIST` NU, e SÓ ele.** São dois comandos de
 *      naturezas diferentes (planilha JIMI V5.0.3): `FILELIST,<url>` (A006) é
 *      *"Modify the server address to receive the playback video namelist
 *      file"* — CONFIGURAÇÃO, escrita no equipamento; `FILELIST` nu (A007) é
 *      *"Let the device to upload..."* — o pedido. O erro já foi cometido nos
 *      DOIS sentidos: primeiro a tela mandava só o de configuração (sete em
 *      produção, zero capturas), depois passou a mandar os dois a cada clique,
 *      e aí uma ação de LEITURA reescrevia a configuração do device a cada
 *      consulta.
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
            configurar: typeof pbConfigurarEndereco,
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

    test('🔴 requisitar manda SÓ o FILELIST nu — nunca reconfigura o device', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage);
        await comEquipamento(authedPage, IMEI_FAKE, 'JIMI', 2);

        await authedPage.evaluate((imei) => new Promise((ok) => {
            // @ts-ignore
            pbRequestJimi(imei, () => ok(true));
        }), IMEI_FAKE);

        expect(enviados, 'consultar é UM comando').toHaveLength(1);
        expect(enviados[0].proNo, 'comando de texto = proNo 128').toBe(128);
        expect(enviados[0].serverFlagId, 'gateway JIMI = serverFlagId 1').toBe(1);
        expect(enviados[0].content, 'a forma NUA é o pedido').toBe('FILELIST');

        // 🔴 O NÚCLEO DESTE TESTE. `FILELIST,<url>` é ESCRITA na configuração do
        // equipamento: mandá-lo junto faz uma ação de leitura reconfigurar a
        // câmera a cada consulta — e com VIDEO_INGEST_IP errado no .env, grava
        // endereço ruim no device sem ninguém pedir.
        expect(enviados.some((e) => String(e.content).indexOf('FILELIST,') === 0),
            'consultar NÃO pode reescrever o endereço gravado na câmera').toBeFalsy();
    });

    test('recusa da câmera oferece a configuração, sem executá-la', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage, { aceitar: false });
        await comEquipamento(authedPage, IMEI_FAKE, 'JIMI', 2);

        await authedPage.evaluate((imei) => new Promise((ok) => {
            // @ts-ignore
            pbRequestJimi(imei, () => ok(true));
        }), IMEI_FAKE);

        expect(enviados, 'só o pedido saiu').toHaveLength(1);
        expect(enviados[0].content).toBe('FILELIST');
        // A tela EXPLICA e oferece o botão — quem escreve na câmera é o
        // usuário, clicando. O aviso cai na lista quando já há período pedido,
        // ou logo abaixo do formulário quando ainda não há.
        const aviso = authedPage.locator('#pb-lista, #pb-recusa');
        await expect(aviso).toContainText(/endereço de upload/i);
        await expect(aviso.locator('button')).toContainText(/configurar endereço/i);

        // 🔴 E o botão de Requisitar volta a funcionar: travá-lo em "Pedindo à
        // câmera" depois de uma recusa deixaria a tela sem saída.
        const bt = authedPage.locator('#playback-form button[type=submit]');
        await expect(bt).toBeEnabled();
        await expect(bt).toContainText(/requisitar/i);
    });

    test('🔴 configurar o endereço é ação SEPARADA e explícita', async ({ authedPage }) => {
        const enviados = await capturarEnvios(authedPage);
        await comEquipamento(authedPage, IMEI_FAKE, 'JIMI', 2);

        await authedPage.evaluate((imei) => pbConfigurarEndereco(imei), IMEI_FAKE);
        await expect.poll(() => enviados.length).toBe(1);

        expect(enviados[0].proNo).toBe(128);
        expect(enviados[0].content, 'a forma com URL é a CONFIGURAÇÃO (A006)')
            .toMatch(/^FILELIST,https?:\/\/.+\/filelist\/860000000000009$/);
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
