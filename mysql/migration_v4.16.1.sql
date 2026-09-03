-- ============================================================================
-- bycamera — migration v4.16.1
-- Dois nomes de alarme JIMI corrigidos contra a Alarm Reference OFICIAL
--
-- Achados ao analisar o PRIMEIRO JM-VL01 real em produção (868982050616424,
-- 03/09/2026). O equipamento mandou o par 254/255 com seis minutos de
-- diferença — 255 às 23:33 (desligou), 254 às 23:39 (ligou) —, e foi isso que
-- levantou a suspeita sobre o rótulo do 254. A confirmação veio da doc
-- oficial (`https://docs.jimicloud.com/integration/integration.html`), que diz
-- a MESMA coisa em dois lugares independentes.
--
-- 🔴 `254` — "Status de Ignição Alterado" está ERRADO, e não só para a linha
-- VL: vale para toda a linha JIMI. A doc publica
--     | 254 | Ignition turned on
--     | 254 | int | Ignition (Engine ON) alarm (0xFE)
--     | 255 | int | Engine OFF alarm (0xFF)
-- ou seja, 254 e 255 são os DOIS lados do mesmo par, não um evento genérico de
-- "mudou". O nome antigo apagava a informação que o operador precisa (ligou ou
-- desligou?) e ainda deixava o 255 órfão de sentido ao lado dele. A wiki da
-- linha VL diz o mesmo ("0xFE Ignição ligada / 0xFF Ignição desligada").
--
-- 🔴 `50` — "Alerta de Reboque" está ERRADO, e o erro foi INTRODUZIDO NA
-- v4.16.0, por mim. A wiki da linha VL rotula o 0x32 com uma palavra só,
-- "Puxar", e eu li isso como reboque/guincho. A doc oficial desfaz:
--     | 50 | Device was plugged out
-- "Puxar" ali é o EQUIPAMENTO sendo arrancado da instalação, não o veículo
-- sendo puxado. É o irmão do 19 ("Device was removed" / Dispositivo
-- Desmontado) e nada tem a ver com reboque. Exatamente o modo de falha que o
-- CLAUDE.md descreve como "nunca batizar por palpite": tinha fonte melhor
-- disponível e eu escolhi a mais curta.
--
-- ⚠️ Categoria e severidade NÃO mudam. `notification_rules` casa por CATEGORIA
-- (ver CLAUDE.md), então mexer nelas mudaria volume de notificação — é decisão
-- de produto, não de correção de rótulo. Os dois já estão em `seguranca`.
--
-- ⚠️ Esta migração NÃO roda no deploy que a traz. Rode
-- `./scripts/deploy.sh --force` duas vezes, ou aplique este .sql à mão.
-- ============================================================================

-- ------------------------------------------------------------
-- 1. ANTES: prova de que renomear é seguro
-- ------------------------------------------------------------
-- 🔴 Renomear `alarm_types.alarm_name_pt` desliga, EM SILÊNCIO, todo parâmetro
-- de ocorrência e toda regra de notificação que case por NOME (CLAUDE.md,
-- v4.8.3 e v4.9.5). As duas consultas abaixo têm de vir VAZIAS; se vierem com
-- linha, o `UPDATE` de remapeamento logo adiante é que passa a valer.
SELECT 'ocorrencias que referenciam os nomes antigos (deve vir vazio)' AS conferencia;
SELECT ocp.id, ocp.alarm_type
  FROM `occurrence_config_params` ocp
 WHERE ocp.alarm_type IN ('Status de Ignição Alterado', 'Alerta de Reboque');

SELECT 'regras de notificacao que referenciam os nomes antigos (deve vir vazio)' AS conferencia;
SELECT nr.id, nr.alarm_type
  FROM `notification_rules` nr
 WHERE nr.alarm_type IN ('Status de Ignição Alterado', 'Alerta de Reboque');

-- Remapeamento — inerte quando as consultas acima vêm vazias, que é o caso
-- medido em produção em 03/09/2026. Fica no arquivo porque uma instalação
-- diferente pode ter criado a regra à mão, e a migração não pode assumir.
UPDATE IGNORE `occurrence_config_params`
   SET `alarm_type` = 'Ignição Ligada (ACC)'
 WHERE `alarm_type` = 'Status de Ignição Alterado';
