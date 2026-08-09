<?php
/**
 * Geocodificação Reversa com cache — v4.8.0
 *
 * Endereço no formato **"rua, cidade, estado"** a partir de lat/lng, servido
 * por um Nominatim **interno** (`NOMINATIM_URL`, default `http://10.1.0.15:8080`).
 *
 * ── O QUE MUDOU E POR QUÊ ────────────────────────────────────────────────
 * Até a v4.7.x isto chamava o Nominatim **público**, que impõe 1 req/s. Essa
 * única restrição moldava todo o resto: `rel_posicoes.php` resolvia no máximo
 * **3 endereços por página**, e o `geocode_cache` acumulou 82 linhas em meses
 * de operação — cache inútil na prática, tela quase sempre sem endereço.
 *
 * Com o servidor interno o custo medido é outro (homolog, 01/08/2026, sobre
 * coordenadas reais do banco):
 *
 * (medidas sobre pontos GENUINAMENTE INÉDITOS, sem o INSERT no meio do laço —
 * ver o aviso de metodologia abaixo)
 *
 *   sequencial (conc=1) ... 23,8 ms/ponto →  42 pts/s
 *   paralelo conc=5 ....... 5,8 ms/ponto  → 174 pts/s
 *   paralelo conc=10 ...... 2,2 ms/ponto  → 448 pts/s   ← usado
 *
 * ⚠️ **Armadilha de metodologia, registrada porque custou uma conclusão
 * errada.** A primeira medição deu "conc=5 é o ótimo e conc=20 satura", e
 * estava errada por dois motivos: (a) reusava a mesma amostra entre os níveis
 * de concorrência, e (b) tinha o INSERT no cache dentro do laço. Como cada
 * INSERT em autocommit custa **72 ms** neste servidor (fsync por commit) e o
 * Nominatim custa **2 ms**, o que estava sendo medido era o banco, não a API.
 * Separados, o Nominatim escala sem esforço e o gargalo real virou a escrita —
 * resolvida em `geocode_persist()` com transação única.
 *
 * Também medido: primeira passada e segunda passada sobre os mesmos pontos dão
 * o MESMO tempo (1,0×). O Nominatim interno não tem cache de resposta
 * relevante, então não adianta contar com ele.
 *
 * ── PRECISÃO DA CHAVE DE CACHE ───────────────────────────────────────────
 * A chave é arredondada em **6 casas** e não menos. Tentar aumentar a taxa de
 * acerto com uma grade mais grossa foi medido e REPROVADO: a 4 casas (~11 m) o
 * endereço muda em **10% dos pontos** — e muda para uma RUA DIFERENTE, não uma
 * variação de grafia (ex.: "Rua João Pessoa" virava "Rua Evaristo da Veiga").
 * Num relatório que o cliente lê, 1 endereço errado a cada 10 é pior do que
 * qualquer ganho de desempenho.
 *
 * Consequência aceita: com 6 casas, ~99% dos pontos de um dia são inéditos, e
 * o cache NÃO se enche sozinho a tempo de servir a primeira leitura. É por isso
 * que existe o `scripts/geocode_worker.php` — o cache é abastecido em segundo
 * plano, não no caminho de quem está esperando a página.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Requisições simultâneas ao Nominatim. 10 foi o melhor medido (448 pts/s);
 * mantido conservador porque o servidor é compartilhado com outros consumidores
 * da rede interna, e o ganho de 5→10 já é suficiente para o volume real.
 */
const GEOCODE_CONCURRENCY = 10;

/** Casas decimais da chave de cache. 6 = ~0,11 m. Não baixar (ver cabeçalho). */
const GEOCODE_PRECISION = 6;

/**
 * Base do Nominatim interno.
 *
 * @returns string
 */
function geocode_base_url(): string
{
    $u = trim((string)(getenv('NOMINATIM_URL') ?: ''));
    return rtrim($u !== '' ? $u : 'http://10.1.0.15:8080', '/');
}

/**
 * Chave canônica de cache para um par de coordenadas.
 *
 * @param float $lat
 * @param float $lng
 * @returns string
 */
function geocode_key(float $lat, float $lng): string
{
    return round($lat, GEOCODE_PRECISION) . ',' . round($lng, GEOCODE_PRECISION);
}

/**
 * Reduz o `address` do Nominatim a "rua, cidade, estado".
 *
 * Cada campo tem cascata de alternativas porque o Nominatim não garante as
 * mesmas chaves em toda parte: via de pedestre não tem `road`, distrito rural
 * não tem `city`. Sem a cascata, endereço em rodovia ou zona rural sairia
 * vazio — que é justamente onde a frota mais anda.
 *
 * @param array|null $a Bloco `address` da resposta
 * @returns string Vazio se nada aproveitável
 */
