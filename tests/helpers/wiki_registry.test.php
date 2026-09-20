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

/** Corpo de cada chamada `fn( ... )` em $src, respeitando parênteses aninhados. */
function chamadas(string $src, string $fn): array {
    $out = [];
    $off = 0;
    while (preg_match('/\b' . $fn . '\(/', $src, $m, PREG_OFFSET_CAPTURE, $off)) {
        $ini  = $m[0][1] + strlen($m[0][0]);
        $prof = 1;
        $i    = $ini;
        $n    = strlen($src);
        while ($i < $n && $prof > 0) {
            if ($src[$i] === '(') $prof++;
            elseif ($src[$i] === ')') $prof--;
            $i++;
        }
        $out[] = substr($src, $ini, $i - $ini - 1);
        $off = $i;
    }
    return $out;
}

/** @returns array<string,string[]> tela => ações (sem `view`) exigidas em handlers/*.php */
function acoes_exigidas(string $raiz): array {
    $map = [];
    foreach (glob($raiz . '/handlers/*.php') as $arq) {
        $src = (string)file_get_contents($arq);
        foreach (['require_permission', 'can'] as $fn) {
            foreach (chamadas($src, $fn) as $arg) {
                if (!preg_match("/^\s*'([a-z_\-]+)'\s*(?:,(.*))?$/s", $arg, $m)) continue;
                preg_match_all("/'(create|edit|delete|export)'/", $m[2] ?? '', $a);
                foreach ($a[1] as $acao) $map[$m[1]][$acao] = true;
            }
        }
    }
    return array_map(fn($v) => array_keys($v), $map);
}

function tem_require_admin(string $raiz, string $handler): bool {
    return (bool)preg_match('/^require_admin\(\);/m', (string)@file_get_contents("$raiz/handlers/$handler"));
}

echo "\n== toda tela da matriz tem seção na wiki ==\n";
$matriz = (string)file_get_contents($RAIZ . '/handlers/grupos_permissao.php');
preg_match('/\$screens\s*=\s*\[(.*?)\n\];/s', $matriz, $blk);
preg_match_all("/^\s*'([a-z_\-]+)'\s*=>/m", $blk[1] ?? '', $ks);
$telas = $ks[1];
checa('a matriz foi lida (sanidade)', true, count($telas) > 25);

// Tela sem seção PRECISA constar aqui com o motivo escrito. Remover a entrada
// quando a seção nascer — o teste reclama de exceção obsoleta.
$EXCECOES = [
    'wiki'                => 'é a própria Central de Ajuda',
    'config-dispositivos' => 'aberta pela ficha do veículo (aba Configurações); documentada dentro de Ativos',
    'config-parametros'   => 'fora do menu desde a v4.13.10, junto com Parâmetros',
    // Etapa 2 (v4.22.1) — remover cada linha ao criar a seção:
    'comandos-sms'        => 'PENDENTE etapa 2',
    'config-sms'          => 'PENDENTE etapa 2',
    'configuracoes-ia'    => 'PENDENTE etapa 2',
];
$cobertas = array_unique(array_filter(array_column($reg, 'screen')));
checa('toda tela da matriz tem seção (ou exceção escrita)', [],
    array_values(array_diff($telas, $cobertas, array_keys($EXCECOES))));
checa('nenhuma exceção obsoleta (a tela já tem seção)', [], array_values(array_intersect(array_keys($EXCECOES), $cobertas)));
checa('toda seção aponta para uma tela que existe na matriz', [], array_values(array_diff($cobertas, $telas)));

echo "== admin_only bate com o handler ==\n";
$divergem = [];
foreach ($reg as $s) {
    if ($s['handler'] === null) continue;
    if ($s['admin_only'] !== tem_require_admin($RAIZ, $s['handler'])) $divergem[] = $s['id'];
}
checa('admin_only == linha require_admin(); do handler', [], $divergem);

// Auditoria é exclusiva do administrador (v4.22.1): a tela-mãe é conferida acima
// pelo registro, mas os 3 relatórios-irmãos não têm seção própria — são
// alcançáveis por URL direta, então cada um precisa da mesma trava.
$irmaosSemTrava = [];
foreach (['auditoria_negados.php', 'auditoria_cadastro.php', 'auditoria_login.php'] as $irmao) {
    if (!tem_require_admin($RAIZ, $irmao)) $irmaosSemTrava[] = $irmao;
}
checa('relatórios-irmãos da Auditoria têm require_admin();', [], $irmaosSemTrava);

echo "== ações batem com o que os handlers exigem ==\n";
$exigidas = acoes_exigidas($RAIZ);
$divergem = [];
foreach (array_unique(array_filter(array_column($reg, 'screen'))) as $tela) {
    $declaradas = [];
    foreach ($reg as $s) if ($s['screen'] === $tela) $declaradas = array_merge($declaradas, $s['actions']);
    $declaradas = array_values(array_unique($declaradas));
    $reais = $exigidas[$tela] ?? [];
    sort($declaradas);
    sort($reais);
    if ($declaradas !== $reais) $divergem[$tela] = ['registro' => $declaradas, 'handlers' => $reais];
}
checa('actions do registro == ações exigidas por tela', [], $divergem);

echo "== a seção oculta é só a que o menu esconde ==\n";
checa('únicas seções ocultas', ['parametros'], array_column(array_filter($reg, fn($s) => $s['hidden']), 'id'));

echo "\n$total verificações, $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
