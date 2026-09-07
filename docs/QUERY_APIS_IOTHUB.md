# Query APIs do IoT Hub — o que existe, o que funciona, o que engana

> Medido em **produção** (`186.248.143.197`, hub local na porta 9080) em
> **07/09/2026**. Nada aqui é dedução a partir da doc — cada linha veio de uma
> chamada real. A doc oficial é
> <https://docs.jimicloud.com/integration/integration.html>, seção **3 Query
> APIs**.
>
> **Decisão do dono do produto (07/09/2026): apenas registrar. Nada será
> implementado agora.** Este documento existe para que a próxima pessoa não
> repita a medição — e, principalmente, para que não caia na armadilha do §1.

## 1. 🔴 "3.2 General Query API" NÃO é geral — é a API dos dispositivos JIMI

Este é o ponto que custa tempo. Os títulos das duas seções sugerem
"específica para JT/T" e "genérica para todos":

- **3.1** Query API (V2, for JT/T devices)
- **3.2** General Query API

A tabela **API Description**, logo acima das duas, diz outra coisa — e é ela
que está certa:

| API | Descrição da própria doc | JIMI | JT/T |
|---|---|---|---|
| `/api/v2/alarm/getAlarm` | Query alarm data of **JT/T** devices | | ✓ |
| `/api/v2/device/deviceTrackerHB` | Latest heartbeat of **JT/T** devices | | ✓ |
| `/api/v2/tracker/trackByTime` | Tracks of **JT/T** devices | | ✓ |
| `/api/v2/tracker/trackOtherPosByTime` | Other positions, **JT/T** | | ✓ |
| `/api/alarm/getAlarm` | Query alarm data of **JIMI** devices | ✓ | |
| `/api/device/deviceTrackerHB` | Latest heartbeat of **JIMI** devices | ✓ | |
| `/api/tracker/trackByTime` | Tracks of **JIMI** devices | ✓ | |
| `/api/tem/getTemDetail` | Temperatura/umidade, **JIMI** | ✓ | |
| `/download/` | File Storage API | ✓ | |

**A divisão é por PROTOCOLO, não por "versão nova/versão velha".** `/api/v2/` é
o mundo JT/T; `/api/` é o mundo JIMI. As duas famílias moram na **mesma porta**
(9080, o container `tracker-dvr-api`) e têm parâmetros idênticos no papel —
`deviceImei`, `startTime`, `endTime` em UTC, `alertType` opcional.

