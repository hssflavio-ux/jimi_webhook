<?php
/**
 * JIMI Webhook System — Maintenance Worker v4.10.1
 * Script: scripts/maintenance_worker.php
 *
 * Cron diário (item 3 do docs/PLANO_IMPLEMENTACAO_v4.10.md) que varre
 * `maintenance_reminders` ativos e `drivers` com lembrete de documento
 * ligado, e notifica (`includes/notification_engine.php`, kind='lembrete')
 * quem estiver em `proximo` ou `vencido` (`includes/maintenance.php`).
 *
 * Dedupe por dia: `last_notified_at`/`cnh_notified_at`/`tox_notified_at` (DATE)
 * — sem isso, uma execução diária notificaria de novo a cada rodada enquanto
 * o item continuasse vencido, e notify() não dedupe o SINO (só o e-mail,
 * numa janela curta). Ver cabeçalho de mysql/migration_v4.10.1.sql.
 *
 * Reexecução no mesmo dia é segura: a segunda rodada não notifica de novo
 * porque `last_notified_at = CURDATE()` já bloqueia o UPDATE seguinte.
 *
 * Uso: php scripts/maintenance_worker.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/maintenance.php';
require_once __DIR__ . '/../includes/notification_engine.php';

$db = Database::getInstance()->getConnection();
$today = date('Y-m-d');
$notified = 0;

// ── 1. Lembretes de manutenção (odômetro/horas/horímetro/data) ─────────────
try {
    $reminders = $db->query("
        SELECT r.*, d.device_name
        FROM maintenance_reminders r
        LEFT JOIN devices d ON d.imei = r.imei
        WHERE r.is_active = 1
          AND (r.last_notified_at IS NULL OR r.last_notified_at < CURDATE())
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "Maintenance Worker: tabela indisponível — aplique a migração v4.10.1.\n");
    Logger::error('Maintenance Worker: tabela indisponível', ['error' => $e->getMessage()]);
    exit(1);
}

foreach ($reminders as $r) {
    $progress = maintenance_reminder_progress($db, $r);
    if (!in_array($progress['status'], ['proximo', 'vencido'], true)) {
        continue;
    }

    $vinculo = $r['device_name'] ? placa_do_device($r['device_name'], $r['imei']) : $r['name'];
    $emails = !empty($r['notify_email']) ? (json_decode((string)($r['emails'] ?? '[]'), true) ?: []) : [];

    // `notify()` (includes/notification_engine.php) sempre grava o SINO —
    // não há como pedir "só e-mail, sem sino" ao motor compartilhado. Com
    // notify_bell desligado e sem e-mail configurado, não há nada a fazer.
    if (empty($r['notify_bell']) && empty($emails)) {
        continue;
    }

    $id = notify($db, [
        'customer_id' => (int)$r['customer_id'],
        'kind'        => 'lembrete',
        'severity'    => $progress['status'] === 'vencido' ? 'critical' : 'warning',
        'title'       => ($progress['status'] === 'vencido' ? 'Manutenção vencida: ' : 'Manutenção próxima: ') . $r['name'],
        'body'        => $vinculo . ' — ' . MAINTENANCE_METRIC_LABELS[$r['metric']] . ': '
                        . $progress['current_label'] . ' (vencimento ' . $progress['due_label'] . ').',
        'link_url'    => '/manutencoes',
        'ref_type'    => 'maintenance_reminder',
        'ref_id'      => (int)$r['id'],
        'want_popup'  => $progress['status'] === 'vencido' ? 1 : 0,
        'emails'      => $emails,
        'dedupe_key'  => 'manut|' . $r['id'] . '|' . $today,
    ]);

    if ($id !== null) {
        $db->prepare("UPDATE maintenance_reminders SET last_notified_at = CURDATE() WHERE id = ?")
           ->execute([$r['id']]);
        $notified++;
    }
}

// ── 2. Documentos do motorista (CNH / toxicológico) ─────────────────────────
try {
    $drivers = $db->query("
        SELECT id, customer_id, name, cnh_expires_at, tox_exam_expires_at,
               remind_cnh, remind_tox, cnh_notified_at, tox_notified_at
        FROM drivers
        WHERE is_active = 1 AND (remind_cnh = 1 OR remind_tox = 1)
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $drivers = [];
}

foreach ($drivers as $d) {
    if (!empty($d['remind_cnh']) && !empty($d['cnh_expires_at'])
        && ($d['cnh_notified_at'] === null || $d['cnh_notified_at'] < $today)) {
        $days = (int)floor((strtotime($d['cnh_expires_at']) - strtotime($today)) / 86400);
        if ($days <= MAINTENANCE_DUE_DAYS) {
            $id = notify($db, [
                'customer_id' => (int)$d['customer_id'],
                'kind'        => 'lembrete',
                'severity'    => $days <= 0 ? 'critical' : 'warning',
                'title'       => ($days <= 0 ? 'CNH vencida: ' : 'CNH próxima do vencimento: ') . $d['name'],
                'body'        => 'Vencimento em ' . date('d/m/Y', strtotime($d['cnh_expires_at'])) . '.',
                'link_url'    => '/manutencoes?tab=documentos',
                'ref_type'    => 'driver_cnh',
                'ref_id'      => (int)$d['id'],
                'want_popup'  => $days <= 0 ? 1 : 0,
                'dedupe_key'  => 'cnh|' . $d['id'] . '|' . $today,
            ]);
            if ($id !== null) {
                $db->prepare("UPDATE drivers SET cnh_notified_at = CURDATE() WHERE id = ?")->execute([$d['id']]);
                $notified++;
            }
        }
    }

    if (!empty($d['remind_tox']) && !empty($d['tox_exam_expires_at'])
        && ($d['tox_notified_at'] === null || $d['tox_notified_at'] < $today)) {
        $days = (int)floor((strtotime($d['tox_exam_expires_at']) - strtotime($today)) / 86400);
        if ($days <= MAINTENANCE_DUE_DAYS) {
            $id = notify($db, [
                'customer_id' => (int)$d['customer_id'],
                'kind'        => 'lembrete',
                'severity'    => $days <= 0 ? 'critical' : 'warning',
                'title'       => ($days <= 0 ? 'Exame toxicológico vencido: ' : 'Exame toxicológico próximo do vencimento: ') . $d['name'],
                'body'        => 'Vencimento em ' . date('d/m/Y', strtotime($d['tox_exam_expires_at'])) . '.',
                'link_url'    => '/manutencoes?tab=documentos',
                'ref_type'    => 'driver_tox',
                'ref_id'      => (int)$d['id'],
                'want_popup'  => $days <= 0 ? 1 : 0,
                'dedupe_key'  => 'tox|' . $d['id'] . '|' . $today,
            ]);
            if ($id !== null) {
                $db->prepare("UPDATE drivers SET tox_notified_at = CURDATE() WHERE id = ?")->execute([$d['id']]);
                $notified++;
            }
        }
    }
}

echo "Maintenance Worker: {$notified} notificação(ões) enviada(s).\n";
