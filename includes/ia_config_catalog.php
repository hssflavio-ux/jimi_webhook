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
 *   - JC450/JC182: **não têm planilha própria no `docs/`** e a wiki
 *     (`wiki-foconavia.newtectelemetria.com.br`) é uma SPA em JS que o
 *     WebFetch não consegue renderizar (só devolve o título da página,
 *     confirmado em 25/08/2026). Cobertura destes dois modelos vem do que já
 *     existia em `command_catalog.php` sob `categoria === 'ia'` com esses
 *     modelos listados — `procedencia: 'wiki'`, a mesma disciplina que o
 *     catálogo original já usa pra marcar confiança menor que `medido`.
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
 *   - `CRASHALM`/`SENSOR`/`SHOCK`/`SENALM`/`DEFENSE*`/`RAPIDACC`/`RAPIDDEC`/
 *     `RAPIDTURN`/`RAPIDTEST` (JC400/JC261) e `COLLIDE`/`SPEEDCHECK`/`SWERVE`
 *     (JC181) — colisão/vibração por ACELERÔMETRO e curva/frenagem brusca,
 *     não visão computacional nem excesso de velocidade. Continuam
 *     disponíveis em `/comandos`, sem mudança.
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
 *   consulta     — forma nua que LÊ o valor, quando documentada; null quando não há
 *   fonte        — planilha + linha/seção de onde veio
 *   procedencia  — 'planilha' (as 3 fontes acima) ou 'wiki' (fallback JC450/JC182)
 *   params[]     — cada um com 'p' (nome do placeholder), 'desc', 'format'
 *                  (a MÁSCARA — é o texto da tag de auxílio na tela) e 'default'
 *   exemplos[]   — pelo menos um comando pronto, da própria planilha
 *
 * Total: 58 entradas — JC371 (42 no campo `modelos`, contando os
 * compartilhados com JC450/JC182) + JC400AD (16, G001–G015) + JC182 (6,
 * fallback do catálogo antigo) + JC450 (3, idem) + JC181 (1, SPEED). JC450 e
 * JC182 não somam entradas PRÓPRIAS — entram como modelo extra nas linhas do
 * JC371 que o catálogo antigo já confirmava (ver a nota no fim do arquivo).
 */

