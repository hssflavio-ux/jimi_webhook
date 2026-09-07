<?php
/**
 * JIMI Webhook System — Helpers de mídia v4.9.8
 *
 * Ponto único para responder três perguntas que a tela de alarmes, o detalhe
 * da ocorrência e o playback fazem sobre o mesmo arquivo:
 *
 *   1. por qual URL ele é TOCÁVEL?   → media_play_url()
 *   2. que tipo de mídia ele é?      → media_kind() / media_is_ts()
 *   3. ele está mesmo no servidor?   → media_available()
 *
 * A resposta de (1) é sempre `/midia?f=` — a NOSSA origem, com login e escopo
 * de cliente. A porta 23010 do IoTHub serve os mesmos bytes e não serve para
 * tocar: sem CORS, sem `Accept-Ranges` e com `Content-Disposition: attachment`
 * (o porquê completo está em handlers/midia.php). Antes desta v4.9.8 o detalhe
 * da ocorrência montava a URL para a 23010 na mão, e por isso o player abria
 * preto mesmo com o arquivo íntegro no disco.
 */

require_once __DIR__ . '/functions.php';   // detect_media_type()
require_once __DIR__ . '/filelist.php';    // filelist_ts_do_nome_utc()

/**
 * Diretório onde o FTP da câmera e o attachment server depositam os arquivos.
 * O mesmo caminho que `handlers/midia.php` lê e que o container `dvr-upload`
 * publica.
 *
 * @returns string Caminho absoluto, sem barra final
 */
function media_base_dir(): string
{
    return rtrim((string)(getenv('VIDEO_MEDIA_DIR') ?: '/iothub/dvr-upload/uploadFile'), '/');
}

/**
 * Os nomes de arquivo de um `file_url` — que pode trazer MAIS DE UM.
 *
 * 🔴 A câmera JIMI anuncia as duas câmeras num campo só, separadas por vírgula:
 * `EVENT_..._I_56.mp4,EVENT_..._F_55.mp4` (I = interna, F = frontal). São 25 dos
 * 106 alarmes com vídeo em produção (18/08/2026). `basename()` sobre a string
 * inteira devolve `..._I_56.mp4,EVENT_..._F_55.mp4`, que não casa com arquivo
 * nenhum — então o par nunca seria encontrado no disco mesmo estando lá.
 *
 * @param string|null $fileUrl Conteúdo cru da coluna
 * @returns string[] Nomes individuais, já sem espaços e sem vazios
 */
function media_file_list(?string $fileUrl): array
{
    $parts = array_map('trim', explode(',', (string)$fileUrl));
    return array_values(array_filter($parts, static fn($p) => $p !== ''));
}

/**
 * O arquivo a usar: o PRIMEIRO que existe no disco; se nenhum existe (ou não dá
 * para conferir), o primeiro da lista.
 *
 * @param string|null $fileUrl Conteúdo cru da coluna
 * @returns string Nome único, ou '' quando não há nada
 */
function media_pick(?string $fileUrl): string
{
    $lista = media_file_list($fileUrl);
    if (!$lista) {
        return '';
    }
    $dir = media_base_dir();
    if (is_dir($dir)) {
        foreach ($lista as $f) {
            if (preg_match('#^https?://#i', $f) || is_file($dir . '/' . basename($f))) {
                return $f;
            }
        }
    }
    return $lista[0];
}

/**
 * URL pela qual o arquivo pode ser TOCADO na página.
 *
 * `media_files.file_url` e `alarms.file_url` guardam só o nome do arquivo
 * (`EVENT_8627..._I_15.mp4`); firmwares antigos podem mandar URL absoluta, e
 * essa passa direto — não há o que servir por aqui.
 *
 * @param string|null $fileUrl Nome do arquivo (ou URL absoluta)
 * @returns string URL tocável, ou '' quando não há arquivo
 */
function media_play_url(?string $fileUrl): string
{
    $f = media_pick($fileUrl);
    if ($f === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $f)) {
        return $f;
    }
    return '/midia?f=' . rawurlencode(basename($f));
}

