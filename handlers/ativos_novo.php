<?php
/**
 * JIMI Webhook System — Cadastro de Equipamento v3.1.0
 * Endpoint: /ativos/novo
 *
 * GET  → exibe formulário de cadastro
 * POST → valida e insere novo dispositivo
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$customer_id = get_current_customer_id();
$db = Database::getInstance()->getConnection();

$models = $db->query("SELECT id, model_name, protocol, camera_count FROM device_models ORDER BY protocol, model_name")->fetchAll(PDO::FETCH_ASSOC);

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome        = trim($_POST['device_name'] ?? '');
    $imei        = trim($_POST['imei'] ?? '');
    $modelo_id   = (int)($_POST['device_model_id'] ?? 0);
    $cameras     = (int)($_POST['camera_count'] ?? 1);
    $ativacao    = trim($_POST['activation_date'] ?? '');

    if (!$nome || !$imei || !$modelo_id) {
        $error = 'Preencha todos os campos obrigatórios (Nome, IMEI e Modelo).';
    } elseif (!preg_match('/^\d{15,17}$/', $imei)) {
        $error = 'IMEI inválido. Deve conter 15 a 17 dígitos.';
    } elseif ($cameras < 1 || $cameras > 16) {
        $error = 'Quantidade de câmeras deve ser entre 1 e 16.';
    } else {
        $exists = $db->prepare("SELECT COUNT(*) FROM devices WHERE imei = ?");
        $exists->execute([$imei]);
        if ($exists->fetchColumn() > 0) {
            $error = 'Já existe um dispositivo cadastrado com este IMEI.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO devices (imei, device_name, customer_id, device_model_id, camera_count, activation_date, created_by, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $imei,
                $nome,
                $customer_id,
                $modelo_id,
                $cameras,
                $ativacao ?: null,
                $_SESSION['user_id']
            ]);
            $success = true;
        }
    }
}

$page_title    = 'Novo Dispositivo';
$current_route = 'ativos';

$extra_head = '<script>
document.addEventListener("DOMContentLoaded", () => {
    const modelSelect = document.getElementById("device_model_id");
    const cameraInput = document.getElementById("camera_count");
    const modelData = ' . json_encode($models) . ';

    modelSelect.addEventListener("change", () => {
        const selected = modelData.find(m => m.id == modelSelect.value);
        if (selected) {
            cameraInput.value = selected.camera_count;
        }
    });
});
</script>';

include __DIR__ . '/../web/layout_base.php';
?>

<?php if ($error): ?>
<div class="card mb-24" style="border-color:#fce4eb;background:#fef2f5;color:var(--error);font-size:13px">
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="card mb-24" style="border-color:#d4f0e2;background:#f0faf5;color:var(--success);font-size:13px">
    Dispositivo <strong><?= htmlspecialchars($imei) ?></strong> cadastrado com sucesso.
    <a href="/ativos/<?= urlencode($imei) ?>" style="color:var(--success);font-weight:500">Ver detalhes</a>
    &nbsp;|&nbsp;
    <a href="/ativos/novo" style="color:var(--success)">Cadastrar outro</a>
</div>
<?php endif; ?>

<div class="card" style="max-width:600px">
    <form method="post">
        <div class="form-row">
            <div class="form-group">
                <label for="device_name">Nome do Dispositivo *</label>
                <input type="text" id="device_name" name="device_name" required
                       placeholder="Ex: Câmera Veículo 01">
            </div>
            <div class="form-group">
                <label for="imei">IMEI *</label>
                <input type="text" id="imei" name="imei" required
                       placeholder="15 a 17 dígitos" pattern="\d{15,17}"
                       style="font-family:'JetBrains Mono',monospace">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="device_model_id">Modelo *</label>
                <select id="device_model_id" name="device_model_id" required>
                    <option value="">Selecione o modelo</option>
                    <?php
                    $curProto = '';
                    foreach ($models as $m):
                        if ($m['protocol'] !== $curProto):
                            $curProto = $m['protocol'];
                            if ($curProto !== 'JIMI'): ?>
                    <optgroup label="──────────────"></optgroup>
                            <?php endif; ?>
                    <optgroup label="Protocolo <?= $curProto ?>">
                        <?php endif; ?>
                        <option value="<?= $m['id'] ?>" data-protocol="<?= $m['protocol'] ?>" data-cameras="<?= $m['camera_count'] ?>">
                            <?= htmlspecialchars($m['model_name']) ?> (<?= $m['camera_count'] ?> câmera<?= $m['camera_count'] > 1 ? 's' : '' ?>)
                        </option>
                    <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
            <div class="form-group">
                <label for="camera_count">Quantidade de Câmeras</label>
                <input type="number" id="camera_count" name="camera_count" value="1" min="1" max="16">
            </div>
        </div>
        <div class="form-group">
            <label for="activation_date">Data de Ativação</label>
            <input type="date" id="activation_date" name="activation_date">
        </div>
        <div class="flex-between mt-16">
            <a href="/ativos" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">Cadastrar Dispositivo</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
