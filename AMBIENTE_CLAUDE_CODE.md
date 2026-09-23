# AMBIENTE_CLAUDE_CODE.md

> Retrato do ambiente de trabalho do **Claude Code** nesta máquina para este projeto — skills,
> memória, regras de comportamento, agentes, MCP e setup de dev — levantado em 22/09/2026 para
> reproduzir o mesmo ambiente num segundo computador. **Isto documenta a FERRAMENTA (Claude Code),
> não o produto.** As regras de negócio/arquitetura do `bycamera` já vivem em `CLAUDE.md` e
> `AGENTS.md`, que chegam ao outro computador normalmente via `git pull` — não estão repetidas aqui.
>
> Nada neste arquivo precisa de segredo nenhum: onde uma configuração depende de credencial
> (senha do MySQL local, chave SSH, etc.), o arquivo aponta ONDE ela mora, nunca o valor.

## 1. O que NÃO chega sozinho pelo `git pull`

Este é o ponto mais importante do documento. Três camadas deste ambiente vivem **fora do
histórico do git** e por isso não seguem o código automaticamente:

| Camada | Onde mora | Por quê fica de fora |
|---|---|---|
| **Configuração global do Claude Code** (modelo, `effortLevel`, auto mode, hooks) | `~/.claude/settings.json` (na home do usuário, não no repo) | É por-usuário/por-máquina, não por-projeto |
| **Memória de longo prazo** (lições aprendidas, decisões, armadilhas medidas) | `~/.claude/projects/<slug-do-caminho>/memory/` | É por-máquina; o Claude Code a constrói sozinho ao longo das sessões |
| **`.claude/settings.local.json`** (allowlist de permissões acumulada, credenciais locais como a senha do MySQL de dev) | `.claude/settings.local.json` no repo, mas **no `.gitignore`** | Contém segredo de máquina (senha root do MySQL local) — nunca deve ir para este repo, que é **público** |

Até hoje (22/09/2026) o `.gitignore` também escondia **as três skills escritas para este
projeto** (`.claude/skills/deploy`, `.claude/skills/db-setup`, `.claude/skills/status-archive`) —
`CLAUDE.md` já as citava havia meses, mas um clone novo do repo simplesmente não as trazia,
porque a regra `.claude/` no `.gitignore` escondia o diretório inteiro sem exceção. **Corrigido
nesta sessão**: o `.gitignore` agora tem uma exceção pontual para essas três pastas (ver §3) — a
partir do próximo `git pull` elas chegam junto com o código. `settings.json`,
`settings.local.json` e qualquer skill vinda de marketplace (ex.: `hallmark`) continuam de fora,
de propósito (ver §3).

**Para a outra máquina ficar igual a esta, faltam duas coisas que só um `git pull` não resolve:**
1. Configuração global do Claude Code (§2) — replicar manualmente ou aceitar o padrão de fábrica.
2. Memória de longo prazo (§5) — ou deixa reconstruir sozinha usando esta pasta, ou copia o
   diretório à mão (não é segredo, mas também não é código — decisão de gosto).

## 2. Modelo e configuração global do Claude Code

Isto está em `~/.claude/settings.json` **do usuário**, não no repositório — para reproduzir na
outra máquina, ajustar lá (ou via `/config`, `/model`, `/permissions` dentro do próprio Claude
Code).

- **Modelo padrão**: `sonnet` (Claude Sonnet 5, `claude-sonnet-5`).
- **`effortLevel: "xhigh"`** — nível de raciocínio alto por padrão, inclusive fixado por modelo
  em `modelSettings.claude-sonnet-5.effortLevel`.
- **`permissions.defaultMode: "auto"`** — Auto Mode ligado: o agente age sem parar para confirmar
  ações de baixo risco, mas ainda para em decisões genuinamente do usuário (ver `autoMode` abaixo).
- **`autoMode.soft_deny`** — exceções que EXIGEM confirmação mesmo em Auto Mode, específicas
  deste projeto:
  - `Bash(scripts/deploy.sh:*)` quando o alvo é `186.248.143.197` / `bycamera.ia.br` (produção)
  - `Bash(scripts/rollback.sh:*)`
  - `Bash(ssh:*)` para `186.248.143.197` / `bycamera.ia.br` (acesso remoto a produção)
  > ⚠️ **Divergência encontrada em 22/09/2026**: o Mac só tinha as regras de `deploy.sh` e `ssh`;
  > faltava `rollback.sh`. Foi adicionada no Mac nesta sessão para unificar. Se a outra máquina
  > (Windows) só tiver `deploy.sh` + `rollback.sh` (como documentado acima originalmente), falta
  > adicionar a regra de `ssh` lá — ver checklist §10.
