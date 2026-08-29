<?php
/**
 * bycamera — Interpretação do retorno de SMS (Allcance) v4.14.0
 *
 * Só LEITURA do payload: nada aqui toca no banco. É de propósito — a
 * interpretação é a parte que erra em silêncio, e mantê-la pura permite fixá-la
 * em teste sem MySQL (`tests/helpers/sms_webhook.test.php`). O handler
 * `/pushsms` usa estas funções e faz a gravação.
 *
 * ── O FORMATO ───────────────────────────────────────────────────────────────
 *
 *   { "messages": [ {...}, {...} ], "total": 8 }
 *
 * Cada item traz `numero`, `status`, `data_envio`, `data_entrega`,
 * `referencia_campanha`, `referencia_numero` e — quando o destinatário
 * respondeu — `mensagem`.
 *
 * 🔑 A DISTINÇÃO QUE DÁ VALOR AO CANAL. `status: "recebido"` significa duas
 * coisas diferentes conforme `mensagem` esteja preenchida:
 *
 *   • "recebido" SOZINHO ......... confirmação de entrega (status final)
 *   • "recebido" COM `mensagem` .. a RESPOSTA do equipamento ao comando
 *
 * Tratar os dois igual faria a tela mostrar "Recebido" e jogar fora exatamente
 * o que a câmera respondeu — que é o motivo de existir este canal de volta.
 *
 * ⚠️ O WEBHOOK É DA CONTA INTEIRA, não da nossa aplicação. Se a conta Allcance
 * for usada para qualquer outra coisa, chegam aqui eventos sem referência
 * nossa. Item sem `referencia_numero` é DESCARTADO — nunca casado pelo número,
 * que atribuiria a resposta de um SMS ao comando errado (o mesmo chip recebe
 * muitos comandos ao longo do tempo).
 */

/**
 * Extrai a lista de itens do envelope, tolerando corpo malformado.
 *
 * Devolve [] em vez de estourar: é endpoint público chamado por terceiro, e
 * exceção aqui viraria 500 que a Allcance interpretaria como "reenviar".
 *
 * @param array|null $payload Corpo já decodificado
 * @returns array Lista de itens (possivelmente vazia)
 */
function sms_webhook_itens(?array $payload): array
{
    if (!is_array($payload)) return [];
    $msgs = $payload['messages'] ?? null;
    if (!is_array($msgs)) return [];

    // Só itens que são arrays associativos — protege contra ["a","b"].
    return array_values(array_filter($msgs, 'is_array'));
}

/**
 * Classifica um item do webhook.
 *
 * @param array $item Um elemento de `messages`
 * @returns array{
 *   referencia:string|null, referencia_campanha:string|null, numero:string|null,
 *   status:string|null, e_resposta:bool, resposta:string|null,
 *   entregue_em:string|null, enviado_em:string|null
 * }
 */
function sms_classificar_item(array $item): array
{
    // Referência vazia ('' é o que a doc mostra quando não foi enviada) conta
    // como AUSENTE — string vazia não casa com nada e não deve virar consulta.
    $ref  = trim((string)($item['referencia_numero'] ?? ''));
    $refC = trim((string)($item['referencia_campanha'] ?? ''));

    // Status cru, minúsculo: a doc publica a tabela em Maiúsculas num lugar e
    // minúsculas noutro. Sem normalizar, a mesma entrega vira dois valores
    // distintos na coluna e o filtro da tela perde metade das linhas.
    $status = mb_strtolower(trim((string)($item['status'] ?? '')));

    $msg = trim((string)($item['mensagem'] ?? ''));

    return [
        'referencia'          => $ref !== ''  ? $ref  : null,
        'referencia_campanha' => $refC !== '' ? $refC : null,
        'numero'              => isset($item['numero']) ? (string)$item['numero'] : null,
        'status'              => $status !== '' ? $status : null,
        // Resposta do equipamento = "recebido" COM texto. Espaço em branco não
        // conta (o trim acima resolve).
        'e_resposta'          => ($status === 'recebido' && $msg !== ''),
        'resposta'            => $msg !== '' ? $msg : null,
        'entregue_em'         => sms_data_ou_null($item['data_entrega'] ?? null),
        'enviado_em'          => sms_data_ou_null($item['data_envio'] ?? null),
    ];
}

/**
 * Normaliza um campo de data do provedor.
 *
 * A doc mostra `null` literal e strings `Y-m-d H:i:s`. Qualquer outra coisa
 * vira null em vez de ir para uma coluna datetime como lixo.
 *
 * @param mixed $v Valor cru
 * @returns string|null
 */
function sms_data_ou_null($v): ?string
{
    if (!is_string($v)) return null;
    $v = trim($v);
    if ($v === '' || $v === 'null') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) ? $v : null;
}
