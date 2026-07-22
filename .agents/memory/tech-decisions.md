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

## Observabilidade: LOG_LEVEL, rotação real e handler global (13/07/2026)
- **LOG_LEVEL no .env**: aplicado LAZY no primeiro log (core/Logger.php) — o .env só é parseado dentro do 1º `Database::getInstance()`, DEPOIS do load do Logger; ler env no init() não funciona. DEBUG liga RAW_WEBHOOK_DATA (payload bruto de webhook)
- **Purga/rotação**: `scripts/log_cleanup.php` no cron diário 03:10 (crontab-setup.sh). Rotação por tamanho para logs de append contínuo (worker.log etc. — mtime sempre fresco, purge por idade nunca os pegaria) → `.old`; depois `cleanOldLogs()` por idade em `*.log` + `*.log.old`. Env: LOG_RETENTION_DAYS (30), LOG_MAX_SIZE_MB (10)
- **Decisão**: log_cleanup NÃO usa a classe Database (o construtor dá `exit` em falha de conexão — limpeza de log deve rodar com banco fora); parse próprio do .env
- **Handler global do dashboard** (auth.php): set_exception_handler → ERROR + 500 neutro; shutdown p/ fatais → CRITICAL; só páginas/AJAX (webhooks têm o try/catch do WebhookHandler); warnings/notices de fora
- **Gotcha de teste**: `php -r` NÃO dispara set_exception_handler neste build (código eval'd) — sempre testar handler com arquivo .php real

## Relatório de Deslocamento v4.3.0: modalidades, teto de período e mapa de rota (22/07/2026)
- **Teto global de 31 dias** em todo relatório com filtro de data: `clamp_report_range()` (includes/functions.php, const `REPORT_RANGE_MAX_DAYS`). Datas invertidas trocam; excesso encurta `date_to`. Telas com banner âmbar; AJAX/exportar clampam silenciosamente. **Relatório novo = aplicar o helper.**
- **Por quê 31 dias**: benchmark com 2,92M viagens (tenant 200 veículos): fechamento diário (GROUP BY) custa 41–177ms até 30 dias, mas 5,7s@90d e ~10s@365d — a agregação em si é o custo, não falta de índice.
- **Índice**: `trips` consultada por período EXIGE o composto `idx_trips_customer_time (customer_id, started_at)` (migration v4.3.0; o antigo `idx_trips_customer` foi dropado — o composto serve a FK). Sem ele, 3,5–6s por consulta.
- **Fechamento diário**: agrega `trips` por `imei + DATE(CONVERT_TZ(started_at,'+00:00','-03:00'))`; viagem que cruza meia-noite conta no dia em que começou; **Jornada** (MAX(ended)−MIN(started), inclui paradas) ≠ **Em Movimento** (SUM(duration_s)). Períodos parados com ACC ligado não entram (isRealTrip filtra no builder).
- **Mapa de rota** (`/relatorios/deslocamento/rota`): janela SEMPRE recalculada server-side (trip_id → started/ended; imei+dia → MIN/MAX das trips do dia BRT) — nunca aceitar datetimes crus da URL. Ocorrência com coordenada = posição do 1º alarme (`occurrence_events`→`alarms`); sem coordenada = anexa ao ponto GPS mais próximo no tempo. Amostragem >3000 pontos preservando primeiro/último; `preferCanvas` no Leaflet.
- **Router**: subrota de 3 segmentos = chave `'segundo/terceiro'` no `$subrouteMap` (ex.: `'deslocamento/rota'`), com precedência sobre a de 2.
