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
#
# `zip` entrou na v4.7.2 depois de o homolog rodar MESES sem ele: XLSX é o
# formato padrão de /exportar E do relatório agendado, e sem ZipArchive o
# worker morria com fatal ("Class ZipArchive not found"), deixando o job preso
# em 'processando' e a execução em 'enfileirado' para sempre — sem erro no
# histórico, porque o fatal mata o processo antes de qualquer UPDATE. Esta
# checagem é justamente o que teria pego isso no primeiro deploy.
#
# `openssl` é o que permite ao includes/mailer.php abrir ssl://smtp:465.
#
# -x casa a linha inteira: sem ele, "pdo" casaria com "pdo_mysql" e um php sem
# PDO passaria batido desde que tivesse pdo_sqlite.
echo "  Verificando módulos PHP..."
for mod in pdo pdo_mysql json mbstring zip openssl; do
    php -m 2>/dev/null | grep -qix "$mod" && echo "  ✓ php-$mod" || { echo "  ✗ FALHA: php-$mod — instale com: sudo apt install php$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")-$mod"; exit 1; }
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

# MySQL — a conexão é testada com as credenciais do .env (que é o que a app e
# as migrations usam). NÃO usar `mysql -e "SELECT 1"` sem credenciais: isso
# conecta como o usuário do SO (root/administrador sob sudo), que normalmente
# não tem conta MySQL, e falha SEMPRE — dando um "verifique credenciais no .env"
# enganoso mesmo com o .env perfeito.
echo "  Verificando MySQL..."
if mysql --version >/dev/null 2>&1; then
    echo "  ✓ MySQL disponível"
    if [ -f .env ]; then
        source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')
        if MYSQL_PWD="${DB_PASS:-}" mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" \
            -u"${DB_USER:-root}" "${DB_NAME:-jimi_tracker}" -e "SELECT 1" >/dev/null 2>/tmp/mysql_check_err.log; then
            echo "  ✓ Conexão MySQL OK (credenciais do .env)"
        else
            echo "  ⚠ AVISO: conexão MySQL com as credenciais do .env falhou:"
            grep -v 'Using a password' /tmp/mysql_check_err.log | sed 's/^/          /'
        fi
        rm -f /tmp/mysql_check_err.log
    else
        echo "  ⚠ AVISO: .env ausente — não foi possível testar a conexão MySQL"
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

    # Backup do banco de dados. O erro do mysqldump é capturado e exibido — não
    # é sempre credencial: uma VIEW quebrada (ex.: definer/tabela inexistente)
    # aborta o dump com erro 1356 e o backup sai incompleto. Silenciar o stderr
    # e culpar as credenciais mascarava a causa real.
    if [ -f .env ]; then
        source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')
        if MYSQL_PWD="${DB_PASS:-}" mysqldump -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
            --single-transaction --routines --triggers \
            "${DB_NAME:-jimi_tracker}" > "$BACKUP_DIR/db_$TIMESTAMP.sql" 2>/tmp/dump_err.log; then
            echo "  ✓ Banco → backup/db_$TIMESTAMP.sql ($(du -h "$BACKUP_DIR/db_$TIMESTAMP.sql" | cut -f1))"
        else
            echo "  ⚠ AVISO: mysqldump falhou (backup NÃO gerado). Erro:"
            grep -v 'Using a password' /tmp/dump_err.log | head -3 | sed 's/^/          /'
        fi
        rm -f /tmp/dump_err.log
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

    # Impressão do próprio script ANTES do pull. O bash lê o arquivo em
    # disco conforme executa: se o pull trocar este script no meio da
    # execução, o interpretador continua a partir do offset antigo dentro
    # do arquivo novo — e blocos inteiros somem silenciosamente.
    # Foi exatamente o que aconteceu no deploy da v4.4.1 (28/07/2026): as
    # migrações v4.4.0 e v4.4.1, recém-adicionadas neste arquivo, nunca
    # rodaram e o deploy terminou com "sucesso". Daí a re-execução abaixo.
    SELF_SHA_BEFORE=$(sha256sum "$0" 2>/dev/null | cut -d' ' -f1 || echo "")

    git pull origin main 2>&1
    echo "  ✓ Código atualizado para $(git rev-parse --short HEAD)"

    SELF_SHA_AFTER=$(sha256sum "$0" 2>/dev/null | cut -d' ' -f1 || echo "")
    if [ -n "$SELF_SHA_BEFORE" ] && [ "$SELF_SHA_BEFORE" != "$SELF_SHA_AFTER" ]; then
        if [ "${DEPLOY_REEXEC:-0}" -eq 1 ]; then
            echo "  ⚠ deploy.sh mudou de novo após a re-execução — seguindo assim mesmo."
        else
            echo ""
            echo "  ⚠ O próprio deploy.sh foi atualizado por este pull."
            echo "    Re-executando a versão nova para não pular etapas..."
            echo ""
            export DEPLOY_REEXEC=1
            exec "$0" "$@"
        fi
    fi
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
#
# Gate por comparação SEMÂNTICA de versão (`sort -V`): uma migração só roda
# quando a versão do banco é MENOR que a dela.
#
# O gate anterior comparava com `!=` bloco a bloco, então um banco em 4.4.1
# satisfazia "!= 4.2.1" e a cadeia inteira era reaplicada a cada publicação:
# 4.4.1 → aplica v4.2.1 → banco vira 4.2.1 → aplica v4.3.0 → 4.3.0 → … De
# fato só não quebrava porque as migrações são idempotentes; em troca, as
# mensagens "versão atual" mentiam, o banco era temporariamente rebaixado no
# meio do deploy e o custo crescia a cada release nova.

