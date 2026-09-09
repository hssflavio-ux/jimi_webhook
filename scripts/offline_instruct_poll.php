<?php
/**
 * JIMI Webhook System — Consulta da fila offline do hub v4.18.0
 * Script: scripts/offline_instruct_poll.php
 *
 * Fecha a lacuna registrada em `docs/FILA_OFFLINE_COMANDOS.md`: até aqui, um
 * comando que virava `commands.status = 'sent'` (device offline/timeout —
 * `iothub_send_instruct()`) ficava PARA SEMPRE com a tela dizendo "será
 * entregue quando o equipamento reconectar", sem nenhuma confirmação do hub.
 *
 * 🔴 O teste decisivo do documento (09/09/2026, produção, equipamento offline
 * há 19 dias) mostrou que o hub CACHEIA o comando offline mesmo sem
 * `offlineFlag` — a fila vazia medida em 07/09 era de comandos cuja janela de
 * validade (`_time_out`) já tinha expirado, não de comandos nunca cacheados.
 * Este script consulta `iothub_query_offline_instruct()` (§2.21,
 * `queryOfflineInstruct`) periodicamente para os comandos pendentes e grava o
 * resultado — `queued` (ainda na fila), `not_found` (saiu da fila: entregue,
 * expirado, ou nunca chegou a ficar lá) ou `error` (hub inacessível) — em
 * `commands.hub_queue_status`/`hub_queue_checked_at` (migração v4.18.0).
 *
 * ⚠️ O §2.21 não recebe id de comando, só IMEI: devolve o que estiver
 * cacheado NAQUELE MOMENTO para o equipamento, sem dizer a qual pedido isso
 * corresponde. A correlação por CONTEÚDO abaixo é heurística — mesma classe
 * da usada em `handlers/comandos.php` para respostas "sem comando
 * correlacionado" — e o comando mais recente do IMEI é o palpite quando o
 * conteúdo não bate exatamente.
 *
 * Uso: php scripts/offline_instruct_poll.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/iothub_command.php';

$db = Database::getInstance()->getConnection();

// Janela de interesse: não vale consultar o hub para um comando de meses
// atrás (a fila com certeza já expirou) nem reconsultar um que acabou de ser
// checado — RECONSULTA_MIN corresponde à cadência do próprio cron
// (crontab-setup.sh), então "não confere de novo antes disso" evita duas
// rodadas se sobreporem em uma execução lenta.
const OFP_JANELA_DIAS   = 7;
const OFP_RECONSULTA_MIN = 10;

$rows = $db->prepare("
    SELECT id, imei, command_content, created_at
    FROM commands
    WHERE status = 'sent'
      AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . OFP_JANELA_DIAS . " DAY)
      AND (hub_queue_checked_at IS NULL
           OR hub_queue_checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . OFP_RECONSULTA_MIN . " MINUTE))
    ORDER BY imei, created_at DESC
");
$rows->execute();
$pendentes = $rows->fetchAll(PDO::FETCH_ASSOC);

if (!$pendentes) {
    echo "Offline Instruct Poll: nada pendente.\n";
    exit(0);
}

// Um IMEI pode ter mais de um comando 'sent' represado; o hub só devolve UM
// resultado por IMEI (o que está cacheado agora) — agrupa para consultar uma
// vez por equipamento, não uma vez por comando.
$porImei = [];
foreach ($pendentes as $r) $porImei[$r['imei']][] = $r;

$update = $db->prepare("
    UPDATE commands SET hub_queue_status = :st, hub_queue_checked_at = UTC_TIMESTAMP()
    WHERE id = :id
");

$stats = ['queued' => 0, 'not_found' => 0, 'error' => 0];

foreach ($porImei as $imei => $comandos) {
    $resp = iothub_query_offline_instruct($imei);

    if ($resp['erro'] !== null) {
        $stats['error']++;
        foreach ($comandos as $c) $update->execute([':st' => 'error', ':id' => $c['id']]);
        Logger::warning('offline_instruct_poll: erro ao consultar hub', ['imei' => $imei, 'erro' => $resp['erro']]);
        continue;
    }

    if (!$resp['encontrado']) {
        $stats['not_found']++;
        foreach ($comandos as $c) $update->execute([':st' => 'not_found', ':id' => $c['id']]);
        continue;
    }

    // Casa pelo CONTEÚDO enviado; sem correspondência exata, assume o
    // comando 'sent' mais recente do IMEI (é o que normalmente ocupa a fila).
    $achado = null;
    foreach ($comandos as $c) {
        if (trim((string)$c['command_content']) === trim((string)$resp['content'])) { $achado = $c; break; }
    }
    if (!$achado) $achado = $comandos[0];

    $stats['queued']++;
    $update->execute([':st' => 'queued', ':id' => $achado['id']]);
    // Os DEMAIS comandos 'sent' do mesmo IMEI, que não bateram desta vez,
    // ficam como "não encontrados NESTA consulta" — honesto: só um comando
    // por vez ocupa a fila do hub, então os outros não estão lá agora.
    foreach ($comandos as $c) {
        if ($c['id'] !== $achado['id']) $update->execute([':st' => 'not_found', ':id' => $c['id']]);
    }
}

echo 'Offline Instruct Poll: ' . count($porImei) . ' equipamento(s) consultado(s) — '
   . $stats['queued'] . ' na fila, ' . $stats['not_found'] . ' não encontrado(s), '
   . $stats['error'] . ' erro(s).' . "\n";
