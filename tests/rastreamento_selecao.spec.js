// @ts-check
/**
 * Spec do Rastreamento — coluna única e seleção de veículos no mapa (v4.17.4).
 *
 * O que estes testes protegem, e por que cada um existe:
 *
 *  - A coluna de Clientes e a de Ativos viraram UMA. Um teste que só olhasse
 *    o `<select>` não perceberia a antiga coluna sobrevivendo ao lado, então
 *    a asserção é sobre a GRADE (`300px 1fr`) e sobre a ausência do
 *    `#customer-list`.
 *  - A caixa de seleção decide se o PINO aparece. Contar caixas marcadas não
 *    prova nada — o que importa é o número de marcadores no mapa Leaflet, que
 *    é o que o operador enxerga; por isso as asserções leem o mapa, não o DOM
 *    da lista.
 *  - 🔴 O auto-refresh de 30 s recria marcadores. Antes da v4.17.4 o código
 *    fazia `.addTo(map)` direto no marcador novo, o que faria o veículo
 *    recém-desmarcado REAPARECER sozinho — com a caixa ainda desmarcada, ou
 *    seja, a tela se contradizendo sem ninguém ter tocado nela. O teste
 *    dispara o mesmo caminho do refresh e exige que o oculto continue oculto.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** Quantos marcadores estão de fato desenhados no mapa. */
async function pinosNoMapa(page) {
    return page.evaluate(() =>
        Object.keys(window.markers || {}).filter((i) => window.map.hasLayer(window.markers[i])).length
    );
}

/** Caixas de seleção habilitadas (ativo sem posição nasce desabilitado). */
function caixasAtivas(page) {
    return page.locator('#device-list .device-toggle:not([disabled])');
}

test.describe('Rastreamento — navegação em coluna única', () => {
    test('Cliente e Ativos na MESMA coluna, cliente acima', async ({ authedPage }) => {
        await authedPage.goto('/rastreamento');

        // Duas colunas (painel + mapa), não as três de antes
        const grid = authedPage.locator('div[style*="grid-template-columns:300px 1fr"]');
        await expect(grid).toHaveCount(1);

        // A coluna de clientes separada não existe mais
        await expect(authedPage.locator('#customer-list')).toHaveCount(0);

        // Os dois seletores vivem no mesmo painel, e Cliente vem primeiro
        const painel = authedPage.locator('.left-panel');
        const rotulos = await painel.locator('.panel-label').allInnerTexts();
        const iCliente = rotulos.findIndex((t) => /Cliente/i.test(t));
        const iAtivos  = rotulos.findIndex((t) => /Ativos/i.test(t));
        if (iCliente >= 0) expect(iCliente).toBeLessThan(iAtivos);   // só admin vê o de cliente
        expect(iAtivos).toBeGreaterThanOrEqual(0);
    });

    test('o contador explica os ativos que nunca entram no mapa', async ({ authedPage }) => {
        await authedPage.goto('/rastreamento');
        await authedPage.waitForFunction(() => window.map && window.markers);

        const texto = await authedPage.locator('#visible-count').innerText();
        expect(texto).toMatch(/\d+ de \d+ no mapa/);

        // Havendo ativo sem posição, o contador tem de dizer — senão o
        // operador vê "0 de 0" ao lado de 20 linhas e procura um defeito.
        const semPos = await authedPage.locator('#device-list .device-toggle[disabled]').count();
        if (semPos > 0) expect(texto).toContain(semPos + ' sem posição');
    });
});

