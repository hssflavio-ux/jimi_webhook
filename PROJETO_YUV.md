# PROJETO YUV PARITY — Blueprint de Implementação (v4.0.0)

> **Objetivo**: transformar o `jimi_webhook` em uma **cópia fiel da plataforma YUV** (`app.yuv.com.br`), preservando o gateway de webhooks Jimi que já temos e reconstruindo o dashboard/produto para replicar a arquitetura de informação, o design, as regras de negócio e a dinâmica do YUV.
>
> Este documento é o **contrato de implementação**. Ele detalha passos, dinâmicas, funcionalidades, layout, modelo de dados e critérios de aceite de cada tela. A análise visual de origem está em [`analise_yuv/analise_yuv.html`](analise_yuv/analise_yuv.html) (22 telas + 6 modais, com screenshots).
>
> **Leia também**: [`DESIGN.md`](DESIGN.md) (design system YUV), [`AGENTS.md`](AGENTS.md) e [`CLAUDE.md`](CLAUDE.md) (arquitetura/rotas), [`STATUS.md`](STATUS.md) (status vivo + backlog de segurança herdado).

---

## Sumário

1. [Visão e escopo](#1-visão-e-escopo)
2. [Conceito de produto e modelo de negócio](#2-conceito-de-produto-e-modelo-de-negócio)
3. [Arquitetura-alvo](#3-arquitetura-alvo)
4. [Mapa de rotas alvo (IA de navegação)](#4-mapa-de-rotas-alvo)
5. [Design system e layout](#5-design-system-e-layout)
6. [Modelo de dados (migração v4.0.0)](#6-modelo-de-dados)
7. [O motor de ocorrências (núcleo do produto)](#7-o-motor-de-ocorrências)
8. [Especificação módulo a módulo (22 telas)](#8-especificação-módulo-a-módulo)
9. [Padrões transversais](#9-padrões-transversais)
10. [Roadmap por fases](#10-roadmap-por-fases)
11. [Critérios de aceite globais](#11-critérios-de-aceite-globais)
12. [Plano de verificação](#12-plano-de-verificação)
13. [Riscos e dívidas herdadas](#13-riscos-e-dívidas-herdadas)

---

## 1. Visão e escopo

### 1.1 O que estamos construindo
Uma **plataforma multi-tenant de rastreamento de ativos com telemetria de vídeo (MDVR/ADAS/DMS)**, idêntica em conceito, fluxo e aparência ao YUV. O núcleo não é o GPS — é a **gestão de ocorrências de comportamento do motorista** capturadas por câmeras com IA (distração, uso de celular, sem cinto, fadiga…), com um fluxo de tratativa, classificação de risco e regras configuráveis por cliente. Todo o resto (rastreamento ao vivo, relatórios, cadastros, comandos, vídeo) sustenta essa operação.

### 1.2 O que preservamos
- **Todo o gateway de webhooks** (`handlers/push*.php` + `config/WebhookHandler.php`): pipeline assíncrono, idempotência, isolamento de protocolo JIMI/JT-T. É a fonte de dados de todo o produto.
- **Autenticação por token em cookie** (`includes/auth.php`, tabela `sessions`).
- **Stack**: PHP puro, sem build step, sem framework JS, MySQL, front controller (`router.php`), CSS inline no layout.
- **Tabelas existentes** que alimentam os módulos: `alarms`, `alarm_types` (114 códigos), `gps_data`, `heartbeats`, `media_files`, `ftp_uploads`, `resource_lists`, `devices`, `commands`, `device_statistics`.

### 1.3 O que muda (a "cópia fiel")
- **Design**: da paleta Cursor (laranja `#f54e00`, canvas creme) para o **design system Coinbase** (azul `#0052ff`, canvas branco, sidebar dark near-black `#0a0b0d`, CTAs pill, mono nos números). Ver [§5](#5-design-system-e-layout), `DESIGN.md` e `DESIGN-coinbase.md`. _(A estrutura/IA de produto continua a do YUV; muda a linguagem visual.)_
- **IA de navegação**: sidebar com grupos-sanfona (Vídeos, Relatórios, Cadastros) + itens principais. Ver [§4](#4-mapa-de-rotas-alvo).
- **Módulos novos**: Ocorrências/DMS, BI, Exportação assíncrona, Vídeo estruturado (Ao Vivo/Playback/Downloads), Chips, Motoristas, Grupos de Permissão, Config. de Ocorrências, e um Resumo executivo enriquecido.

### 1.4 Fora de escopo (primeira entrega)
- Módulo **Checklist** (inspeção veicular) — o YUV referencia mas não expõe na sidebar do perfil analisado. Fica como **fase futura** (ver [§8.23](#823-fase-futura-checklist)).
- App mobile nativo (o YUV é PWA responsivo; nós entregamos web responsivo).
- FaceID como serviço de IA próprio — consumimos o dado de identificação do motorista que o device/hub fornecer.

---

## 2. Conceito de produto e modelo de negócio

### 2.1 Hierarquia de tenancy (2 níveis)
```
Revendedor (reseller)            ← nós / o operador da plataforma
   └── Cliente (customer)        ← empresa dona da frota (ex.: "Construtora Barbosa Mello")
          └── Filial (branch)    ← unidade dentro do cliente (NOVO nível)
                 └── Ativo/Motorista/Equipamento
```
- **Perfil revendedor** vê "Visão por clientes" (Top 3 por equipamentos/ocorrências/desatualizados) e pode **impersonar** um cliente ("entrar como").
- **Perfil cliente** vê apenas os próprios dados.
- Hoje temos só `customer_id`. Precisamos adicionar o **tipo de usuário** (revendedor/cliente) e o nível **Filial**.

### 2.2 Cadeia de valor do DMS (o coração)
```
1. Device de câmera gera ALARME (distração, uso de celular, sem cinto…)   → pushalarm.php
2. REGRA do cliente decide: este alarme vira OCORRÊNCIA? com qual RISCO?   → occurrence_configs
3. OCORRÊNCIA entra em fila de TRATATIVA (status "Aguardando Tratativa")   → occurrences
4. Operador TRATA: valida, marca falso-positivo, resolve, anexa vídeo      → occurrence workflow
5. Métricas alimentam Resumo, Dashboard de Ocorrências e BI               → agregações
```

### 2.3 Modelo comercial observado
- **FaceID** é recurso pago/ativável por cliente ("fale com seu comercial YUV"). → implementar como **feature flag** por cliente.
- **Licenciamento por equipamento** (o YUV encerrou licenças de teste). → campo de licença/plano por device/cliente (fase futura).

---

## 3. Arquitetura-alvo

Mantemos a arquitetura atual e a estendemos. Nada de framework novo.

```
Jimi IoT Hub ──POST──▶ .htaccess ──▶ handlers/router.php ──▶ handlers/*.php
                                              │
   ┌──────────────────────────────────────────┴───────────────────────────────┐
   │ 1) RECEPTORES DE WEBHOOK (push*.php extends WebhookHandler)                 │
   │    token → 200 async (fastcgi_finish_request) → normalize → INSERT → stats │
   │    NOVO: pushalarm.php também aplica o MOTOR DE OCORRÊNCIAS (§7)            │
   │                                                                            │
   │ 2) PÁGINAS + AJAX DO DASHBOARD (layout YUV: web/layout_base.php)            │
   │    require_login()/require_admin() + require_ajax_session() nos AJAX        │
   │                                                                            │
   │ 3) WORKERS (cron): geração de relatórios assíncronos, detecção de viagens, │
   │    agregações do Resumo/BI, limpeza de sessões/logs                        │
   └────────────────────────────────────────────────────────────────────────────┘
```

### 3.1 Componentes novos de infraestrutura
| Componente | Onde | Papel |
|---|---|---|
| **Motor de ocorrências** | `includes/occurrence_engine.php` (novo) | Aplica regras de config → cria/agrupa ocorrências a partir de alarmes |
| **Fila de jobs** | tabela `jobs` + `scripts/worker.php` (cron) | Relatórios assíncronos, downloads de vídeo, agregações |
| **Geocodificação reversa** | `includes/geocode.php` (novo, com cache) | Endereço a partir de lat/lng nos relatórios |
| **Detecção de viagens** | `scripts/trip_builder.php` (cron) | Segmenta `gps_data` em `trips` por ignição |
| **Agregador de métricas** | `scripts/metrics_rollup.php` (cron) | Pré-computa KPIs do Resumo/BI |

---

## 4. Mapa de rotas alvo

Espelha a IA do YUV, com nomes de handler em PT-BR (convenção do projeto). **Coluna "Origem"** = tela YUV correspondente. **"Base"** = o que já existe e será reaproveitado.

### 4.1 Sidebar — Principal
| Rota alvo | Handler | Origem YUV | Base atual | Auth |
|---|---|---|---|---|
| `/` | `resumo.php` | Resumo `/` | `dashboard.php` (evoluir) | Login |
| `/rastreamento` | `rastreamento.php` | Rastreamento `/tracking` | `live.php` (evoluir) | Login |
| `/bi` | `bi.php` | BI `/bi` | — (novo) | Login |
| `/ocorrencias/dashboard` | `ocorrencias_dashboard.php` | Dashboard `/occurrences/dashboard` | — (novo) | Login |
| `/comandos` | `comandos.php` | Comandos `/commands` | `comandos.php` (manter) | Login |
| `/exportar` | `exportar.php` | Exportar `/report-export` | — (novo) | Login |

### 4.2 Grupo Vídeos
| Rota alvo | Handler | Origem YUV | Base atual |
|---|---|---|---|
| `/video/aovivo` | `video_aovivo.php` | Ao Vivo `/streaming` | `video.php` (split) |
| `/video/playback` | `video_playback.php` | Playback `/playback` | `video.php` (split) |
| `/video/downloads` | `video_downloads.php` | Downloads `/downloads` | `media_files`/`ftp_uploads` |

### 4.3 Grupo Relatórios
| Rota alvo | Handler | Origem YUV | Base atual |
|---|---|---|---|
| `/relatorios/posicoes` | `rel_posicoes.php` | Posições `/positions` | `gps_data` |
| `/relatorios/deslocamento` | `rel_deslocamento.php` | Deslocamento `/movement` | `gps_data` → `trips` |
| `/relatorios/desatualizados` | `rel_desatualizados.php` | Desatualizados `/outdated` | `device_statistics` |
| `/relatorios/alarmes` | `rel_alarmes.php` | Alarmes `/alarms` | `alarms` + `alarm_types` |
| `/relatorios/ocorrencias` | `rel_ocorrencias.php` | Ocorrências `/occurrences` | `occurrences` (novo) |

### 4.4 Grupo Cadastros
| Rota alvo | Handler | Origem YUV | Base atual |
|---|---|---|---|
| `/ativos` | `ativos.php` | Ativos `/assets` | `ativos.php` (manter/ajustar) |
| `/chips` | `chips.php` | Chips `/sim-cards` | — (novo) |
| `/clientes` | `clientes.php` | Clientes `/customers` | `clientes.php` (evoluir) |
| `/equipamentos` | `equipamentos.php` | Equipamentos `/devices` | `config.php`+`devicemodels.php` |
| `/grupos-permissao` | `grupos_permissao.php` | Grupos `/permission-groups` | — (novo) |
| `/motoristas` | `motoristas.php` | Motoristas `/drivers` | — (novo) |
| `/config-ocorrencias` | `config_ocorrencias.php` | Config. Ocorr. `/occurrence-settings` | — (novo) |
| `/usuarios` | `usuarios.php` | Usuários `/users` | `usuarios.php` (evoluir) |

### 4.5 AJAX / infra (mantidos + novos)
| Rota | Handler | Papel |
|---|---|---|
| `/customer_switch` | `customer_switch.php` | Troca de cliente (manter) |
| `/camerasdata`, `/trackdata`, `/hbdata`, `/mediadata` | idem | Dados de mapa/telemetria (manter, escopados) |
| `/commandstatus`, `/sendcommand`, `/devicemodels` | idem | Comandos (manter) |
| `/ocorrenciasdata` | `ocorrenciasdata.php` (novo) | Polling do Dashboard de Ocorrências (auto-refresh) |
| `/exportardata` | `exportardata.php` (novo) | Polling da fila de exportação |
| `/perfil` | `perfil.php` | Perfil (manter) |

> **Nota de roteamento**: `router.php` hoje só resolve o primeiro segmento (+ casos especiais `/ativos/*`, `/clientes/*`). Precisamos generalizar o parse para subrotas (`/video/aovivo`, `/relatorios/posicoes`, `/ocorrencias/dashboard`). Ver [§8.0](#80-roteador-fundação).

---

## 5. Design system e layout

> Especificação completa em [`DESIGN.md`](DESIGN.md) (reescrito para o YUV). Aqui, o resumo operacional para a implementação.

### 5.1 Tokens de marca (design system Coinbase)

> **Atualização**: o skin visual do produto é o **design system Coinbase** (`DESIGN-coinbase.md`), aplicado sobre a estrutura de produto YUV. Ver `DESIGN.md`. A estrutura/IA (módulos, ocorrências, rotas) permanece a do YUV; a linguagem visual é a Coinbase.

| Token | Valor | Uso |
|---|---|---|
| `--primary` | `#0052ff` (Coinbase Blue) | CTAs, links, foco, item ativo — **escasso** |
| `--primary-active` | `#003ecc` | Estado pressionado |
| `--surface-dark` | `#0a0b0d` | **Sidebar** (dark near-black) e heros escuros |
| `--surface-dark-elevated` | `#16181c` | Hover/campos na sidebar |
| `--on-dark` / `--on-dark-soft` | `#ffffff` / `#a8acb3` | Texto na sidebar |
| `--canvas` | `#ffffff` | Fundo da área de conteúdo (branco) |
| `--surface-strong` | `#eef0f3` | Botão secundário/chips |
| `--hairline` | `#dee1e6` | Hairlines |
| `--success` / `--error` | `#05b169` / `#cf202f` | Semânticos (up/down) — preferir cor de texto |
| Risco: baixo/médio/alto | `#0052ff` / `#f4b000` / `#cf202f` | Classificação DMS |

- **Tipografia**: Inter (400/500/600/700) + JetBrains Mono em **todo número/IMEI/código**. Headings de display em **peso 400** (voz editorial calma — não usar 700 em display).
- **Geometria**: CTAs **pill (100px)**; cards de dados `16px`, cards grandes `24px`; ícones/avatares `full`.
- **Profundidade**: **um único nível de sombra** (`0 4px 12px rgba(0,0,0,.04)`), só em hover; fora disso, plano + hairline.
- **Sidebar dark, single accent** (sem white-label de cor): a sidebar é near-black e o único destaque é o azul. O logo do tenant (`customers.logo_url`) pode aparecer no topo, mas a cor da sidebar **não** é white-label neste skin.

### 5.2 Shell de layout (reescrever `web/layout_base.php`)
```
┌───────────────┬───────────────────────────────────────────────────────┐
│  SIDEBAR      │  HEADER: [colapsar] [On: N  Off: M] .......... [👤 perfil]│
│  (dark        ├───────────────────────────────────────────────────────┤
│   #0a0b0d)    │                                                         │
│  ▸ logo tenant│                                                         │
│  • Resumo     │   ÁREA DE CONTEÚDO (canvas #faf9fc)                     │
│  • Rastream.  │   breadcrumb + título + ações (Cadastrar/Exportar)      │
│  • BI         │   cards / tabelas / mapa / gráficos                     │
│  • Dashboard  │                                                         │
│  ▾ Vídeos     │                                                         │
│     Ao Vivo   │                                                         │
│     Playback  │                                                         │
│     Downloads │                                                         │
│  ▾ Relatórios │                                                         │
│  ▾ Cadastros  │                                                         │
│  • Comandos   │                                                         │
│  • Exportar   │                                                         │
└───────────────┴───────────────────────────────────────────────────────┘
```
- **Grupos-sanfona** (Vídeos/Relatórios/Cadastros): acordeão que expande sub-links; item pai vira toggle. Estado aberto/fechado persistido em `localStorage`.
- **Header**: contador de frota **On/Off** (verde/vermelho) atualizado por polling; à direita, avatar do usuário → `/perfil`.
- **Colapsar sidebar**: botão que reduz a sidebar a ícones (persistir preferência).
- **Responsivo**: abaixo de 768px a sidebar vira off-canvas (hambúrguer).

### 5.3 Componentes reutilizáveis a criar
| Componente | Onde aparece | Spec |
|---|---|---|
| **Cartão KPI colorido** (gradiente) | Dashboard Ocorrências, Resumo | Número grande + rótulo + ícone; variantes azul/amarelo/verde/vermelho |
| **Barra de distribuição** (3 faixas %) | Risco (Baixo/Médio/Alto), Desatualizados | Faixas somando 100%, cores semânticas |
| **Grade CRUD padrão** | todos os Cadastros | Título + Pesquisar + [Cadastrar] + [Exportar Excel] + tabela + paginação + menu de ações por linha (⋯/☰) |
| **Barra de filtros "Gerar"** | todos os Relatórios/BI | Multiselects (Clientes/Ativos/Tipo de Alarme/Motoristas) + Período + botão [Gerar]; só consulta ao clicar |
| **Multiselect com chips** (`+N ✕`) | filtros | Mostra "+2", "+55" com contador |
| **Seletor de período** (range) | relatórios/BI | Datepicker range (default: hoje 00:00–23:59) |
| **Selo de status/risco** (pill) | ocorrências/alarmes/devices | `Aguardando Tratativa`, `Alto`, `Offline`, `Ativo`… |
| **Toggle "Atualização automática"** | Dashboards | Liga/desliga polling |
| **Player de vídeo** | Ao Vivo/Playback | flv.js (já temos) + timeline |
| **Mapa** | Rastreamento/Posições/Resumo | Leaflet + OSM (já temos) |
| **Gráficos** | Resumo/BI | SVG inline ou uma lib leve sem build (ex.: uPlot via CDN, ou SVG manual). **Sem** build step. |

> **Gráficos sem build step**: usar SVG inline gerado no PHP para pizzas/barras simples e **uPlot** (CDN, ~40kb) ou **Chart.js** (CDN) para séries temporais. Decisão registrada em `DESIGN.md`.

---

## 6. Modelo de dados

Nova migração **`mysql/migration_v4.0.0.sql`** (idempotente, mesmo padrão da v3.1.0: `add_column_if_not_exists`, `CREATE TABLE IF NOT EXISTS`, FKs condicionais).

### 6.1 Novas tabelas
```sql
-- Filial (nível abaixo de customer)
branches(id, customer_id FK, name, is_active, created_at)

-- Motorista + compliance + identificação (FaceID)
drivers(id, customer_id FK, branch_id FK NULL, name, birth_date,
        cnh_number, cnh_category, cnh_expires_at, tox_exam_expires_at,
        identifier /* vínculo FaceID/RFID */, photo_url, is_active, created_at)

-- Chip/SIM
sim_cards(id, customer_id FK NULL, carrier, msisdn, iccid, imei FK->devices NULL,
          is_active, created_at)

-- Grupos de permissão (RBAC)
permission_groups(id, name, user_type ENUM('revendedor','cliente'),
                  permissions JSON /* matriz tela→ações */, created_at)

-- Perfis de configuração de ocorrência (motor de regras)
occurrence_configs(id, name, is_default TINYINT, created_at)
occurrence_config_params(id, config_id FK, alarm_type /* código Jimi */,
                         generates_occurrence TINYINT, risk ENUM('baixo','medio','alto'),
                         threshold INT NULL /* agrupamento/limiar */, created_at)

-- Ocorrências (núcleo DMS)
occurrences(id, customer_id FK, branch_id FK NULL, imei, driver_id FK NULL,
            alarm_type, risk ENUM('baixo','medio','alto'),
            status ENUM('aguardando','em_tratativa','resolvida','descartada') DEFAULT 'aguardando',
            false_positive TINYINT DEFAULT 0,
            first_alarm_at DATETIME, last_alarm_at DATETIME, alarm_count INT DEFAULT 1,
            media_file_id FK NULL, treated_by FK->users NULL, treated_at DATETIME NULL,
            treatment_notes TEXT NULL, created_at)

occurrence_events(id, occurrence_id FK, alarm_id FK->alarms, created_at) -- alarmes agrupados

-- Viagens (relatório de deslocamento)
trips(id, imei, driver_id FK NULL, started_at, start_lat, start_lng, start_addr,
      ended_at, end_lat, end_lng, end_addr, duration_s, max_speed, distance_km,
      alarm_count, created_at)

-- Fila de jobs assíncronos (exportações, downloads de vídeo, rollups)
jobs(id, type ENUM('report','video_download','rollup'), customer_id FK NULL,
     params JSON, status ENUM('pendente','processando','concluido','falhou') DEFAULT 'pendente',
     result_path VARCHAR, requested_by FK->users, created_at, updated_at)

-- Cache de geocodificação reversa
geocode_cache(id, lat DECIMAL(9,6), lng DECIMAL(9,6), address VARCHAR, created_at,
              UNIQUE(lat,lng))

-- Auditoria de impersonação (segurança)
impersonation_log(id, reseller_user_id FK, customer_id FK, started_at, ended_at)
```

### 6.2 Alterações em tabelas existentes
```sql
-- users: tipo de usuário + grupo de permissão + foto
users: ADD user_type ENUM('revendedor','cliente') DEFAULT 'cliente',
       ADD permission_group_id FK->permission_groups NULL,
       ADD photo_url VARCHAR NULL

-- customers: white-label + configs + filial + feature flags
customers: ADD reseller_id FK->users NULL,        -- quem revende este cliente
           ADD brand_color VARCHAR(9) NULL,        -- cor da sidebar
           ADD logo_url VARCHAR NULL,
           ADD occurrence_config_id FK->occurrence_configs NULL,
           ADD checklist_config_id BIGINT NULL,    -- fase futura
           ADD faceid_enabled TINYINT DEFAULT 0    -- feature flag

-- devices: streaming + periféricos + firmware
devices: ADD sim_card_id FK->sim_cards NULL,
         ADD peripherals JSON NULL,
         ADD streaming_rotation SMALLINT DEFAULT 0,   -- 0/90/180/270/360
         ADD streaming_watermark TINYINT DEFAULT 0,
         ADD firmware_version VARCHAR NULL,
         ADD branch_id FK->branches NULL

-- media_files / ftp_uploads: canal + status de download (para /video/downloads)
media_files: ADD channel TINYINT NULL, ADD download_status ENUM('solicitado','disponivel','erro') NULL
```

### 6.3 Índices críticos (performance)
```sql
CREATE INDEX idx_occ_customer_status ON occurrences(customer_id, status, last_alarm_at);
CREATE INDEX idx_occ_imei_type ON occurrences(imei, alarm_type, last_alarm_at);
CREATE INDEX idx_alarms_imei_time ON alarms(imei, alarm_time);   -- relatório de alarmes (448/dia)
CREATE INDEX idx_gps_imei_time ON gps_data(imei, gps_time);      -- posições/viagens
CREATE INDEX idx_payload_hash_created ON request_logs(payload_hash, created_at); -- R07 herdado
CREATE INDEX idx_trips_imei_time ON trips(imei, started_at);
```

### 6.4 Seeds
- `occurrence_configs`: perfil **"Padrão Sistema"** (`is_default=1`) com parâmetros para os tipos DMS comuns (Distração=baixo, Uso de celular=alto, Sem cinto=baixo, Fadiga=alto…), mapeados a partir de `alarm_types`.
- `permission_groups`: **"Administrador"** (revendedor, todas as permissões) e um grupo cliente padrão.

---

## 7. O motor de ocorrências

> É a peça central. Sem ela, os módulos A (Ocorrências) não existem. Implementar em `includes/occurrence_engine.php` e **chamar dentro de `handlers/pushalarm.php`** após o INSERT do alarme.

### 7.1 Algoritmo (executa por alarme recebido)
```
função processar_alarme(alarm):
    device   ← devices[alarm.imei]
    config   ← device.customer.occurrence_config  (fallback: occurrence_config default)
    param    ← config.params[alarm.alarm_type]
    se param == null OU param.generates_occurrence == 0:
        retorna  # alarme fica só no relatório de Alarmes, não vira ocorrência

    # Agrupamento (deduplicação por janela): mesmo device+tipo+motorista dentro de X min
    janela   ← param.threshold ? param.threshold : CONFIG_JANELA_PADRAO (ex.: 10 min)
    aberta   ← occurrences WHERE imei=alarm.imei AND alarm_type=alarm.alarm_type
                         AND status='aguardando' AND last_alarm_at >= now - janela

    se aberta existe:
        aberta.alarm_count += 1
        aberta.last_alarm_at = alarm.time
        occurrence_events += (aberta.id, alarm.id)
    senão:
        nova = occurrences.insert(
            customer_id, branch_id, imei, driver_id=alarm.driver_id,
            alarm_type, risk=param.risk, status='aguardando',
            first_alarm_at=alarm.time, last_alarm_at=alarm.time, alarm_count=1,
            media_file_id = vínculo com media_files do mesmo evento, se houver)
        occurrence_events += (nova.id, alarm.id)
```
- **driver_id** só é preenchido se `faceid_enabled` e o alarme trouxer identificação — senão fica nulo (coluna "Motorista" vazia, como no YUV).
- **media_file_id**: quando o device sobe o vídeo do evento (`pushfileupload`/`pushftpfileupload`), casar por IMEI + timestamp próximo e vincular à ocorrência.
- Roda **dentro do processamento assíncrono** (pós-200), então não impacta a latência do webhook.

### 7.2 Fluxo de tratativa (workflow)
```
aguardando ──[operador abre]──▶ em_tratativa ──┬──[resolver]────▶ resolvida
                                               ├──[falso positivo]▶ descartada (false_positive=1)
                                               └──[descartar]─────▶ descartada
```
- Transições registram `treated_by`, `treated_at`, `treatment_notes`.
- A tela de detalhe da ocorrência (abre em nova visão) mostra: vídeo/mídia, dados do alarme, mapa do ponto, histórico de alarmes agrupados, botões de ação.

---

## 8. Especificação módulo a módulo

> Formato de cada módulo: **Objetivo · Layout · Componentes · Dinâmica · Regras de negócio · Dados/endpoints · Passos de implementação · Aceite**. Screenshots de referência em `analise_yuv/screenshots/`.

### 8.0 Roteador (fundação)
**Passos**: generalizar `router.php` para suportar subrotas de 2 segmentos por prefixo (`video/*`, `relatorios/*`, `ocorrencias/*`, `grupos-permissao`, `config-ocorrencias`). Manter compat das rotas atuais. Registrar todos os handlers novos. Remover rotas mortas (`clientes_novo`, `cliente_dashboard` — R08) e `pushcmd` (R09).
**Aceite**: cada rota de [§4](#4-mapa-de-rotas-alvo) resolve para seu handler; 404 amigável para o resto.

---

### 8.1 Resumo (home) — `/`
**Origem**: `screenshots/page_resumo.png`. **Base**: `dashboard.php`.
**Objetivo**: visão 360° executiva da operação.
**Layout** (blocos verticais):
1. **Tempo real** — 4 cartões (Equipamentos ativos `N/N`, Conectividade On/Off, Ignição lig/desl, Ocorrências total/aguardando/críticas) + **Mapa de Calor** (Leaflet heatmap).
2. **Operação em tempo real** — Velocidade da Frota (Parados/≤20/≤60/>60 km/h), Ociosidade (ignição ligada + vel. 0), Status por modelo (JC371/JC400D/JC450).
3. **Desatualizados** — pizza (>7d, >30d, nunca posicionados).
4. **Visão por clientes** (só perfil revendedor) — Top 3 por equipamentos ativos / ocorrências / desatualizados.
5. **Dados temporais** — série de Alarmes hoje / Ocorrências hoje (toggle Hoje/7 dias/mês) + Top placas/motoristas com mais alarmes.
**Dinâmica**: auto-refresh dos blocos de tempo real (polling 30s, reusa `/camerasdata`, `/hbdata`, `/ocorrenciasdata`). Tour de boas-vindas (11 passos) + banner de comunicado (dispensáveis, `localStorage`).
**Regras**: "Top motoristas" exige `faceid_enabled` (senão, CTA de upsell). "Visão por clientes" condicionada a `user_type='revendedor'`.
**Dados**: agregações pré-computadas por `scripts/metrics_rollup.php` (cron) para não pesar na página.
**Aceite**: todos os blocos renderizam com dados reais escopados ao cliente/contexto; heatmap plota; séries mostram 24h; auto-refresh sem reload.

---

### 8.2 Rastreamento — `/rastreamento`
**Origem**: `screenshots/page_tracking.png`. **Base**: `live.php`.
**Objetivo**: mapa ao vivo com navegação Cliente → Ativo.
**Layout**: duas colunas à esquerda (**Clientes** com busca; **Ativos** com busca) + **mapa Leaflet** à direita. Cada ativo: selo On/Off, nome, IMEI (mono), última posição (data/hora), estado da ignição.
**Dinâmica**: clique no cliente filtra ativos; clique no ativo centraliza o pin; colunas colapsáveis; auto-refresh 30s.
**Regras**: escopo por `customer_id`; "tempo desde última posição" derivado de `device_statistics`.
**Dados**: `/camerasdata` (escopado) + `/trackdata`.
**Aceite**: seleção em cascata funciona; pins atualizam; busca filtra listas.

---

### 8.3 BI — `/bi`
**Origem**: `screenshots/page_bi.png`. **Prioridade baixa.**
**Objetivo**: gerador de análises sob demanda.
**Layout**: barra de filtros (Cliente, Ativos, Motoristas, Alarmes[+55], Período) + [Gerar]; área de gráficos abaixo (vazia até gerar).
**Dinâmica**: só consulta ao clicar Gerar; renderiza gráficos (barras/linhas/pizza) sobre alarmes/ocorrências.
**Dados**: mesmos datasets de Alarmes/Ocorrências; reaproveitar agregações.
**Aceite**: gerar produz ao menos 3 gráficos coerentes com o filtro.

---

### 8.4 Dashboard de Ocorrências — `/ocorrencias/dashboard`
**Origem**: `screenshots/page_occ_dashboard.png` + `occ_detail.png`. **Novo. Prioridade ALTA.**
**Objetivo**: painel operacional de gestão de eventos DMS.
**Layout**: barra **Filtros** + toggle **Atualização automática**; 4 **cartões coloridos** (Ocorrências, Aguardando Tratativa, Online, Offline); **barra de risco** (Baixo/Médio/Alto %); **grade** (Cliente, IMEI, Motorista, Data, Status, Tipo, Risco, ação de abrir).
**Dinâmica**: polling `/ocorrenciasdata` quando o toggle está on; ação por linha abre o **caso** (detalhe/tratativa) em nova visão; filtros colapsáveis; paginação server-side.
**Regras**: risco vem da regra (config), não do alarme; cartões On/Off refletem conectividade do **device de câmera**.
**Dados**: tabela `occurrences` (+ join `devices`, `drivers`).
**Passos**: (1) motor de ocorrências [§7] populando `occurrences`; (2) `ocorrencias_dashboard.php` (cards+barra+grade); (3) `ocorrenciasdata.php` (JSON polling); (4) tela de detalhe/tratativa.
**Aceite**: cartões e barra refletem os dados; auto-refresh atualiza sem reload; abrir caso mostra vídeo+alarme+mapa+ações; transições de status persistem.

---

### 8.5 Comandos — `/comandos`
**Origem**: `screenshots/page_commands.png` + `modal_novo_comando.png`. **Base**: `comandos.php` (manter).
**Objetivo**: envio de comandos com histórico e polling.
**Layout**: Histórico à esquerda + área do equipamento à direita + [Novo comando]. Modal "Novo comando" exige selecionar equipamento (busca 3+ chars) antes de liberar os comandos.
**Dinâmica**: já temos `sendcommand` + `commandstatus` (polling 3s/10s/5min). Alinhar UI ao YUV.
**Aceite**: enviar comando, ver na história, status atualiza; presets JIMI/JT-T corretos.

---

### 8.6 Exportar Relatórios — `/exportar`
**Origem**: `screenshots/page_report_export.png`. **Novo. Prioridade média.**
**Objetivo**: fila de geração assíncrona de relatórios pesados.
**Layout**: grade (Nome, Tipo PDF/Excel, Status, Data de Criação, Data de Atualização, download) + Pesquisar + toggle Atualização automática.
**Dinâmica**: usuário solicita export (a partir de um relatório) → cria `jobs(type=report)` → `worker.php` processa → status "concluído" + arquivo para download; UI faz polling `/exportardata`.
**Dados**: tabela `jobs`; arquivos em `storage/reports/`.
**Aceite**: solicitar gera job pendente; worker conclui; download funciona; polling reflete status.

---

### 8.7–8.9 Vídeo (Ao Vivo / Playback / Downloads)
**Origem**: `page_streaming.png`, `page_playback.png`, `page_downloads.png`. **Base**: `video.php` (split em 3) + `media_files`/`ftp_uploads`.

**8.7 Ao Vivo — `/video/aovivo`**
Seleção Cliente → Ativo → canal; player flv.js (já temos). Aplica `streaming_rotation`/`watermark` do device. Depende de device Online. Envia proNo 37121 antes de tocar (padrão atual).
**Aceite**: selecionar ativo online abre stream do canal.

**8.8 Playback — `/video/playback`**
2 passos: (1) selecionar Equipamento + canal + Período → [Requisitar]; (2) escolher arquivo na **timeline**. Requisitar envia comando de "listar gravações" ao device; monta timeline; clicar num trecho dispara download → aparece em Downloads.
**Aceite**: requisitar lista gravações do período; clicar reproduz/enfileira.

**8.9 Downloads — `/video/downloads`**
Fila de extração device→servidor. Grade (Nome, Identificador, Equipamento, Modelo, Canal, Requisitado em, Status) + filtro Status. `pushfileupload`/`pushftpfileupload` fecham o job (status "disponível" + URL).
**Aceite**: item aparece "solicitado" e vira "disponível" quando o webhook de upload chega; download funciona.

---

### 8.10 Relatório de Posições — `/relatorios/posicoes`
**Origem**: `page_positions.png`. **Base**: `gps_data`.
**Layout**: filtro Ativo + Período + Intervalo (Todas/amostrado) + [Gerar] + [Ver posições] (mapa) + Export Excel/PDF. Grade: Identificador, **Endereço** (geocodificado), Motorista, Ignição, Sinal, Velocidade, Horários.
**Dinâmica**: geocodificação reversa com cache (`geocode_cache`); "Ver posições" plota trajeto no mapa.
**Aceite**: gerar lista posições com endereço; ver no mapa; export.

---

### 8.11 Relatório de Deslocamento — `/relatorios/deslocamento`
**Origem**: `page_movement.png`. **Base**: `gps_data` → `trips`.
**Layout**: filtro Ativos + Período + [Gerar] + Export. Grade: Identificador, Motorista, Início/Local Início, Fim/Local Fim, Evento, Duração, Velocidade Máxima, KM, **Qtd. de Alarmes**.
**Dinâmica**: `scripts/trip_builder.php` (cron) segmenta viagens por ignição (lig→desl) e cruza com `alarms` da janela. Locais geocodificados.
**Aceite**: viagens corretas com KM/duração/vel.máx e contagem de alarmes.

---

### 8.12 Relatório de Desatualizados — `/relatorios/desatualizados`
**Origem**: `page_outdated.png`. **Base**: `device_statistics`.
**Layout**: filtro Cliente + Resumo em faixas: <24h, >1d, >7d, >30d, **nunca posicionados**; cada faixa com Detalhes + Export.
**Regra**: bucketização por `now - last_position_at`; "nunca posicionados" = device sem nenhuma posição.
**Aceite**: percentuais batem; Detalhes lista os devices da faixa.

---

### 8.13 Relatório de Alarmes — `/relatorios/alarmes`
**Origem**: `page_alarms.png`. **Base**: `alarms` + `alarm_types` (já temos). **Parcial.**
**Layout**: filtros Clientes/Equipamentos/Tipo de Alarme[+55]/Filiais/Período + Export. Grade ordenável: Cliente, Filiais, Identificador, IMEI, Tipo de Alarme, Data.
**Dinâmica**: paginação server-side (volume alto, ~448/dia); ordenação por coluna.
**Aceite**: filtros combinam; ordenação funciona; export Excel/PDF.

---

### 8.14 Relatório de Ocorrências — `/relatorios/ocorrencias`
**Origem**: `page_occurrences.png`. **Novo. Prioridade ALTA.**
**Layout**: filtros Clientes/Filiais/Ativos/Tipo de Alarme/Motoristas/**Falso positivo**/Status/Período + Export. Grade: Cliente, Identificador, Motorista, IMEI, **Último alarme em**, Alarme, Falso positivo, Situação.
**Regra**: versão histórica/auditável do dashboard; "Falso positivo" como métrica de qualidade da IA; ocorrências agrupam alarmes (coluna "último alarme em").
**Aceite**: filtro de falso positivo e status funcionam; export; consistente com o dashboard.

---

### 8.15 Ativos — `/ativos`
**Origem**: `page_assets.png`. **Base**: `ativos.php` (manter/ajustar).
**Layout**: grade (Identificador, Cliente, Modelo, Ano, IMEI) + Pesquisar + [Cadastrar] + Export.
**Regra**: separar conceitualmente **Ativo (veículo)** de **Equipamento (device)** — o ativo referencia o IMEI (device pode trocar de veículo).
**Aceite**: CRUD funciona; separação ativo/equipamento clara.

---

### 8.16 Chips (SIM) — `/chips`
**Origem**: `page_sim_cards.png`. **Novo. Prioridade média.**
**Layout**: grade (Operadora, Número, ICCID, IMEI) + Pesquisar + [Cadastrar] + Export.
**Regra**: vínculo 1:1 chip↔device por IMEI; chip pode existir em estoque (IMEI vazio).
**Aceite**: CRUD; vínculo com device.

---

### 8.17 Clientes — `/clientes`
**Origem**: `page_customers.png`. **Base**: `clientes.php` (evoluir).
**Layout**: grade (Nome, E-mail, Grupo de Permissão, **Configuração de Ocorrência**, **Configuração de Checklist**, Ativo, **impersonar**) + [Cadastrar] + Export.
**Regra**: cada cliente aponta para um `occurrence_config`; **impersonação** ("entrar como") registra em `impersonation_log`; white-label (`brand_color`, `logo_url`).
**Aceite**: CRUD; atribuição de config; impersonar troca contexto e audita.

---

### 8.18 Equipamentos — `/equipamentos`
**Origem**: `page_devices.png` + `modal_device_form.png`. **Base**: `config.php`+`devicemodels.php`. **Prioridade ALTA.**
**Layout**: grade (IMEI, Modelo, Cliente, Ativo, Chip, Último Heartbeat, Bateria, Periféricos, Situação On/Off, Status Ativo) + ações **[Exportar Excel] [Cadastrar] [Atualizar Firmware] [Importar em Lote]** + filtros Cliente/Modelo/Status/Situação.
**Form cadastro**: Modelo* + IMEI* (obrigatórios), Chip, Periféricos (multi-seleção), **Rotação do streaming (360°)**, checkbox **marca d'água no streaming**.
**Recursos de destaque**: **OTA firmware** (usa canal de comandos), **Importação em lote** (planilha).
**Dados**: `devices` (colunas novas em [§6.2]); heartbeat via `pushhb`; periféricos via `pushresourcelist`.
**Aceite**: CRUD; heartbeat/bateria exibidos; OTA envia comando; importação em lote cria N devices.

---

### 8.19 Grupos de Permissões — `/grupos-permissao`
**Origem**: `page_permission_groups.png`. **Novo. Prioridade média.**
**Layout**: grade (Nome, Tipo de Usuário [Revendedor/Cliente], Qtd. de Usuários) + [Cadastrar].
**Regra**: RBAC com matriz de permissões (tela→ações) em `permission_groups.permissions` (JSON); dois eixos (revendedor/cliente).
**Aceite**: criar grupo; atribuir a usuário; permissões efetivas restringem telas/ações.

---

### 8.20 Motoristas — `/motoristas`
**Origem**: `page_drivers.png`. **Novo. Prioridade média.**
**Layout**: grade (Foto, Cliente, Nome, Data de Nascimento, CNH, **CNH expira em**, **Exame toxicológico expira em**, Identificador, Categorias) + [Cadastrar] + Export.
**Regra**: alertas de vencimento CNH/toxicológico; **Identificador** habilita FaceID (atribui ocorrências ao motorista).
**Aceite**: CRUD; alertas de vencimento; vínculo de identificação reflete na coluna Motorista das ocorrências.

---

### 8.21 Configurações de Ocorrências — `/config-ocorrencias`
**Origem**: `page_occurrence_settings.png` + `modal_occ_settings_form.png`. **Novo. Prioridade ALTA (base do §7).**
**Layout**: grade (Nome, Qtd. de Clientes) + [Cadastrar]. Form: **Nome + Parâmetros** (linhas dinâmicas "+": tipo de alarme → gera ocorrência? → risco → limiar).
**Regra**: perfil aplicado a N clientes; existe perfil **default** global; specific overrides por cliente.
**Aceite**: criar/editar perfil; parâmetros dinâmicos; o motor [§7] respeita o perfil do cliente.

---

### 8.22 Usuários — `/usuarios`
**Origem**: `page_users.png`. **Base**: `usuarios.php` (evoluir).
**Layout**: abas **Minha Empresa** / **Meus Clientes**; grade (Foto, Nome, E-mail, Grupo de Permissão, Ativo) + [Cadastrar].
**Regra**: separa usuários próprios (revendedor) dos usuários dos clientes; vínculo com Grupos de Permissão.
**Aceite**: abas segmentam; CRUD; grupo de permissão aplicado.

---

### 8.23 (fase futura) Checklist
Módulo de inspeção veicular referenciado no cadastro de Clientes ("Configuração de Checklist"). Levantar em fase futura: `checklist_configs`, `checklist_items`, `checklist_responses`. **Fora do escopo da primeira entrega.**

---

## 9. Padrões transversais

### 9.1 Grade CRUD padrão
Todo Cadastro segue: título + breadcrumb + [Cadastrar] (topo dir) + Pesquisar + [Exportar Excel] + tabela paginada + menu de ações por linha (editar/remover/…). Componentizar em um helper de layout (`web/components/crud_grid.php`).

### 9.2 Barra de filtros "Gerar"
Todo Relatório/BI: multiselects com chips (`+N`) + Período (range, default hoje) + [Gerar]. Consulta só ao clicar. Export Excel/PDF síncrono (relatórios leves) ou via fila (`/exportar`, pesados).

### 9.3 Multi-tenancy e segurança (obrigatório)
- Toda query escopada por `customer_id` da sessão (herdar correções v3.2.1: `require_ajax_session()`).
- Impersonação audita em `impersonation_log`.
- Novos formulários POST **com CSRF token** (fechar R11 na origem).
- Prepared statements sempre (não repetir R04/R12).

### 9.4 Async e polling
- Auto-refresh via polling (padrão `commandstatus`): Resumo, Dashboard Ocorrências, Exportar, Downloads.
- Jobs pesados via `jobs` + `worker.php` (cron a cada 1 min).

### 9.5 i18n / formato
- UI 100% PT-BR. Timestamps UTC no banco → BRT na exibição (padrão atual). Moeda/《número》pt-BR.

---

## 10. Roadmap por fases

Ordenado por valor × dependências. Cada fase termina com deploy + verificação.

| Fase | Entregas | Depende de | Resultado |
|---|---|---|---|
| **0 — Fundação** | Migração v4.0.0 (tabelas/índices); `router.php` com subrotas; novo `layout_base.php` (design YUV, sidebar-sanfona, header On/Off); componentes base (crud_grid, filtros, cards KPI, barra de risco) | — | Casca YUV navegável |
| **1 — Motor de Ocorrências** | `occurrence_engine.php`; integração em `pushalarm.php`; `occurrence_configs`/params; `/config-ocorrencias` | Fase 0 | Alarmes viram ocorrências por regra |
| **2 — Módulo DMS** | `/ocorrencias/dashboard` + `/ocorrenciasdata`; tela de tratativa; `/relatorios/ocorrencias`; `/relatorios/alarmes` | Fase 1 | Operação DMS ponta a ponta |
| **3 — Vídeo** | split `/video/aovivo` `/video/playback` `/video/downloads`; fila de download via `pushfileupload` | Fase 0 | Telemetria de vídeo estruturada |
| **4 — Equipamentos avançado** | `/equipamentos` (grade+form); OTA firmware; importação em lote; periféricos/streaming | Fase 0 + comandos | Provisionamento e manutenção de frota |
| **5 — Relatórios + Exportação** | `/relatorios/posicoes` `/deslocamento` `/desatualizados`; `trip_builder`; geocode cache; `/exportar` + `worker.php` | Fase 1 (qtd. alarmes) | Relatórios ricos + fila assíncrona |
| **6 — Cadastros de apoio** | `/chips`; `/motoristas` (+FaceID); `/grupos-permissao`; evoluir `/clientes` (config+impersonar) e `/usuarios` (abas) | Fase 0 | Cadastros completos + RBAC |
| **7 — Visão executiva** | `/` Resumo enriquecido (heatmap, velocidade, séries, top clientes); `/bi`; `metrics_rollup` | Fases 1–6 | Camada analítica |
| **F — Futuro** | Checklist; licenciamento/planos; white-label completo | — | Paridade total |

---

## 11. Critérios de aceite globais

- [ ] Todas as 22 rotas de [§4](#4-mapa-de-rotas-alvo) resolvem e renderizam no layout com o design Coinbase (azul + sidebar dark + header On/Off).
- [ ] Sidebar com grupos-sanfona (Vídeos/Relatórios/Cadastros) abrindo/fechando, estado persistido.
- [ ] Alarme de câmera → ocorrência conforme regra do cliente; tratativa com transições auditadas.
- [ ] Dashboards com "Atualização automática" (polling) sem reload.
- [ ] Cada Cadastro com CRUD + Pesquisar + Exportar; cada Relatório com filtros "Gerar" + Export.
- [ ] Vídeo: Ao Vivo toca; Playback lista timeline; Downloads reflete `pushfileupload`.
- [ ] Multi-tenancy: nenhum dado cross-tenant; impersonação audita; CSRF em todos os POST.
- [ ] Todas as queries com prepared statements e índices de [§6.3].
- [ ] Documentação atualizada (este doc + DESIGN/CLAUDE/AGENTS/STATUS/CHANGELOG/README).

---

## 12. Plano de verificação

Sem suíte automatizada (convenção do projeto). Por fase:
1. **Lint**: `find handlers includes config core -name "*.php" -exec php -l {} \;` (mirror do deploy FASE 4).
2. **Webhook replay**: `curl` com payloads oficiais em `pushalarm`/`pushgps`/`pushhb`/`pushfileupload` → verificar criação de ocorrências, posições, heartbeats, downloads.
3. **Browser** (Playwright, como fizemos na análise): logar, percorrer cada rota, validar layout/dinâmica/escopo por cliente, auto-refresh, CRUD e filtros.
4. **Multi-tenant**: repetir fluxos como Cliente A e Cliente B, garantindo isolamento; testar impersonação.
5. **Carga**: relatório de Alarmes/Posições com volume real (paginação/índices).

---

## 13. Riscos e dívidas herdadas

Incorporar as pendências de [`STATUS.md`](STATUS.md) §10 **como parte** deste projeto (não deixar para depois):
- **Fechar na origem** ao reescrever handlers: CSRF (R11), prepared statements (R04/R12), índice `request_logs` (R07), cookie `Secure` (R18), limpeza de `sessions`/`request_logs` (R19).
- **Remover** rotas mortas (R08) e `pushcmd` (R09) na Fase 0.
- **GPS (0,0)** (R06): decidir tratamento antes do Resumo/Rastreamento dependerem de "última posição".
- **Ambiente**: instalar PHP CLI no dev ou pipeline de lint (F0.1) para viabilizar `php -l` local.

---

> **Este documento evolui com o projeto.** Cada fase concluída deve atualizar [§10](#10-roadmap-por-fases) (status), o `CHANGELOG.md` e o `STATUS.md`. A fonte visual de verdade é `analise_yuv/analise_yuv.html`.
