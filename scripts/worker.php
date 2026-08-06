<?php
/**
 * JIMI Webhook System — Worker de Jobs v4.4.0
 * Script: scripts/worker.php
 *
 * Cron (cada 1 min): processa fila de jobs pendentes.
 * Tipos: report (CSV/XLSX/PDF), video_download, rollup, notification (e-mail).
 *
 * É aqui que a saída SMTP acontece — nunca no webhook (ver
 * includes/notification_engine.php).
 *
 * Uso: php scripts/worker.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/export_helper.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/fleet_state.php'; // fmt_duration(), resolve_current_state()
require_once __DIR__ . '/../includes/schedule.php';    // SCHEDULE_MAX_ROWS, SCHEDULE_MAX_FAILURES
require_once __DIR__ . '/../includes/notification_engine.php'; // notify() ao desativar agendamento
require_once __DIR__ . '/../includes/download_token.php';      // download_url() do link do e-mail
require_once __DIR__ . '/../includes/geocode.php';             // GEO_ADDR_SQL/geo_join() nos exports

/** Máximo de tentativas de um job com falha transitória (só 'notification'). */
const JOB_MAX_ATTEMPTS = 3;

/**
 * Minutos após os quais um job em 'processando' é considerado órfão.
 *
 * Tem de ser MAIOR que o job legítimo mais lento — um relatório no teto de
 * 100 mil linhas leva minutos. 15 min dá folga larga: o worker roda a cada
 * 1 min, então nada honesto fica 15 min sem terminar nem falhar.
 */
const JOB_ORPHAN_MINUTES = 15;

$db = Database::getInstance()->getConnection();

// Antes de pegar trabalho novo, recupera o que ficou pendurado
reapOrphanJobs($db);

$stmt = $db->prepare("SELECT * FROM jobs WHERE status = 'pendente' ORDER BY created_at ASC LIMIT 5");
$stmt->execute();
$jobs = $stmt->fetchAll();

foreach ($jobs as $job) {
    try {
        $db->prepare("UPDATE jobs SET status = 'processando' WHERE id = :id")
           ->execute([':id' => $job['id']]);

        switch ($job['type']) {
            case 'report':
                $result = processReportJob($db, $job);
                break;
            case 'video_download':
                $result = processVideoJob($db, $job);
                break;
            case 'rollup':
                $result = processRollupJob($db, $job);
                break;
            case 'notification':
                $result = processNotificationJob($db, $job);
                break;
            default:
                $result = ['status' => 'falhou', 'error' => 'Tipo desconhecido'];
        }

        if ($result['status'] === 'concluido') {
            $db->prepare("UPDATE jobs SET status = 'concluido', result_path = :path, updated_at = NOW() WHERE id = :id")
               ->execute([':path' => $result['path'] ?? null, ':id' => $job['id']]);
        } else {
            markJobFailed($db, $job, $result['error'] ?? 'Erro desconhecido');
        }
    } catch (Exception $e) {
        markJobFailed($db, $job, $e->getMessage());
    }
}

echo 'Worker executado: ' . count($jobs) . " jobs processados.\n";

/**
 * Recupera jobs órfãos — presos em 'processando' além de JOB_ORPHAN_MINUTES.
 *
 * ⚠️ POR QUE ISTO EXISTE (v4.7.3). O laço acima marca 'processando' ANTES de
 * trabalhar e só grava o desfecho DEPOIS. Um **erro fatal** — não uma exceção,
 * que o `catch` pega, mas um fatal de verdade, como `Class "ZipArchive" not
 * found` — mata o processo entre os dois pontos. O job então fica em
 * 'processando' PARA SEMPRE: nunca mais é selecionado (a query pega só
 * 'pendente'), nunca falha, nunca notifica, e o `attempts`/retry não alcança
 * porque nem chegou a rodar. A tela mostra "em andamento" indefinidamente.
 *
 * Aconteceu de verdade no homolog em 01/08/2026: `php-zip` não estava
 * instalado, e o job 1 ficou encalhado sem nada no histórico do agendamento.
 * O sintoma era indistinguível de "ainda processando".
 *
 * `updated_at` serve como relógio porque a coluna é ON UPDATE
 * CURRENT_TIMESTAMP: ela marca exatamente quando o job entrou em
 * 'processando'.
 *
 * Fecha as DUAS pontas: o job e, quando houver, a execução do agendamento —
 * senão a `report_schedule_runs` continuaria em 'enfileirado' e a regra das
 * 3 falhas nunca contaria esta.
 *
 * @param PDO $db Conexão ativa
 * @returns int Quantidade de jobs recuperados
 */
function reapOrphanJobs($db): int {
    $stmt = $db->prepare(
        "SELECT * FROM jobs
         WHERE status = 'processando'
           AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :m MINUTE)"
    );
    $stmt->bindValue(':m', JOB_ORPHAN_MINUTES, PDO::PARAM_INT);
    $stmt->execute();
    $orfaos = $stmt->fetchAll();

    if (!$orfaos) {
        return 0;
    }

    $msg = sprintf(
        'Job interrompido: ficou mais de %d min em "processando" sem concluir. '
        . 'Causa provável: erro fatal no worker (o processo morre antes de registrar a falha). '
        . 'Verifique logs/worker.log.',
        JOB_ORPHAN_MINUTES
    );

    foreach ($orfaos as $job) {
        $db->prepare(
            "UPDATE jobs SET status = 'falhou', error_message = :err WHERE id = :id"
        )->execute([':err' => $msg, ':id' => $job['id']]);

        // A execução do agendamento é a outra metade do encalhe
        if (!empty($job['schedule_run_id'])) {
            $sid = 0;
            if (!empty($job['params'])) {
                $p = json_decode((string)$job['params'], true);
                $sid = isset($p['schedule_id']) ? (int)$p['schedule_id'] : 0;
            }
            finishScheduleRun($db, (int)$job['schedule_run_id'], $sid, 'falhou', 0, $msg);
        }

        Logger::warning('Worker: job órfão recuperado', [
            'job_id' => $job['id'], 'type' => $job['type'], 'desde' => $job['updated_at'],
        ]);
        echo "Job {$job['id']}: órfão recuperado (preso em 'processando' desde {$job['updated_at']}).\n";
    }

    return count($orfaos);
}

