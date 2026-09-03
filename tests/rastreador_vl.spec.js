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
 *      aparelho que não tem vídeo nem WiFi. A trava agora é por FAMÍLIA.
 *   2. O cadastro aceita `0` canais. O campo tinha `min="1"`, e o navegador
 *      recusava o formulário do único valor certo para um rastreador.
 *   3. As variantes de aridade da linha VL existem e ficam presas aos modelos
 *      dela — mandar o `SPEED` de quatro campos da JC para um VL01 (onde o 2º
 *      campo é o TEMPO, não a forma de aviso) é aceito e mal interpretado, sem
 *      erro nenhum.
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
                    .map((c) => c.s),
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

    test('as variantes de aridade da VL existem e ficam presas aos modelos dela', async ({ authedPage }) => {
        await authedPage.goto('/comandos');

        const info = await authedPage.evaluate(() => {
            const cat = window.CATALOGO || [];
            const acha = (s) => cat.find((c) => c.s === s) || null;
            return {
                // SPEED tem TRÊS formatos reais: 4 campos na JC, 4 na VL01 (com
                // a ordem trocada) e 5 na VL02 (buzzer).
                speedVl01: acha('SPEED,P1,P2,P3,P4#'),
                speedVl02: acha('SPEED,P1,P2,P3,P4,P5#'),
                // DEFENSE divide nome E aridade com a JC significando outra coisa.
                defenseVl: acha('DEFENSE,P1#'),
                // Comando que só a linha VL tem.
                hotspot: acha('HOTSPOT,P1,P2,P3#'),
                // Universal que a VL documenta: precisa listar os dois modelos.
                status: cat.find((c) => c.s === 'STATUS#'),
            };
        });

        expect(info.speedVl01, 'SPEED de 4 campos da VL01').toBeTruthy();
        expect(info.speedVl01.m).toEqual(['JM-VL01']);
        expect(info.speedVl01.u, 'variante de aridade nunca é universal').toBe(false);
        expect(info.speedVl01.p).toHaveLength(4);
        // O 2º campo da VL01 é o TEMPO — na linha JC é a forma de aviso.
        expect(info.speedVl01.p[1].d).toMatch(/tempo/i);

        expect(info.speedVl02, 'SPEED de 5 campos da VL02').toBeTruthy();
        expect(info.speedVl02.m).toEqual(['JM-VL02']);
        expect(info.speedVl02.p).toHaveLength(5);

        expect(info.defenseVl, 'DEFENSE da VL (atraso em minutos)').toBeTruthy();
        expect(info.defenseVl.p[0].f).toMatch(/minuto/i);

        expect(info.hotspot, 'HOTSPOT só existe na VL01').toBeTruthy();
        expect(info.hotspot.m).toEqual(['JM-VL01']);

        expect(info.status.m, 'STATUS# precisa alcançar os dois rastreadores')
            .toEqual(expect.arrayContaining(['JM-VL01', 'JM-VL02']));
        expect(info.status.f, 'STATUS# vale para as duas famílias')
            .toEqual(expect.arrayContaining(['camera', 'tracker']));
    });

    test('🔴 comando destrutivo da VL não ganha botão de consulta', async ({ authedPage }) => {
        await authedPage.goto('/comandos');

        const comConsulta = await authedPage.evaluate(() =>
            (window.CATALOGO || [])
                .filter((c) => ['OUT2', 'FACTORY', 'RELAY', 'RESET'].includes(c.c) && c.q)
                .map((c) => c.s));

        // A wiki da Jimi documenta `OUT2#`, `RELAY#` e `FACTORY` como consulta.
        // Aqui vale a régua do repo: acionar saída no veículo e apagar a
        // configuração do equipamento são AÇÃO, não pergunta.
        expect(comConsulta).toEqual([]);
    });
});
