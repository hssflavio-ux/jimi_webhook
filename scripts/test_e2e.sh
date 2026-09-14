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
#   BASE_URL=http://189.22.240.43 ./scripts/test_e2e.sh   # alvo explícito (HOMOLOG)
#   # produção é https://bycamera.ia.br (186.248.143.197) — o replay grava alarme
#   # e ocorrência de verdade, então não aponte para lá sem querer exatamente isso
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

# ── 0c. Garante veículo + câmera instalada + motorista de teste (v4.19.0) ──
# `resolve_installation_for_imei()` (includes/functions.php) só resolve
# `vehicle_id` com uma instalação ABERTA — sem isto, todo o mecanismo de
# driver_sessions (pushgps/pushhb/pushalarm) fica no-op pra este IMEI, porque
# a regra "câmera sem veículo não abre/fecha sessão" é intencional (mesma
# nota do CLAUDE.md sobre customer_id/vehicle_id NULL). Usado pelo Playwright
# tests/driver_sessions.spec.js — este script só semeia, não afirma nada
# sobre sessão de motorista.
TEST_DRIVER_IDENTIFIER="${TEST_DRIVER_IDENTIFIER:-E2E_DRIVER_1}"
if [ "$SKIP_DB" != "1" ] && command -v mysql >/dev/null 2>&1; then
    CUST_ID="$(mysql_scalar "SELECT customer_id FROM devices WHERE imei='$TEST_IMEI' LIMIT 1;")"
    DEV_ID="$(mysql_scalar "SELECT id FROM devices WHERE imei='$TEST_IMEI' LIMIT 1;")"
    if [ -n "$CUST_ID" ] && [ -n "$DEV_ID" ]; then
        VEH_ID="$(mysql_scalar "SELECT id FROM vehicles WHERE customer_id=$CUST_ID AND plate='E2E TEST VEHICLE' LIMIT 1;")"
        if [ -z "$VEH_ID" ]; then
            mysql_scalar "INSERT INTO vehicles (customer_id, plate, is_active) VALUES ($CUST_ID, 'E2E TEST VEHICLE', 1);" >/dev/null
            VEH_ID="$(mysql_scalar "SELECT id FROM vehicles WHERE customer_id=$CUST_ID AND plate='E2E TEST VEHICLE' ORDER BY id DESC LIMIT 1;")"
        fi

        # Fecha qualquer instalação aberta que não seja esta ($DEV_ID <-> $VEH_ID)
        # — a invariante é "no máximo 1 aberta por device_id E por vehicle_id".
        mysql_scalar "UPDATE device_installations SET removed_at=NOW()
                      WHERE device_id=$DEV_ID AND vehicle_id<>$VEH_ID AND removed_at IS NULL;" >/dev/null
        mysql_scalar "UPDATE device_installations SET removed_at=NOW()
                      WHERE vehicle_id=$VEH_ID AND device_id<>$DEV_ID AND removed_at IS NULL;" >/dev/null
        OPEN_INST="$(mysql_scalar "SELECT id FROM device_installations WHERE device_id=$DEV_ID AND vehicle_id=$VEH_ID AND removed_at IS NULL LIMIT 1;")"
        if [ -z "$OPEN_INST" ]; then
            mysql_scalar "INSERT INTO device_installations (device_id, vehicle_id, customer_id, installed_at)
                          VALUES ($DEV_ID, $VEH_ID, $CUST_ID, NOW());" >/dev/null
        fi

        DRIVER_ID="$(mysql_scalar "SELECT id FROM drivers WHERE identifier='$TEST_DRIVER_IDENTIFIER' LIMIT 1;")"
        if [ -z "$DRIVER_ID" ]; then
            mysql_scalar "INSERT INTO drivers (customer_id, name, identifier, is_active)
                          VALUES ($CUST_ID, 'Motorista E2E 1', '$TEST_DRIVER_IDENTIFIER', 1);" >/dev/null
        fi
        # Segundo motorista — o spec de troca de motorista precisa de dois
        # identifiers distintos pra provar que a sessão TROCA, não só grava.
        DRIVER_ID_2="$(mysql_scalar "SELECT id FROM drivers WHERE identifier='${TEST_DRIVER_IDENTIFIER}_2' LIMIT 1;")"
        if [ -z "$DRIVER_ID_2" ]; then
            mysql_scalar "INSERT INTO drivers (customer_id, name, identifier, is_active)
                          VALUES ($CUST_ID, 'Motorista E2E 2', '${TEST_DRIVER_IDENTIFIER}_2', 1);" >/dev/null
        fi
    fi
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

    # Pelo vínculo do PRÓPRIO alarme, não pelo nome: a v4.8.3 renomeou o 143
    # para "DMS: Distração do Motorista" e a consulta por nome antigo acusou
    # falha com a ocorrência criada (medido em produção, 14/09/2026).
    OCC_ID="$(mysql_scalar "SELECT oe.occurrence_id FROM occurrence_events oe JOIN alarms a ON a.id = oe.alarm_id
                            WHERE a.imei='$TEST_IMEI' AND a.alarm_type='143' AND a.alarm_time='$NOW_UTC' AND a.status='active'
                            ORDER BY oe.occurrence_id DESC LIMIT 1;")"
    [ -n "$OCC_ID" ]; check "ocorrência criada (id=${OCC_ID:-nenhuma})" $?

    MEDIA_ID="$(mysql_scalar "SELECT id FROM media_files WHERE imei='$TEST_IMEI' AND file_name='$FILE_NAME' AND download_status='disponivel' LIMIT 1;")"
    [ -n "$MEDIA_ID" ]; check "mídia gravada em media_files (id=${MEDIA_ID:-nenhuma})" $?

    if [ -n "$OCC_ID" ] && [ -n "$MEDIA_ID" ]; then
        LINKED="$(mysql_scalar "SELECT media_file_id FROM occurrences WHERE id=$OCC_ID;")"
        [ "$LINKED" = "$MEDIA_ID" ]; check "link_upload_to_occurrence vinculou mídia $MEDIA_ID à ocorrência $OCC_ID" $?
    else
        check "vínculo mídia↔ocorrência (pré-requisitos falharam)" 1
    fi
