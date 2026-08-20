// @ts-check
/**
 * Spec da barra do período do playback (v4.9.35).
 *
 * 🔴 O DEFEITO QUE ESTE SPEC EXISTE PARA PEGAR não é a barra: é o **script da
 * tela inteira morrer**. Uma `alert('texto` com quebra de linha literal dentro
 * da string — resíduo de edição — derruba o `<script>` inline com
 * `Invalid or unexpected token`, e a partir daí NENHUMA função da página
 * existe: nem `pbRequestJimi`, nem `requestExtractJimi`, nem `selectRecording`.
 * A página renderiza bonita, o HTML está correto, e todo clique é morto.
 * Aconteceu de verdade em 20/08/2026, e nenhuma verificação de HTML pegaria —
 * só abrir no navegador pegou.
 *
 * Por isso a primeira asserção aqui é "o console está limpo".
 *
 * O resto trava o que a barra promete: uma faixa por canal, segmentos que são
 * SESSÕES (não os 3.021 blocos de um minuto, que virariam uma mancha sólida
 * mentindo "gravou o tempo todo") e o clique que leva à lista.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/**
 * Abre o playback num equipamento que TENHA listagem válida.
 *
 * ⚠️ Não basta pegar o primeiro `<option>`: a ordem é por cliente e nome, e o
 * primeiro equipamento quase nunca é o que foi listado nos últimos 30 min (a
 * listagem vence — o cartão é buffer circular). Pegar o primeiro fazia os dois
 * testes que mais importam PULAREM, e spec que pula não é cobertura.
 *
 * A varredura é limitada: se nenhum equipamento tem listagem válida, não há o
 * que testar neste ambiente — e aí o skip é honesto, com o motivo certo.
 *
 * @returns {Promise<{imei: string, sessoes: number}>} imei vazio = não achou
 */
/**
 * Garante que existe listagem para desenhar, e devolve o equipamento e a janela.
 *
 * 🔴 SEM ISTO OS DOIS TESTES QUE MAIS IMPORTAM PULAM — e spec que pula não é
 * cobertura. A listagem do cartão VENCE em 30 min (é um retrato de buffer
 * circular), então um teste que dependa de dado pré-existente passa hoje e pula
 * amanhã, sem ninguém perceber que a barra deixou de ser verificada.
 *
 * Com `TEST_IMEI` definido, o spec SEMEIA pelo endpoint real `/filelist/{imei}`
 * — o mesmo caminho da câmera, então o que se testa continua sendo produção e
 * não um atalho. Sem ele, varre os equipamentos atrás de listagem válida e,
 * não achando, pula com o motivo certo.
 *
 * ⚠️ Os nomes semeados ficam num passado DISTANTE (2020) de propósito: se
 * alguém rodar a suíte apontando para um ambiente real, eles não aparecem em
 * nenhuma janela que um operador consulte. O teste pede exatamente esse dia.
 */
const SEED_IMEI = process.env.TEST_IMEI || '';
const SEED_DIA  = '2020-03-01';

/** Lista sintética: 40 blocos, buraco de 30 min, mais 20 — nos dois canais. */
function listaSemeada() {
    const nomes = [];
    const doMinuto = (min, canal) => {
        const d = new Date(Date.UTC(2020, 2, 1, 8, 0, 0) + min * 60000);
        const p = (n) => String(n).padStart(2, '0');
        return `2020_03_${p(d.getUTCDate())}_${p(d.getUTCHours())}_${p(d.getUTCMinutes())}_00_0${canal}.ts`;
    };
    for (const canal of [1, 2]) {
        for (let i = 0; i < 40; i++) nomes.push(doMinuto(i, canal));
        for (let i = 70; i < 90; i++) nomes.push(doMinuto(i, canal));
    }
    return nomes.join(',') + ',';   // vírgula final, como a câmera manda
}

let imeiComDados;   // memo: a varredura custa uma página de centenas de KB por
                    // equipamento, e o worker é o mesmo para todos os testes

async function abrirComSessoes(page, request, maxDevices = 8) {
    if (SEED_IMEI) {
        const r = await request.post(`/filelist/${SEED_IMEI}`, {
            headers: { 'Content-Type': 'application/json' },
            data: { imei: SEED_IMEI, fileNameList: listaSemeada() },
        });
        expect(r.ok(), 'o endpoint /filelist tem de aceitar a semeadura').toBeTruthy();
        await page.goto(`/video/playback?imei=${SEED_IMEI}&channel=1`
            + `&date_from=${SEED_DIA}&date_to=${SEED_DIA}&request=1`);
        return { imei: SEED_IMEI, sessoes: await page.locator('rect.pb-sessao').count(), semeado: true };
    }
    if (imeiComDados !== undefined) {
        if (!imeiComDados) return { imei: '', sessoes: 0, semeado: false };
        await page.goto(`/video/playback?imei=${imeiComDados}&channel=1&request=1`);
        return { imei: imeiComDados, sessoes: await page.locator('rect.pb-sessao').count(), semeado: false };
    }
    const imeis = await page.locator('#pb-imei option').evaluateAll((opts) =>
        opts.map((o) => /** @type {HTMLOptionElement} */ (o).value));
    for (const imei of imeis.slice(0, maxDevices)) {
        await page.goto(`/video/playback?imei=${imei}&channel=1&request=1`);
        const n = await page.locator('rect.pb-sessao').count();
        if (n > 0) { imeiComDados = imei; return { imei, sessoes: n, semeado: false }; }
    }
    imeiComDados = '';
    return { imei: '', sessoes: 0, semeado: false };
}

