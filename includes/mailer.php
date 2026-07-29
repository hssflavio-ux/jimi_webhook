<?php
/**
 * JIMI Webhook System — Cliente SMTP v4.4.0
 *
 * Envio de e-mail sem dependência externa. O app não tem gerenciador de
 * pacotes (CLAUDE.md), então o cliente é implementado sobre
 * stream_socket_client: EHLO → STARTTLS → AUTH LOGIN → MAIL/RCPT/DATA.
 *
 * Escopo deliberadamente pequeno: AUTH LOGIN, STARTTLS/SSL implícito,
 * corpo HTML e anexo base64. Não implementa AUTH CRAM-MD5, DKIM nem pool
 * de conexões. Se o custo de manutenção crescer, a alternativa natural é
 * trocar por uma API HTTP transacional (curl já está disponível).
 *
 * NUNCA chamar dentro de uma transação de webhook: a conversa SMTP pode
 * levar segundos. Quem envia é scripts/worker.php.
 *
 * Uso:
 *   require_once __DIR__ . '/mailer.php';
 *   $r = send_mail(['fulano@x.com'], 'Assunto', '<p>corpo</p>');
 *   if (!$r['ok']) { ... $r['error'] ... }
 */

require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/crypto.php';

/**
 * Cache das credenciais por request (uma consulta por escopo).
 *
 * Exposto por referência para que smtp_settings_cache_clear() possa
 * esvaziá-lo: quem grava novas credenciais e segue usando a mesma request
 * (a tela /config-smtp faz isso) precisa enxergar o valor novo, não o que
 * foi lido antes do INSERT.
 *
 * @returns array Referência ao cache interno
 */
function &smtp_settings_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Invalida o cache de credenciais. Chamar após gravar/remover configuração.
 *
 * @returns void
 */
function smtp_settings_cache_clear(): void
{
    $cache = &smtp_settings_cache();
    $cache = [];
}

/**
 * Busca a linha de `smtp_settings` aplicável, com precedência
 * cliente → global. Devolve null se a tabela não existe (migração v4.4.1
 * não aplicada) ou se não há configuração ativa.
 *
 * @param int|null $customerId Cliente em cujo nome o e-mail será enviado
 * @returns array|null
 */
function smtp_settings_row(?int $customerId = null): ?array
{
    $cache = &smtp_settings_cache();
    $ck = $customerId === null ? 'global' : (string)$customerId;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }

    $row = null;
    try {
        $db = Database::getInstance()->getConnection();
        // ORDER BY coloca a linha do cliente antes da global (customer_id NULL)
        $stmt = $db->prepare(
            "SELECT * FROM smtp_settings
             WHERE is_active = 1 AND (customer_id = :cid OR customer_id IS NULL)
             ORDER BY (customer_id IS NULL) ASC
             LIMIT 1"
        );
        $stmt->execute([':cid' => $customerId]);
        $row = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        // Sem a tabela, o .env continua respondendo — não é erro fatal
        $row = null;
    }

    $cache[$ck] = $row;
    return $row;
}

/**
 * Resolve a configuração de SMTP em uso.
 *
 * Precedência: credenciais do CLIENTE → credenciais GLOBAIS (ambas em
 * `smtp_settings`, cadastradas em /config-smtp) → variáveis do `.env`.
 * O `.env` sobrevive como fallback para não quebrar instalações que já
 * estavam configuradas antes da v4.4.1 e para permitir subir um ambiente
 * sem passar pela interface.
 *
 * @param int|null $customerId Cliente em cujo nome o e-mail será enviado
 * @returns array{host:string,port:int,secure:string,user:string,pass:string,from:string,from_name:string,timeout:int,source:string}
 */
function mail_config(?int $customerId = null): array
{
    $row = smtp_settings_row($customerId);

    if ($row && trim((string)$row['host']) !== '') {
        $secure = strtolower((string)$row['secure']);
        if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
            $secure = 'tls';
        }
        return [
            'host'      => trim((string)$row['host']),
            'port'      => (int)$row['port'] ?: ($secure === 'ssl' ? 465 : 587),
            'secure'    => $secure,
            'user'      => trim((string)($row['username'] ?? '')),
            'pass'      => app_decrypt($row['password_enc'] ?? null),
            'from'      => trim((string)$row['from_email']),
            'from_name' => trim((string)($row['from_name'] ?? 'Jimi Tracker')),
            'timeout'   => (int)($row['timeout_s'] ?: 20),
            'source'    => $row['customer_id'] === null ? 'banco:global' : 'banco:cliente',
        ];
    }

    $secure = strtolower(trim((string)(getenv('SMTP_SECURE') ?: 'tls')));
    if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
        $secure = 'tls';
    }
    return [
        'host'      => trim((string)getenv('SMTP_HOST')),
        'port'      => (int)(getenv('SMTP_PORT') ?: ($secure === 'ssl' ? 465 : 587)),
        'secure'    => $secure,
        'user'      => trim((string)getenv('SMTP_USER')),
        'pass'      => (string)getenv('SMTP_PASS'),
        'from'      => trim((string)(getenv('SMTP_FROM') ?: 'nao-responda@localhost')),
        'from_name' => trim((string)(getenv('SMTP_FROM_NAME') ?: 'Jimi Tracker')),
        'timeout'   => (int)(getenv('SMTP_TIMEOUT') ?: 20),
        'source'    => 'env',
    ];
}

