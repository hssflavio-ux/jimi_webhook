<?php
/**
 * JIMI Webhook System — Rastreamento ao Vivo v4.0.0 (legacy)
 * Endpoint: /live
 *
 * Redireciona para /rastreamento — nova página de rastreamento.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();
header('Location: /rastreamento');
exit;
