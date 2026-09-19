# Central de Ajuda por perfil — Plano de implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Central de Ajuda (`/wiki`) passa a mostrar a cada usuário só o manual do que ele opera (seções bloqueadas ficam visíveis com cadeado e motivo), ganha um card de abertura por perfil e cobre as funcionalidades que ficaram sem explicação desde a v4.13.16.

**Architecture:** O conteúdo sai de `handlers/wiki.php` e vira um parcial por seção em `includes/wiki/sections/`. Um registro (`includes/wiki_registry.php`) descreve cada seção (tela, `admin_only`, ações, resumo); um resolvedor puro (`includes/wiki_access.php`) decide liberada/bloqueada; um renderizador (`includes/wiki_render.php`) gera índice, faixa "Seu acesso", stubs e card. Testes em PHP puro travam o registro contra o código real (matriz de permissões, `require_admin()`, `require_permission()`).

**Tech Stack:** PHP 8.3 puro (sem build, sem composer), testes `tests/helpers/*.test.php` (executados com `php`), Playwright só para um spec de âncoras.

**Spec:** `docs/superpowers/specs/2026-09-18-wiki-por-perfil-design.md` (lido junto com este plano; o §3 foi corrigido em 19/09 — Grupos de Permissão **não** é admin-only).

## Global Constraints

- Comentários e textos de tela em **PT-BR**; PHPDoc com `@param`/`@returns`. Seguir Keep a Changelog em `CHANGELOG.md`.
- Sem build, sem dependência nova. Escapar toda saída dinâmica com `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- **Preservar todos os `id` de âncora atuais** (lista em Task 3) — link `/wiki#rel-posicoes` salvo por alguém tem de continuar valendo.
- Marca do produto: **`bycamera`**. Não renomear o badge `JIMI`, `jimicloud.com`, nomes de tabela, cookies.
- O texto da wiki é para o **usuário final**: sem jargão, sem caminhos de URL, sem integração/infra (regra do docblock atual).
- `admin_only` no registro vem da linha `require_admin();` do handler — **nunca** do badge antigo da wiki.
- Tela nova entra em **TRÊS** lugares: `$screenByHandler` (router), `$screens` (grupos_permissao) e o registro da wiki.
- Todo commit termina com `Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>`. Cada etapa = commit(s) + push (regra do projeto). **Sem deploy**: produção e homolog só a pedido.
- Versões: etapa 1 → **v4.22.0**, etapa 2 → **v4.22.1**, etapa 3 → **v4.23.0**. O bump segue o modelo do commit `acbfb09` (`git show acbfb09 --stat`): `.env.example` (`SYSTEM_VERSION`), `CHANGELOG.md`, `STATUS.md` (+ rotação para `docs/status-history/STATUS_ARCHIVE.md` quando passar de 3 entradas).
- Commit anterior de referência do arquivo antigo: `776ea00` (`git show 776ea00:handlers/wiki.php`) — é a base da comparação de paridade (Task 4).
- Nas tarefas de **conteúdo** (Etapa 2), o plano fixa registro, fontes a ler, fatos obrigatórios e critérios de aceite; o **texto** é escrito lendo o handler real. Afirmação que não puder ser confirmada no código ou em render local não entra: a seção diz "não confirmado" explicitamente.

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `includes/wiki_registry.php` (novo) | `wiki_groups()`, `wiki_sec()`, `wiki_registry()` — só dados |
| `includes/wiki_access.php` (novo) | `wiki_access()`, `wiki_compute_access()`, motivos; `wiki_profile()` na Etapa 3 |
| `includes/wiki_render.php` (novo) | `wiki_render_toc()`, `wiki_render_body()`, faixa, stub, heading; card na Etapa 3 |
| `includes/wiki/sections/<id>.php` (novos) | um parcial de HTML por seção, com trava `WIKI_SECTION` |
| `handlers/wiki.php` (modifica) | só layout + CSS + chamadas ao renderizador |
| `tests/helpers/wiki_access.test.php` (novo) | resolvedor, renderizador, vazamento, âncoras, card |
| `tests/helpers/wiki_registry.test.php` (novo) | travas contra o código real (matriz, admin, ações, parciais) |
| `tests/wiki.spec.js` (novo) | Playwright: todo link do índice leva a uma âncora |

Rodar os dois testes: `php tests/helpers/wiki_access.test.php && php tests/helpers/wiki_registry.test.php`.

---

# ETAPA 1 — Mecanismo (v4.22.0)

### Task 1: Registro e resolvedor de acesso

**Files:**
- Create: `includes/wiki_registry.php`, `includes/wiki_access.php`
- Test: `tests/helpers/wiki_access.test.php`

**Interfaces:**
- Produces: `wiki_groups(): array<string,string>`; `wiki_sec(string $id, string $title, array $o = []): array`; `wiki_registry(): array<int,array>`; `wiki_access(array $sec, string $role, callable $can): array{state:string,reason:?string,allowed:string[],denied:string[]}`; `wiki_compute_access(array $registry, string $role, callable $can): array<string,array>`; constantes `WIKI_MOTIVO_ADMIN`, `WIKI_MOTIVO_GRUPO`.
- Formato de uma seção: `id,title,level(2|3),group(?string),sub(bool),screen(?string),handler(?string),admin_only(bool),actions(string[]),extras(string[]),summary(string),hidden(bool),badge(?string)`.

- [ ] **Step 1: Escrever o teste (falha porque as funções não existem)**

Criar `tests/helpers/wiki_access.test.php`:

```php
<?php
/**
 * Central de Ajuda por perfil (v4.22.0) — resolvedor de acesso e registro, sem banco.
 *
 * Trava o que não aparece em erro nenhum se quebrar: a wiki continua
 * desenhando, só que mostrando ao usuário uma tela que ele não pode abrir (ou
 * escondendo uma que ele pode).
 *
 * Uso:
 *   php tests/helpers/wiki_access.test.php
 */

require_once __DIR__ . '/../../includes/wiki_registry.php';
require_once __DIR__ . '/../../includes/wiki_access.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-66s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

$tudo = fn($s, $a = 'view') => true;
$nada = fn($s, $a = 'view') => false;
$soCria = fn($s, $a = 'view') => in_array($a, ['view', 'create'], true);

echo "== resolvedor de acesso ==\n";
$sec = wiki_sec('x', 'X', ['screen' => 'ativos', 'actions' => ['create', 'delete']]);
checa('libera quando can() é verdadeiro', 'liberada', wiki_access($sec, 'cliente', $tudo)['state']);
checa('bloqueia quando o grupo nega o view', 'bloqueada', wiki_access($sec, 'cliente', $nada)['state']);
checa('motivo de grupo', WIKI_MOTIVO_GRUPO, wiki_access($sec, 'cliente', $nada)['reason']);
$r = wiki_access($sec, 'cliente', $soCria);
checa('ação permitida', ['create'], $r['allowed']);
checa('ação negada', ['delete'], $r['denied']);

$adm = wiki_sec('y', 'Y', ['screen' => 'firmwares', 'admin_only' => true]);
checa('admin_only bloqueia não-admin mesmo com can() verdadeiro', 'bloqueada', wiki_access($adm, 'cliente', $tudo)['state']);
checa('motivo de admin', WIKI_MOTIVO_ADMIN, wiki_access($adm, 'cliente', $tudo)['reason']);
checa('admin_only libera role admin', 'liberada', wiki_access($adm, 'admin', $tudo)['state']);
checa('admin com grupo que nega a tela continua bloqueado', 'bloqueada', wiki_access($adm, 'admin', $nada)['state']);
checa('role vazio não é admin', 'bloqueada', wiki_access($adm, '', $tudo)['state']);

$livre = wiki_sec('z', 'Z');
checa('seção sem tela é sempre liberada', 'liberada', wiki_access($livre, 'cliente', $nada)['state']);

echo "== sanidade do registro ==\n";
$reg = wiki_registry();
$ids = array_column($reg, 'id');
checa('ids únicos', count($ids), count(array_unique($ids)));
$grupos = wiki_groups();
$ruins = [];
foreach ($reg as $s) {
    if (!in_array($s['level'], [2, 3], true)) $ruins[] = $s['id'] . ':level';
    if ($s['group'] !== null && !isset($grupos[$s['group']])) $ruins[] = $s['id'] . ':group';
    if (($s['screen'] !== null || $s['admin_only']) && trim($s['summary']) === '') $ruins[] = $s['id'] . ':summary';
    if (array_diff($s['actions'], ['create', 'edit', 'delete', 'export'])) $ruins[] = $s['id'] . ':actions';
}
checa('toda entrada do registro é válida', [], $ruins);

echo "\n$total verificações, $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/wiki_access.test.php`
Expected: erro fatal `Failed opening required '.../includes/wiki_registry.php'`.

- [ ] **Step 3: Criar `includes/wiki_access.php`**

```php
<?php
/**
 * Central de Ajuda — resolvedor de acesso (v4.22.0).
 *
 * Responde "esta pessoa consegue abrir esta tela?" do mesmo jeito que o
 * HANDLER responde (require_admin() + can(view)), não como o menu mostra: o
 * menu lista Clientes e Usuários em Cadastros para quem tem `can()`, e o
 * handler devolve 403 para quem não é admin.
 *
 * Função pura: recebe o papel e um callable `can`, sem tocar em sessão nem
 * banco — é o que permite testar sem MySQL.
 */

const WIKI_MOTIVO_ADMIN = 'Restrita a administradores.';
const WIKI_MOTIVO_GRUPO = 'Seu grupo de permissão não libera esta tela. Peça ao administrador da sua conta.';

/**
 * @param array    $sec  Seção do registro (ver wiki_sec()).
 * @param string   $role Papel do usuário (`users.role`); vazio = não é admin.
 * @param callable $can  function (string $tela, string $acao): bool — normalmente `can`.
 * @returns array{state:string,reason:?string,allowed:string[],denied:string[]}
 */
function wiki_access(array $sec, string $role, callable $can): array {
    $bloqueada = fn(string $motivo) => [
        'state' => 'bloqueada', 'reason' => $motivo, 'allowed' => [], 'denied' => [],
    ];

    if (!empty($sec['admin_only']) && $role !== 'admin') {
        return $bloqueada(WIKI_MOTIVO_ADMIN);
    }
    $screen = $sec['screen'] ?? null;
    if ($screen !== null && !$can($screen, 'view')) {
        return $bloqueada(WIKI_MOTIVO_GRUPO);
    }

    $allowed = [];
    $denied  = [];
    foreach (($sec['actions'] ?? []) as $acao) {
        if ($screen === null || $can($screen, $acao)) $allowed[] = $acao;
        else $denied[] = $acao;
    }
    return ['state' => 'liberada', 'reason' => null, 'allowed' => $allowed, 'denied' => $denied];
}

/**
 * Resolve o acesso de todas as seções de uma vez (uma consulta por seção, no
 * máximo, e só ao get_user_permissions() que já é cacheado por request).
 *
 * @returns array<string,array> id da seção => resultado de wiki_access()
 */
function wiki_compute_access(array $registry, string $role, callable $can): array {
    $out = [];
    foreach ($registry as $sec) {
        $out[$sec['id']] = wiki_access($sec, $role, $can);
    }
    return $out;
}
```

- [ ] **Step 4: Criar `includes/wiki_registry.php`**

Os `summary` abaixo vêm das linhas "Objetivo" da wiki atual (extraídas em 19/09/2026). Os de seções que a Etapa 2 reescreve (Ativos, Chips, Equipamentos, Desatualizados) são revisados lá.

