# A fila de comando offline do hub — o que a tela promete e o que foi medido

> Medido em **produção** (`186.248.143.197`, `tracker-instruction-server` em
> `10.1.1.8:10088`) em **07/09/2026**. Doc oficial:
> <https://docs.jimicloud.com/integration/integration.html> — §1.16 Push Offline
> Command, §2.21 Query Offline Command, §2.22 Cancel Offline Command.
>
> **Decisão do dono do produto (07/09/2026): apenas registrar. Nada será
> corrigido agora.**

## 1. O que a aplicação faz hoje

| Parte da doc | Endpoint | Estado |
|---|---|---|
| §1.16 Push Offline Command | `POST /pushInstructResponse` (nós recebemos) | ✅ **implementado** — `handlers/pushinstructresponse.php`, trata `msgType` 1 (assíncrono) e 2 (offline), grava em `command_responses` e fecha a linha em `commands` |
| §2.21 Query Offline Command | `POST {InsAddress}/api/device/queryOfflineInstruct` | ❌ **não implementado** |
| §2.22 Cancel Offline Command | `POST {InsAddress}/api/device/deleteOfflineInstruct` | ❌ **não implementado** |

Ou seja: temos o lado **push** (o hub nos avisa quando um comando offline
finalmente é entregue) e **não** temos o lado **pull** — não há como perguntar
"o que está pendente para este equipamento?" nem cancelar. Um comando mandado a
equipamento offline vira uma linha `commands.status = 'sent'` que só se resolve
se o callback chegar.

O acompanhamento na tela é por polling em `/commandstatus`; `sendcommand.php`
devolve `offline_queued` e as telas escrevem "enfileirado".

## 2. 🔴 A fila do hub está VAZIA — inclusive para comando de 18 dias atrás

`queryOfflineInstruct` **funciona** nesta instalação. A resposta foi sempre a
mesma:

| Equipamento | Situação | `_code` que o hub deu no comando | §2.21 |
|---|---|---|---|
| `865478070649936` | offline há **18 dias**, 1 comando preso em `sent` | **`600` — "The device is offline or timed out"** | `20002 The offline instruction could not be found` |
| `868120246598152` (E2E) | offline há 25 dias, 4 presos | `301` | `20002` |
| `865478070654829` | 47 presos | — | `20002` |
| `865478070003241` | 19 presos | — | `20002` |
| `862798051583785` | 11 presos | — | `20002` |

São **97 comandos em `status='sent'`** nos últimos 30 dias e **nenhum** aparece
na fila do hub. O caso do `865478070649936` é o mais direto: o hub respondeu
exatamente o `_code = 600` que o nosso código usa para afirmar
`offline_queued = true`, e 18 dias depois a fila não tem nada.

**Consequência para o usuário:** a frase que `handlers/video_aovivo.php` mostra
("o comando foi enfileirado e será entregue na reconexão") e a nota de
`handlers/comandos.php` ("o comando offline é enfileirado e entregue quando o
equipamento reconecta") **não estão sustentadas por nada que o hub confirme**.

### ⚠️ O que ainda NÃO está provado

Não dá para distinguir, só com estas medições, entre:

- **(a)** o comando nunca foi enfileirado, ou
- **(b)** foi enfileirado e o hub expirou/purgou a fila.

**O teste que resolve** (não executado — envolve disparar comando a
equipamento): mandar um `VERSION#` a um equipamento sabidamente offline e
consultar `queryOfflineInstruct` **em seguida**. Se voltar `20002`, é (a).

## 3. Duas lacunas concretas no nosso lado

### 3.1 🔴 Nunca mandamos `offlineFlag`

`iothub_send_instruct()` (`includes/iothub_command.php`) monta o payload com:

```
imei, cmdContent, serverFlagId, proNo, platform, requestId, cmdType=normallns, token
```

A §1.16 diz, com todas as letras:

> *Offline Commands: If the response indicates the device is offline
> (`_code:300`) or timed out (`_code:600`) **and the "offlineFlag" parameter in
> a command in delivered is set to "true"**, then the command will be cached as
> an offline command…*

O cacheamento é **condicional a esse parâmetro**, e nós não o mandamos. É a
explicação mais provável para o §2 — e o que torna a hipótese (a) a favorita.

### 3.2 O nosso `offline_queued` só olha `_code = 600`

`handlers/sendcommand.php`:

```php
$offlineQueued = ($iothubCode === 0) && (int)($envio['device_code'] ?? 0) === 600;
```

A doc trata **`300` (offline) OU `600` (timeout)** como o caso offline.
Distribuição real de `_code` em 30 dias de produção:

| `_code` | ocorrências | |
|---|---|---|
| `100` | 860 | sucesso |
| *(NULL)* | 150 | |
| `302` | 70 | device busy |
| **`300`** | **21** | **device offline — hoje NÃO recebe o rótulo** |
| **`600`** | **19** | timeout — é o único que recebe |
| `301` | 14 | |

## 4. ⚠️ Divergência da doc: `cmdType` é obrigatório na prática

A doc marca `cmdType` como **N** (opcional) nas duas seções. Medido:

| Chamada | Resposta |
|---|---|
| `POST /api/device/queryOfflineInstruct` com só `deviceImei` | **HTTP 500** `{"status":500,"error":"Internal Server Error"}` |
| idem + `cmdType=normallns` | HTTP 200 `{"code":20002,"msg":"The offline instruction could not be found"}` |
| idem + `cmdType=normallns-general` | HTTP 200, mesma resposta |

`normallns` é o valor que o nosso despacho já usa em `iothub_send_instruct()`.

Mesma família dos erros de doc que o `CLAUDE.md` já cataloga (o hífen do
`VIDEOUPLOAD`, o `deviceImeis` no plural do `deviceTrackerHB`, o `trackByTime`
que é POST e não GET — ver `docs/QUERY_APIS_IOTHUB.md` §3).

## 5. Se um dia for corrigir

Na ordem de risco, do mais barato ao que mexe em equipamento:

1. **Rodar o teste do §2** primeiro. Sem ele, corrigir é adivinhar.
2. **Tratar `300` junto de `600`** em `sendcommand.php` — mudança de rótulo,
   sem efeito no despacho.
3. **Mandar `offlineFlag`** em `iothub_send_instruct()`. ⚠️ Isso **muda o
   comportamento do hub**, não só o nosso: comandos passariam a ficar
   realmente pendentes e a ser entregues na reconexão. Pense no que acontece
   quando um equipamento volta depois de semanas e recebe uma fila inteira de
   uma vez — inclusive comandos de vídeo cujo contexto já passou.
4. **Expor a fila na tela** (§2.21) e permitir cancelar (§2.22). O §2.22 é
   **destrutivo**: apaga o comando pendente. Só faz sentido depois de (3),
   porque hoje não há fila para mostrar nem para cancelar.

⚠️ E, enquanto (3) não existir, **as duas frases de tela citadas no §2 deveriam
ser revistas** — hoje elas afirmam ao operador algo que o hub não confirma.
