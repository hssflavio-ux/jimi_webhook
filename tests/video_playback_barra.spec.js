// @ts-check
/**
 * Spec da barra do período do playback e da lista que a acompanha.
 *
 * 🔴 O DEFEITO QUE ESTE SPEC EXISTE PARA PEGAR não é a barra: é o **script da
 * tela inteira morrer**. Uma `alert('texto` com quebra de linha literal dentro
 * da string — resíduo de edição — derruba o `<script>` inline com
 * `Invalid or unexpected token`, e a partir daí NENHUMA função da página
 * existe. A página renderiza bonita, o HTML está correto, e todo clique é
 * morto. Aconteceu de verdade em 20/08/2026, e nenhuma verificação de HTML
 * pegaria — só abrir no navegador pegou. Por isso a primeira asserção aqui é
 * "o console está limpo".
 *
 * O resto trava o que a barra promete: uma faixa por canal, panorama em
 * SESSÕES, zoom que chega ao bloco de um minuto, dica com início e fim ao
 * passar o mouse, e a lista espelhando a janela de zoom.
 *
 * A geometria da linha da lista está aqui pelo mesmo motivo de método: o botão
 * [Extrair] passava por cima do texto e o HTML estava perfeitamente válido —
 * a GEOMETRIA é que não. Asserção de caixas, não de marcação.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/**
 * Semeia uma listagem e abre a tela nela.
 *
 * 🔴 SEM ISTO OS TESTES QUE MAIS IMPORTAM PULAM — e spec que pula não é
 * cobertura. A listagem do cartão VENCE em 30 min (é um retrato de buffer
 * circular), então um teste que dependa de dado pré-existente passa hoje e pula
 * amanhã, sem ninguém perceber que a barra deixou de ser verificada.
 *
 * A semeadura usa o endpoint REAL `/filelist/{imei}` — o mesmo caminho da
 * câmera —, então o que se testa continua sendo produção e não um atalho. Os
 * nomes ficam num passado distante (2020) de propósito: se a suíte apontar
 * para um ambiente real, eles não aparecem em nenhuma janela que um operador
 * consulte, e o teste pede exatamente esse dia.
 */
const SEED_IMEI = process.env.TEST_IMEI || '';
const SEED_DIA = '2020-03-01';

/** 40 blocos, buraco de 30 min, mais 20 — nos dois canais. */
function listaSemeada() {
    const nomes = [];
    const nome = (min, canal) => {
        const d = new Date(Date.UTC(2020, 2, 1, 8, 0, 0) + min * 60000);
        const p = (n) => String(n).padStart(2, '0');
        return `2020_03_${p(d.getUTCDate())}_${p(d.getUTCHours())}_${p(d.getUTCMinutes())}_00_0${canal}.ts`;
    };
    for (const canal of [1, 2]) {
        for (let i = 0; i < 40; i++) nomes.push(nome(i, canal));
        for (let i = 70; i < 90; i++) nomes.push(nome(i, canal));
    }
    return nomes.join(',') + ',';   // vírgula final, como a câmera manda
}

async function abrirComDados(page, request) {
    const r = await request.post(`/filelist/${SEED_IMEI}`, {
        headers: { 'Content-Type': 'application/json' },
        data: { imei: SEED_IMEI, fileNameList: listaSemeada() },
    });
    expect(r.ok(), '/filelist tem de aceitar a semeadura').toBeTruthy();
    await page.goto(`/video/playback?imei=${SEED_IMEI}`
        + `&date_from=${SEED_DIA}&date_to=${SEED_DIA}&request=1`);
    await expect(page.locator('#pb-svg rect.pb-trilho').first()).toBeVisible();
}

