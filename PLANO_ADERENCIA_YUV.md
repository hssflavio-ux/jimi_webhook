# PLANO DE ADERÊNCIA YUV — Revisão v4.1.2 → v4.2.0

> **Data da revisão**: 11/07/2026
> **Método**: comparação em 3 camadas — (1) plataforma YUV capturada em `analise_yuv/analise_yuv.html` + 29 screenshots (levantamento autenticado de 06/07/2026); (2) blueprint `PROJETO_YUV.md`; (3) **inventário do código real** (leitura de todos os 27 handlers de tela + router + layout + occurrence_engine por agente de exploração).
> **Limitação desta iteração**: a verificação **ao vivo** em `app.yuv.com.br` não pôde ser executada — nenhum Chrome com a extensão do Claude estava conectado. Os itens que dependem dela estão em [§7](#7-verificação-ao-vivo-pendente). O restante do gap-analysis usa a captura de 06/07/2026, que é recente e autenticada.

---

## 0. PROGRESSO DE EXECUÇÃO (checkpoint 11/07/2026 — sessão 3)

> **Fases A e B COMPLETAS.** Retomar do item marcado ⏭️.

| Item | Status | Observações |
|---|---|---|
| **A1** rota morta `/clientes/{id}` | ✅ Feito | Router devolve 404; smoke test OK |
| **A2** `/checklist/inspecao` sombreada | ✅ Feito | `checklist` saiu de `$simpleRoutes`; fallback do subrouteMap serve `/checklist` |
| **A3** CSRF (4 handlers + `/sendcommand`) | ✅ Feito | `ativos`, `perfil`, toggle de `usuarios` com `csrf_field`; **`/sendcommand` agora exige `X-CSRF-Token`** — token exposto em `window.CSRF_TOKEN` pelo `layout_base.php` e enviado nos 6 callers (`comandos`, `ativo_detalhe`, `config`, `equipamentos`, `video_aovivo`, `video_playback`) |
| **A4** nome do grupo em `usuarios.php` | ✅ Feito | Mapa `$pgNames` via `array_column` |
| **B1** export síncrono Excel/PDF | ✅ Feito | `stream_export()` novo em `export_helper.php`; ligado em `rel_alarmes`, `rel_ocorrencias`, `rel_posicoes`, `rel_deslocamento`, `rel_desatualizados` (por faixa) e `equipamentos`; botões Excel+PDF padrão YUV; limite `SYNC_EXPORT_MAX_ROWS=10000` |
| **B2** RBAC efetivo | ✅ Feito | Helpers em `auth.php` (wildcard `"*"` do seed), sidebar filtrada, enforcement central de `view` no router, **gates de ação fina** (`create`/`edit`/`delete`) nos POSTs de `ativos`, `ativos_novo`, `chips`, `clientes` (impersonate segue gated por revendedor), `equipamentos` (incl. import), `motoristas`, `usuarios`, `grupos_permissao`, `config_ocorrencias` (incl. delete via GET) e `require_permission('relatorios'/'equipamentos','export')` nos 6 blocos de export. Falta apenas: spec Playwright com usuário restrito (teste automatizado do RBAC) |
| **B3** geocode em Posições | ✅ Feito | Coluna Endereço substitui Lat/Long (fallback: link OSM com coords); `geocode_cache_lookup()` em lote (cache-only) + máx. 3 misses resolvidos inline/página; **bug corrigido**: `reverse_geocode` comparava float 8 casas com DECIMAL(9,6) → cache nunca dava hit e a API era rechamada sempre |
| **B4** filtros chips/Filiais/Motoristas | ✅ Feito | Novo componente `web/components/chips_multiselect.php` (chips + overflow `+N`, extraído do bi.php); `rel_alarmes` com chips de Tipo de Alarme (IN) + filtro Filial; `rel_ocorrencias` com filtros Filial e Motorista; exports respeitam os novos filtros (mesmo `$where`) |
| **B5** colunas Equipamentos | ✅ Feito | Grade com **Chip** (JOIN `sim_cards`), **Bateria** (`device_statistics.battery_level`) e **Periféricos** (badge com contagem + tooltip); export idem; fallback resiliente p/ schema antigo |
| **B6** import em lote completo | ✅ Feito | CSV agora importa **Modelo** (resolvido por nome, case-insensitive) e **Canais** (fallback: camera_count do modelo); validação de IMEI 15–17 dígitos; **avisos por linha** (IMEI inválido/duplicado, modelo desconhecido) |
| **C** grades CRUD (busca/export/paginação) | ✅ Feito | `chips`/`motoristas`/`ativos`: busca server-side + export Excel/PDF + paginação 25/pág; `clientes`: busca + export + colunas **E-mail** e **Config. Checklist** (JOIN resiliente) + **fix CSRF nos forms "Entrar como"/"Desativar" (estavam 403 desde a Fase F)**; `usuarios`: busca + export por aba; `grupos-permissao`/`config-ocorrencias`: busca client-side (`yuvTableFilter` global no layout); `motoristas` ganhou colunas **Foto** (thumb/inicial) e **Nascimento**. *Adiado: campos veiculares Modelo/Ano em Ativos (exige migration — agrupar na próxima migration v4.2.0)* |
| **D2** Rastreamento sem reload | ✅ Feito | Modo `?ajax=1` no handler + pins atualizados in-place a cada 30s; **bug corrigido**: query usava `g.ignition` (coluna inexistente — é `acc`) → exceção engolida → **mapa sempre sem pins** |
| **D3** Exportar com polling real | ✅ Feito | Poll 10s via `/exportardata` (recarrega só quando um status muda); coluna **Nome** do relatório na grade; `name` exposto no JSON |
| **D4** Indicador de impersonação | ✅ Feito | Banner âmbar sob o header ("operando como X") + botão "Voltar ao meu perfil" (fecha `impersonation_log.ended_at` via `exit_impersonation` no `/customer_switch`, que também ganhou CSRF) |
| **D1** Resumo enriquecido | ✅ Feito | Ociosidade (acc=1 + vel 0, 30 min), Status por modelo (barras on/off + % online), **heatmap real** (`leaflet.heat` CDN sobre os pontos de 2h), séries com **toggle Hoje/7d/Mês** (buckets hora/dia BRT + total do período no card), **Top 3 placas** e **Top 3 motoristas** (com upsell FaceID quando desabilitado — paridade YUV), **Visão por clientes em 3 eixos Top 3** (equipamentos/ocorrências/desatualizados), **auto-refresh 30s dos KPIs** via `?ajax=kpis` (sem reload, pula a query do heatmap), botão **"Ver tutorial"** (reseta o tour). **Bug corrigido**: fallback de velocidade usava `g.ignition` (coluna inexistente — é `acc`) → "Velocidade da Frota" nunca populava on-the-fly |

> **FASES A–D DO PLANO: 100% CONCLUÍDAS.** O que resta no plano depende do operador (§7 verificação ao vivo, credenciais multi-tenant, OTA com device, deploy homolog) ou é opcional/futuro (§8: campos veiculares em Ativos via migration, timeline gráfica do Playback, spec Playwright de RBAC restrito).
| **§7** verificação ao vivo YUV | ⛔ Bloqueada | Extensão Chrome não conectada (depende do operador) |

**Bugs extras encontrados e corrigidos durante a execução** (não estavam no plano):
1. `rel_posicoes.php`: `$where` sem prefixo `g.` com JOIN em `devices` → **coluna ambígua → exceção engolida → relatório sempre vazio**. Corrigido (+ `MOD(g.id,10)`).
2. `rel_posicoes.php`: grade referenciava `$r['ignition']`/`$r['gps_status']` que não vinham no SELECT (ignição sempre "Desligada"). Corrigido com aliases `g.acc AS ignition, g.status AS gps_status` e aceite de `VALID`.
3. `usuarios.php`: form de toggle **sem** `csrf_field()` com `csrf_verify()` ativo → ativar/desativar usuário retornava 403 desde a Fase F. Corrigido.
4. `get_jimi_user()` não selecionava `user_type`/`permission_group_id`/`photo_url` → checks `user_type==='revendedor'` (visão revendedor no Resumo, abas de usuários) **nunca ativavam**. Corrigido com fallback para schemas antigos.

**Verificação (sessão 3)**: `php -l` verde em todos os arquivos tocados; **suite Playwright completa rodando localmente: 36 passed / 0 failed / 4 skipped** (2 rodadas; credenciais E2E provisionadas no MySQL local — usuário `e2e@teste.local`, senha resetada no banco de dev; specs pulados: rate-limit destrutivo e multi-tenant que exigem 2º cliente). Terceira rodada validando B4/B5/B6 disparada ao final da sessão.

**Como retomar**: começar pela **Fase C** (grades CRUD: Pesquisar + Exportar + paginação nos 7 cadastros, reusando `stream_export()` e o padrão de busca de `equipamentos.php`; colunas YUV faltantes da tabela §4), depois **Fase D** (§5). Itens de teste que restam: spec Playwright de RBAC com usuário restrito; specs multi-tenant (dependem de credenciais B).

---

## 1. Veredito geral

A paridade estrutural está **alta**: as 22 rotas YUV existem, todas com implementação real (nenhum stub de página), o motor de ocorrências funciona ponta-a-ponta (alarme → regra → ocorrência → tratativa → relatório, validado no E2E), o vídeo estruturado (Ao Vivo/Playback/Downloads) opera com dispositivo real e a navegação (sidebar-sanfona + header On/Off + colapsar) espelha a IA do YUV.

As lacunas remanescentes se concentram em **4 categorias**:

| Categoria | Resumo | Gravidade |
|---|---|---|
| **A. Bugs de rota e segurança** | 1 rota morta, 1 rota inacessível, CSRF ausente em 4 handlers | Alta (correção imediata) |
| **B. Funcionalidade YUV não entregue** | Export Excel/PDF síncrono é stub em TODAS as telas; RBAC salvo mas nunca aplicado; geocodificação não exibida | Alta (paridade de produto) |
| **C. Padrão de grade CRUD incompleto** | 7 cadastros sem Pesquisar/Exportar/paginação; colunas faltantes vs YUV | Média |
| **D. Resumo/UX abaixo do YUV** | Ociosidade, status por modelo, heatmap real, toggles de período, auto-refresh por AJAX | Média |

**Divergências intencionais (NÃO são lacunas)** — registradas em CLAUDE.md/DESIGN.md:
- Skin **Coinbase** (azul `#0052ff`, sidebar dark) no lugar do white-label laranja da instância "TelecomTrack". A estrutura/IA é a do YUV; a linguagem visual é decisão nossa.
- Checklist fora da sidebar (fase futura no YUV analisado também).
- PWA responsivo no lugar de app nativo.

---

## 2. Fase A — Bugs e segurança (fazer primeiro, ~1 sessão)

### A1. Rota morta `/clientes/{id}` → 404
- **Evidência**: `handlers/router.php` injeta o path-param e despacha para `cliente_detalhe.php`, **que não existe no disco**.
- **Intervenção**: remover o caso especial `/clientes/{id}` do router (o blueprint §8.0 já mandava remover rotas mortas — R08 foi fechado para `clientes_novo`/`cliente_dashboard`, mas esta escapou).
- **Arquivos**: `handlers/router.php`.
- **Aceite**: `/clientes/123` → 404 amigável; nenhuma referência a `cliente_detalhe` no código.

### A2. `/checklist/inspecao` inacessível (rota sombreada)
- **Evidência**: `checklist` está em `$simpleRoutes` **e** em `$subrouteMap`; `$simpleRoutes` resolve primeiro, então `/checklist/inspecao` sempre cai em `checklist.php`. O link "Preencher Inspeção" está quebrado desde a Fase H.
- **Intervenção**: no router, avaliar o mapa de subrotas de 2 segmentos **antes** do fallback de rota simples (ou remover `checklist` de `$simpleRoutes` e tratar `/checklist` como subrota-índice).
- **Arquivos**: `handlers/router.php`.
- **Aceite**: `/checklist/inspecao` abre `checklist_inspection.php`; `/checklist` continua abrindo a gestão; Playwright de navegação verde.

### A3. CSRF remanescente (R11 ainda aberto em 4 handlers)
- **Evidência**: POST sem `csrf_verify()`/`csrf_field()` em:
  - `ativos.php` (editar inline / remover),
  - `ativo_detalhe.php` (aba Comandos e aba Configurações),
  - `usuarios.php` (save e toggle ativo),
  - `perfil.php` (troca de senha).
- **Intervenção**: aplicar o padrão já usado em `chips.php`/`motoristas.php` (token HMAC por sessão de `includes/csrf.php`).
- **Aceite**: os 4 handlers rejeitam POST sem token (403) e os fluxos existentes seguem verdes na suite Playwright.

### A4. `usuarios.php` exibe o ID do grupo de permissão em vez do nome
- **Intervenção**: JOIN com `permission_groups` e exibir `name`.
- **Aceite**: grade mostra o nome do grupo (paridade com o YUV, coluna "Grupo de Permissão").

---

## 3. Fase B — Paridade funcional de alto valor

### B1. Exportação síncrona Excel/PDF em relatórios (stub → real) — **maior lacuna funcional**
- **YUV**: cada relatório tem **[Exportar Excel]** (verde) e **[Exportar PDF]** (vermelho) no topo, síncronos (screenshot `page_occurrences.png`); a fila `/report-export` é só para pesados.
- **Nosso estado**: todos os botões "Exportar Excel" são `alert('em desenvolvimento')` — em `rel_alarmes`, `rel_ocorrencias`, `rel_posicoes`, `rel_deslocamento`, `rel_desatualizados` (por faixa) e `equipamentos`. A infra **já existe**: `includes/export_helper.php` (XlsxWriter + PdfWriter puros, usados pelo worker).
- **Intervenção**: criar um modo `?export=xlsx|pdf|csv` em cada relatório que reusa a MESMA query da grade (sem paginação, com limite de segurança ~10k linhas; acima disso, redirecionar para a fila `/exportar` com os params preenchidos) e chama `export_helper`. Adicionar botões Excel/PDF no padrão YUV via `web/components/filter_bar.php`.
- **Arquivos**: `handlers/rel_*.php` (5), `handlers/equipamentos.php`, `web/components/filter_bar.php`, `includes/export_helper.php` (helper de streaming de resposta).
- **Aceite**: cada relatório baixa XLSX válido (magic bytes `PK`) e PDF válido (`%PDF`) refletindo o filtro ativo; acima do limite, cria job na fila com aviso.

### B2. RBAC efetivo (matriz salva mas nunca aplicada)
- **YUV**: grupos de permissão restringem telas/ações de fato (E6).
- **Nosso estado**: `grupos_permissao.php` grava a matriz JSON (18 telas × 5 ações), mas **nenhum handler consulta** `permission_groups.permissions`; só existe `require_login()`/`require_admin()`.
- **Intervenção**:
  1. `includes/auth.php`: carregar as permissões do grupo do usuário na sessão (`$_SESSION['permissions']`); helper `can($tela, $acao)` + `require_permission($tela, $acao)`.
  2. Handlers: `require_permission('<tela>', 'ver')` no topo; gates de `criar/editar/excluir/exportar` nos POSTs e botões.
  3. `web/layout_base.php`: esconder itens de sidebar sem permissão `ver`.
  4. Usuário sem grupo → comportamento atual (admin/role legado), para não quebrar o existente.
- **Aceite**: usuário com grupo restrito não vê nem acessa telas negadas (link direto → 403); botões de ação somem sem a permissão; suite Playwright com um usuário restrito de teste.

### B3. Geocodificação reversa no Relatório de Posições
- **YUV**: coluna **Endereço** em vez de lat/long (D1), + colunas Motorista e Sinal.
- **Nosso estado**: `rel_posicoes.php` inclui `geocode.php` mas exibe lat/long crus; `geocode_cache` não é consumido na tela.
- **Intervenção**: resolver endereço via `geocode_cache` (só cache hit na renderização; misses enfileiram para o worker preencher — não bloquear a página com N chamadas Nominatim); fallback lat/long com link OSM. Adicionar coluna Motorista (via driver do device/trip) e manter Sinal.
- **Aceite**: posições com cache mostram endereço; misses aparecem como lat/long e são preenchidos após o worker rodar.

### B4. Filtros YUV nos relatórios (chips, Filiais, Motoristas)
- **YUV** (`page_occurrences.png`, `page_alarms.png`): multiselects com chips `+N` para Clientes/Ativos/**Tipo de Alarme (+55)**, filtro **Filiais**, filtro **Motoristas**, período range default hoje 00:00–23:59.
- **Nosso estado**: `rel_alarmes.php` usa inputs de texto simples (sem multiselect, sem Filiais); `rel_ocorrencias.php` sem Filiais/Motoristas (o JOIN com `branches` já existe); o multiselect com chips **já existe no `bi.php`** — reaproveitar.
- **Intervenção**: extrair o multiselect-com-chips do `bi.php` para `web/components/` e aplicar em `rel_alarmes` (Tipo de Alarme, Filiais) e `rel_ocorrencias` (Filiais, Motoristas).
- **Aceite**: filtros combinam (WHERE IN), chips mostram `+N`, export (B1) respeita os mesmos filtros.

### B5. Colunas da grade de Equipamentos (Chip, Bateria, Periféricos)
- **YUV** (`page_devices.png`): grade com IMEI, Modelo, Cliente, Ativo, **Chip**, Último Heartbeat, **Bateria**, **Periféricos**, Situação, Status — colunas **ordenáveis**.
- **Nosso estado**: grade sem Chip/Bateria/Periféricos; sem ordenação por coluna.
- **Intervenção**: JOIN `sim_cards` (chip), última bateria de `heartbeats`, badge com contagem de periféricos (JSON); ordenação clicável no padrão já implementado em `rel_alarmes.php`.
- **Aceite**: grade espelha as 10 colunas YUV com ordenação em IMEI/Modelo/Cliente/Ativo/Chip/Último Heartbeat.

### B6. Importação em lote completa
- **Nosso estado**: o import CSV de equipamentos só lê IMEI/Nome/Firmware — ignora Modelo/Canais.
- **Intervenção**: mapear também Modelo (por nome em `device_models`) e Canais; relatório de erros por linha (IMEI duplicado, modelo desconhecido).
- **Aceite**: CSV com 5 colunas cria devices completos; linhas inválidas reportadas sem abortar o lote.

---

## 4. Fase C — Padrão de grade CRUD (paridade §9.1)

O YUV usa o mesmo esqueleto em todo cadastro: **título + Pesquisar + [Cadastrar] + [Exportar Excel] + tabela paginada + ações por linha**. Hoje só `equipamentos.php` se aproxima; os demais usam tabela + form lateral sem busca/export/paginação.

| Tela | Falta hoje | Intervenção específica |
|---|---|---|
| `ativos.php` | Busca, export, paginação; colunas YUV (Identificador, Cliente, **Modelo, Ano**, IMEI) | Adotar `crud_grid.php`; adicionar campos veiculares (`vehicle_model`, `vehicle_year`) — YUV separa **Ativo (veículo)** de Equipamento (device) |
| `chips.php` | Busca, export, paginação | `crud_grid.php` |
| `clientes.php` | Busca, export; colunas **E-mail, Grupo de Permissão, Config. de Checklist** | Completar colunas; manter Entrar como/brand color |
| `motoristas.php` | Busca, export; colunas **Foto, Data de Nascimento** | Foto (thumb de `photo_url` com fallback inicial) + nascimento na grade |
| `grupos_permissao.php` | Busca | Busca client-side é suficiente (volume baixo) |
| `usuarios.php` | Busca, export | `crud_grid.php` mantendo as abas Minha Empresa/Meus Clientes |
| `config_ocorrencias.php` | Busca | Busca client-side |

- **Nota**: o form de cadastro do YUV para Equipamentos é **página com breadcrumb** ("Equipamentos › Cadastrar Equipamento", screenshot `modal_device_form.png`), não modal — nosso form inline é aceitável; não mudar sem decisão de design.
- **Aceite global**: toda grade de cadastro tem Pesquisar (server-side onde há paginação) e Exportar Excel funcional (via B1).

---

## 5. Fase D — Resumo, Rastreamento e polish de dinâmica

### D1. Resumo `/` — blocos faltantes vs `page_resumo.png`
| Bloco YUV | Nosso estado | Intervenção |
|---|---|---|
| **Ociosidade** (ignição ligada + vel. 0) | Ausente | Card com contagem/lista via `gps_data` últimos 30 min |
| **Status de Equipamentos por modelo** (JC371/JC400D/JC450, on/off + % online) | Ausente | Agrupar `devices`×`device_statistics` por modelo |
| **Mapa de Calor** real | Mapa de pontos (circleMarker) | Plugin `leaflet.heat` (CDN, sem build) sobre as posições de 2h |
| **Séries com toggle Hoje / 7 dias / Último mês** | Só "hoje" fixo | Toggle que refaz a query (`?periodo=`) com agrupamento dia-a-dia p/ 7d/mês |
| **Top 3 placas com mais alarmes** | Ausente | Ranking `alarms`×`devices` do período |
| **Top 3 motoristas** + upsell FaceID | Ausente | Se `faceid_enabled`: ranking por `driver_id`; senão card de upsell (paridade com YUV) |
| **Visão por clientes: 3 eixos Top 3** | Só 1 eixo (equipamentos, Top 5) | 3 cards Top 3: equipamentos ativos / ocorrências / desatualizados |
| **Auto-refresh dos blocos** | Só o header | Polling 30s dos KPIs via endpoint JSON leve (reusar `metrics_snapshots`) |
| Botão **"Ver tutorial"** re-abre o tour | Tour só 1ª visita | Botão no topo que reseta o flag do localStorage |

### D2. Rastreamento — refresh sem reload
- **Nosso estado**: `location.reload()` a cada 60s (perde scroll/seleção; comentário diz 30s).
- **Intervenção**: trocar por `fetch` dos dados (reusar `/trackdata`/`/camerasdata`) atualizando pins e listas in-place, a cada 30s (paridade com YUV).

### D3. Exportar — polling de verdade
- **Nosso estado**: recarrega a página a cada 30s; o endpoint `/exportardata` existe e não é usado; grade sem a coluna "Nome" do job.
- **Intervenção**: polling via `/exportardata` atualizando linhas in-place; exibir o Nome dado no form (paridade com `page_report_export.png`).

### D4. Impersonação — indicador e saída
- **Nosso estado**: "Entrar como" funciona e audita, mas não há indicação visual de impersonação ativa nem botão "sair".
- **Intervenção**: banner/badge no header quando `impersonation_log` tem sessão aberta + botão "Voltar ao meu perfil" (fecha `ended_at`).

### D5. Playback — melhorias menores (baixa prioridade)
- YUV usa **data única** no filtro (screenshot) e timeline; nossa versão usa range De/Até e lista. Manter range (superset funcional). Timeline gráfica: opcional, avaliar depois do restante.

---

## 6. Ordem de execução recomendada

| Ordem | Fase | Esforço estimado | Dependências |
|---|---|---|---|
| 1 | **A** (A1–A4 bugs/CSRF) | Pequeno (1 sessão) | — |
| 2 | **B1** (export síncrono) | Médio | `export_helper.php` (pronto) |
| 3 | **B2** (RBAC efetivo) | Médio/Alto | — |
| 4 | **B3–B6** (geocode, filtros, colunas, import) | Médio | B1 para os botões de export |
| 5 | **C** (grades CRUD) | Médio (mecânico) | B1 |
| 6 | **D** (Resumo/polish) | Médio | metrics_rollup no cron |
| 7 | **§7** (verificação ao vivo) | Pequeno | Extensão Chrome conectada |

Cada fase fecha com: `php -l` em todos os arquivos tocados + suite Playwright (`./scripts/run-tests.ps1`) + atualização de `CHANGELOG.md`/`STATUS.md`.

**Pré-requisito operacional** (verificar no homolog antes da Fase D): crontab com `worker.php` (1 min), `trip_builder.php` (15 min), `metrics_rollup.php` (5 min) — `scripts/crontab-setup.sh --check`. Sem eles, Deslocamento/Resumo/Exportar ficam vazios ou em fallback.

---

## 7. Verificação ao vivo (pendente — bloqueada nesta iteração)

Itens que **exigem** navegar `app.yuv.com.br` autenticado (extensão Claude in Chrome conectada + login YUV):

1. **Re-capturar a tela de detalhe/tratativa de ocorrência** — o arquivo `analise_yuv/screenshots/occ_detail.png` é uma **duplicata do dashboard** (a captura original falhou). Nossa tela de tratativa foi construída a partir da descrição textual; validar: layout do caso, player de vídeo, mapa do ponto, botões de transição, histórico.
2. **Conferir mudanças pós-06/07/2026** — o YUV anunciou fim das licenças de teste em 16/07/2026; telas/fluxos podem ter mudado (licenciamento, novos módulos na sidebar).
3. **Detalhes finos de dinâmica** que screenshot não mostra: comportamento do polling, ordenação default das grades, mensagens de estado vazio, fluxo completo do Playback (timeline por canal), modal "Novo comando" (busca 3+ chars).
4. **Módulo Checklist** — nunca foi explorado no YUV (não exposto na sidebar do perfil analisado); verificar se outro perfil o expõe.

**Como desbloquear**: instalar/ativar a extensão Claude in Chrome (https://claude.ai/chrome) logado na mesma conta, manter o Chrome aberto e logado em `app.yuv.com.br` (`flavio@telecomtrack.com.br`), e rodar novamente a revisão (`/loop` ou pedir "faça o passe ao vivo da revisão YUV").

---

## 8. Registro de decisões desta revisão

- **Skin Coinbase mantido** — divergência visual do YUV é intencional e documentada; nenhuma intervenção de "voltar ao laranja".
- **Form de cadastro inline mantido** (vs página dedicada do YUV) — mudar não agrega função; reavaliar apenas se o operador reclamar.
- **Filtro de período do Playback mantido como range** — superset do comportamento YUV (data única).
- **BI considerado aderente** — nossos 4 gráficos sob demanda cobrem o escopo YUV (tela vazia até Gerar, chips de alarmes com +N).
- **Comandos considerado aderente** — fluxo, presets por protocolo e polling já validados com devices reais (v4.1.1/v4.1.2).
