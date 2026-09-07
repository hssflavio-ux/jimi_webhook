<?php
/**
 * Toda migração do diretório precisa estar na lista do `deploy.sh`.
 *
 * 🔴 O DEFEITO QUE ESTE TESTE EXISTE PARA PEGAR, medido em 07/09/2026: as
 * migrações `v4.17.12` e `v4.17.13` estavam em `mysql/` e **não** na lista de
 * `run_migration` do `deploy.sh`. O `deploy.sh` chama uma a uma,
 * explicitamente — o que não está na lista simplesmente não roda.
 *
 * O modo de falhar é o pior possível, e é o que o `CLAUDE.md` já avisava:
 * **deploy verde, coluna inexistente**. Em produção, `sms_commands.eventos_raw`
 * não existia e cada chamada do `/pushsms` logava
 * `SMS: evento cru não gravado {erro: Unknown column 'eventos_raw'}` — engolido
 * por um `try/catch`, com o webhook respondendo 200. O recurso ficou morto em
 * silêncio desde que subiu, e só apareceu quando alguém foi olhar o log.
 *
 * ⚠️ Confere os DOIS SENTIDOS. Arquivo fora da lista é o defeito acima; entrada
 * na lista sem arquivo é um deploy que aborta em produção, num ponto onde já
 * houve `git pull` — pior ainda.
 *
 * Uso (não precisa de banco):
 *   php tests/helpers/migracoes_no_deploy.test.php
 */

$raiz   = dirname(__DIR__, 2);
$dirSql = $raiz . '/mysql';
$deploy = $raiz . '/scripts/deploy.sh';

$falhas = 0;

if (!is_dir($dirSql) || !is_file($deploy)) {
    fwrite(STDERR, "FALHA: estrutura esperada não encontrada ($dirSql / $deploy)\n");
    exit(1);
}

// ── Migrações que existem no diretório ──────────────────────────────────────
$arquivos = [];
foreach (scandir($dirSql) as $f) {
    if (preg_match('/^migration_v(.+)\.sql$/', $f, $m)) {
        $arquivos[$m[1]] = $f;
    }
}

// 🔴 Vazio NÃO é aprovação: sem migração nenhuma as duas comparações abaixo
// passariam por vacuidade, e o teste viraria decoração exatamente no dia em
// que o caminho do diretório mudasse.
if (!$arquivos) {
    fwrite(STDERR, "FALHA: nenhuma migração encontrada em $dirSql — o teste não teria o que verificar\n");
    exit(1);
}

// ── Migrações que o deploy realmente chama ──────────────────────────────────
$sh = (string)file_get_contents($deploy);
preg_match_all('/run_migration\s+"([^"]+)"\s+"mysql\/migration_v([^"]+)\.sql"/', $sh, $mm, PREG_SET_ORDER);
$naLista = [];
foreach ($mm as $m) {
    $naLista[$m[2]] = $m[1];
    // A versão do 1º argumento (usada no log e no gate de `system_info`) tem de
    // bater com a do NOME do arquivo — divergir aqui faz o deploy pular a
    // migração certa achando que já rodou.
    if ($m[1] !== $m[2]) {
        echo "  FALHA rótulo \"{$m[1]}\" não bate com o arquivo migration_v{$m[2]}.sql\n";
        $falhas++;
    }
}

if (!$naLista) {
    fwrite(STDERR, "FALHA: nenhum run_migration encontrado em deploy.sh — o padrão de busca mudou?\n");
    exit(1);
}

/** Ordena "4.17.9" antes de "4.17.12" (numérico por segmento, não lexical). */
$ordena = function (array $vs): array {
    usort($vs, static function ($a, $b) {
        $pa = array_map('intval', explode('.', $a));
        $pb = array_map('intval', explode('.', $b));
        for ($i = 0; $i < 4; $i++) {
            $d = ($pa[$i] ?? 0) <=> ($pb[$i] ?? 0);
            if ($d !== 0) return $d;
        }
        return 0;
    });
    return $vs;
};

echo "migrações em mysql/: " . count($arquivos) . "  |  chamadas em deploy.sh: " . count($naLista) . "\n\n";

// ── 1) No diretório e fora da lista → nunca roda ────────────────────────────
$foraDaLista = $ordena(array_values(array_diff(array_keys($arquivos), array_keys($naLista))));
if ($foraDaLista) {
    $falhas += count($foraDaLista);
    echo "  FALHA migração no diretório e FORA da lista do deploy (não vai rodar):\n";
    foreach ($foraDaLista as $v) echo "          migration_v{$v}.sql\n";
} else {
    echo "  OK   toda migração do diretório está na lista do deploy\n";
}

// ── 2) Na lista e sem arquivo → deploy aborta em produção ───────────────────
$semArquivo = $ordena(array_values(array_diff(array_keys($naLista), array_keys($arquivos))));
if ($semArquivo) {
    $falhas += count($semArquivo);
    echo "  FALHA entrada na lista SEM arquivo correspondente (deploy aborta):\n";
    foreach ($semArquivo as $v) echo "          migration_v{$v}.sql\n";
} else {
    echo "  OK   toda entrada da lista tem arquivo\n";
}

// ── 3) Ordem crescente: o deploy aplica na sequência em que estão escritas ──
$ordemNoArquivo = array_keys($naLista);
if ($ordemNoArquivo !== $ordena($ordemNoArquivo)) {
    $falhas++;
    echo "  FALHA a lista não está em ordem crescente de versão — o deploy aplica na ordem escrita\n";
} else {
    echo "  OK   a lista está em ordem crescente de versão\n";
}

printf("\n%s\n", $falhas === 0
    ? 'TUDO OK — nenhuma migração órfã nos dois sentidos'
    : "FALHOU ({$falhas})");
exit($falhas === 0 ? 0 : 1);
