#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# JIMI Webhook System — Replay E2E (Fase M.2)
#
# Simula o ciclo completo do motor de ocorrências:
#   1. /ping            — health check
#   2. /pushgps         — posição GPS
#   3. /pushalarm       — alarme DMS "Distração do Motorista" (alertType 143)
#   4. /pushfileupload  — upload de vídeo do evento
#   5. MySQL            — verifica alarme + ocorrência criada + mídia vinculada
#
# Uso:
#   ./scripts/test_e2e.sh                          # auto-detecta o servidor local
#   BASE_URL=http://189.22.240.43 ./scripts/test_e2e.sh   # alvo explícito
#
# Variáveis (todas opcionais):
#   BASE_URL   — default: auto-detecta via /ping em http://localhost (Apache,
#                servidor homolog/produção) e http://localhost:8000 (php -S dev)
#   TOKEN      — default lido do .env (WEBHOOK_TOKEN)
#   TEST_IMEI  — default 868120246598152
#   SKIP_DB    — 1 = pula a verificação MySQL (só replay HTTP)
#
# Requisitos: curl; mysql CLI para a verificação (o alarme 143 só gera
# ocorrência após a migration v4.1.0, que corrige o seed do perfil padrão).
# ═══════════════════════════════════════════════════════════════
set -u

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_IMEI="${TEST_IMEI:-868120246598152}"
SKIP_DB="${SKIP_DB:-0}"

# ── Auto-detecção do BASE_URL (quando não informado) ──────────
# Servidor homolog/produção: Apache na porta 80. Dev: php -S na 8000.
if [ -z "${BASE_URL:-}" ]; then
    for candidate in "http://localhost" "http://localhost:8000" "http://127.0.0.1:8000"; do
        if curl -sS -m 5 "$candidate/ping" 2>/dev/null | grep -q '"pong"'; then
            BASE_URL="$candidate"
            break
        fi
    done
    if [ -z "${BASE_URL:-}" ]; then
        echo "ERRO: nenhum servidor respondeu ao /ping em http://localhost nem :8000." >&2
        echo "      Suba o servidor (Apache ou 'php -S localhost:8000 server.php')" >&2
        echo "      ou informe o alvo: BASE_URL=http://host[:porta] $0" >&2
        exit 1
    fi
fi

# ── Lê .env (mesmo parser manual do config/database.php) ──
env_get() {
    grep -E "^$1=" "$DIR/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d '\r'
}
TOKEN="${TOKEN:-$(env_get WEBHOOK_TOKEN)}"
DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"
DB_NAME="$(env_get DB_NAME)"; DB_USER="$(env_get DB_USER)"; DB_PASS="$(env_get DB_PASS)"

if [ -z "$TOKEN" ]; then
    echo "ERRO: WEBHOOK_TOKEN não encontrado (.env ausente?). Defina TOKEN=..." >&2
    exit 1
fi

# Timestamps UTC únicos por execução (fura a janela de idempotência de 10 min)
NOW_UTC="$(date -u '+%Y-%m-%d %H:%M:%S')"
RUN_ID="$(date -u +%s)"
FILE_NAME="e2e_${RUN_ID}_${TEST_IMEI}.mp4"

PASS=0; FAIL=0
check() { # check <descrição> <ok:0|1>
    if [ "$2" -eq 0 ]; then PASS=$((PASS+1)); echo "  ✔ $1"; else FAIL=$((FAIL+1)); echo "  ✘ $1"; fi
}

post_json() { # post_json <rota> <payload>
    curl -sS -m 15 -X POST "$BASE_URL$1" -H 'Content-Type: application/json' -d "$2"
}

mysql_scalar() { # mysql_scalar <sql> — retorna valor único (ou vazio)
    mysql --host="${DB_HOST:-localhost}" --port="${DB_PORT:-3306}" \
          --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" \
          -N -B -e "$1" 2>/dev/null
}

echo "═══ Replay E2E — $BASE_URL — IMEI $TEST_IMEI — $NOW_UTC UTC ═══"

# ── 1. Health check ──────────────────────────────────────────
echo "[1/5] /ping"
PING="$(curl -sS -m 10 "$BASE_URL/ping" || true)"
echo "$PING" | grep -q -i 'ok\|pong\|"code"' ; check "ping responde" $?

# ── 0b. Garante device cadastrado (necessário para ocorrência) ─
if [ "$SKIP_DB" != "1" ] && command -v mysql >/dev/null 2>&1; then
    mysql_scalar "INSERT IGNORE INTO devices (imei, device_name, customer_id, is_active, created_at)
                  SELECT '$TEST_IMEI', 'Device E2E Test', id, 1, NOW() FROM customers ORDER BY id LIMIT 1;" >/dev/null
