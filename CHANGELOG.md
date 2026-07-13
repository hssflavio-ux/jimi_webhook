# Changelog

Todas as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased] — 4.2.1

### Added
- **Gatilho automático de vídeo de evento (DMS/ADAS, câmeras JT/T)**: ao criar uma ocorrência nova sem mídia vinculada, o motor de ocorrências agenda automaticamente a solicitação de upload da multimídia armazenada do device (`proNo 34818`/0x8802, `mediaType 2`, janela `±AUTO_VIDEO_WINDOW_SECS` ao redor do alarme em GMT-0 compacto, canal `AUTO_VIDEO_CHANNEL` — 0 = todos). O despacho HTTP roda **pós-commit** (fim do `pushalarm.php`, fora da transação do webhook) via novo helper reutilizável `includes/iothub_command.php` (`iothub_dispatch_command()`, mesmo contrato/semântica de status do `sendcommand.php`, registra em `commands` com `operator='auto_video'`). Guarda anti-rajada de 1 solicitação por device a cada 2 min; kill-switch `AUTO_VIDEO_REQUEST=0`. O vídeo chega assíncrono via `pushfileupload` e o `link_upload_to_occurrence()` (±3 min) já o anexa à ocorrência — o frontend (detalhe da ocorrência / playback) o serve por `FILE_STORAGE_URL + file_url` com canal/`event_time` reais do webhook de chegada.

## [Unreleased] — 4.2.0 (Aderência YUV — Fases A–D completas)

Execução do `PLANO_ADERENCIA_YUV.md` (revisão de aderência contra a plataforma YUV capturada em 06/07/2026 + inventário do código real). Progresso e ponto de retomada em `PLANO_ADERENCIA_YUV.md` §0.

