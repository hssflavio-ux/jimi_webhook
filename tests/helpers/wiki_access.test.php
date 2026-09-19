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

echo "\n$total verificações, $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
