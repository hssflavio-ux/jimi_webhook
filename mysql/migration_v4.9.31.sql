-- ============================================================================
-- JIMI Webhook System — Migração v4.9.31
--
-- Religa ao alarme o vídeo REENVIADO sob demanda.
--
-- ── O PROBLEMA ──────────────────────────────────────────────────────────────
-- Quando o vídeo de um alarme não chega (81 de 106 em produção, 18/08/2026), dá
-- para pedir de novo — `EVIDEO` regenera o clipe do cartão e a câmera sobe. Só
-- que o arquivo volta com OUTRO NOME, e `alarms.file_url` continua apontando
-- para o nome antigo, que não existe. O vídeo fica no servidor sem aparecer no
-- relatório:
--
--     gravado no alarme:  EVENT_..._00000000_2026_08_18_16_16_57_I_14.ts
--     chegou pelo EVIDEO: EVENT_..._00000001_2026_08_18_16_16_26_I_02.ts
--
-- ── POR QUE UMA TABELA, E NÃO SÓ UMA JANELA DE TEMPO ────────────────────────
-- 🔴 Casar "arquivo que chegou" com "alarme mais próximo no tempo" cola vídeo
-- no alarme ERRADO: o DMS dispara várias vezes no mesmo minuto, e nada no nome
-- do arquivo diz de qual evento ele é. Como somos NÓS que pedimos o reenvio,
-- sabemos para qual alarme — e é isso que esta tabela guarda. A janela passa a
-- servir só para absorver o deslocamento do clipe, não para adivinhar o dono.
--
-- ── A JANELA (−90 s a +15 s), MEDIDA ────────────────────────────────────────
-- O timestamp do NOME é o INÍCIO do clipe, então o desvio é sempre para trás.
-- Medido em câmera real (18–19/08/2026):
--
--     chegada natural             delta    0 s
--     EVIDEO sem duração          delta    0 s
--     EVIDEO com duração (15 s)   delta  −31 s
--     HVIDEO (bloco de 1 min)     delta  −44 s   (pode chegar a −59)
--
-- Daí a assimetria: −90 s cobre o pior caso com folga; +15 s é tolerância de
-- relógio. Simétrico em ±15 s perderia EVIDEO-com-duração e HVIDEO; simétrico
-- em ±90 s gastaria tolerância num lado onde nada acontece.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `alarm_video_requests` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `alarm_id` bigint unsigned NOT NULL COMMENT 'Alarme que ficou sem vídeo',
    `imei` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    -- Hora LOCAL da câmera (BRT). O nome do arquivo vem nela, não em UTC, então
    -- guardar assim evita converter dos dois lados na hora de casar.
    `requested_for` datetime NOT NULL COMMENT 'Instante pedido, na hora local da câmera',
    `command` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `command_id` bigint unsigned DEFAULT NULL COMMENT 'Linha em `commands`, quando houve',
    `status` enum('pendente','atendido','recusado') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pendente',
    `device_reply` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Resposta crua do equipamento ao pedido',
    `matched_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `matched_at` datetime DEFAULT NULL,
    `requested_by` bigint unsigned DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    -- A busca do casamento é sempre por (imei, status, requested_for).
    KEY `idx_avr_match` (`imei`, `status`, `requested_for`),
    KEY `idx_avr_alarm` (`alarm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pedidos de reenvio de vídeo de alarme (v4.9.31)';

-- `system_info` marca a versão aplicada — mesmo padrão das migrações anteriores.
UPDATE `system_info` SET `version` = '4.9.31' WHERE `id` = 1;