### Added
- **Export síncrono Excel/PDF/CSV nos relatórios (B1 — padrão YUV §9.2)**: novo `stream_export()` em `includes/export_helper.php` (reusa os writers XLSX/PDF puros da fila; CSV com BOM + `;`; limite `SYNC_EXPORT_MAX_ROWS=10000`). Botões **Exportar Excel/PDF** reais (a mesma query da grade, sem paginação, respeitando os filtros ativos) em `rel_alarmes`, `rel_ocorrencias`, `rel_posicoes`, `rel_deslocamento`, `rel_desatualizados` (por faixa) e `equipamentos` — todos os `alert('em desenvolvimento')` removidos.
- **RBAC efetivo (B2 — completo)**: `get_user_permissions()`, `can()` e `require_permission()` em `includes/auth.php` (matriz JSON de `permission_groups`, com suporte ao wildcard `"*"` do seed Administrador; usuário sem grupo → sem restrição, compat com role legado). Sidebar (`layout_base.php`) esconde itens sem permissão `view`; **router** aplica `require_permission(tela,'view')` centralizadamente via mapa handler→tela (webhooks/AJAX/login fora do mapa); **gates de ação fina** (`create`/`edit`/`delete`) nos POSTs de todos os cadastros (ativos, ativos_novo, chips, clientes, equipamentos incl. import, motoristas, usuarios, grupos_permissao, config_ocorrencias incl. delete via GET) e gate de `export` nos 6 blocos de export síncrono.
- **Endereço geocodificado no Relatório de Posições (B3)**: coluna Endereço substitui Lat/Long (fallback: link OSM com as coordenadas); novo `geocode_cache_lookup()` (lote, cache-only) + resolução inline de até 3 misses por página (respeita rate limit Nominatim; o cache enche progressivamente).
- **Filtros padrão YUV (B4)**: novo componente `web/components/chips_multiselect.php` (chips com overflow `+N`, extraído do bi.php). `rel_alarmes`: multiselect de Tipos de Alarme (IN) + filtro Filial; `rel_ocorrencias`: filtros Filial e Motorista. Exports respeitam os novos filtros.
- **Grade de Equipamentos completa (B5)**: colunas **Chip** (JOIN `sim_cards`), **Bateria** (`device_statistics.battery_level`) e **Periféricos** (badge com contagem + tooltip), na grade e no export, com fallback resiliente para schema antigo.
- **Import em lote completo (B6)**: CSV importa Modelo (resolvido por nome) e Canais (fallback: `camera_count` do modelo); validação de IMEI 15–17 dígitos; avisos por linha (IMEI inválido/duplicado, modelo desconhecido).
- **Resumo enriquecido (D1 — paridade YUV `page_resumo`)**: card **Ociosidade** (ignição ligada + parado, últimos 30 min); **Status de Equipamentos por Modelo** (barras on/off + % online); **mapa de calor real** (`leaflet.heat` via CDN sobre as posições de 2h, mantendo os pontos clicáveis); séries temporais com **toggle Hoje / Últimos 7 dias / Último mês** (buckets hora/dia em BRT + total do período); **Top 3 placas com mais alarmes** e **Top 3 motoristas** (com CTA de upsell FaceID quando o recurso está desabilitado, como no YUV); **Visão por Clientes em 3 eixos Top 3** (equipamentos ativos / ocorrências / desatualizados); **auto-refresh 30s dos KPIs** via `/?ajax=kpis` (JSON leve, sem reload); botão **"Ver tutorial"** que reexibe o tour.
- **Rastreamento sem reload (D2)**: modo `?ajax=1` no handler devolve as posições em JSON; o mapa atualiza os pins in-place a cada 30s (posição, cor online/offline, popup) em vez de `location.reload()` a cada 60s.
- **Exportar com polling real (D3)**: poll de 10s via `/exportardata` que só recarrega quando algum status de job muda; coluna **Nome** do relatório na grade (de `params.report_name`, também exposto no JSON).
- **Indicador de impersonação (D4)**: banner âmbar sob o header quando o revendedor está operando como um cliente + botão "Voltar ao meu perfil" (novo modo `exit_impersonation` no `/customer_switch` fecha o `impersonation_log.ended_at` e restaura o contexto); `/customer_switch` também passou a exigir CSRF.
- **Padrão de grade CRUD nos cadastros (Fase C — YUV §9.1)**: busca server-side + Exportar Excel/PDF + paginação (25/pág) em `chips`, `motoristas` e `ativos`; busca + export em `clientes` (novas colunas **E-mail** e **Config. Checklist**) e `usuarios` (por aba Minha Empresa/Meus Clientes); busca client-side (`yuvTableFilter` global) em `grupos-permissao` e `config-ocorrencias`; `motoristas` com colunas **Foto** e **Nascimento**. Todos os exports com gate RBAC `export`.

