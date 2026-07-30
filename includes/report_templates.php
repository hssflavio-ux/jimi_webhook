<?php
/**
 * JIMI Webhook System — Modelos de relatório v4.7.0
 * Arquivo: includes/report_templates.php
 *
 * "Salvar como modelo" e o seletor que repopula os filtros, compartilhados por
 * todas as telas de relatório.
 *
 * O modelo guarda a **query string** da tela, não uma estrutura própria: é o
 * que o relatório já sabe interpretar, funciona para qualquer filtro presente
 * ou futuro e não exige mapeamento por tela. Aplicar um modelo é redirecionar
 * para a mesma rota com aquela query.
 *
 * Chaves excluídas do modelo (`REPORT_TEMPLATE_SKIP`): paginação, export,
 * ordenação, o próprio maquinário de modelos e o token CSRF. Salvar `page=7`
 * num modelo faria o usuário reabrir sempre na página 7; salvar `export=xlsx`
 * faria o modelo baixar um arquivo em vez de abrir a tela.
 *
 * Uso na tela (uma linha, depois de `require layout_base.php`):
 *
 *     render_template_bar('rel_alarmes', '/relatorios/alarmes');
 *
 * E, ANTES de qualquer saída (o salvar redireciona):
 *
 *     handle_template_actions('rel_alarmes', '/relatorios/alarmes');
 */

require_once __DIR__ . '/csrf.php';

/** Parâmetros que nunca entram num modelo. */
const REPORT_TEMPLATE_SKIP = [
    'page', 'export', 'sort', 'order',
    'tpl', 'tpl_name', 'tpl_action', 'tpl_id', '_csrf_token', 'msg',
];

/** Teto de modelos por usuário e por tela. */
const REPORT_TEMPLATE_MAX = 20;

/**
 * Extrai da query string atual o que deve virar modelo.
 *
 * @param array $query Normalmente $_GET
 * @returns array Filtros limpos
 */
function report_template_filters(array $query): array
{
    $out = [];
    foreach ($query as $k => $v) {
        if (in_array($k, REPORT_TEMPLATE_SKIP, true)) {
            continue;
        }
        if (is_array($v)) {
            $v = implode(',', array_map('strval', $v));
        }
        $v = (string)$v;
        if ($v === '') {
            continue; // filtro vazio é ausência de filtro
        }
        $out[$k] = $v;
    }
    return $out;
}

/**
 * Trata as ações de modelo (salvar, aplicar, excluir).
 *
 * Precisa rodar ANTES de qualquer saída HTML: as três ações terminam em
 * redirecionamento (Post/Redirect/Get), para que um F5 não regrave o modelo.
 *
 * @param string $reportType Identificador da tela (ex.: 'rel_alarmes')
 * @param string $basePath   Rota da tela (ex.: '/relatorios/alarmes')
 * @returns void
 */
function handle_template_actions(string $reportType, string $basePath): void
{
    // ── Aplicar (GET) ──────────────────────────────────────────
    // Só leitura: redireciona para a própria tela com os filtros do modelo.
    if (!empty($_GET['tpl'])) {
        $tplId = (int)$_GET['tpl'];
        $row   = report_template_find($tplId);
        if ($row) {
            $filters = json_decode((string)$row['filters'], true);
            $qs = is_array($filters) ? http_build_query($filters) : '';
            header('Location: ' . $basePath . ($qs ? '?' . $qs : ''));
            exit;
        }
        header('Location: ' . $basePath);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['tpl_action'])) {
        return;
    }
    csrf_verify();

    $db   = Database::getInstance()->getConnection();
    $user = get_jimi_user();
    $uid  = (int)($user['id'] ?? 0);
    $cid  = get_customer_id();

    if ($uid <= 0) {
        return;
    }

    try {
        if ($_POST['tpl_action'] === 'save') {
            $name = trim((string)($_POST['tpl_name'] ?? ''));
            if ($name === '') {
                return;
            }

            $count = $db->prepare("SELECT COUNT(*) FROM report_templates WHERE user_id = :u AND report_type = :t");
            $count->execute([':u' => $uid, ':t' => $reportType]);
            if ((int)$count->fetchColumn() >= REPORT_TEMPLATE_MAX) {
                header('Location: ' . $basePath . '?msg=tpl_limite');
                exit;
            }

            // Os filtros salvos são os da URL ATUAL — o usuário monta o
            // relatório, vê o resultado e só então decide guardá-lo.
            $filters = report_template_filters($_GET);

            // ON DUPLICATE sobre (user_id, report_type, name): salvar duas
            // vezes com o mesmo nome atualiza, em vez de estourar a unique.
            $db->prepare("
                INSERT INTO report_templates (customer_id, user_id, name, report_type, filters)
                VALUES (:cid, :uid, :name, :type, :filters)
                ON DUPLICATE KEY UPDATE filters = VALUES(filters)")
               ->execute([
                   ':cid'     => $cid !== null ? (int)$cid : 1,
                   ':uid'     => $uid,
                   ':name'    => mb_substr($name, 0, 150),
                   ':type'    => $reportType,
                   ':filters' => json_encode($filters, JSON_UNESCAPED_UNICODE),
               ]);

            $qs = http_build_query($filters);
            header('Location: ' . $basePath . ($qs ? '?' . $qs . '&' : '?') . 'msg=tpl_salvo');
            exit;
        }

        if ($_POST['tpl_action'] === 'delete') {
            $tplId = (int)($_POST['tpl_id'] ?? 0);
            // O user_id no WHERE é o escopo: ninguém apaga modelo de outro.
            $db->prepare("DELETE FROM report_templates WHERE id = :id AND user_id = :u")
               ->execute([':id' => $tplId, ':u' => $uid]);
            header('Location: ' . $basePath . '?msg=tpl_excluido');
            exit;
        }
    } catch (Throwable $e) {
        // Tabela ausente (migração v4.7.0 pendente) não pode derrubar o
        // relatório — a tela funciona sem modelos.
        return;
    }
}

