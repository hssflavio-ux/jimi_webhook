# Plano — Publicação e validação do envio agendado (pós-v4.7.0)

**Origem**: fim da série `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md` (Fases 1–4 implementadas).
**Base**: v4.7.0 no working tree; homolog em `92725cb` / banco `4.4.1`.
**Data**: 30/07/2026.

> ## ✅ PLANO CONCLUÍDO — 01/08/2026
>
> | Bloco | Estado |
> |---|---|
> | **1 — Publicar** | ✅ **feito** (30/07). Homolog em `a1a879b` / banco **4.7.0**, `/ping` concordando, 9 tabelas criadas, **7 workers no cron**, backfill do `state_builder` rodado, SMTP global cadastrado e testado. |
> | **2 — Validar o envio agendado** | ✅ **CONCLUÍDO em 01/08/2026.** 39 asserções automatizadas (0 falhas) + **confirmação do usuário de que o e-mail chegou**. Detalhe abaixo. |
> | **3 — Decidir sobre download e retenção** | ✅ **decidido e implementado na v4.7.1** — ver abaixo. |
> | **4 — Entregabilidade** | ✅ **não se aplica**: o item 6 (spam) não falhou. O `mailer.php` segue **sem assinar DKIM** — se um dia cair em spam, a decisão registrada é trocar por API HTTP transacional, não implementar DKIM artesanal. |
>
> ### Bloco 2 — como foi fechado
>
> **Automatizado** (3 roteiros rodados no servidor como root, reproduzindo o cron):
> itens **1, 2, 3, 7, 8, 9, 12 e 13** do §2.2 — agendamento criado pela tela com os 3
> destinatários; `next_run_at` gravado em `2026-08-02 10:00 UTC` = `07:00 BRT`; disparo forçado
> percorrendo dispatcher → job → worker; caminho do link com `"link":true` e URL absoluta a
> partir de `APP_URL`; relatório vazio nos **dois** modos de `skip_if_empty`; arquivo `0644 root`
> legível pelo `www-data`; e 22:00 BRT caindo em **01:00 UTC do dia seguinte**.
>
> **Confirmado pelo usuário** (o que exigia caixa de entrada real): itens **4, 5 e 6** — o teste
> de e-mail passou. Com isso o Bloco 4 deixa de ser necessário.
>
> **Achados que o plano não previa**, ambos corrigidos na v4.7.2:
> 1. **`php-zip` nunca esteve instalado no homolog** — XLSX é o formato padrão, então nenhuma
>    exportação nesse formato jamais funcionou lá, e falhava em silêncio (o fatal mata o processo
>    antes de qualquer `UPDATE`: job preso em `processando`, execução em `enfileirado`, histórico
>    sem erro). O `deploy.sh` passou a checar `zip` e `openssl`.
> 2. **`APP_URL` ausente do `.env`** — exatamente o risco que o §2.1 chamava de "o mais silencioso
>    de todos", e estava mesmo quebrado. Corrigida no servidor; o worker agora **aborta** a entrega
>    por link em vez de enviar href relativo.
>
> ### O que o Bloco 3 virou
>
> **§3.1 — download sem autenticação → opção B (token no nome), com C como alvo.**
> A hipótese foi **confirmada no servidor** antes da correção, exatamente como o próprio plano
> mandava medir:
> ```
> $ echo PROBE-ACESSO-PUBLICO > /var/www/jimi_webhook/storage/reports/probe_test.txt
> $ curl -s -o /dev/null -w '%{http_code}' http://localhost/storage/reports/probe_test.txt
> 200          # e o corpo devolveu "PROBE-ACESSO-PUBLICO"
> ```
> A correção está em `scripts/worker.php`: o nome do arquivo passa a levar 32 hex de
> `random_bytes(16)` (relatórios **e** vídeos), o que elimina a enumeração por `job_id` sequencial.
> A **opção A foi descartada** porque quebraria o link do e-mail — o caminho que a Fase 4 criou de
> propósito para o anexo grande. A **opção C (URL assinada com validade) continua sendo o alvo** e
> fica registrada como pendente: o token impede adivinhar, mas não protege link vazado ou
> encaminhado. `storage/.htaccess` com `Options -Indexes` entrou junto, como o plano previa em
> qualquer cenário, mais a negação de execução de script no diretório.
>
> **§3.2 — retenção → implementada.** `REPORT_RETENTION_DAYS` (padrão 30) no
> `scripts/log_cleanup.php`, purgando `storage/reports` **e** `report_schedule_runs`; `0` desliga.
> `/exportar` mostra **"Expirado"** no lugar do botão quando o arquivo já foi purgado, em vez de um
> link para 404. `storage/media` ficou de fora: vídeo de ocorrência é evidência vinculada a uma
> tratativa, não subproduto de consulta.
>
> ### Ajuste ao item 8 do roteiro
> O item 8 ("o link baixa de fora") continua valendo, mas a resposta esperada mudou: o link **deve**
> baixar sem login — é o desenho — e o que protege o arquivo agora é o nome imprevisível. O que o
> teste precisa confirmar é que o **endereço antigo, previsível, não existe mais**.

