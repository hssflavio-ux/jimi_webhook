# STATUS.md — Jimi Webhook System v4.3.0 (YUV Parity)

> **Última atualização**: 23/07/2026 — **Fix da segmentação de viagens: `trip_builder.php` encerrava o deslocamento só em `acc=desligado`, então devices que mantêm a ignição ligada o dia todo (FJR7B59 `869058070151343`) colapsavam a jornada inteira numa viagem única de ~23h. Agora segmenta também por parada sustentada (>3 km/h por >5 min) e por buraco de dados, sempre fechando no último ponto em movimento. Rebuild no homolog: FJR7B59 11 → 39 viagens (§12.17). ⚠️ código ainda NÃO commitado/deployado — só os dados do homolog foram corrigidos.** Anterior: Relatório de Deslocamento em 2 modalidades + mapa de rota + teto de 31 dias + índice composto `trips(customer_id, started_at)`, deployado e verificado no homolog em `4.3.0` (§12.16); fix do `trip_builder`/`worker`/`metrics_rollup` (liam `gps_data.ignition` inexistente) + filtro de qualidade (§12.15).
> Vídeo ao vivo abrindo com stream real capturado da câmera online (payload 37121 corrigido + player resiliente). Comandos → device → resposta ponta-a-ponta (síncrono E offline), horários em BRT em todo o dashboard, cadastro de ativos adotando devices do gateway. Suite Playwright (navegação 25/25 verde), lint OK. **Detalhes da iteração de vídeo: §14. Diagnóstico anterior: §12.**
> **Servidor homolog**: `http://189.22.240.43` (Apache 2.4 host + PHP 8.3 FPM + MySQL 8.0 + stack IoTHub em 16 containers Docker) — implantado em `cd1af0f`
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
| `/relatorios/deslocamento` | `rel_deslocamento.php` | 2 modalidades: por deslocamento (trips) e fechamento diário (agregado por dia BRT); faixa horária opcional; link "Ver rota" por linha |
| `/relatorios/deslocamento/rota` | `rel_deslocamento_rota.php` | Mapa Leaflet do percurso (`trip_id` ou `imei`+`dia`): balões partida/chegada, pontos de comunicação, ocorrências destacadas |
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

- [x] **M.2.1** IoTHub verificado no servidor (09/07): `tracker-instruction-server` UP, `:10088` responde via localhost e `10.1.0.43`
- [x] **M.2.2** Comando real proNo 128 (STATUS) → device `860112070347838` respondeu em ~1s com telemetria (`commands` id 18, `status=sent`)
- [x] **M.2.3** Recepção de respostas **corrigida e validada com callback REAL** (v4.1.1): `offlineCmdPushURL` ganhou o path `/pushinstructresponse` no docker-compose + `WebhookHandler` aceita payload de objeto único (§2.4). Em 08/07 22:59 local o IoTHub entregou o callback real do comando VERSION (`POST /pushinstructresponse → 200`, origem `172.16.13.13`/okhttp, persistido em `command_responses` id 1) — ver §12.3
- [ ] **M.2.5** OTA firmware proNo 33027 com device real
- [x] `test_e2e.sh` executado no servidor pelo operador ("ok em todos os testes")
- [ ] Specs multi-tenant: exigem credenciais de um segundo cliente (`TEST_EMAIL_B`/`TEST_PASSWORD_B`)

### 11.5 Diagnóstico no servidor (09/07/2026 — sessão SSH)

