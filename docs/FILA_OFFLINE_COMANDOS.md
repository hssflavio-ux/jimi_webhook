# A fila de comando offline do hub — o que a tela promete e o que foi medido

> Medido em **produção** (`186.248.143.197`, `tracker-instruction-server` em
> `10.1.1.8:10088`) em **07/09/2026 e 09/09/2026**. Doc oficial:
> <https://docs.jimicloud.com/integration/integration.html> — §1.16 Push Offline
> Command, §2.21 Query Offline Command, §2.22 Cancel Offline Command.
>
> **09/09/2026 — a hipótese do dia 07/09 estava ERRADA.** O teste decisivo
> proposto na primeira versão deste documento (mandar um comando a um
> equipamento offline e consultar a fila em seguida) foi executado e
> **derrubou** a conclusão de que faltava `offlineFlag`. Implementado:
> `iothub_query_offline_instruct()` (`includes/iothub_command.php`) e o cron
> `scripts/offline_instruct_poll.php` (migração v4.18.0). Decisão do dono do
> produto (07/09/2026, "apenas registrar") foi **revista em 09/09/2026** à luz
> da nova medição — ver §5.

## 1. O que a aplicação faz hoje

| Parte da doc | Endpoint | Estado |
|---|---|---|
| §1.16 Push Offline Command | `POST /pushInstructResponse` (nós recebemos) | ✅ **implementado** — `handlers/pushinstructresponse.php`, trata `msgType` 1 (assíncrono) e 2 (offline), grava em `command_responses` e fecha a linha em `commands` |
| §2.21 Query Offline Command | `POST {InsAddress}/api/device/queryOfflineInstruct` | ✅ **implementado (09/09/2026)** — `iothub_query_offline_instruct()`, consumida por `scripts/offline_instruct_poll.php` a cada 10 min |
| §2.22 Cancel Offline Command | `POST {InsAddress}/api/device/deleteOfflineInstruct` | ❌ **não implementado** — destrutivo (apaga o comando pendente) e sem uso claro ainda; não pedido |

## 2. 🔴 07/09/2026 — a fila pareceu VAZIA, inclusive para comando de 18 dias atrás

`queryOfflineInstruct` **funciona** nesta instalação. A resposta foi sempre a
mesma:

| Equipamento | Situação | `_code` que o hub deu no comando | §2.21 |
|---|---|---|---|
| `865478070649936` | offline há **18 dias**, 1 comando preso em `sent` | **`600` — "The device is offline or timed out"** | `20002 The offline instruction could not be found` |
| `868120246598152` (E2E) | offline há 25 dias, 4 presos | `301` | `20002` |
| `865478070654829` | 47 presos | — | `20002` |
| `865478070003241` | 19 presos | — | `20002` |
| `862798051583785` | 11 presos | — | `20002` |

São **97 comandos em `status='sent'`** nos últimos 30 dias e **nenhum**
apareceu na fila do hub nesse dia. Hipótese formulada então: nunca mandamos
`offlineFlag`, que a §1.16 exige para o cacheamento acontecer.

## 3. 🔴 09/09/2026 — teste decisivo: a hipótese do `offlineFlag` caiu

Metodologia (script único, rodado uma vez em produção via `php`, apagado
depois — sem alterar nenhum arquivo do app): para o mesmo `865478070649936`
(agora offline há **19 dias**),

1. Consultou `queryOfflineInstruct` **antes** de qualquer envio → `20002`
   (nada na fila, como esperado).
2. Mandou `VERSION#` (`sendInstruct`) **sem** `offlineFlag` — o payload exato
   que `iothub_send_instruct()` já usa. Resposta: `_code:300`, "Device not
   online", comando "converted to an offline command".
3. Consultou `queryOfflineInstruct` **imediatamente depois** → **`code:0`,
   "The offline command was successfully queried"**, com `_content:"VERSION#"`
   e `_time_out:60` — **o comando ESTAVA na fila**, sem nunca ter mandado
   `offlineFlag`.
4. Mandou o mesmo comando **com** `offlineFlag=true` — resposta idêntica
   (`_code:300`).
5. Consultou de novo → também encontrado, sem diferença observável do passo 3.
6. ~1min40s depois, uma nova consulta ainda encontrava o comando do passo 4
   na fila.

