# API Coverage — Jimi Webhook System v2.0.0

Referência oficial: `https://docs.jimicloud.com/integration/integration.html`

---

## Implemented Endpoints

### `/pushevent` — Login/Logout Notification (Seção 1.1)

**Handler**: `handlers/pushevent.php` v2.0.0  
**Tabela**: `events`

**Parâmetros:**

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| token | string | Sim | Token de acesso |
| data_list | JSON array | Sim | Array de eventos (até 50 por requisição) |

**data_list — Campos de cada item:**

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| deviceImei | string | Sim | IMEI do dispositivo |
| type | string | Sim | LOGIN ou LOGOUT |
| gateTime | Date | Sim | Tempo UTC (yyyy-MM-dd HH:mm:ss) |
| timezone | string | Sim | Fuso horário (ex: GMT+08:00) |

**Resposta:** `{"code":0,"msg":"success"}`

---

### `/pushhb` — Heartbeat (Seção 1.2)

**Handler**: `handlers/pushhb.php` v2.0.0  
**Tabela**: `heartbeats`

**data_list — Campos de cada item:**

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| deviceImei | string | Sim | IMEI do dispositivo |
| gateTime | Date | Sim | Tempo UTC |
| powerLevel | int | Não | Nível de bateria |
| gsmSign | int | Não | Sinal GSM (0-100) |
| acc | int | Não | 0=ACC_OFF, 1=ACC_ON |
| oilEle | int | Não | 0=Conectado, 1=Desconectado |
| gpsPos | int | Não | 0=Não posicionando, 1=Posicionando |
| remoteLock | int | Não | 0=Sem bloqueio, 1=Bloqueio remoto |
| powerStatus | int | Não | 0=Sem carga, 1=Carregando |
| fortify | int | Não | 0=Defesa desativada, 1=Ativada |

---

### `/pushgps` — GPS Data (Seção 1.3)

**Handler**: `handlers/pushgps.php` v2.0.0  
**Tabela**: `gps_data`

**data_list — Campos de cada item (28 campos extraídos):**

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| deviceImei | string | Sim | IMEI do dispositivo |
| gpsTime | Date | Sim | Hora do GPS (UTC) |
| gateTime | Date | Sim | Hora do gateway (UTC) |
| satelliteNum | int | Sim | Número de satélites |
| lng | double | Sim | Longitude |
| lat | double | Sim | Latitude |
| gpsMode | int | Sim | 0=Real-time, 1=Re-upload |
| gpsSpeed | int | Sim | Velocidade (km/h) |
| direction | int | Sim | Direção (0-360°) |
| acc | int | Sim | 0=ACC_OFF, 1=ACC_ON |
| postType | int | Sim | 1=GPS, 2=LBS, 3=WiFi |
| altitude | int | Não | Altitude (metros) |
| status | int | Não | Código de status binário |
| distance | int | Não | Odômetro (metros) |
| postMethod | int | Não | Modo de upload (0x00-0x0F) |
| driverLicenseStatus | int | Não | Status da CNH |
| driverLicense | string | Não | Dados da CNH |
| sosStatus | int | Não | 0=Não acionado, 1=Acionado |
| doorStatus | int | Não | 0=Fechada, 1=Aberta |
| temperature | float | Não | Temperatura (°C) |
| transparentData | string | Não | Dados pass-through (HEX) |

---

### `/pushalarm` — Alarm Data (Seção 1.4)

**Handler**: `handlers/pushalarm.php` v2.0.0  
**Tabelas**: `alarms` (45 colunas), `alarm_types` (lookup), `device_statistics`

**Suporte a 3 tipos de mensagem:**

| type | Protocolo | Descrição |
|---|---|---|
| DEVICE + msgClass=0 | JIMI | Alarme de dispositivo JC400 |
| DEVICE + msgClass=1 | JT/T 808 | Alarme de dispositivo JC450 |
| ICCID | — | Atualização de ICCID do SIM |
| VIN | — | Atualização de VIN do veículo |

**Resolução de nome**: Isolamento estrito JIMI/JTT via `alarm_types`. Suporte a bitmask JT/T 256 (32 bits) e subtipos compostos (264-X ADAS, 265-X DMS, 266-X BSD).

---

### `/pushfileupload` — File Upload Notification (Seção 1.8)

**Handler**: `handlers/pushfileupload.php` v2.0.0  
**Tabela**: `media_files`

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| deviceImei | string | Sim | IMEI do dispositivo |
| fileName | string | Sim | Lista de arquivos (separados por `;`) |
| gateTime | Date | Sim | Tempo UTC |
| result | string | Sim | SUCCESS ou FAILURE |

---

### `/pushlbs` — LBS Data (Seção 1.10)

**Handler**: `handlers/pushlbs.php` v2.0.0  
**Tabela**: `lbs_data`

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| deviceImei | string | Sim | IMEI do dispositivo |
| gateTime | string | Sim | Tempo UTC |
| lbsJson | JSON string | Sim | `{"mcc":724,"mnc":10,"cellList":"LAC1,CI1,RSSI1;..."}` |
| postType | string | Sim | "WIFI" ou "LBS" |

**Processamento**: Cada entrada de `cellList` gera uma linha na tabela `lbs_data`.

---

### `/pushresourcelist` — Resource List (Seção 1.11)

