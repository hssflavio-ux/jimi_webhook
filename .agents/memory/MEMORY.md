# Memory Index

> Auto-load at session start. Max 200 lines.

## User
- [user] Flavian, Windows 11, PowerShell 7+, VS Code/Cursor → user-preferences.md
- [user] Português (PT-BR), respostas concisas, prefere ação a explicação → user-preferences.md
- [user] PHP lint via `C:\Users\flavi\php\php.exe -l` → user-preferences.md

## Project
- [project] jimi_webhook v4.3.0 — YUV Parity, PHP 8.3 puro + MySQL 8.0 → project-conventions.md
- [project] Design system Coinbase: azul #0052ff, sidebar #0a0b0d, CTAs pill 100px, JetBrains Mono números/IMEI → project-conventions.md
- [project] Autenticação token cookie `jimi_token` → MySQL `sessions`, sem `session_start()` — $_SESSION é POR REQUEST (nunca persistir nada nele entre requests) → project-conventions.md
- [project] PROJETO_YUV.md é o contrato-mestre; STATUS.md é o diário vivo (estado atual: §12) → project-conventions.md
- [project] Fases 0-M + iteração v4.1.1 concluídas — comandos ponta-a-ponta OK (síncrono + callback offline real), BRT em todo o dashboard, Playwright 37/37 → project-conventions.md
- [project] Servidor homolog: 189.22.240.43 (host `iothub`) Apache/FPM no HOST + 16 containers IoTHub; containers só alcançam o host via IP LAN 10.1.0.43 (nunca localhost) → project-conventions.md
- [project] Deploy homolog (12/07/2026): servidor puxa de hssflavio-ux/jimi_webhook (deploy key dedicada no root; repo Flaviohses é legado); SSH por chave como administrador + sudo com senha; usuário E2E e2e@teste.local existe no homolog; working copy do servidor é www-data (git pull só com sudo) → project-conventions.md
- [project] Estado homolog 22/07/2026: v4.3.0 implantado em `5f6b8ed` (banco 4.3.0). Relatório de deslocamento em 2 modalidades (viagens | fechamento diário) + mapa de rota (/relatorios/deslocamento/rota) + teto global 31 dias (clamp_report_range) + índice trips(customer_id, started_at). QUANDO deploy.sh muda a si mesmo (bloco de migration novo): rodar `sudo ./scripts/deploy.sh && sudo ./scripts/deploy.sh --force` (git pull no meio do script → bloco novo só vale na 2ª passada; sudo cacheia a senha) → tech-decisions.md
- [project] Auto-vídeo de evento (v4.2.1, 13/07): ocorrência nova JT/T dispara **37384/0x9208 (Alarm Attachment Upload)** pós-commit com alarmLabel do push + alarmNumber=bin2hex(IMEI[-14:]+label[14:]) + attachment server (ingest_ip:21188, overrides ATTACH_UPLOAD_IP/PORT). **34818/0x8802 é CONSULTA (não upload)** — JC371 real respondeu mediaItemsNum:0 para evento DMS. Arquivos chegam {imei}_{label}_{xy}.mp4/.jpg via /pushfileupload; vínculo mídia→ocorrência pelo label (alarms.alarm_label→occurrence_events), vídeo > imagem, fallback ±3min. Kill-switch AUTO_VIDEO_REQUEST=0 → tech-decisions.md
- [reference] Câmeras: device_models.camera_count = MÁXIMO do modelo (JC182=1, JC181/JC400D/JC400AD=2, JC371≤3, JC450≤5); devices.camera_count = instalado (cadastro). Telas de vídeo usam COALESCE(NULLIF(d.camera_count,0), dm.camera_count, 1) — nunca ler só o modelo (2e8472f) → tech-decisions.md
- [feedback] deploy.sh: git pull roda no MEIO do script — mudança no próprio deploy.sh só vale na próxima execução (rodar deploy 2× quando o script muda) → feedback-history.md
- [project] IoTHub: comandos via :10088 (segura resposta até 30s aguardando device — timeout do app é 35s); vídeos servidos pelo dvr-upload :23010 (Apache NÃO acessa /iothub/dvr-upload) → project-conventions.md
- [project] Devices reais no homolog: 860112070347838 (JC181) e 869058070151343 (JC182), ambos JTT serverFlagId=0 → project-conventions.md
- [project] Dev Windows tem MySQL 8.0.37 portátil em C:\Users\flavi\mysql (subir com scripts/dev-windows.ps1); usuário E2E: e2e@teste.local → project-conventions.md

