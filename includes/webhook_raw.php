<?php
/**
 * Captura do PAYLOAD CRU de tudo que os equipamentos enviam (v4.17.12).
 *
 * Pedido do dono do produto: "precisamos do payload raw de tudo que os eqptos
 * enviam". A motivação concreta veio da investigação do MOTIVO DA TRANSMISSÃO
 * (v4.17.11): `handlers/pushgps.php` lê uma lista FIXA de chaves e `gps_data`
 * não tem coluna `raw_data` — qualquer campo que o hub mande fora dessa lista
 * era descartado sem deixar rastro, e não havia como saber sequer se existia.
 *
 * 🔴 O QUE SE GUARDA É O CORPO COMO CHEGOU, não o resultado do parse. É essa a
 * diferença que dá valor à tabela: o parse é a nossa interpretação (e já se
 * provou incompleta); o corpo é o fato. Guardar o array já decodificado
 * repetiria exatamente o filtro que criou o problema.
 *
 * ⚠️ CAPTURA DEPOIS DA VALIDAÇÃO DE TOKEN, nunca antes. Gravar corpo de
 * requisição não autenticada transforma a tabela em vetor de enchimento de
 * disco por qualquer um que descubra a URL — o endpoint é público por
 * natureza. Requisição com token inválido continua só no log.
 *
 * ⚠️ NUNCA DENTRO DA TRANSAÇÃO do handler. `WebhookHandler::handle()` abre
 * transação para processar os itens e faz `rollBack()` em erro — a captura
 * dentro dela sumiria justamente nos casos em que o payload cru é mais
 * necessário (o que falhou ao processar). Por isso a chamada fica ANTES do
 * `beginTransaction()`.
 *
 * ⚠️ E ANTES DA CHECAGEM DE IDEMPOTÊNCIA, de propósito: reenvio do hub (a doc
 * oficial promete 3 tentativas, 2 min de intervalo) É coisa que o equipamento
 * mandou, e some do banco se a captura vier depois do bloqueio de replay. O
 * `payload_hash` gravado junto permite distinguir reenvio de dado novo.
 *
 * Retenção: `RAW_PAYLOAD_RETENTION_DAYS` (default 30), aplicada pelo cron
 * `scripts/log_cleanup.php`. Medido em produção (05/09/2026): 5.429
 * requisições/dia (64% heartbeat), ~2,7 MB/dia, ~80 MB por mês de retenção.
 * O número acompanha o tamanho da frota — 12 equipamentos na medição.
 */

/** Teto de bytes gravados por requisição. O `/filelist` da câmera JIMI passa de 78 KB. */
const RAW_PAYLOAD_MAX_BYTES_DEFAULT = 262144;

/**
 * Grava o corpo cru de uma requisição de equipamento.
 *
 * Silenciosa por contrato: nenhuma falha aqui pode derrubar o processamento do
 * webhook — o dado operacional (posição, alarme) vale mais que a cópia crua.
 * Falha vira WARNING no log, que é o que denuncia a tabela ter parado.
 *
 * @param string      $endpoint Nome do endpoint ('pushgps', 'filelist'…)
 * @param string|null $body     Corpo já lido; NULL faz ler `php://input`
 * @param array       $meta     Opcional: 'imei', 'item_count', 'payload_hash'
 * @returns void
 * @throws void Nunca propaga exceção
 */
