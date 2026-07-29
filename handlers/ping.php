<?php
/**
 * JIMI Webhook System — Verificação de Saúde
 * Endpoint: /ping
 *
 * Sonda de liveness usada pelo `scripts/deploy.sh` (FASE 4) e por
 * monitoração externa. Responde sempre 200 com a versão em execução.
 *
 * DELIBERADAMENTE não abre conexão com o banco: o /ping precisa continuar
 * respondendo quando o MySQL cai, senão deixa de distinguir "aplicação
 * morta" de "banco fora" — que é justamente o que a sonda existe para
 * separar. Por isso a versão é lida direto do .env, com um parser mínimo,
 * em vez de via Database::getInstance().
 *
 * Até a v4.4.1 o campo `version` era a string fixa "2.0.0", herdada da
 * primeira versão do arquivo e nunca atualizada — o endpoint reportava uma
 * versão que não existia mais havia dois anos de releases.
 */

if (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

/**
 * Lê SYSTEM_VERSION sem conectar ao banco.
 *
 * Ordem: variável de ambiente já carregada (caso o .env tenha sido lido
 * antes nesta request) → parse direto do .env → 'desconhecida'.
 *
 * @returns string
 */
function ping_system_version(): string
{
    $env = getenv('SYSTEM_VERSION');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $envFile = __DIR__ . '/../.env';
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            if (trim($k) === 'SYSTEM_VERSION') {
                $v = trim($v);
                if ($v !== '') {
                    return $v;
                }
            }
        }
    }

    return 'desconhecida';
}

echo json_encode([
    'code'      => 0,
    'message'   => 'pong',
    'version'   => ping_system_version(),
    'timestamp' => date('Y-m-d H:i:s'),
]);
