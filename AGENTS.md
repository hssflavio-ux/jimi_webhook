# AGENTS.md — Jimi Webhook System (v4.0.0 — YUV Parity)

## Session Start Protocol (OBRIGATÓRIO)

> **ANTES de qualquer outro trabalho**, leia o sistema de memória persistente e o status do projeto:

```
1. Leia .agents/memory/MEMORY.md            ← preferências do usuário, contexto do projeto
2. Leia STATUS.md                           ← estado atual, bugs, pendências
3. Aplique o contexto silenciosamente       ← não recite memórias
4. Só então prossiga com a tarefa do usuário
```

Se `.agents/memory/MEMORY.md` não existir, crie-o na primeira oportunidade.
Ao final da sessão, se novas decisões ou feedback surgirem, atualize os arquivos de memória.

## Project

PHP IoT gateway that receives GPS/heartbeat/alarm/event webhooks from Jimi IoT Hub (`jimicloud.com`), persists to MySQL, and serves a multi-tenant dashboard for live tracking, MDVR video, command dispatch, reports, and remote configuration.

**Direção v4.0.0 — "YUV Parity":** o produto está sendo reconstruído como **cópia fiel do YUV (`app.yuv.com.br`)**, uma plataforma de rastreamento com **telemetria de vídeo / gestão de ocorrências DMS** (alarme de câmera → ocorrência → tratativa → risco, com regras por cliente). O gateway de webhooks é preservado; dashboard e design são reconstruídos.

- **`PROJETO_YUV.md`** — blueprint-mestre (rotas-alvo, modelo de dados v4.0.0, specs das 22 telas, motor de ocorrências, roadmap). **Leia antes de implementar módulos novos.**
- **`analise_yuv/analise_yuv.html`** — fonte visual de verdade (screenshots + regras das telas YUV).
- **`DESIGN.md`** — design system Coinbase (azul `#0052ff`, sidebar dark `#0a0b0d`, CTAs pill, mono nos números), derivado de `DESIGN-coinbase.md`. Substitui a paleta Cursor das versões ≤3.x. Já aplicado no CSS do dashboard.

Official API reference: `https://docs.jimicloud.com/integration/integration.html`

**STATUS.md** — Status detalhado do desenvolvimento, bugs corrigidos, pendências e roadmap YUV. Leia antes de continuar o desenvolvimento.

> **Nota**: as tabelas de rotas/DB abaixo descrevem o estado v3.x **atual** (implementado). O estado-**alvo** v4.0.0 (rotas do YUV, novas tabelas `occurrences`, `occurrence_configs`, `drivers`, `sim_cards`, `trips`, `jobs` etc.) está em `PROJETO_YUV.md` §4 e §6.

## Architecture (v3.1.0)

```
Jimi IoT Hub  --POST-->  .htaccess  -->  handlers/router.php  -->  handlers/*.php
                                                    │
  Router parses URL segments, dispatches to PHP handlers.
  All non-file requests go through the front controller.

  Webhook handlers extend WebhookHandler (config/WebhookHandler.php)
  → token validation → async HTTP 200 → normalize keys → INSERT → stored proc → commit

  Dashboard pages use Layout Base (web/layout_base.php) — NavTrack two-column
  with left sidebar + customer dropdown + main content area.

  Authentication: session-based (PHP sessions + 'sessions' table).
  Login at /login. First-run setup at /setup.
```

## Routes (v3.1.0)

| Route | Handler | Auth | Description |
|---|---|---|---|
| `/login` | `login.php` | Public | Login page |
| `/logout` | `logout.php` | Public | Logout + destroy session |
| `/setup` | `setup.php` | Public | First admin creation (only when no users exist) |
| `/dashboard` | `dashboard.php` | Login | Main dashboard with KPI cards + activity |
| `/ativos` | `ativos.php` | Login | Device list for current customer |
| `/ativos/novo` | `ativos_novo.php` | Login | Register new device (model dropdown) |
| `/ativos/{imei}` | `ativo_detalhe.php` | Login | Asset detail (9 sub-tabs with sidebar) |
| `/live` | `live.php` | Login | Multi-asset live tracking map (Leaflet) |
| `/relatorios` | `relatorios.php` | Login | Reports: Alarms, Trips, Commands (date filter) |
| `/video` | `video.php` | Login | Unified player: FLV live + HLS/MP4 recordings |
| `/comandos` | `comandos.php` | Login | Command dispatch: model-sensitive, polling ativo |
| `/config` | `config.php` | Login | Device configuration (query/set proNo 33027-33031) |
| `/clientes` | `clientes.php` | Admin | Customer management (multi-tenant) |
| `/customer_switch` | `customer_switch.php` | Login | AJAX: switch customer context |

