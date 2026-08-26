<?php
/**
 * bycamera — Backfill de vídeo de alarme JT/T via VIDEOUPLOAD (v1, 26/08/2026)
 *
 * Cruza os alarmes que a câmera JÁ TEM gravados (consulta `GET
 * /api/v2/alarm/getAlarm` no IoTHub, `includes/iothub_alarm_api.php`) contra os
 * que este sistema já tem no storage, e dispara `VIDEOUPLOAD` só para os que
 * faltam vídeo — ver `docs/COMANDOS_128_CONSULTA.md` §9.9 para o formato do
 * comando e `includes/media.php` (`media_video_complete()`) para o critério de
 * "falta".
 *
 * ── POR QUE ISTO EXISTIA COMO GAP ──────────────────────────────────────────
 * O gatilho automático (`queue_event_video_request()`, disparado no
 * `/pushalarm`) só existe/funciona corretamente desde a correção do formato
 * do comando (26/08/2026 — ver §9.9). Todo alarme JT/T com anexo recebido
 * ANTES disso nunca teve o `VIDEOUPLOAD` certo disparado — mesmo que a
 * ocorrência já exista no banco. Medido em produção no dia da correção: 263
 * dos 268 alarmes com `alarmLabel` dos últimos 7 dias, só no JC371
 * 865478070654829, sem vídeo no disco.
 *
 * ── ESCOPO DELIBERADAMENTE ESTREITO ─────────────────────────────────────────
 * Só dispara para alarme que JÁ EXISTE em `alarms` (casado por
 * imei+alarm_label). Um `alarmLabel` que a câmera anuncia mas que não tem
 * linha correspondente aqui (webhook nunca chegou, ou chegou e a câmera não
 * respondeu com alertType reconhecido) é um problema DIFERENTE — não há
 * ocorrência para linkar o vídeo, e criar uma linha sintética de `alarms` só
 * a partir do `getAlarm` (que traz bem menos campo que o push original) é
 * fora do escopo desta rodada. Este script REPORTA a contagem desses casos
 * (rótulo "sem linha local") sem agir sobre eles.
 *
 * Uso:
 *   php scripts/video_upload_backfill.php                  # 7 dias, todos os JTT ativos
 *   php scripts/video_upload_backfill.php --dias=30
 *   php scripts/video_upload_backfill.php --imei=865478070654829
 *   php scripts/video_upload_backfill.php --limite=50       # teto de comandos disparados na rodada
 *   php scripts/video_upload_backfill.php --dry-run         # só relata, não dispara nada
 *
 * Cron sugerido (rotina permanente — pega o que o gatilho automático perder):
 *   0,30 * * * * php /var/www/jimi_webhook/scripts/video_upload_backfill.php --dias=1 --limite=100 >> /var/log/jimi_video_backfill.log 2>&1
 */

$raiz = dirname(__DIR__);
require_once $raiz . '/config/database.php';
require_once $raiz . '/core/Logger.php';
require_once $raiz . '/includes/media.php';
require_once $raiz . '/includes/alarm_video_request.php';   // request_alarm_video_jtt()
require_once $raiz . '/includes/iothub_alarm_api.php';

/** Teto de comandos disparados por rodada, quando --limite não é passado. */
const VUB_LIMITE_PADRAO = 200;

/** Pausa entre comandos, para não rajar o hub/câmera (ver CLAUDE.md sobre CHECK# em rajada). */
const VUB_PAUSA_MICROSSEGUNDOS = 300000; // 0.3s

// ── Argumentos ───────────────────────────────────────────────────────────────
$opts = getopt('', ['dias::', 'imei::', 'limite::', 'dry-run']);
$dias    = isset($opts['dias']) ? max(1, (int)$opts['dias']) : 7;
$soImei  = $opts['imei'] ?? null;
$limite  = isset($opts['limite']) ? max(1, (int)$opts['limite']) : VUB_LIMITE_PADRAO;
$dryRun  = array_key_exists('dry-run', $opts);

$db = Database::getInstance()->getConnection();

$fimUtc = gmdate('Y-m-d H:i:s');
$iniUtc = gmdate('Y-m-d H:i:s', strtotime($fimUtc . ' UTC') - $dias * 86400);

// ── Devices JT/T elegíveis ──────────────────────────────────────────────────
$sql = "
    SELECT d.imei, dm.model_name, dm.camera_count
      FROM devices d
      JOIN device_models dm ON dm.id = d.device_model_id
     WHERE dm.protocol = 'JTT' AND d.is_active = 1
";
$params = [];
if ($soImei !== null) {
    $sql .= ' AND d.imei = :imei';
    $params[':imei'] = $soImei;
}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$devices) {
    fwrite(STDERR, "Nenhum device JT/T ativo encontrado" . ($soImei ? " para IMEI {$soImei}" : '') . ".\n");
    exit(1);
}

echo "── video_upload_backfill ── janela {$iniUtc} .. {$fimUtc} UTC ({$dias}d) — "
   . count($devices) . " device(s) — limite {$limite} comando(s)"
   . ($dryRun ? ' — DRY-RUN' : '') . "\n\n";

