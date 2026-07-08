// @ts-check
/**
 * Spec de navegação (Fase M.4 §4.4): todas as rotas da sidebar renderizam
 * sem erro 500/fatal e mantêm o shell do dashboard.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

const ROUTES = [
    '/',
    '/rastreamento',
    '/bi',
    '/ocorrencias/dashboard',
    '/comandos',
    '/exportar',
    '/video/aovivo',
    '/video/playback',
    '/video/downloads',
    '/relatorios/posicoes',
    '/relatorios/deslocamento',
    '/relatorios/desatualizados',
    '/relatorios/alarmes',
    '/relatorios/ocorrencias',
    '/ativos',
    '/ativos/novo',
    '/chips',
    '/clientes',
    '/equipamentos',
    '/grupos-permissao',
    '/motoristas',
    '/config-ocorrencias',
    '/usuarios',
    '/checklist',
    '/perfil',
];

test.describe('Navegação — rotas da sidebar', () => {
    for (const route of ROUTES) {
        test(`rota ${route} renderiza sem erro`, async ({ authedPage }) => {
            const response = await authedPage.goto(route);
            expect(response, `sem resposta para ${route}`).toBeTruthy();
            expect(response.status(), `status HTTP de ${route}`).toBeLessThan(500);
            // Sem fatal error/stack trace do PHP no corpo
            const body = await authedPage.locator('body').innerText();
            expect(body).not.toMatch(/Fatal error|Parse error|Uncaught (Error|Exception)/);
            // Shell do dashboard presente (páginas autenticadas)
            await expect(authedPage.locator('.sidebar')).toBeVisible();
        });
    }
});
