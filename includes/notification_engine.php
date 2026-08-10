<?php
/**
 * JIMI Webhook System — Motor de Notificações v4.4.0
 *
 * Decide se um evento vira notificação e por quais canais, com base em
 * notification_rules (cliente × tipo de alarme, com fallback global).
 *
 * REGRA DE OURO: este arquivo só GRAVA. Nunca abre socket, nunca envia
 * e-mail. É chamado de dentro da transação do webhook (pushalarm →
 * occurrence_engine), e uma conversa SMTP ali dentro seguraria locks de
 * alarms/occurrences por segundos. Quem envia é scripts/worker.php, lendo
 * a fila `jobs`. Mesma separação já adotada em
 * flush_pending_video_requests() (occurrence_engine.php).
 *
 * Toda falha é engolida e logada: notificação é acessório, não pode
 * derrubar a ingestão do alarme (um throw aqui faria rollback do INSERT).
 *
 * Fluxo:
 *   process_alarm_to_occurrence() [ocorrência NOVA]
 *      └─ notify_from_occurrence()
 *           ├─ resolve_notification_rule()  (matching triplo + fallback global)
 *           ├─ INSERT notifications         (sino, com want_popup/want_sound)
 *           └─ INSERT jobs type=notification (e-mail, se a regra pedir)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

/** Teto de notificações por cliente por hora (anti-enxurrada). */
const NOTIFY_RATE_LIMIT_PER_HOUR = 60;

/** Janela de dedupe do e-mail para o mesmo (imei, tipo de alarme). */
const NOTIFY_EMAIL_DEDUPE_MINUTES = 15;

/**
 * Kill-switch global. Comparação explícita com '0' porque `getenv` devolve
 * string e '0' é falsy em PHP — com `?:` o switch silenciaria por engano
 * (mesma armadilha documentada em queue_event_video_request()).
 *
 * @returns bool
 */
function notifications_enabled(): bool
{
    $flag = getenv('NOTIFY_ENABLED');
    return !($flag !== false && trim((string)$flag) === '0');
}

/**
 * Ordem de severidade de risco, para comparar com `min_risk` da regra.
 *
 * @param string|null $risk baixo|medio|alto
 * @returns int 1..3 (0 se desconhecido)
 */
function notify_risk_weight(?string $risk): int
{
    switch (strtolower((string)$risk)) {
        case 'baixo': return 1;
        case 'medio': return 2;
        case 'alto':  return 3;
    }
    return 0;
}

/**
 * Resolve a regra aplicável a um alarme.
 *
 * Matching triplo idêntico ao do motor de ocorrências: a regra pode ser
 * escrita como código do alarme, nome (PT ou EN) ou categoria. A regra do
 * cliente tem precedência sobre a global (customer_id NULL).
 *
 * ⚠️ **O CÓDIGO COMPOSTO NÃO É DETALHE.** Para JT/T, `alarms.alarm_type` guarda
 * a base (`264`) e o subtipo em coluna separada, enquanto
 * `alarm_types.alarm_code` guarda o composto (`264-4`). Enquanto esta função
 * comparou só a base, **nenhuma regra por CATEGORIA disparava para DMS ou ADAS
 * de JT/T** — que são o núcleo do produto. E como as regras reais são gravadas
 * por categoria (as 6 do homolog eram, nenhuma por nome), o efeito prático era:
 * câmera JIMI notificava, câmera JT/T não, sem erro em log nem na tela.
 * Medido antes da correção: `264`/`265` não casavam nada; `1027` (JT/T sem
 * subtipo) e todos os JIMI casavam normalmente.
 *
 * @param PDO         $db            Conexão ativa
 * @param int|null    $customerId    Cliente do device
 * @param string      $alarmType     Código BASE do alarme (`264`)
 * @param string      $alarmName     Nome do alarme
 * @param string|null $compositeCode Código do catálogo para JT/T com subtipo
 *                                   (`264-4`); null quando não há subtipo
 * @returns array|null Regra encontrada, ou null
 */
