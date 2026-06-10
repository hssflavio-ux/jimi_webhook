#!/usr/bin/env bash
# ============================================================
# JIMI WEBHOOK SYSTEM — Setup Script (Primeira Instalação) v2.0.0
# ============================================================
# Uso:
#   ./scripts/setup.sh                    — instalação interativa
#   ./scripts/setup.sh --non-interactive  — usa valores padrão do .env.example
# ============================================================
set -euo pipefail

APP_DIR="/var/www/jimi_webhook"
REPO_URL="git@github.com:hssflavio-ux/jimi_webhook.git"
NON_INTERACTIVE=0

for arg in "$@"; do
    case $arg in --non-interactive) NON_INTERACTIVE=1 ;; esac
done

echo ""
echo "============================================================"
echo "  JIMI WEBHOOK SYSTEM — Instalação Inicial v2.0.0"
echo "============================================================"
echo ""

# ════════════════════════════════════════════════════════════
# 1. Verificar pré-requisitos do sistema
# ════════════════════════════════════════════════════════════
echo "=== 1. Verificando pré-requisitos ==="

# Verificar SO
if [ -f /etc/os-release ]; then
    . /etc/os-release
    echo "  SO: $NAME $VERSION_ID"
fi

# PHP
if ! command -v php >/dev/null 2>&1; then
    echo "  ✗ PHP não instalado. Instale:"
    echo "    sudo apt update && sudo apt install php8.1 php8.1-fpm php8.1-mysql php8.1-mbstring php8.1-json"
    exit 1
fi
echo "  ✓ PHP $(php -r 'echo PHP_VERSION;')"

# PHP-FPM
FPM_INSTALLED=false
if systemctl list-units --type=service 2>/dev/null | grep -q "php.*-fpm"; then
    FPM_INSTALLED=true
    echo "  ✓ PHP-FPM instalado"
else
    echo "  ⚠ PHP-FPM não detectado. Instale: sudo apt install php-fpm"
fi

# MySQL
if ! command -v mysql >/dev/null 2>&1; then
    echo "  ✗ MySQL CLI não encontrado. Instale: sudo apt install mysql-client"
    exit 1
fi
MYSQL_VER=$(mysql --version 2>/dev/null | grep -oP '\d+\.\d+\.\d+' | head -1 || echo "desconhecida")
echo "  ✓ MySQL CLI $MYSQL_VER"

# Apache/Nginx
if command -v apache2ctl >/dev/null 2>&1; then
    echo "  ✓ Apache detectado"
elif command -v nginx >/dev/null 2>&1; then
    echo "  ✓ Nginx detectado"
else
    echo "  ⚠ Servidor web não detectado"
fi

# Git
if ! command -v git >/dev/null 2>&1; then
    echo "  ✗ Git não instalado. Instale: sudo apt install git"
    exit 1
fi
echo "  ✓ Git $(git --version | awk '{print $3}')"

# ════════════════════════════════════════════════════════════
# 2. Clonar repositório
# ════════════════════════════════════════════════════════════
echo ""
echo "=== 2. Clonando repositório ==="

if [ -d "$APP_DIR/.git" ]; then
    echo "  ✓ Repositório já existe em $APP_DIR"
    cd "$APP_DIR"
    echo "  Atualizando..."
    git pull origin main 2>/dev/null || echo "  ⚠ git pull falhou — verifique chave SSH"
else
    echo "  Clonando $REPO_URL → $APP_DIR"
    if git clone "$REPO_URL" "$APP_DIR" 2>/dev/null; then
        echo "  ✓ Clone concluído"
        cd "$APP_DIR"
    else
        echo ""
        echo "  ✗ Clone falhou. Configure a chave SSH primeiro:"
        echo ""
        echo "    # No servidor, gere a chave:"
        echo "    ssh-keygen -t ed25519 -C 'deploy@$(hostname)' -f ~/.ssh/github_deploy -N ''"
        echo ""
        echo "    # Copie a chave pública:"
        echo "    cat ~/.ssh/github_deploy.pub"
        echo ""
        echo "    # Adicione em: https://github.com/settings/keys"
        echo ""
        echo "    # Configure o Git:"
        echo "    eval \$(ssh-agent -s)"
        echo "    ssh-add ~/.ssh/github_deploy"
        echo "    ssh -T git@github.com  # teste"
        echo ""
        echo "    # Depois execute novamente: ./scripts/setup.sh"
        exit 1
    fi
