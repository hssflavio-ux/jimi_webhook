# Histórico de STATUS.md

Entradas de sessão arquivadas por `.claude/skills/status-archive`. Mais recentes primeiro.

---

> ### 📍 v4.9.16 (14/08/2026) — Parâmetros vira área de administrador
>
> As três funções de parametrização estavam espalhadas — a leitura numa aba de
> `/ativos/{imei}`, o relatório em **Relatórios**, os perfis em **Cadastros** —
> e nenhuma referenciava a outra. Agora há um menu **Parâmetros**, logo abaixo
> de **Comandos**, e ele é **só de administrador**.
>
> Vizinho de Comandos de propósito: as duas telas mandam instrução para
> equipamento em operação, e é o mesmo perfil de gente que as usa.
>
> 🔴 **A trava é `require_admin()`, não `can()`.** `can()` devolve **true** para
> todo usuário SEM grupo de permissão (`get_user_permissions()` → `null` = "sem
> restrição"), que é o estado dos usuários deste banco hoje. Uma tela que
> escreve configuração em câmera em operação não pode depender de uma checagem
> permissiva por omissão.
>
> **Restringir o menu não bastaria**: `/relatorios/parametros` continuaria
> aberta por URL a qualquer um com acesso a relatórios, e `?tab=parametros` é
> digitável. Por isso a trava está nos **quatro** caminhos — hub, relatório,
> perfis e a aba do equipamento.
>
> Verificado com usuário `operator` real (criado e apagado no teste): **403**
> nas três rotas, aba caindo em Visão Geral com 0 células, item some do menu.
> Sanidade junto — `/comandos` respondeu 200 com a mesma sessão, provando que o
> 403 era a permissão e não sessão inválida.
>
> ⚠️ **A primeira rodada do teste foi inválida e quase passou por boa**: a
> coluna era `password_hash` (não `password`), `UID` é variável reservada do
> bash, a sessão falhou por FK e as rotas responderam **302** — que é "não
> logado", não "sem permissão". Teste de restrição precisa de um caso positivo
> junto: sem o `/comandos → 200`, um 403 universal por sessão quebrada seria
> lido como sucesso.
>
> #### 🔴 O `.env` ilegível voltou — e a causa raiz não era a que parecia
>
> O defeito do `sed -i` (`/ping` → `"version":"desconhecida"`, webhooks em 500,
> nada no log) **repetiu em 14/08**, um dia depois de "mitigado". A mitigação da
> véspera era `chmod g+s` no diretório da aplicação — e o próprio `deploy.sh`,
> na FASE 3c, fazia `chmod 755 "$APP_DIR"`, **apagando o setgid**. Mitigação que
> o próprio deploy desarma não é mitigação.
>
> Ao corrigir para `chmod 2755`, o bit **continuou não grudando**. A causa real:
> `administrador` **não pertencia ao grupo `www-data`**, e o POSIX manda o
> kernel **descartar o setgid em silêncio** quando quem chama `chmod` está fora
> do grupo do arquivo e não tem `CAP_FSETID`. Pelo mesmo motivo, o `chgrp
> www-data .env` que eu havia acrescentado falhava — mascarado por um `|| true`.
> Medido lado a lado: `chmod 2755` como `administrador` → **755**; como root →
> **2755**.
>
> **Correção em três camadas**, porque uma só já provou ser frágil:
> 1. `usermod -aG www-data administrador` (servidor) — sem isto, as outras duas
>    são no-ops silenciosos;
> 2. `deploy.sh` restaura `chgrp`+`chmod` do `.env` logo após o `sed`;
> 3. `deploy.sh` usa `chmod 2755` e **avisa na FASE 1** se o usuário do deploy
>    não estiver em `www-data`.
>
> Verificado com o ciclo completo: `.env` rebaixado → `deploy.sh` → `.env`
> segue `administrador:www-data 640`, diretório em `2755`, `/ping` em 4.9.16.

---

> ### 📍 ESTADO EM 13/08/2026 — PRODUÇÃO NO AR (`186.248.143.197`)
>
> O sistema saiu do homolog. **Produção nova, com câmeras reais reportando**, no
> commit `46d5dc6` e `system_info` em **4.9.14**.
>
> | | Endereço | Papel |
> |---|---|---|
> | **Produção** | `186.248.143.197` (LAN `10.1.1.8/24`, host `bycamera`) | operação |
> | Homolog | `189.22.240.43` | testes |
>
> Servidor chegou **cru**: Ubuntu 24.04 com Apache e MySQL 8.4.11, **sem PHP**.
>
> | Camada | Estado final |
> |---|---|
> | PHP | 8.3-FPM + `pdo_mysql, mbstring, zip, curl, xml, gd, bcmath, intl` |
> | Apache | `rewrite`, `headers`, `proxy_fcgi`; vhost `bycamera.conf`, `000-default` desabilitado |
> | App | `/var/www/jimi_webhook`, 121 arquivos com `php -l` limpo |
> | Banco | dump do homolog — 60 tabelas, já em 4.9.14, **nenhuma migração roda** |
> | Cron | 9 workers |
> | IoT Hub | **no mesmo servidor**, 16 containers em `/iothub` |
> | FTP | `vsftpd`, controle **21222**, passivas **31100–31200** |
>
> #### 🔴 `sed -i` no `.env` derruba o site em silêncio
>
> O `.env` é `640 administrador:www-data`. O `sed -i` **recria** o arquivo, o
> grupo vira `administrador`, o `www-data` perde a leitura e a aplicação passa a
> rodar com os **defaults do código** — inclusive `DB_PASS=1029384756`, a senha
> do homolog, que em produção não abre.
>
> **A assinatura do defeito**: `/ping` responde `"version":"desconhecida"` e os
> webhooks devolvem **500**. Nada no log da aplicação, porque ela nem chega a
> conectar.
>
> Aconteceu em 13/08 ao gravar a senha do FTP: **12 requisições perdidas em ~3
> min** (últimas 500 às 17:10:09, corrigido às 17:10:52). O grave é que **o
> próprio `scripts/deploy.sh` roda esse `sed`** quando `SYSTEM_VERSION` muda —
> ou seja, o próximo deploy repetiria tudo sozinho.
>
> **Mitigado** com `chmod g+s` em `/var/www/jimi_webhook`: arquivo novo (o
> temporário do `sed` inclusive) herda o grupo `www-data`. Verificado
> simulando o `sed` do deploy. **Se o setgid se perder num `chmod -R` futuro, o
> defeito volta.**
>
> #### A senha SMTP do dump era irrecuperável — e isso é regra, não acaso
>
> `smtp_settings.password_enc` veio cifrado com a `APP_KEY` **do homolog**, que
> não viaja no dump. Provado por teste de decifragem: nem o `WEBHOOK_TOKEN` nem
> o fallback abriram. Produção recebeu `APP_KEY` nova e o valor foi recadastrado
> em `/config-smtp` (`last_test_ok = 1`, fonte da chave = `app_key`).
>
> **Todo dump levado para outro ambiente carrega esse buraco**: o que está
> cifrado em repouso não atravessa junto. Contar as senhas de terceiros como
> "restauradas pelo backup" é o erro.
>
> #### Decisões e pendências
>
> - **TLS no ar** (13/08) — `https://bycamera.ia.br` e `www`, Let's Encrypt até
>   **11/11/2026**, redirect 80→443. Ver a subseção abaixo: a renovação **não é
>   automática** neste servidor.
> - **`WEBHOOK_TOKEN` segue `a12341234123`**, de propósito: tem de casar com o
>   que está configurado no IoT Hub, senão todo payload é rejeitado em silêncio.
> - **MySQL/Webmin/Cockpit alcançáveis de fora** — verificado e reportado;
>   **decisão do cliente**, liberação restrita ao IP deles. Não mexer.
> - **`/midia` procura o arquivo só na RAIZ de `VIDEO_MEDIA_DIR`**
>   (`$baseDir . '/' . $arquivo`), e o Hub criou um subdiretório `jtt/`. O FTP da
>   câmera deposita na raiz, então o fluxo do `37382` está certo — mas anexo que
>   caia em `jtt/` não toca na tela.
> - Usuário de teste `e2e@teste.local` veio no dump e continua no banco.
>
> #### 🔴 A renovação do TLS depende de um humano abrir a porta 80
>
> A 80 fica **fechada** no dia a dia por decisão de infraestrutura, e o desafio
> HTTP-01 precisa dela. Logo o certificado **não se renova sozinho**.
>
> `scripts/tls_renew_watch.php` (crontab do **root**, de hora em hora) cobre
> isso: dentro da janela de 30 dias ele tenta renovar a cada hora, alerta por
> e-mail pedindo a porta e, quando ela abre, renova e avisa que já pode fechar.
> O timer padrão do `certbot` fica **desligado** — ele falharia em silêncio, que
> é o modo de falha a evitar.
>
> ⚠️ **A liberação tem de aceitar `0.0.0.0/0`.** A validação é multi-perspectiva
> desde 2020: o desafio é buscado de datacenters em vários continentes e só passa
> com quórum. Na primeira tentativa a porta estava aberta **só para as redes do
> cliente** e a emissão falhou com `Timeout during connect` — enquanto o teste
> a partir do escritório respondia 200. **A assimetria "do escritório funciona,
> a emissão falha" é a assinatura desse erro.** A 443 pode seguir restrita; isso
> não afeta a validação.
>
> Antes de cada tentativa vale confirmar de um ponto externo neutro
> (`curl "https://api.hackertarget.com/httpheaders/?q=http://bycamera.ia.br"`):
> a LE permite só **5 validações falhas por hora** por hostname.
>
> O ensaio `certbot renew --dry-run` passou com a porta aberta — o caminho de
> renovação está provado, não suposto.
>
> #### O vhost fecha o que o `.htaccess` não alcança
>
> O `.htaccess` da raiz só reescreve o que **não** é arquivo real (`!-f`), então
> todo arquivo existente é servido estático — e a config padrão do Ubuntu só nega
> `.ht*`. Sem as negações do vhost, `/.env`, `/logs/*.log` e `/mysql/*.sql` seriam
> públicos. O pior era `/scripts/`: aberto, qualquer um dispararia `worker.php`
> pela web. Conferido: todos em **403**, assets e `manifest.json` em 200.
>
> #### Verificação
>
> - Webhook ponta a ponta: token errado → **401**; token certo → `{"code":0}` e a
>   posição gravou com todos os campos mapeados (linha de teste removida depois).
> - `/setup` bloqueado: POST tentando criar admin não criou nada.
> - FTP provado **de máquina externa** contra o IP público (login, listagem e
>   upload em modo passivo) — sonda do localhost não valeria, é a câmera que
>   conecta de fora.
> - Fluxo real: 3 câmeras cadastradas reportando, 20 alarmes em 15 min,
>   `10.1.1.8:10088` em HTTP 200 e `param_sync` sem erro.

---

> ### 📍 ESTADO EM 12/08/2026 — v4.9.10 a v4.9.14 publicadas e verificadas
>
> | | git HEAD | `/ping` | `system_info` |
> |---|---|---|---|
> | Local | `449757c` | — | — |
> | `origin/main` | `449757c` | — | — |
> | **Homolog** (`189.22.240.43`) | **`449757c`** | **4.9.14** | **4.9.14** |
>
> Árvore limpa, os três em paridade. **Cinco migrações** aplicadas (`v4.9.10`,
> `v4.9.11`, `v4.9.12`, `v4.9.14` — a `v4.9.13` é só código).
>
> #### O que entrou nesta sessão
>
> | Versão | Entrega | Migração |
> |---|---|---|
> | **4.9.10** | `Código 1047 (JTT)` → **Capotamento**; `146` → Curva Brusca; 3 telas que mostravam código | sim |
> | **4.9.11** | 🔴 resposta de comando truncada em 250; 🔴 `/config` morta + fora dos mapas de permissão | sim |
> | **4.9.12** | **F1**: catálogo de 49 parâmetros, 3 tabelas, parser, aba Parâmetros | sim |
> | **4.9.13** | **F2**: worker de leitura automática + relatório Parâmetros da Frota | não |
> | **4.9.14** | **F3**: escrita `33027` com travas, perfis por modelo, diff-only | sim |
>
> **`PROJETO_PARAMETROS.md`** nasceu e foi concluído nesta sessão (F1+F2+F3). É
> o blueprint da parametrização remota das câmeras JT/T, e a **§2 dele vale mais
> que a doc oficial**: as respostas foram medidas em equipamento real.
>
> #### O fio que liga tudo: a doc mente, o equipamento não
>
> Três divergências entre a doc oficial e o que a câmera manda, cada uma capaz
> de quebrar um parser **em silêncio**:
>
> 1. o campo de contagem chama **`paramCount`**; a doc diz `totalNum`, que
>    nenhum device mandou — código pela doc procuraria campo inexistente em 100%
>    das respostas;
> 2. os parâmetros de vídeo vêm num bloco **`channel_N`**, não na chave `119` —
>    parser fiel à doc perderia a configuração de vídeo inteira;
> 3. `paramCount` **não** é o número de chaves de topo (JC371 declara 87 e
>    entrega 46), então não serve para validar "recebi tudo?".
>
> O mesmo padrão apareceu no alarme `1047`: a doc não o publica, mas o
> fornecedor informou que é capotamento, e o bit 28 do bitmask JT/T corrobora.
> **A regra do repo é não batizar por palpite — não "só o que a doc publica".**
>
> #### Números depois de tudo
>
> | | antes | depois |
> |---|---|---|
> | Códigos de alarme exibidos como número | 2 (1047, 146) | **0** |
> | Protocolos que chamam capotamento pelo mesmo nome | 0 de 2 | **2 de 2** |
> | Payload de callback aproveitado | 250 bytes | **até 65.000** |
> | Câmeras com configuração no banco | 0 | **3** (JC371 49 · JC182 47 · JC181 6) |
>
> #### Verificação — em câmera real, não em fixture
>
> Esta sessão desbloqueou um método que o repo tratava como impossível: **sonda
> contra equipamento real** via `ssh` + `curl` no IoT Hub (`10.1.0.43:10088`).
> O `M.2.5` ("nenhum comando disparado para veículo real") vale para o despacho
> pelo dashboard, não para sondar comportamento.
>
> - `33028`/`33030` reais em 3 modelos (JC371, JC182, JC181) — a base da §2 do
>   blueprint.
> - Worker rodado na frota: 2 lidos na hora, 3 enfileirados com backoff.
> - 🔴 **Caminho do callback provado em produção**: o JC181 saiu como "fila
>   offline" e o `/pushinstructresponse` completou sozinho — 94 bytes,
>   `JSON_VALID = 1`.
> - Travas de escrita exercitadas uma a uma (400 / 409 / 409 / 400) e o caminho
>   feliz (`48`: 45 → 46 → 45) conferido **lendo da própria câmera**.
> - Permissão testada com **usuário restrito de verdade**: 403 no operador, 200
>   no admin.
> - `device_params.test.php` — **64 casos**, 0 falhas. `php -l` limpo.
> - Ambiente de teste removido: 0 usuários, 0 sessões órfãs, 0 linhas sintéticas.
>
> #### 🔴 INCIDENTE, com recuperação completa
>
> Testando as travas da F3, **a de parâmetro de rede não disparou e a escrita
> foi para uma câmera real**: `19` (Servidor Principal) recebeu `1.2.3.4`.
>
> **Causa**: `param_catalog()` listava colunas uma a uma e a `is_network` —
> criada pela migração da própria v4.9.14 — não entrou na lista. A guarda virou
> decoração, sem erro nenhum.
>
> **Recuperado em ~1 min**, e a recuperação validou o desenho:
> `device_param_writes` tinha `from_value = 189.22.240.43` gravado **antes** do
> despacho. Conferido lendo da câmera: `19 = 189.22.240.43`, `24 = 21122`,
> `41 = 60`, `48 = 45` — configuração integralmente restaurada.
>
> **Duas regras saíram daqui**: catálogo lido inteiro se lê com `SELECT *` (lista
> explícita ignora coluna nova em silêncio, e se a coluna é uma guarda, a guarda
> deixa de existir); e trava de segurança precisa de teste que a **exercite**.
>
> #### Defeitos que só o teste ponta a ponta achou
>
> Nenhum destes apareceria em revisão de código:
>
> 1. `33028` recusado com HTTP 400 três linhas antes da normalização que existe
>    para montá-lo — seu `cmdContent` é vazio por especificação.
> 2. `33030` marcava o device como sincronizado com 3 de 46 parâmetros.
> 3. A migração se derrubava: `LIKE 'jtt\_%'` perde a barra dentro de string do
>    MySQL, `_` vira coringa, e o `CAST('uct' AS UNSIGNED)` abortava tudo.
> 4. Conferência com falso positivo: `name_pt LIKE 'Parâmetro %'` acusava o `93`
>    (*Parâmetro de Colisão*), que é documentado.
> 5. `/config` devolvia **301**, não 403 — colisão com o diretório `config/`.
> 6. `is_int($k)` não distingue lista de mapa (o PHP converte `'85'` para int).
>
> #### 🔴 Achados registrados e NÃO corrigidos
>
> - **`Colisão do Veículo` não dispara notificação.** `1046` (JT/T) e `147`
>   (JIMI) estão em `veiculo`, que não tem regra; `Airbag Acionado / Colisão`
>   (JIMI `30`) está em `acidente` e dispara. Confirmado rodando a consulta do
>   motor. Movê-la aumenta o volume notificado de um alarme frequente —
>   **decisão de produto, aguardando o dono**.
> - **`/config-dispositivos` fica fora da sidebar de propósito**: é console cru
>   de JSON. A aba Parâmetros a supera; linkar a rústica seria expor trabalho
>   pela metade.
> - ⚠️ **Grupo "Operador Padrão" perdeu `/config-dispositivos`** quando a v4.9.11
>   subiu — é o objetivo da correção, mas é mudança visível. O admin concede na
>   tela de Grupos, onde a entrada nova agora aparece.
>
> #### O que ficou de fora, de propósito
>
> **Aplicar perfil em lote com um clique.** Hoje a escrita é equipamento a
> equipamento pela aba Parâmetros, e a tela de perfil só **simula** o impacto.
> Depois do incidente acima, ampliar o alcance de uma escrita — de uma câmera
> para uma frota — pede sessão própria, com o dono olhando.
>
> #### Pendências herdadas (seguem abertas)
>
> - **`devices.last_communication` incompleta** — só `pushalarm` e `pushlbs` a escrevem.
> - **Cercas**: coluna Mapa existe na tela e não no PDF/XLS.
> - **`tests/comandos.spec.js` escrito e não executado** (v4.9.7).
> - **Suíte Playwright bloqueada** — o MySQL de desenvolvimento local não tem data dir.
> - **`serverFlagId` não é chave de correlação** (é seletor de gateway); corrigir
>   mexe no despacho para veículo real (M.2.5).

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

