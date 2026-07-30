<?php
/**
 * JIMI Webhook System — Agendamento de relatórios v4.7.0
 * Arquivo: includes/schedule.php
 *
 * Cálculo de horário e de período dos relatórios agendados. Vive separado do
 * dispatcher porque a tela (`handlers/agendamentos.php`) precisa exibir o
 * "próximo envio" com exatamente a mesma conta que o cron vai fazer — duas
 * implementações divergiriam e a tela passaria a mentir.
 *
 * ── O ponto de maior risco da fase: fuso ────────────────────────────────────
 *
 *   send_hour   é hora BRT   — o que o usuário digitou
 *   next_run_at é UTC        — o que o dispatcher compara com NOW()
 *
 * A conversão é sempre feita construindo o instante no fuso do usuário e
 * convertendo (`DateTimeZone`), nunca somando 3 horas. O Brasil aboliu o
 * horário de verão em 2019, então hoje o offset é constante — mas somar offset
 * fixo estaria errado para qualquer data anterior a isso e voltaria a estar
 * errado no dia em que a política mudar. O custo de fazer certo é zero.
 *
 * O período consultado também é BRT: "ontem" para a frequência diária, "semana
 * passada" para a semanal, "mês passado" para a mensal — todos resolvidos por
 * brt_day_range_to_utc(), como o worker.php já faz.
 */

require_once __DIR__ . '/functions.php'; // brt_day_range_to_utc()

/** Fuso de exibição/entrada do sistema. */
const SCHEDULE_TZ_BRT = 'America/Sao_Paulo';

/** Falhas consecutivas que desativam um agendamento. */
const SCHEDULE_MAX_FAILURES = 3;

/**
 * Teto de linhas de um relatório agendado.
 *
 * SYNC_EXPORT_MAX_ROWS (10.000) não se aplica aqui — o caminho é assíncrono e
 * não há usuário esperando. Ainda assim há teto: gerar um XLSX de milhões de
 * linhas estoura a memória do worker e derruba a fila inteira, inclusive as
 * notificações que estavam atrás dele.
 */
const SCHEDULE_MAX_ROWS = 100000;

/** Rótulos das frequências. */
const SCHEDULE_FREQUENCY_LABELS = [
    'diaria'  => 'Diária',
    'semanal' => 'Semanal',
    'mensal'  => 'Mensal',
];

/** Dias da semana (ISO-8601: 1 = segunda). */
const SCHEDULE_DOW_LABELS = [
    1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira',
    4 => 'Quinta-feira',  5 => 'Sexta-feira', 6 => 'Sábado', 7 => 'Domingo',
];

/**
 * Converte um instante BRT (data + hora cheia) para UTC.
 *
 * @param string $brtDate Data no formato Y-m-d, interpretada como BRT
 * @param int    $hour    Hora cheia BRT (0-23)
 * @returns string Datetime UTC 'Y-m-d H:i:s'
 */
function brt_hour_to_utc(string $brtDate, int $hour): string
{
    $hour = max(0, min(23, $hour));
    $dt = new DateTime(
        sprintf('%s %02d:00:00', $brtDate, $hour),
        new DateTimeZone(SCHEDULE_TZ_BRT)
    );
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
}

/**
 * Calcula o próximo disparo (UTC) de um agendamento.
 *
 * O cálculo é feito no calendário BRT — "toda segunda às 7h" é uma afirmação
 * sobre o calendário do usuário, não sobre o UTC — e só então convertido. A
 * busca avança dia a dia a partir de "hoje BRT" até achar o primeiro instante
 * estritamente posterior a `$afterUtc`.
 *
 * @param array       $sch      Linha de report_schedules (frequency, send_hour, send_dow, send_dom)
 * @param string|null $afterUtc Instante UTC de referência (default: agora)
 * @returns string Datetime UTC 'Y-m-d H:i:s'
 */
function schedule_next_run(array $sch, ?string $afterUtc = null): string
{
    $afterUtc = $afterUtc ?: gmdate('Y-m-d H:i:s');
    $afterTs  = strtotime($afterUtc . ' UTC');

    $freq = $sch['frequency'] ?? 'diaria';
    $hour = (int)($sch['send_hour'] ?? 7);

    // Ponto de partida: hoje no calendário BRT (não no UTC — perto da
    // meia-noite os dois discordam, e quem manda é o calendário do usuário).
    $cursor = new DateTime('now', new DateTimeZone('UTC'));
    $cursor->setTimestamp($afterTs);
    $cursor->setTimezone(new DateTimeZone(SCHEDULE_TZ_BRT));

    // 400 iterações cobrem com folga o pior caso (mensal com dia 28).
    for ($i = 0; $i < 400; $i++) {
        $day = $cursor->format('Y-m-d');
        $matches = false;

        switch ($freq) {
            case 'semanal':
                $dow = (int)($sch['send_dow'] ?: 1);
                $matches = ((int)$cursor->format('N') === max(1, min(7, $dow)));
                break;

            case 'mensal':
                // Teto de 28 na tela: 29/30/31 não existem em todo mês, e
                // "pular fevereiro" nunca é o que o usuário quis dizer.
                $dom = (int)($sch['send_dom'] ?: 1);
                $matches = ((int)$cursor->format('j') === max(1, min(28, $dom)));
                break;

            case 'diaria':
            default:
                $matches = true;
        }

        if ($matches) {
            $candidate = brt_hour_to_utc($day, $hour);
            if (strtotime($candidate . ' UTC') > $afterTs) {
                return $candidate;
            }
        }

        $cursor->modify('+1 day');
    }

    // Inalcançável com os limites acima; devolve algo sensato em vez de null.
    return brt_hour_to_utc(
        (new DateTime('now', new DateTimeZone(SCHEDULE_TZ_BRT)))->modify('+1 day')->format('Y-m-d'),
        $hour
    );
}

