# Plano de Revisão Geral — Jimi Webhook System

> Criado em: 30/06/2026 | Escopo: todo o código já desenvolvido (backend + frontend), até o commit atual (`787502e` + mudanças locais de auth/multi-tenant/usuários da sessão v3.2.0).
> Objetivo: auditar tudo que foi construído até aqui — não é uma revisão de uma feature isolada, é uma varredura completa de correção, segurança, consistência e dívida técnica antes de seguir para novas funcionalidades.

---

## 0. Por que este formato

O projeto **não tem suíte de testes automatizada nem CI** (`CLAUDE.md`). A única verificação mecânica disponível é `php -l`. Isso significa que esta revisão é necessariamente:
- **Manual e sistemática** — checklist por arquivo/rota, não "rodar os testes e ver o que quebra".
- **Dividida em fases pequenas** — cada fase produz uma lista de achados (bugs, riscos, dívida técnica), não um "aprovado/reprovado" único.
- **Rastreável em `STATUS.md`** — achados viram itens de pendência lá, seguindo a convenção já usada no projeto.

Ambiente local: **não há PHP CLI disponível** nesta máquina de desenvolvimento (verificado nesta sessão — `php -l` falhou). A Fase 0 trata disso.

---

## 1. Inventário do que existe hoje (linha de base)

Antes de revisar, mapear o que precisa ser coberto. Isso evita "esquecer" uma tela ou endpoint.

### Backend — Webhooks (12 handlers `push*.php`)
`pushevent`, `pushhb`, `pushgps`, `pushalarm`, `pushfileupload`, `pushlbs`, `pushresourcelist`, `pushftpfileupload`, `pushiothubevent`, `pushTerminalTransInfo`, `pushinstructresponse`, `pushcmd` (legado).
Todos herdam `WebhookHandler` (`config/WebhookHandler.php`).

### Backend — Páginas do dashboard + AJAX (25 handlers)
`dashboard`, `live`, `ativos`, `ativos_novo`, `ativo_detalhe`, `relatorios`, `video`, `comandos`, `config`, `clientes`, `usuarios`, `perfil`, `login`, `logout`, `setup`, `customer_switch`, `camerasdata`, `commandstatus`, `sendcommand`, `mediadata`, `trackdata`, `hbdata`, `devicemodels`, `ping`, `router`.

### Frontend — Templates (`web/`)
`layout_base.php`, `layout_base_close.php`, `layout_ativo_sidebar.php`, `login_template.php`, `index.php` (wrapper legado, ver §5.4).

### Infra
`config/database.php` (PDO singleton), `core/Logger.php`, `includes/auth.php`, `includes/functions.php`, migrations (`jimi_tracker.sql`, `migration_v2.0.0.sql`, `migration_v3.1.0.sql`), `scripts/deploy.sh|rollback.sh|update-homolog.sh`.

### Achados já conhecidos (não re-descobrir — só confirmar/corrigir na fase correspondente)
| # | Achado | Onde | Fase sugerida |
|---|---|---|---|
| 1 | `router.php` mapeia `/clientes/novo` → `clientes_novo.php` e `/clientes/{id}` → `cliente_dashboard.php`, **mas nenhum dos dois arquivos existe** em `handlers/`. Rota morta (404 "Handler não encontrado"). | `handlers/router.php` linhas 76-83 | Fase 2 |
| 2 | `README.md` e `docs/API_COVERAGE.md` estão datados **v3.0.0** e não mencionam multi-tenancy, autenticação, `/usuarios`, `/perfil` — documentação desalinhada com o código real. | raiz / `docs/` | Fase 6 |
| 3 | `CHANGELOG.md` não tem entradas para v3.1.0 nem v3.2.0 (multi-tenant, auth, gestão de usuários) apesar de já estar em produção. | `CHANGELOG.md` | Fase 6 |
| 4 | `dashboard.php` e `relatorios.php` interpolam `$customer_id` direto na string SQL (`WHERE customer_id = $customer_id`) em vez de bind parametrizado — hoje seguro porque `get_customer_id()` sempre faz `(int)` cast, mas é inconsistente com o resto do código (que usa prepared statements) e frágil a mudanças futuras. | `handlers/dashboard.php`, `handlers/relatorios.php` | Fase 3 |
| 5 | `web/index.php` (wrapper legado v2.0.0 que só faz `require handlers/dashboard.php`) não é mais alcançável via `router.php` (não há rota que aponte pra ele) — candidato a arquivar junto com o resto do legado. | `web/index.php` | Fase 2 |
| 6 | Sem PHP CLI no ambiente local de dev → `php -l` só roda no servidor via `deploy.sh` FASE 4. Lint de mudanças locais fica sem verificação mecânica até o deploy. | ambiente | Fase 0 |

