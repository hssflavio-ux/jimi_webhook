<?php
/**
 * JIMI Webhook System — Servidor de mídia v4.9.1
 * Rota: /midia?f={arquivo}
 *
 * Serve os vídeos extraídos do cartão da câmera pela NOSSA origem, lendo do
 * diretório onde o FTP os deposita (VIDEO_MEDIA_DIR).
 *
 * POR QUE ISTO EXISTE — o `FILE_STORAGE_URL` (container `dvr-upload`, porta
 * 23010) serve os mesmos arquivos, e três propriedades dele impedem tocar o
 * vídeo na página, todas medidas contra o servidor real:
 *
 *   1. **Sem CORS.** O player de MPEG-TS (mpegts.js) precisa BUSCAR os bytes
 *      por `fetch` para remuxar; porta diferente é origem diferente, e sem
 *      `Access-Control-Allow-Origin` o navegador bloqueia. O sintoma era
 *      `NetworkError: Failed to fetch`. Um `<video src>` ou um link de
 *      download não passam por CORS — por isso baixar funcionava e tocar não.
 *   2. **Sem `Accept-Ranges`.** `Range: bytes=0-1000` devolvia HTTP 200 com os
 *      21 MB inteiros, não 206. Sem Range não há como arrastar a barra: o
 *      navegador tem de baixar o arquivo todo antes de mostrar qualquer coisa.
 *   3. **`Content-Disposition: attachment`.** Manda o navegador baixar em vez
 *      de exibir, mesmo num `<video>`.
 *
 * E há um ganho de segurança: a 23010 está aberta ao mundo **sem autenticação
 * nenhuma** — quem souber o nome do arquivo baixa o vídeo. Aqui o acesso exige
 * sessão e o arquivo tem de pertencer a um equipamento do cliente logado.
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

$db  = Database::getInstance()->getConnection();
$cid = get_customer_id();

/**
 * Diretório onde o FTP da câmera deposita as gravações. Mesmo caminho que o
 * container `dvr-upload` publica — o arquivo entra por FTP e sai por aqui.
 */
$baseDir = rtrim((string)(getenv('VIDEO_MEDIA_DIR') ?: '/iothub/dvr-upload/uploadFile'), '/');

$pedido = (string)($_GET['f'] ?? '');

// basename() antes de qualquer coisa: o parâmetro vem da URL, e `../` aqui
// leria qualquer arquivo do servidor que o www-data alcance.
$arquivo = basename($pedido);
if ($arquivo === '' || $arquivo !== $pedido) {
    http_response_code(400);
    exit('Nome de arquivo inválido.');
}

