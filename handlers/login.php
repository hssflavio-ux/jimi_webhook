<?php
/**
 * JIMI Webhook System — Login v3.1.0
 * Endpoint: /login
 */
require_once __DIR__ . '/../config/database.php';

$db  = Database::getInstance()->getConnection();
$tz_brt = new DateTimeZone('America/Sao_Paulo');

$hasUsers = (bool) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../includes/auth.php';

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirect = $_POST['redirect'] ?? '/dashboard';

    $result = login_user($email, $password);

    if ($result['success']) {
        header('Location: ' . $redirect);
        exit;
    }
    $error = $result['error'];
} else {
    if (!$hasUsers) {
        header('Location: /setup');
        exit;
    }
    $redirect = $_GET['redirect'] ?? '/dashboard';
}

$error = $error ?? null;
include __DIR__ . '/../web/login_template.php';
