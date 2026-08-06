// @ts-check
/**
 * Spec dos relatórios operacionais (v4.6.0 — Fase 3 do PLANO_IMPLEMENTACAO_v4.4-v4.7).
 *
 * As cinco telas leem o que o scripts/state_builder.php produziu, então a spec
 * não pode assumir que existe dado: um ambiente recém-migrado tem as tabelas
 * vazias até o primeiro cron rodar. O que se verifica aqui é o comportamento
 * que vale com ou sem dado — filtros, ordenação, KPIs, export, escopo — e as
 * invariantes que precisam valer QUANDO há linha na grade.
 *
 * As asserções de segmentação propriamente dita (soma de 86.400 s por dia,
 * contiguidade, dedupe na reexecução) são feitas contra o banco, não pela UI.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** As cinco telas da fase, com o que cada uma tem de mostrar. */
const TELAS = [
    { path: '/relatorios/paradas',      titulo: 'Relatório de Paradas',                unidade: 'paradas' },
    { path: '/relatorios/ociosidade',   titulo: 'Relatório de Ociosidade',             unidade: 'períodos ociosos' },
    { path: '/relatorios/ignicao',      titulo: 'Relatório de Ignição',                unidade: 'acionamentos' },
    { path: '/relatorios/velocidade',   titulo: 'Relatório de Excesso de Velocidade',  unidade: 'infrações' },
    { path: '/relatorios/status-frota', titulo: 'Status da Frota',                     unidade: 'equipamentos' },
];

test.describe('Relatórios operacionais — estrutura das telas', () => {
    for (const { path, titulo } of TELAS) {
        test(`${path} renderiza título, filtros e grade`, async ({ authedPage }) => {
            const resp = await authedPage.goto(path);
            expect(resp.status()).toBeLessThan(500);

            await expect(authedPage.locator('h2')).toContainText(titulo);
            // Barra de filtros com botão Gerar
            await expect(authedPage.locator('form button:has-text("Gerar")')).toBeVisible();
            // Grade sempre presente (com dado ou com a linha de vazio)
            await expect(authedPage.locator('table')).toBeVisible();
            // Botões de export
            await expect(authedPage.locator('a:has-text("Exportar Excel")')).toBeVisible();
            await expect(authedPage.locator('a:has-text("Exportar PDF")')).toBeVisible();

            const body = await authedPage.locator('body').innerText();
            expect(body).not.toMatch(/Fatal error|Parse error|Uncaught (Error|Exception)/);
        });
    }
});

test.describe('Relatórios operacionais — filtros', () => {
    test('paradas: filtro de duração mínima entra na URL e é preservado', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/paradas');
        await authedPage.selectOption('select[name="min_minutes"]', '30');
        await authedPage.click('button:has-text("Gerar")');
        await expect(authedPage).toHaveURL(/min_minutes=30/);
        // O valor escolhido tem de voltar selecionado — filtro que se perde na
        // submissão é o defeito clássico desta tela
        await expect(authedPage.locator('select[name="min_minutes"]')).toHaveValue('30');
    });

    test('velocidade: filtro de excedente mínimo é preservado', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/velocidade');
        await authedPage.selectOption('select[name="min_over"]', '20');
        await authedPage.click('button:has-text("Gerar")');
        await expect(authedPage).toHaveURL(/min_over=20/);
        await expect(authedPage.locator('select[name="min_over"]')).toHaveValue('20');
    });

    test('ignição: filtro de evento é preservado', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/ignicao');
        await authedPage.selectOption('select[name="event"]', 'desligada');
        await authedPage.click('button:has-text("Gerar")');
        await expect(authedPage).toHaveURL(/event=desligada/);
        await expect(authedPage.locator('select[name="event"]')).toHaveValue('desligada');
    });

    test('período acima de 31 dias é ajustado com aviso', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/paradas?date_from=2020-01-01&date_to=2026-12-31');
        await expect(authedPage.locator('body')).toContainText('ajustado para o máximo');
    });

    test('botão Voltar aparece só quando há filtro aplicado', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/paradas');
        await expect(authedPage.locator('a:has-text("Voltar")')).toHaveCount(0);
        await authedPage.goto('/relatorios/paradas?min_minutes=15');
        await expect(authedPage.locator('a:has-text("Voltar")')).toBeVisible();
    });
});

test.describe('Status da Frota', () => {
    test('os quatro estados somam o total da frota ativa', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/status-frota');

        // Os 5 cartões: 4 estados + total. A soma dos 4 tem de dar o 5º —
        // é o critério de aceite da tela, e o que quebra se a classificação
        // deixar algum equipamento sem estado.
        const nums = await authedPage.locator('.card .text-mono').allInnerTexts();
        const inteiros = nums
            .map((t) => t.trim())
            .filter((t) => /^\d+$/.test(t))
            .map(Number);

        expect(inteiros.length).toBeGreaterThanOrEqual(5);
        const [movimento, ocioso, parado, offline, total] = inteiros;
        expect(movimento + ocioso + parado + offline).toBe(total);
    });

    test('cartão de estado filtra a grade (drill-down)', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/status-frota');
        await authedPage.locator('a.card', { hasText: 'Sem comunicação' }).click();
        await expect(authedPage).toHaveURL(/state=offline/);
        await expect(authedPage.locator('select[name="state"]')).toHaveValue('offline');

        // Toda linha da grade filtrada tem de estar no estado escolhido
        const linhas = authedPage.locator('tbody tr');
        const n = await linhas.count();
        for (let i = 0; i < Math.min(n, 5); i++) {
            const txt = await linhas.nth(i).innerText();
            if (txt.includes('Nenhum equipamento')) continue;
            expect(txt).toContain('Sem comunicação');
        }
    });
});