```php
<?php
/**
 * Central de Ajuda — registro de seções (v4.22.0).
 *
 * É DADO, não conteúdo: cada linha diz o que a seção é, de qual tela depende e
 * o que ela exige de quem lê. O texto vive em includes/wiki/sections/<id>.php.
 *
 * Campos (spec §4.1):
 *  id          âncora — preserva todos os ids anteriores à v4.22.0.
 *  title       título exibido (texto puro; o badge é gerado).
 *  level       2 = <h2>, 3 = <h3> dentro de um grupo.
 *  group       chave de wiki_groups() ou null.
 *  sub         recuo no índice lateral (h2 avulso que o índice sempre recuou).
 *  screen      chave da matriz de permissões (grupos_permissao.php) ou null.
 *  handler     arquivo de handlers/ que decide o acesso; null se a seção não é
 *              uma tela. É o que os testes usam para conferir `admin_only`.
 *  admin_only  espelha a linha `require_admin();` do handler. NUNCA o badge
 *              antigo da wiki: "Grupos de Permissão" e as três telas de
 *              configuração carregavam "admin" sem o handler exigir.
 *  actions     ações que a tela exige de fato (require_permission/can); a matriz
 *              tem as mesmas 5 colunas para toda tela, então ela não serve.
 *  extras      recursos restritos DENTRO de tela liberada.
 *  summary     linha do stub bloqueado (obrigatória se há tela ou admin_only).
 *  hidden      sem item de índice nem corpo (tela fora do menu).
 *  badge       texto de badge fixo (ex.: "tela inicial").
 *  dynamic     sem parcial: o corpo é gerado (só "Meu acesso", Etapa 3).
 *
 * 🔴 Tela nova entra em TRÊS lugares: $screenByHandler (handlers/router.php),
 * $screens (handlers/grupos_permissao.php) e AQUI.
 * tests/helpers/wiki_registry.test.php trava.
 */

/** Grupos que ganham um <h2> próprio e reúnem seções de nível 3. */
function wiki_groups(): array {
    return [
        'videos'     => 'Vídeos',
        'relatorios' => 'Relatórios',
        'cadastros'  => 'Cadastros',
        'operacoes'  => 'Operações',
    ];
}

/**
 * Monta uma entrada com os padrões aplicados.
 *
 * @param string $id    Âncora.
 * @param string $title Título.
 * @param array  $o     Campos que diferem do padrão.
 */
function wiki_sec(string $id, string $title, array $o = []): array {
    return $o + [
        'id' => $id, 'title' => $title,
        'level' => 3, 'group' => null, 'sub' => false,
        'screen' => null, 'handler' => null, 'admin_only' => false,
        'actions' => [], 'extras' => [], 'summary' => '',
        'hidden' => false, 'badge' => null, 'dynamic' => false,
    ];
}

/** @returns array<int,array> Seções na ordem em que aparecem na página. */
function wiki_registry(): array {
    // Relatórios: mesma tela ('relatorios'), cada um com seu handler.
    $rel = fn(string $id, string $title, string $handler, string $summary) => wiki_sec($id, $title, [
        'group' => 'relatorios', 'screen' => 'relatorios', 'handler' => $handler,
        'actions' => ['export'], 'summary' => $summary,
    ]);

    return [
        wiki_sec('intro', 'Visão Geral do Sistema', ['level' => 2]),
        wiki_sec('primeiros-passos', 'Primeiros Passos', ['level' => 2]),
        wiki_sec('resumo', 'Resumo', [
            'level' => 2, 'sub' => true, 'badge' => 'tela inicial',
            'screen' => 'resumo', 'handler' => 'resumo.php',
            'summary' => 'Visão executiva da frota: indicadores, mapa de calor e dispositivos desatualizados.',
        ]),
        wiki_sec('rastreamento', 'Rastreamento', [
            'level' => 2, 'sub' => true, 'screen' => 'rastreamento', 'handler' => 'rastreamento.php',
            'summary' => 'Mapa ao vivo com a última posição de todos os veículos da frota.',
        ]),
        wiki_sec('bi', 'BI — Business Intelligence', [
            'level' => 2, 'sub' => true, 'screen' => 'bi', 'handler' => 'bi.php', 'actions' => ['export'],
            'summary' => 'Análises sob demanda com filtros de cliente, ativos, motoristas, alarmes e período.',
        ]),
        wiki_sec('mapa-risco', 'Mapa de Risco', [
            'level' => 2, 'sub' => true, 'screen' => 'bi', 'handler' => 'mapa_risco.php', 'actions' => ['export'],
            'summary' => 'Onde, quando e com quem os comportamentos de risco (ADAS/DMS) acontecem.',
        ]),
        wiki_sec('ocorrencias-dashboard', 'Dashboard de Ocorrências', [
            'level' => 2, 'sub' => true, 'screen' => 'ocorrencias_dashboard', 'handler' => 'ocorrencias_dashboard.php',
            'summary' => 'Painel operacional das ocorrências de comportamento do motorista e sua tratativa.',
        ]),
        wiki_sec('notificacoes', 'Notificações', ['level' => 2]),

        // ── Vídeos ──
        wiki_sec('video-aovivo', 'Ao Vivo', [
            'group' => 'videos', 'screen' => 'video_aovivo', 'handler' => 'video_aovivo.php',
            'summary' => 'Assistir ao vivo às câmeras dos veículos.',
        ]),
        wiki_sec('video-playback', 'Playback', [
            'group' => 'videos', 'screen' => 'video_playback', 'handler' => 'video_playback.php',
            'summary' => 'Ver as gravações históricas do cartão de memória do equipamento.',
        ]),
        wiki_sec('video-downloads', 'Downloads', [
            'group' => 'videos', 'screen' => 'video_downloads', 'handler' => 'video_downloads.php',
            'summary' => 'Grade dos arquivos de mídia disponíveis para download.',
        ]),

        // ── Relatórios ──
        wiki_sec('rel-comum', 'O que vale para todos os relatórios', [
            'group' => 'relatorios', 'screen' => 'relatorios',
            'summary' => 'Comportamentos iguais em todas as telas de relatório.',
        ]),
        wiki_sec('rel-modelos', 'Modelos salvos', [
            'group' => 'relatorios', 'screen' => 'relatorios',
            'summary' => 'Guardar uma combinação de filtros e reaplicá-la em um clique.',
        ]),
        $rel('rel-posicoes', 'Posições', 'rel_posicoes.php', 'Histórico de posições de um ativo em um período, com trajeto no mapa.'),
        $rel('rel-deslocamento', 'Deslocamento', 'rel_deslocamento.php', 'Deslocamentos do veículo com duração, velocidade máxima e distância.'),
        $rel('rel-desatualizados', 'Desatualizados', 'rel_desatualizados.php', 'Equipamentos que estão há muito tempo sem se comunicar.'),
        $rel('rel-alarmes', 'Alertas Videomonitoramento', 'rel_alarmes.php', 'Histórico dos alarmes das câmeras (tudo que não é evento de condução).'),
        $rel('rel-dirigibilidade', 'Alertas Dirigibilidade', 'rel_dirigibilidade.php', 'Eventos de condução: frenagem, arrancada, curva, velocidade, colisão.'),
        wiki_sec('rel-ocorrencias', 'Ocorrências', [
            'group' => 'relatorios', 'screen' => 'relatorios', 'handler' => 'rel_ocorrencias.php',
            'actions' => ['export'], 'extras' => ['Descarte em massa: só administrador da plataforma'],
            'summary' => 'Histórico de ocorrências com filtros de tipo, situação, risco e motorista.',
        ]),
        $rel('rel-geocercas', 'Geocercas', 'rel_geocercas.php', 'Quando cada veículo entrou e saiu das cercas e quanto tempo ficou dentro.'),
        $rel('rel-status-frota', 'Status da Frota', 'rel_status_frota.php', 'Retrato de agora: veículos em movimento, ociosos, parados e sem comunicação.'),
        $rel('rel-paradas', 'Paradas', 'rel_paradas.php', 'Períodos com a ignição desligada, com início, fim, duração e local.'),
        $rel('rel-ociosidade', 'Ociosidade', 'rel_ociosidade.php', 'Períodos com o motor ligado e o veículo imóvel.'),
        $rel('rel-ignicao', 'Ignição', 'rel_ignicao.php', 'Cada vez que a ignição foi ligada ou desligada e por quanto tempo.'),
        $rel('rel-velocidade', 'Excesso de Velocidade', 'rel_velocidade.php', 'Trechos acima do limite configurado, com velocidade máxima e duração.'),
        wiki_sec('agendamentos', 'Agendamentos', [
            'group' => 'relatorios', 'screen' => 'agendamentos', 'handler' => 'agendamentos.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Receber um relatório por e-mail na frequência escolhida.',
        ]),

        // ── Cadastros ──
        wiki_sec('ativos', 'Ativos', [
            'group' => 'cadastros', 'screen' => 'ativos', 'handler' => 'ativos.php',
            'actions' => ['create', 'edit', 'delete', 'export'],
            'summary' => 'Cadastro dos veículos da frota.',
        ]),
        wiki_sec('chips', 'Chips SIM', [
            'group' => 'cadastros', 'screen' => 'chips', 'handler' => 'chips.php',
            'actions' => ['create', 'edit', 'delete', 'export'],
            'summary' => 'Chips SIM usados nos equipamentos: operadora, linha, ICCID e situação.',
        ]),
        wiki_sec('clientes', 'Clientes', [
            'group' => 'cadastros', 'screen' => 'clientes', 'handler' => 'clientes.php', 'admin_only' => true,
            'actions' => ['create', 'edit', 'delete', 'export'],
            'summary' => 'Cadastro dos clientes da plataforma; cada um tem a própria frota isolada.',
        ]),
        wiki_sec('equipamentos', 'Equipamentos', [
            'group' => 'cadastros', 'screen' => 'equipamentos', 'handler' => 'equipamentos.php',
            'actions' => ['create', 'edit', 'export'],
            'summary' => 'Cadastro dos equipamentos (câmeras e rastreadores) e do chip de cada um.',
        ]),
        wiki_sec('geocercas', 'Geocercas', [
            'group' => 'cadastros', 'screen' => 'geocercas', 'handler' => 'geocercas.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Desenhar áreas no mapa e ser avisado quando um veículo entra ou sai.',
        ]),
        wiki_sec('grupos-permissao', 'Grupos de Permissão', [
            'group' => 'cadastros', 'screen' => 'grupos-permissao', 'handler' => 'grupos_permissao.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Define quais telas e ações cada grupo de usuários pode usar.',
        ]),
        wiki_sec('motoristas', 'Motoristas', [
            'group' => 'cadastros', 'screen' => 'motoristas', 'handler' => 'motoristas.php',
            'actions' => ['create', 'edit', 'delete', 'export'],
            'summary' => 'Cadastro de motoristas com CNH e exame toxicológico.',
        ]),
        wiki_sec('config-ocorrencias', 'Configuração de Ocorrências', [
            'group' => 'cadastros', 'screen' => 'config-ocorrencias', 'handler' => 'config_ocorrencias.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Perfis de regras que transformam cada tipo de alarme em ocorrência.',
        ]),
        wiki_sec('config-notificacoes', 'Configuração de Notificações', [
            'group' => 'cadastros', 'screen' => 'config-notificacoes', 'handler' => 'config_notificacoes.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Define o que gera aviso, por qual canal e para quem.',
        ]),
        wiki_sec('config-smtp', 'Servidor de E-mail', [
            'group' => 'cadastros', 'screen' => 'config-smtp', 'handler' => 'config_smtp.php',
            'actions' => ['edit', 'delete'],
            'summary' => 'Credenciais do servidor que envia os e-mails do sistema.',
        ]),
        wiki_sec('usuarios', 'Usuários', [
            'group' => 'cadastros', 'screen' => 'usuarios', 'handler' => 'usuarios.php', 'admin_only' => true,
            'actions' => ['create', 'edit', 'export'],
            'summary' => 'Gestão dos usuários do sistema (da sua empresa e dos seus clientes).',
        ]),

        // ── Operações ──
        wiki_sec('comandos', 'Comandos', [
            'group' => 'operacoes', 'screen' => 'comandos', 'handler' => 'comandos.php',
            'summary' => 'Enviar comandos remotos aos equipamentos e acompanhar a resposta.',
        ]),
        // Fora do menu desde a v4.13.10 ("a tela ainda não está funcional"): a
        // wiki não a promove enquanto o menu a esconde. O parcial fica guardado.
        wiki_sec('parametros', 'Parâmetros', [
            'group' => 'operacoes', 'screen' => 'parametros', 'handler' => 'parametros.php',
            'admin_only' => true, 'hidden' => true,
            'summary' => 'Ler, comparar e alterar a configuração remota das câmeras JT/T.',
        ]),
        wiki_sec('firmwares', 'Firmware', [
            'group' => 'operacoes', 'screen' => 'firmwares', 'handler' => 'firmwares.php', 'admin_only' => true,
            'summary' => 'Ver a versão de firmware de cada câmera e atualizá-la à distância.',
        ]),
        wiki_sec('exportar', 'Exportar', [
            'group' => 'operacoes', 'screen' => 'exportar', 'handler' => 'exportar.php',
            'summary' => 'Fila de geração de relatórios grandes, com download ao concluir.',
        ]),
        wiki_sec('checklist', 'Checklist e Inspeção', [
            'group' => 'operacoes', 'screen' => 'checklist', 'handler' => 'checklist.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Checklists de inspeção veicular e seu preenchimento por veículo.',
        ]),
    ];
}
```

