-- ============================================================================
-- JIMI Webhook System — Migração v4.10.0
--
-- Tipo de veículo no cadastro de ativos, para o ícone colorido por estado no
-- mapa de /rastreamento (item 5 do docs/PLANO_IMPLEMENTACAO_v4.10.md).
--
-- Sem backfill: não há como inferir o tipo de veículo do parque já cadastrado
-- a partir de dado nenhum que o sistema já tenha (modelo de câmera não diz o
-- tipo do veículo onde ela está instalada). `NULL` é o estado de todo device
-- existente, e o comportamento nesse caso é o ATUAL — pin sem ícone, só o
-- círculo colorido — para não regredir visualmente quem não cadastrar o tipo.
--
-- Idempotente: pode rodar duas vezes.
-- ============================================================================

SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'devices'
              AND column_name = 'vehicle_type');
SET @sql := IF(@c = 0,
    "ALTER TABLE `devices` ADD COLUMN `vehicle_type`
       ENUM('carro','van','caminhao','onibus','moto','trator') NULL DEFAULT NULL
       COMMENT 'Tipo de veiculo p/ icone do mapa (Tabler Icons) — NULL = nao informado, pin vira so um ponto colorido'
       AFTER `device_name`",
    'SELECT ''vehicle_type ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SELECT CONCAT('devices: ', COUNT(*), ' dispositivos, ',
              SUM(vehicle_type IS NOT NULL), ' com tipo de veiculo informado') AS resultado
  FROM devices;
