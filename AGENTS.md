# AGENTS.md — Jimi Webhook System (v2.0.0)

## Project

PHP IoT gateway that receives GPS/heartbeat/alarm/event webhooks from Jimi IoT Hub (`jimicloud.com`), persists to MySQL, and serves a Bootstrap dashboard for device monitoring, media viewing, command dispatch, and remote configuration.

Official API reference: `https://docs.jimicloud.com/integration/integration.html`

## Architecture

```
Jimi IoT Hub  --POST-->  .htaccess rewrite  -->  handlers/*.php
                                                    │
  Each handler extends WebhookHandler (config/WebhookHandler.php)
  → token validation → async HTTP 200 → normalize keys → INSERT → stored proc → commit

Dashboard:  web/index.php  OR  /dashboard → handlers/dashboard.php + web/dashboard_template.php
  AJAX endpoints: /camerasdata, /commandstatus, /sendcommand, /mediadata, /trackdata, /hbdata
```

### Example Webhook Payload (POST form-urlencoded)

```
POST /pushgps
Content-Type: application/x-www-form-urlencoded

token=a12341234123&data_list=[{"deviceImei":"868120246598152","gpsTime":"2023-01-13 02:24:37","gateTime":"2023-01-13 02:24:38","satelliteNum":7,"lng":113.942885,"lat":22.576539,"gpsMode":0,"gpsSpeed":0,"direction":155,"acc":1,"postType":1,"altitude":0,"distance":698,"status":262147}]
```

```
POST /pushalarm (JIMI protocol, msgClass=0)
Content-Type: application/x-www-form-urlencoded

token=a12341234123&data_list=[{"gateTime":"2025-01-07 11:06:05","imei":"752533678900242","msg":{"alertType":212,"lng":113.943102,"alarmTime":"2024-12-31 03:26:34","gpsSpeed":0.0,"voltage":114.0,"satelliteNum":15,"file":"EVENT_864993060084267_00000000_2024_11_21_17_26_12_I_102.ts","deviceImei":"752533678900242","driverId":"04sl","driverName":"SL","alertValue":1,"lat":22.576649},"msgClass":0,"type":"DEVICE"}]
```

```
POST /pushalarm (JTT protocol, msgClass=1)
Content-Type: application/x-www-form-urlencoded

token=a12341234123&data_list=[{"gateTime":"2025-01-07 11:06:05","imei":"869247060081665","msg":{"alertType":"256","standardAlarmValue":2048,"deviceImei":"869247060081665","lng":113.943261,"alarmTime":"2025-01-07 11:06:01","gpsSpeed":0.0,"alarmSerialNo":25075,"lat":22.576697},"msgClass":1,"type":"DEVICE"}]
```

## Key navigation

- **`config/database.php`** — PDO singleton, reads `.env` line-by-line, falls back to hardcoded defaults
- **`config/WebhookHandler.php`** — abstract base: token check, idempotency (MD5 hash, 10-min window), async via `fastcgi_finish_request()`, transaction mgmt
- **`core/Logger.php`** — unified static logger (v2.0.0), daily rotation, JSON context, auto-cleanup >30 days. Writes to `logs/webhook_YYYY-MM-DD.log`
- **`handlers/`** — all HTTP endpoints (webhook receivers + dashboard AJAX)
- **`includes/functions.php`** — `normalize_data()` (camelCase→snake_case), `get_webhook_data()`, `sanitize_date()`, `detect_media_type()`, coordinate validation
- **`web/`** — client-side assets for the standalone dashboard (`web/index.php`)
- **`mysql/jimi_tracker.sql`** — full production schema dump with stored procedures and seed data
- **`mysql/migration_v2.0.0.sql`** — migration script for v2.0.0 (new columns + `command_responses` table)
- **`logs/`** — runtime logs
- **`.agents/`** — AG Kit AI dev toolkit, **not part of the runtime application**

## Webhook Endpoint Coverage

| Endpoint | Handler | Status |
|---|---|---|
| `/pushevent` | `pushevent.php` | Aligned — Section 1.1 |
| `/pushhb` | `pushhb.php` | Aligned — Section 1.2 (all 12 fields) |
| `/pushgps` | `pushgps.php` | Aligned — Section 1.3 (all 28 fields) |
| `/pushalarm` | `pushalarm.php` | Aligned — Section 1.4 (JIMI + JTT) |
| `/pushfileupload` | `pushfileupload.php` | Aligned — Section 1.8 |
| `/pushlbs` | `pushlbs.php` | Aligned — Section 1.10 (lbsJson + cellList) |
| `/pushresourcelist` | `pushresourcelist.php` | Aligned — Section 1.11 |
| `/pushftpfileupload` | `pushftpfileupload.php` | Aligned — Section 1.12 |
| `/pushiothubevent` | `pushiothubevent.php` | Aligned — Section 1.13 |
| `/pushTerminalTransInfo` | `pushTerminalTransInfo.php` | Aligned — Section 1.15 |
| `/pushinstructresponse` | `pushinstructresponse.php` | Aligned — Section 1.16 |

