# Plano de Implementação — v4.4.0 a v4.7.0

**Origem**: `docs/ANALISE_MANUAL_JIMI.md`, sequência recomendada **2 → 1 → 3 → 4**.
**Base**: v4.3.0 (commit `83f9849`).
**Data**: 28/07/2026.

| Fase | Versão | Entrega | Migração | Estado |
|---|---|---|---|---|
| 1 | **v4.4.0** | Motor de notificações (sino, pop-up, som, e-mail) | `migration_v4.4.0.sql` | ✅ publicada |
| — | **v4.4.1** | Credenciais de SMTP cadastráveis pela interface (`/config-smtp`) | `migration_v4.4.1.sql` | ✅ publicada |
| 2 | **v4.5.0** | Geocercas + POI com relatório de entrada/saída | `migration_v4.5.0.sql` | ✅ feita, a publicar |
| 3 | **v4.6.0** | Relatórios Parada / Ociosidade / Ignição / Velocidade / Status da Frota | `migration_v4.6.0.sql` | ✅ feita, a publicar |
| 4 | **v4.7.0** | Relatório agendado por e-mail + modelos de relatório | `migration_v4.7.0.sql` | ✅ feita, a publicar |
| **5** | — | **Atualizar a Central de Ajuda (`/wiki`)** — ver §5 | — | ⬜ pendente |

> **Status em 30/07/2026 — IMPLEMENTAÇÃO COMPLETA (Fases 1 a 4)**:
> - Fase 1 **concluída e publicada no homolog** (v4.4.0 + v4.4.1, commit `4e60322`).
> - Fase 2 (**v4.5.0 — geocercas**) **implementada e verificada localmente** (commit `16f3184`); falta publicar.
> - Fase 3 (**v4.6.0 — relatórios operacionais**) **implementada e verificada localmente**; falta publicar.
> - Fase 4 (**v4.7.0 — relatório agendado + modelos**) **implementada e verificada localmente**; falta publicar.
> - **Resta apenas a Fase 5** (atualizar a `/wiki`), que por decisão registrada é o último passo da iniciativa.
> - **Publicação pendente das TRÊS versões de uma vez**: `sudo ./scripts/deploy.sh` (o gate semântico aplica v4.5.0, v4.6.0 e v4.7.0 em sequência) + `bash scripts/crontab-setup.sh --install` (**7 workers** — o deploy não instala cron) + marcar **"Geocercas"** e **"Agendamentos"** em `/grupos-permissao` para grupos restritos. As 5 telas da Fase 3 **não** precisam de liberação: herdam a permissão `relatorios` existente.
> - **Backfill depois de publicar**: `php scripts/state_builder.php 30` fora do horário de pico. Sem ele, os 4 relatórios de estado só mostram dados a partir da primeira execução do cron.
> - **Para o envio agendado funcionar**: credenciais em `/config-smtp` (ou `.env`) **e `APP_URL` correta** — é a base do link quando o anexo passa de `MAIL_MAX_ATTACH_MB`. O envio real nunca foi validado contra provedor externo; localmente foi validado contra um SMTP de captura.
>
> **Desvios da Fase 3 em relação ao plano original**, todos deliberados:
> 1. **`includes/report_segments.php`** (não previsto): Paradas e Ociosidade são a mesma consulta com um `state` diferente. Dois handlers de 250 linhas quase idênticos reintroduziriam na exibição exatamente a duplicação que §3.1 eliminou no banco. Os handlers viraram ~30 linhas de configuração.
> 2. **`resolve_current_state()` resolve o estado corrente na LEITURA**, em vez de o worker gravar um segmento `offline` de cauda. Gravar exigiria um segmento de duração zero por rodada e quebraria a idempotência; e a verdade muda entre duas rodadas do cron sem que dado novo entre no banco, então a conta do silêncio pertence à leitura.
> 3. **O relatório de Ignição exclui `offline` da janela do `LAG`** e amplia a leitura interna em 2 dias. Sem isso, o silêncio inventaria acionamentos que ninguém observou, e a primeira transição do período se perderia.
> 4. **Segmento de duração zero é descartado** (§3.3 não previa o caso): ponto isolado seguido de buraco colidiria com o `offline` do vão na chave `(imei, started_at)`.
> 5. **Colunas de conveniência**: `point_count` nas duas tabelas, `avg_speed`/`max_lat`/`max_lng` em `speeding_events` (o mapa aponta onde a velocidade máxima ocorreu, não onde a infração começou).
> 6. **`fmt_duration()` mora em `fleet_state.php`** e não em cada handler — o `fmt_dwell()` do relatório de geocercas é privado daquele arquivo, e duas grafias de duração na mesma suíte confundem quem compara telas lado a lado.
>
> **Desvios da Fase 2 em relação ao plano original**, todos deliberados:
> 1. **Histerese em polígono mede distância até a aresta**, não até a bbox expandida (§2.4). A bbox manteria "dentro" um veículo parado no vão da concavidade de uma cerca em "L" — exatamente o caso que o critério de aceite exige classificar certo.
> 2. **`geofences.alert_emails`** (JSON, até 3) acrescentada ao DDL: `notification_rules` é indexada por `alarm_type` e não sabe expressar "cerca X". Sem a coluna, o alerta de geocerca seria sino-e-pop-up apenas.
> 3. **Colunas de conveniência**: `geofences.description`/`created_by`, `geofence_events.speed`. O relatório mostra a velocidade no instante da travessia, que é a primeira pergunta de quem audita uma saída não autorizada.
> 4. **Cerca nova semeia o estado sem gerar evento** — não estava no plano e é o que impede uma enxurrada de "entradas" retroativas ao desenhar uma cerca sobre a garagem.

### Por que esta ordem

```
Fase 1 (notificações)  ──┬──▶ Fase 2 (geocercas: alerta de entrada/saída)
                         └──▶ Fase 4 (envio do relatório por e-mail)

Fase 3 (relatórios)    ─────▶ Fase 4 (novos tipos exportáveis/agendáveis)
```

A Fase 1 entrega o `includes/mailer.php` e o despacho assíncrono que as Fases 2 e 4 consomem.
A Fase 3 é independente das outras três e pode ser paralelizada por outra pessoa — só precisa
estar pronta **antes** da Fase 4 para que os novos relatórios entrem na lista de agendáveis.

---

## 0. Convenções obrigatórias

Regras do repositório que este plano segue e que qualquer implementação precisa respeitar
(`CLAUDE.md`, `AGENTS.md`, `docs/adr/ADR-001.md`):

| Regra | Aplicação neste plano |
|---|---|
| **UTC no banco, BRT na exibição** | Toda coluna `datetime` grava UTC. Exibição sempre por `fmt_brt()`. Filtro de dia digitado pelo usuário passa por `brt_day_range_to_utc()`. Agregação por hora/dia usa `CONVERT_TZ(col,'+00:00','-03:00')`. **O `send_hour` dos agendamentos (Fase 4) é BRT e precisa virar UTC ao calcular `next_run_at`.** |
| **Nada pesado dentro da transação do webhook** | O `pushalarm.php` só faz `INSERT`. Envio de e-mail e chamada HTTP acontecem no `worker.php`. Mesma lição já registrada em `flush_pending_video_requests()`. |
| **Multi-tenant** | Toda query nova filtra por `customer_id` resolvido de `get_customer_id()`. Todo worker propaga o `customer_id` do device. |
| **Prepared statements** | Sem concatenação de variável em SQL. Listas `IN (...)` montadas com placeholders numerados, como em `rel_alarmes.php:55-61`. |
| **CSRF** | Todo `POST` novo chama `csrf_verify()`; todo form embute `csrf_field()`. |
| **Sem build step** | CSS novo entra inline em `web/layout_base.php`. JS novo é vanilla. Sem npm no app. |
| **Migração idempotente** | Reusar as procedures `add_column_if_not_exists` / `create_index_if_not_exists` (padrão de `migration_v4.0.0.sql` e `v4.3.0.sql`) e encerrar com `INSERT ... ON DUPLICATE KEY UPDATE` em `system_info`. |
| **Lint** | `.githooks/pre-commit` roda `php -l`. Todo arquivo novo precisa passar. |

