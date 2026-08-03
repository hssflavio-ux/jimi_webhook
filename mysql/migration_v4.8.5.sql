-- ============================================================
-- Migração v4.8.5 — Central de Ajuda deixa de dar 403 a grupo restrito
-- ============================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- CONTEXTO. `handlers/router.php` exige `require_permission('wiki','view')` na
-- Central de Ajuda desde a v4.2.0, mas `wiki` NUNCA esteve na matriz de telas
-- de `/grupos-permissao`. E `can()` (includes/auth.php) nega tudo que não está
-- no JSON do grupo:
--
--     return !empty($perms[$screen]) && in_array($action, ...);
--
-- Ou seja: era uma tela protegida que NENHUM grupo podia receber — o admin não
-- tinha como conceder o que não aparecia na tela para marcar. Todo usuário de
-- grupo restrito levava 403 no /wiki, que está na sidebar de todo mundo com o
-- rótulo "Ajuda". O código da v4.8.5 põe `wiki` (e `checklist`) na matriz; esta
-- migração conserta os grupos JÁ GRAVADOS, que não têm a chave.
--
-- POR QUE SÓ `wiki` E NÃO `checklist`. Ajuda é tela de leitura que todo perfil
-- deve alcançar — negá-la é defeito, não política. `checklist` é CRUD de
-- configuração e fica FECHADO por padrão: quem quiser libera em
-- /grupos-permissao, agora que a opção existe. Conceder sozinho seria decidir
-- política de acesso por migração.
--
-- Idempotente: só mexe em grupo cujo JSON não tem a chave `wiki`. Grupos com o
-- curinga `{"*": [...]}` (o "Administrador" do seed da v4.0.0) já passam por
-- `can()` e ficam intocados.

UPDATE permission_groups
   SET permissions = JSON_SET(permissions, '$.wiki', JSON_ARRAY('view'))
 WHERE permissions IS NOT NULL
   AND JSON_VALID(permissions)
   AND JSON_EXTRACT(permissions, '$."*"') IS NULL
   AND JSON_EXTRACT(permissions, '$.wiki') IS NULL;

-- ── Conferência (aparece no log do deploy) ────────────────────────────────
SELECT CONCAT(COUNT(*), ' grupo(s) restrito(s) ainda sem acesso à Ajuda (esperado: 0)') AS pendencia
  FROM permission_groups
 WHERE permissions IS NOT NULL
   AND JSON_VALID(permissions)
   AND JSON_EXTRACT(permissions, '$."*"') IS NULL
   AND JSON_EXTRACT(permissions, '$.wiki') IS NULL;

SELECT id, name, JSON_EXTRACT(permissions, '$.wiki') AS ajuda
  FROM permission_groups ORDER BY id;

-- ── Versão ────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.8.5', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.8.5', last_update = NOW();
