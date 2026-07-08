# STATUS.md — Jimi Webhook System v4.1.0 (YUV Parity)

> **Última atualização**: 08/07/2026 — **Fases 0–M concluídas. Suite Playwright 37/37 verde, replay E2E 8/8, 5 bugs críticos corrigidos (ver §11).**
> **Servidor**: `http://189.22.240.43` (Apache 2.4 + PHP 8.3 + MySQL)
> **Dev Windows**: PHP 8.3.32 em `C:\Users\flavi\php\php.exe` + MySQL 8.0.37 portátil em `C:\Users\flavi\mysql` (`scripts/dev-windows.ps1`)

---

## 0. Iniciativa v4.0.0 — YUV Parity (CONCLUÍDA)

**Objetivo**: transformar o projeto em uma cópia fiel do YUV (`app.yuv.com.br`). Gateway de webhooks Jimi preservado; dashboard e design reconstruídos com design system Coinbase. O núcleo é a **gestão de ocorrências DMS** (alarme de câmera → ocorrência → tratativa → risco, com regras por cliente).

**Documentos de referência**:
- `PROJETO_YUV.md` — blueprint-mestre (rotas-alvo, modelo de dados, specs das 22 telas, motor de ocorrências)
- `analise_yuv/analise_yuv.html` — análise visual do YUV (22 telas + regras de negócio, com screenshots)
- `DESIGN.md` / `DESIGN-coinbase.md` — design system Coinbase (azul `#0052ff`, sidebar dark `#0a0b0d`, CTAs pill)

### Status consolidado do roadmap