function geocode_format(?array $a): string
{
    if (!$a) return '';

    $rua = $a['road']
        ?? $a['pedestrian']  ?? $a['footway']    ?? $a['residential']
        ?? $a['cycleway']    ?? $a['path']       ?? $a['neighbourhood']
        ?? $a['hamlet']      ?? '';

    $cidade = $a['city']         ?? $a['town']    ?? $a['village']
           ?? $a['municipality'] ?? $a['county']  ?? '';

    $estado = $a['state'] ?? $a['state_district'] ?? '';

    $partes = array_values(array_filter([$rua, $cidade, $estado], fn($x) => trim((string)$x) !== ''));
    return implode(', ', $partes);
}

/**
 * Uma consulta ao Nominatim. Não toca no cache.
 *
 * @param float $lat
 * @param float $lng
 * @returns string|null Endereço formatado, ou null em falha
 */
function geocode_fetch_one(float $lat, float $lng): ?string
{
    $url = sprintf('%s/reverse?lat=%.6f&lon=%.6f&format=json&accept-language=pt-BR&zoom=18',
                   geocode_base_url(), $lat, $lng);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_USERAGENT      => 'bycamera/4.9',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) return null;
    $j = json_decode((string)$body, true);
    $end = geocode_format($j['address'] ?? null);
    return $end !== '' ? $end : null;
}

/**
 * Consulta em LOTE e PARALELO, gravando no cache o que resolver.
 *
 * @param array $pontos Lista de [lat, lng]
 * @returns array Mapa chave → endereço (só o que resolveu agora)
 */
function geocode_fetch_many(array $pontos): array
{
    if (!$pontos) return [];
    $db = Database::getInstance()->getConnection();
    $out = [];
    $paraGravar = [];

    $loteIdx = 0;
    foreach (array_chunk($pontos, GEOCODE_CONCURRENCY) as $lote) {
        $loteIdx++;

        // ── Disjuntor ────────────────────────────────────────────
        // Se o PRIMEIRO lote não resolveu nada, o Nominatim está fora do ar ou
        // inalcançável — e insistir custa 3 s de timeout por lote. Num
        // relatório de 400 pontos isso pendurava a página por 2 minutos para,
        // no fim, não mostrar endereço nenhum. Desistir cedo devolve a página
        // rápido, com a coluna vazia, que é o comportamento honesto.
        if ($loteIdx === 2 && !$out) {
            break;
        }

        $mh = curl_multi_init();
        $hs = [];
        foreach ($lote as $p) {
            $lat = round((float)$p[0], GEOCODE_PRECISION);
            $lng = round((float)$p[1], GEOCODE_PRECISION);
            $url = sprintf('%s/reverse?lat=%.6f&lon=%.6f&format=json&accept-language=pt-BR&zoom=18',
                           geocode_base_url(), $lat, $lng);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_USERAGENT      => 'bycamera/4.9',
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[] = ['ch' => $ch, 'lat' => $lat, 'lng' => $lng];
        }

        $rodando = null;
        do {
            curl_multi_exec($mh, $rodando);
            curl_multi_select($mh, 0.5);
        } while ($rodando > 0);

        foreach ($hs as $h) {
            $body = curl_multi_getcontent($h['ch']);
            $code = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);

            if ($code !== 200 || !$body) continue;
            $j = json_decode((string)$body, true);
            $end = geocode_format($j['address'] ?? null);
            if ($end === '') continue;

            $out[$h['lat'] . ',' . $h['lng']] = $end;
            $paraGravar[] = [$h['lat'], $h['lng'], $end];
        }
        curl_multi_close($mh);
    }

    geocode_persist($paraGravar);
    return $out;
}

/**
 * Grava o lote no cache em UMA transação, com INSERT multi-linha.
 *
 * ⚠️ ESTE É O PONTO QUENTE DE DESEMPENHO DE TODO O GEOCODE, e não é o que
 * parece. Medido no homolog em 01/08/2026:
 *
 *   INSERT linha a linha (autocommit) .... 72 ms POR LINHA  → 200 linhas = 14,4 s
 *   Nominatim, conc=10, pontos frios ..... 2,2 ms por ponto → 448 pts/s
 *
 * Ou seja: gravar o resultado custava **33× mais** do que obtê-lo. A causa é
 * `innodb_flush_log_at_trx_commit=1` — cada INSERT em autocommit força um
 * fsync. Agrupar num único commit troca N fsyncs por um.
 *
 * A primeira leitura do benchmark culpou o Nominatim e concluiu "concorrência
 * 5 é o ótimo"; era artefato de medir com o INSERT no meio do laço. Com a
 * escrita fora do caminho, o Nominatim escala até conc=10 sem esforço.
 *
 * @param array $linhas Lista de [lat, lng, endereço]
 * @returns int Linhas gravadas
 */