UPDATE IGNORE `occurrence_config_params`
   SET `alarm_type` = 'Dispositivo Arrancado'
 WHERE `alarm_type` = 'Alerta de Reboque';
UPDATE IGNORE `notification_rules`
   SET `alarm_type` = 'Ignição Ligada (ACC)'
 WHERE `alarm_type` = 'Status de Ignição Alterado';
UPDATE IGNORE `notification_rules`
   SET `alarm_type` = 'Dispositivo Arrancado'
 WHERE `alarm_type` = 'Alerta de Reboque';

-- ------------------------------------------------------------
-- 2. O catálogo
-- ------------------------------------------------------------
UPDATE `alarm_types`
   SET `alarm_name_pt` = 'Ignição Ligada (ACC)',
       `alarm_name_en` = 'Ignition turned on',
       `description`   = 'Doc oficial JIMI: "Ignition turned on" / "Ignition (Engine ON) alarm (0xFE)". Par com o 255 (desligada). O nome anterior, "Status de Ignição Alterado", não dizia qual dos dois lados era.'
 WHERE `protocol` = 'JIMI' AND `alarm_code` = '254';

-- O 255 já entrou com o nome PT certo na v4.16.0; só o EN estava abreviado
-- ("ACC Off"). Alinhado com a doc pela mesma razão do 254: é o par dele, e
-- rótulo de par tem de ler igual dos dois lados.
UPDATE `alarm_types`
   SET `alarm_name_en` = 'Ignition turned off',
       `description`   = 'Doc oficial JIMI: "Ignition turned off" / "Engine OFF alarm (0xFF)". Par com o 254 (ligada).'
 WHERE `protocol` = 'JIMI' AND `alarm_code` = '255';

UPDATE `alarm_types`
   SET `alarm_name_pt` = 'Dispositivo Arrancado',
       `alarm_name_en` = 'Device was plugged out',
       `description`   = 'Doc oficial JIMI: "Device was plugged out" — o EQUIPAMENTO foi arrancado da instalação. A wiki da linha VL rotula como "Puxar", que a v4.16.0 leu como reboque/guincho: errado. Irmão do 19 (Device was removed).'
 WHERE `protocol` = 'JIMI' AND `alarm_code` = '50';

-- ------------------------------------------------------------
-- 3. O HISTÓRICO já gravado
-- ------------------------------------------------------------
-- 🔴 `alarms.alarm_name` é DESNORMALIZADO: `pushalarm.php` resolve o nome na
-- CHEGADA e grava a string. Corrigir só `alarm_types` não conserta o passado —
-- e aqui o re-resolve da leitura (`alarm_label_sql()`) também não salva, porque
-- ele só reescreve o rótulo GENÉRICO (`Código NNNN`), nunca um nome de verdade.
-- Sem este UPDATE, todo alarme já gravado continuaria dizendo o nome errado
-- para sempre.
--
-- ⚠️ Casa pelo nome EXATO junto com o código e `msg_class = 0` (JIMI): assim
-- não toca em linha que alguém tenha corrigido à mão, nem no `Fim de Alarme: `,
-- nem em código homônimo do JT/T.
UPDATE `alarms`
   SET `alarm_name` = 'Ignição Ligada (ACC)'
 WHERE `msg_class` = 0 AND `alarm_type` = '254'
   AND `alarm_name` = 'Status de Ignição Alterado';

UPDATE `alarms`
   SET `alarm_name` = 'Dispositivo Arrancado'
 WHERE `msg_class` = 0 AND `alarm_type` = '50'
   AND `alarm_name` = 'Alerta de Reboque';

-- ------------------------------------------------------------
-- 4. Conferência
-- ------------------------------------------------------------
SELECT 'catalogo depois' AS conferencia;
SELECT `alarm_code`, `alarm_name_pt`, `alarm_name_en`, `category`, `severity`
  FROM `alarm_types`
 WHERE `protocol` = 'JIMI' AND `alarm_code` IN ('19', '50', '254', '255')
 ORDER BY CAST(`alarm_code` AS UNSIGNED);

SELECT 'sobrou nome antigo em alarms? (deve vir vazio)' AS conferencia;
SELECT `alarm_type`, `alarm_name`, COUNT(*) AS n
  FROM `alarms`
 WHERE `alarm_name` IN ('Status de Ignição Alterado', 'Alerta de Reboque')
 GROUP BY 1, 2;