- [ ] **Step 5: Rodar e ver passar**

Run: `php tests/helpers/wiki_access.test.php`
Expected: todas `OK`, `N verificações, 0 falha(s)`, exit 0.

- [ ] **Step 6: Lint e commit**

```bash
php -l includes/wiki_registry.php && php -l includes/wiki_access.php && php -l tests/helpers/wiki_access.test.php
git add includes/wiki_registry.php includes/wiki_access.php tests/helpers/wiki_access.test.php
git commit -m "$(cat <<'EOF'
feat: registro e resolvedor de acesso da Central de Ajuda (v4.22.0, 1/5)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Dividir o conteúdo atual em parciais

**Files:**
- Create: `includes/wiki/sections/<id>.php` (um por seção **não oculta ou oculta**, exceto os 4 ids de grupo) — gerados por script
- Create: `$SCRATCH/wiki_split.py` (descartável, **não** vai para o repositório)
- Test: `tests/helpers/wiki_registry.test.php` (novo)

**Interfaces:**
- Consumes: `wiki_registry()` (Task 1).
- Produces: um arquivo `includes/wiki/sections/<id>.php` por `id` do registro, começando com `<?php defined('WIKI_SECTION') || exit; ?>`.

`$SCRATCH` = `/private/tmp/claude-501/-Users-flavio-Documents-Antigravity-jimi-webhook/ceb48a78-6990-4cf0-812e-7858ef1bedeb/scratchpad`.

- [ ] **Step 1: Escrever o teste dos parciais (falha: diretório não existe)**

Criar `tests/helpers/wiki_registry.test.php`:

```php
<?php
/**
 * Central de Ajuda (v4.22.0) — travas do registro contra o código real, sem banco.
 *
 * A wiki parou em 14/08/2026 porque nada a ligava ao resto do sistema. Aqui o
 * registro é conferido contra a matriz de permissões, os handlers e os
 * parciais: quem cadastrar uma tela e esquecer a wiki quebra este teste.
 *
 * Uso:
 *   php tests/helpers/wiki_registry.test.php
 */

require_once __DIR__ . '/../../includes/wiki_registry.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-66s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

$RAIZ = realpath(__DIR__ . '/../..');
$reg  = wiki_registry();

echo "== parciais ==\n";
$dir = $RAIZ . '/includes/wiki/sections';
$arquivos = is_dir($dir) ? array_map(fn($f) => basename($f, '.php'), glob($dir . '/*.php')) : [];
$esperados = array_column(array_filter($reg, fn($s) => empty($s['dynamic'])), 'id');
sort($arquivos);
sort($esperados);
checa('toda seção não dinâmica tem parcial', [], array_values(array_diff($esperados, $arquivos)));
checa('nenhum parcial órfão', [], array_values(array_diff($arquivos, $esperados)));
$semTrava = [];
foreach ($arquivos as $id) {
    $ini = file_get_contents("$dir/$id.php");
    if (strpos($ini, "<?php defined('WIKI_SECTION') || exit; ?>") !== 0) $semTrava[] = $id;
}
checa('todo parcial abre com a trava WIKI_SECTION', [], $semTrava);

echo "\n$total verificações, $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/wiki_registry.test.php`
Expected: FALHA em "toda seção não dinâmica tem parcial" (lista todos os ids).

- [ ] **Step 3: Criar o divisor em `$SCRATCH/wiki_split.py`**

```python
#!/usr/bin/env python3
"""Divide o wiki.php de 776ea00 em um parcial por seção. Uso: wiki_split.py <raiz-do-repo>"""
import pathlib, re, subprocess, sys

root = pathlib.Path(sys.argv[1])
src = subprocess.check_output(['git', '-C', str(root), 'show', '776ea00:handlers/wiki.php'], text=True)
L = src.split('\n')

GRUPOS = {'videos', 'relatorios', 'cadastros', 'operacoes'}
pat = re.compile(r'<h([23]) id="([^"]+)"')
ini = next(i for i, l in enumerate(L) if 'id="wikiContent"' in l)
fim = next(i for i, l in enumerate(L) if 'text-align:center;margin-top:48px' in l)

heads = [(i, pat.match(l).group(2)) for i, l in enumerate(L) if i > ini and i < fim and pat.match(l)]
vazia = re.compile(r'^\s*(<!--.*-->)?\s*$')

saida = root / 'includes/wiki/sections'
saida.mkdir(parents=True, exist_ok=True)
feitos = []
for n, (i, id_) in enumerate(heads):
    if id_ in GRUPOS:
        continue
    prox = heads[n + 1][0] if n + 1 < len(heads) else fim
    corpo = L[i + 1:prox]
    while corpo and vazia.match(corpo[0]):
        corpo.pop(0)
    while corpo and vazia.match(corpo[-1]):
        corpo.pop()
    texto = "<?php defined('WIKI_SECTION') || exit; ?>\n" + '\n'.join(corpo) + '\n'
    (saida / f'{id_}.php').write_text(texto, encoding='utf-8')
    feitos.append(id_)

print(len(feitos), 'parciais:', ' '.join(feitos))
```

- [ ] **Step 4: Rodar o divisor**

Run: `python3 "$SCRATCH/wiki_split.py" /Users/flavio/Documents/Antigravity/jimi_webhook`
Expected: `42 parciais: intro primeiros-passos resumo …` — a contagem tem de ser igual ao número de entradas do registro (`php -r "require 'includes/wiki_registry.php'; echo count(wiki_registry());"` → 42).

- [ ] **Step 5: Rodar o teste e ver passar**

Run: `php tests/helpers/wiki_registry.test.php`
Expected: 3 verificações `OK`, 0 falha(s).

- [ ] **Step 6: Conferir que o HTML dos parciais é o do arquivo antigo**

Run: `for f in includes/wiki/sections/*.php; do php -l "$f" >/dev/null || echo "ERRO: $f"; done; grep -c "" includes/wiki/sections/*.php | awk -F: '{s+=$2} END {print s " linhas em parciais (antigo: ~1560 de corpo)"}'`
Expected: nenhuma linha `ERRO`; soma próxima de 1560.

- [ ] **Step 7: Commit**

```bash
git add includes/wiki/sections tests/helpers/wiki_registry.test.php
git commit -m "$(cat <<'EOF'
refactor: conteudo da Central de Ajuda dividido em um parcial por secao (v4.22.0, 2/5)

Texto identico ao de 776ea00; so foi movido. A paridade com o arquivo antigo
e conferida na tarefa seguinte, junto do renderizador.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: Renderizador (índice, faixa, stub, corpo)

**Files:**
- Create: `includes/wiki_render.php`
- Modify: `tests/helpers/wiki_access.test.php` (acrescentar blocos C–F)

**Interfaces:**
- Consumes: `wiki_registry()`, `wiki_groups()`, `wiki_access()` result maps (Task 1).
- Produces: `wiki_esc(string): string`; `wiki_visible(array): array`; `wiki_lock_icon(): string`; `wiki_heading(array $sec, bool $locked): string`; `wiki_include_section(string $id): string`; `wiki_access_strip(array $sec, array $acc): string`; `wiki_stub(array $sec, array $acc): string`; `wiki_render_toc(array $registry, array $access): string`; `wiki_render_body(array $registry, array $access): string`.

- [ ] **Step 1: Acrescentar os testes ao `wiki_access.test.php`**

Adicionar `require_once __DIR__ . '/../../includes/wiki_render.php';` junto dos outros `require_once` no topo, e inserir **antes** do bloco final (`echo "\n$total verificações…`):

```php
function texto_normal(string $html): string {
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $html = preg_replace('/<\?php.*?\?>/s', '', $html);
    return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
}
function marcador(string $id): string {
    $f = __DIR__ . '/../../includes/wiki/sections/' . $id . '.php';
    return substr(texto_normal((string)file_get_contents($f)), 0, 80);
}

echo "== vazamento: parcial bloqueado não pode aparecer ==\n";
$accAdm  = wiki_compute_access($reg, 'admin', $tudo);
$accBloq = wiki_compute_access($reg, 'cliente', $nada);
$htmlAdm  = texto_normal(wiki_render_body($reg, $accAdm));
$htmlBloq = texto_normal(wiki_render_body($reg, $accBloq));
$marcadores = [];
foreach ($reg as $s) {
    if (!empty($s['dynamic'])) continue;
    $m = marcador($s['id']);
    $marcadores[$s['id']] = $m;
    if (!empty($s['hidden'])) {
        checa("oculta não é renderizada: {$s['id']}", false, strpos($htmlAdm, $m) !== false);
        continue;
    }
    checa("admin vê o conteúdo de {$s['id']}", true, strpos($htmlAdm, $m) !== false);
    $bloqueavel = $s['screen'] !== null || $s['admin_only'];
    checa("perfil sem acesso: {$s['id']} " . ($bloqueavel ? 'não vaza' : 'segue visível'),
        !$bloqueavel, strpos($htmlBloq, $m) !== false);
}
checa('marcadores únicos entre parciais', count($marcadores), count(array_unique($marcadores)));

echo "== índice ==\n";
$toc = wiki_render_toc($reg, $accBloq);
$semAncora = [];
foreach ($reg as $s) {
    if (!empty($s['hidden'])) continue;
    if (strpos($toc, 'href="#' . $s['id'] . '"') === false) $semAncora[] = $s['id'];
}
foreach (array_keys(wiki_groups()) as $g) {
    if (strpos($toc, 'href="#' . $g . '"') === false) $semAncora[] = $g;
}
checa('índice tem toda seção visível e todo grupo', [], $semAncora);
checa('oculta não aparece no índice', false, strpos($toc, 'href="#parametros"') !== false);
checa('bloqueada vira link esmaecido', true, strpos($toc, 'class="locked"') !== false);
checa('liberada não é esmaecida', false, strpos(wiki_render_toc($reg, $accAdm), 'class="locked"') !== false);

echo "== stub e faixa ==\n";
$htmlBloqCru = wiki_render_body($reg, $accBloq);
checa('stub traz o motivo do grupo', true, strpos($htmlBloqCru, WIKI_MOTIVO_GRUPO) !== false);
checa('stub traz o motivo de admin', true, strpos(wiki_render_body($reg, wiki_compute_access($reg, 'cliente', $tudo)), WIKI_MOTIVO_ADMIN) !== false);
checa('stub mantém a âncora da seção', true, strpos($htmlBloqCru, 'id="rel-posicoes"') !== false);
$semExport = fn($s, $a = 'view') => $a !== 'export';
$htmlFaixa = wiki_render_body($reg, wiki_compute_access($reg, 'admin', $semExport));
checa('faixa lista o que falta', true, strpos($htmlFaixa, 'Não disponível para você: exportar') !== false);
checa('faixa mostra o recurso restrito', true, strpos($htmlFaixa, 'Descarte em massa') !== false);
checa('seção sem ações não ganha faixa', false, strpos(wiki_render_body([wiki_sec('intro', 'Visão Geral do Sistema', ['level' => 2])], ['intro' => wiki_access(wiki_sec('intro', 'x'), 'admin', $tudo)]), 'wiki-access') !== false);

echo "== âncoras antigas ==\n";
$antigas = ['intro','primeiros-passos','resumo','rastreamento','bi','mapa-risco','ocorrencias-dashboard','notificacoes',
    'videos','video-aovivo','video-playback','video-downloads','relatorios','rel-comum','rel-modelos','rel-posicoes',
    'rel-deslocamento','rel-desatualizados','rel-alarmes','rel-dirigibilidade','rel-ocorrencias','rel-geocercas',
    'rel-status-frota','rel-paradas','rel-ociosidade','rel-ignicao','rel-velocidade','agendamentos','cadastros',
    'ativos','chips','clientes','equipamentos','geocercas','grupos-permissao','motoristas','config-ocorrencias',
    'config-notificacoes','config-smtp','usuarios','operacoes','comandos','parametros','firmwares','exportar','checklist'];
checa('nenhuma âncora anterior à v4.22.0 sumiu', [], array_values(array_diff($antigas, array_merge($ids, array_keys(wiki_groups())))));
```

