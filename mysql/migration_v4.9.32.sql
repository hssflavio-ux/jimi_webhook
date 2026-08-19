-- ============================================================================
-- JIMI Webhook System — Migração v4.9.32
--
-- Firmware: a versão que o equipamento REPORTA e as URLs de atualização.
--
-- ── O PROBLEMA ──────────────────────────────────────────────────────────────
-- `devices.firmware_version` existe desde a v4.0.0 e está **NULL em 100% da
-- base** (pendência 4 do STATUS.md, 19/08/2026): a coluna é preenchida só à
-- mão, no formulário de `/equipamentos`, e ninguém digita. O `VERSION#`
-- responde na hora — o que faltava era alguém GRAVAR a resposta.
--
-- O custo disso é concreto e já foi pago: na v4.9.31 as duas câmeras de
-- produção divergiram (`V1.8.1.2_250904` aceita `EVIDEO`, `V1.8.0.9_250807`
-- recusa), e como não havia firmware no banco a escolha do comando teve de ser
-- feita **por tentativa e erro**, em runtime, contra o equipamento.
--
-- ── POR QUE UMA TABELA DE URLs ──────────────────────────────────────────────
-- O `UPDATE,<url>#` vale para todos os modelos da linha JC — o que muda de um
-- para o outro é **só o pacote apontado pela URL**. Mandar para um JC182 a URL
-- do JC371 é o pior erro possível nesta tela: não é um comando recusado, é um
-- equipamento em operação baixando o firmware errado. Por isso a URL não é
-- campo livre digitado a cada envio — é cadastro, com o modelo na chave.
--
-- ⚠️ `version` NÃO é comparada por ordem. Não há como ordenar `V1.8.0.9_250807`
-- contra `V4.3.2` sem inventar uma regra que o fornecedor não publica; a tela
-- compara por IGUALDADE contra a release marcada `is_current` e diz apenas
-- "igual à atual" / "diferente" / "desconhecido". Dizer "desatualizado" exigiria
-- a ordem que não temos.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── 1. Quando o firmware foi lido ───────────────────────────────────────────
--
-- Sem isto, `firmware_version` preenchido não se distingue de
-- `firmware_version` recente: um valor lido há seis meses e um lido hoje ficam
-- idênticos na tela, e a decisão "esta câmera precisa de update?" passa a ser
-- tomada sobre dado de idade desconhecida. É a mesma razão de
-- `devices.params_synced_at` existir ao lado de `device_params`.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'devices'
              AND column_name = 'firmware_checked_at');
SET @sql := IF(@c = 0,
    'ALTER TABLE `devices` ADD COLUMN `firmware_checked_at` DATETIME NULL
       COMMENT ''Quando o VERSION# do equipamento foi lido pela ultima vez''
       AFTER `firmware_version`',
    'SELECT ''firmware_checked_at ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- `firmware_source` separa o que o EQUIPAMENTO respondeu do que alguém digitou.
-- Os dois já convivem na mesma coluna hoje (o formulário de /equipamentos
-- aceita texto livre), e sem a marca não há como saber se `V1.8.0.9_250807` é
-- leitura ou anotação — que é justamente a diferença que decide se dá para
-- confiar nela para escolher um comando.
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'devices'
              AND column_name = 'firmware_source');
SET @sql := IF(@c = 0,
    'ALTER TABLE `devices` ADD COLUMN `firmware_source` VARCHAR(16) NULL
       COMMENT ''device = lido do VERSION#; manual = digitado no cadastro''
       AFTER `firmware_checked_at`',
    'SELECT ''firmware_source ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- O que já está gravado veio do formulário — nenhum caminho automático existia
-- antes desta versão. Marcar como `manual` é registro do fato, não suposição.
UPDATE `devices`
   SET `firmware_source` = 'manual'
 WHERE `firmware_version` IS NOT NULL
   AND `firmware_version` <> ''
   AND `firmware_source` IS NULL;

-- ── 2. O cadastro de URLs de atualização ────────────────────────────────────
--
-- A chave é (modelo, versão): a URL é do PACOTE, e pacote é por modelo. Não há
-- `customer_id` de propósito — firmware é catálogo de plataforma, como
-- `device_models` e `alarm_types`, e uma coluna de cliente aqui cairia na
-- armadilha do `get_customer_id()` NULL descrita no CLAUDE.md sem trazer
-- nenhum ganho: dois clientes com o mesmo JC182 recebem o mesmo pacote.
CREATE TABLE IF NOT EXISTS `firmware_releases` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `device_model_id` bigint unsigned NOT NULL COMMENT 'FK device_models — a URL e do PACOTE, e pacote e por modelo',
    `version` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
              COMMENT 'Como o equipamento a reporta no VERSION#, ex.: V1.8.1.2_250904',
    -- 500 caracteres com folga: `commands.command_content` é TEXT desde a
    -- v4.9.11, então o limite aqui é o da tela, não o do despacho.
    `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
          COMMENT 'URL do pacote — sem virgula e sem #, que sao os separadores do proNo 128',
    `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    -- Uma release corrente por modelo. A unicidade NÃO é declarada no schema:
    -- um índice único sobre (device_model_id, is_current) recusaria a segunda
    -- release *não* corrente do mesmo modelo, que é o caso normal. Quem garante
    -- é o handler, zerando as irmãs na mesma transação.
    `is_current` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = versao que a frota deste modelo deveria estar rodando',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_by` bigint unsigned DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_fw_model_version` (`device_model_id`, `version`),
    KEY `idx_fw_model` (`device_model_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='URLs de atualizacao de firmware por modelo (v4.9.32)';

-- `system_info` marca a versão aplicada — mesmo padrão das migrações anteriores.
UPDATE `system_info` SET `version` = '4.9.32' WHERE `id` = 1;