### Fixed
- **Rota morta `/clientes/{id}`** (A1): despachava para `cliente_detalhe.php`, que não existe → agora 404 explícito (R08 residual).
- **`/checklist/inspecao` inacessível** (A2): `checklist` estava em `$simpleRoutes` E no `$subrouteMap`; o primeiro vencia e a tela de inspeção nunca abria. `checklist` saiu de `$simpleRoutes` (o fallback do subrouteMap continua servindo `/checklist`).
- **CSRF remanescente — R11 fechado de vez** (A3): `csrf_verify()`+`csrf_field()` em `ativos.php` (editar/remover) e `perfil.php` (troca de senha); **`/sendcommand` agora exige token** — `layout_base.php` expõe `window.CSRF_TOKEN` (+ meta tag) e os 6 callers (`comandos`, `ativo_detalhe`, `config`, `equipamentos` FOTA, `video_aovivo`, `video_playback`) enviam `X-CSRF-Token`.
- **Toggle de usuário quebrado desde a Fase F**: o form ativar/desativar de `usuarios.php` não tinha `csrf_field()` com `csrf_verify()` ativo no POST → 403 sempre. Corrigido.
- **`usuarios.php` mostrava o ID do grupo** em vez do nome (A4): resolvido com mapa id→nome.
- **Relatório de Posições sempre vazio**: `$where` usava `imei`/`id` sem prefixo e a query da grade faz JOIN com `devices` → erro de **coluna ambígua** engolido pelo try-catch da Fase K → zero resultados. Prefixado `g.` (grade, count, amostragem `MOD(g.id,10)`).
- **Ignição/GPS sempre "Desligada"/vazio em Posições**: a grade lia `$r['ignition']`/`$r['gps_status']` que não vinham no SELECT. Aliases `g.acc AS ignition, g.status AS gps_status` + aceite do status `VALID`.
- **`get_jimi_user()` sem colunas v4**: não selecionava `user_type`/`permission_group_id`/`photo_url`, então os checks `user_type==='revendedor'` (visão por clientes do Resumo, abas de Usuários) nunca ativavam. Incluídas no SELECT com fallback para schema antigo.
- **Cache de geocodificação nunca dava hit**: `reverse_geocode()` comparava o float recebido (8 casas) com a coluna `DECIMAL(9,6)` → toda consulta repetida chamava a API Nominatim de novo. Coordenadas agora arredondadas a 6 casas antes do SELECT/INSERT.
- **"Entrar como" e "Desativar" em Clientes quebrados (403) desde a Fase F**: os dois forms inline não tinham `csrf_field()` com `csrf_verify()` ativo no POST — mesma família do bug do toggle de Usuários. Corrigido.
- **Rastreamento sem nenhum pin no mapa**: a query de posições usava `g.ignition` — coluna que **não existe** em `gps_data` (é `acc`) → a exceção era engolida pelo try-catch e `$positions` ficava vazio silenciosamente. Corrigido com `g.acc AS ignition`.
- **"Velocidade da Frota" do Resumo nunca populava on-the-fly**: mesmo bug de coluna (`g.ignition` → `g.acc`) no fallback quando o cache `metrics_snapshots` está vazio.

### Verified
- `php -l`: verde em todos os arquivos alterados.
- Smoke test HTTP (server dev + MySQL portátil): `/ping` 200; telas protegidas 302→login (RBAC central não quebrou fluxo sem grupo); `/clientes/9` 404; `/checklist` e `/checklist/inspecao` resolvem.
- **Suite Playwright completa com login: 36 passed / 0 failed / 4 skipped** (2 rodadas — pós-Fase A+B1+B2/view e pós-B2-completo+B3; credenciais E2E provisionadas no MySQL local de dev). Skips: rate-limit destrutivo (opt-in) e multi-tenant (exige 2º cliente).

## [4.1.2] — 2026-07-11 (Vídeo ao vivo — payload de streaming e player resiliente)

Correção da abertura dos vídeos ao vivo. Causa-raiz: o comando que instrui o device a **publicar** o stream mandava um endereço inalcançável, então o media server nunca recebia RTP e o player travava em "Conectando".

