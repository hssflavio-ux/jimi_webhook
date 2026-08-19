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
    // 🔴 v4.9.32 — SEM isto a suíte inteira não rodava, e saía com exit 0.
    //
    // O `testMatch` padrão do Playwright é `**/*.@(spec|test).?(c|m)[jt]s?(x)`,
    // então ele coletava também `tests/helpers/player_snapshot.test.js` — que
    // NÃO é um spec: é um script Node autônomo, com uma IIFE que roda na
    // importação e termina em `process.exit()`. Playwright importa todo arquivo
    // coletado para descobrir os testes dele; a importação executava o script,
    // o `process.exit(0)` derrubava o processo do Playwright, e a saída era
    // "Running 137 tests using 1 worker" seguida de NADA — com código de saída
    // 0, que é o que um runner de CI lê como "suíte verde".
    //
    // `npx playwright test tests/algum.spec.js` (com caminho) sempre funcionou,
    // que é por isso que o defeito sobreviveu: quem depurava um spec específico
    // via a suíte rodar normalmente.
    //
    // Os helpers `.test.js`/`.test.php` continuam rodando pelo
    // `scripts/run-tests.ps1`, que os chama um a um antes da suíte.
    testMatch: '**/*.spec.js',
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
