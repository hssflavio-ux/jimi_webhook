# Changelog

Todas as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [4.0.0] — Não lançado (iniciativa "YUV Parity")

Reorientação do produto para ser uma **cópia fiel da plataforma YUV** (`app.yuv.com.br`) — plataforma multi-tenant de rastreamento com **telemetria de vídeo e gestão de ocorrências DMS**. Esta entrada cobre o **planejamento e a documentação**; a implementação segue o roadmap por fases de `PROJETO_YUV.md`.

### Added
- **`PROJETO_YUV.md`** — blueprint-mestre de implementação: visão, modelo de negócio (revendedor/cliente/filial), arquitetura-alvo, mapa de 22 rotas, design system, modelo de dados (migração v4.0.0), **motor de ocorrências** (alarme→ocorrência), spec módulo a módulo das 22 telas, roadmap por fases, critérios de aceite e plano de verificação.
- **`analise_yuv/analise_yuv.html`** — análise funcional do YUV (22 telas + 6 modais navegados via browser, com screenshots, regras de negócio, dinâmica e análise de lacunas vs. o projeto atual).
- **Design system YUV** documentado em `DESIGN.md` (ver Changed).
- **Planejamento de novas tabelas** (v4.0.0): `occurrences`, `occurrence_events`, `occurrence_configs`, `occurrence_config_params`, `drivers`, `sim_cards`, `branches`, `permission_groups`, `trips`, `jobs`, `geocode_cache`, `impersonation_log`.
- **Planejamento de novos módulos**: Dashboard de Ocorrências (DMS), Relatório de Ocorrências, Configurações de Ocorrências, BI, Exportação assíncrona, Vídeo estruturado (Ao Vivo/Playback/Downloads), Chips, Motoristas (CNH/toxicológico + FaceID), Grupos de Permissões, Equipamentos avançado (OTA firmware, importação em lote), Resumo executivo.

### Changed
- **Design system Coinbase aplicado** — o skin visual do produto passou a ser o **sistema Coinbase** (`DESIGN-coinbase.md`): Coinbase Blue `#0052ff` como única voltagem, canvas branco, **sidebar dark near-black `#0a0b0d`** com item ativo azul, CTAs **pill (100px)**, cards com hairline + um único nível de sombra (hover), headings de display em peso 400, **JetBrains Mono em todo número/IMEI**. Implementado em `web/layout_base.php`, `web/login_template.php` e `handlers/setup.php`; `DESIGN.md` reescrito como o design system do app derivado da Coinbase.
- _(Nota: a paleta roxa YUV chegou a ser proposta nesta iniciativa e foi **descartada** em favor do skin Coinbase. A estrutura/IA de produto permanece a do YUV.)_
- **`CLAUDE.md`, `AGENTS.md`, `STATUS.md`, `README.md`, `PLAN.md`, `llms.txt`** — atualizados para o direcionamento YUV Parity (nova visão, rotas-alvo, tabelas, ponteiros para `PROJETO_YUV.md`).
- **`STATUS.md`** — nova §0 com o roadmap por fases da iniciativa v4.0.0.

### Fixed
- **`mysql/jimi_tracker.sql` quebrava num fresh install**: o export do HeidiSQL gerou dois stubs de VIEW malformados (`CREATE TABLE vw_alarm_types_ambiguous_codes` / `vw_alarm_types_unknown_codes` sem colunas → erro de sintaxe) e as duas VIEWs `vw_alarm_types_*` referenciavam a tabela `alarm_types_reference`, que nunca é definida no dump. Os 4 blocos foram removidos (views diagnósticas, não usadas por nenhum handler). O comando documentado `mysql < mysql/jimi_tracker.sql` agora aplica sem erros (validado: 22 tabelas, 3 views, 114 alarm_types).
- **Ambiente de desenvolvimento local (Windows)**: adicionados `server.php` (router shim que reproduz o front controller do `.htaccess` sob `php -S`) e `scripts/dev-windows.ps1` (sobe MySQL portátil + servidor PHP). Fecha a pendência **F0.1** (PHP CLI/lint indisponível localmente).

### Notes
- O gateway de webhooks (`handlers/push*.php` + `config/WebhookHandler.php`) e a autenticação por token são **preservados**.
- As dívidas de segurança da revisão v3.2.x (CSRF, prepared statements, índices, cookie Secure) serão fechadas **na origem** ao reescrever os handlers em cada fase.

## [3.2.1] — 2026-07-04

