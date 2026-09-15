-- ============================================================================
-- Migração v4.21.0 — Alertas de Dirigibilidade (alarm_types.is_driving)
-- ============================================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- Decisão do dono do produto (14/09/2026): os eventos de condução — arrancada,
-- freada, curva, excesso de velocidade, colisão, capotamento, impacto e
-- inclinação — saem de "Alertas Videomonitoramento" e ganham a tela
-- "Alertas Dirigibilidade", venham de câmera ou de rastreador (JM-VL). Nessas
-- linhas não há função de vídeo. Spec:
-- docs/superpowers/specs/2026-09-14-rastreadores-dirigibilidade-design.md
--
-- 🔴 Por CÓDIGO, nunca por nome nem por categoria: `conducao` também guarda
-- Ociosidade, Motorista Alterado e Condução Prolongada, e a colisão mora em
-- `veiculo`/`acidente`. Renomear e recategorizar já desligou dois motores em
-- silêncio (CLAUDE.md). Fora de propósito: 77 (Mudança Abrupta de Faixa),
-- 116 (Velocidade Normalizada) e todo ADAS/DMS de IA (FCW, PCW…).
--
-- Código novo de condução cadastrado depois desta versão tem de receber
-- `is_driving = 1` na MESMA migração — senão cai em Videomonitoramento.

SET time_zone = '+00:00';

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

CALL add_column_if_not_exists('alarm_types', 'is_driving',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Evento de dirigibilidade: tela Alertas Dirigibilidade, sem vídeo (v4.21.0)' AFTER `is_diagnostic`");

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- Lista única: marca E confere. Collation explícita porque o padrão do banco
-- (MySQL 8) é utf8mb4_0900_ai_ci e o JOIN com alarm_types recusaria a mistura.
DROP TEMPORARY TABLE IF EXISTS tmp_driving;
CREATE TEMPORARY TABLE tmp_driving (
    protocol   VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    alarm_code VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (protocol, alarm_code)
);
INSERT INTO tmp_driving (protocol, alarm_code) VALUES
    -- Arrancada / aceleração brusca
    ('JIMI','144'), ('JTT','1024'), ('JTT','1042'),
    -- Freada / frenagem brusca
    ('JIMI','48'), ('JIMI','145'), ('JTT','1025'), ('JTT','1043'),
    -- Curva acentuada
    ('JIMI','76'), ('JIMI','146'), ('JTT','1026'), ('JTT','1044'),
    -- Excesso de velocidade (202 = Aviso de Velocidade, 95 = em Cerca)
    ('JIMI','6'), ('JIMI','135'), ('JIMI','202'), ('JIMI','95'), ('JTT','1027'),
    -- Colisão
    ('JIMI','44'), ('JIMI','147'), ('JTT','1029'), ('JTT','1046'),
    -- Capotamento
    ('JIMI','45'), ('JIMI','106'), ('JIMI','183'), ('JTT','1047'),
    -- Impacto / inclinação
    ('JIMI','55'), ('JIMI','75'), ('JIMI','78'), ('JIMI','79');

UPDATE alarm_types a
  JOIN tmp_driving t ON t.protocol = a.protocol AND t.alarm_code = a.alarm_code
   SET a.is_driving = 1;

SELECT 'is_driving: código esperado AUSENTE do catálogo (deve vir vazio)' AS conferencia;
SELECT t.protocol, t.alarm_code
  FROM tmp_driving t
  LEFT JOIN alarm_types a ON a.protocol = t.protocol AND a.alarm_code = t.alarm_code
 WHERE a.id IS NULL;

SELECT 'is_driving: MESMO NOME de um marcado e sem marca (conferir um a um)' AS conferencia;
SELECT s.protocol, s.alarm_code, s.alarm_name_pt
  FROM alarm_types s
 WHERE s.is_driving = 0
   AND s.alarm_name_pt IN (SELECT m.alarm_name_pt FROM alarm_types m WHERE m.is_driving = 1);

SELECT 'is_driving: ADAS/DMS marcado (deve vir vazio)' AS conferencia;
SELECT protocol, alarm_code, alarm_name_pt FROM alarm_types
 WHERE is_driving = 1 AND category IN ('DMS','ADAS');

SELECT 'is_driving: marcados' AS conferencia;
SELECT protocol, alarm_code, category, alarm_name_pt FROM alarm_types
 WHERE is_driving = 1 ORDER BY alarm_name_pt, protocol, alarm_code;

DROP TEMPORARY TABLE IF EXISTS tmp_driving;