---

# FASE 1 — v4.4.0 — Motor de notificações

## 1.1 Objetivo

Alarme relevante deixa de depender de alguém com o dashboard aberto: gera registro no sino,
pop-up em tempo real, som opcional e e-mail, conforme regra configurável por cliente × tipo de
alarme.

## 1.2 Modelo de dados

```sql
-- Regras: o que notificar, por qual canal, para quem
CREATE TABLE IF NOT EXISTS `notification_rules` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned DEFAULT NULL COMMENT 'NULL = regra global (fallback)',
    `alarm_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Código, nome ou categoria (matching triplo)',
    `min_risk` enum('baixo','medio','alto') COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'NULL = qualquer risco',
    `notify_bell` tinyint(1) NOT NULL DEFAULT 1,
    `notify_popup` tinyint(1) NOT NULL DEFAULT 0,
    `notify_sound` tinyint(1) NOT NULL DEFAULT 0,
    `notify_email` tinyint(1) NOT NULL DEFAULT 0,
    `emails` json DEFAULT NULL COMMENT 'Array de até 3 destinatários',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_nr_customer_type` (`customer_id`,`alarm_type`),
    CONSTRAINT `fk_nr_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Caixa de entrada (sino)
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned DEFAULT NULL,
    `user_id` bigint unsigned DEFAULT NULL COMMENT 'NULL = todos os usuários do cliente',
    `kind` enum('alarme','ocorrencia','geocerca','lembrete','sistema') COLLATE utf8mb4_unicode_ci NOT NULL,
    `severity` enum('info','warning','critical') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
    `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
    `body` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `link_url` varchar(300) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `ref_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'occurrence|alarm|geofence_event',
    `ref_id` bigint unsigned DEFAULT NULL,
    `want_popup` tinyint(1) NOT NULL DEFAULT 0,
    `want_sound` tinyint(1) NOT NULL DEFAULT 0,
    `is_read` tinyint(1) NOT NULL DEFAULT 0,
    `read_at` datetime DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_cust_unread` (`customer_id`,`is_read`,`created_at`),
    KEY `idx_notif_user` (`user_id`,`is_read`,`created_at`),
    CONSTRAINT `fk_notif_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Alterações em tabela existente:

```sql
ALTER TABLE `jobs` MODIFY COLUMN `type`
    enum('report','video_download','rollup','notification') NOT NULL;

CALL add_column_if_not_exists('jobs', 'attempts',
    "tinyint unsigned NOT NULL DEFAULT 0 COMMENT 'Tentativas de execução (retry)' AFTER `error_message`");
```

`jobs.attempts` existe porque envio de e-mail falha por motivo transitório (SMTP fora do ar). Sem
retry, uma indisponibilidade de 2 minutos perde a notificação em definitivo.

**Seed**: uma regra global (`customer_id = NULL`) por categoria crítica já presente em
`alarm_types` — SOS, pânico, DMS de risco alto — com `notify_bell = 1`, `notify_popup = 1`,
`notify_email = 0`. E-mail nasce desligado de propósito: ninguém deve receber e-mail sem ter
pedido.

## 1.3 Arquivos

| Arquivo | Ação | Conteúdo |
|---|---|---|
| `includes/mailer.php` | **novo** | Cliente SMTP mínimo (`stream_socket_client` + STARTTLS/SSL, AUTH LOGIN, anexo MIME base64). Sem Composer — o projeto não tem gerenciador de pacotes. `send_mail($to, $subject, $htmlBody, $attachments = [])`. |
| `includes/notification_engine.php` | **novo** | `resolve_notification_rule()`, `notify()`, `notify_from_occurrence()`. Só grava — nunca envia. |
| `handlers/notificacoesdata.php` | **novo** | AJAX: `GET` devolve `{unread, items[]}`; `POST action=read\|read_all`. `require_login()` + escopo por `customer_id`. |
| `handlers/config_notificacoes.php` | **novo** | Tela de regras — matriz tipo de alarme × canais, modelada em `config_ocorrencias.php` (rows dinâmicas). |
| `scripts/worker.php` | alterar | `case 'notification'` no `switch` (linha 27) + `processNotificationJob()`; incrementar `attempts` e só marcar `falhou` após 3 tentativas. |
| `includes/occurrence_engine.php` | alterar | Chamar `notify_from_occurrence()` dentro de `process_alarm_to_occurrence()`, **apenas no ramo de ocorrência nova** (após `create_occurrence()`, linha 78). |
| `web/layout_base.php` | alterar | Ícone de sino + contador em `.main-header-meta` (antes do `fleet-counter`, linha 1019); painel dropdown; polling 30s; toast e `Audio` para `want_popup`/`want_sound`. CSS inline no bloco de estilos. |
| `handlers/router.php` | alterar | `notificacoesdata` em `$ajaxRoutes` (linha 63); `'config-notificacoes' => 'config_notificacoes.php'` em `$renamedRoutes` (linha 73). |
| `handlers/grupos_permissao.php` | alterar | `'config-notificacoes' => 'Config. Notificações'` em `$screens` (linha 19). |
| `.env.example` | alterar | Bloco SMTP + kill-switch. |
| `mysql/migration_v4.4.0.sql` | **novo** | DDL acima + seed + `system_info` = 4.4.0. |

## 1.4 Fluxo

```
pushalarm.php (dentro da transação)
   └─ process_alarm_to_occurrence()
        ├─ [ocorrência agrupada] → nada (evita spam de alarme repetido)
        └─ [ocorrência NOVA]
             └─ notify_from_occurrence($db, $occId, $alarm, $risk)
                  ├─ resolve_notification_rule(customer_id, alarm_type, risk)
                  │     matching triplo código/nome/categoria + fallback regra global
                  ├─ se notify_bell  → INSERT notifications (want_popup, want_sound)
                  └─ se notify_email → INSERT jobs (type='notification', params={...})
                                        ↑ apenas enfileira

── commit da transação, HTTP 200 já entregue ──

scripts/worker.php (cron */1 min)
   └─ case 'notification' → send_mail() via includes/mailer.php
                             falhou? attempts++ e volta para 'pendente' (até 3)

web/layout_base.php (polling 30s)
   └─ GET /notificacoesdata → badge + dropdown
                            → want_popup ? toast : —
                            → want_sound ? Audio.play() : —
```

### Guardas anti-rajada

Notificar por **ocorrência nova**, e não por alarme, já elimina a maior fonte de repetição — o
agrupamento do motor de ocorrências (janela padrão de 10 min) faz o trabalho. Sobre isso:

1. **Teto por cliente**: máximo 60 notificações/hora por `customer_id`; ao estourar, grava uma
   única notificação-resumo ("N alarmes suprimidos") e para.
2. **Dedupe de e-mail**: não enfileirar e-mail para o mesmo `(imei, alarm_type)` visto há menos de
   15 min, consultando `jobs` com `type='notification'` — mesmo padrão do dedupe por `alarm_label`
   em `flush_pending_video_requests()` (`occurrence_engine.php:452-471`).

### Retenção

`notifications` cresce rápido. Estender `auth_cleanup()` (`includes/auth.php`, já roda
probabilisticamente em ~1% das requests) para apagar notificações lidas com mais de 30 dias e
não lidas com mais de 90.

## 1.5 `.env`

```ini
NOTIFY_ENABLED=1          # kill-switch global
SMTP_HOST=
SMTP_PORT=587
SMTP_SECURE=tls           # tls | ssl | none
SMTP_USER=
SMTP_PASS=
SMTP_FROM=nao-responda@exemplo.com.br
SMTP_FROM_NAME=Jimi Tracker
MAIL_MAX_ATTACH_MB=5      # acima disso, envia link em vez de anexo (usado na Fase 4)
```

Comparar `NOTIFY_ENABLED` com `trim(getenv(...)) === '0'`, e não com `?:` — `'0'` é falsy em PHP
e o kill-switch silenciaria por engano. A armadilha já está documentada em
`occurrence_engine.php:355-359`.

## 1.6 Critérios de aceite

- [ ] `php -l` limpo nos 4 arquivos novos e nos 5 alterados.
- [ ] `bash scripts/test_e2e.sh` com um alarme DMS de risco alto → 1 linha em `notifications` e
      1 job `notification` pendente.
- [ ] `php scripts/worker.php` envia o e-mail; com SMTP inválido, `attempts` chega a 3 e o job
      termina `falhou` sem travar a fila.
- [ ] Reenviar o mesmo alarme dentro da janela de agrupamento **não** gera segunda notificação.
- [ ] Sino mostra contador; "marcar todas como lidas" zera; escopo por cliente confirmado com
      dois tenants.
- [ ] `NOTIFY_ENABLED=0` desativa tudo sem erro.
- [ ] Spec Playwright `tests/notificacoes.spec.js`: badge, dropdown, marcar como lida.

---

# FASE 2 — v4.5.0 — Geocercas

## 2.1 Objetivo

Cercas desenhadas na plataforma (círculo ou polígono), vinculadas a equipamentos, gerando eventos
de entrada/saída, alerta pela Fase 1 e relatório de permanência.

## 2.2 Modelo de dados

```sql
CREATE TABLE IF NOT EXISTS `geofences` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
    `kind` enum('cerca','poi') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cerca',
    `shape` enum('circulo','poligono') COLLATE utf8mb4_unicode_ci NOT NULL,
    `center_lat` decimal(10,8) DEFAULT NULL,
    `center_lng` decimal(11,8) DEFAULT NULL,
    `radius_m` int unsigned DEFAULT NULL,
    `polygon` json DEFAULT NULL COMMENT 'Array [[lat,lng],...] quando shape=poligono',
    `bbox_min_lat` decimal(10,8) DEFAULT NULL COMMENT 'Pré-filtro barato (calculado ao salvar)',
    `bbox_max_lat` decimal(10,8) DEFAULT NULL,
    `bbox_min_lng` decimal(11,8) DEFAULT NULL,
    `bbox_max_lng` decimal(11,8) DEFAULT NULL,
    `alert_on` enum('entrada','saida','ambos','nenhum') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ambos',
    `color` varchar(9) COLLATE utf8mb4_unicode_ci DEFAULT '#0052ff',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_gf_customer` (`customer_id`,`is_active`),
    CONSTRAINT `fk_gf_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `geofence_devices` (
    `geofence_id` bigint unsigned NOT NULL,
    `imei` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
    PRIMARY KEY (`geofence_id`,`imei`),
    KEY `idx_gfd_imei` (`imei`),
    CONSTRAINT `fk_gfd_fence` FOREIGN KEY (`geofence_id`) REFERENCES `geofences`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado atual: evita reprocessar histórico a cada rodada
CREATE TABLE IF NOT EXISTS `geofence_state` (
    `geofence_id` bigint unsigned NOT NULL,
    `imei` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
    `is_inside` tinyint(1) NOT NULL DEFAULT 0,
    `last_gps_time` datetime DEFAULT NULL,
    PRIMARY KEY (`geofence_id`,`imei`),
    CONSTRAINT `fk_gfs_fence` FOREIGN KEY (`geofence_id`) REFERENCES `geofences`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `geofence_events` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `geofence_id` bigint unsigned NOT NULL,
    `customer_id` bigint unsigned DEFAULT NULL,
    `imei` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
    `event_type` enum('entrada','saida') COLLATE utf8mb4_unicode_ci NOT NULL,
    `event_time` datetime NOT NULL COMMENT 'UTC — gps_time do ponto que causou a transição',
    `latitude` decimal(10,8) NOT NULL,
    `longitude` decimal(11,8) NOT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_gfe_dedupe` (`geofence_id`,`imei`,`event_time`),
    KEY `idx_gfe_customer_time` (`customer_id`,`event_time`),
    CONSTRAINT `fk_gfe_fence` FOREIGN KEY (`geofence_id`) REFERENCES `geofences`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

A `UNIQUE KEY uk_gfe_dedupe` torna o worker seguro para reexecução: rodar duas vezes sobre a mesma
janela não duplica evento.

## 2.3 Arquivos

| Arquivo | Ação | Conteúdo |
|---|---|---|
| `includes/geofence.php` | **novo** | `point_in_geofence()`, `point_in_polygon()` (ray casting), `geofence_bbox()`. |
| `scripts/geofence_worker.php` | **novo** | Cron `*/2 min`. Avalia pontos novos, grava eventos, chama `notify()`. |
| `handlers/geocercas.php` | **novo** | CRUD + desenho no mapa Leaflet + vínculo de equipamentos. |
| `handlers/rel_geocercas.php` | **novo** | Relatório entrada/saída/permanência. |
| `includes/functions.php` | alterar | Promover `haversine()` de `trip_builder.php` para cá como `haversine_km()`. |
| `scripts/trip_builder.php` | alterar | Passar a usar `haversine_km()` (remove a cópia local, linha 227). |
| `handlers/router.php` | alterar | `'geocercas'` em `$simpleRoutes`; `'geocercas' => 'rel_geocercas.php'` no `$subrouteMap['relatorios']`. |
| `web/layout_base.php` | alterar | Item "Geocercas" no grupo Cadastros; "Geocercas" no grupo Relatórios. |
| `handlers/grupos_permissao.php` | alterar | `'geocercas' => 'Geocercas'` em `$screens`. |
| `scripts/crontab-setup.sh` | alterar | Nova linha no array `CRON_JOBS` (linha 20). |
| `mysql/migration_v4.5.0.sql` | **novo** | DDL acima + `system_info` = 4.5.0. |

### Sobre a distância

`includes/functions.php:98` já tem `calculate_distance()`, mas ela usa lei dos cossenos esférica e
**retorna 0 quando `$lat1 == 0` ou `$lat2 == 0`** (linha 103) — uma guarda que sabota o teste de
raio. `scripts/trip_builder.php:227` tem uma `haversine()` correta, porém privada do script.
Decisão: promover a `haversine()` para `includes/functions.php` como `haversine_km()`, usá-la nos
dois lugares e **não** tocar em `calculate_distance()` (há chamadores legados).

