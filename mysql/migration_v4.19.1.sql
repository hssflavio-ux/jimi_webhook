-- ============================================================================
-- Migração v4.19.1 — celular do JT/T (265-2) volta a gerar ocorrência
-- ============================================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- 🔴 O QUE ACONTECEU. A v4.8.3 renomeou o JT/T `265-2` para
-- "DMS: Chamada Telefônica", e nenhum perfil de ocorrência ganhou parâmetro
-- com esse nome. Os perfis têm "DMS: Motorista ao Telefone" (casa só com o
-- JIMI 151) e "DMS: Uso de Celular" (casa com o JT/T 265-13).
-- `get_occurrence_param()` resolve por NOME, então o 265-2 não acha parâmetro
-- e o alarme é gravado SEM gerar ocorrência — nada no log, nada na tela.
-- Medido em produção em 13/09/2026: 28 alertas de celular do JT/T em 90 dias,
-- nenhum com ocorrência. É a mesma armadilha do CLAUDE.md que a v4.8.6 corrigiu
-- para outros 21 parâmetros; este caso escapou porque não havia nome ANTIGO a
-- remapear — o nome novo simplesmente nunca foi cadastrado.
--
-- ── Regra ─────────────────────────────────────────────────────────────────
-- Chamada telefônica é o MESMO comportamento de "Motorista ao Telefone" do
-- JIMI (CLAUDE.md: procurar o mesmo evento no outro protocolo antes de decidir
-- nome ou regra). Todo perfil que já configurou o JIMI ganha o JT/T com os
-- mesmos valores. `INSERT IGNORE` respeita `uk_config_alarm (config_id,
-- alarm_type)`: quem já cadastrou "DMS: Chamada Telefônica" à mão fica como está.
--
-- Backup recomendado antes:
--   mysqldump ... occurrence_config_params > backup_params_v4191.sql

INSERT IGNORE INTO occurrence_config_params
       (config_id, alarm_type, generates_occurrence, risk, threshold)
SELECT config_id, 'DMS: Chamada Telefônica', generates_occurrence, risk, threshold
  FROM occurrence_config_params
 WHERE alarm_type = 'DMS: Motorista ao Telefone';

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.19.1', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.19.1', last_update = NOW();

-- ============================================================
-- Conferência (aparece no log do deploy)
-- ============================================================
-- Perfis que têm o JIMI e ainda NÃO têm o JT/T devem dar 0.
SELECT CONCAT(COUNT(*), ' perfil(is) com "Motorista ao Telefone" sem "Chamada Telefônica" (esperado: 0)') AS conferencia
  FROM occurrence_config_params j
 WHERE j.alarm_type = 'DMS: Motorista ao Telefone'
   AND NOT EXISTS (SELECT 1 FROM occurrence_config_params t
                    WHERE t.config_id = j.config_id
                      AND t.alarm_type = 'DMS: Chamada Telefônica');

-- O parâmetro novo precisa resolver para o 265-2 do catálogo; se der 0, o nome
-- em alarm_types mudou de novo e esta migração não religou nada.
SELECT CONCAT(COUNT(*), ' linha(s) do catálogo casam com "DMS: Chamada Telefônica" (esperado: 1, JTT 265-2)') AS conferencia
  FROM alarm_types
 WHERE alarm_name_pt = 'DMS: Chamada Telefônica';

SELECT 'Migracao v4.19.1 concluida' AS status;
