<?php
/**
 * Central de Ajuda por perfil (v4.22.0) — resolvedor de acesso e registro, sem banco.
 *
 * Trava o que não aparece em erro nenhum se quebrar: a wiki continua
 * desenhando, só que mostrando ao usuário uma tela que ele não pode abrir (ou
 * escondendo uma que ele pode).
 *
 * Uso:
 *   php tests/helpers/wiki_access.test.php
 */

require_once __DIR__ . '/../../includes/wiki_registry.php';
require_once __DIR__ . '/../../includes/wiki_access.php';
require_once __DIR__ . '/../../includes/wiki_render.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-66s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

$tudo = fn($s, $a = 'view') => true;
$nada = fn($s, $a = 'view') => false;
$soCria = fn($s, $a = 'view') => in_array($a, ['view', 'create'], true);

echo "== resolvedor de acesso ==\n";
$sec = wiki_sec('x', 'X', ['screen' => 'ativos', 'actions' => ['create', 'delete']]);
checa('libera quando can() é verdadeiro', 'liberada', wiki_access($sec, 'cliente', $tudo)['state']);
checa('bloqueia quando o grupo nega o view', 'bloqueada', wiki_access($sec, 'cliente', $nada)['state']);
checa('motivo de grupo', WIKI_MOTIVO_GRUPO, wiki_access($sec, 'cliente', $nada)['reason']);
$r = wiki_access($sec, 'cliente', $soCria);
checa('ação permitida', ['create'], $r['allowed']);
checa('ação negada', ['delete'], $r['denied']);

$adm = wiki_sec('y', 'Y', ['screen' => 'firmwares', 'admin_only' => true]);
checa('admin_only bloqueia não-admin mesmo com can() verdadeiro', 'bloqueada', wiki_access($adm, 'cliente', $tudo)['state']);
checa('motivo de admin', WIKI_MOTIVO_ADMIN, wiki_access($adm, 'cliente', $tudo)['reason']);
checa('admin_only libera role admin', 'liberada', wiki_access($adm, 'admin', $tudo)['state']);
checa('admin com grupo que nega a tela continua bloqueado', 'bloqueada', wiki_access($adm, 'admin', $nada)['state']);
checa('role vazio não é admin', 'bloqueada', wiki_access($adm, '', $tudo)['state']);

$livre = wiki_sec('z', 'Z');
checa('seção sem tela é sempre liberada', 'liberada', wiki_access($livre, 'cliente', $nada)['state']);

echo "== sanidade do registro ==\n";
$reg = wiki_registry();
$ids = array_column($reg, 'id');
checa('ids únicos', count($ids), count(array_unique($ids)));
$grupos = wiki_groups();
$ruins = [];
foreach ($reg as $s) {
    if (!in_array($s['level'], [2, 3], true)) $ruins[] = $s['id'] . ':level';
    if ($s['group'] !== null && !isset($grupos[$s['group']])) $ruins[] = $s['id'] . ':group';
    if (($s['screen'] !== null || $s['admin_only']) && trim($s['summary']) === '') $ruins[] = $s['id'] . ':summary';
    if (array_diff($s['actions'], ['create', 'edit', 'delete', 'export'])) $ruins[] = $s['id'] . ':actions';
}
checa('toda entrada do registro é válida', [], $ruins);

function texto_normal(string $html): string {
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<\?php.*?\?>/s', '', $html);
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
}
function marcador(string $id): string {
    $f = __DIR__ . '/../../includes/wiki/sections/' . $id . '.php';
    return substr(texto_normal((string)file_get_contents($f)), 0, 80);
}

