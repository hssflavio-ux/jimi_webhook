<?php
/**
 * bycamera — Vigia da renovação do certificado TLS (cron horário)
 *
 * CONTEXTO. Neste servidor a porta 80 fica FECHADA no dia a dia, por decisão de
 * infraestrutura. Como o desafio HTTP-01 da Let's Encrypt precisa dela, a
 * renovação não pode ser automática: alguém abre a porta numa janela e o
 * certificado é renovado ali dentro.
 *
 * O certificado vale 90 dias e a renovação é aceita nos últimos 30 — a janela é
 * larga, não um instante. Este script existe para que ninguém precise lembrar
 * dela sozinho.
 *
 * COMO FUNCIONA. A cada hora, dentro da janela, ele TENTA renovar. Porta
 * fechada = falha inócua e silenciosa. Quando o operador abre a porta, a
 * tentativa seguinte renova e avisa por e-mail que já pode fechar.
 *
 * POR QUE TENTAR EM VEZ DE ESPERAR UM SINAL. Um gatilho manual ("avise o
 * sistema que abri") acrescenta um passo que também pode ser esquecido, e
 * transforma a renovação numa coordenação entre duas pessoas. Tentando de hora
 * em hora, o único ato necessário é abrir a porta.
 *
 * POR QUE ISSO NÃO ESBARRA EM RATE LIMIT. A Let's Encrypt permite 5 validações
 * FALHAS por hora por conta/hostname. Uma tentativa por hora fica muito abaixo,
 * e tentativa falha não consome a cota de emissão (essa conta certificado
 * emitido, não recusado).
 *
 * ⚠️ AO ABRIR A PORTA 80, ABRA PARA `0.0.0.0/0`. Desde 2020 a validação é
 * multi-perspectiva: o desafio é buscado a partir de datacenters em vários
 * continentes e só passa com quórum. Liberação restrita a redes brasileiras
 * FALHA mesmo com a porta "aberta" — e falha de um jeito confuso, porque o
 * teste manual a partir do escritório funciona.
 *
 * ⚠️ O TIMER PADRÃO DO CERTBOT FICA DESLIGADO de propósito
 * (`systemctl disable --now certbot.timer`). Ele tentaria por conta própria e
 * falharia EM SILÊNCIO com a porta fechada — exatamente o modo de falha que
 * este script existe para eliminar. Dois caminhos concorrentes também tornariam
 * o log ambíguo na hora de diagnosticar.
 *
 * Configuração no .env:
 *   TLS_DOMAIN        — nome do certificado (opcional; sem ele, detecta o
 *                       único diretório em /etc/letsencrypt/live)
 *   TLS_ALERT_EMAIL   — destinatários, separados por vírgula. Sem ele, cai
 *                       para os administradores cadastrados.
 *
 * Roda como ROOT (precisa ler /etc/letsencrypt e executar certbot).
 * Instalação: crontab do root, de hora em hora.
 */

require_once __DIR__ . '/../core/Logger.php';

// Parse mínimo do .env (mesmo formato do config/database.php)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        if (!getenv(trim($key))) putenv(trim($key) . '=' . trim($value));
    }
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mailer.php';

/** Dias restantes a partir dos quais o alerta começa (janela da LE é 30). */
const TLS_LIMIAR_ALERTA  = 25;
/** Abaixo disto o assunto do e-mail muda de tom. */
const TLS_LIMIAR_URGENTE = 7;
/** Abaixo disto o alerta deixa de ser 1×/dia e passa a 4×/dia. */
const TLS_LIMIAR_CRITICO = 3;

$estadoArq = __DIR__ . '/../logs/tls_watch_state.json';
$agora     = new DateTimeImmutable('now', new DateTimeZone('UTC'));

/**
 * Diretório do certificado vivo, por TLS_DOMAIN ou por detecção.
 *
 * @returns string|null Nome do certificado, ou null se não há nenhum emitido
 */
function tls_dominio(): ?string
{
    $cfg = trim((string)getenv('TLS_DOMAIN'));
    if ($cfg !== '') {
        return is_file("/etc/letsencrypt/live/$cfg/cert.pem") ? $cfg : null;
    }
    $achados = glob('/etc/letsencrypt/live/*/cert.pem') ?: [];
    if (count($achados) === 1) {
        return basename(dirname($achados[0]));
    }
    // Zero ou ambíguo: exigir TLS_DOMAIN é melhor que renovar o certificado errado.
    return null;
}

/**
 * Instante de expiração do certificado.
 *
 * @param string $dominio Nome do certificado
 * @returns int|null Timestamp UNIX, ou null se ilegível
 */
