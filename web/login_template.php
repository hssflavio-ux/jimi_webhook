<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>bycamera — Entrar</title>
<!-- PWA -->
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0052ff">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="bycamera">
<link rel="apple-touch-icon" href="/assets/icons/icon-192.png">
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
/* A marca substituiu o placeholder de pontinhos + texto "JIMI" (v4.8.0).
   Aqui é o único lugar que usa o lockup COMPLETO (`logo-login.png`, com o
   descritor "videomonitoramento inteligente"): medido, o descritor ocupa
   8,6% da altura da arte (19 px de 221), então só se lê em tamanho grande —
   daí ocupar a largura útil do card inteiro (318 px → 78 px de altura), onde
   ele sai com 6,7 px. Na sidebar, que reserva 84 px de largura para a marca,
   o mesmo descritor teria 1,8 px: um borrão. */
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
.footer{text-align:center;margin-top:24px;font-size:12px;color:var(--muted-soft)}
.footer span{font-family:'JetBrains Mono',monospace}
/* Mobile: card 100% width, touch targets ≥44px, safe areas */
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
    <h1>Entrar no sistema</h1>
    <p class="sub">Insira suas credenciais para acessar o painel.</p>

    <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect ?? '/') ?>">
        <div class="fg">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autofocus autocomplete="email">
        </div>
        <div class="fg">
            <label for="password">Senha</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary">Entrar</button>
    </form>
    <div class="footer">
        <span>v<?= getenv('SYSTEM_VERSION') ?: '4.0.0' ?></span>
    </div>
</div>
</body>
</html>
