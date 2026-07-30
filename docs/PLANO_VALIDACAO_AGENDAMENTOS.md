# Plano — Publicação e validação do envio agendado (pós-v4.7.0)

**Origem**: fim da série `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md` (Fases 1–4 implementadas).
**Base**: v4.7.0 no working tree; homolog em `92725cb` / banco `4.4.1`.
**Data**: 30/07/2026.

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

- [ ] Homolog em `4.7.0` (`/ping` e `system_info` concordando), 7 workers no `crontab -l`.
- [ ] `APP_URL` conferida no `.env` do servidor.
- [ ] Fuso do SO, do PHP e da sessão MySQL registrados em `STATUS.md` (não presumidos).
- [ ] **E-mail agendado recebido numa caixa real, com o XLSX anexado, aberto no Excel pt-BR.**
- [ ] Caminho do link exercitado e o link baixando **de fora do navegador logado**.
- [ ] Relatório vazio e `skip_if_empty` conferidos nos dois modos.
- [ ] 3 falhas consecutivas desativando e notificando, com provedor real.
- [ ] Agendamento às 22:00 BRT gravando `next_run_at` no dia seguinte em UTC.
- [ ] Arquivo gerado pelo cron (root) legível pelo Apache (www-data).
- [ ] **Decisão registrada** sobre 3.1 (download sem autenticação) e 3.2 (retenção), com a
      implementação feita ou explicitamente adiada com justificativa.
- [ ] Nenhum item deste plano marcado como "OK" sem evidência colada em `STATUS.md`.

## Fora do escopo

Novas frequências (a cada hora, dias úteis), mais de 3 destinatários, anexar vários formatos no
mesmo e-mail, `fleet_status` agendável (é foto do agora — não faz sentido) e relatório agendado
por filial. Tudo isso é extensão de produto, não validação do que existe.
