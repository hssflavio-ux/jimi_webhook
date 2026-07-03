# STATUS.md — Jimi Webhook System v3.2.0 (Review)

> Última atualização: 02/07/2026 — Bugs #16–#19 corrigidos (parse error em /comandos, `$extra_head` dentro do `<style>`, token ausente no polling, JSON quotado nos presets JT/T). Feature: presets de texto proNo 128 disponíveis para câmeras JT/T (optgroup "Texto (proNo 128)" em /comandos)
> Servidor: `http://189.22.240.43` (Apache 2.4 + PHP 8.3 + MySQL)

---

## 1. Resumo Geral

Reconstrução completa do dashboard no estilo NavTrack, mantendo PHP puro (sem frameworks JS). Multi-tenant com "Clientes", autenticação via token em cookie (sem dependência de `session_start()`), 16 rotas novas (incluindo `/usuarios` e `/perfil`), 9 abas no detalhe do ativo, player de vídeo HTTP-FLV, envio de comandos com polling ativo, relatórios com filtros, CRUD completo de clientes/ativos/usuários.

**v3.2.0** (30/06/2026): Gestão de usuários (`/usuarios`, admin only), página de perfil (`/perfil`, troca de senha), `camerasdata.php` com auth dupla (sessão + token), auto-refresh no dashboard (30s), arquivos legados movidos para `archive/`.

**Esta revisão geral** (REVIEW_PLAN.md, 30/06/2026): Auditoria completa de 37 arquivos PHP em 7 fases. Resultado: **3 bugs críticos, 5 riscos de segurança HIGH, 12 bugs/riscos MEDIUM**. Ver §13 para o relatório completo.

---

## 2. Arquivos Criados (22 novos)

### Database
| Arquivo | Descrição |
|---|---|
| `mysql/migration_v3.1.0.sql` | Migration idempotente: 5 tabelas + alter devices + seeds |

### Core / Auth
| Arquivo | Função |
|---|---|
| `includes/auth.php` | Token-based auth: `jimi_token` cookie → tabela `sessions` MySQL. Funções: `auth_init()`, `require_login()`, `require_admin()`, `get_jimi_user()`, `get_customer_id()`, `get_customer()`, `login_user()`, `logout_user()`, `set_customer_context()`, `get_available_customers()` |
| `handlers/router.php` | Front controller: parse de URL multi-segmento → dispatch para handlers |
| `handlers/login.php` | GET: formulário login. POST: autentica + redireciona |
| `handlers/logout.php` | Destroi token no DB + cookie |
| `handlers/setup.php` | Cria primeiro admin (só quando tabela users vazia) |
| `handlers/customer_switch.php` | AJAX POST: troca contexto de cliente na sessão |

### Layout
| Arquivo | Função |
|---|---|
| `web/layout_base.php` | Shell HTML: sidebar esquerda + header + área de conteúdo. CSS design system inline (Inter + JetBrains Mono, paleta DESIGN.md) |
| `web/layout_base_close.php` | Fecha tags do layout |
| `web/layout_ativo_sidebar.php` | Sidebar secundária para detalhe do ativo (9 abas) |
| `web/login_template.php` | Template isolado da tela de login |

### Páginas do Dashboard
| Arquivo | Rota | Funcionalidades |
|---|---|---|
| `handlers/dashboard.php` | `/dashboard` | KPI cards + tabela de ativos + mapa Leaflet (clique → centraliza) |
| `handlers/ativos.php` | `/ativos` | Lista + editar inline + remover (soft-delete) |
| `handlers/ativos_novo.php` | `/ativos/novo` | Cadastro com dropdown de modelos (auto-preenche câmeras) |
| `handlers/ativo_detalhe.php` | `/ativos/{imei}` | 9 abas: Visão Geral, Ao Vivo (mapa), Trajetos, Alertas, Log, Relatórios, Vídeo, Comandos, Configurações |
| `handlers/live.php` | `/live` | Mapa multi-ativo com auto-refresh 30s |
| `handlers/relatorios.php` | `/relatorios` | Alarmes (filtro severidade/categoria), Trajetos, Comandos |
| `handlers/video.php` | `/video` | Player HTTP-FLV ao vivo + arquivos gravados. Envia proNo 37121 antes de tocar |
| `handlers/comandos.php` | `/comandos` | Modelo-sensível (JIMI/JT/T), presets por protocolo, polling 3s/10s/5min |
| `handlers/config.php` | `/config` | Query/set parâmetros (proNo 33027-33031) |
| `handlers/clientes.php` | `/clientes` | CRUD clientes (admin only): criar, editar, desativar |
| `handlers/usuarios.php` | `/usuarios` | CRUD usuários (admin only): criar, editar, ativar/desativar, vínculo a cliente. Proteção contra autodesativação |
| `handlers/perfil.php` | `/perfil` | Troca de senha do próprio usuário (valida senha atual, qualquer role) |
| `handlers/devicemodels.php` | `/devicemodels` | AJAX: lista modelos para dropdowns |

---

## 3. Arquivos Modificados (5)

| Arquivo | Alteração |
|---|---|
| `.htaccess` | Front controller: `RewriteRule ^(.*)$ handlers/router.php`. Regra extra para raiz (`^$`) e para `/config` (evita conflito com diretório) |
| `.env.example` | `SYSTEM_VERSION=3.1.0` |
| `AGENTS.md` | Atualizado com novas rotas, auth, DB, gotchas |
| `handlers/sendcommand.php` | Aceita JSON body. `content` alias `cmdContent`. proNos estendidos (128-34818) |
| `handlers/commandstatus.php` | Filtro `?command_id=X` para polling single-command. Suporte `?customer_id=` |

---

## 4. Banco de Dados

### Novas Tabelas (5)
| Tabela | Registros | Descrição |
|---|---|---|
| `customers` | 1 (Frota Principal) | Clientes multi-tenant |
| `users` | 1+ (admin criado via /setup) | Usuários (bcrypt) |
| `customer_users` | 1+ | Vínculo cliente↔usuário (role) |
| `sessions` | variável | Tokens de autenticação (64-char hex) |
| `device_models` | 6 (JC400D, JC400AD, JC371, JC450, JC181, JC182) | Catálogo de modelos com protocolo + câmeras |

