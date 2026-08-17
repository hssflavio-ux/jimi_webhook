// @ts-check
/**
 * Spec de regressão do vínculo cliente↔equipamento no cadastro.
 *
 * O defeito (v4.9.25 e anteriores): a tela de Equipamentos não tinha seletor de
 * cliente — mostrava o cliente da SESSÃO num campo readonly — e gravava
 * `customer_id = $sessaoOuUm`. Resultado, sempre com HTTP 200 e "cadastrado com
 * sucesso": o equipamento ia para o cliente errado, ou (sessão sem cliente)
 * nascia com `customer_id` NULL, órfão e invisível em toda tela com escopo.
 *
 * O teste que importa é o do MEIO: cadastrar escolhendo um cliente DIFERENTE do
 * da sessão e conferir onde a linha caiu. Com o campo readonly antigo esse teste
 * não teria nem como ser escrito — por isso o primeiro caso trava a estrutura
 * (tem de ser `<select name="customer_id">`), que é a causa-raiz.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

const IMEI = '86' + Date.now(); // 15 dígitos, único por execução

test.describe.serial('Vínculo cliente↔equipamento', () => {
    test('o formulário oferece seletor de cliente (e mais de um cliente para escolher)', async ({ authedPage }) => {
        await authedPage.goto('/equipamentos?action=novo');
        const select = authedPage.locator('select[name="customer_id"]');
        await expect(select, 'o cliente tem de ser escolhível, não um campo fixo').toBeVisible();
        await expect(select).toHaveAttribute('required', '');

        // Guarda de não-vacuidade: com um único cliente na base, "gravou no
        // cliente escolhido" passaria por não haver outro lugar onde cair.
        const valores = await select.locator('option').evaluateAll((opts) =>
            opts.map((o) => /** @type {HTMLOptionElement} */ (o).value).filter(Boolean));
        if (valores.length < 2) {
            throw new Error(
                `este spec precisa de 2+ clientes ativos para provar algo; achei ${valores.length}. ` +
                'Rode tests/helpers/seed_tenants.php antes.');
        }
    });

    test('cadastro grava no cliente ESCOLHIDO, não no da sessão', async ({ authedPage }) => {
        await authedPage.goto('/equipamentos?action=novo');
        const select = authedPage.locator('select[name="customer_id"]');

        // Cliente da sessão = o pré-selecionado. Escolhemos deliberadamente outro.
        const daSessao = await select.inputValue();
        const outro = await select.locator('option').evaluateAll((opts, atual) => {
            const o = opts.map((x) => /** @type {HTMLOptionElement} */ (x))
                .find((x) => x.value && x.value !== atual);
            return o ? { value: o.value, label: o.textContent?.trim() || '' } : null;
        }, daSessao);
        expect(outro, 'precisa existir um cliente diferente do da sessão').not.toBeNull();
        const alvo = /** @type {{value: string, label: string}} */ (outro);

        await select.selectOption(alvo.value);
        await authedPage.fill('input[name="imei"]', IMEI);
        await authedPage.fill('input[name="device_name"]', `Camera E2E ${IMEI}`);
        await authedPage.click('button[type="submit"]');

        // Aparece na grade filtrada pelo cliente ESCOLHIDO...
        await authedPage.goto(`/equipamentos?customer_id=${alvo.value}&search=${IMEI}`);
        await expect(authedPage.locator('table'),
            `equipamento deveria estar no cliente escolhido (${alvo.label})`).toContainText(IMEI);

        // ...e NÃO na do cliente da sessão, que era onde o defeito o colocava.
        await authedPage.goto(`/equipamentos?customer_id=${daSessao}&search=${IMEI}`);
        await expect(authedPage.locator('table'),
            'equipamento caiu no cliente da sessão — o vínculo voltou a ignorar a escolha').not.toContainText(IMEI);
    });

    test('a grade mostra o cliente do equipamento, nunca vazio', async ({ authedPage }) => {
        await authedPage.goto(`/equipamentos?search=${IMEI}`);
        const linha = authedPage.locator('tr', { hasText: IMEI });
        await expect(linha).toBeVisible();
        // Coluna "Cliente" é a 4ª. "—" é o sintoma do órfão.
        await expect(linha.locator('td').nth(3),
            'coluna Cliente vazia = equipamento órfão').not.toHaveText('—');
    });

    test('o servidor recusa cadastro sem cliente (não confia só no required do HTML)', async ({ authedPage }) => {
        await authedPage.goto('/equipamentos?action=novo');
        // Tira o `required` para provar a guarda do SERVIDOR: é ela que impede o
        // órfão quando o POST não vem do formulário da tela.
        await authedPage.locator('select[name="customer_id"]').evaluate((el) => {
            el.removeAttribute('required');
            /** @type {HTMLSelectElement} */ (el).value = '';
        });
        await authedPage.fill('input[name="imei"]', '86' + (Date.now() + 1));
        await authedPage.fill('input[name="device_name"]', 'Camera Sem Cliente E2E');
        await authedPage.click('button[type="submit"]');
        await expect(authedPage.locator('body')).toContainText(/Selecione o cliente/i);
    });
});
