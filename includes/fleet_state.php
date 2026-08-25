<?php
/**
 * JIMI Webhook System — Estado da Frota v4.6.0
 * Arquivo: includes/fleet_state.php
 *
 * Fonte ÚNICA das regras de classificação de estado do veículo, compartilhada
 * entre os dois workers de segmentação e os cinco relatórios operacionais.
 *
 * A razão de existir é o risco R6 do plano: se "parado" no relatório de
 * paradas significar algo diferente de "parado" na segmentação de viagens, os
 * dois relatórios passam a se contradizer e nenhum dos dois é auditável. As
 * constantes moram aqui e `scripts/trip_builder.php` as consome deste arquivo
 * desde a v4.6.0 — antes tinha cópia própria.
 *
 * Consumidores:
 *   scripts/state_builder.php   — produz device_state_segments e speeding_events
 *   scripts/trip_builder.php    — segmenta viagens (STOP_SPEED_KMH/STOP_IDLE_SECONDS)
 *   handlers/rel_paradas.php    — recorte state='parado'
 *   handlers/rel_ociosidade.php — recorte state='ocioso'
 *   handlers/rel_ignicao.php    — transições acc 1↔0
 *   handlers/rel_velocidade.php — speeding_events
 *   handlers/rel_status_frota.php — estado corrente (resolve_current_state)
 */

// ── Limiares de estado ──────────────────────────────────────────────────────

/**
 * Abaixo desta velocidade o veículo é considerado parado.
 *
 * 3 km/h é o piso onde a deriva de GPS (veículo imóvel reportando 1–2 km/h)
 * deixa de ser confundida com movimento real.
 */
const STOP_SPEED_KMH = 3.0;

/**
 * Parada mais longa que isto encerra uma viagem (usado pelo trip_builder).
 *
 * Não confundir com OFFLINE_GAP_SECONDS: aqui o device está reportando e
 * dizendo "estou parado"; lá ele não está reportando nada.
 */
const STOP_IDLE_SECONDS = 300;

/**
 * Buraco entre pontos consecutivos que caracteriza ausência de dado.
 *
 * 30 min é folgado de propósito: devices em roaming ou em área de sombra
 * atrasam o envio sem estar realmente fora do ar, e classificar isso como
 * offline encheria o relatório de falsos positivos. O intervalo normal de
 * reporte é de 30 s a 5 min.
 */
const OFFLINE_GAP_SECONDS = 1800;

/**
 * Expressão SQL do ÚLTIMO SINAL de um equipamento — ponto único.
 *
 * 🔴 `devices.last_communication` sozinho engana: só `pushalarm.php` e
 * `pushlbs.php` escrevem nessa coluna. GPS e heartbeat **não** a tocam (não há
 * trigger no banco; conferido). Um equipamento que reporta posição e batimento
 * mas parou de mandar LBS/alarme ficaria "sem comunicar" para sempre, mesmo
 * transmitindo — e a tela diria offline para um device que está no ar.
 *
 * Por isso o último sinal é o MAIOR entre as quatro marcas: qualquer uma delas
 * é prova de que o equipamento falou com o servidor.
 *
 * Existe aqui, e não copiada em cada tela, porque "está online?" já teve
 * respostas diferentes em telas diferentes do mesmo sistema — `video_aovivo`
 * usava esta conta e `comandos` usava só `last_communication` com limiar de 15
 * min (v4.9.21), o que divergiria assim que um device parasse de mandar LBS.
 *
 * Exige `LEFT JOIN device_statistics ds ON ds.imei = d.imei` na consulta.
 *
 * @param string $d  Alias da tabela `devices`
 * @param string $ds Alias de `device_statistics`
 * @returns string Expressão SQL (datetime UTC)
 */
function device_last_seen_sql(string $d = 'd', string $ds = 'ds'): string
{
    return "GREATEST(
               COALESCE({$d}.last_communication,   '1970-01-01'),
               COALESCE({$ds}.last_gps_time,       '1970-01-01'),
               COALESCE({$ds}.last_heartbeat_time, '1970-01-01'),
               COALESCE({$ds}.last_event_time,     '1970-01-01')
           )";
}

/**
 * Presença legível a partir dos minutos desde o último sinal.
 *
 * O limiar de "online" é `OFFLINE_GAP_SECONDS`, o mesmo do Status da Frota e de
 * `resolve_current_state()` — duas definições de online no mesmo produto é
 * exatamente o defeito que este arquivo existe para não repetir.
 *
 * @param int|null $min Minutos desde o último sinal (NULL = nunca falou)
 * @returns array{nivel:string, rotulo:string} nivel: ok | aguardando | erro | neutro
 */
