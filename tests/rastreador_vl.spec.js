// @ts-check
/**
 * Spec dos rastreadores JM-VL01 / JM-VL02 (v4.16.0).
 *
 * São os dois primeiros equipamentos do sistema SEM CÂMERA (`camera_count = 0`),
 * e a chegada deles quebrou premissas que ninguém tinha escrito. O que estes
 * testes protegem, na ordem em que importa:
 *
 *   1. 🔴 `universal`, no catálogo de comandos, NÃO libera mais a frota inteira.
 *      Ele foi derivado de "presente em >= 5 das 6 páginas de CÂMERA da wiki",
 *      e enquanto toda a frota era câmera "não trava por modelo" e "vale para
 *      todo mundo" eram a mesma frase. Com um rastreador na lista, soltar a
 *      trava passou a oferecer `RECORDSW`, `VOLUME`, `SSID` e `WIFIAP` a um
 *      aparelho que não os entende. A trava agora é por FAMÍLIA.
 *      ⚠️ O JM-VL01 TEM WiFi (é hotspot, e o Android dele entra numa rede) — o
 *      que ele não entende é `WIFIAP`/`SSID`: a forma dele é `HOTSPOT`.
 *   2. O cadastro aceita `0` canais. O campo tinha `min="1"`, e o navegador
 *      recusava o formulário do único valor certo para um rastreador.
 *   3. 🔴 Desde a v4.17.28 a tela une as variantes de aridade por NOME de
 *      comando (`command_catalog_merge_by_name()`) e não trava mais a
 *      seleção por modelo — decisão do dono do produto, igual ao
 *      `/comandos-sms`. Mandar o `SPEED` de quatro campos da JC para um VL01
 *      (onde o 2º campo é o TEMPO, não a forma de aviso) continua aceito e
 *      mal interpretado, sem erro nenhum: a proteção que existia deixou de
 *      ser da UI e passou a ser o exemplo por família mostrado na tela
 *      (`command_catalog_examples_by_family()`) — o que este spec passa a
 *      proteger é a UNIÃO de modelos/famílias, não mais a aridade exposta ao JS.
 *
 * ⚠️ Estes testes NÃO precisam de um equipamento JM-VL cadastrado — de
 * propósito. Spec que depende de fixture opcional vira spec que PULA, e spec
 * que pula não é cobertura (é a terceira vez que este repo paga por isso). O
 * que eles exigem é a migração v4.16.0 aplicada: sem ela FALHAM, que é
 * exatamente o aviso que se quer.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

/** Comandos que só existem em câmera — nenhum deles pode alcançar rastreador. */
const SO_CAMERA = ['RECORDSW', 'VOLUME', 'SSID', 'WIFIAP', 'CHECKVIDEO', 'STATUSVIDEO'];

