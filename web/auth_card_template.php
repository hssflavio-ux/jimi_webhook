<?php
/**
 * bycamera — Card de autenticação fora do dashboard v4.13.21
 *
 * Shell das telas que existem ANTES (ou à margem) do painel: `/esqueci-senha`
 * e `/trocar-senha`. Mesmo cartão do login — logo, título, subtítulo, alerta,
 * corpo, rodapé com a versão — sem sidebar nem menu: a de troca obrigatória
 * porque o usuário não pode navegar para lugar nenhum enquanto não trocar a
 * senha, e a de recuperação porque não há sessão.
 *
 * Uso (o corpo vem por output buffering, para o formulário continuar sendo
 * PHP+HTML normal em vez de string concatenada):
 *
 *   ob_start(); ?>
 *   <form method="post"> … </form>
 *   <?php $auth_body = ob_get_clean();
 *   $auth_page_title = 'bycamera — Trocar senha';
 *   $auth_heading    = 'Defina sua senha';
 *   $auth_sub        = 'Sua senha atual é temporária.';
 *   include __DIR__ . '/../web/auth_card_template.php';
 *
 * ⚠️ `web/login_template.php` NÃO passou a usar este arquivo — ele continua
 * com a própria cópia do CSS. A extração foi deixada de fora de propósito na
 * v4.13.21: a tela de login é a única porta do sistema e não há como exercê-la
 * nesta máquina (a suíte precisa de banco), então o ganho de tirar a
 * duplicação não paga o risco de derrubá-la sem conseguir verificar. Fica
 * anotado como pendência — a lição de `web/components/map_assets.php` (10
 * cópias do mesmo tileLayer) vale aqui também.
 */
$auth_error   = $auth_error   ?? null;
$auth_success = $auth_success ?? null;
$auth_body    = $auth_body    ?? '';
$auth_links   = $auth_links   ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= htmlspecialchars($auth_page_title ?? 'bycamera') ?></title>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0052ff">
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" sizes="192x192" href="/assets/icons/icon-192.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* Coinbase Design System (v4.0.0) — azul #0052ff, canvas branco, geometria pill */
:root {
    --primary:#0052ff;--primary-active:#003ecc;--ink:#0a0b0d;--body:#5b616e;
    --muted:#7c828a;--muted-soft:#a8acb3;--canvas:#ffffff;--surface:#ffffff;
    --surface-soft:#f7f7f7;--surface-strong:#eef0f3;
    --hairline:#dee1e6;--hairline-soft:#eef0f3;--error:#cf202f;--success:#05b169;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--surface-soft);color:var(--body);min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-card{background:var(--surface);border:1px solid var(--hairline);border-radius:24px;padding:40px;width:100%;max-width:400px}
.logo{display:flex;justify-content:center;margin-bottom:24px}
.logo img{width:100%;height:auto;display:block}
h1{font-size:28px;font-weight:400;color:var(--ink);margin-bottom:6px;letter-spacing:-.5px;text-align:center}
.sub{font-size:14px;color:var(--muted);margin-bottom:28px;text-align:center}
.fg{margin-bottom:16px}
label{display:block;font-size:12px;font-weight:600;color:var(--ink);margin-bottom:6px;letter-spacing:.2px}
input[type="email"],input[type="password"]{
    width:100%;padding:13px 16px;font-size:15px;font-family:'Inter',sans-serif;
    border:1px solid var(--hairline);border-radius:12px;color:var(--ink);background:var(--canvas);
    transition:border-color .15s,box-shadow .15s
}
input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 1px var(--primary)}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:14px 24px;font-size:16px;font-weight:600;
    font-family:'Inter',sans-serif;border:none;border-radius:100px;cursor:pointer;transition:background .15s;width:100%}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-active)}
.alert{padding:12px 16px;border-radius:12px;font-size:14px;margin-bottom:20px}
.alert-error{background:#fdeaec;color:var(--error);border:1px solid #f5c2c7}
.alert-success{background:#e8f8f1;color:#04794a;border:1px solid #b7e8d1}
.hint{font-size:12px;color:var(--muted);margin-top:-8px;margin-bottom:16px;line-height:1.5}
.links{text-align:center;margin-top:20px;font-size:13px}
.links a{color:var(--primary);text-decoration:none;font-weight:500}
.links a:hover{text-decoration:underline}
.footer{text-align:center;margin-top:24px;font-size:12px;color:var(--muted-soft)}
.footer span{font-family:'JetBrains Mono',monospace}
@media (max-width:480px){
    body{align-items:flex-start;padding:16px;padding-top:max(24px,env(safe-area-inset-top))}
    .login-card{max-width:100%;padding:28px 20px;border-radius:16px;margin-top:6vh}
    h1{font-size:24px}
    input[type="email"],input[type="password"]{min-height:48px;font-size:16px}/* 16px evita zoom no iOS */
    .btn{min-height:48px}
}
</style>
</head>
<body>
<div class="login-card">
    <div class="logo">
        <img src="/web/assets/logo-login.png" alt="bycamera — videomonitoramento inteligente">
    </div>
    <h1><?= htmlspecialchars($auth_heading ?? '') ?></h1>
    <p class="sub"><?= htmlspecialchars($auth_sub ?? '') ?></p>

    <?php if ($auth_error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($auth_error) ?></div>
    <?php endif; ?>
    <?php if ($auth_success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($auth_success) ?></div>
    <?php endif; ?>

    <?= $auth_body ?>

    <?php if ($auth_links): ?>
    <div class="links"><?= $auth_links ?></div>
    <?php endif; ?>

    <div class="footer">
        <span>v<?= getenv('SYSTEM_VERSION') ?: '4.0.0' ?></span>
    </div>
</div>
</body>
</html>
