<?php
/**
 * JIMI Webhook System — CSRF Protection v4.0.0
 *
 * Anti-CSRF token generation and validation.
 * Token stored in session, valid for current session lifetime.
 *
 * Usage:
 *   In forms: <?= csrf_field() ?>  → outputs hidden input
 *   In POST handler: csrf_verify()  → exits with 403 if invalid
 */

define('CSRF_TOKEN_KEY', '_csrf_token');

function csrf_generate(): string {
    if (empty($_SESSION[CSRF_TOKEN_KEY])) {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_KEY];
}

function csrf_token(): string {
    return csrf_generate();
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf_token" value="' . htmlspecialchars(csrf_generate()) . '">';
}

function csrf_verify(bool $exit_on_fail = true): bool {
    $token = $_POST['_csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $valid = !empty($token) && hash_equals(csrf_generate(), $token);
    if (!$valid && $exit_on_fail) {
        http_response_code(403);
        echo json_encode(['code' => 403, 'message' => 'CSRF token inválido']);
        exit;
    }
    return $valid;
}
