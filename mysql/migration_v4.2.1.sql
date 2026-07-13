-- ============================================================
-- Migração v4.2.1 — Correção do catálogo de câmeras por modelo
-- ============================================================
-- Contexto: a tela de vídeo ao vivo não deixava selecionar CH2+/CH3+ porque
-- lia o camera_count do MODELO (seed antigo, valores errados) em vez do
-- cadastro do equipamento. Semântica corrigida:
--   device_models.camera_count = MÁXIMO de canais do modelo
--   devices.camera_count       = quantidade instalada naquele equipamento
--
-- Máximos reais por modelo (12/07/2026):
--   JC182 = 1 · JC181/JC400D/JC400AD = 2 · JC371 = até 3 · JC450 = até 5
--
-- Idempotente: pode rodar mais de uma vez sem efeito colateral.
-- ============================================================

UPDATE `device_models` SET `camera_count` = 2, `description` = 'Câmera veicular JIMI 2 canais (protocolo JIMI)'            WHERE `model_name` = 'JC400D';
UPDATE `device_models` SET `camera_count` = 2, `description` = 'Câmera veicular JIMI avançada 2 canais (protocolo JIMI)'   WHERE `model_name` = 'JC400AD';
UPDATE `device_models` SET `camera_count` = 3, `description` = 'Câmera veicular JT/T até 3 canais (protocolo JT/T 808)'    WHERE `model_name` = 'JC371';
UPDATE `device_models` SET `camera_count` = 5, `description` = 'Câmera veicular JT/T até 5 canais (protocolo JT/T 808)'    WHERE `model_name` = 'JC450';
UPDATE `device_models` SET `camera_count` = 2, `description` = 'Câmera compacta JT/T 2 canais (protocolo JT/T 808)'        WHERE `model_name` = 'JC181';
UPDATE `device_models` SET `camera_count` = 1, `description` = 'Câmera compacta JT/T 1 canal avançada (protocolo JT/T 808)' WHERE `model_name` = 'JC182';

-- Modelos de contagem FIXA (JC181/JC400D/JC400AD = sempre 2 câmeras;
-- JC182 = sempre 1): alinha o cadastro dos equipamentos existentes que
-- ficaram com o default antigo. Modelos variáveis (JC371, JC450) NÃO são
-- tocados — a quantidade instalada é decisão de cadastro por equipamento.
UPDATE `devices` d
JOIN `device_models` dm ON dm.id = d.device_model_id
SET d.camera_count = dm.camera_count
WHERE dm.model_name IN ('JC181', 'JC400D', 'JC400AD', 'JC182')
  AND (d.camera_count IS NULL OR d.camera_count <> dm.camera_count);

-- ============================================================
-- Versão do sistema
-- ============================================================
INSERT INTO `system_info` (`id`, `version`, `installation_date`, `last_update`)
VALUES (1, '4.2.1', NOW(), NOW())
ON DUPLICATE KEY UPDATE `version` = '4.2.1', `last_update` = NOW();