fi

# ── 6-8. v4.19.1 / v4.20.0 / v4.19.2 ────────────────────────
# Rodam scripts PHP locais contra o MESMO banco do .env — o replay tem de
# rodar no servidor. Nunca contra produção: o passo 8 reconstrói 1 dia de
# segmentos da frota inteira (`state_builder --rebuild`).
utc_offset() { # utc_offset <segundos> — instante relativo a NOW_UTC
    local base
    base="$(date -u -d "$NOW_UTC" +%s 2>/dev/null || date -u -j -f '%Y-%m-%d %H:%M:%S' "$NOW_UTC" +%s)"
    date -u -d "@$((base + $1))" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || date -u -r "$((base + $1))" '+%Y-%m-%d %H:%M:%S'
}
post_gps_at() { # post_gps_at <gps_time UTC> <acc 0|1> <velocidade>
    post_json /pushgps "{\"token\":\"$TOKEN\",\"msgType\":\"pushgps\",\"data_list\":[{\"deviceImei\":\"$TEST_IMEI\",\"msgClass\":0,\"lat\":-23.5505,\"lng\":-46.6333,\"speed\":$3,\"heading\":180,\"gpsTime\":\"$1\",\"acc\":$2,\"satelliteNum\":11}]}" >/dev/null
}
table_exists() { # table_exists <tabela>
    [ "$(mysql_scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='$1';")" = "1" ]
}

if [ "$SKIP_DB" = "1" ] || ! command -v mysql >/dev/null 2>&1; then
    echo "[6-8] (sem verificação MySQL — passos de v4.19.1, v4.19.2 e v4.20.0 pulados)"
