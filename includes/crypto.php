<?php
/**
 * JIMI Webhook System — Cifra de segredos em repouso v4.4.1
 *
 * Usado para guardar credenciais de terceiros no banco (hoje: a senha do
 * servidor SMTP em `smtp_settings`). Não é hash — precisamos do valor
 * original para autenticar no provedor, então a operação tem de ser
 * reversível.
 *
 * AES-256-GCM (autenticado: adulterar o ciphertext falha na verificação da
 * tag em vez de devolver lixo silenciosamente). A chave é derivada por
 * SHA-256 de APP_KEY, com WEBHOOK_TOKEN como fallback — a mesma cadeia que
 * includes/csrf.php já usa.
 *
 * ATENÇÃO OPERACIONAL: trocar APP_KEY (ou WEBHOOK_TOKEN, quando é ele que
 * está em uso) torna os segredos já gravados indecifráveis. Não é perda de
 * dado grave — basta recadastrar a senha na tela — mas o envio de e-mail
 * para de funcionar até lá. app_secret_key_source() existe para que a tela
 * avise quando o sistema está dependendo do fallback.
 */

require_once __DIR__ . '/../core/Logger.php';

/** Prefixo de versão do formato — permite trocar de algoritmo depois. */
const APP_CRYPTO_PREFIX = 'v1:';

/**
 * Deriva a chave binária de 32 bytes a partir do segredo do ambiente.
 *
 * @returns string|null 32 bytes, ou null se não há segredo configurado
 */
function app_secret_key(): ?string
{
    $secret = getenv('APP_KEY') ?: getenv('WEBHOOK_TOKEN') ?: '';
    $secret = trim((string)$secret);
    if ($secret === '') {
        return null;
    }
    return hash('sha256', 'jimi-secret-v1|' . $secret, true);
}

/**
 * Informa de onde veio a chave, para a tela poder alertar o administrador.
 *
 * @returns string 'app_key' | 'webhook_token' | 'nenhuma'
 */
function app_secret_key_source(): string
{
    if (trim((string)getenv('APP_KEY')) !== '')       return 'app_key';
    if (trim((string)getenv('WEBHOOK_TOKEN')) !== '') return 'webhook_token';
    return 'nenhuma';
}

/**
 * Cifra um segredo para gravação no banco.
 *
 * @param string $plain Texto puro
 * @returns string|null "v1:base64(iv|tag|ciphertext)", ou null se sem chave
 */
function app_encrypt(string $plain): ?string
{
    if ($plain === '') {
        return null;
    }
    $key = app_secret_key();
    if ($key === null) {
        Logger::error('Cifra: nenhuma chave disponível (APP_KEY/WEBHOOK_TOKEN vazios)');
        return null;
    }

    $iv  = random_bytes(12); // 96 bits, tamanho recomendado para GCM
    $tag = '';
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) {
        Logger::error('Cifra: openssl_encrypt falhou');
        return null;
    }
    return APP_CRYPTO_PREFIX . base64_encode($iv . $tag . $ct);
}

/**
 * Decifra um segredo lido do banco.
 *
 * Devolve '' (e loga) quando o valor não abre — chave trocada, registro
 * corrompido ou adulterado. O chamador trata como "sem senha configurada".
 *
 * @param string|null $stored Valor gravado
 * @returns string Texto puro, ou '' em caso de falha
 */
function app_decrypt(?string $stored): string
{
    if ($stored === null || $stored === '') {
        return '';
    }
    if (strncmp($stored, APP_CRYPTO_PREFIX, strlen(APP_CRYPTO_PREFIX)) !== 0) {
        Logger::warning('Cifra: formato desconhecido no valor armazenado');
        return '';
    }
    $key = app_secret_key();
    if ($key === null) {
        return '';
    }

    $raw = base64_decode(substr($stored, strlen(APP_CRYPTO_PREFIX)), true);
    if ($raw === false || strlen($raw) < 29) { // 12 (iv) + 16 (tag) + >=1
        Logger::warning('Cifra: payload inválido');
        return '';
    }

    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ct  = substr($raw, 28);

    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        Logger::error('Cifra: falha ao decifrar — a chave (APP_KEY/WEBHOOK_TOKEN) mudou?');
        return '';
    }
    return $plain;
}