/**
 * Indica se há SMTP configurado o suficiente para tentar um envio.
 *
 * @param int|null $customerId Escopo a considerar
 * @returns bool
 */
function mail_is_configured(?int $customerId = null): bool
{
    $cfg = mail_config($customerId);
    return $cfg['host'] !== '';
}

/**
 * Codifica um cabeçalho para caracteres não-ASCII (RFC 2047).
 *
 * @param string $text Texto puro
 * @returns string Texto pronto para cabeçalho
 */
function mail_encode_header(string $text): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $text)) {
        return $text;
    }
    return '=?UTF-8?B?' . base64_encode($text) . '?=';
}

/**
 * Valida uma lista de destinatários, descartando entradas inválidas.
 *
 * @param array $recipients Lista crua
 * @returns array Lista apenas com e-mails válidos (reindexada)
 */
function mail_valid_recipients(array $recipients): array
{
    $out = [];
    foreach ($recipients as $r) {
        $r = trim((string)$r);
        if ($r !== '' && filter_var($r, FILTER_VALIDATE_EMAIL)) {
            $out[] = $r;
        }
    }
    return array_values(array_unique($out));
}

/**
 * Monta o corpo MIME completo (cabeçalhos + corpo + anexos).
 *
 * @param array  $cfg         Config de mail_config()
 * @param array  $to          Destinatários válidos
 * @param string $subject     Assunto
 * @param string $htmlBody    Corpo HTML
 * @param array  $attachments [['path'=>string,'name'=>string,'mime'=>string], ...]
 * @returns string Mensagem MIME com CRLF
 */
function mail_build_message(array $cfg, array $to, string $subject, string $htmlBody, array $attachments = []): string
{
    $boundary = 'jimi_' . bin2hex(random_bytes(12));
    $fromName = mail_encode_header($cfg['from_name']);

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $fromName . ' <' . $cfg['from'] . '>',
        'To: ' . implode(', ', $to),
        'Subject: ' . mail_encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (parse_url('http://' . $cfg['host'], PHP_URL_HOST) ?: 'localhost') . '>',
        'MIME-Version: 1.0',
        'X-Mailer: JIMI Webhook System',
    ];

    if (empty($attachments)) {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $body = chunk_split(base64_encode($htmlBody));
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

    $parts = [];
    $parts[] = "--{$boundary}\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: base64\r\n\r\n"
             . chunk_split(base64_encode($htmlBody));

    foreach ($attachments as $att) {
        $path = $att['path'] ?? '';
        if ($path === '' || !is_readable($path)) {
            Logger::warning('Mailer: anexo ignorado (ilegível)', ['path' => $path]);
            continue;
        }
        $name = $att['name'] ?? basename($path);
        $mime = $att['mime'] ?? 'application/octet-stream';
        $data = file_get_contents($path);
        if ($data === false) {
            continue;
        }
        $parts[] = "--{$boundary}\r\n"
                 . "Content-Type: {$mime}; name=\"" . mail_encode_header($name) . "\"\r\n"
                 . "Content-Transfer-Encoding: base64\r\n"
                 . "Content-Disposition: attachment; filename=\"" . mail_encode_header($name) . "\"\r\n\r\n"
                 . chunk_split(base64_encode($data));
    }

    return implode("\r\n", $headers) . "\r\n\r\n"
         . implode("\r\n", $parts)
         . "--{$boundary}--\r\n";
}

/**
 * Lê uma resposta do servidor SMTP (trata continuação multilinha "250-").
 *
 * @param resource $sock  Socket aberto
 * @returns array{code:int,text:string}
 */
function smtp_read($sock): array
{
    $text = '';
    $code = 0;
    while (($line = fgets($sock, 1024)) !== false) {
        $text .= $line;
        // "250-EXTENSAO" continua; "250 OK" encerra
        if (strlen($line) >= 4 && $line[3] === ' ') {
            $code = (int)substr($line, 0, 3);
            break;
        }
        if (strlen($line) >= 4 && $line[3] !== '-') {
            $code = (int)substr($line, 0, 3);
            break;
        }
    }
    return ['code' => $code, 'text' => trim($text)];
}

/**
 * Envia um comando e devolve a resposta.
 *
 * @param resource $sock Socket aberto
 * @param string   $cmd  Comando sem CRLF
 * @returns array{code:int,text:string}
 */
function smtp_cmd($sock, string $cmd): array
{
    fwrite($sock, $cmd . "\r\n");
    return smtp_read($sock);
}