/**
 * Busca um modelo do usuário corrente.
 *
 * @param int $id report_templates.id
 * @returns array|null
 */
function report_template_find(int $id): ?array
{
    try {
        $db   = Database::getInstance()->getConnection();
        $user = get_jimi_user();
        $stmt = $db->prepare("SELECT * FROM report_templates WHERE id = :id AND user_id = :u");
        $stmt->execute([':id' => $id, ':u' => (int)($user['id'] ?? 0)]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Lista os modelos do usuário para uma tela.
 *
 * @param string $reportType Identificador da tela
 * @returns array
 */
function report_templates_for(string $reportType): array
{
    try {
        $db   = Database::getInstance()->getConnection();
        $user = get_jimi_user();
        $stmt = $db->prepare("
            SELECT id, name FROM report_templates
            WHERE user_id = :u AND report_type = :t
            ORDER BY name");
        $stmt->execute([':u' => (int)($user['id'] ?? 0), ':t' => $reportType]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Renderiza a barra de modelos (seletor + salvar + excluir).
 *
 * Some por completo quando não há modelo salvo, nem filtro na URL, nem
 * mensagem a exibir: numa tela recém-aberta não há nada a guardar nem a
 * aplicar, e o controle só ocuparia espaço.
 *
 * A mensagem entra na condição de propósito. Excluir o ÚLTIMO modelo deixa a
 * lista vazia e, se a barra sumisse, o usuário não receberia confirmação
 * nenhuma da ação que acabou de pedir — parece que o clique não funcionou.
 *
 * @param string $reportType Identificador da tela
 * @param string $basePath   Rota da tela
 * @returns void
 */
function render_template_bar(string $reportType, string $basePath): void
{
    $templates = report_templates_for($reportType);
    $filters   = report_template_filters($_GET);
    $hasQuery  = !empty($filters);

    $msg = $_GET['msg'] ?? '';
    $flash = [
        'tpl_salvo'    => ['Modelo salvo.', 'success'],
        'tpl_excluido' => ['Modelo excluído.', 'success'],
        'tpl_limite'   => ['Limite de ' . REPORT_TEMPLATE_MAX . ' modelos por relatório atingido.', 'error'],
    ][$msg] ?? null;

    if (!$templates && !$hasQuery && $flash === null) {
        return;
    }
    ?>
    <div class="card mb-16" style="padding:10px 16px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;">
        <span style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);">Modelos</span>

        <?php if ($templates): ?>
        <form method="GET" action="<?= htmlspecialchars($basePath) ?>" style="display:flex;gap:6px;align-items:center;">
            <select name="tpl" onchange="this.form.submit()"
                    style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);min-width:180px;">
                <option value="">Aplicar um modelo…</option>
                <?php foreach ($templates as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars((string)$t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>

        <?php if ($hasQuery): ?>
        <form method="POST" action="<?= htmlspecialchars($basePath) ?>?<?= htmlspecialchars(http_build_query($filters)) ?>"
              style="display:flex;gap:6px;align-items:center;">
            <?= csrf_field() ?>
            <input type="hidden" name="tpl_action" value="save">
            <input type="text" name="tpl_name" required maxlength="150" placeholder="Salvar filtros atuais como…"
                   style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:210px;">
            <button type="submit" class="btn btn-outline btn-sm">Salvar modelo</button>
        </form>
        <?php endif; ?>

        <?php if ($templates): ?>
        <form method="POST" action="<?= htmlspecialchars($basePath) ?>" style="display:flex;gap:6px;align-items:center;"
              onsubmit="return this.tpl_id.value !== '' && confirm('Excluir este modelo?');">
            <?= csrf_field() ?>
            <input type="hidden" name="tpl_action" value="delete">
            <select name="tpl_id" style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Excluir modelo…</option>
                <?php foreach ($templates as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars((string)$t['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-outline btn-sm">Excluir</button>
        </form>
        <?php endif; ?>

        <?php if ($flash): ?>
        <span style="font-size:12px;color:<?= $flash[1] === 'error' ? 'var(--error)' : '#05b169' ?>;">
            <?= htmlspecialchars($flash[0]) ?>
        </span>
        <?php endif; ?>
    </div>
    <?php
}
