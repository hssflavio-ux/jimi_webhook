<?php
/**
 * JIMI Webhook System — Ícones de veículo v4.10.0
 * Arquivo: includes/vehicle_icons.php
 *
 * Catálogo de ícones de veículo (Tabler Icons, MIT — github.com/tabler/tabler-icons)
 * para o pin colorido por estado em /rastreamento e para o seletor de tipo em
 * /ativos, /ativos/novo. Ver docs/PLANO_IMPLEMENTACAO_v4.10.md §Item 5.
 *
 * Mecânica de recolorização: o SVG do veículo fica sempre de UMA cor (o
 * chamador decide qual — branco no pin do mapa, `var(--muted)` no seletor do
 * formulário); quem muda por ESTADO é o fundo do pin, não o ícone. Por isso
 * cada entrada guarda só os `<path>` internos e se o desenho é preenchido
 * (`fill`) ou contornado (`stroke`) — nunca uma cor fixa.
 *
 * `trator` não tem variante "filled" no Tabler (só existe em outline);
 * `van` usa o ícone `caravan` — o Tabler não tem uma van de entrega
 * dedicada, e `caravan` é o formato "caixa sobre rodas" mais próximo
 * disponível. Os outros quatro usam a variante filled (path único, mais
 * legível num marcador pequeno que a variante outline de 2px).
 */

const VEHICLE_ICONS = [
    'carro' => [
        'label'  => 'Carro',
        'stroke' => false,
        'paths'  => '<path d="M14 5a1 1 0 0 1 .694 .28l.087 .095l3.699 4.625h.52a3 3 0 0 1 2.995 2.824l.005 .176v4a1 1 0 0 1 -1 1h-1.171a3.001 3.001 0 0 1 -5.658 0h-4.342a3.001 3.001 0 0 1 -5.658 0h-1.171a1 1 0 0 1 -1 -1v-6l.007 -.117l.008 -.056l.017 -.078l.012 -.036l.014 -.05l2.014 -5.034a1 1 0 0 1 .928 -.629zm-7 11a1 1 0 1 0 0 2a1 1 0 0 0 0 -2m10 0a1 1 0 1 0 0 2a1 1 0 0 0 0 -2m-6 -9h-5.324l-1.2 3h6.524zm2.52 0h-.52v3h2.92z" />',
    ],
    'van' => [
        'label'  => 'Van / Utilitário',
        'stroke' => false,
        'paths'  => '<path d="M15.949 3.684l.771 2.316h1.28a3 3 0 0 1 3 3v6h1a1 1 0 0 1 0 2h-1.17a3 3 0 0 1 -2.83 2h-6.17a3.001 3.001 0 0 1 -5.66 0h-1.17a3 3 0 0 1 -3 -3v-3.5a6.5 6.5 0 0 1 5.672 -6.448l6.934 -2.971a1 1 0 0 1 1.343 .603m-6.949 13.316a1 1 0 1 0 0 2a1 1 0 0 0 0 -2m5.5 -7h-1a1.5 1.5 0 0 0 -1.5 1.5v1a1.5 1.5 0 0 0 1.5 1.5h1a1.5 1.5 0 0 0 1.5 -1.5v-1a1.5 1.5 0 0 0 -1.5 -1.5m-.105 -4.653l-1.524 .653h1.742z" />',
    ],
    'caminhao' => [
        'label'  => 'Caminhão',
        'stroke' => false,
        'paths'  => '<path d="M13 4a1 1 0 0 1 1 1h4a1 1 0 0 1 .783 .378l.074 .108l3 5l.055 .103l.04 .107l.029 .109l.016 .11l.003 .085v6a1 1 0 0 1 -1 1h-1.171a3.001 3.001 0 0 1 -5.658 0h-4.342a3.001 3.001 0 0 1 -5.658 0h-1.171a1 1 0 0 1 -1 -1v-11a2 2 0 0 1 2 -2zm-6 12a1 1 0 1 0 0 2a1 1 0 0 0 0 -2m10 0a1 1 0 1 0 0 2a1 1 0 0 0 0 -2m.434 -9h-3.434v3h5.234z" />',
    ],
    'onibus' => [
        'label'  => 'Ônibus',
        'stroke' => false,
        'paths'  => '<path d="M17 4c3.4 0 6 3.64 6 8v5a1 1 0 0 1 -1 1h-1.171a3.001 3.001 0 0 1 -5.658 0h-6.342a3.001 3.001 0 0 1 -5.658 0h-1.171a1 1 0 0 1 -1 -1v-11a2 2 0 0 1 2 -2zm-11 12a1 1 0 1 0 0 2a1 1 0 0 0 0 -2m12 0a1 1 0 1 0 0 2a1 1 0 0 0 0 -2m-.76 -9.989l1.068 4.989h2.636c-.313 -2.756 -1.895 -4.82 -3.704 -4.989m-11.24 -.011h-3v3h3zm5 0h-3v3h3zm4.191 0h-2.191v3h2.834z" />',
    ],
    'moto' => [
        'label'  => 'Moto',
        'stroke' => false,
        'paths'  => '<path d="M15 5a1 1 0 0 1 .894 .553l3.225 6.449l.08 .003a4 4 0 1 1 -4.199 3.995l.005 -.2a4 4 0 0 1 2.111 -3.33l-.557 -1.115l-3.352 3.352a1 1 0 0 1 -.707 .293h-3.626q .124 .481 .126 1a4 4 0 1 1 -8 0l.005 -.2a4 4 0 0 1 6.33 -3.049l1.749 -1.751h-3.084a1 1 0 0 1 -.993 -.883l-.007 -.117a1 1 0 0 1 1 -1h9.381l-1 -2h-1.381a1 1 0 0 1 -.993 -.883l-.007 -.117a1 1 0 0 1 1 -1z" />',
    ],
    'trator' => [
        'label'  => 'Trator',
        'stroke' => true,
        'paths'  => '<path d="M3 15a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M7 15l0 .01" /><path d="M17 17a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M10.5 17l6.5 0" /><path d="M20 15.2v-4.2a1 1 0 0 0 -1 -1h-6l-2 -5h-6v6.5" /><path d="M18 5h-1a1 1 0 0 0 -1 1v4" />',
    ],
];

