// @ts-check
/**
 * Spec webhook → ocorrência (Fase M.4 §4.6):
 * POST /pushalarm (alertType 143 — Distração do Motorista) e verifica que
 * o motor de ocorrências cria a ocorrência visível no polling do dashboard.
 *
 * Pré-requisitos:
 *   - migration v4.1.0 aplicada (fix do seed de occurrence_config_params)
 *   - TEST_IMEI cadastrado em devices com customer_id (ver scripts/test_e2e.sh)
 *   - WEBHOOK_TOKEN igual ao .env da aplicação
 */
const { test, expect, hasCreds, BASE_URL } = require('./fixtures/auth');

const TOKEN = process.env.WEBHOOK_TOKEN || '';
const IMEI = process.env.TEST_IMEI || '';

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');
test.skip(!TOKEN || !IMEI, 'defina WEBHOOK_TOKEN e TEST_IMEI (device cadastrado)');

test('pushalarm 143 cria ocorrência "Distração do Motorista"', async ({ authedPage, request }) => {
    // Timestamp UTC único (o gateway descarta payloads repetidos por 10 min)
    const nowUtc = new Date().toISOString().slice(0, 19).replace('T', ' ');

    const resp = await request.post(BASE_URL + '/pushalarm', {
        data: {
            token: TOKEN,
            msgType: 'pushalarm',
            data_list: [{
                imei: IMEI,
                msgClass: 0,
                msg: {
                    alertType: '143',
                    alarmTime: nowUtc,
                    lat: -23.5505,
                    lng: -46.6333,
                    gpsSpeed: 35,
                    alertValue: '1',
                },
            }],
        },
    });
    expect(resp.ok(), 'pushalarm deve responder 200').toBeTruthy();

    // Poll no endpoint AJAX do dashboard DMS até a ocorrência aparecer
    await expect.poll(async () => {
        const r = await authedPage.request.get('/ocorrenciasdata');
        if (!r.ok()) return `http ${r.status()}`;
        const text = await r.text();
        return text.includes('Distração do Motorista') ? 'encontrada' : 'aguardando';
    }, {
        message: 'ocorrência não apareceu em /ocorrenciasdata (device cadastrado? migration v4.1.0 aplicada?)',
        timeout: 30000,
        intervals: [2000, 3000, 5000],
    }).toBe('encontrada');

    // E também na tela do dashboard
    await authedPage.goto('/ocorrencias/dashboard');
    await expect(authedPage.locator('body')).toContainText('Distração do Motorista');
});
