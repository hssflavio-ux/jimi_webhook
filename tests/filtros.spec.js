// @ts-check
/**
 * Spec das BARRAS DE FILTRO — o padrão visual e a lista suspensa múltipla.
 *
 * Dois defeitos, os dois de consistência, e consistência é o que ninguém testa
 * até alguém reclamar:
 *
 *   1. **Borda do navegador em vez da do sistema.** O design system só vestia
 *      campo dentro de `.form-group` (formulário de cadastro). As barras de
 *      filtro usavam `<select>` cru com estilo inline que definia padding e
 *      fonte e **esquecia a borda** — cada select herdava a borda padrão do
 *      navegador, cinza e com raio próprio, ao lado de um `input[type=date]`
 *      que trazia o hairline correto. Corrigido com `.filtro-campo`.
 *
 *   2. **Multisseleção por nuvem de chips.** Dezenas de botões que mudavam de
 *      altura conforme o cadastro do cliente e não se pareciam com nenhum outro
 *      filtro. Viraram lista suspensa com seleção (`select_multi.php`), que
 *      mantém o MESMO contrato de saída — hidden com valores por vírgula — para
 *      que consulta, link e export não soubessem da troca.
 *
 * ⚠️ As asserções de estilo comparam os campos ENTRE SI, nunca contra uma cor
 * fixa: valor cravado envelhece junto com o tema, e o que se quer garantir é
 * "todos iguais", que é exatamente o que estava quebrado.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** Borda computada de um elemento, como assinatura comparável. */
const ASSINATURA = (sel) => `[...document.querySelectorAll('${sel}')].map(el=>{const c=getComputedStyle(el);
  return [c.borderTopWidth,c.borderTopStyle,c.borderTopColor,c.borderTopLeftRadius].join(' ')})`;

test.describe('Barras de filtro — padrão visual', () => {
    for (const rota of ['/comandos', '/video/downloads', '/relatorios/alarmes']) {
        test(`${rota}: todo campo do filtro tem a MESMA borda`, async ({ authedPage }) => {
            await authedPage.goto(rota);
            await authedPage.waitForLoadState('domcontentloaded');

            const bordas = await authedPage.evaluate(() =>
                [...document.querySelectorAll('.filtro-campo')].map((el) => {
                    const c = getComputedStyle(el);
                    return [c.borderTopWidth, c.borderTopStyle, c.borderTopColor,
                            c.borderTopLeftRadius].join(' ');
                }));

            expect(bordas.length, 'a tela precisa ter campos de filtro').toBeGreaterThan(1);
            expect(new Set(bordas).size,
                'campos com bordas diferentes — `' + [...new Set(bordas)].join(' | ') + '`').toBe(1);
            expect(bordas[0], 'a borda não pode ser nenhuma').not.toMatch(/^0px|none/);
        });
    }

    test('🔴 nenhuma tela usa mais a nuvem de chips', async ({ authedPage }) => {
        for (const rota of ['/comandos', '/video/downloads', '/relatorios/alarmes', '/bi']) {
            await authedPage.goto(rota);
            await authedPage.waitForLoadState('domcontentloaded');
            expect(await authedPage.locator('.yuv-chip').count(), rota + ' ainda tem chips').toBe(0);
        }
    });

    test('🔴 o campo do veículo se chama PLACA em toda tela', async ({ authedPage }) => {
        // CONVENÇÃO (dono do produto, 20/08/2026): "placa" é o que estiver
        // cadastrado no campo do dispositivo, TEXTO LIVRE, sem formato exigido.
        // O campo já teve TRÊS nomes para a mesma coisa — "Nome do Dispositivo"
        // no cadastro, "Dispositivo" na grade, "Placa" na operação —, o que
        // fazia parecer que eram campos diferentes.
        const rotas = ['/ativos', '/ativos/novo', '/equipamentos?action=novo', '/relatorios',
                       '/config-dispositivos', '/comandos', '/video/downloads',
                       '/video/playback', '/relatorios/alarmes'];
        for (const rota of rotas) {
            const resp = await authedPage.goto(rota);
            expect(resp && resp.status(), rota + ' não abriu').toBeLessThan(400);
            await authedPage.waitForLoadState('domcontentloaded');

            const rotulos = await authedPage.evaluate(() =>
                [...document.querySelectorAll('label, th')].map((e) => e.textContent.trim()));

            expect(rotulos.some((r) => /^placa/i.test(r)), rota + ': falta o rótulo Placa').toBeTruthy();
            expect(rotulos.filter((r) => /^(nome do )?dispositivo\s*\*?$/i.test(r)),
                rota + ': ainda chama o campo de "Dispositivo"').toEqual([]);
        }
    });

    test('🔴 o filtro de veículo é por PLACA, não por IMEI', async ({ authedPage }) => {
        for (const rota of ['/comandos', '/video/downloads', '/video/playback', '/relatorios/alarmes']) {
            await authedPage.goto(rota);
            await authedPage.waitForLoadState('domcontentloaded');

            const rotulos = await authedPage.locator('label').allTextContents();
            expect(rotulos.some((r) => /placa/i.test(r)), rota + ': falta o rótulo Placa').toBeTruthy();
            expect(rotulos.some((r) => /^\s*equipamento\s*$/i.test(r)),
                rota + ': ainda chama o veículo de "Equipamento"').toBeFalsy();

            // O VALOR continua sendo o IMEI — é por ele que as consultas casam,
            // e duas placas iguais no cadastro se confundiriam num filtro por
            // nome. O que muda é o que a pessoa lê e escolhe.
            const sel = authedPage.locator('select[name="imei"]');
            if (await sel.count()) {
                const textos = await sel.first().locator('option').allTextContents();
                const soDigitos = textos.filter((t) => /^\s*\d{15,17}\s*$/.test(t));
                expect(soDigitos, rota + ': há opção mostrando IMEI cru como se fosse placa').toEqual([]);
            }
        }
    });
});

