<?php
/**
 * Semeador de notificações para a suíte E2E (v4.8.5) — NÃO faz parte da
 * aplicação; existe só para `tests/notificacoes.spec.js`.
 *
 * Notificação não tem caminho de criação pela interface: quem grava é o motor
 * (`includes/notification_engine.php`), chamado pelo worker a partir de alarme
 * ou evento de geocerca. Sem semear, todo teste de "o sino mostra a
 * notificação" passaria por VACUIDADE — a lista vazia satisfaz qualquer
 * asserção do tipo "não contém dado de outro cliente".
 *
 * Usa a `notify()` real, e não um INSERT próprio, para o seed exercitar o
 * mesmo caminho da produção (inclusive o teto horário).
 *
 * Uso:
 *   php tests/helpers/seed_notification.php criar <customer_id> <titulo> [popup]
 *   php tests/helpers/seed_notification.php limpar <prefixo-do-titulo>
 *
 * Escreve JSON na saída padrão: {"ok":true,...} ou {"ok":false,"erro":"..."}.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/notification_engine.php';

function saida(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE), "\n";
    exit(empty($d['ok']) ? 1 : 0);
}

$cmd = $argv[1] ?? '';

try {
    $db = Database::getInstance()->getConnection();

    if ($cmd === 'criar') {
        $cid   = (int)($argv[2] ?? 0);
        $titulo = (string)($argv[3] ?? '');
        $popup  = !empty($argv[4]);
        if ($cid <= 0 || $titulo === '') {
            saida(['ok' => false, 'erro' => 'uso: criar <customer_id> <titulo> [popup]']);
        }

        // O teto horário (NOTIFY_RATE_LIMIT_PER_HOUR) faria `notify()` devolver
        // null numa base com muitas notificações recentes, e o teste acusaria a
        // aplicação por um limite que é o comportamento correto. Distingue-se
        // aqui: null com o motor ligado = suprimido, e isso é reportado.
        $id = notify($db, [
            'customer_id' => $cid,
            'kind'        => 'sistema',
            'severity'    => 'warning',
            'title'       => $titulo,
            'body'        => 'Notificação criada pela suíte E2E.',
            'want_popup'  => $popup ? 1 : 0,
            'want_sound'  => 0,
        ]);

        if ($id === null) {
            saida(['ok' => false, 'erro' => 'notify() devolveu null (motor desligado ou teto horário atingido)']);
        }
        saida(['ok' => true, 'id' => $id, 'customer_id' => $cid]);
    }

    if ($cmd === 'limpar') {
        $prefixo = (string)($argv[2] ?? '');
        if ($prefixo === '') saida(['ok' => false, 'erro' => 'uso: limpar <prefixo-do-titulo>']);
        $stmt = $db->prepare("DELETE FROM notifications WHERE title LIKE ?");
        $stmt->execute([$prefixo . '%']);
        saida(['ok' => true, 'removidas' => $stmt->rowCount()]);
    }

    saida(['ok' => false, 'erro' => "comando desconhecido: '$cmd'"]);

} catch (Throwable $e) {
    saida(['ok' => false, 'erro' => $e->getMessage()]);
}