### Fixed
- **Vídeo ao vivo nunca abria (payload 37121 quebrado)**: `video_aovivo.php` enviava `videoIP: window.location.hostname` (o host visto pelo **navegador**) e `videoTCPPort: "0"`. O comando 0x9101 instrui o **device** a publicar o RTP no media server do IoTHub — o endereço tem que ser o que o **device** alcança (IP público do servidor) e a porta de ingest do `iothub-media` (**10002**), não `0`. Com porta 0 / host do navegador, o device não publicava nada e o `.flv` em `:8881` ficava eternamente sem dados. Também havia `dataType:"1"` (string, "áudio") onde o correto é `0` (vídeo). Corrigido para `dataType:0, codeStreamType:0, videoIP:<IP do servidor>, videoTCPPort:"10002", videoUDPPort:0`.
- **Helper central `video_stream_config()`** (`includes/functions.php`): deriva `flv_base` (saída HTTP-FLV para o navegador) e `ingest_ip`/`ingest_port`/`playback_port` (endereço que o device alcança). O IP sai do host de `STREAM_URL` por padrão, com overrides `VIDEO_INGEST_IP`/`VIDEO_INGEST_PORT`/`VIDEO_PLAYBACK_PORT` no `.env`. Usado por `video_aovivo.php`, `comandos.php` e `ativo_detalhe.php` — presets de streaming/playback deixam de ser hard-coded e ficam consistentes.
- **Player FLV frágil (1 tentativa única)**: o device leva de 5 a 30s entre aceitar o comando e efetivamente publicar o stream; o código antigo tentava conectar uma vez e, se o `.flv` ainda não tinha dados, ou travava em "Conectando" para sempre ou morria no primeiro erro do flv.js. Novo player com **retry** (8 tentativas × 3s), **watchdog** de 8s por tentativa (conexão pendurada sem dados também dispara nova tentativa), tratamento do `flvjs.Events.ERROR` (404 enquanto o device não publica), **autoplay com fallback mudo** (contorna o bloqueio de autoplay dos navegadores), destruição limpa do player entre tentativas e mensagem de falha acionável ao esgotar. Verificado com câmera real: a 1ª tentativa (janela curta) pegava 0 bytes; a retry pega o stream quando o device publica.
- **Aviso de fila offline no vídeo**: `sendcommand.php` passou a expor `status` e `offline_queued` (device desconectado → `data._code=600`) na resposta JSON. O vídeo ao vivo detecta isso e avisa que a transmissão não vai iniciar agora (em vez de esperar um stream que nunca vem).
- **"Requisitar Gravações" enviava o comando errado** (`video_playback.php`): mandava proNo **34817** (comando de **foto**) com um payload de mídia gravada. Corrigido para **34818** (0x8802, upload de mídia armazenada) com `mediaType:2` (vídeo), `beginTime`/`endTime` no formato JT/T `yyMMddHHmmss` em GMT 0. O filtro de período do banco passou a usar `brt_day_range_to_utc()` (dia digitado é BRT; coluna é UTC) e o fetch ganhou `keepalive` (o form navega em seguida e cancelaria a requisição).
- **Preset "Streaming" da tela de Comandos gerava JSON inválido para 37121** (`comandos.php`, `ativo_detalhe.php`): era `{"channelId":1,"mediaType":0,"streamType":0}` — campos que o 0x9101 ignora, sem `videoIP`/porta. Agora usa o payload correto do `video_stream_config()`. Preset "Playback" (37377/0x9201) também corrigido: incluía `serverAddress`/`tcpPort` (porta de playback 10003) que faltavam. Preset "Upload de Vídeo" (texto proNo 128) passou a montar `VIDEOUPLOAD,<host>,<porta>,...` a partir de `FILE_STORAGE_URL`.
- **`/video` legado**: `video.php` (player unificado v3.x com o mesmo payload 37121 quebrado) virou redirect para `/video/aovivo` (ou `/video/downloads` no modo gravações), preservando `?imei=`.

### Verified (servidor de homologação, 2026-07-11)
- Comando **37121 corrigido** → câmera online `869058070151343` (JC182): IoTHub respondeu `code:0, _content:"ok"` em ~1s.
- **Stream ao vivo capturado**: `GET http://189.22.240.43:8881/1/869058070151343.flv` retornou **2 MB de vídeo FLV válido** (assinatura `FLV`, versão 1, flags `0x5` = áudio+vídeo, primeira tag type 18 = metadata) em 28s. A 1ª tentativa com janela curta pegou 0 bytes (device ainda não publicando) — comprova a necessidade do retry/watchdog.
- Lint 7/7 arquivos alterados sem erro. Playwright: navegação **25/25 verde** (inclui `/video/aovivo`, `/video/playback`, `/video/downloads` renderizando sem erro).

## [4.1.1] — 2026-07-09 (Diagnóstico no servidor — comandos IoTHub e respostas offline)

Diagnóstico via SSH no servidor de homologação fechou os itens M.2.1–M.2.3 (IoTHub + comandos + respostas).

