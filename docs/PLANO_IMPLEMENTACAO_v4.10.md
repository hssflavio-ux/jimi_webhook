# Plano de Implementação — v4.10 (Frota: manutenção, mapa, replay, widgets)

> Derivado da análise da plataforma Whitelabel Tracking
> (`docs/ANALISE_WHITELABEL.md`). Este documento registra o plano; a
> implementação será feita em versões a definir no momento do código.

## Escopo

| # | Feature | Origem (WLT) | Migração |
|---|---|---|---|
| 5 | Ícone do veículo colorido por estado no mapa | asset icon por estado | não |
| 3 | Manutenção preventiva (odômetro/ignição/horímetro/data) + documentos do motorista | Maintenance + reminders | sim |
| 6 | Replay de viagem com timeline scrub (tela nova) | Trips replay | não |
| 7 | Dashboard widgetizado por usuário | Dashboard widgets | sim |

## Decisões travadas

- **Versão**: aberta (definida na implementação; bump de `SYSTEM_VERSION`,
  `.env.example` e `deploy.sh`).
- **Horímetro**: campo do webhook é **`horimetro`** → ingestão grava em
  `devices.engine_hours`.
- **Dashboard**: layout **por usuário, edição livre** (cada usuário edita o
  próprio; fallback = padrão global → layout hardcoded atual).
- **Replay**: tela nova em `/relatorios/deslocamento/replay`.
- **Ordem de entrega**: **5 → 3 → 6 → 7**.

---

## Item 5 — Ícone por estado no mapa (sem migração)

`handlers/rastreamento.php` hoje usa `L.circleMarker` com só 2 cores
(online `#05b169` / offline `#a8acb3`).

1. Adicionar `d.speed_limit_kmh` e `c.default_speed_limit_kmh` (JOIN `customers`)
   às queries de devices (`:24-34`) e positions (`:40-51`).
2. Computar estado **no PHP** com `includes/fleet_state.php`:
   - `resolve_current_state($segState, $lastGpsTime)` → `movimento|ocioso|parado|offline`;
   - `classify_point($acc, $speed)`;
   - `resolve_speed_limit($deviceLimit, $customerLimit)` → `excesso` quando
     `speed > limite` e `acc=1`.
3. Emitir `state` no JSON (`?ajax=1` e `mapData` inline).
4. Cores: `FLEET_STATE_COLORS` (movimento `#0052ff`, ocioso `#a97a00`, parado
   `#5b616e`, offline `#cf202f`) + **novo** `excesso = #f4b000` (o vermelho já
   é do offline). Alterar só as linhas `var color = ...` (inicial `:143-152`,
   refresh `:186-205`).
5. Legenda no mapa (swatches + `FLEET_STATE_LABELS` + "Excesso de velocidade").
6. **Decisão**: unificar offline no limiar de 30 min (`OFFLINE_GAP_SECONDS`), em
   vez do 5 min atual de `last_communication`, para não contradizer
   `/relatorios/status-frota`.

**Arquivos**: `handlers/rastreamento.php`.
**Teste**: `tests/rastreamento_estado.spec.js` (cor por estado no navegador).

---

## Item 3 — Manutenção preventiva + documentos do motorista

### 3A. Manutenção por métrica

**Migração** (`mysql/migration_vX.sql` — nome na implementação):

```sql
CREATE TABLE `maintenance_reminders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `imei` varchar(30) DEFAULT NULL,          -- NULL = lembrete por motorista/data
  `driver_id` bigint unsigned DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `metric` enum('odometro','horas_ignicao','horimetro','data') NOT NULL,
  `interval_km` decimal(10,1) DEFAULT NULL,
  `interval_hours` decimal(10,1) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `last_done_km` decimal(12,2) DEFAULT NULL,
  `last_done_hours` decimal(10,1) DEFAULT NULL,
  `last_done_at` datetime DEFAULT NULL,
  `notify_bell` tinyint(1) NOT NULL DEFAULT 1,
  `notify_email` tinyint(1) NOT NULL DEFAULT 0,
  `emails` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mr_customer` (`customer_id`),
  KEY `idx_mr_imei` (`imei`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CALL add_column_if_not_exists('devices','engine_hours',
  "decimal(10,1) DEFAULT NULL COMMENT 'Horímetro reportado pelo equipamento' AFTER `speed_limit_kmh`");
```

**Resolução de métrica** (`includes/maintenance.php`):

| metric | fonte |
|---|---|
| `odometro` | `latest_odometer()` → último `gps_data.mileage > 0` |
| `horas_ignicao` | `SUM(duration_s)/3600` de `device_state_segments WHERE imei AND state IN ('movimento','ocioso') AND started_at >= last_done_at` |
| `horimetro` | `devices.engine_hours` |
| `data` | data de hoje (lembrete único / por data) |

**Ingestão do horímetro**: `handlers/pushgps.php` (e/ou `pushhb.php`) lê o campo
**`horimetro`** do payload e grava em `devices.engine_hours` (só quando > 0,
para não sobrescrever com vazio).

### 3B. Documentos do motorista (CNH / toxicológico)

- Colunas novas em `drivers`: `remind_cnh tinyint(1) DEFAULT 0`,
  `remind_tox tinyint(1) DEFAULT 0` — **a opção de disparar ou não** fica no
  cadastro do item.
- Worker varre `drivers` com `cnh_expires_at`/`tox_exam_expires_at` próximos
  (≤30/15/7 dias) e flag ligada → `notify()` `kind='lembrete'`
  (`includes/notification_engine.php`).

