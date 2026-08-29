<?php
/**
 * bycamera — Gateway de SMS (provedor Allcance) v4.14.0
 *
 * PONTO ÚNICO de fala com a API da Allcance. Nenhum outro arquivo chama
 * `painel.allcancesms.com.br` — telas e webhook passam por aqui.
 *
 * ── O QUE ESTE CANAL É ──────────────────────────────────────────────────────
 *
 * Um SEGUNDO TRANSPORTE para os mesmos comandos de texto do proNo 128 que a
 * tela /comandos já monta. O caminho normal é o IoT Hub (TCP); quando a câmera
 * não fala com o Hub — APN errado, `SERVER` apontando para o lugar errado,
 * equipamento mudo — o SMS chega pela rede da operadora, que é um caminho
 * INDEPENDENTE. É um canal de resgate.
 *
 * 🔑 O TEXTO DO COMANDO É IDÊNTICO AO DA PLATAFORMA. Não há conversão de
 * sintaxe: manda-se a mesma string que o /comandos monta (`CMD,A,B#`, separador
 * vírgula). Decisão do dono do produto (29/08/2026), corroborada pela nota
 * oficial das planilhas Jimi — JC450: *"Commands can all be delivered using any
 * of the following ways: TCP, SMS, or TF card"*, com o mesmo formato de vírgula.
 *
 * ⚠️ Isso DERRUBA a forma `CMD#666666#A#B` que a wiki Foco na Via documenta
 * como "SMS". Não a use aqui; `command_catalog.php` é reusado inteiro, e o teste
 * do catálogo já afirma que nenhuma sintaxe carrega `666666`.
 *
 * ── CONTRATO DA API (medido contra a API real em 29/08/2026) ────────────────
 *
 *   POST /v2/api/login      {username, password}  → 200 {status:success, token}
 *                           400 {status:error_validate} = credencial inválida
 *                           422 {error:{campo:[...]}}   = campo ausente
 *   GET  /v2/api/creditos    (Bearer)             → 200 [{servico, credito}, …]
 *   POST /v2/api/campanhas   (Bearer)             → 201 {status, referencia_campanha}
 *                           406 error_validate_credit = sem saldo
 *
 * O token é um JWT com validade de 3600 s e a API NÃO tem refresh. Por isso o
 * cache em `sms_settings.token` — sem ele, cada abertura de tela faria duas
 * requisições (login + a útil).
 *
 * `cod_servico` 11 = SMS TRANSACIONAL. Os outros códigos da conta (2 OTP,
 * 4 MASSIVO, 14 BLEND, 15/16 BET) NÃO servem: o transacional é o que entrega
 * quase instantaneamente, que é o ponto de um comando.
 *
 * ⚠️ ENVIO É "LOTE AVANÇADO" COM UM NÚMERO SÓ, nunca o "simples". Só o avançado
 * aceita `referencia` POR NÚMERO, e é ela que o webhook devolve em
 * `referencia_numero` — sem isso não há como casar o retorno com a linha certa
 * de `sms_commands`.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Logger.php';
require_once __DIR__ . '/crypto.php';

/** Base da API. Sem barra final. */
const SMS_API_BASE = 'https://painel.allcancesms.com.br/v2/api';

/** Código do serviço SMS TRANSACIONAL na tabela da Allcance. */
const SMS_COD_SERVICO_TRANSACIONAL = 11;

/** Nome do serviço como ele aparece em GET /creditos. */
const SMS_SERVICO_SALDO = 'SMS TRANSACIONAL';

/**
 * Teto de caracteres de um SMS único (GSM-7).
 *
 * ⚠️ Acima disso a operadora PARTE a mensagem em várias, e o equipamento recebe
 * meio comando — que ele não reconhece e descarta em silêncio. Nenhum comando
 * do catálogo chega perto disso, mas `APN` com os 14 campos e uma `UPDATE,<url>`
 * de URL longa podem; por isso a guarda existe antes do POST, não depois.
 */
const SMS_MAX_CHARS = 160;

// ── Configuração ────────────────────────────────────────────────────────────

/**
 * Lê a linha de configuração da conta SMS.
 *
 * Nesta fase o escopo é sempre global (customer_id NULL); a coluna existe para
 * a evolução conta-por-cliente não exigir migração.
 *
 * @param PDO|null $db Conexão (opcional — abre a própria se omitida)
 * @returns array|null Linha de sms_settings, ou null se não configurada
 */