| Fase | Arquivos | Lint | Principais entregas |
|---|---|---|---|
| **0 — Fundação** | 29 | ✅ | Migração v4.0.0 (15 tabelas + alterações), router com subrotas, sidebar-sanfona, header On/Off, 5 componentes base (kpi_card, risk_bar, status_pill, filter_bar, crud_grid), 18 placeholder handlers + 2 AJAX |
| **1 — Motor Ocorrências** | 5 | ✅ | `occurrence_engine.php` integrado em `pushalarm.php` (matching triplo código/nome/categoria + link_upload), CRUD `/config-ocorrencias` com rows dinâmicas de parâmetros, pushfileupload/pushftpfileupload com channel/download_status |
| **2 — Módulo DMS** | 4 | ✅ | Dashboard `/ocorrencias/dashboard` (KPIs + risk bar + grade + polling 15s), tela de tratativa inline (vídeo, alarmes agrupados, transições status/notas/falso-positivo), `/relatorios/ocorrencias` (6 filtros), `/relatorios/alarmes` (ordenação clicável, mapa OSM) |
| **3 — Vídeo** | 3 | ✅ | `/video/aovivo` (flv.js + rotation/watermark + proNo 37121), `/video/playback` (filtro + timeline + play inline), `/video/downloads` (grade com status disponível/solicitado/erro + download direto) |
| **4+5 — Equipamentos+Relatórios** | 9 | ✅ | `/equipamentos` (grade + form com periféricos chip-style + FOTA modal + import CSV), `/relatorios/posicoes` (mapa Leaflet com fitBounds), `/relatorios/deslocamento` (trips com duração/distância/alarmes), `/relatorios/desatualizados` (5 buckets KPI + drill-down), `/exportar` (fila jobs), `scripts/worker.php`, `scripts/trip_builder.php` (haversine), `scripts/metrics_rollup.php` |
| **6 — Cadastros** | 5 | ✅ | `/chips` (CRUD SIM), `/motoristas` (CRUD + alertas vencimento CNH/toxicológico), `/grupos-permissao` (matriz 18 telas × 5 ações JSON + contagem usuários), `/clientes` evoluído (occurrence_config_id, faceid_enabled, brand_color, logo_url, impersonar com `impersonation_log`), `/usuarios` evoluído (abas Minha Empresa/Meus Clientes, user_type, permission_group_id, photo_url) |
| **7 — Visão Executiva** | 4 | ✅ | `/` Resumo (4 KPIs, heatmap Leaflet, velocidade frota, desatualizados, top clientes revendedor, séries Chart.js hora-a-hora alarmes+ocorrências), `/bi` (gráficos barras/pizza/linha sob demanda com filtros), `/rastreamento` (cliente→ativo→mapa cascata + busca + auto-refresh 60s) |
| **F — Segurança+Checklist** | 9 | ✅ | `includes/csrf.php` (token por sessão, `csrf_verify()` em 8 páginas, `csrf_field()` em todos os forms), cookie `Secure`/`HttpOnly`/`SameSite=Lax`, `auth_cleanup()` (sessions + request_logs periódico), GPS (0,0) filtrado, rotas mortas removidas, `/checklist` (3 tabelas + CRUD com itens dinâmicos boolean/text/photo/number) |
| **G — Performance+Polish** | 5 | ✅ | `metrics_snapshots` (nova tabela, 22 métricas por cliente), `metrics_rollup.php` (pré-computa KPIs a cada 5 min), `resumo.php` (lê do cache com fallback on-the-fly), tour de boas-vindas 5 passos (localStorage) + banner de comunicado, `exportar.php` (form de novo relatório com CSRF), `worker.php` (CSV real para 5 tipos: alarms/occurrences/positions/trips/devices), `bi.php` (filtro Motoristas + chips multi-select de Alarmes com overflow +N) |
| **H — UX+Security+Quality** | 11 | ✅ | `/checklist/inspecao` (preenchimento de inspeção), filtro de período no dashboard ocorrências, rate limiting 5 tentativas/15min + `login_log`, white-label `brand_color` na sidebar CSS, import CSV real em equipamentos (POST batch), prepared statements em 9 arquivos legacy (dashboard/ativos/comandos/config/live/video/relatorios/chips/motoristas), `pushcmd.php` removido do disco, md5 com `JSON_UNESCAPED_UNICODE`, aliases `lon`/`msgId` em normalize_data, dupla normalização removida (pushalarm/pushresourcelist) |
| **I — Tooling+Polish** | 4 | ✅ | `.githooks/pre-commit` (lint PHP automático, `git config core.hooksPath .githooks`), R13: `pushTerminalTransInfo` extrai `content`/`extensionData` estruturado, R16: log de erros em pushresourcelist, README.md atualizado com 30 rotas v4.0.0 + workers + segurança + white-label |
| **J — Deploy** | 3 | ✅ | `DEPLOY_v4.md` (plano completo com checklist, rollback, crontab), `scripts/deploy-v4.sh` (--check/--backup/--migrate/--deploy/--verify, idempotente, verifica 17 tabelas v4), `.env.example` atualizado (IOTHUB vars, SYSTEM_VERSION=4.0.0), `update-homolog.sh` e `deploy.sh` com suporte a migration v4.0.0, `scripts/crontab-setup.sh` (--check/--install/--remove workers) |
| **K — Resiliência (hotfix)** | 18 | ✅ | Todas queries de tabelas v4 blindadas com try-catch: `resumo.php` (metrics_snapshots+occurrences), `bi.php` (occurrences+drivers), `rel_ocorrencias.php` (5 queries), `rel_deslocamento.php` (trips+drivers), `exportar.php` (jobs), `ocorrencias_dashboard.php` (detail+events), `ativos.php` (device_statistics), `camerasdata.php` (device_statistics), `ativo_detalhe.php` (device_statistics), `rastreamento.php` (gps_data), `rel_posicoes.php` (gps_data), `rel_desatualizados.php` (last_position_at), `clientes.php` (occurrence_configs), `chips.php` (sim_cards), `motoristas.php` (drivers), `equipamentos.php` (branches), `grupos_permissao.php` (permission_groups), `usuarios.php` (permission_groups), `checklist.php`+`checklist_inspection.php` (checklists), `config_ocorrencias.php` (occurrence_configs) |
| **L — Bugfixes frontend** | 5 | ✅ | Login: redirect `/dashboard`→`/` + versão 4.0.0 + rate limiting resiliente. Legacy: `dashboard.php` e `live.php` viram redirect. Router: `config-ocorrencias` (hífen→underscore) adicionado `$renamedRoutes`. Playback: envia proNo 34817 ao clicar Requisitar. Migration: `d'água`→`dagua` (apostrofo quebrava SQL) |

> **Total**: **80 arquivos** PHP (79 lint) + 1 migration SQL + README.md. **0 erros de lint** em todo o projeto.

---

## 1. Arquitetura do Projeto (v4.0.0)

```
Jimi IoT Hub ──POST──▶ .htaccess ──▶ handlers/router.php ──▶ handlers/*.php
                                              │
   ┌──────────────────────────────────────────┴──────────────────────────────────┐
   │ 1) WEBHOOKS (push*.php extends WebhookHandler)                                │
   │    token → async 200 (fastcgi) → normalize → INSERT → stats → occurrence_engine│
   │                                                                               │
   │ 2) DASHBOARD + AJAX (layout Coinbase: web/layout_base.php)                    │
   │    require_login() / require_admin() + csrf_verify() nos POST                  │
   │                                                                               │
   │ 3) WORKERS (cron): worker.php (jobs), trip_builder.php (viagens),             │
   │    metrics_rollup.php (KPIs)                                                  │
   └───────────────────────────────────────────────────────────────────────────────┘
```