### AJAX Endpoints

| Endpoint | Handler | Purpose |
|---|---|---|
| `/camerasdata` | `camerasdata.php` | Device list + API status |
| `/commandstatus` | `commandstatus.php` | Command history + `?command_id=X` single-command polling |
| `/sendcommand` | `sendcommand.php` | Send commands (JSON body accepted, proNos 128-34818) |
| `/mediadata` | `mediadata.php` | Media files + resource lists |
| `/trackdata` | `trackdata.php` | GPS tracks by IMEI + date range |
| `/hbdata` | `hbdata.php` | Heartbeats by IMEI(s) |
| `/devicemodels` | `devicemodels.php` | List device models for dropdowns |

### Webhook Endpoints (unchanged)

| Endpoint | Handler |
|---|---|
| `/pushevent`, `/pushhb`, `/pushgps`, `/pushalarm`, `/pushfileupload`, `/pushlbs`, `/pushresourcelist`, `/pushftpfileupload`, `/pushiothubevent`, `/pushTerminalTransInfo`, `/pushinstructresponse`, `/pushcmd` | Existing handlers — routed through `router.php` |
| `/filelist/{imei}` | `filelist.php` — **não é do IoT Hub**: quem faz o POST é a CÂMERA JIMI, em HTTP simples e sem token (ela não tem como carregar sessão). Recebe a lista de gravações do cartão, grava o corpo cru em `logs/filelist/` e a interpreta para `resource_lists` via `includes/filelist.php`. A defesa é o IMEI ter de existir em `devices`. 🔴 O carimbo dos nomes é hora LOCAL da câmera (UTC−3), não GMT 0 — ver Gotchas do CLAUDE.md |

## Key navigation

- **`handlers/router.php`** — Front controller: parses URL segments and dispatches to handlers
- **`includes/auth.php`** — Auth middleware: `require_login()`, `require_admin()`, `get_jimi_user()`, `get_customer_id()`, `get_customer()`, `login_user()`, `logout_user()`, `set_customer_context()`
- **`web/layout_base.php`** — Main layout shell (sidebar + header + content). Includes design system CSS inline
- **`web/layout_ativo_sidebar.php`** — Secondary sidebar for asset detail (9 tabs)
- **`web/layout_base_close.php`** — Closes layout tags
- **`web/login_template.php`** — Login page template
- **`config/database.php`** — PDO singleton, reads `.env`
- **`config/WebhookHandler.php`** — Abstract webhook handler base class
- **`core/Logger.php`** — Static logger
- **`includes/functions.php`** — `normalize_data()`, `get_webhook_data()`, etc.
- **`mysql/jimi_tracker.sql`** — Full production schema
- **`mysql/migration_v2.0.0.sql`** — v2.0.0 migration
- **`mysql/migration_v3.1.0.sql`** — v3.1.0 migration (multi-tenant, users, sessions, device_models)

## Database (v3.1.0 — 22 tables)

New tables in v3.1.0:
- **`customers`** — Multi-tenant customers (clients)
- **`users`** — System users (email/password_hash/role)
- **`customer_users`** — Customer↔User pivot with role
- **`sessions`** — Login sessions (PHP session ID ↔ user_id ↔ customer_id)
- **`device_models`** — Device model catalog (JC400D, JC450, etc. with protocol + camera_count + **`family`** desde a v4.16.0: `camera` | `tracker`)

Altered tables:
- **`devices`** — Added `customer_id`, `device_model_id`, `camera_count`, `created_by`

## Dashboard (NavTrack-inspired, v3.1.0)

**No more Bootstrap tabs.** Navigation is URL-routed with a persistent sidebar:

