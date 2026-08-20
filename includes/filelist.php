<?php
/**
 * bycamera — Leitura da lista de gravações do cartão (FILELIST, protocolo JIMI)
 *
 * FASE 1 do `/filelist`: transformar o corpo cru que a câmera sobe numa lista
 * utilizável em `resource_lists`, a mesma tabela que o JT/T alimenta pelo
 * `/pushresourcelist`. A partir daí a tela de playback trata os dois protocolos
 * pelo mesmo caminho.
 *
 * ── O FORMATO, MEDIDO (não suposto) ─────────────────────────────────────────
 * Corpo real da 400AD_3 (`864993060392306`, 20/08/2026, 78.590 bytes):
 *
 *     {"imei":"864993060392306","fileNameList":"2026_08_16_05_33_58_01.ts,…"}
 *
 * 3.021 nomes, todos `.ts`, sufixo `_01` (1.538) ou `_02` (1.483), **lista
 * terminada em vírgula** — o último elemento do `explode` vem vazio, e um
 * parser que não o descarte conta 3.022.
 *
 * 🔴 **O TIMESTAMP DO NOME É HORA LOCAL DA CÂMERA (UTC−3), NÃO GMT 0.** Esta é
 * a armadilha desta fase, e ela falharia em silêncio: a lista apareceria na
 * tela com datas plausíveis, três horas fora do lugar, e o operador baixaria o
 * minuto errado sem nada indicando o desvio. Medido em produção contra os
 * anexos de alarme das TRÊS câmeras JIMI, que carregam o mesmo carimbo no nome:
 *
 *     EVENT_…_2026_08_20_08_47_42_I_15.ts   →  alarms.alarm_time = 11:47:42 UTC
 *
 * 29 amostras, três equipamentos, offset +3 h em todas. A convenção "o device
 * transmite GMT 0" do CLAUDE.md vale para o CORPO do webhook; o nome que o
 * equipamento dá ao arquivo no cartão segue o relógio dele. O código já
 * dependia disso sem dizê-lo: `alarm_video_request.php` converte com
 * `CONVERT_TZ(alarm_time,'+00:00','-03:00')` antes de montar o `EVIDEO`.
 *
 * ── O BLOCO É DE UM MINUTO ──────────────────────────────────────────────────
 * Doc oficial (planilha JIMI V5.0.3, A010 — `HVIDEO`): "the playback video file
 * which store in memory (**which is one minute each file**)". Confere com a
 * medição: 1.442 dos 1.461 intervalos do canal 01 são de exatamente 60 s. Os
 * demais variam (14 s a 184 s) — a câmera corta o bloco ao trocar de estado.
 * Daí o `end_time` ser `min(início do bloco seguinte, início + 60 s)`: nunca
 * anuncia cobertura que o arquivo seguinte desminta, nem mais que o minuto que
 * a doc promete.
 *
 * ⚠️ A descrição do `EVIDEO` (A011) diz "3 mins for each video file". É outro
 * comando — ele GERA um trecho novo; o `HVIDEO` busca o bloco já gravado.
 *
 * ── O NOME É O ARGUMENTO DO HVIDEO ──────────────────────────────────────────
 * `HVIDEO,<Year_Month_Day_Hour_Minute_Second>,<1|2>` (A010) recebe exatamente o
 * prefixo do nome que veio na lista, e o `_01`/`_02` é o mesmo par `1=Front
 * camera; 2=Inward camera` do parâmetro B — a numeração que a tela de vídeo ao
 * vivo já mediu (CH1=OUT/frontal, CH2=IN/cabine). Pedir uma gravação é
 * devolver ao equipamento a string que ele mesmo nos deu, sem conversão pelo
 * meio: ver `filelist_hvideo_command()`.
 */

require_once __DIR__ . '/../config/database.php';

/**
 * Deslocamento do relógio da câmera em relação ao UTC, em segundos.
 *
 * Fixo, e não `America/Sao_Paulo`, porque o equipamento guarda um OFFSET (é o
 * que o comando `GMT` grava nele) e não uma regra de fuso — ele não conhece
 * horário de verão. Se um dia houver câmera configurada em outro fuso, é aqui
 * que se resolve, e o sintoma será a lista inteira deslocada.
 */
const FILELIST_OFFSET_SEGUNDOS = 3 * 3600;   // UTC−3

