# STATUS.md — Jimi Webhook System v3.1.0

> Última atualização: 11/06/2026 — Commit `8870610`
> Servidor: `http://189.22.240.43` (Apache 2.4 + PHP 8.3 + MySQL)

---

## 1. Resumo Geral

Reconstrução completa do dashboard no estilo NavTrack, mantendo PHP puro (sem frameworks JS). Multi-tenant com "Clientes", autenticação via token em cookie (sem dependência de `session_start()`), 14 rotas novas, 9 abas no detalhe do ativo, player de vídeo HTTP-FLV, envio de comandos com polling ativo, relatórios com filtros, e CRUD completo de clientes e ativos.

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

### Prioridade Alta
- [ ] **Live auto-refresh funcional**: O `live.php` tenta fazer polling em `/camerasdata` a cada 30s, mas o endpoint `camerasdata.php` não foi atualizado para retornar no formato que o JS espera (`data.devices` com campos `lat`, `lng`, `speed`, `acc`)
- [ ] **Verificar se comandos estão realmente chegando aos dispositivos**: O fluxo sendcommand → IoTHub → dispositivo → pushinstructresponse → commands.status precisa ser testado end-to-end
- [ ] **Arquivos de mídia**: Verificar se `/pushfileupload` está populando a tabela `media_files` corretamente para a tela de Vídeo/Gravações

### Prioridade Média
- [ ] **Mapa no dashboard**: Atualmente carrega uma vez só. Poderia ter auto-refresh como o live
- [ ] **Relatórios**: Adicionar exportação CSV
- [ ] **Detalhe do ativo**: Aba "Relatórios" dentro do ativo ainda é placeholder. Integrar com os relatórios cross-device
- [ ] **Gestão de usuários**: Criar tela `/usuarios` para admin gerenciar usuários (vincular a clientes, alterar roles)
- [ ] **Página de perfil**: Trocar senha, editar nome

### Prioridade Baixa
- [ ] **Logs de acesso**: Registrar tentativas de login (sucesso/falha) em tabela `access_logs`
- [ ] **Rate limiting** no login: Prevenir brute-force
- [ ] **Dashboard responsivo**: Sidebar colapsável em mobile
- [ ] **Tema escuro**: Variáveis CSS alternativas
- [ ] **`web/dashboard_template.php` (1152 linhas)**: Arquivo legado da v2.0.0 — pode ser arquivado
- [ ] **`web/assets/js/dashboard.js` (515 linhas)**: JS legado duplicado — pode ser removido
- [ ] **`includes/dashboarddata.php`**: Classe legada — pode ser removida

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
│   ├── functions.php             # normalize_data(), get_webhook_data(), etc.
│   └── dashboarddata.php         # LEGACY — v2.0.0 dashboard data class
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
│   ├── dashboard_template.php    # LEGACY — v2.0.0 dashboard
│   ├── index.php                 # LEGACY — v2.0.0 entry point wrapper
│   └── assets/
│       ├── css/dashboard.css     # LEGACY — v2.0.0 Bootstrap CSS
│       └── js/dashboard.js       # LEGACY — v2.0.0 JS
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
