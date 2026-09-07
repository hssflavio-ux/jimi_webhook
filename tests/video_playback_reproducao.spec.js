// @ts-check
/**
 * Spec do casamento bloco ↔ arquivo no playback — o que decide se um trecho
 * pintado de verde REPRODUZ ao clique.
 *
 * 🔴 O DEFEITO (até a v4.17.14). O desenho da barra e a lista resolviam o
 * arquivo pela duração REAL do bloco (`b[1]`); o clique resolvia por
 * `PB.bloco`, uma constante de **60 s**. Esses 60 s são a forma da JIMI — ela
 * pica o cartão em blocos de um minuto —, mas a JT/T entrega blocos de até
 * 5 min. Medido em produção na JC371 do veículo Telecom (`865478070654829`,
 * 07/09/2026): **162 dos 183 blocos vivos duravam 300–301 s**, e por isso
 * **34 dos 38 blocos verdes** respondiam `Este trecho ainda não está no
 * servidor` — o arquivo existia, estava íntegro no disco, e caía depois do
 * primeiro minuto do bloco. Depois do conserto: 38 de 38.
 *
 * 🔴 O SEGUNDO DEFEITO, da mesma família: `pbArquivoDoBloco()` devolvia o
 * primeiro casamento de uma lista ordenada por `event_time DESC`, e 34 dos 38
 * blocos verdes tinham MAIS DE UM arquivo dentro (até 16) — cada alarme sobe
 * `.mp4` **e** `.jpg` com segundos de diferença. Em 4 dos 38 quem ganhava era
 * o `.jpg`, e o player exibia o NOME do arquivo como texto.
 *
 * A verificação é sobre a FUNÇÃO, com dado injetado, e não sobre uma câmera:
 * o que quebrou foi a regra de casamento, e ela é determinística. O dado
 * injetado reproduz a forma medida em produção — bloco de 300 s, arquivo aos
 * +250 s, `.mp4` e `.jpg` a segundos de distância.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

const T = 1757000000;      // instante base, epoch UTC
const DUR_JTT = 300;       // duração real de um bloco da JC371, medida
const DENTRO = 250;        // o arquivo cai aos +250 s — depois do 1º minuto

/** Injeta uma janela e um conjunto de arquivos como o PHP os entrega. */
async function semear(page, arquivos) {
    await page.evaluate(([t, dur, arqs]) => {
        // @ts-ignore — estado global da tela
        PB.janela = [t - 600, t + dur + 600];
        // @ts-ignore
        PB.vista = PB.janela.slice();
        // @ts-ignore
        PB.blocos = [[t, dur, 1]];
        // @ts-ignore
        PB.arquivos = arqs;
    }, [T, DUR_JTT, arquivos]);
}

const VIDEO = { t: T + DENTRO, c: 1, u: 'bloco.mp4', n: 'bloco.mp4', mb: 8.2, st: '', dl: 0, tp: 'video' };
const FOTO  = { t: T + DENTRO + 2, c: 1, u: 'bloco.jpg', n: 'bloco.jpg', mb: 0.2, st: '', dl: 0, tp: 'image' };