É a mesma classe de erro de fonte que o `CLAUDE.md` já documenta três vezes
(`MILE#` batizado por coincidência, `CHECK`/`LOG` descartados como "tokens
soltos", a descrição trocada entre `FILELIST` A006 e A007): **o título mente e
a tabela acerta**.

## 2. O que este sistema implementa

Só a metade **JT/T**:

- **`includes/iothub_alarm_api.php`** → `GET /api/v2/alarm/getAlarm` (§3.1.1).
  - `iothub_get_alarms_raw()` — uma chamada crua.
  - `iothub_get_alarms_chunked()` — subdivide a janela por causa do teto de
    1000 linhas sem paginação.
- **Consumidor único: `scripts/video_upload_backfill.php`** (cron), que cruza
  os alarmes que a câmera já tem contra os que estão no storage e dispara
  `VIDEOUPLOAD` para os que faltam vídeo.
- **Não há tela.** Nenhuma rota do dashboard requisita alarmes ao hub.

A metade **JIMI** (`/api/alarm/getAlarm`, §3.2.1) **não está implementada**.

## 3. 🔴 Implementar a metade JIMI hoje entregaria uma função que retorna vazio

Sondagem em 07/09/2026, janela de 7 dias, os dois endpoints contra as duas
famílias:

| Câmera | Protocolo | Alarmes no NOSSO banco | `/api/v2/alarm/getAlarm` | `/api/alarm/getAlarm` |
|---|---|---|---|---|
| JC181 `860112070347838` | JT/T | 70 | **13** | 0 |
| JC371 `865478070003241` | JT/T | 91 | **36** | 0 |
| JC400AD `864993060392306` | JIMI | 46 | 0 | **0** |
| JM-VL01 `868982050616424` | JIMI | 5 | 0 | **0** |

O JC400AD tem 46 alarmes nossos na janela, **vindos de webhook** — o hub os
viu. Ainda assim `/api/alarm/getAlarm` devolve `{"code":0,"msg":"The query is
successful","data":[]}`, com e sem `alertType`, e também numa janela estreita
ao redor de um alarme que sabemos existir.

### Não é problema de rota — a família `/api/` está publicada

Os irmãos dela respondem na mesma porta, e **contradizem a doc em dois pontos**:

| Chamada | Resposta medida |
|---|---|
| `GET /api/device/deviceTrackerHB?deviceImei=…` | `400002 Missing required parameter, parameter: device imeis` — quer **`deviceImeis`, no plural** (o irmão v2 diz "device imei", singular) |
| `GET /api/tracker/trackByTime?…` | `400001 Method not supported, Request method 'GET' is not supported` — é **POST**, não GET como a doc afirma |

Ou seja: as rotas existem e validam entrada. `/api/alarm/getAlarm` **aceita** os
parâmetros e responde sucesso com lista vazia — o comportamento de um store que
não tem o dado, não de uma rota errada.

**Pergunta em aberto para a fabricante:** o store de consulta de alarmes JIMI
está habilitado neste hub on-premise? Enquanto não houver resposta, escrever o
cliente de §3.2.1 é escrever código que devolve `[]`.

## 4. ⚠️ O lado que funciona NÃO é espelho do webhook

Não use `/api/v2/alarm/getAlarm` como fonte de verdade. JC371
`865478070003241`, mesma janela de 7 dias:

| | Total | Composição |
|---|---|---|
| API | **36** | 24 `removeAlarmType` + 12 `1041` |
| Nosso banco (webhook) | **91** | 30× `1041`, 30× `259`, 29× `257`, 1× `256`, 1× `257` |

Duas diferenças que importam:

1. **Vocabulário próprio.** A API devolve `removeAlarmType` genérico onde o
   webhook entrega o código específico de fim de alarme (`259` = Fim de Alarme:
   Falha no Armazenamento, `257` = Fim de Alarme: Perda de Sinal de Vídeo).
2. **`alarmLabel` é raro.** Nessa amostra, **0 dos 36** vieram com label — e o
   label é justamente a chave que casa o alarme com o anexo de vídeo
   (`link_upload_by_alarm_label()`). Só alarme capturado **com anexo** tem
   (medido em 26/08/2026: `alertType` 264/ADAS e 265/DMS têm; 256/257 e
   `removeAlarmType` não têm).

É por isso que o `video_upload_backfill.php` tem escopo estreito de propósito —
ele procura alarme **com** label, e ignora o resto.

## 5. Se um dia for implementar

- O cliente novo deve ficar **ao lado** de `iothub_alarm_api.php`, reusando
  `iothub_alarm_api_base()` e o mesmo tratamento de `alarmMsg` (a resposta traz
  o alarme como **string JSON dentro de `alarmMsg`** — decodificar duas vezes)
  e de `alarmLabel` (vem separado por vírgula, em hex; concatenar sem a
  vírgula reproduz `alarms.alarm_label` byte a byte).
- **Escolher o endpoint pelo protocolo do modelo** (`device_models.protocol`),
  nunca por tentativa-e-erro: mandar a JIMI para o `/api/v2/` devolve `code:0`
  com lista vazia — sucesso silencioso, exatamente o modo de falha que este
  projeto já pagou no `37121` enviado a câmera JIMI (v4.9.28) e no `33027`
  usado como OTA.
- Antes de qualquer linha, **repetir a sondagem do §3**: se continuar vazio, o
  problema não é nosso.
