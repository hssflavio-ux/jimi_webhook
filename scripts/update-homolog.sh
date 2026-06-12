#!/usr/bin/env bash
# ============================================================
# JIMI WEBHOOK SYSTEM — Atualização Servidor de Homologação v3.0.0
# ============================================================
# Uso:
#   ./scripts/update-homolog.sh              — atualização completa (via SSH)
#   ./scripts/update-homolog.sh --status     — somente status do banco (sem deploy)
#   ./scripts/update-homolog.sh --force      — força redeploy mesmo sem mudanças
#   ./scripts/update-homolog.sh --skip-backup  — pula backup
# ============================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="/var/backups/jimi_webhook"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
STATUS_ONLY=0; FORCE=0; SKIP_BACKUP=0

for arg in "$@"; do
    case $arg in --status) STATUS_ONLY=1 ;; --force) FORCE=1 ;; --skip-backup) SKIP_BACKUP=1 ;; esac
done

cd "$APP_DIR" || { echo "ERRO: Diretório $APP_DIR não encontrado"; exit 1; }

# ════════════════════════════════════════════════════════════
# Funções auxiliares
# ════════════════════════════════════════════════════════════
section()  { echo ""; echo "============================================================"; echo "  $1"; echo "============================================================"; }
ok()       { echo "  ✓ $1"; }
warn()     { echo "  ⚠ $1"; }
fail()     { echo "  ✗ $1"; exit 1; }
info()     { echo "  ℹ $1"; }

# Carrega credenciais do .env para variáveis de ambiente
load_env() {
    if [ -f "$APP_DIR/.env" ]; then
        source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' "$APP_DIR/.env" | sed 's/^/export /')
        source <(grep -E '^WEBHOOK_TOKEN=' "$APP_DIR/.env" | sed 's/^/export /')
        source <(grep -E '^SYSTEM_VERSION=' "$APP_DIR/.env" | sed 's/^/export /')
    fi
    DB_HOST="${DB_HOST:-localhost}"
    DB_PORT="${DB_PORT:-3306}"
    DB_NAME="${DB_NAME:-jimi_tracker}"
    DB_USER="${DB_USER:-root}"
}

# Executa query MySQL e retorna resultado
mysql_query() {
    mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"${DB_PASS}" -N -e "$1" 2>/dev/null || echo "ERRO"
}