fi

# ── 2. pushgps ───────────────────────────────────────────────
echo "[2/5] /pushgps"
GPS_PAYLOAD=$(cat <<EOF
{"token":"$TOKEN","msgType":"pushgps","data_list":[{
  "deviceImei":"$TEST_IMEI","msgClass":0,
  "lat":-23.5505,"lng":-46.6333,"speed":42,"heading":180,
  "gpsTime":"$NOW_UTC","acc":1,"battery":95,"satelliteNum":11
}]}
EOF
)
RESP="$(post_json /pushgps "$GPS_PAYLOAD")"
echo "$RESP" | grep -q '"code":0' ; check "pushgps aceito ($RESP)" $?

# ── 3. pushalarm — Distração do Motorista (JIMI 143) ─────────
echo "[3/5] /pushalarm (alertType 143 — Distração do Motorista)"
ALARM_PAYLOAD=$(cat <<EOF
{"token":"$TOKEN","msgType":"pushalarm","data_list":[{
  "imei":"$TEST_IMEI","msgClass":0,
  "msg":{"alertType":"143","alarmTime":"$NOW_UTC",
         "lat":-23.5505,"lng":-46.6333,"gpsSpeed":42,"alertValue":"1"}
}]}
EOF
)
RESP="$(post_json /pushalarm "$ALARM_PAYLOAD")"
echo "$RESP" | grep -q '"code":0' ; check "pushalarm aceito ($RESP)" $?

# Sob PHP-FPM o processamento é pós-resposta; dá tempo de persistir
sleep 2

# ── 4. pushfileupload — vídeo do evento ──────────────────────
echo "[4/5] /pushfileupload ($FILE_NAME)"
UPLOAD_PAYLOAD=$(cat <<EOF
{"token":"$TOKEN","msgType":"pushfileupload","data_list":[{
  "deviceImei":"$TEST_IMEI","fileName":"$FILE_NAME",
  "result":"SUCCESS","gateTime":"$NOW_UTC","channel":2
}]}
EOF
)
RESP="$(post_json /pushfileupload "$UPLOAD_PAYLOAD")"
echo "$RESP" | grep -q '"code":0' ; check "pushfileupload aceito ($RESP)" $?

sleep 2

# ── 5. Verificação no banco ──────────────────────────────────
echo "[5/5] Verificação MySQL"
if [ "$SKIP_DB" = "1" ]; then
    echo "  (SKIP_DB=1 — verifique manualmente: alarms, occurrences, media_files)"
elif ! command -v mysql >/dev/null 2>&1; then
    echo "  (mysql CLI indisponível — verifique manualmente no dashboard /ocorrencias/dashboard)"
else
    N="$(mysql_scalar "SELECT COUNT(*) FROM alarms WHERE imei='$TEST_IMEI' AND alarm_type='143' AND alarm_time='$NOW_UTC';")"
    [ "${N:-0}" -ge 1 ]; check "alarme 143 gravado em alarms" $?

    OCC_ID="$(mysql_scalar "SELECT id FROM occurrences WHERE imei='$TEST_IMEI' AND alarm_type='Distração do Motorista' AND last_alarm_at='$NOW_UTC' ORDER BY id DESC LIMIT 1;")"
    [ -n "$OCC_ID" ]; check "ocorrência criada (id=${OCC_ID:-nenhuma}) — requer migration v4.1.0" $?

    MEDIA_ID="$(mysql_scalar "SELECT id FROM media_files WHERE imei='$TEST_IMEI' AND file_name='$FILE_NAME' AND download_status='disponivel' LIMIT 1;")"
    [ -n "$MEDIA_ID" ]; check "mídia gravada em media_files (id=${MEDIA_ID:-nenhuma})" $?

    if [ -n "$OCC_ID" ] && [ -n "$MEDIA_ID" ]; then
        LINKED="$(mysql_scalar "SELECT media_file_id FROM occurrences WHERE id=$OCC_ID;")"
        [ "$LINKED" = "$MEDIA_ID" ]; check "link_upload_to_occurrence vinculou mídia $MEDIA_ID à ocorrência $OCC_ID" $?
    else
        check "vínculo mídia↔ocorrência (pré-requisitos falharam)" 1
    fi
fi

echo "═══ Resultado: $PASS ok, $FAIL falha(s) ═══"
[ "$FAIL" -eq 0 ]