- [ ] **Step 2: Rodar e ver falhar**

Run: `php tests/helpers/wiki_access.test.php`
Expected: erro fatal `Failed opening required '.../includes/wiki_render.php'`.

- [ ] **Step 3: Criar `includes/wiki_render.php`**

```php
<?php
/**
 * Central de Ajuda — renderizador (v4.22.0).
 *
 * Recebe o registro e o mapa de acesso (wiki_compute_access) e devolve HTML.
 * Seção bloqueada NUNCA inclui o parcial: o conteúdo dela não chega ao
 * navegador de quem não pode abrir a tela.
 */

require_once __DIR__ . '/wiki_registry.php';

function wiki_esc(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Cadeado inline (herda a cor do texto). */
function wiki_lock_icon(): string {
    return '<svg class="wiki-lock-ico" width="12" height="12" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" '
        . 'aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/>'
        . '<path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
}

/** @returns array Seções sem as ocultas (sem índice e sem corpo). */
function wiki_visible(array $registry): array {
    return array_values(array_filter($registry, fn($s) => empty($s['hidden'])));
}

/** Rótulos das ações na faixa "Seu acesso". */
function wiki_acao_rotulo(string $acao): string {
    return ['create' => 'criar', 'edit' => 'editar', 'delete' => 'excluir', 'export' => 'exportar'][$acao] ?? $acao;
}

/**
 * @param array $sec    Seção do registro.
 * @param bool  $locked Acrescenta o selo "bloqueada".
 */
function wiki_heading(array $sec, bool $locked): string {
    $badge = '';
    if (!empty($sec['badge'])) {
        $badge .= ' <span class="badge">' . wiki_esc($sec['badge']) . '</span>';
    }
    if (!empty($sec['admin_only'])) {
        $badge .= ' <span class="badge" style="background:#fce4eb;color:#c83532">admin</span>';
    }
    if ($locked) {
        $badge .= ' <span class="wiki-lock">' . wiki_lock_icon() . 'bloqueada</span>';
    }
    return sprintf('<h%d id="%s">%s%s</h%d>', $sec['level'], wiki_esc($sec['id']), wiki_esc($sec['title']), $badge, $sec['level']);
}

/**
 * Inclui o parcial da seção capturando a saída. A constante WIKI_SECTION é a
 * trava dos parciais: o .htaccess não nega includes/, e sem ela um parcial
 * abriria por URL direta.
 */
function wiki_include_section(string $id): string {
    $arquivo = __DIR__ . '/wiki/sections/' . $id . '.php';
    if (!is_file($arquivo)) return '';
    if (!defined('WIKI_SECTION')) define('WIKI_SECTION', true);
    ob_start();
    include $arquivo;
    return (string)ob_get_clean();
}

/**
 * Faixa "Seu acesso" — só para seção com ações ou recursos restritos.
 *
 * @param array $sec Seção do registro.
 * @param array $acc Resultado de wiki_access() (liberada).
 */
function wiki_access_strip(array $sec, array $acc): string {
    if (empty($sec['actions']) && empty($sec['extras'])) return '';
    $ok = array_merge(['ver'], array_map('wiki_acao_rotulo', $acc['allowed']));
    $html = '<div class="wiki-access"><span class="wiki-access-label">Seu acesso</span>'
        . '<span class="wiki-access-ok">Você pode: ' . implode(' · ', $ok) . '</span>';
    if ($acc['denied']) {
        $html .= '<span class="wiki-access-no">Não disponível para você: '
            . implode(', ', array_map('wiki_acao_rotulo', $acc['denied'])) . '</span>';
    }
    foreach ($sec['extras'] as $extra) {
        $html .= '<span class="wiki-access-extra">' . wiki_esc($extra) . '</span>';
    }
    return $html . '</div>';
}

/** Stub de seção bloqueada: o que a tela é e por que não está liberada. */
function wiki_stub(array $sec, array $acc): string {
    return '<div class="wiki-locked"><p>' . wiki_esc($sec['summary']) . '</p>'
        . '<p class="wiki-locked-why">' . wiki_lock_icon() . ' ' . wiki_esc((string)$acc['reason']) . '</p></div>';
}

/**
 * Índice lateral. Bloqueada fica esmaecida e continua clicável (leva ao stub).
 * Item de grupo e h2 avulso com `sub` são recuados, como o índice sempre foi.
 */
function wiki_render_toc(array $registry, array $access): string {
    $grupos = wiki_groups();
    $out = '';
    $ultimo = null;
    foreach (wiki_visible($registry) as $sec) {
        if ($sec['group'] !== null && $sec['group'] !== $ultimo) {
            $out .= '<a href="#' . wiki_esc($sec['group']) . '">' . wiki_esc($grupos[$sec['group']]) . "</a>\n";
        }
        $ultimo = $sec['group'];
        $bloqueada = $access[$sec['id']]['state'] === 'bloqueada';
        $recuo = ($sec['group'] !== null || !empty($sec['sub']))
            ? ' style="padding-left:20px;font-size:12px"' : '';
        $out .= '<a href="#' . wiki_esc($sec['id']) . '"' . ($bloqueada ? ' class="locked"' : '') . $recuo . '>'
            . wiki_esc($sec['title']) . ($bloqueada ? wiki_lock_icon() : '') . "</a>\n";
    }
    return $out;
}

/** Corpo da wiki: heading de grupo quando entra num grupo, depois cada seção. */
function wiki_render_body(array $registry, array $access): string {
    $grupos = wiki_groups();
    $out = '';
    $ultimo = null;
    foreach (wiki_visible($registry) as $sec) {
        if ($sec['group'] !== null && $sec['group'] !== $ultimo) {
            $out .= '<h2 id="' . wiki_esc($sec['group']) . '">' . wiki_esc($grupos[$sec['group']]) . "</h2>\n";
        }
        $ultimo = $sec['group'];
        $acc = $access[$sec['id']];
        $bloqueada = $acc['state'] === 'bloqueada';
        $out .= wiki_heading($sec, $bloqueada) . "\n";
        $out .= $bloqueada
            ? wiki_stub($sec, $acc)
            : wiki_access_strip($sec, $acc) . wiki_include_section($sec['id']);
        $out .= "\n";
    }
    return $out;
}
```

- [ ] **Step 4: Rodar e ver passar**

Run: `php tests/helpers/wiki_access.test.php`
Expected: 0 falha(s). Se "marcadores únicos" falhar, dois parciais começam com o mesmo texto: aumentar `substr(..., 0, 80)` para 160 no helper `marcador()` — não relaxar a asserção.

- [ ] **Step 5: Lint e commit**

```bash
php -l includes/wiki_render.php
git add includes/wiki_render.php tests/helpers/wiki_access.test.php
git commit -m "$(cat <<'EOF'
feat: renderizador da Central de Ajuda com stub bloqueado e faixa Seu acesso (v4.22.0, 3/5)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Ligar o `wiki.php` ao mecanismo e provar a paridade

**Files:**
- Modify: `handlers/wiki.php` (script de montagem em `$SCRATCH`, mais dois `Edit` no docblock)
- Create: `$SCRATCH/wiki_assemble.py`, `$SCRATCH/wiki_parity.php` (descartáveis)

**Interfaces:**
- Consumes: tudo das Tasks 1–3.
- Produces: `/wiki` renderizada pelo registro; rodapé com `SYSTEM_VERSION`.

- [ ] **Step 1: Criar `$SCRATCH/wiki_parity.php`** (compara o corpo do arquivo antigo com o que o renderizador gera para o admin; roda só contra os includes, antes de o `wiki.php` ser reescrito)

```php
<?php
// Uso: php wiki_parity.php <raiz> <wiki-antigo.php>
[, $raiz, $antigo] = $argv;
require_once "$raiz/includes/wiki_render.php";
require_once "$raiz/includes/wiki_access.php";

$old = file_get_contents($antigo);
$a = strpos($old, '<div class="wiki-content" id="wikiContent">') + strlen('<div class="wiki-content" id="wikiContent">');
$b = strpos($old, '<p style="text-align:center;margin-top:48px');
ob_start(); eval('?>' . substr($old, $a, $b - $a)); $oldHtml = ob_get_clean();

// Paridade compara TUDO, inclusive a seção oculta (Parâmetros).
$reg = array_map(fn($s) => ['hidden' => false] + $s, wiki_registry());
$newHtml = wiki_render_body($reg, wiki_compute_access($reg, 'admin', fn() => true));

function partes(string $h): array {
    $h = preg_replace('/<!--.*?-->/s', '', $h);
    $h = preg_replace('#<div class="wiki-access">.*?</div>#s', '', $h);
    $h = trim(str_replace('> <', '><', preg_replace('/\s+/', ' ', $h)));
    $out = [];
    foreach (preg_split('/(?=<h[23] id=")/', $h, -1, PREG_SPLIT_NO_EMPTY) as $p) {
        if (preg_match('/^<h[23] id="([^"]+)"/', $p, $m)) $out[$m[1]] = $p; else $out['_antes'] = $p;
    }
    return $out;
}
$o = partes($oldHtml); $n = partes($newHtml);
$badge = ' <span class="badge" style="background:#fce4eb;color:#c83532">admin</span>';
$esperado = ['grupos-permissao', 'config-ocorrencias', 'config-notificacoes', 'config-smtp']; // perderam o "admin" indevido (o handler não exige)
$dif = [];
foreach (array_unique(array_merge(array_keys($o), array_keys($n))) as $id) {
    if (($o[$id] ?? null) === ($n[$id] ?? null)) continue;
    $semBadge = isset($o[$id]) ? str_replace($badge, '', $o[$id]) : null;
    $dif[$id] = ($semBadge === ($n[$id] ?? null)) ? 'só o badge admin indevido' : 'DIFERENTE';
}
foreach ($dif as $id => $m) echo "$id: $m\n";
$ruins = array_diff(array_keys($dif), $esperado);
echo (count($o) . ' partes antigas, ' . count($n) . " novas\n");
echo $ruins ? 'FALHOU: ' . implode(', ', $ruins) . "\n" : "PARIDADE OK\n";
exit($ruins ? 1 : 0);
```

- [ ] **Step 2: Rodar a paridade contra o arquivo antigo**

```bash
git show 776ea00:handlers/wiki.php > "$SCRATCH/wiki_antigo.php"
php "$SCRATCH/wiki_parity.php" "$PWD" "$SCRATCH/wiki_antigo.php"
```
Expected:
```
grupos-permissao: só o badge admin indevido
config-ocorrencias: só o badge admin indevido
config-notificacoes: só o badge admin indevido
config-smtp: só o badge admin indevido
46 partes antigas, 46 novas
PARIDADE OK
```
(Se aparecer uma parte `_antes`, há texto solto antes do primeiro título: investigar.) Qualquer `DIFERENTE` = bug da divisão (Task 2) ou de título/badge no registro: corrigir a origem e rodar de novo. Não mexer no script para "passar".

- [ ] **Step 3: Criar `$SCRATCH/wiki_assemble.py`**

```python
#!/usr/bin/env python3
"""Reescreve handlers/wiki.php: mantém docblock/CSS/script; o corpo vira chamadas ao renderizador."""
import pathlib, sys