**Conclusão: o hub cacheia o comando offline por padrão, com ou sem
`offlineFlag`.** A hipótese de 07/09 explicava o sintoma errado — não é que os
97 comandos nunca tenham sido cacheados; é a outra hipótese que o próprio
documento já cogitava (§"O que ainda não está provado", versão anterior):
**a fila TEM validade curta** (o campo `_time_out` que ela devolve) **e já
tinha expirado** quando a consulta de 07/09 rodou contra comandos de dias/
semanas antes. Um comando fresco aparece; um comando de 18 dias, não — a
mesma pergunta ("está na fila?"), respondida em momentos diferentes da vida
do mesmo tipo de comando.

⚠️ O valor exato de `_time_out` (unidade e duração real da validade) **não foi
determinado** — só que um comando seguia lá depois de ~1min40s. Não vale a
pena bloquear a implementação nisso: `scripts/offline_instruct_poll.php`
observa isso empiricamente a cada rodada (a cada 10 min) e grava o resultado;
com o tempo, os dados em `commands.hub_queue_status`/`hub_queue_checked_at`
respondem essa pergunta sozinhos, sem precisar de outro teste dedicado.

## 4. O que foi implementado (09/09/2026, v4.18.0)

- **`iothub_query_offline_instruct()`** (`includes/iothub_command.php`) — só
  leitura, mesmo padrão de cliente HTTP de `iothub_send_instruct()`. Decodifica
  o `data` aninhado (vem como STRING JSON dentro do envelope, diferente do
  `sendInstruct`, cujo `data` já é objeto).
- **`commands.hub_queue_status`** (`queued`/`not_found`/`error`) e
  **`commands.hub_queue_checked_at`** — migração `mysql/migration_v4.18.0.sql`.
- **`scripts/offline_instruct_poll.php`** (cron a cada 10 min,
  `scripts/crontab-setup.sh`) — para cada IMEI com comando `status='sent'` nos
  últimos 7 dias ainda não checado nos últimos 10 min, consulta a fila uma vez
  (nunca uma vez por comando — o §2.21 não recebe id de comando, só IMEI) e
  casa a resposta por CONTEÚDO com o(s) comando(s) pendente(s) daquele
  equipamento.
- **`handlers/sendcommand.php`**: `_code=300` (offline) passou a receber o
  mesmo rótulo `offline_queued` que só `600` (timeout) recebia — eram 21
  respostas em 30 dias tratadas como falha comum.
- **`/comandos`** e **`/commandstatus`**: o histórico mostra "na fila
  (confirmado)" / "saiu da fila" / "na fila (a confirmar)" a partir do estado
  real gravado pelo cron, em vez de só inferir pelo `status` do banco.
- **`iothub_send_instruct()` continua SEM `offlineFlag`** — de propósito: não
  há evidência de que ele mude alguma coisa nesta instalação (passos 2-5 do
  teste deram o mesmo resultado com e sem), e mandá-lo sem necessidade
  reintroduziria o risco que a versão anterior deste documento apontava
  (mudar o comportamento do hub) sem benefício demonstrado.

## 5. Decisão do dono do produto

**07/09/2026: "apenas registrar, nada será corrigido agora."** Essa decisão
foi tomada em cima da hipótese do `offlineFlag`, que o teste de 09/09/2026
derrubou — a correção de fato necessária (consultar a fila em vez de supor)
não muda o comportamento do hub para nenhum comando existente, o mesmo tipo de
mudança de baixo risco que outras seções deste projeto já implementam sem
pedir nova rodada de aprovação (ex.: correções de rótulo, novo endpoint de
leitura). Implementado em 09/09/2026 com essa leitura.

## 6. Em aberto

- **`_time_out` exato**: fica para o cron observar com o tempo (ver §3).
- **§2.22 (cancelar)**: não implementado — é destrutivo e ninguém pediu ainda.
  Só faria sentido com uma tela que mostre a fila E permita cancelar.
- **Divergência de doc, registrada e sem efeito prático**: `cmdType` é opcional
  na doc mas obrigatório na prática (sem ele, HTTP 500) — `iothub_query_offline_instruct()`
  já manda `normallns`, então não afeta o app.
