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

// ── 2) Helpers (includes/functions.php) ─────────────────────────────────────
require_once $raiz . '/includes/functions.php';
foreach (['alarm_types_has_driving_flag', 'alarm_driving_expr', 'device_has_camera_sql',
          'occurrence_no_video_sql', 'is_driving_alarm'] as $fn) {
    confere(function_exists($fn), "$fn() existe");
}
if (function_exists('alarm_driving_expr')) {
    confere(alarm_driving_expr(true) === 'COALESCE(atc.is_driving, atb.is_driving, 0)', 'expr com coluna usa os joins do rótulo');
    confere(alarm_driving_expr(false) === '0', 'expr sem coluna (janela da migração) = 0');
    confere(device_has_camera_sql('d', 'dm') === 'COALESCE(NULLIF(d.camera_count, 0), dm.camera_count, 1) > 0', 'regra de câmera = v4.16.0');
    $semCol = occurrence_no_video_sql(false);
    confere(str_contains($semCol, 'nvd.imei = o.imei') && !str_contains($semCol, 'is_driving'), 'sem coluna: só "sem câmera"');
    $comCol = occurrence_no_video_sql(true, 'oc');
    confere(str_contains($comCol, 'nve.occurrence_id = oc.id') && str_contains($comCol, 'LEFT JOIN alarm_types atc')
        && str_contains($comCol, 'COALESCE(atc.is_driving, atb.is_driving, 0) = 1'), 'com coluna: condução por código dos alarmes agrupados');
}

// ── 3) Telas, rota e menu ───────────────────────────────────────────────────
$router = (string)file_get_contents($raiz . '/handlers/router.php');
confere((bool)preg_match("/'dirigibilidade'\s*=>\s*'rel_dirigibilidade\.php'/", $router), 'router: /relatorios/dirigibilidade');
confere((bool)preg_match("/'rel_dirigibilidade\.php'\s*=>\s*'relatorios'/", $router), 'router: permissão relatorios');
$layout = (string)file_get_contents($raiz . '/web/layout_base.php');
confere(str_contains($layout, "'label' => 'Alertas Videomonitoramento'"), 'menu: rótulo Alertas Videomonitoramento');
confere((bool)preg_match("/'route' => 'rel_dirigibilidade',\s*'label' => 'Alarmes Dirigibilidade',\s*'href' => '\/relatorios\/dirigibilidade'/", $layout), 'menu: Alarmes Dirigibilidade');
$dirig = is_file($raiz . '/handlers/rel_dirigibilidade.php') ? file_get_contents($raiz . '/handlers/rel_dirigibilidade.php') : '';
confere(str_contains($dirig, "\$ALARM_REPORT_MODE = 'driving';") && str_contains($dirig, "require __DIR__ . '/rel_alarmes.php';"), 'rel_dirigibilidade.php só define o modo');
$relAl = (string)file_get_contents($raiz . '/handlers/rel_alarmes.php');
confere(str_contains($relAl, "<?php if (!\$modoDirig): ?><th>Vídeo</th><?php endif; ?>"), 'coluna Vídeo só no modo vídeo');
confere(str_contains($relAl, "<?php if (!\$modoDirig): // vídeo só existe em Videomonitoramento ?>"), 'modal/JS de vídeo só no modo vídeo');

// ── 4) Ocorrências ──────────────────────────────────────────────────────────
$occData = (string)file_get_contents($raiz . '/handlers/ocorrenciasdata.php');
confere(str_contains($occData, 'occurrence_no_video_sql(') && str_contains($occData, "'no_video' =>"), '/ocorrenciasdata devolve no_video');
$occDash = (string)file_get_contents($raiz . '/handlers/ocorrencias_dashboard.php');
confere(str_contains($occDash, 'if (r.no_video)'), 'grade: célula Vídeo respeita no_video');
confere(substr_count($occDash, '$detailNoVideo') >= 6, 'detalhe: mídia, coluna e player condicionados a $detailNoVideo');

// ── 5) Pedido de vídeo (manual, automático, backfill) ───────────────────────
$avr = (string)file_get_contents($raiz . '/includes/alarm_video_request.php');
confere(str_contains($avr, 'AS cams_efetivas') && str_contains($avr, 'is_driving_alarm($db, (string)$al[\'alarm_type\']'), '/solicitarvideo recusa sem câmera e condução');
$eng = (string)file_get_contents($raiz . '/includes/occurrence_engine.php');
confere((bool)preg_match('/\$mediaId === null\s*&& !is_driving_alarm\(\$db, \$alarmType, \$compositeCode/', $eng), 'motor não agenda vídeo de condução');
$vub = (string)file_get_contents($raiz . '/scripts/video_upload_backfill.php');
confere(str_contains($vub, 'AS is_driving') && str_contains($vub, '$totalConducao'), 'backfill ignora condução e conta');

// ── 6) Rastreador fora das telas de câmera ──────────────────────────────────
$vd = (string)file_get_contents($raiz . '/handlers/video_downloads.php');
confere(str_contains($vd, "device_has_camera_sql('d', 'dm')"), 'Downloads: filtro sem rastreador');
$mr = (string)file_get_contents($raiz . '/handlers/mapa_risco.php');
confere(str_contains($mr, 'di.removed_at IS NULL') && str_contains($mr, "device_has_camera_sql('d', 'dm')"), 'Mapa de Risco: seletor sem veículo com rastreador');
$rb = (string)file_get_contents($raiz . '/scripts/risk_builder.php');
confere(str_contains($rb, 'WHERE d.imei = g.imei AND NOT ('), 'risk_builder: exposição sem ponto de rastreador');

// ── novas seções entram acima desta linha ──

printf("\n%s\n", $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})");
exit($falhas === 0 ? 0 : 1);