root = pathlib.Path(sys.argv[1])
p = root / 'handlers/wiki.php'
src = p.read_text(encoding='utf-8')

head = src[:src.index('<div class="wiki-wrap">')]
tail = src[src.index('<script>\n// ── Scroll spy'):]

assert head.count("require_login();\n") == 1
assert head.count("</style>\nHEAD;") == 1
head = head.replace("require_login();\n",
    "require_once __DIR__ . '/../includes/wiki_registry.php';\n"
    "require_once __DIR__ . '/../includes/wiki_access.php';\n"
    "require_once __DIR__ . '/../includes/wiki_render.php';\n"
    "require_login();\n\n"
    "$user     = get_jimi_user() ?: [];\n"
    "$registry = wiki_registry();\n"
    "$access   = wiki_compute_access($registry, (string)($user['role'] ?? ''), 'can');\n", 1)

css = """
/* ── Acesso por perfil (v4.22.0) ─────────────────── */
.wiki-toc a.locked { color: var(--muted-soft); }
.wiki-toc a.locked:hover, .wiki-toc a.locked.active { color: var(--muted); background: var(--canvas-soft); }
.wiki-toc a .wiki-lock-ico { margin-left: 4px; vertical-align: -1px; }
.wiki-lock-ico { vertical-align: -1px; margin-right: 4px; }
.wiki-lock {
    display: inline-flex; align-items: center; margin-left: 8px; padding: 2px 8px;
    border-radius: 100px; background: var(--surface-strong); color: var(--muted);
    font-size: 11px; font-weight: 600; vertical-align: middle;
}
.wiki-locked {
    border: 1px dashed var(--hairline-strong); border-radius: 12px;
    background: var(--canvas-soft); padding: 14px 16px;
}
.wiki-locked p { margin: 0 0 6px; color: var(--body); }
.wiki-locked .wiki-locked-why { margin: 0; font-size: 13px; color: var(--muted); }
.wiki-access {
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px;
    margin: 0 0 14px; padding: 8px 12px; font-size: 12px; color: var(--body);
    border: 1px solid var(--hairline-soft); border-radius: 8px; background: var(--canvas-soft);
}
.wiki-access-label { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--ink); }
.wiki-access-no { color: var(--muted); }
.wiki-access-extra { color: var(--warning-text-strong); }
"""
head = head.replace("</style>\nHEAD;", css + "</style>\nHEAD;", 1)

body = """<div class="wiki-wrap">
    <!-- ── TOC Sidebar (gerado do registro) ─────────── -->
    <nav class="wiki-toc" id="wikiToc">
        <h4>Central de Ajuda</h4>
<?= wiki_render_toc($registry, $access) ?>
    </nav>

    <!-- ── Content ──────────────────────────────────── -->
    <div class="wiki-content" id="wikiContent">
<?= wiki_render_body($registry, $access) ?>

<p style="text-align:center;margin-top:48px;font-size:12px;color:var(--muted);padding-bottom:40px">
bycamera — Central de Ajuda — versão <?= htmlspecialchars(getenv('SYSTEM_VERSION') ?: '4.0', ENT_QUOTES, 'UTF-8') ?>
</p>

    </div><!-- /.wiki-content -->
</div><!-- /.wiki-wrap -->

"""
p.write_text(head + body + tail, encoding='utf-8')
print('wiki.php reescrito:', len((head + body + tail).split('\n')), 'linhas')
```

- [ ] **Step 4: Montar e lintar**

```bash
python3 "$SCRATCH/wiki_assemble.py" "$PWD" && php -l handlers/wiki.php
```
Expected: `wiki.php reescrito: ~470 linhas` e `No syntax errors detected`.

- [ ] **Step 5: Atualizar o docblock de `handlers/wiki.php`**

`Edit` 1 — `old_string`: ` * JIMI Webhook System — Wiki / Central de Ajuda v4.13.16` → `new_string`: ` * JIMI Webhook System — Wiki / Central de Ajuda v4.22.0`.

`Edit` 2 — `old_string`: ` *   lado, tocando ao mesmo tempo, quando o equipamento tem duas lentes.` → `new_string` (a mesma linha seguida de):

```
 *
 * Atualizada na v4.22.0 — a wiki passa a ser SENSÍVEL AO PERFIL:
 * - O conteúdo saiu daqui: cada seção é um parcial em includes/wiki/sections/,
 *   descrita por includes/wiki_registry.php (tela, admin_only, ações, resumo).
 * - Seção que o usuário não pode abrir continua no índice, com cadeado e o
 *   motivo, mas o texto dela NÃO é enviado ao navegador. Seção liberada ganha
 *   a faixa "Seu acesso" (o que ele pode e o que não pode fazer nela).
 * - O rodapé mostra a versão do sistema em vez de uma data escrita à mão.
 * - Tela nova entra em TRÊS lugares: $screenByHandler, $screens e o registro
 *   (tests/helpers/wiki_registry.test.php trava).
```

- [ ] **Step 6: Rodar tudo**

```bash
php tests/helpers/wiki_access.test.php && php tests/helpers/wiki_registry.test.php
find handlers config core includes -name "*.php" -type f -exec php -l {} \; | grep -v "No syntax errors" || echo "lint OK"
```
Expected: 0 falhas nos dois testes; `lint OK`.

- [ ] **Step 7: Verificação em navegador (quando houver ambiente)**

Se houver MySQL local e servidor PHP (`php -S`) funcionando para o projeto: criar 3 usuários descartáveis (admin; `user_type='revendedor'` sem admin; cliente com grupo restrito que só libera `rastreamento`), abrir `/wiki` com cada um e confirmar: admin vê tudo; cliente restrito vê stubs com cadeado nas demais telas, sem passo-a-passo; Firmware/SMS/Clientes/Usuários bloqueados para não-admin. **Remover os usuários e o grupo ao final.** Sem ambiente local: registrar em `STATUS.md` "não verificado em navegador — conferir em homolog após o deploy" e **não** afirmar que foi verificado.

- [ ] **Step 8: Commit**

```bash
git add handlers/wiki.php
git commit -m "$(cat <<'EOF'
feat: /wiki renderizada pelo registro, com secoes bloqueadas e faixa Seu acesso (v4.22.0, 4/5)

Paridade com 776ea00 conferida para o admin: unica diferenca e o badge admin
que Grupos de Permissao, Config. Ocorrencias, Config. Notificacoes e Servidor
de E-mail levavam sem o handler exigir.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: Travas contra desatualização, regra de processo e release v4.22.0

**Files:**
- Modify: `tests/helpers/wiki_registry.test.php`, `CLAUDE.md`, `AGENTS.md` (se citar a regra), `CHANGELOG.md`, `STATUS.md`, `.env.example`
- Create: `tests/wiki.spec.js`

**Interfaces:**
- Consumes: `wiki_registry()` (`screen`, `handler`, `admin_only`, `actions`, `hidden`).

- [ ] **Step 1: Acrescentar os blocos H–J ao `wiki_registry.test.php`** (antes do `echo "\n$total…`)

```php
/** Corpo de cada chamada `fn( ... )` em $src, respeitando parênteses aninhados. */
function chamadas(string $src, string $fn): array {
    $out = [];
    $off = 0;
    while (preg_match('/\b' . $fn . '\(/', $src, $m, PREG_OFFSET_CAPTURE, $off)) {
        $ini  = $m[0][1] + strlen($m[0][0]);
        $prof = 1;
        $i    = $ini;
        $n    = strlen($src);
        while ($i < $n && $prof > 0) {
            if ($src[$i] === '(') $prof++;
            elseif ($src[$i] === ')') $prof--;
            $i++;
        }
        $out[] = substr($src, $ini, $i - $ini - 1);
        $off = $i;
    }
    return $out;
}

/** @returns array<string,string[]> tela => ações (sem `view`) exigidas em handlers/*.php */
function acoes_exigidas(string $raiz): array {
    $map = [];
    foreach (glob($raiz . '/handlers/*.php') as $arq) {
        $src = (string)file_get_contents($arq);
        foreach (['require_permission', 'can'] as $fn) {
            foreach (chamadas($src, $fn) as $arg) {
                if (!preg_match("/^\s*'([a-z_\-]+)'\s*(?:,(.*))?$/s", $arg, $m)) continue;
                preg_match_all("/'(create|edit|delete|export)'/", $m[2] ?? '', $a);
                foreach ($a[1] as $acao) $map[$m[1]][$acao] = true;
            }
        }
    }
    return array_map(fn($v) => array_keys($v), $map);
}

function tem_require_admin(string $raiz, string $handler): bool {
    return (bool)preg_match('/^require_admin\(\);/m', (string)@file_get_contents("$raiz/handlers/$handler"));
}

echo "== toda tela da matriz tem seção na wiki ==\n";
$matriz = (string)file_get_contents($RAIZ . '/handlers/grupos_permissao.php');
preg_match('/\$screens\s*=\s*\[(.*?)\n\];/s', $matriz, $blk);
preg_match_all("/^\s*'([a-z_\-]+)'\s*=>/m", $blk[1] ?? '', $ks);
$telas = $ks[1];
checa('a matriz foi lida (sanidade)', true, count($telas) > 25);

// Tela sem seção PRECISA constar aqui com o motivo escrito. Remover a entrada
// quando a seção nascer — o teste reclama de exceção obsoleta.
$EXCECOES = [
    'wiki'                => 'é a própria Central de Ajuda',
    'config-dispositivos' => 'aberta pela ficha do veículo (aba Configurações); documentada dentro de Ativos',
    'config-parametros'   => 'fora do menu desde a v4.13.10, junto com Parâmetros',
    // Etapa 2 (v4.22.1) — remover cada linha ao criar a seção:
    'manutencoes'         => 'PENDENTE etapa 2',
    'comandos-sms'        => 'PENDENTE etapa 2',
    'painel'              => 'PENDENTE etapa 2',
    'config-sms'          => 'PENDENTE etapa 2',
    'configuracoes-ia'    => 'PENDENTE etapa 2',
    'auditoria'           => 'PENDENTE etapa 2',
];
$cobertas = array_unique(array_filter(array_column($reg, 'screen')));
checa('toda tela da matriz tem seção (ou exceção escrita)', [],
    array_values(array_diff($telas, $cobertas, array_keys($EXCECOES))));
checa('nenhuma exceção obsoleta (a tela já tem seção)', [], array_values(array_intersect(array_keys($EXCECOES), $cobertas)));
checa('toda seção aponta para uma tela que existe na matriz', [], array_values(array_diff($cobertas, $telas)));

echo "== admin_only bate com o handler ==\n";
$divergem = [];
foreach ($reg as $s) {
    if ($s['handler'] === null) continue;
    if ($s['admin_only'] !== tem_require_admin($RAIZ, $s['handler'])) $divergem[] = $s['id'];
}
checa('admin_only == linha require_admin(); do handler', [], $divergem);

echo "== ações batem com o que os handlers exigem ==\n";
$exigidas = acoes_exigidas($RAIZ);
$divergem = [];
foreach (array_unique(array_filter(array_column($reg, 'screen'))) as $tela) {
    $declaradas = [];
    foreach ($reg as $s) if ($s['screen'] === $tela) $declaradas = array_merge($declaradas, $s['actions']);
    $declaradas = array_values(array_unique($declaradas));
    $reais = $exigidas[$tela] ?? [];
    sort($declaradas);
    sort($reais);
    if ($declaradas !== $reais) $divergem[$tela] = ['registro' => $declaradas, 'handlers' => $reais];
}
checa('actions do registro == ações exigidas por tela', [], $divergem);

echo "== a seção oculta é só a que o menu esconde ==\n";
checa('únicas seções ocultas', ['parametros'], array_column(array_filter($reg, fn($s) => $s['hidden']), 'id'));
```

