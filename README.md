# Jimi Webhook System v4.1.0 — YUV Parity

Gateway PHP para dispositivos IoT Jimi — recebe webhooks de GPS/heartbeat/alarme/evento do Jimi IoT Hub (`jimicloud.com`), persiste em MySQL e fornece uma plataforma multi-tenant de rastreamento com telemetria de vídeo (MDVR) e gestão de ocorrências DMS/ADAS.

> **Status (07/2026)**: Fases 0–M concluídas. Dashboard com 30 rotas, motor de ocorrências DMS (verificado E2E), exportação CSV/XLSX/PDF, PWA mobile, suite Playwright (37 testes verdes), white-label, rate limiting, logs de auditoria.
>
> **Blueprint**: [`PROJETO_YUV.md`](./PROJETO_YUV.md). **Análise visual**: [`analise_yuv/analise_yuv.html`](./analise_yuv/analise_yuv.html).

## Quick Start

```bash
# 1. Configure o ambiente
cp .env.example .env

# 2. Crie o banco e execute todas as migrations em ordem
mysql -u root -p < mysql/jimi_tracker.sql
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.1.0.sql

# 3. Setup pre-commit lint hook
git config core.hooksPath .githooks

# 4. Aponte o Apache/Nginx para o diretório raiz
#    O .htaccess já configura rewrite e headers de segurança
```

Pré-requisitos: PHP 8.3+ com PHP-FPM, MySQL 8.0+, Apache com mod_rewrite.

## Features

