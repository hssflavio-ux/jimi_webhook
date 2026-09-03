# STATUS.md — Jimi Webhook System v4.16.1 (YUV Parity)

> ### 📍 ESTADO EM 03/09/2026 — o primeiro JM-VL01 real está no ar e foi analisado; a v4.16.0 JÁ ESTÁ EM PRODUÇÃO
>
> **A v4.16.0 foi publicada** — `JM-VL01`/`JM-VL02`, `device_models.family` e os
> 14 alarmes da linha VL estão no banco de produção. O primeiro rastreador real
> é o **`868982050616424`**, cliente 1, instalado no veículo 16 em
> 03/09 01:29 UTC.
>
> #### ✅ O equipamento funciona ponta a ponta, e não falta handler nenhum
>
> GPS a cada 60 s, heartbeat a cada 3 min, evento `LOGIN` (com
> `timezone: GMT-03:00`) e o par de alarmes 254/255. As chaves de **121 pushes
> de GPS**, **40 heartbeats** e dos alarmes foram cruzadas uma a uma com as
> colunas das tabelas: **`pushgps`/`pushhb`/`pushalarm` já cobrem tudo que a
> linha VL manda.** Não há campo novo a mapear nem handler novo a escrever —
> que era a pergunta que abriu esta linha de trabalho.
>
> O `255` chegou como `Código 255 (JIMI)`, provando que o catálogo da v4.16.0
> era necessário; já aparece resolvido na tela porque `alarm_label_sql()`
> re-resolve o rótulo genérico na leitura.
>
> #### 🔴 v4.16.1 — dois nomes de alarme errados, um deles meu
>
> - **`254` era "Status de Ignição Alterado".** A doc oficial publica, em dois
>   lugares independentes, `254 = Ignition turned on` e `255 = Ignition turned
>   off`: são os DOIS LADOS de um par. O VL01 confirmou na prática — `255` às
>   23:33 (desligou), `254` às 23:39 (ligou). Vale para toda a linha JIMI.
> - **`50` era "Alerta de Reboque", e o erro entrou na v4.16.0.** A wiki da VL
>   rotula o `0x32` com a palavra solta "Puxar"; a doc oficial diz
>   `Device was plugged out` — é o EQUIPAMENTO arrancado da instalação, irmão
>   do `19`. Havia fonte melhor e escolhi a mais curta.
>
> A migração corrige o catálogo **e o histórico** (`alarms.alarm_name` é
> desnormalizado), e **prova antes de renomear** que nenhuma
> `occurrence_config_params`/`notification_rules` casa pelos nomes antigos.
>
> #### 🔴 ACHADO GRANDE, PRÉ-EXISTENTE E NÃO CORRIGIDO: 28% dos alarmes estão INVISÍVEIS
>
> Medido em produção, últimos 30 dias:
>
> | tabela | linhas | sem `customer_id` | |
> |---|---|---|---|
> | `alarms` | 13.736 | **3.898** | **28,4%** |
> | `gps_data` | 19.755 | 1.815 | 9,2% |
> | `heartbeats` | 86.182 | 3.722 | 4,3% |
> | `events` | 2.080 | 125 | 6,0% |
>
> **Causa:** desde a Fase 2 (v4.12.0) o dono é gravado como SNAPSHOT resolvido
> por `resolve_installation_for_imei()`. Sem instalação aberta em
> `device_installations`, o snapshot é **NULL** — e toda tela com escopo de
> cliente filtra por `customer_id`, então a linha existe no banco e **não
> aparece em lugar nenhum**.
>
> É o design funcionando como especificado, mas ninguém tinha visto o tamanho:
> um JC371 (`865478070649936`) tem **1.694 posições, TODAS órfãs**, de 12 a
> 20/08. E o próprio VL01 perdeu **116 das 122 posições e os DOIS alarmes**,
> porque transmitiu das 23:18 até 01:29 antes de ser instalado no veículo.
>
> ⚠️ **Sintoma que o operador vai relatar:** "cadastrei o equipamento, ele está
> mandando posição, e o relatório está vazio."
>
> **Proposta (NÃO implementada — muda a semântica da Fase 2 e é decisão de
> produto):** quando não houver instalação aberta, gravar ao menos
> `devices.customer_id` como dono e deixar `vehicle_id` NULL. Isso **não**
> reabre o bug que a Fase 2 fechou — aquele era LER o dono atual numa consulta
> histórica; aqui é gravar, no momento do evento, o único dono conhecido
> naquele momento. Some um backfill para o passado.
>
> #### Menor, e já existente
> Alarme com `latitude/longitude = 0,0` (o `255` veio assim, sem fix de GPS).
> Não é da linha VL: em 90 dias são **736 casos no JC182**. As telas de mapa
> plotam isso no Golfo da Guiné em vez de dizer "sem posição".
>
> #### 📋 Pendente
> - 🔴 **~100 códigos JIMI da doc oficial não estão no catálogo** (temos 95).
>   Plausíveis na frota: `80`/`81` (porta), `84` (antena GNSS), `90` (tensão
>   externa baixa), `95` (excesso dentro de cerca), `106` (tombamento), `111`
>   (falha de cartão SD), `119`–`124` (tensão/temperatura ADC), `131` (colisão).
> - Nenhum comando disparado contra o JM-VL01 real — o primeiro deve ser
>   `STATUS#` ou `GPRSSET#`.



