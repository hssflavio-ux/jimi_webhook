<?php
/**
 * Provisiona os DOIS clientes de teste da suíte E2E — NÃO faz parte da
 * aplicação. Existe para `tests/multitenant.spec.js` e para os specs que
 * precisam de `TEST_IMEI`.
 *
 * POR QUE ISTO EXISTE. `multitenant.spec.js` está no repositório desde a Fase
 * M.4 e, até 03/08/2026, **nunca rodou uma vez**: ele pula inteiro sem
 * `TEST_EMAIL_B`, e o segundo usuário nunca foi provisionado. Foi exatamente
 * nesse ponto cego que o vazamento cross-tenant da v4.7.3 sobreviveu. Deixar o
 * provisionamento como passo manual é o que fez isso durar meses — por isso ele
 * vira script versionado e idempotente.
 *
 * ⚠️ ARMADILHA QUE ESTE SCRIPT EXISTE PARA EVITAR. O spec identifica IMEI por
 * REGEX DE DÍGITOS (`\d{15}` e `\d{10,20}`). Se o cliente B tiver só devices de
 * IMEI alfanumérico (como o `IMEIBBB000000002` do fixture antigo), o conjunto
 * de IMEIs dele volta VAZIO, e o teste "A e B não compartilham devices" passa
 * por **vacuidade** — dois conjuntos vazios não têm interseção. Por isso os dois
 * clientes recebem, obrigatoriamente, IMEI de 15 dígitos.
 *
 * Uso:
 *   php tests/helpers/seed_tenants.php aplicar
 *   php tests/helpers/seed_tenants.php conferir
 *
 * Idempotente: reexecutar não duplica nada.
 */

require_once __DIR__ . '/../../config/database.php';

const SENHA_E2E  = 'E2e-Playwright-2026';
const EMAIL_A    = 'e2e@teste.local';
const EMAIL_B    = 'operador.b@teste.local';
const IMEI_A     = '868120246598152';   // já existente no cliente A
const IMEI_B     = '869999000000002';   // 15 dígitos, exigido pelo regex do spec
const CLIENTE_B  = 'Cliente B TESTE';

function saida(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    exit(empty($d['ok']) ? 1 : 0);
}

try {
    $db = Database::getInstance()->getConnection();
    $cmd = $argv[1] ?? 'conferir';

    // ── Cliente A: o da sessão do usuário principal ──────────────────────
    $custA = (int)$db->query("SELECT customer_id FROM customer_users cu
                              JOIN users u ON u.id = cu.user_id
                              WHERE u.email = " . $db->quote(EMAIL_A) . " LIMIT 1")->fetchColumn();
    if (!$custA) saida(['ok' => false, 'erro' => 'usuário A (' . EMAIL_A . ') não existe ou não tem cliente vinculado']);

    if ($cmd === 'aplicar') {
        $db->beginTransaction();

        // ── Cliente B ────────────────────────────────────────────────────
        $stmt = $db->prepare("SELECT id FROM customers WHERE name = ? LIMIT 1");
        $stmt->execute([CLIENTE_B]);
        $custB = (int)$stmt->fetchColumn();
        if (!$custB) {
            $db->prepare("INSERT INTO customers (name, is_active) VALUES (?, 1)")->execute([CLIENTE_B]);
            $custB = (int)$db->lastInsertId();
        } else {
            $db->prepare("UPDATE customers SET is_active = 1 WHERE id = ?")->execute([$custB]);
        }
        if ($custB === $custA) saida(['ok' => false, 'erro' => 'cliente B coincide com o A — o spec não testaria isolamento nenhum']);

        // ── Usuário B (perfil `cliente`/`operator`: NÃO é admin nem revendedor,
        //     que é justamente o perfil da escalada da v4.7.3) ──────────────
        $hash = password_hash(SENHA_E2E, PASSWORD_DEFAULT);
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([EMAIL_B]);
        $userB = (int)$stmt->fetchColumn();
        if ($userB) {
            $db->prepare("UPDATE users SET password_hash=?, role='operator', user_type='cliente', is_active=1 WHERE id=?")
               ->execute([$hash, $userB]);
        } else {
            $db->prepare("INSERT INTO users (email, password_hash, name, role, user_type, is_active)
                          VALUES (?, ?, 'Operador Cliente B', 'operator', 'cliente', 1)")
               ->execute([EMAIL_B, $hash]);
            $userB = (int)$db->lastInsertId();
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM customer_users WHERE user_id=? AND customer_id=?");
        $stmt->execute([$userB, $custB]);
        if (!(int)$stmt->fetchColumn()) {
            $db->prepare("INSERT INTO customer_users (customer_id, user_id, role) VALUES (?, ?, 'operator')")
               ->execute([$custB, $userB]);
        }
        // B não pode ter vínculo com o cliente A, senão o isolamento é legítimo
        $db->prepare("DELETE FROM customer_users WHERE user_id=? AND customer_id<>?")->execute([$userB, $custB]);

        // ── Um device de 15 DÍGITOS em cada cliente (ver armadilha acima) ──
        foreach ([[IMEI_A, $custA, 'VEICULO-A-E2E'], [IMEI_B, $custB, 'VEICULO-B-E2E']] as [$imei, $cust, $nome]) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM devices WHERE imei = ?");
            $stmt->execute([$imei]);
            if ((int)$stmt->fetchColumn()) {
                $db->prepare("UPDATE devices SET customer_id=?, is_active=1 WHERE imei=?")->execute([$cust, $imei]);
            } else {
                $db->prepare("INSERT INTO devices (imei, customer_id, device_name, is_active) VALUES (?,?,?,1)")
                   ->execute([$imei, $cust, $nome]);
            }
        }

        // O rate-limit de login (5 falhas/15 min por IP) derruba a suíte inteira
        // no fixture de autenticação, e o sintoma parece bug da aplicação.
        try { $db->exec("DELETE FROM login_log WHERE success = 0"); } catch (Exception $e) {}

        $db->commit();
    }

    // ── Conferência (vale para `aplicar` e `conferir`) ────────────────────
    $stmt = $db->prepare("SELECT cu.customer_id FROM customer_users cu JOIN users u ON u.id=cu.user_id WHERE u.email=?");
    $stmt->execute([EMAIL_B]);
    $custB = (int)$stmt->fetchColumn();

    $digitos = [];
    foreach ([$custA, $custB] as $c) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM devices WHERE customer_id=? AND is_active=1 AND imei REGEXP '^[0-9]{15}$'");
        $stmt->execute([$c]);
        $digitos[$c] = (int)$stmt->fetchColumn();
    }

    $problemas = [];
    if (!$custB)              $problemas[] = 'usuário B sem cliente vinculado';
    if ($custB === $custA)    $problemas[] = 'A e B no mesmo cliente';
    if (!$digitos[$custA])    $problemas[] = "cliente A ($custA) sem device de 15 dígitos — spec passaria por vacuidade";
    if (!$digitos[$custB])    $problemas[] = "cliente B ($custB) sem device de 15 dígitos — spec passaria por vacuidade";

    saida([
        'ok'          => empty($problemas),
        'cliente_A'   => $custA,
        'cliente_B'   => $custB,
        'devices_15d' => $digitos,
        'problemas'   => $problemas,
        'env'         => [
            'TEST_EMAIL'      => EMAIL_A,
            'TEST_EMAIL_B'    => EMAIL_B,
            'TEST_PASSWORD_B' => SENHA_E2E,
            'TEST_IMEI'       => IMEI_A,
        ],
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    saida(['ok' => false, 'erro' => $e->getMessage()]);
}
