<?php
/**
 * JIMI Webhook System — Worker de leitura de parâmetros (v4.9.13)
 *
 * Lê a configuração das câmeras JT/T que nunca foram lidas (ou cuja leitura
 * envelheceu) disparando `33028`. É a "leitura na primeira conexão" do
 * `PROJETO_PARAMETROS.md` §6.
 *
 * Uso (cron, a cada 5 min — ver scripts/crontab-setup.sh):
 *   php scripts/param_sync_worker.php [limite]
 *
 * 🔴 POR QUE ISTO É UM CRON E NÃO UM GATILHO DENTRO DO WEBHOOK
 *
 * Seria tentador disparar a leitura no `pushgps`/`pushhb` quando o device
 * aparece pela primeira vez. Não: o handler já devolveu 200 e está processando
 * em background, e abrir uma chamada HTTP ao IoT Hub ali acopla o tráfego de
 * TODOS os devices à disponibilidade do hub. Numa frota que reconecta junto
 * (queda de energia, virada de turno) isso vira tempestade — dezenas de
 * comandos simultâneos, cada um segurando até 35 s. O cron dá enfileiramento,
 * teto por rodada e backoff de graça.
 *
 * ⚠️ SÓ JT/T. Os comandos 33027/33028/33030 são da seção 2 da doc (msgClass=1);
 * câmera JIMI não os entende (ADR-001).
 *
 * ⚠️ `_code:600` NÃO É ERRO. Medido em 12/08/2026: o JC182 recusou o comando
 * síncrono com `last_communication` de segundos antes e o comando virou fila
 * offline. Frescor de comunicação não significa que o device aceita comando —
 * tratar isso como falha faria o worker desistir de metade da frota.
 */

$raiz = dirname(__DIR__);
require_once $raiz . '/config/database.php';
require_once $raiz . '/core/Logger.php';
require_once $raiz . '/includes/device_params.php';
require_once $raiz . '/includes/iothub_command.php';

const PARAM_SYNC_LIMITE_PADRAO = 20;   // teto por rodada
const PARAM_SYNC_REVALIDA_DIAS = 30;   // releitura periódica
const PARAM_SYNC_MAX_TENTATIVAS = 5;   // depois disso, para e deixa visível

$limite = isset($argv[1]) ? max(1, (int)$argv[1]) : PARAM_SYNC_LIMITE_PADRAO;

$db = Database::getInstance()->getConnection();

/**
 * Próxima janela de tentativa, em backoff exponencial.
 *
 * A curva é deliberadamente diferente por motivo. "Device busy" é transitório —
 * há um comando anterior em curso, e reenviar na hora recebe a MESMA recusa
 * (observado no homolog), então 15 min bastam. Já device offline pode ficar
 * dias assim, e insistir de hora em hora só gasta o hub.
 *
 * @param  int    $tentativas Quantas já falharam (após incrementar)
 * @param  string $motivo     'busy' | 'offline' | 'erro'
 * @returns string            Data/hora UTC para `params_sync_next`
 */
function proxima_tentativa(int $tentativas, string $motivo): string
{
    $minutos = $motivo === 'busy'
        ? 15
        : min(24 * 60, 60 * (2 ** max(0, $tentativas - 1)));   // 1h, 2h, 4h… teto 24h
    return (new DateTime('now', new DateTimeZone('UTC')))
        ->add(new DateInterval('PT' . $minutos . 'M'))
        ->format('Y-m-d H:i:s');
}

// ── Fila ─────────────────────────────────────────────────────────────────────
//
// `last_communication` entra como ORDENAÇÃO, não como filtro: um device que não
// fala há meses ainda merece uma tentativa (o comando fica em fila offline e é
// entregue quando ele voltar), só não na frente de quem está transmitindo agora.
$sql = "
    SELECT d.imei, d.device_name, d.params_sync_tries
      FROM devices d
      JOIN device_models dm ON dm.id = d.device_model_id
     WHERE d.is_active = 1
       AND dm.protocol = 'JTT'
       AND (d.params_synced_at IS NULL
            OR d.params_synced_at < DATE_SUB(NOW(), INTERVAL :dias DAY))
       AND (d.params_sync_next IS NULL OR d.params_sync_next <= NOW())
       AND d.params_sync_tries < :maxt
     ORDER BY d.last_communication DESC
     LIMIT {$limite}";

$stmt = $db->prepare($sql);
$stmt->execute([':dias' => PARAM_SYNC_REVALIDA_DIAS, ':maxt' => PARAM_SYNC_MAX_TENTATIVAS]);
$fila = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$fila) {
    echo "[param_sync] nada a fazer\n";
    exit(0);
}

echo '[param_sync] ' . count($fila) . " equipamento(s) na fila\n";

$lidos = $enfileirados = $falhas = 0;