/** Duração nominal do bloco gravado no cartão (doc A010 + medição). */
const FILELIST_BLOCO_SEGUNDOS = 60;

/** Teto de canais aceito num nome — a linha JC400 tem 2. */
const FILELIST_MAX_CANAL = 16;

/**
 * Teto de nomes lidos de uma lista.
 *
 * O endpoint é aberto (a câmera não tem como autenticar) e aceita até 8 MB de
 * corpo — 8 MB de vírgulas virariam um array de milhões de elementos antes de
 * qualquer validação. 50.000 blocos de um minuto são ~17 dias de gravação
 * contínua num canal; o cartão real medido guarda 4 dias e 3.021 nomes, então
 * o teto é 16× a maior lista que já vimos e ainda impede o array patológico.
 */
const FILELIST_MAX_NOMES = 50000;

/**
 * Separa o corpo recebido em IMEI + nomes, sem interpretar os nomes.
 *
 * Aceita o JSON medido e, por tolerância, texto cru separado por vírgula ou
 * quebra de linha: o `FILELIST` foi documentado como "TXT" por muito tempo, e
 * um firmware que ainda o mande assim não pode derrubar a leitura.
 *
 * @param string $corpo Corpo cru da requisição
 * @returns array{imei:?string, nomes:string[], formato:string}
 */
function filelist_decodificar(string $corpo): array
{
    $corpo = trim($corpo);
    if ($corpo === '') {
        return ['imei' => null, 'nomes' => [], 'formato' => 'vazio'];
    }

    $json = json_decode($corpo, true);
    if (is_array($json)) {
        // Chaves toleradas por variação de caixa/nome entre firmwares.
        $baixa = array_change_key_case($json, CASE_LOWER);
        $lista = $baixa['filenamelist'] ?? $baixa['filelist'] ?? $baixa['namelist'] ?? null;
        $imei  = $baixa['imei'] ?? $baixa['deviceimei'] ?? null;

        if (is_array($lista)) {
            return ['imei' => $imei, 'nomes' => array_map('strval', $lista), 'formato' => 'json_array'];
        }
        if (is_string($lista)) {
            return ['imei' => $imei, 'nomes' => filelist_quebrar($lista), 'formato' => 'json'];
        }
        // JSON válido sem a chave da lista: não é lista de arquivos.
        return ['imei' => $imei, 'nomes' => [], 'formato' => 'json_sem_lista'];
    }

    return ['imei' => null, 'nomes' => filelist_quebrar($corpo), 'formato' => 'texto'];
}

/**
 * Quebra a string de nomes por vírgula, ponto-e-vírgula ou quebra de linha.
 *
 * O `limit` do `preg_split` é a guarda de memória: sem ele um corpo patológico
 * viraria o array antes de qualquer validação. Com ele o excedente fica todo no
 * último elemento — que não casa com nome nenhum e é descartado como inválido,
 * deixando rastro no log em vez de derrubar o processo.
 *
 * @param string $s Lista em string única
 * @returns string[] Nomes já sem espaços nas pontas; vazios preservados para
 *                   que o chamador possa contá-los (a lista real termina em
 *                   vírgula, e esse vazio NÃO é erro de formato)
 */
function filelist_quebrar(string $s): array
{
    $partes = preg_split('/[,;\r\n]+/', $s, FILELIST_MAX_NOMES + 1) ?: [];
    return array_map('trim', $partes);
}

/**
 * Interpreta um nome de gravação do cartão.
 *
 * `AAAA_MM_DD_HH_MM_SS_<canal>.<ext>` — o carimbo é a hora LOCAL da câmera.
 *
 * @param string $nome Nome do arquivo, com ou sem caminho
 * @returns array{file_name:string, channel:int, device_ts:string, epoch:int}|null
 *          null quando o nome não segue o padrão (inclusive o vazio final)
 */
