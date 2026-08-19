<?php
/**
 * JIMI Webhook System — Reenvio de vídeo de alarme v4.9.31
 *
 * Pede de novo o vídeo de um alarme que ficou sem ele, e religa o arquivo ao
 * alarme quando a câmera termina de subir.
 *
 * ── POR QUE PRECISA RELIGAR ─────────────────────────────────────────────────
 * O arquivo reenviado volta com OUTRO NOME. `alarms.file_url` continua com o
 * nome antigo, que não existe no disco, então o vídeo chega ao servidor e não
 * aparece no relatório:
 *
 *     gravado no alarme:  EVENT_..._00000000_2026_08_18_16_16_57_I_14.ts
 *     chegou pelo EVIDEO: EVENT_..._00000001_2026_08_18_16_16_26_I_02.ts
 *
 * 🔴 Casar por "alarme mais próximo no tempo" cola vídeo no alarme ERRADO — o
 * DMS dispara várias vezes no mesmo minuto e nada no nome diz de qual evento o
 * clipe é. Como somos NÓS que pedimos, sabemos para qual alarme: o pedido fica
 * registrado em `alarm_video_requests` e o casamento é contra ELE.
 *
 * ── ESCOLHA DO COMANDO: POR TENTATIVA, NÃO POR VERSÃO ───────────────────────
 * `EVIDEO` dá o vídeo bom (alta qualidade, do cartão), mas nem todo firmware o
 * aceita. Medido em 19/08/2026 nas duas câmeras de produção:
 *
 *     JC400AD V1.8.1.2_250904 → EVIDEO:OK!
 *     JC400AD V1.8.0.9_250807 → "EVIDEO,command length error. support
 *                                length [3, 4]" — mesmo enviando 4 elementos,
 *                                e em TODAS as formas testadas
 *
 * `devices.firmware_version` está NULL em 100% da base, então não há por onde
 * decidir antes. E não precisa: a resposta do equipamento é SÍNCRONA, então dá
 * para tentar o `EVIDEO` e cair no `HVIDEO` quando ele recusa. Adapta-se a
 * firmware que ainda não existe, sem tabela para manter.
 *
 * ⚠️ `EVIDEO` vai SEM o parâmetro de duração de propósito: com ele, o clipe
 * volta deslocado (−31 s medidos); sem ele, o nome bate exatamente com o
 * instante pedido.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/iothub_command.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/functions.php';   // is_diagnostic_alarm()

/**
 * Janela de casamento, em segundos, relativa ao instante pedido.
 *
 * O timestamp do NOME é o INÍCIO do clipe, então o desvio é sempre para trás —
 * daí a assimetria. Medido: chegada natural e `EVIDEO` sem duração dão 0 s;
 * `EVIDEO` com duração dá −31 s; `HVIDEO` devolve o bloco de 1 minuto que
 * contém o instante, o que chega a −59 s.
 */
const AVR_JANELA_ANTES  = 90;
const AVR_JANELA_DEPOIS = 15;

/**
 * Timestamp embutido no nome do arquivo, na hora LOCAL da câmera.
 *
 * `EVENT_<imei>_<seq>_AAAA_MM_DD_HH_MM_SS_<cam>_<n>.<ext>`
 *
 * @param string $nome Nome do arquivo (com ou sem caminho)
 * @returns int|null Epoch, ou null quando o nome não segue o padrão
 */
