<?php
/**
 * JIMI Webhook System — Equipamentos v4.0.0
 * Rota: /equipamentos
 *
 * Grade CRUD + filtros + ações: Exportar, Cadastrar, Firmware, Importar.
 * Form com modelo*, IMEI*, chip, periféricos multi, rotação, watermark.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/fleet_state.php'; // DEFAULT_SPEED_LIMIT_KMH
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();
$user = get_jimi_user();
$isAdmin = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$message = '';
$messageType = '';

// ── POST: Create/Update ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // RBAC ação fina (v4.2.0 — Fase B2): import/create → create; edit → edit
    require_permission('equipamentos', in_array($action, ['import_batch', 'create'], true) ? 'create' : 'edit');

    // ── Batch Import ─────────────────────────────────────────
    if ($action === 'import_batch') {
        csrf_verify();
        $devicesJson = $_POST['devices'] ?? '';
        $devicesData = json_decode($devicesJson, true);
        // Dono resolvido UMA vez para o lote inteiro (ver resolve_owner_customer_id).
        $importOwner = resolve_owner_customer_id($_POST['customer_id'] ?? null, $isAdmin, $customerId);
        if (!is_array($devicesData) || empty($devicesData)) {
            $message = 'Nenhum dispositivo válido no arquivo.';
            $messageType = 'error';
        } elseif ($importOwner === null) {
            $message = 'Selecione o cliente antes de importar: sua sessão está sem cliente definido, e importar assim criaria equipamentos órfãos.';
            $messageType = 'error';
        } else {
            $imported = 0; $skipped = 0;
            $importErrors = [];
            // B6 (YUV): resolve Modelo por nome (case-insensitive) e Canais do CSV
            $modelMap = [];
            foreach ($db->query("SELECT id, model_name, camera_count FROM device_models")->fetchAll() as $m) {
                $modelMap[mb_strtolower(trim($m['model_name']))] = $m;
            }
            $checkStmt = $db->prepare("SELECT COUNT(*) FROM devices WHERE imei = :imei");
            $insertStmt = $db->prepare("
                INSERT INTO devices (imei, device_name, customer_id, is_active, streaming_rotation, streaming_watermark, firmware_version, camera_count, device_model_id)
                VALUES (:imei, :name, :cid, 1, 0, 0, :fw, :cam, :mid)
            ");
            foreach ($devicesData as $idx => $d) {
                $line = $idx + 2; // linha do CSV (1 = cabeçalho)
                $imei = trim($d['imei'] ?? '');
                $name = trim($d['name'] ?? '');
                if (!preg_match('/^\d{15,17}$/', $imei)) {
                    $skipped++; $importErrors[] = "linha $line: IMEI inválido"; continue;
                }
                $checkStmt->execute([':imei' => $imei]);
                if ($checkStmt->fetchColumn() > 0) {
                    $skipped++; $importErrors[] = "linha $line: IMEI $imei já cadastrado"; continue;
                }
                $modelKey = mb_strtolower(trim($d['model'] ?? ''));
                $model = $modelKey !== '' ? ($modelMap[$modelKey] ?? null) : null;
                if ($modelKey !== '' && !$model) {
                    $importErrors[] = "linha $line: modelo \"" . trim($d['model']) . "\" desconhecido (importado sem modelo)";
                }
                $channels = (int)($d['channels'] ?? 0);
                $insertStmt->execute([
                    ':imei' => $imei,
                    ':name' => $name ?: $imei,
                    ':cid'  => $importOwner,
                    ':fw'   => trim($d['firmware'] ?? '') ?: null,
                    ':cam'  => $channels > 0 ? $channels : (int)($model['camera_count'] ?? 1),
                    ':mid'  => $model['id'] ?? null,
                ]);
                $imported++;
            }
            $message = "$imported importado(s), $skipped ignorado(s).";
            if ($importErrors) {
                $message .= ' Avisos: ' . implode('; ', array_slice($importErrors, 0, 10))
                          . (count($importErrors) > 10 ? ' (+' . (count($importErrors) - 10) . ')' : '');
            }
            $messageType = $imported > 0 ? 'success' : 'warning';
        }
    }
    // ── Single Create/Update ─────────────────────────────────
    else {
    csrf_verify();
    $imei = trim($_POST['imei'] ?? '');
    // "Nome" é só um rótulo interno de estoque (ex.: "Câmera JC400AD #12") —
    // desde a Fase 1 do fluxo chip→câmera→veículo, "Placa" pertence ao
    // VEÍCULO (/ativos), não à câmera. Opcional; sem ele, cai no IMEI (mesmo
    // fallback do import em lote).
    $deviceName = trim($_POST['device_name'] ?? '');
    $modelId = !empty($_POST['device_model_id']) ? (int)$_POST['device_model_id'] : null;
    $simCardId = (int)($_POST['sim_card_id'] ?? 0) ?: null;
    $peripherals = $_POST['peripherals'] ?? [];
    $rotation = (int)($_POST['streaming_rotation'] ?? 0);
    $watermark = !empty($_POST['streaming_watermark']) ? 1 : 0;
    $firmware = trim($_POST['firmware_version'] ?? '');
    $branchId = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
    // Vazio e zero significam "herda do cliente" (v4.6.0). Gravar 0 faria todo
    // ponto do equipamento virar excesso de velocidade.
    $speedLimit = (isset($_POST['speed_limit_kmh']) && (int)$_POST['speed_limit_kmh'] > 0)
        ? (int)$_POST['speed_limit_kmh'] : null;
    $isActive = !empty($_POST['is_active']) ? 1 : ((($_POST['action'] ?? '') === 'create') ? 1 : 0);
    $cameraCount = (int)($_POST['camera_count'] ?? 1);
    $chipWarning = null;

    // Dono do equipamento: o <select> da tela para admin/revendedor, o cliente da
    // sessão para os demais. NULL = não dá para resolver → recusa (o cadastro
    // seguia com `?? 1`, gravando no tenant errado, ou com NULL, criando órfão).
    $ownerId = resolve_owner_customer_id($_POST['customer_id'] ?? null, $isAdmin, $customerId);

    if (empty($imei)) {
        $message = 'IMEI é obrigatório.';
        $messageType = 'error';
    } elseif ($ownerId === null) {
        $message = 'Selecione o cliente do equipamento. Sua sessão está sem cliente definido — salvar assim deixaria o equipamento sem vínculo e invisível nas telas.';
        $messageType = 'error';
    } else {
        $deviceName = $deviceName ?: $imei;
        try {
            $isNew = ($_POST['action'] ?? '') === 'create';
            if ($isNew) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM devices WHERE imei = :imei");
                $stmt->execute([':imei' => $imei]);
                if ($stmt->fetchColumn() > 0) {
                    $message = 'IMEI já cadastrado.';
                    $messageType = 'error';
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO devices (imei, device_name, customer_id, device_model_id, camera_count,
                            streaming_rotation, streaming_watermark, firmware_version, branch_id,
                            speed_limit_kmh, is_active, peripherals)
                        VALUES (:imei, :name, :cid, :mid, :cc, :rot, :wm, :fw, :bid, :spd, :act, :per)
                    ");
                    $stmt->execute([
                        ':imei' => $imei, ':name' => $deviceName, ':cid' => $ownerId,
                        ':mid' => $modelId, ':cc' => $cameraCount, ':rot' => $rotation,
                        ':wm' => $watermark, ':fw' => $firmware ?: null, ':bid' => $branchId,
                        ':spd' => $speedLimit,
                        ':act' => $isActive, ':per' => !empty($peripherals) ? json_encode($peripherals) : null,
                    ]);
                    // Câmera recém-criada nunca tem instalação — o vínculo de
                    // chip aqui é sempre seguro (não há cascade de desativação
                    // a considerar numa linha que acabou de nascer).
                    $chipWarning = link_sim_card_to_device($db, $simCardId, $imei, $ownerId);
                    $message = 'Equipamento cadastrado com sucesso.' . ($chipWarning ? ' ' . $chipWarning : '');
                    $messageType = 'success';
                }
            } else {
                $editImei = $_POST['edit_imei'] ?? $imei;
                // O UPDATE não gravava `customer_id`: equipamento que nascesse órfão
                // (ou no cliente errado) não tinha como ser consertado pela tela.
                // O WHERE também não tinha escopo — qualquer usuário editava o
                // equipamento de qualquer tenant sabendo o IMEI.
                $scopeSql = '';
                $scopeParams = [];
                $allowedIds = reseller_scope_ids();
                if (!$isAdmin) {
                    $scopeSql = ' AND customer_id = :scope';
                    $scopeParams[':scope'] = $customerId;
                } elseif ($allowedIds !== null) {
                    // Revendedor: só dentro do escopo dele. Escopo vazio = não edita nada.
                    $in = implode(',', array_map('intval', $allowedIds));
                    $scopeSql = $in !== '' ? " AND customer_id IN ($in)" : ' AND 1 = 0';
                }

                // Só desativa (is_active 1→0) câmera SEM instalação aberta — é
                // o mesmo princípio já aplicado a chip em chips.php. Desativar
                // em uso deixaria o veículo com uma câmera "fantasma".
                $wasActive = (bool)$db->query(
                    "SELECT is_active FROM devices WHERE imei = " . $db->quote($editImei)
                )->fetchColumn();
                $installed = $db->prepare("
                    SELECT 1 FROM device_installations di
                    JOIN devices d ON d.id = di.device_id
                    WHERE d.imei = ? AND di.removed_at IS NULL
                ");
                $installed->execute([$editImei]);
                $isInstalled = (bool)$installed->fetchColumn();

                if ($wasActive && !$isActive && $isInstalled) {
                    $message = 'Esta câmera está instalada num veículo — desinstale-a em /ativos antes de desativar.';
                    $messageType = 'error';
                } else {
                    // Instalada: dono deriva do veículo (readonly na tela) —
                    // o <select> de cliente do form vem desabilitado nesse
                    // caso e o hidden carrega o valor atual, então $ownerId
                    // já é o certo; não sobrescrevemos aqui.
                    $stmt = $db->prepare("
                        UPDATE devices SET device_name = :name, customer_id = :cid, device_model_id = :mid, camera_count = :cc,
                            streaming_rotation = :rot, streaming_watermark = :wm,
                            firmware_version = :fw, branch_id = :bid, speed_limit_kmh = :spd,
                            is_active = :act, peripherals = :per
                        WHERE imei = :imei" . $scopeSql . "
                    ");
                    $stmt->execute(array_merge([
                        ':name' => $deviceName, ':cid' => $ownerId, ':mid' => $modelId, ':cc' => $cameraCount,
                        ':rot' => $rotation, ':wm' => $watermark, ':fw' => $firmware ?: null,
                        ':bid' => $branchId, ':spd' => $speedLimit,
                        ':act' => $isActive, ':per' => !empty($peripherals) ? json_encode($peripherals) : null,
                        ':imei' => $editImei,
                    ], $scopeParams));
                    if ($stmt->rowCount() === 0) {
                        // 0 linhas = fora do escopo, ou nada mudou. Só o primeiro é erro,
                        // mas confundir "não é seu" com "salvo" é pior que o falso alarme.
                        $chk = $db->prepare("SELECT COUNT(*) FROM devices WHERE imei = :imei" . $scopeSql);
                        $chk->execute(array_merge([':imei' => $editImei], $scopeParams));
                        if ((int)$chk->fetchColumn() === 0) {
                            $message = 'Equipamento não encontrado no seu escopo de cliente.';
                            $messageType = 'error';
                        } else {
                            $message = 'Equipamento atualizado.';
                            $messageType = 'success';
                        }
                    } else {
                        // Desativou agora (estava ativa, câmera livre, confirmado
                        // acima): libera o chip — cascade pedido pelo dono do
                        // produto ("desativar a câmera libera o chip").
                        if ($wasActive && !$isActive) {
                            link_sim_card_to_device($db, null, $editImei, $ownerId);
                        } else {
                            $chipWarning = link_sim_card_to_device($db, $simCardId, $editImei, $ownerId);
                        }
                        $message = 'Equipamento atualizado.' . ($chipWarning ? ' ' . $chipWarning : '');
                        $messageType = 'success';
                    }
                }
            }
        } catch (Exception $e) {
            $message = 'Erro: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
    } // fi import_batch else
}

// ── Filters ─────────────────────────────────────────────────────
$filterCust   = $_GET['customer_id'] ?? null;
$filterModel  = $_GET['model_id'] ?? null;
$filterStatus = $_GET['filter_status'] ?? null;
$filterOnline = $_GET['filter_online'] ?? null;
$filterSearch = $_GET['search'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$where = 'WHERE 1=1';
$params = [];

// Escopo multi-tenant centralizado (v4.7.3) — ver report_customer_scope()
// `customer_id=none` é o filtro dos ÓRFÃOS (equipamento sem cliente): só faz
// sentido para quem enxerga além de um cliente, e é como o admin acha o que o
// cadastro antigo deixou sem vínculo para então corrigir pela tela de edição.
$semCliente = ($filterCust === 'none' && $isAdmin);
if ($semCliente) {
    $where .= ' AND d.customer_id IS NULL';
} else {
    $scopeCust = report_customer_scope($filterCust, $isAdmin, $customerId);
    if ($scopeCust !== null) {
        $where .= ' AND d.customer_id = :cid';
        $params[':cid'] = $scopeCust;
    }
}
if ($filterModel) {
    $where .= ' AND d.device_model_id = :mid';
    $params[':mid'] = (int)$filterModel;
}
if ($filterStatus !== null && $filterStatus !== '') {
    $where .= ' AND d.is_active = :st';
    $params[':st'] = (int)$filterStatus;
}
if ($filterOnline === '1') {
    $where .= ' AND TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5';
} elseif ($filterOnline === '0') {
    $where .= ' AND (TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) > 5 OR d.last_communication IS NULL)';
}
if ($filterSearch) {
    $where .= ' AND (d.imei LIKE :q OR d.device_name LIKE :q2)';
    $params[':q'] = "%$filterSearch%";
    $params[':q2'] = "%$filterSearch%";
}

// Export síncrono (padrão YUV §9.2): mesma query da grade, sem paginação
$export = $_GET['export'] ?? '';
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_permission('equipamentos', 'export');
    require_once __DIR__ . '/../includes/export_helper.php';
    $expRows = [];
    try {
        $expStmt = $db->prepare("
            SELECT d.imei, d.device_name, d.is_active, d.last_communication,
                   d.peripherals, d.firmware_version,
                   dm.model_name, c.name as customer_name,
                   sc.msisdn as chip_msisdn, ds.battery_level,
                   CASE WHEN TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5 THEN 1 ELSE 0 END as is_online
            FROM devices d
            LEFT JOIN device_models dm ON d.device_model_id = dm.id
            LEFT JOIN customers c ON c.id = d.customer_id
            LEFT JOIN sim_cards sc ON sc.imei = d.imei AND sc.is_active = 1
            LEFT JOIN device_statistics ds ON ds.imei = d.imei
            $where
            ORDER BY d.is_active DESC, d.device_name ASC
            LIMIT " . SYNC_EXPORT_MAX_ROWS);
        $expStmt->execute($params);
        while ($r = $expStmt->fetch()) {
            $periph = $r['peripherals'] ? implode(', ', (array)json_decode($r['peripherals'], true)) : '—';
            $expRows[] = [
                $r['imei'],
                $r['device_name'] ?? '—',
                $r['model_name'] ?? '—',
                $r['customer_name'] ?? '—',
                $r['chip_msisdn'] ?? '—',
                $r['last_communication'] ? fmt_brt($r['last_communication']) : '—',
                $r['battery_level'] !== null ? (int)$r['battery_level'] . '%' : '—',
                $r['firmware_version'] ?? '—',
                $periph,
                $r['is_online'] ? 'Online' : 'Offline',
                $r['is_active'] ? 'Ativo' : 'Inativo',
            ];
        }
    } catch (Exception $e) { /* tabelas v4 ausentes → export vazio */ }
    stream_export($export, 'equipamentos',
        ['IMEI', 'Nome', 'Modelo', 'Cliente', 'Chip', 'Último Heartbeat', 'Bateria', 'Firmware', 'Periféricos', 'Situação', 'Status'],
        $expRows, 'Equipamentos');
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM devices d $where");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