## 2.4 Algoritmo do worker

```php
// Só devices que têm cerca vinculada — não varre a frota inteira
foreach (devices_com_cerca() as $dev) {
    $fences = fences_do_device($dev['imei']);          // + estado atual
    $since  = min(last_gps_time de cada estado) ?? '-1 day';
    $points = gps_data WHERE imei = ? AND gps_time > ? ORDER BY gps_time ASC;
    //        ↑ usa idx_imei_time (imei, gps_time DESC), já existente

    foreach ($points as $p) {
        if (!is_valid_coordinate($p)) continue;         // descarta (0,0) — R06
        foreach ($fences as $f) {
            // 1. pré-filtro por bounding box (comparação de float, sem trigonometria)
            // 2. círculo:   haversine_km(centro, ponto) * 1000 <= radius_m
            //    polígono:  point_in_polygon($p, $f['polygon'])
            $inside = point_in_geofence($p, $f);
            if ($inside !== $f['state']['is_inside']) {
                INSERT IGNORE geofence_events (...);    // uk_gfe_dedupe protege
                if ($f['alert_on'] casa com a transição) notify(...);  // Fase 1
                UPSERT geofence_state;
            }
        }
        UPSERT geofence_state.last_gps_time = $p['gps_time'];
    }
}
```

**Custo**: o pré-filtro por bbox descarta a esmagadora maioria dos pares ponto×cerca antes de
qualquer trigonometria. Com 200 veículos × 3 cercas × ~300 pontos/2 min o worker fica na casa de
dezenas de milissegundos por rodada.

**Anti-flapping**: veículo parado na borda da cerca oscila dentro/fora e gera dezenas de eventos.
Mitigação: histerese de 50 m — para *sair*, a distância precisa superar `radius_m + 50`; para
*entrar*, ficar abaixo de `radius_m`. Em polígono, aplicar a histerese sobre a bbox expandida.

## 2.5 Desenho no mapa

Usar **Leaflet puro**, sem `leaflet-draw`: círculo = clique define o centro e um campo numérico
define o raio; polígono = cliques acumulam vértices com botão "fechar". Evita nova dependência de
CDN por um ganho de UX pequeno. Se depois houver demanda, `leaflet-draw` entra pelo mesmo caminho
que Leaflet/Chart.js/flv.js já usam.

## 2.6 Relatório de permanência

Pareamento entrada→saída com função de janela (MySQL 8 disponível):

```sql
SELECT e.imei, g.name AS cerca, e.event_time AS entrada,
       LEAD(e.event_time) OVER (PARTITION BY e.geofence_id, e.imei ORDER BY e.event_time) AS saida
FROM geofence_events e
JOIN geofences g ON g.id = e.geofence_id
WHERE e.customer_id = :cid AND e.event_time BETWEEN :df AND :dt
```

Filtra-se `event_type = 'entrada'` na camada externa; a permanência é `saida - entrada`, com
`saida IS NULL` exibido como "em permanência". Página segue o padrão de `rel_alarmes.php`:
`clamp_report_range()`, `report_sort_params()`, `report_pagination()`, `stream_export()`.

## 2.7 Critérios de aceite

- [x] `php -l` limpo (0 erros em todo o projeto); `bash -n` limpo no `crontab-setup.sh`.
      **Cron ainda não instalado no homolog** — pendente do deploy.
- [x] Cerca circular de 200 m sobre um trajeto gera exatamente 1 entrada e 1 saída.
      *(21 pontos sintéticos, sul→norte, passo de 50 m.)*
- [x] Cerca poligonal côncava (formato em "L") classifica corretamente — ponto no vão da
      concavidade dá **fora**, embora esteja **dentro** da bounding box.
- [x] Rodar o worker duas vezes sobre a mesma janela **não** duplica eventos.
      *(2ª rodada: "0 pontos avaliados"; contagem permanece 2.)*
- [x] Veículo parado sobre a borda por 30 min gera no máximo 1 par de eventos (histerese).
      *(30 pontos oscilando 185 m ↔ 215 m numa cerca de 200 m → **1** evento.)*
- [x] Entrada em cerca com `alert_on='ambos'` gera notificação (integração com a Fase 1).
      *(2 linhas em `notifications` com `kind='geocerca'`.)*
- [x] Cliente A não enxerga cerca do cliente B — evento carrega o `customer_id` da cerca,
      relatório filtra por ele, e a FK impede apontar cerca para cliente inexistente.
- [x] **Extra**: migração idempotente (2×, exit 0); pareamento `LEAD` correto (2h / 30min /
      "em permanência"); CRUD por HTTP com CSRF (POST sem token → 403); geometria inválida
      recusada (polígono de 2 vértices, raio de 5 m); e-mail inválido descartado no cadastro.

**Verificação executada**: 36 asserções de geometria + 13 do worker ponta-a-ponta + 28 de
relatório/CRUD = **77 asserções, 0 falhas**. Specs Playwright em `tests/geocercas.spec.js`
(9 casos) e 2 rotas novas em `tests/navigation.spec.js` — dependem de `TEST_EMAIL`/`TEST_PASSWORD`.

---

# FASE 3 — v4.6.0 — Relatórios operacionais

## 3.1 Decisão de arquitetura

Parada, Ociosidade, Ignição e Status da Frota são **a mesma segmentação** de `gps_data`, vista por
recortes diferentes. Calcular cada relatório na hora da consulta significaria varrer `gps_data`
quatro vezes com a mesma lógica duplicada em quatro handlers.

Decisão: **um worker produz uma tabela de segmentos de estado; os quatro relatórios são recortes
dela.** É exatamente o padrão já validado em `scripts/trip_builder.php` — inclusive as constantes
de parada (`STOP_SPEED_KMH`, `STOP_IDLE_SECONDS`) devem ser compartilhadas, para que "parado" no
relatório de paradas signifique o mesmo que "parado" na segmentação de viagens.

```
gps_data ──▶ state_builder.php ──▶ device_state_segments ──┬─▶ /relatorios/paradas
                                                            ├─▶ /relatorios/ociosidade
                                                            ├─▶ /relatorios/ignicao
                                                            └─▶ /relatorios/status-frota
         └──▶ speeding_builder      ──▶ speeding_events    ───▶ /relatorios/velocidade
```

Excesso de velocidade fica separado porque não é um estado do veículo e sim um evento com limiar
próprio.

## 3.2 Modelo de dados