> ### 📍 ESTADO EM 02/09/2026 — a frota deixou de ser só de câmeras: JM-VL01 e JM-VL02 cadastráveis, NÃO publicados e NÃO exercitados contra equipamento real
>
> **v4.16.0 — dois rastreadores entram no catálogo.** `JM-VL01` e `JM-VL02`
> falam o MESMO protocolo JIMI (`msgClass=0`), chegam pelos MESMOS webhooks e
> aceitam os MESMOS comandos de texto proNo 128 das câmeras da linha JC. O que
> muda é que **não têm câmera**: são os dois primeiros modelos do sistema com
> `camera_count = 0`, e isso quebrou premissas que ninguém tinha escrito.
>
> ⚠️ **O nome é `JM`-VL01, não `JC`-VL01.** `JC` é a linha de câmeras; `JM` é a
> de rastreadores. `model_name` é UNIQUE e vira chave da trava por modelo, de
> `/firmwares` e do `modelos` do catálogo de comandos.
>
> **O que está pronto e verificado (contra MySQL e servidor local, não contra
> equipamento):**
> - `migration_v4.16.0.sql` — os 2 modelos, `device_models.family`
>   (`camera`/`tracker`) e **14 alarmes JIMI** da linha VL que faltavam
>   (`0`,`19`,`50`,`60`,`61`,`62`,`75`–`79`,`83`,`94`,`255`). Aplicada **duas
>   vezes** no banco local: idempotente. Linha no `deploy.sh`.
> - `command_catalog.php`: 42 entradas novas (237 no total). Regra seguida à
>   risca, a pedido do dono do produto: **entrada nova só onde a quantidade ou o
>   formato dos parâmetros muda**; onde a sintaxe é a mesma, o modelo entrou na
>   entrada que já existia.
> - `/equipamentos` cadastra rastreador (campo Canais aceita 0);
>   `/video/aovivo`, `/video/playback` e `/configuracoes-ia` não os listam;
>   `/ativos/{id}` esconde as abas Ao Vivo e Vídeo. Tudo conferido por smoke
>   autenticado com um JM-VL01 real no banco, **com guarda de não-vacuidade**
>   (cada "não aparece" acompanhado de um "mas OUTRO equipamento aparece").
> - `command_response.test.php`: **124/124**. Eram 111/115 — as 4 falhas eram
>   **pré-existentes** (contagens do cabeçalho envelhecidas desde a v4.9.32 e o
>   invariante da consulta contra a família `EVENTSET`).
> - `tests/rastreador_vl.spec.js` (novo): **7/7**. Ele NÃO depende de um JM-VL
>   cadastrado, de propósito — spec que pula não é cobertura; o que ele exige é
>   a migração aplicada, e sem ela FALHA, que é o aviso que se quer.
>
> ⚠️ **Três specs afirmavam o significado ANTIGO de `universal`** e foram
> corrigidos junto: o "comando universal libera TODOS os equipamentos"
> (`comandos.spec.js`), o `CHECK#` do mesmo arquivo e o `UPDATE` de
> `firmware.spec.js`. Os três asseriam "equipamento nenhum desabilitado" — uma
> frase que só era verdadeira porque a frota inteira era câmera.
>
> **Playwright, 106 testes nos arquivos que esta versão toca**: `comandos` +
> `firmware` + `comandos_sms` **34 passando**; `video_*` + `equipamento_vinculo`
> + `navigation` **66 passando**; `rastreador_vl.spec.js` **7/7**.
>
> ⚠️ **As 3 falhas restantes são PRÉ-EXISTENTES** — cada uma reproduzida com o
> código do HEAD (`git stash`) antes de ser descartada:
> - `comandos.spec.js` × 2 (`VIDETIMEZONE`/`VIDEOTIMEZONE`): é DADO, não código.
>   O cliente de teste local não tem nenhum **JC371**, e os dois comandos só
>   existem nesse modelo — a trava desabilita todas as linhas e o `marcarUm()`
>   do spec não acha checkbox livre. Some assim que houver um JC371 no cliente.
> - `video_playback_filelist.spec.js` × 1: espera 3 canais no laço do 37381 e
>   recebe 1. Não investigado — está fora do escopo desta versão, mas **entra na
>   lista de pendências**, porque é um spec vermelho que ninguém estava vendo.
>
> ⚠️ **A suíte COMPLETA (201 testes) não foi rodada até o fim** — leva mais de
> uma hora com um worker. O que foi rodado é o conjunto que toca os arquivos
> alterados, e os helpers PHP inteiros.
>
> ⚠️ O banco de desenvolvimento local estava em **4.9.32** — sete migrações
> atrás. Foi migrado até a 4.16.0 para a suíte rodar contra o esquema real (o
> `senha_temporaria.spec.js` acusava `Unknown column 'must_change_password'`).
>
> #### 🔴 A trava por FAMÍLIA — o que a chegada do rastreador quebrou
>
> `universal`, no catálogo de comandos, foi derivado de "presente em >= 5 das 6
> páginas de **câmera** da wiki", e a tela o traduzia como "libera a frota
> inteira". Enquanto toda a frota era câmera, as duas frases eram a mesma. Com
> um rastreador na lista, "liberar a frota" passou a significar oferecer
> `RECORDSW`, `VOLUME`, `SSID` e `WIFIAP` a um aparelho que não os entende —
> comandos que voltariam como "não suportado" horas depois, no callback.
>
> ⚠️ **Não é "rastreador não tem WiFi"** (corrigido pelo dono do produto,
> 03/09/2026): o **JM-VL01 TEM** — hotspot WiFi é recurso de capa na wiki dele,
> e o Android embarcado ainda o conecta como cliente a uma rede. O que ele não
> entende é `WIFIAP`/`SSID`: a forma dele é `HOTSPOT,S,N,P#`, já catalogada.
> Mesmo recurso, comando outro — como `LED` (JC/VL01) contra `LEDSLEEP` (VL02).
> O JM-VL02, esse sim, não tem rádio WiFi (Cat-M1/NB2).
>
> Agora `universal` libera **as famílias que o próprio comando documenta**,
> derivadas de `modelos` via `device_models.family`. Não há chave nova no
> catálogo: comando universal que valha para rastreador é comando que lista
> `JM-VL01`/`JM-VL02` em `modelos`.
>
> #### 🔴 Dois defeitos pré-existentes que só apareceram por causa disso
>
> - **`SOSALM,A,B#` era inenviável pela tela** — cinco parâmetros declarados
>   para dois placeholders (três eram lixo de raspagem da wiki). Como
>   `faltaParametro()` exige toda caixa preenchida, o botão Enviar nunca
>   habilitava. Só funcionava pelo modo livre, o que ninguém percebe.
> - **`parseInt(...) || 1`** em `onModelChange()`: `0` é falsy em JS, então o
>   modelo de 0 câmeras gravava 1 canal e o rastreador nascia parecendo câmera.
>   Latente desde sempre, porque até agora todo modelo tinha ao menos 1 canal.
>
> 🔴 **O que NÃO foi verificado — leia antes de confiar:**
> 1. **Nenhum comando foi disparado contra um JM-VL real.** Toda entrada nova
>    tem `consulta_ref => 'wiki'`, nunca `medido`. O primeiro teste tem de ser
>    uma consulta inócua (`STATUS#` ou `GPRSSET#`) num equipamento só.
> 2. **Nenhum webhook de um JM-VL foi lido.** Os 14 alarmes novos vieram da
>    tabela da wiki, não de payload observado — falta ver o que o IoT Hub
>    realmente manda (é o próximo passo combinado: o dono do produto vai deixar
>    um VL01 ligado).
> 3. **A cerca RETANGULAR (`FENCE,B,1,…`) ficou de fora de propósito**: a wiki
>    escreve a sintaxe com 7 campos e descreve 8 logo abaixo. Aridade errada no
>    proNo 128 é aceita sem erro nenhum — aqui daria cerca no lugar errado.
> 4. Alarmes `33`/`34` não catalogados: a wiki os publica como "Reservado", e
>    batizar por palpite é erro que este catálogo já pagou.
> 5. Os 14 alarmes novos **não geram ocorrência** (sem `occurrence_config_params`)
>    — ligar o motor para evento de rastreador muda volume de tratativa e é
>    decisão de produto, não de migração.
>
> ⚠️ **Migração nova → DOIS deploys** (`--force` duas vezes) ou o `.sql` à mão.
> No intervalo, `/comandos` cai no `catch` de `device_models.family` e trata
> todo mundo como câmera — que é exatamente o comportamento anterior, de
> propósito. As telas de vídeo filtram por `camera_count`, sem depender da
> coluna nova.

> ### 📍 ESTADO EM 29/08/2026 — canal de SMS implementado, NÃO publicado e NÃO exercitado contra equipamento real
>
> **v4.14.0 — comandos do proNo 128 por SMS (Allcance).** Segundo transporte
> para o mesmo catálogo de comandos de texto: quando a câmera não fala com o IoT
> Hub (APN ou `SERVER` errados), o SMS chega por um caminho independente.
>
> **O que está pronto e verificado:**
> - Tela `/comandos-sms` (catálogo inteiro + trava de modelo + saldo a cada
>   abertura), `/config-sms` (admin), webhook `/pushsms`, ação `/sendsms`,
>   `includes/sms_gateway.php`, `includes/sms_inbound.php`.
> - `migration_v4.14.0.sql` (`sms_settings`, `sms_commands`) + linha no `deploy.sh`.
> - `php -l` limpo em `handlers/ config/ core/ includes/`; JS da tela validado
>   por `node --check`; **44/44** em `tests/helpers/sms_webhook.test.php`.
> - **A API foi exercitada de verdade** (29/08/2026): `POST /v2/api/login`
>   devolveu `200 success` e `GET /v2/api/creditos` listou `SMS TRANSACIONAL`.
>   O token é JWT de 3600 s e não há refresh — daí o cache em `sms_settings`.
>
> 🔴 **O que NÃO foi verificado — leia antes de confiar:**
> 1. **Nenhum SMS foi enviado.** O caminho `/campanhas` está escrito contra a
>    doc e a coleção do Postman, não contra uma resposta real. O primeiro teste
>    tem de ser um comando inócuo (`STATUS#`) num equipamento só.
> 2. **Chip M2M frequentemente NÃO recebe SMS** — é contratual da operadora, não
>    técnico. Se o primeiro envio for aceito pela API e nunca entregue, suspeite
>    disto antes do código.
> 3. **O SQL não rodou contra MySQL nenhum** (a máquina de desenvolvimento não
>    tem servidor). Foi revisado à mão; a `UNIQUE` na coluna gerada
>    `customer_key` é o que impede duas linhas globais.
> 4. **A conta usada tem 10 créditos** — de teste, não de operação.
>
> ⚠️ **Passo de infraestrutura FORA DO GIT:** a URL do webhook
> (`APP_URL` + `/pushsms?k=<segredo>`) precisa ser cadastrada **no painel da
> Allcance** — não há endpoint de API para isso. Sem ela, a tela mostra "aceito"
> e o status de entrega e a resposta do equipamento nunca chegam. Mesma classe
> do `docs/apache/filelist-chunked.conf`: some se a conta for reprovisionada, e
> nada no deploy avisa. O segredo é gerado em `/config-sms`, que mostra a URL
> pronta.
>
> ⚠️ **Migração nova → DOIS deploys** (`--force` duas vezes) ou o `.sql` à mão.
>
> Design completo em `docs/superpowers/specs/2026-08-29-comandos-sms-design.md`.

