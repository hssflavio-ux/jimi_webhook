// @ts-nocheck
/**
 * Harness do player de vídeo (v4.9.8) — Node puro, sem navegador.
 *
 * Executa o JS **REAL** de `web/components/video_player_assets.php` (extraído
 * do arquivo, não uma cópia) sobre um DOM mínimo, e afirma a regra que define
 * a entrega: **o snapshot é o quadro do MEIO do vídeo**. 10 s → 5 s, 20 s → 10 s.
 *
 * O QUE ISTO PROVA: a máquina de estados — onde o seek para, quando o overlay
 * aparece, o que o play faz, que o mpegts.js entra só no `.ts` e que fechar o
 * modal desmonta o player.
 *
 * O QUE ISTO NÃO PROVA: a decodificação. Um `<video>` de mentira não desenha
 * quadro nenhum, então `capturarPoster()` roda e não produz imagem. A miniatura
 * de verdade só se confirma na tela — está registrado como pendência no
 * STATUS.md, e não é o mesmo que "verificado".
 *
 * Uso: node tests/helpers/player_snapshot.test.js
 */
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const arquivo = path.join(__dirname, '..', '..', 'web', 'components', 'video_player_assets.php');
const bruto = fs.readFileSync(arquivo, 'utf8');
const m = bruto.match(/<script>([\s\S]*?)<\/script>/);
if (!m) { console.error('FALHA: bloco <script> não encontrado em video_player_assets.php'); process.exit(1); }
const codigo = m[1];

let falhas = 0, ok = 0;
function afirmar(cond, desc) {
    if (cond) { ok++; console.log('  ✓ ' + desc); }
    else { falhas++; console.log('  ✗ ' + desc); }
}

// ── DOM mínimo ───────────────────────────────────────────────────────────────
function criarElemento(tag) {
    const el = {
        tagName: tag.toUpperCase(),
        style: {}, dataset: {}, attrs: {}, filhos: [], ouvintes: {},
        innerHTML: '', textContent: '',
        muted: false, controls: false, currentTime: 0, duration: NaN,
        videoWidth: 0, videoHeight: 0, src: null,
        addEventListener(ev, fn) { (this.ouvintes[ev] = this.ouvintes[ev] || []).push(fn); },
        disparar(ev) { (this.ouvintes[ev] || []).forEach(fn => fn.call(this, {})); },
        setAttribute(k, v) { this.attrs[k] = v; },
        removeAttribute(k) { delete this.attrs[k]; },
        getContext() { return { drawImage() {} }; },
        toDataURL() { return 'data:image/jpeg;base64,FAKE'; },
        pause() { this.pausado = true; },
        play() { this.tocou = true; return Promise.resolve(); },
        load() {},
        querySelector(sel) {
            const busca = (n) => {
                for (const f of n.filhos) {
                    if (sel === 'video' && f.tagName === 'VIDEO') return f;
                    if (sel.startsWith('.') && (f.classe || '').split(' ').includes(sel.slice(1))) return f;
                    const r = busca(f); if (r) return r;
                }
                return null;
            };
            return busca(this);
        },
    };
    return el;
}

function montarBloco({ ts }) {
    const box   = criarElemento('div'); box.classe = 'bc-player';
    const caixa = criarElemento('div'); caixa.classe = 'bc-player-box';
    const video = criarElemento('video');
    const ov    = criarElemento('button'); ov.classe = 'bc-ov';
    const msg   = criarElemento('div');    msg.classe = 'bc-msg';
    caixa.filhos = [video, ov, msg];
    box.filhos = [caixa];
    box.dataset.src = '/midia?f=teste' + (ts ? '.ts' : '.mp4');
    box.dataset.ts = ts ? '1' : '0';
    return { box, video, ov, msg };
}

