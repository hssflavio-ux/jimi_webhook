<?php
/**
 * JIMI Webhook System — Limpeza e rotação de logs e relatórios (cron diário)
 *
 * 1. Rotação por tamanho: logs de append contínuo (worker.log, trip_builder.log,
 *    metrics.log…) nunca são pegos pelo purge por idade (o mtime está sempre
 *    fresco) — acima de LOG_MAX_SIZE_MB viram <nome>.log.old (substituindo o
 *    anterior; o redirect do cron recria o arquivo na próxima execução).
 * 2. Purga por idade: Logger::cleanOldLogs() remove *.log e *.log.old com
 *    mtime além de LOG_RETENTION_DAYS (default 30) — inclui os webhook_* datados
 *    e órfãos de writers antigos.
 * 3. Purga das capturas do FILELIST (v4.9.34): `logs/filelist/` guarda o corpo
 *    CRU que a câmera sobe (~78 KB por listagem, e ela sobe a mesma lista duas
 *    vezes por disparo). Ele fica de propósito — foi essa captura que resolveu
 *    a investigação da v4.9.33, e é o que permitirá diagnosticar o próximo
 *    firmware que mudar o formato —, mas `Logger::cleanOldLogs()` só varre
 *    `*.log`, então até aqui nada limpava esse diretório. Agora que a tela de
 *    playback dispara o upload de verdade (v4.9.34), ele cresce sozinho.
 *    Mesma retenção dos logs (LOG_RETENTION_DAYS).
 * 4. Purga do payload CRU dos webhooks (v4.17.12): `webhook_payloads` guarda
 *    o corpo exato de tudo que os equipamentos enviam. Medido em produção:
 *    5.429 requisições/dia com 12 equipamentos (~2,7 MB/dia), então a tabela
 *    cresce sozinha e proporcional à frota. RAW_PAYLOAD_RETENTION_DAYS
 *    (default 30); 0 desliga a purga.
 * 5. Purga de relatórios (v4.7.1): storage/reports cresce para sempre — um
 *    agendamento diário gera 1 arquivo por dia, e cada arquivo é uma cópia de
 *    dado de cliente parada em disco. REPORT_RETENTION_DAYS (default 30) apaga
 *    os arquivos antigos e, junto, as linhas de report_schedule_runs além da
 *    mesma idade, para o histórico não crescer sem teto.
 *
 * ⚠️ A numeração já andou duas vezes (FILELIST na v4.9.34, payload cru na
 * v4.17.12): o item 5 é o antigo 3. A conexão PDO citada logo abaixo é a
 * dele, e o item 4 abre a sua própria pelo mesmo motivo.
 *
 * NÃO usa a classe Database: o construtor dela dá exit em falha de conexão e a
 * limpeza precisa rodar mesmo com o banco fora. A conexão do item 3 é PDO
 * direto, dentro de try/catch — banco fora apenas pula a purga do histórico,
 * sem impedir a limpeza de arquivos.
 *
 * storage/media fica INTOCADO: vídeo de ocorrência é evidência vinculada a uma
 * tratativa, não subproduto de consulta. Apagá-lo por idade é decisão de
 * produto, não de higiene de disco.
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

// 3) Capturas do FILELIST — `.body.raw`, `.meta.json`, `.parse.json`, `.fileN.raw`
//
// Por IDADE do arquivo, não por contagem: uma câmera listada uma única vez em
// três meses não pode perder a captura dela porque outra foi listada mil vezes.
// O diretório pode não existir (instalação sem câmera JIMI).
$filelistDir = $logDir . '/filelist';
$capturasRemovidas = 0;
$limiteCaptura = time() - $days * 86400;
foreach (glob($filelistDir . '/*') ?: [] as $arquivoCaptura) {
    if (!is_file($arquivoCaptura)) continue;
    if (filemtime($arquivoCaptura) < $limiteCaptura && @unlink($arquivoCaptura)) {
        $capturasRemovidas++;
    }
}

echo sprintf(
    "[%s] log_cleanup OK — retenção %dd, teto %dMB: %d rotacionado(s), %d removido(s), %d arquivo(s) restante(s), %d captura(s) de FILELIST removida(s)\n",
    Logger::stamp(),
    $days,
    $maxMb,
    $rotated,
    max(0, $before - $after),
    $after,
    $capturasRemovidas
);

// ════════════════════════════════════════════════════════════
// 4) Purga do payload CRU dos webhooks (v4.17.12)
//
// 🔴 ANTES da purga de relatórios de propósito: aquela seção faz `exit(0)`
// quando REPORT_RETENTION_DAYS é 0, e um bloco colocado depois dela nunca
// rodaria para quem desligou a purga de relatórios — a tabela cresceria para
// sempre, em silêncio.
//
// Dimensionado em produção (05/09/2026): 5.429 requisições/dia com 12
// equipamentos, ~2,7 MB/dia. Em 30 dias, ~80 MB. Escala com a frota.
// ════════════════════════════════════════════════════════════
// Mesmo cuidado com `?:` da seção abaixo: '0' é falsy, e o operador devolveria
// o default de 30 justamente para quem escreveu 0 querendo DESLIGAR a purga.
$rawWebhook  = getenv('RAW_PAYLOAD_RETENTION_DAYS');
$rawWebhook  = ($rawWebhook === false) ? '' : trim($rawWebhook);
$webhookDays = ($rawWebhook === '') ? 30 : (int)$rawWebhook;

if ($webhookDays <= 0) {
    echo sprintf("[%s] webhook_payloads — purga desligada (RAW_PAYLOAD_RETENTION_DAYS=%s)
",
        Logger::stamp(), $rawWebhook);
} else {
    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                getenv('DB_HOST') ?: 'localhost', getenv('DB_PORT') ?: '3306',
                getenv('DB_NAME') ?: 'jimi_tracker'),
            getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        // Apaga em lotes: um DELETE único de centenas de milhares de linhas
        // segura o binlog e trava a tabela que os webhooks estão escrevendo.
        $totalRemovido = 0;
        do {
            $st = $pdo->prepare(
                'DELETE FROM webhook_payloads
                  WHERE received_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)
                  LIMIT 5000'
            );
            $st->bindValue(':d', $webhookDays, PDO::PARAM_INT);
            $st->execute();
            $lote = $st->rowCount();
            $totalRemovido += $lote;
        } while ($lote === 5000 && $totalRemovido < 500000);
        echo sprintf("[%s] webhook_payloads OK — retenção %dd: %d linha(s) removida(s)
",
            Logger::stamp(), $webhookDays, $totalRemovido);
    } catch (Throwable $e) {
        // Tabela ainda não criada (deploy trouxe o código antes da migração —
        // ver CLAUDE.md) não pode derrubar a limpeza de disco.
        echo sprintf("[%s] webhook_payloads — não purgado (%s)
",
            Logger::stamp(), $e->getMessage());
    }
}
// ════════════════════════════════════════════════════════════
// 5) Purga de relatórios gerados (v4.7.1)
// ════════════════════════════════════════════════════════════
// `?:` não serve para ler este valor: '0' é falsy em PHP e o operador
// devolveria o default de 30 dias justamente para quem escreveu 0 querendo
// DESLIGAR a purga — a mesma armadilha documentada em occurrence_engine.php.
$rawRetention   = getenv('REPORT_RETENTION_DAYS');
$rawRetention   = ($rawRetention === false) ? '' : trim($rawRetention);
$reportDays     = ($rawRetention === '') ? 30 : (int)$rawRetention;
$reportsDir     = __DIR__ . '/../storage/reports';

if ($reportDays <= 0) {
    echo sprintf("[%s] report_cleanup — desligado (REPORT_RETENTION_DAYS=%s)\n",
        Logger::stamp(), $rawRetention);
    exit(0);
}

$cutoff        = time() - ($reportDays * 86400);
$filesRemoved  = 0;
$bytesFreed    = 0;
$filesKept     = 0;

foreach (glob($reportsDir . '/*') ?: [] as $file) {
    if (!is_file($file)) continue;
    // O .htaccess mora em storage/, não aqui; ainda assim, arquivo de
    // configuração nunca entra na purga por idade.
    if (basename($file) === '.htaccess' || basename($file) === '.gitkeep') continue;

    if (filemtime($file) < $cutoff) {
        $size = (int)filesize($file);
        if (@unlink($file)) {
            $filesRemoved++;
            $bytesFreed += $size;
        }
    } else {
        $filesKept++;
    }
}

// Histórico de execuções dos agendamentos: mesma idade dos arquivos.
// Só as LINHAS DE EXECUÇÃO são apagadas — o agendamento em si permanece.
$runsRemoved = null;   // null = não foi possível consultar o banco
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_PORT') ?: '3306',
        getenv('DB_NAME') ?: 'jimi_tracker'
    );
    $pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT            => 5,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // UTC_TIMESTAMP() é UTC qualquer que seja o time_zone da sessão — a coluna
    // executed_at grava UTC, então a comparação fecha sem depender da conexão.
    $stmt = $pdo->prepare(
        "DELETE FROM report_schedule_runs
         WHERE executed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)"
    );
    $stmt->bindValue(':d', $reportDays, PDO::PARAM_INT);
    $stmt->execute();
    $runsRemoved = $stmt->rowCount();
} catch (Throwable $e) {
    // Banco fora não pode derrubar a limpeza de disco, que é o motivo de este
    // script não usar a classe Database.
    echo sprintf("[%s] report_cleanup — histórico não purgado (banco indisponível: %s)\n",
        Logger::stamp(), $e->getMessage());
}

echo sprintf(
    "[%s] report_cleanup OK — retenção %dd: %d arquivo(s) removido(s) (%.1f MB), %d mantido(s), %s execução(ões) de histórico removida(s)\n",
    Logger::stamp(),
    $reportDays,
    $filesRemoved,
    $bytesFreed / 1048576,
    $filesKept,
    $runsRemoved === null ? 'nenhuma' : $runsRemoved
);