### Fixed
- **Comandos marcados "failed" que na verdade foram aceitos**: o `tracker-instruction-server` segura a resposta HTTP por até 30s aguardando o device ("processSendInstruct await timeout"); o `sendcommand.php` abortava aos 15s (`CURLOPT_TIMEOUT`) e reportava "IoTHub inacessível". Timeout elevado para 35s, timeout distinguido de conexão recusada na mensagem, `curl_error` no log estruturado.
- **Respostas de comandos offline perdidas (nunca chegavam)**: evidência no access log — `POST / 302` vindos de `172.16.13.13` (okhttp, rede dos containers): o `offlineCmdPushURL` estava configurado **sem path** (`http://10.1.0.43`), o callback caía na raiz e morria no redirect de login. Além disso, o corpo do callback (§2.4) é um **objeto único sem `data_list`** e o `WebhookHandler` o descartava como "empty data". Correções: `offlineCmdPushURL=http://10.1.0.43/pushinstructresponse` no docker-compose do IoTHub (serviços `api` e `tracker-instruction-server` recriados), suporte opt-in a payload de objeto único no `WebhookHandler` (hash de idempotência calculado sobre a lista final), flag habilitada em `pushinstructresponse.php`, alias camelCase `pushInstructResponse` no router.
- **`/rastreamento` sem nenhum device (e 500 na versão pré-4.1.0)**: `ORDER BY d.is_online` referenciava o alias com prefixo de tabela → unknown column; a exceção era engolida pelo try-catch e a tela renderizava vazia. Corrigido para o alias puro.
- **`.env` do servidor sem `IOTHUB_COMMAND_URL`/`IOTHUB_API_TOKEN`** — adicionadas com `http://10.1.0.43:10088` (IP da LAN, pedido do operador; consistente com o `pushURL` dos containers). `.env.example` atualizado com a orientação.
- **Horários exibidos em UTC (3h adiantados) em todo o dashboard v4**: o armazenamento sempre foi UTC correto (conexão PDO com `time_zone '+00:00'`, devices em GMT 0, PHP em UTC), e os handlers legados convertiam para BRT — mas as 13 telas novas do YUV Parity formatavam com `date()/strtotime()` sem conversão, e os filtros de data tratavam o dia digitado como dia UTC. Correção sistêmica: helpers canônicos `fmt_brt()` (exibição UTC→America/Sao_Paulo), `brt_day_range_to_utc()` (dia local digitado → janela UTC na query) e `brt_today()` (defaults) em `includes/functions.php`, aplicados em 17 pontos de exibição, 8 telas com filtro de período, relatórios exportados (CSV/XLSX/PDF em BRT), popup do rastreamento, séries hora-a-hora do Resumo e por-dia do BI (`CONVERT_TZ` offset fixo) e janelas hoje/ontem do `metrics_rollup`. Colunas DATE puras (vencimento CNH, ativação) preservadas sem conversão. Regra documentada no CLAUDE.md.
- **Dashboard nunca exibia a resposta dos comandos (falso "Timeout/fila offline")**: quando o device está online, o sendInstruct devolve a resposta **no próprio HTTP response** (`data._content`), mas o `sendcommand.php` gravava `status='sent'` de qualquer forma — e o polling de `/comandos` só declara sucesso em `status='executed'`, terminando em falso timeout após 5 min sem nunca mostrar a resposta. Agora: resposta síncrona → `executed` + `response_time` imediatos (1º poll de 3s já mostra "✓ Resposta recebida" + conteúdo); fila offline (`_code` 600) permanece `sent` até o callback. `commandstatus.php` passou a extrair a resposta real do device (`data._content`/`data._msg`) em vez do `msg` genérico "success". Efeito colateral positivo: com síncronos saindo do pool de pendentes, a correlação do callback offline (heurística "mais recente pendente") fica confiável. **Callback offline real validado em produção**: `POST /pushinstructresponse → 200` vindo do container (okhttp), resposta persistida em `command_responses`.
- **Cadastro de ativos recusava devices auto-criados pelo gateway**: o gateway insere a linha do device (`customer_id NULL`) assim que ele transmite telemetria, antes do cadastro manual; o `/ativos/novo` checava o IMEI com COUNT global e respondia "já cadastrado" para um device invisível na listagem (filtrada por cliente) — beco sem saída. O cadastro agora **adota** a linha órfã (preservando a telemetria já recebida), **reativa** soft-deletados do próprio cliente e recusa apenas IMEI ativo do mesmo cliente ou vinculado a outro cliente. O form também ganhou proteção CSRF. Caso real resolvido: JC182 `869058070151343`.

