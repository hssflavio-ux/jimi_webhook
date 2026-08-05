# PLANO DE INFRAESTRUTURA — Jimi Webhook v4.8.3+

> **Objetivo**: dimensionamento de hardware, arquitetura de servidores e projeção de
> crescimento para o produto em produção, partindo de **500 câmeras + 50 usuários**
> com rota de escala documentada.

**Última atualização**: 2026-08-03
**Versão do produto**: 4.8.3 (YUV Parity)

---

## Sumário

1. [Premissas e baseline](#1-premissas-e-baseline)
2. [Arquitetura lógica atual](#2-arquitetura-lógica-atual)
3. [Perfil de carga — 500 câmeras](#3-perfil-de-carga--500-câmeras)
4. [Dimensionamento — Fase 1 (500 câmeras / 50 usuários)](#4-dimensionamento--fase-1-500-câmeras--50-usuários)
5. [Arquitetura de servidores — Fase 1](#5-arquitetura-de-servidores--fase-1)
6. [Projeção de crescimento](#6-projeção-de-crescimento)
7. [Plano de escala](#7-plano-de-escala)
8. [Velocidade de link](#8-velocidade-de-link)
9. [Requisitos de storage](#9-requisitos-de-storage)
10. [Alta disponibilidade e disaster recovery](#10-alta-disponibilidade-e-disaster-recovery)
11. [Segurança de rede](#11-segurança-de-rede)
12. [Monitoramento e alertas](#12-monitoramento-e-alertas)
13. [Custos estimados](#13-custos-estimados)
14. [Checklist de provisionamento](#14-checklist-de-provisionamento)

---

## 1. Premissas e baseline

### 1.1 Stack tecnológico (já homologado)

| Camada | Tecnologia | Versão |
|---|---|---|
| Linguagem | PHP (puro, sem framework) | 8.3 FPM |
| Servidor web | Apache | 2.4 + mod_rewrite |
| Banco de dados | MySQL | 8.0 (InnoDB) |
| Gateway IoT | IoTHub (Docker) | 16 containers |
| SO servidor | Linux (Ubuntu 24.04 LTS) | — |
| Media streaming | FLV/RTP (ingest próprio) | iothub-media |
| Cache geocode | Nominatim interno | — |

### 1.2 Arquitetura de processamento de webhooks

```
Device (câmera) ──► IoTHub (Docker) ──POST──► Apache/FPM ──► handlers/push*.php
                                                      │
                             ┌────────────────────────┴──────────────────────┐
                             │ 1. Valida token + payload hash (idempotência)    │
                             │ 2. fastcgi_finish_request() → HTTP 200 imediato │
                             │ 3. normalize_data() → INSERT → stored proc      │
                             │ 4. occurrence_engine (pushalarm)                │
                             │ 5. notification_engine (ocorrência nova)        │
                             │ 6. commit                                       │
                             └─────────────────────────────────────────────────┘
```

- O processamento pesado roda **após** o HTTP 200 — latência de webhook não é
  gargalo visível ao Hub.
- O padrão assíncrono (`fastcgi_finish_request`) significa que **o PHP-FPM
  mantém o processo filho ocupado** por mais tempo do que o request HTTP dura.
  Isso aumenta o número de workers FPM necessários.

### 1.3 Cron workers (8 processos, via crontab do root)

| # | Worker | Intervalo | Carga estimada (500 cams) |
|---|---|---|---|
| 1 | `worker.php` | 1 min | Média: processa jobs pendentes (relatórios/e-mail/notificações). Carga sob demanda. |
| 2 | `trip_builder.php` | 15 min | Varre `gps_data` incremental por IMEI, haversine. ~4-8s por execução. |
| 3 | `metrics_rollup.php` | 5 min | 22 métricas por cliente. ~1-2s. |
| 4 | `log_cleanup.php` | 1× dia (03:10) | Purga logs e `storage/reports/`. ~1s. |
| 5 | `geofence_worker.php` | 2 min | Avalia pontos novos × cercas vinculadas. ~2-5s. |
| 6 | `state_builder.php` | 15 min | Segmenta `gps_data` em `device_state_segments` + `speeding_events`. O mais pesado: ~10-20s. |
| 7 | `schedule_dispatcher.php` | 1× hora (:05) | Enfileira relatórios agendados. ~1s. |
| 8 | `geocode_worker.php` | 5 min | Aquece cache de endereços (Nominatim). ~0.4s. |

Os workers **7 e 8** são os que consomem menos recurso. O **6** (`state_builder`)
é o mais intensivo em CPU/IO por varrer `gps_data` incrementalmente para todos
os devices.

---

## 2. Arquitetura lógica atual

```
                          ┌──────────────┐
                          │   Internet   │
                          └──────┬───────┘
                                 │
                    ┌────────────┴────────────┐
                    │      Firewall / NAT      │
                    └────────────┬────────────┘
                                 │
              ┌──────────────────┼──────────────────┐
              │                  │                  │
     ┌────────▼────────┐ ┌──────▼──────┐  ┌────────▼────────┐
     │  HTTPS (443)     │ │ TCP 23010  │  │ TCP 8881        │
     │  Dashboard +     │ │ Media Files│  │ FLV Live Stream │
     │  Webhooks + API  │ │ (download) │  │ (HLS/FLV)       │
     └────────┬────────┘ └──────┬──────┘  └────────┬────────┘
              │                 │                  │
     ┌────────▼─────────────────▼──────────────────▼────────┐
     │              Servidor Único (Homolog atual)            │
     │                                                       │
     │  ┌──────────┐  ┌──────────┐  ┌───────────────────┐   │
     │  │  Apache   │  │ PHP-FPM  │  │   MySQL 8.0       │   │
     │  │  2.4      │  │  8.3     │  │   (localhost)     │   │
     │  └──────────┘  └──────────┘  └───────────────────┘   │
     │                                                       │
     │  ┌────────────────────────────────────────────────┐   │
     │  │         IoTHub (16 containers Docker)           │   │
     │  │  ┌──────────┐ ┌──────────┐ ┌────────────────┐  │   │
     │  │  │ iothub   │ │ iothub   │ │ iothub-upload  │  │   │
     │  │  │ core     │ │ media    │ │ process        │  │   │
     │  │  │ :10088   │ │ :8881    │ │ :21188         │  │   │
     │  │  │ (comandos)│ │ :10002   │ │ :23010         │  │   │
     │  │  │          │ │ :10003   │ │                │  │   │
     │  │  └──────────┘ └──────────┘ └────────────────┘  │   │
     │  └────────────────────────────────────────────────┘   │
     │                                                       │
     │  ┌──────────────────────────────┐                    │
     │  │  Nominatim (geocodificação)  │                    │
     │  │  :8080 (container)           │                    │
     │  └──────────────────────────────┘                    │
     │                                                       │
     └───────────────────────────────────────────────────────┘
```

**Observações sobre o homolog atual:**
- Tudo em uma única máquina (Apache + PHP-FPM + MySQL + IoTHub + Nominatim).
- IoTHub tem 16 containers Docker e só alcança o host pelo IP de LAN
  (`10.1.0.43`) — **nunca localhost**.
- O servidor não tem hairpin NAT (não alcança o próprio IP público).
- A working copy é do usuário `www-data` (git pull só com sudo).

---

## 3. Perfil de carga — 500 câmeras

### 3.1 Telemetria de entrada (webhooks)

| Tipo de webhook | Frequência por câmera | Volume total (500 cams) | Pico (rajada) |
|---|---|---|---|
| **GPS** (`pushgps`) | 1 posição a cada 10–30 s (média 30 s) | ~17 req/s (bateladas de ~5–20 posições) | ~50 posições/s em lote |
| **Heartbeat** (`pushhb`) | 1 a cada 60–180 s (média 120 s) | ~4 req/s | ~10 req/s |
| **Alarmes** (`pushalarm`) | 0–50 eventos/dia/câmera (média 10/dia) | ~5.000/dia = 0.06 req/s médio | Rajadas de dezenas/segundo (evento de frota) |
| **Media** (`pushfileupload`/`pushftpfileupload`) | Eventual (vídeo de alarme) | ~1.000–5.000/dia (variável) | Uploads de ~1–30 MB cada |
| **Resource lists** (`pushresourcelist`) | Sob demanda (comando 37381) | ~100–500/dia | — |
| **Command responses** (`pushinstructresponse`) | Por comando enviado | ~50–200/dia | — |
| **Outros** (`pushevent`, `pushlbs`, `pushiothubevent`, `pushTerminalTransInfo`) | Baixo volume | ≤100/dia combinados | — |

**Total estimado (média):**
- ~25 requisições HTTP/segundo para webhooks
- ~200 requisições/segundo em pico (rajada matinal de GPS sincronizada)

### 3.2 Tráfego de saída (dashboard + streaming)

| Componente | 50 usuários simultâneos |
|---|---|
| **Dashboard (páginas)** | ~2–5 req/s (HTML + assets CSS inline, recargas) |
| **AJAX polling** (camerasdata, trackdata, ocorrenciasdata, etc.) | ~10–20 req/s (30s–60s intervalos) |
| **Mapa Leaflet** (tiles OSM) | Cache local recomendado; senão ~50–100 req/s para tile server externo |
| **FLV live streams** (vídeo ao vivo) | 1 stream por câmera assistida (~200–500 kbps cada). Até ~10 streams simultâneos = 2–5 Mbps |
| **Download de mídia** (playback/exportação) | Sob demanda; picos de 1–5 MB/s |

**Total estimado (média):**
- ~25–40 requisições HTTP/segundo para dashboard + AJAX
- ~2–10 Mbps de streaming de vídeo (dependendo de quantos usuários assistem ao vivo)

### 3.3 Carga de banco de dados (MySQL)

| Operação | Volume | Observação |
|---|---|---|
| **INSERTs** (gps_data) | ~17 rows/s (média) / ~50 rows/s (pico) | Bateladas de 5–20 linhas por request |
| **INSERTs** (heartbeats) | ~4 rows/s | Upsert em device_statistics |
| **INSERTs** (alarms) | ~0.06 rows/s (média) / ~50 rows/s (pico de frota) | + occurrence_engine (SELECT + INSERT occurrence) |
| **INSERTs** (outras tabelas) | ~1–5 rows/s | media_files, request_logs, geofence_events, etc. |
| **SELECTs** (dashboard/queries) | 10–30 queries/s | Páginas + AJAX + relatórios |
| **UPDATEs** (device_statistics, jobs, device_state_segments) | 5–10 rows/s | ON DUPLICATE KEY UPDATE |
| **Workers** (trip_builder, state_builder) | Varreduras pesadas a cada 15 min | Lê incremental de gps_data por IMEI |

**Total estimado de queries MySQL:**
- Média: ~40–80 queries/s
- Pico: ~150 queries/s (rajada de webhooks + usuários ativos)

### 3.4 Uso de disco (IOPS)

| Operação | Volume |
|---|---|
| **Escrita** (INSERT/UPDATE) | ~30–80 operações/s |
| **Leitura** (SELECT do dashboard + workers) | ~20–100 operações/s |
| **IOPS total estimado** | ~100–200 IOPS (média) / ~400 IOPS (pico) |

---

## 4. Dimensionamento — Fase 1 (500 câmeras / 50 usuários)

### 4.1 Servidor de aplicação (Apache + PHP-FPM + IoTHub)

| Recurso | Mínimo | Recomendado | Justificativa |
|---|---|---|---|
| **CPU** | 8 vCPUs | **16 vCPUs** | Cada request PHP-FPM é um processo. Com ~25 req/s de webhook + processo assíncrono (fastcgi_finish_request mantém o worker ocupado), ~50 workers FPM ativos simultaneamente. O IoTHub (16 containers) consome ~4–6 vCPUs sozinho. Workers cron consomem picos de CPU. |
| **RAM** | 16 GB | **32 GB** | PHP-FPM: 50 workers × ~80 MB = 4 GB. IoTHub: 16 containers × ~300–500 MB = 6–8 GB. MySQL: ~8–12 GB (buffer pool + conexões). Nominatim: ~2–4 GB. Sistema + buffer: ~4 GB. |
| **Disco (OS + app)** | 100 GB SSD | **200 GB NVMe** | Sistema + aplicação PHP (~50 MB) + IoTHub + Nominatim (~40 GB índice). NVMe pela latência — os workers varrem gps_data com leitura sequencial. |
| **Disco (dados MySQL)** | 200 GB SSD | **500 GB NVMe** | Dados brutos ~15–20 GB/mês com 500 cams (ver §9). Com 6 meses de retenção: ~120 GB + índices (2–3× dados) = ~360 GB. Margem de 30%. |
| **Disco (mídia)** | 500 GB | **1 TB SSD ou HDD** | Uploads de vídeo (pushfileupload). ~1–5 GB/dia com 500 cams. Retenção de 90 dias: ~450 GB. |

### 4.2 PHP-FPM (pool www)

| Parâmetro | Valor | Justificativa |
|---|---|---|
| `pm` | `dynamic` | Melhor relação cpu/memória |
| `pm.max_children` | **100** | 25 req/s webhook + processos assíncronos mantidos + 25 req/s dashboard |
| `pm.start_servers` | 20 | Aquecimento inicial |
| `pm.min_spare_servers` | 10 | Reserva mínima |
| `pm.max_spare_servers` | 40 | Teto de ociosos |
| `pm.max_requests` | 500 | Recicla workers periodicamente (previne memory leaks) |
| `request_terminate_timeout` | 120s | Mata processo travado; workers cron podem rodar por minutos, mas são CLI (não passam pelo FPM) |
| `memory_limit` | 256M | Cada worker. Exportação de relatório pode consumir mais — o worker.php roda via CLI com `memory_limit = 512M` |
| `max_execution_time` | 60s | Via FPM; CLI workers usam `set_time_limit(0)` |

### 4.3 MySQL 8.0

| Parâmetro | Valor | Justificativa |
|---|---|---|
| `innodb_buffer_pool_size` | **8 GB** | 500 cams produzem ~40M linhas/mês em gps_data (tabela mais quente). Buffer pool cobre índice + dados quentes. |
| `innodb_log_file_size` | **2 GB** | Reduz checkpoint frequency com volume de escrita |
| `innodb_flush_log_at_trx_commit` | `2` | Performance: flush a cada 1s em vez de a cada commit. Aceitável para telemetria (dado de GPS perdido em crash de 1s é recuperável). |
| `innodb_io_capacity` | **2000** (NVMe) | Ajustado ao storage |
| `max_connections` | **200** | 50 workers FPM + workers CLI + buffer |
| `query_cache_type` | `0` (OFF) | Descontinuado no MySQL 8.0; usar application-level cache se necessário |
| `table_open_cache` | 4000 | ~55 tabelas no schema, muitas conexões |
| `tmp_table_size` / `max_heap_table_size` | 64M | Relatórios com GROUP BY em tabelas derivadas |
| `slow_query_log` | ON (`long_query_time = 2`) | Essencial para identificar queries degradadas com o crescimento |
| `binlog_format` | ROW | Para point-in-time recovery |
| `expire_logs_days` | 7 | Rotação de binlogs |

### 4.4 Apache 2.4 (mpm_event)

| Parâmetro | Valor | Justificativa |
|---|---|---|
| `StartServers` | 4 | Inicial |
| `MinSpareThreads` | 25 | Reserva |
| `MaxSpareThreads` | 100 | Teto de ociosos |
| `ThreadsPerChild` | 25 | Threads por processo |
| `MaxRequestWorkers` | **250** | Equivalente ao MaxClients antigo. 50 usuários × ~5 conexões simultâneas (keep-alive) |
| `MaxConnectionsPerChild` | 10000 | Recicla processos periodicamente |
| `Timeout` | 60 | Conexões lentas |
| `KeepAlive` | On | Essencial para dashboard (múltiplos assets) |
| `KeepAliveTimeout` | 5 | Curto para liberar workers rápido |
| `MaxKeepAliveRequests` | 100 | Por conexão |

> **Nota sobre o streaming**: FLV ao vivo (porta 8881) e download de mídia (porta
> 23010) **não passam pelo Apache** — são servidos diretamente pelo
> `iothub-media` (container Docker). Isso alivia o Apache de tráfego de streaming.

### 4.5 IoTHub (Docker)

| Recurso | Valor | Observação |
|---|---|---|
| Containers | 16 | Conforme homolog atual |
| CPU alocada | 4–6 vCPUs (compartilhadas) | Pode ser limitado via `--cpus` |
| RAM alocada | 6–8 GB | Monitorar e ajustar |
| Rede | `host` ou bridge com port mapping | O IoTHub precisa alcançar os devices na internet e o PHP no host |

---

## 5. Arquitetura de servidores — Fase 1

### 5.1 Recomendação: 1 servidor bare-metal ou VPS dedicado

Para 500 câmeras iniciais, **um único servidor bem dimensionado** é suficiente e
reduz complexidade operacional. A separação em múltiplos servidores é recomendada
a partir de ~2.000 câmeras (ver §7).

```
┌──────────────────────────────────────────────────────────────┐
│                  Servidor Principal                           │
│                                                              │
│  CPU: 16 vCPUs (ex.: AMD EPYC ou Intel Xeon Scalable)        │
│  RAM: 32 GB ECC                                              │
│                                                              │
│  ┌──────────────────────────────────────────────────────┐    │
│  │ Disco 1: NVMe 200 GB — SO + App + IoTHub + Nominatim │    │
│  │ Disco 2: NVMe 500 GB — MySQL (dados + índices)       │    │
│  │ Disco 3: SSD/HDD 1 TB — Mídia (storage/media)        │    │
│  └──────────────────────────────────────────────────────┘    │
│                                                              │
│  Rede: 1 Gbps (dedicado, não compartilhado)                  │
│  IP público fixo                                             │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

### 5.2 Por que 1 Gbps de link?

| Tráfego | Downstream (entrada) | Upstream (saída) |
|---|---|---|
| Webhooks (GPS/HB/alarm) | ~1–5 Mbps constante | ~0.1 Mbps (ACKs) |
| Upload de vídeo (pushfileupload) | ~10–50 Mbps em rajada | ~0.1 Mbps |
| Dashboard (páginas HTML) | — | ~1–5 Mbps |
| AJAX JSON (camerasdata, etc.) | — | ~2–5 Mbps |
| FLV streaming (até 10 cams × 500 kbps) | — | ~5 Mbps |
| Download de mídia (usuários) | — | ~10–50 Mbps (rajada) |
| Tiles de mapa (OSM, sem cache local) | — | ~5–10 Mbps |
| **Total médio** | ~10 Mbps | ~30 Mbps |
| **Total pico** | ~60 Mbps | ~100 Mbps |

> **100 Mbps é o MÍNIMO VIÁVEL.** Com 500 câmeras, rajadas de upload de vídeo
> podem saturar um link de 100 Mbps momentaneamente, causando latência no
> dashboard. **1 Gbps oferece folga de 10× no pico**, garante que o dashboard
> nunca seja afetado por uploads de vídeo concomitantes e permite crescimento até
> ~2.000 câmeras sem upgrade de link.

### 5.3 Diagrama de rede — Fase 1

```
                         ┌──────────────┐
                         │   Internet   │
                         └──────┬───────┘
                                │
                                │ 1 Gbps dedicado
                                │ IP público fixo
                                │
                    ┌───────────┴───────────┐
                    │     Firewall / UFW     │
                    │                        │
                    │  Portas abertas:       │
                    │  22   (SSH — restrito) │
                    │  80   (HTTP → 443)     │
                    │  443  (HTTPS)          │
                    │  8881 (FLV stream)     │
                    │  10002 (RTP ingest)    │
                    │  10003 (Playback)      │
                    │  21188 (Attachment)    │
                    │  23010 (Media download)│
                    └───────────┬───────────┘
                                │
                    ┌───────────┴───────────┐
                    │   Servidor Principal   │
                    │   (ver specs acima)    │
                    └───────────────────────┘
```

> **Portas expostas diretamente**: 8881, 10002, 10003, 21188 e 23010 são
> acessadas pelos **dispositivos Jimi diretamente** (não passam pelo Apache).
> Precisam estar abertas ao público. Se possível, restringir por geolocalização
> (faixa de IPs da operadora móvel dos chips) ou usar um proxy TCP com rate
> limiting.

---

## 6. Projeção de crescimento

### 6.1 Curva de adoção projetada

| Marco | Câmeras | Usuários | Tempo estimado | Complexidade |
|---|---|---|---|---|
| **Lançamento** | 500 | 50 | Mês 1 | Servidor único |
| **Crescimento inicial** | 1.000 | 100 | Mês 3–6 | Servidor único (folga) |
| **Escala 1** | 2.000 | 200 | Mês 9–12 | Servidor único no limite — começar split |
| **Escala 2** | 5.000 | 500 | Ano 2 | Arquitetura distribuída (2–3 servidores) |
| **Escala 3** | 10.000+ | 1.000+ | Ano 3+ | Cluster com réplicas, balanceador |

### 6.2 Gatilhos para upgrade (o que monitorar)

| Métrica | Limite | Ação |
|---|---|---|
| CPU (load average) | > 70% sustentado por > 5 min | Investigar queries lentas ou escalar CPU |
| RAM utilizada | > 80% (sem buffer/cache) | Adicionar RAM ou dividir MySQL para servidor próprio |
| MySQL slow queries | > 5% das queries | Otimizar índices; considerar read replica |
| Disco MySQL (IOPS) | > 80% do provisionado | Migrar para NVMe mais rápido ou separar em volume dedicado |
| PHP-FPM queue | > 10 requests enfileirados | Aumentar pm.max_children |
| Webhook latency (p95) | > 500ms (antes do async) | Escalar PHP-FPM workers ou CPU |
| Link utilization | > 70% sustentado | Upgrade para 2 Gbps ou link dedicado de 10 Gbps |
| `gps_data` rows/month | > 100M | Particionar tabela ou arquivar dados frios |
| Cron worker atraso | Acumula > 2 execuções | Paralelizar worker por faixa de IMEI ou aumentar intervalo |
| Dashboard page load | > 3s (p95) | Read replica, cache de query, ou otimização de frontend |

### 6.3 Curvas de carga por componente

```
Webhooks (req/s)
 1000 │
  800 │                                    ╭─ 10k cams
  600 │                          ╭─────────╯
  400 │                ╭─────────╯
  200 │      ╭─────────╯
  100 │──────╯
    0 └────────────────────────────────────────────
       500      2k       5k       10k      20k
                  Câmeras conectadas

MySQL (GB de dados acumulados — 6 meses de retenção)
 2400 │                                    ╭─ 10k cams (60M rows/dia)
 1800 │                          ╭─────────╯
 1200 │                ╭─────────╯
  600 │      ╭─────────╯
   90 │──────╯ 500 cams
    0 └────────────────────────────────────────────
       500      2k       5k       10k      20k
```

---

## 7. Plano de escala

### 7.1 Fase 1 — Servidor único (até 2.000 câmeras)

**Configuração**: conforme §4 e §5.

**Limitações conhecidas:**
- MySQL é o primeiro gargalo: com 2.000 câmeras, `gps_data` recebe ~60M
  linhas/mês (~18 GB/mês brutos). O buffer pool de 8 GB começa a ficar
  pressionado.
- Workers cron (state_builder, trip_builder) começam a competir por IO com o
  MySQL de produção.
- Picos de rajada de alarmes (ex.: acionamento em massa de DMS numa frota)
  podem congestionar o occurrence_engine.

### 7.2 Fase 2 — Split MySQL (2.000–5.000 câmeras)

```
┌──────────────────┐     ┌──────────────────┐
│  Servidor App     │     │  Servidor MySQL   │
│                    │     │                    │
│  Apache + FPM     │────▶│  MySQL 8.0         │
│  IoTHub (Docker)  │     │  32 GB RAM         │
│  Nominatim        │     │  16 vCPUs          │
│  Workers cron     │     │  NVMe 1 TB (RAID1) │
│                    │     │                    │
│  16 vCPUs         │     │  Link interno:     │
│  32 GB RAM        │     │  10 Gbps (LAN)     │
│  200 GB NVMe      │     │                    │
│  1 TB mídia       │     │                    │
└──────────────────┘     └──────────────────┘
         │
         │ 1 Gbps público
         ▼
     Internet
```

**Mudanças:**
- MySQL em servidor dedicado com **32 GB RAM** e `innodb_buffer_pool_size = 24 GB`.
- Conexão app→MySQL via **rede interna 10 Gbps** (latência < 0.5ms).
- Workers cron continuam no servidor de app (acessam MySQL via rede).
- IoTHub continua no servidor de app (precisa escrever no MySQL para comandos).
- Backup do MySQL com `mysqldump` ou `Percona XtraBackup` (não bloqueante).

### 7.3 Fase 3 — Read replica + Balanceador (5.000–10.000+ câmeras)

```
                         ┌─────────────────┐
                         │   Balanceador    │
                         │   (Nginx/HAProxy)│
                         └────────┬────────┘
                                  │
              ┌───────────────────┼───────────────────┐
              │                   │                   │
     ┌────────▼────────┐ ┌───────▼───────┐  ┌────────▼────────┐
     │  App Server 1   │ │  App Server 2 │  │  App Server N   │
     │  (Apache+FPM)   │ │  (Apache+FPM) │  │  (Apache+FPM)   │
     └────────┬────────┘ └───────┬───────┘  └────────┬────────┘
              │                   │                   │
              └───────────────────┼───────────────────┘
                                  │
                    ┌─────────────┴─────────────┐
                    │                           │
          ┌─────────▼─────────┐    ┌────────────▼──────────┐
          │  MySQL Primary     │    │  MySQL Read Replica 1  │
          │  (escritas)        │───▶│  (dashboards/relatórios)│
          └───────────────────┘    └────────────────────────┘
                                               │
                                   ┌───────────▼───────────┐
                                   │  MySQL Read Replica 2  │
                                   │  (workers cron)        │
                                   └───────────────────────┘
```

**Mudanças:**
- Balanceador de carga (Nginx ou HAProxy) na frente dos servidores de app.
- Sessões PHP migradas para **Redis** (compartilhado entre app servers) ou
  `sessions` table já resolve (está no MySQL).
- Workers cron apontam para réplicas de leitura (evitam competir com escritas).
- Dashboard aponta para réplicas de leitura.
- Escritas (webhooks) continuam na primary.
- IoTHub dedicado em servidor próprio (ou mantido no app server 1).
- **Mídia** movida para storage de objeto (S3 compatível) ou NFS compartilhado.

### 7.4 Fase 4 — Cluster completo (10.000+ câmeras)

- Particionamento de `gps_data` por tempo (mensal).
- Arquivamento de dados frios (> 6 meses) para storage object.
- MySQL com Group Replication ou InnoDB Cluster.
- CDN para tiles de mapa e assets estáticos.
- IoTHub em cluster próprio com balanceamento de devices.
- Cache Redis para queries frequentes (dashboard KPIs, device status).
- Workers paralelizados por shard de IMEI.

---

## 8. Velocidade de link

### 8.1 Recomendação por fase

| Fase | Câmeras | Link recomendado | Tipo |
|---|---|---|---|
| Lançamento | 500 | **1 Gbps** dedicado | Fibra empresarial |
| Crescimento | 2.000 | **1 Gbps** (ainda suficiente) | Mesmo link |
| Escala 2 | 5.000 | **2–5 Gbps** | Upgrade ou agregação |
| Escala 3 | 10.000+ | **10 Gbps** | Fibra dedicada empresarial |

### 8.2 O que o link precisa suportar

| Tipo de tráfego | Direção | 500 cams | 2.000 cams | 5.000 cams |
|---|---|---|---|---|
| Webhooks (entrada) | ↓ | 5–20 Mbps | 20–80 Mbps | 50–200 Mbps |
| Upload de vídeo (entrada) | ↓ | 10–50 Mbps | 40–200 Mbps | 200–500 Mbps |
| Dashboard (saída) | ↑ | 5–15 Mbps | 20–60 Mbps | 50–150 Mbps |
| Streaming FLV (saída) | ↑ | 5–25 Mbps | 20–100 Mbps | 50–250 Mbps |
| Download mídia (saída) | ↑ | 10–50 Mbps | 40–200 Mbps | 200–500 Mbps |
| **Total pico** | | **~140 Mbps** | **~640 Mbps** | **~1.6 Gbps** |

> **Com 500 câmeras, 1 Gbps é confortável.** O pico teórico fica em ~14% do link.
> A partir de 5.000 câmeras, 1 Gbps começa a ser pressionado nos picos de upload
> de vídeo simultâneo. O upgrade deve ser planejado junto com a Fase 2 de
> servidores.

### 8.3 Características desejáveis do link

- **IP público fixo** (obrigatório — os devices Jimi são configurados com IP
  fixo do servidor para envio de telemetria e comandos).
- **Banda simétrica** (upload = download). O tráfego de saída (streaming +
  dashboard + download) pode ser maior que o de entrada em certos horários.
- **SLA empresarial** com 99.5%+ de uptime e tempo de reparo ≤ 4h.
- **Sem CGNAT** — o servidor precisa ser alcançável diretamente da internet.
- **Suporte a IP failover** (para migração futura entre datacenters).

---

## 9. Requisitos de storage

### 9.1 Projeção de crescimento de dados (6 meses de retenção)

| Tabela | 500 cams | 2.000 cams | 5.000 cams | 10.000 cams |
|---|---|---|---|---|
| `gps_data` | 43M rows/mês × 6 = 258M rows (~77 GB) | 172M rows/mês (~52 GB/mês) | 430M rows/mês (~129 GB/mês) | 860M rows/mês (~258 GB/mês) |
| `heartbeats` | 7.2M rows/mês (~9 GB em 6m) | 28.8M rows/mês | 72M rows/mês | 144M rows/mês |
| `alarms` | 150K rows/mês (~450 MB em 6m) | 600K rows/mês | 1.5M rows/mês | 3M rows/mês |
| `device_state_segments` | ~150K rows/mês (~130 MB em 6m) | ~600K rows/mês | ~1.5M rows/mês | ~3M rows/mês |
| `trips` | ~75K rows/mês (~135 MB em 6m) | ~300K rows/mês | ~750K rows/mês | ~1.5M rows/mês |
| `geofence_events` | ~10K rows/mês (~5 MB em 6m) | ~40K rows/mês | ~100K rows/mês | ~200K rows/mês |
| `speeding_events` | ~20K rows/mês (~20 MB em 6m) | ~80K rows/mês | ~200K rows/mês | ~400K rows/mês |
| `media_files` (metadados) | ~30K rows/mês (~15 MB em 6m) | ~120K rows/mês | ~300K rows/mês | ~600K rows/mês |
| Outras tabelas | ~30 GB combinadas | ~60 GB | ~100 GB | ~150 GB |
| **Total dados + índices** | **~180 GB** | **~550 GB** | **~1.3 TB** | **~2.5 TB** |

> Índices tipicamente representam 2–3× o tamanho dos dados brutos para tabelas
> de alta ingestão como `gps_data`. Os valores acima já incluem índices.

### 9.2 Mídia (vídeos de evento)

| Câmeras | Vídeos/dia (média) | MB/dia | GB/mês | 90 dias (recomendado) |
|---|---|---|---|---|
| 500 | 2.000 | ~5–10 GB | 150–300 GB | **450–900 GB** |
| 2.000 | 8.000 | ~20–40 GB | 600 GB–1.2 TB | **1.8–3.6 TB** |
| 5.000 | 20.000 | ~50–100 GB | 1.5–3 TB | **4.5–9 TB** |

> **Política de retenção de mídia**: `storage/media` (vídeos de ocorrência) **não
> são purgados** pelo `log_cleanup.php` — são evidência. Na Fase 3+, migrar para
> **S3 compatível** (Wasabi, Backblaze B2, AWS S3) com política de ciclo de vida
> (ex.: 90 dias em hot storage, depois archive).

### 9.3 Estratégia de particionamento (Fase 2+)

Quando `gps_data` ultrapassar ~500M linhas, particionar por **mês**:

```sql
ALTER TABLE gps_data PARTITION BY RANGE (TO_DAYS(gps_time)) (
    PARTITION p202608 VALUES LESS THAN (TO_DAYS('2026-09-01')),
    PARTITION p202609 VALUES LESS THAN (TO_DAYS('2026-10-01')),
    ...
);
```

- Workers cron (`trip_builder`, `state_builder`) só varrem partições recentes.
- Partições antigas (> 6 meses) podem ser arquivadas ou movidas para storage
  mais barato.
- Facilita `DROP PARTITION` em vez de `DELETE` massivo (instantâneo, sem
  fragmentação).

---

## 10. Alta disponibilidade e disaster recovery

### 10.1 Fase 1 (servidor único)

| Componente | Estratégia | RPO | RTO |
|---|---|---|---|
| **Banco MySQL** | Backup diário (`mysqldump` ou `Percona XtraBackup`) + binlog contínuo | 24h (pode ser reduzido para 1h com binlog) | 2–4h (restauração + validação) |
| **Arquivos PHP** | Git (`origin/main` é a fonte da verdade). `deploy.sh` recria o ambiente. | 0 (código no GitHub) | 15 min |
| **Mídia** (`storage/`) | Rsync diário para storage externo (ou S3 a cada hora) | 24h | 1–2h (download de volta) |
| **Configuração** (`.env`) | Backup manual após cada alteração | Manual | 30 min |
| **IoTHub** | Docker compose versionado. Reconstruível. | — | 30 min |
| **Servidor físico** | Contrato de SLA com provedor (substituição de hardware) | — | ≤ 4h (SLA) |

### 10.2 Fase 2+ (split MySQL)

- **MySQL replicação**: master → slave com binlog. Failover semi-automático
  (promover slave).
- **Backup na réplica**: evita carga no master durante o dump.
- **Storage de mídia externo** (S3 compatível): desacopla mídia do servidor.

### 10.3 Procedimento de backup diário

```bash
# Backup MySQL (sem bloquear — InnoDB com --single-transaction)
mysqldump --single-transaction --routines --triggers --events \
  -u root -p jimi_tracker | gzip > /backup/mysql/jimi_$(date +%Y%m%d).sql.gz

# Backup incremental de mídia
rsync -avz /var/www/jimi_webhook/storage/media/ /backup/media/

# Backup do .env (contém credenciais)
cp /var/www/jimi_webhook/.env /backup/config/.env.$(date +%Y%m%d)

# Reter 7 dias de backup local + cópia semanal para off-site
find /backup/mysql/ -mtime +7 -delete
```

---

## 11. Segurança de rede

### 11.1 Firewall (UFW / iptables)

```
# Política padrão: negar entrada, permitir saída
ufw default deny incoming
ufw default allow outgoing

# SSH — restrito ao IP do administrador
ufw allow from <ADMIN_IP> to any port 22 proto tcp

# HTTPS (dashboard + webhooks)
ufw allow 443/tcp

# HTTP → redirecionar para HTTPS
ufw allow 80/tcp

# IoTHub — portas alcançáveis pelos devices
# ⚠️ Restringir por faixa de IP das operadoras M2M se conhecida
ufw allow 8881/tcp   # FLV live stream
ufw allow 10002/tcp  # RTP ingest (devices publicam stream)
ufw allow 10003/tcp  # Playback
ufw allow 21188/tcp  # Attachment upload
ufw allow 23010/tcp  # Media file download

# MySQL — APENAS localhost (ou rede interna na Fase 2)
ufw allow from 127.0.0.1 to any port 3306 proto tcp
# Na Fase 2 (servidor MySQL separado):
# ufw allow from <APP_SERVER_IP> to any port 3306 proto tcp

# Nominatim — apenas localhost (app consulta via localhost)
ufw allow from 127.0.0.1 to any port 8080 proto tcp

ufw enable
```

### 11.2 Rate limiting (nível Apache / PHP)

O sistema já tem rate limiting no **login** (5 tentativas / 15 min, tabela
`login_log`). Para proteger webhooks contra abuso de volume, adicionar no
Apache (mod_ratelimit) ou no PHP:

```php
// Rate limit por IP de origem (webhooks vêm do IoTHub, IP fixo)
// 500 req/s é o teto realista com 500 cams em rajada
```

### 11.3 TLS/SSL

- **HTTPS obrigatório** no dashboard (Let's Encrypt com renovação automática
  via certbot).
- Portas do IoTHub (8881, 10002, etc.) são TCP puro (os devices embarcados
  Jimi não suportam TLS). Isso é aceitável pois são portas de ingest de
  telemetria e streaming, não de autenticação.
- Cookies configurados: `Secure` (true), `HttpOnly` (true), `SameSite=Lax`
  (já implementado em `includes/auth.php`).

---

## 12. Monitoramento e alertas

### 12.1 Métricas a monitorar

| Camada | Métrica | Ferramenta | Alerta |
|---|---|---|---|
| **Sistema** | CPU, RAM, disco, load average | Netdata / Prometheus + Node Exporter | > 80% por 5 min |
| **PHP-FPM** | Active workers, queue length, slow requests | PHP-FPM status page + Netdata | Queue > 10 por 1 min |
| **Apache** | Requests/s, workers busy, latency p95 | Apache mod_status + Netdata | Workers busy > 80% |
| **MySQL** | Queries/s, slow queries, connections, buffer pool hit ratio | Netdata MySQL plugin / Percona PMM | Slow queries > 5%, buffer hit < 95% |
| **IoTHub** | Container status, CPU/RAM por container | `docker stats` + Netdata cgroups | Container down > 30s |
| **Cron workers** | Última execução, exit code, duração | Script customizado (ver abaixo) | Última execução > 2× o intervalo |
| **Disco** | Uso % e crescimento diário | Netdata | > 80% ou crescimento anormal |
| **Link** | Utilização % e erros | Netdata / iftop | > 70% sustentado |
| **Aplicação** | `/ping` endpoint, erros 5xx | Script customizado (curl) | `/ping` != 200 ou versão errada |
| **SSL** | Validade do certificado | certbot renew --dry-run | < 30 dias para expirar |

### 12.2 Healthcheck dos cron workers

```bash
#!/bin/bash
# scripts/healthcheck-workers.sh
# Verifica se todos os workers rodaram dentro do esperado

WORKERS=(
    "worker.php:2"        # máximo 2 min desde última execução
    "trip_builder.php:20"
    "metrics_rollup.php:7"
    "log_cleanup.php:1500" # 25h (roda 1x/dia)
    "geofence_worker.php:4"
    "state_builder.php:20"
    "schedule_dispatcher.php:65"
    "geocode_worker.php:7"
)

for entry in "${WORKERS[@]}"; do
    script="${entry%%:*}"
    max_min="${entry##*:}"
    logfile="logs/${script%.php}.log"

    if [ ! -f "$logfile" ]; then
        echo "CRITICAL: $logfile não existe"
        continue
    fi

    last_ts=$(stat -c %Y "$logfile")
    now=$(date +%s)
    age_min=$(( (now - last_ts) / 60 ))

    if [ $age_min -gt $max_min ]; then
        echo "CRITICAL: $script — última execução há ${age_min}min (limite: ${max_min}min)"
    else
        echo "OK: $script — ${age_min}min atrás"
    fi
done
```

### 12.3 Logs

| Componente | Localização | Rotação |
|---|---|---|
| Apache access/error | `/var/log/apache2/` | logrotate (diário, 30 dias) |
| PHP-FPM | `/var/log/php*-fpm.log` | logrotate |
| MySQL | `/var/log/mysql/` | logrotate |
| App webhooks | `logs/webhook_YYYY-MM-DD.log` | `log_cleanup.php` (diário) |
| App workers | `logs/worker.log`, `logs/trip_builder.log`, etc. | `log_cleanup.php` |
| IoTHub containers | `docker logs` → journald | journald |

---

## 13. Custos estimados

### 13.1 Fase 1 — Servidor único (500 câmeras)

| Item | Especificação | Custo mensal estimado (USD) |
|---|---|---|
| **Servidor bare-metal** | 16 vCPUs, 32 GB RAM, 2× NVMe 500 GB, 1× SSD 1 TB | $250–$400 |
| **OU VPS dedicado** | 16 vCPUs, 32 GB RAM, 600 GB NVMe, 1 TB block storage | $300–$500 |
| **Link 1 Gbps** | Fibra dedicada empresarial, IP fixo | $100–$300 |
| **IPs adicionais** | 1–2 IPs (failover) | $10–$20 |
| **Backup storage** | 500 GB (S3 compatível ou servidor externo) | $10–$25 |
| **SSL** | Let's Encrypt (gratuito) | $0 |
| **Monitoramento** | Netdata (gratuito, self-hosted) | $0 |
| **Total mensal** | | **$370–$845** |

> Preços variam por região e provedor. No Brasil, bare-metal com essas specs
> (ex.: Locaweb, HostDime, EVEO) tende a custar R$ 1.500–R$ 3.500/mês.
> Alternativa: locação no exterior (Hetzner, OVH) para redução de custo, mas
> com latência maior para devices no Brasil.

### 13.2 Fase 2 — Split MySQL (2.000–5.000 câmeras)

| Item | Custo adicional/mês |
|---|---|
| Servidor MySQL dedicado (32 GB, 16 vCPUs, 1 TB NVMe) | $250–$400 |
| Link interno 10 Gbps (ou mesmo rack) | $0 (mesmo DC) |
| **Total adicional** | **$250–$400** |

### 13.3 Fase 3 — Balanceador + Réplicas (5.000+ câmeras)

| Item | Custo adicional/mês |
|---|---|
| 2+ servidores de app (cada) | $150–$250 |
| 1+ réplica MySQL (cada) | $200–$350 |
| Balanceador (pode ser software no app server) | $0 |
| Storage de objeto (S3) para mídia | $50–$200 |
| Redis (cache) | $0 (self-hosted no app server) |
| **Total adicional** | **$600–$1.400** |

---

## 14. Checklist de provisionamento

### Antes do lançamento (Fase 1)

- [ ] Contratar servidor com specs de §4.1.
- [ ] Contratar link dedicado 1 Gbps com IP fixo (§8.1).
- [ ] Instalar SO (Ubuntu 24.04 LTS), configurar SSH apenas por chave.
- [ ] Configurar firewall (UFW) conforme §11.1.
- [ ] Instalar stack: Apache 2.4 + PHP 8.3 FPM + MySQL 8.0.
- [ ] Instalar extensões PHP: `pdo`, `pdo_mysql`, `json`, `mbstring`, `zip`,
  `openssl`, `curl`. Conferir com `deploy.sh` (já valida).
- [ ] Configurar PHP-FPM pool conforme §4.2.
- [ ] Configurar MySQL conforme §4.3 (incluindo `innodb_buffer_pool_size`).
- [ ] Instalar IoTHub (Docker + 16 containers, conforme homolog).
- [ ] Instalar Nominatim interno para geocodificação.
- [ ] Clonar repositório: `git clone git@github.com:hssflavio-ux/jimi_webhook.git`.
- [ ] Criar `.env` a partir de `.env.example` e preencher credenciais.
- [ ] Rodar migrações: `sudo ./scripts/deploy.sh && sudo ./scripts/deploy.sh --force`.
- [ ] Instalar cron workers: `bash scripts/crontab-setup.sh --install`.
- [ ] Configurar Let's Encrypt (certbot) com renovação automática.
- [ ] Criar usuário admin via `/setup`.
- [ ] Configurar backup automático (MySQL diário + rsync de mídia).
- [ ] Instalar Netdata para monitoramento.
- [ ] Configurar script de healthcheck dos workers (§12.2) no cron.
- [ ] Testar end-to-end: webhook → banco → dashboard → vídeo → comando.
- [ ] Rodar `npx playwright test` (suíte completa) contra o ambiente de produção.
- [ ] Configurar sentinela externa (ex.: UptimeRobot, HetrixTools) pingando `/ping`.
- [ ] Documentar IPs, senhas, chaves em cofre seguro (não no repositório).

### Pós-lançamento (primeira semana)

- [ ] Monitorar CPU/RAM/IOPS com 500 cams reais.
- [ ] Ajustar `pm.max_children` conforme fila do PHP-FPM.
- [ ] Ajustar `innodb_buffer_pool_size` conforme hit ratio.
- [ ] Verificar crescimento diário de `gps_data` (ajustar projeções).
- [ ] Revisar slow query log e criar índices adicionais se necessário.
- [ ] Configurar alertas de monitoramento (disco > 80%, worker atrasado).

---

## Apêndice A — Resumo de portas expostas

| Porta | Protocolo | Serviço | Origem | Restrição recomendada |
|---|---|---|---|---|
| 22 | TCP | SSH | Admin | IP do administrador apenas |
| 80 | TCP | HTTP → redirect 443 | Público | Nenhuma (público) |
| 443 | TCP | HTTPS (dashboard + webhooks) | Público | Rate limit |
| 3306 | TCP | MySQL | localhost (ou app server) | localhost apenas na Fase 1 |
| 8080 | TCP | Nominatim | localhost | localhost apenas |
| 8881 | TCP | FLV live stream | Devices Jimi | Faixa IPs operadora M2M |
| 10002 | TCP | RTP ingest (video stream) | Devices Jimi | Faixa IPs operadora M2M |
| 10003 | TCP | Playback | Devices Jimi | Faixa IPs operadora M2M |
| 10088 | TCP | IoTHub command API | localhost (PHP) | localhost apenas |
| 21188 | TCP | Attachment upload | Devices Jimi | Faixa IPs operadora M2M |
| 23010 | TCP | Media file download | Público | Rate limit |

---

## Apêndice B — Variáveis de ambiente críticas para produção

```ini
# .env — valores recomendados para 500 câmeras
DB_HOST=localhost
DB_PORT=3306
DB_NAME=jimi_tracker
DB_USER=jimi_app              # NUNCA 'root' em produção
DB_PASS=<senha_forte>

WEBHOOK_TOKEN=<token_64_hex>  # openssl rand -hex 32
SYSTEM_VERSION=4.8.3

APP_URL=https://seudominio.com.br     # SEM barra final — obrigatório
FILE_STORAGE_URL=https://seudominio.com.br:23010/download/
STREAM_URL=https://seudominio.com.br:8881

IOTHUB_COMMAND_URL=http://10.1.0.43:10088/api/device/sendInstruct
IOTHUB_API_TOKEN=<token_interno>

# NOTIFY_ENABLED=1            # Descomentar quando SMTP estiver pronto
# APP_KEY=<openssl rand -hex 32>

# Retenção
LOG_RETENTION_DAYS=30
REPORT_RETENTION_DAYS=30

# Teto de anexo (relatórios agendados)
MAIL_MAX_ATTACH_MB=5
```

---

## Apêndice C — Script de provisionamento inicial

```bash
#!/bin/bash
# provision.sh — provisionamento inicial do servidor de produção
# Executar como root em Ubuntu 24.04 LTS limpo

set -euo pipefail

# ── Pacotes ────────────────────────────────────────────
apt update && apt upgrade -y
apt install -y apache2 mysql-server php8.3 php8.3-fpm php8.3-mysql \
  php8.3-mbstring php8.3-curl php8.3-zip php8.3-xml php8.3-gd \
  certbot python3-certbot-apache git rsync curl netdata

# ── MySQL ──────────────────────────────────────────────
mysql -e "CREATE DATABASE IF NOT EXISTS jimi_tracker DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -e "CREATE USER IF NOT EXISTS 'jimi_app'@'localhost' IDENTIFIED BY '<senha>'"
mysql -e "GRANT ALL PRIVILEGES ON jimi_tracker.* TO 'jimi_app'@'localhost'"
mysql -e "FLUSH PRIVILEGES"

# ── PHP-FPM ─────────────────────────────────────────────
sed -i 's/^pm.max_children.*/pm.max_children = 100/' /etc/php/8.3/fpm/pool.d/www.conf
sed -i 's/^memory_limit.*/memory_limit = 256M/' /etc/php/8.3/fpm/php.ini
systemctl restart php8.3-fpm

# ── Apache ─────────────────────────────────────────────
a2enmod rewrite ssl headers
systemctl restart apache2

# ── Deploy app ─────────────────────────────────────────
cd /var/www
git clone git@github.com:hssflavio-ux/jimi_webhook.git
cd jimi_webhook
cp .env.example .env
# Editar .env com credenciais reais
./scripts/deploy.sh && ./scripts/deploy.sh --force

# ── Cron workers ────────────────────────────────────────
bash scripts/crontab-setup.sh --install

# ── SSL ─────────────────────────────────────────────────
certbot --apache -d seudominio.com.br

# ── Firewall ────────────────────────────────────────────
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 8881/tcp
ufw allow 10002/tcp
ufw allow 10003/tcp
ufw allow 21188/tcp
ufw allow 23010/tcp
ufw enable

echo "Provisionamento concluído. Acesse https://seudominio.com.br/setup"
```

---

> **Este documento deve ser revisado a cada marco de crescimento (500, 1.000,
> 2.000, 5.000 câmeras) e após qualquer alteração significativa de carga no
> homolog.**