- **`autoMode.environment`** — bloco de contexto que o Auto Mode usa para julgar risco: registra
  que o repositório `hssflavio-ux/jimi_webhook` é **PÚBLICO** (qualquer push publica), que os
  segredos vivem só em `.env` (gitignored, parseado à mão por `config/database.php`), quais hosts
  são "de confiança" (`jimicloud.com`, `docs.jimicloud.com`) e quais são alvo sensível
  (`bycamera.ia.br` / `186.248.143.197` = produção; `189.22.240.43` = homolog, tratado como
  staging, não sensível por padrão). Vale recriar esse bloco na outra máquina se o Auto Mode for
  usado lá também — é ele que evita o agente tratar homolog como produção ou vice-versa.
- **Outras chaves relevantes**: `autoUpdatesChannel: "latest"`, `tui: "fullscreen"`,
  `skipDangerousModePermissionPrompt: true`, `agentPushNotifEnabled: true`. (`autoUpdatesChannel`
  faltava no Mac — adicionada em 22/09/2026 para igualar.)
- **Plugins/skills adicionais ligados no Mac, não documentados aqui até 22/09/2026**:
  `superpowers@claude-plugins-official`, `remember@claude-plugins-official`,
  `security-guidance@claude-plugins-official`, `claude-md-management@claude-plugins-official`
  (além de `context-mode`, já documentado). É por isso que skills como
  `superpowers:brainstorming`/`systematic-debugging`/`writing-plans` e `remember:remember`
  aparecem disponíveis no Mac — uso real e pesado (`superpowers:brainstorming` 10x,
  `superpowers:writing-plans` 7x no histórico do Mac). Ver checklist §10 para replicar no Windows.
  O Mac também tem um `skillOverrides` desligando 9 skills genéricas não usadas neste projeto PHP
  (`code-reviewer`, `mcp-builder`, `senior-architect/backend/frontend/fullstack`,
  `ui-design-system`, `webapp-testing`, `ignore-optimizer`) — preferência de ruído, não obrigatório
  replicar.
- **Hook global** (`~/.claude/settings.json` → `hooks.SessionStart`): roda
  `context-mode-cache-heal.mjs` (`~/.claude/hooks/`) a cada início de sessão — é infraestrutura do
  plugin context-mode (§4), não deste projeto.

## 3. Skills — quais são efetivamente usadas

Contagens reais de uso (globais, de `~/.claude.json` → `skillUsage`, acumuladas nesta máquina):

| Skill | Usos | Origem |
|---|---|---|
| `loop` | 16 | built-in |
| `status-archive` | 6 | **deste projeto** |
| `deploy` | 5 | **deste projeto** |
| `doctor` (context-mode) | 3 | plugin context-mode |
| `context-mode:ctx-stats` | 2 | plugin context-mode |
| `update-config` | 2 | built-in |
| `claude-in-chrome` | 2 | built-in/extensão |
| `artifact-design` | 2 | built-in |
| `context-mode:ctx-doctor` / `ctx-upgrade` | 1 cada | plugin context-mode |
| `hallmark` | 1 | marketplace (`nutlope/hallmark`) |
| `find-skills` | 1 | marketplace (`vercel-labs/skills`) |
| `run`, `init`, `db-setup` | 1 cada | built-in / **deste projeto** |

### 3.1 Skills escritas para este projeto (`.claude/skills/`)

Só estas três existem hoje, **cada uma um único arquivo `SKILL.md`**, sem front-matter de
`metadata` (só `name` + `description`):

- **`deploy`** — comando de deploy com `sudo`, qual chave SSH funciona de qual máquina para
  produção/homolog, a chave do GitHub sob `sudo` em produção, o bump obrigatório de
  `SYSTEM_VERSION`, flags e rollback. Também documenta a armadilha "migração nova exige
  `deploy.sh` rodar duas vezes" (o `.gitignore`/CLAUDE.md também registram isso).
- **`db-setup`** — instalação de banco MySQL do zero e ordem completa de migrações.
  ⚠️ **Desatualizada**: a lista para em `migration_v4.9.14.sql`, e o projeto já está em v4.23.0
  (migrações até `v4.21.5.sql` na última sincronização). Vale atualizar antes de confiar nela
  para montar um banco novo — hoje ela mesma avisa "esta lista precisa acompanhar
  `scripts/deploy.sh`" e já ficou para trás uma vez antes.
- **`status-archive`** — como arquivar o log cronológico do topo do `STATUS.md` para
  `docs/status-history/STATUS_ARCHIVE.md`, mantendo as 3 entradas mais recentes inline.