### Verified (servidor de homologação, 09/07/2026)
- Comando real proNo 128 (STATUS) → device `860112070347838` respondeu em ~1s com telemetria completa (`commands.status=sent`, `response_payload` populado) — **M.2.2 ✓**
- IoTHub `:10088` UP e acessível (localhost e 10.1.0.43) — **M.2.1 ✓**
- Rota `http://10.1.0.43/pushinstructresponse` alcançável da rede docker (401 sem token; processa e grava em `command_responses` com token — validado com payload §2.4 simulado) — **M.2.3 ✓** (callback real será observado no próximo comando com device offline)
- Vídeos: `dvr-upload` (:23010) serve `/iothub/dvr-upload/uploadFile` interna e externamente (HTTP 200, 21 MB testado); o app monta `FILE_STORAGE_URL + file_url` — **Apache não precisa de acesso direto ao diretório**.

## [4.1.0] — 2026-07-08 (Fases M.1–M.5 — Pendências pós-YUV Parity)

### Added
- **Exportação Excel/PDF (Fase M.1)** — os 5 tipos de relatório do worker agora saem em CSV, **XLSX real** ou **PDF**, com seletor de formato no form de `/exportar` e badge de formato na grade. Implementação **100% PHP puro, sem Composer** (decisão: o projeto é "no package manager"): `includes/export_helper.php` com `XlsxWriter` (Office Open XML mínimo via `ZipArchive`, streaming em disco, cabeçalho azul Coinbase, IMEIs preservados como texto) e `PdfWriter` (PDF 1.4 tabular A4 paisagem, Helvetica core fonts, paginação automática, cap de 20 mil linhas). CSV melhorado: UTF-8 BOM + separador `;` (Excel pt-BR). `/exportardata` responde `format` + `mime_type`.
- **Migration `mysql/migration_v4.1.0.sql`** — coluna `jobs.format` ENUM('csv','xlsx','pdf') + fix do seed de `occurrence_config_params` (ver Fixed) + versão 4.1.0 em `system_info`. Integrada ao `scripts/deploy.sh`.
- **Script de replay E2E (Fase M.2)** — `scripts/test_e2e.sh`: ping → pushgps → pushalarm (143) → pushfileupload → verificação MySQL (alarme + ocorrência + mídia + vínculo). 8/8 verde no ambiente dev.
- **PWA (Fase M.3)** — `manifest.json` (standalone, theme `#0052ff`, background `#0a0b0d`), ícones 192/512 + variantes maskable (`assets/icons/`, gerados com GD), meta tags PWA/apple-touch em `layout_base.php` e `login_template.php`.
- **Suite Playwright (Fase M.4)** — 40 testes em 6 specs (`tests/`): login (senha errada, redirect, open-redirect R05, rate limiting opt-in), navegação (25 rotas sem erro 500/fatal), CRUD motoristas, webhook→ocorrência via `/pushalarm`, isolamento multi-tenant, exportação e2e (job→worker→download CSV/XLSX/PDF com validação de magic bytes). `playwright.config.js` sobe `php -S` automaticamente; `scripts/run-tests.ps1` para Windows. **Resultado: 37 passed, 0 failed** (3 specs opt-in pulados).
- **`API_COVERAGE.md`** — mapa completo de webhooks, AJAX e páginas com métodos, parâmetros, auth e respostas.