### Alterações em `devices`
- `customer_id` FK → customers
- `device_model_id` FK → device_models
- `camera_count` INT DEFAULT 1
- `created_by` FK → users
- Índices: `idx_dev_customer`, `idx_dev_model`
- FKs: `fk_dev_customer`, `fk_dev_model`

---

## 5. Fluxo de Autenticação

```
1. /setup          → cria admin (bcrypt) + vincula ao cliente 1
2. /login (GET)    → formulário (não carrega auth.php)
3. /login (POST)   → login_user() → gera token 64-char → setcookie jimi_token → INSERT sessions
                    → set_customer_context() → UPDATE sessions.customer_id
                    → redirect /dashboard
4. /dashboard      → require_login() → auth_init() → lê cookie → SELECT sessions → popula $_SESSION
                    → refresh_session() → UPDATE expires_at
5. /logout         → DELETE FROM sessions → clear cookie
```

**Cookie**: `jimi_token` — 64 caracteres hex, HttpOnly, Path=/  
**Tabela**: `sessions` (id = token, user_id, customer_id, expires_at)  
**Sem dependência de `session_start()`** — o token é a fonte da verdade no MySQL.

---

## 6. Bugs Corrigidos Durante o Desenvolvimento

| # | Bug | Causa | Correção | Arquivo |
|---|---|---|---|---|
| 1 | Tela de login não abre | `get_current_user()` conflito com função built-in do PHP | Renomeada `get_jimi_user()` em 10 arquivos | `auth.php` + handlers |
| 2 | Login não redireciona | `session_start()` depende de arquivos em disco sem permissão | Substituído por token cookie + tabela `sessions` | `auth.php` (reescrito) |
| 3 | `http://IP/` retorna 500 | `index.php` incluía `auth.php` quebrado | Removido `index.php`. `.htaccess`: `RewriteRule ^$ router.php` | `.htaccess` |
| 4 | `/config` retorna 403 | Apache via diretório `config/` e `RewriteCond !-d` falha | `RewriteRule ^config$ router.php [L]` antes das condições | `.htaccess` |
| 5 | Migration re-aplica v2.0.0 | `DB_VERSION != "2.0.0"` verdadeiro quando 3.1.0 | Só aplica se `DB_VERSION = "0"` | `deploy.sh` |
| 6 | Migration v3.1.0 falha no ALTER TABLE | Colunas já existem de execução anterior | Procedure `add_column_if_not_exists` + FKs condicionais | `migration_v3.1.0.sql` |
| 7 | Polling comandos para silenciosamente | `if (!cmd) return;` sem retry | Continua polling mesmo sem encontrar comando ainda | `comandos.php` |
| 8 | Vídeo URL errada | `/live/{IMEI}_{CH}.flv` em vez de `/{CH}/{IMEI}.flv` | URL corrigida + botão "Iniciar Transmissão" envia proNo 37121 | `video.php` |
| 9 | `set_customer_context()` não persistia | `$_COOKIE` não tem o token na mesma request | `$GLOBALS['_auth_token']` no `login_user()` | `auth.php` |
| 10 | `_gen_token()` fallback quebrado | `bin2hex()` em string ASCII | `md5()` duplo como fallback | `auth.php` |
| 11 | Edit de ativos não salvava | `f.action` retorna URL do form, não campo hidden | `f.querySelector('[name=action]').value` | `ativos.php` |
| 12 | Botão "Detalhes" não navega para detalhe do ativo | Click handler da row `.device-row` interceptava cliques em links/botões internos (evento bubbling sem early return) | Adicionado `onclick="event.stopPropagation()"` no `<a>` + `e.target.closest('a, button')` return no handler da row | `dashboard.php` |
| 13 | Dispositivos removidos continuam visíveis no painel/mapa/live | Queries de `dashboard.php`, `camerasdata.php`, `live.php`, `comandos.php` não filtravam `is_active=1`. Soft-delete (`is_active=0`) não era respeitado fora de `ativos.php` | Adicionado `AND d.is_active = 1` em todas as queries de dispositivos (KPIs, listagens, auto-refresh) + `WHERE d.is_active = 1` no `camerasdata.php` | `dashboard.php`, `camerasdata.php`, `live.php`, `comandos.php` |
| 14 | Comandos não exibem resposta (nem do dispositivo nem erro do IoTHub) | Polling bar mostrava apenas status genérico ("Resposta recebida!" / "Falha") sem conteúdo da resposta. `sendCommand()` não exibia detalhes do erro IoTHub (código, mensagem, status HTTP). Tabela de histórico não tinha coluna Resposta | Polling bar agora exibe `cmd.response` do `commandstatus.php` em div dedicada. Erro de envio mostra `iothub_msg`, `iothub_code` e alerta de equipamento offline. Tabela de histórico com coluna "Resposta" que extrai preview do `response_payload` (mesma lógica do `commandstatus.php`) | `comandos.php` |
| 15 | `/comandos` retorna HTTP 500 após correção #14 | Uso de `mb_substr()` (extensão `mbstring` não disponível no servidor) na nova coluna Resposta da tabela de histórico | Substituído `mb_substr()` por `substr()`. Cadeia `??` trocada por `isset()` para consistência com o padrão do projeto. **Não resolveu — ver #16** | `comandos.php` |
| 16 | `/comandos` continua com HTTP 500 após correção #15 | **Causa real do 500**: aspas simples não escapadas em `font-family: 'JetBrains Mono'` (linha 62, CSS `.poll-response` adicionado na correção #14) dentro da string single-quoted `$extra_head` → parse error em tempo de compilação. Por isso o 500 ocorria antes até do `require_login()` (curl sem sessão recebia 500, não 302). O `mb_substr()` de #15 nunca chegava a executar | Aspas do CSS trocadas para duplas: `font-family: "JetBrains Mono", monospace` | `comandos.php` |
| 17 | Spinner ("ampulheta") do envio aparece animado ao abrir `/comandos` sem nenhuma ação | `layout_base.php` imprimia `$extra_head` **dentro** do bloco `<style>` global (antes do `</style>`). O `<style>` da página ficava aninhado e o parser CSS descartava a regra `.poll-bar{display:none}` (seletor poluído pelo texto literal `<style>`). Efeito colateral em TODAS as páginas: `<link>`/`<script>` do Leaflet (dashboard/live) e do flv.js (video) eram engolidos como texto CSS | `<?= $extra_head ?>` movido para **depois** do `</style>`, direto no `<head>`. Removida a classe `poll-spinner` do container `#poll-spinner` (evitava spinner duplo e círculo girando mesmo após resposta/falha) | `layout_base.php`, `comandos.php` |
| 18 | Respostas de comandos nunca aparecem (nem resposta do dispositivo, nem erro da API com câmera offline); modal de detalhes do histórico não abre | `poll()` e `showDetail()` chamavam `/commandstatus` **sem** o token (`X-Dashboard-Token`) → HTTP 401 → `data.commands` nunca chegava: polling girava para sempre e o modal retornava em silêncio. Backend verificado OK via curl com `_token` (pipeline `pushinstructresponse` → `commands.response_payload` → `response` funciona) | Header `X-Dashboard-Token: dashToken` adicionado aos dois `fetch`. Branch `!cmd` do polling agora exibe timeout na fase 2 (antes parava em silêncio) e `.catch` re-agenda em falha de rede | `comandos.php` |
| 19 | Presets JT/T enviam conteúdo corrompido: `"{}"` (string quotada) no lugar do JSON; e `{}` chega ao IoTHub como `[]` | (a) `fillJttPreset()` fazia `JSON.stringify(p.content)` sobre `p.content` que **já é string JSON** → resultado quotado (`'"{}"'`) — visível no histórico como comando `"{}"`. (b) `sendcommand.php` re-serializa com `json_decode(assoc)` + `json_encode`, e objeto vazio `{}` vira array `[]` em PHP — comando #11 de teste gravado como `[]` | (a) `fillJttPreset()` agora formata com `JSON.stringify(JSON.parse(p.content), null, 2)` com fallback. (b) `sendcommand.php` restaura `{}` quando o canônico deu `[]` e o original começava com `{` | `comandos.php`, `sendcommand.php` |

