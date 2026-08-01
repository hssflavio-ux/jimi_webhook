<?php
/**
 * JIMI Webhook System — Link de download assinado com validade (v4.7.3)
 *
 * PROBLEMA QUE ISTO RESOLVE. Até a v4.7.2 o relatório gerado era servido
 * como arquivo estático em `storage/reports/`, fora de qualquer autenticação
 * (o `.htaccess` da raiz só reescreve o que NÃO é arquivo, `!-f`). A v4.7.1
 * pôs 32 hex aleatórios no nome, o que matou a **enumeração** — mas não fez
 * nada contra o link **vazado ou encaminhado**: uma vez conhecida, a URL valia
 * para sempre, para qualquer um, sem login. E esses links viajam por e-mail,
 * atravessam servidores de terceiros e ficam parados em caixas de entrada.
 *
 * Agora o download passa por `/download`, que exige assinatura HMAC e prazo.
 * O link continua abrindo **sem sessão** — é requisito do e-mail com anexo
 * grande, e era por isso que a opção "rota autenticada" tinha sido descartada
 * na v4.7.1 — mas agora ele **expira**.
 *
 * O segredo é `APP_KEY` (com fallback para `WEBHOOK_TOKEN`, como o resto do
 * projeto). ⚠️ Rotacionar `APP_KEY` invalida todos os links já enviados —
 * o mesmo aviso que vale para as senhas de SMTP cifradas.
 */

/** Validade padrão de um link enviado por e-mail: 7 dias. */
const DOWNLOAD_LINK_TTL = 604800;

/**
 * Segredo de assinatura. Mesma precedência usada por includes/crypto.php.
 *
 * @returns string
 */
function download_secret(): string
{
    return (string)(getenv('APP_KEY') ?: getenv('WEBHOOK_TOKEN') ?: 'jimi-download-fallback-secret');
}

/**
 * Assina o par (job, expiração).
 *
 * Assina o **ID do job**, não o caminho do arquivo: o caminho vem do banco no
 * momento do download, então não há como forjar um caminho nem escapar do
 * diretório por `../` — a superfície de path traversal simplesmente não
 * existe.
 *
 * @param int $jobId     jobs.id
 * @param int $expiresAt Epoch UTC de expiração
 * @returns string HMAC em hex
 */
function download_sign(int $jobId, int $expiresAt): string
{
    return hash_hmac('sha256', $jobId . '|' . $expiresAt, download_secret());
}

/**
 * Confere a assinatura e o prazo.
 *
 * `hash_equals()` compara em tempo constante: comparar com `===` vazaria, pelo
 * tempo de resposta, quantos bytes iniciais o atacante acertou.
 *
 * @param int    $jobId
 * @param int    $expiresAt
 * @param string $sig
 * @returns array{ok:bool, erro:string}
 */
function download_verify(int $jobId, int $expiresAt, string $sig): array
{
    if ($sig === '' || $expiresAt <= 0) {
        return ['ok' => false, 'erro' => 'invalido'];
    }
    if (!hash_equals(download_sign($jobId, $expiresAt), $sig)) {
        return ['ok' => false, 'erro' => 'invalido'];
    }
    // Prazo conferido DEPOIS da assinatura: verificar antes contaria ao
    // atacante que a assinatura estava certa e só o prazo havia passado.
    if ($expiresAt < time()) {
        return ['ok' => false, 'erro' => 'expirado'];
    }
    return ['ok' => true, 'erro' => ''];
}

/**
 * Caminho relativo (`/download?...`) para um job concluído.
 *
 * @param int $jobId
 * @param int $ttl Validade em segundos
 * @returns string
 */
function download_path(int $jobId, int $ttl = DOWNLOAD_LINK_TTL): string
{
    $exp = time() + max(60, $ttl);
    return '/download?j=' . $jobId . '&exp=' . $exp . '&sig=' . download_sign($jobId, $exp);
}

/**
 * URL absoluta, para uso em e-mail.
 *
 * Devolve string vazia quando `APP_URL` não está configurada — cabe a quem
 * chama decidir o que fazer. O `worker.php` aborta a entrega nesse caso, em
 * vez de mandar link relativo (ver v4.7.2).
 *
 * @param int $jobId
 * @param int $ttl
 * @returns string URL absoluta, ou '' se APP_URL estiver vazia
 */
function download_url(int $jobId, int $ttl = DOWNLOAD_LINK_TTL): string
{
    $base = rtrim((string)(getenv('APP_URL') ?: ''), '/');
    return $base === '' ? '' : $base . download_path($jobId, $ttl);
}
