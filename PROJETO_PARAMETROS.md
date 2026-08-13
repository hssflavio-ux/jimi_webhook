# PROJETO PARÂMETROS — Blueprint de Implementação

> **Objetivo**: ler, guardar e (depois) escrever a configuração das câmeras JT/T
> via os comandos `33027` (definir), `33028` (consultar tudo) e `33030`
> (consultar específicos), com leitura automática na primeira conexão de cada
> equipamento, para que o banco tenha sempre o retrato de como cada câmera está
> configurada.
>
> **Fonte oficial**: seções 2.9, 2.10 e 2.11 de
> https://docs.jimicloud.com/integration/integration.html
>
> ⚠️ **Este documento vale mais que a doc oficial em três pontos.** As respostas
> abaixo foram **medidas em câmeras reais do homolog em 12/08/2026**, e a doc
> está errada sobre o nome do campo de contagem, sobre o formato dos parâmetros
> de vídeo e sobre o que o dispositivo devolve. Onde houver conflito, **vale o
> que está medido aqui**. Ver §2.

---

## Sumário

1. [Escopo e restrição de protocolo](#1-escopo-e-restrição-de-protocolo)
2. [O que o device REALMENTE responde (medido)](#2-o-que-o-device-realmente-responde-medido)
3. [O que já existe e está quebrado](#3-o-que-já-existe-e-está-quebrado)
4. [Modelo de dados](#4-modelo-de-dados)
5. [Pipeline de leitura](#5-pipeline-de-leitura)
6. [Leitura na primeira conexão](#6-leitura-na-primeira-conexão)
7. [Telas](#7-telas)
8. [Escrita de parâmetros (fase 3)](#8-escrita-de-parâmetros-fase-3)
9. [Armadilhas](#9-armadilhas)
10. [Fases e verificação](#10-fases-e-verificação)

---

## 1. Escopo e restrição de protocolo

`33027`/`33028`/`33030` são da **seção 2** da doc — **JT/T 808 apenas**
(`msgClass=1`). Câmeras JIMI (`msgClass=0`) não participam deste fluxo, por
ADR-001: cruzar protocolo é proibido no projeto inteiro.

No homolog (12/08/2026), dos 8 equipamentos ativos:

| Protocolo | Modelos | Equipamentos |
|---|---|---|
| **JTT** | JC371, JC450, JC181, JC182 | **5** (4 transmitindo) |
| JIMI | JC400D, JC400AD | 3 — **fora de escopo** |

Toda consulta e toda tela deste projeto filtram por
`device_models.protocol = 'JTT'`. Equipamento JIMI não aparece na aba de
parâmetros, e não como linha vazia: **não aparece**, com uma nota explicando o
porquê — senão o usuário lê ausência de dado como defeito.

---

## 2. O que o device REALMENTE responde (medido)

Três sondas reais contra o hub (`http://10.1.0.43:10088/api/device/sendInstruct`),
em 12/08/2026, câmeras do homolog.

### 2.1 `33028` (todos) — JC371, IMEI `865478070003241`

```json
{"paramCount":"87","paramId":"119","channelCount":3,
 "channel_1":"1,0,0,0,0,0,0,0,0,0,0,0",
 "channel_2":"2,0,0,0,0,0,0,0,0,0,0,0",
 "channel_3":"3,0,0,0,0,0,0,0,0,0,0,0",
 "85":"0","86":"600","87":"0","88":"0","89":"3888000","90":"0","91":"0.0","92":"0",
 "16":"cmnet","17":"usr","18":"pwd","19":"189.22.240.43","20":"","21":"","22":"",
 "23":"","24":"21122","25":"0","1":"60","32":"0","128":"0","129":"0","130":"0",
 "131":"","132":0,"41":"60","93":"0","100":"0","2":"0","3":"0","4":"0","5":"0",
 "6":"0","7":"0","33":"0","34":"0","39":"60","40":"0","44":"0","45":"0","46":"300",
 "47":"0","48":"45","49":"0","82":"0","83":"0"}
```

**612 bytes. 46 chaves numéricas. 3 blocos de canal.**

### 2.2 `33030` (específicos) — mesma câmera, pedindo `44`, `45`, `85`

`cmdContent` enviado: `{"44":"","45":"","85":""}`

```json
{"paramCount":"3","85":"0","44":"0","45":"0"}
```

### 2.3 `33028` — JC181, IMEI `860112070347838`

```json
{"paramCount":"6","1":"180","19":"189.22.240.43","24":"21122",
 "41":"180","48":"45","128":"15"}
```

### 2.4 `33028` — JC182, IMEI `869058070151343`

```json
{"code":0,"msg":"The device is offline or timed out, and the command is
 converted to an offline command",
 "data":{"_code":"600","_imei":"869058070151343","_msg":"request timeout"}}
```

### 2.5 As cinco conclusões que mudam o desenho

🔴 **1. O campo de contagem chama `paramCount`, não `totalNum`.** A doc diz
`totalNum` nos dois exemplos de resposta (2.10 e 2.11). Nenhum device devolveu
esse nome. **Implementar pela doc procuraria um campo que não existe** — e o
código cairia no ramo "resposta sem contagem" para 100% das respostas, calado.

🔴 **2. `paramCount` NÃO é o número de chaves de topo.** No JC371 ele diz `87` e
vêm **46** chaves numéricas (46 + 3 canais + 36 sub-valores + 2 metacampos = 87,
que é a aritmética que fecha, mas depende de o firmware contar do mesmo jeito).
No JC181 (`6` e 6 chaves) e no `33030` (`3` e 3 chaves) ele bate. **Não serve
como "recebi tudo?"** — guardar como informação, nunca como validação.

🔴 **3. Os parâmetros de vídeo NÃO vêm como a chave `119`.** A doc descreve
`"119":"1,0,5,25,..."`. O device manda um **bloco estruturado**: `paramId:"119"`,
`channelCount:3` e `channel_1`/`channel_2`/`channel_3`, cada um com os 12 valores
posicionais da Tabela 2.3.9.2.3. Um parser escrito pela doc **perde a
configuração de vídeo inteira** — que, numa plataforma de câmera, é o que mais
importa.

⚠️ **4. Metade dos parâmetros não está na Tabela 2.3.9.1.** Dos 46 do JC371,
**20 não são documentados**: `2,3,4,5,6,7,16,17,18,20,21,22,46,82,83,100,128,
129,130,131`. Três deles são identificáveis pelo valor — `16`=`cmnet` (APN),
`17`=`usr`, `18`=`pwd`. O catálogo **tem de degradar** para `Parâmetro 128` em
vez de esconder a linha, exatamente como `Código NNNN (JTT)` faz com alarme fora
do catálogo.

⚠️ **5. Cada modelo devolve um conjunto radicalmente diferente.** JC371: 46
parâmetros + 3 canais. JC181: **6**. Não é falha do JC181 — é firmware
diferente. **Perfil de configuração tem de ser por `device_model`**, com
sobreposição por cliente; um perfil único por cliente compararia JC181 com JC371
e acusaria 40 divergências falsas.

### 2.6 Observações de campo

- `132` volta como **número** (`0`), todo o resto como string. `91` volta como
  `"0.0"` — decimal em string. O parser não pode assumir tipo.
- `94` (ângulo de capotamento, ligado ao alarme `1047` da v4.9.10) **é
  documentado e o JC371 não o devolveu**. Reforça a conclusão 2: ausência não é
  "desconfigurado".
- `19` = `189.22.240.43` e `24` = `21122` confirmam a doc: endereço do servidor
  principal e porta do gateway JT/T.
- `85` (velocidade máxima) = **0** no JC371: sem limite configurado. É
  exatamente o tipo de achado que justifica o projeto — hoje não há como
  perguntar isso à frota.
- **`_code: "600"` = virou comando offline.** O JC182 tinha
  `last_communication` de segundos antes e mesmo assim não aceitou comando.
  **Frescor de `last_communication` não significa que o device aceita comando** —
  o worker (§6) tem de tratar isso como caso normal, não como erro.

---

## 3. O que já existe e está quebrado

### 3.1 As telas mandam o `cmdContent` errado

| Onde | Manda hoje | Doc / medido |
|---|---|---|
| `config.php:137`, `ativo_detalhe.php:572` (33027) | `{"paramId":1,"paramValue":"x"}` | `{"1":"x"}` |
| `config.php:116`, `comandos.php:98` (33028) | `{}` | vazio |
| `config.php:120` (33030) | `{"paramIds":[44,45]}` | `{"44":"","45":""}` |
| `equipamentos.php:673` (33027) | `{"firmware_url":"…"}` | não é parâmetro nenhum |

⚠️ **Assimetria que derruba quem for rápido**: `33027` recebe **objeto**
(`{"1":"3"}`); `33030` recebe **objeto com valores vazios**
(`{"44":"","45":""}`). Confirmado na sonda §2.2.

### 3.2 A resposta síncrona já chega inteira — e é ignorada

`sendcommand.php:335` já lê `data._content`, marca `executed` e grava o
`rawResp` **completo** em `commands.response_payload` (coluna JSON). Para device
online, **o JSON de parâmetros já está no banco hoje**; falta apenas quem o leia.
`api_type` já grava `jtt_33028`, então dá para saber qual proNo respondeu sem
coluna nova.

### 3.3 🔴 O caminho offline TRUNCA o payload — e já está perdendo dado

`pushinstructresponse.php:62` faz `substr($content, 0, 250)` para dentro de
`command_responses.command_content`, que é `varchar(250)`.

**Prova de que não é hipótese**: a linha `id=14` do homolog tem
`LENGTH(command_content) = 250` **exato** — uma resposta de VERSION do JC371
cortada no limite. Com 612 bytes do JC371, **60% da configuração se perde**, e o
que sobra é um JSON sintaticamente quebrado.

E o caminho offline **não é exceção**: o JC182 caiu nele na primeira sonda.

### 3.4 🔴 `/config` está fora dos DOIS mapas de permissão

`config.php` está em `$simpleRoutes` (`router.php:85`) e **não está** em
`$screenByHandler` nem em `$screens` (`grupos_permissao.php`). É a **quinta**
ocorrência da armadilha documentada no `CLAUDE.md` — depois de `checklist`,
`wiki`, `config-notificacoes` e `config-smtp`.

Consequência hoje: **qualquer usuário logado**, de qualquer grupo, abre a tela
que reconfigura câmera. O `sendcommand.php` barra cross-tenant (R02), mas não
barra papel. Entra como chave `config-parametros` nos dois lugares, na F1.

---

## 4. Modelo de dados

Três tabelas, cada uma com um papel distinto. A separação não é preciosismo: o
erro do pipeline atual foi **não guardar o payload bruto**, e é dele que se
recupera quando o parser estiver errado.

### 4.1 `device_param_catalog` — o dicionário

```sql
CREATE TABLE device_param_catalog (
  param_no      SMALLINT UNSIGNED NOT NULL,
  name_pt       VARCHAR(120) NOT NULL,
  name_en       VARCHAR(120) NULL,
  unit          VARCHAR(20)  NULL,          -- s, m, km/h, graus, kbps
  value_kind    ENUM('int','decimal','ip','port','text','enum','bitmask','csv')
                NOT NULL DEFAULT 'text',
  enum_json     JSON NULL,                  -- {"0":"CBR","1":"VBR","2":"ABR"}
  grupo         VARCHAR(40) NOT NULL,       -- rede, reporte, conducao, video, seguranca
  writable      TINYINT(1) NOT NULL DEFAULT 0,
  is_secret     TINYINT(1) NOT NULL DEFAULT 0,  -- mascarar na tela (17, 18)
  doc_ref       VARCHAR(40) NULL,           -- '2.3.9.1'
  PRIMARY KEY (param_no)
);
```

> ⚠️ **Junção sempre por `param_no`.** É a mesma armadilha que o `CLAUDE.md`
> documenta três vezes para `alarm_types`: junção por NOME morre em silêncio
> quando alguém renomeia o rótulo. Nenhuma tabela deste projeto guarda
> `name_pt` como chave.

Semeado por migração com os 29 da Tabela 2.3.9.1 **mais** os 20 medidos e não
documentados (§2.5.4), estes com `name_pt = 'Parâmetro NNN'` e
`doc_ref = NULL` — nome provisório e honesto, na linha do `Código NNNN (JTT)`.
`16`/`17`/`18` entram nomeados (APN, usuário, senha) com `is_secret = 1` nos
dois últimos.

### 4.2 `device_params` — o estado atual

```sql
CREATE TABLE device_params (
  imei          VARCHAR(20) NOT NULL,
  param_no      SMALLINT UNSIGNED NOT NULL,
  channel       TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = global; 1..N = canal
  value_raw     VARCHAR(255) NOT NULL,
  value_json    JSON NULL,        -- CSV de canal expandido e nomeado
  read_at       DATETIME NOT NULL,
  source        VARCHAR(16) NOT NULL,   -- '33028' | '33030' | '33027-echo'
  desired_value VARCHAR(255) NULL,
  applied_at    DATETIME NULL,
  PRIMARY KEY (imei, param_no, channel),
  KEY idx_dp_param (param_no),
  KEY idx_dp_read (read_at)
);
```

`channel` na chave primária é o que resolve o bloco `channel_N` (§2.5.3) sem
tabela extra: o CSV de 12 posições do canal 2 vira
`(imei, 119, 2)` com `value_raw` = o CSV e `value_json` com os 12 campos
nomeados (resolução, bitrate, keyframe, OSD…).

### 4.3 `device_param_snapshots` — a verdade bruta

```sql
CREATE TABLE device_param_snapshots (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  imei          VARCHAR(20) NOT NULL,
  pro_no        SMALLINT UNSIGNED NOT NULL,     -- 33028 | 33030
  content_raw   TEXT NOT NULL,                  -- `_content` INTEIRO
  param_count   INT NULL,                       -- o que o device declarou
  parsed_count  INT NOT NULL,                   -- o que o parser extraiu
  command_id    BIGINT UNSIGNED NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dps_imei (imei, created_at DESC)
);
```

Append-only. `param_count` **e** `parsed_count` lado a lado: divergir é o
normal (§2.5.2), não é erro — mas some do radar se só um for guardado.

### 4.4 Alterações em tabelas existentes

```sql
ALTER TABLE devices
  ADD COLUMN params_synced_at   DATETIME NULL,
  ADD COLUMN params_sync_tries  SMALLINT NOT NULL DEFAULT 0,
  ADD COLUMN params_sync_next   DATETIME NULL;

ALTER TABLE commands
  ADD COLUMN pro_no SMALLINT UNSIGNED NULL;   -- hoje só existe em api_type='jtt_33028'

-- 🔴 O conserto do truncamento (§3.3)
ALTER TABLE command_responses
  MODIFY COLUMN command_content TEXT NULL;
```

---

## 5. Pipeline de leitura

**Um parser só, dois caminhos** — a lição do `alarm_label_sql()`, que só virou
ponto único depois de o `worker.php` divergir por meses.

`includes/device_params.php`:

| Função | Papel |
|---|---|
| `parse_param_content(string $content): array` | `_content` → `['params'=>[[no,channel,raw]], 'param_count'=>int]`. Trata `channel_N`, valor numérico (`132`) e decimal (`91`). |
| `upsert_device_params(PDO, string $imei, array, string $source): int` | Grava snapshot + `INSERT … ON DUPLICATE KEY UPDATE` em `device_params`. **Nunca apaga o ausente.** |
| `param_label(int $no): string` | Rótulo do catálogo, ou `Parâmetro NNN`. |
| `param_format(int $no, string $raw): string` | Valor formatado por `value_kind` + `enum_json` + `unit`. |

Chamado de dois lugares:

1. **`sendcommand.php`** — quando `proNo ∈ {33028, 33030}` e veio `_content`
   síncrono (§3.2). É o caminho do device online, e hoje já tem o dado.
2. **`pushinstructresponse.php`** — no callback offline, **depois** de alargar a
   coluna. A correlação já existente casa a linha de `commands`, e daí sai o
   `pro_no`.

⚠️ A correlação por `_content` de `pushinstructresponse.php:123` **não casa**
para estes comandos: o pedido do 33028 é vazio e a resposta é o JSON inteiro.
Ele cai no ramo "mais recente pendente do IMEI", que é aceitável — mas o
`pro_no` novo (§4.4) deixa de depender disso.

---

## 6. Leitura na primeira conexão

**Não existe webhook de "device conectou"** — `pushiothubevent` é evento de
upload de arquivo. Deriva-se de `devices.params_synced_at IS NULL`.

🔴 **O disparo NÃO pode morar dentro do webhook.** O handler já devolveu 200 e
processa em background; abrir chamada HTTP ao IoT Hub ali acopla o tráfego dos
devices à disponibilidade do hub e vira tempestade quando uma frota reconecta
junto. Vai para cron, no padrão de `geocode_worker` / `trip_builder`:

`scripts/param_sync_worker.php`, a cada 5 min:

```
SELECT devices JT/T ativos
 WHERE (params_synced_at IS NULL OR params_synced_at < NOW() - INTERVAL 30 DAY)
   AND (params_sync_next IS NULL OR params_sync_next <= NOW())
 LIMIT 20                       -- teto por rodada: nunca varrer a frota de uma vez
```

Para cada um: dispara `33028` com `offLineFlag=1`. Depois:

| Resposta | Ação |
|---|---|
| `_code:100` + `_content` | parseia, grava, `params_synced_at = NOW()`, zera tries |
| `_code:600` (virou offline) | **não é erro** (§2.6) — espera o callback; `tries++`, `next = NOW() + 2^tries horas` (teto 24h) |
| `Device busy` | `tries++`, backoff curto (15 min). Reenviar na hora recebe a mesma recusa — observado no homolog |
| falha de rede | `tries++`, backoff |

Depois de 5 tentativas sem sucesso, para e marca para a tela mostrar
"não foi possível ler" — em vez de tentar para sempre em silêncio.

---

## 7. Telas

### 7.1 Aba `Parâmetros` em `/ativos/{imei}`

Décima aba, só para equipamento JT/T. Agrupada por `grupo` do catálogo, com
`param_no` em JetBrains Mono (é número, DESIGN.md), rótulo em pt-BR, valor
formatado, unidade, e quando foi lido. Bloco de vídeo por canal, em tabela
própria. Botão **Reler agora** (33028) e **Reler estes** (33030) na seleção.

Parâmetro sem catálogo aparece como `Parâmetro 128` com o valor cru — visível,
não escondido. `is_secret` mostra `••••` com revelação só para admin.

### 7.2 Relatório de frota — "fora do padrão"

O que o projeto entrega de valor: compara cada câmera com o perfil do **modelo**
(§2.5.5) e lista as divergências. É a resposta para *"quais câmeras estão sem
limite de velocidade?"* — que hoje não tem como ser feita, e cuja resposta no
JC371 sondado seria: essa está (`85 = 0`).

### 7.3 Permissão

Chave `config-parametros` em **`$screenByHandler` (router) E `$screens`
(grupos_permissao)** — os dois, sempre (§3.4). A F1 aproveita e corrige
`config.php`, que está fora dos dois.

---

## 8. Escrita de parâmetros (fase 3)

`33027`, `cmdContent` como **objeto** `{"1":"60","85":"110"}`.

**Só o diff.** Reenviar os 46 parâmetros para mudar um é pedir para um valor
correto ser sobrescrito por um valor velho de perfil.

### 8.1 Consequência assumida sobre os parâmetros de rede

Os parâmetros `19` (servidor principal), `23` (backup), `24`/`25` (portas) e
`16`/`17`/`18` (APN) controlam **como a câmera chega ao sistema**. Valor errado
e ela some da plataforma: sem log, sem alarme, sem caminho de volta pela rede.

**Decisão do dono do produto (12/08/2026): risco aceito** — a câmera continua
alcançável por **SMS**, que é o caminho de recuperação. Portanto **não haverá
bloqueio** desses parâmetros.

O que fica, porque custa pouco e a consequência é assimétrica:

- `writable = 1` explícito no catálogo (nada é gravável por omissão);
- confirmação em dois passos nesses sete parâmetros, dizendo **na tela** que a
  recuperação é por SMS — para quem executa saber o que está aceitando;
- `desired_value` + `applied_at` gravados **antes** do envio, para que o valor
  anterior fique registrado e a recuperação por SMS saiba para onde voltar.

Esse último item é o que transforma "risco aceito" em "risco gerenciável": sem o
valor anterior gravado, o SMS de recuperação não tem para onde apontar.

---

## 9. Armadilhas

| # | Armadilha | Sintoma se ignorada |
|---|---|---|
| 1 | Campo é `paramCount`, não `totalNum` (§2.5.1) | Contagem sempre nula, calado |
| 2 | `paramCount` ≠ nº de chaves (§2.5.2) | "Leitura incompleta" falso em toda leitura |
| 3 | Vídeo vem em `channel_N`, não em `119` (§2.5.3) | Configuração de vídeo **inteira** perdida |
| 4 | 20 de 46 parâmetros fora da doc (§2.5.4) | Metade da configuração invisível na tela |
| 5 | Cada modelo devolve conjunto diferente (§2.5.5) | Perfil por cliente acusa dezenas de divergências falsas |
| 6 | `command_content` truncado em 250 (§3.3) | JSON quebrado; **já acontece hoje** |
| 7 | 33027 = objeto, 33030 = objeto com valores vazios (§3.1) | Comando aceito pelo hub e ignorado pelo device |
| 8 | `last_communication` fresco ≠ device aceita comando (§2.6) | Worker trata caso normal como erro e desiste |
| 9 | Ausência de parâmetro ≠ desconfigurado (§2.6) | `DELETE` do ausente apaga configuração real |
| 10 | `serverFlagId` é seletor de gateway (0=JT/T), não correlação | Correlação construída sobre campo não único |
| 11 | `/config` fora dos dois mapas (§3.4) | Operador reconfigura câmera de outro papel |
| 12 | `17`/`18` são credenciais de APN | Senha de rede visível a qualquer operador |

---

## 10. Fases e verificação

| Fase | Entrega | Risco | Estado |
|---|---|---|---|
| **F1** | Catálogo + 3 tabelas + parser + `cmdContent` correto em 33028/33030 + captura síncrona + **destruncar** + aba Parâmetros + permissão | Baixo — **só leitura** | ✅ **v4.9.11 + v4.9.12**, verificada em câmera real |
| **F2** | `param_sync_worker.php` + backoff + relatório "fora do padrão" | Médio — tráfego ao hub | ✅ **v4.9.13**, worker rodado na frota real |
| **F3** | Escrita 33027, diff-only, perfis por modelo, `desired_value` | Alto — mexe em câmera viva | pendente |

### O que a F1 achou depois de escrito este blueprint

Quatro defeitos que **só o teste ponta a ponta em câmera real** revelou, e que
nenhuma leitura de código teria pego:

1. **`33028` era recusado com HTTP 400** três linhas antes do bloco que existe
   para montar seu `cmdContent` — que é vazio por especificação.
2. **Um `33030` marcava o device como sincronizado** com 3 de 46 parâmetros,
   fazendo o worker da F2 parar de buscar o resto antes mesmo de existir.
3. **A migração se derrubava sozinha**: `LIKE 'jtt\_%'` perde a barra dentro de
   string do MySQL e o `_` vira coringa.
4. **Uma conferência com falso positivo**: `name_pt LIKE 'Parâmetro %'` acusava
   o `93` (*Parâmetro de Colisão*), que é documentado e gravável. Invariante boa
   é sobre a **procedência** do dado, não sobre como o rótulo começa.

Some-se o quinto, pego pelo teste unitário: `is_int($k)` não distingue lista de
mapa, porque o PHP converte a chave `'85'` para inteiro sozinho.

**A lição para a F2 e a F3**: a sonda contra equipamento real (ssh + `curl` no
hub, §2) é barata e achou em minutos o que a revisão não achou. Usá-la antes de
declarar pronto, não depois.

**F1 dá para verificar de verdade**, e isso é raro neste repo: há **4 câmeras
JT/T transmitindo** no homolog, e as sondas da §2 já provaram o caminho síncrono
ponta a ponta. Diferente do M.2.5 (despacho para veículo real, bloqueado), este
fluxo não depende de nada indisponível.

Verificação da F1:
- parser contra as **três respostas reais** da §2, fixadas como fixture — inclui
  o JC181 de 6 parâmetros e o bloco de canais do JC371;
- `33028` real numa câmera online → linha em `device_params` com 46+3 entradas;
- `33028` real numa câmera **offline** (JC182) → callback chega, `_content`
  inteiro em `device_param_snapshots`, `LENGTH > 250` provando o destruncamento;
- `php -l` + a suíte Playwright com a aba nova.

### O que NÃO entra

- Câmeras JIMI (§1).
- `33029` (reiniciar terminal) e `33031` (info) — já existem e não são
  parâmetro.
- Perfis por cliente antes de perfis por modelo (§2.5.5).
