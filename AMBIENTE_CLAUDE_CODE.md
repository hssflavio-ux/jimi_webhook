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
- **`autoMode.soft_deny`** — duas exceções que EXIGEM confirmação mesmo em Auto Mode, específicas
  deste projeto:
  - `Bash(scripts/deploy.sh:*)` quando o alvo é `186.248.143.197` / `bycamera.ia.br` (produção)
  - `Bash(scripts/rollback.sh:*)`
- **`autoMode.environment`** — bloco de contexto que o Auto Mode usa para julgar risco: registra
  que o repositório `hssflavio-ux/jimi_webhook` é **PÚBLICO** (qualquer push publica), que os
  segredos vivem só em `.env` (gitignored, parseado à mão por `config/database.php`), quais hosts
  são "de confiança" (`jimicloud.com`, `docs.jimicloud.com`) e quais são alvo sensível
  (`bycamera.ia.br` / `186.248.143.197` = produção; `189.22.240.43` = homolog, tratado como
  staging, não sensível por padrão). Vale recriar esse bloco na outra máquina se o Auto Mode for
  usado lá também — é ele que evita o agente tratar homolog como produção ou vice-versa.
- **Outras chaves relevantes**: `autoUpdatesChannel: "latest"`, `tui: "fullscreen"`,
  `skipDangerousModePermissionPrompt: true`, `agentPushNotifEnabled: true`.
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

**Pendência conhecida**: a versão mais recente do `CLAUDE.md` (puxada nesta sessão) já referencia
uma **quarta skill, `protocolo-comandos`** (`.claude/skills/protocolo-comandos/SKILL.md`, sobre
catálogo de comandos JT/T, firmware, vídeo, chunked body do Apache, `FILELIST`/`VIDEOUPLOAD`,
Query APIs JIMI×JT/T) — o arquivo **ainda não existe** em nenhuma das duas máquinas. É conteúdo a
escrever (ou resgatar de alguma sessão anterior que não persistiu o arquivo), não algo para copiar.

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

## 7. Ambiente de desenvolvimento local (Windows)

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
  medições de protocolo (proNo 128, fila offline, Query APIs JIMI×JT/T) que vão alimentar a skill
  `protocolo-comandos` ainda não escrita (§3.1).

## 9. Checklist para deixar a outra máquina parecida com esta

1. `git clone`/`git pull` o repo — traz código, `CLAUDE.md`/`AGENTS.md`, `skills-lock.json` e,
   a partir de agora, as três skills do projeto (§3.2).
2. Instalar Claude Code, logar com a mesma conta — plugins de marketplace (`hallmark`,
   `find-skills`) reinstalam a partir do `skills-lock.json`; `context-mode` precisa ser
   habilitado manualmente se não vier por padrão (marketplace `mksglu/context-mode`).
3. Ajustar `~/.claude/settings.json` na máquina nova (§2): modelo `sonnet`, `effortLevel: xhigh`
   se desejado, `permissions.defaultMode: auto`, e (se for usar Auto Mode) recriar o bloco
   `autoMode.environment`/`soft_deny` específico deste projeto.
4. Escrever `.env` local a partir de `.env.example` (§7) — nenhum valor real chega pelo git.
5. Instalar PHP 8.3 + MySQL portátil (ou serviço) e rodar a skill `db-setup` — **conferir antes
   se a lista de migrações precisa de atualização** (hoje para em v4.9.14, o projeto está em
   v4.23.0; ver §3.1).
6. `npm install` só se for rodar a suíte Playwright.
7. Parear a extensão `claude-in-chrome` de novo (pareamento é por dispositivo, §4).
8. Decidir sobre a memória (§5): deixar reconstruir sozinha ou copiar a pasta manualmente.
9. Escrever a skill `protocolo-comandos` que falta (§3.1) — pendência que já existe nesta
   máquina também, não é regressão da outra.
