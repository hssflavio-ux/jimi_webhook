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
    // 1047 era o "fora do catálogo" deste teste até a v4.9.10 cadastrá-lo como
    // Capotamento. Ficou como caso de alarme REAL (acidente nunca é
    // diagnóstico), e o fail-open passou a usar um código que não existe mesmo.
    ['Capotamento (JT/T 1047) — acidente, nunca diagnóstico', '1047', null,  1, false],
    ['Código fora do catálogo (JT/T 9999) — fail-open', '9999', null,       1, false],
    ['Curva Brusca (JIMI 146)',                         '146',  null,       0, false],
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

// ── v4.17.0: a colisão de NÚMERO entre JIMI e JT/T ─────────────────────────
//
// 🔴 O espaço de códigos JIMI vai até 262 e o JT/T do catálogo começa em 256:
// eles se cruzam. Depois do cadastro completo há um caso real —
//   JIMI 262 = Fim de Movimento
//   JT/T 262 = Comportamento de Condução Irregular
// As duas linhas convivem porque a chave é (alarm_code, protocol) e quem
// desempata na leitura é o `msg_class` gravado na chegada. Este teste existe
// porque a próxima pessoa que olhar a tabela vai ver "262 duplicado" e sentir
// vontade de apagar um — e o sintoma de apagar seria o alarme de um protocolo
// aparecer com o nome do outro, sem erro nenhum.
echo "\n── Colisão de número JIMI x JT/T (v4.17.0) ──\n";
$colisao = [
    // [código, msg_class, nome esperado]
    ['262', 0, 'Fim de Movimento'],
    ['262', 1, 'Comportamento de Condução Irregular'],
];
$stmt = $db->prepare("SELECT alarm_name_pt FROM alarm_types
                       WHERE alarm_code = :c AND protocol = IF(:m = 1, 'JTT', 'JIMI')");
foreach ($colisao as [$cod, $mc, $esperado]) {
    $stmt->execute([':c' => $cod, ':m' => $mc]);
    $obtido = $stmt->fetchColumn();
    $ok = ($obtido === $esperado);
    if (!$ok) $falhas++;
    printf("  %s %-50s esperado=%-38s obtido=%s\n", $ok ? 'OK ' : 'FALHA',
           "código $cod com msg_class=$mc", $esperado, var_export($obtido, true));
}

// E o inverso: nenhum OUTRO número pode ter caído em cima do JT/T sem que
// alguém tenha pensado nisso. A lista é o retrato de hoje; crescer sem revisão
// é o que se quer pegar.
$dupl = $db->query("SELECT alarm_code FROM alarm_types GROUP BY alarm_code
                     HAVING COUNT(DISTINCT protocol) > 1 ORDER BY CAST(alarm_code AS UNSIGNED)")
           ->fetchAll(PDO::FETCH_COLUMN);
$ok = ($dupl === ['262']);
if (!$ok) $falhas++;
printf("  %s %-50s esperado=%-38s obtido=%s\n", $ok ? 'OK ' : 'FALHA',
       '🔴 só o 262 existe nos dois protocolos', "['262']", '[' . implode(',', $dupl) . ']');

echo $falhas ? "\n$falhas falha(s)\n" : "\nTodos os " . (count($casos) + count($colisao) + 1) . " casos passaram\n";
exit($falhas ? 1 : 0);
