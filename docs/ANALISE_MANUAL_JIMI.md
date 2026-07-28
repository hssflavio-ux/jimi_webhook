# Análise do User Manual Jimi — Oportunidades de Evolução

**Fonte**: `User Manual.pdf` (154 páginas, edição de 04/12/2024) — manual da plataforma TSP do fabricante Jimi IoT.
**Data da análise**: 28/07/2026
**Base comparada**: Jimi Webhook System v4.3.0 (`handlers/router.php`, `mysql/*.sql`, `handlers/*.php`).

Este documento lista **apenas o que não existe no projeto hoje**. Cada item traz: dinâmica de
funcionamento, resultado esperado e a justificativa da decisão (incluindo o que foi
deliberadamente descartado, na última seção).

Referência oficial da API: `https://docs.jimicloud.com/integration/integration.html`

---

## Índice

| # | Funcionalidade | Tier | Dado já no banco? |
|---|---|---|---|
| 1 | Geocercas + POI com relatório de entrada/saída | 1 | Sim (`gps_data`) |
| 2 | Motor de notificações (sino, pop-up, som, e-mail) | 1 | — (infra nova) |
| 3 | Relatórios Parada / Ociosidade / Ignição / Velocidade / Status da Frota | 1 | Sim (`gps_data.acc`, `.speed`) |
| 4 | Relatório agendado por e-mail (Auto Report) + templates salvos | 1 | Sim (reusa `jobs`) |
| 5 | Playback animado da rota | 1 | Sim (`gps_data`) |
| 6 | Manutenção preventiva por hodômetro + central de lembretes | 2 | Sim (`gps_data.mileage`, `drivers`) |
| 7 | Compartilhamento de rastreamento por link temporário | 2 | Sim |
| 8 | Telemetria de sensores (temperatura, tensão, porta) | 2 | **Sim — gravado e nunca exibido** |
| 9 | Solicitar mídia faltante de um alarme, por canal | 2 | Parcial (`media_files.channel`) |
| 10 | Roteirização com alerta de desvio de rota | 2 | Sim (`gps_data`) |
| 11 | Ciclo de vida comercial do equipamento (vigência/renovação) | 3 | — (schema novo) |
| 12 | Templates de comando + envio em lote | 3 | Sim (reusa `commands`) |
| 13 | Log de negócio (auditoria) + histórico de login | 3 | — (schema novo) |

**Sequência recomendada**: 2 → 1 → 3 → 4. O motor de notificação (2) destrava os itens 1, 4, 6 e 10;
os itens 1 e 3 são os que mais aparecem em concorrência. Os itens 8 e 9 têm o melhor retorno
imediato porque o dado já está no banco sem consumidor.

---

## TIER 1 — Alto valor comercial, dados já disponíveis no banco

### 1. Geocercas (polígono/círculo) + POI com relatório de entrada/saída

**Manual**: §6.4 (Fence/POI no mapa), §7.2.4 (Geo Fence Report).

**Dinâmica de funcionamento**
CRUD de cercas desenhadas no Leaflet (tabelas `geofences`, `geofence_devices`, `geofence_events`).
Um worker avalia cada ponto novo de `gps_data` com *point-in-polygon* em PHP puro contra as cercas
vinculadas ao IMEI, gera evento de entrada/saída e dispara alerta. Relatório com cerca, hora de
entrada, hora de saída e tempo de permanência.

**Resultado esperado**
Controle de pátio/base/obra, confirmação de entrega no cliente e alerta de saída de área
permitida — sem depender de firmware do equipamento.

**Por quê**
Hoje só gravamos `alarms.fence_id`, que é a cerca configurada *dentro do equipamento* e portanto
invisível para o operador da plataforma. É a funcionalidade nº 1 que qualquer comprador de
rastreamento no Brasil pede na primeira demonstração, e roda 100% sobre dados que já temos
(lat/lng/acc). Maior alavancagem comercial por esforço de todo o levantamento.

---

### 2. Motor de notificações: sino in-app + pop-up em tempo real + som + e-mail

**Manual**: §6.5.1 (Alert Settings — push, som customizável, pop-up de tempo real), §11 (Message).

**Dinâmica de funcionamento**
Tabela `notification_rules` por cliente × tipo de alarme (notifica? pop-up? som? quais e-mails?) e
tabela `notifications`. O `pushalarm.php` e o `occurrence_engine.php` enfileiram em `jobs`; o
`scripts/worker.php` despacha SMTP e grava o registro; o `web/layout_base.php` faz polling e mostra
badge, toast e áudio.

