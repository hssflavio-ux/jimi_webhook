-- ============================================================================
-- JIMI Webhook System — Migração v4.9.37
--
-- O download passa a ser um ESTADO do arquivo, não só uma ação que some.
--
-- POR QUÊ. A fila de `/video/downloads` sabia dizer "pedido" e "pronto", e
-- parava aí. Quem opera precisa da terceira: **já baixei este?** Sem ela, uma
-- fila com dezenas de vídeos prontos não distingue o que já foi levado do que
-- ainda ninguém tocou — e o operador baixa duas vezes ou deixa passar.
--
-- POR QUE DUAS COLUNAS E NÃO UM VALOR NOVO NO ENUM. `download_status` descreve
-- o caminho DEVICE → SERVIDOR (`solicitado` → `disponivel` | `erro`). O download
-- do usuário é outro eixo — SERVIDOR → PESSOA — e acontece depois, podendo
-- repetir. Enfiá-lo no mesmo enum apagaria a informação de que o arquivo chegou
-- (que é o que o callback do device precisa consultar) e tornaria impossível
-- responder "chegou mas ninguém baixou".
--
-- 🔴 QUEM MARCA É O DOWNLOAD EXPLÍCITO, NÃO O PLAYER. O player de MPEG-TS busca
-- os bytes pelo MESMO `/midia?f=`, por `fetch`, para remuxar — tocar um vídeo
-- dispararia "baixado" sem ninguém ter baixado nada. Por isso o link de
-- download leva `&dl=1` e só ele carimba. Ver handlers/midia.php.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── media_files.downloaded_at / downloaded_by ───────────────────────────────
SET @existe := (
    SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name   = 'media_files'
       AND column_name  = 'downloaded_at'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE `media_files`
     ADD COLUMN `downloaded_at` DATETIME NULL DEFAULT NULL
         COMMENT ''1o download pelo usuario (UTC); NULL = ninguem baixou''
         AFTER `download_status`,
     ADD COLUMN `downloaded_by` BIGINT UNSIGNED NULL DEFAULT NULL
         COMMENT ''users.id de quem baixou primeiro''
         AFTER `downloaded_at`,
     ADD COLUMN `download_count` INT UNSIGNED NOT NULL DEFAULT 0
         COMMENT ''quantas vezes foi baixado''
         AFTER `downloaded_by`',
  'SELECT ''media_files.downloaded_at ja existe'' AS aviso');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Índice para a tela filtrar "prontos e ainda não baixados", que é a pergunta
-- que a fila passa a responder.
SET @existe := (
    SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name   = 'media_files'
       AND index_name   = 'idx_media_baixado'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE `media_files` ADD INDEX `idx_media_baixado` (`imei`, `download_status`, `downloaded_at`)',
  'SELECT ''idx_media_baixado ja existe'' AS aviso');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ⚠️ NENHUM backfill: não há registro histórico de quem baixou o quê, e
-- inventar `downloaded_at` a partir de `created_at` diria que todo arquivo
-- antigo já foi baixado — o oposto da verdade para os 29 órfãos que nem
-- apareciam na tela. O passado fica NULL, que é exatamente "não se sabe".

SELECT CONCAT('media_files: ', COUNT(*), ' arquivos, ',
              SUM(downloaded_at IS NOT NULL), ' ja baixados') AS resultado
  FROM media_files;