/**
 * Encerra um job com falha, aplicando retry quando a falha é transitória.
 *
 * Só 'notification' faz retry: SMTP fora do ar por dois minutos é condição
 * passageira e perder o aviso seria pior. Report e video_download continuam
 * falhando de primeira — reexecutá-los repetiria trabalho pesado e o usuário
 * pode simplesmente pedir de novo em /exportar.
 *
 * @param PDO    $db    Conexão ativa
 * @param array  $job   Linha da fila
 * @param string $error Mensagem de erro
 * @returns void
 */
function markJobFailed($db, array $job, string $error): void {
    $attempts = (int)($job['attempts'] ?? 0) + 1;

    if ($job['type'] === 'notification' && $attempts < JOB_MAX_ATTEMPTS) {
        $db->prepare(
            "UPDATE jobs SET status = 'pendente', attempts = :att, error_message = :err, updated_at = NOW()
             WHERE id = :id"
        )->execute([':att' => $attempts, ':err' => $error, ':id' => $job['id']]);
        echo "Job {$job['id']}: falha transitória (tentativa {$attempts}/" . JOB_MAX_ATTEMPTS . ") — {$error}\n";
        return;
    }

    $db->prepare(
        "UPDATE jobs SET status = 'falhou', attempts = :att, error_message = :err, updated_at = NOW()
         WHERE id = :id"
    )->execute([':att' => $attempts, ':err' => $error, ':id' => $job['id']]);
}