test.describe('Playback — barra do período', () => {
    test('🔴 o script da tela carrega sem erro de sintaxe', async ({ authedPage }) => {
        /** @type {string[]} */
        const erros = [];
        authedPage.on('pageerror', (e) => erros.push('pageerror: ' + e.message));
        authedPage.on('console', (m) => { if (m.type() === 'error') erros.push(m.text()); });

        await authedPage.goto('/video/playback');
        await expect(authedPage.locator('#pb-imei')).toBeVisible();

        const vivas = await authedPage.evaluate(() => [
            // @ts-ignore — funções globais da tela
            typeof onSubmitRequest, typeof pbSendCmd, typeof selectRecording, typeof pbRequestJimi,
            // @ts-ignore
            typeof pbVerNaCamera, typeof pbSubirStorage, typeof pbZoom, typeof pbTudo, typeof pbIrPara,
        ]);
        expect(erros, 'nenhum erro de console ao abrir a tela').toEqual([]);
        expect(vivas.every((t) => t === 'function'),
            'toda função global da tela precisa existir — `' + vivas.join(',') + '`').toBeTruthy();
    });

    test('a barra só aparece depois de um período pedido', async ({ authedPage }) => {
        await authedPage.goto('/video/playback');
        await expect(authedPage.locator('#pb-svg')).toHaveCount(0);
    });

    test('uma faixa por canal, no mesmo eixo', async ({ authedPage }) => {
        test.skip(!SEED_IMEI, 'defina TEST_IMEI');
        await abrirComDados(authedPage, authedPage.request);
        // Os canais gravam juntos: desenhá-los no mesmo eixo é o que torna o
        // desalinhamento entre câmeras visível de relance.
        expect(await authedPage.locator('rect.pb-trilho').count()).toBeGreaterThanOrEqual(2);
    });

    test('🔴 panorama mostra SESSÕES; o zoom chega ao bloco de um minuto', async ({ authedPage }) => {
        test.skip(!SEED_IMEI, 'defina TEST_IMEI');
        await abrirComDados(authedPage, authedPage.request);

        // Panorama: 120 blocos semeados viram 2 sessões por canal (o buraco de
        // 30 min parte cada canal em duas). Desenhar os 120 individualmente
        // produziria uma mancha sólida que MENTE — diria "gravou o tempo todo"
        // exatamente onde há buraco.
        const panorama = await authedPage.evaluate(() => ({
            sessoes: document.querySelectorAll('rect.pb-sessao').length,
            blocos: document.querySelectorAll('rect.pb-bloco').length,
            blocosNoDado: PB.blocos.length,
        }));
        expect(panorama.blocosNoDado, '120 blocos semeados').toBe(120);
        expect(panorama.sessoes, 'duas sessões por canal').toBe(4);
        expect(panorama.blocos, 'no panorama não se desenha bloco a bloco').toBe(0);

        // Aproximando o suficiente, cada bloco vira um alvo próprio — é o
        // "chegar ao intervalo de 1 vídeo".
        const perto = await authedPage.evaluate(() => {
            pbIrPara(PB.blocos[0][0]);
            for (let i = 0; i < 12; i++) pbZoom(1.8, PB.blocos[0][0]);
            return {
                sessoes: document.querySelectorAll('rect.pb-sessao').length,
                blocos: document.querySelectorAll('rect.pb-bloco').length,
                vista: PB.vista[1] - PB.vista[0],
            };
        });
        expect(perto.blocos, 'aproximado, os blocos aparecem um a um').toBeGreaterThan(0);
        expect(perto.sessoes, 'e as sessões dão lugar a eles').toBe(0);
        expect(perto.vista, 'o zoom máximo é da ordem do bloco').toBeLessThanOrEqual(600);

        // E volta: o botão Tudo devolve o período pedido inteiro.
        const volta = await authedPage.evaluate(() => { pbTudo(); return PB.vista[1] - PB.vista[0]; });
        expect(volta, 'Tudo devolve a janela pedida').toBeGreaterThan(perto.vista);
    });

    test('🔴 a dica do mouse mostra início e fim do trecho', async ({ authedPage }) => {
        test.skip(!SEED_IMEI, 'defina TEST_IMEI');
        await abrirComDados(authedPage, authedPage.request);
        await authedPage.evaluate(() => {
            pbIrPara(PB.blocos[0][0]);
            for (let i = 0; i < 12; i++) pbZoom(1.8, PB.blocos[0][0]);
        });

        await authedPage.locator('rect.pb-bloco').first().hover();
        const dica = authedPage.locator('#pb-dica');
        await expect(dica).toHaveClass(/on/);
        // Início — fim, que é o que o dono do produto pediu para ver ao passar
        // o mouse. `HH:MM:SS — HH:MM:SS`, na hora local da câmera.
        await expect(dica).toContainText(/\d{2}:\d{2}:\d{2}\s*—\s*\d{2}:\d{2}:\d{2}/);
        await expect(dica).toContainText(/clique/i);
    });

    test('a lista espelha a janela de zoom', async ({ authedPage }) => {
        test.skip(!SEED_IMEI, 'defina TEST_IMEI');
        await abrirComDados(authedPage, authedPage.request);

        const tudo = await authedPage.locator('.timeline-item').count();
        expect(tudo, 'no panorama, a lista traz o período inteiro').toBe(120);

        // 🔴 É isto que elimina o teto de itens: em vez de cortar as 500 mais
        // recentes e avisar, a tela mostra o que está na vista — e aproximar
        // É filtrar.
        await authedPage.evaluate(() => {
            pbIrPara(PB.blocos[0][0]);
            for (let i = 0; i < 12; i++) pbZoom(1.8, PB.blocos[0][0]);
        });
        const perto = await authedPage.locator('.timeline-item').count();
        expect(perto, 'aproximado, a lista encolhe junto').toBeLessThan(tudo);
        expect(perto, 'e ainda mostra algo').toBeGreaterThan(0);
    });

    test('vazio é ACIONÁVEL: leva até a gravação mais próxima', async ({ authedPage }) => {
        test.skip(!SEED_IMEI, 'defina TEST_IMEI');
        await abrirComDados(authedPage, authedPage.request);
        // O buraco de 30 min entre as duas sessões semeadas. Dois terços de um
        // cartão real são buraco, então cair num é o caso NORMAL — e "nada
        // aqui" sem saída deixa o usuário arrastando às cegas.
        // ⚠️ Acha o buraco MEDINDO, e não por índice: os dois canais chegam
        // intercalados por tempo, então `blocos[39]` não é o fim da primeira
        // sessão — é o vigésimo minuto dela. Foi o que fez este teste falhar
        // apontando para o lugar errado.
        await authedPage.evaluate(() => {
            const ts = [...new Set(PB.blocos.map((b) => b[0]))].sort((a, b) => a - b);
            let maior = 0, em = ts[0];
            for (let i = 1; i < ts.length; i++) {
                if (ts[i] - ts[i - 1] > maior) { maior = ts[i] - ts[i - 1]; em = ts[i - 1]; }
            }
            pbAplicarVista(em + 300, em + 900);   // dentro do vazio
        });
        const botao = authedPage.locator('#pb-lista button', { hasText: /gravação mais próxima/i });
        await expect(botao).toBeVisible();
        await botao.click();
        await expect.poll(async () => authedPage.locator('.timeline-item').count()).toBeGreaterThan(0);
    });
});

