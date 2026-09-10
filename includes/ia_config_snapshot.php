<?php
/**
 * bycamera — Perfil de leitura completa de IA (device_ia_config_snapshots) v1.0
 *
 * Ponto único de leitura/escrita de `device_ia_config_snapshots` — o conjunto
 * de comandos ADAS/DMS/velocidade (catálogo de `includes/ia_config_catalog.php`)
 * já com os valores lidos da própria câmera ao final de um "Ler tudo agora"
 * em /configuracoes-ia. Usado para: mostrar a data da última leitura completa,
 * reenviar o mesmo perfil a outras câmeras do MESMO MODELO, e gerar o
 * `writeconfig.txt` para download. Ver migration_v4.18.2.sql.
 */

/**
 * Grava (substitui) o perfil de leitura completa mais recente de um
 * equipamento. Um snapshot por `imei` (UNIQUE) — não é histórico.
 *
 * `$capturedAtUtc` vem do CHAMADOR (não `NOW()` do MySQL) para o handler
 * poder devolver o mesmo instante formatado em BRT na resposta JSON, sem
 * outro round-trip ao banco — sempre `gmdate('Y-m-d H:i:s')`, nunca
 * `date()` (regra do projeto: UTC no miolo, ver CLAUDE.md).
 *
 * @param PDO         $db
 * @param string      $imei
 * @param string      $modelDisplay   Modelo no momento da leitura
 * @param string      $commandsText   Comandos prontos, um por linha, sem comentário
 * @param int         $totalCatalogo  Quantos comandos o catálogo documenta para o modelo
 * @param int         $totalCapturado Quantos entraram no perfil
 * @param string      $capturedAtUtc  `gmdate('Y-m-d H:i:s')` do instante da leitura
 * @param int|null    $userId
 * @param int|null    $customerId
 * @returns void
 * @throws Throwable se a gravação falhar (chamador decide como responder)
 */
function ia_snapshot_save(
    PDO $db,
    string $imei,
    string $modelDisplay,
    string $commandsText,
    int $totalCatalogo,
    int $totalCapturado,
    string $capturedAtUtc,
    ?int $userId,
    ?int $customerId
): void {
    $db->prepare("
        INSERT INTO device_ia_config_snapshots
            (imei, model_display, captured_at, total_catalogo, total_capturado, commands_text, created_by, customer_id)
        VALUES (:imei, :modelo, :capturado, :tot_cat, :tot_cap, :texto, :uid, :cid)
        ON DUPLICATE KEY UPDATE
            model_display   = VALUES(model_display),
            captured_at     = VALUES(captured_at),
            total_catalogo  = VALUES(total_catalogo),
            total_capturado = VALUES(total_capturado),
            commands_text   = VALUES(commands_text),
            created_by      = VALUES(created_by),
            customer_id     = VALUES(customer_id)
    ")->execute([
        ':imei' => $imei, ':modelo' => $modelDisplay, ':capturado' => $capturedAtUtc,
        ':tot_cat' => $totalCatalogo, ':tot_cap' => $totalCapturado,
        ':texto' => $commandsText, ':uid' => $userId, ':cid' => $customerId,
    ]);
}

/**
 * Devolve o perfil salvo de um equipamento, ou null se nunca houve leitura
 * completa (ou a migração v4.18.2 ainda não rodou).
 *
 * @returns array{model_display:string,captured_at:string,total_catalogo:int,total_capturado:int,commands_text:string}|null
 */
function ia_snapshot_get(PDO $db, string $imei): ?array
{
    try {
        $st = $db->prepare("SELECT model_display, captured_at, total_catalogo, total_capturado, commands_text
                               FROM device_ia_config_snapshots WHERE imei = ?");
        $st->execute([$imei]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}
