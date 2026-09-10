-- ═══════════════════════════════════════════════════════════════════════════
-- Migration v4.18.2 — "Perfil de configuração de IA"
--
-- Depois de uma leitura completa ("Ler tudo agora") em /configuracoes-ia, o
-- conjunto de comandos prontos (já com os valores lidos da própria câmera)
-- fica salvo por equipamento — para reexibir a data da última leitura
-- completa quando o equipamento for selecionado, reenviar o mesmo perfil a
-- outras câmeras do MESMO MODELO, e baixar como `writeconfig.txt`.
--
-- Um snapshot por equipamento (UNIQUE em `imei`) — a leitura completa mais
-- recente sobrescreve a anterior; não é histórico versionado.
-- ═══════════════════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `device_ia_config_snapshots` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `imei`            VARCHAR(20) NOT NULL,
  `model_display`   VARCHAR(80) NOT NULL COMMENT 'Modelo no momento da leitura — só câmeras deste modelo recebem o perfil',
  `captured_at`     DATETIME NOT NULL COMMENT 'Quando a leitura completa terminou',
  `total_catalogo`  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantos comandos o catálogo documenta para este modelo',
  `total_capturado` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Quantos entraram no perfil (resposta da câmera reconhecida)',
  `commands_text`   LONGTEXT NOT NULL COMMENT 'Comandos prontos, um por linha, sem comentário — mesmo conteúdo do writeconfig.txt',
  `created_by`      INT UNSIGNED NULL COMMENT 'users.id de quem disparou a leitura',
  `customer_id`     INT UNSIGNED NULL,
  `created_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ia_snapshot_imei` (`imei`),
  KEY `idx_ia_snapshot_model` (`model_display`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Última leitura completa de configuração de IA por câmera (export entre câmeras do mesmo modelo + writeconfig.txt)';
