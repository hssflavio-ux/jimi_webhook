<?php
/**
 * JIMI Webhook System — Configuração de Dispositivos v3.1.0
 * Endpoint: /config
 *
 * Consulta e define parâmetros de dispositivos via proNo 33027-33031.
 * Modelo-sensível: detecta protocolo automaticamente.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_customer_id();
$db = Database::getInstance()->getConnection();
$dashToken = getenv('WEBHOOK_TOKEN') ?: 'a12341234123';

$devicesStmt = $db->prepare("
    SELECT d.imei, d.device_name, COALESCE(dm.model_name, d.device_model, '-') AS model_display,
           COALESCE(dm.protocol, 'JIMI') AS protocol
    FROM devices d
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    WHERE d.customer_id = :cid
    ORDER BY d.device_name
");
$devicesStmt->execute([':cid' => $customer_id]);
$devices = $devicesStmt->fetchAll(PDO::FETCH_ASSOC);

$page_title    = 'Configuração';
$current_route = 'config';

include __DIR__ . '/../web/layout_base.php';
?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
    <!-- Consultar -->
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">Consultar Dispositivo</h4>
        <div class="form-group">
            <label>Dispositivo</label>
            <select id="cfg-imei-query">
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>" data-protocol="<?= $d['protocol'] ?>">
                    <?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?> (<?= htmlspecialchars($d['model_display']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Tipo de Consulta</label>
            <select id="cfg-query-type" onchange="document.getElementById('cfg-specific-group').style.display=this.value==='33030'?'block':'none'">
                <option value="33031">Informações do Dispositivo (33031)</option>
                <option value="33028">Todos os Parâmetros (33028)</option>
                <option value="33030">Parâmetros Específicos (33030)</option>
                <option value="34818">Mídia Armazenada (34818)</option>
            </select>
        </div>
        <div class="form-group" id="cfg-specific-group" style="display:none">
            <label>IDs dos Parâmetros (separados por vírgula)</label>
            <input type="text" id="cfg-param-ids" placeholder="Ex: 1,2,3,4">
        </div>
        <button class="btn btn-primary" onclick="queryDevice()">Consultar</button>
        <div id="cfg-query-result" style="margin-top:12px;font-size:13px;max-height:400px;overflow-y:auto"></div>
    </div>

    <!-- Definir -->
    <div class="card">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:16px">Definir Parâmetro</h4>
        <div class="form-group">
            <label>Dispositivo</label>
            <select id="cfg-imei-set">
                <?php foreach ($devices as $d): ?>
                <option value="<?= $d['imei'] ?>"><?= htmlspecialchars($d['device_name'] ?? $d['imei']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>ID do Parâmetro</label>
                <input type="number" id="cfg-set-param-id" placeholder="Ex: 1">
            </div>
            <div class="form-group">
                <label>Valor</label>
                <input type="text" id="cfg-set-param-value" placeholder="Valor">
            </div>
        </div>
        <button class="btn btn-outline" onclick="setParam()">Definir (proNo 33027)</button>
        <div id="cfg-set-result" style="margin-top:12px;font-size:13px"></div>
    </div>

    <!-- Reset/Info -->
    <div class="card" style="grid-column:1/-1">
        <h4 style="font-size:14px;font-weight:600;color:var(--ink);margin-bottom:12px">Outras Ações</h4>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-outline btn-sm" onclick="resetDevice()">Reiniciar Terminal (33029)</button>
            <button class="btn btn-outline btn-sm" onclick="queryDeviceInfo()">Info Terminal (33031)</button>
        </div>
    </div>
</div>

<script>
var dashToken = <?= json_encode($dashToken) ?>;

function getQueryImei() { return document.getElementById('cfg-imei-query').value; }
function getSetImei() { return document.getElementById('cfg-imei-set').value; }

function sendCfg(imei, proNo, content) {
    return fetch('/sendcommand', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Dashboard-Token': dashToken, 'X-CSRF-Token': window.CSRF_TOKEN || '' },
        body: JSON.stringify({ imei: imei, proNo: proNo, content: content, serverFlagId: 0 })
    }).then(function(r) { return r.json(); });
}

function queryDevice() {
    var imei = getQueryImei();
    var proNo = parseInt(document.getElementById('cfg-query-type').value);
    var content = '{}';
    if (proNo === 33030) {
        var ids = document.getElementById('cfg-param-ids').value.trim();
        if (!ids) { alert('Informe os IDs dos parâmetros.'); return; }
        content = JSON.stringify({ paramIds: ids.split(',').map(function(s) { return parseInt(s.trim()); }) });
    }
    var el = document.getElementById('cfg-query-result');
    el.innerHTML = '<span style="color:var(--muted)">Consultando...</span>';
    sendCfg(imei, proNo, content).then(function(d) {
        el.innerHTML = '<pre style="font-family:JetBrains Mono,monospace;font-size:11px;background:var(--canvas);padding:10px;border-radius:var(--radius-sm);white-space:pre-wrap">' +
            JSON.stringify(d, null, 2) + '</pre>';
    });
}

function setParam() {
    var imei = getSetImei();
    var id = parseInt(document.getElementById('cfg-set-param-id').value);
    var val = document.getElementById('cfg-set-param-value').value;
    if (!id) { alert('Informe o ID do parâmetro.'); return; }
    var el = document.getElementById('cfg-set-result');
    el.innerHTML = '<span style="color:var(--muted)">Enviando...</span>';
    sendCfg(imei, 33027, JSON.stringify({ paramId: id, paramValue: val })).then(function(d) {
        el.innerHTML = '<pre style="font-family:JetBrains Mono,monospace;font-size:11px;background:var(--canvas);padding:10px;border-radius:var(--radius-sm)">' +
            JSON.stringify(d, null, 2) + '</pre>';
    });
}

function resetDevice() {
    if (!confirm('Confirmar reinicialização do terminal?')) return;
    var imei = getQueryImei();
    sendCfg(imei, 33029, '{}').then(function(d) {
        alert(JSON.stringify(d.msg || d));
    });
}

function queryDeviceInfo() {
    var imei = getQueryImei();
    var el = document.getElementById('cfg-query-result');
    el.innerHTML = '<span style="color:var(--muted)">Consultando...</span>';
    sendCfg(imei, 33031, '{}').then(function(d) {
        el.innerHTML = '<pre style="font-family:JetBrains Mono,monospace;font-size:11px;background:var(--canvas);padding:10px;border-radius:var(--radius-sm);white-space:pre-wrap">' +
            JSON.stringify(d, null, 2) + '</pre>';
    });
}
</script>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
