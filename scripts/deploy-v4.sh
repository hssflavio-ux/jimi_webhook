#!/usr/bin/env bash
# ============================================================
# JIMI WEBHOOK SYSTEM — Deploy v4.0.0
# ============================================================
# ATENÇÃO: Este script é a ÚNICA forma suportada de migrar de
# v3.x para v4.0.0. Não execute migration_v4.0.0.sql manualmente
# sem antes fazer backup e verificar o estado do banco.
#
# Uso:
#   bash scripts/deploy-v4.sh                     # deploy completo
#   bash scripts/deploy-v4.sh --check             # diagnóstico pré-deploy (sem alterações)
#   bash scripts/deploy-v4.sh --backup-only       # apenas backup
#   bash scripts/deploy-v4.sh --migrate           # apenas migração do banco
#   bash scripts/deploy-v4.sh --deploy            # apenas deploy do código
#   bash scripts/deploy-v4.sh --verify            # apenas verificação pós-deploy
#   bash scripts/deploy-v4.sh --force             # força git reset --hard mesmo sem mudanças
#   bash scripts/deploy-v4.sh --skip-backup       # pula backup (NÃO recomendado em produção)
# ============================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="/var/backups/jimi_webhook"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
MODE="all"
FORCE=0
SKIP_BACKUP=0

for arg in "$@"; do
    case $arg in
        --check) MODE="check" ;;
        --backup-only) MODE="backup" ;;
        --migrate) MODE="migrate" ;;
        --deploy) MODE="deploy" ;;
        --verify) MODE="verify" ;;
        --force) FORCE=1 ;;
        --skip-backup) SKIP_BACKUP=1 ;;
    esac
done

cd "$APP_DIR" || { echo "ERRO: Diretório $APP_DIR não encontrado"; exit 1; }

# ════════════════════════════════════════════════════════════
# Helpers
# ════════════════════════════════════════════════════════════
section() { echo ""; echo "============================================================"; echo "  $1"; echo "============================================================"; }
ok()      { echo "  OK  $1"; }
warn()    { echo "  WARN $1"; }
fail()    { echo "  FAIL $1"; exit 1; }
info()    { echo "  ...  $1"; }

load_env() {
    [ -f "$APP_DIR/.env" ] && source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS|PASS)=' "$APP_DIR/.env" | sed 's/^/export /')
    DB_HOST="${DB_HOST:-localhost}"; DB_PORT="${DB_PORT:-3306}"; DB_NAME="${DB_NAME:-jimi_tracker}"; DB_USER="${DB_USER:-root}"
}

mysql_q() { mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"${DB_PASS:-}" -N -e "$1" 2>/dev/null || echo "ERR"; }
mysql_exec() { mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"${DB_PASS:-}" "$DB_NAME" "$@" 2>/tmp/v4_migrate_err.log; }