**Resultado esperado**
SOS/pânico e ocorrências DMS críticas chegam ao operador em segundos, mesmo com a aba fechada.

**Por quê**
O projeto **não tem nenhuma infraestrutura de notificação** — nenhum `mail()`, nenhum SMTP
(verificado por varredura no código). Hoje toda a operação de ocorrências depende de alguém estar
com o `/ocorrencias/dashboard` aberto acompanhando o polling de 15s. Isso é o teto do produto: sem
push, não se vende central 24h. Reaproveita a fila `jobs` já existente e é pré-requisito dos itens
4 e 6.

---

### 3. Relatórios operacionais ausentes: Parada, Ociosidade, Ignição, Excesso de Velocidade e Status da Frota

**Manual**: §7.2.1 (Parking, Idling, Ignition, Vehicle Status, Vehicle Status Details),
§7.2.3 (Overspeed).

**Dinâmica de funcionamento**
Derivar de `gps_data` no mesmo padrão do `scripts/trip_builder.php`:

- **Parada** — `acc = 0`
- **Ociosidade** — `acc = 1 AND speed = 0` (motor ligado parado)
- **Ignição** — transições de `acc`, com duração de cada estado
- **Excesso de velocidade** — `speed > limite` configurado por equipamento/cliente
- **Status da Frota** — sumário atual (em movimento / parado ligado / parado / offline) com
  **duração no estado** e drill-down por equipamento

**Resultado esperado**
Relatório de motor ligado parado (custo direto de combustível), controle de jornada e de
velocidade — os três indicadores que o gestor de frota cobra.

**Por quê**
Temos posições, deslocamento, alarmes, ocorrências e desatualizados; falta exatamente a camada de
*comportamento operacional*. O banco já guarda `acc`, `speed` e `mileage` — é SQL mais tela
reusando `filter_bar`, `crud_grid` e `report_pagination()`. Custo baixíssimo e item de checklist
em concorrência.

---

### 4. Relatório agendado por e-mail (Auto Report) + templates de relatório salvos

**Manual**: §7.3 (Auto Report — tipo, frequência, até 3 e-mails), §7.2 (nova versão do My Report,
com template salvo por usuário).

**Dinâmica de funcionamento**
Tabela `report_schedules` (tipo, filtros em JSON, frequência diária/semanal/mensal, destinatários).
O cron enfileira o job na hora marcada, o `scripts/worker.php` gera o arquivo com o
`includes/export_helper.php` (XLSX/PDF já prontos) e envia como anexo. O mesmo JSON de filtros
serve como "template" na tela, evitando reconfigurar os filtros a cada consulta.

**Resultado esperado**
O gestor recebe o consolidado da semana na segunda de manhã sem logar; o operador não reconfigura
8 filtros toda vez.

**Por quê**
Encaixa em três peças já construídas (`jobs` + `worker.php` + `export_helper.php`) — o incremento
real é a tabela de agendamento e o envio. É o recurso clássico de **retenção**: relatório que
chega sozinho mantém a conta viva.

---

### 5. Playback animado da rota (player 0.1x–8x, alarmes na timeline, marcação de parada)

**Manual**: §6.6 (Tracks / control panel do player).

**Dinâmica de funcionamento**
Reaproveitar o endpoint de pontos do `/relatorios/deslocamento/rota` e adicionar play/pause/replay,
seletor de velocidade, marcador percorrendo a polyline, pinos de alarme clicáveis (agrupados
quando próximos) e limiar configurável de "quanto tempo parado vira ponto de parada".

**Resultado esperado**
Auditoria de sinistro e resposta a reclamação de cliente ("prove que o veículo passou às 14h").

**Por quê**
É praticamente só frontend sobre dados que já servimos, e é o recurso **mais demonstrável numa
venda** — o mapa que se move fecha reunião. Hoje o `rel_deslocamento_rota.php` desenha apenas o
traçado estático.

---

## TIER 2 — Diferenciação e novos verticais

### 6. Manutenção preventiva por hodômetro + central de lembretes configuráveis

**Manual**: §5.3.2 (Maintenance Alert com calibração de hodômetro), §9.2 e §9.3 (lembrete de CNH e
de seguro com antecedência, repetição e e-mails).

**Dinâmica de funcionamento**
Tabela `reminders` genérica: entidade + campo-data **ou** meta de quilometragem, antecedência em
dia/semana/mês, intervalo de repetição e destinatários. Worker diário compara com
`gps_data.mileage` e com as datas de `drivers`, disparando pelo item 2. Inclui edição em lote via
Excel.