**Pendência resolvida em 22/09/2026 (sessão no Mac)**: a quarta skill, `protocolo-comandos`
(`.claude/skills/protocolo-comandos/SKILL.md`, sobre catálogo de comandos JT/T, firmware, vídeo,
chunked body do Apache, `FILELIST`/`VIDEOUPLOAD`, Query APIs JIMI×JT/T), já existia **escrita
localmente no Mac** (não se sabe se veio de uma sessão anterior ou foi escrita e nunca versionada),
mas ficava fora do `git` porque a exceção do `.gitignore` (ver §3.2) só cobria `deploy`,
`db-setup` e `status-archive`. Corrigido nesta sessão: adicionada a exceção
`!.claude/skills/protocolo-comandos/` e o arquivo foi commitado — a partir do próximo `git pull`
ele chega em qualquer máquina, Windows incluído. Ver §10.

### 3.2 Por que elas não viajavam pelo git (corrigido nesta sessão)

`.gitignore` tinha uma regra plana `.claude/` que escondia o diretório inteiro. Ajustado para:

```gitignore
.claude/*
!.claude/skills/
.claude/skills/*
!.claude/skills/deploy/
!.claude/skills/db-setup/
!.claude/skills/status-archive/
```

Isso versiona só as três skills do projeto — deixa de fora `settings.json`/`settings.local.json`
(estado/permissões da máquina) e qualquer skill de **marketplace** cacheada localmente (ex.:
`hallmark`, que sozinha passa de 100 arquivos de referência de design, sem relação com este
projeto PHP). Skills de marketplace são reinstaladas a partir do `skills-lock.json` (esse sim
versionado — ver 3.3), não vendorizadas no repo.

### 3.3 Skills instaladas via marketplace (`skills-lock.json`, já no git)

```json
{
  "find-skills": { "source": "vercel-labs/skills", "skillPath": "skills/find-skills/SKILL.md" },
  "hallmark":    { "source": "nutlope/hallmark",   "skillPath": "skills/hallmark/SKILL.md" }
}
```

Isso viaja pelo git normalmente. Na outra máquina, o próprio Claude Code resolve a instalação a
partir do lock file (mesma mecânica de um `package-lock.json`) — não precisa copiar nada à mão.
`find-skills` ajuda a descobrir skills novas por marketplace; `hallmark` é a skill anti-slop de
design (mockups, redesign, auditoria visual) — usada uma vez neste projeto, provavelmente na fase
de estudo do YUV (`analise_yuv/`).

### 3.4 Skills built-in usadas com frequência (não precisam de setup — já vêm com o Claude Code)

`loop` (rodar um prompt/skill em intervalo, uso mais alto do projeto — 16x), `deploy`-adjacent
`run` (subir e testar o app), `update-config` (mexer em `settings.json`/hooks), `doctor` (ver
abaixo, é do plugin context-mode, não do produto médico), `artifact-design`, `claude-in-chrome`,
`init`. Nenhuma ação necessária além de ter o Claude Code atualizado.

### 3.5 Agentes (Task tool / subagents)

Uso real é **baixo** neste projeto: só um agente em background (`bg`) apareceu no histórico. Não
há agentes customizados em `.claude/agents/` (o diretório não existe). Na prática, o trabalho
neste repo é feito quase sempre pelo agente principal em conversa direta, sem fan-out para
subagentes — vale manter esse padrão na outra máquina em vez de introduzir spawns novos por
hábito.

## 4. MCP / Plugins

Não há `.mcp.json` neste repositório — toda a configuração de plugins/MCP é **global**
(`~/.claude/settings.json` → `enabledPlugins`, `extraKnownMarketplaces`), não por-projeto.

- **`context-mode@context-mode`** — **ligado e de longe o mais usado** (7.694 chamadas
  acumuladas nesta máquina). Marketplace `mksglu/context-mode`. Roda comandos/leituras pesadas
  (`git log`, saída de teste, JSON grande, snapshot de página) num sandbox e só devolve o
  resultado processado para a conversa — existe para não estourar a janela de contexto em
  tarefas de análise. Ferramentas: `ctx_batch_execute`, `ctx_execute`, `ctx_execute_file`,
  `ctx_search`, `ctx_fetch_and_index`, `ctx_index`, `ctx_insight`, `ctx_stats`, `ctx_doctor`,
  `ctx_upgrade`, `ctx_purge`. Também ativa um hook `PreToolUse`/`SessionStart` global (visto nos
  system-reminders desta sessão) que sugere seu uso no lugar de Bash/Grep/Read crus quando a
  intenção é **processar** a saída, não só observá-la.
  - Habilitado também no projeto: `.claude/settings.json` tem
    `{"enabledPlugins": {"context-mode@context-mode": true}}` — mas esse arquivo está no
    `.gitignore` (§3.2), então essa habilitação por-projeto **não** chega à outra máquina; lá o
    plugin funcionará pela configuração global mesmo assim, já que está habilitado em
    `~/.claude/settings.json`.
- **`reflexion@context-engineering-kit`** — instalado (marketplace `NeoLabHQ/context-engineering-kit`,
  304 usos históricos) mas **hoje DESLIGADO** (`enabledPlugins: false`). Não reativar sem
  confirmar com o usuário — foi desligado deliberadamente em algum momento.
