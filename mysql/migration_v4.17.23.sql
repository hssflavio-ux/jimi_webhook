-- ============================================================================
-- Migration v4.17.23 — webhook_payloads: as linhas de SMS ficavam sem IMEI
--
-- Pedido do dono do produto (07/09/2026): *"precisamos ter a possibilidade do
-- filtro, não é cosmético"*.
--
-- 🔴 O PAYLOAD DA ALLCANCE NÃO TEM IMEI. `webhook_capture_raw()` preenche a
--    coluna com `webhook_raw_sniff_imei()`, que procura `deviceImei`/`imei` no
--    corpo — chaves que o provedor de SMS nunca manda. Resultado medido em
--    produção: **8 de 8** chamadas de `pushsms` com `imei` NULL,
--    `item_count` 0 e `payload_hash` NULL. Filtrar `webhook_payloads` por
--    equipamento simplesmente não trazia os SMS.
--
--    O código passou a preencher os três no INSERT (v4.17.23,
--    `sms_imei_do_lote()` em includes/sms_inbound.php). Esta migração conserta
--    o que já está gravado — sem ela, o filtro continua cego para o histórico.
--
-- O vínculo existe e é indexado:
--    body → `messages[0].referencia_numero` → `sms_commands.referencia`
--    (UNIQUE `uk_sms_referencia`) → `imei`.
-- ============================================================================

SET time_zone = '+00:00';

-- ── 1. IMEI das linhas já gravadas ──────────────────────────────────────────
--
-- ⚠️ `IF(JSON_VALID(body), …, NULL)` e não `JSON_EXTRACT` direto: corpo
-- truncado ou ilegível faz o `JSON_EXTRACT` ABORTAR o script inteiro, e a
-- captura crua grava corpo vazio de propósito (o /filelist passou cinco dias
-- assim — CLAUDE.md). Verificado no servidor: com o IF, corpo inválido e corpo
-- vazio devolvem NULL em vez de erro, e NULL simplesmente não casa no JOIN.
--
-- ⚠️ `messages[0]` — o primeiro item. Mesma convenção do
-- `webhook_raw_sniff_imei()`: a coluna é CONVENIÊNCIA DE CONSULTA, não fonte
-- de verdade. Lote que misture equipamentos grava o primeiro; o corpo tem
-- todos, e a linha continua achável por endpoint e data.
UPDATE `webhook_payloads` wp
  JOIN `sms_commands` sc
    ON sc.referencia = IF(JSON_VALID(wp.body),
                          JSON_UNQUOTE(JSON_EXTRACT(wp.body, '$.messages[0].referencia_numero')),
                          NULL)
   SET wp.imei = sc.imei
 WHERE wp.endpoint = 'pushsms'
   AND wp.imei IS NULL
   AND sc.imei IS NOT NULL;

-- ── 2. Contagem de itens ────────────────────────────────────────────────────
UPDATE `webhook_payloads`
   SET item_count = IF(JSON_VALID(body),
                       COALESCE(JSON_LENGTH(body, '$.messages'), 0),
                       0)
 WHERE endpoint = 'pushsms'
   AND item_count = 0;

-- ── 3. Hash do corpo ────────────────────────────────────────────────────────
--
-- 🔴 Aqui ele NÃO é anti-replay (o UPDATE por referência já é idempotente) —
-- é o que IDENTIFICA O REENVIO DO PROVEDOR. Medido em 07/09/2026: a Allcance
-- mandou o MESMO evento duas vezes, a 1 segundo de distância, byte a byte
-- igual (payloads #11260 e #11261, hash `13574cc7…`). Sem o hash, as duas
-- linhas parecem eventos distintos.
UPDATE `webhook_payloads`
   SET payload_hash = MD5(body)
 WHERE endpoint = 'pushsms'
   AND (payload_hash IS NULL OR payload_hash = '')
   AND body IS NOT NULL;

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.17.23', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.17.23', last_update = NOW();

-- ============================================================
-- Conferência — deve devolver 0 nas três
-- ============================================================
SELECT CONCAT('pushsms ainda sem imei: ', COUNT(*)) AS status
  FROM webhook_payloads wp
 WHERE wp.endpoint = 'pushsms' AND wp.imei IS NULL
   AND EXISTS (SELECT 1 FROM sms_commands sc
                WHERE sc.referencia = IF(JSON_VALID(wp.body),
                        JSON_UNQUOTE(JSON_EXTRACT(wp.body, '$.messages[0].referencia_numero')), NULL));

SELECT CONCAT('pushsms ainda sem item_count: ', COUNT(*)) AS status
  FROM webhook_payloads
 WHERE endpoint = 'pushsms' AND item_count = 0 AND JSON_VALID(body) = 1;

SELECT CONCAT('pushsms ainda sem hash: ', COUNT(*)) AS status
  FROM webhook_payloads
 WHERE endpoint = 'pushsms' AND (payload_hash IS NULL OR payload_hash = '');

SELECT 'Migracao v4.17.23 concluida' AS status;