- [ ] **Step 2: Rodar**

Run: `php tests/helpers/wiki_registry.test.php`
Expected: 0 falhas. Se `actions do registro == ações exigidas por tela` falhar, o teste mostra `registro` × `handlers` da tela: **ler o handler**, corrigir o registro (não o teste) e, se a tela realmente não exige a ação, retirar a ação do registro. Se `admin_only` divergir, corrigir o registro pela linha `require_admin();` do handler.

- [ ] **Step 3: Spec Playwright das âncoras**

Abrir `tests/navigation.spec.js` e reproduzir exatamente como ele obtém a página autenticada (mesma fixture `./fixtures/auth`). Criar `tests/wiki.spec.js`:

```js
// @ts-check
/**
 * Central de Ajuda (v4.22.0): todo link do índice lateral leva a uma âncora
 * que existe na página — cobre seção liberada e stub bloqueado.
 */
const { test, expect, hasCreds } = require('./fixtures/auth');

test.skip(!hasCreds(), 'defina TEST_EMAIL e TEST_PASSWORD');

test('wiki: todo link do índice tem âncora correspondente', async ({ page }) => {
    await page.goto('/wiki');
    const hrefs = await page.$$eval('#wikiToc a', (as) => as.map((a) => a.getAttribute('href') || ''));
    expect(hrefs.length).toBeGreaterThan(10);
    for (const h of hrefs) {
        expect(await page.locator(h).count(), `âncora ${h}`).toBe(1);
    }
});
```
(Se `navigation.spec.js` usar outra forma de receber `page`, seguir a dele.)

- [ ] **Step 4: Regra de processo no `CLAUDE.md` (e `AGENTS.md`, se tiver o mesmo texto)**

`Edit` em `CLAUDE.md`, linha que começa com `- **Tela nova entra em DOIS lugares, sempre**:` → trocar por `- **Tela nova entra em TRÊS lugares, sempre**:` e acrescentar ao fim do bullet: ` A terceira é o registro da Central de Ajuda (`includes/wiki_registry.php`, v4.22.0): tela sem seção na wiki é tela que o usuário não sabe usar, e `tests/helpers/wiki_registry.test.php` falha se a chave existir em `$screens` sem seção nem exceção escrita.` Rodar `grep -n "DOIS lugares" CLAUDE.md AGENTS.md` e ajustar o que sobrar.

- [ ] **Step 5: Release v4.22.0**

Seguir o modelo de `git show acbfb09 --stat`: `SYSTEM_VERSION=4.22.0` em `.env.example`; entrada em `CHANGELOG.md` (Adicionado: registro + parciais + faixa "Seu acesso" + índice gerado + rodapé por versão + testes; Alterado: badge "admin" agora vem do handler — Grupos de Permissão, Config. Ocorrências/Notificações e Servidor de E-mail deixam de exibir o badge indevido; Parâmetros fica oculto); entrada em `STATUS.md` (topo, mantendo as 3 mais recentes inline e rotacionando a mais antiga para `docs/status-history/STATUS_ARCHIVE.md`) incluindo a **pendência**: *"`/grupos-permissao` não exige admin — não-admin sem grupo consegue criar/editar/excluir grupos (achado 19/09/2026; decisão do dono do produto)"* e o estado da verificação em navegador (Task 4, Step 7).

- [ ] **Step 6: Rodar tudo e commitar**

```bash
php tests/helpers/wiki_access.test.php && php tests/helpers/wiki_registry.test.php
find handlers config core includes -name "*.php" -type f -exec php -l {} \; | grep -v "No syntax errors" || echo "lint OK"
git add -A tests includes handlers CLAUDE.md AGENTS.md CHANGELOG.md STATUS.md .env.example docs/status-history
git status --short   # conferir que só entrou o esperado
git commit -m "$(cat <<'EOF'
feat: Central de Ajuda sensivel ao perfil e travada contra desatualizacao (v4.22.0)

Registro + parciais + resolvedor de acesso; tela nova agora entra em TRES
lugares. Achado: /grupos-permissao nao exige admin (registrado em STATUS).

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
git push origin main
```

---

# ETAPA 2 — Conteúdo novo e correções (v4.22.1)

**Modelo de cada parcial:** copiar a estrutura de uma seção irmã já existente (ex.: `includes/wiki/sections/motoristas.php` para um cadastro, `includes/wiki/sections/firmwares.php` para uma tela operacional): `<p><strong>Objetivo:</strong> …</p>`, mockup da tela com as classes já usadas (`mockup-header`, `mockup-body`, `kpi-row`, `callout info|warn`…), tabela **Ação / Resultado**. **Não inventar classes CSS.** O parcial começa com `<?php defined('WIKI_SECTION') || exit; ?>`. Sem jargão, sem URL, sem infra.

**Ciclo de cada entrada nova (TDD com o registro):** (a) adicionar a entrada em `wiki_registry.php`; (b) remover a linha `PENDENTE etapa 2` da tela em `$EXCECOES` (só para tela nova da matriz); (c) rodar `php tests/helpers/wiki_registry.test.php` → falha por parcial ausente; (d) escrever o parcial; (e) rodar os dois testes → passam (o de vazamento cobre o parcial novo sozinho).

### Task 6: Cadastros — o fluxo chip → câmera → veículo (a maior correção)

**Files:**
- Modify: `includes/wiki/sections/ativos.php`, `chips.php`, `equipamentos.php`, `includes/wiki_registry.php` (summaries de `ativos`, `chips`, `equipamentos`)
- Ler antes: `handlers/ativos.php`, `ativos_novo.php`, `ativo_detalhe.php`, `chips.php`, `equipamentos.php`; `includes/functions.php` (`install_device_on_vehicle()`, `uninstall_device_from_vehicle()`); `CHANGELOG.md` (v4.11.0–v4.12.1, v4.16.0)

- [ ] **Step 1: Ler as fontes e anotar, por tela, o que o usuário vê e faz** (campos, botões, mensagens de erro literais).
- [ ] **Step 2: Reescrever os três parciais cobrindo estes fatos** (cada um confirmado no handler antes de entrar):
  1. A ordem: **chip livre e ativo → câmera cadastrada com esse chip (Equipamentos) → câmera instalada num veículo (Ativos)**; instalar exige chip já vinculado.
  2. **Veículo** (Ativos) existe sem câmera; **Placa é texto livre** (`ABC1D23`, `Frota 07` valem — a tela não valida formato).
  3. Trocar a câmera de um veículo preserva o histórico: instalação corrente × removida, com datas.
  4. Desinstalar libera a câmera (o chip continua nela); desativar a câmera só é possível sem instalação aberta e libera o chip; desativar o chip só é possível com o chip livre.
  5. O vínculo chip↔câmera é feito **só em Equipamentos**; em Chips a câmera vinculada aparece como leitura, com link para editar lá.
  6. Enquanto instalada, o cliente da câmera é o do veículo; câmera livre pode ter o cliente editado em Equipamentos.
  7. Abas do veículo: Trajetos/Alertas/Log/Vídeo mostram o histórico do veículo mesmo sem câmera agora; as abas ao vivo (ao vivo, comandos, configurações, parâmetros) exigem câmera instalada.
  8. Callout de suporte: *"Cadastrei e o relatório está vazio"* → o equipamento só passa a aparecer nos relatórios depois de completar chip → equipamento → veículo (decisão do dono do produto, 03/09/2026; **não é defeito**).
  9. **Rastreadores JM-VL01/JM-VL02** (Equipamentos): não têm câmera — sem vídeo e sem alertas de IA (DMS/ADAS); o modelo define a família; contagem de câmeras 0.
- [ ] **Step 3: Ajustar os `summary` de `ativos` ("Cadastro dos veículos da frota e da câmera instalada em cada um."), `chips` e `equipamentos` se o texto novo os contradisser.**
- [ ] **Step 4: Rodar os dois testes → 0 falhas.**
- [ ] **Step 5: Commit** — `docs: wiki - fluxo chip, camera e veiculo e rastreadores JM-VL (v4.22.1)`.

### Task 7: Seções novas de uso diário — Manutenção, Painel, Auditoria

**Files:**
- Modify: `includes/wiki_registry.php`, `tests/helpers/wiki_registry.test.php` (remover 3 `PENDENTE`)
- Create: `includes/wiki/sections/manutencoes.php`, `painel.php`, `auditoria.php`
- Ler antes: `handlers/manutencoes.php`; `handlers/painel.php` + `includes/dashboard_widgets.php`; `handlers/auditoria.php`, `auditoria_negados.php`, `auditoria_cadastro.php`, `auditoria_login.php`

- [ ] **Step 1: Adicionar ao registro** (a posição importa — é a da página):

```php
// logo após 'resumo' (h2 avulso, recuado no índice, como as demais telas de topo):
wiki_sec('painel', 'Painel', [
    'level' => 2, 'sub' => true, 'screen' => 'painel', 'handler' => 'painel.php',
    'summary' => 'Versão do Resumo em widgets que você organiza do seu jeito.',
]),
// em Cadastros, logo após 'motoristas':
wiki_sec('manutencoes', 'Manutenção', [
    'group' => 'cadastros', 'screen' => 'manutencoes', 'handler' => 'manutencoes.php',
    'actions' => ['create', 'edit', 'delete'],
    'summary' => 'Registro e acompanhamento das manutenções dos veículos.', // conferir no handler
]),
// em Cadastros, logo após 'usuarios':
wiki_sec('auditoria', 'Auditoria', [
    'group' => 'cadastros', 'screen' => 'auditoria', 'handler' => 'auditoria.php',
    'actions' => ['export'],
    'summary' => 'Quem fez o quê no sistema, acessos negados, cadastros e logins.',
]),
```
- [ ] **Step 2: Remover `manutencoes`, `painel`, `auditoria` de `$EXCECOES`; rodar o teste → falha por parciais ausentes.**
- [ ] **Step 3: Escrever os parciais.** Fatos obrigatórios (confirmar no handler): **Manutenção** — o que se cadastra, filtros, o que acontece ao criar/editar/excluir, alertas de vencimento se existirem; **Painel** — é opt-in e convive com o Resumo (comentário v4.10.3), como adicionar/remover/reordenar widgets, se a organização é por usuário, o que os widgets mostram; **Auditoria** — as quatro visões (geral, negados, cadastro, login), filtros, exportação, e que ela só lê (não altera nada).
- [ ] **Step 4: Rodar os dois testes → 0 falhas; confirmar que `actions` do registro batem** (o teste de ações acusa se a tela exigir outra).
- [ ] **Step 5: Commit** — `docs: wiki - Manutencao, Painel e Auditoria (v4.22.1)`.

### Task 8: Seções novas de operação — Comandos por SMS, SMS (configuração), Configurações IA

**Files:**
- Modify: `includes/wiki_registry.php`, `tests/helpers/wiki_registry.test.php` (remover 3 `PENDENTE`)
- Create: `includes/wiki/sections/comandos-sms.php`, `config-sms.php`, `configuracoes-ia.php`
- Ler antes: `handlers/comandos_sms.php` + `docs/superpowers/specs/2026-08-29-comandos-sms-design.md`; `handlers/config_sms.php`; `handlers/configuracoes_ia.php`

- [ ] **Step 1: Adicionar ao registro** (grupo `operacoes`; `comandos-sms` logo após `comandos`; `config-sms` e `configuracoes-ia` logo após `firmwares`):

