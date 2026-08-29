<?php
/**
 * bycamera — Envio de comando por SMS
 * Rota: POST /sendsms  (AJAX, JSON)
 *
 * Espelha `sendcommand.php`, mas pelo transporte da operadora em vez do IoT
 * Hub. O envio em lote NÃO é um endpoint próprio: o frontend chama aqui uma vez
 * por equipamento, exatamente como a tela /comandos faz — assim a checagem de
 * posse por IMEI, o log e o registro por linha continuam sendo um caminho só.
 *
 * 🔑 O texto vai como a tela montou, na forma de PLATAFORMA (`CMD,A,B#`). Não
 * há conversão para a forma `CMD#666666#…` da wiki: decisão do dono do produto,
 * apoiada na nota oficial das planilhas ("TCP, SMS, or TF card", mesmo formato).
 *
 * 🔴 O DONO É SNAPSHOT. `customer_id`/`vehicle_id` de `sms_commands` são o
 * retrato de quem tinha a câmera NO MOMENTO do envio, resolvido por
 * `resolve_installation_for_imei()` — nunca lidos depois por JOIN em
 * `devices.customer_id`, que é só "quem tem a câmera hoje" (regra da Fase 2).
 *
 * Respostas: 200 com {ok:true,...} no sucesso; 200 com {ok:false,erro} nas
 * recusas de negócio (sem número, sem saldo, comando longo demais) — a tela
 * mostra o motivo por linha. 4xx só para falha de autorização/entrada.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms_gateway.php';
require_login();
require_permission('comandos-sms', 'view');

header('Content-Type: application/json; charset=utf-8');

/**
 * Encerra com JSON.
 *
 * @param array $payload Corpo
 * @param int   $code    HTTP
 * @returns void
 */
function sendsms_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendsms_out(['ok' => false, 'erro' => 'Método não permitido'], 405);
}

// Aceita JSON e form-urlencoded, como o sendcommand.php.
$ct  = $_SERVER['CONTENT_TYPE'] ?? '';
$in  = $_POST;
if (stripos($ct, 'application/json') !== false) {
    $j = json_decode(file_get_contents('php://input') ?: '', true);
    if (is_array($j)) $in = $j;
}

// csrf_verify() lê de $_POST['_csrf_token'] OU do cabeçalho X-CSRF-Token — o
// corpo JSON não popula $_POST, então a tela manda pelo cabeçalho (mesmo
// padrão do /sendcommand em comandos.php).
csrf_verify();

$imei    = trim((string)($in['imei'] ?? ''));
$comando = trim((string)($in['comando'] ?? $in['cmdContent'] ?? ''));

if ($imei === '' || $comando === '') {
    sendsms_out(['ok' => false, 'erro' => 'Informe o equipamento e o comando.'], 400);
}

$db   = Database::getInstance()->getConnection();
$user = get_jimi_user();

// ── Escopo multi-tenant ─────────────────────────────────────────────────────
// Mesma disciplina do /comandos: para não-admin o cliente é o da sessão, e o
// IMEI tem de pertencer a ele. Sem isso, trocar o IMEI no POST mandaria comando
// (que CUSTA dinheiro e mexe em equipamento) para o veículo de outro cliente.
$isAdmin    = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$customerId = get_customer_id();

$sqlDev = "SELECT d.imei, d.customer_id, s.msisdn
             FROM devices d
             LEFT JOIN sim_cards s ON s.imei = d.imei
            WHERE d.imei = :imei AND d.is_active = 1";
$params = [':imei' => $imei];

if (!$isAdmin) {
    $sqlDev .= " AND d.customer_id = :cid";
    $params[':cid'] = $customerId;
} else {
    // Revendedor: restringe à carteira. reseller_scope_ids() devolve null para
    // admin de plataforma (sem restrição) e [] para revendedor sem clientes —
    // são coisas OPOSTAS e tratá-las igual vaza ou some com tudo.
    $escopo = reseller_scope_ids();
    if ($escopo !== null) {
        if (!$escopo) {
            sendsms_out(['ok' => false, 'erro' => 'Seu usuário não tem clientes atribuídos.'], 403);
        }
        $ph = implode(',', array_map(fn($i) => ':c' . $i, array_keys($escopo)));
        $sqlDev .= " AND d.customer_id IN ($ph)";
        foreach ($escopo as $i => $cid) $params[':c' . $i] = $cid;
    }
}