test.describe('Playback — barra do período', () => {
    test('🔴 o script da tela carrega sem erro de sintaxe', async ({ authedPage }) => {
        /** @type {string[]} */
        const erros = [];
        authedPage.on('pageerror', (e) => erros.push('pageerror: ' + e.message));
        authedPage.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });

        await authedPage.goto('/video/playback');
        await expect(authedPage.locator('#pb-imei')).toBeVisible();

        // Se o <script> morreu, as funções globais não existem — é o sintoma
        // direto, e mais específico que só olhar o console.
        const vivas = await authedPage.evaluate(() => [
            // @ts-ignore
            typeof onSubmitRequest, typeof pbSendCmd, typeof selectRecording,
            // @ts-ignore
            typeof pbRequestJimi, typeof requestExtractJimi, typeof pbIrParaSessao,
        ]);
        expect(erros, 'nenhum erro de console ao abrir a tela').toEqual([]);
        expect(vivas.every((t) => t === 'function'),
            'toda função global da tela precisa existir — `' + vivas.join(',') + '`').toBeTruthy();
    });

    test('a barra só aparece depois de um período pedido', async ({ authedPage }) => {
        await authedPage.goto('/video/playback');
        await expect(authedPage.locator('.pb-barra')).toHaveCount(0);
    });

    test('uma faixa por canal do equipamento, no mesmo eixo', async ({ authedPage }) => {
        await authedPage.goto('/video/playback');
        const imei = await authedPage.locator('#pb-imei option').first().getAttribute('value');
        const cams = Number(await authedPage.locator('#pb-imei option').first().getAttribute('data-cam')) || 1;
        test.skip(!imei, 'nenhum equipamento cadastrado neste ambiente');

        await authedPage.goto(`/video/playback?imei=${imei}&channel=1&request=1`);
        await expect(authedPage.locator('.pb-barra')).toBeVisible();

        // Uma trilha por câmera cadastrada. Os dois canais gravam juntos, então
        // desenhá-los no mesmo eixo é o que torna o desalinhamento visível.
        await expect(authedPage.locator('.pb-trilho')).toHaveCount(cams);
        await expect(authedPage.locator('.pb-canal.atual')).toHaveCount(1);
    });

    test('🔴 os segmentos são SESSÕES, não um por bloco de minuto', async ({ authedPage }) => {
        // A varredura abre uma página pesada por equipamento até achar o que
        // tem listagem válida — o default de 45 s não cobre isso.
        test.setTimeout(150000);
        await authedPage.goto('/video/playback');
        const { imei, sessoes, semeado } = await abrirComSessoes(authedPage, authedPage.request);
        test.skip(!imei, 'defina TEST_IMEI, ou tenha um equipamento com listagem válida');
        await expect(authedPage.locator('.pb-barra')).toBeVisible();

        const itens = await authedPage.locator('.timeline-item').count();

        if (semeado) {
            // 60 blocos por canal, partidos por um buraco de 30 min: DUAS
            // sessões em cada uma das faixas. Números exatos, porque o dado é
            // nosso — é aqui que a agregação fica de fato travada.
            expect(sessoes, 'duas sessões por canal semeado').toBe(4);
            expect(itens, '60 blocos do canal 1 na lista').toBe(60);
        }

        // A fusão só tem valor se REDUZIR. Igualdade significaria que cada bloco
        // virou um segmento, que é exatamente a mancha sólida que queremos
        // evitar. (Com poucos itens a redução pode ser 1:1 legitimamente, daí
        // o piso.)
        if (itens > 20) {
            expect(sessoes, 'a barra tem de agregar blocos contíguos em sessões')
                .toBeLessThan(itens);
        }

        // Todo segmento carrega o resumo no tooltip nativo do SVG — sem ele a
        // barra vira decoração: dá para ver que há gravação e não quando.
        const comTitulo = await authedPage.locator('rect.pb-sessao > title').count();
        expect(comTitulo).toBe(sessoes);
    });

    test('clicar numa faixa leva até a lista', async ({ authedPage }) => {
        test.setTimeout(150000);
        authedPage.on('dialog', (d) => d.dismiss().catch(() => {}));
        await authedPage.goto('/video/playback');
        const { imei } = await abrirComSessoes(authedPage, authedPage.request);
        test.skip(!imei, 'defina TEST_IMEI, ou tenha um equipamento com listagem válida');

        // Uma sessão do canal ATUAL destaca o item; de outro canal recarregaria
        // a página, e é por isso que o teste usa a primeira faixa.
        expect(await authedPage.locator('.timeline-item.alvo').count()).toBe(0);
        await authedPage.locator('rect.pb-sessao').first().click();
        await expect(authedPage.locator('.timeline-item.alvo')).toHaveCount(1);
    });
});