test.describe('Playback — bloco verde tem de reproduzir', () => {
    test.beforeEach(async ({ authedPage }) => {
        await authedPage.goto('/video/playback');
        await expect(authedPage.locator('#pb-imei')).toBeVisible();
    });

    test('🔴 o arquivo é achado pela duração REAL do bloco, não por 60 s fixos', async ({ authedPage }) => {
        await semear(authedPage, [VIDEO]);
        const r = await authedPage.evaluate(([t, dur]) => ({
            // @ts-ignore — como a barra e a lista resolvem: duração do bloco
            comDuracaoReal: !!pbArquivoDoBloco(t, dur, 1),
            // @ts-ignore — como o CLIQUE resolvia antes: 60 s fixos
            // @ts-ignore
            comBlocoFixo: !!pbArquivoDoBloco(t, PB.bloco, 1),
        }), [T, DUR_JTT]);

        expect(r.comDuracaoReal, 'a barra pinta de verde porque acha o arquivo').toBeTruthy();
        // Esta asserção é o retrato do defeito: com a janela fixa o MESMO
        // bloco verde não acha nada. É por isso que a duração tem de viajar
        // com o clique — não porque a função esteja errada.
        expect(r.comBlocoFixo, 'com 60 s fixos o arquivo da JT/T some — era o defeito').toBeFalsy();
    });

    test('🔴 clicar num bloco verde da JT/T reproduz, sem alerta de "não está no servidor"', async ({ authedPage }) => {
        await semear(authedPage, [VIDEO]);
        /** @type {string[]} */
        const alertas = [];
        authedPage.on('dialog', async (d) => { alertas.push(d.message()); await d.dismiss(); });

        await authedPage.evaluate(([t, dur]) => {
            // @ts-ignore — a duração viaja com o clique
            pbTocar(t, 1, dur);
        }, [T, DUR_JTT]);

        expect(alertas, 'nenhum alerta — o arquivo existe e o bloco estava verde').toEqual([]);
        const dl = authedPage.locator('#pb-download');
        await expect(dl, 'o botão Baixar aponta para o arquivo achado').toBeVisible();
        expect(await dl.getAttribute('href')).toContain('bloco.mp4');
        await expect(authedPage.locator('#pb-fonte')).toContainText(/Arquivo do servidor/i);
    });

    test('🔴 vídeo ganha de foto quando os dois caem no mesmo bloco', async ({ authedPage }) => {
        // O alarme sobe .mp4 E .jpg com segundos de diferença, e a lista chega
        // em `event_time DESC` — devolver "o primeiro" entregava a miniatura.
        await semear(authedPage, [FOTO, VIDEO]);
        const escolhido = await authedPage.evaluate(([t, dur]) =>
            // @ts-ignore
            pbArquivoDoBloco(t, dur, 1).n, [T, DUR_JTT]);
        expect(escolhido, 'o player recebe o vídeo, não a miniatura').toBe('bloco.mp4');

        // E a ordem inversa na entrada não muda o resultado: o desempate é
        // explícito, não um efeito da ordem em que o banco devolveu.
        await semear(authedPage, [VIDEO, FOTO]);
        const denovo = await authedPage.evaluate(([t, dur]) =>
            // @ts-ignore
            pbArquivoDoBloco(t, dur, 1).n, [T, DUR_JTT]);
        expect(denovo).toBe('bloco.mp4');
    });

    test('bloco em que só a FOTO chegou exibe a foto, não o nome do arquivo', async ({ authedPage }) => {
        await semear(authedPage, [FOTO]);
        /** @type {string[]} */
        const alertas = [];
        authedPage.on('dialog', async (d) => { alertas.push(d.message()); await d.dismiss(); });

        await authedPage.evaluate(([t, dur]) => {
            // @ts-ignore
            pbTocar(t, 1, dur);
        }, [T, DUR_JTT]);

        expect(alertas).toEqual([]);
        // A foto responde a mesma pergunta ("o que aconteceu neste minuto?").
        // Antes, o operador clicava num item verde e via texto.
        await expect(authedPage.locator('#vid-placeholder img')).toHaveCount(1);
        expect(await authedPage.locator('#vid-placeholder img').getAttribute('src'))
            .toContain('bloco.jpg');
        await expect(authedPage.locator('#pb-fonte')).toContainText(/Foto do evento/i);
    });

    test('canal errado continua não casando', async ({ authedPage }) => {
        // A folga da duração não pode virar folga de CANAL: o vídeo da câmera
        // interna não é o do minuto pedido na frontal.
        await semear(authedPage, [{ ...VIDEO, c: 2 }]);
        const achou = await authedPage.evaluate(([t, dur]) =>
            // @ts-ignore
            !!pbArquivoDoBloco(t, dur, 1), [T, DUR_JTT]);
        expect(achou, 'CH2 não pode ser servido como CH1').toBeFalsy();
    });

    test('arquivo FORA do bloco não pinta de verde', async ({ authedPage }) => {
        // O limite superior é exclusivo: um arquivo em `t + dur` pertence ao
        // bloco SEGUINTE, senão dois blocos vizinhos reclamariam o mesmo.
        await semear(authedPage, [{ ...VIDEO, t: T + DUR_JTT }]);
        const achou = await authedPage.evaluate(([t, dur]) =>
            // @ts-ignore
            !!pbArquivoDoBloco(t, dur, 1), [T, DUR_JTT]);
        expect(achou).toBeFalsy();
    });
});