/**
 * Tipo de mídia normalizado ('video', 'image', 'audio' ou 'other').
 *
 * A EXTENSÃO vence o valor gravado. `alarms.file_type` é preenchido na chegada
 * do webhook por um regex que não conhecia `.ts` — todo anexo MPEG-TS de alarme
 * ficou com a coluna NULL (dois dos quatro anexos do homolog). Resolver pela
 * extensão aqui faz a tela acertar mesmo nas linhas já gravadas erradas.
 *
 * @param string|null $fileUrl  Nome do arquivo
 * @param string|null $fileType Tipo gravado no banco (usado só como reserva)
 * @returns string
 */
function media_kind(?string $fileUrl, ?string $fileType = null): string
{
    $f = trim((string)$fileUrl);
    if ($f !== '') {
        $kind = detect_media_type($f);
        if ($kind !== 'other') {
            return $kind;
        }
    }
    $t = strtolower(trim((string)$fileType));
    if (in_array($t, ['video', 'mp4', 'flv', 'ts', 'avi'], true))  return 'video';
    if (in_array($t, ['image', 'jpg', 'jpeg', 'png'], true))       return 'image';
    if ($t === 'audio')                                            return 'audio';
    return 'other';
}

/**
 * MPEG-TS? É o formato que a câmera grava, e NENHUM navegador o toca no
 * `<video>` nativo — precisa do mpegts.js, que remuxa TS→fMP4 em JavaScript.
 *
 * @param string|null $fileUrl Nome do arquivo
 * @returns bool
 */
function media_is_ts(?string $fileUrl): bool
{
    return (bool)preg_match('/\.ts(\?|$)/i', trim((string)$fileUrl));
}

/**
 * O arquivo está no disco do servidor?
 *
 * Devolve `true` quando NÃO dá para afirmar o contrário — ou seja, quando o
 * diretório de mídia não existe (máquina de desenvolvimento, container sem o
 * volume montado). A alternativa seria esconder o player em todo ambiente que
 * não é o servidor de produção, trocando um erro visível ("não carregou") por
 * um silêncio ("não há vídeo"), que é justamente o defeito que esta versão
 * conserta.
 *
 * @param string|null $fileUrl Nome do arquivo
 * @returns bool
 */
function media_available(?string $fileUrl): bool
{
    $lista = media_file_list($fileUrl);
    if (!$lista) {
        return false;
    }
    $dir = media_base_dir();
    if (!is_dir($dir)) {
        return true;   // sem como conferir — ver o bloco acima
    }
    foreach ($lista as $f) {
        if (preg_match('#^https?://#i', $f)) {
            return true;   // origem externa: não há disco local para conferir
        }
        if (is_file($dir . '/' . basename($f))) {
            return true;
        }
    }
    return false;
}

/**
 * Canal que o NOME do arquivo declara: `_F_` = frontal (1), `_I_` = interna (2).
 *
 * É a mesma convenção que o reenvio de vídeo de alarme usa para escolher a
 * câmera no `EVIDEO`/`HVIDEO`.
 *
 * @param string $fileUrl Nome do arquivo
 * @returns int|null 1, 2, ou null quando o nome não declara
 */
function media_canal_do_nome(string $fileUrl): ?int
{
    if (!preg_match('/_([FI])_/i', basename($fileUrl), $m)) return null;
    return strtoupper($m[1]) === 'F' ? 1 : 2;
}

