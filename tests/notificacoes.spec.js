// @ts-check
/**
 * Spec do sino de notificações (v4.4.0) — a dívida "notificacoes.spec.js nunca
 * foi escrita", aberta desde a v4.7.3.
 *
 * Cobre o endpoint `/notificacoesdata` (contrato, 401, CSRF, marcar como lida)
 * e o sino no layout (badge, painel abre/fecha).
 *
 * SOBRE SEMEAR. Notificação não tem caminho de criação pela interface: quem
 * grava é o motor (`includes/notification_engine.php`), chamado pelo worker.
 * Sem dado, "o sino mostra a notificação" e "não vaza a de outro cliente"
 * passariam por VACUIDADE — lista vazia satisfaz as duas. Por isso os testes
 * de dado usam `tests/helpers/seed_notification.php`, que chama a `notify()`
 * real pela config do próprio app.
 *
 * O seeder escreve no banco do `.env` DESTA máquina. Isso só é o mesmo banco
 * que o app sob teste enxerga quando a suíte roda contra o servidor local —
 * o caso normal. Apontando `BASE_URL` para o homolog, o seed iria para o banco
 * errado e o teste acusaria a aplicação por um erro do arranjo: nesse caso o
 * bloco semeado sai de cena, com o motivo dito em voz alta. Os demais testes
 * (contrato, 401, CSRF, UI do sino) rodam sempre.
 */
const { execFileSync } = require('child_process');
const path = require('path');
const { test, expect, hasCreds, BASE_URL } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

const PHP = process.env.PHP_BIN || 'php';
const SEEDER = path.join(__dirname, 'helpers', 'seed_notification.php');
const PREFIXO = `ZZ Notif E2E ${Date.now()}`;

// O app sob teste compartilha o .env desta máquina?
const LOCAL = /^https?:\/\/(127\.0\.0\.1|localhost)(:|\/|$)/.test(BASE_URL);

/** Roda o seeder e devolve o JSON. Lança se o processo falhar. */
function seeder(...args) {
    const out = execFileSync(PHP, [SEEDER, ...args], { encoding: 'utf8' });
    const json = JSON.parse(out.trim().split('\n').pop());
    if (!json.ok) throw new Error(`seeder ${args[0]}: ${json.erro}`);
    return json;
}

/**
 * Abre uma página do dashboard com o sino utilizável.
 *
 * Até a v4.8.x `/resumo` abria o tour de boas-vindas na primeira visita, e o
 * `#tour-overlay` cobria a tela inteira com z-index 10000 — todo clique no sino
 * era interceptado por ele; o teste plantava `jimi_tour_seen_v4` no
 * localStorage para escapar. O tour foi removido na v4.9.0 (a documentação do
 * produto é a wiki), e a asserção mudou de "está escondido" para "não existe":
 * `toBeHidden()` passa por vacuidade num seletor que não casa nada, e seria
 * exatamente o que aconteceria se o overlay voltasse a ser renderizado visível.
 */
async function abrirComSino(page, rota = '/resumo') {
    await page.goto(rota);
    await expect(page.locator('#tour-overlay')).toHaveCount(0);
}

/** GET /notificacoesdata pela sessão da página. */
async function lerNotificacoes(page, lastId = 0) {
    const r = await page.request.get(`/notificacoesdata?last_id=${lastId}`);
    expect(r.ok(), `/notificacoesdata devolveu ${r.status()}`).toBeTruthy();
    return r.json();
}

test.describe('Notificações — endpoint', () => {
    test('sem sessão devolve 401 e não vaza notificação', async ({ request }) => {
        const r = await request.get(BASE_URL + '/notificacoesdata');
        const body = await r.json();
        expect(body.code).toBe(401);
        expect(body.items).toBeUndefined();
    });

    test('autenticado devolve o contrato documentado', async ({ authedPage }) => {
        const data = await lerNotificacoes(authedPage);
        expect(data.code).toBe(0);
        expect(typeof data.unread).toBe('number');
        expect(typeof data.max_id).toBe('number');
        expect(Array.isArray(data.items)).toBe(true);
        expect(Array.isArray(data.popups)).toBe(true);
        // `last_id=0` é a primeira carga: o front só estabelece a régua e não
        // dispara toast, então popup aqui seria alarme velho reaparecendo.
        expect(data.popups).toHaveLength(0);
    });

    test('POST sem token CSRF é recusado com 403', async ({ authedPage }) => {
        const r = await authedPage.request.post('/notificacoesdata', {
            data: { action: 'read_all' },
        });
        expect(r.status()).toBe(403);
        const body = await r.json();
        expect(body.code).toBe(403);
    });
});

test.describe('Notificações — sino no layout', () => {
    test('sino renderiza e o painel abre e fecha', async ({ authedPage }) => {
        await abrirComSino(authedPage);

        const botao = authedPage.locator('#notif-btn');
        const painel = authedPage.locator('#notif-panel');
        await expect(botao).toBeVisible();
        await expect(painel).toBeHidden();

        await botao.click();
        await expect(painel).toBeVisible();
        await expect(painel).toContainText('Marcar todas como lidas');

        // Clique fora fecha. O handler de `document` fecha o painel sempre que
        // o alvo não está dentro de `.notif-wrap`, então serve qualquer ponto
        // neutro — aqui o canto do conteúdo, que não tem link nem botão.
        // (`/resumo` não tem h1/h2: os títulos são das seções internas.)
        await authedPage.locator('.main-content').click({ position: { x: 4, y: 4 } });
        await expect(painel).toBeHidden();
    });

    test('a lista do painel deixa de dizer "Carregando" após o polling', async ({ authedPage }) => {
        await abrirComSino(authedPage);
        await authedPage.locator('#notif-btn').click();
        await expect(authedPage.locator('#notif-list')).not.toContainText('Carregando', { timeout: 15000 });
    });
});

