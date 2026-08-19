<?php
/**
 * bycamera — Firmware da frota v4.9.32
 * Rota: /firmwares
 *
 * Duas metades da mesma pergunta — "qual firmware está rodando, e para onde
 * atualizo?":
 *
 *   1. **Frota** — a versão que cada equipamento REPORTOU, quando reportou, e
 *      como ela se compara à de referência do modelo. O botão [Ler versão]
 *      dispara um `VERSION#`; a resposta é gravada por `firmware_capture()`
 *      (includes/firmware.php), nos dois caminhos — síncrono e callback.
 *   2. **URLs de atualização** — cadastro por MODELO, que é o que o comando
 *      `UPDATE,<url>#` precisa.
 *
 * ── Por que a URL é cadastro, e não um campo livre no envio ────────────────
 *
 * 🔴 Mandar para um JC182 a URL do pacote do JC371 **não devolve erro de
 * comando**: o equipamento aceita, baixa e aplica. Diferente de quase todo
 * outro erro desta plataforma, esse não aparece num callback nem num log —
 * aparece numa câmera em operação que parou de funcionar. Digitar a URL a cada
 * envio é repetir a chance desse erro; cadastrá-la com o modelo na chave é
 * eliminá-la.
 *
 * ⚠️ Vírgula e `#` na URL partem o comando (são os separadores do proNo 128).
 * `firmware_url_problema()` recusa as duas AQUI, no cadastro, que é o único
 * ponto onde a URL ainda está separada do resto — depois de virar
 * `UPDATE,<url>#`, uma URL com vírgula e um comando de dois parâmetros são
 * indistinguíveis, e nem o `/sendcommand` consegue separá-los.
 *
 * 🔒 ADMINISTRADOR APENAS, com `require_admin()` — não `can()`. `can()` devolve
 * **true** para todo usuário sem grupo de permissão, que é o estado de todos os
 * usuários deste banco; uma tela que manda equipamento em operação trocar o
 * próprio firmware não pode depender de checagem permissiva por omissão. É a
 * mesma trava de `/parametros` (v4.9.16), pela mesma razão.
 *
 * A entrada em `$screens` (grupos_permissao.php) existe assim mesmo: tela fora
 * da matriz é tela que o admin não consegue enxergar para conceder — o defeito
 * que este repo já pagou cinco vezes.
 *
 * O envio NÃO ganhou endpoint próprio: o frontend chama `/sendcommand` uma vez
 * por equipamento, como `/comandos` faz. Assim a checagem de posse por IMEI, o
 * log e a linha em `commands` continuam exatamente onde estão.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/firmware.php';
require_once __DIR__ . '/../includes/fleet_state.php';
require_admin();

$db   = Database::getInstance()->getConnection();
$user = get_jimi_user();
$erro = $ok = null;

// ── Escopo multi-tenant da LISTA (a de releases é global) ───────────────────
//
// `report_customer_scope()` é o ponto único: para quem não é admin o
// `?customer_id` é IGNORADO, não validado. Aqui todo mundo já é admin por
// `require_admin()`, mas passar pelo helper mesmo assim mantém o comportamento
// idêntico ao das outras telas — inclusive para o revendedor, cuja carteira o
// helper restringe.
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$filtroCust = $_GET['customer_id'] ?? null;
$scopeCust  = report_customer_scope($filtroCust, $isAdmin, get_customer_id());
$customers  = report_customer_options($db);

// ── Ações ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'salvar_release') {
            $id      = (int)($_POST['id'] ?? 0);
            $modelId = (int)($_POST['device_model_id'] ?? 0);
            $versao  = trim($_POST['version'] ?? '');
            $url     = trim($_POST['url'] ?? '');
            $notas   = trim($_POST['notes'] ?? '');
            $current = !empty($_POST['is_current']);

            if (!$modelId)      throw new Exception('Escolha o modelo: a URL do pacote é por modelo.');
            if ($versao === '') throw new Exception('Informe a versão, exatamente como o equipamento a reporta no VERSION#.');
            if ($p = firmware_url_problema($url)) throw new Exception($p);

            // Modelo tem de existir — sem isto uma FK inválida vira release
            // órfã, invisível na tela e ainda assim despachável pelo id.
            $st = $db->prepare("SELECT COUNT(*) FROM device_models WHERE id = :id");
            $st->execute([':id' => $modelId]);
            if (!(int)$st->fetchColumn()) throw new Exception('Modelo inexistente.');

            $db->beginTransaction();
            if ($id > 0) {
                $db->prepare("UPDATE firmware_releases
                                 SET device_model_id = :m, version = :v, url = :u,
                                     notes = :n, is_current = :c
                               WHERE id = :id")
                   ->execute([':m' => $modelId, ':v' => $versao, ':u' => $url,
                              ':n' => $notas ?: null, ':c' => $current ? 1 : 0, ':id' => $id]);
            } else {
                // ON DUPLICATE KEY: (modelo, versão) é único. Recadastrar a
                // mesma versão é corrigir a URL, não criar uma segunda linha.
                $db->prepare("INSERT INTO firmware_releases
                                (device_model_id, version, url, notes, is_current, created_by)
                              VALUES (:m, :v, :u, :n, :c, :by)
                              ON DUPLICATE KEY UPDATE url = VALUES(url), notes = VALUES(notes),
                                                      is_current = VALUES(is_current), is_active = 1")
                   ->execute([':m' => $modelId, ':v' => $versao, ':u' => $url,
                              ':n' => $notas ?: null, ':c' => $current ? 1 : 0,
                              ':by' => $user['id'] ?? null]);
                $id = (int)$db->lastInsertId();
            }

            // Uma referência por modelo. O schema NÃO declara essa unicidade
            // (um índice sobre (modelo, is_current) recusaria a segunda release
            // não-corrente do mesmo modelo, que é o caso normal) — quem garante
            // é este UPDATE, dentro da mesma transação da escrita acima.
            if ($current) {
                $db->prepare("UPDATE firmware_releases SET is_current = 0
                               WHERE device_model_id = :m AND id <> :id")
                   ->execute([':m' => $modelId, ':id' => $id]);
            }
            $db->commit();
            $ok = 'Firmware cadastrado.';

        } elseif ($acao === 'remover_release') {
            $id = (int)($_POST['id'] ?? 0);
            // Baixa lógica: a URL pode estar citada num comando já despachado,
            // e apagar a linha faria o histórico perder a referência.
            $db->prepare("UPDATE firmware_releases SET is_active = 0, is_current = 0 WHERE id = :id")
               ->execute([':id' => $id]);
            $ok = 'Firmware desativado.';
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        // `(modelo, versão)` é único. Editar uma release para um par que já
        // existe é erro de digitação comum, e PDOException crua na tela
        // ("SQLSTATE[23000]… Duplicate entry") não diz o que fazer — é o mesmo
        // problema que o CLAUDE.md registra nos cadastros NOT NULL.
        if ($e instanceof PDOException && ($e->errorInfo[1] ?? 0) === 1062) {
            $erro = 'Já existe esta versão cadastrada para este modelo. Edite a linha existente em vez de criar outra.';
        } else {
            $erro = $e->getMessage();
        }
        Logger::error('firmwares: falha na ação', ['acao' => $acao ?? '', 'erro' => $e->getMessage()]);
    }
}

// ── Dados ───────────────────────────────────────────────────────────────────
$modelos  = $db->query("SELECT id, model_name, protocol FROM device_models ORDER BY model_name")
               ->fetchAll(PDO::FETCH_ASSOC);
$releases = firmware_releases($db, null, true);

// Release de referência por modelo, para a coluna de situação da frota.
$refPorModelo = [];
foreach ($releases as $r) {
    if ((int)$r['is_current'] === 1) $refPorModelo[(int)$r['device_model_id']] = $r;
}

// URLs por modelo para o JS do botão [Atualizar].
$urlsPorModelo = [];
foreach ($releases as $r) {
    $urlsPorModelo[(int)$r['device_model_id']][] = [
        'v' => $r['version'], 'u' => $r['url'], 'c' => (int)$r['is_current'],
    ];
}

$editando = null;
if (!empty($_GET['editar'])) {
    foreach ($releases as $r) if ((int)$r['id'] === (int)$_GET['editar']) $editando = $r;
}

// ── Frota ───────────────────────────────────────────────────────────────────
//
// Sem paginação, como `/parametros` (v4.9.16): é tela de administrador sobre a
// frota inteira, e o recorte que existe é o seletor de cliente. Produção tem 11
// equipamentos. Se a base crescer para centenas, paginar aqui muda também o
// alcance do botão [Ler versão de todos] — que passa a valer só pela página.
$scopeSql    = $scopeCust !== null ? ' AND d.customer_id = :cid' : '';
$scopeParams = $scopeCust !== null ? [':cid' => $scopeCust] : [];

$st = $db->prepare("
    SELECT d.imei,
           COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name,
           d.firmware_version, d.firmware_checked_at, d.firmware_source,
           d.device_model_id,
           COALESCE(dm.model_name, d.device_model, '—') AS model_name,
           COALESCE(cu.name, '—') AS customer_name,
           TIMESTAMPDIFF(MINUTE, " . device_last_seen_sql() . ", UTC_TIMESTAMP()) AS mudo_min
      FROM devices d
      LEFT JOIN device_models dm ON dm.id = d.device_model_id
      LEFT JOIN device_statistics ds ON ds.imei = d.imei
      LEFT JOIN customers cu ON cu.id = d.customer_id
     WHERE d.is_active = 1 {$scopeSql}
     ORDER BY (d.firmware_version IS NULL) DESC, dm.model_name, d.device_name
");
$st->execute($scopeParams);
$frota = $st->fetchAll(PDO::FETCH_ASSOC);

$totais = ['total' => count($frota), 'lidos' => 0, 'nunca' => 0, 'diferentes' => 0, 'sem_modelo' => 0];
foreach ($frota as &$f) {
    $ref = $refPorModelo[(int)$f['device_model_id']] ?? null;
    $f['ref_version'] = $ref['version'] ?? null;
    $f['ref_url']     = $ref['url'] ?? null;
    $f['situacao']    = firmware_situacao($f['firmware_version'], $f['ref_version']);
    // 🔴 Equipamento sem modelo cadastrado não tem como receber UPDATE: a URL é
    // POR MODELO, e sem ele não existe pacote certo para escolher — só um
    // palpite que o equipamento aceitaria sem reclamar. Em produção isto não é
    // hipótese: 1 dos 11 equipamentos está assim (STATUS.md, 19/08/2026).
    $f['sem_modelo']  = empty($f['device_model_id']);
    if ($f['sem_modelo']) $f['situacao'] = ['estado' => 'sem_modelo', 'rotulo' => 'Modelo não cadastrado'];
    $f['presenca']    = device_presence($f['mudo_min'] !== null ? (int)$f['mudo_min'] : null);
    if (trim((string)$f['firmware_version']) !== '') $totais['lidos']++; else $totais['nunca']++;
    if ($f['situacao']['estado'] === 'diferente') $totais['diferentes']++;
    if ($f['sem_modelo']) $totais['sem_modelo']++;
}
unset($f);

$page_title    = 'Firmware';
$current_route = 'firmwares';
include __DIR__ . '/../web/layout_base.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Firmware</h1>
        <p class="page-sub">
            A versão que cada equipamento reporta e as URLs de atualização por modelo.
            O comando <code>UPDATE</code> é o mesmo em toda a linha JC — o que muda é o pacote.
        </p>
    </div>
</div>

<?php if ($erro): ?><div class="card" style="margin-bottom:16px"><p style="color:var(--error);margin:0;font-size:13px"><?= htmlspecialchars($erro) ?></p></div><?php endif; ?>
<?php if ($ok):   ?><div class="card" style="margin-bottom:16px"><p style="color:var(--success);margin:0;font-size:13px"><?= htmlspecialchars($ok) ?></p></div><?php endif; ?>

<div class="card" style="margin-bottom:16px">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin:0 0 6px">A URL é por MODELO</h4>
    <p style="font-size:12px;color:var(--muted);margin:0">
        Mandar para um JC182 a URL do pacote do JC371 <strong>não devolve erro</strong>: o
        equipamento aceita, baixa e aplica. Por isso a URL é cadastro com o modelo na chave,
        e não campo digitado a cada envio. A comparação de versões é por
        <strong>igualdade</strong> com a de referência — não há regra publicada que ordene
        <code>V1.8.0.9_250807</code> contra <code>V4.3.2</code>, então a tela não afirma
        "desatualizado".
    </p>
</div>

<!-- ── KPIs ─────────────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:16px">
    <div class="card"><div style="font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:600">Equipamentos ativos</div>
        <div class="text-mono" style="font-size:24px;font-weight:600"><?= (int)$totais['total'] ?></div></div>
    <div class="card"><div style="font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:600">Com versão lida</div>
        <div class="text-mono" style="font-size:24px;font-weight:600"><?= (int)$totais['lidos'] ?></div></div>
    <div class="card"><div style="font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:600">Nunca lidos</div>
        <div class="text-mono" style="font-size:24px;font-weight:600"><?= (int)$totais['nunca'] ?></div></div>
    <div class="card"><div style="font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:600">Diferentes da referência</div>
        <div class="text-mono" style="font-size:24px;font-weight:600"><?= (int)$totais['diferentes'] ?></div></div>
    <div class="card"><div style="font-size:11px;text-transform:uppercase;color:var(--muted);font-weight:600">Sem modelo cadastrado</div>
        <div class="text-mono" style="font-size:24px;font-weight:600;<?= $totais['sem_modelo'] ? 'color:var(--error)' : '' ?>"><?= (int)$totais['sem_modelo'] ?></div>
        <div style="font-size:10px;color:var(--muted)">não recebem UPDATE</div></div>
</div>

<!-- ── Frota ────────────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 style="font-size:13px;font-weight:600;margin:0">Frota</h4>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <form method="get" style="display:flex;gap:8px;align-items:center">
                <select name="customer_id" onchange="this.form.submit()"
                        style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm)">
                    <option value="">Todos os clientes</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (string)$filtroCust === (string)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <button class="btn btn-outline btn-sm" onclick="lerTodos()">Ler versão de todos</button>
        </div>
    </div>

    <div class="table-wrap">
    <table id="tbl-frota">
        <thead><tr>
            <th>Equipamento</th><th>Modelo</th><th>Cliente</th>
            <th>Firmware</th><th>Lido em</th><th>Referência</th><th>Situação</th>
            <th style="text-align:center">Ações</th>
        </tr></thead>
        <tbody>
        <?php if (!$frota): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--muted)">Nenhum equipamento ativo.</td></tr>
        <?php else: foreach ($frota as $f):
            $cor = ['igual' => 'badge-success', 'diferente' => 'badge-warning',
                    'sem_modelo' => 'badge-error',
                    'sem_leitura' => '', 'sem_release' => ''][$f['situacao']['estado']] ?? '';
        ?>
            <tr data-imei="<?= htmlspecialchars($f['imei']) ?>" data-model="<?= (int)$f['device_model_id'] ?>">
                <td><?= htmlspecialchars($f['device_name']) ?>
                    <div class="text-mono" style="font-size:11px;color:var(--muted)"><?= htmlspecialchars($f['imei']) ?></div></td>
                <td><?= htmlspecialchars($f['model_name']) ?></td>
                <td><?= htmlspecialchars($f['customer_name']) ?></td>
                <td class="text-mono fw-cell" style="font-size:12px">
                    <?= $f['firmware_version'] !== null && $f['firmware_version'] !== '' ? htmlspecialchars($f['firmware_version']) : '—' ?>
                    <?php if ($f['firmware_source'] === 'manual'): ?>
                    <span class="badge" style="font-size:9px" title="Digitado no cadastro, não lido do equipamento">manual</span>
                    <?php endif; ?>
                </td>
                <td class="text-mono fw-when" style="font-size:12px;color:var(--muted)">
                    <?= $f['firmware_checked_at'] ? fmt_brt($f['firmware_checked_at'], 'd/m H:i') : 'nunca' ?>
                </td>
                <td class="text-mono" style="font-size:12px;color:var(--muted)">
                    <?= $f['ref_version'] ? htmlspecialchars($f['ref_version']) : '—' ?>
                </td>
                <td><span class="badge <?= $cor ?>" style="font-size:10px"><?= htmlspecialchars($f['situacao']['rotulo']) ?></span>
                    <div style="font-size:10px;color:var(--muted)"><?= htmlspecialchars($f['presenca']['rotulo']) ?></div></td>
                <td style="text-align:center">
                    <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                        <button class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px"
                                onclick="lerVersao('<?= htmlspecialchars($f['imei']) ?>', this)">Ler versão</button>
                        <?php if ($f['sem_modelo']): ?>
                        <button class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px" disabled
                                title="Cadastre o modelo do equipamento em /equipamentos — sem ele não há como saber qual pacote é o certo.">Atualizar</button>
                        <?php else: ?>
                        <button class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px"
                                onclick="abrirUpdate('<?= htmlspecialchars($f['imei']) ?>', <?= (int)$f['device_model_id'] ?>, '<?= htmlspecialchars(addslashes($f['model_name'])) ?>')">Atualizar</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
    <div id="fw-feedback" style="font-size:12px;margin-top:10px"></div>
</div>

<!-- ── Cadastro de URLs ─────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:16px">
    <h4 style="font-size:13px;font-weight:600;margin-bottom:12px">URLs de atualização</h4>
    <div class="table-wrap">
    <table id="tbl-releases">
        <thead><tr><th>Modelo</th><th>Versão</th><th>URL</th><th>Observação</th><th>Referência</th><th></th></tr></thead>
        <tbody>
        <?php if (!$releases): ?>
            <tr><td colspan="6" style="color:var(--muted);font-size:13px;padding:20px">
                Nenhuma URL cadastrada — sem ela o botão <strong>Atualizar</strong> não tem para onde apontar.
            </td></tr>
        <?php else: foreach ($releases as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['model_name']) ?></td>
                <td class="text-mono" style="font-size:12px"><?= htmlspecialchars($r['version']) ?></td>
                <td class="text-mono" style="font-size:11px;word-break:break-all;max-width:340px"><?= htmlspecialchars($r['url']) ?></td>
                <td style="font-size:12px;color:var(--muted)"><?= htmlspecialchars($r['notes'] ?? '—') ?></td>
                <td><?= (int)$r['is_current'] ? '<span class="badge badge-success" style="font-size:10px">referência</span>' : '—' ?></td>
                <td style="white-space:nowrap">
                    <a href="/firmwares?editar=<?= (int)$r['id'] ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px">Editar</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Desativar esta URL?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="remover_release">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px">Desativar</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <form method="post" style="margin-top:16px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar_release">
        <input type="hidden" name="id" value="<?= $editando ? (int)$editando['id'] : '' ?>">
        <div class="form-group" style="margin:0"><label>Modelo *</label>
            <select name="device_model_id" required>
                <?php foreach ($modelos as $m): ?>
                <option value="<?= (int)$m['id'] ?>" <?= $editando && (int)$editando['device_model_id'] === (int)$m['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['model_name']) ?> (<?= htmlspecialchars($m['protocol']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0"><label>Versão *</label>
            <input type="text" name="version" required placeholder="V1.8.1.2_250904"
                   value="<?= $editando ? htmlspecialchars($editando['version']) : '' ?>"
                   style="font-family:'JetBrains Mono',monospace"></div>
        <div class="form-group" style="margin:0;flex:1;min-width:280px"><label>URL do pacote *</label>
            <input type="url" name="url" required placeholder="https://…"
                   value="<?= $editando ? htmlspecialchars($editando['url']) : '' ?>"
                   style="width:100%;font-family:'JetBrains Mono',monospace"></div>
        <div class="form-group" style="margin:0"><label>Observação</label>
            <input type="text" name="notes" placeholder="opcional"
                   value="<?= $editando ? htmlspecialchars($editando['notes'] ?? '') : '' ?>"></div>
        <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:8px">
            <input type="checkbox" name="is_current" value="1" <?= $editando && (int)$editando['is_current'] ? 'checked' : '' ?>>
            versão de referência do modelo
        </label>
        <button class="btn btn-primary"><?= $editando ? 'Salvar' : 'Cadastrar' ?></button>
        <?php if ($editando): ?><a href="/firmwares" class="btn btn-outline">Cancelar</a><?php endif; ?>
    </form>
    <p style="font-size:11px;color:var(--muted);margin:10px 0 0">
        A URL não pode conter vírgula nem <code>#</code>: são os separadores do comando de texto
        (proNo 128) e o equipamento receberia o endereço partido.
    </p>
</div>

<!-- ── Modal de atualização ─────────────────────────────────────────────── -->
<div id="upd-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999;align-items:center;justify-content:center">
    <div class="card" style="width:520px;max-width:94vw">
        <h3 style="font-size:16px;font-weight:600;margin-bottom:6px">Atualizar firmware</h3>
        <p style="font-size:12px;color:var(--muted);margin-bottom:14px">
            Envia <code>UPDATE,&lt;url&gt;#</code> ao equipamento. Ele baixa e aplica o pacote —
            <strong>não há confirmação de qual pacote é</strong>, então a URL precisa ser a do modelo certo.
        </p>
        <div class="form-group"><label>Equipamento</label>
            <input type="text" id="upd-imei" readonly class="text-mono" style="font-family:'JetBrains Mono',monospace"></div>
        <div class="form-group"><label>Pacote cadastrado para <span id="upd-modelo"></span></label>
            <select id="upd-sel" onchange="escolherUrl()"></select></div>
        <div class="form-group"><label>URL</label>
            <input type="text" id="upd-url" placeholder="https://…" style="font-family:'JetBrains Mono',monospace"></div>
        <div style="font-size:11px;color:var(--muted);margin-bottom:12px">
            Comando: <code id="upd-preview" class="text-mono">—</code>
        </div>
        <div id="upd-erro" style="font-size:12px;color:var(--error);margin-bottom:10px"></div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
            <button class="btn btn-outline btn-sm" onclick="fecharUpdate()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="enviarUpdate()">Enviar UPDATE</button>
        </div>
    </div>
</div>

<script>
var URLS = <?= json_encode($urlsPorModelo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

/**
 * Dispara `VERSION#` e mostra o que voltou.
 *
 * A gravação em `devices.firmware_version` acontece no servidor
 * (`firmware_capture()`), não aqui: a mesma resposta chega pelo caminho
 * síncrono e pelo callback offline, e duplicar a regra no JS faria a versão
 * gravada depender de qual tela estava aberta.
 */
