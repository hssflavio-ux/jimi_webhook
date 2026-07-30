<?php
/**
 * JIMI Webhook System — Schedule Dispatcher v4.7.0
 * Script: scripts/schedule_dispatcher.php
 *
 * Cron (`5 * * * *`) que enfileira os relatórios agendados que venceram.
 * Não gera nada: só cria o job. Quem gera o arquivo e envia o e-mail é o
 * scripts/worker.php — a mesma separação que vale para as notificações.
 *
 * Uso:
 *   php scripts/schedule_dispatcher.php          # normal (cron)
 *   php scripts/schedule_dispatcher.php --dry    # mostra o que faria, sem gravar
 *
 * ── Reentrância ────────────────────────────────────────────────────────────
 * `next_run_at` é recalculado e gravado ANTES de o job ser enfileirado, dentro
 * de um UPDATE condicionado ao valor antigo. Se o cron atrasar e dois
 * processos coincidirem, o UPDATE do segundo afeta 0 linhas e ele desiste —
 * quem "ganha" a linha é quem conseguiu movê-la. Sem isso, um cron sobreposto
 * mandaria o mesmo relatório duas vezes, que é o defeito que o usuário percebe
 * primeiro e perdoa por último.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/schedule.php';
require_once __DIR__ . '/../includes/notification_engine.php';

$dryRun = in_array('--dry', $argv ?? [], true);

$db = Database::getInstance()->getConnection();

try {
    $db->query("SELECT 1 FROM report_schedules LIMIT 1");
} catch (Throwable $e) {
    fwrite(STDERR, "Schedule Dispatcher: tabelas indisponíveis — aplique a migração v4.7.0.\n");
    Logger::error('Schedule Dispatcher: tabelas indisponíveis', ['error' => $e->getMessage()]);
    exit(1);
}

$nowUtc = gmdate('Y-m-d H:i:s');

// next_run_at NULL = agendamento recém-criado que ainda não foi agendado;
// tratado como vencido para que o primeiro envio não espere um ciclo inteiro.
$stmt = $db->prepare("
    SELECT * FROM report_schedules
    WHERE is_active = 1 AND (next_run_at IS NULL OR next_run_at <= :now)
    ORDER BY next_run_at IS NULL DESC, next_run_at ASC
    LIMIT 200");
$stmt->execute([':now' => $nowUtc]);
$due = $stmt->fetchAll();

if (!$due) {
    echo "Schedule Dispatcher: nenhum agendamento vencido.\n";
    exit(0);
}

$queued  = 0;
$skipped = 0;

foreach ($due as $sch) {
    $id      = (int)$sch['id'];
    $nextRun = schedule_next_run($sch, $nowUtc);

    if ($dryRun) {
        printf("  [dry] #%d %-30s → próximo: %s UTC\n", $id, substr((string)$sch['name'], 0, 30), $nextRun);
        continue;
    }

    // ── Guarda de reentrância ──────────────────────────────────
    // O UPDATE só pega se `next_run_at` ainda estiver como foi lido. Dois
    // dispatchers simultâneos: um move a linha, o outro afeta 0 e desiste.
    if ($sch['next_run_at'] === null) {
        $claim = $db->prepare("
            UPDATE report_schedules
            SET next_run_at = :next, last_run_at = :now
            WHERE id = :id AND next_run_at IS NULL AND is_active = 1");
        $claim->execute([':next' => $nextRun, ':now' => $nowUtc, ':id' => $id]);
    } else {
        $claim = $db->prepare("
            UPDATE report_schedules
            SET next_run_at = :next, last_run_at = :now
            WHERE id = :id AND next_run_at = :prev AND is_active = 1");
        $claim->execute([
            ':next' => $nextRun, ':now' => $nowUtc,
            ':id' => $id, ':prev' => $sch['next_run_at'],
        ]);
    }

    if ($claim->rowCount() === 0) {
        $skipped++;
        Logger::info('Schedule Dispatcher: agendamento já reivindicado por outro processo', ['schedule_id' => $id]);
        continue;
    }

    try {
        $queued += dispatchSchedule($db, $sch, $nowUtc);
    } catch (Throwable $e) {
        Logger::error('Schedule Dispatcher: falha ao enfileirar', [
            'schedule_id' => $id,
            'error'       => $e->getMessage(),
        ]);
        registerScheduleFailure($db, $sch, 'Falha ao enfileirar: ' . $e->getMessage());
    }
}

echo "Schedule Dispatcher: {$queued} job(s) enfileirado(s), {$skipped} já reivindicado(s).\n";

/**
 * Cria a execução e o job de um agendamento vencido.
 *
 * @param PDO    $db     Conexão ativa
 * @param array  $sch    Linha de report_schedules (já reivindicada)
 * @param string $nowUtc Instante de referência
 * @returns int 1 se enfileirou
 */
