// @ts-check
/**
 * Spec de regressão: tela de vídeo não oferece equipamento DESATIVADO.
 *
 * O defeito (até a v4.9.28): `video_aovivo.php` e `video_playback.php`
 * montavam a lista com `WHERE 1=1` — sem filtro nenhum de `is_active`. O vídeo
 * ao vivo ainda ordenava por `d.is_active DESC`, ou seja, o campo era conhecido
 * e mesmo assim não filtrava: o equipamento dado baixa no cadastro aparecia no
 * seletor, só que por último.
 *
 * Baixa de equipamento é soft delete (`ativos.php` põe `is_active=0`), então a
 * linha continua no banco para sempre — e sem o filtro ela reaparece em toda
 * tela operacional. Toda as demais do sistema já filtravam (`comandos.php`,
 * `rastreamento.php`, `camerasdata.php`, `hbdata.php`); só o CADASTRO mostra
 * inativo, e lá com selo.
 *
 * ⚠️ NÃO-VACUIDADE: sem um equipamento inativo no banco, "não lista inativo"
 * passa sozinho. O teste exige que o par exista antes de afirmar qualquer coisa.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

const IMEI_INATIVO = '869900000000777';
const IMEI_ATIVO   = '869900000000888';

// Cada tela nomeia seu <select> de um jeito.
const SELETOR = {
    '/video/aovivo':   '#dev-sel',
    '/video/playback': '#pb-imei',
};

/** IMEIs oferecidos no seletor de equipamento da tela. */
async function imeisDoSeletor(page, rota) {
    await page.goto(rota);
    const sel = page.locator(SELETOR[rota]);
    await expect(sel, `${rota} deve ter o seletor de equipamento`).toBeVisible();
    return sel.locator('option').evaluateAll((opts) =>
        opts.map((o) => /** @type {HTMLOptionElement} */ (o).value).filter(Boolean));
}

for (const rota of Object.keys(SELETOR)) {
    test(`${rota} não oferece equipamento desativado`, async ({ authedPage }) => {
        const imeis = await imeisDoSeletor(authedPage, rota);

        // Guarda de não-vacuidade: o fixture precisa estar no ar, senão a
        // asserção seguinte não prova nada.
        expect(imeis, `${rota}: o equipamento ATIVO do fixture tem de aparecer — ` +
            'sem ele o teste passaria por lista vazia').toContain(IMEI_ATIVO);

        expect(imeis, `${rota}: equipamento com is_active=0 não pode ser oferecido`)
            .not.toContain(IMEI_INATIVO);
    });
}