### Stack
- PHP 8.3 puro (sem framework, sem build step)
- MySQL 8.0 com prepared statements
- Front controller `router.php` (subrotas de 2 segmentos)
- Design system Coinbase inline CSS (Inter + JetBrains Mono, azul `#0052ff`)
- Leaflet + Chart.js + flv.js via CDN
- Autenticação token-based via cookie `jimi_token` + tabela `sessions` MySQL
- CSRF via token de sessão (`includes/csrf.php`)

---

## 2. Rotas Implementadas (v4.0.0 — 30 rotas)

### Sidebar — Principal
| Rota | Handler | Auth | Descrição |
|---|---|---|---|
| `/` | `resumo.php` | Login | Visão 360°: KPIs, heatmap, velocidade, desatualizados, top clientes, séries Chart.js |
| `/rastreamento` | `rastreamento.php` | Login | Mapa live: cliente→ativo cascata, circle markers, busca, auto-refresh 60s |
| `/bi` | `bi.php` | Login | BI: filtros + gráficos barras/pizza/linha sob demanda (Chart.js) |
| `/ocorrencias/dashboard` | `ocorrencias_dashboard.php` | Login | Dashboard DMS: KPIs, risk bar, grade, polling 15s, detalhe/tratativa inline |
| `/comandos` | `comandos.php` | Login | Presets JIMI/JT-T, polling 3s/10s/5min |
| `/exportar` | `exportar.php` | Login | Fila de jobs assíncronos com auto-refresh 30s |

### Grupo Vídeos (sidebar-sanfona)
| Rota | Handler | Descrição |
|---|---|---|
| `/video/aovivo` | `video_aovivo.php` | flv.js + proNo 37121 + rotation/watermark CSS + status bar |
| `/video/playback` | `video_playback.php` | Filtro equipamento/canal/período → timeline → play inline |
| `/video/downloads` | `video_downloads.php` | Grade com status disponível/solicitado/erro + download |

### Grupo Relatórios (sidebar-sanfona)
| Rota | Handler | Descrição |
|---|---|---|
| `/relatorios/posicoes` | `rel_posicoes.php` | Ativo + período + mapa Leaflet + paginação |
| `/relatorios/deslocamento` | `rel_deslocamento.php` | Viagens (trips): duração, vel.máx, distância km, alarmes |
| `/relatorios/desatualizados` | `rel_desatualizados.php` | 5 buckets KPI clicáveis + drill-down |
| `/relatorios/alarmes` | `rel_alarmes.php` | Ordenação clicável, 5 filtros, link mapa OSM, paginação |
| `/relatorios/ocorrencias` | `rel_ocorrencias.php` | 6 filtros: cliente, IMEI, tipo, status, risco, falso-positivo |

### Grupo Cadastros (sidebar-sanfona)
| Rota | Handler | Descrição |
|---|---|---|
| `/ativos` | `ativos.php` | Lista + editar inline + remover (soft-delete) |
| `/ativos/novo` | `ativos_novo.php` | Cadastro com dropdown de modelos |
| `/ativos/{imei}` | `ativo_detalhe.php` | 9 abas com sidebar lateral |
| `/chips` | `chips.php` | CRUD SIM cards (operadora, MSISDN, ICCID, vínculo IMEI) |
| `/clientes` | `clientes.php` | CRUD + occurrence_config + faceid + brand_color + impersonar |
| `/equipamentos` | `equipamentos.php` | Grade + form (periféricos, rotação, watermark) + FOTA + import CSV |
| `/grupos-permissao` | `grupos_permissao.php` | Matriz 18 telas × 5 ações JSON + contagem de usuários |
| `/motoristas` | `motoristas.php` | CRUD + compliance (CNH, toxicológico, vencimentos com alerta) |
| `/config-ocorrencias` | `config_ocorrencias.php` | Perfis de regras com rows dinâmicas de parâmetros |
| `/usuarios` | `usuarios.php` | Abas Minha Empresa/Meus Clientes, user_type, permission_group, photo |

### AJAX / Infra
| Rota | Handler | Descrição |
|---|---|---|
| `/camerasdata`, `/trackdata`, `/hbdata`, `/mediadata` | idem | Dados de mapa/telemetria (escopo por sessão) |
| `/commandstatus`, `/sendcommand`, `/devicemodels` | idem | Comandos e modelos |
| `/ocorrenciasdata` | `ocorrenciasdata.php` | Polling DMS (KPIs + grade paginada) |
| `/exportardata` | `exportardata.php` | Polling da fila de jobs |
| `/customer_switch` | `customer_switch.php` | AJAX: troca contexto de cliente |
| `/perfil` | `perfil.php` | Troca de senha |
| `/checklist` | `checklist.php` | CRUD de checklists de inspeção |