## Por que este plano existe

O SMTP **já está cadastrado e testado no homolog** — o botão "Enviar e-mail de teste" de
`/config-smtp` conversa com o provedor real. Mas isso valida **um** caminho: a conexão SMTP a
partir de uma requisição web.

O envio agendado é outro caminho, e nenhuma parte dele foi exercitada contra um provedor de
verdade:

```
cron (root)  →  schedule_dispatcher.php  →  jobs  →  worker.php  →  send_mail() com ANEXO
                     ↑ fuso do SO              ↑ FPM não está no meio    ↑ ou LINK, se grande
```

Localmente tudo isso foi verificado contra um **SMTP de captura** (71 asserções, incluindo o
`.eml` inspecionado byte a byte). O que um SMTP de captura **não** revela:

| O que só aparece com provedor real | Por quê |
|---|---|
| Anexo XLSX recusado ou renomeado | Provedor aplica limite próprio, antivírus e política de extensão |
| Anexo que chega corrompido | Algum ponto do caminho quebra linha do base64 ou reescreve o MIME |
| E-mail classificado como spam | Sem SPF/DKIM/DMARC para o domínio remetente — o `mailer.php` **não assina DKIM** |
| `APP_URL` errada | O link do relatório grande fica quebrado, e **nada aparece no log**: o envio foi bem-sucedido |
| Fuso do SO do servidor | O cron dispara pelo relógio do SO; o app calcula em UTC |
| Permissão do arquivo gerado pelo cron | O worker roda como **root**; o Apache serve como **www-data** |

---

## Bloco 1 — Publicar (pré-requisito)

Os passos já estão em `STATUS.md` (seção RETOMAR AQUI) e não se repetem aqui. Em resumo:
commitar a v4.7.0 → `sudo ./scripts/deploy.sh` (aplica v4.5.0, v4.6.0 e v4.7.0 pelo gate
semântico) → `bash scripts/crontab-setup.sh --install` (**7 workers**) →
`php scripts/state_builder.php 30` → liberar "Geocercas" e "Agendamentos" no RBAC.

**Conferir antes de seguir para o Bloco 2:**

```bash
# .env do servidor
grep -E '^(APP_URL|APP_KEY|SYSTEM_VERSION|MAIL_MAX_ATTACH_MB|NOTIFY_ENABLED)=' .env

# fuso do SO x fuso do PHP x fuso da conexão MySQL
date; timedatectl 2>/dev/null | head -3
php -r 'echo "PHP TZ: ".date_default_timezone_get()." | agora: ".date("Y-m-d H:i:s")."\n";'
mysql --defaults-extra-file=... -e "SELECT @@global.time_zone, @@session.time_zone, NOW(), UTC_TIMESTAMP();"

crontab -l | grep -c scripts/    # esperado: 7
```

