<?php
/**
 * bycamera — Auditoria de ações de usuário v4.15.0
 *
 * Ponto ÚNICO de escrita em `audit_log` (mysql/migration_v4.15.0.sql). Nenhum
 * handler faz `INSERT INTO audit_log` direto — todos passam por `audit_log()`
 * ou `audit_log_denied()`, para que autor/cliente/IP/user-agent sejam
 * resolvidos sempre da MESMA forma, e para que a tabela permaneça
 * apenas-inserção por convenção: este arquivo não expõe (e não deve nunca
 * expor) função de UPDATE ou DELETE sobre `audit_log`.
 *
 * 🔴 NUNCA lança exceção para fora. Falha ao gravar a linha de auditoria não
 * pode derrubar a ação de negócio que já aconteceu — o chip já foi excluído;
 * se o INSERT do log falhar, o chip continua excluído. A alternativa
 * (propagar a exceção) faria o usuário ver "erro 500" numa operação que na
 * verdade deu certo. Mesma filosofia do `Logger`: auditoria é acessório,
 * nunca o ponto único de falha de uma tela.
 *
 * Espelha o desenho de `record_param_intent()` (includes/device_params.php):
 * grava o "antes" ANTES da ação destrutiva, resolve o autor da sessão, nunca
 * do que o chamador diz que é (evita forjar autoria por parâmetro de POST).
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Registra uma ação de auditoria (sucesso, negação ou erro de negócio).
 *
 * `user_id`/`actor_name`/`actor_email`/`customer_id`/`ip_address`/
 * `user_agent` são resolvidos automaticamente de `get_jimi_user()` /
 * `get_customer_id()` / `$_SERVER` — o chamador nunca os passa à mão, para
 * não haver risco de duas chamadas no mesmo handler resolverem o autor de
 * formas diferentes.
 *
 * @param string      $action     'entidade.verbo', ex. 'chip.delete'
 * @param string|null $entityType Ex.: 'sim_card', 'geofence', 'user'
 * @param int|null    $entityId
 * @param array|null  $before     Estado antes da ação (snapshot cru, não diff)
 * @param array|null  $after      Estado depois da ação
 * @param string      $status     'success' | 'denied' | 'error'
 * @returns void
 */