// Escopo multi-tenant: o arquivo precisa pertencer a um equipamento DESTE
// cliente. Sem isto, bastaria adivinhar o nome para ler o vídeo de outro
// tenant — que é o que a porta 23010 permite hoje.
//
// São DUAS origens, e conferir só a primeira era o motivo de o anexo de alarme
// não tocar (v4.9.8):
//   1. `media_files` — o que a fila de extração (37382) e os uploads do IoTHub
//      registram;
//   2. `alarms.file_url` — o vídeo do evento que a câmera JIMI sobe sozinha e
//      ANUNCIA no próprio push do alarme (ADR-001). Esse arquivo existe no
//      disco desde sempre e nunca teve linha em `media_files`.
// A migração v4.9.8 passou a criar a linha de (1) também para (2), mas a
// verificação continua aceitando as duas: os alarmes já gravados antes dela
// não deixam de ser do cliente por isso.
try {
    // Fase 2 do fluxo chip→câmera→veículo: dono GRAVADO no arquivo/alarme
    // (snapshot do momento em que chegou), não o dono ATUAL da câmera —
    // senão trocar a câmera de cliente destrancaria vídeo antigo pra quem
    // tem a câmera agora, e trancaria pra quem era dono quando foi gravado.
    $stmt = $db->prepare("
        SELECT 1
          FROM media_files mf
         WHERE mf.file_url = :f
           AND (:cid IS NULL OR mf.customer_id = :cid2)
         LIMIT 1");
    $stmt->execute([':f' => $arquivo, ':cid' => $cid, ':cid2' => $cid]);
    $autorizado = (bool)$stmt->fetchColumn();

    if (!$autorizado) {
        $stmt = $db->prepare("
            SELECT 1
              FROM alarms a
             WHERE a.file_url = :f
               AND (:cid IS NULL OR a.customer_id = :cid2)
             LIMIT 1");
        $stmt->execute([':f' => $arquivo, ':cid' => $cid, ':cid2' => $cid]);
        $autorizado = (bool)$stmt->fetchColumn();
    }

    if (!$autorizado) {
        http_response_code(404);
        Logger::warning('midia: arquivo fora do escopo do cliente', [
            'arquivo' => $arquivo, 'customer_id' => $cid,
        ]);
        exit('Arquivo não encontrado.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    Logger::error('midia: falha ao validar escopo', ['error' => $e->getMessage()]);
    exit('Erro interno.');
}

// ── Carimbo de "já baixado" (v4.9.37) ───────────────────────────────────────
//
// 🔴 SÓ COM `dl=1`, e a distinção não é preciosismo: o player de MPEG-TS busca
// os bytes por esta MESMA URL, por `fetch`, para remuxar. Carimbar em toda
// leitura faria "assistir" virar "baixado", e a fila de downloads passaria a
// mentir exatamente na coluna que ela existe para responder — "isto aqui eu já
// levei?". Quem manda `dl=1` é o link com atributo `download`, mais nada.
//
// Fora de qualquer `if` de erro: o carimbo vem ANTES do envio dos bytes porque
// um download interrompido no meio ainda foi um download iniciado pela pessoa;
// e falhar aqui não pode impedir o arquivo de sair.
if (!empty($_GET['dl'])) {
    try {
        $db->prepare("
            UPDATE media_files
               SET downloaded_at  = COALESCE(downloaded_at, UTC_TIMESTAMP()),
                   downloaded_by  = COALESCE(downloaded_by, :uid),
                   download_count = download_count + 1
             WHERE file_url = :f
        ")->execute([':uid' => (int)($_SESSION['user_id'] ?? 0) ?: null, ':f' => $arquivo]);
    } catch (Throwable $e) {
        Logger::warning('midia: falha ao carimbar download', [
            'arquivo' => $arquivo, 'error' => $e->getMessage(),
        ]);
    }
}

$caminho = $baseDir . '/' . $arquivo;
if (!is_file($caminho) || !is_readable($caminho)) {
    http_response_code(404);
    Logger::warning('midia: arquivo ausente no disco', ['caminho' => $caminho]);
    exit('Arquivo não encontrado.');
}

$tamanho = filesize($caminho);
$ext     = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));
$mimes   = [
    'ts'  => 'video/mp2t',   // MPEG-TS: o formato que a câmera grava
    'mp4' => 'video/mp4',
    'flv' => 'video/x-flv',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

// `inline` (e não `attachment`): quem quiser baixar usa o atributo `download`
// do link, que o navegador respeita mesmo com inline.
if (ob_get_level()) ob_end_clean();
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $arquivo . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=600');
header('X-Content-Type-Options: nosniff');

// ── Range (HTTP 206) ─────────────────────────────────────────────────────────
// É o que permite arrastar a barra do player e o que o mpegts.js usa para não
// puxar 21 MB antes do primeiro quadro.
$inicio = 0;
$fim    = $tamanho - 1;
$parcial = false;

if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    $rIni = $m[1] === '' ? null : (int)$m[1];
    $rFim = $m[2] === '' ? null : (int)$m[2];

    if ($rIni === null && $rFim !== null) {
        // "bytes=-N" = os N últimos bytes
        $inicio = max(0, $tamanho - $rFim);
    } elseif ($rIni !== null) {
        $inicio = $rIni;
        if ($rFim !== null) $fim = min($rFim, $tamanho - 1);
    }

    if ($inicio > $fim || $inicio >= $tamanho) {
        http_response_code(416);
        header('Content-Range: bytes */' . $tamanho);
        exit;
    }
    $parcial = true;
}

if ($parcial) {
    http_response_code(206);
    header('Content-Range: bytes ' . $inicio . '-' . $fim . '/' . $tamanho);
}
header('Content-Length: ' . ($fim - $inicio + 1));

// Envia em blocos de 512 KB: `readfile()` de um arquivo de 20 MB carrega tudo
// em memória, e o worker do PHP-FPM tem memory_limit.
$fp = fopen($caminho, 'rb');
if ($fp === false) {
    http_response_code(500);
    exit;
}
fseek($fp, $inicio);
$restante = $fim - $inicio + 1;
while ($restante > 0 && !feof($fp) && !connection_aborted()) {
    $bloco = fread($fp, (int)min(524288, $restante));
    if ($bloco === false || $bloco === '') break;
    echo $bloco;
    $restante -= strlen($bloco);
    flush();
}
fclose($fp);
exit;
