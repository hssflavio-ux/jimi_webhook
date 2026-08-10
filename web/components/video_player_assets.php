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
        var eng = null, snapPronto = false, tocando = false, morto = false;

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

        v.addEventListener('loadedmetadata', function () {
            if (snapPronto || tocando) return;
            var d = v.duration;
            // Duração desconhecida (stream sem índice): não há "meio" para buscar
            if (!isFinite(d) || d <= 0) { snapPronto = true; pronto(); return; }
            try { v.currentTime = d / 2; } catch (e) { snapPronto = true; pronto(); }
        });

        v.addEventListener('seeked', function () {
            if (snapPronto || tocando) return;
            snapPronto = true;
            capturarPoster();
            if (eng) { try { eng.pause(); } catch (e) {} }
            try { v.pause(); } catch (e) {}
            v.muted = false;
            pronto();
        });

        // O play não pode ficar refém do snapshot: passados 12 s sem o seek
        // completar, libera assim mesmo — sem miniatura, mas tocável.
        setTimeout(function () {
            if (!snapPronto && !morto) { snapPronto = true; pronto(); }
        }, 12000);

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
     */
    function montar(container, url, ehTs, alturaMax) {
        if (!container) return null;
        destruir(container.querySelector('.bc-player'));
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
