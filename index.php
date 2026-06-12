<?php
/**
 * JIMI Webhook System — Entry Point v3.1.0
 *
 * Acesso direto via http://189.22.240.43/
 * Redireciona para /dashboard (se autenticado) ou /login.
 */
require_once __DIR__ . '/includes/auth.php';
auth_init();

if (!empty($_SESSION['user_id'])) {
    header('Location: /dashboard');
} else {
    header('Location: /login');
}
exit;