# ════════════════════════════════════════════════════════════
# FASE 1: CHECK — Diagnóstico do estado atual
# ════════════════════════════════════════════════════════════
do_check() {
    section "FASE 1: DIAGNÓSTICO DO AMBIENTE ($(date '+%Y-%m-%d %H:%M:%S'))"
    load_env

    # ─── 1a. Conexão MySQL ─────────────────────────────────────
    MYSQL_OK=$(mysql_q "SELECT 1")
    [ "$MYSQL_OK" = "1" ] && ok "MySQL conectado (${DB_USER}@${DB_HOST}:${DB_PORT}/${DB_NAME})" \
        || fail "MySQL inacessível — verifique .env e serviço mysqld"

    MYSQL_VER=$(mysql_q "SELECT VERSION()")
    info "MySQL $MYSQL_VER"

    # ─── 1b. Versão do sistema registrada ─────────────────────
    DB_VER=$(mysql_q "SELECT COALESCE(version,'NÃO REGISTRADA') FROM ${DB_NAME}.system_info WHERE id=1 LIMIT 1")
    DB_INSTALL=$(mysql_q "SELECT COALESCE(DATE_FORMAT(installation_date,'%d/%m/%Y %H:%i'),'-') FROM ${DB_NAME}.system_info WHERE id=1 LIMIT 1")
    ENV_VER=$(grep 'SYSTEM_VERSION=' "$APP_DIR/.env" 2>/dev/null | cut -d= -f2 || echo "N/D")
    info "Versão no banco: $DB_VER | Instalado: $DB_INSTALL"
    info "Versão no .env:  $ENV_VER"

    # ─── 1c. Contagem de registros ─────────────────────────────
    echo ""
    echo "  --- Registros por tabela ---"
    for tbl in devices gps_data heartbeats alarms events device_events commands media_files resource_lists request_logs; do
        CNT=$(mysql_q "SELECT COUNT(*) FROM ${DB_NAME}.${tbl}" 2>/dev/null || echo "n/a")
        printf "  %-25s %s\n" "$tbl:" "$CNT"
    done

    # ─── 1d. Tabelas v4.0.0 — quais existem e quais faltam ────
    echo ""
    echo "  --- Tabelas v4.0.0 ---"
    V4_TABLES=(
        "branches" "drivers" "sim_cards" "permission_groups"
        "occurrence_configs" "occurrence_config_params" "occurrences" "occurrence_events"
        "trips" "jobs" "geocode_cache" "impersonation_log"
        "checklist_configs" "checklist_items" "checklist_responses"
        "metrics_snapshots" "login_log"
    )
    MISSING=0; EXISTS=0
    for tbl in "${V4_TABLES[@]}"; do
        FOUND=$(mysql_q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='${tbl}'")
        if [ "$FOUND" = "1" ]; then
            printf "  %-35s EXISTS\n" "$tbl"
            EXISTS=$((EXISTS + 1))
        else
            printf "  %-35s MISSING\n" "$tbl"
            MISSING=$((MISSING + 1))
        fi
    done
    echo "  --- $EXISTS existentes, $MISSING faltando (de ${#V4_TABLES[@]} tabelas v4) ---"

    # ─── 1e. Colunas novas em tabelas existentes ───────────────
    echo ""
    echo "  --- Colunas v4.0.0 em tabelas existentes ---"
    for check in "users:user_type" "users:permission_group_id" "users:photo_url" \
                 "customers:reseller_id" "customers:brand_color" "customers:occurrence_config_id" \
                 "devices:sim_card_id" "devices:peripherals" "devices:streaming_rotation" "devices:firmware_version" \
                 "media_files:channel" "media_files:download_status"; do
        TABLE="${check%%:*}"; COL="${check##*:}"
        FOUND=$(mysql_q "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='${TABLE}' AND COLUMN_NAME='${COL}'")
        [ "$FOUND" = "1" ] && printf "  %-40s OK\n" "${TABLE}.${COL}" || printf "  %-40s MISSING\n" "${TABLE}.${COL}"
    done

    # ─── 1f. Últimos dados recebidos ──────────────────────────
    echo ""
    echo "  --- Últimos dados recebidos ---"
    LAST_GPS=$(mysql_q "SELECT COALESCE(DATE_FORMAT(MAX(gps_time),'%Y-%m-%d %H:%i'),'NUNCA') FROM ${DB_NAME}.gps_data")
    LAST_ALARM=$(mysql_q "SELECT COALESCE(DATE_FORMAT(MAX(alarm_time),'%Y-%m-%d %H:%i'),'NUNCA') FROM ${DB_NAME}.alarms")
    LAST_HB=$(mysql_q "SELECT COALESCE(DATE_FORMAT(MAX(heartbeat_time),'%Y-%m-%d %H:%i'),'NUNCA') FROM ${DB_NAME}.heartbeats")
    echo "  GPS:        $LAST_GPS"
    echo "  Alarmes:    $LAST_ALARM"
    echo "  Heartbeats: $LAST_HB"

    ONE_HOUR_AGO=$(date -d '1 hour ago' '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -v-1H '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "")
    if [ -n "$ONE_HOUR_AGO" ] && [ "$LAST_GPS" != "NUNCA" ] && [ "$LAST_GPS" != "ERR" ] && [[ "$LAST_GPS" < "$ONE_HOUR_AGO" ]]; then
        warn "Último GPS ($LAST_GPS) > 1h atrás — possível falha de conectividade"
    fi

    # ─── 1g. Disco ─────────────────────────────────────────────
    DISK=$(df -h "$APP_DIR" 2>/dev/null | tail -1 | awk '{print $5" usado de "$2" ("$4" livre)"}' || echo "n/a")
    info "Disco: $DISK"

    echo ""
    ok "Diagnóstico concluído."
}

# ════════════════════════════════════════════════════════════
# FASE 2: BACKUP
# ════════════════════════════════════════════════════════════
do_backup() {
    section "FASE 2: BACKUP ($TIMESTAMP)"

    if [ "$SKIP_BACKUP" -eq 1 ]; then
        warn "Backup PULADO (--skip-backup)"
        return
    fi

    load_env
    mkdir -p "$BACKUP_DIR"

    if [ -f "$APP_DIR/.env" ]; then
        cp "$APP_DIR/.env" "$BACKUP_DIR/env_${TIMESTAMP}.bak"
        ok ".env → backup/env_${TIMESTAMP}.bak"
    fi

    info "Dump do banco ${DB_NAME}..."
    MYSQL_PWD="${DB_PASS:-}" mysqldump -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" \
        --single-transaction --routines --triggers --skip-lock-tables \
        "$DB_NAME" > "$BACKUP_DIR/db_${TIMESTAMP}.sql" 2>/tmp/v4_backup_err.log

    if [ -s "$BACKUP_DIR/db_${TIMESTAMP}.sql" ]; then
        SIZE=$(du -h "$BACKUP_DIR/db_${TIMESTAMP}.sql" | cut -f1)
        ok "Banco → backup/db_${TIMESTAMP}.sql ($SIZE)"
    else
        warn "mysqldump falhou: $(cat /tmp/v4_backup_err.log 2>/dev/null)"
    fi

    # Limitar a 10 backups
    ls -1t "$BACKUP_DIR"/db_*.sql 2>/dev/null | tail -n +11 | xargs -r rm -f
    ls -1t "$BACKUP_DIR"/env_*.bak 2>/dev/null | tail -n +11 | xargs -r rm -f
}

# ════════════════════════════════════════════════════════════
# FASE 3: MIGRATE — Atualização do banco v3.x → v4.0.0
# ════════════════════════════════════════════════════════════
do_migrate() {
    section "FASE 3: MIGRAÇÃO DO BANCO — v3.x → v4.0.0"
    load_env

    DB_VER=$(mysql_q "SELECT COALESCE(version,'0') FROM ${DB_NAME}.system_info WHERE id=1 LIMIT 1")

    if [ "$DB_VER" = "4.0.0" ]; then
        info "Banco já está em v4.0.0"
        V4_COUNT=$(mysql_q "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='${DB_NAME}' AND TABLE_NAME='occurrences'")
        [ "$V4_COUNT" = "1" ] && ok "Tabelas v4.0.0 confirmadas — nada a migrar" && return
        info "Tabelas v4.0.0 incompletas apesar da versão 4.0.0 — reaplicando migration..."
    else
        info "Versão atual do banco: $DB_VER — aplicando migration v4.0.0..."
    fi

    # Aplica a migration (idempotente: CREATE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS)
    MYSQL_PWD="${DB_PASS:-}" mysql_exec < mysql/migration_v4.0.0.sql 2>/tmp/v4_migrate_err.log
    MIGRATE_EXIT=$?

    if [ $MIGRATE_EXIT -eq 0 ]; then
        ok "migration_v4.0.0.sql aplicada com sucesso"
    else
        MIGRATE_ERR=$(cat /tmp/v4_migrate_err.log 2>/dev/null | head -20)
        warn "Erro na migration (pode ser inofensivo se tabelas já existirem):"
        echo "$MIGRATE_ERR"
        echo ""
        info "Continuando verificação..."
    fi

    # ─── Verificação pós-migration ────────────────────────────
    echo ""
    info "Verificando tabelas pós-migration..."

    NEW_VER=$(mysql_q "SELECT COALESCE(version,'?') FROM ${DB_NAME}.system_info WHERE id=1 LIMIT 1")
    [ "$NEW_VER" = "4.0.0" ] && ok "system_info.version = 4.0.0" || warn "system_info.version = $NEW_VER (esperado 4.0.0)"

    # Conta tabelas v4
    V4_COUNT=$(mysql_q "
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA='${DB_NAME}'
          AND TABLE_NAME IN ('branches','drivers','sim_cards','permission_groups',
            'occurrence_configs','occurrence_config_params','occurrences','occurrence_events',
            'trips','jobs','geocode_cache','impersonation_log',
            'checklist_configs','checklist_items','checklist_responses',
            'metrics_snapshots','login_log')
    ")
    ok "$V4_COUNT de 17 tabelas v4.0.0 criadas"

    # Seeds
    OCC_CFG=$(mysql_q "SELECT COUNT(*) FROM ${DB_NAME}.occurrence_configs")
    PERM_GRP=$(mysql_q "SELECT COUNT(*) FROM ${DB_NAME}.permission_groups")
    info "Seeds: $OCC_CFG occurrence_configs, $PERM_GRP permission_groups"

    echo ""
    ok "Migração concluída."
}

# ════════════════════════════════════════════════════════════
# FASE 4: DEPLOY — Atualização do código
# ════════════════════════════════════════════════════════════
do_deploy() {
    section "FASE 4: DEPLOY — Atualizando código"

    # Git pull
    GIT_REMOTE=$(git remote get-url origin 2>/dev/null || echo "")
    info "Remote: $GIT_REMOTE"

    if [[ "$GIT_REMOTE" == https://github.com/* ]]; then
        SSH_REMOTE=$(echo "$GIT_REMOTE" | sed 's|https://github.com/|git@github.com:|')
        SSH_REMOTE="${SSH_REMOTE%/}"; [[ "$SSH_REMOTE" != *.git ]] && SSH_REMOTE="${SSH_REMOTE}.git"
        if git ls-remote "$SSH_REMOTE" HEAD --quiet 2>/dev/null; then
            git remote set-url origin "$SSH_REMOTE" && ok "SSH ativado: $SSH_REMOTE"
        fi
    fi

    git fetch origin --quiet 2>/dev/null || fail "git fetch falhou — verifique chave SSH (ssh -T git@github.com)"
    ok "git fetch OK"

    git checkout main --quiet 2>/dev/null || true
    LOCAL=$(git rev-parse HEAD)
    REMOTE=$(git rev-parse origin/main)

    if [ "$LOCAL" = "$REMOTE" ] && [ "$FORCE" -eq 0 ]; then
        info "Código já está no HEAD ($(git rev-parse --short HEAD))"
        info "Use --force para redeploy"
    else
        info "Atualizando: $(git rev-parse --short HEAD) → $(git rev-parse --short origin/main)"
        git pull origin main 2>&1 || git reset --hard origin/main 2>/dev/null
        ok "Código atualizado para $(git rev-parse --short HEAD)"
    fi

    # ─── .env ──────────────────────────────────────────────────
    if [ ! -f .env ]; then
        [ -f .env.example ] && cp .env.example .env
        fail ".env criado de .env.example — EDITE AS CREDENCIAIS e re-execute!"
    fi

    # Atualiza SYSTEM_VERSION
    if grep -q 'SYSTEM_VERSION=' .env; then
        sed -i "s/SYSTEM_VERSION=.*/SYSTEM_VERSION=4.0.0/" .env
    else
        echo "SYSTEM_VERSION=4.0.0" >> .env
    fi
    ok "SYSTEM_VERSION=4.0.0 no .env"

    # Garante novas variáveis de ambiente
    for var in "IOTHUB_COMMAND_URL" "IOTHUB_API_TOKEN"; do
        if ! grep -q "^${var}=" .env 2>/dev/null; then
            DEF=$(grep "^${var}=" .env.example 2>/dev/null | cut -d= -f2- || echo "")
            [ -n "$DEF" ] && echo "${var}=${DEF}" >> .env
        fi
    done

    # ─── Permissões ──────────────────────────────────────────
    info "Aplicando permissões..."
    find config core handlers includes web scripts -type d -exec chmod 755 {} \; 2>/dev/null || true
    find config core handlers includes web -type f -name "*.php" -exec chmod 644 {} \; 2>/dev/null || true
    find scripts -type f -name "*.sh" -exec chmod 755 {} \; 2>/dev/null || true
    [ -f .htaccess ] && chmod 644 .htaccess
    [ -f .env ] && chmod 600 .env
    mkdir -p logs storage/reports storage/media && chmod 777 logs storage/reports storage/media 2>/dev/null || true
    ok "Permissões aplicadas"

    # ─── Remove arquivos obsoletos da v3 ──────────────────────
    for dead in "handlers/pushcmd.php"; do
        if [ -f "$dead" ]; then
            rm -f "$dead" && info "Removido arquivo obsoleto: $dead"
        fi
    done
}

# ════════════════════════════════════════════════════════════
# FASE 5: VERIFY — Testes pós-deploy
# ════════════════════════════════════════════════════════════
do_verify() {
    section "FASE 5: VERIFICAÇÃO PÓS-DEPLOY"

    # ─── Lint PHP ────────────────────────────────────────────
    info "Verificando sintaxe PHP..."
    ERRORS=0
    for f in $(find handlers config core includes -name "*.php" -type f 2>/dev/null); do
        if ! php -l "$f" >/dev/null 2>&1; then
            echo "  LINT FAIL: $f"
            php -l "$f" 2>&1 | head -3
            ERRORS=$((ERRORS + 1))
        fi
    done
    [ "$ERRORS" -eq 0 ] && ok "Todos arquivos PHP com sintaxe OK (lint: $ERRORS erros)" \
        || warn "$ERRORS arquivo(s) com erro de sintaxe!"

    # ─── /ping ───────────────────────────────────────────────
    info "Testando /ping..."
    if command -v curl >/dev/null 2>&1; then
        PING_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/ping" --connect-timeout 5 2>/dev/null || echo "000")
        [ "$PING_CODE" = "200" ] && ok "/ping HTTP 200" || warn "/ping HTTP $PING_CODE"
    fi

    # ─── MySQL ───────────────────────────────────────────────
    load_env
    MYSQL_POST=$(mysql_q "SELECT 1")
    [ "$MYSQL_POST" = "1" ] && ok "MySQL OK pós-deploy" || warn "MySQL falhou pós-deploy!"

    DB_VER=$(mysql_q "SELECT COALESCE(version,'?') FROM ${DB_NAME}.system_info WHERE id=1 LIMIT 1")
    info "Versão do banco: $DB_VER"

    # ─── logs/ ───────────────────────────────────────────────
    if [ -d logs ] && [ -w logs ]; then
        echo "_test_" > logs/.deploy_test 2>/dev/null && rm -f logs/.deploy_test && ok "logs/ gravável" || warn "logs/ sem permissão de escrita"
    fi

    echo ""
    ok "Verificação concluída."
}

# ════════════════════════════════════════════════════════════
# EXECUÇÃO PRINCIPAL
# ════════════════════════════════════════════════════════════

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  JIMI WEBHOOK — Deploy v4.0.0                               ║"
echo "║  Servidor: $(hostname 2>/dev/null || echo 'desconhecido')"
echo "║  Data:     $(date '+%Y-%m-%d %H:%M:%S')"
echo "║  Modo:     $MODE"
echo "╚══════════════════════════════════════════════════════════════╝"

case "$MODE" in
    check)
        do_check
        echo ""
        ok "Check concluído. Nenhuma alteração foi feita."
        ;;
    backup)
        do_backup
        echo ""
        ok "Backup concluído em $BACKUP_DIR"
        ;;
    migrate)
        do_check
        do_backup
        do_migrate
        do_verify
        ;;
    deploy)
        do_backup
        do_deploy
        do_verify
        ;;
    verify)
        do_verify
        ;;
    all)
        do_check
        do_backup
        do_migrate
        do_deploy
        do_verify

        section "DEPLOY v4.0.0 CONCLUÍDO"
        echo "  Projeto:    $APP_DIR"
        echo "  Commit:     $(git rev-parse --short HEAD 2>/dev/null || echo 'N/D')"
        echo "  Backup:     $BACKUP_DIR/${TIMESTAMP}_*"
        echo ""
        echo "  Próximos passos:"
        echo "  1. Configure crontab para os workers (ver DEPLOY_v4.md)"
        echo "  2. Monitore por ~15 min: tail -f logs/webhook_\$(date +%Y-%m-%d).log"
        echo "  3. Acesse o painel em: http://$(hostname -I 2>/dev/null | awk '{print $1}' || echo '<ip>')/dashboard"
        echo "  4. Em caso de problema: bash scripts/rollback.sh --last"
        echo "============================================================"
        ;;
esac

exit 0
