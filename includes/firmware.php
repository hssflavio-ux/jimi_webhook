<?php
/**
 * bycamera — Firmware dos equipamentos v4.9.32
 *
 * Ponto ÚNICO de três coisas que estavam faltando ou espalhadas:
 *
 *   1. LER a versão que o equipamento reporta no `VERSION#` e gravá-la em
 *      `devices.firmware_version` — a coluna existe desde a v4.0.0 e estava
 *      NULL em 100% da base porque só o formulário de `/equipamentos` a
 *      escrevia, à mão.
 *   2. VALIDAR a URL de atualização antes de ela virar `UPDATE,<url>#`.
 *   3. RESOLVER qual URL vale para um equipamento — sempre pelo MODELO.
 *
 * ── Por que isso importa, com um caso real ─────────────────────────────────
 *
 * Na v4.9.31 as duas JC400AD de produção divergiram: `V1.8.1.2_250904` aceita
 * `EVIDEO` e `V1.8.0.9_250807` recusa **todas** as formas testadas. Sem
 * firmware no banco, a escolha do comando teve de virar tentativa-e-erro
 * contra o equipamento em operação. O dado estava a um `VERSION#` de distância
 * o tempo todo; faltava alguém gravar a resposta.
 *
 * 🔴 A captura só acontece para comando `VERSION` e só quando a resposta NÃO é
 * recusa. As quatro recusas da linha JC (`Time Out!`, `Not support!`,
 * `instruction error!`, `<CMD#>Command was not recognized!`) já são
 * classificadas por `command_response_interpret()`; reaproveitar essa
 * classificação evita gravar "Time Out!" como se fosse versão de firmware —
 * que é exatamente o tipo de dado errado que ninguém desconfia depois.
 */

require_once __DIR__ . '/command_response.php';

/**
 * O comando enviado é um `VERSION` de texto (proNo 128)?
 *
 * Casa pelo PRIMEIRO token, como `command_label()`: o histórico grava tanto
 * `VERSION#` quanto `VERSION` conforme o caminho que despachou.
 *
 * @param  string   $conteudo Conteúdo enviado (`commands.command_content`)
 * @param  int|null $proNo    proNo do envio; 128 é o canal de texto
 * @returns bool
 */
function firmware_is_version_command(string $conteudo, ?int $proNo = 128): bool
{
    if ($proNo !== null && $proNo !== 128) return false;
    $base = mb_strtoupper(trim(preg_split('/[,#]/', trim($conteudo))[0] ?? ''), 'UTF-8');
    return $base === 'VERSION';
}

/**
 * Extrai a versão de firmware do que o equipamento respondeu.
 *
 * ⚠️ O formato da resposta varia por firmware e NÃO está documentado em lugar
 * nenhum — a wiki lista o comando, não o retorno. Por isso a leitura é
 * tolerante e ancorada no que se sabe de verdade: as versões observadas em
 * produção têm a forma `V1.8.1.2_250904`, ou seja, pelo menos dois grupos
 * numéricos separados por ponto.
 *
 * A ordem é deliberada:
 *   1. resposta em pares (`Version:V1.8.1.2_250904; ...`) → o par vence, porque
 *      ali o próprio equipamento diz qual campo é a versão;
 *   2. senão, o primeiro token que contenha `\d+\.\d+`.
 *
 * O eco do comando (`<VERSION#>`) não tem dígito com ponto e é ignorado
 * sozinho, sem regra especial.
 *
 * @param  string $conteudo Resposta do equipamento (`data._content`)
 * @returns string|null Versão, ou NULL quando não há nada reconhecível
 */
function firmware_parse_version(string $conteudo): ?string
{
    $conteudo = trim($conteudo);
    if ($conteudo === '') return null;

    // 1. Resposta em pares — o equipamento nomeia o campo.
    foreach (command_response_kv($conteudo) as $chave => $valor) {
        if (preg_match('/vers|firmware|soft/i', $chave) && preg_match('/\d+\.\d+/', $valor)) {
            return mb_substr(trim($valor), 0, 50);
        }
    }

    // 2. Primeiro token com cara de versão.
    if (preg_match_all('/[A-Za-z0-9][A-Za-z0-9._\-]*/', $conteudo, $m)) {
        foreach ($m[0] as $tok) {
            if (preg_match('/\d+\.\d+/', $tok)) return mb_substr(rtrim($tok, '.-_'), 0, 50);
        }
    }
    return null;
}

/**
 * Grava em `devices` a versão lida de uma resposta ao `VERSION#`.
 *
 * Chamado dos DOIS caminhos por onde uma resposta chega — o síncrono
 * (`sendcommand.php`, device online) e o callback (`pushinstructresponse.php`,
 * comando de fila offline). Cobrir só um deixaria metade da frota sem leitura,
 * e seria justamente a metade que estava offline quando alguém perguntou.
 *
 * 🔴 Recusa não é versão. `command_response_interpret()` classifica os quatro
 * dialetos de recusa da linha JC; nível `erro` ou `aguardando` não grava nada.
 *
 * @param  PDO         $db
 * @param  string      $imei
 * @param  string      $conteudo Resposta do equipamento (`data._content`)
 * @param  string      $texto    Status de entrega do gateway (`data._msg`)
 * @param  string      $codigo   Código do gateway, quando houver
 * @returns string|null Versão gravada, ou NULL quando não havia o que gravar
 */
