<?php
/**
 * JIMI Webhook System — Perfis de Parâmetros v4.9.14
 * Endpoint: /config-parametros
 *
 * Perfil de configuração **por modelo** de câmera, e a aplicação dele em lote.
 *
 * ⚠️ POR MODELO, NÃO POR CLIENTE, e isso é medição e não opinião: em
 * 12/08/2026 o JC371 devolveu 49 parâmetros e o JC181, **6**, com conjuntos
 * diferentes. Um perfil por cliente compararia os dois e, pior, aplicaria ao
 * JC181 parâmetros que ele não tem. O cliente pode sobrepor o perfil do modelo;
 * a base é sempre o modelo.
 *
 * 🔴 A APLICAÇÃO É DIFF-ONLY. Reenviar todos os parâmetros para mudar um
 * sobrescreve os corretos com o que o perfil ACHA que eles deveriam ser —
 * inclusive ajustes feitos de propósito naquele veículo. Ver
 * `param_profile_diff()`.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/device_params.php';
// 🔒 v4.9.16 — a parametrização virou área de administrador. `require_admin()`
// ANTES do require_permission de propósito: `can()` é permissivo por omissão
// (usuário sem grupo passa em tudo), e esta tela aplica configuração em lote
// a equipamento em operação.
require_admin();
require_permission('config-parametros', 'view');

$db         = Database::getInstance()->getConnection();
$customerId = (int)get_customer_id();
$user       = get_jimi_user();
$ehAdmin    = ($user['role'] ?? '') === 'admin';

$erro = $ok = null;

// ── Ações ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'salvar_perfil') {
            require_permission('config-parametros', $_POST['id'] ? 'edit' : 'create');
            $modelId = (int)$_POST['device_model_id'];
            $nome    = trim($_POST['name'] ?? '');
            $escopo  = ($_POST['escopo'] ?? 'modelo') === 'cliente' ? $customerId : null;
            if ($nome === '' || !$modelId) throw new Exception('Informe nome e modelo.');

            // Resolvido por SELECT dedicado, não por lastInsertId(): no ramo
            // ON DUPLICATE KEY UPDATE (perfil já existia), lastInsertId()
            // devolve 0, não o id da linha afetada.
            $existia = $db->prepare("SELECT id FROM param_profiles WHERE device_model_id = :m AND (customer_id = :c OR (customer_id IS NULL AND :c IS NULL))");
            $existia->execute([':m' => $modelId, ':c' => $escopo]);
            $beforeId = $existia->fetchColumn();

            $st = $db->prepare("INSERT INTO param_profiles (name, device_model_id, customer_id)
                                VALUES (:n, :m, :c)
                                ON DUPLICATE KEY UPDATE name = VALUES(name), is_active = 1");
            $st->execute([':n' => $nome, ':m' => $modelId, ':c' => $escopo]);

            $existia->execute([':m' => $modelId, ':c' => $escopo]);
            $profileId = (int)$existia->fetchColumn();
            audit_log($beforeId ? 'param_profile.update' : 'param_profile.create', 'param_profile', $profileId,
                $beforeId ? ['id' => (int)$beforeId] : null, ['name' => $nome, 'device_model_id' => $modelId, 'customer_id' => $escopo]);
            $ok = 'Perfil salvo.';

        } elseif ($acao === 'salvar_valor') {
            require_permission('config-parametros', 'edit');
            $pid = (int)$_POST['profile_id'];
            $no  = (int)$_POST['param_no'];
            $val = trim($_POST['value'] ?? '');

            // 🔴 A trava do gravável é AQUI, no servidor. Perfil é o caminho
            // pelo qual um valor chega a MUITAS câmeras de uma vez — deixar
            // entrar um parâmetro que ninguém sabe nomear multiplicaria o erro.
            $cat = param_catalog($db);
            if (empty($cat[$no]['writable'])) {
                throw new Exception("Parâmetro {$no} não é gravável — só entra em perfil o que o catálogo sabe nomear.");
            }
            $beforeVal = $db->prepare("SELECT value FROM param_profile_values WHERE profile_id = :p AND param_no = :n AND channel = 0");
            $beforeVal->execute([':p' => $pid, ':n' => $no]);
            $beforeValue = $beforeVal->fetchColumn();

            $db->prepare("INSERT INTO param_profile_values (profile_id, param_no, channel, value)
                          VALUES (:p, :n, 0, :v)
                          ON DUPLICATE KEY UPDATE value = VALUES(value)")
               ->execute([':p' => $pid, ':n' => $no, ':v' => $val]);
            audit_log('param_profile_value.set', 'param_profile', $pid,
                $beforeValue !== false ? ['param_no' => $no, 'value' => $beforeValue] : null,
                ['param_no' => $no, 'value' => $val]);
            $ok = 'Valor do perfil salvo.';

        } elseif ($acao === 'remover_valor') {
            require_permission('config-parametros', 'delete');
            $pid = (int)$_POST['profile_id'];
            $no  = (int)$_POST['param_no'];
            $beforeVal = $db->prepare("SELECT value FROM param_profile_values WHERE profile_id = :p AND param_no = :n AND channel = 0");
            $beforeVal->execute([':p' => $pid, ':n' => $no]);
            $beforeValue = $beforeVal->fetchColumn();

            $db->prepare("DELETE FROM param_profile_values WHERE profile_id = :p AND param_no = :n")
               ->execute([':p' => $pid, ':n' => $no]);
            if ($beforeValue !== false) {
                audit_log('param_profile_value.remove', 'param_profile', $pid, ['param_no' => $no, 'value' => $beforeValue], null);
            }
            $ok = 'Valor removido do perfil.';
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

// ── Dados ───────────────────────────────────────────────────────────────────
$modelos = $db->query("SELECT id, model_name, protocol FROM device_models
                        WHERE protocol = 'JTT' ORDER BY model_name")->fetchAll(PDO::FETCH_ASSOC);

$perfis = $db->prepare("
    SELECT p.*, dm.model_name, c.name AS customer_name,
           (SELECT COUNT(*) FROM param_profile_values v WHERE v.profile_id = p.id) AS n_valores
      FROM param_profiles p
      JOIN device_models dm ON dm.id = p.device_model_id
      LEFT JOIN customers c ON c.id = p.customer_id
     WHERE p.customer_id IS NULL OR p.customer_id = :cid
     ORDER BY dm.model_name, (p.customer_id IS NULL) DESC");
$perfis->execute([':cid' => $customerId]);
$perfis = $perfis->fetchAll(PDO::FETCH_ASSOC);

$catalogo = param_catalog($db);
$gravaveis = array_filter($catalogo, fn($c) => !empty($c['writable']));

$perfilAberto = null;
$valoresPerfil = [];
$impacto = [];
if (!empty($_GET['perfil'])) {
    foreach ($perfis as $p) if ((int)$p['id'] === (int)$_GET['perfil']) $perfilAberto = $p;
    if ($perfilAberto) {
        $st = $db->prepare("SELECT param_no, channel, value FROM param_profile_values
                             WHERE profile_id = :p ORDER BY param_no");
        $st->execute([':p' => $perfilAberto['id']]);
        $valoresPerfil = $st->fetchAll(PDO::FETCH_ASSOC);

        // Impacto: o que MUDARIA em cada equipamento do modelo, sem aplicar.
        // Mostrar antes é o que separa "aplicar perfil" de "apertar e ver".
        $dev = $db->prepare("SELECT imei, COALESCE(NULLIF(device_name,''), imei) AS rotulo
                               FROM devices WHERE device_model_id = :m AND customer_id = :c AND is_active = 1");
        $dev->execute([':m' => $perfilAberto['device_model_id'], ':c' => $customerId]);
        foreach ($dev->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $impacto[$d['imei']] = ['rotulo' => $d['rotulo']]
                                 + param_profile_diff($db, $d['imei'], (int)$perfilAberto['id']);
        }
    }
}

$page_title    = 'Perfis de Parâmetros';
$current_route = 'config-parametros';
include __DIR__ . '/../web/layout_base.php';
?>

<!-- v4.13.0 — pausa temporária: ver a mesma nota em handlers/parametros.php.
     O bloqueio de verdade é em handlers/sendcommand.php (33027/33028/33030) —
     este aviso só explica por que "Aplicar" vai recusar. -->
<div class="card mb-16" style="padding:12px 16px;border-left:3px solid #f5a623;font-size:13px;color:var(--muted);">
    ⚠️ <strong>Parametrização JT/T pausada</strong> — 33027 (escrita) e 33028/33030 (leitura)
    não funcionam no firmware atual do fabricante. Perfis continuam editáveis aqui, mas
    "Aplicar" vai recusar o envio até a correção.
</div>

<?php if ($erro): ?><div class="card" style="margin-bottom:16px"><p style="color:var(--danger);margin:0;font-size:13px"><?= htmlspecialchars($erro) ?></p></div><?php endif; ?>
<?php if ($ok): ?><div class="card" style="margin-bottom:16px"><p style="color:var(--success);margin:0;font-size:13px"><?= htmlspecialchars($ok) ?></p></div><?php endif; ?>

<div class="card" style="margin-bottom:16px">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin:0 0 6px">Perfil é por MODELO</h4>
    <p style="font-size:12px;color:var(--muted);margin:0">
        Um JC371 devolve 49 parâmetros e um JC181, <strong>6</strong>, com conjuntos
        diferentes — perfil único por cliente aplicaria a um modelo parâmetros que ele
        não tem. O cliente pode sobrepor o perfil padrão do modelo.
        A aplicação envia <strong>só a diferença</strong>: reenviar tudo sobrescreveria
        ajustes corretos feitos naquele veículo.
    </p>
</div>

<div class="card" style="margin-bottom:16px">
    <h4 style="font-size:13px;font-weight:600;margin-bottom:12px">Perfis</h4>
    <table class="tbl" style="width:100%">
        <thead><tr><th>Modelo</th><th>Nome</th><th>Escopo</th><th>Parâmetros</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($perfis as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['model_name']) ?></td>
                <td><?= htmlspecialchars($p['name']) ?></td>
                <td><?= $p['customer_id'] === null ? 'padrão do modelo' : htmlspecialchars($p['customer_name'] ?? 'cliente') ?></td>
                <td class="mono"><?= (int)$p['n_valores'] ?></td>
                <td><a href="/config-parametros?perfil=<?= (int)$p['id'] ?>">abrir</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$perfis): ?><tr><td colspan="5" style="color:var(--muted);font-size:13px">Nenhum perfil.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <form method="post" style="margin-top:16px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar_perfil">
        <input type="hidden" name="id" value="">
        <div class="form-group" style="margin:0"><label>Modelo</label>
            <select name="device_model_id">
                <?php foreach ($modelos as $m): ?>
                <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['model_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0"><label>Nome</label>
            <input type="text" name="name" placeholder="Padrão da frota" required></div>
        <div class="form-group" style="margin:0"><label>Escopo</label>
            <select name="escopo">
                <option value="modelo">Padrão do modelo</option>
                <option value="cliente">Só deste cliente</option>
            </select>
        </div>
        <button class="btn btn-primary">Criar / atualizar</button>
    </form>
</div>

<?php if ($perfilAberto): ?>
<div class="card" style="margin-bottom:16px">
    <h4 style="font-size:13px;font-weight:600;margin-bottom:12px">
        <?= htmlspecialchars($perfilAberto['name']) ?> — <?= htmlspecialchars($perfilAberto['model_name']) ?>
    </h4>
    <table class="tbl" style="width:100%">
        <thead><tr><th style="width:60px">Nº</th><th>Parâmetro</th><th>Valor desejado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($valoresPerfil as $v): $no = (int)$v['param_no']; ?>
            <tr>
                <td class="mono" style="color:var(--muted)"><?= $no ?></td>
                <td><?= htmlspecialchars(param_label($catalogo, $no)) ?>
                    <?php if (!empty($catalogo[$no]['is_network'])): ?>
                        <span style="font-size:10px;color:var(--danger)">· rede — volta só por SMS</span>
                    <?php endif; ?>
                </td>
                <td class="mono"><?= htmlspecialchars($v['value']) ?></td>
                <td>
                    <form method="post" style="display:inline" onsubmit="return confirm('Remover do perfil?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="acao" value="remover_valor">
                        <input type="hidden" name="profile_id" value="<?= (int)$perfilAberto['id'] ?>">
                        <input type="hidden" name="param_no" value="<?= $no ?>">
                        <button class="btn btn-outline btn-sm">remover</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" style="margin-top:16px;display:flex;gap:8px;align-items:end;flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="acao" value="salvar_valor">
        <input type="hidden" name="profile_id" value="<?= (int)$perfilAberto['id'] ?>">
        <div class="form-group" style="margin:0"><label>Parâmetro (só graváveis)</label>
            <select name="param_no">
                <?php foreach ($gravaveis as $no => $c): ?>
                <option value="<?= (int)$no ?>"><?= $no ?> — <?= htmlspecialchars($c['name_pt']) ?><?= !empty($c['is_network']) ? ' (rede)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0"><label>Valor</label>
            <input type="text" name="value" required></div>
        <button class="btn btn-primary">Adicionar ao perfil</button>
    </form>
</div>

<div class="card">
    <h4 style="font-size:13px;font-weight:600;margin-bottom:6px">O que mudaria (simulação)</h4>
    <p style="font-size:12px;color:var(--muted);margin:0 0 12px">
        Nada é enviado nesta tela. Ver o impacto antes é o que separa "aplicar perfil"
        de "apertar e ver" — a aplicação é feita equipamento a equipamento, pela aba
        <strong>Parâmetros</strong> do ativo.
    </p>
    <table class="tbl" style="width:100%">
        <thead><tr><th>Equipamento</th><th>Já iguais</th><th>Mudariam</th><th>Sem leitura</th></tr></thead>
        <tbody>
        <?php foreach ($impacto as $im => $i): ?>
            <tr>
                <td><a href="/ativos/<?= urlencode($im) ?>?tab=parametros"><?= htmlspecialchars($i['rotulo']) ?></a></td>
                <td class="mono"><?= (int)$i['iguais'] ?></td>
                <td>
                    <?php if (!$i['alterar']): ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    <?php foreach ($i['alterar'] as $a): ?>
                        <div style="font-size:12px">
                            <span class="mono"><?= $a['param_no'] ?></span>
                            <?= htmlspecialchars(param_label($catalogo, $a['param_no'])) ?>:
                            <span class="mono"><?= htmlspecialchars($a['de']) ?> → <?= htmlspecialchars($a['para']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </td>
                <td style="font-size:11px;color:var(--muted)">
                    <?= $i['sem_leitura'] ? implode(', ', $i['sem_leitura']) . ' (o modelo pode não ter)' : '—' ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$impacto): ?><tr><td colspan="4" style="color:var(--muted);font-size:13px">Nenhum equipamento deste modelo neste cliente.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