/**
 * Qual pedido pendente este arquivo está fechando — se algum.
 *
 * 🔴 A DECISÃO É ESTREITA DE PROPÓSITO, e a razão é a mesma da v4.9.31: o DMS
 * dispara várias vezes no mesmo minuto, e o anexo de um alarme comum que caísse
 * na janela ROUBARIA o pedido de um trecho que o usuário pediu — a fila
 * mostraria "pronto" apontando para o vídeo errado, e o pedido de verdade
 * ficaria pendente para sempre. Por isso casa por instante E por canal.
 *
 * Pura de propósito: é a parte que erra silencioso, então é a parte que precisa
 * de teste sem banco.
 *
 * @param array  $pendentes Linhas `solicitado` do MESMO imei: cada uma com
 *                          `id`, `event_time` (UTC) e `channel`
 * @param string $fileUrl   Nome do arquivo que chegou
 * @param int    $janela    Tolerância em segundos
 * @returns array|null A linha escolhida, ou null quando nenhuma corresponde
 */
function media_pedido_correspondente(array $pendentes, string $fileUrl, int $janela = 90): ?array
{
    $instante = filelist_ts_do_nome_utc(basename($fileUrl));
    if ($instante === null) return null;          // sem carimbo não há como casar
    $t = strtotime($instante . ' UTC');
    $canal = media_canal_do_nome($fileUrl);

    $melhor = null; $menor = PHP_INT_MAX;
    foreach ($pendentes as $p) {
        if (empty($p['event_time'])) continue;
        $pt = strtotime($p['event_time'] . ' UTC');
        if ($pt === false) continue;
        $dist = abs($t - $pt);
        if ($dist > $janela) continue;
        // Canal declarado dos dois lados e diferente = não é este pedido.
        $pc = isset($p['channel']) && $p['channel'] !== null ? (int)$p['channel'] : null;
        if ($canal !== null && $pc !== null && $canal !== $pc) continue;
        if ($dist < $menor) { $menor = $dist; $melhor = $p; }
    }
    return $melhor;
}

/**
 * Canal de um anexo `VIDEOUPLOAD` (JT/T): `{imei}_{alarmLabel hex}_{canal}_{seq}.ext`
 * — o MESMO regex que `pushfileupload.php` usa para extrair o alarmLabel do
 * nome (26/08/2026). Âncora no IMEI (15-17 dígitos) + hex longo de propósito:
 * o nome de gravação crua do cartão (`AAAA_MM_DD_HH_MM_SS_canal.ext`,
 * `filelist.php`) também termina em dois grupos numéricos separados por `_`
 * (segundos e canal) e bateria num regex genérico — começa com ANO, não IMEI,
 * então não colide com esta âncora. Por isso esta função é separada de
 * `media_canal_do_nome()` (que resolve `_F_`/`_I_`, convenção JIMI) em vez de
 * estendida — misturar as duas arriscava a leitura errada MERGE quando o
 * `HVIDEO` reenvia o nome cru do cartão.
 *
 * @param string $fileUrl Nome do arquivo
 * @returns int|null Canal declarado, ou null quando o nome não é deste formato
 */
function media_canal_jtt_upload(string $fileUrl): ?int
{
    if (!preg_match('/^\d{15,17}_[0-9A-Fa-f]{16,40}_(\d+)_\d+\.[A-Za-z0-9]+$/', basename($fileUrl), $m)) {
        return null;
    }
    return (int)$m[1];
}

/**
 * Agrupa os nomes de um `file_url` por tipo e canal.
 *
 * Um alarme JT/T pedido com `VIDEOUPLOAD,...,1_2,2` (vídeo+foto, dois canais)
 * chega como até 4 arquivos no MESMO campo (vírgula-separados, convenção já
 * usada pela JIMI — ver `media_file_list()`): vídeo canal 1, vídeo canal 2,
 * foto canal 1, foto canal 2. Esta função é o ponto único que separa isso para
 * quem precisa dos DOIS vídeos ao mesmo tempo (2 players) ou só da foto do
 * canal 2 (miniatura).
 *
 * @param string|null $fileUrl Conteúdo cru da coluna
 * @returns array{video: array<int,string>, image: array<int,string>} Canal => nome
 */
