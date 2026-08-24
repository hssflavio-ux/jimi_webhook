<?php
/**
 * JIMI Webhook System — Cadastro de Equipamento v3.1.0
 * Endpoint: /ativos/novo
 *
 * GET  → exibe formulário de cadastro
 * POST → valida e insere novo dispositivo
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/vehicle_icons.php';
require_login();

$customer_id = get_customer_id();
$db = Database::getInstance()->getConnection();

$user      = get_jimi_user();
$is_admin  = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$customers = $is_admin ? report_customer_options($db) : [];

$models = $db->query("SELECT id, model_name, protocol, camera_count FROM device_models ORDER BY protocol, model_name")->fetchAll(PDO::FETCH_ASSOC);

$error   = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // RBAC ação fina (v4.2.0 — Fase B2)
    require_permission('ativos', 'create');
    $nome        = trim($_POST['device_name'] ?? '');
    $imei        = trim($_POST['imei'] ?? '');
    $modelo_id   = (int)($_POST['device_model_id'] ?? 0);
    $cameras     = (int)($_POST['camera_count'] ?? 1);
    $ativacao    = trim($_POST['activation_date'] ?? '');
    $vehicleType = trim($_POST['vehicle_type'] ?? '');
    $vehicleType = array_key_exists($vehicleType, VEHICLE_ICONS) ? $vehicleType : null;
    $simCardId   = (int)($_POST['sim_card_id'] ?? 0) ?: null;
    $chipWarning = null;

    // Dono do dispositivo — ver resolve_owner_customer_id(). Antes gravava
    // `$customer_id` cru: sessão sem cliente virava dispositivo com customer_id
    // NULL, salvo com "cadastrado com sucesso" e invisível em /ativos depois.
    $owner_id = resolve_owner_customer_id($_POST['customer_id'] ?? null, $is_admin, $customer_id);

    if (!$nome || !$imei || !$modelo_id) {
        $error = 'Preencha todos os campos obrigatórios (Nome, IMEI e Modelo).';
    } elseif ($owner_id === null) {
        $error = 'Selecione o cliente do dispositivo. Sua sessão está sem cliente definido — salvar assim deixaria o dispositivo sem vínculo e invisível na lista de Ativos.';
    } elseif (!preg_match('/^\d{15,17}$/', $imei)) {
        $error = 'IMEI inválido. Deve conter 15 a 17 dígitos.';
    } elseif ($cameras < 1 || $cameras > 16) {
        $error = 'Quantidade de câmeras deve ser entre 1 e 16.';
    } else {
        // O gateway auto-cria a linha do device (customer_id NULL) assim que ele
        // transmite telemetria, ANTES do cadastro. O cadastro deve ADOTAR essa
        // linha órfã (e reativar soft-deletados do próprio cliente), não recusá-la.
        $existsStmt = $db->prepare("SELECT id, customer_id, is_active FROM devices WHERE imei = ? LIMIT 1");
        $existsStmt->execute([$imei]);
        $existing = $existsStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing && !is_null($existing['customer_id']) && (int)$existing['customer_id'] !== (int)$owner_id) {
            // Multi-tenant: IMEI de outro cliente nunca é adotável por aqui
            $error = 'Este IMEI já está vinculado a outro cliente. Contate o administrador.';
        } elseif ($existing && !is_null($existing['customer_id']) && (int)$existing['is_active'] === 1) {
            $error = 'Já existe um dispositivo ativo cadastrado com este IMEI (veja na lista de Ativos).';
        } elseif ($existing) {
            // Órfão do gateway ou soft-deletado deste cliente → adota/reativa
            $stmt = $db->prepare("
                UPDATE devices
                SET device_name = ?, customer_id = ?, device_model_id = ?, camera_count = ?,
                    activation_date = ?, vehicle_type = ?, created_by = ?, is_active = 1
                WHERE id = ?
            ");
            $stmt->execute([
                $nome,
                $owner_id,
                $modelo_id,
                $cameras,
                $ativacao ?: null,
                $vehicleType,
                $_SESSION['user_id'],
                $existing['id']
            ]);
            $chipWarning = link_sim_card_to_device($db, $simCardId, $imei, $owner_id);
            $success = true;
        } else {
            $stmt = $db->prepare("
                INSERT INTO devices (imei, device_name, customer_id, device_model_id, camera_count, activation_date, vehicle_type, created_by, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $imei,
                $nome,
                $owner_id,
                $modelo_id,
                $cameras,
                $ativacao ?: null,
                $vehicleType,
                $_SESSION['user_id']
            ]);
            $chipWarning = link_sim_card_to_device($db, $simCardId, $imei, $owner_id);
            $success = true;
        }
    }
}

// Chips LIVRES (v4.10.4) — o cadastro de equipamento é onde o chip já
// existente no estoque é escolhido, nunca digitado aqui. Só entram os que
// ainda não estão vinculados a nenhum IMEI (ver link_sim_card_to_device()).
// Roda DEPOIS do bloco de POST de propósito: se o request acabou de linkar
// um chip, a lista tem de refletir isso na mesma resposta — buscar antes do
// POST mostraria o chip recém-consumido como livre até o próximo reload.
$chipWhere = $is_admin ? '1=1' : 's.customer_id = :cid';
$chipParams = $is_admin ? [] : [':cid' => $customer_id];
$chipsStmt = $db->prepare("
    SELECT s.id, s.carrier, s.msisdn, s.iccid, c.name AS customer_name
    FROM sim_cards s LEFT JOIN customers c ON c.id = s.customer_id
    WHERE $chipWhere AND s.is_active = 1 AND (s.imei IS NULL OR s.imei = '')
    ORDER BY s.carrier, s.msisdn
");
$chipsStmt->execute($chipParams);
$freeChips = $chipsStmt->fetchAll(PDO::FETCH_ASSOC);

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
            // camera_count do modelo é o MÁXIMO de canais; o valor é o default
            cameraInput.value = selected.camera_count;
            cameraInput.max = selected.camera_count;
        }
    });

    const vtHidden = document.getElementById("vehicle_type");
    document.querySelectorAll(".vehicle-type-opt").forEach((btn) => {
        btn.addEventListener("click", () => {
            vtHidden.value = btn.dataset.value;
            document.querySelectorAll(".vehicle-type-opt").forEach((b) => {
                b.classList.remove("active");
                b.style.borderColor = "var(--hairline)";
                b.style.background = "var(--canvas)";
                b.style.color = "var(--body)";
            });
            btn.classList.add("active");
            btn.style.borderColor = "var(--primary)";
            btn.style.background = "var(--primary-soft)";
            btn.style.color = "var(--primary)";
        });
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
<?php if (!empty($chipWarning)): ?>
<div class="card mb-24" style="border-color:#ffe082;background:#fff8e1;color:#7c5a00;font-size:13px">
    <?= htmlspecialchars($chipWarning) ?>
</div>
<?php endif; ?>

<div class="card" style="max-width:600px">
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="form-group">
                <label for="device_name">Placa *</label>
                <input type="text" id="device_name" name="device_name" required
                       placeholder="Ex: ABC1D23, Frota 07, Câmera Veículo 01">
                <small style="display:block;margin-top:4px;font-size:11px;color:var(--muted);line-height:1.45">
                    Texto livre: placa, número de frota ou apelido — o sistema não exige formato.
                    É por este texto que o veículo aparece em todas as telas.
                </small>
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
        <div class="form-row">
            <?php if ($is_admin): ?>
            <div class="form-group">
                <label for="customer_id">Cliente *</label>
                <select id="customer_id" name="customer_id" required>
                    <option value="">— Selecione o cliente —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (string)$customer_id === (string)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-group">
                <label for="activation_date">Data de Ativação</label>
                <input type="date" id="activation_date" name="activation_date">
            </div>
        </div>
        <div class="form-group">
            <label>Tipo de Veículo</label>
            <small style="display:block;margin-bottom:8px;font-size:11px;color:var(--muted);line-height:1.45">
                Usado só para desenhar o ícone do veículo no mapa de Rastreamento
                (a cor do pin continua indicando o estado). Opcional.
            </small>
            <input type="hidden" id="vehicle_type" name="vehicle_type" value="<?= htmlspecialchars($_POST['vehicle_type'] ?? '') ?>">
            <div id="vehicle-type-picker" style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php
                $vtSelected = $_POST['vehicle_type'] ?? '';
                $vtOptions = ['' => 'Não informado'] + array_map(fn($v) => $v['label'], VEHICLE_ICONS);
                foreach ($vtOptions as $vtKey => $vtLabel):
                ?>
                <button type="button" class="vehicle-type-opt <?= $vtSelected === $vtKey ? 'active' : '' ?>"
                        data-value="<?= htmlspecialchars($vtKey) ?>"
                        style="display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 12px;min-width:76px;
                               border:1px solid <?= $vtSelected === $vtKey ? 'var(--primary)' : 'var(--hairline)' ?>;
                               border-radius:var(--radius-sm);background:<?= $vtSelected === $vtKey ? 'var(--primary-soft)' : 'var(--canvas)' ?>;
                               color:<?= $vtSelected === $vtKey ? 'var(--primary)' : 'var(--body)' ?>;cursor:pointer;">
                    <?= $vtKey === '' ? '<span style="width:24px;height:24px;display:flex;align-items:center;justify-content:center;">—</span>' : vehicle_icon_svg($vtKey, 'currentColor', 24) ?>
                    <span style="font-size:11px;"><?= htmlspecialchars($vtLabel) ?></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="sim_card_id">Chip (SIM)</label>
                <select id="sim_card_id" name="sim_card_id">
                    <option value="">— Nenhum —</option>
                    <?php foreach ($freeChips as $chip):
                        $chipLabel = chip_label($chip);
                        if ($is_admin && $chip['customer_name']) $chipLabel .= ' (' . $chip['customer_name'] . ')';
                    ?>
                    <option value="<?= $chip['id'] ?>" <?= (string)($_POST['sim_card_id'] ?? '') === (string)$chip['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($chipLabel) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small style="display:block;margin-top:4px;font-size:11px;color:var(--muted);line-height:1.45">
                    Só aparecem chips ainda sem equipamento vinculado.
                    <?= empty($freeChips) ? 'Nenhum chip livre agora — cadastre um em <a href="/chips">Chips</a>.' : '' ?>
                </small>
            </div>
        </div>
        <div class="flex-between mt-16">
            <a href="/ativos" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">Cadastrar Dispositivo</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