**Resultado esperado**
Módulo de compliance vendável como add-on: CNH, exame toxicológico, seguro, licenciamento, troca
de óleo, revisão.

**Por quê**
Já existe um pedaço disso em `/motoristas` (badge de vencimento de CNH), mas é um beco sem saída —
não notifica ninguém e não vale para veículo. Generalizar custa pouco e transforma um badge em
produto.

---

### 7. Compartilhamento de rastreamento por link temporário

**Manual**: §6.3.6 (Share).

**Dinâmica de funcionamento**
Tabela `share_links` (token aleatório, IMEI, validade) e rota pública `/s/{token}` sem
autenticação, renderizando somente a posição atual em mapa read-only, com expiração automática.

**Resultado esperado**
O embarcador ou cliente final acompanha a carga sem virar usuário licenciado.

**Por quê**
Esforço mínimo (uma tabela e um handler) e efeito de marketing desproporcional — cada link é a
marca do cliente aparecendo para terceiros.

> **Ressalva de segurança**: expiração obrigatória e exposição **apenas da posição atual**, nunca
> do histórico. Um link permanente com trilha completa é vazamento de rota.

---

### 8. Telemetria de sensores (temperatura/umidade, porta, tensão) com gráfico e alerta por limiar

**Manual**: §7.2.2 (Temperature & Humidity, Logistics/Door Status, External/Internal Battery,
Various sensor data).

**Dinâmica de funcionamento**
Tela de série temporal (Chart.js) sobre `heartbeats`, mais regra de limiar por equipamento
(ex.: `temperatura > -15 °C por 10 min` → alarme → notificação do item 2).

**Resultado esperado**
Entrada no vertical de **cadeia fria** (alimentos, vacinas, farmacêutico), que paga ticket mais
alto que rastreamento puro.

**Por quê**
`heartbeats.temperature` e `heartbeats.voltage` **já são gravados e não são exibidos em lugar
nenhum**. É o melhor retorno por linha de código do levantamento — o dado já está pago e parado.

---

### 9. Solicitar mídia faltante de um alarme, por canal ("Get More")

**Manual**: §6.5.3 (obter vídeo/imagem do alarme manualmente, com status Adquirido / Falha de
upload / Arquivo não encontrado, por canal).

**Dinâmica de funcionamento**
Na tela de tratativa da ocorrência, listar quais canais já enviaram arquivo e quais não. O botão
dispara o comando ao equipamento para os canais faltantes; o retorno atualiza
`media_files.download_status` e revincula o arquivo à ocorrência.

**Resultado esperado**
O operador não fica travado numa ocorrência sem imagem — solicita o vídeo do canal certo durante a
tratativa.

**Por quê**
É a única lacuna do Tier 1/2 que está **dentro do núcleo declarado do produto** (gestão de
ocorrências DMS, ver `PROJETO_YUV.md`). Hoje `/video/downloads` já mostra canal e status, mas a
tratativa em `ocorrencias_dashboard.php` não tem nenhum acionamento de mídia. Fecha o ciclo
alarme → ocorrência → prova.

---

### 10. Roteirização com alerta de desvio de rota

**Manual**: §9.5 (Route Planning — até 20 paradas, limiar de desvio padrão de 1 km, janela de
validade, realerta a cada 2 minutos).

**Dinâmica de funcionamento**
Rota como polyline desenhada no mapa, com paradas, veículos vinculados e janela de vigência. O
worker calcula a distância ponto-linha e gera alerta enquanto o veículo estiver fora do limiar.

**Resultado esperado**
Controle de itinerário para distribuição, transporte de valores e locação.

**Por quê**
É o recurso premium do manual, mas o de maior custo desta lista. Recomendação: implementar a
**versão simples com polyline desenhada à mão** e **não** integrar roteador externo (OSRM/Google)
numa primeira fase, para não criar dependência de serviço pago. Depende do item 1 estar pronto —
mesma matemática geográfica.

---

## TIER 3 — Operação de revenda e governança

### 11. Ciclo de vida comercial do equipamento: vigência, renovação e transferência entre contas

**Manual**: §5.1 (importação com ativação manual/automática), §5.4 (Move Device), §5.2 (busca por
expirado/a vencer), §12 (renovação).