- **`claude-in-chrome`** — extensão do Chrome pareada com esta máquina
  (`chromeExtension.pairedDeviceId`, apelido "Browser 1"). Pareamento é por dispositivo: **não
  transporta via git nem via config** — na outra máquina é preciso instalar a extensão e parear
  de novo. Foi usada para explorar `app.yuv.com.br` (fase de estudo do YUV Parity) e para
  screenshot/E2E visual pontual.
- **Marketplaces conhecidos** (`extraKnownMarketplaces`): `context-engineering-kit`
  (`NeoLabHQ/context-engineering-kit`) e `context-mode` (`mksglu/context-mode`).
  > ⚠️ **Divergência em 22/09/2026**: o Mac tem `context-mode` também, mas não
  > `context-engineering-kit` (não precisa — `reflexion` fica desligado por decisão do usuário) —
  > e tem TRÊS que faltam aqui: `interface-design` (`Dammyjay93/interface-design`),
  > `anthropic-agent-skills` (`anthropics/skills`) e `karpathy-skills`
  > (`forrestchang/andrej-karpathy-skills`), sem uso registrado no histórico do Mac, e
  > `ponytail` (`DietrichGebert/ponytail`), com 3 usos reais (`ponytail-audit` 2x,
  > `ponytail-gain` 1x). Ver §10.

## 5. Sistema de memória (aprendizados entre sessões)

Mecanismo do próprio Claude Code, documentado no prompt de sistema, **não é deste projeto** — mas
o que ele acumulou SOBRE este projeto é valioso e vale entender antes de repetir erros já
resolvidos.

