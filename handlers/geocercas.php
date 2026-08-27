<?php
/**
 * JIMI Webhook System — Geocercas v4.5.0
 * Rota: /geocercas
 *
 * CRUD de cercas e POIs, com desenho no mapa e vínculo de equipamentos.
 *
 * GET                              → lista
 * GET ?action=nova                 → formulário de criação
 * GET ?action=editar&id=N          → formulário de edição
 * POST action=excluir&id=N         → exclusão (CASCATA em vínculos/estado/eventos)
 * POST                             → salva (CSRF obrigatório)
 *
 * ⚠️ A exclusão era `GET ?action=excluir&id=N` até a v4.7.2. `csrf_verify()`
 * não lê da query string, então bastava um `<img src="/geocercas?action=
 * excluir&id=3">` em QUALQUER página que um administrador logado abrisse para
 * apagar a cerca e todo o histórico de eventos dela — o navegador manda o
 * cookie de sessão sozinho. Agora é POST com token, como o resto do CRUD.
 *
 * O desenho usa Leaflet puro, sem leaflet-draw: círculo = clique no mapa
 * define o centro e um campo numérico define o raio; polígono = cliques
 * acumulam vértices e um botão fecha a forma. Uma dependência de CDN a menos
 * por um ganho de UX pequeno.
 *
 * A bounding box é calculada AQUI, ao salvar, e não no worker: é o pré-filtro
 * que o geofence_worker usa milhares de vezes por rodada.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/geofence.php';
require_login();

$db         = Database::getInstance()->getConnection();
$user       = get_jimi_user();
$customerId = get_customer_id();
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';

$message = '';
$messageType = '';
$savedAction = '';

// Retorno do Post/Redirect/Get do salvamento. O código vem na URL porque o
// projeto não tem mecanismo de flash em sessão — e um enum fechado aqui é
// melhor do que ecoar texto vindo da query string.
const GEOFENCE_FLASH = [
    'criada'                 => ['Geocerca criada.', 'success'],
    'atualizada'             => ['Geocerca atualizada.', 'success'],
    'criada_sem_device'      => ['Geocerca criada. Nenhum equipamento vinculado — a cerca não será avaliada até que você vincule ao menos um.', 'warning'],
    'atualizada_sem_device'  => ['Geocerca atualizada. Nenhum equipamento vinculado — a cerca não será avaliada até que você vincule ao menos um.', 'warning'],
    'excluida'               => ['Geocerca excluída.', 'success'],
    'nao_encontrada'         => ['Geocerca não encontrada.', 'error'],
    'fora_escopo'            => ['Geocerca fora do seu escopo.', 'error'],
    'erro_excluir'           => ['Erro ao excluir a geocerca. O detalhe foi registrado no log.', 'error'],
];
if (!empty($_GET['msg']) && isset(GEOFENCE_FLASH[$_GET['msg']])) {
    [$message, $messageType] = GEOFENCE_FLASH[$_GET['msg']];
}

/**
 * Normaliza a lista de e-mails de alerta: até 3, sem duplicata, só válidos.
 *
 * @param string $raw Texto separado por vírgula, ponto-e-vírgula ou quebra
 * @returns array
 */
function parse_geofence_emails(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '' && filter_var($p, FILTER_VALIDATE_EMAIL)) {
            $out[] = $p;
        }
    }
    return array_slice(array_values(array_unique($out)), 0, 3);
}