function audit_log(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    ?array $before = null,
    ?array $after = null,
    string $status = 'success'
): void {
    try {
        $db   = Database::getInstance()->getConnection();
        $user = function_exists('get_jimi_user') ? get_jimi_user() : null;

        $stmt = $db->prepare("
            INSERT INTO audit_log
                (user_id, actor_name, actor_email, customer_id, action,
                 entity_type, entity_id, before_data, after_data, status,
                 ip_address, user_agent)
            VALUES
                (:uid, :name, :email, :cid, :action,
                 :etype, :eid, :before, :after, :status, :ip, :ua)
        ");
        $stmt->execute([
            ':uid'    => $user['id'] ?? null,
            ':name'   => $user['name'] ?? null,
            ':email'  => $user['email'] ?? null,
            ':cid'    => function_exists('get_customer_id') ? get_customer_id() : null,
            ':action' => $action,
            ':etype'  => $entityType,
            ':eid'    => $entityId,
            // Coluna JSON: SEMPRE json_encode(). String crua faz o MySQL
            // recusar com 3140 Invalid JSON text (lição de commands.response_payload).
            ':before' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            ':after'  => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            ':status' => $status,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
            ':ua'     => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        error_log('audit_log: ' . $e->getMessage());
    }
}

/**
 * Atalho para negação de acesso (403). Chamado de dentro de
 * `require_admin()`/`require_permission()` (includes/auth.php) — é o que
 * cobre TODAS as telas do sistema de uma vez, sem instrumentar cada handler.
 *
 * `entityType`/`entityId` ficam NULL: no momento do 403 o handler ainda não
 * chegou a identificar uma entidade — a negação acontece antes do
 * processamento do POST/GET.
 *
 * @param string $screen Tela/handler que negou (ex. 'chips', 'grupos-permissao')
 * @returns void
 */
function audit_log_denied(string $screen): void
{
    audit_log('permission.denied', null, null, null, ['screen' => $screen], 'denied');
}

/**
 * SQL do `UNION ALL` que unifica toda fonte de auditoria numa forma só, com
 * colunas normalizadas — ponto ÚNICO usado por `/auditoria` e pelos 3
 * relatórios (`auditoria_negados.php`, `auditoria_cadastro.php`,
 * `auditoria_login.php`). Extraído de `handlers/auditoria.php` (v4.15.0) na
 * v4.15.1 para não duplicar o SQL do UNION quatro vezes.
 *
 * `login_log` não tem `user_id`/`customer_id`/`entity_type` (NULL nas três)
 * — o efeito colateral é ÚTIL: filtrar por usuário, cliente ou entidade
 * exclui essas linhas automaticamente (`NULL = :param` nunca é verdadeiro em
 * SQL), sem precisar de lógica condicional em PHP.
 *
 * `commands`/`sms_commands` entram como branches CONDICIONAIS (checadas por
 * INFORMATION_SCHEMA, não por try/catch): sem isso, uma instalação em que a
 * migração v4.14.0 (que cria sms_commands) ainda não rodou perderia a tela
 * de auditoria INTEIRA por causa de uma tabela periférica.
 *
 * `commands` (proNo 128/JT/T via IoT Hub) NUNCA teve customer_id
 * acrescentado (conferido nas migrações) — só imei. NULL aqui, de
 * propósito: juntar pelo dono ATUAL de devices.customer_id reabriria a
 * classe de bug que a Fase 2 (v4.12.0) fechou (câmera trocada de cliente
 * reatribuindo retroativamente o histórico). Efeito: aparece só na visão
 * sem filtro de cliente (admin), nunca filtrado por um cliente específico —
 * melhor não mostrar do que mostrar errado.
 *
 * `sms_commands.customer_id` É snapshot (`resolve_installation_for_imei()`,
 * regra da Fase 2) — seguro filtrar por cliente aqui, ao contrário de
 * `commands` acima.
 *
 * @param PDO $db Conexão ativa
 * @returns string SQL pronto para `SELECT t.* FROM ($sql) t WHERE ...`
 */
function audit_union_sql(PDO $db): string
{
    $existentes = [];
    try {
        $existentes = $db->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('commands','sms_commands')"
        )->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { /* segue sem os branches extras */ }

    $parts = ["
        SELECT al.id AS id, 'audit_log' AS src, al.user_id, al.actor_name, al.actor_email,
               al.customer_id, cu.name AS customer_name, al.action, al.entity_type, al.entity_id,
               al.before_data, al.after_data, al.status, al.created_at
          FROM audit_log al
          LEFT JOIN customers cu ON cu.id = al.customer_id
    ", "
        SELECT ll.id AS id, 'login_log' AS src, NULL AS user_id, NULL AS actor_name, ll.email AS actor_email,
               NULL AS customer_id, NULL AS customer_name,
               IF(ll.success = 1, 'session.login', 'session.login_failed') AS action,
               NULL AS entity_type, NULL AS entity_id, NULL AS before_data, NULL AS after_data,
               IF(ll.success = 1, 'success', 'denied') AS status, ll.created_at
          FROM login_log ll
    "];

    if (in_array('commands', $existentes, true)) {
        $parts[] = "
        SELECT c.id AS id, 'commands' AS src, NULL AS user_id, c.operator AS actor_name, NULL AS actor_email,
               NULL AS customer_id, NULL AS customer_name,
               'command.dispatch' AS action, 'device' AS entity_type, NULL AS entity_id,
               NULL AS before_data, JSON_OBJECT('imei', c.imei, 'pro_no', c.pro_no) AS after_data,
               CASE c.status WHEN 'executed' THEN 'success' WHEN 'failed' THEN 'error' ELSE 'aguardando' END AS status,
               c.created_at
          FROM commands c
        ";
    }
    if (in_array('sms_commands', $existentes, true)) {
        $parts[] = "
        SELECT sc.id AS id, 'sms_commands' AS src, NULL AS user_id, sc.operator AS actor_name, NULL AS actor_email,
               sc.customer_id, cu2.name AS customer_name,
               'sms_command.dispatch' AS action, 'device' AS entity_type, NULL AS entity_id,
               NULL AS before_data, JSON_OBJECT('imei', sc.imei, 'command_content', sc.command_content) AS after_data,
               CASE sc.status_envio WHEN 'enviado' THEN 'success' ELSE 'error' END AS status,
               sc.created_at
          FROM sms_commands sc
          LEFT JOIN customers cu2 ON cu2.id = sc.customer_id
        ";
    }

    return implode(' UNION ALL ', $parts);
}