fi

# ════════════════════════════════════════════════════════════
# 3. Configurar .env
# ════════════════════════════════════════════════════════════
echo ""
echo "=== 3. Configurando .env ==="

if [ -f .env ]; then
    echo "  ✓ .env já existe — preservando"
else
    cp .env.example .env
    chmod 600 .env
    echo "  ✓ .env criado a partir de .env.example"

    if [ "$NON_INTERACTIVE" -eq 0 ]; then
        echo ""
        echo "  ╔══════════════════════════════════════════════════════╗"
        echo "  ║  ATENÇÃO: Edite as credenciais no arquivo .env      ║"
        echo "  ║  nano $APP_DIR/.env                                 ║"
        echo "  ║                                                      ║"
        echo "  ║  DB_HOST  = endereço do MySQL                        ║"
        echo "  ║  DB_PORT  = porta (geralmente 3306)                  ║"
        echo "  ║  DB_NAME  = jimi_tracker                             ║"
        echo "  ║  DB_USER  = usuário do MySQL                         ║"
        echo "  ║  DB_PASS  = senha do MySQL                           ║"
        echo "  ║  WEBHOOK_TOKEN = token de acesso (mesmo do IoTHub)    ║"
        echo "  ║  FILE_STORAGE_URL = URL do servidor de arquivos      ║"
        echo "  ║  STREAM_URL = URL do servidor de streaming           ║"
        echo "  ╚══════════════════════════════════════════════════════╝"
        echo ""
        echo "  Após editar o .env, execute:"
        echo "    cd $APP_DIR && ./scripts/deploy.sh --force"
        exit 0
    fi
fi

# ════════════════════════════════════════════════════════════
# 4. Criar banco de dados e aplicar schema
# ════════════════════════════════════════════════════════════
echo ""
echo "=== 4. Configurando banco de dados ==="

if [ -f .env ]; then
    source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')

    # Verificar se o banco já existe
    DB_EXISTS=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
        -p"${DB_PASS}" -N -e "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${DB_NAME:-jimi_tracker}'" \
        2>/dev/null || echo "0")

    if [ "$DB_EXISTS" = "1" ]; then
        echo "  ⚠ Banco '${DB_NAME:-jimi_tracker}' já existe."
        echo "    Deseja recriar? (isso APAGARÁ todos os dados!) [s/N]"
        read -r RECREATE
        if [ "$RECREATE" = "s" ] || [ "$RECREATE" = "S" ]; then
            mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                -p"${DB_PASS}" -e "DROP DATABASE IF EXISTS ${DB_NAME:-jimi_tracker}; CREATE DATABASE ${DB_NAME:-jimi_tracker} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
            echo "  ✓ Banco recriado"
        else
            echo "  Mantendo banco existente — pulando schema (use --force no deploy.sh para migração)"
        fi
    else
        echo "  Criando banco '${DB_NAME:-jimi_tracker}'..."
        mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
            -p"${DB_PASS}" -e "CREATE DATABASE ${DB_NAME:-jimi_tracker} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        echo "  ✓ Banco criado"

        # Aplicar schema completo
        if [ -f mysql/jimi_tracker.sql ]; then
            echo "  Aplicando schema..."
            mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                -p"${DB_PASS}" "${DB_NAME:-jimi_tracker}" < mysql/jimi_tracker.sql \
                && echo "  ✓ Schema aplicado" || echo "  ⚠ Erro ao aplicar schema"
        fi

        # Aplicar migração
        if [ -f mysql/migration_v2.0.0.sql ]; then
            echo "  Aplicando migração v2.0.0..."
            mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                -p"${DB_PASS}" "${DB_NAME:-jimi_tracker}" < mysql/migration_v2.0.0.sql \
                && echo "  ✓ Migração aplicada" || echo "  ⚠ Erro na migração"
        fi
    fi