function device_presence(?int $min): array
{
    if ($min === null)                          return ['nivel' => 'neutro', 'rotulo' => 'sem contato registrado'];
    if ($min < 0)                               return ['nivel' => 'ok',     'rotulo' => 'agora'];
    if ($min * 60 <= OFFLINE_GAP_SECONDS)       return ['nivel' => 'ok',     'rotulo' => 'online'];
    if ($min <= 60)                             return ['nivel' => 'aguardando', 'rotulo' => 'há ' . $min . ' min'];
    if ($min <= 1440)                           return ['nivel' => 'aguardando', 'rotulo' => 'há ' . intdiv($min, 60) . 'h'];
    return ['nivel' => 'erro', 'rotulo' => 'há ' . intdiv($min, 1440) . 'd'];
}

/**
 * Limite de velocidade quando nem o equipamento nem o cliente definiram um.
 *
 * 80 km/h é o limite de rodovia de pista simples no Brasil (CTB art. 61),
 * que é o cenário mais comum de frota rodoviária.
 */
const DEFAULT_SPEED_LIMIT_KMH = 80;

/**
 * Piso de pontos para um excesso de velocidade valer como evento.
 *
 * Um único ponto acima do limite é indistinguível de erro de leitura de GPS
 * (o clássico salto de 140 km/h numa via urbana). Dois pontos consecutivos
 * já exigem que a condição tenha persistido entre dois envios.
 */
const MIN_SPEEDING_POINTS = 2;

/** Rótulos de exibição dos estados — usados nas cinco telas. */
const FLEET_STATE_LABELS = [
    'movimento' => 'Em movimento',
    'ocioso'    => 'Ocioso (motor ligado)',
    'parado'    => 'Parado (ignição desligada)',
    'offline'   => 'Sem comunicação',
];

/** Cor de cada estado no design system Coinbase. */
const FLEET_STATE_COLORS = [
    'movimento' => '#0052ff',
    'ocioso'    => '#a97a00',
    'parado'    => '#5b616e',
    'offline'   => '#cf202f',
];

// ── Classificação ───────────────────────────────────────────────────────────

/**
 * Classifica um ponto de gps_data em um estado do veículo.
 *
 * `offline` NUNCA sai daqui: é a ausência de ponto, não uma propriedade de um
 * ponto. Quem detecta buraco é o state_builder, comparando gps_time
 * consecutivos contra OFFLINE_GAP_SECONDS.
 *
 * @param int|string|bool|null $acc   Ignição (gps_data.acc): 1 = ligada
 * @param float|string|null    $speed Velocidade em km/h
 * @returns string movimento|ocioso|parado
 */
function classify_point($acc, $speed): string
{
    if (empty($acc)) {
        return 'parado';
    }
    return ((float)$speed > STOP_SPEED_KMH) ? 'movimento' : 'ocioso';
}

/**
 * Resolve o estado CORRENTE de um equipamento para a tela de Status da Frota.
 *
 * O segmento aberto (`ended_at IS NULL`) diz qual era o estado no último ponto
 * conhecido — o que não é a mesma coisa que o estado agora. Um veículo que
 * parou de reportar às 3h da manhã tem segmento aberto em `movimento`, e
 * mostrar "em movimento" às 10h seria mentira. A conta do silêncio é feita
 * na leitura de propósito: entre duas rodadas do cron a verdade muda sem que
 * nenhum dado novo entre no banco.
 *
 * @param string|null $segState    Estado do segmento aberto (NULL = sem segmento)
 * @param string|null $lastGpsTime UTC do último ponto do equipamento
 * @param string|null $now         UTC de referência (default: agora)
 * @returns string movimento|ocioso|parado|offline
 */
function resolve_current_state(?string $segState, ?string $lastGpsTime, ?string $now = null): string
{
    if ($lastGpsTime === null || $lastGpsTime === '') {
        return 'offline';
    }
    $nowTs  = strtotime($now ?? gmdate('Y-m-d H:i:s'));
    $lastTs = strtotime($lastGpsTime);
    if ($lastTs === false || ($nowTs - $lastTs) >= OFFLINE_GAP_SECONDS) {
        return 'offline';
    }
    return $segState ?: 'offline';
}