function avr_ts_do_nome(string $nome): ?int
{
    if (!preg_match('/_(\d{4})_(\d{2})_(\d{2})_(\d{2})_(\d{2})_(\d{2})_/', basename($nome), $m)) {
        return null;
    }
    return mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * Pede à câmera o vídeo de um alarme, e registra o pedido.
 *
 * @param int      $alarmId Alarme que ficou sem vídeo
 * @param int|null $userId  Quem pediu (para auditoria)
 * @returns array{ok:bool, msg:string, comando?:string, resposta?:string}
 */
function request_alarm_video(int $alarmId, ?int $userId = null): array
{
    $db = Database::getInstance()->getConnection();

    // `alarm_time` é UTC; a câmera nomeia o arquivo na hora LOCAL dela.
    $st = $db->prepare("
        SELECT a.id, a.imei, a.file_url,
               DATE_FORMAT(CONVERT_TZ(a.alarm_time, '+00:00', '-03:00'), '%Y-%m-%d %H:%i:%s') AS local_ts
          FROM alarms a
         WHERE a.id = :id
    ");
    $st->execute([':id' => $alarmId]);
    $al = $st->fetch(PDO::FETCH_ASSOC);
    if (!$al) {
        return ['ok' => false, 'msg' => 'Alarme não encontrado.'];
    }
    if (media_available($al['file_url'])) {
        return ['ok' => false, 'msg' => 'Este alarme já tem o vídeo no servidor.'];
    }

    // Um pedido pendente por alarme: reenviar em cima do outro só duplicaria
    // comando na câmera e gastaria franquia do SIM.
    $st = $db->prepare("SELECT id FROM alarm_video_requests WHERE alarm_id = :id AND status = 'pendente'");
    $st->execute([':id' => $alarmId]);
    if ($st->fetchColumn()) {
        return ['ok' => false, 'msg' => 'Já há um pedido em andamento para este alarme.'];
    }

    // Câmera: o sufixo `_I_`/`_F_` do nome antigo diz qual gravou o evento.
    // 2 = interna, 1 = frontal. Sem pista, a interna, que é a do DMS.
    $antigo = basename((string)media_pick($al['file_url']));
    $cam    = (stripos($antigo, '_F_') !== false) ? 1 : 2;

    // EVIDEO primeiro (vídeo bom); HVIDEO quando o firmware recusa.
    $tentativas = [
        'EVIDEO,' . $al['local_ts'] . ',' . $cam,
        'HVIDEO,' . str_replace([' ', '-', ':'], '_', $al['local_ts']) . ',' . $cam,
    ];

    $ultima = '';
    foreach ($tentativas as $cmd) {
        $env    = iothub_send_instruct($al['imei'], 128, $cmd, 1, 'reenvio');
        $ultima = trim((string)($env['result_msg'] ?? ''));

        // O equipamento responde de forma síncrona: `EVIDEO:OK!` ou o motivo da
        // recusa. Erro de sintaxe/parâmetro = firmware não suporta esta forma;
        // qualquer outra falha (offline, timeout) NÃO se resolve trocando de
        // comando, então não insiste.
        if (stripos($ultima, ':OK') !== false) {
            $ins = $db->prepare("
                INSERT INTO alarm_video_requests
                    (alarm_id, imei, requested_for, command, status, device_reply, requested_by)
                VALUES (:aid, :imei, :ts, :cmd, 'pendente', :reply, :uid)
            ");
            $ins->execute([
                ':aid'   => $alarmId,
                ':imei'  => $al['imei'],
                ':ts'    => $al['local_ts'],
                ':cmd'   => $cmd,
                ':reply' => mb_substr($ultima, 0, 255),
                ':uid'   => $userId,
            ]);
            return ['ok' => true, 'msg' => 'Vídeo solicitado. Ele aparece aqui quando a câmera terminar de enviar.',
                    'comando' => $cmd, 'resposta' => $ultima];
        }
        if (!preg_match('/(length error|parameter [A-Z] error|command error)/i', $ultima)) {
            break;  // não é incompatibilidade de firmware — insistir não ajuda
        }
    }

    $db->prepare("
        INSERT INTO alarm_video_requests
            (alarm_id, imei, requested_for, command, status, device_reply, requested_by)
        VALUES (:aid, :imei, :ts, :cmd, 'recusado', :reply, :uid)
    ")->execute([
        ':aid'   => $alarmId,
        ':imei'  => $al['imei'],
        ':ts'    => $al['local_ts'],
        ':cmd'   => $tentativas[0],
        ':reply' => mb_substr($ultima, 0, 255),
        ':uid'   => $userId,
    ]);
    return ['ok' => false, 'msg' => 'A câmera recusou o pedido: ' . ($ultima ?: 'sem resposta.'),
            'resposta' => $ultima];
}

/**
 * Religa ao alarme um arquivo que acabou de chegar.
 *
 * Chamado quando o equipamento avisa que terminou de subir (o evento
 * `105 — Upload de Vídeo Concluído` traz o nome do arquivo).
 *
 * @param string $imei     Equipamento que subiu
 * @param string $fileName Nome do arquivo anunciado
 * @returns int|null `alarms.id` religado, ou null quando não havia pedido
 */
function match_pending_video(string $imei, string $fileName): ?int
{
    $arquivo = basename(trim($fileName));
    $ts      = avr_ts_do_nome($arquivo);
    if ($arquivo === '' || $ts === null) {
        return null;
    }

    $db = Database::getInstance()->getConnection();

    // O pedido cujo instante cai na janela, o mais próximo primeiro. A janela é
    // relativa ao PEDIDO: arquivo entre (pedido − 90 s) e (pedido + 15 s).
    $st = $db->prepare("
        SELECT id, alarm_id, requested_for,
               ABS(TIMESTAMPDIFF(SECOND, requested_for, :ts1)) AS dist
          FROM alarm_video_requests
         WHERE imei = :imei
           AND status = 'pendente'
           AND :ts2 BETWEEN requested_for - INTERVAL :antes SECOND
                        AND requested_for + INTERVAL :depois SECOND
         ORDER BY dist ASC
         LIMIT 1
    ");
    $quando = date('Y-m-d H:i:s', $ts);
    $st->execute([
        ':ts1'    => $quando,
        ':ts2'    => $quando,
        ':imei'   => $imei,
        ':antes'  => AVR_JANELA_ANTES,
        ':depois' => AVR_JANELA_DEPOIS,
    ]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!$req) {
        return null;
    }

    // 🔴 Não roubar o vídeo de outro alarme. Se este arquivo já é o anexo de
    // algum alarme, ele é a chegada NORMAL daquele evento — não a resposta ao
    // nosso pedido. Sem esta guarda, um alarme novo que caísse na janela de um
    // pedido pendente teria o vídeo religado no alarme errado.
    //
    // ⚠️ EVENTO DE DIAGNÓSTICO NÃO É DONO DE VÍDEO, e ignorar isso desliga o
    // religamento inteiro. Quem avisa o fim do upload é o `105 — Upload de
    // Vídeo Concluído`, e essa linha é gravada com o MESMO nome de arquivo,
    // logo antes desta função rodar. Sem excluir os diagnósticos, a guarda
    // encontrava o próprio 105 e recusava TODOS os casamentos — medido em
    // produção em 19/08/2026: arquivo no disco, pedido eternamente `pendente`.
    $donos = $db->prepare("
        SELECT id, alarm_type, alarm_subtype, msg_class
          FROM alarms
         WHERE file_url LIKE :like AND id <> :aid
         LIMIT 20
    ");
    $donos->execute([':like' => '%' . $arquivo . '%', ':aid' => (int)$req['alarm_id']]);
    foreach ($donos->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $composto = ($d['alarm_subtype'] !== null && $d['alarm_subtype'] !== '')
            ? $d['alarm_type'] . '-' . $d['alarm_subtype'] : null;
        if (!is_diagnostic_alarm($db, (string)$d['alarm_type'], $composto, (int)$d['msg_class'])) {
            return null;   // alarme de verdade já usa este arquivo
        }
    }

    $db->prepare("UPDATE alarms SET file_url = :f, file_type = 'video' WHERE id = :id")
       ->execute([':f' => $arquivo, ':id' => (int)$req['alarm_id']]);

    $db->prepare("
        UPDATE alarm_video_requests
           SET status = 'atendido', matched_file = :f, matched_at = UTC_TIMESTAMP()
         WHERE id = :id
    ")->execute([':f' => $arquivo, ':id' => (int)$req['id']]);

    return (int)$req['alarm_id'];
}
