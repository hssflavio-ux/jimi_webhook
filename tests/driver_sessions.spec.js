// @ts-check
/**
 * Spec de sessão de motorista (v4.19.0):
 * POST /pushalarm com alertType 6 (Face Recognition Success, JT/T) e verifica
 * que o motorista reconhecido aparece como "corrente" em /rastreamento?ajax=1
 * — persiste até (a) um motorista DIFERENTE ser reconhecido ou (b) a ignição
 * desligar (acc=0 via /pushgps).
 *
 * Pré-requisitos (ver scripts/test_e2e.sh, seção "0c"):
 *   - TEST_IMEI cadastrado em devices, com uma câmera instalada num veículo
 *     (device_installations aberta) — sem isso resolve_installation_for_imei()
 *     devolve vehicle_id nulo e toda a lógica de driver_sessions é NO-OP.
 *   - Dois motoristas cadastrados com identifier TEST_DRIVER_IDENTIFIER e
 *     TEST_DRIVER_IDENTIFIER + '_2'.
 *   - migration v4.19.0 aplicada (tabela driver_sessions existe).
 */
const { test, expect, hasCreds, BASE_URL } = require('./fixtures/auth');

const TOKEN = process.env.WEBHOOK_TOKEN || '';
const IMEI = process.env.TEST_IMEI || '';
const DRIVER_1 = process.env.TEST_DRIVER_IDENTIFIER || 'E2E_DRIVER_1';
const DRIVER_2 = DRIVER_1 + '_2';

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');
test.skip(!TOKEN || !IMEI, 'defina WEBHOOK_TOKEN e TEST_IMEI (device com veículo instalado)');

function nowUtc() {
    // Timestamp único por chamada (o gateway descarta payloads repetidos por 10 min).
    return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

async function postAfis(request, driverId, driverName) {
    return request.post(BASE_URL + '/pushalarm', {
        data: {
            token: TOKEN,
            msgType: 'pushalarm',
            data_list: [{
                imei: IMEI,
                msgClass: 1,
                msg: {
                    alertType: '6',
                    alarmTime: nowUtc(),
                    lat: -23.5505,
                    lng: -46.6333,
                    driverId: driverId,
                    driverName: driverName,
                },
            }],
        },
    });
}

async function postAcc(request, acc) {
    return request.post(BASE_URL + '/pushgps', {
        data: {
            token: TOKEN,
            msgType: 'pushgps',
            data_list: [{
                deviceImei: IMEI, msgClass: 1,
                lat: -23.5505, lng: -46.6333, speed: acc ? 20 : 0,
                gpsTime: nowUtc(), acc: acc,
            }],
        },
    });
}

// Motorista corrente do IMEI, lido do MESMO endpoint que /rastreamento usa
// pro refresh de 30s — é a leitura AO VIVO da sessão (driver_sessions aberta).
async function currentDriverName(authedPage) {
    const r = await authedPage.request.get('/rastreamento?ajax=1');
    if (!r.ok()) return null;
    const body = await r.json();
    const pos = (body.positions || []).find((p) => p.imei === IMEI);
    return pos ? (pos.driver || null) : undefined; // undefined = IMEI nem apareceu no escopo
}

test('AFIS reconhece motorista — vira o motorista corrente do veículo', async ({ authedPage, request }) => {
    const resp = await postAfis(request, DRIVER_1, 'Motorista E2E 1');
    expect(resp.ok(), 'pushalarm deve responder 200').toBeTruthy();

    await expect.poll(async () => currentDriverName(authedPage), {
        message: 'motorista não apareceu como corrente em /rastreamento (veículo instalado? migration v4.19.0 aplicada?)',
        timeout: 30000,
        intervals: [2000, 3000, 5000],
    }).toBe('Motorista E2E 1');
});

test('AFIS de motorista diferente TROCA o motorista corrente', async ({ authedPage, request }) => {
    // Garante um ponto de partida conhecido (motorista 1).
    await postAfis(request, DRIVER_1, 'Motorista E2E 1');
    await expect.poll(async () => currentDriverName(authedPage), { timeout: 30000, intervals: [2000, 3000] })
        .toBe('Motorista E2E 1');

    const resp = await postAfis(request, DRIVER_2, 'Motorista E2E 2');
    expect(resp.ok()).toBeTruthy();

    await expect.poll(async () => currentDriverName(authedPage), {
        message: 'motorista não trocou — driver_session_recognize() não fechou a sessão anterior?',
        timeout: 30000,
        intervals: [2000, 3000, 5000],
    }).toBe('Motorista E2E 2');
});

test('ACC OFF encerra a sessão — motorista corrente some', async ({ authedPage, request }) => {
    // Reconhece um motorista e confirma que apareceu, antes de desligar a ignição.
    await postAfis(request, DRIVER_1, 'Motorista E2E 1');
    await expect.poll(async () => currentDriverName(authedPage), { timeout: 30000, intervals: [2000, 3000] })
        .toBe('Motorista E2E 1');

    // ACC=1 primeiro: a guarda de frescor de driver_session_handle_acc_reading()
    // só fecha a sessão numa transição 1→0 — sem uma leitura "ligada" mais
    // recente que o alarme, o acc=0 seguinte não teria o que encerrar.
    const on = await postAcc(request, 1);
    expect(on.ok()).toBeTruthy();

    const off = await postAcc(request, 0);
    expect(off.ok()).toBeTruthy();

    await expect.poll(async () => currentDriverName(authedPage), {
        message: 'motorista continuou "corrente" depois do ACC OFF — driver_session_handle_acc_reading() não fechou a sessão?',
        timeout: 30000,
        intervals: [2000, 3000, 5000],
    }).toBe(null);
});