---

## 2. Fase 0 — Preparar o terreno

- [ ] Confirmar se há PHP CLI disponível em algum ambiente acessível (WSL, container, ou só no servidor via SSH). Se não houver, decidir: instalar PHP local (XAMPP/Laragon) **ou** aceitar que lint só roda no deploy e reforçar revisão manual de sintaxe.
- [ ] Gerar a lista real de rotas (`grep` em `router.php`) × arquivos existentes em `handlers/` para achar **todas** as rotas mortas (não só o caso já encontrado em `/clientes/novo`).
- [ ] Conferir se `.env` local existe e aponta para um banco de homologação acessível (para testes manuais via browser) — sem isso, boa parte das Fases 3-5 não pode ser testada de ponta a ponta, só lida estaticamente.
- [ ] Rodar `mysql -u root -p < mysql/jimi_tracker.sql` + as duas migrations em um banco limpo de teste, para confirmar que a cadeia de migração ainda funcima do zero (idempotência de `migration_v3.1.0.sql` já é uma decisão documentada — validar que continua verdadeira).

---

## 3. Fase 1 — Backend: pipeline de webhooks (ingestão IoT)

Cobre os 12 `push*.php` + `config/WebhookHandler.php` + `includes/functions.php`.

- [ ] **Conformidade com a API oficial**: para cada handler, comparar campos lidos em `processItem()` contra a tabela em `docs/API_COVERAGE.md` e a doc oficial (`docs.jimicloud.com`). Marcar divergências.
- [ ] **Isolamento JIMI vs JT/T 808** (`ADR-001.md`): confirmar que `msgClass=0` e `msgClass=1` nunca se misturam em `pushalarm.php` e em qualquer handler que trate ambos os protocolos.
- [ ] **Idempotência**: revisar `isDuplicateRequest()` — janela de 10 min fixa, hash MD5 só de `data_list` (ignora `token`/`msgType`). Validar se isso pode causar falso-positivo entre handlers diferentes que recebam `data_list` idêntico por coincidência (baixo risco, mas documentar).
- [ ] **Tratamento de erro por item**: em `WebhookHandler::handle()`, erro em um item do `data_list` é logado e o loop continua (não aborta a transação) — confirmar que isso é o comportamento desejado para todos os 12 handlers (um item malformado não deveria travar os outros 49 do lote).
- [ ] **`normalize_data()`**: mapa de aliases camelCase→snake_case é curto (14 entradas). Conferir se todos os handlers dependem de campos fora desse mapa e se estão tratando o alias correto manualmente.
- [ ] **`pushcmd.php`** (legado, fora da doc oficial): entender por que ainda existe, se está em uso ativo ou é candidato a arquivar como os outros legados.
- [ ] **Métricas / `request_logs`**: confirmar que `logMetrics()` não cresce sem controle (tem rotina de limpeza? comparar com o auto-purge de 30 dias do `Logger`).
- [ ] **Endpoints não implementados** (`API_COVERAGE.md` §"Not Yet Implemented", 12 endpoints): não é bug, mas registrar como backlog consciente — confirmar com o responsável do produto se algum virou prioridade.

---

## 4. Fase 2 — Backend: roteamento, autenticação e multi-tenancy