```php
wiki_sec('comandos-sms', 'Comandos por SMS', [
    'group' => 'operacoes', 'screen' => 'comandos-sms', 'handler' => 'comandos_sms.php',
    'summary' => 'Os mesmos comandos, enviados pela rede da operadora (cada envio consome crédito).',
]),
wiki_sec('config-sms', 'SMS (configuração)', [
    'group' => 'operacoes', 'screen' => 'config-sms', 'handler' => 'config_sms.php', 'admin_only' => true,
    'summary' => 'Conta do provedor de SMS e retorno de entrega.',
]),
wiki_sec('configuracoes-ia', 'Configurações IA', [
    'group' => 'operacoes', 'screen' => 'configuracoes-ia', 'handler' => 'configuracoes_ia.php', 'admin_only' => true,
    'summary' => 'Ler e ajustar à distância a configuração dos alertas de IA (ADAS/DMS) e da velocidade nas câmeras.',
]),
```
- [ ] **Step 2: Remover `comandos-sms`, `config-sms`, `configuracoes-ia` de `$EXCECOES`; rodar → falha por parcial.**
- [ ] **Step 3: Escrever os parciais.** Fatos obrigatórios: **Comandos SMS** — o consumo de crédito por equipamento em destaque (callout `warn`), permissão própria, o que muda em relação a Comandos, como ver o retorno; **SMS (config)** — para que serve a conta, o que o admin preenche, o que o retorno de entrega mostra (sem expor segredo nem URL de webhook); **Configurações IA** — o que é lido e o que é enviado ao equipamento, o perfil de leitura completa (v4.18.2), o aviso de que age em equipamento em operação (admin).
- [ ] **Step 4: Rodar os dois testes → 0 falhas.**
- [ ] **Step 5: Commit** — `docs: wiki - Comandos por SMS, SMS e Configuracoes IA (v4.22.1)`.

### Task 9: Corrigir o que mudou nas seções existentes

**Files:**
- Modify: `includes/wiki/sections/primeiros-passos.php`, `rel-deslocamento.php`, `rel-desatualizados.php`, `ocorrencias-dashboard.php`, `rel-dirigibilidade.php`, `rel-alarmes.php`, `resumo.php`, `notificacoes.php` (conforme o achado); `includes/wiki_registry.php` (summary de `rel-desatualizados` se mudar)
- Ler antes (um item por vez, com a fonte): 

- [ ] **Step 1: Levantar o que mudou para o usuário desde a v4.13.16.** Rodar `sed -n` no `CHANGELOG.md` da v4.13.16 até a v4.21.8 e listar só as linhas visíveis a quem usa. Para cada uma, abrir o parcial da tela e marcar "cobre / não cobre / contradiz".
- [ ] **Step 2: Corrigir estes pontos (mínimo obrigatório), cada um confirmado na fonte citada:**
  1. **Desatualizados** — `rel-desatualizados.php` diz "cinco faixas"; desde a v4.21.2 são **dois grupos** e o critério é o **último sinal de comunicação** (não a posição GPS), com tolerância de 5 min (ligada) / 30 min (desligada). Fonte: `handlers/rel_desatualizados.php`, `tests/helpers/device_outdated.test.php`, CHANGELOG v4.21.2.
  2. **Deslocamento** — a distância vem do **hodômetro** (v4.21.7). Ler `handlers/rel_deslocamento.php`, `scripts/trip_builder.php` e o CHANGELOG v4.21.7 e descrever **exatamente** o que a tela faz com equipamento sem hodômetro; **não afirmar além do que o código faz** (a memória do projeto registra divergência do dono do produto sobre quais modelos têm hodômetro — se houver dúvida, escrever "não confirmado" e listar a dúvida no `STATUS.md`).
  3. **Tratativa de ocorrência** — velocidade e número do alarme na tela (v4.21.6/v4.21.8), valendo para qualquer alarme. Fonte: `handlers/ocorrencias_dashboard.php`.
  4. **Alertas Videomonitoramento × Dirigibilidade** — `rel-alarmes.php` e `rel-dirigibilidade.php`: conferir que explicam a separação (condução vai para Dirigibilidade, venha de câmera ou de rastreador; Videomonitoramento exclui condução e equipamento sem câmera). Fonte: `handlers/rel_alarmes.php`, `rel_dirigibilidade.php`.
  5. **Recuperar e trocar senha** — em `primeiros-passos.php`, após "Login": o fluxo "Esqueci minha senha" e a troca de senha, incluindo a senha temporária. Fonte: `handlers/esqueci_senha.php`, `trocar_senha.php`, `includes/password_reset.php`, `tests/helpers/temp_password.test.php`.
  6. **Visão do revendedor** — em `resumo.php` (ranking entre clientes) e em `primeiros-passos.php` ("Trocar Cliente"): o que o revendedor vê e como opera como cliente (banner). Fonte: `includes/dashboard_widgets.php` (`dashboard_render_reseller_view`), `handlers/customer_switch.php`, `web/layout_base.php` (banner de impersonação).
  7. Qualquer contradição/lacuna achada no Step 1: corrigir seguindo o mesmo critério (fonte lida antes de escrever).
- [ ] **Step 3: Rodar os dois testes → 0 falhas.**
- [ ] **Step 4: Commit** — `docs: wiki - Desatualizados, Deslocamento, tratativa, senha e visao do revendedor (v4.22.1)`.

### Task 10: Release v4.22.1

**Files:** `CHANGELOG.md`, `STATUS.md`, `.env.example`, `includes/wiki_registry.php` (confirmar que nenhum `PENDENTE etapa 2` sobrou em `wiki_registry.test.php`).

- [ ] **Step 1:** `grep -n "PENDENTE" tests/helpers/wiki_registry.test.php` → sem resultado.
- [ ] **Step 2:** Rodar os dois testes e o lint global (comandos da Task 5, Step 6).
- [ ] **Step 3:** Bump `SYSTEM_VERSION=4.22.1`, `CHANGELOG.md` (Adicionado: seções de Manutenção, Painel, Auditoria, Comandos SMS, SMS, Configurações IA, senha; Corrigido: Desatualizados, Deslocamento, fluxo chip→câmera→veículo…), `STATUS.md` (+ rotação).
- [ ] **Step 4:** Commit + `git push origin main`.

---

# ETAPA 3 — Cards por perfil e "Meu acesso" (v4.23.0)

### Task 11: Perfil, card de abertura e "Meu acesso"

**Files:**
- Modify: `includes/wiki_access.php` (perfil), `includes/wiki_render.php` (card, "Meu acesso"), `includes/wiki_registry.php` (`meu-acesso` + `dynamic`), `tests/helpers/wiki_access.test.php`, `tests/helpers/wiki_registry.test.php` (remover a exceção `wiki`)

**Interfaces:**
- Produces: `wiki_profile(array $user): string` (`admin|revendedor|cliente`); `wiki_profile_info(string $perfil): array{label:string,daily:string,shortcuts:string[]}`; `wiki_render_card(string $perfil, ?string $grupo, array $registry, array $access): string`; `wiki_render_meu_acesso(array $registry, array $access): string`.

- [ ] **Step 1: Escrever os testes (falham)** — acrescentar ao `wiki_access.test.php`, antes do bloco final:

```php
echo "== perfil e card ==\n";
checa('role admin → admin', 'admin', wiki_profile(['role' => 'admin', 'user_type' => 'revendedor']));
checa('user_type revendedor → revendedor', 'revendedor', wiki_profile(['role' => 'user', 'user_type' => 'revendedor']));
checa('demais → cliente', 'cliente', wiki_profile(['role' => 'user', 'user_type' => 'cliente']));
checa('sem role e sem user_type → cliente', 'cliente', wiki_profile([]));

$idsVisiveis = array_column(wiki_visible($reg), 'id');
foreach (['admin', 'revendedor', 'cliente'] as $p) {
    $info = wiki_profile_info($p);
    checa("atalhos de $p existem no registro", [], array_values(array_diff($info['shortcuts'], $idsVisiveis)));
    checa("atalhos de $p têm ao menos 6 opções (reposição)", true, count($info['shortcuts']) >= 6);
}

$cardCli = wiki_render_card('cliente', null, $reg, wiki_compute_access($reg, 'cliente', $tudo));
checa('card mostra o perfil', true, strpos($cardCli, 'Seu perfil: Cliente') !== false);
checa('card sem grupo explica o "vê tudo, exceto admin"', true, strpos($cardCli, 'exceto as áreas restritas a administradores') !== false);
checa('card com grupo mostra o nome', true, strpos(wiki_render_card('cliente', 'Supervisão', $reg, wiki_compute_access($reg, 'cliente', $tudo)), 'Grupo Supervisão') !== false);
preg_match_all('/href="#([a-z0-9-]+)" class="wiki-shortcut"/', $cardCli, $sc);
checa('card mostra exatamente 5 atalhos (a lista tem reposição)', 5, count($sc[1]));

$semRastreamento = fn($s, $a = 'view') => $s !== 'rastreamento';
$cardSem = wiki_render_card('cliente', null, $reg, wiki_compute_access($reg, 'cliente', $semRastreamento));
preg_match_all('/href="#([a-z0-9-]+)" class="wiki-shortcut"/', $cardSem, $sc2);
checa('atalho bloqueado sai e o próximo entra (continua 5)', 5, count($sc2[1]));
checa('atalho para tela bloqueada não aparece', false, in_array('rastreamento', $sc2[1], true));

$soRastreamento = fn($s, $a = 'view') => $s === 'rastreamento';
$cardPouco = wiki_render_card('cliente', 'Restrito', $reg, wiki_compute_access($reg, 'cliente', $soRastreamento));
preg_match_all('/href="#([a-z0-9-]+)" class="wiki-shortcut"/', $cardPouco, $sc3);
checa('grupo restrito: só sobra o atalho liberado, sem botão morto', ['rastreamento'], $sc3[1]);

echo "== contador \"N de M telas\" ==\n";
$telas = array_values(array_filter(wiki_visible($reg), fn($s) => $s['handler'] !== null));
$m = count($telas);
checa('contador mostra M do registro', true, strpos($cardCli, "de $m telas") !== false);
$cardBloq = wiki_render_card('cliente', null, $reg, wiki_compute_access($reg, 'cliente', $nada));
checa('sem nenhuma permissão: 0 de M', true, strpos($cardBloq, "0 de $m telas") !== false);

echo "== Meu acesso ==\n";
$meu = wiki_render_meu_acesso($reg, wiki_compute_access($reg, 'cliente', $nada));
checa('lista bloqueadas com o motivo', true, strpos($meu, WIKI_MOTIVO_GRUPO) !== false);
checa('Meu acesso entra no corpo da página', true, strpos(wiki_render_body($reg, wiki_compute_access($reg, 'cliente', $tudo)), 'id="meu-acesso"') !== false);
```
Run: `php tests/helpers/wiki_access.test.php` → falha (`wiki_profile` indefinida).

- [ ] **Step 2: Perfil em `includes/wiki_access.php`** (acrescentar):

```php
/**
 * Perfil para o card de abertura. `role` vem primeiro: um admin com
 * user_type='revendedor' é admin (reseller_scope_ids() o trata como sem
 * restrição).
 *
 * @param array $user Linha de get_jimi_user() (pode ser vazia).
 * @returns string admin|revendedor|cliente
 */
function wiki_profile(array $user): string {
    if (($user['role'] ?? '') === 'admin') return 'admin';
    if (($user['user_type'] ?? '') === 'revendedor') return 'revendedor';
    return 'cliente';
}

/**
 * Texto e atalhos do card. As listas têm mais de 5 ids de propósito: o card
 * mostra os 5 primeiros que o usuário PODE abrir, então um bloqueado é
 * substituído pelo próximo. Ids de seção do registro (rótulo = título dela).
 *
 * @returns array{label:string,daily:string,shortcuts:string[]}
 */
function wiki_profile_info(string $perfil): array {
    $info = [
        'admin' => [
            'label' => 'Administrador',
            'daily' => 'Você cadastra clientes, usuários e equipamentos, define permissões e ajusta as configurações da plataforma.',
            'shortcuts' => ['clientes', 'usuarios', 'grupos-permissao', 'equipamentos', 'comandos', 'firmwares', 'auditoria'],
        ],
        'revendedor' => [
            'label' => 'Revendedor',
            'daily' => 'Você acompanha vários clientes, entra na operação de cada um e compara o desempenho entre eles.',
            'shortcuts' => ['resumo', 'rastreamento', 'ocorrencias-dashboard', 'ativos', 'rel-comum', 'rel-status-frota', 'video-aovivo'],
        ],
        'cliente' => [
            'label' => 'Cliente',
            'daily' => 'Você acompanha a sua frota, trata as ocorrências dos motoristas e tira relatórios.',
            'shortcuts' => ['rastreamento', 'ocorrencias-dashboard', 'video-aovivo', 'rel-alarmes', 'ativos', 'rel-posicoes', 'motoristas'],
        ],
    ];
    return $info[$perfil] ?? $info['cliente'];
}
```
(`auditoria` só existe após a Etapa 2 — está lá. Se a Etapa 2 não estiver mergeada, o teste "atalhos existem no registro" acusa.)