function media_channel_files(?string $fileUrl): array
{
    $out = ['video' => [], 'image' => []];
    foreach (media_file_list($fileUrl) as $f) {
        $kind = media_kind($f);
        if (!isset($out[$kind])) continue;
        $canal = media_canal_do_nome($f) ?? media_canal_jtt_upload($f);
        if ($canal === null) continue;
        if (!isset($out[$kind][$canal])) $out[$kind][$canal] = $f;
    }
    return $out;
}

/**
 * Canais de VÍDEO deste alarme que já estão de fato no disco (não só
 * anunciados no nome).
 *
 * @param string|null $fileUrl Conteúdo cru da coluna
 * @returns int[] Canais confirmados, ordenados
 */
function media_video_channels_no_disco(?string $fileUrl): array
{
    $dir = media_base_dir();
    $semConferir = !is_dir($dir);   // mesma convenção de media_available(): sem
                                     // como checar, assume presente
    $out = [];
    foreach (media_channel_files($fileUrl)['video'] as $canal => $f) {
        if ($semConferir || preg_match('#^https?://#i', $f) || is_file($dir . '/' . basename($f))) {
            $out[] = $canal;
        }
    }
    sort($out);
    return $out;
}

/**
 * Este alarme já tem PELO MENOS UM vídeo confirmado no disco?
 *
 * 🔴 Diferente de `media_available()`, que conta QUALQUER arquivo (inclusive
 * foto). Desde que `VIDEOUPLOAD` passou a pedir foto+vídeo juntos (mediaType 2,
 * 26/08/2026), um alarme pode ter só as fotos no disco enquanto o vídeo ainda
 * não chegou — `media_available()` diria "tem", e quem lê essa resposta como
 * "já tem o vídeo" (era o caso de `request_alarm_video()`) bloquearia um
 * reenvio que ainda faz sentido.
 *
 * @param string|null $fileUrl Conteúdo cru da coluna
 * @returns bool
 */
function media_has_video(?string $fileUrl): bool
{
    return !empty(media_video_channels_no_disco($fileUrl));
}

/**
 * Este alarme tem vídeo confirmado em TODOS os canais esperados (1..N)?
 *
 * Convenção do produto (26/08/2026): trabalhar sempre com os canais 1 e 2 —
 * `$canaisEsperados = 2` é o default para todo device com 2+ câmeras.
 *
 * @param string|null $fileUrl        Conteúdo cru da coluna
 * @param int         $canaisEsperados Quantos canais, a partir de 1, contam como completo
 * @returns bool
 */
function media_video_complete(?string $fileUrl, int $canaisEsperados = 2): bool
{
    $presentes = media_video_channels_no_disco($fileUrl);
    for ($c = 1; $c <= $canaisEsperados; $c++) {
        if (!in_array($c, $presentes, true)) return false;
    }
    return true;
}

/**
 * Garante que existe linha em `media_files` para um arquivo anunciado por um
 * alarme, e devolve o id.
 *
 * 🔴 POR QUE ISTO EXISTE (v4.9.35). Até aqui o ÚNICO caminho que registrava
 * anexo de alarme era `link_media_to_occurrence()`, dentro do motor de
 * ocorrências — ou seja, **o arquivo só ficava visível se o alarme gerasse
 * ocorrência**. Quem não gera some: evento de diagnóstico (`105 — Upload de
 * Vídeo Concluído`, que é justamente o que anuncia vídeo EXTRAÍDO a pedido) e
 * alarme sem parâmetro em `occurrence_config_params`. Medido em produção em
 * 20/08/2026: **7 dos 12** eventos `105` das últimas 48 h tinham o arquivo no
 * disco e NENHUMA linha em `media_files` — invisíveis para o playback, para a
 * fila de downloads e para a galeria, com o vídeo íntegro no servidor.
 *
 * O sintoma pelo lado do usuário é o de sempre neste projeto: o `[Extrair]` do
 * playback diz "Solicitado", a câmera sobe o arquivo, e o item continua "No
 * cartão" para sempre.
 *
 * Idempotente por `file_url`: chamar de novo devolve o id existente. É por isso
 * que ela pode ser chamada tanto no caminho do alarme quanto no do motor de
 * ocorrências sem risco de duplicar.
 *
 * @param PDO         $db        Conexão ativa
 * @param string      $imei      Equipamento
 * @param string      $fileUrl   Nome do arquivo como o device o anunciou
 * @param string|null $eventTime Instante a gravar (UTC); default: agora
 * @param string      $origem    `media_files.source_type`
 * @returns int|null id da linha, ou null quando não há arquivo ou o INSERT falha
 */