test.describe('Notificações — empilhamento sobre o mapa', () => {
    /**
     * Regressão da v4.8.8: o mapa pintava por cima da lista de notificações em
     * toda tela com mapa.
     *
     * Causa: o Leaflet dá z-index alto aos próprios painéis (tiles 200,
     * marcadores 600, popup 700, controles 1000) e NÃO cria contexto de
     * empilhamento no container. Esses valores subiam para a raiz do documento.
     * O header, por ser `position:sticky; z-index:50`, CRIA contexto — então o
     * `z-index:1200` do painel valia 1200 só dentro do header e 50 no
     * documento. 200 > 50, e o mapa cobria a lista.
     *
     * O que se afirma aqui é a INVARIANTE que corrige isso — o mapa contido no
     * próprio contexto —, não o pixel. Comparação visual é frágil como teste
     * automático; a prova de pintura foi feita por screenshot antes/depois, e
     * está registrada no CHANGELOG. Se alguém remover o `isolation`, este teste
     * cai e diz exatamente por quê.
     */
    test('o mapa não vaza z-index para a raiz do documento', async ({ authedPage }) => {
        await abrirComSino(authedPage, '/rastreamento');
        const mapa = authedPage.locator('.leaflet-container').first();
        await expect(mapa).toBeVisible({ timeout: 20000 });

        const contido = await mapa.evaluate((el) => {
            const cs = getComputedStyle(el);
            // Qualquer uma destas propriedades cria contexto de empilhamento.
            return cs.isolation === 'isolate'
                || (cs.position !== 'static' && cs.zIndex !== 'auto')
                || cs.transform !== 'none'
                || cs.filter !== 'none'
                || cs.contain === 'paint' || cs.contain === 'strict' || cs.contain === 'content';
        });
        expect(contido,
            'o .leaflet-container precisa criar contexto de empilhamento (isolation: isolate), '
            + 'senão os z-index internos do Leaflet (até 1000) sobem para a raiz e cobrem '
            + 'o painel de notificações, que fica preso no contexto do header (z-index 50)')
            .toBe(true);

        // E o painel continua abrindo por cima na tela com mapa
        await authedPage.locator('#notif-btn').click();
        await expect(authedPage.locator('#notif-panel')).toBeVisible();
    });
});

test.describe.serial('Notificações — com dado real', () => {
    test.skip(!LOCAL, `BASE_URL=${BASE_URL} não é local: o seeder escreveria em outro banco que o app sob teste`);

    const tituloA = `${PREFIXO} cliente A`;
    const tituloB = `${PREFIXO} cliente B`;

    test.afterAll(() => {
        try { seeder('limpar', PREFIXO); } catch (e) { /* nada a limpar */ }
    });

    test('notificação nova aparece na lista e conta como não lida', async ({ authedPage }) => {
        const antes = await lerNotificacoes(authedPage);

        // customer 1 é o cliente do usuário E2E (convenção do fixture)
        seeder('criar', '1', tituloA, 'popup');

        const depois = await lerNotificacoes(authedPage);
        expect(depois.unread).toBe(antes.unread + 1);
        expect(depois.items.map((i) => i.title)).toContain(tituloA);

        // E aparece no painel, não só no JSON
        await abrirComSino(authedPage);
        await authedPage.locator('#notif-btn').click();
        await expect(authedPage.locator('#notif-list')).toContainText(tituloA, { timeout: 15000 });
        await expect(authedPage.locator('#notif-badge')).toHaveClass(/show/);
    });

    test('notificação de OUTRO cliente não aparece', async ({ authedPage }) => {
        // Se o cliente 2 não existir nesta base, a FK derruba o seeder e o
        // teste falha dizendo isso — melhor que passar sem ter testado nada.
        seeder('criar', '2', tituloB);

        const data = await lerNotificacoes(authedPage);
        const titulos = data.items.map((i) => i.title);
        // Controle positivo: a do próprio cliente continua na lista. Sem isto,
        // uma lista vazia faria o `not.toContain` passar por vacuidade.
        expect(titulos).toContain(tituloA);
        expect(titulos).not.toContain(tituloB);
    });

    test('popup só vem para o que chegou depois do last_id', async ({ authedPage }) => {
        const base = await lerNotificacoes(authedPage);
        const regua = base.max_id;

        const nova = seeder('criar', '1', `${PREFIXO} popup`, 'popup');
        const data = await lerNotificacoes(authedPage, regua);

        const ids = data.popups.map((p) => p.id);
        expect(ids).toContain(nova.id);
        // A anterior à régua não pode reaparecer como toast
        expect(Math.min(...ids)).toBeGreaterThan(regua);
    });

    test('marcar todas como lidas zera o contador', async ({ authedPage }) => {
        const antes = await lerNotificacoes(authedPage);
        expect(antes.unread, 'os testes anteriores deixaram não lidas para marcar').toBeGreaterThan(0);

        // O endpoint exige X-CSRF-Token; o valor vem do <meta> do layout
        await abrirComSino(authedPage);
        const token = await authedPage.locator('meta[name="csrf-token"]').getAttribute('content');
        expect(token, 'o layout precisa expor o csrf-token no <meta>').toBeTruthy();

        const r = await authedPage.request.post('/notificacoesdata', {
            headers: { 'X-CSRF-Token': token },
            data: { action: 'read_all' },
        });
        expect(r.ok()).toBeTruthy();
        expect((await r.json()).code).toBe(0);

        const depois = await lerNotificacoes(authedPage);
        expect(depois.unread).toBe(0);
    });
});