test.describe('Relatórios operacionais — export', () => {
    for (const { path } of TELAS) {
        test(`${path} exporta XLSX`, async ({ authedPage }) => {
            await authedPage.goto(path);
            const download = authedPage.waitForEvent('download', { timeout: 20000 });
            await authedPage.click('a:has-text("Exportar Excel")');
            const file = await download;
            expect(file.suggestedFilename()).toMatch(/\.xlsx$/);
        });
    }
});

test.describe('Relatórios operacionais — coerência entre telas', () => {
    test('desligamentos de ignição batem com o número de paradas', async ({ authedPage }) => {
        // O relatório de Ignição publica os dois números lado a lado
        // justamente para que a divergência salte aos olhos.
        await authedPage.goto('/relatorios/ignicao');
        const body = await authedPage.locator('body').innerText();

        const desligadas = body.match(/Ignições desligadas\s*\n?\s*(\d+)/);
        const paradas = body.match(/há\s+(\d+)\s+período/);

        // Sem dado no período os dois blocos não aparecem — nada a comparar.
        test.skip(!desligadas || !paradas, 'sem dado de segmentação no período');

        // Podem diferir em no máximo 1: o veículo que entrou no período já
        // desligado tem o segmento de parada sem a transição que o abriu.
        const diff = Math.abs(Number(desligadas[1]) - Number(paradas[1]));
        expect(diff).toBeLessThanOrEqual(1);
    });

    test('a sidebar lista as cinco telas novas no grupo Relatórios', async ({ authedPage }) => {
        await authedPage.goto('/relatorios/paradas');
        for (const { path } of TELAS) {
            await expect(authedPage.locator(`.sidebar a[href="${path}"]`)).toHaveCount(1);
        }
    });
});

/**
 * A padronização por PLACA (v4.9.0), na tela e no arquivo exportado.
 *
 * Existe porque nada guardava o LAYOUT das colunas: as asserções acima olham
 * título, filtros e a presença da grade, e passariam iguais com IMEI de volta
 * na primeira coluna. As quatro telas de segmento tinham o IMEI como segunda
 * linha embaixo da placa e uma coluna Cliente repetindo o mesmo nome em toda
 * linha — as duas coisas saíram, e é isso que se afirma aqui.
 *
 * O cabeçalho do export é lido em CSV de propósito: é o único dos três
 * formatos que se inspeciona sem writer nenhum, e os três saem da MESMA lista
 * de `stream_export()`, então verificar um verifica os três.
 */
test.describe('Relatórios operacionais — padronização por placa', () => {
    /** As quatro telas de segmento/evento; Status da Frota tem grade própria. */
    const POR_PLACA = [
        { path: '/relatorios/paradas',    slug: 'paradas' },
        { path: '/relatorios/ociosidade', slug: 'ociosidade' },
        { path: '/relatorios/ignicao',    slug: 'ignicao' },
        { path: '/relatorios/velocidade', slug: 'velocidade' },
    ];

    for (const { path } of POR_PLACA) {
        test(`${path}: grade abre pela placa, sem IMEI nem Cliente`, async ({ authedPage }) => {
            await authedPage.goto(path);

            // ⚠️ `innerText` devolve o texto JÁ TRANSFORMADO pelo CSS, e o
            // `th` da folha de estilo é `text-transform: uppercase` — chega
            // aqui como "PLACA", com o "⇅" do link de ordenação colado. Sem o
            // `/i`, o `not.toMatch(/Cliente/)` passaria por VACUIDADE: nunca
            // encontraria "Cliente" nem com a coluna de volta na grade.
            const cabecalhos = await authedPage.locator('table thead th').allInnerTexts();
            expect(cabecalhos.length, 'a grade tem de ter cabeçalho').toBeGreaterThan(0);
            expect(cabecalhos[0].trim()).toMatch(/^Placa/i);
            expect(cabecalhos.join(' | ')).not.toMatch(/IMEI|Cliente/i);

            // O filtro é seleção de placa, não caixa de texto de IMEI
            await expect(authedPage.locator('form select[name="imei"]')).toHaveCount(1);
            await expect(authedPage.locator('form input[type="text"][name="imei"]')).toHaveCount(0);
        });

        test(`${path}: export abre pela placa e leva o link do mapa`, async ({ authedPage }) => {
            const resp = await authedPage.request.get(`${path}?export=csv`);
            expect(resp.ok(), `${path} export devolveu ${resp.status()}`).toBeTruthy();

            // Primeira linha = cabeçalho; o BOM UTF-8 vem antes dele
            const cabecalho = (await resp.text()).split('\n')[0].replace(/^﻿/, '');
            expect(cabecalho).toMatch(/^Placa;/);
            expect(cabecalho).not.toMatch(/IMEI|Cliente/);
            expect(cabecalho).toMatch(/Mapa\s*$/);
        });
    }

    test('/relatorios/status-frota: export troca Equipamento por Placa e ganha o mapa', async ({ authedPage }) => {
        const resp = await authedPage.request.get('/relatorios/status-frota?export=csv');
        expect(resp.ok()).toBeTruthy();

        const cabecalho = (await resp.text()).split('\n')[0].replace(/^﻿/, '');
        expect(cabecalho).toMatch(/^Placa;/);
        expect(cabecalho).not.toMatch(/IMEI|Equipamento/);
        expect(cabecalho).toMatch(/Mapa\s*$/);
    });
});

