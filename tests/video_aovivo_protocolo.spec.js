// @ts-check
/**
 * Spec de regressão do vídeo ao vivo por PROTOCOLO.
 *
 * O defeito (até a v4.9.27): a tela mandava `proNo 37121` — o comando do
 * JT/T 1078 — em TODO equipamento, inclusive nas câmeras JIMI, que não o
 * entendem. No banco de produção isso aparecia limpo: todo 37121 para JC400AD
 * ficava `sent` (device nunca respondeu), enquanto para JC371/JC181 ficava
 * `executed`. E a URL do player também era só a do JT/T (`/<canal>/<imei>.flv`),
 * enquanto a JIMI publica em `/live/<canal-base-0>/<imei>.flv`.
 *
 * Medido em 18/08/2026 numa JC400AD real: `RTMP,ON,INOUT` registrou
 * `live/0/<imei>` e `live/1/<imei>` no media server, `/live/0/<imei>.flv`
 * devolveu 200 com assinatura FLV, e `/1/<imei>.flv` não devolveu nada.
 *
 * Aqui não há câmera: o que se trava é a DECISÃO da tela — qual comando ela
 * monta e qual URL ela pede, para cada protocolo. É onde o defeito morava.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** Executa as funções da própria página com um protocolo forçado. */
async function decisao(page, proto, canal) {
    return page.evaluate(([p, ch]) => {
        // @ts-ignore — funções globais da tela
        selProto = p; selCh = ch; selImei = '860000000000001';
        return {
            // @ts-ignore
            url: urlDoStream(),
            // @ts-ignore
            camera: typeof cameraJimi === 'function' ? cameraJimi(ch) : null,
        };
    }, [proto, canal]);
}

test.describe('Vídeo ao vivo — ramificação por protocolo', () => {
    test.beforeEach(async ({ authedPage }) => {
        await authedPage.goto('/video/aovivo');
        await expect(authedPage.locator('#dev-sel')).toBeVisible();
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
        /** @type {any[]} */
        const enviados = [];
        await authedPage.route('**/sendcommand', async (route) => {
            enviados.push(JSON.parse(route.request().postData() || '{}'));
            await route.fulfill({ status: 200, contentType: 'application/json',
                                  body: JSON.stringify({ code: 0 }) });
        });
        await authedPage.evaluate(() => {
            // @ts-ignore — equipamento sem modelo cadastrado
            selProto = ''; selCh = 1; selImei = '860000000000003';
            // @ts-ignore
            startLive();
        });
        await expect(authedPage.locator('#stream-bar-text'))
            .toContainText(/sem modelo cadastrado/i);
        expect(enviados, 'nenhum comando pode sair sem protocolo conhecido').toHaveLength(0);
    });

    test('JIMI: URL usa live/ com canal em base ZERO', async ({ authedPage }) => {
        const ch1 = await decisao(authedPage, 'JIMI', 1);
        expect(ch1.url).toContain('/live/0/860000000000001.flv');
        const ch2 = await decisao(authedPage, 'JIMI', 2);
        expect(ch2.url).toContain('/live/1/860000000000001.flv');
    });

    test('JT/T: URL mantém o canal em base UM, sem live/', async ({ authedPage }) => {
        const ch1 = await decisao(authedPage, 'JTT', 1);
        expect(ch1.url).toContain('/1/860000000000001.flv');
        expect(ch1.url, 'JT/T não publica sob live/').not.toContain('/live/');
    });

    test('JIMI: CH1 é a câmera OUT (frontal) e CH2 a IN (cabine)', async ({ authedPage }) => {
        expect((await decisao(authedPage, 'JIMI', 1)).camera).toBe('OUT');
        expect((await decisao(authedPage, 'JIMI', 2)).camera).toBe('IN');
    });

    test('o comando enviado difere por protocolo', async ({ authedPage }) => {
        // Intercepta /sendcommand e devolve sucesso sem tocar em device real.
        /** @type {any[]} */
        const enviados = [];
        await authedPage.route('**/sendcommand', async (route) => {
            enviados.push(JSON.parse(route.request().postData() || '{}'));
            await route.fulfill({ status: 200, contentType: 'application/json',
                                  body: JSON.stringify({ code: 1, msg: 'stub' }) });
        });

        await authedPage.evaluate(() => {
            // @ts-ignore
            selProto = 'JIMI'; selCh = 1; selImei = '860000000000001';
            // @ts-ignore
            startLive();
        });
        await expect.poll(() => enviados.length).toBeGreaterThan(0);
        expect(enviados[0].proNo, 'JIMI usa comando de texto (128)').toBe(128);
        expect(enviados[0].serverFlagId, 'JIMI é o gateway 1').toBe(1);
        expect(enviados[0].content).toBe('RTMP,ON,OUT');

        enviados.length = 0;
        await authedPage.evaluate(() => {
            // @ts-ignore
            selProto = 'JTT'; selCh = 1; selImei = '860000000000002';
            // @ts-ignore
            startLive();
        });
        await expect.poll(() => enviados.length).toBeGreaterThan(0);
        expect(enviados[0].proNo, 'JT/T usa 37121').toBe(37121);
        expect(enviados[0].serverFlagId, 'JT/T é o gateway 0').toBe(0);
        expect(String(enviados[0].content)).toContain('codeStreamType');
    });
});
