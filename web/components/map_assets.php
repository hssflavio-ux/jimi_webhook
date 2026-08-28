<?php
/**
 * bycamera — Assets compartilhados dos mapas (Leaflet + camada híbrida) v4.13.20
 *
 * Ponto ÚNICO do `<link>`/`<script>` do Leaflet e da inicialização das camadas
 * de base (Ruas/OpenStreetMap e Híbrido/Esri) — antes disso, os 10 mapas do
 * sistema (`rastreamento`, `resumo`, `painel`, `geocercas`, `ativo_detalhe` e
 * os `rel_*` com mapa) duplicavam a MESMA tag `<link rel="stylesheet"
 * href="…leaflet@1.9.4…">` e o MESMO `L.tileLayer(osm).addTo(map)`, cada um
 * copiado à mão do vizinho. Padronizar aqui é o que permite acrescentar
 * satélite numa vez só, em vez de editar 10 arquivos com o mesmo risco de
 * esquecer um.
 *
 * Uso — 2 passos, em qualquer handler que hoje monta `$extra_head` com Leaflet:
 *
 *   require_once __DIR__ . '/../web/components/map_assets.php';
 *   $extra_head = BC_MAP_ASSETS_HTML . '<script>…resto específico da tela…</script>';
 *
 *   // no JS da tela, ONDE ANTES ERA:
 *   //   L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {…}).addTo(map);
 *   // AGORA É:
 *   bcMapBaseLayers(map);
 *
 * `bcMapBaseLayers(map)` adiciona a camada Ruas por padrão, a camada Híbrido
 * (Esri World Imagery — grátis, sem chave de API) e o controle de camadas do
 * próprio Leaflet (canto superior direito, recolhido) para alternar entre as
 * duas. Devolve `{ruas, satelite}` para quem precisar dos objetos de camada
 * diretamente — nenhum chamador atual precisa.
 *
 * ⚠️ **Satélite puro não existe mais como opção: a camada é HÍBRIDA.** Imagem
 * aérea sem via nem rótulo é inútil para operação de frota — o operador não
 * localiza um veículo sem saber em que rua ele está. O híbrido é a imagem do
 * `World_Imagery` com DOIS overlays transparentes de referência do próprio
 * Esri por cima, agrupados num `L.layerGroup`: `World_Transportation` (traçado
 * e nome das vias) e `World_Boundaries_and_Places` (limites e topônimos).
 * Overlay é transparente por definição: se um tile de referência faltar num
 * zoom/quadrante, o buraco cai sobre a imagem de satélite e o mapa continua
 * legível — nunca fica em branco.
 *
 * ⚠️ Esri World Imagery é gratuito e sem cadastro, mas é um serviço de
 * terceiro sem contrato de nível de serviço com este projeto — se um dia a
 * URL mudar ou o serviço ficar instável, é aqui, num lugar só, que se troca.
 */
if (!defined('BC_MAP_ASSETS_HTML')) {
define('BC_MAP_ASSETS_HTML', <<<'HTML'
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
/**
 * Camadas de base padrão de todo mapa do sistema: Ruas (OSM) + Híbrido
 * (satélite Esri + vias e rótulos), com o controle de alternância já incluído.
 *
 * @param {L.Map} map
 * @param {{satelite?: boolean}} [opts] opts.satelite=true abre já em modo híbrido
 * @returns {{ruas: L.TileLayer, satelite: L.LayerGroup}}
 */
window.bcMapBaseLayers = function (map, opts) {
    opts = opts || {};
    var ruas = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    });
    // Híbrido = imagem aérea + os dois overlays de referência do Esri.
    var imagem = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri — Source: Esri, Maxar, Earthstar Geographics'
    });
    var vias = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Vias &copy; Esri'
    });
    var rotulos = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Limites &copy; Esri'
    });
    var satelite = L.layerGroup([imagem, vias, rotulos]);
    (opts.satelite ? satelite : ruas).addTo(map);
    L.control.layers({ 'Ruas': ruas, 'Híbrido': satelite }, null, { collapsed: true, position: 'topright' }).addTo(map);
    return { ruas: ruas, satelite: satelite };
};
</script>
HTML
);
}
