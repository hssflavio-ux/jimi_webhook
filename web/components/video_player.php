<?php
/**
 * JIMI Webhook System — Player de vídeo com snapshot v4.9.8
 *
 * Player embutido, sem download: mostra um QUADRO DO MEIO do vídeo como
 * snapshot e só começa a tocar quando o usuário clica no play.
 *
 * POR QUE O SNAPSHOT É CAPTURADO NO NAVEGADOR — a alternativa óbvia seria
 * gerar a miniatura no servidor com ffmpeg, e o servidor **não tem ffmpeg**
 * (conferido no homolog: `ffmpeg: command not found`). Instalá-lo seria mais
 * uma peça de infraestrutura fora do git, como o `php8.3-zip` e o `APP_URL`
 * já são. Como os anexos de evento têm 1–4 MB e `/midia` responde a `Range`,
 * pedir ao próprio `<video>` que decodifique o quadro do meio custa uma
 * fração do arquivo e nenhuma dependência nova.
 *
 * MPEG-TS precisa do mpegts.js — nenhum navegador decodifica `.ts` no
 * `<video>` nativo, e sem a biblioteca o player fica preto sem reclamar. É a
 * PÁGINA que carrega a lib (só quando há `.ts` na tela), não este componente.
 *
 * Variáveis esperadas (defina ANTES do include):
 *   $vp_url     — URL tocável (media_play_url())              [obrigatório]
 *   $vp_ts      — bool, é MPEG-TS (media_is_ts())             [default false]
 *   $vp_kind    — 'video' | 'image'                           [default 'video']
 *   $vp_height  — altura máxima em px                         [default 300]
 *   $vp_missing — bool, arquivo não localizado no disco       [default false]
 *   $vp_auto    — bool, inicializa sozinho no load da página  [default true]
 *   $vp_id      — id do bloco (para init manual)              [opcional]
 */

require_once __DIR__ . '/video_player_assets.php';

$vp_url     = $vp_url     ?? '';
$vp_ts      = !empty($vp_ts);
$vp_kind    = $vp_kind    ?? 'video';
$vp_height  = (int)($vp_height ?? 300);
$vp_missing = !empty($vp_missing);
$vp_auto    = !isset($vp_auto) || $vp_auto;
$vp_id      = $vp_id      ?? ('bcp-' . substr(md5($vp_url . mt_rand()), 0, 8));
?>
<?php if ($vp_kind === 'image'): ?>
<div class="bc-player-box" style="max-height:<?= $vp_height ?>px;">
    <img src="<?= htmlspecialchars($vp_url) ?>" alt="Imagem do evento"
         style="max-width:100%;max-height:<?= $vp_height ?>px;display:block;">
</div>
<?php else: ?>
<div class="bc-player" id="<?= htmlspecialchars($vp_id) ?>"
     data-src="<?= htmlspecialchars($vp_url) ?>"
     data-ts="<?= $vp_ts ? '1' : '0' ?>"
     data-auto="<?= $vp_auto ? '1' : '0' ?>">
    <div class="bc-player-box" style="min-height:<?= min(220, $vp_height) ?>px;">
        <video playsinline preload="metadata" style="max-height:<?= $vp_height ?>px;"></video>
        <button type="button" class="bc-ov" aria-label="Reproduzir vídeo"><span>&#9654;</span></button>
        <div class="bc-msg">Carregando o vídeo…</div>
    </div>
</div>
<?php endif; ?>
<?php if ($vp_missing): ?>
<p style="font-size:11px;color:var(--warning);margin-top:6px;">
    O arquivo não foi localizado no servidor de mídia — pode ainda estar em transferência.
</p>
<?php endif; ?>