```sql
CREATE TABLE IF NOT EXISTS `device_state_segments` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `imei` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
    `customer_id` bigint unsigned DEFAULT NULL,
    `state` enum('movimento','ocioso','parado','offline') COLLATE utf8mb4_unicode_ci NOT NULL,
    `started_at` datetime NOT NULL COMMENT 'UTC',
    `ended_at` datetime DEFAULT NULL COMMENT 'NULL = estado em curso',
    `duration_s` int unsigned DEFAULT NULL,
    `start_lat` decimal(10,8) DEFAULT NULL,
    `start_lng` decimal(11,8) DEFAULT NULL,
    `end_lat` decimal(10,8) DEFAULT NULL,
    `end_lng` decimal(11,8) DEFAULT NULL,
    `distance_km` decimal(10,3) DEFAULT NULL,
    `max_speed` decimal(6,2) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_dss_imei_start` (`imei`,`started_at`),
    KEY `idx_dss_customer_time` (`customer_id`,`started_at`),
    KEY `idx_dss_state_time` (`customer_id`,`state`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `speeding_events` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `imei` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
    `customer_id` bigint unsigned DEFAULT NULL,
    `started_at` datetime NOT NULL,
    `ended_at` datetime DEFAULT NULL,
    `duration_s` int unsigned DEFAULT NULL,
    `max_speed` decimal(6,2) NOT NULL,
    `limit_kmh` smallint unsigned NOT NULL,
    `start_lat` decimal(10,8) DEFAULT NULL,
    `start_lng` decimal(11,8) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_spd_imei_start` (`imei`,`started_at`),
    KEY `idx_spd_customer_time` (`customer_id`,`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Limiar de velocidade, com precedência device → cliente → padrão global (80 km/h):

```sql
CALL add_column_if_not_exists('devices', 'speed_limit_kmh',
    "smallint unsigned DEFAULT NULL COMMENT 'Limite de velocidade (NULL = herda do cliente)' AFTER `branch_id`");
CALL add_column_if_not_exists('customers', 'default_speed_limit_kmh',
    "smallint unsigned DEFAULT NULL COMMENT 'Limite padrão da frota' AFTER `faceid_enabled`");
```

## 3.3 Regras de classificação

| Estado | Condição | Origem |
|---|---|---|
| `movimento` | `acc = 1 AND speed > STOP_SPEED_KMH` (3 km/h) | mesma constante do `trip_builder.php:28` |
| `ocioso` | `acc = 1 AND speed <= STOP_SPEED_KMH` | motor ligado parado |
| `parado` | `acc = 0` | ignição desligada |
| `offline` | intervalo entre pontos `>= OFFLINE_GAP_SECONDS` (30 min) | ausência de dado |

O segmento fecha quando o estado muda ou quando surge um buraco de dados. O último segmento fica
com `ended_at IS NULL` (estado em curso) e é reavaliado na rodada seguinte — mesma proteção
`$staleBefore` do `trip_builder.php:37`, para não fragmentar um estado ainda em andamento.

**Excesso de velocidade**: pontos consecutivos com `speed > limite` viram um evento único; fecha
quando cai abaixo do limite ou há buraco de dados. Piso de 2 pontos para descartar spike de GPS.

## 3.4 Arquivos

| Arquivo | Ação |
|---|---|
| `scripts/state_builder.php` | **novo** — cron `*/15 min`, espelha a estrutura de `trip_builder.php` (lookback incremental por device, `argv[1]` para backfill) |
| `includes/fleet_state.php` | **novo** — constantes compartilhadas e `classify_point()`, consumido pelo worker e pelo relatório de Status da Frota |
| `handlers/rel_paradas.php` | **novo** |
| `handlers/rel_ociosidade.php` | **novo** |
| `handlers/rel_ignicao.php` | **novo** |
| `handlers/rel_velocidade.php` | **novo** |
| `handlers/rel_status_frota.php` | **novo** — sumário (contagem e percentual por estado) + drill-down |
| `handlers/equipamentos.php` | alterar — campo `speed_limit_kmh` no formulário |
| `handlers/clientes.php` | alterar — campo `default_speed_limit_kmh` |
| `scripts/worker.php` | alterar — novos tipos em `buildReportSource()` (linha 126) para export assíncrono |
| `handlers/router.php` | alterar — 5 entradas em `$subrouteMap['relatorios']` |
| `web/layout_base.php` | alterar — 5 itens no accordion Relatórios |
| `scripts/crontab-setup.sh` | alterar — `state_builder.php` |
| `mysql/migration_v4.6.0.sql` | **novo** |

Cada handler de relatório segue integralmente o molde de `rel_alarmes.php`: `require_login()`,
`clamp_report_range()` (teto de 31 dias), filtros com placeholders, whitelist de ordenação em
`report_sort_params()`, export síncrono por `stream_export()` limitado a `SYNC_EXPORT_MAX_ROWS`,
`report_pagination()` e `report_back_button()`.

## 3.5 Backfill

Rodar uma vez após a migração, fora do horário de pico:

```bash
php scripts/state_builder.php 30    # 30 dias de histórico
```

Com ~4,8 mil pontos/dia por tenant o backfill de 30 dias é rápido; em base grande, executar por
faixas de device para não segurar transação longa.

## 3.6 Critérios de aceite

- [x] `php -l` limpo (0 erros em todo o projeto, incluindo `scripts/` e `web/`);
      `bash -n` limpo em `crontab-setup.sh` e `deploy.sh`.
- [x] Soma de `duration_s` de todos os segmentos de um device em um dia = 86.400 s
      — **exatamente**, sem tolerância de borda. *(Trajetória sintética de 1.515 pontos com
      margens antes e depois do dia; medida como a interseção de cada segmento com a janela
      do dia. O resultado exato decorre da contiguidade, não de arredondamento.)*
- [x] Nenhum par de segmentos do mesmo device se sobrepõe no tempo — e não há **vão** entre
      eles (a asserção de contiguidade é o que sustenta o item acima).
- [x] Reexecução do worker sobre a mesma janela não duplica.
      *(2ª rodada: mesmas 8 linhas, soma do dia continua 86.400 s.)*
- [x] Total de "parado" bate com a contagem de transições `acc 1→0` do relatório de Ignição.
      *(2 = 2; os dois números são publicados lado a lado na própria tela.)*
- [x] Device com limite 60 e pico de 85 km/h aparece em Excesso de Velocidade com
      `limit_kmh = 60` — e o device sem limite próprio herda os 100 do cliente.
- [x] Status da Frota soma exatamente o total de equipamentos ativos do cliente.
- [x] Specs Playwright: **19 casos novos** em `tests/relatorios-operacionais.spec.js` + 5 rotas
      em `tests/navigation.spec.js`. Suíte completa: **69 passed, 0 failed, 5 skipped**.
- [x] **Extra**: migração idempotente (2×, exit 0); `trip_builder.php` sem regressão após
      passar a consumir as constantes compartilhadas (2 viagens, mesma segmentação); export
      XLSX das 5 telas; export **assíncrono** dos 5 tipos novos via fila `jobs`; escopo
      multi-tenant (cliente B não vê dado do cliente A); subrota inexistente devolve 404;
      spike de 1 ponto acima do limite descartado; velocidade **igual** ao limite não é
      infração; backfill de 30 dias sobre os dados reais locais (0 sobreposições).

**Verificação executada**: 51 asserções de segmentação/geometria + 39 de HTTP autenticado
(rotas, dados na tela, export, multi-tenant) + 24 de worker/regressão = **114 asserções,
0 falhas**, mais **69 testes Playwright** verdes.

---

# FASE 4 — v4.7.0 — Relatório agendado por e-mail

## 4.1 Objetivo

O relatório configurado uma vez chega sozinho por e-mail na frequência escolhida, e os filtros
usados com frequência viram modelos reutilizáveis.

## 4.2 Modelo de dados

```sql
CREATE TABLE IF NOT EXISTS `report_schedules` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `user_id` bigint unsigned DEFAULT NULL COMMENT 'Criador',
    `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
    `report_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
    `format` enum('csv','xlsx','pdf') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'xlsx',
    `filters` json DEFAULT NULL COMMENT 'Mesmo shape de jobs.params (sem o período)',
    `frequency` enum('diaria','semanal','mensal') COLLATE utf8mb4_unicode_ci NOT NULL,
    `send_hour` tinyint unsigned NOT NULL DEFAULT 7 COMMENT 'Hora BRT (0-23)',
    `send_dow` tinyint unsigned DEFAULT NULL COMMENT '1=segunda … 7=domingo (semanal)',
    `send_dom` tinyint unsigned DEFAULT NULL COMMENT 'Dia do mês (mensal)',
    `recipients` json NOT NULL COMMENT 'Array de até 3 e-mails',
    `is_active` tinyint(1) NOT NULL DEFAULT 1,
    `last_run_at` datetime DEFAULT NULL COMMENT 'UTC',
    `next_run_at` datetime DEFAULT NULL COMMENT 'UTC — calculado a partir de send_hour BRT',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rs_due` (`is_active`,`next_run_at`),
    CONSTRAINT `fk_rs_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_schedule_runs` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `schedule_id` bigint unsigned NOT NULL,
    `job_id` bigint unsigned DEFAULT NULL,
    `status` enum('enfileirado','enviado','falhou') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enfileirado',
    `error_message` text COLLATE utf8mb4_unicode_ci,
    `executed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rsr_schedule` (`schedule_id`,`executed_at`),
    CONSTRAINT `fk_rsr_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `report_schedules`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modelos de relatório (filtros salvos por usuário)
CREATE TABLE IF NOT EXISTS `report_templates` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `customer_id` bigint unsigned NOT NULL,
    `user_id` bigint unsigned NOT NULL,
    `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
    `report_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
    `filters` json DEFAULT NULL,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_rt_user_name` (`user_id`,`report_type`,`name`),
    CONSTRAINT `fk_rt_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 4.3 Fuso horário — o ponto de maior risco desta fase

`send_hour` é **hora BRT** (o que o usuário digita). `next_run_at` é **UTC** (o que o dispatcher
compara com `NOW()`). Converter sempre pelos helpers, nunca somando 3 horas na mão: em janeiro e
em julho o Brasil não tem o mesmo comportamento histórico de horário de verão, e somar offset fixo
produz relatório chegando uma hora errada em parte do ano.

```php
// Correto: constrói o instante no fuso do usuário e converte
$dt = new DateTime("{$diaBrt} {$sendHour}:00:00", new DateTimeZone('America/Sao_Paulo'));
$dt->setTimezone(new DateTimeZone('UTC'));
$nextRunAt = $dt->format('Y-m-d H:i:s');
```

O período consultado também é BRT: "ontem" para a frequência diária, "semana passada" para a
semanal, "mês passado" para a mensal — todos resolvidos por `brt_today()` +
`brt_day_range_to_utc()`, como o `worker.php:60-63` já faz.

## 4.4 Arquivos

| Arquivo | Ação | Conteúdo |
|---|---|---|
| `scripts/schedule_dispatcher.php` | **novo** | Cron `5 * * * *`. Seleciona `is_active = 1 AND next_run_at <= NOW()`, cria o job `report` com `params.deliver_email`, grava `report_schedule_runs`, recalcula `next_run_at`. |
| `handlers/agendamentos.php` | **novo** | CRUD dos agendamentos + histórico de execuções. |
| `scripts/worker.php` | alterar | Ao fim de `processReportJob()`: se `params.deliver_email`, anexa o arquivo e envia por `includes/mailer.php` (Fase 1); acima de `MAIL_MAX_ATTACH_MB`, envia link para `/exportar` no lugar do anexo. |
| `handlers/exportar.php` | alterar | Aba "Agendados" com a lista e o histórico. |
| `handlers/rel_*.php` | alterar | Botão "Salvar como modelo" e seletor de modelos na barra de filtros (7 relatórios). |
| `handlers/router.php` | alterar | `'agendamentos'` em `$simpleRoutes`. |
| `web/layout_base.php` | alterar | Item "Agendamentos" no grupo Relatórios. |
| `handlers/grupos_permissao.php` | alterar | `'agendamentos' => 'Agendamentos'` em `$screens`. |
| `scripts/crontab-setup.sh` | alterar | `schedule_dispatcher.php`. |
| `mysql/migration_v4.7.0.sql` | **novo** | DDL acima + `system_info` = 4.7.0. |

## 4.5 Guardas

- **Reentrância**: o dispatcher atualiza `next_run_at` **antes** de enfileirar o job. Se o cron
  atrasar e dois processos coincidirem, o segundo não encontra a linha vencida.
- **Falha em cascata**: 3 execuções falhas consecutivas desativam o agendamento
  (`is_active = 0`) e geram uma notificação (Fase 1) ao criador — melhor do que tentar para sempre
  contra um e-mail inválido.
- **Relatório vazio**: por padrão envia mesmo assim, com o corpo indicando "nenhum registro no
  período". Opção `skip_if_empty` na configuração.
- **Volume**: `SYNC_EXPORT_MAX_ROWS` (10.000) não se aplica aqui — o caminho é assíncrono. Ainda
  assim, teto de 100.000 linhas por relatório agendado para não estourar memória do worker.

## 4.6 Critérios de aceite

- [x] `php -l` limpo (0 erros em todo o projeto); `bash -n` limpo em `crontab-setup.sh` e
      `deploy.sh`. **Cron ainda não instalado no homolog** — pendente do deploy.
- [x] Agendamento diário às 07:00 BRT com `next_run_at` gravado em UTC (10:00);
      **testado com data de janeiro e de julho** — as duas dão 10:00 UTC, porque o Brasil
      aboliu o horário de verão em 2019. Para provar que a conversão é por **tzdata** e não
      por offset fixo, há uma asserção em **16/02/2018** (DST vigente), que dá **09:00 UTC**:
      somar 3 h na mão erraria essa data em uma hora.
- [x] Dispatcher executado duas vezes na mesma hora enfileira **um** job.
      *(Guarda de reentrância: o `UPDATE` de `next_run_at` é condicionado ao valor lido, então
      o segundo processo afeta 0 linhas e desiste.)*
- [x] E-mail chega com o XLSX anexado — verificado contra um **servidor SMTP de captura**: a
      mensagem é `multipart/mixed`, o anexo tem MIME `spreadsheetml.sheet`, nome amigável
      (`ZZ Diario Alarmes - 28-07-2026.xlsx`, não `report_66_...`) e decodifica para um zip
      válido (assinatura `PK`). *Abrir no Excel pt-BR não é verificável por automação; o
      `XlsxWriter` é o mesmo já em uso no export síncrono desde a v4.1.0.*
- [x] Arquivo acima de `MAIL_MAX_ATTACH_MB` chega como link: a mensagem deixa de ser
      multipart, o corpo traz o link para `storage/reports/` e explica o motivo.
- [x] 3 falhas consecutivas desativam o agendamento e notificam o criador.
      *(Medido ciclo a ciclo: `fail_count` 1 → 2 → 3 com `is_active` caindo para 0 no 3º; o erro
      real do SMTP fica no histórico; sucesso **zera** o contador, porque a regra é
      "consecutivas".)*
- [x] Modelo salvo em `/relatorios/alarmes` reaparece no seletor e repopula os filtros
      — verificado pela UI: salvar → aparecer → aplicar → campos preenchidos → excluir.
- [x] Usuário do cliente A não vê agendamento do cliente B — nem na grade, nem no histórico,
      nem abrindo a URL de edição pelo id, nem tentando excluí-lo.
- [x] **Extra**: migração idempotente (2×, exit 0); `skip_if_empty` grava `vazio` e não envia;
      CSRF obrigatório em criar/editar/excluir/alternar (POST sem token → 403, nada gravado);
      destinatário inválido e tipo de relatório inexistente recusados; reativar zera o contador
      de falhas; agendamento inativo não é mais disparado; modelo não vaza para outra tela;
      `/exportar` mostra o resumo dos agendamentos e o próximo envio.

**Verificação executada**: 71 asserções de agendamento/fuso/entrega (com SMTP de captura) +
72 de HTTP autenticado (CRUD, CSRF, escopo, ciclo dos modelos) = **143 asserções, 0 falhas**,
mais **19 casos Playwright** em `tests/agendamentos.spec.js` e a rota nova em
`tests/navigation.spec.js`.

### Desvios em relação ao plano original, todos deliberados

1. **Excluir e alternar são POST, não link GET.** `csrf_verify()` só lê o token de `$_POST` ou
   do cabeçalho `X-CSRF-Token`; uma ação destrutiva alcançável por GET é acionável por um
   `<img src="/agendamentos?action=excluir&id=1">` em qualquer página que o usuário logado
   abra. (O `/geocercas` da v4.5.0 tem esse problema — ver §4.7.)
2. **Job continua `concluido` quando só a ENTREGA falha.** O arquivo existe e fica baixável em
   `/exportar`; marcar o job como falho esconderia o `result_path` e perderia o artefato. A
   falha aparece onde importa: no histórico do agendamento (`report_schedule_runs`), que é o
   que alimenta a regra das 3 falhas.
3. **`report_schedule_runs.status` ganhou `vazio`** (o plano previa só enfileirado/enviado/
   falhou): "não enviei porque não havia nada" não é falha nem envio, e confundir os dois faria
   `skip_if_empty` desativar o agendamento em 3 dias tranquilos.
4. **`jobs.schedule_run_id`** acrescentada: sem o vínculo, o worker não sabe qual execução
   fechar e o histórico ficaria eternamente em "enfileirado".
5. **`fleet_status` fica fora dos tipos agendáveis** — é uma foto do agora, e "o estado da
   frota de ontem às 7h" não significa nada.
6. **Dia do mês limitado a 28**: 29/30/31 não existem em todo mês, e pular fevereiro nunca é o
   que o usuário quis dizer.
7. **`includes/report_templates.php` guarda a query string da tela**, não uma estrutura por
   relatório: é o que a tela já sabe interpretar, serve para qualquer filtro presente ou futuro
   e dispensa mapeamento tela a tela. Aplicar um modelo é redirecionar com aquela query.

## 4.7 Achado fora do escopo — CSRF em ação destrutiva por GET

`/geocercas?action=excluir&id=N` (v4.5.0) apaga a geocerca **sem token CSRF**, porque
`csrf_verify()` não lê da query string. Qualquer página que um administrador logado abra pode
disparar a exclusão com uma tag `<img>`. Os agendamentos da Fase 4 já nascem com POST + CSRF;
a correção de `/geocercas` (e a varredura por outros `action=` destrutivos em GET) fica
**pendente**, como passe focado de segurança — vale revisar `handlers/*.php` inteiro de uma vez
em vez de corrigir de raspão no fim de uma fase de features.

---

# 5. Riscos e decisões

| # | Risco | Mitigação / decisão |
|---|---|---|
| R1 | **SMTP dentro do webhook** travaria o processamento pós-200 do `pushalarm`. | Nunca enviar inline: `notify_from_occurrence()` só faz `INSERT`. Envio é do `worker.php`. Mesma lição já aprendida com o despacho de vídeo. |
| R2 | **Enxurrada de notificação** com device em rajada de alarmes. | Notificar por ocorrência nova (não por alarme) + teto de 60/h por cliente + dedupe de e-mail de 15 min. |
| R3 | **SMTP artesanal** em vez de biblioteca madura. | Consequência de não haver gerenciador de pacotes no app (`CLAUDE.md`). Escopo limitado a AUTH LOGIN + STARTTLS + anexo base64. Se o custo de manutenção aparecer, a alternativa é uma API HTTP transacional (Resend/SES), que troca SMTP por `curl` — já disponível. |
| R4 | **Flapping de geocerca** em veículo parado na borda. | Histerese de 50 m. |
| R5 | **Custo do worker de geocerca** crescer com a frota. | Pré-filtro por bounding box + processar só devices com cerca vinculada + leitura incremental por `geofence_state.last_gps_time`. |
| R6 | **Segmentação de estado divergir** da segmentação de viagens. | Constantes compartilhadas em `includes/fleet_state.php`, consumidas pelos dois workers. Teste de aceite exige soma de 86.400 s/dia. |
| R7 | **Horário de verão** deslocar o envio agendado. | Conversão sempre via `DateTimeZone`, nunca offset fixo. Teste obrigatório em janeiro e julho. |
| R8 | **Crescimento de tabelas** (`notifications`, `geofence_events`, `device_state_segments`). | Purga em `auth_cleanup()` para notificações; para as demais, avaliar retenção junto do item 11 da análise (vigência de dados). |
| R9 | **Migração em produção** com as 2 VIEWs órfãs pendentes (`STATUS.md`, item aberto). | Rodar `DROP VIEW IF EXISTS vw_alarm_types_ambiguous_codes, vw_alarm_types_unknown_codes;` **antes** da primeira migração desta série, senão o backup do `deploy.sh` aborta. |

---

# 6. Verificação

Por fase, na ordem:

```bash
# 1. Lint (o pre-commit já roda, mas rodar antes de abrir PR)
find handlers config core includes scripts -name "*.php" -type f -exec php -l {} \;

# 2. Migração em base COM DADOS, duas vezes (idempotência)
mysql -u root -p jimi_tracker < mysql/migration_v4.X.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.X.0.sql   # 2ª vez: sem erro

# 3. Replay de webhook com asserções em MySQL
bash scripts/test_e2e.sh

# 4. Workers em seco
php scripts/geofence_worker.php
php scripts/state_builder.php 1
php scripts/schedule_dispatcher.php

# 5. Suite E2E
./scripts/run-tests.ps1
```

> ⚠️ **Não existe teste "em base limpa" com os arquivos como estão** — e tentar fazê-lo escreve
> na base real. `mysql/jimi_tracker.sql`, `migration_v2.0.0.sql`, `migration_v3.1.0.sql` e
> `migration_v4.0.0.sql` **embutem `USE \`jimi_tracker\``** (o primeiro também tem
> `CREATE DATABASE`), então ignoram o banco passado ao cliente; de `v4.1.0` em diante os arquivos
> respeitam o alvo. Consequências: (a) instalar em banco com outro nome exige editar os 4
> arquivos; (b) rodar a cadeia apontando para um banco de teste executa os 4 primeiros contra o
> `jimi_tracker` de verdade e, como a `v4.0.0` termina com
> `system_info.version = '4.0.0'`, **empurra o marcador de versão para trás** — exatamente o
> valor que o gate do `deploy.sh` lê. (Verificado nesta sessão, ao tentar o teste: o banco de dev
> foi para `4.0.0` e precisou da cadeia `4.1.0 → 4.7.0` para voltar a `4.7.0`; os dados ficaram
> intactos porque todos os seeds usam `INSERT IGNORE`.) **O deploy não é afetado** — o gate
> semântico nunca reexecuta a base nem a v4.0.0 num banco já adiante. Correção sugerida: remover
> `USE`/`CREATE DATABASE` dos 4 arquivos e deixar o nome do banco a cargo de quem invoca.

Specs Playwright acrescentadas: `geocercas.spec.js` (Fase 2), `relatorios-operacionais.spec.js`
(Fase 3) e `agendamentos.spec.js` (Fase 4); `navigation.spec.js` estendida com as rotas novas.
Suíte ao fim da série: **84 passed / 0 failed / 5 skipped** (os skips são os documentados —
multi-tenant sem 2º cliente, `TEST_IMEI` ausente, rate-limit destrutivo).

> **`notificacoes.spec.js` da Fase 1 não foi escrita** e o sino segue sem cobertura E2E — a Fase 1
> foi verificada por asserções PHP (13 do motor) e por rotas autenticadas, não pela UI. Fica como
> lacuna conhecida.

> ⚠️ **Rodar a suíte COM credenciais antes de dar uma fase por concluída.** Os 9 casos de
> `geocercas.spec.js` foram escritos na Fase 2 e **nunca executados** (faltavam
> `TEST_EMAIL`/`TEST_PASSWORD`); ao rodarem na Fase 3, dois falharam de imediato — um deles
> revelando bug real (`/geocercas` re-renderizava o formulário vazio depois de salvar, e um F5
> duplicava a cerca). Spec escrita não é spec verificada.

---

# FASE 5 — Atualizar a Central de Ajuda (`/wiki`)

## 5.1 Quando

**Depois de concluídas as Fases 1 a 4** — não a cada fase. As Fases 2, 3 e 4 tocam nas mesmas
telas de relatório e no mesmo motor de notificação; documentar a cada entrega significaria
reescrever as mesmas seções quatro vezes. A wiki é o último passo da iniciativa.

## 5.2 Onde

`handlers/wiki.php` (rota `/wiki`), hoje congelada na **v4.3.0**. A estrutura é uma seção
`<h2 id="…">` por assunto, com mockup e regra de negócio. O precedente a seguir é o commit
`83f9849` ("Central de Ajuda atualizada para a v4.3.0").

## 5.3 O que precisa entrar

| Origem | Conteúdo |
|---|---|
| **Fase 1** (feita) | O **sino** no cabeçalho (badge, painel, polling de 30s que pausa com a aba oculta), o **pop-up em tempo real** e o **som**. A tela `/config-notificacoes`: regra por cliente × tipo de alarme, o fato de uma **categoria** cobrir todos os alarmes dela, `min_risk`, precedência da regra do cliente sobre a global, limite de 3 destinatários. A tela `/config-smtp`: escopo global × por cliente, senha cifrada e nunca reexibida, botão "Enviar e-mail de teste". |
| **Fase 2** | Desenho de cerca no mapa (círculo e polígono), vínculo de equipamentos, relatório de entrada/saída/permanência. |
| **Fase 3** | Parada, Ociosidade, Ignição, Excesso de Velocidade e Status da Frota — **com a definição de cada estado** (`movimento`/`ocioso`/`parado`/`offline`) e os limiares usados. |
| **Fase 4** | `/agendamentos`, modelos de relatório salvos, histórico de execuções. |
| **Transversal** | Revisar "O que vale para todos os relatórios" se as Fases 3–4 mudarem filtros, ordenação ou export. |

## 5.4 Um ponto que o usuário precisa entender

A documentação tem de explicar que o sistema **notifica por ocorrência, não por alarme**. Sem
isso, o operador vê uma rajada de 12 alarmes gerando um único aviso e conclui que o sistema
falhou — quando é a janela de agrupamento do perfil funcionando como projetado. Vale o mesmo
para o teto de 60 notificações/hora, que produz uma notificação-resumo e passa a suprimir.

---

# 7. Dimensionamento

Estimativa por fase, contando arquivo novo e alterado. Números são ordem de grandeza para
sequenciamento, não compromisso de prazo.

| Fase | Arquivos novos | Arquivos alterados | Tabelas | LOC aprox. | Esforço |
|---|---|---|---|---|---|
| 1 — Notificações | 4 | 6 | 2 (+2 alterações) | ~1.100 | 3–4 dias |
| 2 — Geocercas | 4 | 6 | 4 | ~1.000 | 3–4 dias |
| 3 — Relatórios | 7 | 6 | 2 (+2 colunas) | ~1.500 | 4–5 dias |
| 4 — Agendamento | 2 | 11 | 3 | ~900 | 3–4 dias |
| **Total** | **17** | **29** | **11** | **~4.500** | **13–17 dias** |

A Fase 3 é a maior em volume, mas a de menor risco: são cinco telas sobre o mesmo molde já
consolidado em `rel_alarmes.php`, alimentadas por um worker que espelha o `trip_builder.php`. A
Fase 1 é a menor em volume e a de maior risco, por introduzir a primeira saída SMTP do sistema.

## Rotas resultantes

Ao fim da série o sistema saiu de 30 para **39 rotas** — uma a mais que as 38 previstas, porque a
v4.4.1 (`/config-smtp`) não estava no plano original: as credenciais de SMTP nasceram no `.env` e
só depois virou evidente que trocar de provedor não pode exigir acesso ao servidor.

**Todas implementadas:**

| Rota | Fase | Estado |
|---|---|---|
| `/config-notificacoes` | 1 | ✅ |
| `/notificacoesdata` (AJAX) | 1 | ✅ |
| `/config-smtp` | 1 (não previsto) | ✅ |
| `/geocercas` | 2 | ✅ |
| `/relatorios/geocercas` | 2 | ✅ |
| `/relatorios/paradas` | 3 | ✅ |
| `/relatorios/ociosidade` | 3 | ✅ |
| `/relatorios/ignicao` | 3 | ✅ |
| `/relatorios/velocidade` | 3 | ✅ |
| `/relatorios/status-frota` | 3 | ✅ |
| `/agendamentos` | 4 | ✅ |

**Telas que exigem liberação em `/grupos-permissao`**: `/config-notificacoes`, `/config-smtp`,
`/geocercas` e `/agendamentos`. Os 5 relatórios da Fase 3 herdam a permissão `relatorios` e não
aparecem na matriz — tela nova nasce opt-in de propósito, mas é a primeira coisa que alguém
reporta como "bug".

## Crontab final

```cron
*/1  * * * *   php scripts/worker.php               # existente
*/15 * * * *   php scripts/trip_builder.php         # existente
*/5  * * * *   php scripts/metrics_rollup.php       # existente
10   3 * * *   php scripts/log_cleanup.php          # existente
*/2  * * * *   php scripts/geofence_worker.php      # Fase 2 ✅
*/15 * * * *   php scripts/state_builder.php        # Fase 3 ✅
5    * * * *   php scripts/schedule_dispatcher.php  # Fase 4 ✅
```

Os **7 workers** estão no array `CRON_JOBS` de `scripts/crontab-setup.sh` e o `--check` confere os 7.
Nenhum deles é instalado pelo `deploy.sh` — a instalação é o passo manual que sobra em cada release
que traz worker novo, e esquecê-la produz a falha mais silenciosa possível: tela funcionando,
relatório vazio para sempre.

Instalação por `scripts/crontab-setup.sh --install` (o array `CRON_JOBS`, linha 20, é a fonte
única — atualizar lá, não no crontab à mão).
