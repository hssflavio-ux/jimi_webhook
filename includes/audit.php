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
