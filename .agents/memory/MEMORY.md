# Memory Index

> Auto-load at session start. Max 200 lines.

## User
- [user] Flavian, Windows 11, PowerShell 7+, VS Code/Cursor → user-preferences.md
- [user] Português (PT-BR), respostas concisas, prefere ação a explicação → user-preferences.md
- [user] PHP lint via `C:\Users\flavi\php\php.exe -l` → user-preferences.md

## Project
- [project] jimi_webhook v4.1.1 — YUV Parity, PHP 8.3 puro + MySQL 8.0 → project-conventions.md
- [project] Design system Coinbase: azul #0052ff, sidebar #0a0b0d, CTAs pill 100px, JetBrains Mono números/IMEI → project-conventions.md
- [project] Autenticação token cookie `jimi_token` → MySQL `sessions`, sem `session_start()` — $_SESSION é POR REQUEST (nunca persistir nada nele entre requests) → project-conventions.md
- [project] PROJETO_YUV.md é o contrato-mestre; STATUS.md é o diário vivo (estado atual: §12) → project-conventions.md
- [project] Fases 0-M + iteração v4.1.1 concluídas — comandos ponta-a-ponta OK (síncrono + callback offline real), BRT em todo o dashboard, Playwright 37/37 → project-conventions.md
- [project] Servidor homolog: 189.22.240.43 (host `iothub`) Apache/FPM no HOST + 16 containers IoTHub; containers só alcançam o host via IP LAN 10.1.0.43 (nunca localhost) → project-conventions.md
- [project] Deploy homolog (12/07/2026): servidor puxa de hssflavio-ux/jimi_webhook (deploy key dedicada no root; repo Flaviohses é legado); SSH por chave como administrador + sudo com senha; usuário E2E e2e@teste.local existe no homolog; v4.2.0 (e5f9309) implantado, replay 8/8, Playwright 33/40 (7 skips esperados) → project-conventions.md
- [project] Auto-vídeo de evento (v4.2.1, 8e86076): ocorrência nova JT/T dispara 34818 pós-commit (includes/iothub_command.php, operator=auto_video, kill-switch AUTO_VIDEO_REQUEST=0); validado no homolog com device fake (_code 301); falta validar com câmera real (eventCode 0 pode filtrar mídia de alarme) → tech-decisions.md
- [project] IoTHub: comandos via :10088 (segura resposta até 30s aguardando device — timeout do app é 35s); vídeos servidos pelo dvr-upload :23010 (Apache NÃO acessa /iothub/dvr-upload) → project-conventions.md
- [project] Devices reais no homolog: 860112070347838 (JC181) e 869058070151343 (JC182), ambos JTT serverFlagId=0 → project-conventions.md
- [project] Dev Windows tem MySQL 8.0.37 portátil em C:\Users\flavi\mysql (subir com scripts/dev-windows.ps1); usuário E2E: e2e@teste.local → project-conventions.md

## Feedback
- [feedback] Usuário diz "Continue" para avançar fases (não perguntar se quer continuar) → feedback-history.md
- [feedback] Prefere implementação completa por fase com verificação lint ao final → feedback-history.md
- [feedback] Valoriza STATUS.md atualizado como artefato de handoff entre sessões → feedback-history.md

## Reference
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
- [reference] Deploy: scripts/deploy.sh aplica migrations até v4.1.0; deploy-v4.sh (--check/--migrate/--deploy/--verify) → tech-decisions.md
- [reference] Router: `$renamedRoutes` para rotas com hífen (config-ocorrencias, grupos-permissao) — rota nova com hífen vai ali, não em $simpleRoutes → tech-decisions.md
- [reference] Testes: npx playwright test (tests/, 40 testes; specs autenticados pulam sem TEST_EMAIL/TEST_PASSWORD); bash scripts/test_e2e.sh (replay webhooks) → tech-decisions.md

## Pendências (exigem produção/dispositivo — ver STATUS.md §11.4 e §12.7)
- OTA firmware: testar proNo 33027 com dispositivo real
- Specs multi-tenant: exigem credenciais de segundo cliente (TEST_EMAIL_B/TEST_PASSWORD_B)
- deploy.sh: mysqldump falha silenciosamente no homolog (backup de banco não sai) — investigar privilégios
- Limpeza opcional no homolog: device teste 868120246598152 + usuário e2e@teste.local + ocorrências/mídias de teste
