<?php
/**
 * JIMI Webhook System — Manutenção Preventiva v4.10.1
 * Arquivo: includes/maintenance.php
 *
 * Fonte única de cálculo de progresso dos lembretes de `maintenance_reminders`
 * (item 3 do docs/PLANO_IMPLEMENTACAO_v4.10.md), compartilhada entre a tela
 * (`handlers/manutencoes.php`) e o worker diário (`scripts/maintenance_worker.php`)
 * — mesmo padrão de `includes/fleet_state.php` para o estado da frota: uma
 * única definição de "está vencido?" evita que tela e worker discordem.
 */

/** Odômetro remanescente, em km, a partir do qual o item entra em "próximo". */
const MAINTENANCE_DUE_KM = 200.0;

/**
 * Horas remanescentes a partir das quais o item entra em "próximo".
 *
 * O plano não fixou N; 10h foi escolhido por ser a ordem de grandeza de um
 * turno e meio de operação — dá pelo menos um dia de folga para agendar a
 * oficina antes do vencimento efetivo.
 */
const MAINTENANCE_DUE_HOURS = 10.0;

/** Dias remanescentes a partir dos quais o item entra em "próximo". */
const MAINTENANCE_DUE_DAYS = 7;

/** Rótulos de exibição de cada métrica. */
const MAINTENANCE_METRIC_LABELS = [
    'odometro'      => 'Odômetro',
    'horas_ignicao' => 'Horas de Ignição',
    'horimetro'     => 'Horímetro',
    'data'          => 'Data',
];

/** Rótulos e cor de cada status calculado. */
const MAINTENANCE_STATUS_LABELS = [
    'ok'       => 'Em dia',
    'proximo'  => 'Próximo do vencimento',
    'vencido'  => 'Vencido',
    'sem_dado' => 'Sem dado suficiente',
];
const MAINTENANCE_STATUS_COLORS = [
    'ok'       => '#0a7d3c',
    'proximo'  => '#a97a00',
    'vencido'  => '#cf202f',
    'sem_dado' => '#7c828a',
];

/**
 * Último odômetro conhecido do equipamento.
 *
 * @param PDO    $db   Conexão ativa
 * @param string $imei Equipamento
 * @returns float|null Km, ou null se não há ponto com odômetro válido
 */
