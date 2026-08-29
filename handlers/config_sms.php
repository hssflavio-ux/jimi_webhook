<?php
/**
 * bycamera — Conta de SMS (Allcance) v4.14.0
 * Rota: /config-sms
 *
 * Cadastro das credenciais da conta de SMS usada pela tela /comandos-sms, e o
 * segredo do webhook de retorno.
 *
 * ESCOPO GLOBAL. Diferente do /config-smtp, aqui há UMA conta para a plataforma
 * inteira (decisão do dono do produto, 29/08/2026): o custo do SMS é da
 * operação, não rateado por cliente. A coluna `customer_id` existe em
 * `sms_settings` para a evolução conta-por-cliente não exigir migração, mas
 * nesta fase é sempre NULL.
 *
 * `require_admin()` — não basta a matriz de permissão. `can()` devolve **true**
 * para usuário sem grupo (permissivo por omissão), e esta tela grava credencial
 * de terceiro e revela o segredo do webhook. Mesma trava e mesma razão de
 * /firmwares, /parametros e /configuracoes-ia.
 *
 * A senha é gravada cifrada (AES-256-GCM) e NUNCA volta para o navegador: o
 * campo aparece vazio e, se ficar vazio ao salvar, a senha atual é preservada.
 *
 * POST action=salvar   → grava credenciais
 * POST action=testar   → autentica e lê o saldo com o que está gravado
 * POST action=segredo  → gera um segredo de webhook novo
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/crypto.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/sms_gateway.php';
require_login();
require_admin();

$db      = Database::getInstance()->getConnection();
$user    = get_jimi_user();
$message = '';
$messageType = '';
$tableMissing = false;
$saldoTeste = null;

/**
 * Garante que exista a linha global, para os UPDATEs seguintes terem alvo.
 *
 * @param PDO $db Conexão
 * @returns void
 */
function sms_settings_ensure_row(PDO $db): void
{
    // O INSERT IGNORE sozinho basta: `customer_key` é coluna gerada
    // (IFNULL(customer_id,0)) com UNIQUE, então a segunda tentativa de criar a
    // linha global colide e é ignorada. É por isso que a coluna gerada existe —
    // sem ela o MySQL trataria os NULLs como distintos e criaria duplicatas.
    $db->exec("INSERT IGNORE INTO sms_settings (customer_id, username, is_active)
               VALUES (NULL, '', 1)");
}

// ── POST ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'salvar';

    try {
        sms_settings_ensure_row($db);

        if ($action === 'salvar') {
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            if ($username === '') {
                throw new RuntimeException('O usuário da API é obrigatório.');
            }

            // Senha em branco = "não mexer". É o que permite editar o usuário
            // ou o switch de ativo sem redigitar a senha toda vez.
            $sets   = ['username = :u', 'is_active = :a', 'updated_by = :ub'];
            $params = [':u' => $username, ':a' => $isActive, ':ub' => $user['id'] ?? null];

            if ($password !== '') {
                $enc = app_encrypt($password);
                if ($enc === null) {
                    throw new RuntimeException(
                        'Não há APP_KEY nem WEBHOOK_TOKEN definidos — sem chave a senha não pode ser cifrada.');
                }
                $sets[] = 'password_enc = :p';
                $params[':p'] = $enc;
                // Credencial nova invalida o Bearer em cache: senão o sistema
                // seguiria usando o token da conta ANTIGA até ele expirar.
                $sets[] = 'token = NULL';
                $sets[] = 'token_expires_at = NULL';
            }

            $db->prepare("UPDATE sms_settings SET " . implode(', ', $sets)
                        . " WHERE customer_id IS NULL")->execute($params);

            $message = 'Credenciais gravadas.';
            $messageType = 'success';

        } elseif ($action === 'segredo') {
            // Segredo novo invalida a URL antiga — que continua cadastrada na
            // Allcance. Por isso o aviso explícito na tela.
            $novo = bin2hex(random_bytes(24));
            $db->prepare("UPDATE sms_settings SET webhook_secret = :s WHERE customer_id IS NULL")
               ->execute([':s' => $novo]);
            $message = 'Segredo do webhook gerado. Atualize a URL no painel da Allcance — a anterior parou de valer.';
            $messageType = 'success';

        } elseif ($action === 'testar') {
            // Login novo de propósito (ignora o cache): o ponto do teste é
            // provar que a CREDENCIAL vale agora, não que sobrou token velho.
            $tk = sms_token($db, true);
            if (!$tk['ok']) {
                throw new RuntimeException($tk['erro']);
            }
            $s = sms_saldo($db);
            if (!$s['ok']) {
                throw new RuntimeException($s['erro']);
            }
            $saldoTeste = $s;
            $message = 'Autenticou. Saldo de ' . SMS_SERVICO_SALDO . ': ' . $s['saldo'] . '.';
            $messageType = 'success';
            $db->prepare("UPDATE sms_settings
                             SET last_test_at = UTC_TIMESTAMP(), last_test_ok = 1, last_test_error = NULL
                           WHERE customer_id IS NULL")->execute();
        }

    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'sms_settings')) {
            $tableMissing = true;
            $message = 'A tabela sms_settings não existe — a migração v4.14.0 ainda não foi aplicada.';
        } else {
            $message = 'Erro: ' . $e->getMessage();
        }
        $messageType = 'error';
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $messageType = 'error';
        if ($action === 'testar') {
            try {
                $db->prepare("UPDATE sms_settings
                                 SET last_test_at = UTC_TIMESTAMP(), last_test_ok = 0, last_test_error = :e
                               WHERE customer_id IS NULL")
                   ->execute([':e' => mb_substr($e->getMessage(), 0, 500)]);
            } catch (Throwable $ignored) { /* o erro já está na tela */ }
        }
    }
}