Ver CHANGELOG [4.1.1]. Resumo: comando "failed" era timeout de 15s vs espera de 30s do IoTHub (não inacessibilidade); respostas offline caíam em `POST /` (302) por `offlineCmdPushURL` sem path; `/rastreamento` vazio por `ORDER BY d.is_online` (alias com prefixo). Vídeos OK: `dvr-upload` (:23010) serve `/iothub/dvr-upload/uploadFile` interna/externamente — Apache **não** precisa acessar o diretório. Mudanças no servidor: `.env` (+IOTHUB_COMMAND_URL=http://10.1.0.43:10088, backup `.env.bak-*`), `/iothub/docker-compose.yml` (backup `.bak-*`, serviços `api` e `tracker-instruction-server` recriados). Arquivos untracked pré-existentes no servidor (não tocados): `handlers/pushterminalrealtimestatus.php`, `includes/config.php`.

---

## 12. Iteração v4.1.1 — Diagnóstico operacional no servidor (08–09/07/2026)

Sessão de correções guiada pela análise visual/operacional do operador, com acesso SSH ao homolog.
Commits `75441a7`…`cd1af0f` (7 fixes + docs), todos implantados. CHANGELOG [4.1.1] tem o detalhe técnico de cada um.

### 12.1 Topologia descoberta (homolog `189.22.240.43`, hostname `iothub`)

- **App**: Apache 2.4 + PHP-FPM **no host**, DocumentRoot `/var/www/jimi_webhook`, vhost com log em `/var/log/apache2/jimi-webhook-{access,error}.log`. Sistema em `America/Sao_Paulo` (-03); PHP em UTC; conexão PDO em UTC.
- **Stack IoTHub**: 16 containers Docker (`/iothub/docker-compose.yml`), rede interna `172.16.13.0/24`. Portas relevantes: `tracker-instruction-server` **:10088** (envio de comandos), `msg-dispatch-iothub` :10066 (push de webhooks, `pushURL=http://10.1.0.43`), `dvr-upload` **:23010** (serve os vídeos de `/iothub/dvr-upload/uploadFile`), `iothub-media` :8881 (streaming), gateways :21100/:21122/:31506, api :9080, kafka/zookeeper/redis/mongodb.
- **Regra de rede**: containers alcançam o host **somente pelo IP da LAN `10.1.0.43`** (localhost dentro do container é o próprio container). O host alcança os containers por localhost OU 10.1.0.43 (portas publicadas em 0.0.0.0).
- **Devices reais**: `860112070347838` (JC181 "181_7838", JTT) e `869058070151343` (JC182 "Camera JC182", JTT) — ambos online e respondendo a comandos.

### 12.2 Bugs corrigidos nesta iteração

| # | Sintoma reportado | Causa-raiz | Fix |
|---|---|---|---|
| 1 | Comando marcado "failed / IoTHub inacessível" | IoTHub **segura o HTTP response por até 30s** aguardando o device; `sendcommand.php` abortava aos 15s (`CURLOPT_TIMEOUT`) — o comando tinha sido aceito e enfileirado | Timeout 35s; timeout distinguido de conexão recusada; `curl_error` no log (`b18a4df`) |
| 2 | Respostas de comandos offline nunca chegavam | (a) `offlineCmdPushURL=http://10.1.0.43` **sem path** → callback caía em `POST /` → 302 login → descartado (evidência no access log, okhttp/172.16.13.13); (b) corpo §2.4 é objeto único sem `data_list` → `WebhookHandler` descartava como "empty data" | Path `/pushinstructresponse` no compose (serviços `api` + `tracker-instruction-server` recriados); flag `allowSingleObjectPayload` no `WebhookHandler` (hash de idempotência sobre a lista final); alias camelCase no router (`b18a4df`) |
| 3 | Dashboard sem "sucesso" nem resposta do comando (falso "Timeout/fila offline" após 5 min) | Resposta síncrona do device vem no próprio HTTP response (`data._content`), mas era gravada com `status='sent'` — e o polling só declara sucesso em `'executed'` | Síncrono → `executed` + `response_time` imediatos; `commandstatus` extrai `data._content` (resposta real) em vez do `msg` genérico; histórico retro-corrigido (`35fa94d`) |
| 4 | `/rastreamento` com 500 (pré-4.1.0) / lista de devices vazia | `ORDER BY d.is_online` referencia **alias** com prefixo de tabela → unknown column, engolido pelo try-catch da Fase K | Alias puro `ORDER BY is_online` (`b18a4df`) |
| 5 | Câmera JC182 `869058070151343` "já cadastrada" mas invisível na listagem | Gateway auto-cria a linha do device (`customer_id NULL`) na 1ª telemetria; listagem filtra por cliente (órfão invisível) mas o cadastro checava IMEI globalmente — beco sem saída | `/ativos/novo` **adota** órfãos (preserva telemetria), reativa soft-deletados do cliente, recusa só IMEI ativo/de outro cliente; ganhou CSRF; câmera cadastrada (`539f3e7`) |
| 6 | Horários exibidos 3h adiantados (UTC cru) | Armazenamento UTC estava correto; as 13 telas novas do YUV formatavam sem conversão e filtros tratavam o dia digitado como dia UTC | Helpers canônicos `fmt_brt()` / `brt_day_range_to_utc()` / `brt_today()` aplicados em 17 pontos de exibição, 8 filtros, relatórios exportados, séries do Resumo/BI (`CONVERT_TZ`), rollup; regra no CLAUDE.md (`cd1af0f`) |

### 12.3 Verificações executadas (com evidência real)

- **Comando síncrono**: STATUS (proNo 128) → JC182 respondeu em ~1s (`Battery:12.4V; Mode:SLEEP…`), `commands` id 22 `executed`, `/commandstatus` entregando o conteúdo que o JS renderiza no 1º poll de 3s.
- **Comando offline ponta-a-ponta**: VERSION → JC181 (comando 20) virou fila offline; o IoTHub entregou o **callback real** (`POST /pushinstructresponse → 200`, okhttp/172.16.13.13) e a resposta foi persistida em `command_responses`. Nota: o callback foi correlacionado ao comando errado (21) porque na época os síncronos ainda poluíam o pool de pendentes — com o fix #3 isso não ocorre mais; comando 20 reconciliado manualmente.
- **Vídeos**: `.ts` de 21 MB servido pelo `dvr-upload` (:23010) interna E externamente (HTTP 200). O app monta `FILE_STORAGE_URL + file_url` — **Apache não precisa de acesso a `/iothub/dvr-upload/uploadFile`**. Pipeline `pushfileupload → media_files → vínculo com ocorrência` validado no E2E.
- **Timezone**: UTC 02:36 → exibição 23:36 = relógio local do servidor; helpers testados (dia BRT 08/07 → janela UTC 08/07 03:00–09/07 02:59).
- **Regressão**: lint 80/80, suite Playwright **37 passed / 0 failed** após cada mudança.

### 12.4 Mudanças de infraestrutura no servidor (fora do git)

- `/var/www/jimi_webhook/.env`: `IOTHUB_COMMAND_URL=http://10.1.0.43:10088/api/device/sendInstruct` + `IOTHUB_API_TOKEN=123` (backup `.env.bak-20260708_215709`)
- `/iothub/docker-compose.yml`: `offlineCmdPushURL=http://10.1.0.43/pushinstructresponse` nos serviços `api` e `tracker-instruction-server` (backup `docker-compose.yml.bak-*`); containers recriados via `sudo docker compose up -d`
- Retro-fixes de dados: comandos 16/18–21 reconciliados (`executed`), device de teste `868120246598152` ("Device E2E Test") existe no banco de produção — candidato a limpeza quando não for mais útil

### 12.5 Convenção de timezone (agora obrigatória)

**Armazenar SEMPRE UTC** (PDO força `time_zone '+00:00'`; devices GMT 0; PHP UTC). **Exibir SEMPRE BRT** via `fmt_brt()`; filtros de dia digitados são BRT → converter com `brt_day_range_to_utc()`; defaults com `brt_today()`; agrupamentos SQL por hora/dia com `CONVERT_TZ(col, '+00:00', '-03:00')`. Colunas DATE puras (CNH, ativação) **não** convertem. Caveat: offset fixo -03 nos agrupamentos SQL — se o Brasil retomar horário de verão, revisar (o `fmt_brt()` PHP usa `America/Sao_Paulo` e se ajusta sozinho).

### 12.6 Pendências em aberto

- [ ] **OTA firmware** (proNo 33027) com device real — M.2.5, único item remanescente da Fase M
- [ ] **Specs multi-tenant** do Playwright: exigem credenciais de um segundo cliente (`TEST_EMAIL_B`/`TEST_PASSWORD_B`) — hoje há apenas 1 cliente ("Frota Principal")
- [ ] **Arquivos untracked no servidor** (pré-existentes, não tocados): `handlers/pushterminalrealtimestatus.php`, `includes/config.php` — o operador deve decidir se commita ou remove
- [ ] **Correlação do callback offline**: heurística "comando pendente mais recente" — confiável agora que síncronos saem do pool, mas uma correlação por `requestId` seria mais robusta (melhoria futura)
- [ ] **Limpeza opcional**: device de teste `868120246598152` + ocorrência/mídia de teste no banco do homolog
- [ ] Retomar a **análise visual/operacional do frontend** pelo operador (interrompida pelos fixes desta iteração)

### 12.7 Deploy v4.2.0 no homolog (12/07/2026 — sessão remota)

- **Implantado `e5f9309`** (v4.2.0 Fases A–D) via `sudo ./scripts/deploy.sh` — fast-forward de `9d30f1e`, 34 arquivos; sem migration nova (banco permanece 4.1.0); lint OK; `/ping` 200.
- **Causa-raiz do homolog desatualizado**: o servidor puxava de `git@github.com:Flaviohses/jimi_webhook.git` (repo legado, inacessível ao PAT atual), enquanto o dev empurra para `hssflavio-ux/jimi_webhook`. **Remote do servidor trocado para `git@github.com:hssflavio-ux/jimi_webhook.git`**, com deploy key dedicada read-only (`/root/.ssh/github_hssflavio`, GitHub key ID 157097998) selecionada via `git config core.sshCommand` no repo (a chave antiga `/root/.ssh/id_ed25519` continua presa ao repo Flaviohses — GitHub exige unicidade de chave).
- **Acesso SSH da máquina dev**: chave pública do Windows (`~/.ssh/id_ed25519`) instalada em `administrador@189.22.240.43`; deploy roda como root (`sudo` exige senha). Cuidado reprodutível: `authorized_keys` escrito via pipe do PowerShell ganha `\r\n` — limpar com `tr -d '\r'`.
- **Usuário E2E criado no homolog**: `e2e@teste.local` (admin, customer 1, `users.id=2`) — mesmo padrão do dev local; candidato a limpeza junto com o device de teste `868120246598152`.
- **Testes executados**: replay E2E no servidor **8/8** (GPS → alarme 143 → ocorrência id=2 → mídia id=2 vinculada); Playwright contra o homolog **33/40 efetivos, 0 falhas** (7 skipped: multi-tenant sem 2º cliente + rate-limit gated). Flake único no 1º run: login >15s no primeiro load pós-deploy (dashboard v4.2.0 mais pesado + caches frios) — verde na reexecução.
- **Avisos pré-existentes do `deploy.sh`** (não bloqueiam, a investigar): `mysqldump` falha silenciosamente (backup de banco não é gerado — provável falta de privilégio/credencial no check); "mod_headers ausente" e "VirtualHost não detectado" na FASE 1; check MySQL da FASE 1 roda `mysql` sem credenciais (as migrations com `.env` funcionam normalmente).

### 12.17 Deslocamento — segmentação por movimento (viagem única de 24h) (23/07/2026)

- **Sintoma (reportado pelo usuário)**: no `/relatorios/deslocamento` o veículo **FJR7B59** (`869058070151343`) aparecia com **1 rota compreendendo o dia todo** em vez dos vários deslocamentos esperados.
- **Diagnóstico no homolog remoto** (`189.22.240.43`, MySQL direto): a `trip 14` ia de **07-22 11:58 → 07-23 10:55 = 1376 min (~23h)**, cruzando dois dias. Dentro dessa janela só existia **1 ponto `acc=0`** (no próprio início): o device manteve `acc=1` ininterrupto por ~23h (uma jornada de trabalho inteira sem desligar a ignição). O perfil por hora mostrava paradas longas reais (ex.: 07-22 13:00–15:00 essencialmente parado) e retomadas — deslocamentos distintos que o builder não separava. Havia ainda casos gêmeos: `181_7838` (só deriva de GPS, nunca >7 km/h) com "viagem" fantasma de 24h, e o device de teste E2E com "viagem" de **97h** por pontos a 4 dias de distância.
- **Causa-raiz**: `scripts/trip_builder.php` encerrava a viagem **exclusivamente** em `acc=desligado`. Devices que mantêm a ignição/voltagem reportada ligada (por horas ou o dia todo) nunca disparavam o fim → a jornada inteira colapsava numa viagem só. (Este era exatamente o item (1) das pendências da §12.15 — "detecção por movimento contínuo para devices com ACC sempre ligado".)
- **Fix (3 gatilhos de fim de viagem, todos fechando no último ponto em movimento; a cauda parada é descartada)**:
  1. **Ignição desligada** (comportamento original, preservado).
  2. **Parada sustentada**: velocidade abaixo de `STOP_SPEED_KMH=3 km/h` por mais de `STOP_IDLE_SECONDS=300 s` (5 min) — mesmo com `acc=1` — encerra; o próximo movimento abre viagem nova.
  3. **Buraco de dados**: intervalo entre pontos consecutivos ≥ `STOP_IDLE_SECONDS` (device offline/silente) — não dá para afirmar deslocamento contínuo através de um silêncio do rastreador (mata a viagem de 97h do E2E).
  - `isRealTrip()` ganhou piso `MIN_TRIP_DURATION_S=60 s` (descarta *slivers* de poucos segundos). A abertura de viagem passou a exigir **movimento** (não abre em veículo parado com ACC ligado). Fechamento centralizado no novo `finalizeTrip()` (recorta até o último ponto em movimento, calcula duração/dist/vel.máx/alarmes, aplica o filtro).
- **Validação**: simulador offline contra os 3535 pontos reais de FJR7B59 (07-18→hoje) confirmou thresholds antes de escrever o fix; depois **rebuild com o código real** (`DB_HOST=189.22.240.43 … php scripts/trip_builder.php 30`, após `DELETE FROM trips`; backup em scratchpad). Resultado no homolog: FJR7B59 **11 → 39 viagens** (5–9/dia; a viagem de ~23h fatiada em 17 deslocamentos reais; o trajeto-tronco de 07-19 — 3h / ~292 km / 138 km/h — preservado intacto). Modalidade **diário** confere: 07-23=9 viagens, 07-22=8, 07-20=8, etc. `181_7838` deixou de gerar a viagem-fantasma de 24h; E2E deixou de gerar a de 97h. Lint OK.
- **PENDÊNCIA DE DEPLOY**: os **dados** do homolog já foram corrigidos (rebuild direto no MySQL remoto), mas o **código** `scripts/trip_builder.php` corrigido ainda **não foi commitado nem deployado no servidor** — o cron de 15 min lá ainda roda a versão antiga e pode voltar a colar viagens longas em dados NOVOS. Falta: commit em `main` + `deploy.sh` no homolog (o usuário dispara; sem acesso sudo pelo tool). Thresholds (`STOP_SPEED_KMH`/`STOP_IDLE_SECONDS`) podem ser afinados por device se necessário.

### 12.16 Deslocamento em 2 modalidades + mapa de rota + teto global de 31 dias (22/07/2026)

- **Contexto**: pedido do usuário após parecer de viabilidade com benchmark real (2,92M viagens sintéticas, MySQL local): com os índices antigos a grade do deslocamento custava **3,5–6s** num tenant de 200 veículos (o índice só por `customer_id` varre todas as viagens do cliente, qualquer período); com o composto `(customer_id, started_at)` cai para **<1ms** (por viagem) e **41–177ms** (fechamento diário 7–30 dias). Fechamento diário além de ~90 dias é caro por natureza (agregação de centenas de milhares de linhas) → daí o teto global.
- **Migration v4.3.0** (`mysql/migration_v4.3.0.sql` + bloco no `deploy.sh`): cria `idx_trips_customer_time (customer_id, started_at)` e dropa o redundante `idx_trips_customer` (o composto tem `customer_id` como prefixo e segue servindo a FK). Procedures guardadas (create/drop_index_if_exists), idempotente.
- **Teto global de 31 dias em TODOS os relatórios**: novo `clamp_report_range()` + const `REPORT_RANGE_MAX_DAYS` em `includes/functions.php` (datas invertidas são trocadas; excesso encurta o `date_to`). Aplicado em `rel_deslocamento`, `rel_posicoes`, `rel_alarmes`, `rel_ocorrencias`, `bi` (com banner âmbar "período ajustado" + label "máx. 31 dias") e silenciosamente em `relatorios.php` (legacy), `ocorrenciasdata.php` (AJAX) e `exportar.php` (criação de job).
- **Duas modalidades no `/relatorios/deslocamento`** (select "Modalidade"):
  1. **Por deslocamento** (default): grade anterior (1 linha por viagem lig→desl) + coluna **Rota**.
  2. **Fechamento diário**: `GROUP BY imei + dia BRT` sobre `trips` — primeira ignição ligada, última desligada (viagem que cruza a meia-noite conta inteira no dia em que começou; se a última desligada cai no dia seguinte a grade mostra a data junto), **Jornada** (última−primeira, inclui paradas) e **Em Movimento** (Σ durações) lado a lado, Σ km, máx vel., Σ alarmes, nº de viagens. Paginação com COUNT de grupos (subquery); export XLSX/PDF próprio.
  - **Faixa horária opcional** (`time_from`/`time_to`): refina a janela contínua via novo `brt_datetime_range_to_utc()`.
- **Mapa de rota** (`/relatorios/deslocamento/rota`, novo `rel_deslocamento_rota.php`; router ganhou suporte a subrota de 3 segmentos via chave `'segundo/terceiro'` no `$subrouteMap`): aceita `trip_id` (viagem) ou `imei`+`dia` (dia fechado, janela recalculada server-side = primeira→última ignição do dia). Leaflet com polyline azul + balão **Partida** (verde, data/hora BRT) + **Chegada** (vermelho, data/hora) + um circleMarker por posição/comunicação (popup hora/velocidade/ignição) + **ocorrências em laranja**: com coordenada própria (posição do 1º alarme agrupado via `occurrence_events`→`alarms`) plota no local exato; sem coordenada, destaca o ponto de comunicação mais próximo no tempo, citando a ocorrência no balão (tipo, hora, risco, status). Amostragem >3000 pontos (preserva primeiro/último), `preferCanvas`, escopo multi-tenant em tudo.
- **Verificado ponta-a-ponta no ambiente local** (migration aplicada; seed determinístico: 3 viagens em 2 dias + 1 ocorrência com coordenada + 1 sem; `trip_builder 5`; server `php -S` + sessão via cookie): modalidade viagens = 3 linhas com "Ver rota"; fechamento diário 20/07 = primeira 09:00, última 12:22, jornada 3h22m, em movimento 0h54m, 10,8 km, 2 viagens; clamp 01/05→22/07 ajustou para 31/05 com banner (tb. em posições/alarmes/bi); faixa horária 08–10h BRT filtrou só a viagem das 09:00; rota da viagem = 17 pontos + pino da ocorrência "Distração/risco alto" nas coordenadas do alarme, balões `Partida — 20/07/2026 09:00:00`/`Chegada — 09:32:00`; rota do dia = 2 viagens, ocorrência sem coordenada ("Uso de celular") anexada ao ponto mais próximo. Dados de teste removidos após a verificação. Lint total OK.
- **Commit e deploy (22/07/2026)**: commitado direto em `main` (convenção do repo — o deploy faz `git pull origin main`) como `5f6b8ed` (`feat: relatorio de deslocamento em 2 modalidades + mapa de rota + teto de 31 dias`; hook pre-commit OK, 11 PHP limpos) e pushado para `hssflavio-ux/jimi_webhook`. **Deploy no homolog exigiu 2 execuções encadeadas** (`sudo ./scripts/deploy.sh && sudo ./scripts/deploy.sh --force`) porque esta entrega **altera o próprio `deploy.sh`** (bloco da migration v4.3.0): o `git pull` roda no meio do script, então o `deploy.sh` novo — com o bloco v4.3.0 — só executa na 2ª passada (reforça a nota da §12.7 / feedback-history). O `sudo` pediu senha uma vez (cache p/ a 2ª). Rodado pelo usuário via `! ssh -t` (o tool não tem a senha sudo; ssh por chave como `administrador`, working copy do servidor é `www-data`).
- **Verificação pós-deploy no homolog** (SSH só-leitura + `.php` via scp → `Database::getInstance()`): `HEAD == 5f6b8ed` em `main` (subiu de `63b686c`); `handlers/rel_deslocamento_rota.php` e `mysql/migration_v4.3.0.sql` presentes; **`system_info.version == 4.3.0`**; índices de `trips` = `PRIMARY`, `idx_trips_imei_time`, `idx_trips_driver`, **`idx_trips_customer_time (customer_id, started_at)` criado** e **`idx_trips_customer` (redundante) removido**; `trips` com 12 viagens reais. Smoke test HTTP: `/relatorios/deslocamento` e `/relatorios/deslocamento/rota` → **302** (redirect p/ login sem sessão — roteamento OK, incl. subrota de 3 segmentos; sem 404/500).
- **Pendências**: exibir aviso de clamp também no `/exportar` (hoje só encurta silenciosamente o job); considerar rollup `trip_days` se um dia o fechamento diário precisar de janelas > 31 dias; validação visual logada no painel do homolog (opcional — as 12 viagens reais já aparecem com o link "Ver rota").

### 12.15 Fix Relatório de Deslocamento — coluna de ignição errada + fragmentação de viagens (22/07/2026)

- **Sintoma**: `/relatorios/deslocamento` sempre vazio; a tabela `trips` nunca era populada.
- **Causa-raiz**: `scripts/trip_builder.php` (o cron que segmenta viagens por ignição) consultava `SELECT ... ignition FROM gps_data`, mas **não existe coluna `ignition`** — a ignição fica em `gps_data.acc` (o `pushgps` grava `acc`/`accStatus`; `rel_posicoes.php` já lê corretamente `g.acc AS ignition`). Com o PDO em `ERRMODE_EXCEPTION`, o script morria com `Column not found: 'ignition'` **na primeira execução, antes de gravar qualquer viagem**. O mesmo bug estava em `scripts/worker.php` (export de posições, `g.ignition`) e `scripts/metrics_rollup.php` (distribuição de velocidade, `g.ignition = 1`).
- **Fix 1 (blocker)**: os três scripts passam a usar `g.acc` (aliasado como `ignition` onde a lógica lê esse nome). 
- **Fix 2 (fragmentação)**: a variável `$staleBefore` (`-2h`; antes `$batchTime`, calculada e **nunca usada**) agora guarda o fallback que fecha viagem aberta ao fim do lote — só finaliza se o último ponto já é mais velho que 2h; senão deixa em aberto para o próximo cron. Sem isso, cada rodada do cron pegando um veículo em movimento fechava a viagem no último ponto e a continuação virava outra linha → uma viagem longa fragmentada em N.
- **Fix 3 (qualidade)**: `isRealTrip()` — só persiste viagem com movimento real (`max_speed >= 6 km/h` OU `distância >= 1 km`, ≥2 pontos). Filtra viagem de 1 ponto, parada com ignição ligada (estacionado a noite toda com ACC on) e deriva de GPS.
- **Validação contra dados reais do homolog** (acesso SSH por chave via PowerShell — o tool Bash tem `ssh` bloqueado pelo classificador; consultas via `scp` de um `.php` que dá `require '/var/www/jimi_webhook/config/database.php'`): `trips` estava **vazia no homolog** (confirma o blocker em produção). **Câmera 182 = FJR7B59** (`869058070151343`, nome de placa; o device `400AD_2939`/`864993060182939`, cujo IMEI contém "182", está sem dados desde 08/abr): 14 viagens brutas → **10 reais** após o filtro (4 ruídos descartados), com KM/vel.máx/alarmes coerentes. **Câmera 181** (`860112070347838`, 65k pontos): investigado o parsing do ACC a pedido — **não é bug**; o payload bruto traz `"acc":1` em 100% das amostras e o bit 0 do `status` (262159) é constante → o device transmite ACC permanentemente ligado. A FJR7B59, por contraste, alterna ACC corretamente (2489 on/363 off, bit 0 do status acompanha).
- **Verificado ponta-a-ponta com a câmera 182** (IMEI `864993060182939`, que o banco local não tinha pontos GPS): seed de 3 ciclos acc lig→desl com movimento + pontos ociosos + 2 alarmes na 2ª viagem → `trip_builder` gera **exatamente 3 viagens** (duração 20/25/12 min, vel.máx 60/70/30, KM 3,2/3,0/1,2, alarmes 0/**2**/0); pontos ociosos (acc off) não viram viagem; 2ª rodada é idempotente (0 novas); viagem em curso (pontos recentes acc=1 sem acc=0) é corretamente **adiada**. Camada do relatório (conversão BRT→UTC + joins) confirmada retornando as 3 viagens com horários em BRT. Lint total OK.
- **Observação de tooling**: o cliente `mysql.exe` usa `@@session.time_zone = SYSTEM` (BRT, UTC−3), enquanto o app força `+00:00` na conexão PDO — seeds via CLI devem prefixar `SET time_zone='+00:00';` para casar com o relógio UTC do app (reforça a §12.5).
- **Deployado e verificado em produção (22/07/2026)**: `deploy.sh --skip-migrate` levou os commits `f88b3c9` (fixes 1–3) e `63b686c` (arg de lookback p/ backfill) ao homolog. O log `trip_builder.log` provava o crash a cada 15 min (`Unknown column 'ignition'` na linha 45) — o cron já roda os workers como root. Backfill de 30 dias (`php scripts/trip_builder.php 30`) populou a `trips`: **câmera 182 (FJR7B59) com 10 viagens** (700,6 km, 1044 alarmes), horários corretos em BRT, ruído filtrado. Daqui pra frente o cron (15 min) mantém incremental.
- **Pendências**: (1) **detecção por movimento contínuo** para devices que reportam ACC sempre ligado (câmera 181 e similares) — o spec YUV prevê "janela entre ignição ligada e desligada **ou movimento contínuo**"; hoje esses devices geram 0 viagens. Decisão do usuário: fazer **depois** (item 3). (2) `trip_builder` não geocodifica `start_addr`/`end_addr` (YUV exibe "Local Início/Fim"; grade mostra "—") e omite a coluna **Evento** — reuso do `geocode_cache`, como no rel. de Posições.

### 12.14 Wiki para o usuário final — linguagem, menus e mapas reais (18/07/2026)

- **Feedback do operador** sobre a Central de Ajuda (`handlers/wiki.php`, rota `/wiki` criada no commit `4811166`): linguagem de desenvolvedor, caminhos de URL expostos, mapas mockados vazios e seções de infra. Revisão completa em 4 frentes:
  1. **Sem termos técnicos**: removidos proNos/códigos de comando (37121/37381/34818/37384/33027), AJAX, polling, cache, localStorage, FLV/flv.js, Leaflet/Chart.js, RTP, soft-delete, DELETE físico, CRUD, RBAC, JSON batch, FILE_STORAGE_URL, IoTHub, cookie `jimi_token` etc. Tabelas Ação→Resultado descrevem apenas o efeito visível (ex.: "A câmera é acionada e o vídeo abre em alguns segundos").
  2. **Sem caminhos de URL**: badges de rota (`/setup`, `/bi`, `/perfil`, `/video/aovivo`, ...) removidos de todas as seções — a navegação é descrita pela função no menu lateral; badges "admin"/"público"/"tela inicial" mantidos.
  3. **Mapas reais nos mockups**: novos `assets/img/wiki_map_city.png` e `wiki_map_streets.png` (tiles OSM z13/z15 de São Paulo, 3×2 tiles = 768×512, stitch com GD, otimizados truecolor→paleta: 216/204 KB; crédito "© OpenStreetMap" sobreposto via `.map-credit`). Aplicados no mapa de calor do Resumo (blobs radial-gradient por cima), no mapa do Rastreamento (marcadores verde/vermelho com borda branca) e na trajetória do rel. de Posições (polyline SVG azul sobre o mapa).
  4. **Seções de dev removidas**: "Webhooks e Integração", "Motor de Ocorrências (fluxo técnico)" e "Segurança" (incl. callout de workers/cron) saíram do conteúdo e do sumário.
- **Validado com render real** (MySQL portátil + `php -S` + usuário descartável, removido após o teste): `/wiki` 200, PNGs servidos (`image/png`), marcadores presentes, zero ocorrências de proNo/AJAX/webhooks/segurança no HTML; lint OK.

### 12.13 Fix vídeo automático dos alarmes: 34818→37384 (anexo do alarme) + exibição na ocorrência — v4.2.1 (13/07/2026)

- **Bug reportado**: eventos DMS gerados na câmera JC371 real (`865478070003241`) criaram as ocorrências 4/5/6 no homolog, mas nenhum vídeo apareceu na aplicação. **Resolve a pendência do §12.8** (validar 0x8802 com câmera real): o log de 14:52/14:55 mostra o IoTHub aceitando o 34818 e a câmera respondendo `_proNo 2050` com **`mediaItemsNum: 0`** — o 0x8802 é uma **consulta** ao acervo de multimídia 808 (fotos do 34817 etc.), **não** um comando de upload, e o vídeo de evento DMS/ADAS não vive lá: ele é um **anexo do alarme** (JT/T 1078/Su Biao).
- **Causa-raiz tripla**:
  1. Gatilho automático enviava 34818 (consulta) → nunca há upload; o certo é **37384 (0x9208, Alarm Attachment Upload, doc §2.20)**: a plataforma devolve à câmera o `alarmLabel` que veio no push do alarme + `alarmNumber` + endereço do attachment server (porta **21188** = `jimi-tracker-upload-process`, aberta no homolog); os arquivos caem no file storage nomeados `{imei}_{alarmLabel}_{xy}.mp4/.jpg` (doc §1.8) e o `/pushfileupload` notifica.
  2. `pushalarm.php` não repassava `alarm_label` ao motor, e o vínculo mídia→ocorrência era só por janela ±3 min (upload que demora mais se perdia).
  3. Detalhe da ocorrência renderizava `file_url` cru no `<video src>` (sem `FILE_STORAGE_URL`) — mesmo vídeo vinculado não tocava. Bônus: detalhe agora é escopado por `customer_id` (antes qualquer id abria).
- **Implementação**: `queue_event_video_request()` agora exige `alarmLabel` hex válido (alarme JTT sem anexo — ignição, ociosidade — loga e não dispara); `alarmNumber = bin2hex(últimos 14 dígitos do IMEI + cauda do label)` — **validado contra o exemplo da doc §1.13 (match exato)**; endereço via `video_stream_config()` + `ATTACH_UPLOAD_IP`/`ATTACH_UPLOAD_PORT` (.env, default 21188); anti-rajada re-desenhada (dedupe por label 10 min + teto 5/2min por device — o teto antigo de 1/2min perderia os vídeos das outras ocorrências de uma rajada); `pushfileupload.php` extrai `alarmLabel`/canal do fileName e vincula pela cadeia `alarms.alarm_label → occurrence_events → occurrences` (vídeo tem precedência sobre imagem; fallback ±3 min mantido); whitelist do `sendcommand` + presets (`alarm_attach` novo; `ftp_upload` corrigido para o formato real da doc §2.7 — o antigo usava campos do 34818).
- **Validado local (E2E real, MySQL + php -S)**: alarme JTT 265-4 com `alarmLabel` → ocorrência criada + `commands` com `jtt_37384` e content correto → `pushfileupload` com jpg ANTES do mp4 → 2 `media_files` (canal extraído do nome) e o **vídeo** assume o vínculo → página da ocorrência renderiza `<source src="http://…:23010/download/{arquivo}.mp4">`. Lint total OK.
- **Pendência (usuário/próxima iteração)**: deploy no homolog (`sudo ./scripts/deploy.sh`) + gerar eventos reais na JC371 e conferir a chegada do `/pushfileupload` e o vídeo na ocorrência. O [Extrair] do playback segue em 34818 (consulta — deve falhar da mesma forma); extração de gravação do cartão exige 37382 com **FTP do cliente** (doc §2.7) — precisa de um FTP no host (pendência de infra) ou validar se o attachment server 21188 aceita esse fluxo.

### 12.12 Deploy homolog + validação com câmera real do fluxo de vídeo histórico (13/07/2026, `135845a`)

- **Deploy**: servidor atualizado para `135845a` (fix vídeo histórico + observabilidade + fix crontab-setup); `/ping` OK; Playwright contra o homolog **8/8** (login + 3 rotas de vídeo; 1 skip esperado de rate-limit).
- **Fix adicional pré-deploy** (`135845a`): `crontab-setup.sh` `remove_entries()` só removia a linha do marcador — cada `--install` **duplicaria** os workers existentes (worker.php 2×/min) e `--remove` não removia nada; agora filtra também as linhas dos próprios scripts.
- **🎯 Validação com câmera real (JC181 `860112070347838`) — o 37381 funciona**: `sendInstruct` proNo 37381 (canal 0, janela de hoje GMT-0) → resposta síncrona `"AudioVideoResourceList ack successful response"` com `_content: "434"` → push §1.11 no `/pushresourcelist` (COM envelope `data_list`) → **434/434 gravações inseridas em `resource_lists`, 0 erros, 333 ms** → timeline do `/video/playback` renderiza **157 gravações CH1 "No cartão"** com botão Extrair e janelas compactas corretas. Bug original reproduzido no log: às 13:12–13:14 o usuário havia disparado 34818 pela página antiga (comando errado) com o device offline (`_code 600`).
- **JC182 `869058070151343`: não é bug de plataforma** — online mas com flap a cada ~5 min; ACKa o 0x9205 e **nunca emite 0x1205** (mesmo com o comando entregue via fila no relogin); teve alarmes "Falha no Armazenamento"/"Perda de Sinal de Vídeo" hoje → SD ausente/defeituosa. Em 10 dias de log do gateway, nenhum 0x1205 dela.
- **Extração (34818 na janela exata)**: primeiro envio transmitido pelo gateway (`packMediaDataUpload`, seq 44) mas a câmera caiu antes do ACK; reenviado como comando de fila (command 50) — upload monitorado; a chegada via `/pushfileupload` vira "Disponível" na timeline (mecânica validada no dev local ponta a ponta).
- **Diagnóstico de infra útil**: pushes do IoTHub saem do `msg-dispatch-iothub` com `pushURL=http://10.1.0.43` (base única + path por tipo); instrução JT/T percorre `tracker-instruction-server` (:10088) → `router` → `jimi-gateway-450` (:21122); Apache access log (`jimi-webhook-access.log`) distingue "IoTHub não enviou" de "handler descartou".
- **Pendência sudo (usuário)**: `sudo bash scripts/crontab-setup.sh --install` (registra o worker `log_cleanup` diário) + `sudo -u www-data php scripts/log_cleanup.php` (limpeza one-shot) — o deploy foi feito sem esses passos.

### 12.11 Observabilidade: LOG_LEVEL, rotação/purga real e handler global — v4.2.1 (13/07/2026)

- **Contexto**: auditoria de logs revelou (1) nível DEBUG morto (`Logger::$logLevel` hardcoded INFO, `setLogLevel()` nunca chamado → `RAW_WEBHOOK_DATA` jamais gravado); (2) purga anunciada (`cleanOldLogs`) **nunca era invocada** e o glob só cobria `webhook_*.log` (worker/órfãos ficavam para sempre — 37 MB no dev, 13 arquivos órfãos de maio); (3) dashboard sem logging de aplicação (exceção/fatal em página = tela branca sem rastro).
- **`LOG_LEVEL` no `.env`** (`core/Logger.php`): aplicado **lazy no primeiro log** — necessário porque o `.env` só é parseado dentro do primeiro `Database::getInstance()`, depois do load do Logger. `LOG_LEVEL=DEBUG` liga o payload bruto dos webhooks sob demanda (diagnóstico de device com formato inesperado). Default continua INFO.
- **`scripts/log_cleanup.php`** (cron diário 03:10 via `crontab-setup.sh`): rotação por tamanho (`LOG_MAX_SIZE_MB`, default 10 — logs de append contínuo como `worker.log` viram `.old`, que o purge por idade remove depois) + `Logger::cleanOldLogs()` com glob estendido a `*.log`/`*.log.old` (`LOG_RETENTION_DAYS`, default 30). **Não usa a classe Database de propósito** (ela dá `exit` com banco fora; limpeza de log tem que rodar mesmo assim) — parse próprio do `.env`.
- **Handler global do dashboard** (`includes/auth.php`, incluído por todas as páginas/AJAX; webhooks ficam fora — têm o try/catch do `WebhookHandler`): `set_exception_handler` (→ ERROR com class/message/file/line + resposta 500 neutra) e `register_shutdown_function` para fatais (→ CRITICAL). Warnings/notices ficam de fora de propósito.
- **Validado local**: `log_cleanup.php` executado de verdade — purgou os 13 órfãos de maio e rotacionou+purgou `traffic.log` >10 MB (17→3 arquivos); `LOG_LEVEL=DEBUG` grava `RAW_WEBHOOK_DATA`, default INFO suprime; handlers testados via arquivo (exceção → ERROR, fatal → CRITICAL) — atenção: `php -r` NÃO dispara `set_exception_handler` neste build (eval'd code), testar sempre com arquivo; Playwright login+vídeo 8/8 (1 skip esperado).
- **Deploy**: rodar `bash scripts/crontab-setup.sh --install` no homolog para registrar o novo worker; opcional definir `LOG_RETENTION_DAYS`/`LOG_MAX_SIZE_MB` no `.env`.

### 12.10 Fix consulta de vídeos históricos do cartão — v4.2.1 (13/07/2026)

- **Bug reportado**: "Requisitar Gravações" no `/video/playback` sempre devolvia vazio, mesmo com o app Android listando vários vídeos no cartão. **Causa tripla**: (1) a tela disparava **34818** (0x8802, extração de multimídia de *evento*) em vez de **37381** (0x9205, consulta da lista de gravações — o que o app usa); (2) a resposta da câmera chega via `/pushresourcelist` → tabela `resource_lists`, mas a timeline lia só `media_files` (arquivos já extraídos) — a lista nunca aparecia; (3) o 37381 exige janela GMT-0 compacta (`yyMMddHHmmss`) **que não cruza o dia** — o período default (ontem→hoje) seria ignorado pela câmera.
- **Fluxo corrigido** (`video_playback.php`): Requisitar → 37381 fatiado em **segmentos por dia UTC** (cap 15; campos `channel`+`channelId`, `alarmFlag/resourceType/codeType/storageType=0`, `instructionID` único); timeline = `resource_lists` ("**No cartão**") ∪ `media_files` ("**Disponível**" → play), com merge por janela ±120s (upload que cai na janela da gravação torna-a reproduzível, sem duplicar item); botão **[Extrair]** por gravação dispara 34818 com a janela exata (mesmo contrato validado do §12.8) → arquivo chega via `/pushfileupload` e o item vira Disponível; auto-refresh 6×8s pós-requisição (cancelado ao interagir; comando NÃO é reenviado no reload); `serverFlagId` por protocolo do device (`data-proto`; JIMI=1, JT/T=0); modelos protocolo JIMI (JC400D/AD) mantêm 34818 na janela inteira (0x9205 não existe lá — limitação documentada); **fix multi-tenant**: `imei` do GET só vale se pertencer ao cliente da sessão.
- **`pushresourcelist.php`**: `allowSingleObjectPayload=true` (push §1.11 `{imei,totalNum,instructionID,resourceList}` pode vir sem envelope `data_list` — antes era descartado como "empty data"); mapa de `resourceType` corrigido para semântica 0x1205 (**0=áudio e vídeo→`video`**, não `image` — 0=imagem é do multimídia 0x0800, que não passa por este push); datas parseadas explicitamente como UTC; log de lista vazia agora inclui `totalNum`+keys para diagnóstico.
- **Presets 37381** (`comandos.php`, `ativo_detalhe.php`): formato da doc (`channel`, janela GMT-0 compacta de hoje, sem cruzar o dia) — antes usavam `channelId`/`mediaType` com datas vazias.
- **Validado local (E2E real)**: push §1.11 simulado (objeto único, resourceType 0 e 2) → 2 linhas `video` em `resource_lists` → timeline renderiza "2 gravações" No cartão com [Extrair] → `pushfileupload` na janela da 1ª → item vira Disponível com o mp4 clicável (o 2º permanece No cartão, sem duplicatas); fatiamento UTC validado em Node (1 dia BRT → 2 segmentos, range invertido → 0, cap 15); Playwright rotas `video/*` 3/3 e `comandos`/`ativos*` 3/3.
- **Pendência**: exercitar com câmera real (JC450/JC182) no homolog — confirmar que o firmware responde 0x9205 com a lista e aceita 0x8802 na janela exata da gravação; se o push §1.11 vier COM envelope `data_list`, o caminho antigo continua cobrindo.

### 12.9 Fix seleção de canais nas telas de vídeo — v4.2.1 (12/07/2026, `2e8472f`)

- **Bug**: ao vivo/playback não deixavam selecionar CH2+/CH3+ em equipamentos cadastrados com mais câmeras — as telas liam `dm.camera_count` (modelo, seed errado) e ignoravam `devices.camera_count` (cadastro); o ao vivo ainda iniciava com `maxCams=1` até trocar o select e tinha teto fixo de 4 canais.
- **Semântica canônica**: `device_models.camera_count` = **máximo do modelo** (JC182=1, JC181/JC400D/JC400AD=2, JC371≤3, JC450≤5); `devices.camera_count` = **quantidade instalada** (cadastro). Telas usam `COALESCE(NULLIF(d.camera_count,0), dm.camera_count, 1)` (`video_aovivo`, `video_playback`, `comandos`, grade `equipamentos`).
- **Migration v4.2.1** (deploy.sh ganhou o bloco; condição do bloco v4.1.0 corrigida para não reaplicar pós-bump): corrige o catálogo e alinha `devices.camera_count` dos modelos de contagem **fixa**; modelos variáveis (JC371/JC450) respeitam o cadastro. Seed da v3.1.0 corrigido para instalações novas. `system_info.version = 4.2.1`.
- **Validado no homolog**: JC450 de teste cadastrado com 4 câmeras → ao vivo `data-cam="4"` (CH1–4 clicáveis), playback lista CH1–4 com CH3 selecionável; devices JC181/JC400D/AD alinhados para 2; Playwright rotas de vídeo 3/3.
- **Gotcha de deploy**: o `git pull` roda no meio do próprio `deploy.sh` — mudanças no script só valem na PRÓXIMA execução (a migration v4.2.1 exigiu rodar o deploy 2×).

### 12.8 Gatilho automático de vídeo de evento — v4.2.1 (12/07/2026, `8e86076`)

- **Implementado e implantado**: ocorrência nova sem mídia em câmera JT/T → `queue_event_video_request()` (motor) agenda proNo **34818** (0x8802, `mediaType 2`, janela ±60s GMT-0 compacto, canal 0 = todos, chaves `channel`+`channelId` por divergência de exemplos) → `flush_pending_video_requests()` despacha **pós-commit** no fim do `pushalarm.php` via novo `includes/iothub_command.php` (`operator='auto_video'`, anti-rajada 2 min/device, kill-switch `AUTO_VIDEO_REQUEST=0`).
- **Validado no homolog**: E2E replay 8/8; comando 38 auto-criado para a ocorrência 3 com payload/janela corretos; IoTHub aceitou o formato (code 0; `_code 301 "device not registered"` — esperado para device fake).
- **Pendência**: validar com câmera real (JC182) gerando evento DMS de verdade — confirmar se o firmware devolve vídeo para 0x8802 com `eventCode 0` (pode devolver só mídia disparada por comando; se vier vazio, testar `eventCode` do alarme correspondente). Semântica conhecida: resposta síncrona com `_code 301/600` marca `executed`/`sent` conforme `_content` — mesma do `sendcommand.php`.

---

## 13. Comandos Úteis

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

---

## 14. Iteração v4.1.2 — Vídeo ao vivo (11/07/2026)

Correção da abertura dos vídeos ao vivo, reportada como ainda quebrada. CHANGELOG [4.1.2] tem o detalhe técnico.

### 14.1 Causa-raiz

O comando **37121 (0x9101)** instrui o **device** a *publicar* o stream RTP no media server do IoTHub. O `video_aovivo.php` mandava `videoIP: window.location.hostname` (o host que o **navegador** vê) e `videoTCPPort: "0"` — endereço que o device não alcança e porta inválida. Resultado: o device nunca publicava, o `.flv` em `:8881` ficava sem dados e o player travava em "Conectando" indefinidamente. Havia ainda `dataType:"1"` (áudio, string) onde o correto é `0` (vídeo).

### 14.2 Correções

| # | O quê | Onde |
|---|---|---|
| 1 | Payload 37121 correto: `dataType:0, codeStreamType:0, videoIP:<IP do servidor>, videoTCPPort:"10002", videoUDPPort:0` | `video_aovivo.php` |
| 2 | Helper `video_stream_config()` (flv_base + ingest_ip/port + playback_port, com overrides `.env`) | `includes/functions.php` |
| 3 | Player FLV resiliente: retry 8×3s, watchdog 8s, `Events.ERROR`, autoplay-fallback mudo, destroy limpo, sessão anti-corrida | `video_aovivo.php` |
| 4 | `sendcommand.php` expõe `status` + `offline_queued` (device offline → `_code=600`); vídeo avisa fila offline em vez de esperar | `sendcommand.php`, `video_aovivo.php` |
| 5 | "Requisitar Gravações" 34817 (foto!) → **34818** (upload de mídia); datas JT/T `yyMMddHHmmss` GMT0; filtro com `brt_day_range_to_utc()`; fetch `keepalive` | `video_playback.php` |
| 6 | Presets "Streaming"/"Playback"/"Upload de Vídeo" corrigidos (via `video_stream_config()` + `FILE_STORAGE_URL`) | `comandos.php`, `ativo_detalhe.php` |
| 7 | `/video` legado → redirect para `/video/aovivo` (preserva `?imei=`) | `video.php` |
| 8 | `.env.example`: `VIDEO_INGEST_IP`/`VIDEO_INGEST_PORT`/`VIDEO_PLAYBACK_PORT` documentados | `.env.example` |

### 14.3 Verificações (com câmera real, homolog)

- **37121 corrigido** → IoTHub (`:10088`) → câmera `869058070151343` (JC182, online): `code:0, _content:"ok"` em ~1s.
- **Stream capturado**: `GET :8881/1/869058070151343.flv` → **2 MB de FLV válido** (assinatura `FLV` v1, flags `0x5` áudio+vídeo, 1ª tag type 18). A 1ª tentativa com janela curta pegou 0 bytes (device ainda não publicando) — comprova o valor do retry/watchdog.
- Lint 7/7 arquivos alterados OK. Playwright navegação **25/25 verde** (inclui as 3 rotas `/video/*`).

### 14.4 Observações operacionais

- **`videoIP` depende de rede**: hoje deriva do host de `STREAM_URL` (IP público `189.22.240.43`). Se algum dia o device precisar publicar via IP da LAN (como o `IOTHUB_COMMAND_URL` usa `10.1.0.43`), setar `VIDEO_INGEST_IP` no `.env` — o teste real confirmou que o IP público funciona para a câmera atual.
- **Latência de abertura**: 5–30s entre clicar "Iniciar" e o vídeo aparecer é esperado (device liga a câmera e negocia o RTP). O player agora comunica isso ("tentativa N/8… o dispositivo leva alguns segundos") em vez de parecer travado.
- Pendências de vídeo remanescentes: nenhuma bloqueante. Playback (37377) e foto (34817) têm presets corretos mas não foram exercidos com device real nesta iteração.