echo "== vazamento: parcial bloqueado não pode aparecer ==\n";
$accAdm  = wiki_compute_access($reg, 'admin', $tudo);
$accBloq = wiki_compute_access($reg, 'cliente', $nada);
$htmlAdm  = texto_normal(wiki_render_body($reg, $accAdm));
$htmlBloq = texto_normal(wiki_render_body($reg, $accBloq));
$marcadores = [];
foreach ($reg as $s) {
    if (!empty($s['dynamic'])) continue;
    $m = marcador($s['id']);
    $marcadores[$s['id']] = $m;
    if (!empty($s['hidden'])) {
        checa("oculta não é renderizada: {$s['id']}", false, strpos($htmlAdm, $m) !== false);
        continue;
    }
    checa("admin vê o conteúdo de {$s['id']}", true, strpos($htmlAdm, $m) !== false);
    $bloqueavel = $s['screen'] !== null || $s['admin_only'];
    checa("perfil sem acesso: {$s['id']} " . ($bloqueavel ? 'não vaza' : 'segue visível'),
        !$bloqueavel, strpos($htmlBloq, $m) !== false);
}
checa('marcadores únicos entre parciais', count($marcadores), count(array_unique($marcadores)));

echo "== índice ==\n";
$toc = wiki_render_toc($reg, $accBloq);
$semAncora = [];
foreach ($reg as $s) {
    if (!empty($s['hidden'])) continue;
    if (strpos($toc, 'href="#' . $s['id'] . '"') === false) $semAncora[] = $s['id'];
}
foreach (array_keys(wiki_groups()) as $g) {
    if (strpos($toc, 'href="#' . $g . '"') === false) $semAncora[] = $g;
}
checa('índice tem toda seção visível e todo grupo', [], $semAncora);
checa('oculta não aparece no índice', false, strpos($toc, 'href="#parametros"') !== false);
checa('bloqueada vira link esmaecido', true, strpos($toc, 'class="locked"') !== false);
checa('liberada não é esmaecida', false, strpos(wiki_render_toc($reg, $accAdm), 'class="locked"') !== false);

echo "== stub e faixa ==\n";
$htmlBloqCru = wiki_render_body($reg, $accBloq);
checa('stub traz o motivo do grupo', true, strpos($htmlBloqCru, WIKI_MOTIVO_GRUPO) !== false);
checa('stub traz o motivo de admin', true, strpos(wiki_render_body($reg, wiki_compute_access($reg, 'cliente', $tudo)), WIKI_MOTIVO_ADMIN) !== false);
checa('stub mantém a âncora da seção', true, strpos($htmlBloqCru, 'id="rel-posicoes"') !== false);
$semExport = fn($s, $a = 'view') => $a !== 'export';
$htmlFaixa = wiki_render_body($reg, wiki_compute_access($reg, 'admin', $semExport));
checa('faixa lista o que falta', true, strpos($htmlFaixa, 'Não disponível para você: exportar') !== false);
checa('faixa mostra o recurso restrito', true, strpos($htmlFaixa, 'Descarte em massa') !== false);
checa('seção sem ações não ganha faixa', false, strpos(wiki_render_body([wiki_sec('intro', 'Visão Geral do Sistema', ['level' => 2])], ['intro' => wiki_access(wiki_sec('intro', 'x'), 'admin', $tudo)]), 'wiki-access') !== false);

echo "== âncoras antigas ==\n";
$antigas = ['intro','primeiros-passos','resumo','rastreamento','bi','mapa-risco','ocorrencias-dashboard','notificacoes',
    'videos','video-aovivo','video-playback','video-downloads','relatorios','rel-comum','rel-modelos','rel-posicoes',
    'rel-deslocamento','rel-desatualizados','rel-alarmes','rel-dirigibilidade','rel-ocorrencias','rel-geocercas',
    'rel-status-frota','rel-paradas','rel-ociosidade','rel-ignicao','rel-velocidade','agendamentos','cadastros',
    'ativos','chips','clientes','equipamentos','geocercas','grupos-permissao','motoristas','config-ocorrencias',
    'config-notificacoes','config-smtp','usuarios','operacoes','comandos','parametros','firmwares','exportar','checklist'];
checa('nenhuma âncora anterior à v4.22.0 sumiu', [], array_values(array_diff($antigas, array_merge($ids, array_keys(wiki_groups())))));

