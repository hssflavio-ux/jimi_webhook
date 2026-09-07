// @ts-check
/**
 * Spec da grade de Downloads: a LARGURA das colunas e o ALARME de cada arquivo.
 *
 * 🔴 O DEFEITO DE LARGURA (até a v4.17.16). "Arquivo" era a primeira coluna,
 * com `max-width:200px` e reticências, e o nome precisa de **894 px** — 57
 * caracteres no caso simples, **119** quando a câmera JIMI anuncia frontal e
 * interna no mesmo campo. O que sobrava na tela era `<imei>_303635…`: o IMEI,
 * que tem coluna própria ao lado, mais o começo de um blob igual em todas as
 * linhas. A coluna mostrava só o que se repete.
 *
 * ⚠️ **Um teste que só medisse o conteúdo ATUAL passaria com o defeito no
 * lugar** em qualquer tela larga. O contrato travado aqui é o do LAYOUT: por
 * mais longo que o nome seja, ele **quebra** — nunca é cortado —, e a tabela
 * rola dentro do card em vez de empurrar a página. É a mesma lição do spec da
 * lista de playback, onde o texto real curto escondia a colisão.
 *
 * 🔴 O ALARME (v4.17.17). A fila é, na prática, uma fila de anexos de alarme:
 * medido em produção, **2.999 de 3.000** arquivos dos últimos 30 dias têm
 * alarme identificável. O que se trava aqui é que a coluna RESOLVE — uma
 * coluna que existe e vem vazia é pior que coluna nenhuma, porque parece dado
 * faltando no equipamento.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

test.describe('Downloads — largura das colunas', () => {
    test.beforeEach(async ({ authedPage }) => {
        await authedPage.goto('/video/downloads');
        await expect(authedPage.locator('table')).toBeVisible();
    });

    test('🔴 o nome do arquivo nunca é cortado, por mais longo que seja', async ({ authedPage }) => {
        const linhas = await authedPage.locator('td.dl-nome').count();
        test.skip(!linhas, 'nenhum arquivo na fila — nada a medir');

        // O texto REAL pode caber num monitor largo, e aí a medição não prova
        // nada. Injeta-se um nome deliberadamente longo — que é a forma que a
        // câmera JIMI produz (dois arquivos num campo só) — e mede-se de novo.
        const medida = await authedPage.locator('td.dl-nome').first().evaluate((td) => {
            const alvo = td.querySelector('.dl-arq-txt');
            if (!alvo) return null;
            const original = alvo.textContent;
            alvo.textContent = 'EVENT_862798051583785_00000000_2026_09_07_11_12_28_I_40.mp4'
                             + 'EVENT_862798051583785_00000000_2026_09_07_11_12_28_F_39.mp4';
            const r = {
                cortado: alvo.scrollWidth > alvo.clientWidth + 1,
                quebrou: alvo.getBoundingClientRect().height > 20,
                larguraCelula: td.getBoundingClientRect().width,
            };
            alvo.textContent = original;
            return r;
        });
        expect(medida, 'a célula do nome precisa existir').not.toBeNull();
        expect(medida.cortado, 'nome longo tem de QUEBRAR, nunca ser cortado').toBeFalsy();
        expect(medida.quebrou, 'e quebrar quer dizer ocupar mais de uma linha').toBeTruthy();
        // O piso protege a coluna de ser espremida a nada quando há muitas
        // outras: sem ele, o navegador reparte a folga por igual e a única
        // coluna que precisa dela é a que não recebe.
        expect(medida.larguraCelula, 'a coluna tem um piso de largura').toBeGreaterThanOrEqual(270);
    });

    test('nenhuma célula da grade fica com texto cortado', async ({ authedPage }) => {
        const total = await authedPage.locator('tbody td').count();
        test.skip(!total, 'grade vazia');
        const cortadas = await authedPage.locator('tbody td').evaluateAll((tds) =>
            tds.filter((t) => t.scrollWidth > t.clientWidth + 1).length);
        expect(cortadas, 'nenhuma célula pode esconder conteúdo').toBe(0);
    });

    test('em tela estreita a tabela rola no card, e a PÁGINA não rola', async ({ authedPage }) => {
        // O pior caso aceitável é rolagem dentro do card. O inaceitável é a
        // página inteira rolar na horizontal, que quebra toda a navegação.
        await authedPage.setViewportSize({ width: 900, height: 800 });
        const r = await authedPage.evaluate(() => {
            const w = document.querySelector('.table-wrap');
            return {
                rolaNoCard: w.scrollWidth > w.clientWidth,
                paginaRola: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
                cortadas: [...document.querySelectorAll('tbody td')]
                    .filter((t) => t.scrollWidth > t.clientWidth + 1).length,
            };
        });
        expect(r.paginaRola, 'a página nunca rola na horizontal').toBeFalsy();
        expect(r.cortadas, 'apertado, o texto quebra — não some').toBe(0);
    });

    test('o botão de rebaixar diz "Baixar novamente"', async ({ authedPage }) => {
        await authedPage.goto('/video/downloads?status=baixado');
        const n = await authedPage.locator('a.btn', { hasText: /Baixar/i }).count();
        test.skip(!n, 'nenhum arquivo já baixado na fila');
        await expect(authedPage.locator('a.btn', { hasText: /Baixar novamente/i }).first()).toBeVisible();
        await expect(authedPage.locator('a.btn', { hasText: /Baixar de novo/i })).toHaveCount(0);
    });
});

test.describe('Downloads — o alarme de cada arquivo', () => {
    test('🔴 a coluna Alarme RESOLVE, não vem vazia', async ({ authedPage }) => {
        await authedPage.goto('/video/downloads');
        const celulas = await authedPage.locator('td.dl-alarme').count();
        test.skip(!celulas, 'nenhum arquivo na fila — nada a resolver');

        const r = await authedPage.locator('td.dl-alarme').evaluateAll((tds) => ({
            total: tds.length,
            comAlarme: tds.filter((t) => t.querySelector('.dl-alm-nome')).length,
            onDemand: tds.filter((t) => t.querySelector('.dl-alm-tag')).length,
            semNada: tds.filter((t) => t.querySelector('.dl-alm-sem')).length,
            vazias: tds.filter((t) => !t.textContent.trim()).length,
        }));

        // 🔴 Toda célula diz alguma coisa: o alarme, ou "On demand", ou o traço
        // honesto de "não sei". Vazia é o estado que não pode existir — o
        // operador a leria como dado faltando no equipamento.
        expect(r.vazias, 'célula vazia é o único desfecho proibido').toBe(0);
        expect(r.comAlarme + r.onDemand + r.semNada,
            'toda célula tem um dos três desfechos').toBeGreaterThanOrEqual(r.total);
        // Medido em produção: 2.999 de 3.000 têm alarme. A margem é generosa
        // de propósito — o que se pega aqui é a resolução ter parado de
        // funcionar, não uma flutuação da fila.
        expect(r.comAlarme, 'a maioria esmagadora da fila é anexo de alarme').toBeGreaterThan(r.total / 2);
    });

    test('o alarme vem com nome legível e hora, não com o código cru', async ({ authedPage }) => {
        await authedPage.goto('/video/downloads');
        const nomes = authedPage.locator('.dl-alm-nome');
        test.skip(!(await nomes.count()), 'nenhum arquivo com alarme na fila');

        const textos = await nomes.allTextContents();
        // `alarm_label_sql()` re-resolve `Código NNNN` contra o catálogo atual:
        // ver o nome cru na tela quer dizer que o rótulo deixou de passar por
        // ele — o mesmo ponto único que /relatorios/alarmes usa.
        expect(textos.every((t) => t.trim().length > 0), 'nome do alarme não pode ser vazio').toBeTruthy();
        const crus = textos.filter((t) => /^C[óo]digo \d+ \((JTT|JIMI)\)$/i.test(t.trim()));
        expect(crus, 'código cru significa alarme fora do catálogo — cadastre-o').toEqual([]);

        const horas = await authedPage.locator('.dl-alm-hora').allTextContents();
        expect(horas.length, 'o alarme resolvido traz a hora junto').toBeGreaterThan(0);
        expect(horas[0].trim()).toMatch(/^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}/);
    });

    test('🔴 "On demand" nunca é o rótulo de quem só não achou alarme', async ({ authedPage }) => {
        // A tentação é rotular "On demand" tudo que não casou com alarme, e
        // isso vira uma afirmação sobre a INTENÇÃO de uma pessoa feita a partir
        // de um dado que faltou: um anexo de alarme com vínculo quebrado
        // apareceria como pedido do operador. O selo sai da ORIGEM do arquivo
        // (`source_type`), verificada em tests/helpers/media.test.php.
        //
        // Aqui se trava o efeito na tela: quem NÃO tem alarme e NÃO tem origem
        // de extração mostra o traço honesto, nunca a pílula.
        await authedPage.goto('/video/downloads');
        const celulas = await authedPage.locator('td.dl-alarme').count();
        test.skip(!celulas, 'fila vazia');

        const conflito = await authedPage.locator('td.dl-alarme').evaluateAll((tds) =>
            tds.filter((t) => t.querySelector('.dl-alm-tag') && t.querySelector('.dl-alm-sem')).length);
        expect(conflito, 'nenhuma célula pode dizer "On demand" e "não sei" ao mesmo tempo').toBe(0);

        const tags = await authedPage.locator('.dl-alm-tag').allTextContents();
        for (const t of tags) expect(t.trim()).toBe('On demand');
    });
});
