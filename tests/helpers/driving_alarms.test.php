<?php
/**
 * Dirigibilidade (v4.21.0) — guarda da classificação e da fiação das telas.
 *
 * Não precisa de banco: lê a migração e os fontes. O que protege é a LISTA
 * aprovada pelo dono do produto em 14/09/2026 (spec
 * docs/superpowers/specs/2026-09-14-rastreadores-dirigibilidade-design.md §3)
 * e as bordas — nenhum ADAS/DMS de IA pode virar "condução" e perder o vídeo.
 *
 * Uso: php tests/helpers/driving_alarms.test.php
 */

$raiz   = dirname(__DIR__, 2);
$falhas = 0;

function confere(bool $cond, string $desc): void
{
    global $falhas;
    if ($cond) {
        echo "  OK   $desc\n";
    } else {
        $falhas++;
        echo "  FALHA $desc\n";
    }
}

// ── 1) Migração: a lista marcada é exatamente a aprovada ────────────────────
$esperado = [
    'JIMI|144', 'JTT|1024', 'JTT|1042',                              // arrancada
    'JIMI|48', 'JIMI|145', 'JTT|1025', 'JTT|1043',                   // freada
    'JIMI|76', 'JIMI|146', 'JTT|1026', 'JTT|1044',                   // curva
    'JIMI|6', 'JIMI|135', 'JIMI|202', 'JIMI|95', 'JTT|1027',         // velocidade
    'JIMI|44', 'JIMI|147', 'JTT|1029', 'JTT|1046',                   // colisão
    'JIMI|45', 'JIMI|106', 'JIMI|183', 'JTT|1047',                   // capotamento
    'JIMI|55', 'JIMI|75', 'JIMI|78', 'JIMI|79',                      // impacto/inclinação
];
$proibido = [
    'JIMI|77', 'JIMI|116',                                            // fora por decisão
    'JIMI|143', 'JIMI|151', 'JIMI|204', 'JIMI|206', 'JIMI|207', 'JIMI|229', // DMS/ADAS JIMI
    'JTT|264-1', 'JTT|264-3', 'JTT|264-4', 'JTT|265-2',              // DMS/ADAS JT/T
];

$arqMig = $raiz . '/mysql/migration_v4.21.0.sql';
$sql    = is_file($arqMig) ? file_get_contents($arqMig) : '';
confere($sql !== '', 'mysql/migration_v4.21.0.sql existe');

$marcados = [];
if (preg_match('/INSERT INTO tmp_driving[^;]*;/s', $sql, $bloco)) {
    preg_match_all("/\('(JIMI|JTT)',\s*'([0-9-]+)'\)/", $bloco[0], $m, PREG_SET_ORDER);
    foreach ($m as $par) {
        $marcados[] = $par[1] . '|' . $par[2];
    }
}
sort($marcados);
$ordenado = $esperado;
sort($ordenado);
confere($marcados === $ordenado, 'lista marcada = lista aprovada (' . count($esperado) . ' códigos)'
    . ($marcados === $ordenado ? '' : ' — sobra: ' . implode(',', array_diff($marcados, $esperado))
        . ' falta: ' . implode(',', array_diff($esperado, $marcados))));
confere(!array_intersect($marcados, $proibido), 'nenhum código proibido (ADAS/DMS, 77, 116) marcado');
confere(str_contains($sql, "add_column_if_not_exists('alarm_types', 'is_driving'"), 'coluna criada de forma idempotente');
confere(str_contains($sql, 'COLLATE utf8mb4_unicode_ci'), 'tabela temporária com a collation de alarm_types');

$deploy = (string)file_get_contents($raiz . '/scripts/deploy.sh');
confere(str_contains($deploy, 'run_migration "4.21.0" "mysql/migration_v4.21.0.sql"'), 'deploy.sh aplica a v4.21.0');

// ── novas seções entram acima desta linha ──

printf("\n%s\n", $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})");
exit($falhas === 0 ? 0 : 1);
