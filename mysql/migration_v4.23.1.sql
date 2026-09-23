-- ============================================================================
-- Migração v4.23.1 — corrige comentário errado de gps_data.gps_mode
-- ============================================================================
-- Sem `USE`: o banco vem da linha de comando (convenção desde a v4.7.3).
--
-- Pedido do dono do produto: conferir se `gpsMode` e `postMethod` (§1.3 Push
-- GPS Data da doc oficial, https://docs.jimicloud.com/integration/integration.html)
-- estavam sendo tratados em handlers/pushgps.php. Os dois JÁ eram extraídos e
-- gravados (gps_mode desde a v2.0.0 do handler, post_method também) — nada de
-- código mudou. O que a conferência achou foi o COMENTÁRIO da coluna errado:
--
-- 🔴 `gps_data.gps_mode` estava comentado '0=GPS, 1=LBS, 2=WiFi' desde o dump
-- original do schema (13/06/2026) — essa é a definição de `postType`, não de
-- `gpsMode`. A tabela oficial da doc (conferida ao vivo nesta sessão) diz:
--
--   gpsMode  | 0: Real-time upload / 1: Re-upload
--   postType | 1: GPS / 2: LBS / 3: WiFi
--
-- `gps_data.post_type` já está com o comentário CERTO (migration_v2.0.0.sql:
-- '1=GPS, 2=LBS, 3=WiFi') e `alarms.gps_mode` também já está certo ('0: Real-time
-- upload, 1: Re-upload') — só o `gps_data.gps_mode` ficou com a legenda trocada.
-- migration_v4.19.2.sql já usava a interpretação CORRETA na prática ("11% dos
-- pontos chegam reenviados, gps_mode=1"), então o dado sempre foi gravado
-- certo — só a documentação embutida na coluna mentia. Sem impacto em runtime:
-- grep confirma que nenhum código decodifica gps_mode em rótulo nenhum hoje
-- (só post_type é decodificado, e com o valor certo). Mesma classe de
-- mislabeling do `postMethod`/`API_COVERAGE_v3.0.0.md` já registrada no
-- CHANGELOG v4.17.11 — fonte arquivada, nunca corrigida contra o device/doc
-- oficial. `postMethod` continua sem tabela de valores publicada (só aparece
-- em exemplo de payload) — nada a corrigir aí, comportamento já correto.
--
-- jimi_tracker.sql (dump base) não é retrocorrigido — mesma convenção já
-- usada para status_bits/v4.21.5: o dump fica congelado, a migração é a
-- fonte de verdade cumulativa que o `db-setup` aplica por cima dele.

SET time_zone = '+00:00';

ALTER TABLE `gps_data`
  MODIFY COLUMN `gps_mode` tinyint DEFAULT '0'
  COMMENT '0: Real-time upload, 1: Re-upload (doc oficial Sec 1.3 Push GPS Data) - NAO e postType (GPS/LBS/WiFi), ver post_type';

-- ============================================================
-- Versão
-- ============================================================
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.23.1', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.23.1', last_update = NOW();

-- ============================================================
-- Conferência
-- ============================================================
SELECT COLUMN_COMMENT AS gps_mode_comment_atual
  FROM information_schema.COLUMNS
 WHERE table_schema = DATABASE() AND table_name = 'gps_data' AND column_name = 'gps_mode';

SELECT 'Migracao v4.23.1 concluida' AS status;
