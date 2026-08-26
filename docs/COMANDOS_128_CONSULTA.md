# Comandos `proNo 128` — a forma de CONSULTA que o catálogo não registra

> Levantado em 16/08/2026 contra a wiki oficial da linha JC
> (<https://wiki-foconavia.newtectelemetria.com.br/>, Wiki.js — 76 páginas, 68 legíveis
> por HTTP) e medido em quatro câmeras reais de produção.
> Complementa `PROJETO_PARAMETROS.md`, que trata do lado JT/T (`33027`/`33028`/`33030`).

## 1. A falha, em uma frase

**Todo comando da wiki tem uma forma NUA — `CMD#`, sem parâmetros — que é a
consulta, e o `includes/command_catalog.php` registra apenas o setter.**

O catálogo tem `APN,NOME,APN#` e `SERVER,A,B,C#`. A wiki lista, ao lado de cada
setter, também `APN#` e `SERVER#`. Como a tela só oferece o que está no
catálogo, **nunca houve como ler um parâmetro pelo canal JIMI** — só escrever.
É por isso que a divergência de APN documentada em `docs/` (33028 diz `cmnet`,
o equipamento usa `allcombl.br`) ficou invisível: ninguém tinha o botão de
perguntar.

Consequência prática do vazio: das 27 respostas de comando registradas em
produção até 15/08/2026, **nenhuma** era consulta de configuração.

## 2. A convenção, confirmada em campo

`CMD#` é consulta. Medido nos quatro equipamentos, com o `33028` capturado
antes e depois de cada bateria e comparado byte a byte — **nenhuma escrita em
nenhum dos quatro**.

Quando o comando não se aplica, o equipamento **recusa**; não aplica valor
vazio. São quatro dialetos para a mesma recusa, e é preciso conhecer os quatro:

| Dialeto | Quem responde assim |
|---|---|
| `Time Out!` | JC371 |
| `<CMD#>Command was not recognized!` | JC371 (firmware JMBS) |
| `Not support!` | JC181 |
| `instruction error!` | JC182 |
| `Error:Number of parameters errors!` | todos, quando o comando exige parâmetro |

⚠️ **`command_response_interpret()` só conhece dois deles.** `Not support!` e
`instruction error!` viram `erro`; `Time Out!` e `Command was not recognized!`
caem em `neutro — "Resposta do equipamento"` e chegam à tela como se fossem
dado. Mesma família do defeito que a v4.9.20 corrigiu.

⚠️ **`CAMERA#` respondeu `<CAMERA#> SET OK` no JC182** — "SET OK", não um
valor —, o que à primeira vista parecia escrita. A wiki resolve: `CAMERA,TF#`
devolve "espaço de memória total e livre" e a página diz explicitamente *"Para
consulta, enviar: `CAMERA#`"*. É consulta; o firmware é que responde com uma
frase de confirmação em vez do dado. Fica catalogado como consulta, com a
ressalva de que a resposta não traz o valor.

## 3. O conjunto é POR MODELO — e ignorar isso custa a medição

A primeira bateria mandou uma lista única para todos e colheu `Time Out!` em 11
de 17 comandos no JC371. Não era o equipamento: **o JC371 não implementa
`SPEED`/`FATIGUE`/`ACCREP`/`TIMER1`** — quem faz excesso de velocidade nele é o
`AOSD`. Refeita por modelo, conforme a wiki:

| Equipamento | Bateria genérica | Bateria por modelo |
|---|---|---|
| 371_3241 (JC371) | 4/17 — **24%** | 16/18 — **89%** |
| FJR7B59 (JC182) | 8/17 — **47%** | 19/22 — **86%** |
| 181_7838 (JC181) | 13/17 — 76% | 9/16 medidos antes do canal cair |

### Consultas documentadas, por modelo

- **JC371** — `APN# SERVER# TIMER# ANGLEREP# PARAM# VERSION# STATUS# DISCAMERA#
  DMSSW# DMSVSP# HDS# LED# PICTURE# PWDSW# SSID# UPFILESIZE# WIFIAPT#`
