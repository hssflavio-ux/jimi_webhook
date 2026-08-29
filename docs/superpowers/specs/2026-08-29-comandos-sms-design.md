# Comandos do protocolo 128 via SMS (Allcance) — design

**Data:** 2026-08-29
**Versão-alvo:** v4.14.0 (subsistema novo)
**Provedor:** Allcance SMS — API de Comunicação Multicanal
**Doc da API:** https://documenter.getpostman.com/view/16985605/2s8YmLuiBX
**Coleção JSON (fonte-de-verdade):** `https://documenter.gw.postman.com/api/collections/16985605/2s8YmLuiBX?segregateAuth=true&versionTag=latest`

---

## 1. Objetivo e escopo

Um **segundo transporte** para os mesmos comandos de texto do protocolo 128 que a
tela `/comandos` já monta. Hoje o único caminho é o IoT Hub (proNo 128 por TCP);
quando a câmera não fala com o Hub — APN errado, `SERVER` apontando para o lugar
errado, equipamento mudo — não há como alcançá-la. O SMS chega pela rede da
operadora, um caminho **independente** do Hub. É um canal de **resgate**.

**Confirmado com hardware/API real (2026-08-29):**
- Login `POST /v2/api/login` com `username = flaviohses@live.com`, `password = bama5960`
  → `200 {"status":"success","token":"<JWT>"}`. Token JWT com validade de **3600 s**
  (`exp - iat`).
- `GET /v2/api/creditos` com `Authorization: Bearer <token>` → `200`, e a conta tem
  o serviço **`SMS TRANSACIONAL`** com saldo (código do serviço = **11**).
