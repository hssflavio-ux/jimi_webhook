# Changelog

Todas as mudanças notáveis deste projeto serão documentadas neste arquivo.

O formato é baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.0.0/),
e este projeto adere ao [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased] — 4.14.1

**Dois defeitos relatados no playback JT/T no mesmo dia: horário do vídeo ora em GMT, ora em GMT-3; e requisição de lista de gravações falhando em câmeras de mais de um canal.**

### Fixed
- **`begin`/`end` do 37381 (`/pushresourcelist`) é hora LOCAL da câmera (UTC−3), não GMT 0** — a mesma armadilha já corrigida para o `FILELIST` da JIMI (`includes/filelist.php`), nunca medida para o lado JT/T. `cleanDate()` tratava o campo como já-UTC; agora soma `FILELIST_OFFSET_SEGUNDOS` antes de gravar em `resource_lists`, a mesma constante e a mesma regra do FILELIST. Medido em produção em 31/08/2026 contra `865478070654829` (JC371, veículo em movimento): pedido um 37381 da janela real das últimas 4h, o bloco mais recente devolvido tinha `end_time` 3h00m04s atrás do `NOW()` do servidor — se já fosse UTC, estaria a segundos do `NOW()`, não a 3h.
- **`handlers/video_playback.php` mandava de volta ao equipamento (37377/37382) o epoch bruto de `resource_lists`, agora corrigido para UTC de verdade — sem reconverter para local, o pedido de stream/extração ficaria 3h no futuro.** `fmtCompactLocal()` (novo) passa pelo mesmo `pbLocal()` que já convertia certo para o `HVIDEO` da JIMI; substitui `fmtCompactUTC(new Date(t*1000))` nos dois pontos (`pbSendCmd` do 37377 e do 37382).
- **`utcDaySegments()` → `localDaySegments()`**: fatiava o período pedido por dia UTC e mandava como se fosse dia local — perdia as 3 primeiras horas do dia BRT pedido e invadia a madrugada do dia seguinte. Agora fatia por dia de calendário local, sem conversão de fuso nenhuma (o rótulo que sai já é o que o equipamento espera).
- **37381 concorrente entre canais**: `onSubmitRequest()` disparava um `pbSendCmd` por canal/dia num `forEach`, todos em paralelo — a câmera ainda processava o primeiro pedido quando o próximo chegava, não respondia, e o comando voltava como falha. Só acontecia no JT/T (a JIMI sobe a lista inteira sozinha, sem pedido por canal). A fila agora é serializada: só avança no callback do pedido anterior — sucesso OU falha —, nunca em paralelo.

### Verificação
- Sonda direta em produção (`iothub_send_instruct` com 37381 para `865478070654829`, janela das últimas 4h): confirmou o offset de 3h nos horários de `resource_lists` antes da correção.
- Cruzamento independente: `alarms.file_url`/`media_files.file_name` da mesma câmera embutem um carimbo (`…20260831182702…`) que também é 3h atrás de `alarms.alarm_time` (comprovadamente UTC) — mesma classe de defeito, subsistema diferente, mesma câmera, mesmo dia.
- `php -l` limpo em todos os arquivos alterados. Não há migração — `resource_lists` tem TTL de 30 min (`captured_at`), então linhas gravadas antes da correção expiram sozinhas.

### Pendente
- Não medido: se o corpo numérico (epoch em ms) do 37381 sofre o mesmo deslocamento — a medição em produção usou o formato de string, que é o que a 865478070654829 mandou. `cleanDate()` aplica a correção nos dois ramos por precaução, mas só o de string foi comprovado.
- Não medido: se `media_files.file_name`/`alarms.file_url` dos alarmes JT/T (que também embutem hora local) são lidos em algum ponto do código como se fossem UTC — fora do escopo desta sessão, fica como suspeita a investigar se aparecer mais confusão de horário em telas de alarme.

## [Unreleased] — 4.14.0

**Comandos do protocolo 128 por SMS — um segundo transporte para o mesmo catálogo.**

O caminho normal de um comando de texto é o IoT Hub, por TCP. Quando a câmera não fala com o Hub — APN errado, `SERVER` apontando para o lugar errado, equipamento mudo — não havia como alcançá-la. O SMS chega pela rede da operadora, que é um caminho **independente**: é um canal de resgate.

### Added
- **Tela `/comandos-sms`** — o catálogo INTEIRO de `command_catalog.php` (o mesmo do `/comandos`, sem catálogo paralelo), com a trava por modelo, preview da string exata e disparo em lote. O **saldo é consultado a cada abertura da tela**, e o resumo diz quantos créditos o disparo vai custar antes de confirmar.
- **`includes/sms_gateway.php`** — ponto único de fala com a API da Allcance: login com cache do Bearer, saldo, envio. Nenhum outro arquivo chama o provedor.
- **`/pushsms?k=<segredo>`** — webhook de retorno. Grava o status de entrega **e a resposta que o equipamento devolve por SMS**, que é o que fecha o ciclo e faz do canal algo além de um disparo cego.
- **`/config-sms`** (admin) — credenciais da conta (senha cifrada AES-256-GCM), teste de credencial+saldo, e o gerador do segredo do webhook, que exibe a URL pronta para cadastrar no painel da Allcance.
- **`sms_settings`** e **`sms_commands`** (`migration_v4.14.0.sql`).

### Notas de implementação
- **O texto do comando é IDÊNTICO ao da plataforma** (`CMD,A,B#`), sem conversão. Decisão do dono do produto, apoiada na nota oficial das planilhas Jimi (JC450: *"Commands can all be delivered using any of the following ways: TCP, SMS, or TF card"*, mesmo formato de vírgula). ⚠️ Isso **derruba** a forma `CMD#666666#A#B` que a wiki Foco na Via documenta como "SMS" — o teste do catálogo continua afirmando que nenhuma sintaxe carrega `666666`, e o spec da tela nova repete a asserção.
- 🔴 **`sms_commands` é tabela própria, não `commands`.** Os estados do provedor (`entregue celular`, `saldo insuficiente`, `lista negra`, `message_text_invalid`) não cabem no enum `pending/queued/sent/executed/failed` sem tradução com perda, e `/commandstatus` faz polling em `commands` esperando o ciclo do Hub. Custo aceito: o histórico SMS ainda não aparece na aba de comandos de `/ativo_detalhe`.
- 🔴 **`sms_normalizar_msisdn()` é função nomeada e testada, não um `preg_replace` no handler.** `sim_cards.msisdn` é texto livre e a API aceita QUALQUER string sem reclamar — cobra o crédito e a mensagem não chega, sem erro em log nem tela. A armadilha específica é o `55`: DDD 55 é Caxias do Sul, então o prefixo do país só é removido quando o resto fica com 10 ou 11 dígitos. 20 formas reais fixadas em `tests/helpers/sms_webhook.test.php`.
- **"recebido" significa duas coisas.** Sozinho é confirmação de entrega; **com `mensagem` preenchida é a resposta do equipamento**. Tratar os dois igual faria a tela mostrar "Recebido" e jogar fora exatamente o que a câmera respondeu.
- **`/pushsms` não estende `WebhookHandler`** — aquela classe exige `WEBHOOK_TOKEN` no corpo e idempotência por MD5 de `data_list`, e o payload da Allcance não tem nem um nem outro. Segue o precedente do `/filelist`. A defesa é o segredo `k` na query (`hash_equals`), a única possível: o provedor não envia cabeçalho de autenticação nenhum. **Segredo não configurado = endpoint fechado** — aberto por omissão deixaria qualquer um inventar status de entrega e resposta de equipamento.
- ⚠️ **O webhook é da CONTA INTEIRA**, não da nossa aplicação. Item sem `referencia_numero` conhecido é logado e descartado — casar por número solto atribuiria a resposta de um SMS ao comando errado, já que o mesmo chip recebe muitos comandos ao longo do tempo.
- **Equipamento com chip sem número aparece na lista, desabilitado, com o motivo escrito e link para `/chips`** — em vez de sumir. Sumir faria a lista mentir por omissão. A trava de modelo **não** reabilita essas linhas: são dois motivos independentes de bloqueio.
- `/config-sms` entrou em `$navBottom` com `admin_only`, e **não** no grupo Cadastros: item de grupo é filtrado só por `can()`, que é permissivo por omissão, então tela de administrador dentro de um grupo aparece para todo usuário sem grupo. Mesma razão do `/firmwares`.
- `api_response` é coluna JSON e é gravada **sempre** com `json_encode()` — a lição do `3140 Invalid JSON text` que quebrou o callback de comando offline por meses.
- `customer_id`/`vehicle_id` de `sms_commands` são **snapshot** do dono no momento do envio (`resolve_installation_for_imei()`), e o histórico da tela filtra por eles — nunca por JOIN em `devices.customer_id`, que é só "quem tem a câmera hoje" (regra da Fase 2).

### Pendente
- **Cron de PULL** (`/relatorios/campanhas/sms`) como plano B para webhook indisponível. Não implementado de propósito: a doc diz que "cada relatório é disponibilizado apenas uma vez por consulta", e não foi medido se o PULL consome o que o webhook entregaria. Rodar os dois às cegas pode fazer status sumir.
- **Primeiro envio real não foi feito.** Chip M2M frequentemente não recebe SMS — é contratual da operadora, não técnico. O teste inicial precisa ser um comando inócuo (`STATUS#`) num equipamento só.
- **A migração não roda no deploy que a traz** — `./scripts/deploy.sh --force` duas vezes, ou o `.sql` à mão.

## [4.13.23]

**Achado no teste em produção com o dono do produto: pedir recuperação duas vezes seguidas parecia "não enviar nada".**

### Changed
- A mensagem neutra de `/esqueci-senha` passou a explicar o limite de reenvio: *"Se você já tinha pedido nos últimos minutos, use a senha do e-mail anterior: enviamos no máximo uma a cada 5 minutos."* O limite por e-mail é aplicado **em silêncio** de propósito (dizer "já enviamos há pouco para este endereço" confirmaria que a conta existe), e o efeito colateral era o legítimo concluir que o sistema estava quebrado. A frase é genérica, vale para todo mundo e não revela nada. Travada em `tests/senha_temporaria.spec.js`.

### Verificação em produção (v4.13.21 + migração)
- Cadastro sem senha → **"Usuário criado. Senha temporária enviada"**, selo `senha temporária` na lista, e-mail recebido.
- Login com a temporária → `/rastreamento` digitado na barra de endereço **cai em `/trocar-senha`** (a trava do `require_login()`, confirmada manualmente pelo dono do produto — é o ponto que nenhum teste automatizado cobre).
- Troca: senha igual à temporária recusada; senha nova aceita.
- `/esqueci-senha` fora da janela de 5 min: e-mail enviado e selo `senha temporária` de volta na lista — que é a prova indireta do envio, já que o selo só é gravado quando `send_mail()` retorna sucesso.
- ⚠️ Faltou exercitar em produção: o caso **"senha não entregue"** (falha de SMTP no cadastro) e a retentativa automática de 30 s. O SMTP global está saudável, e derrubá-lo de propósito não compensa.

## [Unreleased] — 4.13.22

**Achado testando a v4.13.21 em produção: o rodapé de `/esqueci-senha` mostrava `v4.0.0` enquanto o `/login` ao lado mostrava a versão real.**

### Fixed
- **`env_load()`** (novo, `config/database.php`) — o `.env` era lido **dentro do construtor do `Database`**, ou seja, só ao abrir conexão. Qualquer página que renderize sem tocar no banco enxergava `getenv()` vazio e caía nos valores padrão. `/esqueci-senha` é servida sem consultar nada num GET, então mostrava o fallback `4.0.0` do `SYSTEM_VERSION`; no POST (que consulta `users`) a versão certa aparecia — a assimetria GET/POST na mesma tela é a assinatura do defeito. A leitura virou função própria, idempotente e sem custo de conexão, chamada pelo construtor (comportamento inalterado) e pelo handler público.
- ⚠️ Vale para qualquer tela futura que renderize sem banco: `require_once config/database.php` **não** carrega o `.env` sozinho — é preciso chamar `env_load()`.

### Verificação
- Provado localmente com um `.env` temporário: antes de `env_load()`, `getenv('SYSTEM_VERSION')` vazio; depois, o valor do arquivo. O rodapé de `/esqueci-senha` renderizou `v9.9.9-teste` no servidor embutido, sem abrir conexão. `.env` de teste removido em seguida.

## [Unreleased] — 4.13.21

**Pedido do dono do produto: cadastrar usuário informando só o e-mail. O sistema gera uma senha temporária de 6 caracteres alfanuméricos, envia por e-mail e obriga a troca no primeiro acesso; a mesma mecânica atende "esqueci minha senha".**

### Added
- **`includes/password_reset.php`** (novo) — ponto único dos dois fluxos: gera a senha (alfabeto de 32 símbolos, sem `I`/`O`/`0`/`1`, porque a pessoa digita lendo do e-mail), envia e **só grava o hash se o envio deu certo**. Ordem deliberada: gravar antes faria uma queda de SMTP matar a senha que o usuário já usava — ele ficaria sem a antiga (sobrescrita) e sem a nova (não entregue).
- **`/esqueci-senha`** (`handlers/esqueci_senha.php`, rota pública) — resposta **sempre idêntica**, exista ou não o e-mail: diferenciar transformaria a tela num verificador de quem tem conta. Falha de SMTP também devolve a mensagem neutra (o erro vai para o log). Limite de 5 pedidos por IP/hora (`password_reset_log`) e, em silêncio, 1 por e-mail a cada 5 min.
- **`/trocar-senha`** (`handlers/trocar_senha.php`) — nova + confirmar, mínimo 6, recusa senha igual à temporária; ao gravar, chama `rotate_session_token()` (novo em `auth.php`), porque a credencial mudou.
- **`web/auth_card_template.php`** (novo) — cartão das telas fora do dashboard (sem sidebar: enquanto a senha for temporária não há navegação possível).
- **`/usuarios`**: botão **Reenviar senha temporária** por linha e selo **"senha não entregue"** (`must_change_password = 1 AND temp_password_sent_at IS NULL` — estado derivado, não uma quarta coluna).
- Link **"Esqueci minha senha"** no `/login`.
- `mysql/migration_v4.13.21.sql` — `users.must_change_password`, `users.temp_password_expires_at`, `users.temp_password_sent_at` e a tabela `password_reset_log`. Sem tabela de token: a temporária **é** a senha, em bcrypt como qualquer outra, e o login continua sendo um `password_verify()` só.

### Changed
- **`/usuarios`**: o campo Senha deixou de ser obrigatório na criação. Em branco = gera e envia; preenchido = vale direto, sem e-mail e sem troca obrigatória (mantido a pedido do dono do produto, para conta de teste ou usuário sem e-mail real).
- 🔴 **A trava do primeiro acesso mora em `require_login()`** (`includes/auth.php`), não no `login.php`. Redirecionar só na tela de login deixaria a trava valendo por convenção: bastava digitar `/rastreamento` na barra de endereço para usar o sistema inteiro com a senha que veio por e-mail. Exceções: `/trocar-senha`, `/logout`, `/login`, `/setup`.
- `login_user()` recusa senha temporária **vencida** com mensagem própria ("solicite outra em Esqueci minha senha") — dizer "senha incorreta" faria a pessoa tentar de novo e queimar o rate limit de 5 falhas por IP.
- Falha de envio no cadastro: mensagem na tela e **uma** retentativa automática em 30 s, com o temporizador no **navegador**. `sleep(30)` no PHP prenderia um worker do PHP-FPM — os mesmos que atendem os webhooks das câmeras.
- Texto do e-mail de recuperação **não** diz "ignore: sua senha anterior continua valendo" (o padrão do gênero): neste desenho ela já não vale quando a mensagem chega, e mandar ignorar trancaria o usuário do lado de fora sem explicação.

### Verificação
- `php -l` limpo em `handlers/`, `includes/`, `config/`, `core/` (o mesmo comando da FASE 4 do deploy) e nos dois templates; `bash -n scripts/deploy.sh`.
- `tests/helpers/temp_password.test.php` (novo, **18/18 sem banco**, rodado): 2000 senhas geradas — todas com 6 caracteres, nenhuma fora do alfabeto, nenhuma com `I`/`O`/`0`/`1`, gerador não constante; corpo do e-mail com a senha, botão absoluto só quando há `APP_URL`, nome escapado, e o rodapé corrigido travado nos dois sentidos.
- `tests/senha_temporaria.spec.js` (novo): link no login, render da rota pública, resposta neutra sem vazar existência, `/trocar-senha` sem sessão → `/login`, e usuário sem pendência → `/perfil`.
- Rotas exercidas no servidor embutido: `GET /esqueci-senha` = **200** com o formulário; `GET /trocar-senha` sem sessão = **302** para `/login?redirect=%2Ftrocar-senha`. Os dois corpos de e-mail renderizados e conferidos no navegador.
- ⚠️ **Não verificado nesta máquina** (sem MySQL e sem `.env`): o envio real por SMTP, a trava com a flag ligada e a migração. Conferir no homolog após o deploy — criar um usuário com senha em branco, confirmar a chegada do e-mail, e tentar `/rastreamento` antes de trocar a senha.

### Pendências conhecidas
- `web/login_template.php` continua com a própria cópia do CSS do cartão, em vez de usar `web/auth_card_template.php`. A extração ficou de fora de propósito: é a única porta do sistema e não há como exercê-la sem banco nesta máquina.
- Pedir recuperação invalida a senha atual na hora, então quem souber o e-mail de alguém pode forçar a troca dessa pessoa (incômodo, não acesso). É consequência direta de "a temporária é a senha"; separar as duas exigiria um segundo caminho de autenticação.

## [Unreleased] — 4.13.20

**Pedido do dono do produto: a camada de satélite passa a ser HÍBRIDA — imagem aérea com as vias e os nomes por cima. Imagem aérea pura não serve para operação de frota: o operador vê o telhado, mas não sabe em que rua o veículo está.**

### Changed
- **`bcMapBaseLayers()`** (`web/components/map_assets.php`) — a segunda camada de base deixa de ser `Satélite` e passa a ser `Híbrido`: um `L.layerGroup` com o `World_Imagery` mais os DOIS overlays transparentes de referência do próprio Esri — `Reference/World_Transportation` (traçado, nome e sentido das vias) e `Reference/World_Boundaries_and_Places` (limites e topônimos). Continua grátis e sem chave de API, e o rótulo do controle de camadas passa a `Ruas` / `Híbrido`.
- Vale para os 10 mapas do sistema de uma vez, sem tocar em nenhum handler — é exatamente o que a padronização da v4.13.18 comprou. Nenhum chamador passa `opts`, e o retorno mantém a chave `satelite` (agora um `L.LayerGroup`) para não quebrar assinatura.

### Verificação
- `php -l` limpo em `web/components/map_assets.php`.
- Os 3 serviços Esri sondados com `curl` em São Paulo nos zooms 13/15/17/19: **200** em todos os 12, `image/jpeg` na imagem e `image/png` nos dois overlays (o overlay é transparente, então tile faltante em algum quadrante cai sobre a imagem e nunca deixa o mapa em branco).
- Renderizado em Chrome com o próprio `BC_MAP_ASSETS_HTML`: nomes de rua, praças e setas de sentido legíveis sobre a imagem em z17, e a alternância `Ruas` ⇄ `Híbrido` troca as três camadas do grupo de uma vez.
- ℹ️ As linhas finas de borda de tile visíveis em alguns zooms são do Leaflet (arredondamento de device pixel ratio) e aparecem **igualmente na camada de satélite anterior** — verificado lado a lado; não é regressão desta mudança.

## [Unreleased] — 4.13.19

**Pedido do dono do produto: os links "Ver Mapa" que abrem em aba externa devem ir para o Google Maps, não o OpenStreetMap. Mesma lição da padronização de mapas (v4.13.18): 9 arquivos duplicavam a mesma URL do OSM.**

### Changed
- **`map_link_url($lat, $lng)`** (novo, `includes/functions.php`) — ponto único da URL de "Ver Mapa"; troca `https://www.openstreetmap.org/?mlat=…` por `https://www.google.com/maps/search/?api=1&query=lat,lng` (API pública "Universal" do Google Maps, sem chave). Substituído nos 9 pontos que geravam o link: `rel_alarmes`, `rel_desatualizados`, `rel_geocercas` (2×), `rel_ignicao`, `rel_posicoes`, `rel_status_frota`, `rel_velocidade`, `report_segments.php` e `export_helper.php` (exportação XLSX/CSV/PDF).
- A camada de tiles do mapa embutido (Leaflet "Ruas") continua OpenStreetMap — só o link externo mudou.

### Verificação
- `php -l` limpo nos 10 arquivos tocados.
- `tests/export.spec.js` atualizado para a URL nova (assert que quebraria em silêncio se alguém revertesse um dos 9 pontos).

## [Unreleased] — 4.13.18

**Pedido do dono do produto: camada de mapa satelital. Os 10 mapas do sistema duplicavam cada um o próprio `<link>`/`<script>` do Leaflet e o próprio `L.tileLayer(OSM)` — padronizado antes de acrescentar a camada nova, para não repetir a mudança 10 vezes.**

### Added
- **`web/components/map_assets.php`** (novo) — ponto único do `<link>`/`<script>` do Leaflet 1.9.4 e da função `bcMapBaseLayers(map)`, que adiciona as camadas Ruas (OpenStreetMap) e Satélite (Esri World Imagery — grátis, sem chave de API) mais o controle de alternância do próprio Leaflet (canto superior direito, recolhido).
- Camada de satélite disponível nos 10 mapas do sistema: `/rastreamento`, `/` (Resumo), `/painel` (heatmap), `/geocercas`, `/ativos/{id}` (ao vivo), `/relatorios/alarmes`, `/relatorios/desatualizados`, `/relatorios/posicoes`, `/relatorios/deslocamento` (rota e replay).

### Verificação
- `php -l` limpo nos 12 arquivos tocados.
- `BC_MAP_ASSETS_HTML` renderizado via `php -r` para conferir a concatenação com o `$extra_head` específico de cada tela (scripts extras como `leaflet.heat`/`chart.js` continuam entrando depois, sem duplicar o Leaflet).

## [Unreleased] — 4.13.17

**Achado rodando o backfill da v4.13.16 contra produção de verdade (não só o dry-run): o efeito real — não a resposta síncrona — mostrou que `alarms.file_url` continuava NULL mesmo com os 4 arquivos (2 vídeos + 2 fotos) já íntegros em `media_files`, para todo alarme sem ocorrência gerada.**

### Fixed
- 🔴 **`link_upload_by_alarm_label()` (`includes/occurrence_engine.php`) só gravava `alarms.file_url` quando o alarme JÁ TINHA ocorrência** — o `SELECT` fazia `INNER JOIN occurrence_events`/`occurrences` ANTES de decidir gravar, então um alarme sem `occurrence_config_params` pro tipo (medido: `264-3`, "ADAS: Distância Insegura (HMW)") nunca ganhava a coluna preenchida, mesmo com o arquivo íntegro no disco e a linha certa em `media_files`. Sintoma idêntico ao que esta MESMA função já tinha corrigido uma vez (25/08/2026, docblock da função) — só que para uma fatia diferente de alarmes; o comentário antigo prometia "grava alarms.file_url sempre" e isso nunca foi verdade para quem não tinha ocorrência. Agora a gravação em `alarms` é incondicional (resolve por `imei`+`alarm_label` sozinha, sem JOIN); o vínculo com `occurrences.media_file_id` é um segundo passo, opcional, que não bloqueia mais o primeiro.
- Reparados retroativamente os `alarms.file_url` que ficaram NULL durante a janela entre o deploy da v4.13.16 e esta correção (script avulso, não versionado — religou pelos `media_files` já no disco).

### Verificação
- `php -l` limpo.
- Reprodução em produção: alarme #13787 (264-3, sem ocorrência) tinha os 4 arquivos em `media_files` e `file_url` NULL antes da correção; depois de reaplicar `link_upload_by_alarm_label()` para os arquivos já recebidos, `file_url` ficou populado com os 4 nomes.

## [Unreleased] — 4.13.16

**Dono do produto descobriu, testando manualmente contra produção, que o comando `VIDEOUPLOAD` estava com o separador de canal errado — hífen em vez de sublinhado — e faltando um campo inteiro (`mediaType`). Corrigido, e aproveitado para fechar o gap: 263 dos 268 alarmes JT/T com anexo dos últimos 7 dias (só no JC371 865478070654829) nunca tiveram o vídeo pedido corretamente.**

### Fixed
- 🔴 **`VIDEOUPLOAD`: canais com SUBLINHADO (`1_2`), não hífen (`1-2-3`) — e um 6º campo, `mediaType`, que nem constava do catálogo.** A forma anterior (`includes/alarm_video_request.php`, `includes/occurrence_engine.php`) tinha sido resgatada do dashboard antigo mas NUNCA testada contra hardware — nem lá, nem aqui. Confirmado por teste manual do dono do produto (Postman, JC371 865478070654829, upload real chegou ao storage): `VIDEOUPLOAD,<host>,<porta>,<alarmLabel>,1_2,2` (`mediaType` 0=fotos, 1=vídeos, 2=vídeos e fotos). Convenção do produto: sempre canais 1 e 2 (só 1 no JC182, câmera única) e sempre `mediaType=2`. Ver `docs/COMANDOS_128_CONSULTA.md` §9.9.
- **`request_alarm_video()` bloqueava reenvio quando só a FOTO já estava no disco** — `media_available()` conta qualquer arquivo, e desde que `VIDEOUPLOAD` passou a trazer foto+vídeo juntos isso virou um bloqueio real. Trocado por `media_has_video()` (`includes/media.php`), que olha só arquivos de kind vídeo.

### Added
- **`includes/iothub_alarm_api.php`** (novo) — cliente do endpoint `GET /api/v2/alarm/getAlarm` (porta 9080, `tracker-dvr-api`), que não existia até agora. Medido contra produção: resposta traz `alarmMsg` como STRING JSON (decodificar duas vezes), `alarmLabel` separado por vírgula (concatenar reproduz `alarms.alarm_label` byte a byte), `alarmTime` em UTC, e teto de 1000 linhas sem paginação — `iothub_get_alarms_chunked()` subdivide a janela recursivamente quando bate no teto.
- **`scripts/video_upload_backfill.php`** (novo) — cruza os alarmes que a câmera já tem (via `getAlarm` v2) com o que este sistema já tem completo no storage (`media_video_complete()`), e dispara `VIDEOUPLOAD` só para o que falta. Escopo deliberadamente estreito: só age sobre alarme que já existe em `alarms` (webhook já processou); alarme só na câmera é reportado, não criado. Roda manual (backfill do histórico) e é cron-friendly (rotina permanente).
- **Player duplo (canal 1 + canal 2) em `handlers/rel_alarmes.php`** (modal) **e `handlers/ocorrencias_dashboard.php`** (detalhe) — os dois vídeos tocam simultaneamente, com fallback para o player único de sempre quando o arquivo é de formato antigo sem canal reconhecível no nome. Suporta `includes/media.php` novo: `media_channel_files()`, `media_canal_jtt_upload()`, `media_video_channels_no_disco()`, `media_video_complete()`.

### Verificação
- `php -l` limpo em todos os arquivos alterados/novos.
- `media_channel_files()`/`media_video_complete()` testados com nomes reais de produção (par de foto, par de vídeo JT/T, par JIMI `_F_`/`_I_`, nome cru de cartão de `filelist.php` — este último confirmado como NÃO batendo, evitando colisão com `media_canal_jtt_upload()`) e com o caso misto de 4 arquivos (2 fotos + 2 vídeos do mesmo alarme) — vídeo vence foto no mesmo canal, como esperado.
- `getAlarm` v2 testado ao vivo (ssh+curl, 865478070654829) antes de escrever o cliente — formato de resposta e teto de 1000 confirmados na prática, não pela doc.

## [Unreleased] — 4.13.15

**Dono do produto corrigiu a v4.13.14: o erro estava invertido. A planilha de comandos JC181 é explicitamente "applicable to JC181 series products" e nunca cita o JC182 — apesar do número de modelo maior, o JC182 tem BEM MENOS funções que o JC181.**

### Fixed
- 🔴 **`SENALM`, `COLLIDE`, `SPEEDCHECK`, `SWERVE`, `FATIGUE`, `GFENCE` (circular/retangular) e `SPEED` voltam a ser comandos do JC181, não do JC182** — em `includes/command_catalog.php` (removido JC182 dos 7 comandos) e em `includes/ia_config_catalog.php` (as mesmas 7 entradas mudaram de `modelos: ['JC182']` para `['JC181']`). Este era o terceiro erro consecutivo na mesma tarefa: v4.13.13 replicou o vocabulário do JC181 para os dois modelos; v4.13.14 tentou corrigir removendo o JC181 e deixando só o JC182 — na direção ERRADA. O JC182 mantém, nas duas telas, só os 2 códigos EVENTSET medidos em campo (`ACD`, `AVD`) + `AOSD` (velocidade, compartilhado com o JC371) — 3 comandos ao todo, consistente com o teste real de campo do início desta tarefa.

### Verificação
- `php -l` limpo em `includes/ia_config_catalog.php` e `includes/command_catalog.php`.
- Catálogo recarregado via `php -r`: JC182 caiu para 3 entradas em `ia_config_catalog.php` (eram 11); JC181 subiu para 8 (era 1). Em `command_catalog.php`, os mesmos 7 comandos voltaram a ter só JC181 (mais JC400D/JC400AD/JC450 onde já tinham antes desta sessão). Total de entradas inalterado nos dois arquivos — só o modelo foi corrigido.

## [Unreleased] — 4.13.14

**Dono do produto corrigiu a v4.13.13: os comandos de acelerômetro/GPS (senalm, speedcheck, swerve, collide, gfence, fatigue, EVENTSET,ACD/AVD) tinham sido replicados também para o JC181, mas o pedido listou os comandos especificamente por modelo — só o JC182 devia ganhá-los. E o texto "— dialeto planilha" nos cartões não comunica nada ao operador.**

### Fixed
- 🔴 **JC181 removido das 8 entradas trazidas para "Configurações IA" na v4.13.13** (`EVENTSET,ACD`, `EVENTSET,AVD`, `SENALM`, `COLLIDE`, `SPEEDCHECK`, `SWERVE`, `FATIGUE`, `GFENCE` circular e retangular) — em `includes/ia_config_catalog.php` e nos dois `GFENCE` de `includes/command_catalog.php` (os únicos que eu tinha criado do zero com os dois modelos; os demais já tinham JC181 de origem legítima, antes desta sessão, e não foram tocados). `SPEED` continua com os dois modelos — não fazia parte deste erro, já tinha JC181 de antes.
- **Removida a frase "— dialeto planilha" do rótulo dos cartões de `SENALM` e `COLLIDE`** (`ia_config_catalog.php`) — jargão interno que não ajudava o operador a entender o comando. A distinção real (qual comando é o dialeto EVENTSET/JT/T e qual é o de texto simples da planilha) já está no texto de descrição de cada cartão.

### Verificação
- `php -l` limpo em `includes/ia_config_catalog.php` e `includes/command_catalog.php`.
- Catálogo recarregado via `php -r`: JC181 caiu de 8 para 1 entrada (só `SPEED`) em `ia_config_catalog.php`; JC182 mantido em 11. Total ainda 79 (nenhuma entrada removida, só o modelo tirado das erradas).

## [Unreleased] — 4.13.13

**Dono do produto corrigiu a leitura da v4.13.12: "todos os outros comandos desse modelo devem estar na tela de comandos" se referia à própria tela de Configurações IA ("tela de comandos de IA"), não à tela genérica /comandos. Os comandos de acelerômetro/GPS do JC181/JC182 tinham ficado só em /comandos.**

### Added
- **10 entradas novas em `includes/ia_config_catalog.php` (tela Configurações IA) para JC181/JC182**: `EVENTSET,ACD` (colisão) e `EVENTSET,AVD` (vibração) — os dois dialetos EVENTSET medidos em campo no JC182 —, mais `SENALM`, `COLLIDE`, `SPEEDCHECK`, `SWERVE`, `FATIGUE` e `GFENCE` (circular e retangular) no dialeto "planilha JC181" para os dois modelos, e `JC182` adicionado ao `SPEED` que já existia para JC181. São exceção deliberada à regra "fora de escopo" do cabeçalho do arquivo (acelerômetro/GPS não é visão computacional) — mas JC181/JC182 não têm outra tela de configuração de IA, e o dono do produto pediu explicitamente que entrassem aqui. Duplicatas de propósito das entradas equivalentes em `command_catalog.php`/`/comandos` (mesma fonte, mesmos parâmetros — não removidas de lá).

### Verificação
- `php -l` limpo em `includes/ia_config_catalog.php`.
- Catálogo recarregado via `php -r`: 79 entradas (era 70), sem colisão de chave; 21 `medido`, 56 `inferido`, 2 sem forma de consulta (os dois `GFENCE`).

## [Unreleased] — 4.13.12

**Dono do produto reportou (26/08/2026): JC182 real, testado em campo, só responde a 3 dos ~9 códigos EVENTSET que a tela de Configurações IA mostrava para o modelo — bug de categorização herdado por analogia com o JC371. Pediu também: (1) completar os comandos reais do JC182 (planilha JC181 + confirmação de campo) e (2) deixar pronta a tela de Configurações IA para o JC450, que ainda não tem equipamento instalado mas tem planilha própria.**

### Fixed
- 🔴 **JC182 não tem câmera de IA/visão computacional — os 6 códigos ADAS/DMS (`ALDW`/`AHMW`/`ADCA`/`ACEA`/`ANDD`/`AFIF`) que `includes/ia_config_catalog.php` atribuía a ele por analogia com o JC371 nunca funcionaram.** Teste real de campo confirmou que o JC182 só responde a 3 códigos `EVENTSET`: `ACD` (colisão), `AVD` (vibração) — ambos por acelerômetro — e `AOSD` (excesso de velocidade). Os 6 códigos errados foram removidos do modelo; `EVENTSET,AOSD` ganhou o JC182.
- 🔴 **`EVENTSET,ACD` estava com o valor "80" cravado na CHAVE do catálogo** (`command_catalog.php`), sem `template`, impedindo enviar qualquer sensibilidade diferente de 80 pela tela de Comandos. Corrigido para `EVENTSET,ACD,P1#`, templada.
- 🔴 **`SPEED`, `SENALM`, `COLLIDE`, `SPEEDCHECK`, `SWERVE`, `FATIGUE` (`command_catalog.php`) tinham parâmetros incompletos, com arity ou descrição erradas** em relação à planilha oficial mais recente (`docs/JC181_Command_List_V1.0.7_20250811.xlsx`, V1.0.7): `SENALM` tinha 2 campos documentados (real: 5), `COLLIDE` tinha 4 (real: 8), `FATIGUE` tinha 3 campos sem descrição (real: 4), `SPEEDCHECK`/`SWERVE` tinham os 5 campos todos em branco, e `SPEED` trazia a descrição de dois campos TROCADA (B/D). Todos corrigidos e reescritos com os campos reais da planilha.

### Added
- **JC182 ganhou os comandos `SENALM`/`SPEED`/`SPEEDCHECK`/`SWERVE`/`COLLIDE`/`FATIGUE`** em `command_catalog.php` (tela de Comandos, não Configurações IA — são acelerômetro/GPS, não visão computacional), a pedido do dono do produto. Fonte: planilha JC181 V1.0.7, compartilhada entre os dois modelos por decisão de produto; sinalizado como não confirmado individualmente em hardware JC182 além do EVENTSET medido.
- 🆕 **`GFENCE` (cerca eletrônica circular e retangular) — comando novo, não existia no catálogo.** Adicionado para JC181/JC182 a partir da planilha JC181 V1.0.7 (linhas D011/D012). ⚠️ Um campo em cada variante não tem descrição nenhuma na planilha do próprio fabricante (sempre "1" nos dois exemplos oficiais) — sinalizado como desconhecido; não confirmado em câmera real.
- 🆕 **Tela "Configurações IA" preparada para o JC450** — 18 entradas novas/ajustadas em `includes/ia_config_catalog.php`, extraídas de `docs/JC450 series command list-EN V2.1.1.xlsx` (planilha própria do JC450, adicionada ao repo nesta sessão): velocidade (`SPEED`), ativação de IA (`DMSSP`), calibração por medidas físicas (`ADAS,CALIBRATION`, formato totalmente diferente do JC371), área de detecção (`DMSCROP`), intervalos por tipo de evento (`DMSPI`/`DMSVI`/`DMSSEP`), sensibilidade DMS (`DMSSEN`, condicional por tipo de evento), temporização DMS (`DMS_CONTINUITY`/`DMS_ALERT_CUSTOM`/`DMS_VOICE_CUSTOM`), velocidade virtual permanente (`FDMSVSP`) e cinto de segurança (`EVENTSET,AWSB`/`ANWSB`, arity própria do JC450). Sem equipamento real disponível — todas as consultas ficam `consulta_ref: 'inferido'`; nenhum dado enviado a hardware. `DMSSW`/`EVENTSET,AFIF`/`ADASSEN` ganharam JC450 nas entradas já existentes, com as diferenças de semântica por modelo documentadas nos próprios parâmetros.
- 🔴 **Corrigidos dois falsos-positivos que já estavam no catálogo antes desta sessão, achados ao cruzar com a planilha própria do JC450**: `DMSSP`/`ADAS,CALIBRATION` do JC371 tinham "JC450" no modelo por analogia nunca confirmada — a sintaxe real do JC450 para os dois é completamente diferente (2 campos vs. 4 em `DMSSP`; 5 medidas em mm vs. 1 letra de tipo de veículo em `ADAS,CALIBRATION`). JC450 removido dessas duas entradas e movido para as entradas próprias do modelo.

### Verificação
- `php -l` limpo em `includes/ia_config_catalog.php` e `includes/command_catalog.php`.
- Catálogo carregado via `php -r` para confirmar ausência de colisão de chave (nenhuma sobrescrita silenciosa) e contagem final: 70 entradas em `ia_config_catalog.php` (18 JC450, 43 JC371, 14 JC400AD, 1 JC182, 1 JC181), 0 sem `modelos` esperado.
- Tela `/configuracoes-ia` não exige mudança de JS: a filtragem por modelo já é 100% orientada a dado (`CATALOGO_IA.filter(x => x.m.indexOf(modelo) >= 0)`) — `device_models` já tem a linha `JC450` cadastrada (migration_v4.2.1.sql); a tela funciona assim que o primeiro equipamento real for registrado com esse modelo.
- Sem hardware JC450 disponível para teste ao vivo — pendência explícita, sinalizada nos comentários do catálogo.

## [Unreleased] — 4.13.11

**Dono do produto pediu: excesso de velocidade não é uma frente que o produto vai tratar por enquanto — elevar o limite padrão para não disparar o estado no uso normal.**

### Changed
- `DEFAULT_SPEED_LIMIT_KMH` (`includes/fleet_state.php`) elevado de **80 para 150 km/h**. É o piso usado por `resolve_speed_limit()` quando nem o equipamento (`devices.speed_limit_kmh`) nem o cliente (`customers.default_speed_limit_kmh`) têm valor cadastrado — afeta o estado "Excesso de velocidade" do balão em `/rastreamento` e qualquer outra tela que use `resolve_speed_limit()`/`resolve_live_state()`. Funcionalidade preservada (não removida): equipamento ou cliente com valor próprio cadastrado continuam usando o valor deles, sem alteração.

## [Unreleased] — 4.13.10

**Dono do produto pediu: remover do menu o item Resumo (substituído pelo Painel) e o item Parâmetros (tela ainda não funcional) — só a exibição, código intocado para uso futuro.**

### Changed
- Item de menu **Resumo** removido de `$navPrincipal` (`web/layout_base.php`) — o Painel (`/painel`) já é a tela inicial de fato. `resumo.php` e a rota `/` continuam funcionando normalmente para quem tiver o link direto; só some do menu.
- Item de menu **Parâmetros** removido de `$navBottom` (`web/layout_base.php`) — a tela (`/parametros`) ainda não está funcional (parametrização JT/T em andamento, ver `PROJETO_PARAMETROS.md`). O handler e a permissão em `grupos_permissao.php` ficam intocados de propósito.
- Ambos os itens foram comentados (não apagados), com nota explicando o motivo e como reintroduzir — reversível descomentando duas linhas.

## [Unreleased] — 4.13.9

**Dono do produto reportou: os gráficos do `/painel` são difíceis de entender — todos mostram "00" a "23" sem dizer o que é isso, fácil de confundir com dia do mês.**

### Fixed
- 🔴 **Os dois gráficos de barra do painel (`ts_alarms`/`ts_occurrences`) não tinham título nenhum de eixo.** Com `periodo=hoje` (padrão da tela), o eixo X é HORA do dia em GMT-3 (00–23) — sem rótulo, fácil de ler como dia do mês, ainda mais perto do dia 23–25. Adicionado título de eixo no Chart.js (X: "Hora do dia (GMT-3)" ou "Dia", conforme o período; Y: "Alarmes"/"Ocorrências") e uma legenda curta acima do gráfico ("Hoje, por hora (GMT-3)" / "Últimos 7 dias, por dia" / "Últimos 30 dias, por dia") — nova função `dashboard_period_caption()` em `includes/dashboard_widgets.php`, chamada pelos dois widgets.

### Verificação
- `php -l` limpo em `includes/dashboard_widgets.php`.
- JS gerado extraído e validado com `node --check` (sintaticamente correto, sem executar no navegador).

## [Unreleased] — 4.13.8

**Vídeo chegava (v4.13.7) mas continuava sem aparecer em `/relatorios/alarmes` — quarto bug da cadeia, dois motivos independentes.**

### Fixed
- 🔴 **`link_upload_by_alarm_label()` só gravava `occurrences.media_file_id`, nunca `alarms.file_url`.** `handlers/rel_alarmes.php` (e a grade "Alarmes Agrupados" do detalhe da ocorrência) leem exclusivamente `alarms.file_url`, por linha de alarme — sem essa coluna preenchida, o anexo ficava invisível ali mesmo já vinculado à ocorrência. Corrigido gravando as duas, com a convenção de múltiplos canais já usada pela JIMI (nomes separados por vírgula).
- 🔴 **O anexo do `VIDEOUPLOAD` pode chegar como FOTO (`.jpg`), não só vídeo** — medido em produção: um `.jpg` por canal para o mesmo alarme. `rel_alarmes.php` filtrava só `media_kind(...) === 'video'`, e `bcPlayer.montar()` (o player em JS do modal, `web/components/video_player_assets.php`) só sabia montar `<video>` — um `.jpg` carregado ali dispararia o evento `error` do `<video>` em silêncio. Os dois ganharam o ramo de imagem: filtro aceita `['video','image']`, `montar()` ganhou parâmetro `kind` e monta `<img>` quando for foto. Botão passa a dizer "Ver Foto" quando aplicável.

### Verificação
- `php -l` limpo em `includes/occurrence_engine.php`, `handlers/pushfileupload.php`, `handlers/rel_alarmes.php`, `web/components/video_player_assets.php`, `handlers/pushalarm.php`.
- `STATUS.md` ganhou entrada consolidada com a cadeia completa dos 4 bugs (v4.13.3–4.13.8); arquivamento rodado (`status-archive`) para manter as 3 entradas mais recentes inline.

## [Unreleased] — 4.13.7

**Primeiro upload JT/T de verdade da sessão (VIDEOUPLOAD pós-fix) — e ele revelou mais um bug: o anexo de uma ocorrência foi ligado a OUTRA.**

### Fixed
- 🔴 **`pushfileupload.php`: o regex de nome de arquivo do anexo JT/T nunca batia.** A doc §1.8 descreve o nome como `{imei}_{alarmLabel}_{xy}.ext` com canal+sequência colados sem separador; o arquivo real medido em produção é `865478070654829_<label>_1_00.jpg` — **com `_` entre canal e sequência**. Sem esse `_` no regex, a extração de `alarmLabel` falhava sempre, e TODO anexo JT/T caía no fallback impreciso de janela ±3min (`link_upload_to_occurrence()`) em vez do casamento preciso por alarmLabel (`link_upload_by_alarm_label()`). Consequência observada ao vivo: os dois canais do mesmo alarme (`_1_`/`_2_`) chegaram no mesmo segundo — o primeiro ligou certo à ocorrência dona (por sorte, era a mais próxima no tempo); o segundo, já com aquela ocorrência preenchida, foi parar na ocorrência ABERTA mais próxima seguinte — de um alarme completamente diferente (`Fadiga` recebendo a foto de `Motorista Fumando`). Corrigido o regex (`_(\d+)_(\d+)\.` no lugar de `_(\d)(\d+)\.`); vínculo incorreto já gravado (ocorrência 117 → mídia 111) desfeito manualmente em produção.

### Verificação
- Testado o regex antigo vs. novo contra os dois nomes reais medidos: antigo não batia em nenhum dos dois, novo bate nos dois com canal/sequência corretos.
- `php -l` limpo em `handlers/pushfileupload.php`.
- Confirmado em produção: gatilho automático (`VIDEOUPLOAD`, pós fix da v4.13.6) produziu o primeiro upload JT/T bem-sucedido do histórico do banco — 2 fotos (`.jpg`, uma por canal) para a ocorrência 120. `occurrences.media_file_id` populado corretamente após a correção do vínculo.

## [Unreleased] — 4.13.6

**Correção do dono do produto: 37384 não é o comando certo — é `VIDEOUPLOAD`, já documentado no dashboard antigo (arquivo morto).**

### Fixed
- 🔴 **`queue_event_video_request()`/`flush_pending_video_requests()` (gatilho automático) trocaram de 37384 (0x9208, Alarm Attachment Upload) para `VIDEOUPLOAD` (proNo 128, texto).** 37384 chega a ser aceito pelo device (resposta síncrona `_content:"ok"`), mas isso é só o ACK genérico do protocolo — nenhum upload de verdade acontece (confirmado em produção: zero conexões da Telecom no serviço de upload apesar do "ok", contra dezenas de conexões reais de outro device no mesmo período). `VIDEOUPLOAD` é o mesmo comando que `request_alarm_video_jtt()` já usa no caminho manual desde a correção anterior — os dois ficam consistentes.
- 🔴 **`flush_pending_video_requests()` ganhou `@set_time_limit(180)`.** `max_execution_time` (30s) continua correndo depois do `fastcgi_finish_request()` (mesmo alerta já documentado em `handlers/filelist.php`), e `iothub_send_instruct()` pode segurar até 35s por comando — sem estender, um device lento mata o processo em silêncio antes do curl retornar. É a mesma família do bug da v4.13.3 (função inexistente), só que na borda do tempo em vez do nome da função.

### Verificação
- `php -l` limpo em `includes/occurrence_engine.php`.
- Pendente: confirmar em produção que `VIDEOUPLOAD` automático também não produz upload real da Telecom (mesmo padrão do manual) — reforça a hipótese de falha de hardware da câmera, não do software, mas só fecha depois do deploy + reteste.

## [Unreleased] — 4.13.5

**Dono do produto reportou que os eventos JT/T sem vídeo continuavam "sem aparecer nas telas" — a API já devolvia tudo, só que a grade principal de `/ocorrencias/dashboard` nunca mostrava nada sobre vídeo.**

### Fixed
- 🔴 **`ocorrenciasdata.php` já calculava `has_media` desde sempre, mas nenhuma tela lia o campo.** A grade principal (`updateTable()` em `ocorrencias_dashboard.php`) não tinha coluna de vídeo nenhuma — só o detalhe de cada ocorrência (que exige abrir uma por uma) mostrava isso. Confirmado direto na API (sessão temporária + curl no localhost): as 32 ocorrências de hoje da Telecom estavam todas lá, corretamente, só invisíveis quanto a vídeo na grade.
- `has_media` também passou a considerar o degrau 2 da resolução de mídia (qualquer alarme do grupo com `file_url` próprio, mesma leitura que o detalhe já fazia) — antes só olhava `occurrences.media_file_id`, então uma ocorrência com vídeo saberia via `ocorrencias_dashboard.php?id=N` mas apareceria como "sem vídeo" na grade.

### Added
- 🆕 **Coluna "Vídeo" na grade principal de `/ocorrencias/dashboard`**: badge "Disponível" ou botão "Pedir vídeo" (mesmo `/solicitarvideo` de sempre) por ocorrência, usando o alarme mais recente do grupo (`repr_alarm_id`, subquery nova em `occurrence_events`). `pedirVideo()` saiu de dentro do `if($detailOcc)` do detalhe (onde só existia numa das duas renderizações da rota) para um `<script>` comum às duas — a grade principal precisava da mesma função e não tinha acesso a ela.

### Verificação
- `php -l` limpo em `handlers/ocorrenciasdata.php`, `handlers/ocorrencias_dashboard.php`.
- Único ponto de definição de `pedirVideo()` confirmado por grep (antes duplicava risco de `function already declared` se algum dia as duas branches fossem emitidas juntas).

## [Unreleased] — 4.13.4

**Câmera desativada continuava aparecendo no filtro de placa de `/relatorios/ocorrencias`, apesar da varredura da v4.12.7 — cobertura incompleta, não regressão.**

### Fixed
- 🔴 **`report_device_options()` (`includes/functions.php`) nunca filtrou por `is_active` — a v4.12.7 não a tocou.** Aquela varredura corrigiu 6 telas cuja consulta do dropdown de placa estava COPIADA inline (`rel_deslocamento.php`, `rel_alarmes.php`, `rel_posicoes.php`, `relatorios.php`, `exportar.php`, `rel_desatualizados.php`), mas esta função COMPARTILHADA — usada por `rel_ocorrencias.php`, `rel_geocercas.php`, `rel_velocidade.php`, `rel_ignicao.php`, `rel_status_frota.php`, `report_segments.php` — não é uma cópia da mesma query, então ficou de fora e continuou vazando as 5 câmeras inativas de produção (todas do mesmo cliente da Telecom) pra esses seis pontos. O comentário antigo da função ("relatório é histórico, não filtra por is_active") ficou defasado desde que a v4.12.7 decidiu o oposto para o mesmo dropdown nos outros seis arquivos — atualizado para refletir a decisão atual.
- **`config_dispositivos.php`** (console de parâmetros 33027-33031) e **`video_downloads.php`** (fila de extração de vídeo) também listavam câmera inativa no seletor — achados na varredura completa desta sessão por `FROM devices` sem `is_active` no projeto inteiro (excluindo `equipamentos.php`, que é o cadastro e deve mesmo listar todas).

### Verificação
- Varredura de todo `FROM devices`/`SELECT imei, device_name` no projeto (handlers + includes); confirmado que os demais já filtravam (`bi.php`, `checklist_inspection.php`, `exportar.php`, `geocercas.php`, `manutencoes.php`, `relatorios.php`, `rel_alarmes.php`, `rel_deslocamento.php`, `rel_posicoes.php`, `rel_desatualizados.php`, `comandos.php`, `configuracoes_ia.php`, `video_aovivo.php`, `video_playback.php`, `parametros.php`, `rastreamento.php`, `camerasdata.php`, `rel_status_frota.php`) ou são agregação histórica por alarme (não dropdown — `dashboard_widgets.php`/`resumo.php` "Top placas com mais alarmes"), onde manter o inativo é o comportamento certo.
- Confirmado em produção: 5 devices com `is_active=0`, todos do cliente "Frota Principal".
- `php -l` limpo em `includes/functions.php`, `handlers/config_dispositivos.php`, `handlers/video_downloads.php`.

## [Unreleased] — 4.13.3

**Vídeo de evento nunca subia sozinho para nenhuma câmera JT/T, desde 12/08/2026 — achado analisando a câmera Telecom a pedido do dono do produto.**

### Fixed
- 🔴 **`flush_pending_video_requests()` (`includes/occurrence_engine.php`) chamava `iothub_dispatch_command()` — função que não existe.** A v4.9.13 (12/08/2026, `f1a8bae1`) renomeou esse despacho para `iothub_send_instruct()` com assinatura/retorno diferentes e atualizou os outros dois chamadores (`sendcommand.php`, `param_sync_worker.php`), mas não este. Como é `Error` de PHP (função indefinida) — não `Exception` —, o `catch` do laço não pega, e o processo morre em background, pós-`fastcgi_finish_request()`, sem log nenhum. Toda solicitação automática de vídeo de alarme (proNo 37384) falhava assim, silenciosamente, para a frota JT/T inteira, desde então. Confirmado em produção: `commands` nunca teve uma linha com `operator='auto_video'`. Corrigido chamando `iothub_send_instruct()` com a assinatura atual; como essa função não grava mais em `commands` sozinha (responsabilidade que passou para o chamador na v4.9.13), o `INSERT` voltou a existir aqui — sem ele o dedupe/anti-rajada da própria função, que leem `commands WHERE operator='auto_video'`, continuariam cegos mesmo com o despacho funcionando.

- 🔴 **`request_alarm_video()` (`includes/alarm_video_request.php`) só sabia pedir vídeo com `EVIDEO`/`HVIDEO` — comandos do vocabulário JIMI, medidos só em JC400AD.** Testado ao vivo contra a Telecom (JC371, protocolo JT/T): `EVIDEO` recusado por aridade ("Number of parameters errors!") e `HVIDEO` nem é reconhecido — confirma o catálogo (`command_catalog.php`), que só lista os dois para `JC400AD`/`JC400D`. O comando certo para JT/T é **`VIDEOUPLOAD`** (também proNo 128, texto: `VIDEOUPLOAD,<host storage>,<porta>,<alarmLabel>,1-2-3`) — já tinha sido implementado numa versão anterior do dashboard (`docs/_arquivo_morto/archive/web/dashboard.js`, função `requestVideoUpload()`) que não sobreviveu à reescrita do produto; resgatado como `request_alarm_video_jtt()`, acionado automaticamente por protocolo do device.

### Added
- 🆕 **Botão "Solicitar vídeo" em `/ocorrencias/dashboard`** (card de mídia da ocorrência e cada linha sem vídeo da grade "Alarmes Agrupados") — pede o anexo de novo à câmera via `/solicitarvideo` (endpoint já existente, usado por `rel_alarmes.php` desde a v4.9.31), que agora escolhe `EVIDEO`/`HVIDEO` (JIMI) ou `VIDEOUPLOAD` (JT/T) por `device_models.protocol`. Cobre tanto o gatilho automático acima ter falhado silenciosamente quanto o vídeo genuinamente não ter chegado (sinal, câmera offline), sem exigir um worker de retry novo. `handlers/solicitarvideo.php` passou a aceitar tanto `relatorios` quanto `ocorrencias_dashboard` como permissão de tela, já que agora tem dois chamadores.

### Verificação
- `php -l` limpo em `includes/occurrence_engine.php`, `handlers/ocorrencias_dashboard.php`, `handlers/solicitarvideo.php`, `includes/alarm_video_request.php`.
- **Backfill em produção** (script one-off, não commitado): as 29 ocorrências JT/T de hoje sem vídeo (todas da Telecom) receberam `VIDEOUPLOAD` — 27 aceitas de primeira ("success" ou fila offline), 2 já tinham pedido pendente da sonda de calibração. Nenhuma recusa de sintaxe. Vídeo ainda não confirmado chegando (~3 min de espera, sem `/pushfileupload` correspondente) — compatível com o quadro de `Falha de Câmera`/`Perda de Sinal de Vídeo` que essa câmera vem gerando o dia inteiro (ver STATUS.md); acompanhar depois do deploy.

## [Unreleased] — 4.13.2

**Teste ao vivo da 4.13.1 (Chrome via IDE, sessão temporária de admin), pedido do dono do produto. Achado: as formas de consulta "a confirmar" estavam mesmo erradas, e o teste real corrigiu isso.**

### Fixed
- 🔴 **`ADAS,CALIBRATION#` e `DMSSP#` (as duas com maior confiança prévia — a segunda herdada de `command_catalog.php`) foram RECUSADAS pela câmera real** (Telecom, JC371, 865478070654829): `Error:Number of parameters errors!`. A forma certa precisa da função: `ADAS,CALIBRATION#` (o comando INTEIRO, não só `ADAS#`) e `DMSSP,ADAS#`/`DMSSP,DMS#`. Corrigido, `consulta_ref: 'medido'`.
- 🔴 **`EVENTSET#`/`EVENTALERT#` (bare, sem código de evento) foram recusados**: `Command was not recognized!`. A forma certa inclui o CÓDIGO do evento — `EVENTSET,ALDW#`, `EVENTALERT,ADCA#` etc. — e devolve só os valores daquele evento (`EVENTSET,ALDW#` → `EVENTSET,ALDW#,60`, batendo com o padrão documentado). Testado ao vivo em 4 códigos nos dois verbos (`ALDW`, `AOSD`, `ADCA`, `AFVS` — 8 disparos, 8 respostas): `consulta_ref: 'medido'`. Os outros 15 códigos de cada verbo passam a ter consulta própria `CMD,CÓDIGO#` seguindo o mesmo padrão confirmado — `'inferido'` (não testados individualmente, mas mesma família de um comando que FOI testado — distinção que `device_param_catalog.doc_ref` já usa).
- Também confirmados ao vivo (Telecom JC371 + `864993060429173` JC400AD + `860112070347838` JC181): `DMSVSP#`, `DMSSW#` (nos dois registros — JC371 de 2 parâmetros e JC400AD de 1, respondem ao mesmo verbo bare), `ADASSW#`, `DMS_SWITCH#`, `SPEED#`. `ADASSEP#`/`ADASSEN#` respondem de verdade mas exigem `ADASSW` ligado antes ("Please Open Adas Switch" quando desligado) — forma confirmada, câmera testada só estava com ADAS desligado.
- `includes/ia_config_catalog.php`: as 59 entradas agora TODAS têm forma de consulta (18 `medido`, 41 `inferido`, 0 sem forma) — zero ficou com um "a confirmar" genérico sem pista nenhuma.

### Verificação
- Testado ao vivo via Chrome (extensão da IDE), sessão temporária de admin criada e removida ao final: seletor de equipamento lista os 8 dispositivos/6 modelos corretamente; botão "Ler agora" e "Ler tudo (cadência)" disparam, exibem a resposta em tempo real e a gravam em `device_ia_config_state` — confirmado por consulta direta ao banco depois do teste.
- `php -l` limpo; suíte de comandos 115/115.

## [Unreleased] — 4.13.1

**Pedido do dono do produto, complemento da 4.13.0: um comando que dispare em cadência a leitura de todos os parâmetros configurados na câmera, para análise.**

### Added
- 🆕 **Botão "Ler tudo (cadência)"** em `/configuracoes-ia` — dispara a forma de consulta de cada comando do modelo selecionado, um de cada vez (2,5s de intervalo, não em paralelo), reaproveitando `iaEnviar()`/`iaAcompanhar()` já existentes por card — sem endpoint novo.
- **`includes/ia_config_catalog.php` ganhou forma de consulta (`VERBO#`) para 21 dos 22 verbos do catálogo** (antes só `DMSSW#` tinha, herdado e medido). O subtipo do evento (`ALDW`, `AHMW`...) é PARÂMETRO do comando, não o verbo — por isso só a PRIMEIRA entrada de cada verbo carrega a consulta (`EVENTSET#` já pede tudo de uma vez, não uma leitura por evento), mesma regra que `command_catalog.php` já aplicava à família `EVENTSET` original.
- 🔴 **20 dessas 21 consultas são `consulta_ref: 'nao_confirmado'`** — deduzidas mecanicamente (`VERBO#`), não medidas. Tentei extrair a marcação "vermelho = aceita consulta" que a planilha JC371 documenta na própria aba, mas o parser de cor não distinguiu destaque manual do estilo base da coluna (quase toda a coluna testou "vermelha"). Em vez de inventar, o botão "Ler tudo" É o mecanismo de medição — mesmo caminho que resolveu `CHECK#`/`ADASxx`/`FILELIST` no passado: dispara contra equipamento real, e toda resposta de verdade (não recusa, não fila) promove o campo para `'medido'`. Card cuja consulta ainda não foi confirmada mostra o selo "a confirmar".

### Verificação
- `php -l` limpo; JS embutido extraído e checado com `node --check`.
- Checagem estrutural do catálogo (todo campo obrigatório, placeholders batendo com `params`, toda `consulta` termina em `#`) — 0 problemas nas 58 entradas.
- `tests/helpers/command_response.test.php`: 115/115.

## [Unreleased] — 4.13.0

**Pedido do dono do produto: os comandos de parâmetro JT/T (33027 escrita, 33028/33030 leitura) não funcionam — problema de firmware do fabricante. Pausar essa área e criar uma tela nova, "Configurações IA", só com configuração de ADAS/DMS/velocidade, reprocessada do zero das planilhas oficiais (não copiada do catálogo de `/comandos`), com layout de quadros e a máscara de cada parâmetro como tag de auxílio. Esses comandos saem de `/comandos`, que passa a ter só configuração básica de equipamento.**

### Added
- 🆕 **`includes/ia_config_catalog.php`** — catálogo próprio de comandos de ADAS/DMS/velocidade (58 entradas), reprocessado direto de `docs/JC 371 Command List V1.0.1.xlsx` (JC371: `DMSSP`, `ADAS,CALIBRATION`, `DMSVSP`, 19 pares `EVENTSET`/`EVENTALERT` por evento, mais o par de excesso de velocidade), `docs/JC400 & JC261 Command List V5.0.3.20230626.xlsx` (JC400AD/JC261: `DMSSW`, `DMS_SWITCH`, `DMS_VOICE_CUSTOM`, `DMS_ALERT_CUSTOM`, `DMS_VIRTUAL_SPEED`, `DMS_CONTINUITY`, `DMS_CALIB_ABNORMAL`, `DMS_SECOND_EVENT`, `ADASSW`, `ADASSEP`, `ADASPI`, `ADASVI`, `ADASSP`, `ADASSEN`, `ADASVSP`) e `docs/JC181_Command_List_V1.0.7_20250811.xlsx` (JC181: `SPEED` — sem chip de IA, sem ADAS/DMS). **Cada família de câmera usa vocabulário totalmente diferente para o mesmo conceito** — não existe sintaxe universal de ADAS/DMS no proNo 128. JC450/JC182 não têm planilha própria (a wiki é uma SPA em JS que o `WebFetch` não consegue renderizar); cobertura desses dois vem do que o catálogo antigo já confirmava, marcada `procedencia: 'wiki'`. Fora de escopo, de propósito: `EVENTSET,FACE` (CRUD de biblioteca facial) e colisão/vibração por acelerômetro (`CRASHALM`, `SENSOR`, `SHOCK`, `COLLIDE`…) — continuam em `/comandos`.
- 🆕 **`handlers/configuracoes_ia.php`** (rota `/configuracoes-ia`, menu "Configurações IA", só admin) — quadros por comando (`.ia-cell`), filtrados pelo modelo do equipamento selecionado; cada parâmetro mostra a **máscara/formato** como tag de auxílio (o `format` do catálogo) e o padrão de fábrica; botões **Ler agora** (quando o comando tem forma de consulta) e **Aplicar**; envio via `/sendcommand` (proNo 128) + acompanhamento em `/commandstatus`, mesmo contrato de `handlers/comandos.php` — sem endpoint de despacho novo.
- 🆕 **`device_ia_config_state`** (`mysql/migration_v4.13.0.sql`) — último valor lido/aplicado por câmera de cada comando do catálogo novo, no formato certo (chave de texto + vários parâmetros nomeados por comando), sem mexer em `device_param_catalog`/`device_params` (formato JT/T, chave numérica — incompatível, e continuam paradas, não apagadas). Gravado em `includes/ia_config_state.php` (`ia_config_capture()`), chamado tanto do caminho síncrono (`handlers/sendcommand.php`, device online) quanto do assíncrono/offline (`handlers/pushinstructresponse.php`) — mesmo padrão de `upsert_device_params()`. `ia_config_match_key()` casa o comando enviado contra o catálogo por FORMA (tokens literais iguais, `P<n>` casa qualquer valor), não por nome.

### Changed
- 🔴 **45 comandos saíram de `includes/command_catalog.php`** — `EVENTSET`/`EVENTALERT` de eventos ADAS/DMS, `DMSSP`, `DMSSW,P1,P2#`, `DMSVSP`, `ADAS,CALIBRATION`, `DMS_SWITCH`, `DMS_VOICE_CUSTOM`, `DMS_ALERT_CUSTOM`, `DMS_VIRTUAL_SPEED`, `DMS_CONTINUITY` — de 238 para 193 entradas. `/comandos` passa a oferecer só configuração básica (APN, ACC, STATUS, VERSION, CHECK, SERVER, REBOOT, UPDATE, FILELIST…). Cada comando mora em exatamente uma tela.
- **Parametrização JT/T pausada, não apagada.** `handlers/parametros.php`, `handlers/config_parametros.php`, `handlers/rel_parametros.php` e a aba `parametros` de `handlers/ativo_detalhe.php` ganham um aviso explicando a pausa; nada de código, rota ou tabela foi removido — é reversível quando o fabricante corrigir o firmware. O bloqueio de verdade é num ponto único: `handlers/sendcommand.php` recusa `proNo` 33027/33028/33030 com HTTP 409, então nenhuma das quatro telas (nem um link antigo) chega a mandar o comando de verdade, mesmo que o aviso na tela seja perdido.

### Verificação
- `php -l` limpo em todos os arquivos novos/alterados.
- `tests/helpers/command_response.test.php`: 115/115 — a contagem do cabeçalho de `command_catalog.php` (193/143/16) é conferida dinamicamente contra o array, não hardcoded.
- Checagem estrutural do catálogo novo (script ad-hoc): todo campo obrigatório presente, e o número de placeholders `P<n>` no template bate exatamente com `count(params)` em todas as 58 entradas — 0 problemas.
- `ia_config_match_key()` testado contra 7 casos (aplicação preenchida, consulta nua, `STATUS#` não casando com nada) — todos batem.

## [Unreleased] — 4.12.11

**Pedido do dono do produto: o mapa do `/painel` ("Mapa de Posições Recentes") deve mostrar os pontos individuais de posição, igual ao mapa do `/` (Resumo), além da camada de calor.**

### Changed
- **`dashboard_render_heatmap()` (`includes/dashboard_widgets.php`) só desenhava a camada de calor (`L.heatLayer`).** `handlers/resumo.php`, de onde este widget foi copiado (v4.12.6 já tinha corrigido a query dele), também desenha um `L.circleMarker` por posição — ponto azul pequeno com popup (placa + velocidade) — e essa parte não tinha sido replicada. Adicionado o mesmo `circleMarker` dentro do `forEach` que já existia, com o mesmo estilo (`radius:3, color:#0052ff, fillOpacity:0.25`) e o mesmo popup.

### Verificação
- `php -l` limpo em `includes/dashboard_widgets.php`.

## [Unreleased] — 4.12.10

**Correção pedida pelo dono do produto: o contador On/Off ao lado do sino de notificações está incorreto.**

### Fixed
- 🔴 **`handlers/camerasdata.php` lia `device_statistics.is_online` — coluna que só é gravada como 1 e NUNCA volta a 0.** As stored procedures de alarme/gps/heartbeat/evento (`mysql/jimi_tracker.sql`) fazem `is_online = 1` em todo `ON DUPLICATE KEY UPDATE`; nenhum ponto do sistema jamais grava 0 nela. Resultado: uma câmera que comunicou uma vez fica "Online" PARA SEMPRE nessa coluna, mesmo dias depois de calada — o contador do header (`fleet-on`/`fleet-off`, alimentado por este endpoint) inflava o "On" e nunca contava ninguém como "Off" enquanto o dispositivo já tivesse conectado alguma vez. Medido em produção: câmera de teste sem comunicar há 17.196 min (~12 dias) ainda com `is_online = 1`. Todo o resto do sistema já evitava essa coluna e calculava online por `TIMESTAMPDIFF(MINUTE, last_communication, NOW()) <= 5` (`equipamentos.php`, `dashboard_widgets.php`) ou por classificação ao vivo (`rastreamento.php`, `video_aovivo.php`) — só `camerasdata.php` lia a coluna estática. Corrigido substituindo `s.is_online` pela mesma expressão de 5 minutos usada em `equipamentos.php`, nas duas variantes da consulta (principal e fallback).
- `ativo_detalhe.php` e `ativos.php` também selecionam `s.is_online` da mesma coluna estática, mas o valor não é usado em nenhuma tela — não corrigido por não ter efeito visível; documentado aqui para não ser reintroduzido como bug ativo se alguém passar a renderizá-lo.

### Verificação
- `php -l` limpo em `handlers/camerasdata.php`.
- Query corrigida testada em produção: a câmera parada há 12 dias passa de `is_online=1` (coluna estática) para `is_online=0` (calculado); contagem do cliente de teste vai de 8 On/0 Off para 7 On/1 Off.

## [Unreleased] — 4.12.9

**Complemento da 4.12.8, a pedido do dono do produto: parar de abrir Rota/Replay do Deslocamento em nova janela.**

### Changed
- Removido `target="_blank"` dos três links ("Ver rota" no fechamento diário, "Ver rota" e "Replay" por viagem) em `rel_deslocamento.php`. Navegação passa a ser na mesma aba — o parâmetro `return` da 4.12.8 já resolve o "para onde volta", então não havia mais motivo para a aba extra. `rel_deslocamento_rota.php`/`rel_deslocamento_replay.php` não precisaram mudar: já não tinham `target="_blank"` no próprio link cruzado entre si.

### Verificação
- `php -l` limpo; conferido que nenhum dos três links mantém `target="_blank"`.

## [Unreleased] — 4.12.8

**Correção pedida pelo dono do produto: no Relatório de Deslocamento, "Voltar ao relatório" (a partir da tela de Rota/Replay, aberta em nova janela) caía no formulário vazio, sem o filtro que o operador tinha aplicado.**

### Fixed
- 🔴 **`report_back_button()` chamado com a URL base fixa, sem devolver o filtro.** `rel_deslocamento_rota.php` e `rel_deslocamento_replay.php` linkavam de volta para `/relatorios/deslocamento` sem query string nenhuma — modalidade, placa, período, página e ordenação eram todos perdidos, e o operador caía na tela "Selecione os filtros e clique em Gerar" mesmo tendo acabado de gerar uma grade. Corrigido com um parâmetro `return` (URL completa, já com toda a query string exceto `export`) que `rel_deslocamento.php` passa para os links "Ver rota" e "Replay"; as duas telas filhas usam esse valor no botão "Voltar ao relatório", validado por regex contra o próprio path (`^/relatorios/deslocamento(\?.*)?$`) para não virar redirecionamento aberto se alguém adulterar o parâmetro num link compartilhado.
- Varrida a base por `target="_blank"` apontando para rota interna própria (não Google Maps externo, que não tem esse problema): só `rel_deslocamento.php` tinha esse padrão — os demais "Ver Mapa" do sistema (`rel_alarmes`, `rel_status_frota`, `rel_ignicao`, `rel_velocidade`, `rel_posicoes`, `rel_geocercas`, `rel_desatualizados`) apontam direto para `google.com/maps`, sem botão de volta próprio.

### Verificação
- `php -l` limpo nos 3 arquivos.
- Simulado o round-trip da URL (`urlencode` → `$_GET['return']` → regex → href) via `php -r`: reconstrói exatamente `mode`, `imei`, `date_from`, `date_to`, `gerar`, `page` e `sort/order` originais.

## [Unreleased] — 4.12.7

**Correção pedida pelo dono do produto: o Relatório de Deslocamento listava câmeras inativas no filtro de placa. Verificado o resto do sistema pelo mesmo padrão.**

### Fixed
- 🔴 **Seis pontos com o mesmo defeito: seletor/relatório de câmera sem `is_active = 1`.** O padrão já tinha sido corrigido em `handlers/bi.php` ("o dropdown Ativo listava câmera desativada"), mas o mesmo `SELECT imei, device_name FROM devices WHERE customer_id = :cid ORDER BY device_name` — sem o filtro — estava copiado em mais cinco lugares:
  - `handlers/rel_deslocamento.php` — dropdown "Placa" do Relatório de Deslocamento (o relato original).
  - `handlers/rel_alarmes.php`, `handlers/rel_posicoes.php`, `handlers/relatorios.php`, `handlers/exportar.php` — mesmo dropdown "Placa"/"Device list" nos relatórios de Alarmes, Posições, na home de relatórios e no agendador de exportação.
  - `handlers/rel_desatualizados.php` — mais grave que os dropdowns: o `$where` compartilhado por TODAS as consultas da tela (contagem por faixa, grade completa, drill-down, os três exports) não tinha filtro nenhum de `is_active`. Câmera desativada não posiciona nunca mais, então ficava PARA SEMPRE na faixa "Nunca posicionados"/">30 dias" — ruído permanente num relatório que existe para apontar problema na frota ATIVA. Testado em produção: cliente com 13 câmeras cadastradas, 8 ativas — as 5 inativas inflavam as faixas antes da correção.
- Confirmado com dado real de produção (câmera `865478070649936`, desativada, com 21 viagens históricas): o dropdown de placa não a lista mais, e a query base do relatório de desatualizados a exclui.

### Verificação
- `php -l` limpo em todos os 6 arquivos.
- Queries corrigidas testadas diretamente em produção: dropdown do Deslocamento não lista mais a câmera inativa; base do Desatualizados cai de 13 para 8 dispositivos.

## [Unreleased] — 4.12.6

**Correção pedida pelo dono do produto: no `/painel`, o mapa de posições não mostrava rastro nenhum dos veículos, e a legenda do gráfico "Velocidade da Frota" saía monocromática.**

### Fixed
- 🔴 **`dashboard_render_heatmap()` (`includes/dashboard_widgets.php`) — a consulta SEMPRE falhava, e o `catch` silencioso escondia o erro.** `SELECT DISTINCT g.imei, g.latitude, g.longitude, g.speed, ... ORDER BY g.gps_time` sem `g.gps_time` no SELECT é rejeitado pelo MySQL (erro 3065: `ORDER BY` sobre coluna ausente do SELECT é incompatível com `DISTINCT`) — não depende de `sql_mode`, é regra fixa do MySQL para `DISTINCT`. O widget nunca teve um único ponto no mapa, em nenhum cliente, desde que foi criado (v4.10.3): a query sempre lançava exceção, o `catch (Throwable $e) {}` engolia, e `$rows` ficava `[]`. A mesma consulta em `handlers/resumo.php` (de onde este widget foi copiado) já inclui `g.gps_time` no SELECT — só a cópia para o painel perdeu a coluna. Corrigido replicando a coluna; testado em produção: 180 linhas retornadas onde antes dava erro 3065 silencioso.
- **`dashboard_render_speed_dist()` — legenda sem cor nenhuma, todos os `■` no cinza padrão do texto.** As barras do gráfico (`Parados/≤20/≤60/>60`) já usavam `var(--muted-soft)/--primary/--warning/--error`; os `<span>` da legenda logo abaixo, que deveriam repetir a mesma cor de cada faixa (como em `handlers/resumo.php`, a versão original de onde este widget foi copiado), saíram sem nenhum `style="color:...`" — os quatro apareciam idênticos, monocromáticos. Corrigido replicando a mesma cor de cada span da barra na etiqueta correspondente.

### Verificação
- `php -l` limpo em `includes/dashboard_widgets.php`.
- Query corrigida testada diretamente em produção (customer_id=1): 180 linhas, sem erro.

## [Unreleased] — 4.12.5

**Investigação pedida pelo dono do produto: veículo placa "Telecom" (câmera JC371/JT-T, IMEI 865478070654829) não subia vídeo dos eventos DMS/ADAS.**

### Fixed
- 🔴 **`handlers/pushalarm.php` gravava `alarm_label` no formato errado, e isso desligava o disparo automático de vídeo na frota JT/T inteira, não só nessa câmera.** O IoT Hub manda o `alarmLabel` como os 16 bytes separados por vírgula (`"30,36,35,...,05,00"`), não como string hex contígua de 32 caracteres — a doc oficial descreve o segundo formato, que não é o que chega. `queue_event_video_request()` (`includes/occurrence_engine.php`) valida o label com `ctype_xdigit()`, que falha por causa das vírgulas: toda ocorrência DMS/ADAS caía no ramo "alarme sem alarmLabel de anexo — solicitação não enviada" e o comando 37384 (Alarm Attachment Upload) nunca era emitido. Medido em produção: zero comandos `auto_video`/37384 desde que o proNo foi corrigido (os 3 registros históricos usam o proNo antigo 34818, já documentado como não funcional). Mesmo bug quebrava em segundo lugar `link_upload_by_alarm_label()` (`pushfileupload.php`), que compara `alarms.alarm_label` (com vírgulas) contra o label contínuo extraído do NOME do arquivo — nunca casava, então até o vídeo que chega pelo caminho de auto-upload da câmera só linkava pelo fallback impreciso de janela ±3min. Corrigido tirando as vírgulas no ÚNICO ponto de extração (`$alarmLabel = str_replace(',', '', ...)`), antes de o valor alimentar qualquer um dos dois consumidores.

### Verificação
- `php -l` limpo em `handlers/pushalarm.php`.
- Reproduzido em produção: alarmes reais do IMEI acima e de outro IMEI JT/T mostram o mesmo formato com vírgula; concatenar os 16 tokens produz hex válido de 32 chars, batendo com o formato que `pushfileupload.php` já extrai do nome do arquivo.

## [Unreleased] — 4.12.4

**Correção pedida pelo dono do produto: o balão do veículo em `/rastreamento` mostrava "Estado: Parado (ignição desligada)" ao lado de "Ignição: Ligada" e velocidade real (ex.: 65 km/h) — dado contraditório no mesmo balão.**

### Fixed
- 🔴 **`handlers/rastreamento.php` misturava duas fontes de estado com velocidades de atualização diferentes.** "Estado" vinha do segmento aberto em `device_state_segments` (regravado só a cada 15 min pelo cron `scripts/state_builder.php`); "Ignição" e "Vel", do mesmo balão, vinham de `device_statistics` (atualizado em tempo real, a cada push de GPS). Um veículo que ligou e saiu andando entre duas rodadas do cron ficava com o segmento ainda em `parado` enquanto os outros dois campos já mostravam a realidade — produzindo exatamente o sintoma reportado.
- **`includes/fleet_state.php`** ganha **`resolve_live_state()`**: classifica o estado pelo ÚLTIMO PONTO conhecido (`classify_point()` sobre `device_statistics.last_acc_status`/`last_speed`), não pelo segmento — os três campos do balão passam a vir sempre da mesma leitura, nunca divergem entre si. `resolve_current_state()` (baseada em segmento) segue em uso, de propósito, nos relatórios batch (`rel_paradas`, `rel_ociosidade`, `rel_status_frota`), que precisam do segmento para fechar duração/histórico — trocá-los pelo resolvedor novo sem redefinir o que "Tempo no estado" passaria a significar reintroduziria a mesma classe de contradição ali.

### Verificação
- Reproduzido o cenário exato do relato (segmento aberto em `parado`, último ponto com ignição ligada e 65 km/h): `resolve_current_state()` devolve `parado` (o defeito), `resolve_live_state()` devolve `movimento` (correto). Comportamento de `offline` (por silêncio de comunicação) conferido idêntico entre as duas funções. `php -l` limpo nos dois arquivos.

## [Unreleased] — 4.12.3

**Correção pedida pelo dono do produto: verificar se todos os widgets do painel widgetizado (`/painel`) funcionam corretamente, com a mesma metodologia usada no BI (dados fictícios simulados + prints publicados para revisão).**

### Fixed
- 🔴 **`dashboard_outdated_kpis()` (`includes/dashboard_widgets.php`) — único KPI dos quatro sem fallback ao vivo.** Os outros três (dispositivos, ocorrências, velocidade) já caem para uma consulta ao vivo quando `metrics_snapshots` está vazia; este lia só a snapshot e, sem o cron `scripts/metrics_rollup.php` já ter rodado (banco novo, ambiente de dev, ou cron simplesmente falho), o widget "Desatualizados" mostrava **0 sempre** — indistinguível de "frota inteira em dia". Corrigido replicando a mesma consulta do rollup como fallback ao vivo.
- 🔴 **`dashboard_render_reseller_view()` sem escopo de revendedor nenhum.** As três consultas do "ranking Top 3" partiam de `FROM customers c` sem filtro — qualquer revendedor via clientes de OUTROS revendedores no próprio painel gerencial. Corrigido com `reseller_scope_ids()` (mesmo mecanismo já usado em `/equipamentos`), distinguindo `null` (admin, sem restrição) de `[]` (revendedor sem cliente atribuído) — tratar os dois igual teria escondido o painel do admin.
- **Mesma função — eixo "Top 3 por ocorrências" ignorava o seletor de período.** Único widget do painel que não respeitava Hoje/7 dias/Mês (contava sempre desde o início dos tempos). Corrigido usando `dashboard_series_window($periodo)`, mesma janela que os widgets vizinhos já usam.

### Verificação
- Frota fictícia simulada para 2 clientes de teste (5 câmeras — uma inativa —, 4 veículos, 2 motoristas, 40 pontos de GPS, ~75 alarmes/48 ocorrências em 4 semanas) e uma sessão de revendedor temporária, cobrindo os 3 períodos do seletor, isolamento entre clientes e escopo de revendedor. Capturas de tela publicadas como Artifact temporário para revisão visual; nenhum dado real de cliente foi usado, e os dados fictícios foram removidos do banco ao final.

## [Unreleased] — 4.12.2

**Correção pedida pelo dono do produto: a tela de BI (`/bi`) estava listando câmeras inativas no filtro "Ativo".**

### Fixed
- 🔴 **`handlers/bi.php` — filtro "Ativo" sem `is_active = 1`.** A consulta que monta o `<select>` listava toda câmera do cliente, ativa ou não — uma câmera desligada aparecia ao lado das em uso, sem produzir dado nenhum ao ser escolhida (e confundindo quem via uma câmera "sumida" ainda na lista).
- 🔴 **Achado ao testar, mais sério que o pedido original: os 4 gráficos do BI nunca renderizavam sob `sql_mode=ONLY_FULL_GROUP_BY`** (o padrão do MySQL desde a 5.7). A consulta de "Top 10 Eventos" agrupava (`GROUP BY alarm_label`) pelo APELIDO de uma expressão `CASE` que lê colunas de duas tabelas em `LEFT JOIN` (`alarm_types` por dois caminhos possíveis) — o otimizador recusa isso como "não funcionalmente dependente" e a consulta inteira falhava, derrubando os OUTROS três gráficos junto (o `catch` era único para as quatro). Toda análise gerada mostrava "Não foi possível gerar os gráficos com estes filtros." Corrigido repetindo a expressão inteira no `GROUP BY` em vez do apelido — grupo pela expressão em si é sempre válido, independente das colunas de onde ela vem.

### Verificação
- 10 análises simuladas com dados fictícios (2 clientes, 6 câmeras — uma delas desativada de propósito —, 3 motoristas, 70 alarmes e 54 ocorrências em 45 dias), cobrindo filtro por cliente, por ativo, por motorista, por tipo de evento (simples e múltiplo), período curto, período sem dado nenhum, e visão consolidada sem filtro de cliente. Capturas de tela publicadas como Artifact temporário para revisão visual; nenhum dado real de cliente foi usado.

## [Unreleased] — 4.12.1

**Correção pedida pelo dono do produto: o cadastro de chip ainda deixava vincular câmera na direção errada.**

### Fixed
- 🔴 **`handlers/chips.php` ainda tinha um `<select>` de câmera no formulário do chip** — dava pra vincular dos dois lados (chip escolhendo câmera, e câmera escolhendo chip), quando a regra é só uma direção: o vínculo se propõe no cadastro da CÂMERA (`/equipamentos`, "Chip (SIM)"), nunca no do chip. Removido — o formulário do chip agora só MOSTRA a câmera vinculada (texto somente leitura, com link para editar em `/equipamentos`); não oferece mais escolha nenhuma. `handlers/chips.php` não chama mais `link_sim_card_to_device()` em nenhuma hipótese.
- 🔴 **Achado no caminho: trocar SÓ o chip de uma câmera (nenhum outro campo) não gravava nada, sem erro nenhum.** `handlers/equipamentos.php` decidia se estava "fora do escopo do cliente" pelo `rowCount()` do `UPDATE devices` — mas o MySQL conta 0 linhas afetadas quando nenhuma COLUNA do `SET` muda de valor, que é exatamente o caso de "só troquei o chip" (nenhum campo de `devices` na query muda). Com `rowCount() === 0`, o código pulava `link_sim_card_to_device()` inteiro e ainda mostrava "Equipamento atualizado" — o vínculo ficava intocado em silêncio, nos dois sentidos (vincular e desvincular). Corrigido: o escopo agora se confere com um `SELECT` dedicado, nunca com o efeito colateral do `UPDATE`.

## [Unreleased] — 4.12.0

**Fase 2 da correção pedida pelo dono do produto: fecha o requisito que a Fase 1 (v4.11.0) deixou em aberto — "quando uma câmera é reinstalada num novo veículo, o dono do carro só vê os dados do seu veículo".**

### Added
- **`resolve_installation_for_imei()`** (`includes/functions.php`) — ponto único que resolve o dono (cliente + veículo) de um IMEI no momento em que é chamado, usado só na INGESTÃO (nunca na leitura).
- **`gps_data`, `alarms`, `events`, `heartbeats`, `media_files`** ganham `customer_id`/`vehicle_id` (`mysql/migration_v4.12.0.sql`); `occurrences` ganha `vehicle_id` (já tinha `customer_id`, gravado como snapshot desde sempre — o padrão que esta versão generaliza). Cada webhook (`pushgps`, `pushalarm`, `pushevent`, `pushhb`, upload de mídia em `pushfileupload`/`pushftpfileupload`/`sendcommand`/`includes/media.php`) grava o dono do momento, não o atual.
- Backfill exato de todo o histórico existente: como a Fase 1 acabou de nascer, cada câmera tinha no máximo uma instalação — nenhuma trocou de veículo ainda.

### Changed
- ~20 pontos de leitura (relatórios, painel, dashboard, download/playback de vídeo, `/midia`) passam a escopar por cliente pelo valor GRAVADO na linha, não mais reconsultando o dono ATUAL da câmera via JOIN em `devices`.
- **`handlers/ativo_detalhe.php`**: as abas Trajetos/Alertas/Log/Vídeo passam a filtrar por `vehicle_id`, não por `imei` — a mesma câmera reinstalada noutro veículo não mistura mais o histórico dos dois. Como consequência, essas abas voltaram a funcionar mesmo com o veículo sem câmera instalada no momento (mostram o histórico que já é dele); só as abas de operação AO VIVO (Ao Vivo, Comandos, Configurações, Parâmetros) continuam exigindo câmera instalada.

### Fixed (achados durante a Fase 2, fora do escopo original)
- 🔴 **`handlers/rel_posicoes.php` não validava que o `?imei=` da URL pertencesse ao cliente da sessão** — bastava trocar o parâmetro para ver a posição de qualquer cliente. Fechado escopando pelo `customer_id` gravado no ponto.
- 🔴 **`handlers/trackdata.php` e `handlers/hbdata.php`** (endpoints AJAX do mapa ao vivo) validavam o IMEI contra o dono ATUAL da câmera, mas liam o histórico inteiro sem limite de período — um período em que a câmera esteve com outro cliente vazava. Fechado com o mesmo `customer_id` gravado.
- 🔴 **`handlers/midia.php`** (servidor de arquivo de vídeo) autorizava pelo dono ATUAL da câmera — mesma falha de período, agora corrigida pelo dono gravado no arquivo/alarme.

## [Unreleased] — 4.11.0

**Fase 1 da correção pedida pelo dono do produto: chip, câmera e veículo eram a mesma linha (`devices`) — sem estado, sem histórico, sem como reusar uma câmera em veículos diferentes ao longo do tempo.**

### Added
- **Tabela `vehicles`** (`mysql/migration_v4.11.0.sql`) — o veículo vira entidade própria (placa, tipo, cliente, status), independente de câmera. Existe sem câmera instalada.
- **Tabela `device_installations`** — histórico de qual câmera esteve em qual veículo, de quando a quando. Ponto único de escrita: `install_device_on_vehicle()` / `uninstall_device_from_vehicle()` (`includes/functions.php`), dentro de transação — garante no máximo uma instalação aberta por câmera e por veículo.
- **`/ativos/{id}`** (agora ID do veículo, não IMEI — ver Changed): ações "Instalar/Trocar Câmera" e "Desinstalar", card de histórico de instalações. Instalar só oferece câmeras livres com chip já vinculado — é a ordem do fluxo: chip → câmera → veículo, nunca ao contrário.
- **`/equipamentos`**: ganhou o seletor de chip (chips livres + ativos) que faltava nesta tela; "Cliente" vira somente leitura quando a câmera está instalada (deriva do veículo); desativar só é permitido sem instalação aberta, e libera o chip automaticamente ao desativar.
- **`/chips`**: não é mais possível desativar um chip vinculado a uma câmera — precisa desvincular primeiro.

### Changed
- **`/ativos`, `/ativos/novo`, `/ativos/{id}`** passam a ser telas de VEÍCULO (placa, tipo, cliente) — cadastro de câmera (IMEI, modelo, canais, chip) é só em `/equipamentos`. `/ativos/{id}` usa o id do veículo na URL (era o IMEI da câmera); links antigos por IMEI continuam funcionando via redirect de compatibilidade em `handlers/ativo_detalhe.php`.
- **`devices.customer_id`** passa a significar "quem tem a câmera hoje" — sincronizado automaticamente ao instalar/desinstalar de um veículo, e só editável livremente em `/equipamentos` enquanto a câmera está livre.
- **`devices.sim_card_id`** (FK de `migration_v4.0.0.sql`, nunca escrita por nenhum código) removida — o vínculo chip↔câmera sempre rodou por `sim_cards.imei` (já com `UNIQUE` desde a v4.10.4), e a coluna morta só confundia quem lesse o schema.

### Not included (Fase 2, escopo separado)
Relatórios e telemetria (`gps_data`, `alarms`, `events`, `media_files`, `heartbeats`) continuam escopados pelo dono ATUAL da câmera (`devices.customer_id`), não pelo período de instalação — essas tabelas não têm `customer_id` próprio. Transferir uma câmera de veículo/cliente ainda reatribui retroativamente o dono de todo o histórico de telemetria daquele IMEI. `device_installations` (criada nesta versão) é o que a Fase 2 vai consumir para corrigir isso, no mesmo padrão que `occurrences` já usa (`customer_id` gravado como snapshot na criação).

## [Unreleased] — 4.10.0

**Item 5 do `docs/PLANO_IMPLEMENTACAO_v4.10.md`: ícone do veículo por tipo, colorido por estado, no mapa de `/rastreamento` — primeiro item entregue da rodada "YUV Parity — frota".**

### Added
- **`devices.vehicle_type`** — tipo de veículo (`carro`/`van`/`caminhao`/`onibus`/`moto`/`trator`), opcional, cadastrado em `/ativos/novo` (seletor visual com ícone) e editável na grade de `/ativos`. `NULL` (padrão de todo device existente, sem backfill) mantém o comportamento anterior: pin sem ícone, só o círculo colorido.
- **`includes/vehicle_icons.php`** — catálogo de ícones [Tabler Icons](https://tabler.io/icons) (MIT, sem CDN nova — só os `<path>` embutidos como string PHP, mesmo padrão de `nav_icon()`). O SVG é sempre de UMA cor, decidida pelo chamador; a variação por estado do veículo é o fundo do pin, não o ícone.
- **`/rastreamento`**: marcador do mapa passa de `L.circleMarker` (2 cores) para `L.divIcon` — círculo colorido por estado (`movimento`/`ocioso`/`parado`/`offline`/**`excesso`**, novo) com o ícone branco do tipo de veículo centrado dentro. Estado calculado com `includes/fleet_state.php` (`resolve_current_state()`), a mesma fonte de `/relatorios/status-frota` — o limiar de offline que era 5 min ad-hoc nesta tela passa a ser os 30 min (`OFFLINE_GAP_SECONDS`) usados no resto do produto. Legenda nova no canto do mapa.

### Changed
- `handlers/ativos.php` e `handlers/ativos_novo.php` ganham a coluna/seletor "Veículo".

## [Unreleased] — 4.10.5

**Correção pedida pelo dono do produto: notificação lida deve sair da lista do sino ao final do dia.**

### Fixed
- **`handlers/notificacoesdata.php`**: a consulta do sino não tinha corte nenhum — uma notificação lida ficava na lista até ser empurrada por 20 mais novas, às vezes por dias. Agora uma notificação **lida** só aparece na lista enquanto `read_at` estiver dentro do dia BRT corrente; na virada da meia-noite ela some sozinha no próximo polling (30s) ou na próxima vez que o sino for aberto — sem precisar de cron. **Não lidas continuam sempre visíveis**, independente da data. O filtro é só na consulta do sino: a linha continua no banco, dentro da janela de 30/90 dias que `includes/auth.php::auth_cleanup()` já mantinha antes desta versão — nada mudou na retenção de dados, só no que aparece na lista.

### Verificação
- Testado com três notificações semeadas (não lida / lida há 2 dias / lida agora): a lida há 2 dias ficou de fora tanto na resposta JSON de `/notificacoesdata` quanto no dropdown real do sino no navegador; as outras duas apareceram normalmente; o contador de não lidas não foi afetado; marcar uma notificação como lida agora e reconsultar a lista confirmou que ela continua visível no mesmo dia.

## [Unreleased] — 4.10.4

**Falha de lógica reportada pelo dono do produto: chip é 1:1 com equipamento, mas nada garantia isso — e o cadastro pedia o vínculo na direção errada.**

### Fixed
- 🔴 **`sim_cards.imei` só tinha índice comum, não `UNIQUE`** — dois chips podiam apontar para o mesmo equipamento sem erro nenhum, e o `<select>` de equipamento em `/chips` deixava escolher qualquer um, mesmo já vinculado a outro chip. Adicionada `UNIQUE KEY uk_sim_imei` (`mysql/migration_v4.10.4.sql`) como trava de banco — a migração se recusa a criá-la se já existir duplicata (mostra as linhas em conflito em vez de decidir sozinha qual apagar).
- 🔴 **O cadastro de equipamento (`/ativos/novo`, `/ativos`) não tinha campo de chip nenhum** — o único jeito de vincular era pela tela de Chips, escolhendo o equipamento; o caminho operacional certo é o inverso (o chip já existe no estoque; ao cadastrar a câmera, escolhe-se um chip **livre** para ela). As duas telas ganharam o campo **"Chip (SIM)"**, listando só chips sem equipamento vinculado (mais o próprio chip já vinculado, ao editar). `/chips` continua existindo para gestão de estoque, mas agora com a mesma restrição — filtra o `<select>` de equipamento para excluir os que já têm outro chip.

### Added
- **`link_sim_card_to_device()`** (`includes/functions.php`) — ponto único de escrita do vínculo chip↔equipamento, usado pelas três telas. Desvincula o chip atual antes de tentar o novo (troca explícita nunca deixa o equipamento preso a um chip que o usuário decidiu trocar) e é resiliente a corrida entre dois cadastros simultâneos disputando o mesmo chip (`UPDATE ... WHERE imei IS NULL` — 0 linhas afetadas vira aviso ao usuário, não erro silencioso).

### Verificação
- Testado no navegador (extensão do Chrome conectada nesta sessão): criado um equipamento escolhendo um chip livre → o chip some da lista de livres na MESMA resposta (bug de ordenação pego e corrigido: a query de chips livres rodava ANTES do vínculo ser gravado); editado o mesmo equipamento trocando de chip → o antigo volta a aparecer como livre, o novo passa a mostrar o IMEI vinculado; tentativa direta via SQL de forçar duplicata rejeitada pelo banco (`Duplicate entry ... for key 'sim_cards.uk_sim_imei'`); `/chips` confirmado excluindo o equipamento já vinculado do próprio `<select>`.

## [Unreleased] — 4.10.3

**Item 7 do `docs/PLANO_IMPLEMENTACAO_v4.10.md`: painel widgetizado por usuário — quarto e último item entregue da rodada "YUV Parity — frota" (itens 5, 3, 6, 7).**

### Added
- **`/painel`** (tela nova, item próprio na sidebar, ao lado de "Resumo") —
  dashboard com 13 widgets reaproveitando as mesmas consultas de
  `handlers/resumo.php` (`includes/dashboard_widgets.php`), em layout
  configurável por usuário: mostrar/ocultar + reordenar (↑/↓), sem biblioteca
  de drag-and-drop nova. `handlers/resumo.php` **não foi alterado em nenhuma
  linha** — `/` continua servindo os mesmos KPIs fixos de sempre.
- **`dashboard_layouts`** (migração) — layout por usuário (`user_id`) com
  fallback a um **padrão global único do sistema** (`user_id IS NULL`, não
  por cliente — decisão do plano) e, na ausência de qualquer linha, ao
  catálogo hardcoded de 9 widgets.
- **`/dashboarddata`** (AJAX) — `GET` devolve o layout efetivo; `POST` grava
  o layout do PRÓPRIO usuário autenticado, validado contra o catálogo
  (`dashboard_sanitize_layout()`: só chaves conhecidas, sem duplicata) —
  mesmo ponto de validação usado tanto na leitura quanto na escrita.

### Verificação
- Migração aplicada duas vezes (idempotente). Ciclo completo no MySQL local:
  `/painel` com o catálogo padrão (9 widgets, todos renderizando sem erro),
  edição salvando um layout de 3 widgets via `/dashboarddata` e o `/painel`
  imediatamente refletindo só esses três, fallback ao padrão global
  confirmado com um segundo usuário sem layout próprio (exatamente os 2
  widgets do global, nem um a mais), sanitização confirmada contra chaves
  inválidas/duplicadas (`DROP TABLE users`, `<script>…</script>` descartados
  sem erro), gate de `reseller_view` testado nos dois sentidos (aparece só
  para `user_type='revendedor'`), e **`/` conferido byte a byte quanto à
  ausência de qualquer marca do painel novo** (regressão zero em
  `resumo.php`). CSRF do `POST /dashboarddata` testado e confirmado — uma
  anomalia inicial (POST sem token aceito) só reproduziu quando duas
  requisições share a mesma conexão keep-alive dentro do MESMO script; em
  chamadas isoladas (inclusive em dois processos separados) a rejeição 403
  foi consistente nas três vezes — artefato do servidor de desenvolvimento
  embutido do PHP (`php -S`, single-thread), não do código.

## [Unreleased] — 4.10.2

**Item 6 do `docs/PLANO_IMPLEMENTACAO_v4.10.md`: replay do deslocamento — terceiro item entregue da rodada "YUV Parity — frota".**

### Added
- **`/relatorios/deslocamento/replay?trip_id=`** (tela nova, sem migração) —
  reproduz uma viagem já registrada: marcador se move pelo percurso
  (interpolação linear entre os pontos de GPS amostrados, `requestAnimationFrame`),
  com play/pause, velocidade 0.5×/1×/2×/4×, e leitura de hora/velocidade/
  distância percorrida atualizada quadro a quadro. Link "Replay" ao lado de
  "Ver rota" na grade de `/relatorios/deslocamento` (só na modalidade por
  viagem — o fechamento diário pode agregar viagens com buracos entre elas,
  e reproduzir um buraco não faz sentido).
- **Linha do tempo em SVG** com a mesma mecânica de `handlers/video_playback.php`
  (roda do mouse para zoom ancorado no cursor, arraste para pan, `pointerdown`/
  `pointermove`/`setPointerCapture`, guarda contra confundir arraste com
  clique) adaptada para uma única faixa com sparkline de velocidade no lugar
  dos blocos de vídeo por canal — clique na linha faz *seek*.
- **Marcador reaproveita o ícone do veículo do item 5** (`includes/vehicle_icons.php`):
  se o ativo da viagem tiver `vehicle_type` cadastrado, o pin do replay mostra
  o mesmo ícone Tabler do mapa de `/rastreamento`; sem tipo cadastrado, cai no
  círculo azul sem ícone (mesmo comportamento de fallback).

### Verificação
- Migração N/A (tela sem schema novo).
- Verificado com viagem real do MySQL local (`trips.id=17`, 5 pontos de GPS):
  HTTP sem `Warning`/`Fatal error`; a lógica JS (interpolação, zoom/pan,
  conversão de fuso do playhead) foi extraída do HTML renderizado e executada
  em Node com DOM/Leaflet simulados — interpolação no meio de um segmento
  bateu exatamente com a matemática esperada, round-trip pixel↔tempo exato,
  clamp de zoom mínimo (10s) respeitado, e o `readout` de hora bateu com o
  horário BRT esperado ao segundo. `trip_id` inexistente devolve erro
  amigável, não 500. **Não verificado**: renderização visual real no
  navegador (mesma limitação dos itens 5 e 3 nesta sessão).

## [Unreleased] — 4.10.1

**Item 3 do `docs/PLANO_IMPLEMENTACAO_v4.10.md`: manutenção preventiva por métrica + lembrete de vencimento de documento do motorista — segundo item entregue da rodada "YUV Parity — frota".**

### Added
- **`maintenance_reminders`** — lembrete de manutenção por `odometro`/`horas_ignicao`/`horimetro`/`data`, opcionalmente vinculado a um ativo e/ou motorista. Tela nova **`/manutencoes`** (item dedicado na sidebar, `$navPrincipal`), com aba **Manutenção** (CRUD + "Registrar concluído") e aba **Documentos** (liga/desliga o lembrete de CNH/toxicológico por motorista — `drivers.remind_cnh`/`remind_tox`, novos).
- **`scripts/maintenance_worker.php`** (cron diário, 06:10) — notifica (`kind='lembrete'`) quando um item entra em `próximo` (≤200 km / ≤10h / ≤7 dias do vencimento) ou `vencido`. Dedupe **por dia** via `last_notified_at`/`cnh_notified_at`/`tox_notified_at`: `notify()` só dedupe o e-mail numa janela curta, nunca o sino — sem essas colunas o worker recriaria a notificação todo dia em que o item continuasse vencido.
- **`devices.engine_hours`** — horímetro reportado pelo equipamento; `pushgps.php`/`pushhb.php` tentam capturá-lo (`horimetro`/`engineHours`/`engine_hours`/`hourmeter`, nome ainda **não confirmado** contra device real) e só gravam quando > 0.

### Fixed
- 🔴 **O KPI "Distância Total" de `/ativos/{imei}` sempre mostrou 0.** A query referenciava `device_statistics.total_distance` — coluna que não existe (o nome real é `total_distance_km`) — e caía sempre no `catch`, que hardcodeava `NULL`. Corrigido; a aba Visão Geral ganhou também **Odômetro Atual** (`latest_odometer()`, `includes/maintenance.php`).
- 🔴 **Bug pego na verificação manual antes mesmo de chegar a produção**: o cálculo de vencimento por odômetro/horímetro derivava o "baseline" do valor **atual** quando `last_done_km`/`last_done_hours` estava vazio — o que faz o vencimento "perseguir" o odômetro e o item **nunca vencer**. Corrigido: o baseline só vem de `last_done_km`/`last_done_hours`, gravado uma vez na criação do lembrete (assume "serviço feito agora") e só reescrito por "Registrar concluído".

## [Unreleased] — 4.9.40

**A pergunta era se a planilha do JC371 tinha um comando de parar o playback. Não tem — e a busca achou 18 sintaxes ausentes do catálogo, uma delas descartada por engano há três versões.**

### Added
- 🔑 **`CHECK#` catalogado como consulta UNIVERSAL — a mais completa do proNo 128.** É a **segunda exceção manual** de `universal` (a primeira é o `UPDATE`, v4.9.32), e pelo mesmo motivo: a derivação automática mede a FONTE, e só a planilha do JC371 documenta o comando. Medido em produção em 20/08/2026, e os quatro equipamentos alcançáveis responderam:

  | Equipamento | Modelo | Trecho da resposta |
  |---|---|---|
  | `864993060429173` | JC400AD | `VERSION:KMC28_..._V1.8.0.9_250807.1920; UPLOAD:http://…:23010/upload; SERVER:0,…,21100; TIMEZONE:-3:00` |
  | `864993060392306` | JC400AD | `VERSION:KMC28_..._V1.8.1.3_250925.1127` |
  | `865478070003241` | JC371 | `VERSION:C371_..._V1.9.0.2b_260528.0543;SERVER:0,…,21122;BCD:0` |
  | `869058070151343` | JC182 | `IMEI:…;VERSION:C182_..._V1.2.5.2_260422.0924;[AR9150]:…` |

  Sendo LEITURA, o custo de errar o modelo é uma recusa, não um estrago — é o inverso do `SERVER`, onde o endereço errado tira a câmera da plataforma. ⚠️ **No JC181 ele é caro**: na bateria da v4.9.25 estourou os 30 s do hub e derrubou a sessão JIMI daquele equipamento (nove respostas boas antes, zero depois). Não se varre a frota com ele em rajada.

- 🔴 **Correção de um descarte da v4.9.25: `CHECK` e `LOG` não eram "tokens soltos".** Aquela varredura leu a wiki, onde os dois aparecem como palavra solta em tabelas de parâmetros, e os classificou como ruído. A planilha da fabricante os documenta (A003, A025) e o equipamento responde. É o **espelho** do erro do `MILE#` registrado na §4 do mesmo documento: lá batizei por coincidência, aqui descartei por ausência. **Ausência numa fonte não é ausência no protocolo.**

- **Mais 17 sintaxes da planilha `JC 371 Command List V1.0.1`** — o catálogo foi de 220 para 238 entradas.
  - **Seis nomes novos**: `CHECKVIDEO#` (servidor, BCD e DMS/ADAS da câmera — ⚠️ **não vale na linha JC400**, relatado do campo e ausente da planilha `JC400 & JC261`), `STATUSVIDEO#`, `SENSORSET`, `SHUTDOWNTIME`, `VIDEORSL_SUB`, `VIDETIMEZONE`.
  - ⚠️ **Onze variantes de ARIDADE**, o buraco que comparar só o nome-base nunca mostra — a mesma classe que escondeu a forma nua do `FILELIST` por meses (v4.9.27): `KEYFUN,A,B` · `APN,A,B,C,D` · `SERVER,A,B,C,D,E,F` · `BCD,A,B` · `LOG,ALL` · `RECORDAUDIO,A,B` · `RECORDAUDIO_SUB,A,B` · `RATATION,A,B,C,D` · `PICTIMER,A,B,C,D` · `TIMER,A` · `ANGLEREP,A`. Mandar a aridade errada é **aceito e mal interpretado, sem erro nenhum**; o que protege é a trava por modelo, e por isso todas nascem presas ao JC371 mesmo quando o nome é universal em outra sintaxe.
  - 🔑 **`BCD,A,B#` revela o que a entrada de um campo não sabia dizer**: o segundo campo é a **versão do protocolo JT/T 808** — `0` para 2011, `1` para 2019. Muda o dialeto que a câmera fala com o IoT Hub inteiro. A entrada antiga, vinda da wiki, descrevia "JT/T 808-2013", ano que não existe nesta planilha.
  - 🔑 **`STATUSVIDEO#` diz o que nenhuma outra resposta diz**: `On video` × `Camera insertion` — quais canais estão **gravando** contra quais câmeras estão **conectadas**. Quando a barra do playback vier vazia num canal, é a pergunta que separa "não gravou" de "não tem câmera".

### Fixed
- 🔴 **A guarda de "placeholder por preencher" da tela de comandos casava por FORMATO, e recusava valor legítimo de uma letra.** Ela testava `/(,P\d+|,[A-Z])(,|#)/` sobre o texto montado — "parece placeholder" —, e isso é indistinguível de um **valor** de uma letra só. `VIDEOTIMEZONE,W,3,0#` (`W` = oeste de GMT, o exemplo oficial do próprio comando na planilha JC371 A006) era recusado **pela própria tela**, com a mensagem "ainda há placeholders". O comando novo `VIDETIMEZONE,A,B,C#` cairia na mesma armadilha assim que alguém preenchesse o primeiro campo com o valor documentado. Agora a pergunta é sobre os **campos** (`faltaParametro()`): o que está em branco está em branco, e um `W` digitado é um valor — sem ambiguidade possível.
- 🔴 **Sete comandos podiam mandar o PLACEHOLDER CRU para o equipamento — e um deles era impossível de enviar.** A combinação `template: true` com `params: []` não desenhava campo nenhum na tela, e o preview saía com a letra crua (`TIMER,A,B#`). A guarda por formato barrava seis deles no clique (tarde, mas barrava) e barrava **errado** o sétimo, o `VIDEOTIMEZONE,W,3,0#`, que é comando pronto e não molde. Corrigidos os sete: `EVENTALERT,ANWSB` e `EVENTALERT,AWSB` ganharam os três campos que a planilha JC371 documenta para a família inteira (D002–D014: alerta na plataforma, intervalo de reporte, intervalo do aviso de voz), `WIFIAP,A#` ganhou o `ON/OFF` de A015, e `VIDEOTIMEZONE,W,3,0#` virou `template: false`. ⚠️ `TIMER,A,B#`, `TIMER1,A,B#` e `DMS_VIRTUAL_SPEED,A#` **só existem na wiki, que não descreve os campos**: as posições foram declaradas (a sintaxe já as diz) e a descrição ficou **em branco de propósito** — inventar o significado seria o palpite que este projeto não aceita.
- **O botão de envio desabilita com campo em branco**, em vez de deixar clicar e recusar depois. O teste que se chamava "parâmetro em branco bloqueia o envio" só conferia que o placeholder continuava no preview — o nome prometia mais do que a asserção cobrava.
- 🔴 **A leitura de pares descartava, em silêncio, chave que não fosse uma palavra.** `command_response_kv()` exigia `[A-Za-z][A-Za-z0-9 _/.-]{1,28}`, e três formatos reais caíam fora — a linha simplesmente não aparecia na tela, sem nada indicando por quê: `EVENTSET,AVD:OFF`, `EVENTSET,AEPLD:ON,115,120,10` e `WAKEUP,RTC:0,240` (JC371, chave com **vírgula**) e `[AR9150]:C182_…` (JC182, chave com **colchetes**). Duas coisas continuam de fora de propósito: a chave **nunca atravessa um `:`** — é o que mantém `RSERVICE:rtmp://ip:1936/live` com o rótulo certo apesar dos três dois-pontos — e o bloco `bootcase[…]` do JC182, que tem quebra de linha no meio e é despejo, não par. O preço de aceitar vírgula na chave seria uma frase comum (`Device busy, previous command: not returned`) virar par; uma regra de uma linha o impede.

### Changed
- **A leitura de firmware pega carona no `CHECK#`** (`firmware_is_version_command()` → `firmware_comando_le_versao()`). Só foi possível porque as duas leituras devolvem a **MESMA string**, conferida byte a byte nos dois modelos alcançáveis:

  ```
  JC400AD  VERSION# → KMC28_0_0_STD_JM_C261_V1.8.0.9_250807.1920
           CHECK#   → VERSION:KMC28_0_0_STD_JM_C261_V1.8.0.9_250807.1920
  JC371    VERSION# → C371_0_0_STD_JM_JC371_V1.9.0.2b_260528.0543
           CHECK#   → VERSION:C371_0_0_STD_JM_JC371_V1.9.0.2b_260528.0543
  ```

  A conferência não é preciosismo: `/firmwares` compara versão por **igualdade** (não há regra publicada que ordene `V1.8.0.9_250807` contra `V4.3.2`), e duas grafias do mesmo firmware fariam a tela acusar "diferente da referência" conforme o comando usado por último. Importa porque `devices.firmware_version` só é preenchido quando alguém **pergunta** — estava NULL na 400AD de produção, online, no dia da medição.
- **A contagem por categoria do cabeçalho do catálogo passou a ser conferida por teste**, como já era a de entradas/comandos desde a v4.9.32, mais uma guarda de que nenhuma categoria fica fora do mapa de rótulos de `handlers/comandos.php` (categoria desconhecida cai no rótulo cru na tela).

### Não encontrado
- **Não há comando de parar o playback na planilha do JC371.** Ela é toda de configuração e consulta; o controle de stream do JC371 vive no binário do JT/T 1078 (`37378`), cujos nomes de campo continuam sem verificação em equipamento real.

## [Unreleased] — 4.9.39

**Duas falhas relatadas do campo nas câmeras JIMI, e a doc oficial (docs.jimicloud.com §1.3.5–1.3.7) resolveu as duas.** Uma era minha suposição não medida; a outra, uma assimetria com o JT/T.

### Fixed
- 🔴 **"Ver na câmera" nunca funcionaria: a URL do stream estava errada — nas DUAS famílias, e de formas diferentes.** Eu havia reaproveitado a URL do vídeo AO VIVO. Medido em 21/08/2026 perguntando ao próprio media server (ZLMediaKit, `/index/api/getMediaList`) com o stream no ar:

  | | comando | app | stream | URL do playback |
  |---|---|---|---|---|
  | JIMI | `REPLAYLIST` | `live` | `<imei>` | `/live/<imei>.flv` |
  | JT/T | `37377` | `<canal>` | `<imei>.history` | `/<canal>/<imei>.history.flv` |

  O ao vivo publica em `live/<canal-base-0>/<imei>` (JIMI) e `<canal>/<imei>` (JT/T), medidos em 18/08. Reaproveitar aquela URL faz o comando ser aceito, a câmera publicar, e o player buscar um endereço vazio. A doc oficial (§1.3.5) descreve só o lado JIMI, e nele bate com a medição; **o sufixo `.history` do JT/T não está documentado em lugar nenhum** — só apareceu perguntando ao servidor.
- **O prazo do player subiu de 20 s para 30 s.** Medido: a JIMI leva ~12 s entre aceitar o `REPLAYLIST` e publicar; a JT/T, ~6 s. Prazo apertado transforma câmera lenta em "falhou".
- 🔴 **O pedido de upload não aparecia em Downloads enquanto o arquivo não chegava.** O JT/T ganhava linha `solicitado` ao despachar o 37382; o JIMI não ganhava nada ao despachar o `HVIDEO`. Entre o clique e a chegada (~15 s medidos) a tela não tinha o que mostrar — relatado como "não listam, seja como pendente ou pronto". Agora o `HVIDEO`/`EVIDEO` grava a mesma linha de espera, e quando o arquivo chega ela é **promovida** em vez de nascer uma segunda: um pedido, uma linha, de "aguardando câmera" a "pronto".
  - ⚠️ **O casamento é por instante E por canal.** O DMS dispara várias vezes no mesmo minuto: um anexo de alarme comum que roubasse o pedido faria a fila dizer "pronto" apontando para o vídeo ERRADO, e o pedido de verdade ficaria pendente para sempre. A decisão virou função pura (`media_pedido_correspondente()`) com 11 checagens sem banco, e foi verificada nos três cenários contra o banco real.
- 🔴 **A coluna "Canal" da tela de Downloads estava vazia em toda linha de anexo de alarme** — `media_register_file()` nunca gravava `channel`. O dado sempre esteve no nome (`_F_` = frontal, `_I_` = interna). Passou a ser gravado, e a tela também o deriva do nome para as linhas antigas, que continuam com NULL.
- **`REPLAYLIST,OFF` ao fechar o player** (§1.3.6). Sem ele a câmera segue empurrando vídeo até o timeout de 20 s do media server, gastando franquia do SIM por um stream que ninguém assiste — o mesmo cuidado que o ao vivo já tinha com o `RTMP,OFF`.

### Changed
- **Downloads: as colunas dizem o que se procura.** Saíram **Tipo** (sempre "video") e **Tamanho**; entrou **Início do vídeo**. A tela dizia apenas QUANDO o arquivo foi pedido e nunca QUANDO é a gravação — e os dois podem estar a dias de distância ao extrair algo antigo. O instante sai do carimbo do NOME, com `event_time` como reserva. O export ganhou a mesma coluna.

### Notes
- **A resposta do `/filelist` passou a ser `{"code":0,"ok":true}`**, como a §1.3.5 especifica; devolvíamos `message:"success"`. Funcionava — este firmware não confere. Alinhado mesmo assim: contar com a tolerância do device é apostar que o próximo firmware também será tolerante, e este projeto já perdeu essa aposta.
- ⚠️ **`REPLAYLIST` aceita até OITO nomes** separados por vírgula (§1.3.5), o que permitiria emendar trechos numa reprodução só. Mandamos um — o bloco pedido.
- ⚠️ **O JT/T continua SEM comando de parada do playback.** O par do `37377` é o `37378` (0x9202), e os nomes de campo que o hub da Jimi espera não constam de nenhuma fonte conferida — inventá-los produz comando aceito pelo gateway e ignorado pelo device. Uma tentativa de medição em 21/08 ficou inconclusiva (o device não respondeu; virou comando offline). O impacto é pequeno e conhecido: **o playback termina sozinho** ao fim da janela pedida — verificado no media server, os dois streams do teste sumiram sem ninguém mandar parar. O JIMI tem parada (`REPLAYLIST,OFF`, respondeu `OK!`).

## [Unreleased] — 4.9.38

**As barras de filtro ganharam o padrão visual que nunca tiveram.**

### Fixed
- 🔴 **Listas suspensas com a borda do NAVEGADOR, não a do sistema.** O design system só vestia campo dentro de `.form-group` — formulário de cadastro. As barras de filtro das telas de listagem usavam `<select>` cru com estilo inline que definia padding e tamanho de fonte e **esquecia a borda**: cada campo herdava a borda padrão do navegador (cinza, raio próprio, diferente entre Chrome e Firefox) ao lado de um `input[type=date]` que trazia o hairline correto. Agora existe `.filtro-campo` (`web/layout_base.php`), um lugar só para select, data e busca de barra de filtro.
- **`/comandos`, histórico de envios**: os cinco campos passaram a usar a classe, com rótulo em cima (`.filtro-rotulo`), e o par De/Até deixou de quebrar linha no meio — dois campos que só fazem sentido lidos como intervalo.

### Changed
- **O equipamento vira lista suspensa**, como os outros filtros. Eram 15 botões-chip ocupando três linhas, oferecendo multisseleção que ninguém pediu, num controle que não se parecia com nenhum outro filtro da tela. ⚠️ **O parâmetro da URL não mudou**: link antigo com vários IMEIs separados por vírgula continua filtrando; o seletor reflete o caso de um equipamento, que é o uso real.
- 🔴 **A multisseleção também virou lista suspensa** — `web/components/select_multi.php`, um dropdown que abre com caixas de seleção, busca (a partir de 8 opções), *marcar todos* / *limpar*, e resumo no botão fechado ("Todos" · o nome do único · "N selecionados"). Substitui `chips_multiselect.php` em `/relatorios/alarmes` e `/bi`, onde o filtro é de **tipos de alarme/evento** e chega a 33 opções — como chips, o controle mudava de altura conforme o cadastro do cliente e precisava de um "+N" para caber. ⚠️ **Mantém o contrato de saída** (hidden com valores por vírgula, mesmo parâmetro GET): nenhuma consulta, link ou export precisou mudar. `chips_multiselect.php` ficou sem uso e foi removido.
- 🔴 **O filtro de veículo é por PLACA em toda parte**, não por equipamento/IMEI: `/comandos`, `/video/downloads` e `/video/playback` passam a rotular e listar **placa**. ⚠️ O **valor** continua sendo o IMEI — é por ele que as consultas casam, e duas placas iguais no cadastro se confundiriam num filtro por nome. Veículo sem placa cadastrada aparece como `(sem placa) <imei>`, e não como um número cru numa lista de placas: quem lê procuraria um veículo que não existe.

### Notes
- 🔴 **CONVENÇÃO FIXADA: "placa" é o que estiver cadastrado no campo do dispositivo, e é TEXTO LIVRE** (decisão do dono do produto). O campo é preenchido no **cadastro de ativos** sob o rótulo "Nome do Dispositivo" e é o MESMO que a operação chama de "Placa" — `ABC1D23`, `Frota 07` e `Câmera Frontal Ônibus 12` são todos válidos, porque cada cliente identifica a frota do jeito dele. ⚠️ **Nunca validar formato**: recusar `Ônibus 12` por "não ser placa" quebraria quem nomeia assim. Nenhuma validação do tipo existia — o que faltava era a convenção escrita, agora no `CLAUDE.md`, no docblock de `placa_do_device()` e nos testes.
  - **A convenção passou a ser dita na ORIGEM**: `/ativos/novo` e `/equipamentos` explicam, sob o campo, que aquele texto é o que aparece como Placa nas telas de operação, e que o sistema não exige formato. O exemplo do placeholder deixou de ser só um nome de câmera.
  - **O texto de vazio unificou**: `/ativos` mostrava `Sem Nome` para o mesmo estado que a operação chamava de `(sem placa)` — dois nomes para o mesmo estado do mesmo campo.
- 🔴 **`placa_do_device()` nasceu porque a mesma lógica apareceu em TRÊS telas.** E a armadilha é dupla: várias consultas já trazem `COALESCE(NULLIF(device_name,''), imei)`, então a placa ausente chega como o **próprio IMEI** e nunca como vazio — um `?:` no template não dispara e o número passa como se fosse placa. O spec pegou exatamente isso, em `/comandos` e depois em `/relatorios/alarmes`. Agora há uma casa só (`includes/functions.php`), com teste unitário dos quatro casos.
- **Os testes comparam os campos ENTRE SI, não contra uma cor fixa.** Um valor cravado no teste envelheceria junto com o tema; "todos os campos do filtro têm a mesma borda" vale para sempre — e é exatamente o que estava quebrado.
- **`web/components/filter_bar.php` removido**: código morto, sem uso em tela nenhuma, e o cabeçalho dele descrevia justamente o padrão que esta versão abandona ("multiselects com chips (+N)"). Resquício da fundação YUV (v4.0.0). Fica no histórico do git.
  - ⚠️ **A mesma varredura achou outros TRÊS órfãos da mesma leva** — `crud_grid.php`, `kpi_card.php` e `risk_bar.php`, todos com zero uso em código. Não foram removidos: os cabeçalhos dizem "Uso: Todos os Cadastros" / "Resumo, Dashboard, BI", ou seja, foram escritos para um plano que as telas acabaram implementando sem eles. Vale decidir se viram padrão de verdade ou se saem junto.
- ⚠️ **`marcar todos` respeita a busca em curso** — senão marcaria opções que a pessoa não está vendo, e ela só descobriria pelo resultado do relatório. Há teste para isso.

## [Unreleased] — 4.9.37

**O playback foi refeito em torno da barra.** Cinco apontamentos do dono do produto, e o segundo era um diagnóstico que não batia com o que o dado dizia — a gravação contínua *estava* sendo capturada.

### Fixed
- 🔴 **"Só aparecem gravações de alarme" — a causa não era captura.** A gravação contínua é capturada (2.625 blocos numa câmera), mas a listagem **vence em 30 min** (v4.9.17, cartão é buffer circular) e some da tela; o que fica são os vídeos de evento, que não têm validade. O aviso de vencimento falava de "download de arquivo sobrescrito" — verdade, mas não explicava o buraco na tela. Agora ele diz o que está acontecendo: *"sem ela a gravação contínua não aparece — o que sobra são só os vídeos de evento"*.
- 🔴 **Seletor de canal na requisição.** A resposta do equipamento sempre traz todos os canais: pedir um era filtro de exibição disfarçado de parâmetro, e obrigava a consultar duas vezes o que vem de uma vez. O seletor sumiu; escolhe-se o canal **clicando na faixa** dele. No JT/T o laço por canal ficou no código (o `37381` não aceita "todos"), mas a tela não pergunta — é o que dá às duas famílias a mesma experiência apesar da dinâmica diferente.
- **Teto de 500 itens na lista.** Deixou de existir: a lista espelha a **janela de zoom**, e aproximar É filtrar.

### Added
- **Barra com zoom** — roda do mouse aproxima (ancorado no cursor), arrasto desloca, `Tudo` volta ao período. No panorama desenha **sessões**; aproximando o suficiente, cada **bloco de um minuto** vira um alvo próprio. Passar o mouse mostra **início, fim e duração** do trecho.
- 🔴 **Duas ações explícitas, e nenhuma automática.** Clicar num bloco abre a escolha; **nada sobe para o storage sem pedido**:
  - **Ver na câmera** — transmite direto do equipamento e **não deixa arquivo**: `REPLAYLIST,<nome>` (JIMI) ou `37377` (JT/T), no mesmo player do vídeo ao vivo.
  - **Subir para o storage** — `HVIDEO,<carimbo>,<câmera>` (JIMI) ou `37382` (JT/T).
- **Downloads: o estado que faltava.** `pendente na câmera` → `pronto` → **`já baixado`**. Migração `v4.9.37` acrescenta `downloaded_at`, `downloaded_by` e `download_count`; a tela ganha lista suspensa de equipamento e, escolhido um, **some com as colunas que repetem a escolha** (placa, IMEI, modelo, cliente) — eram elas que empurravam status e download para fora da tela.
- **Vazio acionável**: caindo num trecho sem gravação — o caso NORMAL, já que dois terços de um cartão real são buraco — a lista oferece *ir para a gravação mais próxima* em vez de só dizer "nada aqui".

### Notes
- 🔴 **O carimbo de "baixado" é só do download EXPLÍCITO (`&dl=1`).** O player de MPEG-TS busca os bytes pela MESMA URL, por `fetch`, para remuxar: carimbar toda leitura faria *assistir* virar *baixado*, e a fila mentiria exatamente na coluna que ela existe para responder.
- ⚠️ **O caminho de publicação do playback por streaming NÃO foi medido em câmera real.** Para o ao vivo ele é `live/<canal-base-0>/<imei>.flv` (JIMI) e `<canal>/<imei>.flv` (JT/T), medidos em 18/08/2026; supor que o playback publica no mesmo lugar é a hipótese mais provável, não um fato. Por isso o player tem **prazo de 20 s com mensagem explícita** em vez de ficar preto.
- **A agregação em sessões passou a existir nos dois lados** (PHP para o resumo, JS para redesenhar a cada zoom). O algoritmo se repete; a **regra** tem uma casa só — `FILELIST_SESSAO_GAP_SEGUNDOS` viaja do PHP para o JS, então não há dois limiares para divergirem.
- ⚠️ **`setPointerCapture` troca o alvo do clique.** Com a captura ativa (necessária para o arrasto), o `click` chega com `target` = o `<svg>`, não o `<rect>` — e o clique simplesmente não fazia nada. Guardar quem estava sob o ponteiro no `pointerdown` é o que preserva o alvo.

## [Unreleased] — 4.9.36

**A linha da lista de gravações, refeita.** O sintoma relatado era o botão *Extrair* passando por cima do texto; a causa era `white-space:nowrap` sem `overflow` — o texto transbordava do flex e era pintado sob o botão. Mas o texto que transbordava era justamente o que não precisava estar ali.

### Fixed
- 🔴 **Texto sobre o botão.** `.tl-meta` ganhou `min-width:0` + `overflow:hidden` + `text-overflow:ellipsis`; a hora e a ação ganharam `flex:0 0 auto`. **Quem encolhe é a descrição** — a chave de leitura e a ação nunca somem para caber texto.
- 🔴 **Vídeo extraído aparecia no bloco ERRADO.** A tolerância de ±120 s da unificação vinha do JT/T, onde a gravação dura minutos; no JIMI o bloco tem **um minuto**, e ±120 s abrange **cinco blocos**. Visto na tela: um arquivo de `22:00:46` renderizado na linha das `22:02:46`. Agora são duas passadas — primeiro contenção EXATA (o caso do `HVIDEO`, cujo nome traz o início do bloco), e só o que sobra disputa a folga antiga.

### Changed
- **A linha diz o que varia, e só isso.** Saíram: `Gravação CH1` e `· CH1` (a mesma informação, duas vezes, já fixada no filtro de canal — 500 repetições de um dado constante); a data completa em toda linha (virou **separador de dia**, sticky, dito uma vez); e o nome do arquivo (`EVENT_…_I_02.ts`), que é identificação técnica e foi para o `title`. Ficou `● 22:06:46 · 1 min · 4,2 MB`, com a hora em `tabular-nums` para as colunas alinharem na vertical — é o que torna 500 linhas varríveis com o olho.
- **Badge só no estado excepcional.** `NO CARTÃO` aparecia em 500 linhas de 500 e comia um quarto da largura da coluna, esmagando a duração até `1 …`. Quem não tem badge tem o botão *Extrair*, que já diz o que a linha é; o estado continua no `title`, para leitor de tela e para o mouse.
- **`cursor:pointer` só onde o clique faz algo** — a lista inteira fingia ser clicável.

### Notes
- ⚠️ **A guarda contra a sobreposição precisou de um teste que FALHASSE primeiro.** A primeira versão media o conteúdo real (`1 min`), que é curto demais para transbordar: passava com o defeito no lugar. O teste agora **força um texto absurdo** e mede as caixas — foi verificado removendo a correção de propósito (falha) e recolocando (passa). Geometria, não marcação: `nowrap` sem `overflow` produz HTML perfeitamente válido.

## [Unreleased] — 4.9.35

**O vídeo chegava ao servidor e o sistema não sabia.** Achado durante o teste da v4.9.34 nas câmeras reais: o `[Extrair]` do playback disse "Solicitado", a câmera subiu o arquivo, o `105 — Upload de Vídeo Concluído` chegou com o nome certo — e o item continuou "No cartão". O arquivo estava íntegro no disco.

### Fixed
- 🔴 **Anexo de alarme só era registrado quando o alarme gerava OCORRÊNCIA.** O único caminho que inseria em `media_files` era `link_media_to_occurrence()`, dentro do motor de ocorrências. Quem não gera ocorrência ficava invisível: evento de **diagnóstico** — e o `105`, que é justamente quem anuncia vídeo extraído a pedido, é diagnóstico — e alarme sem parâmetro em `occurrence_config_params`. Medido em produção em 20/08/2026: **7 dos 12** eventos `105` das últimas 48 h tinham o arquivo no disco e **nenhuma** linha em `media_files`. Invisíveis para o playback, para a fila de downloads e para a galeria.
  - O registro passou para `media_register_file()` (`includes/media.php`), ponto **único**, chamado pelo `pushalarm.php` para **todo** alarme com anexo. `link_media_to_occurrence()` delega para ela e continua sendo quem LIGA o anexo à ocorrência — as duas são idempotentes por `file_url`, então a ordem entre elas não importa.
  - ⚠️ O `file_url` é gravado **como o device o anunciou**, inclusive quando traz DOIS arquivos separados por vírgula (a JIMI anuncia as duas câmeras num campo só). Partir aqui criaria linhas que o motor de ocorrências não reconheceria — ele casa pela string inteira — e o mesmo alarme acabaria com três linhas. Quem separa na hora de tocar é o `media_pick()`.

### Added
- **Barra do período no playback** — uma faixa horizontal por canal, no mesmo eixo, com as gravações do cartão marcadas. 🔴 Os segmentos são **SESSÕES contíguas**, não os blocos de um minuto: a 400AD_3 tem 3.021 blocos que são **47 sessões** por canal, e desenhar 3.021 traços numa faixa de ~900 px produz uma mancha sólida que **mente**, dizendo "gravou o tempo todo" exatamente onde há buracos. A fusão é `filelist_sessoes()` (`includes/filelist.php`), com folga de 120 s calibrada pela **concordância entre os canais** — as duas câmeras gravam juntas, então têm de dar o mesmo número de sessões (30 s → 48 e 54; 120 s → 47 e 47; 180 s → 45 e 46).
  - A barra cobre o **período pedido**, não o intervalo gravado: é assim que o vazio fica visível. Na 400AD_3 são 3,7 dias de janela para 25 h gravadas — dois terços do período não existem no cartão, e nenhuma lista comunica isso.
  - Marcas verdes para o que **já está no servidor**, tooltip nativo do SVG com início/fim/duração em cada sessão, e clique que leva até a sessão na lista (canal diferente recarrega, porque a lista é de um canal só). SVG inline, sem biblioteca — o projeto não tem build step.
  - Resolve de quebra o teto de 500 itens: com sessões, o período inteiro cabe mesmo quando a lista está truncada.

### Notes
- **A cadeia do `[Extrair]` foi fechada em câmera real** (400AD e 400AD_3, 20/08/2026): `HVIDEO,<carimbo>,<câmera>` → `HVIDEO:OK!` → a câmera sobe → `105` com `EVENT_…_2026_08_20_10_06_52_F_01.ts`, **o carimbo exato que foi pedido** → linha em `media_files` → o item vira "Disponível" no minuto certo da linha do tempo.
- ⚠️ **Passivo encerrado sem ação** (decisão do dono do produto): os arquivos que chegaram antes desta versão continuam sem linha e assim ficarão — 29 vídeos distintos, ainda no disco, invisíveis para o sistema. A correção vale do momento dela em diante; o backfill foi escrito, rodado em dry-run e **descartado**.
- ⚠️ **`filelist_url_base()` devolve `localhost` quando o `.env` não foi carregado**, e `localhost` é um endereço que a câmera *aceita* e nunca alcança. Só o construtor do `Database` lê o `.env` (`config/database.php`), então script CLI que não instancie a conexão antes recebe o fallback. Custou um disparo perdido no teste de campo: a câmera respondeu `FILELIST:OK!` ao endereço e `failed!` ao upload. O `failed!` do comando nu é, aliás, um sinal útil — é o device dizendo que não alcançou o destino.

## [Unreleased] — 4.9.34

**A lista de gravações do cartão passou a ser LIDA.** A v4.9.33 fez o corpo chegar; ele ia inteiro para `logs/filelist/` e ninguém o abria. Agora o `/filelist` interpreta os nomes e grava em `resource_lists` — a mesma tabela do JT/T —, e a tela de playback trata os dois protocolos pelo mesmo caminho. Verificado contra a captura real da 400AD_3 (78.590 bytes): **3.021 nomes, 3.021 linhas gravadas, zero descartados**.

Junto saíram os dois defeitos que teriam feito a leitura não valer nada, e que falhavam do mesmo jeito de sempre — comando aceito, tela verde, nada acontecendo.

### Added
- **`includes/filelist.php`** — parser e persistência da lista. Entrada: o corpo cru; saída: linhas em `resource_lists` com início, fim e canal. Idempotente por `ON DUPLICATE KEY UPDATE` (a câmera sobe a MESMA lista duas vezes por disparo, com 5 s de intervalo — medido).
- **`/filelist` responde antes de processar** (`fastcgi_finish_request`): a câmera não espera 3.000 gravações irem para o banco. O corpo cru continua sendo gravado ANTES de qualquer interpretação, e um `.parse.json` ao lado da captura diz o que aquele corpo virou.
- **[Extrair] no playback das câmeras JIMI** — `HVIDEO,<carimbo>,<câmera>` (proNo 128), montado a partir do nome que veio na lista.
- **`tests/helpers/filelist.test.php`** (41 checagens, sem banco) e **`tests/video_playback_filelist.spec.js`** (5 testes em navegador).
- **Retenção das capturas cruas** em `scripts/log_cleanup.php`. `logs/filelist/` guarda ~78 KB por listagem (e a câmera manda duas por disparo), e `Logger::cleanOldLogs()` só varre `*.log` — até aqui nada limpava esse diretório. Ele **continua existindo de propósito**: foi essa captura que resolveu a v4.9.33. Agora que a tela dispara o upload de verdade, ele cresce sozinho; a purga usa `LOG_RETENTION_DAYS`.

### Fixed
- 🔴 **A tela de playback nunca disparou o upload da lista.** Ela mandava só `FILELIST,<url>`, que apenas **grava o endereço** no equipamento; quem manda subir é a forma **NUA** `FILELIST` (planilha A006 vs A007 — a metade que a v4.9.27 achou faltando no catálogo, mas que a tela continuou sem usar). Está nos dados de produção: sete `FILELIST,<url>` entre 14:54 e 15:22 de 19/08, **zero** capturas; o `FILELIST` nu de 15:00:19 produziu a captura de 15:00:19, no mesmo segundo. Agora vão os dois, em sequência — e o segundo **não** sai se o equipamento recusar o endereço.
- 🔴 **O [Extrair] mandava `37382` para câmera JIMI.** É o "FTP file upload command" do JT/T; a JIMI não o conhece. Era o mesmo erro que a v4.9.0 corrigiu no JT/T (34818) e a v4.9.32 no FOTA (33027), agora no dialeto errado: o comando sai, o gateway aceita, e arquivo nenhum aparece. O botão passa a existir **por protocolo**, e some quando não há comando válido para o item.
- **Arquivo extraído aparecia no lugar errado da linha do tempo.** `media_files.event_time` de um bloco trazido por `HVIDEO` é a hora em que o **upload terminou** (o equipamento avisa pelo evento `105`), não a hora do que está gravado — o item virava um "Disponível" solto horas adiante, e a gravação de origem ficava "No cartão" para sempre. A unificação passa a usar o carimbo do **nome** quando ele existe. Para anexo de alarme os dois coincidem (medido), e nome de arquivo JT/T não tem carimbo: nesses casos nada muda.
- **A lista era cortada em silêncio.** O teto de 300 itens era invisível — no JT/T uma listagem traz dezenas. No JIMI o cartão é picado em blocos de **um minuto** (1.440 por dia por canal), e o filtro padrão da tela pede dois dias: cortar sem avisar mostraria as horas mais recentes como se fossem tudo o que há. Teto em 500, com aviso quando corta.
- **Plural quebrado no cabeçalho da lista** — "gravaçãoões" desde sempre.

### Notes
- 🔴 **O carimbo do nome é a hora LOCAL da câmera (UTC−3), não GMT 0.** É a armadilha desta fase e a única parte dela que falharia em silêncio: a lista apareceria com datas plausíveis, três horas fora do lugar, e o operador baixaria o minuto errado. Medido contra os anexos de alarme das **três** câmeras JIMI, que trazem o mesmo carimbo no nome e cujo `alarms.alarm_time` é conhecido — `EVENT_…_2026_08_20_08_47_42_I_15.ts` ↔ `11:47:42` UTC, offset +3 h em **29 de 29** amostras. A convenção "o device transmite GMT 0" vale para o CORPO do webhook; o nome do arquivo segue o relógio do equipamento. O código já dependia disso sem dizê-lo: `alarm_video_request.php` converte com `CONVERT_TZ(…,'-03:00')` antes de montar o `EVIDEO`.
- **O bloco do cartão é de um minuto** — doc oficial (A010: "which is one minute each file") e medição (1.442 dos 1.461 intervalos do canal 01 são de exatamente 60 s) concordam. `end_time` é `min(início do bloco seguinte, início + 60 s)`: nunca anuncia cobertura que o arquivo seguinte desminta. ⚠️ O "3 mins for each video file" da descrição do `EVIDEO` é outro comando — ele GERA um trecho novo.
- **O `HVIDEO` recebe o nome de volta sem conversão.** `HVIDEO,<Year_Month_Day_Hour_Minute_Second>,<1|2>` é exatamente o prefixo do nome que veio na lista, e o `_01`/`_02` é o mesmo par `1=Front camera; 2=Inward camera` do parâmetro B. Converter para UTC no caminho faria a câmera procurar um bloco três horas fora — e responder "não existe", não "fuso errado".
- ⚠️ **Lista vazia agora grita.** Corpo que chega e não produz nome nenhum vira `WARNING` no log (`filelist: nenhum nome reconhecido`), com o formato e o tamanho. É o alarme para a config do Apache da v4.9.33 ter sumido — ela é infra fora do git.
- **Volume**: uma listagem são ~3.000 linhas por câmera. Linhas de listas antigas não são apagadas; elas somem da tela sozinhas, pela validade de `captured_at` (v4.9.17).

## [Unreleased] — 4.9.33

**O `FILELIST` nunca produziu nada porque o Apache descartava o corpo — a câmera sempre mandou a lista.** Levantado com `tcpdump` na porta 80 da 400AD_3 (`864993060392306`) enquanto o comando era disparado.

### Fixed
- 🔴 **Corpo `chunked` acima de 16 KB era descartado em silêncio entre o Apache e o PHP-FPM** (`mod_proxy_fcgi`). HTTP 200 normal, nada no `error.log`, `php://input` vazio. Busca binária no servidor: 13.043 B → chegam; 16.293 B → chegam; **16.699 B → 0**; 39.043 B → 0. Fronteira cravada em **16.384 (16 K)** — número de buffer, não de protocolo; `post_max_size` é 8M e não há `LimitRequestBody`.
  - **Culpa isolada por experimento**: o MESMO corpo de 39.030 bytes, pelo mesmo socket cru, chega inteiro ao servidor embutido do PHP (sem Apache) e chega **zerado** por Apache → `mod_proxy_fcgi` → PHP-FPM.
  - **Correção**: `docs/apache/filelist-chunked.conf` — `SetEnvIf Request_URI "^/filelist/" proxy-sendcl=1`, que manda o proxy spoolar o corpo e entregá-lo com `Content-Length`. Aplicada em produção e **verificada com a câmera real**: 78.590 bytes, 3.021 nomes de arquivo, `CONTENT_LENGTH=78590`, sem `Transfer-Encoding`.

### Added
- **O layout do retorno do `FILELIST` deixou de ser desconhecido.** Não é TXT, é **JSON**: `{"imei":"…","fileNameList":"2026_08_16_05_33_58_01.ts,…"}`. O sufixo `_01`/`_02` é a câmera (frontal/interna), o mesmo par do `EVIDEO`/`HVIDEO`. O parser da FASE 1 tem contra o que ser escrito — 3.021 nomes medidos.
- **`docs/apache/filelist-chunked.conf`** — a config versionada, com o diagnóstico inteiro no cabeçalho.

### Notes
- ⚠️ **É infra FORA do git.** Mora em `/etc/apache2/conf-available/` e o `deploy.sh` **não** a instala: sobrevive a deploy, some se a máquina for reprovisionada. Instalar com `a2enconf filelist-chunked`, reverter com `a2disconf`. Captura de 0 byte voltando a aparecer em `logs/filelist/` é o primeiro sintoma de que ela sumiu.
- 🔴 **`<LocationMatch "^/filelist/">` NÃO é escopo válido nesta rota, e falha em silêncio.** O `.htaccess` reescreve `/filelist/…` para `handlers/router.php` e o location walk roda contra a URI REESCRITA — config no ar, `configtest` OK, zero efeito. Foi assim que a primeira tentativa de correção passou por boa. `SetEnvIf` funciona porque roda antes da reescrita; a segunda linha recupera a marca do outro lado do redirect interno (mod_rewrite prefixa `REDIRECT_`).
- 🔴 **`mod_buffer` (`SetInputFilter BUFFER`) não resolve** — testado com escopo largo de propósito, para não confundir "não funciona" com "não foi aplicado". Módulo desativado de volta.
- ⚠️ **A conclusão anterior ("infraestrutura descartada") estava errada**, e o motivo generaliza: os POSTs de controle tinham 17 e 28 bytes. O teste reproduziu o *protocolo* e não o *tamanho* — e o tamanho era a variável. A pista do `ACC: OFF` também não era: a captura boa foi feita com `ACC: OFF`.
- ⚠️ **Uma checagem passou por vacuidade e quase virou conclusão errada.** `strings mod_proxy_fcgi.so | grep proxy-sendcl` devolveu vazio e quase foi lido como "o módulo não suporta"; o `strings` **não existe no servidor** e o comando falhou. Refeito com `grep -a` (com controle em `mod_proxy_http.so`), o resultado se inverteu — e era justamente a correção que funciona.
- **Pendência aberta**: escrever o parser (FASE 1). Hoje a lista só é gravada em `logs/filelist/`.

## [Unreleased] — 4.9.32

**A trava do `UPDATE` estava errada, e por isso só um dos seis modelos podia ser atualizado pela tela.** O comando de atualização de firmware vale para a linha JC inteira — o que muda de um modelo para o outro é **só a URL do pacote**. Ele estava travado em JC371 porque `includes/command_catalog.php` deriva o campo `universal` da wiki ("presente em 5+ das 6 páginas") e **só a página do JC371 documenta o comando**. Era artefato da fonte, não do protocolo: com 9 dos 10 equipamentos do banco de teste desabilitados, a tela tornava impossível atualizar as JC400AD de produção — justamente onde a divergência de firmware custou caro na v4.9.31.

Junto vieram as duas coisas que faltavam para o `UPDATE` ser utilizável: **o firmware atual gravado no banco** e **um cadastro de URLs por modelo**.

### Fixed
- 🔴 **`UPDATE,P1#` deixou de travar a seleção por modelo.** Passa a cobrir os seis modelos (`universal => true`), com `P1` finalmente descrito ("URL do pacote de firmware do MODELO deste equipamento") e um exemplo. Procedência: **informação do fornecedor + operação**, não a wiki — a planilha oficial `JC400 & JC261 Command List` sequer lista `UPDATE`. A exceção manual está documentada no cabeçalho do catálogo e travada em teste, porque uma regeneração do catálogo por script a desfaz em silêncio.
- 🔴 **O botão FOTA de `/equipamentos` nunca atualizou firmware nenhum.** Ele mandava `proNo 33027` — que é **"Definir parâmetro"** do JT/T — com um payload inventado, `{"firmware_url":"…"}`. O gateway aceita, responde `code:0`, a tela diz "Comando de firmware enviado" e o equipamento ignora. Mesma família do defeito que a v4.9.12 corrigiu na família de parâmetros: comando aceito pelo gateway e ignorado pelo device. O botão agora leva para `/firmwares`, e a atualização sai como `UPDATE,<url>#` no proNo 128.
- **`devices.firmware_version` aparece na grade de `/equipamentos`.** A coluna já saía no export e não existia na tela.
- **A contagem no cabeçalho do catálogo estava errada desde a v4.9.27** — dizia 219 entradas / 143 comandos / video=29 quando o array já tinha 220 / 144 / 30. Corrigida, e agora **conferida por teste** contra o array de verdade.

### Added
- **`/firmwares`** (`handlers/firmwares.php`, só admin) — as duas metades da mesma pergunta:
  - **Frota**: a versão que cada equipamento reportou, quando reportou, como se compara à de referência do modelo, e os botões **[Ler versão]** (dispara `VERSION#`) e **[Atualizar]** (monta o `UPDATE,<url>#` com a URL cadastrada já pré-selecionada).
  - **URLs de atualização**: cadastro por **modelo**, com uma marcada como referência.
- **`includes/firmware.php`** — ponto único de ler a versão da resposta, validar a URL e resolver qual pacote vale para um equipamento.
- **A resposta do `VERSION#` passa a ser GRAVADA**, nos dois caminhos: o síncrono (`sendcommand.php`, device online) e o callback (`pushinstructresponse.php`, comando de fila offline). Cobrir só um deixaria sem leitura justamente a metade da frota que estava desligada quando alguém perguntou.
- **`migration_v4.9.32.sql`** — `firmware_releases` (URLs por modelo) + `devices.firmware_checked_at` e `devices.firmware_source`.
- **`tests/firmware.spec.js`** — 7 casos em navegador real, todos verificados **reprovando no código anterior** (com o catálogo antigo, 9 de 10 equipamentos ficavam desabilitados ao escolher `UPDATE`). E 30 checagens novas em `tests/helpers/command_response.test.php`.

### Changed
- 🔴 **`UPDATE` com mais de um modelo marcado bloqueia o envio em `/comandos`.** Soltar a trava de modelo sem isto seria trocar um erro por outro pior: o envio em lote manda a **mesma string** para todos os marcados, e a URL de um modelo aplicada em outro **não devolve erro nenhum** — o equipamento aceita, baixa e aplica. É o único comando do catálogo cujo *parâmetro* é específico do modelo.
- **`/comandos` oferece os pacotes cadastrados** como chips clicáveis quando o `UPDATE` está escolhido, e avisa quando o modelo marcado não tem nenhum.
- **Equipamento sem modelo cadastrado não recebe `UPDATE`** — o botão fica desabilitado com a razão no tooltip. Sem modelo não existe pacote certo para escolher, só um palpite que o equipamento aceitaria sem reclamar; em produção 1 dos 11 equipamentos está assim.

### Fixed (achado durante a verificação, fora do escopo original)
- 🔴 **A suíte Playwright inteira não rodava — e saía com exit 0.** `npx playwright test` sem argumento imprimia "Running 137 tests using 1 worker" e nada mais. Causa: o `testMatch` padrão do Playwright é `**/*.@(spec|test).?(c|m)[jt]s?(x)`, então ele coletava também `tests/helpers/player_snapshot.test.js` — que **não é um spec**, é um script Node autônomo cuja IIFE roda na importação e termina em `process.exit()`. Playwright importa todo arquivo coletado para descobrir os testes dele; a importação matava o processo antes do primeiro spec. O código de saída 0 é o que qualquer CI lê como "suíte verde".
  - Passar um caminho (`npx playwright test tests/algum.spec.js`) sempre funcionou — é por isso que sobreviveu: quem depurava um spec específico via a suíte rodar normalmente.
  - Corrigido com `testMatch: '**/*.spec.js'` no `playwright.config.js`. Os helpers `.test.js` passaram a ser chamados explicitamente pelo `scripts/run-tests.ps1`, ao lado dos `.test.php` — antes o `player_snapshot` rodava **por acidente**, como efeito colateral de envenenar a coleta.
  - **Resultado da primeira execução real da suíte: 133 passaram, 0 falharam, 6 pulados** (rate limiting do login, que bloqueia o IP de propósito; 3 de multi-tenant, que exigem `TEST_EMAIL_B`; 1 de coerência entre relatórios; 1 de webhook→ocorrência, que exige `TEST_IMEI`). Os 6 são pendências anteriores, não regressões.
- **`/parametros` e `/firmwares` entraram em `tests/navigation.spec.js`.** A lista de rotas cobria a sidebar de antes da v4.9.16 — as duas telas de administrador não eram exercitadas por ninguém.

### Notes
- ⚠️ **A migração foi registrada no `scripts/deploy.sh`** e o `SYSTEM_VERSION` do `.env.example` foi para `4.9.32`.
- ⚠️ **Vírgula e `#` na URL partem o comando** (são os separadores do proNo 128) — o equipamento recebe um pedaço de endereço e não baixa nada. Recusado no cadastro e nas duas telas que montam o comando. O `/sendcommand` pega o `#`, a URL sem esquema, com espaço ou vazia — mas **não** a vírgula: quando a string chega lá, uma URL partida e um `UPDATE` de dois parâmetros são indistinguíveis, e recusar o segundo caso bloquearia uma variante que algum modelo pode aceitar. Está escrito no próprio arquivo, com o que ele pega e o que não pega.
- ⚠️ **O que NENHUMA validação alcança é a URL do modelo ERRADO**: não há erro de comando, o equipamento baixa e aplica. Contra isso o que existe é o cadastro com o modelo na chave e a guarda de "um modelo por vez" — por isso a URL virou cadastro em vez de campo de texto.
- ⚠️ **A comparação de versões é por IGUALDADE, nunca por ordem.** Não há regra publicada que ordene `V1.8.0.9_250807` contra `V4.3.2`; a tela diz "igual à de referência" / "diferente" / "não lido", e nunca "desatualizado".
- **O formato da resposta do `VERSION#` não é documentado em lugar nenhum** — a wiki lista o comando, não o retorno. A leitura é tolerante (par `Version:` quando existe, senão o primeiro token com `\d+\.\d+`) e **recusa não vira versão**: os quatro dialetos de recusa da linha JC já são classificados por `command_response_interpret()`, e nível `erro` não grava nada.
- **Pendência que isto NÃO fecha**: `UPDATE` continua sem verificação em câmera real (M.2.5). O que foi verificado é tudo o que não exige device — catálogo, parser, guardas, telas e o caminho de gravação contra o schema real.
## [Unreleased] — 4.9.31

**O vídeo reenviado agora se religa ao alarme.** Quando o vídeo de um alarme não chega, dá para pedir de novo — mas o arquivo volta com **outro nome**, e `alarms.file_url` continua apontando para o antigo. O vídeo chegava ao servidor e não aparecia no relatório.

### Added
- **Botão "Pedir vídeo"** no relatório de alarmes, no lugar do selo "Vídeo não recebido" da v4.9.30. Dispara o reenvio e confirma o pedido; o vídeo aparece quando a câmera termina de subir.
- **`POST /solicitarvideo`** (`handlers/solicitarvideo.php`) — exige login, CSRF e **escopo de cliente**: sem ele qualquer usuário dispararia comando na câmera de outro tenant informando um `alarm_id`. Responde 404 (e não 403) fora do escopo, mesma postura do `filelist.php`.
- **`alarm_video_requests`** (`migration_v4.9.31.sql`) — o pedido pendente, com `alarm_id`, IMEI e o instante pedido.

### Fixed
- 🔴 **Casar por "alarme mais próximo no tempo" colaria o vídeo no alarme ERRADO.** O DMS dispara várias vezes no mesmo minuto e nada no nome do arquivo diz de qual evento ele é. Como somos nós que pedimos o reenvio, sabemos para qual alarme: o casamento é contra o **pedido registrado**, não contra proximidade. Há ainda a guarda de não roubar arquivo que já é anexo de outro alarme.
- **Janela de −90 s a +15 s, medida em câmera real** (18–19/08/2026). O timestamp do nome é o **início do clipe**, então o desvio é sempre para trás:

  | Origem do arquivo | Delta |
  |---|---|
  | Chegada natural | 0 s |
  | `EVIDEO` sem duração | 0 s |
  | `EVIDEO` com duração (15 s) | −31 s |
  | `HVIDEO` (bloco de 1 min) | −44 s (até −59) |

  Daí a assimetria: ±15 s perderia os dois últimos; ±90 s gastaria tolerância num lado onde nada acontece.

### Changed
- **A escolha do comando é por tentativa, não por versão de firmware.** `EVIDEO` dá o vídeo bom, mas nem todo firmware aceita — medido nas duas câmeras de produção: `V1.8.1.2_250904` responde `EVIDEO:OK!`, e `V1.8.0.9_250807` recusa **todas** as formas testadas (`command length error. support length [3, 4]`, mesmo enviando 4 elementos). Como `devices.firmware_version` está NULL em 100% da base e a resposta do equipamento é **síncrona**, o código tenta `EVIDEO` e cai em `HVIDEO` quando o device recusa por sintaxe — e só nesse caso: falha de rede ou device offline não se resolve trocando de comando.
- ⚠️ **`EVIDEO` vai SEM o parâmetro de duração**, de propósito: com ele o clipe volta deslocado (−31 s medidos); sem ele o nome bate exatamente com o instante pedido.

- 🔴 **Evento de diagnóstico não é dono de vídeo, e ignorar isso desligava o religamento inteiro.** Quem avisa o fim do upload é o próprio `105 — Upload de Vídeo Concluído`, gravado com o **mesmo nome de arquivo** logo antes do casamento rodar. A guarda de "não roubar anexo de outro alarme" encontrava esse 105 e recusava **todos** os casamentos. Pego no primeiro teste ponta a ponta em produção: arquivo no disco, pedido eternamente `pendente`. A guarda passou a excluir diagnósticos via `is_diagnostic_alarm()`.

### Notes
- ⚠️ **A migração foi registrada no `scripts/deploy.sh`.** As migrações são listadas **explicitamente** ali — uma migração nova que não entre nessa lista faz o deploy passar verde com a tabela inexistente, e a funcionalidade quebra só em produção.
- `tests/helpers/alarm_video_match.test.php` — 16 checagens, incluindo os dois limites da janela (−90 casa, −91 não; +15 casa, +16 não), o arquivo sem pedido, e a guarda contra roubar anexo de outro alarme.

## [Unreleased] — 4.9.30

**O relatório de alarmes oferecia "Ver Vídeo" para arquivo que nunca chegou.** Levantado do campo a partir do alarme `DMS: Motorista Bocejando` da 400AD_3 em 18/08/2026 16:16:57, que não abria o vídeo. Auditoria em produção: **81 dos 106 alarmes com `file_url` — 76% — apontavam para arquivo inexistente no disco**, e a tela oferecia o botão nos 106. O clique abria um player que nunca carregava: sem mensagem, sem erro, nada.

Ter `file_url` **não é** ter o vídeo. O nome do arquivo é anunciado pela câmera no próprio push do alarme; o arquivo sobe depois, por outro caminho (o container `dvr-upload`, porta 23010), e pode não chegar. O evento de diagnóstico `105 — Upload de Vídeo Concluído` também não é recibo: é a câmera dizendo que *ela* concluiu, e 27 desses apontam para arquivo ausente.

### Fixed
- 🔴 **A tela passa a distinguir três estados**: sem vídeo (`—`), **vídeo disponível** (botão) e **"Vídeo não recebido"** (selo neutro, com o nome do arquivo e a explicação no tooltip). `media_available()` já existia exatamente para isso e era usada só pelo dashboard de ocorrências.
- 🔴 **`file_url` com DOIS arquivos nunca era encontrado.** A câmera anuncia as duas câmeras num campo só, separadas por vírgula: `EVENT_..._I_56.mp4,EVENT_..._F_55.mp4` (I = interna, F = frontal) — 25 dos 106 alarmes. `basename()` sobre a string inteira devolve `..._I_56.mp4,EVENT_..._F_55.mp4`, que não casa com arquivo nenhum, então o par não seria achado nem estando no disco. Novos `media_file_list()` e `media_pick()` resolvem a lista e escolhem o primeiro que existe; `media_play_url()`, `media_available()` e a detecção de `.ts` passam por eles.

### Added
- **`tests/helpers/media.test.php`** — 22 checagens sobre os dois defeitos acima, com diretório de mídia controlado (não depende do servidor). Verificado que não passam nos helpers anteriores.
- **Os testes PHP de helper entraram no `scripts/run-tests.ps1`.** Eles existiam desde a v4.9.x e **nunca rodavam pelo runner**, que só chamava o Playwright — cada um dependia de alguém lembrar de executá-lo na mão. Agora são 4 (`command_response`, `device_params`, `diagnostico_guard`, `media`) e rodam antes da suíte, porque são rápidos e falham cedo.

### Notes
- **Como recuperar o vídeo de um alarme antigo** (medido nas câmeras reais em 18/08/2026):
  - **`EVIDEO,<AAAA-MM-DD HH:MM:SS>,<1=frontal|2=interna>,<10–60 s>`** é o que funciona: gera um clipe NOVO a partir do cartão TF e envia. Testado no alarme das 16:16:57 — chegou arquivo de 3,8 MB.
  - `UPLOADFILE,<nome do arquivo>` pede o arquivo exato pelo nome, mas a câmera respondeu **`file not exist!`**: o clipe de evento não fica guardado. Serve para reenvio recente, não para histórico.
  - `HVIDEO,<AAAA_MM_DD_HH_MM_SS>,<1|2>` (separador `_`, não `-`) traz o vídeo de memória — baixa qualidade, 1 min por arquivo.
- ⚠️ **O reenvio NÃO se religa ao alarme.** O arquivo regenerado volta com outro nome (`..._00000001_..._16_16_26_I_02.ts` contra `..._00000000_..._16_16_57_I_14.ts` gravado em `alarms.file_url`), então o vídeo fica no servidor sem aparecer no relatório. Religar exige uma regra de correspondência (IMEI + proximidade de horário) — **não implementado**, decisão de produto pendente.
- As falhas de 18/08 entre 08h e 16h coincidem com a queda do servidor (17/08 20:09 → 18/08 16:22); depois que voltou foram 9 alarmes e 9 vídeos, sem falha. As três câmeras respondem `http://186.248.143.197:23010/upload` ao `UPLOAD#` — destino correto.

## [Unreleased] — 4.9.29

### Fixed
- 🔴 **As telas de vídeo ofereciam equipamento DESATIVADO.** `video_aovivo.php` e `video_playback.php` montavam a lista com `WHERE 1=1`, sem filtro nenhum de `is_active`. O vídeo ao vivo ainda ordenava por `d.is_active DESC` — ou seja, o campo era conhecido e mesmo assim não filtrava: o equipamento dado baixa aparecia no seletor, só que por último. Baixa de equipamento é **soft delete** (`ativos.php` põe `is_active=0`), então a linha fica no banco para sempre e reaparece em toda tela que esquecer o filtro.
  Toda tela **operacional** do sistema já filtrava assim — `comandos.php`, `rastreamento.php`, `camerasdata.php`, `hbdata.php`. Só o **cadastro** (`ativos.php`) mostra inativo, e lá com selo "Inativo" ao lado. As de vídeo eram a exceção.
- **`/video/downloads` ficou de fora, de propósito.** Ali o `<select>` filtra uma lista de arquivos **já extraídos**, não pede nada ao equipamento: esconder o desativado apagaria da busca os downloads históricos dele. Se preferir uniformizar, é uma linha.

### Added
- **`tests/video_equipamento_inativo.spec.js`** — verifica as duas telas contra o par ativo/inativo do fixture. Verificado que **as duas reprovam** no código anterior.
- **Par ativo/inativo em `tests/helpers/seed_tenants.php`** (`869900000000888` ativo, `869900000000777` inativo, ambos no cliente A). Sem o inativo, "não lista desativado" passa por vacuidade; sem o ativo, passa por lista vazia. O spec exige os dois antes de afirmar qualquer coisa.

## [Unreleased] — 4.9.28

**A tela de vídeo ao vivo falava JT/T com câmera JIMI.** Ela sempre enviou `proNo 37121` (0x9101, o comando de vídeo do JT/T 1078) em **todo** equipamento, sem ramificar por protocolo — o arquivo nunca teve um ramo JIMI, desde a primeira versão. O banco de produção mostrava o defeito limpo: todo 37121 para JC400AD ficava `sent` (o device nunca respondeu), enquanto para JC371/JC181 ficava `executed`.

Não foi o HTTPS. O proxy `/stream` (v4.9.26, `docs/apache/bycamera-stream.conf`) está instalado e funcionando — a prova é que o 404 que volta de fora vem assinado `Server: JIMI-ZLMediaKit`, ou seja, a requisição atravessa o Apache e chega ao media server. O TLS quebrou o vídeo das câmeras **JT/T**, e isso já estava corrigido; nas JIMI o vídeo nunca funcionou por esta tela.

### Fixed
- 🔴 **Ramificação por protocolo no vídeo ao vivo** (`handlers/video_aovivo.php`):
  - **JIMI** → comando de texto `RTMP,ON,<CÂMERA>` (proNo 128, `serverFlagId 1`). O device faz **push RTMP** para o endereço já gravado nele em `RSERVICE` — as três câmeras de produção respondem `rtmp://186.248.143.197:1936/live` quando consultadas.
  - **JT/T** → segue com 37121 (`serverFlagId 0`), inalterado.
- 🔴 **A URL do player também diferia, e a tela só sabia a do JT/T.** JIMI publica em `live/<canal>/<imei>`, com o canal em **base zero**; JT/T em `<canal>/<imei>`, base um. Medido com a câmera publicando: `/live/0/<imei>.flv` devolveu 200 com assinatura `FLV`, e `/1/<imei>.flv` não devolveu nada.
- **Mapeamento de câmera medido, não suposto.** `RTMP,ON,INOUT` registrou `live/0/<imei>` **e** `live/1/<imei>` no media server; `RTMP,ON,OUT` registrou só o `0`. Logo **CH1 = OUT (frontal)** e **CH2 = IN (cabine)**. O device recusa o resto: `RTMP,parameter B error. options:[IN,OUT,INOUT,PIP]`.
- **`RTMP,OFF` ao parar.** Fica no botão Parar, e não em `stopPlayer()`, porque este também roda no início do `startLive()` e na troca de equipamento — ali um OFF desligaria o que acabou de ser pedido, ou o equipamento errado.
- **Equipamento sem modelo agora RECUSA em vez de adivinhar.** Sem modelo não há protocolo, e o default silencioso `JTT` repetiria o mesmo defeito ao contrário. A tela explica o que falta. São 1 de 11 equipamentos em produção (18/08/2026).

### Changed
- **`RTMP` no catálogo perdeu o parâmetro de duração** (`RTMP,A,B,C#` → `RTMP,A,B#`). Correção apontada pelo operador: o `<C>` da planilha (A014) existe, mas só em firmware V4.3+ e **não** governa a transmissão — o doc oficial de *pull live stream* usa `RTMP,ON,INOUT`, e o stream cai sozinho ~20 s depois que o último leitor sai. Quem tem tempo é `Video,<câmera>,<segundos>`, que é **captura de clipe**, não streaming. A v4.9.27 trocou os dois.

### Added
- **`tests/video_aovivo_protocolo.spec.js`** — 6 casos travando a decisão da tela (qual comando, qual URL, o mapeamento CH↔câmera e a recusa sem protocolo), sem depender de câmera real: o `/sendcommand` é interceptado. Verificado que os **6 reprovam** no código anterior.
- Duas travas em `command_response.test.php` (44 → 46): `RTMP` é ON/OFF + câmera sem duração, e `Video` mantém a sua.

### Notes
- Diagnóstico do `FILELIST` avançou e **não é o cartão**: `STATUS` (B020) devolveu `TFcard: 4.49GB/119.05GB` e `4.20GB/119.05GB` — cartões presentes e com gravações. Também não é infraestrutura (POST chunked entrega o corpo normalmente). As duas câmeras estavam com `ACC: OFF`; repetir com o veículo em operação é o próximo teste. `DISK` não serve para diagnosticar aqui — é comando do JC371 e a JC400AD responde `DISK,command header error`.

## [Unreleased] — 4.9.27

**A tela de Comandos só sabia CONFIGURAR o envio da lista de gravações, nunca PEDIR o envio — e mais 71 comandos oficiais não existiam.** Cruzando o catálogo com `docs/JC400 & JC261 Command List V5.0.3.20230626.xlsx`, a lista oficial da fabricante, apareceram três defeitos de naturezas diferentes. **JC261 é a nossa JC400AD** — a planilha nomeia pelo código de fábrica, e é por isso que os comandos marcados "Only for JC261" nunca tinham sido reconhecidos como nossos.

### Fixed
- 🔴 **`FILELIST` tinha só metade.** `FILELIST,<A>` (A006) apenas **configura** o endereço para onde a câmera mandará a lista; quem manda o equipamento **subir** a lista é a forma NUA `FILELIST` (A007), que não estava no catálogo. Como a tela só oferece o que está catalogado, era impossível disparar o upload: dez comandos foram enviados em 17–18/08/2026 e nenhuma lista chegou, todos marcados `executed`. Com a forma nua, as **três** JC400AD responderam (`FILELIST:OK!` em duas, `failed!` na terceira) e as três chamaram `/filelist` em 1–3 s. O corpo ainda chega vazio, mas isso é problema do device, não nosso: testes de controle no próprio servidor provaram que POST chunked e POST com `Content-Length` entregam o corpo normalmente.
- 🔴 **`Picture` e `Video` são sensíveis à CAIXA.** A planilha avisa nas duas linhas (A012/A013): *"the 'P' need uppercase letter and others need Lowercase letters"*. São os dois únicos comandos assim no proNo 128 — todo o resto é maiúsculo. Tínhamos `PICTURE` maiúsculo (e nenhum `Video`), forma que o equipamento recusa. O `PICTURE#`/`PICTURE,1#` antigo **fica**: é da wiki do JC371, chama-se "Parâmetros" e é outro comando — mesma base, mesma aridade, significado diferente. Foi justamente essa colisão que escondeu a falta.
- **O cruzamento passou a ser por COMANDO:ARIDADE, não por nome.** Comparar só o nome-base esconde variante faltante de comando que já existe — foi como o `FILELIST` nu passou despercebido. A regra recuperou mais 15 variantes oficiais (`APN` de 14 campos, `SSID,<A>,<B>,<C>`, `RAPIDACC,<A>`, `REPLAYLIST,OFF`, `TIMERPICRAM,DEL`, `FORMAT` nu…).

### Added
- **72 comandos/variantes da planilha oficial** (catálogo de 147 → 220 entradas, 90 → 144 comandos distintos), com `fonte` guardando a linha de origem (A007, G014…) — a mesma disciplina de procedência do `consulta_ref`.
  - **A família ADAS inteira** (`ADASSW`, `ADASSEP`, `ADASPI`, `ADASVI`, `ADASSP`, `ADASSEN`, `ADASVSP` — G009 a G015), que é o **núcleo do produto** e não existia: são "Only for JC261 & JC261P", ou seja, exatamente a JC400AD. Sem elas não havia como ligar, calibrar sensibilidade ou definir velocidade mínima do ADAS pela tela.
  - Eventos (`DEFENSE`, `SHOCK`, `SENSOR`, `RAPIDTEST`, `EXBATALM`, `NOSDCARDALM`, `ALARMTONE`…), vídeo (`RTMP`, `HVIDEO`, `EVIDEO`, `UPLOADFILE`, `CAR`, `MIRROR`), acessórios (`OILPARAM`, `TEMPCOLLECTINTERVAL`, `CARDREADER`, `UART`) e manutenção (`PING`, `LOG`, `GCALIBRAT`).
  - `ENCRYPT` (modelo 228) e `FACERECOGNITION` (518) ficaram **de fora**: são de outra linha de produto.
- **Quatro travas novas** em `tests/helpers/command_response.test.php` (40 → 44 checagens): `Picture`/`Video` preservam a caixa exata; a sintaxe começa pelo comando na caixa correta; `FILELIST` tem as duas formas; a família ADASxx está presente. As três primeiras existem porque cada uma corresponde a um defeito real desta rodada.

### Notes
- O `#` final deixou de ser automático. Ele **tem função**: a guarda de "placeholder não preenchido" da tela (`/(,P\d+|,[A-Z])(,|#)/`) só enxerga o último parâmetro porque existe `,` ou `#` depois dele. Em comando **sem** parâmetro não cumpre esse papel, e aí vale a forma da planilha — que para `FILELIST` é a nua, sem `#`, a mesma medida em três câmeras reais.
- `SOS` e `PASSWORD` entraram como comando **livre** (sem template): em `SOS,A,<A>,<B>,<C>` o primeiro `A` é literal ("Add") e o detector da tela não distingue literal de placeholder; `PASSWORD,<A><B>` não tem separador. O template montaria string errada, então os dois vão com os exemplos oficiais.
- Sete comandos da planilha são marcados `Private` (`UPLOADFILE`, `WIFIKIT`, `RAPIDSW`, `RAPIDACC`, `RAPIDDEC`, `RAPIDTURN`, `ALARMTONE`); o `fonte` registra isso.
- ⚠️ **`commands.response_time` é carimbado no DESPACHO, não na resposta** (`sendcommand.php:459`, `':rtime' => date(...)` dentro do INSERT). Além disso, 140 das 183 linhas com resposta têm exatamente 10800 s de intervalo — 3 h cravadas, mesmo segundo —, e a configuração inspecionada não explica o desvio (a conexão do app força `time_zone='+00:00'`, o `date()` do PHP bate com o `NOW()` do MySQL, não há `date.timezone` nos php.ini nem `date_default_timezone_set()` no código). Registrado como observação, **não corrigido**.

## [Unreleased] — 4.9.26

**O cadastro de equipamento gravava o cliente errado — ou nenhum — e dizia "cadastrado com sucesso".** Relatado do campo: uma câmera cadastrada em 17/08/2026 não vinculou ao cliente. A tela `/equipamentos` **não tinha seletor de cliente**: mostrava o cliente da sessão num campo `readonly` (que, por cima, lia `$editDevice['customer_name']` — coluna que o `SELECT *` da tela nunca trouxe, então exibia o cliente da sessão mesmo ao editar equipamento de outro) e gravava `customer_id = $sessao ?? 1`. Daí saíam três desfechos, os três com HTTP 200 e mensagem verde:

| situação | o que era gravado | como aparecia |
|---|---|---|
| admin com a grade filtrada no cliente X | cliente da **sessão**, não o X | equipamento "sumiu" — está em outro cliente |
| sessão sem cliente, via `/equipamentos` | **`1`** (fallback fixo) | equipamento no tenant errado |
| sessão sem cliente, via `/ativos/novo` | **`NULL`** | órfão: invisível em toda tela com escopo |

Sessão sem cliente não é hipótese: `login_user()` só chama `set_customer_context()` se `get_available_customers()` devolver algo, e devolve vazio para quem não é admin de plataforma e não tem vínculo em `customer_users`. E como `devices.customer_id` e `sim_cards.customer_id` são **nullable**, o `INSERT` do órfão passava sem reclamar — as colunas `NOT NULL` (`drivers`, `geofences`, `report_schedules`) escapavam da corrupção só porque o banco recusava, com uma `PDOException` crua na tela.

O erro **não estava só nessa tela**: o mesmo `get_customer_id()` sem guarda alimentava `/ativos/novo`, `/chips`, `/motoristas` e a importação em lote.

### Fixed
- **`resolve_owner_customer_id()`** (`includes/functions.php`) — o espelho de **escrita** do `report_customer_scope()`, com a mesma semântica de escopo (`reseller_scope_ids()`), para não existirem duas respostas para "que clientes são dele". Devolve `null` quando não dá para resolver com segurança, e o chamador **recusa o cadastro**: nunca há id "de consolo". Aplicado a `/equipamentos` (avulso e lote), `/ativos/novo`, `/chips` e `/motoristas`.
- **Seletor de cliente** em `/equipamentos` e `/ativos/novo` para admin/revendedor, pré-selecionado pelo dono atual → filtro da grade → sessão. O `<select>` sai de `report_customer_options()`, então revendedor só enxerga o próprio escopo. A importação em lote ganhou o mesmo seletor e vincula o arquivo inteiro ao cliente escolhido.
- **O `UPDATE` de `/equipamentos` passa a gravar `customer_id`.** Sem isso não havia como consertar pela tela o equipamento que nascesse órfão ou no cliente errado — o dono era imutável depois do cadastro.
- **Vazamento cross-tenant na edição de equipamento.** `SELECT * FROM devices WHERE imei` e `UPDATE … WHERE imei` não tinham escopo nenhum: qualquer usuário abria e **alterava** o equipamento de qualquer cliente sabendo o IMEI da URL. Agora o `WHERE` carrega o escopo (cliente da sessão para usuário comum, `IN (escopo)` para revendedor), e 0 linhas afetadas devolve "não encontrado no seu escopo" em vez de "atualizado".

### Added
- **Filtro "— Sem cliente (órfãos) —"** e aviso no topo de `/equipamentos` contando os equipamentos com `customer_id` NULL, com link para a lista. É como o admin acha o que o cadastro antigo deixou órfão — que, por definição, não aparece em nenhuma tela com escopo — para então vinculá-lo pela edição.
- **`tests/equipamento_vinculo.spec.js`** — 4 casos: a estrutura (tem de ser `<select>`, não campo fixo, com guarda de não-vacuidade exigindo 2+ clientes), o cadastro num cliente **diferente** do da sessão, a coluna Cliente nunca vazia na grade, e a recusa do **servidor** com o `required` removido do HTML. Verificado que reprova no código anterior — os 4 casos passam depois da correção e o primeiro falha antes dela.

## [Unreleased] — 4.9.25

**O catálogo de comandos só sabia escrever.** Todo comando `proNo 128` tem uma forma nua — `CMD#`, sem parâmetros — que **lê** o valor atual, e o `command_catalog.php` registrava apenas o setter (`APN,NOME,APN#`, `SERVER,A,B,C#`). Como a tela só oferece o que está no catálogo, nunca houve como perguntar nada ao equipamento pelo canal JIMI. Das 27 respostas de comando gravadas em produção até 15/08/2026, **nenhuma** era consulta — e foi por isso que a divergência de APN da v4.9.24 (o `33028` diz `cmnet`, o equipamento usa `allcombl.br`) sobreviveu sem ser notada: faltava o botão de perguntar. Levantado contra a wiki oficial da linha JC e medido em quatro câmeras de produção. Detalhe em `docs/COMANDOS_128_CONSULTA.md`.

### Added
- **Forma de consulta no catálogo**, em 49 comandos: `consulta` (a string a enviar), `consulta_modelos` (onde é sabidamente aceita) e `consulta_ref` — `medido` (bateria em câmera real) ou `wiki` (a página do JC400 escreve `CMD#666666`, a forma SMS da mesma pergunta). A procedência aparece na tela porque as duas não valem a mesma confiança; é a disciplina do `doc_ref` do `device_param_catalog` aplicada aqui.
- **Botão "Ler o valor atual"** no painel da tela de comandos, antes dos exemplos — perguntar é o passo natural antes de mudar. Vai pelo modo livre, porque a consulta é justamente o comando **sem** os campos que o template preenche.
- **27 comandos que faltavam** (catálogo de 120 → 147): `ASETAPN`, `BCD`, `CAMERA`, `COLLIDE`, `DISK`, `FILTER`, `GPSDUP`, `KEYFUN`, `LOGASW`, `MILE`, `MILEAGE`, `PICRATE`, `PICTIMER`, `PWDSW`, `RECORDAUDIO_SUB`, `RECORDSW_SUB`, `SF`, `SPEEDCHECK`, `SWERVE`, `TFMODE`, `UPLOADSW`, `VIDEOPARAM`, `VIDEORSL` e os quatro destrutivos (`FORMAT`, `RESET`, `RESTART`, `UPDATE`). Nome e descrição saem do texto da wiki, não de palpite.
- **Trava em teste: comando destrutivo nunca ganha forma de consulta.** `REBOOT#`, `FORMAT#` e `RESET#` têm forma nua — ela só não é uma pergunta. No dia em que alguém regenerar o catálogo por script, a distinção sumiria sozinha sem o teste.

### Fixed
- 🔴 **Dois dos quatro dialetos de recusa chegavam à tela como se fossem dado.** Cada firmware da linha JC recusa com uma frase diferente e o classificador conhecia só duas: `Not support!` (JC181) e `instruction error!` (JC182) viravam `erro`; **`Time Out!` (JC371) e `<CMD#>Command was not recognized!` (JC371 JMBS) caíam em `neutro — "Resposta do equipamento"`**. O operador lia "Resposta do equipamento: Time Out!" e concluía que o comando rodou — a mesma família do defeito que a v4.9.20 corrigiu. A regra do timeout procurava `timeout` colado e o equipamento escreve com espaço. O `Time Out!` ganhou título próprio ("Equipamento não atendeu o comando"), separado do `request timeout` do **gateway**: são causas diferentes e pedem ações diferentes.
- 🔴 **APN (`16`/`17`/`18`) sai da escrita** (`migration_v4.9.25.sql`). O `33028` reporta os valores de fábrica (`cmnet`/`usr`/`pwd`) enquanto o modem está conectado com outro APN, e a tela oferecia "corrigir" o que já estava visivelmente errado — gravando num slot que ninguém sabe se o modem obedece. Quatro provas independentes: os equipamentos têm APNs **diferentes** entre si e o `33028` diz `cmnet` para todos; o `STATUS#` reporta o APN real junto do `IP_ADDR` ativo; `cmnet` é da China Mobile e não autenticaria numa SIM brasileira; e o `ASETAPN#` confirma pela quarta via. O `19` (Servidor Principal) **continua gravável** — foi conferido contra o `SERVER#` nos quatro equipamentos e bate; a migração tem conferência que falha se ele for junto por associação.

### Corrigido de análises anteriores
- **`MILE#` não é o hodômetro.** A análise da v4.9.24 anunciou `MILE#` como contraparte do parâmetro `128` porque o JC182 respondeu `MILE#,0` e o `33028` do mesmo equipamento trazia `"128":"0"`. Os zeros coincidiram e a coincidência foi lida como significado. A wiki desfaz: `MILE,P1#` é a **unidade de velocidade** (0 = km/h, 1 = mph). O hodômetro é o `MILEAGE,P1#`, documentado só na forma de escrita — **o `128` segue sem contraparte de consulta**.
- **`AOSD` não é comando**, e sim sub-comando do `EVENTSET` (`EVENTSET,AOSD,30,0#`), que já estava catalogado. Segue verdadeiro que é por ele, e não por `SPEED`, que o JC371 faz excesso de velocidade.
- **`CAMERA#` é consulta**, apesar de responder `<CAMERA#> SET OK`. A wiki diz "Para consulta, enviar: `CAMERA#`" e `CAMERA,TF#` devolve espaço total e livre do cartão; o firmware é que confirma em vez de devolver o valor.

## [Unreleased] — 4.9.24

**Apareceu a tabela completa de parâmetros do JT/T 808, e ela confirmou o catálogo inteiro — menos quatro pontos.** A fonte é o [`QuecPython/jtt808`](https://github.com/QuecPython/jtt808/blob/master/docs/en/API_Reference.md) (`TerminalParams.set_params`): os **86 IDs** de `0x0001` a `0x0110`, com tipo, unidade, faixa e a distinção 2013 vs 2019. Confirma a descoberta da v4.9.15 — os números do device são os IDs da norma em decimal — em **47 parâmetros conferidos, sem uma divergência de numeração**. O outro documento avaliado, `docs/jtt-808-2019-meigou.pdf` (MEITRACK), publica só 4 IDs e não contradiz nada; fica registrado para ninguém reabrir a conferência achando que falta ler.

### Fixed
- 🔴 **`93` (0x005D) estava catalogado como `bitmask` e GRAVÁVEL, com a dica errada na tela.** A norma define **dois campos independentes** — tempo de colisão (ms) e aceleração de colisão (0,1 g, faixa 0–79, default 10) —, mas `param_input_spec()` exibia *"Máscara de bits em DECIMAL"*. Quem informasse `10` querendo 10 × 0,1 g de aceleração gravaria **tempo de colisão = 0** num parâmetro de **segurança**, numa câmera em operação, e a releitura devolveria um número plausível: o mesmo padrão de erro invisível do hodômetro em décimos de km. Vira o tipo novo `composite` e **somente leitura**.
- 🔴 **`132` (Cor da Placa) não resolvia o valor que a câmera realmente manda.** O enum tinha `{1,2,3,4,9}` e faltavam **`0` = sem placa** e **`5` = verde** (JT/T 697.7-2014). O `0` não é hipótese: é exatamente o que o JC371 devolveu em campo — a tela vinha mostrando o número cru por não achá-lo no mapa.

### Added
- **`100` (0x0064) tem nome e sai dos ocultos — era o último `Parâmetro NNN`.** É o `Timing photo control`: composto de 12 campos (liga/desliga e destino dos 5 canais + unidade de tempo + intervalo). A v4.9.15 o deixou oculto dizendo "continua sem fonte"; a fonte existe. Entra em `video`, somente leitura.
- **Tipo `composite` no catálogo**, para parâmetro de vários campos independentes — distinto de `csv` (lista posicional de canal) e de `bitmask` (um inteiro cujos bits têm significado). Sem tipo próprio, o `93` voltaria a cair na dica de bitmask. Nenhum `composite` é gravável, e a migração tem conferência que falha se algum for: caixa de texto não expressa N campos, que é a mesma razão pela qual a v4.9.15 recusou escrita nos bitmasks `82`/`83`.
- **Os 39 parâmetros da norma que faltavam no dicionário**, todos somente leitura. Nenhuma câmera nossa reportou nenhum deles — o catálogo sempre foi dirigido por medição —, mas linha no dicionário não aparece em tela enquanto não houver leitura em `device_params`, então o custo é zero e o ganho é não repetir a arqueologia da v4.9.15 no dia em que um firmware novo reportar um deles. Grupos novos: `gnss` (`144`–`149`), `can` (`256`–`259`, `272`) e `telefonia` (`64`–`73`). O mais relevante do lote é o **`80` (0x0050), máscara de bloqueio de alarme** — irmão do `82`/`83` que já tínhamos, e a alavanca de volume mais direta que existe num produto de ocorrências.
- **`37122`, `37378` e `37383` (0x9102, 0x9202, 0x9207) na whitelist do `/sendcommand`** — os controles que fazem par com requisições que já disparamos: parar o stream ao vivo do `37121`, pausar/avançar/parar o playback do `37377` e pausar/cancelar o upload do `37382`. Conseguíamos **iniciar** as três operações e não conseguíamos **interromper** nenhuma; a câmera seguia transmitindo até o timeout dela. Ainda **sem preset de tela**, de propósito: os presets carregam os nomes de campo do hub da Jimi (`videoIP`, `codeStreamType`…), que não são os da norma e não constam de nenhuma das duas fontes — inventá-los produziria comando aceito pelo gateway e ignorado pelo device, que é o defeito que o `37382` tinha antes da v4.9.1.

### Changed
- **Procedência deixa de ser inferência em cinco parâmetros.** `6` entrou na v4.9.15 como `0x0006 (simetria)` por falta de fonte, e a fonte confirma *"SMS message response timeout"*; `16`/`17`/`18` estavam como `medido/inferido` e são `0x0010`/`0x0011`/`0x0012`; `23` é `0x0017`. A inferência estava certa nos cinco — o que muda é o `doc_ref` parar de mentir sobre de onde o dado veio.
- **`24`/`25` (0x0018/0x0019) passam a declarar que são obsoletos no 808-2019**, que fundiu porta TCP/UDP dentro do `0x0013` (`host:porta;host:porta`). O JC371 responde à moda 2013 (`19` = IP puro, `24` = 21122 à parte) e continuam graváveis por isso — mas sem o aviso no `doc_ref`, o primeiro firmware 2019 puro vira caça ao fantasma.

## [Unreleased] — 4.9.23

**As três telas de vídeo tinham o mesmo problema da tela de comandos: presas ao cliente da sessão.** Para ver a câmera de outra carteira, o administrador precisava trocar de cliente no cabeçalho.

### Added
- **Filtro de cliente em `/video/aovivo`, `/video/playback` e `/video/downloads`**, pelos mesmos pontos únicos (`report_customer_scope()` + `report_customer_options()`): para quem não é admin o `?customer_id` é **ignorado**, não validado. Com "Todos os clientes" a lista mistura carteiras — abrir a câmera do cliente errado é dano de privacidade, não engano cosmético —, então nesse modo o nome do cliente entra no rótulo do equipamento e numa coluna da grade.
- **Filtro de vários equipamentos e exportação (Excel/PDF/CSV) em `/video/downloads`**, sensível a cliente, equipamentos e status. Exporta o recorte inteiro, não a página, com teto de 5000 declarado no subtítulo quando atingido.

### Fixed
- 🔴 **`/video/downloads` mostrava a fila de TODOS os clientes quando a sessão estava sem cliente no contexto.** O filtro era `if ($customerId)`: com o contexto vazio — estado normal do admin de plataforma antes de escolher um cliente — a cláusula não entrava e a grade trazia os arquivos da base inteira, sem que a tela dissesse isso em lugar nenhum. Agora o escopo é sempre explícito e, quando é "todos", a tela declara de quem é cada arquivo.
- **Os filtros de `/video/downloads` deixam de se apagar entre si.** O status recarregava com `location.href='?status='+…`, que descarta qualquer outro parâmetro — com cliente e equipamentos na URL, trocar o status jogaria os dois fora. Viraram um `form` GET.
- 🔴 **"Está online?" tinha uma terceira resposta no sistema.** `video_aovivo` usa o MAIOR entre `last_communication`, `last_gps_time`, `last_heartbeat_time` e `last_event_time`, com limiar `OFFLINE_GAP_SECONDS` (30 min) — porque **só `pushalarm.php` e `pushlbs.php` escrevem `last_communication`**; GPS e heartbeat não a tocam. A presença que a v4.9.21 colocou na tela de comandos usava aquela coluna sozinha, com limiar próprio de 15 min. Nos 8 equipamentos de produção hoje as duas regras concordam — a falha é **latente**: divergiria no dia em que um equipamento parasse de mandar LBS/alarme e seguisse reportando posição, dizendo "offline" numa tela e "online" na outra. A conta virou ponto único (`device_last_seen_sql()` + `device_presence()` em `includes/fleet_state.php`) e as duas telas passam a usá-la.

## [Unreleased] — 4.9.22

**A tela de comandos era presa ao cliente da sessão, e o histórico não saía de lá.** O administrador só enxergava os comandos do cliente em que estivesse posicionado — sem olhar a base inteira, sem recortar por cliente e sem levar o histórico para fora da tela.

### Added
- **Filtro de cliente para admin e revendedor.** O `?customer_id` passa por `report_customer_scope()` e a lista por `report_customer_options()`, como manda o CLAUDE.md: para quem não é admin o parâmetro é **ignorado**, não validado — obedecê-lo deixaria qualquer usuário ler comandos de outro cliente trocando um número na URL; para revendedor, o escopo é a carteira dele.
  - Com "Todos os clientes" a lista de envio mistura carteiras, e disparar comando no veículo do cliente errado é dano real: nesse modo — e só nele — o nome do cliente aparece na linha do equipamento e numa coluna do histórico.
- **Filtro de vários equipamentos** (multisseleção com chips) no lugar do seletor de um só. O parâmetro continua sendo `imei`, agora aceitando lista separada por vírgula: **link antigo com um IMEI continua valendo**. Os IMEIs recebidos são conferidos contra a lista visível ao usuário, para o parâmetro da URL não alcançar equipamento fora do escopo.
- **Exportação do histórico em Excel, PDF e CSV**, sensível aos mesmos filtros da tela (cliente, equipamentos, período, desfecho). Exporta o recorte **inteiro**, não a página visível — quem pede relatório quer o filtro, não o pedaço que coube na tela. O subtítulo carrega cliente, equipamentos, desfecho e período, porque o PDF circula fora da tela e um número sem recorte não diz nada; quando o teto de leitura é atingido, o arquivo declara isso em vez de parecer completo.

### Changed
- `web/components/chips_multiselect.php` aceita `$chips_labels` (mapa valor ⇒ rótulo). O componente nasceu para tipos de alarme, onde o valor **é** o texto; equipamento precisa de `imei` como valor e placa como rótulo. Sem o mapa a alternativa seria filtrar por nome, e dois veículos com a mesma placa cadastrada passariam a se confundir. Ausente, o comportamento é idêntico ao anterior.

## [Unreleased] — 4.9.21

**A tela de comandos passa a dizer se o equipamento está online — sem impedir o envio para quem não está.** Comando para equipamento offline é fluxo suportado de ponta a ponta (o IoT Hub responde `converted to an offline command`, guarda e entrega no reconecte), então a presença **informa e só**: quem programa manutenção manda comando de madrugada justamente para o veículo desligado.

### Added
- **Presença por equipamento** na lista de envio, de `devices.last_communication` — conferido em produção contra `MAX(heartbeats.created_at)`, bate equipamento a equipamento. Online (≤15 min), "há N min/h/d" e "sem contato registrado", com o horário exato do último contato no `title`. Ao marcar um equipamento sem contato recente, a tela explica que **o comando entra na fila** — e o botão continua liberado.
- **Marca "na fila"** nas linhas do histórico em que o gateway aceitou e a entrega segue pendente (`status='sent'` + desfecho de espera). Era lido como falha por quem opera.
- 🔴 **Painel de respostas sem comando correlacionado.** `pushinstructresponse.php` grava toda resposta em `command_responses` e tenta casá-la com a linha de `commands`; quando falha, a resposta existe no banco e **some da interface**. Medido em produção: **14 de 23** respostas offline nunca chegaram a `commands`, incluindo conteúdo real de equipamento (`ext Battery:12.1V; GPRS:Link Up`, `Device busy`). O painel mostra o que existe **sem inventar correlação** — a associação é por equipamento e horário, e a tela diz isso com todas as letras.
- **Filtro de período e paginação** no histórico (padrão: 7 dias, 25 por página). Antes era `LIMIT 200` fixo, sem recorte de data.
  - ⚠️ A paginação **não** pode ir para o SQL: o filtro de desfecho depende de interpretar o payload em PHP, então um `LIMIT` no banco devolveria páginas de tamanho variável e contagens erradas nos chips. Lê-se a janela (teto de 2000, avisado na tela quando estoura), interpreta-se, pagina-se depois.
- **Atualização automática opcional a cada 30 s**, desligada por padrão e lembrada entre sessões. As travas são o ponto: pausa com envio em curso, detalhe aberto, foco em campo ou **comando em edição** — e a seleção (equipamentos, busca, comando) é preservada na recarga. Auto-atualização que atrapalha é pior que nenhuma; o operador desliga e nunca mais liga.
  - A trava de edição compara `input.value` com `input.defaultValue` — pausar só porque existe um comando escolhido deixaria a atualização em pausa permanente, que é o estado normal de quem usa a tela.

### Changed
- **O catálogo deixou de ser um `<select size="9">`.** Num `<option>` só o rótulo aparece: a sintaxe e a descrição — o que decide qual comando usar — ficavam invisíveis, e escolhia-se no escuro entre 119 itens. Virou lista com nome, sintaxe e descrição, categorias fixas no topo ao rolar. O `<select>` continua existindo fora da vista como **estado**: é o que `aoEscolherComando()` lê, o que dá controle nativo de teclado e leitor de tela, e o que a suíte Playwright manipula.
- O fim do acompanhamento de um comando em fila deixa de dizer "sem resposta": agora diz que a entrega segue pendente e que a resposta aparece no histórico quando o equipamento reconectar. Dizer "sem resposta" ali repetiria a mentira que a tela contava antes da v4.9.20.

## [Unreleased] — 4.9.20

**A tela de comandos mostrava o recibo do gateway no lugar da resposta do equipamento.** O operador via "Executado" e nada mais; o que o device respondeu — `OK!`, a leitura dos parâmetros, a linha de status com sinal e bateria — não aparecia em lugar nenhum da interface. Medido no histórico de produção: **168 comandos com resposta gravada, 120 (71%) mostravam texto errado ao operador**.

🔴 **E não era só omissão: 5 comandos recusados pelo equipamento eram anunciados como `Executado`.** O device respondeu `failed!` (FILELIST) e `command error` (RECORDSW#) enquanto a tela dizia que tinha dado certo, porque o `msg:"success"` do envelope significa "a mensagem foi entregue ao gateway", não "o comando funcionou".

### Fixed
- **A resposta do equipamento vem em `data._content`, e nenhum dos três leitores lia esse campo.** `command_response_extract()` passa a devolver `conteudo` (o que o device respondeu) separado de `texto` (o que o gateway diz da entrega), e `command_response_interpret()` classifica **primeiro pela palavra do equipamento**, caindo no gateway só quando o device não disse nada reconhecível — senão um `_content` dizendo `Device busy` com envelope em `success` continua saindo como "Executado".
  - ⚠️ `_content` vem como **string vazia** (não ausente) quando o device está offline, então a checagem é de "não vazio": com `??` o vazio vence e a mensagem útil (`Device not online`, que está em `_msg`) se perde. Era esse o bug do `commandstatus.php`.
  - ⚠️ Resposta **estruturada** (`{...}`) não passa pelas regras de frase: o retorno da consulta de parâmetros começa com `{"paramCount":…` e a regra de parâmetro casava em `param`, anunciando "Parâmetro inválido" para uma leitura correta.
- 🔴 **O painel de envio lia `j.response`, um campo de primeiro nível que o `/commandstatus` nunca emitiu** (o endpoint devolve `{commands:[{…response}]}`). A condição era falsa **sempre**: o painel parava em "enfileirado #N" e, um minuto depois, afirmava "sem resposta (fila offline)" mesmo com a resposta já gravada no banco. Quebra de contrato silenciosa — sem erro no console, sem 500, só uma tela que mente.
- **Três cópias divergentes da mesma regra de leitura viraram uma.** `commandstatus.php` tinha a sua (preferia `_content`, mas com `??`), `relatorios.php` a terceira e pior (lia só o topo do envelope, nunca entrava em `data`) e `includes/command_response.php` a que a tela usava. Duas cópias divergentes da mesma regra são o motivo de corrigir uma nunca corrigir a outra.
- **`/ativos/{imei}` selecionava `response_payload` do banco e não renderizava nada dele** — a aba de comandos mostrava só o status de envio. Passa a mostrar o desfecho interpretado e a resposta, pela mesma leitura única.

### Changed
- **A resposta do equipamento aparece na LISTA**, sob o desfecho, monoespaçada e limitada a duas linhas (payload de parâmetros passa de 300 caracteres e esticaria a linha). Antes era preciso abrir o detalhe de cada linha — e mesmo lá vinha o texto errado.
- **Detalhe do comando ganhou "Copiar" e a resposta bruta do gateway** num `<details>` fechado. O envelope cru é o que permite ver que a interpretação está certa — ou provar que não está — sem abrir o banco, que é exatamente o que faltou para este defeito aparecer antes.
- **Os números do resumo viraram filtro** (clique alterna, preserva o equipamento escolhido) e ganharam o nível `neutro`, que era contado e filtrável mas invisível — a soma dos três não batia com o total de registros.
  - 🔴 **Ao torná-los clicáveis apareceu um erro que estava escondido**: o resumo era contado **depois** do filtro, então filtrar por "com erro" zerava os outros três. Enquanto os números eram decorativos ninguém reparou; como filtro, um "0 executados" falso levaria o operador a concluir que não há nada. A contagem passou para antes do filtro.
- **A tela deixa de quebrar em telas estreitas.** O grid de duas colunas estava em `style=` **inline**, e estilo inline não pode ser sobreposto por media query — não existe media query dentro do atributo e na cascata ele vence a folha. Resultado: dois painéis densos lado a lado em qualquer largura, inclusive no telefone. Virou classe, com colapso para uma coluna abaixo de 1100px.
- **Acessibilidade e teclado**: as linhas do histórico abrem um modal, então viraram controles de verdade (`tabindex`, Enter/Espaço, `aria-label`) e o modal fecha com **ESC**.
- **Aviso de histórico defasado**: a lista é renderizada no servidor e não incluía o que acabou de ser enviado. Some a impressão de que a tela engoliu o comando.
- `.res-dot`/`.dot-*`/`.res-msg` saíram da tela de comandos para o `layout_base.php`: duas telas mostram o mesmo desfecho, e a cópia local faria as cores divergirem.
- **Teste novo** (`tests/helpers/command_response.test.php`, 17 checagens) fixado em payloads **reais** de produção — inclusive o device offline com `_content` vazio e o `_msg: null` dos comandos de texto, que são os dois casos que quebravam.

## [Unreleased] — 4.9.19

**O BI passa a falar o nome do evento.** Era a última tela que agrupava, rotulava e filtrava pelo **código cru**: o gráfico dizia `264` e `45` onde o Relatório de Alarmes, `/relatorios` e a aba de alertas do ativo já diziam *Fadiga do Motorista* e *Capotamento* — as três foram convertidas na v4.9.10 e o BI ficou para trás.

### Fixed
- **`/bi` resolve o nome pelo mesmo ponto único das outras telas** (`alarm_label_sql()`): rótulos do "Top 10", chips do multiselect e o filtro que eles alimentam. O filtro casa contra o nome **resolvido**, não contra a coluna gravada — senão o evento que o gráfico mostra como "Capotamento" sumiria ao marcar o próprio chip, porque em `alarms.alarm_name` ele pode estar congelado como `Código 1047 (JTT)`.
- 🔴 **Quatro defeitos que só apareceram ao trocar código por nome — todos escondidos pelo mesmo `catch (Exception $e) {}` vazio**, que exibia erro de SQL como "nenhum dado no período":
  1. **Nenhum gráfico de alarme tinha dado quando não havia chip marcado.** O formulário envia `alarm_types` mesmo vazio, `explode(',', '')` devolve `['']` e `!empty([''])` é **verdadeiro** — o filtro virava `IN ('')`, que não casa com nada. Ou seja: o caminho padrão da tela.
  2. **`alarms` não tem `customer_id`.** O ramo do admin filtrava por `a.customer_id` e derrubava a consulta inteira em *Unknown column*. O cliente vem de `devices`. De quebra, o escopo multi-tenant passou pelo ponto único (`report_customer_scope()`), como manda o CLAUDE.md.
  3. **O filtro de motorista (`o.driver_id`) ia junto para as consultas de `alarms`**, onde o alias `o` não existe — escolher um motorista zerava a tela inteira.
  4. **`Ocorrências por Risco` não tinha `GROUP BY`**: a rosca de três fatias vinha de uma linha só.
- **Marcar qualquer chip zerava os dois gráficos de ocorrência.** `occurrences.alarm_type` guarda o **nome** (é o que `process_alarm_to_occurrence()` grava), e o filtro comparava com o **código**. Com o filtro por nome, os dois lados passam a casar.
- **Parâmetro de perfil gravado fora da lista do `<select>` era apagado no primeiro salvamento.** `occurrence_config_params.alarm_type` aceita nome, **código** e **categoria** — o `<select>` de `/config-ocorrencias` só oferece nomes, e só de algumas categorias. O que não casava abria em branco e sumia ao salvar; como `get_occurrence_param()` retorna cedo sem parâmetro, o evento parava de gerar ocorrência **em silêncio**. Agora o valor é preservado numa opção própria e **traduzido**: código vira o nome do evento, categoria vira o rótulo em pt-BR.
- **Mesma correção em `/config-notificacoes`**: regra gravada por código aparecia como número na lista e abria o formulário em branco (campo `required` — a única saída era reescrever a regra por cima). Passa a mostrar o **nome do evento** com o código ao lado. Como o mesmo número significa coisas diferentes em JIMI e JT/T (ADR-001) e o motor casa os dois, os nomes vêm **concatenados** em vez de escolhidos.

- **`deploy.sh` acusava "Nenhum VirtualHost apontando para /var/www/jimi_webhook" em TODO deploy de produção, com o vhost perfeitamente configurado.** A checagem procurava o caminho da aplicação na saída de `apache2ctl -S` — que **não informa o DocumentRoot dos vhosts**: traz ServerName, porta, o arquivo de config de cada um e o `Main DocumentRoot` global (`/var/www/html` no Debian). O teste falhava por construção sempre que a aplicação não fosse o docroot principal, que é o caso normal. Agora o script lê os arquivos de config que o próprio Apache aponta e procura neles o `DocumentRoot`; de quebra confere o `AllowOverride All`, que é o que faz o `.htaccess` (o front controller) valer — sem ele toda rota do dashboard vira 404. Quando nada casa, o aviso agora **lista os arquivos conferidos** e lembra que o smoke test de `/ping` da FASE 4 é a prova final. Um aviso que aparece sempre é um aviso que se aprende a ignorar — e aí o dia em que ele for verdade passa batido.
  - ⚠️ Duas armadilhas de shell no caminho, ambas anotadas no script: `grep -r` **pula symlink** encontrado na recursão (e `sites-enabled/` é só symlink para `sites-available/`), e o GNU grep sai com **status 2 por arquivo inexistente mesmo tendo casado** em outro — só o `-q` sai com 0 no primeiro match "even if an error was detected". A segunda chegou a fazer a versão nova negar um `AllowOverride All` que estava lá, e só apareceu porque a correção foi testada contra o Apache real de produção antes de publicar.

### Changed
- Os chips do BI passam a usar `web/components/chips_multiselect.php` — componente que foi **extraído desta própria tela** na v4.2.0 e nunca reaproveitado aqui. Some a cópia de CSS/JS que vivia só no `bi.php` e que já divergia da do Relatório de Alarmes.
- O `catch` mudo do BI virou `Logger::error` + aviso na tela. Os quatro defeitos acima sobreviveram porque não havia diferença visível entre "erro de SQL" e "período sem alarme".
- **`SYSTEM_VERSION` do `.env.example` foi para `4.9.19`.** Ficou em `4.9.17` durante duas versões, e é dele que o `deploy.sh` propaga o valor para o `.env` do servidor — o `/ping` de produção anunciava `4.9.17` com o código da v4.9.18 no ar.

### Docs
- 🔴 **A doc chamava o servidor errado de produção.** `189.22.240.43` foi o ambiente único até 13/08/2026 e ficou registrado como "servidor produção" na §8 do `STATUS.md` e em `.agents/memory/project-conventions.md`; desde então **produção é `186.248.143.197` (`https://bycamera.ia.br`)** e aquele endereço é **homologação**. O texto velho já custou um deploy apontado para o servidor errado. Corrigidos os dois arquivos e criada a seção **Ambientes** do `CLAUDE.md`, com a tabela dos dois servidores, o comando de deploy (`ssh -t`, porque o `sudo` pede senha) e a assimetria das chaves SSH — produção aceita a chave do Mac de dev, o homolog só tem a da máquina Windows, e a recusa de lá é **chave ausente, não senha errada**.
- Exemplos com o IP do homolog passaram a dizer que são do homolog (`scripts/test_e2e.sh`, `scripts/run-tests.ps1`); o replay E2E grava alarme e ocorrência de verdade, então apontá-lo para produção sem querer não é inofensivo.
- `AGENTS.md`: a coluna "Default" de `FILE_STORAGE_URL`/`STREAM_URL` mostrava o endereço do homolog, que não é default de nada — o código cai em `localhost`. Agora traz o default real e o valor de cada ambiente, inclusive o `STREAM_URL` de produção passando pelo proxy HTTPS (`/stream`), sem o qual o navegador recusa o FLV em página TLS.

## [Unreleased] — 4.9.14

**F3 do `PROJETO_PARAMETROS.md`** — a escrita. É a única parte do projeto que mexe em equipamento em operação.

### Added
- **Escrita de parâmetro (`33027`) com três travas, todas no servidor.** O JavaScript da tela é sugestão; quem forja o POST passa por cima dele.
  1. **Só se escreve o que o catálogo sabe nomear** (`writable = 1`). Os 17 parâmetros `medido` — que a doc não publica — nunca são graváveis.
  2. **O valor anterior é gravado ANTES do despacho.** Foi a contrapartida acordada quando o dono do produto aceitou o risco dos parâmetros de rede: a recuperação é por SMS, e o SMS precisa saber **para onde voltar**. Se o valor anterior só fosse gravado no sucesso, o caso em que ele é indispensável — a escrita que derrubou a câmera — seria exatamente o caso em que ele não existe. Falhou o registro, o comando **não é despachado**.
  3. **`is_network` marca os sete** (`16`,`17`,`18`,`19`,`23`,`24`,`25`) que tiram a câmera da plataforma. Não bloqueiam — decisão de §8.1 — mas exigem `confirm_network` explícito: é a diferença entre decidir e esbarrar.
- **Perfis de configuração por MODELO** (`/config-parametros`), com sobreposição por cliente. Por modelo porque foi **medido**: o JC371 devolve 49 parâmetros e o JC181, **6**, com conjuntos diferentes — perfil por cliente aplicaria a um modelo parâmetros que ele não tem. A tela mostra o **impacto antes**, sem enviar nada: é o que separa "aplicar perfil" de "apertar e ver".
- **`device_param_writes`** — toda escrita tentada, com o valor de origem. `device_params` guarda o **estado**; isto guarda o que foi **tentado**. Uma escrita recusada não muda o estado, mas precisa aparecer para quem investiga.

### Fixed
- 🔴 **A trava de parâmetro de rede não disparava — e escreveu numa câmera real.** Testando as travas contra o homolog, um `33027` com o parâmetro `19` (Servidor Principal) e valor `1.2.3.4` foi **aceito por uma câmera de verdade**, sem a confirmação obrigatória.
  - **Causa**: `param_catalog()` selecionava as colunas uma a uma e `is_network` — criada pela própria migração v4.9.14 — **não entrou na lista**. `$cat[19]['is_network']` vinha indefinido, `!empty()` dava `false`, e a guarda virou decoração. Lista explícita de colunas num catálogo lido **inteiro** só cria esse modo de falha: coluna nova é ignorada em silêncio. Trocado por `SELECT *`.
  - **Recuperação em ~1 minuto, e ela validou o desenho**: `device_param_writes` tinha `from_value = 189.22.240.43` gravado **antes** do despacho, então a volta foi imediata e sem depender da memória de ninguém. Valor reescrito e **conferido relendo da própria câmera**.
  - É exatamente o caso que justificou a regra. Desta vez foi um IP obviamente falso num equipamento de teste; num caso real seria uma câmera em campo, alcançável só por SMS.

### Documented
- ⚠️ **`aceito` não significa "valor aplicado".** O `33027` responde `ok` de **recebimento**; o valor real só se conhece **relendo**. Por isso `device_params.value_raw` não é tocado pela escrita — quem o atualiza é sempre uma leitura. Gravar o desejado como se fosse o lido faria a tela mentir exatamente quando o device recusou em silêncio.
- ⚠️ **`from_value` é o último valor LIDO**, não o valor vivo no instante da escrita. Duas escritas seguidas sem releitura registram o mesmo `from_value` — observado em campo. É o correto para o que o campo existe: a recuperação quer voltar ao último valor **verificado**.

## [Unreleased] — 4.9.13

**F2 do `PROJETO_PARAMETROS.md`**: a leitura vira automática e a frota ganha um relatório de configuração.

### Added
- **`scripts/param_sync_worker.php`** — lê a configuração das câmeras JT/T que nunca foram lidas (ou cuja leitura passou de 30 dias), a cada 5 min pelo cron. Teto de 20 por rodada.
  - 🔴 **É cron, e não gatilho dentro do webhook, por decisão de arquitetura.** Seria tentador disparar no `pushgps` quando o device aparece; não: o handler já devolveu 200 e processa em background, e abrir uma chamada HTTP ao IoT Hub ali acopla o tráfego de **todos** os devices à disponibilidade do hub. Numa frota que reconecta junto (queda de energia, virada de turno) vira tempestade, com cada comando segurando até 35 s. O cron dá enfileiramento, teto e backoff de graça.
  - **`_code:600` não é erro** e o worker trata assim — quem completa a leitura é o callback, pelo mesmo parser. O backoff separa `busy` (15 min; reenviar na hora recebe a mesma recusa, observado no homolog) de `offline` (1 h dobrando até 24 h). Após 5 tentativas o worker para e **deixa visível** — device desistido não pode ficar indistinguível de device que nunca entrou na fila.
  - `last_communication` entra como **ordenação, não filtro**: quem não fala há meses ainda merece uma tentativa (o comando fica em fila e é entregue na reconexão), só não na frente de quem está transmitindo agora.
- **Relatório `/relatorios/parametros`** — *Parâmetros da Frota*. O padrão é a **própria frota, por modelo** (a moda entre equipamentos do mesmo modelo), sem ninguém cadastrar nada; perfil declarado é a F3. Três decisões, todas contra o silêncio que parece aprovação:
  - modelo com **um** equipamento lido não tem padrão (a moda seria ele mesmo) — vai para *"sem base de comparação"* em vez de sumir;
  - **empate não elege vencedor**: sem maioria não há padrão, e sortear faria metade da frota aparecer como divergente;
  - **"achados de operação"** independem de comparação — `85 = 0` (sem limite de velocidade) e `94 = 0` (ângulo de capotamento zerado, o alarme não dispara) estariam errados **mesmo que a frota inteira estivesse assim**, que é exatamente onde a moda não ajudaria.

### Changed
- **O despacho ao IoT Hub virou ponto único** (`includes/iothub_command.php`). O worker precisava do mesmo despacho do `sendcommand.php`; copiá-lo repetiria o erro que este repositório já pagou três vezes — cópia divergente que ninguém percebe até uma das duas deixar de valer (o `worker.php` imprimiu código cru de alarme por meses assim). Ficou lá o que é igual para qualquer chamador; validação de proNo, escopo multi-tenant e injeção de credenciais de FTP continuam no handler, porque worker nenhum precisa disso.
- `param_moda()` saiu do handler para `includes/device_params.php`: é a regra que decide o que o relatório chama de "fora do padrão", e regra de decisão sem teste é como este repositório já perdeu junção por nome três vezes. **56 casos** no `device_params.test.php`.

### Verified
- **Worker rodado contra a frota real**: 2 lidos na hora (JC371 com 49 parâmetros, JC182 com 47), 3 enfileirados offline com backoff de 1 h aplicado.
- 🔴 **O caminho do callback foi provado em produção, com dado real**: o JC181 saiu como "fila offline" e o `/pushinstructresponse` completou a leitura sozinho — 6 parâmetros, **94 bytes, `JSON_VALID = 1`**. É o destruncamento da v4.9.11 valendo num callback de verdade, não em replay.

## [Unreleased] — 4.9.12

**F1 do `PROJETO_PARAMETROS.md`**: o sistema passa a saber como cada câmera JT/T está configurada, em vez de só poder mandar comando e torcer.

### Added
- **Três tabelas e um catálogo de 49 parâmetros.** `device_param_catalog` (o dicionário que transforma `"85":"110"` em *Velocidade máxima — 110 km/h*), `device_params` (estado atual, com `channel` na chave para o vídeo por canal) e `device_param_snapshots` (o `_content` bruto de cada leitura, append-only). Mais `devices.params_synced_at`/`params_sync_tries`/`params_sync_next` e `commands.pro_no`.
  - **Procedência explícita em `doc_ref`**: **27** publicados na Tabela 2.3.9.1, 2 de vídeo, **17 medidos sem doc** (nome honesto `Parâmetro NNN`, `writable = 0`) e **3 medidos com nome inferido do valor** (`16` = `cmnet` é o APN; `17`/`18` usuário e senha, com `is_secret`). A regra do repo é não batizar por palpite — `Parâmetro 128` é um nome honesto para algo que só se sabe existir, e ler `cmnet` não é palpite. A conferência da migração prova que **nada gravável tem nome genérico**.
- **Aba `Parâmetros` em `/ativos/{imei}`**, agrupada por categoria, com o bloco de vídeo por canal decodificado (resolução, bitrate, OSD) e botão **Ler agora**. **Só aparece para equipamento JT/T** — para câmera JIMI ela não existe, em vez de existir vazia: tela vazia o usuário lê como defeito do sistema, não como "não se aplica".
- `includes/device_params.php` — parser, upsert, rótulos e formatação, num ponto único usado pelos dois caminhos de captura. `tests/helpers/device_params.test.php` com **48 casos** fixados nas três respostas reais.

### Fixed
- 🔴 **O `cmdContent` estava errado nas quatro telas que o montavam** (`config_dispositivos.php`, `ativo_detalhe.php`, `comandos.php`) — e errado nas **três** formas: `{"paramId":1,"paramValue":"x"}` no 33027, `{}` no 33028 e `{"paramIds":[44,45]}` no 33030. O gateway **aceita** qualquer um deles (devolve `code:0`) e o device ignora: o defeito se apresentava como *"mandei e não aconteceu nada"*, sem erro em lugar nenhum. Corrigido nas telas **e normalizado no servidor**, que é por onde todo comando passa. O formato antigo do 33027 é **convertido**, não recusado.
- 🔴 **A resposta síncrona já trazia a configuração inteira e ninguém lia.** `sendcommand.php:335` capturava `data._content` desde antes; faltava o parser. Agora device online grava na mesma requisição, e device offline grava pelo callback — **decidido pelo `pro_no` do comando correlacionado, nunca por adivinhar o formato do conteúdo**, que gravaria configuração a partir de qualquer resposta JSON.
- 🔴 **`33028` era recusado com HTTP 400 três linhas antes da normalização que existe para montá-lo.** O `cmdContent` da consulta é vazio **por especificação**, e a validação de campo obrigatório não abria exceção. Pego pelo primeiro teste ponta a ponta contra câmera real.
- 🔴 **Um `33030` marcava o device como sincronizado.** Ele traz um punhado de parâmetros; carimbar `params_synced_at` com ele faria o worker parar de buscar o resto — device com 3 valores lidos e 43 nunca lidos, sem nada na tela denunciando. O caminho do callback já tinha a guarda; o síncrono não.
- **`UPDATE` da migração derrubava a própria migração**: `LIKE 'jtt\_%'` perde a barra dentro de string do MySQL e o `_` vira coringa, então `api_type = 'instruct'` entrava no `UPDATE` e o `CAST('uct' AS UNSIGNED)` abortava tudo em modo estrito. Trocado por `REGEXP '^jtt_[0-9]+$'`.

### Documented
- ⚠️ **O parser segue a CÂMERA, não a doc** — e as três divergências estão fixadas como teste: o campo de contagem é `paramCount` (a doc diz `totalNum`, que nenhum device mandou); os parâmetros de vídeo vêm num bloco `channel_N` (não na chave `119`); e `paramCount` **não** é o número de chaves de topo — o JC371 declara 87 e entrega 46.
- ⚠️ **O upsert nunca apaga o parâmetro ausente.** Ausência não é "desconfigurado": o `94` (ângulo de capotamento, o mesmo evento que a v4.9.10 batizou) é documentado e o JC371 não o devolveu. Um `DELETE` do que não veio apagaria configuração real a cada leitura parcial.

## [Unreleased] — 4.9.11

Abertura da **F1 do `PROJETO_PARAMETROS.md`** por dois consertos que valem por si e não dependem de nenhuma decisão do resto do blueprint.

### Fixed
- 🔴 **`command_responses.command_content` truncava a resposta do equipamento em 250 caracteres — e já estava perdendo dado.** A coluna era `varchar(250)` **e** `pushinstructresponse.php` fazia `substr($content, 0, 250)` antes de gravar. O campo que ela recebe é o `_content` do callback, que para a família `33028`/`33030` é a **configuração inteira do equipamento**.
  - **Não era hipótese**: a linha `id=14` do homolog tem `LENGTH(command_content) = 250` **exato** — uma resposta de VERSION do JC371 cortada no limite.
  - **Medido em câmera real** (12/08/2026): o `_content` de um `33028` do JC371 tem **612 bytes**. Perdiam-se 60%, e o que sobrava era JSON sintaticamente inválido — não dava nem para recusar direito.
  - **As duas correções eram necessárias, e o replay provou isso.** Com a coluna já `TEXT`, um callback replicado com os 612 bytes reais **ainda** gravou 250 e `JSON_VALID = 0`: quem cortava era o `substr` do PHP, não o banco. Alterar só a coluna teria deixado o defeito de pé com a aparência de corrigido.
  - O teto que ficou é o da própria `TEXT` (65000), não um limite de negócio: sem ele um payload acima de 64 KB faria o `INSERT` estourar e a linha **não seria gravada de jeito nenhum** — perda parcial viraria perda total.
- 🔴 **`/config` estava MORTA — e o defeito de rota escondia um defeito de permissão.** Dois problemas empilhados na mesma tela, a que consulta e reconfigura a câmera (proNo 33027/33028/33029/33030):
  - **A rota nunca chegava ao PHP.** Existe um diretório `config/` no docroot (o do PDO singleton), então o `mod_dir` do Apache redirecionava `/config` → `/config/` com **301** e tentava servir o **diretório**, que morre em **403** por `Options -Indexes`. Provado no log do servidor: `AH01276: Cannot serve directory /var/www/jimi_webhook/config/`. A linha `RewriteRule ^config$` do `.htaccess` era a tentativa de contorno e **não funciona** — o `mod_dir` se antecipa ao `mod_rewrite`. Rota renomeada para **`/config-dispositivos`** (arquivo `config_dispositivos.php`), que resolve sem brigar com o Apache e alinha com as irmãs `config-*`.
  - **A tela estava fora dos DOIS mapas de permissão** — quinta ocorrência da armadilha, depois de `checklist` e `wiki` (v4.8.5) e `config-notificacoes`/`config-smtp` (v4.8.9). Entra como `config-dispositivos` em `$screenByHandler` (router) **e** em `$screens` (grupos_permissao).
  - ⚠️ **Correção do que esta entrada afirmava antes**: *não* houve janela em que "qualquer usuário logado reconfigurava câmera". Ninguém alcançava a tela — o Apache barrava antes. A trava de permissão passa a ser o que impede a exposição **a partir de agora**, com a rota funcionando.
  - `.htaccess` ganhou a regra que faltava, em comentário: **nenhuma rota pode ter o nome de um diretório do docroot** (`config`, `core`, `includes`, `mysql`, `scripts`, `storage`, `tests`, `web`, `logs`, `docs`).
  - ⚠️ **Mudança de comportamento visível**: `can()` nega por omissão para quem tem grupo. Conferido no homolog — `Administrador` mantém (wildcard `*`), **`Operador Padrão` perde `/config`**. É o objetivo, e de propósito a migração **não** concede a tela de volta aos grupos existentes: devolver o que nunca deveria ter sido concedido anularia a correção. O admin libera pela tela de Grupos, onde a entrada nova agora aparece para marcar — que é exatamente o que a matriz incompleta impedia.

### Added
- **`PROJETO_PARAMETROS.md`** — blueprint da parametrização remota das câmeras JT/T (`33027`/`33028`/`33030`), com leitura automática na primeira conexão. A **§2 vale mais que a doc oficial**: as respostas foram medidas em câmeras reais do homolog e a doc está errada em três pontos — o campo de contagem é `paramCount` (não `totalNum`), os parâmetros de vídeo vêm em blocos `channel_N` (não na chave `119`), e `paramCount` não é o número de chaves de topo. Um parser escrito pela doc falharia calado nos três.

## [Unreleased] — 4.9.10

### Added
- **`Código 1047 (JTT)` virou `Capotamento`, e com ele saiu o ÚLTIMO alarme sem nome do sistema.** Havia dois, não um: `1047` (JT/T, 10 linhas, 05→12/08/2026) e `146` (JIMI, 4 linhas, 11/08/2026). A conferência que provava isso — os mesmos JOINs que `alarm_label_sql()` usa na leitura — agora devolve **zero linhas**.
  - **O que destravou o 1047 foi informação do fornecedor, não a doc.** A tabela oficial "Other Alarms" continua indo de `1046` (*Collision (ACDU)*) direto a `3073` (reconferido no HTML servido em 12/08/2026). A regra do `CLAUDE.md` sempre foi contra batizar por **palpite** — não contra catalogar o que se sabe. Duas evidências independentes sustentam: o próprio JT/T 808 põe capotamento ao lado de colisão no bitmask padrão (bit 27 `Pré-aviso de Colisão`, bit 28 `Pré-aviso de Capotamento`, já em `decodeStandardAlarm()`), e a faixa 1042–1046 segue a ordem das unidades ADAS (AHADU, AHBDU, AHTDU, … ACDU), onde 1047 é o passo seguinte.
  - 🔴 **O mesmo evento já existia no outro protocolo com nome errado.** JIMI `45` — *Vehicle tipped over onto its side* — estava gravado como **"Veículo Tumbado"** (espanhol, não português). Cadastrar `1047` como "Capotamento" e deixar `45` como está reproduziria o defeito que a v4.9.0 descreveu para os pares 1024/1042: **o filtro da tela casa por NOME**, então o usuário escolheria um dos dois rótulos e perderia metade dos eventos — os do outro protocolo. Os dois passaram a se chamar `Capotamento`, com o remapeamento de `occurrence_config_params` e `notification_rules` junto, como o `CLAUDE.md` exige.
  - **Categoria `acidente`, não `veiculo`** — não é inconsistência com `1046`: é seguir a classificação que o **mesmo evento** já tinha em JIMI `45`. Tem consequência medida: `notification_engine.php` casa a regra por categoria, e a regra ativa `acidente` (id 2) passa a disparar para capotamento. **Provado com a consulta do motor**, não presumido.
  - **`Capotamento` voltou ao perfil padrão de ocorrências** (`gera`, risco `alto`, janela 5 min — o mesmo do irmão `Airbag Acionado / Colisão`). A v4.0.0 o semeara; a v4.8.7 o apagou por "nome sem alvo no catálogo", que é exatamente o que esta versão derrubou. Sem ele, capotamento seria catalogado, apareceria no relatório e **não geraria ocorrência nenhuma** — `process_alarm_occurrence()` retorna cedo quando não há parâmetro. Nomear o alarme e parar aí seria meia correção, do lado invisível.
- **JIMI `144`/`145`/`146` — a geração nova de condução brusca.** A doc (`1.7 Driving Behavior Alerts`) publica os três; o catálogo tinha só os antigos `41` e `48`. Só `146` chegou até agora, mas os três vêm do mesmo grupo de firmware — cadastrar apenas o que já apareceu deixaria os outros dois caindo no rótulo genérico no dia em que aparecerem, que é o defeito que a v4.8.1 criou ao inserir 11 de 39 códigos. Nomes **idênticos** aos das linhas JT/T equivalentes (`1042`/`1043`/`1044`), de novo porque o filtro casa por nome. `146` passa a casar a regra ativa `conducao` (id 6).

### Fixed
- 🔴 **Três telas mostravam o código enquanto o Relatório de Alarmes já mostrava o nome** — liam `a.alarm_name` cru, que é **congelado na chegada do webhook**, em vez da re-resolução na leitura. É a mesma divergência que o `scripts/worker.php` teve por meses (v4.9.0), agora em outras três cópias: a **aba Alertas** de `/ativos/{imei}` (`ativo_detalhe.php`), o **relatório genérico** de alarmes (`relatorios.php`) e os **eventos da ocorrência** no Dashboard (`ocorrencias_dashboard.php`). As três passaram a usar `alarm_label_sql()`, o ponto único.
  - **Efeito colateral positivo nas duas primeiras**: o JOIN que elas usavam era um `IF(subtipo IS NOT NULL, composto, base)` — **sem fallback**. Alarme JT/T com subtipo fora do catálogo não casava nada, e com isso a **severidade caía para `info`** e o **filtro de categoria não casava**. Os joins do helper tentam composto e depois base.
  - Provado com sonda contra o homolog: as 14 linhas históricas saem como `Capotamento` (crítico, `acidente`) e `Curva Brusca` (aviso, `conducao`) nas três consultas, e a trava `WHERE <expr> LIKE 'Código %'` devolve **0**.

### Documented
- 🔴 **`Colisão do Veículo` NÃO dispara notificação, e isto ficou registrado sem ser corrigido.** `1046` (JT/T) e `147` (JIMI) estão na categoria `veiculo`, para a qual **não há regra**; `Airbag Acionado / Colisão` (JIMI `30`) está em `acidente` e dispara. Confirmado rodando a consulta de `resolve_notification_rule()` contra o homolog: colisão devolve **zero regras**. Mover colisão para `acidente` aumentaria o volume notificado de um alarme frequente — é decisão de produto, não de migração.

## [Unreleased] — 4.9.9

### Changed
- 🔴 **Evento de DIAGNÓSTICO deixou de ser tratado como alarme.** Diagnóstico é o que o equipamento diz ao **sistema** — handshake de upload de vídeo, entrada e saída de repouso, defeito de hardware — e não o que o veículo diz ao **operador**. Some das telas de alarme, ocorrência, Resumo e BI; a linha continua **inteira** em `alarms`, com `raw_data`.
  - **A medida que motivou**: de **5.112** alarmes no homolog, **5.073 eram ruído de infraestrutura** e **16** eram DMS/ADAS — o núcleo do produto. O relatório de alarmes, o gráfico do Resumo e o "top 3 equipamentos" descreviam a saúde do equipamento, não a operação.
  - **Classificado no CATÁLOGO, por CÓDIGO**: nova coluna `alarm_types.is_diagnostic`. Por código, não por nome, porque `alarms.alarm_name` é congelado na chegada e existe em variantes — os **845** `Fim de Alarme: …` carregam o mesmo código do alarme de abertura e são pegos de graça; por nome exigiria enumerar cada variante. E é a armadilha que o `CLAUDE.md` documenta três vezes: junção por nome morre em silêncio quando alguém renomeia.
  - **Falha para o lado de MOSTRAR**: `COALESCE(atc.is_diagnostic, atb.is_diagnostic, 0)`. Código fora do catálogo — `Código 1047 (JTT)` é o caso real — dá NULL nos dois JOINs; sem o zero final, um alarme novo desapareceria da tela sem erro.
  - **Visualização restrita ao administrador**, com checagem no servidor (`role === 'admin'` estrito, não o `$isAdmin` das telas de relatório, que inclui `revendedor`). O modo diagnóstico mostra **só** os técnicos — misturado com os 39 alarmes reais não serviria para conferir nada.
  - **Classificados**: `JIMI 105` Upload de Vídeo Concluído · `JT/T 1040/1041` Modo Repouso/Trabalho · `JT/T 257` Perda de Sinal de Vídeo · `JT/T 259` Falha no Armazenamento · `JT/T 256-2048` Falha de Câmera.
  - 🔴 **`Falha de Câmera` entrou como código COMPOSTO, e isso é uma trava de segurança.** Ela chega como o bitmask padrão JT/T (`256`) com subtipo `2048`. O mesmo código 256 carrega `Emergência / SOS` (bit 0) e `Excesso de Velocidade` (bit 1), e `decodeStandardAlarm()` **combina** os bits ativos num nome só — câmera + SOS chega como subtipo `2049`, código diferente, que **segue visível**. Marcar a base `256` teria escondido pedidos de socorro.
  - `alarm_types` ganhou a linha `256-2048` que **não existia**: sem ela `alarm_label_sql()` não conseguia sequer re-resolver o nome dessas 188 linhas.

### Fixed
- **Diagnóstico nunca gera ocorrência nem notificação.** Guarda em `process_alarm_to_occurrence()`, antes da busca de configuração. Hoje nenhum evento técnico tem parâmetro cadastrado, então não muda comportamento — existe porque a tela de config lista o catálogo inteiro e o operador não tem como saber quais códigos são técnicos. Uma guarda só, no ponto de estrangulamento: `notify_from_occurrence()` é chamada **exclusivamente** a partir daí.
- **`pushalarm.php` passa o `msg_class` adiante.** Sem o protocolo, a guarda consultaria a linha errada do catálogo — `105` é Upload de Vídeo em JIMI e outro alarme em JT/T (ADR-001).

### Notas de implementação
- `camerasdata.php` **não** foi filtrado, de propósito: ali `MAX(alarms.created_at)` é sinal de vida da API ("quando o gateway recebeu algo pela última vez"), e evento técnico é tráfego legítimo. Excluí-lo faria a API parecer offline.
- `tests/helpers/diagnostico_guard.test.php` — **12 casos** contra banco real, com as bordas que importam: `256-2049` (câmera+SOS) e `256` base seguem visíveis, código fora do catálogo segue visível, `105` em JT/T não é diagnóstico. Aborta com mensagem própria se a migração não estiver aplicada, em vez de acusar erro de classificação.
- ⚠️ **A primeira tentativa de testar a trava de permissão foi vácua**: o `UPDATE users SET role='operador'` falhou (a coluna é `ENUM('admin','operator','viewer')`) e o teste mediu o admin de novo, "provando" um vazamento que não existia. Refeito com `operator` e `viewer`, conferindo o valor gravado antes de medir.

## [Unreleased] — 4.9.8

### Fixed — achados NO NAVEGADOR, depois do primeiro deploy
- 🔴 **O snapshot do `.ts` parava no início e o vídeo seguia tocando mudo.** Com fonte MSE o `loadedmetadata` dispara **antes** de o mpegts.js publicar a duração; o código decidia ali, uma vez só, desistia da miniatura e liberava o play — deixando o vídeo rodando **mudo, do começo**, com o botão de play por cima. Medido: `duration 15.162` mas `currentTime 4.91` (devia ser 7.581), sem poster, `paused: false`. A tentativa passou a se repetir a cada evento que pode trazer a duração (`durationchange`, `canplay`, `progress`), com guarda de seek único, e o caminho de desistência agora **pausa** o vídeo. A captura do quadro também foi adiada para o frame seguinte ao `seeked`: em MSE o quadro raramente está pintado no instante do evento, e capturar ali fixaria um poster **em branco** por cima do vídeo. *Só o navegador podia pegar isto — o harness em Node passava.*
- 🔴 **As 9 abas de `/ativos/{imei}` renderizavam VAZIAS** (defeito anterior a esta sessão). `web/layout_ativo_sidebar.php` fazia `foreach ($tabs as $tab)` e com isso sobrescrevia a variável do **chamador**: `ativo_detalhe.php` guarda a aba pedida em `$tab`, inclui a sidebar e só então roda `switch ($tab)` — que a essa altura valia o último item do array. Em PHP 8, array comparado com string é sempre falso, então nenhum `case` casava. A sidebar continuava destacando a aba certa (usa `$current_tab`), o que fazia a tela parecer apenas "sem dados". Prova de que é antigo: render das duas versões (`7998b7a` e `HEAD`) devolveu **bytes idênticos**, 56192, com zero `<table>`. Corrigido nos dois lados — a sidebar usa `$abaItem` e o `switch` despacha por `$current_tab`.
- **A sidebar do ativo e o conteúdo da aba empilhavam** em vez de ficar lado a lado: a regra `.with-asset-sidebar .main-content-inner` apontava para um elemento **que não existe** no projeto (aquela linha era a única ocorrência do nome), então o `display:flex` nunca valeu. Invisível enquanto as abas vinham em branco.

### Added
- **Coluna Vídeo no relatório de alarmes** (`/relatorios/alarmes`). Mostra o anexo que o próprio equipamento declarou no push do alarme; abre num modal com o player embutido, sem sair da grade e sem baixar nada. O `<video>` é montado **no clique**, não uma vez por linha — 25 elementos com `preload="metadata"` abririam 25 conexões só para exibir a tabela.
- **Player com snapshot no detalhe da ocorrência.** O bloco de mídia abre num quadro do **meio** do vídeo (10 s → 5 s; 20 s → 10 s) e toca na própria tela.
  - O snapshot é capturado **no navegador**: o servidor **não tem ffmpeg** (conferido no homolog) e instalá-lo seria mais uma peça de infraestrutura fora do git. Como os anexos de evento têm 1–4 MB e `/midia` responde a `Range`, pedir ao `<video>` que decodifique o quadro do meio custa uma fração do arquivo e nenhuma dependência nova. O quadro é copiado para um canvas e fixado como `poster` — sem isso, o play (que volta a agulha para 0) pisca o primeiro quadro, quase sempre preto num vídeo DMS.
  - `.ts` passa pelo mpegts.js, carregado **só quando há MPEG-TS na tela**. Sem a biblioteca, nenhum navegador decodifica TS no `<video>` nativo e o player fica preto sem reclamar.
  - Novos: `includes/media.php` (ponto único de "por qual URL isto toca / que tipo é / está no disco?") e `web/components/video_player.php` + `video_player_assets.php`.

### Fixed
- 🔴 **"Sem vídeo vinculado" com o vídeo no disco** — no núcleo do produto. Em câmera JIMI o device sobe o vídeo do evento sozinho e anuncia o nome no **próprio push do alarme** (ADR-001); não há webhook de upload. `link_media_to_occurrence()` procurava esse arquivo em `media_files` e **nada no sistema o inseria ali** — só o fluxo JT/T (extração 37382 → `/pushftpfileupload`) cria a linha. Medido no homolog: 4 alarmes com anexo, **0** linhas em `media_files`, 5 de 8 ocorrências sem mídia, e **todos** os arquivos presentes em `/iothub/dvr-upload/uploadFile`.
  - Corrigido nos dois sentidos: o motor passa a **criar** a linha na chegada (`source_type = 'pushalarm'`, `download_status = 'disponivel'` — o alarme é a declaração do device de que o arquivo já está no storage), e `migration_v4.9.8.sql` faz o mesmo para trás, religando as ocorrências já gravadas.
  - O vínculo passou a valer também no ramo de **agrupamento**: numa rajada, quem traz o anexo raramente é o primeiro alarme.
  - A leitura do detalhe ganhou dois degraus de reserva (anexo de um dos alarmes agrupados → upload do mesmo equipamento na janela de ±3 min), porque o vídeo pode chegar **depois** de a ocorrência nascer.
- 🔴 **A busca do Dashboard de Ocorrências nunca devolveu nada.** O `$where` é montado uma vez e reaproveitado por três consultas, e só a última tem os JOINs: as de KPI e contagem são `FROM occurrences o` puro. Com o termo preenchido, o `dr.name` do filtro virava *Unknown column 'dr.name' in 'where clause'* já no KPI — o `catch` devolvia o payload zerado com `code: 0`, e a tela mostrava grade vazia e KPIs em zero **como se não houvesse ocorrência**. O predicado passou a ser todo em `EXISTS`, que não depende de JOIN nenhum.
- **`.ts` não era reconhecido como vídeo na chegada do alarme.** O regex de `pushalarm.php` conhecia `mp4|avi|flv|mkv` e nada mais, então todo anexo MPEG-TS — o formato das câmeras JT/T — gravou `alarms.file_type` NULL (metade dos anexos reais do homolog). Passou a usar `detect_media_type()`, o mesmo catálogo de extensões dos outros dois handlers de upload; e `media_kind()` resolve pela **extensão** na leitura, para acertar também nas linhas já gravadas erradas.
- **`/midia` recusava o anexo de alarme.** O escopo multi-tenant conferia só `media_files`; o anexo que vive em `alarms.file_url` dava 404 mesmo sendo do cliente logado. Agora as duas origens autorizam (com índice de prefixo em `alarms.file_url`, senão é varredura completa da maior tabela a cada byte servido).
- **`/ativos/{imei}` linkava mídia pela porta 23010** (`FILE_STORAGE_URL`), que serve os mesmos bytes **sem autenticação nenhuma** e com `Content-Disposition: attachment`. Passou por `/midia`, como o resto do sistema desde a v4.9.1. Nenhuma tela usa mais o `FILE_STORAGE_URL`.

### Changed
- **A ocorrência é identificada pela PLACA, não pelo IMEI.** Cabeçalho do detalhe ("Placa: FJR7B59"), coluna da grade do dashboard e busca — que agora casa a placa, justamente o que a tela exibe. `devices.device_name` é onde a placa mora; o IMEI volta só como último recurso, para equipamento sem placa cadastrada. O campo `imei` continua no payload de `/ocorrenciasdata` (integrações e `multitenant.spec.js` o usam).
- `/video/downloads`: a coluna "Equipamento" virou **Placa** e assumiu a primeira posição depois do arquivo, com o IMEI rebaixado a coluna secundária.

## [Unreleased] — 4.9.7

### Added
- **Tela de Comandos reorganizada em torno do MODELO do equipamento**, com catálogo extraído da wiki Foco na Via (`wiki-foconavia.newtectelemetria.com.br`) além da doc oficial.
  - **`includes/command_catalog.php`** — **119 comandos**, dos quais **87 com tabela de parâmetros** e **68 com exemplos**, cobrindo os 6 modelos do parque (JC371 94, JC182 50, JC400AD 42, JC400D 41, JC450 31, JC181 19). Gerado das 10 páginas da wiki, que usam **dois formatos diferentes** (`<strong>SINTAXE,P1,P2#</strong>` + tabela no JC371/JC182/JC450; tabela com `<mark>` e parâmetros `A`/`B` no JC400/JC400AD).
  - ⚠️ **Só entra a forma de PLATAFORMA.** A wiki documenta cada comando em duas formas — SMS (`SENALM#666666#ON#1`, com senha) e plataforma (`SENALM,ON,1`). O envio daqui é por **proNo 128** pelo IoT Hub, onde não há senha de SMS; mandar a forma de SMS faz o device recusar. O gerador converte e o teste afirma que **nenhuma** sintaxe do catálogo carrega `666666`.
  - **Multi-seleção com trava de modelo**: marcar um JC371 desabilita os equipamentos de outro modelo enquanto o comando escolhido for específico daquele modelo. O aviso diz *por quê* — enviar assim mesmo devolveria "comando não suportado" só minutos depois, no callback.
  - **Exceção do proNo 128 universal**: comando documentado em 5+ das 6 páginas (`STATUS#`, `VERSION#`, `REBOOT#`, `SERVER`…) é o núcleo comum do protocolo de texto. Com um desses escolhido a trava solta e o envio vale para a frota inteira. São **14** no catálogo.
  - **Campo por parâmetro**, com o que a documentação diz de cada um: descrição, **formato aceito** e **padrão de fábrica** (ex.: `DMSSP,P1,P2,P3,P4#` → P1 = função de IA, P2 = 10–120 km/h, P3 = canal, P4 = área de detecção). Exemplos da wiki viram chips clicáveis que preenchem os campos. O preview mostra exatamente o que sai.
  - **Placeholder não preenchido bloqueia o envio.** Antes, um campo em branco viraria `DMSSP,ADAS,P2,1,0#` — comando inválido despachado para o veículo.
  - **Envio em lote NÃO virou endpoint novo**: o frontend chama `/sendcommand` uma vez por equipamento. A checagem de posse por IMEI, o log e o registro em `commands` continuam idênticos; um endpoint de lote teria de reimplementar tudo isso num caminho crítico de despacho.

### Fixed
- **A resposta do comando chegava em inglês técnico de gateway.** `Device busy (previous command has not returned)`, `media resource is empty`, `request timeout` — sem tradução e embrulhados no envelope `{"data":{"_msg":…}}`. `includes/command_response.php` desembrulha e traduz para desfecho com **dica do que fazer** ("há um comando anterior sem resposta; espere e reenvie"). Casa por trecho, não por igualdade: o gateway varia a frase entre versões e uma tabela exata envelheceria em silêncio, voltando a mostrar inglês cru.
- 🔴 **`commands.status` não é o desfecho, e a tela tratava como se fosse.** Há linhas no homolog com `status = 'executed'` e resposta `"request timeout"` — o status registra que o **callback chegou**, não que o device obedeceu. O histórico passou a mostrar o desfecho **interpretado da resposta**; o status cru ficou no detalhe, onde não engana.
- **Resposta de dados vinha numa linha só, truncada.** `ext Battery:12.1V; GPRS:Link Up` era cortado na coluna e só aparecia inteiro no `title`. Agora vira grade de pares — a tensão da bateria é legível sem passar o mouse.
- **O histórico mostrava o JSON cru do payload** numa coluna de 180 px. Passou a mostrar o **nome** do comando (resolvido pelo catálogo, ou pelo proNo em JT/T), com a placa, o modelo, o **tempo até a resposta** e filtros por equipamento e por desfecho.

### Notas de implementação
- **A lógica da tela foi testada executando o JS REAL da página**, extraído do HTML renderizado (não uma cópia), sobre um DOM mínimo em Node: **12 asserções**, incluindo trava estrita (`DISCAMERA`, só JC371 → só JC371 habilitado), trava de dois modelos, liberação pelo universal, desmarcação automática ao trocar de comando, montagem da string e integridade do catálogo.
  - ⚠️ **O teste pegou um erro do TESTE, não do código**: a primeira versão presumia que `DMSSP` era exclusivo do JC371 e acusou falha por o JC450 seguir habilitado. A wiki documenta `DMSSP` nos **dois** modelos — o comportamento estava certo. A asserção passou a conferir contra a lista real do comando em vez de um valor fixo.
- **Render de verdade contra o banco do homolog** (somente leitura), com auth e layout stubbados: 244 KB de HTML, 119 comandos e 112 linhas de histórico, e os **5 blocos JSON embutidos** (`CATALOGO`, `DEVICES`, `JTT`, `LINHAS`, `ROTCAT`) validados — JSON quebrado ali mata a tela no navegador e `php -l` não veria.
  - Foi esse render que pegou `video_stream_config()['ip']`/`['port']`, que não existem: as chaves são `ingest_ip`/`ingest_port`. `php -l` passa limpo num acesso a chave inexistente.
- ⚠️ **`tests/comandos.spec.js` foi escrito mas NÃO executado**: a suíte precisa de servidor + banco locais, e o MySQL de desenvolvimento desta máquina não tem mais data dir. O spec pula sem `TEST_EMAIL`/`TEST_PASSWORD`, e **spec que pula não é cobertura** — a verificação desta entrega veio do harness em Node e do render, não dele.
- Duas armadilhas do parser da wiki, registradas por serem do tipo que falha calada: `json_encode` devolvendo `false` e gravando **arquivo vazio** sem erro (agora o gerador aborta), e `trim($s, " \t-–—:")` cortando **bytes** — os travessões são multibyte e o `trim` comia o `e2` inicial de um `⚠️` vizinho, deixando uma cauda inválida que derrubava o encode.

## [Unreleased] — 4.9.6

### Fixed
- 🔴 **Nenhuma regra de notificação disparava para DMS ou ADAS de JT/T** — o núcleo do produto. `pushalarm.php` entrega aos motores o código **base** (`alarm_type = '264'`), porque o subtipo mora em coluna separada (`alarms.alarm_subtype = 4`); mas `alarm_types.alarm_code` guarda o **composto** (`'264-4'`). Comparando só a base, o ramo `at.alarm_code = :atype` nunca casava para essas famílias.
  - **Por que passou despercebido**: os alarmes JIMI têm código simples (207, 71, 132) e casavam normalmente, assim como os JT/T sem subtipo (`1027` = Excesso de Velocidade). O sintoma era "câmera JIMI notifica, câmera JT/T não" — sem erro em log nem na tela.
  - **Por que só a notificação sofria**: regra/parâmetro gravado por **nome** casa pelo ramo `= :aname` e sempre escapou do defeito. Os 22 parâmetros de ocorrência são por nome; as **6** regras de notificação são por **categoria**, que só tem o ramo de código para casar. Medido: o motor de ocorrências resolvia certo nos dois protocolos enquanto o de notificação falhava em todo `264`/`265`.
  - Corrigido nos **dois** motores (a estrutura de matching é a mesma e a divergência é só de dado): `alarm_subtype` viaja de `pushalarm.php` → `process_alarm_to_occurrence()` → `notify_from_occurrence()`, e as consultas passaram a casar também pelo código composto.
  - **Verificado com regras e catálogo reais do homolog, antes e depois.** Antes: `264`→nenhuma, `265`→nenhuma, `207`→regra #4, `1027`→regra #6. Depois: `264-4`/`264-1`/`264-2`→regra #4 (ADAS), `265-1`/`265-10`/`265-3`→regra #3 (DMS), com JIMI e JT/T-sem-subtipo **inalterados** e código fora do catálogo continuando a não casar nada.
  - **O elo que o teste SQL não cobria foi conferido à parte**: `$subType` é o mesmo valor que já era gravado em `alarms.alarm_subtype`, e há linhas reais no banco provando que ele chega populado (`265-1`, `265-4`, `265-5`, `265-10`), todas presentes no catálogo.
- 🔴 **Regressão da v4.9.5 na lista de regras**: a coluna "Alarme" imprimia `alarm_type` cru, então depois da normalização de categorias ela passou a mostrar `conducao`, `seguranca`, `emergencia` — slugs sem acento. Antes mostrava `Driving`/`Security`: a v4.9.5 trocou inglês por slug feio nessa coluna. Agora a lista mostra o mesmo rótulo do formulário, com selo **Categoria** distinguindo regra de categoria inteira de regra de evento — dois alcances muito diferentes que a tela não separava.
- **Regra morta agora é visível.** Uma regra cujo `alarm_type` não casa com nome, código nem categoria nunca dispara, e falha calada é o modo de falha caro deste sistema. A lista marca essas linhas com "⚠ não corresponde a nenhum alarme — esta regra nunca dispara", em vez de deixar o usuário descobrir pela ausência de aviso.
- **"Medio" sem acento chegava ao usuário em quatro lugares**, um deles o **export** do Relatório de Ocorrências (PDF/Excel que vai ao cliente). O componente `status_pill.php` já acentuava certo; o resto usava `ucfirst()` no valor do enum. Unificado em `occurrence_risk_label()` — `config_notificacoes.php`, `rel_ocorrencias.php` (tela **e** export) e `bi.php` (rótulo do gráfico).

### Notas de implementação
- Mudança **só de código**, sem migração: `system_info` permanece em `4.9.5` e o `SYSTEM_VERSION` vai a `4.9.6`. É a distinção que o `deploy.sh` usa.
- As sondas de verificação são cópias **literais** das consultas dos dois motores, rodadas contra o banco do homolog em modo leitura, com as entradas que `pushalarm.php` realmente passa (código base + nome resolvido) — não com entradas convenientes.
- ⚠️ **Não é possível afirmar quantas notificações se perderam.** As 26 notificações no homolog são anteriores às regras atuais e há seeds de teste na base (`tests/helpers/seed_notification.php`), o que confunde qualquer contagem histórica. O que está provado é o comportamento do matching, antes e depois.

## [Unreleased] — 4.9.5

### Fixed
- 🔴 **O cadastro de ocorrências listava o MESMO evento uma vez por protocolo.** "ADAS: Colisão com Pedestre (PCW)" aparecia duas vezes — JIMI 207 e JT/T 264-4 — como se fossem dois eventos, e "ADAS: Colisão Frontal (FCW)" aparecia **três** (JIMI 204, JIMI 229, JT/T 264-1). O dropdown era montado com uma linha por linha de catálogo, e o catálogo tem uma linha por protocolo (às vezes duas no mesmo protocolo, para gerações diferentes de firmware).
  - **A escolha entre as duplicatas sempre foi indiferente**, o que torna o ruído pior: o parâmetro é gravado pelo **nome** (`occurrence_config_params.alarm_type`) e `get_occurrence_param()` casa por nome, então **uma** linha já cobria todos os protocolos e códigos. O usuário escolhia entre opções idênticas sem ter como saber disso.
  - Agora a consulta agrupa por `alarm_name_pt` e mostra os códigos agregados: `ADAS: Colisão com Pedestre (PCW) — JT/T 264-4 · JIMI 207`. Os códigos continuam visíveis porque são o que o instalador confere contra a doc do fabricante. **Medido: 83 opções → 67, com 18 duplicatas eliminadas e zero opções repetidas.**
  - Mesma correção em `/config-notificacoes`, que tinha o dropdown idêntico com o mesmo defeito.
- 🔴 **A categoria do alarme estava dividida por protocolo E por idioma.** As linhas JIMI usavam categoria em inglês e as JT/T em português, para o mesmo conceito: `Driving`×`conducao`, `Security`×`seguranca`, `Vehicle`×`veiculo`, `Device`×`dispositivo`, `Geofence`×`cerca`, `Emergency`×`emergencia`. Como o `<optgroup>` sai de `category`, o usuário via "Driving" e "Condução" como grupos separados, com o mesmo evento nos dois.
  - As duas coisas eram **o mesmo defeito**: os únicos **6** nomes que cruzavam mais de uma categoria cruzavam exatamente esses pares. Depois da unificação, **nenhum** nome cruza categoria — é o que torna o agrupamento por nome inequívoco, e a migração verifica isso em vez de supor.
  - ⚠️ **O remap de `notification_rules` não era opcional.** `notification_engine.php` casa a regra por `at.category = nr.alarm_type`, e as **6** regras do homolog casam **todas** por categoria, nenhuma por nome. Renomear a categoria sem remapear teria desligado as notificações em silêncio — o modo de falha que a v4.8.3 causou no motor de ocorrências. `UPDATE IGNORE` + limpeza por causa da `UNIQUE KEY (customer_key, alarm_type)`.
  - **Efeito colateral desejado**: como as regras deixaram de estar presas ao protocolo, três delas passaram a cobrir mais alarmes — `conducao` 8 → **15**, `seguranca` 11 → **14**, `emergencia` 1 → **2**. Antes, um alarme de condução vindo de câmera JT/T não disparava a regra "Driving" do cliente.
  - A cláusula de filtro da tela foi reescrita junto (`'Driving','Accident'…` → `'conducao','acidente'…`): mantida a antiga, a lista teria encolhido para DMS/ADAS mais o que a severidade pegasse. De quebra, **2 eventos que a lista nunca ofereceu** entraram (`Curva Brusca` e `Desaceleração Brusca`, JT/T puros, invisíveis porque a cláusula só citava o `Driving` inglês).
- **Textos em inglês que chegavam ao usuário, traduzidos na exibição**: a badge de severidade do Detalhe do Ativo e do Relatório de Alarmes imprimia `critical` / `warning` crus; o filtro de severidade oferecia "Critical/Warning/Info"; o filtro de categoria mostrava o valor cru. Agora `Crítica`, `Atenção`, `Informativa`, `Condução`, `Segurança`…
  - A tradução é **na exibição**, por `alarm_category_label()` / `alarm_severity_label()` / `protocol_label()` em `includes/functions.php`, nunca gravando o rótulo na coluna: `category` e `severity` são **chave de junção** e valor de comparação SQL. É a lição da v4.8.3 aplicada preventivamente.
  - `DMS` e `ADAS` não são traduzidos — são siglas do setor, e `rel_alarmes.php` filtra por `category IN ('DMS','ADAS')`. Ganham só o significado expandido no rótulo ("DMS — Monitoramento do Motorista").
  - `JTT` passa a ser exibido como **JT/T**, a grafia que o resto do sistema já usava. Duas grafias faziam parecer protocolos diferentes.

### Added
- **Guarda de evento único no salvamento do perfil.** Nada impedia adicionar duas linhas para o mesmo evento com regras conflitantes, e `get_occurrence_param()` fecha com `LIMIT 1` — o perfil passava a depender da ordem das linhas no banco. A primeira linha vence e o aviso diz quantas foram descartadas.

### Notas de implementação
- **Testado em cópia real do homolog, não em fixture inventado**: `mysqldump` de `alarm_types` (148), `notification_rules` (6), `occurrence_config_params` (22) e `occurrence_configs` para instância MySQL isolada. Migração rodada **duas vezes** com saída idêntica; as 4 conferências (regra órfã, parâmetro órfão, nome cruzando categoria, categoria em inglês) devolvem zero linhas.
- **O "antes" foi reconstruído, não estimado**: o dump original foi recarregado num segundo banco e a consulta **antiga** rodada contra ele — 83 opções para 65 eventos distintos. A conta fecha exata com o depois: 65 + 2 recuperados = **67**.
- ⚠️ **A conferência de categoria em inglês precisou de `BINARY`.** A primeira execução acusou `sensor` e `video` como "ainda em inglês": a collation `utf8mb4_unicode_ci` ignora caixa, então `IN ('Sensor','Video')` casava os valores já corrigidos. Sem o `BINARY` a migração denunciaria erro onde não há — e, pior, a conferência não distinguiria os dois estados.
- **Cadeia de `require` conferida à mão**, não presumida: os 4 handlers que passaram a usar os helpers chegam a `functions.php` via `auth.php:3`. `php -l` não pega `require` faltando (lição da v4.8.x).
- Helpers exercitados nos limites: categoria desconhecida volta capitalizada em vez de sumir (denuncia categoria nova sem tradução), `Video` de base antiga ainda resolve para `Vídeo` (busca sem diferenciar caixa), severidade nula vira `Informativa`.

## [Unreleased] — 4.9.4

### Fixed
- 🔴 **Os relatórios agendados chegavam assinados como "Jimi Tracker".** O produto se chama `bycamera` desde a v4.8.0, e aquela versão trocou "o remetente padrão de e-mail" — mas trocou **um** dos três lugares que decidem o nome, e não o que vencia. A precedência de `mail_config()` é **banco → `.env`**, então enquanto a linha de `smtp_settings` existisse com o nome antigo, nenhuma mudança em PHP apareceria na caixa de entrada. As três camadas:
  - **`smtp_settings.from_name`** — a coluna foi criada na v4.4.1 com `DEFAULT 'Jimi Tracker'`. Todo `INSERT` que omitisse a coluna gravava o nome antigo **sozinho**, sem ninguém digitá-lo; foi assim que o homolog ficou com ele. Este era o caminho efetivo. `migration_v4.9.4.sql` troca o DEFAULT e atualiza as linhas.
  - **`includes/mailer.php`** — os **dois** fallbacks (`?? 'Jimi Tracker'` no ramo do banco e `?: 'Jimi Tracker'` no ramo do `.env`). Corrigir só um deixaria o outro reintroduzindo o nome conforme o ambiente.
  - **`.env.example`** — `SMTP_FROM_NAME` comentado com o valor antigo, que vira o nome de qualquer instalação nova que descomente a linha.
  - **O `UPDATE` da migração é deliberadamente estreito**: casa a string exata `'Jimi Tracker'` e nada mais. Quem personalizou o remetente com o nome da própria transportadora **não é tocado** — sobrescrever isso seria trocar um nome errado por outro. Provado com duas linhas: a que tinha o valor do DEFAULT virou `bycamera`, a personalizada sobreviveu intacta.
- **O rodapé dos e-mails dizia "JIMI Tracker" em dois templates** (`scripts/worker.php`): "Envio automático do JIMI Tracker" no relatório agendado e "Mensagem automática do JIMI Tracker" no alerta de notificação. É o texto que o destinatário lê no fim de toda mensagem que o sistema manda.
- **Cabeçalho `X-Mailer: JIMI Webhook System`** e o boundary MIME `jimi_…` — aparecem no código-fonte da mensagem, que é o que se olha ao diagnosticar entrega.
- **User-Agent do geocode** (`JimiWebhook/4.8`) — é como o sistema se identifica ao servidor de endereços. Passou a `bycamera/4.9`.

### Changed
- **Wiki (`/wiki`) atualizada para o estado atual**, com o que as v4.8.x/v4.9.x mudaram **para quem usa** — estava parada em 30/07/2026 (v4.7.1):
  - **"O que vale para todos os relatórios"** ganhou três linhas que valem para todas as telas: o filtro de equipamento é **lista de placas** (não caixa de digitação), o local aparece como **endereço** em vez de latitude/longitude, e há uma **coluna Mapa** cujo link **funciona para quem recebe o arquivo**, sem conta no sistema.
  - **Alarmes**: filtro e ordenação por **placa**; o mapa da consulta inteira; e um callout explicando o `Código 1047 (JTT)` — que o fabricante não documenta o código e que o sistema mostra o número **em vez de inventar um rótulo**, com o registro sendo válido mesmo assim. Sem isso, o usuário lê o número como defeito.
  - **Ocorrências (relatório)**: filtro e coluna viraram **placa**. O **Dashboard** de Ocorrências e o drill-down de Desatualizados continuam mostrando IMEI — conferido no código, não presumido, e por isso a wiki continua dizendo IMEI nos dois.
  - **Desatualizados**: a exportação é da **frota completa** mesmo com uma faixa aberta, com callout dizendo que isso é proposital.
  - **Vídeo ao vivo**: o painel de informações (placa, canais, última comunicação em BRT, status) e a regra de que ele **acompanha a lista** — placa divergente é defeito, não defasagem.
  - **Playback**: a extração descrita como o fluxo que funciona hoje, com barra de progresso e busca no vídeo; callout de que o arquivo extraído entra na linha do tempo **na data em que foi gravado**, não na data da extração (era a dúvida que a v4.9.2 corrigiu no código); e que extrair depende da câmera conectada.
  - **Servidor de E-mail**: diz que o nome do remetente vem preenchido como `bycamera` e que **é esta tela** que decide como a mensagem chega — o lugar para onde mandar quem vir um nome errado no e-mail.
- **`AGENTS.md` e a skill `db-setup` voltaram a listar todas as migrações** — as duas paravam na v4.8.7 e omitiam v4.8.9 e v4.9.0. Um fresh install seguindo a lista subiria sem a matriz de permissão de duas telas e sem 28 nomes de alarme.

### Notas de implementação
- **Verificação em banco real, não por leitura do código.** Instância MySQL limpa, `smtp_settings` recriada **a partir do DDL da própria v4.4.1** (portanto com o `DEFAULT 'Jimi Tracker'` original) e duas linhas: uma que recebeu o DEFAULT sem ninguém digitá-lo — o cenário do homolog — e outra personalizada (`Transportadora Silva`). Depois da migração: a primeira virou `bycamera`, a segunda **intacta**, o `COLUMN_DEFAULT` do `information_schema` é `bycamera` e `system_info` marca `4.9.4`. Rodada **duas vezes** com saída idêntica.
- **A prova de que o bug acabou é o cabeçalho da mensagem, não o valor no banco.** Com o app apontado para a base migrada, `mail_build_message()` produziu `From: bycamera <a@x.com>` e `X-Mailer: bycamera`; `mail_config()` no escopo do cliente devolveu `Transportadora Silva`, confirmando que a precedência banco→`.env` continua funcionando e que a personalização sobrevive.
- ⚠️ **Conferir o `.env` do servidor no deploy.** A migração e o código cobrem banco e fallback, mas um `SMTP_FROM_NAME=Jimi Tracker` escrito à mão no `.env` do homolog venceria o fallback do `.env.example`. O `.env` local não tem nenhuma linha SMTP; o do servidor não foi inspecionado daqui. A conferência da migração devolve as linhas com qualquer variação de "jimi" restante.
- **HTML da wiki validado estruturalmente** (`DOMDocument` sobre a região de conteúdo, 102 KB): 0 erros. As **42** âncoras do índice resolvem para as 42 seções — nenhum item do menu lateral leva a lugar nenhum.
- Nomes técnicos **não** foram tocados, pelo mesmo motivo da v4.8.0: o banco `jimi_tracker`, o badge de protocolo `JIMI` (`msgClass=0`), `jimicloud.com`, o cookie `jimi_token`, as chaves de `localStorage` e `jimi-tracker-upload-process` (nome real do serviço de FTP do fornecedor). Os ~100 docblocks `JIMI Webhook System —` ficaram como estão por decisão explícita: é o nome do repositório, não da marca, e renomeá-los tocaria quase todo o projeto sem mudar nada para o usuário.

## [Unreleased] — 4.9.0

### Fixed
- 🔴 **A coluna "Mapa" saía VAZIA em todo XLSX exportado.** A célula de link era escrita como `<c t="str"><f>HYPERLINK(…)</f></c>` — fórmula **sem valor em cache**. O `<v>` não é opcional no Office Open XML: sem ele a célula só ganha conteúdo depois que o programa **recalcula** a planilha, e quem abre em visualizador que não recalcula (preview do Google Sheets, Numbers, painel do Windows, LibreOffice conforme a configuração) vê a coluna sumir. Era por isso que o link aparecia no PDF — que não depende de recálculo — e não na planilha, e por isso o Relatório de Posições parecia "não ter" a coluna que o PDF tinha. Corrigido em `XlsxWriter::writeRow()`, num ponto só: vale para os **onze** relatórios que exportam link.
- 🔴 **O Relatório de Alarmes imprimia `Código NNNN (JTT)` no lugar do nome.** `pushalarm.php` resolve o nome **uma vez**, na chegada do webhook, e grava o resultado em `alarms.alarm_name`; código ausente de `alarm_types` naquele instante fica gravado como rótulo genérico **para sempre**, mesmo depois de o código entrar no catálogo. Duas correções, e as duas são necessárias:
  - **Resolução na leitura** (`rel_alarmes.php`): o nome gravado continua vencendo sempre que for um nome de verdade; só o rótulo genérico é trocado pelo do catálogo. Preferir o catálogo cegamente apagaria o prefixo **"Fim de Alarme: "** (evento de fim de alarme — 494 linhas no homolog) e o **bitmask decodificado do JT/T 256** ("Excesso de Velocidade + Fadiga…"), que o catálogo não sabe reproduzir. O filtro de tipos passou a casar contra o nome **resolvido**, senão marcar o chip de um alarme recém-nomeado devolveria zero linhas.
  - **Catálogo completo** (`migration_v4.9.0.sql`): a v4.8.1 abriu a lista branca da poda para toda a faixa JT/T 1024–3097 mas **inseriu só onze** desses códigos. Entram os **28** restantes que a doc oficial publica em *2.7 Other Alarms*.
  - ⚠️ **`1047` continua sem nome porque a doc oficial não o publica.** A tabela vai de 1046 (*Collision*) direto a 3073; o código aparece em 6 linhas do homolog. Batizá-lo por palpite seria pior do que mostrar o número — a lição da v4.8.3/v4.8.6 é que nome de alarme é chave de junção, não legenda.
- 🔴 **Sobra da v4.8.3 que a v4.8.6 não pegou: o parâmetro de ocorrência "Bateria Fraca" estava órfão.** Achado pela conferência de órfãos que esta migração roda em si mesma, num banco real. A v4.8.3 renomeou o código JIMI 14 para "Tensão da Alimentação Externa Baixa"; a v4.8.6 remapeou 21 parâmetros e deixou este. Desde então o alarme chegava, era gravado, aparecia nos relatórios — e **ocorrência nenhuma nascia**, sem erro no log nem na tela. Exatamente o modo de falha que o `CLAUDE.md` descreve. Remapeado; a conferência agora devolve zero linhas.
- **O link "Rota" do Relatório de Deslocamento não abria para quem recebia o arquivo.** No PDF e no XLSX ele apontava para `/relatorios/deslocamento/rota`, tela **atrás de login** — o destinatário do e-mail sem conta no sistema caía na tela de login. E o OSM público **não sabe desenhar um percurso a partir de uma URL**: aceita marcador (`?mlat/?mlon`) ou uma rota **recalculada** pelo motor de rotas entre dois pontos, que não é o caminho que o veículo fez. Trocado por duas colunas — **Mapa (partida)** e **Mapa (chegada)** — que abrem para qualquer um. O traçado real continua na tela, para quem tem login, com os balões A/B já nomeando partida e chegada com data/hora.

### Changed
- **Todo filtro de equipamento virou `<select>` de PLACA.** Eram caixas de texto de IMEI em Ocorrências, Cercas, Status da Frota, Paradas, Ociosidade, Ignição e Excesso de Velocidade. Duas consequências além da ergonomia: o parâmetro passou de `LIKE '%…%'` para **igualdade** (com `LIKE`, escolher a placa cujo IMEI é sufixo do de outra trazia as duas, sem avisar), e a lista respeita o escopo do usuário — `report_device_options()` é a companheira de `report_customer_scope()` pelo mesmo motivo que `report_customer_options()` já era. O nome do campo na URL continua `imei`: links antigos, modelos salvos em `report_templates` e os e-mails de agendamento carregam essa chave.
- **Paradas, Ociosidade, Ignição e Excesso de Velocidade: a PLACA é a primeira coluna**, na tela e nos exports; **IMEI e Cliente saíram** das duas superfícies. O IMEI vinha como segunda linha embaixo da placa em toda linha da grade; o Cliente repetia o mesmo nome em toda linha de uma tela que já roda dentro de um cliente. Os quatro ganharam a coluna **Mapa** no XLSX/PDF, que só a tela tinha.
- **Status da Frota**: "Equipamento" virou **Placa** no PDF/XLS, sem a coluna IMEI e com o link do mapa.
- **Ocorrências**: a coluna IMEI virou **Placa** na tela, no PDF e no XLS (`COALESCE(devices.device_name, o.imei)`).
- **Cercas**: coluna IMEI fora do PDF e do XLS nas duas modalidades.
- **O balão do mapa do Relatório de Posições mostra a data/hora**, não a placa. O relatório é de **um** equipamento, então a placa era idêntica em todos os marcadores e não distinguia um ponto do outro; o que se quer saber ao clicar num ponto do trajeto é *quando* o veículo esteve ali.

### Added
- **Relatório de Desatualizados ganhou PDF e XLS da frota completa.** A tela só sabia exportar a faixa aberta no drill-down; a grade principal — a frota inteira ordenada por tempo sem transmitir, que é a que se leva para a reunião — não tinha nem um nem outro. O botão do topo exporta sempre a frota completa (o `bucket` sai da query de propósito, senão ele mudaria de significado assim que uma faixa fosse aberta).
- **Mapa embutido em Alarmes e Desatualizados**, no mesmo padrão do "Ver posições no mapa" do Relatório de Posições. O balão leva **placa + data/hora** (e o nome do alarme, em Alarmes) — aqui cada marcador é de um veículo diferente, ao contrário de Posições.
- **`export_map_link()`** e **`report_device_options()` / `report_device_select()`** — os três pontos únicos do link de mapa e do seletor de placa, que estavam copiados em nove handlers.

### Removed
- **O tour de boas-vindas e o botão "Ver tutorial" saíram do `/resumo`.** A documentação do produto é a **wiki** (`/wiki`); manter dois canais garantia que um dia o overlay dissesse uma coisa e a wiki outra. Saíram junto o CSS, o overlay, o JS e a chave `jimi_tour_seen_v4` no `localStorage` — e a menção ao tour na própria wiki, que descrevia uma tela que deixou de existir.
  - O teste que dependia dele (`notificacoes.spec.js`) plantava a chave no `localStorage` para escapar do overlay que interceptava o clique no sino. A asserção mudou de `toBeHidden()` para **`toHaveCount(0)`**: `toBeHidden()` passa por vacuidade num seletor que não casa nada, e passaria igual se o overlay voltasse.

- **Os relatórios AGENDADOS (`scripts/worker.php`) foram alinhados às telas** — os dez tipos. É o mesmo relatório entregue por e-mail, e ele vinha com cabeçalhos próprios (`IMEI / Equipamento / Cliente`), sem coluna de mapa e sem pesos de coluna no PDF. Agora: placa primeiro, sem IMEI, sem Cliente, com o link do mapa e com as mesmas larguras relativas das telas.
  - 🔴 **O relatório agendado de Alarmes imprimia `alarm_type` CRU** — o código numérico, sem nem o rótulo genérico que a tela tinha. Passou a usar a mesma resolução de nome; provado com linha plantada: `Código 1046 (JTT)` → `Colisão do Veículo`.
  - **A resolução de nome virou ponto único** (`alarm_label_sql()`, em `includes/functions.php`), compartilhada pela tela e pelo worker. Duas cópias de uma regra dessas divergem — foi o que aconteceu, e é o motivo de esta correção existir.
  - **O CSV do worker convertia a célula de link em só "MAPA"**, perdendo a URL: ele chama `fputcsv()` direto, sem passar por `stream_export()`, e o `__toString()` do `ExportLink` devolve o rótulo. Agora converte para a URL, como o caminho síncrono já fazia.
  - **Exceção deliberada**: o relatório de **Equipamentos** mantém a coluna IMEI (ganhou a Placa na frente). É o inventário do parque — sem o IMEI, a lista não serve para quem cuida dos equipamentos.
  - **Não houve paridade total de colunas**, de propósito: Posições continua com Bateria em vez de Motorista/Sinal GPS (o Motorista da tela exige um `JOIN` em `trips` que sairia caro num período de 30 dias), e Ocorrências mantém as colunas de auditoria `Tratado por` e `Notas`, que a tela não tem e são a razão de se receber essa versão por e-mail.

### Notas de implementação
- **Verificação**: `php -l` limpo em `handlers/ config/ core/ includes/`; os **11 relatórios** abertos contra MySQL local sem `Fatal error`, `PHP Warning` ou `SQLSTATE`; cabeçalho de **todos** os exports conferido em CSV; **9 PDFs** gerados com assinatura `%PDF-` e anotações `/Link` presentes; o XLSX real do Relatório de Alarmes inspecionado célula a célula confirmando `<f>HYPERLINK(…)</f><v>MAPA</v>`.
- A resolução de nome foi provada com **três linhas plantadas**: `Código 1046 (JTT)` → `Colisão do Veículo`; `Fim de Alarme: Código 3094 (JTT)` → `Fim de Alarme: Cartão SD Corrompido` (**prefixo preservado**); `Código 9999 (JTT)` (fora do catálogo) → **inalterado**, mostrando o número em vez de inventar um nome. O filtro por chip devolveu só a linha certa.
- `migration_v4.9.0.sql` rodada **duas vezes** em banco real com saída idêntica e zero órfãos.
- **Suíte Playwright: 110 passaram, 2 puladas, 0 falharam** (17,5 min) — os 99 do baseline da v4.8.9 mais os 11 testes novos; as 2 puladas são os skips deliberados de sempre.
- Os relatórios agendados foram verificados **executando o worker de verdade**, com os 10 tipos enfileirados como job real: CSV com a URL crua na coluna Mapa, XLSX com `<f>HYPERLINK(…)</f><v>MAPA</v>`, PDF com assinatura `%PDF-` e anotações `/Link` presentes.
- ⚠️ **Rodar a suíte localmente exige `NOMINATIM_URL` apontando para algo que recuse rápido.** O default (`10.1.0.15:8080`) é a LAN do homolog e não existe na máquina de desenvolvimento: cada página de relatório paga o timeout de 8 s do cURL e a suíte passa de 17 para mais de 45 minutos. Subir o servidor de teste com `NOMINATIM_URL=http://127.0.0.1:9` resolve e não muda o que se testa — a coluna Endereço já saía vazia pelos timeouts.

## [Unreleased] — 4.8.9

### Fixed
- 🔴 **Usuário sem vínculo em `customer_users` recebia o cliente de OUTRO tenant.** `get_available_customers()` (`includes/auth.php`) caía num fallback que devolvia o **primeiro cliente ativo da base** (`ORDER BY name LIMIT 1`) com role `viewer`. A v4.8.5 registrou isto como "residual consciente" e adiou; medido agora com usuários reais dos cinco perfis, era pior do que a nota dizia:
  - Não é só a lista do seletor do cabeçalho. **`customer_switch.php` usa o retorno da função como autorização** para trocar de cliente — a checagem dele é "o id está nesta lista?". Logo, o cliente vazado era **assumível**, não só visível.
  - Baseline medido revertendo o código (não deduzido dele): **operador, viewer e os dois perfis de revendedor sem vínculo recebiam todos `Cliente B TESTE`**, de outro tenant. Depois: lista vazia para os três perfis sem direito; o revendedor com cliente próprio (`customers.reseller_id`) recebe **só o dele**.
  - E era **bug funcional para o admin também**: o admin de plataforma sem vínculo recebia **um** cliente (o primeiro alfabético), não todos. Agora recebe todos, como já acontecia no seletor dos relatórios.
  - A regra nova **espelha `reseller_scope_ids()`** de propósito, para não existirem duas respostas no sistema para "que clientes são deste usuário".
  - Sonda HTTP no caminho de exploração real: `POST /customer_switch {customer_id:2}` como operador sem vínculo → **HTTP 400 "Cliente inválido"**, contexto da sessão permanece `NULL`, e nenhum nome de cliente aparece no HTML do dashboard.
- 🔴 **Nenhum callback de comando offline jamais atualizou a tabela `commands`.** `commands.response_payload` é coluna **JSON**, e `pushinstructresponse.php` gravava a resposta de texto do device crua (`(string)$response`). O MySQL recusava com **`3140 Invalid JSON text`** em toda resposta de texto — que é o caso normal (`ext Battery:12.1V; GPRS:Link Up`) — e o `catch` do método **engolia a exceção em silêncio**.
  - Sintoma: o comando ficava `sent` para sempre e o dashboard expirava em "Comando em fila offline", mesmo com o device tendo respondido. A resposta chegava e era salva em `command_responses` (coluna TEXT, sem o problema), então **parecia** que só a correlação era imperfeita.
  - Discutia-se desde a v4.1.1 **qual heurística de correlação** usar. A correlação não chegava a acontecer: o `UPDATE` lançava antes.
  - Corrigido com `json_encode($response, JSON_UNESCAPED_UNICODE)` sempre, e o `catch` **passou a logar** — foi ele que escondeu isto por meses.
- **`/config-notificacoes` podia ser aberta por quem a matriz de permissões não autorizava.** A tela estava em `$screens` (`grupos_permissao.php`) e **fora** do `$screenByHandler` do router, e o handler só chamava `require_login()`: `create`/`edit`/`delete` davam 403, mas o **`view` não era verificado em lugar nenhum** — negar a tela ao grupo não impedia abri-la e ler todas as regras de notificação do cliente. É o mesmo par de erros da v4.8.5 (`checklist` no router e fora da matriz; `wiki` o inverso). Provado nos dois sentidos com grupo restrito real: **403** sem a tela concedida, **200** com ela, para o mesmo usuário. `config_smtp.php` entrou junto por uniformidade (já se protegia sozinho no topo do handler).

### Changed
- **Correlação da resposta offline passa a usar o `_content`** que o callback devolve, casando com `commands.command_content`, em vez de "o comando pendente mais recente do IMEI". Cenário que a heurística antiga erra e o teste exercita: dois comandos pendentes para o mesmo device, o device responde ao **primeiro** — antes, o segundo é que seria marcado. Degrada para o comportamento antigo quando o callback vem sem `_content`, e registra no log quando isso acontece.
  - ⚠️ **O backlog pedia correlação por `requestId`, e a doc oficial desmente os dois lados dessa suposição.** `requestId` é definido como "used for troubleshooting and log tracing" e **não volta no callback** (a estrutura de resposta da §1.16 é `{code, msg, data:{_code,_imei,_content,_msg,_serverFlagId}}`) — correlacionar por ele é impossível, não difícil. Quem a doc define como chave é **`serverFlagId`**: *"the unique identification field for the current request which is used for correspondence between request and response"*.
  - ⚠️ **Só que aqui `serverFlagId` não é único por comando**: `sendcommand.php` o usa como **seletor de gateway** (0 = JT/T, 1 = JIMI), decisão empírica registrada como "BUG #3" naquele arquivo. Torná-lo único mexe no despacho para veículo real e **só se verifica com device real** (M.2.5, bloqueado) — trocar o valor às cegas arrisca parar o envio de comandos. Fica registrado como o próximo passo dessa linha, com a coluna já no banco.

### Added
- **`migration_v4.8.9.sql`** — `commands.request_id` e `commands.server_flag_id`. `sendcommand.php` já gerava e mandava os dois ao IoTHub e **não guardava nenhum**, então não havia como ligar uma linha de `commands` ao rastro que o IoTHub tem do mesmo envio — que é exatamente a função que a doc dá ao `requestId`. Idempotente, testada **duas vezes em banco-cópia** com saída idêntica.
- **`get_available_customers()` loga quando o usuário fica sem cliente algum**, nomeando a causa (falta o vínculo). Falha fechada não precisa ser falha muda: sem isso o painel abre vazio e sem explicação, que é caro de diagnosticar pelo sintoma — a lição da v4.8.6.

### Notas de implementação
- **Critérios de aceite globais do `PROJETO_YUV.md` §11 conferidos pela primeira vez** (nunca haviam sido marcados), cada um com a evidência ao lado. **6 de 9 sustentam**; os 3 que não viraram texto em vez de caixa marcada: a **auditoria da tratativa** grava só o autor do *último* estado (reabrir uma ocorrência apaga quem a tratou antes — dívida real num produto cujo núcleo é a tratativa); **Cadastros com CRUD+Pesquisar+Exportar** são 6 de 8 (os 2 restantes são matrizes de config, não grades — o critério é que está largo); e **Vídeo** não é verificável sem câmera real.
- ⚠️ **O item "limpar o device de teste `868120246598152` do homolog" foi retirado do backlog em vez de executado.** Ele deixou de ser resíduo: desde a v4.8.6 é `TEST_IMEI`, e `webhook_occurrence.spec.js` **pula sem ele** ("device cadastrado"). Apagá-lo re-silencia a spec que existe desde a Fase M.4, nunca havia rodado, e na primeira execução real **pegou o motor de ocorrências parado**. Seria a armadilha do "spec que pula não é cobertura" pela quarta vez — desta vez causada de propósito.
- **Verificação**: `php -l` limpo em `handlers/ config/ core/ includes/`; sondas com **baseline medido por reversão do código** (não por leitura) nos três achados; controle positivo em todos. Suíte Playwright: **99 passaram, 2 puladas, 0 falharam** — as 2 puladas seguem sendo os skips deliberados (`RATE_LIMIT_TEST`, e a condicional por ausência de dado).
- 📝 O bloco da v4.8.8 no `STATUS.md` dizia "98 passaram"; o commit `a15f8df` registra **99**. Era erro de transcrição na prosa — corrigido.

## [Unreleased] — 4.8.8

### Fixed
- **O mapa pintava por cima da lista de notificações** em toda tela com mapa (`/rastreamento`, `/resumo`, `/geocercas`, `/ativo/{imei}`, posições e rota). Uma linha de CSS resolve, mas a causa merece registro porque não é o valor do z-index — é **contexto de empilhamento**:
  - O Leaflet dá z-index alto aos próprios painéis (tiles **200**, marcadores 600, popup 700, **controles 1000**) e **não cria contexto** no container: `.leaflet-container` é `position:relative; z-index:auto`. Sem contexto, esses valores sobem para a **raiz do documento**.
  - O header é `position:sticky; z-index:50` e, por isso, **cria** contexto. O `z-index:1200` do painel de notificações vale 1200 **dentro do header** e **50** no documento.
  - Resultado: 200 do mapa > 50 do header. O painel tinha o maior número da folha de estilo e perdia mesmo assim.
  - Correção: `.leaflet-container { isolation: isolate; }` — cria o contexto sem mexer em layout nem posição, e os 200/600/700/1000 do Leaflet passam a se resolver dentro do mapa, que é onde fazem sentido.
  - **Por que não foi "aumentar o z-index do header"**: acima de 1000 ele passaria a cobrir os modais das telas (999/1000 em `comandos.php`, `equipamentos.php` e outros) e o backdrop do menu off-canvas (99) deixaria de escurecê-lo. A correção tem de conter o mapa, não escalar o header.

### Notas de implementação
- ⚠️ **A primeira sonda deu falso negativo e por pouco não enterrou o diagnóstico.** Um teste com `document.elementFromPoint()` no centro do painel reportou "painel no topo" em 6 rotas, sugerindo que não havia bug. `elementFromPoint` responde **hit-testing**, não **pintura** — e o defeito relatado era visual. O que fechou o caso foi screenshot antes/depois: no "antes", a lista aparece cortada e o mapa pinta sobre ela; no "depois", a lista sai inteira por cima. **Lição registrada: para bug de sobreposição, a prova é a imagem.**
- O diagnóstico só apareceu depois de ler a **cadeia de ancestrais** de mapa e painel (posição, z-index e o que cria contexto em cada nível), em vez de deduzir da folha de estilo.
- **Verificação**: nas 3 telas com mapa, `isolation: isolate` aplicado, **controle de zoom do Leaflet continua no topo dentro do mapa** e os tiles seguem carregando (16/15/8 tiles) — a contenção não quebrou o mapa. Novo teste de regressão em `tests/notificacoes.spec.js` afirma a **invariante** (o container cria contexto de empilhamento), não o pixel: comparação visual é frágil como teste automático, e o teste diz isso no comentário. Suíte: **99 passaram, 2 puladas, 0 falharam** (o commit `a15f8df` registra 99; o "98" que constava aqui e no STATUS era erro de transcrição, corrigido na v4.8.9).

## [Unreleased] — 4.8.7

### Changed
- **Os três parâmetros que resolviam desligados passam a gerar ocorrência** — `DMS: Distração do Motorista`, `DMS: Motorista ao Telefone` e `ADAS: Colisão Frontal (FCW)`. A v4.8.6 os deixou em `generates_occurrence = 0` porque **preservou o valor que já estava no banco**, o que era o comportamento correto para uma migração de reparo; ligá-los é decisão de produto, tomada em 03/08/2026.
- **Quatro parâmetros sem alvo no catálogo foram removidos**: `Capotamento`, `Olhar Lateral Prolongado`, `Comendo ou Bebendo ao Volante` e `DMS: Comendo ou Bebendo ao Volante`. São nomes que a doc oficial não publica — a v4.8.1/v4.8.3 mostraram que eram invenção. A v4.8.6 os manteve de propósito (apagar configuração alheia por conta própria é invasivo); agora há decisão explícita.

### Added
- **A família de cartão do motorista (DLT) entra no catálogo**, para o quinto órfão deixar de apontar para o vazio. O parâmetro `DMS: Falha na Autenticação ID` não tinha alarme por trás: a v4.8.3 provou que `265-13` é *Phone use*, e o nome antigo era invenção. A doc oficial tem a família em **2.7 Other Alarms**:
  - `3085` *DLT non-registered card alarm* → **`DMS: Falha de Autenticação do Motorista`** — literalmente "cartão não cadastrado", que é a falha de autenticação. O parâmetro foi renomeado para casar **exatamente** com este nome (`get_occurrence_param()` casa por `at.alarm_name_pt = ocp.alarm_type`; parecido não serve).
  - `3083` *DLT card login* e `3084` *DLT card logout* → cadastrados como `Device`/info, fora do filtro. São login/logout, não infração; entram só para não caírem no rótulo genérico `"Código 3083 (JTT)"`, mesma razão da v4.8.3 §6.
  - Nenhum dos três estava em `alarm_types` e **nenhum alarme desses jamais chegou** (0 linhas em `alarms`), então o cadastro não reescreve histórico.
  - ⚠️ **Ressalva taxonômica registrada de propósito**: DMS ao pé da letra é o sistema de câmera/IA, e cartão DLT é RFID — não é evento de câmera. `3085` entra como `DMS` mesmo assim porque o filtro de alarmes lista **só DMS/ADAS** desde a v4.8.3, e é a única superfície onde evento de motorista aparece; fora dela o alarme existiria e seria infiltrável.

### Notas de implementação
- **Verificação**: migração testada em **banco-cópia do homolog** e rodada **duas vezes** com saída idêntica — **34 de 34** parâmetros resolvendo e **0 sem alvo**, contra 33 de 38 antes. Os três ligados conferidos um a um na saída da migração.
- ⚠️ No **dev local** sobra 1 órfão (`Bateria Fraca`, já com `generates_occurrence = 0`) que **não existe no homolog** e não fazia parte da decisão. Por isso a conferência da migração diz "esperado: 0" e o dev responde 1: é resíduo de base de desenvolvimento, não pendência do produto.

## [Unreleased] — 4.8.6

### Fixed
- 🔴 **A v4.8.3 tinha parado o motor de ocorrências, e isso já estava publicado no homolog.** `occurrence_config_params.alarm_type` guarda o **nome** do alarme, não o código, e `get_occurrence_param()` resolve o parâmetro por `JOIN alarm_types ON at.alarm_name_pt = ocp.alarm_type`. A v4.8.3 renomeou dezenas de alarmes DMS/ADAS (o prefixo `DMS:` da §7b, os sete subtipos deslocados, o "Nível" que saiu do nome da fadiga) e **não remapeou essa tabela**: sem o nome antigo no catálogo, o JOIN não casa, nenhum parâmetro é achado e **o alarme é gravado sem gerar ocorrência**.
  - **Falha silenciosa da pior espécie**: nada no log, nada na tela. O alarme entra, aparece nos relatórios, e a ocorrência só não nasce. No homolog matou **21 dos 41** parâmetros — e ocorrência de comportamento do motorista é o **núcleo do produto**, não configuração acessória.
  - **Como apareceu**: provisionando `TEST_IMEI`/`WEBHOOK_TOKEN` para tirar `webhook_occurrence.spec.js` do estado "pulado". O spec existe desde a Fase M.4 e **nunca havia rodado**. Na primeira execução real, falhou. Não foi dedução: o **mesmo IMEI com o mesmo alarme 143 gerava ocorrência até 09/07/2026** (`occurrences` 1–5) e parou de gerar — regressão, não configuração ausente.
  - `migration_v4.8.6.sql` remapeia 16 nomes aposentados para os atuais, conferidos contra o catálogo. Vários apontam para o mesmo alvo (a v4.8.3 fundiu "Nível 1"/"Nível 2" numa fadiga só) e há `UNIQUE (config_id, alarm_type)`, então a fusão fica com `MAX(generates_occurrence)` e o maior `risk` do grupo — **decisão consciente**: esses parâmetros estavam mortos, nenhum comportamento recente dependia deles, e num produto de segurança errar para "gera a ocorrência" mostra o evento em vez de escondê-lo. Desligar segue disponível em `/config-ocorrencias`.
  - **Não apaga** os órfãos sem alvo no catálogo (`Capotamento`, `Olhar Lateral Prolongado`, `Comendo ou Bebendo ao Volante`…, nomes que a v4.8.1/v4.8.3 mostraram serem inventados): são configuração visível do usuário, e apagar ajuste alheio por conta própria é mais invasivo do que deixar um botão que não dispara. A migração os **lista** no log do deploy para decisão.
  - Limpa as linhas com **mojibake** (`Distra├º├úo do Motorista`) — duplicatas corrompidas de importação antiga que nunca casaram com nada. Existem só em base de desenvolvimento; no homolog são zero.
  - Medido em cópia do homolog: **20 de 41 → 33 de 38** parâmetros resolvendo (o total cai porque as fusões colapsam linhas), idempotente em duas passadas.

### Added
- **`tests/helpers/seed_tenants.php`** — provisiona os dois clientes de teste de forma **idempotente e versionada**. `multitenant.spec.js` está no repositório desde a Fase M.4 e **nunca rodou uma vez**, porque pula sem `TEST_EMAIL_B` e o segundo usuário nunca foi criado; foi nesse ponto cego que o vazamento cross-tenant da v4.7.3 sobreviveu. Deixar o provisionamento como passo manual é o que fez a lacuna durar meses.
  - ⚠️ **A armadilha que o script existe para evitar**: o spec identifica IMEI por **regex de dígitos** (`\d{15}`). Enquanto o cliente B tinha só device de IMEI alfanumérico (`IMEIBBB000000002`), o conjunto dele voltava **vazio** e "A e B não compartilham devices" passava por **vacuidade** — dois conjuntos vazios não se intersectam. Os dois clientes recebem agora, obrigatoriamente, IMEI de 15 dígitos, e o spec ganhou guarda `exigeDevices()` que falha com instrução em vez de passar em silêncio.

### Changed
- **`multitenant.spec.js` rodou pela primeira vez — e tem dentes, provado por mutação.** Com o usuário B promovido a `role='admin'`, o teste de escalada **falha** e nomeia os IMEIs vazados do cliente A (`865478070003241, 865478070011327, 864993060182939, 353376110010771`); revertido para `operator`, passa. Sem essa mutação, "passou" não distinguiria isolamento correto de asserção inócua.
  - O teste de escalada ganhou `test.setTimeout(180000)`: são 4 telas × 3 ids = 12 relatórios completos em sequência (a suíte roda com 1 worker porque o servidor embutido do PHP é single-thread), e o timeout global de 45 s estourava no meio do laço com "Test ended" — que se lê como falha de aplicação sendo só orçamento de tempo, e ainda deixava as últimas telas sem exercitar.
- **A suíte saiu de 94 passando / 6 puladas para 98 passando / 2 puladas, 0 falhas.** As 4 que entraram são as 3 de `multitenant.spec.js` e a de `webhook_occurrence.spec.js` — todas rodando pela primeira vez. As 2 que continuam puladas são **skip deliberado**, não lacuna: `login.spec.js:67` (rate limiting) só roda com `RATE_LIMIT_TEST=1` porque **bloqueia o IP por 15 minutos**, e `relatorios-operacionais.spec.js:136` tem skip condicional por ausência de dado.
- **`webhook_occurrence.spec.js`**: a asserção final olhava `body` com o timeout padrão de 5 s, mas a grade do dashboard é montada **no cliente** — o HTML servido chega com `#occurrence-tbody` vazio, preenchido depois por JS a partir de `/ocorrenciasdata`. A asserção corria com o fetch e falhava com "unexpected value" vazio, que se lê como "a ocorrência não existe" quando ela já estava no endpoint. Agora aponta para o `#occurrence-tbody` com timeout compatível.

## [Unreleased] — 4.8.5

### Security
- 🔴 **Revendedor lia os dados de QUALQUER cliente da base — inclusive os de outro revendedor.** É a mesma escalada que a v4.7.3 fechou para o `operator`, um perfil acima, e que aquela versão deixou aberta de propósito ("pergunta de produto, não de segurança óbvia") para não mudar semântica de perfil no mesmo passe. `$isAdmin` é `role==='admin' || user_type==='revendedor'` em ~10 handlers, e `report_customer_scope()` tratava os dois como admin de plataforma.
  - **Medido, não deduzido**: com um revendedor puro real (`user_type='revendedor'`, `role='operator'`, vinculado só ao cliente 1), a sonda antes da correção via o equipamento do cliente 2 em **três** cenários — `?customer_id=2`, `?customer_id=1` e, o pior, **sem parâmetro nenhum**. Esse terceiro é mais grave do que o registrado no STATUS: sem `?customer_id`, `report_customer_scope()` devolvia `null` = *sem filtro*, então a tela abria com a base inteira sem que ninguém precisasse adulterar a URL. Depois da correção, os três cenários mostram só o cliente 1, e o admin de plataforma segue enxergando os dois.
  - Regra nova: **admin de plataforma** (`role === 'admin'`) inalterado; **revendedor** honra `?customer_id` só se o cliente for dele e, fora disso, cai no cliente da sessão — **nunca** `null`, isto é, revendedor não tem mais visão "todos os clientes da base"; **demais perfis** inalterados. Fora do escopo o parâmetro é **ignorado, não validado** — validar responderia, pela tela, se o cliente existe.
  - O escopo do revendedor é a **união** de `customers.reseller_id = <user>` e dos vínculos em `customer_users`. A coluna `reseller_id` existia desde a v3.1.0 e era **escrita** por `handlers/clientes.php` na criação do cliente, mas **nunca lida** — `reseller_scope_ids()` é o primeiro leitor dela. O segundo termo da união não é redundância: `reseller_id` é NULL em **100%** das linhas das bases atuais, e sem ele todo revendedor já existente passaria a não ver cliente nenhum — trocar um vazamento por um apagão.
  - Erro de banco na resolução do escopo devolve lista **vazia**, não "sem restrição": falha fechada.
- **A outra metade: o SELETOR de cliente listava todos.** Restringir o filtro sem restringir a lista deixaria o revendedor lendo os **nomes** de todos os clientes da base num `<select>` cujas opções, além do mais, não teriam mais efeito ao serem escolhidas. Eram **12 handlers** repetindo `SELECT id, name FROM customers WHERE is_active=1`; todos passam agora por `report_customer_options()`, companheira obrigatória de `report_customer_scope()`. Sondadas as 12 rotas com o revendedor puro: **0 vazamentos, 0 erros de PHP**, admin de plataforma inalterado em todas.
- **Exclusão de checklist obedecia a `$isAdmin` cru** — um revendedor apagava o checklist de qualquer cliente, a mesma escalada num caminho **destrutivo**. O `require_permission('checklist','delete')` novo não cobre sozinho: usuário **sem grupo** passa por `can()` sem restrição (role legado), e hoje `permission_group_id` é NULL em 100% dos usuários. Agora o escopo do revendedor decide, e checklist global (`customer_id` NULL) segue só para admin de plataforma. Verificado nos dois sentidos: a exclusão do checklist do cliente 2 é **recusada** ("fora do seu escopo") e a linha sobrevive no banco; a do cliente 1 (dele) **conclui**.

### Fixed
- **A Central de Ajuda dava 403 a todo usuário de grupo restrito, e não havia como liberar.** `wiki` estava no `$screenByHandler` de `handlers/router.php` desde a v4.2.0 (portanto exigia `require_permission('wiki','view')`), mas **nunca esteve na matriz de telas** de `/grupos-permissao`. Como `can()` nega tudo que não está no JSON do grupo, era uma tela protegida que **nenhum grupo podia receber**: o admin não tinha como conceder o que não aparece na tela para marcar. O rótulo "Ajuda" está na sidebar de todo mundo. `migration_v4.8.5.sql` acrescenta `wiki: ["view"]` aos grupos já gravados que não têm a chave (grupos com o curinga `{"*": …}` ficam intocados, já passam por `can()`).
- **`checklist` entrou na matriz e no RBAC do router** — a dívida aberta desde a v4.7.2, quando aquela versão fechou o CSRF da exclusão mas deixou a permissão de fora justamente porque a tela não estava na matriz. Era o inverso do caso `wiki`: fora dos **dois** lugares, ou seja, um CRUD vivo e alcançável **sem checagem de permissão nenhuma**. Agora `handlers/checklist.php` chama `require_permission('checklist', create|edit|delete)` nas ações finas.
  - `checklist` fica **fechado por padrão** para grupos restritos: é CRUD de configuração, e conceder por migração seria decidir política de acesso no lugar do administrador. `wiki` é o oposto — tela de leitura que todo perfil deve alcançar, então negá-la é defeito, não política.
  - Fica registrado na própria matriz: **toda tela nova precisa entrar nos dois lugares**, `$screens` (grupos_permissao.php) e `$screenByHandler` (router.php). Só no router = impossível de liberar; só na matriz = sem proteção.

### Added
- **`tests/notificacoes.spec.js`** — o sino da v4.4.0 estava sem cobertura E2E desde que foi escrito; dívida aberta na v4.7.3. **9 testes**: contrato do `/notificacoesdata` (chaves e tipos), `401` sem sessão, `403` no POST sem `X-CSRF-Token`, o sino renderizando, o painel abrindo e fechando por clique fora, a lista saindo de "Carregando", a notificação nova aparecendo no contador **e** no painel, o `last_id` limitando os popups, e o "marcar todas como lidas" zerando o contador.
  - **Com dado real, não vazio.** Notificação não tem caminho de criação pela interface — quem grava é o motor, chamado pelo worker —, então "o sino mostra a notificação" e "não vaza a de outro cliente" passariam por **vacuidade**: lista vazia satisfaz as duas. `tests/helpers/seed_notification.php` (arquivo de teste, não da aplicação) semeia chamando a **`notify()` real**, e por isso exercita o mesmo caminho da produção, teto horário incluído. O teste cross-tenant tem **controle positivo**: afirma que a notificação do próprio cliente está na lista antes de afirmar que a do outro não está.
  - O seeder escreve no banco do `.env` desta máquina, que só é o banco do app sob teste quando a suíte roda contra o servidor local — o caso normal. Com `BASE_URL` apontando para outro host, o bloco semeado sai de cena com o motivo dito em voz alta; os outros 5 testes rodam sempre.

### Changed
- **Os 2 specs que falhavam desde a v4.8.2 foram corrigidos** — eram asserções velhas, e o diagnóstico de cada uma decidiu qual lado estava errado:
  - `geocercas.spec.js` esperava o `h2` "Relatório de **Geocercas**"; a tela diz "Relatório de **Cercas**" desde `7a0a75f`. **A tela está certa**: a sidebar tem duas entradas para o assunto — "Cercas" (o relatório, `/relatorios/geocercas`) e "Geocercas" (o CRUD, `/geocercas`) — e o `h2` segue o label para o usuário saber em qual das duas está. Corrigido o teste.
  - `agendamentos.spec.js` procurava `input[name="imei"]` em `/relatorios/alarmes`, onde o campo virou `<select name="imei">` populado com os equipamentos do cliente, no mesmo commit. Trocar só o seletor não bastava: o teste usava o IMEI inventado `12345`, e um `<select>` não seleciona opção que não existe — a asserção de "os campos voltam preenchidos" passaria a testar o vazio. Agora o valor é **capturado da própria lista** num `beforeAll`, o que também faz o spec valer em qualquer ambiente.

### Notas de implementação
- **Verificação**: `php -l` limpo em todo `handlers/ config/ core/ includes/`; `migration_v4.8.5.sql` testada em **banco-cópia** do homolog e **rodada duas vezes** com saída idêntica (grupo curinga intocado, grupo restrito ganhando `wiki`); sondas HTTP com um revendedor puro real, cada uma com **controle positivo**, e o **baseline provado por reversão do código** — não por leitura dele; suíte Playwright completa: **94 passaram, 6 puladas, 0 falharam**. As 2 que falhavam desde a v4.8.2 estão agora entre as que passam, e 9 dos 94 são o spec de notificações novo.
  - ⚠️ Uma primeira rodada da suíte foi **contaminada** por edições de handler feitas no meio dela. Passou (94/0), mas não vale como prova. O número acima é de uma rodada limpa, com a árvore parada.
- ⚠️ **Residual conhecido, deixado de propósito**: `get_available_customers()` (`includes/auth.php:278`) ainda cai no "primeiro cliente ativo" quando o usuário não tem vínculo em `customer_users` — para um revendedor sem vínculo, isso entrega o cliente de outro. **Não foi mexido**: está no caminho de login, e nenhum usuário das duas bases depende do fallback (todos têm vínculo explícito e são admin de plataforma, para quem nada muda). Merece passe próprio, com verificação de login por perfil, em vez de carona num passe que já mexeu em 12 telas.

## [Unreleased] — 4.8.4

### Added
- **Decisão sobre os quatro códigos JIMI ambíguos** (`migration_v4.8.4.sql`), que a v4.8.3 tinha deixado abertos de propósito. Dos 197 códigos JIMI da *Alarm Reference*, exatamente quatro aparecem **duas vezes**, em subseções diferentes e com sentidos conflitantes — reconferido no HTML servido em 03/08/2026, varrendo todas as `<tr>` da seção 1, o que confirma serem esses quatro **e mais nenhum**:
  - `80` — 1.6 *Door was closed* / 1.9 *Door opening alarm*
  - `81` — 1.6 *Door was opened* / 1.9 *Door closing alarm*
  - `131` — 1.5 *Vehicle collided* / 1.8 *Seatbelt fastened alarm*
  - `132` — 1.8 *Seatbelt unfastened alarm* / 1.9 *Camera 1 exception*

  Em `80`/`81` as leituras são **espelhadas** — uma diz "fechou" onde a outra diz "abriu" —, e as tabelas da doc têm só duas colunas: não há modelo, firmware ou protocolo que sirva de desempate. **O impasse não se resolveu pela doc; deixou de importar pelo escopo.** Das cinco funcionalidades em disputa (abrir porta, fechar porta, colisão, cinto afivelado, falha da câmera 1), a única que o sistema terá é **cinto NÃO afivelado**. Portanto `132` é catalogado como **"DMS: Cinto Não Afivelado"**, e `80`, `81` e `131` **não** entram — vale notar que as **duas** leituras de `131` caem fora, porque a de 1.8 é o cinto **afivelado**, o evento positivo, não a infração.
  - O nome é **deliberadamente idêntico** ao de `167` (JIMI) e `265-10` (JT/T). Como o filtro casa por nome desde a v4.8.3 (`SELECT DISTINCT alarm_name_pt`), o código novo **não cria uma opção nova**: faz o chip existente passar a pegar também o `132`.
  - A migração reaplica o nome ao histórico (`msg_class=0 AND alarm_type='132'`), escopo estreito de propósito. No homolog isso é zero linha; em produção pode não ser.

### Changed
- **O evento POSITIVO de cinto sai do filtro de alarmes.** A mesma regra alcança um código que já estava catalogado: se só "sem uso do cinto" é alarme, então `166` (*Driver is already buckled up* — o motorista **colocou** o cinto) não é. Ele era um dos **33 chips** oferecidos no filtro, que desde a v4.8.3 lista `WHERE category IN ('DMS','ADAS')`. Agora são **32**.
  - É **recategorização, não exclusão**: `166` sai de `DMS` para `Vehicle`. Apagar a linha faria o alarme cair no fallback `"Código 166 (JIMI)"` se algum equipamento mandasse o código — trocaria um rótulo correto por um genérico. A doc publica o `166`; o que mudou é o nosso escopo, não a doc.
  - O prefixo `DMS:` cai junto (`DMS: Cinto Afivelado` → `Cinto Afivelado`), no catálogo e no histórico. Ele foi criado na v4.8.3 §7b justamente porque o filtro passou a listar só DMS/ADAS e as entradas precisavam se anunciar como tal; fora do filtro, o prefixo afirmaria uma categoria que a linha não tem mais.
  - **Porta (`20`, `28`, `29`) não foi tocada**: é categoria `Vehicle` desde sempre e por isso **já** não aparecia no filtro. Não havia o que remover.

### Notas de implementação
- ⚠️ **O que a decisão NÃO resolve**: ela diz o que queremos ver, não o que o equipamento quis dizer. Se algum firmware emitir `132` significando *Camera 1 exception*, o sistema passa a rotular falha de hardware como infração do motorista — a mesma classe de erro que a v4.8.3 corrigiu nos sete subtipos DMS deslocados. O risco é **aceito** com três atenuantes: (1) incidência **zero** — em 3.583 alarmes do homolog não há uma linha com tipo `80`, `81`, `131` ou `132`, e a frota de lá é 99% JT/T; (2) a funcionalidade de cinto **já está coberta** por códigos não ambíguos (`167` e `265-10`), então `132` é redundância defensiva para o caso de uma geração de firmware usar esse número; (3) falha de câmera correlaciona com `107`/`161` e chega **sem** mídia de motorista, então dá para conferir antes de confiar no rótulo.
- **Armadilha de leitura registrada**: a whitelist da `migration_v4.8.1.sql` **lista** `80`, `81`, `131` e `132` entre os códigos oficiais, o que dá a impressão de que estavam catalogados. **Não estavam** — a whitelist só protege linhas do `DELETE`, não cria linha nenhuma. Os quatro eram, ao mesmo tempo, "oficiais" e ausentes de `alarm_types`, e qualquer um deles que chegasse caía no fallback `"Código NNN (JIMI)"` de `handlers/pushalarm.php:395`. Efeito colateral útil: reexecutar a v4.8.1 depois desta migração **não** apaga o `132`.
- A migração emite três conferências no log do deploy, e uma delas é uma **sonda para produção**: `SELECT COUNT(*) FROM alarms WHERE msg_class=0 AND alarm_type IN ('80','81','131')`. Se vier > 0, os códigos descartados chegam de verdade e a ambiguidade precisa ser decidida com dado real (correlação com velocidade, `car_status` e presença de mídia), não com a doc.
- **Verificação**: migração testada em **banco-cópia** (`jimi_amb_test`, criado do `mysqldump` de `alarm_types`+`alarms`+`system_info` do homolog) e **rodada duas vezes**, com saída idêntica nas duas passadas — 119 → 120 tipos, 3.583 alarmes intactos, `system_info` em `4.8.4`; os três códigos de cinto não afivelado presentes (`132`, `167`, `265-10`), os três descartados ausentes (`80`, `81`, `131`), `166` em `Vehicle` sem o prefixo, porta intacta em `Vehicle`; chips do filtro medidos em **33 antes e 32 depois**. Banco-cópia removido ao fim. **Nada foi aplicado ao homolog nem à produção.**

## [Unreleased] — 4.8.3

### Fixed
- **O endereço parava de sair pela metade no PDF.** O `PdfWriter` dava a TODAS as colunas a mesma largura e cortava com "…" o que não coubesse; numa grade de oito colunas isso são ~96 pt por coluna, e o endereço geocodificado — o campo mais longo de qualquer relatório — saía sempre truncado ("Avenida Presidente Juscelino Kub…"). Duas mudanças no writer:
  - **Largura por peso**: cada relatório declara pesos relativos de coluna (`stream_export(..., $colWeights)`), e o endereço leva 3,2–3,6× o de uma coluna comum. Em Posições isso o leva de 96 pt para **266 pt**.
  - **Quebra de linha** em até 4 linhas por célula, no lugar do corte. Medida com as **métricas AFM reais do Helvetica** (tabela de largura por caractere), não com o "nº de caracteres × 0,52 em" anterior, que erra >20% em texto com muitos `i`/`l` (222) ou `M`/`W` (833/944) — margem suficiente para o texto vazar a coluna vizinha ou ser cortado cedo demais. A altura da linha passou a variar, e a quebra de página agora **mede antes de decidir**.
  - Como a quebra vale para todo PDF do sistema, **nenhum relatório trunca mais** — os que não declaram pesos seguem com colunas iguais, só que quebrando em vez de cortando.
- **Cabeçalho de período do PDF** (`report_period_label()`, ponto único): sai o sufixo `(BRT)` — o sistema só exibe em horário de Brasília, e anotar o fuso levantava a dúvida de que houvesse outro —, a data vira **DD/MM/AAAA** no lugar do `Y-m-d` cru do `<input type="date">`, e **a hora é sempre escrita**, inclusive quando o filtro de faixa horária ficou vazio (`00:00:00` a `23:59:59`), que é exatamente a janela consultada. Antes, omitir a hora deixava ambíguo se o dia final entrava inteiro. Aplicado nos 9 relatórios que exportam PDF, não só nos três reportados.
- **Nomes de alarme conferidos contra a doc oficial** (`migration_v4.8.3.sql`). A v4.8.1 podou `alarm_types` para os códigos publicados mas **não conferiu os nomes**, e a conferência achou erro de mapeamento, não de tradução:
  - **Sete subtipos DMS deslocados** — o relatório acusava o motorista da coisa errada. `265-10` estava como "Comendo ou Bebendo ao Volante" e é **Cinto Não Afivelado**; `265-13` estava como "Falha na Autenticação ID" e é **Uso de Celular**; `265-6` estava como "Captura Automática" e é **Câmera Obstruída**; `265-8`, `265-11`, `265-16` e `265-17` idem. "Comendo ou Bebendo", "Bocejando", "Postura da Cabeça" e "Fadiga Nível 2" não existem na tabela 2.6 da doc: eram nomes inventados ocupando subtipos reais.
  - **`264-6`** estava como "Reconhecimento de Placa" — a doc separa `0x06` *Road sign overrun* (ultrapassou o limite da placa) de `0x10` *Road sign recognition* (só reconheceu). Estavam fundidos; agora são `264-6` e `264-16`.
  - **JT/T `1040`/`1041`**, os dois códigos com mais linhas gravadas, estavam como "Ociosidade Excessiva" e "Ignição Não Autorizada"; a doc (2.7) diz **Sleep Mode Event** e **Working Mode Event**.
  - **JIMI `147`** estava como "Fadiga Extrema do Motorista" na categoria DMS; a doc (1.5) diz **Vehicle collided**. `44` estava como "Frenagem Brusca" — 44 é colisão, quem freia forte é o `48`. Também corrigidos `14`, `17`, `18`, `103` e `104`.
  - **15 códigos DMS/ADAS do protocolo JIMI que faltavam** no catálogo (`71`, `107`, `117`, `140`, `148`, `161`–`163`, `166`, `167`, `170`, `199`, `228`, `229`) — sem eles o alarme de câmera de um equipamento JIMI caía no rótulo genérico "Código NNN (JIMI)" e ficava fora do filtro.
  - **Saem os subtipos sem respaldo**: `264-8`…`264-12` e `265-18`…`265-21` caem em faixas que a doc declara *User defined*, `265-7` é indefinido, e o grupo `266` (BSD) não consta da Alarm Reference. Nenhum tem uma única linha em `alarms`.

### Changed
- **Filtro "Tipos de Alarme" passa a listar só DMS e ADAS.** A lista vinha de `SELECT DISTINCT alarm_name FROM alarms` — do que por acaso já tinha acontecido —, então trazia rótulos de infraestrutura ("Falha no Armazenamento", "Perda de Sinal de Vídeo"), as variantes "Fim de Alarme: …" e os "Código NNNN (JTT)" não cadastrados. Agora vem de `alarm_types` restrita às categorias DMS/ADAS: **33 opções**, o catálogo canônico. Consequência a conhecer: um tipo que nunca ocorreu também aparece — que é o comportamento desejado num filtro, senão só se filtra o que já se sabe existir.
- **Alarmes de câmera do protocolo JIMI ganharam o prefixo `DMS:`** que os equivalentes JT/T já tinham. Num filtro que agora lista só DMS/ADAS, metade das entradas não se anunciava como tal ("Motorista Bebendo", "Erro de Alinhamento Facial" pareciam ter caído ali por engano). Onde o evento é **o mesmo nos dois protocolos** (fumo, fadiga, cinto, câmera obstruída, condução prolongada) o nome é **deliberadamente idêntico**: o filtro casa por nome, então um chip só passa a pegar o alarme venha ele de equipamento JIMI ou JT/T. Os **códigos** seguem separados por protocolo — o isolamento do ADR-001 é de código, não de rótulo.
- **Deslocamento**: a 4ª coluna do fechamento diário deixa de ser "Última Ign. Deslig." e passa a **"Última Ignição"**, na grade e no export.
- **Coluna "Rota" do Deslocamento vira link rotulado `ROTA`**, como a coluna Mapa dos outros relatórios desde a v4.8.2. Não estava no pedido: a URL crua já ocupava a coluna inteira e, com a quebra de linha nova, passaria a ocupar **três linhas** em cada viagem.

### Notas de implementação
- `alarms.alarm_name` é **desnormalizado** — `pushalarm.php` resolve o nome na chegada e grava a string. Corrigir só `alarm_types` deixaria o relatório exibindo o nome errado em todo o histórico, então a migração **reaplica os nomes ao já gravado**. Duas exclusões deliberadas no `UPDATE ... JOIN`: o `256`, cujo nome vem da decodificação do bitmask de 32 bits e cujo `alarm_subtype` guarda o **valor** do bitmask (rejuntar destruiria o dado), e `264`/`265` sem `alarm_subtype`, porque sem o subtipo não há como saber qual alarme de câmera foi. O prefixo "Fim de Alarme: " do `removeAlarmType` é preservado.
- **A doc oficial é uma SPA, mas a Alarm Reference ESTÁ no HTML servido** — ao contrário do que a nota da v4.8.1 registrou. `Invoke-WebRequest` traz 803 KB já com as tabelas renderizadas; basta recortar a partir de `id="_1-jimi-device-alarms-msgclass-0"` e parsear os `<table>`. Não é preciso navegador.
- **A própria doc tem códigos ambíguos**: `131` aparece em 1.5 como *Vehicle collided* e em 1.8 como *Seatbelt fastened*; `132` em 1.8 como *Seatbelt unfastened* e em 1.9 como *Camera 1 exception*; `80`/`81` trocam "porta aberta"/"porta fechada" entre 1.6 e 1.9. Esses **não foram tocados** — não há como decidir pela doc.
- **Verificação**: `php -l` limpo em todo `handlers/ config/ core/ includes/`; migração testada primeiro num **banco-cópia** (`jimi_alarm_test`, criado do `mysqldump` de `alarm_types`+`alarms`+`system_info`) e **rodada duas vezes** para provar idempotência, só então aplicada a homolog e ao dev local; os três PDFs gerados **pelo caminho real do handler** (`/relatorios/{posicoes,alarmes,deslocamento}?export=pdf`, HTTP 200) com o content stream extraído e conferido posição a posição; verificador automático sobre um PDF de 120 linhas com endereços longos confirmando **0 textos vazando a coluna**, **0 reticências** e as 4 ruas remontáveis a partir das linhas quebradas (folga mínima de 7,95 pt até a borda). Suíte Playwright: **81 passaram, 2 falharam, 6 puladas, 2 não rodaram**.
  - ⚠️ **As 2 falhas são as mesmas da v4.8.2 e não têm relação com esta entrega** — `geocercas.spec.js:116` espera o `h2` "Relatório de Geocercas" e a tela diz "Relatório de Cercas" desde `7a0a75f`; `agendamentos.spec.js:169` procura `input[name="imei"]` em `/relatorios/alarmes`, onde o campo virou `<select name="imei">` no **mesmo** commit. O diff desta versão não toca uma linha com `imei` em `rel_alarmes.php`.
- **Publicado no homolog** (`671887c`, `/ping` 4.8.3) e reverificado **contra o servidor real**: 4 PDFs exportados pelo caminho do handler, **93 páginas / 23.100 posicionamentos de texto, 0 vazando a coluna e 0 truncados** (folga mínima de 4,06 pt no de Alarmes, o mais apertado); filtro com 33 chips, todos `DMS:`/`ADAS:`. **Produção não foi tocada.**
  - ⚠️ **A quebra de linha não foi exercitada por dado real**: o endereço mais longo no `geocode_cache` do homolog tem 72 caracteres e cabe nos 266 pt da coluna — as 0 reticências são também 0 quebras. O homolog prova que as larguras valem e que nada trunca; a quebra continua provada só pelo teste sintético (endereços de ~120 caracteres, ruas remontáveis a partir das linhas).
  - O deploy exigiu as duas passadas (`deploy.sh && deploy.sh --force`), e desta vez ficou **provado**: antes dele, `grep -c 'migration_v4.8.3' scripts/deploy.sh` no servidor devolvia `0`.

## [Unreleased] — 4.8.2

### Changed
- **A marca passa a ter TRÊS artes, uma por superfície** — o asset único da v4.8.0 não dava conta de três contextos com exigências opostas:
  - `web/assets/logo-login.png` — lockup **completo, com o descritor "videomonitoramento inteligente"**, usado só na tela de login. Medido: o descritor ocupa **8,6% da altura da arte** (19 px de 221), então só se lê com largura — ocupa a **largura útil do card inteiro** (318 px → 78 px de altura), onde sai com **6,7 px**. Nos 38 px de altura da versão anterior ele teria 3,3 px: existia na imagem e era ilegível na tela.
  - `web/assets/logo-dark.png` — arte oficial de **fundo escuro** (texto cinza-claro + símbolo azul), na sidebar e em qualquer superfície near-black. Substitui a variante que era **sintetizada** a partir da arte clara.
  - `web/assets/logo-report.png` — arte **sem o descritor**, fundo branco sólido, no cabeçalho do PDF dos relatórios (a 26 pt, tamanho em que o descritor não se leria de qualquer modo).
  - Os três nomes são explícitos de propósito: o `logo.png` genérico anterior não dizia em que fundo servia, e foi exatamente assim que a marca acabou invisível na sidebar. `logo.png` foi **removido** — nada mais o referencia.
- **"Entrar no sistema" centralizado** no card de login, junto com o subtítulo e a marca.
- **Ícones do PWA e nome do app instalado** — ponta do rebrand que tinha ficado para trás: `manifest.json` ainda dizia `"JIMI — Gestão de Frota e Ocorrências"` / `short_name: "JIMI"`, e os quatro ícones (08/07/2026) eram o **placeholder de pontinhos + texto "JIMI"** que o resto do frontend já tinha aposentado. É o que aparece na aba do navegador (favicon), na tela inicial do celular e no instalador do PWA. Agora são o **símbolo** da marca (o "b"-olho) centrado em preto — o lockup inteiro num quadrado de 192 px deixaria "bycamera" com 4 px de altura. Os `maskable` ocupam 46% da altura contra 62% dos normais, porque o SO recorta em círculo/squircle.
  - A fonte dos ícones é a arte **original**, não o `logo-dark.png`: naquele a pupila do olho virou transparente pela chave de preto e, composta sobre o fundo, saía cinza. Como o fundo do ícone é o mesmo preto da arte, aqui basta recortar.
  - Escapou da varredura anterior porque o verificador de marca olhava PHP; `manifest.json` é JSON e os ícones são binários.
- **Mockups de login e setup da `/wiki`** atualizados para a arte nova. O mockup do login tinha uma linha de texto "Videomonitoramento inteligente" abaixo do logo — agora o descritor faz parte da arte, e repeti-lo descreveria uma tela que não existe.

### Notas de implementação
- **O `logo_pto_fundo transparente.png` não tem fundo transparente** — apesar do nome, o fundo é **preto opaco** (`#010001`, alfa 0 nos quatro cantos). Sobre a sidebar `#0a0b0d` isso sairia como um retângulo levemente mais escuro que o fundo, e a chave para transparência teve de ser feita aqui.
- **A chave do preto é GLOBAL, não flood fill a partir da borda.** A primeira tentativa preencheu só a região conectada às bordas e as **contraformas das letras** (o buraco do "b", do "a", do "e") ficaram como caixinhas pretas opacas — invisíveis no near-black da sidebar, mas erradas em qualquer outro fundo escuro. Efeito colateral aceito da versão global: a **pupila do olho** também é preta e virou transparente. Sobre near-black lê igual, e uma pupila levemente fora é menos visível que oito caixas. As bordas anti-aliased ganharam rampa de alfa (luma 10→48) para não serrilhar.
- **`REPORT_LOGO_PATH` virou o ponto único do caminho da arte dos relatórios**, e `export_helper.php` passou a `require_once` o `functions.php` em vez de repetir o caminho: os chamadores são ~12 handlers mais o worker, e o dia em que um deles não tiver `functions.php` carregado o erro é **fatal no meio da exportação**, não um logo faltando.
- O achatamento sobre branco antes de virar JPEG ficou no lugar mesmo com a arte agora opaca — é guarda para o dia em que o asset for trocado por um com alfa, caso em que o fundo sairia **preto** no PDF e a falha só apareceria no documento gerado.
- **Verificação**: `php -l` limpo nos 5 arquivos PHP tocados; `manifest.json` reparseado; login conferido por screenshot em 1280 px e em 390 px (descritor legível nos dois); sidebar conferida por screenshot autenticado (marca visível sobre `#0a0b0d`); PDF exportado pelo **caminho real do handler** (`/equipamentos?export=pdf`, HTTP 200) com o XObject extraído do arquivo e **conferido visualmente** — 360×94, `/DCTDecode`, um `/Im1 Do`; suíte Playwright completa: **81 passaram, 2 falharam, 6 puladas**.
  - ⚠️ **As 2 falhas são anteriores a esta versão** — provado rodando os mesmos dois specs com as mudanças em `git stash`: falham igual. `geocercas.spec.js:116` espera o `h2` "Relatório de **Geocercas**" e a tela diz "Relatório de **Cercas**" desde a renomeação em `7a0a75f` — o teste não acompanhou, o que também mostra que a suíte não rodou naquele commit. `agendamentos.spec.js:155` não encontra no seletor o modelo que o próprio teste acabou de criar (aparece um de execução anterior); esse precisa de investigação, não é asserção velha.

## [Unreleased] — 4.8.0

### Added
- **Geocode reverso pelo Nominatim interno** (`10.1.0.15:8080`) e **endereço no lugar de latitude/longitude em todos os relatórios** — ver a nota de arquitetura em `includes/geocode.php`. O formato é **"rua, cidade, estado"**, com cascata de alternativas por campo porque o Nominatim não garante as mesmas chaves em rodovia e zona rural, que é onde a frota mais anda.
- **`scripts/geocode_worker.php`** (cron 5 min) mantém o cache quente. A decisão entre geocodificar na entrada, na saída ou em segundo plano foi **medida**, não presumida: saída com cache frio custa 4,7 s por dia de posições (117 s a 25× a frota); com cache quente, 0,148 ms/ponto; o pré-aquecimento roda a 349 pts/s, ou 0,4 s por execução a 25×. Na entrada custaria 27 ms por ponto **dentro da transação do webhook**, acoplando a gravação da posição do veículo à disponibilidade do geocode.
- **Marca nos relatórios**: `report_brand()` em 10 telas e logo embutido no PDF como XObject `/DCTDecode`.

### Changed
- **O produto passa a se chamar `bycamera` no frontend.** Login, sidebar, `<title>`, setup, wiki, marca d'água do vídeo ao vivo e o remetente padrão de e-mail. O placeholder de "pontinhos + texto JIMI" do login e da sidebar deu lugar ao logo real.
  - **O que NÃO foi renomeado, de propósito**: o badge de **protocolo** `JIMI` (contra `JT/T 808`) é nome técnico real — `msgClass=0` é o protocolo JIMI, e trocá-lo tornaria a tela mentirosa. Também ficaram intactos `jimicloud.com`, o nome do banco, o cookie `jimi_token`, as chaves de `localStorage` (renomear resetaria o estado de UI salvo dos usuários) e identificadores internos como `get_jimi_user()` — churn sem ganho para quem usa.
  - O verificador de marca precisou aprender essa distinção: a primeira varredura acusou `/relatorios/alarmes` porque contava o badge de protocolo de cada linha da grade.

### Notas de implementação
- **Dois padrões diferentes para o endereço, por um motivo.** Nos exports síncronos é `fetchAll` + resolução em lote paralelo; nos assíncronos do `worker.php` a resolução foi para o **SQL** (`LEFT JOIN geocode_cache`), porque ali as linhas são consumidas em streaming — export agendado chega a 100 mil linhas, e `fetchAll` estouraria memória.
- **O gargalo não era o que parecia.** A primeira medição concluiu "o Nominatim satura em concorrência 5"; era artefato de ter o `INSERT` no cache dentro do laço. Cada `INSERT` em autocommit custa **72 ms** neste servidor (fsync por commit) contra **2,2 ms** da chamada à API — 33× mais caro **gravar** o resultado do que obtê-lo. Com `geocode_persist()` em transação única, o Nominatim escala a 448 pts/s.
- **Precisão medida, não estimada**: arredondar a chave de cache para 4 casas (11 m) muda a **rua** em 10% dos pontos — e para uma rua diferente, não uma variação de grafia. Mantidas 6 casas.
- ⚠️ **XLSX ficou sem logo**: o writer é artesanal e começa direto no `writeHeader`, sem conceito de título; embutir imagem exigiria `drawing1.xml` + rels + media + content-types. Registrado, não feito pela metade.
- ⚠️ **Retrabalho evitável, registrado como lição**: o asset começou como `logo_bycamera.png`, que tem **fundo branco sólido**, e mesmo assim foi escrito um achatamento sobre branco "porque o logo tem transparência" — falso para aquele arquivo. Existiam dois logos e o outro (`by_camera_fundo_transparente.png`) já era transparente. Agora o asset é o transparente, que serve tanto no card claro do login quanto na sidebar near-black com **uma** imagem — e aí o achatamento no PDF passa a ser genuinamente necessário, porque JPEG não tem canal alfa.

## [Unreleased] — 4.7.3

Passe de dívida técnica, disparado pela auditoria dos **critérios de aceite globais do
`PROJETO_YUV.md` §11** — nove itens que estavam marcados mentalmente como "ok" e nunca
tinham sido medidos como conjunto. Sem tela nova e **sem migração**.

### Security
- 🔴 **VAZAMENTO CROSS-TENANT: `?customer_id=N` na URL dava acesso aos dados de qualquer cliente.** Nove pontos do código repetiam este padrão:
  ```php
  if (!$isAdmin && !$filterCust) { /* filtra pelo cliente da SESSÃO */ }
  elseif ($filterCust)           { /* filtra pelo cliente PEDIDO NA URL */ }
  ```
  Um usuário **não-admin** que acrescentasse `?customer_id=N` fazia `!$filterCust` virar falso, o primeiro ramo era pulado e o `elseif` filtrava pelo cliente **pedido**, sem nenhuma verificação de posse. O parâmetro que existia para o admin escolher um cliente virava, para qualquer usuário, um **seletor livre de tenant**.
  - **Confirmado empiricamente antes da correção**, não deduzido do código: um usuário `operator`/`cliente` do cliente B leu alarmes, equipamentos e status de frota do cliente A só mudando a URL. A sonda de sanidade (sem o parâmetro, o mesmo usuário vê apenas o seu) prova que o fixture era real.
  - **Telas afetadas**: `/relatorios/alarmes`, `/ocorrencias`, `/desatualizados`, `/ignicao`, `/velocidade`, `/paradas`, `/ociosidade`, `/status-frota` e `/equipamentos`.
  - **Correção**: `report_customer_scope()` em `includes/functions.php`, único ponto de decisão. Para não-admin o `?customer_id` é **ignorado por completo** — não validado — porque validar produziria respostas diferentes para "cliente inexistente" e "cliente que não é seu", vazando a existência. Sem cliente na sessão devolve `0`, que não casa com nada: falha **fechada**, onde antes `if ($customerId)` simplesmente omitia o filtro e um usuário mal provisionado via **tudo**.
  - `bi.php` já usava a forma correta (`if ($isAdmin && $filterCust)`) e ficou como estava — é o que mostra que o padrão errado era descuido, não desenho.
  - **Em aberto de propósito**: `$isAdmin` inclui `user_type === 'revendedor'`, então um revendedor continua podendo filtrar qualquer cliente. Se deve poder é pergunta de produto; esta correção fecha a falha sem mudar semântica de perfil no mesmo passe.
- **Link do relatório agora é assinado e expira** (`/download?j=…&exp=…&sig=…`, `includes/download_token.php`). O token de 32 hex da v4.7.1 matou a enumeração, mas não o link **vazado ou encaminhado**: conhecida a URL, ela valia para sempre, para qualquer um, sem login — e esses links viajam por e-mail e ficam parados em caixas de entrada. Agora há HMAC sobre `(job_id, expiração)` com `APP_KEY`, validade de 7 dias no e-mail e 1 hora no botão de `/exportar`.
  - **`storage/reports/.htaccess` com `Require all denied`** entrou junto, e é o que dá sentido à assinatura: com o caminho direto ainda aberto, o link antigo e eterno continuaria funcionando e o prazo seria decorativo. **Efeito colateral aceito**: links de relatório em e-mails enviados antes desta versão param de funcionar — eles eram exatamente o problema.
  - Assina o **ID do job**, não o caminho: o caminho vem do banco na hora do download, então não existe superfície de path traversal. `hash_equals()` para comparação em tempo constante, e o prazo é conferido **depois** da assinatura (o contrário diria ao atacante que a assinatura estava certa).

### Fixed
- **Job preso em `processando` para sempre.** O worker marca `processando` antes de trabalhar e só grava o desfecho depois; um **erro fatal** — não uma exceção, que o `catch` pega — mata o processo entre os dois pontos, e o job nunca mais é selecionado (a fila pega só `pendente`), nunca falha, nunca notifica. Aconteceu de verdade com o `php-zip` ausente (v4.7.2). `reapOrphanJobs()` recupera o que passou de 15 min e fecha **as duas pontas**: o job e a execução do agendamento, que sem isso ficaria em `enfileirado` e nunca contaria para a regra das 3 falhas.
- **Os SQL antigos deixaram de embutir o nome do banco.** `jimi_tracker.sql` (que também tinha `CREATE DATABASE`), `migration_v2.0.0`, `v3.1.0`, `v4.0.0` e `hotfix_login_log` ignoravam o banco passado ao cliente `mysql`. Consequência real, ocorrida em 30/07/2026: apontar a cadeia para um banco de teste executava os primeiros arquivos contra o `jimi_tracker` **real** e rebaixava `system_info.version` para `4.0.0` — justamente o valor que o gate do `deploy.sh` lê. **Verificado**: cópia limpa montada em `jimi_teste_copia` chegou a `4.7.0` com 54 tabelas, e o banco real não foi tocado.
  - O comando de instalação em `CLAUDE.md` mudou: o `CREATE DATABASE` agora é explícito, e trocar o nome do banco em todas as linhas passa a bastar.

### Notas de implementação
- **O que a auditoria do §11 encontrou e o que NÃO encontrou** — o valor de registrar os dois:
  - *CSRF em todos os POST*: **sustenta**. 22 handlers tratam POST, 21 chamam `csrf_verify()`; as exceções (`login`, `setup`) são legítimas. Nenhuma escrita no banco restou alcançável por GET — varredura por profundidade de blocos sobre 76 escritas; `customer_switch.php` e `sendcommand.php` apareceram como suspeitos e se confirmaram seguros (guarda incondicional e rejeição de não-POST).
  - *Prepared statements*: **sustenta**, checado nos três lugares onde PDO não protege sozinho. (a) Interpolação em `query()`/`exec()`: só duas no projeto, ambas benignas (`(int)` vindo do banco; literal constante). (b) `ORDER BY` dinâmico em ~10 telas — PDO não parametriza identificador — protegido por whitelist estrita (`in_array(..., true)` com fallback) em `report_sort_params()`, e `$order` restrito a `ASC|DESC`. (c) `LIMIT $perPage OFFSET $offset`, presente em 22 pontos e **invisível à primeira varredura**, que só olhava `query()`/`exec()` e não `prepare()`: todos os 22 derivam de `max(1, (int)$_GET['page'])` com `$perPage` literal, e os `$limit` de endpoints AJAX são `min(max((int)…))` — nenhuma string do usuário chega ao SQL.
  - *Multi-tenancy*: **NÃO sustentava** — ver acima.
- **Verificação**: `php -l` **0 erros** em 110 arquivos; **21 asserções** de escopo cross-tenant com dois clientes e um usuário não-admin reais, exercitando as **9 telas** por HTTP e com guarda de vacuidade em cada uma (HTML curto, `Fatal error`, `Warning:` ou "Erro interno" invalidam a asserção seguinte); **12 asserções** do download assinado, das quais 9 negativas — assinatura forjada, ausente, prazo vencido, `exp` esticado com assinatura válida, assinatura reusada em outro job, `result_path` com `../` e arquivo purgado; **5 asserções** do varredor de órfãos, incluindo as duas que importam (job de 2 min **não** é tocado; 2ª execução é idempotente); e a prova da cópia limpa de banco.

## [Unreleased] — 4.7.2

Versão de correção, sem tela nova e **sem migração**. Nasceu de duas perguntas do
usuário — "qual é o status?" e "trabalhe tudo em GMT-3" — e do que a conferência do
servidor revelou por baixo delas.

### Security
- **Exclusão destrutiva por GET, sem token CSRF, em QUATRO telas.** `csrf_verify()` não lê da query string, então `GET ?action=excluir&id=N` estava inteiramente fora do alcance da proteção: bastava um `<img src="/geocercas?action=excluir&id=3">` em qualquer página que um usuário logado abrisse para o navegador enviar o cookie de sessão sozinho e concluir a exclusão — sem clique, sem confirmação, sem rastro que parecesse ataque. Todas as quatro passam a **POST com `csrf_field()`**:
  - **`/geocercas`** — apagava a cerca e, por `ON DELETE CASCADE`, todo o histórico de eventos dela. Era o caso já registrado no `STATUS.md`.
  - **`/config-notificacoes`** — apagava regra de notificação, inclusive regra **global**.
  - **`/config-ocorrencias`** — apagava perfil de ocorrências.
  - **`/checklist`** — o pior dos quatro: além de não ter CSRF, **não tinha checagem de escopo nenhuma**, então o `id` da query string apagava o checklist de *qualquer* cliente, e os itens dele junto. Ganhou a verificação de escopo (global só admin; cliente só o próprio). **Não** ganhou `require_permission()` de propósito: a tela não está na matriz de `/grupos-permissao` e `can()` nega tela ausente da matriz — exigir permissão ali daria 403 a todo usuário de grupo restrito.
  - O padrão aplicado é o mesmo nos quatro: o bloco de exclusão fica **dentro** do POST e **mutuamente exclusivo** do bloco de salvar (guarda `action !== 'excluir'`). Sem essa exclusividade, um POST de exclusão cairia também no `save` com o formulário vazio e criaria um registro em branco como efeito colateral.
- **`/geocercas`**: todo caminho da exclusão termina em `exit` (Post/Redirect/Get com enum de flash fechado). O detalhe da exceção vai para o log em vez da tela.

### Fixed
- **`APP_URL` ausente no `.env` do homolog** — encontrada na conferência de 01/08/2026, e é o defeito mais silencioso da série do agendamento: sem ela o `$base` fica vazio, o botão "Baixar relatório" do e-mail vira um href **relativo** que não resolve em caixa de entrada nenhuma, o provedor aceita o envio e o histórico marca **"enviado"**. Ninguém descobre exceto o destinatário.
  - O `scripts/worker.php` agora **aborta** a entrega quando o relatório passa de `MAIL_MAX_ATTACH_MB` e `APP_URL` está vazia, registrando o motivo no histórico do agendamento (que por sua vez alimenta a regra das 3 falhas). Falhar visível é melhor do que entregar link morto.
  - O e-mail de **notificação** apenas **omite o botão** no mesmo caso, em vez de renderizar um link quebrado: ali a URL é um atalho, não o conteúdo.
  - `.env.example` passa a marcar `APP_URL` como obrigatória quando há relatório agendado.

- **`php-zip` faltava no homolog — o XLSX nunca funcionou lá.** Descoberto ao rodar o Bloco 2 do plano de validação: o worker morria com `Class "ZipArchive" not found` em `includes/export_helper.php:123`. Como XLSX é o formato **padrão** de `/exportar` e do relatório agendado, toda exportação nesse formato falhava no servidor — e falhava **em silêncio**: o fatal mata o processo antes de qualquer `UPDATE`, então o job ficava preso em `processando` e a execução em `enfileirado` para sempre, sem nada no histórico. Extensão instalada (`php8.3-zip` + restart do FPM).
  - **A raiz não era a extensão, era a ausência de checagem**: `deploy.sh` validava `pdo pdo_mysql json mbstring` e nunca `zip`. Agora valida `zip` e `openssl` (este é o que permite ao `mailer.php` abrir `ssl://smtp:465`) — e a comparação virou `grep -qix`, casando a linha inteira: com o `-i` solto de antes, `pdo` casava com `pdo_mysql` e um PHP sem PDO passaria batido desde que tivesse `pdo_sqlite`.

### Changed
- **Log e `/ping` em BRT (GMT-3).** O SO do servidor é `America/Sao_Paulo` e o PHP roda em `UTC`: o mesmo evento aparecia com **três horas de diferença** conforme se olhasse o `ls -la` (mtime, BRT) ou o conteúdo do arquivo (carimbo, UTC). Agora `Logger` carimba em BRT, **e o nome do arquivo diário também** — se só o carimbo mudasse, tudo entre 21:00 e 00:00 BRT cairia no arquivo do dia seguinte e `tail logs/webhook_$(date +%F).log` no servidor apontaria para o arquivo errado nessa faixa. `/ping` ganhou o campo `timezone`, para a resposta não depender de quem a lê saber o fuso.
  - **O armazenamento continua em UTC e nenhuma linha do banco foi tocada.** É o desenho correto: os devices transmitem GMT 0, a conexão PDO força `time_zone = '+00:00'` e a conversão para BRT acontece na exibição (`fmt_brt()`, `CONVERT_TZ`), em 146 pontos do código. `Logger::stamp()` existe **só** para texto lido por gente e traz o aviso de nunca ser usado para montar valor destinado ao banco — gravar BRT numa coluna UTC misturaria dois fusos na mesma coluna, dano silencioso e caro de desfazer. Os `date()` que alimentam o banco (`metrics_rollup.php`, os `push*.php`, `occurrence_engine.php`) foram auditados um a um e deixados intactos.
- **`SYSTEM_VERSION`** de `4.7.0` para `4.7.2` no `.env.example`: o `/ping` do homolog reportava `4.7.0` mesmo com o código da v4.7.1 publicado, porque a v4.7.1 não subiu a variável.

### Notas de implementação
- **`docs/PLANO_VALIDACAO_AGENDAMENTOS.md` CONCLUÍDO.** O Bloco 2 rodou contra o provedor real: e-mail enviado pelo `smtp.task.com.br` com XLSX de 419 linhas para 3 destinatários, e **a chegada foi confirmada pelo usuário em 01/08/2026** (itens 4, 5 e 6 do roteiro). Com isso o Bloco 4 (SPF/DKIM) deixa de ser necessário — mas fica registrado que o `mailer.php` não assina DKIM, e que a decisão, se um dia cair em spam, é trocar por API HTTP transacional em vez de implementar DKIM artesanal.
  - **Lacuna consciente**: o item 10 (3 falhas consecutivas desativam e notificam) **não** foi exercitado contra o provedor real — exigiria 3 ciclos com destinatário em domínio inexistente. A lógica tem cobertura local desde a v4.7.0, com SMTP de captura.
- **Verificação**: `php -l` **0 erros** em 108 arquivos (`handlers config core includes scripts web`); **15 asserções** de fuso com o PHP forçado em UTC, cobrindo a virada do dia nos dois sentidos (02:00 UTC → dia BRT anterior; 23:30 UTC → mesmo dia) e o **horário de verão histórico** — 16/02/2018 09:00 UTC dá **07:00** BRT porque o DST vigorava, e somar 3 h fixas erraria em uma hora; mais as duas asserções que provam que o armazenamento não mudou (`date()` do processo continua UTC, fuso default intocado). **24 asserções** de CSRF nas 3 telas sem cobertura Playwright (GET inócuo, POST sem token → 403, POST com token exclui). **Playwright 9/9** em `geocercas.spec.js`, com uma guarda de regressão nova que cria uma cerca, tenta apagá-la por `GET ?action=excluir` e exige que ela continue lá. **39 asserções** do Bloco 2 no próprio homolog, rodadas como root para reproduzir o cron.
  - A suíte completa acusou **1 falha legítima** na primeira execução: o spec de geocercas procurava `a:has-text("Excluir")`, o link que virou botão. É exatamente o tipo de regressão que o `php -l` não pega — o mesmo papel que a suíte cumpriu na v4.7.0 com a armadilha do CRLF.

## [4.7.1] — 2026-07-30

Fecha a iniciativa do `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`: as duas decisões que o
Bloco 3 do `docs/PLANO_VALIDACAO_AGENDAMENTOS.md` deixou em aberto e a **Fase 5** (a
Central de Ajuda). Nenhuma tela nova e nenhuma migração — é a versão que torna
publicável o que as quatro fases anteriores entregaram.

### Security
- **O nome do relatório gerado deixa de ser adivinhável.** `storage/` é servido como estático (o `.htaccess` da raiz só reescreve o que **não** é arquivo, `!-f`), então `storage/reports/<arquivo>` nunca passou por `require_login()`. O nome era `report_<job_id>_<timestamp>.<ext>` — `job_id` sequencial e timestamp com granularidade de segundo, isto é, **enumerável**: num sistema multi-tenant, o relatório de posições de um cliente era baixável por quem não é dele e não está logado. Confirmado no homolog antes da correção (`curl` sem cookie → **200** e o conteúdo). Agora o nome leva **32 hex de `random_bytes(16)`**. O mesmo vale para o vídeo baixado em `storage/media`, pelo mesmo motivo e com a mesma correção.
  - **Por que token e não rota autenticada**: o relatório agendado que passa de `MAIL_MAX_ATTACH_MB` viaja como **link** dentro do e-mail, e exigir login quebraria justamente esse caminho. O token mantém o link funcionando e elimina a enumeração. Uma URL assinada com validade (`?exp=…&sig=…`) continua sendo o alvo melhor e está registrado como tal — não foi feito aqui.
- **`storage/.htaccess`** (versionado): `Options -Indexes` — com índice ligado, o token do nome não valeria nada, bastaria listar o diretório — e negação de execução para `.php`/`.phtml`/`.phar`/`.cgi`/`.pl`/`.py`/`.sh`. Não usa `php_flag engine off`: essa diretiva só existe em mod_php e derruba o Apache com 500 sob PHP-FPM, que é a configuração deste projeto. O `.gitignore` passou de `storage/` para `storage/*` + `!storage/.htaccess`, porque negação dentro de diretório excluído o git ignora — e o arquivo precisa chegar ao servidor pelo `git pull` do deploy, não pela memória de quem publica.

### Added
- **Retenção dos relatórios gerados** (`REPORT_RETENTION_DAYS`, padrão **30**): o `scripts/log_cleanup.php` — que já roda diariamente e já sabe ler o `.env` sem banco — passa a apagar os arquivos de `storage/reports` além da idade e, junto, as linhas de `report_schedule_runs` mais antigas que isso. Antes, **nada** apagava relatório: um agendamento diário produzia 1 arquivo por dia indefinidamente, cada um uma cópia de dado de cliente parada em disco.
  - `0` **desliga** a purga. O valor é lido sem `?:` de propósito: `'0'` é falsy em PHP e o operador devolveria o padrão de 30 dias justamente para quem escreveu 0 querendo desligar — a mesma armadilha já documentada em `occurrence_engine.php`.
  - A conexão é PDO direto em `try/catch`, e não `Database::getInstance()`: o construtor da classe dá `exit` em falha e este script precisa continuar limpando disco com o banco fora. Banco indisponível apenas pula a purga do histórico, com aviso no log.
  - **`storage/media` fica intocado**: vídeo de ocorrência é evidência vinculada a uma tratativa, não subproduto de consulta. Apagá-lo por idade é decisão de produto.
- **Central de Ajuda atualizada para a v4.7.1** (Fase 5 do plano, o último passo da iniciativa): `handlers/wiki.php` estava congelada na v4.3.0. Entraram 12 seções — **Notificações** (sino, pop-up, som, e-mail), **Config. Notificações**, **Servidor de E-mail**, **Geocercas**, **Relatório de Geocercas**, **Status da Frota**, **Paradas**, **Ociosidade**, **Ignição**, **Excesso de Velocidade**, **Agendamentos** e **Modelos salvos** — com os limiares que definem cada estado (3 km/h, 30 min de silêncio, 80 km/h de limite padrão).
  - A wiki documenta o que **só ela** pode explicar: que o sistema **notifica por ocorrência e não por alarme** (uma rajada de 12 alarmes vira 1 aviso — é o agrupamento funcionando, e sem esse parágrafo o operador conclui que o sistema falhou), o teto de 60 notificações/hora, e que o link do relatório grande é **secreto mas não exige login**.

### Changed
- **`/exportar`** mostra **"Expirado"** no lugar do botão Baixar quando o arquivo já foi purgado — o job continua `concluido` e o `result_path` continua gravado, mas o botão levaria a um 404 sem explicação. `/exportardata` ganhou o campo `expired` (o `result_path` foi preservado como estava: quem já o guardou continua vendo o mesmo valor).
- **`.env.example`**: `REPORT_RETENTION_DAYS` documentado.

### Notas de implementação
- **Verificação**: `php -l` limpo; **48 asserções** em 2 suítes (32 da retenção e do nome tokenizado, em sandbox isolado com banco de teste próprio — inclusive o caso "banco fora não impede a limpeza de disco" — e 16 ponta-a-ponta com o `worker.php` real gerando o arquivo e a tela indo de "Baixar" a "Expirado") + **43 asserções** da wiki, com a página renderizada autenticada e conferida contra o código: nenhum link do índice sem seção, os 22 nomes de tela do RBAC batendo com `grupos_permissao.php` e os rótulos do menu lateral com a mesma grafia.
- **O que continua pendente e não é código**: o envio agendado nunca rodou contra provedor real a partir do cron (Bloco 2 do plano de validação) — localmente foi validado contra um SMTP de captura, e no homolog só o botão "Enviar e-mail de teste" foi exercitado.

## [Unreleased] — 4.7.0

### Added
- **Relatório agendado por e-mail** (Fase 4 de `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`, a última da série): o relatório configurado uma vez chega sozinho na frequência escolhida — **diária**, **semanal** ou **mensal** — para até 3 destinatários, em `/agendamentos`. Fecha a iniciativa iniciada na v4.4.0: o `includes/mailer.php` construído para as notificações é o mesmo que entrega os relatórios.
- **`scripts/schedule_dispatcher.php`** (cron `5 * * * *`): enfileira o que venceu e nada mais. Quem gera o arquivo e envia o e-mail é o `scripts/worker.php` — mesma separação que vale para notificações, e a razão de nenhuma conversa SMTP acontecer no caminho de uma requisição.
- **`includes/schedule.php`**: `brt_hour_to_utc()`, `schedule_next_run()`, `schedule_period_days()`, `schedule_describe()`. A tela e o cron usam **as mesmas funções**, para que o "próximo envio" exibido não possa divergir do que vai acontecer.
- **`/agendamentos`**: CRUD com recorrência traduzida em português ("Toda segunda-feira às 07:00 (BRT)"), próximo/último envio em BRT, contador de falhas visível e **histórico de execuções** (período coberto, nº de registros, status e o erro real do provedor). Sem o histórico, "o relatório não chegou" é indepurável — não se distingue agendamento que nunca disparou de e-mail recusado.
- **Modelos de relatório** (`includes/report_templates.php`): "salvar filtros atuais como…" e um seletor que os repõe, em **10 telas** de relatório. O modelo guarda a **query string** da tela, não uma estrutura por relatório: é o que a tela já sabe interpretar, serve para qualquer filtro presente ou futuro e dispensa mapeamento tela a tela. Escopo por **usuário** — o filtro de quem trata ocorrências não é o de quem audita combustível, e os dois podem ser do mesmo cliente.
- **Anexo com nome amigável**: `Excesso de velocidade - 20-07-2026 a 26-07-2026.xlsx` em vez de `report_66_20260730_004335.xlsx`, que não diz nada a quem recebe e colide visualmente na caixa de entrada.
- **Migração v4.7.0**: `report_schedules`, `report_schedule_runs`, `report_templates` e `jobs.schedule_run_id`. Idempotente — validada com duas execuções seguidas (exit 0 nas duas).
- **Specs**: `tests/agendamentos.spec.js` (19 casos: ciclo do agendamento, campos por frequência, ciclo dos modelos) + a rota nova em `tests/navigation.spec.js`.

### Changed
- **`scripts/worker.php`** passa a contar as linhas escritas e, quando o job veio de um agendamento, a entregar por e-mail. Acima de `MAIL_MAX_ATTACH_MB` (5 por padrão) o arquivo vira **link** em vez de anexo: provedor recusa anexo grande, e e-mail recusado é pior do que link. Teto de `SCHEDULE_MAX_ROWS` (100.000) por relatório assíncrono — sem teto, um relatório de milhões de linhas estoura a memória do worker e derruba a fila inteira, inclusive as notificações que estavam atrás dele.
- **`scripts/crontab-setup.sh`**: `schedule_dispatcher.php` no array `CRON_JOBS` — **7 workers**. **`scripts/deploy.sh`**: `run_migration "4.7.0"`. **`.env.example`**: `MAIL_MAX_ATTACH_MB` documentado (o link usa `APP_URL` como base, que precisa estar correta).
- **`/exportar`**: botão "Agendados" e um resumo com quantos agendamentos estão ativos e quando é o próximo envio. A fila mostra o resultado; quem quer saber *por que* um relatório chegou (ou não) vai para `/agendamentos`.
- **Navegação**: "Agendamentos" no grupo Relatórios (38 rotas) e a tela nova na matriz de `/grupos-permissao`.

### Fixed
- **`/relatorios/geocercas` quebrou e foi consertado dentro desta fase**: o script que injetou o maquinário de modelos nos handlers usou uma regex com `\n`, que **não casa com CRLF** — e `rel_geocercas.php` é o único arquivo do repositório com terminação CRLF. O resultado foi a chamada de `render_template_bar()` inserida **sem** o `require_once` correspondente, e a tela passou a devolver "Erro interno" (`Call to undefined function`). O lint não pega isso: a função existe, só não está carregada. Quem pegou foi a suíte Playwright completa, rodada depois da mudança — e a lição é que uma edição automatizada em lote precisa ser auditada arquivo por arquivo, não conferida por amostragem.

### Notas de implementação
- **Fuso é o ponto de maior risco da fase, e é tratado por `DateTimeZone`, nunca por offset fixo.** `send_hour` é BRT (o que o usuário digita); `next_run_at` é UTC (o que o cron compara com `NOW()`). O Brasil aboliu o horário de verão em 2019, então hoje janeiro e julho dão o mesmo offset — o teste confirma isso em vez de supor, **e** ancora uma asserção em 16/02/2018, quando o DST estava vigente: lá 07:00 BRT são **09:00 UTC**, e somar 3 h na mão erraria a data em uma hora. O cálculo é feito no calendário BRT ("toda segunda às 7h" é uma afirmação sobre o calendário do usuário) e só então convertido.
- **Reentrância por UPDATE condicional.** O dispatcher move `next_run_at` **antes** de enfileirar, num `UPDATE ... WHERE next_run_at = <valor lido>`. Dois processos simultâneos: um move a linha, o outro afeta 0 linhas e desiste. Enviar o mesmo relatório duas vezes é o defeito que o usuário percebe primeiro e perdoa por último.
- **O período é sempre o fechado anterior**, nunca o corrente: quem recebe o diário às 7h quer o dia de ontem inteiro, não as 7 horas de hoje. Semanal é segunda a domingo da semana passada; mensal é o mês passado inteiro. Tudo em dias BRT convertidos para janela UTC por `brt_day_range_to_utc()`.
- **Job `concluido` com entrega falha é proposital.** O arquivo existe e fica baixável em `/exportar`; marcar o job como falho esconderia o `result_path` e perderia o artefato. A falha aparece onde importa — no histórico do agendamento —, e é ele que alimenta a regra das 3 falhas.
- **3 falhas CONSECUTIVAS desativam** e notificam o criador. Sucesso **zera** o contador: sem o reset, três tropeços espalhados por meses derrubariam um agendamento saudável. Editar ou reativar também zera — mexer na configuração é a resposta do usuário ao problema.
- **`vazio` é um status próprio**, distinto de enviado e de falhou: "não enviei porque não havia nada" não é erro, e confundir os dois faria `skip_if_empty` desativar o agendamento depois de 3 dias tranquilos.
- **Excluir e alternar são POST com CSRF.** `csrf_verify()` só lê o token de `$_POST` ou do cabeçalho; ação destrutiva por GET é acionável por um `<img src="…">` em qualquer página que o usuário logado abra. ⚠️ **`/geocercas?action=excluir` (v4.5.0) tem exatamente esse problema e continua pendente** — ver §4.7 do plano.
- **Dia do mês limitado a 28**: 29/30/31 não existem em todo mês, e pular fevereiro nunca é o que o usuário quis dizer.
- **`fleet_status` não é agendável** — é uma foto do agora, e "o estado da frota de ontem às 7h" não significa nada.
- **Verificado com SMTP de verdade**: um servidor de captura mínimo recebe a mensagem e o teste inspeciona o `.eml` — `multipart/mixed`, MIME `spreadsheetml.sheet`, nome do anexo, e o anexo decodificado com assinatura `PK` (zip válido). O caminho do link foi exercitado baixando `MAIL_MAX_ATTACH_MB` a ~100 bytes.

## [Unreleased] — 4.6.0

### Added
- **Relatórios operacionais** (Fase 3 de `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`): cinco telas novas — **Status da Frota** (`/relatorios/status-frota`), **Paradas** (`/relatorios/paradas`), **Ociosidade** (`/relatorios/ociosidade`), **Ignição** (`/relatorios/ignicao`) e **Excesso de Velocidade** (`/relatorios/velocidade`).
- **`scripts/state_builder.php`** (cron de 15 min): segmenta `gps_data` em `device_state_segments` (`movimento` / `ocioso` / `parado` / `offline`) e apura `speeding_events`. **Um worker alimenta quatro das cinco telas** — Parada, Ociosidade, Ignição e Status da Frota são recortes da mesma segmentação. Calcular cada tela na hora da consulta varreria `gps_data` quatro vezes com a lógica escrita quatro vezes, e as telas divergiriam na primeira correção aplicada a só uma delas. Aceita backfill: `php scripts/state_builder.php 30` (e um IMEI como 2º argumento para reprocessar um equipamento).
- **`includes/fleet_state.php`**: fonte única das regras de estado — os limiares (`STOP_SPEED_KMH`, `STOP_IDLE_SECONDS`, `OFFLINE_GAP_SECONDS`, `DEFAULT_SPEED_LIMIT_KMH`, `MIN_SPEEDING_POINTS`), `classify_point()`, `resolve_current_state()`, `resolve_speed_limit()` e `fmt_duration()`. Consumido pelos dois workers e pelas cinco telas.
- **Limite de velocidade com precedência equipamento → cliente → global** (80 km/h): campo em `/equipamentos` (`devices.speed_limit_kmh`) e em `/clientes` (`customers.default_speed_limit_kmh`). O limite apurado é **gravado no evento** — mudar o limite hoje não reescreve o histórico, e quem audita precisa saber contra qual limite a infração foi apurada.
- **Export assíncrono dos 5 tipos novos** em `/exportar` (`stops`, `idling`, `ignition`, `speeding`, `fleet_status`), já preparando os relatórios agendáveis da Fase 4.
- **Migração v4.6.0**: `device_state_segments` e `speeding_events`, mais as duas colunas de limite. Idempotente — validada com duas execuções seguidas (exit 0 nas duas).
- **Specs**: `tests/relatorios-operacionais.spec.js` (19 casos: estrutura, preservação de filtros, drill-down, export, coerência entre telas) e as 5 rotas novas em `tests/navigation.spec.js`.

### Changed
- **`scripts/trip_builder.php` passa a consumir os limiares de `includes/fleet_state.php`** em vez de declarar `STOP_SPEED_KMH`/`STOP_IDLE_SECONDS` localmente. Se "parado" na segmentação de viagens significar algo diferente de "parado" no relatório de paradas, as duas telas se contradizem e nenhuma é auditável (risco R6 do plano).
- **`includes/report_segments.php`**: corpo comum de Paradas e Ociosidade, que são a mesma consulta com um `state` diferente. Dois arquivos de 250 linhas quase idênticos garantiriam que a primeira correção fosse aplicada a só um deles — o mesmo problema que a fase resolveu no banco e que seria incoerente reintroduzir na exibição.
- **`scripts/crontab-setup.sh`**: `state_builder.php` no array `CRON_JOBS` (15 min, `logs/state_builder.log`) — **6 workers**. **`scripts/deploy.sh`**: nova linha `run_migration "4.6.0"`.
- **Navegação**: 5 itens no grupo Relatórios (37 rotas). As telas herdam a permissão `relatorios` já existente, então **nenhum ajuste em `/grupos-permissao` é necessário** — quem já exporta relatórios enxerga as novas.

### Fixed
- **`/geocercas` re-renderizava o formulário vazio depois de salvar** (v4.5.0): o POST caía no mesmo `?action=nova`, então a página voltava a ser o formulário em branco com o toast "Geocerca criada." — o usuário nunca via o registro na grade e um F5 reenviava o POST, criando uma cerca duplicada. Agora há Post/Redirect/Get para `/geocercas` com a mensagem em código fechado na URL. Encontrado ao rodar os specs da Fase 2, que tinham sido escritos mas nunca executados com credenciais.
- **`tests/geocercas.spec.js`**: o teste de export em CSV usava `page.goto()` numa URL que dispara download e falhava com "Download is starting" — o navegador nunca navega. Passou a usar `page.request.get()`, que carrega o cookie de sessão sem navegar, e ainda verifica o `content-type`.

### Notas de implementação
- **A invariante que torna a segmentação auditável**: os segmentos de um equipamento são **contíguos e sem sobreposição** — o `ended_at` de um é exatamente o `started_at` do seguinte. Daí decorre que **a soma das durações de um dia fecha em 86.400 s**, que é o teste de aceite e o único capaz de revelar furo de segmentação. Duas regras produzem a propriedade: **mudança de estado** põe a fronteira no `gps_time` do ponto **novo** (o estado antigo acaba no instante em que o novo é observado); **buraco de dados** põe a fronteira no ponto **anterior**, e um segmento `offline` cobre o vão. Fechar no ponto anterior é o que impede creditar 6 h de "movimento" a um veículo que ficou sem sinal.
- **O último segmento fica aberto (`ended_at IS NULL`) de propósito.** O estado está em curso; fechá-lo a cada rodada fatiaria um estado em andamento em pedaços de 15 min. A rodada seguinte retoma do `started_at` dele e o reescreve por `ON DUPLICATE KEY UPDATE` sobre `uk_dss_imei_start` — mesma chave, mesma linha. Rodar duas vezes sobre a mesma janela não duplica nem fragmenta.
- **Segmento de duração zero não é gravado.** Um ponto isolado seguido de buraco fecharia no próprio instante que o abriu, e o segmento `offline` do vão começaria no mesmo instante: os dois disputariam `(imei, started_at)` e o offline sobrescreveria o outro. O resultado seria certo por acidente — melhor descartar de propósito. Nada se perde: um instante isolado não sustenta afirmação de duração.
- **`offline` nunca sai de `classify_point()`**: é ausência de ponto, não propriedade de um ponto. Quem detecta buraco é o worker, comparando `gps_time` consecutivos.
- **O estado corrente do Status da Frota é resolvido na leitura, não lido do segmento.** Um veículo que parou de reportar às 3h da manhã tem segmento aberto em `movimento`; mostrar "em movimento" às 10h seria mentira. `resolve_current_state()` derruba para `offline` quem não reporta há mais de 30 min, qualquer que fosse o estado anterior — entre duas rodadas do cron a verdade muda sem que nenhum dado novo entre no banco.
- **A soma dos quatro estados é sempre o total de equipamentos ativos**: a lista parte de `devices`, não dos segmentos, e equipamento sem segmento algum cai em `offline`.
- **O relatório de Ignição exclui os segmentos `offline` da janela do `LAG`.** Durante o silêncio não se sabe o que a ignição fez; incluir o offline inventaria dois acionamentos (desligou ao sumir, ligou ao voltar) que ninguém observou. A janela interna começa 2 dias antes do filtro para que o `LAG` do primeiro segmento do período tenha um anterior de verdade — sem essa folga, a primeira transição do período se perderia sempre que o veículo tivesse passado a noite desligado.
- **Piso de 2 pontos para excesso de velocidade**: um único ponto acima do limite é indistinguível do salto de leitura de GPS (140 km/h numa via urbana). Velocidade **igual** ao limite não é infração — a regra é `>`.
- **Duração de segmento em curso é contada até agora** (`COALESCE(duration_s, TIMESTAMPDIFF(...))`), inclusive no filtro de duração mínima e na ordenação: sem isso, a parada que começou há 4 h e não terminou apareceria com duração vazia e iria para o fim da lista — justamente a que mais interessa a quem audita.
- **Ponto (0,0) é descartado** antes da segmentação (R06): usá-lo faria a distância do segmento saltar milhares de km via golfo da Guiné.
- **Limitação conhecida**: ponto que chega atrasado, com `gps_time` anterior à marca-d'água, não é reprocessado — mesma limitação do `trip_builder.php`. Para corrigir uma janela histórica, rodar o backfill com o IMEI como 2º argumento.

## [Unreleased] — 4.5.0

### Added
- **Geocercas e POIs** (Fase 2 de `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`): áreas monitoradas desenhadas na própria plataforma, em **círculo** (centro + raio) ou **polígono** (vértices), vinculadas a equipamentos. Cada travessia da borda vira um evento de entrada ou saída, notifica pelo motor da v4.4.0 e alimenta o relatório de permanência. Peças novas: `includes/geofence.php` (geometria pura), `scripts/geofence_worker.php` (cron de 2 min), `handlers/geocercas.php` (CRUD + desenho no mapa) e `handlers/rel_geocercas.php` (relatório).
- **Desenho no mapa com Leaflet puro** (`/geocercas`): círculo = clique define o centro (marcador arrastável) e um campo numérico define o raio; polígono = cliques acumulam vértices, com "Desfazer" e "Limpar". Sem `leaflet-draw` — evita uma dependência de CDN por um ganho de UX pequeno, e o Leaflet já estava no projeto. A cor escolhida é aplicada ao vivo no desenho e volta na grade e no relatório.
- **Relatório de Geocercas** (`/relatorios/geocercas`) em duas modalidades: **entradas e saídas** (lista crua, com velocidade e link para o mapa) e **permanência**, que pareia cada entrada com a saída seguinte por função de janela (`LEAD` particionado por cerca × equipamento) e mostra o tempo dentro da área. Entrada sem saída no período aparece como "Em permanência" — leitura correta tanto para quem ainda está lá dentro quanto para quem saiu depois do fim do filtro. Segue o molde de `rel_alarmes.php`: teto de 31 dias, ordenação por whitelist, paginação com janela deslizante e export XLSX/PDF/CSV.
- **Migração v4.5.0**: `geofences`, `geofence_devices`, `geofence_state` e `geofence_events`. Idempotente — validada com duas execuções seguidas (exit 0 nas duas).

### Changed
- **`haversine()` promovida de `scripts/trip_builder.php` para `includes/functions.php`** como `haversine_km()`: o teste de raio da geocerca precisa exatamente da mesma medida da segmentação de viagens, e duas implementações no repositório divergiriam. `calculate_distance()` fica **intocada** de propósito — ela usa lei dos cossenos e **retorna 0 quando qualquer latitude é 0** (guarda contra GPS inválido que, aplicada a uma cerca, tornaria todo ponto na linha do Equador "dentro de tudo"), e há chamador legado (`pushgps.php`) dependendo daquele comportamento.
- **`scripts/crontab-setup.sh`**: `geofence_worker.php` entrou no array `CRON_JOBS` (a cada 2 min, `logs/geofence.log`). **`scripts/deploy.sh`**: nova linha `run_migration "4.5.0"`.
- **Navegação**: "Geocercas" no grupo Cadastros e no grupo Relatórios; nova tela na matriz de `/grupos-permissao`; rotas `/geocercas` e `/relatorios/geocercas` no router (32 rotas).

### Notas de implementação
- **O evento é sempre gravado; `alert_on` decide apenas se notifica.** Sem os dois lados do par, o relatório de permanência não teria o que parear — silenciar o alerta não pode silenciar o histórico.
- **Anti-flapping por histerese de 50 m.** Um veículo parado sobre a borda oscila dentro/fora a cada ponto (a precisão típica de GPS já é de 5–15 m) e geraria dezenas de pares em meia hora. A borda vira uma faixa: **entrar** exige cruzar a borda real; **sair** exige afastar-se mais de 50 m dela. Medido no teste: 30 minutos de oscilação entre 185 m e 215 m de uma cerca de 200 m produziram **1** evento, não 30.
- **Em polígono, a histerese mede a distância até a aresta, não até a bounding box.** A bbox expandida — solução mais barata — manteria "dentro" um veículo parado no vão da concavidade de uma cerca em "L", a centenas de metros da área real.
- **Reexecução do worker é inofensiva**: os eventos entram com `INSERT IGNORE` sob a `UNIQUE (geofence_id, imei, event_time)`. Rodar duas vezes sobre a mesma janela não duplica.
- **Custo proporcional ao que foi configurado**: o worker parte de `geofence_devices` (cerca sem equipamento vinculado não custa nada, device sem cerca nunca é lido), lê incrementalmente por `geofence_state.last_gps_time` e pré-filtra por bounding box gravada — quatro comparações de float antes de qualquer trigonometria. A bbox é calculada **ao salvar**, em `handlers/geocercas.php`, não a cada avaliação.
- **Cerca nova não gera entrada retroativa**: sem estado gravado, o primeiro ponto avaliado apenas **semeia** o estado. Do contrário, desenhar uma cerca sobre a garagem produziria uma "entrada" para cada veículo já estacionado ali. Editar a geometria apaga `geofence_state` pelo mesmo motivo — o estado antigo descreve uma cerca que não existe mais.
- **Ponto (0,0) é descartado** antes de qualquer avaliação (R06): coordenada inválida não diz nada sobre posição e criaria entrada/saída fantasma.

## [Unreleased] — 4.4.1

### Fixed
- **Gate de migração reaplicava a cadeia inteira a cada deploy** (`scripts/deploy.sh`): cada bloco comparava a versão do banco com `!=` da sua própria, então um banco em 4.4.1 satisfazia `!= "4.2.1"` e disparava tudo de novo — `4.4.1 → aplica v4.2.1 → banco vira 4.2.1 → aplica v4.3.0 → 4.3.0 → …`. Só não quebrava porque as migrações são idempotentes; em troca, o banco era **temporariamente rebaixado no meio do deploy**, as mensagens "versão atual" mentiam e o custo crescia a cada release. Substituído por gate **semântico** (`sort -V`): a migração roda apenas quando a versão do banco é menor que a dela. Os 8 blocos duplicados (176 linhas) viraram `version_lt()` + `db_version()` + `run_migration()` e 8 chamadas de uma linha (68 linhas). Validado com 16 casos, incluindo a armadilha lexicográfica (`4.9.0 < 4.10.0`, que uma comparação de string erraria).
- **`/ping` reportava a versão fixa `2.0.0`** (`handlers/ping.php`): string herdada da primeira versão do arquivo e nunca atualizada — o endpoint anunciava uma versão que não existia havia dois anos de releases, e era justamente ela que o `deploy.sh` imprimia no fim de cada publicação. Agora lê `SYSTEM_VERSION`. A leitura é feita por um parser mínimo do `.env`, **sem** `Database::getInstance()`, de propósito: o `/ping` precisa continuar respondendo com o MySQL fora, senão deixa de distinguir "aplicação morta" de "banco fora" — que é a razão de a sonda existir.

### Added
- **`handlers/pushterminalrealtimestatus.php` voltou ao versionamento, documentado**: o arquivo existia **apenas no disco do servidor de homologação** desde 11/03/2026 — presente em produção, ausente do git e, portanto, fora de qualquer revisão, lint ou deploy. É um endpoint de **diagnóstico**: recebe o push de "Real-Time Status" das câmeras e só registra o payload bruto em `logs/terminal_realtime_status_YYYY-MM-DD.log`, sem validar token, normalizar ou persistir no banco — os dados não alimentam nenhuma tela do produto. O docblock agora explica: (a) por que é a exceção deliberada à regra de estender `WebhookHandler` (a classe base exige token, faz idempotência e abre transação — pipeline inútil para um coletor de payload cru, que precisa ver inclusive o payload malformado que a base descartaria); (b) que **não** está no `$webhookRoutes` do router e é alcançado pelo caminho direto `POST /handlers/pushterminalrealtimestatus.php`, porque o `.htaccess` só reescreve o que não corresponde a um arquivo existente; (c) a relação com o `pushTerminalTransInfo.php`, que o sucedeu para os dados que o produto realmente usa; (d) a ressalva de não ter autenticação. Comportamento preservado, com um único ajuste: `mkdir` do diretório de logs passou de `0777` para `0755`, alinhado ao `core/Logger.php`.
- **Credenciais de SMTP cadastráveis pela interface** (`/config-smtp`, "Cadastros › Servidor de E-mail"): a v4.4.0 lia o servidor de e-mail só do `.env`, o que obrigava acesso ao servidor para trocar de provedor. Agora as credenciais (host, porta, segurança, usuário, senha, remetente, timeout) são cadastradas na tela e ficam em `smtp_settings`. Dois escopos: **global da plataforma** (`customer_id NULL`, só administrador) e **por cliente**, que sobrepõe a global — cenário white-label, em que o cliente envia do próprio domínio. Resolução em `mail_config()`: **credenciais do cliente → servidor global → variáveis do `.env`**; o `.env` permanece como fallback para não quebrar instalação já configurada e para permitir subir ambiente sem passar pela interface. A tela mostra um painel "em uso agora" com a origem efetiva das credenciais daquele cliente.
- **Botão "Enviar e-mail de teste"** com registro do resultado (`last_test_at`/`last_test_ok`/`last_test_error`): o envio usa exatamente o que está gravado, e o erro real do provedor aparece na tela — diagnosticar SMTP às cegas era o principal risco operacional do canal de e-mail.
- **Cifra de segredos em repouso** (`includes/crypto.php`): a senha do SMTP é gravada com **AES-256-GCM** (autenticado — adulterar o ciphertext falha na verificação da tag em vez de devolver lixo), chave derivada por SHA-256 de `APP_KEY` com `WEBHOOK_TOKEN` como fallback, mesma cadeia do `includes/csrf.php`. Formato versionado (`v1:`) para permitir troca de algoritmo. A senha **nunca volta para o navegador**: o campo aparece vazio e, se ficar vazio ao salvar, a senha atual é preservada.
- **Migração v4.4.1**: tabela `smtp_settings` com `customer_key` gerada (mesma solução da v4.4.0 para impedir duas configurações globais, já que MySQL trata `NULL`s como distintos em índice único) e FKs para `customers` (CASCADE) e `users` (SET NULL).

### Changed
- **`send_mail()` e `mail_config()` ganharam escopo por cliente** (`?int $customerId`): o `scripts/worker.php` passa o `customer_id` do job, então uma notificação de um cliente com SMTP próprio sai pelo servidor dele. Chamadas sem o parâmetro continuam válidas e resolvem a configuração global.
- **`.env.example`**: bloco SMTP marcado como fallback com a ordem de precedência explícita, e nova `APP_KEY` documentada (`openssl rand -hex 32`) — a tela avisa quando o sistema está caindo no `WEBHOOK_TOKEN`, porque rotacionar esse token tornaria as senhas gravadas indecifráveis.

### Fixed
- **Cache de credenciais não era invalidado após salvar**: `smtp_settings_row()` guarda a configuração por request para não repetir a consulta, mas a tela `/config-smtp` grava e renderiza o painel "em uso agora" na **mesma** request — sem invalidar, o administrador salvava um servidor novo e continuava vendo o antigo. Novo `smtp_settings_cache_clear()`, chamado após salvar e após remover. Encontrado pelo teste de precedência, não em produção.

## [Unreleased] — 4.4.0

### Added
- **Motor de notificações — sino, pop-up, som e e-mail** (Fase 1 de `docs/PLANO_IMPLEMENTACAO_v4.4-v4.7.md`): até aqui um alarme crítico só existia para quem estivesse com o `/ocorrencias/dashboard` aberto acompanhando o polling de 15s — não havia **nenhuma** saída de notificação no sistema (nem `mail()`, nem SMTP). Agora, quando o motor de ocorrências abre uma ocorrência **nova**, uma regra por cliente × tipo de alarme decide o que acontece. Peças: `includes/notification_engine.php` (resolução da regra, gravação e enfileiramento), `includes/mailer.php` (cliente SMTP próprio — o app não tem gerenciador de pacotes), `handlers/notificacoesdata.php` (AJAX), `handlers/config_notificacoes.php` (tela de regras em `/config-notificacoes`) e o sino no `web/layout_base.php` (badge, painel, toast e som, polling de 30s que pausa com a aba oculta).
- **Regras de notificação** (`notification_rules`, tela `/config-notificacoes`): por **cliente × tipo de alarme**, com canais independentes (sino / pop-up em tempo real / som / e-mail), **risco mínimo** opcional (`min_risk` — ex.: só notificar DMS de risco alto) e até 3 destinatários de e-mail. O tipo aceita **código, nome PT/EN ou categoria** — o mesmo *matching triplo* do `occurrence_engine`, então uma regra escrita como `DMS` cobre os 26 alarmes da categoria sem cadastro item a item. Regra do cliente tem precedência sobre a **regra global** (`customer_id NULL`), que serve de fallback e só o administrador edita. Seed com 6 regras globais (Emergency, Accident, DMS, ADAS, Security, Driving) — **e-mail nasce desligado de propósito**: ninguém deve receber e-mail sem ter pedido.
- **Migração v4.4.0**: tabelas `notification_rules` e `notifications`, novo valor `notification` no enum `jobs.type` e coluna `jobs.attempts` (retry). Idempotente — validada com duas execuções seguidas na base local (exit 0 nas duas, 6 regras de seed e não 12).

### Changed
- **`scripts/worker.php` ganhou o tipo `notification`** e uma política de retry: falha de envio **não** mata o job de imediato — `attempts` é incrementado e o job volta para `pendente` até 3 tentativas, porque SMTP fora do ar por dois minutos é condição transitória e perder o aviso seria pior. O retry vale **só** para `notification`; `report` e `video_download` continuam falhando de primeira, já que reexecutá-los repetiria trabalho pesado e o usuário pode simplesmente pedir de novo em `/exportar`.
- **`auth_cleanup()` passou a purgar notificações** (`includes/auth.php`): lidas com mais de 30 dias e não lidas com mais de 90 — `notifications` cresce a uma linha por ocorrência nova. A purga tem `try/catch` próprio para que uma base sem a migração v4.4.0 não quebre o login.
- **`.env.example`**: bloco SMTP (`SMTP_HOST`/`PORT`/`SECURE`/`USER`/`PASS`/`FROM`/`FROM_NAME`/`TIMEOUT`), kill-switch `NOTIFY_ENABLED` e `APP_URL` (usada para montar o link "Abrir no sistema" no corpo do e-mail — sem ela o link sairia relativo e não funcionaria fora do navegador). Sino, pop-up e som funcionam **sem** SMTP; só o canal de e-mail depende dele, e a tela avisa quando `SMTP_HOST` está vazio.

### Notas de implementação
- **Nada de SMTP dentro da transação do webhook**: `notify_from_occurrence()` apenas grava (`notifications` + fila `jobs`); quem abre socket é o worker. É a mesma separação já adotada em `flush_pending_video_requests()` — uma conversa SMTP dentro do `pushalarm` seguraria locks de `alarms`/`occurrences` por segundos. Pelo mesmo motivo, toda falha do motor é engolida e logada: um `throw` ali dentro faria **rollback do INSERT do alarme**.
- **Notificar por ocorrência, não por alarme**: o gancho fica só no ramo de ocorrência nova do `process_alarm_to_occurrence()`. A janela de agrupamento do perfil (padrão 10 min) já absorve a rajada de alarmes repetidos, então notificar ali elimina a maior fonte de spam sem nenhum código extra. Somam-se a isso um teto de 60 notificações/hora por cliente (que gera **uma** notificação-resumo e passa a suprimir) e dedupe de e-mail por `(imei, alarme)` em 15 min.
- **`uk_nr_customer_type` usa coluna gerada `customer_key = COALESCE(customer_id, 0)`**: MySQL trata `NULL`s como **distintos** num índice único, então sem ela duas regras globais para o mesmo alarme seriam aceitas e o seed duplicaria a cada execução da migração. A coluna é `VIRTUAL` e não `STORED` por imposição do próprio MySQL — uma FK sobre a coluna-base de uma coluna gerada `STORED` não pode usar `CASCADE` como ação referencial (erro 1215, reproduzido e confirmado em teste isolado antes da escolha).
- **Som sintetizado via WebAudio** em vez de arquivo de áudio: evita versionar um binário no repositório e não depende de CDN, coerente com o "sem build step" do projeto.

## [Unreleased] — 4.3.0

### Added
- **Central de Ajuda atualizada para a v4.3.0** (`/wiki`): nova seção **"O que vale para todos os relatórios"** (ordem crescente por data, setinha de ordenação ▲/▼/⇅, botão ← Voltar, paginação com janela deslizante, export herdando filtros e ordenação) mais os avisos de **teto de 31 dias** e de **horário de Brasília**. **Posições** ganhou a explicação das duas modalidades de faixa horária (contínua × em cada dia do período, incluindo o caso do turno que cruza a meia-noite) e o mockup atualizado. **Deslocamento** passou a documentar as 2 modalidades (por deslocamento / fechamento diário), o "Ver rota" no mapa e a regra real de separação de trajetos (ignição, parada de 5 min ou silêncio do equipamento). **Desatualizados** teve as faixas corrigidas — a wiki dizia "1h, 6h, 12h, 24h, >24h" e as reais são "menos de 24h, mais de 1 dia, mais de 7 dias, mais de 30 dias e nunca posicionados". **Alarmes** e **Ocorrências** ganharam as colunas ordenáveis e os filtros que de fato existem na tela.
- **Faixa horária opcional no Relatório de Posições, em 2 modalidades** (`/relatorios/posicoes`): novos campos `time_from`/`time_to` ao lado do período (vazios = 00:00 / 23:59) mais um seletor `time_mode` com as duas leituras possíveis do filtro — **Contínua (início → fim)**: uma janela só, de `date_from time_from` até `date_to time_to` (ex.: 01/07 08:00 → 05/07 10:00, madrugadas incluídas); **Em cada dia do período**: dias inteiros no intervalo com a faixa horária aplicada a cada um (ex.: só as manhãs de 08:00–10:00 de 01/07 a 05/07). Faixa invertida no modo diário (`time_from` > `time_to`) é lida como janela que cruza a meia-noite — 22:00–06:00 = turno da noite. Novo helper `report_time_window()` em `includes/functions.php` (o modo contínuo usa `brt_datetime_range_to_utc()`; o diário mantém o `BETWEEN` indexado dos dias e acrescenta `TIME(CONVERT_TZ(col,'+00:00','-03:00'))`). Vale também para os exports, cujo subtítulo cita a faixa e a modalidade.
- **Botão "Voltar" em todos os relatórios**: depois que o resultado é exibido, cada relatório mostra `← Voltar` ao lado dos botões de export, devolvendo o usuário à tela inicial (filtros limpos) do próprio relatório — antes era preciso reabrir a tela pelo menu lateral. Aplicado em `/relatorios/alarmes`, `/relatorios/ocorrencias`, `/relatorios/posicoes`, `/relatorios/deslocamento`, `/relatorios/desatualizados` (o "Voltar" do detalhe de faixa foi padronizado e passou a preservar o filtro de cliente) e no hub `/relatorios`; `/relatorios/deslocamento/rota` (mapa, abre em nova aba) ganhou "← Voltar ao relatório". Aparece só quando há resultado na tela (`report_has_query()`).
- **Ordenação clicável por data/hora em todos os relatórios** (`report_sort_params()` + `report_sort_link()` em `includes/functions.php`): o cabeçalho da coluna de data/hora virou link com seta — ▲ crescente / ▼ decrescente na coluna ativa, ⇅ neutro nas demais — e o clique inverte a direção, preservando os filtros da URL e reiniciando a paginação. A coluna ordenável entra no SQL por whitelist (PDO não parametriza identificadores). Colunas cobertas: `alarm_time`/`imei`/`alarm_type`/`alarm_name` (alarmes), `gps_time` (posições), `last_alarm_at`/`imei`/`alarm_count` (ocorrências), `started_at`/`ended_at`/`max_speed`/`distance_km` (deslocamento por viagem), `dia` (fechamento diário), `last_gps_time` (desatualizados) e a coluna Data/Hora do hub `/relatorios`. Estilos `.sort-link`/`.sort-arrow` no `web/layout_base.php`.
- **Relatório de Deslocamento em duas modalidades** (`/relatorios/deslocamento`, select "Modalidade"): **Por deslocamento** (grade anterior, 1 linha por viagem ignição lig→desl) e **Fechamento diário** — agregado por dia BRT sobre `trips` com primeira ignição ligada, última desligada (viagem que cruza a meia-noite conta inteira no dia em que começou), **Jornada** (última−primeira, inclui paradas) e **Em Movimento** (soma das durações das viagens) lado a lado, Σ distância, vel. máxima, Σ alarmes e nº de viagens do dia. Paginação por grupos e export XLSX/PDF próprios da modalidade. Filtro de **faixa horária opcional** (`time_from`/`time_to`, novo helper `brt_datetime_range_to_utc()`).
- **Mapa de rota por deslocamento** (`/relatorios/deslocamento/rota`, novo `rel_deslocamento_rota.php`; cada linha do relatório ganhou o link "Ver rota"): aceita `trip_id` (viagem) ou `imei`+`dia` (dia fechado; janela primeira→última ignição recalculada server-side, escopo multi-tenant). Leaflet com a polyline do percurso, balão de **Partida** (verde) e **Chegada** (vermelho) com data/hora BRT, um ponto por posição/comunicação da câmera (popup com hora, velocidade e ignição) e **ocorrências em cor de destaque** (laranja): com coordenada própria (posição do 1º alarme agrupado) o pino vai no local exato; sem coordenada, o ponto de comunicação mais próximo no tempo é destacado — o balão cita a ocorrência (tipo, hora, risco, status). Amostragem automática acima de 3000 pontos (preserva primeiro/último) e KPIs do percurso (distância, duração, vel. máx, viagens, alarmes, posições, ocorrências). Router ganhou subrotas de 3 segmentos (chave `'segundo/terceiro'` no `$subrouteMap`).
- **Teto global de período nos relatórios: 31 dias** (`clamp_report_range()` + `REPORT_RANGE_MAX_DAYS` em `includes/functions.php`): datas invertidas são corrigidas e períodos maiores têm o fim encurtado, com banner "período ajustado" e label "máx. 31 dias" em `rel_deslocamento`, `rel_posicoes`, `rel_alarmes`, `rel_ocorrencias` e `bi`; aplicado silenciosamente em `relatorios.php`, `ocorrenciasdata.php` (AJAX) e na criação de jobs do `exportar.php`.

### Changed
- **Relatórios com data abrem em ordem CRESCENTE** (mais antigo no topo, mais recente no fim da página) — antes todos abriam decrescente. Vale para alarmes (`alarm_time`), posições (`gps_time`), ocorrências (`last_alarm_at`), deslocamento por viagem (`started_at`) e fechamento diário (`dia`); desatualizados já abria do mais desatualizado para o mais recente e agora alterna pela seta (os "Nunca posicionados", `NULL`, acompanham o extremo mais antigo — primeiro em ASC, último em DESC). No hub `/relatorios` a inversão é feita em PHP (`array_reverse`) porque a query tem `LIMIT 200`: ordenar em ASC no SQL traria os 200 registros **mais antigos** do período em vez dos 200 mais recentes. Os exports XLSX/PDF/CSV seguem a ordenação escolhida na grade.
- **Migração v4.3.0**: novo índice composto `idx_trips_customer_time (customer_id, started_at)` em `trips` (o antigo `idx_trips_customer` é removido — redundante, o composto serve a FK). Motivação medida em benchmark com 2,92M viagens (tenant de 200 veículos): grade do relatório caía de 3,5–6s para <1ms (por viagem) e 41–177ms (fechamento diário de 7–30 dias); o teto de 31 dias mantém a modalidade diária nessa faixa. `deploy.sh` aplica a migração automaticamente.

### Fixed
- **Paginação nunca passava da página 10** (relatado no Relatório de Posições, mas o defeito era o mesmo em 6 telas): o laço era fixo em `1..min($totalPages, 10)` (ou 8), então com 14 páginas o usuário na página 12 via só os botões 1–10 — sem a página atual, sem as vizinhas e sem caminho de volta a não ser o `»`. Novo helper `report_pagination()` (`includes/functions.php`) com **janela deslizante** ao redor da página atual (primeira e última sempre visíveis, reticências nos saltos) substitui o widget copiado em `rel_posicoes`, `rel_alarmes`, `rel_deslocamento`, `rel_ocorrencias`, `equipamentos`, `exportar` e `video_downloads`. De quebra: os links de página deixam de carregar `export=` (clicar numa página durante um export re-disparava o download), `exportar`/`video_downloads` passam a preservar os filtros da URL na paginação (antes o link era `?page=N` cru, perdendo o filtro) e `equipamentos`/`exportar` ganham a contagem de registros no rótulo.
- **Deploy "não acessava o banco com as credenciais do .env" — diagnóstico falso** (`scripts/deploy.sh` + limpeza no homolog): os dois avisos "verifique credenciais no .env" **nunca foram problema de credencial** (as migrations conectam com o `.env` e funcionam — banco no homolog está em `4.3.0`). (1) O check da **FASE 1** rodava `mysql -e "SELECT 1"` **sem credenciais** → conectava como o usuário do SO (root/administrador sob sudo, sem conta MySQL) e falhava sempre; agora testa com as credenciais do `.env` (`MYSQL_PWD` + `-h/-u/-D`). (2) O backup da **FASE 2** (`mysqldump … 2>/dev/null`) abortava (erro 1356) por causa de **duas VIEWs órfãs** no banco — `vw_alarm_types_ambiguous_codes` e `vw_alarm_types_unknown_codes`, que referenciam a tabela inexistente `alarm_types_reference` (já removidas do schema canônico em 06/07/2026, mas ainda presentes no banco do homolog); o dump saía incompleto e a mensagem culpava as credenciais. VIEWs dropadas no homolog (não usadas pela app) → `mysqldump` completa (`exit 0`, "Dump completed"); `deploy.sh` passou a capturar e exibir o stderr real do dump em vez de silenciá-lo/atribuir a credenciais. **Bancos criados antes de 06/07/2026 (ex.: produção) podem ter as mesmas VIEWs órfãs** — dropar com `DROP VIEW IF EXISTS vw_alarm_types_ambiguous_codes, vw_alarm_types_unknown_codes;`.
- **Deslocamento colapsava a jornada inteira numa única viagem de 24h** (`scripts/trip_builder.php`): a segmentação encerrava uma viagem **só** ao ver `acc=desligado`. Muitos devices mantêm a ignição/voltagem reportada ligada o dia todo (não enviam `acc=0` entre um deslocamento e outro), então uma jornada inteira — incluindo paradas e pernoite — virava **uma linha só cobrindo o dia todo** (validado no homolog com a placa FJR7B59 / IMEI 869058070151343: viagem única de **07-22 11:58 → 07-23 10:55**, ~23h). Agora a viagem também é encerrada por **parada sustentada** (velocidade abaixo de `STOP_SPEED_KMH=3 km/h` por mais de `STOP_IDLE_SECONDS=300s`) e por **buraco de dados** (device offline/silente por mais que esse mesmo limite) — sempre no **último ponto em movimento** (a cauda parada é descartada), e o próximo movimento abre uma viagem nova; a segmentação deixa de depender do sinal de ignição. Filtro `isRealTrip()` ganhou piso de duração (`MIN_TRIP_DURATION_S=60s`) para descartar *slivers* de poucos segundos. Rebuild no homolog (lookback 30d): FJR7B59 saiu de 11 → **39 viagens** (5–9/dia, a viagem de ~23h fatiada em 17 deslocamentos reais; o trajeto-tronco de 07-19, 3h/292 km, preservado) e o device-bancada `181_7838` (só deriva de GPS, nunca >7 km/h) deixou de gerar "viagem" de 24h fantasma.

## [Unreleased] — 4.2.1

### Fixed
- **Vídeo do alarme nunca subia para o storage (gatilho automático inócuo)**: a validação com a câmera JC371 real provou que `proNo 34818` (0x8802) é uma **consulta** à multimídia 808 (respondeu `mediaItemsNum:0` para eventos DMS reais) e não um comando de upload. O gatilho automático do motor de ocorrências agora envia **37384 (0x9208 — Alarm Attachment Upload)** com o `alarmLabel` recebido no push do alarme (`pushalarm.php` passa a repassá-lo ao motor; alarmes JTT sem anexo não disparam solicitação), `alarmNumber` derivado conforme a doc (§2.20/§1.13 — validado contra o exemplo oficial) e o endereço do attachment server do IoTHub (`ATTACH_UPLOAD_IP`/`ATTACH_UPLOAD_PORT` no `.env`; defaults: IP de ingest do vídeo + porta 21188). Anti-rajada re-desenhada: dedupe por anexo (label, 10 min) + teto de 5/2min por device — o teto antigo de 1/2min descartaria os vídeos das demais ocorrências de uma rajada.
- **Mídia não vinculava à ocorrência dona do alarme**: `pushfileupload.php` agora extrai `alarmLabel` e canal do nome do arquivo (`{imei}_{alarmLabel}_{xy}.ext`, doc §1.8) e vincula pela cadeia `alarms.alarm_label → occurrence_events → occurrences` (novo `link_upload_by_alarm_label()` no motor; vídeo tem precedência sobre imagem; janela ±3 min mantida como fallback para uploads sem label).
- **Vídeo vinculado não aparecia no detalhe da ocorrência**: `ocorrencias_dashboard.php` renderizava `file_url` cru (só o nome do arquivo) no `<video>`/`<img>`/links "Ver" — agora prefixa `FILE_STORAGE_URL` quando a URL é relativa, como o playback já fazia. Bônus: detalhe da ocorrência escopado por `customer_id` (multi-tenant). Preset `ftp_upload` (37382) corrigido para os campos reais da doc §2.7 (o antigo usava os campos do 34818); novo preset `alarm_attach` (37384) em `comandos.php`/`ativo_detalhe.php`; whitelist do `sendcommand.php` inclui 37384.
- **Consulta de vídeos históricos do cartão sempre vazia** (`/video/playback`): a tela disparava `proNo 34818` (0x8802 — extração de multimídia de evento) em vez de **37381** (0x9205 — lista de gravações do cartão, o mesmo que o app usa) e lia apenas `media_files` (arquivos já extraídos), ignorando `resource_lists`, onde a resposta da câmera de fato aterrissa via `/pushresourcelist`. Fluxo corrigido: Requisitar → 37381 fatiado por dia UTC (a janela GMT-0 compacta `yyMMddHHmmss` não pode cruzar o dia; cap 15 segmentos), timeline unifica `resource_lists` ("No cartão") e `media_files` ("Disponível" → play inline) com merge por janela ±120s, botão **Extrair** por gravação dispara 34818 com a janela exata (o arquivo chega via `/pushfileupload` e o item vira reproduzível), auto-refresh 6×8s enquanto a câmera responde (sem reenviar o comando) e `serverFlagId` derivado do protocolo do device. Bônus: `imei` do GET agora é validado contra o cliente da sessão (multi-tenant). Em `pushresourcelist.php`: aceita o push §1.11 sem envelope `data_list` (`allowSingleObjectPayload` — antes descartado como "empty data"), `resourceType` mapeado pela semântica 0x1205 (0 = áudio+vídeo → `video`, não `image`) e datas parseadas explicitamente como UTC. Presets 37381 de `comandos.php`/`ativo_detalhe.php` corrigidos para o formato da documentação (`channel`, janela GMT-0 de hoje).
- **Relatório de Deslocamento sempre vazio (viagens nunca eram construídas)**: `scripts/trip_builder.php`, `scripts/worker.php` (export de posições) e `scripts/metrics_rollup.php` (distribuição de velocidade) consultavam a coluna inexistente `gps_data.ignition` — a ignição fica em `gps_data.acc` (o `pushgps` grava `acc`/`accStatus`; `rel_posicoes.php` já lia corretamente `g.acc AS ignition`). Como o PDO roda em `ERRMODE_EXCEPTION`, o `trip_builder` (cron) morria com `Unknown column 'ignition'` **antes de gravar qualquer viagem** → tabela `trips` permanentemente vazia → a grade do `/relatorios/deslocamento` nunca mostrava deslocamentos. Corrigido nos três scripts para `g.acc` (aliasado como `ignition` onde a lógica lê esse nome). Verificado ponta-a-ponta com dados reais da câmera 182 (IMEI 864993060182939): 3 viagens construídas com duração/vel.máx/KM corretos e a contagem de alarmes cruzada por janela (Viagem B = 2 alarmes).
- **Viagem em curso era fragmentada em várias linhas** (`trip_builder.php`): a variável `$batchTime` (`-2h`) era calculada mas nunca usada — o fallback que fecha uma viagem ainda aberta ao fim do lote a persistia **mesmo com o veículo em movimento**, e como cada rodada do cron avança a janela pela `MAX(ended_at)`, uma única viagem longa virava N viagens fragmentadas a cada execução. Agora (`$staleBefore`) só finaliza a viagem aberta se o último ponto já é mais velho que 2h (viagem encerrada / device silenciou); do contrário deixa em aberto para o próximo cron. Verificado: viagem com pontos recentes (acc=1, sem acc=0 de fim) é corretamente **adiada** e não gera linha até encerrar.
- **Viagens-ruído poluíam o relatório** (`trip_builder.php`): novo filtro `isRealTrip()` — uma viagem só é persistida se teve movimento efetivo (`max_speed >= 6 km/h` **ou** `distância >= 1 km`) e ≥2 pontos. Descarta viagens de 1 ponto, paradas com ignição ligada (ex.: veículo estacionado a noite toda com ACC on → "viagem" de 11h/0,33 km) e deriva de GPS. Validado com dados reais da câmera 182 (FJR7B59, IMEI 869058070151343): das 14 viagens brutas, 10 reais são mantidas (trajetos de 0,39–292 km) e 4 ruídos descartados, sem derrubar trajetos curtos legítimos.
- **Vídeo ao vivo não deixava selecionar CH2+/CH3+** em equipamentos cadastrados com mais câmeras: as telas de vídeo liam o `camera_count` do **modelo** (seed antigo com valores errados) e ignoravam o do **equipamento**. Semântica corrigida — `device_models.camera_count` = máximo do modelo; `devices.camera_count` = quantidade instalada (cadastro) — e as telas (`video_aovivo`, `video_playback`, `comandos`, grade de `equipamentos`) passam a usar `COALESCE(NULLIF(d.camera_count,0), dm.camera_count, 1)`. Catálogo corrigido (migration v4.2.1 + seed v3.1.0): JC182=1, JC181/JC400D/JC400AD=2, JC371=até 3, JC450=até 5; equipamentos de modelos de contagem fixa são alinhados automaticamente. Ao vivo: seletor de canais agora renderiza 1..N (sem teto de 4 — JC450 chega a CH5), lê o device selecionado já no primeiro load (antes só CH1 ficava habilitado até trocar o select) e reseta o canal ao trocar para device com menos câmeras; playback: dropdown de canais reconstruído conforme o equipamento; formulários de cadastro limitam a quantidade ao máximo do modelo.

### Added
- **Observabilidade de logs**: `LOG_LEVEL` configurável via `.env` (aplicado lazy no `core/Logger.php` — o `.env` só existe após o primeiro `Database::getInstance()`; `DEBUG` liga o `RAW_WEBHOOK_DATA`, o payload bruto de cada webhook, antes inacessível por nível hardcoded); novo `scripts/log_cleanup.php` no cron diário (03:10, via `crontab-setup.sh`) com rotação por tamanho (`LOG_MAX_SIZE_MB`, default 10 MB — logs de append contínuo viram `.old`) e purga por idade (`LOG_RETENTION_DAYS`, default 30) agora cobrindo **todos** os `*.log`/`*.log.old` (antes `cleanOldLogs()` existia mas nunca era chamado, e só olhava `webhook_*.log`); handler global de exceções não tratadas (→ ERROR + resposta 500 neutra) e erros fatais (→ CRITICAL) para páginas/AJAX do dashboard em `includes/auth.php` (webhooks seguem no try/catch próprio do `WebhookHandler`).
- **Gatilho automático de vídeo de evento (DMS/ADAS, câmeras JT/T)**: ao criar uma ocorrência nova sem mídia vinculada, o motor de ocorrências agenda automaticamente o upload do anexo do alarme (ver o Fixed "Vídeo do alarme nunca subia" acima — forma final: `proNo 37384` com `alarmLabel`/`alarmNumber`). O despacho HTTP roda **pós-commit** (fim do `pushalarm.php`, fora da transação do webhook) via novo helper reutilizável `includes/iothub_command.php` (`iothub_dispatch_command()`, mesmo contrato/semântica de status do `sendcommand.php`, registra em `commands` com `operator='auto_video'`); kill-switch `AUTO_VIDEO_REQUEST=0`. O vídeo chega assíncrono via `pushfileupload` e é vinculado à ocorrência (por `alarmLabel`; ±3 min como fallback) — o frontend (detalhe da ocorrência / playback) o serve por `FILE_STORAGE_URL + file_url` com canal/`event_time` reais do webhook de chegada.

## [Unreleased] — 4.2.0 (Aderência YUV — Fases A–D completas)

Execução do `PLANO_ADERENCIA_YUV.md` (revisão de aderência contra a plataforma YUV capturada em 06/07/2026 + inventário do código real). Progresso e ponto de retomada em `PLANO_ADERENCIA_YUV.md` §0.

### Added
- **Export síncrono Excel/PDF/CSV nos relatórios (B1 — padrão YUV §9.2)**: novo `stream_export()` em `includes/export_helper.php` (reusa os writers XLSX/PDF puros da fila; CSV com BOM + `;`; limite `SYNC_EXPORT_MAX_ROWS=10000`). Botões **Exportar Excel/PDF** reais (a mesma query da grade, sem paginação, respeitando os filtros ativos) em `rel_alarmes`, `rel_ocorrencias`, `rel_posicoes`, `rel_deslocamento`, `rel_desatualizados` (por faixa) e `equipamentos` — todos os `alert('em desenvolvimento')` removidos.
- **RBAC efetivo (B2 — completo)**: `get_user_permissions()`, `can()` e `require_permission()` em `includes/auth.php` (matriz JSON de `permission_groups`, com suporte ao wildcard `"*"` do seed Administrador; usuário sem grupo → sem restrição, compat com role legado). Sidebar (`layout_base.php`) esconde itens sem permissão `view`; **router** aplica `require_permission(tela,'view')` centralizadamente via mapa handler→tela (webhooks/AJAX/login fora do mapa); **gates de ação fina** (`create`/`edit`/`delete`) nos POSTs de todos os cadastros (ativos, ativos_novo, chips, clientes, equipamentos incl. import, motoristas, usuarios, grupos_permissao, config_ocorrencias incl. delete via GET) e gate de `export` nos 6 blocos de export síncrono.
- **Endereço geocodificado no Relatório de Posições (B3)**: coluna Endereço substitui Lat/Long (fallback: link OSM com as coordenadas); novo `geocode_cache_lookup()` (lote, cache-only) + resolução inline de até 3 misses por página (respeita rate limit Nominatim; o cache enche progressivamente).
- **Filtros padrão YUV (B4)**: novo componente `web/components/chips_multiselect.php` (chips com overflow `+N`, extraído do bi.php). `rel_alarmes`: multiselect de Tipos de Alarme (IN) + filtro Filial; `rel_ocorrencias`: filtros Filial e Motorista. Exports respeitam os novos filtros.
- **Grade de Equipamentos completa (B5)**: colunas **Chip** (JOIN `sim_cards`), **Bateria** (`device_statistics.battery_level`) e **Periféricos** (badge com contagem + tooltip), na grade e no export, com fallback resiliente para schema antigo.
- **Import em lote completo (B6)**: CSV importa Modelo (resolvido por nome) e Canais (fallback: `camera_count` do modelo); validação de IMEI 15–17 dígitos; avisos por linha (IMEI inválido/duplicado, modelo desconhecido).
- **Resumo enriquecido (D1 — paridade YUV `page_resumo`)**: card **Ociosidade** (ignição ligada + parado, últimos 30 min); **Status de Equipamentos por Modelo** (barras on/off + % online); **mapa de calor real** (`leaflet.heat` via CDN sobre as posições de 2h, mantendo os pontos clicáveis); séries temporais com **toggle Hoje / Últimos 7 dias / Último mês** (buckets hora/dia em BRT + total do período); **Top 3 placas com mais alarmes** e **Top 3 motoristas** (com CTA de upsell FaceID quando o recurso está desabilitado, como no YUV); **Visão por Clientes em 3 eixos Top 3** (equipamentos ativos / ocorrências / desatualizados); **auto-refresh 30s dos KPIs** via `/?ajax=kpis` (JSON leve, sem reload); botão **"Ver tutorial"** que reexibe o tour.
- **Rastreamento sem reload (D2)**: modo `?ajax=1` no handler devolve as posições em JSON; o mapa atualiza os pins in-place a cada 30s (posição, cor online/offline, popup) em vez de `location.reload()` a cada 60s.
- **Exportar com polling real (D3)**: poll de 10s via `/exportardata` que só recarrega quando algum status de job muda; coluna **Nome** do relatório na grade (de `params.report_name`, também exposto no JSON).
- **Indicador de impersonação (D4)**: banner âmbar sob o header quando o revendedor está operando como um cliente + botão "Voltar ao meu perfil" (novo modo `exit_impersonation` no `/customer_switch` fecha o `impersonation_log.ended_at` e restaura o contexto); `/customer_switch` também passou a exigir CSRF.
- **Padrão de grade CRUD nos cadastros (Fase C — YUV §9.1)**: busca server-side + Exportar Excel/PDF + paginação (25/pág) em `chips`, `motoristas` e `ativos`; busca + export em `clientes` (novas colunas **E-mail** e **Config. Checklist**) e `usuarios` (por aba Minha Empresa/Meus Clientes); busca client-side (`yuvTableFilter` global) em `grupos-permissao` e `config-ocorrencias`; `motoristas` com colunas **Foto** e **Nascimento**. Todos os exports com gate RBAC `export`.

### Fixed
- **Rota morta `/clientes/{id}`** (A1): despachava para `cliente_detalhe.php`, que não existe → agora 404 explícito (R08 residual).
- **`/checklist/inspecao` inacessível** (A2): `checklist` estava em `$simpleRoutes` E no `$subrouteMap`; o primeiro vencia e a tela de inspeção nunca abria. `checklist` saiu de `$simpleRoutes` (o fallback do subrouteMap continua servindo `/checklist`).
- **CSRF remanescente — R11 fechado de vez** (A3): `csrf_verify()`+`csrf_field()` em `ativos.php` (editar/remover) e `perfil.php` (troca de senha); **`/sendcommand` agora exige token** — `layout_base.php` expõe `window.CSRF_TOKEN` (+ meta tag) e os 6 callers (`comandos`, `ativo_detalhe`, `config`, `equipamentos` FOTA, `video_aovivo`, `video_playback`) enviam `X-CSRF-Token`.
- **Toggle de usuário quebrado desde a Fase F**: o form ativar/desativar de `usuarios.php` não tinha `csrf_field()` com `csrf_verify()` ativo no POST → 403 sempre. Corrigido.
- **`usuarios.php` mostrava o ID do grupo** em vez do nome (A4): resolvido com mapa id→nome.
- **Relatório de Posições sempre vazio**: `$where` usava `imei`/`id` sem prefixo e a query da grade faz JOIN com `devices` → erro de **coluna ambígua** engolido pelo try-catch da Fase K → zero resultados. Prefixado `g.` (grade, count, amostragem `MOD(g.id,10)`).
- **Ignição/GPS sempre "Desligada"/vazio em Posições**: a grade lia `$r['ignition']`/`$r['gps_status']` que não vinham no SELECT. Aliases `g.acc AS ignition, g.status AS gps_status` + aceite do status `VALID`.
- **`get_jimi_user()` sem colunas v4**: não selecionava `user_type`/`permission_group_id`/`photo_url`, então os checks `user_type==='revendedor'` (visão por clientes do Resumo, abas de Usuários) nunca ativavam. Incluídas no SELECT com fallback para schema antigo.
- **Cache de geocodificação nunca dava hit**: `reverse_geocode()` comparava o float recebido (8 casas) com a coluna `DECIMAL(9,6)` → toda consulta repetida chamava a API Nominatim de novo. Coordenadas agora arredondadas a 6 casas antes do SELECT/INSERT.
- **"Entrar como" e "Desativar" em Clientes quebrados (403) desde a Fase F**: os dois forms inline não tinham `csrf_field()` com `csrf_verify()` ativo no POST — mesma família do bug do toggle de Usuários. Corrigido.
- **Rastreamento sem nenhum pin no mapa**: a query de posições usava `g.ignition` — coluna que **não existe** em `gps_data` (é `acc`) → a exceção era engolida pelo try-catch e `$positions` ficava vazio silenciosamente. Corrigido com `g.acc AS ignition`.
- **"Velocidade da Frota" do Resumo nunca populava on-the-fly**: mesmo bug de coluna (`g.ignition` → `g.acc`) no fallback quando o cache `metrics_snapshots` está vazio.

### Verified
- `php -l`: verde em todos os arquivos alterados.
- Smoke test HTTP (server dev + MySQL portátil): `/ping` 200; telas protegidas 302→login (RBAC central não quebrou fluxo sem grupo); `/clientes/9` 404; `/checklist` e `/checklist/inspecao` resolvem.
- **Suite Playwright completa com login: 36 passed / 0 failed / 4 skipped** (2 rodadas — pós-Fase A+B1+B2/view e pós-B2-completo+B3; credenciais E2E provisionadas no MySQL local de dev). Skips: rate-limit destrutivo (opt-in) e multi-tenant (exige 2º cliente).

## [4.1.2] — 2026-07-11 (Vídeo ao vivo — payload de streaming e player resiliente)

Correção da abertura dos vídeos ao vivo. Causa-raiz: o comando que instrui o device a **publicar** o stream mandava um endereço inalcançável, então o media server nunca recebia RTP e o player travava em "Conectando".

### Fixed
- **Vídeo ao vivo nunca abria (payload 37121 quebrado)**: `video_aovivo.php` enviava `videoIP: window.location.hostname` (o host visto pelo **navegador**) e `videoTCPPort: "0"`. O comando 0x9101 instrui o **device** a publicar o RTP no media server do IoTHub — o endereço tem que ser o que o **device** alcança (IP público do servidor) e a porta de ingest do `iothub-media` (**10002**), não `0`. Com porta 0 / host do navegador, o device não publicava nada e o `.flv` em `:8881` ficava eternamente sem dados. Também havia `dataType:"1"` (string, "áudio") onde o correto é `0` (vídeo). Corrigido para `dataType:0, codeStreamType:0, videoIP:<IP do servidor>, videoTCPPort:"10002", videoUDPPort:0`.
- **Helper central `video_stream_config()`** (`includes/functions.php`): deriva `flv_base` (saída HTTP-FLV para o navegador) e `ingest_ip`/`ingest_port`/`playback_port` (endereço que o device alcança). O IP sai do host de `STREAM_URL` por padrão, com overrides `VIDEO_INGEST_IP`/`VIDEO_INGEST_PORT`/`VIDEO_PLAYBACK_PORT` no `.env`. Usado por `video_aovivo.php`, `comandos.php` e `ativo_detalhe.php` — presets de streaming/playback deixam de ser hard-coded e ficam consistentes.
- **Player FLV frágil (1 tentativa única)**: o device leva de 5 a 30s entre aceitar o comando e efetivamente publicar o stream; o código antigo tentava conectar uma vez e, se o `.flv` ainda não tinha dados, ou travava em "Conectando" para sempre ou morria no primeiro erro do flv.js. Novo player com **retry** (8 tentativas × 3s), **watchdog** de 8s por tentativa (conexão pendurada sem dados também dispara nova tentativa), tratamento do `flvjs.Events.ERROR` (404 enquanto o device não publica), **autoplay com fallback mudo** (contorna o bloqueio de autoplay dos navegadores), destruição limpa do player entre tentativas e mensagem de falha acionável ao esgotar. Verificado com câmera real: a 1ª tentativa (janela curta) pegava 0 bytes; a retry pega o stream quando o device publica.
- **Aviso de fila offline no vídeo**: `sendcommand.php` passou a expor `status` e `offline_queued` (device desconectado → `data._code=600`) na resposta JSON. O vídeo ao vivo detecta isso e avisa que a transmissão não vai iniciar agora (em vez de esperar um stream que nunca vem).
- **"Requisitar Gravações" enviava o comando errado** (`video_playback.php`): mandava proNo **34817** (comando de **foto**) com um payload de mídia gravada. Corrigido para **34818** (0x8802, upload de mídia armazenada) com `mediaType:2` (vídeo), `beginTime`/`endTime` no formato JT/T `yyMMddHHmmss` em GMT 0. O filtro de período do banco passou a usar `brt_day_range_to_utc()` (dia digitado é BRT; coluna é UTC) e o fetch ganhou `keepalive` (o form navega em seguida e cancelaria a requisição).
- **Preset "Streaming" da tela de Comandos gerava JSON inválido para 37121** (`comandos.php`, `ativo_detalhe.php`): era `{"channelId":1,"mediaType":0,"streamType":0}` — campos que o 0x9101 ignora, sem `videoIP`/porta. Agora usa o payload correto do `video_stream_config()`. Preset "Playback" (37377/0x9201) também corrigido: incluía `serverAddress`/`tcpPort` (porta de playback 10003) que faltavam. Preset "Upload de Vídeo" (texto proNo 128) passou a montar `VIDEOUPLOAD,<host>,<porta>,...` a partir de `FILE_STORAGE_URL`.
- **`/video` legado**: `video.php` (player unificado v3.x com o mesmo payload 37121 quebrado) virou redirect para `/video/aovivo` (ou `/video/downloads` no modo gravações), preservando `?imei=`.

### Verified (servidor de homologação, 2026-07-11)
- Comando **37121 corrigido** → câmera online `869058070151343` (JC182): IoTHub respondeu `code:0, _content:"ok"` em ~1s.
- **Stream ao vivo capturado**: `GET http://189.22.240.43:8881/1/869058070151343.flv` retornou **2 MB de vídeo FLV válido** (assinatura `FLV`, versão 1, flags `0x5` = áudio+vídeo, primeira tag type 18 = metadata) em 28s. A 1ª tentativa com janela curta pegou 0 bytes (device ainda não publicando) — comprova a necessidade do retry/watchdog.
- Lint 7/7 arquivos alterados sem erro. Playwright: navegação **25/25 verde** (inclui `/video/aovivo`, `/video/playback`, `/video/downloads` renderizando sem erro).

## [4.1.1] — 2026-07-09 (Diagnóstico no servidor — comandos IoTHub e respostas offline)

Diagnóstico via SSH no servidor de homologação fechou os itens M.2.1–M.2.3 (IoTHub + comandos + respostas).

### Fixed
- **Comandos marcados "failed" que na verdade foram aceitos**: o `tracker-instruction-server` segura a resposta HTTP por até 30s aguardando o device ("processSendInstruct await timeout"); o `sendcommand.php` abortava aos 15s (`CURLOPT_TIMEOUT`) e reportava "IoTHub inacessível". Timeout elevado para 35s, timeout distinguido de conexão recusada na mensagem, `curl_error` no log estruturado.
- **Respostas de comandos offline perdidas (nunca chegavam)**: evidência no access log — `POST / 302` vindos de `172.16.13.13` (okhttp, rede dos containers): o `offlineCmdPushURL` estava configurado **sem path** (`http://10.1.0.43`), o callback caía na raiz e morria no redirect de login. Além disso, o corpo do callback (§2.4) é um **objeto único sem `data_list`** e o `WebhookHandler` o descartava como "empty data". Correções: `offlineCmdPushURL=http://10.1.0.43/pushinstructresponse` no docker-compose do IoTHub (serviços `api` e `tracker-instruction-server` recriados), suporte opt-in a payload de objeto único no `WebhookHandler` (hash de idempotência calculado sobre a lista final), flag habilitada em `pushinstructresponse.php`, alias camelCase `pushInstructResponse` no router.
- **`/rastreamento` sem nenhum device (e 500 na versão pré-4.1.0)**: `ORDER BY d.is_online` referenciava o alias com prefixo de tabela → unknown column; a exceção era engolida pelo try-catch e a tela renderizava vazia. Corrigido para o alias puro.
- **`.env` do servidor sem `IOTHUB_COMMAND_URL`/`IOTHUB_API_TOKEN`** — adicionadas com `http://10.1.0.43:10088` (IP da LAN, pedido do operador; consistente com o `pushURL` dos containers). `.env.example` atualizado com a orientação.
- **Horários exibidos em UTC (3h adiantados) em todo o dashboard v4**: o armazenamento sempre foi UTC correto (conexão PDO com `time_zone '+00:00'`, devices em GMT 0, PHP em UTC), e os handlers legados convertiam para BRT — mas as 13 telas novas do YUV Parity formatavam com `date()/strtotime()` sem conversão, e os filtros de data tratavam o dia digitado como dia UTC. Correção sistêmica: helpers canônicos `fmt_brt()` (exibição UTC→America/Sao_Paulo), `brt_day_range_to_utc()` (dia local digitado → janela UTC na query) e `brt_today()` (defaults) em `includes/functions.php`, aplicados em 17 pontos de exibição, 8 telas com filtro de período, relatórios exportados (CSV/XLSX/PDF em BRT), popup do rastreamento, séries hora-a-hora do Resumo e por-dia do BI (`CONVERT_TZ` offset fixo) e janelas hoje/ontem do `metrics_rollup`. Colunas DATE puras (vencimento CNH, ativação) preservadas sem conversão. Regra documentada no CLAUDE.md.
- **Dashboard nunca exibia a resposta dos comandos (falso "Timeout/fila offline")**: quando o device está online, o sendInstruct devolve a resposta **no próprio HTTP response** (`data._content`), mas o `sendcommand.php` gravava `status='sent'` de qualquer forma — e o polling de `/comandos` só declara sucesso em `status='executed'`, terminando em falso timeout após 5 min sem nunca mostrar a resposta. Agora: resposta síncrona → `executed` + `response_time` imediatos (1º poll de 3s já mostra "✓ Resposta recebida" + conteúdo); fila offline (`_code` 600) permanece `sent` até o callback. `commandstatus.php` passou a extrair a resposta real do device (`data._content`/`data._msg`) em vez do `msg` genérico "success". Efeito colateral positivo: com síncronos saindo do pool de pendentes, a correlação do callback offline (heurística "mais recente pendente") fica confiável. **Callback offline real validado em produção**: `POST /pushinstructresponse → 200` vindo do container (okhttp), resposta persistida em `command_responses`.
- **Cadastro de ativos recusava devices auto-criados pelo gateway**: o gateway insere a linha do device (`customer_id NULL`) assim que ele transmite telemetria, antes do cadastro manual; o `/ativos/novo` checava o IMEI com COUNT global e respondia "já cadastrado" para um device invisível na listagem (filtrada por cliente) — beco sem saída. O cadastro agora **adota** a linha órfã (preservando a telemetria já recebida), **reativa** soft-deletados do próprio cliente e recusa apenas IMEI ativo do mesmo cliente ou vinculado a outro cliente. O form também ganhou proteção CSRF. Caso real resolvido: JC182 `869058070151343`.

### Verified (servidor de homologação, 09/07/2026)
- Comando real proNo 128 (STATUS) → device `860112070347838` respondeu em ~1s com telemetria completa (`commands.status=sent`, `response_payload` populado) — **M.2.2 ✓**
- IoTHub `:10088` UP e acessível (localhost e 10.1.0.43) — **M.2.1 ✓**
- Rota `http://10.1.0.43/pushinstructresponse` alcançável da rede docker (401 sem token; processa e grava em `command_responses` com token — validado com payload §2.4 simulado) — **M.2.3 ✓** (callback real será observado no próximo comando com device offline)
- Vídeos: `dvr-upload` (:23010) serve `/iothub/dvr-upload/uploadFile` interna e externamente (HTTP 200, 21 MB testado); o app monta `FILE_STORAGE_URL + file_url` — **Apache não precisa de acesso direto ao diretório**.

## [4.1.0] — 2026-07-08 (Fases M.1–M.5 — Pendências pós-YUV Parity)

### Added
- **Exportação Excel/PDF (Fase M.1)** — os 5 tipos de relatório do worker agora saem em CSV, **XLSX real** ou **PDF**, com seletor de formato no form de `/exportar` e badge de formato na grade. Implementação **100% PHP puro, sem Composer** (decisão: o projeto é "no package manager"): `includes/export_helper.php` com `XlsxWriter` (Office Open XML mínimo via `ZipArchive`, streaming em disco, cabeçalho azul Coinbase, IMEIs preservados como texto) e `PdfWriter` (PDF 1.4 tabular A4 paisagem, Helvetica core fonts, paginação automática, cap de 20 mil linhas). CSV melhorado: UTF-8 BOM + separador `;` (Excel pt-BR). `/exportardata` responde `format` + `mime_type`.
- **Migration `mysql/migration_v4.1.0.sql`** — coluna `jobs.format` ENUM('csv','xlsx','pdf') + fix do seed de `occurrence_config_params` (ver Fixed) + versão 4.1.0 em `system_info`. Integrada ao `scripts/deploy.sh`.
- **Script de replay E2E (Fase M.2)** — `scripts/test_e2e.sh`: ping → pushgps → pushalarm (143) → pushfileupload → verificação MySQL (alarme + ocorrência + mídia + vínculo). 8/8 verde no ambiente dev.
- **PWA (Fase M.3)** — `manifest.json` (standalone, theme `#0052ff`, background `#0a0b0d`), ícones 192/512 + variantes maskable (`assets/icons/`, gerados com GD), meta tags PWA/apple-touch em `layout_base.php` e `login_template.php`.
- **Suite Playwright (Fase M.4)** — 40 testes em 6 specs (`tests/`): login (senha errada, redirect, open-redirect R05, rate limiting opt-in), navegação (25 rotas sem erro 500/fatal), CRUD motoristas, webhook→ocorrência via `/pushalarm`, isolamento multi-tenant, exportação e2e (job→worker→download CSV/XLSX/PDF com validação de magic bytes). `playwright.config.js` sobe `php -S` automaticamente; `scripts/run-tests.ps1` para Windows. **Resultado: 37 passed, 0 failed** (3 specs opt-in pulados).
- **`API_COVERAGE.md`** — mapa completo de webhooks, AJAX e páginas com métodos, parâmetros, auth e respostas.

### Changed
- **Responsivo mobile (Fase M.3)** — sidebar off-canvas com backdrop + scroll lock + swipe-para-fechar, touch targets ≥44px, header compacto (relógio oculto, nome do cliente truncado), tabelas com scroll interno (`.table-wrap` overflow-x) e `white-space:nowrap` em células, form grids empilhados, login 100% width com inputs 16px (evita zoom iOS). Verificado com emulação iPhone 14: **0px de overflow horizontal**.
- **`server.php`** — `csv`/`xlsx` adicionados à whitelist de estáticos (downloads de relatórios no dev).
- **`scripts/worker.php`** — refatorado: as 5 funções `generate*CSV` viraram `buildReportSource()` (headers + statement + mapper) com despacho por formato.

### Fixed
- **CRÍTICO — Motor de ocorrências nunca disparava via webhook**: `pushalarm.php` capturava `lastInsertId()` **depois** do `CALL update_device_stats_after_alarm`, que reseta o valor para 0 — o gate `$alarmId > 0` nunca passava e `process_alarm_to_occurrence()` jamais era chamado. O ID agora é capturado imediatamente após o INSERT. (Descoberto pelo replay E2E da Fase M.2.)
- **CRÍTICO — Seed DMS/ADAS órfão**: os nomes dos parâmetros do perfil "Padrão Sistema" (`'Distração'`, `'Fadiga'`, `'SOS'`…) não existiam em `alarm_types`, e o matching do engine exige igualdade exata — nenhum alarme DMS gerava ocorrência. A migration v4.1.0 substitui os 19 parâmetros órfãos por 34 com os nomes reais do catálogo (JIMI 143–160/204–207, JT/T 264-X/265-X, acidentes e informativos).
- **CRÍTICO — CSRF quebrava todos os POSTs**: o token era gerado em `$_SESSION` sem `session_start()` (o app não usa sessões nativas — `$_SESSION` é por request), então cada request gerava token novo e `csrf_verify()` sempre falhava com 403 — todo CRUD (motoristas, chips, clientes, exportar…) estava inoperante desde a Fase F. O token agora é derivado por HMAC-SHA256 do token de sessão (cookie HttpOnly) + secret do servidor: estável durante o login, impossível de forjar sem o cookie.
- **`auth_init()` sem valor de retorno** — `/ocorrenciasdata` e `/exportardata` testam `if (!auth_init())` e sempre recebiam `null` → 401 permanente mesmo autenticado. Agora retorna o estado de autenticação.
- **Rota `/grupos-permissao` 404** — estava em `$simpleRoutes` (montava `grupos-permissao.php`, arquivo inexistente); movida para `$renamedRoutes` → `grupos_permissao.php` (mesma classe do fix de `config-ocorrencias` da Fase L).
- **Coluna fantasma `devices.last_position_at`** — referenciada em `worker.php` (relatório de devices), `rel_desatualizados.php` (5 buckets) e `metrics_rollup.php`, mas não existe em nenhuma migration; as queries falhavam (mascaradas pelos try-catch da Fase K). Corrigido com `LEFT JOIN device_statistics` → `last_gps_time` (fonte viva mantida pelas procedures).
- **`Logger.php` deprecation PHP 8.1+** — `date()` recebia float de `microtime(true)`; o warning de conversão implícita vazava HTML nas respostas JSON dos webhooks (headers already sent). Cast para int.
- **`exportar.php` passava o token CSRF como flag** — `csrf_verify($_POST['csrf_token'])` usava a string como parâmetro `$exit_on_fail`; trocado por `csrf_verify()`.

### Notes
- Pendências que exigem produção/dispositivo real (documentadas no STATUS.md §11): IoTHub `localhost:10088` (M.2.1–M.2.3), OTA proNo 33027 (M.2.5), execução do `test_e2e.sh` no servidor.

## [4.0.0] — Não lançado (iniciativa "YUV Parity")

Reorientação do produto para ser uma **cópia fiel da plataforma YUV** (`app.yuv.com.br`) — plataforma multi-tenant de rastreamento com **telemetria de vídeo e gestão de ocorrências DMS**. Esta entrada cobre o **planejamento e a documentação**; a implementação segue o roadmap por fases de `PROJETO_YUV.md`.

### Added
- **`PROJETO_YUV.md`** — blueprint-mestre de implementação: visão, modelo de negócio (revendedor/cliente/filial), arquitetura-alvo, mapa de 22 rotas, design system, modelo de dados (migração v4.0.0), **motor de ocorrências** (alarme→ocorrência), spec módulo a módulo das 22 telas, roadmap por fases, critérios de aceite e plano de verificação.
- **`analise_yuv/analise_yuv.html`** — análise funcional do YUV (22 telas + 6 modais navegados via browser, com screenshots, regras de negócio, dinâmica e análise de lacunas vs. o projeto atual).
- **Design system YUV** documentado em `DESIGN.md` (ver Changed).
- **Planejamento de novas tabelas** (v4.0.0): `occurrences`, `occurrence_events`, `occurrence_configs`, `occurrence_config_params`, `drivers`, `sim_cards`, `branches`, `permission_groups`, `trips`, `jobs`, `geocode_cache`, `impersonation_log`.
- **Planejamento de novos módulos**: Dashboard de Ocorrências (DMS), Relatório de Ocorrências, Configurações de Ocorrências, BI, Exportação assíncrona, Vídeo estruturado (Ao Vivo/Playback/Downloads), Chips, Motoristas (CNH/toxicológico + FaceID), Grupos de Permissões, Equipamentos avançado (OTA firmware, importação em lote), Resumo executivo.

### Changed
- **Design system Coinbase aplicado** — o skin visual do produto passou a ser o **sistema Coinbase** (`DESIGN-coinbase.md`): Coinbase Blue `#0052ff` como única voltagem, canvas branco, **sidebar dark near-black `#0a0b0d`** com item ativo azul, CTAs **pill (100px)**, cards com hairline + um único nível de sombra (hover), headings de display em peso 400, **JetBrains Mono em todo número/IMEI**. Implementado em `web/layout_base.php`, `web/login_template.php` e `handlers/setup.php`; `DESIGN.md` reescrito como o design system do app derivado da Coinbase.
- _(Nota: a paleta roxa YUV chegou a ser proposta nesta iniciativa e foi **descartada** em favor do skin Coinbase. A estrutura/IA de produto permanece a do YUV.)_
- **`CLAUDE.md`, `AGENTS.md`, `STATUS.md`, `README.md`, `PLAN.md`, `llms.txt`** — atualizados para o direcionamento YUV Parity (nova visão, rotas-alvo, tabelas, ponteiros para `PROJETO_YUV.md`).
- **`STATUS.md`** — nova §0 com o roadmap por fases da iniciativa v4.0.0.

### Fixed
- **`mysql/jimi_tracker.sql` quebrava num fresh install**: o export do HeidiSQL gerou dois stubs de VIEW malformados (`CREATE TABLE vw_alarm_types_ambiguous_codes` / `vw_alarm_types_unknown_codes` sem colunas → erro de sintaxe) e as duas VIEWs `vw_alarm_types_*` referenciavam a tabela `alarm_types_reference`, que nunca é definida no dump. Os 4 blocos foram removidos (views diagnósticas, não usadas por nenhum handler). O comando documentado `mysql < mysql/jimi_tracker.sql` agora aplica sem erros (validado: 22 tabelas, 3 views, 114 alarm_types).
- **Ambiente de desenvolvimento local (Windows)**: adicionados `server.php` (router shim que reproduz o front controller do `.htaccess` sob `php -S`) e `scripts/dev-windows.ps1` (sobe MySQL portátil + servidor PHP). Fecha a pendência **F0.1** (PHP CLI/lint indisponível localmente).

### Notes
- O gateway de webhooks (`handlers/push*.php` + `config/WebhookHandler.php`) e a autenticação por token são **preservados**.
- As dívidas de segurança da revisão v3.2.x (CSRF, prepared statements, índices, cookie Secure) serão fechadas **na origem** ao reescrever os handlers em cada fase.

## [3.2.1] — 2026-07-04

### Security
- **Cross-tenant data leak fechado nos endpoints AJAX (R01/R02)**: `camerasdata.php`, `trackdata.php`, `hbdata.php`, `mediadata.php`, `commandstatus.php` e `sendcommand.php` agora exigem sessão de dashboard ativa (`require_ajax_session()` em `includes/auth.php`) e filtram TODAS as queries pelo `customer_id` da sessão. O token compartilhado (`WEBHOOK_TOKEN`) não concede mais acesso sozinho — antes, qualquer portador do token via dados (GPS, heartbeats, mídia, comandos) de todos os clientes e podia enviar comandos para qualquer IMEI.
- **`sendcommand.php` valida posse do IMEI**: comandos só são aceitos para dispositivos ativos do cliente da sessão (HTTP 403 caso contrário).
- **`sendcommand.php` bloqueia proNo fora da whitelist (R03)**: proNo desconhecido agora retorna HTTP 400 (antes apenas logava warning e enviava o comando).
- **Open redirect corrigido no `login.php` (R05)**: parâmetro `redirect` sanitizado via `safe_redirect_path()` — aceita apenas paths locais; rejeita URLs absolutas, `//host`, backslash e CR/LF.
- **`commandstatus.php` não aceita mais `?customer_id=` do cliente**: o escopo vem exclusivamente da sessão.

> Nota: as entradas de v3.1.0 (multi-tenant + auth) e v3.2.0 (usuários/perfil) ainda serão registradas retroativamente (pendência F6.3).

## [3.0.0] — 2026-06-10

### Added
- **Design System Cursor-inspired**: redesign completo do dashboard baseado no DESIGN.md
- **Tipografia editorial**: Inter (weight 400/500/600) + JetBrains Mono em todas superfícies de código
- **Design tokens**: 30+ CSS custom properties (surfaces, hairlines, text, brand, timeline pastels, semantic, radii, spacing)
- **Timeline pastels**: 5 cores dedicadas para status pills (thinking=peach, grep=mint, read=blue, edit=lavender, done=gold)
- **Protocol toggle**: pill selector substituindo radio buttons Bootstrap para JIMI/JTT
- **Galeria de mídia responsiva**: cards 3-colunas com thumbnails condicionais (imagem real vs ícone por tipo), download + player
- **Player de vídeo modal**: suporte a playback de arquivos de mídia via modal dedicado
- **Configuração assíncrona**: queries device info/params/set com feedback em code-block
- **`docs/PRD.md`**: Product Requirements Document completo (12 seções, 650+ linhas)
- **Plano de redesign**: `.opencode/plans/dashboard-redesign.md`

### Changed
- **Painel**: migrado de visual Bootstrap 5.3 padrão para design system Cursor-inspired
  - Canvas: `#f0f2f5` (cinza Bootstrap) → `#f7f7f4` (cream quente)
  - Cor primária: `#0d6efd` (azul) → `#f54e00` (Cursor Orange)
  - Profundidade: sombras Bootstrap → hairlines 1px (`#e6e5e0`)
  - CTAs: `rounded-pill` → raio 8px (dev-tool dialect)
  - Cards: shadows → bordas hairline + white-on-cream contrast
  - Tabelas: zebra stripe → hairline lines + hover canvas-soft
  - Alarmes: tabela densa → cards individuais com barra de severidade colorida
  - Status: badges Bootstrap → timeline pastel pills
  - Tabs: nav-tabs Bootstrap → navegação editorial com underline laranja
  - Forms: Bootstrap form-control → ds-input (44px, 8px radius, focus ring laranja)
  - Code blocks: bg-dark com texto claro → ds-code-block (canvas-soft, fonte mono)
  - Navbar: bg-dark → cream canvas com dots coloridos
- **`web/dashboard_template.php`**: reescrita completa (~850 linhas) com CSS tokens + JS inline + HTML adaptado
- **`web/assets/js/dashboard.js`**: atualizado para novas classes (`cs-*` → `ds-cmd-*`, `src-*` → `ds-origin-*`, protocol toggle como pills)
- **Fontes**: Bootstrap Icons → Google Fonts (Inter + JetBrains Mono via CDN)
- **Versionamento**: `2.0.0` → `3.0.0` (major bump — redesign completo do frontend)

### Removed
- Classes CSS Bootstrap visuais (`bg-*`, `btn-*`, `badge`, `table-*`, `card`, `shadow-*`, `border-*` utilitários visuais)
- Protocol radio buttons (`input[name="proto"]`) substituídos por `.ds-proto-option` pill selector
- Estilos inline de cores (`style="background:..."`) no JS de renderização dinâmica

## [2.0.0] — 2026-06-09

### Added
- Handler `/pushTerminalTransInfo` (Seção 1.15) — persistência em `device_events`
- Tabela `command_responses` para respostas assíncronas/offline de comandos
- Colunas `acc`, `oil_ele`, `gps_pos`, `remote_lock`, `power_status`, `fortify` em `heartbeats`
- Colunas `post_type`, `post_method`, `driver_license`, `door_status`, `sos_status`, `temperature`, `transparent_data` em `gps_data`
- Campo `requestMeta` no `WebhookHandler` para metadados extras do POST (ex: `msgType`)
- Funções `sanitize_date()` e `detect_media_type()` em `includes/functions.php`
- PHPDoc completo em `includes/functions.php` (8 funções documentadas)
- `docs/API_COVERAGE.md` — matriz de cobertura de endpoints
- `README.md`, `CHANGELOG.md`, `LICENSE`, `llms.txt`
- `docs/adr/ADR-001.md` — decisão de isolamento de protocolo JIMI/JTT
- **Dashboard unificado**: `web/index.php` agora é wrapper para `handlers/dashboard.php` + template canônico
- **Aba Mídia**: galeria de arquivos (imagem/vídeo/áudio) com filtro por IMEI
- **Player de vídeo HTTP-FLV**: flv.js para stream ao vivo e playback na aba Câmeras
- **Aba Configuração**: ler/alterar parâmetros do dispositivo (proNos 33027-33031)
- **Handlers de consulta**: `/trackdata` (GPS histórico), `/hbdata` (heartbeats), `/mediadata` (galeria)
- **Modal de detalhes de comando**: JSON formatado no histórico
- **Coordenadas + link de mapa** na tabela de alarmes
- **Links de arquivo de mídia** nos alarmes
- Presets JTT: `34817|foto`, `34818|midia`, `33028|params`, `33030|params_esp`, `33031|info`, `33029|reset`
- Variáveis `.env`: `FILE_STORAGE_URL`, `STREAM_URL`

### Changed
- **Logger unificado**: `core/Logger.php` (estático) é o único logger do sistema
- **Handler `pushiothubevent`**: migrado para extender `WebhookHandler` (token, idempotência, transação)
- **Handler `pushhb`**: extrai todos os 12 campos documentados (eram apenas 6)
- **Handler `pushgps`**: extrai todos os 28 campos documentados (eram apenas 17)
- **Handler `pushfileupload`**: reescrito para usar `fileName` (split), `gateTime`, `result` da spec
- **Handler `pushftpfileupload`**: reescrito para usar `result`, `instructionID`, `gateTime` da spec
- **Handler `pushlbs`**: reescrito para parsear `lbsJson` + `cellList` (LAC,CI,RSSI)
- **Handler `pushinstructresponse`**: reescrito para estrutura `{code, msg, data: {_imei, ...}}`
- **Handler `pushevent`**: `gateTime` priorizado como campo primário de tempo; `timezone` extraído
- **Handler `pushalarm`**: unificado para usar stored procedure `update_device_stats_after_alarm`
- **`get_webhook_data()`**: preserva todos os campos POST (não apenas `token` e `data_list`)
- **Stored procedure `update_device_stats_after_alarm`**: agora aceita coordenadas opcionais
- **Comentários**: 100% PT-BR, padronizados com template de 4 linhas (Endpoint, Versão, Referência)
- **Versionamento**: reset global para `2.0.0`

### Removed
- **`includes/config.php`**: removido (config duplicada, substituída por `.env` + `database.php`)
- **Classe `Logger` de `includes/functions.php`**: removida (unificada com `core/Logger.php`)
- **`handlers/pushterminalrealtimestatus.php`**: substituído por `pushTerminalTransInfo.php`
- **Métodos `sanitizeTimestamp()` duplicados**: removidos de `pushiothubevent.php` e `pushTerminalTransInfo.php`

### Fixed
- **pushalarm.php**: chave de fechamento da classe ausente/desalinhada (linha 420)
- **pushalarm.php**: 5 chamadas `Logger::` sem `'source'` no contexto
- **pushiothubevent.php**: sem validação de token, sem idempotência (migrado para WebhookHandler)
- **pushterminalrealtimestatus.php**: só logava raw payload, não persistia no banco
- **pushfileupload/pushftpfileupload**: campos mapeados incorretamente vs documentação oficial
- **pushlbs**: não parseava `lbsJson` + `cellList`
- **pushinstructresponse**: estrutura de payload completamente diferente da documentada
- **Painel**: presets JTT quebrados (data ISO em vez de JTT, sem serverFlagId)
- **Painel**: `serverFlagId` ausente no `sendCommand()` e `requestVideoUpload()` do JS antigo
- **Painel**: require case-sensitive `DashboardData.php` → `dashboarddata.php` no `web/index.php`
- **Painel**: dois dashboards divergentes (`web/index.php` vs `/dashboard`) unificados

## [1.0.0] — 2026-01-23 (v3.0.1 original)

### Added
- 10 webhook endpoints iniciais (pushevent, pushhb, pushgps, pushalarm, pushfileupload, pushlbs, pushresourcelist, pushftpfileupload, pushiothubevent, pushinstructresponse)
- Painel Bootstrap 5.3 com 3 abas (Monitoramento, Alarmes, Comandos)
- `WebhookHandler` abstrato com token, idempotência, async, transação
- `core/Logger.php` v2.0.0 com rotação diária e JSON context
- Stored procedures MySQL (`update_device_stats_after_*`)
- Tabela `alarm_types` com 114 códigos JIMI + JTT
- Decodificador de bitmask JT/T 808 (32 bits)
- Suporte dual-protocol JIMI/JTT no pushalarm v6.2