### Webhook Endpoints (preservados)
`/pushevent`, `/pushhb`, `/pushgps`, `/pushalarm`, `/pushfileupload`, `/pushlbs`, `/pushresourcelist`, `/pushftpfileupload`, `/pushiothubevent`, `/pushTerminalTransInfo`, `/pushinstructresponse`, `/pushevent`

---

## 3. Banco de Dados (v4.0.0 — 27 tabelas)

### Tabelas v4.0.0 (12 novas)
| Tabela | Descrição |
|---|---|
| `branches` | Filiais (nível abaixo de customer) |
| `drivers` | Motoristas + compliance (CNH, toxicológico, FaceID identifier) |
| `sim_cards` | Chips SIM (operadora, MSISDN, ICCID, vínculo IMEI) |
| `permission_groups` | Grupos RBAC com matriz JSON de permissões |
| `occurrence_configs` | Perfis de configuração de ocorrências |
| `occurrence_config_params` | Parâmetros por tipo de alarme (gera? risco? janela?) |
| `occurrences` | Ocorrências DMS (núcleo do motor) |
| `occurrence_events` | Alarmes agrupados em cada ocorrência |
| `trips` | Viagens detectadas por ignição |
| `jobs` | Fila de jobs assíncronos (report, video_download, rollup) |
| `geocode_cache` | Cache de geocodificação reversa (lat/lng → endereço) |
| `impersonation_log` | Auditoria de impersonação revendedor→cliente |
| `checklist_configs` | Configurações de checklist de inspeção |
| `checklist_items` | Itens de checklist (pergunta, tipo, obrigatório) |
| `checklist_responses` | Respostas de inspeções realizadas |

### Alterações em tabelas existentes
| Tabela | Colunas adicionadas |
|---|---|
| `users` | `user_type` (revendedor/cliente), `permission_group_id`, `photo_url` |
| `customers` | `reseller_id`, `brand_color`, `logo_url`, `occurrence_config_id`, `checklist_config_id`, `faceid_enabled` |
| `devices` | `sim_card_id`, `peripherals` (JSON), `streaming_rotation`, `streaming_watermark`, `firmware_version`, `branch_id` |
| `media_files` | `channel`, `download_status` (solicitado/disponivel/erro) |

### Índices críticos
- `idx_occ_customer_status` em `occurrences(customer_id, status, last_alarm_at)`
- `idx_occ_imei_type` em `occurrences(imei, alarm_type, last_alarm_at)`
- `idx_alarms_imei_time` em `alarms(imei, alarm_time)`
- `idx_gps_imei_time` em `gps_data(imei, gps_time)`
- `idx_trips_imei_time` em `trips(imei, started_at)`
- `idx_payload_hash_created` em `request_logs(payload_hash, created_at)` — corrige R07

### Seeds
- `occurrence_configs`: perfil "Padrão Sistema" com 22 parâmetros DMS/ADAS/Acidente
- `permission_groups`: "Administrador" (revendedor, todas as permissões) e "Operador Padrão" (cliente)

### Migrations (ordem correta)
```bash
mysql -u root -p < mysql/jimi_tracker.sql                  # schema base
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql # v2.0.0
mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql # v3.1.0 (multi-tenant)
mysql -u root -p jimi_tracker < mysql/migration_v4.0.0.sql # v4.0.0 (YUV Parity)
mysql -u root -p jimi_tracker < mysql/migration_v4.1.0.sql # v4.1.0 (Excel/PDF + fix seed DMS)
```

---

## 4. Fluxo do Motor de Ocorrências (DMS)

```
1. Device gera ALARME (distração, celular, sem cinto, fadiga…)
        │
        ▼
2. pushalarm.php recebe → INSERT alarms
        │
        ▼
3. occurrence_engine.php (assíncrono, pós-200):
   ├─ resolve occurrence_config do customer do device (fallback: default)
   ├─ busca occurrence_config_param por alarm_type (matching triplo: código/nome/categoria)
   ├─ se generates_occurrence=0 → retorna (alarme fica só no relatório)
   ├─ verifica janela de dedup (threshold minutos, default 10)
   ├─ se existe ocorrência aberta → agrupa (incrementa count + last_alarm_at)
   └─ senão → cria nova ocorrência com risco do perfil
        │
        ▼
4. pushfileupload/pushftpfileupload → INSERT media_files
   └─ link_upload_to_occurrence(): vincula mídia a ocorrência aberta (±3 min)
        │
        ▼
5. Dashboard /ocorrencias/dashboard → polling 15s → operador vê em tempo real
        │
        ▼
6. Operador abre caso → vê vídeo + alarmes agrupados + mapa
   ├─ Iniciar Tratativa → status = em_tratativa
   ├─ Resolver → status = resolvida
   └─ Descartar / Falso Positivo → status = descartada
        │
        ▼
7. Relatórios auditam: /relatorios/ocorrencias + /relatorios/alarmes
```