---

## 7. Status de Cada Tela

| Tela | Status | Observações |
|---|---|---|
| **Login** | Funcional | GET mostra form; POST autentica via token cookie |
| **Setup** | Funcional | Cria admin + vincula cliente 1; redirect imediato ao login |
| **Painel (Dashboard)** | Funcional | KPI + tabela ativos + mapa Leaflet (clique centraliza). Sem "Atividade Recente" |
| **Ao Vivo** | Funcional | Mapa multi-ativo com circle markers; "Sem dados" se vazio; auto-refresh 30s |
| **Ativos** | Funcional | Lista + editar inline + remover (soft-delete is_active=0) |
| **Ativos → Novo** | Funcional | Dropdown de device_models com auto-preenchimento de câmeras |
| **Ativos → Detalhe** | Funcional | 9 abas com sidebar lateral; mapa Leaflet na aba Ao Vivo |
| **Relatórios** | Funcional | Abas Alarmes/Trajetos/Comandos; filtros data/IMEI/severidade/categoria |
| **Vídeo** | Funcional | Envia proNo 37121 → toca HTTP-FLV; arquivos gravados via HTML5 |
| **Comandos** | Funcional | Detecta protocolo do device_models; presets JIMI/JT/T; polling com retry |
| **Configuração** | Funcional | Query/set parâmetros via proNo 33027-33031; acesso corrigido (não mais 403) |
| **Clientes** | Funcional | CRUD completo: criar, editar, desativar (admin only) |

---

## 8. Webhook Endpoints (preservados)

Todos os 12 endpoints de webhook continuam funcionando inalterados:

| Endpoint | Handler | Protocolo |
|---|---|---|
| `/pushevent` | `pushevent.php` | Seção 1.1 |
| `/pushhb` | `pushhb.php` | Seção 1.2 |
| `/pushgps` | `pushgps.php` | Seção 1.3 |
| `/pushalarm` | `pushalarm.php` | Seção 1.4 (JIMI + JT/T) |
| `/pushfileupload` | `pushfileupload.php` | Seção 1.8 |
| `/pushlbs` | `pushlbs.php` | Seção 1.10 |
| `/pushresourcelist` | `pushresourcelist.php` | Seção 1.11 |
| `/pushftpfileupload` | `pushftpfileupload.php` | Seção 1.12 |
| `/pushiothubevent` | `pushiothubevent.php` | Seção 1.13 |
| `/pushTerminalTransInfo` | `pushTerminalTransInfo.php` | Seção 1.15 |
| `/pushinstructresponse` | `pushinstructresponse.php` | Seção 1.16 (atualiza commands.status) |
| `/pushcmd` | `pushcmd.php` | Legacy |

Roteados via `router.php` sem modificação — o front controller apenas inclui o arquivo PHP.

---

## 9. Ambiente e Deploy

### Servidor
- **IP**: `189.22.240.43`
- **Apache**: 2.4 com mod_rewrite
- **PHP**: 8.3 (FPM)
- **MySQL**: em localhost
- **Path**: `/var/www/jimi_webhook`

### Variáveis de Ambiente (.env)
```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=jimi_tracker
DB_USER=root
DB_PASS=***
WEBHOOK_TOKEN=a12341234123
SYSTEM_VERSION=3.1.0
FILE_STORAGE_URL=http://189.22.240.43:23010/download/
STREAM_URL=http://189.22.240.43:8881
IOTHUB_COMMAND_URL=http://localhost:10088/api/device/sendInstruct
IOTHUB_API_TOKEN=123
```

### Deploy
```bash
./scripts/deploy.sh          # deploy normal (backup + pull + migrate)
./scripts/deploy.sh --force  # redeploy mesmo sem mudanças
./scripts/update-homolog.sh  # atualização completa com status do banco
```

