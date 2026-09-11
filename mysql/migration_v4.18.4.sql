-- ============================================================================
-- Migration v4.18.4 — 3 códigos JT/T sem nome (§2.7 "Other Alarms" da doc oficial)
--
-- Pedido do dono do produto: a câmera do veículo "Telecom" (IMEI
-- 865478070654829, JC450/JT/T) subiu `alertType` 4 e 6 sem nome no catálogo
-- ("Código 4 (JTT)"/"Código 6 (JTT)"). Medido em produção (10-11/09/2026,
-- últimas 48h desse IMEI + 30 dias da frota inteira):
--   - alertType 4:  104 ocorrências, 1 equipamento
--   - alertType 5:    1 ocorrência,  1 equipamento (achado de bônus na varredura)
--   - alertType 6:   14 ocorrências, 1 equipamento
--
-- Fonte: docs.jimicloud.com/integration/integration.html §2.7 "Other Alarms"
-- (JT/T Device Alarms, msgClass=1) — NÃO é a tabela OBD (§3.45 "Table 24 OBD
-- alarm Data ID"), que por coincidência numera 4/5/6 como
-- Geofence-entry/exit/Overspeed: essa tabela pertence ao endpoint /pushobd,
-- que este projeto não implementa. O payload real capturado
-- (`webhook_payloads`, endpoint pushalarm) tem `alarmLabel`/`driverId`/
-- `driverName` no mesmo formato dos alarmes 264/265 já cadastrados — confirma
-- §2.7, não OBD.
--
--   §2.7 | 4 | Seatbelt Fastened (AWSB)          → Cinto de Segurança Afivelado
--   §2.7 | 5 | Face Recognition Failed (AFIF)    → Falha no Reconhecimento Facial
--   §2.7 | 6 | Face Recognition Success (AFIS)   → Reconhecimento Facial Bem-sucedido
--
-- Categoria/severidade/is_diagnostic são JULGAMENTO deste cadastro (a doc não
-- publica isso), por analogia com entradas JTT já cadastradas da mesma seção:
--   - 4 (cinto afivelado): evento de resolução, não alarme — 'seguranca'/'info',
--     is_diagnostic=0 (mesmo raciocínio de '58 Tampa do Dispositivo Aberta
--     (Confirmado)' no catálogo JIMI: aviso positivo, não alerta).
--   - 5 (falha no reconhecimento): falha técnica do módulo DMS, mas que afeta
--     rastreabilidade do motorista — 'dispositivo'/'warning', is_diagnostic=0
--     (mesmo padrão de '3078 Anomalia de Calibração da Câmera DMS': visível,
--     não é só "conversa do equipamento com o sistema").
--   - 6 (reconhecimento OK): confirmação rotineira e frequente —
--     'dispositivo'/'info', is_diagnostic=1 (mesmo padrão de
--     '1040/1041 Modo Repouso/Trabalho': fica fora dos relatórios de alarme
--     por padrão, visível ao administrador no modo diagnóstico).
--
-- 🔴 Achado no caminho, NÃO corrigido nesta migração — fica para o dono do
-- produto decidir: `alertType 1049` (JTT) tem 252 ocorrências em 30 dias (a
-- frota inteira, não só o Telecom) e NÃO CONSTA em lugar nenhum do doc oficial
-- (nem em §2.1-2.7, nem em nenhuma tabela adjacente) — mesma situação do 1047
-- antes da v4.9.10, que só foi resolvido com informação do fornecedor. Não
-- batizado aqui por falta dessa fonte.
--
-- ⚠️ Achado no caminho, também não corrigido aqui — código, não dado:
-- `decodeStandardAlarm()` (handlers/pushalarm.php) tem os bits 12+ do bitmask
-- JT/T 256 ERRADOS (não apenas incompletos) contra a doc oficial §2.1 —
-- ver o commit que acompanha esta migração para a correção do bitmap.
-- ============================================================================

INSERT IGNORE INTO `alarm_types`
    (`alarm_code`, `protocol`, `category`, `severity`, `alarm_name_pt`, `alarm_name_en`, `requires_action`, `is_diagnostic`)
VALUES
    ('4', 'JTT', 'seguranca',  'info',    'Cinto de Segurança Afivelado',       'Seatbelt Fastened (AWSB)',        0, 0),
    ('5', 'JTT', 'dispositivo','warning', 'Falha no Reconhecimento Facial',     'Face Recognition Failed (AFIF)',  0, 0),
    ('6', 'JTT', 'dispositivo','info',    'Reconhecimento Facial Bem-sucedido', 'Face Recognition Success (AFIS)', 0, 1);

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.18.4', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.18.4', last_update = NOW();

-- ============================================================
-- Conferência
-- ============================================================
SELECT 'codigos 4/5/6 (JTT) cadastrados' AS conferencia;
SELECT alarm_code, protocol, category, severity, alarm_name_pt, is_diagnostic
  FROM alarm_types WHERE protocol = 'JTT' AND alarm_code IN ('4','5','6');

SELECT 'Migracao v4.18.4 concluida' AS status;