## Dashboard (5 tabs)

| Tab | Description | Key Features |
|---|---|---|
| **Câmeras** | Device telemetry + live streaming | HTTP-FLV player (flv.js), real-time AJAX refresh, GPS map links |
| **Alarmes** | Alarm listing with actions | Map links, media file links, VIDEOUPLOAD button (JTT), severity borders |
| **Comandos** | Command dispatch | 16 JIMI presets + 17 JTT presets, command history, detail modal with JSON |
| **Mídia** | Media file gallery | Card grid, type icons, download/playback, IMEI filter |
| **Configuração** | Device configuration | Query/set parameters (proNo 33027-33031), terminal info, reset |

### Dashboard AJAX Endpoints

| Endpoint | Handler | Purpose |
|---|---|---|
| `/camerasdata` | `camerasdata.php` | Device list + API status |
| `/commandstatus` | `commandstatus.php` | Command history + offline response count |
| `/sendcommand` | `sendcommand.php` | Send commands to IoT Hub (proNos 128-37382) |
| `/mediadata` | `mediadata.php` | Media files + resource lists (IMEI filter, pagination) |
| `/trackdata` | `trackdata.php` | GPS tracks by IMEI + date range |
| `/hbdata` | `hbdata.php` | Heartbeats by IMEI(s) |

## Environment Variables

| Variable | Default | Description |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | — | MySQL connection |
| `WEBHOOK_TOKEN` | `a12341234123` | Token for webhook and dashboard auth |
| `SYSTEM_VERSION` | `2.0.0` | System version (auto-populated) |
| `FILE_STORAGE_URL` | `http://189.22.240.43:23010/download/` | Base URL for media file downloads |
| `STREAM_URL` | `http://189.22.240.43:8881` | Base URL for HTTP-FLV live/playback streams |

## Gotchas

### Antes de qualquer ação, verifique as skills do `.agents/`
O AG Kit define 45 skills e 20 agentes especialistas. **Sempre** verifique se alguma skill se relaciona à tarefa antes de executá-la. Ex: `documentation-templates` para documentação, `clean-code` para refatoração, `lint-and-validate` para sintaxe, `database-design` para schema.

### Case-sensitive include mismatch (CORRIGIDO em v2.0.0)
`web/index.php` agora é um wrapper que carrega `handlers/dashboard.php`. O require case-sensitive `DashboardData.php` → `dashboarddata.php` foi removido.

### Two dashboard entry points
- `web/index.php` — wrapper que carrega `handlers/dashboard.php` (rota canônica: `/dashboard`)
- `/dashboard` → `handlers/dashboard.php` + `web/dashboard_template.php` (controller/view pattern)

Ambos usam o mesmo código. O template (`dashboard_template.php`) é a fonte canônica do HTML/JS. O `web/assets/js/dashboard.js` mantém os presets sincronizados para referência.

### Timezone handling is error-prone
Multiple comments mark `ROOT OF BUG` around UTC/BRT (GMT-3) conversions. All DB times are UTC; dashboard converts to BRT for display. Double-conversion is a known hazard.

### Async processing via fastcgi_finish_request()
Webhook handlers return HTTP 200 immediately, then continue processing. This requires PHP-FPM (not mod_php). Logging or debugging after the early response is invisible to the client.

### Hardcoded fallback credentials
`config/database.php` and handler files contain hardcoded DB password and webhook token as fallbacks. Treat as non-sensitive dev defaults; production relies on `.env`.

### No build step, no package manager
Pure PHP — no `composer.json`, no `package.json`, no framework. CDN-loaded Bootstrap 5.3 + Bootstrap Icons + flv.js for the frontend.

### requestMeta for extra POST fields
`WebhookHandler` stores extra POST fields (like `msgType` for `/pushinstructresponse`) in `$this->requestMeta`. Handlers needing non-standard POST params should read from this property.

## Commands

```bash
# No build/lint/test commands exist. The application is deployed as-is to an Apache + PHP-FPM host.

# Database setup (fresh install):
mysql -u root -p < mysql/jimi_tracker.sql

# Database migration (v3.0.1 → v2.0.0):
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql

# The AG Kit has its own Python scripts (not required for the PHP app):
python .agents/scripts/verify_all.py
python .agents/scripts/checklist.py
```

## Database

MySQL database `jimi_tracker` with 17 tables. Stored procedures (`update_device_stats_after_*`) are called by handlers — the schema dump includes them. `alarm_types` table provides the alarm code→name lookup (JIMI and JTT protocols). The stored function `decode_standard_alarm_bits()` handles JT/T 808 32-bit alarm bitmask decoding.

`command_responses` table was added in v2.0.0 (created by migration script if not present).
