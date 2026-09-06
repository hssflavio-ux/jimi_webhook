-- ============================================================================
-- Migration v4.17.12 — payload CRU de tudo que os equipamentos enviam
--
-- Pedido do dono do produto. Origem concreta: a investigação do "motivo da
-- transmissão" (v4.17.11) esbarrou no fato de que `handlers/pushgps.php` lê
-- uma lista FIXA de chaves e `gps_data` não tem `raw_data` — campo que o hub
-- mande fora dessa lista some sem rastro, e não havia como saber se existe.
--
-- Guarda-se o CORPO COMO CHEGOU, não o parse: o parse é a nossa interpretação
-- (já provada incompleta), o corpo é o fato.
--
-- Dimensionamento medido em produção (05/09/2026): 5.429 requisições/dia com
-- 12 equipamentos (64% heartbeat), ~2,7 MB/dia. Com RAW_PAYLOAD_RETENTION_DAYS
-- em 30 dias dá ~80 MB — contra 70 GB livres. Escala com o tamanho da frota.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `webhook_payloads` (
  `id`           bigint unsigned NOT NULL AUTO_INCREMENT,
  `endpoint`     varchar(50)  NOT NULL COMMENT 'pushgps, pushhb, pushalarm, filelist…',
  `imei`         varchar(20)  DEFAULT NULL COMMENT 'Conveniência de consulta (1o item); o corpo é a fonte de verdade',
  `item_count`   int          NOT NULL DEFAULT '0' COMMENT 'Itens no data_list (0 = corpo sem envelope)',
  `payload_hash` varchar(32)  DEFAULT NULL COMMENT 'Mesmo hash da idempotencia: distingue reenvio do hub de dado novo',
  `content_type` varchar(100) DEFAULT NULL,
  `body_bytes`   int unsigned NOT NULL DEFAULT '0' COMMENT 'Tamanho original via Content-Length; em chunked, os bytes lidos',
  `truncated`    tinyint(1)   NOT NULL DEFAULT '0' COMMENT '1 = corpo excedeu RAW_PAYLOAD_MAX_BYTES',
  `body`         mediumtext   COMMENT 'Corpo cru, exatamente como chegou',
  `received_at`  datetime     NOT NULL COMMENT 'UTC',
  PRIMARY KEY (`id`),
  KEY `idx_wp_recv`          (`received_at`),
  KEY `idx_wp_imei_recv`     (`imei`, `received_at`),
  KEY `idx_wp_endpoint_recv` (`endpoint`, `received_at`),
  KEY `idx_wp_hash`          (`payload_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Payload cru dos webhooks de equipamento (v4.17.12)';

-- ============================================================
-- Versão (mesmo upsert das migrações anteriores: a linha id=1 pode não
-- existir num banco recém-instalado, e o UPDATE puro passaria em silêncio)
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.17.12', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.17.12', last_update = NOW();

-- ============================================================
-- Conferência
-- ============================================================
SELECT CONCAT('webhook_payloads existe: ', COUNT(*)) AS status
  FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'webhook_payloads';

SELECT 'Migracao v4.17.12 concluida' AS status;