foreach ($fila as $dev) {
    $imei    = $dev['imei'];
    $rotulo  = $dev['device_name'] ?: $imei;
    $tries   = (int)$dev['params_sync_tries'];

    // cmdContent do 33028 é VAZIO por especificação — build_param_cmd_content()
    // é quem sabe disso, e é o mesmo que a tela usa.
    $envio = iothub_send_instruct($imei, 33028, build_param_cmd_content(33028, []), 0, 'paramsync');

    // Toda tentativa vira linha em `commands`: sem isso, um comando disparado
    // pelo cron seria invisível na tela de Comandos e ninguém entenderia de
    // onde veio a resposta que chega depois pelo callback.
    $ins = $db->prepare("
        INSERT INTO commands (imei, command_content, command_type, status, operator,
                              api_type, pro_no, request_id, server_flag_id,
                              response_payload, response_time, created_at, updated_at)
        VALUES (:imei, '(leitura automática de parâmetros)', 'query', :status, 'param_sync_worker',
                'jtt_33028', 33028, :rid, '0', :resp, :rtime, NOW(), NOW())");
    $ins->execute([
        ':imei'   => $imei,
        ':status' => $envio['status'],
        ':rid'    => $envio['request_id'],
        ':resp'   => $envio['raw'],
        ':rtime'  => $envio['status'] === 'executed' ? gmdate('Y-m-d H:i:s') : null,
    ]);
    $commandId = (int)$db->lastInsertId();

    // ── Device respondeu na hora ────────────────────────────────────────────
    if ($envio['status'] === 'executed' && !empty($envio['content'])) {
        $parsed = parse_param_content((string)$envio['content']);
        if ($parsed['ok']) {
            $n = upsert_device_params($db, $imei, $parsed, 33028, '33028', $commandId);
            $db->prepare("UPDATE devices SET params_synced_at = NOW(),
                                 params_sync_tries = 0, params_sync_next = NULL
                           WHERE imei = :imei")->execute([':imei' => $imei]);
            $lidos++;
            echo "  ✓ {$rotulo}: {$n} parâmetro(s)\n";
            Logger::info('param_sync: leitura concluída', [
                'imei' => $imei, 'gravados' => $n, 'param_count' => $parsed['param_count'],
            ]);
        } else {
            // Resposta veio e não parseou — isso é defeito, não indisponibilidade.
            $falhas++;
            $db->prepare("UPDATE devices SET params_sync_tries = params_sync_tries + 1,
                                 params_sync_next = :prox WHERE imei = :imei")
               ->execute([':prox' => proxima_tentativa($tries + 1, 'erro'), ':imei' => $imei]);
            echo "  ✗ {$rotulo}: resposta não parseou ({$parsed['erro']})\n";
            Logger::error('param_sync: _content não parseou', [
                'imei' => $imei, 'erro' => $parsed['erro'],
                'tamanho' => strlen((string)$envio['content']),
            ]);
        }
        continue;
    }

    // ── Fila offline: o normal, não a exceção ───────────────────────────────
    //
    // Não incrementa `params_synced_at`: quem completa é o callback em
    // /pushinstructresponse, que grava pelo MESMO parser. O que se marca aqui é
    // só o backoff, para não reenfileirar o mesmo device na próxima rodada.
    $ehBusy = stripos((string)$envio['hub_msg'], 'busy') !== false
           || stripos((string)$envio['result_msg'], 'busy') !== false;
    $motivo = $envio['status'] === 'sent' ? ($ehBusy ? 'busy' : 'offline') : 'erro';

    $db->prepare("UPDATE devices SET params_sync_tries = params_sync_tries + 1,
                         params_sync_next = :prox WHERE imei = :imei")
       ->execute([':prox' => proxima_tentativa($tries + 1, $motivo), ':imei' => $imei]);

    if ($envio['status'] === 'sent') {
        $enfileirados++;
        echo "  … {$rotulo}: em fila offline ({$motivo})\n";
    } else {
        $falhas++;
        echo "  ✗ {$rotulo}: {$envio['result_msg']}\n";
        Logger::warning('param_sync: envio falhou', [
            'imei' => $imei, 'status' => $envio['status'], 'msg' => $envio['result_msg'],
        ]);
    }
}

echo "[param_sync] lidos={$lidos} enfileirados={$enfileirados} falhas={$falhas}\n";

// Quem estourou o teto de tentativas fica visível: sem isto, um device que o
// worker desistiu de ler seria indistinguível de um que nunca entrou na fila.
$parados = $db->query("
    SELECT COUNT(*) FROM devices d JOIN device_models dm ON dm.id = d.device_model_id
     WHERE d.is_active = 1 AND dm.protocol = 'JTT'
       AND d.params_synced_at IS NULL
       AND d.params_sync_tries >= " . PARAM_SYNC_MAX_TENTATIVAS)->fetchColumn();
if ($parados > 0) {
    echo "[param_sync] ⚠ {$parados} equipamento(s) desistidos após "
       . PARAM_SYNC_MAX_TENTATIVAS . " tentativas\n";
}
exit(0);