- Truncar a parte decimal do crédito conforme a doc ("Desconsiderar caracteres após
  o ponto"): `"10.000"` → `10`.

### O texto do comando é IDÊNTICO ao da plataforma (decisão do dono do produto)

Não há transformação de sintaxe. O que se manda por SMS é a **mesma string** que o
`/comandos` monta para a plataforma (`CMD,A,B#`, separador vírgula). Isso:
- Alinha com a nota oficial das planilhas (JC450: *"Commands can all be delivered
  using any of the following ways: TCP, SMS, or TF card"*, com o mesmo formato de
  vírgula), e **derruba** a forma `CMD#666666#A#B` da wiki Foco na Via como caminho
  de SMS.
- Permite **reusar `includes/command_catalog.php` inteiro**, sem conversor e sem
  catálogo paralelo.

### Fora de escopo
- Não substitui `/comandos`, não altera `sendcommand.php`, não toca no despacho pelo Hub.
- WhatsApp, RCS, Torpedo, Voz da Allcance — ignorados.
- Cron de PULL de relatórios — **pendência documentada** (§9), não implementado nesta fase.

---

## 2. Decisões travadas com o dono do produto (2026-08-29)

| Pergunta | Decisão |
|---|---|
| Destino do SMS | **Estritamente `sim_cards.msisdn`** do chip vinculado à câmera. Sem `msisdn`, o equipamento não pode receber SMS até o cadastro em `/chips` ser corrigido. |
| Conta Allcance | **Uma conta global** da plataforma. Saldo único, custo com a operação. |
| Resposta do equipamento | **Capturar** e gravar como resposta do comando. O SMS vira canal completo, não disparo cego. |
| Catálogo de comandos | **O catálogo inteiro** (`command_catalog.php`), com a mesma trava de modelo do `/comandos`. |

---

## 3. Contrato da API Allcance (medido/extraído da coleção)

Base: `https://painel.allcancesms.com.br/v2/api`

### 3.1 Login — `POST /login`
- Body JSON: `{"username": "...", "password": "..."}`
- `200` → `{"status":"success","token":"<JWT>"}` (Bearer, validade 3600 s)
- `400` → `{"status":"error_validate","message":"dados inválidos"}` (credencial inválida)
- `422` → `{"error":{"username":[...],"password":[...]}}` (campo ausente)
- **Não há refresh** — token novo a cada expiração.

### 3.2 Saldo — `GET /creditos` (Bearer)
- `200` → array `[{"servico":"SMS TRANSACIONAL","credito":"10.000"}, ...]`
- Filtrar `servico === "SMS TRANSACIONAL"`; truncar decimal.

### 3.3 Envio — `POST /campanhas` (Bearer) — **usar Lote Avançado**
Body:
```json
{
  "cod_servico": "11",
  "titulo": "Comando SMS — <IMEI>",
  "referencia": "<referencia_campanha unica>",
  "numeros": [
    { "numero": "37999368807", "texto": "STATUS#", "referencia": "<referencia_numero unica>" }
  ]
}
```
- `201` → `{"status":"success","mensagem":"campanha recebida","referencia_campanha":"..."}`
- `404` → `{"status":"not_found","message":"Serviço não encontrado"}`
- `406` → `{"status":"error_validate_credit","mensagem":"Crédito insuficiente","total_numeros":0,"creditos":0}`
- `406` → `{"status":"error_validate","errors":{...}}`
- `503` → serviço desativado temporariamente

**Por que o Lote Avançado com UM número, e não o Simples:** só o Avançado aceita
`referencia` **por número**, e o webhook devolve `referencia_numero` +
`referencia_campanha`. Precisamos das duas para casar o retorno com a linha certa.

### 3.4 Webhook de retorno (PUSH) — configurado no PAINEL da Allcance
Body `POST` (JSON):
```json
{
  "messages": [
    { "numero":"3799...", "status":"recebido", "mensagem":"texto da resposta",
      "data_envio":"...", "data_entrega":null,
      "referencia_campanha":"...", "referencia_numero":"..." },
    { "numero":"3799...", "status":"entregue celular", "data_entrega":"...",
      "data_envio":"...", "hash":null,
      "referencia_campanha":"...", "referencia_numero":"..." }
  ],
  "total": 8
}
```
- **Só para SMS.** A URL do webhook é cadastrada no painel da Allcance (passo de
  infraestrutura, fora do git — ver §10).
- O webhook é **da conta inteira**, não da nossa aplicação. Se a conta for usada
  para outra coisa, chegam eventos sem referência nossa.

**Status possíveis (minúsculo):** `cancelado`, `duplicado`, `saldo insuficiente`,
`número inválido`, `entregue celular`, `entregue operadora`/`enviado`, `expired`,
`lista negra`, `message_text_invalid`, `não entregue`, `recebido`.

### 3.5 PULL (plano B, NÃO implementado nesta fase)
- `GET /relatorios/campanhas/sms` — status; `GET /relatorios/campanhas/respostas/sms` — respostas.
- Regra: "cada relatório é disponibilizado apenas uma vez por consulta", validade 48 h.
- Risco não medido: rodar PULL + webhook às cegas pode consumir status que o webhook
  entregaria. Fica como pendência (§9).

---

## 4. Arquivos

| Arquivo | Papel |
|---|---|
| `includes/sms_gateway.php` | **Ponto único** de fala com a Allcance: token (cache+renovação), saldo, envio. Nada mais chama a API. |
| `handlers/comandos_sms.php` | Tela `/comandos-sms` |
| `handlers/sendsms.php` | POST do envio (espelha `sendcommand.php`) |
| `handlers/pushsms.php` | Webhook de retorno `/pushsms` |
| `handlers/config_sms.php` | Tela `/config-sms` (credenciais + segredo do webhook + teste) |
| `mysql/migration_v4.14.0.sql` | `sms_settings`, `sms_commands` |
| `tests/comandos_sms.spec.js` | E2E da tela (Playwright) |
| `tests/helpers/sms_webhook.test.php` | script PHP autônomo: normalização msisdn, casamento de referência, separação resposta/status |

Alterações em arquivos existentes:
- `handlers/router.php` — rota `comandos-sms`, `config-sms` em `$screenByHandler`; rotas
  de webhook `pushsms` e ação `sendsms` FORA de `$screenByHandler` (como `filelist`/`sendcommand`).
- `handlers/grupos_permissao.php` — `$screens` ganha `comandos-sms` e `config-sms`.
- `web/layout_base.php` — item de sidebar (ver §7).
- `scripts/deploy.sh` — linha `run_migration "4.14.0" ...`.
- `CHANGELOG.md`, `STATUS.md`.

---

## 5. Modelo de dados (`migration_v4.14.0.sql`)

### 5.1 `sms_settings` — uma linha global (espelha `smtp_settings`)
```
id                bigint PK
customer_id       bigint NULL      -- reservado; nesta fase sempre NULL (conta global)
username          varchar(190)
password_enc      varchar(500)     -- AES-256-GCM via app_encrypt() (includes/crypto.php)
token             text NULL        -- cache do Bearer
token_expires_at  datetime NULL    -- UTC; renova com 5 min de margem
webhook_secret    varchar(64)      -- segredo da URL /pushsms?k=...
cod_servico       smallint DEFAULT 11
is_active         tinyint DEFAULT 1
last_test_at      datetime NULL
last_test_ok      tinyint NULL
last_test_error   varchar(500) NULL
updated_by        bigint NULL
created_at/updated_at timestamps
```
Segredo cifrado **sempre** por `app_encrypt()` — nunca texto puro (precedente de
`smtp_settings.password_enc`).

### 5.2 `sms_commands` — tabela PRÓPRIA (não reusa `commands`)
**Por que tabela separada:** os estados da Allcance (`entregue celular`,
`saldo insuficiente`, `lista negra`, `message_text_invalid`) não cabem no enum
`pending/queued/sent/executed/failed` de `commands` sem tradução com perda; e
`/commandstatus` faz polling em `commands` esperando o ciclo do Hub. Canal
diferente, ciclo diferente, tabela diferente. Custo aceito: histórico SMS não
aparece na aba de comandos de `/ativo_detalhe` nesta fase.

```
id                 bigint PK
referencia         varchar(40) UNIQUE   -- referencia_numero: chave de retorno do webhook
referencia_campanha varchar(40) NULL    -- devolvida no 201 do envio
customer_id        bigint NULL          -- SNAPSHOT via resolve_installation_for_imei()
vehicle_id         bigint NULL          -- SNAPSHOT via resolve_installation_for_imei()
imei               varchar(20)
msisdn             varchar(20)          -- normalizado, como enviado
command_content    text                 -- a string exata (CMD,A,B#)
status_envio       enum('enviado','falha_envio','sem_saldo','sem_msisdn')
api_response       json NULL            -- json_encode() SEMPRE (lição 3140 Invalid JSON text)
status_entrega     varchar(40) NULL     -- texto CRU da Allcance, minúsculo
resposta_texto     text NULL            -- resposta do equipamento (status "recebido" + mensagem)
resposta_em        datetime NULL
operator           varchar(120)
created_at/updated_at timestamps
KEY (imei), KEY (referencia_campanha), KEY (created_at DESC)
```

`customer_id`/`vehicle_id` são **snapshot do dono no momento do envio**, resolvidos
por `resolve_installation_for_imei()` — nunca lidos pelo JOIN em `devices.customer_id`
(regra da Fase 2, v4.12.0).

---

## 6. Fluxo de envio (`sendsms.php` + `sms_gateway.php`)

1. `require_login()`; CSRF; escopo por `report_customer_scope()`.
2. Resolve `msisdn` a partir do chip do IMEI. **Sem `msisdn` → grava
   `sms_commands.status_envio='sem_msisdn'` e devolve erro legível** (não chama a API).
3. **`sms_normalizar_msisdn()`** — função nomeada, com teste próprio, NÃO inline.
   🔴 Ponto frágil: `sim_cards.msisdn` é texto livre; a base real terá
   `+55 37 99936-8807`, `5537999368807`, `(37) 99936-8807`. O exemplo da Allcance é
   `37999368807` (DDD + número, **sem +55**). Formato errado é aceito pela API e
   nunca entregue. A normalização remove não-dígitos e retira o prefixo `55` do país
   quando presente, validando 10–11 dígitos após o DDD.
4. Guardas: `strlen(texto) <= 160` (SMS parte acima disso e o equipamento recebe
   meio comando); trava de modelo herdada do catálogo.
5. Gera **duas** referências únicas, ambas `bin2hex(random_bytes(16))` — **não** o
   `id` da linha (a doc exige unicidade eterna por conta, e banco reinstalado
   reciclaria ids): uma vai no campo `referencia` de topo do body (= `referencia_campanha`)
   e outra no `referencia` do número (= `referencia_numero`, a coluna UNIQUE de
   `sms_commands` e a chave que o webhook casa). Como é 1 número por envio, as duas
   identificam a mesma linha.
6. `sms_gateway_send()` → `POST /campanhas` (Lote Avançado, 1 número, `cod_servico=11`).
7. Grava `sms_commands` com `api_response` (`json_encode`), `status_envio`, e o
   `referencia_campanha` do `201`. Trata `406 error_validate_credit` como
   `status_envio='sem_saldo'` + mensagem legível.

Envio em lote (vários equipamentos) = o frontend chama `/sendsms` uma vez por
equipamento — mesmo padrão do `/comandos` (mantém posse por IMEI, log e registro por linha).

---

## 7. Tela `/comandos-sms` (`comandos_sms.php`)

- Ao abrir, **consulta o saldo na hora** (pedido explícito): `SMS TRANSACIONAL` de
  `/creditos`, truncando decimal, exibido no topo. Saldo indisponível → mostra o erro
  e **não bloqueia** o envio (quem bloqueia é o `406` da API).
- Lista de equipamentos com escopo por `report_customer_scope()` — reusa a consulta
  de `comandos.php`.
- Catálogo e trava de modelo reusados de `comandos.php` (mesmo `$catJs`, sem catálogo paralelo).
- Preview da string exata antes de enviar.
- **Equipamento cujo chip não tem `msisdn` aparece desabilitado com o motivo escrito
  e link para `/chips`** — em vez de sumir da lista sem explicação (modo de falha que
  este projeto mais repetiu).
- Entra em `$screenByHandler` **e** em `$screens` de `grupos_permissao.php`.
- Permissão = mesma faixa de `/comandos` (**não** `admin_only`): é o mesmo ato com
  outro transporte. Item na sidebar ao lado de "Comandos" em `$navBottom`.
- `/config-sms` fica em Cadastros (como `/config-smtp`), e como grava credencial de
  terceiro deve exigir `require_admin()` no handler (não basta a matriz — `can()` é
  permissivo por omissão).

---

## 8. Fluxo de retorno (`pushsms.php`)

- Rota `/pushsms?k=<webhook_secret>`. **NÃO estende `WebhookHandler`**: aquela classe
  exige `WEBHOOK_TOKEN` no corpo e idempotência por MD5 de `data_list`, e o payload da
  Allcance não tem nem um nem outro. Segue o precedente de `filelist.php` (webhook de
  terceiro, sem sessão, defesa dentro do handler).
- Defesa: o segredo `k` na URL comparado com `sms_settings.webhook_secret`
  (`hash_equals`). A Allcance não envia cabeçalho de autenticação.
- Precisa chamar `env_load()` (v4.13.22) se renderizar/consultar antes de tocar no
  banco — toda tela/endpoint novo sem o construtor do Database.
- Para cada item de `messages`, casa por `referencia_numero` → linha de `sms_commands`.
  Distinção que dá valor ao canal:
  - `status === "recebido"` **e `mensagem` não vazia** → **resposta do equipamento**:
    grava `resposta_texto` + `resposta_em`.
  - Qualquer outro → grava `status_entrega` (texto cru, minúsculo).
- **Item sem `referencia_numero` correspondente → log `INFO` e descarta.** Nunca casar
  por número solto: atribuiria a resposta ao comando errado (o webhook é da conta inteira).
- Responde `200` sempre que o corpo for válido (não reprocessa entregas).

---

## 9. Pendências documentadas (não são bugs — são fases futuras)

1. **Cron de PULL** (`/relatorios/campanhas/sms` + `/respostas/sms`) como plano B para
   quando o webhook estiver indisponível. Não medido se PULL consome o que o webhook
   entregaria — implementar só depois de medir, para não fazer status sumir.
2. **Histórico SMS na aba de comandos de `/ativo_detalhe`** — agregação na leitura de
   `sms_commands`, fase posterior.
3. **`sms_settings.customer_id`** existe mas nesta fase é sempre NULL (conta global);
   caminho para conta-por-cliente no futuro sem migração de esquema.

---

## 10. Riscos operacionais (fora do código)

- **Chip M2M costuma não receber SMS** — é contratual da operadora, não técnico. O
  primeiro teste real precisa ser um comando inócuo (`STATUS#`) num equipamento só.
- **A URL do webhook se cadastra no painel da Allcance**, não pela API — passo de
  infraestrutura fora do git (como `docs/apache/filelist-chunked.conf`). Documentar em
  STATUS.md que some se a conta/máquina for reprovisionada.
- **Conta de teste tem 10 créditos** transacionais — suficiente para validar, não para operar.

---

## 11. Verificação

- `php -l` em todos os arquivos novos (espelha `deploy.sh` FASE 4).
- `tests/helpers/sms_webhook.test.php` — script PHP autônomo (como
  `command_response.test.php`): normalização de msisdn (todas as formas reais),
  casamento por referência, separação resposta/status, e a regra "sem referência → descarta".
- `tests/comandos_sms.spec.js` — tela abre; saldo aparece; equipamento sem msisdn
  desabilitado **com motivo**; preview exato; sintaxe não carrega `666666`.
- 🔴 **Migração nova → DOIS deploys** (ou `.sql` à mão logo após o primeiro), pela
  regra do `run_migration` no mesmo `git pull` (CLAUDE.md / STATUS.md v4.13.21).

---

## 12. Ordem de implementação (para o plano)

1. `migration_v4.14.0.sql` + linha no `deploy.sh`.
2. `includes/sms_gateway.php` (token cache, saldo, envio) + `sms_normalizar_msisdn()`.
3. `tests/helpers/sms_webhook.test.php` (TDD da normalização e do casamento).
4. `handlers/config_sms.php` + rota + `$screens` + sidebar + `require_admin()`.
5. `handlers/pushsms.php` + rota.
6. `handlers/comandos_sms.php` + `handlers/sendsms.php` + rotas + `$screens` + sidebar.
7. `tests/comandos_sms.spec.js`.
8. `CHANGELOG.md`, `STATUS.md`, doc de infra do webhook.
