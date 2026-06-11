# PRD — Jimi Webhook System v2.0.0

> **Product Requirements Document**  
> Gateway IoT para dispositivos Jimi — recepção de webhooks GPS/heartbeat/alarme/evento, persistência MySQL e painel Bootstrap para monitoramento, visualização de mídia, envio de comandos e configuração remota.

| Meta | Detalhe |
|---|---|
| **Versão** | 2.0.0 |
| **Data** | 2026-06-10 |
| **Status** | Produção |
| **Stakeholders** | Operações de frota, Desenvolvimento |
| **Referência API** | [Jimi IoT Hub Integration Docs](https://docs.jimicloud.com/integration/integration.html) |

---

## 1. Executive Summary

O **Jimi Webhook System** é um gateway PHP que atua como ponte entre o **Jimi IoT Hub** (`jimicloud.com`) e uma infraestrutura local MySQL. Ele recebe 11 tipos de webhooks push de dispositivos veiculares (GPS, heartbeats, alarmes, eventos, mídia, LBS, comandos), persiste todos os dados em 16 tabelas normalizadas, e serve um painel web Bootstrap 5.3 com 5 abas para monitoramento em tempo real.

**Diferenciais técnicos:**
- Processamento assíncrono via `fastcgi_finish_request()` — resposta HTTP 200 imediata, processamento em background
- Anti-replay com idempotência por hash MD5 (janela de 10 minutos)
- Suporte dual-protocol: **JIMI** (JC400) e **JT/T 808** (JC450/JC181) com isolamento estrito (ADR-001)
- Dashboard com player de vídeo HTTP-FLV (flv.js), atualização silenciosa a cada 30s
- 16 presets de comando JIMI + 17 presets JTT para dispatch remoto

**Cobertura:** 11 de 24 endpoints da API oficial implementados (46%). Os 13 restantes estão mapeados no backlog.

---

## 2. Product Vision & Objectives

### 2.1 Problema

Operadores de frota que utilizam rastreadores Jimi (JC400, JC450, JC181) precisam de uma solução self-hosted para:
- Centralizar dados de telemetria de múltiplos dispositivos em banco próprio
- Visualizar alarmes, eventos e mídia em tempo real sem depender exclusivamente do portal JimiCloud
- Enviar comandos de configuração remota (foto, parâmetros, reset)
- Manter histórico local para auditoria e integração com sistemas internos

### 2.2 Objetivos

| # | Objetivo | Métrica |
|---|---|---|
| O1 | Receber e persistir 100% dos webhooks do IoT Hub sem perda | Zero `data_list` descartados por timeout/erro |
| O2 | Responder ao IoT Hub em <200ms (antes do processamento pesado) | Métrica em `request_logs.execution_time` |
| O3 | Fornecer painel com refresh silencioso e zero reload de página | Dashboard funcional em navegadores modernos |
| O4 | Isolar protocolos JIMI e JT/T sem cross-contamination | Nenhum alarme com nome de protocolo errado |
| O5 | Garantir idempotência contra replay/retry do IoT Hub | Payloads duplicados descartados em janela de 10min |

### 2.3 Non-Goals (v2.0.0)

- Autenticação multi-usuário (token único compartilhado)
- Rate limiting além da idempotência
- Notificações push (email, SMS, webhook de saída)
- Relatórios exportáveis (PDF, CSV)
- Suporte a dispositivos não-Jimi

---

## 3. Current Architecture (v2.0.0)

### 3.1 Diagrama de Fluxo

```
┌──────────────┐     POST      ┌─────────────┐     ┌──────────────┐
│  Jimi IoT Hub │ ────────────> │  .htaccess   │ ──> │  handlers/   │
│ jimicloud.com │  form-json   │  mod_rewrite │     │  *.php       │
└──────────────┘               └─────────────┘     └──────┬───────┘
                                                          │
                    ┌─────────────────────────────────────┤
                    │ WebhookHandler (abstract base)      │
                    │  ├─ get_webhook_data()              │
                    │  ├─ validateToken()                 │
                    │  ├─ sendEarlySuccess() ──> HTTP 200 │
                    │  ├─ isDuplicateRequest() (MD5)      │
                    │  ├─ normalize_data()                │
                    │  ├─ processItem() [abstract]        │
                    │  ├─ callProcedure()                 │
                    │  └─ logMetrics()                    │
                    └─────────────────────────────────────┤
                                                          │
                    ┌──────────────────────┐              │
                    │  MySQL (jimi_tracker) │<─────────────┘
                    │  16 tables            │
                    │  6 stored procedures  │
                    │  1 function, 1 trigger│
                    │  4 views              │
                    └──────────────────────┘

┌──────────────┐     GET/AJAX    ┌──────────────────────┐
│  Browser     │ <────────────── │  Dashboard SSR + AJAX │
│  Bootstrap 5 │                │  handlers/dashboard   │
│  flv.js      │                │  camerasdata/command  │
└──────────────┘                │  media/track/hb       │
                                └──────────────────────┘
```

### 3.2 Technology Stack

| Camada | Tecnologia | Versão |
|---|---|---|
| Runtime | PHP + PHP-FPM | 7.4+ |
| Web Server | Apache 2.4 | mod_rewrite |
| Database | MySQL | 8.0+ |
| Frontend | Bootstrap 5.3 + Bootstrap Icons | CDN |
| Video Player | flv.js | CDN |
| Auth | Token estático (query string/header) | — |
| Config | `.env` (parse manual, sem dotenv lib) | — |

### 3.3 Project Structure

```
jimi_webhook/
├── .htaccess                    # Apache rewrite + security headers
├── .env / .env.example          # Configuração de ambiente
├── config/
│   ├── database.php             # PDO singleton (lê .env)
│   └── WebhookHandler.php       # Abstract base (173 linhas)
├── core/
│   └── Logger.php               # Logger estático unificado (311 linhas)
├── includes/
│   └── functions.php            # normalize, validate, sanitize (185 linhas)
├── handlers/                    # 17 endpoints HTTP
│   ├── pushalarm.php            # Alarme (JIMI + JTT)
│   ├── pushevent.php            # Evento (login/logout)
│   ├── pushgps.php              # GPS (28 campos)
│   ├── pushhb.php               # Heartbeat (12 campos)
│   ├── pushfileupload.php       # Upload de arquivo
│   ├── pushftpfileupload.php    # Resultado FTP
│   ├── pushlbs.php              # LBS/cell-tower
│   ├── pushresourcelist.php     # Lista de recursos
│   ├── pushiothubevent.php      # Eventos do Hub
│   ├── pushTerminalTransInfo.php# Dados de extensão (1.15)
│   ├── pushinstructresponse.php # Resposta de comando (1.16)
│   ├── pushcmd.php              # Custom: match orphan responses
│   ├── dashboard.php            # SSR do painel
│   ├── camerasdata.php          # AJAX: dispositivos
│   ├── commandstatus.php        # AJAX: histórico comandos
│   ├── sendcommand.php          # AJAX: envio comandos
│   ├── mediadata.php            # AJAX: galeria mídia
│   ├── trackdata.php            # AJAX: tracks GPS
│   ├── hbdata.php               # AJAX: heartbeats
│   └── ping.php                 # Health check
├── web/
│   ├── index.php                # Wrapper -> handlers/dashboard.php
│   ├── dashboard_template.php   # Template HTML/JS canônico (1429 linhas)
│   └── assets/js/
│       └── dashboard.js         # Client-side JS (498 linhas)
├── mysql/
│   ├── jimi_tracker.sql         # Schema completo (901 linhas)
│   └── migration_v2.0.0.sql     # Migração v3.0.1 -> v2.0.0
├── logs/                        # Logs diários (webhook_YYYY-MM-DD.log)
├── docs/
│   ├── API_COVERAGE.md          # Matriz de cobertura
│   └── adr/ADR-001.md           # Decisão de arquitetura
├── .agents/                     # AG Kit (não-runtime)
├── README.md, AGENTS.md, CHANGELOG.md, LICENSE, llms.txt
└── DESIGN.md                    # Não relacionado ao projeto
```

### 3.4 Processing Pipeline (WebhookHandler)

Cada handler de webhook segue este pipeline exato:

```
1. get_webhook_data()
   └─ Parse POST (JSON body ou form-urlencoded com data_list como string JSON)

2. RAW_WEBHOOK_DATA log
   └─ DEBUG level: raw $_POST, php://input, content_type, keys

3. REQUEST RECEIVED log
   └─ INFO level: token_valid, data_count, payload_hash (MD5)

4. validateToken()
   └─ Compare com WEBHOOK_TOKEN do .env (fallback: a12341234123)

5. requestMeta
   └─ Armazena campos POST extras (ex: msgType) para handlers filhos

6. sendEarlySuccess()
   └─ HTTP 200 + JSON response
   └─ fastcgi_finish_request() — libera TCP, continua em background

7. isDuplicateRequest()
   └─ SELECT em request_logs WHERE payload_hash + 10min window
   └─ Se duplicado: WARNING log + exit

8. db->beginTransaction()

9. For each item in data_list:
   ├─ normalize_data() — camelCase -> snake_case
   ├─ processItem() — INSERT específico do handler
   └─ callProcedure() — stored procedure (se aplicável)

10. db->commit()

11. logMetrics()
    └─ INSERT em request_logs (endpoint, response_code, execution_time, payload_hash)
    └─ INFO log com saved/total/execution_time_ms
```

---

## 4. Feature Specification

### 4.1 Webhook Gateway

#### 4.1.1 Endpoints Implementados (11 de 24)

| # | Endpoint | Seção API | Handler | DB Table | Campos |
|---|---|---|---|---|---|
| 1 | `/pushevent` | 1.1 | `pushevent.php` | `events` | 4 |
| 2 | `/pushhb` | 1.2 | `pushhb.php` | `heartbeats` | 12 |
| 3 | `/pushgps` | 1.3 | `pushgps.php` | `gps_data` | 28 |
| 4 | `/pushalarm` | 1.4 | `pushalarm.php` | `alarms` | 45 |
| 5 | `/pushfileupload` | 1.8 | `pushfileupload.php` | `media_files` | 5 |
| 6 | `/pushlbs` | 1.10 | `pushlbs.php` | `lbs_data` | 5 |
| 7 | `/pushresourcelist` | 1.11 | `pushresourcelist.php` | `resource_lists` | 6 |
| 8 | `/pushftpfileupload` | 1.12 | `pushftpfileupload.php` | `media_files` | 4 |
| 9 | `/pushiothubevent` | 1.13 | `pushiothubevent.php` | `iothub_events` | 4 |
| 10 | `/pushTerminalTransInfo` | 1.15 | `pushTerminalTransInfo.php` | `device_events` | 4 |
| 11 | `/pushinstructresponse` | 1.16 | `pushinstructresponse.php` | `command_responses`, `commands` | 5 |

#### 4.1.2 Token Authentication

- Token fixo configurado em `WEBHOOK_TOKEN` no `.env` (fallback: `a12341234123`)
- Validado em toda requisição webhook e dashboard
- Sem refresh, sem expiração, sem escopos

#### 4.1.3 Idempotency / Anti-Replay

- Hash MD5 de `json_encode(data_list)`
- Verificação contra `request_logs` com janela de 10 minutos (`NOW() - INTERVAL 10 MINUTE`)
- Payloads duplicados recebem WARNING log e são descartados silenciosamente
- A verificação ocorre **após** o `sendEarlySuccess()` — o IoT Hub sempre recebe HTTP 200

#### 4.1.4 Async Processing

- `fastcgi_finish_request()` libera a conexão TCP após `sendEarlySuccess()`
- Todo o processamento (normalize, INSERT, stored proc, commit, metrics) ocorre em background
- **Requisito**: PHP-FPM (não funciona com mod_php)
- **Trade-off**: Logs e debugging após o early response são invisíveis ao chamador (IoT Hub)

#### 4.1.5 Data Normalization

`normalize_data()` em `includes/functions.php` (linha 28):
- Mapeia camelCase da API Jimi → snake_case interno/DB:
  - `deviceImei` → `imei`
  - `lat` → `latitude`, `lng` → `longitude`
  - `gpsSpeed` → `speed`
  - `gpsTime` → `gps_time`
  - `gateTime` → `gateway_time`
  - `alarmTime` → `alarm_time`
  - `satelliteNum` → `satellites`
  - `gsmSignal` → `gsm`
  - `power` → `battery`
- Preserva chave original + adiciona chave normalizada (não substitui)

#### 4.1.6 Coordinate Validation

`is_valid_coordinate()` em `includes/functions.php` (linha 78):
- Latitude: -90 a 90
- Longitude: -180 a 180
- Rejeita (0,0) — valor padrão de dispositivos sem fix GPS
- Retorna `false` para coordenadas fora dos limites

#### 4.1.7 /pushalarm — Dual Protocol Alarm Handler

O handler mais complexo do sistema. Suporta 4 tipos de mensagem:

| type | msgClass | Protocolo | Resolução |
|---|---|---|---|
| `DEVICE` | 0 | JIMI (JC400) | `alarm_types` com `protocol='JIMI'` |
| `DEVICE` | 1 | JT/T 808 (JC450/JC181) | `alarm_types` com `protocol='JTT'` + bitmask |
| `ICCID` | — | — | Atualiza ICCID do SIM no registro do dispositivo |
| `VIN` | — | — | Atualiza VIN do veículo no registro do dispositivo |

**Pipeline de resolução de nome JT/T (3 caminhos):**
1. `alertType=256` + `standardAlarmValue` → `decode_standard_alarm_bits()` (32-bit bitmask)
2. `alertType=264/265/266` + `subType` → código composto `264-X` → `alarm_types`
3. `alertType` direto → `alarm_types` com `protocol='JTT'`
4. Fallback: `"Código {alertType} (JTT)"` — nunca cruza protocolos

**Campos JT/T específicos** (extraídos apenas quando `msgClass=1`):
- `alarmSerialNo`, `signalDropChannel`, `standardAlarmValue`, `drivingAlarmFlag`, `overspeedAlarmAdditional`, `fatigueAlarmAdditional`, `reservedAlarmAdditional`, `adasAlarmEnable`, `dmsAlarmEnable`, `bsdAlarmEnable`, `tpmsAlarmEnable`, `channel1-8AlarmFlag`, `videoRelatedFlag`

### 4.2 Dashboard

#### 4.2.1 Entry Points

| Rota | Arquivo | Descrição |
|---|---|---|
| `/` ou `/dashboard` | `handlers/dashboard.php` + `web/dashboard_template.php` | SSR do painel completo |
| `web/index.php` | Wrapper → `handlers/dashboard.php` | Rota canônica alternativa |

#### 4.2.2 Aba: Câmeras (Device Monitor)

**Funcionalidades:**
- Tabela de dispositivos com: nome, IMEI, placa, ignição (ACC), velocidade, satélites, coordenadas, link Google Maps, última comunicação
- Badge de status da API (Online/Offline com cores)
- Player de vídeo HTTP-FLV via **flv.js** (CDN) — stream ao vivo do dispositivo
- Atualização silenciosa a cada 30 segundos via `fetch()` para `/camerasdata`
- Indicador visual de refresh (ponto verde pulsante)

**Endpoint AJAX:** `GET /camerasdata`
- Retorna JSON com `devices[]`, `api_status`, `alarm_count`

#### 4.2.3 Aba: Alarmes

**Funcionalidades:**
- Lista paginada de alarmes com:
  - Severidade codificada por cor na borda esquerda (critical=vermelho, warning=amarelo, info=azul)
  - Nome do alarme resolvido (PT-BR)
  - Coordenadas com link para Google Maps
  - Link para arquivo de mídia associado (se houver)
- Botão **VIDEOUPLOAD** exclusivo para alarmes JT/T (solicita upload de vídeo do dispositivo)
- Filtro por IMEI, data e severidade

#### 4.2.4 Aba: Comandos

**Funcionalidades:**
- **16 presets JIMI** — rastreamento em tempo real (on/off), intervalos de tempo/distância, foto, config servidor/heartbeat, gravação, OBD
- **17 presets JTT** — foto (34817), mídia (34818), query params (33028), query específico (33030), device info (33031), reset (33029), config upload
- Campo de IMEI com validação
- Histórico de comandos com status (sent/pending/response_received)
- **Modal de detalhes** com JSON formatado da resposta do dispositivo
- Contador de respostas offline pendentes
- JTT commands usam formato de timestamp `yyMMddHHmmss` com `serverFlagId=0`

**Endpoint AJAX:** `POST /sendcommand`
- Proxy para o servidor de instruções Jimi (porta 10088)
- Parâmetros: `imei`, `proNo`, `command`, `serverFlagId` (JTT)

**Endpoint AJAX:** `GET /commandstatus`
- Retorna histórico de comandos + contagem de respostas offline

#### 4.2.5 Aba: Mídia

**Funcionalidades:**
- Galeria de cards com ícones por tipo (imagem, vídeo, áudio)
- Thumbnails para imagens, ícones para vídeo/áudio
- Botões de download e playback
- Filtro por IMEI
- Paginação

**Endpoint AJAX:** `GET /mediadata?imei=XXX&page=N`

#### 4.2.6 Aba: Configuração

**Funcionalidades:**
- **Query device info** (proNo 33031) — retorna informações do dispositivo
- **Query all parameters** (proNo 33028) — lista todos os parâmetros configuráveis
- **Query specific parameter** (proNo 33030) — consulta parâmetro individual por ID
- **Set parameter** (proNo 33027) — altera parâmetro individual (ID + valor)
- **Reset** (proNo 33029) — reset de fábrica ou reinicialização
- Interface com campos: IMEI, proNo, parameter ID, valor

#### 4.2.7 AJAX Endpoints Auxiliares

| Endpoint | Handler | Método | Parâmetros | Retorno |
|---|---|---|---|---|
| `/camerasdata` | `camerasdata.php` | GET | — | `devices[]`, `api_status`, `alarm_count` |
| `/commandstatus` | `commandstatus.php` | GET | — | `commands[]`, `offline_count` |
| `/sendcommand` | `sendcommand.php` | POST | `imei`, `proNo`, `command`, `serverFlagId` | `{success, response}` |
| `/mediadata` | `mediadata.php` | GET | `imei?`, `page?` | `files[]`, `total`, `pages` |
| `/trackdata` | `trackdata.php` | GET | `imei`, `start`, `end` | `tracks[]` (GPS points) |
| `/hbdata` | `hbdata.php` | GET | `imeis` (comma-separated) | `heartbeats[]` |

### 4.3 Infrastructure Services

#### 4.3.1 Logger (`core/Logger.php`)

| Característica | Detalhe |
|---|---|
| Níveis | DEBUG, INFO, WARNING, ERROR, CRITICAL |
| Rotação | Diária (`webhook_YYYY-MM-DD.log`) |
| Formato | `[timestamp] [LEVEL] message {"context":"json"}` |
| Request ID | UUID por requisição (`YmdHis-md5hash`) |
| Contexto | JSON estruturado (source, error, memory_mb, http_*) |
| Auto-cleanup | Arquivos >30 dias removidos |
| Performance | Warning automático para operações >500ms |
| Stack traces | Até 5 frames em exceções |
| Fallback | `error_log()` do PHP se escrita em arquivo falhar |

#### 4.3.2 PDO Singleton (`config/database.php`)

- Conexão única por request
- Lê `.env` linha por linha (parse manual, sem biblioteca dotenv)
- Hardcoded fallbacks para ambiente dev (host=localhost, user=root, pass=1029384756)
- Timezone UTC forçado: `SET time_zone = '+00:00'`
- Charset: `utf8mb4`
- Erro mode: `PDO::ERRMODE_EXCEPTION`

#### 4.3.3 Health Check

`GET /ping` → `handlers/ping.php`
- Retorna string literal `"pong"` com HTTP 200
- Sem dependência de banco de dados

---

## 5. Database Schema

### 5.1 Core Tables

| Tabela | Linhas típicas | Descrição | Escrita por | Índices |
|---|---|---|---|---|
| `alarm_types` | ~114 | Lookup: código→nome do alarme (PT/EN), protocolo, severidade | Manual (seed) | `uk_code_protocol`, `idx_protocol`, `idx_severity` |
| `alarms` | Alta | Registro de alarmes com 45 colunas (JIMI + JTT) | `pushalarm.php` | `imei`, `alarm_time`, `gateway_time` |
| `commands` | Média | Comandos enviados com status | `sendcommand.php`, `pushcmd.php` | `imei`, `created_at` |
| `command_responses` | Média | Respostas assíncronas/offline de comandos (v2.0.0+) | `pushinstructresponse.php` | `imei`, `created_at` |
| `device_events` | Baixa | Dados de extensão de terminal | `pushTerminalTransInfo.php` | `imei`, `post_time` |
| `device_statistics` | 1 por dispositivo | Estatísticas agregadas (última posição, contadores) | Stored procedures | `imei` (único) |
| `devices` | 1 por dispositivo | Registro de dispositivos (auto-registro) | Stored procedures | `imei` (único) |
| `events` | Média | Eventos de login/logout | `pushevent.php` | `imei`, `gateway_time` |
| `ftp_uploads` | Baixa | Tracking de uploads FTP | `pushftpfileupload.php` | `imei`, `instruction_id` |
| `gps_data` | Muito alta | Dados GPS com 28 campos | `pushgps.php` | `imei`, `gps_time`, `gateway_time` |
| `heartbeats` | Alta | Heartbeats com 12 campos | `pushhb.php` | `imei`, `gateway_time` |
| `iothub_events` | Baixa | Eventos do Hub (upload begin/end) | `pushiothubevent.php` | `imei`, `gateway_time` |
| `lbs_data` | Baixa | Posicionamento LBS/cell-tower | `pushlbs.php` | `imei`, `gateway_time` |
| `media_files` | Média | Arquivos de mídia (imagem/vídeo/áudio) | `pushfileupload.php`, `pushftpfileupload.php` | `imei`, `gateway_time` |
| `request_logs` | Alta | Idempotência + métricas de performance | `WebhookHandler` (auto) | `payload_hash`, `created_at` |
| `resource_lists` | Baixa | Horários de gravação por canal | `pushresourcelist.php` | `imei`, `channel` |
| `system_info` | 1 linha | Versionamento do sistema | Migration script | — |

### 5.2 Stored Procedures

| Procedure | Chamado por | Parâmetros | Efeito |
|---|---|---|---|
| `update_device_stats_after_gps` | `pushgps.php` | `imei, lat, lng, speed, distance, acc, gps_time, gateway_time, satellites` | Atualiza última posição, odômetro, ACC, data |
| `update_device_stats_after_alarm` | `pushalarm.php` | `imei, alarm_time, gateway_time, lat?, lng?` | Incrementa contador de alarmes, atualiza coordenadas se fornecidas |
| `update_device_stats_after_heartbeat` | `pushhb.php` | `imei, gateway_time, battery, gsm, acc, oil_ele, gps_pos, remote_lock, power_status, fortify` | Atualiza status de bateria, GSM, ACC e flags |
| `update_device_stats_after_event` | `pushevent.php` | `imei, gateway_time, event_type` | Atualiza último evento, incrementa contador |
| `update_alarm_name` | Trigger `trg_alarms_before_insert` | — | Auto-popula `alarm_name` da tabela `alarm_types` |

### 5.3 Stored Function

| Function | Uso | Descrição |
|---|---|---|
| `decode_standard_alarm_bits(bitmask INT)` | `pushalarm.php` (JT/T) | Decodifica bitmask de 32 bits do JT/T 808-2019 em string legível (ex: "Bit0: Emergency, Bit1: Overspeed") |

### 5.4 Trigger

| Trigger | Evento | Ação |
|---|---|---|
| `trg_alarms_before_insert` | BEFORE INSERT em `alarms` | Popula `alarm_name` via join com `alarm_types` |

### 5.5 Views

| View | Descrição |
|---|---|
| `v_alarm_report` | Listagem enriquecida de alarmes |
| `v_alarm_statistics` | Agregações estatísticas de alarmes |
| `v_alarms_enriched` | Alarmes com nomes resolvidos |
| `vw_alarm_types_ambiguous_codes` | Códigos com documentação ambígua |
| `vw_alarm_types_unknown_codes` | Códigos não encontrados na referência |

---

## 6. Protocol Support (ADR-001)

### 6.1 Decisão

**Isolamento estrito de protocolo JIMI vs JT/T 808 em todo o ciclo de vida do alarme.**

| Aspecto | JIMI (msgClass=0) | JT/T 808 (msgClass=1) |
|---|---|---|
| Dispositivos | JC400 series | JC450, JC181 series |
| Campo de alarme | `alertType` | `alertType` + `standardAlarmValue` + `alarmSerialNo` |
| Resolução de nome | `alarm_types` com `protocol='JIMI'` | `alarm_types` com `protocol='JTT'` + bitmask 256 |
| Subtipos | N/A | 264-X (ADAS), 265-X (DMS), 266-X (BSD) |
| Fallback | `"Código 999 (JIMI)"` | `"Código 999 (JTT)"` |
| Canais de vídeo | N/A | `channel1-8AlarmFlag`, `videoRelatedFlag` |

### 6.2 Bitmask JT/T 808 (alertType=256)

A função `decode_standard_alarm_bits()` decodifica o `standardAlarmValue` (32-bit) conforme JT/T 808-2019:
- Bit 0: Emergency alarm
- Bit 1: Overspeed
- Bit 2: Fatigue driving
- Bit 3: Dangerous driving behavior预警
- Bit 4: GNSS module fault
- Bits 5-7: Reserved
- Bit 8: GNSS antenna disconnected/short circuit
- Bits 9-13: Reserved
- Bit 14: GNSS antenna open circuit
- Bits 15-31: Reserved

### 6.3 Alarm Types Reference

A tabela `alarm_types` contém **~114 entradas** cobrindo:
- **71 JIMI codes**: SOS, power cut, vibration, geofences, overspeed, harsh driving (acceleration, braking, turning), ADAS (FCW, LDW, HMW, PCW), DMS (fatigue, phone, smoking, distraction, absent), device status, security
- **43 JTT codes**: Standard alarm (bitmask), video signal loss/blockage, storage fault, abnormal driving, ADAS subtypes (264-1 a 264-12), DMS subtypes (265-1 a 265-18), BSD subtypes (266-X)

Unique key: `uk_code_protocol` (`alarm_code`, `protocol`) — permite mesmo código em protocolos diferentes.

---

## 7. Security & Compliance

### 7.1 Authentication

| Camada | Mecanismo |
|---|---|
| Webhooks | Token fixo (`WEBHOOK_TOKEN`) no POST body |
| Dashboard | Mesmo token, passado via query string `?token=` para AJAX |
| Banco de dados | Credenciais no `.env`, PDO prepared statements |

### 7.2 Anti-Replay

- Hash MD5 do `data_list` completo
- Janela de 10 minutos em `request_logs`
- Verificação **após** envio do HTTP 200 (não bloqueia resposta ao Hub)

### 7.3 Security Headers (.htaccess)

```apache
Header set X-Content-Type-Options "nosniff"
Header set X-Frame-Options "DENY"
Header set X-XSS-Protection "1; mode=block"
Header set Referrer-Policy "strict-origin-when-cross-origin"
```

### 7.4 SQL Injection Prevention

- 100% PDO prepared statements com parâmetros nomeados (`:param`)
- Nenhuma concatenação de strings em queries
- Stored procedures chamadas via `CALL proc(?,?,?)` com array de parâmetros

### 7.5 Known Security Gaps (v2.0.0)

| Gap | Severidade | Mitigação atual |
|---|---|---|
| Token estático sem expiração | Média | Rotação manual via `.env` |
| Sem rate limiting | Baixa | Idempotência cobre replay, não volume |
| Hardcoded fallback credentials | Alta | Produção deve usar `.env`; fallbacks são dev defaults |
| Sem HTTPS enforcement | Média | Responsabilidade do reverse proxy / Apache |
| Token aparece em URLs (query string) | Baixa | Logs de servidor podem conter token |

---

## 8. Non-Functional Requirements

### 8.1 Performance

| Métrica | Target | Atual |
|---|---|---|
| Tempo de resposta ao IoT Hub | <200ms | <50ms (early response antes do processamento) |
| Processamento por item do data_list | <50ms | <20ms típico |
| Tempo total de background (lote de 50) | <5s | ~2-3s |
| Dashboard refresh | <2s | <500ms (AJAX leve) |
| Memory por request | <50MB | ~5MB típico |

### 8.2 Availability

- Single-server, sem HA
- Dependência do Apache + PHP-FPM + MySQL no mesmo host
- Sem fila de mensagens — se PHP-FPM morrer durante background processing, dados são perdidos
- `request_logs` trackeia métricas; alarmes/monitoramento devem detectar gaps

### 8.3 Logging & Observability

| Aspecto | Implementação |
|---|---|
| Logs de aplicação | `logs/webhook_YYYY-MM-DD.log` (JSON context) |
| Métricas de request | `request_logs` table (endpoint, execution_time, payload_hash) |
| Níveis configuráveis | `Logger::setLogLevel()` |
| Retenção | 30 dias (auto-cleanup) |

### 8.4 Timezone Handling

**Regra:** Todo o banco de dados opera em **UTC**. O dashboard converte para **BRT (GMT-3, America/Sao_Paulo)** para exibição.

**Implementação:**
- PDO: `SET time_zone = '+00:00'` na conexão
- Dashboard PHP: `new DateTime($str, new DateTimeZone('UTC'))` → `setTimezone(new DateTimeZone('America/Sao_Paulo'))`
- Dashboard JS: Conversão manual de UTC para BRT

**Gotcha:** Sem o parâmetro explícito de timezone no construtor `DateTime()`, o PHP interpreta a string no timezone local do servidor, causando offset de 3 horas.

---

## 9. Backlog & Roadmap

### 9.1 Endpoints Não Implementados (13)

| # | Seção | Endpoint | Descrição | Prioridade | Complexidade |
|---|---|---|---|---|---|
| 1 | 1.5 | `/rfid` | RFID Data Push | Média | Baixa |
| 2 | 1.6 | `/wgtc` | Plug-In Module Data | Baixa | Média |
| 3 | 1.7 | `/pushoil` | Oil Data | Baixa | Baixa |
| 4 | 1.9 | `/pushtem` | Temperature and Humidity | Baixa | Baixa |
| 5 | 1.14 | `/pushPassThroughData` | Pass-Through Data | Média | Média |
| 6 | 1.17 | `/pushFileContent` | Facial Recognition ID List | Baixa | Média |
| 7 | 1.18 | `/pushextendedkks` | Extended JIMI Protocol 0x07 | Média | Alta |
| 8 | 1.19 | `/uploadCallback` | DVR Real-Time Upload Callback | Alta | Alta |
| 9 | 1.20 | `/generalBusiness` | EV49E Bluetooth MAC Address | Baixa | Baixa |
| 10 | 1.21 | `/pushobd` | OBD Data | Alta | Média |
| 11 | 1.22 | `/pushfaultinfo` | Fault Info | Alta | Média |
| 12 | 1.23 | `/pushtripreport` | Trip Report | Média | Média |
| 13 | 1.24 | (não documentado) | Outros endpoints futuros | — | — |

### 9.2 Features Planejadas (v2.1.0+)

| Feature | Descrição | Prioridade |
|---|---|---|
| **Autenticação multi-token** | Múltiplos tokens com escopos (read/write/admin) | Alta |
| **Rate limiting** | Limite por token/IP para prevenir abuso | Alta |
| **Notificações** | Webhook de saída / email para alarmes críticos | Média |
| **Relatórios exportáveis** | CSV/PDF de tracks, alarmes, eventos | Média |
| **Multi-tenancy** | Isolamento de dados por conta/cliente | Baixa |
| **Fila de mensagens** | Redis/RabbitMQ para garantir processamento (elimina risco de perda no background) | Média |
| **Cobertura OBD** | Handler `/pushobd` + dashboard de diagnóstico veicular | Alta |
| **DVR Callback** | `/uploadCallback` para upload em tempo real de DVR | Alta |
| **Cache de dashboard** | Redis para reduzir carga de queries na atualização de 30s | Baixa |

### 9.3 Dívida Técnica

| Item | Impacto | Esforço |
|---|---|---|
| Hardcoded fallback credentials em `database.php` e `WebhookHandler.php` | Segurança | Baixo |
| Timezone handling frágil (múltiplos `ROOT OF BUG` comments) | Corretude | Médio |
| Dois entry points do dashboard (`web/index.php` wrapper + `/dashboard`) | Manutenção | Baixo |
| `includes/dashboarddata.php` legado não utilizado ativamente | Confusão | Baixo |
| Falta de testes automatizados (zero cobertura) | Qualidade | Alto |
| Parse manual de `.env` (sem biblioteca dotenv) | Robustez | Baixo |
| `DESIGN.md` não relacionado ao projeto no repositório | Organização | Baixo |

---

## 10. Integration & Dependencies

### 10.1 External Services

| Serviço | URL | Propósito | Fallback |
|---|---|---|---|
| Jimi IoT Hub | `jimicloud.com` | Fonte de webhooks push | N/A (dados são push) |
| Jimi Instruction Server | Porta 10088 | Envio de comandos para dispositivos | Timeout tratado |
| File Storage | `FILE_STORAGE_URL` (ex: `http://189.22.240.43:23010/download/`) | Download de arquivos de mídia | Link quebrado se offline |
| Stream Server | `STREAM_URL` (ex: `http://189.22.240.43:8881`) | Streams HTTP-FLV ao vivo/playback | Player mostra erro |
| Google Maps | `maps.google.com` | Links de coordenadas no dashboard | Links abrem em nova aba |
| Bootstrap 5.3 CDN | `cdn.jsdelivr.net` | CSS + JS do dashboard | Dashboard sem estilo se offline |
| Bootstrap Icons CDN | `cdn.jsdelivr.net` | Ícones do dashboard | Ícones ausentes se offline |
| flv.js CDN | `cdn.jsdelivr.net` | Player de vídeo HTTP-FLV | Player não carrega se offline |

### 10.2 Deployment Requirements

| Componente | Requisito |
|---|---|
| PHP | 7.4+ com extensões: `pdo_mysql`, `json`, `mbstring` |
| PHP-FPM | Obrigatório (mod_php não suporta `fastcgi_finish_request`) |
| MySQL | 8.0+ com `utf8mb4_unicode_ci` |
| Apache | 2.4+ com `mod_rewrite` habilitado |
| File System | Escrita em `logs/` para Logger; leitura de `.env` |

### 10.3 Environment Variables

| Variável | Obrigatória | Padrão | Descrição |
|---|---|---|---|
| `DB_HOST` | Sim | `localhost` | MySQL host |
| `DB_PORT` | Não | `3306` | MySQL port |
| `DB_NAME` | Sim | `jimi_tracker` | Database name |
| `DB_USER` | Sim | `root` | MySQL user |
| `DB_PASS` | Sim | `1029384756` | MySQL password |
| `WEBHOOK_TOKEN` | Sim | `a12341234123` | Token de autenticação |
| `SYSTEM_VERSION` | Não | `2.0.0` | Versão do sistema (auto) |
| `FILE_STORAGE_URL` | Não | `http://189.22.240.43:23010/download/` | URL base para arquivos de mídia |
| `STREAM_URL` | Não | `http://189.22.240.43:8881` | URL base para streams HTTP-FLV |

---

## 11. Operational Guide

### 11.1 Fresh Install

```bash
# 1. Configurar .env
cp .env.example .env
# Editar DB_HOST, DB_NAME, DB_USER, DB_PASS, WEBHOOK_TOKEN

# 2. Criar banco de dados
mysql -u root -p < mysql/jimi_tracker.sql

# 3. Executar migração v2.0.0
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql

# 4. Configurar Apache
# - DocumentRoot apontando para o diretório raiz
# - AllowOverride All (para .htaccess)
# - PHP-FPM como handler

# 5. Verificar health
curl http://localhost/ping
# Deve retornar: "pong"
```

### 11.2 Simulating Webhooks (Testing)

```bash
# GPS
curl -X POST http://localhost/pushgps \
  -d 'token=a12341234123' \
  -d 'data_list=[{"deviceImei":"868120246598152","gpsTime":"2026-01-13 02:24:37","lng":113.942885,"lat":22.576539,"gpsSpeed":0,"satelliteNum":7,"acc":1}]'

# Heartbeat
curl -X POST http://localhost/pushhb \
  -d 'token=a12341234123' \
  -d 'data_list=[{"deviceImei":"868120246598152","gateTime":"2026-01-13 02:24:37","powerLevel":90,"gsmSign":80}]'

# Alarm (JIMI)
curl -X POST http://localhost/pushalarm \
  -d 'token=a12341234123' \
  -d 'data_list=[{"type":"DEVICE","msgClass":0,"imei":"752533678900242","msg":{"alertType":1,"lng":113.943102,"lat":22.576649,"gpsSpeed":0},"gateTime":"2026-01-07 11:06:05"}]'

# Alarm (JTT)
curl -X POST http://localhost/pushalarm \
  -d 'token=a12341234123' \
  -d 'data_list=[{"type":"DEVICE","msgClass":1,"imei":"869247060081665","msg":{"alertType":"256","standardAlarmValue":2048,"lng":113.943261,"lat":22.576697,"alarmSerialNo":25075},"gateTime":"2026-01-07 11:06:05"}]'
```

### 11.3 Troubleshooting

| Sintoma | Causa provável | Verificação |
|---|---|---|
| Dashboard em branco | Token inválido na URL | `?token=` deve bater com `.env` |
| Player de vídeo não carrega | `STREAM_URL` inacessível | Verificar CORS e disponibilidade da porta 8881 |
| Webhooks retornam 401 | Token mismatch | Log mostra `token_valid: false` |
| Dados duplicados | Idempotência não está funcionando | Verificar `request_logs` para o `payload_hash` |
| Timezone errado no dashboard | Conversão UTC→BRT dupla | Verificar `new DateTime($str, $tz_utc)` — precisa do 2º parâmetro |
| `fastcgi_finish_request` não definido | Rodando com mod_php em vez de PHP-FPM | `phpinfo()` deve mostrar FPM/FastCGI |
| Logs não estão sendo escritos | Permissão do diretório `logs/` | `chmod 755 logs/` |

### 11.4 Monitoring Queries

```sql
-- Dispositivos sem comunicação nas últimas 24h
SELECT imei, last_gps_time, last_heartbeat_time
FROM device_statistics
WHERE last_heartbeat_time < NOW() - INTERVAL 24 HOUR
   OR last_heartbeat_time IS NULL;

-- Taxa de erro nos webhooks (últimas 24h)
SELECT endpoint, COUNT(*) as errors
FROM request_logs
WHERE response_code >= 400
  AND created_at >= NOW() - INTERVAL 24 HOUR
GROUP BY endpoint;

-- Volume de dados por endpoint (últimas 24h)
SELECT endpoint, COUNT(*) as requests, AVG(execution_time) as avg_ms
FROM request_logs
WHERE created_at >= NOW() - INTERVAL 24 HOUR
GROUP BY endpoint
ORDER BY requests DESC;

-- Payloads rejeitados por idempotência (última hora)
SELECT payload_hash, COUNT(*) as attempts
FROM request_logs
WHERE created_at >= NOW() - INTERVAL 1 HOUR
GROUP BY payload_hash
HAVING COUNT(*) > 1;
```

---

## 12. Glossary & References

### 12.1 Glossary

| Termo | Definição |
|---|---|
| **Jimi IoT Hub** | Plataforma cloud da Jimi (`jimicloud.com`) que recebe dados de dispositivos e os encaminha via webhook |
| **IMEI** | International Mobile Equipment Identity — identificador único de 15 dígitos do dispositivo |
| **JIMI Protocol** | Protocolo proprietário da Jimi, usado por dispositivos JC400 (msgClass=0) |
| **JT/T 808** | Protocolo padrão chinês para comunicação de terminais de veículos comerciais (msgClass=1), usado por JC450/JC181 |
| **msgClass** | Campo que distingue o protocolo: 0=JIMI, 1=JT/T 808 |
| **ADAS** | Advanced Driver Assistance Systems — alertas de colisão, saída de faixa, pedestres |
| **DMS** | Driver Monitoring System — fadiga, distração, telefone, fumante |
| **BSD** | Blind Spot Detection — detecção de ponto cego |
| **LBS** | Location-Based Service — posicionamento por torres de celular (cell tower triangulation) |
| **HTTP-FLV** | Formato de streaming de vídeo sobre HTTP (Flash Video container) |
| **proNo** | Número de protocolo JT/T 808 para identificação de parâmetros (ex: 33027=set, 33028=query all) |
| **serverFlagId** | Identificador único de servidor para correlacionar comandos e respostas JT/T |
| **fastcgi_finish_request** | Função PHP que libera a conexão TCP com o cliente enquanto o script continua executando |
| **Stored Procedure** | Rotina SQL armazenada no banco de dados, chamada pelos handlers para atualizar estatísticas |
| **Idempotência** | Garantia de que processar o mesmo payload múltiplas vezes produz o mesmo resultado (anti-replay) |

### 12.2 References

| Documento | Link |
|---|---|
| API Oficial Jimi IoT Hub | [docs.jimicloud.com/integration/integration.html](https://docs.jimicloud.com/integration/integration.html) |
| JT/T 808-2019 Standard | [中华人民共和国民用航空行业标准](https://www.mot.gov.cn/) |
| ADR-001: Protocol Isolation | [`docs/adr/ADR-001.md`](./adr/ADR-001.md) |
| API Coverage Matrix | [`docs/API_COVERAGE.md`](./API_COVERAGE.md) |
| AGENTS.md (AI Agent Guide) | [`../AGENTS.md`](../AGENTS.md) |
| CHANGELOG | [`../CHANGELOG.md`](../CHANGELOG.md) |
| flv.js | [github.com/bilibili/flv.js](https://github.com/bilibili/flv.js) |
| Bootstrap 5.3 | [getbootstrap.com](https://getbootstrap.com/docs/5.3/) |

### 12.3 Version History

| Versão | Data | Escopo |
|---|---|---|
| 1.0.0 (v3.0.1) | 2026-01-23 | 10 webhook endpoints, dashboard com 3 abas, WebhookHandler abstrato, Logger |
| 2.0.0 | 2026-06-09 | +1 endpoint (1.15), +2 abas (Mídia, Config), correções de spec, protocol isolation ADR, 16+17 presets, flv.js |

---

> **Document Control**  
> Autor: Jimi Webhook System Team  
> Aprovado por: —  
> Próxima revisão: v2.1.0 release