### Webhook Gateway
- **12 endpoints push** alinhados com a [API oficial Jimi](https://docs.jimicloud.com/integration/integration.html)
- **Processamento assíncrono** via `fastcgi_finish_request()` — HTTP 200 imediato, processamento em background
- **Anti-replay** — idempotência por hash MD5 do payload com janela de 10 minutos
- **Duplo protocolo** — suporte completo a JIMI (msgClass=0) e JT/T 808 (msgClass=1)

### Dashboard (v4.0.0 — 30 rotas)

| Rota | Descrição |
|---|---|
| **`/` Resumo** | Visão 360°: KPIs, heatmap GPS, velocidade frota, desatualizados, top clientes, séries hora-a-hora (Chart.js) |
| **`/rastreamento`** | Mapa live cliente→ativo cascata, circle markers, busca, auto-refresh 60s |
| **`/bi`** | BI: filtros (cliente/ativo/motorista/alarmes), gráficos barras/pizza/linha sob demanda |
| **`/ocorrencias/dashboard`** | Dashboard DMS: KPIs + risk bar + grade + polling 15s, detalhe/tratativa inline |
| **`/video/aovivo`** | flv.js + rotação/watermark CSS + status bar |
| **`/video/playback`** | Filtro equipamento/canal/período → timeline → play inline |
| **`/video/downloads`** | Grade com status disponível/solicitado/erro + download direto |
| **`/relatorios/posicoes`** | Mapa Leaflet + paginação |
| **`/relatorios/deslocamento`** | Viagens (trips): duração, vel.máx, distância km, alarmes |
| **`/relatorios/desatualizados`** | 5 buckets KPI clicáveis + drill-down |
| **`/relatorios/alarmes`** | Ordenação clicável, 5 filtros, link mapa OSM |
| **`/relatorios/ocorrencias`** | 6 filtros: cliente, IMEI, tipo, status, risco, falso-positivo |
| **`/ativos`** | Lista + editar inline + remover (soft-delete) |
| **`/chips`** | CRUD SIM cards (operadora, MSISDN, ICCID, vínculo IMEI) |
| **`/clientes`** | CRUD + impersonar + white-label (logo, cor, faceid) |
| **`/equipamentos`** | Grade + form (periféricos, rotação, watermark) + FOTA + import CSV |
| **`/grupos-permissao`** | Matriz 18 telas × 5 ações JSON |
| **`/motoristas`** | CRUD + compliance (CNH, toxicológico, vencimentos) |
| **`/config-ocorrencias`** | Perfis de regras DMS |
| **`/usuarios`** | Abas Minha Empresa/Meus Clientes, user_type, permission_group |
| **`/comandos`** | Presets JIMI/JT-T, polling 3s/10s/5min |
| **`/exportar`** | Fila de jobs assíncronos: CSV real para 5 tipos |
| **`/checklist`** | CRUD + preenchimento de inspeção veicular (`/checklist/inspecao`) |
| **`/perfil`** | Troca de senha |

### Motor de Ocorrências DMS
- Alarmes de câmera com IA → ocorrências com fluxo de tratativa
- Classificação de risco (baixo/médio/alto) configurável por cliente
- Agrupamento inteligente (dedup por janela de 10 min)
- Vínculo automático com vídeo do evento
- Dashboard operacional em tempo real com polling 15s

### Design System (Coinbase)
- **Coinbase Blue** (#0052ff) — única voltagem de marca (CTAs, links, foco)
- **Sidebar dark near-black** (#0a0b0d) com grupos sanfona; **canvas branco**
- **CTAs pill** (100px); cards com hairline + sombra suave no hover (16px)
- **Tipografia Inter** (peso 400 em display) + **JetBrains Mono** em números/IMEI
- **White-label**: `brand_color` do cliente aplicado dinamicamente na sidebar
- Cores de risco: Baixo (azul) / Médio (amarelo) / Alto (vermelho)

### Segurança
- Autenticação token-based via cookie `jimi_token` + MySQL `sessions` (sem `session_start()`)
- CSRF em todos os formulários POST (`includes/csrf.php`)
- Rate limiting de login: 5 tentativas/15 min por IP + tabela `login_log`
- SQL injection: 100% prepared statements com named placeholders
- Cookies Secure/HttpOnly/SameSite=Lax
- Sanitização de redirect (R05), filtro GPS (0,0) (R06)

### Workers (cron)
| Script | Periodicidade | Função |
|---|---|---|
| `scripts/worker.php` | 1 min | Processa fila `jobs`: geração de CSV, download de vídeo |
| `scripts/trip_builder.php` | 15 min | Segmenta `gps_data` em `trips` (haversine), cruza alarmes |
| `scripts/metrics_rollup.php` | 5 min | Pré-computa 22 KPIs por cliente em `metrics_snapshots` |

## Configuration

| Variável | Descrição | Padrão |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` | MySQL | `localhost:3306/jimi_tracker` |
| `WEBHOOK_TOKEN` | Token de autenticação (webhooks + painel) | `a12341234123` |
| `SYSTEM_VERSION` | Versão do sistema | `4.0.0` |
| `FILE_STORAGE_URL` | URL base para arquivos de mídia | `http://IP:23010/download/` |
| `STREAM_URL` | URL base para streams HTTP-FLV | `http://IP:8881` |
| `IOTHUB_COMMAND_URL` | Endpoint de comandos IoTHub | `http://localhost:10088/api/device/sendInstruct` |
| `IOTHUB_API_TOKEN` | Token interno da API IoTHub | `123` |

## Testes

O app é PHP puro; **npm/Node são usados exclusivamente para a suite E2E Playwright** (`tests/`).

```powershell
# Setup (uma vez): instala @playwright/test + Chromium
npm install
npx playwright install chromium

# Credenciais de um usuário de teste (specs autenticados são pulados sem elas)
$env:TEST_EMAIL = 'usuario@teste.local'
$env:TEST_PASSWORD = 'senha'

# Roda tudo (sobe php -S localhost:8000 automaticamente; requer MySQL local)
./scripts/run-tests.ps1
# ou: npx playwright test [--headed] [--grep "Login"]
npx playwright show-report   # relatório HTML
```

Variáveis opcionais: `BASE_URL` (alvo ≠ localhost), `TEST_EMAIL_B`/`TEST_PASSWORD_B`
(spec de isolamento multi-tenant — clientes distintos), `TEST_IMEI` + `WEBHOOK_TOKEN`
(spec webhook→ocorrência), `RATE_LIMIT_TEST=1` (opt-in — **bloqueia o IP por 15 min**).

Replay E2E de webhooks (bash, usável no servidor):

```bash
bash scripts/test_e2e.sh                              # local
BASE_URL=http://SEU_SERVIDOR bash scripts/test_e2e.sh # produção
```

Cobertura: login/rate-limit/open-redirect, 25 rotas da sidebar, CRUD motoristas,
pushalarm→motor de ocorrências→dashboard, isolamento multi-tenant e exportação
completa (job → worker → download CSV/XLSX/PDF). Ver `API_COVERAGE.md` para o mapa
de endpoints.

## Contributing

1. Handlers de webhook devem extender `WebhookHandler` (`config/WebhookHandler.php`)
2. Comentários em **PT-BR**, PHPDoc
3. SQL sempre com `$db->prepare()` + placeholders nomeados (nunca interpolar variáveis)
4. Testar localmente: `php -S localhost:8000 server.php`
5. Lint: `php -l <arquivo>` — hook automático via `.githooks/pre-commit`
6. Seguir o [CHANGELOG.md](./CHANGELOG.md)

## Documentation

| Documento | Descrição |
|---|---|
| **[PROJETO_YUV.md](./PROJETO_YUV.md)** | Blueprint-mestre v4.0.0 |
| **[STATUS.md](./STATUS.md)** | Status detalhado, bugs, pendências (diário vivo) |
| [API_COVERAGE.md](./API_COVERAGE.md) | Mapa de endpoints: webhooks, AJAX, páginas (métodos, params, auth) |
| **[analise_yuv/analise_yuv.html](./analise_yuv/analise_yuv.html)** | Análise visual das 22 telas do YUV |
| [DESIGN.md](./DESIGN.md) | Design system Coinbase (deriva de DESIGN-coinbase.md) |
| [AGENTS.md](./AGENTS.md) | Guia para AI agents (arquitetura, gotchas, comandos) |
| [CHANGELOG.md](./CHANGELOG.md) | Histórico de versões |
| [docs/ADRs](./docs/adr/) | Registro de decisões de arquitetura |
| [API Oficial Jimi](https://docs.jimicloud.com/integration/integration.html) | Documentação de referência |

## License

MIT