function sms_settings_row(?PDO $db = null): ?array
{
    $db = $db ?: Database::getInstance()->getConnection();
    try {
        $row = $db->query(
            "SELECT * FROM sms_settings WHERE customer_id IS NULL LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        // Tabela ausente = migração não rodou. Não é erro de programa.
        Logger::warning('SMS: sms_settings indisponível', ['erro' => $e->getMessage()]);
        return null;
    }
}

/**
 * Resolve as credenciais em uso.
 *
 * Precedência banco → .env, o mesmo desenho de mail_config(). O `.env` é
 * FALLBACK de primeira instalação: assim que a tela grava, o banco vence.
 *
 * ⚠️ A lição do `from_name` (v4.9.4) vale aqui: trocar o literal no código não
 * muda nada enquanto existir linha no banco. Para mudar a conta em uso, mexa
 * em /config-sms.
 *
 * @param PDO|null $db Conexão
 * @returns array{user:string,pass:string,cod_servico:int,ativo:bool,origem:string}
 */
function sms_config(?PDO $db = null): array
{
    env_load();
    $row = sms_settings_row($db);

    if ($row && trim((string)$row['username']) !== '') {
        return [
            'user'        => (string)$row['username'],
            'pass'        => app_decrypt($row['password_enc'] ?? null),
            'cod_servico' => (int)($row['cod_servico'] ?: SMS_COD_SERVICO_TRANSACIONAL),
            'ativo'       => (int)$row['is_active'] === 1,
            'origem'      => 'banco',
        ];
    }

    return [
        'user'        => (string)(getenv('SMS_USER') ?: ''),
        'pass'        => (string)(getenv('SMS_PASS') ?: ''),
        'cod_servico' => (int)(getenv('SMS_COD_SERVICO') ?: SMS_COD_SERVICO_TRANSACIONAL),
        'ativo'       => true,
        'origem'      => 'env',
    ];
}

// ── HTTP ────────────────────────────────────────────────────────────────────

/**
 * Executa uma chamada à API e devolve o resultado já decodificado.
 *
 * Devolve estrutura uniforme em vez de lançar: o chamador é sempre uma tela ou
 * um webhook, e ambos precisam mostrar/registrar o motivo em vez de estourar.
 *
 * @param string      $metodo  'GET' | 'POST'
 * @param string      $caminho Caminho após SMS_API_BASE (ex.: '/creditos')
 * @param array|null  $body    Corpo JSON (POST)
 * @param string|null $bearer  Token, quando a rota exige
 * @returns array{ok:bool,http:int,json:array|null,raw:string|null,erro:string|null}
 */
function sms_http(string $metodo, string $caminho, ?array $body = null, ?string $bearer = null): array
{
    $url     = SMS_API_BASE . $caminho;
    $headers = ['Accept: application/json'];
    if ($bearer !== null && $bearer !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearer;
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ];
    if ($metodo === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_UNESCAPED_UNICODE);
        $headers[]                = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;

    $ch = curl_init($url);
    curl_setopt_array($ch, $opts);
    $raw   = curl_exec($ch);
    $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr  = curl_error($ch);
    curl_close($ch);

    if ($cerr !== '' || $http === 0) {
        return ['ok' => false, 'http' => $http, 'json' => null, 'raw' => $raw ?: null,
                'erro' => 'Provedor de SMS inacessível: ' . ($cerr ?: "HTTP $http")];
    }

    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'ok'   => ($http >= 200 && $http < 300),
        'http' => $http,
        'json' => is_array($json) ? $json : null,
        'raw'  => is_string($raw) ? $raw : null,
        'erro' => null,
    ];
}

// ── Token ───────────────────────────────────────────────────────────────────

/**
 * Devolve um Bearer válido, do cache ou de um login novo.
 *
 * O token vale 3600 s e não há refresh; renovamos com 5 min de margem para que
 * uma requisição iniciada perto do limite não expire no meio.
 *
 * @param PDO|null $db    Conexão
 * @param bool     $forca Ignora o cache e faz login novo
 * @returns array{ok:bool,token:string|null,erro:string|null}
 */
