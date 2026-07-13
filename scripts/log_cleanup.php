<?php
/**
 * JIMI Webhook System — Limpeza e rotação de logs (cron diário)
 *
 * 1. Rotação por tamanho: logs de append contínuo (worker.log, trip_builder.log,
 *    metrics.log…) nunca são pegos pelo purge por idade (o mtime está sempre
 *    fresco) — acima de LOG_MAX_SIZE_MB viram <nome>.log.old (substituindo o
 *    anterior; o redirect do cron recria o arquivo na próxima execução).
 * 2. Purga por idade: Logger::cleanOldLogs() remove *.log e *.log.old com
 *    mtime além de LOG_RETENTION_DAYS (default 30) — inclui os webhook_* datados
 *    e órfãos de writers antigos.
 *
 * NÃO usa a classe Database: o construtor dela dá exit em falha de conexão e a
 * limpeza de logs precisa rodar mesmo com o banco fora. O .env é lido aqui só
 * para LOG_RETENTION_DAYS / LOG_MAX_SIZE_MB.
 *
 * Instalação: scripts/crontab-setup.sh (diário às 03:10).
 */

require_once __DIR__ . '/../core/Logger.php';

// Parse mínimo do .env (mesmo formato do config/database.php)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        if (!getenv(trim($key))) putenv(trim($key) . '=' . trim($value));
    }
}

$days  = max(1, (int)(getenv('LOG_RETENTION_DAYS') ?: 30));
$maxMb = max(1, (int)(getenv('LOG_MAX_SIZE_MB') ?: 10));
$logDir = __DIR__ . '/../logs';

// 1) Rotação por tamanho (só arquivos NÃO datados — os datados o purge resolve)
$rotated = 0;
foreach (glob($logDir . '/*.log') ?: [] as $file) {
    if (preg_match('/_\d{4}-\d{2}-\d{2}\.log$/', $file)) continue;
    if (filesize($file) > $maxMb * 1024 * 1024) {
        @unlink($file . '.old'); // rename não sobrescreve no Windows
        if (@rename($file, $file . '.old')) $rotated++;
    }
}

// 2) Purga por idade
$before = count(glob($logDir . '/*.log*') ?: []);
Logger::cleanOldLogs($days);
$after = count(glob($logDir . '/*.log*') ?: []);

echo sprintf(
    "[%s] log_cleanup OK — retenção %dd, teto %dMB: %d rotacionado(s), %d removido(s), %d arquivo(s) restante(s)\n",
    date('Y-m-d H:i:s'),
    $days,
    $maxMb,
    $rotated,
    max(0, $before - $after),
    $after
);