### Migrations (ordem correta)
```bash
mysql -u root -p < mysql/jimi_tracker.sql         # schema base
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql   # v2.0.0 (command_responses)
mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql   # v3.1.0 (multi-tenant, auth)
```

A migration v3.1.0 é **idempotente** — pode ser executada múltiplas vezes sem erro.

---

## 10. Pendências e Próximos Passos

> **Atualização v3.2.0** (ver `PLAN.md`): itens abaixo marcados com ✅ foram implementados nesta rodada.
> - `handlers/camerasdata.php`: aceita sessão de dashboard (cookie) OU token compartilhado (`token`/`_token`/header); filtra dispositivos por `customer_id` quando há sessão ativa; retorna `lat`/`lng`/`acc`/`last`/`is_online` por dispositivo.
> - `handlers/dashboard.php`: auto-refresh a cada 30s (tabela + marcadores do mapa) via `/camerasdata`.
> - Legados v2.0.0 movidos para `archive/` (não excluídos): `archive/web/dashboard_template.php`, `archive/web/assets/js/dashboard.js`, `archive/includes/dashboarddata.php`.
> - Novas rotas `/usuarios` (admin, CRUD de usuários vinculados a cliente) e `/perfil` (troca de senha) registradas em `router.php`; links adicionados ao sidebar (`web/layout_base.php`).
> - **Revisão geral completa** (30/06/2026): 37 arquivos PHP auditados em 7 fases — ver §13.

### CRÍTICO (Segurança — Correção Imediata)
- [ ] **R01 — `camerasdata.php` vaza dados cross-tenant via token**: Quando acessado sem sessão (apenas com token, como faz o auto-refresh do dashboard e do live), retorna TODOS os dispositivos de TODOS os clientes. Só filtra por `customer_id` quando há sessão ativa. **Impacto**: qualquer pessoa com o token de webhook vê/dispositivos de todos os clientes.
- [ ] **R02 — Cross-tenant data leak nos 6 endpoints AJAX**: `commandstatus.php`, `sendcommand.php`, `mediadata.php`, `trackdata.php`, `hbdata.php`, `commandstatus.php` — NENHUM filtra por `customer_id` quando acessado via token. **Impacto**: vazamento de dados de GPS, heartbeat, comandos e mídia entre clientes. `sendcommand.php` ainda permite ENVIAR comandos para qualquer IMEI.
- [ ] **R03 — `sendcommand.php` não bloqueia proNo desconhecidos**: proNos fora da whitelist são apenas logados (warning), mas o comando é enviado mesmo assim. **Impacto**: comandos arbitrários podem ser injetados se o token vazar.

### ALTO (Risco/Bug — Próximo Deploy)
- [ ] **R04 — `relatorios.php` SQL injection via string interpolation**: $_GET params (`imei`, `from`, `to`, `severity`, `category`) interpolados em string SQL com `$db->quote()`. Padrão frágil — converter para prepared statements.
- [ ] **R05 — `login.php` open redirect**: parâmetro `redirect` usado sem validação em `header('Location: ' . $redirect)`. Atacante pode craftar `/login?redirect=https://evil.com`. Fix: validar que redirect é path local.
- [ ] **R06 — `pushgps.php` descarta silenciosamente coordenadas (0,0)**: `is_valid_coordinate()` retorna false para (0,0), que é um sinal válido de "sem fix GPS". O handler descarta o ponto e NÃO atualiza `device_statistics`. **Impacto**: dashboard/live mostra posição stale do dispositivo.
- [ ] **R07 — `request_logs` sem índice em `payload_hash`**: `isDuplicateRequest()` faz `SELECT COUNT(1) FROM request_logs WHERE payload_hash = ?` sem índice → full table scan em toda requisição. Tabela cresce ~25K linhas+ com risco de timeout no webhook. Fix: `CREATE INDEX idx_payload_hash_created ON request_logs(payload_hash, created_at)`.
- [ ] **R08 — Rotas mortas em `router.php`**: `/clientes/novo` → `clientes_novo.php` e `/clientes/{id}` → `cliente_dashboard.php` — **nenhum dos dois arquivos existe**. Acesso resulta em 404 "Handler não encontrado".
- [ ] **R09 — `pushcmd.php` é código morto**: handler legado superseded por `pushinstructresponse.php`, mas ainda registrado em `router.php`. Insere lixo na tabela `commands` (linhas "Orphan Response"). Remover do router e do disco.
- [ ] **R10 — `.env` local desatualizado**: `SYSTEM_VERSION=3.0.0` (deveria ser 3.2.0), e faltam `IOTHUB_COMMAND_URL` e `IOTHUB_API_TOKEN`. O servidor de produção pode estar usando `.env` diferente — verificar.

### MÉDIO (Dívida Técnica e Consistência)
- [ ] **R11 — CSRF ausente em TODOS os formulários POST**: `ativos.php`, `ativos_novo.php`, `clientes.php`, `usuarios.php`, `perfil.php`, `comandos.php` — nenhum token anti-CSRF. Ataque possível: página maliciosa que faz POST para deletar ativo/usuário com cookie da vítima.
- [ ] **R12 — String interpolation de `$customer_id` em 8 arquivos**: `dashboard.php`, `ativos.php`, `live.php`, `video.php`, `comandos.php`, `config.php`, `relatorios.php`, `ativos.php`. O valor vem da sessão (seguro), mas padrão inconsistente com o resto do código que usa prepared statements.
- [ ] **R13 — `pushTerminalTransInfo.php` não extrai dados estruturados**: Só salva `extensionId` e raw payload. `lat`, `lng`, `speed`, `content` do JSON não são parseados para colunas. Tabela `device_events` é basicamente um blob store.
- [ ] **R14 — `normalize_data()` faltam aliases**: `lon` → `longitude`, `msgId` → `msg_id`, `accStatus` → `acc`. Handlers fazem fallback manual (código duplicado em `pushevent.php:30,69` e `pushgps.php:25,36`).
- [ ] **R15 — Dupla normalização em `pushalarm.php:40` e `pushresourcelist.php:25`**: Chamam `normalize_data()` de novo, mesmo a base class já tendo chamado. É inócuo mas desperdiça CPU.
- [ ] **R16 — Bloco de código morto em `pushresourcelist.php:28-37`**: Tenta decodificar `data_list` dentro de `processItem()`, mas a base class já extraiu os itens do `data_list` antes de despachar.
- [ ] **R17 — `md5(json_encode(...))` sem `JSON_UNESCAPED_UNICODE`**: Em `WebhookHandler.php:48`, pode gerar hashes diferentes para o mesmo payload Unicode entre versões de PHP.
- [ ] **R18 — Cookie `Secure=false`**: `setcookie()` em `auth.php:171` e `:202` usa `$secure=false`. Se produção usa HTTPS, deveria ser `true`.
- [ ] **R19 — Rotina de limpeza de `sessions` e `request_logs`**: `request_logs` cresce sem auto-purge (Logger tem 30 dias, mas request_logs não). `sessions` expiradas não são limpas automaticamente.
- [ ] **R20 — `web/index.php` legado ainda existe na raiz**: Não é mais alcançável via router, mas ainda está em `web/`. Mover para `archive/`.