function sms_token(?PDO $db = null, bool $forca = false): array
{
    $db  = $db ?: Database::getInstance()->getConnection();
    $cfg = sms_config($db);

    if ($cfg['user'] === '' || $cfg['pass'] === '') {
        return ['ok' => false, 'token' => null,
                'erro' => 'Conta de SMS não configurada — cadastre em Cadastros › SMS (Allcance).'];
    }

    if (!$forca) {
        $row = sms_settings_row($db);
        if ($row && !empty($row['token']) && !empty($row['token_expires_at'])) {
            // Margem de 300 s: token que expira no meio da chamada seguinte é
            // erro difícil de ler (401 num lugar que nunca dá 401).
            $exp = strtotime((string)$row['token_expires_at'] . ' UTC');
            if ($exp !== false && $exp - 300 > time()) {
                return ['ok' => true, 'token' => (string)$row['token'], 'erro' => null];
            }
        }
    }

    $r = sms_http('POST', '/login', ['username' => $cfg['user'], 'password' => $cfg['pass']]);
    if ($r['erro'] !== null) {
        return ['ok' => false, 'token' => null, 'erro' => $r['erro']];
    }

    $token = $r['json']['token'] ?? null;
    if (!$r['ok'] || !$token) {
        // 400 error_validate = usuário/senha rejeitados. 422 = campo ausente
        // (não deveria acontecer daqui, mas a mensagem ajuda a diagnosticar).
        $msg = $r['json']['message'] ?? ($r['http'] === 422 ? 'campos obrigatórios ausentes' : 'credencial recusada');
        Logger::error('SMS: login falhou', ['http' => $r['http'], 'msg' => $msg]);
        return ['ok' => false, 'token' => null,
                'erro' => 'Não foi possível autenticar no provedor de SMS (' . $msg . ').'];
    }

    // Validade real vem do próprio JWT quando legível; 3600 s é o observado.
    $validade = 3600;
    $partes   = explode('.', (string)$token);
    if (count($partes) === 3) {
        $payload = json_decode(base64_decode(strtr($partes[1], '-_', '+/')) ?: '', true);
        if (is_array($payload) && isset($payload['exp'], $payload['iat'])) {
            $calc = (int)$payload['exp'] - (int)$payload['iat'];
            if ($calc > 60 && $calc < 86400) $validade = $calc;
        }
    }

    try {
        $db->prepare("
            UPDATE sms_settings
               SET token = :t,
                   token_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL :v SECOND)
             WHERE customer_id IS NULL
        ")->execute([':t' => $token, ':v' => $validade]);
    } catch (PDOException $e) {
        // Cache é otimização: falhar aqui não impede o uso do token recém-obtido.
        Logger::warning('SMS: não foi possível cachear o token', ['erro' => $e->getMessage()]);
    }

    return ['ok' => true, 'token' => (string)$token, 'erro' => null];
}

// ── Saldo ───────────────────────────────────────────────────────────────────

/**
 * Consulta o saldo do serviço SMS TRANSACIONAL.
 *
 * ⚠️ A doc manda "desconsiderar caracteres após o ponto": a API devolve
 * `"10.000"`, que são DEZ créditos, não dez mil. Ler como float e arredondar
 * daria 10.0 por acaso neste exemplo e 6789.388 em `"6789388.000"` — o ponto
 * NÃO é separador decimal de verdade. Cortamos a string no ponto.
 *
 * @param PDO|null $db Conexão
 * @returns array{ok:bool,saldo:int|null,servicos:array,erro:string|null}
 */
function sms_saldo(?PDO $db = null): array
{
    $db = $db ?: Database::getInstance()->getConnection();

    $tk = sms_token($db);
    if (!$tk['ok']) {
        return ['ok' => false, 'saldo' => null, 'servicos' => [], 'erro' => $tk['erro']];
    }

    $r = sms_http('GET', '/creditos', null, $tk['token']);

    // 401 com token cacheado = token revogado antes da hora. Uma única
    // retentativa com login novo, para não exigir intervenção manual.
    if ($r['http'] === 401) {
        $tk = sms_token($db, true);
        if (!$tk['ok']) {
            return ['ok' => false, 'saldo' => null, 'servicos' => [], 'erro' => $tk['erro']];
        }
        $r = sms_http('GET', '/creditos', null, $tk['token']);
    }

    if ($r['erro'] !== null || !$r['ok'] || !is_array($r['json'])) {
        return ['ok' => false, 'saldo' => null, 'servicos' => [],
                'erro' => $r['erro'] ?: 'O provedor não devolveu o saldo (HTTP ' . $r['http'] . ').'];
    }

    $saldo = null;
    $lista = [];
    foreach ($r['json'] as $item) {
        if (!is_array($item) || !isset($item['servico'])) continue;
        $nome  = (string)$item['servico'];
        $bruto = (string)($item['credito'] ?? '0');
        $inteiro = (int)strtok($bruto, '.');   // corta no ponto, não arredonda
        $lista[$nome] = $inteiro;
        if (strcasecmp($nome, SMS_SERVICO_SALDO) === 0) {
            $saldo = $inteiro;
        }
    }

    if ($saldo === null) {
        return ['ok' => false, 'saldo' => null, 'servicos' => $lista,
                'erro' => 'A conta não tem o serviço "' . SMS_SERVICO_SALDO . '" habilitado.'];
    }

    return ['ok' => true, 'saldo' => $saldo, 'servicos' => $lista, 'erro' => null];
}