/**
 * Extrai uma entrada de um .xlsx (que é um zip) com o `zlib` do próprio Node.
 *
 * ⚠️ A primeira versão disto chamava `unzip -p` e **pulava** quando o binário
 * não estava no PATH — que é o caso do processo do Playwright no Windows, onde
 * o `unzip` do Git Bash não é visível. O teste passou "verde" sem ter olhado
 * byte nenhum: exatamente o modo de falha que este repositório já pagou caro
 * (spec que pula não é cobertura). Sem dependência externa não há como pular.
 *
 * Lê o diretório central do zip (EOCD → entradas) e infla a entrada pedida.
 *
 * @param {Buffer} buf   Conteúdo do .xlsx
 * @param {string} nome  Caminho da entrada (ex.: 'xl/worksheets/sheet1.xml')
 * @returns {string} Conteúdo da entrada, em UTF-8
 */
function entradaDoZip(buf, nome) {
    const zlib = require('zlib');

    // EOCD (fim do diretório central): assinatura 0x06054b50, no fim do
    // arquivo. Varre de trás para frente porque pode haver comentário depois.
    let eocd = -1;
    for (let i = buf.length - 22; i >= 0; i--) {
        if (buf.readUInt32LE(i) === 0x06054b50) { eocd = i; break; }
    }
    if (eocd < 0) throw new Error('XLSX inválido: EOCD não encontrado');

    const total = buf.readUInt16LE(eocd + 10);
    let p = buf.readUInt32LE(eocd + 16);

    for (let n = 0; n < total; n++) {
        if (buf.readUInt32LE(p) !== 0x02014b50) throw new Error('diretório central corrompido');
        const metodo   = buf.readUInt16LE(p + 10);
        const tamComp  = buf.readUInt32LE(p + 20);
        const lenNome  = buf.readUInt16LE(p + 28);
        const lenExtra = buf.readUInt16LE(p + 30);
        const lenCom   = buf.readUInt16LE(p + 32);
        const offLocal = buf.readUInt32LE(p + 42);
        const atual    = buf.toString('utf8', p + 46, p + 46 + lenNome);

        if (atual === nome) {
            // Cabeçalho local: os comprimentos de nome/extra podem DIFERIR dos
            // do diretório central, então são relidos aqui.
            const lnNome  = buf.readUInt16LE(offLocal + 26);
            const lnExtra = buf.readUInt16LE(offLocal + 28);
            const ini     = offLocal + 30 + lnNome + lnExtra;
            const dados   = buf.subarray(ini, ini + tamComp);
            // 8 = deflate (o que o ZipArchive do PHP usa), 0 = armazenado
            return (metodo === 8 ? zlib.inflateRawSync(dados) : dados).toString('utf8');
        }
        p += 46 + lenNome + lenExtra + lenCom;
    }
    throw new Error(`entrada ${nome} não existe no XLSX`);
}

/**
 * A célula de link do XLSX carrega o VALOR EM CACHE junto da fórmula.
 *
 * Sem o `<v>`, `=HYPERLINK(…)` só ganha conteúdo depois que o programa
 * recalcula a planilha — e em visualizador que não recalcula a coluna "Mapa"
 * aparece VAZIA, que foi o defeito relatado. O teste abre o XML da planilha
 * porque é onde a diferença existe: pelo tamanho do arquivo ou pelo status
 * HTTP os dois casos são idênticos.
 */
test('XLSX: a célula do mapa leva fórmula E valor em cache', async ({ authedPage }) => {
    const resp = await authedPage.request.get('/relatorios/status-frota?export=xlsx');
    expect(resp.ok()).toBeTruthy();

    const sheet = entradaDoZip(Buffer.from(await resp.body()), 'xl/worksheets/sheet1.xml');

    // Guarda de não-vacuidade: sem linha com coordenada não há célula de link,
    // e a asserção abaixo passaria sem ter olhado o que interessa.
    expect(sheet, 'nenhuma célula de link na planilha — o teste não teria o que verificar')
        .toContain('HYPERLINK');
    expect(sheet).toMatch(/<f>HYPERLINK\([^<]*\)<\/f><v>[^<]+<\/v>/);
});
