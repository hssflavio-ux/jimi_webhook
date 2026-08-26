<?php
/**
 * JIMI Webhook System — Catálogo de configuração de IA (ADAS/DMS/velocidade) v1.0
 *
 * Fonte: reprocessamento direto das planilhas oficiais do fabricante em
 * 25/08/2026 — não é derivado de `includes/command_catalog.php`.
 *
 *   - `docs/JC 371 Command List V1.0.1.xlsx`, aba "Lista de Comandos" (PT-BR),
 *     linhas D001/D002/D015/E003–E042 — JC371.
 *   - `docs/JC400 & JC261 Command List V5.0.3.20230626.xlsx`, aba "Command
 *     list" (EN), linhas G001–G015 — JC400D/JC400AD/JC261/JC261P.
 *     **JC261 é a nossa JC400AD** (código de fábrica; ver
 *     includes/command_catalog.php sobre a mesma armadilha nos comandos
 *     ADASxx, que sumiam até a v4.9.27 por isso).
 *   - `docs/JC181_Command_List_V1.0.7_20250811.xlsx`, aba "Command list"
 *     (EN), linha D003 — JC181.
 *   - `docs/JC450 series command list-EN V2.1.1.xlsx`, aba de comandos (EN),
 *     linhas D004/G001–G021 — JC450 (adicionada em 26/08/2026; JC450 TEM
 *     planilha própria, diferente do JC182 abaixo — a nota antiga dizia que
 *     não tinha, estava desatualizada).
 *   - JC182: **não tem planilha própria no `docs/`**. Cobertura vem de duas
 *     fontes: (1) teste real de campo em 26/08/2026 (dono do produto,
 *     `EVENTSET,ACD`/`AVD`/`AOSD` — os únicos 3 códigos EVENTSET que o
 *     equipamento respondeu; os demais códigos ADAS/DMS herdados do JC371
 *     por analogia foram REMOVIDOS desta tela nessa mesma data, pois o JC182
 *     não tem câmera de IA/visão computacional) e (2)
 *     `docs/JC181_Command_List_V1.0.7_20250811.xlsx` — o JC182 comparte o
 *     vocabulário "de planilha" do JC181 (SENALM/SPEED/SPEEDCHECK/SWERVE/
 *     COLLIDE/FATIGUE/GFENCE) por instrução direta do dono do produto,
 *     mas esses ficam em `command_catalog.php`/`/comandos` — são
 *     acelerômetro/GPS, não visão computacional (ver "Fora de escopo" abaixo).
 *   - A wiki (`wiki-foconavia.newtectelemetria.com.br`) é uma SPA em JS que o
 *     WebFetch não consegue renderizar (só devolve o título da página,
 *     confirmado em 25/08/2026 e de novo em 26/08/2026 para a página do
 *     JC450) — nenhuma entrada deste arquivo vem da wiki além do que já
 *     estava aqui por herança do catálogo antigo (`procedencia: 'wiki'`).
 *
 * ── Por que cada modelo tem um vocabulário DIFERENTE ────────────────────────
 * Não existe sintaxe universal de ADAS/DMS no proNo 128: JC371 usa
 * `EVENTSET,<código>`/`EVENTALERT,<código>,A,B,C` (um par por evento) +
 * `DMSSP`/`DMSVSP`/`ADAS,CALIBRATION`; a família JC400D/JC400AD/JC261/JC261P
 * usa `DMSSW`/`DMS_*`/`ADASxx` — vocabulário totalmente diferente, mesmo
 * conceito. JC181 não tem câmera de IA (sem chip de visão) — só evento de
 * velocidade por GPS (`SPEED`) e colisão por acelerômetro (fora de escopo
 * aqui, ver abaixo). É por isso que esta tela agrupa por MODELO, não por
 * comando: mostrar `EVENTSET,ALDW` pra uma JC400AD seria comando que ela
 * nunca vai entender.
 *
 * ── Fora de escopo, de propósito ────────────────────────────────────────────
 * Pedido do dono do produto: só "configuração de parâmetros de ADAS, DMS e
 * velocidade" (exemplo: wiki `IA-jc371`). Ficam de fora, mesmo estando perto:
 *   - `EVENTSET,FACE` (JC371) — CRUD de biblioteca facial (upload/exclusão de
 *     foto), não é "parâmetro com máscara".
 *   - `CRASHALM`/`SENSOR`/`SHOCK`/`DEFENSE*`/`RAPIDACC`/`RAPIDDEC`/
 *     `RAPIDTURN`/`RAPIDTEST` (JC400/JC261) e `SENALM`/`COLLIDE`/
 *     `SPEEDCHECK`/`SWERVE`/`FATIGUE`/`GFENCE` (JC181) — colisão/vibração/
 *     curva/frenagem brusca/fadiga/cerca eletrônica por ACELERÔMETRO ou GPS,
 *     não visão computacional. Continuam só em `/comandos` — sem pedido do
 *     dono do produto para trazê-los aqui **para o JC181**.
 *   - ⚠️ EXCEÇÃO deliberada a esta regra, só para o JC182: `SENALM`/
 *     `COLLIDE`/`SPEEDCHECK`/`SWERVE`/`FATIGUE`/`GFENCE`/`EVENTSET,ACD`/
 *     `EVENTSET,AVD` SÃO acelerômetro/GPS, mas o dono do produto pediu
 *     explicitamente (26/08/2026) que entrassem NESTA tela — o JC182 não
 *     tem tela rica de DMS/ADAS como o JC371/JC400AD, então a tela de IA é
 *     o painel de configuração dele. ⚠️ O pedido foi POR MODELO, listado
 *     explicitamente pelo dono do produto — **não vale por analogia para o
 *     JC181**, mesmo os dois compartilhando a mesma planilha de origem
 *     (corrigido em v4.13.14, depois de replicar por engano para o JC181
 *     também). Ver a seção "JC182 — comandos de acelerômetro/GPS" mais
 *     abaixo. Duplicadas de propósito em `command_catalog.php`/`/comandos`
 *     (mesma fonte, mesmos parâmetros) — não é inconsistência, é o mesmo
 *     comando acessível pelos dois caminhos.
 *   - `EVENTSET,FACE,*` (JC450, G016–G019) — mesmo caso do JC371 acima, CRUD
 *     de biblioteca facial.
 *   - `COLLIDE`/`INSTALLANGLE` (JC450, D011/D012) — colisão por acelerômetro
 *     e ângulo de instalação para o algoritmo dela; mesma política do
 *     `COLLIDE` do JC181/JC182. Ficam em `/comandos`.
 *
 * ── Formato das entradas ─────────────────────────────────────────────────────
 * Mesmo espírito de `command_catalog.php` (array PHP, sem tabela de catálogo
 * no banco — só o VALOR lido/aplicado por câmera vai pro banco, em
 * `device_ia_config_state`, ver migration_v4.13.0.sql).
 *
 *   cmd          — verbo base do comando
 *   nome         — rótulo em PT-BR
 *   desc         — o que o comando faz
 *   modelos      — em quais modelos da linha JC ele é documentado
 *   template     — sempre true aqui (todo comando desta tela tem parâmetro)
 *   consulta     — forma nua que LÊ o valor; null quando não há
 *   consulta_ref — procedência da CONSULTA (não do comando de escrita):
 *                  'medido' (testada em câmera real) ou 'inferido' (mesmo
 *                  padrão de um comando MEDIDO no mesmo verbo/família, mas
 *                  este código/campo específico não foi testado individualmente)
 *   fonte        — planilha + linha/seção de onde veio
 *   procedencia  — 'planilha' (as 3 fontes acima) ou 'wiki' (fallback JC450/JC182)
 *   params[]     — cada um com 'p' (nome do placeholder), 'desc', 'format'
 *                  (a MÁSCARA — é o texto da tag de auxílio na tela) e 'default'
 *   exemplos[]   — pelo menos um comando pronto, da própria planilha
 *
 * ── `consulta` de `EVENTSET`/`EVENTALERT`: MEDIDA em 25/08/2026 contra a
 * Telecom (JC371, 865478070654829) — a hipótese inicial era que só
 * `EVENTSET#`/`EVENTALERT#` (verbo puro) fizessem sentido, por analogia com
 * a família `EVENTSET` de `command_catalog.php`. **Errada**: a câmera
 * recusou os dois ("Command was not recognized!"). A forma certa inclui o
 * CÓDIGO do evento — `EVENTSET,ALDW#`, `EVENTALERT,ADCA#` etc. — e devolve
 * só os valores daquele evento (`EVENTSET,ALDW#` → `EVENTSET,ALDW#,60`).
 * Testados ao vivo: `ALDW`, `AOSD`, `ADCA`, `AFVS` nos dois verbos (8
 * disparos, 8 respostas) — `consulta_ref: 'medido'`. Os outros 15 códigos de
 * cada verbo seguem o mesmo padrão `CMD,CÓDIGO#`, não testados
 * individualmente — `'inferido'`.
 *
 * ── Também medidos em 25/08/2026 (Telecom JC371 + `864993060429173`
 * JC400AD + `860112070347838` JC181): `DMSSP,ADAS#`/`DMSSP,DMS#` (a forma
 * bare `DMSSP#` também foi recusada — precisa da função) e
 * `ADAS,CALIBRATION#` (a forma antiga `ADAS#`, herdada de
 * `command_catalog.php`, também estava errada — mesma recusa por número de
 * parâmetros). `DMSVSP#`, `DMSSW#` (JC371 de 2 parâmetros E JC400AD de 1 —
 * são registros DIFERENTES que respondem ao mesmo verbo bare, medido nos
 * dois), `ADASSW#`, `DMS_SWITCH#`, `SPEED#` (JC181) — todos bare `CMD#`,
 * todos confirmados. `ADASSEP#`/`ADASSEN#` respondem de verdade mas exigem
 * `ADASSW` ligado primeiro ("Please Open Adas Switch" quando desligado) —
 * a FORMA está confirmada, a câmera testada só estava com ADAS desligado.
 * O resto da família `ADASxx`/`DMS_*` (G002–G015) segue o mesmo padrão bare
 * `CMD#` dos que FORAM testados — `'inferido'`, não `'medido'`.
 *
 * A tela "Configurações IA" tem um botão **Ler tudo (cadência)** que
 * dispara cada consulta, uma por vez — é o que produziu essas medições, e
 * continua sendo o mecanismo pra promover os `'inferido'` restantes pra
 * `'medido'` conforme forem testados em mais câmeras/modelos.
 *
 * Total (26/08/2026): 79 entradas (21 `medido`, 56 `inferido`, 2 sem forma de
 * consulta — os dois `GFENCE`, planilha não confirma se são consultáveis) —
 * JC371 (43 no campo `modelos`) + JC450 (18) + JC400AD (14) + JC182 (11) +
 * JC181 (1, `SPEED`). As 6 entradas do JC371 que o catálogo chegou a
 * atribuir também ao JC182 por analogia (ALDW/AHMW/ADCA/ACEA/ANDD/AFIF)
 * foram REMOVIDAS do JC182 em 26/08/2026 — teste real de campo mostrou que
 * o equipamento não tem câmera de IA/visão computacional. No lugar delas, o
 * JC182 ganhou uma seção própria de comandos de acelerômetro/GPS —
 * `EVENTSET,ACD`/`AVD`, `SENALM`, `COLLIDE`, `SPEEDCHECK`, `SWERVE`,
 * `FATIGUE`, `GFENCE` (circular e retangular) — trazidos para ESTA tela por
 * pedido explícito do dono do produto (26/08/2026), apesar de serem
 * acelerômetro/GPS e não visão computacional (ver "Fora de escopo, de
 * propósito" acima para a exceção documentada). O JC181 NÃO ganhou os
 * mesmos: o pedido foi específico do JC182, mesmo os dois compartilhando a
 * planilha-fonte (`docs/JC181_Command_List_V1.0.7_20250811.xlsx`) — uma
 * primeira versão (v4.13.13) tinha replicado por engano para o JC181
 * também, corrigido em v4.13.14. São duplicatas de propósito das mesmas
 * entradas em `command_catalog.php` (que continua com JC181 nelas, por ser
 * a fonte legítima/original desses comandos naquela tela).
 */