- **JC181** — `APN# SERVER# TIMER# TIMER1# SPEED# FATIGUE# ANGLEREP# SENALM#
  ACCREP# COLLIDE# GPSDUP# SPEEDCHECK# SWERVE# TIMEZONE# VERSION# STATUS#`
- **JC182** — `APN# ASETAPN# SERVER# TIMER# ANGLEREP# MILE# BCD# SF# SSID# ACC#
  ACCV# TLR# URLTYPE# VIDEOTIMEZONE# VOLUME# WIFIAPT# VERSION# STATUS#`
- **JC400 / JC400AD / JC450** — não medidos; a wiki registra conjuntos próprios.

## 4. Mapeamentos novos para o `33028`

🔴 **Correção de uma leitura errada feita na primeira análise.** Eu havia
anunciado `MILE#` como a contraparte do hodômetro (parâmetro `128`), porque o
JC182 respondeu `MILE#,0` e o `33028` do mesmo equipamento trazia `"128":"0"`.
Os dois zeros coincidiram e eu li coincidência como significado. A wiki desfaz:
**`MILE,P1#` é a UNIDADE DE VELOCIDADE** — `0` = km/h, `1` = mph. O hodômetro é
o **`MILEAGE,P1#`**, que a wiki documenta só na forma de escrita ("permite
ajustar manualmente o valor atual do hodômetro"). **O parâmetro `128` segue sem
contraparte de consulta conhecida.** É o mesmo erro que a regra "nunca batizar
por palpite" existe para evitar, cometido sobre um valor medido em vez de um
nome.

⚠️ **`AOSD` também não é comando.** Ele aparece como `EVENTSET,AOSD,30,0#` — é
sub-comando do `EVENTSET`, que **já estava catalogado**. A afirmação continua
verdadeira no essencial (é por ele que o JC371 faz excesso de velocidade, e não
por `SPEED`), mas não há nada a cadastrar.

| Parâmetro JT/T | Comando 128 | Medido |
|---|---|---|
| `93` colisão | `COLLIDE#` (JC181) | o JC181 recusa `CRASHALM#` com `Not support!` |
| `85`/`86` velocidade | `SPEED#` (JC181/JC182/JC400) · `EVENTSET,AOSD,P1,P2#` (JC371) | JC181: `OFF,0,110,10` |
| `16` APN | `ASETAPN#` (JC182) | quarta fonte independente do APN real |
| `117`/`119` vídeo | `VIDEOPARAM#` (duração dos clipes) · `VIDEORSL,…#` (resolução no TF) | agora catalogados |
| `128` hodômetro | — | `MILEAGE,P1#` existe, mas só na forma de escrita |

## 5. Os comandos que faltavam — 27 cadastrados na v4.9.25

O primeiro levantamento contou 36 ausentes. Depois de ler o texto em volta de
cada um, sete saíram: `AOSD` e `SOS` não são comandos (`AOSD` é sub-comando do
`EVENTSET`; `SOS` apareceu dentro da string SMS `FILTER#666666#SOS#1`, onde é
**nome de evento**, não comando), e `ON`, `P3`, `P5`, `CAR`, `DMS`, `CHECK` e
`LOG` são tokens soltos de tabelas de parâmetros. 🔴 **`CHECK` e `LOG` NÃO
eram — ver §8.1.** Os dois são comandos reais, documentados na planilha do
JC371 e medidos respondendo em produção; o descarte foi feito contra a wiki,
que não os documenta. Sobraram **27**, todos
cadastrados com nome e descrição tirados do texto da wiki — não de palpite.

O catálogo foi de **120 para 147** entradas, 49 delas com forma de consulta.

| Comando | Sintaxes na wiki |
|---|---|
| `ASETAPN` | `ASETAPN#` |
| `BCD` | `BCD#` · `BCD,P1#` |
| `CAMERA` | `CAMERA#` — ⚠️ ver §2 |
| `COLLIDE` | `COLLIDE#` |
| `DISK` | `DISK,1#` |
| `FILTER` | `FILTER#` |
| `FORMAT` | `FORMAT,1#` · `FORMAT#` — 🔴 destrutivo |
| `GPSDUP` | `GPSDUP#` |
| `KEYFUN` | `KEYFUN,1,1,2#` |
| `LOGASW` | `LOGASW#` · `LOGASW,ON#` |
| `MILE` | `MILE#` · `MILE,P1#` |
| `MILEAGE` | `MILEAGE,P1#` · `MILEAGE,100#` |
| `PICRATE` | `PICRATE,P1,P2#` · `PICRATE,2,50#` |
| `PICTIMER` | `PICTIMER#` · `PICTIMER,ON,30,2,10,ON#` |
| `PWDSW` | `PWDSW#` · `PWDSW,ON#` |
| `RECORDAUDIO_SUB` | `RECORDAUDIO_SUB#` · `RECORDAUDIO_SUB,P1,P2#` |
| `RECORDSW_SUB` | `RECORDSW_SUB,P1,P2#` · `RECORDSW_SUB,1,OFF#` |
| `RESET` | `RESET#` — 🔴 destrutivo |
| `RESTART` | `RESTART#` — 🔴 destrutivo |
| `SF` | `SF#` · `SF,OFF#` |
| `SPEEDCHECK` | `SPEEDCHECK#` |
| `SWERVE` | `SWERVE#` |
| `TFMODE` | `TFMODE,2#` |
| `UPDATE` | `UPDATE,P1#` — 🔴 destrutivo |
| `UPLOADSW` | `UPLOADSW#` |
| `VIDEOPARAM` | `VIDEOPARAM#` · `VIDEOPARAM,1,10#` |
| `VIDEORSL` | `VIDEORSL,P1,P2,P3,P4,P5#` |

🔴 **Lista negra**: `REBOOT`, `RESTORE`, `RELAY`, `FORMAT`, `RESET`, `RESTART`,
`UPDATE`. São ação, não pergunta — os quatro últimos estão catalogados (o
operador precisa saber que existem e o que fazem) mas **nenhum recebe forma de
consulta**, mesmo tendo forma nua. `FORMAT#`, `RESET#` e `REBOOT#` existem e
não são perguntas. O invariante está travado em
`tests/helpers/command_response.test.php`, porque no dia em que alguém
regenerar o catálogo da wiki por script essa distinção some sozinha.

## 6. Como o hub se comporta sob rajada (aprendido do jeito difícil)

- **Um comando que estoura os 30 s do hub derruba a sessão JIMI do
  equipamento.** Daí em diante *tudo* volta em ~4 ms convertido em fila
  offline, e a bateria inteira vira ruído. Foi o que o `CHECK#` fez com o
  JC181: 9 respostas boas antes dele, zero depois. **Abortar no primeiro
  silêncio** e relatar parcial.
- **As sessões JT/T (21122) e JIMI (21100) são independentes.** Houve momento
  em que o `33028` do JC181 respondia normalmente e nenhum comando 128 passava.
  "Equipamento online" não é uma pergunta só.
- **O hub serializa por equipamento.** Comando pendurado faz o próximo voltar
  com `_code 302 — Device busy (previous command has not returned)`. Esse já é
  tratado corretamente (`aguardando`), assim como o `600` (fila offline).
- Espaçar os disparos em ~1,2 s. Com 0,4 s o hub devolveu respostas vazias em
  4 ms que pareciam recusa do equipamento e não eram.

## 7. Estado — tudo isto foi feito na v4.9.25

1. ✅ **Forma de consulta no `command_catalog.php`**, com `consulta`,
   `consulta_modelos` e `consulta_ref` (`medido` / `wiki`). 49 comandos.
2. ✅ **`Time Out!` e `Command was not recognized!` classificados como erro.**
   O primeiro ganhou título próprio — "Equipamento não atendeu o comando" —
   para não se confundir com o `request timeout` do gateway, que é outra causa
   e pede outra ação.
3. ✅ **27 comandos cadastrados** (§5); os destrutivos entram marcados e sem
   consulta.
4. ✅ **`COLLIDE` catalogado para o JC181.**
5. ✅ **APN (`16`/`17`/`18`) fora da escrita** — `migration_v4.9.25.sql`.
6. ✅ **Botão "Ler o valor atual"** na tela de comandos, com a procedência
   visível ao lado.

### O que continua em aberto

- **Parser de resposta por modelo.** `SERVER#` tem três formatos
  (`SERVER,IP,PORT,,` / `SERVER,0,IP,PORT,0` / `SERVER,IP,PORT,,0,IMEI,`) e um
  parser posicional único lê o IP do JC181 como `0`. Hoje a resposta é exibida
  como texto, então não há defeito ativo — só não dá para extrair campo dela.
- **Qual memória o modem obedece no APN.** Enquanto não se souber, `16`/`17`/
  `18` ficam somente leitura.
- **Contraparte de consulta do hodômetro** (`128`): não existe forma nua
  documentada para `MILEAGE`.
- **JC450 e JC400D não foram medidos.** A consulta neles está marcada
  `consulta_ref = 'wiki'` — é promessa da página, não medição. O JC400AD saiu
  dessa lista em 20/08/2026: dois equipamentos responderam ao `CHECK#` (§8.2).

## 8. A planilha do JC371 — 18 buracos, e um comando descartado por engano (v4.9.40)

> Levantado em 20/08/2026 contra `docs/JC 371 Command List V1.0.1.xlsx` e medido
> em quatro equipamentos de produção.

A pergunta que abriu isto era outra: **existe na planilha do JC371 um comando de
PARAR o playback?** Não existe. A planilha é toda de configuração e consulta, e
o controle de stream do JC371 vive no binário do JT/T 1078 (`37378`), não no
proNo 128. Mas o cruzamento feito para responder achou 18 sintaxes ausentes do
catálogo, em duas naturezas:

- **sete nomes** que não existiam aqui: `CHECK`, `CHECKVIDEO`, `STATUSVIDEO`,
  `SENSORSET`, `SHUTDOWNTIME`, `VIDEORSL_SUB`, `VIDETIMEZONE`;
- **onze variantes de ARIDADE** — o nome já existia, aquela sintaxe não:
  `KEYFUN,A,B` · `APN,A,B,C,D` · `SERVER,A,B,C,D,E,F` · `BCD,A,B` · `LOG,ALL` ·
  `RECORDAUDIO,A,B` · `RECORDAUDIO_SUB,A,B` · `RATATION,A,B,C,D` ·
  `PICTIMER,A,B,C,D` · `TIMER,A` · `ANGLEREP,A`.

As onze são o buraco que **comparar só o nome-base nunca mostra** — a mesma
classe que escondeu a forma nua do `FILELIST` por meses (v4.9.27). Todas nascem
travadas no JC371: mandar a sintaxe de um campo do `TIMER` para uma JC400 que
espera dois é aceito e mal interpretado, sem erro nenhum.

### 8.1 🔴 Correção: o `CHECK` não era "token solto" — é o comando mais útil

A §5 desta mesma página, escrita na v4.9.25, descartou sete candidatos como
"tokens soltos de tabelas de parâmetros", e **dois deles eram comandos de
verdade**: `CHECK` (A003 da planilha) e `LOG` (A025, `LOG,ALL`). O descarte foi
feito lendo o texto em volta da ocorrência na wiki, onde `CHECK` aparece mesmo
como palavra solta — a wiki não o documenta como comando. A planilha da
fabricante documenta, e o equipamento responde.

É o espelho do erro do `MILE#` na §4: lá eu batizei por coincidência, aqui
descartei por ausência. **Ausência numa fonte não é ausência no protocolo** —
foi preciso a segunda fonte para desfazer.

### 8.2 O `CHECK#` responde em toda a linha JC — medido

Quatro equipamentos, 20/08/2026, produção:

| Equipamento | Modelo | Resposta |
|---|---|---|
| `864993060429173` | JC400AD | `VERSION:KMC28_..._V1.8.0.9_250807.1920; …` |
| `864993060392306` | JC400AD | `VERSION:KMC28_..._V1.8.1.3_250925.1127; …` |
| `865478070003241` | JC371 | `VERSION:C371_..._V1.9.0.2b_260528.0543;…` |
| `869058070151343` | JC182 | `IMEI:…;VERSION:C182_..._V1.2.5.2_260422.0924;…` |

O JC181 estava offline e o comando virou fila — não é recusa. Por isso o
`CHECK#` entrou como **segunda exceção manual de `universal`** no catálogo (a
primeira é o `UPDATE`, v4.9.32): a derivação automática mede a FONTE, e só a
planilha do JC371 o documenta. Sendo LEITURA, o custo de errar o modelo é uma
recusa, não um estrago — é o inverso do `SERVER`.

⚠️ **No JC181 ele é caro, e isso também está medido**: na bateria da v4.9.25 o
`CHECK#` estourou os 30 s do hub e derrubou a sessão JIMI daquele equipamento —
nove respostas boas antes dele, zero depois (§6). A sessão volta sozinha, mas
não se varre a frota com ele em rajada.

⚠️ **O `CHECKVIDEO#` é o contrário e a distinção é o motivo de a trava existir**:
mesma planilha, mesma família, e não vale na linha JC400 — relatado do campo, e
a planilha `JC400 & JC261 Command List V5.0.3` de fato não o lista. Marcá-lo
universal junto com o `CHECK#` só porque os dois começam igual seria o erro que
a trava por modelo existe para impedir.

### 8.3 O que a resposta do `CHECK#` entrega

Cada linha dela já respondeu uma pergunta que custou dias neste projeto:

| Campo | Por que importa |
|---|---|
| `VERSION` | **A MESMA string que o `VERSION#` devolve** — conferido byte a byte nos dois modelos alcançáveis. Por isso `firmware_capture()` passou a aceitar a resposta do `CHECK#`: `/firmwares` compara versão por IGUALDADE, e duas grafias do mesmo firmware fariam a tela acusar "diferente da referência" conforme o comando usado por último |
| `UPLOAD` | O endereço para onde a câmera sobe arquivo — é o que falta conferir na 400D, que aceita o `FILELIST` e nunca sobe a lista |
| `SERVER` | Para onde ela aponta, **com a porta**: `21100` na linha 400, `21122` no JC371/JC182. Copiar a de um modelo para o outro derruba uma câmera que estava funcionando |
| `TIMEZONE` | `-3:00` — o fuso do equipamento, pela boca dele. É a confirmação da convenção que `includes/filelist.php` assume ao ler o carimbo dos nomes dos vídeos |
| `APN` | Quarta fonte independente do APN real, ao lado do `ASETAPN#` da §4 |

O `STATUSVIDEO#` (só JC371) acrescenta o que nenhum outro diz: **`On video` ×
`Camera insertion`** — quais canais estão gravando contra quais câmeras estão
conectadas. Quando a barra do playback vier vazia num canal, é a pergunta que
separa "não gravou" de "não tem câmera".

### 8.4 A leitura de pares descartava chave que não fosse palavra

`command_response_kv()` exigia que a chave fosse `[A-Za-z][A-Za-z0-9 _/.-]{1,28}`,
e **três formatos reais caíam fora, em silêncio** — a linha simplesmente não
aparecia na tela:

- `EVENTSET,AVD:OFF`, `EVENTSET,AEPLD:ON,115,120,10`, `WAKEUP,RTC:0,240` (JC371)
  — a chave tem **vírgula**;
- `[AR9150]:C182_0_3_STD_JM_JC182_V2.1.0.0b_260422.0116` (JC182) — a chave tem
  **colchetes**.

Duas coisas continuam de fora, de propósito: a chave **nunca atravessa um `:`**
(é o que mantém `RSERVICE:rtmp://ip:1936/live` com o rótulo certo apesar dos
três dois-pontos), e o bloco `bootcase[…]` do JC182, que tem quebra de linha no
meio — é despejo de diagnóstico, não par, e fica melhor cru. O preço de aceitar
vírgula na chave é que uma frase comum (`Device busy, previous command: not
returned`) viraria par; uma regra de uma linha o impede — chave com espaço **e**
vírgula é frase, não rótulo.

Tudo isto está travado em `tests/helpers/command_response.test.php`, com as
respostas cruas das três medições como fixture.

## 9. Comandos de AÇÃO (upload de anexo) — "aceito" não é "executado", e o comando certo já tinha sido descoberto antes (25/08/2026)

> Levantado numa sessão inteira em cima da Telecom (JC371, `865478070654829`,
> cliente Frota Principal) depois de o dono do produto reportar que vídeo de
> evento nunca subia pra nenhuma câmera JT/T. Detalhe técnico completo (SQL,
> logs, timestamps) em `STATUS.md`, entrada "ESTADO EM 25/08/2026"; aqui fica
> só o que generaliza — pra não cometer os mesmos quatro erros de novo.

### 9.1 A falha, em uma frase

**O comando escolhido pra pedir upload de anexo de alarme JT/T (`37384`,
0x9208) nunca foi testado contra hardware real, e quando finalmente rodou
(depois de destravar um bug que o impedia de rodar), a câmera ACEITAVA o
pedido — sem nunca de fato subir o arquivo.** O comando certo (`VIDEOUPLOAD`,
texto, proNo 128) já tinha sido implementado e usado numa versão ANTERIOR do
dashboard (`docs/_arquivo_morto/archive/web/dashboard.js`, função
`requestVideoUpload()`) — perdido na reescrita do produto, sem que nada
registrasse "isto aqui é o que funciona, não mexer".

### 9.2 A cadeia de 4 bugs — cada um escondia o próximo

| # | Bug | Por que não apareceu antes |
|---|---|---|
| 1 | `flush_pending_video_requests()` chamava uma função renomeada 13 dias antes (v4.9.13) — `Error` de PHP, não `Exception`, não cai no `catch` | Processo morre em silêncio, pós-resposta HTTP já enviada — zero log, zero sintoma pro usuário |
| 2 | O comando escolhido (`37384`) nunca tinha sido testado — destravar o bug 1 revelou que ele é *aceito* (`_content:"ok"`) mas não *executado* | O "ok" parece sucesso; só desmascarou cruzando com o log do container de upload (zero conexão da câmera, apesar do "ok") |
| 3 | O comando certo (`VIDEOUPLOAD`) tem o `alarmLabel` no nome do arquivo que a câmera sobe — mas o regex que extrai esse nome nunca batia (doc erra o separador) | Todo anexo caía num fallback impreciso (janela ±3min) em vez do casamento exato — funcionava "por sorte" na maioria, errou na primeira vez que dois canais chegaram juntos |
| 4 | O anexo ficava só em `occurrences.media_file_id` — `alarms.file_url`, que é o que a tela de Alarmes lê, nunca era escrito | A tela de Ocorrências mostrava o vídeo (lia a coluna certa); a tela de Alarmes mostrava `—` pro MESMO arquivo (lia a coluna errada) — dois pontos de leitura, um só de escrita |

Nenhum destes quatro apareceu como erro visível — cada um só ficou claro
depois que o anterior foi corrigido e a evidência ficou "estranha demais pra
ignorar" (ex.: vídeo aparecendo numa tela e não na outra).

### 9.3 "Aceito" não é "executado" — um modo de falha novo neste projeto

Toda a disciplina de `command_response_interpret()` (§2 acima) trata de
DISTINGUIR recusa de sucesso pela RESPOSTA. O `37384` é um caso mais
traiçoeiro: a resposta É de sucesso genuíno (`_code:"100"`,
`"Command communication successful response"`, `_content:"ok"`) — só que
"ok" aqui é o **ACK do protocolo recebendo o comando**, não confirmação de
que o efeito pretendido (upload) aconteceu. `VIDEOUPLOAD`, em contraste,
respondeu `"start upload task;"` — uma frase que descreve a AÇÃO, não só o
recebimento; diferença que só apareceu comparando os dois lado a lado.

**Lição que generaliza**: pra comando de AÇÃO (não consulta), o único jeito
confiável de confirmar que funciona é verificar o EFEITO do lado de fora do
comando — aqui, cruzar com o log do serviço que deveria RECEBER o upload
(`docker logs dvr-upload`), não só ler a resposta síncrona do device. Uma
resposta de sucesso sintaticamente válida não é prova de execução.

🔴 **Isto já tinha acontecido antes, com OUTRO comando de ação, e ninguém
cruzou os dois casos até hoje.** `handlers/sendcommand.php` (comentários de
v4.9.1, sessão anterior a esta) documenta exatamente o mesmo sintoma para o
`37382` (FTP upload de gravação, §"Extração de vídeo do cartão" do
`.env.example`): o comando era **aceito pelo device e pelo hub**, sem nunca
produzir arquivo — corrigido só depois de perceber que faltavam as
credenciais de FTP configuradas no servidor. **"Aceito ≠ executado" não é um
acidente pontual do `37384` — é o SEGUNDO caso do mesmo padrão nesta base**,
o que muda a lição de "cuidado com este comando" para "todo comando de ação
neste projeto merece confirmação de efeito, por padrão, antes de ser dado
como resolvido".

### 9.4 EVIDEO/HVIDEO são JIMI; VIDEOUPLOAD é JT/T — não são intercambiáveis

| Comando | Protocolo (`device_models.protocol`) | Modelos confirmados | JC371 |
|---|---|---|---|
| `EVIDEO` | JIMI | JC400AD, JC400D | 🔴 recusado: `Error:Number of parameters errors!` (25/08/2026) |
| `HVIDEO` | JIMI | JC400AD, JC400D | 🔴 recusado: `Command was not recognized!` (25/08/2026) |
| `VIDEOUPLOAD` | JT/T | JC182 (wiki), **JC371 (medido 25/08/2026)** | ✅ aceito, produziu upload real |
| `37384` (0x9208 binário) | JT/T | — | ⚠️ aceito ("ok"), **nunca produziu upload** (medido 25/08/2026) |

A escolha original (`37384`) era plausível pela doc oficial (JT/T 1078,
"Alarm Attachment Upload") — e é exatamente esse tipo de escolha "razoável
pela doc, nunca medida" que já causou os outros defeitos deste arquivo
(`MILE#`, `CHECK`/`LOG` descartados — §4 e §8.1). O padrão se repete: **doc
oficial dá um candidato plausível; só o teste contra hardware real confirma
ou derruba.**

### 9.5 O comando certo já tinha sido implementado — no arquivo morto

`docs/_arquivo_morto/archive/web/dashboard.js` e `dashboard_template.php`
(versão do dashboard anterior à reescrita YUV) já tinham um botão "Solicitar"
por alarme JT/T, chamando exatamente `VIDEOUPLOAD,<host>,<porta>,<alarmLabel
sem vírgula>,1-2-3` — a mesma forma que se mostrou correta hoje, incluindo o
detalhe de que os canais vão com HÍFEN (`1-2-3`), não vírgula. Ninguém tinha
registrado essa implementação como "isto funciona, preservar" em nenhum
lugar que sobrevivesse à reescrita — ela simplesmente não foi portada, e o
conhecimento morreu junto com o código antigo.

**Lição que generaliza, e é o motivo desta seção existir**: antes de
DERIVAR um comando a partir de documentação (planilha, wiki, spec oficial),
**procurar em `docs/_arquivo_morto/` se uma versão anterior do produto já
implementou e usou aquilo contra câmera real** — mesmo código morto pode ser
a fonte de verdade mais barata disponível, mais barata que testar do zero.
O arquivo morto não é só histórico de UI — é registro de comportamento
medido que a reescrita não herdou automaticamente.

### 9.7 Uma fonte de diagnóstico que existe e nunca foi consultada: `iothub_events`

A doc oficial (seção 1.13, ver `docs/_arquivo_morto/API_COVERAGE_v3.0.0.md`)
descreve um endpoint `/pushIothubEvent` que recebe eventos explícitos de
início/fim de upload — `UploadAlarmFileBegin`, `UploadAlarmFileEnd`,
`UploadMediaFileBegin`, `UploadMediaFileEnd`. `handlers/pushiothubevent.php`
existe, roda em produção e grava tudo em `iothub_events` — mas a
investigação de hoje diagnosticou "câmera não subiu o anexo" só pelo log do
container `dvr-upload` (visão de fora, do lado do servidor de upload), sem
nunca consultar essa tabela, que teria a visão do PRÓPRIO PROTOCOLO: ela
distingue "a câmera nunca tentou" de "a câmera tentou e o upload começou mas
não terminou" — dois diagnósticos diferentes, duas correções diferentes.
**Pendência para a próxima vez que um upload falhar**: checar
`iothub_events` (`event_type LIKE 'Upload%'`) para o IMEI/janela em questão
ANTES de ir direto ao log do container — é a fonte mais próxima do
protocolo, já gravada, e ainda não usada nenhuma vez para isto.

### 9.8 Lições que generalizam, resumidas

1. **"Aceito" ≠ "executado"** para comando de ação — confirme o EFEITO
   (arquivo chegou? valor mudou?), não só a resposta síncrona do device.
   Já aconteceu duas vezes nesta base (`37382` em v4.9.1, `37384` hoje) —
   não é acidente pontual, é padrão a vigiar por padrão.
2. **Fila/tabela nova em screen nova**: um valor gravado num ponto
   (`occurrences.media_file_id`) não aparece automaticamente em toda tela
   que deveria mostrá-lo — cada consumidor lê sua própria coluna; conferir
   TODAS as telas que exibem o mesmo dado, não só a primeira que funcionar.
3. **Nome de arquivo/campo que a doc descreve raramente bate com o que o
   device realmente emite** — é o mesmo "doc mente, meça no device" já
   documentado em `CLAUDE.md` pra outros protocolos (proNo 33027/33028,
   `FILELIST`), agora confirmado também pro nome do anexo de upload.
4. **Antes de escrever um comando novo a partir de spec, procurar se uma
   versão anterior do produto (arquivo morto, código comentado, migração
   antiga) já resolveu o mesmo problema contra hardware real.** É mais
   barato achar do que redescobrir. O arquivo morto (`docs/_arquivo_morto/`)
   hoje contém só 4 arquivos de código (o dashboard antigo + doc de
   cobertura de API) — pequeno o bastante para varrer inteiro em minutos
   quando a dúvida surgir de novo.
5. **Um bug que trava a EXECUÇÃO (função inexistente, exceção não
   capturada) pode esconder, por trás de si, um SEGUNDO bug de escolha
   errada** — corrigir só o primeiro e assumir que "agora funciona" sem
   verificar o efeito real deixaria o segundo intocado, "corrigido" só na
   aparência.
6. **O bug de fuso "3 horas de diferença" também já se repetiu** —
   `archive/includes/dashboarddata.php` documenta a mesma causa-raiz
   (`new DateTime($str)` sem timezone explícito lendo string UTC como se
   fosse local) que resurgiu nesta sessão em `alarms.created_at` (achado
   tangencial, não corrigido — ver STATUS.md). Ao investigar qualquer
   horário "estranho por exatas 3h", suspeitar primeiro deste padrão.
7. **Existe uma fonte de diagnóstico de upload já gravada e nunca usada**
   (`iothub_events`, §9.7) — verificar antes de assumir que só o log do
   container conta a história.
