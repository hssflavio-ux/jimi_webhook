-- ═══════════════════════════════════════════════════════════════════════════
-- Migration v4.13.21 — "Senha temporária por e-mail"
--
-- Criação de usuário sem senha digitada pelo admin: o sistema gera uma senha
-- temporária de 6 caracteres, manda por e-mail e obriga a troca no primeiro
-- acesso. Mesma mecânica atende o "esqueci minha senha".
--
-- 🔴 A senha temporária NÃO é um token à parte: ela É a senha, gravada com
-- bcrypt em `users.password_hash` como qualquer outra. O que a distingue são
-- as três colunas abaixo. Por isso não existe tabela de token de reset — o
-- login continua sendo um `password_verify()` só, e não há um segundo caminho
-- de autenticação para manter em dia.
--
-- O estado "usuário criado mas o e-mail não saiu" é DERIVADO
-- (`must_change_password = 1 AND temp_password_sent_at IS NULL`), não uma
-- quarta coluna: coluna de estado que duplica o que já dá para deduzir é
-- coluna que um dia diverge do resto.
-- ═══════════════════════════════════════════════════════════════════════════

-- ------------------------------------------------------------
-- Auxiliar idempotente (mesmo padrão de v3.1.0/v4.0.0/v4.1.0)
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
-- 1. users — as três colunas da senha temporária
-- ============================================================
CALL add_column_if_not_exists('users', 'must_change_password',
    "TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = a senha atual é temporária; require_login() prende o usuário em /trocar-senha'");

-- DATETIME, não TIMESTAMP: o projeto guarda tudo em UTC e converte na
-- exibição (fmt_brt). TIMESTAMP no MySQL converte sozinho conforme a
-- time_zone da conexão, que é o tipo de conversão dupla que este projeto
-- evita de propósito.
CALL add_column_if_not_exists('users', 'temp_password_expires_at',
    "DATETIME NULL DEFAULT NULL COMMENT 'Prazo da senha temporária (24 h, UTC). Vencida, o login recusa e manda usar /esqueci-senha'");

CALL add_column_if_not_exists('users', 'temp_password_sent_at',
    "DATETIME NULL DEFAULT NULL COMMENT 'Quando o e-mail com a temporária saiu (UTC). NULL + must_change_password=1 = criado e NÃO entregue'");

DROP PROCEDURE IF EXISTS `add_column_if_not_exists`;

-- ============================================================
-- 2. password_reset_log — limite de abuso do /esqueci-senha
-- ============================================================
-- Rota pública: sem isso, qualquer um dispara e-mail em rajada usando o nosso
-- SMTP contra a caixa de terceiros (e queima a reputação do remetente).
-- Guarda o PEDIDO, não o resultado — o e-mail digitado pode nem existir, e é
-- justamente essa tentativa que interessa contar.
--
-- Não reaproveita `login_log` de propósito: aquela tabela alimenta a estatística
-- de login e o rate limit de senha errada; pedido de recuperação entrando lá
-- viraria "falha de login" em toda leitura que já existe.
CREATE TABLE IF NOT EXISTS `password_reset_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email`      VARCHAR(255) NOT NULL COMMENT 'E-mail digitado no formulário — pode não existir em users',
  `ip_address` VARCHAR(45) NOT NULL COMMENT 'IPv4 ou IPv6 de quem pediu',
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_prl_ip_time` (`ip_address`, `created_at`),
  KEY `idx_prl_email_time` (`email`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Pedidos de recuperação de senha (rate limit da rota pública /esqueci-senha)';

-- ============================================================
-- 3. Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.13.21', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.13.21', last_update = NOW();

SELECT 'Migracao v4.13.21 concluida' AS status;