test.describe('Rastreadores JM-VL — cadastro e trava por família', () => {

    test('os dois modelos estão no catálogo de modelos, rotulados como rastreador', async ({ authedPage }) => {
        await authedPage.goto('/equipamentos?action=novo');

        const opcoes = await authedPage.$$eval(
            'select[name="device_model_id"] option',
            (els) => els.map((e) => ({
                texto: (e.textContent || '').replace(/\s+/g, ' ').trim(),
                cam: e.getAttribute('data-cam'),
            }))
        );

        for (const nome of ['JM-VL01', 'JM-VL02']) {
            const op = opcoes.find((o) => o.texto.startsWith(nome));
            expect(op, `${nome} precisa estar no <select> de modelo (migração v4.16.0 aplicada?)`).toBeTruthy();
            expect(op.cam, `${nome} tem 0 canais`).toBe('0');
            expect(op.texto).toContain('rastreador');
        }

        // Guarda de não-vacuidade: se o <select> viesse vazio, o laço acima
        // não teria rodado e o teste passaria sem provar nada.
        expect(opcoes.length, 'o <select> de modelo não pode vir vazio').toBeGreaterThan(2);
    });

    test('o campo de canais aceita 0 — era min="1", que recusava o rastreador', async ({ authedPage }) => {
        await authedPage.goto('/equipamentos?action=novo');
        const min = await authedPage.getAttribute('#camera_count', 'min');
        expect(min).toBe('0');
    });

    test('escolher um modelo de 0 câmeras zera o campo (o `|| 1` transformava 0 em 1)', async ({ authedPage }) => {
        await authedPage.goto('/equipamentos?action=novo');

        const valor = await authedPage.$eval(
            'select[name="device_model_id"]',
            (sel) => {
                const op = Array.from(sel.options).find((o) => (o.textContent || '').includes('JM-VL01'));
                if (!op) return null;
                sel.value = op.value;
                sel.dispatchEvent(new Event('change'));
                // `onchange` inline: dispara na mão para não depender do evento.
                if (typeof window.onModelChange === 'function') window.onModelChange(sel);
                return document.getElementById('camera_count').value;
            }
        );

        expect(valor, 'JM-VL01 precisa estar no <select>').not.toBeNull();
        expect(valor).toBe('0');
    });

    test('🔴 comando exclusivo de câmera nunca declara a família tracker', async ({ authedPage }) => {
        await authedPage.goto('/comandos');

        const { total, vazam } = await authedPage.evaluate((soCamera) => {
            const cat = window.CATALOGO || [];
            return {
                total: cat.length,
                vazam: cat
                    .filter((c) => soCamera.includes(c.c) && (c.f || []).includes('tracker'))
                    .map((c) => c.c),
            };
        }, SO_CAMERA);

        expect(total, 'o catálogo não pode chegar vazio ao JS').toBeGreaterThan(100);
        expect(vazam, 'comando de vídeo/WiFi alcançando rastreador').toEqual([]);
    });

    test('a família chega ao JS: há comando de rastreador e comando só de câmera', async ({ authedPage }) => {
        await authedPage.goto('/comandos');

        const { comTracker, soCamera } = await authedPage.evaluate(() => {
            const cat = window.CATALOGO || [];
            return {
                comTracker: cat.filter((c) => (c.f || []).includes('tracker')).length,
                soCamera: cat.filter((c) => (c.f || []).length === 1 && c.f[0] === 'camera').length,
            };
        });

        // Os dois lados precisam existir: só um deles significaria que a
        // derivação da família não está funcionando (tudo caiu no default).
        expect(comTracker, 'nenhum comando declara família tracker').toBeGreaterThan(0);
        expect(soCamera, 'nenhum comando ficou exclusivo de câmera').toBeGreaterThan(0);
    });

    // 🔴 v4.17.28 — a tela deixou de mostrar uma linha por VARIANTE de aridade
    // (`command_catalog_merge_by_name()`, decisão do dono do produto): SPEED
    // (4 campos na JC, 4 na VL01 em ORDEM diferente, 5 na VL02) vira UMA linha,
    // com `modelos` = união das três e parametrização livre. A distinção fina
    // por aridade continua existindo só no catálogo PHP (`includes/command_catalog.php`)
    // e na consulta que monta a lista de EXEMPLOS por família — não mais como
    // campo estruturado nem como trava. Este teste passou a proteger a UNIÃO
    // de modelos/famílias, não mais a aridade exata exposta ao JS.
    test('SPEED, DEFENSE, HOTSPOT e STATUS ficam unidos por nome, com a família certa', async ({ authedPage }) => {
        await authedPage.goto('/comandos');

        const info = await authedPage.evaluate(() => {
            const cat = window.CATALOGO || [];
            const acha = (c) => cat.find((x) => x.c === c) || null;
            return {
                speed: acha('SPEED'),
                defense: acha('DEFENSE'),
                hotspot: acha('HOTSPOT'),
                status: acha('STATUS'),
            };
        });

        expect(info.speed, 'SPEED precisa existir na lista unificada').toBeTruthy();
        expect(info.speed.m, 'SPEED precisa unir os modelos JC com as duas VL')
            .toEqual(expect.arrayContaining(['JM-VL01', 'JM-VL02']));
        expect(info.speed.f, 'SPEED vale para as duas famílias').toEqual(expect.arrayContaining(['camera', 'tracker']));
        // Com mais de 1 família, o exemplo mostrado não pode ficar mudo sobre
        // qual é qual — ver command_catalog_examples_by_family().
        if (info.speed.e.length > 1) {
            expect(info.speed.e.every((e) => e.l), 'exemplo sem rótulo de família com mais de um exemplo').toBe(true);
        }

        expect(info.defense, 'DEFENSE precisa existir').toBeTruthy();
        expect(info.defense.f, 'DEFENSE vale para as duas famílias, com significado diferente em cada uma')
            .toEqual(expect.arrayContaining(['camera', 'tracker']));

        expect(info.hotspot, 'HOTSPOT só existe na linha VL01').toBeTruthy();
        expect(info.hotspot.m).toEqual(['JM-VL01']);
        expect(info.hotspot.f).toEqual(['tracker']);

        expect(info.status.m, 'STATUS precisa alcançar os dois rastreadores')
            .toEqual(expect.arrayContaining(['JM-VL01', 'JM-VL02']));
        expect(info.status.f, 'STATUS vale para as duas famílias')
            .toEqual(expect.arrayContaining(['camera', 'tracker']));
    });

    test('🔴 comando destrutivo da VL não ganha botão de consulta', async ({ authedPage }) => {
        await authedPage.goto('/comandos');

        // 🔴 'RESET' virou 'REBOOT' na lista (09/09/2026 — REBOOT#/RESET#/RESTART#
        // consolidados numa entrada só, ver includes/command_catalog.php). Checar
        // pelo nome antigo aqui seria vacuidade: o filtro não acharia nada e o
        // teste passaria sem provar que REBOOT continua sem botão de consulta.
        const comConsulta = await authedPage.evaluate(() =>
            (window.CATALOGO || [])
                .filter((c) => ['OUT2', 'FACTORY', 'RELAY', 'REBOOT'].includes(c.c) && c.q)
                .map((c) => c.c));

        // A wiki da Jimi documenta `OUT2#`, `RELAY#` e `FACTORY` como consulta.
        // Aqui vale a régua do repo: acionar saída no veículo e apagar a
        // configuração do equipamento são AÇÃO, não pergunta.
        expect(comConsulta).toEqual([]);
    });
});
