<?php
/**
 * JIMI Webhook System — CSS + JS do player de vídeo v4.9.8
 *
 * Emitido UMA vez por página (guarda por constante). Duas telas o usam:
 * o detalhe da ocorrência, que já nasce com o player no HTML, e o relatório
 * de alarmes, que monta o player em JS dentro de um modal — daí `montar()`
 * existir como parte pública da API.
 *
 * O funcionamento e o porquê da captura no navegador estão em
 * web/components/video_player.php.
 */
if (defined('BC_PLAYER_ASSETS')) return;
define('BC_PLAYER_ASSETS', 1);
?>
<style>
.bc-player-box{position:relative;background:#0a0b0d;border-radius:var(--radius-md);overflow:hidden;display:flex;align-items:center;justify-content:center;}
.bc-player-box video{width:100%;display:block;background:#0a0b0d;}
.bc-ov{position:absolute;inset:0;display:none;align-items:center;justify-content:center;background:rgba(10,11,13,.28);border:0;cursor:pointer;width:100%;height:100%;padding:0;}
.bc-ov span{display:flex;align-items:center;justify-content:center;width:56px;height:56px;border-radius:100px;background:var(--primary);color:#fff;font-size:20px;padding-left:4px;box-shadow:0 2px 12px rgba(0,0,0,.35);transition:transform .12s;}
.bc-ov:hover span{transform:scale(1.06);}
.bc-msg{position:absolute;left:0;right:0;text-align:center;color:var(--muted-soft);font-size:12px;padding:0 16px;}
</style>
<script>
window.bcPlayer = (function () {
    /**
     * Prepara um bloco .bc-player: carrega o vídeo, posiciona no MEIO da
     * duração (o snapshot) e deixa o play a cargo do usuário.
     */
    function init(box) {
        if (!box || box.dataset.bcReady === '1') return;
        box.dataset.bcReady = '1';

        var url  = box.dataset.src;
        var ehTs = box.dataset.ts === '1';
        var v    = box.querySelector('video');
        var ov   = box.querySelector('.bc-ov');
        var msg  = box.querySelector('.bc-msg');
        var eng = null, snapPronto = false, tocando = false, morto = false, buscou = false;

        function falhar(texto) {
            if (morto) return;
            morto = true;
            if (msg) { msg.textContent = texto; msg.style.display = ''; }
            if (ov) ov.style.display = 'none';
            v.style.display = 'none';
        }

        function pronto() {
            if (morto) return;
            if (msg) msg.style.display = 'none';
            if (ov) ov.style.display = 'flex';
        }

        // Copia o quadro corrente para um canvas e o fixa como poster. Mesma
        // origem (/midia), então o canvas não fica "tainted". Sem o poster, o
        // play — que devolve a agulha para 0 — pisca o primeiro quadro, quase
        // sempre preto num vídeo de evento.
        function capturarPoster() {
            try {
                if (!v.videoWidth) return;
                var c = document.createElement('canvas');
                c.width = v.videoWidth;
                c.height = v.videoHeight;
                c.getContext('2d').drawImage(v, 0, 0, c.width, c.height);
                v.setAttribute('poster', c.toDataURL('image/jpeg', 0.82));
            } catch (e) { /* quadro ainda não decodificado — segue sem poster */ }
        }

        /**
         * Encerra a fase de snapshot: para o que estiver rodando, devolve o som
         * e entrega o play ao usuário. Chamada com miniatura (pelo `seeked`) ou
         * sem ela (pelo estouro do prazo) — em ambos os casos o vídeo NÃO pode
         * ficar tocando mudo por trás do botão de play.
         */
        function liberar() {
            if (snapPronto) return;
            snapPronto = true;
            if (eng) { try { eng.pause(); } catch (e) {} }
            try { v.pause(); } catch (e) {}
            v.muted = false;
            pronto();
        }

        /**
         * 🔴 A duração NÃO está pronta no `loadedmetadata` quando a fonte é MSE.
         *
         * Medido no navegador contra o `.ts` real: no `loadedmetadata` o
         * mpegts.js ainda não publicou a duração, então a versão anterior — que
         * decidia ali, uma vez só — desistia do snapshot e deixava o vídeo
         * rodando MUDO do começo, com o botão de play por cima. O sintoma era
         * "o `.ts` abre no início em vez do meio, e se mexe sozinho".
         *
         * Por isso a tentativa se REPETE a cada evento que pode trazer a
         * duração, e `buscou` garante um único seek.
         */
        function tentarSnapshot() {
            if (snapPronto || tocando || buscou) return;
            var d = v.duration;
            if (!isFinite(d) || d <= 0) return;   // ainda não sabemos: espera o próximo evento
            buscou = true;
            try { v.currentTime = d / 2; } catch (e) { liberar(); }
        }

        ['loadedmetadata', 'durationchange', 'canplay', 'progress'].forEach(function (ev) {
            v.addEventListener(ev, tentarSnapshot);
        });

        v.addEventListener('seeked', function () {
            if (snapPronto || tocando) return;
            // O quadro pode não estar PINTADO no instante do `seeked` — em MSE
            // com frequência não está. Capturar no frame seguinte evita poster
            // em branco (e, pior, um poster branco fixado por cima do vídeo).
            var capturar = function () { capturarPoster(); liberar(); };
            if (window.requestAnimationFrame) {
                requestAnimationFrame(function () { setTimeout(capturar, 80); });
            } else {
                setTimeout(capturar, 120);
            }
        });

        // O play não pode ficar refém do snapshot: passados 12 s sem o seek
        // completar, libera assim mesmo — sem miniatura, mas tocável e parado.
        setTimeout(function () { if (!morto) liberar(); }, 12000);

        if (ehTs) {
            if (!(window.mpegts && window.mpegts.isSupported())) {
                falhar('Este navegador não reproduz MPEG-TS.');
                return;
            }
            eng = mpegts.createPlayer({ type: 'mpegts', isLive: false, url: url });
            box.__bcEng = eng;
            eng.on(mpegts.Events.ERROR, function (tipo) {
                falhar('Não foi possível reproduzir o vídeo (' + tipo + ').');
            });
            eng.attachMediaElement(v);
            eng.load();
            // MPEG-TS só entrega quadro depois de decodificar: sem dar play, o
            // seek não renderiza nada. Mudo, porque autoplay com som é bloqueado.
            v.muted = true;
            var p0 = eng.play();
            if (p0 && p0.catch) p0.catch(function () {});
        } else {
            v.addEventListener('error', function () {
                falhar('Não foi possível carregar o vídeo.');
            });
            v.src = url;
        }

        function tocar() {
            tocando = true;
            if (ov) ov.style.display = 'none';
            v.controls = true;
            v.muted = false;
            try { v.currentTime = 0; } catch (e) {}
            var p = eng ? eng.play() : v.play();
            if (p && p.catch) p.catch(function () {});
        }

        if (ov) ov.addEventListener('click', tocar);

        // Fim do vídeo: volta ao snapshot, para o bloco não terminar preto
        v.addEventListener('ended', function () {
            tocando = false;
            v.controls = false;
            if (ov) ov.style.display = 'flex';
            var d = v.duration;
            if (isFinite(d) && d > 0) { try { v.currentTime = d / 2; } catch (e) {} }
        });
    }

    /**
     * Monta o bloco do player dentro de um container vazio e inicializa.
     * É o caminho de quem só conhece a URL na hora do clique (modal).
     *
     * `kind` é novo (25/08/2026): até então `montar()` SEMPRE montava um
     * `<video>`, e o anexo de alarme JT/T (VIDEOUPLOAD) pode chegar como FOTO
     * por canal — um `<video src="foto.jpg">` dispara o evento `error` e cai
     * em "Não foi possível carregar o vídeo", mesmo com o arquivo íntegro.
     * `kind === 'image'` usa `<img>`, mesmo tratamento que
     * `web/components/video_player.php` já dava no detalhe da ocorrência
     * (que nasce com a mídia no HTML); aqui é o caminho de quem só sabe a URL
     * na hora do clique.
     */
    function montar(container, url, ehTs, alturaMax, kind) {
        if (!container) return null;
        destruir(container.querySelector('.bc-player'));
        if (kind === 'image') {
            container.innerHTML = '<div class="bc-player-box" style="max-height:' + (alturaMax || 440) + 'px;"></div>';
            var img = document.createElement('img');
            img.src = url;
            img.alt = 'Imagem do evento';
            img.style.maxWidth = '100%';
            img.style.maxHeight = (alturaMax || 440) + 'px';
            img.style.display = 'block';
            container.querySelector('.bc-player-box').appendChild(img);
            return null;
        }
        container.innerHTML =
            '<div class="bc-player" data-auto="0" data-ts="' + (ehTs ? '1' : '0') + '">' +
              '<div class="bc-player-box" style="min-height:240px;">' +
                '<video playsinline preload="metadata" style="max-height:' + (alturaMax || 440) + 'px;"></video>' +
                '<button type="button" class="bc-ov" aria-label="Reproduzir vídeo"><span>&#9654;</span></button>' +
                '<div class="bc-msg">Carregando o vídeo…</div>' +
              '</div>' +
            '</div>';
        var box = container.querySelector('.bc-player');
        box.dataset.src = url;   // via dataset: URL com acento/aspas não escapa no HTML
        init(box);
        return box;
    }

    /** Descarrega o player (ao fechar o modal — senão a rede continua). */
    function destruir(box) {
        if (!box) return;
        var v = box.querySelector('video');
        if (box.__bcEng) {
            try {
                box.__bcEng.pause();
                box.__bcEng.unload();
                box.__bcEng.detachMediaElement();
                box.__bcEng.destroy();
            } catch (e) { /* já desmontado */ }
            box.__bcEng = null;
        }
        if (v) { try { v.pause(); } catch (e) {} v.removeAttribute('src'); v.load(); }
        box.dataset.bcReady = '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.bc-player[data-auto="1"]').forEach(init);
    });

    return { init: init, montar: montar, destruir: destruir };
})();
</script>
