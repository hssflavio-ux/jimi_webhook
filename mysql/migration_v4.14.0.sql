-- ============================================================================
-- bycamera — migration v4.14.0
-- Comandos do protocolo 128 via SMS (provedor Allcance)
--
-- Cria as DUAS tabelas do canal SMS:
--   sms_settings  — credenciais da conta Allcance (senha cifrada) + cache do
--                   Bearer + segredo do webhook. Uma linha global.
--   sms_commands  — um registro por comando enviado por SMS, com o ciclo de
--                   vida do PROVEDOR (não o do IoT Hub).
--
-- 🔴 POR QUE NÃO REUSAR `commands`: os estados que a Allcance devolve
-- (`entregue celular`, `saldo insuficiente`, `lista negra`,
-- `message_text_invalid`, `não entregue`…) não cabem no enum
-- `pending/queued/sent/executed/failed` sem tradução com perda, e o polling de
-- `/commandstatus` varre `commands` esperando o ciclo do Hub. Canal diferente,
-- ciclo diferente, tabela diferente.
--
-- 🔴 SNAPSHOT DE DONO (regra da Fase 2, v4.12.0): `sms_commands` grava
-- customer_id/vehicle_id como retrato do dono NO MOMENTO DO ENVIO, resolvido
-- por resolve_installation_for_imei(). Nunca leia o dono desta tabela pelo JOIN
-- em devices.customer_id — a câmera pode trocar de cliente depois.
--
-- ⚠️ Esta migração NÃO roda no deploy que a traz (o bash relê o deploy.sh em
-- execução do disco). Rode `./scripts/deploy.sh --force` duas vezes, ou aplique
-- este .sql à mão logo após o primeiro deploy.
-- ============================================================================

-- ── 1. Credenciais e estado da conta Allcance ───────────────────────────────
CREATE TABLE IF NOT EXISTS `sms_settings` (
    `id`               bigint unsigned NOT NULL AUTO_INCREMENT,
    -- Reservado para conta-por-cliente no futuro. Nesta fase é SEMPRE NULL
    -- (conta global da plataforma) — assim a evolução não exige migração de
    -- esquema, só passar a gravar o id.
    `customer_id`      bigint unsigned DEFAULT NULL COMMENT 'NULL = conta global da plataforma',
    `username`         varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        COMMENT 'Usuário da API Allcance — é o E-MAIL da conta, não um login separado',
    `password_enc`     varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Senha cifrada (AES-256-GCM, app_encrypt) — nunca em texto puro',
    -- Cache do Bearer: o token vale 3600s (medido no JWT em 29/08/2026) e a API
    -- não tem refresh. Sem cache, cada abertura de tela faria 2 requisições.
    `token`            text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Bearer em cache',
    `token_expires_at` datetime DEFAULT NULL COMMENT 'UTC — renovado com 5 min de margem',
    -- A Allcance NÃO envia cabeçalho de autenticação no webhook. O segredo na
    -- URL (/pushsms?k=…) é a única defesa possível desse endpoint.
    `webhook_secret`   varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Segredo da URL do webhook (/pushsms?k=...)',
    `cod_servico`      smallint unsigned NOT NULL DEFAULT 11
        COMMENT '11 = SMS TRANSACIONAL (tabela de códigos da Allcance)',
    `is_active`        tinyint(1) NOT NULL DEFAULT 1,
    `last_test_at`     datetime DEFAULT NULL COMMENT 'UTC — última verificação de credencial/saldo',
    `last_test_ok`     tinyint(1) DEFAULT NULL,
    `last_test_error`  varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `updated_by`       bigint unsigned DEFAULT NULL,
    `created_at`       timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Mesma razão de smtp_settings: o MySQL trata NULLs como distintos num
    -- índice único, então sem a coluna gerada seria possível criar duas
    -- configurações globais e a precedência viraria sorteio.
    `customer_key`     bigint unsigned GENERATED ALWAYS AS (IFNULL(`customer_id`, 0)) VIRTUAL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sms_settings_scope` (`customer_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Credenciais da conta SMS (Allcance) — senha cifrada';

-- ── 2. Comandos enviados por SMS ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sms_commands` (
    `id`                  bigint unsigned NOT NULL AUTO_INCREMENT,
    -- 🔴 A referência é bin2hex(random_bytes(16)), NÃO o id da linha: a doc da
    -- Allcance exige que o "ID CONTROLE NUNCA PODE SE REPETIR" — e isso vale
    -- para a conta inteira, para sempre. Um banco reinstalado reciclaria ids e
    -- a API recusaria (ou pior, casaria o webhook com o comando errado).
    `referencia`          varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        COMMENT 'referencia_numero — chave que o webhook usa para voltar',
    `referencia_campanha` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Devolvida no 201 do envio',
    -- Snapshot do dono no momento do envio (resolve_installation_for_imei).
    `customer_id`         bigint unsigned DEFAULT NULL,
    `vehicle_id`          bigint unsigned DEFAULT NULL,
    `imei`                varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `msisdn`              varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL
        COMMENT 'Número normalizado, exatamente como foi enviado à API',
    `command_content`     text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        COMMENT 'A string exata (forma de PLATAFORMA, separador vírgula)',
    `status_envio`        enum('enviado','falha_envio','sem_saldo','sem_msisdn')
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'falha_envio',
    -- ⚠️ Coluna JSON: gravar SEMPRE com json_encode(). Escrever string crua aqui
    -- faz o MySQL recusar com 3140 Invalid JSON text — foi o defeito que quebrou
    -- todo callback de comando offline por meses em `commands.response_payload`.
    `api_response`        json DEFAULT NULL COMMENT 'Resposta crua da API no envio',
    `http_code`           smallint unsigned DEFAULT NULL,
    -- Texto CRU da Allcance, minúsculo. Não traduzir na gravação: a tradução é
    -- na EXIBIÇÃO (mesma disciplina de alarm_category_label()).
    `status_entrega`      varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `entregue_em`         datetime DEFAULT NULL COMMENT 'UTC — data_entrega do webhook',
    -- A resposta do EQUIPAMENTO ao comando, quando ele responde por SMS.
    -- Chega no webhook como status "recebido" COM o campo `mensagem` preenchido.
    `resposta_texto`      text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `resposta_em`         datetime DEFAULT NULL COMMENT 'UTC',
    `operator`            varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `created_at`          timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_sms_referencia` (`referencia`),
    KEY `idx_sms_imei` (`imei`),
    KEY `idx_sms_campanha` (`referencia_campanha`),
    KEY `idx_sms_customer` (`customer_id`),
    KEY `idx_sms_vehicle` (`vehicle_id`),
    KEY `idx_sms_created` (`created_at` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Comandos proNo 128 despachados por SMS (Allcance) — v4.14.0';
