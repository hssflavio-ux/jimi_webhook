-- ============================================================================
-- Migração v4.19.2 — worker_watermarks: marca-d'água por ORDEM DE CHEGADA
-- ============================================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- 🔴 POR QUE. `scripts/state_builder.php` retomava a leitura de gps_data pelo
-- `gps_time` do último segmento. A câmera guarda posições quando perde sinal e
-- as descarrega depois com o horário ORIGINAL — medido em produção em
-- 13/09/2026: 11% dos pontos chegam reenviados (`gps_mode=1`), mediana de 6,3 h
-- de atraso, máximo de 6,8 dias. Esses pontos caíam antes da marca-d'água e
-- eram ignorados para sempre: 11 dos 2.810 segmentos "offline" de 30 dias
-- continham 108 pontos que chegaram depois de o segmento ser gravado.
--
-- O `id` de gps_data (e de alarms) cresce na ordem de CHEGADA — as duas
-- tabelas só recebem INSERT — então "linhas com id maior que o último
-- processado" é exatamente "o que chegou desde a última rodada", qualquer que
-- seja o horário do evento. Esta tabela guarda esse id por script e por fonte.
-- `last_time` serve às fontes que só têm carimbo de atualização
-- (occurrences.updated_at, usada pelo mapa de risco na v4.20.0).

CREATE TABLE IF NOT EXISTS `worker_watermarks` (
  `worker` varchar(40) NOT NULL COMMENT 'Script dono da marca (state_builder, risk_builder)',
  `source` varchar(40) NOT NULL COMMENT 'Fonte lida (gps_data, alarms, occurrences)',
  `last_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'Maior id já processado — o id cresce na ordem de chegada',
  `last_time` datetime DEFAULT NULL COMMENT 'UTC — para fontes marcadas por updated_at',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`worker`, `source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Marca-d''água dos workers por ordem de chegada (id), não por horário do evento';

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.19.2', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.19.2', last_update = NOW();

-- ============================================================
-- Conferência
-- ============================================================
SELECT CONCAT('worker_watermarks existe: ', COUNT(*)) AS conferencia
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name = 'worker_watermarks';

SELECT 'Migracao v4.19.2 concluida — rode: php scripts/state_builder.php 30 --rebuild' AS status;