### BAIXO (Melhorias Futuras)
- [ ] **Verificar se comandos estão realmente chegando aos dispositivos**: Fluxo sendcommand → IoTHub → dispositivo → pushinstructresponse → commands.status precisa ser testado end-to-end
- [ ] **Arquivos de mídia**: Verificar se `/pushfileupload` está populando `media_files` corretamente para a tela de Vídeo/Gravações
- [ ] **Relatórios**: Adicionar exportação CSV
- [ ] **Detalhe do ativo**: Aba "Relatórios" ainda é placeholder
- [ ] **Logs de acesso**: Registrar tentativas de login (sucesso/falha) em tabela `access_logs`
- [ ] **Rate limiting** no login: Prevenir brute-force
- [ ] **Dashboard responsivo**: Sidebar colapsável em mobile
- [ ] **Tema escuro**: Variáveis CSS alternativas
- [ ] **Deploy & CI**: Instalar PHP CLI no ambiente de dev ou configurar pipeline de lint em pre-commit hook
- [ ] **`pushcmd.php` cleanup**: Confirmar com Jimi se endpoint ainda é usado; remover do router e disco
- [ ] **Endpoints Jimi não implementados**: 12 endpoints mapeados em API_COVERAGE.md §"Not Yet Implemented" — confirmar priorização com produto
- [ ] **Duplicação de JS de mapa**: `live.php` e `dashboard.php` têm lógica Leaflet quase idêntica (`upsertMarker`/`loadMarkers`) — extrair para `web/assets/js/map-utils.js` se crescer mais

---

## 11. Estrutura de Arquivos do Projeto

```
jimi_webhook/
├── .env                          # Credenciais (gitignored)
├── .env.example                  # Template
├── .htaccess                     # Front controller + security headers
├── AGENTS.md                     # Guia para AI agents
├── STATUS.md                     # Este arquivo
├── DESIGN.md                     # Design system tokens (paleta Cursor-inspired)
│
├── config/
│   ├── database.php              # PDO singleton
│   └── WebhookHandler.php        # Abstract webhook base class
│
├── core/
│   └── Logger.php                # Static logger (daily rotation)
│
├── includes/
│   ├── auth.php                  # Token-based authentication (MySQL-backed)
│   └── functions.php             # normalize_data(), get_webhook_data(), etc.
│
├── handlers/
│   ├── router.php                # Front controller (URL dispatch)
│   ├── login.php                 # Login page
│   ├── logout.php                # Logout
│   ├── setup.php                 # First admin setup
│   ├── customer_switch.php       # AJAX: switch customer context
│   ├── dashboard.php             # Main dashboard (KPI + table + map)
│   ├── ativos.php                # Device list + edit/delete
│   ├── ativos_novo.php           # New device form
│   ├── ativo_detalhe.php         # Asset detail (9 tabs)
│   ├── live.php                  # Multi-asset live map
│   ├── relatorios.php            # Reports hub
│   ├── video.php                 # Video player (FLV + MP4)
│   ├── comandos.php              # Command dispatch
│   ├── config.php                # Device configuration
│   ├── clientes.php              # Customer management (admin)
│   ├── usuarios.php              # User management (admin) — v3.2.0
│   ├── perfil.php                # Profile / password change — v3.2.0
│   ├── devicemodels.php          # AJAX: device models list
│   ├── camerasdata.php           # AJAX: device list + API status
│   ├── commandstatus.php         # AJAX: command history + polling
│   ├── sendcommand.php           # AJAX: send command to IoTHub
│   ├── mediadata.php             # AJAX: media files
│   ├── trackdata.php             # AJAX: GPS tracks
│   ├── hbdata.php                # AJAX: heartbeats
│   ├── ping.php                  # Health check
│   └── push*.php (12 webhooks)   # Webhook receivers
│
├── web/
│   ├── layout_base.php           # Main layout (sidebar + header)
│   ├── layout_base_close.php     # Close layout tags
│   ├── layout_ativo_sidebar.php  # Asset secondary sidebar
│   ├── login_template.php        # Login page template
│   └── index.php                 # LEGACY — v2.0.0 wrapper (não alcançável, mover p/ archive)
│
├── mysql/
│   ├── jimi_tracker.sql          # Base schema (v1.0.0)
│   ├── migration_v2.0.0.sql      # v2.0.0 migration
│   └── migration_v3.1.0.sql      # v3.1.0 migration (idempotent)
│
├── scripts/
│   ├── deploy.sh                 # Deploy (backup + pull + migrate + verify)
│   └── update-homolog.sh         # Homologation update (full DB status)
│
├── docs/
│   ├── PRD.md                    # Product Requirements Document
│   └── adr/ADR-001.md            # JIMI vs JT/T protocol isolation decision
│
└── logs/                         # Runtime logs (gitignored)
```

---

## 12. Decisões Técnicas Chave

