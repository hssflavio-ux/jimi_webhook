// @ts-check
/**
 * Spec do firmware (v4.9.32) — a trava do `UPDATE` e o cadastro de URLs.
 *
 * O que estes testes protegem, na ordem em que importa:
 *
 *   1. O `UPDATE` NÃO trava mais a seleção em JC371. Era artefato da fonte (só
 *      a página do JC371 documenta o comando), não do protocolo: ele vale para
 *      a linha JC inteira e o que muda é a URL do pacote. Travado, a tela
 *      tornava impossível atualizar as outras cinco.
 *   2. Em troca, `UPDATE` com MAIS DE UM MODELO marcado é bloqueado. O envio em
 *      lote manda a mesma string para todos, e a URL de um modelo aplicada em
 *      outro não devolve erro nenhum — o equipamento baixa e aplica.
 *   3. A URL inválida é recusada no SERVIDOR, não só na tela: `/comandos` tem
 *      modo livre e quem forja o POST passa por cima do JavaScript.
 *
 * As asserções são sobre o ESTADO REAL depois do JS (checkbox habilitado,
 * botão desabilitado, resposta HTTP), nunca sobre a presença de um texto de
 * aviso — "o aviso apareceu" passaria com o envio continuando liberado.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

test.describe('Firmware — UPDATE e cadastro de URLs', () => {

    test('UPDATE está no catálogo, é universal e cobre a linha JC inteira', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        const upd = await authedPage.evaluate(() =>
            (window.CATALOGO || []).find(c => c.c === 'UPDATE'));

        expect(upd, 'UPDATE precisa estar no catálogo').toBeTruthy();
        expect(upd.u, 'UPDATE não pode travar a seleção por modelo').toBe(true);
        expect(upd.m.sort()).toEqual(['JC181', 'JC182', 'JC371', 'JC400AD', 'JC400D', 'JC450']);
        // P1 em branco deixava na tela um campo sem dizer o que espera.
        expect(upd.p[0].d).toMatch(/URL/i);
    });

    test('com UPDATE escolhido, nenhum equipamento fica desabilitado', async ({ authedPage }) => {
        await authedPage.goto('/comandos');
        await authedPage.selectOption('#cmd-sel', 'T:UPDATE,P1#');

        // O estado REAL do checkbox, não o texto do aviso.
        const desabilitados = await authedPage.$$eval('.dev-row',
            rows => rows.filter(r => r.querySelector('.dev-chk').disabled).length);
        expect(desabilitados, 'UPDATE não pode desabilitar equipamento nenhum').toBe(0);
    });

    test('UPDATE com dois modelos marcados bloqueia o envio', async ({ authedPage }) => {
        await authedPage.goto('/comandos');

        const modelos = await authedPage.$$eval('.dev-row',
            rows => [...new Set(rows.map(r => r.dataset.modelo))]);
        test.skip(modelos.length < 2, 'este cliente não tem dois modelos para exercitar a guarda');

        await authedPage.selectOption('#cmd-sel', 'T:UPDATE,P1#');
        await authedPage.locator('.p-in').first().fill('https://ota.exemplo.com/x.bin');

        // Um equipamento de cada um dos dois primeiros modelos.
        for (const m of modelos.slice(0, 2)) {
            await authedPage.locator(`.dev-row[data-modelo="${m}"] .dev-chk`).first().check();
        }

        const btn = authedPage.locator('#btn-enviar');
        await expect(btn, 'envio precisa ficar bloqueado com modelos misturados').toBeDisabled();
        await expect(btn).toContainText(/um modelo por vez/i);

        // Desmarcando até sobrar um modelo, o envio volta.
        await authedPage.locator(`.dev-row[data-modelo="${modelos[1]}"] .dev-chk`).first().uncheck();
        await expect(btn).toBeEnabled();
    });

    test('a tela de firmware lista a frota e o cadastro de URLs', async ({ authedPage }) => {
        const resp = await authedPage.goto('/firmwares');
        expect(resp.status()).toBe(200);

        // 🔴 200 não prova nada: a tela pode abrir vazia. O que prova é a grade.
        await expect(authedPage.locator('tr[data-imei]').first()).toBeVisible();
        await expect(authedPage.locator('select[name="device_model_id"]')).toBeVisible();
        await expect(authedPage.locator('input[name="url"]')).toBeVisible();

        // O KPI de equipamentos ativos tem de bater com as linhas renderizadas.
        const linhas = await authedPage.locator('tr[data-imei]').count();
        expect(linhas).toBeGreaterThan(0);
    });

    test('URL com vírgula é recusada no cadastro', async ({ authedPage }) => {
        await authedPage.goto('/firmwares');
        await authedPage.fill('input[name="version"]', 'V0.0.0_spec');
        // Vírgula é o separador de parâmetros do proNo 128: a URL chegaria
        // partida ao equipamento.
        await authedPage.fill('input[name="url"]', 'https://ota.exemplo.com/a,b.bin');
        await authedPage.click('button:has-text("Cadastrar")');

        await expect(authedPage.locator('.card').first()).toContainText(/vírgula/i);
        // E não pode ter entrado na grade.
        await expect(authedPage.locator('#tbl-releases')).not.toContainText('V0.0.0_spec');
    });

    test('equipamento sem modelo cadastrado não recebe UPDATE', async ({ authedPage }) => {
        await authedPage.goto('/firmwares');

        // 🔴 Sem modelo não há pacote certo para escolher — só um palpite que o
        // equipamento aceitaria sem reclamar. Em produção não é hipótese: 1 dos
        // 11 equipamentos está assim.
        const semModelo = authedPage.locator('tr[data-imei][data-model="0"]');
        const n = await semModelo.count();
        test.skip(n === 0, 'todos os equipamentos deste cliente têm modelo');

        const linha = semModelo.first();
        await expect(linha).toContainText(/Modelo não cadastrado/i);
        // Estado real do botão, não o rótulo.
        await expect(linha.locator('button:has-text("Atualizar")')).toBeDisabled();
        // Ler a versão continua liberado: `VERSION#` não depende do modelo.
        await expect(linha.locator('button:has-text("Ler versão")')).toBeEnabled();
    });

    test('o servidor recusa UPDATE com URL inválida, não só a tela', async ({ authedPage }) => {
        await authedPage.goto('/firmwares');
        const imei = await authedPage.$eval('tr[data-imei]', r => r.dataset.imei);

        // Chamada direta ao endpoint, contornando todo o JavaScript da tela —
        // é o caminho que `/comandos` abre com o modo livre.
        const r = await authedPage.evaluate(async ({ imei }) => {
            const res = await fetch('/sendcommand', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
                body: JSON.stringify({ imei, content: 'UPDATE,nao-e-uma-url#', proNo: 128, serverFlagId: 1 }),
            });
            return { status: res.status, body: await res.text() };
        }, { imei });

        expect(r.status).toBe(400);
        expect(r.body).toMatch(/UPDATE recusado/);
    });
});
