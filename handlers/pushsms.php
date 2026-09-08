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
require_once __DIR__ . '/../includes/sms_gateway.php';

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
// Payload cru no banco (v4.17.12) — AQUI, depois do `hash_equals` do segredo:
// gravar corpo de requisição não autenticada é convite a encher disco por
// quem descobrir a URL. Ver includes/webhook_raw.php.
require_once __DIR__ . '/../includes/webhook_raw.php';

// 🔴 DECODIFICA ANTES DE CAPTURAR, e isso não inverte a ordem que importa: a
// captura continua acontecendo aconteça o que acontecer com o parse (corpo
// ilegível vira `$itens = []` e é gravado igual, que é justamente o caso em que
// o corpo cru vale mais). O que se ganha é preencher os METADADOS da linha.
//
// ⚠️ O payload da Allcance NÃO TEM IMEI — `webhook_raw_sniff_imei()`, que é o
// que preenche a coluna nos demais endpoints, procura `deviceImei`/`imei` e não
// acha nada aqui. Até a v4.17.23 toda linha de `pushsms` ficava com `imei`
// NULL, e filtrar `webhook_payloads` por equipamento não trazia os SMS (medido:
// 6 de 6 chamadas). O vínculo sai de `referencia_numero` →
// `sms_commands.referencia` (UNIQUE) → `imei`, numa consulta por LOTE.
//
// ⚠️ `payload_hash` é o MD5 do corpo. Nos endpoints do IoT Hub ele é o hash do
// `data_list` usado como anti-replay; aqui não há idempotência a aplicar (o
// UPDATE por referência já é idempotente), mas o hash **identifica o reenvio do
// provedor** — que acontece: medido em 07/09/2026, a Allcance mandou o MESMO
// evento duas vezes, a 1 segundo de distância, byte a byte igual.
$payload = json_decode($raw ?: '', true);
$itens   = sms_webhook_itens(is_array($payload) ? $payload : null);

webhook_capture_raw('pushsms', (string)$raw, [
    'imei'         => sms_imei_do_lote($db, $itens),
    'item_count'   => count($itens),
    'payload_hash' => $raw !== '' && $raw !== false ? md5((string)$raw) : null,
]);

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

// Acumula o item do webhook EXATAMENTE como a Allcance mandou (v4.17.13).
// `webhook_payloads` já guarda o corpo inteiro da requisição; esta coluna é o
// recorte por comando, para responder "o que o provedor disse sobre ESTE
// envio?" sem garimpar JSON por referência. `sms_grava_evento_raw()`
// (includes/sms_gateway.php) é o ponto ÚNICO — compartilhado com
// scripts/sms_respostas_pull.php, que grava a MESMA coluna pelo outro caminho.
$gravaEvento = fn(int $id, array $item) => sms_grava_evento_raw($db, $id, $item, 'webhook');

// 🔑 v4.17.24 — webhook e pull são REDUNDÂNCIA um do outro, nunca simultâneos
// por padrão (decisão do dono do produto). O webhook nunca entregou uma
// resposta sequer nesta conta (0 de 29 comandos, 0 payloads com `mensagem`);
// enquanto `sms_respostas_metodo()` continuar em 'pull' (o padrão), este
// endpoint grava STATUS DE ENTREGA normalmente e o evento cru sempre — só a
// escrita de `resposta_texto`/`resposta_em` fica de fora, para não competir
// com scripts/sms_respostas_pull.php. Ver includes/sms_gateway.php.
$metodoRespostas = sms_respostas_metodo($db);

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
        // Antes de interpretar: o item cru fica gravado no comando,
        // aconteça o que acontecer com a interpretação abaixo.
        $gravaEvento((int)$linha['id'], $item);

        if ($c['e_resposta']) {
            // 🔑 A RESPOSTA DO EQUIPAMENTO. Grava o texto e a hora, e NÃO
            // sobrescreve status_entrega: a entrega já aconteceu (o aparelho
            // não responderia sem ter recebido), e o status dela veio — ou
            // virá — em outro item do mesmo lote.
            //
            // v4.17.24: só grava se este for o método ATIVO. O evento cru
            // (acima) já entrou no histórico de qualquer jeito — o que fica de
            // fora quando o método é 'pull' é só a ESCRITA em
            // resposta_texto/resposta_em, para não competir com
            // scripts/sms_respostas_pull.php.
            if ($metodoRespostas === 'webhook') {
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
            }
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
