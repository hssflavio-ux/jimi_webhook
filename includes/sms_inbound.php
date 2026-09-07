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
        'entregue_em'         => sms_data_utc_ou_null($item['data_entrega'] ?? null),
        'enviado_em'          => sms_data_utc_ou_null($item['data_envio'] ?? null),
    ];
}

/**
 * Converte um campo de data do provedor (BRT) para UTC.
 *
 * 🔴 A ALLCANCE MANDA HORÁRIO LOCAL, NÃO UTC — e as colunas de destino
 * (`sms_commands.entregue_em`, `resposta_em`) são UTC como todo o resto do
 * sistema. Até a v4.17.13 a string ia crua para o banco, e o efeito era
 * silencioso e absurdo na tela: `comandos_sms.php` renderiza `resposta_em` com
 * `fmt_brt()`, que subtrai mais 3 h, enquanto `created_at` (TIMESTAMP, tratado
 * certo pelo MySQL) aparece correto — a MESMA linha mostrava a resposta
 * chegando 3 h ANTES de o comando ter sido enviado.
 *
 * MEDIDO em produção (06/09/2026), não deduzido: nos três payloads capturados
 * em `webhook_payloads`, a diferença entre o `received_at` que gravamos em UTC
 * e o `data_entrega`/`data_envio` do provedor foi de **180 minutos exatos**.
 *
 * Converte por `America/Sao_Paulo`, não por offset fixo de -3 h: o valor é
 * hora de PAREDE de uma plataforma brasileira, então a regra de fuso é a certa
 * se o horário de verão voltar. (É o oposto do `FILELIST_OFFSET_SEGUNDOS`, que
 * é offset fixo porque o equipamento guarda offset, não regra — ver CLAUDE.md.)
 *
 * ⚠️ Se a Allcance algum dia passar a mandar UTC, esta conversão vira o bug.
 * O detector já existe e é barato: comparar `webhook_payloads.received_at`
 * (UTC de verdade) com o carimbo do provedor no corpo cru da mesma linha — se
 * a diferença deixar de ser ~180 min, é aqui que se mexe.
 *
 * A doc mostra `null` literal e strings `Y-m-d H:i:s`. Qualquer outra coisa
 * vira null em vez de ir para uma coluna datetime como lixo.
 *
 * @param mixed $v Valor cru do provedor (hora local BRT)
 * @returns string|null Datetime em UTC ('Y-m-d H:i:s'), ou null
 */
function sms_data_utc_ou_null($v): ?string
{
    if (!is_string($v)) return null;
    $v = trim($v);
    if ($v === '' || $v === 'null') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v)) return null;

    try {
        $d = new DateTime($v, new DateTimeZone('America/Sao_Paulo'));
        $d->setTimezone(new DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        // Data sintaticamente válida que o DateTime recusa não pode derrubar o
        // webhook — vira null, como qualquer outro lixo.
        return null;
    }
}

/**
 * O IMEI a que um lote do webhook se refere — para a coluna indexada de
 * `webhook_payloads`.
 *
 * 🔴 O PAYLOAD DA ALLCANCE NÃO TEM IMEI, e é por isso que esta função existe.
 * `webhook_capture_raw()` preenche a coluna com `webhook_raw_sniff_imei()`, que
 * procura `deviceImei`/`imei` no corpo — chaves que o provedor de SMS nunca
 * manda. Resultado até a v4.17.23: toda linha de `pushsms` ficava com `imei`
 * NULL, e **filtrar `webhook_payloads` por equipamento não trazia os SMS**.
 * Medido em produção: 6 de 6 chamadas com a coluna vazia.
 *
 * O vínculo existe e é indexado: `referencia_numero` → `sms_commands.referencia`
 * (UNIQUE `uk_sms_referencia`) → `imei`. Uma consulta por LOTE, não por item.
 *
 * ⚠️ Mesma natureza do `webhook_raw_sniff_imei()`: é **conveniência de
 * consulta**, não fonte de verdade — o corpo cru continua sendo o fato. Um lote
 * que misture equipamentos (o provedor manda `total: N`) grava o primeiro que
 * resolver; a linha continua achável por endpoint e data, e o corpo tem todos.
 *
 * ⚠️ Nunca propaga exceção: entre o deploy do código e a migração a tabela pode
 * não existir (CLAUDE.md), e a captura crua não pode derrubar o webhook.
 *
 * @param PDO   $db
 * @param array $itens Itens já extraídos por `sms_webhook_itens()`
 * @returns string|null IMEI do primeiro item cuja referência é nossa
 */
function sms_imei_do_lote(PDO $db, array $itens): ?string
{
    $refs = [];
    foreach ($itens as $item) {
        if (!is_array($item)) continue;
        $r = trim((string)($item['referencia_numero'] ?? ''));
        if ($r !== '') $refs[$r] = true;
    }
    if (!$refs) return null;

    try {
        $refs = array_slice(array_keys($refs), 0, 50);   // teto: o lote é pequeno por natureza
        $ph = implode(',', array_fill(0, count($refs), '?'));
        $st = $db->prepare("SELECT imei FROM sms_commands WHERE referencia IN ($ph) AND imei IS NOT NULL LIMIT 1");
        $st->execute($refs);
        $imei = $st->fetchColumn();
        return $imei !== false && $imei !== null ? (string)$imei : null;
    } catch (Throwable $e) {
        return null;
    }
}
