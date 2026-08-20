<?php
/**
 * bycamera — Recepção do FILELIST das câmeras JIMI
 * Rota: /filelist/{imei}
 *
 * O QUE É. No protocolo JIMI a listagem de gravações do cartão funciona ao
 * contrário do JT/T. No JT/T pedimos uma janela (`37381`) e o IoT Hub devolve
 * a lista estruturada por webhook. No JIMI a CÂMERA sobe sozinha a lista
 * inteira, sem filtro de data, para um endereço que ela tem GRAVADO.
 *
 * 🔴 SÃO DOIS COMANDOS COM NATUREZAS DIFERENTES, e confundi-los custou caro nos
 * dois sentidos. A planilha oficial (JIMI V5.0.3) é inequívoca:
 *
 *   A006  `FILELIST,<url>`  "Modify the server address to receive the playback
 *                            video namelist file."  → CONFIGURAÇÃO (escrita)
 *   A007  `FILELIST`        "Let the device to upload the playback video
 *                            namelist file to the server."  → PEDIDO (leitura)
 *
 * Os dados de produção mostram a diferença: sete `FILELIST,<url>` entre 14:54 e
 * 15:22 de 19/08 — nenhuma captura; o `FILELIST` nu de 15:00:19 produziu a
 * captura de 15:00:19, no mesmo segundo.
 *
 * ⚠️ O ERRO SIMÉTRICO, corrigido na v4.9.38: a tela de playback passou a mandar
 * OS DOIS a cada clique em Requisitar. Como o primeiro é ESCRITA, uma ação de
 * leitura reconfigurava o equipamento toda vez. O endereço é setup — grava-se
 * uma vez, de propósito.
 *
 * O LAYOUT FOI MEDIDO em 19–20/08/2026 (400AD_3, `tcpdump` na porta 80 e
 * captura crua). Não é TXT: é **JSON**, e a lista vem numa string única
 * separada por vírgula —
 *
 *     {"imei":"864993060392306","fileNameList":"2026_08_16_05_33_58_01.ts,…"}
 *
 * O sufixo `_01`/`_02` do nome é a câmera (1=frontal, 2=interna), o mesmo par
 * do parâmetro B do `EVIDEO`/`HVIDEO`. A interpretação dos nomes — inclusive a
 * armadilha do fuso, que **não é GMT 0** — vive em `includes/filelist.php`.
 *
 * ⚠️ O CORPO SÓ CHEGA COM A CONFIG DO APACHE NO LUGAR. Corpo `chunked` acima de
 * 16 KB era descartado em silêncio entre o Apache e o PHP-FPM (mod_proxy_fcgi),
 * e a lista real tem ~78 KB — por isso este handler passou dias gravando
 * captura de 0 byte. Corrigido em 19/08/2026 com
 * `docs/apache/filelist-chunked.conf` (`SetEnvIf … proxy-sendcl=1`), que é
 * **infra fora do git**: o `deploy.sh` não a instala, e ela some se a máquina
 * for reprovisionada. Captura de 0 byte voltando é o primeiro sintoma disso.
 *
 * ── O QUE ESTE HANDLER FAZ (v4.9.34) ────────────────────────────────────────
 *   1. valida o IMEI contra `devices`;
 *   2. grava o corpo CRU em `logs/filelist/` — a captura continua, porque foi
 *      ela que resolveu esta investigação e é o que permitirá diagnosticar o
 *      próximo firmware que mudar o formato;
 *   3. responde 200 e ENCERRA a requisição (`fastcgi_finish_request`) — a
 *      câmera não espera as ~3.000 gravações irem para o banco;
 *   4. só então interpreta e grava em `resource_lists`, a mesma tabela do
 *      JT/T, de onde a tela de playback lê.
 *
 * A ordem importa: o corpo cru é escrito ANTES de qualquer interpretação. Se o
 * parser falhar, o dado que custou cinco dias para chegar continua no disco.
 *
 * SEM LOGIN, DE PROPÓSITO — e a razão é a mesma do `/download`: quem chama é
 * a câmera, que não tem como carregar sessão nem o token do webhook (ele nem
 * existe no comando de texto). As defesas são outras:
 *   • o IMEI do caminho tem de existir em `devices` — senão nada é gravado;
 *   • teto de tamanho, para o endpoint não virar depósito;
 *   • o corpo é gravado no disco ANTES de ser interpretado, e o parser não
 *     executa nada do que leu — no máximo descarta o nome que não casa;
 *   • a captura vai para `logs/filelist/`, que o VirtualHost NEGA à web
 *     (`DirectoryMatch` de `logs`), então o que entra não sai por aqui.
 *
 * ⚠️ A CÂMERA FALA HTTP SIMPLES. O redirect 80→443 do site tem exceção para
 * este caminho (ver docs/apache/bycamera.conf) — sem ela a câmera receberia
 * um 301 para HTTPS e, se não seguir redirect ou não fizer TLS, o upload
 * morreria sem deixar rastro no nosso lado.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/../includes/filelist.php';

if (ob_get_level()) ob_end_clean();

/** Teto do corpo aceito. Lista de milhares de nomes ainda cabe folgado. */
const FILELIST_MAX_BYTES = 8 * 1024 * 1024;