function tls_expira_em(string $dominio): ?int
{
    $arq = "/etc/letsencrypt/live/$dominio/cert.pem";
    if (!is_readable($arq)) {
        return null;
    }
    $saida = shell_exec('openssl x509 -enddate -noout -in ' . escapeshellarg($arq) . ' 2>/dev/null');
    if (!$saida || !preg_match('/notAfter=(.+)/', $saida, $m)) {
        return null;
    }
    $ts = strtotime(trim($m[1]));
    return $ts === false ? null : $ts;
}

/**
 * Destinatários do alerta: TLS_ALERT_EMAIL ou, na falta, os administradores.
 *
 * @returns array Lista de e-mails
 */
function tls_destinatarios(): array
{
    $cfg = trim((string)getenv('TLS_ALERT_EMAIL'));
    if ($cfg !== '') {
        return array_filter(array_map('trim', explode(',', $cfg)));
    }
    try {
        $db = Database::getInstance()->getConnection();
        $q  = $db->query("SELECT email FROM users WHERE user_type = 'admin' AND email <> '' LIMIT 10");
        $r  = $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if ($r) return $r;
        // Sem admin explícito (o dump veio com user_type 'cliente'): usa quem existe.
        $q = $db->query("SELECT email FROM users WHERE email <> '' ORDER BY id LIMIT 5");
        return $q->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        Logger::error('tls_watch: não foi possível resolver destinatários', ['erro' => $e->getMessage()]);
        return [];
    }
}

/**
 * Lê o estado persistido entre execuções.
 *
 * @param string $arq Caminho do arquivo de estado
 * @returns array
 */
function tls_estado(string $arq): array
{
    if (!is_file($arq)) return [];
    $j = json_decode((string)file_get_contents($arq), true);
    return is_array($j) ? $j : [];
}

/**
 * Grava o estado.
 *
 * @param string $arq   Caminho
 * @param array  $dados Conteúdo
 * @returns void
 */
