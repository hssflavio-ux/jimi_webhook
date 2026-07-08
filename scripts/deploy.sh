#!/usr/bin/env bash
# ============================================================
# JIMI WEBHOOK SYSTEM — Deploy Script v2.0.0
# ============================================================
# Uso:
#   ./scripts/deploy.sh              — deploy normal (backup + pull + migrate)
#   ./scripts/deploy.sh --force      — força redeploy mesmo sem mudanças
#   ./scripts/deploy.sh --skip-migrate — pula migração do banco
#   ./scripts/deploy.sh --skip-backup  — pula backup (NÃO recomendado)
# ============================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="/var/backups/jimi_webhook"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
FORCE=0; SKIP_MIGRATE=0; SKIP_BACKUP=0

for arg in "$@"; do
    case $arg in --force) FORCE=1 ;; --skip-migrate) SKIP_MIGRATE=1 ;; --skip-backup) SKIP_BACKUP=1 ;; esac
done

cd "$APP_DIR" || { echo "ERRO: Diretório $APP_DIR não encontrado"; exit 1; }
echo "=== DEPLOY: $(date) ==="

# ════════════════════════════════════════════════════════════
# FASE 1: PREPARE — Verificações de ambiente
# ════════════════════════════════════════════════════════════
echo ""
echo "=== FASE 1/5: PREPARE — Verificando dependências ==="

# Binários essenciais
check_cmd() { command -v "$1" >/dev/null 2>&1 || { echo "  ✗ FALHA: $1 não encontrado"; exit 1; }; }
check_cmd php; check_cmd mysql; check_cmd git

# PHP version
PHP_VER=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
echo "  PHP $PHP_VER"
if awk "BEGIN {exit !($PHP_VER >= 7.4)}" 2>/dev/null; then
    echo "  ✓ PHP >= 7.4"
else
    echo "  ✗ FALHA: PHP 7.4+ requerido. Instale: sudo apt install php8.1 php8.1-fpm php8.1-mysql"
    exit 1
fi

# Módulos PHP críticos
echo "  Verificando módulos PHP..."
for mod in pdo pdo_mysql json mbstring; do
    php -m 2>/dev/null | grep -qi "$mod" && echo "  ✓ php-$mod" || { echo "  ✗ FALHA: php-$mod — instale com: sudo apt install php-$mod"; exit 1; }
done

# PHP-FPM (necessário para fastcgi_finish_request)
FPM_SERVICE=$(systemctl list-units --type=service 2>/dev/null | grep -oP 'php\d+\.\d+-fpm' | head -1 || true)
if [ -n "$FPM_SERVICE" ]; then
    if systemctl is-active --quiet "$FPM_SERVICE" 2>/dev/null; then
        echo "  ✓ PHP-FPM ($FPM_SERVICE) ativo"
    else
        echo "  ⚠ AVISO: PHP-FPM ($FPM_SERVICE) inativo. Inicie: sudo systemctl start $FPM_SERVICE"
    fi
else
    echo "  ⚠ AVISO: PHP-FPM não detectado. fastcgi_finish_request() não funcionará sem FPM."
    echo "          Instale: sudo apt install php-fpm"
fi

# Apache modules
echo "  Verificando Apache..."
if command -v apache2ctl >/dev/null 2>&1; then
    apache2ctl -M 2>/dev/null | grep -qi rewrite && echo "  ✓ mod_rewrite" \
        || echo "  ⚠ AVISO: mod_rewrite ausente. Ative: sudo a2enmod rewrite && sudo systemctl reload apache2"
    apache2ctl -M 2>/dev/null | grep -qi headers && echo "  ✓ mod_headers" \
        || echo "  ⚠ AVISO: mod_headers ausente"
    # Verificar AllowOverride
    if apache2ctl -S 2>/dev/null | grep -qi "$APP_DIR"; then
        echo "  ✓ VirtualHost detectado"
    else
        echo "  ⚠ AVISO: Nenhum VirtualHost apontando para $APP_DIR"
        echo "          Configure AllowOverride All para o diretório."
    fi