$stmt = $db->prepare($sqlDev . " LIMIT 1");
$stmt->execute($params);
$dev = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dev) {
    sendsms_out(['ok' => false, 'erro' => 'Equipamento não encontrado no seu escopo.'], 403);
}

// ── Snapshot do dono (Fase 2) ───────────────────────────────────────────────
$inst      = resolve_installation_for_imei($db, $imei);
$snapCust  = $inst['customer_id'] ?? $dev['customer_id'] ?? null;
$snapVeic  = $inst['vehicle_id'] ?? null;

$operador  = $user['name'] ?? $user['email'] ?? 'sistema';
$refNumero = sms_nova_referencia();
$refCamp   = sms_nova_referencia();

/**
 * Grava a tentativa (bem ou malsucedida) e devolve o id.
 *
 * Toda tentativa vira linha, inclusive as que nem chegam à API: "não enviei
 * porque o chip não tem número" é informação operacional, e sem registro a tela
 * não teria como mostrar o histórico do que falhou.
 *
 * @param PDO         $db
 * @param array       $campos
 * @returns int
 */
function sendsms_registrar(PDO $db, array $campos): int
{
    $db->prepare("
        INSERT INTO sms_commands
            (referencia, referencia_campanha, customer_id, vehicle_id, imei, msisdn,
             command_content, status_envio, api_response, http_code, operator)
        VALUES
            (:ref, :refc, :cid, :vid, :imei, :msisdn,
             :cmd, :st, :api, :http, :op)
    ")->execute($campos);
    return (int)$db->lastInsertId();
}

// ── Número de destino ───────────────────────────────────────────────────────
// Estritamente o msisdn do chip (decisão do dono do produto). Sem número, não
// há envio — mas HÁ registro, com o motivo, para a tela poder explicar.
$msisdn = sms_normalizar_msisdn($dev['msisdn'] ?? null);

if ($msisdn === null) {
    $bruto = trim((string)($dev['msisdn'] ?? ''));
    $erro  = $bruto === ''
        ? 'O chip deste equipamento não tem número cadastrado. Cadastre o MSISDN em /chips.'
        : 'O número do chip ("' . $bruto . '") não é um MSISDN válido. Corrija em /chips.';

    sendsms_registrar($db, [
        ':ref' => $refNumero, ':refc' => null, ':cid' => $snapCust, ':vid' => $snapVeic,
        ':imei' => $imei, ':msisdn' => null, ':cmd' => $comando,
        ':st' => 'sem_msisdn', ':api' => null, ':http' => null, ':op' => $operador,
    ]);

    Logger::warning('SMS: envio recusado por MSISDN ausente/inválido',
                    ['imei' => $imei, 'bruto' => $bruto]);
    sendsms_out(['ok' => false, 'erro' => $erro]);
}

// ── Envio ───────────────────────────────────────────────────────────────────
$titulo = 'bycamera — comando ' . mb_substr($comando, 0, 40);
$r = sms_enviar($msisdn, $comando, $refNumero, $refCamp, $titulo, $db);

$id = sendsms_registrar($db, [
    ':ref'    => $refNumero,
    ':refc'   => $r['ref_campanha'] ?? $refCamp,
    ':cid'    => $snapCust,
    ':vid'    => $snapVeic,
    ':imei'   => $imei,
    ':msisdn' => $msisdn,
    ':cmd'    => $comando,
    ':st'     => $r['status'],
    // ⚠️ Coluna JSON: json_encode() SEMPRE. String crua faz o MySQL recusar com
    // 3140 Invalid JSON text — o defeito que quebrou o callback de comando
    // offline por meses em `commands.response_payload`.
    ':api'    => $r['json'] !== null ? json_encode($r['json'], JSON_UNESCAPED_UNICODE) : null,
    ':http'   => $r['http'] ?: null,
    ':op'     => $operador,
]);

Logger::info('SMS: comando despachado', [
    'imei' => $imei, 'msisdn' => $msisdn, 'comando' => $comando,
    'status' => $r['status'], 'id' => $id, 'operador' => $operador,
]);

if (!$r['ok']) {
    sendsms_out(['ok' => false, 'id' => $id, 'status' => $r['status'], 'erro' => $r['erro']]);
}

sendsms_out([
    'ok'     => true,
    'id'     => $id,
    'status' => 'enviado',
    'msisdn' => $msisdn,
    'msg'    => 'SMS aceito pelo provedor. O status de entrega e a resposta do '
              . 'equipamento chegam pelo webhook.',
]);