/**
 * Monta o SVG inline de um tipo de veículo numa cor e tamanho dados.
 *
 * @param string|null $type  Chave de VEHICLE_ICONS (`devices.vehicle_type`)
 * @param string      $color Cor CSS (hex, var(--x), etc.)
 * @param int         $size  Lado do SVG em px (viewBox interno é sempre 24x24)
 * @returns string HTML do <svg>, ou '' se o tipo não existe no catálogo
 */
function vehicle_icon_svg(?string $type, string $color = '#ffffff', int $size = 16): string
{
    $icon = VEHICLE_ICONS[$type] ?? null;
    if (!$icon) {
        return '';
    }
    $color = htmlspecialchars($color, ENT_QUOTES);
    $attrs = $icon['stroke']
        ? 'fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
        : 'fill="' . $color . '"';
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int)$size . '" height="' . (int)$size
         . '" viewBox="0 0 24 24" ' . $attrs . '>' . $icon['paths'] . '</svg>';
}

/**
 * Rótulo de exibição de um tipo de veículo.
 *
 * @param string|null $type Chave de VEHICLE_ICONS
 * @returns string
 */
function vehicle_type_label(?string $type): string
{
    return VEHICLE_ICONS[$type]['label'] ?? 'Não informado';
}

/**
 * Catálogo pronto para `json_encode()`, consumido pelo JS do mapa em
 * /rastreamento: o estado do pin muda a cada refresh (30s) e o ícone precisa
 * ser remontado no cliente sem round-trip ao servidor.
 *
 * @returns array<string, array{label:string, stroke:bool, paths:string}>
 */
function vehicle_icons_js_catalog(): array
{
    return VEHICLE_ICONS;
}