// B5 (YUV): Chip (sim_cards) e Bateria (device_statistics) na grade
try {
    $devicesStmt = $db->prepare("
        SELECT d.imei, d.device_name, d.device_model, d.is_active,
               d.last_communication, d.peripherals, d.streaming_rotation,
               d.streaming_watermark, d.firmware_version, d.branch_id,
               d.created_at,
               dm.model_name, COALESCE(NULLIF(d.camera_count, 0), dm.camera_count) AS camera_count, dm.protocol,
               c.name as customer_name,
               sc.msisdn as chip_msisdn,
               ds.battery_level,
               CASE WHEN TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5 THEN 1 ELSE 0 END as is_online
        FROM devices d
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        LEFT JOIN customers c ON c.id = d.customer_id
        LEFT JOIN sim_cards sc ON sc.imei = d.imei AND sc.is_active = 1
        LEFT JOIN device_statistics ds ON ds.imei = d.imei
        $where
        ORDER BY d.is_active DESC, d.device_name ASC
        LIMIT $perPage OFFSET $offset
    ");
    $devicesStmt->execute($params);
    $devices = $devicesStmt->fetchAll();
} catch (Exception $e) {
    // sim_cards/device_statistics ausentes (schema antigo) → grade sem chip/bateria
    $devicesStmt = $db->prepare("
        SELECT d.imei, d.device_name, d.device_model, d.is_active,
               d.last_communication, d.peripherals, d.streaming_rotation,
               d.streaming_watermark, d.firmware_version, d.branch_id,
               d.created_at,
               dm.model_name, COALESCE(NULLIF(d.camera_count, 0), dm.camera_count) AS camera_count, dm.protocol,
               c.name as customer_name,
               NULL as chip_msisdn, NULL as battery_level,
               CASE WHEN TIMESTAMPDIFF(MINUTE, d.last_communication, NOW()) <= 5 THEN 1 ELSE 0 END as is_online
        FROM devices d
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        LEFT JOIN customers c ON c.id = d.customer_id
        $where
        ORDER BY d.is_active DESC, d.device_name ASC
        LIMIT $perPage OFFSET $offset
    ");
    $devicesStmt->execute($params);
    $devices = $devicesStmt->fetchAll();
}

// Equipamentos que ficaram sem cliente — o rastro do cadastro que gravava
// customer_id NULL. Some da grade de todo mundo que não seja admin, então sem
// este aviso ninguém descobre que existem.
$orfaos = 0;
if ($isAdmin && reseller_scope_ids() === null) {
    try {
        $orfaos = (int)$db->query("SELECT COUNT(*) FROM devices WHERE customer_id IS NULL")->fetchColumn();
    } catch (Exception $e) { $orfaos = 0; }
}

// Dropdowns
$customers = report_customer_options($db);
$models = $db->query("SELECT id, model_name, protocol, camera_count FROM device_models ORDER BY model_name")->fetchAll();
$branches = [];
try {
    $branches = $db->query("SELECT id, name, customer_id FROM branches WHERE is_active=1 ORDER BY name")->fetchAll();
} catch (Exception $e) {}

// ── Edit mode ───────────────────────────────────────────────────
$editDevice = null;
$action = $_GET['action'] ?? '';
$editImei = $_GET['imei'] ?? '';
if ($action === 'editar' && $editImei) {
    // `SELECT *` não traz customer_name (a coluna é de `customers`), então o campo
    // "Cliente" do formulário caía sempre no cliente da SESSÃO — mostrando um dono
    // que podia não ser o do equipamento. E sem escopo, qualquer usuário abria o
    // equipamento de qualquer tenant pelo IMEI na URL.
    $editScope = '';
    $editParams = [':imei' => $editImei];
    $allowedIds = reseller_scope_ids();
    if (!$isAdmin) {
        $editScope = ' AND d.customer_id = :scope';
        $editParams[':scope'] = $customerId;
    } elseif ($allowedIds !== null) {
        $in = implode(',', array_map('intval', $allowedIds));
        $editScope = $in !== '' ? " AND d.customer_id IN ($in)" : ' AND 1 = 0';
    }
    $stmt = $db->prepare("
        SELECT d.*, c.name AS customer_name
        FROM devices d
        LEFT JOIN customers c ON c.id = d.customer_id
        WHERE d.imei = :imei" . $editScope);
    $stmt->execute($editParams);
    $editDevice = $stmt->fetch();
}

// Instalação corrente (Fase 1 do fluxo chip→câmera→veículo): trava a
// desativação e faz o campo "Cliente" virar somente leitura na tela (o dono
// passa a derivar do veículo — ver install_device_on_vehicle()).
$editInstalled = $editDevice ? get_open_installation_for_device($db, (int)$editDevice['id']) : null;

// Chips livres (mesmo padrão de /ativos/novo) — inclui o já vinculado a esta
// câmera na edição, senão ele "some" da lista ao reabrir o formulário.
$currentChipImei = $editDevice['imei'] ?? '';
$chipWhere = $isAdmin ? '1=1' : 's.customer_id = :cid';
$chipParams = $isAdmin ? [] : [':cid' => $customerId];
$chipParams[':cur'] = $currentChipImei;
$chipsStmt = $db->prepare("
    SELECT s.id, s.carrier, s.msisdn, s.iccid, c.name AS customer_name
    FROM sim_cards s LEFT JOIN customers c ON c.id = s.customer_id
    WHERE $chipWhere AND s.is_active = 1 AND (s.imei IS NULL OR s.imei = '' OR s.imei = :cur)
    ORDER BY s.carrier, s.msisdn
");
$chipsStmt->execute($chipParams);
$freeChips = $chipsStmt->fetchAll(PDO::FETCH_ASSOC);
$currentSimCardId = null;
if ($currentChipImei !== '') {
    $cc = $db->prepare("SELECT id FROM sim_cards WHERE imei = ?");
    $cc->execute([$currentChipImei]);
    $currentSimCardId = $cc->fetchColumn() ?: null;
}

$isForm = ($action === 'novo' || ($action === 'editar' && $editDevice));

$page_title = 'Equipamentos';
$current_route = 'equipamentos';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($message): ?>
<div class="toast toast-<?= $messageType ?> toast-show" style="position:fixed;bottom:24px;right:24px;z-index:9999;">
    <?= htmlspecialchars($message) ?>
</div>
<script>setTimeout(function(){var t=document.querySelector('.toast');if(t)t.style.display='none';},4000);</script>
<?php endif; ?>

<?php if ($isForm): ?>
<!-- ═══════════ FORM ═══════════ -->
<div class="card" style="max-width:800px;">
    <div class="flex-between mb-24">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">
            <?= $editDevice ? 'Editar Equipamento' : 'Cadastrar Equipamento' ?>
        </h2>
        <a href="/equipamentos" class="btn btn-outline btn-sm">Voltar</a>
    </div>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="<?= $editDevice ? 'update' : 'create' ?>">
        <?php if ($editDevice): ?>
        <input type="hidden" name="edit_imei" value="<?= htmlspecialchars($editDevice['imei']) ?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>IMEI *</label>
                <input type="text" name="imei" value="<?= htmlspecialchars($editDevice['imei'] ?? '') ?>"
                       <?= $editDevice ? 'readonly' : 'required' ?>
                       placeholder="IMEI do dispositivo"
                       class="text-mono" style="font-family:'JetBrains Mono',monospace;">
            </div>
            <div class="form-group">
                <label>Nome (rótulo interno)</label>
                <input type="text" name="device_name" value="<?= htmlspecialchars($editDevice['device_name'] ?? '') ?>"
                       placeholder="Ex: Câmera JC400AD #12 — opcional, cai no IMEI se vazio">
                <small style="display:block;margin-top:4px;font-size:11px;color:var(--muted);line-height:1.45">
                    Identifica a câmera no estoque — não é a placa do veículo. A placa é
                    cadastrada em <a href="/ativos">Ativos</a>, ao instalar esta câmera num veículo.
                </small>
            </div>
        </div>

        <?php if ($editInstalled): ?>
        <div class="card mb-16" style="border-color:#fdf3e8;background:#fffaf3;font-size:13px;">
            Instalada no veículo <strong><?= htmlspecialchars($editInstalled['plate']) ?></strong> desde
            <?= htmlspecialchars(fmt_brt($editInstalled['installed_at'])) ?>.
            Para trocar o dono ou desativar esta câmera, desinstale-a primeiro em
            <a href="/ativos">Ativos</a>.
        </div>
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label for="sim_card_id">Chip (SIM)</label>
                <select id="sim_card_id" name="sim_card_id">
                    <option value="">— Nenhum —</option>
                    <?php foreach ($freeChips as $chip):
                        $chipLabel = chip_label($chip);
                        if ($isAdmin && $chip['customer_name']) $chipLabel .= ' (' . $chip['customer_name'] . ')';
                    ?>
                    <option value="<?= $chip['id'] ?>" <?= (string)$currentSimCardId === (string)$chip['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($chipLabel) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small style="display:block;margin-top:4px;font-size:11px;color:var(--muted);line-height:1.45">
                    Só aparecem chips ativos ainda sem câmera vinculada (e o já vinculado a
                    esta, se houver).
                    <?= empty($freeChips) ? 'Nenhum chip livre agora — cadastre um em <a href="/chips">Chips</a>.' : '' ?>
                </small>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Modelo</label>
                <select name="device_model_id" onchange="onModelChange(this)">
                    <option value="">— Selecione —</option>
                    <?php foreach ($models as $m):
                        $sel = ($editDevice['device_model_id'] ?? '') == $m['id'] ? 'selected' : ''; ?>
                    <option value="<?= $m['id'] ?>" data-cam="<?= $m['camera_count'] ?>" <?= $sel ?>>
                        <?= htmlspecialchars($m['model_name']) ?> (<?= $m['protocol'] ?>, <?= $m['camera_count'] ?> câm.)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Canais (Câmeras)</label>
                <input type="number" name="camera_count" id="camera_count" min="1" max="8"
                       value="<?= $editDevice['camera_count'] ?? 1 ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Firmware</label>
                <input type="text" name="firmware_version" value="<?= htmlspecialchars($editDevice['firmware_version'] ?? '') ?>"
                       placeholder="Versão do firmware" class="text-mono" style="font-family:'JetBrains Mono',monospace;">
            </div>
            <div class="form-group">
                <label>Filial</label>
                <select name="branch_id">
                    <option value="">— Nenhuma —</option>
                    <?php foreach ($branches as $b):
                        $sel = ($editDevice['branch_id'] ?? '') == $b['id'] ? 'selected' : ''; ?>
                    <option value="<?= $b['id'] ?>" <?= $sel ?>><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Limite de velocidade (km/h)</label>
                <input type="number" name="speed_limit_kmh" min="1" max="300"
                       value="<?= htmlspecialchars((string)($editDevice['speed_limit_kmh'] ?? '')) ?>"
                       placeholder="Herda do cliente" class="text-mono" style="font-family:'JetBrains Mono',monospace;">
                <small class="text-muted" style="font-size:11px;">
                    Em branco = herda do cliente, e sem limite no cliente vale o padrão de
                    <?= DEFAULT_SPEED_LIMIT_KMH ?> km/h. Alimenta
                    <a href="/relatorios/velocidade">Excesso de Velocidade</a>.
                </small>
            </div>
        </div>

        <!-- Periféricos -->
        <div class="form-group">
            <label>Periféricos</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php
                $periOptions = ['GPS','WiFi','4G','Bluetooth','Sensor Temperatura','Sensor Combustível',
                    'Leitor RFID','Câmera Interna','Câmera Externa','Áudio','Display LED','Alarme Sonoro'];
                $currentPeri = $editDevice['peripherals'] ? json_decode($editDevice['peripherals'], true) : [];
                if (!is_array($currentPeri)) $currentPeri = [];
                foreach ($periOptions as $po):
                    $checked = in_array($po, $currentPeri) ? 'checked' : '';
                ?>
                <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;padding:4px 10px;border:1px solid var(--hairline);border-radius:20px;<?= $checked?'background:var(--primary-soft);border-color:var(--primary);':'' ?>">
                    <input type="checkbox" name="peripherals[]" value="<?= htmlspecialchars($po) ?>" <?= $checked ?> style="width:auto;">
                    <?= htmlspecialchars($po) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Streaming -->
        <div class="form-row">
            <div class="form-group">
                <label>Rotação do Streaming</label>
                <select name="streaming_rotation">
                    <?php foreach ([0, 90, 180, 270] as $deg):
                        $sel = (int)($editDevice['streaming_rotation'] ?? 0) === $deg ? 'selected' : ''; ?>
                    <option value="<?= $deg ?>" <?= $sel ?>><?= $deg ?>°</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="streaming_watermark" value="1"
                           <?= ($editDevice['streaming_watermark'] ?? 0) ? 'checked' : '' ?> style="width:auto;">
                    Marca d'água no streaming
                </label>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Cliente <?= $isAdmin ? '*' : '' ?></label>
                <?php if ($editInstalled): ?>
                <?php // Instalada: o dono deriva do veículo (install_device_on_vehicle()
                      // sincroniza isto) — editável só desinstalando primeiro. ?>
                <input type="text" value="<?= htmlspecialchars($editDevice['customer_name'] ?? '—') ?>" readonly
                       style="background:var(--canvas-soft);">
                <input type="hidden" name="customer_id" value="<?= (int)$editDevice['customer_id'] ?>">
                <small style="display:block;margin-top:4px;font-size:11px;color:var(--muted);">
                    Derivado do veículo instalado — desinstale em /ativos para trocar.
                </small>
                <?php elseif ($isAdmin): ?>
                <?php
                // Pré-seleção: o dono atual (edição) → o filtro da grade → o da sessão.
                // Sem seleção o POST é RECUSADO: era daqui que saía equipamento órfão.
                $selOwner = $editDevice['customer_id'] ?? ($filterCust !== null && $filterCust !== '' ? (int)$filterCust : $customerId);
                ?>
                <select name="customer_id" required>
                    <option value="">— Selecione o cliente —</option>
                    <?php foreach ($customers as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (string)$selOwner === (string)$c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (($editDevice['customer_id'] ?? null) === null && $editDevice): ?>
                <small class="text-muted" style="font-size:11px;color:var(--error);">
                    Este equipamento está <strong>sem cliente</strong>. Selecione o dono e salve para vinculá-lo.
                </small>
                <?php endif; ?>
                <?php else: ?>
                <input type="text" value="<?= htmlspecialchars($editDevice['customer_name'] ?? get_customer()['name'] ?? '—') ?>" readonly
                       style="background:var(--canvas-soft);">
                <?php endif; ?>
            </div>
            <div class="form-group" style="display:flex;align-items:flex-end;">
                <?php if ($editInstalled): ?>
                <label style="display:flex;align-items:center;gap:8px;color:var(--muted);" title="Desinstale a câmera do veículo em /ativos antes de desativar.">
                    <input type="checkbox" checked disabled style="width:auto;">
                    Equipamento Ativo (instalada — desinstale para desativar)
                </label>
                <input type="hidden" name="is_active" value="1">
                <?php else: ?>
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1"
                           <?= ($editDevice ? ($editDevice['is_active'] ?? 1) : 1) ? 'checked' : '' ?> style="width:auto;">
                    Equipamento Ativo
                </label>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-16">
            <button type="submit" class="btn btn-primary"><?= $editDevice ? 'Salvar Alterações' : 'Cadastrar Equipamento' ?></button>
        </div>
    </form>
</div>

<?php else: ?>
<!-- ═══════════ GRADE PRINCIPAL ═══════════ -->
<?php if ($orfaos > 0 && $filterCust !== 'none'): ?>
<div class="card mb-16" style="border-color:#fce4eb;background:#fef2f5;font-size:13px;">
    <strong><?= $orfaos ?></strong> equipamento<?= $orfaos > 1 ? 's' : '' ?> sem cliente vinculado —
    <?= $orfaos > 1 ? 'eles não aparecem' : 'ele não aparece' ?> em nenhuma tela com escopo de cliente.
    <a href="?customer_id=none" style="font-weight:500;">Ver e vincular</a>
</div>
<?php endif; ?>
<div class="flex-between mb-16" style="flex-wrap:wrap;gap:12px;">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">
        Equipamentos
        <span style="font-size:12px;color:var(--muted);font-weight:400;">(<?= $totalRows ?>)</span>
    </h2>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <?php $expQ = $_GET; unset($expQ['page'], $expQ['export'], $expQ['action']); $expBase = http_build_query($expQ); ?>
        <a href="?<?= $expBase ?>&export=xlsx" class="btn btn-outline btn-sm">Exportar Excel</a>
        <a href="?<?= $expBase ?>&export=pdf" class="btn btn-outline btn-sm">Exportar PDF</a>
        <a href="?action=novo" class="btn btn-primary btn-sm">+ Cadastrar</a>
        <button class="btn btn-outline btn-sm" onclick="showFirmwareModal()">Atualizar Firmware</button>
        <button class="btn btn-outline btn-sm" onclick="showImportModal()">Importar em Lote</button>
    </div>
</div>

<!-- Filters -->
<div class="card mb-16" style="padding:12px 16px;">
    <form method="GET" style="display:flex;flex-wrap:wrap;align-items:flex-end;gap:8px;">
        <?php if ($isAdmin): ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Cliente</label>
            <select name="customer_id" style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="none" <?= $filterCust === 'none' ? 'selected' : '' ?>>— Sem cliente (órfãos) —</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (string)$filterCust === (string)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Modelo</label>
            <select name="model_id" style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <?php foreach ($models as $m): ?>
                <option value="<?= $m['id'] ?>" <?= $filterModel == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['model_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Situação</label>
            <select name="filter_online" style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="1" <?= $filterOnline==='1'?'selected':'' ?>>Online</option>
                <option value="0" <?= $filterOnline==='0'?'selected':'' ?>>Offline</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Status</label>
            <select name="filter_status" style="padding:6px 8px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                <option value="">Todos</option>
                <option value="1" <?= $filterStatus==='1'?'selected':'' ?>>Ativo</option>
                <option value="0" <?= $filterStatus==='0'?'selected':'' ?>>Inativo</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);display:block;">Busca</label>
            <input type="text" name="search" value="<?= htmlspecialchars($filterSearch ?? '') ?>" placeholder="IMEI ou nome..."
                   style="padding:6px 10px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);width:160px;">
        </div>
        <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
        <a href="/equipamentos" class="btn btn-outline btn-sm" style="color:var(--muted);">Limpar</a>
    </form>
</div>

<!-- Grade -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>IMEI</th>
                <th>Nome</th>
                <th>Modelo</th>
                <th>Cliente</th>
                <th>Chip</th>
                <th>Último Heartbeat</th>
                <th>Bateria</th>
                <th>Firmware</th>
                <th>Periféricos</th>
                <th>Situação</th>
                <th>Status</th>
                <th style="text-align:center;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($devices)): ?>
            <tr><td colspan="12" style="text-align:center;padding:32px;color:var(--muted);">Nenhum equipamento encontrado</td></tr>
            <?php else: ?>
            <?php foreach ($devices as $d): ?>
            <tr>
                <td><span class="text-mono"><?= htmlspecialchars($d['imei']) ?></span></td>
                <td><?= htmlspecialchars($d['device_name'] ?? '—') ?></td>
                <td>
                    <?= htmlspecialchars($d['model_name'] ?? $d['device_model'] ?? '—') ?>
                    <?php if ($d['camera_count']): ?>
                    <span style="font-size:10px;color:var(--muted);">(<?= $d['camera_count'] ?>ch)</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($d['customer_name'] ?? '—') ?></td>
                <td class="text-mono" style="font-size:12px;"><?= htmlspecialchars($d['chip_msisdn'] ?? '—') ?></td>
                <td class="text-mono" style="font-size:12px;">
                    <?= $d['last_communication'] ? fmt_brt($d['last_communication']) : 'Nunca' ?>
                </td>
                <td class="text-mono" style="font-size:12px;"><?= $d['battery_level'] !== null ? (int)$d['battery_level'] . '%' : '—' ?></td>
                <?php /* v4.9.32 — a coluna já saía no export e não aparecia na grade.
                        Quem preenche agora é `firmware_capture()`, a partir da resposta
                        do VERSION#; a leitura e o UPDATE ficam em /firmwares. */ ?>
                <td class="text-mono" style="font-size:12px;">
                    <?= $d['firmware_version'] !== null && $d['firmware_version'] !== '' ? htmlspecialchars($d['firmware_version']) : '—' ?>
                </td>
                <td>
                    <?php
                    $periphArr = $d['peripherals'] ? (array)json_decode($d['peripherals'], true) : [];
                    if ($periphArr): ?>
                    <span class="badge" title="<?= htmlspecialchars(implode(', ', $periphArr)) ?>"><?= count($periphArr) ?> perif.</span>
                    <?php else: echo '—'; endif; ?>
                </td>
                <td>
                    <?php if ($d['is_online']): ?>
                    <span class="badge badge-success">Online</span>
                    <?php else: ?>
                    <span class="badge badge-error">Offline</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($d['is_active']): ?>
                    <span class="badge badge-success">Ativo</span>
                    <?php else: ?>
                    <span class="badge">Inativo</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <div style="display:flex;gap:4px;justify-content:center;">
                        <a href="?action=editar&imei=<?= urlencode($d['imei']) ?>" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;">Editar</a>
                        <?php if ($d['is_online']): ?>
                        <?php /* v4.9.32 — o FOTA mora em /firmwares, onde a URL do pacote é
                                cadastro POR MODELO. Aqui ele era um campo de texto livre
                                enviado com proNo 33027 ("Definir parâmetro" do JT/T), que
                                nunca atualizou firmware nenhum. */ ?>
                        <a href="/firmwares" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;" title="Atualização de firmware — URL cadastrada por modelo">FOTA</a>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, 'equipamentos') ?>
<?php endif; ?>

<!-- Import Modal -->
<div id="import-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.4);z-index:999;align-items:center;justify-content:center;">
    <div class="card" style="width:500px;">
        <h3 style="font-size:16px;font-weight:600;margin-bottom:12px;">Importar Equipamentos em Lote</h3>
        <p class="text-muted" style="font-size:12px;margin-bottom:16px;">
            Faça upload de um arquivo CSV com as colunas: IMEI, Nome, Modelo, Canais, Firmware
        </p>
        <?php if ($isAdmin): ?>
        <div class="form-group">
            <label>Cliente *</label>
            <select id="import-customer" required>
                <option value="">— Selecione o cliente —</option>
                <?php foreach ($customers as $c): ?>
                <option value="<?= $c['id'] ?>" <?= (string)$filterCust === (string)$c['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <small class="text-muted" style="font-size:11px;">Todos os equipamentos do arquivo serão vinculados a este cliente.</small>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label>Arquivo CSV</label>
            <input type="file" id="import-file" accept=".csv">
        </div>
        <?= csrf_field() ?>
        <div style="display:flex;gap:8px;justify-content:flex-end;">
            <button class="btn btn-outline btn-sm" onclick="closeImportModal()">Cancelar</button>
            <button class="btn btn-primary btn-sm" onclick="submitImport()">Importar</button>
        </div>
        <div id="import-result" style="margin-top:12px;font-size:12px;"></div>
    </div>
</div>

<script>
function onModelChange(sel) {
    var opt = sel.options[sel.selectedIndex];
    var cam = parseInt(opt.dataset.cam) || 1;
    // camera_count do modelo é o MÁXIMO de canais; o valor é o default
    var input = document.getElementById('camera_count');
    input.value = cam;
    input.max = cam;
}

function showImportModal() { document.getElementById('import-modal').style.display = 'flex'; }
function closeImportModal() { document.getElementById('import-modal').style.display = 'none'; }

function submitImport() {
    var file = document.getElementById('import-file').files[0];
    if (!file) { alert('Selecione um arquivo CSV'); return; }
    var custSel = document.getElementById('import-customer');
    if (custSel && !custSel.value) {
        document.getElementById('import-result').innerHTML =
            '<div class="badge badge-error">Selecione o cliente antes de importar.</div>';
        return;
    }
    var reader = new FileReader();
    reader.onload = function(e) {
        var lines = e.target.result.split('\n');
        var results = [];
        for (var i = 1; i < lines.length; i++) {
            var cols = lines[i].split(',');
            if (cols.length < 2 || !cols[0].trim()) continue;
            results.push({
                imei: cols[0].trim(),
                name: (cols[1] || '').trim(),
                model: (cols[2] || '').trim(),
                channels: (cols[3] || '').trim(),
                firmware: (cols[4] || '').trim()
            });
        }
        if (results.length === 0) {
            document.getElementById('import-result').innerHTML =
                '<div class="badge badge-error">Nenhuma linha válida encontrada.</div>';
            return;
        }
        document.getElementById('import-result').innerHTML =
            '<div class="badge badge-info"><span class="spinner-inline"></span>Importando ' + results.length + ' dispositivo(s)...</div>';

        var formData = new FormData();
        formData.append('_csrf_token', document.querySelector('input[name="_csrf_token"]').value);
        formData.append('action', 'import_batch');
        formData.append('devices', JSON.stringify(results));
        if (custSel) formData.append('customer_id', custSel.value);

        fetch('', { method: 'POST', body: formData })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                document.getElementById('import-result').innerHTML =
                    '<div class="badge badge-success">Importação concluída. Recarregando...</div>';
                setTimeout(function() { location.reload(); }, 1500);
            })
            .catch(function() {
                document.getElementById('import-result').innerHTML =
                    '<div class="badge badge-error">Erro de rede.</div>';
            });
    };
    reader.readAsText(file);
}
</script>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
