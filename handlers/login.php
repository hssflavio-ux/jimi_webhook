<?php
/**
 * JIMI Webhook System — Login v3.1.0
 * Endpoint: /login
 */
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    $redirect = $_GET['redirect'] ?? '/dashboard';
}

$db = Database::getInstance()->getConnection();
$hasUsers = (bool) $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
if (!$hasUsers && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Location: /setup');
    exit;
}

$error = $error ?? null;
include __DIR__ . '/../web/login_template.php';