- **Onde mora**: `~/.claude/projects/<caminho-do-repo-com-traços>/memory/` — nesta máquina,
  `C:\Users\flavi\.claude\projects\C--Users-flavi-Documents-Antigravity-jimi-webhook\memory\`.
  Na outra máquina o slug do caminho será diferente (depende de onde o repo for clonado), e a
  pasta **nasce vazia** — o Claude Code a preenche sozinho, sessão após sessão, à medida que
  aprende coisas sobre o projeto.
- **Formato**: um `MEMORY.md` índice (uma linha por memória, `- [Título](arquivo.md) — gancho`)
  mais um arquivo `.md` por memória, com front-matter:
  ```yaml
  ---
  name: kebab-case-slug
  description: "resumo de uma linha, usado para achar relevância"
  metadata:
    node_type: memory
    type: feedback   # ou: user | project | reference
    originSessionId: <uuid da sessão que criou>
    modified: <timestamp ISO>
  ---
  ```
  Corpo em prosa com **Why:** (motivo/incidente que gerou a lição) e **How to apply:** (quando
  ela se aplica) — para tipos `feedback` e `project`. Memórias se linkam entre si com
  `[[outro-slug]]`.
- **34 memórias acumuladas nesta máquina hoje** (22/09/2026), quase todas `type: project`
  (particularidades medidas do produto/infra: fuso horário, protocolo JT/T vs JIMI, armadilhas de
  deploy, etc.) e algumas `type: feedback` (como o usuário quer ser atendido — ver §6). O índice
  completo (títulos + gancho de uma linha) está em `MEMORY.md` nessa pasta; não reproduzido aqui
  porque é conteúdo operacional específico deste produto, não da ferramenta.
- **Não transporta por git, de propósito** — é estado de aprendizado por-máquina. Duas opções
  para a outra máquina:
  1. **Deixar reconstruir sozinha** — mais simples, mas repete o custo de redescobrir armadilhas já
     medidas (ex.: senha de MySQL local, rate-limit de login, formato do `VIDEOUPLOAD`) até a
     memória crescer de novo lá.
  2. **Copiar a pasta `memory/` à mão** (USB, zip, `scp`) para o slug correspondente na outra
     máquina — funciona porque nenhuma memória registrada aqui contém segredo (senhas/tokens são
     descritos por onde ficam, nunca pelo valor), mas é uma cópia manual fora do fluxo normal do
     Claude Code, então pode ficar desatualizada em relação à máquina de origem depois do primeiro
     dia.

## 6. Regras de comportamento fixadas por feedback do usuário

Estas vieram de correções/confirmações explícitas do usuário durante sessões neste repo e estão
gravadas como memória `type: feedback` — não são convenção de código, são de **como trabalhar**.
Valem também na outra máquina, mas só se a memória for copiada (§5) ou o usuário repetir a
instrução lá:

- **Commit e push automáticos ao concluir tarefa de código, sem perguntar antes.** Duas
  instruções explícitas na mesma sessão ("sempre faça o commit" → depois "suba para o git
  sempre") tornaram isso permanente **para este repo**. Continua valendo dentro disso: revisar
  `git status`/`git diff` antes de commitar, nunca `--no-verify`, nunca force-push. Deploy em
  produção continua sendo passo separado, nunca implícito no commit/push (reforçado pelo
  `soft_deny` do Auto Mode, §2).
  - Armadilha registrada: "antes que eu comite" do usuário é pedido de **agrupar tudo num commit
    só**, não uma transferência da responsabilidade de commitar — o agente já parou por engano
    uma vez interpretando errado.
- **Responder sempre em pt-BR** — preferência geral de comunicação, não ligada a uma tarefa
  específica.
- **Produção é `bycamera.ia.br` / `186.248.143.197`, não `189.22.240.43`** — a documentação
  antiga trocou os nomes por meses (o homolog foi o único ambiente até 13/08/2026 e ficou
  registrado como "produção" em texto velho). Não referenciar homolog como produção sem
  instrução explícita nova. Ver `CLAUDE.md` §"Ambientes" para a tabela completa.
- **Acesso remoto real**: chave SSH do Windows autorizada em produção desde 19/08/2026; `sudo`
  ainda pede senha mesmo entrando por chave (são coisas diferentes); `plink` do PuTTY como
  alternativa quando a chave não vale. Detalhe operacional completo na skill `deploy` (§3.1).

## 7. Ambiente de desenvolvimento local (Windows + Mac)

### 7.1 Windows

Já documentado em `scripts/dev-windows.ps1` e `.env.example` (ambos no git — chegam sozinhos), mas
o resumo de como efetivamente se trabalha aqui:

- **PHP 8.3** em `C:\Users\flavi\php` (no PATH do usuário) — nesta máquina, PHP 8.3.32 CLI.
- **MySQL 8.0.37 portátil** em `C:\Users\flavi\mysql` — **não é serviço do Windows**; sobe com
  `scripts/dev-windows.ps1` (que também sobe o `php -S` embutido servindo `server.php`) ou
  manualmente com `mysqld --defaults-file=C:\Users\flavi\mysql\my.ini`. Sem ele rodando, nada que
  toca banco funciona, nem a tela de login. **Credencial fica no `.env`/no próprio script — não
  reproduzida aqui.**
- **Node.js** só para a suíte Playwright (`v24.14.1` nesta máquina) — a aplicação em si não usa
  Node/build step nenhum.
- **Usuário de teste E2E**: `e2e@teste.local` / senha em `.env`/memória local, admin do
  customer 1 (existe também no homolog).
- **Armadilhas conhecidas de dev local** (detalhadas na memória `jimi-webhook-workflow`, não
  repetidas por completo aqui): rate-limit de login bloqueia o IP após 5 falhas em 15 min e
  derruba a suíte Playwright inteira com um falso "timeout"; o campo CSRF é `_csrf_token`, não
  `csrf_token`; dá para logar sem senha injetando uma linha em `sessions` + cookie `jimi_token`
  para smoke test rápido.
- **Testes**: `php -l` por arquivo/lint completo, `scripts/test_e2e.sh` (replay de webhook com
  asserções no MySQL), suíte Playwright em `tests/` (`npx playwright test` ou
  `scripts/run-tests.ps1`) — specs autenticados pulam (não falham) sem `TEST_EMAIL`/`TEST_PASSWORD`.

### 7.2 Mac — provisionado em 22/09/2026 (não existia até então)

Até esta sessão o Mac não tinha ambiente de dev nenhum montado — só o código e o Claude Code,
sem `.env`, sem MySQL server (só a lib cliente `mysql-client` do Homebrew) e sem `node_modules`.
Provisionado do zero nesta sessão, para chegar perto da paridade com o Windows:

- **PHP**: continua no `8.5.10` (Homebrew) do sistema — **não trocado de propósito**, por pedido
  do usuário ("não é importante nesse momento"). Different do `8.3` de produção/Windows — ver
  achado abaixo sobre por que isso importa mais do que parece.
- **MySQL 8.4** via Homebrew (`brew install mysql@8.4` — o formula `mysql` sozinho instala uma
  versão muito mais nova, incompatível, ver bug abaixo). Keg-only, linkado com
  `brew link mysql@8.4 --force`; sobe com `brew services start mysql@8.4`. Banco `jimi_tracker`
  criado e populado com `mysql/jimi_tracker.sql` + as 61 migrações em ordem, `2.0.0` até `4.21.5`
  (a mesma lista que a skill `db-setup` deveria ter — ela ainda está parada em `4.9.14`, ver §3.1
  do AGENTS/CLAUDE; a lista completa e correta usada aqui foi extraída de
  `scripts/deploy.sh:414-484`, que é a fonte de verdade real). Senha de root gerada localmente
  (aleatória, só no `.env` deste Mac, não reproduzida aqui).
- **`.env` criado** a partir de `.env.example`, só com as chaves essenciais para rodar localmente
  (`DB_*`, `WEBHOOK_TOKEN`, `SYSTEM_VERSION`, `APP_URL=http://localhost:8000`) — as variáveis de
  FTP/vídeo/SMTP/SMS/IoTHub ficaram de fora de propósito: são infraestrutura que só existe nos
  servidores reais (produção/homolog), sem equivalente local.
