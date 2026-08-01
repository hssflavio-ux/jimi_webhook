<?php
/**
 * JIMI Webhook System — Download assinado (v4.7.3)
 * Rota: GET /download?j=<job_id>&exp=<epoch>&sig=<hmac>
 *
 * Serve o arquivo de um job concluído mediante assinatura válida e dentro do
 * prazo. **NÃO exige login de propósito**: é este o link que vai no e-mail do
 * relatório grande, e exigir sessão quebraria justamente o caminho que a
 * Fase 4 (v4.7.0) criou. A autorização aqui é a assinatura, não o cookie.
 *
 * Substitui o link direto para `storage/reports/`, que era estático, sem
 * autenticação e — depois que o nome ganhou 32 hex na v4.7.1 — inadivinhável,
 * porém **eterno**. Ver includes/download_token.php.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/download_token.php';

/**
 * Encerra com uma página de erro simples.
 *
 * A mensagem é deliberadamente igual para assinatura inválida e job
 * inexistente: distinguir os dois diria a quem tenta se o job existe.
 *
 * @param int    $code
 * @param string $titulo
 * @param string $texto
 * @returns void
 */
function download_erro(int $code, string $titulo, string $texto): void
{
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . htmlspecialchars($titulo) . '</title></head>'
       . '<body style="margin:0;padding:48px 24px;background:#f5f6f8;'
       . 'font-family:Helvetica,Arial,sans-serif;color:#0a0b0d;text-align:center;">'
       . '<div style="max-width:420px;margin:0 auto;background:#fff;border:1px solid #e6e8eb;'
       . 'border-radius:12px;padding:32px;">'
       . '<h1 style="margin:0 0 12px;font-size:18px;font-weight:600;">' . htmlspecialchars($titulo) . '</h1>'
       . '<p style="margin:0;font-size:14px;line-height:1.6;color:#5b616e;">' . htmlspecialchars($texto) . '</p>'
       . '</div></body></html>';
    exit;
}

$jobId = (int)($_GET['j'] ?? 0);
$exp   = (int)($_GET['exp'] ?? 0);
$sig   = (string)($_GET['sig'] ?? '');

// ⚠️ A CONEXÃO VEM ANTES DA VERIFICAÇÃO, e a ordem importa: `config/database.php`
// só parseia o `.env` dentro de `Database::getInstance()`. Verificar a
// assinatura antes disso faria `getenv('APP_KEY')` devolver false, o segredo
// cair no fallback e TODO link legítimo ser recusado com 403 — falha total e
// silenciosa do recurso, que foi exatamente o que o teste pegou.
try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    download_erro(503, 'Indisponível', 'Não foi possível recuperar o arquivo agora. Tente de novo em instantes.');
}

$check = download_verify($jobId, $exp, $sig);
if (!$check['ok']) {
    if ($check['erro'] === 'expirado') {
        download_erro(410, 'Link expirado',
            'Este link de download passou da validade. Gere o relatório novamente em Exportar Relatórios.');
    }
    download_erro(403, 'Link inválido',
        'Não foi possível validar este link de download.');
}

try {
    $stmt = $db->prepare("SELECT status, result_path FROM jobs WHERE id = :id");
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();
} catch (Throwable $e) {
    download_erro(503, 'Indisponível', 'Não foi possível recuperar o arquivo agora. Tente de novo em instantes.');
}

if (!$job || $job['status'] !== 'concluido' || empty($job['result_path'])) {
    download_erro(404, 'Arquivo não encontrado', 'Este relatório não está mais disponível.');
}

// `basename()` fecha qualquer tentativa de escapar do diretório caso um
// result_path torto entre no banco por outro caminho. O diretório é fixo aqui,
// nunca vem da requisição.
$arquivo = basename((string)$job['result_path']);
$abs     = __DIR__ . '/../storage/reports/' . $arquivo;

if ($arquivo === '' || !is_file($abs)) {
    // Caso normal depois da purga por REPORT_RETENTION_DAYS
    download_erro(410, 'Arquivo expirado',
        'O arquivo foi removido pela política de retenção. Gere o relatório novamente em Exportar Relatórios.');
}

$mimes = [
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'csv'  => 'text/csv; charset=utf-8',
    'pdf'  => 'application/pdf',
];
$ext = strtolower(pathinfo($arquivo, PATHINFO_EXTENSION));

if (ob_get_level()) ob_end_clean();
header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: attachment; filename="' . $arquivo . '"');
// Link assinado e temporário não deve ficar em cache compartilhado
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($abs);