/**
 * Resolve o estado CORRENTE de um equipamento a partir do ÚLTIMO PONTO
 * conhecido (`device_statistics`), para telas AO VIVO — nunca do segmento.
 *
 * 🔴 `resolve_current_state()` confia no segmento aberto, que só é
 * regravado pelo cron de 15 em 15 min (`scripts/state_builder.php`).
 * `device_statistics.last_acc_status`/`last_speed`, ao contrário, são
 * atualizados a cada push de GPS — em tempo real. No intervalo entre duas
 * rodadas do cron, um veículo que ligou e saiu andando às 14:06 continua
 * com o segmento aberto em `parado` até a próxima rodada (~14:15), enquanto
 * `device_statistics` já mostra ignição ligada e velocidade real. O balão
 * de `/rastreamento` lê Estado do segmento e Ignição/Velocidade do
 * `device_statistics` — misturar as duas fontes produz exatamente
 * "Estado: Parado (ignição desligada)" ao lado de "Ignição: Ligada" e
 * "Vel: 65 km/h", os três campos de UM MESMO balão descrevendo instantes
 * diferentes. Aqui os três sempre vêm da mesma leitura, então nunca
 * divergem entre si — o preço é não ter a MESMA definição de "estado" que
 * os relatórios batch (`rel_paradas`, `rel_ociosidade`, `rel_status_frota`),
 * que precisam do segmento para fechar duração/histórico; essas telas
 * continuam com `resolve_current_state()` de propósito.
 *
 * @param string|null           $lastGpsTime UTC do último ponto do equipamento
 * @param int|string|bool|null  $acc         Ignição do último ponto (device_statistics.last_acc_status)
 * @param float|string|null     $speed       Velocidade do último ponto (device_statistics.last_speed)
 * @param string|null           $now         UTC de referência (default: agora)
 * @returns string movimento|ocioso|parado|offline
 */
function resolve_live_state(?string $lastGpsTime, $acc, $speed, ?string $now = null): string
{
    if ($lastGpsTime === null || $lastGpsTime === '') {
        return 'offline';
    }
    $nowTs  = strtotime($now ?? gmdate('Y-m-d H:i:s'));
    $lastTs = strtotime($lastGpsTime);
    if ($lastTs === false || ($nowTs - $lastTs) >= OFFLINE_GAP_SECONDS) {
        return 'offline';
    }
    return classify_point($acc, $speed);
}

/**
 * Limite de velocidade vigente para um equipamento.
 *
 * Precedência equipamento → cliente → global. `0` é tratado como "não
 * definido" e não como "limite zero": a coluna é unsigned e um zero gravado
 * por engano faria todo ponto virar infração.
 *
 * @param int|string|null $deviceLimit   devices.speed_limit_kmh
 * @param int|string|null $customerLimit customers.default_speed_limit_kmh
 * @returns int Limite em km/h
 */
function resolve_speed_limit($deviceLimit, $customerLimit): int
{
    if ($deviceLimit !== null && (int)$deviceLimit > 0) {
        return (int)$deviceLimit;
    }
    if ($customerLimit !== null && (int)$customerLimit > 0) {
        return (int)$customerLimit;
    }
    return DEFAULT_SPEED_LIMIT_KMH;
}

// ── Formatação ──────────────────────────────────────────────────────────────

/**
 * Formata uma duração em segundos como "2h 14min", "36min", "48s".
 *
 * Vive aqui porque os cinco relatórios da fase mostram duração e o helper
 * equivalente do relatório de geocercas (fmt_dwell) é privado daquele
 * arquivo. Duas grafias diferentes de duração na mesma suíte de relatórios
 * confundem quem compara duas telas lado a lado.
 *
 * @param int|null $secs      Duração em segundos
 * @param string   $emptyText O que exibir quando não há duração
 * @returns string
 */
function fmt_duration(?int $secs, string $emptyText = '—'): string
{
    if ($secs === null || $secs < 0) {
        return $emptyText;
    }
    if ($secs < 60) {
        return $secs . 's';
    }
    $h = intdiv($secs, 3600);
    $m = intdiv($secs % 3600, 60);
    if ($h > 0) {
        return $h . 'h ' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . 'min';
    }
    return $m . 'min';
}

/**
 * Rótulo de exibição de um estado.
 *
 * @param string|null $state movimento|ocioso|parado|offline
 * @returns string
 */
function fleet_state_label(?string $state): string
{
    return FLEET_STATE_LABELS[$state ?? ''] ?? '—';
}

/**
 * Cor de exibição de um estado.
 *
 * @param string|null $state movimento|ocioso|parado|offline
 * @returns string Hex
 */
function fleet_state_color(?string $state): string
{
    return FLEET_STATE_COLORS[$state ?? ''] ?? '#5b616e';
}