$totalDisparados = 0;
$totalPulados     = 0; // já completo, ou pedido pendente
$totalSemLinha    = 0; // câmera tem, o banco não
$totalErroApi     = 0;

foreach ($devices as $dev) {
    if ($totalDisparados >= $limite) {
        echo "Limite de {$limite} comando(s) atingido — parando antes de {$dev['imei']}.\n";
        break;
    }

    $imei     = $dev['imei'];
    $canais   = ((int)$dev['camera_count'] >= 2) ? 2 : 1;

    $r = iothub_get_alarms_chunked($imei, $iniUtc, $fimUtc);
    if (!$r['ok']) {
        echo "[{$imei}] {$dev['model_name']}: falha ao consultar getAlarm v2 — pulando device.\n";
        $totalErroApi++;
        continue;
    }
    if ($r['truncado']) {
        echo "[{$imei}] {$dev['model_name']}: ⚠️ resposta ainda truncada na menor granularidade — pode haver alarme fora da amostra.\n";
    }

    $labelsCamera = [];
    foreach ($r['alarmes'] as $a) {
        if ($a['alarm_label'] !== null) {
            $labelsCamera[$a['alarm_label']] = true;
        }
    }
    if (!$labelsCamera) {
        echo "[{$imei}] {$dev['model_name']}: 0 alarme(s) com anexo na janela.\n";
        continue;
    }

    // Um SELECT só, com todos os alarmes locais do device na janela que têm
    // label — evita N idas ao banco por alarmLabel visto na câmera.
    $stmt = $db->prepare("
        SELECT id, alarm_label, file_url,
               DATE_FORMAT(CONVERT_TZ(alarm_time, '+00:00', '-03:00'), '%Y-%m-%d %H:%i:%s') AS local_ts
          FROM alarms
         WHERE imei = :imei
           AND alarm_label IS NOT NULL AND alarm_label <> ''
           AND alarm_time BETWEEN DATE_SUB(:ini, INTERVAL 1 DAY) AND DATE_ADD(:fim, INTERVAL 1 DAY)
    ");
    // Janela local +-1 dia de folga: alarm_time (UTC) x alarmTime da API (UTC)
    // devem bater, a folga só absorve arredondamento de fuso/relógio do device.
    $stmt->execute([':imei' => $imei, ':ini' => $iniUtc, ':fim' => $fimUtc]);
    $locais = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $locais[$row['alarm_label']] = $row;
    }

    $semLinha = 0;
    $completos = 0;
    $pendentes = 0;
    $disparadosDevice = 0;

    foreach (array_keys($labelsCamera) as $label) {
        if (!isset($locais[$label])) {
            $semLinha++;
            continue;
        }
        $al = $locais[$label];
        if (media_video_complete($al['file_url'], $canais)) {
            $completos++;
            continue;
        }

        // Dedup: mesmo pedido pendente que request_alarm_video() respeita.
        $chk = $db->prepare("SELECT id FROM alarm_video_requests WHERE alarm_id = :id AND status = 'pendente'");
        $chk->execute([':id' => (int)$al['id']]);
        if ($chk->fetchColumn()) {
            $pendentes++;
            continue;
        }

        if ($totalDisparados >= $limite) {
            break;
        }

        if ($dryRun) {
            echo "[{$imei}] alarme #{$al['id']} ({$label}): dispararia VIDEOUPLOAD (dry-run).\n";
            $totalDisparados++;
            $disparadosDevice++;
            continue;
        }

        $alParaEnvio = [
            'id'           => (int)$al['id'],
            'imei'         => $imei,
            'alarm_label'  => $label,
            'local_ts'     => $al['local_ts'],
            'camera_count' => $dev['camera_count'],
        ];
        $resp = request_alarm_video_jtt($db, $alParaEnvio, (int)$al['id'], null);
        $ok   = $resp['ok'] ? 'ok' : 'falhou';
        echo "[{$imei}] alarme #{$al['id']} ({$label}): VIDEOUPLOAD {$ok} — {$resp['msg']}\n";
        $totalDisparados++;
        $disparadosDevice++;
        usleep(VUB_PAUSA_MICROSSEGUNDOS);
    }

    $totalPulados  += $completos + $pendentes;
    $totalSemLinha += $semLinha;

    echo "[{$imei}] {$dev['model_name']}: " . count($labelsCamera) . " com anexo na câmera | "
       . "{$completos} já completo(s) | {$pendentes} pedido pendente | "
       . "{$semLinha} sem linha local | {$disparadosDevice} disparado(s) agora.\n\n";
}

echo "── Resumo ──\n";
echo "Disparados: {$totalDisparados}" . ($dryRun ? ' (dry-run, nenhum comando saiu de fato)' : '') . "\n";
echo "Pulados (já completo ou pendente): {$totalPulados}\n";
echo "Sem linha local (alarme só na câmera — webhook não gravou; não tratado por este script): {$totalSemLinha}\n";
if ($totalErroApi > 0) {
    echo "Devices com falha na consulta getAlarm v2: {$totalErroApi}\n";
}
