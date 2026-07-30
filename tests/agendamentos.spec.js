// @ts-check
/**
 * Spec de Agendamentos e Modelos de relatório
 * (v4.7.0 — Fase 4 do PLANO_IMPLEMENTACAO_v4.4-v4.7).
 *
 * Cobre o ciclo pela interface: criar o agendamento → conferir a recorrência
 * traduzida → editar → desativar/reativar → excluir; e, nos relatórios, salvar
 * os filtros como modelo → aplicá-lo → conferir que os campos voltam
 * preenchidos → excluir.
 *
 * O ENVIO em si não é testado aqui — depende de um servidor SMTP e é coberto
 * pela suíte PHP, que sobe um SMTP de captura e inspeciona o .eml gerado.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

test.describe('Agendamentos — tela', () => {
    test('renderiza grade e histórico', async ({ authedPage }) => {
        const resp = await authedPage.goto('/agendamentos');
        expect(resp.status()).toBeLessThan(500);
        await expect(authedPage.locator('h2')).toContainText('Agendamentos');
        await expect(authedPage.getByText('Histórico de execuções')).toBeVisible();

        const body = await authedPage.locator('body').innerText();
        expect(body).not.toMatch(/Fatal error|Parse error|Uncaught (Error|Exception)/);
    });

    test('formulário mostra os campos certos por frequência', async ({ authedPage }) => {
        await authedPage.goto('/agendamentos?action=novo');

        // Diária: nem dia da semana nem dia do mês
        await authedPage.selectOption('select[name="frequency"]', 'diaria');
        await expect(authedPage.locator('#dowGroup')).toBeHidden();
        await expect(authedPage.locator('#domGroup')).toBeHidden();

        // Semanal: só dia da semana
        await authedPage.selectOption('select[name="frequency"]', 'semanal');
        await expect(authedPage.locator('#dowGroup')).toBeVisible();
        await expect(authedPage.locator('#domGroup')).toBeHidden();

        // Mensal: só dia do mês
        await authedPage.selectOption('select[name="frequency"]', 'mensal');
        await expect(authedPage.locator('#domGroup')).toBeVisible();
        await expect(authedPage.locator('#dowGroup')).toBeHidden();
    });

    test('dia do mês vai só até 28', async ({ authedPage }) => {
        await authedPage.goto('/agendamentos?action=novo');
        const opts = await authedPage.locator('select[name="send_dom"] option').allInnerTexts();
        // 29/30/31 não existem em todo mês — deixar escolher seria prometer
        // um envio que pularia fevereiro
        expect(opts).toHaveLength(28);
        expect(opts[opts.length - 1].trim()).toBe('28');
    });
});

test.describe.serial('Agendamentos — ciclo completo', () => {
    const nome = `ZZ E2E ${Date.now()}`;
    const nomeEditado = `${nome} (editado)`;

    test('criar agendamento semanal', async ({ authedPage }) => {
        await authedPage.goto('/agendamentos?action=novo');
        await authedPage.fill('input[name="name"]', nome);
        await authedPage.selectOption('select[name="report_type"]', 'speeding');
        await authedPage.selectOption('select[name="frequency"]', 'semanal');
        await authedPage.selectOption('select[name="send_hour"]', '7');
        await authedPage.selectOption('select[name="send_dow"]', '1');
        await authedPage.fill('input[name="recipients"]', 'gestor@teste.local');
        await authedPage.click('button[type="submit"]:has-text("Salvar Agendamento")');

        // Post/Redirect/Get: cai na grade, não no formulário
        await expect(authedPage).toHaveURL(/\/agendamentos\?msg=criado/);
        // A página tem 2 tabelas (agendamentos e histórico) — a grade é a 1ª
        await expect(authedPage.locator('table').first()).toContainText(nome);
    });

    test('grade traduz a recorrência e mostra o próximo envio em BRT', async ({ authedPage }) => {
        await authedPage.goto('/agendamentos');
        const row = authedPage.locator('tr', { hasText: nome });
        await expect(row).toBeVisible();
        await expect(row).toContainText('Toda segunda-feira às 07:00');
        await expect(row).toContainText('Ativo');
        // Próximo envio preenchido (dd/mm/aaaa hh:mm)
        await expect(row).toContainText(/\d{2}\/\d{2}\/\d{4} \d{2}:\d{2}/);
    });

    test('destinatário inválido é recusado', async ({ authedPage }) => {
        await authedPage.goto('/agendamentos?action=novo');
        await authedPage.fill('input[name="name"]', `${nome} (ruim)`);
        // O input é type=text de propósito (aceita lista); a validação é do servidor
        await authedPage.fill('input[name="recipients"]', 'nao-e-um-email');
        await authedPage.click('button[type="submit"]:has-text("Salvar Agendamento")');
        await expect(authedPage.locator('body')).toContainText('e-mail de destino válido');
    });

    test('editar agendamento', async ({ authedPage }) => {
        await authedPage.goto('/agendamentos');
        await authedPage.locator('tr', { hasText: nome }).locator('a:has-text("Editar")').click();
        await expect(authedPage).toHaveURL(/action=editar&id=\d+/);
        await authedPage.fill('input[name="name"]', nomeEditado);
        await authedPage.click('button[type="submit"]:has-text("Salvar Agendamento")');
        await expect(authedPage.locator('table').first()).toContainText(nomeEditado);
    });

    test('desativar e reativar', async ({ authedPage }) => {
        await authedPage.goto('/agendamentos');
        await authedPage.locator('tr', { hasText: nomeEditado })
            .locator('button:has-text("Desativar")').click();
        await expect(authedPage).toHaveURL(/msg=desativado/);
        await expect(authedPage.locator('tr', { hasText: nomeEditado })).toContainText('Inativo');

        await authedPage.locator('tr', { hasText: nomeEditado })
            .locator('button:has-text("Ativar")').click();
        await expect(authedPage).toHaveURL(/msg=ativado/);
        await expect(authedPage.locator('tr', { hasText: nomeEditado })).toContainText('Ativo');
    });

    test('excluir agendamento', async ({ authedPage }) => {
        await authedPage.goto('/agendamentos');
        authedPage.once('dialog', (d) => d.accept());
        await authedPage.locator('tr', { hasText: nomeEditado })
            .locator('button:has-text("Excluir")').click();
        await expect(authedPage).toHaveURL(/msg=excluido/);
        await expect(authedPage.locator('table').first()).not.toContainText(nomeEditado);
    });
});

test.describe.serial('Modelos de relatório', () => {
    const modelo = `ZZ Modelo ${Date.now()}`;

    test('barra de modelos só aparece quando há filtro ou modelo', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        // Numa tela recém-aberta não há o que guardar nem o que aplicar
        const semFiltro = await authedPage.locator('text=Salvar filtros atuais como').count();

        await authedPage.goto('/relatorios/alarmes?imei=12345');
        await expect(authedPage.locator('input[name="tpl_name"]')).toBeVisible();

        // Só é significativo se antes realmente não aparecia
        expect(semFiltro).toBe(0);
    });

    test('salvar os filtros atuais como modelo', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes?imei=12345&alarm_status=active');
        await authedPage.fill('input[name="tpl_name"]', modelo);
        await authedPage.click('button:has-text("Salvar modelo")');
        await expect(authedPage.locator('body')).toContainText('Modelo salvo');
    });

    test('modelo reaparece no seletor e repopula os filtros', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        const sel = authedPage.locator('select[name="tpl"]');
        await expect(sel).toBeVisible();
        await expect(sel).toContainText(modelo);

        // Aplicar: o select submete sozinho, o servidor redireciona (302) e a
        // rota final traz os filtros. São DUAS navegações — esperar pela URL
        // final em vez de conferir logo após o selectOption, que corre com a
        // primeira delas.
        await Promise.all([
            authedPage.waitForURL(/imei=12345/, { timeout: 15000 }),
            sel.selectOption({ label: modelo }),
        ]);
        await expect(authedPage).toHaveURL(/alarm_status=active/);

        // E os campos do formulário voltam preenchidos — é isso que o
        // usuário quer dizer com "reaplicar o modelo"
        await expect(authedPage.locator('input[name="imei"]')).toHaveValue('12345');
        await expect(authedPage.locator('select[name="alarm_status"]')).toHaveValue('active');
    });

    test('modelo não vaza para outra tela de relatório', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/velocidade?imei=1');
        const body = await authedPage.locator('body').innerText();
        expect(body).not.toContain(modelo);
    });

    test('excluir modelo', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/alarmes');
        authedPage.once('dialog', (d) => d.accept());
        await authedPage.selectOption('select[name="tpl_id"]', { label: modelo });
        await authedPage.click('button:has-text("Excluir")');
        await expect(authedPage.locator('body')).toContainText('Modelo excluído');
    });
});