function lerVersao(imei, btn) {
    if (btn) { btn.disabled = true; btn.textContent = 'lendo…'; }
    return fetch('/sendcommand', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify({ imei: imei, content: 'VERSION#', proNo: 128, serverFlagId: 1 })
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
        var ok = j && (j.code === 0 || j.code === 200);
        if (btn) { btn.disabled = false; btn.textContent = 'Ler versão'; }
        feedback(imei + ': ' + (ok ? 'VERSION# enviado — a resposta é gravada assim que chegar (recarregue em instantes).'
                                   : ((j && j.msg) || 'falhou')), ok);
        return ok;
    })
    .catch(function () {
        if (btn) { btn.disabled = false; btn.textContent = 'Ler versão'; }
        feedback(imei + ': erro de rede.', false);
        return false;
    });
}

/**
 * Lê a versão de todos os equipamentos listados.
 *
 * ⚠️ Em série, não em paralelo. O IoT Hub recusa comando novo enquanto o
 * anterior do MESMO device não voltou (`Device busy`), e uma frota inteira
 * disparada de uma vez também consome franquia de SIM em rajada. Um a um é
 * mais lento e é o que funciona.
 */
function lerTodos() {
    var linhas = Array.prototype.slice.call(document.querySelectorAll('tr[data-imei]'));
    if (!linhas.length || !confirm('Enviar VERSION# para ' + linhas.length + ' equipamento(s)?')) return;
    var i = 0;
    (function proximo() {
        if (i >= linhas.length) { feedback('Fim: ' + linhas.length + ' comando(s) despachado(s).', true); return; }
        var imei = linhas[i].dataset.imei;
        i++;
        lerVersao(imei, null).then(proximo);
    })();
}