function geocode_persist(array $linhas): int
{
    if (!$linhas) return 0;
    $db = Database::getInstance()->getConnection();

    // Não abre transação se já houver uma do chamador (ex.: pipeline do
    // webhook) — commitar aqui fecharia a transação dele pela metade.
    $proprio = !$db->inTransaction();
    $total = 0;

    try {
        if ($proprio) $db->beginTransaction();

        foreach (array_chunk($linhas, 200) as $lote) {
            $ph = implode(',', array_fill(0, count($lote), '(?,?,?)'));
            $args = [];
            foreach ($lote as $l) { $args[] = $l[0]; $args[] = $l[1]; $args[] = $l[2]; }
            $db->prepare(
                "INSERT INTO geocode_cache (lat, lng, address) VALUES $ph
                 ON DUPLICATE KEY UPDATE address = VALUES(address)"
            )->execute($args);
            $total += count($lote);
        }

        if ($proprio) $db->commit();
    } catch (Throwable $e) {
        if ($proprio && $db->inTransaction()) $db->rollBack();
        // Cache é otimização: falhar aqui não pode derrubar a página nem o worker
        return 0;
    }
    return $total;
}

/**
 * Consulta SOMENTE o cache — nenhuma chamada HTTP.
 *
 * É o caminho normal das telas: o `scripts/geocode_worker.php` mantém o cache
 * quente, então a página resolve tudo em UMA consulta ao banco.
 *
 * @param array $pontos Lista de [lat, lng]
 * @returns array Mapa chave → endereço
 */
function geocode_cache_lookup(array $pontos): array
{
    if (!$pontos) return [];
    $db = Database::getInstance()->getConnection();

    $conds = [];
    $params = [];
    $vistos = [];
    foreach ($pontos as $p) {
        $lat = round((float)$p[0], GEOCODE_PRECISION);
        $lng = round((float)$p[1], GEOCODE_PRECISION);
        if ($lat == 0.0 && $lng == 0.0) continue;
        $k = $lat . ',' . $lng;
        if (isset($vistos[$k])) continue;
        $vistos[$k] = true;
        $conds[]  = '(lat = ? AND lng = ?)';
        $params[] = $lat;
        $params[] = $lng;
    }
    if (!$conds) return [];

    $map = [];
    try {
        // Lotes de 500 pares: uma cláusula OR com milhares de termos estoura o
        // limite de placeholders e degrada o planner.
        foreach (array_chunk($conds, 500) as $i => $chunkConds) {
            $slice = array_slice($params, $i * 1000, count($chunkConds) * 2);
            $stmt = $db->prepare("SELECT lat, lng, address FROM geocode_cache WHERE "
                               . implode(' OR ', $chunkConds));
            $stmt->execute($slice);
            while ($row = $stmt->fetch()) {
                $map[round((float)$row['lat'], GEOCODE_PRECISION) . ','
                   . round((float)$row['lng'], GEOCODE_PRECISION)] = $row['address'];
            }
        }
    } catch (Throwable $e) { /* sem cache a tela mostra vazio, não quebra */ }
    return $map;
}

/**
 * Resolve endereços de uma lista de pontos: cache primeiro, depois busca em
 * paralelo o que faltar (até $tetoMiss pontos).
 *
 * É o que as telas e os exports usam. O teto existe para a página não ficar
 * refém do tamanho do relatório: sem ele, um relatório de 20 mil posições com
 * cache frio seguraria a requisição por ~2 min. O `geocode_worker.php` é quem
 * deve manter o cache quente; este preenchimento é a rede de segurança.
 *
 * @param array $pontos   Lista de [lat, lng]
 * @param int   $tetoMiss Máximo de consultas ao Nominatim nesta chamada
 * @returns array Mapa chave → endereço
 */
