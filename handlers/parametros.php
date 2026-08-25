<?php
/**
 * bycamera — Parâmetros (área dedicada) v4.9.16
 * Endpoint: /parametros
 *
 * Reúne num único lugar as três funções de parametrização das câmeras JT/T,
 * que antes estavam espalhadas: a leitura por equipamento vivia numa aba
 * dentro de `/ativos/{imei}`, o relatório de frota em Relatórios e os perfis
 * em Cadastros. Quem administra configuração de câmera tinha de conhecer três
 * caminhos que não se referenciavam.
 *
 * 🔒 ADMINISTRADOR APENAS, e a trava é `require_admin()` — não `can()`.
 * `can()` devolve **true** para todo usuário SEM grupo de permissão
 * (`get_user_permissions()` retorna null → "sem restrição"), que é exatamente
 * o estado de todos os usuários deste banco hoje. Uma tela que escreve
 * configuração em equipamento em operação não pode depender de uma checagem
 * que é permissiva por omissão.
 *
 * A entrada em `$screens` (grupos_permissao.php) existe assim mesmo, porque
 * tela fora da matriz é tela que o admin não consegue sequer enxergar para
 * conceder — o defeito que este repo já pagou quatro vezes.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$db         = Database::getInstance()->getConnection();
$customerId = (int)get_customer_id();

$erro = null;
$equipamentos = [];
$totais = ['devices' => 0, 'lidos' => 0, 'nunca' => 0];

try {
    // Só JT/T: os comandos 33027/33028/33030 são da seção 2 da doc
    // (`msgClass=1`) e câmera JIMI não os entende (ADR-001). Listar JIMI aqui
    // ofereceria uma ação que falharia sempre.
    $st = $db->prepare("
        SELECT d.imei, d.device_name, m.model_name, m.protocol,
               d.last_communication, d.params_synced_at,
               (SELECT COUNT(*) FROM device_params dp
                 JOIN device_param_catalog c ON c.param_no = dp.param_no
                WHERE dp.imei = d.imei AND dp.channel = 0 AND c.is_hidden = 0) AS params,
               (SELECT MAX(dp2.read_at) FROM device_params dp2 WHERE dp2.imei = d.imei) AS ultima_leitura
          FROM devices d
          JOIN device_models m ON m.id = d.device_model_id
         WHERE m.protocol = 'JTT'
           AND (:cid = 0 OR d.customer_id = :cid2)
           AND d.is_active = 1
         ORDER BY (params = 0) DESC, m.model_name, d.device_name
    ");
    $st->execute([':cid' => $customerId, ':cid2' => $customerId]);
    $equipamentos = $st->fetchAll(PDO::FETCH_ASSOC);

    $totais['devices'] = count($equipamentos);
    foreach ($equipamentos as $e) {
        if ((int)$e['params'] > 0) $totais['lidos']++; else $totais['nunca']++;
    }
} catch (Throwable $e) {
    $erro = 'Não foi possível listar os equipamentos: ' . $e->getMessage();
    Logger::error('parametros: falha ao listar', ['erro' => $e->getMessage()]);
}

$page_title    = 'Parâmetros';
$current_route = 'parametros';
include __DIR__ . '/../web/layout_base.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Parâmetros</h1>
        <p class="page-sub">Configuração das câmeras JT/T — leitura, comparação com o perfil do modelo e escrita.</p>
    </div>
</div>

<!-- v4.13.0 — pausa temporária: os comandos JT/T 33027 (escrita) e 33028/33030
     (leitura) não funcionam no firmware atual do fabricante. Nada foi
     apagado — código, rotas e as três tabelas continuam intactos; é reversível
     assim que o fabricante corrigir. Comandos de ADAS/DMS/velocidade (proNo
     128) saíram desta área — ver /configuracoes-ia. -->
<div class="card mb-16" style="padding:12px 16px;border-left:3px solid #f5a623;font-size:13px;color:var(--muted);">
    ⚠️ <strong>Parametrização JT/T pausada</strong> — os comandos 33027 (escrita) e 33028/33030
    (leitura) não estão funcionando no firmware atual do fabricante. A tela continua aqui
    para consulta do que já foi lido antes, mas leitura e escrita novas ficam suspensas até
    a correção. Configuração de ADAS/DMS/velocidade agora mora em
    <a href="/configuracoes-ia">Configurações IA</a>.
</div>

<?php if ($erro): ?>
<div class="card"><p style="color:var(--danger);font-size:13px;margin:0"><?= htmlspecialchars($erro) ?></p></div>
<?php else: ?>

<!-- Atalhos para as outras duas funções da área -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:20px">
    <a href="/relatorios/parametros" class="card" style="text-decoration:none;display:block">
        <h4 style="font-size:13px;font-weight:600;color:var(--ink);margin:0 0 4px">Parâmetros da Frota</h4>
        <p style="font-size:12px;color:var(--muted);margin:0">
            Compara cada câmera com o perfil do <strong>modelo</strong> e lista as divergências —
            responde "quais estão sem limite de velocidade?".
        </p>
    </a>
    <a href="/config-parametros" class="card" style="text-decoration:none;display:block">
        <h4 style="font-size:13px;font-weight:600;color:var(--ink);margin:0 0 4px">Perfis de Parâmetros</h4>
        <p style="font-size:12px;color:var(--muted);margin:0">
            Define a configuração-padrão <strong>por modelo</strong> e a aplica em lote,
            enviando só o que diverge.
        </p>
    </a>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:12px">
        <h4 style="font-size:13px;font-weight:600;color:var(--ink);margin:0">Equipamentos JT/T</h4>
        <span style="font-size:12px;color:var(--muted)">
            <span class="mono"><?= $totais['devices'] ?></span> equipamento(s) ·
            <span class="mono"><?= $totais['lidos'] ?></span> com configuração lida ·
            <span class="mono"><?= $totais['nunca'] ?></span> nunca lido(s)
        </span>
    </div>

    <?php if (!$equipamentos): ?>
        <p style="font-size:13px;color:var(--muted);margin:0">
            Nenhuma câmera JT/T neste cliente. Câmeras JIMI não aparecem aqui de propósito:
            os comandos de parâmetro são do protocolo JT/T e elas não os entendem.
        </p>
    <?php else: ?>
    <table class="tbl" style="width:100%">
        <thead><tr>
            <th>Equipamento</th><th style="width:90px">Modelo</th><th style="width:150px">IMEI</th>
            <th style="width:110px">Parâmetros</th><th style="width:160px">Última leitura</th>
            <th style="width:110px"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($equipamentos as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['device_name'] ?: '—') ?></td>
                <td><span class="badge" style="background:#eef4fa;color:#5a7fa8"><?= htmlspecialchars($e['model_name']) ?></span></td>
                <td class="mono" style="font-size:11px"><?= htmlspecialchars($e['imei']) ?></td>
                <td class="mono">
                    <?php if ((int)$e['params'] > 0): ?>
                        <?= (int)$e['params'] ?>
                    <?php else: ?>
                        <span style="color:var(--muted)">—</span>
                    <?php endif; ?>
                </td>
                <td style="font-size:11px;color:var(--muted)">
                    <?= $e['ultima_leitura'] ? fmt_brt($e['ultima_leitura']) : 'nunca lida' ?>
                </td>
                <td style="text-align:right">
                    <a class="btn btn-outline btn-sm" href="/ativos/<?= urlencode($e['imei']) ?>?tab=parametros">Abrir</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p style="font-size:11px;color:var(--muted);margin:12px 0 0">
        Cada modelo devolve um conjunto próprio — o JC371 reporta 45 parâmetros exibíveis
        e o JC181, 6. Quantidade diferente é firmware, não falha de leitura.
    </p>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