function media_register_file(PDO $db, string $imei, string $fileUrl, ?string $eventTime = null,
                             string $origem = 'pushalarm'): ?int
{
    $fileUrl = trim($fileUrl);
    if ($fileUrl === '') {
        return null;
    }

    $st = $db->prepare("SELECT id FROM media_files WHERE imei = :i AND file_url = :u LIMIT 1");
    $st->execute([':i' => $imei, ':u' => $fileUrl]);
    $id = $st->fetchColumn();
    if ($id) {
        return (int)$id;
    }

    // ── Fecha o pedido que estava esperando por ESTE arquivo (v4.9.39) ──────
    //
    // Quando o usuário pede um trecho (`HVIDEO`/`EVIDEO` ou o 37382 do JT/T), o
    // despacho grava uma linha `solicitado` sem nome — o nome só existe quando
    // a câmera termina de subir. Chegando o arquivo, essa linha é PROMOVIDA em
    // vez de nascer uma segunda: assim a fila mostra um pedido só, do "aguardando
    // câmera" ao "pronto", e não um par de linhas fantasma.
    //
    // 🔴 A janela é apertada e o CANAL entra na conta, pela mesma razão da
    // v4.9.31: o DMS dispara várias vezes no mesmo minuto, e um anexo de alarme
    // que caísse solto na janela roubaria o pedido de outro trecho. O carimbo do
    // nome é o instante da gravação — o mesmo que foi pedido (medido: o `HVIDEO`
    // devolve exatamente o carimbo solicitado).
    // A ESCOLHA é de `media_pedido_correspondente()` — pura e testada sem banco.
    // Aqui só se busca o conjunto de candidatos.
    if (filelist_ts_do_nome_utc(basename($fileUrl)) !== null) {
        $st = $db->prepare(
            "SELECT id, event_time, channel FROM media_files
              WHERE imei = :i AND download_status = 'solicitado' AND event_time IS NOT NULL
              ORDER BY id DESC LIMIT 20"
        );
        $st->execute([':i' => $imei]);
        $escolhido = media_pedido_correspondente($st->fetchAll(PDO::FETCH_ASSOC), $fileUrl);
        if ($escolhido) {
            $pendente = $escolhido['id'];
            $db->prepare(
                "UPDATE media_files
                    SET file_name = :n, file_url = :u, file_type = :t,
                        download_status = 'disponivel'
                  WHERE id = :id"
            )->execute([
                ':n'  => basename($fileUrl),
                ':u'  => $fileUrl,
                ':t'  => detect_media_type($fileUrl),
                ':id' => (int)$pendente,
            ]);
            if (class_exists('Logger')) {
                Logger::info('media: pedido pendente fechado pelo arquivo que chegou', [
                    'imei' => $imei, 'media_id' => (int)$pendente, 'file' => $fileUrl,
                ]);
            }
            return (int)$pendente;
        }
    }

    try {
        // Snapshot do dono no momento do evento (Fase 2 do fluxo
        // chip→câmera→veículo) — ver resolve_installation_for_imei().
        $ownership = resolve_installation_for_imei($db, $imei);

        // `download_status` nasce 'disponivel' porque o device só anuncia o
        // arquivo depois de subi-lo; e o tipo sai da EXTENSÃO, não de um campo
        // do alarme — `file_type` é ENUM e não aceita palpite (v4.9.8).
        $ins = $db->prepare(
            "INSERT INTO media_files
                (imei, customer_id, vehicle_id, file_name, file_type, file_size, file_url, source_type,
                 event_time, channel, download_status)
             VALUES (:i, :cid, :vid, :n, :t, 0, :u, :s, :e, :c, 'disponivel')"
        );
        $ins->execute([
            ':i' => $imei,
            ':cid' => $ownership['customer_id'],
            ':vid' => $ownership['vehicle_id'],
            ':n' => basename($fileUrl),
            ':t' => detect_media_type($fileUrl),
            ':u' => $fileUrl,
            ':s' => $origem,
            ':e' => $eventTime ?: gmdate('Y-m-d H:i:s'),
            // 🔴 O CANAL VINHA SEMPRE NULL (corrigido na v4.9.39), e a coluna
            // "Canal" da tela de downloads aparecia vazia em TODA linha de
            // anexo de alarme — que é a maioria delas. O dado sempre esteve ali,
            // no nome: `_F_` é a frontal, `_I_` a interna.
            ':c' => media_canal_do_nome($fileUrl),
        ]);
        return (int)$db->lastInsertId() ?: null;
    } catch (Throwable $e) {
        // Nunca derrubar o webhook por causa da mídia: sem a linha o alarme
        // ainda é gravado, só o arquivo fica invisível — que é o estado antigo.
        if (class_exists('Logger')) {
            Logger::error('media_register_file: falha ao registrar anexo', [
                'imei' => $imei, 'file' => $fileUrl, 'erro' => $e->getMessage(),
            ]);
        }
        return null;
    }
}

