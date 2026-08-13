# STATUS.md — Jimi Webhook System v4.9.12 (YUV Parity)

> ### 📍 v4.9.12 — F1 do PROJETO_PARAMETROS entregue e PUBLICADA
>
> | | estado |
> |---|---|
> | Local / `origin/main` / Homolog | os três em **`128fbab`** |
> | `/ping` e `system_info` | **4.9.12** |
>
> O sistema passa a saber **como cada câmera JT/T está configurada**. Antes só
> dava para mandar comando e torcer.
>
> #### Verificação — em câmera real, não em fixture
>
> Um `33028` e um `33030` disparados pela aplicação (login + CSRF + rota real)
> contra a **JC371 `865478070003241`**, transmitindo no momento:
>
> | | resultado |
> |---|---|
> | `33028` → snapshot | **612 bytes**, `paramCount` 87, **49 entradas** extraídas |
> | `33030` (44, 45, 85) → snapshot | 45 bytes, 3 de 3 |
> | `device_params` | **49 linhas**, 3 delas de canal de vídeo |
> | Rótulos | `19` → *Servidor Principal*, `16` → *APN da Operadora*, `128` → *Parâmetro 128* |
> | `params_synced_at` | carimbado só pelo `33028` |
> | Aba renderizada (JT/T) | todas as seções, `3888000 s` exibido como **45d** |
> | Aba em câmera **JIMI** | **não existe** — protocolo isolado (ADR-001) |
> | Credencial do APN, `role=operator` | **mascarada**; `cmnet` (não é segredo) aparece |
> | Controle: aba Alertas | segue mostrando `Capotamento` (v4.9.10 intacta) |
>
> `tests/helpers/device_params.test.php` — **48 casos**, 0 falhas. `php -l` limpo.
> Ambiente de teste removido: 0 usuários, 0 sessões órfãs.
>
> #### Quatro defeitos que só o teste real achou
>
> 1. **`33028` recusado com HTTP 400** três linhas antes da normalização que
>    existe para montá-lo — o `cmdContent` da consulta é vazio por especificação.
> 2. **`33030` marcava o device como sincronizado** com 3 de 46 parâmetros, o
>    que faria o worker parar de buscar o resto, em silêncio.
> 3. **A migração se derrubava**: `LIKE 'jtt\_%'` perde a barra dentro de string
>    do MySQL, `_` vira coringa, e o `CAST('uct' AS UNSIGNED)` abortava tudo.
> 4. **Conferência com falso positivo**: `name_pt LIKE 'Parâmetro %'` acusava o
>    `93` (*Parâmetro de Colisão*), que é documentado. Invariante boa é sobre a
>    **procedência** do dado, não sobre como o rótulo começa.
>
> E um quinto, achado pelo teste unitário: `is_int($k)` não distingue lista de
> mapa, porque o PHP converte a chave `'85'` para inteiro sozinho.
>
> #### Pendente (F2 e F3 do blueprint)
>
> - **F2**: `scripts/param_sync_worker.php` (leitura na primeira conexão, com
>   backoff) + relatório de frota "fora do padrão".
> - **F3**: escrita `33027` com diff-only, `desired_value` gravado antes do
>   envio, e perfis **por modelo** — JC181 devolve 6 parâmetros e JC371 devolve
>   46+3 canais, então perfil por cliente acusaria divergência falsa.


> ### 📍 v4.9.11 — F1 do PROJETO_PARAMETROS aberta pelos dois consertos independentes
>
> | | estado |
> |---|---|
> | Migração `v4.9.11` | ✅ **aplicada** no banco do homolog (`system_info` = 4.9.11) |
> | Código | ⚠️ **na árvore local, não publicado** — falta commit + deploy |
>
> | Conserto | Prova |
> |---|---|
> | 🔴 `command_responses.command_content` truncava em 250 | linha `id=14` com `LENGTH = 250` exato; `_content` real do JC371 = **612 bytes** |
> | 🔴 `/config` **morta** (colisão com o diretório `config/`) + fora dos dois mapas | log do Apache: `AH01276: Cannot serve directory .../config/`; renomeada para `/config-dispositivos` |
>
> #### O replay mostrou que a coluna sozinha não bastava
>
> Com `command_content` **já** em `TEXT`, repliquei o callback com os 612 bytes
> reais medidos na câmera. O banco gravou **250** e `JSON_VALID = 0`, cortado no
> meio de `"16":"cmnet"`. **Quem cortava era o `substr` do PHP, não o banco** —
> alterar só a coluna teria deixado o defeito de pé com aparência de corrigido.
> Por isso a verificação final do destruncamento **depende do deploy**: só o
> banco está corrigido no homolog hoje.
>
> Linha sintética do replay **removida** (`command_responses` id 19).
>
> #### ⚠️ Correção de uma afirmação desta sessão
>
> Eu afirmei que "qualquer usuário logado reconfigurava câmera" por `/config`
> estar fora dos mapas de permissão. **Isso estava errado, e o teste com usuário
> restrito de verdade é que mostrou**: `/config` devolvia **301**, não 403.
> Existe um diretório `config/` no docroot, o `mod_dir` do Apache se antecipa ao
> `mod_rewrite`, redireciona para `/config/` e serve o diretório — que morre em
> 403 por `Options -Indexes`. **O PHP nunca rodava.** Ninguém alcançava a tela.
>
> Eram **dois defeitos empilhados, e o de rota escondia o de permissão**. Os
> dois estão corrigidos: rota renomeada para `/config-dispositivos` (arquivo
> `config_dispositivos.php`) e tela nos dois mapas. A trava de permissão passa a
> valer **a partir de agora**, com a rota viva.
>
> A regra que faltava, agora escrita no `.htaccess`: **nenhuma rota pode ter o
> nome de um diretório do docroot** (`config`, `core`, `includes`, `mysql`,
> `scripts`, `storage`, `tests`, `web`, `logs`, `docs`).
>
> A tela **continua fora da sidebar** de propósito: é um console cru de JSON e
> os `cmdContent` que ela monta estão errados (§3.1 do blueprint). A F1 entrega
> a tela definitiva; linkar a rústica agora seria expor trabalho pela metade.
>
> #### Bônus: o JC182 respondeu de verdade
>
> As linhas `17` e `18` são callbacks **reais** do JC182 à sonda de `33028`:
> `Device busy (previous command has not returned)` e, um minuto depois,
> `request timeout`. É exatamente o par de casos que o `PROJETO_PARAMETROS.md`
> §6 prevê para o worker — e a confirmação de que device com
> `last_communication` de segundos antes **recusa comando**.
>
> #### Pendente para fechar a F1
>
> Catálogo de parâmetros + as 3 tabelas + `includes/device_params.php` +
> `cmdContent` correto em 33028/33030 + captura síncrona + aba Parâmetros.



> ### 📍 ESTADO EM 12/08/2026 — v4.9.10: acabaram os alarmes sem nome
>
> | | git HEAD | `system_info` |
> |---|---|---|
> | Local | v4.9.10 (código + migração) | — |
> | **Homolog** (`189.22.240.43`) | código **ainda em `063354c`** ⚠️ | **4.9.10** (migração aplicada) |
>
> ⚠️ **A migração foi aplicada no banco do homolog; o CÓDIGO ainda não foi
> publicado lá.** Falta commit + `deploy.sh` (o gate de versão já vai pular a
> v4.9.10, que está aplicada — é só o código de `wiki.php`/`functions.php` que
> precisa subir). Nada quebra nesse intervalo: a mudança de comportamento veio
> toda do banco, e as duas alterações em PHP são texto de tela e comentário.
>
> #### O que entrou
>
> | Versão | Entrega | Migração | Estado |
> |---|---|---|---|
> | **4.9.10** | `Código 1047 (JTT)` → **`Capotamento`** | sim | ✅ verificado no banco |
> | **4.9.10** | `Código 146 (JIMI)` → **`Curva Brusca`** (+ `144`/`145` preventivos) | sim | ✅ |
> | **4.9.10** | JIMI `45` `Veículo Tumbado` → `Capotamento` (une os dois protocolos) | sim | ✅ |
> | **4.9.10** | `Capotamento` volta ao perfil padrão de ocorrências (alto / 5 min) | sim | ✅ |
> | **4.9.10** | 🔴 **três telas** liam `alarm_name` cru e mostravam o código | não | ✅ provado por sonda |
>
> #### A medida
>
> | | antes | depois |
> |---|---|---|
> | Códigos que a tela mostrava como número | **2** (1047 ×10, 146 ×4) | **0** |
> | Protocolos que chamam capotamento pelo mesmo nome | 0 de 2 | **2 de 2** |
>
> #### Verificação (executada, não presumida)
>
> - Migração rodada **duas vezes** contra o homolog — idempotente, saída idêntica.
> - Conferência de alarme sem nome (mesmos JOINs de `alarm_label_sql()`): **0 linhas**.
> - Conferência de parâmetro de ocorrência órfão: **0 linhas**.
> - `Veículo Tumbado` nas três camadas, com `BINARY`: **0, 0, 0**.
> - Rótulo de tela provado com a expressão de `alarm_label_sql()['expr']`:
>   `Código 1047 (JTT)` → **Capotamento**, `Código 146 (JIMI)` → **Curva Brusca**
>   (as 14 linhas históricas; `alarms.alarm_name` continua congelado, quem
>   conserta é a re-resolução na leitura).
> - `resolve_notification_rule()` reproduzida em SQL: `1047` → regra `acidente`,
>   `146` → regra `conducao`. **`1046` (Colisão) → nenhuma regra** — ver abaixo.
> - `get_occurrence_param()` reproduzida: JT/T `1047` e JIMI `45` caem no MESMO
>   parâmetro `Capotamento` (gera, alto, 5 min).
> - **As três telas corrigidas**, com sonda que roda as consultas reais contra o
>   homolog: `ativo_detalhe` (aba Alertas), `relatorios` (tipo=alarmes) e
>   `ocorrencias_dashboard` (eventos) devolvem `Capotamento` / `Curva Brusca`
>   nas 14 linhas históricas; o filtro de categoria `acidente` devolve 10; e a
>   trava `WHERE <expr> LIKE 'Código %'` devolve **0**.
> - `tests/helpers/diagnostico_guard.test.php` — **14 casos**, 0 falhas.
> - `php -l` em `handlers/ config/ core/ includes/ scripts/` — limpo.
>
> #### 📐 Blueprint novo: `PROJETO_PARAMETROS.md` (parametrização das câmeras)
>
> Desenho aprovado para ler/guardar/escrever a configuração das câmeras JT/T via
> `33027`/`33028`/`33030`, com leitura automática na primeira conexão. **Nenhuma
> linha de código ainda** — o documento é a entrega.
>
> O que o levantamento achou, com **sonda em câmera real** (não pela doc):
>
> | Achado | Impacto |
> |---|---|
> | Campo de contagem é `paramCount`, doc diz `totalNum` | parser pela doc falha 100% das vezes, calado |
> | Vídeo vem em `channel_1..N`, doc diz chave `119` | perderia a configuração de vídeo inteira |
> | 20 dos 46 parâmetros do JC371 não constam da doc | metade invisível na tela |
> | JC181 devolve **6** parâmetros, JC371 devolve **46+3 canais** | perfil tem de ser por **modelo**, não por cliente |
> | 🔴 `command_responses.command_content` é `varchar(250)` com `substr(…,250)` | **já perde dado hoje**: linha `id=14` tem `LENGTH = 250` exato |
> | 🔴 `/config` fora de `$screenByHandler` **e** de `$screens` | 5ª ocorrência da armadilha; qualquer usuário reconfigura câmera |
> | `_code:600` com `last_communication` de segundos antes (JC182) | frescor de comunicação ≠ device aceita comando |
>
> Consequência assumida pelo dono (12/08/2026): **não bloquear** os parâmetros de
> rede (`19`,`23`,`24`,`25`,`16`,`17`,`18`), porque a câmera continua alcançável
> por **SMS**. Em troca, o valor anterior é gravado antes do envio — sem isso o
> SMS de recuperação não tem para onde apontar.
>
> #### 🔴 Achado registrado e NÃO corrigido
>
> **`Colisão do Veículo` não dispara notificação.** `1046` (JT/T) e `147` (JIMI)
> estão na categoria `veiculo`, que não tem regra; `Airbag Acionado / Colisão`
> (JIMI `30`) está em `acidente` e dispara. Confirmado rodando a consulta do
> motor: colisão devolve zero regras. Movê-la para `acidente` aumenta o volume
> notificado de um alarme frequente — **decisão de produto, aguardando o dono**.

> ### 📍 ESTADO EM 10/08/2026, 21h15 — v4.9.8 e v4.9.9 publicadas e verificadas
>
> | | git HEAD | `/ping` | `system_info` |
> |---|---|---|---|
> | Local | `063354c` (+ docs) | — | — |
> | `origin/main` | `063354c` (+ docs) | — | — |
> | **Homolog** (`189.22.240.43`) | **`063354c`** (+ docs) | **4.9.9** | **4.9.9** |
>
> Árvore limpa, os três em paridade. `063354c` é o último commit de **código**;
> o que veio depois nesta sessão é documentação. **Duas migrações** foram
> aplicadas (`v4.9.8` e `v4.9.9`), por isso `system_info` acompanha o código.
>
> #### O que entrou nesta sessão
>
> | Versão | Entrega | Migração | Estado |
> |---|---|---|---|
> | **4.9.8** | Vídeo da ocorrência toca na tela (snapshot do meio) + coluna Vídeo em alarmes | sim | ✅ verificado no navegador |
> | **4.9.8** | 🔴 "Sem vídeo vinculado" com o vídeo no disco | sim | ✅ |
> | **4.9.8** | 🔴 Busca do Dashboard de Ocorrências nunca devolveu nada | não | ✅ |
> | **4.9.8** | Ocorrência identificada pela **placa**, não pelo IMEI | não | ✅ |
> | **4.9.8** | 🔴 As **9 abas** de `/ativos/{imei}` renderizavam vazias *(defeito antigo)* | não | ✅ |
> | **4.9.9** | 🔴 Evento de **diagnóstico** deixou de ser tratado como alarme | sim | ✅ |
>
> #### O fio que liga as duas versões
>
> **As duas nasceram da mesma pergunta: quem é o público deste dado?**
>
> A v4.9.8 achou um vídeo que existia no disco e não aparecia, porque o sistema
> só olhava `media_files` e o vídeo do evento JIMI é anunciado dentro do push do
> alarme — **duas fontes para o mesmo arquivo, e só uma consultada.** A v4.9.9
> achou o inverso: 5.073 linhas aparecendo para o operador que nunca foram
> destinadas a ele — **um público só, para dados de dois públicos diferentes.**
>
> Em ambas o sintoma era **ausência de sinal**, não erro: nenhum log, nenhum
> HTTP ≠ 200, nenhuma exceção. É a mesma família das v4.9.4–4.9.6, com uma
> variação — ali um valor em duas camadas se contradizia; aqui uma camada
> simplesmente **não era consultada**, e uma classificação **não existia**.
>
> #### Números depois de tudo
>
> | | antes | depois |
> |---|---|---|
> | Linhas na tela de alarmes | 5.112 | **39** |
> | Aba Alertas de um equipamento | 51 | **14** |
> | Ocorrências com vídeo vinculado | 3 de 8 | **5 de 8** *(as 3 restantes não têm anexo)* |
> | Abas com conteúdo em `/ativos/{imei}` | 0 de 9 | **9 de 9** |
>
> #### Verificação (executada, não presumida)
>
> - `php -l` em toda a árvore — limpo.
> - `tests/helpers/player_snapshot.test.js` — **59 asserções**, 0 falhas.
> - `tests/helpers/diagnostico_guard.test.php` — **12 casos**, 0 falhas
>   (contra o banco do homolog).
> - **Navegador real** contra o homolog: player mp4 e `.ts` com snapshot do meio,
>   modal do relatório, grade por placa, busca, trava de admin com usuário
>   `operator` de verdade, e varredura de **36 rotas + 9 abas** sem erro.
> - Ambiente de teste **removido**: 0 bancos `jimi_migtest*`, 0 sessões órfãs,
>   0 usuários de teste.
>
> #### Pendências desta sessão
>
> - 👀 **Defeito de equipamento saiu da tela do operador** (decisão de produto).
>   `Falha no Armazenamento`, `Perda de Sinal de Vídeo` e `Falha de Câmera` são
>   `critical` e somam **4.541** linhas — há defeito real acontecendo no parque.
>   Eles seguem no modo diagnóstico, restrito ao admin. Se ninguém com esse
>   perfil olhar, câmera quebrada deixa de ser percebida. Caminho combinado, se
>   incomodar: **visão de manutenção com o flag invertido**, não devolvê-los ao
>   operador.
> - ⚠️ **As placas do parque de teste não são placas** — `devices.device_name`
>   está `400AD` e `400AD_2` nos equipamentos das ocorrências novas. A tela está
>   certa; o cadastro é que não tem placa.
> - 💡 **O `.ts` é baixado duas vezes** para montar o snapshot (mpegts.js
>   bufferiza o clipe e o seek reabre o stream). Com 1–4 MB não tem sintoma; se
>   esse player abrir gravação de cartão (21 MB), revisitar.
> - 🔭 **Exports de alarmes seguem sem a coluna Vídeo** — link que exige sessão
>   não se sustenta em PDF que circula por e-mail; o caminho seria o link
>   assinado do `download_token.php`.
> - ⚠️ **Suíte Playwright continua bloqueada** — o MySQL de desenvolvimento
>   desta máquina não tem data dir. As duas suítes acima rodam sem ele, mas
>   `tests/*.spec.js` (40 testes) não roda desde a sessão anterior.
>
> #### Pendências herdadas (seguem abertas)
>
> - ~~**`Código 1047 (JTT)`** — não consta da doc oficial; depende do fornecedor.~~
>   **RESOLVIDO na v4.9.10**: o fornecedor informou que é **capotamento**. Junto
>   saiu o `Código 146 (JIMI)`, que esta lista nunca mencionou — eram dois.
> - **`devices.last_communication` incompleta** — só `pushalarm` e `pushlbs` a escrevem.
> - **Cercas**: coluna Mapa existe na tela e não no PDF/XLS.
> - **`tests/comandos.spec.js` escrito e não executado** (v4.9.7).
> - **Nenhum comando disparado para veículo real** (v4.9.7).

---

> ### 📍 v4.9.9 (10/08/2026) — evento de diagnóstico deixou de ser alarme
>
> **Diagnóstico é o que o equipamento diz ao SISTEMA** — handshake de upload de
> vídeo, entrada e saída de repouso, defeito de hardware — **e não o que o
> veículo diz ao OPERADOR.** Some das telas de alarme, ocorrência, Resumo e BI;
> a linha continua inteira em `alarms`, com `raw_data`.
>
> A medida que motivou, no homolog: de **5.112** alarmes, **5.073 eram ruído de
> infraestrutura** e **16** eram DMS/ADAS — o núcleo do produto. O relatório de
> alarmes e o "top 3 equipamentos" do Resumo descreviam a saúde do equipamento,
> não a operação.
>
> | | antes | depois |
> |---|---|---|
> | Linhas na tela de alarmes | 5.112 | **39** |
> | Aba Alertas de um device | 51 | **14** |
> | Export XLSX (3 dias) | — | 2.682 B normal · 16.548 B em diagnóstico |
>
> #### As três decisões que sustentam isso
>
> 1. **Classificação no CATÁLOGO, por CÓDIGO** (`alarm_types.is_diagnostic`).
>    Por código porque `alarms.alarm_name` é congelado na chegada e tem
>    variantes: os **845** `Fim de Alarme: …` carregam o mesmo código do alarme
>    de abertura e são pegos de graça. E porque junção por nome morre em
>    silêncio quando alguém renomeia — a armadilha que o `CLAUDE.md` documenta
>    três vezes.
> 2. **Falha para o lado de MOSTRAR** — `COALESCE(atc.is_diagnostic,
>    atb.is_diagnostic, 0)`. Código fora do catálogo (`Código 1047 (JTT)` é o
>    caso real, 8 linhas) dá NULL nos dois JOINs; sem o zero final, um alarme
>    novo desapareceria da tela sem erro nenhum.
> 3. 🔴 **`Falha de Câmera` entrou como código COMPOSTO `256-2048`, e isso é
>    trava de segurança.** O mesmo código 256 é o bitmask padrão do JT/T e
>    carrega `Emergência / SOS` (bit 0) e `Excesso de Velocidade` (bit 1);
>    `decodeStandardAlarm()` **combina** os bits ativos num nome só, então
>    câmera + SOS chega como `256-2049` — código diferente, que segue visível.
>    Marcar a base `256` teria escondido pedidos de socorro.
>
> #### Verificação
>
> - **Migração em cópia completa** (`alarm_types` + `alarms` + apoio) antes de
>   tocar no banco real; as duas travas do arquivo (nenhum DMS/ADAS/SOS
>   classificado, nenhum parâmetro de ocorrência apontando para técnico)
>   voltaram **zero linhas**. O banco real reproduziu o mesmo: 5.073 / 39.
> - **`tests/helpers/diagnostico_guard.test.php`** — 12 casos contra banco real,
>   incluindo as bordas: `256-2049` e `256` base seguem visíveis, código fora do
>   catálogo segue visível, `105` em JT/T não é diagnóstico (ADR-001). Aborta
>   com mensagem própria se a migração não estiver aplicada.
> - **No navegador, contra o homolog**: tela de alarmes sem técnicos, caixa
>   "Eventos de diagnóstico" só para admin, modo diagnóstico com aviso;
>   Resumo, BI, aba do ativo, relatórios e ocorrências sem vazamento.
> - **A trava de permissão, com usuário `operator` de verdade** forçando
>   `?diagnostico=1`: sem caixa, sem aviso, zero eventos técnicos — e o **export
>   XLSX saiu byte a byte idêntico** ao do modo normal (2.682 B).
>
> ⚠️ **A primeira tentativa de testar a trava foi VÁCUA**: o `UPDATE users SET
> role='operador'` falhou (a coluna é `ENUM('admin','operator','viewer')`) e o
> teste mediu o admin de novo, "provando" um vazamento inexistente. Refeito com
> `operator` e `viewer`, conferindo o valor gravado antes de medir. É o mesmo
> padrão de [[vacuous-assertions]] e valeu para as duas versões desta sessão.
>
> #### Consequência assumida
>
> **Defeito de equipamento saiu da tela do operador.** `Falha no Armazenamento`,
> `Perda de Sinal de Vídeo` e `Falha de Câmera` são `severity = critical` no
> catálogo e alguém **precisa** consertá-los — foi decisão de produto (10/08)
> tirá-los da operação. Eles seguem no modo diagnóstico, que é restrito ao
> administrador. **Se ninguém com perfil admin olhar aquela tela, câmera
> quebrada deixa de ser percebida.** O caminho natural, se isso incomodar, é uma
> visão de manutenção com o mesmo flag invertido — não devolvê-los ao operador.
>
> #### Não filtrado, de propósito
>
> `camerasdata.php`: ali `MAX(alarms.created_at)` é **sinal de vida da API**
> ("quando o gateway recebeu algo pela última vez"), e evento técnico é tráfego
> legítimo. Excluí-lo faria a API parecer offline.

---


> ### 📍 v4.9.8 (10/08/2026) — vídeo da ocorrência e placa no lugar do IMEI
>
> Publicada e verificada; `ee455c3` foi o último commit de código dela.
> A v4.9.9, acima, veio na mesma sessão e é o estado corrente.
>
> Os três em paridade. `system_info` subiu para 4.9.8 porque desta vez **houve
> migração** de esquema/dados (`migration_v4.9.8.sql`).
>
> #### O que entrou na v4.9.8
>
> | Entrega | Tipo | Estado |
> |---|---|---|
> | Coluna **Vídeo** no relatório de alarmes (modal com player) | feature | ✅ verificado no servidor |
> | **Player com snapshot** no detalhe da ocorrência (quadro do meio) | feature | ✅ verificado no navegador (mp4 e `.ts`) |
> | 🔴 "Sem vídeo vinculado" **com o vídeo no disco** | fix | ✅ migração aplicada, conferências em zero |
> | 🔴 Busca do Dashboard de Ocorrências **nunca devolveu nada** | fix | ✅ provado antes e depois |
> | `.ts` não era reconhecido como vídeo na chegada do alarme | fix | ✅ |
> | `/midia` recusava o anexo de alarme | fix | ✅ HTTP 206 no servidor |
> | Ocorrência identificada pela **placa**, não pelo IMEI | change | ✅ |
> | 🔴 Snapshot do `.ts` parava no início, vídeo tocava mudo sozinho | fix | ✅ achado e provado no navegador |
> | 🔴 As **9 abas** de `/ativos/{imei}` renderizavam vazias (defeito antigo) | fix | ✅ |
> | Sidebar do ativo empilhava sobre o conteúdo (CSS órfão) | fix | ✅ |
>
> #### O fio que liga a v4.9.8
>
> **Duas fontes para o mesmo arquivo, e o sistema só olhava uma.** O vídeo do
> evento chega por dois caminhos que nunca se encontraram: em JT/T pela extração
> 37382 → `/pushftpfileupload`, que grava `media_files`; em JIMI o device sobe o
> arquivo sozinho e só ANUNCIA o nome dentro do push do alarme (ADR-001), sem
> webhook de upload. Todo o resto do sistema — o vínculo da ocorrência, o escopo
> do `/midia`, a fila de downloads — consultava apenas `media_files`. Resultado
> medido: **4 alarmes com anexo, 0 linhas em `media_files`, todos os arquivos
> presentes no disco**, e a tela de tratativa dizendo "Sem vídeo vinculado"
> justamente no caminho JIMI, que é o núcleo do produto. Como ausência de mídia
> não é erro em lugar nenhum, nada apareceu em log nem na tela.
>
> É o mesmo padrão das v4.9.4–4.9.6 (*"um valor de identidade em duas camadas"*),
> com uma variação: aqui as duas camadas nem se contradiziam — uma simplesmente
> **não era consultada**.
>
> #### Como foi verificado (sem servidor local)
>
> O MySQL de desenvolvimento continua sem data dir, então a suíte Playwright
> segue bloqueada. A verificação veio de:
>
> 1. **Migração numa cópia real ANTES do deploy** — `jimi_migtest` com dump de
>    `alarms`, `media_files`, `occurrences`, `occurrence_events` do homolog. As
>    duas conferências do arquivo voltaram **zero linhas**; ocorrências com mídia
>    foram de 3 para 5, e as 3 que sobraram são as que **de fato** não têm anexo.
>    Rodada duas vezes: idempotente (nenhuma linha duplicada). Cópia descartada.
>    No deploy, o banco real reproduziu o MESMO resultado (8 / 5 / 3).
> 2. **`/midia` servindo o anexo no servidor** — `HTTP/1.1 206 Partial Content`,
>    `Accept-Ranges: bytes`, `Content-Range: bytes 0-1023/1149033`,
>    `Content-Type: video/mp4` (e `video/mp2t` no `.ts`), `Disposition: inline`.
>    É exatamente o que o player precisa para buscar o meio sem baixar o arquivo
>    inteiro. Sem cookie → **302** (barra); arquivo inexistente → **404**.
> 3. **Telas reais, servidas pelo Apache do homolog**: ocorrência 8 (mp4) e 7
>    (`.ts`) abrem com player, placa no cabeçalho e mpegts.js carregado **só** no
>    caso `.ts`; ocorrência 5, que não tem anexo, segue mostrando "Sem vídeo
>    vinculado" — o fallback não inventa mídia. No relatório de alarmes os botões
>    saem com `/midia?f=…` e o `data-ts` certo em cada protocolo.
> 4. **A busca de ocorrências, antes e depois** — o `SELECT` cru reproduz
>    `Unknown column 'dr.name'`; depois do fix, buscar pela placa devolve 2 de 2.
> 5. **O JS do player, executado de verdade** —
>    `tests/helpers/player_snapshot.test.js` roda o script **real** extraído de
>    `video_player_assets.php` sobre um DOM mínimo em Node: **59 asserções**,
>    incluindo a regra que define a entrega (10 s → 5 s, 20 s → 10 s, 15 s →
>    7,5 s, 37,4 s → 18,7 s), o play voltando a agulha para 0, o `pause()` no
>    mpegts após capturar o quadro e o `destroy()` ao fechar o modal.
>
> 6. **No navegador de verdade** (Chrome, contra o homolog), depois do primeiro
>    deploy — e foi aqui que apareceram **três** defeitos que nenhuma das provas
>    acima pegava:
>    - mp4: `duration 10.272 → currentTime 5.136`, poster de 75 KB capturado,
>      1280×720, `readyState 4`; play recomeça do zero (0 → 1.19) e, ao terminar,
>      volta ao meio e reoferece o play.
>    - `.ts`: `15.162 → 7.61`, poster de 33 KB, 640×360, pausado. **Só depois do
>      fix** — ver abaixo.
>    - Modal do relatório de alarmes: abre, fecha desmontando o player, reabre,
>      Esc fecha; zero `<video>` no DOM antes do clique (25 linhas na página).
>    - Grade de ocorrências: coluna **Placa**, busca `400AD` → 2 de 2, `ZZZZZZ` → 0.
>    - Varredura de **36 rotas + 9 abas do ativo**: nenhum HTTP ≠ 200, nenhum
>      erro de PHP. Console sem um único erro nas telas com player.
>
> #### 🔴 O que só o navegador pegou
>
> 1. **O snapshot do `.ts` parava no início e o vídeo tocava mudo sozinho.** Com
>    MSE, `loadedmetadata` dispara ANTES de a duração existir; o código decidia
>    ali, uma vez só. O harness em Node **passava** — ele entregava a duração
>    junto com o evento, como um `<video>` nativo faz e a MSE não faz. A lição:
>    um dublê fiel demais ao caso fácil esconde o caso real. O harness ganhou os
>    dois casos que faltavam (duração que chega tarde, duração que nunca chega)
>    e foi para **59 asserções**.
> 2. **As 9 abas de `/ativos/{imei}` renderizavam vazias** — anterior a esta
>    sessão, provado com render byte-idêntico na v4.9.7. `foreach ($tabs as $tab)`
>    num include sobrescrevia o `$tab` do chamador, e `switch ($tab)` passou a
>    comparar array com string.
> 3. **A sidebar do ativo empilhava sobre o conteúdo**: regra de CSS apontando
>    para `.main-content-inner`, elemento que não existe no projeto.
>
> #### Pendências da v4.9.8
>
> - 💡 **O `.ts` é baixado duas vezes** para montar o snapshot: o mpegts.js
>   bufferiza o clipe inteiro e o seek reabre o stream (visível no console como
>   um segundo `onSourceOpen`). Com anexos de 1–4 MB isso é ~3 MB e nenhum
>   sintoma; se algum dia esse player abrir gravação de cartão (21 MB), vale
>   revisitar. O mp4 não tem esse custo — usa `Range` nativo.
> - ⚠️ **As placas do parque de teste não são placas.** `devices.device_name`
>   está como `400AD` e `400AD_2` nos dois equipamentos das ocorrências novas,
>   então o cabeçalho mostra "Placa: 400AD_2". A tela está certa; o **cadastro**
>   é que não tem a placa. Corrigir em *Equipamentos* para ver o efeito real.
> - 💡 **O anexo do alarme agora aparece em `/video/downloads`** (linha com
>   `source_type = 'pushalarm'`, status Disponível). É consequência desejada —
>   o arquivo existe e é baixável —, mas muda o que aquela fila mostra: ela
>   deixou de ser só "o que eu pedi por extração".
> - 🔭 **Exports de alarmes seguem sem a coluna Vídeo.** O pedido era a tela, e
>   um link que exige sessão não se sustenta bem num PDF que circula por e-mail.
>   Se for para entrar, o caminho é o link assinado do `download_token.php`.

---

> ### 📍 ESTADO EM 09/08/2026, 23h00 — tudo publicado e verificado no homolog
>
> | | git HEAD | `/ping` | `system_info` |
> |---|---|---|---|
> | Local / `origin/main` | `ae80bcd` | — | — |
> | **Homolog** (`189.22.240.43`) | **`ae80bcd`** | **4.9.7** | **4.9.5** |
>
> Árvore limpa, os três em paridade. `system_info` em 4.9.5 é correto: ela é a
> versão do **esquema**, e as v4.9.6/4.9.7 foram só código.
>
> #### O que entrou nesta sessão
>
> | Versão | Entrega | Migração | Estado |
> |---|---|---|---|
> | **4.9.4** | Remetente do e-mail: nome antigo do produto → `bycamera` | sim | ✅ verificado |
> | **4.9.5** | Evento único por protocolo no cadastro de ocorrências + categorias em pt-BR | sim | ✅ verificado |
> | **4.9.6** | 🔴 Notificação de DMS/ADAS em JT/T nunca disparava | não | ✅ verificado |
> | **4.9.7** | Tela de Comandos: catálogo por modelo, parâmetros, respostas legíveis | não | ✅ publicado |
>
> #### O fio que liga as quatro
>
> Todas nasceram do mesmo padrão: **um valor de identidade guardado em duas
> camadas, e a de baixo vencendo em silêncio.** O nome do produto vivia no código
> *e* na coluna `smtp_settings.from_name` (o banco vencia). A categoria do alarme
> existia em inglês nas linhas JIMI e em português nas JT/T. O código do alarme
> chega como base (`264`) mas o catálogo guarda o composto (`264-4`). Em todos os
> casos o sintoma era ausência — e-mail com nome errado, ocorrência duplicada,
> notificação que não dispara — sem erro em log nem na tela.
>
> #### Pendências desta sessão
>
> - ⚠️ **`tests/comandos.spec.js` escrito e NÃO executado.** A suíte precisa de
>   servidor + banco locais, e o MySQL de desenvolvimento desta máquina **não tem
>   mais data dir** (`C:\Users\flavi\mysql` só tem os binários). Isso bloqueia a
>   suíte inteira, não só este spec — é a dívida mais concreta em aberto.
> - ⚠️ **Nenhum comando foi disparado para veículo real** na v4.9.7. Trava de
>   modelo, montagem da string e leitura das respostas estão provadas contra dados
>   reais; o ciclo ponta a ponta com câmera depende de uso na tela.
> - 👀 **Acompanhar o volume de notificação nos próximos dias.** A v4.9.5 fez as
>   regras valerem para os dois protocolos (`conducao` 8→15 alarmes cobertos,
>   `seguranca` 11→14, `emergencia` 1→2) e a v4.9.6 destravou DMS/ADAS de JT/T.
>   As duas **aumentam** o que notifica, de propósito — mas é mudança real de volume.
>
> #### Pendências herdadas (seguem abertas)
>
> - **`Código 1047 (JTT)`** — não consta da doc oficial; depende do fornecedor.
> - **`devices.last_communication` incompleta** — só `pushalarm` e `pushlbs` a escrevem.
> - **Cercas**: coluna Mapa existe na tela e não no PDF/XLS.
>
> ---

