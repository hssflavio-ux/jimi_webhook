# API Coverage — Jimi Webhook System v4.1.0

> Mapa de todos os endpoints HTTP do sistema: webhooks, AJAX e páginas.
> Gerado na Fase M.5 (08/07/2026). Fonte de verdade: `handlers/router.php`.

## 1. Webhooks (Jimi IoT Hub → sistema)

Todos aceitam `POST` com JSON `{token, msgType, data_list[]}` (ou form-urlencoded
com `data_list` como string JSON). **Auth**: campo `token` == `WEBHOOK_TOKEN` do `.env`.
Resposta imediata `{"code":0,"message":"Accepted and Processing","data":{"queue_hash"}}`
(HTTP 200 antecipado via `fastcgi_finish_request`; o processamento é assíncrono).
Payloads repetidos (mesmo MD5 de `data_list`) são descartados por 10 minutos.

| Endpoint | O que persiste | Observações |
|---|---|---|
| `POST /pushgps` | `gps_data` + `device_statistics` (procedure) | Campos no nível do item: `deviceImei`, `lat`, `lng`, `gpsTime`, `speed`, `acc`… GPS (0,0) é filtrado |
| `POST /pushhb` | `heartbeats` | Heartbeat/keepalive |
| `POST /pushalarm` | `alarms` (45 colunas) → **motor de ocorrências** (`occurrences`, `occurrence_events`) | `msg.alertType`/`alarmTime`; isolamento JIMI (`msgClass=0`) vs JT/T (`msgClass=1`); `type=ICCID/VIN` vira update de device |
| `POST /pushevent` | `events`/`device_events` | Eventos genéricos |
| `POST /pushfileupload` | `media_files` (+ `link_upload_to_occurrence`, janela ±3 min) | `deviceImei`, `fileName` (lista `;`), `result` SUCCESS/FAILURE, `gateTime`, `channel` |
| `POST /pushftpfileupload` | `ftp_uploads` / `media_files` | Upload via FTP |
| `POST /pushlbs` | `lbs_data` | Posição por célula (LBS) |
| `POST /pushresourcelist` | `resource_lists` | Lista de recursos de vídeo do device |
| `POST /pushiothubevent` | `iothub_events` | Eventos do IoT Hub |
| `POST /pushTerminalTransInfo` | dados transparentes | Extrai `content`/`extensionData` estruturado |
| `POST /pushinstructresponse` | `command_responses` | Resposta de comandos enviados ao device |

## 2. Endpoints AJAX (dashboard)

**Auth**: cookie `jimi_token` (64-hex, tabela `sessions`). Escopo multi-tenant pelo
`customer_id` da sessão. Sem sessão → `{"code":401}` (JSON).

| Endpoint | Método | Parâmetros | Resposta (`data`) |
|---|---|---|---|
| `/camerasdata` | GET | — | Lista de devices com status online/offline (usado pelo fleet counter e mapas) |
| `/trackdata` | GET | `imei`, período | Posições GPS para trilha no mapa |
| `/hbdata` | GET | `imei` | Heartbeats do device |
| `/mediadata` | GET | `imei`, filtros | Arquivos de mídia (fotos/vídeos) |
| `/ocorrenciasdata` | GET | `status`, `risk`, `page`, `per_page`, `date_from`, `date_to`, `search` | `kpis{total,aguardando,em_tratativa,resolvida,descartada}`, `devices{online,offline,total}`, `risk_distribution`, `rows[]` paginado |
| `/exportardata` | GET | — | `jobs[]` (últimos 20: `id`, `type`, `status`, **`format`**, **`mime_type`**, `result_path`, `error_message`), `pending` |
| `/commandstatus` | GET | `command_id` | Status do comando (polling 3s/10s até 5 min) |
| `/sendcommand` | POST | JSON ou form: `imei`, `proNo` (whitelist 128–34818), `content`/`cmdContent` | Despacho de comando via IoTHub; valida posse do IMEI |
| `/devicemodels` | GET | — | Modelos de device cadastrados |
| `/customer_switch` | POST | JSON `{customer_id}` | Troca o contexto de cliente da sessão |

## 3. Páginas (dashboard — `require_login()`)

POSTs de formulário exigem CSRF (`csrf_field()` → `_csrf_token`, derivado por HMAC
do token de sessão). Páginas administrativas usam `require_admin()`.

| Rota | Handler | POST actions |
|---|---|---|
| `/` | `resumo.php` | — (visão 360°, cache `metrics_snapshots`) |
| `/rastreamento` | `rastreamento.php` | — |
| `/bi` | `bi.php` | — (gráficos sob demanda) |
| `/ocorrencias/dashboard` | `ocorrencias_dashboard.php` | Transições de status, notas, falso-positivo |
| `/comandos` | `comandos.php` | Presets JIMI/JT-T |
| `/exportar` | `exportar.php` | Criar job de relatório (`report_name`, `report_type`, `date_from`, `date_to`, **`format`** csv/xlsx/pdf) |
| `/video/aovivo` `/video/playback` `/video/downloads` | `video_*.php` | Playback envia proNo 34817 |
| `/relatorios/{posicoes,deslocamento,desatualizados,alarmes,ocorrencias}` | `rel_*.php` | — (filtros via GET) |
| `/ativos`, `/ativos/novo`, `/ativos/{imei}` | `ativos*.php` | CRUD devices (path param → `$_GET['imei']`) |
| `/chips` | `chips.php` | CRUD SIM (`action=save/delete`) |
| `/clientes`, `/clientes/{id}` | `clientes.php` | CRUD + impersonar + white-label |
| `/equipamentos` | `equipamentos.php` | CRUD + FOTA + import CSV em lote |
| `/grupos-permissao` | `grupos_permissao.php` | Matriz RBAC (rota com hífen → `$renamedRoutes`) |
| `/motoristas` | `motoristas.php` | `action=save/delete` (name, cnh_number, cnh_category, vencimentos, identifier) |
| `/config-ocorrencias` | `config_ocorrencias.php` | Perfis de regras DMS (rota com hífen → `$renamedRoutes`) |
| `/usuarios` | `usuarios.php` | Abas Minha Empresa/Meus Clientes |
| `/checklist`, `/checklist/inspecao` | `checklist*.php` | CRUD + preenchimento |
| `/perfil` | `perfil.php` | Troca de senha |

## 4. Autenticação / infra

| Rota | Método | Notas |
|---|---|---|
| `/login` | GET/POST | `email`, `password`, `redirect` (só paths locais — R05). Rate limit: 5 falhas/IP/15 min (`login_log`). Cookie `jimi_token` HttpOnly/SameSite=Lax |
| `/logout` | GET | Invalida a sessão |
| `/setup` | GET/POST | Cria o admin — só funciona com a tabela `users` vazia |
| `/ping` | GET | Health check: `{"code":0,"message":"pong",…}` sem auth |

## 5. Arquivos estáticos

`manifest.json` (PWA), `/assets/icons/*.png` (ícones 192/512 + maskable),
`storage/reports/*.{csv,xlsx,pdf}` (downloads de relatórios — servidos direto
pelo Apache; no dev, `server.php` inclui `csv`/`xlsx` na whitelist de estáticos).
