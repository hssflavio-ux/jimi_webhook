# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

> **Nome do produto: `bycamera`** (v4.8.3). O frontend inteiro — login, sidebar, `<title>`, setup, wiki, marca d'água do vídeo — usa essa marca. São **três artes**, uma por superfície, e trocar uma pela outra quebra a legibilidade: `web/assets/logo-login.png` (lockup completo **com o descritor** "videomonitoramento inteligente", só no login, onde há largura para lê-lo), `web/assets/logo-dark.png` (texto claro sobre transparente — sidebar e qualquer fundo escuro; a arte clara **some** no near-black) e `web/assets/logo-report.png` (sem descritor, fundo branco — cabeçalho do PDF dos relatórios, via `REPORT_LOGO_PATH`). Os originais ficam em `docs/imagens/`. Os **ícones do PWA/favicon** (`assets/icons/icon-*.png`, referenciados por `manifest.json`) são só o **símbolo** — num quadrado de 192 px o lockup seria ilegível.
> **NÃO renomear**: o badge de protocolo `JIMI` (contra `JT/T 808`) é nome técnico real (`msgClass=0`), assim como `jimicloud.com`, o banco `jimi_tracker`, o cookie `jimi_token`, as chaves de `localStorage` e helpers como `get_jimi_user()`.

PHP IoT gateway that receives GPS/heartbeat/alarm/event webhooks from the Jimi IoT Hub (`jimicloud.com`), persists them to MySQL, and serves a multi-tenant dashboard for live tracking, video (MDVR), command dispatch, reports, and remote device configuration. Pure PHP — **no build step, no package manager for the app** (npm/Node are used *only* for the Playwright E2E suite in `tests/`; XLSX/PDF export is hand-rolled pure PHP in `includes/export_helper.php`).

**Direção atual (v4.0.0 — "YUV Parity"): o projeto está sendo transformado em uma cópia fiel da plataforma YUV (`app.yuv.com.br`).** O núcleo do produto passa a ser a **gestão de ocorrências de comportamento do motorista (DMS/ADAS)** — alarmes de câmera com IA (distração, uso de celular, sem cinto) que viram ocorrências com fluxo de tratativa, classificação de risco e regras configuráveis por cliente. O gateway de webhooks é preservado; o dashboard e o design são reconstruídos.

