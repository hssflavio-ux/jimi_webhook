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
