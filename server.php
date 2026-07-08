<?php
/**
 * JIMI Webhook System — Router shim para o servidor embutido do PHP
 *
 * Reproduz localmente o front controller do `.htaccess` (que só existe sob
 * Apache/mod_rewrite) para permitir desenvolvimento com o servidor embutido:
 *
 *     php -S localhost:8000 server.php
 *
 * Regra (equivalente ao .htaccess):
 *   - Assets estáticos reais (css/js/img/fontes/vídeo) → servidos direto.
 *   - Arquivos sensíveis (.env, .htaccess, .sql, .php avulso) → NÃO servidos
 *     direto; caem no front controller como qualquer rota.
 *   - Todo o resto (/, /config, /dashboard, /pushgps, ...) → handlers/router.php
 *
 * Limitações vs. produção (Apache + PHP-FPM):
 *   - `fastcgi_finish_request()` não existe no servidor embutido; o
 *     processamento assíncrono dos webhooks roda de forma síncrona.
 *   - Servidor single-thread: uma requisição por vez.
 *   Adequado para navegar o dashboard e testar rotas — não para carga.
 */

$root = __DIR__;
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$real = realpath($root . $path);

// Extensões de assets estáticos que o servidor embutido pode entregar direto.
$staticExt = [
    'css','js','mjs','map','json','txt',
    'png','jpg','jpeg','gif','svg','ico','webp','avif',
    'woff','woff2','ttf','eot',
    'mp4','webm','flv','mp3','pdf',
    'csv','xlsx',   // downloads de relatórios (storage/reports)
];

if ($path !== '/' && $real !== false && is_file($real) && strpos($real, $root) === 0) {
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    if (in_array($ext, $staticExt, true)) {
        return false; // deixa o php -S servir o arquivo estático
    }
    // .env / .htaccess / .sql / .php avulso não são servidos diretamente →
    // seguem para o front controller (mesma proteção do .htaccess em produção).
}

// Front controller.
require $root . '/handlers/router.php';