- **Node 24** instalado via `brew install node@24`, **keg-only, sem linkar** — não altera o `node`
  global do sistema (Homebrew tinha só um `node` genérico em v26, usado por outros projetos deste
  Mac). Para este projeto, usar o binário completo:
  `/opt/homebrew/opt/node@24/bin/npm install` / `npx playwright test`. `npm install` +
  `npx playwright install chromium` já rodados — suíte Playwright pronta para uso.
- **Servidor de dev**: `php -S 127.0.0.1:8000 -t . server.php` (equivalente Mac do que
  `dev-windows.ps1` faz no Windows) — não existe ainda um script `.sh` dedicado; rodar manualmente.
- **`/ping` validado**: responde `v4.23.0` corretamente.

**Dois bugs reais encontrados ao provisionar** (não são do ambiente — são do código, expostos por
rodar num MySQL/servidor mais novo/diferente do que qualquer pessoa tinha testado até agora):

1. 🔴 **`mysql/jimi_tracker.sql` tinha emoji dentro do CORPO de duas stored procedures**
   (`update_device_stats_after_gps`, comentários `-- 🔴`/`-- ⚠️`) — MySQL 8.4 (e também a v26 que
   instalei por engano antes de trocar) recusa com `ERROR 4089: Definition of stored routine
   contains an invalid utf8mb3 character string`, porque o dicionário interno de rotinas do MySQL
   é limitado a utf8mb3 independente do charset da conexão/tabela. Isso **quebra qualquer
   instalação nova do zero** (exatamente o caminho que a skill `db-setup` documenta) — corrigido
   nesta sessão removendo só os 4 emojis de DENTRO dos blocos `DELIMITER //...DELIMITER ;` do
   arquivo (script usado: busca por bloco delimitado + regex de emoji, não um find-replace cego —
   emoji em comentário FORA de rotina, que existe aos montes nos outros `.sql`, não tem esse
   problema e foi deixado como está). Commitado junto com o resto desta sessão.
2. ⚠️ **`config/WebhookHandler.php:34` lê `getenv('WEBHOOK_TOKEN')` ANTES de `env_load()` ter
   rodado** — `env_load()` só é disparado de dentro do `Database::__construct()`, chamado na
   linha seguinte (36). Em produção isso nunca aparece porque os workers do PHP-FPM ficam quentes
   e alguma requisição anterior (qualquer uma, não precisa ser webhook) já rodou `env_load()`
   naquele worker antes. Rodando com `php -S` (que não reaproveita estado entre requisições) ou
   — mais preocupante — num worker de PHP-FPM **recém-reiniciado**, o PRIMEIRO webhook a chegar
   cai no fallback `'a12341234123'` e é rejeitado com `401 Unauthorized` mesmo com o token certo.
   Foi isso, e não a versão do PHP, que fez `scripts/test_e2e.sh` falhar em bloco nesta máquina.
   **Não corrigido** — é código de produção (autenticação de webhook), fora do escopo desta
   sessão (sincronizar ambiente); fica registrado para decisão do usuário.

## 8. Mapa de documentação do próprio repo (já vem pelo git)

Não duplicado aqui — só o índice de onde procurar o quê:

- `CLAUDE.md` — regras de arquitetura, convenções e armadilhas medidas do produto (o arquivo mais
  denso e mais importante; leitura obrigatória antes de qualquer mudança).
- `AGENTS.md` — mesmo nível de detalhe arquitetural, com tabelas completas de rotas/schema.
- `STATUS.md` — log cronológico do estado atual + roadmap YUV Parity (3 entradas mais recentes
  inline, resto arquivado via skill `status-archive` em `docs/status-history/STATUS_ARCHIVE.md`).
- `CHANGELOG.md` — Keep a Changelog, por versão.
- `PROJETO_YUV.md` — blueprint-mestre da transformação em cópia da plataforma YUV.
- `PROJETO_PARAMETROS.md` — blueprint da parametrização remota JT/T (`33027`/`33028`/`33030`).
- `DESIGN.md` / `DESIGN-coinbase.md` — design system (Coinbase: azul `#0052ff`, sidebar
  near-black, CTAs pill).
- `docs/COMANDOS_128_CONSULTA.md`, `docs/FILA_OFFLINE_COMANDOS.md`, `docs/QUERY_APIS_IOTHUB.md` —
  medições de protocolo (proNo 128, fila offline, Query APIs JIMI×JT/T) que alimentaram a skill
  `protocolo-comandos`, já escrita e versionada desde 22/09/2026 (§3.1).