## Feedback
- [feedback] Usuário diz "Continue" para avançar fases (não perguntar se quer continuar) → feedback-history.md
- [feedback] Prefere implementação completa por fase com verificação lint ao final → feedback-history.md
- [feedback] Valoriza STATUS.md atualizado como artefato de handoff entre sessões → feedback-history.md
- [feedback] Wiki (/wiki) é para USUÁRIO FINAL: sem termos técnicos (proNo/AJAX/polling), sem caminhos de URL (referir telas pelo menu lateral), mapas com imagem real (assets/img/wiki_map_*.png), sem seções de webhook/motor/segurança → feedback-history.md

## Reference
- [reference] LOGS: LOG_LEVEL no .env (lazy — .env só existe após 1º Database::getInstance(); DEBUG liga RAW_WEBHOOK_DATA); purga/rotação = cron diário scripts/log_cleanup.php (NÃO usa classe Database — ela dá exit com banco fora); handler global de exceção/fatal do dashboard em auth.php; `php -r` NÃO dispara set_exception_handler (testar com arquivo) → tech-decisions.md
- [reference] VÍDEO HISTÓRICO (cartão): LISTAR = 37381/0x9205 (janela GMT-0 compacta yyMMddHHmmss que NÃO cruza o dia → fatiar por dia UTC; resposta assíncrona → /pushresourcelist → resource_lists, push §1.11 pode vir SEM data_list). Timeline do /video/playback une resource_lists+media_files (merge ±120s). resourceType do 0x1205: 0=áudio+vídeo (NÃO é imagem). ATENÇÃO: [Extrair] usa 34818/0x8802 que é CONSULTA — extração real de gravação do cartão = 37382/0x9206 e exige FTP do cliente (doc §2.7; pendência de infra) → tech-decisions.md
- [reference] VÍDEO AO VIVO (37121/0x9101): o comando manda o DEVICE PUBLICAR o RTP no media server — videoIP/videoTCPPort devem ser o endereço que o DEVICE alcança (IP público do servidor + porta ingest 10002), NUNCA window.location.hostname nem porta 0. dataType:0=vídeo. Usar helper video_stream_config() (flv_base saída FLV :8881; ingest_ip/port; playback_port 10003; overrides VIDEO_INGEST_IP/PORT no .env). FLV URL: STREAM_URL/{canal}/{imei}.flv. Player precisa RETRY (device leva 5–30s para publicar) — 1 tentativa única falha. Validado com JC182 real (2 MB FLV capturado). → tech-decisions.md
- [reference] TIMEZONE: armazenar UTC, exibir BRT SEMPRE via fmt_brt(); filtros de dia via brt_day_range_to_utc(); defaults brt_today(); GROUP BY hora/dia via CONVERT_TZ(col,'+00:00','-03:00'); colunas DATE puras NÃO convertem → tech-decisions.md
- [reference] Gateway auto-cria devices órfãos (customer_id NULL) na 1ª telemetria — cadastro em /ativos/novo ADOTA a linha (nunca recusar por COUNT global de IMEI) → tech-decisions.md
- [reference] Resposta síncrona de comando vem no HTTP response do sendInstruct (data._content) → status 'executed' imediato; offline (_code 600) fica 'sent' até callback em /pushinstructresponse (objeto único §2.4, allowSingleObjectPayload) → tech-decisions.md
- [reference] Migration v4.1.0: jobs.format + fix seed occurrence_config_params (nomes reais de alarm_types) → tech-decisions.md
- [reference] Motor de ocorrências: pushalarm → occurrence_engine → ocorrências (dedup 10min); matching exige nome EXATO de alarm_types → tech-decisions.md
- [reference] CSRF: token derivado por HMAC-SHA256(cookie jimi_token, WEBHOOK_TOKEN) — NÃO usar $_SESSION para persistir token (não há session_start) → tech-decisions.md
- [reference] Exportação: includes/export_helper.php — XLSX via ZipArchive + PDF 1.4 puro-PHP, SEM Composer (decisão: projeto é "no package manager") → tech-decisions.md
- [reference] pushalarm: capturar lastInsertId() ANTES de callProcedure() — CALL reseta o valor para 0 → tech-decisions.md
- [reference] devices.last_position_at NÃO existe — última posição vem de device_statistics.last_gps_time (JOIN) → tech-decisions.md
- [reference] Workers cron: worker.php (jobs csv/xlsx/pdf), trip_builder.php (haversine), metrics_rollup.php → tech-decisions.md
- [reference] Paginação (v4.3.0): usar `report_pagination($page,$totalPages,$totalRows,$unit)` — o laço `min($totalPages,10)` que estava copiado em 7 telas travava o contador na página 10 (não mostrava a página atual a partir da 11ª). Faixa horária opcional (posições/deslocamento) = `time_from`/`time_to` + `brt_datetime_range_to_utc()`, janela CONTÍNUA (não "essa faixa em cada dia") → tech-decisions.md
- [reference] Relatórios — UI comum (v4.3.0): TODO relatório com data abre em ordem CRESCENTE (mais antigo no topo) e tem seta clicável na coluna de data/hora — usar `report_sort_params($whitelist, $default, 'ASC')` + `report_sort_link()` (includes/functions.php; a whitelist é obrigatória, a coluna vai interpolada no SQL) e `report_back_button('/relatorios/xxx')` + `report_has_query()` para o `← Voltar` à tela limpa. Export deve usar a MESMA variável de ordenação da grade. Cuidado: query com `ORDER BY … DESC LIMIT N` (hub /relatorios) NÃO pode virar ASC no SQL — inverter em PHP, senão a amostra troca pelos N mais antigos → tech-decisions.md
- [reference] Relatórios (v4.3.0): teto GLOBAL de 31 dias via clamp_report_range() — todo relatório novo com filtro de data DEVE aplicá-lo; deslocamento tem 2 modalidades (viagens | fechamento diário GROUP BY dia BRT) + mapa de rota (/relatorios/deslocamento/rota, trip_id ou imei+dia); trips indexada por (customer_id, started_at) — queries por período dependem desse composto → tech-decisions.md
- [reference] Deploy: scripts/deploy.sh aplica migrations até v4.1.0; deploy-v4.sh (--check/--migrate/--deploy/--verify) → tech-decisions.md
- [reference] Router: `$renamedRoutes` para rotas com hífen (config-ocorrencias, grupos-permissao) — rota nova com hífen vai ali, não em $simpleRoutes → tech-decisions.md
- [reference] Testes: npx playwright test (tests/, 40 testes; specs autenticados pulam sem TEST_EMAIL/TEST_PASSWORD); bash scripts/test_e2e.sh (replay webhooks) → tech-decisions.md

## Pendências (exigem produção/dispositivo — ver STATUS.md §11.4, §12.7 e §12.13)
- Deploy do fix 37384 no homolog (sudo) + validar com eventos DMS reais da JC371 865478070003241 (vídeo deve chegar via /pushfileupload e aparecer na ocorrência)
- [Extrair] do playback ainda usa 34818 (consulta) — precisa de 37382 + servidor FTP (infra) ou validar attachment server 21188 para esse fluxo
- OTA firmware: testar proNo 33027 com dispositivo real
- Specs multi-tenant: exigem credenciais de segundo cliente (TEST_EMAIL_B/TEST_PASSWORD_B)
- deploy.sh: mysqldump falha silenciosamente no homolog (backup de banco não sai) — investigar privilégios
- Limpeza opcional no homolog: device teste 868120246598152 + usuário e2e@teste.local + ocorrências/mídias de teste