// ── Número de destino ───────────────────────────────────────────────────────

/**
 * Normaliza um MSISDN para o formato que a Allcance aceita.
 *
 * 🔴 ESTE É O PONTO FRÁGIL DO CANAL. `sim_cards.msisdn` é `varchar(20)` de texto
 * livre, preenchido à mão: na base real convivem `+55 37 99936-8807`,
 * `5537999368807`, `(37) 99936-8807` e `37999368807`. Os exemplos da API são
 * `37999368807` e `3132311301` — DDD + número, **sem o código do país**.
 *
 * ⚠️ Mandar o formato errado NÃO devolve erro: a API aceita, cobra o crédito e
 * a mensagem nunca chega. Por isso a normalização é função nomeada e testada
 * (tests/helpers/sms_webhook.test.php), não um `preg_replace` solto no handler.
 *
 * Regra: só dígitos; tira o `55` do país quando o resto fica com 10 ou 11
 * dígitos (o que distingue "55" de país do "55" que é DDD de Caxias do Sul —
 * `5599999999` tem 10 dígitos e é DDD 55, e é preservado).
 *
 * @param string|null $bruto Valor como está no cadastro
 * @returns string|null Número normalizado, ou null se não for utilizável
 */
function sms_normalizar_msisdn(?string $bruto): ?string
{
    $d = preg_replace('/\D+/', '', (string)$bruto) ?? '';
    if ($d === '') return null;

    // Prefixo internacional explícito (+55 vira 55 aqui): 12 ou 13 dígitos.
    if (strlen($d) >= 12 && strncmp($d, '55', 2) === 0) {
        $resto = substr($d, 2);
        if (strlen($resto) === 10 || strlen($resto) === 11) {
            $d = $resto;
        }
    }

    // DDD (2) + assinante (8 fixo / 9 móvel).
    if (strlen($d) !== 10 && strlen($d) !== 11) return null;
    // DDD brasileiro válido começa em 11.
    if ((int)substr($d, 0, 2) < 11) return null;

    return $d;
}

// ── Envio ───────────────────────────────────────────────────────────────────

/**
 * Gera uma referência única para correlacionar envio ↔ webhook.
 *
 * 🔴 NÃO usar o id da linha: a doc é explícita — "ID CONTROLE NUNCA PODE SE
 * REPETIR" — e isso vale para a conta inteira, para sempre. Um banco
 * reinstalado reciclaria ids, e o webhook casaria a resposta com o comando
 * errado de meses atrás.
 *
 * @returns string 32 hex
 */
function sms_nova_referencia(): string
{
    return bin2hex(random_bytes(16));
}

/**
 * Envia um comando por SMS.
 *
 * Usa o "Envio em Lote Avançado" com UM número — só ele aceita `referencia` por
 * número, que é o que o webhook devolve em `referencia_numero`.
 *
 * @param string   $msisdn     Número JÁ normalizado
 * @param string   $texto      A string do comando (forma de plataforma)
 * @param string   $refNumero  Referência do número (chave do webhook)
 * @param string   $refCamp    Referência da campanha
 * @param string   $titulo     Título da campanha (aparece no painel da Allcance)
 * @param PDO|null $db         Conexão
 * @returns array{ok:bool,status:string,http:int,json:array|null,ref_campanha:string|null,erro:string|null}
 */
