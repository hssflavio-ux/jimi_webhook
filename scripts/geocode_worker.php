<?php
/**
 * JIMI Webhook System — Pré-aquecimento do cache de endereços (v4.8.0)
 * Script: scripts/geocode_worker.php   (cron a cada 5 min)
 *
 * Percorre as coordenadas recentes que ainda não têm endereço em
 * `geocode_cache` e as resolve no Nominatim interno, em paralelo.
 *
 * ── POR QUE EM SEGUNDO PLANO, E NÃO NA ENTRADA NEM NA SAÍDA ──────────────
 *
 * **Não na entrada (webhook)**: o `WebhookHandler` processa dentro de
 * `beginTransaction`/`commit`. Uma chamada HTTP de ~40 ms por ponto lá dentro
 * segura a transação aberta e — pior — acopla a INGESTÃO à disponibilidade do
 * Nominatim. Se o geocode cair, ou a gravação do GPS para, ou passa a depender
 * de tratamento de erro num caminho que hoje é simples e confiável. Perder
 * posição de veículo para enfeitar relatório é troca ruim.
 *
 * **Não na saída (página)**: com chave de 6 casas, ~99% dos pontos de um dia
 * são inéditos (medido: 821 pontos/dia → 819 distintos). Um relatório de
 * posições com cache frio faria centenas de chamadas enquanto o usuário
 * espera; numa frota 25× maior seriam ~20 mil pontos, ou ~2 min de página
 * pendurada. O teto de `geocode_resolve()` limita o dano, mas o resultado
 * seria endereço faltando justamente no relatório grande.
 *
 * **Em segundo plano**: o custo real é ridículo — 435 pontos/dia hoje levam
 * ~3 s; uma frota de 100 equipamentos geraria ~11 mil pontos/dia, ou ~70 s
 * diluídos em 288 execuções de cron. E o relatório lê UMA consulta ao banco.
 *
 * Uso: php scripts/geocode_worker.php [--limit=N] [--days=N] [--backfill]
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/geocode.php';
require_once __DIR__ . '/../core/Logger.php';

$opts     = getopt('', ['limit::', 'days::', 'backfill']);
$limite   = max(1, (int)($opts['limit'] ?? 800));
$dias     = max(1, (int)($opts['days'] ?? 3));
$backfill = isset($opts['backfill']);

if ($backfill) {
    // Backfill histórico: sem janela de tempo, teto alto. Roda à mão.
    $dias   = 3650;
    $limite = max($limite, 5000);
}

$db = Database::getInstance()->getConnection();
$t0 = microtime(true);

/**
 * Coordenadas distintas ainda SEM endereço no cache.
 *
 * O `LEFT JOIN ... IS NULL` faz o filtro no banco: trazer tudo para o PHP e
 * comparar em memória custaria mais do que a própria geocodificação.
 *
 * @param PDO    $db
 * @param string $tabela
 * @param string $colLat
 * @param string $colLng
 * @param string $colData Coluna de tempo para a janela
 * @param int    $dias
 * @param int    $limite
 * @returns array Lista de [lat, lng]
 */
function pendentes(PDO $db, string $tabela, string $colLat, string $colLng,
                   string $colData, int $dias, int $limite): array {
    $sql = "
        SELECT DISTINCT ROUND(t.$colLat, 6) AS lat, ROUND(t.$colLng, 6) AS lng
        FROM $tabela t
        LEFT JOIN geocode_cache g
               ON g.lat = ROUND(t.$colLat, 6) AND g.lng = ROUND(t.$colLng, 6)
        WHERE t.$colData >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)
          AND t.$colLat IS NOT NULL AND t.$colLat <> 0
          AND g.id IS NULL
        LIMIT :lim";
    try {
        $st = $db->prepare($sql);
        $st->bindValue(':d', $dias, PDO::PARAM_INT);
        $st->bindValue(':lim', $limite, PDO::PARAM_INT);
        $st->execute();
        return array_map(fn($r) => [(float)$r['lat'], (float)$r['lng']], $st->fetchAll());
    } catch (Throwable $e) {
        echo "  aviso: $tabela — {$e->getMessage()}\n";
        return [];
    }
}

// Ordem importa: o que aparece em relatório primeiro.
$fontes = [
    ['alarms',          'latitude', 'longitude', 'alarm_time'],
    ['gps_data',        'latitude', 'longitude', 'gps_time'],
    ['geofence_events', 'latitude', 'longitude', 'event_time'],
    ['events',          'latitude', 'longitude', 'event_time'],
];

$total = 0;
$resolvidos = 0;
$restante = $limite;

foreach ($fontes as [$tab, $cl, $cg, $cd]) {
    if ($restante <= 0) break;
    $pts = pendentes($db, $tab, $cl, $cg, $cd, $dias, $restante);
    if (!$pts) continue;

    $total += count($pts);
    $mapa = geocode_fetch_many($pts);
    $resolvidos += count($mapa);
    $restante -= count($pts);

    printf("  %-16s %4d pendente(s) → %d resolvido(s)\n", $tab, count($pts), count($mapa));
}

$ms = (microtime(true) - $t0) * 1000;
$linha = sprintf(
    "[%s] geocode_worker OK — %d ponto(s) pendente(s), %d resolvido(s) em %.0f ms (%.1f pts/s)%s",
    Logger::stamp(), $total, $resolvidos, $ms,
    $ms > 0 ? $resolvidos / ($ms / 1000) : 0,
    $backfill ? ' [backfill]' : ''
);
echo $linha . "\n";

if ($total > 0 && $resolvidos === 0) {
    // Sintoma clássico de Nominatim fora do ar. Vale log de aviso: sem isto o
    // sintoma visível seria só "relatório sem endereço", que ninguém liga ao
    // geocode.
    Logger::warning('geocode_worker: nenhum ponto resolvido', [
        'pendentes' => $total, 'nominatim' => geocode_base_url(),
    ]);
}
