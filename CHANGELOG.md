# Changelog

Todas as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

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