### Security
- **Cross-tenant data leak fechado nos endpoints AJAX (R01/R02)**: `camerasdata.php`, `trackdata.php`, `hbdata.php`, `mediadata.php`, `commandstatus.php` e `sendcommand.php` agora exigem sessão de dashboard ativa (`require_ajax_session()` em `includes/auth.php`) e filtram TODAS as queries pelo `customer_id` da sessão. O token compartilhado (`WEBHOOK_TOKEN`) não concede mais acesso sozinho — antes, qualquer portador do token via dados (GPS, heartbeats, mídia, comandos) de todos os clientes e podia enviar comandos para qualquer IMEI.
- **`sendcommand.php` valida posse do IMEI**: comandos só são aceitos para dispositivos ativos do cliente da sessão (HTTP 403 caso contrário).
- **`sendcommand.php` bloqueia proNo fora da whitelist (R03)**: proNo desconhecido agora retorna HTTP 400 (antes apenas logava warning e enviava o comando).
- **Open redirect corrigido no `login.php` (R05)**: parâmetro `redirect` sanitizado via `safe_redirect_path()` — aceita apenas paths locais; rejeita URLs absolutas, `//host`, backslash e CR/LF.
- **`commandstatus.php` não aceita mais `?customer_id=` do cliente**: o escopo vem exclusivamente da sessão.

> Nota: as entradas de v3.1.0 (multi-tenant + auth) e v3.2.0 (usuários/perfil) ainda serão registradas retroativamente (pendência F6.3).

## [3.0.0] — 2026-06-10

### Added
- **Design System Cursor-inspired**: redesign completo do dashboard baseado no DESIGN.md
- **Tipografia editorial**: Inter (weight 400/500/600) + JetBrains Mono em todas superfícies de código
- **Design tokens**: 30+ CSS custom properties (surfaces, hairlines, text, brand, timeline pastels, semantic, radii, spacing)
- **Timeline pastels**: 5 cores dedicadas para status pills (thinking=peach, grep=mint, read=blue, edit=lavender, done=gold)
- **Protocol toggle**: pill selector substituindo radio buttons Bootstrap para JIMI/JTT
- **Galeria de mídia responsiva**: cards 3-colunas com thumbnails condicionais (imagem real vs ícone por tipo), download + player
- **Player de vídeo modal**: suporte a playback de arquivos de mídia via modal dedicado
- **Configuração assíncrona**: queries device info/params/set com feedback em code-block
- **`docs/PRD.md`**: Product Requirements Document completo (12 seções, 650+ linhas)
- **Plano de redesign**: `.opencode/plans/dashboard-redesign.md`

### Changed
- **Painel**: migrado de visual Bootstrap 5.3 padrão para design system Cursor-inspired
  - Canvas: `#f0f2f5` (cinza Bootstrap) → `#f7f7f4` (cream quente)
  - Cor primária: `#0d6efd` (azul) → `#f54e00` (Cursor Orange)
  - Profundidade: sombras Bootstrap → hairlines 1px (`#e6e5e0`)
  - CTAs: `rounded-pill` → raio 8px (dev-tool dialect)
  - Cards: shadows → bordas hairline + white-on-cream contrast
  - Tabelas: zebra stripe → hairline lines + hover canvas-soft
  - Alarmes: tabela densa → cards individuais com barra de severidade colorida
  - Status: badges Bootstrap → timeline pastel pills
  - Tabs: nav-tabs Bootstrap → navegação editorial com underline laranja
  - Forms: Bootstrap form-control → ds-input (44px, 8px radius, focus ring laranja)
  - Code blocks: bg-dark com texto claro → ds-code-block (canvas-soft, fonte mono)
  - Navbar: bg-dark → cream canvas com dots coloridos
- **`web/dashboard_template.php`**: reescrita completa (~850 linhas) com CSS tokens + JS inline + HTML adaptado
- **`web/assets/js/dashboard.js`**: atualizado para novas classes (`cs-*` → `ds-cmd-*`, `src-*` → `ds-origin-*`, protocol toggle como pills)
- **Fontes**: Bootstrap Icons → Google Fonts (Inter + JetBrains Mono via CDN)
- **Versionamento**: `2.0.0` → `3.0.0` (major bump — redesign completo do frontend)

### Removed
- Classes CSS Bootstrap visuais (`bg-*`, `btn-*`, `badge`, `table-*`, `card`, `shadow-*`, `border-*` utilitários visuais)
- Protocol radio buttons (`input[name="proto"]`) substituídos por `.ds-proto-option` pill selector
- Estilos inline de cores (`style="background:..."`) no JS de renderização dinâmica

## [2.0.0] — 2026-06-09

