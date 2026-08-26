<?php
/**
 * bycamera — Cliente do endpoint de consulta de alarmes do IoT Hub (v2)
 *
 * GET /api/v2/alarm/getAlarm — mora no `tracker-dvr-api` (porta 9080), o MESMO
 * container que serve consultas históricas — NÃO confundir com a porta
 * :10088, que é só ENVIO de comando (ver o cabeçalho de
 * `handlers/sendcommand.php`, bug #2 documentado ali: 9080 para enviar
 * comando nunca funcionou; aqui é o oposto — 9080 é o certo, porque é
 * CONSULTA). Devolve os alarmes que a plataforma da Jimi já recebeu do
 * device, para cruzar contra o que ESTE sistema já tem gravado.
 *
 * ── MEDIDO, não suposto (26/08/2026, ssh+curl contra produção,
 *    865478070654829 / JC371 — mesma técnica de `docs/COMANDOS_128_CONSULTA.md`
 *    §"sonda de comando em câmera real") ──────────────────────────────────────
 *
 *   - A resposta é `{"code":0,"msg":"...","data":[{"alarmMsg":"<json>"}]}` —
 *     cada item de `data` traz o alarme inteiro como STRING JSON dentro de
 *     `alarmMsg`, não como objeto direto. Decodificar duas vezes.
 *
 *   - `msg.alarmTime` é UTC — bate com `alarms.alarm_time`. Confirmado
 *     comparando com `msg.alarmLabel` do MESMO registro: o label embute a
 *     hora LOCAL da câmera (UTC−3, a mesma convenção de `filelist.php` e do
 *     nome dos anexos — ver CLAUDE.md), e a diferença entre os dois campos no
 *     mesmo alarme é exatamente 3h em toda amostra.
 *
 *   - `msg.alarmLabel` vem SEPARADO POR VÍRGULA — cada elemento é um byte em
 *     hex de dois dígitos (`"30","36","35",...`). Concatenar os elementos SEM
 *     a vírgula reproduz, byte a byte, o valor gravado em `alarms.alarm_label`
 *     (conferido contra o banco de produção). Este cliente já devolve a
 *     versão limpa (sem vírgula, pronta para casar com `alarms.alarm_label`
 *     ou para entrar no comando `VIDEOUPLOAD`).
 *
 *   - Nem todo alarme tem `alarmLabel` — só os que a câmera capturou com
 *     anexo (medido: `alertType` 264/ADAS e 265/DMS têm; 256/257 (bitmask
 *     padrão/diagnóstico) e `"removeAlarmType"` (fim de alarme) não têm).
 *
 *   - **Teto de 1000 linhas por chamada, mais recentes primeiro — sem
 *     paginação documentada.** Numa janela de 7 dias pedida contra uma câmera
 *     ativa, as 1000 linhas cobriram só as últimas ~28h — um período "de 7
 *     dias" que na prática devolveu 1. `iothub_get_alarms_chunked()` existe
 *     por causa disso: subdivide a janela recursivamente enquanto o teto for
 *     atingido.
 *
 *   - `startTime`/`endTime` são interpretados em UTC (mesma conclusão: pedir
 *     até "agora" (23:59:59 do dia UTC corrente) devolveu resultados até o
 *     instante exato da chamada, não até a meia-noite BRT).
 */

require_once __DIR__ . '/../core/Logger.php';

/** Teto real observado do endpoint (26/08/2026) — usado para decidir quando subdividir a janela. */
const IOTHUB_ALARM_API_TETO = 1000;

/** Menor janela que ainda vale a pena subdividir — abaixo disso, aceita o teto e segue (evita recursão infinita/custosa). */
const IOTHUB_ALARM_API_GRANULARIDADE_MIN_SEGUNDOS = 300; // 5 min

/**
 * @returns string Base do tracker-dvr-api, sem barra final
 */
function iothub_alarm_api_base(): string
{
    return rtrim((string)(getenv('IOTHUB_DVR_API_URL') ?: 'http://localhost:9080'), '/');
}

/**
 * Uma chamada crua ao endpoint, sem paginação/subdivisão.
 *
 * @param string $imei
 * @param string $startUtc 'Y-m-d H:i:s', UTC
 * @param string $endUtc   'Y-m-d H:i:s', UTC
 * @returns array{ok:bool, alarmes:array, erro:?string}
 *   Cada item de `alarmes`: array{imei:string, alert_type:?string,
 *   alarm_time:?string, alarm_label:?string, msg_class:?int, msg:array}
 */