// ── POST: salvar ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // ── POST: excluir ──────────────────────────────────────────
    // Fica DENTRO do bloco POST, depois do csrf_verify(), justamente para
    // herdar a verificação do token — era essa a falha da versão anterior.
    if (($_POST['action'] ?? '') === 'excluir' && !empty($_POST['id'])) {
        require_permission('geocercas', 'delete');
        $delId = (int)$_POST['id'];
        try {
            $stmt = $db->prepare("SELECT customer_id FROM geofences WHERE id = :id");
            $stmt->execute([':id' => $delId]);
            $ownerId = $stmt->fetchColumn();

            if ($ownerId === false) {
                header('Location: /geocercas?msg=nao_encontrada');
                exit;
            }
            if (!$isAdmin && (int)$ownerId !== (int)$customerId) {
                header('Location: /geocercas?msg=fora_escopo');
                exit;
            }

            // Vínculos, estado e eventos saem por ON DELETE CASCADE
            $db->prepare("DELETE FROM geofences WHERE id = :id")->execute([':id' => $delId]);
            header('Location: /geocercas?msg=excluida');
            exit;
        } catch (Throwable $e) {
            // TODO caminho daqui tem de terminar em exit: sem isso a execução
            // cai na lógica de SALVAR logo abaixo, com um POST que não tem
            // nenhum campo do formulário — criaria uma cerca vazia como efeito
            // colateral de uma exclusão que falhou.
            Logger::error('Geocercas: falha ao excluir', [
                'geofence_id' => $delId, 'erro' => $e->getMessage(),
            ]);
            header('Location: /geocercas?msg=erro_excluir');
            exit;
        }
    }

    $fenceId = !empty($_POST['fence_id']) ? (int)$_POST['fence_id'] : null;
    require_permission('geocercas', $fenceId ? 'edit' : 'create');

    $name     = trim($_POST['name'] ?? '');
    $kind     = ($_POST['kind'] ?? 'cerca') === 'poi' ? 'poi' : 'cerca';
    $shape    = ($_POST['shape'] ?? 'circulo') === 'poligono' ? 'poligono' : 'circulo';
    $alertOn  = in_array($_POST['alert_on'] ?? '', ['entrada','saida','ambos','nenhum'], true)
                ? $_POST['alert_on'] : 'ambos';
    $color    = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#0052ff';
    $descr    = trim($_POST['description'] ?? '');
    $isActive = !empty($_POST['is_active']) ? 1 : 0;
    $emails   = parse_geofence_emails($_POST['alert_emails'] ?? '');
    $imeis    = array_values(array_filter(array_map('trim', (array)($_POST['imeis'] ?? []))));

    $centerLat = trim($_POST['center_lat'] ?? '') !== '' ? (float)$_POST['center_lat'] : null;
    $centerLng = trim($_POST['center_lng'] ?? '') !== '' ? (float)$_POST['center_lng'] : null;
    $radiusM   = (int)($_POST['radius_m'] ?? 0);
    $polygon   = geofence_normalize_polygon($_POST['polygon'] ?? '');

    // Validação da geometria: sem ela o worker avaliaria uma cerca que não
    // contém nada e o usuário nunca entenderia por que não há evento.
    $geomError = null;
    if ($name === '') {
        $geomError = 'Informe o nome da geocerca.';
    } elseif ($shape === 'circulo') {
        if ($centerLat === null || $centerLng === null) {
            $geomError = 'Clique no mapa para definir o centro da cerca.';
        } elseif ($radiusM < 20 || $radiusM > 500000) {
            $geomError = 'O raio deve ficar entre 20 m e 500 km.';
        } else {
            $polygon = null;
        }
    } else {
        if (!$polygon) {
            $geomError = 'Marque ao menos 3 pontos no mapa para fechar o polígono.';
        } else {
            $centerLat = $centerLng = null;
            $radiusM = null;
        }
    }

    if ($geomError) {
        $message = $geomError;
        $messageType = 'error';
    } else {
        $bbox = geofence_bbox([
            'shape'      => $shape,
            'center_lat' => $centerLat,
            'center_lng' => $centerLng,
            'radius_m'   => $radiusM,
            'polygon'    => $polygon,
        ]);

        try {
            $db->beginTransaction();

            $fields = [
                ':name'    => $name,
                ':kind'    => $kind,
                ':shape'   => $shape,
                ':clat'    => $centerLat,
                ':clng'    => $centerLng,
                ':rad'     => $radiusM ?: null,
                ':poly'    => $polygon ? json_encode($polygon) : null,
                ':bminlat' => $bbox[0] ?? null,
                ':bmaxlat' => $bbox[1] ?? null,
                ':bminlng' => $bbox[2] ?? null,
                ':bmaxlng' => $bbox[3] ?? null,
                ':alert'   => $alertOn,
                ':emails'  => $emails ? json_encode($emails, JSON_UNESCAPED_UNICODE) : null,
                ':color'   => $color,
                ':descr'   => $descr !== '' ? mb_substr($descr, 0, 300) : null,
                ':active'  => $isActive,
            ];

            if ($fenceId) {
                // Escopo: usuário comum só edita cerca do próprio cliente
                $own = $db->prepare("SELECT customer_id FROM geofences WHERE id = :id");
                $own->execute([':id' => $fenceId]);
                $ownerId = $own->fetchColumn();
                if ($ownerId === false) {
                    throw new RuntimeException('Geocerca não encontrada.');
                }
                if (!$isAdmin && (int)$ownerId !== (int)$customerId) {
                    throw new RuntimeException('Geocerca fora do seu escopo.');
                }

                $stmt = $db->prepare(
                    "UPDATE geofences SET
                        name = :name, kind = :kind, shape = :shape,
                        center_lat = :clat, center_lng = :clng, radius_m = :rad, polygon = :poly,
                        bbox_min_lat = :bminlat, bbox_max_lat = :bmaxlat,
                        bbox_min_lng = :bminlng, bbox_max_lng = :bmaxlng,
                        alert_on = :alert, alert_emails = :emails, color = :color,
                        description = :descr, is_active = :active
                     WHERE id = :id"
                );
                $stmt->execute($fields + [':id' => $fenceId]);
                $message = 'Geocerca atualizada.';
                $savedAction = 'atualizada';
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO geofences
                     (customer_id, name, kind, shape, center_lat, center_lng, radius_m, polygon,
                      bbox_min_lat, bbox_max_lat, bbox_min_lng, bbox_max_lng,
                      alert_on, alert_emails, color, description, is_active, created_by)
                     VALUES (:cid, :name, :kind, :shape, :clat, :clng, :rad, :poly,
                             :bminlat, :bmaxlat, :bminlng, :bmaxlng,
                             :alert, :emails, :color, :descr, :active, :uid)"
                );
                $stmt->execute($fields + [
                    ':cid' => $customerId,
                    ':uid' => $user['id'] ?? null,
                ]);
                $fenceId = (int)$db->lastInsertId();
                $message = 'Geocerca criada.';
                $savedAction = 'criada';
            }

            // ── Vínculo de equipamentos ────────────────────────────
            // A geometria pode ter mudado; o estado antigo (dentro/fora)
            // descreveria uma cerca que não existe mais. Apagar geofence_state
            // faz o worker re-semear no próximo ponto — sem gerar entrada
            // retroativa para quem já estava lá dentro.
            $db->prepare("DELETE FROM geofence_devices WHERE geofence_id = :id")->execute([':id' => $fenceId]);
            $db->prepare("DELETE FROM geofence_state   WHERE geofence_id = :id")->execute([':id' => $fenceId]);

            if ($imeis) {
                $link = $db->prepare("INSERT IGNORE INTO geofence_devices (geofence_id, imei) VALUES (:gid, :imei)");
                foreach ($imeis as $imei) {
                    $link->execute([':gid' => $fenceId, ':imei' => $imei]);
                }
            }

            $db->commit();
            $messageType = 'success';
            if (!$imeis) {
                $message .= ' Nenhum equipamento vinculado — a cerca não será avaliada até que você vincule ao menos um.';
                $messageType = 'warning';
            }

            // Post/Redirect/Get. Sem isto o POST cai no mesmo `?action=nova` e a
            // página volta a ser o formulário VAZIO: o usuário recebe "Geocerca
            // criada." sem ver o registro na grade, e um F5 reenvia o POST e
            // cria uma cerca duplicada. Redirecionar para a lista mostra o que
            // acabou de ser salvo e torna o refresh inofensivo.
            header('Location: /geocercas?msg=' . $savedAction . ($imeis ? '' : '_sem_device'));
            exit;
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $message = 'Erro ao salvar: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$action = $_GET['action'] ?? '';

// ── GET: editar ────────────────────────────────────────────────
$editFence = null;
$editImeis = [];
if ($action === 'editar' && !empty($_GET['id'])) {
    try {
        $stmt = $db->prepare("SELECT * FROM geofences WHERE id = :id");
        $stmt->execute([':id' => (int)$_GET['id']]);
        $editFence = $stmt->fetch() ?: null;
        if ($editFence && !$isAdmin && (int)$editFence['customer_id'] !== (int)$customerId) {
            $editFence = null;
        }
        if ($editFence) {
            $stmt = $db->prepare("SELECT imei FROM geofence_devices WHERE geofence_id = :id");
            $stmt->execute([':id' => (int)$editFence['id']]);
            $editImeis = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Throwable $e) {}
}

// ── Lista ──────────────────────────────────────────────────────
$fences = [];
$tableMissing = false;
try {
    if ($isAdmin && !$customerId) {
        $stmt = $db->query(
            "SELECT g.*, c.name AS customer_name,
                    (SELECT COUNT(*) FROM geofence_devices gd WHERE gd.geofence_id = g.id) AS device_count
             FROM geofences g
             LEFT JOIN customers c ON c.id = g.customer_id
             ORDER BY g.is_active DESC, g.name"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT g.*, c.name AS customer_name,
                    (SELECT COUNT(*) FROM geofence_devices gd WHERE gd.geofence_id = g.id) AS device_count
             FROM geofences g
             LEFT JOIN customers c ON c.id = g.customer_id
             WHERE g.customer_id = :cid
             ORDER BY g.is_active DESC, g.name"
        );
        $stmt->execute([':cid' => $customerId]);
    }
    $fences = $stmt->fetchAll();
} catch (Throwable $e) {
    $tableMissing = true;
    $message = 'Tabelas de geocerca indisponíveis — aplique a migração v4.5.0.';
    $messageType = 'error';
}

// ── Equipamentos disponíveis para vínculo ──────────────────────
$devices = [];
try {
    if ($isAdmin && !$customerId) {
        $devices = $db->query(
            "SELECT imei, device_name FROM devices WHERE is_active = 1 ORDER BY device_name, imei"
        )->fetchAll();
    } else {
        $stmt = $db->prepare(
            "SELECT imei, device_name FROM devices
             WHERE is_active = 1 AND customer_id = :cid ORDER BY device_name, imei"
        );
        $stmt->execute([':cid' => $customerId]);
        $devices = $stmt->fetchAll();
    }
} catch (Throwable $e) {}

$showForm = ($action === 'nova' || $editFence) && !$tableMissing;

$page_title = 'Geocercas';
$current_route = 'geocercas';
if ($showForm) {
    require_once __DIR__ . '/../web/components/map_assets.php';
    $extra_head = BC_MAP_ASSETS_HTML;
}
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($message): ?>
<div class="toast toast-<?= htmlspecialchars($messageType) ?> toast-show" style="position:fixed;bottom:24px;right:24px;z-index:9999;max-width:420px;">
    <?= htmlspecialchars($message) ?>
</div>
<script>setTimeout(function(){var t=document.querySelector('.toast');if(t)t.style.display='none';},6000);</script>
<?php endif; ?>

<?php if ($showForm):
    $fShape   = $editFence['shape'] ?? 'circulo';
    $fPolygon = geofence_normalize_polygon($editFence['polygon'] ?? null);
    $fEmails  = json_decode((string)($editFence['alert_emails'] ?? '[]'), true) ?: [];
?>
<div class="flex-between mb-16">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);">
        <?= $editFence ? 'Editar Geocerca' : 'Nova Geocerca' ?>
    </h2>
    <a href="/geocercas" class="btn btn-outline btn-sm">Voltar</a>
</div>

<form method="POST" id="fenceForm">
    <?= csrf_field() ?>
    <?php if ($editFence): ?>
    <input type="hidden" name="fence_id" value="<?= (int)$editFence['id'] ?>">
    <?php endif; ?>
    <input type="hidden" name="center_lat" id="centerLat" value="<?= htmlspecialchars((string)($editFence['center_lat'] ?? '')) ?>">
    <input type="hidden" name="center_lng" id="centerLng" value="<?= htmlspecialchars((string)($editFence['center_lng'] ?? '')) ?>">
    <input type="hidden" name="polygon"    id="polygonJson" value="<?= htmlspecialchars($fPolygon ? json_encode($fPolygon) : '') ?>">

    <div style="display:grid;grid-template-columns:minmax(320px,380px) 1fr;gap:20px;align-items:start;">

        <div class="card" style="padding:20px;">
            <div class="form-group">
                <label>Nome</label>
                <input type="text" name="name" required maxlength="150"
                       value="<?= htmlspecialchars($editFence['name'] ?? '') ?>"
                       placeholder="Ex.: Pátio Central">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Tipo</label>
                    <select name="kind" style="width:100%;padding:9px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                        <option value="cerca" <?= ($editFence['kind'] ?? 'cerca') === 'cerca' ? 'selected' : '' ?>>Geocerca</option>
                        <option value="poi"   <?= ($editFence['kind'] ?? '') === 'poi' ? 'selected' : '' ?>>Ponto de Interesse</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Cor</label>
                    <input type="color" name="color" id="fenceColor"
                           value="<?= htmlspecialchars($editFence['color'] ?? '#0052ff') ?>"
                           style="width:100%;height:38px;padding:2px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                </div>
            </div>

            <div class="form-group">
                <label>Formato</label>
                <div style="display:flex;gap:16px;padding:10px 12px;background:var(--canvas-soft);border-radius:var(--radius-sm);">
                    <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
                        <input type="radio" name="shape" value="circulo" style="width:auto;"
                               <?= $fShape === 'circulo' ? 'checked' : '' ?> onchange="setShape('circulo')"> Círculo
                    </label>
                    <label style="display:flex;align-items:center;gap:7px;font-size:13px;cursor:pointer;">
                        <input type="radio" name="shape" value="poligono" style="width:auto;"
                               <?= $fShape === 'poligono' ? 'checked' : '' ?> onchange="setShape('poligono')"> Polígono
                    </label>
                </div>
            </div>

            <div class="form-group" id="radiusGroup" style="<?= $fShape === 'poligono' ? 'display:none;' : '' ?>">
                <label>Raio (metros)</label>
                <input type="number" name="radius_m" id="radiusM" min="20" max="500000" step="10"
                       value="<?= (int)($editFence['radius_m'] ?? 200) ?>" oninput="redraw()">
            </div>

            <div class="form-group" id="polygonGroup" style="<?= $fShape === 'circulo' ? 'display:none;' : '' ?>">
                <label>Vértices</label>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="text-mono" id="vertexCount" style="font-size:13px;"><?= count($fPolygon) ?></span>
                    <span class="text-muted" style="font-size:12px;">marcados</span>
                    <button type="button" class="btn btn-outline btn-sm" style="margin-left:auto;padding:4px 10px;font-size:12px;"
                            onclick="undoVertex()">Desfazer</button>
                    <button type="button" class="btn btn-outline btn-sm" style="padding:4px 10px;font-size:12px;"
                            onclick="clearVertices()">Limpar</button>
                </div>
            </div>

            <div class="form-group">
                <label>Alertar em</label>
                <select name="alert_on" style="width:100%;padding:9px;font-size:13px;border:1px solid var(--hairline);border-radius:var(--radius-sm);">
                    <option value="ambos"   <?= ($editFence['alert_on'] ?? 'ambos') === 'ambos' ? 'selected' : '' ?>>Entrada e saída</option>
                    <option value="entrada" <?= ($editFence['alert_on'] ?? '') === 'entrada' ? 'selected' : '' ?>>Somente entrada</option>
                    <option value="saida"   <?= ($editFence['alert_on'] ?? '') === 'saida' ? 'selected' : '' ?>>Somente saída</option>
                    <option value="nenhum"  <?= ($editFence['alert_on'] ?? '') === 'nenhum' ? 'selected' : '' ?>>Não alertar</option>
                </select>
                <p class="text-muted" style="font-size:11px;margin-top:6px;">
                    O evento é sempre gravado para o relatório — isto decide apenas se notifica.
                </p>
            </div>

            <div class="form-group">
                <label>E-mails de alerta (até 3)</label>
                <input type="text" name="alert_emails"
                       value="<?= htmlspecialchars(implode(', ', $fEmails)) ?>"
                       placeholder="operacao@empresa.com.br">
                <p class="text-muted" style="font-size:11px;margin-top:6px;">
                    Opcional. O sino e o pop-up funcionam sem preencher isto.
                </p>
            </div>

            <div class="form-group">
                <label>Descrição</label>
                <input type="text" name="description" maxlength="300"
                       value="<?= htmlspecialchars($editFence['description'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="is_active" value="1" style="width:auto;"
                           <?= (!$editFence || !empty($editFence['is_active'])) ? 'checked' : '' ?>>
                    Geocerca ativa
                </label>
            </div>
        </div>

        <div>
            <div class="card mb-16" style="padding:0;overflow:hidden;">
                <div style="padding:12px 16px;border-bottom:1px solid var(--hairline);font-size:12px;color:var(--muted);" id="mapHint">
                    Clique no mapa para posicionar o centro da cerca.
                </div>
                <div id="fenceMap" style="height:420px;"></div>
            </div>

            <div class="card" style="padding:16px 20px;">
                <div class="flex-between mb-16">
                    <label style="font-size:11px;font-weight:600;text-transform:uppercase;color:var(--muted);">
                        Equipamentos monitorados
                    </label>
                    <div style="display:flex;gap:6px;">
                        <button type="button" class="btn btn-outline btn-sm" style="padding:3px 9px;font-size:11px;"
                                onclick="toggleAllDevices(true)">Todos</button>
                        <button type="button" class="btn btn-outline btn-sm" style="padding:3px 9px;font-size:11px;"
                                onclick="toggleAllDevices(false)">Nenhum</button>
                    </div>
                </div>
                <?php if (empty($devices)): ?>
                <p class="text-muted" style="font-size:13px;">Nenhum equipamento ativo neste cliente.</p>
                <?php else: ?>
                <div style="max-height:200px;overflow-y:auto;display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px;">
                    <?php foreach ($devices as $d): ?>
                    <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;padding:5px 8px;border-radius:var(--radius-sm);">
                        <input type="checkbox" name="imeis[]" value="<?= htmlspecialchars($d['imei']) ?>" style="width:auto;"
                               <?= in_array($d['imei'], $editImeis, true) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($d['device_name'] ?: $d['imei']) ?></span>
                        <span class="text-mono text-muted" style="font-size:11px;margin-left:auto;"><?= htmlspecialchars($d['imei']) ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="mt-24">
                <button type="submit" class="btn btn-primary">Salvar Geocerca</button>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    // Centro padrão: São Paulo. Só entra em cena quando não há geometria salva.
    var DEFAULT_CENTER = [-23.5505, -46.6333];

    var savedLat  = parseFloat(document.getElementById('centerLat').value);
    var savedLng  = parseFloat(document.getElementById('centerLng').value);
    var savedPoly = [];
    try { savedPoly = JSON.parse(document.getElementById('polygonJson').value || '[]') || []; } catch (e) {}

    var start = DEFAULT_CENTER, zoom = 12;
    if (!isNaN(savedLat) && !isNaN(savedLng)) { start = [savedLat, savedLng]; zoom = 15; }
    else if (savedPoly.length) { start = savedPoly[0]; zoom = 15; }

    var map = L.map('fenceMap').setView(start, zoom);
    bcMapBaseLayers(map);

    var shape = document.querySelector('input[name=shape]:checked').value;
    var center = (!isNaN(savedLat) && !isNaN(savedLng)) ? L.latLng(savedLat, savedLng) : null;
    var vertices = savedPoly.map(function (v) { return L.latLng(v[0], v[1]); });
    var layer = null, markers = [];

    function color() { return document.getElementById('fenceColor').value || '#0052ff'; }

    function clearLayers() {
        if (layer) { map.removeLayer(layer); layer = null; }
        markers.forEach(function (m) { map.removeLayer(m); });
        markers = [];
    }

    window.redraw = function () {
        clearLayers();
        if (shape === 'circulo') {
            if (!center) return;
            var r = parseInt(document.getElementById('radiusM').value, 10) || 200;
            layer = L.circle(center, { radius: r, color: color(), weight: 2, fillOpacity: 0.15 }).addTo(map);
            markers.push(L.marker(center, { draggable: true })
                .on('dragend', function (e) { center = e.target.getLatLng(); syncFields(); redraw(); })
                .addTo(map));
        } else {
            vertices.forEach(function (v) {
                markers.push(L.circleMarker(v, { radius: 5, color: color(), fillOpacity: 1 }).addTo(map));
            });
            if (vertices.length >= 3) {
                layer = L.polygon(vertices, { color: color(), weight: 2, fillOpacity: 0.15 }).addTo(map);
            } else if (vertices.length === 2) {
                layer = L.polyline(vertices, { color: color(), weight: 2, dashArray: '4,4' }).addTo(map);
            }
            document.getElementById('vertexCount').textContent = vertices.length;
        }
        syncFields();
    };

    function syncFields() {
        document.getElementById('centerLat').value = (shape === 'circulo' && center) ? center.lat.toFixed(8) : '';
        document.getElementById('centerLng').value = (shape === 'circulo' && center) ? center.lng.toFixed(8) : '';
        document.getElementById('polygonJson').value = (shape === 'poligono' && vertices.length)
            ? JSON.stringify(vertices.map(function (v) { return [ +v.lat.toFixed(8), +v.lng.toFixed(8) ]; }))
            : '';
    }

    window.setShape = function (s) {
        shape = s;
        document.getElementById('radiusGroup').style.display  = (s === 'circulo')  ? '' : 'none';
        document.getElementById('polygonGroup').style.display = (s === 'poligono') ? '' : 'none';
        document.getElementById('mapHint').textContent = (s === 'circulo')
            ? 'Clique no mapa para posicionar o centro da cerca. Arraste o marcador para ajustar.'
            : 'Clique para marcar cada vértice. A partir do 3º ponto o polígono se fecha sozinho.';
        redraw();
    };

    window.undoVertex = function () { vertices.pop(); redraw(); };
    window.clearVertices = function () { vertices = []; redraw(); };

    window.toggleAllDevices = function (on) {
        document.querySelectorAll('input[name="imeis[]"]').forEach(function (cb) { cb.checked = on; });
    };

    map.on('click', function (e) {
        if (shape === 'circulo') { center = e.latlng; } else { vertices.push(e.latlng); }
        redraw();
    });

    document.getElementById('fenceColor').addEventListener('input', redraw);

    setShape(shape);
    if (layer && layer.getBounds) { try { map.fitBounds(layer.getBounds().pad(0.3)); } catch (e) {} }
})();
</script>