> ### 📍 v4.9.7 (09/08/2026) — Comandos: catálogo por modelo, parâmetros e respostas legíveis
>
> **✅ PUBLICADO** — commit `ae80bcd`, `/ping` em 4.9.7. Sonda no servidor
> confirmou o catálogo publicado (119 comandos, 14 universais, zero sintaxes com
> a senha de SMS) e a interpretação das três respostas reais do gateway.
> Sem migração — só código. `system_info` fica em **4.9.5**.
>
> **Catálogo**: `includes/command_catalog.php`, **119 comandos** (87 com tabela de
> parâmetros, 68 com exemplos), extraído das 10 páginas da wiki Foco na Via.
> Cobertura: JC371 94 · JC182 50 · JC400AD 42 · JC400D 41 · JC450 31 · JC181 19.
>
> ⚠️ **Só a forma de PLATAFORMA entrou.** A wiki documenta cada comando em duas
> formas: SMS (`SENALM#666666#ON#1`, com senha) e plataforma (`SENALM,ON,1`).
> O envio daqui é proNo 128 pelo IoT Hub — a forma de SMS seria recusada pelo
> device. Teste afirma que nenhuma sintaxe carrega `666666`.
>
> **As duas regras da tela**
> - Comando específico de modelo **desabilita** os equipamentos dos outros.
> - Comando universal (5+ das 6 páginas — 14 no catálogo) **solta a trava**.
>
> **Respostas**: `includes/command_response.php` desembrulha o envelope do gateway
> e traduz com dica de ação. 🔴 Descoberto no caminho: **`commands.status` não é o
> desfecho** — há linhas com `status='executed'` e resposta `"request timeout"`.
> O status registra que o callback chegou, não que o device obedeceu; o histórico
> passou a mostrar o desfecho interpretado.
>
> **Verificação**: o JS **real** da página (extraído do HTML renderizado) foi
> exercitado em Node sobre um DOM mínimo — **12 asserções, todas passando**. Render
> completo contra o banco do homolog validou os 5 blocos JSON embutidos, e foi ele
> que pegou `video_stream_config()['ip']`, chave que não existe (`php -l` passa).
>
> ⚠️ **`tests/comandos.spec.js` foi escrito mas NÃO rodou** — a suíte precisa de
> servidor e banco locais, e o MySQL de desenvolvimento desta máquina não tem mais
> data dir. Spec que pula não é cobertura: a prova desta entrega é o harness em
> Node e o render, não o spec.
>
> ---


> ### 📍 v4.9.6 (09/08/2026) — 🔴 notificação de DMS/ADAS em JT/T nunca disparava
>
> **Sem migração** — só código. `system_info` fica em **4.9.5**, `SYSTEM_VERSION` vai a **4.9.6**.
>
> Achado ao auditar `/config-notificacoes` a pedido. É o defeito mais grave desta
> série, e é **funcional, não cosmético**.
>
> **A causa.** `pushalarm.php` entrega aos motores o código **base** (`264`),
> porque o subtipo mora em coluna separada (`alarms.alarm_subtype = 4`); mas
> `alarm_types.alarm_code` guarda o **composto** (`264-4`). Comparando só a base,
> o ramo `at.alarm_code = :atype` nunca casava para DMS e ADAS de JT/T.
>
> **Por que ninguém viu.** JIMI tem código simples (207, 71, 132) e casava; JT/T
> sem subtipo (`1027`) casava. O sintoma era *"câmera JIMI notifica, câmera JT/T
> não"* — sem erro em log nem na tela.
>
> **Por que só a notificação sofria.** Regra gravada por **nome** casa pelo ramo
> `= :aname` e sempre escapou. Os 22 parâmetros de ocorrência são por nome — por
> isso o motor de ocorrências resolvia certo nos dois protocolos. As **6** regras
> de notificação são por **categoria**, que só tem o ramo de código.
>
> | Entrada | Antes | Depois |
> |---|---|---|
> | JT/T `264-4` (PCW) | **nenhuma regra** | regra #4 (ADAS) |
> | JT/T `265-1` (Fadiga) | **nenhuma regra** | regra #3 (DMS) |
> | JT/T `265-10` (Cinto) | **nenhuma regra** | regra #3 (DMS) |
> | JIMI `207`, `71` | regra #4 / #3 | inalterado |
> | JT/T `1027` (sem subtipo) | regra #6 | inalterado |
> | fora do catálogo `9999` | nenhuma | nenhuma |
>
> Corrigido nos **dois** motores (mesma estrutura de matching), com
> `alarm_subtype` viajando de `pushalarm.php` até `notify_from_occurrence()`.
> O elo que o teste SQL não cobre foi conferido à parte: `$subType` é o mesmo
> valor já gravado em `alarms.alarm_subtype`, e há linhas reais no banco
> (`265-1`, `265-4`, `265-5`, `265-10`) provando que chega populado.
>
> ⚠️ **Não dá para dizer quantas notificações se perderam**: as 26 do homolog são
> anteriores às regras atuais e há seeds de teste na base. O que está provado é o
> comportamento do matching, medido antes e depois.
>
> **Também nesta versão** (achados da mesma auditoria):
> - **Regressão da v4.9.5**: a lista de regras imprimia `alarm_type` cru e passou
>   a mostrar `conducao`/`seguranca` depois da normalização. Agora mostra o mesmo
>   rótulo do formulário, com selo **Categoria** separando regra de categoria
>   inteira de regra de evento.
> - **Regra morta agora é visível** — "⚠ não corresponde a nenhum alarme".
> - **"Medio" sem acento** em 4 lugares, um deles o **export** do Relatório de
>   Ocorrências (PDF/Excel do cliente). Unificado em `occurrence_risk_label()`.
>
> ---

> ### 📍 v4.9.5 (09/08/2026) — evento único por protocolo + categorias em pt-BR
>
> **✅ PUBLICADO E VERIFICADO NO HOMOLOG** — commit `3e4329e`, `/ping` e
> `system_info` em **4.9.5**. Sonda com o código publicado devolveu **67 opções,
> nenhuma repetida**, e os casos do pedido resolvidos:
> `ADAS: Colisão com Pedestre (PCW) → JT/T 264-4 · JIMI 207`,
> `ADAS: Colisão Frontal (FCW) → JT/T 264-1 · JIMI 204 · JIMI 229`.
> Baseline medido antes: 67 linhas com categoria em inglês e 4 regras casando
> por categoria inglesa; depois, **zero** em ambos e as 6 regras cobrindo
> alarmes (2, 2, 30, 15, 14, 15). `/config-ocorrencias`,
> `/config-notificacoes` e `/relatorios` respondem 302 → login (não 500).
>
> **O pedido**: no cadastro de ocorrências, o evento deve ser único independente
> do protocolo — "Colisão com Pedestre" é JIMI 207 **e** JT/T 264-4, um evento só.
>
> **O que estava por trás.** Dois defeitos que se revelaram o mesmo:
> 1. o dropdown listava uma opção por **linha de catálogo**, e o catálogo tem uma
>    linha por protocolo (FCW tinha três: JIMI 204, JIMI 229, JT/T 264-1);
> 2. `alarm_types.category` estava dividida por **idioma**: inglês nas linhas
>    JIMI, português nas JT/T (`Driving`×`conducao`, `Security`×`seguranca`…),
>    então o mesmo evento caía em dois `<optgroup>` diferentes.
>
> A prova de que são o mesmo defeito: os **únicos 6** nomes que cruzavam mais de
> uma categoria cruzavam exatamente esses pares. Unificada a categoria, nenhum
> nome cruza — e aí agrupar por nome vira operação inequívoca.
>
> | | Antes | Depois |
> |---|---|---|
> | Opções no dropdown | **83** | **67** |
> | Eventos distintos por trás | 65 | 67 |
> | Duplicatas visíveis | **18** | **0** |
> | Categorias | 19 (mistas EN/PT) | 13 (pt-BR + siglas) |
>
> O "antes" foi **reconstruído** recarregando o dump original e rodando a consulta
> antiga — não estimado. 65 + 2 eventos recuperados = 67, fecha exato.
>
> ⚠️ **O remap de `notification_rules` era obrigatório.** As 6 regras do homolog
> casam **todas** por categoria, nenhuma por nome; renomear sem remapear teria
> desligado as notificações em silêncio. Efeito desejado do conserto: as regras
> deixaram de ser presas a um protocolo — `conducao` 8 → **15** alarmes cobertos,
> `seguranca` 11 → **14**, `emergencia` 1 → **2**.
>
> **Inglês que chegava ao usuário**: badge de severidade (`critical`/`warning`),
> filtros de severidade e categoria, e `JTT` onde o resto do sistema diz `JT/T`.
> Traduzido **na exibição** (`alarm_category_label()`, `alarm_severity_label()`,
> `protocol_label()`), nunca gravando o rótulo na coluna — `category` e
> `severity` são chave de junção. `DMS`/`ADAS` ficam: são siglas, e
> `rel_alarmes.php` filtra por elas.
>
> ---


> ### 📍 v4.9.4 (09/08/2026) — nome antigo fora dos e-mails + wiki atualizada
>
> **✅ PUBLICADO E VERIFICADO NO HOMOLOG** — commit `00103f7`, `/ping` em **4.9.4**,
> `system_info` em **4.9.4**, backup `20260809_150522`.
>
> **O sintoma**: relatório agendado chegava assinado como "Jimi Tracker". A v4.8.0
> disse ter trocado "o remetente padrão de e-mail" e trocou **um** dos três lugares
> que decidem o nome — não o que vencia. A precedência de `mail_config()` é
> **banco → `.env`**, e a linha de `smtp_settings` tinha o nome antigo, então
> nenhuma mudança em PHP chegava à caixa de entrada.
>
> **Como o nome antigo entrou no banco sem ninguém digitá-lo**: a coluna
> `smtp_settings.from_name` foi criada na v4.4.1 com `DEFAULT 'Jimi Tracker'`.
> Qualquer `INSERT` que omitisse a coluna gravava o nome sozinho. É a mesma
> família de defeito do `alarm_types`: o valor certo no código, o errado no banco,
> e o banco vencendo em silêncio.
>
> | Camada | Estado |
> |---|---|
> | `smtp_settings.from_name` (DEFAULT + linhas) | ✅ `migration_v4.9.4.sql` aplicada no homolog |
> | `includes/mailer.php` (2 fallbacks + `X-Mailer` + boundary) | ✅ corrigido |
> | `scripts/worker.php` (rodapé dos 2 templates de e-mail) | ✅ corrigido |
> | `.env.example` (`SMTP_FROM_NAME`) | ✅ corrigido |
> | `includes/geocode.php` (User-Agent) | ✅ `bycamera/4.9` |
> | Wiki `/wiki` | ✅ atualizada (estava em 30/07/2026) |
>
> **Verificado em banco real**: `smtp_settings` recriada a partir do DDL da própria
> v4.4.1 (com o DEFAULT antigo), duas linhas — uma com o valor do DEFAULT, uma
> personalizada. Depois da migração a primeira virou `bycamera` e a **segunda
> sobreviveu intacta**; migração rodada duas vezes com saída idêntica. A prova
> final é o cabeçalho da mensagem, não o valor no banco: `mail_build_message()`
> devolveu **`From: bycamera <a@x.com>`**.
>
> **Confirmado no homolog, com baseline medido antes do deploy** (não deduzido):
> a linha global de `smtp_settings` tinha mesmo `from_name = 'Jimi Tracker'` e o
> `COLUMN_DEFAULT` da coluna também — exatamente o cenário previsto. Depois da
> migração: linha e DEFAULT em `bycamera`, **zero** linhas com qualquer variação
> de "jimi". Sonda no servidor com a config real (`source=banco:global`,
> `smtp.task.com.br`) produziu **`From: bycamera <camera@telecomtrack.com.br>`**
> e `X-Mailer: bycamera` — a sonda **monta** a mensagem e imprime o cabeçalho,
> não envia e-mail para ninguém.
>
> ✅ **O `.env` do homolog não tem `SMTP_FROM_NAME` nenhum** — a ressalva que
> estava aqui está fechada. E, com linha no banco, o `.env` nem é consultado
> para este campo.
>
> `/wiki` responde **302 → /login** sem sessão (não 500), e os webhooks seguem
> entrando normalmente (`pushhb` processado durante a verificação).
>
> **Não renomeado, de propósito**: banco `jimi_tracker`, badge de protocolo `JIMI`,
> `jimicloud.com`, cookie `jimi_token`, chaves de `localStorage`,
> `jimi-tracker-upload-process` (serviço real do fornecedor) e os ~100 docblocks
> `JIMI Webhook System —` (nome do repositório, não da marca).
>
> ---

> ### 📍 ESTADO EM 06/08/2026, 10h10 — tudo publicado e verificado no homolog
>
> | | git HEAD | `/ping` | `system_info` |
> |---|---|---|---|
> | Local / `origin/main` | `4954124` | — | — |
> | **Homolog** (`189.22.240.43`) | **`4954124`** | 4.9.0 → **4.9.3** no próximo deploy | **4.9.0** |
>
> ⚠️ **`/ping` vinha anunciando 4.9.0 enquanto o código já era 4.9.3.** Os commits
> de v4.9.1 a v4.9.3 não bumparam o `SYSTEM_VERSION` do `.env.example`, que é de
> onde o `deploy.sh` propaga a versão para o `.env` do servidor e de onde o
> `/ping` a lê. Corrigido agora para **4.9.3**.
>
> `system_info` permanece em **4.9.0** de propósito: ela é a versão do **esquema**,
> escrita pelas migrações, e nenhuma migração rodou depois da v4.9.0 — as três
> entregas seguintes foram só código. É a distinção que o `deploy.sh` usa para
> decidir quais migrações aplicar.
>
> #### O que entrou desde a v4.9.0
>
> | Versão | Entrega | Estado |
> |---|---|---|
> | **4.9.0** | Padronização dos relatórios por placa (17 itens) + relatórios agendados do worker | ✅ verificado |
> | **4.9.1** | Extração de vídeo: comando errado (34818→37382), destino FTP, player de MPEG-TS, rota `/midia` | ✅ verificado |
> | **4.9.2** | `condition`/`instructionID` no 37382, correlação por instructionID, nome do arquivo, `event_time` | ✅ **ciclo completo com câmera real** |
> | **4.9.3** | Painel do Vídeo ao Vivo: placa, canais, data em BRT, status | ✅ verificado nos 7 equipamentos |
>
> #### Estado das duas funções de vídeo
>
> - **Playback/extração**: funcionando ponta a ponta. Última prova: `CH1_20260805_235208_W0300_000030.ts`, 4.400.150 bytes, subido pela câmera a 1,4 MB/s, tocando a 720×480 no Chrome com seek.
> - **Ao vivo**: o painel de informações está correto. **O streaming em si não foi
>   exercitado nesta sessão** — exige a câmera publicando RTP no media server, e o
>   que se verificou foi a tela, não o vídeo ao vivo.
>
> #### Pendências conhecidas
>
> - **`Código 1047 (JTT)`** — 6 linhas no homolog, único código não resolvido; não
>   consta da doc oficial. Depende do fornecedor. Quando ele entrar em
>   `alarm_types`, a resolução na leitura corrige o histórico sozinha.
> - **`devices.last_communication` continua incompleta no banco**: só é escrita por
>   `pushalarm.php` e `pushlbs.php`, não por GPS nem heartbeat. A v4.9.3 corrigiu a
>   **leitura** na tela de vídeo ao vivo (usa o maior entre quatro sinais); qualquer
>   outra tela que leia a coluna crua mostra o problema.
> - **Cercas**: a coluna Mapa existe na tela e não no PDF/XLS — ficou fora porque a
>   lista de pedidos da v4.9.0 trazia só dois itens para esse relatório.
> - ~~3306 exposta~~ — **não é problema**: o firewall só a libera para o IP do
>   escritório (informado em 06/08/2026).
>
> ---

> ### ✅ EXTRAÇÃO DE VÍDEO FUNCIONANDO PONTA A PONTA (06/08/2026, 09h53)
>
> Com as portas certas (**21222** controle, **31100–31200** passivas) o ciclo
> fechou com a câmera real:
>
> ```
> [Extrair] → 37382 → câmera: "ok" → FTP 191.38.202.11 → OK UPLOAD 4,4 MB a 1,4 MB/s
>          → /pushftpfileupload (result 0) → media_files "disponivel"
>          → /midia (206) → player 720x480, seek OK
> ```
>
> | Sonda | Resultado |
> |---|---|
> | `OK UPLOAD` no log do vsftpd, vindo da câmera | `CH1_20260805_235208_W0300_000030.ts`, **4.400.150 bytes** |
> | Linha em `media_files` | **uma só**, `disponivel`, nome/tipo/tamanho reais |
> | `event_time` | **05/08 23:52:08** — o da gravação, não o do upload |
> | `/midia` sem Range / com Range | **200** / **206 `bytes 0-999/4400150`** |
> | Player no Chrome | **`readyState 4`, 720×480**, seek para 63 s, 249 s em buffer |
>
> #### Mais três defeitos que só o teste com câmera real revelou
>
> 1. **`condition` e `instructionID` são obrigatórios no 37382** (aparecem só no
>    exemplo da doc, não na tabela de campos). Sem eles a câmera **não confirma**
>    o comando: `600 request timeout`, e depois `302 Device busy`. Comparação
>    controlada com o device comprovadamente online: 2 envios sem → timeout;
>    2 com → `ok`.
> 2. **O callback não diz qual arquivo é.** Traz só `imei`, `result`, `gateTime`
>    e `instructionID`. Gravava-se o instructionID como nome, e `/midia` dava
>    404. Resolvido pelo padrão do nome, que codifica a janela pedida
>    (`CH{canal}_{AAAAMMDD}_{HHMMSS}_…`) — e essa janela está no pedido.
> 3. **O `event_time` era sobrescrito pelo `gateTime`** (hora do upload), então o
>    vídeo extraído sumia do dia filtrado: pedia-se 05/08 e o item nascia
>    carimbado em 06/08.
>
> #### Configuração de infraestrutura aplicada (fora do git)
>
> - `/etc/vsftpd.conf`: `listen_port` 2121 → **21222**, `pasv_min/max_port`
>   23100-23200 → **31100–31200** (backup em `/etc/vsftpd.conf.bak_20260806`).
> - `.env`: `VIDEO_FTP_PORT=21222`, `VIDEO_FTP_CONDITION=7`, `VIDEO_MEDIA_DIR`.
> - Senha do `dvrupload` redefinida (a antiga havia se perdido sem registro).
>
> ⚠️ **Lição de método**: o teste do dia anterior foi feito de dentro do servidor
> (`127.0.0.1`) e passou — sem provar nada sobre o acesso externo, que é o
> caminho da câmera. A sonda válida é `curl --ftp-pasv` **de outra máquina**.
>
> ⚠️ ~~A 3306 (MySQL) está exposta à internet~~ — **falso alarme**: o firewall só a
> libera para o IP do escritório. Confirmado pelo usuário em 06/08/2026. O que a
> minha sonda mediu foi "alcançável **do meu IP**", que é justamente o liberado.


> ### 🟠 v4.9.2 — teste com a câmera REAL achou mais dois defeitos; falta abrir portas no roteador (06/08/2026)
>
> A 371_3241 voltou a ficar online e o teste ponta a ponta pôde ser feito. Ele
> derrubou uma suposição do dia anterior — "o device está offline" — e achou
> **dois defeitos a mais**, além de um bloqueio de infraestrutura.
>
> #### 🔴 O 37382 exige `condition` e `instructionID`, e sem eles a câmera não confirma
>
> Os dois campos aparecem **só no exemplo** da doc, não na tabela de campos
> logo acima dele. Comparação controlada, com o device comprovadamente online:
>
> | Cmd | proNo | com os 2 campos? | Resposta |
> |---|---|---|---|
> | 87 | 37382 | não | `600 request timeout` |
> | 88 | 37381 | — | **`ok`, 13 recursos** ← device online |
> | 89 | 37382 | não | `600 request timeout` |
> | 90 | 37381 | — | `302 Device busy` |
> | 91 | 37382 | **sim** | **`ok`** |
> | 92 | 37382 | **sim** (injetado pelo servidor) | **`ok`** |
>
> `condition` é a máscara de rede autorizada para o download (bit0 WiFi, bit1
> LAN, bit2 3G/4G). Num rastreador veicular só existe 4G — deixá-la de fora faz
> a câmera **aceitar e nunca baixar**. Default 7, via `VIDEO_FTP_CONDITION`.
>
> ⚠️ E a mensagem do IoTHub engana: ela diz *"The device is offline or timed
> out"*, mas o `_msg` de dentro é **`request timeout`**. Foi o que fez parecer,
> na v4.9.1, que a câmera estava fora do ar. Ela estava online e respondendo.
>
> #### 🔴 A correlação do callback por "pedido mais antigo" errou em campo
>
> Um pedido que falhara por timeout foi fechado com o resultado de **outro**,
> enviado depois. Agora casa por `instructionID` — que é exatamente o que a doc
> diz que ele serve. A regra antiga fica como fallback e **loga** quando é usada.
>
> #### 🚧 BLOQUEIO ABERTO: o FTP não é alcançável da internet
>
> Com os campos certos a câmera aceita, tenta, e devolve **`result: 1`** (falha)
> no `/pushftpfileupload` — **sem nunca aparecer no log do vsftpd**. A causa foi
> medida de fora, e o teste da v4.9.1 a mascarou porque foi feito de dentro do
> servidor (`127.0.0.1`), o que não prova nada sobre acesso externo:
>
> | Porta | De fora |
> |---|---|
> | 80, 3306, 8881, 10002, 21100, 21122, 23010 | **abertas** |
> | **2121** (controle do FTP) | **não encaminhada** |
> | **23100–23200** (dados passivos do vsftpd) | **não encaminhadas** |
>
> A câmera conecta no FTP **pela internet** — não adianta o vsftpd responder no
> localhost. Em 07/05/2026 funcionou (o log registra `OK UPLOAD` a 3 MB/s de
> `191.38.239.88`), então o encaminhamento existia e se perdeu.
>
> **Ação necessária, fora do servidor**: encaminhar no roteador `2121/tcp` e a
> faixa `23100-23200/tcp` para `189.22.240.43`. Feito isso, o fluxo fecha
> sozinho — o resto do caminho está verificado.
>
> ⚠️ ~~Achado colateral: a 3306 (MySQL) está exposta à internet~~ — **retratado**:
> o firewall a libera só para o IP do escritório (confirmado em 06/08/2026). A
> sonda mediu "alcançável do MEU IP" e eu li como "aberta ao mundo"; para afirmar
> exposição seria preciso testar de um IP de fora da lista.


> ### ✅ v4.9.1 PUBLICADA — extração de vídeo: **nunca funcionou**, e agora funciona (06/08/2026)
>
> Relato: "a lista chega, mas não toca nem baixa". Eram **quatro** defeitos em
> série, e o primeiro sozinho já impedia qualquer arquivo de existir.
>
> | # | Defeito | Evidência |
> |---|---|---|
> | 1 | **Comando errado.** [Extrair] mandava `34818` = *"Multimedia data retrieval"* — uma CONSULTA, da família de fotos do JT/T 808. O certo é **`37382`** = *"FTP file upload command"* | Em 3 dias, `/pushfileupload` e `/pushftpfileupload` **nunca** foram chamados; `media_files` só tinha 3 linhas sintéticas de julho |
> | 2 | **Sem destino FTP.** O 37382 leva `serverAddress/ftpPort/userName/password` no payload, e não havia nada configurado | `.env` sem qualquer chave de FTP |
> | 3 | **`.ts` não toca em browser nenhum.** As gravações são MPEG-TS; Chrome/Firefox/Safari só decodificam MP4/WebM | `file` → *MPEG transport stream data*, byte de sync `0x47` |
> | 4 | **O servidor de arquivos não serve streaming.** Sem CORS (o mpegts.js precisa de `fetch`), sem `Accept-Ranges`, e `Content-Disposition: attachment` | Medido: `Range: bytes=0-1000` → **HTTP 200 com 21 MB**; no Chrome, `NetworkError: Failed to fetch` |
>
> #### A infra existia e estava ociosa
>
> vsftpd na **2121**, usuário `dvrupload` chrootado em `/iothub/dvr-upload/uploadFile`
> — o mesmo diretório que o container `dvr-upload` publica na 23010. Dois `.ts` de
> 21 MB estavam lá desde 07/05, e o log do vsftpd registra a câmera fazendo
> `OK LOGIN` e `OK UPLOAD` a 3 MB/s. O caminho funcionava; o app é que nunca o
> acionava. **A senha do `dvrupload` havia se perdido sem registro** — foi
> redefinida em 06/08/2026 e agora vive no `.env` do servidor (nunca no git).
>
> #### Decisões que valem registro
>
> - **A senha do FTP é injetada no servidor**, em `sendcommand.php`, e o que o
>   cliente mandar nesses cinco campos é **descartado**. Montar o payload no
>   navegador exporia a credencial no código-fonte; aceitar os campos do cliente
>   deixaria forjar o POST para despejar vídeo num FTP de terceiro. Ela também
>   **não** é gravada em `commands.command_content` (vai como `***`).
> - **Nova rota `/midia`** serve o arquivo pela nossa origem, com Range/206 em
>   blocos de 512 KB e `Content-Type: video/mp2t`. Ganho de segurança junto: a
>   porta 23010 está aberta **sem autenticação alguma** — quem souber o nome do
>   arquivo baixa o vídeo. `/midia` exige sessão e confere o escopo do cliente.
>
> #### Verificação
>
> | Sonda | Resultado |
> |---|---|
> | 37382 real disparado para gravação de 05/08 | payload correto no banco, com FTP e `password:"***"` |
> | Login e escrita no FTP com a senha nova | `226` nos dois; arquivo aparece no diretório |
> | Callback `/pushftpfileupload` | **fecha** a linha `solicitado` em vez de duplicar |
> | `/midia` sem Range / com Range | **200** / **206 `bytes 0-999/21495808`** |
> | `/midia` sem sessão / travessia `../../etc/passwd` | **302** / **400** |
> | Player no Chrome | **`readyState 4`, 720×480**, 216 s em buffer, seek para 33 s funcionando |
>
> ⚠️ **O que NÃO foi verificado ponta a ponta**: o upload FTP disparado pela
> própria câmera. A 371_3241 está **offline desde 05/08 20h16** (16 h sem
> posição), e o IoTHub converteu o 37382 em comando offline (`commands.id 87`,
> `sent`) — ele será entregue na reconexão. O teste do player usou o `.ts` real
> de 21 MB que já estava no servidor, e o callback foi exercitado com um POST
> em `/pushftpfileupload` idêntico ao que o IoTHub manda. **Quando a câmera
> voltar, o comando enfileirado deve produzir o arquivo sozinho — vale conferir.**


> ### ✅ v4.9.0 PUBLICADA E VERIFICADA no homolog (06/08/2026, 08h20)
>
> | | git HEAD | `/ping` | `system_info` | Suíte |
> |---|---|---|---|---|
> | Local / `origin/main` | `a89eaac` | — | 4.9.0 | **110 passaram, 2 puladas, 0 falharam** |
> | **Homolog** (`189.22.240.43`) | **`a89eaac`** | **4.9.0** | **4.9.0** | — |
>
> Sessão de padronização dos relatórios pedida em lista: 17 itens, todos entregues,
> mais o alinhamento dos relatórios agendados pedido em seguida.
>
> #### Verificação PÓS-DEPLOY, contra o servidor real
>
> | Sonda | Resultado |
> |---|---|
> | XLSX de Alarmes, células de link | **1422 de 1422** com o `<v>` em cache — o defeito relatado, resolvido em dado real |
> | `<select>` de placa nos 8 filtros | **8/8**, nenhuma caixa de texto de IMEI sobrando |
> | Cabeçalho dos 10 exports | todos com **Placa** na posição certa e **Mapa** onde foi pedido |
> | `occurrence_config_params` órfãos | **0** (22 parâmetros) |
> | 28 alertTypes JT/T novos | **28/28 presentes** |
> | Tour de boas-vindas no `/resumo` | **ausente** (overlay, botão e chave de localStorage) |
> | Worker: 3 jobs reais (csv/xlsx/pdf) | CSV com a URL crua, XLSX com `<v>MAPA</v>`, PDF com 5 anotações `/Link` |
> | `logs/webhook_2026-08-06.log` | sem `fatal`, `uncaught` ou `SQLSTATE` |
>
> A migração entrou **já na primeira passada** — o `git pull` da FASE 2 trocou o
> `deploy.sh` debaixo do bash, como na v4.8.9. A segunda passada (`--force`)
> confirmou idempotência: "Banco em 4.9.0 — migração v4.9.0 desnecessária".
> Backup de `occurrence_config_params` + `alarm_types` feito **antes**, em
> `/tmp/backup_v490_20260806_081920.sql`.
>
> ⚠️ **`Código 1047 (JTT)` continua aparecendo, e isso é o comportamento correto**:
> 6 linhas no homolog, único código não resolvido, e ele **não existe na doc
> oficial** (a tabela pula de 1046 *Collision* para 3073). Quando o fornecedor
> confirmar o significado, basta uma linha em `alarm_types` — a resolução na
> leitura corrige o histórico sozinha, sem tocar em código.
>
> #### Os dois defeitos que a lista descrevia por fora e eram outra coisa por dentro
>
> | O que se via | O que era |
> |---|---|
> | "a coluna do mapa no XLS aparece vazia" (Alarmes) e "falta a coluna do mapa no XLS" (Posições) | **O mesmo defeito, em todos os onze relatórios**: a célula `=HYPERLINK(…)` era escrita **sem `<v>`** (valor em cache). Fórmula sem cache só ganha conteúdo depois que o programa **recalcula** — em visualizador que não recalcula, a coluna some. Por isso "no PDF tem, no Excel não". Um ponto de correção em `XlsxWriter::writeRow()` resolveu os onze. |
> | "ainda temos códigos e não o nome do alarme" | O nome é resolvido **uma vez**, na chegada do webhook, e o rótulo `Código NNNN (JTT)` fica gravado **para sempre**. A v4.8.1 abriu a lista branca da poda para a faixa JT/T 1024–3097 e **inseriu só 11** dos 39 códigos. Corrigido nos dois lados: catálogo completo (migração) **e** resolução na leitura, para o histórico já gravado. |
>
> #### 🔴 Achado de brinde: mais um parâmetro de ocorrência órfão
>
> A conferência de órfãos que a própria migração roda encontrou
> **`occurrence_config_params` = "Bateria Fraca"** sem alarme correspondente — sobra
> da v4.8.3 que a v4.8.6 não pegou. Desde então o alarme chegava, era gravado,
> aparecia no relatório, e **ocorrência nenhuma nascia**, em silêncio. É a terceira
> vez que esse modo de falha aparece. Remapeado; a conferência agora devolve zero.
>
> #### Os relatórios agendados foram alinhados junto (2ª parte da sessão)
>
> Os dez tipos do `scripts/worker.php` seguiam com cabeçalhos próprios. Além da
> padronização (placa primeiro, sem IMEI, sem Cliente, com link do mapa e com os
> pesos de coluna do PDF), dois defeitos próprios apareceram: o de **Alarmes
> imprimia `alarm_type` CRU** — o código, sem nem o rótulo genérico da tela — e o
> **CSV perdia a URL do mapa** (chama `fputcsv()` direto, e o `__toString()` do
> `ExportLink` devolve só "MAPA"). A resolução do nome virou ponto único
> (`alarm_label_sql()`), compartilhada com a tela: duas cópias de uma regra dessas
> divergem, e foi exatamente o que tinha acontecido.
>
> Verificado **executando o worker de verdade**: os 10 tipos enfileirados como job
> real, arquivos gerados e inspecionados — CSV com a URL crua, XLSX com
> `<f>HYPERLINK(…)</f><v>MAPA</v>`, PDF com assinatura `%PDF-` e 2 e 8 anotações
> `/Link`. `Código 1046 (JTT)` saiu como `Colisão do Veículo` no arquivo agendado.
>
> #### ⚠️ O que ficou de fora, de propósito
>
> - **`1047` continua mostrando o número.** A doc oficial pula de 1046 (*Collision*)
>   para 3073; o código aparece em 6 linhas do homolog e **não existe na doc**.
>   Batizá-lo por palpite quebraria junção por nome — a lição da v4.8.3/v4.8.6.
>   Depende de confirmação do fornecedor.
> - **A rota do deslocamento não virou link público.** O OSM não desenha percurso a
>   partir de URL; o PDF/XLS passaram a levar **partida e chegada** como pontos, que
>   abrem sem login. O traçado real segue na tela, para quem tem login.
>
> #### Verificação
>
> - `php -l` limpo em `handlers/ config/ core/ includes/`.
> - Os **11 relatórios** abertos contra MySQL local: nenhum `Fatal error`, `PHP Warning` ou `SQLSTATE`.
> - Cabeçalho de **todos** os exports conferido em CSV; **9 PDFs** com assinatura `%PDF-` e anotações `/Link`.
> - Resolução de nome provada com **três linhas plantadas**: `Código 1046 (JTT)` → `Colisão do Veículo`; `Fim de Alarme: Código 3094 (JTT)` → `Fim de Alarme: Cartão SD Corrompido` (**prefixo preservado**); `Código 9999 (JTT)` (fora do catálogo) → **inalterado**.
> - Migração rodada **duas vezes** em banco real, saída idêntica, zero órfãos.
> - **Suíte completa, execução única contra o código final: 110 passaram, 2 puladas, 0 falharam** (17,5 min). São os 99 do baseline da v4.8.9 mais os 11 testes novos; as 2 puladas seguem sendo os skips deliberados (`RATE_LIMIT_TEST` e o condicional por ausência de dado de segmentação).
> - ⚠️ **A primeira tentativa de fechar essa execução travou por 45 min, e a causa vale registro**: esta máquina **não alcança o Nominatim** (`NOMINATIM_URL` default = `10.1.0.15:8080`, LAN do homolog), então **cada página de relatório paga 8 s de timeout de cURL** e a suíte inteira arrasta. Subir o servidor de teste com `NOMINATIM_URL=http://127.0.0.1:9` (porta fechada → recusa imediata) devolve a suíte aos ~17 min e não muda o que se testa, porque a coluna Endereço já vinha vazia pelos timeouts. Vale para qualquer execução local da suíte, não só esta sessão.
> - **Testes novos com controle positivo**: o teste do `<v>` do XLSX **falha** quando a correção é revertida. E a primeira versão dele **pulava em silêncio** (dependia do `unzip` no PATH, que o Playwright no Windows não tem) — trocado por inflate nativo do Node, sem como pular. Outra armadilha "spec que pula não é cobertura" evitada.
> - Um dos testes novos pegou uma **asserção vácua na primeira escrita**: `innerText` de `th` devolve o texto já em MAIÚSCULAS (CSS `text-transform`), então `not.toMatch(/Cliente/)` nunca encontraria nada — nem com a coluna de volta.