function feedback(msg, ok) {
    var el = document.getElementById('fw-feedback');
    var linha = document.createElement('div');
    linha.style.color = ok ? 'var(--muted)' : 'var(--error)';
    linha.textContent = msg;
    el.insertBefore(linha, el.firstChild);
}

function abrirUpdate(imei, modelId, modelName) {
    document.getElementById('upd-imei').value = imei;
    document.getElementById('upd-modelo').textContent = modelName || 'este modelo';
    document.getElementById('upd-erro').textContent = '';
    var sel = document.getElementById('upd-sel');
    sel.innerHTML = '';
    var lista = URLS[modelId] || [];
    if (!lista.length) {
        sel.innerHTML = '<option value="">— nenhuma URL cadastrada para este modelo —</option>';
        document.getElementById('upd-url').value = '';
    } else {
        lista.forEach(function (r) {
            var o = document.createElement('option');
            o.value = r.u;
            o.textContent = r.v + (r.c ? '  (referência)' : '');
            sel.appendChild(o);
        });
        // A de referência é a escolha certa na esmagadora maioria das vezes;
        // deixá-la pré-selecionada evita o clique que erra o pacote.
        var ref = lista.filter(function (r) { return r.c; })[0] || lista[0];
        sel.value = ref.u;
        document.getElementById('upd-url').value = ref.u;
    }
    atualizarPreviewUpd();
    document.getElementById('upd-modal').style.display = 'flex';
}