$imei   = preg_replace('/[^0-9A-Za-z]/', '', (string)($_GET['imei'] ?? ''));
$metodo = $_SERVER['REQUEST_METHOD'] ?? '?';

// ── 1. O IMEI precisa ser conhecido ─────────────────────────────────────────
// Sem isto o endpoint aceitaria upload de qualquer origem para qualquer nome.
$conhecido = false;
if ($imei !== '') {
    try {
        $db = Database::getInstance()->getConnection();
        $st = $db->prepare("SELECT 1 FROM devices WHERE imei = :i LIMIT 1");
        $st->execute([':i' => $imei]);
        $conhecido = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        Logger::error('filelist: falha ao validar IMEI', ['imei' => $imei, 'erro' => $e->getMessage()]);
    }
}

if (!$conhecido) {
    // 404 e não 403: para quem sonda, "não existe" diz menos que "existe mas
    // você não pode". O log guarda o suficiente para investigar.
    Logger::warning('filelist: IMEI desconhecido ou ausente', [
        'imei' => $imei, 'metodo' => $metodo,
        'origem' => $_SERVER['REMOTE_ADDR'] ?? '?',
    ]);
    http_response_code(404);
    echo "not found\n";
    exit;
}

// ── 2. Captura crua ─────────────────────────────────────────────────────────
$corpo = file_get_contents('php://input', false, null, 0, FILELIST_MAX_BYTES + 1);
$corpo = $corpo === false ? '' : $corpo;
$truncado = strlen($corpo) > FILELIST_MAX_BYTES;
if ($truncado) $corpo = substr($corpo, 0, FILELIST_MAX_BYTES);

// Alguns firmwares mandam multipart em vez de corpo cru — capturar os dois,
// porque descobrir qual é depois de descartar um deles custaria outra rodada
// de campo.
$arquivos = [];
foreach ($_FILES as $campo => $f) {
    if (!isset($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) continue;
    $arquivos[] = [
        'campo' => $campo,
        'nome'  => $f['name'] ?? '?',
        'bytes' => (int)($f['size'] ?? 0),
        'dados' => (string)file_get_contents($f['tmp_name'], false, null, 0, FILELIST_MAX_BYTES),
    ];
}

$dir = __DIR__ . '/../logs/filelist';
if (!is_dir($dir)) @mkdir($dir, 0777, true);

$carimbo = gmdate('Ymd_His');
$base    = $dir . '/' . $imei . '_' . $carimbo;

// Cabeçalhos completos: o formato pode estar declarado num Content-Type que
// ninguém esperava, e essa informação só existe agora.
$cabecalhos = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0 || in_array($k, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
        $cabecalhos[$k] = $v;
    }
}

