# Deploy v4.0.0 — Jimi Webhook System

> **Data**: 07/07/2026  
> **Alvo**: upgrade de v3.x para v4.0.0 no servidor de homologação (`189.22.240.43`)  
> **Stack**: PHP 8.3 FPM + MySQL 8.0 + Apache 2.4

---

## 0. Pre-flight Checklist

Antes de qualquer alteração, execute o diagnóstico:

```bash
cd /var/www/jimi_webhook
bash scripts/deploy-v4.sh --check
```

Isso exibe:
- Estado do banco (versão, tabelas, registros, último dado recebido)
- Se a migration v4.0.0 já foi aplicada
- Quais tabelas novas existem e quais faltam
- Espaço em disco, PHP modules, Apache status

**NÃO avance se:**
- Disco com >90% de uso
- PHP-FPM parado
- MySQL inacessível
- Dados com gap >1h (possível falha de conectividade nos dispositivos)

---

## 1. Backup Completo

```bash
bash scripts/deploy-v4.sh --backup-only
```

Gera:
- `db_{TIMESTAMP}.sql` — dump completo com routines + triggers
- `env_{TIMESTAMP}.bak` — cópia do `.env`
- Backup armazenado em `/var/backups/jimi_webhook/`

---

## 2. Database Migration — v3.x → v4.0.0

A migration v4.0.0 adiciona:

| O que | Impacto |
|---|---|
| 15 tabelas novas | `branches`, `drivers`, `sim_cards`, `permission_groups`, `occurrence_configs`, `occurrence_config_params`, `occurrences`, `occurrence_events`, `trips`, `jobs`, `geocode_cache`, `impersonation_log`, `checklist_configs`, `checklist_items`, `checklist_responses`, `metrics_snapshots`, `login_log` |
| 4 tabelas alteradas | `users` (+user_type, permission_group_id, photo_url), `customers` (+reseller_id, brand_color, logo_url, occurrence_config_id, checklist_config_id, faceid_enabled), `devices` (+sim_card_id, peripherals, streaming_rotation, streaming_watermark, firmware_version, branch_id), `media_files` (+channel, download_status) |
| 3 índices novos | `alarms(imei, alarm_time)`, `gps_data(imei, gps_time)`, `request_logs(payload_hash, created_at)` |
| 2 seeds | Perfil "Padrão Sistema" (22 parâmetros DMS/ADAS), grupos "Administrador" + "Operador Padrão" |

**A migration é idempotente**: usa `CREATE TABLE IF NOT EXISTS`, `ADD COLUMN IF NOT EXISTS` (via procedure), `INSERT IGNORE`, e `ON DUPLICATE KEY UPDATE`. Pode ser executada múltiplas vezes sem erro.

```bash
bash scripts/deploy-v4.sh --migrate
```

O script verifica:
- Se `system_info.version` já está em `4.0.0` → pula
- Se falta alguma tabela v4 → aplica migration
- Ao final, confirma que todas as 29 tabelas esperadas existem

---

## 3. Deploy do Código

```bash
bash scripts/deploy-v4.sh --deploy
```

Faz:
1. `git pull origin main` (ou `git reset --hard` com `--force`)
2. Aplica permissões (`chmod 755` dirs, `644` PHP, `600` .env, `777` logs)
3. Atualiza `SYSTEM_VERSION=4.0.0` no `.env`
4. Atualiza `.env.example` com novas variáveis de ambiente

**Novas variáveis de ambiente** (adicionar ao `.env` se não existirem):

```bash
IOTHUB_COMMAND_URL=http://localhost:10088/api/device/sendInstruct
IOTHUB_API_TOKEN=123
```

---

## 4. Deploy Completo (recomendado)

```bash
bash scripts/deploy-v4.sh
```

Executa todas as fases em sequência: check → backup → migrate → deploy → verify.

Flag `--force` para redeploy mesmo sem mudanças no git.

---

## 5. Verificação Pós-Deploy

```bash
bash scripts/deploy-v4.sh --verify
```

Verifica:
- [x] Sintaxe PHP em todos os arquivos (79 arquivos)
- [x] `/ping` HTTP 200
- [x] Conexão MySQL mantida
- [x] `logs/` gravável
- [x] Tabelas v4.0.0 criadas (29 tabelas)
- [x] `system_info.version = 4.0.0`
- [x] Seeds aplicados (occurrence_configs, permission_groups)

### Teste funcional rápido

```bash
# 1. Login funciona?
curl -s -o /dev/null -w "%{http_code}" http://localhost/login

# 2. Dashboard responde? (precisa de cookie de sessão)
curl -s -o /dev/null -w "%{http_code}" http://localhost/ -b "jimi_token=..."

# 3. Webhook responde?
curl -s -X POST http://localhost/pushevent \
  -H "Content-Type: application/json" \
  -d '{"token":"'$(grep WEBHOOK_TOKEN .env | cut -d= -f2)'","data_list":[]}'
```

---

## 6. Rollback

Se algo falhar:

```bash
bash scripts/rollback.sh --last
```

Restaura:
- Banco de dados do dump pré-migração
- `.env` do backup
- Código para o commit anterior

---

## 7. Novas Tabelas — Verificação Rápida

```sql
-- Quais tabelas v4 existem?
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'jimi_tracker'
  AND TABLE_NAME IN (
    'branches','drivers','sim_cards','permission_groups',
    'occurrence_configs','occurrence_config_params','occurrences','occurrence_events',
    'trips','jobs','geocode_cache','impersonation_log',
    'checklist_configs','checklist_items','checklist_responses',
    'metrics_snapshots','login_log'
  );

-- Versão atual do sistema?
SELECT version, installation_date, last_update FROM system_info WHERE id = 1;
-- Esperado: 4.0.0

-- Seeds aplicados?
SELECT id, name, is_default FROM occurrence_configs;
-- Esperado: Padrão Sistema (1)

SELECT id, name, user_type FROM permission_groups;
-- Esperado: Administrador (revendedor), Operador Padrão (cliente)
```

---

## 8. Workers (cron — configurar após deploy)

Adicionar ao crontab:

```cron
# Jimi Webhook v4.0.0 — Workers
* * * * * cd /var/www/jimi_webhook && php scripts/worker.php >> logs/worker.log 2>&1
*/15 * * * * cd /var/www/jimi_webhook && php scripts/trip_builder.php >> logs/trip_builder.log 2>&1
*/5 * * * * cd /var/www/jimi_webhook && php scripts/metrics_rollup.php >> logs/metrics.log 2>&1
```

Os workers são **não-críticos** para o funcionamento básico:
- `worker.php`: gera CSVs de exportação (sem ele, `/exportar` mostra jobs pendentes)
- `trip_builder.php`: segmenta viagens (sem ele, `/relatorios/deslocamento` fica vazio)
- `metrics_rollup.php`: pré-computa KPIs (sem ele, `/` Resumo cai para on-the-fly, mais lento)

---

## 9. Notas de Compatibilidade

- **Autenticação**: token-based mantido. Nenhuma ação necessária para usuários existentes.
- **Sessões**: PHP session via cookie `jimi_token` + tabela `sessions` — sem mudança.
- **Webhooks**: todos os 12 endpoints preservados com mesma assinatura.
- **Legacy**: `/dashboard`, `/live`, `/video` ainda funcionam (redirecionam para rotas novas).
- **Novas rotas**: `/ocorrencias/dashboard`, `/video/aovivo`, `/checklist/inspecao`, etc. disponíveis imediatamente.
- **First-run após deploy**: acessar `/` para ver o novo Resumo. Visitar `/setup` **não** é necessário — usuários existentes são preservados.
