<?php
/**
 * Central de Ajuda — renderizador (v4.22.0).
 *
 * Recebe o registro e o mapa de acesso (wiki_compute_access) e devolve HTML.
 * Seção bloqueada NUNCA inclui o parcial: o conteúdo dela não chega ao
 * navegador de quem não pode abrir a tela.
 */

require_once __DIR__ . '/wiki_registry.php';
require_once __DIR__ . '/wiki_access.php';

/** Acesso padrão quando o mapa não tem a seção: falha FECHADO (bloqueada). */
function wiki_acesso_padrao(): array {
    return ['state' => 'bloqueada', 'reason' => WIKI_MOTIVO_GRUPO, 'allowed' => [], 'denied' => []];
}

function wiki_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Cadeado inline (herda a cor do texto). */
function wiki_lock_icon(): string {
    return '<svg class="wiki-lock-ico" width="12" height="12" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
        . 'aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/>'
        . '<path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
}

/** @returns array Seções sem as ocultas (sem índice e sem corpo). */
function wiki_visible(array $registry): array {
    return array_values(array_filter($registry, fn($s) => empty($s['hidden'])));
}

/** Rótulos das ações na faixa "Seu acesso". */
function wiki_acao_rotulo(string $acao): string {
    return ['create' => 'criar', 'edit' => 'editar', 'delete' => 'excluir', 'export' => 'exportar'][$acao] ?? $acao;
}

/**
 * @param array $sec    Seção do registro.
 * @param bool  $locked Acrescenta o selo "bloqueada".
 */
function wiki_heading(array $sec, bool $locked): string {
    $badge = '';
    if (!empty($sec['badge'])) {
        $badge .= ' <span class="badge">' . wiki_esc($sec['badge']) . '</span>';
    }
    if (!empty($sec['admin_only'])) {
        $badge .= ' <span class="badge" style="background:#fce4eb;color:#c83532">admin</span>';
    }
    if ($locked) {
        $badge .= ' <span class="wiki-lock">' . wiki_lock_icon() . 'bloqueada</span>';
    }
    return sprintf('<h%d id="%s">%s%s</h%d>', $sec['level'], wiki_esc($sec['id']), wiki_esc($sec['title']), $badge, $sec['level']);
}

/**
 * Inclui o parcial da seção capturando a saída. A constante WIKI_SECTION é a
 * trava dos parciais: o .htaccess não nega includes/, e sem ela um parcial
 * abriria por URL direta.
 */
function wiki_include_section(string $id): string {
    $arquivo = __DIR__ . '/wiki/sections/' . $id . '.php';
    if (!is_file($arquivo)) return '';
    if (!defined('WIKI_SECTION')) define('WIKI_SECTION', true);
    ob_start();
    include $arquivo;
    return (string)ob_get_clean();
}

/**
 * Faixa "Seu acesso" — só para seção com ações ou recursos restritos.
 *
 * @param array $sec Seção do registro.
 * @param array $acc Resultado de wiki_access() (liberada).
 */
function wiki_access_strip(array $sec, array $acc): string {
    if (empty($sec['actions']) && empty($sec['extras'])) return '';
    $ok = array_merge(['ver'], array_map('wiki_acao_rotulo', $acc['allowed']));
    $html = '<div class="wiki-access"><span class="wiki-access-label">Seu acesso</span>'
        . '<span class="wiki-access-ok">Você pode: ' . wiki_esc(implode(' · ', $ok)) . '</span>';
    if ($acc['denied']) {
        $html .= '<span class="wiki-access-no">Não disponível para você: '
            . wiki_esc(implode(', ', array_map('wiki_acao_rotulo', $acc['denied']))) . '</span>';
    }
    foreach ($sec['extras'] as $extra) {
        $html .= '<span class="wiki-access-extra">' . wiki_esc($extra) . '</span>';
    }
    return $html . '</div>';
}

/** Stub de seção bloqueada: o que a tela é e por que não está liberada. */
function wiki_stub(array $sec, array $acc): string {
    return '<div class="wiki-locked"><p>' . wiki_esc($sec['summary']) . '</p>'
        . '<p class="wiki-locked-why">' . wiki_lock_icon() . ' ' . wiki_esc((string)$acc['reason']) . '</p></div>';
}

/**
 * Índice lateral. Bloqueada fica esmaecida e continua clicável (leva ao stub).
 * Item de grupo e h2 avulso com `sub` são recuados, como o índice sempre foi.
 */
function wiki_render_toc(array $registry, array $access): string {
    $grupos = wiki_groups();
    $out = '';
    $ultimo = null;
    foreach (wiki_visible($registry) as $sec) {
        if ($sec['group'] !== null && $sec['group'] !== $ultimo) {
            $out .= '<a href="#' . wiki_esc($sec['group']) . '">' . wiki_esc($grupos[$sec['group']]) . "</a>\n";
        }
        $ultimo = $sec['group'];
        $bloqueada = ($access[$sec['id']] ?? wiki_acesso_padrao())['state'] === 'bloqueada';
        $recuo = ($sec['group'] !== null || !empty($sec['sub']))
            ? ' style="padding-left:20px;font-size:12px"' : '';
        $out .= '<a href="#' . wiki_esc($sec['id']) . '"' . ($bloqueada ? ' class="locked"' : '') . $recuo . '>'
            . wiki_esc($sec['title']) . ($bloqueada ? wiki_lock_icon() : '') . "</a>\n";
    }
    return $out;
}

/** Corpo da wiki: heading de grupo quando entra num grupo, depois cada seção. */
function wiki_render_body(array $registry, array $access): string {
    $grupos = wiki_groups();
    $out = '';
    $ultimo = null;
    foreach (wiki_visible($registry) as $sec) {
        if ($sec['group'] !== null && $sec['group'] !== $ultimo) {
            $out .= '<h2 id="' . wiki_esc($sec['group']) . '">' . wiki_esc($grupos[$sec['group']]) . "</h2>\n";
        }
        $ultimo = $sec['group'];
        $acc = $access[$sec['id']] ?? wiki_acesso_padrao();
        $bloqueada = $acc['state'] === 'bloqueada';
        $out .= wiki_heading($sec, $bloqueada) . "\n";
        $out .= $bloqueada
            ? wiki_stub($sec, $acc)
            : wiki_access_strip($sec, $acc) . wiki_include_section($sec['id']);
        $out .= "\n";
    }
    return $out;
}