---

## 5. Componentes Reutilizáveis (`web/components/`)

| Componente | Arquivo | Parâmetros |
|---|---|---|
| Cartão KPI colorido | `kpi_card.php` | `$label`, `$value`, `$variant` (blue/green/yellow/red), `$sub_value` |
| Barra de distribuição (3 faixas) | `risk_bar.php` | `$low_pct`, `$med_pct`, `$high_pct`, labels customizáveis |
| Selo de status/risco (pill) | `status_pill.php` | `$status`, `$type` (status/risk/online/generic) |
| Barra de filtros "Gerar" | `filter_bar.php` | `$filters` (multiselects), `$show_period`, `$show_export` |
| Grade CRUD padrão | `crud_grid.php` | `$title`, `$columns`, `$rows`, `$actions`, `$create_url`, paginação |

---

## 6. Workers (cron)

| Script | Periodicidade | Função |
|---|---|---|
| `scripts/worker.php` | Cada 1 min | Processa fila `jobs`: report (gera CSV), video_download, rollup |
| `scripts/trip_builder.php` | Cada 15 min | Segmenta `gps_data` em `trips` por ignição (lig→desl), calcula distância haversine, cruza alarmes da janela |
| `scripts/metrics_rollup.php` | Cada 5 min | Stub para pré-computar KPIs do Resumo/BI (implementação completa na próxima iteração) |

---

## 7. Segurança (dívidas fechadas na Fase F)

| Ref | O que | Status |
|---|---|---|
| R01/R02 | Cross-tenant leak em 7 endpoints AJAX | ✅ Corrigido v3.2.1 |
| R03 | proNo whitelist não-bloqueante | ✅ Corrigido v3.2.1 |
| R04 | SQL injection em relatorios.php | ✅ Corrigido (prepared statements v4.0.0) |
| R05 | Open redirect no login | ✅ Corrigido v3.2.1 |
| R06 | GPS (0,0) descartado | ✅ Corrigido v4.0.0 (filtro ABS > 0.0001) |
| R07 | Índice faltante em request_logs | ✅ Adicionado na migração v4.0.0 |
| R08 | Rotas mortas clientes_novo/cliente_dashboard | ✅ Removidas do router |
| R09 | pushcmd.php código morto | ✅ Removido do router |
| R11 | CSRF ausente em formulários POST | ✅ `includes/csrf.php` + 8 páginas protegidas |
| R18 | Cookie Secure=false | ✅ Secure/HttpOnly/SameSite=Lax |
| R19 | Sem limpeza de sessions/request_logs | ✅ `auth_cleanup()` probabilístico (~1% requests) |

---

## 8. Ambiente de Desenvolvimento

### Servidor produção
- **IP**: `189.22.240.43`
- **Apache**: 2.4 + mod_rewrite
- **PHP**: 8.3 (FPM)
- **MySQL**: 8.0 em localhost
- **Path**: `/var/www/jimi_webhook`

### Dev Windows
- **PHP**: `C:\Users\flavi\php\php.exe` (8.3.32)
- **Lint**: `php -l <arquivo>` — 68 arquivos verificados, 0 erros
- **Servidor local**: `php -S localhost:8000 server.php`

### Environment (.env)
```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=jimi_tracker
DB_USER=root
DB_PASS=***
WEBHOOK_TOKEN=a12341234123
SYSTEM_VERSION=4.0.0
FILE_STORAGE_URL=http://189.22.240.43:23010/download/
STREAM_URL=http://189.22.240.43:8881
IOTHUB_COMMAND_URL=http://localhost:10088/api/device/sendInstruct
IOTHUB_API_TOKEN=123
```

---

## 9. Estrutura de Arquivos (v4.0.0)