```
Sidebar (left):
  ├─ Brand (JIMI logo + version)
  ├─ Customer dropdown selector
  ├─ Painel (/dashboard)
  ├─ Ao Vivo (/live)
  ├─ Ativos (/ativos)
  ├─ Relatórios (/relatorios)
  ├─ Vídeo (/video)
  ├─ Comandos (/comandos)
  ├─ Configuração (/config)
  └─ Clientes (/clientes — admin only)

Asset Detail (secondary sidebar, 9 tabs):
  ├─ Visão Geral, Ao Vivo, Trajetos
  ├─ Alertas, Log, Relatórios
  ├─ Vídeo, Comandos, Configurações
```

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | — | MySQL connection |
| `WEBHOOK_TOKEN` | `a12341234123` | Token for webhook and dashboard auth |
| `SYSTEM_VERSION` | `3.1.0` | System version |
| `FILE_STORAGE_URL` | `http://localhost:23010/download/` | Base URL for media files — prod: `http://186.248.143.197:23010/download/` |
| `STREAM_URL` | `http://localhost:8881` | Base URL for HTTP-FLV live streams — prod: `https://bycamera.ia.br/stream` (proxy TLS), homolog: `http://189.22.240.43:8881` |
| `IOTHUB_COMMAND_URL` | `http://localhost:10088/api/device/sendInstruct` | IoTHub command endpoint |
| `IOTHUB_API_TOKEN` | `123` | IoTHub internal API token |

## Gotchas

### .htaccess front controller (v3.1.0)
All non-file requests go through `router.php`. This replaces the old single-segment rewrite. Multi-segment URLs like `/ativos/868120246598152` or `/clientes/1` are now supported.

### Authentication (v3.1.0)
Token-based auth using cookie `jimi_token` (64-char hex) + `sessions` table in MySQL. No dependency on `session_start()` or PHP session files. All dashboard pages must call `require_login()`. First-run: visit `/setup` to create admin user after migration.

### Design System (v4.0.0 — Coinbase)
- **Voltagem**: azul `#0052ff` (Coinbase Blue) para CTAs/links/foco/item ativo — **escasso**.
- **Sidebar**: dark near-black `#0a0b0d` com item ativo azul; **canvas** branco `#ffffff`.
- **Geometria**: CTAs **pill (100px)**; cards 16px (grandes 24px); ícones/avatares `full`.
- **Profundidade**: um único nível de sombra (`0 4px 12px rgba(0,0,0,.04)`), só em hover.
- **Typography**: Inter 400/500/600/700 (display em **peso 400**) + JetBrains Mono em **todo número/IMEI**.
- CSS inline em `layout_base.php`, `login_template.php`, `setup.php` — **já migrado**. Navegação com grupos-sanfona é alvo da Fase 0. Ver `DESIGN.md` / `DESIGN-coinbase.md`.
- _(≤3.x usavam a paleta Cursor creme/laranja; a paleta roxa YUV foi proposta e descartada em favor da Coinbase.)_

### Rastreadores JM-VL — nem todo equipamento é câmera (v4.16.0)
`JM-VL01` / `JM-VL02` são RASTREADORES: mesmo protocolo JIMI (`msgClass=0`), mesmos webhooks, mesmos comandos proNo 128, **`camera_count = 0`** — sem vídeo, sem canal, sem DMS/ADAS. `device_models.family` (`camera`/`tracker`) separa os dois mundos.
- O prefixo é **JM**, não JC (JC = linha de câmeras). `model_name` é UNIQUE e vira chave de `command_catalog.modelos`, `firmware_releases` e da trava por modelo — renomear depois quebra o casamento em silêncio.
- 🔴 **`universal` no catálogo de comandos passou a valer por FAMÍLIA**, derivada de `modelos`. Antes queria dizer "libera a frota inteira" — com um rastreador na lista isso oferecia `RECORDSW`/`VOLUME`/`SSID`/`WIFIAP` a um aparelho sem vídeo.
- **Entrada nova no catálogo só onde a aridade/formato muda**; onde a sintaxe é idêntica, o modelo entra no `modelos` da entrada existente. `SPEED` tem três formatos reais (JC, VL01, VL02) e no da VL01 o 2º campo é o TEMPO, não a forma de aviso.
- 🔴 **Placeholder só vale como `P1..Pn` ou letra única maiúscula** (`montarComando()`); `SW`/`T1`/`ΔV1` da wiki ficariam crus no comando, e token fixo de uma letra é indistinguível de placeholder.
- ⚠️ `params` com contagem != placeholders desabilita o Enviar para sempre (`faltaParametro()`). E em JS, `parseInt(x) || 1` transforma `camera_count = 0` em 1.
- Telas que EXCLUEM rastreador: `/video/aovivo`, `/video/playback`, `/configuracoes-ia`, e as abas Ao Vivo/Vídeo de `/ativos/{id}`.

