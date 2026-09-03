<?php
/**
 * JIMI Webhook System — Captura de Status em Tempo Real do Terminal
 * Arquivo: handlers/pushterminalrealtimestatus.php
 *
 * Endpoint de DIAGNÓSTICO. Recebe o push de "Real-Time Status" das câmeras
 * e apenas registra o payload bruto em arquivo — não valida token, não
 * normaliza e não persiste nada no banco. Os dados que chegam aqui não são
 * usados por nenhuma tela ou relatório do produto; o valor do endpoint é
 * permitir inspecionar o que o equipamento envia quando surge dúvida sobre
 * um modelo ou firmware novo.
 *
 * ── Por que NÃO estende WebhookHandler ──────────────────────────────
 * A convenção do projeto (CLAUDE.md) é que todo receptor `push*` estenda
 * `config/WebhookHandler.php`. Este é a exceção deliberada: a classe base
 * exige token, faz idempotência por hash e abre transação — pipeline que
 * não faz sentido para um coletor que só quer ver o payload cru, inclusive
 * o payload malformado que a classe base descartaria antes de registrar.
 *
 * ── Como é alcançado ────────────────────────────────────────────────
 * NÃO está no `$webhookRoutes` do `handlers/router.php`, de propósito.
 * O `.htaccess` só reescreve requisições que não correspondem a um arquivo
 * existente (`RewriteCond %{REQUEST_FILENAME} !-f`), então o endereço real
 * é o caminho direto do arquivo:
 *
 *     POST /handlers/pushterminalrealtimestatus.php
 *
 * ── Histórico ───────────────────────────────────────────────────────
 * Para os dados de terminal que o produto de fato usa, o receptor é o
 * `pushTerminalTransInfo.php` (extrai `content`/`extensionData` estruturado
 * e grava no banco). Este arquivo o antecedeu, foi retirado do repositório
 * quando o outro assumiu (ver CHANGELOG) e permaneceu no servidor de
 * homologação como ferramenta de inspeção. Voltou ao versionamento em
 * 28/07/2026 para deixar de ser um arquivo fantasma em produção — presente
 * no disco, ausente do git e, portanto, fora de qualquer revisão ou lint.
 *
 * ── Ressalvas ───────────────────────────────────────────────────────
 * - Sem autenticação: qualquer POST ao caminho acima grava no log. É um
 *   append limitado a um arquivo diário, e o `scripts/log_cleanup.php`
 *   purga o arquivo junto com os demais (`glob('*.log')`, retenção de
 *   LOG_RETENTION_DAYS). Ainda assim, se o endpoint deixar de ser útil o
 *   certo é remover o arquivo, não deixá-lo exposto.
 * - O conteúdo do log é payload de terceiro, não sanitizado. Tratar como
 *   dado não confiável ao abrir ou processar.
 *
 * @returns void Responde sempre HTTP 200 com {"code":0,"message":"success"}
 */

// Payload bruto da requisição, exatamente como chegou
$payload = file_get_contents('php://input');

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// Um arquivo por dia — mesma convenção de nomenclatura do core/Logger.php,
// o que faz o log_cleanup.php purgar este arquivo junto com os demais.
$logFile   = $logDir . '/terminal_realtime_status_' . date('Y-m-d') . '.log';
$timestamp = gmdate('Y-m-d H:i:s');

$logEntry  = "[{$timestamp}] PAYLOAD RECEIVED:\n";
$logEntry .= $payload . "\n";
$logEntry .= str_repeat('-', 60) . "\n";

file_put_contents($logFile, $logEntry, FILE_APPEND);

// Resposta imediata para a Jimicloud / equipamento
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'code'    => 0,
    'message' => 'success',
]);