else
    echo "  ⚠ AVISO: Apache não detectado (pode ser Nginx — verifique rewrite manualmente)"
fi

# MySQL
echo "  Verificando MySQL..."
if mysql --version >/dev/null 2>&1; then
    echo "  ✓ MySQL disponível"
    if mysql -e "SELECT 1" >/dev/null 2>&1; then
        echo "  ✓ Conexão MySQL OK (via socket/auth local)"
    else
        echo "  ⚠ AVISO: Conexão MySQL falhou — verifique credenciais no .env"
    fi
else
    echo "  ✗ FALHA: mysql CLI não encontrado"
    exit 1
fi

# Git remote — verificar conexão e forçar SSH
echo "  Verificando Git..."
GIT_REMOTE=$(git remote get-url origin 2>/dev/null || echo "")
echo "  Remote: $GIT_REMOTE"

# Se o remote for HTTPS, testa SSH e converte se disponível
if [[ "$GIT_REMOTE" == https://github.com/* ]]; then
    echo "  ℹ Remote HTTPS detectado — testando SSH..."
    SSH_REMOTE=$(echo "$GIT_REMOTE" | sed 's|https://github.com/|git@github.com:|')
    SSH_REMOTE="${SSH_REMOTE%/}"
    if [[ "$SSH_REMOTE" != *.git ]]; then SSH_REMOTE="${SSH_REMOTE}.git"; fi

    if git ls-remote "$SSH_REMOTE" HEAD --quiet 2>/dev/null; then
        git remote set-url origin "$SSH_REMOTE"
        echo "  ✓ Remote alterado para: $SSH_REMOTE"
        GIT_REMOTE="$SSH_REMOTE"
    else
        echo "  ⚠ SSH indisponível — mantendo HTTPS"
    fi
fi

if git fetch origin --quiet 2>/dev/null; then
    echo "  ✓ Conexão GitHub OK (SSH)"
else
    echo "  ✗ FALHA: git fetch falhou."
    echo "          Configure SSH Key: ssh-keygen -t ed25519 -C 'deploy' -f ~/.ssh/github_deploy"
    echo "          Adicione chave pública em: https://github.com/settings/keys"
    echo "          Teste: ssh -T git@github.com"
    exit 1
fi

# Disco
DISK_USAGE=$(df -h "$APP_DIR" | tail -1 | awk '{print $5}' | tr -d '%')
echo "  Disco: ${DISK_USAGE}% usado"
if [ "$DISK_USAGE" -gt 90 ]; then
    echo "  ⚠ AVISO: Disco quase cheio (${DISK_USAGE}%). Libere espaço."
fi

# ════════════════════════════════════════════════════════════
# FASE 2: BACKUP — Salvar estado atual
# ════════════════════════════════════════════════════════════
echo ""
if [ "$SKIP_BACKUP" -eq 0 ]; then
    echo "=== FASE 2/5: BACKUP — Salvando estado atual ==="
    mkdir -p "$BACKUP_DIR"

    # Backup do .env (contém credenciais — crítico)
    if [ -f .env ]; then
        cp .env "$BACKUP_DIR/env_$TIMESTAMP.bak"
        echo "  ✓ .env → backup/env_$TIMESTAMP.bak"
    fi

    # Backup do banco de dados
    if [ -f .env ]; then
        source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')
        if mysqldump -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
            -p"${DB_PASS}" --single-transaction --routines --triggers \
            "${DB_NAME:-jimi_tracker}" > "$BACKUP_DIR/db_$TIMESTAMP.sql" 2>/dev/null; then
            echo "  ✓ Banco → backup/db_$TIMESTAMP.sql ($(du -h "$BACKUP_DIR/db_$TIMESTAMP.sql" | cut -f1))"
        else
            echo "  ⚠ AVISO: mysqldump falhou — verifique credenciais no .env"
        fi
    fi

    # Limpar backups antigos (manter últimos 10)
    ls -1t "$BACKUP_DIR"/db_*.sql 2>/dev/null | tail -n +11 | xargs -r rm -f
    ls -1t "$BACKUP_DIR"/env_*.bak 2>/dev/null | tail -n +11 | xargs -r rm -f
else
    echo "=== FASE 2/5: BACKUP — PULADO (--skip-backup) ==="
fi

# ════════════════════════════════════════════════════════════
# FASE 3: DEPLOY — Atualizar código
# ════════════════════════════════════════════════════════════
echo ""
echo "=== FASE 3/5: DEPLOY — Atualizando código ==="

# Garantir que estamos no branch main
git checkout main --quiet 2>/dev/null || true

LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)

if [ "$LOCAL" = "$REMOTE" ] && [ "$FORCE" -eq 0 ]; then
    echo "  ✓ Já estamos no commit mais recente ($(git rev-parse --short HEAD))"
    echo "  Use --force para redeploy mesmo sem mudanças."
else
    echo "  Atualizando: $(git rev-parse --short HEAD) → $(git rev-parse --short origin/main)"

    # Listar arquivos alterados antes do pull
    CHANGED=$(git diff --name-only HEAD origin/main 2>/dev/null | head -20 || true)
    if [ -n "$CHANGED" ]; then
        echo "  Arquivos que serão atualizados:"
        echo "$CHANGED" | while read -r f; do echo "    - $f"; done
    fi

    git pull origin main 2>&1
    echo "  ✓ Código atualizado para $(git rev-parse --short HEAD)"
fi

# ─── 3a. Verificar/Criar .env ────────────────────────────────
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        echo ""
        echo "  ⚠ ATENÇÃO: .env criado a partir de .env.example"
        echo "  Edite as credenciais ANTES de continuar:"
        echo "    nano $APP_DIR/.env"
        echo "  Depois execute: ./scripts/deploy.sh --force --skip-backup"
        exit 0
    fi
fi

# Verificar SYSTEM_VERSION
if [ -f .env ] && [ -f .env.example ]; then
    REPO_VERSION=$(grep 'SYSTEM_VERSION=' .env.example | cut -d= -f2)
    LOCAL_VERSION=$(grep 'SYSTEM_VERSION=' .env | cut -d= -f2 || echo "")
    if [ "$LOCAL_VERSION" != "$REPO_VERSION" ] && [ -n "$LOCAL_VERSION" ]; then
        echo "  ℹ Versão do sistema no .env: $LOCAL_VERSION → $REPO_VERSION (repositório)"
        echo "    Atualizando SYSTEM_VERSION no .env..."
        sed -i "s/SYSTEM_VERSION=.*/SYSTEM_VERSION=$REPO_VERSION/" .env
    fi
fi

# ─── 3b. Migração do banco de dados ──────────────────────────
if [ "$SKIP_MIGRATE" -eq 0 ] && [ -f .env ]; then
    echo "  Verificando migrações pendentes..."
    source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')

    if [ -f "mysql/migration_v2.0.0.sql" ]; then
        # Verifica versão atual do banco
        DB_VERSION=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
            -p"${DB_PASS}" -N -e \
            "SELECT COALESCE(version,'0') FROM ${DB_NAME:-jimi_tracker}.system_info WHERE id=1 LIMIT 1" \
            2>/dev/null || echo "0")

        if [ "$DB_VERSION" = "0" ]; then
            echo "  Aplicando migration_v2.0.0.sql (versão atual do banco: $DB_VERSION)..."
            if mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                -p"${DB_PASS}" "${DB_NAME:-jimi_tracker}" < mysql/migration_v2.0.0.sql 2>/tmp/migrate_err.log; then
                echo "  ✓ Migração v2.0.0 aplicada com sucesso"
            else
                echo "  ⚠ AVISO: Erro na migração v2.0.0. Veja /tmp/migrate_err.log"
                cat /tmp/migrate_err.log 2>/dev/null || true
            fi
        else
            echo "  ✓ Banco já está na versão $DB_VERSION — migração v2.0.0 desnecessária"
        fi

        # v3.1.0 migration
        if [ -f "mysql/migration_v3.1.0.sql" ]; then
            DB_VERSION=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                -p"${DB_PASS}" -N -e \
                "SELECT COALESCE(version,'0') FROM ${DB_NAME:-jimi_tracker}.system_info WHERE id=1 LIMIT 1" \
                2>/dev/null || echo "0")

            if [ "$DB_VERSION" = "2.0.0" ] || [ "$DB_VERSION" = "0" ]; then
                echo "  Aplicando migration_v3.1.0.sql (versão atual do banco: $DB_VERSION)..."
                if mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                    -p"${DB_PASS}" "${DB_NAME:-jimi_tracker}" < mysql/migration_v3.1.0.sql 2>/tmp/migrate_err_v31.log; then
                    echo "  ✓ Migração v3.1.0 aplicada com sucesso"
                else
                    echo "  ⚠ AVISO: Erro na migração v3.1.0. Veja /tmp/migrate_err_v31.log"
                    cat /tmp/migrate_err_v31.log 2>/dev/null || true
                fi
            else
                echo "  ✓ Banco já está na versão $DB_VERSION — migração v3.1.0 desnecessária"
            fi
        fi

        # v4.0.0 migration
        if [ -f "mysql/migration_v4.0.0.sql" ]; then
            DB_VERSION=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                -p"${DB_PASS}" -N -e \
                "SELECT COALESCE(version,'0') FROM ${DB_NAME:-jimi_tracker}.system_info WHERE id=1 LIMIT 1" \
                2>/dev/null || echo "0")

            if [ "$DB_VERSION" = "3.1.0" ] || [ "$DB_VERSION" = "2.0.0" ] || [ "$DB_VERSION" = "0" ]; then
                echo "  Aplicando migration_v4.0.0.sql (YUV Parity, versão atual: $DB_VERSION)..."
                if MYSQL_PWD="${DB_PASS:-}" mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                    "${DB_NAME:-jimi_tracker}" < mysql/migration_v4.0.0.sql 2>/tmp/migrate_err_v40.log; then
                    echo "  ✓ Migração v4.0.0 aplicada com sucesso"
                else
                    echo "  ⚠ AVISO: Erro na migração v4.0.0. Veja /tmp/migrate_err_v40.log"
                    cat /tmp/migrate_err_v40.log 2>/dev/null || true
                fi
            else
                echo "  ✓ Banco já está na versão $DB_VERSION — migração v4.0.0 desnecessária"
            fi
        fi

        # v4.1.0 migration (jobs.format + fix seed occurrence_config_params)
        if [ -f "mysql/migration_v4.1.0.sql" ]; then
            DB_VERSION=$(mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                -p"${DB_PASS}" -N -e \
                "SELECT COALESCE(version,'0') FROM ${DB_NAME:-jimi_tracker}.system_info WHERE id=1 LIMIT 1" \
                2>/dev/null || echo "0")

            if [ "$DB_VERSION" != "4.1.0" ]; then
                echo "  Aplicando migration_v4.1.0.sql (Excel/PDF + fix seed DMS, versão atual: $DB_VERSION)..."
                if MYSQL_PWD="${DB_PASS:-}" mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
                    "${DB_NAME:-jimi_tracker}" < mysql/migration_v4.1.0.sql 2>/tmp/migrate_err_v41.log; then
                    echo "  ✓ Migração v4.1.0 aplicada com sucesso"
                else
                    echo "  ⚠ AVISO: Erro na migração v4.1.0. Veja /tmp/migrate_err_v41.log"
                    cat /tmp/migrate_err_v41.log 2>/dev/null || true
                fi
            else
                echo "  ✓ Banco já está na versão $DB_VERSION — migração v4.1.0 desnecessária"
            fi
        fi
    fi