@file_put_contents($base . '.meta.json', json_encode([
    'imei'        => $imei,
    'metodo'      => $metodo,
    'uri'         => $_SERVER['REQUEST_URI'] ?? '',
    'origem'      => $_SERVER['REMOTE_ADDR'] ?? '',
    'recebido_em' => gmdate('c'),
    'corpo_bytes' => strlen($corpo),
    'truncado'    => $truncado,
    'query'       => $_GET,
    'post_campos' => array_keys($_POST),
    'arquivos'    => array_map(fn($a) => ['campo' => $a['campo'], 'nome' => $a['nome'], 'bytes' => $a['bytes']], $arquivos),
    'cabecalhos'  => $cabecalhos,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

if ($corpo !== '') @file_put_contents($base . '.body.raw', $corpo);
foreach ($arquivos as $i => $a) {
    @file_put_contents($base . '.file' . $i . '.raw', $a['dados']);
}

Logger::info('filelist: captura recebida', [
    'imei'     => $imei,
    'metodo'   => $metodo,
    'tipo'     => $_SERVER['CONTENT_TYPE'] ?? '',
    'bytes'    => strlen($corpo),
    'arquivos' => count($arquivos),
    'destino'  => basename($base),
]);

// ── 3. Resposta, ANTES de interpretar ───────────────────────────────────────
//
// A câmera não tem por que esperar ~3.000 gravações irem para o banco: o
// `fastcgi_finish_request()` devolve o 200 e libera a conexão, e o trabalho
// segue no processo — é a mesma técnica dos webhooks (ver CLAUDE.md; sem
// PHP-FPM ela não existe e a resposta simplesmente espera).
//
// 🔴 A EXPLICAÇÃO ANTERIOR DESTE BLOCO ESTAVA ERRADA, e fica registrada porque
// o erro é instrutivo. Ela dizia que o corpo vazio era um HANDSHAKE — a câmera
// anunciando e esperando a resposta antes de enviar — e que responder `ok` em
// texto puro a fazia desistir. Era teoria construída sobre a AUSÊNCIA de dado.
//
// O `tcpdump` de 19/08/2026 (400AD_3) desmentiu: a câmera manda a lista INTEIRA,
// de uma vez, logo depois dos cabeçalhos. Quem perdia o corpo éramos nós — corpo
// `chunked` acima de **16 KB** era descartado entre o Apache e o PHP-FPM (busca
// binária no servidor: 16.293 B chegavam, 16.699 B viravam zero, sem erro em log
// nenhum). Corrigido pela config do Apache, não por este arquivo — ver
// `docs/apache/filelist-chunked.conf`.
//
// O envelope JSON FICA: é o mesmo dos outros receptores deste projeto, e não
// custa nada. Mas ele não é o que faz o corpo chegar — acreditar que era é o
// que manteve esta investigação parada.
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
// ⚠️ O CORPO DA RESPOSTA É ESPECIFICADO (docs.jimicloud.com §1.3.5): a doc
// exige `{"code":0,"ok":true}`. Estávamos devolvendo `message:"success"`, e
// funcionava — este firmware não confere. Alinhado mesmo assim: depender de o
// device ser tolerante é apostar que o próximo firmware também será, e este
// projeto já perdeu essa aposta antes.
echo json_encode(['code' => 0, 'ok' => true], JSON_UNESCAPED_UNICODE) . "\n";

if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

// ── 4. Interpretação e gravação (FASE 1) ────────────────────────────────────
//
// Daqui para baixo a câmera já foi embora: nada aqui pode alterar a resposta, e
// uma falha não pode derrubar a captura crua, que já está no disco.
//
// ⚠️ O IMEI que vale é o da ROTA, não o do corpo. Os dois batem em tudo que foi
// medido, mas quem foi conferido contra `devices` é o da rota — deixar o corpo
// escolher a chave de gravação seria aceitar que um equipamento grave lista em
// nome de outro.
//
// O `set_time_limit` conta a partir daqui: são ~3.000 gravações por lista, e o
// relógio do `max_execution_time` continua correndo depois do
// `fastcgi_finish_request()`. Estourar aqui deixaria a lista pela metade, sem
// nada na tela dizendo que faltou — a captura crua estaria completa no disco e
// o banco não.
@set_time_limit(120);

try {
    $resultado = filelist_parse($corpo);

    // Fallback multipart: se o corpo cru não trouxe nomes mas um arquivo veio
    // anexado, a lista está nele. Nenhum firmware fez isso até aqui — é a
    // mesma precaução que já existe na captura, e custa uma condição.
    if ($resultado['validos'] === 0 && !empty($arquivos)) {
        foreach ($arquivos as $a) {
            $tentativa = filelist_parse($a['dados']);
            if ($tentativa['validos'] > 0) { $resultado = $tentativa; break; }
        }
    }

    $imeiCorpo = $resultado['imei'] !== null ? preg_replace('/[^0-9A-Za-z]/', '', (string)$resultado['imei']) : null;
    if ($imeiCorpo !== null && $imeiCorpo !== '' && $imeiCorpo !== $imei) {
        Logger::warning('filelist: IMEI do corpo diverge do IMEI da rota', [
            'rota' => $imei, 'corpo' => $imeiCorpo,
        ]);
    }

    $gravacao = filelist_persistir($db, $imei, $resultado['entradas']);

    // Resumo ao lado da captura: o diretório passa a dizer, sozinho, o que
    // aquele corpo virou — sem isso, conferir uma captura antiga exige
    // reprocessá-la à mão.
    @file_put_contents($base . '.parse.json', json_encode([
        'formato'     => $resultado['formato'],
        'imei_corpo'  => $resultado['imei'],
        'total_nomes' => $resultado['total_nomes'],
        'validos'     => $resultado['validos'],
        'vazios'      => $resultado['vazios'],
        'invalidos'   => $resultado['invalidos'],
        'gravados'    => $gravacao['gravados'],
        'erros'       => $gravacao['erros'],
        'captura'     => $gravacao['captura'],
        'primeiro'    => $resultado['entradas'][0]['start_utc'] ?? null,
        'ultimo'      => $resultado['entradas'] ? end($resultado['entradas'])['start_utc'] : null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    // Lista que chega e não produz linha nenhuma é o modo de falhar desta fase
    // — e é exatamente o tipo de silêncio que custou os dias anteriores. Fica
    // como WARNING, não INFO.
    if ($resultado['validos'] === 0) {
        Logger::warning('filelist: nenhum nome reconhecido na lista', [
            'imei'    => $imei,
            'formato' => $resultado['formato'],
            'bytes'   => strlen($corpo),
            'amostra' => $resultado['invalidos'],
        ]);
    } else {
        Logger::info('filelist: lista interpretada', [
            'imei'      => $imei,
            'nomes'     => $resultado['total_nomes'],
            'validos'   => $resultado['validos'],
            'invalidos' => count($resultado['invalidos']),
            'gravados'  => $gravacao['gravados'],
            'erros'     => $gravacao['erros'],
        ]);
    }
} catch (Throwable $e) {
    Logger::error('filelist: falha ao interpretar a lista', [
        'imei' => $imei, 'erro' => $e->getMessage(), 'captura' => basename($base),
    ]);
}