// mpegts.js de mentira: registra o que o player pediu
function criarMpegts() {
    const chamadas = { criados: 0, load: 0, play: 0, pause: 0, destroy: 0, url: null };
    return {
        chamadas,
        lib: {
            isSupported: () => true,
            Events: { ERROR: 'error' },
            createPlayer(cfg) {
                chamadas.criados++; chamadas.url = cfg.url;
                return {
                    on() {}, attachMediaElement() {}, detachMediaElement() {},
                    load() { chamadas.load++; },
                    play() { chamadas.play++; return Promise.resolve(); },
                    pause() { chamadas.pause++; },
                    unload() {}, destroy() { chamadas.destroy++; },
                };
            },
        },
    };
}

function carregar(mpegtsLib) {
    const doc = {
        ouvintes: {},
        addEventListener(ev, fn) { (this.ouvintes[ev] = this.ouvintes[ev] || []).push(fn); },
        querySelectorAll() { return []; },
        createElement: criarElemento,
    };
    const janela = { document: doc, mpegts: mpegtsLib };
    const ctx = vm.createContext({ window: janela, document: doc, setTimeout, clearTimeout, console });
    ctx.mpegts = mpegtsLib;
    vm.runInContext(codigo, ctx);
    return janela.bcPlayer;
}

// ── 1. MP4: o seek para no MEIO ──────────────────────────────────────────────
console.log('\nMP4 — snapshot no meio da duração');
[[10, 5], [20, 10], [37.4, 18.7]].forEach(([dur, meio]) => {
    const p = carregar(null);
    const { box, video, ov, msg } = montarBloco({ ts: false });
    p.init(box);
    afirmar(video.src === box.dataset.src, `src recebe a URL do /midia (${dur}s)`);
    video.duration = dur;
    video.disparar('loadedmetadata');
    afirmar(Math.abs(video.currentTime - meio) < 1e-6,
        `duração ${dur}s → snapshot em ${meio}s (currentTime=${video.currentTime})`);
    // o quadro decodificado chega
    video.videoWidth = 1280; video.videoHeight = 720;
    video.disparar('seeked');
    afirmar(ov.style.display === 'flex', `overlay de play aparece após o seek (${dur}s)`);
    afirmar(msg.style.display === 'none', `mensagem "Carregando" some após o seek (${dur}s)`);
    afirmar(video.attrs.poster === 'data:image/jpeg;base64,FAKE', `quadro vira poster (${dur}s)`);
    afirmar(video.controls === false, `controles ficam escondidos antes do play (${dur}s)`);
});

// ── 2. O play volta ao início, não continua do meio ──────────────────────────
console.log('\nMP4 — o play recomeça do zero');
{
    const p = carregar(null);
    const { box, video, ov } = montarBloco({ ts: false });
    p.init(box);
    video.duration = 20; video.disparar('loadedmetadata');
    video.videoWidth = 640; video.videoHeight = 360; video.disparar('seeked');
    afirmar(video.currentTime === 10, 'antes do play a agulha está no meio (10s)');
    ov.ouvintes.click[0]();   // clique no overlay
    afirmar(video.currentTime === 0, 'o play devolve a agulha para 0 — não toca do meio');
    afirmar(video.controls === true, 'controles nativos aparecem só depois do play');
    afirmar(ov.style.display === 'none', 'overlay some durante a reprodução');
    afirmar(video.tocou === true, 'play() foi chamado');
    // fim do vídeo: volta ao snapshot
    video.disparar('ended');
    afirmar(video.currentTime === 10, 'ao terminar, volta ao quadro do meio');
    afirmar(ov.style.display === 'flex', 'ao terminar, o overlay reaparece');
}

// ── 3. Duração desconhecida não trava o play ─────────────────────────────────
console.log('\nDuração desconhecida (stream sem índice)');
{
    const p = carregar(null);
    const { box, video, ov } = montarBloco({ ts: false });
    p.init(box);
    video.duration = Infinity; video.disparar('loadedmetadata');
    afirmar(video.currentTime === 0, 'sem duração finita não há seek');
    afirmar(ov.style.display === 'flex', 'mesmo sem snapshot o play fica disponível');
}

