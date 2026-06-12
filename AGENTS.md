# AGENTS.md — Jimi Webhook System (v3.1.0)

## Project

PHP IoT gateway that receives GPS/heartbeat/alarm/event webhooks from Jimi IoT Hub (`jimicloud.com`), persists to MySQL, and serves a NavTrack-inspired editorial dashboard with multi-tenant support, device registration, live tracking, video playback, command dispatch, reports, and remote configuration.

Official API reference: `https://docs.jimicloud.com/integration/integration.html`

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
- **`includes/auth.php`** — Auth middleware: `require_login()`, `require_admin()`, `get_current_user()`, `get_current_customer()`, `login_user()`, `logout_user()`, `set_customer_context()`
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
Session-based auth using PHP native sessions + `sessions` table. Cookie: `jimi_session`. All dashboard pages must call `require_login()`. First-run: visit `/setup` to create admin user after migration.

### Design System (v3.0.0 — preserved)
- **Canvas**: `#f7f7f4` cream, **Primary**: `#f54e00` Cursor Orange
- **Typography**: Inter 400/500/600 + JetBrains Mono
- **No shadows** — hairlines only (1px borders)
- CSS is inline in `layout_base.php` (no build step)

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

# No build step needed — pure PHP
```
