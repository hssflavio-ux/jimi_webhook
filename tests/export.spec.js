// @ts-check
/**
 * Spec de exportação (Fase M.4 §4.8):
 * cria job de relatório via UI → roda o worker → verifica conclusão e download.
 *
 * O worker roda via `php scripts/worker.php` (mesmo processo do cron em produção),
 * portanto o PHP CLI precisa estar no PATH e o .env apontar para o mesmo banco
 * que a aplicação sob teste (por isso este spec só roda contra localhost).
 */
const { execFileSync } = require('child_process');
const path = require('path');
const { test, expect, hasCreds, BASE_URL } = require('./fixtures/auth');

const isLocal = BASE_URL.includes('localhost') || BASE_URL.includes('127.0.0.1');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');
test.skip(!isLocal, 'spec de exportação roda apenas contra localhost (precisa executar o worker)');

for (const format of ['csv', 'xlsx', 'pdf']) {
    test(`job de exportação ${format.toUpperCase()} conclui e permite download`, async ({ authedPage }) => {
        const nome = `E2E Export ${format} ${Date.now()}`;

        // 1. Cria o job via formulário
        await authedPage.goto('/exportar');
        await authedPage.fill('input[name="report_name"]', nome);
        await authedPage.selectOption('select[name="report_type"]', 'devices');
        await authedPage.selectOption('select[name="format"]', format);
        await authedPage.click('form:has(input[name="report_name"]) button[type="submit"]');
        await expect(authedPage.locator('.alert-success')).toContainText('fila');

        // 2. Processa a fila (equivalente ao cron de 1 min)
        execFileSync('php', ['scripts/worker.php'], {
            cwd: path.resolve(__dirname, '..'),
            stdio: 'pipe',
            timeout: 60000,
        });

        // 3. Job mais recente concluído com link de download
        // (goto em vez de reload — reload após POST re-submeteria o form)
        await authedPage.goto('/exportar');
        const firstRow = authedPage.locator('#export-tbody tr').first();
        await expect(firstRow).toContainText('Concluído');
        await expect(firstRow).toContainText(format.toUpperCase());

        const href = await firstRow.locator('a:has-text("Baixar")').getAttribute('href');
        expect(href, 'link de download presente').toBeTruthy();

        // 4. Download responde 200 com o formato correto
        const dl = await authedPage.request.get('/' + String(href).replace(/^\//, ''));
        expect(dl.status(), `download de ${href}`).toBe(200);
        const body = await dl.body();
        expect(body.length).toBeGreaterThan(0);
        if (format === 'pdf') expect(body.subarray(0, 5).toString()).toContain('%PDF');
        if (format === 'xlsx') expect(body.subarray(0, 2).toString()).toBe('PK'); // zip OOXML
    });
}

/**
 * O relatório AGENDADO usa as mesmas colunas da tela (v4.9.0).
 *
 * O worker tem listas de coluna próprias, em `buildReportSource()`, e por isso
 * ficou divergindo das telas sem que nada acusasse: é o mesmo relatório, só que
 * entregue por e-mail. Este teste prende as duas pontas — o cabeçalho e a URL
 * do mapa —, porque o CSV do worker não passa por `stream_export()` e já perdeu
 * a URL uma vez (o `__toString()` do `ExportLink` devolve só o rótulo).
 */
test('relatório agendado de Alarmes sai padronizado por placa, com a URL do mapa', async ({ authedPage }) => {
    await authedPage.goto('/exportar');
    await authedPage.fill('input[name="report_name"]', `E2E Colunas ${Date.now()}`);
    await authedPage.selectOption('select[name="report_type"]', 'alarms');
    await authedPage.selectOption('select[name="format"]', 'csv');
    await authedPage.click('form:has(input[name="report_name"]) button[type="submit"]');
    await expect(authedPage.locator('.alert-success')).toContainText('fila');

    execFileSync('php', ['scripts/worker.php'], {
        cwd: path.resolve(__dirname, '..'),
        stdio: 'pipe',
        timeout: 60000,
    });

    await authedPage.goto('/exportar');
    const href = await authedPage.locator('#export-tbody tr').first()
        .locator('a:has-text("Baixar")').getAttribute('href');
    expect(href, 'link de download presente').toBeTruthy();

    const dl = await authedPage.request.get('/' + String(href).replace(/^\//, ''));
    expect(dl.status()).toBe(200);
    const linhas = (await dl.text()).replace(/^﻿/, '').trim().split('\n');

    // O cabeçalho é a invariante: vale com ou sem alarme no período.
    expect(linhas[0]).toBe('Placa;Data/Hora;"Nome do Alarme";Status;"Velocidade (km/h)";Endereço;Mapa');

    // A URL só pode ser conferida quando existe linha de dado. Sem alarme no
    // período não há o que verificar — e dizer isso aqui é melhor do que uma
    // asserção que passaria por vacuidade sobre um arquivo só com cabeçalho.
    if (linhas.length > 1) {
        expect(linhas[1], 'a coluna Mapa do CSV leva a URL, não o rótulo')
            .toContain('https://www.google.com/maps/search/?api=1&query=');
    }
});