function sms_enviar(
    string $msisdn,
    string $texto,
    string $refNumero,
    string $refCamp,
    string $titulo,
    ?PDO $db = null
): array {
    $db  = $db ?: Database::getInstance()->getConnection();
    $cfg = sms_config($db);

    $falha = fn(string $status, string $erro, int $http = 0, ?array $json = null) => [
        'ok' => false, 'status' => $status, 'http' => $http,
        'json' => $json, 'ref_campanha' => null, 'erro' => $erro,
    ];

    if (!$cfg['ativo']) {
        return $falha('falha_envio', 'O canal de SMS está desativado em Cadastros › SMS (Allcance).');
    }
    if (mb_strlen($texto) > SMS_MAX_CHARS) {
        return $falha('falha_envio', 'Comando com ' . mb_strlen($texto) . ' caracteres — acima do limite de '
            . SMS_MAX_CHARS . '. A operadora partiria a mensagem e o equipamento receberia meio comando.');
    }

    $tk = sms_token($db);
    if (!$tk['ok']) return $falha('falha_envio', $tk['erro']);

    $body = [
        'cod_servico' => (string)$cfg['cod_servico'],
        'titulo'      => $titulo,
        'referencia'  => $refCamp,
        'numeros'     => [[
            'numero'     => $msisdn,
            'texto'      => $texto,
            'referencia' => $refNumero,
        ]],
    ];

    $r = sms_http('POST', '/campanhas', $body, $tk['token']);

    if ($r['http'] === 401) {
        $tk = sms_token($db, true);
        if (!$tk['ok']) return $falha('falha_envio', $tk['erro']);
        $r = sms_http('POST', '/campanhas', $body, $tk['token']);
    }

    if ($r['erro'] !== null) {
        return $falha('falha_envio', $r['erro'], $r['http'], $r['json']);
    }

    $status = (string)($r['json']['status'] ?? '');

    // 406 error_validate_credit é o caso REAL de saldo zero — merece estado
    // próprio, senão vira "falha genérica" e ninguém sabe que é só recarregar.
    if ($status === 'error_validate_credit') {
        $cred = $r['json']['creditos'] ?? 0;
        return $falha('sem_saldo',
            'Crédito insuficiente na conta de SMS (saldo: ' . (int)$cred . ').', $r['http'], $r['json']);
    }

    if (!$r['ok'] || $status !== 'success') {
        $msg = $r['json']['message'] ?? $r['json']['mensagem'] ?? null;
        if ($msg === null && isset($r['json']['errors'])) {
            $msg = 'validação: ' . json_encode($r['json']['errors'], JSON_UNESCAPED_UNICODE);
        }
        return $falha('falha_envio',
            'O provedor recusou o envio (HTTP ' . $r['http'] . ($msg ? ' — ' . $msg : '') . ').',
            $r['http'], $r['json']);
    }

    return [
        'ok'           => true,
        'status'       => 'enviado',
        'http'         => $r['http'],
        'json'         => $r['json'],
        'ref_campanha' => (string)($r['json']['referencia_campanha'] ?? $refCamp),
        'erro'         => null,
    ];
}

// ── Exibição ────────────────────────────────────────────────────────────────

/**
 * Traduz o status cru da Allcance para rótulo e nível visual.
 *
 * A tradução é na EXIBIÇÃO, nunca na gravação — a coluna guarda o texto cru do
 * provedor (mesma disciplina de alarm_category_label()). Status desconhecido
 * volta como veio, em vez de virar "—": provedor pode criar estado novo, e
 * esconder isso atrasaria o diagnóstico.
 *
 * @param string|null $cru Valor de sms_commands.status_entrega
 * @returns array{rotulo:string,nivel:string} nivel: ok | erro | aguardando | neutro
 */
function sms_status_label(?string $cru): array
{
    $s = mb_strtolower(trim((string)$cru));
    if ($s === '') return ['rotulo' => 'aguardando retorno', 'nivel' => 'aguardando'];

    $mapa = [
        'entregue celular'   => ['Entregue no aparelho',      'ok'],
        'recebido'           => ['Recebido',                  'ok'],
        'entregue operadora' => ['Entregue à operadora',      'aguardando'],
        'enviado'            => ['Enviado',                   'aguardando'],
        'cancelado'          => ['Cancelado',                 'erro'],
        'duplicado'          => ['Duplicado (já no mailing)', 'erro'],
        'saldo insuficiente' => ['Saldo insuficiente',        'erro'],
        'número inválido'    => ['Número inválido',           'erro'],
        'numero invalido'    => ['Número inválido',           'erro'],
        'expired'            => ['Expirado (aparelho indisponível)', 'erro'],
        'lista negra'        => ['Lista negra',               'erro'],
        'message_text_invalid' => ['Bloqueado (restrição Anatel/Procon ou conteúdo)', 'erro'],
        'não entregue'       => ['Não entregue',              'erro'],
        'nao entregue'       => ['Não entregue',              'erro'],
    ];

    return isset($mapa[$s])
        ? ['rotulo' => $mapa[$s][0], 'nivel' => $mapa[$s][1]]
        : ['rotulo' => $cru, 'nivel' => 'neutro'];
}