> **`APP_URL` é o item mais fácil de esquecer e o mais silencioso de todos.** Ela não afeta o
> envio: o e-mail sai, o provedor aceita, o histórico marca "enviado" — e o destinatário recebe um
> link que dá 404. Conferir explicitamente que `APP_URL` é a URL pela qual o usuário realmente
> acessa o sistema (com esquema e sem barra final).

---

## Bloco 2 — Validar o envio agendado

### 2.1 O problema de não poder esperar um dia

A frequência mínima é diária. Esperar 24 h por ciclo de teste é inviável. Duas formas de forçar,
**sem** alterar código:

```sql
-- Vence o agendamento agora: o próximo cron (minuto 5) o pega
UPDATE report_schedules SET next_run_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ?;
```

```bash
# Ou dispara na mão, sem esperar o cron (mesma coisa que o cron faz)
php scripts/schedule_dispatcher.php   # enfileira
php scripts/worker.php                # gera e envia
```

Rodar `--dry` primeiro (`php scripts/schedule_dispatcher.php --dry`) mostra o que ele faria e o
`next_run_at` que calcularia, sem gravar nada.

> **O período coberto continua sendo "ontem"** mesmo forçando o disparo hoje — é o desenho, não um
> defeito. Para o teste render um relatório **não vazio**, escolher um tipo que tenha dado de
> ontem no homolog (`alarms` e `positions` têm; `speeding` e `stops` só depois do backfill do
> `state_builder`).

### 2.2 Roteiro

| # | O que | Como | Esperado |
|---|---|---|---|
| 1 | Agendamento diário, XLSX, para um e-mail real | `/agendamentos` | Grade mostra "Todo dia às HH:00 (BRT)" e `Próximo envio` em BRT |
| 2 | `next_run_at` gravado em UTC | `SELECT send_hour, next_run_at FROM report_schedules` | `next_run_at` = `send_hour` BRT + 3 h |
| 3 | Disparo | forçar por SQL e esperar o cron | Execução em "Na fila" → "Enviado" em ≤ 1 min |
| 4 | **E-mail chega** | caixa de entrada real | Assunto com nome + período; anexo `.xlsx` |
| 5 | **Abre no Excel pt-BR** | abrir o anexo | Colunas separadas, acentuação correta, números como número |
| 6 | Não caiu em spam | conferir lixeira/spam | Se caiu: item de SPF/DKIM no Bloco 4 |
| 7 | Caminho do link | `MAIL_MAX_ATTACH_MB=0.01` no `.env`, repetir | E-mail sem anexo, com botão "Baixar relatório" |
| 8 | **O link baixa de fora** | abrir o link em janela anônima / outra rede | Download começa — e ver o Bloco 3, porque baixar **sem login** é o problema |
| 9 | Relatório vazio | agendamento de tipo sem dado no período | Envia com "Nenhum registro"; com `skip_if_empty`, status `vazio` e nada sai |
| 10 | 3 falhas → desativa | destinatário em domínio inexistente, forçar 3 ciclos | `fail_count` 1→2→3, `is_active=0`, sino notifica o criador |
| 11 | Reativar zera | botão "Ativar" | `fail_count=0` e `next_run_at` recalculado |
| 12 | Permissão do arquivo | `ls -l storage/reports/` após o cron | Legível pelo www-data (o cron roda como root) |
| 13 | Fuso na virada | agendamento às **22:00 BRT** | `next_run_at` cai em **01:00 UTC do dia seguinte** |
| 14 | Escopo | usuário do cliente B | Não vê agendamento nem histórico do cliente A |

### 2.3 Onde olhar quando falhar

```bash
tail -f logs/schedule.log       # dispatcher (o que venceu, o que foi reivindicado)
tail -f logs/worker.log         # geração + envio
tail -f logs/webhook_$(date +%F).log | grep -i mailer   # erro real do SMTP
```

E, no banco, a fonte da verdade do que aconteceu:

```sql
SELECT r.executed_at, s.name, r.status, r.row_count, r.error_message
FROM report_schedule_runs r JOIN report_schedules s ON s.id = r.schedule_id
ORDER BY r.executed_at DESC LIMIT 20;
```

---

## Bloco 3 — ⚠️ Dois problemas que a v4.7.0 agrava e que precisam de decisão

Os dois são **pré-existentes** (nasceram com `/exportar` na v4.0.0), mas o agendamento aumenta a
exposição de ambos, porque agora esses arquivos são **enviados por e-mail** em vez de só existirem
atrás de um login.

### 3.1 Segurança — o relatório é baixável sem autenticação, e o nome é previsível

`.htaccess` só reescreve o que **não** é arquivo existente (`RewriteCond %{REQUEST_FILENAME} !-f`),
e **não há `.htaccess` em `storage/`**. Logo `storage/reports/<arquivo>.xlsx` é servido pelo
Apache como estático, **sem passar por `require_login()`**.

O nome é `report_<job_id>_<YYYYmmdd_HHMMSS>.<ext>` — `job_id` é sequencial e o timestamp tem
granularidade de segundo. **É enumerável.** Num sistema multi-tenant, isso significa que o
relatório de posições ou de alarmes de um cliente pode ser baixado por quem não é dele e não está
logado.

Com o agendamento, esses links passam a viajar por servidores de e-mail de terceiros e a ficar
parados em caixas de entrada — a URL deixa de ser um detalhe interno.

**Opções (decidir antes de considerar a fase pronta):**

| Opção | Como | Custo | Observação |
|---|---|---|---|
| **A. Rota autenticada** | `/exportar/download?job=N` com `require_login()` + escopo por `customer_id`; `storage/` bloqueado no Apache | médio | Mais correto. **Quebra o link do e-mail** para quem não está logado — o que é o comportamento desejado, mas precisa ser explicado ao usuário |
| **B. Token no nome** | acrescentar 32 hex ao nome do arquivo (`report_66_<random>.xlsx`) e bloquear listagem | baixo | Mantém o link direto funcionando; deixa de ser enumerável. **Não** resolve link vazado/encaminhado |
| **C. Link assinado com validade** | `/download?j=N&exp=…&sig=hmac` | médio | Melhor equilíbrio para link em e-mail: funciona sem login, expira, não é enumerável |
| **D. Aceitar o risco** | nada | zero | Só defensável se o homolog não tiver dado real de cliente |

**Recomendação: B agora (uma linha, elimina a enumeração) + C como alvo**, e o bloqueio de
`storage/` no Apache em qualquer cenário:

```apache
# storage/.htaccess — impede listagem e acesso direto ao que não deve ser público
Options -Indexes
```

> Vale medir o alcance antes de decidir: `curl -I http://<homolog>/storage/reports/<arquivo>` sem
> cookie. Se responder 200, o problema é real naquele ambiente; se o Apache já bloqueia
> `storage/` por outra diretiva de VirtualHost, a opção A/C passa a ser obrigatória, porque o link
> do e-mail **nunca** vai funcionar.

### 3.2 Retenção — `storage/reports` cresce para sempre

`log_cleanup.php` limpa **só `logs/*.log`**. Nada apaga relatório gerado. Um agendamento diário
produz 1 arquivo por dia, indefinidamente; com N clientes × M agendamentos o diretório cresce sem
teto e sem ninguém olhando — e cada arquivo é uma cópia de dado de cliente parada em disco.

**Proposta**: estender `scripts/log_cleanup.php` (que já roda diariamente e já sabe ler o `.env`
sem banco) para purgar `storage/reports` por idade, com `REPORT_RETENTION_DAYS` (sugestão: 30) —
e, junto, apagar as linhas de `report_schedule_runs` mais antigas que isso, para o histórico não
crescer indefinidamente também. Um arquivo apagado cujo `job.result_path` ainda aponta para ele
deve aparecer como "expirado" em `/exportar`, não como link quebrado.

---

## Bloco 4 — Entregabilidade (se o item 6 do roteiro falhar)