fi

# ─── 3c. Permissões ──────────────────────────────────────────
echo "  Configurando permissões..."

# Diretórios PHP — leitura
chmod 755 "$APP_DIR"
find config core handlers includes web -type d -exec chmod 755 {} \; 2>/dev/null || true
find config core handlers includes web -type f -exec chmod 644 {} \; 2>/dev/null || true

# .htaccess
[ -f .htaccess ] && chmod 644 .htaccess

# Logs — escrita pelo Apache/PHP
if [ ! -d logs ]; then
    mkdir -p logs
fi
chmod 777 logs
# Storage — reports e media
if [ ! -d storage/reports ]; then
    mkdir -p storage/reports
fi
if [ ! -d storage/media ]; then
    mkdir -p storage/media
fi
chmod 777 storage storage/reports storage/media 2>/dev/null || true
# Manter logs existentes com permissão de escrita
find logs -type f -exec chmod 666 {} \; 2>/dev/null || true
echo "  ✓ Permissões configuradas"

# ════════════════════════════════════════════════════════════
# FASE 4: VERIFY — Testes pós-deploy
# ════════════════════════════════════════════════════════════
echo ""
echo "=== FASE 4/5: VERIFY — Testando ==="

# Sintaxe PHP em todos os arquivos
echo "  Verificando sintaxe PHP..."
ERRORS=0
for f in $(find handlers config core includes -name "*.php" -type f); do
    if ! php -l "$f" >/dev/null 2>&1; then
        echo "  ✗ Erro sintaxe: $f"
        php -l "$f" 2>&1 | head -2
        ERRORS=$((ERRORS + 1))
    fi