### Added
- Handler `/pushTerminalTransInfo` (Seção 1.15) — persistência em `device_events`
- Tabela `command_responses` para respostas assíncronas/offline de comandos
- Colunas `acc`, `oil_ele`, `gps_pos`, `remote_lock`, `power_status`, `fortify` em `heartbeats`
- Colunas `post_type`, `post_method`, `driver_license`, `door_status`, `sos_status`, `temperature`, `transparent_data` em `gps_data`
- Campo `requestMeta` no `WebhookHandler` para metadados extras do POST (ex: `msgType`)
- Funções `sanitize_date()` e `detect_media_type()` em `includes/functions.php`
- PHPDoc completo em `includes/functions.php` (8 funções documentadas)
- `docs/API_COVERAGE.md` — matriz de cobertura de endpoints
- `README.md`, `CHANGELOG.md`, `LICENSE`, `llms.txt`
- `docs/adr/ADR-001.md` — decisão de isolamento de protocolo JIMI/JTT
- **Dashboard unificado**: `web/index.php` agora é wrapper para `handlers/dashboard.php` + template canônico
- **Aba Mídia**: galeria de arquivos (imagem/vídeo/áudio) com filtro por IMEI
- **Player de vídeo HTTP-FLV**: flv.js para stream ao vivo e playback na aba Câmeras
- **Aba Configuração**: ler/alterar parâmetros do dispositivo (proNos 33027-33031)
- **Handlers de consulta**: `/trackdata` (GPS histórico), `/hbdata` (heartbeats), `/mediadata` (galeria)
- **Modal de detalhes de comando**: JSON formatado no histórico
- **Coordenadas + link de mapa** na tabela de alarmes
- **Links de arquivo de mídia** nos alarmes
- Presets JTT: `34817|foto`, `34818|midia`, `33028|params`, `33030|params_esp`, `33031|info`, `33029|reset`
- Variáveis `.env`: `FILE_STORAGE_URL`, `STREAM_URL`

### Changed
- **Logger unificado**: `core/Logger.php` (estático) é o único logger do sistema
- **Handler `pushiothubevent`**: migrado para extender `WebhookHandler` (token, idempotência, transação)
- **Handler `pushhb`**: extrai todos os 12 campos documentados (eram apenas 6)
- **Handler `pushgps`**: extrai todos os 28 campos documentados (eram apenas 17)
- **Handler `pushfileupload`**: reescrito para usar `fileName` (split), `gateTime`, `result` da spec
- **Handler `pushftpfileupload`**: reescrito para usar `result`, `instructionID`, `gateTime` da spec
- **Handler `pushlbs`**: reescrito para parsear `lbsJson` + `cellList` (LAC,CI,RSSI)
- **Handler `pushinstructresponse`**: reescrito para estrutura `{code, msg, data: {_imei, ...}}`
- **Handler `pushevent`**: `gateTime` priorizado como campo primário de tempo; `timezone` extraído
- **Handler `pushalarm`**: unificado para usar stored procedure `update_device_stats_after_alarm`
- **`get_webhook_data()`**: preserva todos os campos POST (não apenas `token` e `data_list`)
- **Stored procedure `update_device_stats_after_alarm`**: agora aceita coordenadas opcionais
- **Comentários**: 100% PT-BR, padronizados com template de 4 linhas (Endpoint, Versão, Referência)
- **Versionamento**: reset global para `2.0.0`

### Removed
- **`includes/config.php`**: removido (config duplicada, substituída por `.env` + `database.php`)
- **Classe `Logger` de `includes/functions.php`**: removida (unificada com `core/Logger.php`)
- **`handlers/pushterminalrealtimestatus.php`**: substituído por `pushTerminalTransInfo.php`
- **Métodos `sanitizeTimestamp()` duplicados**: removidos de `pushiothubevent.php` e `pushTerminalTransInfo.php`

### Fixed
- **pushalarm.php**: chave de fechamento da classe ausente/desalinhada (linha 420)
- **pushalarm.php**: 5 chamadas `Logger::` sem `'source'` no contexto
- **pushiothubevent.php**: sem validação de token, sem idempotência (migrado para WebhookHandler)
- **pushterminalrealtimestatus.php**: só logava raw payload, não persistia no banco
- **pushfileupload/pushftpfileupload**: campos mapeados incorretamente vs documentação oficial
- **pushlbs**: não parseava `lbsJson` + `cellList`
- **pushinstructresponse**: estrutura de payload completamente diferente da documentada
- **Painel**: presets JTT quebrados (data ISO em vez de JTT, sem serverFlagId)
- **Painel**: `serverFlagId` ausente no `sendCommand()` e `requestVideoUpload()` do JS antigo
- **Painel**: require case-sensitive `DashboardData.php` → `dashboarddata.php` no `web/index.php`
- **Painel**: dois dashboards divergentes (`web/index.php` vs `/dashboard`) unificados

## [1.0.0] — 2026-01-23 (v3.0.1 original)

### Added
- 10 webhook endpoints iniciais (pushevent, pushhb, pushgps, pushalarm, pushfileupload, pushlbs, pushresourcelist, pushftpfileupload, pushiothubevent, pushinstructresponse)
- Painel Bootstrap 5.3 com 3 abas (Monitoramento, Alarmes, Comandos)
- `WebhookHandler` abstrato com token, idempotência, async, transação
- `core/Logger.php` v2.0.0 com rotação diária e JSON context
- Stored procedures MySQL (`update_device_stats_after_*`)
- Tabela `alarm_types` com 114 códigos JIMI + JTT
- Decodificador de bitmask JT/T 808 (32 bits)
- Suporte dual-protocol JIMI/JTT no pushalarm v6.2
