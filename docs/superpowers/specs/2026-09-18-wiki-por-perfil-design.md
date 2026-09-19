# Central de Ajuda por perfil — desenho

Data: 2026-09-18 · Estado: aguardando revisão do dono do produto
Escopo: `handlers/wiki.php` (rota `/wiki`), com registro de seções, renderizador
sensível ao acesso e conteúdo atualizado desde a v4.13.16.

## 1. Problema

1. **Conteúdo defasado.** `handlers/wiki.php` tem 2047 linhas de HTML escrito à
   mão; o docblock para na v4.13.16 e o rodapé diz "Última atualização:
   14/08/2026". O sistema está na v4.21.8. Sem nenhuma menção: Manutenção,
   Comandos por SMS, SMS (configuração), recuperar/trocar senha, o fluxo
   chip → câmera → veículo (v4.11.0) e hodômetro. Quase sem cobertura: Painel,
   Configurações IA, Auditoria.
2. **Igual para todos.** O índice e o conteúdo não dependem de quem lê. Só
   alguns títulos têm o badge "admin", e o badge é texto solto: nada o liga ao
   `require_admin()` do handler.
3. **Nada obriga a wiki a acompanhar o sistema.** Tela nova entra hoje em dois
   lugares (`$screenByHandler` e `$screens`); a wiki ficou de fora e envelheceu
   por isso.

## 2. Decisões do dono do produto

| Pergunta | Decisão |
|---|---|
| Tela que o usuário não pode abrir | **Mostrar bloqueada**: fica no índice com cadeado, uma linha do que é a tela e o motivo. Sem passo-a-passo. |
| Ações finas (criar/editar/excluir/exportar) | **Faixa "Seu acesso" por seção**, gerada de `can()`. O texto das ações não muda. |
| Abertura | **Card por perfil** (admin, revendedor, cliente/operador), com atalhos filtrados pelo acesso real. |
| Abordagem técnica | **A**: registro de seções + parciais por seção. |

## 3. Regra de acesso

A wiki responde à pergunta "esta pessoa consegue abrir esta tela?" do mesmo
jeito que o **handler** responde, não como o menu mostra. Ordem de decisão:

1. `admin_only` e `role !== 'admin'` → **bloqueada**, motivo "restrita a
   administradores".
2. `can(screen, 'view')` falso → **bloqueada**, motivo "seu grupo de permissão
   não libera esta tela; peça ao administrador da sua conta".
3. Caso contrário → **liberada**.

`admin_only` é copiado do `require_admin()` que abre o handler (linha que
começa com `require_admin();`). Medido em 19/09/2026, vale para: Clientes,
Usuários, Perfis de Parâmetros, Parâmetros, Firmware, SMS (config),
Configurações IA e o relatório de Parâmetros. **Não são admin-only:** Auditoria
(liberável por grupo) e **Grupos de Permissão**, que a wiki antiga marcava como
"admin" mas cujo handler só cita `require_admin()` em comentário — as escritas
passam por `require_permission('grupos-permissao', …)`, e `can()` é permissivo
para quem não tem grupo. O menu mostra Clientes e Usuários dentro de Cadastros
mesmo sendo `require_admin()`; a wiki segue o handler.

> ⚠️ **Achado no caminho, fora do escopo desta entrega:** por essa mesma razão,
> um usuário não-admin **sem grupo de permissão** consegue abrir
> `/grupos-permissao` e gravar (criar/editar/excluir grupos). A wiki passa a
> refletir o que o handler faz; se isso é intencional ou uma lacuna é decisão do
> dono do produto e vai como pendência no `STATUS.md`, não como correção aqui.

`can()` é permissivo por omissão (usuário sem grupo → sem restrição), então
usuário sem grupo vê tudo, exceto o que é `admin_only`. O card diz isso.

## 4. Componentes

### 4.1 Registro — `includes/wiki_registry.php`

Lista ordenada de seções. Campos:

- `id` — âncora; **preserva todos os ids atuais**.
- `title`, `group` (Visão geral, Vídeos, Relatórios, Cadastros, Operações…),
  `summary` (uma linha, usada no stub bloqueado), `order`.