done
[ "$ERRORS" -eq 0 ] && echo "  ✓ Todos os arquivos PHP com sintaxe OK" \
    || echo "  ⚠ $ERRORS arquivo(s) com erro de sintaxe"

# Teste /ping
echo "  Testando /ping..."
PING_URL="http://localhost/ping"
if command -v curl >/dev/null 2>&1; then
    HTTP_CODE=$(curl -s -o /tmp/ping_resp.txt -w "%{http_code}" "$PING_URL" --connect-timeout 5 2>/dev/null || echo "000")
    if [ "$HTTP_CODE" = "200" ]; then
        PING_BODY=$(cat /tmp/ping_resp.txt)
        echo "  ✓ /ping HTTP 200: $PING_BODY"
    else
        echo "  ⚠ /ping HTTP $HTTP_CODE — verifique se o Apache está servindo $APP_DIR"
    fi
    rm -f /tmp/ping_resp.txt
fi

# Logs graváveis
if [ -d logs ] && [ -w logs ]; then
    # Teste de escrita
    echo "test" > logs/.deploy_test 2>/dev/null && rm -f logs/.deploy_test \
        && echo "  ✓ logs/ gravável" || echo "  ⚠ logs/ não está gravável"
fi

# ════════════════════════════════════════════════════════════
# FASE 5: CONFIRM — Resumo final
# ════════════════════════════════════════════════════════════
echo ""
echo "============================================================"
echo "  DEPLOY CONCLUÍDO — $(date)"
echo "============================================================"
echo "  Projeto:    $APP_DIR"
echo "  Commit:     $(git rev-parse --short HEAD)"
echo "  Branch:     $(git rev-parse --abbrev-ref HEAD)"
echo "  PHP:        $PHP_VER"
echo "  Backup:     $BACKUP_DIR/${TIMESTAMP}_*"
echo ""
echo "  Próximos passos:"
echo "  1. Monitore por ~15 minutos: tail -f logs/webhook_$(date +%Y-%m-%d).log"
echo "  2. Acesse o painel: http://<ip>/dashboard"
echo "  3. Em caso de problema: ./scripts/rollback.sh $TIMESTAMP"
echo "============================================================"
