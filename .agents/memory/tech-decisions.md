---
type: project
created: 2026-07-06
updated: 2026-07-06
---

# Technical Decisions — jimi_webhook v4.0.0

## Auth: Token em Cookie vs PHP Sessions
- **Decisão**: Token 64-char hex em cookie `jimi_token` + tabela `sessions` MySQL
- **Por quê**: `session_start()` depende de arquivos em disco com permissão. Token MySQL é portátil e não quebra em deploy.
- **Cookie flags**: Secure (auto-detect HTTPS), HttpOnly, SameSite=Lax
- **Limpeza**: `auth_cleanup()` chamado em ~1% das requests (DELETE sessions expiradas + request_logs >7 dias)

## Motor de Ocorrências: pushalarm → occurrence_engine
- **Decisão**: `occurrence_engine.php` chamado dentro de `pushalarm.php` após INSERT do alarme
- **Matching triplo**: código numérico → nome resolvido → categoria via JOIN com `alarm_types`
- **Dedup**: janela configurável por tipo de alarme (default 10min), mesmo IMEI+tipo+status='aguardando'
- **Mídia**: vinculada via `link_upload_to_occurrence()` (±3 min do evento) nos handlers de upload
- **Assíncrono**: roda pós `fastcgi_finish_request()`, não impacta latência do webhook HTTP 200

## Migração v4.0.0: 15 Tabelas Novas
- **Tabelas DMS**: `occurrences`, `occurrence_events`, `occurrence_configs`, `occurrence_config_params`
- **Tabelas de cadastro**: `branches`, `drivers`, `sim_cards`, `permission_groups`
- **Tabelas de relatório**: `trips` (preenchida pelo trip_builder)
- **Tabelas de infra**: `jobs` (fila assíncrona), `geocode_cache` (Nominatim), `impersonation_log`
- **Checklist**: `checklist_configs`, `checklist_items`, `checklist_responses`
- **Alterações**: `users` (+user_type, +permission_group_id, +photo_url), `customers` (+reseller_id, +brand_color, +logo_url, +occurrence_config_id, +faceid_enabled), `devices` (+sim_card_id, +peripherals JSON, +streaming_rotation, +streaming_watermark, +firmware_version, +branch_id), `media_files` (+channel, +download_status)

## Design: Coinbase em vez de Cursor
- **Decisão**: Adotar design system Coinbase (azul #0052ff, sidebar dark, canvas branco)
- **Por quê**: Paleta Cursor (laranja #f54e00, canvas creme) não transmite profissionalismo de plataforma de rastreamento
- **Estrutura YUV mantida**: a IA de produto (módulos, ocorrências, rotas) é do YUV; só o skin visual é Coinbase

## CSRF: Token por Sessão
- **Decisão**: `includes/csrf.php` com `csrf_generate()` (sessão) + `csrf_verify()` (POST) + `csrf_field()` (forms)
- **Por quê**: Sem framework, precisamos de proteção nativa PHP. Token armazenado em `$_SESSION['_csrf_token']`
- **Páginas protegidas**: clientes, usuarios, equipamentos, chips, motoristas, grupos-permissao, config-ocorrencias, ocorrencias_dashboard

## Workers: Cron Jobs
- **worker.php**: processa `jobs` (report, video_download, rollup) — pendente → processando → concluido/falhou
- **trip_builder.php**: segmenta `gps_data` em `trips` por ignição (lig→desl), distância haversine, cruza alarms
- **metrics_rollup.php**: stub para KPIs pré-computados do Resumo/BI (implementação futura)

## Vídeo histórico do cartão: 37381 lista, 34818 extrai (fix 13/07/2026)
- **Bug**: /video/playback disparava 34818 (0x8802, multimídia de EVENTO) e lia só `media_files` → "sempre vazio" mesmo com o cartão cheio (o app Android usa 0x9205)
- **Decisão**: LISTAR gravações = **37381/0x9205** → resposta assíncrona via /pushresourcelist → `resource_lists`; EXTRAIR trecho = **34818/0x8802** com a janela exata da gravação → /pushfileupload → `media_files`
- **Gotchas do 37381**: janela `beginTime/endTime` GMT-0 compacta (yyMMddHHmmss) **não pode cruzar o dia** — fatiar o período por dia UTC (a tela fatia com cap de 15 segmentos); campos `channel` (doc) + `channelId` (compat) + alarmFlag/resourceType/codeType/storageType=0 + instructionID
- **Push §1.11** (`{imei,totalNum,instructionID,resourceList[]}`): pode vir SEM envelope data_list → `allowSingleObjectPayload=true` no handler; `resourceType` segue o 0x1205: **0=áudio+vídeo** (mapear para `video` — 0=imagem é do 0x0800, outro push)
- **Timeline**: `resource_lists` ("No cartão") ∪ `media_files` ("Disponível") com merge por janela ±120s; botão Extrair por gravação; auto-refresh 6×8s sem reenviar o comando; serverFlagId por protocolo do device
