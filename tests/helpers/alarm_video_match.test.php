<?php
/**
 * Casamento do vídeo REENVIADO com o alarme certo (v4.9.31).
 *
 * O que este teste protege, em ordem de importância:
 *
 *  1. 🔴 **Não colar vídeo no alarme errado.** O DMS dispara várias vezes no
 *     mesmo minuto e nada no nome do arquivo diz de qual evento ele é. Por isso
 *     o casamento é contra um PEDIDO registrado, não contra "o alarme mais
 *     próximo", e um arquivo que já é anexo de outro alarme é recusado.
 *  2. A JANELA (−90 s a +15 s) medida em câmera real: o timestamp do nome é o
 *     INÍCIO do clipe, então o desvio é sempre para trás.
 *
 * Precisa de banco (usa a tabela `alarm_video_requests` e `alarms`):
 *   php tests/helpers/alarm_video_match.test.php
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/alarm_video_request.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-58s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

$db   = Database::getInstance()->getConnection();
$IMEI = '869911100000001';
$BASE = '2026-08-18 16:16:57';   // hora local da câmera

/** Nome no padrão da câmera, com o timestamp deslocado em $offset segundos. */
function nomeCom(string $imei, string $base, int $offset, string $seq = '00000001'): string {
    return 'EVENT_' . $imei . '_' . $seq . '_'
         . date('Y_m_d_H_i_s', strtotime($base) + $offset) . '_I_02.ts';
}

/** Cria um alarme de teste sem vídeo e devolve o id. */
function novoAlarme(PDO $db, string $imei, string $baseLocal, string $fileUrl = ''): int {
    // alarm_time é UTC; o nome do arquivo usa a hora local (BRT = UTC−3).
    $utc = date('Y-m-d H:i:s', strtotime($baseLocal) + 3 * 3600);
    $st = $db->prepare("INSERT INTO alarms (imei, alarm_type, alarm_name, alarm_time, msg_class, file_url)
                        VALUES (:i, 999, 'TESTE reenvio', :t, 0, :f)");
    $st->execute([':i' => $imei, ':t' => $utc, ':f' => $fileUrl]);
    return (int)$db->lastInsertId();
}

function limpa(PDO $db, string $imei): void {
    $db->prepare("DELETE FROM alarm_video_requests WHERE imei = ?")->execute([$imei]);
    $db->prepare("DELETE FROM alarms WHERE imei = ?")->execute([$imei]);
}

function pedido(PDO $db, int $alarmId, string $imei, string $quando): int {
    $db->prepare("INSERT INTO alarm_video_requests (alarm_id, imei, requested_for, command, status)
                  VALUES (:a, :i, :t, 'EVIDEO,teste,2', 'pendente')")
       ->execute([':a' => $alarmId, ':i' => $imei, ':t' => $quando]);
    return (int)$db->lastInsertId();
}

limpa($db, $IMEI);

echo "\n── Timestamp embutido no nome ──\n";
checa('lê o instante do nome do arquivo',
      strtotime('2026-08-18 16:16:57'), avr_ts_do_nome(nomeCom($IMEI, $BASE, 0)));
checa('nome fora do padrão não engana', null, avr_ts_do_nome('qualquer_coisa.ts'));

echo "\n── Janela: −90 s a +15 s (medida em câmera real) ──\n";
foreach ([0 => true, -31 => true, -44 => true, -90 => true, -91 => false,
          15 => true, 16 => false] as $off => $deveCasar) {
    limpa($db, $IMEI);
    $aid = novoAlarme($db, $IMEI, $BASE);
    pedido($db, $aid, $IMEI, $BASE);
    $r = match_pending_video($IMEI, nomeCom($IMEI, $BASE, (int)$off));
    checa(sprintf('delta %+4ds %s', $off, $deveCasar ? '→ casa' : '→ NÃO casa'),
          $deveCasar ? $aid : null, $r);
}

echo "\n── Sem pedido registrado, nada é religado ──\n";
limpa($db, $IMEI);
$aid = novoAlarme($db, $IMEI, $BASE);
checa('🔴 arquivo que chega sem pedido não vira anexo de ninguém',
      null, match_pending_video($IMEI, nomeCom($IMEI, $BASE, 0)));

echo "\n── Não roubar o vídeo de outro alarme ──\n";
limpa($db, $IMEI);
$alvo  = novoAlarme($db, $IMEI, $BASE);
pedido($db, $alvo, $IMEI, $BASE);
$nome  = nomeCom($IMEI, $BASE, -10);
$outro = novoAlarme($db, $IMEI, $BASE, $nome);   // já é anexo de OUTRO alarme
checa('🔴 arquivo que já é anexo de outro alarme é recusado',
      null, match_pending_video($IMEI, $nome));

echo "\n── O pedido é fechado ao casar ──\n";
limpa($db, $IMEI);
$aid = novoAlarme($db, $IMEI, $BASE);
$pid = pedido($db, $aid, $IMEI, $BASE);
$nome = nomeCom($IMEI, $BASE, -31);
checa('casa e devolve o alarme', $aid, match_pending_video($IMEI, $nome));

$st = $db->prepare("SELECT status, matched_file FROM alarm_video_requests WHERE id = ?");
$st->execute([$pid]);
$req = $st->fetch(PDO::FETCH_ASSOC);
checa('pedido marcado como atendido', 'atendido', $req['status']);
checa('arquivo casado registrado', $nome, $req['matched_file']);

$st = $db->prepare("SELECT file_url FROM alarms WHERE id = ?");
$st->execute([$aid]);
checa('🔴 o alarme passou a apontar para o arquivo NOVO', $nome, $st->fetchColumn());

checa('o mesmo arquivo não casa duas vezes', null, match_pending_video($IMEI, $nome));

limpa($db, $IMEI);

printf("\n%s — %d de %d checagens passaram\n",
    $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
