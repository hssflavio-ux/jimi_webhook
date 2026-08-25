<?php
/**
 * bycamera — Estado de configuração de IA (device_ia_config_state) v1.0
 *
 * Ponto único de escrita em `device_ia_config_state` — a tabela que guarda o
 * último valor lido/aplicado, por câmera, de cada comando de
 * `includes/ia_config_catalog.php` (ADAS/DMS/velocidade). Chamado dos dois
 * pontos onde uma resposta de comando pode chegar: síncrona
 * (`handlers/sendcommand.php`, device online) e assíncrona/offline
 * (`handlers/pushinstructresponse.php`) — mesmo padrão de
 * `upsert_device_params()` para os parâmetros JT/T.
 */

/**
 * Acha a chave do catálogo de IA que corresponde ao comando enviado.
 *
 * Casa por FORMA, não por nome: um comando aplicado ('EVENTSET,ALDW,60#')
 * bate contra o template ('EVENTSET,ALDW,P1#') por posição — os tokens
 * literais têm de ser iguais, os `P<n>` casam qualquer valor. A forma de
 * CONSULTA ('DMSSW#') é comparada à parte, porque tem aridade diferente do
 * template de escrita.
 *
 * @param array  $catalog Catálogo de includes/ia_config_catalog.php
 * @param string $cmdSent Comando exatamente como foi enviado ao equipamento
 * @returns string|null Chave do catálogo, ou null se nenhum comando bate
 */
function ia_config_match_key(array $catalog, string $cmdSent): ?string
{
    $cmd = trim($cmdSent);
    if ($cmd === '') return null;
    if (substr($cmd, -1) !== '#') $cmd .= '#';

    foreach ($catalog as $key => $def) {
        if (!empty($def['consulta']) && strcasecmp($def['consulta'], $cmd) === 0) {
            return $key;
        }
    }

    $sentToks = explode(',', rtrim($cmd, '#'));
    foreach ($catalog as $key => $def) {
        $keyToks = explode(',', rtrim($key, '#'));
        if (count($keyToks) !== count($sentToks)) continue;
        $match = true;
        foreach ($keyToks as $i => $kt) {
            if (preg_match('/^P\d+$/', $kt)) continue;
            if (strcasecmp($kt, $sentToks[$i]) !== 0) { $match = false; break; }
        }
        if ($match) return $key;
    }
    return null;
}

/**
 * Grava a resposta de um comando de IA no estado da câmera, se o comando
 * enviado for um dos que `includes/ia_config_catalog.php` documenta.
 *
 * Silencioso quando não casa (a maioria dos comandos passa por aqui sem ser
 * de IA — comandos básicos de `/comandos`, JT/T, etc.) e quando a migração
 * v4.13.0 ainda não rodou (tabela ausente).
 *
 * @param PDO         $db
 * @param string      $imei
 * @param string      $cmdSent    Comando exatamente como foi enviado
 * @param string|null $response   Resposta bruta do equipamento (pode ser null/vazia)
 * @param int|null    $commandId  `commands.id` do envio
 * @returns void
 */
function ia_config_capture(PDO $db, string $imei, string $cmdSent, ?string $response, ?int $commandId = null): void
{
    static $catalog = null;
    if ($catalog === null) {
        $catalog = require __DIR__ . '/ia_config_catalog.php';
    }

    $key = ia_config_match_key($catalog, $cmdSent);
    if ($key === null) return;

    try {
        $db->prepare("
            INSERT INTO device_ia_config_state (imei, cmd_key, last_response, read_at, command_id)
            VALUES (:imei, :key, :resp, NOW(), :cid)
            ON DUPLICATE KEY UPDATE
                last_response = VALUES(last_response),
                read_at = VALUES(read_at),
                command_id = VALUES(command_id),
                requested_value = NULL,
                requested_at = NULL
        ")->execute([
            ':imei' => $imei, ':key' => $key,
            ':resp' => ($response !== null && $response !== '') ? $response : null,
            ':cid'  => $commandId,
        ]);
    } catch (Throwable $e) {
        Logger::warning('ia_config_capture: falha ao gravar estado', [
            'imei' => $imei, 'cmd' => $cmdSent, 'erro' => $e->getMessage(),
        ]);
    }
}
