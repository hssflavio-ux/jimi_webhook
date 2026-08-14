-- ============================================================================
-- JIMI Webhook System — Migração v4.9.17
--
-- A lista de gravações do cartão passa a ter VALIDADE (30 min por padrão).
--
-- 🔴 O DEFEITO QUE ISTO CORRIGE ESTAVA EM PRODUÇÃO, no JT/T. `resource_lists`
-- só recebia `INSERT` e nada nunca saía. Medido em 14/08/2026:
--
--   860112070347838 → 434 arquivos, gravações de 12–13/07, listados há 32 DIAS
--   869058070151343 →  36 arquivos, gravações de 19/07,    listados há 24 DIAS
--   865478070003241 → 851 arquivos, gravações de 04–06/08, listados há  8 DIAS
--
-- O cartão é buffer circular: gravação de 12/07 numa câmera que roda todo dia
-- foi sobrescrita há muito tempo. A timeline do playback oferecia esses
-- arquivos como se existissem, **sem dizer a idade da informação** — e o
-- download só falharia lá na frente, sem explicar por quê.
--
-- POR QUE VALIDADE POR TEMPO, E NÃO RECONCILIAÇÃO. A retenção real do cartão
-- depende de quantas horas o veículo roda, e não há número fixo (decisão do
-- dono, 14/08/2026). Então a lista não tenta ser um acervo correto: ela é um
-- RETRATO com hora. Passou da validade, deixa de ser acionável e a tela pede
-- uma nova — em vez de fingir que ainda vale.
--
-- POR QUE NÃO APAGAR AO FECHAR A TELA (proposta inicial). `beforeunload` /
-- `unload` não disparam de forma confiável — aba fechada à força, queda de
-- rede, e no Safari do iPhone frequentemente nunca. A limpeza ficaria órfã
-- justamente nos casos que ninguém percebe. A verificação passou para a
-- ABERTURA, que é determinística. (O fechamento ainda limpa por `sendBeacon`,
-- mas como otimização — nada depende dele.)
--
-- `media_files` NÃO é afetada, e é isso que torna seguro descartar a lista:
-- o que já foi baixado para o servidor vive lá, permanente. `resource_lists`
-- é só o espelho volátil do cartão.
-- ============================================================================

-- ── 1. Quando o device reportou este arquivo numa listagem ──────────────────
-- DATETIME, não TIMESTAMP: `created_at` já é TIMESTAMP e não queremos herdar
-- comportamento automático de atualização nesta coluna.
SET @tem := (SELECT COUNT(*) FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND table_name = 'resource_lists'
                AND column_name = 'captured_at');
SET @sql := IF(@tem = 0,
  'ALTER TABLE `resource_lists`
     ADD COLUMN `captured_at` DATETIME NULL DEFAULT NULL
       COMMENT "Instante da listagem que reportou este arquivo (validade curta)",
     ADD INDEX `idx_imei_captured` (`imei`, `captured_at`)',
  'SELECT "coluna captured_at ja existe" AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Backfill ─────────────────────────────────────────────────────────────
-- As linhas existentes recebem o `created_at`, o que as deixa TODAS vencidas
-- na primeira leitura — e isso é o comportamento correto, não um efeito
-- colateral: elas têm de 8 a 32 dias e não descrevem mais o cartão. A tela vai
-- pedir listagem nova, que é exatamente o que deveria ter acontecido antes.
UPDATE `resource_lists` SET `captured_at` = `created_at` WHERE `captured_at` IS NULL;

-- ── 3. Conferência ──────────────────────────────────────────────────────────
SELECT 'linhas sem captured_at (tem de ser 0)' AS conferencia, COUNT(*) AS total
  FROM `resource_lists` WHERE `captured_at` IS NULL;

SELECT imei,
       COUNT(*) AS arquivos,
       TIMESTAMPDIFF(MINUTE, MAX(captured_at), NOW()) AS minutos_desde_captura,
       IF(TIMESTAMPDIFF(MINUTE, MAX(captured_at), NOW()) <= 30, 'VALIDA', 'vencida') AS situacao
  FROM `resource_lists` GROUP BY imei;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.17', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.17', last_update = NOW();

SELECT 'Migracao v4.9.17 concluida' AS status;