/**
 * O arquivo é REPRODUZÍVEL no player da tela de playback?
 *
 * 🔴 Foto NÃO entra na tela de playback (decisão do dono do produto,
 * 07/09/2026: *"não vamos exibir fotos no sistema nesse momento, não trate
 * nenhuma ação para esses arquivos"*). Cada alarme sobe `.mp4` **e** `.jpg`
 * com instantes a segundos de distância — sem este corte a foto disputa o
 * bloco com o vídeo, pinta o trecho de verde e ganha uma ação que não deve
 * existir.
 *
 * A regra olha o TIPO e a EXTENSÃO: `file_type` é preenchido por
 * `detect_media_type()` na chegada do webhook e nem toda origem histórica o
 * gravou, então o nome é a segunda opinião — nunca a única, porque um
 * firmware que mande extensão desconhecida não pode virar "vídeo" por omissão.
 *
 * @param string|null $fileUrl  Nome do arquivo (já escolhido por media_pick())
 * @param string|null $fileType Valor de `media_files.file_type`
 * @returns bool true quando o arquivo é vídeo tocável na página
 */
function media_pb_reproduzivel(?string $fileUrl, ?string $fileType): bool
{
    if ((string)$fileType === 'video') {
        return true;
    }
    return (bool)preg_match('/\.(ts|mp4|flv|m4v)(\?|$)/i', (string)$fileUrl);
}

