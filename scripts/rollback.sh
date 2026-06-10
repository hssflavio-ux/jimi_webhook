#!/usr/bin/env bash
# ============================================================
# JIMI WEBHOOK SYSTEM — Rollback Script v2.0.0
# ============================================================
# Uso:
#   ./scripts/rollback.sh                    — lista backups disponíveis
#   ./scripts/rollback.sh <timestamp>        — reverte para o backup especificado
#   ./scripts/rollback.sh --last             — reverte para o último backup
# ============================================================
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="/var/backups/jimi_webhook"
TIMESTAMP="${1:-}"

cd "$APP_DIR" || { echo "ERRO: Diretório $APP_DIR não encontrado"; exit 1; }

# ─── Listar backups disponíveis ──────────────────────────────
if [ -z "$TIMESTAMP" ] || [ "$TIMESTAMP" = "--list" ]; then
    echo "=== Backups Disponíveis ==="
    echo ""
    echo "Bancos de dados:"
    for f in "$BACKUP_DIR"/db_*.sql; do
        [ -f "$f" ] || continue
        TS=$(basename "$f" | sed 's/db_//;s/\.sql//')
        SIZE=$(du -h "$f" | cut -f1)
        echo "  $TS  ($SIZE)"
    done
    echo ""
    echo "Variáveis de ambiente (.env):"
    for f in "$BACKUP_DIR"/env_*.bak; do
        [ -f "$f" ] || continue
        TS=$(basename "$f" | sed 's/env_//;s/\.bak//')
        echo "  $TS"
    done
    echo ""
    echo "Uso: ./scripts/rollback.sh <timestamp>"
    exit 0
fi

# ─── --last: pegar o backup mais recente ─────────────────────
if [ "$TIMESTAMP" = "--last" ]; then
    TIMESTAMP=$(ls -1t "$BACKUP_DIR"/db_*.sql 2>/dev/null | head -1 | sed 's/.*db_//;s/\.sql//' || true)
    if [ -z "$TIMESTAMP" ]; then
        echo "Nenhum backup encontrado em $BACKUP_DIR"
        exit 1
    fi
    echo "Usando backup mais recente: $TIMESTAMP"
fi

# ─── Validar existência do backup ────────────────────────────
if [ ! -f "$BACKUP_DIR/env_$TIMESTAMP.bak" ] && [ ! -f "$BACKUP_DIR/db_$TIMESTAMP.sql" ]; then
    echo "ERRO: Nenhum backup encontrado para o timestamp '$TIMESTAMP'"
    echo "Use './scripts/rollback.sh' para listar backups disponíveis."
    exit 1
fi

echo ""
echo "============================================================"
echo "  ROLLBACK para $TIMESTAMP"
echo "============================================================"

# ─── 1. Restaurar .env ──────────────────────────────────────
if [ -f "$BACKUP_DIR/env_$TIMESTAMP.bak" ]; then
    cp "$BACKUP_DIR/env_$TIMESTAMP.bak" .env
    echo "  ✓ .env restaurado do backup"
else
    echo "  ⚠ Backup do .env não encontrado — mantendo atual"
fi

# ─── 2. Restaurar banco de dados ────────────────────────────
if [ -f .env ] && [ -f "$BACKUP_DIR/db_$TIMESTAMP.sql" ]; then
    source <(grep -E '^DB_(HOST|PORT|NAME|USER|PASS)=' .env | sed 's/^/export /')
    echo "  Restaurando banco ${DB_NAME:-jimi_tracker}..."
    if mysql -h"${DB_HOST:-localhost}" -P"${DB_PORT:-3306}" -u"${DB_USER:-root}" \
        -p"${DB_PASS}" "${DB_NAME:-jimi_tracker}" < "$BACKUP_DIR/db_$TIMESTAMP.sql" 2>/tmp/rollback_err.log; then
        echo "  ✓ Banco restaurado com sucesso"
    else
        echo "  ✗ FALHA na restauração do banco:"
        cat /tmp/rollback_err.log 2>/dev/null || true
        exit 1
    fi
else
    echo "  ⚠ Backup do banco não encontrado — pulando restauração"
fi

# ─── 3. Reverter código Git ─────────────────────────────────
CURRENT=$(git rev-parse --short HEAD)
echo "  Revertendo código (atual: $CURRENT)..."

# Tenta voltar 1 commit
if git rev-parse HEAD~1 >/dev/null 2>&1; then
    git reset --hard HEAD~1 2>/dev/null \
        && echo "  ✓ Código revertido para $(git rev-parse --short HEAD)" \
        || { echo "  ✗ git reset falhou"; exit 1; }
else
    echo "  ⚠ Apenas 1 commit no histórico — não é possível reverter"
    echo "    Para restaurar manualmente: git checkout <hash_do_commit_anterior>"
fi

# ─── 4. Verificação rápida ──────────────────────────────────
echo ""
echo "  Verificando /ping..."
if command -v curl >/dev/null 2>&1; then
    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/ping" --connect-timeout 5 2>/dev/null || echo "000")
    [ "$HTTP_CODE" = "200" ] && echo "  ✓ /ping HTTP 200" || echo "  ⚠ /ping HTTP $HTTP_CODE"
fi

echo ""
echo "============================================================"
echo "  ROLLBACK CONCLUÍDO — $(date)"
echo "  Backup aplicado: $TIMESTAMP"
echo "============================================================"
