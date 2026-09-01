-- ============================================================================
-- bycamera — migration v4.15.0
-- Auditoria de ações de usuário / segurança operacional
--
-- Cria `audit_log`: UMA linha por ação de mutação de estado (create/update/
-- delete/login/permissão negada/troca de cliente/...), com autor, escopo de
-- cliente, entidade afetada e snapshot de antes/depois quando aplicável.
--
-- 🔴 POR QUE NÃO REUSAR `login_log`/`device_param_writes`/`impersonation_log`:
-- cada uma audita UM tipo de evento com o schema que aquele evento pediu
-- (`login_log` não tem `user_id` porque falha de login pode não achar o
-- usuário; `device_param_writes` é específico do ciclo de escrita em
-- equipamento). Esta tabela é a linha do tempo CROSS-ENTIDADE que nenhuma
-- delas responde: "o que o usuário X fez esta semana, em qualquer tela". As
-- tabelas antigas continuam sendo a fonte de verdade dos seus domínios — a
-- tela `/auditoria` só as LÊ (via UNION), nunca duplica dado nelas.
--
-- 🔴 SEM UPDATE/DELETE NA APLICAÇÃO: nenhum handler deve alterar ou apagar
-- linha desta tabela — é o contrato de "log de auditoria não se edita".
-- `includes/audit.php` só expõe função de INSERT. Não há trigger nem grant
-- bloqueando isso a nível de banco nesta fase — é garantia de convenção de
-- código, não de banco (decisão consciente, ver PROJETO/plano de auditoria).
--
-- 🔴 SNAPSHOT DE AUTOR: `actor_name`/`actor_email` são congelados no momento
-- do INSERT porque `user_id` é FK com `ON DELETE SET NULL` — apagar um
-- usuário não pode apagar o rastro do que ele fez (o oposto do propósito
-- desta tabela), mas sem o snapshot a linha viraria "ação de ninguém".
--
-- ⚠️ Esta migração NÃO roda no deploy que a traz (o bash relê o deploy.sh em
-- execução do disco). Rode `./scripts/deploy.sh --force` duas vezes, ou
-- aplique este .sql à mão logo após o primeiro deploy.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`            bigint unsigned NOT NULL AUTO_INCREMENT,

    -- Autor: FK fraca (SET NULL) + snapshot congelado no INSERT.
    `user_id`       bigint unsigned DEFAULT NULL,
    `actor_name`    varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Nome do autor no momento da ação — sobrevive à exclusão do usuário',
    `actor_email`   varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'E-mail do autor no momento da ação — idem',

    -- Escopo multi-tenant. NULL = ação sem cliente único (config global,
    -- criação de cliente, tela administrativa). Sem FK: o cliente pode ser
    -- excluído depois e a linha de auditoria tem que sobreviver.
    `customer_id`   bigint unsigned DEFAULT NULL
        COMMENT 'NULL = ação fora do escopo de um cliente (config global, admin)',

    -- 'entidade.verbo' em snake_case, ex.: chip.delete, geofence.create,
    -- session.login, permission.denied, customer.switch. String livre e não
    -- ENUM de propósito: tela nova não pode exigir ALTER TABLE.
    `action`        varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        COMMENT 'Convenção "entidade.verbo", ex.: chip.delete',

    -- Entidade afetada. Sem FK pelo mesmo motivo de customer_id: a linha
    -- referenciada pode ser a que esta própria ação acabou de apagar.
    `entity_type`   varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Ex.: sim_card, geofence, user — NULL para ações sem entidade singular (login)',
    `entity_id`     bigint unsigned DEFAULT NULL,

    -- Snapshot cru do estado — não um diff calculado. NULL quando a ação não
    -- tem antes/depois (login, permissão negada, troca de senha — esta por
    -- regra fixa, nunca grava hash).
    `before_data`   JSON DEFAULT NULL COMMENT 'Estado antes da ação (lido imediatamente antes do UPDATE/DELETE)',
    `after_data`    JSON DEFAULT NULL COMMENT 'Estado depois da ação',

    -- 'denied' cobre o 403 de require_admin()/require_permission() — mesma
    -- tabela, sem precisar de uma segunda para negações de acesso.
    `status`        varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success'
        COMMENT 'success | denied | error',

    `ip_address`    varchar(45) DEFAULT NULL COMMENT 'cabe IPv6, mesmo padrão de login_log',
    `user_agent`    varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at`    timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC, como todo timestamp do sistema',

    PRIMARY KEY (`id`),
    CONSTRAINT `fk_al_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,

    KEY `idx_al_customer_created` (`customer_id`, `created_at` DESC),
    KEY `idx_al_user_created`     (`user_id`, `created_at` DESC),
    KEY `idx_al_entity`           (`entity_type`, `entity_id`),
    KEY `idx_al_action_created`   (`action`, `created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Auditoria de ações de usuário — apenas-inserção por convenção da aplicação';