# ════════════════════════════════════════════════════════════
# FASE 1: STATUS DO BANCO DE DADOS
# ════════════════════════════════════════════════════════════
db_status() {
    section "FASE 1: STATUS DO BANCO DE DADOS ($(date '+%Y-%m-%d %H:%M:%S'))"

    load_env

    # ─── 1a. Conexão MySQL ─────────────────────────────────────
    info "Verificando conexão MySQL..."
    MYSQL_OK=$(mysql_query "SELECT 1" 2>/dev/null || echo "")
    if [ "$MYSQL_OK" = "1" ]; then
        ok "Conexão MySQL estabelecida ($DB_USER@$DB_HOST:$DB_PORT/$DB_NAME)"
    else
        fail "Conexão MySQL falhou — verifique .env e serviço mysqld"
    fi

    # ─── 1b. Versão e uptime MySQL ─────────────────────────────
    MYSQL_VER=$(mysql_query "SELECT VERSION()")
    MYSQL_UPTIME=$(mysql_query "SELECT CONCAT(FLOOR(VARIABLE_VALUE/86400),'d ', FLOOR((VARIABLE_VALUE%86400)/3600),'h ', FLOOR((VARIABLE_VALUE%3600)/60),'m') FROM performance_schema.global_status WHERE VARIABLE_NAME='Uptime'" 2>/dev/null || echo "N/D")
    info "MySQL $MYSQL_VER — Uptime: $MYSQL_UPTIME"

    # ─── 1c. Tamanho do banco ──────────────────────────────────
    DB_SIZE=$(mysql_query "SELECT CONCAT(ROUND(SUM(data_length + index_length)/1024/1024, 2),' MB') FROM information_schema.TABLES WHERE table_schema='$DB_NAME'")
    info "Tamanho do banco '$DB_NAME': $DB_SIZE"

    # ─── 1d. Versão do sistema registrada ──────────────────────
    DB_SYS_VER=$(mysql_query "SELECT COALESCE(version,'NÃO REGISTRADA') FROM ${DB_NAME}.system_info WHERE id=1 LIMIT 1" 2>/dev/null || echo "N/D")
    DB_INSTALL_DATE=$(mysql_query "SELECT COALESCE(installation_date,'-') FROM ${DB_NAME}.system_info WHERE id=1 LIMIT 1" 2>/dev/null || echo "-")
    DB_LAST_UPDATE=$(mysql_query "SELECT COALESCE(last_update,'-') FROM ${DB_NAME}.system_info WHERE id=1 LIMIT 1" 2>/dev/null || echo "-")
    ENV_VERSION="${SYSTEM_VERSION:-N/D}"
    info "Versão registrada no banco: $DB_SYS_VER | Instalado: $DB_INSTALL_DATE | Última atualização: $DB_LAST_UPDATE"
    info "Versão no .env: $ENV_VERSION"

    # ─── 1e. Contagem de registros por tabela ─────────────────
    echo ""
    echo "  ─── Contagem de Registros ───"

    TABLES=(
        "devices:Dispositivos"
        "gps_data:Coordenadas GPS"
        "heartbeats:Heartbeats"
        "alarms:Alarmes"
        "events:Eventos"
        "device_events:Eventos de Dispositivo"
        "iothub_events:Eventos IoT Hub"
        "commands:Comandos Enviados"
        "command_responses:Respostas de Comandos"
        "media_files:Arquivos de Mídia"
        "resource_lists:Listas de Recursos"
        "lbs_data:Dados LBS"
        "ftp_uploads:Uploads FTP"
        "device_statistics:Estatísticas"
        "request_logs:Logs de Requisição"
        "alarm_types:Tipos de Alarme"
        "system_info:Info do Sistema"
    )

    TOTAL_ROWS=0
    for entry in "${TABLES[@]}"; do
        TABLE="${entry%%:*}"
        LABEL="${entry##*:}"
        COUNT=$(mysql_query "SELECT COUNT(*) FROM ${DB_NAME}.${TABLE}" 2>/dev/null || echo "ERRO")
        if [ "$COUNT" != "ERRO" ] && [ -n "$COUNT" ]; then
            printf "  %-30s %'10d\n" "$LABEL:" "$COUNT"
            TOTAL_ROWS=$((TOTAL_ROWS + COUNT))
        else
            printf "  %-30s %10s\n" "$LABEL:" "--"
        fi
    done
    printf "  %-30s %'10d\n" "TOTAL DE REGISTROS:" "$TOTAL_ROWS"

    # ─── 1f. Dispositivos ativos/com inatividade ──────────────
    echo ""
    echo "  ─── Dispositivos ───"
    DEV_TOTAL=$(mysql_query "SELECT COUNT(*) FROM ${DB_NAME}.devices" 2>/dev/null || echo "0")
    DEV_ACTIVE=$(mysql_query "SELECT COUNT(*) FROM ${DB_NAME}.devices WHERE is_active=1" 2>/dev/null || echo "0")
    DEV_ONLINE=$(mysql_query "SELECT COUNT(*) FROM ${DB_NAME}.device_statistics WHERE is_online=1" 2>/dev/null || echo "0")
    DEV_24H=$(mysql_query "SELECT COUNT(*) FROM ${DB_NAME}.devices WHERE last_communication >= DATE_SUB(NOW(), INTERVAL 24 HOUR)" 2>/dev/null || echo "0")
    DEV_7D=$(mysql_query "SELECT COUNT(*) FROM ${DB_NAME}.devices WHERE last_communication >= DATE_SUB(NOW(), INTERVAL 7 DAY)" 2>/dev/null || echo "0")
    DEV_30D=$(mysql_query "SELECT COUNT(*) FROM ${DB_NAME}.devices WHERE last_communication >= DATE_SUB(NOW(), INTERVAL 30 DAY)" 2>/dev/null || echo "0")
    DEV_SILENT=$(mysql_query "SELECT COUNT(*) FROM ${DB_NAME}.devices WHERE last_communication < DATE_SUB(NOW(), INTERVAL 30 DAY) OR last_communication IS NULL" 2>/dev/null || echo "0")

    echo "  Total cadastrados:     $DEV_TOTAL"
    echo "  Ativos (is_active=1):  $DEV_ACTIVE"
    echo "  Online (stats):        $DEV_ONLINE"
    echo "  Comunicaram <24h:      $DEV_24H"
    echo "  Comunicaram <7d:       $DEV_7D"
    echo "  Comunicaram <30d:      $DEV_30D"
    if [ "$DEV_SILENT" -gt 0 ]; then
        warn "$DEV_SILENT dispositivo(s) sem comunicação há >30 dias"
    fi

    # ─── 1g. Últimos registros por tipo de dado ────────────────
    echo ""
    echo "  ─── Último Registro por Tipo ───"

    LAST_GPS=$(mysql_query "SELECT COALESCE(DATE_FORMAT(MAX(gps_time),'%Y-%m-%d %H:%i:%s'),'NUNCA') FROM ${DB_NAME}.gps_data" 2>/dev/null || echo "ERRO")
    LAST_ALARM=$(mysql_query "SELECT COALESCE(DATE_FORMAT(MAX(alarm_time),'%Y-%m-%d %H:%i:%s'),'NUNCA') FROM ${DB_NAME}.alarms" 2>/dev/null || echo "ERRO")
    LAST_HB=$(mysql_query "SELECT COALESCE(DATE_FORMAT(MAX(heartbeat_time),'%Y-%m-%d %H:%i:%s'),'NUNCA') FROM ${DB_NAME}.heartbeats" 2>/dev/null || echo "ERRO")
    LAST_EVENT=$(mysql_query "SELECT COALESCE(DATE_FORMAT(MAX(event_time),'%Y-%m-%d %H:%i:%s'),'NUNCA') FROM ${DB_NAME}.events" 2>/dev/null || echo "ERRO")
    LAST_MEDIA=$(mysql_query "SELECT COALESCE(DATE_FORMAT(MAX(created_at),'%Y-%m-%d %H:%i:%s'),'NUNCA') FROM ${DB_NAME}.media_files" 2>/dev/null || echo "ERRO")
    LAST_CMD_RESP=$(mysql_query "SELECT COALESCE(DATE_FORMAT(MAX(created_at),'%Y-%m-%d %H:%i:%s'),'NUNCA') FROM ${DB_NAME}.command_responses" 2>/dev/null || echo "ERRO")

    echo "  GPS:                $LAST_GPS"
    echo "  Alarmes:            $LAST_ALARM"
    echo "  Heartbeats:         $LAST_HB"
    echo "  Eventos:            $LAST_EVENT"
    echo "  Mídia:              $LAST_MEDIA"
    echo "  Respostas Comandos: $LAST_CMD_RESP"

    # Alerta se dados estão parados há mais de 1 hora
    ONE_HOUR_AGO=$(date -d '1 hour ago' '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -v-1H '+%Y-%m-%d %H:%M:%S' 2>/dev/null || echo "")
    if [ -n "$ONE_HOUR_AGO" ]; then
        if [ "$LAST_GPS" != "NUNCA" ] && [ "$LAST_GPS" != "ERRO" ] && [[ "$LAST_GPS" < "$ONE_HOUR_AGO" ]]; then
            warn "Último GPS é de $LAST_GPS (>1h atrás) — possível falha de conectividade"
        fi
    fi

    # ─── 1h. Top 5 dispositivos por volume ────────────────────
    echo ""
    echo "  ─── Top 5 Dispositivos (GPS) ───"
    mysql_query "SELECT CONCAT('  ', imei, ' → ', COUNT(*), ' registros (último: ', DATE_FORMAT(MAX(gps_time),'%d/%m %H:%i'), ')')
        FROM ${DB_NAME}.gps_data GROUP BY imei ORDER BY COUNT(*) DESC LIMIT 5" 2>/dev/null || echo "  (sem dados)"

    echo ""
    echo "  ─── Top 5 Dispositivos (Alarmes) ───"
    mysql_query "SELECT CONCAT('  ', imei, ' → ', COUNT(*), ' alarmes (último: ', DATE_FORMAT(MAX(alarm_time),'%d/%m %H:%i'), ')')
        FROM ${DB_NAME}.alarms GROUP BY imei ORDER BY COUNT(*) DESC LIMIT 5" 2>/dev/null || echo "  (sem dados)"

    # ─── 1i. Objetos do banco (procedures, triggers, views) ────
    echo ""
    echo "  ─── Objetos do Banco ───"
    PROC_COUNT=$(mysql_query "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$DB_NAME' AND ROUTINE_TYPE='PROCEDURE'" 2>/dev/null || echo "0")
    FUNC_COUNT=$(mysql_query "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$DB_NAME' AND ROUTINE_TYPE='FUNCTION'" 2>/dev/null || echo "0")
    TRIG_COUNT=$(mysql_query "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB_NAME'" 2>/dev/null || echo "0")
    VIEW_COUNT=$(mysql_query "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$DB_NAME'" 2>/dev/null || echo "0")
    echo "  Stored Procedures: $PROC_COUNT | Functions: $FUNC_COUNT | Triggers: $TRIG_COUNT | Views: $VIEW_COUNT"

    # ─── 1j. Espaço em disco do servidor ───────────────────────
    echo ""
    echo "  ─── Espaço em Disco ───"
    df -h "$APP_DIR" 2>/dev/null | tail -1 | awk '{printf "  Partição: %s | Total: %s | Usado: %s (%s) | Livre: %s\n", $1, $2, $3, $5, $4}'

    # ─── 1k. Índices (apenas informativo) ─────────────────────
    INDEX_COUNT=$(mysql_query "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$DB_NAME'" 2>/dev/null || echo "0")
    info "Total de índices: $INDEX_COUNT"

    echo ""
    ok "Status do banco concluído."
}

# ════════════════════════════════════════════════════════════
# FASE 2: BACKUP
# ════════════════════════════════════════════════════════════
do_backup() {
    section "FASE 2: BACKUP ($TIMESTAMP)"

    if [ "$SKIP_BACKUP" -eq 1 ]; then
        warn "Backup pulado (--skip-backup)"
        return
    fi

    load_env
    mkdir -p "$BACKUP_DIR"

    # Backup .env
    if [ -f .env ]; then
        cp .env "$BACKUP_DIR/env_$TIMESTAMP.bak"
        ok ".env → backup/env_${TIMESTAMP}.bak"
    fi

    # Backup banco de dados
    if mysqldump -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
        -p"${DB_PASS}" --single-transaction --routines --triggers \
        "${DB_NAME:-jimi_tracker}" > "$BACKUP_DIR/db_$TIMESTAMP.sql" 2>/dev/null; then
        BACKUP_SIZE=$(du -h "$BACKUP_DIR/db_$TIMESTAMP.sql" | cut -f1)
        ok "Banco → backup/db_${TIMESTAMP}.sql ($BACKUP_SIZE)"
    else
        warn "mysqldump falhou — verifique credenciais e espaço em disco"
    fi

    # Limitar backups antigos (últimos 10)
    ls -1t "$BACKUP_DIR"/db_*.sql 2>/dev/null | tail -n +11 | xargs -r rm -f
    ls -1t "$BACKUP_DIR"/env_*.bak 2>/dev/null | tail -n +11 | xargs -r rm -f
}

# ════════════════════════════════════════════════════════════
# FASE 3: ATUALIZAÇÃO DO CÓDIGO
# ════════════════════════════════════════════════════════════
do_deploy() {
    section "FASE 3: DEPLOY — Atualizando código"

    GIT_REMOTE=$(git remote get-url origin 2>/dev/null || echo "")
    info "Remote: $GIT_REMOTE"

    # Se o remote for HTTPS, testa SSH e converte se disponível
    if [[ "$GIT_REMOTE" == https://github.com/* ]]; then
        info "Remote HTTPS detectado — testando SSH..."
        SSH_REMOTE=$(echo "$GIT_REMOTE" | sed 's|https://github.com/|git@github.com:|')
        SSH_REMOTE="${SSH_REMOTE%/}"
        if [[ "$SSH_REMOTE" != *.git ]]; then SSH_REMOTE="${SSH_REMOTE}.git"; fi

        if git ls-remote "$SSH_REMOTE" HEAD --quiet 2>/dev/null; then
            git remote set-url origin "$SSH_REMOTE"
            ok "Remote alterado para: $SSH_REMOTE"
            GIT_REMOTE="$SSH_REMOTE"
        else
            warn "SSH indisponível — mantendo HTTPS"
        fi
    fi

    if git fetch origin --quiet 2>/dev/null; then
        ok "Conexão GitHub OK (SSH)"
    else
        fail "git fetch falhou. Verifique a chave SSH no servidor: ssh -T git@github.com"
    fi

    # Verificar branch
    git checkout main --quiet 2>/dev/null || true
    LOCAL=$(git rev-parse HEAD)
    REMOTE=$(git rev-parse origin/main)

    if [ "$LOCAL" = "$REMOTE" ] && [ "$FORCE" -eq 0 ]; then
        info "Já estamos no commit mais recente ($(git rev-parse --short HEAD))"
        info "Use --force para redeploy mesmo sem mudanças."
        return
    fi

    echo ""
    info "Atualizando: $(git rev-parse --short HEAD) → $(git rev-parse --short origin/main)"

    # Listar arquivos alterados
    CHANGED=$(git diff --name-only HEAD origin/main 2>/dev/null | head -30 || true)
    if [ -n "$CHANGED" ]; then
        echo "  Arquivos que serão atualizados:"
        echo "$CHANGED" | while read -r f; do echo "    - $f"; done
    fi

    # Pull
    git pull origin main 2>&1

    # Se foi force e já estava no mesmo commit, puxa mesmo assim
    if [ "$FORCE" -eq 1 ] && [ "$(git rev-parse HEAD)" = "$LOCAL" ]; then
        git reset --hard origin/main 2>/dev/null
    fi

    ok "Código atualizado para $(git rev-parse --short HEAD)"

    # ─── 3a. Verificar/Criar .env ──────────────────────────────
    if [ ! -f .env ]; then
        if [ -f .env.example ]; then
            cp .env.example .env
            warn ".env criado a partir de .env.example — EDITE AS CREDENCIAIS!"
            exit 0
        fi
    fi

    # Atualizar SYSTEM_VERSION do .env.example
    if [ -f .env ] && [ -f .env.example ]; then
        REPO_VERSION=$(grep 'SYSTEM_VERSION=' .env.example | cut -d= -f2)
        LOCAL_VERSION=$(grep 'SYSTEM_VERSION=' .env | cut -d= -f2 || echo "")
        if [ "$LOCAL_VERSION" != "$REPO_VERSION" ] && [ -n "$LOCAL_VERSION" ]; then
            info "Versão .env: $LOCAL_VERSION → $REPO_VERSION (repositório)"
            sed -i "s/SYSTEM_VERSION=.*/SYSTEM_VERSION=$REPO_VERSION/" .env
        fi
    fi

    # ─── 3b. Migração do banco ─────────────────────────────────
    load_env
    if [ -f "mysql/migration_v2.0.0.sql" ]; then
        DB_VERSION=$(mysql_query "SELECT COALESCE(version,'0') FROM ${DB_NAME}.system_info WHERE id=1 LIMIT 1" 2>/dev/null || echo "0")
        info "Versão do banco: $DB_VERSION"

        if [ "$DB_VERSION" != "2.0.0" ] || [ "$FORCE" -eq 1 ]; then
            info "Aplicando migration_v2.0.0.sql..."
            if mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"${DB_PASS}" "$DB_NAME" < mysql/migration_v2.0.0.sql 2>/tmp/migrate_err.log; then
                ok "Migração aplicada com sucesso"
            else
                warn "Erro na migração:"
                cat /tmp/migrate_err.log 2>/dev/null || true
            fi
        else
            ok "Banco já está na versão $DB_VERSION — migração desnecessária"
        fi
    fi

    # ─── 3c. Permissões ────────────────────────────────────────
    info "Configurando permissões..."
    chmod 755 "$APP_DIR"
    find config core handlers includes web -type d -exec chmod 755 {} \; 2>/dev/null || true
    find config core handlers includes web -type f -exec chmod 644 {} \; 2>/dev/null || true
    [ -f .htaccess ] && chmod 644 .htaccess
    [ -f .env ] && chmod 600 .env
    mkdir -p logs && chmod 777 logs
    find logs -type f -exec chmod 666 {} \; 2>/dev/null || true
    ok "Permissões configuradas"
}

# ════════════════════════════════════════════════════════════
# FASE 4: VERIFICAÇÃO PÓS-DEPLOY
# ════════════════════════════════════════════════════════════
do_verify() {
    section "FASE 4: VERIFY — Testes pós-deploy"

    # Sintaxe PHP
    info "Verificando sintaxe PHP..."
    ERRORS=0
    for f in $(find handlers config core includes -name "*.php" -type f 2>/dev/null); do
        if ! php -l "$f" >/dev/null 2>&1; then
            fail "Erro sintaxe: $f"
            php -l "$f" 2>&1 | head -3
            ERRORS=$((ERRORS + 1))
        fi
    done
    [ "$ERRORS" -eq 0 ] && ok "Todos os arquivos PHP com sintaxe OK"

    # Teste /ping
    info "Testando /ping..."
    if command -v curl >/dev/null 2>&1; then
        PING_RESP=$(curl -s -o /tmp/ping_resp.txt -w "%{http_code}" "http://localhost/ping" --connect-timeout 5 2>/dev/null || echo "000")
        if [ "$PING_RESP" = "200" ]; then
            PING_BODY=$(cat /tmp/ping_resp.txt)
            ok "/ping HTTP 200: $PING_BODY"
        else
            warn "/ping HTTP $PING_RESP — verifique servidor web"
        fi
        rm -f /tmp/ping_resp.txt
    fi

    # logs/ gravável
    if [ -d logs ] && [ -w logs ]; then
        echo "test" > logs/.deploy_test 2>/dev/null && rm -f logs/.deploy_test \
            && ok "logs/ gravável" || warn "logs/ não está gravável"
    fi

    # Conexão pós-deploy com o banco
    load_env
    POST_DB_OK=$(mysql_query "SELECT 1" 2>/dev/null || echo "")
    if [ "$POST_DB_OK" = "1" ]; then
        ok "Conexão MySQL mantida após deploy"
    else
        fail "Conexão MySQL PERDIDA após deploy!"
    fi
}

# ════════════════════════════════════════════════════════════
# FASE 5: RESUMO FINAL
# ════════════════════════════════════════════════════════════
do_summary() {
    section "RESUMO DA ATUALIZAÇÃO — $(date '+%Y-%m-%d %H:%M:%S')"

    load_env
    echo "  Projeto:    $APP_DIR"
    echo "  Commit:     $(git rev-parse --short HEAD 2>/dev/null || echo 'N/D')"
    echo "  Branch:     $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'N/D')"
    echo "  PHP:        $(php -r 'echo PHP_VERSION;' 2>/dev/null || echo 'N/D')"
    echo "  MySQL:      $(mysql_query "SELECT VERSION()" 2>/dev/null || echo 'N/D')"
    echo "  DB Version: $(mysql_query "SELECT COALESCE(version,'-') FROM ${DB_NAME}.system_info WHERE id=1" 2>/dev/null || echo 'N/D')"
    echo "  Token:      ${WEBHOOK_TOKEN:+configurado}${WEBHOOK_TOKEN:-NÃO configurado}"
    echo "  Backup:     $BACKUP_DIR/${TIMESTAMP}_*"
    echo ""
    echo "  Próximos passos:"
    echo "  1. Monitore: tail -f $APP_DIR/logs/webhook_\$(date +%Y-%m-%d).log"
    echo "  2. Acesse o painel: http://<ip>/dashboard"
    echo "  3. Rollback se necessário: $APP_DIR/scripts/rollback.sh $TIMESTAMP"
    echo "============================================================"
}

# ════════════════════════════════════════════════════════════
# EXECUÇÃO PRINCIPAL
# ════════════════════════════════════════════════════════════

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  JIMI WEBHOOK — Atualização Homologação v3.0.0              ║"
echo "║  Servidor: $(hostname 2>/dev/null || echo 'desconhecido')"
echo "║  Data:     $(date '+%Y-%m-%d %H:%M:%S')"
echo "╚══════════════════════════════════════════════════════════════╝"

# FASE 1: Status do banco (SEMPRE roda)
db_status

# Se for apenas status, para aqui
if [ "$STATUS_ONLY" -eq 1 ]; then
    echo ""
    ok "Modo --status: somente conferência do banco. Nenhuma alteração foi feita."
    exit 0
fi

# FASE 2: Backup
do_backup

# FASE 3: Deploy
do_deploy

# FASE 4: Verificação
do_verify

# FASE 5: Resumo
do_summary

exit 0