echo "== mapa de acesso sem a seção: falha FECHADO ==\n";
// Mapa vazio = nenhuma seção resolvida. Bloquear é o lado seguro: liberar
// abriria o parcial (e o texto) de uma tela que ninguém decidiu liberar.
$htmlSemMapa = texto_normal(wiki_render_body($reg, []));
checa('seção ausente do mapa não vaza o parcial (rel-posicoes)', false, strpos($htmlSemMapa, marcador('rel-posicoes')) !== false);
checa('seção ausente do mapa vira link com cadeado no índice', true, strpos(wiki_render_toc($reg, []), 'class="locked"') !== false);

echo "== perfil e card ==\n";
checa('role admin → admin', 'admin', wiki_profile(['role' => 'admin', 'user_type' => 'revendedor']));
checa('user_type revendedor → revendedor', 'revendedor', wiki_profile(['role' => 'user', 'user_type' => 'revendedor']));
checa('demais → cliente', 'cliente', wiki_profile(['role' => 'user', 'user_type' => 'cliente']));
checa('sem role e sem user_type → cliente', 'cliente', wiki_profile([]));

$idsVisiveis = array_column(wiki_visible($reg), 'id');
foreach (['admin', 'revendedor', 'cliente'] as $p) {
    $info = wiki_profile_info($p);
    checa("atalhos de $p existem no registro", [], array_values(array_diff($info['shortcuts'], $idsVisiveis)));
    checa("atalhos de $p têm ao menos 6 opções (reposição)", true, count($info['shortcuts']) >= 6);
}

$cardCli = wiki_render_card('cliente', null, $reg, wiki_compute_access($reg, 'cliente', $tudo));
checa('card mostra o perfil', true, strpos($cardCli, 'Seu perfil: Cliente') !== false);
checa('card sem grupo explica o "vê tudo, exceto admin"', true, strpos($cardCli, 'exceto as áreas restritas a administradores') !== false);
checa('card com grupo mostra o nome', true, strpos(wiki_render_card('cliente', 'Supervisão', $reg, wiki_compute_access($reg, 'cliente', $tudo)), 'Grupo Supervisão') !== false);
preg_match_all('/href="#([a-z0-9-]+)" class="wiki-shortcut"/', $cardCli, $sc);
checa('card mostra exatamente 5 atalhos (a lista tem reposição)', 5, count($sc[1]));

$semRastreamento = fn($s, $a = 'view') => $s !== 'rastreamento';
$cardSem = wiki_render_card('cliente', null, $reg, wiki_compute_access($reg, 'cliente', $semRastreamento));
preg_match_all('/href="#([a-z0-9-]+)" class="wiki-shortcut"/', $cardSem, $sc2);
checa('atalho bloqueado sai e o próximo entra (continua 5)', 5, count($sc2[1]));
checa('atalho para tela bloqueada não aparece', false, in_array('rastreamento', $sc2[1], true));

$soRastreamento = fn($s, $a = 'view') => $s === 'rastreamento';
$cardPouco = wiki_render_card('cliente', 'Restrito', $reg, wiki_compute_access($reg, 'cliente', $soRastreamento));
preg_match_all('/href="#([a-z0-9-]+)" class="wiki-shortcut"/', $cardPouco, $sc3);
checa('grupo restrito: só sobra o atalho liberado, sem botão morto', ['rastreamento'], $sc3[1]);

echo "== contador \"N de M telas\" ==\n";
$telas = array_values(array_filter(wiki_visible($reg), fn($s) => $s['handler'] !== null));
$m = count($telas);
checa('contador mostra M do registro', true, strpos($cardCli, "de $m telas") !== false);
$cardBloq = wiki_render_card('cliente', null, $reg, wiki_compute_access($reg, 'cliente', $nada));
checa('sem nenhuma permissão: 0 de M', true, strpos($cardBloq, "0 de $m telas") !== false);

echo "== Meu acesso ==\n";
$meu = wiki_render_meu_acesso($reg, wiki_compute_access($reg, 'cliente', $nada));
checa('lista bloqueadas com o motivo', true, strpos($meu, WIKI_MOTIVO_GRUPO) !== false);
checa('Meu acesso entra no corpo da página', true, strpos(wiki_render_body($reg, wiki_compute_access($reg, 'cliente', $tudo)), 'id="meu-acesso"') !== false);

echo "\n$total verificações, $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