return [

    // ═══════════════════════════════════════════════════════════════════════
    // JC371 — velocidade (D001/D002) e velocidade virtual de teste (D015)
    // ═══════════════════════════════════════════════════════════════════════

    'EVENTSET,AOSD,P1,P2#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — excesso de velocidade',
        'desc' => 'Define o limite de velocidade e por quanto tempo acima dele gera o evento de excesso de velocidade.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha D001', 'procedencia' => 'planilha',
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
        'consulta' => null,
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha D015', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Velocidade simulada', 'format' => '0–120 (km/h)', 'default' => '—'],
        ],
        'exemplos' => [['cmd' => 'DMSVSP,60#', 'desc' => 'simula 60 km/h.']],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // JC371 — ativação geral de IA e calibração de veículo
    // ═══════════════════════════════════════════════════════════════════════

    'DMSSP,P1,P2,P3,P4#' => [
        'cmd' => 'DMSSP', 'nome' => 'Ativação de IA (velocidade/canal/área)',
        'desc' => 'Define a velocidade mínima de ativação, o canal de vídeo e a área de detecção do ADAS ou do DMS.',
        'modelos' => ['JC371', 'JC450'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E003', 'procedencia' => 'planilha',
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
    'DMSSW,P1,P2#' => [
        'cmd' => 'DMSSW', 'nome' => 'Chave de funções de IA',
        'desc' => 'Ativa ou desativa uma função de IA específica (ADAS, DMS ou reconhecimento facial). Qualquer recurso de IA precisa estar habilitado aqui antes de ser usado.',
        'modelos' => ['JC371', 'JC400AD'], 'template' => true,
        'consulta' => 'DMSSW#', 'fonte' => 'command_catalog.php (wiki) — ausente da planilha JC371', 'procedencia' => 'wiki',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função de IA', 'format' => '1=ADAS / 2=DMS / 3=FACE (reconhecimento facial)', 'default' => '1 e 2 ativos / 3 inativo'],
            ['p' => 'P2', 'desc' => 'Estado', 'format' => '0=Desativar / 1=Ativar', 'default' => 'conforme a função'],
        ],
        'exemplos' => [
            ['cmd' => 'DMSSW,1,0#', 'desc' => 'desativa o ADAS.'],
            ['cmd' => 'DMSSW,3,1#', 'desc' => 'ativa o reconhecimento facial.'],
        ],
    ],
    'ADAS,CALIBRATION,P1#' => [
        'cmd' => 'ADAS', 'nome' => 'Calibração — perfil do veículo',
        'desc' => 'Define os parâmetros de instalação da câmera conforme o tipo/porte do veículo.',
        'modelos' => ['JC371', 'JC450', 'JC400AD'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E005', 'procedencia' => 'planilha',
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
        'modelos' => ['JC371', 'JC182'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E006', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Sensibilidade (distância de cruzamento das rodas)', 'format' => 'OFF / 10–100 (cm)', 'default' => '60']],
        'exemplos' => [['cmd' => 'EVENTSET,ALDW,60#', 'desc' => 'dispara a 60 cm de cruzamento.']],
    ],
    'EVENTALERT,ALDW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — saída de faixa (ADAS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de saída de faixa.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E007', 'procedencia' => 'planilha',
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
        'modelos' => ['JC371', 'JC182'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E008', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Sensibilidade (limiar de tempo de risco)', 'format' => 'OFF / 500–10000 (ms)', 'default' => '1200']],
        'exemplos' => [['cmd' => 'EVENTSET,AHMW,1200#', 'desc' => 'limiar de 1200 ms.']],
    ],
    'EVENTALERT,AHMW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — distância insegura (ADAS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de distância insegura.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E009', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E010', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Sensibilidade (limiar de tempo de risco)', 'format' => 'OFF / 500–10000 (ms)', 'default' => '2500']],
        'exemplos' => [['cmd' => 'EVENTSET,AFCW,2500#', 'desc' => 'limiar de 2500 ms.']],
    ],
    'EVENTALERT,AFCW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — colisão frontal (ADAS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de colisão frontal.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E011', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E012', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Sensibilidade', 'format' => 'OFF / 500–10000 (ms)', 'default' => '5000']],
        'exemplos' => [['cmd' => 'EVENTSET,APCW,5000#', 'desc' => 'limiar de 5000 ms.']],
    ],
    'EVENTALERT,APCW,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — colisão com pedestre (ADAS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para o evento de risco de colisão com pedestre.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E013', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E014', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E015', 'procedencia' => 'planilha',
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
        'modelos' => ['JC371', 'JC182'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E016', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Tempo limite para calibração', 'format' => 'OFF / 0–64800 (segundos)', 'default' => '60']],
        'exemplos' => [['cmd' => 'EVENTSET,ADCA,60#', 'desc' => 'limite de 60 s.']],
    ],
    'EVENTALERT,ADCA,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — anomalia de calibração (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz para anomalia de calibração do DMS.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E017', 'procedencia' => 'planilha',
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
        'modelos' => ['JC371', 'JC182'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E018', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E020', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E021', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E022', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E019', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E023', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E024', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E025', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E026', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E027', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E028', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E029', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E030', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E031', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E032', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E033', 'procedencia' => 'planilha',
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
        'modelos' => ['JC371', 'JC182'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E034', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E035', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Alerta na plataforma', 'format' => '0 (fixo)', 'default' => '0'],
            ['p' => 'P2', 'desc' => 'Intervalo de envio', 'format' => '0=não reportar / 1=imediato / 2–64800 (segundos)', 'default' => '0'],
            ['p' => 'P3', 'desc' => 'Intervalo entre avisos de voz', 'format' => '0=sem voz / 1=imediato / 2–64800 (segundos)', 'default' => '0'],
        ],
        'exemplos' => [['cmd' => 'EVENTALERT,ANDD,0,30,60#', 'desc' => 'reporta a cada 30 s, voz a cada 60 s.']],
    ],

    // DMS — Reconhecimento facial: sucesso (AFIS) / falha (AFIF)
    'EVENTSET,AFIF,P1,P2,P3#' => [
        'cmd' => 'EVENTSET', 'nome' => 'Sensibilidade — reconhecimento facial (DMS)',
        'desc' => 'Sensibilidade de similaridade, duração e intervalo entre tentativas de reconhecimento facial.',
        'modelos' => ['JC371', 'JC182'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E036', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Sensibilidade (similaridade)', 'format' => 'OFF / 1–100 (recomendado 40–60)', 'default' => 'OFF'],
            ['p' => 'P2', 'desc' => 'Duração para reconhecimento malsucedido', 'format' => '1–255 (segundos)', 'default' => '180'],
            ['p' => 'P3', 'desc' => 'Intervalo entre tentativas', 'format' => '0=detecta só uma vez ao ligar / 1–255 (segundos)', 'default' => '0'],
        ],
        'exemplos' => [['cmd' => 'EVENTSET,AFIF,50,30,10#', 'desc' => 'exemplo da planilha.']],
    ],
    'EVENTALERT,AFIS,P1,P2,P3#' => [
        'cmd' => 'EVENTALERT', 'nome' => 'Alerta — reconhecimento facial com sucesso (DMS)',
        'desc' => 'Intervalo de envio à plataforma e de aviso de voz quando o reconhecimento facial dá certo.',
        'modelos' => ['JC371'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E037', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E038', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E039', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E040', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E041', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC371 Command List V1.0.1, linha E042', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G001', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Modo', 'format' => '0=Versão AHD / 3=Versão JC170', 'default' => '—']],
        'exemplos' => [['cmd' => 'DMSSW,3', 'desc' => 'muda para modo JC170.']],
    ],
    'DMS_SWITCH,P1,P2,P3#' => [
        'cmd' => 'DMS_SWITCH', 'nome' => 'Ativação, sensibilidade e velocidade do DMS',
        'desc' => 'Liga/desliga o DMS, define a sensibilidade geral e a velocidade a partir da qual o alinhamento facial começa.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G002', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G003', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G004', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G005', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Velocidade simulada', 'format' => '0–120 (km/h)', 'default' => '—']],
        'exemplos' => [['cmd' => 'DMS_VIRTUAL_SPEED,30', 'desc' => 'simula 30 km/h.']],
    ],
    'DMS_CONTINUITY,P1,P2,P3,P4,P5,P6#' => [
        'cmd' => 'DMS_CONTINUITY', 'nome' => 'Duração de reconhecimento contínuo por evento',
        'desc' => 'Por quanto tempo o comportamento precisa persistir antes de disparar o evento, um valor por tipo.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G006', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G007', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G008', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G009', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Ativação', 'format' => '0=Desligado / 1=Ligado', 'default' => '—']],
        'exemplos' => [['cmd' => 'ADASSW,1', 'desc' => 'liga o ADAS.']],
    ],
    'ADASSEP,P1,P2#' => [
        'cmd' => 'ADASSEP', 'nome' => 'Ativação por função de ADAS',
        'desc' => 'Liga/desliga individualmente cada função de ADAS. Requer ADASSW ligado antes.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G010', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G011', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G012', 'procedencia' => 'planilha',
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
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G013', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função', 'format' => '1=FCW+HMW (colisão frontal / veículo próximo) / 2=LDW (saída de faixa)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Velocidade mínima', 'format' => 'km/h', 'default' => 'FCW:30 / HMW:30 / LDW:60'],
        ],
        'exemplos' => [
            ['cmd' => 'ADASSP,1,60', 'desc' => 'FCW/HMW a partir de 60 km/h.'],
            ['cmd' => 'ADASSP,2,60', 'desc' => 'LDW a partir de 60 km/h.'],
        ],
    ],
    'ADASSEN,P1,P2,P3#' => [
        'cmd' => 'ADASSEN', 'nome' => 'Sensibilidade de disparo por função de ADAS',
        'desc' => 'Sensibilidade específica de cada função de ADAS — o significado de P2/P3 muda conforme a função (P1).',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G014', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Função', 'format' => '1=LDW (saída de faixa) / 2=FCW (colisão frontal) / 3=HMW (veículo muito próximo)', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Sensibilidade (significado depende de P1)', 'format' => 'P1=1: −0,3 a 0,6 (padrão −0,1; negativo=antes da faixa, positivo=depois) / P1=2 ou 3: 0–10 s, tempo até possível colisão (padrão 1,5 s para FCW, 1,0 s para HMW)', 'default' => 'LDW: −0,1 / FCW: 1,5 / HMW: 1,0'],
            ['p' => 'P3', 'desc' => 'Auxiliar (só usado quando P1=1)', 'format' => 'P1=1: 1 (fixo) / P1=2 ou 3: não preencher', 'default' => '—'],
        ],
        'exemplos' => [
            ['cmd' => 'ADASSEN,1,-0.2,1', 'desc' => 'LDW, sensibilidade −0,2.'],
            ['cmd' => 'ADASSEN,2,2.0', 'desc' => 'FCW, 2,0 s.'],
            ['cmd' => 'ADASSEN,3,2.5', 'desc' => 'HMW, 2,5 s.'],
        ],
    ],
    'ADASVSP,P1#' => [
        'cmd' => 'ADASVSP', 'nome' => 'Velocidade virtual do ADAS para simulação',
        'desc' => 'Simula uma velocidade para testar o ADAS em bancada.',
        'modelos' => ['JC400AD'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC400 & JC261 Command List V5.0.3, linha G015', 'procedencia' => 'planilha',
        'params' => [['p' => 'P1', 'desc' => 'Velocidade simulada', 'format' => '10–120 (km/h)', 'default' => '—']],
        'exemplos' => [['cmd' => 'ADASVSP,60', 'desc' => 'simula 60 km/h.']],
    ],

    // ═══════════════════════════════════════════════════════════════════════
    // JC181 — sem ADAS/DMS por visão (sem chip de IA); só evento de velocidade
    // ═══════════════════════════════════════════════════════════════════════

    'SPEED,P1,P2,P3,P4#' => [
        'cmd' => 'SPEED', 'nome' => 'Evento de excesso de velocidade',
        'desc' => 'Ativa/desativa o evento de excesso de velocidade, define modo de alerta, limite e duração acima dele.',
        'modelos' => ['JC181'], 'template' => true,
        'consulta' => null, 'fonte' => 'JC181 Command List V1.0.7, linha D003', 'procedencia' => 'planilha',
        'params' => [
            ['p' => 'P1', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => '—'],
            ['p' => 'P2', 'desc' => 'Modo de alerta', 'format' => '0=GPRS / 1=SMS+GPRS', 'default' => '—'],
            ['p' => 'P3', 'desc' => 'Limite de velocidade', 'format' => '1–255 (km/h)', 'default' => '50'],
            ['p' => 'P4', 'desc' => 'Duração acima do limite para gerar o evento', 'format' => '5–600 (segundos)', 'default' => '20'],
        ],
        'exemplos' => [['cmd' => 'SPEED,ON,0,90,10', 'desc' => 'dispara acima de 90 km/h mantidos por 10 s.']],
    ],

    // JC450 e JC182 não têm planilha própria (ver cabeçalho): a cobertura
    // deles é o que o catálogo antigo já confirmava — adicionados como
    // MODELO A MAIS nas entradas JC371 acima (não como entradas separadas):
    // JC450 em DMSSP/DMSVSP/ADAS,CALIBRATION; JC182 em EVENTSET,ACEA/ADCA/
    // AFIF/AHMW/ALDW/ANDD. Sem confirmação de EVENTALERT para nenhum dos
    // dois — por isso os pares EVENTALERT ficam só em JC371.

];