/**
 * O ALARME a que cada arquivo se refere.
 *
 * A fila de downloads é, na prática, uma fila de anexos de alarme — medido em
 * produção: **2.999 de 3.000** arquivos dos últimos 30 dias têm alarme
 * identificável, e o único que não tinha era uma extração manual do playback
 * (`ext20260831122209f7c617`, origem `pushftpfileupload`). Sem esta resolução a
 * tela lista o arquivo sem dizer POR QUE ele existe.
 *
 * 🔴 SÃO DOIS CAMINHOS, e nenhum deles é "o nome parecido com".
 *
 *   1. **O nome CARREGA o `alarm_label`** quando a câmera é JT/T:
 *      `<imei>_<alarmLabel>_<canal>_NN.mp4`. É o mesmo rótulo que
 *      `link_upload_by_alarm_label()` usa para casar o upload com o alarme, e
 *      `alarms.alarm_label` é indexado (`idx_alarm_label`) — um `IN()` resolve
 *      a página inteira. Medido: 75 de 75 rótulos casaram, em 6 ms.
 *   2. **A JIMI não põe rótulo no nome** (`EVENT_<imei>_..._I_40.mp4`) — lá
 *      quem guarda o vínculo é `alarms.file_url`, escrito pelo próprio
 *      `pushalarm`. Uma consulta por JANELA de tempo traz esses alarmes e o
 *      casamento é feito em PHP, EXATO, contra os pedaços do campo.
 *
 * ⚠️ **O `LIKE` foi deliberadamente evitado no caminho 2.** `%<nome>%` parece
 * a saída óbvia e tem dois defeitos: `_` é curinga do LIKE e o nome do arquivo
 * é cheio deles (casaria um arquivo diferente do mesmo tamanho), e um LIKE por
 * linha vira 5.000 consultas no teto do export. A janela lê os alarmes de uma
 * vez: medido, **4.198 de 4.208 em 160 ms** no pior caso, e 25 de 25 em 37 ms
 * numa página.
 *
 * ⚠️ `media_files.file_name` pode guardar os DOIS arquivos da JIMI numa string
 * só (`a.mp4,b.mp4`) — exatamente como `alarms.file_url` os guarda. Por isso o
 * índice recebe os pedaços **e** o campo inteiro como chave.
 *
 * O rótulo sai de `alarm_label_sql()`, o ponto único do projeto: nome gravado
 * no webhook vence, mas `Código NNNN (JTT)` é re-resolvido contra o catálogo
 * atual — senão a tela mostraria o código cru de um alarme catalogado depois.
 *
 * @param PDO   $db
 * @param array $arquivos Linhas com `imei`, `file_name` e (opcional) `event_time`/`created_at`
 * @returns array Mapa `"<imei>|<file_name>" => ['nome','alarm_time','id']`
 */
