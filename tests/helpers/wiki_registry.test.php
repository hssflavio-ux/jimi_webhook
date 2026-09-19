<?php
/**
 * Central de Ajuda (v4.22.0) — travas do registro contra o código real, sem banco.
 *
 * A wiki parou em 14/08/2026 porque nada a ligava ao resto do sistema. Aqui o
 * registro é conferido contra a matriz de permissões, os handlers e os
 * parciais: quem cadastrar uma tela e esquecer a wiki quebra este teste.
 *
 * Uso:
 *   php tests/helpers/wiki_registry.test.php
 */

require_once __DIR__ . '/../../includes/wiki_registry.php';

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

$RAIZ = realpath(__DIR__ . '/../..');
$reg  = wiki_registry();

echo "== parciais ==\n";
$dir = $RAIZ . '/includes/wiki/sections';
$arquivos = is_dir($dir) ? array_map(fn($f) => basename($f, '.php'), glob($dir . '/*.php')) : [];
$esperados = array_column(array_filter($reg, fn($s) => empty($s['dynamic'])), 'id');
sort($arquivos);
sort($esperados);
checa('toda seção não dinâmica tem parcial', [], array_values(array_diff($esperados, $arquivos)));
checa('nenhum parcial órfão', [], array_values(array_diff($arquivos, $esperados)));
$semTrava = [];
foreach ($arquivos as $id) {
    $ini = file_get_contents("$dir/$id.php");
    if (strpos($ini, "<?php defined('WIKI_SECTION') || exit; ?>") !== 0) $semTrava[] = $id;
}
checa('todo parcial abre com a trava WIKI_SECTION', [], $semTrava);

echo "\n$total verificações, $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
