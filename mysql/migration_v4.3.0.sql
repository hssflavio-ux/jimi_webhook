-- ============================================================
-- Migração v4.3.0 — Relatório de Deslocamento: modalidades + índice de período
-- ============================================================
-- Contexto: as consultas do relatório de deslocamento filtram por
-- customer_id + started_at, mas trips só tinha índice em (customer_id).
-- Benchmark com 2,92M viagens (tenant de 200 veículos): a grade caía de
-- 3,5-6s para <1ms (por viagem) / 41-177ms (fechamento diário 7-30 dias)
-- com o índice composto (customer_id, started_at).
--
-- O índice antigo idx_trips_customer fica redundante (o composto tem
-- customer_id como prefixo e continua servindo a FK fk_trips_customer).
--
-- Idempotente: pode rodar mais de uma vez sem efeito colateral.
-- ============================================================

DROP PROCEDURE IF EXISTS `create_index_if_not_exists`;
DELIMITER //
CREATE PROCEDURE `create_index_if_not_exists`(IN p_table VARCHAR(128), IN p_index VARCHAR(128), IN p_columns TEXT)
BEGIN
    DECLARE idx_count INT;
    SELECT COUNT(*) INTO idx_count FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_index;
    IF idx_count = 0 THEN
        SET @sql = CONCAT('CREATE INDEX `', p_index, '` ON `', p_table, '` ', p_columns);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

DROP PROCEDURE IF EXISTS `drop_index_if_exists`;
DELIMITER //
CREATE PROCEDURE `drop_index_if_exists`(IN p_table VARCHAR(128), IN p_index VARCHAR(128))
BEGIN
    DECLARE idx_count INT;
    SELECT COUNT(*) INTO idx_count FROM information_schema.STATISTICS
    WHERE table_schema = DATABASE() AND table_name = p_table AND index_name = p_index;
    IF idx_count > 0 THEN
        SET @sql = CONCAT('DROP INDEX `', p_index, '` ON `', p_table, '`');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- Índice composto primeiro (garante que a FK fk_trips_customer sempre
-- tenha um índice com customer_id como prefixo), depois remove o redundante.
CALL create_index_if_not_exists('trips', 'idx_trips_customer_time', '(`customer_id`, `started_at`)');
CALL drop_index_if_exists('trips', 'idx_trips_customer');

DROP PROCEDURE IF EXISTS `create_index_if_not_exists`;
DROP PROCEDURE IF EXISTS `drop_index_if_exists`;

-- ============================================================
-- Versão do sistema
-- ============================================================
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '4.3.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '4.3.0', `last_update` = NOW();