1. **Token em cookie vs PHP sessions**: PHP `session_start()` depende de arquivos em disco com permissão de escrita. Substituído por token aleatório 64-char armazenado na tabela `sessions`. Cookie `jimi_token` HttpOnly.

2. **Front controller vs rewrite rules**: `.htaccess` com `RewriteRule ^(.*)$ handlers/router.php` — todas as URLs passam pelo router que faz dispatch baseado nos segmentos da URL.

3. **Multi-tenant via customer_id**: Cada dispositivo pertence a um cliente. Usuários são vinculados a clientes via `customer_users`. Contexto do cliente é selecionado no dropdown da sidebar e armazenado em `sessions.customer_id`.

4. **PHP < 7.3 compatibilidade**: Todo código usa `isset()` em vez de `??`, `array()` em vez de `[]`, e `setcookie()` com parâmetros individuais (não array).

5. **URL `/config` vs diretório `config/`**: Conflito resolvido com `RewriteRule ^config$ handlers/router.php [L]` antes das condições `!-f !-d`.

6. **Mapas Leaflet + OpenStreetMap**: Todos os 3 mapas (dashboard, live, ativo detalhe) usam tiles OSM gratuitos sem API key.

7. **Video streaming**: Envia proNo 37121 (JT/T 808) para iniciar stream, depois toca HTTP-FLV via flv.js na URL `http://{IP}:8881/{CANAL}/{IMEI}.flv`.

---

## 13. Revisão Geral — 30/06/2026

> Auditoria completa de 37 arquivos PHP em 7 fases conforme `REVIEW_PLAN.md`.
> Classificação: **Bug** (comportamento incorreto) | **Risco de segurança** | **Dívida técnica** | **Documentação**
> Itens mapeados como pendências em §10 com código R01-R20.

### 13.1 Fase 0 — Preparação (Ambiente)

| # | Tipo | Severidade | Descrição |
|---|---|---|---|
| F0.1 | Ambiente | — | **PHP CLI não disponível** na máquina de dev. `php -l` não roda localmente. Lint depende do servidor via deploy. |
| F0.2 | Doc | — | **`.env` local desatualizado**: `SYSTEM_VERSION=3.0.0` (correto: 3.2.0). Faltam `IOTHUB_COMMAND_URL` e `IOTHUB_API_TOKEN`. |
| F0.3 | Bug | ALTO | **Rotas mortas**: `router.php` mapeia `/clientes/novo` → `clientes_novo.php` e `/clientes/{id}` → `cliente_dashboard.php`. Nenhum dos dois arquivos existe → 404 "Handler não encontrado". |

### 13.2 Fase 1 — Pipeline de Webhooks (12 push*.php + base)

| # | Tipo | Severidade | Arquivo:Linha | Descrição |
|---|---|---|---|---|
| F1.1 | Bug | CRÍTICO | `WebhookHandler.php:147-149` | **`request_logs` sem índice**: `isDuplicateRequest()` faz SELECT por `payload_hash` sem índice → full table scan em toda requisição webhook. ~25K linhas e crescendo. |
| F1.2 | Risco | ALTO | `pushgps.php:53-59` | **Coordenadas (0,0) descartadas**: `is_valid_coordinate()` retorna false para (0,0) que é sinal válido de "sem fix GPS". Descarta o ponto e não atualiza `device_statistics`. Dashboard/live mostram posição stale. |
| F1.3 | Risco | ALTO | `pushcmd.php:1-35` | **Handler legado ativo**: Superseded por `pushinstructresponse.php` mas ainda registrado no router. Insere lixo ("Orphan Response") em `commands`. Candidate a remoção. |
| F1.4 | Bug | ALTO | `pushTerminalTransInfo.php:30-48` | **Não extrai dados estruturados**: Só salva `extensionId` e raw JSON. `lat`, `lng`, `speed`, `content` não vão para colunas. |
| F1.5 | Dívida | MÉDIO | `functions.php:30` | **normalize_data() faltam aliases**: `lon` → `longitude`, `msgId` → `msg_id`, `accStatus` → `acc`. Cada handler faz fallback manual. |
| F1.6 | Dívida | MÉDIO | `pushalarm.php:40`, `pushresourcelist.php:25` | **Dupla normalização**: Chamam `normalize_data()` de novo (base class já chamou). Inócuo mas ineficiente. |
| F1.7 | Dívida | MÉDIO | `pushresourcelist.php:28-37` | **Bloco de código morto**: Tenta decodificar `data_list` dentro do item (base class já extraiu). |
| F1.8 | Dívida | MÉDIO | `WebhookHandler.php:48` | **`md5(json_encode(...))` sem `JSON_UNESCAPED_UNICODE`**: Hashes podem divergir entre versões PHP para mesmo payload Unicode. |
| F1.9 | Dívida | BAIXO | `functions.php:31-32` | **Alias `power` → `battery` semanticamente errado**: `power` é status power-on (0/1), não nível de bateria. |
| F1.10 | Dívida | BAIXO | `pushalarm.php:468-472` | **processUpdate() não armazena ICCID/VIN**: Só atualiza `last_communication`, descarta o valor real. |

### 13.3 Fase 2 — Roteamento, Autenticação e Multi-Tenancy