/**
 * Resolve o período que o relatório deve cobrir, em dias BRT.
 *
 * O relatório fala do período FECHADO anterior, nunca do corrente: quem recebe
 * o diário às 7h quer o dia de ontem inteiro, não as 7 horas de hoje.
 *
 *   diária  → ontem
 *   semanal → segunda a domingo da semana passada
 *   mensal  → mês passado inteiro
 *
 * @param string      $frequency diaria|semanal|mensal
 * @param string|null $refBrtDay Dia BRT de referência (default: hoje BRT)
 * @returns array{0:string,1:string} [dateFrom, dateTo] em Y-m-d BRT
 */
function schedule_period_days(string $frequency, ?string $refBrtDay = null): array
{
    $ref = new DateTime($refBrtDay ?: brt_today(), new DateTimeZone(SCHEDULE_TZ_BRT));

    switch ($frequency) {
        case 'semanal':
            // Semana ISO: segunda a domingo. 'last monday' a partir de uma
            // segunda-feira devolve a segunda ANTERIOR, que é o que queremos.
            $start = (clone $ref)->modify('monday last week');
            $end   = (clone $start)->modify('+6 days');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];

        case 'mensal':
            $start = (clone $ref)->modify('first day of last month');
            $end   = (clone $ref)->modify('last day of last month');
            return [$start->format('Y-m-d'), $end->format('Y-m-d')];

        case 'diaria':
        default:
            $d = (clone $ref)->modify('-1 day')->format('Y-m-d');
            return [$d, $d];
    }
}

/**
 * Descrição legível da recorrência, para a tela e para o corpo do e-mail.
 *
 * @param array $sch Linha de report_schedules
 * @returns string Ex.: "Toda segunda-feira às 07:00 (BRT)"
 */
function schedule_describe(array $sch): string
{
    $hour = sprintf('%02d:00', (int)($sch['send_hour'] ?? 7));

    switch ($sch['frequency'] ?? 'diaria') {
        case 'semanal':
            $dow = SCHEDULE_DOW_LABELS[(int)($sch['send_dow'] ?: 1)] ?? 'Segunda-feira';
            return 'Toda ' . mb_strtolower($dow) . ' às ' . $hour . ' (BRT)';
        case 'mensal':
            return 'Todo dia ' . (int)($sch['send_dom'] ?: 1) . ' às ' . $hour . ' (BRT)';
        case 'diaria':
        default:
            return 'Todo dia às ' . $hour . ' (BRT)';
    }
}

/**
 * Normaliza a lista de destinatários: até 3, sem duplicata, só válidos.
 *
 * Mesma regra do alerta de geocerca (v4.5.0): o teto existe para que a tela
 * não vire lista de distribuição — quem precisa de mais usa um grupo no
 * próprio servidor de e-mail.
 *
 * @param string|array $raw Texto separado por vírgula/ponto-e-vírgula/quebra, ou array
 * @returns array Lista reindexada de e-mails válidos
 */
function schedule_parse_recipients($raw): array
{
    if (is_string($raw)) {
        $raw = preg_split('/[,;\r\n]+/', $raw) ?: [];
    }
    if (!is_array($raw)) {
        return [];
    }

    $out = [];
    foreach ($raw as $e) {
        $e = trim((string)$e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array($e, $out, true)) {
            $out[] = $e;
        }
    }
    return array_slice($out, 0, 3);
}

/**
 * Tipos de relatório agendáveis, na ordem em que aparecem na tela.
 *
 * A chave é o `report_type` que `buildReportSource()` (scripts/worker.php)
 * entende. Manter as duas listas em sincronia: um tipo aqui que não exista lá
 * gera job que falha com "tipo não reconhecido".
 *
 * `fleet_status` fica de fora de propósito — é uma foto do agora, e agendar
 * "o estado da frota de ontem às 7h" não significa nada.
 *
 * @returns array<string,string> tipo => rótulo
 */
function schedule_report_types(): array
{
    return [
        'alarms'      => 'Alarmes',
        'occurrences' => 'Ocorrências',
        'positions'   => 'Posições GPS',
        'trips'       => 'Viagens (Deslocamento)',
        'devices'     => 'Equipamentos',
        'stops'       => 'Paradas',
        'idling'      => 'Ociosidade',
        'ignition'    => 'Ignição',
        'speeding'    => 'Excesso de Velocidade',
    ];
}