function firmware_capture(PDO $db, string $imei, string $conteudo, string $texto = '', string $codigo = ''): ?string
{
    $nivel = command_response_interpret($texto, $codigo, $conteudo)['nivel'];
    if ($nivel === 'erro' || $nivel === 'aguardando') return null;

    $versao = firmware_parse_version($conteudo);
    if ($versao === null) return null;

    $db->prepare(
        "UPDATE devices
            SET firmware_version = :v, firmware_checked_at = UTC_TIMESTAMP(), firmware_source = 'device'
          WHERE imei = :imei"
    )->execute([':v' => $versao, ':imei' => $imei]);

    return $versao;
}

/**
 * A URL pode virar `UPDATE,<url>#` sem ser partida no caminho?
 *
 * 🔴 Vírgula e `#` são os separadores do proNo 128. Uma URL que contenha
 * qualquer um dos dois chega ao equipamento cortada, e o que ele tenta baixar
 * é um pedaço de endereço — falha que aparece como "atualização não aconteceu",
 * sem nada no log dizendo por quê. É por isso que a checagem é aqui, antes do
 * envio, e não um `str_replace` esperançoso.
 *
 * @param  string $url
 * @returns string|null Mensagem do problema, ou NULL quando a URL serve
 */
function firmware_url_problema(string $url): ?string
{
    $url = trim($url);
    if ($url === '')                      return 'Informe a URL do pacote de firmware.';
    if (mb_strlen($url) > 500)            return 'URL longa demais (máximo de 500 caracteres).';
    if (!preg_match('#^https?://#i', $url)) return 'A URL precisa começar com http:// ou https://.';
    if (strpos($url, ',') !== false)      return 'A URL não pode conter vírgula: é o separador de parâmetros do comando, e o equipamento receberia o endereço partido ao meio.';
    if (strpos($url, '#') !== false)      return 'A URL não pode conter #: é o terminador do comando, e o equipamento descartaria tudo depois dele.';
    if (preg_match('/\s/', $url))         return 'A URL não pode conter espaços.';
    if (!filter_var($url, FILTER_VALIDATE_URL)) return 'URL inválida.';
    return null;
}

/**
 * Monta o comando de atualização — a forma canônica, em um lugar só.
 *
 * ⚠️ As duas telas montam a MESMA string em JavaScript, e não por descuido: as
 * duas mostram o comando em tempo real enquanto a URL é digitada, e uma volta
 * ao servidor por tecla não se justifica. Esta função existe para que a forma
 * tenha uma definição de referência (é a que o teste fixa) — quem mudar o
 * formato tem de mudar aqui E nos dois `'UPDATE,' + url + '#'` do front.
 *
 * @param  string $url URL já validada por firmware_url_problema()
 * @returns string `UPDATE,<url>#`
 */
function firmware_update_command(string $url): string
{
    return 'UPDATE,' . trim($url) . '#';
}

/**
 * Releases cadastradas, opcionalmente de um modelo só.
 *
 * @param  PDO      $db
 * @param  int|null $modelId  NULL = todos os modelos
 * @param  bool     $ativas   Só as ativas (padrão)
 * @returns array<int,array<string,mixed>>
 */
function firmware_releases(PDO $db, ?int $modelId = null, bool $ativas = true): array
{
    $sql = "SELECT r.*, dm.model_name, dm.protocol
              FROM firmware_releases r
              JOIN device_models dm ON dm.id = r.device_model_id
             WHERE 1=1";
    $p = [];
    if ($modelId !== null) { $sql .= " AND r.device_model_id = :m"; $p[':m'] = $modelId; }
    if ($ativas)           { $sql .= " AND r.is_active = 1"; }
    $sql .= " ORDER BY dm.model_name, r.is_current DESC, r.version DESC";

    $st = $db->prepare($sql);
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Compara o firmware do equipamento com a release corrente do modelo dele.
 *
 * ⚠️ Por IGUALDADE, nunca por ordem. Não há regra publicada que ordene
 * `V1.8.0.9_250807` contra `V4.3.2`, e inventar uma faria a tela afirmar
 * "desatualizado" sobre um palpite. Os estados possíveis dizem só o que se
 * sabe.
 *
 * @param  string|null $atual     `devices.firmware_version`
 * @param  string|null $corrente  `version` da release marcada is_current
 * @returns array{estado:string, rotulo:string} estado: igual|diferente|sem_leitura|sem_release
 */
function firmware_situacao(?string $atual, ?string $corrente): array
{
    $atual    = trim((string)$atual);
    $corrente = trim((string)$corrente);

    if ($atual === '')    return ['estado' => 'sem_leitura', 'rotulo' => 'Firmware não lido'];
    if ($corrente === '') return ['estado' => 'sem_release', 'rotulo' => 'Sem versão de referência'];
    // Comparação sem caixa: a versão de referência é DIGITADA no cadastro e a
    // do equipamento vem da resposta dele. Diferença de caixa entre as duas
    // seria erro de digitação exibido como "firmware diferente".
    if (strcasecmp($atual, $corrente) === 0) return ['estado' => 'igual', 'rotulo' => 'Igual à de referência'];
    return ['estado' => 'diferente', 'rotulo' => 'Diferente da de referência'];
}