test.describe('Lista suspensa com seleção múltipla', () => {
    test('abre, marca, resume e serializa no mesmo parâmetro', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        const raiz = authedPage.locator('#msel-alarmtypes');
        await expect(raiz).toBeVisible();

        // Fechada e sem seleção, diz o estado — não fica em branco.
        await expect(raiz.locator('.txt')).toHaveText(/todos/i);
        await expect(raiz.locator('.msel-painel')).toBeHidden();

        await raiz.locator('.msel-botao').click();
        await expect(raiz.locator('.msel-painel')).toBeVisible();
        const itens = raiz.locator('.msel-item input');
        expect(await itens.count(), 'o filtro precisa de opções').toBeGreaterThan(1);

        await itens.nth(0).check();
        await expect(raiz.locator('.txt'), 'com uma marcada, o resumo mostra QUAL').not.toHaveText(/todos/i);
        await itens.nth(1).check();
        await expect(raiz.locator('.txt')).toHaveText(/2 selecionados/);

        // 🔴 O contrato de saída: hidden com os valores por vírgula, no mesmo
        // parâmetro de antes. É o que permitiu trocar o componente sem tocar em
        // consulta, link ou export.
        const hidden = raiz.locator('.msel-hidden');
        expect(await hidden.getAttribute('name')).toBe('alarm_types');
        const valor = await hidden.inputValue();
        expect(valor.split(',').filter(Boolean)).toHaveLength(2);
    });

    test('a busca filtra e avisa quando não acha', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        const raiz = authedPage.locator('#msel-alarmtypes');
        await raiz.locator('.msel-botao').click();

        const total = await raiz.locator('.msel-item').count();
        test.skip(total < 8, 'busca só aparece com muitas opções');

        await raiz.locator('.msel-busca').fill('zzzznaoexiste');
        await expect(raiz.locator('.msel-nada')).toBeVisible();
        const visiveis = await raiz.locator('.msel-item:visible').count();
        expect(visiveis, 'busca sem resultado não mostra item').toBe(0);

        await raiz.locator('.msel-busca').fill('');
        await expect.poll(async () => raiz.locator('.msel-item:visible').count()).toBe(total);
    });

    test('marcar todos respeita a busca em curso', async ({ authedPage }) => {
        // Senão "Marcar todos" com um filtro digitado marcaria coisas que a
        // pessoa não está vendo — e ela só descobriria pelo resultado.
        await authedPage.goto('/relatorios/alarmes');
        const raiz = authedPage.locator('#msel-alarmtypes');
        await raiz.locator('.msel-botao').click();
        const total = await raiz.locator('.msel-item').count();
        test.skip(total < 8, 'busca só aparece com muitas opções');

        await raiz.locator('.msel-busca').fill('adas');
        const visiveis = await raiz.locator('.msel-item:visible').count();
        expect(visiveis, 'a busca precisa achar algo').toBeGreaterThan(0);
        expect(visiveis, 'e precisa filtrar de verdade').toBeLessThan(total);

        await raiz.locator('.msel-acoes button', { hasText: /marcar todos/i }).click();
        const marcados = (await raiz.locator('.msel-hidden').inputValue()).split(',').filter(Boolean);
        expect(marcados.length, 'marcou só o que estava visível').toBe(visiveis);
    });

    test('fecha ao clicar fora e com Esc', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        const painel = authedPage.locator('#msel-alarmtypes .msel-painel');

        await authedPage.locator('#msel-alarmtypes .msel-botao').click();
        await expect(painel).toBeVisible();
        await authedPage.locator('h1, h2').first().click();
        await expect(painel).toBeHidden();

        await authedPage.locator('#msel-alarmtypes .msel-botao').click();
        await expect(painel).toBeVisible();
        await authedPage.keyboard.press('Escape');
        await expect(painel).toBeHidden();
    });
});