## 9. Checklist para deixar a outra máquina parecida com esta

1. `git clone`/`git pull` o repo — traz código, `CLAUDE.md`/`AGENTS.md`, `skills-lock.json` e as
   **quatro** skills do projeto, `protocolo-comandos` incluída desde 22/09/2026 (§3.2).
2. Instalar Claude Code, logar com a mesma conta — plugins de marketplace (`hallmark`,
   `find-skills`) reinstalam a partir do `skills-lock.json`; `context-mode` precisa ser
   habilitado manualmente se não vier por padrão (marketplace `mksglu/context-mode`).
3. Ajustar `~/.claude/settings.json` na máquina nova (§2): modelo `sonnet`, `effortLevel: xhigh`
   se desejado, `permissions.defaultMode: auto`, `autoUpdatesChannel: latest`, e (se for usar Auto
   Mode) recriar o bloco `autoMode.environment`/`soft_deny` completo — as **três** regras
   (`deploy.sh`, `rollback.sh`, `ssh` para produção), não só duas. Ver §10 para o checklist
   detalhado do que replicar de cada lado.
4. Escrever `.env` local a partir de `.env.example` (§7) — nenhum valor real chega pelo git.
5. Instalar MySQL **8.4** (ou a versão que bater com produção) e rodar a skill `db-setup` —
   **atualizada e validada ponta-a-ponta em 22/09/2026** (61 migrações, `2.0.0` → `4.21.5`; ver
   §7.2 sobre o bug de emoji em stored procedure que ela agora documenta).
6. `npm install` só se for rodar a suíte Playwright — no Mac isso foi feito com uma instalação
   `keg-only` do Node 24 via Homebrew, sem mexer no Node global da máquina (§7.2); adaptar à
   ferramenta de versionamento de Node disponível em cada máquina.
7. `claude-in-chrome`: não precisou de pareamento manual nesta sessão — a extensão já respondia
   às ferramentas MCP assim que instalada, mesmo com `chromeExtension` vazio em `~/.claude.json`.
   Só investigar mais se as ferramentas `mcp__claude-in-chrome__*` falharem na prática.
8. Decidir sobre a memória (§5): deixar reconstruir sozinha ou copiar a pasta manualmente — sem
   mudança nesta sessão (o Mac tem só 6 arquivos de memória contra as 34 relatadas no Windows;
   não há como copiar de uma sessão que só enxerga o Mac).
9. Ver §10 — checklist específico do que EXISTE/É USADO no Mac e falta replicar no Windows.

## 10. Itens do Mac que faltam no Windows (levantado e ajustado em 22/09/2026)

Comparação feita nesta sessão entre o retrato deste documento (autoria original: Windows,
22/09/2026, antes do meio-dia) e o estado real do Mac no mesmo dia. Tudo que dava para ajustar
**a partir desta máquina** (repositório git + `~/.claude/settings.json` local) já foi feito; o que
segue é o que só pode ser aplicado abrindo o Claude Code **no Windows**.

### 10.1 Já ajustado nesta sessão (não precisa repetir manualmente — chega pelo `git pull`)

- Skill `protocolo-comandos` versionada (§3.1/§3.2) — chega no próximo `git pull` de qualquer
  máquina.
- `mysql/jimi_tracker.sql` sem emoji dentro de stored procedure (§7.2) — corrige a instalação
  fresca em qualquer máquina, Windows incluído (não é preciso reinstalar o banco do Windows, que
  já está de pé; só importa para quem monta um banco novo do zero a partir de agora).
- Skill `db-setup` com a lista de 61 migrações atualizada (`2.0.0` → `4.21.5`), extraída de
  `scripts/deploy.sh` e validada de ponta a ponta.

### 10.2 Só ajustável abrindo o Claude Code no Windows (editar `~/.claude/settings.json` de lá)

1. **`autoMode.soft_deny`** — adicionar a regra que falta:
   `Bash(ssh:*) para 186.248.143.197 / bycamera.ia.br — acesso remoto a produção`
   (o Mac tinha essa e não tinha `rollback.sh`; ficou corrigido no Mac. O Windows, pelo
   documentado aqui, tem `rollback.sh` mas não tem a de `ssh` — falta essa última lá).
