<?php
/**
 * bycamera — Recepção do FILELIST das câmeras JIMI (FASE 0: só captura)
 * Rota: /filelist/{imei}
 *
 * O QUE É. No protocolo JIMI a listagem de gravações do cartão funciona ao
 * contrário do JT/T. No JT/T pedimos uma janela (`37381`) e o IoT Hub devolve
 * a lista estruturada por webhook. No JIMI mandamos
 * `FILELIST,http://<servidor>/filelist/<imei>` e a CÂMERA sobe sozinha um
 * arquivo TXT com os nomes — sem filtro de data, a lista inteira.
 *
 * 🔴 POR QUE ESTA VERSÃO SÓ CAPTURA. **Ninguém publica o layout desse TXT.**
 * Escrever o parser por suposição é exatamente como o `34818` entrou no
 * projeto: aceito pelo IoT Hub, marcado `executed`, e nunca produziu um
 * arquivo — 18 tentativas até alguém desconfiar. A mesma disciplina da §2 do
 * PROJETO_PARAMETROS.md, que evitou três parsers errados nos parâmetros, vale
 * aqui: medir primeiro, interpretar depois.
 *
 * Então este handler guarda o corpo CRU, registra método, tipo, tamanho e
 * cabeçalhos, e devolve 200. O parser vem na fase seguinte, escrito contra o
 * arquivo real.
 *
 * SEM LOGIN, DE PROPÓSITO — e a razão é a mesma do `/download`: quem chama é
 * a câmera, que não tem como carregar sessão nem o token do webhook (ele nem
 * existe no comando de texto). As defesas são outras:
 *   • o IMEI do caminho tem de existir em `devices` — senão nada é gravado;
 *   • teto de tamanho, para o endpoint não virar depósito;
 *   • o corpo NUNCA é interpretado, só escrito em disco;
 *   • a captura vai para `logs/filelist/`, que o VirtualHost NEGA à web
 *     (`DirectoryMatch` de `logs`), então o que entra não sai por aqui.
 *
 * ⚠️ A CÂMERA FALA HTTP SIMPLES. O redirect 80→443 do site tem exceção para
 * este caminho (ver docs/apache/bycamera.conf) — sem ela a câmera receberia
 * um 301 para HTTPS e, se não seguir redirect ou não fizer TLS, o upload
 * morreria sem deixar rastro no nosso lado.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';

if (ob_get_level()) ob_end_clean();

/** Teto do corpo aceito. Lista de milhares de nomes ainda cabe folgado. */
const FILELIST_MAX_BYTES = 8 * 1024 * 1024;

$imei   = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['imei'] ?? ''));
$metodo = $_SERVER['REQUEST_METHOD'] ?? '?';

// ── 1. O IMEI precisa ser conhecido ─────────────────────────────────────────
// Sem isto o endpoint aceitaria upload de qualquer origem para qualquer nome.
$conhecido = false;
if ($imei !== '') {
    try {
        $db = Database::getInstance()->getConnection();
        $st = $db->prepare("SELECT 1 FROM devices WHERE imei = :i LIMIT 1");
        $st->execute([':i' => $imei]);
        $conhecido = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        Logger::error('filelist: falha ao validar IMEI', ['imei' => $imei, 'erro' => $e->getMessage()]);
    }
}

if (!$conhecido) {
    // 404 e não 403: para quem sonda, "não existe" diz menos que "existe mas
    // você não pode". O log guarda o suficiente para investigar.
    Logger::warning('filelist: IMEI desconhecido ou ausente', [
        'imei' => $imei, 'metodo' => $metodo,
        'origem' => $_SERVER['REMOTE_ADDR'] ?? '?',
    ]);
    http_response_code(404);
    echo "not found\n";
    exit;
}

// ── 2. Captura crua ─────────────────────────────────────────────────────────
$corpo = file_get_contents('php://input', false, null, 0, FILELIST_MAX_BYTES + 1);
$corpo = $corpo === false ? '' : $corpo;
$truncado = strlen($corpo) > FILELIST_MAX_BYTES;
if ($truncado) $corpo = substr($corpo, 0, FILELIST_MAX_BYTES);

// Alguns firmwares mandam multipart em vez de corpo cru — capturar os dois,
// porque descobrir qual é depois de descartar um deles custaria outra rodada
// de campo.
$arquivos = [];
foreach ($_FILES as $campo => $f) {
    if (!isset($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) continue;
    $arquivos[] = [
        'campo' => $campo,
        'nome'  => $f['name'] ?? '?',
        'bytes' => (int)($f['size'] ?? 0),
        'dados' => (string)file_get_contents($f['tmp_name'], false, null, 0, FILELIST_MAX_BYTES),
    ];
}

$dir = __DIR__ . '/../logs/filelist';
if (!is_dir($dir)) @mkdir($dir, 0777, true);

$carimbo = gmdate('Ymd_His');
$base    = $dir . '/' . $imei . '_' . $carimbo;

// Cabeçalhos completos: o formato pode estar declarado num Content-Type que
// ninguém esperava, e essa informação só existe agora.
$cabecalhos = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0 || in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
        $cabecalhos[$k] = $v;
    }
}

@file_put_contents($base . '.meta.json', json_encode([
    'imei'        => $imei,
    'metodo'      => $metodo,
    'uri'         => $_SERVER['REQUEST_URI'] ?? '',
    'origem'      => $_SERVER['REMOTE_ADDR'] ?? '',
    'recebido_em' => gmdate('c'),
    'corpo_bytes' => strlen($corpo),
    'truncado'    => $truncado,
    'query'       => $_GET,
    'post_campos' => array_keys($_POST),
    'arquivos'    => array_map(fn($a) => ['campo' => $a['campo'], 'nome' => $a['nome'], 'bytes' => $a['bytes']], $arquivos),
    'cabecalhos'  => $cabecalhos,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

if ($corpo !== '') @file_put_contents($base . '.body.raw', $corpo);
foreach ($arquivos as $i => $a) {
    @file_put_contents($base . '.file' . $i . '.raw', $a['dados']);
}

Logger::info('filelist: captura recebida', [
    'imei'     => $imei,
    'metodo'   => $metodo,
    'tipo'     => $_SERVER['CONTENT_TYPE'] ?? '',
    'bytes'    => strlen($corpo),
    'arquivos' => count($arquivos),
    'destino'  => basename($base),
]);

// ── 3. Resposta ─────────────────────────────────────────────────────────────
//
// 🔴 JSON, e não texto. A câmera manda `Content-Type: application/json` com
// `Transfer-Encoding: chunked` e corpo VAZIO — três vezes seguidas, medido em
// 14/08/2026. O padrão é de HANDSHAKE: ela anuncia, espera a resposta, e só
// então envia. Respondendo `ok` em texto puro ela desistia e o corpo nunca
// vinha.
//
// O envelope segue o mesmo dos receptores de webhook deste projeto
// (`{"code":0,...}`), que é o que o ecossistema Jimi usa e a câmera
// presumivelmente espera.
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['code' => 0, 'message' => 'success'], JSON_UNESCAPED_UNICODE) . "\n";