- `screen` — chave da matriz de permissões (`relatorios`, `ativos`,
  `comandos-sms`…).
- `handler` — arquivo de `handlers/` que decide o acesso à tela (ex.:
  `firmwares.php`); é o que permite os testes conferirem `admin_only` contra o
  código real. Vazio para seções que não são uma tela (Visão Geral, "O que vale
  para todos os relatórios").
- `admin_only` — bool, derivado do `require_admin()` real.
- `dynamic` — bool; a seção não tem parcial, o corpo é gerado (só "Meu acesso").
- `actions` — as ações que a tela de fato exige em `require_permission()`
  (ex.: `['create','edit','delete','export']`), ou `[]` para tela só de leitura.
  A matriz de permissões expõe as mesmas 5 colunas para toda tela e **não**
  declara quais existem, por isso o registro declara.
- `extras` (opcional) — recursos restritos dentro de tela liberada, com a
  condição em uma linha (ex.: Ocorrências, "descarte em massa: só admin da
  plataforma").
- `hidden` (opcional) — seção sem item de índice (ver §7).

### 4.2 Resolvedor — `wiki_access($secao, $role, $can)`

Função pura: recebe a seção, o papel e um callable `can`; devolve estado
(`liberada`/`bloqueada`), motivo, ações permitidas e negadas. Testável sem
banco, como os demais `tests/helpers/*.test.php`.

### 4.3 Renderizador — `wiki_render($registro, $role, $can, $perfil)`

Devolve o HTML. `handlers/wiki.php` fica só com layout + chamada + `echo`.

- **Liberada:** título, faixa "Seu acesso" (✓ permitido / traço negado, só com
  as `actions` da seção; `extras` em segunda linha), depois o parcial.
- **Bloqueada:** stub com o mesmo `id`, cadeado, `summary` e o motivo. O
  conteúdo do parcial **não é incluído**.
- **Índice lateral** gerado do registro, mesma ordem; bloqueado esmaecido com
  cadeado e clicável. O script de rolagem atual permanece.
- **Rodapé:** versão lida de `SYSTEM_VERSION` no lugar da data manual.

### 4.4 Parciais — `includes/wiki/sections/<id>.php`

Um por seção, HTML idêntico ao de hoje na etapa 1. Cada um começa com
`defined('WIKI_SECTION') || exit;`: o `.htaccess` do repositório não nega
`includes/`, e a trava impede abrir o parcial por URL direta.

### 4.5 Card de abertura por perfil

No topo, antes de "Visão Geral":

- Perfil: `role='admin'` → Admin; senão `user_type='revendedor'` → Revendedor;
  senão Cliente/operador. Nome do grupo de `permission_group_id` (uma consulta;
  se falhar, o card mostra só o perfil).
- Contador: "Você tem acesso a N de M telas" (só telas do menu, sem as ocultas).
- Uma frase do dia a dia por perfil.
- "Comece por aqui": até 5 atalhos de uma lista ordenada por perfil, filtrada
  pelo acesso; o próximo da lista entra no lugar de um bloqueado.
- Link para a seção **"Meu acesso"**, que lista liberado × bloqueado com o
  motivo de cada um.

Listas de atalhos (rascunho — ver §7):
Admin: Clientes, Usuários, Grupos de Permissão, Equipamentos, Comandos ·
Revendedor: Resumo, Rastreamento, Dashboard de Ocorrências, Ativos, Relatórios ·
Cliente/operador: Rastreamento, Dashboard de Ocorrências, Vídeo Ao Vivo,
Alertas Videomonitoramento, Ativos.

## 5. Conteúdo

Método: cada seção é escrita a partir da leitura do handler real e das entradas
do `CHANGELOG.md` desde a v4.13.16, em linguagem de usuário (sem jargão, sem
caminhos de URL, sem infraestrutura — regra atual do arquivo). Onde não for
possível confirmar no handler ou em render local, a seção diz isso.

**Novas:** Manutenção · Comandos por SMS (com o aviso de consumo de crédito) ·
Auditoria (geral, negados, cadastro, login) · Configurações IA (admin) · SMS,
configuração da conta (admin) · Recuperar e trocar senha (em Primeiros Passos).

**Corrigir/ampliar:**
- Cadastros: fluxo chip → câmera → veículo, troca de câmera e histórico (v4.11).
  Provavelmente a maior correção: o texto atual trata a câmera como "o ativo".
- Rastreadores JM-VL: sem vídeo e sem alertas de IA.
- Deslocamento: distância pelo hodômetro.
- Desatualizados: critério de último sinal de comunicação.
- Ocorrência: velocidade e número do alarme na tratativa.
- Alertas Dirigibilidade × Videomonitoramento.
- Painel (versão com widgets) e visão do revendedor (ranking, troca de cliente).

## 6. Testes e verificação

PHP puro, sem banco:

- `tests/helpers/wiki_access.test.php` — resolvedor, renderizador, card; três
  perfis, grupo restrito, usuário sem grupo e sem `role`; **vazamento**: um
  marcador exclusivo de cada parcial bloqueado não pode aparecer no HTML do
  perfil bloqueado; atalhos nunca apontam para tela bloqueada.
- `tests/helpers/wiki_registry.test.php` — travas contra desatualização:
  1. toda chave de `$screens` (`grupos_permissao.php`) tem seção, ou consta
     numa lista de exceções com o motivo escrito;
  2. `admin_only` ↔ linha `require_admin();` no `handler` da seção, nos dois
     sentidos (comentário que cita `require_admin()` não conta);
  3. `actions` ↔ chamadas `require_permission('<tela>', …)` / `can('<tela>', …)`
     em `handlers/*.php`, comparadas **por tela** (a união das `actions` das
     seções da tela tem de ser igual ao que os handlers exigem, exceto `view`).
     Por tela e não por handler porque a permissão pode ser exigida num arquivo
     irmão (`ativos_novo.php` exige `create` de `ativos`);
  4. âncoras antigas preservadas;
  5. cada seção tem parcial e não há parcial órfão.
- `php -l` em tudo (espelha a fase VERIFY do `deploy.sh`).

Verificação de que a divisão não quebrou nada (etapa 1): renderizar `/wiki`
como admin antes e depois e comparar; só podem diferir os elementos novos
(faixa, card, índice gerado).

Navegador: MySQL local + três usuários descartáveis (admin, revendedor, cliente
com grupo restrito), removidos ao fim; um spec Playwright confere que todo link
do índice leva a uma âncora existente (pula sem `TEST_EMAIL`/`TEST_PASSWORD`).

## 7. Decisões a revisar

1. **Parâmetros e Perfis de Parâmetros** estão fora do menu desde a v4.13.10
   (a tela "ainda não está funcional"). Proposta: ficam no registro como
   `hidden` — sem item de índice — enquanto o menu os esconde.
2. **Listas de atalhos por perfil** (§4.5) são rascunho.
3. **`extras` restritos** conhecidos: descarte em massa em Ocorrências (só
   admin da plataforma). Outros são levantados ao escrever cada seção.

## 8. Entrega

Três etapas, cada uma com commit + push, CHANGELOG e STATUS:

1. Mecanismo, testes e rodapé por versão (minor) — conteúdo movido, não
   reescrito.
2. Conteúdo novo e correções (patch).
3. Cards por perfil e "Meu acesso" (minor).

Numeração exata no plano, seguindo o bump de `SYSTEM_VERSION` do projeto.
**Sem deploy:** produção e homolog só a pedido.

Regra de processo: a nota do `CLAUDE.md` "Tela nova entra em DOIS lugares" passa
a dizer **TRÊS** (`$screenByHandler`, `$screens` e o registro da wiki); o teste
1 do §6 é o que a faz valer.

## 9. Fora de escopo

Busca dentro da wiki, impressão/PDF, idiomas, card editável pelo admin,
personalização por cliente e texto diferente por grupo de permissão.

## 10. Riscos

- **Parcial usar variável PHP do `wiki.php`:** cada parcial recebe o que precisa;
  a comparação antes/depois pega o que faltar.
- **Afirmar comportamento não conferido:** coberto pelo método do §5.
- **Links externos para âncoras:** teste 4.
- **`includes/` sem negação no `.htaccess`:** trava `WIKI_SECTION` nos parciais.