function latest_odometer(PDO $db, string $imei): ?float
{
    try {
        $stmt = $db->prepare("
            SELECT mileage FROM gps_data
            WHERE imei = :imei AND mileage > 0
            ORDER BY gps_time DESC LIMIT 1
        ");
        $stmt->execute([':imei' => $imei]);
        $v = $stmt->fetchColumn();
        return $v !== false ? (float)$v : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Horas em `movimento`/`ocioso` (motor ligado) desde uma referência.
 *
 * É a acumulação usada pela métrica `horas_ignicao`: soma de
 * `device_state_segments.duration_s`, não o odômetro nem o horímetro do
 * equipamento — por isso funciona em qualquer device, mesmo sem `engine_hours`.
 *
 * @param PDO         $db    Conexão ativa
 * @param string      $imei  Equipamento
 * @param string|null $since UTC de referência (NULL = soma tudo que houver)
 * @returns float|null Horas, ou null se a tabela de segmentos não existir
 */
function ignition_hours_since(PDO $db, string $imei, ?string $since): ?float
{
    try {
        $sql = "
            SELECT COALESCE(SUM(duration_s), 0) FROM device_state_segments
            WHERE imei = :imei AND state IN ('movimento', 'ocioso')
              AND ended_at IS NOT NULL
        ";
        $params = [':imei' => $imei];
        if ($since !== null) {
            $sql .= " AND started_at >= :since";
            $params[':since'] = $since;
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return ((float)$stmt->fetchColumn()) / 3600.0;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Progresso de um lembrete: valor atual, valor de vencimento e status.
 *
 * A mesma função serve à tela (exibição) e ao worker (decidir se notifica) —
 * ver o comentário de cabeçalho deste arquivo.
 *
 * @param PDO         $db     Conexão ativa
 * @param array       $r      Linha de `maintenance_reminders`
 * @param string|null $nowUtc UTC de referência (default: agora)
 * @returns array{status:string, remaining:float|null, current_label:string, due_label:string}
 */
function maintenance_reminder_progress(PDO $db, array $r, ?string $nowUtc = null): array
{
    $nowUtc = $nowUtc ?? gmdate('Y-m-d H:i:s');
    $status = 'sem_dado';
    $remaining = null;
    $currentLabel = '—';
    $dueLabel = '—';

    switch ($r['metric']) {
        case 'odometro':
            $current = $r['imei'] ? latest_odometer($db, $r['imei']) : null;
            $interval = (float)($r['interval_km'] ?? 0);
            if ($current !== null) {
                $currentLabel = number_format($current, 0, ',', '.') . ' km';
            }
            // O baseline TEM de vir de `last_done_km` — nunca de `$current`.
            // `latest_odometer()` muda a cada ponto de GPS novo; se o baseline
            // reacompanhasse o odômetro atual, "vencimento" seria sempre
            // "daqui a `interval_km`" e o item NUNCA venceria. O chamador
            // (handlers/manutencoes.php) grava o baseline uma vez, na criação.
            if ($interval > 0 && $r['last_done_km'] !== null) {
                $baseline = (float)$r['last_done_km'];
                $due = $baseline + $interval;
                $dueLabel = number_format($due, 0, ',', '.') . ' km';
                if ($current !== null) {
                    $remaining = $due - $current;
                    $status = $remaining <= 0 ? 'vencido' : ($remaining <= MAINTENANCE_DUE_KM ? 'proximo' : 'ok');
                }
            }
            break;

        case 'horas_ignicao':
            $since = $r['last_done_at'] ?? $r['created_at'] ?? null;
            $current = $r['imei'] ? ignition_hours_since($db, $r['imei'], $since) : null;
            $interval = (float)($r['interval_hours'] ?? 0);
            if ($current !== null) {
                $currentLabel = number_format($current, 1, ',', '.') . ' h desde a última';
            }
            if ($interval > 0) {
                $dueLabel = number_format($interval, 1, ',', '.') . ' h';
                if ($current !== null) {
                    $remaining = $interval - $current;
                    $status = $remaining <= 0 ? 'vencido' : ($remaining <= MAINTENANCE_DUE_HOURS ? 'proximo' : 'ok');
                }
            }
            break;

        case 'horimetro':
            $current = null;
            if ($r['imei']) {
                try {
                    $stmt = $db->prepare("SELECT engine_hours FROM devices WHERE imei = :imei LIMIT 1");
                    $stmt->execute([':imei' => $r['imei']]);
                    $v = $stmt->fetchColumn();
                    $current = ($v !== false && $v !== null) ? (float)$v : null;
                } catch (Throwable $e) {
                    $current = null;
                }
            }
            $interval = (float)($r['interval_hours'] ?? 0);
            if ($current !== null) {
                $currentLabel = number_format($current, 1, ',', '.') . ' h';
            }
            // Mesma razão do caso 'odometro': baseline só de `last_done_hours`.
            if ($interval > 0 && $r['last_done_hours'] !== null) {
                $baseline = (float)$r['last_done_hours'];
                $due = $baseline + $interval;
                $dueLabel = number_format($due, 1, ',', '.') . ' h';
                if ($current !== null) {
                    $remaining = $due - $current;
                    $status = $remaining <= 0 ? 'vencido' : ($remaining <= MAINTENANCE_DUE_HOURS ? 'proximo' : 'ok');
                }
            }
            break;

        case 'data':
            if (!empty($r['due_date'])) {
                $today = substr($nowUtc, 0, 10);
                $remaining = (strtotime($r['due_date']) - strtotime($today)) / 86400;
                $dueLabel = date('d/m/Y', strtotime($r['due_date']));
                $currentLabel = date('d/m/Y', strtotime($today));
                $status = $remaining <= 0 ? 'vencido' : ($remaining <= MAINTENANCE_DUE_DAYS ? 'proximo' : 'ok');
            }
            break;
    }

    return [
        'status'        => $status,
        'remaining'     => $remaining,
        'current_label' => $currentLabel,
        'due_label'     => $dueLabel,
    ];
}

/**
 * Grava `engine_hours` a partir do horímetro reportado pelo equipamento.
 *
 * Só grava valores positivos: um campo ausente no payload chega aqui como
 * `null`/`0`, e sobrescrever o valor já conhecido com isso apagaria o único
 * horímetro que a tela tem para mostrar — pior que não atualizar.
 *
 * ⚠️ O nome do campo no webhook ainda NÃO foi confirmado contra um device
 * real (ver docs/PLANO_IMPLEMENTACAO_v4.10.md §Pendências). Os chamadores
 * (`pushgps.php`, `pushhb.php`) tentam os nomes mais prováveis; se nenhum
 * bater, esta função nunca é chamada e a coluna fica NULL — comportamento
 * seguro, não uma falha.
 *
 * @param PDO        $db    Conexão ativa
 * @param string     $imei  Equipamento
 * @param float|null $hours Horímetro reportado
 * @returns void
 */
function update_engine_hours(PDO $db, string $imei, $hours): void
{
    if ($hours === null || (float)$hours <= 0) {
        return;
    }
    try {
        $db->prepare("UPDATE devices SET engine_hours = :h WHERE imei = :imei")
           ->execute([':h' => (float)$hours, ':imei' => $imei]);
    } catch (Throwable $e) {
        // Ingestão de webhook nunca pode morrer por causa de um campo
        // acessório — mesma postura de calculateDistance() em pushgps.php.
    }
}
