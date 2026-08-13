<?php
/**
 * JIMI IoT Hub - Push Instruct Response Handler
 * Endpoint: /pushinstructresponse
 * Versão: 2.0.0 (Alinhado com spec oficial - Seção 1.16)
 * Referência: Seção 1.16 - Respostas de Comandos Assíncronos/Offline
 */
define('HANDLER_NAME', 'pushinstructresponse');
if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/WebhookHandler.php';

class PushInstructResponseHandler extends WebhookHandler {

    // Callback de comando offline (§2.4) chega como objeto único, sem data_list
    protected $allowSingleObjectPayload = true;

    public function __construct() {
        parent::__construct(HANDLER_NAME);
    }

    protected function processItem($item) {
        // msgType no nível do request: 1=async, 2=offline
        $msgType = $this->requestMeta['msgType'] ?? null;

        // Estrutura documentada: { code, msg, data: { _code, _imei, _content, _msg, _serverFlagId } }
        $code   = $item['code']   ?? null;
        $msg    = $item['msg']    ?? null;
        $data   = $item['data']   ?? [];

        // Extrair campos do objeto data (documentado) com fallback para estrutura antiga
        $imei       = $data['_imei']    ?? $item['deviceImei'] ?? $item['imei'] ?? null;
        $content    = $data['_content'] ?? $item['content']   ?? $item['instructContent'] ?? null;
        $response   = $data['_msg']     ?? $item['resultContent'] ?? $item['response'] ?? $msg;
        $serverFlagId = $data['_serverFlagId'] ?? $item['serverFlagId'] ?? null;
        $instructId = $data['_code']    ?? $item['instructId'] ?? $item['commandId'] ?? $item['msgId'] ?? null;

        if (!$imei) {
            Logger::warning('InstructResponse ignorado: IMEI ausente', [
                'source' => $this->handlerName, 'keys' => array_keys($item)
            ]);
            return false;
        }

        $status   = ($code === 0 || $code === '0') ? 'success' : 'failed';
        $execTime = sanitize_date($item['updateTime'] ?? $item['executeTime'] ?? $item['time'] ?? null);

        try {
            // 1. Inserir na tabela de respostas
            $stmt = $this->db->prepare("
                INSERT INTO command_responses 
                (imei, instruct_id, msg_type, command_content, response_content, 
                 status, server_flag_id, remark, execute_time, server_time, created_at)
                VALUES 
                (:imei, :iid, :mtype, :cmd, :resp,
                 :status, :sfid, :remark, :etime, NOW(), NOW())
            ");
            $stmt->execute([
                ':imei'   => $imei,
                ':iid'    => substr((string)$instructId, 0, 50),
                ':mtype'  => $msgType,
                // 🔴 v4.9.11 — SEM `substr(…, 250)`. Este campo é o `_content`
                // do callback, e para a família 33028/33030 ele é a
                // CONFIGURAÇÃO INTEIRA do equipamento: 612 bytes só no JC371
                // medido em 12/08/2026, e cresce com o número de canais. A
                // coluna era `varchar(250)` e o corte já tinha acontecido de
                // verdade — a linha id=14 deste banco tem LENGTH exatamente
                // 250. O que sobra de um JSON cortado no meio não dá nem para
                // recusar direito. Coluna agora é TEXT (migration_v4.9.11).
                //
                // O teto de 65000 é o da própria TEXT, não um limite de
                // negócio: sem ele um payload acima de 64 KB faria o INSERT
                // estourar e a linha não seria gravada de jeito nenhum —
                // trocaríamos perda parcial por perda total. Mesmo padrão de
                // `:resp` logo abaixo.
                ':cmd'    => substr((string)$content, 0, 65000),
                ':resp'   => substr((string)$response, 0, 65000),
                ':status' => $status,
                ':sfid'   => $serverFlagId ? substr((string)$serverFlagId, 0, 20) : null,
                ':remark' => substr((string)$msg, 0, 250),
                ':etime'  => $execTime
            ]);

            // 2. Atualizar tabela commands se existir comando pendente
            $commandId = $this->updatePendingCommand($imei, $status, $response, $content);

            // 3. Se o comando correlacionado era leitura de parâmetros, o
            //    `_content` deste callback É a configuração do equipamento.
            //    Este é o caminho MAIS usado, não a borda: na sonda de
            //    12/08/2026 o JC182 recusou o comando síncrono com
            //    `last_communication` de segundos antes e caiu aqui.
            $this->capturarParametros($imei, $content, $commandId);

            Logger::info('InstructResponse registrado', [
                'source' => $this->handlerName, 'imei' => $imei,
                'status' => $status, 'msg_type' => $msgType
            ]);

            return true;

        } catch (PDOException $e) {
            Logger::error('Erro SQL InstructResponse: ' . $e->getMessage(), [
                'source' => $this->handlerName, 'imei' => $imei
            ]);
            return false;
        }
    }

    /**
     * Atualiza o comando pendente correspondente na tabela commands.
     *
     * CORRELAÇÃO (v4.8.9) — por que não é por `requestId`, que o backlog pedia.
     * A doc oficial é explícita e desmente os dois lados da suposição antiga:
     *   - `requestId` serve para "troubleshooting and log tracing" e **não volta
     *     no callback** — a estrutura de resposta (1.16) é `{code, msg, data:{
     *     _code, _imei, _content, _msg, _serverFlagId}}`, sem ele. Correlacionar
     *     por `requestId` é impossível, não difícil.
     *   - quem a doc define como chave é `serverFlagId`: "the unique
     *     identification field for the current request which is used for
     *     correspondence between request and response", e o `_serverFlagId` da
     *     resposta corresponde a ele.
     *
     * ⚠️ Só que aqui `serverFlagId` **não é único por comando**: `sendcommand.php`
     * o usa como seletor de gateway (0 = JT/T, 1 = JIMI), decisão empírica
     * registrada como "BUG #3" naquele arquivo. Então ele chega valendo 0 ou 1 e
     * não distingue dois comandos para o mesmo device. Torná-lo único é mexer no
     * despacho para veículo real e **só se verifica com device real** (M.2.5,
     * bloqueado) — trocar o valor às cegas arrisca parar o envio de comandos.
     *
     * O que dá para fazer sem esse risco, e é o que esta versão faz: usar o
     * `_content` que o callback devolve — o próprio conteúdo do comando — para
     * casar com `commands.command_content`. Discrimina muito melhor que "o mais
     * recente do IMEI" e degrada para o comportamento antigo quando o callback
     * vem sem `_content`.
     *
     * @param string      $imei    IMEI do device que respondeu
     * @param string      $status  'success' | 'failed'
     * @param mixed       $response Conteúdo da resposta
     * @param string|null $content Comando ecoado pelo callback (`_content`)
     * @returns int|null            Linha de `commands` correlacionada, se houve
     */
    private function updatePendingCommand($imei, $status, $response, $content = null) {
        try {
            $pendentes = "status IN ('pending', 'sent', 'queued')";
            if ($content !== null && $content !== '') {
                // Casa o comando exato; entre iguais, o mais antigo pendente é o
                // que está esperando resposta há mais tempo.
                $stmt = $this->db->prepare("
                    SELECT id FROM commands
                    WHERE imei = :imei AND {$pendentes} AND command_content = :cmd
                    ORDER BY created_at ASC LIMIT 1
                ");
                $stmt->execute([':imei' => $imei, ':cmd' => $content]);
                $existing = $stmt->fetch();
                if ($existing) {
                    $this->applyCommandUpdate($existing['id'], $status, $response);
                    return (int)$existing['id'];
                }
                Logger::info('InstructResponse: _content não casou com comando pendente — caindo no mais recente', [
                    'source' => $this->handlerName, 'imei' => $imei,
                    'content' => substr((string)$content, 0, 80),
                ]);
            }

            $stmt = $this->db->prepare("
                SELECT id FROM commands
                WHERE imei = :imei AND {$pendentes}
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([':imei' => $imei]);
            $existing = $stmt->fetch();

            if ($existing) {
                $this->applyCommandUpdate($existing['id'], $status, $response);
                return (int)$existing['id'];
            }
            return null;
        } catch (Exception $e) {
            // Não relança: a resposta já está salva em command_responses, e
            // derrubar o webhook por causa do espelho em `commands` seria pior.
            // Mas NÃO é mais silencioso — foi este catch que escondeu por meses
            // o "Invalid JSON text" que impedia toda atualização de comando.
            Logger::error('InstructResponse: falha ao correlacionar com commands', [
                'source' => $this->handlerName,
                'imei'   => $imei,
                'erro'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Grava a configuração quando o callback responde a um 33028/33030.
     *
     * ⚠️ A DECISÃO É PELO `pro_no` DO COMANDO, NÃO PELO FORMATO DO CONTEÚDO.
     * Sniffar "parece JSON de parâmetros" gravaria configuração a partir de
     * qualquer resposta que por acaso fosse um objeto JSON — inclusive de
     * comandos de vídeo, que também respondem JSON. O `pro_no` (coluna nova na
     * v4.9.12) é a única fonte que diz o que foi pedido.
     *
     * Sem comando correlacionado, não grava: um `_content` órfão pode ser
     * resposta de qualquer coisa, e adivinhar é como nasce dado errado que
     * ninguém desconfia.
     *
     * @param string      $imei
     * @param string|null $content   `_content` do callback
     * @param int|null    $commandId Linha de `commands` correlacionada
     * @returns void
     */
    private function capturarParametros($imei, $content, $commandId) {
        if ($commandId === null || $content === null || $content === '') return;

        try {
            $stmt = $this->db->prepare("SELECT pro_no FROM commands WHERE id = :id");
            $stmt->execute([':id' => $commandId]);
            $proNo = (int)$stmt->fetchColumn();
            if ($proNo !== 33028 && $proNo !== 33030) return;

            require_once __DIR__ . '/../includes/device_params.php';
            $parsed = parse_param_content((string)$content);

            if (!$parsed['ok']) {
                // Até a v4.9.11 este era o caso NORMAL: `command_content` era
                // varchar(250) e o JSON chegava aqui cortado ao meio.
                Logger::warning('InstructResponse: _content de parâmetros não parseou', [
                    'source' => $this->handlerName, 'imei' => $imei,
                    'proNo'  => $proNo, 'erro' => $parsed['erro'],
                    'tamanho' => strlen((string)$content),
                ]);
                return;
            }

            $gravados = upsert_device_params(
                $this->db, $imei, $parsed, $proNo, (string)$proNo, (int)$commandId
            );

            // Só a leitura COMPLETA (33028) marca o device como sincronizado.
            // O 33030 traz um punhado de parâmetros e dizer "sincronizado" com
            // três valores faria o worker parar de buscar o resto.
            if ($proNo === 33028) {
                $this->db->prepare("UPDATE devices SET params_synced_at = NOW(),
                                          params_sync_tries = 0, params_sync_next = NULL
                                    WHERE imei = :imei")->execute([':imei' => $imei]);
            }

            Logger::info('InstructResponse: parâmetros gravados', [
                'source' => $this->handlerName, 'imei' => $imei, 'proNo' => $proNo,
                'gravados' => $gravados, 'param_count' => $parsed['param_count'],
            ]);
        } catch (Throwable $e) {
            Logger::error('InstructResponse: falha ao gravar parâmetros', [
                'source' => $this->handlerName, 'imei' => $imei,
                'erro'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Grava o desfecho da resposta na linha de `commands` já escolhida.
     *
     * @param int   $commandId Linha correlacionada
     * @param string $status   'success' | 'failed'
     * @param mixed  $response Conteúdo da resposta
     * @returns void
     */
    private function applyCommandUpdate($commandId, $status, $response) {
        $cmdStatus = ($status === 'success') ? 'executed' : 'failed';
        $stmt = $this->db->prepare("
            UPDATE commands SET status = :status, response_time = NOW(),
            response_payload = :payload, updated_at = NOW() WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $cmdStatus,
            // 🔴 v4.8.9 — `commands.response_payload` é coluna **JSON**. O código
            // antigo (`is_array(...) ? json_encode(...) : (string)$response`)
            // mandava a resposta de texto do device crua, e o MySQL recusava com
            // "3140 Invalid JSON text" em TODA resposta de texto — que é o caso
            // normal ("ext Battery:12.1V; GPRS:Link Up"). O catch de
            // updatePendingCommand engolia a exceção, então **nenhum** callback
            // offline jamais atualizou `commands`: o comando ficava 'sent' para
            // sempre e o dashboard expirava em "Comando em fila offline".
            // Discutia-se qual heurística de correlação usar; a correlação não
            // chegava a acontecer. json_encode() sempre — string vira string JSON.
            ':payload' => json_encode($response, JSON_UNESCAPED_UNICODE),
            ':id'      => $commandId,
        ]);
    }
}

$handler = new PushInstructResponseHandler();
$handler->handle();