function media_alarmes_dos_arquivos(PDO $db, array $arquivos): array
{
    $achados = [];
    if (!$arquivos) {
        return $achados;
    }
    ['joins' => $joins, 'expr' => $expr] = alarm_label_sql();

    // ── 1) O rótulo que o próprio nome carrega (JT/T) ───────────────────────
    $labels = [];
    $labelDoArquivo = [];
    foreach ($arquivos as $r) {
        $imei = (string)($r['imei'] ?? '');
        $nome = (string)($r['file_name'] ?? '');
        if ($imei === '' || $nome === '') continue;
        $p = explode('_', $nome);
        if (count($p) >= 3 && $p[0] === $imei && $p[1] !== '') {
            $labels[$p[1]] = true;
            $labelDoArquivo[$imei . '|' . $nome] = $p[1];
        }
    }
    $porLabel = [];
    // Lotes de 800: um `IN()` sem teto vira consulta de megabytes no export.
    foreach (array_chunk(array_keys($labels), 800) as $lote) {
        $ph = implode(',', array_fill(0, count($lote), '?'));
        $st = $db->prepare("
            SELECT a.id, a.imei, a.alarm_label, a.alarm_time, {$expr} AS nome
              FROM alarms a {$joins}
             WHERE a.alarm_label IN ($ph)");
        $st->execute($lote);
        foreach ($st as $a) {
            $porLabel[$a['imei'] . '|' . $a['alarm_label']] = $a;
        }
    }

    // ── 2) O que sobrou: vínculo por `alarms.file_url`, em UMA consulta ─────
    $faltam = [];
    $imeis = [];
    $tmin = null;
    $tmax = null;
    foreach ($arquivos as $r) {
        $imei = (string)($r['imei'] ?? '');
        $nome = (string)($r['file_name'] ?? '');
        if ($imei === '' || $nome === '') continue;
        $chave = $imei . '|' . $nome;
        $lb = $labelDoArquivo[$chave] ?? null;
        if ($lb !== null && isset($porLabel[$imei . '|' . $lb])) {
            $achados[$chave] = $porLabel[$imei . '|' . $lb];
            continue;
        }
        $faltam[] = $r;
        $imeis[$imei] = true;
        $t = strtotime(((string)($r['event_time'] ?? $r['created_at'] ?? '')) . ' UTC');
        if ($t) {
            $tmin = $tmin === null ? $t : min($tmin, $t);
            $tmax = $tmax === null ? $t : max($tmax, $t);
        }
    }
    if (!$faltam || !$imeis || $tmin === null) {
        return $achados;
    }

    // Folga de 2 dias porque `event_time` de um bloco extraído é a hora em que
    // o UPLOAD terminou, não a do alarme — a mesma folga que a tela de playback
    // usa pela mesma razão.
    $ip = implode(',', array_fill(0, count($imeis), '?'));
    $st = $db->prepare("
        SELECT a.id, a.imei, a.file_url, a.alarm_time, {$expr} AS nome
          FROM alarms a {$joins}
         WHERE a.imei IN ($ip)
           AND a.file_url IS NOT NULL AND a.file_url <> ''
           AND a.alarm_time BETWEEN ? AND ?");
    $st->execute(array_merge(array_keys($imeis), [
        gmdate('Y-m-d H:i:s', $tmin - 2 * 86400),
        gmdate('Y-m-d H:i:s', $tmax + 2 * 86400),
    ]));
    $porNome = [];
    foreach ($st as $a) {
        $inteiro = trim((string)$a['file_url']);
        if ($inteiro !== '') {
            $porNome[$a['imei'] . '|' . $inteiro] = $a;   // o par junto, como a JIMI o anuncia
        }
        foreach (media_file_list($a['file_url']) as $pedaco) {
            $porNome[$a['imei'] . '|' . $pedaco] = $a;
        }
    }
    foreach ($faltam as $r) {
        $chave = (string)$r['imei'] . '|' . (string)$r['file_name'];
        if (isset($porNome[$chave])) {
            $achados[$chave] = $porNome[$chave];
        }
    }
    return $achados;
}

/**
 * O arquivo foi PEDIDO pelo operador ("on demand"), e não empurrado por um alarme?
 *
 * Pedido do dono do produto (07/09/2026): *"nessa lista temos também os
 * arquivos solicitados pelo operador, identifique esses arquivos na coluna que
 * exibe o alarme para 'On demand'"*.
 *
 * 🔴 `source_type` É O MARCADOR, e ele sobrevive ao ciclo de vida da linha.
 * O despacho grava `extracao_hvideo` / `extracao_evideo` / `extracao_37382`
 * com `download_status='solicitado'` e sem nome — o nome só existe quando a
 * câmera termina de subir. Quando o arquivo chega, `media_register_file()`
 * **promove** a linha (file_name, file_url, file_type, download_status) e
 * **não toca em `source_type`**: por isso a marca de "quem pediu" continua lá
 * depois de o arquivo ficar pronto. Conferido no `UPDATE` daquela função.
 *
 * ⚠️ **`pushftpfileupload` também conta.** A extração do JT/T (`37382`) sobe por
 * FTP, e o nome que a câmera dá (`ext20260831122209f7c617`) **não tem carimbo
 * parseável** — então `media_register_file()` nem tenta promover o pedido
 * pendente, e o arquivo entra como linha nova com essa origem. Em produção é
 * exatamente o caso do único arquivo sem alarme em 2.000 linhas.
 *
 * ⚠️ **A ausência de alarme sozinha NÃO basta** e não é usada aqui. Um anexo de
 * alarme cujo vínculo falhasse cairia no mesmo balde e seria rotulado como
 * pedido do operador — afirmação sobre a intenção de uma pessoa, feita a partir
 * de um dado que faltou. Quem não casa nem com alarme nem com origem de
 * extração fica sem rótulo, que é honesto.
 *
 * @param string|null $sourceType Valor de `media_files.source_type`
 * @returns bool
 */
function media_pedido_pelo_operador(?string $sourceType): bool
{
    $s = strtolower(trim((string)$sourceType));
    return $s !== '' && (strpos($s, 'extracao_') === 0 || $s === 'pushftpfileupload');
}