// ── Estado atual ────────────────────────────────────────────────────────────
$row = null;
try {
    $row = sms_settings_row($db);
} catch (Throwable $e) {
    $tableMissing = true;
}

$keySource = app_secret_key_source();
$cfg       = $tableMissing ? ['user' => '', 'origem' => 'env', 'ativo' => false] : sms_config($db);

// URL do webhook que o operador precisa cadastrar no painel da Allcance.
$appUrl  = rtrim((string)(getenv('APP_URL') ?: ''), '/');
$segredo = $row['webhook_secret'] ?? '';
$webhookUrl = ($appUrl !== '' && $segredo !== '')
    ? $appUrl . '/pushsms?k=' . $segredo
    : null;

$page_title = 'SMS (Allcance)';
require_once __DIR__ . '/../web/layout_base.php';
?>

<?php if ($message): ?>
<div class="card mb-16" style="border-left:3px solid <?= $messageType === 'success' ? '#0a7c3f' : '#b3261e' ?>;">
    <div style="font-size:13px;color:<?= $messageType === 'success' ? '#0a7c3f' : '#b3261e' ?>;">
        <?= htmlspecialchars($message) ?>
    </div>
</div>
<?php endif; ?>

<?php if ($tableMissing): ?>
<div class="card mb-16" style="border-left:3px solid #b3261e;background:#fdecea;">
    <div style="font-size:13px;color:#7a1a12;line-height:1.6;">
        <strong>A migração v4.14.0 não foi aplicada.</strong>
        Rode <code>./scripts/deploy.sh --force</code> mais uma vez, ou aplique
        <code>mysql/migration_v4.14.0.sql</code> à mão. Migração nova nunca roda no deploy que a traz.
    </div>
</div>
<?php endif; ?>

<?php if ($keySource === 'nenhuma'): ?>
<div class="card mb-16" style="border-left:3px solid #b3261e;background:#fdecea;">
    <div style="font-size:13px;color:#7a1a12;">
        <strong>Sem <code>APP_KEY</code> e sem <code>WEBHOOK_TOKEN</code>:</strong>
        a senha não pode ser cifrada e o cadastro vai falhar. Defina <code>APP_KEY</code> no <code>.env</code>
        (<code>openssl rand -hex 32</code>).
    </div>
</div>
<?php elseif ($keySource === 'webhook_token'): ?>
<div class="card mb-16" style="border-left:3px solid #a97a00;background:#fdf9ec;">
    <div style="font-size:13px;color:#7a5a00;">
        <strong>A senha será cifrada com o <code>WEBHOOK_TOKEN</code></strong> (não há <code>APP_KEY</code>).
        Funciona, mas rotacionar o token do webhook torna a senha gravada indecifrável e ela precisa ser recadastrada.
    </div>
</div>
<?php endif; ?>

<div class="card mb-16">
    <div style="font-size:12px;color:var(--muted);line-height:1.6;">
        <strong style="color:var(--ink);">Em uso agora:</strong>
        <?php if (($cfg['user'] ?? '') === ''): ?>
            nenhuma conta configurada — a tela de Comandos por SMS não vai conseguir enviar nada.
        <?php else: ?>
            <span class="text-mono"><?= htmlspecialchars($cfg['user']) ?></span>
            (origem: <?= $cfg['origem'] === 'banco' ? 'cadastro nesta tela' : 'variáveis do .env (legado)' ?>),
            serviço <span class="text-mono">SMS TRANSACIONAL</span> (código <?= (int)($cfg['cod_servico'] ?? 11) ?>)
            — <?= !empty($cfg['ativo']) ? 'ativo' : '<strong>desativado</strong>' ?>.
        <?php endif; ?>
    </div>
</div>

