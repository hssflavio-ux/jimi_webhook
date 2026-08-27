<?php
/**
 * bycamera — Assets compartilhados dos mapas (Leaflet + camada de satélite) v4.13.18
 *
 * Ponto ÚNICO do `<link>`/`<script>` do Leaflet e da inicialização das camadas
 * de base (Ruas/OpenStreetMap e Satélite/Esri) — antes disso, os 10 mapas do
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
 * `bcMapBaseLayers(map)` adiciona a camada Ruas por padrão, a camada Satélite
 * (Esri World Imagery — grátis, sem chave de API) e o controle de camadas do
 * próprio Leaflet (canto superior direito, recolhido) para alternar entre as
 * duas. Devolve `{ruas, satelite}` para quem precisar dos objetos de camada
 * diretamente — nenhum chamador atual precisa.
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
 * Camadas de base padrão de todo mapa do sistema: Ruas (OSM) + Satélite
 * (Esri), com o controle de alternância já incluído.
 *
 * @param {L.Map} map
 * @param {{satelite?: boolean}} [opts] opts.satelite=true abre já em modo satélite
 * @returns {{ruas: L.TileLayer, satelite: L.TileLayer}}
 */
window.bcMapBaseLayers = function (map, opts) {
    opts = opts || {};
    var ruas = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    });
    var satelite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: 'Tiles &copy; Esri — Source: Esri, Maxar, Earthstar Geographics'
    });
    (opts.satelite ? satelite : ruas).addTo(map);
    L.control.layers({ 'Ruas': ruas, 'Satélite': satelite }, null, { collapsed: true, position: 'topright' }).addTo(map);
    return { ruas: ruas, satelite: satelite };
};
</script>
HTML
);
}
