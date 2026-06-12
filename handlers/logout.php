<?php
/**
 * JIMI Webhook System — Logout v3.1.0
 * Endpoint: /logout
 */
require_once __DIR__ . '/../includes/auth.php';
logout_user();
header('Location: /login');
exit;
