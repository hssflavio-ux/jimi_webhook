#!/usr/bin/env bash
# ============================================================
# JIMI WEBHOOK SYSTEM — Crontab Setup & Verify v4.0.0
# ============================================================
# Uso:
#   bash scripts/crontab-setup.sh              # instala ou verifica workers
#   bash scripts/crontab-setup.sh --install    # força instalação (mesmo se existir)
#   bash scripts/crontab-setup.sh --check      # apenas verifica (sem alterar)
#   bash scripts/crontab-setup.sh --remove     # remove workers do crontab
# ============================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
MODE="${1:---auto}"

PHP_BIN=$(command -v php || echo "/usr/bin/php")

CRON_MARKER="# jimi_webhook_v4_workers"
WORKER_ENTRIES=(
    "scripts/worker.php:worker.log:1 min:*/1 * * * *"
    "scripts/trip_builder.php:trip_builder.log:15 min:*/15 * * * *"
    "scripts/metrics_rollup.php:metrics.log:5 min:*/5 * * * *"
)

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[0;33m'; NC='\033[0m'
ok()    { echo -e "  ${GREEN}OK${NC}  $1"; }
warn()  { echo -e "  ${YELLOW}WARN${NC} $1"; }
fail()  { echo -e "  ${RED}FAIL${NC} $1"; }
info()  { echo "  ...  $1"; }
header() { echo ""; echo "============================================================"; echo "  $1"; echo "============================================================"; }

cd "$APP_DIR"

# ════════════════════════════════════════════════════════════
# Verifica se o script de worker existe
# ════════════════════════════════════════════════════════════
for entry in "${WORKER_ENTRIES[@]}"; do
    SCRIPT=$(echo "$entry" | cut -d: -f1)
    if [ ! -f "$APP_DIR/$SCRIPT" ]; then
        fail "Script não encontrado: $SCRIPT"
        echo "  Certifique-se de que os arquivos estão no diretório correto."
        exit 1
    fi
done

# ════════════════════════════════════════════════════════════
# Garante diretórios de logs e storage
# ════════════════════════════════════════════════════════════
mkdir -p "$APP_DIR/logs" && chmod 777 "$APP_DIR/logs" 2>/dev/null || true
mkdir -p "$APP_DIR/storage/reports" "$APP_DIR/storage/media"
chmod 777 "$APP_DIR/storage" "$APP_DIR/storage/reports" "$APP_DIR/storage/media" 2>/dev/null || true

# ════════════════════════════════════════════════════════════
# Crontab: remove existing entries (--remove ou --install)
# ════════════════════════════════════════════════════════════
remove_entries() {
    local tmpfile
    tmpfile=$(mktemp)
    crontab -l 2>/dev/null | grep -v "$CRON_MARKER" > "$tmpfile" || true
    crontab "$tmpfile" 2>/dev/null || { warn "Falha ao atualizar crontab (pode estar vazio)"; }
    rm -f "$tmpfile"
}

if [ "$MODE" = "--remove" ]; then
    header "REMOVENDO WORKERS DO CRONTAB"
    info "Removendo entradas com marcador: $CRON_MARKER"
    remove_entries
    ok "Workers removidos do crontab"
    header "CRONTAB ATUAL"
    crontab -l 2>/dev/null | sed '/^$/d' || echo "  (vazio)"
    exit 0
fi

# ════════════════════════════════════════════════════════════
# Verifica se as entradas JÁ existem no crontab
# ════════════════════════════════════════════════════════════
CURRENT_CRON=$(crontab -l 2>/dev/null || echo "")
FOUND_COUNT=0
MISSING_COUNT=0

for entry in "${WORKER_ENTRIES[@]}"; do
    SCRIPT=$(echo "$entry" | cut -d: -f1)
    if echo "$CURRENT_CRON" | grep -qF "$SCRIPT"; then
        FOUND_COUNT=$((FOUND_COUNT + 1))
    else
        MISSING_COUNT=$((MISSING_COUNT + 1))
    fi
done

# ════════════════════════════════════════════════════════════
# Modo --check: apenas relata e sai
# ════════════════════════════════════════════════════════════
check_only() {
    header "VERIFICAÇÃO DO CRONTAB ($(date '+%Y-%m-%d %H:%M:%S'))"

    if [ "$FOUND_COUNT" -eq "${#WORKER_ENTRIES[@]}" ]; then
        ok "Todos os ${#WORKER_ENTRIES[@]} workers estão configurados"
    else
        warn "$FOUND_COUNT de ${#WORKER_ENTRIES[@]} workers configurados ($MISSING_COUNT faltando)"
    fi

    echo ""
    for entry in "${WORKER_ENTRIES[@]}"; do
        SCRIPT=$(echo "$entry" | cut -d: -f1)
        LOGFILE=$(echo "$entry" | cut -d: -f2)
        INTERVAL=$(echo "$entry" | cut -d: -f3)
        CRON_EXPR=$(echo "$entry" | cut -d: -f4-)

        if echo "$CURRENT_CRON" | grep -qF "$SCRIPT"; then
            LINE=$(echo "$CURRENT_CRON" | grep -F "$SCRIPT" | head -1 | sed 's/^[[:space:]]*//')
            printf "  %-30s ${GREEN}OK${NC}     %s\n" "$SCRIPT" "${LINE:0:60}"
        else
            printf "  %-30s ${RED}MISSING${NC}  %s\n" "$SCRIPT" "(esperado: $CRON_EXPR cd $APP_DIR && $PHP_BIN $SCRIPT >> $APP_DIR/logs/$LOGFILE 2>&1)"
        fi
    done

    echo ""
    info "Endereço do projeto: $APP_DIR"
    info "PHP: $PHP_BIN"

    # Testa execução dos scripts (dry-run sintático)
    echo ""
    info "Testando sintaxe PHP dos workers..."
    for entry in "${WORKER_ENTRIES[@]}"; do
        SCRIPT=$(echo "$entry" | cut -d: -f1)
        if $PHP_BIN -l "$APP_DIR/$SCRIPT" >/dev/null 2>&1; then
            ok "Lint: $SCRIPT"
        else
            fail "Lint: $SCRIPT"
            $PHP_BIN -l "$APP_DIR/$SCRIPT" 2>&1 | head -2
        fi
    done

    echo ""
    if [ "$MISSING_COUNT" -gt 0 ]; then
        warn "Execute 'bash scripts/crontab-setup.sh --install' para instalar os workers faltantes."
    fi
}

if [ "$MODE" = "--check" ]; then
    check_only
    exit 0
fi

# ════════════════════════════════════════════════════════════
# Modo --install ou --auto (padrão)
# ════════════════════════════════════════════════════════════
if [ "$MODE" = "--install" ] || [ "$MODE" = "--auto" ]; then

    if [ "$FOUND_COUNT" -eq "${#WORKER_ENTRIES[@]}" ] && [ "$MODE" != "--install" ]; then
        check_only
        echo ""
        ok "Todos os workers já estão configurados. Use --install para forçar reinstalação."
        exit 0
    fi

    header "INSTALANDO WORKERS NO CRONTAB"

    # Remove entradas antigas primeiro
    if [ "$FOUND_COUNT" -gt 0 ]; then
        info "Removendo $FOUND_COUNT entrada(s) antiga(s)..."
    fi
    remove_entries

    # Constrói novo crontab
    tmpfile=$(mktemp)

    # Preserva entradas existentes (exceto as marcadas)
    crontab -l 2>/dev/null | grep -v "$CRON_MARKER" > "$tmpfile" || true

    # Remove linhas vazias duplicadas
    sed -i '/^$/d' "$tmpfile" 2>/dev/null || true

    # Adiciona marcador e workers
    echo "" >> "$tmpfile"
    echo "$CRON_MARKER" >> "$tmpfile"

    for entry in "${WORKER_ENTRIES[@]}"; do
        SCRIPT=$(echo "$entry" | cut -d: -f1)
        LOGFILE=$(echo "$entry" | cut -d: -f2)
        INTERVAL=$(echo "$entry" | cut -d: -f3)
        CRON_EXPR=$(echo "$entry" | cut -d: -f4-)

        CRON_LINE="$CRON_EXPR cd $APP_DIR && $PHP_BIN $APP_DIR/$SCRIPT >> $APP_DIR/logs/$LOGFILE 2>&1"
        echo "$CRON_LINE" >> "$tmpfile"
        ok "$SCRIPT — a cada $INTERVAL → logs/$LOGFILE"
    done

    echo "" >> "$tmpfile"

    # Aplica crontab
    if crontab "$tmpfile" 2>/dev/null; then
        ok "Crontab instalado com ${#WORKER_ENTRIES[@]} workers"
    else
        fail "Falha ao instalar crontab"
        echo ""
        echo "Conteúdo que seria instalado:"
        cat "$tmpfile"
        rm -f "$tmpfile"
        exit 1
    fi

    rm -f "$tmpfile"

    # ─── Verificação do crontab instalado ───────────────────
    echo ""
    header "VERIFICAÇÃO PÓS-INSTALAÇÃO"
    crontab -l 2>/dev/null | grep "$CRON_MARKER" -A 10 || warn "Marcador não encontrado no crontab!"

    echo ""
    info "Endereço do projeto: $APP_DIR"
    info "PHP: $PHP_BIN"

    # Testa execução (sintaxe PHP)
    echo ""
    for entry in "${WORKER_ENTRIES[@]}"; do
        SCRIPT=$(echo "$entry" | cut -d: -f1)
        if $PHP_BIN -l "$APP_DIR/$SCRIPT" >/dev/null 2>&1; then
            ok "Lint OK: $SCRIPT"
        else
            fail "Erro sintaxe: $SCRIPT"
            $PHP_BIN -l "$APP_DIR/$SCRIPT" 2>&1 | head -2
        fi
    done

    echo ""
    ok "Crontab configurado com sucesso."
    echo ""
    echo "  Monitore a execução:"
    echo "    tail -f $APP_DIR/logs/worker.log"
    echo "    tail -f $APP_DIR/logs/trip_builder.log"
    echo "    tail -f $APP_DIR/logs/metrics.log"
    echo ""
    echo "  Para verificar: bash scripts/crontab-setup.sh --check"
    echo "  Para remover:   bash scripts/crontab-setup.sh --remove"

else
    echo "Modo desconhecido: $MODE"
    echo "Uso: bash scripts/crontab-setup.sh [--install|--check|--remove]"
    exit 1
fi
