# Plano de Implementação — v4.10 (Frota: manutenção, mapa, replay, widgets)

> Derivado da análise da plataforma Whitelabel Tracking
> (`docs/ANALISE_WHITELABEL.md`). Este documento registra o plano; a
> implementação será feita em versões a definir no momento do código.

## Escopo

| # | Feature | Origem (WLT) | Migração | Menu | Status |
|---|---|---|---|---|---|
| 5 | Ícone do veículo por tipo (Tabler Icons), colorido por estado, no mapa | asset icon por estado | sim (`vehicle_type`) | tela existente (`/rastreamento`) | ✅ **implementado — v4.10.0** |
| 3 | Manutenção preventiva (odômetro/ignição/horímetro/data) + documentos do motorista | Maintenance + reminders | sim | **item novo e dedicado** na sidebar | ✅ **implementado — v4.10.1** |
| 6 | Replay de viagem com timeline scrub (tela nova) | Trips replay | não | link dentro do grupo Relatórios existente | ✅ **implementado — v4.10.2** |
| 7 | Dashboard widgetizado por usuário | Dashboard widgets | sim | **item novo, em paralelo ao Resumo atual** (não substitui) | ✅ **implementado — v4.10.3** |

## Decisões travadas

- **Versão**: aberta (definida na implementação; bump de `SYSTEM_VERSION`,
  `.env.example` e `deploy.sh`).
- **Horímetro**: campo do webhook é **`horimetro`** → ingestão grava em
  `devices.engine_hours`.
- **Dashboard**: **NÃO substitui `handlers/resumo.php`.** O painel widgetizado
  nasce como tela nova e paralela — `/painel` — com item próprio na sidebar.
  `resumo.php` (rota `/`, tela "Resumo") continua exatamente como está, KPIs
  fixos, sem tocar em uma linha. Layout do painel novo é **por usuário, edição
  livre** (cada usuário edita o próprio; fallback = padrão global → catálogo
  hardcoded de widgets).
- **Manutenção**: item de menu **dedicado**, não sub-aba de tela existente —
  ver "Item 3 / Menu" abaixo.
- **Replay**: tela nova em `/relatorios/deslocamento/replay`.
- **Ordem de entrega**: **5 → 3 → 6 → 7**.

---

## Item 5 — Ícone do veículo por tipo, colorido por estado no mapa

`handlers/rastreamento.php` hoje usa `L.circleMarker` com só 2 cores
(online `#05b169` / offline `#a8acb3`) e nenhuma noção de tipo de veículo —
`devices` não tem essa coluna.