### Command Polling (v3.1.0)
After sending a command, the frontend polls `/commandstatus?command_id=X`:
- Fast phase: every 3s for 30s
- Slow phase: every 10s for 5 minutes
- Timeout: "Comando em fila offline"

### sendcommand.php (v3.1.0)
Now accepts both JSON (`Content-Type: application/json`) and form-urlencoded POST. `content` field aliases `cmdContent`. Extended proNo whitelist includes 33027-34818 for config commands.

### Async processing via fastcgi_finish_request()
Webhook handlers return HTTP 200 immediately, then continue processing. Requires PHP-FPM.

### Timezone handling
All DB times are UTC. Dashboard converts to BRT (America/Sao_Paulo) for display.

### XLSX formula cells need a cached `<v>` (v4.9.0)
`XlsxWriter` writes the map link as `=HYPERLINK(url;"MAPA")`. A formula without a **cached value** only renders after the app recalculates the sheet — viewers that don't recalculate (Google Sheets preview, Numbers, Windows preview) show the cell **empty**. "The link shows in the PDF but not in Excel" is the signature of this bug. Always emit `<f>…</f><v>label</v>`.

### Alarm names are resolved once, at ingestion (v4.9.0)
`pushalarm.php` looks the code up in `alarm_types` when the webhook arrives and stores the result in `alarms.alarm_name`. A code missing from the catalogue at that moment is stored as `Código NNNN (JTT)` **forever**, even after the code is catalogued later. `rel_alarmes.php` therefore re-resolves at read time — but replaces **only** the generic label: the stored name wins whenever it is a real name, otherwise the `Fim de Alarme: ` prefix (alarm END event) and the decoded JT/T 256 bitmask would be wiped out by the catalogue name. New codes go in via migration (`migration_v4.9.0.sql`, `migration_v4.9.10.sql`). The rule is **never name a code by guessing** — not "only what the doc publishes": `1047` is absent from the doc and was catalogued as `Capotamento` in v4.9.10 from vendor information, corroborated by bit 28 of the JT/T standard bitmask (rollover warning) and by the ADAS unit ordering in the 1042–1046 range.
  - ⚠️ **Before naming a new code, look for the SAME event under the other protocol.** Rollover already existed as JIMI `45` labelled `Veículo Tumbado`; adding `1047` as `Capotamento` and stopping there would have split one event across two labels, and **the screen filter matches by name** — the user picks one and loses half the events. Same reason v4.9.0 repeated names on purpose for the 1024/1042 pairs.
  - ⚠️ **A catalogued alarm with no row in `occurrence_config_params` generates no occurrence at all** — `process_alarm_occurrence()` returns early when `get_occurrence_param()` yields NULL. And the **category** decides notification: `Colisão do Veículo` sits in `veiculo`, which has no rule, so it **never notifies** (recorded in v4.9.10, deliberately not changed — it alters volume).

## Commands

