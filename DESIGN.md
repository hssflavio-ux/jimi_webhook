---
version: 4.0.0
name: app-design-system
description: Design system do dashboard jimi_webhook, derivado do sistema Coinbase (ver DESIGN-coinbase.md). Voltagem única Coinbase Blue (#0052ff) para CTAs, links e foco; canvas branco; sidebar dark near-black (#0a0b0d) com item ativo azul; geometria pill (100px) em todo CTA; cards com hairline + um único nível de sombra no hover; tipografia Inter (display peso 400, corpo 400/600/700) com JetBrains Mono em todo número/IMEI/código. Aplica a estética institucional/editorial da Coinbase à estrutura de produto YUV (rastreamento + ocorrências DMS). Substitui a paleta Cursor (≤3.x) e a paleta roxa YUV proposta.

colors:
  primary: "#0052ff"
  primary-active: "#003ecc"
  primary-disabled: "#a8b8cc"
  primary-soft: "#eaf0ff"
  ink: "#0a0b0d"
  body: "#5b616e"
  body-strong: "#0a0b0d"
  muted: "#7c828a"
  muted-soft: "#a8acb3"
  canvas: "#ffffff"
  surface-soft: "#f7f7f7"
  surface-card: "#ffffff"
  surface-strong: "#eef0f3"
  surface-dark: "#0a0b0d"
  surface-dark-elevated: "#16181c"
  hairline: "#dee1e6"
  hairline-soft: "#eef0f3"
  on-primary: "#ffffff"
  on-dark: "#ffffff"
  on-dark-soft: "#a8acb3"
  success: "#05b169"       # semantic-up (só texto/borda/fundo suave)
  error: "#cf202f"         # semantic-down
  accent-yellow: "#f4b000" # ilustrativo/aviso

typography:
  display-lg:
    fontFamily: "'Inter', -apple-system, system-ui, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif"
    fontSize: 40px
    fontWeight: 400
    lineHeight: 1.05
    letterSpacing: -1px
  display-md:
    fontFamily: "'Inter', sans-serif"
    fontSize: 32px
    fontWeight: 400
    lineHeight: 1.13
    letterSpacing: -0.4px
  title-md:
    fontFamily: "'Inter', sans-serif"
    fontSize: 18px
    fontWeight: 600
    lineHeight: 1.33
  title-sm:
    fontFamily: "'Inter', sans-serif"
    fontSize: 16px
    fontWeight: 600
    lineHeight: 1.25
  body-md:
    fontFamily: "'Inter', sans-serif"
    fontSize: 15px
    fontWeight: 400
    lineHeight: 1.5
  body-strong:
    fontFamily: "'Inter', sans-serif"
    fontSize: 15px
    fontWeight: 700
    lineHeight: 1.5
  body-sm:
    fontFamily: "'Inter', sans-serif"
    fontSize: 13px
    fontWeight: 400
    lineHeight: 1.5
  label:
    fontFamily: "'Inter', sans-serif"
    fontSize: 11px
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: 0.5px
    textTransform: uppercase
  number-display:
    fontFamily: "'JetBrains Mono', ui-monospace, monospace"
    fontSize: 18px
    fontWeight: 500
    lineHeight: 1.4
  kpi-number:
    fontFamily: "'JetBrains Mono', monospace"
    fontSize: 28px
    fontWeight: 500
    lineHeight: 1.1
    letterSpacing: -0.5px
  button:
    fontFamily: "'Inter', sans-serif"
    fontSize: 14px
    fontWeight: 600
    lineHeight: 1.15
  nav-link:
    fontFamily: "'Inter', sans-serif"
    fontSize: 13px
    fontWeight: 500
    lineHeight: 1.4

rounded:
  xs: 4px
  sm: 8px
  md: 12px
  lg: 16px
  xl: 24px
  pill: 100px
  full: 9999px

spacing:
  xxs: 4px
  xs: 8px
  sm: 12px
  base: 16px
  md: 20px
  lg: 24px
  xl: 32px
  xxl: 48px

shadow:
  none: "none"
  soft: "0 4px 12px rgba(0,0,0,0.04)"   # ÚNICO nível — só em hover de card

components:
  sidebar-dark:
    backgroundColor: "{colors.surface-dark}"
    textColor: "{colors.on-dark-soft}"
    width: 244px
    note: "Sidebar dark near-black (leva a estética de hero escuro da Coinbase para a navegação do app)."
  sidebar-item-active:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.sm}"
  header-light:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.ink}"
    height: 64px
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.on-primary}"
    typography: "{typography.button}"
    rounded: "{rounded.pill}"
    padding: 10px 20px
    height: 40px
  button-secondary:
    backgroundColor: "{colors.surface-strong}"
    textColor: "{colors.ink}"
    typography: "{typography.button}"
    rounded: "{rounded.pill}"
  button-dark:
    backgroundColor: "{colors.ink}"
    textColor: "#ffffff"
    rounded: "{rounded.pill}"
  button-disabled:
    backgroundColor: "{colors.primary-disabled}"
    textColor: "{colors.on-primary}"
    rounded: "{rounded.pill}"
  card:
    backgroundColor: "{colors.surface-card}"
    rounded: "{rounded.lg}"
    padding: 20px
    border: "1px solid {colors.hairline}"
    shadowHover: "{shadow.soft}"
  feature-card:
    backgroundColor: "{colors.surface-card}"
    rounded: "{rounded.xl}"
    padding: 32px
    border: "1px solid {colors.hairline}"
    note: "Cartão maior (empty-state, hero interno) — raio 24px como no Coinbase marketing."
  kpi-item:
    backgroundColor: "{colors.surface-card}"
    rounded: "{rounded.lg}"
    padding: 20px
    numberTypography: "{typography.kpi-number}"
  text-input:
    backgroundColor: "{colors.canvas}"
    border: "1px solid {colors.hairline}"
    rounded: "{rounded.md}"
    padding: 11px 14px
    focus: "border 1px {colors.primary} + box-shadow 0 0 0 1px {colors.primary} (efeito 2px)"
  badge-pill:
    rounded: "{rounded.full}"
    typography: "{typography.label}"
    padding: 3px 10px
  price-up:
    textColor: "{colors.success}"
    typography: "{typography.number-display}"
    note: "Só cor de texto — nunca fundo."
  price-down:
    textColor: "{colors.error}"
    typography: "{typography.number-display}"
  asset-icon-circular:
    backgroundColor: "{colors.surface-strong}"
    rounded: "{rounded.full}"
    size: 32px