**Fonte dos ícones — decidido**: [Tabler Icons](https://tabler.io/icons)
(MIT, sem atribuição), pelas mesmas razões levantadas na pesquisa de
21/08/2026: cobre os tipos que interessam (`car`, `truck`, `bus`,
`motorbike`, `tractor`, `caravan`), tem variante **filled**
(`fill="currentColor"`, path único — legível em marcador pequeno) para os 5
mais comuns, e o estilo **outline** (`stroke="currentColor"`) já é o mesmo
idioma dos ícones da sidebar em `nav_icon()` (`web/layout_base.php:145`). Sem
CDN nova: só os `<path>` necessários, embutidos como string PHP — mesmo
padrão de `nav_icon()`.

**Mecânica de recolorização**: o SVG do veículo fica sempre **branco**; quem
muda por estado é o **fundo do pin** (círculo colorido atrás do ícone, cor de
`FLEET_STATE_COLORS`). Isso desacopla "este tipo tem variante filled?" de
"como ele muda de cor" — funciona igual para os 5 tipos com filled e para
`trator`, que só existe em outline (usa `stroke="#fff"` em vez de
`fill="#fff"`, mesmo mecanismo).

### 5A. Campo novo — tipo de veículo no cadastro de ativos

**Migração** (`mysql/migration_v4.10.0.sql`, padrão idempotente atual —
`information_schema` + `PREPARE`/`EXECUTE`, igual `migration_v4.9.32.sql`):

```sql
SET @c := (SELECT COUNT(*) FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'devices'
              AND column_name = 'vehicle_type');
SET @sql := IF(@c = 0,
    "ALTER TABLE `devices` ADD COLUMN `vehicle_type`
       ENUM('carro','van','caminhao','onibus','moto','trator') NULL
       COMMENT 'Tipo de veiculo p/ icone do mapa (Tabler Icons) — NULL = nao informado, pin vira so um ponto colorido'
       AFTER `device_name`",
    'SELECT ''vehicle_type ja existe'' AS status');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;
```

`NULL` é o estado de todo device existente (sem backfill: não há como inferir
o tipo do parque já cadastrado) e o comportamento nesse caso é o **atual**
— pin sem ícone, só o círculo colorido — para não regredir visualmente quem
não cadastrar o tipo.

**Catálogo de ícones** (`includes/vehicle_icons.php`, novo arquivo): array
`VEHICLE_ICONS[type] = ['label', 'stroke' (bool), 'paths' (string dos
`<path>` do Tabler)]`, função `vehicle_icon_svg($type, $color, $size)` (uso
server-side no picker do formulário) e `vehicle_icon_js_catalog()` (mesmo
array pronto para `json_encode()`, consumido pelo JS do mapa — o estado muda
a cada refresh de 30s, então o ícone tem de poder ser remontado no client
sem round-trip ao servidor).

| `vehicle_type` | Label | Ícone Tabler | Variante |
|---|---|---|---|
| `carro` | Carro | `car` | filled |
| `van` | Van / Utilitário | `caravan` | filled (aproximação — Tabler não tem uma "van" de entrega dedicada; `caravan` é o formato boxy mais próximo disponível) |
| `caminhao` | Caminhão | `truck` | filled |
| `onibus` | Ônibus | `bus` | filled |
| `moto` | Moto | `motorbike` | filled |
| `trator` | Trator | `tractor` | outline (sem variante filled no Tabler) |
| `NULL` | Não informado | — | ponto colorido, sem ícone (comportamento atual) |

**Cadastro** (`handlers/ativos_novo.php`): seletor visual — grade de botões
(um por tipo + "Não informado"), cada um renderizando
`vehicle_icon_svg($type, 'var(--muted)', 24)` + label; clique seta um
`<input type="hidden" name="vehicle_type">` e marca o botão ativo (`<select>`
nativo não comporta ícone por `<option>`). `INSERT`/`UPDATE devices` ganha a
coluna.

**Grade e edição em lote** (`handlers/ativos.php`): nova coluna "Veículo" na
tabela (ícone pequeno + label, via `vehicle_icon_svg`); a linha de edição
inline ganha um `<select>` simples (sem ícone — a linha já é apertada) com as
mesmas 6 opções + "Não informado"; `UPDATE devices ... vehicle_type=?` junto
das colunas já editadas ali.

### 5B. Estado no mapa (`handlers/rastreamento.php`)

1. Adicionar `d.speed_limit_kmh`, `d.vehicle_type` e `c.default_speed_limit_kmh`
   (JOIN `customers`) às queries de devices (`:24-34`) e positions (`:40-51`);
   trocar o `CASE WHEN TIMESTAMPDIFF(...) <= 5` ad-hoc por
   `LEFT JOIN device_statistics ds ON ds.imei=d.imei` +
   `LEFT JOIN device_state_segments s ON s.imei=d.imei AND s.ended_at IS NULL`
   — o mesmo padrão de `fleet_status_sql()` (`handlers/rel_status_frota.php:83-105`).
2. Computar estado **no PHP** reaproveitando `includes/fleet_state.php` (já
   existe, não precisa ser criado — construído para `rel_status_frota.php` e
   os workers de segmentação):
   - `resolve_current_state($segState, $lastGpsTime)` → `movimento|ocioso|parado|offline`;
   - `resolve_speed_limit($deviceLimit, $customerLimit)` → limite vigente;
   - **novo**, só aqui: se `state !== 'offline'` e `acc=1` e
     `speed > limite`, sobrepõe para `excesso`.
3. Emitir `state` e `vehicle_type` no JSON (`?ajax=1` e `mapData` inline).
4. Cores: `FLEET_STATE_COLORS` de `includes/fleet_state.php` (movimento
   `#0052ff`, ocioso `#a97a00`, parado `#5b616e`, offline `#cf202f`) +
   **novo**, só no mapa, `excesso = #f4b000`.
5. Marcador: `L.divIcon` (não mais `L.circleMarker`) com um pin HTML —
   círculo `FLEET_STATE_COLORS[state]` + SVG branco do `vehicle_type` (ou
   vazio, se `NULL`) centrado dentro, borda branca 2px, sombra leve.
6. Legenda no mapa (swatches + `FLEET_STATE_LABELS` + "Excesso de velocidade").
7. **Decisão**: unificar offline no limiar de 30 min (`OFFLINE_GAP_SECONDS`),
   em vez do 5 min atual, para não contradizer `/relatorios/status-frota`.

**Arquivos**: `mysql/migration_v4.10.0.sql`, `includes/vehicle_icons.php`
(novo), `handlers/ativos_novo.php`, `handlers/ativos.php`,
`handlers/rastreamento.php`.
**Teste**: `tests/rastreamento_estado.spec.js` (cor por estado e ícone por
tipo no navegador) — **ainda não escrito**; a entrega desta rodada foi
verificada manualmente (23/08/2026): migração aplicada duas vezes no MySQL
local (idempotente, `ENUM` correto), as três queries de
`rastreamento.php`/`ativos.php` rodadas contra os 41 devices locais, POST de
edição em `/ativos` gravando `vehicle_type='van'` e confirmado no banco, valor
fora do catálogo (`DROP TABLE devices`) gravando `NULL` em vez do texto cru
(whitelist de `VEHICLE_ICONS`, não veio do `ENUM` do MySQL), e as três telas
(`/rastreamento`, `/ativos`, `/ativos/novo`) carregando sem `Warning`/`Fatal
error` no HTML retornado. **Não verificado**: renderização visual real do pin
e do seletor no navegador (extensão do Chrome não estava conectada nesta
sessão) — recomenda-se um passe visual antes do deploy em produção.

---

## Item 3 — Manutenção preventiva + documentos do motorista

### 3A. Manutenção por métrica

**Migração** (`mysql/migration_vX.sql` — nome na implementação):

```sql
CREATE TABLE `maintenance_reminders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `imei` varchar(30) DEFAULT NULL,          -- NULL = lembrete por motorista/data
  `driver_id` bigint unsigned DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `metric` enum('odometro','horas_ignicao','horimetro','data') NOT NULL,
  `interval_km` decimal(10,1) DEFAULT NULL,
  `interval_hours` decimal(10,1) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `last_done_km` decimal(12,2) DEFAULT NULL,
  `last_done_hours` decimal(10,1) DEFAULT NULL,
  `last_done_at` datetime DEFAULT NULL,
  `notify_bell` tinyint(1) NOT NULL DEFAULT 1,
  `notify_email` tinyint(1) NOT NULL DEFAULT 0,
  `emails` json DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mr_customer` (`customer_id`),
  KEY `idx_mr_imei` (`imei`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CALL add_column_if_not_exists('devices','engine_hours',
  "decimal(10,1) DEFAULT NULL COMMENT 'Horímetro reportado pelo equipamento' AFTER `speed_limit_kmh`");
```

**Resolução de métrica** (`includes/maintenance.php`):

| metric | fonte |
|---|---|
| `odometro` | `latest_odometer()` → último `gps_data.mileage > 0` |
| `horas_ignicao` | `SUM(duration_s)/3600` de `device_state_segments WHERE imei AND state IN ('movimento','ocioso') AND started_at >= last_done_at` |
| `horimetro` | `devices.engine_hours` |
| `data` | data de hoje (lembrete único / por data) |

**Ingestão do horímetro**: `handlers/pushgps.php` (e/ou `pushhb.php`) lê o campo
**`horimetro`** do payload e grava em `devices.engine_hours` (só quando > 0,
para não sobrescrever com vazio).

### 3B. Documentos do motorista (CNH / toxicológico)

- Colunas novas em `drivers`: `remind_cnh tinyint(1) DEFAULT 0`,
  `remind_tox tinyint(1) DEFAULT 0` — **a opção de disparar ou não** fica no
  cadastro do item.
- Worker varre `drivers` com `cnh_expires_at`/`tox_exam_expires_at` próximos
  (≤30/15/7 dias) e flag ligada → `notify()` `kind='lembrete'`
  (`includes/notification_engine.php`).

### Worker + tela

- `scripts/maintenance_worker.php` (cron diário): calcula `compute_due`, notifica
  dentro da janela (odômetro ≤200 km do vencimento; horas ≤N h; data ≤7 dias).
  Registrar em `scripts/crontab-setup.sh` (`CRON_JOBS`) + `--install` no servidor
  (o `deploy.sh` NÃO instala cron).
- Tela `/manutencoes` (`handlers/manutencoes.php`): abas **Manutenção** e
  **Documentos**; CRUD + "Registrar concluído" (grava `last_done_*`). Registrar
  em `$simpleRoutes` + `$screenByHandler['manutencoes.php']='manutencoes'` +
  `grupos_permissao.php::$screens['manutencoes']`.

### Menu — item dedicado (não sub-aba de tela existente)

Padrão dos itens recentes que "mandam instrução"/"tocam operação" é
`$navBottom` (`web/layout_base.php`), mas Manutenção é consulta/CRUD do
usuário comum (mecânico, gestor de frota) — não comando em equipamento — então
entra em `$navPrincipal`, ao lado de Resumo/Rastreamento/BI, e não em
`$navBottom` nem dentro do grupo "Relatórios" ou "Cadastros":

```php
// web/layout_base.php — $navPrincipal
['route' => 'manutencoes', 'label' => 'Manutenção', 'icon' => 'wrench', 'href' => '/manutencoes'],
```

Badge de contagem (itens vencidos/próximos do vencimento) é natural aqui —
mesmo padrão do contador de frota no header — mas fica como melhoria
opcional, não bloqueante para a v4.10. Alerta de "sino" (`notify_bell`) já
cobre a notificação; o badge no menu é só visibilidade extra.

Como sempre: a permissão efetiva é `require_permission('manutencoes','view')`
dentro do handler (via `$screenByHandler`); o item de nav sumir para quem não
tem grupo é conveniência, não a trava.

### Correção bônus

`handlers/ativo_detalhe.php:34,47` referencia `s.total_distance` (coluna
inexistente) → trocar por `s.total_distance_km` e exibir o odômetro atual
(`latest_odometer`) na aba Visão Geral.

### Implementado — v4.10.1 (24/08/2026) — decisões tomadas no código

- **`MAINTENANCE_DUE_HOURS = 10`**: o plano deixou o limiar de horas em
  aberto ("≤N h"); escolhido por ser a ordem de grandeza de um turno e meio,
  dando folga para agendar oficina antes do vencimento efetivo.
- **Dedupe diário que o plano não previu**: `notify()`
  (`includes/notification_engine.php`) só dedupe o **e-mail**, numa janela
  curta — o SINO é gravado a cada chamada, sem controle nenhum. Um worker
  diário sem dedupe próprio recriaria a notificação todo dia em que o item
  seguisse vencido. Adicionadas `maintenance_reminders.last_notified_at`,
  `drivers.cnh_notified_at`/`tox_notified_at` (todas `DATE`) — "já notifiquei
  HOJE?", não "há quanto tempo". Resultado: lembra uma vez por dia enquanto
  vencido, nunca duas na mesma rodada.
- 🔴 **Bug real pego na verificação manual, antes de qualquer deploy**: a
  primeira versão do cálculo de vencimento (odômetro/horímetro) derivava o
  baseline do valor **atual** quando `last_done_km`/`last_done_hours` estava
  `NULL` — isso faz o vencimento "perseguir" o odômetro (sempre
  `atual + intervalo`) e o item **nunca entra em vencido**. Corrigido em
  `includes/maintenance.php`: o baseline só vem da coluna gravada, nunca do
  valor corrente; `handlers/manutencoes.php` grava o baseline **uma vez**, na
  criação do lembrete (assume "serviço feito agora" — não havia campo no
  formulário para digitar um KM/hora de referência diferente), e só
  "Registrar concluído" o reescreve depois. Confirmado com um ciclo completo
  no MySQL local: criar → "Em dia" (due = atual+intervalo) → nova posição de
  GPS empurra o odômetro além do due → "Vencido" → "Registrar concluído"
  → volta a "Em dia" com novo baseline. Sem essa correção, TODO lembrete de
  odômetro/horímetro criado sem um "Registrar concluído" manual anterior
  jamais teria disparado.
- **`horimetro` continua não confirmado contra device real** — `pushgps.php`/
  `pushhb.php` tentam 4 nomes de campo prováveis; nenhum verificado em
  payload real ainda. Enquanto isso, `metric='horimetro'` fica "sem dado" em
  qualquer frota sem `devices.engine_hours` preenchido manualmente.
- **"Registrar concluído" em lembrete `metric='data'`** desativa o item
  (`is_active=0`) em vez de recalcular uma próxima data — não há intervalo
  para uma data avulsa, e o plano não cobriu esse caso.
- Verificado end-to-end no MySQL local (24/08/2026): migração aplicada duas
  vezes (idempotente), CRUD completo de lembrete via POST real (criar →
  editar → registrar concluído → remover), toggle de documento do motorista
  via POST, worker rodado duas vezes seguidas (2 notificações na 1ª, 0 na
  2ª — dedupe confirmado), e as três telas tocadas
  (`/manutencoes` nas duas abas, `/ativos/{imei}`, `/grupos-permissao`) sem
  `Warning`/`Fatal error`. **Não verificado**: renderização visual real no
  navegador (extensão do Chrome não conectou nesta sessão, mesma limitação do
  item 5).

---

## Item 6 — Replay de viagem (tela nova, sem migração)

1. Rota: `$subrouteMap['relatorios']['deslocamento/replay'] =>
   'rel_deslocamento_replay.php'` (padrão de `deslocamento/rota`).
   `$screenByHandler` → `'relatorios'` (sem tela nova na matriz). Link "Replay"
   em `rel_deslocamento.php` (`:367`/`:381`) ao lado de "Ver rota".
2. `handlers/rel_deslocamento_replay.php`: carrega a viagem (`trips` por
   `trip_id`), busca pontos (`gps_data BETWEEN started_at AND ended_at`,
   downsample ~3000 como `rel_deslocamento_rota.php:109-125`), injeta `points`
   (epoch, lat, lng, speed, acc, mileage) + `janela = [strtotime(started_at),
   strtotime(ended_at)]`.
3. UI: Leaflet com polilinha + **marcador móvel** (interpolação via
   `requestAnimationFrame`, idiom `m.setLatLng()` de `rastreamento.php:186-205`);
   play/pause, velocidade (0.5×/1×/2×/4×), readout hora/velocidade/distância;
   **timeline SVG** copiando a interação de `video_playback.php` (zoom wheel,
   drag-to-pan, pointer-capture, `alvoDown`/`moveu`; linhas `579-654`,
   `670-694`, `1258-1336`) com **playhead** vertical em `x(now)`.
4. Overlay opcional de alarmes/ocorrências (reuso de
   `rel_deslocamento_rota.php:127-175`).

**Teste**: `tests/replay.spec.js` (replay de trip semeado: playhead avança,
marcador se move, scrub por clique) — **ainda não escrito**.

### Implementado — v4.10.2 (24/08/2026) — decisões tomadas no código

- **Só modalidade por viagem** (`trip_id`), não fechamento diário — o
  fechamento agrega várias viagens com buracos de ignição desligada entre
  elas, e um replay contínuo faria o marcador "voar" no buraco. O link
  "Replay" só aparece na linha da grade em modo "Por deslocamento".
- **Marcador reaproveita `includes/vehicle_icons.php` (item 5)**: mesmo ícone
  Tabler do `vehicle_type` do ativo, branco sobre um pin azul — não precisou
  de nenhum SVG novo.
- **Velocidade não é interpolada entre pontos** (só a posição lat/lng é) — o
  readout mostra a velocidade do último ponto de GPS conhecido, não uma média
  suavizada; é mais honesto sobre a granularidade real do dado (pontos a
  cada ~30s–5min, não um sensor contínuo).
- **Sparkline de velocidade na própria timeline**, no lugar dos "blocos de
  vídeo por canal" que `video_playback.php` desenha — não existe conceito de
  canal/arquivo aqui, mas mostrar onde a viagem acelerou/parou dá o mesmo
  tipo de contexto visual que os blocos davam lá.
- **Verificação sem browser** (mesma limitação dos itens 3 e 5 nesta sessão):
  além do HTTP sem erro contra uma viagem real do MySQL local, a lógica JS
  foi extraída do HTML e rodada em Node com `document`/`L` (Leaflet)
  simulados — interpolação, round-trip pixel↔tempo, clamp de zoom e
  conversão de fuso do playhead todos conferidos por valor, não só por "não
  quebrou". Não substitui um passe visual real antes do deploy.

---

## Item 7 — Dashboard widgetizado por usuário (tela nova, em paralelo ao Resumo)

**Decisão do dono do produto**: manter o Resumo atual **intocado** e entrar
com o painel widgetizado como **segunda tela**, com item próprio no menu —
não uma reescrita de `handlers/resumo.php`. Motivo prático: o Resumo atual é
o KPI fixo que todo mundo já conhece; o painel novo é opt-in, e ninguém perde
a tela que usa hoje enquanto o widgetizado amadurece.

**Migração** (`mysql/migration_vX.sql`):

```sql
CREATE TABLE `dashboard_layouts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,     -- NULL = padrão global
  `layout` json NOT NULL,                     -- [{"key":"kpi_devices","w":1,"config":{}}, ...]
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `user_key` bigint GENERATED ALWAYS AS (COALESCE(`user_id`,0)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dl_user` (`user_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

1. **Catálogo** (`includes/dashboard_widgets.php`): 13 widgets reaproveitando os
   blocos de `resumo.php` + `metrics_snapshots` (leitura só — nenhuma função de
   `resumo.php` é alterada, só chamada/reaproveitada):
   `kpi_devices`, `kpi_connectivity`, `kpi_occurrences`, `kpi_outdated`,
   `heatmap`, `speed_dist`, `idle`, `model_status`, `ts_alarms`,
   `ts_occurrences`, `top_plates`, `top_drivers`, `reseller_view` (só
   revendedor). `render_widget($key, $db, $cid)` + `dashboard_widget_catalog()`.
2. **`handlers/painel.php`** (tela nova, NÃO `resumo.php`): renderiza na ordem
   do layout do usuário (fallback padrão global → catálogo com um conjunto
   inicial razoável de widgets). Modo "Editar painel" (mostrar/ocultar +
   reordenar ↑/↓, sem biblioteca nova).
3. **`handlers/dashboarddata.php`** (AJAX, `$ajaxRoutes`): `GET` devolve o
   layout; `POST` valida chaves contra o catálogo e grava (padrão de
   `camerasdata.php`: `require_ajax_session()` + escopo do próprio usuário +
   `csrf_verify()` no POST).
4. **Rota + menu**: `/painel` → `$simpleRoutes` + `$screenByHandler['painel.php']
   = 'painel'` + `grupos_permissao.php::$screens['painel']`. Item novo em
   `$navPrincipal` (`web/layout_base.php`), logo após "Resumo" — os dois ficam
   lado a lado, o usuário escolhe qual abre por hábito:

   ```php
   // web/layout_base.php — $navPrincipal
   ['route' => 'resumo', 'label' => 'Resumo', 'icon' => 'grid', 'href' => '/'],
   ['route' => 'painel', 'label' => 'Painel', 'icon' => 'layout-grid', 'href' => '/painel'],
   ```

   (Nome de rota/label "Painel" evita colidir com "Dashboard", que já é usado
   como legado de `resumo` — ver `$current_route === 'dashboard'` no fim de
   `layout_base.php` — e com `ocorrencias_dashboard`, a tela de Ocorrências.)

**Testes**: helper PHP (validação de layout/whitelist) +
`tests/dashboard_widgets.spec.js` (ocultar → persiste após F5; reordenar → ordem
muda; **e** um teste de regressão simples confirmando que `/` continua
servindo `resumo.php` sem alteração de conteúdo/layout) — **ainda não
escrito**.

### Implementado — v4.10.3 (24/08/2026) — decisões tomadas no código

- **Largura do widget é fixa por tipo** (`sm`/`md`/`lg` no catálogo), não
  escolhida pelo usuário — o plano previa `{"key":...,"w":1,...}` no JSON do
  layout, mas como o modo de edição é só "mostrar/ocultar + reordenar" (sem
  biblioteca de grid nova), um seletor de largura seria uma segunda decisão
  de UI que o plano não pediu. O `layout` gravado é só a lista ordenada de
  chaves visíveis; a largura vem do catálogo.
- **`resumo.php` não foi tocado nem lido em runtime** — `includes/dashboard_widgets.php`
  reimplementa as mesmas consultas (KPIs, mapa de calor, distribuição de
  velocidade, séries temporais, top 3) como funções `dashboard_render_*()`
  independentes, memoizadas por request quando dois widgets partilham a
  mesma fonte (ex.: `kpi_devices` e `kpi_connectivity` vêm dos mesmos 4
  números). "Reaproveitar" o bloco significou reaproveitar a QUERY, não
  chamar o arquivo — `resumo.php` mistura dado e HTML na página inteira, sem
  nenhuma função extraída para importar sem efeito colateral.
- **Padrão global é um único registro do SISTEMA** (`user_id IS NULL`, sem
  `customer_id` na chave), exatamente como o plano especificou — não por
  cliente. Não construí uma tela para EDITAR esse registro global (o plano
  não pediu); hoje só existe por INSERT manual. Documentado como lacuna
  conhecida, não decisão de produto.
- **Verificação encontrou e descartou uma falsa vulnerabilidade de CSRF**:
  num teste com duas requisições em sequência rápida no mesmo processo
  PowerShell, um `POST /dashboarddata` sem token pareceu ser aceito (200,
  gravou no banco). Isolado em três reproduções controladas — inclusive
  como duas chamadas de ferramenta genuinamente separadas — a rejeição 403
  foi consistente nas três; o servidor de produção (PHP-FPM atrás de
  Apache) não tem o comportamento single-thread de keep-alive do `php -S`
  que causou a aparência do bypass. Registrado aqui porque a investigação
  valeu a pena documentar, não porque havia bug de verdade.
- Ciclo completo verificado no MySQL local (24/08/2026): catálogo padrão (9
  widgets) sem erro, edição salvando 3 widgets e `/painel` refletindo
  exatamente esses três, fallback ao padrão global testado com um segundo
  usuário (2 widgets exatos, nem um a mais), sanitização contra chave
  inválida/duplicada, gate de `reseller_view` nos dois sentidos, e `/`
  conferida sem nenhuma marca do painel novo. **Não verificado**:
  renderização visual real no navegador — a extensão do Chrome não
  conectou nesta sessão apesar de o usuário reportá-la ativa (mesma
  limitação dos itens 3, 5 e 6).

---

## Cross-cutting / entrega

1. **Migrations** registradas explicitamente em `scripts/deploy.sh`
   (`run_migration`).
2. **Cron**: `maintenance_worker.php` em `CRON_JOBS` de `scripts/crontab-setup.sh`
   + `bash scripts/crontab-setup.sh --install` no servidor (falha é silenciosa).
3. **Permissões**: as duas telas novas (`manutencoes.php`, `painel.php`) em
   `$screenByHandler` **e** `grupos_permissao.php::$screens` (lição da
   v4.8.5/v4.8.9/v4.9.11); `dashboarddata.php` é AJAX (entra em `$ajaxRoutes`,
   protegido por `require_ajax_session()` + escopo do próprio usuário, igual
   `camerasdata.php` — **não** entra em `$screenByHandler`, que é só para
   telas). Lembrar que `can()` é permissivo para usuário sem grupo. Os dois
   itens de menu novos (`manutencoes`, `painel`) entram em `$navPrincipal` —
   não em `$navBottom` (reservado para telas que mandam instrução ao
   equipamento) — e passam pelo mesmo filtro `$navCanView` das demais.
4. **Verificação**: `php -l` em todos os arquivos; `scripts/run-tests.ps1`
   (helpers + Playwright); specs novos por feature.

## Sequência

**5 → 3 → 6 → 7 →** testes + deploy.

## Pendências de implementação (a resolver no momento do código)

- Nome/versão exatos das migrações.
- Confirmar o campo `horimetro` num payload real (fallback genérico
  `horimetro` já planejado).
- Retenção de `device_state_segments` para `horas_ignicao` desde `last_done_at`
  (se os segmentos forem podados, o acumulado desde a última manutenção pode
  recomeçar — documentar a limitação).