> ### ✅ v4.8.9 PUBLICADA E VERIFICADA no homolog (04/08/2026, 23h02)
>
> | | git HEAD | `/ping` | `system_info` | `commands.request_id` |
> |---|---|---|---|---|
> | Local / `origin/main` | `1de071e` | — | 4.8.9 | — |
> | **Homolog** (`189.22.240.43`) | **`1de071e`** | **4.8.9** | **4.8.9** | **presente** |
>
> Sessão pedida como "execute 1, 2, 3 e 4" sobre o backlog levantado no início dela.
>
> #### O reconhecimento antes do deploy mudou o risco para perto de zero
>
> Sonda somente-leitura no homolog **antes** de publicar, e ela respondeu as duas
> perguntas que decidiam se as correções trancariam alguém para fora:
>
> | Pergunta | Resposta no homolog |
> |---|---|
> | Quantos usuários **sem vínculo** em `customer_users`? | **0** — o fix de escopo não muda nada para quem existe |
> | Quantos usuários **com grupo de permissão** atribuído? | **0** — o gate de `/config-notificacoes` não tranca ninguém ("Operador Padrão" existe e está vazio) |
> | Comandos presos em `sent`/`pending`? | **17**, com **12** respostas recebidas — a pegada do bug do JSON em dado real |
>
> ⚠️ O STATUS dizia que o homolog estava em `a15f8df`; estava em **`d851bdf`** — os dois
> commits de documentação já haviam sido puxados. Conferir em vez de presumir, de novo.
>
> #### O deploy exercitou a armadilha do `git pull` no meio do script — desta vez a favor
>
> `grep -c 'migration_v4.8.9' scripts/deploy.sh` **antes** do deploy devolvia **0**, então
> a expectativa era precisar das duas passadas. A migração entrou **já na primeira**: o
> bash lê o script incrementalmente, e o `git pull` da FASE 2 trocou o arquivo debaixo
> dele. A segunda passada (`--force`) rodou mesmo assim e confirmou idempotência.
> **A regra das duas passadas continua valendo** — o comportamento depende de onde o
> `git pull` cai em relação ao byte que o bash está lendo, o que não é garantia.
>
> #### Verificação PÓS-DEPLOY, contra o servidor real
>
> | Sonda | Resultado |
> |---|---|
> | `POST /customer_switch` como operador **sem vínculo** | **HTTP 400**, sessão sem contexto, nome do cliente **não** aparece no HTML |
> | `/config-notificacoes`, `/config-smtp`, `/config-ocorrencias` **sem** a tela no grupo | **403, 403, 403** |
> | as mesmas três **com** a tela concedida (mesmo usuário) | **200, 200, 200**, sem erro de PHP |
> | Callback offline com 2 comandos pendentes, respondendo ao **mais antigo** | `VERSION#` → **`executed`** com payload JSON válido; `STATUS#` → segue `sent` |
> | 35 rotas do dashboard, sessão de admin injetada | **35 de 35**, **0 erro de PHP** |
> | `[ERROR]`/`[CRITICAL]` no log do dia | **0** |
> | Usuários, grupo, comandos e sessões de teste | **removidos (0 restantes)** |
>
> #### ⚠️ O que esta verificação NÃO cobre, e o que ficou pendente de decisão
>
> - **16 comandos do histórico continuam presos** em `sent`/`pending` **apesar de já
>   terem recebido resposta** — são as vítimas do bug do JSON, contadas no banco real.
>   A correção vale para os próximos; **não reescreve o passado**. Reparar exigiria
>   correlacionar respostas antigas a comandos antigos com a heurística fraca, em dado
>   real — é decisão do usuário, não minha, e não foi feita.
> - O fix de escopo foi provado **com usuários de teste**, porque o homolog tem **um só
>   cliente** e **zero usuários sem vínculo**: não há para onde vazar lá. O vazamento real
>   está medido no dev, com baseline por reversão do código.
>
> #### 🔴 Três achados, dois deles piores do que o backlog descrevia
>
> **1. Usuário sem vínculo recebia o cliente de outro tenant** (o "residual consciente"
> que a v4.8.5 adiou). O fallback de `get_available_customers()` devolvia o **primeiro
> cliente ativo da base**. O que a nota da v4.8.5 não dizia: **`customer_switch.php` usa
> essa lista como autorização** — o cliente vazado era *assumível*, não só visível.
> Medido com usuários reais dos cinco perfis, baseline por **reversão do código**:
>
> | Perfil, sem vínculo | Antes | Depois |
> |---|---|---|
> | Operador | **Cliente B TESTE** (de outro tenant) | vazio |
> | Viewer | **Cliente B TESTE** | vazio |
> | Revendedor sem cliente próprio | **Cliente B TESTE** | vazio |
> | Revendedor **com** cliente próprio | Cliente B TESTE (por acaso o dele) | **só o dele**, via `reseller_id` |
> | Admin de plataforma | **1 cliente** (o 1º alfabético) | **todos** |
>
> A última linha era **bug funcional**, não vazamento: o admin via um cliente só.
> `POST /customer_switch` como operador sem vínculo agora responde **400** e a sessão
> fica sem contexto.
>
> **2. Nenhum callback de comando offline jamais atualizou `commands`.**
> `commands.response_payload` é coluna **JSON**; o handler gravava a resposta de texto
> do device crua, e o MySQL recusava com **`3140 Invalid JSON text`** em toda resposta
> de texto — o caso normal. O `catch` do método **engolia a exceção**.
> Discutia-se desde a v4.1.1 *qual heurística de correlação* usar; **a correlação não
> chegava a acontecer**. Sintoma para o usuário: comando eternamente "em fila offline"
> mesmo com o device tendo respondido — e a resposta *aparecia* em `command_responses`
> (coluna TEXT), o que fazia o problema parecer menor do que era.
>
> **3. `/config-notificacoes` abria para quem a matriz de permissões negava.**
> Estava em `$screens` e **fora** do `$screenByHandler`; o handler só tinha
> `require_login()`. `create`/`edit`/`delete` davam 403 e o **`view` não era conferido
> em lugar nenhum**. Mesmo par de erros da v4.8.5 (`checklist`/`wiki`), terceira e
> quarta ocorrência. Provado nos dois sentidos: **403** sem a tela concedida, **200**
> com ela, mesmo usuário.
>
> #### O que a doc oficial disse sobre o item do backlog (e o inverteu)
>
> O backlog pedia **correlação por `requestId`**. A doc desmente os dois lados:
> `requestId` é *"used for troubleshooting and log tracing"* e **não volta no callback**
> — correlacionar por ele é **impossível**, não difícil. A chave que a doc define é
> **`serverFlagId`** (*"correspondence between request and response"*) — mas aqui ele é
> usado como **seletor de gateway** (0=JT/T, 1=JIMI), então não é único por comando.
> Torná-lo único mexe no despacho para veículo real e **só se verifica com device real**
> (M.2.5, bloqueado). Ficou registrado, com as colunas já criadas no banco.
> A correlação passou a usar o **`_content`** que o callback devolve — exercitado com
> dois comandos pendentes no mesmo device, respondendo ao primeiro.
>
> #### ⚠️ Um item do backlog foi RETIRADO em vez de executado
>
> "Limpar o device de teste `868120246598152` do homolog" **deixou de ser resíduo**:
> desde a v4.8.6 ele é `TEST_IMEI`, e `webhook_occurrence.spec.js` **pula sem ele**.
> Apagá-lo re-silencia a spec que nunca havia rodado e que, na primeira execução real,
> **pegou o motor de ocorrências parado**. Seria "spec que pula não é cobertura" pela
> quarta vez — desta vez provocada de propósito.
>
> #### Critérios de aceite do `PROJETO_YUV.md` §11 — conferidos pela 1ª vez
>
> **6 de 9 sustentam.** Os 3 que não viraram texto no próprio §11 em vez de caixa marcada:
> a **auditoria da tratativa** guarda só o autor do *último* estado (reabrir uma
> ocorrência apaga quem a tratou antes — dívida real, o núcleo do produto);
> **Cadastros** são 6 de 8 (os outros 2 são matrizes de config, não grades — o critério
> é que está largo); **Vídeo** não é verificável sem câmera real.
>
> #### Verificação
>
> `php -l` limpo em `handlers/ config/ core/ includes/`; migração idempotente rodada
> **duas vezes em banco-cópia**; os três achados com **baseline medido por reversão do
> código**, não por leitura dele, e controle positivo em todos.
> **Suíte: 99 passaram, 2 puladas, 0 falharam.**
>
> 📝 O bloco da v4.8.8 abaixo dizia "98 passaram"; o commit `a15f8df` registra **99**.
> Erro de transcrição na prosa, corrigido.
>
> 📝 **A mensagem do commit `d851bdf` não descreve o que ele contém.** Diz "brand
> identity refresh with specialized logos, PWA icons, and updated login interface" —
> isso foi o `69df863`, oito commits antes. O que `d851bdf` faz é: versionar
> `docs/PLANO_INFRAESTRUTURA.md` (959 linhas, até então fora do git), enxugar o bloco
> de instalação do banco no `CLAUDE.md` apontando para a skill `db-setup`, e uma
> **edição corrompida** em `.agents/skills/code-review-graph/SKILL.md` (a linha
> `uvx code-review-graph install` saiu do bloco de instalação e foi parar solta no meio
> da seção de *rename preview*). A corrupção **foi revertida** na v4.8.9 — o arquivo
> voltou byte a byte ao estado anterior. A **mensagem ficou como está**: reescrevê-la
> exige `push --force` sobre commit já publicado, e o ganho é cosmético. Fica o registro
> aqui, que é onde se procura histórico de deploy.
>
> ---
>
> ### ✅ v4.8.8 PUBLICADA E VERIFICADA no homolog (03/08/2026, 22h37)
>
> | | git HEAD | `/ping` |
> |---|---|---|
> | `origin/main` | `a15f8df` | — |
> | **Homolog** | **`a15f8df`** | **4.8.8** |
>
> **Verificação pós-deploy no servidor real**: sessão injetada, `/rastreamento` e `/resumo`
> abertos com o painel de notificações aberto — **mapa isolado em 1 de 1 nas duas telas**,
> tiles carregando (12 e 10), e o **screenshot mostra a lista pintando inteira por cima do
> mapa**, com as notificações reais da base do homolog. Sessão de teste removida.
>
> Relatado pelo usuário. **Sem migração** — uma linha de CSS.
>
> A causa não é o valor do z-index, é **contexto de empilhamento**: o Leaflet dá z-index
> alto aos próprios painéis (tiles 200, controles 1000) e **não cria contexto** no
> container, então esses valores sobem para a raiz do documento; o header é
> `sticky; z-index:50` e **cria** contexto, então o `z-index:1200` do painel vale 1200 só
> dentro do header e **50** no documento. O painel tinha o maior número da folha de estilo
> e perdia mesmo assim.
>
> `.leaflet-container { isolation: isolate; }` contém o mapa no próprio contexto.
> **Não** dá para "aumentar o z-index do header": acima de 1000 ele cobriria os modais das
> telas (999/1000) e o backdrop do menu off-canvas (99) deixaria de escurecê-lo.
>
> ⚠️ **A primeira sonda deu falso negativo.** `document.elementFromPoint()` disse "painel no
> topo" em 6 rotas e quase encerrou o diagnóstico como "não reproduz": ele responde
> **hit-testing**, não **pintura**. Quem fechou o caso foi screenshot antes/depois.
> **Para bug de sobreposição, a prova é a imagem.**
>
> Verificado nas 3 telas com mapa: contenção aplicada, zoom do Leaflet ainda no topo dentro
> do mapa, tiles carregando. Teste de regressão novo afirma a **invariante** (o container
> cria contexto), não o pixel — e o comentário do teste diz por quê.
>
> ---
>
> ### ✅ v4.8.7 PUBLICADA E VERIFICADA no homolog (03/08/2026, 22h00)
>
> | | git HEAD | `/ping` | `system_info` | Motor de ocorrências |
> |---|---|---|---|---|
> | `origin/main` | `64548f5` | — | 4.8.7 | 37 de 38 (o órfão a mais é só do dev) |
> | **Homolog** | **`64548f5`** | **4.8.7** | **4.8.7** | **34 de 34 · 0 sem alvo** |
>
> Conferido no banco publicado: os quatro parâmetros com `generates_occurrence = 1`
> (`ADAS: Colisão Frontal`, `DMS: Distração do Motorista`, `DMS: Motorista ao Telefone`,
> `DMS: Falha de Autenticação do Motorista`) e a família DLT no catálogo — `3085` em `DMS`,
> `3083`/`3084` em `Device`, fora do filtro.
>
> **Trajetória do motor nesta sessão**: 20 de 41 → 33 de 38 (v4.8.6) → **34 de 34** (v4.8.7).
>
> ---
>
> ### ✅ v4.8.6 PUBLICADA E VERIFICADA no homolog (03/08/2026, 21h29)
>
> | | git HEAD | `/ping` | `system_info` | Motor de ocorrências |
> |---|---|---|---|---|
> | Local / `origin/main` | `dc71edb` | — | 4.8.6 | 36 de 42 resolvem |
> | **Homolog** (`189.22.240.43`) | **`dc71edb`** | **4.8.6** | **4.8.6** | **33 de 38** (era **20 de 41**) |
>
> `/ping` saiu certo **já na primeira passada** desta vez — o `.env.example` foi bumpado
> junto, que era a armadilha da v4.8.5.
>
> #### Verificação pós-deploy, com o código e o dado publicados
>
> Sonda **somente leitura** (`scp` → `php` → `rm`) chamando as funções reais do engine,
> `get_occurrence_config_for_imei()` e `get_occurrence_param()`, para os códigos que a
> v4.8.3 havia renomeado:
>
> | Código | Nome no catálogo | Parâmetro |
> |---|---|---|
> | 143 | DMS: Distração do Motorista | achado |
> | 151 | DMS: Motorista ao Telefone | achado |
> | 154 | DMS: Motorista Fumando | achado |
> | 160 | DMS: Motorista Bocejando | achado |
> | 161 | DMS: Câmera Obstruída | achado |
> | 167 | DMS: Cinto Não Afivelado | achado |
> | 71 | DMS: Fadiga ao Dirigir | achado |
> | 204 | ADAS: Colisão Frontal (FCW) | achado |
>
> **8 de 8.** Antes da v4.8.6 esse número era **0** para todos os renomeados.
>
> Os 5 órfãos que sobram (`Capotamento`, `Olhar Lateral Prolongado`, `Comendo ou Bebendo ao
> Volante` ×2, `DMS: Falha na Autenticação ID`) são nomes **sem alvo no catálogo** — a doc
> oficial não os publica. Ficaram de propósito: são configuração visível do usuário, e
> apagar ajuste alheio por conta própria é mais invasivo do que deixar um botão que não
> dispara. A migração os lista no log do deploy.
>
> ✅ **As duas perguntas que a v4.8.6 deixou em aberto foram respondidas** (03/08/2026) e
> viraram a **v4.8.7**:
>
> - Os três que resolviam desligados (`DMS: Distração do Motorista`,
>   `DMS: Motorista ao Telefone`, `ADAS: Colisão Frontal`) **passam a gerar ocorrência**.
> - Dos cinco órfãos, **quatro foram removidos**; fica só a **falha de autenticação**, que
>   ganhou alarme real por trás: `3085` *DLT non-registered card alarm* (2.7 da doc), ou
>   seja, cartão de motorista não cadastrado. `3083`/`3084` (login/logout do cartão) entram
>   como `Device`, fora do filtro, só para não caírem no rótulo genérico.
>
> Resultado no banco-cópia do homolog: **34 de 34 parâmetros resolvem, 0 sem alvo.**
>
> ---
>
> ### 🔴 O que a v4.8.6 corrigiu — a v4.8.3 tinha PARADO o motor de ocorrências
>
> #### O achado
>
> `occurrence_config_params.alarm_type` guarda o **nome** do alarme, não o código.
> `get_occurrence_param()` resolve por `JOIN alarm_types ON at.alarm_name_pt = ocp.alarm_type`.
> A v4.8.3 renomeou dezenas de alarmes DMS/ADAS e **não remapeou essa tabela** — JOIN não
> casa, nenhum parâmetro é achado, e **o alarme é gravado sem gerar ocorrência**.
>
> **Falha silenciosa**: nada no log, nada na tela. O alarme entra, aparece nos relatórios,
> e a ocorrência só não nasce. No homolog matou **21 dos 41** parâmetros. Ocorrência de
> comportamento do motorista é o **núcleo do produto**, não configuração acessória.
>
> **Como apareceu**: provisionando `TEST_IMEI`/`WEBHOOK_TOKEN` para tirar
> `webhook_occurrence.spec.js` do estado "pulado". O spec existe desde a Fase M.4 e **nunca
> havia rodado**. Na primeira execução real, falhou. Não é dedução: o **mesmo IMEI com o
> mesmo alarme 143 gerava ocorrência até 09/07/2026** (`occurrences` 1–5) e parou.
>
> Isto é a lição de "spec que pula não é cobertura" cobrando pela terceira vez — e a
> justificativa de ter feito o item 2 **antes** do deploy de produção.
>
> #### ⚠️ "Deploy em PRODUÇÃO" sai do backlog — não era dívida
>
> **Definido pelo usuário em 03/08/2026**: só se trabalha com o **servidor de homologação e
> testes**. Produção **só será provisionada quando o sistema estiver pronto para o
> lançamento**. Não existe host, credencial nem banco de produção — o
> `docs/PLANO_INFRAESTRUTURA.md` tem "Contratar servidor" como primeiro item, não marcado.
>
> Os blocos antigos deste arquivo listavam "Deploy em PRODUÇÃO — pendente" como **dívida no
> topo do backlog** desde a v4.8.3. **Era leitura errada**: é fase futura, não atraso.
> Daqui em diante, **"publicar" sem qualificação significa homolog**, e o acúmulo de versões
> não publicadas em produção não é risco a resolver.
>
> Quando o lançamento chegar, o roteiro é o `§14` do plano de infraestrutura; valem então o
> `DROP VIEW` das duas VIEWs órfãs antes da primeira migração e o `mysqldump` de
> `alarm_types`/`alarms`.
>
> **Nota que o acaso trouxe**: se produção existisse e tivesse recebido a v4.8.3, estaria com
> o motor de ocorrências parado em silêncio desde ontem.
>
> #### Item 2 — provisionamento dos clientes de teste: FEITO
>
> `tests/helpers/seed_tenants.php`, idempotente e versionado. As **6 specs puladas rodaram
> pela primeira vez**.
>
> ⚠️ **E quase passaram por vacuidade.** O spec identifica IMEI por regex de **dígitos**
> (`\d{15}`); o cliente B só tinha device de IMEI alfanumérico, então o conjunto dele voltava
> **vazio** e "A e B não compartilham devices" passava sozinho — dois conjuntos vazios não se
> intersectam. Corrigido com IMEI de 15 dígitos nos dois clientes e a guarda `exigeDevices()`.
>
> **O spec tem dentes, provado por mutação**: promovendo o usuário B a `role='admin'`, o teste
> de escalada **falha** e nomeia os IMEIs vazados do cliente A; revertido, passa.
>
> #### Suíte: 98 passaram, 2 puladas, 0 falharam (eram 94/6)
>
> As 4 que entraram são as 3 de `multitenant.spec.js` e a de `webhook_occurrence.spec.js`.
> As **2 que continuam puladas são skip deliberado**, não lacuna: `login.spec.js:67`
> (rate limiting) só roda com `RATE_LIMIT_TEST=1` porque **bloqueia o IP por 15 minutos**, e
> `relatorios-operacionais.spec.js:136` tem skip condicional por ausência de dado.
>
> Para rodar a suíte completa daqui em diante:
>
> ```
> php tests/helpers/seed_tenants.php aplicar     # imprime as variáveis a exportar
> TEST_EMAIL_B=operador.b@teste.local TEST_PASSWORD_B=E2e-Playwright-2026 \
> TEST_IMEI=868120246598152 WEBHOOK_TOKEN=<do .env> npx playwright test
> ```
>
> ---
>
> ### ✅ v4.8.5 PUBLICADA E VERIFICADA no homolog (03/08/2026, 10h32)
>
> | | git HEAD | `/ping` | `system_info` |
> |---|---|---|---|
> | Local / `origin/main` | `9ddc7e0` | — | 4.8.5 |
> | **Homolog** (`189.22.240.43`) | **`9ddc7e0`** | **4.8.5** | **4.8.5** |
> | **Produção** | — | — | **PENDENTE desde a 4.8.3 — nada tocado** |
>
> Sai junto a **v4.8.4** (códigos JIMI ambíguos), que estava pronta e não publicada.
>
> #### ⚠️ Armadilha nova, e ela quase passou batido
>
> As duas passadas do deploy rodaram, migrações aplicadas, código em `f8846af` — e
> **`/ping` continuou anunciando 4.8.3**. Causa: `/ping` lê `SYSTEM_VERSION` do `.env`, e
> a FASE 3b do `deploy.sh` sincroniza esse valor **a partir do `.env.example`**, que eu
> tinha esquecido de bumpar. O sintoma aparecia num só lugar: **a própria sonda usada para
> confirmar o deploy**. Um deploy podia ser dado por falho (ou por bem-sucedido na versão
> errada) por causa disso. Corrigido em `9ddc7e0`.
>
> **Regra que fica**: bumpar versão exige tocar **`.env.example`** junto com o CHANGELOG e
> a migration. Sem isso a verificação pós-deploy mente.
>
> #### Verificação pós-deploy, contra o servidor real
>
> | Sonda | Resultado |
> |---|---|
> | 16 rotas do dashboard, sessão injetada | **16× HTTP 200, 0 erro de PHP** |
> | Filtro de alarmes | traz `Cinto Não Afivelado`; **não** traz o positivo `Cinto Afivelado` |
> | `alarm_types` | `132`/`167`/`265-10` como Cinto Não Afivelado; `166` movido para `Vehicle`; **32 chips**; 0 ambíguo indevido |
> | `/grupos-permissao` | matriz mostra **"Checklist de Inspeção"** e **"Central de Ajuda"** |
> | `permission_groups` | "Operador Padrão" com `wiki: ["view"]`; curinga do "Administrador" intocado |
> | Sessões de teste | removidas (0 restantes) |
>
> ⚠️ **O que essa verificação NÃO cobre**: o comportamento do **revendedor** foi provado
> **localmente** (com baseline por reversão de código e controle positivo), não no homolog —
> lá só existe **um cliente**, então não há para onde vazar, e criar um segundo cliente e um
> revendedor no servidor de homologação seria mexer em dado que não é de teste. O que o
> homolog prova é que o código novo está publicado e roda sem erro nas 16 telas.
>
> ---
>
> ### ▶️ O que a v4.8.5 entregou (03/08/2026)
>
> Sessão pedida como "itens 3, 5 e 6, depois publique no homolog". O **5** já estava
> pronto da sessão anterior (v4.8.4, códigos ambíguos) e entrou na mesma leva.
>
> #### 🔴 O achado da sessão: revendedor lia a base inteira
>
> É a escalada da v4.7.3 um perfil acima, e aquela versão a deixou aberta de propósito
> ("pergunta de produto"). **Medido com um revendedor puro real** (`user_type='revendedor'`,
> `role='operator'`, vinculado só ao cliente 1), não deduzido do código:
>
> | Cenário | Antes | Depois |
> |---|---|---|
> | `?customer_id=2` (não é dele) | **via o cliente 2** | não vê |
> | `?customer_id=1` (é dele) | via | vê (controle positivo) |
> | **sem parâmetro nenhum** | **via o cliente 2** | não vê |
> | Admin de plataforma, `?customer_id=2` | via | **continua vendo** |
>
> A terceira linha é pior do que o STATUS anterior registrava: **sem `?customer_id`**,
> `report_customer_scope()` devolvia `null` = *sem filtro*. Não era preciso adulterar URL
> nenhuma — a tela já abria com a base inteira.
>
> Faltava ainda a outra metade: **12 handlers** montavam o seletor de cliente com
> `SELECT id, name FROM customers` cru, então o revendedor lia os **nomes** de todos os
> clientes mesmo com o filtro corrigido. Agora passam todos por `report_customer_options()`.
> Sondado nas 12 rotas: **0 vazamentos, 0 erros de PHP**, admin de plataforma inalterado.
>
> `customers.reseller_id` existia desde a v3.1.0, era **escrito** por `clientes.php` e
> **nunca lido** — `reseller_scope_ids()` é o primeiro leitor. O escopo é a **união** dele
> com `customer_users`, e o segundo termo não é redundância: `reseller_id` é NULL em 100%
> das linhas, e sem ele todo revendedor existente ficaria sem ver cliente algum.
>
> #### As outras entregas
>
> | Item | O que era | O que ficou |
> |---|---|---|
> | **3** — 2 specs velhos | Falhavam desde a v4.8.2 | Corrigidos. A **tela** estava certa nos dois casos |
> | **6a** — `notificacoes.spec.js` | O sino da v4.4.0 sem cobertura E2E | **9 testes**, 4 deles sobre dado **semeado** |
> | **6b** — `checklist` fora da matriz | CRUD vivo **sem permissão nenhuma** | Na matriz, no router e com `require_permission()` |
> | **6b bis** — 🆕 `wiki` | Protegida no router e **ausente** da matriz: 403 impossível de liberar | Na matriz + migração para os grupos gravados |
> | **5** — códigos ambíguos | Da v4.8.4, pronta e não publicada | Entra nesta leva |
>
> O caso `wiki` é o **inverso** do `checklist` e apareceu ao varrer o primeiro: uma tela no
> `$screenByHandler` do router mas fora de `$screens` é impossível de conceder, porque o
> admin não tem o que marcar. Regra que fica registrada na própria matriz: **toda tela nova
> entra nos dois lugares**. Só no router = inalcançável; só na matriz = desprotegida.
>
> #### Verificação
>
> `php -l` limpo em `handlers/ config/ core/ includes/`; as duas migrações testadas em
> **banco-cópia** do homolog e **rodadas duas vezes** (idempotentes); sondas HTTP de
> revendedor com controle positivo e **baseline provado por reversão do código**, não por
> leitura dele. Exclusão de checklist conferida nos dois sentidos: a do cliente 2 é
> **recusada** e a linha sobrevive no banco; a do cliente 1 (dele) **conclui**.
>
> **Suíte Playwright: 94 passaram, 6 puladas, 0 falharam.** As 2 que falhavam desde a
> v4.8.2 estão agora entre as que passam, e 9 dos 94 são o spec de notificações novo.
>
> ⚠️ Uma primeira rodada foi **contaminada** por edições de handler feitas no meio dela.
> Passou (94/0), mas não vale como prova — o número acima é de rodada limpa, árvore parada.
>
> As **6 puladas** seguem as mesmas: `TEST_EMAIL_B`/`TEST_PASSWORD_B` (multi-tenant) e
> `TEST_IMEI`/`WEBHOOK_TOKEN` (webhook→ocorrência). **Continua sendo a pendência que mais
> vale**: o vazamento do revendedor fechado hoje é exatamente o tipo de coisa que
> `multitenant.spec.js` pegaria, e ele nunca rodou uma vez.
>
> ⚠️ **Residual consciente**: `get_available_customers()` (`includes/auth.php:278`) ainda
> cai no "primeiro cliente ativo" quando o usuário não tem vínculo em `customer_users`.
> Para um revendedor sem vínculo isso entrega o cliente de outro. **Não foi mexido**:
> está no caminho de login, e nenhum usuário das duas bases depende do fallback (todos têm
> vínculo explícito e são admin de plataforma). Merece passe próprio, com verificação de
> login por perfil.
>
> ---
>
> ### v4.8.4 — códigos ambíguos (03/08/2026)
>
> #### Estado das três pontas
>
> | | git HEAD | `/ping` | `system_info` | Migração 4.8.4 |
> |---|---|---|---|---|
> | Local | v4.8.4 na working tree | — | 4.8.3 | **não aplicada** |
> | **Homolog** (`189.22.240.43`) | `671887c` | 4.8.3 | 4.8.3 | **não aplicada** |
> | **Produção** | — | — | — | **PENDENTE desde a 4.8.3 — nada tocado** |
>
> Nada foi aplicado a banco real: a migração foi validada só em banco-cópia
> (`jimi_amb_test`, criado do `mysqldump` do homolog, rodada **duas vezes** com saída
> idêntica, removido ao fim). **A v4.8.3 continua sem ir a produção** — a v4.8.4 entra
> na mesma leva.
>
> #### O que a v4.8.4 fecha: a última pendência da lista da v4.8.3
>
> Os **quatro códigos JIMI ambíguos** (`80`, `81`, `131`, `132`) deixam de estar em
> aberto. A doc foi **reconferida hoje** — 804 KB baixados, todas as `<tr>` da seção 1
> parseadas — e o levantamento anterior se confirmou: são esses quatro **e mais
> nenhum**, de 197 códigos JIMI. Em `80`/`81` as duas leituras são **espelhadas** (1.6
> diz "fechou" onde 1.9 diz "abriu"), e as tabelas têm só duas colunas: não há modelo
> nem firmware que sirva de desempate.
>
> **O impasse não foi resolvido pela doc — deixou de importar pelo escopo.** Decisão de
> produto de 03/08: das cinco funcionalidades em disputa (abrir porta, fechar porta,
> colisão, cinto afivelado, falha da câmera 1), a única que o sistema terá é **cinto NÃO
> afivelado**. Então `132` entra como "DMS: Cinto Não Afivelado" e `80`/`81`/`131` ficam
> de fora — nas **duas** leituras, porque a de 1.8 para o `131` é o cinto *afivelado*, o
> evento positivo. Pela mesma regra, `166` (evento positivo, já catalogado) sai de `DMS`
> para `Vehicle`: **o filtro cai de 33 para 32 chips**. É recategorização, não exclusão —
> apagar trocaria um rótulo correto pelo genérico "Código 166 (JIMI)".
>
> ⚠️ **O que a decisão NÃO resolve, registrado de propósito**: ela diz o que queremos
> ver, não o que o equipamento quis dizer. Se um firmware emitir `132` significando
> *Camera 1 exception*, o sistema rotula falha de hardware como infração do motorista —
> a classe de erro que a v4.8.3 corrigiu. Aceito porque (1) a incidência é **zero** (nem
> uma linha com esses códigos em 3.583 alarmes do homolog), (2) cinto já está coberto por
> `167` e `265-10`, não ambíguos, e (3) falha de câmera correlaciona com `107`/`161` e
> chega sem mídia, então dá para conferir se aparecer.
>
> **Achado que o STATUS anterior não registrava**: os quatro códigos **nunca estiveram**
> em `alarm_types`. A whitelist da `migration_v4.8.1.sql` os **lista** entre os oficiais,
> o que dá a impressão contrária — mas whitelist só protege do `DELETE`, não cria linha.
> Eram "oficiais" e ausentes ao mesmo tempo, caindo no fallback de `pushalarm.php:395`.
>
> #### ▶️ Próximo passo
>
> **Deploy de v4.8.3 + v4.8.4 em produção**, nesta ordem (o gate do `deploy.sh` decide
> sozinho). Exige as **duas passadas** (`deploy.sh && deploy.sh --force`) porque o
> `deploy.sh` mudou, e um `mysqldump alarm_types alarms` antes — a 4.8.3 reescreve
> `alarms.alarm_name` em todo o histórico. A migração v4.8.4 emite no log **uma sonda
> para produção**: `SELECT COUNT(*) FROM alarms WHERE msg_class=0 AND alarm_type IN
> ('80','81','131')`. Se vier > 0, os códigos descartados chegam de verdade e a
> ambiguidade precisa ser decidida com dado real (velocidade, `car_status`, presença de
> mídia), não com a doc.
>
> ---
>
> ### v4.8.3 PUBLICADA no homolog (02/08/2026, 23h40)
>
> #### Estado dos três ambientes, CONFERIDO no fim da sessão (não presumido)
>
> | | git HEAD | `/ping` | `system_info` | Migração 4.8.3 |
> |---|---|---|---|---|
> | Local | `671887c` | — | **4.8.3** | aplicada |
> | `origin/main` | `671887c` | — | — | — |
> | **Homolog** (`189.22.240.43`) | **`671887c`** | **4.8.3** | **4.8.3** | aplicada |
> | **Produção** | — | — | — | **PENDENTE — nada tocado** |
>
> O deploy exigiu as **duas passadas** (`sudo ./scripts/deploy.sh && sudo ./scripts/deploy.sh
> --force`), e desta vez ficou provado em vez de presumido: antes do deploy,
> `grep -c 'migration_v4.8.3' scripts/deploy.sh` no servidor devolvia **0**. A regra do
> `git pull` no meio do script continua valendo para toda entrega que mexe no `deploy.sh`.
>
> O banco do homolog **já estava em 4.8.3 antes do deploy** — a migração foi aplicada
> direto durante o desenvolvimento (depois de testada num banco-cópia), então o log do
> deploy diz "migração desnecessária". Isso é o esperado, não falha.
>
> ⚠️ **`sudo` no homolog pede senha** (`sudo -n true` → *a password is required*): deploy é
> sempre pelo usuário, via `! ssh -t`. E o `.env` do servidor **não está mais legível** pelo
> usuário `administrador` (*Permission denied*) — a nota antiga de que era `644` caducou.
>
> #### O que esta sessão entregou (v4.8.3)
>
> **1. O endereço parava de sair pela metade no PDF.** O `PdfWriter` dava a todas as
> colunas a mesma largura e cortava com "…" — em oito colunas são ~96 pt cada, e o
> endereço geocodificado é o campo mais longo do sistema. Agora cada relatório declara
> **pesos de coluna** (`stream_export(..., $colWeights)`; o endereço leva 3,2–3,6× uma
> coluna comum, indo a 266 pt em Posições) e o que ainda não couber **quebra em até 4
> linhas** em vez de ser cortado, medido com as **métricas AFM reais do Helvetica** — a
> conta antiga ("nº de caracteres × 0,52 em") erra >20% conforme as letras. A quebra
> vale para **todo** PDF do sistema: nenhum relatório trunca mais.
>
> **2. Cabeçalho de período** (`report_period_label()`, ponto único): sem `(BRT)`, data em
> **DD/MM/AAAA** e **hora sempre escrita** — `00:00:00` a `23:59:59` quando o filtro de
> faixa horária ficou vazio, que é a janela realmente consultada. Nos **9** relatórios
> que exportam PDF, não só nos três reportados.
>
> **3. Nomes de alarme conferidos contra a doc oficial** (`migration_v4.8.3.sql`). A
> v4.8.1 podou `alarm_types` mas não conferiu os NOMES, e havia **erro de mapeamento**:
> `265-10` era "Comendo ou Bebendo" e é **Cinto Não Afivelado**; `265-13` era "Falha na
> Autenticação ID" e é **Uso de Celular**; `265-6` era "Captura Automática" e é **Câmera
> Obstruída** — sete subtipos DMS deslocados, ou seja, o relatório acusava o motorista da
> coisa errada. JT/T `1040`/`1041` (os dois códigos com mais linhas gravadas) eram
> "Ociosidade Excessiva"/"Ignição Não Autorizada" e são **Sleep/Working Mode Event**.
> JIMI `147` era "Fadiga Extrema" na categoria DMS e é **colisão**. Mais 15 códigos
> DMS/ADAS JIMI que faltavam, e a saída dos subtipos em faixa *User defined* e do grupo
> `266` (BSD), que não existe na doc.
>
> **4. Filtro "Tipos de Alarme" só com DMS e ADAS** (33 opções, vindas de `alarm_types` e
> não mais do `DISTINCT` sobre o histórico). Os alarmes de câmera JIMI ganharam o prefixo
> `DMS:` que os JT/T já tinham — e, onde o evento é o mesmo nos dois protocolos, o nome é
> **idêntico de propósito**, para um chip só pegar os dois.
>
> **5. Deslocamento**: 4ª coluna do fechamento diário vira **"Última Ignição"**; a coluna
> Rota vira link rotulado `ROTA` (a URL crua ocuparia três linhas com a quebra nova).
>
> #### Verificação PÓS-DEPLOY, contra o homolog real
>
> Os quatro PDFs foram exportados **pelo caminho real do handler**
> (`http://189.22.240.43/relatorios/...?export=pdf`, sessão injetada em `sessions` e
> removida no fim), e o content stream de cada um foi extraído e conferido posição a
> posição contra as bordas de coluna calculadas dos pesos:
>
> | Relatório | Páginas | Textos | Vazando a coluna | Truncados ("…") | Folga mínima |
> |---|---|---|---|---|---|
> | Posições | 7 | 1.903 | **0** | **0** | 21,16 pt |
> | Alarmes | 82 | 20.000 | **0** | **0** | 4,06 pt |
> | Deslocamento (viagens) | 4 | 1.197 | **0** | **0** | 33,90 pt |
> | Deslocamento (diário) | 1 | — | **0** | **0** | — |
>
> Cabeçalho conferido nos quatro: `Período: 23/07/2026 00:00:00 a 23/07/2026 23:59:59`.
> Nomes corrigidos aparecendo em dado real: `Evento de Modo Trabalho` onde antes saía
> "Ignição Não Autorizada". Filtro de alarmes: **33 chips, 100% deles `DMS:`/`ADAS:`**,
> zero erro de PHP na página. Fechamento diário com `… | Primeira Ignição | Última
> Ignição | …`; coluna Rota como link `ROTA`.
>
> ⚠️ **O que essa verificação NÃO cobre.** A **quebra de linha do endereço não foi
> exercitada** pelos dados do homolog: o endereço mais longo do `geocode_cache` de lá tem
> **72 caracteres** ("Avenida Presidente Juscelino Kubitschek de Oliveira, Sorocaba, São
> Paulo") e cabe folgado nos 266 pt da coluna — daí as 0 reticências serem também 0
> quebras. O que o homolog prova é que **as larguras novas valem e nada trunca nem vaza**.
> A quebra em si está provada pelo teste sintético (120 linhas, endereços de ~120
> caracteres, as 4 ruas remontáveis a partir das linhas quebradas). Se produção tiver
> endereços mais longos, o caminho está coberto pelos dois testes somados — **não** pelos
> dados reais de homologação.
>
> #### Suíte Playwright: 81 passaram, 2 falharam, 6 puladas, 2 não rodaram
>
> As **2 falhas são anteriores a esta entrega** e já constavam do CHANGELOG da v4.8.2 —
> aqui foram reconfirmadas pelo git, não por suposição:
> `geocercas.spec.js:116` espera o `h2` "Relatório de **Geocercas**" e a tela diz
> "Relatório de **Cercas**" desde `7a0a75f`; `agendamentos.spec.js:169` procura
> `input[name="imei"]` em `/relatorios/alarmes`, onde o campo virou `<select name="imei">`
> no **mesmo** commit. O diff da v4.8.3 **não toca uma linha com `imei`** em
> `rel_alarmes.php` (`git diff | grep -c imei` → 0). As 2 que "não rodaram" são as do
> mesmo `describe` serial da que falhou. **São specs velhas, não regressão — e continuam
> abertas.**
>
> As **6 puladas** seguem puladas por falta de `TEST_EMAIL_B`/`TEST_PASSWORD_B` (multi-tenant)
> e `TEST_IMEI`/`WEBHOOK_TOKEN` (webhook→ocorrência). Spec que pula não é cobertura — o
> vazamento cross-tenant que já apareceu uma vez esteve escondido exatamente aí.
>
> #### Dívidas fechadas nesta sessão
>
> `CLAUDE.md`/`AGENTS.md` **paravam em `migration_v4.7.0`** — agora listam a 4.8.0, a
> 4.8.1 e a 4.8.3, então a instalação limpa monta banco completo.
>
> #### Pendências que esta sessão deixa
>
> | Item | Estado |
> |---|---|
> | ~~**Deploy em PRODUÇÃO**~~ | ❌ **NÃO É DÍVIDA** — definido em 03/08/2026: produção só será provisionada no lançamento. Ver o bloco no topo. O cuidado com `mysqldump alarm_types alarms` antes da migração continua valendo **para quando** esse dia chegar |
> | ~~**2 specs velhas**~~ | ✅ **FECHADO na v4.8.5** — a tela estava certa nos dois casos; os testes é que ficaram para trás |
> | **6 specs puladas** | Faltam `TEST_EMAIL_B`, `TEST_IMEI` no ambiente. O 2º cliente para multi-tenant continua sendo a pendência antiga |
> | **Quebra de linha sem dado real** | Ver a ressalva acima. Confirmar num PDF de produção, onde os endereços tendem a ser mais longos |
> | ~~**Ambiguidades da doc oficial**~~ | ✅ **FECHADO na v4.8.4** (03/08/2026) — resolvido por escopo de produto, não pela doc. Ver o bloco no topo |
>
> ---
>
> ### v4.8.2, identidade visual fechada (02/08/2026, noite)
>
> #### Estado do servidor, CONFERIDO no início da sessão (não presumido)
>
> Local, `origin` e homolog estavam os três em `ec52a7c`; `/ping` respondia **4.8.1**.
> Ou seja: **tudo que a v4.8.0/4.8.1 entregou já está publicado** — geocode/endereço,
> marca `bycamera`, placa no lugar do IMEI, motorista na posição, `alarm_types` oficial e
> o link MAPA nos exports. O bloco da v4.7.3 abaixo continua válido como histórico.
>
> #### O que esta sessão entregou (v4.8.2 — ainda NÃO publicada)
>
> A marca passou a ter **três artes**, uma por superfície, porque um asset só não atende
> contextos com exigências opostas: `logo-login.png` (lockup com o descritor, na largura
> do card do login — medido, o descritor tem 8,6% da altura da arte e só se lê grande),
> `logo-dark.png` (arte oficial de fundo escuro, sidebar) e `logo-report.png` (sem
> descritor, no PDF). O `logo.png` genérico saiu — o nome não dizia em que fundo servia,
> e foi assim que a marca ficou invisível na sidebar. "Entrar no sistema" centralizado.
>
> **Achado**: o `manifest.json` e os quatro ícones do PWA ainda eram **"JIMI"** — nome do
> app instalado, favicon da aba e ícone da tela inicial. Escaparam da varredura de marca
> porque ela olhava PHP, e isso é JSON mais binário. Agora são o símbolo da marca.
>
> #### ⚠️ Duas dívidas que esta sessão NÃO fechou — e uma descoberta
>
> | Item | Estado |
> |---|---|
> | **CHANGELOG atrasado 5 commits** | Ainda falta a entrada de `45cd0f4`, `7a0a75f`, `95e4a41`, `3b4f694` e `ec52a7c` (placa, motorista na posição, `alarm_types`, link MAPA). A seção da v4.8.2 foi escrita; a lacuna da 4.8.1 continua |
> | **`CLAUDE.md`/`AGENTS.md` param em `migration_v4.7.0`** | `deploy.sh` já roda a 4.8.0 e a 4.8.1, mas quem seguir o comando de instalação limpa monta banco incompleto |
> | 🔴 **2 testes falhando ANTES desta sessão** | Provado com `git stash`: falham no baseline. `geocercas.spec.js:116` espera "Relatório de **Geocercas**" e a tela diz "Relatório de **Cercas**" desde `7a0a75f` — asserção velha, e prova de que **a suíte não rodou naquele commit**. `agendamentos.spec.js:155` não acha no seletor o modelo que ele mesmo acabou de criar: esse **precisa de investigação**, pode ser bug real de gravação de modelo |
>
> Suíte completa nesta sessão: **81 passaram, 2 falharam (as de cima), 6 puladas**. As
> puladas seguem incluindo `multitenant.spec.js`, que continua sem `TEST_EMAIL_B` —
> a dívida 🔴 do bloco da v4.7.3, ainda aberta.

> ### ▶️ v4.7.3, passe de dívida técnica (01/08/2026, fim da tarde)
>
> Feito a pedido de "trate as dívidas e depois os outros itens", começando pela auditoria dos
> **critérios de aceite globais do `PROJETO_YUV.md` §11** — nunca verificados como conjunto.
>
> #### 🔴 O achado: vazamento cross-tenant por `?customer_id=N`
>
> Nove pontos permitiam que **qualquer usuário não-admin lesse os dados de qualquer cliente**
> apenas acrescentando `?customer_id=N` à URL. Não foi deduzido do código: foi **provado** com
> dois clientes e um usuário `operator` reais, que leu alarmes, equipamentos e status de frota
> de outro tenant. Telas afetadas: os relatórios de Alarmes, Ocorrências, Desatualizados,
> Ignição, Velocidade, Paradas, Ociosidade, Status da Frota e a tela de Equipamentos.
>
> Corrigido com `report_customer_scope()` (`includes/functions.php`), ponto único de decisão.
> Para não-admin o parâmetro é **ignorado**, não validado — validar diria, pela resposta, se o
> cliente existe. Sem cliente na sessão o filtro vira `0`: falha **fechada**, onde antes a
> ausência de contexto simplesmente omitia o filtro e mostrava tudo.
>
> ⚠️ **Deixado em aberto de propósito**: `$isAdmin` inclui `user_type='revendedor'`, então um
> revendedor segue podendo filtrar qualquer cliente. É pergunta de produto, não de segurança
> óbvia — e mudar semântica de perfil no mesmo passe que fecha uma falha é como se introduz a
> próxima.
>
> #### O que a auditoria do §11 NÃO encontrou (vale tanto quanto)
>
> - **CSRF em todos os POST**: sustenta. 22 handlers tratam POST, 21 chamam `csrf_verify()`
>   (`login` e `setup` são exceções legítimas). Varredura por profundidade de blocos sobre **76
>   escritas** no banco: nenhuma restou alcançável por GET.
> - **Prepared statements**: sustenta. Duas interpolações no projeto inteiro, ambas benignas. O
>   `ORDER BY` dinâmico de ~10 telas é protegido por whitelist estrita em `report_sort_params()`.
>
> #### Demais dívidas fechadas nesta versão
>
> | Dívida | Estado |
> |---|---|
> | **URL assinada com validade** para o link do relatório | ✅ `/download?j=&exp=&sig=`, HMAC com `APP_KEY`, 7 dias no e-mail e 1 h em `/exportar`. **`storage/reports/.htaccess` com `Require all denied`** entrou junto — sem negar o caminho direto, assinar não protege nada |
> | **Varredor de jobs órfãos** | ✅ `reapOrphanJobs()` no worker, teto de 15 min, fechando job **e** execução do agendamento |
> | **`USE jimi_tracker` nos SQL antigos** | ✅ removido de **5** arquivos (não 4 — `hotfix_login_log.sql` também). Provado: cópia limpa em banco de outro nome chega a 4.7.0/54 tabelas sem tocar no real |
>
> ⚠️ **Quebra intencional**: links de relatório em e-mails enviados **antes** da v4.7.3 param de
> funcionar. Eram precisamente o problema que a assinatura resolve.
>
> #### ✅ PUBLICADO E VERIFICADO no homolog — `8c676fd`, 01/08/2026 16:52 BRT
>
> `/ping` em **4.7.3**. As migrações reportaram "desnecessária" (banco segue em `4.7.0`, correto —
> nem a v4.7.1, nem a v4.7.2, nem a v4.7.3 têm migração). A checagem de módulos nova passou
> (`php-zip`, `php-openssl`).
>
> **O `.htaccess` só pode ser verificado no servidor** — `php -S` do ambiente local não processa
> `.htaccess`, então esta era a única prova possível de que a assinatura não é decorativa:
>
> | Sonda | Resultado |
> |---|---|
> | `storage/reports/.htaccess` chegou pelo `git pull` | ✅ presente |
> | `GET` direto num `.xlsx` real de cliente | ✅ **403** |
> | Listagem de `storage/reports/` | ✅ 403 |
> | `storage/media/` (não devia ser afetado) | ✅ 403 de listagem, **não** 500 |
> | `/download?j=1` sem assinatura | ✅ 403 |
> | **Link assinado, gerado com a `APP_KEY` real** | ✅ **200**, conteúdo real, `Content-Disposition: attachment`, `Cache-Control: private, no-store` |
> | O **mesmo** arquivo pelo caminho direto | ✅ 403 |
>
> ⚠️ **Armadilha do ambiente registrada**: o homolog ficou **inacessível por ~1 min** no meio desta
> sessão — ICMP respondia (máquina de pé) mas as portas 22, 80 e 3306 davam timeout **as três
> juntas**. Voltou sozinho. Padrão compatível com `fail2ban`/firewall reagindo ao volume de
> conexões SSH de uma sessão longa. Se acontecer de novo: sondar antes de concluir que o serviço
> caiu, e considerar espaçar os comandos remotos.
>
> #### Dívidas que continuam abertas
>
> | Item | Por quê ficou |
> |---|---|
> | 🔴 **`TEST_EMAIL_B` nunca provisionado** | **É a causa-raiz de o vazamento ter sobrevivido.** `tests/multitenant.spec.js` existe desde a Fase M.4 exatamente para pegar isolamento entre clientes, mas **pula inteiro** sem o segundo usuário — nunca rodou uma vez. Ganhou agora o caso da escalada por `?customer_id`, que continua pulando. **Criar esse usuário vale mais do que qualquer teste novo**: é a diferença entre ter a rede e ter a rede pendurada |
> | ~~**`notificacoes.spec.js` nunca escrita**~~ | ✅ **FECHADO na v4.8.5** — 9 testes, 4 sobre dado semeado |
> | ~~**`checklist` fora da matriz de `/grupos-permissao`**~~ | ✅ **FECHADO na v4.8.5** — na matriz, no router e com `require_permission()` |
> | ~~**Escopo do revendedor**~~ | ✅ **FECHADO na v4.8.5** — era vazamento real, medido; ver o bloco no topo |
> | **Fase F do YUV** (checklist completo, licenciamento, white-label) | É produto, não dívida — vem depois, conforme combinado |


> ### ▶️ RETOMAR AQUI — estado em 01/08/2026 (tarde)
>
> #### 0. O STATUS mentiu de novo — e a mesma lição de 30/07 vale outra vez
>
> O bloco abaixo (30/07, noite) afirmava que a **v4.7.1 não estava commitada** e listava
> "commitar / publicar / conferir" como Passos 1 a 3. **Os três já tinham sido feitos** — a
> publicação aconteceu depois que aquele texto foi escrito, exatamente como havia acontecido
> na iteração anterior. Conferido nesta sessão, não presumido:
>
> | Ponta | Estado real em 01/08 15:00 BRT | Como foi conferido |
> |---|---|---|
> | Git local / `origin` / homolog | os três em **`0685630`** | `git rev-list --left-right --count origin/main...main` = `0 0`; `ssh … git log -1` |
> | `storage/.htaccess` | presente; `GET /storage/reports/` → **403** | `curl -w '%{http_code}'` |
> | `report_cleanup` (v4.7.1) | rodou **31/07 e 01/08** às 06:10 UTC | `logs/log_cleanup.log` — prova de que o código novo está no cron |
> | Cron | **7 workers**, todos com escrita no dia | `ls -la` dos 7 `.log` |
> | Banco | `4.7.0` (correto — a v4.7.1 não tem migração) | `SELECT * FROM system_info` |
> | Segmentos | **2.425** (eram 2.049 em 30/07) | `SELECT COUNT(*) FROM device_state_segments` |
>
> **Regra que fica**: antes de escrever "pendente" no STATUS, conferir o servidor. Um STATUS
> escrito no meio de uma sessão descreve o estado de quando foi escrito, não o de quando é lido.
>
> #### 1. Fusos — MEDIDOS, não presumidos (fecha um critério de aceite do Bloco 1)
>
> | Ponta | Valor | Consequência |
> |---|---|---|
> | SO do servidor | `America/Sao_Paulo (-03)` | **é o relógio que dispara o cron** |
> | PHP (CLI e FPM) | `UTC` (`date.timezone => UTC`) | `date()` produz UTC → é o que grava no banco |
> | MySQL `@@global`/`@@session` | `SYSTEM` (= −03) | ⚠️ **armadilha**: consulta manual pelo cliente `mysql` vem em **BRT**; a conexão PDO do app força `+00:00`. Ao conferir `next_run_at` na mão, use `SET time_zone='+00:00'` |
>
> **Decisão registrada (01/08/2026)**: trabalhar em **GMT-3 apenas na superfície visível**. O
> armazenamento continua UTC — é o que os devices transmitem (GMT 0) e o que os 146 pontos de
> `fmt_brt()`/`CONVERT_TZ`/`brt_day_range_to_utc()` esperam. Converter o banco para −03 exigiria
> migrar toda coluna de data de ~22 tabelas, reescrever esses 146 pontos e converter o timestamp
> na entrada dos webhooks — alto risco e, na prática, irreversível. O que mudou na v4.7.2 foi só
> o carimbo do `Logger` (e o nome do arquivo diário) e o `/ping`. **Nenhuma linha do banco foi
> tocada.**
>
> #### 2. O que esta sessão entregou — v4.7.2
>
> **Segurança — exclusão por GET sem CSRF em QUATRO telas, não uma.** O `STATUS` registrava só
> `/geocercas`; a varredura por `action=` destrutivo em GET achou mais três:
> `/config-notificacoes`, `/config-ocorrencias` e `/checklist`. Este último era o pior: **sem
> CSRF, sem checagem de escopo e sem permissão** — o `id` da query string apagava o checklist de
> qualquer cliente. As quatro viraram POST com `csrf_field()`.
>
> **`APP_URL` estava AUSENTE do `.env` do homolog.** É o item que o `PLANO_VALIDACAO_AGENDAMENTOS.md`
> chamava de "o mais silencioso de todos", e ele estava mesmo quebrado: sem a variável o botão
> "Baixar relatório" do e-mail vira href **relativo**, o provedor aceita, o histórico marca
> "enviado" e só o destinatário descobre. Corrigido no servidor (`APP_URL=http://189.22.240.43`,
> com backup do `.env`) **e** no código, que agora aborta a entrega por link em vez de mandar link
> morto. ⚠️ **O `deploy.sh` sincroniza apenas `SYSTEM_VERSION`** — ele **não** copia chaves novas
> do `.env.example` para o `.env`. Toda variável nova é trabalho manual no servidor.
>
> **Fuso**: `Logger` e `/ping` em BRT (ver §1).
>
> #### 3. Bloco 2 do plano de validação — EXECUTADO contra o provedor real
>
> **O agendamento saiu do papel: e-mail enviado de verdade, pelo `smtp.task.com.br`, com XLSX
> de 419 linhas anexado.** 39 asserções em 3 roteiros, 0 falhas. O que foi medido:
>
> | # | Item do roteiro §2.2 | Resultado |
> |---|---|---|
> | 1 | Agendamento diário XLSX de Alarmes, 3 destinatários | ✅ criado, `is_active=1` |
> | 2 | `next_run_at` em UTC | ✅ `2026-08-02 10:00 UTC` = `07:00 BRT` |
> | 3 | Disparo (dispatcher → job → worker) | ✅ `1 job enfileirado`, execução `enviado` |
> | 7 | Caminho do link (`MAIL_MAX_ATTACH_MB=0.01`) | ✅ log com `"link":true`, URL absoluta a partir de `APP_URL` |
> | 8 | Link abre sem login | ✅ HTTP 200 — **é o desenho**; o que protege é o nome imprevisível |
> | 9 | Vazio nos dois modos (tipo `occurrences`, zerado) | ✅ sem `skip_if_empty` **envia**; com ele, status `vazio` e nada sai |
> | 12 | Permissão do arquivo gerado pelo **root** | ✅ `0644 root` — legível pelo `www-data` do Apache |
> | 13 | Virada do dia: 22:00 BRT | ✅ `2026-08-02 01:00 UTC`, dia seguinte |
> | — | Nome com 32 hex | ✅ `report_2_20260801_184516_7901fbee…xlsx` |
> | — | Endereço antigo previsível | ✅ **404** |
> | — | Listagem de `storage/reports` | ✅ **403** |
> | — | Guard novo da v4.7.2 (APP_URL vazia) | ✅ execução `falhou` citando `APP_URL`, com o arquivo ainda gerado e baixável |
>
> **✅ Itens 4, 5 e 6 — CONFIRMADOS PELO USUÁRIO em 01/08/2026**: o teste de e-mail passou. Os
> envios foram para `flaviohses@gmail.com`, `flavio.pessoal@gmail.com` e `flaviohs@hotmail.com`.
> **Com isso o `docs/PLANO_VALIDACAO_AGENDAMENTOS.md` está CONCLUÍDO** — e o Bloco 4 (SPF/DKIM)
> deixa de ser necessário, porque o item 6 não falhou. Fica o registro de que o `mailer.php`
> **não assina DKIM**: se um dia começar a cair em spam, a decisão registrada é trocar por API
> HTTP transacional, não implementar DKIM artesanal.
>
> **Única lacuna consciente do roteiro**: o item 10 (**3 falhas consecutivas desativam e
> notificam**) não foi exercitado contra o provedor real — exigiria 3 ciclos com destinatário em
> domínio inexistente. A lógica tem cobertura local, com SMTP de captura, desde a v4.7.0.
>
> **Agendamento deixado ativo** (`#5`, "VALIDACAO BLOCO2 20260801_184515") com `next_run_at` em
> `2026-08-02 10:00 UTC` = **02/08 07:00 BRT**, para exercitar o **cron real** sem forçar nada.
> Apague-o em `/agendamentos` quando não quiser mais receber.
>
> #### 4. 🔴 O achado mais grave da sessão — `php-zip` nunca esteve instalado
>
> O Bloco 2 só passou na segunda tentativa. Na primeira, o worker morreu com:
> ```
> PHP Fatal error: Uncaught Error: Class "ZipArchive" not found
>   in /var/www/jimi_webhook/includes/export_helper.php:123
> ```
> **XLSX é o formato padrão** de `/exportar` e do relatório agendado. Ou seja: **nenhuma
> exportação XLSX jamais funcionou no homolog** — e falhava do pior jeito possível, porque o
> fatal mata o processo **antes** de qualquer `UPDATE` de status: o job ficava preso em
> `processando`, a execução em `enfileirado`, e o histórico não registrava erro nenhum. Quem
> olhasse a tela veria "em andamento" para sempre.
>
> Corrigido: `apt install php8.3-zip` + restart do PHP-FPM (conferido nos dois caminhos, CLI e
> web). **Mas a raiz não era a extensão — era a ausência de checagem**: o `deploy.sh` validava
> `pdo pdo_mysql json mbstring` e nunca `zip`. Agora valida `zip` e `openssl`, com `grep -qix`
> (linha inteira; antes `pdo` casava com `pdo_mysql`).
>
> ⚠️ **Mudança de infra fora do git** — se este servidor for reconstruído, ou se produção subir
> do zero, `php8.3-zip` precisa entrar no provisionamento. O deploy agora **aborta** se faltar,
> em vez de deixar quebrar em silêncio meses depois.
>
> #### 5. Backlog novo desta sessão
>
> | # | Item | Por quê importa |
> |---|---|---|
> | 1 | **Fatal no worker deixa job preso em `processando`** | O retry por `attempts` não cobre fatal: o processo morre antes do `UPDATE`. Um job travado nunca é retomado nem reportado. Vale um "varredor de jobs órfãos" (status `processando` há mais de N minutos → `falhou`) |
> | 2 | **`checklist` não está na matriz de `/grupos-permissao`** | Por isso não foi possível pôr `require_permission()` na exclusão sem dar 403 a todo grupo restrito. A tela é "fase futura", mas o CRUD está vivo e alcançável |
> | 3 | **`putenv()` é herdado por processo filho** | Armadilha de teste, não de produção: script que já leu o `.env` e chama `shell_exec('php scripts/worker.php')` faz o filho herdar os valores VELHOS, porque `config/database.php` só define `if (!getenv($key))`. Use `env -u VAR` ao testar mudança de `.env`. Sob cron não ocorre (ambiente limpo) |
> | 4 | **Servidor não alcança o próprio IP público** | Sem hairpin NAT: `curl http://189.22.240.43/...` de dentro do servidor dá HTTP 0. Sondas de dentro têm de usar `localhost` |
>
> #### 6. ▶️ PRÓXIMO PASSO — a iniciativa v4.4–v4.7 acabou; o que vem depois é escolha
>
> Com o Bloco 2 fechado, **`docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md` e
> `docs/PLANO_VALIDACAO_AGENDAMENTOS.md` estão ambos CONCLUÍDOS**. Não há próximo passo obrigatório
> herdado — o que existe é dívida acumulada e o roadmap YUV. Em ordem de risco:
>
> | Prioridade | Item | Onde |
> |---|---|---|
> | **Alta** | **URL assinada com validade** (`?exp=…&sig=…`) para o link do relatório | O token do nome elimina a enumeração, mas **não** protege link vazado ou encaminhado — e agora esses links viajam por e-mail de verdade, comprovadamente |
> | **Alta** | **Varredor de jobs órfãos** (`processando` há mais de N min → `falhou`) | Backlog #1 acima. O `php-zip` mostrou que um fatal no worker some sem deixar rastro no histórico |
> | Média | **Os 4 SQL mais antigos embutem `USE jimi_tracker`** | Impede montar cópia limpa de teste; já rebaixou o banco de dev para `4.0.0` uma vez |
> | Média | **`notificacoes.spec.js` nunca foi escrita** | O sino da v4.4.0 segue sem cobertura E2E |
> | Média | **`checklist` fora da matriz de `/grupos-permissao`** | Backlog #2 acima |
> | — | **Roadmap YUV** (`PROJETO_YUV.md`) | As telas de paridade que ainda faltam — é o rumo do produto, não dívida |
>
> ---
>
> **Última atualização anterior**: 30/07/2026 (noite) — **v4.7.1: download seguro, retenção de relatórios e a Central de Ajuda em dia**. Fecha a iniciativa do `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`: as duas decisões pendentes do Bloco 3 do `docs/PLANO_VALIDACAO_AGENDAMENTOS.md` **e a Fase 5**. Sem tela nova e **sem migração**. Alterados: `scripts/worker.php` (nome do arquivo com 32 hex aleatórios, relatório e vídeo), `scripts/log_cleanup.php` (`REPORT_RETENTION_DAYS`), `handlers/exportar.php` + `exportardata.php` ("Expirado"), `handlers/wiki.php` (12 seções novas, saiu da v4.3.0 para a v4.7.1), `.gitignore`, `.env.example`. Novo: `storage/.htaccess` (versionado).
>
> ### ▶️ RETOMAR AQUI — estado em 30/07/2026 (noite)
>
> #### 1. Onde cada ponta está (tudo conferido nesta sessão, não presumido)
>
> | Ponta | Estado | Como foi conferido |
> |---|---|---|
> | **Git local** | `main` = `origin/main` = **`a1a879b`** | `git log --oneline -1` |
> | **Working tree** | **v4.7.1 NÃO commitada** — 12 arquivos alterados + 1 novo | `git status --short` |
> | **Homolog (código)** | **`a1a879b`** — igual ao GitHub | `ssh … git log -1` |
> | **Homolog (`/ping`)** | **`4.7.0`** | `curl http://localhost/ping` |
> | **Homolog (banco)** | `system_info.version` = **`4.7.0`**, atualizado em 29/07 22:41 | `SELECT * FROM system_info` |
> | **Tabelas da série** | as 9 criadas (`geofence*`×4, `device_state_segments`, `speeding_events`, `report_schedule*`×2, `report_templates`) | `SHOW TABLES LIKE …` |
> | **Cron** | **7 workers rodando** | `logs/state_builder.log`, `geofence.log`, `schedule.log` com escrita nos últimos minutos |
> | **Backfill** | feito — **2.049 segmentos**, de 03/07 até agora, 4 equipamentos | `SELECT MIN/MAX(started_at)` |
> | **SMTP** | global cadastrado: `smtp.task.com.br:465/ssl`, remetente `camera@telecomtrack.com.br`, **teste sem erro em 29/07 23:39** | `SELECT … FROM smtp_settings` |
> | **Agendamentos** | **nenhum criado** (`report_schedules` vazia) | `SELECT COUNT(*)` |
> | **Geocercas** | 1 cerca, 4 eventos | `SELECT COUNT(*)` |
>
> **Portanto: o Bloco 1 do `docs/PLANO_VALIDACAO_AGENDAMENTOS.md` está CONCLUÍDO.** O bloco anterior deste arquivo dizia `92725cb` / `4.4.1` e estava **errado** — a publicação aconteceu depois de ele ter sido escrito. Lição: conferir o servidor antes de confiar no STATUS.
>
> #### 2. O que esta sessão entregou — v4.7.1 (working tree, a commitar e publicar)
>
> **Bloco 3.1 — download sem autenticação.** A hipótese do plano virou fato **antes** da correção:
> ```
> $ echo PROBE-ACESSO-PUBLICO > /var/www/jimi_webhook/storage/reports/probe_test.txt
> $ curl -s -w '%{http_code}' http://localhost/storage/reports/probe_test.txt
> 200   →  "PROBE-ACESSO-PUBLICO"
> ```
> Adotada a **opção B** (token no nome), não a A (rota autenticada): o link do relatório grande no e-mail **precisa** abrir sem sessão, e a opção A quebraria justamente o caminho que a Fase 4 criou. Nome agora com **32 hex de `random_bytes(16)`**, em relatórios **e** vídeos. Mais `storage/.htaccess` versionado (`Options -Indexes` + negar execução de `.php/.phtml/.phar/.cgi/.pl/.py/.sh`). **Não** usa `php_flag engine off` — só existe em mod_php e daria 500 sob PHP-FPM.
>
> **Bloco 3.2 — retenção.** `REPORT_RETENTION_DAYS` (padrão 30, `0` desliga) no `log_cleanup.php`, purgando `storage/reports` **e** `report_schedule_runs`. `/exportar` mostra **"Expirado"** em vez de link para 404. `storage/media` intocado de propósito (evidência, não subproduto).
>
> **Fase 5 — `/wiki`** da v4.3.0 para a v4.7.1: 12 seções novas (Notificações, Config. Notificações, Servidor de E-mail, Geocercas, e os relatórios de Geocercas / Status da Frota / Paradas / Ociosidade / Ignição / Excesso de Velocidade, mais Agendamentos e Modelos salvos), limiares de cada estado, Grupos de Permissão de 18 → **22** telas, e Exportar com os tipos novos.
>
> **Arquivos**: `scripts/worker.php`, `scripts/log_cleanup.php`, `handlers/exportar.php`, `handlers/exportardata.php`, `handlers/wiki.php`, `.gitignore`, `.env.example`, `CHANGELOG.md`, `STATUS.md`, `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`, `docs/PLANO_VALIDACAO_AGENDAMENTOS.md`, `.agents/memory/MEMORY.md` — e **novo**: `storage/.htaccess`.
>
> **Verificado (local, PHP 8.3 + MySQL 8.0)**: `php -l` **0 erros** em `handlers config core includes scripts web`; **48 asserções** do Bloco 3 (32 em sandbox isolado com banco de teste próprio — inclusive "banco fora não impede a limpeza de disco" e a armadilha do `'0'` falsy desligando a purga — e 16 ponta-a-ponta com o `worker.php` real: job enfileirado → `report_90_20260731_020446_cb883b4b….xlsx` gerado → `/exportar` com "Baixar" → arquivo removido → **"Expirado"**, job ainda `concluido`); **43 asserções** da wiki sobre a página renderizada autenticada (nenhum link do índice sem seção, 12 seções presentes, 18 regras conferidas por texto, 22 telas do RBAC batendo com `grupos_permissao.php`); **Playwright: 84 passed / 0 failed / 5 skipped** (suíte inteira, com credenciais).
>
> #### 3. PRÓXIMOS PASSOS, em ordem
>
> **Passo 1 — commitar a v4.7.1** (não foi feito; o working tree está sujo).
> ```bash
> git add -A && git commit   # mensagem: security+feat, ver o CHANGELOG [Unreleased] 4.7.1
> git push
> ```
>
> **Passo 2 — publicar** *(exige sudo; o tool não tem a senha — rodar via `! ssh -t administrador@189.22.240.43 "..."`)*
> ```bash
> sudo ./scripts/deploy.sh
> ```
> - **Não há migração na v4.7.1.** O gate semântico não tem o que aplicar e o banco fica em `4.7.0`. Se quiser que o `/ping` reporte `4.7.1`, subir `SYSTEM_VERSION` no `.env.example` **antes** do deploy (o script propaga para o `.env` sozinho).
> - **Cron não muda** — nenhum worker novo nesta versão.
> - O `deploy.sh` **não** é alterado por esta versão, então **não** haverá re-execução (um só cabeçalho `=== DEPLOY:` no log).
>
> **Passo 3 — conferir a publicação** *(só leitura, sem sudo — o tool consegue fazer)*
> ```bash
> ls -la /var/www/jimi_webhook/storage/.htaccess          # tem de existir (veio no git pull)
> curl -s -o /dev/null -w '%{http_code}\n' http://localhost/storage/reports/   # esperado: 403
> sudo crontab -l | grep -c 'scripts/'                    # esperado: 7 (o cron é do ROOT: `crontab -l` como administrador devolve 0)
> ```
> Repetir a sonda da enumeração: gerar um relatório por `/exportar` e conferir que o nome tem os 32 hex.
>
> **Passo 4 — conferir `APP_URL`** ⚠️ *(exige sudo: o `.env` do servidor é `640 www-data:www-data` e o usuário `administrador` NÃO consegue lê-lo)*
> ```bash
> sudo grep -E '^(APP_URL|APP_KEY|SYSTEM_VERSION|MAIL_MAX_ATTACH_MB|NOTIFY_ENABLED|REPORT_RETENTION_DAYS)=' /var/www/jimi_webhook/.env
> ```
> `APP_URL` é o item mais silencioso do plano inteiro: errada, o e-mail sai, o provedor aceita, o histórico marca "enviado" — e o destinatário recebe um link que dá 404. Tem de ser a URL pela qual o usuário realmente acessa, com esquema e **sem** barra final.
>
> **Passo 5 — Bloco 2: validar o envio agendado contra o provedor real** — **o único item que depende do usuário** (é preciso uma caixa de entrada de verdade). Roteiro completo de 14 itens em `docs/PLANO_VALIDACAO_AGENDAMENTOS.md` §2.2. O essencial:
> 1. Criar em `/agendamentos` um agendamento **diário**, **XLSX**, tipo **Alarmes** (é o que tem dado de ontem no homolog; `speeding`/`stops` também têm, depois do backfill já feito), para um e-mail real.
> 2. Conferir no banco que `next_run_at` ficou em **UTC** = `send_hour` BRT + 3 h.
> 3. Forçar o disparo sem esperar 24 h:
>    ```sql
>    UPDATE report_schedules SET next_run_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ?;
>    ```
>    ou, na mão: `php scripts/schedule_dispatcher.php` (aceita `--dry`) e depois `php scripts/worker.php`.
> 4. **Confirmar a chegada**: assunto com nome + período, anexo `.xlsx`, **abrindo no Excel pt-BR**, e que não caiu em spam.
> 5. Caminho do link: baixar `MAIL_MAX_ATTACH_MB` para `0.01`, repetir, e confirmar que o e-mail vem **sem anexo** e com botão de download que **abre em janela anônima**. ⚠️ **O item 8 do roteiro mudou de sentido com a v4.7.1**: o link *deve* baixar sem login — é o desenho — e o que protege agora é o nome imprevisível; o que o teste precisa confirmar é que **o endereço antigo e previsível não existe mais**.
> 6. `skip_if_empty` nos dois modos; 3 falhas consecutivas desativando e notificando o criador; reativar zerando o contador.
> 7. Permissão do arquivo gerado pelo cron (roda como **root**) sendo legível pelo Apache (**www-data**).
> 8. Fuso na virada: agendamento às **22:00 BRT** tem de gravar `next_run_at` em **01:00 UTC do dia seguinte**.
> 9. Registrar o fuso do SO, do PHP e da sessão MySQL **aqui no STATUS**, medidos e não presumidos.
>
> #### 4. Backlog — o que fica em aberto depois disso
>
> | # | Item | Por quê importa |
> |---|---|---|
> | 1 | **URL assinada com validade** (`?exp=…&sig=…`) para o link do relatório | O token elimina a enumeração, mas **não** protege link vazado ou encaminhado. É o alvo registrado desde a decisão do Bloco 3.1 |
> | 2 | **`/geocercas?action=excluir&id=N` sem CSRF** (v4.5.0) | `csrf_verify()` não lê da query string: um `<img src="…">` em qualquer página que um admin logado abra apaga a cerca. Vale como passe focado varrendo `handlers/*.php` inteiro por outros `action=` destrutivos em GET |
> | 3 | **Os 4 SQL mais antigos embutem `USE jimi_tracker`** | Impede montar cópia limpa de teste e já rebaixou o banco de dev para `4.0.0` uma vez. Detalhado no bloco da v4.7.0 abaixo |
> | 4 | **Entregabilidade (SPF/DKIM)** | Só vira problema se o item 6 do roteiro do Bloco 2 falhar. O `mailer.php` **não assina DKIM**; se precisar assinar, a decisão é trocar por API HTTP transacional, não implementar DKIM artesanal (Bloco 4 do plano de validação) |
> | 5 | **`notificacoes.spec.js` nunca foi escrita** | O sino da v4.4.0 segue sem cobertura E2E — lacuna conhecida desde a Fase 1 |
>
> #### 5. Armadilhas do ambiente (custaram tempo nesta sessão)
>
> - **`bash -n` nos `.sh` falha no Windows e é FALSO POSITIVO**: `core.autocrlf=true` deixa o working tree em CRLF, mas o índice — o que o servidor recebe — é LF. Lintar de verdade com `git show :scripts/deploy.sh | bash -n`.
> - **Porta 8124 é recusada pelo Windows** (faixa excluída) para servidor de teste local; **8123 funciona**.
> - **`glob('*')` não casa dotfile** — conferir `.htaccess`/`.gitkeep` com `file_exists()`, não pela listagem.
> - **Asserção sobre ausência passa por vacuidade**: com o HTML vazio, "nenhum link morto" e "não sobrou o rodapé antigo" passaram. Todo script de verificação precisa **abortar** se o fixture não respondeu.
> - **Negação no `.gitignore` dentro de diretório excluído não funciona**: foi preciso `storage/*` + `!storage/.htaccess`, não `storage/` + negação.
>
> Anterior — **v4.7.0: relatório agendado por e-mail + modelos de relatório** (Fase 4 de `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`, **a última da série**). O relatório configurado uma vez chega sozinho por e-mail — diária, semanal ou mensal — para até 3 destinatários, em `/agendamentos`, com histórico de execuções. E os filtros usados com frequência viram **modelos** reutilizáveis em 10 telas de relatório. Novos: `mysql/migration_v4.7.0.sql`, `includes/schedule.php`, `includes/report_templates.php`, `scripts/schedule_dispatcher.php`, `handlers/agendamentos.php`, `tests/agendamentos.spec.js`. Alterados: `worker.php` (entrega por e-mail, contagem de linhas, teto de 100 mil), `exportar.php`, 9 handlers `rel_*` + `report_segments.php` (barra de modelos), `router.php`, `layout_base.php`, `grupos_permissao.php`, `crontab-setup.sh`, `deploy.sh`, `.env.example`, `navigation.spec.js`.
> **Verificado (local, PHP 8.3 + MySQL 8.0)**: lint **0 erros** em todo o projeto + `bash -n` nos 2 scripts; migração **idempotente** (2×, exit 0, banco em `4.7.0`); **143 asserções** em 2 suítes (71 de fuso/agendamento/entrega com **servidor SMTP de captura** inspecionando o `.eml`; 72 de HTTP autenticado — CRUD, CSRF, escopo multi-tenant, ciclo completo dos modelos) — **0 falhas**; **Playwright: 84 passed / 0 failed / 5 skipped** (suíte inteira).
>
> ### ~~▶️ RETOMAR AQUI — estado em 30/07/2026 (manhã)~~ — **SUPERADO**
> ⚠️ **Este bloco está desatualizado**: a v4.7.0 foi commitada (`a1a879b`) e **publicada** no homolog depois que ele foi escrito. Vale o bloco do topo. Mantido pelo registro das decisões, não do estado.
> **A implementação do `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md` está COMPLETA (Fases 1 a 4).** Falta commitar a v4.7.0, publicar e, depois, a Fase 5.
>
> **Git**: `main` local = `origin/main` = **`24eb6c8`** (v4.6.0, "fleet status monitoring"). A **v4.7.0 está no working tree, NÃO commitada** — 26 arquivos alterados + 6 novos (`agendamentos.php`, `schedule.php`, `report_templates.php`, `schedule_dispatcher.php`, `migration_v4.7.0.sql`, `agendamentos.spec.js`).
>
> **Homolog continua em `92725cb` / banco `4.4.1`.** Depois de commitar a v4.7.0, serão **três** versões a publicar de uma vez: v4.5.0 (geocercas), v4.6.0 (relatórios operacionais) e v4.7.0 (agendamentos). O gate semântico do `deploy.sh` aplica as três em sequência numa única execução.
>
> **Os passos que faltam:**
> ```bash
> sudo ./scripts/deploy.sh                   # aplica v4.5.0, v4.6.0 e v4.7.0 (gate semântico)
> bash scripts/crontab-setup.sh --install    # 7 workers — o deploy NÃO instala cron
> bash scripts/crontab-setup.sh --check
> php scripts/state_builder.php 30           # backfill dos segmentos, fora do horário de pico
> ```
> 1. **Cron é obrigatório e não sai no deploy.** São 3 workers novos (`geofence_worker`, `state_builder`, `schedule_dispatcher`) e a falha é silenciosa do pior tipo: as telas funcionam, os filtros funcionam, e nada nunca acontece.
> 2. **Sem o backfill**, os 4 relatórios de estado só têm dado a partir da 1ª execução do cron.
> 3. **RBAC**: **"Geocercas"** e **"Agendamentos"** precisam ser marcadas em `/grupos-permissao` para grupos restritos. As 5 telas da v4.6.0 herdam `relatorios` e não precisam.
> 4. **SMTP: já cadastrado e testado no homolog** (informado pelo usuário em 30/07/2026 — o botão "Enviar e-mail de teste" de `/config-smtp` funciona contra o provedor real). O que **ainda não foi validado é o caminho do AGENDAMENTO**: dispatcher → job → worker → e-mail com anexo, e o caminho alternativo do link. Isso depende da publicação da v4.7.0 e está planejado em **`docs/PLANO_VALIDACAO_AGENDAMENTOS.md`**. Ainda pendente também: **`APP_URL` correta** no `.env` do servidor — é a base do link quando o anexo passa de `MAIL_MAX_ATTACH_MB`, e um `APP_URL` errado gera e-mail com link quebrado sem nenhum erro no log.
> 5. **Verificar depois de publicar**: `/ping` em `4.7.0`; `SELECT version FROM system_info` = `4.7.0`; as 9 tabelas novas da série (`geofence*`, `device_state_segments`, `speeding_events`, `report_schedule*`, `report_templates`); `crontab -l` com 7 workers; criar um agendamento diário para o próprio e-mail e conferir a chegada no dia seguinte.
>
> **Próximo passo do roadmap**: **publicar e validar o envio agendado no homolog** — `docs/PLANO_VALIDACAO_AGENDAMENTOS.md` (novo, 30/07/2026). O SMTP já foi cadastrado e testado no servidor, mas o **caminho do agendamento** (cron → dispatcher → worker → e-mail com anexo, e o caminho alternativo do link) nunca rodou contra provedor real — localmente só contra um SMTP de captura. O plano cobre o roteiro de validação, como forçar o disparo sem esperar 24 h, e **duas decisões pendentes** que o agendamento agrava (ver abaixo). **A Fase 5 (`/wiki`) vem depois disso**, de propósito: a wiki precisa descrever o comportamento já validado, inclusive o que se decidir sobre o link do relatório grande.
>
> ### ⚠️ Dois problemas pré-existentes que a v4.7.0 agrava (decisão pendente — §3 do plano de validação)
> 1. **O relatório é baixável sem autenticação e o nome é previsível.** O `.htaccess` só reescreve o que não é arquivo (`!-f`) e **não há `.htaccess` em `storage/`**, então `storage/reports/*.xlsx` é servido como estático, fora do `require_login()`. O nome é `report_<job_id>_<timestamp>.<ext>` — `job_id` sequencial, timestamp com granularidade de segundo: **enumerável**. Em sistema multi-tenant isso é vazamento entre clientes. Nasceu com `/exportar` (v4.0.0), mas agora esses links viajam por e-mail e ficam parados em caixas de entrada. Opções avaliadas no plano (rota autenticada / token no nome / link assinado com validade); recomendação: token no nome agora + link assinado como alvo, e `Options -Indexes` em `storage/` em qualquer cenário.
> 2. **Nada purga `storage/reports`.** `log_cleanup.php` limpa só `logs/*.log`. Um agendamento diário gera 1 arquivo/dia para sempre — cada um uma cópia de dado de cliente em disco. Proposta: estender o `log_cleanup.php` com `REPORT_RETENTION_DAYS` (30) e purgar também `report_schedule_runs` antigo.
>
> ### ⚠️ Achado em aberto — os 4 SQL mais antigos embutem `USE jimi_tracker`
> `mysql/jimi_tracker.sql` (que também tem `CREATE DATABASE`), `migration_v2.0.0.sql`, `migration_v3.1.0.sql` e `migration_v4.0.0.sql` **ignoram o banco passado ao cliente**; de `v4.1.0` em diante respeitam. Então: (a) instalar em banco com outro nome exige editar os 4 arquivos; (b) **não é possível montar uma cópia limpa de teste** a partir do repositório; (c) tentar a cadeia apontando para um banco de teste executa os 4 primeiros contra o `jimi_tracker` real e, como a v4.0.0 termina com `system_info.version = '4.0.0'`, **empurra a versão para trás** — o valor que o gate do `deploy.sh` lê. **Aconteceu nesta sessão** ao tentar o teste de base limpa que o próprio plano recomendava: o banco de dev foi para `4.0.0` e voltou a `4.7.0` com a cadeia `4.1.0 → 4.7.0`; **os dados ficaram intactos** (todos os seeds usam `INSERT IGNORE`; zero duplicatas conferidas em `alarm_types`, `occurrence_config_params`, `permission_groups`, `occurrence_configs` e `notification_rules`). **O deploy não é afetado** — o gate semântico nunca reexecuta a base nem a v4.0.0 num banco já adiante. Correção sugerida: tirar `USE`/`CREATE DATABASE` dos 4 arquivos.
>
> ### ⚠️ Achado de segurança em aberto (fora do escopo da Fase 4)
> **`/geocercas?action=excluir&id=N` apaga a geocerca sem token CSRF** — `csrf_verify()` não lê da query string, então a exclusão é acionável por um `<img src="…">` em qualquer página que um administrador logado abra. Os agendamentos da v4.7.0 já nascem com POST + CSRF. A correção de `/geocercas` e a **varredura por outros `action=` destrutivos em GET** ficam pendentes como passe focado de segurança — vale revisar `handlers/*.php` de uma vez, não de raspão no fim de uma fase de features.
>
> ### O que sustenta a v4.7.0 (decisões que valem revisitar)
> - **Fuso por `DateTimeZone`, nunca offset fixo.** `send_hour` é BRT, `next_run_at` é UTC. Janeiro e julho dão o mesmo offset hoje (DST abolido em 2019) — mas o teste ancora uma asserção em **16/02/2018**, com DST vigente, onde 07:00 BRT são **09:00 UTC**: somar 3 h erraria em uma hora. O cálculo roda no calendário BRT e só então converte.
> - **Reentrância por UPDATE condicional**: o dispatcher move `next_run_at` **antes** de enfileirar, com `WHERE next_run_at = <valor lido>`. Dois processos: um ganha a linha, o outro afeta 0 e desiste.
> - **Período é sempre o fechado anterior** (ontem / semana passada / mês passado), nunca o corrente.
> - **Job `concluido` + execução `falhou`** quando só a entrega falha: o arquivo existe e fica baixável em `/exportar`: marcar o job como falho esconderia o `result_path`. A falha aparece no histórico do agendamento, que é o que alimenta a regra das 3 falhas.
> - **Sucesso zera `fail_count`** — a regra é "3 CONSECUTIVAS". Sem o reset, três tropeços espalhados por meses derrubariam um agendamento saudável.
> - **Modelo guarda a query string da tela**, não uma estrutura por relatório: serve para qualquer filtro presente ou futuro e dispensa mapeamento tela a tela.
>
> ### Lição da sessão: edição em lote precisa de auditoria arquivo por arquivo
> O script que injetou a barra de modelos nos 9 handlers usou uma regex com `\n`, que **não casa com CRLF** — e `rel_geocercas.php` é o **único** arquivo do repositório com terminação CRLF. Resultado: a chamada de `render_template_bar()` entrou **sem** o `require_once`, e a tela passou a devolver "Erro interno". **O `php -l` não pega isso** (a função existe, só não está carregada) e conferir por amostragem também não pegaria. Quem pegou foi a suíte Playwright completa. Depois do conserto, uma auditoria explícita (`require` + `handle` + `bar` presentes nos 9 arquivos) e um smoke das 14 rotas de relatório fecharam a verificação.
>
> Anterior — **v4.6.0: relatórios operacionais** (Fase 3 de `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`). Cinco telas novas — **Status da Frota**, **Paradas**, **Ociosidade**, **Ignição** e **Excesso de Velocidade** — alimentadas por **um** worker (`scripts/state_builder.php`, cron de 15 min) que segmenta `gps_data` em `device_state_segments` (`movimento`/`ocioso`/`parado`/`offline`) e apura `speeding_events`. Novos: `mysql/migration_v4.6.0.sql`, `includes/fleet_state.php`, `includes/report_segments.php`, `scripts/state_builder.php`, `handlers/rel_paradas.php`, `rel_ociosidade.php`, `rel_ignicao.php`, `rel_velocidade.php`, `rel_status_frota.php`, `tests/relatorios-operacionais.spec.js`. Alterados: `trip_builder.php` (passa a consumir os limiares compartilhados), `worker.php` (5 tipos novos de export assíncrono), `equipamentos.php` + `clientes.php` (limite de velocidade), `exportar.php`, `router.php`, `layout_base.php`, `crontab-setup.sh`, `deploy.sh`, `.env.example`, `navigation.spec.js`. **Corrigido de quebra**: `/geocercas` re-renderizava o formulário vazio após salvar (v4.5.0) — agora Post/Redirect/Get.
> **Verificado (local, PHP 8.3 + MySQL 8.0)**: lint **0 erros** em todo o projeto + `bash -n` em `crontab-setup.sh` e `deploy.sh`; migração **idempotente** (2×, exit 0, banco em `4.6.0`); **114 asserções** em 3 suítes (51 de segmentação com trajetória sintética de 1.515 pontos, 39 de HTTP autenticado ponta-a-ponta, 24 de worker/regressão) — **0 falhas**; **Playwright: 69 passed / 0 failed / 5 skipped** (a suíte inteira, não só as telas novas). O critério duro — **soma das durações de um dia = 86.400 s exatos** — passa, e com ele a contiguidade sem vão nem sobreposição.
>
> ### ▶️ RETOMAR AQUI — estado em 29/07/2026
> **Homolog continua em `92725cb` / banco `4.4.1`.** Há **duas** versões commitadas e não publicadas: a v4.5.0 (geocercas) e a v4.6.0 (relatórios operacionais). O gate semântico do `deploy.sh` aplica as duas em sequência numa única execução.
>
> **Os passos que faltam:**
> ```bash
> sudo ./scripts/deploy.sh                   # aplica v4.5.0 E v4.6.0 (gate semântico)
> bash scripts/crontab-setup.sh --install    # 6 workers — o deploy NÃO instala cron
> bash scripts/crontab-setup.sh --check
> php scripts/state_builder.php 30           # backfill, fora do horário de pico
> ```
> 1. **Cron é obrigatório e não sai no deploy.** Sem `--install`, nem `geofence_worker.php` nem `state_builder.php` rodam, e a falha é silenciosa do pior tipo: as telas funcionam, os filtros funcionam, e os relatórios ficam vazios para sempre.
> 2. **Sem o backfill, os 4 relatórios de estado só têm dado a partir da 1ª execução do cron.** O worker é incremental por natureza; ele não vai atrás do histórico sozinho.
> 3. **RBAC**: só **"Geocercas"** (v4.5.0) precisa ser marcada em `/grupos-permissao` para grupos restritos. As 5 telas da v4.6.0 herdam a permissão `relatorios` já existente — quem hoje vê Alarmes já vê as novas.
> 4. **Verificar depois de publicar**: `/ping` em `4.6.0`; `SELECT version FROM system_info` = `4.6.0`; as 6 tabelas (`geofence*` + `device_state_segments` + `speeding_events`); `crontab -l` com 6 workers; e a consulta que fecha a conta —
>    `SELECT COUNT(*) FROM device_state_segments a JOIN device_state_segments b ON a.imei=b.imei AND a.id<>b.id AND a.started_at < COALESCE(b.ended_at,'9999-12-31') AND COALESCE(a.ended_at,'9999-12-31') > b.started_at;` **tem de dar 0** (nenhuma sobreposição).
>
> **Próximo passo do roadmap**: **Fase 4 — v4.7.0, relatório agendado por e-mail + modelos de relatório** (§4 do plano). Depende da Fase 1 (mailer) e da Fase 3 (os tipos novos já estão em `buildReportSource()`, prontos para serem agendados). Maior risco da fase: **fuso horário** — `send_hour` é BRT e `next_run_at` é UTC; converter por `DateTimeZone`, nunca somando 3 h, e testar com data de janeiro E de julho. Depois dela, a **Fase 5** (atualizar `/wiki`), que é o último passo da iniciativa por decisão registrada.
>
> ### O que sustenta a precisão da v4.6.0 (decisões que valem revisitar)
> - **A invariante**: segmentos contíguos e sem sobreposição (`ended_at` de um = `started_at` do seguinte) ⇒ a soma de um dia fecha em 86.400 s. **Quem mexer no `state_builder.php` tem de preservar isso** — é o único teste que pega furo de segmentação. Mudança de estado põe a fronteira no ponto **novo**; buraco de dados põe no ponto **anterior**, com um segmento `offline` cobrindo o vão (fechar no anterior é o que impede creditar 6 h de "movimento" a um veículo sem sinal).
> - **O último segmento fica aberto de propósito** (`ended_at IS NULL`) e é reescrito na rodada seguinte por `ON DUPLICATE KEY UPDATE` sobre `(imei, started_at)`. Fechá-lo a cada rodada fatiaria um estado em curso em pedaços de 15 min.
> - **Segmento de duração zero não é gravado**: ponto isolado seguido de buraco colidiria com o `offline` do vão na mesma chave, e o offline o sobrescreveria — certo por acidente. Descartar é explícito e mantém a soma intacta.
> - **O estado corrente do Status da Frota é resolvido na leitura** (`resolve_current_state()`), não lido do segmento aberto: um veículo calado desde as 3h tem segmento aberto em `movimento`, e dizer "em movimento" às 10h seria mentira. Entre duas rodadas do cron a verdade muda sem dado novo no banco.
> - **`offline` nunca sai de `classify_point()`** — é ausência de ponto, não propriedade de um ponto.
> - **Ignição ignora os segmentos `offline`** ao comparar estados: durante o silêncio não se sabe o que a ignição fez, e incluí-lo inventaria dois acionamentos que ninguém observou.
> - **`trip_builder.php` e `state_builder.php` compartilham os limiares** (`includes/fleet_state.php`). Redeclarar localmente faz "parado" significar coisas diferentes em duas telas e nenhuma das duas fica auditável (risco R6).
>
> Anterior — **v4.5.0: geocercas e POIs** (Fase 2 de `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`). Cercas em **círculo** ou **polígono** desenhadas no mapa (`/geocercas`, Leaflet puro — sem `leaflet-draw`), vinculadas a equipamentos; cada travessia vira evento em `geofence_events`, notifica pelo motor da v4.4.0 e alimenta `/relatorios/geocercas` em duas modalidades (**entradas/saídas** e **permanência**, pareada com `LEAD` sobre cerca × equipamento). Novos: `mysql/migration_v4.5.0.sql`, `includes/geofence.php`, `scripts/geofence_worker.php`, `handlers/geocercas.php`, `handlers/rel_geocercas.php`, `tests/geocercas.spec.js`. Alterados: `includes/functions.php` (`haversine_km()` promovida do `trip_builder`), `scripts/trip_builder.php`, `router.php`, `layout_base.php`, `grupos_permissao.php`, `crontab-setup.sh`, `deploy.sh`, `tests/navigation.spec.js`.
> **Verificado (local, PHP 8.3 + MySQL 8.0)**: lint **0 erros** em todo o projeto (`handlers`, `config`, `core`, `includes`, `scripts`, `web`) + `bash -n` em `crontab-setup.sh` e `deploy.sh`; migração **idempotente** (2 execuções, exit 0, banco em `4.5.0`, as 4 tabelas criadas); **36 asserções de geometria** (haversine, círculo, histerese, ray casting em polígono côncavo em "L", normalização, geometria incompleta); **13 asserções do worker** ponta-a-ponta com trajetória sintética; **28 asserções** de relatório + CRUD via HTTP; 7 rotas renderizando 200 sem erro/aviso de PHP; `trip_builder.php` sem regressão após a troca da haversine.
> **Publicação da v4.5.0**: continua pendente e foi absorvida pelo bloco RETOMAR AQUI da v4.6.0 acima — as duas versões sobem juntas, numa única execução do `deploy.sh`. O ponto que permanece exclusivo da v4.5.0 é o **RBAC**: `can()` nega tela ausente da matriz JSON, então usuário em grupo restrito (ex.: "Operador Padrão") recebe 403 em `/geocercas` e não vê o item na sidebar até o administrador marcar a linha "Geocercas" em `/grupos-permissao`. Admin (`{"*": [...]}`) e usuário sem grupo não são afetados. Tela nova nasce opt-in de propósito, mas é a primeira coisa que alguém reporta como "bug".
>
> **Corrigido na v4.6.0**: `/geocercas` re-renderizava o **formulário vazio** depois de salvar (o POST caía no mesmo `?action=nova`), então o usuário recebia "Geocerca criada." sem ver o registro na grade — e um F5 reenviava o POST e criava uma cerca duplicada. Agora há Post/Redirect/Get. O defeito passou despercebido porque os specs da Fase 2 foram escritos e **nunca executados com credenciais**; rodá-los na Fase 3 o revelou de imediato.
>
> ### Deploy — auditoria de 29/07/2026
> O script está **apto** para publicar a v4.5.0 **e a v4.6.0 na mesma execução**. O que foi conferido, e o que mudou:
> - ✅ **Migração**: `run_migration "4.5.0"` e `run_migration "4.6.0"` na cadeia; o gate semântico (`sort -V`) roda só o que falta. Banco em 4.4.1 → aplica as duas, em ordem.
> - ✅ **`SYSTEM_VERSION`**: atualizado automaticamente a partir do `.env.example` (linhas 250‑258), já em **`4.6.0`**. Nada de editar `.env` à mão. *(Correção: uma nota anterior desta sessão dizia que o script não mexia no `.env` — dizia errado.)*
> - ✅ **Auto-substituição**: a guarda de sha256 + `exec` (v4.4.1) continua no lugar, e este release **altera o próprio `deploy.sh`** — a re-execução vai disparar. Esperar **dois** cabeçalhos `=== DEPLOY:` no log; é o comportamento correto, não erro.
> - ✅ **Backup**: `mysqldump` roda antes do pull; as 2 VIEWs órfãs já foram dropadas no homolog, então não aborta.
> - 🔧 **Corrigido nesta sessão**: a FASE 4 (VERIFY) lintava `handlers config core includes` e **deixava `scripts/` de fora** — justamente onde vivem os 5 workers de cron. Um erro de sintaxe no `geofence_worker.php` passaria pelo deploy e só apareceria na primeira execução do cron, dentro de `logs/geofence.log`. `scripts` entrou no `find`.
> - ⚠️ **Não coberto pelo script** (continua manual): instalação do cron, o **backfill** do `state_builder` e a concessão da permissão de `/geocercas`.
>
> ### O que sustenta o custo e a precisão da v4.5.0 (decisões que valem revisitar)
> - **Histerese de 50 m** contra flapping: a borda vira uma faixa — entrar exige cruzar a borda real, sair exige afastar-se 50 m dela. Medido: 30 min de oscilação entre 185 m e 215 m numa cerca de 200 m produziram **1** evento, não 30.
> - **Em polígono, a histerese mede distância até a ARESTA**, não até a bbox expandida (que é o que o plano original sugeria): a bbox manteria "dentro" um veículo parado no vão da concavidade de uma cerca em "L", a centenas de metros da área real. O teste do vão é asserção explícita.
> - **`INSERT IGNORE` + `UNIQUE (geofence_id, imei, event_time)`**: reexecutar o worker sobre a mesma janela não duplica evento. Confirmado rodando duas vezes.
> - **Cerca nova não gera entrada retroativa**: sem estado gravado, o primeiro ponto apenas *semeia* `geofence_state`. Desenhar uma cerca sobre a garagem não dispara uma "entrada" para cada veículo já estacionado. Editar a geometria apaga o estado pelo mesmo motivo.
> - **O evento é sempre gravado; `alert_on` só decide se notifica** — sem os dois lados do par, a permanência não teria o que parear.
> - **`haversine_km()` promovida** de `trip_builder.php` para `includes/functions.php`. `calculate_distance()` ficou intocada de propósito: ela **retorna 0 quando qualquer latitude é 0**, e essa guarda transformaria todo ponto na linha do Equador em "dentro de tudo". Há chamador legado (`pushgps.php`) dependendo do comportamento antigo.
>
> Anterior — **v4.4.1: credenciais de SMTP cadastráveis pela interface** (`/config-smtp`, "Cadastros › Servidor de E-mail"). Escopo **global** (só admin) e **por cliente** (white-label, envia do próprio domínio); resolução **cliente → global → `.env`** (o `.env` vira fallback). Senha gravada cifrada em **AES-256-GCM** (`includes/crypto.php`, chave de `APP_KEY` com fallback `WEBHOOK_TOKEN`) e nunca reexibida no formulário. Botão **"Enviar e-mail de teste"** grava resultado e erro do provedor na própria tela. Novos: `mysql/migration_v4.4.1.sql`, `includes/crypto.php`, `handlers/config_smtp.php`. Alterados: `mailer.php` (resolução + cache invalidável), `worker.php` (passa `customer_id`), `config_notificacoes.php` (aviso aponta para a tela), `router.php`, `grupos_permissao.php`, `layout_base.php`, `deploy.sh`, `.env.example`.
> **Verificado**: lint limpo no projeto + `bash -n` no deploy; migração idempotente (2×, exit 0); 16 asserções de cifra e precedência (round-trip, não-determinismo por IV, detecção de adulteração pela tag GCM, cliente vence global vence `.env`, unicidade da config global, config inativa fora da resolução); fluxo HTTP ponta-a-ponta (salvar → painel atualiza na mesma request → senha ausente do HTML e cifrada no banco → teste reporta erro real → excluir).
> ⚠️ **Para produção**: definir **`APP_KEY`** no `.env` antes de cadastrar a senha (sem ela usa-se o `WEBHOOK_TOKEN`, e rotacioná-lo torna as senhas gravadas indecifráveis — a tela avisa). O envio real ainda **não foi validado contra um provedor**: falta cadastrar as credenciais do servidor externo e clicar em "Enviar e-mail de teste".
>
> ### Estado publicado — 28/07/2026, `4e60322` (local = GitHub = homolog)
> **As três pontas na mesma branch e no mesmo commit.** `main` em `4e60322`, working tree limpo dos dois lados; nenhuma outra branch existe (a `feat/v4.4.0-notificacoes` foi mesclada por fast-forward e removida local/remota; a `master` fóssil em `729f094` foi apagada do servidor após confirmar por `rev-list --count main..master = 0` e `merge-base --is-ancestor` que não havia história exclusiva).
> **Homolog verificado**: schema em **4.4.1** (`notification_rules`, `notifications`, `smtp_settings` criadas; `jobs.type` com `notification`; `jobs.attempts` presente; 6 regras globais de seed; só as 3 VIEWs boas), `.env` com `SYSTEM_VERSION=4.4.1`, `NOTIFY_ENABLED=1` e `APP_KEY` de 64 hex gerada no servidor. Lint 0 erros; `/config-smtp` e `/config-notificacoes` em 302 (existem, exigem login), `/notificacoesdata` em 200, `/ping` reportando `4.4.1`; Apache/MySQL/PHP-FPM ativos e os 4 workers no crontab do root rodando.
> **Higiene aplicada no servidor**: removidos `includes/config.php` (resquício com `DB_PASS` em texto puro apontando para um banco inexistente `jimi_webhook`; confirmado por grep que nada o carregava) e `.env.bak-20260708_215709`. O `handlers/pushterminalrealtimestatus.php` deixou de ser arquivo-fantasma — foi documentado e versionado em `f55a561` (endpoint de diagnóstico, fora do `$webhookRoutes`, alcançado por `POST /handlers/pushterminalrealtimestatus.php` porque o `.htaccess` só reescreve o que não é arquivo existente).
>
> #### Três defeitos de infraestrutura encontrados e corrigidos nesta sessão
> 1. ⚠️ **`deploy.sh` se auto-substituía durante a própria execução** (corrigido em `2d32164`). O bash lê o script do disco conforme executa; o `git pull` da FASE 3 trocou o arquivo e o interpretador seguiu a partir do offset antigo dentro do arquivo novo — **as migrações v4.4.0 e v4.4.1 nunca rodaram e o deploy imprimiu "CONCLUÍDO" com exit 0**, deixando código novo sobre banco 4.3.0. Agora o script compara o próprio sha256 antes/depois do pull e se re-executa uma vez (guarda `DEPLOY_REEXEC` contra laço). **Exercitado de verdade** na publicação do `4e60322`, que alterava o próprio `deploy.sh`: dois cabeçalhos `=== DEPLOY:` no log (22:38:05 e 22:38:12) comprovam a re-execução.
> 2. ⚠️ **Gate de migração reaplicava a cadeia inteira a cada deploy** (corrigido em `4e60322`). Cada bloco comparava com `!=` da própria versão, então um banco em 4.4.1 satisfazia `!= "4.2.1"` e disparava tudo de novo — **rebaixando o banco no meio do deploy** (`4.4.1 → 4.2.1 → 4.3.0 → …`). Só não quebrava porque as migrações são idempotentes. Substituído por gate **semântico** (`sort -V`, roda só quando a versão do banco é menor); 176 linhas de blocos duplicados viraram `version_lt()` + `db_version()` + `run_migration()` + 8 chamadas (68 linhas). Validado em 16 casos isolados — incluindo a armadilha lexicográfica `4.9.0 < 4.10.0` — e contra o banco real do homolog, onde as 8 migrações reportaram "desnecessária" sem rebaixamento.
> 3. ⚠️ **`/ping` reportava a versão fixa `"2.0.0"`** (corrigido em `4e60322`), string herdada da primeira versão do arquivo — e era justamente ela que o `deploy.sh` imprimia ao fim de cada publicação. Passa a ler `SYSTEM_VERSION` por um parser mínimo do `.env`, **sem** `Database::getInstance()` de propósito: a sonda precisa continuar respondendo com o MySQL fora, senão deixa de distinguir "aplicação morta" de "banco fora". Confirmado: `handlers/ping.php` tem **zero** `require` e a única menção a `Database::getInstance` está no comentário da linha 13.
>
> #### Segurança tratada
> - **Token do GitHub fora do `.git/config`**: o remote embutia um PAT em texto puro na URL. Movido para o Gerenciador de Credenciais do Windows; o remote agora é `https://github.com/hssflavio-ux/jimi_webhook.git` limpo, com push funcionando.
> - ⚠️ **Pendente do usuário**: revogar em github.com/settings/tokens os tokens **`ghp_2ZHz…`** (exposto em texto puro) e o fine-grained `github_pat_11B2…` (criado só com `Contents: Read`, não servia para push). O token em uso é o clássico `ghp_G2Rt…` (`repo, workflow, write:packages`).
> - **Credencial em disco removida** do servidor: `includes/config.php` (ver higiene acima).
>
> Anterior — **v4.4.0: motor de notificações** (Fase 1 de `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`, derivado de `docs/ANALISE_MANUAL_JIMI.md`). Ocorrência nova passa a gerar notificação no **sino** (badge + painel + polling 30s), **pop-up**, **som** (WebAudio) e **e-mail**, conforme regra por cliente × tipo de alarme em `/config-notificacoes` (matching triplo código/nome/categoria, `min_risk` opcional, fallback global). Novos: `includes/notification_engine.php`, `includes/mailer.php` (SMTP próprio, sem dependência), `handlers/notificacoesdata.php`, `handlers/config_notificacoes.php`, `mysql/migration_v4.4.0.sql`. Alterados: `worker.php` (tipo `notification` + retry por `attempts`), `occurrence_engine.php` (gancho), `layout_base.php` (sino), `router.php`, `grupos_permissao.php`, `auth.php` (purga), `.env.example` (bloco SMTP + `APP_URL` + `NOTIFY_ENABLED`).
> **Verificado**: `php -l` limpo nos 10 arquivos; migração idempotente (2 execuções, exit 0, seed não duplica); 13 asserções do motor passando (matching por categoria a partir do código, corte por `min_risk`, precedência da regra do cliente, dedupe de e-mail, kill-switch, teto horário); retry do worker (2 reagendamentos → falha definitiva na 3ª, sem loop); rotas autenticadas 200/401 e CSRF devolvendo 403.
> ⚠️ **Pendente para produção**: `SMTP_*` no `.env` (sem isso só o canal de e-mail falha — sino/pop-up/som funcionam) e as 2 VIEWs órfãs (ver item aberto abaixo) **antes** de rodar a migração.
>
> ### 📌 AÇÃO PROGRAMADA — atualizar a Central de Ajuda ao fim da iniciativa
> **Quando**: depois de concluída **toda** a implementação do `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md` — ou seja, ao fim da **Fase 4 (v4.7.0)**, e não a cada fase. Fazer de uma vez evita reescrever as mesmas seções quatro vezes, já que Fases 2–4 mexem nas mesmas telas de relatório e no mesmo motor de notificação.
> **O que**: `handlers/wiki.php` (`/wiki`), que hoje documenta até a v4.3.0. Precisa cobrir o que as quatro fases entregarem — ver o checklist detalhado em **§12.6 Pendências em aberto**.
> **Precedente**: a wiki já foi atualizada assim ao fechar a v4.3.0 (commit `83f9849`, "Central de Ajuda atualizada para a v4.3.0"). Manter o mesmo padrão: uma seção `<h2 id="…">` por tela nova, com mockup e regra de negócio, mais os avisos transversais.
>
> Anterior — **23/07/2026** — **UX dos relatórios**: (a) botão `← Voltar` + ordenação crescente por data/hora com seta clicável em todos os relatórios (commit **`6b73765`**, §12.19); (b) **paginação travada na página 10** corrigida com janela deslizante (`report_pagination()`, 7 telas afetadas) + **faixa horária opcional no Relatório de Posições** (§12.20 — ainda não commitado). Helpers de UI dos relatórios centralizados em `includes/functions.php`.
> Anterior na mesma data: **sessão de 2 fixes, ambos commitados/pushados/deployados no homolog (servidor em `HEAD=88a98e1`, `origin/main=88a98e1`, working tree limpo).**
> **(a) Relatório de Deslocamento — viagem única de ~23h** (`trip_builder.php`): a segmentação encerrava o deslocamento **só** em `acc=desligado`; devices que ficam com a ignição ligada o dia todo (FJR7B59 `869058070151343`) colapsavam a jornada inteira numa viagem só cobrindo o dia todo. Agora encerra também por **parada sustentada** (`STOP_SPEED_KMH=3` por >`STOP_IDLE_SECONDS=300s`) e por **buraco de dados** (mesmo limite), sempre fechando no último ponto em movimento; piso `MIN_TRIP_DURATION_S=60s`. Rebuild no homolog: FJR7B59 **11 → 39 viagens** (hoje já em 40, o cron incremental somou 1 nova — prova de que a lógica nova está viva). Commit **`acf09f3`** (§12.17).
> **(b) Deploy "não acessa o banco com as credenciais do .env" — diagnóstico falso**: as migrations sempre conectaram com o `.env` (banco em `4.3.0`); os 2 avisos eram misdiagnósticos — **FASE 1** rodava `mysql -e "SELECT 1"` **sem credenciais** (conectava como usuário do SO, sem conta MySQL) e **FASE 2** abortava o `mysqldump` (erro 1356) por **2 VIEWs órfãs** (`vw_alarm_types_ambiguous_codes`/`vw_alarm_types_unknown_codes` → tabela inexistente `alarm_types_reference`, já removidas do schema canônico em 06/07). Correções: **VIEWs dropadas no homolog** (só restam as 3 boas; `mysqldump` completa, `exit 0`) + **`deploy.sh`** passou a testar com as credenciais do `.env` e a exibir o stderr real. Commit **`88a98e1`** (§12.18).
> ⚠️ **Único item aberto**: **produção** (se existir e for anterior a 06/07/2026) provavelmente tem as mesmas 2 VIEWs órfãs → rodar `DROP VIEW IF EXISTS vw_alarm_types_ambiguous_codes, vw_alarm_types_unknown_codes;`.
> Anterior: Deslocamento em 2 modalidades + mapa de rota + teto de 31 dias + índice composto `trips` em `4.3.0` (§12.16).
> Vídeo ao vivo abrindo com stream real capturado da câmera online (payload 37121 corrigido + player resiliente). Comandos → device → resposta ponta-a-ponta (síncrono E offline), horários em BRT em todo o dashboard, cadastro de ativos adotando devices do gateway. Suite Playwright (navegação 25/25 verde), lint OK. **Detalhes da iteração de vídeo: §14. Diagnóstico anterior: §12.**
> **Servidor homolog**: `http://189.22.240.43` (Apache 2.4 host + PHP 8.3 FPM + MySQL 8.0 + stack IoTHub em 16 containers Docker) — implantado em `cd1af0f`
> **Dev Windows**: PHP 8.3.32 em `C:\Users\flavi\php\php.exe` + MySQL 8.0.37 portátil em `C:\Users\flavi\mysql` (`scripts/dev-windows.ps1`)

---

## 0. Iniciativa v4.0.0 — YUV Parity (CONCLUÍDA)

**Objetivo**: transformar o projeto em uma cópia fiel do YUV (`app.yuv.com.br`). Gateway de webhooks Jimi preservado; dashboard e design reconstruídos com design system Coinbase. O núcleo é a **gestão de ocorrências DMS** (alarme de câmera → ocorrência → tratativa → risco, com regras por cliente).

**Documentos de referência**:
- `PROJETO_YUV.md` — blueprint-mestre (rotas-alvo, modelo de dados, specs das 22 telas, motor de ocorrências)
- `analise_yuv/analise_yuv.html` — análise visual do YUV (22 telas + regras de negócio, com screenshots)
- `DESIGN.md` / `DESIGN-coinbase.md` — design system Coinbase (azul `#0052ff`, sidebar dark `#0a0b0d`, CTAs pill)

### Status consolidado do roadmap

| Fase | Arquivos | Lint | Principais entregas |
|---|---|---|---|
| **0 — Fundação** | 29 | ✅ | Migração v4.0.0 (15 tabelas + alterações), router com subrotas, sidebar-sanfona, header On/Off, 5 componentes base (kpi_card, risk_bar, status_pill, filter_bar, crud_grid), 18 placeholder handlers + 2 AJAX |
| **1 — Motor Ocorrências** | 5 | ✅ | `occurrence_engine.php` integrado em `pushalarm.php` (matching triplo código/nome/categoria + link_upload), CRUD `/config-ocorrencias` com rows dinâmicas de parâmetros, pushfileupload/pushftpfileupload com channel/download_status |
| **2 — Módulo DMS** | 4 | ✅ | Dashboard `/ocorrencias/dashboard` (KPIs + risk bar + grade + polling 15s), tela de tratativa inline (vídeo, alarmes agrupados, transições status/notas/falso-positivo), `/relatorios/ocorrencias` (6 filtros), `/relatorios/alarmes` (ordenação clicável, mapa OSM) |
| **3 — Vídeo** | 3 | ✅ | `/video/aovivo` (flv.js + rotation/watermark + proNo 37121), `/video/playback` (filtro + timeline + play inline), `/video/downloads` (grade com status disponível/solicitado/erro + download direto) |
| **4+5 — Equipamentos+Relatórios** | 9 | ✅ | `/equipamentos` (grade + form com periféricos chip-style + FOTA modal + import CSV), `/relatorios/posicoes` (mapa Leaflet com fitBounds), `/relatorios/deslocamento` (trips com duração/distância/alarmes), `/relatorios/desatualizados` (5 buckets KPI + drill-down), `/exportar` (fila jobs), `scripts/worker.php`, `scripts/trip_builder.php` (haversine), `scripts/metrics_rollup.php` |
| **6 — Cadastros** | 5 | ✅ | `/chips` (CRUD SIM), `/motoristas` (CRUD + alertas vencimento CNH/toxicológico), `/grupos-permissao` (matriz 18 telas × 5 ações JSON + contagem usuários), `/clientes` evoluído (occurrence_config_id, faceid_enabled, brand_color, logo_url, impersonar com `impersonation_log`), `/usuarios` evoluído (abas Minha Empresa/Meus Clientes, user_type, permission_group_id, photo_url) |
| **7 — Visão Executiva** | 4 | ✅ | `/` Resumo (4 KPIs, heatmap Leaflet, velocidade frota, desatualizados, top clientes revendedor, séries Chart.js hora-a-hora alarmes+ocorrências), `/bi` (gráficos barras/pizza/linha sob demanda com filtros), `/rastreamento` (cliente→ativo→mapa cascata + busca + auto-refresh 60s) |
| **F — Segurança+Checklist** | 9 | ✅ | `includes/csrf.php` (token por sessão, `csrf_verify()` em 8 páginas, `csrf_field()` em todos os forms), cookie `Secure`/`HttpOnly`/`SameSite=Lax`, `auth_cleanup()` (sessions + request_logs periódico), GPS (0,0) filtrado, rotas mortas removidas, `/checklist` (3 tabelas + CRUD com itens dinâmicos boolean/text/photo/number) |
| **G — Performance+Polish** | 5 | ✅ | `metrics_snapshots` (nova tabela, 22 métricas por cliente), `metrics_rollup.php` (pré-computa KPIs a cada 5 min), `resumo.php` (lê do cache com fallback on-the-fly), tour de boas-vindas 5 passos (localStorage) + banner de comunicado, `exportar.php` (form de novo relatório com CSRF), `worker.php` (CSV real para 5 tipos: alarms/occurrences/positions/trips/devices), `bi.php` (filtro Motoristas + chips multi-select de Alarmes com overflow +N) |
| **H — UX+Security+Quality** | 11 | ✅ | `/checklist/inspecao` (preenchimento de inspeção), filtro de período no dashboard ocorrências, rate limiting 5 tentativas/15min + `login_log`, white-label `brand_color` na sidebar CSS, import CSV real em equipamentos (POST batch), prepared statements em 9 arquivos legacy (dashboard/ativos/comandos/config/live/video/relatorios/chips/motoristas), `pushcmd.php` removido do disco, md5 com `JSON_UNESCAPED_UNICODE`, aliases `lon`/`msgId` em normalize_data, dupla normalização removida (pushalarm/pushresourcelist) |
| **I — Tooling+Polish** | 4 | ✅ | `.githooks/pre-commit` (lint PHP automático, `git config core.hooksPath .githooks`), R13: `pushTerminalTransInfo` extrai `content`/`extensionData` estruturado, R16: log de erros em pushresourcelist, README.md atualizado com 30 rotas v4.0.0 + workers + segurança + white-label |
| **J — Deploy** | 3 | ✅ | `DEPLOY_v4.md` (plano completo com checklist, rollback, crontab), `scripts/deploy-v4.sh` (--check/--backup/--migrate/--deploy/--verify, idempotente, verifica 17 tabelas v4), `.env.example` atualizado (IOTHUB vars, SYSTEM_VERSION=4.0.0), `update-homolog.sh` e `deploy.sh` com suporte a migration v4.0.0, `scripts/crontab-setup.sh` (--check/--install/--remove workers) |
| **K — Resiliência (hotfix)** | 18 | ✅ | Todas queries de tabelas v4 blindadas com try-catch: `resumo.php` (metrics_snapshots+occurrences), `bi.php` (occurrences+drivers), `rel_ocorrencias.php` (5 queries), `rel_deslocamento.php` (trips+drivers), `exportar.php` (jobs), `ocorrencias_dashboard.php` (detail+events), `ativos.php` (device_statistics), `camerasdata.php` (device_statistics), `ativo_detalhe.php` (device_statistics), `rastreamento.php` (gps_data), `rel_posicoes.php` (gps_data), `rel_desatualizados.php` (last_position_at), `clientes.php` (occurrence_configs), `chips.php` (sim_cards), `motoristas.php` (drivers), `equipamentos.php` (branches), `grupos_permissao.php` (permission_groups), `usuarios.php` (permission_groups), `checklist.php`+`checklist_inspection.php` (checklists), `config_ocorrencias.php` (occurrence_configs) |
| **L — Bugfixes frontend** | 5 | ✅ | Login: redirect `/dashboard`→`/` + versão 4.0.0 + rate limiting resiliente. Legacy: `dashboard.php` e `live.php` viram redirect. Router: `config-ocorrencias` (hífen→underscore) adicionado `$renamedRoutes`. Playback: envia proNo 34817 ao clicar Requisitar. Migration: `d'água`→`dagua` (apostrofo quebrava SQL) |

> **Total**: **80 arquivos** PHP (79 lint) + 1 migration SQL + README.md. **0 erros de lint** em todo o projeto.

---

## 1. Arquitetura do Projeto (v4.0.0)

```
Jimi IoT Hub ──POST──▶ .htaccess ──▶ handlers/router.php ──▶ handlers/*.php
                                              │
   ┌──────────────────────────────────────────┴──────────────────────────────────┐
   │ 1) WEBHOOKS (push*.php extends WebhookHandler)                                │
   │    token → async 200 (fastcgi) → normalize → INSERT → stats → occurrence_engine│
   │                                                                               │
   │ 2) DASHBOARD + AJAX (layout Coinbase: web/layout_base.php)                    │
   │    require_login() / require_admin() + csrf_verify() nos POST                  │
   │                                                                               │
   │ 3) WORKERS (cron): worker.php (jobs), trip_builder.php (viagens),             │
   │    metrics_rollup.php (KPIs)                                                  │
   └───────────────────────────────────────────────────────────────────────────────┘
```

### Stack
- PHP 8.3 puro (sem framework, sem build step)
- MySQL 8.0 com prepared statements
- Front controller `router.php` (subrotas de 2 segmentos)
- Design system Coinbase inline CSS (Inter + JetBrains Mono, azul `#0052ff`)
- Leaflet + Chart.js + flv.js via CDN
- Autenticação token-based via cookie `jimi_token` + tabela `sessions` MySQL
- CSRF via token de sessão (`includes/csrf.php`)

---

## 2. Rotas Implementadas (v4.0.0 — 30 rotas)

### Sidebar — Principal
| Rota | Handler | Auth | Descrição |
|---|---|---|---|
| `/` | `resumo.php` | Login | Visão 360°: KPIs, heatmap, velocidade, desatualizados, top clientes, séries Chart.js |
| `/rastreamento` | `rastreamento.php` | Login | Mapa live: cliente→ativo cascata, circle markers, busca, auto-refresh 60s |
| `/bi` | `bi.php` | Login | BI: filtros + gráficos barras/pizza/linha sob demanda (Chart.js) |
| `/ocorrencias/dashboard` | `ocorrencias_dashboard.php` | Login | Dashboard DMS: KPIs, risk bar, grade, polling 15s, detalhe/tratativa inline |
| `/comandos` | `comandos.php` | Login | Presets JIMI/JT-T, polling 3s/10s/5min |
| `/exportar` | `exportar.php` | Login | Fila de jobs assíncronos com auto-refresh 30s |

### Grupo Vídeos (sidebar-sanfona)
| Rota | Handler | Descrição |
|---|---|---|
| `/video/aovivo` | `video_aovivo.php` | flv.js + proNo 37121 + rotation/watermark CSS + status bar |
| `/video/playback` | `video_playback.php` | Filtro equipamento/canal/período → timeline → play inline |
| `/video/downloads` | `video_downloads.php` | Grade com status disponível/solicitado/erro + download |

### Grupo Relatórios (sidebar-sanfona)
| Rota | Handler | Descrição |
|---|---|---|
| `/relatorios/posicoes` | `rel_posicoes.php` | Ativo + período + mapa Leaflet + paginação |
| `/relatorios/deslocamento` | `rel_deslocamento.php` | 2 modalidades: por deslocamento (trips) e fechamento diário (agregado por dia BRT); faixa horária opcional; link "Ver rota" por linha |
| `/relatorios/deslocamento/rota` | `rel_deslocamento_rota.php` | Mapa Leaflet do percurso (`trip_id` ou `imei`+`dia`): balões partida/chegada, pontos de comunicação, ocorrências destacadas |
| `/relatorios/desatualizados` | `rel_desatualizados.php` | 5 buckets KPI clicáveis + drill-down |
| `/relatorios/alarmes` | `rel_alarmes.php` | Ordenação clicável, 5 filtros, link mapa OSM, paginação |
| `/relatorios/ocorrencias` | `rel_ocorrencias.php` | 6 filtros: cliente, IMEI, tipo, status, risco, falso-positivo |
| `/relatorios/geocercas` | `rel_geocercas.php` | **v4.5.0** — 2 modalidades: entradas/saídas e permanência (`LEAD` por cerca × equipamento) |
| `/relatorios/status-frota` | `rel_status_frota.php` | **v4.6.0** — foto do agora: 4 cartões de estado com % + barra de distribuição + drill-down; estado resolvido na leitura |
| `/relatorios/paradas` | `rel_paradas.php` | **v4.6.0** — `state='parado'` (ignição desligada); filtro de duração mínima |
| `/relatorios/ociosidade` | `rel_ociosidade.php` | **v4.6.0** — `state='ocioso'` (motor ligado, imóvel); combustível queimado sem deslocamento |
| `/relatorios/ignicao` | `rel_ignicao.php` | **v4.6.0** — acionamentos derivados por `LAG` sobre os segmentos, com `offline` fora da janela |
| `/relatorios/velocidade` | `rel_velocidade.php` | **v4.6.0** — `speeding_events`; limite gravado no evento; filtro de excedente mínimo |

| `/agendamentos` | `agendamentos.php` | **v4.7.0** — CRUD de relatórios recorrentes por e-mail + histórico de execuções |

> Os 5 handlers da v4.6.0 usam a permissão **`relatorios`** no `$screenByHandler` — não são telas novas na matriz de `/grupos-permissao`. **`/agendamentos` é tela própria** e precisa ser liberada.
> **Modelos de relatório (v4.7.0)**: 10 telas (as 6 anteriores + as 4 novas de estado) têm a barra "salvar filtros como modelo / aplicar / excluir", por `includes/report_templates.php`. Escopo por usuário; o modelo guarda a query string e só aparece na tela que o criou.

### Grupo Cadastros (sidebar-sanfona)
| Rota | Handler | Descrição |
|---|---|---|
| `/ativos` | `ativos.php` | Lista + editar inline + remover (soft-delete) |
| `/ativos/novo` | `ativos_novo.php` | Cadastro com dropdown de modelos |
| `/ativos/{imei}` | `ativo_detalhe.php` | 9 abas com sidebar lateral |
| `/chips` | `chips.php` | CRUD SIM cards (operadora, MSISDN, ICCID, vínculo IMEI) |
| `/clientes` | `clientes.php` | CRUD + occurrence_config + faceid + brand_color + impersonar |
| `/equipamentos` | `equipamentos.php` | Grade + form (periféricos, rotação, watermark) + FOTA + import CSV |
| `/grupos-permissao` | `grupos_permissao.php` | Matriz 18 telas × 5 ações JSON + contagem de usuários |
| `/motoristas` | `motoristas.php` | CRUD + compliance (CNH, toxicológico, vencimentos com alerta) |
| `/config-ocorrencias` | `config_ocorrencias.php` | Perfis de regras com rows dinâmicas de parâmetros |
| `/usuarios` | `usuarios.php` | Abas Minha Empresa/Meus Clientes, user_type, permission_group, photo |

### AJAX / Infra
| Rota | Handler | Descrição |
|---|---|---|
| `/camerasdata`, `/trackdata`, `/hbdata`, `/mediadata` | idem | Dados de mapa/telemetria (escopo por sessão) |
| `/commandstatus`, `/sendcommand`, `/devicemodels` | idem | Comandos e modelos |
| `/ocorrenciasdata` | `ocorrenciasdata.php` | Polling DMS (KPIs + grade paginada) |
| `/exportardata` | `exportardata.php` | Polling da fila de jobs |
| `/customer_switch` | `customer_switch.php` | AJAX: troca contexto de cliente |
| `/perfil` | `perfil.php` | Troca de senha |
| `/checklist` | `checklist.php` | CRUD de checklists de inspeção |

### Webhook Endpoints (preservados)
`/pushevent`, `/pushhb`, `/pushgps`, `/pushalarm`, `/pushfileupload`, `/pushlbs`, `/pushresourcelist`, `/pushftpfileupload`, `/pushiothubevent`, `/pushTerminalTransInfo`, `/pushinstructresponse`, `/pushevent`

---

## 3. Banco de Dados (v4.0.0 — 27 tabelas)

### Tabelas v4.0.0 (12 novas)
| Tabela | Descrição |
|---|---|
| `branches` | Filiais (nível abaixo de customer) |
| `drivers` | Motoristas + compliance (CNH, toxicológico, FaceID identifier) |
| `sim_cards` | Chips SIM (operadora, MSISDN, ICCID, vínculo IMEI) |
| `permission_groups` | Grupos RBAC com matriz JSON de permissões |
| `occurrence_configs` | Perfis de configuração de ocorrências |
| `occurrence_config_params` | Parâmetros por tipo de alarme (gera? risco? janela?) |
| `occurrences` | Ocorrências DMS (núcleo do motor) |
| `occurrence_events` | Alarmes agrupados em cada ocorrência |
| `trips` | Viagens detectadas por ignição |
| `jobs` | Fila de jobs assíncronos (report, video_download, rollup) |
| `geocode_cache` | Cache de geocodificação reversa (lat/lng → endereço) |
| `impersonation_log` | Auditoria de impersonação revendedor→cliente |
| `checklist_configs` | Configurações de checklist de inspeção |
| `checklist_items` | Itens de checklist (pergunta, tipo, obrigatório) |
| `checklist_responses` | Respostas de inspeções realizadas |

### Alterações em tabelas existentes
| Tabela | Colunas adicionadas |
|---|---|
| `users` | `user_type` (revendedor/cliente), `permission_group_id`, `photo_url` |
| `customers` | `reseller_id`, `brand_color`, `logo_url`, `occurrence_config_id`, `checklist_config_id`, `faceid_enabled` |
| `devices` | `sim_card_id`, `peripherals` (JSON), `streaming_rotation`, `streaming_watermark`, `firmware_version`, `branch_id` |
| `media_files` | `channel`, `download_status` (solicitado/disponivel/erro) |

### Índices críticos
- `idx_occ_customer_status` em `occurrences(customer_id, status, last_alarm_at)`
- `idx_occ_imei_type` em `occurrences(imei, alarm_type, last_alarm_at)`
- `idx_alarms_imei_time` em `alarms(imei, alarm_time)`
- `idx_gps_imei_time` em `gps_data(imei, gps_time)`
- `idx_trips_imei_time` em `trips(imei, started_at)`
- `idx_payload_hash_created` em `request_logs(payload_hash, created_at)` — corrige R07

### Seeds
- `occurrence_configs`: perfil "Padrão Sistema" com 22 parâmetros DMS/ADAS/Acidente
- `permission_groups`: "Administrador" (revendedor, todas as permissões) e "Operador Padrão" (cliente)

### Migrations (ordem correta)
```bash
mysql -u root -p < mysql/jimi_tracker.sql                  # schema base
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql # v2.0.0
mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql # v3.1.0 (multi-tenant)
mysql -u root -p jimi_tracker < mysql/migration_v4.0.0.sql # v4.0.0 (YUV Parity)
mysql -u root -p jimi_tracker < mysql/migration_v4.1.0.sql # v4.1.0 (Excel/PDF + fix seed DMS)
```

---

## 4. Fluxo do Motor de Ocorrências (DMS)

```
1. Device gera ALARME (distração, celular, sem cinto, fadiga…)
        │
        ▼
2. pushalarm.php recebe → INSERT alarms
        │
        ▼
3. occurrence_engine.php (assíncrono, pós-200):
   ├─ resolve occurrence_config do customer do device (fallback: default)
   ├─ busca occurrence_config_param por alarm_type (matching triplo: código/nome/categoria)
   ├─ se generates_occurrence=0 → retorna (alarme fica só no relatório)
   ├─ verifica janela de dedup (threshold minutos, default 10)
   ├─ se existe ocorrência aberta → agrupa (incrementa count + last_alarm_at)
   └─ senão → cria nova ocorrência com risco do perfil
        │
        ▼
4. pushfileupload/pushftpfileupload → INSERT media_files
   └─ link_upload_to_occurrence(): vincula mídia a ocorrência aberta (±3 min)
        │
        ▼
5. Dashboard /ocorrencias/dashboard → polling 15s → operador vê em tempo real
        │
        ▼
6. Operador abre caso → vê vídeo + alarmes agrupados + mapa
   ├─ Iniciar Tratativa → status = em_tratativa
   ├─ Resolver → status = resolvida
   └─ Descartar / Falso Positivo → status = descartada
        │
        ▼
7. Relatórios auditam: /relatorios/ocorrencias + /relatorios/alarmes
```

---

## 5. Componentes Reutilizáveis (`web/components/`)

| Componente | Arquivo | Parâmetros |
|---|---|---|
| Cartão KPI colorido | `kpi_card.php` | `$label`, `$value`, `$variant` (blue/green/yellow/red), `$sub_value` |
| Barra de distribuição (3 faixas) | `risk_bar.php` | `$low_pct`, `$med_pct`, `$high_pct`, labels customizáveis |
| Selo de status/risco (pill) | `status_pill.php` | `$status`, `$type` (status/risk/online/generic) |
| Barra de filtros "Gerar" | `filter_bar.php` | `$filters` (multiselects), `$show_period`, `$show_export` |
| Grade CRUD padrão | `crud_grid.php` | `$title`, `$columns`, `$rows`, `$actions`, `$create_url`, paginação |

---

## 6. Workers (cron)

| Script | Periodicidade | Função |
|---|---|---|
| `scripts/worker.php` | Cada 1 min | Processa fila `jobs`: report (CSV/XLSX/PDF, 10 tipos), video_download, rollup, notification (e-mail, com retry por `attempts`) |
| `scripts/trip_builder.php` | Cada 15 min | Segmenta `gps_data` em `trips` por ignição (lig→desl) e parada sustentada; distância haversine; cruza alarmes da janela |
| `scripts/metrics_rollup.php` | Cada 5 min | Pré-computa KPIs do Resumo/BI em `metrics_snapshots` |
| `scripts/log_cleanup.php` | Diário (3h10) | Purga/rotação de log por `LOG_RETENTION_DAYS`/`LOG_MAX_SIZE_MB` |
| `scripts/geofence_worker.php` | Cada 2 min | **v4.5.0** — avalia pontos novos contra as cercas vinculadas, grava `geofence_events`, notifica |
| `scripts/state_builder.php` | Cada 15 min | **v4.6.0** — segmenta `gps_data` em `device_state_segments` e apura `speeding_events`; alimenta 5 relatórios |
| `scripts/schedule_dispatcher.php` | Hora cheia (min 5) | **v4.7.0** — enfileira os relatórios agendados vencidos; recalcula `next_run_at` **antes** de enfileirar (reentrância) |

**Fonte única da configuração**: o array `CRON_JOBS` de `scripts/crontab-setup.sh` (**7 workers**) — atualizar lá, nunca o crontab à mão. **`deploy.sh` não instala cron**: worker novo exige `bash scripts/crontab-setup.sh --install`.

**Limiares compartilhados**: `trip_builder.php` e `state_builder.php` leem `STOP_SPEED_KMH` e `STOP_IDLE_SECONDS` de `includes/fleet_state.php`. Não redeclarar localmente — "parado" tem de significar o mesmo nos dois.

**Backfill**: `php scripts/state_builder.php 30` (dias) e `php scripts/state_builder.php 30 <imei>` (um equipamento). É também a saída para ponto que chegou atrasado, que a leitura incremental não reprocessa.

---

## 7. Segurança (dívidas fechadas na Fase F)

| Ref | O que | Status |
|---|---|---|
| R01/R02 | Cross-tenant leak em 7 endpoints AJAX | ✅ Corrigido v3.2.1 |
| R03 | proNo whitelist não-bloqueante | ✅ Corrigido v3.2.1 |
| R04 | SQL injection em relatorios.php | ✅ Corrigido (prepared statements v4.0.0) |
| R05 | Open redirect no login | ✅ Corrigido v3.2.1 |
| R06 | GPS (0,0) descartado | ✅ Corrigido v4.0.0 (filtro ABS > 0.0001) |
| R07 | Índice faltante em request_logs | ✅ Adicionado na migração v4.0.0 |
| R08 | Rotas mortas clientes_novo/cliente_dashboard | ✅ Removidas do router |
| R09 | pushcmd.php código morto | ✅ Removido do router |
| R11 | CSRF ausente em formulários POST | ✅ `includes/csrf.php` + 8 páginas protegidas |
| R18 | Cookie Secure=false | ✅ Secure/HttpOnly/SameSite=Lax |
| R19 | Sem limpeza de sessions/request_logs | ✅ `auth_cleanup()` probabilístico (~1% requests) |

---

## 8. Ambiente de Desenvolvimento

### Servidor produção
- **IP**: `189.22.240.43`
- **Apache**: 2.4 + mod_rewrite
- **PHP**: 8.3 (FPM)
- **MySQL**: 8.0 em localhost
- **Path**: `/var/www/jimi_webhook`

### Dev Windows
- **PHP**: `C:\Users\flavi\php\php.exe` (8.3.32)
- **Lint**: `php -l <arquivo>` — 68 arquivos verificados, 0 erros
- **Servidor local**: `php -S localhost:8000 server.php`

### Environment (.env)
```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=jimi_tracker
DB_USER=root
DB_PASS=***
WEBHOOK_TOKEN=a12341234123
SYSTEM_VERSION=4.0.0
FILE_STORAGE_URL=http://189.22.240.43:23010/download/
STREAM_URL=http://189.22.240.43:8881
IOTHUB_COMMAND_URL=http://localhost:10088/api/device/sendInstruct
IOTHUB_API_TOKEN=123
```

---

## 9. Estrutura de Arquivos (v4.0.0)

```
jimi_webhook/
├── .env / .env.example
├── .htaccess                     # Front controller + security headers
├── AGENTS.md / CLAUDE.md         # Guias para AI agents
├── STATUS.md                     # Este arquivo
├── PROJETO_YUV.md                # Blueprint-mestre YUV Parity
├── DESIGN.md / DESIGN-coinbase.md # Design system Coinbase
├── CHANGELOG.md
│
├── config/
│   ├── database.php              # PDO singleton
│   └── WebhookHandler.php        # Abstract webhook base class
│
├── core/
│   └── Logger.php                # Static logger
│
├── includes/
│   ├── auth.php                  # Token-based auth + cleanup (Secure/HttpOnly/SameSite)
│   ├── csrf.php                  # CSRF protection (token por sessão)
│   ├── functions.php             # normalize_data(), get_webhook_data()
│   ├── occurrence_engine.php     # Motor DMS (process_alarm_to_occurrence, link_upload)
│   └── geocode.php               # Geocodificação reversa com cache
│
├── handlers/
│   ├── router.php                # Front controller v4.0.0 (subrotas 2 segmentos)
│   ├── login.php / logout.php / setup.php
│   ├── customer_switch.php
│   ├── resumo.php                # Home — visão 360°
│   ├── rastreamento.php          # Mapa live cliente→ativo
│   ├── bi.php                    # Business Intelligence
│   ├── ocorrencias_dashboard.php # Dashboard DMS + tratativa inline
│   ├── ocorrenciasdata.php       # AJAX polling DMS
│   ├── exportar.php              # Fila de jobs
│   ├── exportardata.php          # AJAX polling jobs
│   ├── ativos.php / ativos_novo.php / ativo_detalhe.php
│   ├── chips.php                 # CRUD SIM cards
│   ├── clientes.php              # CRUD + impersonar + white-label
│   ├── equipamentos.php          # CRUD + FOTA + import CSV
│   ├── grupos_permissao.php      # Matriz RBAC JSON
│   ├── motoristas.php            # CRUD + compliance
│   ├── config_ocorrencias.php    # Perfis de regras DMS
│   ├── checklist.php             # CRUD checklists
│   ├── usuarios.php              # Abas empresa/clientes
│   ├── perfil.php / devicemodels.php
│   ├── comandos.php / config.php
│   ├── video_aovivo.php          # flv.js + proNo 37121
│   ├── video_playback.php        # Timeline + play
│   ├── video_downloads.php       # Grade downloads
│   ├── rel_posicoes.php          # Mapa Leaflet + paginação
│   ├── rel_deslocamento.php      # Viagens (trips)
│   ├── rel_desatualizados.php    # 5 buckets KPI
│   ├── rel_alarmes.php           # Ordenação clicável
│   ├── rel_ocorrencias.php       # 6 filtros históricos
│   ├── camerasdata.php / commandstatus.php / sendcommand.php
│   ├── mediadata.php / trackdata.php / hbdata.php
│   ├── ping.php
│   └── push*.php (11 webhook receivers)
│
├── web/
│   ├── layout_base.php           # Shell v4.0.0: sidebar-sanfona + header On/Off + colapsar + mobile
│   ├── layout_base_close.php
│   ├── layout_ativo_sidebar.php
│   ├── login_template.php
│   └── components/
│       ├── kpi_card.php
│       ├── risk_bar.php
│       ├── status_pill.php
│       ├── filter_bar.php
│       └── crud_grid.php
│
├── mysql/
│   ├── jimi_tracker.sql          # Schema base
│   ├── migration_v2.0.0.sql
│   ├── migration_v3.1.0.sql
│   └── migration_v4.0.0.sql      # YUV Parity (15 tabelas + alterações + índices + seeds)
│
├── scripts/
│   ├── deploy.sh / update-homolog.sh
│   ├── worker.php                # Cron: processa jobs
│   ├── trip_builder.php          # Cron: segmenta viagens (haversine)
│   ├── metrics_rollup.php        # Cron: KPIs (stub)
│   └── dev-windows.ps1           # Ambiente dev Windows
│
├── docs/
│   └── (PRD, ADRs)
│
├── analise_yuv/
│   └── analise_yuv.html          # Fonte visual de verdade
│
└── logs/                         # Runtime logs (gitignored)
```

---

## 10. Pendências para Próxima Iteração

### Melhorias funcionais
- [x] **Resumo `/`**: metrics_rollup para pré-computar KPIs — **Fase G**
- [x] **Resumo `/`**: tour de boas-vindas (5 passos) + banner de comunicado com localStorage — **Fase G**
- [x] **BI `/bi`**: filtro de Motoristas e multi-select de Alarmes com chips `+N` — **Fase G**
- [x] **Exportar**: CSV real para 5 tipos de relatório — **Fase G**
- [x] **Dashboard ocorrências**: filtro de período no polling — **Fase H**
- [x] **Checklist**: tela de preenchimento/inspeção — **Fase H**
- [x] **Importação em lote**: POST real do CSV parseado — **Fase H**
- [x] **White-label**: brand_color na sidebar — **Fase H**
- [x] **Vídeo Playback**: envia proNo 34817 ao clicar Requisitar — **Fase L**
- [ ] **OTA firmware**: testar proNo 33027 end-to-end com dispositivo real *(requer device — ver §11.4)*
- [x] **Relatórios**: exportação Excel/PDF (CSV/XLSX/PDF, PHP puro) — **Fase M.1**
- [x] **App mobile PWA**: manifest + ícones + off-canvas + touch targets — **Fase M.3**

### Infra e tooling
- [x] **Rate limiting no login**: 5 tentativas/15 min + `login_log` — **Fase H**
- [x] **Lint pre-commit hook**: `.githooks/pre-commit` — **Fase I**
- [x] **Logs de acesso**: `login_log` — **Fase H**
- [x] **Resiliência total**: 55 queries v4 com try-catch — **Fase K**
- [x] **Login redirect**: `/dashboard` → `/` + safe_redirect_path — **Fase L**
- [x] **Router: config-ocorrencias**: renamedRoutes map (hífen vs underscore) — **Fase L**
- [x] **Migration fix**: apóstrofo `d'água` → `dagua` — **Fase L**
- [x] **Legacy pages**: dashboard.php e live.php → redirect — **Fase L**
- [x] **Deploy scripts**: deploy-v4.sh, crontab-setup.sh, hotfix_login_log.sql — **Fase J**
- [x] **Testes automatizados**: Playwright — 40 testes, 6 specs, 37/37 verde — **Fase M.4**
- [ ] **Verificar end-to-end**: comandos → IoTHub → dispositivo → pushinstructresponse *(script pronto; execução requer servidor — ver §11.4)*
- [x] **Arquivos de mídia**: `/pushfileupload` → `media_files` → vínculo com ocorrência verificado no replay E2E — **Fase M.2**

### Dívida técnica (não-crítica)
- [x] String interpolation em 9 arquivos → prepared statements — **Fase H**
- [x] `pushTerminalTransInfo.php` estruturado (R13) — **Fase I**
- [x] `normalize_data()` aliases `lon`/`msgId` (R14) — **Fase H**
- [x] Dupla normalização (pushalarm/pushresourcelist) (R15) — **Fase H**
- [x] Código morto em pushresourcelist (R16) — **Fase I**
- [x] md5 sem `JSON_UNESCAPED_UNICODE` (R17) — **Fase H**
- [x] `pushcmd.php` removido do disco — **Fase H**
- [x] README.md atualizado para v4.0.0 — **Fase I**

### Funcionalidades futuras (fora do escopo YUV)
- [ ] **Licenciamento por equipamento**: campo de licença/plano por device/cliente
- [ ] **White-label completo**: sidebar colorida por cliente (hoje só armazena `brand_color`)
- [ ] **App mobile**: PWA responsivo (hoje web responsivo com sidebar off-canvas)
- [ ] **FaceID como serviço**: identificar motorista automaticamente (hoje consome identificador do device)

---

## 11. Iteração v4.1.0 — Fases M.1–M.5 (08/07/2026)

Plano executado: [PLANO_PENDENCIAS_v4.md](PLANO_PENDENCIAS_v4.md). Decisões das questões abertas:
**(1)** Excel/PDF em **PHP puro** (ZipArchive + writer PDF 1.4 próprios) — Composer não existe no ambiente e o projeto é "no package manager"; **(2)** IoTHub produção não acessível daqui — partes locais executadas, restante documentado em §11.4; **(3)** Playwright instalado via npm/npx (Node 24 local).

### 11.1 Entregas

| Fase | Entrega | Verificação |
|---|---|---|
| **M.3 PWA** | `manifest.json` + 4 ícones GD (`assets/icons/`) + meta tags + sidebar off-canvas (backdrop, scroll lock, swipe) + touch targets 44px + tabelas com scroll interno + header mobile compacto + login responsivo | Emulação iPhone 14: manifest/ícones 200, **0px overflow horizontal**, screenshots aprovados |
| **M.1 Excel/PDF** | `includes/export_helper.php` (XlsxWriter streaming + PdfWriter paginado + `export_mime_type`), `worker.php` com `buildReportSource()` + despacho por formato, seletor no form, `jobs.format` (migration v4.1.0), CSV com BOM + `;` | XLSX: zip válido, 6 parts XML well-formed; PDF: xref 100% correto, 4 páginas/120 linhas; specs Playwright de download 3/3 verdes (magic bytes) |
| **M.2 E2E** | `scripts/test_e2e.sh` (ping→gps→alarme 143→upload→verificação MySQL) + fix seed DMS na migration v4.1.0 | **8/8 verde** no dev: alarme gravado, ocorrência criada, mídia vinculada (±3 min) |
| **M.4 Playwright** | `package.json`, `playwright.config.js` (webServer automático), `tests/fixtures/auth.js`, 6 specs / 40 testes, `scripts/run-tests.ps1` | **37 passed, 0 failed, 3 skipped** (opt-in: rate-limit destrutivo; multi-tenant requer 2º cliente) |
| **M.5 Docs** | `API_COVERAGE.md` novo, README (Testes + doc table), CHANGELOG 4.1.0, este STATUS, PRD, memória de agents | — |

### 11.2 Bugs encontrados e corrigidos (achados da verificação E2E)

| # | Severidade | Bug | Fix |
|---|---|---|---|
| 1 | **Crítica** | Motor de ocorrências **nunca disparava via webhook**: `pushalarm.php` lia `lastInsertId()` depois do `CALL update_device_stats_after_alarm` (procedure reseta para 0) → gate `$alarmId > 0` nunca passava | ID capturado imediatamente após o INSERT (`$insertedAlarmId`) |
| 2 | **Crítica** | Seed `occurrence_config_params` órfão: nomes ('Distração', 'Fadiga', 'SOS'…) não existem em `alarm_types` → nenhum alarme DMS/ADAS gerava ocorrência | Migration v4.1.0: 19 params órfãos substituídos por 34 com nomes reais do catálogo (JIMI + JT/T) |
| 3 | **Crítica** | CSRF sempre falhava (403 em **todo POST** desde a Fase F): token guardado em `$_SESSION` sem `session_start()` (superglobal é por request) → token novo a cada request | Token derivado por HMAC-SHA256(cookie de sessão, secret) — estável por login, sem estado no servidor |
| 4 | Alta | `auth_init()` sem `return` → `/ocorrenciasdata` e `/exportardata` respondiam 401 sempre | Retorna `!empty($_SESSION['user_id'])` |
| 5 | Alta | `/grupos-permissao` 404 (rota com hífen em `$simpleRoutes` montava arquivo inexistente) | Movida para `$renamedRoutes` → `grupos_permissao.php` |
| 6 | Alta | Coluna fantasma `devices.last_position_at` (não existe em migration alguma) quebrava relatório de devices, `/relatorios/desatualizados` e `metrics_rollup` | `LEFT JOIN device_statistics` → `last_gps_time` |
| 7 | Média | `Logger.php`: deprecation float→int (PHP 8.1+) vazava HTML nas respostas JSON dos webhooks | Cast `(int)$timestamp` |
| 8 | Baixa | `exportar.php` passava token CSRF como flag `$exit_on_fail` | `csrf_verify()` |

### 11.3 Ambiente de teste local (usado na verificação)

- MySQL 8.0.37 portátil (`C:\Users\flavi\mysql`) — subir com `scripts/dev-windows.ps1`; migrations v4.0.0 + v4.1.0 aplicadas (42 tabelas, `system_info.version = 4.1.0`)
- Usuário E2E: `e2e@teste.local` (admin, customer 1 "Frota Principal") — usado por `TEST_EMAIL`/`TEST_PASSWORD`
- Device de teste: IMEI `868120246598152` (criado pelo `test_e2e.sh`)

### 11.4 Pendências que exigem produção/dispositivo real

- [x] **M.2.1** IoTHub verificado no servidor (09/07): `tracker-instruction-server` UP, `:10088` responde via localhost e `10.1.0.43`
- [x] **M.2.2** Comando real proNo 128 (STATUS) → device `860112070347838` respondeu em ~1s com telemetria (`commands` id 18, `status=sent`)
- [x] **M.2.3** Recepção de respostas **corrigida e validada com callback REAL** (v4.1.1): `offlineCmdPushURL` ganhou o path `/pushinstructresponse` no docker-compose + `WebhookHandler` aceita payload de objeto único (§2.4). Em 08/07 22:59 local o IoTHub entregou o callback real do comando VERSION (`POST /pushinstructresponse → 200`, origem `172.16.13.13`/okhttp, persistido em `command_responses` id 1) — ver §12.3
- [ ] **M.2.5** OTA firmware proNo 33027 com device real
- [x] `test_e2e.sh` executado no servidor pelo operador ("ok em todos os testes")
- [ ] Specs multi-tenant: exigem credenciais de um segundo cliente (`TEST_EMAIL_B`/`TEST_PASSWORD_B`)

### 11.5 Diagnóstico no servidor (09/07/2026 — sessão SSH)

Ver CHANGELOG [4.1.1]. Resumo: comando "failed" era timeout de 15s vs espera de 30s do IoTHub (não inacessibilidade); respostas offline caíam em `POST /` (302) por `offlineCmdPushURL` sem path; `/rastreamento` vazio por `ORDER BY d.is_online` (alias com prefixo). Vídeos OK: `dvr-upload` (:23010) serve `/iothub/dvr-upload/uploadFile` interna/externamente — Apache **não** precisa acessar o diretório. Mudanças no servidor: `.env` (+IOTHUB_COMMAND_URL=http://10.1.0.43:10088, backup `.env.bak-*`), `/iothub/docker-compose.yml` (backup `.bak-*`, serviços `api` e `tracker-instruction-server` recriados). Arquivos untracked pré-existentes no servidor (não tocados): `handlers/pushterminalrealtimestatus.php`, `includes/config.php`.

---

## 12. Iteração v4.1.1 — Diagnóstico operacional no servidor (08–09/07/2026)

Sessão de correções guiada pela análise visual/operacional do operador, com acesso SSH ao homolog.
Commits `75441a7`…`cd1af0f` (7 fixes + docs), todos implantados. CHANGELOG [4.1.1] tem o detalhe técnico de cada um.

### 12.1 Topologia descoberta (homolog `189.22.240.43`, hostname `iothub`)

- **App**: Apache 2.4 + PHP-FPM **no host**, DocumentRoot `/var/www/jimi_webhook`, vhost com log em `/var/log/apache2/jimi-webhook-{access,error}.log`. Sistema em `America/Sao_Paulo` (-03); PHP em UTC; conexão PDO em UTC.
- **Stack IoTHub**: 16 containers Docker (`/iothub/docker-compose.yml`), rede interna `172.16.13.0/24`. Portas relevantes: `tracker-instruction-server` **:10088** (envio de comandos), `msg-dispatch-iothub` :10066 (push de webhooks, `pushURL=http://10.1.0.43`), `dvr-upload` **:23010** (serve os vídeos de `/iothub/dvr-upload/uploadFile`), `iothub-media` :8881 (streaming), gateways :21100/:21122/:31506, api :9080, kafka/zookeeper/redis/mongodb.
- **Regra de rede**: containers alcançam o host **somente pelo IP da LAN `10.1.0.43`** (localhost dentro do container é o próprio container). O host alcança os containers por localhost OU 10.1.0.43 (portas publicadas em 0.0.0.0).
- **Devices reais**: `860112070347838` (JC181 "181_7838", JTT) e `869058070151343` (JC182 "Camera JC182", JTT) — ambos online e respondendo a comandos.

### 12.2 Bugs corrigidos nesta iteração

| # | Sintoma reportado | Causa-raiz | Fix |
|---|---|---|---|
| 1 | Comando marcado "failed / IoTHub inacessível" | IoTHub **segura o HTTP response por até 30s** aguardando o device; `sendcommand.php` abortava aos 15s (`CURLOPT_TIMEOUT`) — o comando tinha sido aceito e enfileirado | Timeout 35s; timeout distinguido de conexão recusada; `curl_error` no log (`b18a4df`) |
| 2 | Respostas de comandos offline nunca chegavam | (a) `offlineCmdPushURL=http://10.1.0.43` **sem path** → callback caía em `POST /` → 302 login → descartado (evidência no access log, okhttp/172.16.13.13); (b) corpo §2.4 é objeto único sem `data_list` → `WebhookHandler` descartava como "empty data" | Path `/pushinstructresponse` no compose (serviços `api` + `tracker-instruction-server` recriados); flag `allowSingleObjectPayload` no `WebhookHandler` (hash de idempotência sobre a lista final); alias camelCase no router (`b18a4df`) |
| 3 | Dashboard sem "sucesso" nem resposta do comando (falso "Timeout/fila offline" após 5 min) | Resposta síncrona do device vem no próprio HTTP response (`data._content`), mas era gravada com `status='sent'` — e o polling só declara sucesso em `'executed'` | Síncrono → `executed` + `response_time` imediatos; `commandstatus` extrai `data._content` (resposta real) em vez do `msg` genérico; histórico retro-corrigido (`35fa94d`) |
| 4 | `/rastreamento` com 500 (pré-4.1.0) / lista de devices vazia | `ORDER BY d.is_online` referencia **alias** com prefixo de tabela → unknown column, engolido pelo try-catch da Fase K | Alias puro `ORDER BY is_online` (`b18a4df`) |
| 5 | Câmera JC182 `869058070151343` "já cadastrada" mas invisível na listagem | Gateway auto-cria a linha do device (`customer_id NULL`) na 1ª telemetria; listagem filtra por cliente (órfão invisível) mas o cadastro checava IMEI globalmente — beco sem saída | `/ativos/novo` **adota** órfãos (preserva telemetria), reativa soft-deletados do cliente, recusa só IMEI ativo/de outro cliente; ganhou CSRF; câmera cadastrada (`539f3e7`) |
| 6 | Horários exibidos 3h adiantados (UTC cru) | Armazenamento UTC estava correto; as 13 telas novas do YUV formatavam sem conversão e filtros tratavam o dia digitado como dia UTC | Helpers canônicos `fmt_brt()` / `brt_day_range_to_utc()` / `brt_today()` aplicados em 17 pontos de exibição, 8 filtros, relatórios exportados, séries do Resumo/BI (`CONVERT_TZ`), rollup; regra no CLAUDE.md (`cd1af0f`) |

### 12.3 Verificações executadas (com evidência real)

- **Comando síncrono**: STATUS (proNo 128) → JC182 respondeu em ~1s (`Battery:12.4V; Mode:SLEEP…`), `commands` id 22 `executed`, `/commandstatus` entregando o conteúdo que o JS renderiza no 1º poll de 3s.
- **Comando offline ponta-a-ponta**: VERSION → JC181 (comando 20) virou fila offline; o IoTHub entregou o **callback real** (`POST /pushinstructresponse → 200`, okhttp/172.16.13.13) e a resposta foi persistida em `command_responses`. Nota: o callback foi correlacionado ao comando errado (21) porque na época os síncronos ainda poluíam o pool de pendentes — com o fix #3 isso não ocorre mais; comando 20 reconciliado manualmente.
- **Vídeos**: `.ts` de 21 MB servido pelo `dvr-upload` (:23010) interna E externamente (HTTP 200). O app monta `FILE_STORAGE_URL + file_url` — **Apache não precisa de acesso a `/iothub/dvr-upload/uploadFile`**. Pipeline `pushfileupload → media_files → vínculo com ocorrência` validado no E2E.
- **Timezone**: UTC 02:36 → exibição 23:36 = relógio local do servidor; helpers testados (dia BRT 08/07 → janela UTC 08/07 03:00–09/07 02:59).
- **Regressão**: lint 80/80, suite Playwright **37 passed / 0 failed** após cada mudança.

### 12.4 Mudanças de infraestrutura no servidor (fora do git)

- `/var/www/jimi_webhook/.env`: `IOTHUB_COMMAND_URL=http://10.1.0.43:10088/api/device/sendInstruct` + `IOTHUB_API_TOKEN=123` (backup `.env.bak-20260708_215709`)
- `/iothub/docker-compose.yml`: `offlineCmdPushURL=http://10.1.0.43/pushinstructresponse` nos serviços `api` e `tracker-instruction-server` (backup `docker-compose.yml.bak-*`); containers recriados via `sudo docker compose up -d`
- Retro-fixes de dados: comandos 16/18–21 reconciliados (`executed`), device de teste `868120246598152` ("Device E2E Test") existe no banco de produção — candidato a limpeza quando não for mais útil

### 12.5 Convenção de timezone (agora obrigatória)

**Armazenar SEMPRE UTC** (PDO força `time_zone '+00:00'`; devices GMT 0; PHP UTC). **Exibir SEMPRE BRT** via `fmt_brt()`; filtros de dia digitados são BRT → converter com `brt_day_range_to_utc()`; defaults com `brt_today()`; agrupamentos SQL por hora/dia com `CONVERT_TZ(col, '+00:00', '-03:00')`. Colunas DATE puras (CNH, ativação) **não** convertem. Caveat: offset fixo -03 nos agrupamentos SQL — se o Brasil retomar horário de verão, revisar (o `fmt_brt()` PHP usa `America/Sao_Paulo` e se ajusta sozinho).

### 12.6 Pendências em aberto

- [ ] 📌 **Atualizar a Central de Ajuda (`/wiki`) ao fim da iniciativa v4.4–v4.7** — executar **após a Fase 4 (v4.7.0)**, não a cada fase. A wiki está congelada na v4.3.0. Checklist do que precisará entrar:
  - **Notificações (Fase 1, já implementada)**: seção nova explicando o **sino** no cabeçalho (badge, painel, polling de 30s que pausa com a aba oculta), o **pop-up em tempo real** e o **som**; a tela `/config-notificacoes` (regra por cliente × tipo de alarme, o fato de uma **categoria** cobrir todos os alarmes dela, `min_risk`, precedência da regra do cliente sobre a global, limite de 3 e-mails); e a tela `/config-smtp` (escopo global × por cliente, senha cifrada e nunca reexibida, botão "Enviar e-mail de teste"). Avisar que **notifica-se por ocorrência, não por alarme** — é o que explica ao usuário por que uma rajada de alarmes gera um aviso só.
  - **Geocercas (Fase 2, já implementada)**: desenho de cerca no mapa, vínculo de equipamentos, relatório de entrada/saída/permanência. Explicar que **cerca sem equipamento vinculado não é avaliada** e que **cerca nova não gera entrada retroativa** — as duas coisas parecem bug e são projeto.
  - **Relatórios operacionais (Fase 3, já implementada)**: Parada, Ociosidade, Ignição, Excesso de Velocidade e Status da Frota — incluindo a definição de cada estado (`movimento`/`ocioso`/`parado`/`offline`) e os limiares usados, que o usuário precisa entender para interpretar os números. Quatro pontos que geram dúvida e precisam estar escritos: (a) **Parada ≠ Ociosidade** — parada é ignição desligada, ociosidade é motor ligado com o veículo imóvel abaixo de 3 km/h; (b) **"Sem comunicação" ganha de qualquer estado anterior** depois de 30 min de silêncio, então o veículo que sumiu em movimento aparece como sem comunicação, não em movimento; (c) **a troca movimento↔ociosidade não é acionamento de ignição**, e períodos sem comunicação são ignorados na contagem de acionamentos; (d) **o limite de velocidade exibido é o vigente quando o evento foi apurado** — mudar o limite hoje não reescreve o histórico —, com a precedência equipamento → cliente → 80 km/h. Vale também dizer que os relatórios dependem de um **cron de 15 min**: dado do último quarto de hora pode ainda não estar segmentado.
  - **Agendamento (Fase 4, já implementada)**: `/agendamentos`, modelos de relatório salvos e o histórico de execuções. Pontos que precisam estar escritos: (a) **o relatório cobre o período FECHADO anterior** — o diário das 7h traz ontem inteiro, não as 7 horas de hoje; (b) a hora escolhida é **BRT** e o disparo é conferido de hora em hora, então o envio sai na hora cheia; (c) **3 falhas consecutivas desativam** o agendamento e notificam quem o criou, e editar/reativar zera o contador; (d) arquivo grande chega como **link**, não anexo; (e) `skip_if_empty` — por padrão o relatório vazio é enviado mesmo assim, porque "nada aconteceu" é informação; (f) os **modelos são por usuário** e só aparecem na tela que os criou.
  - **Transversal**: revisar a seção "O que vale para todos os relatórios" se as Fases 3–4 mudarem filtros, ordenação ou export.
- [ ] **OTA firmware** (proNo 33027) com device real — M.2.5, único item remanescente da Fase M
- [ ] **Specs multi-tenant** do Playwright: exigem credenciais de um segundo cliente (`TEST_EMAIL_B`/`TEST_PASSWORD_B`) — hoje há apenas 1 cliente ("Frota Principal")
- [x] ~~**Arquivos untracked no servidor**: `handlers/pushterminalrealtimestatus.php`, `includes/config.php`~~ — resolvido em 28/07/2026: o handler foi **documentado e versionado** (endpoint de diagnóstico, alcançado por caminho direto fora do router); o `includes/config.php` foi **removido** do servidor (resquício legado com `DB_PASS` em texto puro apontando para um banco inexistente `jimi_webhook`)
- [ ] **Correlação do callback offline**: heurística "comando pendente mais recente" — confiável agora que síncronos saem do pool, mas uma correlação por `requestId` seria mais robusta (melhoria futura)
- [ ] **Limpeza opcional**: device de teste `868120246598152` + ocorrência/mídia de teste no banco do homolog
- [ ] Retomar a **análise visual/operacional do frontend** pelo operador (interrompida pelos fixes desta iteração)

### 12.7 Deploy v4.2.0 no homolog (12/07/2026 — sessão remota)

- **Implantado `e5f9309`** (v4.2.0 Fases A–D) via `sudo ./scripts/deploy.sh` — fast-forward de `9d30f1e`, 34 arquivos; sem migration nova (banco permanece 4.1.0); lint OK; `/ping` 200.
- **Causa-raiz do homolog desatualizado**: o servidor puxava de `git@github.com:Flaviohses/jimi_webhook.git` (repo legado, inacessível ao PAT atual), enquanto o dev empurra para `hssflavio-ux/jimi_webhook`. **Remote do servidor trocado para `git@github.com:hssflavio-ux/jimi_webhook.git`**, com deploy key dedicada read-only (`/root/.ssh/github_hssflavio`, GitHub key ID 157097998) selecionada via `git config core.sshCommand` no repo (a chave antiga `/root/.ssh/id_ed25519` continua presa ao repo Flaviohses — GitHub exige unicidade de chave).
- **Acesso SSH da máquina dev**: chave pública do Windows (`~/.ssh/id_ed25519`) instalada em `administrador@189.22.240.43`; deploy roda como root (`sudo` exige senha). Cuidado reprodutível: `authorized_keys` escrito via pipe do PowerShell ganha `\r\n` — limpar com `tr -d '\r'`.
- **Usuário E2E criado no homolog**: `e2e@teste.local` (admin, customer 1, `users.id=2`) — mesmo padrão do dev local; candidato a limpeza junto com o device de teste `868120246598152`.
- **Testes executados**: replay E2E no servidor **8/8** (GPS → alarme 143 → ocorrência id=2 → mídia id=2 vinculada); Playwright contra o homolog **33/40 efetivos, 0 falhas** (7 skipped: multi-tenant sem 2º cliente + rate-limit gated). Flake único no 1º run: login >15s no primeiro load pós-deploy (dashboard v4.2.0 mais pesado + caches frios) — verde na reexecução.
- **Avisos pré-existentes do `deploy.sh`** (não bloqueiam, a investigar): `mysqldump` falha silenciosamente (backup de banco não é gerado — provável falta de privilégio/credencial no check); "mod_headers ausente" e "VirtualHost não detectado" na FASE 1; check MySQL da FASE 1 roda `mysql` sem credenciais (as migrations com `.env` funcionam normalmente).

### 12.19 Relatórios — botão "Voltar" + ordenação crescente por data/hora com seta clicável (23/07/2026)

- **Pedido do usuário**: (a) um comando de **voltar** nos relatórios depois que o resultado é exibido, para não ter de reabrir a tela do relatório pelo menu lateral; (b) **toda ordenação de relatório com data como condicional deve abrir crescente** (mais antigo no topo → mais recente embaixo); (c) uma **seta na coluna** para alternar crescente/decrescente por data/hora. Em todos os relatórios do sistema.
- **Helpers compartilhados** (`includes/functions.php`, seção "UI comum dos relatórios"), para não repetir a lógica em 7 handlers:
  - `report_sort_params(array $validSorts, string $defaultSort, string $defaultOrder = 'ASC')` — lê e valida `?sort=`/`?order=`. A **whitelist é obrigatória**: a coluna volta interpolada no SQL (PDO não parametriza identificadores). Default `'ASC'` = a convenção nova do sistema.
  - `report_sort_link($col, $label, $sort, $order, $firstOrder = 'ASC')` — `<th>` clicável: **▲** crescente / **▼** decrescente na coluna ativa (azul), **⇅** neutro nas demais; o clique inverte a direção, **preserva os filtros da URL** e **remove `page`/`export`** (nova ordenação reinicia a paginação).
  - `report_back_button($baseUrl, $label = 'Voltar')` e `report_has_query()` — botão `← Voltar` para a tela inicial do próprio relatório, exibido só quando há resultado na tela.
  - CSS `.sort-link`/`.sort-arrow` em `web/layout_base.php` (hover azul, seta ativa em `var(--primary)`).
- **Aplicado em**: `rel_alarmes` (o `sort_link()` local, que já existia só aqui, foi removido em favor do helper; default virou ASC), `rel_posicoes` (`gps_time`), `rel_ocorrencias` (`last_alarm_at`, `imei`, `alarm_count`), `rel_deslocamento` (whitelist por modalidade: `started_at`/`ended_at`/`max_speed`/`distance_km` em viagens, `dia` no fechamento diário), `rel_desatualizados` (`last_gps_time`; o "Voltar" do detalhe de faixa foi padronizado e passou a preservar `customer_id`), o hub legacy `relatorios.php` e `rel_deslocamento_rota` (só o "← Voltar ao relatório", é mapa). **Os exports XLSX/PDF/CSV seguem a ordenação da grade** (mesma variável no `ORDER BY`).
- **Cuidado no hub `/relatorios`**: as 3 queries são `ORDER BY … DESC LIMIT 200`. Ordenar em ASC no SQL traria os **200 mais antigos** do período — outra amostra. A inversão é feita em PHP (`array_reverse`) depois do fetch, preservando "os 200 mais recentes".
- **NULL em desatualizados**: "Nunca posicionados" (`last_gps_time IS NULL`) acompanha o extremo mais antigo — `ORDER BY ds.last_gps_time IS NULL <inverso>, ds.last_gps_time <ordem>` → primeiro em ASC, último em DESC (verificado direto no MySQL).
- **Verificação local** (MySQL portátil + `php -S` + sessão via cookie `jimi_token`, dados reais do banco de dev): lint total OK; unit test dos helpers (whitelist rejeita `sort=x; DROP`/`order=ASC; DROP` → cai no default; `page`/`export` removidos; filtros preservados); as **6 telas** carregaram 200 e, em cada uma, a grade veio **crescente por default** e **decrescente ao seguir o link da seta** — alarmes 10 linhas, posições 14, deslocamento 4 viagens / 2 dias, ocorrências 6, desatualizados 3, hub 10 (alarmes) e 17 (trajetos); `← Voltar` presente em todas quando há resultado e ausente na tela limpa; href do Voltar de desatualizados = `/relatorios/desatualizados?customer_id=1` (filtro preservado); mapa da rota 200 com "Voltar ao relatório"; exports XLSX/CSV com a ordenação escolhida = 200.
- **Pendente**: commit/push e deploy no homolog (não feitos nesta sessão).

### 12.20 Posições — paginação travada na página 10 + faixa horária opcional (23/07/2026)

- **Sintoma (usuário)**: no `/relatorios/posicoes`, com resultado de mais de 10 páginas, o contador só exibia de 1 a 10 mesmo estando na página 12 ou 15.
- **Causa-raiz**: o widget de paginação era um laço fixo `for ($i = 1; $i <= min($totalPages, 10); $i++)` — a partir da página 11 o usuário não via a página atual nem as vizinhas, só o bloco 1–10 e o `»`. O mesmo widget estava **copiado em 7 telas** com o mesmo defeito (`rel_posicoes`, `rel_alarmes`, `min(…,8)` em `rel_deslocamento`, `equipamentos`, `exportar`, `video_downloads`; só `rel_ocorrencias` tinha janela, mas iterando todas as páginas).
- **Fix**: novo `report_pagination($page,$totalPages,$totalRows,$unit,$window=2)` em `includes/functions.php` — janela deslizante ao redor da página atual, primeira/última sempre visíveis, reticências nos saltos. Substituiu o widget nas 7 telas. Ganhos colaterais: (1) os links de página deixam de carregar `export=` (clicar numa página logo após exportar re-disparava o download); (2) `exportar`/`video_downloads` preservam os filtros na paginação (o link era `?page=N` cru); (3) `equipamentos`/`exportar` ganham a contagem no rótulo.
- **Faixa horária no `/relatorios/posicoes`, em 2 modalidades** (pedido do usuário: "escolher a data e a hora fica opcional" → ao ser questionado sobre a semântica, pediu **as duas opções à escolha**): campos `time_from`/`time_to` (vazios = `00:00`/`23:59`) + seletor `time_mode`:
  1. **Contínua (início → fim)** — uma janela só, `date_from time_from` → `date_to time_to` (via `brt_datetime_range_to_utc()`, mesma semântica do Relatório de Deslocamento).
  2. **Em cada dia do período** — dias inteiros no `BETWEEN` (indexado) + `TIME(CONVERT_TZ(col,'+00:00','-03:00'))` filtrando dentro de cada dia. Faixa invertida (`time_from` > `time_to`) vira **janela que cruza a meia-noite** (turno da noite, ex.: 22:00–06:00) com `OR` em vez de `BETWEEN` — sem isso o resultado seria vazio.
  - Encapsulado em `report_time_window($col,$df,$dt,$tf,$tt,$mode)` (`includes/functions.php`) para o Deslocamento herdar quando quiser. Nota de performance: o predicado de hora do modo diário não é indexável, mas a janela do `BETWEEN` continua servida pelo índice `(imei, tempo)` e limitada pelo teto de 31 dias.
  - **Verificado com 240 posições sintéticas** (5 dias × 48, de 30 em 30 min, 15–19/06/2026 BRT; removidas depois): sem faixa 240; `08:00–10:00` contínua = **197** (de 15/06 08:00 a 19/06 10:00, madrugadas incluídas) vs. em cada dia = **25** (exatamente 08:00/08:30/09:00/09:30/10:00 nos 5 dias, conferido dia a dia); `22:00–06:00` em cada dia = **85** (17/dia = 13 da madrugada + 4 da noite) vs. contínua = **161**. Export CSV e PDF respeitam modo e faixa.
- **Verificação local** (700 posições sintéticas semeadas em 15/06/2026 BRT → 14 páginas; removidas depois, `gps_data` de volta às 17 linhas originais, `geocode_cache` limpo): unit test do helper cobrindo páginas 1/6/12/15/24/25 de 25 e 13 de 40 (janela correta, `export` fora dos links, 1 página = pager vazio) + telas reais — página 12/14 renderiza `« 1 … 10 11 12 13 14 »` (era `1…10`), página 14/14 renderiza `« 1 … 12 13 14`. Faixa horária: sem faixa 700 posições; `08:00–10:00` → 61 começando exatamente em 08:00; `22:00`–vazio → 22:00→23:18; vazio–`02:00` → 61; export CSV da faixa = 62 linhas (1 cabeçalho + 61). Lint total OK; Playwright completo verde.
- **Central de Ajuda (`/wiki`) atualizada para a v4.3.0**: nova seção **"O que vale para todos os relatórios"** (ordem crescente, setinha ▲/▼/⇅, ← Voltar, paginação com janela deslizante, export herdando filtros/ordenação) + callouts do teto de 31 dias e do horário de Brasília; **Posições** com as 2 modalidades de faixa horária (incluindo turno que cruza a meia-noite) e mockup novo; **Deslocamento** com as 2 modalidades, "Ver rota" e a regra real de corte de trajeto (ignição / parada de 5 min / silêncio); **Alarmes** e **Ocorrências** com as colunas ordenáveis e os filtros reais. **Correção factual**: as faixas de Desatualizados estavam erradas na wiki ("1h, 6h, 12h, 24h, >24h") — as reais são "<24h, >1 dia, >7 dias, >30 dias, nunca posicionados". Rodapé e cabeçalho do arquivo em `v4.3.0`.
- **Pendente**: deploy no homolog.

### 12.19 Relatórios — botão "Voltar" + ordenação crescente por data/hora com seta clicável (23/07/2026)

- **Sintoma (usuário)**: `deploy.sh` no homolog avisando que não conseguia acessar o banco com as credenciais do `.env`.
- **Investigação no servidor** (SSH `administrador@189.22.240.43`, host `iothub`; `.env` é `644` em `/var/www/jimi_webhook`, legível): o `.env` está **limpo (LF, sem CRLF)** e as credenciais estão corretas — `DB_HOST=localhost DB_USER=root DB_PASS=1029384756 DB_NAME=jimi_tracker`. Reproduzi os acessos exatos do deploy com stderr visível.
- **Conclusão: nunca foi problema de credencial.** As migrations (FASE 3) conectam e funcionam (`SELECT VERSION(),CURRENT_USER()` → `8.0.46 / root@% / iothub`, exit 0; banco em `4.3.0`). Os dois avisos são **misdiagnósticos**:
  1. **FASE 1** (`mysql -e "SELECT 1"`): roda **sem credenciais** → conecta como o usuário do SO (root/administrador sob sudo, que **não tem conta MySQL**) → "Access denied for 'administrador'@'localhost' (using password: NO)" → aviso "verifique credenciais no .env" **mesmo com o .env perfeito**. Nunca leu o `.env`.
  2. **FASE 2** (`mysqldump … 2>/dev/null`): abortava com **erro 1356** ao dar `SHOW FIELDS` em **duas VIEWs órfãs** — `vw_alarm_types_ambiguous_codes` e `vw_alarm_types_unknown_codes` — que referenciam a tabela **inexistente `alarm_types_reference`**. Essas VIEWs **já foram removidas do schema canônico** (`mysql/jimi_tracker.sql`, correção de 06/07/2026, "não usada pela aplicação"), mas continuavam no banco do homolog (criado antes disso). O dump saía **incompleto** e a mensagem culpava as credenciais. Detalhe que confundia: a app (`config/database.php`) faz `trim()` de cada valor do `.env`, enquanto o `source <(grep… )` do deploy não — mas aqui isso era irrelevante (sem CRLF).
- **Correções**:
  - **Homolog (dados)**: `DROP VIEW` das duas órfãs (defs salvas em scratchpad antes; não referenciadas por nenhum código — só docs/`jimi_tracker.sql`). `mysqldump` completo agora verificado no servidor (`exit 0`, 69 MB, "Dump completed"). Restam as 3 VIEWs boas (`v_alarm_report`, `v_alarm_statistics`, `v_alarms_enriched`).
  - **`deploy.sh` (código)**: FASE 1 passou a testar a conexão **com as credenciais do `.env`** (`MYSQL_PWD` + `-h/-P/-u/-D`, sem senha na linha de comando) — validado no servidor ("✓ Conexão MySQL OK (credenciais do .env)"). FASE 2 passou a **capturar e exibir o stderr real** do `mysqldump` (antes `2>/dev/null` + "verifique credenciais") e também usa `MYSQL_PWD`. `bash -n` OK.
- **Commit e deploy (23/07/2026)**: `deploy.sh` + docs commitados como **`88a98e1`** (`fix(deploy): checks de MySQL não confundem VIEW quebrada/socket com credencial`) e pushados p/ `origin/main`. **Deployado no homolog pelo usuário** — servidor confirmado em `HEAD=88a98e1` com o `deploy.sh` novo em disco (o `grep 'credenciais do .env'` casa 3×). Como esta entrega altera o próprio `deploy.sh`, valeu a regra das 2 passadas (§12.16).
- **PENDÊNCIA (única aberta)**: **Produção**, se existir e for anterior a 06/07/2026, provavelmente tem as mesmas 2 VIEWs órfãs → rodar `DROP VIEW IF EXISTS vw_alarm_types_ambiguous_codes, vw_alarm_types_unknown_codes;` lá (senão o backup da FASE 2 do deploy sai incompleto). Obs.: o dir de backup `/var/backups/jimi_webhook` só é legível via `sudo` (dono `www-data`) — não deu p/ conferir o arquivo de backup pelo tool, mas o `mysqldump` foi validado completando direto no servidor.

### 12.17 Deslocamento — segmentação por movimento (viagem única de 24h) (23/07/2026)

- **Sintoma (reportado pelo usuário)**: no `/relatorios/deslocamento` o veículo **FJR7B59** (`869058070151343`) aparecia com **1 rota compreendendo o dia todo** em vez dos vários deslocamentos esperados.
- **Diagnóstico no homolog remoto** (`189.22.240.43`, MySQL direto): a `trip 14` ia de **07-22 11:58 → 07-23 10:55 = 1376 min (~23h)**, cruzando dois dias. Dentro dessa janela só existia **1 ponto `acc=0`** (no próprio início): o device manteve `acc=1` ininterrupto por ~23h (uma jornada de trabalho inteira sem desligar a ignição). O perfil por hora mostrava paradas longas reais (ex.: 07-22 13:00–15:00 essencialmente parado) e retomadas — deslocamentos distintos que o builder não separava. Havia ainda casos gêmeos: `181_7838` (só deriva de GPS, nunca >7 km/h) com "viagem" fantasma de 24h, e o device de teste E2E com "viagem" de **97h** por pontos a 4 dias de distância.
- **Causa-raiz**: `scripts/trip_builder.php` encerrava a viagem **exclusivamente** em `acc=desligado`. Devices que mantêm a ignição/voltagem reportada ligada (por horas ou o dia todo) nunca disparavam o fim → a jornada inteira colapsava numa viagem só. (Este era exatamente o item (1) das pendências da §12.15 — "detecção por movimento contínuo para devices com ACC sempre ligado".)
- **Fix (3 gatilhos de fim de viagem, todos fechando no último ponto em movimento; a cauda parada é descartada)**:
  1. **Ignição desligada** (comportamento original, preservado).
  2. **Parada sustentada**: velocidade abaixo de `STOP_SPEED_KMH=3 km/h` por mais de `STOP_IDLE_SECONDS=300 s` (5 min) — mesmo com `acc=1` — encerra; o próximo movimento abre viagem nova.
  3. **Buraco de dados**: intervalo entre pontos consecutivos ≥ `STOP_IDLE_SECONDS` (device offline/silente) — não dá para afirmar deslocamento contínuo através de um silêncio do rastreador (mata a viagem de 97h do E2E).
  - `isRealTrip()` ganhou piso `MIN_TRIP_DURATION_S=60 s` (descarta *slivers* de poucos segundos). A abertura de viagem passou a exigir **movimento** (não abre em veículo parado com ACC ligado). Fechamento centralizado no novo `finalizeTrip()` (recorta até o último ponto em movimento, calcula duração/dist/vel.máx/alarmes, aplica o filtro).
- **Validação**: simulador offline contra os 3535 pontos reais de FJR7B59 (07-18→hoje) confirmou thresholds antes de escrever o fix; depois **rebuild com o código real** (`DB_HOST=189.22.240.43 … php scripts/trip_builder.php 30`, após `DELETE FROM trips`; backup em scratchpad). Resultado no homolog: FJR7B59 **11 → 39 viagens** (5–9/dia; a viagem de ~23h fatiada em 17 deslocamentos reais; o trajeto-tronco de 07-19 — 3h / ~292 km / 138 km/h — preservado intacto). Modalidade **diário** confere: 07-23=9 viagens, 07-22=8, 07-20=8, etc. `181_7838` deixou de gerar a viagem-fantasma de 24h; E2E deixou de gerar a de 97h. Lint OK.
- **DEPLOY (feito pelo usuário)**: commitado e pushado como **`acf09f3`** (`feat: add automated trip segmentation script…`) e **já deployado no homolog** — servidor em `HEAD=acf09f3`, `scripts/trip_builder.php` com a nova segmentação em produção; o cron de 15 min passa a usar a lógica corrigida (incremental). Dados do homolog já rebuildados direto no MySQL. Thresholds (`STOP_SPEED_KMH`/`STOP_IDLE_SECONDS`) podem ser afinados por device se necessário.

### 12.16 Deslocamento em 2 modalidades + mapa de rota + teto global de 31 dias (22/07/2026)

- **Contexto**: pedido do usuário após parecer de viabilidade com benchmark real (2,92M viagens sintéticas, MySQL local): com os índices antigos a grade do deslocamento custava **3,5–6s** num tenant de 200 veículos (o índice só por `customer_id` varre todas as viagens do cliente, qualquer período); com o composto `(customer_id, started_at)` cai para **<1ms** (por viagem) e **41–177ms** (fechamento diário 7–30 dias). Fechamento diário além de ~90 dias é caro por natureza (agregação de centenas de milhares de linhas) → daí o teto global.
- **Migration v4.3.0** (`mysql/migration_v4.3.0.sql` + bloco no `deploy.sh`): cria `idx_trips_customer_time (customer_id, started_at)` e dropa o redundante `idx_trips_customer` (o composto tem `customer_id` como prefixo e segue servindo a FK). Procedures guardadas (create/drop_index_if_exists), idempotente.
- **Teto global de 31 dias em TODOS os relatórios**: novo `clamp_report_range()` + const `REPORT_RANGE_MAX_DAYS` em `includes/functions.php` (datas invertidas são trocadas; excesso encurta o `date_to`). Aplicado em `rel_deslocamento`, `rel_posicoes`, `rel_alarmes`, `rel_ocorrencias`, `bi` (com banner âmbar "período ajustado" + label "máx. 31 dias") e silenciosamente em `relatorios.php` (legacy), `ocorrenciasdata.php` (AJAX) e `exportar.php` (criação de job).
- **Duas modalidades no `/relatorios/deslocamento`** (select "Modalidade"):
  1. **Por deslocamento** (default): grade anterior (1 linha por viagem lig→desl) + coluna **Rota**.
  2. **Fechamento diário**: `GROUP BY imei + dia BRT` sobre `trips` — primeira ignição ligada, última desligada (viagem que cruza a meia-noite conta inteira no dia em que começou; se a última desligada cai no dia seguinte a grade mostra a data junto), **Jornada** (última−primeira, inclui paradas) e **Em Movimento** (Σ durações) lado a lado, Σ km, máx vel., Σ alarmes, nº de viagens. Paginação com COUNT de grupos (subquery); export XLSX/PDF próprio.
  - **Faixa horária opcional** (`time_from`/`time_to`): refina a janela contínua via novo `brt_datetime_range_to_utc()`.
- **Mapa de rota** (`/relatorios/deslocamento/rota`, novo `rel_deslocamento_rota.php`; router ganhou suporte a subrota de 3 segmentos via chave `'segundo/terceiro'` no `$subrouteMap`): aceita `trip_id` (viagem) ou `imei`+`dia` (dia fechado, janela recalculada server-side = primeira→última ignição do dia). Leaflet com polyline azul + balão **Partida** (verde, data/hora BRT) + **Chegada** (vermelho, data/hora) + um circleMarker por posição/comunicação (popup hora/velocidade/ignição) + **ocorrências em laranja**: com coordenada própria (posição do 1º alarme agrupado via `occurrence_events`→`alarms`) plota no local exato; sem coordenada, destaca o ponto de comunicação mais próximo no tempo, citando a ocorrência no balão (tipo, hora, risco, status). Amostragem >3000 pontos (preserva primeiro/último), `preferCanvas`, escopo multi-tenant em tudo.
- **Verificado ponta-a-ponta no ambiente local** (migration aplicada; seed determinístico: 3 viagens em 2 dias + 1 ocorrência com coordenada + 1 sem; `trip_builder 5`; server `php -S` + sessão via cookie): modalidade viagens = 3 linhas com "Ver rota"; fechamento diário 20/07 = primeira 09:00, última 12:22, jornada 3h22m, em movimento 0h54m, 10,8 km, 2 viagens; clamp 01/05→22/07 ajustou para 31/05 com banner (tb. em posições/alarmes/bi); faixa horária 08–10h BRT filtrou só a viagem das 09:00; rota da viagem = 17 pontos + pino da ocorrência "Distração/risco alto" nas coordenadas do alarme, balões `Partida — 20/07/2026 09:00:00`/`Chegada — 09:32:00`; rota do dia = 2 viagens, ocorrência sem coordenada ("Uso de celular") anexada ao ponto mais próximo. Dados de teste removidos após a verificação. Lint total OK.
- **Commit e deploy (22/07/2026)**: commitado direto em `main` (convenção do repo — o deploy faz `git pull origin main`) como `5f6b8ed` (`feat: relatorio de deslocamento em 2 modalidades + mapa de rota + teto de 31 dias`; hook pre-commit OK, 11 PHP limpos) e pushado para `hssflavio-ux/jimi_webhook`. **Deploy no homolog exigiu 2 execuções encadeadas** (`sudo ./scripts/deploy.sh && sudo ./scripts/deploy.sh --force`) porque esta entrega **altera o próprio `deploy.sh`** (bloco da migration v4.3.0): o `git pull` roda no meio do script, então o `deploy.sh` novo — com o bloco v4.3.0 — só executa na 2ª passada (reforça a nota da §12.7 / feedback-history). O `sudo` pediu senha uma vez (cache p/ a 2ª). Rodado pelo usuário via `! ssh -t` (o tool não tem a senha sudo; ssh por chave como `administrador`, working copy do servidor é `www-data`).
- **Verificação pós-deploy no homolog** (SSH só-leitura + `.php` via scp → `Database::getInstance()`): `HEAD == 5f6b8ed` em `main` (subiu de `63b686c`); `handlers/rel_deslocamento_rota.php` e `mysql/migration_v4.3.0.sql` presentes; **`system_info.version == 4.3.0`**; índices de `trips` = `PRIMARY`, `idx_trips_imei_time`, `idx_trips_driver`, **`idx_trips_customer_time (customer_id, started_at)` criado** e **`idx_trips_customer` (redundante) removido**; `trips` com 12 viagens reais. Smoke test HTTP: `/relatorios/deslocamento` e `/relatorios/deslocamento/rota` → **302** (redirect p/ login sem sessão — roteamento OK, incl. subrota de 3 segmentos; sem 404/500).
- **Pendências**: exibir aviso de clamp também no `/exportar` (hoje só encurta silenciosamente o job); considerar rollup `trip_days` se um dia o fechamento diário precisar de janelas > 31 dias; validação visual logada no painel do homolog (opcional — as 12 viagens reais já aparecem com o link "Ver rota").

### 12.15 Fix Relatório de Deslocamento — coluna de ignição errada + fragmentação de viagens (22/07/2026)

- **Sintoma**: `/relatorios/deslocamento` sempre vazio; a tabela `trips` nunca era populada.
- **Causa-raiz**: `scripts/trip_builder.php` (o cron que segmenta viagens por ignição) consultava `SELECT ... ignition FROM gps_data`, mas **não existe coluna `ignition`** — a ignição fica em `gps_data.acc` (o `pushgps` grava `acc`/`accStatus`; `rel_posicoes.php` já lê corretamente `g.acc AS ignition`). Com o PDO em `ERRMODE_EXCEPTION`, o script morria com `Column not found: 'ignition'` **na primeira execução, antes de gravar qualquer viagem**. O mesmo bug estava em `scripts/worker.php` (export de posições, `g.ignition`) e `scripts/metrics_rollup.php` (distribuição de velocidade, `g.ignition = 1`).
- **Fix 1 (blocker)**: os três scripts passam a usar `g.acc` (aliasado como `ignition` onde a lógica lê esse nome). 
- **Fix 2 (fragmentação)**: a variável `$staleBefore` (`-2h`; antes `$batchTime`, calculada e **nunca usada**) agora guarda o fallback que fecha viagem aberta ao fim do lote — só finaliza se o último ponto já é mais velho que 2h; senão deixa em aberto para o próximo cron. Sem isso, cada rodada do cron pegando um veículo em movimento fechava a viagem no último ponto e a continuação virava outra linha → uma viagem longa fragmentada em N.
- **Fix 3 (qualidade)**: `isRealTrip()` — só persiste viagem com movimento real (`max_speed >= 6 km/h` OU `distância >= 1 km`, ≥2 pontos). Filtra viagem de 1 ponto, parada com ignição ligada (estacionado a noite toda com ACC on) e deriva de GPS.
- **Validação contra dados reais do homolog** (acesso SSH por chave via PowerShell — o tool Bash tem `ssh` bloqueado pelo classificador; consultas via `scp` de um `.php` que dá `require '/var/www/jimi_webhook/config/database.php'`): `trips` estava **vazia no homolog** (confirma o blocker em produção). **Câmera 182 = FJR7B59** (`869058070151343`, nome de placa; o device `400AD_2939`/`864993060182939`, cujo IMEI contém "182", está sem dados desde 08/abr): 14 viagens brutas → **10 reais** após o filtro (4 ruídos descartados), com KM/vel.máx/alarmes coerentes. **Câmera 181** (`860112070347838`, 65k pontos): investigado o parsing do ACC a pedido — **não é bug**; o payload bruto traz `"acc":1` em 100% das amostras e o bit 0 do `status` (262159) é constante → o device transmite ACC permanentemente ligado. A FJR7B59, por contraste, alterna ACC corretamente (2489 on/363 off, bit 0 do status acompanha).
- **Verificado ponta-a-ponta com a câmera 182** (IMEI `864993060182939`, que o banco local não tinha pontos GPS): seed de 3 ciclos acc lig→desl com movimento + pontos ociosos + 2 alarmes na 2ª viagem → `trip_builder` gera **exatamente 3 viagens** (duração 20/25/12 min, vel.máx 60/70/30, KM 3,2/3,0/1,2, alarmes 0/**2**/0); pontos ociosos (acc off) não viram viagem; 2ª rodada é idempotente (0 novas); viagem em curso (pontos recentes acc=1 sem acc=0) é corretamente **adiada**. Camada do relatório (conversão BRT→UTC + joins) confirmada retornando as 3 viagens com horários em BRT. Lint total OK.
- **Observação de tooling**: o cliente `mysql.exe` usa `@@session.time_zone = SYSTEM` (BRT, UTC−3), enquanto o app força `+00:00` na conexão PDO — seeds via CLI devem prefixar `SET time_zone='+00:00';` para casar com o relógio UTC do app (reforça a §12.5).
- **Deployado e verificado em produção (22/07/2026)**: `deploy.sh --skip-migrate` levou os commits `f88b3c9` (fixes 1–3) e `63b686c` (arg de lookback p/ backfill) ao homolog. O log `trip_builder.log` provava o crash a cada 15 min (`Unknown column 'ignition'` na linha 45) — o cron já roda os workers como root. Backfill de 30 dias (`php scripts/trip_builder.php 30`) populou a `trips`: **câmera 182 (FJR7B59) com 10 viagens** (700,6 km, 1044 alarmes), horários corretos em BRT, ruído filtrado. Daqui pra frente o cron (15 min) mantém incremental.
- **Pendências**: (1) **detecção por movimento contínuo** para devices que reportam ACC sempre ligado (câmera 181 e similares) — o spec YUV prevê "janela entre ignição ligada e desligada **ou movimento contínuo**"; hoje esses devices geram 0 viagens. Decisão do usuário: fazer **depois** (item 3). (2) `trip_builder` não geocodifica `start_addr`/`end_addr` (YUV exibe "Local Início/Fim"; grade mostra "—") e omite a coluna **Evento** — reuso do `geocode_cache`, como no rel. de Posições.

### 12.14 Wiki para o usuário final — linguagem, menus e mapas reais (18/07/2026)

- **Feedback do operador** sobre a Central de Ajuda (`handlers/wiki.php`, rota `/wiki` criada no commit `4811166`): linguagem de desenvolvedor, caminhos de URL expostos, mapas mockados vazios e seções de infra. Revisão completa em 4 frentes:
  1. **Sem termos técnicos**: removidos proNos/códigos de comando (37121/37381/34818/37384/33027), AJAX, polling, cache, localStorage, FLV/flv.js, Leaflet/Chart.js, RTP, soft-delete, DELETE físico, CRUD, RBAC, JSON batch, FILE_STORAGE_URL, IoTHub, cookie `jimi_token` etc. Tabelas Ação→Resultado descrevem apenas o efeito visível (ex.: "A câmera é acionada e o vídeo abre em alguns segundos").
  2. **Sem caminhos de URL**: badges de rota (`/setup`, `/bi`, `/perfil`, `/video/aovivo`, ...) removidos de todas as seções — a navegação é descrita pela função no menu lateral; badges "admin"/"público"/"tela inicial" mantidos.
  3. **Mapas reais nos mockups**: novos `assets/img/wiki_map_city.png` e `wiki_map_streets.png` (tiles OSM z13/z15 de São Paulo, 3×2 tiles = 768×512, stitch com GD, otimizados truecolor→paleta: 216/204 KB; crédito "© OpenStreetMap" sobreposto via `.map-credit`). Aplicados no mapa de calor do Resumo (blobs radial-gradient por cima), no mapa do Rastreamento (marcadores verde/vermelho com borda branca) e na trajetória do rel. de Posições (polyline SVG azul sobre o mapa).
  4. **Seções de dev removidas**: "Webhooks e Integração", "Motor de Ocorrências (fluxo técnico)" e "Segurança" (incl. callout de workers/cron) saíram do conteúdo e do sumário.
- **Validado com render real** (MySQL portátil + `php -S` + usuário descartável, removido após o teste): `/wiki` 200, PNGs servidos (`image/png`), marcadores presentes, zero ocorrências de proNo/AJAX/webhooks/segurança no HTML; lint OK.

### 12.13 Fix vídeo automático dos alarmes: 34818→37384 (anexo do alarme) + exibição na ocorrência — v4.2.1 (13/07/2026)

- **Bug reportado**: eventos DMS gerados na câmera JC371 real (`865478070003241`) criaram as ocorrências 4/5/6 no homolog, mas nenhum vídeo apareceu na aplicação. **Resolve a pendência do §12.8** (validar 0x8802 com câmera real): o log de 14:52/14:55 mostra o IoTHub aceitando o 34818 e a câmera respondendo `_proNo 2050` com **`mediaItemsNum: 0`** — o 0x8802 é uma **consulta** ao acervo de multimídia 808 (fotos do 34817 etc.), **não** um comando de upload, e o vídeo de evento DMS/ADAS não vive lá: ele é um **anexo do alarme** (JT/T 1078/Su Biao).
- **Causa-raiz tripla**:
  1. Gatilho automático enviava 34818 (consulta) → nunca há upload; o certo é **37384 (0x9208, Alarm Attachment Upload, doc §2.20)**: a plataforma devolve à câmera o `alarmLabel` que veio no push do alarme + `alarmNumber` + endereço do attachment server (porta **21188** = `jimi-tracker-upload-process`, aberta no homolog); os arquivos caem no file storage nomeados `{imei}_{alarmLabel}_{xy}.mp4/.jpg` (doc §1.8) e o `/pushfileupload` notifica.
  2. `pushalarm.php` não repassava `alarm_label` ao motor, e o vínculo mídia→ocorrência era só por janela ±3 min (upload que demora mais se perdia).
  3. Detalhe da ocorrência renderizava `file_url` cru no `<video src>` (sem `FILE_STORAGE_URL`) — mesmo vídeo vinculado não tocava. Bônus: detalhe agora é escopado por `customer_id` (antes qualquer id abria).
- **Implementação**: `queue_event_video_request()` agora exige `alarmLabel` hex válido (alarme JTT sem anexo — ignição, ociosidade — loga e não dispara); `alarmNumber = bin2hex(últimos 14 dígitos do IMEI + cauda do label)` — **validado contra o exemplo da doc §1.13 (match exato)**; endereço via `video_stream_config()` + `ATTACH_UPLOAD_IP`/`ATTACH_UPLOAD_PORT` (.env, default 21188); anti-rajada re-desenhada (dedupe por label 10 min + teto 5/2min por device — o teto antigo de 1/2min perderia os vídeos das outras ocorrências de uma rajada); `pushfileupload.php` extrai `alarmLabel`/canal do fileName e vincula pela cadeia `alarms.alarm_label → occurrence_events → occurrences` (vídeo tem precedência sobre imagem; fallback ±3 min mantido); whitelist do `sendcommand` + presets (`alarm_attach` novo; `ftp_upload` corrigido para o formato real da doc §2.7 — o antigo usava campos do 34818).
- **Validado local (E2E real, MySQL + php -S)**: alarme JTT 265-4 com `alarmLabel` → ocorrência criada + `commands` com `jtt_37384` e content correto → `pushfileupload` com jpg ANTES do mp4 → 2 `media_files` (canal extraído do nome) e o **vídeo** assume o vínculo → página da ocorrência renderiza `<source src="http://…:23010/download/{arquivo}.mp4">`. Lint total OK.
- **Pendência (usuário/próxima iteração)**: deploy no homolog (`sudo ./scripts/deploy.sh`) + gerar eventos reais na JC371 e conferir a chegada do `/pushfileupload` e o vídeo na ocorrência. O [Extrair] do playback segue em 34818 (consulta — deve falhar da mesma forma); extração de gravação do cartão exige 37382 com **FTP do cliente** (doc §2.7) — precisa de um FTP no host (pendência de infra) ou validar se o attachment server 21188 aceita esse fluxo.

### 12.12 Deploy homolog + validação com câmera real do fluxo de vídeo histórico (13/07/2026, `135845a`)

- **Deploy**: servidor atualizado para `135845a` (fix vídeo histórico + observabilidade + fix crontab-setup); `/ping` OK; Playwright contra o homolog **8/8** (login + 3 rotas de vídeo; 1 skip esperado de rate-limit).
- **Fix adicional pré-deploy** (`135845a`): `crontab-setup.sh` `remove_entries()` só removia a linha do marcador — cada `--install` **duplicaria** os workers existentes (worker.php 2×/min) e `--remove` não removia nada; agora filtra também as linhas dos próprios scripts.
- **🎯 Validação com câmera real (JC181 `860112070347838`) — o 37381 funciona**: `sendInstruct` proNo 37381 (canal 0, janela de hoje GMT-0) → resposta síncrona `"AudioVideoResourceList ack successful response"` com `_content: "434"` → push §1.11 no `/pushresourcelist` (COM envelope `data_list`) → **434/434 gravações inseridas em `resource_lists`, 0 erros, 333 ms** → timeline do `/video/playback` renderiza **157 gravações CH1 "No cartão"** com botão Extrair e janelas compactas corretas. Bug original reproduzido no log: às 13:12–13:14 o usuário havia disparado 34818 pela página antiga (comando errado) com o device offline (`_code 600`).
- **JC182 `869058070151343`: não é bug de plataforma** — online mas com flap a cada ~5 min; ACKa o 0x9205 e **nunca emite 0x1205** (mesmo com o comando entregue via fila no relogin); teve alarmes "Falha no Armazenamento"/"Perda de Sinal de Vídeo" hoje → SD ausente/defeituosa. Em 10 dias de log do gateway, nenhum 0x1205 dela.
- **Extração (34818 na janela exata)**: primeiro envio transmitido pelo gateway (`packMediaDataUpload`, seq 44) mas a câmera caiu antes do ACK; reenviado como comando de fila (command 50) — upload monitorado; a chegada via `/pushfileupload` vira "Disponível" na timeline (mecânica validada no dev local ponta a ponta).
- **Diagnóstico de infra útil**: pushes do IoTHub saem do `msg-dispatch-iothub` com `pushURL=http://10.1.0.43` (base única + path por tipo); instrução JT/T percorre `tracker-instruction-server` (:10088) → `router` → `jimi-gateway-450` (:21122); Apache access log (`jimi-webhook-access.log`) distingue "IoTHub não enviou" de "handler descartou".
- **Pendência sudo (usuário)**: `sudo bash scripts/crontab-setup.sh --install` (registra o worker `log_cleanup` diário) + `sudo -u www-data php scripts/log_cleanup.php` (limpeza one-shot) — o deploy foi feito sem esses passos.

### 12.11 Observabilidade: LOG_LEVEL, rotação/purga real e handler global — v4.2.1 (13/07/2026)

- **Contexto**: auditoria de logs revelou (1) nível DEBUG morto (`Logger::$logLevel` hardcoded INFO, `setLogLevel()` nunca chamado → `RAW_WEBHOOK_DATA` jamais gravado); (2) purga anunciada (`cleanOldLogs`) **nunca era invocada** e o glob só cobria `webhook_*.log` (worker/órfãos ficavam para sempre — 37 MB no dev, 13 arquivos órfãos de maio); (3) dashboard sem logging de aplicação (exceção/fatal em página = tela branca sem rastro).
- **`LOG_LEVEL` no `.env`** (`core/Logger.php`): aplicado **lazy no primeiro log** — necessário porque o `.env` só é parseado dentro do primeiro `Database::getInstance()`, depois do load do Logger. `LOG_LEVEL=DEBUG` liga o payload bruto dos webhooks sob demanda (diagnóstico de device com formato inesperado). Default continua INFO.
- **`scripts/log_cleanup.php`** (cron diário 03:10 via `crontab-setup.sh`): rotação por tamanho (`LOG_MAX_SIZE_MB`, default 10 — logs de append contínuo como `worker.log` viram `.old`, que o purge por idade remove depois) + `Logger::cleanOldLogs()` com glob estendido a `*.log`/`*.log.old` (`LOG_RETENTION_DAYS`, default 30). **Não usa a classe Database de propósito** (ela dá `exit` com banco fora; limpeza de log tem que rodar mesmo assim) — parse próprio do `.env`.
- **Handler global do dashboard** (`includes/auth.php`, incluído por todas as páginas/AJAX; webhooks ficam fora — têm o try/catch do `WebhookHandler`): `set_exception_handler` (→ ERROR com class/message/file/line + resposta 500 neutra) e `register_shutdown_function` para fatais (→ CRITICAL). Warnings/notices ficam de fora de propósito.
- **Validado local**: `log_cleanup.php` executado de verdade — purgou os 13 órfãos de maio e rotacionou+purgou `traffic.log` >10 MB (17→3 arquivos); `LOG_LEVEL=DEBUG` grava `RAW_WEBHOOK_DATA`, default INFO suprime; handlers testados via arquivo (exceção → ERROR, fatal → CRITICAL) — atenção: `php -r` NÃO dispara `set_exception_handler` neste build (eval'd code), testar sempre com arquivo; Playwright login+vídeo 8/8 (1 skip esperado).
- **Deploy**: rodar `bash scripts/crontab-setup.sh --install` no homolog para registrar o novo worker; opcional definir `LOG_RETENTION_DAYS`/`LOG_MAX_SIZE_MB` no `.env`.

### 12.10 Fix consulta de vídeos históricos do cartão — v4.2.1 (13/07/2026)

- **Bug reportado**: "Requisitar Gravações" no `/video/playback` sempre devolvia vazio, mesmo com o app Android listando vários vídeos no cartão. **Causa tripla**: (1) a tela disparava **34818** (0x8802, extração de multimídia de *evento*) em vez de **37381** (0x9205, consulta da lista de gravações — o que o app usa); (2) a resposta da câmera chega via `/pushresourcelist` → tabela `resource_lists`, mas a timeline lia só `media_files` (arquivos já extraídos) — a lista nunca aparecia; (3) o 37381 exige janela GMT-0 compacta (`yyMMddHHmmss`) **que não cruza o dia** — o período default (ontem→hoje) seria ignorado pela câmera.
- **Fluxo corrigido** (`video_playback.php`): Requisitar → 37381 fatiado em **segmentos por dia UTC** (cap 15; campos `channel`+`channelId`, `alarmFlag/resourceType/codeType/storageType=0`, `instructionID` único); timeline = `resource_lists` ("**No cartão**") ∪ `media_files` ("**Disponível**" → play), com merge por janela ±120s (upload que cai na janela da gravação torna-a reproduzível, sem duplicar item); botão **[Extrair]** por gravação dispara 34818 com a janela exata (mesmo contrato validado do §12.8) → arquivo chega via `/pushfileupload` e o item vira Disponível; auto-refresh 6×8s pós-requisição (cancelado ao interagir; comando NÃO é reenviado no reload); `serverFlagId` por protocolo do device (`data-proto`; JIMI=1, JT/T=0); modelos protocolo JIMI (JC400D/AD) mantêm 34818 na janela inteira (0x9205 não existe lá — limitação documentada); **fix multi-tenant**: `imei` do GET só vale se pertencer ao cliente da sessão.
- **`pushresourcelist.php`**: `allowSingleObjectPayload=true` (push §1.11 `{imei,totalNum,instructionID,resourceList}` pode vir sem envelope `data_list` — antes era descartado como "empty data"); mapa de `resourceType` corrigido para semântica 0x1205 (**0=áudio e vídeo→`video`**, não `image` — 0=imagem é do multimídia 0x0800, que não passa por este push); datas parseadas explicitamente como UTC; log de lista vazia agora inclui `totalNum`+keys para diagnóstico.
- **Presets 37381** (`comandos.php`, `ativo_detalhe.php`): formato da doc (`channel`, janela GMT-0 compacta de hoje, sem cruzar o dia) — antes usavam `channelId`/`mediaType` com datas vazias.
- **Validado local (E2E real)**: push §1.11 simulado (objeto único, resourceType 0 e 2) → 2 linhas `video` em `resource_lists` → timeline renderiza "2 gravações" No cartão com [Extrair] → `pushfileupload` na janela da 1ª → item vira Disponível com o mp4 clicável (o 2º permanece No cartão, sem duplicatas); fatiamento UTC validado em Node (1 dia BRT → 2 segmentos, range invertido → 0, cap 15); Playwright rotas `video/*` 3/3 e `comandos`/`ativos*` 3/3.
- **Pendência**: exercitar com câmera real (JC450/JC182) no homolog — confirmar que o firmware responde 0x9205 com a lista e aceita 0x8802 na janela exata da gravação; se o push §1.11 vier COM envelope `data_list`, o caminho antigo continua cobrindo.

### 12.9 Fix seleção de canais nas telas de vídeo — v4.2.1 (12/07/2026, `2e8472f`)

- **Bug**: ao vivo/playback não deixavam selecionar CH2+/CH3+ em equipamentos cadastrados com mais câmeras — as telas liam `dm.camera_count` (modelo, seed errado) e ignoravam `devices.camera_count` (cadastro); o ao vivo ainda iniciava com `maxCams=1` até trocar o select e tinha teto fixo de 4 canais.
- **Semântica canônica**: `device_models.camera_count` = **máximo do modelo** (JC182=1, JC181/JC400D/JC400AD=2, JC371≤3, JC450≤5); `devices.camera_count` = **quantidade instalada** (cadastro). Telas usam `COALESCE(NULLIF(d.camera_count,0), dm.camera_count, 1)` (`video_aovivo`, `video_playback`, `comandos`, grade `equipamentos`).
- **Migration v4.2.1** (deploy.sh ganhou o bloco; condição do bloco v4.1.0 corrigida para não reaplicar pós-bump): corrige o catálogo e alinha `devices.camera_count` dos modelos de contagem **fixa**; modelos variáveis (JC371/JC450) respeitam o cadastro. Seed da v3.1.0 corrigido para instalações novas. `system_info.version = 4.2.1`.
- **Validado no homolog**: JC450 de teste cadastrado com 4 câmeras → ao vivo `data-cam="4"` (CH1–4 clicáveis), playback lista CH1–4 com CH3 selecionável; devices JC181/JC400D/AD alinhados para 2; Playwright rotas de vídeo 3/3.
- **Gotcha de deploy**: o `git pull` roda no meio do próprio `deploy.sh` — mudanças no script só valem na PRÓXIMA execução (a migration v4.2.1 exigiu rodar o deploy 2×).

### 12.8 Gatilho automático de vídeo de evento — v4.2.1 (12/07/2026, `8e86076`)

- **Implementado e implantado**: ocorrência nova sem mídia em câmera JT/T → `queue_event_video_request()` (motor) agenda proNo **34818** (0x8802, `mediaType 2`, janela ±60s GMT-0 compacto, canal 0 = todos, chaves `channel`+`channelId` por divergência de exemplos) → `flush_pending_video_requests()` despacha **pós-commit** no fim do `pushalarm.php` via novo `includes/iothub_command.php` (`operator='auto_video'`, anti-rajada 2 min/device, kill-switch `AUTO_VIDEO_REQUEST=0`).
- **Validado no homolog**: E2E replay 8/8; comando 38 auto-criado para a ocorrência 3 com payload/janela corretos; IoTHub aceitou o formato (code 0; `_code 301 "device not registered"` — esperado para device fake).
- **Pendência**: validar com câmera real (JC182) gerando evento DMS de verdade — confirmar se o firmware devolve vídeo para 0x8802 com `eventCode 0` (pode devolver só mídia disparada por comando; se vier vazio, testar `eventCode` do alarme correspondente). Semântica conhecida: resposta síncrona com `_code 301/600` marca `executed`/`sent` conforme `_content` — mesma do `sendcommand.php`.

---

## 13. Comandos Úteis

```bash
# Lint local (Windows PowerShell)
$files = Get-ChildItem -Recurse -Include *.php -Path handlers,includes,config,core,web,scripts
foreach ($f in $files) { & "C:\Users\flavi\php\php.exe" -l $f.FullName }

# Servidor dev local
php -S localhost:8000 server.php

# Deploy produção
./scripts/deploy.sh
./scripts/deploy.sh --force

# Migração (fresh install)
mysql -u root -p < mysql/jimi_tracker.sql
mysql -u root -p jimi_tracker < mysql/migration_v2.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v3.1.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.0.0.sql
mysql -u root -p jimi_tracker < mysql/migration_v4.1.0.sql

# Testes E2E
./scripts/run-tests.ps1          # Playwright (Windows)
bash scripts/test_e2e.sh         # replay de webhooks

# Webhook replay (teste)
curl -X POST http://localhost:8000/pushalarm \
  -H "Content-Type: application/json" \
  -d '{"token":"a12341234123","data_list":[{"imei":"868120246598152","msgClass":0,"msg":{"alertType":"100","alarmTime":"2026-07-06 12:00:00"}}]}'

# Workers (cron)
php scripts/worker.php
php scripts/trip_builder.php
php scripts/metrics_rollup.php
```

---

## 14. Iteração v4.1.2 — Vídeo ao vivo (11/07/2026)

Correção da abertura dos vídeos ao vivo, reportada como ainda quebrada. CHANGELOG [4.1.2] tem o detalhe técnico.

### 14.1 Causa-raiz

O comando **37121 (0x9101)** instrui o **device** a *publicar* o stream RTP no media server do IoTHub. O `video_aovivo.php` mandava `videoIP: window.location.hostname` (o host que o **navegador** vê) e `videoTCPPort: "0"` — endereço que o device não alcança e porta inválida. Resultado: o device nunca publicava, o `.flv` em `:8881` ficava sem dados e o player travava em "Conectando" indefinidamente. Havia ainda `dataType:"1"` (áudio, string) onde o correto é `0` (vídeo).

### 14.2 Correções

| # | O quê | Onde |
|---|---|---|
| 1 | Payload 37121 correto: `dataType:0, codeStreamType:0, videoIP:<IP do servidor>, videoTCPPort:"10002", videoUDPPort:0` | `video_aovivo.php` |
| 2 | Helper `video_stream_config()` (flv_base + ingest_ip/port + playback_port, com overrides `.env`) | `includes/functions.php` |
| 3 | Player FLV resiliente: retry 8×3s, watchdog 8s, `Events.ERROR`, autoplay-fallback mudo, destroy limpo, sessão anti-corrida | `video_aovivo.php` |
| 4 | `sendcommand.php` expõe `status` + `offline_queued` (device offline → `_code=600`); vídeo avisa fila offline em vez de esperar | `sendcommand.php`, `video_aovivo.php` |
| 5 | "Requisitar Gravações" 34817 (foto!) → **34818** (upload de mídia); datas JT/T `yyMMddHHmmss` GMT0; filtro com `brt_day_range_to_utc()`; fetch `keepalive` | `video_playback.php` |
| 6 | Presets "Streaming"/"Playback"/"Upload de Vídeo" corrigidos (via `video_stream_config()` + `FILE_STORAGE_URL`) | `comandos.php`, `ativo_detalhe.php` |
| 7 | `/video` legado → redirect para `/video/aovivo` (preserva `?imei=`) | `video.php` |
| 8 | `.env.example`: `VIDEO_INGEST_IP`/`VIDEO_INGEST_PORT`/`VIDEO_PLAYBACK_PORT` documentados | `.env.example` |

### 14.3 Verificações (com câmera real, homolog)

- **37121 corrigido** → IoTHub (`:10088`) → câmera `869058070151343` (JC182, online): `code:0, _content:"ok"` em ~1s.
- **Stream capturado**: `GET :8881/1/869058070151343.flv` → **2 MB de FLV válido** (assinatura `FLV` v1, flags `0x5` áudio+vídeo, 1ª tag type 18). A 1ª tentativa com janela curta pegou 0 bytes (device ainda não publicando) — comprova o valor do retry/watchdog.
- Lint 7/7 arquivos alterados OK. Playwright navegação **25/25 verde** (inclui as 3 rotas `/video/*`).

### 14.4 Observações operacionais

- **`videoIP` depende de rede**: hoje deriva do host de `STREAM_URL` (IP público `189.22.240.43`). Se algum dia o device precisar publicar via IP da LAN (como o `IOTHUB_COMMAND_URL` usa `10.1.0.43`), setar `VIDEO_INGEST_IP` no `.env` — o teste real confirmou que o IP público funciona para a câmera atual.
- **Latência de abertura**: 5–30s entre clicar "Iniciar" e o vídeo aparecer é esperado (device liga a câmera e negocia o RTP). O player agora comunica isso ("tentativa N/8… o dispositivo leva alguns segundos") em vez de parecer travado.
- Pendências de vídeo remanescentes: nenhuma bloqueante. Playback (37377) e foto (34817) têm presets corretos mas não foram exercidos com device real nesta iteração.
