-- ═══════════════════════════════════════════════════════════════
-- JIMI Webhook System — Migration v4.1.0
-- Fase M.1: Exportação Excel/PDF nos relatórios assíncronos
--
-- Uso: mysql -u root -p jimi_tracker < mysql/migration_v4.1.0.sql
-- Idempotente: usa add_column_if_not_exists (padrão v3.1.0/v4.0.0).
-- ═══════════════════════════════════════════════════════════════

-- ------------------------------------------------------------
-- Auxiliar idempotente (herdado do padrão v3.1.0/v4.0.0)
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
DELIMITER //
CREATE PROCEDURE `add_column_if_not_exists`(IN p_table VARCHAR(128), IN p_column VARCHAR(128), IN p_definition TEXT)
BEGIN
    DECLARE col_count INT;
    SELECT COUNT(*) INTO col_count FROM information_schema.COLUMNS
    WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_column;
    IF col_count = 0 THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END//
DELIMITER ;

-- ============================================================
-- 1. jobs.format — formato de saída do relatório (csv = legado)
-- ============================================================
CALL add_column_if_not_exists('jobs', 'format',
    "ENUM('csv','xlsx','pdf') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'csv' COMMENT 'Formato de saída do relatório (Fase M.1)' AFTER `type`");

-- ============================================================
-- 2. FIX (Fase M.2): seed do perfil "Padrão Sistema" nunca gerava
--    ocorrências DMS/ADAS — os nomes dos parâmetros ('Distração',
--    'Fadiga', 'SOS'…) não existem em alarm_types, e o matching do
--    occurrence_engine exige igualdade exata de código/nome/categoria.
--    Correção: substitui os parâmetros órfãos pelos nomes reais do
--    catálogo alarm_types (JIMI e JT/T).
--    Obs.: as linhas antigas nunca casaram com alarme algum, portanto
--    removê-las não altera comportamento existente.
-- ============================================================

DELETE FROM `occurrence_config_params`
WHERE `config_id` = 1
  AND `alarm_type` IN (
    'Distração', 'Uso de Celular', 'Sem Cinto de Segurança', 'Fadiga',
    'Cigarro', 'Olhos Fechados', 'Bocejo', 'Obstrução de Câmera',
    'Motorista Não Identificado', 'Colisão Frontal', 'Saída de Faixa',
    'Pedestre Detectado', 'Alerta de Distância', 'Airbag Acionado',
    'SOS', 'Corte de Alimentação', 'Vibração', 'Cerca Eletrônica',
    'SIM Alterado'
  );

INSERT IGNORE INTO `occurrence_config_params` (`config_id`, `alarm_type`, `generates_occurrence`, `risk`, `threshold`) VALUES
-- DMS JIMI (alertType 143–160)
(1, 'Distração do Motorista', 1, 'medio', 10),
(1, 'Motorista ao Telefone', 1, 'alto', 10),
(1, 'Fadiga Extrema do Motorista', 1, 'alto', 10),
(1, 'Motorista Fumando', 1, 'baixo', 10),
(1, 'Motorista Bocejando', 1, 'medio', 10),
(1, 'Motorista Ausente', 1, 'alto', 10),
(1, 'Câmera DMS Bloqueada', 1, 'baixo', 30),
(1, 'Comendo ou Bebendo ao Volante', 1, 'baixo', 10),
(1, 'Olhar Lateral Prolongado', 1, 'medio', 10),
-- ADAS (nomes compartilhados JIMI 204–207 / JTT 264-1..4)
(1, 'ADAS: Colisão Frontal (FCW)', 1, 'alto', 5),
(1, 'ADAS: Saída de Faixa (LDW)', 1, 'medio', 10),
(1, 'ADAS: Distância Insegura (HMW)', 1, 'medio', 10),
(1, 'ADAS: Colisão com Pedestre (PCW)', 1, 'alto', 5),
(1, 'ADAS: Colisão com Pedestre', 1, 'alto', 5),
-- DMS JT/T (265-X)
(1, 'DMS: Distração Visual', 1, 'medio', 10),
(1, 'DMS: Uso de Celular ao Volante', 1, 'alto', 10),
(1, 'DMS: Fadiga ao Dirigir (Nível 1)', 1, 'medio', 10),
(1, 'DMS: Fadiga ao Dirigir (Nível 2)', 1, 'alto', 10),
(1, 'DMS: Motorista Fumando', 1, 'baixo', 10),
(1, 'DMS: Bocejando', 1, 'medio', 10),
(1, 'DMS: Sem Cinto de Segurança', 1, 'baixo', 10),
(1, 'DMS: Lente da Câmera Bloqueada', 1, 'baixo', 30),
(1, 'DMS: Ausência do Motorista', 1, 'alto', 10),
(1, 'DMS: Mãos fora do Volante', 1, 'alto', 10),
(1, 'DMS: Falha na Autenticação ID', 1, 'baixo', 30),
(1, 'DMS: Comendo ou Bebendo ao Volante', 1, 'baixo', 10),
-- Acidente / Segurança
(1, 'Airbag Acionado / Colisão', 1, 'alto', 5),
(1, 'Alerta SOS', 1, 'alto', 5),
(1, 'Corte de Alimentação Externa', 1, 'medio', 30),
(1, 'Corte de Alimentação Externa (Periférico)', 1, 'medio', 30),
(1, 'Alerta de Vibração', 1, 'medio', 10),
-- Informativos — sem ocorrência
(1, 'Entrada em Cerca Eletrônica', 0, 'baixo', NULL),
(1, 'Saída de Cerca Eletrônica', 0, 'baixo', NULL),
(1, 'Cartão SIM Alterado', 0, 'baixo', NULL);

-- ============================================================
-- 3. Versão do sistema
-- ============================================================
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '4.1.0', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '4.1.0', `last_update` = NOW();

-- Limpeza
DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;