### Changed
- **Responsivo mobile (Fase M.3)** — sidebar off-canvas com backdrop + scroll lock + swipe-para-fechar, touch targets ≥44px, header compacto (relógio oculto, nome do cliente truncado), tabelas com scroll interno (`.table-wrap` overflow-x) e `white-space:nowrap` em células, form grids empilhados, login 100% width com inputs 16px (evita zoom iOS). Verificado com emulação iPhone 14: **0px de overflow horizontal**.
- **`server.php`** — `csv`/`xlsx` adicionados à whitelist de estáticos (downloads de relatórios no dev).
- **`scripts/worker.php`** — refatorado: as 5 funções `generate*CSV` viraram `buildReportSource()` (headers + statement + mapper) com despacho por formato.

### Fixed
- **CRÍTICO — Motor de ocorrências nunca disparava via webhook**: `pushalarm.php` capturava `lastInsertId()` **depois** do `CALL update_device_stats_after_alarm`, que reseta o valor para 0 — o gate `$alarmId > 0` nunca passava e `process_alarm_to_occurrence()` jamais era chamado. O ID agora é capturado imediatamente após o INSERT. (Descoberto pelo replay E2E da Fase M.2.)
- **CRÍTICO — Seed DMS/ADAS órfão**: os nomes dos parâmetros do perfil "Padrão Sistema" (`'Distração'`, `'Fadiga'`, `'SOS'`…) não existiam em `alarm_types`, e o matching do engine exige igualdade exata — nenhum alarme DMS gerava ocorrência. A migration v4.1.0 substitui os 19 parâmetros órfãos por 34 com os nomes reais do catálogo (JIMI 143–160/204–207, JT/T 264-X/265-X, acidentes e informativos).
- **CRÍTICO — CSRF quebrava todos os POSTs**: o token era gerado em `$_SESSION` sem `session_start()` (o app não usa sessões nativas — `$_SESSION` é por request), então cada request gerava token novo e `csrf_verify()` sempre falhava com 403 — todo CRUD (motoristas, chips, clientes, exportar…) estava inoperante desde a Fase F. O token agora é derivado por HMAC-SHA256 do token de sessão (cookie HttpOnly) + secret do servidor: estável durante o login, impossível de forjar sem o cookie.
- **`auth_init()` sem valor de retorno** — `/ocorrenciasdata` e `/exportardata` testam `if (!auth_init())` e sempre recebiam `null` → 401 permanente mesmo autenticado. Agora retorna o estado de autenticação.
- **Rota `/grupos-permissao` 404** — estava em `$simpleRoutes` (montava `grupos-permissao.php`, arquivo inexistente); movida para `$renamedRoutes` → `grupos_permissao.php` (mesma classe do fix de `config-ocorrencias` da Fase L).
- **Coluna fantasma `devices.last_position_at`** — referenciada em `worker.php` (relatório de devices), `rel_desatualizados.php` (5 buckets) e `metrics_rollup.php`, mas não existe em nenhuma migration; as queries falhavam (mascaradas pelos try-catch da Fase K). Corrigido com `LEFT JOIN device_statistics` → `last_gps_time` (fonte viva mantida pelas procedures).
- **`Logger.php` deprecation PHP 8.1+** — `date()` recebia float de `microtime(true)`; o warning de conversão implícita vazava HTML nas respostas JSON dos webhooks (headers already sent). Cast para int.
- **`exportar.php` passava o token CSRF como flag** — `csrf_verify($_POST['csrf_token'])` usava a string como parâmetro `$exit_on_fail`; trocado por `csrf_verify()`.

### Notes
- Pendências que exigem produção/dispositivo real (documentadas no STATUS.md §11): IoTHub `localhost:10088` (M.2.1–M.2.3), OTA proNo 33027 (M.2.5), execução do `test_e2e.sh` no servidor.

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
