<?php
/**
 * JIMI Webhook System — Vídeo v4.0.0 (legacy redirect)
 * Endpoint: /video
 *
 * A tela unificada v3.x foi substituída pelo grupo Vídeos:
 * /video/aovivo, /video/playback e /video/downloads.
 * Preserva o parâmetro ?imei= quando presente.
 */
require_once __DIR__ . '/../includes/auth.php';
require_login();

$target = (($_GET['mode'] ?? '') === 'recorded') ? '/video/downloads' : '/video/aovivo';
$imei = $_GET['imei'] ?? '';
if ($imei !== '' && preg_match('/^\d{15,17}$/', $imei)) {
    $target .= '?imei=' . urlencode($imei);
}
header('Location: ' . $target);
exit;
