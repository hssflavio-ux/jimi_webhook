<?php
/**
 * bycamera — Retorno do provedor de SMS (Allcance)
 * Rota: /pushsms?k=<segredo>
 *
 * A Allcance chama este endereço em tempo real a cada mudança de status de uma
 * mensagem — e também quando o destinatário RESPONDE. Como o destinatário aqui
 * é a câmera, "resposta do destinatário" é a resposta do equipamento ao comando:
 * é o que fecha o ciclo e faz do SMS um canal completo, não um disparo cego.
 *
 * 🔴 NÃO ESTENDE `WebhookHandler`, e isso é deliberado. Aquela classe exige o
 * `WEBHOOK_TOKEN` DENTRO do corpo e faz idempotência por MD5 de `data_list` —
 * o payload da Allcance (`{"messages":[…],"total":N}`) não tem nem um nem
 * outro, e nunca terá: quem posta é um terceiro que não conhece o nosso
 * protocolo. Segue o precedente do `/filelist`, já documentado no router:
 * webhook de fora, sem sessão, defesa dentro do handler.
 *
 * A DEFESA é o segredo `k` na query string, comparado com
 * `sms_settings.webhook_secret` por `hash_equals`. É a única possível: a
 * Allcance não envia cabeçalho de autenticação nenhum, nem assinatura de corpo.
 * O segredo é gerado e exibido em /config-sms, e cadastrado à mão no painel
 * deles.
 *
 * ⚠️ O WEBHOOK É DA CONTA INTEIRA, não da nossa aplicação. Se a conta Allcance
 * for usada para outra coisa, chegam aqui eventos que não têm referência nossa.
 * Item sem `referencia_numero` conhecido é registrado e DESCARTADO — casar por
 * número solto atribuiria a resposta de um SMS ao comando errado, já que o
 * mesmo chip recebe muitos comandos ao longo do tempo.
 *
 * SEMPRE 200 quando o corpo é legível. Devolver erro faria a Allcance
 * reenfileirar e repetir o mesmo lote; como o processamento é idempotente por
 * referência (um UPDATE na linha), reenvio não corrompe nada, mas 200 evita o
 * ruído.
 *
 * ⚠️ `env_load()` explícito: o carregamento do .env mora no construtor do
 * Database, e este handler lê `getenv()` antes de qualquer consulta. Sem isso,
 * repete-se o defeito da v4.13.22 (tela mostrando v4.0.0 no GET).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/sms_inbound.php';

env_load();

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

/**
 * Encerra a requisição com um JSON curto.
 *
 * @param int    $code HTTP
 * @param string $msg  Mensagem
 * @returns void
 */
function pushsms_fim(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['status' => $code === 200 ? 'ok' : 'error', 'message' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pushsms_fim(405, 'Método não permitido');
}

$raw = file_get_contents('php://input');

Logger::debug('RAW_WEBHOOK_DATA', [
    'source'       => 'pushsms',
    'raw_input'    => $raw,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown',
]);

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    Logger::error('SMS webhook: banco indisponível', ['erro' => $e->getMessage()]);
    pushsms_fim(500, 'Erro interno');
}

// ── Autorização ─────────────────────────────────────────────────────────────
$k = (string)($_GET['k'] ?? '');
try {
    $segredo = (string)($db->query(
        "SELECT COALESCE(webhook_secret,'') FROM sms_settings WHERE customer_id IS NULL LIMIT 1"
    )->fetchColumn() ?: '');
} catch (PDOException $e) {
    Logger::error('SMS webhook: sms_settings indisponível (migração v4.14.0 aplicada?)',
                  ['erro' => $e->getMessage()]);
    pushsms_fim(500, 'Erro interno');
}

// Segredo não configurado = endpoint fechado. Aberto por omissão seria pior:
// qualquer um poderia inventar status de entrega e resposta de equipamento.
if ($segredo === '' || $k === '' || !hash_equals($segredo, $k)) {
    Logger::warning('SMS webhook: segredo inválido', ['ip' => $_SERVER['REMOTE_ADDR'] ?? '?']);
    pushsms_fim(403, 'Não autorizado');
}

// ── Corpo ───────────────────────────────────────────────────────────────────
$payload = json_decode($raw ?: '', true);
$itens   = sms_webhook_itens(is_array($payload) ? $payload : null);

if (!$itens) {
    // Corpo vazio/ilegível não é erro do provedor a ponto de merecer 4xx — mas
    // vira WARNING porque é o sintoma de o formato ter mudado.
    Logger::warning('SMS webhook: corpo sem itens utilizáveis', ['tamanho' => strlen((string)$raw)]);
    pushsms_fim(200, 'Nada a processar');
}

// A câmera não espera nada disto: fecha a resposta e processa depois.
if (function_exists('fastcgi_finish_request')) {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'received' => count($itens)]);
    fastcgi_finish_request();
} else {
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'received' => count($itens)]);
}

// ── Processamento ───────────────────────────────────────────────────────────
$casados = 0;
$orfaos  = 0;
$respostas = 0;

$sel = $db->prepare("SELECT id, imei FROM sms_commands WHERE referencia = :r LIMIT 1");

foreach ($itens as $item) {
    $c = sms_classificar_item($item);

    if ($c['referencia'] === null) {
        $orfaos++;
        continue;
    }

    $sel->execute([':r' => $c['referencia']]);
    $linha = $sel->fetch(PDO::FETCH_ASSOC);

    if (!$linha) {
        // Referência que não é nossa: outro sistema usando a mesma conta.
        $orfaos++;
        continue;
    }

    try {
        if ($c['e_resposta']) {
            // 🔑 A RESPOSTA DO EQUIPAMENTO. Grava o texto e a hora, e NÃO
            // sobrescreve status_entrega: a entrega já aconteceu (o aparelho
            // não responderia sem ter recebido), e o status dela veio — ou
            // virá — em outro item do mesmo lote.
            $db->prepare("
                UPDATE sms_commands
                   SET resposta_texto = :t,
                       resposta_em    = COALESCE(:em, UTC_TIMESTAMP())
                 WHERE id = :id
            ")->execute([
                ':t'  => $c['resposta'],
                ':em' => $c['enviado_em'],
                ':id' => $linha['id'],
            ]);
            $respostas++;

            Logger::info('SMS: resposta do equipamento recebida', [
                'imei'       => $linha['imei'],
                'referencia' => $c['referencia'],
            ]);
        } else {
            $db->prepare("
                UPDATE sms_commands
                   SET status_entrega = :s,
                       entregue_em    = COALESCE(:em, entregue_em)
                 WHERE id = :id
            ")->execute([
                ':s'  => $c['status'],
                ':em' => $c['entregue_em'],
                ':id' => $linha['id'],
            ]);
        }
        $casados++;
    } catch (PDOException $e) {
        Logger::error('SMS webhook: falha ao gravar retorno', [
            'referencia' => $c['referencia'],
            'erro'       => $e->getMessage(),
        ]);
    }
}

Logger::info('SMS webhook processado', [
    'itens'     => count($itens),
    'casados'   => $casados,
    'respostas' => $respostas,
    // Órfão constante e alto = a conta está sendo usada por outro sistema, ou o
    // formato da referência mudou. Vale olhar antes de virar rotina.
    'orfaos'    => $orfaos,
]);
