# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

PHP IoT gateway (v3.1.0) that receives GPS/heartbeat/alarm/event webhooks from the Jimi IoT Hub (`jimicloud.com`), persists them to MySQL, and serves a multi-tenant editorial dashboard (NavTrack-inspired) for live tracking, video playback, command dispatch, reports, and remote device configuration. Pure PHP — **no build step, no package manager, no test framework.**

Official API reference: https://docs.jimicloud.com/integration/integration.html

**Read `STATUS.md` before continuing development** — it tracks current bugs, fixed issues, and pending work. `AGENTS.md` holds the same architectural detail as this file with the full route/table tables.

## Commands

```bash
# Fresh database install
mysql -u root -p < mysql/jimi_tracker.sql
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql

# Lint a single PHP file (the only "test" available)
php -l handlers/pushgps.php

# Lint everything (mirrors deploy.sh FASE 4 VERIFY)
find handlers config core includes -name "*.php" -type f -exec php -l {} \;

# Deploy (backup → git pull → migrate → chmod → php -l → /ping smoke test)
./scripts/deploy.sh                 # normal
./scripts/deploy.sh --force         # redeploy with no code changes
./scripts/deploy.sh --skip-migrate  # skip DB migration
./scripts/rollback.sh <TIMESTAMP>   # restore a backup from /var/backups/jimi_webhook

# Health check
curl http://localhost/ping

# Tail logs (rotated daily)
tail -f logs/webhook_$(date +%Y-%m-%d).log
```

There is no automated test suite. Verify changes by linting with `php -l` and replaying official-doc payloads with `curl` against the webhook endpoints.

## Architecture

```
Jimi IoT Hub --POST--> .htaccess --> handlers/router.php --> handlers/*.php
```

**Front controller**: `.htaccess` rewrites every non-file request to `handlers/router.php`, which parses URL segments and `require`s the matching `handlers/*.php`. Path params (e.g. `/ativos/{imei}`, `/clientes/{id}`) are injected into `$_GET` before dispatch. Adding a route = add the segment to the relevant array in `router.php` AND create the handler file.

**Two kinds of handlers share the `handlers/` directory:**

1. **Webhook receivers** (`push*.php`) — each instantiates a subclass of `WebhookHandler` (`config/WebhookHandler.php`) and calls `handle()`. The base class enforces a fixed pipeline: validate token → log raw payload → send HTTP 200 early via `fastcgi_finish_request()` → **then** process in background → idempotency check → `beginTransaction` → `normalize_data()` + `processItem()` per item → `commit` → write metrics. Subclasses only implement `processItem()` (and optionally `validateData()`). Real work happens *after* the client already got its 200, so errors there are logged, never returned.

2. **Dashboard pages + AJAX endpoints** — render via the layout shell in `web/` and call `require_login()` / `require_admin()` from `includes/auth.php`.

## Critical conventions & gotchas

- **Async requires PHP-FPM.** `fastcgi_finish_request()` is what lets webhooks return 200 instantly and process in background. Without FPM the response blocks until processing finishes.

- **Idempotency / anti-replay.** Each webhook payload is hashed (MD5 of `data_list`); a hash seen within 10 minutes (checked against `request_logs`) is dropped. Re-sending the same payload to test will be silently rejected during that window.

- **Authentication is cookie-token based, NOT PHP session files.** `includes/auth.php` reads a 64-char hex `jimi_token` cookie and looks it up in the `sessions` table (joined to `user_id` + `customer_id`). Despite the cookie mechanism, helpers populate `$_SESSION` for the rest of the request. Every dashboard page must call `require_login()`; admin-only pages call `require_admin()`. First run: visit `/setup` to create the admin (only works while the `users` table is empty).

- **Multi-tenant context.** Most data is scoped by `customer_id`, resolved from the session via `get_customer_id()`. The customer dropdown / `/customer_switch` changes `set_customer_context()`. New device/data queries must filter by customer.

- **JIMI vs JT/T 808 protocol isolation is strict** (see `docs/adr/ADR-001.md`). `msgClass=0` is JIMI, `msgClass=1` is JT/T 808 — never mix them. Command presets and config flows are protocol-sensitive.

- **Timezone**: all DB timestamps are UTC (connection forces `time_zone = '+00:00'`); the dashboard converts to BRT (America/Sao_Paulo) at display time only.

- **`.env` loading is manual.** `config/database.php` parses `.env` line-by-line into `putenv()` (no dotenv library). Read config with `getenv()`. The PDO singleton (`Database::getInstance()`) is the only DB connection.

- **`sendcommand.php`** accepts both JSON (`Content-Type: application/json`) and form-urlencoded POST; `content` aliases `cmdContent`. proNo whitelist spans 128–34818 (config commands use 33027–34818).

- **Command polling**: after dispatch the frontend polls `/commandstatus?command_id=X` — fast (every 3s for 30s) then slow (every 10s for 5min), then times out as "Comando em fila offline".

- **CSS has no build step.** The whole design system (cream canvas `#f7f7f4`, Cursor Orange `#f54e00`, Inter + JetBrains Mono, hairline borders / no shadows) is inlined in `web/layout_base.php`. See `DESIGN.md` for tokens.

## Key files

- `handlers/router.php` — front controller / route table
- `config/WebhookHandler.php` — abstract base for all `push*.php` receivers
- `config/database.php` — PDO singleton + `.env` parser
- `includes/auth.php` — `require_login()`, `require_admin()`, `get_jimi_user()`, `get_customer_id()`, `login_user()`, `set_customer_context()`
- `includes/functions.php` — `get_webhook_data()`, `normalize_data()`
- `core/Logger.php` — static logger (daily rotation, DEBUG→CRITICAL, auto-purge >30 days)
- `web/layout_base.php` / `layout_ativo_sidebar.php` / `layout_base_close.php` — dashboard shell (sidebar + header + content)
- `mysql/jimi_tracker.sql` + `migration_v2.0.0.sql` + `migration_v3.1.0.sql` — schema (22 tables; v3.1.0 added `customers`, `users`, `customer_users`, `sessions`, `device_models`)

## Code style

- New webhook handlers **must** extend `WebhookHandler` and implement only `processItem()`.
- Comments in **PT-BR**; PHPDoc with `@param`/`@returns`/`@throws`.
- Follow Keep a Changelog in `CHANGELOG.md`.

## Not part of the application

`.agents/`, `.opencode/` are external agent-tooling frameworks (skills, sub-agent definitions), unrelated to the webhook system. Ignore them when working on application code.