function webhook_capture_raw(string $endpoint, ?string $body = null, array $meta = []): void
{
    try {
        // Desligável sem deploy de código. ⚠️ Mudar isto no .env NÃO pega em
        // worker de PHP-FPM já aquecido: `env_load()` (config/database.php) só
        // faz `putenv` se a variável ainda não existir, e `putenv` persiste no
        // processo. Exige `systemctl reload php8.3-fpm`.
        $ligado = getenv('RAW_PAYLOAD_CAPTURE');
        if ($ligado !== false && in_array(strtolower(trim((string)$ligado)), ['0', 'off', 'false', 'no'], true)) {
            return;
        }

        $max = (int)(getenv('RAW_PAYLOAD_MAX_BYTES') ?: RAW_PAYLOAD_MAX_BYTES_DEFAULT);
        if ($max < 1024) $max = RAW_PAYLOAD_MAX_BYTES_DEFAULT;

        if ($body === null) {
            // Lê 1 byte além do teto só para saber se truncou — sem carregar
            // um corpo gigante inteiro na memória para depois cortar.
            $body = file_get_contents('php://input', false, null, 0, $max + 1);
            $body = ($body === false) ? '' : $body;
        }

        $lidos    = strlen($body);
        $truncado = $lidos > $max;
        if ($truncado) $body = substr($body, 0, $max);

        // 🔴 `strlen()` do que foi lido NÃO é o tamanho original quando trunca:
        // a leitura para em `$max + 1` de propósito (não carregar um corpo
        // gigante na memória só para cortar), então um payload de 4 KB com teto
        // de 1 KB registraria "1025" e enganaria quem for investigar o tamanho.
        // O `Content-Length` tem o número de verdade — exceto em `chunked`, que
        // é justamente o caso do /filelist da câmera JIMI (CLAUDE.md), onde o
        // cabeçalho não existe e os bytes lidos são a melhor medida disponível.
        $bytes = $lidos;
        $cl = $_SERVER['CONTENT_LENGTH'] ?? null;
        if ($cl !== null && ctype_digit((string)$cl) && (int)$cl > $lidos) {
            $bytes = (int)$cl;
        }

        // Corpo vazio ainda é fato: o `/filelist` passou cinco dias entregando
        // corpo vazio por causa do `chunked` descartado entre Apache e FPM
        // (CLAUDE.md), e a ausência era o próprio sintoma. Grava-se assim mesmo.
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("
            INSERT INTO webhook_payloads
                (endpoint, imei, item_count, payload_hash, content_type,
                 body_bytes, truncated, body, received_at)
            VALUES
                (:endpoint, :imei, :item_count, :hash, :ctype,
                 :bytes, :trunc, :body, :recv)");
        $stmt->execute([
            ':endpoint'   => substr($endpoint, 0, 50),
            ':imei'       => isset($meta['imei']) && $meta['imei'] !== '' ? substr((string)$meta['imei'], 0, 20) : null,
            ':item_count' => (int)($meta['item_count'] ?? 0),
            ':hash'       => $meta['payload_hash'] ?? null,
            ':ctype'      => substr((string)($_SERVER['CONTENT_TYPE'] ?? ''), 0, 100),
            ':bytes'      => $bytes,           // original (Content-Length) ou lidos, se chunked
            ':trunc'      => $truncado ? 1 : 0,
            ':body'       => $body,
            // gmdate, não date: o resto do sistema grava UTC e o php.ini estar
            // em UTC é coincidência de ambiente, não garantia (CLAUDE.md).
            ':recv'       => gmdate('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // Sem `Logger::` fatal aqui: se a tabela não existe (deploy trouxe o
        // código antes da migração — o caso que o CLAUDE.md documenta), o
        // webhook TEM de continuar funcionando.
        if (class_exists('Logger')) {
            Logger::warning('webhook_capture_raw falhou', [
                'source' => $endpoint, 'error' => $e->getMessage(),
            ]);
        }
    }
}

/**
 * Descobre o IMEI de um `data_list` já decodificado, para a coluna indexada.
 *
 * É conveniência de CONSULTA ("o que este equipamento mandou ontem?"), não
 * fonte de verdade — o corpo cru continua sendo o fato. Item sem IMEI
 * reconhecível grava NULL, e a linha continua achável por endpoint e data.
 *
 * @param array $dataList Itens já decodificados
 * @returns string|null IMEI do primeiro item que tiver um
 */
function webhook_raw_sniff_imei(array $dataList): ?string
{
    foreach ($dataList as $item) {
        if (!is_array($item)) continue;
        foreach (['deviceImei', 'imei', 'device_imei'] as $k) {
            if (!empty($item[$k])) return (string)$item[$k];
        }
        // JT/T e alguns pushes aninham o equipamento dentro de `msg`
        if (!empty($item['msg']) && is_array($item['msg'])) {
            foreach (['deviceImei', 'imei'] as $k) {
                if (!empty($item['msg'][$k])) return (string)$item['msg'][$k];
            }
        }
    }
    return null;
}