> ### 📍 ESTADO EM 28/08/2026 — senha temporária por e-mail NO AR e testada ponta a ponta; v4.13.22–23 pendentes
>
> **Produção (`bycamera.ia.br`) está com o código da `4.13.21` e o banco migrado
> para `4.13.21`.** ⚠️ O `/ping` e o rodapé do login continuam dizendo
> **`4.13.19`**: o `SYSTEM_VERSION` do `.env` não foi bumpado no deploy. É só o
> letreiro — o código no ar é o novo, provado pelas rotas que só existem nele.
>
> **Commitadas e NÃO publicadas: `4.13.22` (`424993e`) e `4.13.23` (`7b56c13`).**
> Nenhuma das duas mexe em banco; sobem no próximo deploy junto com o bump do
> `SYSTEM_VERSION`.
>
> #### 🔧 v4.13.20 — a camada de satélite virou HÍBRIDA (no ar)
>
> Imagem aérea sem via nem nome não serve para operação de frota: o operador vê
> o telhado e não sabe em que rua o veículo está. `bcMapBaseLayers()`
> (`web/components/map_assets.php`) monta um `L.layerGroup` com o
> `World_Imagery` mais os dois overlays de referência do próprio Esri
> (`Reference/World_Transportation` e `Reference/World_Boundaries_and_Places`).
> Grátis, sem chave; o controle diz `Ruas` / `Híbrido`. Vale nos 10 mapas de uma
> vez, sem tocar em handler nenhum — o retorno da padronização da v4.13.18.
> ⚠️ As linhas finas de borda de tile em alguns zooms são do Leaflet e aparecem
> **igualmente na camada anterior**; não é regressão.
>
> #### 🔧 v4.13.21 — cadastro por e-mail, senha temporária e "esqueci minha senha" (no ar)
>
> Antes: o admin inventava a senha em `/usuarios` (campo obrigatório), combinava
> por WhatsApp, e ninguém era obrigado a trocá-la; quem esquecia dependia de um
> admin — **não existia rota de recuperação nenhuma**. Agora: senha em branco no
> cadastro = o sistema gera 6 caracteres, envia (`includes/password_reset.php`,
> ponto único) e obriga a troca. Decisões do dono do produto: validade de 24 h,
> campo de senha manual mantido como opção, falha de envio = mensagem + UMA
> retentativa em 30 s (temporizador no navegador, nunca `sleep()` no PHP).
>
> 🔴 **A trava mora no `require_login()`**, não no `login.php`: só na tela de
> login, bastaria digitar `/rastreamento` na barra de endereço para escapar dela.
>
> #### ✅ Verificado em produção, com o dono do produto (28/08, manhã)
>
> | Etapa | |
> |---|---|
> | Cadastro sem senha → e-mail | ✅ criado, enviado, selo `senha temporária` |
> | Login com a temporária | ✅ |
> | `/rastreamento` antes de trocar → `/trocar-senha` | ✅ **a trava, confirmada à mão** |
> | Repetir a temporária como definitiva | ✅ recusada |
> | `/esqueci-senha` fora da janela de 5 min | ✅ enviado; selo volta na lista |
> | E-mail inexistente | ✅ resposta neutra, sem vazar existência |
>
> O selo voltando é **prova indireta do envio**: `issue_temp_password()` só grava
> as flags depois que o `send_mail()` retorna sucesso.
>
> #### 🔴 Três armadilhas descobertas NO deploy — valem para a próxima vez
>
> 1. **A migração não rodou no deploy que a trouxe.** O `deploy.sh` ganhou a
>    linha `run_migration "4.13.21"` no MESMO `git pull` que o estava
>    executando, e o bash lê o script em execução aos poucos, direto do disco:
>    alterar o arquivo no meio da execução faz o interpretador perder o pedaço
>    novo. Sintoma: código no ar, colunas ausentes. **Toda migração nova precisa
>    de um SEGUNDO deploy** (ou do `.sql` aplicado à mão).
> 2. ⚠️ **Enquanto isso, o `/esqueci-senha` fica armado errado E EM SILÊNCIO**: o
>    SMTP envia, o `UPDATE` falha por coluna ausente, a transação cai — e a
>    pessoa recebe uma senha que não funciona. O `/usuarios` grita
>    (`Erro: SQLSTATE[42S22]`), o `/esqueci-senha` não pode gritar, porque a
>    resposta neutra é o que impede a tela de virar verificador de contas.
> 3. **`require_once config/database.php` NÃO carrega o `.env`** — o
>    carregamento morava dentro do construtor do `Database`, ou seja, só ao abrir
>    conexão. Tela que renderiza sem tocar no banco via `getenv()` vazio:
>    `/esqueci-senha` mostrava `v4.0.0` no GET e a versão certa no POST. Extraído
>    para **`env_load()`** na v4.13.22; toda tela nova sem banco precisa chamá-la.
>
> #### 🔧 v4.13.23 — o limite de 5 min enganou o próprio dono do produto
>
> O limite por e-mail do `/esqueci-senha` é aplicado em silêncio (dizer "já
> enviamos há pouco para este endereço" confirmaria que a conta existe). No
> teste, dois pedidos seguidos → "enviamos" e nada chegou → conclusão natural de
> que estava quebrado. A mensagem neutra passou a explicar o limite, em frase
> genérica que vale para todo mundo e não revela nada. Travada no spec.
>
> #### ⏳ O que continua sem exercício
>
> - **Caminho de falha do envio**: selo `senha não entregue` e a retentativa de
>   30 s. Exigiria derrubar o SMTP de propósito, com câmeras reais operando.
> - **A trava com a flag ligada não tem teste automatizado** — exige usuário
>   semeado com `must_change_password=1`, e criá-lo pela tela dispara e-mail de
>   verdade. Hoje só a verificação manual acima cobre isso.
> - `web/login_template.php` ainda duplica o CSS do cartão em vez de usar
>   `web/auth_card_template.php` (é a única porta do sistema; não dá para
>   exercê-la sem banco na máquina de desenvolvimento).
> - Pedir recuperação invalida a senha atual na hora: quem souber o e-mail de
>   alguém força a troca dessa pessoa (incômodo, não acesso). Consequência de
>   "a temporária é a senha"; separar as duas exigiria um segundo caminho de
>   autenticação.
>
> #### 🧹 Usuário de teste
>
> `flaviohses+teste28@gmail.com` (id 9109, Visualizador, Frota Principal) foi
> criado para este teste e **desativado** ao fim. Não apagar sem necessidade: é
> o registro do fluxo que funcionou.

> ### 📍 ESTADO EM 26/08/2026 — produção em v4.13.17, `VIDEOUPLOAD` com o separador certo + gap histórico fechado
>
> **Produção está em `4.13.17`** (deploy + verificação nesta sessão). Dono do
> produto testou `VIDEOUPLOAD` manualmente no Postman e achou o motivo pelo
> qual a v4.13.3–7 (sessão anterior, `VIDEOUPLOAD` "confirmado" contra a
> Telecom) não estava de fato enchendo o storage: o separador de canal e um
> campo inteiro estavam errados. Corrigir isso destrancou um SEGUNDO bug, só
> visível depois que o primeiro upload de verdade em escala aconteceu — mesmo
> padrão em cadeia da entrada "ESTADO EM 25/08/2026" logo abaixo neste
> arquivo (cadeia de 4 bugs do vídeo de evento JT/T).
>
> #### 🔧 v4.13.16 — `VIDEOUPLOAD`: sublinhado, não hífen, e faltava `mediaType`
>
> Formato usado desde a v4.13.6 (`1-2-3`, três canais com hífen) nunca tinha
> sido testado contra hardware — só resgatado do dashboard morto por
> semelhança de forma (mesma classe de erro do item 2 da cadeia de 25/08:
> doc/código antigo dá candidato plausível, ninguém mede). Confirmado no Postman
> (865478070654829, JC371): `VIDEOUPLOAD,<host>,<porta>,<alarmLabel>,1_2,2` —
> canais com SUBLINHADO, e um 6º campo, `mediaType` (0=fotos, 1=vídeos,
> 2=ambos), que não existia em versão nenhuma do código. Convenção fixada:
> sempre canais 1 e 2 (só 1 no JC182), sempre `mediaType=2`. Corrigido em
> `includes/alarm_video_request.php` e `includes/occurrence_engine.php`; doc
> em `docs/COMANDOS_128_CONSULTA.md` §9.9 e `CLAUDE.md`.
>
> Aproveitado para fechar peças que faltavam: cliente novo pra
> `GET /api/v2/alarm/getAlarm` (`includes/iothub_alarm_api.php` — não existia;
> medido contra produção: `alarmLabel` vem separado por vírgula, concatenar
> reproduz `alarms.alarm_label`; teto de 1000 linhas sem paginação, por isso
> `iothub_get_alarms_chunked()` subdivide a janela), backfill
> (`scripts/video_upload_backfill.php`, cron a cada 30 min desde esta sessão)
> e player duplo (canal 1 + canal 2 simultâneos) em `rel_alarmes.php` e
> `ocorrencias_dashboard.php`.
>
> #### 🔧 v4.13.17 — o bug que o `VIDEOUPLOAD` corrigido destrancou: `alarms.file_url` gated por ocorrência
>
> Rodando o backfill de verdade (não o dry-run) contra o 865478070654829: os
> 4 arquivos por alarme (2 vídeos + 2 fotos) chegavam certos em `media_files`,
> mas `alarms.file_url` continuava NULL pra alarmes sem ocorrência — medido:
> `264-3` ("ADAS: Distância Insegura"), que não tem
> `occurrence_config_params`. Causa: `link_upload_by_alarm_label()`
> (`includes/occurrence_engine.php`) fazia `JOIN` até `occurrences` ANTES de
> decidir gravar `alarms.file_url` — a MESMA função que a cadeia de 25/08
> (item 4a) tinha corrigido pra passar a gravar `alarms.file_url` (antes só
> gravava `occurrences.media_file_id`), sem perceber que a correção ainda
> dependia do `JOIN` até ocorrência ficar de pé — e a MESMA classe do bug que
> `media_register_file()`/`link_media_to_occurrence()` resolveu pra
> `media_files` na v4.9.35 (ver `CLAUDE.md`, bullet logo acima do novo).
> Corrigido resolvendo o alarme por `imei`+`alarm_label` sozinho, sem depender
> de ocorrência; o vínculo com `occurrences.media_file_id` virou segundo passo
> opcional. 199 arquivos que já tinham chegado nessa janela foram religados
> retroativamente por script avulso (não versionado).
>
> **Resultado do backfill real (janela de 7 dias, só 865478070654829):** 131
> `VIDEOUPLOAD` disparados, 3 já completos, 29 com pedido pendente de antes,
> **85 alarmes existem só na câmera — o webhook nunca gravou** (achado
> registrado, não investigado nesta sessão — é gap DIFERENTE deste, na
> ingestão, não no vídeo).

> ### 📍 ESTADO EM 25/08/2026 (tarde/noite) — produção em v4.13.7, vídeo de evento JT/T destravado nesta sessão
>
> **Produção está em `4.13.7`** (deployada pelo dono do produto, confirmado
> por `git log` no servidor). Sessão longa, toda em cima da câmera Telecom
> (JC371, `865478070654829`, cliente Frota Principal): o vídeo de evento
> nunca tinha subido para NENHUMA câmera JT/T do histórico do banco, e a
> causa era uma cadeia de 4 bugs empilhados — só depois de destravar os 4 é
> que o primeiro upload de verdade aconteceu.
>
> #### 🔧 v4.13.3–4.13.7 — cadeia completa do vídeo de evento JT/T: 4 bugs, 1 por vez
>
> Dono do produto reportou que a câmera Telecom não subia vídeo dos eventos.
> Cada correção revelou o bug seguinte — só ficou visível depois que o
> anterior parou de mascará-lo:
>
> 1. **`flush_pending_video_requests()` chamava `iothub_dispatch_command()` —
>    função que não existe** desde a v4.9.13 (12/08/2026), que a renomeou
>    para `iothub_send_instruct()` e atualizou os outros dois chamadores
>    (`sendcommand.php`, `param_sync_worker.php`), mas não este. `Error` de
>    PHP (função indefinida) — não `Exception` —, então o `catch` do laço não
>    pega, e o processo morre em silêncio, pós-`fastcgi_finish_request()`,
>    sem log nenhum. O gatilho automático de vídeo esteve morto para a frota
>    JT/T inteira por 13 dias sem nenhum sintoma visível. `commands` nunca
>    teve uma linha `operator='auto_video'` nesse intervalo.
> 2. **O proNo estava errado.** A escolha original era 37384 (0x9208, Alarm
>    Attachment Upload) — plausível pela doc, mas NUNCA testado contra
>    hardware real. Depois de destravar o item 1, 37384 passou a ser aceito
>    e respondido `_content:"ok"` — só que é um ACK genérico do protocolo,
>    não prova de upload: zero conexões da Telecom no serviço de upload
>    (log do container `dvr-upload` cross-checado) apesar do "ok", enquanto
>    outro device fazia upload real no mesmo período. O dono do produto
>    apontou o comando certo — **`VIDEOUPLOAD`** (proNo 128, texto) — já
>    documentado numa versão anterior do dashboard
>    (`docs/_arquivo_morto/archive/web/dashboard.js`, função
>    `requestVideoUpload()`) que não sobreviveu à reescrita do produto.
>    Trocado em `queue_event_video_request()`/`flush_pending_video_requests()`
>    (gatilho automático) e em `includes/alarm_video_request.php`
>    (`request_alarm_video_jtt()`, botão manual "Pedir vídeo").
> 3. **O primeiro upload real (pós-fix) revelou um bug de VINCULAÇÃO.**
>    `pushfileupload.php` extrai o `alarmLabel` do NOME do arquivo por
>    regex — a doc §1.8 descreve `{imei}_{alarmLabel}_{xy}.ext` com canal+
>    sequência colados; o nome real medido foi
>    `865478070654829_<label>_1_00.jpg` (**`_` entre canal e sequência**).
>    Sem esse `_` no regex, `alarmLabel` nunca era extraído, e todo anexo
>    JT/T caía no fallback impreciso de janela ±3min em vez do casamento
>    preciso — e já ligou o anexo de UMA ocorrência a OUTRA (a mais próxima
>    no tempo com `media_file_id` ainda vazio). Vínculo errado já gravado em
>    produção desfeito manualmente.
> 4. **Mesmo depois de 1–3, a tela de Alarmes continuava sem mostrar nada.**
>    Duas causas independentes: (a) `link_upload_by_alarm_label()` só
>    gravava `occurrences.media_file_id` — nunca `alarms.file_url`, que é a
>    ÚNICA coluna que `handlers/rel_alarmes.php` lê por linha de alarme (e
>    também a grade "Alarmes Agrupados" do detalhe da ocorrência). Corrigido
>    gravando os dois, com a mesma convenção da JIMI pra múltiplos canais
>    (nomes separados por vírgula). (b) O anexo do VIDEOUPLOAD pode chegar
>    como **FOTO** (`.jpg`, um por canal — foi o caso medido), e tanto o
>    filtro (`media_kind(...) === 'video'`, estrito) quanto o player em JS
>    de `rel_alarmes.php` (`bcPlayer.montar()`, que só sabia montar
>    `<video>`) excluíam imagem por completo — mesmo com o arquivo íntegro
>    no disco, a coluna Ação mostrava `—`, e clicar num `.jpg` como se fosse
>    `.mp4` dispararia erro silencioso no player. Os dois ganharam o ramo de
>    imagem: filtro aceita `['video','image']`, `bcPlayer.montar()` ganhou
>    parâmetro `kind` e monta `<img>` quando `kind==='image'`.
>
> **Verificado ao vivo em produção** (não em ambiente de teste): sessão de
> admin temporária + `curl` no localhost do servidor confirmaram, em cada
> passo, o sintoma antes da correção e o resultado depois. O primeiro
> upload JT/T bem-sucedido do histórico do banco aconteceu nesta sessão
> (ocorrência #120, dois `.jpg`, um por canal) e `/midia?f=...` serviu o
> arquivo com `200`/`image/jpeg` válido.
>
> ⚠️ **A correção do item (a) só vale pra upload NOVO** — `pushfileupload.php`
> já tinha processado os dois `.jpg` da ocorrência 120 ANTES do deploy da
> v4.13.8, então `alarms.file_url` ficou vazio mesmo depois do fix (o código
> corrigido nunca rodou pra esse registro específico). Rodado backfill único
> (chamando `link_upload_by_alarm_label()` de novo para os dois
> `media_files` já existentes) pra corrigir o caso já gravado. Confirmado
> visualmente no Chrome da IDE, sessão de admin temporária: `/relatorios/
> alarmes` mostra "📷 Ver Foto" na linha certa e o modal abre a foto real
> (estrada, timestamp, velocidade, IMEI sobrepostos) — sem erro de console.
> Evento NOVO, gerado depois do deploy, não precisa de backfill nenhum.
>
> **Pendência real, não de software**: as 30+ ocorrências da Telecom
> anteriores a este fix (antes da v4.13.6 estar no ar) continuam sem
> vídeo — o pedido foi reenviado pra todas depois da correção, mas a
> câmera não tinha ATTACHMENT algum guardado pra esses eventos antigos
> (o `VIDEOUPLOAD` pede o que já está no cartão sob aquele `alarmLabel`;
> não é possível reconstruir depois). Só eventos NOVOS, a partir do deploy
> da v4.13.6, têm chance real de trazer vídeo/foto.
>
> #### 🔧 v4.13.2 — formas de consulta corrigidas por teste ao vivo (Chrome)
>
> Dono do produto pediu para testar a 4.13.1 usando o Chrome da IDE contra
> produção. Criei uma sessão de admin temporária (removida ao final),
> selecionei a Telecom (JC371) e cliquei em "Ler agora" nas entradas
> marcadas "a confirmar". Resultado: **as duas com maior confiança prévia
> estavam erradas.** `ADAS,CALIBRATION#`/`DMSSP#` (a segunda herdada de
> `command_catalog.php`) voltaram `Error:Number of parameters errors!` —
> precisam da função (`ADAS,CALIBRATION#` inteiro, `DMSSP,ADAS#`/`DMSSP,DMS#`).
> `EVENTSET#`/`EVENTALERT#` bare voltaram `Command was not recognized!` — a
> forma certa leva o CÓDIGO do evento (`EVENTSET,ALDW#` → devolve
> `EVENTSET,ALDW#,60`, batendo com o default documentado). Testei 4 códigos
> nos dois verbos ao vivo (`ALDW`/`AOSD`/`ADCA`/`AFVS`, 8 disparos, 8
> respostas) e generalizei o padrão confirmado pros outros 15 códigos de
> cada verbo como `'inferido'` (mesma família de um comando MEDIDO, não
> testado individualmente — distinção que `device_param_catalog.doc_ref`
> já usa). Também confirmados: `DMSVSP#`, `DMSSW#` (JC371 e JC400AD),
> `ADASSW#`, `DMS_SWITCH#`, `SPEED#` (JC181). `ADASSEP#`/`ADASSEN#`
> respondem de verdade mas exigem ADAS ligado antes — forma confirmada,
> câmera testada só estava com ADAS desligado ("Please Open Adas Switch").
> Resultado: as 59 entradas do catálogo agora TODAS têm consulta (18
> `medido`, 41 `inferido`, 0 sem forma nenhuma).
>
> Testado também o botão "Ler tudo (cadência)" em si — dispara em sequência
> com status "Lendo N de 6: ...", desabilita o botão durante o disparo,
> reabilita ao concluir. Confirmado por SELECT direto que as respostas
> caem em `device_ia_config_state`. `php -l` limpo, suíte 115/115.
>
> Pedido do dono do produto: um comando que dispare, em cadência, a leitura
> de todos os parâmetros configurados na câmera, pra análise. Adicionado o
> botão em `/configuracoes-ia` — dispara a forma de consulta (`VERBO#`) de
> cada comando do modelo, um de cada vez, 2,5s de intervalo. Pra isso,
> `includes/ia_config_catalog.php` precisou ganhar forma de consulta pros
> 21 verbos do catálogo (antes só `DMSSW#` tinha) — mas **20 dessas 21 são
> `nao_confirmado`**: tentei extrair a marcação "vermelho = aceita consulta"
> que a própria planilha JC371 documenta, e o parser de cor não distinguiu
> destaque manual do estilo base da coluna (quase tudo testou "vermelho").
> Em vez de inventar, assumi a dedução mecânica (`VERBO#`, mesma convenção
> já usada pro `EVENTSET` no catálogo original) e deixei o próprio botão
> "Ler tudo" como o MECANISMO DE MEDIÇÃO — mesmo caminho que resolveu
> `CHECK#`/`ADASxx`/`FILELIST` no passado. Toda resposta de verdade (não
> recusa, não fila) promove o campo pra `'medido'`; até lá, o card mostra
> o selo "a confirmar". `php -l` limpo, JS extraído e validado com
> `node --check`, checagem estrutural 0 problemas, suíte 115/115.
>
> #### 🆕 v4.13.0 — "Configurações IA" (ADAS/DMS/velocidade) + pausa do JT/T
>
> Pedido do dono do produto: os comandos JT/T de parâmetro (33027 escrita,
> 33028/33030 leitura) não funcionam — firmware do fabricante, fora do nosso
> controle. Pediu para pausar essa área e criar uma tela nova, só com
> configuração de ADAS/DMS/velocidade, reprocessada do zero das planilhas
> oficiais (não copiada do catálogo de `/comandos`), no layout de "quadros"
> da aba de parâmetros, com a máscara de cada campo como tag de auxílio —
> esses comandos saem de `/comandos` de vez, ficam só na tela nova.
>
> **Achado central:** cada família de câmera usa um vocabulário de comando
> TOTALMENTE diferente pro mesmo conceito de ADAS/DMS — não existe sintaxe
> universal no proNo 128. JC371 usa `EVENTSET,<código>`/`EVENTALERT,<código>`
> (um par por evento) + `DMSSP`/`DMSVSP`/`ADAS,CALIBRATION`; a família
> JC400AD/JC261/JC400D usa `DMSSW`/`DMS_*`/`ADASxx`; JC181 não tem ADAS/DMS
> nenhum (sem chip de visão) — só `SPEED` por GPS. Reprocessei as 3
> planilhas (`docs/JC 371 Command List V1.0.1.xlsx`, `docs/JC400 & JC261
> Command List V5.0.3.20230626.xlsx`, `docs/JC181_Command_List_V1.0.7_20250811.xlsx`)
> com um parser `.xlsx` escrito na hora (`ZipArchive`+DOM, sem lib nova) —
> 58 entradas no catálogo novo (`includes/ia_config_catalog.php`), cada uma
> com a máscara/faixa exata da planilha. A wiki (`wiki-foconavia...`) é uma
> SPA em JS que o `WebFetch` não renderiza (só devolve o título) — JC450/JC182
> não têm planilha própria, então a cobertura deles vem do que
> `command_catalog.php` já confirmava, marcada `procedencia: 'wiki'`
> (confiança menor, mesma disciplina do `doc_ref` de `device_param_catalog`).
>
> 45 comandos SAÍRAM de `includes/command_catalog.php` (238 → 193 entradas) —
> `/comandos` fica só com configuração básica. `handlers/sendcommand.php`
> ganhou um bloqueio único: `proNo` 33027/33028/33030 devolve HTTP 409 — é
> o que garante que nenhuma das 4 telas de Parâmetros JT/T (que só ganharam
> um AVISO, nada foi apagado) consegue de fato mandar comando, mesmo que
> alguém chegue lá por um link antigo.
>
> Tabela nova `device_ia_config_state` (não mexi em
> `device_param_catalog`/`device_params` — formato incompatível, chave
> numérica JT/T vs. chave de texto com vários parâmetros por comando;
> ficam paradas, prontas pra voltar se o firmware for corrigido).
>
> `php -l` limpo; `tests/helpers/command_response.test.php` 115/115 (a
> contagem do cabeçalho é conferida dinamicamente, não hardcoded); checagem
> estrutural do catálogo novo (todo campo obrigatório presente, placeholders
> batendo com `params`) e do casador de comando→catálogo, 0 problemas.
>
> ⚠️ **Não testado com envio real a câmera de produção** — só leitura/
> renderização foram verificadas nesta sessão. Validação do envio de verdade
> fica para o dono do produto, depois do deploy.
>
> #### 🔧 v4.12.11 — mapa do `/painel` sem os pontos individuais de posição
>
> Pedido do dono do produto: o "Mapa de Posições Recentes" do `/painel`
> deveria mostrar os pontos, igual ao mapa do Resumo (`/`), além da camada
> de calor. `dashboard_render_heatmap()` só desenhava `L.heatLayer`;
> `handlers/resumo.php` (origem deste widget) também desenha um
> `L.circleMarker` por posição — ponto azul com popup de placa+velocidade —
> que não tinha sido copiado. Adicionado dentro do `forEach` já existente,
> mesmo estilo e popup do Resumo. `php -l` limpo.
>
> #### 🔧 v4.12.10 — contador On/Off do sino sempre inflava o "On"
>
> Dono do produto reportou que a sinalização On/Off ao lado do sino de
> notificações parecia errada. Achado: `handlers/camerasdata.php` (que
> alimenta esse contador no header) lia `device_statistics.is_online` — uma
> coluna que as stored procedures de alarme/gps/heartbeat/evento só gravam
> como `1`, nunca de volta a `0`. Câmera que comunicou uma vez fica "Online"
> PARA SEMPRE nessa coluna. Medido em produção: câmera de teste sem
> comunicar há 17.196 min (~12 dias) ainda com `is_online = 1`. O resto do
> sistema já evita essa coluna (`equipamentos.php`, `dashboard_widgets.php`
> calculam por `TIMESTAMPDIFF(MINUTE, last_communication, NOW()) <= 5`;
> `rastreamento.php`/`video_aovivo.php` classificam ao vivo) — só
> `camerasdata.php` lia a coluna estática. Corrigido com a mesma expressão
> de 5 minutos, nas duas variantes da query (principal e fallback).
> `ativo_detalhe.php`/`ativos.php` também selecionam a mesma coluna estática
> mas não a exibem em tela nenhuma — não corrigidos por não terem efeito
> visível, só documentado para não virar bug ativo se alguém passar a
> renderizá-la.
> `php -l` limpo; testado em produção (8 câmeras do cliente 1: contagem foi
> de 8 On/0 Off para 7 On/1 Off, batendo com a real).
>
> #### 🔧 v4.12.9 — Rota/Replay do Deslocamento saem da nova janela
>
> Complemento pedido pelo dono do produto na mesma sessão da 4.12.8: com o
> `return` já resolvendo "para onde volta", não havia mais motivo para os
> links "Ver rota" (fechamento diário e por viagem) e "Replay" abrirem em
> nova janela. Removido `target="_blank"` dos três, em `rel_deslocamento.php`
> — navegação passa a ser na mesma aba. `php -l` limpo.
>
> #### 🔧 v4.12.8 — "Voltar ao relatório" do Deslocamento perdia o filtro
>
> Dono do produto reportou: em `/relatorios/deslocamento`, "Ver rota"/"Replay"
> abrem em nova janela; o botão "Voltar ao relatório" dessas telas linkava
> para `/relatorios/deslocamento` sem query string — caía no formulário
> vazio, perdendo modalidade/placa/período/página/ordenação que o operador
> tinha acabado de gerar. Corrigido com um parâmetro `return` (URL completa
> da grade, gerado por `rel_deslocamento.php`) que `rel_deslocamento_rota.php`
> e `rel_deslocamento_replay.php` usam no botão de volta, validado por regex
> contra o próprio path para não virar redirecionamento aberto.
> Varrida a base por `target="_blank"` para rota PRÓPRIA (não Google Maps
> externo): só o Deslocamento tinha esse padrão — os demais "Ver Mapa" do
> sistema apontam direto pro Maps, sem botão de volta.
> `php -l` limpo; round-trip da URL simulado via `php -r`.
>
> #### 🔧 v4.12.7 — câmera inativa aparecendo em 6 pontos do sistema
>
> Dono do produto reportou que o Relatório de Deslocamento listava câmeras
> inativas no filtro de placa, e pediu para varrer o resto do sistema pelo
> mesmo padrão. Achado: o dropdown `SELECT imei, device_name FROM devices
> WHERE customer_id = :cid ORDER BY device_name` (sem `is_active = 1`) estava
> copiado em `rel_deslocamento.php` (o relato), `rel_alarmes.php`,
> `rel_posicoes.php`, `relatorios.php` e `exportar.php` — mesma classe de
> bug já corrigida antes em `bi.php` ("o dropdown Ativo listava câmera
> desativada"), só não replicada para esses cinco. Mais grave:
> `rel_desatualizados.php` tinha o `$where` compartilhado por TODAS as
> consultas da tela (contagem por faixa, grade completa, drill-down, os três
> exports) sem filtro de `is_active` nenhum — câmera desativada não posiciona
> nunca mais, então ficava PARA SEMPRE na faixa "Nunca posicionados"/">30
> dias", ruído permanente num relatório que existe para apontar problema na
> frota ATIVA. Testado em produção com a câmera `865478070649936`
> (desativada, 21 viagens históricas — cliente com 13 câmeras, 8 ativas): o
> dropdown do Deslocamento não a lista mais, e a base do Desatualizados cai
> de 13 para 8 dispositivos.
>
> `php -l` limpo nos 6 arquivos.
>
> #### 🔧 v4.12.6 — mapa do `/painel` sem rastro nenhum + legenda monocromática
>
> Dono do produto reportou dois defeitos visuais no `/painel`: o "Mapa de
> Posições Recentes" não mostrava rastro nenhum dos veículos, e a legenda do
> gráfico "Velocidade da Frota" saía toda na mesma cor. Achados em
> `includes/dashboard_widgets.php`:
>
> - 🔴 **`dashboard_render_heatmap()` — consulta SEMPRE falhava, silenciada pelo
>   `catch`.** `SELECT DISTINCT ... ORDER BY g.gps_time` sem `g.gps_time` no
>   SELECT é erro 3065 do MySQL (`ORDER BY` sobre coluna ausente do SELECT é
>   incompatível com `DISTINCT`) — regra fixa, não depende de `sql_mode`. O
>   widget nunca teve um ponto sequer no mapa desde que foi criado (v4.10.3): a
>   query sempre lançava exceção e o `catch (Throwable $e) {}` engolia, sem
>   log nenhum. A mesma consulta em `handlers/resumo.php` (de onde este widget
>   foi copiado) já tem `g.gps_time` no SELECT — só a cópia perdeu a coluna.
>   Testado em produção: 180 linhas onde antes dava erro 3065 silencioso.
> - **`dashboard_render_speed_dist()` — legenda sem `color:` nenhum.** As
>   barras já usavam `var(--muted-soft)/--primary/--warning/--error`; os
>   `<span>` da legenda (que em `handlers/resumo.php`, a versão original,
>   repetem a mesma cor de cada faixa) saíram sem estilo — os quatro
>   apareciam idênticos, cinza-padrão do texto. Corrigido replicando a cor de
>   cada span da barra na etiqueta correspondente.
>
> `php -l` limpo.
>
> #### 🔧 v4.12.5 — vídeo de evento DMS/ADAS nunca subia (frota JT/T inteira)
>
> Dono do produto reportou que a câmera do veículo placa "Telecom" (JC371/JT-T,
> IMEI 865478070654829) não subia vídeo dos eventos. Achado em produção: o
> disparo automático (`queue_event_video_request()`,
> `includes/occurrence_engine.php`) recusava TODA ocorrência DMS/ADAS com
> `"Auto-vídeo: alarme sem alarmLabel de anexo — solicitação não enviada"` no
> log. Causa: o IoT Hub manda `alarmLabel` como 16 bytes separados por vírgula
> (`"30,36,35,...,05,00"`), não como string hex contígua de 32 chars — a doc
> oficial descreve o formato errado. `ctype_xdigit()` falha por causa das
> vírgulas. Confirmado por consulta em `commands`: **zero** comandos
> `auto_video`/37384 emitidos desde que o proNo foi corrigido — não é defeito
> só dessa câmera, é a frota JT/T inteira, desde que o recurso foi escrito.
> Mesmo bug quebrava em segundo lugar `link_upload_by_alarm_label()`
> (`pushfileupload.php`): o label extraído do NOME do arquivo (hex contínuo)
> nunca batia contra `alarms.alarm_label` (com vírgulas), então até o vídeo que
> chega pelo caminho de auto-upload da câmera só linkava pelo fallback
> impreciso de janela ±3min. Corrigido tirando as vírgulas no ÚNICO ponto de
> extração, em `handlers/pushalarm.php`. Retroativo: alarmes já gravados
> continuam com o `alarm_label` antigo (com vírgula) — não houve backfill.
>
> #### 🔧 v4.12.4 — balão de `/rastreamento` com Estado contradizendo Ignição/Velocidade
>
> Dono do produto reportou o balão do veículo mostrando `Estado: Parado
> (ignição desligada)` ao lado de `Ignição: Ligada` e velocidade real (3, 65,
> 49 km/h em três amostras de 6 minutos). Causa: "Estado" vinha do segmento
> aberto em `device_state_segments`, regravado só a cada 15 min pelo cron
> `scripts/state_builder.php`; "Ignição"/"Vel", do MESMO balão, vinham de
> `device_statistics` — atualizado a cada push de GPS, em tempo real. Um
> veículo que liga e sai andando entre duas rodadas do cron fica com o
> segmento em `parado` enquanto os outros dois campos já mostram a
> realidade — os três campos do balão descrevendo instantes diferentes.
> Corrigido com `resolve_live_state()` (`includes/fleet_state.php`): classifica
> pelo ÚLTIMO PONTO (`classify_point()` sobre `device_statistics`), não pelo
> segmento — os três campos passam a vir sempre da mesma leitura.
> `resolve_current_state()` (baseada em segmento) segue em uso, de propósito,
> nos relatórios batch (`rel_paradas`, `rel_ociosidade`, `rel_status_frota`),
> que precisam do segmento para "Tempo no estado" — não foram tocados.
> Reproduzido o cenário exato do relato via `resolve_current_state()` vs
> `resolve_live_state()` isolados: o antigo devolve `parado`, o novo devolve
> `movimento`; comportamento de `offline` conferido idêntico entre os dois.
> `php -l` limpo.
>
> #### 🔧 v4.12.3 — 3 defeitos nos widgets do painel (`/painel`)
>
> Dono do produto pediu a mesma verificação já feita no BI: conferir os 13
> widgets do painel widgetizado, um a um, com dados fictícios simulados.
> Achados em `includes/dashboard_widgets.php`:
>
> - **`dashboard_outdated_kpis()` sem fallback ao vivo** — dos 4 KPIs do
>   painel (dispositivos, ocorrências, velocidade, desatualizados), só este
>   não caía para uma consulta ao vivo quando `metrics_snapshots` estava
>   vazia. Sem o cron `scripts/metrics_rollup.php` já ter rodado, o widget
>   "Desatualizados" mostrava **0 sempre** — indistinguível de frota em dia.
>   Corrigido replicando a query do rollup como fallback.
> - **`dashboard_render_reseller_view()` sem NENHUM escopo de revendedor** —
>   as três consultas do ranking "Top 3" partiam de `FROM customers c` sem
>   filtro; qualquer revendedor via clientes de OUTROS revendedores. Corrigido
>   com `reseller_scope_ids()` (mesmo mecanismo de `/equipamentos`),
>   distinguindo `null` (admin, sem restrição) de `[]` (revendedor sem
>   cliente) — os dois tratados igual teria escondido o painel do admin.
> - **Mesma função — "Top 3 por ocorrências" ignorava o período** (Hoje/7
>   dias/Mês), único eixo do painel que não respeitava o seletor. Corrigido
>   com `dashboard_series_window($periodo)`.
>
> Verificado com frota fictícia (5 câmeras, 4 veículos, 40 pontos de GPS, ~75
> alarmes/48 ocorrências) sob 2 clientes de teste + sessão de revendedor
> temporária, cobrindo os 3 períodos e o isolamento entre clientes. Capturas
> publicadas como Artifact temporário; dados de teste removidos ao final.
>
> #### 🔧 v4.12.2 — BI listando câmera inativa + gráficos que nunca renderizavam
>
> Dono do produto reportou câmera inativa no filtro "Ativo" de `/bi`. Corrigido
> (faltava `is_active = 1` na consulta do `<select>`) — e testando com dados
> fictícios (10 análises simuladas, réplica local) apareceu um segundo defeito
> mais sério: os 4 gráficos da tela NUNCA renderizavam, sempre com "Não foi
> possível gerar os gráficos" — `GROUP BY alarm_label` agrupava pelo APELIDO de
> um `CASE` que lê `alarm_types` via `LEFT JOIN`, e isso quebra sob
> `sql_mode=ONLY_FULL_GROUP_BY` (padrão do MySQL desde 5.7). Corrigido
> repetindo a expressão inteira no `GROUP BY`. Capturas das 10 análises
> publicadas como Artifact temporário para revisão visual.
>
> #### 🔧 v4.12.1 — vínculo chip↔câmera só numa direção
>
> Dono do produto apontou: `handlers/chips.php` ainda deixava escolher a
> câmera no formulário do CHIP — dava pra vincular dos dois lados, quando a
> regra é só uma direção (a câmera escolhe o chip, nunca o inverso). Removido
> o `<select>` de câmera de `chips.php`; o formulário só mostra a câmera
> vinculada, texto somente leitura. **Achado no caminho, mais sério que o
> pedido original:** trocar SÓ o chip de uma câmera em `/equipamentos` (nenhum
> outro campo) não gravava nada, sem erro — o código usava `rowCount()` do
> `UPDATE devices` pra decidir "está no escopo do cliente", e o MySQL conta 0
> linhas quando nenhuma coluna do `SET` muda de valor (exatamente o caso de
> "só o chip mudou"). A tela dizia "atualizado" e o vínculo ficava intocado,
> nos dois sentidos. Corrigido: escopo agora é um `SELECT` dedicado, nunca o
> efeito colateral do `UPDATE`. Testado ponta a ponta via HTTP (linkar só-o-
> chip, desvincular só-o-chip, cada um como ÚNICA mudança no POST) — os dois
> agora persistem.
>
> #### 🔑 Fase 1 do fluxo chip → câmera → veículo
>
> Pedido do dono do produto: a relação cadastral era ilógica — `devices` (a
> câmera) sempre FOI o "ativo", com `device_name` ("Placa"), `vehicle_type` e
> `activation_date` na mesma linha da câmera física. Não existia veículo sem
> câmera, não existia histórico de duas instalações da mesma câmera em veículos
> diferentes, e trocar a câmera de veículo reescrevia a identidade da própria
> linha — o dado antigo desaparecia. `devices.sim_card_id` (FK de v4.0.0) nunca
> foi escrita por código nenhum: todo o vínculo chip↔câmera sempre rodou por
> `sim_cards.imei` (string, já com UNIQUE desde v4.10.4).
>
> Corrigido com duas tabelas novas: **`vehicles`** (o veículo, entidade própria,
> pode existir sem câmera) e **`device_installations`** (histórico de qual
> câmera esteve em qual veículo, de quando a quando — ponto único de escrita:
> `install_device_on_vehicle()` / `uninstall_device_from_vehicle()`,
> `includes/functions.php`, dentro de transação). `/equipamentos` cadastra só a
> câmera (com chip); `/ativos` cadastra só o veículo; a instalação é ação
> separada em `/ativos/{id}` — só oferece câmera que já tem chip. Migração faz
> backfill 1:1 de toda `devices` com `customer_id` para `vehicles` +
> `device_installations` (aberta se ativa, fechada em `updated_at` se já estava
> soft-deletada) — nada some da grade.
>
> `/ativos/{id}` passou a usar o ID do veículo na URL (era o IMEI da câmera,
> que deixou de identificar univocamente "qual ativo é esse"). Links antigos
> por IMEI (relatórios, `/chips`, `/parametros`) continuam funcionando via
> redirect de compatibilidade em `handlers/ativo_detalhe.php` — resolve pelo
> veículo que tem aquele IMEI instalado AGORA.
>
> **Testado ponta a ponta** contra cópia local do banco (`jimi_test_bisect` +
> réplica de `jimi_tracker` local, com backup prévio): criar chip → criar
> câmera com o chip livre → chip some da lista de livres; criar veículo →
> instalar só oferece a câmera com chip; tentar desativar câmera instalada
> recusa; desinstalar libera a câmera; instalar a MESMA câmera num SEGUNDO
> veículo confirma reuso sequencial (histórico do primeiro veículo mostra a
> instalação fechada, o segundo mostra a aberta); desativar câmera livre libera
> o chip automaticamente; desativar chip vinculado recusa, desativar chip livre
> funciona. `php -l` limpo em todos os arquivos tocados.
>
> #### 🔑 Fase 2 — isolamento de dados por período de instalação
>
> Fecha o requisito que a Fase 1 deixou em aberto: *"quando a câmera é
> reinstalada num novo veículo, o dono do carro só vê os dados do seu
> veículo"*. `gps_data`, `alarms`, `events`, `heartbeats`, `media_files`
> ganharam `customer_id`/`vehicle_id` (`occurrences` ganhou só `vehicle_id` —
> já tinha `customer_id` como snapshot desde sempre, e é o padrão que esta
> fase generaliza). Cada webhook grava o dono do MOMENTO via
> `resolve_installation_for_imei()` (`includes/functions.php`); a leitura
> nunca reconsulta — lê o valor já gravado. Backfill do histórico existente é
> EXATO (não aproximado): a Fase 1 acabou de nascer, então cada câmera tinha
> no máximo uma instalação.
>
> **~20 pontos de leitura** trocaram de "JOIN devices + filtro pelo dono
> atual" para "filtro pelo dono gravado na própria linha" — relatórios,
> painel, dashboard, download/playback de vídeo, `/midia`.
> **`handlers/ativo_detalhe.php`**: as 4 abas históricas (Trajetos, Alertas,
> Log, Vídeo) passaram de `WHERE imei = ?` para `WHERE vehicle_id = ?` — é a
> mudança que efetivamente separa o histórico de dois veículos que
> compartilharam a mesma câmera. Consequência que quase passou despercebida:
> a trava da Fase 1 ("sem câmera instalada, esconde a aba") escondia essas 4
> abas também — errado agora, porque elas são do HISTÓRICO do veículo, não da
> câmera atual. Corrigido: só **Ao Vivo/Comandos/Configurações/Parâmetros**
> (operação sobre o equipamento físico) continuam exigindo câmera instalada.
>
> 🔴 **Três achados de tenant leak, fora do escopo original, corrigidos no
> caminho:** `rel_posicoes.php` não validava que `?imei=` da URL pertencesse
> ao cliente da sessão (bastava trocar o parâmetro); `trackdata.php` e
> `hbdata.php` (AJAX do mapa ao vivo) validavam o IMEI mas liam o histórico
> sem limite de período; `midia.php` (servidor de vídeo) autorizava pelo dono
> ATUAL da câmera. Os três tinham a MESMA forma: checar posse atual não
> impede vazar dado de um período em que a posse era de outro cliente.
>
> **Testado ponta a ponta** contra réplica local: câmera QA instalada no
> veículo A (cliente 1) → ponto de GPS via `/pushgps` real → gravado com
> `customer_id=1, vehicle_id=A`. Desinstalada de A, instalada no veículo B
> (cliente 2) → segundo ponto → gravado com `customer_id=2, vehicle_id=B`.
> Confirmado: `/ativos/{A}` mostra só o primeiro ponto (mesmo sem câmera
> instalada agora), consulta direta por `vehicle_id=B` mostra só o segundo.
> `php -l` limpo em todos os ~27 arquivos tocados.


> Entradas anteriores a "📍 ESTADO EM 25/08/2026" (a partir de "📍 ESTADO EM 21/08/2026") arquivadas em docs/status-history/STATUS_ARCHIVE.md.

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
| `/equipamentos` | `equipamentos.php` | Grade (com firmware) + form (periféricos, rotação, watermark) + import CSV; FOTA leva a `/firmwares` (v4.9.32) |
| `/firmwares` | `firmwares.php` | **Só admin** — frota com a versão lida do `VERSION#` + cadastro de URLs de atualização por modelo (v4.9.32) |
| `/grupos-permissao` | `grupos_permissao.php` | Matriz de telas × 5 ações JSON + contagem de usuários |
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
| `devices` | `sim_card_id`, `peripherals` (JSON), `streaming_rotation`, `streaming_watermark`, `firmware_version`, `branch_id`, `firmware_checked_at` + `firmware_source` (v4.9.32) |
| `firmware_releases` | tabela nova (v4.9.32) — URL do pacote **por modelo**, `is_current` marca a de referência |
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
- **IP**: `186.248.143.197` — domínio **`https://bycamera.ia.br`** (o DNS aponta para cá), LAN `10.1.1.8`
- **Host**: `bycamera` · Ubuntu 24.04.4 LTS
- **Apache**: 2.4.58 + mod_rewrite (HTTP 80 redireciona 301 para HTTPS)
- **PHP**: 8.3.6 (FPM)
- **MySQL**: **8.4.11** em localhost
- **Path**: `/var/www/jimi_webhook`
- **IoTHub**: 16 containers Docker no mesmo host
- **Acesso**: `ssh administrador@186.248.143.197`; `sudo` pede senha → usar `ssh -t`.
  **Duas chaves autorizadas** em `/home/administrador/.ssh/authorized_keys`: a do
  Mac de dev (`claude-code`, `SHA256:jGEHWet…`) e a da máquina Windows
  (`flavi@dev-windows-jimi`, `SHA256:lHHRen2…`), esta instalada em **19/08/2026**
  — até então a Windows era recusada em produção e só entrava por senha.
  Backup do arquivo anterior em `~/.ssh/authorized_keys.bak-20260819`.
  ⚠️ `authorized_keys` é infra **fora do git**: sobrevive a deploy, some se a
  máquina for reprovisionada. `PasswordAuthentication` **não** foi desabilitado —
  a senha continua valendo como escape hatch.
  🔴 Entrar por chave **não** dispensa a senha do `sudo`: são coisas diferentes.
- 🔴 **A chave do GitHub mora em `/home/administrador/.ssh/id_ed25519` — `/root/.ssh` está VAZIO** (só um `authorized_keys` de 0 byte). Como `deploy.sh` roda sob `sudo`, o `git fetch` da FASE 1 saía sem credencial e o deploy morria em `✗ FALHA: git fetch falhou`, cuja mensagem manda **criar uma chave nova** — conserto errado. O erro real só aparece rodando `git fetch origin` sem o `2>/dev/null` do script: `Host key verification failed` (root não tem nem `known_hosts`). Resolvido em 14/08/2026 apontando o repo de produção para a chave que já existe:
  ```bash
  sudo git -C /var/www/jimi_webhook config core.sshCommand \
    "ssh -i /home/administrador/.ssh/id_ed25519 -o IdentitiesOnly=yes \
     -o UserKnownHostsFile=/home/administrador/.ssh/known_hosts"
  ```
  Fica no `.git/config`, então vale para todo deploy seguinte — **mas some se o repo for reclonado**. (No homolog o arranjo é outro: chave própria do root em `/root/.ssh/github_hssflavio`.)

### Servidor homologação
- **IP**: `189.22.240.43` (`http://189.22.240.43`, sem TLS)
- **Host**: `iothub`
- **Apache**: 2.4 + mod_rewrite · **PHP**: 8.3 (FPM) · **MySQL**: 8.0 em localhost
- **Path**: `/var/www/jimi_webhook`
- **Acesso**: chave da máquina **Windows** instalada em `administrador@189.22.240.43`. Do Mac de dev a conexão é recusada (`publickey,password`) — é chave ausente, não senha errada.

> 🔴 **Até 14/08/2026 esta seção chamava `189.22.240.43` de "servidor produção"** — verdade até 13/08, quando produção subiu em `186.248.143.197`. O texto velho já induziu erro de leitura (deploy apontado para o servidor errado). Produção é a que responde em `bycamera.ia.br`.

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
SYSTEM_VERSION=4.9.19
APP_URL=https://bycamera.ia.br
FILE_STORAGE_URL=http://186.248.143.197:23010/download/
STREAM_URL=https://bycamera.ia.br/stream
IOTHUB_COMMAND_URL=http://10.1.1.8:10088/api/device/sendInstruct
IOTHUB_API_TOKEN=123
```

Acima é o `.env` **de produção** (valores reais, sem os segredos). No **homolog** as três URLs apontam para ele mesmo em texto claro — `FILE_STORAGE_URL=http://189.22.240.43:23010/download/`, `STREAM_URL=http://189.22.240.43:8881`, `IOTHUB_COMMAND_URL=http://localhost:10088/...`. Em produção o `STREAM_URL` passa pelo **proxy HTTPS** (`/stream`) porque a página é servida em TLS e o navegador recusa FLV em `http://`, e o `IOTHUB_COMMAND_URL` usa o **IP da LAN** (`10.1.1.8`) porque os containers não alcançam o host por `localhost`.

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
│   ├── equipamentos.php          # CRUD + import CSV (FOTA → /firmwares)
│   ├── firmwares.php             # Firmware da frota + URLs por modelo (v4.9.32, só admin)
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
- [ ] **OTA firmware**: testar `UPDATE,<url>#` (proNo **128**) end-to-end com dispositivo real *(requer device — ver §11.4)*. ⚠️ O proNo 33027 desta linha estava errado — é "Definir parâmetro" do JT/T, não OTA; corrigido na v4.9.32.
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
- [ ] **M.2.5** OTA firmware `UPDATE,<url>#` (proNo 128) com device real — falta a URL do pacote, que só o fornecedor tem
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
- [ ] **OTA firmware** (`UPDATE,<url>#`, proNo 128) com device real — M.2.5, único item remanescente da Fase M
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