function resolve_notification_rule(PDO $db, ?int $customerId, string $alarmType, string $alarmName = '', ?string $compositeCode = null): ?array
{
    $stmt = $db->prepare(
        "SELECT nr.*
         FROM notification_rules nr
         LEFT JOIN alarm_types at ON (
            at.alarm_name_pt = nr.alarm_type
            OR at.alarm_name_en = nr.alarm_type
            OR at.category = nr.alarm_type
         )
         WHERE nr.is_active = 1
           AND (nr.customer_id = :cid OR nr.customer_id IS NULL)
           AND (
               nr.alarm_type = :atype
               OR nr.alarm_type = :aname
               OR nr.alarm_type = :ccode1
               OR at.alarm_code = :atype2
               OR at.alarm_code = :ccode2
           )
         ORDER BY (nr.customer_id IS NULL) ASC, nr.id ASC
         LIMIT 1"
    );
    $stmt->execute([
        ':cid'    => $customerId,
        ':atype'  => $alarmType,
        ':aname'  => $alarmName,
        ':atype2' => $alarmType,
        // Sentinela em vez de NULL: com NULL a comparação vira UNKNOWN e o
        // ramo não casa (comportamento correto), mas o sentinela deixa a
        // intenção explícita para quem lê.
        ':ccode1' => $compositeCode ?? "\0",
        ':ccode2' => $compositeCode ?? "\0",
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Verifica se o cliente já estourou o teto horário de notificações.
 *
 * Ao estourar, grava UMA notificação-resumo e passa a suprimir o resto até
 * a janela virar — melhor do que 300 linhas no sino de um device em rajada.
 *
 * @param PDO      $db         Conexão ativa
 * @param int|null $customerId Cliente
 * @returns bool true se deve SUPRIMIR a notificação
 */
function notify_rate_limited(PDO $db, ?int $customerId): bool
{
    if ($customerId === null) {
        return false;
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE customer_id = :cid AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $stmt->execute([':cid' => $customerId]);
    if ((int)$stmt->fetchColumn() < NOTIFY_RATE_LIMIT_PER_HOUR) {
        return false;
    }

    // Já existe um aviso de supressão nesta janela? Então nem isso repete.
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE customer_id = :cid AND ref_type = 'rate_limit'
           AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $stmt->execute([':cid' => $customerId]);
    if ((int)$stmt->fetchColumn() === 0) {
        $ins = $db->prepare(
            "INSERT INTO notifications
             (customer_id, kind, severity, title, body, ref_type, want_popup, want_sound)
             VALUES (:cid, 'sistema', 'warning', :title, :body, 'rate_limit', 0, 0)"
        );
        $ins->execute([
            ':cid'   => $customerId,
            ':title' => 'Muitas notificações neste período',
            ':body'  => 'O limite de ' . NOTIFY_RATE_LIMIT_PER_HOUR . ' notificações por hora foi atingido. '
                      . 'As novas foram suprimidas — os alarmes continuam registrados nos relatórios.',
        ]);
        Logger::warning('Notificações suprimidas por teto horário', ['customer_id' => $customerId]);
    }
    return true;
}

/**
 * Grava uma notificação e, se a regra pedir, enfileira o e-mail.
 *
 * @param PDO   $db Conexão ativa
 * @param array $n  Campos: customer_id, kind, severity, title, body, link_url,
 *                  ref_type, ref_id, want_popup, want_sound, emails (array)
 * @returns int|null ID da notificação criada, ou null se suprimida
 */
function notify(PDO $db, array $n): ?int
{
    if (!notifications_enabled()) {
        return null;
    }

    $customerId = $n['customer_id'] ?? null;
    if (notify_rate_limited($db, $customerId)) {
        return null;
    }

    $stmt = $db->prepare(
        "INSERT INTO notifications
         (customer_id, user_id, kind, severity, title, body, link_url, ref_type, ref_id, want_popup, want_sound)
         VALUES
         (:cid, :uid, :kind, :sev, :title, :body, :link, :rtype, :rid, :popup, :sound)"
    );
    $stmt->execute([
        ':cid'   => $customerId,
        ':uid'   => $n['user_id'] ?? null,
        ':kind'  => $n['kind'] ?? 'sistema',
        ':sev'   => $n['severity'] ?? 'info',
        ':title' => mb_substr((string)($n['title'] ?? 'Notificação'), 0, 200),
        ':body'  => isset($n['body']) ? mb_substr((string)$n['body'], 0, 500) : null,
        ':link'  => $n['link_url'] ?? null,
        ':rtype' => $n['ref_type'] ?? null,
        ':rid'   => $n['ref_id'] ?? null,
        ':popup' => !empty($n['want_popup']) ? 1 : 0,
        ':sound' => !empty($n['want_sound']) ? 1 : 0,
    ]);
    $notifId = (int)$db->lastInsertId();

    $emails = $n['emails'] ?? [];
    if (!empty($emails)) {
        queue_notification_email($db, $notifId, $n, $emails);
    }

    return $notifId;
}

/**
 * Enfileira o envio de e-mail em `jobs` (processado por scripts/worker.php).
 *
 * Dedupe por (imei, tipo): retransmissão de alarme e agrupamento produzem
 * o mesmo par, e ninguém quer 12 e-mails do mesmo evento. Mesma estratégia
 * do dedupe por alarmLabel em flush_pending_video_requests().
 *
 * @param PDO   $db      Conexão ativa
 * @param int   $notifId Notificação de origem
 * @param array $n       Campos da notificação
 * @param array $emails  Destinatários
 * @returns void
 */
function queue_notification_email(PDO $db, int $notifId, array $n, array $emails): void
{
    $dedupeKey = ($n['dedupe_key'] ?? ('notif|' . $notifId));

    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM jobs
         WHERE type = 'notification'
           AND created_at > DATE_SUB(NOW(), INTERVAL :mins MINUTE)
           AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.dedupe_key')) = :key"
    );
    $stmt->execute([':mins' => NOTIFY_EMAIL_DEDUPE_MINUTES, ':key' => $dedupeKey]);
    if ((int)$stmt->fetchColumn() > 0) {
        Logger::info('Notificação: e-mail pulado (dedupe)', ['dedupe_key' => $dedupeKey]);
        return;
    }

    $params = [
        'notification_id' => $notifId,
        'dedupe_key'      => $dedupeKey,
        'to'              => array_values($emails),
        'subject'         => (string)($n['title'] ?? 'Notificação'),
        'title'           => (string)($n['title'] ?? 'Notificação'),
        'body'            => (string)($n['body'] ?? ''),
        'link_url'        => $n['link_url'] ?? null,
        'severity'        => $n['severity'] ?? 'info',
    ];

    $ins = $db->prepare(
        "INSERT INTO jobs (type, customer_id, params, status)
         VALUES ('notification', :cid, :params, 'pendente')"
    );
    $ins->execute([
        ':cid'    => $n['customer_id'] ?? null,
        ':params' => json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
}

/**
 * Ponto de entrada usado pelo motor de ocorrências.
 *
 * Chamado APENAS quando uma ocorrência nova é criada — nunca no ramo de
 * agrupamento. A janela de dedup do occurrence_engine (threshold do perfil,
 * padrão 10 min) já absorve a rajada de alarmes repetidos; notificar por
 * ocorrência e não por alarme elimina a maior fonte de spam de graça.
 *
 * @param PDO      $db           Conexão ativa
 * @param int      $occurrenceId Ocorrência recém-criada
 * @param array    $alarm        Dados do alarme (imei, alarm_type, alarm_name, alarm_time)
 * @param string   $risk         Risco atribuído pelo perfil (baixo|medio|alto)
 * @param int|null $customerId   Cliente do device
 * @returns void
 */
function notify_from_occurrence(PDO $db, int $occurrenceId, array $alarm, string $risk, ?int $customerId): void
{
    try {
        if (!notifications_enabled()) {
            return;
        }

        $imei      = (string)($alarm['imei'] ?? '');
        $alarmType = (string)($alarm['alarm_type'] ?? '');
        $alarmName = (string)($alarm['alarm_name'] ?? $alarmType);

        $subType   = $alarm['alarm_subtype'] ?? null;
        $composite = ($subType !== null && $subType !== '') ? $alarmType . '-' . $subType : null;

        $rule = resolve_notification_rule($db, $customerId, $alarmType, $alarmName, $composite);
        if (!$rule) {
            return;
        }

        // Regra com min_risk só dispara de tal risco para cima
        if (!empty($rule['min_risk'])
            && notify_risk_weight($risk) < notify_risk_weight($rule['min_risk'])) {
            return;
        }
        if (empty($rule['notify_bell']) && empty($rule['notify_email'])) {
            return;
        }

        $deviceLabel = notify_device_label($db, $imei);
        $severity    = $risk === 'alto' ? 'critical' : ($risk === 'medio' ? 'warning' : 'info');

        $emails = [];
        if (!empty($rule['notify_email'])) {
            $decoded = json_decode((string)($rule['emails'] ?? '[]'), true);
            if (is_array($decoded)) {
                $emails = array_slice(array_filter(array_map('trim', $decoded)), 0, 3);
            }
        }

        // Sem sino e sem e-mail válido não há o que fazer
        if (empty($rule['notify_bell']) && empty($emails)) {
            return;
        }

        notify($db, [
            'customer_id' => $customerId,
            'kind'        => 'ocorrencia',
            'severity'    => $severity,
            'title'       => $alarmName . ' — ' . $deviceLabel,
            'body'        => 'Risco ' . $risk . '. Ocorrência aberta em '
                           . fmt_notify_time($alarm['alarm_time'] ?? null) . '.',
            'link_url'    => '/ocorrencias/dashboard?id=' . $occurrenceId,
            'ref_type'    => 'occurrence',
            'ref_id'      => $occurrenceId,
            'want_popup'  => !empty($rule['notify_popup']),
            'want_sound'  => !empty($rule['notify_sound']),
            'emails'      => $emails,
            'dedupe_key'  => $imei . '|' . $alarmName,
        ]);

    } catch (Throwable $e) {
        // Nunca propagar: estamos dentro da transação do webhook e um throw
        // aqui faria rollback do INSERT do alarme.
        Logger::error('Notificação: falha ao processar ocorrência', [
            'occurrence_id' => $occurrenceId,
            'error'         => $e->getMessage(),
        ]);
    }
}

/**
 * Rótulo amigável do device (nome cadastrado, ou o próprio IMEI).
 *
 * @param PDO    $db   Conexão ativa
 * @param string $imei IMEI
 * @returns string
 */
function notify_device_label(PDO $db, string $imei): string
{
    if ($imei === '') {
        return 'Equipamento';
    }
    try {
        $stmt = $db->prepare("SELECT device_name FROM devices WHERE imei = :imei LIMIT 1");
        $stmt->execute([':imei' => $imei]);
        $name = $stmt->fetchColumn();
        return $name ? (string)$name : $imei;
    } catch (Throwable $e) {
        return $imei;
    }
}

/**
 * Formata um instante UTC para exibição BRT no corpo da notificação.
 *
 * Usa fmt_brt() quando includes/functions.php já está carregado (caso do
 * webhook); do contrário converte na mão, para que o worker e o CLI também
 * consigam formatar sem arrastar dependências.
 *
 * @param string|null $utc Data/hora UTC
 * @returns string
 */
function fmt_notify_time(?string $utc): string
{
    if (!$utc) {
        return '—';
    }
    if (function_exists('fmt_brt')) {
        return fmt_brt($utc, 'd/m/Y H:i');
    }
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        return $dt->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return (string)$utc;
    }
}
