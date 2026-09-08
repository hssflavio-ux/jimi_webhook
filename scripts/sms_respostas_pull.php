<?php
/**
 * bycamera — Poller de respostas SMS (Allcance, Método Pull) v1 (v4.17.24)
 *
 * Fecha o gap que o webhook (`/pushsms`) nunca fechou: `sms_commands.resposta_texto`
 * nunca foi preenchido por ele — medido em produção em 08/09/2026, 0 de 29
 * comandos, 0 payloads capturados com o campo `mensagem`. A própria doc oficial
 * da Allcance aponta a causa: respostas/interações do destinatário vêm por um
 * endpoint SEPARADO do webhook de status de entrega —
 * `GET /v2/api/relatorios/campanhas/respostas/{tipo}` — cujo exemplo de retorno
 * já mostra texto de resposta real ("OK 1", "OK 2") que o webhook nunca viu.
 *
 * 🔑 REDUNDÂNCIA, NÃO SUBSTITUIÇÃO. `sms_respostas_metodo()`
 * (includes/sms_gateway.php) decide qual dos dois caminhos GRAVA
 * `resposta_texto`/`resposta_em` — os dois nunca escrevem ao mesmo tempo por
 * padrão (decisão do dono do produto, 08/09/2026). Enquanto o método ativo não
 * for 'pull', este script só CONSULTA e sai sem gravar nada — silencioso, não é
 * erro, é o outro caminho estando ligado.
 *
 * 🔴 FORMATO DIFERENTE DO WEBHOOK. Os nomes dos campos vêm com a ordem das
 * palavras trocada (`numero_referencia`/`campanha_referencia` aqui,
 * `referencia_numero`/`referencia_campanha` no webhook) e o texto da resposta
 * vem em `resposta`, nunca em `mensagem`. `sms_pull_classificar_item()`
 * (includes/sms_inbound.php) é a função certa — nunca reusar
 * `sms_classificar_item()` aqui, que descartaria tudo como "sem referência".
 *
 * A doc garante que cada interação sai da fila do provedor só UMA vez
 * ("disponibilizada apenas uma vez por consulta") — não há paginação nem
 * anti-replay a fazer aqui; rodar de novo simplesmente traz o que for novo.
 * Ainda assim o UPDATE é por referência (idempotente), como o webhook.
 *
 * Uso:
 *   php scripts/sms_respostas_pull.php
 *
 * Cron sugerido (a cada 2 min — mesma cadência das demais consultas
 * periódicas do canal SMS; registrado em scripts/crontab-setup.sh):
 *   [2 min] php /var/www/jimi_webhook/scripts/sms_respostas_pull.php >> /var/www/jimi_webhook/logs/sms_pull.log 2>&1
 */

$raiz = dirname(__DIR__);
require_once $raiz . '/config/database.php';
require_once $raiz . '/core/Logger.php';
require_once $raiz . '/includes/sms_gateway.php';
require_once $raiz . '/includes/sms_inbound.php';

env_load();
$db = Database::getInstance()->getConnection();

// ── Toggle: só age se este for o método ativo ───────────────────────────────
$metodo = sms_respostas_metodo($db);
if ($metodo !== 'pull') {
    echo "SMS pull: método ativo é '{$metodo}' (config em /config-sms) — nada a fazer.\n";
    exit(0);
}

// ── Autenticação ─────────────────────────────────────────────────────────────
$tk = sms_token($db);
if (!$tk['ok']) {
    fwrite(STDERR, "SMS pull: sem token — {$tk['erro']}\n");
    exit(1);
}

$caminho = '/relatorios/campanhas/respostas/sms';
$r = sms_http('GET', $caminho, null, $tk['token']);

// 401 com token cacheado = token revogado antes da hora (mesmo tratamento do
// resto do gateway — sms_gateway.php).
if ($r['http'] === 401) {
    $tk = sms_token($db, true);
    if (!$tk['ok']) {
        fwrite(STDERR, "SMS pull: reautenticação falhou — {$tk['erro']}\n");
        exit(1);
    }
    $r = sms_http('GET', $caminho, null, $tk['token']);
}

if ($r['erro'] !== null) {
    fwrite(STDERR, "SMS pull: provedor inacessível — {$r['erro']}\n");
    Logger::error('SMS pull: provedor inacessível', ['erro' => $r['erro']]);
    exit(1);
}

// 🔴 A ALLCANCE USA HTTP 404 COMO "NADA DE NOVO" NESTE ENDPOINT — não é rota
// errada. Medido em produção (08/09/2026): `{"message":"sem novas mensagens"}`
// com status 404, exatamente na URL que a doc manda. `sms_pull_itens()` já
// devolve `[]` para esse corpo (não é uma lista de itens), então o
// comportamento estava certo por acidente de forma — isto só torna a
// intenção explícita no log, para não confundir "sem novidade" com "rota
// quebrada" quando alguém for ler `logs/sms_pull.log`.
if ($r['http'] === 404 && is_array($r['json']) && array_key_exists('message', $r['json']) && !isset($r['json'][0])) {
    echo "SMS pull: sem novas interações (a API sinaliza isso com HTTP 404 — não é erro de rota).\n";
    exit(0);
}

// Lote vazio ("nada de novo desde a última consulta") é o caso normal, não erro.
$itens = sms_pull_itens($r['json']);
echo "SMS pull: http {$r['http']} — " . count($itens) . " interação(ões) no lote.\n";

if (!$itens) {
    exit(0);
}

$sel = $db->prepare("SELECT id, imei FROM sms_commands WHERE referencia = :r LIMIT 1");

$casados = 0;
$semResposta = 0; // item sem texto de resposta (não deveria existir neste endpoint, mas não estoura)
$orfaos  = 0;

foreach ($itens as $item) {
    $c = sms_pull_classificar_item($item);

    if ($c['resposta'] === null) {
        $semResposta++;
        continue;
    }

    // ⚠️ NUNCA casar por número solto — mesma regra do webhook: o mesmo chip
    // recebe muitos comandos ao longo do tempo, e casar por `numero` atribuiria
    // a resposta ao comando errado.
    if ($c['referencia'] === null) {
        $orfaos++;
        continue;
    }

    $sel->execute([':r' => $c['referencia']]);
    $linha = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$linha) {
        // Referência que não é nossa: outro sistema usando a mesma conta
        // Allcance (o endpoint é da conta inteira, igual ao webhook).
        $orfaos++;
        continue;
    }

    sms_grava_evento_raw($db, (int)$linha['id'], $item, 'pull');

    try {
        $db->prepare("
            UPDATE sms_commands
               SET resposta_texto = :t,
                   resposta_em    = COALESCE(:em, UTC_TIMESTAMP())
             WHERE id = :id
        ")->execute([
            ':t'  => $c['resposta'],
            ':em' => $c['resposta_em'],
            ':id' => $linha['id'],
        ]);
        $casados++;

        Logger::info('SMS pull: resposta do equipamento recebida', [
            'imei'       => $linha['imei'],
            'referencia' => $c['referencia'],
        ]);
    } catch (PDOException $e) {
        Logger::error('SMS pull: falha ao gravar resposta', [
            'referencia' => $c['referencia'],
            'erro'       => $e->getMessage(),
        ]);
    }
}

echo "Casados: {$casados} | sem referência nossa: {$orfaos} | sem texto de resposta: {$semResposta}\n";
Logger::info('SMS pull processado', [
    'itens'        => count($itens),
    'casados'      => $casados,
    'orfaos'       => $orfaos,
    'sem_resposta' => $semResposta,
]);