```bash
# Database setup (fresh install):
mysql -u root -p < mysql/jimi_tracker.sql
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.0.0.sql   # YUV Parity
mysql -u root -p jimi_tracker < mysql/migration_v4.1.0.sql   # jobs.format + fix seed DMS
mysql -u root -p jimi_tracker < mysql/migration_v4.2.1.sql   # catálogo de câmeras por modelo
mysql -u root -p jimi_tracker < mysql/migration_v4.3.0.sql   # índice composto trips (customer_id, started_at)
mysql -u root -p jimi_tracker < mysql/migration_v4.4.0.sql   # motor de notificações (regras + sino + fila de e-mail)
mysql -u root -p jimi_tracker < mysql/migration_v4.4.1.sql   # credenciais SMTP cadastráveis (senha cifrada)
mysql -u root -p jimi_tracker < mysql/migration_v4.5.0.sql   # geocercas e POIs (cerca + vínculo + estado + eventos)
mysql -u root -p jimi_tracker < mysql/migration_v4.6.0.sql   # relatórios operacionais (segmentos de estado + excesso de velocidade)
mysql -u root -p jimi_tracker < mysql/migration_v4.7.0.sql   # relatórios agendados por e-mail + modelos de filtro
mysql -u root -p jimi_tracker < mysql/migration_v4.8.0.sql   # motorista na posição (gps_data)
mysql -u root -p jimi_tracker < mysql/migration_v4.8.1.sql   # alarm_types só com os alarmes da doc oficial
mysql -u root -p jimi_tracker < mysql/migration_v4.8.3.sql   # nomes DMS/ADAS conforme a doc + backfill de alarms
mysql -u root -p jimi_tracker < mysql/migration_v4.8.4.sql   # decisão sobre os 4 códigos JIMI ambíguos (só cinto fica)
mysql -u root -p jimi_tracker < mysql/migration_v4.8.5.sql   # Ajuda (wiki) liberada a grupo restrito
mysql -u root -p jimi_tracker < mysql/migration_v4.8.6.sql   # religa o motor de ocorrências (nomes da v4.8.3)
mysql -u root -p jimi_tracker < mysql/migration_v4.8.7.sql   # decisões de produto no motor (3 ligados, 4 removidos, cartão DLT)
mysql -u root -p jimi_tracker < mysql/migration_v4.8.9.sql   # telas config-notificacoes e config-smtp na matriz de permissão
mysql -u root -p jimi_tracker < mysql/migration_v4.9.0.sql   # alarmes "Other Alarms" da doc JT/T que faltavam no catálogo
mysql -u root -p jimi_tracker < mysql/migration_v4.9.4.sql   # remetente de e-mail: nome antigo do produto → bycamera
mysql -u root -p jimi_tracker < mysql/migration_v4.9.5.sql   # categoria unificada em pt-BR + remap de notification_rules

# No build step needed — pure PHP
```

---

## Estado-alvo v4.0.0 (YUV Parity) — referência rápida

> Detalhe completo em `PROJETO_YUV.md`. Aqui, o mapa para orientação dos agentes.

### Rotas-alvo (espelham a IA do YUV)

**Principal**: `/` Resumo (`resumo.php`) · `/rastreamento` (`rastreamento.php`) · `/bi` (`bi.php`) · `/ocorrencias/dashboard` (`ocorrencias_dashboard.php`) · `/comandos` (mantido) · `/exportar` (`exportar.php`)

**Só admin**: `/parametros` (v4.9.16) · `/firmwares` (`firmwares.php`, v4.9.32 — versão lida do `VERSION#` por equipamento + URLs de atualização **por modelo**, que é o que o `UPDATE,<url>#` precisa)

**Vídeos**: `/video/aovivo` · `/video/playback` · `/video/downloads`

**Relatórios**: `/relatorios/posicoes` · `/relatorios/deslocamento` · `/relatorios/desatualizados` · `/relatorios/alarmes` · `/relatorios/ocorrencias` · `/relatorios/geocercas` (v4.5.0) · `/relatorios/status-frota` · `/relatorios/paradas` · `/relatorios/ociosidade` · `/relatorios/ignicao` · `/relatorios/velocidade` (v4.6.0) · `/agendamentos` (v4.7.0)

**Cadastros**: `/ativos` · `/chips` · `/clientes` · `/equipamentos` · `/geocercas` (v4.5.0) · `/grupos-permissao` · `/motoristas` · `/config-ocorrencias` · `/config-notificacoes` · `/config-smtp` (v4.4.x) · `/usuarios`

**AJAX novos**: `/ocorrenciasdata` (polling do dashboard DMS) · `/exportardata` (polling da fila)