### Worker + tela

- `scripts/maintenance_worker.php` (cron diário): calcula `compute_due`, notifica
  dentro da janela (odômetro ≤200 km do vencimento; horas ≤N h; data ≤7 dias).
  Registrar em `scripts/crontab-setup.sh` (`CRON_JOBS`) + `--install` no servidor
  (o `deploy.sh` NÃO instala cron).
- Tela `/manutencoes` (`handlers/manutencoes.php`): abas **Manutenção** e
  **Documentos**; CRUD + "Registrar concluído" (grava `last_done_*`). Registrar
  em `$simpleRoutes` + `$screenByHandler['manutencoes.php']='manutencoes'` +
  `grupos_permissao.php::$screens['manutencoes']`.

### Correção bônus

`handlers/ativo_detalhe.php:34,47` referencia `s.total_distance` (coluna
inexistente) → trocar por `s.total_distance_km` e exibir o odômetro atual
(`latest_odometer`) na aba Visão Geral.

---

## Item 6 — Replay de viagem (tela nova, sem migração)

1. Rota: `$subrouteMap['relatorios']['deslocamento/replay'] =>
   'rel_deslocamento_replay.php'` (padrão de `deslocamento/rota`).
   `$screenByHandler` → `'relatorios'` (sem tela nova na matriz). Link "Replay"
   em `rel_deslocamento.php` (`:367`/`:381`) ao lado de "Ver rota".
2. `handlers/rel_deslocamento_replay.php`: carrega a viagem (`trips` por
   `trip_id`), busca pontos (`gps_data BETWEEN started_at AND ended_at`,
   downsample ~3000 como `rel_deslocamento_rota.php:109-125`), injeta `points`
   (epoch, lat, lng, speed, acc, mileage) + `janela = [strtotime(started_at),
   strtotime(ended_at)]`.
3. UI: Leaflet com polilinha + **marcador móvel** (interpolação via
   `requestAnimationFrame`, idiom `m.setLatLng()` de `rastreamento.php:186-205`);
   play/pause, velocidade (0.5×/1×/2×/4×), readout hora/velocidade/distância;
   **timeline SVG** copiando a interação de `video_playback.php` (zoom wheel,
   drag-to-pan, pointer-capture, `alvoDown`/`moveu`; linhas `579-654`,
   `670-694`, `1258-1336`) com **playhead** vertical em `x(now)`.
4. Overlay opcional de alarmes/ocorrências (reuso de
   `rel_deslocamento_rota.php:127-175`).

**Teste**: `tests/replay.spec.js` (replay de trip semeado: playhead avança,
marcador se move, scrub por clique).

---

## Item 7 — Dashboard widgetizado por usuário

**Migração** (`mysql/migration_vX.sql`):

```sql
CREATE TABLE `dashboard_layouts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,     -- NULL = padrão global
  `layout` json NOT NULL,                     -- [{"key":"kpi_devices","w":1,"config":{}}, ...]
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `user_key` bigint GENERATED ALWAYS AS (COALESCE(`user_id`,0)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dl_user` (`user_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

1. **Catálogo** (`includes/dashboard_widgets.php`): 13 widgets reaproveitando os
   blocos de `resumo.php` + `metrics_snapshots`:
   `kpi_devices`, `kpi_connectivity`, `kpi_occurrences`, `kpi_outdated`,
   `heatmap`, `speed_dist`, `idle`, `model_status`, `ts_alarms`,
   `ts_occurrences`, `top_plates`, `top_drivers`, `reseller_view` (só
   revendedor). `render_widget($key, $db, $cid)` + `dashboard_widget_catalog()`.
2. **`handlers/resumo.php`**: renderiza na ordem do layout do usuário (fallback
   padrão global → hardcoded atual). Modo "Editar painel" (mostrar/ocultar +
   reordenar ↑/↓, sem biblioteca nova).
3. **`handlers/dashboarddata.php`** (AJAX, `$ajaxRoutes`): `GET` devolve o
   layout; `POST` valida chaves contra o catálogo e grava (padrão de
   `camerasdata.php`: `require_ajax_session()` + escopo do próprio usuário +
   `csrf_verify()` no POST).

**Testes**: helper PHP (validação de layout/whitelist) +
`tests/dashboard_widgets.spec.js` (ocultar → persiste após F5; reordenar → ordem
muda).

---

## Cross-cutting / entrega

1. **Migrations** registradas explicitamente em `scripts/deploy.sh`
   (`run_migration`).
2. **Cron**: `maintenance_worker.php` em `CRON_JOBS` de `scripts/crontab-setup.sh`
   + `bash scripts/crontab-setup.sh --install` no servidor (falha é silenciosa).
3. **Permissões**: novas telas em `$screenByHandler` **e**
   `grupos_permissao.php::$screens` (lição da v4.8.5/v4.8.9/v4.9.11); lembrar
   que `can()` é permissivo para usuário sem grupo.
4. **Verificação**: `php -l` em todos os arquivos; `scripts/run-tests.ps1`
   (helpers + Playwright); specs novos por feature.

## Sequência

**5 → 3 → 6 → 7 →** testes + deploy.

## Pendências de implementação (a resolver no momento do código)

- Nome/versão exatos das migrações.
- Confirmar o campo `horimetro` num payload real (fallback genérico
  `horimetro` já planejado).
- Retenção de `device_state_segments` para `horas_ignicao` desde `last_done_at`
  (se os segmentos forem podados, o acumulado desde a última manutenção pode
  recomeçar — documentar a limitação).