else
    # ── 6. Celular do JT/T (265-2) gera ocorrência — v4.19.1 ─────
    echo "[6] /pushalarm JT/T 265-2 (Chamada Telefônica)"
    post_json /pushalarm "{\"token\":\"$TOKEN\",\"msgType\":\"pushalarm\",\"data_list\":[{\"imei\":\"$TEST_IMEI\",\"msgClass\":1,\"msg\":{\"alertType\":\"265\",\"alarmType\":\"2\",\"alarmTime\":\"$NOW_UTC\",\"lat\":-23.5505,\"lng\":-46.6333,\"gpsSpeed\":42}}]}" >/dev/null
    sleep 2
    OCC_265="$(mysql_scalar "SELECT o.id FROM occurrences o
                             JOIN occurrence_events oe ON oe.occurrence_id = o.id
                             JOIN alarms a ON a.id = oe.alarm_id
                             WHERE a.imei='$TEST_IMEI' AND a.alarm_type='265' AND a.alarm_subtype=2
                               AND a.alarm_time='$NOW_UTC' LIMIT 1;")"
    [ -n "$OCC_265" ]; check "JT/T 265-2 gera ocorrência (id=${OCC_265:-nenhuma}) — requer migração v4.19.1" $?

    # ── 7. Mapa de risco — v4.20.0 ───────────────────────────────
    echo "[7] Mapa de risco (risk_builder)"
    if [ -z "${VEH_ID:-}" ] || ! table_exists risk_events; then
        check "mapa de risco: pré-requisitos (veículo de teste e migração v4.20.0)" 1
    else
        # 40 min dirigindo antes do alarme 143 do passo 3 (ignição ligada).
        for m in 40 30 20 10; do post_gps_at "$(utc_offset $((-m * 60)))" 1 50; done
        sleep 2
        BRT_DATE="$(utc_offset -10800 | cut -c1-10)"
        php "$DIR/scripts/risk_builder.php" --desde="$BRT_DATE" --veiculo="$VEH_ID" >/dev/null 2>&1
        RE="$(mysql_scalar "SELECT CONCAT(re.risk_group,'|',re.weight,'|',COALESCE(re.continuous_s,-1),'|',IF(re.cell_y IS NULL,'sem','com'))
                            FROM risk_events re JOIN alarms a ON a.id = re.alarm_id
                            WHERE a.imei='$TEST_IMEI' AND a.alarm_type='143' AND a.alarm_time='$NOW_UTC' LIMIT 1;")"
        [ "$(echo "$RE" | cut -d'|' -f1)" = "distracao" ]; check "alarme 143 entra como 'distracao' ($RE)" $?
        case "$(echo "$RE" | cut -d'|' -f2)" in 1|3|5) true ;; *) false ;; esac; check "peso vem do perfil (1, 3 ou 5)" $?
        [ "$(echo "$RE" | cut -d'|' -f3)" -ge 2400 ] 2>/dev/null; check "direção contínua >= 40 min no instante do alarme" $?
        [ "$(echo "$RE" | cut -d'|' -f4)" = "com" ]; check "alarme ganhou célula de 1 km" $?
        EXP="$(mysql_scalar "SELECT COALESCE(SUM(driving_s),0) FROM risk_exposure WHERE vehicle_id=$VEH_ID AND brt_date='$BRT_DATE';")"
        [ "${EXP:-0}" -ge 1800 ]; check "exposição do dia gravada (${EXP:-0} s em movimento)" $?

        # O fim do mesmo alarme vira linha própria em alarms — não pode contar de novo.
        post_json /pushalarm "{\"token\":\"$TOKEN\",\"msgType\":\"pushalarm\",\"data_list\":[{\"imei\":\"$TEST_IMEI\",\"msgClass\":0,\"msg\":{\"alertType\":\"removeAlarmType\",\"removeAlarmType\":\"143\",\"alarmTime\":\"$NOW_UTC\",\"lat\":-23.5505,\"lng\":-46.6333}}]}" >/dev/null
        sleep 2
        php "$DIR/scripts/risk_builder.php" --desde="$BRT_DATE" --veiculo="$VEH_ID" >/dev/null 2>&1
        N_FIM="$(mysql_scalar "SELECT COUNT(*) FROM alarms WHERE imei='$TEST_IMEI' AND alarm_type='143' AND alarm_time='$NOW_UTC' AND status='resolved';")"
        N_RE="$(mysql_scalar "SELECT COUNT(*) FROM risk_events re JOIN alarms a ON a.id = re.alarm_id
                              WHERE a.imei='$TEST_IMEI' AND a.alarm_type='143' AND a.alarm_time='$NOW_UTC';")"
        [ "${N_FIM:-0}" -ge 1 ] && [ "${N_RE:-0}" = "1" ]; check "fim de alarme gravado (${N_FIM:-0}) e fora do mapa (${N_RE:-0} linha)" $?
    fi

    # ── 8. state_builder recalcula posição atrasada — v4.19.2 ────
    echo "[8] state_builder x posição atrasada"
    if ! table_exists worker_watermarks; then
        check "state_builder: pré-requisito (migração v4.19.2)" 1
    else
        P1="$(utc_offset -21600)"; P2="$(utc_offset -14400)"; LATE="$(utc_offset -18000)"
        post_gps_at "$P1" 1 30
        post_gps_at "$P2" 1 30
        sleep 2
        php "$DIR/scripts/state_builder.php" 1 --rebuild >/dev/null 2>&1
        ANTES="$(mysql_scalar "SELECT COUNT(*) FROM device_state_segments WHERE imei='$TEST_IMEI' AND state='offline'
                               AND started_at < '$LATE' AND ended_at > '$LATE';")"
        [ "${ANTES:-0}" -ge 1 ]; check "pré-condição: vão de 2 h vira segmento offline (se falhar, a janela já tinha pontos)" $?
        post_gps_at "$LATE" 1 30
        sleep 2
        php "$DIR/scripts/state_builder.php" >/dev/null 2>&1
        DEPOIS="$(mysql_scalar "SELECT COUNT(*) FROM device_state_segments WHERE imei='$TEST_IMEI' AND state='offline'
                                AND started_at < '$LATE' AND ended_at > '$LATE';")"
        [ "${DEPOIS:-1}" = "0" ]; check "ponto atrasado desfaz o offline que o cobria ($DEPOIS restante)" $?
        SOBREP="$(mysql_scalar "SELECT COUNT(*) FROM device_state_segments a JOIN device_state_segments b
                                ON a.imei = b.imei AND a.id < b.id
                               AND a.started_at < COALESCE(b.ended_at, '9999-12-31') AND b.started_at < COALESCE(a.ended_at, '9999-12-31')
                               WHERE a.imei='$TEST_IMEI' AND a.started_at >= '$P1';")"
        [ "${SOBREP:-1}" = "0" ]; check "linha do tempo reconstruída sem sobreposição" $?
    fi
fi

echo "═══ Resultado: $PASS ok, $FAIL falha(s) ═══"
[ "$FAIL" -eq 0 ]
