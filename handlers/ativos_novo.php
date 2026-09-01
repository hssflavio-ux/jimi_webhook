<?php
/**
 * JIMI Webhook System — Cadastro de Veículo v4.11.0
 * Endpoint: /ativos/novo
 *
 * Fase 1 do fluxo chip→câmera→veículo: cadastra só o VEÍCULO (placa, tipo,
 * cliente). A câmera é cadastrada à parte em /equipamentos (com seu chip) e
 * instalada aqui depois, na tela de detalhe do veículo (/ativos/{id}) — nunca
 * no mesmo formulário, porque a instalação exige uma câmera que já tenha
 * chip vinculado.
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

$error   = null;
$success = null;
$newVehicleId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    require_permission('ativos', 'create');
    $plate       = trim($_POST['plate'] ?? '');
    $vehicleType = trim($_POST['vehicle_type'] ?? '');
    $vehicleType = array_key_exists($vehicleType, VEHICLE_ICONS) ? $vehicleType : null;

    // Dono do veículo — ver resolve_owner_customer_id(). Sessão sem cliente
    // definido recusa em vez de gravar customer_id NULL/errado.
    $owner_id = resolve_owner_customer_id($_POST['customer_id'] ?? null, $is_admin, $customer_id);

    if (!$plate) {
        $error = 'Placa é obrigatória.';
    } elseif ($owner_id === null) {
        $error = 'Selecione o cliente do veículo. Sua sessão está sem cliente definido — salvar assim deixaria o veículo sem vínculo e invisível na lista de Ativos.';
    } else {
        $stmt = $db->prepare("
            INSERT INTO vehicles (customer_id, plate, vehicle_type, created_by, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$owner_id, $plate, $vehicleType, $_SESSION['user_id'] ?? null]);
        $newVehicleId = (int)$db->lastInsertId();
        audit_log('vehicle.create', 'vehicle', $newVehicleId, null,
            ['plate' => $plate, 'vehicle_type' => $vehicleType, 'customer_id' => $owner_id]);
        $success = true;
    }
}

$page_title    = 'Novo Veículo';
$current_route = 'ativos';

$extra_head = '<script>
document.addEventListener("DOMContentLoaded", () => {
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
    Veículo cadastrado com sucesso.
    <a href="/ativos/<?= $newVehicleId ?>" style="color:var(--success);font-weight:500">Instalar uma câmera nele agora</a>
    &nbsp;|&nbsp;
    <a href="/ativos/novo" style="color:var(--success)">Cadastrar outro</a>
</div>
<?php endif; ?>

<div class="card" style="max-width:600px">
    <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="plate">Placa *</label>
            <input type="text" id="plate" name="plate" required
                   placeholder="Ex: ABC1D23, Frota 07, Câmera Veículo 01">
            <small style="display:block;margin-top:4px;font-size:11px;color:var(--muted);line-height:1.45">
                Texto livre: placa, número de frota ou apelido — o sistema não exige formato.
                É por este texto que o veículo aparece em todas as telas.
            </small>
        </div>

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

        <div class="flex-between mt-16">
            <a href="/ativos" class="btn btn-outline">Cancelar</a>
            <button type="submit" class="btn btn-primary">Cadastrar Veículo</button>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../web/layout_base_close.php'; ?>