| # | Tipo | Severidade | Arquivo:Linha | Descrição |
|---|---|---|---|---|
| F2.1 | Risco | CRÍTICO | `camerasdata.php:34-41,91-94` | **Cross-tenant leak via token**: Quando acessado sem sessão (token-only, como faz o auto-refresh de dashboard/live), `$customerId = null` e a query retorna TODOS os dispositivos de TODOS os clientes. |
| F2.2 | Risco | CRÍTICO | 6 AJAX handlers | **Cross-tenant leak nos AJAX endpoints**: `commandstatus.php`, `sendcommand.php`, `mediadata.php`, `trackdata.php`, `hbdata.php` — nenhum filtra `customer_id` via token. Dados de qualquer IMEI expostos. `sendcommand.php` também permite enviar comandos. |
| F2.3 | Risco | ALTO | `login.php:9,24,29` | **Open redirect**: parâmetro `redirect` usado sem validação em `header('Location: ' . $redirect)`. Ex: `/login?redirect=https://evil.com`. |
| F2.4 | Risco | ALTO | `sendcommand.php:97-104` | **proNo desconhecido NÃO bloqueado**: Só loga warning, mas envia o comando. Deveria retornar HTTP 400. |
| F2.5 | Risco | MÉDIO | `auth.php:171,202` | **Cookie `Secure=false`**: Ambos `setcookie()` hardcoded com `$secure=false`. Se produção usa HTTPS, deveria ser true. |
| F2.6 | Risco | MÉDIO | `router.php:76-83` | **Rotas mortas** `/clientes/novo` e `/clientes/{id}` → arquivos inexistentes (já listado em F0.3). |
| F2.7 | Dívida | MÉDIO | `auth.php` | **Sessões expiradas sem rotina de limpeza**: `sessions` e `request_logs` não têm auto-purge (Logger tem 30 dias, mas `request_logs` não). |
| F2.8 | OK | — | `auth.php` (geral) | Auth bem implementada: bcrypt, token 64-char, prepared statements, sem `session_start()`. `$_SESSION` é array per-request populado do cookie — seguro e intencional. |
| F2.9 | OK | — | `customer_switch.php:13-17` | Valida que usuário tem acesso ao cliente alvo via `get_available_customers()`. |
| F2.10 | OK | — | `usuarios.php:23` | Proteção contra autodesativação: bloqueia toggle para `$id === (int)$currentUser['id']`. |
| F2.11 | OK | — | `setup.php:12-17` | Bloqueia re-acesso se `users` não está vazia (`SELECT COUNT(*) FROM users`). |

### 13.4 Fase 3 — Endpoints AJAX e Páginas de Dados

| # | Tipo | Severidade | Arquivo:Linha | Descrição |
|---|---|---|---|---|
| F3.1 | Risco | ALTO | `relatorios.php:44-100` | **SQL via string interpolation**: `$where` construído com `$_GET` params (`imei`, `from`, `to`, `severity`, `category`) + `$db->quote()`. Padrão frágil — converter para prepared statements. |
| F3.2 | Risco | MÉDIO | 8 arquivos dashboard | **`$customer_id` interpolado**: `dashboard.php`, `ativos.php`, `live.php`, `video.php`, `comandos.php`, `config.php`, `relatorios.php`, `ativos.php` usam `$db->query("...WHERE customer_id = $customer_id")`. Valor da sessão (seguro), mas padrão inconsistente com prepared statements do resto do código. |
| F3.3 | Risco | MÉDIO | `hbdata.php:49` | **`LIMIT $limit` interpolado**: Apesar de `$limit` ser validado como int 1-500, é string interpolation em SQL. |
| F3.4 | Dívida | MÉDIO | TODOS formulários POST | **CSRF ausente**: `ativos.php`, `ativos_novo.php`, `clientes.php`, `usuarios.php`, `perfil.php`, `comandos.php` — nenhum token anti-CSRF. |
| F3.5 | OK | — | `ativo_detalhe.php:37` | Valida `d.customer_id = ?` no JOIN. Demais queries por IMEI são seguras (IMEI já validado como pertencente ao cliente). |
| F3.6 | OK | — | `login.php`, `setup.php`, `perfil.php` | Password hashing com bcrypt, validação de senha atual antes de trocar. |
| F3.7 | OK | — | `sendcommand.php:80-118` | IMEI regex (15-17 dígitos), proNo whitelist, JSON canônico para JT/T, prepared statements no INSERT. |

### 13.5 Fase 4 — Frontend (Revisão Funcional)

Devido à ausência de PHP CLI local, a revisão funcional completa via browser não foi executada. A análise estática do HTML/JS inline revelou:

| # | Tipo | Arquivo | Descrição |
|---|---|---|---|
| F4.1 | OK | `layout_base.php` | Design system consistente com DESIGN.md. Sidebar com 9 links + dropdown de cliente. CSS tokens inline corretos. |
| F4.2 | OK | `dashboard.php` | Auto-refresh 30s funcional (JS inline). Mapa Leaflet com `upsertMarker`. |
| F4.3 | OK | `live.php` | Auto-refresh 30s via `/camerasdata?token=`. Mapa com circle markers. "Sem dados" fallback. |
| F4.4 | OK | `ativos.php` | Lista com edit inline, soft-delete (`is_active=0`). Formulários POST com action hidden. |
| F4.5 | OK | `ativos_novo.php` | Dropdown `device_models` com auto-preenchimento de `camera_count`. |
| F4.6 | OK | `ativo_detalhe.php` | 9 abas com `layout_ativo_sidebar.php`. JS inline para cada aba. |
| F4.7 | OK | `video.php` | Envia proNo 37121, player HTTP-FLV. limit 50 em media files. |
| F4.8 | OK | `comandos.php` | Detecta protocolo via `device_models.protocol`. Presets JIMI/JTT corretos. Polling 3s/10s/5min. |
| F4.9 | OK | `clientes.php` | CRUD com `require_admin()`. Bloqueia exclusão de customer ID 1. |
| F4.10 | OK | `usuarios.php`, `perfil.php` | CRUD de usuários admin-only. Perfil acessível a qualquer role. Senha bcrypt. |
| F4.11 | OK | `login_template.php`, `setup.php` | Templates isolados com CSS próprio mas paleta consistente com DESIGN.md. |
| F4.12 | Dívida | `live.php`, `dashboard.php` | Lógica Leaflet duplicada (`upsertMarker`/`loadMarkers`) — duas implementações ligeiramente diferentes do mesmo conceito. |

### 13.6 Fase 5 — Design System e Qualidade de UI