**`/download` (v4.7.3)** — `download.php`, único caminho para baixar relatório gerado. **Não exige login de propósito**: a autorização é a assinatura HMAC com prazo na URL (`?j=&exp=&sig=`, ver `includes/download_token.php`), porque é este link que viaja no e-mail do relatório grande. O acesso direto a `storage/reports/` passou a ser **negado** (`storage/reports/.htaccess`) — sem isso a assinatura não protegeria nada, já que o link antigo continuaria valendo para sempre.

> `router.php` precisa generalizar o parse para subrotas de 2 segmentos (`video/*`, `relatorios/*`, `ocorrencias/*`).

### Tabelas novas (migração v4.0.0)

`branches`, `drivers`, `sim_cards`, `permission_groups`, `occurrence_configs`, `occurrence_config_params`, `occurrences`, `occurrence_events`, `trips`, `jobs`, `geocode_cache`, `impersonation_log`.

**Alterações**: `users`(+user_type,+permission_group_id,+photo_url) · `customers`(+reseller_id,+brand_color,+logo_url,+occurrence_config_id,+faceid_enabled) · `devices`(+sim_card_id,+peripherals,+streaming_rotation,+streaming_watermark,+firmware_version,+branch_id; +firmware_checked_at,+firmware_source na v4.9.32) · `media_files`(+channel,+download_status).

### Tabelas novas (v4.4.0 → v4.7.0)

`notification_rules`, `notifications` (v4.4.0) · `smtp_settings` (v4.4.1) · `geofences`, `geofence_devices`, `geofence_state`, `geofence_events` (v4.5.0) · `device_state_segments`, `speeding_events` (v4.6.0) · `report_schedules`, `report_schedule_runs`, `report_templates` (v4.7.0).

**Alterações**: `jobs`(+attempts, +schedule_run_id, `type` com `notification`) · `devices`(+speed_limit_kmh) · `customers`(+default_speed_limit_kmh).

> **Fuso nos agendamentos (v4.7.0)**: `report_schedules.send_hour` é hora **BRT**; `next_run_at` é **UTC**. Converter só por `DateTimeZone` (`includes/schedule.php`), nunca somando 3 h — erra para datas anteriores a 2019 (quando havia horário de verão) e voltaria a errar se a política mudar. A tela e o cron chamam as MESMAS funções, para que o "próximo envio" exibido não divirja do que acontece.

> `device_state_segments` sustenta 4 das 5 telas da v4.6.0 (paradas, ociosidade, ignição, status da frota). A invariante que a torna auditável: os segmentos de um equipamento são **contíguos e sem sobreposição** (`ended_at` de um = `started_at` do seguinte), de onde a soma das durações de um dia fecha em 86.400 s. Quem altera `scripts/state_builder.php` tem de preservar isso — é o único teste que pega furo de segmentação.

### Núcleo: motor de ocorrências

`includes/occurrence_engine.php` (a criar), chamado **dentro de `pushalarm.php`** após o INSERT do alarme: resolve o `occurrence_config` do cliente → aplica o parâmetro do tipo de alarme → cria ou agrupa a ocorrência (dedup por janela). Ver `PROJETO_YUV.md` §7.

### Workers (cron)

`scripts/worker.php` a cada 1 min (fila `jobs`: relatórios/downloads/e-mail) · `scripts/trip_builder.php` a cada 15 min (viagens) · `scripts/metrics_rollup.php` a cada 5 min (KPIs Resumo/BI) · `scripts/log_cleanup.php` diário · `scripts/geofence_worker.php` a cada 2 min (travessias de cerca) · `scripts/state_builder.php` a cada 15 min (segmentos de estado + excesso de velocidade) · `scripts/schedule_dispatcher.php` na hora cheia (enfileira relatórios agendados).

O array `CRON_JOBS` de `scripts/crontab-setup.sh` é a fonte única — atualizar lá, nunca o crontab à mão. **`deploy.sh` NÃO instala cron**: worker novo exige `bash scripts/crontab-setup.sh --install`, e a falha é silenciosa (a tela funciona, o relatório fica vazio para sempre).

`scripts/trip_builder.php` e `scripts/state_builder.php` compartilham os limiares de `includes/fleet_state.php` (`STOP_SPEED_KMH`, `STOP_IDLE_SECONDS`). Não redeclarar localmente: "parado" tem de significar o mesmo nos dois, ou os relatórios se contradizem.
