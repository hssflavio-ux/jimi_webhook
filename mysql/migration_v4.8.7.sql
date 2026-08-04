-- ============================================================
-- Migração v4.8.7 — decisões de produto sobre o motor de ocorrências
-- ============================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- A v4.8.6 religou o motor e deixou DUAS perguntas em aberto, ambas de produto
-- e não de código. Respondidas pelo usuário em 03/08/2026:
--
--   1. Três parâmetros resolviam mas estavam com `generates_occurrence = 0`
--      (a migração preservou o que já estava no banco). **Devem gerar.**
--   2. Cinco parâmetros sobraram sem alvo no catálogo. **Quatro não serão
--      usados; só a falha de autenticação fica.**
--
-- ── Sobre a falha de autenticação ─────────────────────────────────────────
-- O parâmetro se chamava "DMS: Falha na Autenticação ID" e apontava para o
-- vazio: a v4.8.3 provou que `265-13` é *Phone use*, não autenticação, e o
-- nome antigo era invenção. Para o parâmetro passar a valer é preciso um
-- alarme REAL por trás — e a doc oficial tem a família certa em 2.7:
--
--     3083  DLT card login alarm
--     3084  DLT card logout alarm
--     3085  DLT non-registered card alarm   ← a falha de autenticação
--
-- `3085` é literalmente "cartão não cadastrado": o motorista apresentou um
-- cartão que o sistema não reconhece. Nenhum dos três estava em `alarm_types`
-- (conferido: 0 linhas) e nenhum alarme desses jamais chegou (0 linhas em
-- `alarms`), então cadastrá-los não reescreve histórico nenhum.
--
-- ⚠️ Ressalva taxonômica registrada de propósito: DMS, ao pé da letra, é o
-- sistema de câmera/IA, e cartão DLT é RFID — não é evento de câmera. Ainda
-- assim `3085` entra como categoria **DMS**, porque o filtro de alarmes do
-- produto lista SÓ DMS/ADAS (v4.8.3) e é a única superfície onde evento de
-- motorista aparece. Fora dela, o alarme existiria e seria infiltrável. O
-- prefixo `DMS:` acompanha, pela regra da v4.8.3 §7b (entrada do filtro se
-- anuncia). `3083`/`3084` são login/logout, não infração: ficam em `Device`,
-- fora do filtro, cadastrados só para não caírem no rótulo genérico
-- "Código 3083 (JTT)" — mesma razão da v4.8.3 §6.

-- ── 1. Os três que devem gerar ocorrência ─────────────────────────────────
UPDATE occurrence_config_params
   SET generates_occurrence = 1
 WHERE alarm_type IN (
        'DMS: Distração do Motorista',
        'DMS: Motorista ao Telefone',
        'ADAS: Colisão Frontal (FCW)'
       );

-- ── 2. Os quatro que não serão usados ─────────────────────────────────────
-- Nomes que a doc oficial não publica — a v4.8.1/v4.8.3 mostraram que eram
-- invenção. A v4.8.6 os manteve porque apagar configuração alheia por conta
-- própria seria invasivo; agora há decisão explícita para apagar.
DELETE FROM occurrence_config_params
 WHERE alarm_type IN (
        'Capotamento',
        'Olhar Lateral Prolongado',
        'Comendo ou Bebendo ao Volante',
        'DMS: Comendo ou Bebendo ao Volante'
       );

-- ── 3. A família de cartão do motorista entra no catálogo ─────────────────
INSERT INTO alarm_types (alarm_code, protocol, category, severity, alarm_name_pt, alarm_name_en, requires_action) VALUES
('3085','JTT','DMS',   'high','DMS: Falha de Autenticação do Motorista','DLT non-registered card alarm', 1),
('3083','JTT','Device','info','Login do Cartão do Motorista',           'DLT card login alarm',          0),
('3084','JTT','Device','info','Logout do Cartão do Motorista',          'DLT card logout alarm',         0)
ON DUPLICATE KEY UPDATE alarm_name_pt=VALUES(alarm_name_pt), alarm_name_en=VALUES(alarm_name_en),
                        category=VALUES(category), severity=VALUES(severity), requires_action=VALUES(requires_action);

-- ── 4. O parâmetro passa a apontar para o alarme real ─────────────────────
-- `get_occurrence_param()` casa por `at.alarm_name_pt = ocp.alarm_type`, então
-- o nome do parâmetro tem de ser IDÊNTICO ao do catálogo, não parecido.
UPDATE occurrence_config_params
   SET alarm_type = 'DMS: Falha de Autenticação do Motorista'
 WHERE alarm_type = 'DMS: Falha na Autenticação ID';

-- ── 5. Conferência (aparece no log do deploy) ─────────────────────────────
SELECT CONCAT(
         SUM(at.id IS NOT NULL), ' de ', COUNT(*),
         ' parâmetro(s) de ocorrência resolvem (esperado: todos)'
       ) AS resultado
  FROM occurrence_config_params ocp
  LEFT JOIN alarm_types at ON (at.alarm_name_pt = ocp.alarm_type
                            OR at.alarm_name_en = ocp.alarm_type
                            OR at.category      = ocp.alarm_type);

SELECT ocp.alarm_type, ocp.generates_occurrence, ocp.risk
  FROM occurrence_config_params ocp
 WHERE ocp.alarm_type IN ('DMS: Distração do Motorista','DMS: Motorista ao Telefone',
                          'ADAS: Colisão Frontal (FCW)','DMS: Falha de Autenticação do Motorista')
 ORDER BY ocp.alarm_type;

SELECT CONCAT(COUNT(*), ' parâmetro(s) ainda sem alvo no catálogo (esperado: 0)') AS pendencia
  FROM occurrence_config_params ocp
  LEFT JOIN alarm_types at ON (at.alarm_name_pt = ocp.alarm_type
                            OR at.alarm_name_en = ocp.alarm_type
                            OR at.category      = ocp.alarm_type)
 WHERE at.id IS NULL;

-- ── Versão ────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.8.7', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.8.7', last_update = NOW();
