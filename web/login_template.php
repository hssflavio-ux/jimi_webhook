<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>JIMI — Entrar</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
    --primary:#f54e00;--primary-active:#d04200;--ink:#26251e;--body:#5a5852;
    --muted:#807d72;--muted-soft:#a09c92;--canvas:#f7f7f4;--surface:#ffffff;
    --hairline:#e6e5e0;--hairline-soft:#efeee8;--error:#cf2d56;--success:#1f8a65;
    --read:#9fbbe0;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Inter',sans-serif;background:var(--canvas);color:var(--body);min-height:100vh;display:flex;align-items:center;justify-content:center}
.login-card{background:var(--surface);border:1px solid var(--hairline);border-radius:12px;padding:40px;width:100%;max-width:400px}
.logo{display:flex;align-items:center;gap:10px;margin-bottom:28px}
.logo-dots{display:flex;gap:5px}
.logo-dots span{width:9px;height:9px;border-radius:50%}
.p1{background:var(--primary)}.p2{background:#dfa88f}.p3{background:#9fc9a2}
.logo-text{font-size:18px;font-weight:600;color:var(--ink);letter-spacing:-.5px}
h1{font-size:20px;font-weight:600;color:var(--ink);margin-bottom:6px}
.sub{font-size:13px;color:var(--muted);margin-bottom:28px}
.fg{margin-bottom:16px}
label{display:block;font-size:12px;font-weight:500;color:var(--ink);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px}
input[type="email"],input[type="password"]{
    width:100%;padding:10px 12px;font-size:14px;font-family:'Inter',sans-serif;
    border:1px solid var(--hairline);border-radius:6px;color:var(--ink);background:var(--canvas);
    transition:border-color .15s
}
input:focus{outline:none;border-color:var(--primary)}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 24px;font-size:14px;font-weight:500;
    font-family:'Inter',sans-serif;border:none;border-radius:8px;cursor:pointer;transition:background .15s;width:100%}
.btn-primary{background:var(--primary);color:#fff}
.btn-primary:hover{background:var(--primary-active)}
.alert{padding:10px 14px;border-radius:6px;font-size:13px;margin-bottom:20px}
.alert-error{background:#fef2f5;color:var(--error);border:1px solid #fce4eb}
.footer{text-align:center;margin-top:24px;font-size:12px;color:var(--muted-soft)}
.footer span{font-family:'JetBrains Mono',monospace}
</style>
</head>
<body>
<div class="login-card">
    <div class="logo">
        <div class="logo-dots">
            <span class="p1"></span><span class="p2"></span><span class="p3"></span>
        </div>
        <div class="logo-text">JIMI</div>
    </div>
    <h1>Entrar no sistema</h1>
    <p class="sub">Insira suas credenciais para acessar o painel.</p>

    <?php if (!empty($error)): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect ?? '/dashboard') ?>">
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
        <span>v<?= getenv('SYSTEM_VERSION') ?: '3.1.0' ?></span>
    </div>
</div>
</body>
</html>