| # | Tipo | Descrição |
|---|---|---|
| F5.1 | OK | Paleta Cursor-inspired aplicada consistentemente em `layout_base.php`, `login_template.php` e `setup.php`. Todos usam os mesmos tokens CSS (`--primary: #f54e00`, `--canvas: #f7f7f4`, etc.). |
| F5.2 | OK | Tipografia: Inter 400/500/600 + JetBrains Mono aplicada em todas as superfícies. |
| F5.3 | OK | Hairlines (sem sombras): bordas 1px `--hairline` em cards, tabelas, inputs. |
| F5.4 | Pendente | Responsividade: sidebar fixa 240px sem colapso mobile. Já listado como prioridade baixa. |
| F5.5 | Pendente | Acessibilidade: labels presentes, mas sem teste de navegação por teclado/leitores de tela. |

### 13.7 Fase 6 — Documentação

| # | Tipo | Arquivo | Descrição |
|---|---|---|---|
| F6.1 | Doc | `README.md:1` | **Versão desatualizada**: título diz "v3.0.0". Deveria ser v3.2.0. Seção Quick Start não menciona migration v3.1.0 nem `/setup`. |
| F6.2 | Doc | `API_COVERAGE.md:1` | **Versão desatualizada**: título diz "v3.0.0". Não menciona multi-tenancy, auth, nem os 12 endpoints "Not Yet Implemented" priorizados. |
| F6.3 | Doc | `CHANGELOG.md` | **Sem entradas para v3.1.0 e v3.2.0**. Última entrada é v3.0.0 (2026-06-10). Multi-tenant, auth, `/usuarios`, `/perfil` nunca foram registrados. |
| F6.4 | Doc | `PRD.md:1,11` | **v2.0.0** — menciona "token único compartilhado" como non-goal, mas auth multi-usuário já existe. Non-goal de "relatórios exportáveis" ainda é verdade. |
| F6.5 | Doc | `AGENTS.md` | **Rotas faltando**: Não lista `/usuarios` nem `/perfil` na tabela de rotas. Seção do Dashboard não inclui os novos links do sidebar. |
| F6.6 | Doc | `STATUS.md` (este arquivo) | **Atualizado** com todos os achados da revisão, consolidado com pendências existentes. |

### 13.8 Fase 7 — Segurança (Passagem Dedicada)

| # | Tipo | OWASP | Descrição |
|---|---|---|---|
| F7.1 | Risco | A01: Broken Access Control | **Cross-tenant data leak** (F2.1, F2.2): 6 endpoints AJAX expõem dados de todos os clientes via token compartilhado. |
| F7.2 | Risco | A01: Broken Access Control | **Open redirect** no login (F2.3): phishing vector. |
| F7.3 | Risco | A01: Broken Access Control | **proNo whitelist não bloqueante** (F2.4): comandos arbitrários possíveis se token vazar. |
| F7.4 | Risco | A03: Injection | **SQL injection via string interpolation** (F3.1): `relatorios.php` com `$_GET` params. |
| F7.5 | Risco | A01: CSRF | **CSRF ausente** em todos os formulários POST (F3.4): ataque possível via cookie da vítima. |
| F7.6 | Risco | A04: Insecure Design | **`WEBHOOK_TOKEN` compartilhado**: Usado tanto para autenticar IoT Hub quanto como fallback do dashboard AJAX. Superfície de exposição ampliada. Avaliar separar token de webhook de token de dashboard. |
| F7.7 | Risco | A05: Security Misconfiguration | **Cookie `Secure=false`** (F2.5): se produção usa HTTPS, cookies trafegam em plain text. |
| F7.8 | Risco | A07: Identification Failures | **Sem rate limiting** no login (pendência existente). Força bruta possível. |
| F7.9 | OK | A02: Cryptographic Failures | Senhas com bcrypt (PASSWORD_BCRYPT). Tokens com `random_bytes(32)` → hex 64-char. |
| F7.10 | OK | A03: Injection | Todos os webhook handlers usam prepared statements. Sem injeção nos 12 push*.php. |
| F7.11 | OK | A08: Software Integrity | Dependências externas via CDN com SRI (Leaflet CSS/JS). flv.js sem SRI — considerar adicionar. |

### 13.9 Resumo de Severidade

| Severidade | Qtd | Itens |
|---|---|---|
| **CRÍTICO** (cross-tenant leak) | 2 | R01 (camerasdata), R02 (6 AJAX endpoints) |
| **ALTO** (bug/risco com impacto) | 6 | R03 (proNo), R04 (SQL), R05 (redirect), R06 (GPS 0,0), R07 (índice), R08 (rotas mortas) |
| **MÉDIO** (dívida/consistência) | 10 | CSRF, string interp, normalize_data, pushTerminal, pushcmd, dupla normalização, código morto, md5/unicode, cookie Secure, rotina limpeza |
| **BAIXO** (melhorias) | 10+ | Export CSV, rate limit, responsivo, tema escuro, CI/lint, endpoints Jimi não implementados |
| **OK** (verificado correto) | 15+ | Auth, bcrypt, anti-replay, protocol isolation, prepared statements nos webhooks, validação IMEI, autodesativação, setup lock |

---

## 14. Próximos Passos Recomendados

### Ordem de execução sugerida (por impacto):

1. **Imediato** (antes do próximo deploy):
   - Corrigir cross-tenant leak: `camerasdata.php` (R01) + 6 AJAX endpoints (R02) — adicionar filtro `customer_id` via token + IMEI JOIN em `devices`
   - Bloquear proNo desconhecidos em `sendcommand.php` (R03)
   - Corrigir open redirect em `login.php` (R05)

2. **Próximo deploy**:
   - Criar índice `idx_payload_hash_created` em `request_logs` (R07)
   - Refatorar `relatorios.php` para prepared statements (R04)
   - Remover rotas mortas do `router.php` (R08) e `pushcmd.php` (R09)
   - Atualizar `.env` no servidor (R10)

3. **Segunda rodada**:
   - Adicionar CSRF tokens (R11)
   - Migrar string interpolation para prepared statements nos 8 dashboard pages (R12)
   - Extrair dados estruturados em `pushTerminalTransInfo.php` (R13)
   - Atualizar documentação (README, CHANGELOG, API_COVERAGE, PRD, AGENTS) (F6.1-F6.5)

4. **Backlog**:
   - Rate limiting, responsividade, export CSV, CI/lint, endpoints Jimi não implementados
