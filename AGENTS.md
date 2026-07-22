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
- **`device_models`** — Device model catalog (JC400D, JC450, etc. with protocol + camera_count)

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
| `FILE_STORAGE_URL` | `http://189.22.240.43:23010/download/` | Base URL for media files |
| `STREAM_URL` | `http://189.22.240.43:8881` | Base URL for HTTP-FLV live streams |
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

# No build step needed — pure PHP
```

---

## Estado-alvo v4.0.0 (YUV Parity) — referência rápida

> Detalhe completo em `PROJETO_YUV.md`. Aqui, o mapa para orientação dos agentes.

### Rotas-alvo (espelham a IA do YUV)

**Principal**: `/` Resumo (`resumo.php`) · `/rastreamento` (`rastreamento.php`) · `/bi` (`bi.php`) · `/ocorrencias/dashboard` (`ocorrencias_dashboard.php`) · `/comandos` (mantido) · `/exportar` (`exportar.php`)

**Vídeos**: `/video/aovivo` · `/video/playback` · `/video/downloads`

**Relatórios**: `/relatorios/posicoes` · `/relatorios/deslocamento` · `/relatorios/desatualizados` · `/relatorios/alarmes` · `/relatorios/ocorrencias`

**Cadastros**: `/ativos` · `/chips` · `/clientes` · `/equipamentos` · `/grupos-permissao` · `/motoristas` · `/config-ocorrencias` · `/usuarios`

**AJAX novos**: `/ocorrenciasdata` (polling do dashboard DMS) · `/exportardata` (polling da fila)

> `router.php` precisa generalizar o parse para subrotas de 2 segmentos (`video/*`, `relatorios/*`, `ocorrencias/*`).

### Tabelas novas (migração v4.0.0)

`branches`, `drivers`, `sim_cards`, `permission_groups`, `occurrence_configs`, `occurrence_config_params`, `occurrences`, `occurrence_events`, `trips`, `jobs`, `geocode_cache`, `impersonation_log`.

**Alterações**: `users`(+user_type,+permission_group_id,+photo_url) · `customers`(+reseller_id,+brand_color,+logo_url,+occurrence_config_id,+faceid_enabled) · `devices`(+sim_card_id,+peripherals,+streaming_rotation,+streaming_watermark,+firmware_version,+branch_id) · `media_files`(+channel,+download_status).

### Núcleo: motor de ocorrências

`includes/occurrence_engine.php` (a criar), chamado **dentro de `pushalarm.php`** após o INSERT do alarme: resolve o `occurrence_config` do cliente → aplica o parâmetro do tipo de alarme → cria ou agrupa a ocorrência (dedup por janela). Ver `PROJETO_YUV.md` §7.

### Workers (cron, a criar)

`scripts/worker.php` (fila `jobs`: relatórios/downloads) · `scripts/trip_builder.php` (viagens) · `scripts/metrics_rollup.php` (KPIs Resumo/BI).
