-- ============================================================================
-- JIMI Webhook System — Migração v4.9.25
--
-- Tira o APN (`16`/`17`/`18`) da escrita, porque o valor que o `33028` reporta
-- é FALSO e a tela oferecia corrigi-lo.
--
-- ── A MEDIÇÃO ───────────────────────────────────────────────────────────────
-- Sonda em quatro câmeras de produção, 16/08/2026. Os três equipamentos que
-- respondem ao `33028` informam:
--
--     "16":"cmnet", "17":"usr", "18":"pwd"
--
-- que são os valores de FÁBRICA, literalmente. O comando JIMI `APN#` nos
-- mesmos equipamentos devolve, no mesmo minuto:
--
--     371_3241  -> allcombl.br,allcom,allcom,IP
--     371_2     -> allcom.br,allcom,allcom,IP
--     FJR7B59   -> allcombl.br,allcom,allcom
--
-- Três provas independentes de que o `33028` está errado, não o `APN#`:
--   1. Os equipamentos têm APNs DIFERENTES entre si (`allcombl.br` vs
--      `allcom.br`) e o `33028` diz `cmnet` para todos — é constante, não
--      leitura.
--   2. O `STATUS#` do 371_2, que é outra via, reporta
--      `APN:allcom.br,allcom,allcom,IP` junto de `IP_ADDR:"10.36.8.253"` — o
--      equipamento está conectado, e não é com `cmnet`.
--   3. `cmnet` é APN da China Mobile. Não autenticaria numa SIM brasileira.
--   4. (JC182) o `ASETAPN#` confirma pela quarta via.
--
-- ── POR QUE ISSO É PERIGOSO E NÃO SÓ FEIO ───────────────────────────────────
-- `16`/`17`/`18` estavam `writable = 1`. A tela mostra `cmnet` — que é
-- visivelmente errado para quem conhece a frota — e oferece o campo de
-- correção. Escrever ali grava no slot JT/T, e NÃO SE SABE se é esse slot que
-- o modem obedece na próxima discagem: se for, e o valor sair errado, a câmera
-- perde a conexão e só volta por SMS (PROJETO_PARAMETROS.md §8.1). O convite
-- ao erro é o defeito; o dado falso é só a isca.
--
-- Ficam SOMENTE LEITURA até alguém medir qual das duas memórias vence. O
-- caminho de leitura correto passa a ser o `APN#` pelo proNo 128, agora
-- catalogado (`includes/command_catalog.php`, campo `consulta`).
--
-- ⚠️ `19` (Servidor Principal) NÃO entra aqui: ele foi conferido contra o
-- `SERVER#` nos quatro equipamentos e BATE. Mexer nele por associação seria
-- tirar da operação uma escrita que funciona.
-- ============================================================================

-- ── 1. APN sai da escrita ───────────────────────────────────────────────────
UPDATE `device_param_catalog` SET
  writable = 0,
  doc_ref  = 'JT/T 808 0x0010 (valor NAO confiavel)'
WHERE param_no = 16;

UPDATE `device_param_catalog` SET
  writable = 0,
  doc_ref  = 'JT/T 808 0x0011 (valor NAO confiavel)'
WHERE param_no = 17;

UPDATE `device_param_catalog` SET
  writable = 0,
  doc_ref  = 'JT/T 808 0x0012 (valor NAO confiavel)'
WHERE param_no = 18;

-- ── 2. Conferências ─────────────────────────────────────────────────────────

-- 2a. Os três têm de estar somente leitura. VAZIO.
SELECT 'APN ainda gravavel (tem de vir vazio)' AS conferencia,
       GROUP_CONCAT(param_no) AS params
  FROM `device_param_catalog`
 WHERE param_no IN (16,17,18) AND writable = 1;

-- 2b. O `19` tem de continuar gravável — a conferência que impede o excesso
--     de zelo de virar perda de função.
SELECT 'servidor principal segue gravavel (tem de dizer 1)' AS conferencia,
       writable, doc_ref
  FROM `device_param_catalog` WHERE param_no = 19;

-- 2c. Estado do bloco de rede, para o operador ver de uma vez.
SELECT param_no, name_pt, writable, is_network, doc_ref
  FROM `device_param_catalog`
 WHERE grupo = 'rede' AND param_no IN (16,17,18,19,20,21,22,23,24,25)
 ORDER BY param_no;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.25', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.25', last_update = NOW();

SELECT 'Migracao v4.9.25 concluida' AS status;