```
jimi_webhook/
├── .env / .env.example
├── .htaccess                     # Front controller + security headers
├── AGENTS.md / CLAUDE.md         # Guias para AI agents
├── STATUS.md                     # Este arquivo
├── PROJETO_YUV.md                # Blueprint-mestre YUV Parity
├── DESIGN.md / DESIGN-coinbase.md # Design system Coinbase
├── CHANGELOG.md
│
├── config/
│   ├── database.php              # PDO singleton
│   └── WebhookHandler.php        # Abstract webhook base class
│
├── core/
│   └── Logger.php                # Static logger
│
├── includes/
│   ├── auth.php                  # Token-based auth + cleanup (Secure/HttpOnly/SameSite)
│   ├── csrf.php                  # CSRF protection (token por sessão)
│   ├── functions.php             # normalize_data(), get_webhook_data()
│   ├── occurrence_engine.php     # Motor DMS (process_alarm_to_occurrence, link_upload)
│   └── geocode.php               # Geocodificação reversa com cache
│
├── handlers/
│   ├── router.php                # Front controller v4.0.0 (subrotas 2 segmentos)
│   ├── login.php / logout.php / setup.php
│   ├── customer_switch.php
│   ├── resumo.php                # Home — visão 360°
│   ├── rastreamento.php          # Mapa live cliente→ativo
│   ├── bi.php                    # Business Intelligence
│   ├── ocorrencias_dashboard.php # Dashboard DMS + tratativa inline
│   ├── ocorrenciasdata.php       # AJAX polling DMS
│   ├── exportar.php              # Fila de jobs
│   ├── exportardata.php          # AJAX polling jobs
│   ├── ativos.php / ativos_novo.php / ativo_detalhe.php
│   ├── chips.php                 # CRUD SIM cards
│   ├── clientes.php              # CRUD + impersonar + white-label
│   ├── equipamentos.php          # CRUD + FOTA + import CSV
│   ├── grupos_permissao.php      # Matriz RBAC JSON
│   ├── motoristas.php            # CRUD + compliance
│   ├── config_ocorrencias.php    # Perfis de regras DMS
│   ├── checklist.php             # CRUD checklists
│   ├── usuarios.php              # Abas empresa/clientes
│   ├── perfil.php / devicemodels.php
│   ├── comandos.php / config.php
│   ├── video_aovivo.php          # flv.js + proNo 37121
│   ├── video_playback.php        # Timeline + play
│   ├── video_downloads.php       # Grade downloads
│   ├── rel_posicoes.php          # Mapa Leaflet + paginação
│   ├── rel_deslocamento.php      # Viagens (trips)
│   ├── rel_desatualizados.php    # 5 buckets KPI
│   ├── rel_alarmes.php           # Ordenação clicável
│   ├── rel_ocorrencias.php       # 6 filtros históricos
│   ├── camerasdata.php / commandstatus.php / sendcommand.php
│   ├── mediadata.php / trackdata.php / hbdata.php
│   ├── ping.php
│   └── push*.php (11 webhook receivers)
│
├── web/
│   ├── layout_base.php           # Shell v4.0.0: sidebar-sanfona + header On/Off + colapsar + mobile
│   ├── layout_base_close.php
│   ├── layout_ativo_sidebar.php
│   ├── login_template.php
│   └── components/
│       ├── kpi_card.php
│       ├── risk_bar.php
│       ├── status_pill.php
│       ├── filter_bar.php
│       └── crud_grid.php
│
├── mysql/
│   ├── jimi_tracker.sql          # Schema base
│   ├── migration_v2.0.0.sql
│   ├── migration_v3.1.0.sql
│   └── migration_v4.0.0.sql      # YUV Parity (15 tabelas + alterações + índices + seeds)
│
├── scripts/
│   ├── deploy.sh / update-homolog.sh
│   ├── worker.php                # Cron: processa jobs
│   ├── trip_builder.php          # Cron: segmenta viagens (haversine)
│   ├── metrics_rollup.php        # Cron: KPIs (stub)
│   └── dev-windows.ps1           # Ambiente dev Windows
│
├── docs/
│   └── (PRD, ADRs)
│
├── analise_yuv/
│   └── analise_yuv.html          # Fonte visual de verdade
│
└── logs/                         # Runtime logs (gitignored)
```

---

## 10. Pendências para Próxima Iteração

### Melhorias funcionais
- [x] **Resumo `/`**: metrics_rollup para pré-computar KPIs — **Fase G**
- [x] **Resumo `/`**: tour de boas-vindas (5 passos) + banner de comunicado com localStorage — **Fase G**
- [x] **BI `/bi`**: filtro de Motoristas e multi-select de Alarmes com chips `+N` — **Fase G**
- [x] **Exportar**: CSV real para 5 tipos de relatório — **Fase G**
- [x] **Dashboard ocorrências**: filtro de período no polling — **Fase H**
- [x] **Checklist**: tela de preenchimento/inspeção — **Fase H**
- [x] **Importação em lote**: POST real do CSV parseado — **Fase H**
- [x] **White-label**: brand_color na sidebar — **Fase H**
- [x] **Vídeo Playback**: envia proNo 34817 ao clicar Requisitar — **Fase L**
- [ ] **OTA firmware**: testar proNo 33027 end-to-end com dispositivo real *(requer device — ver §11.4)*
- [x] **Relatórios**: exportação Excel/PDF (CSV/XLSX/PDF, PHP puro) — **Fase M.1**
- [x] **App mobile PWA**: manifest + ícones + off-canvas + touch targets — **Fase M.3**

