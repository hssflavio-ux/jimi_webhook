-- ============================================================================
-- JIMI Webhook System — Migração v4.9.8
--
-- Registra em `media_files` os anexos de vídeo que só existiam em
-- `alarms.file_url`, e liga as ocorrências que ficaram sem mídia por causa
-- disso.
--
-- O DEFEITO, medido no homolog em 10/08/2026:
--
--     alarms com file_url ....................... 4   (2 arquivos distintos)
--     linhas correspondentes em media_files ..... 0
--     ocorrências com media_file_id NULL ........ 5 de 8
--     arquivos presentes em /iothub/dvr-upload/uploadFile ... TODOS
--
-- Ou seja: o vídeo estava no disco, o alarme sabia o nome do arquivo, e a tela
-- de tratativa dizia "Sem vídeo vinculado".
--
-- POR QUE: em câmera JIMI (msgClass=0) o device sobe o vídeo do evento sozinho
-- e ANUNCIA o nome no próprio push do alarme (ADR-001) — não há webhook de
-- upload. `link_media_to_occurrence()` procurava esse arquivo em `media_files`,
-- e nenhum caminho do sistema o inserta ali: só o fluxo JT/T (extração 37382 →
-- /pushftpfileupload) cria a linha. Como o motor de ocorrências é justamente
-- o núcleo DMS, o efeito atingia o caso principal do produto — em silêncio,
-- porque a ausência de mídia não é erro em lugar nenhum.
--
-- O código passou a inserir a linha na chegada (includes/occurrence_engine.php);
-- esta migração faz o mesmo para trás.
--
-- ⚠️ O tipo sai da EXTENSÃO, não de `alarms.file_type`. O regex que preenchia
-- aquela coluna não conhecia `.ts` — o formato dos anexos JT/T —, então metade
-- dos anexos reais está com NULL lá. `media_files.file_type` é ENUM e recusaria
-- o palpite.
-- ============================================================================

-- ── 1. Índice de busca por nome de arquivo em alarms ────────────────────────
-- `/midia` passou a autorizar o download conferindo `alarms.file_url` (o anexo
-- de alarme não tem linha em media_files nos registros antigos). Sem índice
-- isso é varredura completa da maior tabela do sistema a cada byte servido.
-- `file_url` é TEXT: índice tem de ser de PREFIXO.
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'alarms'
                AND index_name = 'idx_alarm_file_url');
SET @sql := IF(@idx = 0,
    'ALTER TABLE `alarms` ADD KEY `idx_alarm_file_url` (`file_url`(191))',
    'SELECT ''idx_alarm_file_url ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- ── 2. Anexos de alarme → media_files ───────────────────────────────────────
-- Um alarme e o seu "Upload de Vídeo Concluído" carregam o MESMO file_url, daí
-- o agrupamento por (imei, file_url): uma linha por arquivo, não por alarme.
-- `download_status` nasce 'disponivel' porque o alarme é a declaração do device
-- de que o arquivo já está no storage — e nos casos reais o mtime do arquivo é
-- anterior ao push. 'solicitado' deixaria a fila de /video/downloads com um
-- "Aguardando" eterno: não houve pedido de extração para um callback fechar.
INSERT INTO `media_files`
    (imei, file_name, file_type, file_size, file_url, source_type, event_time, download_status)
SELECT a.imei,
       SUBSTRING_INDEX(a.file_url, '/', -1) AS file_name,
       CASE
           WHEN a.file_url REGEXP '\\.(mp4|avi|ts|mov|h264|h265|dav|mkv|flv|wmv)$' THEN 'video'
           WHEN a.file_url REGEXP '\\.(jpg|jpeg|png|gif|bmp|webp)$'                THEN 'image'
           WHEN a.file_url REGEXP '\\.(mp3|amr|wav|aac|ogg|wma|flac)$'             THEN 'audio'
           ELSE 'other'
       END AS file_type,
       0 AS file_size,
       a.file_url,
       'pushalarm' AS source_type,
       MIN(a.alarm_time) AS event_time,
       'disponivel' AS download_status
  FROM `alarms` a
  LEFT JOIN `media_files` mf
         ON mf.imei = a.imei AND mf.file_url = a.file_url
 WHERE a.file_url IS NOT NULL
   AND a.file_url <> ''
   AND mf.id IS NULL
 GROUP BY a.imei, a.file_url;

-- ── 3. Ocorrências sem mídia ← anexo de um dos alarmes agrupados ────────────
-- Vídeo na frente de imagem: a prova do comportamento do motorista é o vídeo.
-- O `ORDER BY` decide o desempate quando a ocorrência agrupa mais de um anexo.
UPDATE `occurrences` o
   SET o.media_file_id = (
        SELECT mf.id
          FROM `occurrence_events` e
          JOIN `alarms` a  ON a.id = e.alarm_id
          JOIN `media_files` mf ON mf.imei = a.imei AND mf.file_url = a.file_url
         WHERE e.occurrence_id = o.id
         ORDER BY (mf.file_type = 'video') DESC, a.alarm_time DESC
         LIMIT 1)
 WHERE o.media_file_id IS NULL
   AND EXISTS (SELECT 1
                 FROM `occurrence_events` e2
                 JOIN `alarms` a2 ON a2.id = e2.alarm_id
                WHERE e2.occurrence_id = o.id
                  AND a2.file_url IS NOT NULL AND a2.file_url <> '');

-- ── 4. Conferência: anexo de alarme sem linha em media_files ────────────────
-- Deve devolver ZERO linhas.
SELECT a.imei, a.file_url AS anexo_sem_registro
  FROM `alarms` a
  LEFT JOIN `media_files` mf ON mf.imei = a.imei AND mf.file_url = a.file_url
 WHERE a.file_url IS NOT NULL AND a.file_url <> ''
   AND mf.id IS NULL
 GROUP BY a.imei, a.file_url;

-- ── 5. Conferência: ocorrência com anexo e ainda sem mídia vinculada ────────
-- Deve devolver ZERO linhas. É a condição que a tela de tratativa lê para
-- decidir entre o player e o "Sem vídeo vinculado".
SELECT o.id AS ocorrencia_sem_midia, o.imei, o.alarm_type
  FROM `occurrences` o
 WHERE o.media_file_id IS NULL
   AND EXISTS (SELECT 1
                 FROM `occurrence_events` e
                 JOIN `alarms` a ON a.id = e.alarm_id
                WHERE e.occurrence_id = o.id
                  AND a.file_url IS NOT NULL AND a.file_url <> '');

-- ── 6. Panorama pós-migração (informativo) ──────────────────────────────────
SELECT COUNT(*)                                AS ocorrencias,
       SUM(media_file_id IS NOT NULL)          AS com_midia,
       SUM(media_file_id IS NULL)              AS sem_midia
  FROM `occurrences`;

-- ── Versão ──────────────────────────────────────────────────────────────────
INSERT INTO system_info (id, version, installation_date, last_update)
VALUES (1, '4.9.8', NOW(), NOW())
ON DUPLICATE KEY UPDATE version = '4.9.8', last_update = NOW();

SELECT 'Migracao v4.9.8 concluida' AS status;
