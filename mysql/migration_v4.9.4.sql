-- ============================================================================
-- JIMI Webhook System — Migração v4.9.4
--
-- Tira o nome antigo do produto do remetente dos e-mails.
--
-- O sintoma relatado: os relatórios agendados chegavam assinados como
-- "Jimi Tracker". O produto se chama `bycamera` desde a v4.8.0, mas o nome
-- antigo sobreviveu em três camadas independentes, e corrigir só uma não
-- muda o que o destinatário lê:
--
--   1. `includes/mailer.php` — fallback do `from_name` (corrigido em código);
--   2. `.env` / `SMTP_FROM_NAME` — fallback de instalação (corrigido no
--      `.env.example`, mas um `.env` já existente no servidor pode ter o
--      valor antigo escrito à mão — conferir lá);
--   3. **esta tabela** — o DEFAULT da coluna abaixo, e o valor JÁ GRAVADO na
--      linha de configuração. Este é o caminho que efetivamente vencia: a
--      precedência de `mail_config()` é banco → `.env`, então enquanto a
--      linha existir com o nome antigo, nenhuma mudança em PHP aparece no
--      e-mail. Era por aqui que o homolog enviava.
--
-- O UPDATE é deliberadamente estreito: casa a string exata `'Jimi Tracker'`
-- (o valor que o DEFAULT da v4.4.1 gravava sozinho) e nada mais. Quem
-- personalizou o remetente — o nome da própria transportadora, por exemplo —
-- não é tocado. Sobrescrever isso seria trocar um nome errado por outro.
--
-- Idempotente: pode rodar duas vezes. Na segunda o UPDATE casa zero linhas.
-- ============================================================================

-- ── DEFAULT da coluna, para linhas futuras ──────────────────────────────────
-- A v4.4.1 criou a coluna com DEFAULT 'Jimi Tracker'. Qualquer INSERT que
-- omitisse `from_name` reintroduziria o nome antigo. `handlers/config_smtp.php`
-- hoje sempre envia a coluna, mas o DEFAULT é a rede de segurança e precisa
-- concordar com a marca.
ALTER TABLE `smtp_settings`
  MODIFY COLUMN `from_name` varchar(120)
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    DEFAULT 'bycamera';

-- ── Linhas já gravadas com o nome antigo ────────────────────────────────────
UPDATE `smtp_settings`
   SET `from_name` = 'bycamera'
 WHERE `from_name` = 'Jimi Tracker';

-- ── Conferência: nenhum remetente pode restar com o nome antigo ─────────────
-- Deve devolver ZERO linhas. Se devolver alguma, o valor gravado é uma
-- variação que o UPDATE acima não casa (espaço a mais, caixa diferente,
-- "JIMI Tracker") e precisa ser corrigido à mão em /config-smtp — o e-mail
-- continuará saindo com o nome antigo até lá.
SELECT id, customer_id, from_email, from_name AS remetente_com_nome_antigo
  FROM `smtp_settings`
 WHERE `from_name` LIKE '%jimi%';

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.4', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.4', last_update = NOW();

SELECT 'Migracao v4.9.4 concluida' AS status;
