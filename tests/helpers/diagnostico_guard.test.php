<?php
/**
 * Guarda de evento de DIAGNÓSTICO (v4.9.9) — casos que precisam continuar valendo.
 *
 * Executa `is_diagnostic_alarm()` de verdade contra um banco, com os códigos
 * exatos que os equipamentos do parque enviam. O que ele protege não é a
 * classificação em si (isso é dado, e muda por `UPDATE`), e sim as **bordas**:
 * o que NÃO pode ser escondido por acidente.
 *
 * 🔴 O caso mais importante é o do bitmask 256. `decodeStandardAlarm()` COMBINA
 * os bits ativos num nome só, então câmera + SOS chega como subtipo 2049. Se
 * alguém um dia classificar a BASE `256` em vez do composto `256-2048`, este
 * teste falha — e é a diferença entre esconder um defeito de câmera e esconder
 * um pedido de socorro.
 *
 * Uso:
 *   DB_NAME=jimi_tracker php tests/helpers/diagnostico_guard.test.php
 * (ou com DB_HOST/DB_USER/DB_PASS apontando para uma cópia)
 */

$raiz = __DIR__ . '/../..';
require_once $raiz . '/config/database.php';
require_once $raiz . '/includes/functions.php';

$db = Database::getInstance()->getConnection();

// [descrição, código base, código composto, msg_class, é diagnóstico?]
$casos = [
    // — Classificados como técnicos —
    ['Upload de Vídeo Concluído (JIMI 105)',            '105',  null,       0, true],
    ['Evento de Modo Repouso (JT/T 1040)',              '1040', null,       1, true],
    ['Evento de Modo Trabalho (JT/T 1041)',             '1041', null,       1, true],
    ['Perda de Sinal de Vídeo (JT/T 257)',              '257',  null,       1, true],
    ['Falha no Armazenamento (JT/T 259)',               '259',  null,       1, true],
    ['Falha de Câmera (JT/T 256, subtipo 2048)',        '256',  '256-2048', 1, true],

    // — Bordas: o que NÃO pode sumir da tela —
    ['🔴 256 com câmera+SOS (2049) segue visível',      '256',  '256-2049', 1, false],
    ['🔴 256 base sozinho segue visível',               '256',  null,       1, false],
    ['DMS: Distração do Motorista (JIMI 143)',          '143',  null,       0, false],
    ['SOS (JIMI 1)',                                    '1',    null,       0, false],
    ['Código fora do catálogo (JT/T 1047) — fail-open', '1047', null,       1, false],
    ['🔴 105 em JT/T é outro alarme (ADR-001)',         '105',  null,       1, false],
];

$falhas = 0;
foreach ($casos as [$desc, $base, $composto, $msgClass, $esperado]) {
    $obtido = is_diagnostic_alarm($db, $base, $composto, $msgClass);
    $ok = ($obtido === $esperado);
    if (!$ok) $falhas++;
    printf("  %s %-50s esperado=%-5s obtido=%s\n",
        $ok ? 'OK ' : 'FALHA', $desc, var_export($esperado, true), var_export($obtido, true));
}

// A coluna tem de existir: sem ela `is_diagnostic_alarm()` devolve false para
// tudo pelo `catch`, e os 6 primeiros casos falhariam — mas um teste que roda
// contra banco sem a migração deve dizer ISSO, não "classificação errada".
try {
    $temColuna = (bool)$db->query(
        "SELECT COUNT(*) FROM information_schema.columns
          WHERE table_schema = DATABASE() AND table_name = 'alarm_types'
            AND column_name = 'is_diagnostic'")->fetchColumn();
} catch (Throwable $e) {
    $temColuna = false;
}
if (!$temColuna) {
    echo "\nABORTADO: coluna alarm_types.is_diagnostic ausente — aplique migration_v4.9.9.sql.\n";
    exit(2);
}

echo $falhas ? "\n$falhas falha(s)\n" : "\nTodos os " . count($casos) . " casos passaram\n";
exit($falhas ? 1 : 0);