function fecharUpdate() { document.getElementById('upd-modal').style.display = 'none'; }
function escolherUrl() {
    document.getElementById('upd-url').value = document.getElementById('upd-sel').value;
    atualizarPreviewUpd();
}
function atualizarPreviewUpd() {
    var u = document.getElementById('upd-url').value.trim();
    document.getElementById('upd-preview').textContent = u ? ('UPDATE,' + u + '#') : '—';
}
document.getElementById('upd-url').addEventListener('input', atualizarPreviewUpd);

function enviarUpdate() {
    var imei = document.getElementById('upd-imei').value;
    var url  = document.getElementById('upd-url').value.trim();
    var err  = document.getElementById('upd-erro');
    err.textContent = '';

    // Espelho da checagem do servidor (`firmware_url_problema()`). O servidor
    // continua sendo quem decide — isto é só para não gastar um envio.
    if (!/^https?:\/\//i.test(url)) { err.textContent = 'A URL precisa começar com http:// ou https://.'; return; }
    if (url.indexOf(',') >= 0)      { err.textContent = 'A URL não pode conter vírgula: é o separador do comando.'; return; }
    if (url.indexOf('#') >= 0)      { err.textContent = 'A URL não pode conter #: é o terminador do comando.'; return; }
    if (!confirm('Enviar UPDATE para ' + imei + '?\n\nO equipamento vai baixar e aplicar o pacote.\nConfira que a URL é a do modelo dele.')) return;

    fetch('/sendcommand', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify({ imei: imei, content: 'UPDATE,' + url + '#', proNo: 128, serverFlagId: 1 })
    })
    .then(function (r) { return r.json(); })
    .then(function (j) {
        var ok = j && (j.code === 0 || j.code === 200);
        feedback(imei + ': ' + (ok ? 'UPDATE enviado' + (j.command_id ? ' (#' + j.command_id + ')' : '') +
                                     ' — acompanhe em /comandos.'
                                   : ((j && j.msg) || 'falhou')), ok);
        if (ok) fecharUpdate(); else err.textContent = (j && j.msg) || 'Falha no envio.';
    })
    .catch(function () { err.textContent = 'Erro de rede.'; });
}
</script>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
