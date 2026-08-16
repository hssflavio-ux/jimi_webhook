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
`LOG` são tokens soltos de tabelas de parâmetros. Sobraram **27**, todos
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
- **JC400, JC400AD e JC450 não foram medidos.** A consulta neles está marcada
  `consulta_ref = 'wiki'` — é promessa da página, não medição.