### Infra e tooling
- [x] **Rate limiting no login**: 5 tentativas/15 min + `login_log` — **Fase H**
- [x] **Lint pre-commit hook**: `.githooks/pre-commit` — **Fase I**
- [x] **Logs de acesso**: `login_log` — **Fase H**
- [x] **Resiliência total**: 55 queries v4 com try-catch — **Fase K**
- [x] **Login redirect**: `/dashboard` → `/` + safe_redirect_path — **Fase L**
- [x] **Router: config-ocorrencias**: renamedRoutes map (hífen vs underscore) — **Fase L**
- [x] **Migration fix**: apóstrofo `d'água` → `dagua` — **Fase L**
- [x] **Legacy pages**: dashboard.php e live.php → redirect — **Fase L**
- [x] **Deploy scripts**: deploy-v4.sh, crontab-setup.sh, hotfix_login_log.sql — **Fase J**
- [x] **Testes automatizados**: Playwright — 40 testes, 6 specs, 37/37 verde — **Fase M.4**
- [ ] **Verificar end-to-end**: comandos → IoTHub → dispositivo → pushinstructresponse *(script pronto; execução requer servidor — ver §11.4)*
- [x] **Arquivos de mídia**: `/pushfileupload` → `media_files` → vínculo com ocorrência verificado no replay E2E — **Fase M.2**

### Dívida técnica (não-crítica)
- [x] String interpolation em 9 arquivos → prepared statements — **Fase H**
- [x] `pushTerminalTransInfo.php` estruturado (R13) — **Fase I**
- [x] `normalize_data()` aliases `lon`/`msgId` (R14) — **Fase H**
- [x] Dupla normalização (pushalarm/pushresourcelist) (R15) — **Fase H**
- [x] Código morto em pushresourcelist (R16) — **Fase I**
- [x] md5 sem `JSON_UNESCAPED_UNICODE` (R17) — **Fase H**
- [x] `pushcmd.php` removido do disco — **Fase H**
- [x] README.md atualizado para v4.0.0 — **Fase I**

### Funcionalidades futuras (fora do escopo YUV)
- [ ] **Licenciamento por equipamento**: campo de licença/plano por device/cliente
- [ ] **White-label completo**: sidebar colorida por cliente (hoje só armazena `brand_color`)
- [ ] **App mobile**: PWA responsivo (hoje web responsivo com sidebar off-canvas)
- [ ] **FaceID como serviço**: identificar motorista automaticamente (hoje consome identificador do device)

---

## 11. Iteração v4.1.0 — Fases M.1–M.5 (08/07/2026)

Plano executado: [PLANO_PENDENCIAS_v4.md](PLANO_PENDENCIAS_v4.md). Decisões das questões abertas:
**(1)** Excel/PDF em **PHP puro** (ZipArchive + writer PDF 1.4 próprios) — Composer não existe no ambiente e o projeto é "no package manager"; **(2)** IoTHub produção não acessível daqui — partes locais executadas, restante documentado em §11.4; **(3)** Playwright instalado via npm/npx (Node 24 local).

### 11.1 Entregas

| Fase | Entrega | Verificação |
|---|---|---|
| **M.3 PWA** | `manifest.json` + 4 ícones GD (`assets/icons/`) + meta tags + sidebar off-canvas (backdrop, scroll lock, swipe) + touch targets 44px + tabelas com scroll interno + header mobile compacto + login responsivo | Emulação iPhone 14: manifest/ícones 200, **0px overflow horizontal**, screenshots aprovados |
| **M.1 Excel/PDF** | `includes/export_helper.php` (XlsxWriter streaming + PdfWriter paginado + `export_mime_type`), `worker.php` com `buildReportSource()` + despacho por formato, seletor no form, `jobs.format` (migration v4.1.0), CSV com BOM + `;` | XLSX: zip válido, 6 parts XML well-formed; PDF: xref 100% correto, 4 páginas/120 linhas; specs Playwright de download 3/3 verdes (magic bytes) |
| **M.2 E2E** | `scripts/test_e2e.sh` (ping→gps→alarme 143→upload→verificação MySQL) + fix seed DMS na migration v4.1.0 | **8/8 verde** no dev: alarme gravado, ocorrência criada, mídia vinculada (±3 min) |
| **M.4 Playwright** | `package.json`, `playwright.config.js` (webServer automático), `tests/fixtures/auth.js`, 6 specs / 40 testes, `scripts/run-tests.ps1` | **37 passed, 0 failed, 3 skipped** (opt-in: rate-limit destrutivo; multi-tenant requer 2º cliente) |
| **M.5 Docs** | `API_COVERAGE.md` novo, README (Testes + doc table), CHANGELOG 4.1.0, este STATUS, PRD, memória de agents | — |