**Handler**: `handlers/pushresourcelist.php` v2.0.0  
**Tabela**: `resource_lists`

**resourceList — Campos de cada item:**

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| channel | int | Sim | Canal da câmera |
| beginTime | string | Sim | Início da gravação |
| endTime | string | Sim | Fim da gravação |
| alarmFlag | int | Sim | Tipo de alarme |
| resourceType | int | Sim | 0=imagem, 1=áudio, 2=vídeo |

---

### `/pushftpfileupload` — FTP Upload Result (Seção 1.12)

**Handler**: `handlers/pushftpfileupload.php` v2.0.0  
**Tabela**: `media_files`

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| deviceImei | string | Sim | IMEI |
| result | int | Sim | 0=Sucesso, 1=Falha |
| instructionID | string | Sim | ID da instrução |
| gateTime | string | Sim | Tempo UTC |

---

### `/pushIothubEvent` — Hub Events (Seção 1.13)

**Handler**: `handlers/pushiothubevent.php` v2.0.0  
**Tabela**: `iothub_events`

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| deviceImei | string | Sim | IMEI |
| gateTime | long | Sim | Timestamp Unix |
| eventType | string | Sim | UploadAlarmFileList/UploadAlarmFileBegin/UploadAlarmFileEnd/UploadMediaFileBegin/UploadMediaFileEnd |
| eventContent | string | Sim | Conteúdo do evento |

---

### `/pushTerminalTransInfo` — Extension Data (Seção 1.15)

**Handler**: `handlers/pushTerminalTransInfo.php` v2.0.0  
**Tabela**: `device_events`

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| deviceImei | string | Sim | IMEI |
| postTime | string | Sim | Tempo de transmissão |
| extensionId | int | Sim | 8197=device status, 8199=serial port data |
| content | string | Sim | Conteúdo HEX |

---

### `/pushInstructResponse` — Command Response (Seção 1.16)

**Handler**: `handlers/pushinstructresponse.php` v2.0.0  
**Tabelas**: `command_responses`, `commands`

**Parâmetros POST (nível superior):**

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| token | string | Sim | Token de acesso |
| msgType | int | Sim | 1=assíncrono, 2=offline |
| data_list | JSON array | Sim | Array de respostas |

**data_list — Campos de cada item:**

| Campo | Tipo | Obrig. | Descrição |
|---|---|---|---|
| code | int | Sim | Código de resultado (0=sucesso) |
| msg | string | Não | Mensagem descritiva |
| data._imei | string | Sim | IMEI do dispositivo |
| data._content | string | Não | Conteúdo do comando |
| data._msg | string | Não | Resposta do dispositivo |
| data._serverFlagId | string | Não | ID do servidor |

---

## Not Yet Implemented

| Seção | Endpoint | Descrição |
|---|---|---|
| 1.5 | `/rfid` | RFID Data Push |
| 1.6 | `/wgtc` | Plug-In Module Data |
| 1.7 | `/pushoil` | Oil Data |
| 1.9 | `/pushtem` | Temperature and Humidity |
| 1.14 | `/pushPassThroughData` | Pass-Through Data |
| 1.17 | `/pushFileContent` | Facial Recognition ID List |
| 1.18 | `/pushextendedkks` | Extended JIMI Protocol 0x07 |
| 1.19 | `/uploadCallback` | DVR Real-Time Upload Callback |
| 1.20 | `/generalBusiness` | EV49E Bluetooth MAC Address |
| 1.21 | `/pushobd` | OBD Data |
| 1.22 | `/pushfaultinfo` | Fault Info |
| 1.23 | `/pushtripreport` | Trip Report |

## Non-API Endpoints

| Endpoint | Handler | Tipo |
|---|---|---|
| `/ping` | `handlers/ping.php` | Health check |
| `/dashboard` | `handlers/dashboard.php` | Painel SSR |
| `/camerasdata` | `handlers/camerasdata.php` | AJAX - dados dos dispositivos |
| `/commandstatus` | `handlers/commandstatus.php` | AJAX - histórico de comandos |
| `/sendcommand` | `handlers/sendcommand.php` | AJAX - envio de comandos |
| `/mediadata` | `handlers/mediadata.php` | AJAX - galeria de mídia |
| `/trackdata` | `handlers/trackdata.php` | AJAX - tracks GPS histórico |
| `/hbdata` | `handlers/hbdata.php` | AJAX - heartbeats |
| `/pushcmd` | `handlers/pushcmd.php` | Custom - match de respostas |

## Database Tables

| Tabela | Handlers |
|---|---|
| `alarm_types` | pushalarm (lookup) |
| `alarms` | pushalarm |
| `command_responses` | pushinstructresponse, commandstatus |
| `commands` | sendcommand, pushcmd, pushinstructresponse |
| `device_events` | pushTerminalTransInfo |
| `device_statistics` | Todos (via stored procedures) |
| `devices` | Auto-registro |
| `events` | pushevent |
| `gps_data` | pushgps |
| `heartbeats` | pushhb |
| `iothub_events` | pushiothubevent |
| `lbs_data` | pushlbs |
| `media_files` | pushfileupload, pushftpfileupload |
| `request_logs` | WebhookHandler (auto) |
| `resource_lists` | pushresourcelist |
| `system_info` | Migration script |