// ── 4. MPEG-TS passa pelo mpegts.js ──────────────────────────────────────────
console.log('\nMPEG-TS — remux pelo mpegts.js');
{
    const mp = criarMpegts();
    const p = carregar(mp.lib);
    const { box, video, ov } = montarBloco({ ts: true });
    p.init(box);
    afirmar(mp.chamadas.criados === 1, 'createPlayer chamado uma vez');
    afirmar(mp.chamadas.url === box.dataset.src, 'a URL entregue ao mpegts é a do /midia');
    afirmar(mp.chamadas.load === 1 && mp.chamadas.play === 1,
        'load()+play() — TS só entrega quadro depois de decodificar');
    afirmar(video.muted === true, 'play do snapshot é MUDO (autoplay com som é bloqueado)');
    afirmar(video.src === null, 'o <video> NÃO recebe src direto no caminho TS (é MSE)');
    video.duration = 15; video.disparar('loadedmetadata');
    afirmar(video.currentTime === 7.5, 'duração 15s → snapshot em 7,5s');
    video.videoWidth = 1280; video.videoHeight = 720; video.disparar('seeked');
    afirmar(mp.chamadas.pause === 1, 'pausa após capturar o quadro — não fica baixando');
    afirmar(video.muted === false, 'som devolvido depois do snapshot');
    afirmar(ov.style.display === 'flex', 'overlay de play aparece');
    // fechar o modal desmonta
    p.destruir(box);
    afirmar(mp.chamadas.destroy === 1, 'destruir() desmonta o mpegts (senão a rede continua)');
    afirmar(box.dataset.bcReady === '', 'o bloco volta a poder ser inicializado');
}

// ── 5. Sem mpegts.js o TS avisa, em vez de ficar preto ───────────────────────
console.log('\nMPEG-TS sem a biblioteca');
{
    const p = carregar(null);
    const { box, video, ov, msg } = montarBloco({ ts: true });
    p.init(box);
    afirmar(/MPEG-TS/.test(msg.textContent), 'mostra o motivo: "não reproduz MPEG-TS"');
    afirmar(ov.style.display === 'none', 'não oferece play que não funcionaria');
    afirmar(video.style.display === 'none', 'esconde o <video> preto');
}

// ── 6. montar(): o caminho do modal ──────────────────────────────────────────
console.log('\nmontar() — player criado no clique (modal do relatório de alarmes)');
{
    const p = carregar(null);
    const cont = criarElemento('div');
    // O DOM de mentira não parseia innerHTML, então o container simula o que o
    // navegador faria: guarda o HTML e passa a devolver um bloco pronto no
    // querySelector — que é o contrato que montar() usa.
    let htmlRecebido = '';
    let bloco = null;
    Object.defineProperty(cont, 'innerHTML', {
        get() { return htmlRecebido; },
        set(v) { htmlRecebido = v; bloco = montarBloco({ ts: false }); },
    });
    cont.querySelector = () => bloco && bloco.box;

    const urlComAspas = '/midia?f=EVENT_"aspas".mp4';
    p.montar(cont, urlComAspas, false, 440);
    afirmar(htmlRecebido.includes('class="bc-player"'), 'monta o bloco do player');
    afirmar(!htmlRecebido.includes('EVENT_"aspas"'),
        'a URL NÃO é interpolada no HTML — vai pelo dataset, imune a aspas no nome');
    afirmar(htmlRecebido.includes('max-height:440px'), 'respeita a altura pedida');
    afirmar(htmlRecebido.includes('data-auto="0"'), 'não auto-inicializa: quem manda é o clique');
    afirmar(bloco.box.dataset.src === urlComAspas, 'a URL chega inteira pelo dataset');
    afirmar(bloco.box.dataset.bcReady === '1', 'montar() já inicializa o bloco');
    afirmar(bloco.video.src === urlComAspas, 'o <video> recebeu a URL');

    // O snapshot vale igual no modal
    bloco.video.duration = 30;
    bloco.video.disparar('loadedmetadata');
    afirmar(bloco.video.currentTime === 15, 'no modal também: 30s → snapshot em 15s');
}

console.log(`\n${ok} asserções ok, ${falhas} falha(s)\n`);
process.exit(falhas ? 1 : 0);
