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
 * blocos verdes tinham MAIS DE UM arquivo dentro (até 16) — o resultado
 * dependia da ordem em que o banco devolveu. Agora ganha o mais ANTIGO, que é
 * o começo do trecho.
 *
 * ⚠️ FOTO NÃO ENTRA nesta tela (decisão do dono do produto, 07/09/2026), e o
 * corte é no PHP — `media_pb_reproduzivel()`, travada em
 * `tests/helpers/media.test.php`. Aqui se verifica o outro lado: que a lista
 * entregue ao navegador NÃO traz imagem, o que é a asserção que pega o corte
 * tendo sido desfeito na origem.
 *
 * A verificação é sobre a FUNÇÃO, com dado injetado, e não sobre uma câmera:
 * o que quebrou foi a regra de casamento, e ela é determinística. O dado
 * injetado reproduz a forma medida em produção — bloco de 300 s, arquivo aos
 * +250 s.
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
const OUTRO = { t: T + DENTRO + 30, c: 1, u: 'tarde.mp4', n: 'tarde.mp4', mb: 6.0, st: '', dl: 0, tp: 'video' };

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

    test('🔴 com vários arquivos no bloco, ganha o MAIS ANTIGO — nas duas ordens de entrada', async ({ authedPage }) => {
        // 34 dos 38 blocos verdes tinham mais de um arquivo dentro (até 16).
        // "O primeiro da lista" fazia o resultado depender da ordem em que o
        // banco devolveu, então a mesma tela mostrava coisas diferentes.
        await semear(authedPage, [OUTRO, VIDEO]);
        const a = await authedPage.evaluate(([t, dur]) =>
            // @ts-ignore
            pbArquivoDoBloco(t, dur, 1).n, [T, DUR_JTT]);
        await semear(authedPage, [VIDEO, OUTRO]);
        const b = await authedPage.evaluate(([t, dur]) =>
            // @ts-ignore
            pbArquivoDoBloco(t, dur, 1).n, [T, DUR_JTT]);
        expect(a, 'o começo do trecho é o que o operador pediu').toBe('bloco.mp4');
        expect(b, 'a ordem de entrada não pode mudar o resultado').toBe('bloco.mp4');
    });

    test('🔴 a tela NÃO recebe foto do servidor — o corte é na origem', async ({ authedPage }) => {
        // Foto não é exibida e não tem ação (decisão do dono do produto,
        // 07/09/2026). O filtro vive em `media_pb_reproduzivel()`, no PHP, e é
        // lá que ele é verificado em detalhe (tests/helpers/media.test.php).
        // Esta asserção é o outro lado: se o corte for desfeito na montagem de
        // `$pbArquivos`, a imagem reaparece aqui — e nos cinco consumidores.
        //
        // ⚠️ Vazio NÃO é aprovação: sem arquivo nenhum a asserção passaria por
        // vacuidade, então o caso é anunciado em vez de contado como cobertura.
        await authedPage.goto('/video/playback?imei=' + (process.env.TEST_IMEI || '')
            + '&date_from=2020-03-01&date_to=2020-03-01&request=1');
        const arquivos = await authedPage.evaluate(() =>
            // @ts-ignore
            (typeof PB === 'undefined' ? [] : PB.arquivos).map((a) => ({ n: a.n, tp: a.tp })));
        test.skip(!arquivos.length, 'nenhum arquivo no período — nada a verificar');
        const imagens = arquivos.filter((a) => a.tp === 'image' || /\.(jpe?g|png|webp)$/i.test(a.n || ''));
        expect(imagens, 'nenhuma imagem pode chegar à tela de playback').toEqual([]);
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