- [ ] **Step 3: Registro — seção "Meu acesso"** em `wiki_registry.php`, **logo após `intro`**:

```php
wiki_sec('meu-acesso', 'Meu acesso', [
    'level' => 2, 'screen' => 'wiki', 'dynamic' => true,
    'summary' => 'O que está liberado e o que está bloqueado para você.',
]),
```
E em `tests/helpers/wiki_registry.test.php`: remover a linha `'wiki' => 'é a própria Central de Ajuda'` de `$EXCECOES` (agora a tela `wiki` tem seção — o teste acusaria exceção obsoleta).

- [ ] **Step 4: Renderizador — card e "Meu acesso"** em `includes/wiki_render.php` (acrescentar) e, em `wiki_render_body()`, trocar a linha do corpo por:

```php
        $out .= $bloqueada
            ? wiki_stub($sec, $acc)
            : wiki_access_strip($sec, $acc)
                . (!empty($sec['dynamic']) ? wiki_render_meu_acesso($registry, $access) : wiki_include_section($sec['id']));
```

```php
/** Seções que são uma tela (têm handler) e não estão ocultas — a base do "N de M". */
function wiki_telas(array $registry): array {
    return array_values(array_filter(wiki_visible($registry), fn($s) => $s['handler'] !== null));
}

/**
 * Card de abertura por perfil (spec §4.5).
 *
 * @param string  $perfil   admin|revendedor|cliente (wiki_profile()).
 * @param ?string $grupo    Nome do grupo de permissão, ou null se não tem.
 * @param array   $registry Registro.
 * @param array   $access   Mapa de wiki_compute_access().
 */
function wiki_render_card(string $perfil, ?string $grupo, array $registry, array $access): string {
    $info   = wiki_profile_info($perfil);
    $porId  = [];
    foreach (wiki_visible($registry) as $s) $porId[$s['id']] = $s;
    $telas  = wiki_telas($registry);
    $livres = count(array_filter($telas, fn($s) => $access[$s['id']]['state'] === 'liberada'));

    $linhaGrupo = $grupo !== null
        ? 'Grupo ' . wiki_esc($grupo)
        : 'Sem grupo de permissão: você vê tudo, exceto as áreas restritas a administradores.';

    $atalhos = '';
    $n = 0;
    foreach ($info['shortcuts'] as $id) {
        if ($n >= 5) break;
        if (!isset($porId[$id]) || $access[$id]['state'] !== 'liberada') continue;
        $atalhos .= '<a href="#' . wiki_esc($id) . '" class="wiki-shortcut">' . wiki_esc($porId[$id]['title']) . '</a>';
        $n++;
    }

    return '<div class="wiki-card">'
        . '<div class="wiki-card-top"><strong>Seu perfil: ' . wiki_esc($info['label']) . '</strong>'
        . '<span>' . $linhaGrupo . '</span></div>'
        . '<p>' . wiki_esc($info['daily']) . '</p>'
        . '<p class="wiki-card-count">Você tem acesso a ' . $livres . ' de ' . count($telas) . ' telas. '
        . '<a href="#meu-acesso">Ver meu acesso completo</a></p>'
        . ($atalhos !== '' ? '<div class="wiki-card-go"><span>Comece por aqui</span>' . $atalhos . '</div>' : '')
        . '</div>';
}

/** Corpo da seção "Meu acesso": liberado × bloqueado, com o motivo. */
function wiki_render_meu_acesso(array $registry, array $access): string {
    $liberadas = '';
    $bloqueadas = '';
    foreach (wiki_telas($registry) as $s) {
        $acc = $access[$s['id']];
        if ($acc['state'] === 'liberada') {
            $liberadas .= '<li><a href="#' . wiki_esc($s['id']) . '">' . wiki_esc($s['title']) . '</a></li>';
        } else {
            $bloqueadas .= '<li><a href="#' . wiki_esc($s['id']) . '">' . wiki_esc($s['title']) . '</a> — '
                . wiki_esc((string)$acc['reason']) . '</li>';
        }
    }
    return '<div class="wiki-meu-acesso"><h4>Liberado para você</h4><ul>' . ($liberadas ?: '<li>Nenhuma tela.</li>') . '</ul>'
        . '<h4>Bloqueado</h4><ul>' . ($bloqueadas ?: '<li>Nada bloqueado.</li>') . '</ul></div>';
}
```

- [ ] **Step 5: CSS do card** — acrescentar em `handlers/wiki.php`, dentro do bloco do `$extra_head`, antes de `</style>`:

```css
.wiki-card { border: 1px solid var(--hairline); border-radius: 16px; padding: 18px 20px; margin: 0 0 28px; background: var(--canvas); }
.wiki-card-top { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 16px; font-size: 14px; color: var(--ink); }
.wiki-card-top span { color: var(--muted); font-size: 13px; }
.wiki-card p { margin: 8px 0 0; color: var(--body); }
.wiki-card-count a { color: var(--primary); }
.wiki-card-go { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 12px; }
.wiki-card-go > span { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); }
.wiki-shortcut { padding: 6px 14px; border-radius: 100px; background: var(--primary-soft); color: var(--primary); font-size: 13px; font-weight: 600; text-decoration: none; }
.wiki-meu-acesso ul { margin: 6px 0 14px; padding-left: 18px; line-height: 1.7; color: var(--body); }
```

- [ ] **Step 6: Rodar os dois testes → 0 falhas; lint.** Se `contador mostra M` falhar por diferença de contagem, conferir `wiki_telas()` — a base é `handler !== null` e não oculta.
- [ ] **Step 7: Commit** — `feat: card de abertura por perfil e secao Meu acesso na Central de Ajuda (v4.23.0)`.

### Task 12: Ligar o card no `wiki.php`, verificar e lançar v4.23.0

**Files:** `handlers/wiki.php`, `tests/wiki.spec.js`, `CHANGELOG.md`, `STATUS.md`, `.env.example`

- [ ] **Step 1: Nome do grupo e card no `wiki.php`.** Logo após a linha `$access = wiki_compute_access(...)`, acrescentar:

```php
$perfil    = wiki_profile($user);
$grupoNome = null;
if (!empty($user['permission_group_id'])) {
    try {
        $st = Database::getInstance()->getConnection()->prepare('SELECT name FROM permission_groups WHERE id = ?');
        $st->execute([(int)$user['permission_group_id']]);
        $grupoNome = $st->fetchColumn() ?: null;
    } catch (Throwable $e) {
        $grupoNome = null; // o card mostra só o perfil, sem o nome do grupo
    }
}
```
e, dentro de `.wiki-content`, antes de `<?= wiki_render_body(...) ?>`:

```php
<?= wiki_render_card($perfil, $grupoNome, $registry, $access) ?>
```
- [ ] **Step 2: Estender `tests/wiki.spec.js`** com:

```js
test('wiki: mostra o card do perfil e o "Meu acesso"', async ({ page }) => {
    await page.goto('/wiki');
    await expect(page.locator('.wiki-card')).toContainText('Seu perfil:');
    await expect(page.locator('#meu-acesso')).toHaveCount(1);
});
```
- [ ] **Step 3: Verificação em navegador** (mesma regra da Task 4, Step 7): os três perfis; confirmar no card o contador, os 5 atalhos filtrados e "Meu acesso" com motivos. Sem ambiente local, registrar como **não verificado** no `STATUS.md`.
- [ ] **Step 4: Testes, lint, release** — os dois testes + lint global; `SYSTEM_VERSION=4.23.0`, `CHANGELOG.md`, `STATUS.md` (+ rotação); atualizar o docblock do `wiki.php` com "Atualizada na v4.23.0" (card por perfil e "Meu acesso").
- [ ] **Step 5: Commit + push**

```bash
git add -A handlers includes tests CHANGELOG.md STATUS.md .env.example docs/status-history
git commit -m "$(cat <<'EOF'
feat: Central de Ajuda com card de abertura por perfil e Meu acesso (v4.23.0)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
git push origin main
```

---

## Auto-revisão (contra o spec)

- **§2 decisões** → stub bloqueado (Tasks 3–4), faixa (3), card (11–12), abordagem A (1–4). ✔
- **§3 regra de acesso** → `wiki_access()` (Task 1) com os dois motivos; `admin_only` do handler conferido na Task 5. ✔ (§3 do spec corrigido: Grupos de Permissão não é admin-only.)
- **§4.1–4.5** → registro (1), resolvedor (1), renderizador + índice + rodapé por versão (3–4), parciais + trava `WIKI_SECTION` (2), card + "Meu acesso" (11). ✔
- **§5 conteúdo** → Tasks 6–9 (Cadastros/JM-VL, Manutenção, Comandos SMS, Auditoria, Config. IA, SMS, senha, Deslocamento, Desatualizados, Ocorrência, Dirigibilidade, Painel, revendedor). ✔
- **§6 testes** → `wiki_access.test.php` (vazamento, âncoras, card), `wiki_registry.test.php` (matriz, `admin_only`, ações, parciais), paridade (Task 4), Playwright (5, 12), navegador (4, 12). ✔
- **§7 decisões a revisar** → Parâmetros `hidden` (Task 1 + trava "únicas seções ocultas"); listas de atalhos (Task 11); `extras` de `rel-ocorrencias` (Task 1). ✔
- **§8 entrega** → v4.22.0 / v4.22.1 / v4.23.0, commit + push, sem deploy; regra "TRÊS lugares" (Task 5). ✔

Consistência de nomes conferida: `wiki_sec`, `wiki_registry`, `wiki_groups`, `wiki_access`, `wiki_compute_access`, `wiki_visible`, `wiki_telas`, `wiki_render_toc/body/card/meu_acesso`, `wiki_profile`, `wiki_profile_info`, `WIKI_MOTIVO_ADMIN/GRUPO`, `WIKI_SECTION`.

## O que já foi rodado ao escrever este plano (19/09/2026)

Numa cópia descartável (fora do repositório), o código **exato** deste plano foi extraído e executado:

- **Etapas 1 e 3:** `wiki_access.test.php` com os blocos das Tasks 1, 3 e 11 → **131 verificações, 0 falhas**; `wiki_registry.test.php` com os blocos da Task 5 → **10 verificações, 0 falhas** (contra os `handlers/` reais); divisor da Task 2 → 42 parciais; `php -l` em tudo.
- **Paridade da Task 4:** contra `776ea00`, para o admin → `PARIDADE OK`, com exatamente 4 diferenças (o badge "admin" indevido de Grupos de Permissão, Config. Ocorrências, Config. Notificações e Servidor de E-mail). 46 partes antigas × 46 novas.
- **Montagem do `wiki.php`** (Task 4) → 500 linhas, lint OK.
- **Amostra do card** para um cliente sem grupo: "34 de 37 telas" (as 3 bloqueadas são as admin-only: Clientes, Usuários, Firmware).

**Não foi rodado:** o texto das seções da Etapa 2 (ainda não existe), a verificação em navegador com os três perfis e o spec Playwright. Esses três ficam como passos explícitos das Tasks 4, 5 e 12.