function filelist_ler_nome(string $nome): ?array
{
    $nome = basename(trim($nome));
    if ($nome === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})_(\d{2})_(\d{2})_(\d{2})_(\d{2})_(\d{2})_(\d{1,2})\.([A-Za-z0-9]{1,5})$/', $nome, $m)) {
        return null;
    }

    [, $ano, $mes, $dia, $hora, $min, $seg, $canal] = $m;

    // Faixas conferidas explicitamente: `gmmktime` normaliza silenciosamente
    // (hora 24 vira 00 do dia seguinte), e um nome corrompido viraria data
    // plausível. A própria doc traz `2020_01_01_24_05_06` como exemplo — hora
    // 24 não existe, e aceitá-la seria inventar o dia seguinte.
    if (!checkdate((int)$mes, (int)$dia, (int)$ano)) return null;
    if ((int)$hora > 23 || (int)$min > 59 || (int)$seg > 59) return null;

    $canal = (int)$canal;
    if ($canal < 1 || $canal > FILELIST_MAX_CANAL) return null;

    return [
        'file_name' => $nome,
        'channel'   => $canal,
        'device_ts' => "{$ano}_{$mes}_{$dia}_{$hora}_{$min}_{$seg}",
        'epoch'     => gmmktime((int)$hora, (int)$min, (int)$seg, (int)$mes, (int)$dia, (int)$ano)
                       + FILELIST_OFFSET_SEGUNDOS,
    ];
}

/**
 * Instante UTC embutido no nome de um arquivo do equipamento.
 *
 * Serve tanto para os nomes da lista (`AAAA_MM_DD_…_01.ts`) quanto para os
 * anexos de evento (`EVENT_<imei>_<seq>_AAAA_MM_DD_HH_MM_SS_I_15.ts`), que
 * usam o mesmo carimbo local. É o que permite colocar um arquivo extraído no
 * ponto certo da linha do tempo: `media_files.event_time` de um `HVIDEO` é a
 * hora em que o UPLOAD terminou, não a hora do que está gravado.
 *
 * @param string $nome Nome do arquivo
 * @returns string|null `Y-m-d H:i:s` em UTC, ou null quando não há carimbo
 */
function filelist_ts_do_nome_utc(string $nome): ?string
{
    $nome = basename(trim($nome));
    if (!preg_match('/(?:^|_)(\d{4})_(\d{2})_(\d{2})_(\d{2})_(\d{2})_(\d{2})(?:_|\.)/', $nome, $m)) {
        return null;
    }
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    if ((int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59) return null;

    $epoch = gmmktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1])
           + FILELIST_OFFSET_SEGUNDOS;
    return gmdate('Y-m-d H:i:s', $epoch);
}

/**
 * Comando que traz UMA gravação da lista para o servidor.
 *
 * `HVIDEO,<carimbo>,<câmera>` — o carimbo volta ao equipamento na forma em que
 * ele o mandou, sem conversão de fuso pelo caminho. É o mesmo comando que o
 * reenvio de vídeo de alarme usa em produção desde a v4.9.31.
 *
 * @param string $fileName Nome vindo da lista
 * @returns string|null null quando o nome não é do padrão ou o canal não é
 *                      1/2 — o parâmetro B só admite `1=Front`/`2=Inward`, e
 *                      chutar um terceiro valor é pedir recusa silenciosa
 */
function filelist_hvideo_command(string $fileName): ?string
{
    $e = filelist_ler_nome($fileName);
    if ($e === null || $e['channel'] < 1 || $e['channel'] > 2) {
        return null;
    }
    return 'HVIDEO,' . $e['device_ts'] . ',' . $e['channel'];
}

/**
 * Corpo cru → entradas prontas para `resource_lists`.
 *
 * @param string $corpo Corpo da requisição
 * @returns array{imei:?string, formato:string, entradas:array<int,array{file_name:string,
 *          channel:int, start_utc:string, end_utc:string}>, total_nomes:int,
 *          validos:int, invalidos:string[], vazios:int}
 */