function iothub_get_alarms_raw(string $imei, string $startUtc, string $endUtc): array
{
    $url = iothub_alarm_api_base() . '/api/v2/alarm/getAlarm?' . http_build_query([
        'deviceImei' => $imei,
        'startTime'  => $startUtc,
        'endTime'    => $endUtc,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw       = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode !== 200 || $raw === false) {
        return ['ok' => false, 'alarmes' => [], 'erro' => $curlError ?: "HTTP $httpCode"];
    }

    $j = json_decode($raw, true);
    if (!is_array($j) || ($j['code'] ?? null) !== 0 || !isset($j['data']) || !is_array($j['data'])) {
        return ['ok' => false, 'alarmes' => [], 'erro' => 'Resposta inesperada: ' . substr((string)$raw, 0, 300)];
    }

    $alarmes = [];
    foreach ($j['data'] as $item) {
        $m = json_decode((string)($item['alarmMsg'] ?? ''), true);
        if (!is_array($m) || !isset($m['msg']) || !is_array($m['msg'])) {
            continue;
        }
        $msg   = $m['msg'];
        $label = null;
        if (isset($msg['alarmLabel']) && $msg['alarmLabel'] !== '') {
            $limpo = str_replace(',', '', (string)$msg['alarmLabel']);
            $label = $limpo !== '' ? $limpo : null;
        }
        $alarmes[] = [
            'imei'        => (string)($m['imei'] ?? $imei),
            'alert_type'  => isset($msg['alertType']) ? (string)$msg['alertType'] : null,
            'alarm_time'  => isset($msg['alarmTime']) ? (string)$msg['alarmTime'] : null,
            'alarm_label' => $label,
            'msg_class'   => isset($m['msgClass']) ? (int)$m['msgClass'] : null,
            'msg'         => $msg,
        ];
    }
    return ['ok' => true, 'alarmes' => $alarmes, 'erro' => null];
}

/**
 * Como `iothub_get_alarms_raw()`, mas subdivide a janela recursivamente
 * quando bate no teto do endpoint — sem isso, um período de vários dias
 * contra uma câmera ativa devolve só as últimas ~28h e chama isso de
 * "período completo" (ver cabeçalho deste arquivo).
 *
 * ⚠️ A subdivisão pode contar o MESMO alarme duas vezes perto da fronteira
 * (janelas com ponta inclusiva nos dois lados) — inofensivo para quem só usa
 * o resultado para CONFERIR existência (dedup por alarm_label no chamador),
 * não para contar alarmes.
 *
 * @returns array{ok:bool, alarmes:array, truncado:bool}
 *   `truncado` = true quando o teto foi atingido mesmo na menor granularidade
 *   aceita — sinal de que ainda pode faltar alarme fora do resultado.
 */
function iothub_get_alarms_chunked(string $imei, string $startUtc, string $endUtc): array
{
    $r = iothub_get_alarms_raw($imei, $startUtc, $endUtc);
    if (!$r['ok']) {
        return ['ok' => false, 'alarmes' => [], 'truncado' => false];
    }
    if (count($r['alarmes']) < IOTHUB_ALARM_API_TETO) {
        return ['ok' => true, 'alarmes' => $r['alarmes'], 'truncado' => false];
    }

    $ini = strtotime($startUtc . ' UTC');
    $fim = strtotime($endUtc . ' UTC');
    if ($ini === false || $fim === false || ($fim - $ini) < IOTHUB_ALARM_API_GRANULARIDADE_MIN_SEGUNDOS) {
        Logger::warning('iothub_get_alarms_chunked: teto do endpoint atingido na menor granularidade aceita', [
            'imei' => $imei, 'start' => $startUtc, 'end' => $endUtc,
        ]);
        return ['ok' => true, 'alarmes' => $r['alarmes'], 'truncado' => true];
    }

    $meioStr = gmdate('Y-m-d H:i:s', $ini + intdiv($fim - $ini, 2));
    $a = iothub_get_alarms_chunked($imei, $startUtc, $meioStr);
    $b = iothub_get_alarms_chunked($imei, $meioStr, $endUtc);
    return [
        'ok'       => $a['ok'] && $b['ok'],
        'alarmes'  => array_merge($a['alarmes'], $b['alarmes']),
        'truncado' => $a['truncado'] || $b['truncado'],
    ];
}