# Verdadeiro se $1 < $2 na ordem semântica de versões.
version_lt() {
    [ "$1" = "$2" ] && return 1
    [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$1" ]
}

# Versão registrada em system_info; "0" quando a tabela ainda não existe.
db_version() {
    local v
    v=$(MYSQL_PWD="${DB_PASS:-}" mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" \
        -u"${DB_USER:-root}" -N -e \
        "SELECT COALESCE(version,'0') FROM ${DB_NAME:-jimi_tracker}.system_info WHERE id=1 LIMIT 1" \
        2>/dev/null) || v=""
    echo "${v:-0}"
}

# run_migration <versão-alvo> <arquivo> <descrição>
run_migration() {
    local target="$1" file="$2" desc="$3" current errlog
    [ -f "$file" ] || return 0

    current=$(db_version)
    if ! version_lt "$current" "$target"; then
        echo "  ✓ Banco em $current — migração v$target desnecessária"
        return 0
    fi

    echo "  Aplicando $(basename "$file") ($desc; banco em $current)..."
    errlog="/tmp/migrate_err_v$(echo "$target" | tr -d '.').log"
    if MYSQL_PWD="${DB_PASS:-}" mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" \
        -u"${DB_USER:-root}" "${DB_NAME:-jimi_tracker}" < "$file" 2>"$errlog"; then
        echo "  ✓ Migração v$target aplicada — banco agora em $(db_version)"
    else
        echo "  ⚠ AVISO: erro na migração v$target. Veja $errlog"
        cat "$errlog" 2>/dev/null || true
    fi
    return 0
}

if [ "$SKIP_MIGRATE" -eq 0 ] && [ -f .env ]; then
    echo "  Verificando migrações pendentes..."
    source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')

    echo "  Versão atual do banco: $(db_version)"

    # Ordem cronológica — o gate decide sozinho quais estão pendentes.
    run_migration "2.0.0" "mysql/migration_v2.0.0.sql" "base v2"
    run_migration "3.1.0" "mysql/migration_v3.1.0.sql" "multi-tenant"
    run_migration "4.0.0" "mysql/migration_v4.0.0.sql" "YUV Parity"
    run_migration "4.1.0" "mysql/migration_v4.1.0.sql" "Excel/PDF + fix seed DMS"
    run_migration "4.2.1" "mysql/migration_v4.2.1.sql" "câmeras por modelo"
    run_migration "4.3.0" "mysql/migration_v4.3.0.sql" "índice de período em trips"
    run_migration "4.4.0" "mysql/migration_v4.4.0.sql" "motor de notificações"
    run_migration "4.4.1" "mysql/migration_v4.4.1.sql" "credenciais SMTP"
    run_migration "4.5.0" "mysql/migration_v4.5.0.sql" "geocercas"
    run_migration "4.6.0" "mysql/migration_v4.6.0.sql" "relatórios operacionais"
    run_migration "4.7.0" "mysql/migration_v4.7.0.sql" "relatórios agendados + modelos"
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

# Sintaxe PHP em todos os arquivos.
# `scripts` entrou na varredura na v4.5.0: os workers de cron (worker.php,
# trip_builder.php, geofence_worker.php…) ficam lá e estavam FORA do lint do
# deploy — um erro de sintaxe num worker passaria batido e só apareceria na
# primeira execução do cron, em silêncio, dentro de logs/<worker>.log.
echo "  Verificando sintaxe PHP..."
ERRORS=0
for f in $(find handlers config core includes scripts -name "*.php" -type f); do
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