2. **`autoUpdatesChannel: "latest"`** — conferir se já está lá (o Mac não tinha até esta sessão).
3. **Plugins a habilitar, se quiser o mesmo conjunto de skills disponíveis no Mac** (uso real,
   não especulativo — contagens do histórico do Mac entre parênteses):
   - `superpowers@claude-plugins-official` (`brainstorming` 10x, `writing-plans` 7x,
     `subagent-driven-development` 5x, `systematic-debugging` 5x, `executing-plans` 2x,
     `test-driven-development` 1x) — é de onde vêm as skills de processo
     (`superpowers:brainstorming`, `superpowers:systematic-debugging` etc.) citadas no próprio
     prompt de sistema do Claude Code.
   - `remember@claude-plugins-official` — mantém o histórico de sessão em `.remember/`
     (`now.md`, `recent.md`, `archive.md`) que aparece nos hooks de `SessionStart`/consolidação.
   - `claude-md-management@claude-plugins-official` (`claude-md-improver` 1x,
     `revise-claude-md` 1x) — auditoria/atualização deste próprio `CLAUDE.md`.
   - `security-guidance@claude-plugins-official` — sem uso isolado no histórico, mas ligado; é o
     que dá a skill `security-review`.
4. **Marketplace `ponytail`** (`DietrichGebert/ponytail`) — único dos "extras" do Mac com uso
   real (`ponytail-audit` 2x, `ponytail-gain` 1x). `interface-design`, `anthropic-agent-skills` e
   `karpathy-skills` também estão registrados no Mac mas **sem nenhum uso** no histórico — só
   adicionar no Windows se for usar de fato, não por completude.
5. **NÃO fazer**: reativar `reflexion@context-engineering-kit` — está desligado no Windows
   deliberadamente (decisão de usuário registrada em memória); o Mac nem tem o marketplace
   `context-engineering-kit` registrado, e está certo assim.

### 10.3 Aplicado no Windows em 22/09/2026 (sessão seguinte, depois do `git pull`)

Os itens de §10.1 chegaram sozinhos pelo `git pull` (skill `protocolo-comandos`, `db-setup`
atualizada, `jimi_tracker.sql` sem emoji em stored procedure) — nenhuma ação manual precisou. De
§10.2, aplicado direto em `~/.claude/settings.json` **desta máquina** (Windows):

1. ✅ `autoMode.soft_deny` ganhou a regra de `ssh` para produção — as três máquinas (Mac/Windows)
   agora têm o mesmo trio: `deploy.sh`, `rollback.sh`, `ssh` para `186.248.143.197`/`bycamera.ia.br`.
2. ✅ `autoUpdatesChannel: "latest"` já estava presente (não era pendência real do Windows, só do
   Mac na sessão anterior).
3. ✅ Habilitados os quatro plugins do marketplace oficial com uso real comprovado no Mac:
   `superpowers@claude-plugins-official`, `remember@claude-plugins-official`,
   `claude-md-management@claude-plugins-official`, `security-guidance@claude-plugins-official`.
4. ✅ `ponytail` registrado em `extraKnownMarketplaces` (`DietrichGebert/ponytail`) e habilitado
   como `ponytail@ponytail` em `enabledPlugins`. ⚠️ **A chave `ponytail@ponytail` é um palpite por
   convenção** (repetiu o padrão `<id>@<id>` que `context-mode@context-mode` e
   `reflexion@context-engineering-kit` já usam) — não foi possível confirmar o nome exato do
   plugin dentro do marketplace a partir desta sessão (sem acesso interativo ao seletor de
   plugins). Conferir no primeiro `/plugin` aberto nesta máquina; se o nome vier diferente,
   corrigir a chave em `enabledPlugins`.
   `interface-design`, `anthropic-agent-skills` e `karpathy-skills` ficaram de fora, como o Mac
   também decidiu — zero uso registrado em qualquer uma das duas máquinas.
5. Não fiz o `skillOverrides` de 9 skills genéricas desligadas no Mac — o próprio §10.2 já
   descreve isso como preferência de ruído, não obrigatório para a paridade.
6. `reflexion@context-engineering-kit` continua `false` — não reativado, como instruído.

Depois deste ajuste, reiniciar o Claude Code (ou `/reload-plugins`) para os plugins novos
carregarem.

### 10.4 Ambiente de dev local — decisão consciente de NÃO igualar agora

- **PHP**: Mac em 8.5.10 (Homebrew), Windows em 8.3.32 — **mantido divergente a pedido do
  usuário** ("não é importante nesse momento", 22/09/2026). Vale lembrar que essa divergência já
  se mostrou real na prática (§7.2, achado nº2: `WebhookHandler.php` só falhou visivelmente sob
  execução "fria", que o Mac expôs e o Windows/produção não) — não é só uma diferença de número
  de versão.
- **MySQL**: Windows usa 8.0.37 portátil, Mac usa 8.4 (Homebrew) — ambas mais antigas que a `26.x`
  que o Homebrew instala por padrão hoje (que quebra o schema, §7.2). Não há ação pendente aqui;
  registrado só para quem for reinstalar do zero em qualquer uma das duas não pegar a versão
  default do Homebrew sem querer.
- **Node**: Windows em v24.14.1, Mac agora em v24.21.0 (keg-only, via `node@24`, sem tocar no
  Node global v26 do sistema) — mesma linha major, suficiente para a suíte Playwright.
