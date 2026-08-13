<?php
/**
 * JIMI Webhook System — Parâmetros da Frota v4.9.13
 * Rota: /relatorios/parametros
 *
 * Responde a pergunta que motivou o `PROJETO_PARAMETROS.md`: **quais câmeras
 * estão configuradas fora do padrão?** Antes desta tela não havia como saber —
 * dava para mandar comando e torcer, e só.
 *
 * ⚠️ O PADRÃO É A PRÓPRIA FROTA, POR MODELO, e isso não é preguiça de não fazer
 * perfil: é a única comparação que já vale hoje, sem ninguém cadastrar nada.
 * O valor de referência é a MODA (o mais comum) entre os equipamentos do MESMO
 * modelo. Comparar modelos diferentes entre si produziria dezenas de
 * divergências falsas — medido em 12/08/2026: o JC371 devolve 49 parâmetros e o
 * JC182, 47, com conjuntos diferentes. O perfil declarado por modelo é a F3.
 *
 * ⚠️ Modelo com UM equipamento só não tem padrão: a moda seria ele mesmo e o
 * relatório diria "tudo certo" sem ter comparado nada. Esses aparecem numa
 * seção própria, como "sem base de comparação" — silêncio aqui seria pior que
 * a ausência do dado, porque parece aprovação.
 *
 * Só JT/T: os comandos de parâmetro são da seção 2 da doc (ADR-001).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/device_params.php';

$page_title    = 'Parâmetros da Frota';
$current_route = 'rel_parametros';

$db         = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user       = get_jimi_user();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$ehAdminEstrito = ($user['role'] ?? '') === 'admin';

$filterCust = $_GET['customer_id'] ?? null;
$scopeCust  = report_customer_scope($filterCust, $isAdmin, $customerId);

$catalogo = param_catalog($db);

$where  = "d.is_active = 1 AND dm.protocol = 'JTT'";
$params = [];
if ($scopeCust !== null) { $where .= ' AND d.customer_id = :cid'; $params[':cid'] = $scopeCust; }

// ── Equipamentos no escopo, com o estado da leitura ─────────────────────────
$stmt = $db->prepare("
    SELECT d.imei, COALESCE(NULLIF(d.device_name,''), d.imei) AS rotulo,
           dm.model_name, d.params_synced_at, d.params_sync_tries, d.params_sync_next
      FROM devices d JOIN device_models dm ON dm.id = d.device_model_id
     WHERE {$where}
     ORDER BY dm.model_name, rotulo");
$stmt->execute($params);
$equipamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$porImei = [];
foreach ($equipamentos as $e) $porImei[$e['imei']] = $e;
$imeis = array_keys($porImei);

$naoLidos = array_values(array_filter($equipamentos, fn($e) => $e['params_synced_at'] === null));

// ── Valores lidos ───────────────────────────────────────────────────────────
$valores = [];
if ($imeis) {
    $ph = implode(',', array_fill(0, count($imeis), '?'));
    $q = $db->prepare("SELECT imei, param_no, channel, value_raw FROM device_params
                        WHERE imei IN ($ph)");
    $q->execute($imeis);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $modelo = $porImei[$r['imei']]['model_name'];
        $chave  = $r['param_no'] . ':' . $r['channel'];
        $valores[$modelo][$chave][$r['imei']] = $r['value_raw'];
    }
}

// ── Divergências ────────────────────────────────────────────────────────────
$divergencias = [];   // modelo => [ [param_no, channel, padrao, n_padrao, fora[]] ]
$semBase      = [];   // modelos com um único equipamento lido
foreach ($valores as $modelo => $porParam) {
    $lidos = [];
    foreach ($porParam as $vals) foreach (array_keys($vals) as $im) $lidos[$im] = true;
    if (count($lidos) < 2) { $semBase[$modelo] = array_keys($lidos); continue; }

    foreach ($porParam as $chave => $vals) {
        if (count($vals) < 2) continue;              // parâmetro visto em um só
        [$padrao, $n] = param_moda($vals);
        if ($padrao === null) continue;              // empate: sem padrão
        $fora = [];
        foreach ($vals as $im => $v) if ((string)$v !== $padrao) $fora[$im] = $v;
        if (!$fora) continue;
        [$no, $ch] = array_map('intval', explode(':', $chave));
        $divergencias[$modelo][] = [
            'param_no' => $no, 'channel' => $ch, 'padrao' => $padrao,
            'n_padrao' => $n, 'total' => count($vals), 'fora' => $fora,
        ];
    }
}

// ── Achados de operação (independem de comparação) ──────────────────────────
//
// Não é "fora do padrão": é errado mesmo que a frota inteira esteja assim —
// e é justamente aí que a moda não ajudaria. Medido no JC371: `85` = 0.
$achados = [];
foreach ($valores as $modelo => $porParam) {
    foreach (($porParam['85:0'] ?? []) as $im => $v) {
        if ((string)$v === '0') $achados[] = [$im, 85, 'Sem limite de velocidade configurado'];
    }
    foreach (($porParam['94:0'] ?? []) as $im => $v) {
        if ((string)$v === '0') $achados[] = [$im, 94, 'Ângulo de capotamento zerado — o alarme não dispara'];
    }
}

include __DIR__ . '/../web/layout_base.php';
?>

<div class="card" style="margin-bottom:16px">
    <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin:0 0 6px">Como este relatório compara</h4>
    <p style="font-size:12px;color:var(--muted);margin:0">
        O padrão é o valor <strong>mais comum entre os equipamentos do mesmo modelo</strong> —
        a própria frota é a referência, sem ninguém cadastrar nada. Modelos diferentes
        <strong>não</strong> são comparados entre si: um JC371 devolve 49 parâmetros e um
        JC182, 47, com conjuntos diferentes.
    </p>
</div>

<?php if ($achados): ?>
<div class="card" style="margin-bottom:16px">
    <h4 style="font-size:13px;font-weight:600;color:var(--danger);margin-bottom:12px">
        Achados de operação (<?= count($achados) ?>)
    </h4>
    <p style="font-size:11px;color:var(--muted);margin:-6px 0 12px">
        Estes não dependem de comparação — estariam errados mesmo que a frota inteira
        estivesse assim.
    </p>
    <table class="tbl" style="width:100%">
        <thead><tr><th>Equipamento</th><th style="width:60px">Nº</th><th>Parâmetro</th><th>O que foi encontrado</th></tr></thead>
        <tbody>
        <?php foreach ($achados as [$im, $no, $texto]): ?>
            <tr>
                <td><a href="/ativos/<?= urlencode($im) ?>?tab=parametros"><?= htmlspecialchars($porImei[$im]['rotulo']) ?></a></td>
                <td class="mono" style="color:var(--muted)"><?= $no ?></td>
                <td><?= htmlspecialchars(param_label($catalogo, $no)) ?></td>
                <td><?= htmlspecialchars($texto) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($divergencias): foreach ($divergencias as $modelo => $itens): ?>
<div class="card" style="margin-bottom:16px">
    <h4 style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:12px">
        <?= htmlspecialchars($modelo) ?> — <?= count($itens) ?> parâmetro(s) fora do padrão
    </h4>
    <table class="tbl" style="width:100%">
        <thead><tr>
            <th style="width:60px">Nº</th><th>Parâmetro</th><th>Padrão do modelo</th><th>Fora do padrão</th>
        </tr></thead>
        <tbody>
        <?php foreach ($itens as $d): ?>
            <tr>
                <td class="mono" style="color:var(--muted)"><?= $d['param_no'] ?><?= $d['channel'] ? '/c' . $d['channel'] : '' ?></td>
                <td><?= htmlspecialchars(param_label($catalogo, $d['param_no'])) ?></td>
                <td class="mono"><?= htmlspecialchars(param_format($catalogo, $d['param_no'], $d['padrao'], $ehAdminEstrito)) ?>
                    <span style="font-size:10px;color:var(--muted)">(<?= $d['n_padrao'] ?> de <?= $d['total'] ?>)</span></td>
                <td>
                    <?php foreach ($d['fora'] as $im => $v): ?>
                        <div style="font-size:12px">
                            <a href="/ativos/<?= urlencode($im) ?>?tab=parametros"><?= htmlspecialchars($porImei[$im]['rotulo']) ?></a>:
                            <span class="mono"><?= htmlspecialchars(param_format($catalogo, $d['param_no'], (string)$v, $ehAdminEstrito)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; else: ?>
<div class="card" style="margin-bottom:16px">
    <p style="font-size:13px;color:var(--muted);margin:0">
        Nenhuma divergência entre equipamentos do mesmo modelo.
    </p>
</div>
<?php endif; ?>

<?php if ($semBase): ?>
<div class="card" style="margin-bottom:16px">
    <h4 style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:6px">Sem base de comparação</h4>
    <p style="font-size:12px;color:var(--muted);margin:0 0 12px">
        Modelos com <strong>um único</strong> equipamento lido. Não há padrão a comparar —
        e dizer "tudo certo" aqui seria aprovar sem ter comparado nada.
    </p>
    <table class="tbl" style="width:100%">
        <thead><tr><th>Modelo</th><th>Equipamento</th></tr></thead>
        <tbody>
        <?php foreach ($semBase as $modelo => $ims): foreach ($ims as $im): ?>
            <tr>
                <td><?= htmlspecialchars($modelo) ?></td>
                <td><a href="/ativos/<?= urlencode($im) ?>?tab=parametros"><?= htmlspecialchars($porImei[$im]['rotulo']) ?></a></td>
            </tr>
        <?php endforeach; endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($naoLidos): ?>
<div class="card">
    <h4 style="font-size:13px;font-weight:600;color:var(--ink);margin-bottom:6px">
        Ainda não lidos (<?= count($naoLidos) ?>)
    </h4>
    <p style="font-size:12px;color:var(--muted);margin:0 0 12px">
        A leitura é automática (worker a cada 5 min). Equipamento offline recebe o
        comando em fila e responde quando reconecta — a coluna de tentativas mostra
        onde o backoff está.
    </p>
    <table class="tbl" style="width:100%">
        <thead><tr><th>Equipamento</th><th>Modelo</th><th>Tentativas</th><th>Próxima tentativa</th></tr></thead>
        <tbody>
        <?php foreach ($naoLidos as $e): ?>
            <tr>
                <td><a href="/ativos/<?= urlencode($e['imei']) ?>?tab=parametros"><?= htmlspecialchars($e['rotulo']) ?></a></td>
                <td><?= htmlspecialchars($e['model_name']) ?></td>
                <td class="mono"><?= (int)$e['params_sync_tries'] ?></td>
                <td class="mono" style="font-size:11px"><?= $e['params_sync_next'] ? fmt_brt($e['params_sync_next']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