---

## Visão geral

Design system do dashboard `jimi_webhook`, **derivado do sistema Coinbase** (`DESIGN-coinbase.md`). A estética institucional/editorial da Coinbase é aplicada à **estrutura de produto YUV** (rastreamento multi-tenant + gestão de ocorrências DMS descrita em `PROJETO_YUV.md`). Ele **substitui** a paleta Cursor (versões ≤3.x) e a paleta roxa YUV que havia sido proposta.

**Personalidade**: financeira, calma e quase monocromática. A **única voltagem de marca é o Coinbase Blue `#0052ff`** — usado com parcimônia em CTAs, links e foco. Canvas branco; a sidebar leva a **superfície dark near-black `#0a0b0d`** da Coinbase para a navegação do app (item ativo em azul). Geometria **pill** (100px) em todo CTA; números sempre em **JetBrains Mono**; profundidade por **um único nível de sombra** (hover), nunca camadas decorativas.

**Diferenças-chave vs. designs anteriores:**
| Aspecto | Cursor (≤3.x) | YUV (proposto) | **Coinbase (atual)** |
|---|---|---|---|
| Voltagem | Laranja `#f54e00` | Roxo `#702fd3` | **Azul `#0052ff`** |
| Canvas | Creme `#f7f7f4` | Lavanda `#faf9fc` | **Branco `#ffffff`** |
| Sidebar | Branca | Cor de marca | **Dark near-black `#0a0b0d`** |
| CTA | Raio 8px | Raio 8–10px | **Pill 100px** |
| Headings | Peso 400 | Peso 700–800 | **Peso 400 (editorial)** |
| Números | Inter | Inter | **JetBrains Mono** |
| Profundidade | Só hairline | Sombra sutil | **1 nível (hover)** |

## Cores