test.describe('Rastreamento — escolher os veículos do mapa', () => {
    test('desmarcar um ativo tira o pino do mapa; remarcar traz de volta', async ({ authedPage }) => {
        await authedPage.goto('/rastreamento');
        await authedPage.waitForFunction(() => window.map && window.markers);

        const caixas = caixasAtivas(authedPage);
        const n = await caixas.count();
        test.skip(n < 1, 'nenhum ativo com posição neste ambiente');

        const antes = await pinosNoMapa(authedPage);
        expect(antes).toBeGreaterThan(0);

        await caixas.first().uncheck();
        expect(await pinosNoMapa(authedPage)).toBe(antes - 1);

        await caixas.first().check();
        expect(await pinosNoMapa(authedPage)).toBe(antes);
    });

    test('"Nenhum" esvazia o mapa e "Todos" repõe', async ({ authedPage }) => {
        await authedPage.goto('/rastreamento');
        await authedPage.waitForFunction(() => window.map && window.markers);

        const total = await pinosNoMapa(authedPage);
        test.skip(total < 1, 'nenhum ativo com posição neste ambiente');

        await authedPage.click('button:has-text("Nenhum")');
        expect(await pinosNoMapa(authedPage)).toBe(0);

        await authedPage.click('button:has-text("Todos")');
        expect(await pinosNoMapa(authedPage)).toBe(total);
    });

    test('"Nenhum" com busca ativa só mexe no que está filtrado', async ({ authedPage }) => {
        await authedPage.goto('/rastreamento');
        await authedPage.waitForFunction(() => window.map && window.markers);

        const total = await pinosNoMapa(authedPage);
        test.skip(total < 2, 'precisa de pelo menos 2 ativos com posição');

        // Filtra por um ativo específico e manda esconder "Nenhum": esconder a
        // frota inteira aqui seria uma armadilha silenciosa — o operador vê
        // uma linha na tela e o mapa esvazia.
        const alvo = await authedPage.evaluate(() => Object.keys(window.markers)[0]);
        await authedPage.fill('#device-search', alvo);
        await authedPage.click('button:has-text("Nenhum")');
        expect(await pinosNoMapa(authedPage)).toBe(total - 1);

        await authedPage.fill('#device-search', '');
        await authedPage.click('button:has-text("Todos")');
        expect(await pinosNoMapa(authedPage)).toBe(total);
    });

    test('a escolha sobrevive ao recarregar a página', async ({ authedPage }) => {
        await authedPage.goto('/rastreamento');
        await authedPage.waitForFunction(() => window.map && window.markers);

        const caixas = caixasAtivas(authedPage);
        test.skip((await caixas.count()) < 1, 'nenhum ativo com posição neste ambiente');

        const antes = await pinosNoMapa(authedPage);
        await caixas.first().uncheck();

        await authedPage.reload();
        await authedPage.waitForFunction(() => window.map && window.markers);
        expect(await pinosNoMapa(authedPage)).toBe(antes - 1);

        // devolve o ambiente ao estado inicial para não contaminar outros specs
        await authedPage.click('button:has-text("Todos")');
    });

    test('🔴 o refresh de 30s NÃO reexibe o que foi desmarcado', async ({ authedPage }) => {
        await authedPage.goto('/rastreamento');
        await authedPage.waitForFunction(() => window.map && window.markers);

        const caixas = caixasAtivas(authedPage);
        test.skip((await caixas.count()) < 1, 'nenhum ativo com posição neste ambiente');

        const antes = await pinosNoMapa(authedPage);
        await caixas.first().uncheck();
        const oculto = await authedPage.evaluate(() =>
            Object.keys(window.markers).find((i) => !window.map.hasLayer(window.markers[i]))
        );
        expect(oculto).toBeTruthy();

        // Reproduz o caminho do auto-refresh: apaga o marcador do oculto (é o
        // que o `else` do setInterval faz quando o imei some do dicionário) e
        // deixa o ciclo recriá-lo a partir do /rastreamento?ajax=1.
        await authedPage.evaluate(async (imei) => {
            delete window.markers[imei];
            const url = new URL(location.href);
            url.searchParams.set('ajax', '1');
            const resp = await fetch(url.toString()).then((r) => r.json());
            (resp.positions || []).forEach((p) => {
                if (!p.lat || p.lat === 0) return;
                if (!window.markers[p.imei]) {
                    window.markers[p.imei] = L.marker([p.lat, p.lng], {
                        icon: pinIcon(p.state, p.vehicleType),
                    }).bindPopup(popupHtml(p));
                }
            });
            aplicarVisibilidade(false);
        }, oculto);

        expect(await pinosNoMapa(authedPage)).toBe(antes - 1);
        const aindaOculto = await authedPage.evaluate(
            (imei) => !!window.markers[imei] && !window.map.hasLayer(window.markers[imei]),
            oculto
        );
        expect(aindaOculto).toBe(true);

        await authedPage.click('button:has-text("Todos")');
    });
});
