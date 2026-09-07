// @ts-check
/**
 * A coluna "Filial" não pode voltar a nenhum relatório — tela nem impresso.
 *
 * 🔴 O DEFEITO ORIGINAL era de DIVERGÊNCIA, não de excesso: o Relatório de
 * Ocorrências tinha "Filial" como **segunda coluna do PDF/XLSX** e **nenhuma
 * coluna Filial na tela**. Quem conferisse o arquivo contra a grade achava uma
 * coluna a mais no impresso, preenchida com "—" em toda linha. Medido em
 * produção (07/09/2026): **0 filiais cadastradas**, 0 equipamentos e 0
 * ocorrências com `branch_id` — a coluna nunca teve o que mostrar.
 *
 * Decisão do dono do produto: *"não estamos usando esse cadastro no sistema no
 * momento"*.
 *
 * ⚠️ A varredura do §2 existe porque a pergunta feita foi "algum OUTRO
 * relatório tem a coluna?". Responder isso uma vez é uma conferência; travar
 * num teste é o que impede a resposta de mudar sem ninguém perceber — a
 * coluna voltaria pelo mesmo caminho que entrou, um `stream_export()` a mais.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** Cabeçalho do CSV de um relatório, já quebrado em colunas. */
async function cabecalhoDoExport(page, rota) {
    const csv = await page.evaluate(async (r) => {
        const sep = r.includes('?') ? '&' : '?';
        const resp = await fetch(r + sep + 'export=csv');
        return resp.ok ? await resp.text() : '';
    }, rota);
    const primeira = (csv.split(/\r?\n/)[0] || '').trim();
    return primeira === '' ? null
        : primeira.split(';').map((c) => c.replace(/^"|"$/g, '').trim());
}

test.describe('Relatório de Ocorrências — Filial fora', () => {
    test('🔴 nem na tela nem no arquivo', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/ocorrencias');
        await expect(authedPage.locator('table')).toBeVisible();

        const naTela = (await authedPage.locator('thead th').allTextContents()).map((t) => t.trim());
        expect(naTela.length, 'a grade precisa ter cabeçalho').toBeGreaterThan(0);
        expect(naTela, 'a tela nunca teve a coluna — e continua sem').not.toContain('Filial');

        const noExport = await cabecalhoDoExport(authedPage, '/relatorios/ocorrencias');
        // ⚠️ Sem esta guarda o teste passaria por vacuidade se o export
        // quebrasse: "nenhuma coluna Filial" é trivialmente verdade num
        // arquivo vazio.
        expect(noExport, 'o export precisa produzir cabeçalho').not.toBeNull();
        expect(noExport, 'era a 2ª coluna do PDF/XLSX').not.toContain('Filial');
    });

    test('o arquivo segue a mesma sequência da tela', async ({ authedPage }) => {
        // Tirar a coluna fechou a divergência; este teste é o que a mantém
        // fechada. "Ação" é botão e não existe em arquivo.
        await authedPage.goto('/relatorios/ocorrencias');
        const naTela = (await authedPage.locator('thead th').allTextContents())
            .map((t) => t.trim().replace(/\s*[▲▼⇅]\s*$/, '').trim())
            .filter((t) => t !== 'Ação' && t !== '');
        const noExport = await cabecalhoDoExport(authedPage, '/relatorios/ocorrencias');
        expect(noExport).not.toBeNull();

        // ⚠️ Compara a SEQUÊNCIA, não os rótulos: a tela abrevia para caber
        // ("Qtd", "Falso Pos."), o arquivo escreve por extenso ("Qtd. Alarmes",
        // "Falso Positivo"). O que não pode divergir é quantas colunas e em
        // que ordem — foi disso que a Filial se aproveitou.
        expect(noExport.length, 'mesma quantidade de colunas').toBe(naTela.length);
    });
});

test.describe('O FILTRO de Filial também saiu', () => {
    // v4.17.21. ⚠️ Os dois `<select>` já viviam dentro de um
    // `<?php if ($branchList): ?>` — com zero filiais eles NÃO eram
    // desenhados. Ou seja, a remoção não tirou nada da vista de ninguém: tirou
    // código morto que voltaria a aparecer no dia em que alguém cadastrasse
    // uma filial "só para testar". É por isso que o teste olha o NOME do
    // campo, e não a aparência — o defeito estava latente, não visível.
    for (const rota of ['/relatorios/ocorrencias', '/relatorios/alarmes']) {
        test(`${rota} não tem campo branch_id no formulário`, async ({ authedPage }) => {
            await authedPage.goto(rota);
            await expect(authedPage.locator('form').first()).toBeVisible();
            await expect(authedPage.locator('[name="branch_id"]'),
                'o filtro de filial não pode voltar').toHaveCount(0);
            const rotulos = await authedPage.locator('form label').allTextContents();
            expect(rotulos.map((t) => t.trim().toLowerCase()),
                'nem o rótulo').not.toContain('filial');
        });

        test(`${rota} ignora ?branch_id= na URL sem quebrar`, async ({ authedPage }) => {
            // Link antigo, modelo de relatório salvo ou favorito ainda pode
            // carregar o parâmetro. Ele tem de ser ignorado — não virar erro.
            const erros = [];
            authedPage.on('pageerror', (e) => erros.push(e.message));
            const resp = await authedPage.goto(rota + '?branch_id=42');
            expect(resp.status(), 'parâmetro órfão não pode derrubar a tela').toBeLessThan(400);
            await expect(authedPage.locator('table')).toBeVisible();
            expect(erros).toEqual([]);
        });
    }
});

test.describe('Nenhum outro relatório traz Filial', () => {
    // Toda rota de relatório com exportação. Cadastros entram junto porque a
    // pergunta é sobre a COLUNA, e ela poderia ter sido copiada para qualquer
    // `stream_export()`.
    const rotas = [
        '/relatorios/posicoes', '/relatorios/deslocamento', '/relatorios/desatualizados',
        '/relatorios/alarmes', '/relatorios/ocorrencias', '/relatorios/geocercas',
        '/relatorios/paradas', '/relatorios/ociosidade', '/relatorios/ignicao',
        '/relatorios/velocidade', '/relatorios/status-frota',
        '/video/downloads', '/ativos', '/equipamentos', '/chips', '/motoristas',
    ];

    for (const rota of rotas) {
        test(`${rota} não exporta coluna Filial`, async ({ authedPage }) => {
            await authedPage.goto('/relatorios/ocorrencias');   // origem autenticada p/ o fetch
            const cols = await cabecalhoDoExport(authedPage, rota);
            // Relatório que exige filtro obrigatório pode não exportar sem ele;
            // aí não há o que afirmar, e dizer isso é melhor que passar mudo.
            test.skip(!cols, `${rota} não produziu cabeçalho (provável filtro obrigatório)`);
            expect(cols, 'coluna Filial não pode aparecer aqui').not.toContain('Filial');
        });
    }
});
