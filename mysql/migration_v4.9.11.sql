-- ============================================================================
-- JIMI Webhook System — Migração v4.9.11
--
-- Abre a F1 do `PROJETO_PARAMETROS.md` por dois consertos que valem por si e
-- não dependem de nenhuma decisão do resto do blueprint.
--
-- ── 1. `command_responses.command_content` PERDE DADO HOJE ──────────────────
--
-- A coluna é `varchar(250)` e `pushinstructresponse.php` ainda fazia
-- `substr($content, 0, 250)` antes de gravar. O campo que ela recebe é o
-- `_content` do callback — que para a família 33028/33030 é a CONFIGURAÇÃO
-- INTEIRA do equipamento.
--
-- 🔴 Não é hipótese. A linha `id=14` deste banco tem `LENGTH(command_content)`
-- = 250 EXATO: uma resposta de VERSION do JC371 cortada no limite da coluna.
-- E a sonda de 12/08/2026 mediu o `_content` de um 33028 real do JC371 em
-- **612 bytes** — 60% se perderia, e o que sobra é um JSON sintaticamente
-- quebrado, que nenhum parser consegue nem recusar direito.
--
-- O caminho offline também NÃO é exceção: na mesma sonda o JC182 recusou o
-- comando (`_code:600`, virou fila offline) com `last_communication` de
-- segundos antes. Quem responde por callback é a maioria, não a borda.
--
-- ⚠️ TEXT e não varchar maior: o `_content` cresce com o número de canais da
-- câmera (o JC450 tem 5) e com parâmetros que o firmware acrescenta. Escolher
-- um teto novo seria repetir o erro com um número diferente.
--
-- ── 2. `/config` estava FORA dos dois mapas de permissão ────────────────────
--
-- `config.php` está em `$simpleRoutes` (router.php) e não estava nem em
-- `$screenByHandler` nem em `$screens`. Quinta ocorrência da armadilha que o
-- CLAUDE.md documenta — depois de `checklist`, `wiki`, `config-notificacoes` e
-- `config-smtp`. É a tela que RECONFIGURA CÂMERA, e qualquer usuário logado a
-- abria.
--
-- A correção é em código (as duas entradas), não em SQL. Esta migração só
-- registra a consequência, porque ela é real e visível ao usuário:
--
-- 🔴 `can()` NEGA POR OMISSÃO para quem tem grupo. O grupo "Operador Padrão"
-- (id 2) não lista `config-dispositivos`, então ele PERDE o acesso a /config —
-- que é o objetivo, mas é mudança de comportamento. Quem tem `permissions`
-- `{"*": [...]}` (Administrador) e quem está SEM grupo (`permission_group_id
-- IS NULL`, os dois usuários deste banco) não são afetados.
--
-- Deliberadamente NÃO concedemos a tela aos grupos existentes: conceder de
-- volta o que nunca deveria ter sido concedido transformaria a correção em
-- nada. O admin libera pela tela de Grupos de Permissão, onde a entrada nova
-- agora aparece para marcar — que é justamente o que a matriz incompleta
-- impedia.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

-- ── 1. A coluna ─────────────────────────────────────────────────────────────
SET @t := (SELECT DATA_TYPE FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'command_responses'
              AND column_name = 'command_content');
SET @sql := IF(@t <> 'text',
    'ALTER TABLE `command_responses`
       MODIFY COLUMN `command_content` TEXT NULL
       COMMENT ''Comando ecoado pelo callback (_content). TEXT desde v4.9.11: era varchar(250) e truncava a configuracao inteira do equipamento''',
    'SELECT ''command_content ja e TEXT'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Conferência: o tipo novo ─────────────────────────────────────────────
SELECT column_name, data_type, character_maximum_length
  FROM information_schema.columns
 WHERE table_schema = DATABASE() AND table_name = 'command_responses'
   AND column_name = 'command_content';

-- ── 3. Conferência: o que já foi truncado (histórico, não recuperável) ──────
-- Linha com LENGTH exatamente 250 é resposta cortada no limite da coluna
-- antiga. O dado perdido NÃO volta — alargar a coluna só impede novas perdas.
-- Serve para dimensionar o estrago e para provar que o defeito era real.
SELECT id, imei, LENGTH(command_content) AS tamanho, created_at
  FROM `command_responses`
 WHERE LENGTH(command_content) = 250
 ORDER BY id;

-- ── 4. Conferência: grupos que perdem /config pela correção de permissão ────
-- Grupo com `permissions` explícito e SEM `config-dispositivos` deixa de abrir
-- a tela. Wildcard `*` e usuário sem grupo não são afetados.
SELECT pg.id, pg.name,
       CASE WHEN JSON_CONTAINS_PATH(pg.permissions, 'one', '$."*"')             THEN 'mantem (wildcard)'
            WHEN JSON_CONTAINS_PATH(pg.permissions, 'one', '$."config-dispositivos"') THEN 'mantem (concedido)'
            ELSE 'PERDE /config'
       END AS efeito
  FROM `permission_groups` pg
 ORDER BY pg.id;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.11', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.11', last_update = NOW();

SELECT 'Migracao v4.9.11 concluida' AS status;