### 11.2 Bugs encontrados e corrigidos (achados da verificação E2E)

| # | Severidade | Bug | Fix |
|---|---|---|---|
| 1 | **Crítica** | Motor de ocorrências **nunca disparava via webhook**: `pushalarm.php` lia `lastInsertId()` depois do `CALL update_device_stats_after_alarm` (procedure reseta para 0) → gate `$alarmId > 0` nunca passava | ID capturado imediatamente após o INSERT (`$insertedAlarmId`) |
| 2 | **Crítica** | Seed `occurrence_config_params` órfão: nomes ('Distração', 'Fadiga', 'SOS'…) não existem em `alarm_types` → nenhum alarme DMS/ADAS gerava ocorrência | Migration v4.1.0: 19 params órfãos substituídos por 34 com nomes reais do catálogo (JIMI + JT/T) |
| 3 | **Crítica** | CSRF sempre falhava (403 em **todo POST** desde a Fase F): token guardado em `$_SESSION` sem `session_start()` (superglobal é por request) → token novo a cada request | Token derivado por HMAC-SHA256(cookie de sessão, secret) — estável por login, sem estado no servidor |
| 4 | Alta | `auth_init()` sem `return` → `/ocorrenciasdata` e `/exportardata` respondiam 401 sempre | Retorna `!empty($_SESSION['user_id'])` |
| 5 | Alta | `/grupos-permissao` 404 (rota com hífen em `$simpleRoutes` montava arquivo inexistente) | Movida para `$renamedRoutes` → `grupos_permissao.php` |
| 6 | Alta | Coluna fantasma `devices.last_position_at` (não existe em migration alguma) quebrava relatório de devices, `/relatorios/desatualizados` e `metrics_rollup` | `LEFT JOIN device_statistics` → `last_gps_time` |
| 7 | Média | `Logger.php`: deprecation float→int (PHP 8.1+) vazava HTML nas respostas JSON dos webhooks | Cast `(int)$timestamp` |
| 8 | Baixa | `exportar.php` passava token CSRF como flag `$exit_on_fail` | `csrf_verify()` |

### 11.3 Ambiente de teste local (usado na verificação)

- MySQL 8.0.37 portátil (`C:\Users\flavi\mysql`) — subir com `scripts/dev-windows.ps1`; migrations v4.0.0 + v4.1.0 aplicadas (42 tabelas, `system_info.version = 4.1.0`)
- Usuário E2E: `e2e@teste.local` (admin, customer 1 "Frota Principal") — usado por `TEST_EMAIL`/`TEST_PASSWORD`
- Device de teste: IMEI `868120246598152` (criado pelo `test_e2e.sh`)

### 11.4 Pendências que exigem produção/dispositivo real

- [ ] **M.2.1** Verificar IoTHub no servidor: `curl http://localhost:10088/api/device/status` (via SSH)
- [ ] **M.2.2/2.3** Comando real proNo 128 via `/sendcommand` + monitorar `pushinstructresponse` nos logs
- [ ] **M.2.5** OTA firmware proNo 33027 com device real
- [ ] Rodar `bash scripts/test_e2e.sh` no servidor após aplicar a migration v4.1.0 (`./scripts/deploy.sh` já a aplica)
- [ ] Specs multi-tenant: exigem credenciais de um segundo cliente (`TEST_EMAIL_B`/`TEST_PASSWORD_B`)

---

## 12. Comandos Úteis

```bash
# Lint local (Windows PowerShell)
$files = Get-ChildItem -Recurse -Include *.php -Path handlers,includes,config,core,web,scripts
foreach ($f in $files) { & "C:\Users\flavi\php\php.exe" -l $f.FullName }

# Servidor dev local
php -S localhost:8000 server.php

# Deploy produção
./scripts/deploy.sh
./scripts/deploy.sh --force

# Migração (fresh install)
mysql -u root -p < mysql/jimi_tracker.sql
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.1.0.sql

# Testes E2E
./scripts/run-tests.ps1          # Playwright (Windows)
bash scripts/test_e2e.sh         # replay de webhooks

# Webhook replay (teste)
curl -X POST http://localhost:8000/pushalarm \
  -H "Content-Type: application/json" \
  -d '{"token":"a12341234123","data_list":[{"imei":"868120246598152","msgClass":0,"msg":{"alertType":"100","alarmTime":"2026-07-06 12:00:00"}}]}'

# Workers (cron)
php scripts/worker.php
php scripts/trip_builder.php
php scripts/metrics_rollup.php
```