<div class="card mb-16" style="max-width:760px;">
    <div class="flex-between mb-24">
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Credenciais da API</h2>
        <?php if ($row && !empty($row['last_test_at'])): ?>
        <span class="badge <?= $row['last_test_ok'] ? 'badge-success' : 'badge-error' ?>">
            Último teste: <?= $row['last_test_ok'] ? 'OK' : 'falhou' ?> em <?= fmt_brt($row['last_test_at'], 'd/m/Y H:i') ?>
        </span>
        <?php endif; ?>
    </div>

    <?php if ($row && !$row['last_test_ok'] && !empty($row['last_test_error'])): ?>
    <div class="mb-16" style="font-size:12px;color:#b3261e;">
        <?= htmlspecialchars($row['last_test_error']) ?>
    </div>
    <?php endif; ?>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="salvar">

        <div class="form-group mb-16">
            <label>Usuário da API</label>
            <input type="text" name="username" autocomplete="off"
                   value="<?= htmlspecialchars($row['username'] ?? '') ?>"
                   placeholder="conta@exemplo.com">
            <div style="font-size:12px;color:var(--muted);margin-top:4px;">
                É o <strong>e-mail</strong> da conta Allcance — a API não tem login separado do painel.
            </div>
        </div>

        <div class="form-group mb-16">
            <label>Senha</label>
            <input type="password" name="password" autocomplete="new-password"
                   placeholder="<?= !empty($row['password_enc']) ? '•••••••• (gravada — deixe em branco para manter)' : 'senha da conta' ?>">
            <div style="font-size:12px;color:var(--muted);margin-top:4px;">
                Gravada cifrada (AES-256-GCM) e nunca devolvida ao navegador.
                Em branco = mantém a atual. <strong>Diferencia maiúsculas de minúsculas.</strong>
            </div>
        </div>

        <div class="form-group mb-24">
            <label style="display:flex;align-items:center;gap:8px;font-size:14px;cursor:pointer;">
                <input type="checkbox" name="is_active" value="1" <?= (!$row || $row['is_active']) ? 'checked' : '' ?>>
                Canal de SMS ativo
            </label>
        </div>

        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary">Salvar</button>
        </div>
    </form>

    <hr style="margin:24px 0;border:none;border-top:1px solid var(--hairline);">

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="testar">
        <button type="submit" class="btn btn-outline">Testar credencial e ler saldo</button>
        <span style="font-size:12px;color:var(--muted);margin-left:8px;">
            Autentica de novo (ignora o token em cache) e consulta os créditos. Não envia SMS.
        </span>
    </form>

    <?php if ($saldoTeste && !empty($saldoTeste['servicos'])): ?>
    <div class="mt-16" style="font-size:12px;color:var(--muted);">
        <strong style="color:var(--ink);">Saldos da conta:</strong>
        <?php foreach ($saldoTeste['servicos'] as $nome => $qtd): ?>
            <div style="display:flex;justify-content:space-between;max-width:340px;padding:2px 0;">
                <span><?= htmlspecialchars($nome) ?></span>
                <span class="text-mono"><?= (int)$qtd ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="card" style="max-width:760px;">
    <h2 style="font-size:18px;font-weight:600;color:var(--ink);" class="mb-16">Webhook de retorno</h2>

    <div style="font-size:12px;color:var(--muted);line-height:1.6;" class="mb-16">
        A Allcance envia aqui, em tempo real, o status de entrega de cada SMS <strong>e a resposta
        que o equipamento devolve</strong>. Sem isso a tela mostra "enviado" e nunca mais nada.
        <br><br>
        <strong style="color:var(--ink);">Este endereço tem de ser cadastrado no painel da Allcance</strong>
        — não há endpoint de API para configurá-lo. É passo manual, e se a conta for
        reprovisionada ele some sem avisar.
    </div>

    <?php if ($webhookUrl): ?>
    <div class="mb-16">
        <label>URL para cadastrar</label>
        <input type="text" class="text-mono" readonly onclick="this.select()"
               value="<?= htmlspecialchars($webhookUrl) ?>">
        <div style="font-size:12px;color:var(--muted);margin-top:4px;">
            O parâmetro <code>k</code> é a única defesa deste endpoint: a Allcance não envia
            cabeçalho de autenticação nenhum.
        </div>
    </div>
    <?php elseif ($appUrl === ''): ?>
    <div class="mb-16" style="font-size:13px;color:#b3261e;">
        <strong><code>APP_URL</code> não está definida no <code>.env</code></strong> — sem ela não dá para
        montar a URL do webhook.
    </div>
    <?php else: ?>
    <div class="mb-16" style="font-size:13px;color:#a97a00;">
        Nenhum segredo gerado ainda. Gere um para obter a URL.
    </div>
    <?php endif; ?>

    <form method="post" onsubmit="return confirm('Gerar um segredo novo invalida a URL já cadastrada na Allcance. Continuar?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="segredo">
        <button type="submit" class="btn btn-outline">
            <?= $segredo === '' ? 'Gerar segredo' : 'Gerar segredo novo' ?>
        </button>
    </form>
</div>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