test.describe('Playback — lista de gravações', () => {
    test('🔴 o texto da linha nunca invade o botão', async ({ authedPage }) => {
        test.skip(!SEED_IMEI, 'defina TEST_IMEI');
        await abrirComDados(authedPage, authedPage.request);

        const linhas = authedPage.locator('.timeline-item');
        expect(await linhas.count(), 'a lista precisa ter linhas').toBeGreaterThan(0);

        // ⚠️ O TEXTO REAL É CURTO ("1 min"), e com ele a colisão não acontece
        // nem sem a correção — um teste que só medisse o conteúdo atual PASSA
        // com o defeito no lugar, e vira decoração. O contrato a travar é o do
        // LAYOUT: por mais longo que o texto fique, ele encolhe, e a ação não.
        const medida = await linhas.first().evaluate((it) => {
            const meta = it.querySelector('.tl-meta');
            const acao = it.querySelector('.pb-extract') || it.querySelector('.pb-badge');
            if (!meta || !acao) return null;
            const original = meta.textContent;
            meta.textContent = 'descrição deliberadamente longa '.repeat(12);
            const a = meta.getBoundingClientRect(), b = acao.getBoundingClientRect();
            const r = { invade: a.right > b.left + 0.5, acaoVisivel: b.width > 1,
                        truncou: meta.scrollWidth > meta.clientWidth };
            meta.textContent = original;
            return r;
        });
        expect(medida, 'a linha precisa ter texto e ação').not.toBeNull();
        expect(medida.invade, 'texto longo NÃO pode ser pintado sobre a ação').toBeFalsy();
        expect(medida.acaoVisivel, 'a ação não pode ser espremida a zero').toBeTruthy();
        expect(medida.truncou, 'o texto que não cabe tem de ser truncado, não transbordar').toBeTruthy();

        const cortados = await linhas.locator('.tl-meta').evaluateAll((ms) =>
            ms.slice(0, 30).filter((m) => m.scrollWidth > m.clientWidth + 1).length);
        expect(cortados, 'a duração não pode ser cortada na largura padrão').toBe(0);
    });

    test('a data é dita uma vez por dia; o canal, em cada linha', async ({ authedPage }) => {
        test.skip(!SEED_IMEI, 'defina TEST_IMEI');
        await abrirComDados(authedPage, authedPage.request);

        const dias = await authedPage.locator('.tl-dia').allTextContents();
        expect(dias.length, 'a lista precisa de separador de dia').toBeGreaterThan(0);
        expect(new Set(dias).size, 'cada dia aparece uma única vez').toBe(dias.length);

        const hora = await authedPage.locator('.tl-hora').first().textContent();
        expect((hora || '').trim()).toMatch(/^\d{2}:\d{2}:\d{2}$/);

        // 🔴 O canal VOLTOU para a linha, e isso não contradiz tê-lo removido
        // antes: naquele momento a lista era de um canal só e ele não variava.
        // Agora a requisição traz os dois, então ele varia — e o que varia é
        // exatamente o que a linha tem de dizer.
        const canais = await authedPage.locator('.tl-canal').allTextContents();
        expect(canais.length, 'toda linha diz o canal').toBe(await authedPage.locator('.timeline-item').count());
        expect(new Set(canais.map((c) => c.trim())).size, 'os dois canais aparecem').toBeGreaterThan(1);
    });
});