- [ ] **Tabela de rotas completa**: gerar de fato a lista `router.php` × arquivos em `handlers/` (ver achado #1). Corrigir ou remover as entradas mortas.
- [ ] **`includes/auth.php`** linha a linha:
  - `auth_init()` — confirmar que popular `$_SESSION` sem `session_start()` não colide com nenhum código que espera sessão PHP nativa (ex: alguma lib de terceiros).
  - `require_admin()` — só checa `role !== 'admin'`; confirmar que não há endpoint admin-only que esqueceu de chamar essa função (ex: novo `handlers/usuarios.php`).
  - `set_customer_context()` — usa `$GLOBALS['_auth_token']` como workaround para "cookie não está disponível na mesma request do login". Validar que isso não quebra em cenários de múltiplas requisições concorrentes (race condition entre login e primeira troca de cliente).
- [ ] **Escopo por `customer_id`**: para *cada* handler que consulta `devices`, `alarms`, `commands`, `gps_data`, etc., confirmar que o `WHERE customer_id = ...` está presente. Isso é crítico — é o tipo de bug que já foi encontrado uma vez em `camerasdata.php` nesta sessão. Fazer essa checagem em: `ativos.php`, `ativo_detalhe.php`, `relatorios.php`, `mediadata.php`, `trackdata.php`, `hbdata.php`, `commandstatus.php`, `sendcommand.php`, `video.php`, `comandos.php`, `config.php`.
- [ ] **Sessões expiradas**: `sessions.expires_at` — confirmar que não há sessões zumbi acumulando (rotina de limpeza?) e que `refresh_session()` está sendo chamado em todo request autenticado (via `require_login()`).
- [ ] **`/setup`**: só funciona com tabela `users` vazia — confirmar que não há forma de re-acessar depois que já existe um admin (bypass).
- [ ] **Cookie `jimi_token`**: `HttpOnly=true`, mas `Secure` está hardcoded `false` em `setcookie()` (`includes/auth.php:171` e `:202`) — se o servidor rodar atrás de HTTPS (ver ADR/infra), isso deveria ser `true`. Confirmar protocolo real do servidor de produção.
- [ ] **`handlers/usuarios.php` e `handlers/perfil.php`** (novos, desta sessão): revisar como parte desta fase já que nunca passaram por revisão de par — CRUD de senha, troca de `customer_users`, proteção contra autodesativação.

---

## 5. Fase 3 — Backend: endpoints AJAX e páginas de dados

- [ ] **Injeção SQL**: grep por `->query(` com interpolação de variável (já achado em `dashboard.php`/`relatorios.php`, achado #4) — migrar para prepared statements por consistência, mesmo onde hoje é "seguro" por causa do cast `(int)`.
- [ ] **`camerasdata.php`** (reescrito nesta sessão): validar em produção real — autenticação dupla (sessão + token compartilhado), filtro por `customer_id`, e os novos campos (`lat`, `lng`, `acc`, `is_online`, `last`) batendo com o que `live.php` e `dashboard.php` esperam.
- [ ] **`sendcommand.php`**: aceita JSON e form-urlencoded, alias `content`/`cmdContent`, whitelist de `proNo` 128-34818. Confirmar que a whitelist está correta e que não há `proNo` que deveria estar bloqueado (comandos destrutivos?).
- [ ] **`commandstatus.php`**: polling com `?command_id=X` — confirmar timeout ("Comando em fila offline") e que não há vazamento de comandos de outro `customer_id` no polling.
- [ ] **`config.php`**: leitura/escrita de parâmetros via `proNo` 33027-34818 — mesma checagem de escopo por cliente.
- [ ] **Paginação/limites**: `trackdata.php`, `hbdata.php`, `mediadata.php` — confirmar que há `LIMIT` nas queries (dispositivo com meses de histórico de GPS não deveria travar a página).
- [ ] **Validação de entrada**: todos os endpoints AJAX que recebem `$_GET`/`$_POST` (IMEI, datas, IDs) — confirmar `htmlspecialchars()`/cast/whitelist antes de usar em query ou output.

---

## 6. Fase 4 — Frontend: revisão funcional tela por tela

Usar a tabela do `STATUS.md` §7 como checklist base, mas testando de fato no browser (ver skill `/verify` e `/run` já disponíveis). Login como **admin** e como **usuário não-admin** para cada tela relevante.

| Tela | O que testar |
|---|---|
| Login / Setup | Fluxo completo de primeiro acesso; tentativa de reacessar `/setup` com admin já criado |
| Painel (`/dashboard`) | KPIs corretos por cliente; auto-refresh de 30s (novo) atualizando tabela + mapa sem reload; troca de cliente no dropdown reflete na tabela |
| Ao Vivo (`/live`) | Auto-refresh (corrigido nesta sessão) realmente atualizando marcadores; comportamento com 0 dispositivos com GPS |
| Ativos (`/ativos`, `/ativos/novo`, `/ativos/{imei}`) | CRUD completo; 9 abas do detalhe; isolamento entre clientes (usuário do cliente A não deve listar/acessar IMEI do cliente B via URL direta) |
| Relatórios | Filtros de data/severidade/categoria; exportação (ainda não existe — confirmar que não é esperado nesta fase) |
| Vídeo | Envio de `proNo` 37121 antes do play; player HTTP-FLV; arquivos gravados |
| Comandos | Presets JIMI vs JTT corretos por modelo; polling 3s/10s/5min; timeout |
| Configuração | Query/set de parâmetros sem 403 |
| Clientes (`/clientes`, admin only) | CRUD; bloqueio para não-admin (403) |
| **Usuários (`/usuarios`, novo)** | CRUD; vínculo a cliente; ativar/desativar; bloqueio de autodesativação; bloqueio para não-admin |
| **Perfil (`/perfil`, novo)** | Troca de senha com validação de senha atual; acesso por qualquer role |
| Logout | Sessão realmente destruída no banco (`sessions` DELETE) e cookie limpo |

---

## 7. Fase 5 — Frontend: design system e qualidade de UI

- [ ] **Consistência de tokens**: `web/layout_base.php` define o design system inline (cores, radii, tipografia). Conferir se `login_template.php` e `setup.php` (que têm CSS próprio, fora do layout) não divergiram da paleta oficial (`DESIGN.md`).
- [ ] **Responsividade**: sidebar fixa de 240px sem colapso mobile (já listado como pendência de prioridade baixa no `STATUS.md`) — decidir se entra nesta rodada de revisão ou fica para depois.
- [ ] **Erros de console**: abrir cada tela com DevTools aberto, checar por erros JS (ex: Leaflet, flv.js, fetch falhando silenciosamente com `.catch(function(){})` mascarando problemas reais).
- [ ] **Acessibilidade básica**: labels de formulário, contraste de cor dos badges (`--warning`, `--error` sobre fundo cream), navegação por teclado nos modais.
- [ ] **Duplicação de JS inline**: `live.php` e `dashboard.php` agora têm lógica de marcador Leaflet quase idêntica (`upsertMarker`/`loadMarkers`) implementada duas vezes de formas ligeiramente diferentes. Avaliar extrair para um `web/assets/js/map-utils.js` compartilhado — troca "3 linhas parecidas" por abstração só se a duplicação crescer mais.

---

## 8. Fase 6 — Documentação e alinhamento

- [ ] Atualizar `README.md` e `docs/API_COVERAGE.md` de v3.0.0 → versão atual, incluindo multi-tenancy, auth, `/usuarios`, `/perfil`.
- [ ] Adicionar entradas de `CHANGELOG.md` para v3.1.0 e v3.2.0 (retroativo, a partir do histórico de commits/STATUS.md).
- [ ] Confirmar que `AGENTS.md` (tabela de rotas para agentes) está sincronizado com `router.php` real.
- [ ] Revisar `docs/PRD.md` — checar se o backlog nele ainda reflete prioridades atuais ou se ficou desatualizado frente ao `STATUS.md`.

---

## 9. Fase 7 — Segurança (passagem dedicada)

Depois das Fases 1-6 (que já capturam a maioria dos problemas específicos), uma passagem final focada só em segurança:
- [ ] Checklist OWASP Top 10 aplicado a: SQL injection (Fase 3), XSS (output sem `htmlspecialchars`), CSRF (formulários POST sem token — `clientes.php`, `usuarios.php`, `perfil.php` não têm CSRF token hoje), autenticação quebrada (sessão, cookie flags), controle de acesso (multi-tenant bypass via IMEI/ID direto na URL).
- [ ] Rate limiting em `/login` (força bruta) — pendência já conhecida no `STATUS.md`.
- [ ] Revisar `WEBHOOK_TOKEN` — usado tanto para autenticar o IoT Hub quanto (antes desta sessão) hardcoded no HTML de `live.php`/`dashboard.php` como fallback de token do dashboard. Avaliar se vale a pena separar "token de webhook" de "token de dashboard AJAX" para reduzir superfície de exposição do segredo.
- [ ] Usar o skill `/security-review` deste harness sobre o diff acumulado da sessão de multi-tenant/auth antes de considerar essa parte "fechada".

---

## 10. Entregável e critério de conclusão

Cada fase termina com uma lista de achados classificados como:
- **Bug** (comportamento incorreto observável) → vira item em `STATUS.md` §10 com prioridade.
- **Risco de segurança** → tratado com prioridade máxima, não espera o rodízio normal de prioridades.
- **Dívida técnica** (funciona, mas devia ser diferente) → registrado, mas não bloqueia.
- **Documentação desalinhada** → corrigida na própria Fase 6.

A revisão é considerada concluída quando:
1. Todas as fases 0-7 têm checklist marcado (ou explicitamente adiado com justificativa).
2. `STATUS.md` reflete os achados novos.
3. Nenhum achado classificado como "Risco de segurança" ficou em aberto sem decisão do responsável do projeto.

---

## 11. Ordem sugerida de execução

Fases 0 → 1 → 2 → 3 podem ser feitas só lendo código (não dependem de ambiente rodando). Fases 4 e 5 exigem app rodando + browser. Fases 6 e 7 fecham o ciclo. Se o tempo for limitado, priorizar **Fase 2 (auth/multi-tenant) e Fase 7 (segurança)** primeiro — são as que têm maior impacto se algo estiver errado (vazamento de dados entre clientes).
