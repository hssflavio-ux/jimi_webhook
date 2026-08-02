-- ============================================================
-- Migração v4.8.0 — motorista junto com a POSIÇÃO
-- ============================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- POR QUE ESTA MIGRAÇÃO EXISTE.
-- A documentação oficial da Jimi (docs.jimicloud.com/integration) confirma que
-- `driverId` e `driverName` viajam no payload ao lado das coordenadas, nos dois
-- protocolos. O `pushalarm.php` já os captura — `alarms` tem driver_id e
-- driver_name desde a v4.0.0 — mas `gps_data` não tinha onde guardá-los.
--
-- A consequência prática aparecia no Relatório de Posições: sem motorista na
-- posição, a única forma de responder "quem dirigia" era casar cada ponto com a
-- VIAGEM que o contém (JOIN em trips por faixa de tempo). Funciona, mas é um
-- join caro por linha e só acerta quando existe viagem fechada — posição fora
-- de viagem ficava sem condutor.
--
-- Com as colunas abaixo o dado passa a chegar pronto DA CÂMERA, quando ela o
-- enviar. O join em trips continua como fallback, então nada quebra enquanto os
-- equipamentos não mandarem o campo.
--
-- Idempotente: as duas colunas e o índice são criados só se não existirem.

-- ── gps_data.driver_id / driver_name ────────────────────────
SET @exists := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'gps_data'
                  AND column_name = 'driver_id');
SET @sql := IF(@exists = 0,
  'ALTER TABLE gps_data ADD COLUMN driver_id BIGINT UNSIGNED NULL COMMENT ''drivers.id resolvido pelo identifier enviado pela câmera'' AFTER driver_license',
  'SELECT ''gps_data.driver_id já existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @exists := (SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'gps_data'
                  AND column_name = 'driver_name');
SET @sql := IF(@exists = 0,
  'ALTER TABLE gps_data ADD COLUMN driver_name VARCHAR(150) NULL COMMENT ''Nome cru enviado pelo equipamento (pode não ter cadastro local)'' AFTER driver_id',
  'SELECT ''gps_data.driver_name já existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Índice para o relatório filtrar/agrupar por motorista sem varrer a tabela
SET @exists := (SELECT COUNT(*) FROM information_schema.statistics
                WHERE table_schema = DATABASE() AND table_name = 'gps_data'
                  AND index_name = 'idx_gps_driver');
SET @sql := IF(@exists = 0,
  'ALTER TABLE gps_data ADD INDEX idx_gps_driver (driver_id, gps_time)',
  'SELECT ''idx_gps_driver já existe''');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ── Versão ──────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.8.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.8.0', last_update = NOW();