**Dinâmica de funcionamento**
Colunas `platform_expires_at`, `user_expires_at` e `activation_mode` em `devices`; tela de
renovação em lote; bloqueio de leitura de dados de equipamento vencido; registro de transferência
revendedor → cliente.

**Resultado esperado**
Faturamento recorrente e suspensão controlados pelo sistema, e não por planilha.

**Por quê**
O schema já tem `customers.reseller_id` e `impersonation_log` — a intenção de operar por revenda
está declarada, mas **não existe nenhuma noção de vigência comercial**. Sem isso não há como
cobrar nem cortar.

> **Decisão explícita**: copiar as *datas e o status*, **não** o "Mi Coins" (§12). Moeda interna é
> o modelo do fabricante para vender crédito a distribuidor; num SaaS direto ela só adiciona
> atrito de compra.

---

### 12. Templates de comando + envio em lote com acompanhamento e cancelamento

**Manual**: §13 (Command Template, Batch Command, Working Mode Template, cancelamento de comando
pendente).

**Dinâmica de funcionamento**
Tabela `command_templates` (modelo + conjunto de comandos com parâmetros). A seleção múltipla de
IMEIs cria N linhas em `commands` sob um `batch_id`, com tela do lote exibindo progresso,
cancelamento de pendentes e exportação do resultado.

**Resultado esperado**
Campanha de configuração ou FOTA em 300 equipamentos vira um clique, com evidência de quem
respondeu.

**Por quê**
Dor real de pós-venda e instalação. A tabela `commands`, o dispatcher e o polling 3s/10s já
existem — é orquestração sobre o que está pronto, sem tocar em protocolo.

> **Restrição de protocolo**: respeitando `docs/adr/ADR-001.md`, um template pertence a um único
> `msgClass` (0 = JIMI, 1 = JT/T 808). Nunca misturar os dois no mesmo lote.

---

### 13. Log de negócio (auditoria de operações) e histórico de login por usuário

**Manual**: §14.2 (Business Log), §14.3 (Login logs).

**Dinâmica de funcionamento**
Helper `audit_log($acao, $entidade, $antes, $depois)` chamado nos CRUDs e nas transições de status
de ocorrência, com tela filtrável por usuário, período e entidade.

**Resultado esperado**
Rastreabilidade de "quem descartou esta ocorrência como falso-positivo" e "quem apagou este
motorista".

**Por quê**
Hoje existem apenas `login_log` (usado para rate limit) e `impersonation_log`. Uma tratativa de
ocorrência — que é a prova de gestão que o cliente apresenta à seguradora dele — pode ser alterada
sem deixar rastro. Barato de implementar e costuma ser requisito eliminatório em conta corporativa
e em avaliação de LGPD.

---

## O que foi deliberadamente descartado

| Item do manual | Motivo da exclusão |
|---|---|
| **Mi Coins / carteira / serviços de valor agregado** (§12) | Modelo de crédito do fabricante para distribuidores. Num SaaS B2B direto vira atrito de compra. Substituído pelo item 11 (datas de vigência). |
| **Multi-tanque de combustível, OBD/CAN, RFID, check-in Bluetooth** (§5.3.2, §7.2.1, §7.2.2, §9.4) | Dependem de periférico/hardware fora do parque atual. Implementar sob demanda de um cliente pagante específico, não especulativamente. |
| **Impressão nativa de relatório** (§7.2, presente em todos) | Redundante — já temos XLSX e PDF no `includes/export_helper.php`. |
| **Conta virtual e árvore de contas de 4 níveis** (§4.1, §4.6) | Já coberto funcionalmente por `permission_groups` + `branches` + impersonar. Copiar a hierarquia Sales → Distributor → Enterprise → End User traz complexidade sem receita nova. |
| **Colunas selecionáveis nos relatórios** (§7.2) | Valor comercial baixo isoladamente — mas é barato acoplar junto com os relatórios novos do item 3. Tratado como detalhe de implementação, não como funcionalidade. |

---

## Notas de método

- O texto do PDF foi extraído com `pypdf` (o Read nativo exige `poppler-utils`, ausente no ambiente
  Windows de desenvolvimento).
- Cada lacuna foi confirmada por varredura no código antes de entrar na lista: `handlers/router.php`
  (rotas existentes), `mysql/jimi_tracker.sql` e `mysql/migration_v*.sql` (schema), e busca por
  palavra-chave nos handlers.
- Itens marcados como "dado já no banco" não exigem mudança de firmware nem de integração com o
  IoT Hub — apenas leitura do que os webhooks já persistem.