return [

    // ═══════════════════════════════════════════════════════════════════════
    // JC371 — velocidade (D001/D002) e velocidade virtual de teste (D015)
    // ═══════════════════════════════════════════════════════════════════════

    // v4.13.12 — JC182 confirmado pelo dono do produto em teste real de campo
    // (26/08/2026): é um dos únicos 3 códigos EVENTSET que o JC182 responde
    // (junto de ACD/colisão e AVD/vibração, ambos por acelerômetro — ver
    // includes/command_catalog.php, fora de escopo desta tela por política já
    // documentada acima). Os outros 6 códigos ADAS/DMS que este catálogo
    // atribuía ao JC182 por analogia com o JC371 (ALDW/AHMW/ADCA/ACEA/ANDD/
    // AFIF) foram REMOVIDOS do `modelos` desses códigos nesta mesma versão:
    // o JC182 não tem câmera de IA/visão computacional, só este evento de
    // velocidade (que não depende de visão) — mesma característica do JC181.
    // Sem confirmação para o par EVENTALERT,AOSD neste modelo — por isso ele
    // continua só em JC371 abaixo; o card do JC182 aparece "solo" na tela.
    'EVENTSET,AOSD,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — excesso de velocidade',
        'desc' => 'Define o limite de velocidade e por quanto tempo acima dele gera o evento de excesso de velocidade.',
        'modelos' => ['JC371', 'JC182'], 'template' => true,
        'consulta' => 'EVENTSET,AOSD#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha D001; JC182 confirmado em campo 26/08/2026', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Limite de velocidade', 'format' => 'OFF / 1–255 (km/h)', 'default' => '4'],
            ['p' => 'P2', 'desc' => 'Duração acima do limite para gerar o evento', 'format' => '0–600 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,AOSD,80,10#', 'desc' => 'dispara acima de 80 km/h mantidos por 10 s.']],
    ],
    'EVENTALERT,AOSD,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — excesso de velocidade',
        'desc' => 'Define o intervalo de envio à plataforma e entre avisos de voz para o evento de excesso de velocidade.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,AOSD#', 'consulta_ref' => 'medido',
        // ⚠️ O cabeçalho da linha D002 usa o código "ADSD", mas os DOIS exemplos
        // da própria planilha usam "AOSD" (o mesmo código do EVENTSET acima,
        // da linha D001). Typo da planilha do fabricante — segui o exemplo, não
        // o cabeçalho, porque é o que a câmera de fato aceita nos casos medidos
        // do resto do catálogo (ver command_catalog.php sobre "doc mente").
        'fonte' => 'JC371 Command List V1.0.1, linha D002 (cabeçalho diz "ADSD", exemplo usa "AOSD")',
        'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio à plataforma', 'format' => '0/OFF = não reportar / 1 = imediato / 2–64800 (segundos)', 'default' => '120'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0/OFF = sem voz / 1 = imediato / 2–64800 (segundos)', 'default' => '30'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,AOSD,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],
    'DMSVSP,P1#' => [
        'cmd' => 'DMSVSP', 'nome' => 'Velocidade virtual para simulação',
        'desc' => 'Simula uma velocidade para testar o ADAS/DMS em bancada, sem o veículo em movimento.',
        'modelos' => ['JC371', 'JC450'], 'template' => true,
        'consulta' => 'DMSVSP#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha D015', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Velocidade simulada', 'format' => '0–120 (km/h)', 'default' => '—'],
        ],
        'exemplos' => [['cmd' => 'DMSVSP,60#', 'desc' => 'simula 60 km/h.']],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // JC371 — ativação geral de IA e calibração de veículo
    // ═══════════════════════════════════════════════════════════════════════

    // v4.13.12 — JC450 REMOVIDO deste comando (estava aqui por analogia com o
    // JC371, nunca confirmado): a planilha própria do JC450
    // ("JC450 series command list-EN V2.1.1.xlsx", linha G004) documenta
    // `DMSSP,A,B` com só 2 campos (função + velocidade) — sem canal nem área.
    // Ver a entrada específica do JC450 na seção "JC450" mais abaixo.
    'DMSSP,P1,P2,P3,P4#' => [
        'cmd' => 'DMSSP', 'nome' => 'Ativação de IA (velocidade/canal/área)',
        'desc' => 'Define a velocidade mínima de ativação, o canal de vídeo e a área de detecção do ADAS ou do DMS.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'DMSSP,ADAS#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha E003', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função', 'format' => 'ADAS / DMS', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Velocidade de ativação', 'format' => '10–120 (km/h)', 'default' => '60 (ADAS) / 30 (DMS)'],
            ['p' => 'P3', 'desc' => 'Canal da câmera', 'format' => '1 / 2 / 3', 'default' => '1 (ADAS) / 2 (DMS)'],
            ['p' => 'P4', 'desc' => 'Área de detecção', 'format' => '0=Imagem completa / 1=Esquerdo ½ / 2=Meio ½ / 3=Direito ½ / 4=Esquerdo ⅔ / 5=Meio ⅔ / 6=Direito ⅔', 'default' => '0 (ADAS) / 3 (DMS)'],
        ],
        'exemplos' => [
            ['cmd' => 'DMSSP,ADAS,60,1,0#', 'desc' => 'ADAS a partir de 60 km/h, canal 1, imagem completa.'],
            ['cmd' => 'DMSSP,DMS,60,2,3#', 'desc' => 'DMS a partir de 60 km/h, canal 2, área direita ½.'],
        ],
    ],
    // ⚠️ Não consta na planilha JC371 (aba "Lista de Comandos") apesar de ser
    // documentado para JC371/JC400AD — fallback do catálogo antigo (wiki),
    // mesma disciplina do JC450/JC182 acima.
    // v4.13.12 — JC450 adicionado: a planilha própria do JC450 (linha G011)
    // confirma `DMSSW,A,B` com a MESMA forma de 2 parâmetros (A=1 ADAS/2 DMS,
    // B=0 desativa/1 ativa) — só que, EXCLUSIVO deste modelo, B também aceita
    // 2 quando A=2 (DMS): "JC171 (available when A=2 DMS)", um modo especial
    // não documentado na planilha do JC371.
    'DMSSW,P1,P2#' => [
        'cmd' => 'DMSSW', 'nome' => 'Chave de funções de IA',
        'desc' => 'Ativa ou desativa uma função de IA específica (ADAS, DMS ou reconhecimento facial). Qualquer recurso de IA precisa estar habilitado aqui antes de ser usado.',
        'modelos' => ['JC371', 'JC400AD', 'JC450'], 'template' => true,
        'consulta' => 'DMSSW#', 'consulta_ref' => 'medido', 'fonte' => 'command_catalog.php (wiki) — ausente da planilha JC371; confirmado na planilha JC450 V2.1.1, linha G011', 'procedencia' => 'wiki',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função de IA', 'format' => '1=ADAS / 2=DMS / 3=FACE (reconhecimento facial)', 'default' => '1 e 2 ativos / 3 inativo'],
            ['p' => 'P2', 'desc' => 'Estado', 'format' => '0=Desativar / 1=Ativar / 2=modo "JC171" — só no JC450 e só quando P1=2 (DMS)', 'default' => 'conforme a função'],
        ],
        'exemplos' => [
            ['cmd' => 'DMSSW,1,0#', 'desc' => 'desativa o ADAS.'],
            ['cmd' => 'DMSSW,3,1#', 'desc' => 'ativa o reconhecimento facial.'],
        ],
    ],
    // v4.13.12 — JC450 REMOVIDO deste comando (estava aqui por analogia com o
    // JC371, nunca confirmado): a planilha própria do JC450 (linha G001)
    // documenta `ADAS,CALIBRATION,A,B,C,D,E` com 5 MEDIDAS EM MILÍMETROS
    // (altura, distâncias da lente ao para-choque/rodas/eixo) — nada a ver
    // com a letra de tipo de veículo do JC371. JC400AD mantido: a consulta
    // bare foi medida em câmera real nesta mesma sessão (25/08/2026, ver
    // cabeçalho do arquivo). Ver a entrada específica do JC450 mais abaixo.
    'ADAS,CALIBRATION,P1#' => [
        'cmd' => 'ADAS', 'nome' => 'Calibração — perfil do veículo',
        'desc' => 'Define os parâmetros de instalação da câmera conforme o tipo/porte do veículo.',
        'modelos' => ['JC371', 'JC400AD'], 'template' => true,
        'consulta' => 'ADAS,CALIBRATION#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha E005', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Tipo de veículo', 'format' => 'A=Carro de passeio / B=SUV ou caminhonete pequena / C=Caminhão pequeno (baú curto) / D=Caminhão médio (baú médio) / E=Caminhão grande (baú longo) / F=Caminhão médio (cabine estendida) / G=Caminhão grande (cabine estendida)', 'default' => 'A'],
        ],
        'exemplos' => [['cmd' => 'ADAS,CALIBRATION,A#', 'desc' => 'calibra como carro de passeio.']],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // JC371 — pares EVENTSET (sensibilidade) / EVENTALERT (alerta) por evento
    // ═══════════════════════════════════════════════════════════════════════

    // ADAS — Saída de faixa (ALDW)
    'EVENTSET,ALDW,P1#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — saída de faixa (ADAS)',
        'desc' => 'Distância de cruzamento das rodas que dispara o evento de saída de faixa. Requer DMSSP com ADAS ativo antes.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ALDW#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha E006', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Sensibilidade (distância de cruzamento das rodas)', 'format' => 'OFF / 10–100 (cm)', 'default' => '60']],
        'exemplos' => [['cmd' => 'EVENTSET,ALDW,60#', 'desc' => 'dispara a 60 cm de cruzamento.']],
    ],
    'EVENTALERT,ALDW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — saída de faixa (ADAS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de saída de faixa.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ALDW#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha E007', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '120'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ALDW,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // ADAS — Distância insegura / vizinhança de veículo (AHMW)
    'EVENTSET,AHMW,P1#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — distância insegura (ADAS)',
        'desc' => 'Limiar de tempo de risco de colisão por proximidade do veículo à frente. Requer DMSSP com ADAS ativo antes.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,AHMW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E008', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Sensibilidade (limiar de tempo de risco)', 'format' => 'OFF / 500–10000 (ms)', 'default' => '1200']],
        'exemplos' => [['cmd' => 'EVENTSET,AHMW,1200#', 'desc' => 'limiar de 1200 ms.']],
    ],
    'EVENTALERT,AHMW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — distância insegura (ADAS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de distância insegura.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,AHMW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E009', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '120'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,AHMW,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // ADAS — Colisão frontal (AFCW)
    'EVENTSET,AFCW,P1#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — colisão frontal (ADAS)',
        'desc' => 'Limiar de tempo de risco de colisão frontal direta. Requer DMSSP com ADAS ativo antes.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,AFCW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E010', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Sensibilidade (limiar de tempo de risco)', 'format' => 'OFF / 500–10000 (ms)', 'default' => '2500']],
        'exemplos' => [['cmd' => 'EVENTSET,AFCW,2500#', 'desc' => 'limiar de 2500 ms.']],
    ],
    'EVENTALERT,AFCW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — colisão frontal (ADAS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de colisão frontal.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,AFCW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E011', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '120'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0/OFF=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,AFCW,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // ADAS — Colisão com pedestre (APCW)
    'EVENTSET,APCW,P1#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — colisão com pedestre (ADAS)',
        'desc' => 'Ativa/define a sensibilidade de disparo do risco de colisão com pedestre. Requer DMSSP com ADAS ativo antes.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,APCW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E012', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 500–10000 (ms)', 'default' => '5000']],
        'exemplos' => [['cmd' => 'EVENTSET,APCW,5000#', 'desc' => 'limiar de 5000 ms.']],
    ],
    'EVENTALERT,APCW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — colisão com pedestre (ADAS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de risco de colisão com pedestre.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,APCW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E013', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0/OFF=não reportar / 2–64800 (segundos)', 'default' => '120'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '10'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,APCW,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // ADAS — Partida do veículo à frente (AFVS)
    'EVENTSET,AFVS,P1,P2,P3,P4#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — partida do veículo à frente (ADAS)',
        'desc' => 'Distância e tempo parado do veículo à frente que disparam o aviso de partida.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,AFVS#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha E014', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 0–64800 (ms)', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Tempo de partida (startup time)', 'format' => '1–600 (segundos)', 'default' => '60'],
            ['p' => 'P3', 'desc' => 'Distância para acionar a detecção', 'format' => '1–64800 (mm)', 'default' => '600'],
            ['p' => 'P4', 'desc' => 'Tempo de permanência do veículo frontal parado', 'format' => '1–600 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,AFVS,6000,30,3000,10#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,AFVS,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — partida do veículo à frente (ADAS)',
        'desc' => 'Alerta de plataforma e voz quando o veículo à frente parte após parada.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,AFVS#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha E015', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Alerta na plataforma (2º campo, fixo pela planilha)', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,AFVS,0,0,60#', 'desc' => 'exemplo da planilha.']],
    ],

    // DMS — Anomalia de calibração (ADCA)
    'EVENTSET,ADCA,P1#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — anomalia de calibração (DMS)',
        'desc' => 'Tempo limite sem alinhamento correto do DMS que dispara o evento de anomalia de calibração.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ADCA#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha E016', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Tempo limite para calibração', 'format' => 'OFF / 0–64800 (segundos)', 'default' => '60']],
        'exemplos' => [['cmd' => 'EVENTSET,ADCA,60#', 'desc' => 'limite de 60 s.']],
    ],
    'EVENTALERT,ADCA,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — anomalia de calibração (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para anomalia de calibração do DMS.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ADCA#', 'consulta_ref' => 'medido', 'fonte' => 'JC371 Command List V1.0.1, linha E017', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 1–64800 (segundos)', 'default' => '—'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 1–64800 (segundos)', 'default' => '—'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ADCA,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Fadiga / olhos fechados (ACEA)
    'EVENTSET,ACEA,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — fadiga/olhos fechados (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima de olhos fechados para gerar o evento de fadiga.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ACEA#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E018', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => '2'],
            ['p' => 'P2', 'desc' => 'Duração mínima do fechamento dos olhos', 'format' => '1–255 (segundos)', 'default' => '2.5'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ACEA,2,2.5#', 'desc' => 'sensibilidade média, 2,5 s.']],
    ],
    'EVENTALERT,ACEA,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — fadiga/olhos fechados (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de fadiga do motorista.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ACEA#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E020', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '120'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ACEA,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],
    // DMS — Fechamento de olhos SUSTENTADO (ASCE): só tem forma de ALERTA na
    // planilha (não existe EVENTSET,ASCE próprio) — usa a mesma sensibilidade
    // configurada em ACEA.
    'EVENTALERT,ASCE,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — olhos fechados sustentados (DMS)',
        'desc' => 'Alerta de plataforma e voz para fechamento de olhos sustentado (usa a sensibilidade de ACEA).',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ASCE#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E021', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Alerta na plataforma (2º campo, fixo pela planilha)', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ASCE,0,0,60#', 'desc' => 'voz a cada 60 s.']],
    ],

    // DMS — Distração (ADW) e Bocejo (ADDW). ⚠️ A planilha nomeia a linha E023
    // "Ativar alerta de voz de distração" mas o COMANDO da linha é
    // EVENTALERT,ADDW (código de BOCEJO) — não existe EVENTALERT,ADW
    // documentado. Transcrito exatamente como a planilha traz; ver nota no
    // 'desc' de cada entrada.
    'EVENTSET,ADW,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — distração (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima de distração para gerar o evento.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ADW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E022', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => '2'],
            ['p' => 'P2', 'desc' => 'Duração mínima da distração', 'format' => '1–255 (segundos)', 'default' => '3'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ADW,2,2.5#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTSET,ADDW,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — bocejo (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima de boca aberta para gerar o evento de bocejo.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ADDW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E019', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => '2'],
            ['p' => 'P2', 'desc' => 'Duração mínima de boca aberta', 'format' => '1–255 (segundos)', 'default' => '2.5'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ADDW,2,2.5#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,ADDW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — bocejo/distração (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz — a planilha rotula esta linha como "distração" mas o código do comando (ADDW) é o de BOCEJO; não há EVENTALERT,ADW documentado à parte.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ADDW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E023', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '120'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ADDW,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Fumar (ASW)
    'EVENTSET,ASW,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — fumo (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima de fumo para gerar o evento.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ASW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E024', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => '2'],
            ['p' => 'P2', 'desc' => 'Duração mínima de fumo', 'format' => '1–255 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ASW,2,2.5#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,ASW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — fumo (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de fumo.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ASW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E025', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '120'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ASW,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Uso de telefone (ACPW)
    'EVENTSET,ACPW,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — uso de telefone (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima de uso do telefone para gerar o evento.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ACPW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E026', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => '2'],
            ['p' => 'P2', 'desc' => 'Duração mínima de uso do telefone', 'format' => '1–255 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ACPW,2,2.5#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,ACPW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — uso de telefone (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de uso do telefone.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ACPW#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E027', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '120'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ACPW,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Obstrução de lente (AMS)
    'EVENTSET,AMS,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — obstrução de lente (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima de obstrução da lente para gerar o evento.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,AMS#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E028', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => '2'],
            ['p' => 'P2', 'desc' => 'Duração mínima da obstrução', 'format' => '1–255 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,AMS,2,30#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,AMS,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — obstrução de lente (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de obstrução de lente.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,AMS#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E029', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '14400'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '14400'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,AMS,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Óculos de sol (ASS)
    'EVENTSET,ASS,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — óculos de sol (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima de uso de óculos escuros para gerar o evento.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ASS#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E030', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Duração mínima com óculos de sol', 'format' => '1–255 (segundos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ASS,2,30#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,ASS,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — óculos de sol (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de óculos de sol detectado.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ASS#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E031', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '0'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '0'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ASS,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Beber/comer (ADA)
    'EVENTSET,ADA,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — bebida/comida (DMS)',
        'desc' => 'Nível de sensibilidade para gerar o evento de motorista bebendo/comendo.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ADA#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E032', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Intervalo entre avisos de voz', 'format' => '1–255 (segundos)', 'default' => '5'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ADA,2,5#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,ADA,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — bebida/comida (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de bebida/comida.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ADA#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E033', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '0'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '0'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ADA,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Perda de rosto (ANDD)
    'EVENTSET,ANDD,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — rosto não detectado (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima sem detecção de rosto para gerar o evento.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ANDD#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E034', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixa / 2=Média / 3=Alta', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Duração mínima sem rosto detectado', 'format' => '1–255 (segundos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ANDD,2,60#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,ANDD,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — rosto não detectado (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para perda de detecção facial.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ANDD#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E035', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '0'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '0'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ANDD,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Reconhecimento facial: sucesso (AFIS) / falha (AFIF)
    // v4.13.12 — JC450 adicionado: a planilha própria do JC450 (linha G015)
    // documenta a MESMA forma de 3 parâmetros (similaridade/duração/
    // intervalo) — único ponto de convergência exata entre as duas planilhas
    // nesta seção. ⚠️ O campo de intervalo (P3) muda de UNIDADE entre
    // modelos: no JC371 é segundos, no JC450 a planilha diz "unit is
    // minutes" — mesmo texto de comando, semântica diferente por modelo.
    'EVENTSET,AFIF,P1,P2,P3#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — reconhecimento facial (DMS)',
        'desc' => 'Sensibilidade de similaridade, duração e intervalo entre tentativas de reconhecimento facial.',
        'modelos' => ['JC371', 'JC450'], 'template' => true,
        'consulta' => 'EVENTSET,AFIF#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E036; JC450 series command list V2.1.1, linha G015', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade (similaridade)', 'format' => 'JC371: OFF / 1–100 (recomendado 40–60) — JC450: 0–100, 0 = desativado', 'default' => 'OFF / 0'],
            ['p' => 'P2', 'desc' => 'Duração para reconhecimento malsucedido', 'format' => '1–255 (segundos)', 'default' => '180'],
            ['p' => 'P3', 'desc' => 'Intervalo entre tentativas — ⚠️ unidade diferente por modelo', 'format' => 'JC371: 0=detecta só uma vez ao ligar / 1–255 (segundos) — JC450: 1–255 (minutos)', 'default' => 'JC371: 0 (s) / JC450: 1 (min)'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,AFIF,50,30,10#', 'desc' => 'exemplo da planilha do JC371.']],
    ],
    'EVENTALERT,AFIS,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — reconhecimento facial com sucesso (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz quando o reconhecimento facial dá certo.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,AFIS#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E037', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '60'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,AFIS,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],
    'EVENTALERT,AFIF,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — falha no reconhecimento facial (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz quando o reconhecimento facial falha.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,AFIF#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E038', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '60'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,AFIF,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Cinto: apertado (AWSB) / sem cinto (ANWSB)
    'EVENTSET,AWSB,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — cinto de segurança apertado (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima para confirmar cinto apertado.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,AWSB#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E039', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Duração mínima para gerar o evento', 'format' => '1–255 (segundos)', 'default' => '1'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,AWSB,2,5#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,AWSB,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — cinto de segurança apertado (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz quando o cinto está apertado.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,AWSB#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E040', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '60'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '0'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,AWSB,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],
    'EVENTSET,ANWSB,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — sem cinto de segurança (DMS)',
        'desc' => 'Nível de sensibilidade e duração mínima para confirmar ausência de cinto.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTSET,ANWSB#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E041', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1=Baixo / 2=Médio / 3=Alto', 'default' => '1'],
            ['p' => 'P2', 'desc' => 'Duração mínima para gerar o evento', 'format' => '1–255 (segundos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ANWSB,2,30#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,ANWSB,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — sem cinto de segurança (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz quando o motorista está sem cinto.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => 'EVENTALERT,ANWSB#', 'consulta_ref' => 'inferido', 'fonte' => 'JC371 Command List V1.0.1, linha E042', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '3600'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '600'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ANWSB,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // JC400D / JC400AD / JC261 / JC261P — DMS
    // ═══════════════════════════════════════════════════════════════════════

    'DMSSW,P1#' => [
        'cmd' => 'DMSSW', 'nome' => 'Modo da subcâmera',
        'desc' => 'Define o modo da subcâmera do JC261. Reinicia o equipamento 10 s após aplicar.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'DMSSW#', 'consulta_ref' => 'medido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G001', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Modo', 'format' => '0=Versão AHD / 3=Versão JC170', 'default' => '—']],
        'exemplos' => [['cmd' => 'DMSSW,3', 'desc' => 'muda para modo JC170.']],
    ],
    'DMS_SWITCH,P1,P2,P3#' => [
        'cmd' => 'DMS_SWITCH', 'nome' => 'Ativação, sensibilidade e velocidade do DMS',
        'desc' => 'Liga/desliga o DMS, define a sensibilidade geral e a velocidade a partir da qual o alinhamento facial começa.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'DMS_SWITCH#', 'consulta_ref' => 'medido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G002', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Ativação do DMS', 'format' => '0=Desligado / 1=Ligado', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Sensibilidade', 'format' => '1=Normal / 2=Agressiva', 'default' => '—'],
            ['p' => 'P3', 'desc' => 'Velocidade para começar o alinhamento facial', 'format' => '15 / 30 / 60 / 90 (km/h)', 'default' => '—'],
        ],
        'exemplos' => [['cmd' => 'DMS_SWITCH,1,1,60', 'desc' => 'liga, sensibilidade normal, alinha a partir de 60 km/h.']],
    ],
    'DMS_VOICE_CUSTOM,P1,P2,P3,P4,P5,P6#' => [
        'cmd' => 'DMS_VOICE_CUSTOM', 'nome' => 'Filtro de repetição de voz por evento',
        'desc' => 'Período mínimo entre avisos de voz repetidos, um valor por tipo de evento de DMS.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'DMS_VOICE_CUSTOM#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G003', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Olhos fechados', 'format' => '0=desliga sempre / 10 / 30 / 60 (segundos)', 'default' => '5 (JC400D) / 5 (JC261)'],
            ['p' => 'P2', 'desc' => 'Bocejo', 'format' => '0=desliga sempre / 10 / 30 / 60 (segundos)', 'default' => '5 (JC400D) / 5 (JC261)'],
            ['p' => 'P3', 'desc' => 'Distração/cabeça baixa', 'format' => '0=desliga sempre / 10 / 30 / 60 (segundos)', 'default' => '5 (JC400D) / 5 (JC261)'],
            ['p' => 'P4', 'desc' => 'Fumando', 'format' => '0=desliga sempre / 10 / 30 / 60 (segundos)', 'default' => '5 (JC400D) / 5 (JC261)'],
            ['p' => 'P5', 'desc' => 'Ao telefone', 'format' => '0=desliga sempre / 10 / 30 / 60 (segundos)', 'default' => '5 (JC400D) / 5 (JC261)'],
            ['p' => 'P6', 'desc' => 'Rosto não detectado', 'format' => '0=desliga sempre / 10 / 30 / 60 (segundos)', 'default' => '5 (JC400D) / 60 (JC261)'],
        ],
        'exemplos' => [['cmd' => 'DMS_VOICE_CUSTOM,10,10,10,10,10,10', 'desc' => 'exemplo da planilha.']],
    ],
    'DMS_ALERT_CUSTOM,P1,P2,P3,P4,P5,P6#' => [
        'cmd' => 'DMS_ALERT_CUSTOM', 'nome' => 'Filtro de repetição de alerta por evento',
        'desc' => 'Período mínimo entre envios repetidos à plataforma, um valor por tipo de evento de DMS.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'DMS_ALERT_CUSTOM#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G004', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Olhos fechados', 'format' => '0=nunca envia / 180 / 600 / 1800 (segundos)', 'default' => '120 (JC400D) / 120 (JC261)'],
            ['p' => 'P2', 'desc' => 'Bocejo', 'format' => '0=nunca envia / 180 / 600 / 1800 (segundos)', 'default' => '120 (JC400D) / 120 (JC261)'],
            ['p' => 'P3', 'desc' => 'Distração/cabeça baixa', 'format' => '0=nunca envia / 180 / 600 / 1800 (segundos)', 'default' => '120 (JC400D) / 120 (JC261)'],
            ['p' => 'P4', 'desc' => 'Fumando', 'format' => '0=nunca envia / 180 / 600 / 1800 (segundos)', 'default' => '0 (JC400D) / 120 (JC261)'],
            ['p' => 'P5', 'desc' => 'Ao telefone', 'format' => '0=nunca envia / 180 / 600 / 1800 (segundos)', 'default' => '0 (JC400D) / 120 (JC261)'],
            ['p' => 'P6', 'desc' => 'Rosto não detectado', 'format' => '0=nunca envia / 180 / 600 / 1800 (segundos)', 'default' => '180 (JC400D) / 120 (JC261)'],
        ],
        'exemplos' => [['cmd' => 'DMS_ALERT_CUSTOM,180,180,180,180,180,180', 'desc' => 'exemplo da planilha.']],
    ],
    'DMS_VIRTUAL_SPEED,P1#' => [
        'cmd' => 'DMS_VIRTUAL_SPEED', 'nome' => 'Velocidade virtual para simulação',
        'desc' => 'Simula uma velocidade para testar o ADAS/DMS em bancada. Fica inválido após o próximo ACC OFF.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'DMS_VIRTUAL_SPEED#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G005', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Velocidade simulada', 'format' => '0–120 (km/h)', 'default' => '—']],
        'exemplos' => [['cmd' => 'DMS_VIRTUAL_SPEED,30', 'desc' => 'simula 30 km/h.']],
    ],
    'DMS_CONTINUITY,P1,P2,P3,P4,P5,P6#' => [
        'cmd' => 'DMS_CONTINUITY', 'nome' => 'Duração de reconhecimento contínuo por evento',
        'desc' => 'Por quanto tempo o comportamento precisa persistir antes de disparar o evento, um valor por tipo.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'DMS_CONTINUITY#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G006', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Olhos fechados', 'format' => '1–10 (segundos)', 'default' => '3 (JC400D) / 2 (JC261)'],
            ['p' => 'P2', 'desc' => 'Bocejo', 'format' => '1–10 (segundos)', 'default' => '3 (JC400D) / 2 (JC261)'],
            ['p' => 'P3', 'desc' => 'Distração/cabeça baixa', 'format' => '1–10 (segundos)', 'default' => '3 (JC400D) / 2 (JC261)'],
            ['p' => 'P4', 'desc' => 'Fumando', 'format' => '1–10 (segundos)', 'default' => '3 (JC400D) / 3 (JC261)'],
            ['p' => 'P5', 'desc' => 'Ao telefone', 'format' => '1–10 (segundos)', 'default' => '3 (JC400D) / 3 (JC261)'],
            ['p' => 'P6', 'desc' => 'Rosto não detectado', 'format' => '1–10 (segundos)', 'default' => '3 (JC400D) / 10 (JC261)'],
        ],
        'exemplos' => [['cmd' => 'DMS_CONTINUITY,3,3,3,3,3,3', 'desc' => 'exemplo da planilha.']],
    ],
    'DMS_CALIB_ABNORMAL,P1,P2,P3#' => [
        'cmd' => 'DMS_CALIB_ABNORMAL', 'nome' => 'Alerta de anomalia de alinhamento',
        'desc' => 'Quantas anomalias de alinhamento até gerar alerta, e se avisa por som/plataforma.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'DMS_CALIB_ABNORMAL#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G007', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Anomalias até gerar alerta', 'format' => '0=desativado / 1–10', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Notificar por som', 'format' => '0=não / 1=sim', 'default' => '—'],
            ['p' => 'P3', 'desc' => 'Enviar à plataforma', 'format' => '0=não / 1=sim', 'default' => '—'],
        ],
        'exemplos' => [['cmd' => 'DMS_CALIB_ABNORMAL,3,1,0', 'desc' => 'exemplo da planilha.']],
    ],
    'DMS_SECOND_EVENT,P1,P2,P3,P4#' => [
        'cmd' => 'DMS_SECOND_EVENT', 'nome' => 'Escalonamento para evento de nível 2',
        'desc' => 'Quantas ocorrências consecutivas do mesmo evento, numa janela de tempo, viram um alerta sonoro de nível 2.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'DMS_SECOND_EVENT#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G008', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Tipo de evento', 'format' => '1=Distração / 2=Olhos fechados / 3=Bocejo / 4=Ao telefone / 5=Fumando / 6=Rosto não detectado', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Ocorrências consecutivas', 'format' => '0=desativado / 1–10', 'default' => '—'],
            ['p' => 'P3', 'desc' => 'Janela de tempo para contar', 'format' => '1–180 (segundos)', 'default' => '—'],
            ['p' => 'P4', 'desc' => 'Duração do alarme sonoro', 'format' => '0=desativado / 1–10 (segundos)', 'default' => '—'],
        ],
        'exemplos' => [['cmd' => 'DMS_SECOND_EVENT,2,5,60,3', 'desc' => 'exemplo da planilha.']],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // JC400AD / JC261 / JC261P — ADAS
    // ═══════════════════════════════════════════════════════════════════════

    'ADASSW,P1#' => [
        'cmd' => 'ADASSW', 'nome' => 'Ativação geral do ADAS',
        'desc' => 'Liga/desliga o ADAS. Reinicia o equipamento 10 s após aplicar.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'ADASSW#', 'consulta_ref' => 'medido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G009', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Ativação', 'format' => '0=Desligado / 1=Ligado', 'default' => '—']],
        'exemplos' => [['cmd' => 'ADASSW,1', 'desc' => 'liga o ADAS.']],
    ],
    'ADASSEP,P1,P2#' => [
        'cmd' => 'ADASSEP', 'nome' => 'Ativação por função de ADAS',
        'desc' => 'Liga/desliga individualmente cada função de ADAS. Requer ADASSW ligado antes.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'ADASSEP#', 'consulta_ref' => 'medido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G010', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função', 'format' => '1=FCW (colisão frontal) / 2=HMW (veículo muito próximo) / 3=LDW (saída de faixa)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Estado', 'format' => '0=Desligado / 1=Ligado', 'default' => 'FCW:1 / HMW:1 / LDW:1'],
        ],
        'exemplos' => [['cmd' => 'ADASSEP,2,1', 'desc' => 'liga HMW.']],
    ],
    'ADASPI,P1,P2#' => [
        'cmd' => 'ADASPI', 'nome' => 'Filtro de repetição de alerta por função de ADAS',
        'desc' => 'Período mínimo entre envios repetidos à plataforma para o mesmo tipo de evento de ADAS.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'ADASPI#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G011', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função', 'format' => '1=FCW (colisão frontal) / 2=HMW (veículo muito próximo) / 3=LDW (saída de faixa)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Período', 'format' => '0–3600 (segundos)', 'default' => 'FCW:60 / HMW:60 / LDW:60'],
        ],
        'exemplos' => [['cmd' => 'ADASPI,2,50', 'desc' => 'HMW a cada 50 s.']],
    ],
    'ADASVI,P1,P2#' => [
        'cmd' => 'ADASVI', 'nome' => 'Filtro de repetição de voz por função de ADAS',
        'desc' => 'Período mínimo entre avisos de voz repetidos para o mesmo tipo de evento de ADAS.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'ADASVI#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G012', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função', 'format' => '1=FCW (colisão frontal) / 2=HMW (veículo muito próximo) / 3=LDW (saída de faixa)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Período', 'format' => '0–3600 (segundos)', 'default' => 'FCW:60 / HMW:60 / LDW:60'],
        ],
        'exemplos' => [['cmd' => 'ADASVI,2,50', 'desc' => 'voz de HMW a cada 50 s.']],
    ],
    'ADASSP,P1,P2#' => [
        'cmd' => 'ADASSP', 'nome' => 'Velocidade mínima por função de ADAS',
        'desc' => 'Velocidade a partir da qual cada função de ADAS pode disparar.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'ADASSP#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G013', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função', 'format' => '1=FCW+HMW (colisão frontal / veículo próximo) / 2=LDW (saída de faixa)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Velocidade mínima', 'format' => 'km/h', 'default' => 'FCW:30 / HMW:30 / LDW:60'],
        ],
        'exemplos' => [
            ['cmd' => 'ADASSP,1,60', 'desc' => 'FCW/HMW a partir de 60 km/h.'],
            ['cmd' => 'ADASSP,2,60', 'desc' => 'LDW a partir de 60 km/h.'],
        ],
    ],
    // v4.13.12 — JC450 adicionado: a planilha própria do JC450 (linha G008)
    // documenta o mesmo comando de 3 campos, mesma numeração de função (1/2/3),
    // mas o SIGNIFICADO de P3 é diferente por modelo — no JC400AD é um valor
    // fixo (1) só preenchido quando P1=1; no JC450 é um ENUM real de 3 níveis
    // (baixo/médio/alto), também só quando P1=1. Documentado nos dois abaixo.
    'ADASSEN,P1,P2,P3#' => [
        'cmd' => 'ADASSEN', 'nome' => 'Sensibilidade de disparo por função de ADAS',
        'desc' => 'Sensibilidade específica de cada função de ADAS — o significado de P2/P3 muda conforme a função (P1) e, em parte, conforme o modelo.',
        'modelos' => ['JC400AD', 'JC450'], 'template' => true,
        'consulta' => 'ADASSEN#', 'consulta_ref' => 'medido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G014; JC450 series command list V2.1.1, linha G008', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função', 'format' => '1=LDW (saída de faixa) / 2=FCW (colisão frontal) / 3=HMW (veículo muito próximo)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Sensibilidade (significado depende de P1)', 'format' => 'P1=1: −0,3 a 0,6 (padrão −0,1; negativo=antes da faixa, positivo=depois) / P1=2 ou 3: 0–10 s, tempo até possível colisão (padrão 1,5 s para FCW, 1,0 s para HMW)', 'default' => 'LDW: −0,1 / FCW: 1,5 / HMW: 1,0'],
            ['p' => 'P3', 'desc' => 'Auxiliar — só usado quando P1=1, e o significado muda por modelo', 'format' => 'JC400AD: 1 (fixo) — JC450: 0=baixo / 1=médio / 2=alto. Em ambos, não preencher quando P1=2 ou 3', 'default' => '—'],
        ],
        'exemplos' => [
            ['cmd' => 'ADASSEN,1,-0.2,1', 'desc' => 'LDW, sensibilidade −0,2 (exemplo JC400AD).'],
            ['cmd' => 'ADASSEN,1,-0.111,2', 'desc' => 'LDW, sensibilidade −0,111, nível alto (exemplo JC450).'],
            ['cmd' => 'ADASSEN,2,2.0', 'desc' => 'FCW, 2,0 s.'],
            ['cmd' => 'ADASSEN,3,2.5', 'desc' => 'HMW, 2,5 s.'],
        ],
    ],
    'ADASVSP,P1#' => [
        'cmd' => 'ADASVSP', 'nome' => 'Velocidade virtual do ADAS para simulação',
        'desc' => 'Simula uma velocidade para testar o ADAS em bancada.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => 'ADASVSP#', 'consulta_ref' => 'inferido', 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G015', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Velocidade simulada', 'format' => '10–120 (km/h)', 'default' => '—']],
        'exemplos' => [['cmd' => 'ADASVSP,60', 'desc' => 'simula 60 km/h.']],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // JC450 — ainda sem equipamento real instalado (26/08/2026). Tela deixada
    // pronta a pedido do dono do produto: comandos de IA "semelhantes em
    // funcionalidade aos do JC371", mas com sintaxe PRÓPRIA — o JC450 tem
    // planilha oficial dedicada, diferente da do JC371/JC400AD.
    //
    // Fonte: `docs/JC450 series command list-EN V2.1.1.xlsx`, aba "AI
    // Features" (linhas G001–G021) + D004 (velocidade). A wiki
    // (`Configurações-JC450`) é a mesma SPA em JS que o WebFetch não consegue
    // renderizar (confirmado 26/08/2026, mesma limitação já documentada no
    // cabeçalho deste arquivo para JC450/JC182) — toda a cobertura abaixo vem
    // exclusivamente da planilha.
    //
    // 🔴 NADA aqui foi testado contra hardware real — `consulta_ref` é
    // sempre 'inferido' (nunca 'medido') nesta seção inteira, e a forma de
    // CONSULTA (bare `CMD#`) é uma SUPOSIÇÃO por analogia com o resto do
    // catálogo, não uma leitura da planilha (a planilha do JC450 declara a
    // convenção "cabeçalho em vermelho = consultável" mas a extração usada
    // aqui não preserva cor de fonte). Antes de usar em produção, confirmar
    // com o primeiro equipamento JC450 real via o botão "Ler tudo".
    //
    // ⚠️ Comandos da família "AI Features" que ficam de FORA, mesma política
    // do resto do arquivo: EVENTSET,FACE,* (G016–G019, CRUD de biblioteca
    // facial) e VIDEOUPLOAD/D010 (pedido de vídeo, não parâmetro). COLLIDE
    // (D011, colisão por acelerômetro) e INSTALLANGLE (D012) continuam em
    // `command_catalog.php`/`/comandos` — JC450 já foi adicionado ao COLLIDE
    // de lá nesta mesma versão.
    //
    // ⚠️ Vários campos da planilha do JC450 têm a mesma inconsistência
    // "coluna Format subconta os campos que o Comment/Example documentam"
    // já vista na planilha do JC181 (ver nota em GFENCE, command_catalog.php)
    // — nesses casos o EXEMPLO da própria planilha foi seguido, não a coluna
    // Format truncada; sinalizado caso a caso abaixo.
    // ═══════════════════════════════════════════════════════════════════════

    'SPEED,P1#' => [
        'cmd' => 'SPEED', 'nome' => 'Sensibilidade — excesso de velocidade',
        'desc' => 'Define o limite de velocidade que dispara o evento de excesso de velocidade. A duração acima do limite é FIXA em 5 s — não configurável neste modelo (diferente do JC371/JC181, onde a duração é um parâmetro).',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'SPEED#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha D004', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Limite de velocidade', 'format' => '30–255 (km/h)', 'default' => '255'],
        ],
        'exemplos' => [['cmd' => 'SPEED,100#', 'desc' => 'dispara acima de 100 km/h mantidos por 5 s (tempo fixo).']],
    ],
    'DMSSP,P1,P2#' => [
        'cmd' => 'DMSSP', 'nome' => 'Velocidade de ativação da IA',
        'desc' => 'Define a velocidade mínima do veículo para o ADAS ou o DMS começar a gerar eventos. Sem canal nem área — diferente do DMSSP do JC371 (4 parâmetros).',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'DMSSP#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G004', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função', 'format' => '1=ADAS / 2=DMS', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Velocidade de ativação', 'format' => 'km/h', 'default' => '57 (ADAS) / 28 (DMS)'],
        ],
        'exemplos' => [['cmd' => 'DMSSP,1,60#', 'desc' => 'ADAS a partir de 60 km/h.']],
    ],
    'FDMSVSP,P1,P2#' => [
        'cmd' => 'FDMSVSP', 'nome' => 'Velocidade virtual PERMANENTE para IA',
        'desc' => 'Fixa uma velocidade simulada de forma permanente (diferente de DMSVSP, que é só para teste pontual em bancada) — liga/desliga o modo e define o valor.',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'FDMSVSP#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G010', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Velocidade simulada', 'format' => '10–120 (km/h)', 'default' => '—'],
        ],
        'exemplos' => [['cmd' => 'FDMSVSP,ON,40#', 'desc' => 'exemplo da planilha.']],
    ],
    'ADAS,CALIBRATION,P1,P2,P3,P4,P5#' => [
        'cmd' => 'ADAS', 'nome' => 'Calibração — medidas de instalação da câmera',
        'desc' => 'Define as medidas físicas da instalação da câmera (em mm), usadas pelo algoritmo de ADAS. Totalmente diferente da calibração por tipo de veículo do JC371 (uma letra).',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'ADAS,CALIBRATION#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G001', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Altura de instalação da câmera', 'format' => '1–100000 (mm)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Distância da lente ao para-choque', 'format' => '1–100000 (mm)', 'default' => '—'],
            ['p' => 'P3', 'desc' => 'Distância da lente à roda esquerda', 'format' => '1–100000 (mm)', 'default' => '—'],
            ['p' => 'P4', 'desc' => 'Distância da lente à roda direita', 'format' => '1–100000 (mm)', 'default' => '—'],
            ['p' => 'P5', 'desc' => 'Distância da lente ao eixo', 'format' => '1–100000 (mm)', 'default' => '—'],
        ],
        'exemplos' => [['cmd' => 'ADAS,CALIBRATION,2900,200,1200,1200,2200#', 'desc' => 'exemplo da planilha.']],
    ],
    'DMSCROP,P1,P2,P3,P4#' => [
        'cmd' => 'DMSCROP', 'nome' => 'Área de detecção do DMS (recorte de vídeo)',
        'desc' => 'Define a região do quadro de vídeo que o algoritmo de DMS analisa, por coordenadas.',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'DMSCROP#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G009', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Coordenada X inicial', 'format' => '0–1280', 'default' => '—'],
            ['p' => 'P3', 'desc' => 'Coordenada Y inicial', 'format' => '0–720', 'default' => '—'],
            ['p' => 'P4', 'desc' => 'Largura da área (eixo X)', 'format' => '0–1280', 'default' => '—'],
        ],
        'exemplos' => [['cmd' => 'DMSCROP,ON,640,0,640#', 'desc' => 'exemplo da planilha.']],
    ],
    // DMSPI/DMSVI: UM comando cobre TODOS os tipos de evento ADAS+DMS — o tipo
    // é um parâmetro (P1), não um comando por evento como no JC371
    // (EVENTALERT,<código>). ⚠️ Esta tabela de códigos é PRÓPRIA do
    // DMSPI/DMSVI — não é a mesma numeração usada em DMSSEN/DMSSEP abaixo,
    // mesmo repetindo os números 1–10 (armadilha: nomes/números iguais não
    // implicam tabela igual entre comandos, nem entre modelos).
    'DMSPI,P1,P2#' => [
        'cmd' => 'DMSPI', 'nome' => 'Intervalo de envio à plataforma (por evento)',
        'desc' => 'Define de quanto em quanto tempo um tipo de evento ADAS/DMS é reenviado à plataforma enquanto a condição persiste.',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'DMSPI#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G002', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Tipo de evento', 'format' => '1=FCW (colisão frontal) / 2=HMW (veículo muito próximo) / 3=LDW (saída de faixa) / 5=DFW (olhos fechados) / 6=YAWN (bocejo) / 7=FSW (distração) / 8=CALL (celular) / 9=SMOKE (fumo) / 10=YCW (sem rosto detectado)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Intervalo de reenvio', 'format' => '1–64800 (segundos)', 'default' => '60 (todos os tipos)'],
        ],
        'exemplos' => [['cmd' => 'DMSPI,5,120#', 'desc' => 'exemplo da planilha — DFW reenviado a cada 120 s.']],
    ],
    'DMSVI,P1,P2#' => [
        'cmd' => 'DMSVI', 'nome' => 'Intervalo de aviso de voz (por evento)',
        'desc' => 'Define de quanto em quanto tempo o aviso de voz soa para um tipo de evento ADAS/DMS enquanto a condição persiste. Mesma tabela de códigos do DMSPI acima.',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'DMSVI#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G003', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Tipo de evento', 'format' => '1=FCW / 2=HMW / 3=LDW / 5=DFW / 6=YAWN / 7=FSW / 8=CALL / 9=SMOKE / 10=YCW (ver descrição completa em DMSPI)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Intervalo entre avisos de voz', 'format' => '1–64800 (segundos)', 'default' => 'FCW: 30 / HMW: 10 / LDW: 5 / DFW: 5 / YAWN: 5 / FSW: 5 / CALL: 30 / SMOKE: 30 / YCW: 120'],
        ],
        'exemplos' => [['cmd' => 'DMSVI,1,10#', 'desc' => 'exemplo da planilha — FCW com voz a cada 10 s.']],
    ],
    'DMSSEP,P1,P2#' => [
        'cmd' => 'DMSSEP', 'nome' => 'Ativação por tipo de evento ADAS/DMS',
        'desc' => 'Ativa ou desativa individualmente um tipo de evento ADAS/DMS. Mesma tabela de códigos do DMSPI/DMSVI acima.',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'DMSSEP#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G005', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Tipo de evento', 'format' => '1=FCW / 2=HMW / 3=LDW / 5=DFW / 6=YAWN / 7=FSW / 8=CALL / 9=SMOKE / 10=YCW (ver descrição completa em DMSPI)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Estado', 'format' => '0=Desativar / 1=Ativar', 'default' => 'todos ativos, exceto YCW (10) desativado'],
        ],
        'exemplos' => [['cmd' => 'DMSSEP,3,0#', 'desc' => 'exemplo da planilha — desativa LDW.']],
    ],
    // DMSSEN: sensibilidade por tipo de evento — ⚠️ TABELA DE CÓDIGOS PRÓPRIA
    // (não é a do DMSPI/DMSVI/DMSSEP acima) e a ARIDADE/SIGNIFICADO de
    // P2–P5 muda conforme P1. Documentado por completo aqui porque a tela
    // não tem como desenhar um formulário condicional — o operador precisa
    // ler esta descrição antes de preencher.
    'DMSSEN,P1,P2,P3,P4,P5#' => [
        'cmd' => 'DMSSEN', 'nome' => 'Sensibilidade de disparo por função de DMS',
        'desc' => "Sensibilidade específica de cada função de DMS — P1 escolhe a função e MUDA o significado de P2–P5:\n"
            . "P1=1 (olhos fechados): P2=tempo de detecção (1–10 s, padrão 2) · P3=limiar de sensibilidade (0–0,3, padrão 0,07, quanto maior mais sensível) · P4/P5 não usados.\n"
            . "P1=2 (celular): P2=uso da boca aberta como condição (-1 a 1; <0 não usa, >0 usa; padrão -1) · P3=tempo de detecção (1–10 s, padrão 2) · P4=limiar (0,1–0,9, padrão 0,4, quanto menor mais sensível) · P5 não usado.\n"
            . "P1=3 (fumo): P2=uso da boca aberta (-1 a 1, padrão 0,2) · P3=tempo de detecção (1–10 s, padrão 1,5) · P4=limiar (0,1–0,9, padrão 0,7) · P5=uso de faísca como condição (0/1, padrão 0).\n"
            . "P1=4 (bocejo): P2=tempo de detecção (1–10 s, padrão 2) · P3=limiar (0,2–1,9, padrão 0,5) · P4/P5 não usados.\n"
            . "P1=5 (distração): P2=tempo de detecção (1–10 s, padrão 2) · P3=ângulo esquerda/direita (5–60°, padrão 20/30) · P4=ângulo para baixo (5–30°, padrão 15/30) · P5 não usado.\n"
            . "P1=7 (sem rosto): P2=tempo de detecção (1–30 s, padrão 10) · P3/P4/P5 não usados.\n"
            . "P1=8 (calibração de rosto): P2=tempo de detecção (10–100 s, padrão 15) · P3/P4/P5 não usados.\n"
            . "P1=10 (olhos fechados contínuo): P2=tempo de detecção (5–10 s, padrão 5) · P3/P4/P5 não usados.",
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'DMSSEN#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G007', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função (define o significado de P2–P5 — ver descrição completa acima)', 'format' => '1=olhos fechados / 2=celular / 3=fumo / 4=bocejo / 5=distração / 7=sem rosto / 8=calibração de rosto / 10=olhos fechados contínuo', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Depende de P1 — ver descrição completa acima', 'format' => 'varia por função', 'default' => '—'],
            ['p' => 'P3', 'desc' => 'Depende de P1 — deixar em branco se a função não usar (ver descrição completa acima)', 'format' => 'varia por função', 'default' => '—'],
            ['p' => 'P4', 'desc' => 'Depende de P1 — deixar em branco se a função não usar (ver descrição completa acima)', 'format' => 'varia por função', 'default' => '—'],
            ['p' => 'P5', 'desc' => 'Depende de P1 — deixar em branco se a função não usar (ver descrição completa acima)', 'format' => 'varia por função', 'default' => '—'],
        ],
        'exemplos' => [
            ['cmd' => 'DMSSEN,1,5,0.1#', 'desc' => 'olhos fechados, detecção 5 s, limiar 0,1.'],
            ['cmd' => 'DMSSEN,5,3,45,20#', 'desc' => 'distração, detecção 3 s, ângulos 45°/20°.'],
        ],
    ],
    'DMS_CONTINUITY,P1,P2,P3,P4,P5,P6#' => [
        'cmd' => 'DMS_CONTINUITY', 'nome' => 'Duração mínima por tipo de alerta DMS',
        'desc' => 'Duração mínima que cada condição precisa persistir para gerar o alerta — um campo por tipo, na ordem fixa: olhos fechados, bocejo, distração, fumo, celular, sem rosto. Só tem efeito com DMSSW,2,2 ativo (ver DMSSW acima).',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'DMS_CONTINUITY#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G012', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Olhos fechados', 'format' => '0–10 (segundos, 0 desativa)', 'default' => '3'],
            ['p' => 'P2', 'desc' => 'Bocejo', 'format' => '0–10 (segundos, 0 desativa)', 'default' => '3'],
            ['p' => 'P3', 'desc' => 'Distração', 'format' => '0–10 (segundos, 0 desativa)', 'default' => '3'],
            ['p' => 'P4', 'desc' => 'Fumo', 'format' => '0–10 (segundos, 0 desativa)', 'default' => '3'],
            ['p' => 'P5', 'desc' => 'Celular', 'format' => '0–10 (segundos, 0 desativa)', 'default' => '3'],
            ['p' => 'P6', 'desc' => 'Sem rosto', 'format' => '0–60 (segundos, 0 desativa)', 'default' => '10'],
        ],
        'exemplos' => [['cmd' => 'DMS_CONTINUITY,5,5,5,5,5,15#', 'desc' => 'exemplo da planilha.']],
    ],
    'DMS_ALERT_CUSTOM,P1,P2,P3,P4,P5,P6#' => [
        'cmd' => 'DMS_ALERT_CUSTOM', 'nome' => 'Intervalo de envio por tipo de alerta DMS',
        'desc' => 'Intervalo entre reenvios do mesmo tipo de alerta à plataforma — mesma ordem de campos do DMS_CONTINUITY acima. Só tem efeito com DMSSW,2,2 ativo.',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'DMS_ALERT_CUSTOM#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G013', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Olhos fechados', 'format' => '10–3600 (segundos)', 'default' => '60'],
            ['p' => 'P2', 'desc' => 'Bocejo', 'format' => '10–3600 (segundos)', 'default' => '60'],
            ['p' => 'P3', 'desc' => 'Distração', 'format' => '10–3600 (segundos)', 'default' => '60'],
            ['p' => 'P4', 'desc' => 'Fumo', 'format' => '10–3600 (segundos)', 'default' => '60'],
            ['p' => 'P5', 'desc' => 'Celular', 'format' => '10–3600 (segundos)', 'default' => '60'],
            ['p' => 'P6', 'desc' => 'Sem rosto', 'format' => '10–3600 (segundos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'DMS_ALERT_CUSTOM,120,120,120,120,120,120#', 'desc' => 'exemplo da planilha.']],
    ],
    'DMS_VOICE_CUSTOM,P1,P2,P3,P4,P5,P6#' => [
        'cmd' => 'DMS_VOICE_CUSTOM', 'nome' => 'Intervalo de voz por tipo de alerta DMS',
        'desc' => 'Intervalo entre avisos de voz do mesmo tipo de alerta — mesma ordem de campos do DMS_CONTINUITY acima. Só tem efeito com DMSSW,2,2 ativo.',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'DMS_VOICE_CUSTOM#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G014', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Olhos fechados', 'format' => '10–3600 (segundos)', 'default' => '5'],
            ['p' => 'P2', 'desc' => 'Bocejo', 'format' => '10–3600 (segundos)', 'default' => '5'],
            ['p' => 'P3', 'desc' => 'Distração', 'format' => '10–3600 (segundos)', 'default' => '5'],
            ['p' => 'P4', 'desc' => 'Fumo', 'format' => '10–3600 (segundos)', 'default' => '5'],
            ['p' => 'P5', 'desc' => 'Celular', 'format' => '10–3600 (segundos)', 'default' => '5'],
            ['p' => 'P6', 'desc' => 'Sem rosto', 'format' => '10–3600 (segundos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'DMS_VOICE_CUSTOM,10,10,10,10,10,10#', 'desc' => 'exemplo da planilha.']],
    ],
    // Cinto de segurança — só existe no JC450 e no JC371 (ver mais abaixo, na
    // seção de pares EVENTSET do JC371); ARIDADE DIFERENTE entre os dois
    // modelos (JC371 tem 2 campos, JC450 tem 3), por isso são entradas
    // SEPARADAS, não uma compartilhada. ⚠️ A coluna "Format" da planilha do
    // JC450 desta linha (G020/G021) diz só "A,B", mas o Comment descreve 3
    // campos e o EXEMPLO tem 3 valores — mesma inconsistência já vista em
    // GFENCE (command_catalog.php); seguido o exemplo, não a coluna truncada.
    'EVENTSET,AWSB,P1,P2,P3#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — cinto afivelado (DMS)',
        'desc' => 'Sensibilidade, duração e intervalo de detecção para o alerta de cinto de segurança FASTENED (afivelado incorretamente/de forma insegura, conforme detecção visual).',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'EVENTSET,AWSB#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G020 (coluna Format truncada — ver nota acima)', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => '0=desativado / 1=alta / 2=média / 3=baixa', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Duração mínima para gerar o evento', 'format' => '1–255 (segundos)', 'default' => '3'],
            ['p' => 'P3', 'desc' => 'Intervalo entre detecções', 'format' => '1–255 (minutos)', 'default' => '1'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,AWSB,2,3,1#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTSET,ANWSB,P1,P2,P3#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — sem cinto (DMS)',
        'desc' => 'Sensibilidade, duração e intervalo de relatório para o alerta de cinto de segurança NÃO afivelado.',
        'modelos' => ['JC450'], 'template' => true,
        'consulta' => 'EVENTSET,ANWSB#', 'consulta_ref' => 'inferido', 'fonte' => 'JC450 series command list V2.1.1, linha G021', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => '0=desativado / 1=alta / 2=média / 3=baixa', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Duração mínima para gerar o evento', 'format' => '1–255 (segundos)', 'default' => '30'],
            ['p' => 'P3', 'desc' => 'Intervalo de relatório', 'format' => '10–3600 (minutos)', 'default' => '60'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ANWSB,2,30,60#', 'desc' => 'exemplo da planilha.']],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // JC181 — sem ADAS/DMS por visão (sem chip de IA); só evento de velocidade
    // ═══════════════════════════════════════════════════════════════════════

    // v4.13.13 (26/08/2026) — JC182 adicionado: o dono do produto confirmou
    // que a câmera responde ao mesmo `SPEED` da planilha JC181 (além do
    // `EVENTSET,AOSD` já medido — as duas formas convivem no mesmo
    // equipamento). Ver a seção "JC181/JC182 — comandos de acelerômetro/GPS"
    // logo abaixo para o resto do vocabulário compartilhado dos dois modelos.
    'SPEED,P1,P2,P3,P4#' => [
        'cmd' => 'SPEED', 'nome' => 'Evento de excesso de velocidade',
        'desc' => 'Ativa/desativa o evento de excesso de velocidade, define modo de alerta, limite e duração acima dele.',
        'modelos' => ['JC181', 'JC182'], 'template' => true,
        'consulta' => 'SPEED#', 'consulta_ref' => 'medido', 'fonte' => 'JC181 Command List V1.0.7, linha D003', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Modo de alerta', 'format' => '0=GPRS / 1=SMS+GPRS', 'default' => '—'],
            ['p' => 'P3', 'desc' => 'Limite de velocidade', 'format' => '1–255 (km/h)', 'default' => '50'],
            ['p' => 'P4', 'desc' => 'Duração acima do limite para gerar o evento', 'format' => '5–600 (segundos)', 'default' => '20'],
        ],
        'exemplos' => [['cmd' => 'SPEED,ON,0,90,10', 'desc' => 'dispara acima de 90 km/h mantidos por 10 s.']],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // JC182 — comandos de acelerômetro/GPS trazidos para a tela de IA
    // ═══════════════════════════════════════════════════════════════════════
    //
    // v4.13.13 (26/08/2026) — CORREÇÃO de leitura do pedido original: "todos
    // os outros comandos desse modelo devem estar na tela de comandos" (msg.
    // do dono do produto) se referia a ESTA tela ("tela de comandos de IA" —
    // Configurações IA), não à tela genérica `/comandos`. A v4.13.12 tinha
    // interpretado errado e deixado esses comandos só em
    // `command_catalog.php`; o dono do produto corrigiu ("você não corrigiu
    // a tela de comandos de ia... com os comandos que listei").
    //
    // v4.13.14 — SEGUNDA correção: a v4.13.13 tinha replicado estas 8
    // entradas também para o JC181, mas o pedido original ("as câmeras
    // modelo 182 possuem os comandos senalm, speed, speedcheck, swerve,
    // collide, gfence e fatigue") é ESPECÍFICO do JC182 — o dono do produto
    // listou os comandos por modelo, não um vocabulário genérico "JC18x".
    // JC181 REMOVIDO de todas as entradas abaixo (`SPEED` continua com os
    // dois modelos porque já tinha JC181 desde ANTES desta sessão, por
    // fonte própria — não é parte deste erro). O rótulo "— dialeto planilha"
    // também foi removido dos cartões por não comunicar nada útil ao
    // operador; a fonte real (qual comando é EVENTSET/JT/T vs. o de texto
    // simples da planilha) já está documentada no `desc` de cada um.
    //
    // Estes comandos entram nesta tela (não só em `/comandos`) por exceção
    // deliberada à regra geral de "fora de escopo" do cabeçalho do arquivo
    // (que continua valendo para JC181/JC400/JC261/outros modelos, onde não
    // há pedido do dono do produto para trazê-los) — o JC182 não tem uma
    // tela rica de DMS/ADAS como o JC371/JC400AD, então a tela de IA é o
    // painel de configuração dele. DUPLICATAS de propósito das mesmas
    // entradas em `command_catalog.php`/`/comandos` — mesma fonte, mesmos
    // parâmetros.
    //
    // Fonte de todas: `docs/JC181_Command_List_V1.0.7_20250811.xlsx` (o
    // JC182 compartilha este vocabulário com o JC181, confirmado pelo dono
    // do produto), exceto EVENTSET,ACD/AVD (medidos em campo no JC182,
    // 26/08/2026 — ver nota em EVENTSET,AOSD no topo deste arquivo).

    'EVENTSET,ACD,P1#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — colisão',
        'desc' => 'Sensibilidade do evento de colisão (dialeto EVENTSET/JT/T do JC182). Confirmado em campo — um dos 3 códigos que a câmera de fato responde.',
        'modelos' => ['JC182'], 'template' => true,
        'consulta' => 'EVENTSET,ACD#', 'consulta_ref' => 'medido', 'fonte' => 'medido em campo, 26/08/2026', 'procedencia' => 'wiki',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'valor visto em campo: 80 — faixa completa não confirmada', 'default' => '80'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,ACD,80#', 'desc' => 'valor visto em campo no JC182 (26/08/2026).']],
    ],
    'EVENTSET,AVD,P1,P2,P3,P4#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — vibração',
        'desc' => 'Sensibilidade do evento de vibração com o veículo parado (dialeto EVENTSET/JT/T do JC182). Confirmado em campo — um dos 3 códigos que a câmera de fato responde.',
        'modelos' => ['JC182'], 'template' => true,
        'consulta' => 'EVENTSET,AVD#', 'consulta_ref' => 'medido', 'fonte' => 'medido em campo, 26/08/2026', 'procedencia' => 'wiki',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 1–5 (quanto menor, mais sensível)', 'default' => '3'],
            ['p' => 'P2', 'desc' => 'Tempo de detecção', 'format' => '1–300 (segundos)', 'default' => '10'],
            ['p' => 'P3', 'desc' => 'Nº de vibrações', 'format' => '1–20', 'default' => '5'],
            ['p' => 'P4', 'desc' => 'Filtro de alarme', 'format' => '10–60 (segundos)', 'default' => '30'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,AVD,3,10,5,30#', 'desc' => 'gera evento se houver 5 vibrações em 10s, após 30s em ACC OFF.']],
    ],
    'SENALM,P1,P2,P3,P4,P5#' => [
        'cmd' => 'SENALM', 'nome' => 'Vibração (veículo parado)',
        'desc' => 'Sensibilidade para disparar evento de vibração com o veículo estacionado. Comando alternativo ao EVENTSET,AVD acima, com mais parâmetros de ajuste.',
        'modelos' => ['JC182'], 'template' => true,
        'consulta' => 'SENALM#', 'consulta_ref' => 'medido', 'fonte' => 'JC181 Command List V1.0.7, linha D002', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade (0 desativa; quanto maior o número, menos sensível)', 'format' => '0/1/2/3/4/5', 'default' => '2'],
            ['p' => 'P2', 'desc' => 'Número de interrupções por vibração para disparar o alarme', 'format' => '1–20', 'default' => '5'],
            ['p' => 'P3', 'desc' => 'Tempo de detecção', 'format' => '1–3000 (segundos)', 'default' => '10'],
            ['p' => 'P4', 'desc' => 'Intervalo mínimo até o próximo alarme (filtro)', 'format' => '1–3000 (minutos)', 'default' => '5'],
            ['p' => 'P5', 'desc' => 'Forma de envio do alarme', 'format' => '0 = GPRS / 1 = SMS+GPRS', 'default' => '0'],
        ],
        'exemplos' => [['cmd' => 'SENALM,2,10,15,5,0#', 'desc' => 'exemplo da planilha.']],
    ],
    'COLLIDE,P1,P2,P3,P4,P5,P6,P7,P8#' => [
        'cmd' => 'COLLIDE', 'nome' => 'Colisão',
        'desc' => 'Sensibilidade para disparar alerta de colisão durante a condução. Comando alternativo ao EVENTSET,ACD acima, com mais parâmetros de ajuste.',
        'modelos' => ['JC182'], 'template' => true,
        'consulta' => 'COLLIDE#', 'consulta_ref' => 'inferido', 'fonte' => 'JC181 Command List V1.0.7, linha D006', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'ON'],
            ['p' => 'P2', 'desc' => 'Forma de envio do alarme', 'format' => '0 = GPRS / 1 = SMS+GPRS', 'default' => '0'],
            ['p' => 'P3', 'desc' => 'Sensibilidade de disparo', 'format' => '0–255', 'default' => '120'],
            ['p' => 'P4', 'desc' => 'Atraso antes de checar a velocidade', 'format' => '0–20 (segundos)', 'default' => '0'],
            ['p' => 'P5', 'desc' => 'Tempo de checagem — confirma colisão se a velocidade ficar abaixo do limiar por este tempo', 'format' => '10–90 (segundos)', 'default' => '15'],
            ['p' => 'P6', 'desc' => 'Limiar de velocidade para confirmar colisão', 'format' => '5–30 (km/h)', 'default' => '5'],
            ['p' => 'P7', 'desc' => 'Taxa mínima de variação de aceleração', 'format' => '0–100', 'default' => '70'],
            ['p' => 'P8', 'desc' => 'Taxa de variação de aceleração acima da qual dispensa a dupla confirmação', 'format' => '2–300', 'default' => '90'],
        ],
        'exemplos' => [['cmd' => 'COLLIDE,ON,0,600,10,90,5#', 'desc' => 'exemplo literal da planilha — ⚠️ tem só 6 valores para 8 campos documentados (planilha do fabricante inconsistente); confirmar arity real antes de usar em produção.']],
    ],
    'SPEEDCHECK,P1,P2,P3,P4,P5#' => [
        'cmd' => 'SPEEDCHECK', 'nome' => 'Frenagem/aceleração brusca (detecção)',
        'desc' => 'Queda ou aumento de velocidade em N segundos para caracterizar frenagem/aceleração brusca.',
        'modelos' => ['JC182'], 'template' => true,
        'consulta' => 'SPEEDCHECK#', 'consulta_ref' => 'inferido', 'fonte' => 'JC181 Command List V1.0.7, linha D004', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Forma de envio do alarme', 'format' => '0 = GPRS / 1 = SMS+GPRS', 'default' => '0'],
            ['p' => 'P3', 'desc' => 'Tempo de detecção', 'format' => '1–30 (segundos)', 'default' => '4'],
            ['p' => 'P4', 'desc' => 'Variação de velocidade que caracteriza aceleração brusca', 'format' => '10–300 (km/h)', 'default' => '30'],
            ['p' => 'P5', 'desc' => 'Variação de velocidade que caracteriza frenagem brusca', 'format' => '10–300 (km/h)', 'default' => '50'],
        ],
        'exemplos' => [['cmd' => 'SPEEDCHECK,ON,0,4,30,50#', 'desc' => 'exemplo da planilha.']],
    ],
    'SWERVE,P1,P2,P3,P4,P5#' => [
        'cmd' => 'SWERVE', 'nome' => 'Curva brusca (detecção)',
        'desc' => 'Tempo de detecção para caracterizar curva brusca.',
        'modelos' => ['JC182'], 'template' => true,
        'consulta' => 'SWERVE#', 'consulta_ref' => 'inferido', 'fonte' => 'JC181 Command List V1.0.7, linha D005', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Forma de envio do alarme', 'format' => '0 = GPRS / 1 = SMS+GPRS', 'default' => '0'],
            ['p' => 'P3', 'desc' => 'Limiar do ângulo de curva (planilha rotula a unidade como "km/h" — provável erro do fabricante)', 'format' => '10–180', 'default' => '30'],
            ['p' => 'P4', 'desc' => 'Velocidade mínima para caracterizar curva brusca', 'format' => '10–300 (km/h)', 'default' => '60'],
            ['p' => 'P5', 'desc' => 'Tempo de detecção', 'format' => '1–30 (segundos)', 'default' => '3'],
        ],
        'exemplos' => [['cmd' => 'SWERVE,ON,0,30,30,3#', 'desc' => 'exemplo da planilha.']],
    ],
    'FATIGUE,P1,P2,P3,P4#' => [
        'cmd' => 'FATIGUE', 'nome' => 'Fadiga (direção por tempo excessivo)',
        'desc' => 'Configura o limiar de horas dirigindo sem parar que dispara o evento de fadiga.',
        'modelos' => ['JC182'], 'template' => true,
        'consulta' => 'FATIGUE#', 'consulta_ref' => 'inferido', 'fonte' => 'JC181 Command List V1.0.7, linha D010', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Tempo dirigindo sem parar que dispara o evento', 'format' => '4–12 (horas)', 'default' => '4'],
            ['p' => 'P3', 'desc' => 'Tempo mínimo de parada para zerar a contagem', 'format' => '1–30 (minutos)', 'default' => '30'],
            ['p' => 'P4', 'desc' => 'Forma de envio do alarme', 'format' => '0 = GPRS / 1 = SMS+GPRS', 'default' => '0'],
        ],
        'exemplos' => [['cmd' => 'FATIGUE,ON,6,15,0#', 'desc' => 'dispara após 6 h dirigindo sem parar por ao menos 15 min.']],
    ],
    // GFENCE: ⚠️ mesma incerteza genuína documentada em `command_catalog.php`
    // — um campo final sem descrição em nenhum lugar da planilha (sempre "1"
    // nos dois exemplos oficiais). NÃO enviar em produção sem confirmar em
    // câmera real primeiro.
    'GFENCE,P1,P2,P3,P4,P5,P6,P7,P8,P9,P10#' => [
        'cmd' => 'GFENCE', 'nome' => 'Cerca eletrônica (circular)',
        'desc' => 'Configura uma cerca eletrônica circular no equipamento e, opcionalmente, controla a gravação dentro/fora dela.',
        'modelos' => ['JC182'], 'template' => true,
        'consulta' => null, 'consulta_ref' => null, 'fonte' => 'JC181 Command List V1.0.7, linha D011', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Número da cerca', 'format' => '1 (único valor visto na planilha)', 'default' => '1'],
            ['p' => 'P2', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'OFF'],
            ['p' => 'P3', 'desc' => 'Forma da cerca (fixo nesta variante)', 'format' => '0 = circular', 'default' => '0'],
            ['p' => 'P4', 'desc' => 'Latitude do centro', 'format' => '0 = detecção automática pela posição atual do GPS, ou valor fixo', 'default' => '0'],
            ['p' => 'P5', 'desc' => 'Longitude do centro', 'format' => '0 = detecção automática pela posição atual do GPS, ou valor fixo', 'default' => '0'],
            ['p' => 'P6', 'desc' => 'Raio do círculo', 'format' => '1–9999, unidade 100 m (ex.: 10 = 1000 m)', 'default' => '10'],
            ['p' => 'P7', 'desc' => 'Direção do alarme', 'format' => 'IN = ao entrar / OUT = ao sair / vazio = os dois', 'default' => 'vazio'],
            ['p' => 'P8', 'desc' => 'Forma de envio do alarme', 'format' => '0 = GPRS / 1 = SMS+GPRS', 'default' => '0'],
            ['p' => 'P9', 'desc' => 'Controle de gravação', 'format' => '0 = grava só fora da cerca / 1 = grava só dentro / 255 = não controla', 'default' => '0'],
            ['p' => 'P10', 'desc' => '⚠️ Campo sem descrição na planilha — sempre "1" no único exemplo visto', 'format' => 'desconhecido', 'default' => '1'],
        ],
        'exemplos' => [['cmd' => 'GFENCE,1,ON,0,0,0,10,,0,0,1#', 'desc' => 'exemplo literal da planilha — cerca 1, centro pela posição atual, raio 1000 m, alarme ao entrar e sair, GPRS. NÃO confirmado em câmera real.']],
    ],
    'GFENCE,P1,P2,P3,P4,P5,P6,P7,P8,P9,P10,P11#' => [
        'cmd' => 'GFENCE', 'nome' => 'Cerca eletrônica (retangular)',
        'desc' => 'Configura uma cerca eletrônica retangular no equipamento e, opcionalmente, controla a gravação dentro/fora dela.',
        'modelos' => ['JC182'], 'template' => true,
        'consulta' => null, 'consulta_ref' => null, 'fonte' => 'JC181 Command List V1.0.7, linha D012', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Número da cerca', 'format' => '1 (único valor visto na planilha)', 'default' => '1'],
            ['p' => 'P2', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'OFF'],
            ['p' => 'P3', 'desc' => 'Forma da cerca (fixo nesta variante)', 'format' => '1 = retangular', 'default' => '1'],
            ['p' => 'P4', 'desc' => 'Latitude do 1º canto', 'format' => 'graus decimais', 'default' => '—'],
            ['p' => 'P5', 'desc' => 'Longitude do 1º canto', 'format' => 'graus decimais', 'default' => '—'],
            ['p' => 'P6', 'desc' => 'Latitude do 2º canto', 'format' => 'graus decimais', 'default' => '—'],
            ['p' => 'P7', 'desc' => 'Longitude do 2º canto', 'format' => 'graus decimais', 'default' => '—'],
            ['p' => 'P8', 'desc' => 'Direção do alarme', 'format' => 'IN = ao entrar / OUT = ao sair / vazio = os dois', 'default' => 'vazio'],
            ['p' => 'P9', 'desc' => 'Forma de envio do alarme', 'format' => '0 = GPRS / 1 = SMS+GPRS', 'default' => '0'],
            ['p' => 'P10', 'desc' => 'Controle de gravação', 'format' => '0 = grava só fora da cerca / 1 = grava só dentro / 255 = não controla', 'default' => '0'],
            ['p' => 'P11', 'desc' => '⚠️ Campo sem descrição na planilha — sempre "1" no único exemplo visto', 'format' => 'desconhecido', 'default' => '1'],
        ],
        'exemplos' => [['cmd' => 'GFENCE,1,ON,1,23,113,24,114,,0,0,1#', 'desc' => 'exemplo literal da planilha — retângulo entre os cantos (23,113) e (24,114), alarme ao entrar e sair, GPRS. NÃO confirmado em câmera real.']],
    ],

    // v4.13.13 (26/08/2026) — nota histórica atualizada: esta seção dizia que
    // JC450 e JC182 "não têm planilha própria" e entravam só como modelo a
    // mais nas linhas do JC371 (DMSSP/DMSVSP/ADAS,CALIBRATION para o JC450;
    // EVENTSET,ACEA/ADCA/AFIF/AHMW/ALDW/ANDD para o JC182). Isso mudou:
    //   - O JC450 GANHOU planilha própria nesta versão
    //     (`docs/JC450 series command list-EN V2.1.1.xlsx`) e uma seção
    //     inteira de 18 entradas, logo após "JC400D/JC400AD/JC261/JC261P —
    //     ADAS" acima — `DMSSP`/`ADAS,CALIBRATION` do JC450 têm sintaxe
    //     PRÓPRIA (removidos das entradas do JC371 que citavam esta nota).
    //   - O JC182 perdeu os 6 códigos ADAS/DMS que citavam esta nota: teste
    //     real de campo (26/08/2026) mostrou que o equipamento não tem
    //     câmera de IA/visão computacional — só responde a 3 códigos EVENTSET
    //     (`ACD`, `AVD`, `AOSD`). Os 3 ganharam entrada própria nesta tela
    //     (ver acima), junto do restante do vocabulário de
    //     `docs/JC181_Command_List_V1.0.7_20250811.xlsx` que o JC182
    //     compartilha com o JC181 — trazido só para o JC182, por pedido
    //     explícito do dono do produto (26/08/2026). O JC181 continua fora
    //     desta tela (nunca teve tela rica de DMS/ADAS, mas também nunca foi
    //     pedido trazer estes comandos de acelerômetro/GPS para cá).

];