- **`PROJETO_YUV.md`** é o blueprint-mestre de implementação (visão, rotas-alvo, modelo de dados, specs de todas as 22 telas, motor de ocorrências, roadmap por fases). **Leia-o antes de implementar qualquer módulo novo.**
- **`analise_yuv/analise_yuv.html`** é a fonte visual de verdade (screenshots + regras de negócio das 22 telas do YUV).
- **`PROJETO_PARAMETROS.md`** é o blueprint da parametrização remota das câmeras JT/T (`33027`/`33028`/`33030`). **Leia a §2 antes de escrever qualquer parser**: as respostas foram medidas em câmera real e a doc oficial está errada em três pontos — o campo de contagem é `paramCount` (não `totalNum`), os parâmetros de vídeo vêm em blocos `channel_N` (não na chave `119`) e 20 dos 46 parâmetros do JC371 não constam da tabela oficial. **A tabela COMPLETA do JT/T 808 não é a da Jimi** — é a de [`QuecPython/jtt808`](https://github.com/QuecPython/jtt808/blob/master/docs/en/API_Reference.md) (`TerminalParams.set_params`): os 86 IDs de `0x0001` a `0x0110`, com tipo, unidade, faixa e a distinção 2013 vs 2019. Os números do device são esses IDs **em decimal** (`16` = `0x0010`), e a tabela 2.3.9.1 da Jimi é um recorte parcial dela; consulte-a antes de declarar um parâmetro "não documentado". O `docs/jtt-808-2019-meigou.pdf` (MEITRACK) publica só 4 IDs — não serve para isso, mas é a melhor fonte que temos das mensagens JT/T 1078 (`0x9101`/`0x9102`, `0x9201`/`0x9202`, `0x9205`–`0x9208`).
- O **design system é o da Coinbase** (`DESIGN-coinbase.md`): azul `#0052ff` como única voltagem, canvas branco, **sidebar dark near-black `#0a0b0d`** com item ativo azul, CTAs pill (100px), números em JetBrains Mono, headings de display em peso 400. Aplica-se sobre a estrutura de produto YUV. Substitui a paleta Cursor (≤3.x). Ver `DESIGN.md`.

Official API reference: https://docs.jimicloud.com/integration/integration.html

**Read `STATUS.md` before continuing development** — it tracks current bugs, fixed issues, pending work, and the YUV-parity roadmap status. `AGENTS.md` holds the same architectural detail as this file with the full route/table tables.

## Ambientes — DOIS servidores, e a doc antiga trocava os nomes

| | Endereço | Host | Stack | Papel |
|---|---|---|---|---|
| **Produção** | `186.248.143.197` — **`https://bycamera.ia.br`** (o DNS aponta para cá), LAN `10.1.1.8` | `bycamera` | Ubuntu 24.04 · Apache 2.4.58 · PHP 8.3 FPM · **MySQL 8.4** · 16 containers IoTHub | operação, câmeras reais |
| Homologação | `189.22.240.43` (`http://189.22.240.43`, sem TLS) | `iothub` | Apache 2.4 · PHP 8.3 FPM · **MySQL 8.0** · 16 containers IoTHub | testes |

🔴 **`189.22.240.43` NÃO é produção.** Ele foi o ambiente único até 13/08/2026 e ficou gravado como "produção" em textos de julho (`.agents/memory/`, §8 do STATUS.md) — corrigido, mas o engano ressurge de qualquer doc velha. Produção é a que responde em `bycamera.ia.br`. Os dois `/ping` divergem **de propósito** (homolog costuma estar à frente ou atrás); comparar os dois é o jeito mais rápido de saber o que está no ar em cada um.

- **Deploy** (idêntico nos dois): `ssh -t administrador@<ip> "cd /var/www/jimi_webhook && sudo ./scripts/deploy.sh"`. O `sudo` **pede senha** — daí o `-t`, senão a sessão morre sem prompt.
- **Chave SSH**: a do Mac de dev está autorizada **em produção**; no homolog está instalada a da máquina **Windows**, então do Mac o homolog recusa (`Permission denied (publickey,password)`) — não é senha errada.
- **Em produção a chave do GitHub é do `administrador`, não do root** — e o `deploy.sh` roda sob `sudo`. Sem `core.sshCommand` no `.git/config` apontando para `/home/administrador/.ssh/id_ed25519` (+ o `known_hosts` dele), a FASE 1 morre em `✗ FALHA: git fetch falhou` e a mensagem sugere criar uma chave nova, que é o conserto errado — o erro real é `Host key verification failed`. Configurado em 14/08/2026; **some se o repo for reclonado** (detalhe na §8 do `STATUS.md`).
- **A versão anunciada mora no `.env.example`** (`SYSTEM_VERSION`), e o `deploy.sh` a propaga para o `.env` do servidor. Subir código sem subir esse número faz o `/ping` anunciar a versão antiga com o código novo no ar — foi o que as v4.9.18 e v4.9.19 fizeram.

## Commands

```bash
# Fresh database install → see skill `db-setup` (.claude/skills/db-setup/SKILL.md);
# rarely needed after initial setup.

# Lint a single PHP file
php -l handlers/pushgps.php

# Lint everything (mirrors deploy.sh FASE 4 VERIFY)
find handlers config core includes -name "*.php" -type f -exec php -l {} \;

# Deploy (backup → git pull → migrate → chmod → php -l → /ping smoke test)
./scripts/deploy.sh                 # normal
./scripts/deploy.sh --force         # redeploy with no code changes
./scripts/deploy.sh --skip-migrate  # skip DB migration
./scripts/rollback.sh <TIMESTAMP>   # restore a backup from /var/backups/jimi_webhook

# Health check
curl http://localhost/ping

# Tail logs (rotated daily)
tail -f logs/webhook_$(date +%Y-%m-%d).log

# E2E tests (Playwright, needs local MySQL — see scripts/dev-windows.ps1)
./scripts/run-tests.ps1              # or: npx playwright test
TEST_EMAIL=... TEST_PASSWORD=...     # authed specs skip without these

# Webhook replay E2E (bash; also runnable on the server)
bash scripts/test_e2e.sh
```

Verification: `php -l` lint + `scripts/test_e2e.sh` (webhook replay with MySQL assertions) + the Playwright suite in `tests/` (40 tests: login, 25 routes, CRUD, webhook→occurrence, multi-tenant, export). Authed specs skip when `TEST_EMAIL`/`TEST_PASSWORD` are unset.

## Architecture

```
Jimi IoT Hub --POST--> .htaccess --> handlers/router.php --> handlers/*.php
```

**Front controller**: `.htaccess` rewrites every non-file request to `handlers/router.php`, which parses URL segments and `require`s the matching `handlers/*.php`. Path params (e.g. `/ativos/{imei}`, `/clientes/{id}`) are injected into `$_GET` before dispatch. Adding a route = add the segment to the relevant array in `router.php` AND create the handler file.

**Two kinds of handlers share the `handlers/` directory:**

1. **Webhook receivers** (`push*.php`) — each instantiates a subclass of `WebhookHandler` (`config/WebhookHandler.php`) and calls `handle()`. The base class enforces a fixed pipeline: validate token → log raw payload → send HTTP 200 early via `fastcgi_finish_request()` → **then** process in background → idempotency check → `beginTransaction` → `normalize_data()` + `processItem()` per item → `commit` → write metrics. Subclasses only implement `processItem()` (and optionally `validateData()`). Real work happens *after* the client already got its 200, so errors there are logged, never returned.

2. **Dashboard pages + AJAX endpoints** — render via the layout shell in `web/` and call `require_login()` / `require_admin()` from `includes/auth.php`.

## Critical conventions & gotchas

- **Async requires PHP-FPM.** `fastcgi_finish_request()` is what lets webhooks return 200 instantly and process in background. Without FPM the response blocks until processing finishes.

- **Idempotency / anti-replay.** Each webhook payload is hashed (MD5 of `data_list`); a hash seen within 10 minutes (checked against `request_logs`) is dropped. Re-sending the same payload to test will be silently rejected during that window.

- **Authentication is cookie-token based, NOT PHP session files.** `includes/auth.php` reads a 64-char hex `jimi_token` cookie and looks it up in the `sessions` table (joined to `user_id` + `customer_id`). Despite the cookie mechanism, helpers populate `$_SESSION` for the rest of the request. Every dashboard page must call `require_login()`; admin-only pages call `require_admin()`. First run: visit `/setup` to create the admin (only works while the `users` table is empty).

- **Multi-tenant context.** Most data is scoped by `customer_id`, resolved from the session via `get_customer_id()`. The customer dropdown / `/customer_switch` changes `set_customer_context()`. New device/data queries must filter by customer.

- **Renomear alarme em `alarm_types` quebra o motor de ocorrências se você parar aí.** `occurrence_config_params.alarm_type` guarda o **nome** do alarme, não o código, e `get_occurrence_param()` (`includes/occurrence_engine.php`) resolve o parâmetro por `JOIN alarm_types ON at.alarm_name_pt = ocp.alarm_type`. Nome renomeado = JOIN não casa = **nenhuma ocorrência é gerada**, em silêncio: o alarme é gravado, aparece nos relatórios, e a ocorrência simplesmente não nasce — sem erro no log nem na tela. Foi o que a v4.8.3 fez com 21 dos 41 parâmetros do homolog, e só apareceu quando `webhook_occurrence.spec.js` saiu do estado "pulado". **Toda migração que mexer em `alarm_name_pt` tem de remapear `occurrence_config_params` junto** (ver `migration_v4.8.6.sql`) e conferir com `SELECT` de órfãos.
  - **`alarm_types.category` é a MESMA armadilha, numa segunda tabela.** `notification_rules.alarm_type` aceita nome **ou categoria**, e `notification_engine.php` casa por `at.category = nr.alarm_type`. No homolog as **6** regras casam por categoria e **nenhuma** por nome — então renomear categoria sem remapear a regra desliga a notificação em silêncio, exatamente como o caso acima. A v4.9.5 unificou as categorias (estavam em inglês nas linhas JIMI e em português nas JT/T) e precisou remapear `notification_rules` junto, com `UPDATE IGNORE` por causa da `UNIQUE KEY (customer_key, alarm_type)`. `DMS` e `ADAS` ficam intocados: são siglas, e `rel_alarmes.php` filtra por `category IN ('DMS','ADAS')`. A tradução para a tela é na **exibição**, por `alarm_category_label()` — nunca gravando o rótulo traduzido na coluna.
  - 🔴 **O código JT/T que chega aos motores é a BASE, não o do catálogo.** `pushalarm.php` passa `alarm_type = '264'`; `alarm_types.alarm_code` guarda `'264-4'` (o subtipo vive em coluna separada, `alarms.alarm_subtype`). Enquanto os dois motores compararam só a base, **nenhuma regra por CATEGORIA ou por CÓDIGO disparava para DMS/ADAS de JT/T** — o núcleo do produto — enquanto JIMI (códigos simples) e JT/T sem subtipo (`1027`) funcionavam. Sintoma: câmera JIMI notifica, câmera JT/T não, sem erro em log nem tela. Corrigido na v4.9.6 passando `alarm_subtype` adiante e casando também pelo composto. **Regra/parâmetro gravado por NOME sempre escapou disso** (é o ramo `= :aname`), e é por isso que o motor de ocorrências nunca exibiu o defeito: os parâmetros são por nome, as regras de notificação são por categoria.
  - ⚠️ **Conferência de "sobrou algo em inglês?" precisa de `BINARY`.** A collation é `utf8mb4_unicode_ci`: sem ele, `WHERE category IN ('Video','Sensor')` casa os valores **já corrigidos** (`video`, `sensor`) e a migração acusa erro onde não há.

- **Tela nova entra em DOIS lugares, sempre**: `$screenByHandler` (`handlers/router.php`) **e** `$screens` (`handlers/grupos_permissao.php`). Só no router = tela impossível de conceder (o admin não tem o que marcar). Só na matriz = o `view` **não é verificado por ninguém** — as ações de escrita dão 403 e a tela abre para todo mundo, então negá-la ao grupo não faz nada. Já aconteceu quatro vezes: `checklist` e `wiki` (v4.8.5), `config-notificacoes` e `config-smtp` (v4.8.9).

- **Célula de fórmula no XLSX sem `<v>` aparece VAZIA.** `XlsxWriter` escreve o link do mapa como `=HYPERLINK(url;"MAPA")`, e uma fórmula sem **valor em cache** só ganha conteúdo depois que o programa recalcula a planilha. Quem abre em visualizador que não recalcula — preview do Google Sheets, Numbers, painel do Windows — vê a coluna sumir, enquanto no PDF (que não depende de recálculo) o mesmo link aparece normalmente. A assimetria "no PDF tem, no Excel não" é a assinatura desse defeito. Sempre emitir `<f>…</f><v>rótulo</v>`.

- **O nome do alarme é resolvido UMA vez, na chegada do webhook.** `pushalarm.php` consulta `alarm_types` e grava o resultado em `alarms.alarm_name`; código fora do catálogo naquele instante vira `Código NNNN (JTT)` e fica gravado assim **para sempre**, mesmo depois de o código entrar em `alarm_types`. Por isso `rel_alarmes.php` re-resolve na leitura — mas só o rótulo genérico: o nome gravado vence sempre que for um nome de verdade, senão o `Fim de Alarme: ` (evento de FIM) e o bitmask decodificado do JT/T 256 seriam apagados pelo nome do catálogo. Códigos novos entram por migração (`migration_v4.9.0.sql`, `migration_v4.9.10.sql`). A regra é **nunca batizar por palpite** — não "só o que a doc publica": `1047` não consta da doc e foi catalogado como `Capotamento` na v4.9.10 com informação do fornecedor, corroborada pelo bit 28 do bitmask JT/T (`Pré-aviso de Capotamento`) e pela ordem das unidades ADAS na faixa 1042–1046.
  - ⚠️ **Ao cadastrar um código, procure o MESMO evento no outro protocolo antes de escolher o nome.** Capotamento já existia como JIMI `45` sob o rótulo `Veículo Tumbado`; cadastrar `1047` como `Capotamento` e parar aí teria partido o evento em dois rótulos, e **o filtro da tela casa por nome** — o usuário escolheria um e perderia metade dos eventos. Mesma razão pela qual a v4.9.0 repetiu nomes de propósito nos pares 1024/1042.
  - ⚠️ **Alarme catalogado sem parâmetro em `occurrence_config_params` não gera ocorrência nenhuma** — `process_alarm_occurrence()` retorna cedo quando `get_occurrence_param()` devolve NULL. E a **categoria** decide se notifica: `Colisão do Veículo` está em `veiculo`, para a qual não há regra, e por isso **não dispara notificação** (registrado na v4.9.10, não corrigido — muda volume).

- **`commands.response_payload` é coluna JSON — nunca grave string crua nela.** Escrever `(string)$response` faz o MySQL recusar com `3140 Invalid JSON text` para toda resposta de texto do device (`ext Battery:12.1V…`), que é o caso normal. Isso quebrou **todo** callback de comando offline por meses sem aparecer, porque o `catch` do método era silencioso e a resposta continuava sendo salva em `command_responses` (essa é TEXT). Use `json_encode()` sempre. A lição maior: `catch` silencioso em caminho de webhook esconde defeito que só se manifesta como "o comando nunca sai de pendente".

- **Trocar um valor de configuração no código não muda o que o usuário vê — o BANCO vence.** `mail_config()` (`includes/mailer.php`) resolve na ordem **linha de `smtp_settings` → `.env` → literal no código**, então corrigir só o literal não tem efeito nenhum enquanto existir linha no banco. Pior: uma coluna com `DEFAULT` reintroduz o valor antigo **sozinha**, sem ninguém digitá-lo — foi assim que `from_name` ficou `'Jimi Tracker'` (DEFAULT criado na v4.4.1) e os relatórios agendados chegaram meses assinados com o nome antigo, mesmo depois de a v4.8.0 declarar a marca trocada. **Renomear valor de configuração exige varrer as três camadas e migrar o DEFAULT junto** (ver `migration_v4.9.4.sql`). No `UPDATE` da migração, case a string **exata** do DEFAULT: quem personalizou o valor não pode ser sobrescrito. E a prova é o artefato final — o cabeçalho `From:` da mensagem —, não o valor no banco.

- **`serverFlagId` NÃO é o seletor de gateway que `sendcommand.php` supõe.** A doc oficial o define como a chave de correspondência requisição↔resposta ("unique identification field for the current request"), e é o `_serverFlagId` que volta no callback. Aqui ele vale 0 (JT/T) ou 1 (JIMI), então não distingue dois comandos do mesmo device. `requestId`, ao contrário do que o backlog supunha, é só para log tracing e **não volta no callback**. Corrigir isso mexe no despacho para veículo real e exige device real para verificar (M.2.5).

- **JIMI vs JT/T 808 protocol isolation is strict** (see `docs/adr/ADR-001.md`). `msgClass=0` is JIMI, `msgClass=1` is JT/T 808 — never mix them. Command presets and config flows are protocol-sensitive.

- **Timezone**: all DB timestamps are UTC (connection forces `time_zone = '+00:00'`; devices transmit GMT 0; PHP runs UTC); the dashboard converts to BRT (America/Sao_Paulo) at display time only — **always via `fmt_brt()`** (`includes/functions.php`). Date filters typed by the user are BRT days: convert to UTC windows with `brt_day_range_to_utc()`; "today" defaults use `brt_today()`. Pure DATE columns (`activation_date`, `cnh_expires_at`…) must NOT go through `fmt_brt()` (day would shift). Hourly/daily SQL groupings use `CONVERT_TZ(col, '+00:00', '-03:00')`.

- **`.env` loading is manual.** `config/database.php` parses `.env` line-by-line into `putenv()` (no dotenv library). Read config with `getenv()`. The PDO singleton (`Database::getInstance()`) is the only DB connection.

- **`sendcommand.php`** accepts both JSON (`Content-Type: application/json`) and form-urlencoded POST; `content` aliases `cmdContent`. proNo whitelist spans 128–34818 (config commands use 33027–34818).

- **Command polling**: after dispatch the frontend polls `/commandstatus?command_id=X` — fast (every 3s for 30s) then slow (every 10s for 5min), then times out as "Comando em fila offline".

- **CSS has no build step.** The whole design system is inlined in `web/layout_base.php` (+ `web/login_template.php`, `handlers/setup.php`). **O design é o da Coinbase** (`DESIGN-coinbase.md` → `DESIGN.md`): azul `#0052ff` (única voltagem), canvas branco, **sidebar dark near-black `#0a0b0d`** com item ativo azul, CTAs **pill (100px)**, cards com hairline + um único nível de sombra no hover, headings de display Inter peso 400, JetBrains Mono em todo número/IMEI. A navegação (alvo) usa sidebar com grupos-sanfona. (As versões ≤3.x usavam a paleta Cursor creme/laranja — substituída; a paleta roxa YUV foi proposta e descartada em favor da Coinbase.)

## Key files

- `handlers/router.php` — front controller / route table
- `includes/download_token.php` + `handlers/download.php` — link de relatório **assinado com validade** (`/download?j=&exp=&sig=`). Único caminho de download: `storage/reports/` é negado no Apache. Sem login de propósito — a autorização é a assinatura, porque o link viaja por e-mail
- `includes/functions.php` → **`report_customer_scope()`** — ponto único do escopo multi-tenant dos relatórios. Toda tela nova que aceite `?customer_id` **tem** de passar por ela: para não-admin o parâmetro é ignorado, não validado
- `config/WebhookHandler.php` — abstract base for all `push*.php` receivers
- `config/database.php` — PDO singleton + `.env` parser
- `includes/auth.php` — `require_login()`, `require_admin()`, `get_jimi_user()`, `get_customer_id()`, `login_user()`, `set_customer_context()`
- `includes/functions.php` — `get_webhook_data()`, `normalize_data()`
- `core/Logger.php` — static logger (daily file naming, DEBUG→CRITICAL; level via `LOG_LEVEL` in `.env` — `DEBUG` enables raw webhook payloads; purge/rotation via cron `scripts/log_cleanup.php`, `LOG_RETENTION_DAYS`/`LOG_MAX_SIZE_MB`)
- `web/layout_base.php` / `layout_ativo_sidebar.php` / `layout_base_close.php` — dashboard shell (sidebar + header + content)
- `mysql/jimi_tracker.sql` + `migration_v2.0.0.sql` + `migration_v3.1.0.sql` — schema (22 tables; v3.1.0 added `customers`, `users`, `customer_users`, `sessions`, `device_models`)

## Code style

- New webhook handlers **must** extend `WebhookHandler` and implement only `processItem()`.
- Comments in **PT-BR**; PHPDoc with `@param`/`@returns`/`@throws`.
- Follow Keep a Changelog in `CHANGELOG.md`.

## Not part of the application

`.agents/`, `.opencode/` are external agent-tooling frameworks (skills, sub-agent definitions), unrelated to the webhook system. Ignore them when working on application code.