function dispatchSchedule(PDO $db, array $sch, string $nowUtc): int
{
    $freq = (string)($sch['frequency'] ?? 'diaria');

    // O período é do calendário BRT do usuário e vira janela UTC para o SQL —
    // mesma conversão que toda tela de relatório faz.
    [$fromDay, $toDay] = schedule_period_days($freq);
    [$utcFrom, $utcTo] = brt_day_range_to_utc($fromDay, $toDay);

    $filters = json_decode((string)($sch['filters'] ?? '{}'), true);
    if (!is_array($filters)) {
        $filters = [];
    }

    $recipients = schedule_parse_recipients(json_decode((string)$sch['recipients'], true) ?: []);
    if (!$recipients) {
        // Agendamento sem destinatário válido não tem como cumprir seu
        // propósito; contar como falha o desativa após 3 ciclos em vez de
        // gerar arquivo para ninguém, para sempre.
        registerScheduleFailure($db, $sch, 'Nenhum destinatário válido cadastrado');
        return 0;
    }

    $db->prepare("
        INSERT INTO report_schedule_runs (schedule_id, status, period_from, period_to)
        VALUES (:sid, 'enfileirado', :pf, :pt)")
       ->execute([':sid' => (int)$sch['id'], ':pf' => $utcFrom, ':pt' => $utcTo]);
    $runId = (int)$db->lastInsertId();

    $format = in_array($sch['format'] ?? 'xlsx', ['csv', 'xlsx', 'pdf'], true) ? $sch['format'] : 'xlsx';

    $params = $filters + [
        'report_name'   => (string)$sch['name'],
        'report_type'   => (string)$sch['report_type'],
        'date_from'     => $fromDay,
        'date_to'       => $toDay,
        'format'        => $format,
        // É este par de chaves que faz o worker enviar o e-mail em vez de
        // apenas deixar o arquivo em /exportar.
        'deliver_email' => $recipients,
        'schedule_id'   => (int)$sch['id'],
        'skip_if_empty' => (int)($sch['skip_if_empty'] ?? 0) === 1,
        'periodo_label' => schedule_period_label($freq, $fromDay, $toDay),
    ];

    $db->prepare("
        INSERT INTO jobs (type, format, customer_id, params, status, requested_by, schedule_run_id)
        VALUES ('report', :fmt, :cid, :params, 'pendente', :uid, :run)")
       ->execute([
           ':fmt'    => $format,
           ':cid'    => (int)$sch['customer_id'],
           ':params' => json_encode($params, JSON_UNESCAPED_UNICODE),
           ':uid'    => $sch['user_id'] !== null ? (int)$sch['user_id'] : null,
           ':run'    => $runId,
       ]);
    $jobId = (int)$db->lastInsertId();

    $db->prepare("UPDATE report_schedule_runs SET job_id = :jid WHERE id = :id")
       ->execute([':jid' => $jobId, ':id' => $runId]);

    Logger::info('Schedule Dispatcher: relatório enfileirado', [
        'schedule_id' => (int)$sch['id'],
        'job_id'      => $jobId,
        'periodo'     => "$fromDay a $toDay",
        'destinos'    => count($recipients),
    ]);

    return 1;
}

/**
 * Rótulo humano do período coberto, para o corpo do e-mail.
 *
 * @param string $freq    diaria|semanal|mensal
 * @param string $fromDay Y-m-d BRT
 * @param string $toDay   Y-m-d BRT
 * @returns string
 */
function schedule_period_label(string $freq, string $fromDay, string $toDay): string
{
    $f = date('d/m/Y', strtotime($fromDay));
    $t = date('d/m/Y', strtotime($toDay));
    if ($freq === 'diaria' || $fromDay === $toDay) {
        return $f;
    }
    return "$f a $t";
}

/**
 * Contabiliza uma falha do agendamento e o desativa na terceira consecutiva.
 *
 * Tentar para sempre contra um e-mail inválido enche o log, a fila e a caixa
 * de ninguém. Desativar e avisar o criador devolve o problema a quem pode
 * resolvê-lo.
 *
 * @param PDO    $db    Conexão ativa
 * @param array  $sch   Linha de report_schedules
 * @param string $error Motivo
 * @returns void
 */
function registerScheduleFailure(PDO $db, array $sch, string $error): void
{
    $id = (int)$sch['id'];

    $db->prepare("UPDATE report_schedules SET fail_count = fail_count + 1 WHERE id = :id")
       ->execute([':id' => $id]);

    $count = (int)$db->query("SELECT fail_count FROM report_schedules WHERE id = $id")->fetchColumn();

    $db->prepare("
        INSERT INTO report_schedule_runs (schedule_id, status, error_message)
        VALUES (:sid, 'falhou', :err)")
       ->execute([':sid' => $id, ':err' => $error]);

    if ($count < SCHEDULE_MAX_FAILURES) {
        return;
    }

    $db->prepare("UPDATE report_schedules SET is_active = 0 WHERE id = :id")->execute([':id' => $id]);

    notify($db, [
        'customer_id' => (int)$sch['customer_id'],
        'user_id'     => $sch['user_id'] !== null ? (int)$sch['user_id'] : null,
        'kind'        => 'sistema',
        'severity'    => 'warning',
        'title'       => 'Agendamento desativado: ' . (string)$sch['name'],
        'body'        => SCHEDULE_MAX_FAILURES . ' execuções falharam em sequência. Último erro: ' . mb_substr($error, 0, 200),
        'link_url'    => '/agendamentos',
        'ref_type'    => 'report_schedule',
        'ref_id'      => $id,
        'want_popup'  => 0,
        'want_sound'  => 0,
        'dedupe_key'  => 'sched|' . $id,
    ]);

    Logger::warning('Schedule Dispatcher: agendamento desativado após falhas consecutivas', [
        'schedule_id' => $id,
        'fail_count'  => $count,
        'error'       => $error,
    ]);
}