function filelist_parse(string $corpo): array
{
    $dec   = filelist_decodificar($corpo);
    $vazios = 0;
    $invalidos = [];
    $porCanal = [];

    foreach ($dec['nomes'] as $nome) {
        if (trim($nome) === '') { $vazios++; continue; }
        $e = filelist_ler_nome($nome);
        if ($e === null) {
            // Guarda só uma amostra: uma lista corrompida inteira no log não
            // diagnostica melhor que os primeiros dez nomes, e enche o disco.
            if (count($invalidos) < 10) $invalidos[] = $nome;
            continue;
        }
        // Mapa por canal→epoch: nome repetido na mesma lista (visto quando a
        // câmera reenvia) colapsa em vez de virar duas linhas.
        $porCanal[$e['channel']][$e['epoch']] = $e['file_name'];
    }

    $entradas = [];
    foreach ($porCanal as $canal => $mapa) {
        ksort($mapa);                       // a lista NÃO chega ordenada
        $epochs = array_keys($mapa);
        foreach ($epochs as $i => $ep) {
            $fim = $ep + FILELIST_BLOCO_SEGUNDOS;
            $prox = $epochs[$i + 1] ?? null;
            if ($prox !== null && $prox < $fim) {
                $fim = $prox;               // bloco cortado antes do minuto
            }
            $entradas[] = [
                'file_name' => $mapa[$ep],
                'channel'   => $canal,
                'start_utc' => gmdate('Y-m-d H:i:s', $ep),
                'end_utc'   => gmdate('Y-m-d H:i:s', $fim),
            ];
        }
    }

    usort($entradas, fn($a, $b) => strcmp($a['start_utc'], $b['start_utc'])
                                   ?: ($a['channel'] <=> $b['channel']));

    return [
        'imei'        => $dec['imei'],
        'formato'     => $dec['formato'],
        'entradas'    => $entradas,
        'total_nomes' => count($dec['nomes']) - $vazios,
        'validos'     => count($entradas),
        'invalidos'   => $invalidos,
        'vazios'      => $vazios,
    ];
}

/**
 * Grava a listagem em `resource_lists`.
 *
 * ⚠️ `captured_at` é UM instante para a lista inteira, como no
 * `/pushresourcelist`: é ele que dá VALIDADE à listagem (v4.9.17, cartão é
 * buffer circular). Se cada linha usasse `NOW()`, uma lista de 3.000 arquivos
 * ficaria espalhada por segundos e a idade dependeria de qual linha se olhasse.
 *
 * O `ON DUPLICATE KEY UPDATE` é o que torna a operação idempotente — e ela
 * PRECISA ser: a câmera sobe a mesma lista duas vezes por disparo (medido:
 * dois POSTs idênticos de 78.590 bytes com 5 s de intervalo).
 *
 * @param PDO         $db        Conexão ativa
 * @param string      $imei      Equipamento dono da lista
 * @param array       $entradas  Saída de `filelist_parse()['entradas']`
 * @param string|null $capturaEm Instante da captura (UTC); default: agora
 * @returns array{gravados:int, erros:int, captura:string}
 * @throws PDOException quando a transação não pode sequer começar
 */
function filelist_persistir(PDO $db, string $imei, array $entradas, ?string $capturaEm = null): array
{
    $capturaEm = $capturaEm ?: gmdate('Y-m-d H:i:s');
    if (empty($entradas)) {
        return ['gravados' => 0, 'erros' => 0, 'captura' => $capturaEm];
    }

    // Sem `VALUES()` nem alias de linha (as duas têm ressalva de versão no
    // MySQL 8): o placeholder repetido funciona em qualquer versão.
    $sql = "INSERT INTO resource_lists
                (imei, resource_type, file_name, file_size,
                 start_time, end_time, channel_id, alarm_type,
                 created_at, captured_at)
            VALUES
                (:imei, 'video', :fname, 0,
                 :start, :end, :chan, NULL,
                 NOW(), :captured)
            ON DUPLICATE KEY UPDATE
                captured_at = :captured2,
                start_time  = :start2,
                end_time    = :end2";

    $gravados = 0;
    $erros    = 0;

    $proprio = !$db->inTransaction();
    if ($proprio) $db->beginTransaction();

    try {
        $stmt = $db->prepare($sql);
        foreach ($entradas as $e) {
            try {
                $stmt->execute([
                    ':imei'      => $imei,
                    ':fname'     => $e['file_name'],
                    ':start'     => $e['start_utc'],
                    ':end'       => $e['end_utc'],
                    ':chan'      => $e['channel'],
                    ':captured'  => $capturaEm,
                    ':captured2' => $capturaEm,
                    ':start2'    => $e['start_utc'],
                    ':end2'      => $e['end_utc'],
                ]);
                $gravados++;
            } catch (PDOException $ex) {
                $erros++;
                if ($erros <= 3 && class_exists('Logger')) {
                    Logger::error('filelist: falha ao gravar entrada', [
                        'imei' => $imei, 'file' => $e['file_name'], 'erro' => $ex->getMessage(),
                    ]);
                }
            }
        }
        if ($proprio) $db->commit();
    } catch (Throwable $t) {
        if ($proprio && $db->inTransaction()) $db->rollBack();
        throw $t;
    }

    return ['gravados' => $gravados, 'erros' => $erros, 'captura' => $capturaEm];
}