function geocode_resolve(array $pontos, int $tetoMiss = 200): array
{
    $map = geocode_cache_lookup($pontos);
    if ($tetoMiss <= 0) return $map;

    $faltam = [];
    $vistos = [];
    foreach ($pontos as $p) {
        $lat = round((float)$p[0], GEOCODE_PRECISION);
        $lng = round((float)$p[1], GEOCODE_PRECISION);
        if ($lat == 0.0 && $lng == 0.0) continue;
        $k = $lat . ',' . $lng;
        if (isset($map[$k]) || isset($vistos[$k])) continue;
        $vistos[$k] = true;
        $faltam[] = [$lat, $lng];
        if (count($faltam) >= $tetoMiss) break;
    }

    if ($faltam) {
        $map += geocode_fetch_many($faltam);
    }
    return $map;
}

/**
 * Endereço de um único ponto (cache primeiro).
 *
 * Mantida por compatibilidade com chamadores antigos. Para grade ou export use
 * `geocode_resolve()`: uma chamada por linha desperdiça o paralelismo.
 *
 * @param float $lat
 * @param float $lng
 * @returns string|null
 */
function reverse_geocode(float $lat, float $lng): ?string
{
    if ($lat == 0.0 && $lng == 0.0) return null;
    $map = geocode_resolve([[$lat, $lng]], 1);
    return $map[geocode_key($lat, $lng)] ?? null;
}

/**
 * Coluna `endereco` para consultas que resolvem o endereço no PRÓPRIO SQL.
 *
 * Usada junto com `geo_join()`. Existe para o caminho de STREAMING do
 * `scripts/worker.php`, onde as linhas são consumidas uma a uma (export
 * assíncrono chega a 100 mil linhas) e portanto **não cabe** `fetchAll()` +
 * resolução em lote em memória. Deixar o banco fazer o LEFT JOIN evita tanto o
 * estouro de memória quanto uma consulta por linha; o índice único
 * `uk_geocode_coords (lat,lng)` torna o join direto.
 */
const GEO_ADDR_SQL = 'gc.address AS endereco';

/**
 * LEFT JOIN no cache de endereços para uma coluna de coordenada.
 *
 * ⚠️ `$colLat`/`$colLng` entram por interpolação: são **identificadores de
 * coluna**, que o PDO não parametriza. Nunca passe valor vindo do usuário —
 * todos os chamadores usam literais escritos no código.
 *
 * @param string $colLat Ex.: 'a.latitude'
 * @param string $colLng Ex.: 'a.longitude'
 * @returns string Trecho SQL de JOIN
 */
function geo_join(string $colLat, string $colLng): string
{
    return "LEFT JOIN geocode_cache gc
                   ON gc.lat = ROUND($colLat, " . GEOCODE_PRECISION . ")
                  AND gc.lng = ROUND($colLng, " . GEOCODE_PRECISION . ")";
}

/**
 * Resolve os endereços de um conjunto de linhas já buscadas do banco.
 *
 * Existe para que cada chamador não repita o `array_map` de extração — e,
 * sobretudo, para forçar o padrão certo: **buscar todas as linhas primeiro,
 * resolver em UM lote, e só então montar a saída**. Resolver dentro do
 * `while ($r = $stmt->fetch())` faria uma chamada HTTP por linha e jogaria fora
 * o paralelismo (medido: 23,8 ms/ponto sequencial contra 2,2 ms em lote).
 *
 * @param array  $linhas  Linhas com colunas de coordenada
 * @param string $chaveLat
 * @param string $chaveLng
 * @param int    $teto    Máximo de consultas ao Nominatim (0 = só cache)
 * @returns array Mapa chave → endereço, para usar com geocode_cell()
 */
function geocode_map_rows(array $linhas, string $chaveLat = 'latitude',
                          string $chaveLng = 'longitude', int $teto = 400): array
{
    $pts = [];
    foreach ($linhas as $l) {
        $lat = $l[$chaveLat] ?? null;
        $lng = $l[$chaveLng] ?? null;
        if ($lat === null || $lng === null || (float)$lat == 0.0) continue;
        $pts[] = [(float)$lat, (float)$lng];
    }
    return $pts ? geocode_resolve($pts, $teto) : [];
}

/**
 * Açúcar para montar a célula de endereço numa grade.
 *
 * @param array  $map  Mapa devolvido por geocode_resolve()
 * @param mixed  $lat
 * @param mixed  $lng
 * @param string $vazio Texto quando não há endereço
 * @returns string
 */
function geocode_cell(array $map, $lat, $lng, string $vazio = '—'): string
{
    if (!$lat || (float)$lat == 0.0) return $vazio;
    return $map[geocode_key((float)$lat, (float)$lng)] ?? $vazio;
}