O `includes/mailer.php` faz SMTP autenticado, mas **não assina DKIM** e não há controle sobre
SPF/DMARC do domínio remetente. Se o e-mail cair em spam:

1. Conferir SPF do domínio de `from_email` autorizando o servidor/provedor de saída.
2. Se o provedor SMTP assina DKIM em nome do domínio (a maioria dos transacionais assina), nada a
   fazer no código — é configuração de DNS.
3. Se for necessário assinar no app, **não** implementar DKIM no `mailer.php` artesanal: é o
   momento de trocar por uma API HTTP transacional (Resend/SES), que já resolve assinatura,
   bounce e reputação. A troca é `curl`, já disponível, e o ponto de mudança é único
   (`send_mail()`).

> Registrar em `STATUS.md` qual caminho foi escolhido — isso determina se o `mailer.php`
> continua sendo mantido ou entra em depreciação.

---

## Bloco 5 — Ordem recomendada e relação com a Fase 5 (wiki)

```
Bloco 1 (publicar)  →  Bloco 2 (validar)  →  Bloco 3 (decidir sobre download e retenção)
                                                        ↓
                                          Fase 5 do plano anterior: /wiki
```

**A wiki vem depois de propósito.** Ela precisa descrever o comportamento **validado** — inclusive
"o relatório grande chega como link" e o que quer que se decida no Bloco 3 sobre esse link. Se a
opção A for escolhida (link exige login), a wiki tem de dizer isso, e escrevê-la antes significaria
reescrevê-la.

---

## Critérios de aceite desta fase

- [x] Homolog em `4.7.0` (`/ping` e `system_info` concordando), 7 workers no `crontab -l`.
      *(hoje em `4.7.2` no `/ping`; o banco segue `4.7.0` porque v4.7.1 e v4.7.2 não têm migração)*
- [x] `APP_URL` conferida no `.env` do servidor. **Estava AUSENTE** — adicionada em 01/08/2026
      (`http://189.22.240.43`), com backup do `.env`.
- [x] Fuso do SO, do PHP e da sessão MySQL registrados em `STATUS.md` (não presumidos):
      SO `America/Sao_Paulo (-03)`, PHP `UTC`, MySQL `SYSTEM` (= −03) numa sessão crua, com a
      conexão PDO forçando `+00:00`.
- [x] **E-mail agendado recebido numa caixa real, com o XLSX anexado, aberto no Excel pt-BR.**
      Confirmado pelo usuário em 01/08/2026.
- [x] Caminho do link exercitado e o link baixando **de fora do navegador logado**
      (`"link":true` no log, URL absoluta a partir de `APP_URL`, HTTP 200 sem cookie).
- [x] Relatório vazio e `skip_if_empty` conferidos nos dois modos (tipo `occurrences`, zerado):
      sem a opção **envia**; com ela, status `vazio` e nada sai.
- [ ] ~~3 falhas consecutivas desativando e notificando, com provedor real.~~ **NÃO exercitado.**
      A lógica tem cobertura local (suíte da v4.7.0, com SMTP de captura), mas contra o provedor
      real exigiria 3 ciclos com destinatário em domínio inexistente. Fica como lacuna consciente.
- [x] Agendamento às 22:00 BRT gravando `next_run_at` no dia seguinte em UTC
      (`2026-08-02 01:00 UTC`).
- [x] Arquivo gerado pelo cron (root) legível pelo Apache (www-data): `0644 root`.
- [x] **Decisão registrada** sobre 3.1 (download sem autenticação) e 3.2 (retenção): token no
      nome implementado na v4.7.1; URL assinada com validade segue como alvo, não feita.
- [x] Nenhum item deste plano marcado como "OK" sem evidência colada em `STATUS.md`.

## Fora do escopo

Novas frequências (a cada hora, dias úteis), mais de 3 destinatários, anexar vários formatos no
mesmo e-mail, `fleet_status` agendável (é foto do agora — não faz sentido) e relatório agendado
por filial. Tudo isso é extensão de produto, não validação do que existe.
