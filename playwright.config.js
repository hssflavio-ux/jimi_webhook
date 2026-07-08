// @ts-check
/**
 * Playwright — configuração da suite E2E (Fase M.4).
 *
 * Variáveis de ambiente:
 *   BASE_URL        — alvo dos testes (default http://localhost:8000)
 *   TEST_EMAIL      — usuário para specs autenticados
 *   TEST_PASSWORD   — senha do usuário
 *   TEST_EMAIL_B / TEST_PASSWORD_B — segundo cliente (isolamento multi-tenant)
 *   TEST_IMEI       — device cadastrado (spec webhook → ocorrência)
 *   WEBHOOK_TOKEN   — token dos endpoints push* (default lido do .env pela app)
 *   RATE_LIMIT_TEST — 1 habilita o teste de rate limiting (bloqueia o IP por 15 min!)
 *
 * Specs sem credenciais definidas são pulados (skip), não falham.
 */
const { defineConfig } = require('@playwright/test');

const BASE_URL = process.env.BASE_URL || 'http://localhost:8000';
const isLocal = BASE_URL.includes('localhost') || BASE_URL.includes('127.0.0.1');

module.exports = defineConfig({
    testDir: './tests',
    // Servidor embutido do PHP é single-thread: 1 worker evita interleaving
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 45000,
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL: BASE_URL,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        locale: 'pt-BR',
        timezoneId: 'America/Sao_Paulo',
    },
    // Sobe o servidor dev automaticamente quando o alvo é localhost
    webServer: isLocal ? {
        command: 'php -S localhost:8000 server.php',
        url: BASE_URL + '/ping',
        reuseExistingServer: true,
        timeout: 15000,
    } : undefined,
});