function processReportJob($db, $job): array {
    $params = json_decode($job['params'] ?? '{}', true);
    $reportType = $params['report_type'] ?? 'alarms';
    // Dias do form são BRT; colunas do banco são UTC
    [$dateFrom, $dateTo] = brt_day_range_to_utc(
        $params['date_from'] ?? brt_today('Y-m-d', '-30 days'),
        $params['date_to'] ?? brt_today()
    );
    $cid        = $job['customer_id'];
    $reportName = $params['report_name'] ?? 'Relatório';

    // Formato: coluna jobs.format (v4.1.0) com fallback para params (pré-migração)
    $format = $job['format'] ?? $params['format'] ?? 'csv';
    if (!in_array($format, ['csv', 'xlsx', 'pdf'], true)) $format = 'csv';

    $dir = __DIR__ . '/../storage/reports';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $source = buildReportSource($db, $reportType, $cid, $dateFrom, $dateTo);
    if (!$source) return ['status' => 'falhou', 'error' => 'Tipo de relatório não reconhecido'];
    [$headers, $stmt, $mapper] = $source;
    // Pesos de coluna do PDF (v4.9.0). Sem eles todas as colunas nascem com a
    // mesma largura, e o endereço — o campo mais longo de todos os relatórios —
    // saía cortado pela metade; ver a nota em PdfWriter.
    $colWeights = $source[3] ?? [];

    // O nome carrega 32 hex aleatórios (v4.7.1) porque `storage/` é servido como
    // estático pelo Apache — o .htaccess da raiz só reescreve o que NÃO é arquivo
    // (`!-f`), então o download não passa por require_login(). Sem o token, o nome
    // era `report_<job_id>_<timestamp>` — job_id sequencial e timestamp com
    // granularidade de segundo, isto é, ENUMERÁVEL, e num sistema multi-tenant
    // isso é vazamento entre clientes. O token não substitui a autenticação; ele
    // torna a URL impossível de adivinhar, que é o que o link enviado por e-mail
    // (acima de MAIL_MAX_ATTACH_MB) precisa para continuar funcionando sem login.
    $filename = 'report_' . $job['id'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.' . $format;
    $filepath = $dir . '/' . $filename;

    // Teto de linhas (v4.7.0). SYNC_EXPORT_MAX_ROWS não se aplica ao caminho
    // assíncrono, mas sem teto algum um relatório de milhões de linhas estoura
    // a memória do worker e derruba a fila inteira — inclusive as notificações
    // que estavam atrás dele.
    $rowCount = 0;

    switch ($format) {
        case 'xlsx':
            $writer = new XlsxWriter($filepath);
            $writer->writeHeader($headers);
            while ($row = $stmt->fetch()) {
                $writer->writeRow($mapper($row));
                if (++$rowCount >= SCHEDULE_MAX_ROWS) break;
            }
            if (!$writer->close()) return ['status' => 'falhou', 'error' => 'Falha ao gerar XLSX'];
            break;

        case 'pdf':
            $subtitle = 'Período: ' . substr($dateFrom, 0, 10) . ' a ' . substr($dateTo, 0, 10)
                      . ' — gerado em ' . brt_today('d/m/Y H:i') . ' BRT';
            $writer = new PdfWriter($filepath, $reportName, $headers, $subtitle, $colWeights);
            while ($row = $stmt->fetch()) {
                $writer->writeRow($mapper($row));
                $rowCount++;
                if ($writer->isFull() || $rowCount >= SCHEDULE_MAX_ROWS) break;
            }
            if (!$writer->close()) return ['status' => 'falhou', 'error' => 'Falha ao gerar PDF'];
            break;

        default: // csv — UTF-8 BOM + ';' (Excel pt-BR abre em colunas)
            $fp = fopen($filepath, 'w');
            if (!$fp) return ['status' => 'falhou', 'error' => 'Não foi possível criar o arquivo'];
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv($fp, $headers, ';');
            while ($row = $stmt->fetch()) {
                $linha = $mapper($row);
                // CSV não tem hyperlink: a célula de link vira a URL, como em
                // stream_export(). Sem isto o `__toString()` do ExportLink
                // escreveria só "MAPA" e a coluna ficaria inútil no arquivo de
                // texto — justamente onde esconder o endereço é perder o dado.
                foreach ($linha as $k => $v) {
                    if ($v instanceof ExportLink) $linha[$k] = $v->url;
                }
                fputcsv($fp, $linha, ';');
                if (++$rowCount >= SCHEDULE_MAX_ROWS) break;
            }
            fclose($fp);
    }

    $relPath = 'storage/reports/' . $filename;

    // Entrega por e-mail (v4.7.0): só quando o job veio de um agendamento.
    // O job continua 'concluido' mesmo se o e-mail falhar — o ARQUIVO existe e
    // fica baixável em /exportar; o que falhou foi a entrega, e é no histórico
    // do agendamento que essa distinção importa.
    if (!empty($params['deliver_email'])) {
        deliverScheduledReport($db, $job, $params, $filepath, $relPath, $rowCount);
    }

    return ['status' => 'concluido', 'path' => $relPath];
}

/**
 * Envia por e-mail o relatório recém-gerado e fecha a execução do agendamento.
 *
 * Acima de MAIL_MAX_ATTACH_MB o arquivo vira **link** em vez de anexo: provedor
 * de e-mail recusa anexo grande (o limite comum é 25 MB, e vários param bem
 * antes), e um e-mail recusado é pior do que um link.
 *
 * @param PDO    $db       Conexão ativa
 * @param array  $job      Linha da fila
 * @param array  $params   Params decodificados do job
 * @param string $filepath Caminho absoluto do arquivo gerado
 * @param string $relPath  Caminho relativo (para o link e para jobs.result_path)
 * @param int    $rowCount Linhas escritas no relatório
 * @returns void
 */
function deliverScheduledReport($db, array $job, array $params, string $filepath, string $relPath, int $rowCount): void {
    $runId      = isset($job['schedule_run_id']) ? (int)$job['schedule_run_id'] : 0;
    $scheduleId = isset($params['schedule_id']) ? (int)$params['schedule_id'] : 0;
    $cid        = isset($job['customer_id']) ? (int)$job['customer_id'] : null;
    $to         = is_array($params['deliver_email']) ? $params['deliver_email'] : [];

    // Relatório vazio: por padrão envia mesmo assim (o "nada aconteceu" é
    // informação), mas o agendamento pode pedir para pular.
    if ($rowCount === 0 && !empty($params['skip_if_empty'])) {
        finishScheduleRun($db, $runId, $scheduleId, 'vazio', $rowCount, null);
        Logger::info('Worker: relatório agendado vazio, envio pulado', ['job_id' => $job['id']]);
        return;
    }

    if (!mail_is_configured($cid)) {
        finishScheduleRun($db, $runId, $scheduleId, 'falhou', $rowCount,
            'Servidor SMTP não cadastrado (Cadastros › Servidor de E-mail)');
        return;
    }

    $maxBytes = (float)(getenv('MAIL_MAX_ATTACH_MB') ?: 5) * 1024 * 1024;
    $size     = is_file($filepath) ? (int)filesize($filepath) : 0;
    $asLink   = $size > $maxBytes;

    // APP_URL ausente + entrega por link = a pior combinação possível: o e-mail
    // sai, o provedor aceita, o histórico marca "enviado" — e o destinatário
    // recebe uma URL relativa que não abre em caixa de entrada nenhuma. Falhar
    // aqui é melhor do que entregar link morto em silêncio: o erro fica visível
    // no histórico do agendamento e alimenta a regra das 3 falhas.
    // (Encontrado no homolog em 01/08/2026: APP_URL simplesmente não estava
    // no .env, e nada no código reclamava.)
    if ($asLink && rtrim((string)(getenv('APP_URL') ?: ''), '/') === '') {
        $msg = sprintf(
            'APP_URL não configurada no .env: o arquivo tem %.1f MB (acima de %.1f MB) '
            . 'e seria entregue como link, mas não há endereço base para montá-lo.',
            $size / 1048576,
            $maxBytes / 1048576
        );
        finishScheduleRun($db, $runId, $scheduleId, 'falhou', $rowCount, $msg);
        Logger::error('Worker: entrega por link abortada — APP_URL ausente', [
            'job_id' => $job['id'], 'size_mb' => round($size / 1048576, 2),
        ]);
        return;
    }

    $attachments = $asLink ? [] : [[
        'path' => $filepath,
        'name' => scheduleAttachmentName($params, $relPath),
        'mime' => scheduleMimeType($relPath),
    ]];

    $result = send_mail(
        $to,
        'Relatório: ' . (string)($params['report_name'] ?? 'Relatório')
            . ' — ' . (string)($params['periodo_label'] ?? ''),
        buildScheduledReportEmailHtml($params, $rowCount, $asLink, $relPath, $size, (int)$job['id']),
        $attachments,
        $cid
    );

    if ($result['ok']) {
        finishScheduleRun($db, $runId, $scheduleId, 'enviado', $rowCount, null);
        Logger::info('Worker: relatório agendado enviado', [
            'job_id' => $job['id'], 'linhas' => $rowCount, 'link' => $asLink,
        ]);
    } else {
        finishScheduleRun($db, $runId, $scheduleId, 'falhou', $rowCount, $result['error']);
    }
}

/**
 * Fecha a execução no histórico e atualiza o contador de falhas do agendamento.
 *
 * Sucesso **zera** `fail_count`: a regra é 3 falhas CONSECUTIVAS. Sem o reset,
 * três tropeços espalhados por meses desativariam um agendamento saudável.
 *
 * @param PDO         $db         Conexão ativa
 * @param int         $runId      report_schedule_runs.id (0 = sem histórico)
 * @param int         $scheduleId report_schedules.id (0 = job avulso)
 * @param string      $status     enviado|vazio|falhou
 * @param int         $rowCount   Linhas do relatório
 * @param string|null $error      Mensagem de erro, quando houver
 * @returns void
 */
function finishScheduleRun($db, int $runId, int $scheduleId, string $status, int $rowCount, ?string $error): void {
    try {
        if ($runId > 0) {
            $db->prepare("
                UPDATE report_schedule_runs
                SET status = :st, row_count = :rc, error_message = :err
                WHERE id = :id")
               ->execute([':st' => $status, ':rc' => $rowCount, ':err' => $error, ':id' => $runId]);
        }
        if ($scheduleId <= 0) {
            return;
        }

        if ($status === 'falhou') {
            $db->prepare("UPDATE report_schedules SET fail_count = fail_count + 1 WHERE id = :id")
               ->execute([':id' => $scheduleId]);

            $stmt = $db->prepare("SELECT * FROM report_schedules WHERE id = :id");
            $stmt->execute([':id' => $scheduleId]);
            $sch = $stmt->fetch();

            if ($sch && (int)$sch['fail_count'] >= SCHEDULE_MAX_FAILURES && (int)$sch['is_active'] === 1) {
                $db->prepare("UPDATE report_schedules SET is_active = 0 WHERE id = :id")
                   ->execute([':id' => $scheduleId]);
                notify($db, [
                    'customer_id' => (int)$sch['customer_id'],
                    'user_id'     => $sch['user_id'] !== null ? (int)$sch['user_id'] : null,
                    'kind'        => 'sistema',
                    'severity'    => 'warning',
                    'title'       => 'Agendamento desativado: ' . (string)$sch['name'],
                    'body'        => SCHEDULE_MAX_FAILURES . ' execuções falharam em sequência. Último erro: '
                                   . mb_substr((string)$error, 0, 200),
                    'link_url'    => '/agendamentos',
                    'ref_type'    => 'report_schedule',
                    'ref_id'      => $scheduleId,
                    'want_popup'  => 0,
                    'want_sound'  => 0,
                    'dedupe_key'  => 'sched|' . $scheduleId,
                ]);
                Logger::warning('Worker: agendamento desativado após falhas consecutivas', [
                    'schedule_id' => $scheduleId, 'error' => $error,
                ]);
            }
        } else {
            $db->prepare("UPDATE report_schedules SET fail_count = 0 WHERE id = :id")
               ->execute([':id' => $scheduleId]);
        }
    } catch (Throwable $e) {
        Logger::error('Worker: falha ao registrar execução do agendamento', [
            'run_id' => $runId, 'error' => $e->getMessage(),
        ]);
    }
}

/**
 * Nome amigável do anexo: "Alarmes Julho - 01-07-2026.xlsx".
 *
 * O nome interno (`report_42_20260729_1830.xlsx`) não diz nada a quem recebe e
 * colide visualmente com os outros na caixa de entrada.
 *
 * @param array  $params  Params do job
 * @param string $relPath Caminho relativo (fonte da extensão)
 * @returns string
 */
function scheduleAttachmentName(array $params, string $relPath): string {
    $ext  = pathinfo($relPath, PATHINFO_EXTENSION) ?: 'csv';
    $base = (string)($params['report_name'] ?? 'relatorio');
    $per  = (string)($params['periodo_label'] ?? '');
    // Caractere de caminho ou de citação no nome do anexo quebra o cabeçalho MIME
    $safe = preg_replace('/[^\p{L}\p{N} _.-]+/u', '', $base . ($per ? ' - ' . str_replace('/', '-', $per) : ''));
    $safe = trim(preg_replace('/\s+/', ' ', (string)$safe)) ?: 'relatorio';
    return mb_substr($safe, 0, 120) . '.' . $ext;
}

/**
 * MIME do arquivo gerado.
 *
 * @param string $relPath Caminho relativo
 * @returns string
 */
function scheduleMimeType(string $relPath): string {
    switch (strtolower(pathinfo($relPath, PATHINFO_EXTENSION))) {
        case 'xlsx': return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        case 'pdf':  return 'application/pdf';
        default:     return 'text/csv; charset=UTF-8';
    }
}

/**
 * Corpo HTML do e-mail de relatório agendado.
 *
 * @param array  $params   Params do job
 * @param int    $rowCount Linhas do relatório
 * @param bool   $asLink   Se o arquivo foi substituído por link
 * @param string $relPath  Caminho relativo do arquivo
 * @param int    $size     Tamanho em bytes
 * @returns string HTML completo
 */
function buildScheduledReportEmailHtml(array $params, int $rowCount, bool $asLink, string $relPath, int $size, int $jobId = 0): string {
    $accent = '#0052ff';
    $nome   = htmlspecialchars((string)($params['report_name'] ?? 'Relatório'), ENT_QUOTES, 'UTF-8');
    $per    = htmlspecialchars((string)($params['periodo_label'] ?? ''), ENT_QUOTES, 'UTF-8');

    $corpo = $rowCount === 0
        ? 'Nenhum registro foi encontrado no período.'
        : number_format($rowCount, 0, ',', '.') . ' registro(s) no período.';

    $anexo = '';
    if ($asLink) {
        $mb = number_format($size / 1024 / 1024, 1, ',', '.');
        // Link ASSINADO com validade (v4.7.3), não mais o caminho direto para
        // storage/reports — que era inadivinhável desde a v4.7.1, mas eterno,
        // e viaja por servidores de e-mail de terceiros. Ver
        // includes/download_token.php. Valida por DOWNLOAD_LINK_TTL (7 dias),
        // dentro da retenção de 30 dias do arquivo.
        $url = htmlspecialchars(download_url($jobId), ENT_QUOTES, 'UTF-8');
        $validade = round(DOWNLOAD_LINK_TTL / 86400);
        $anexo = '<p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#5b616e;">'
               . 'O arquivo tem ' . $mb . ' MB e ficou grande demais para anexo. Baixe pelo link '
               . '(válido por ' . $validade . ' dias):'
               . '</p><p style="margin:12px 0 0;">'
               . '<a href="' . $url . '" style="display:inline-block;background:' . $accent . ';color:#fff;'
               . 'text-decoration:none;padding:12px 24px;border-radius:100px;font-weight:600;font-size:14px;">'
               . 'Baixar relatório</a></p>';
    } elseif ($rowCount > 0) {
        $anexo = '<p style="margin:24px 0 0;font-size:14px;line-height:1.6;color:#5b616e;">'
               . 'O relatório está anexado a esta mensagem.</p>';
    }

    return '<!doctype html><html lang="pt-BR"><body style="margin:0;padding:24px;'
         . 'background:#f5f6f8;font-family:Helvetica,Arial,sans-serif;color:#0a0b0d;">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
         . 'style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e6e8eb;border-radius:12px;">'
         . '<tr><td style="height:4px;background:' . $accent . ';border-radius:12px 12px 0 0;"></td></tr>'
         . '<tr><td style="padding:28px;">'
         . '<h1 style="margin:0 0 6px;font-size:18px;font-weight:600;line-height:1.4;">' . $nome . '</h1>'
         . '<p style="margin:0 0 16px;font-size:13px;color:#8a919e;">Período: ' . $per . ' (BRT)</p>'
         . '<p style="margin:0;font-size:14px;line-height:1.6;color:#5b616e;">' . $corpo . '</p>'
         . $anexo
         . '</td></tr>'
         . '<tr><td style="padding:16px 28px;border-top:1px solid #e6e8eb;font-size:12px;color:#8a919e;">'
         . 'Envio automático do JIMI Tracker. Para alterar a frequência ou os destinatários, '
         . 'acesse Relatórios &rsaquo; Agendamentos.'
         . '</td></tr></table></body></html>';
}

/**
 * Fonte de dados de cada tipo de relatório.
 *
 * @param PDO    $db
 * @param string $type alarms|occurrences|positions|trips|devices|stops|idling|ignition|speeding|fleet_status
 * @param mixed  $cid  customer_id do job
 * @param string $from Data inicial (Y-m-d H:i:s)
 * @param string $to   Data final (Y-m-d H:i:s)
 * @returns array|null [headers, PDOStatement executado, fn(row): array, pesos]
 *                     ou null se o tipo for desconhecido. `pesos` é opcional
 *                     (peso relativo das colunas no PDF; vazio = todas iguais).
 */
function buildReportSource($db, string $type, $cid, string $from, string $to): ?array {
    // Os tipos da v4.6.0 (stops/idling/ignition/speeding/fleet_status) leem as
    // tabelas do state_builder. Duração é apresentada por fmt_duration(), a
    // mesma dos relatórios na tela — export e tela têm de dizer a mesma coisa.
    //
    // ── Padronização por PLACA (v4.9.0) ───────────────────────────
    // Estas listas de coluna são as MESMAS das telas correspondentes: a placa
    // primeiro, sem IMEI e sem Cliente, e o link do mapa no fim. O relatório
    // agendado é o mesmo relatório entregue por e-mail — divergir das telas
    // significava que a mesma pergunta tinha duas respostas com cabeçalhos
    // diferentes, conforme o caminho pelo qual o usuário chegasse a ela.
    //
    // O Cliente sai porque o job É de um cliente (`$cid`): repetir o mesmo
    // nome em toda linha só gastava largura. O IMEI sai porque quem lê o
    // relatório identifica o veículo pela placa — a exceção é `devices`, que
    // é o INVENTÁRIO de equipamentos e ficaria sem o identificador do produto.
    switch ($type) {
        case 'alarms':
            // Nome do alarme resolvido na leitura — ver alarm_label_sql().
            // Até a v4.8.x este relatório imprimia `a.alarm_type` CRU, isto é,
            // o código numérico, sem nem o rótulo genérico que a tela tinha.
            ['joins' => $alarmJoins, 'expr' => $alarmExpr] = alarm_label_sql();
            $stmt = $db->prepare("
                SELECT COALESCE(d.device_name, a.imei) as device_name, $alarmExpr AS alarm_label,
                       a.alarm_time, a.status, a.speed, a.latitude, a.longitude, " . GEO_ADDR_SQL . "
                FROM alarms a
                JOIN devices d ON d.imei = a.imei AND d.customer_id = :cid
                $alarmJoins
                " . geo_join('a.latitude', 'a.longitude') . "
                WHERE a.alarm_time BETWEEN :df AND :dt
                ORDER BY a.alarm_time DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            $statusLabels = ['active' => 'Ativo', 'resolved' => 'Resolvido'];
            return [
                ['Placa', 'Data/Hora', 'Nome do Alarme', 'Status', 'Velocidade (km/h)', 'Endereço', 'Mapa'],
                $stmt,
                fn($r) => [$r['device_name'], fmt_brt($r['alarm_time'], 'd/m/Y H:i:s'),
                           $r['alarm_label'] ?: '—', $statusLabels[$r['status']] ?? $r['status'],
                           $r['speed'], $r['endereco'] ?? '—',
                           export_map_link($r['latitude'], $r['longitude'])],
                [1.0, 1.35, 2.4, 0.8, 0.9, 3.2, 0.6],
            ];

        case 'occurrences':
            // Espelha a grade de /relatorios/ocorrencias e mantém as duas
            // colunas de AUDITORIA que a tela não tem (quem tratou e as notas)
            // — é o que justifica receber esta versão por e-mail.
            $stmt = $db->prepare("
                SELECT COALESCE(dv.device_name, o.imei) AS device_label,
                       COALESCE(dr.name, '—') AS driver_name,
                       o.alarm_type, o.risk, o.status, o.alarm_count, o.false_positive,
                       o.first_alarm_at, o.last_alarm_at, u.name as treated_by, o.treatment_notes
                FROM occurrences o
                LEFT JOIN users u ON u.id = o.treated_by
                LEFT JOIN devices dv ON dv.imei = o.imei
                LEFT JOIN drivers dr ON dr.id = o.driver_id
                WHERE o.customer_id = :cid AND o.first_alarm_at BETWEEN :df AND :dt
                ORDER BY o.first_alarm_at DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            $situacoes = ['aguardando'=>'Aguardando','em_tratativa'=>'Em Tratativa','resolvida'=>'Resolvida','descartada'=>'Descartada'];
            return [
                ['Placa', 'Motorista', 'Tipo de Alarme', 'Risco', 'Situação', 'Falso Positivo',
                 'Qtd Alarmes', 'Primeiro Alarme', 'Último Alarme', 'Tratado por', 'Notas'],
                $stmt,
                fn($r) => [$r['device_label'], $r['driver_name'], $r['alarm_type'], ucfirst((string)$r['risk']),
                           $situacoes[$r['status']] ?? $r['status'], $r['false_positive'] ? 'Sim' : 'Não',
                           $r['alarm_count'],
                           fmt_brt($r['first_alarm_at'], 'd/m/Y H:i:s'), fmt_brt($r['last_alarm_at'], 'd/m/Y H:i:s'),
                           $r['treated_by'] ?? '—', $r['treatment_notes'] ?? ''],
                [1.0, 1.4, 2.0, 0.7, 1.0, 0.9, 0.8, 1.3, 1.3, 1.2, 2.2],
            ];

        case 'positions':
            $stmt = $db->prepare("
                SELECT COALESCE(d.device_name, g.imei) as device_name,
                       g.gps_time, g.speed, g.acc AS ignition, g.battery,
                       g.latitude, g.longitude, " . GEO_ADDR_SQL . "
                FROM gps_data g
                JOIN devices d ON d.imei = g.imei AND d.customer_id = :cid
                " . geo_join('g.latitude', 'g.longitude') . "
                WHERE g.gps_time BETWEEN :df AND :dt AND ABS(g.latitude) > 0.0001 AND ABS(g.longitude) > 0.0001
                ORDER BY g.gps_time DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            return [
                ['Placa', 'Data/Hora', 'Endereço', 'Mapa', 'Velocidade (km/h)', 'Ignição', 'Bateria (%)'],
                $stmt,
                fn($r) => [$r['device_name'], fmt_brt($r['gps_time'], 'd/m/Y H:i:s'), $r['endereco'] ?? '—',
                           export_map_link($r['latitude'], $r['longitude']),
                           $r['speed'], $r['ignition'], $r['battery']],
                [1.0, 1.35, 3.6, 0.6, 0.9, 0.7, 0.8],
            ];

        case 'trips':
            // Duas colunas de mapa no lugar de uma rota (v4.9.0): o percurso
            // real só existe na tela /relatorios/deslocamento/rota, que exige
            // login — e o OSM público não desenha trajeto a partir de URL. Ver
            // a nota em handlers/rel_deslocamento.php.
            $stmt = $db->prepare("
                SELECT t.started_at, t.ended_at, t.duration_s, t.distance_km, t.max_speed, t.alarm_count,
                       t.start_addr, t.end_addr, t.start_lat, t.start_lng, t.end_lat, t.end_lng,
                       COALESCE(d.device_name, t.imei) AS device_label
                FROM trips t
                LEFT JOIN devices d ON d.imei = t.imei
                WHERE t.customer_id = :cid AND t.started_at BETWEEN :df AND :dt
                ORDER BY t.started_at DESC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            return [
                ['Placa', 'Início', 'Fim', 'Duração', 'Distância (km)', 'Vel. Máx (km/h)', 'Qtd Alarmes',
                 'Origem', 'Destino', 'Mapa (partida)', 'Mapa (chegada)'],
                $stmt,
                function($r) {
                    $duration = $r['duration_s'] ? gmdate('H:i:s', $r['duration_s']) : '';
                    return [$r['device_label'], fmt_brt($r['started_at']), $r['ended_at'] ? fmt_brt($r['ended_at']) : '', $duration,
                            $r['distance_km'] ?? '', $r['max_speed'] ?? '', $r['alarm_count'],
                            $r['start_addr'] ?? '', $r['end_addr'] ?? '',
                            export_map_link($r['start_lat'], $r['start_lng'], 'PARTIDA'),
                            export_map_link($r['end_lat'], $r['end_lng'], 'CHEGADA')];
                },
                [1.0, 1.25, 1.25, 0.8, 0.9, 0.95, 0.8, 2.4, 2.4, 0.8, 0.85],
            ];

        case 'devices':
            // Única exceção à regra "sem IMEI": este é o INVENTÁRIO de
            // equipamentos, e o IMEI é o identificador do produto — tirá-lo
            // deixaria a lista sem serventia para quem cuida do parque.
            // Última posição via device_statistics (devices.last_position_at não existe)
            $stmt = $db->prepare("
                SELECT d.imei, d.device_name, dm.model_name, d.is_active, d.last_communication,
                       ds.last_gps_time AS last_position_at, d.camera_count, d.firmware_version
                FROM devices d
                LEFT JOIN device_models dm ON dm.id = d.device_model_id
                LEFT JOIN device_statistics ds ON ds.imei = d.imei
                WHERE d.customer_id = :cid
                ORDER BY d.device_name
            ");
            $stmt->execute([':cid' => $cid]);
            return [
                ['Placa', 'IMEI', 'Modelo', 'Ativo', 'Última Comunicação', 'Última Posição', 'Câmeras', 'Firmware'],
                $stmt,
                fn($r) => [$r['device_name'] ?: $r['imei'], $r['imei'], $r['model_name'] ?? '',
                           $r['is_active'] ? 'Sim' : 'Não', fmt_brt($r['last_communication'], 'd/m/Y H:i', ''),
                           fmt_brt($r['last_position_at'], 'd/m/Y H:i', ''), $r['camera_count'] ?? 0, $r['firmware_version'] ?? ''],
                [1.0, 1.35, 1.2, 0.6, 1.3, 1.3, 0.7, 1.1],
            ];

        // ── v4.6.0 — recortes de device_state_segments ────────────────
        case 'stops':
        case 'idling':
            $state = $type === 'stops' ? 'parado' : 'ocioso';
            $stmt = $db->prepare("
                SELECT COALESCE(d.device_name, s.imei) AS device_name,
                       s.started_at, s.ended_at, s.start_lat, s.start_lng,
                       COALESCE(s.duration_s, TIMESTAMPDIFF(SECOND, s.started_at, UTC_TIMESTAMP())) AS dur_s,
                       " . GEO_ADDR_SQL . "
                FROM device_state_segments s
                LEFT JOIN devices d ON d.imei = s.imei
                " . geo_join('s.start_lat', 's.start_lng') . "
                WHERE s.customer_id = :cid AND s.state = :state AND s.started_at BETWEEN :df AND :dt
                ORDER BY s.started_at ASC
            ");
            $stmt->execute([':cid' => $cid, ':state' => $state, ':df' => $from, ':dt' => $to]);
            return [
                ['Placa', 'Início', 'Fim', 'Duração', 'Endereço', 'Mapa'],
                $stmt,
                fn($r) => [$r['device_name'],
                           fmt_brt($r['started_at'], 'd/m/Y H:i:s'),
                           $r['ended_at'] ? fmt_brt($r['ended_at'], 'd/m/Y H:i:s') : 'Em curso',
                           fmt_duration((int)$r['dur_s']), $r['endereco'] ?? '—',
                           export_map_link($r['start_lat'], $r['start_lng'])],
                [1.0, 1.35, 1.35, 0.9, 3.4, 0.6],
            ];

        case 'ignition':
            // Mesma derivação por LAG do handlers/rel_ignicao.php: transição é
            // a fronteira entre segmentos cujo estado do MOTOR difere, com os
            // segmentos offline fora da janela.
            $stmt = $db->prepare("
                SELECT t.*, CASE WHEN t.state = 'parado' THEN 'Ignição desligada' ELSE 'Ignição ligada' END AS event_label,
                       " . GEO_ADDR_SQL . "
                FROM (
                    SELECT s.imei, COALESCE(d.device_name, s.imei) AS device_name,
                           s.state, s.started_at, s.start_lat, s.start_lng,
                           COALESCE(s.duration_s, TIMESTAMPDIFF(SECOND, s.started_at, UTC_TIMESTAMP())) AS dur_s,
                           LAG(s.state) OVER (PARTITION BY s.imei ORDER BY s.started_at) AS prev_state
                    FROM device_state_segments s
                    LEFT JOIN devices d ON d.imei = s.imei
                    WHERE s.customer_id = :cid AND s.state <> 'offline'
                      AND s.started_at BETWEEN DATE_SUB(:df, INTERVAL 2 DAY) AND :dt
                ) t
                " . geo_join('t.start_lat', 't.start_lng') . "
                WHERE t.prev_state IS NOT NULL
                  AND (t.state = 'parado') <> (t.prev_state = 'parado')
                  AND t.started_at BETWEEN :df2 AND :dt2
                ORDER BY t.started_at ASC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to, ':df2' => $from, ':dt2' => $to]);
            return [
                ['Placa', 'Data/Hora', 'Evento', 'Permanência no estado', 'Endereço', 'Mapa'],
                $stmt,
                fn($r) => [$r['device_name'], fmt_brt($r['started_at'], 'd/m/Y H:i:s'),
                           $r['event_label'], fmt_duration((int)$r['dur_s']), $r['endereco'] ?? '—',
                           export_map_link($r['start_lat'], $r['start_lng'])],
                [1.0, 1.35, 1.2, 1.2, 3.4, 0.6],
            ];

        case 'speeding':
            $stmt = $db->prepare("
                SELECT COALESCE(d.device_name, e.imei) AS device_name,
                       e.started_at, e.ended_at,
                       COALESCE(e.duration_s, TIMESTAMPDIFF(SECOND, e.started_at, UTC_TIMESTAMP())) AS dur_s,
                       e.max_speed, e.avg_speed, e.limit_kmh,
                       e.max_lat, e.max_lng, e.start_lat, e.start_lng, " . GEO_ADDR_SQL . "
                FROM speeding_events e
                LEFT JOIN devices d ON d.imei = e.imei
                " . geo_join('e.max_lat', 'e.max_lng') . "
                WHERE e.customer_id = :cid AND e.started_at BETWEEN :df AND :dt
                ORDER BY e.started_at ASC
            ");
            $stmt->execute([':cid' => $cid, ':df' => $from, ':dt' => $to]);
            return [
                ['Placa', 'Início', 'Fim', 'Duração', 'Vel. máxima (km/h)',
                 'Vel. média (km/h)', 'Limite (km/h)', 'Excedente (km/h)', 'Endereço', 'Mapa'],
                $stmt,
                fn($r) => [$r['device_name'],
                           fmt_brt($r['started_at'], 'd/m/Y H:i:s'),
                           $r['ended_at'] ? fmt_brt($r['ended_at'], 'd/m/Y H:i:s') : 'Em curso',
                           fmt_duration((int)$r['dur_s']),
                           $r['max_speed'], $r['avg_speed'] ?? '', (int)$r['limit_kmh'],
                           number_format((float)$r['max_speed'] - (int)$r['limit_kmh'], 1, ',', ''),
                           $r['endereco'] ?? '—',
                           // O ponto é onde a velocidade MÁXIMA foi registrada,
                           // como na tela — não o início do evento.
                           export_map_link($r['max_lat'] ?: $r['start_lat'], $r['max_lng'] ?: $r['start_lng'])],
                [1.0, 1.35, 1.35, 0.9, 1.0, 1.0, 0.9, 1.0, 3.2, 0.6],
            ];

        case 'fleet_status':
            // Foto do agora: o período do job é ignorado de propósito. O estado
            // é resolvido em PHP por resolve_current_state() — a mesma função da
            // tela — porque o segmento aberto não sabe do silêncio posterior.
            $stmt = $db->prepare("
                SELECT d.imei, d.device_name, ds.last_gps_time,
                       ds.last_speed, ds.last_latitude, ds.last_longitude,
                       s.state AS seg_state, s.started_at AS seg_started_at,
                       " . GEO_ADDR_SQL . "
                FROM devices d
                LEFT JOIN device_statistics ds ON ds.imei = d.imei
                LEFT JOIN device_state_segments s ON s.imei = d.imei AND s.ended_at IS NULL
                " . geo_join('ds.last_latitude', 'ds.last_longitude') . "
                WHERE d.customer_id = :cid AND d.is_active = 1
                ORDER BY d.device_name, d.imei
            ");
            $stmt->execute([':cid' => $cid]);
            $nowUtc = gmdate('Y-m-d H:i:s');
            return [
                ['Placa', 'Estado', 'Tempo no estado', 'Última posição',
                 'Velocidade (km/h)', 'Endereço', 'Mapa'],
                $stmt,
                function ($r) use ($nowUtc) {
                    $state = resolve_current_state($r['seg_state'], $r['last_gps_time'], $nowUtc);
                    $since = $state === 'offline' ? $r['last_gps_time'] : ($r['seg_started_at'] ?: $r['last_gps_time']);
                    $inState = $since ? max(0, strtotime($nowUtc) - strtotime($since)) : null;
                    return [$r['device_name'] ?: $r['imei'], fleet_state_label($state),
                            fmt_duration($inState), fmt_brt($r['last_gps_time'], 'd/m/Y H:i:s', 'Nunca'),
                            $r['last_speed'] ?? '', $r['endereco'] ?? '—',
                            export_map_link($r['last_latitude'], $r['last_longitude'])];
                },
                [1.0, 1.0, 1.0, 1.35, 0.95, 3.4, 0.6],
            ];
    }
    return null;
}

function processVideoJob($db, $job): array {
    $params = json_decode($job['params'] ?? '{}', true);
    $dir = __DIR__ . '/../storage/media';
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => ($params['url'] ?? ''),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $data = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['status' => 'falhou', 'error' => $err];

    // Token aleatório pelo mesmo motivo do relatório (ver processReportJob):
    // storage/ é servido como estático, e vídeo de ocorrência é dado de cliente.
    $filename = 'video_' . $job['id'] . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(16)) . '.mp4';
    file_put_contents($dir . '/' . $filename, $data);

    return ['status' => 'concluido', 'path' => 'storage/media/' . $filename];
}

function processRollupJob($db, $job): array {
    return ['status' => 'concluido', 'path' => null, 'error' => null];
}

/**
 * Envia por e-mail uma notificação enfileirada pelo notification_engine.
 *
 * @param PDO   $db  Conexão ativa
 * @param array $job Linha da fila (params com to/subject/title/body/link_url)
 * @returns array{status:string,path:?string,error:?string}
 */
function processNotificationJob($db, $job): array {
    $p  = json_decode($job['params'] ?? '{}', true) ?: [];
    $to = $p['to'] ?? [];
    // Cliente do job decide quais credenciais SMTP valem (cliente → global → .env)
    $cid = isset($job['customer_id']) ? (int)$job['customer_id'] : null;

    if (!is_array($to) || empty($to)) {
        return ['status' => 'falhou', 'error' => 'Sem destinatários'];
    }
    if (!mail_is_configured($cid)) {
        return ['status' => 'falhou', 'error' => 'Servidor SMTP não cadastrado (Cadastros › Servidor de E-mail)'];
    }

    $result = send_mail(
        $to,
        (string)($p['subject'] ?? 'Notificação'),
        buildNotificationEmailHtml($p),
        [],
        $cid
    );

    if ($result['ok']) {
        return ['status' => 'concluido', 'path' => null, 'error' => null];
    }
    return ['status' => 'falhou', 'error' => $result['error']];
}

/**
 * Monta o HTML do e-mail de notificação.
 *
 * HTML inline e tabela simples de propósito: cliente de e-mail não tem
 * cascata de CSS confiável nem suporte a flexbox.
 *
 * @param array $p Params do job
 * @returns string HTML completo
 */
function buildNotificationEmailHtml(array $p): string {
    $accent = [
        'critical' => '#cf202f',
        'warning'  => '#a97a00',
        'info'     => '#0052ff',
    ][$p['severity'] ?? 'info'] ?? '#0052ff';

    $title = htmlspecialchars((string)($p['title'] ?? 'Notificação'), ENT_QUOTES, 'UTF-8');
    $body  = htmlspecialchars((string)($p['body'] ?? ''), ENT_QUOTES, 'UTF-8');

    // Sem APP_URL o botão viraria um href relativo ("/agendamentos"), que em
    // cliente de e-mail não resolve para lugar nenhum. Melhor omitir o botão do
    // que exibir um que não funciona — título e corpo já carregam a informação.
    // Aqui não se pode falhar o envio, ao contrário do relatório entregue por
    // link: lá a URL É o conteúdo; aqui é só um atalho.
    $link = '';
    $base = rtrim((string)(getenv('APP_URL') ?: ''), '/');
    if (!empty($p['link_url']) && $base !== '') {
        $url  = htmlspecialchars($base . $p['link_url'], ENT_QUOTES, 'UTF-8');
        $link = '<p style="margin:24px 0 0;">'
              . '<a href="' . $url . '" style="display:inline-block;background:' . $accent . ';color:#fff;'
              . 'text-decoration:none;padding:12px 24px;border-radius:100px;font-weight:600;font-size:14px;">'
              . 'Abrir no sistema</a></p>';
    }

    return '<!doctype html><html lang="pt-BR"><body style="margin:0;padding:24px;'
         . 'background:#f5f6f8;font-family:Helvetica,Arial,sans-serif;color:#0a0b0d;">'
         . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" '
         . 'style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e6e8eb;border-radius:12px;">'
         . '<tr><td style="height:4px;background:' . $accent . ';border-radius:12px 12px 0 0;"></td></tr>'
         . '<tr><td style="padding:28px;">'
         . '<h1 style="margin:0 0 12px;font-size:18px;font-weight:600;line-height:1.4;">' . $title . '</h1>'
         . '<p style="margin:0;font-size:14px;line-height:1.6;color:#5b616e;">' . $body . '</p>'
         . $link
         . '</td></tr>'
         . '<tr><td style="padding:16px 28px;border-top:1px solid #e6e8eb;font-size:12px;color:#8a919e;">'
         . 'Mensagem automática do JIMI Tracker. Para deixar de receber, ajuste as regras em '
         . 'Cadastros &rsaquo; Config. Notificações.'
         . '</td></tr></table></body></html>';
}