function tls_grava_estado(string $arq, array $dados): void
{
    @file_put_contents($arq, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

/**
 * Envia o alerta, respeitando o intervalo mínimo entre avisos.
 *
 * @param string $assunto  Assunto
 * @param string $corpo    HTML
 * @param bool   $forcar   Ignora o intervalo (usado no sucesso)
 * @param int    $minHoras Intervalo mínimo desde o último aviso
 * @returns bool Enviado?
 */
function tls_alerta(string $assunto, string $corpo, bool $forcar, int $minHoras): bool
{
    global $estadoArq, $agora;
    $estado = tls_estado($estadoArq);

    if (!$forcar && !empty($estado['ultimo_alerta'])) {
        $ultimo = strtotime((string)$estado['ultimo_alerta']);
        if ($ultimo && ($agora->getTimestamp() - $ultimo) < $minHoras * 3600) {
            return false; // ainda dentro do intervalo — silêncio proposital
        }
    }

    $para = tls_destinatarios();
    if (!$para) {
        Logger::error('tls_watch: nenhum destinatário para o alerta');
        return false;
    }

    $r = send_mail($para, $assunto, $corpo);
    if (!empty($r['ok'])) {
        $estado['ultimo_alerta'] = $agora->format('c');
        tls_grava_estado($estadoArq, $estado);
        echo "  alerta enviado para " . implode(', ', $para) . "\n";
        return true;
    }

    Logger::error('tls_watch: falha ao enviar alerta', ['erro' => $r['error'] ?? '?']);
    echo "  FALHA ao enviar alerta: " . ($r['error'] ?? '?') . "\n";
    return false;
}

/**
 * Corpo HTML do aviso de "abra a porta".
 *
 * @param string $dominio Certificado
 * @param int    $dias    Dias restantes
 * @returns string
 */
function tls_corpo_abrir(string $dominio, int $dias): string
{
    $d = htmlspecialchars($dominio, ENT_QUOTES);
    return "<div style=\"font-family:Arial,sans-serif;font-size:14px;color:#0a0b0d\">
<h2 style=\"font-weight:400\">Renovação do certificado TLS pendente</h2>
<p>O certificado de <strong>$d</strong> expira em <strong>$dias dia(s)</strong>.</p>
<p><strong>O que fazer:</strong> liberar a porta <strong>80/tcp</strong> de entrada.
O sistema tenta renovar de hora em hora e avisa por e-mail assim que conseguir —
aí a porta pode ser fechada de novo.</p>
<p style=\"background:#fdeaec;padding:12px;border-radius:6px\">
<strong>Atenção:</strong> a liberação precisa aceitar <strong>qualquer origem
(0.0.0.0/0)</strong>. A Let's Encrypt valida a partir de datacenters em vários
continentes; liberar só para redes do Brasil <strong>falha</strong>, mesmo que o
teste do escritório funcione.</p>
<p style=\"color:#5b616e;font-size:12px\">Enviado por scripts/tls_renew_watch.php</p>
</div>";
}

// ─────────────────────────────────────────────────────────────────────────────

echo "[tls_watch] " . $agora->format('Y-m-d H:i:s') . " UTC\n";

if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    echo "  AVISO: não está rodando como root — certbot e /etc/letsencrypt ficarão inacessíveis.\n";
}

$dominio = tls_dominio();
if ($dominio === null) {
    echo "  nenhum certificado encontrado (defina TLS_DOMAIN no .env se houver mais de um)\n";
    Logger::warning('tls_watch: nenhum certificado localizado');
    tls_alerta(
        '[bycamera] TLS: nenhum certificado encontrado no servidor',
        '<p>O vigia de renovação não encontrou certificado em <code>/etc/letsencrypt/live</code>. '
        . 'Se o TLS ainda não foi instalado, ignore este aviso.</p>',
        false,
        24
    );
    exit(0);
}

$expira = tls_expira_em($dominio);
if ($expira === null) {
    echo "  não foi possível ler a validade de $dominio\n";
    Logger::error('tls_watch: validade ilegível', ['dominio' => $dominio]);
    exit(1);
}

$dias = (int)floor(($expira - $agora->getTimestamp()) / 86400);
echo "  certificado: $dominio | expira em $dias dia(s) (" . date('Y-m-d', $expira) . ")\n";

if ($dias > TLS_LIMIAR_ALERTA) {
    echo "  fora da janela de renovação — nada a fazer\n";
    exit(0);
}

// ── Dentro da janela: tentar renovar ────────────────────────────────────────
echo "  dentro da janela — tentando renovar...\n";
$cmd = 'certbot renew --cert-name ' . escapeshellarg($dominio)
     . ' --non-interactive --no-random-sleep-on-renew 2>&1';
$saida = (string)shell_exec($cmd);

$expiraDepois = tls_expira_em($dominio) ?? $expira;

if ($expiraDepois > $expira) {
    $novosDias = (int)floor(($expiraDepois - $agora->getTimestamp()) / 86400);
    echo "  RENOVADO — nova validade: " . date('Y-m-d', $expiraDepois) . " ($novosDias dias)\n";
    Logger::info('tls_watch: certificado renovado', [
        'dominio' => $dominio,
        'expira'  => date('c', $expiraDepois),
    ]);

    $d = htmlspecialchars($dominio, ENT_QUOTES);
    tls_alerta(
        "[bycamera] TLS renovado — pode fechar a porta 80",
        "<div style=\"font-family:Arial,sans-serif;font-size:14px\">
<h2 style=\"font-weight:400\">Certificado renovado</h2>
<p><strong>$d</strong> agora vale até <strong>" . date('d/m/Y', $expiraDepois) . "</strong> ($novosDias dias).</p>
<p><strong>A porta 80 já pode ser fechada.</strong></p>
<p style=\"color:#5b616e;font-size:12px\">O próximo aviso chega quando faltarem "
        . TLS_LIMIAR_ALERTA . " dias.</p></div>",
        true,
        0
    );

    // Zera o estado para o ciclo seguinte começar limpo.
    tls_grava_estado($estadoArq, ['expira_em' => date('c', $expiraDepois)]);
    exit(0);
}

// ── Não renovou: a porta provavelmente segue fechada ────────────────────────
echo "  não renovou (porta 80 fechada?) — saída do certbot:\n";
foreach (array_slice(array_filter(explode("\n", $saida)), -5) as $l) {
    echo "    $l\n";
}

$minHoras = $dias <= TLS_LIMIAR_CRITICO ? 6 : 24;
$prefixo  = $dias <= TLS_LIMIAR_CRITICO ? 'CRÍTICO' : ($dias <= TLS_LIMIAR_URGENTE ? 'URGENTE' : 'Aviso');

Logger::warning('tls_watch: renovação pendente', ['dominio' => $dominio, 'dias' => $dias]);

tls_alerta(
    "[bycamera] $prefixo — abrir porta 80: certificado expira em $dias dia(s)",
    tls_corpo_abrir($dominio, $dias),
    false,
    $minHoras
);