fi

# ════════════════════════════════════════════════════════════
# 5. Permissões e configuração final
# ════════════════════════════════════════════════════════════
echo ""
echo "=== 5. Configurando permissões ==="

# Logs
mkdir -p logs
chmod 777 logs
echo "  ✓ logs/ (777)"

# Arquivos PHP
find config core handlers includes web -type d -exec chmod 755 {} \; 2>/dev/null || true
find config core handlers includes web -type f -exec chmod 644 {} \; 2>/dev/null || true
echo "  ✓ Permissões dos arquivos PHP"

# .env protegido
[ -f .env ] && chmod 600 .env && echo "  ✓ .env (600 — somente leitura pelo dono)"
[ -f .htaccess ] && chmod 644 .htaccess

# ════════════════════════════════════════════════════════════
# 6. Configuração do Apache
# ════════════════════════════════════════════════════════════
echo ""
echo "=== 6. Verificando Apache ==="

if command -v apache2ctl >/dev/null 2>&1; then
    # Ativar módulos necessários
    echo "  Ativando módulos Apache..."
    sudo a2enmod rewrite 2>/dev/null || echo "  ⚠ Não foi possível ativar mod_rewrite"
    sudo a2enmod headers 2>/dev/null || echo "  ⚠ Não foi possível ativar mod_headers"

    echo ""
    echo "  Certifique-se de configurar o VirtualHost:"
    echo ""
    echo "  sudo nano /etc/apache2/sites-available/jimi-webhook.conf"
    echo ""
    echo "  <VirtualHost *:80>"
    echo "      ServerName <seu-dominio-ou-ip>"
    echo "      DocumentRoot $APP_DIR"
    echo ""
    echo "      <Directory $APP_DIR>"
    echo "          Options FollowSymLinks"
    echo "          AllowOverride All"
    echo "          Require all granted"
    echo "      </Directory>"
    echo ""
    echo "      ErrorLog \${APACHE_LOG_DIR}/jimi_error.log"
    echo "      CustomLog \${APACHE_LOG_DIR}/jimi_access.log combined"
    echo "  </VirtualHost>"
    echo ""
    echo "  sudo a2ensite jimi-webhook.conf"
    echo "  sudo systemctl reload apache2"

elif command -v nginx >/dev/null 2>&1; then
    echo ""
    echo "  Para Nginx, configure o server block:"
    echo ""
    echo "  server {"
    echo "      listen 80;"
    echo "      server_name <seu-dominio-ou-ip>;"
    echo "      root $APP_DIR;"
    echo "      index index.php;"
    echo ""
    echo "      location / {"
    echo "          try_files \$uri \$uri/ /index.php?\$args;"
    echo "      }"
    echo ""
    echo "      location ~ [^/]\.php(/|$) {"
    echo "          fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;"
    echo "          fastcgi_index index.php;"
    echo "          include fastcgi_params;"
    echo "      }"
    echo ""
    echo "      # Rewrite para handlers (similar ao .htaccess)"
    echo "      location ~ ^/([a-zA-Z0-9_-]+)$ {"
    echo "          try_files \$uri /handlers/\$1.php?\$args;"
    echo "      }"
    echo "  }"
fi

# ════════════════════════════════════════════════════════════
# 7. Finalizar
# ════════════════════════════════════════════════════════════
echo ""
echo "============================================================"
echo "  INSTALAÇÃO CONCLUÍDA — $(date)"
echo "============================================================"
echo ""
echo "  Para atualizações futuras:"
echo "    cd $APP_DIR && ./scripts/deploy.sh"
echo ""
echo "  Acesse o painel:"
echo "    http://<ip-do-servidor>/dashboard"
echo ""
echo "  Verifique a saúde:"
echo "    curl http://localhost/ping"
echo "    tail -f $APP_DIR/logs/webhook_$(date +%Y-%m-%d).log"
echo "============================================================"