<?php else: ?>
<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Geocercas</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">
            Áreas monitoradas. Cada entrada e saída vira evento no
            <a href="/relatorios/geocercas" style="color:var(--accent);">relatório de geocercas</a>.
        </p>
    </div>
    <?php if (!$tableMissing): ?>
    <a href="?action=nova" class="btn btn-primary btn-sm">+ Nova Geocerca</a>
    <?php endif; ?>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Nome</th>
                <?php if ($isAdmin): ?><th>Cliente</th><?php endif; ?>
                <th>Tipo</th>
                <th>Geometria</th>
                <th>Alerta</th>
                <th style="text-align:center;">Equip.</th>
                <th>Status</th>
                <th style="text-align:center;">Ações</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($fences)): ?>
            <tr><td colspan="<?= $isAdmin ? 8 : 7 ?>" style="text-align:center;padding:32px;color:var(--muted);">
                Nenhuma geocerca cadastrada.
            </td></tr>
            <?php else: foreach ($fences as $g):
                $alertLabels = ['ambos' => 'Entrada e saída', 'entrada' => 'Entrada', 'saida' => 'Saída', 'nenhum' => '—'];
            ?>
            <tr>
                <td style="font-weight:500;">
                    <span style="display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:7px;vertical-align:middle;background:<?= htmlspecialchars($g['color'] ?: '#0052ff') ?>;"></span>
                    <?= htmlspecialchars($g['name']) ?>
                </td>
                <?php if ($isAdmin): ?><td><?= htmlspecialchars($g['customer_name'] ?? '—') ?></td><?php endif; ?>
                <td><?= ($g['kind'] ?? '') === 'poi' ? 'POI' : 'Geocerca' ?></td>
                <td style="font-size:12px;color:var(--muted);"><?= htmlspecialchars(geofence_shape_label($g)) ?></td>
                <td style="font-size:12px;"><?= htmlspecialchars($alertLabels[$g['alert_on']] ?? '—') ?></td>
                <td style="text-align:center;">
                    <?php if ((int)$g['device_count'] === 0): ?>
                        <span class="badge badge-warning" title="Sem equipamento vinculado a cerca não é avaliada">0</span>
                    <?php else: ?>
                        <span class="text-mono"><?= (int)$g['device_count'] ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($g['is_active'])): ?>
                        <span class="badge badge-success">Ativa</span>
                    <?php else: ?>
                        <span class="badge">Inativa</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center;">
                    <div style="display:flex;gap:4px;justify-content:center;">
                        <a href="/relatorios/geocercas?geofence_id=<?= (int)$g['id'] ?>" class="btn btn-outline btn-sm"
                           style="padding:4px 10px;font-size:12px;">Eventos</a>
                        <a href="?action=editar&id=<?= (int)$g['id'] ?>" class="btn btn-outline btn-sm"
                           style="padding:4px 10px;font-size:12px;">Editar</a>
                        <form method="post" action="/geocercas" style="display:inline"
                              onsubmit="return confirm('Excluir esta geocerca? Os eventos históricos dela também serão removidos.')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="excluir">
                            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
                            <button type="submit" class="btn btn-outline btn-sm"
                                    style="padding:4px 10px;font-size:12px;color:var(--error);">Excluir</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
