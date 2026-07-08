<?php
/**
 * JIMI Webhook System — Painel v4.0.0 (legacy redirect)
 * Endpoint: /dashboard
 *
 * Redireciona para Resumo (/) — nova home page v4.0.0.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();
header('Location: /');
exit;