/**
 * Envia um e-mail via SMTP.
 *
 * Falhas são devolvidas (nunca lançadas) para que o worker decida sobre
 * retry — SMTP indisponível é condição transitória, não erro de programa.
 *
 * @param array    $to          Destinatários
 * @param string   $subject     Assunto
 * @param string   $htmlBody    Corpo HTML
 * @param array    $attachments [['path'=>string,'name'=>string,'mime'=>string], ...]
 * @param int|null $customerId  Cliente cujas credenciais devem ser usadas
 *                              (null = configuração global)
 * @returns array{ok:bool,error:?string}
 */
function send_mail(array $to, string $subject, string $htmlBody, array $attachments = [], ?int $customerId = null): array
{
    $cfg = mail_config($customerId);
    $to  = mail_valid_recipients($to);

    if (empty($to)) {
        return ['ok' => false, 'error' => 'Nenhum destinatário válido'];
    }
    if ($cfg['host'] === '') {
        return ['ok' => false, 'error' => 'Servidor SMTP não cadastrado (ver Cadastros › Servidor de E-mail)'];
    }

    $transport = ($cfg['secure'] === 'ssl' ? 'ssl://' : 'tcp://') . $cfg['host'] . ':' . $cfg['port'];
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_client($transport, $errno, $errstr, $cfg['timeout'],
        STREAM_CLIENT_CONNECT, stream_context_create([]));

    if (!$sock) {
        return ['ok' => false, 'error' => "Conexão falhou ($transport): $errstr"];
    }
    stream_set_timeout($sock, $cfg['timeout']);

    try {
        $r = smtp_read($sock);
        if ($r['code'] !== 220) {
            throw new RuntimeException('Saudação inesperada: ' . $r['text']);
        }

        $ehloHost = gethostname() ?: 'localhost';
        $r = smtp_cmd($sock, 'EHLO ' . $ehloHost);
        if ($r['code'] !== 250) {
            throw new RuntimeException('EHLO recusado: ' . $r['text']);
        }

        if ($cfg['secure'] === 'tls') {
            $r = smtp_cmd($sock, 'STARTTLS');
            if ($r['code'] !== 220) {
                throw new RuntimeException('STARTTLS recusado: ' . $r['text']);
            }
            $ok = @stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if (!$ok) {
                throw new RuntimeException('Handshake TLS falhou');
            }
            // Após o STARTTLS o EHLO precisa ser repetido (RFC 3207 §4.2)
            $r = smtp_cmd($sock, 'EHLO ' . $ehloHost);
            if ($r['code'] !== 250) {
                throw new RuntimeException('EHLO pós-TLS recusado: ' . $r['text']);
            }
        }

        if ($cfg['user'] !== '') {
            $r = smtp_cmd($sock, 'AUTH LOGIN');
            if ($r['code'] !== 334) {
                throw new RuntimeException('AUTH LOGIN recusado: ' . $r['text']);
            }
            $r = smtp_cmd($sock, base64_encode($cfg['user']));
            if ($r['code'] !== 334) {
                throw new RuntimeException('Usuário recusado: ' . $r['text']);
            }
            $r = smtp_cmd($sock, base64_encode($cfg['pass']));
            if ($r['code'] !== 235) {
                throw new RuntimeException('Autenticação falhou: ' . $r['text']);
            }
        }

        $r = smtp_cmd($sock, 'MAIL FROM:<' . $cfg['from'] . '>');
        if ($r['code'] !== 250) {
            throw new RuntimeException('MAIL FROM recusado: ' . $r['text']);
        }

        foreach ($to as $rcpt) {
            $r = smtp_cmd($sock, 'RCPT TO:<' . $rcpt . '>');
            // 251 = will forward
            if ($r['code'] !== 250 && $r['code'] !== 251) {
                throw new RuntimeException("RCPT TO <$rcpt> recusado: " . $r['text']);
            }
        }

        $r = smtp_cmd($sock, 'DATA');
        if ($r['code'] !== 354) {
            throw new RuntimeException('DATA recusado: ' . $r['text']);
        }

        $message = mail_build_message($cfg, $to, $subject, $htmlBody, $attachments);
        // Dot-stuffing (RFC 5321 §4.5.2): linha iniciada por "." encerraria o DATA
        $message = preg_replace('/^\./m', '..', $message);

        fwrite($sock, $message . "\r\n.\r\n");
        $r = smtp_read($sock);
        if ($r['code'] !== 250) {
            throw new RuntimeException('Mensagem recusada: ' . $r['text']);
        }

        @smtp_cmd($sock, 'QUIT');
        fclose($sock);

        Logger::info('Mailer: e-mail enviado', ['to' => $to, 'subject' => $subject]);
        return ['ok' => true, 'error' => null];

    } catch (Throwable $e) {
        if (is_resource($sock)) {
            @fclose($sock);
        }
        Logger::error('Mailer: falha no envio', [
            'to' => $to, 'subject' => $subject, 'error' => $e->getMessage(),
        ]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