### Marca e ação
- **Coinbase Blue** `{colors.primary}` (#0052ff): CTAs primários, links, foco de input, item ativo da sidebar, ênfase inline. **Escasso** — um ou dois momentos azuis por tela.
- **Blue Active** `{colors.primary-active}` (#003ecc) · **Blue Disabled** `{colors.primary-disabled}` (#a8b8cc) · **Blue Soft** `{colors.primary-soft}` (#eaf0ff, fundo de badge/realce).

### Superfície
- **Canvas** `{colors.canvas}` (#ffffff) · **Surface Soft** `{colors.surface-soft}` (#f7f7f7, bandas alternadas/cabeçalho de tabela) · **Surface Strong** `{colors.surface-strong}` (#eef0f3, botão secundário/chips).
- **Surface Dark** `{colors.surface-dark}` (#0a0b0d, sidebar/heros) · **Surface Dark Elevated** `{colors.surface-dark-elevated}` (#16181c, hover/campos na sidebar).

### Texto
- **Ink** `{colors.ink}` (#0a0b0d) · **Body** `{colors.body}` (#5b616e) · **Muted** `{colors.muted}` (#7c828a) · **On Dark** `{colors.on-dark}` (#fff) / **On Dark Soft** `{colors.on-dark-soft}` (#a8acb3).

### Semântico (herança "trading" da Coinbase)
- **Sucesso / up** `{colors.success}` (#05b169) e **Erro / down** `{colors.error}` (#cf202f): preferir **cor de texto**; fundo apenas em versões suaves (badges). Nunca use verde/vermelho como fundo de botão.
- **Accent Yellow** `{colors.accent-yellow}` (#f4b000): ilustrativo/aviso, uso raro.

> **Risco DMS** (Ocorrências): mapeado sobre a paleta acima — Baixo = azul `{colors.primary}`, Médio = amarelo `{colors.accent-yellow}`, Alto = vermelho `{colors.error}`.

## Tipografia

- **Família**: **Inter** (400/500/600/700) para tudo; **JetBrains Mono** para **todo número** (KPIs, preços, %, IMEI, ICCID, códigos). Substitutos documentados de CoinbaseDisplay/Sans → Inter; CoinbaseMono → JetBrains Mono.
- **Display peso 400**: títulos de página/hero em `display-md`/`display-lg` ficam em **peso 400** com tracking negativo — voz institucional calma (a escolha tipográfica mais distintiva; não usar 700 em display).
- **KPIs e tabelas numéricas**: `kpi-number` / `number-display` em JetBrains Mono.
- **Rótulos de coluna**: `label` (11px/600, uppercase).

## Layout

### Shell
Sidebar dark fixa (244px, `surface-dark`) com item ativo azul + área principal em canvas branco. Header claro de 64px (`top-nav-light`) com contador de frota On/Off à esquerda e avatar → `/perfil` à direita.

### Grade e ritmo
- Conteúdo fluido; cards em grid `auto-fill minmax(220px, 1fr)`, 16–24px de gap.
- Base 4px. Densidade de dashboard: usamos raio **16px (lg)** nos cards de dados; **24px (xl)** fica reservado a cartões grandes (empty-state, feature). CTAs sempre **pill (100px)**; ícones/avatares **full (9999px)**.

## Profundidade e formas

- **Um único nível de sombra**: `{shadow.soft}` = `0 4px 12px rgba(0,0,0,.04)`, **apenas em hover** de card. Fora disso, superfícies planas com hairline `{colors.hairline}`. Não criar tiers de sombra.
- **Raio**: CTAs pill (100px); cards de dados 16px; cards grandes 24px; inputs 12px; ícones full.

## Componentes

### Botões (sempre pill)
- **Primário** azul (`{components.button-primary}`), **Secundário** cinza `surface-strong`, **Escuro** ink `#0a0b0d`. Disabled = azul desbotado. Um CTA primário por contexto.

### Cartões
- **Card** branco, hairline, 16px, sombra só no hover. **Feature/empty-state** 24px, 32px de padding.
- **KPI**: rótulo uppercase + número grande em mono + delta semântico (verde/vermelho).

### Formulários
- **Input** branco, borda hairline, 12px; **foco** = borda azul + `box-shadow 0 0 0 1px` (efeito 2px Coinbase Blue).

### Selos (badges) e situação
- Pill `full`, `label`. Situação/risco de ocorrência: `badge-error` (Alto), `badge-info`/azul (Baixo), `badge-warning` (Aguardando), `badge-success` (Resolvida).

### Superfícies de dados / "trading"
- Preços/variações e IMEI em `number-display` (mono); up/down **só cor de texto**. Ícones de ativo em plaquinha circular `surface-strong` 32px.

### Mapa e vídeo
- **Mapa**: Leaflet + OpenStreetMap (reuso). **Vídeo**: flv.js (reuso). Ambos herdam a paleta (controles/realces em azul).

### Gráficos (sem build step)
- Séries e pizzas: SVG inline no PHP ou uPlot/Chart.js por CDN. Cor de série primária = Coinbase Blue; up/down em verde/vermelho semânticos.

## Do's e Don'ts

### Do
- Reserve o **azul `#0052ff`** para CTAs, links, foco e item ativo — escasso.
- CTA sempre **pill**; número sempre em **JetBrains Mono**; ícone/avatar sempre **full circle**.
- Mantenha **headings de display em peso 400**.
- Sidebar **dark**; conteúdo em **canvas branco**.
- Use verde/vermelho **como cor de texto** (up/down); fundo só em badge suave.

### Don't
- Não introduza uma segunda cor de ação (o azul é a única; verde/vermelho são semânticos).
- Não use canvas creme/lavanda nem sidebar de cor de marca (herança dos designs anteriores).
- Não deixe display em peso 700+.
- Não crie tiers de sombra; há um único nível (hover).
- Não use cantos retos (0px) em CTAs; não use verde/vermelho como fundo de botão.

## Responsivo

| Breakpoint | Largura | Mudanças |
|---|---|---|
| Mobile | < 768px | Sidebar off-canvas (hambúrguer); grids 1-up; header compacto |
| Tablet | 768–1024px | Sidebar colapsável para ícones; grids 2-up |
| Desktop | > 1024px | Sidebar 244px; grids 3–4-up; conteúdo até ~1200px |

## Implementação

- CSS inline em `web/layout_base.php` (`:root` + componentes) — **já migrado** para os tokens acima.
- Telas de `web/login_template.php` e `handlers/setup.php` — **já migradas** (card 24px, botão pill azul, foco azul).
- Fonte: Inter `400;500;600;700` + JetBrains Mono via Google Fonts (sem build step).
- Referência de origem: [`DESIGN-coinbase.md`](DESIGN-coinbase.md). Estrutura de produto: [`PROJETO_YUV.md`](PROJETO_YUV.md).
