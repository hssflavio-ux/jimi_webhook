<?php
/**
 * JIMI Webhook System — Catálogo de comandos de texto (proNo 128) v4.9.7
 *
 * GERADO a partir da wiki Foco na Via (Wiki.js, Jimi IoT Brasil):
 *   https://wiki-foconavia.newtectelemetria.com.br/
 * Páginas de origem: config-371, geral-jc371, novas_configurações_jc371,
 * IA-jc371, Configurações-JC450, Configurações-JC400AD, configuração_jc182,
 * Configuracao-181, comandos (JC400).
 *
 * ⚠️ TODOS os comandos daqui são a forma de PLATAFORMA (separador vírgula).
 * A wiki também documenta a forma de SMS (`CMD#666666#A#B`), com a senha
 * `666666` — essa NÃO se aplica aqui: o envio é por proNo 128 pelo IoT Hub,
 * onde não há senha de SMS. Mandar a forma de SMS pela plataforma faz o
 * device recusar.
 *
 * ── `consulta`: a forma de PERGUNTAR (v4.9.25) ──────────────────────────────
 *
 * 🔑 Todo comando tem uma forma NUA — `CMD#`, sem parâmetros — que LÊ o valor
 * atual em vez de escrever. Até a v4.9.24 este catálogo registrava só o setter
 * (`APN,NOME,APN#`, `SERVER,A,B,C#`), e como a tela só oferece o que está aqui,
 * **não havia como ler nada pelo canal JIMI — apenas escrever**. Das 27
 * respostas de comando gravadas em produção até 15/08/2026, nenhuma era
 * consulta. Foi por isso que a divergência do APN (o `33028` diz `cmnet`, o
 * equipamento usa `allcombl.br`) sobreviveu sem ser notada: faltava o botão de
 * perguntar. Ver `docs/COMANDOS_128_CONSULTA.md`.
 *
 *   `consulta`          — a string a enviar (`'APN#'`), ou null.
 *   `consulta_modelos`  — modelos onde ela é sabidamente aceita.
 *   `consulta_ref`      — `medido` (bateria em câmera real, 16/08/2026),
 *                         `wiki` (a página do JC400 escreve `CMD#666666`),
 *                         ou `medido+wiki`.
 *
 * A procedência importa porque `medido` e `wiki` não são a mesma confiança —
 * é a disciplina do `doc_ref` do `device_param_catalog`, aplicada aqui.
 *
 * 🔴 Comando DESTRUTIVO nunca recebe `consulta`, mesmo que a forma nua exista:
 * `REBOOT`, `RESTORE`, `RELAY`, `FORMAT`, `RESET`, `RESTART`, `UPDATE` são
 * ação, não pergunta. O invariante está travado em `tests/helpers/`.
 *
 * ⚠️ Quando um comando tem várias sintaxes (a família `EVENTSET,*`), só a
 * PRIMEIRA entrada carrega a consulta — senão a tela ofereceria vinte botões
 * idênticos.
 *
 * `modelos` = modelos cuja página documenta o comando. `universal` = o comando
 * NÃO trava a seleção de equipamentos por modelo (`aplicarTrava()`,
 * handlers/comandos.php). Normalmente é derivado: presente em >= 5 das 6
 * páginas da wiki = núcleo comum do proNo 128.
 *
 * ⚠️ A derivação tem EXCEÇÕES MANUAIS, e elas precisam sobreviver a uma
 * regeneração do catálogo por script. Hoje são duas:
 *
 *   `UPDATE,P1#` (v4.9.32) — só a página do JC371 documenta a atualização de
 *   firmware, e por isso ele nascia travado nesse modelo. O comando é o mesmo
 *   em toda a linha JC; **o que muda de um modelo para o outro é só a URL do
 *   pacote**. Travado em JC371, a tela tornava impossível atualizar as outras
 *   cinco — inclusive as JC400AD de produção, que é onde a divergência de
 *   firmware custou caro (ver v4.9.31). A trava por modelo existe para impedir
 *   "comando não suportado"; aqui ela impedia o comando suportado.
 *   Procedência: informação do fornecedor + operação, **não** a wiki — a
 *   planilha oficial `JC400 & JC261 Command List` sequer lista `UPDATE`.
 *   O invariante está travado em `tests/helpers/command_response.test.php`.
 *
 *   `CHECK#` (v4.9.40) — só a planilha do JC371 documenta a consulta, e a
 *   derivação a travaria nesse modelo. Foi MEDIDA em 20/08/2026 em produção
 *   em JC400AD (dois equipamentos), JC371 e JC182, e os quatro responderam;
 *   o JC181 estava offline e o comando virou fila, o que não é recusa.
 *   Procedência: medição em câmera real, não a wiki. É a consulta de
 *   diagnóstico mais completa do proNo 128 — firmware, servidor, endereço de
 *   upload, APN e FUSO numa resposta só — e travá-la no JC371 esconderia dos
 *   outros cinco modelos exatamente a informação que se procura quando uma
 *   câmera some. Sendo LEITURA, o custo de errar o modelo é uma recusa, não
 *   um estrago: é o inverso do `SERVER`. Travado em
 *   `tests/helpers/command_response.test.php`.
 *
 * 🔴 `universal` NÃO significa "seguro em qualquer modelo": significa "a tela
 * não escolhe o equipamento por você". No `UPDATE` a escolha errada é a da
 * URL, não a do comando — e é `/firmwares` que a resolve, cadastrando o pacote
 * com o modelo na chave.
 *
 * `template` = a sintaxe traz placeholders (P1/A) e a tela monta um campo por
 * parâmetro. Sem template, a sintaxe já é um comando pronto.
 *
 * ── Planilha oficial JIMI (v4.9.27) ────────────────────────────────────────
 *
 * A base passou a ser cruzada com `docs/JC400 & JC261 Command List
 * V5.0.3.20230626.xlsx`, a lista oficial da fabricante. **JC261 é a nossa
 * JC400AD** — a planilha usa o código de fábrica, e foi por isso que os sete
 * comandos `ADASxx` (G009–G015), marcados "Only for JC261 & JC261P", nunca
 * tinham entrado aqui, apesar de ADAS ser o núcleo do produto.
 *
 * O cruzamento é por COMANDO:ARIDADE, não por nome: comparar só o nome-base
 * esconde variante faltante de comando que já existe. Foi exatamente o caso do
 * `FILELIST` — tínhamos `FILELIST,A#` (A006), que apenas CONFIGURA o endereço
 * de destino, e faltava a forma NUA `FILELIST` (A007), que é a que manda o
 * equipamento subir a lista. Sem ela a tela só sabia configurar, e nenhuma
 * lista de gravação jamais subiu: medido em três câmeras em 18/08/2026, as
 * três responderam e chamaram de volta assim que a forma nua foi enviada.
 *
 * `fonte` guarda a linha de origem na planilha (A007, G014…).
 *
 * Total: 193 entradas / 143 comandos distintos (16 universais), 63 com consulta.
 * Por categoria: alarme=45, audio=4, ia=16, manutencao=21, outros=24, posicao=25, rede=22, video=36.
 *
 * ⚠️ Estes números eram 219/143/video=29 e estavam ERRADOS desde a v4.9.27 — o
 * arquivo já tinha 220/144/video=30 antes da v4.9.32. Contagem em comentário
 * envelhece sem avisar; `tests/helpers/command_response.test.php` passou a
 * conferi-la contra o array de verdade.
 *
 * ── v4.13.0 — 45 comandos de ADAS/DMS/velocidade saíram daqui ───────────────
 * `EVENTSET`/`EVENTALERT` de eventos ADAS/DMS, `DMSSP`, `DMSSW,P1,P2#`,
 * `DMSVSP`, `ADAS,CALIBRATION`, `DMS_SWITCH`, `DMS_VOICE_CUSTOM`,
 * `DMS_ALERT_CUSTOM`, `DMS_VIRTUAL_SPEED` e `DMS_CONTINUITY` foram embora —
 * moram exclusivamente em `includes/ia_config_catalog.php`, reprocessado do
 * zero direto das planilhas oficiais (não copiado daqui), na tela
 * "Configurações IA". Comando de ADAS/DMS que sobrou aqui é DE PROPÓSITO:
 * `EVENTSET,FACE` (CRUD de biblioteca facial) e os de colisão/vibração por
 * acelerômetro (`CRASHALM`, `SENSOR`, `SHOCK`, `COLLIDE`…) não são
 * "configuração de parâmetro de ADAS/DMS/velocidade" — ver o cabeçalho do
 * catálogo novo para a lista completa do que ficou de fora e por quê.
 */

return [
  'ACC,ON,5#' => [
    'cmd' => 'ACC',
    'nome' => 'Tipo de detecção ACC',
    'desc' => '',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC400D',
      4 => 'JC400AD',
    ],
    'universal' => true,
    'template' => false,
    'consulta' => 'ACC#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'medido',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'ACCREP,A#' => [
    'cmd' => 'ACCREP',
    'nome' => 'Posição por ACC',
    'desc' => 'Para que seja enviado posição quando houver alteração de ACC, envie',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC181',
      1 => 'JC400D',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'ACCREP#',
    'consulta_modelos' => ['JC181', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => 'OFF Para consulta, envie: ACCREP#666666',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'ACCV,280,5,129,120#' => [
    'cmd' => 'ACCV',
    'nome' => 'Limites de tensão ACC',
    'desc' => '',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'ACCV#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'medido',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'ANGLEREP,A,B#' => [
    'cmd' => 'ANGLEREP',
    'nome' => 'Posição por ângulo',
    'desc' => 'Para configurar o envio de posição por ângulo, envie',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => true,
    'consulta' => 'ANGLEREP#',
    'consulta_modelos' => ['JC181', 'JC182', 'JC371', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Ângulo(5~45º)',
        'format' => '',
        'default' => 'ON,5 - Em algumas situações, pode ocorre',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ANGLEREP,30#',
        'desc' => 'Definir mudança de ângulo para envio adicional de localização',
      ],
    ],
  ],
  'APN,NOME,APN#' => [
    'cmd' => 'APN',
    'nome' => '2 - Configurações',
    'desc' => 'Para configurar a APN no equipamento, envie',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => false,
    'consulta' => 'APN#',
    'consulta_modelos' => ['JC181', 'JC182', 'JC371', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'ASETAPN,P1#' => [
    'cmd' => 'ASETAPN',
    'nome' => 'APN automática',
    'desc' => 'Liga ou desliga a seleção automática de APN. P1: ON/OFF, padrão ON.',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'ASETAPN#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'medido',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'BCD,P1#' => [
    'cmd' => 'BCD',
    'nome' => 'Formato do identificador',
    'desc' => '0: BCD hexadecimal (Tracksolid Pro, JT/T 808-2013); 1: últimos 12 dígitos do IMEI. Padrão 0.',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC182',
      1 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'BCD#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'medido',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'CAMERA,TF#' => [
    'cmd' => 'CAMERA',
    'nome' => 'Espaço do cartão',
    'desc' => 'Devolve espaço de memória total e livre.',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC182',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'CAMERA#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'medido',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  // v4.13.12 — reescrito a partir de docs/JC181_Command_List_V1.0.7_20250811.xlsx,
  // linha D006 (a versão anterior desta entrada tinha só 4 parâmetros vazios,
  // sem desc/format — a planilha real documenta 8).
  // v4.13.15 — JC182 REMOVIDO: chegou a ser adicionado (v4.13.12) por pedido
  // do dono do produto, mas a planilha-fonte é explicitamente "applicable to
  // JC181 series products" e nunca cita o JC182 em nenhuma linha. O dono do
  // produto confirmou (26/08/2026) que o JC182, apesar do número de modelo
  // maior, tem bem menos funções que o JC181 — o único código de colisão que
  // ele de fato responde é `EVENTSET,ACD` (dialeto EVENTSET/JT/T, abaixo),
  // não este `COLLIDE` de texto simples.
  'COLLIDE,P1,P2,P3,P4,P5,P6,P7,P8#' => [
    'cmd' => 'COLLIDE',
    'nome' => 'Colisão',
    'desc' => 'Sensibilidade para disparar alerta de colisão durante a condução. Abaixo do limiar de velocidade o evento é tratado como alarme falso.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC181',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'COLLIDE#',
    'consulta_modelos' => ['JC181'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Ativação',
        'format' => 'ON / OFF',
        'default' => 'ON',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Forma de envio do alarme',
        'format' => '0 = GPRS / 1 = SMS+GPRS',
        'default' => '0',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Sensibilidade de disparo',
        'format' => '0–255',
        'default' => '120',
      ],
      3 => [
        'p' => 'P4',
        'desc' => 'Atraso antes de checar a velocidade',
        'format' => '0–20 (segundos)',
        'default' => '0',
      ],
      4 => [
        'p' => 'P5',
        'desc' => 'Tempo de checagem — confirma colisão se a velocidade ficar abaixo do limiar por este tempo',
        'format' => '10–90 (segundos)',
        'default' => '15',
      ],
      5 => [
        'p' => 'P6',
        'desc' => 'Limiar de velocidade para confirmar colisão',
        'format' => '5–30 (km/h)',
        'default' => '5',
      ],
      6 => [
        'p' => 'P7',
        'desc' => 'Taxa mínima de variação de aceleração',
        'format' => '0–100',
        'default' => '70',
      ],
      7 => [
        'p' => 'P8',
        'desc' => 'Taxa de variação de aceleração acima da qual dispensa a dupla confirmação',
        'format' => '2–300',
        'default' => '90',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'COLLIDE,ON,0,600,10,90,5#',
        'desc' => 'exemplo literal da planilha — ⚠️ tem só 6 valores após o cabeçalho para 8 campos documentados; a planilha do próprio fabricante está inconsistente aqui (mesma classe de erro já vista em outros comandos, ver CLAUDE.md "doc mente, meça no device"). Confirmar arity real em câmera antes de usar em produção.',
      ],
    ],
  ],
  'CRASHALM,A,B#' => [
    'cmd' => 'CRASHALM',
    'nome' => 'Colisão',
    'desc' => 'Para configurar o envio de eventos de colisão, envie',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC450',
      1 => 'JC400D',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'CRASHALM#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '1/2/3Refere-se à sensibilidade do gatilho:',
        'format' => '',
        'default' => 'ON B = 1/2/3Refere-se à sensibilidade do',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'CRASHALM,ON,3#',
        'desc' => 'Colisão',
      ],
    ],
  ],
  'DISCAMERA#' => [
    'cmd' => 'DISCAMERA',
    'nome' => 'Consulta',
    'desc' => '',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'DISCAMERA#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'medido',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'DISK,P1#' => [
    'cmd' => 'DISK',
    'nome' => 'Modo disco (USB)',
    'desc' => 'Ativa o modo de disco. Para desativar, P1=0 e reiniciar o equipamento.',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'DISK#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'EVENTALERT,ACDU,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Alerta na plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF = não enviar / 1 = imediato / 2–64800 s',
        'default' => '60',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF = sem voz / 1 = imediato / 2–64800 s',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ACDU,0,30,60#',
        'desc' => 'Exemplo:EVENTALERT,ACDU,0,30,60# → envia evento a cada 30s, voz a cada 60s.',
      ],
    ],
  ],
  'EVENTALERT,ADW,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Configura o envio do evento para a plataforma e o alerta de voz. Em repetições, um novo envio ocorre após P2 e um novo aviso de voz após P3.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Alerta na plataforma',
        'format' => '0',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio do evento',
        'format' => '0 / 1–64800 s; 0/OFF = não enviar; 1 = enviar imediatamente',
        'default' => '120',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo entre avisos de voz',
        'format' => '0 / 1–64800 s; 0/OFF = sem voz; 1 = voz imediata',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ADW,0,30,60#',
        'desc' => 'Envia a cada 30 s e fala a cada 60 s quando ocorrerem eventos de distração. (Parâmetros P2/P3 idênticos aos demais eventos.)',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ADW,0,120,5#',
        'desc' => 'Usa os padrões (envio 120 s; voz 5 s).',
      ],
    ],
  ],
  'EVENTALERT,AEPLV,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio',
    'desc' => '⚠️ Este evento não suporta voz.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Alerta na plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF = não enviar / 1 = imediato / 2–64800 s',
        'default' => '600',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Voz',
        'format' => 'Sempre OFF',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AEPLV,0,3600,OFF#',
        'desc' => 'Exemplo:EVENTALERT,AEPLV,0,3600,OFF# → envia a cada 1h, sem voz.',
      ],
    ],
  ],
  'EVENTALERT,AHADU,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Alerta na plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF = não enviar / 1 = imediato / 2–64800 s',
        'default' => '60',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF = sem voz / 1 = imediato / 2–64800 s',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AHADU,0,30,60#',
        'desc' => 'Exemplo:EVENTALERT,AHADU,0,30,60# → envia a cada 30s e voz a cada 60s.',
      ],
    ],
  ],
  'EVENTALERT,AHBDU,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Alerta na plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF = não enviar / 1 = imediato / 2–64800 s',
        'default' => '60',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF = sem voz / 1 = imediato / 2–64800 s',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AHBDU,0,30,60#',
        'desc' => 'Exemplo:EVENTALERT,AHBDU,0,30,60# → envia evento a cada 30s, voz a cada 60s.',
      ],
    ],
  ],
  'EVENTALERT,AHTDU,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Alerta na plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF = não enviar / 1 = imediato / 2–64800 s',
        'default' => '60',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF = sem voz / 1 = imediato / 2–64800 s',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AHTDU,0,30,60#',
        'desc' => 'Exemplo:EVENTALERT,AHTDU,0,30,60# → envia evento a cada 30s, voz a cada 60s.',
      ],
    ],
  ],
  'EVENTALERT,AODD,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Alerta na plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF = não enviar / 1 = imediato / 2–64800 s',
        'default' => '3600',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF = sem voz / 1 = imediato / 2–64800 s',
        'default' => '600',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AODD,0,3600,600#',
        'desc' => 'Exemplo:EVENTALERT,AODD,0,3600,600# → envia a cada 1h e voz a cada 10 min.',
      ],
    ],
  ],
  'EVENTALERT,ASM,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Define envio do evento de fumo para a plataforma e aviso de voz.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Alerta para plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF = não enviar / 1 = imediato / 2–64800 s',
        'default' => '120',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF = sem voz / 1 = imediato / 2–64800 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ASM,0,120,5#',
        'desc' => 'Envia evento a cada 120 s e voz a cada 5 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ASM,0,30,60#',
        'desc' => 'Envia evento a cada 30 s e voz a cada 60 s.',
      ],
      2 => [
        'cmd' => 'EVENTALERT,ASM#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTALERT,AVD,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio',
    'desc' => '⚠️ Este evento não suporta voz.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Alerta na plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF = não enviar / 1 = imediato / 2–64800 s',
        'default' => '60',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Voz',
        'format' => 'Sempre OFF',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AVD,0,30,OFF#',
        'desc' => 'Exemplo:EVENTALERT,AVD,0,30,OFF# → envia a cada 30s, sem voz.',
      ],
    ],
  ],
  // v4.13.12 — corrigido: a entrada tinha o valor "80" cravado na CHAVE
  // (template=>false, params=>[]), o que impedia enviar qualquer sensibilidade
  // diferente de 80 pela tela. Confirmado pelo dono do produto em campo
  // (26/08/2026): é um dos 3 códigos EVENTSET que o JC182 de fato responde
  // (junto de AVD/vibração acima e AOSD/velocidade em
  // includes/ia_config_catalog.php — os únicos 3; os demais códigos ADAS/DMS
  // do JC371 foram removidos da tela de Configurações IA para este modelo).
  // Sem planilha própria para o significado exato de P1 — "80" era o único
  // valor visto; mantido como default, faixa não confirmada.
  'EVENTSET,ACD,P1#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Colisão',
    'desc' => 'Sensibilidade do evento de colisão (dialeto EVENTSET/JT/T do JC182 e JC371).',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'EVENTSET,ACD#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'medido',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Sensibilidade',
        'format' => 'valor visto em campo: 80 — faixa completa não confirmada',
        'default' => '80',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,ACD,80#',
        'desc' => 'valor visto em campo no JC182 (26/08/2026).',
      ],
    ],
  ],
  'EVENTSET,ACDU,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Limite de aceleração do impacto',
        'format' => '10–20 m/s²',
        'default' => '20',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Ângulo de inclinação do veículo',
        'format' => '15–50°',
        'default' => '20°',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,ACDU,20,20#',
        'desc' => 'Exemplo:EVENTSET,ACDU,20,20# → evento se impacto horizontal atingir 20 m/s² com inclinação superior a 20°.',
      ],
    ],
  ],
  'EVENTSET,AEPLV,P1,P2,P3#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Tensão baixa',
        'format' => 'OFF / 10–360 (x0,1V)',
        'default' => '115 (11,5V)',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Tensão normal (reset)',
        'format' => '10–360 (x0,1V)',
        'default' => '120 (12,0V)',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Tempo de detecção',
        'format' => '1–300 s',
        'default' => '10',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AEPLV,115,120,10#',
        'desc' => 'Exemplo:EVENTSET,AEPLV,115,120,10# → evento se <11,5V por 10s, limpa ao voltar >12,0V.',
      ],
      1 => [
        'cmd' => 'EVENTSET,AEPLV,120,125,30#',
        'desc' => 'Bateria fraca',
      ],
    ],
  ],
  'EVENTSET,AHADU,P1#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Aceleração limite',
        'format' => '1,0–5,0 m/s²',
        'default' => '3,5',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'EVENTSET,AHBDU,P1#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Limite de desaceleração',
        'format' => '–6,0 a –1,0 m/s² (valor negativo)',
        'default' => '–4,5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AHBDU,-4.5#',
        'desc' => 'Exemplo:EVENTSET,AHBDU,-4.5# → evento se a desaceleração for maior que –4,5 m/s².',
      ],
    ],
  ],
  'EVENTSET,AHTDU,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Limite de aceleração lateral',
        'format' => '2,0–6,0 m/s²',
        'default' => '4,0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Ângulo de mudança de direção',
        'format' => '30–80°',
        'default' => '45°',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AHTDU,4.0,45#',
        'desc' => 'Exemplo:EVENTSET,AHTDU,4.0,45# → evento se curva for feita com aceleração lateral de 4,0 m/s² em ângulo maior que 45°.',
      ],
    ],
  ],
  'EVENTSET,AODD,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Tempo de condução contínua (ACC ON)',
        'format' => 'OFF / 1–24 h',
        'default' => '4',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Descanso para limpar evento (ACC OFF)',
        'format' => '0–64800 min',
        'default' => '10',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AODD,4,15#',
        'desc' => 'Exemplo:EVENTSET,AODD,4,15# → evento após 4h dirigindo, limpa após 15 min parado.',
      ],
    ],
  ],
  'EVENTSET,ASM,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa ou desativa a detecção de cigarro/fumo e define as condições de disparo. O evento é gerado se fumar for detectado por pelo menos P2 segundos no nível de sensibilidade P1.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Nível de similaridade (threshold)',
        'format' => 'OFF (Desativado) / 1 (Alto) / 2 (Médio) / 3 (Baixo) / Nota: quanto maior o valor, menor a exigência de similaridade para detectar cigarros.',
        'default' => '2',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração mínima de fumo para disparar o evento',
        'format' => '1–255 s',
        'default' => '3',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'EVENTSET,AVD,P1,P2,P3,P4#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Sensibilidade',
        'format' => 'OFF / 1–5 (quanto menor, mais sensível)',
        'default' => '3',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Tempo de detecção',
        'format' => '1–300 s',
        'default' => '10',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Nº de vibrações',
        'format' => '1–20',
        'default' => '5',
      ],
      3 => [
        'p' => 'P4',
        'desc' => 'Filtro de alarme',
        'format' => '10–60 s',
        'default' => '30',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AVD,3,10,5,30#',
        'desc' => 'Exemplo:EVENTSET,AVD,3,10,5,30# → gera evento se houver 5 vibrações em 10s, após 30s em ACC OFF.',
      ],
      1 => [
        'cmd' => 'EVENTSET,AVD,2,15,5,25#',
        'desc' => 'Vibração/estacionamento',
      ],
    ],
  ],
  'EVENTSET,FACE,P1,P2,P3#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Gerenciar Biblioteca Facial',
    'desc' => 'Gerencia a biblioteca de rostos usada no reconhecimento facial.',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Tipo de operação',
        'format' => 'DOWN = importar pacote .zip / SHOT = capturar selfie / DEL = excluir rosto / CHECK = consultar biblioteca / TEST = testar reconhecimento',
        'default' => '',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'ID ou URL',
        'format' => 'Para SHOT/DEL = ID único (até 24 caracteres) / Para DOWN = URL do pacote zip',
        'default' => '',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Nome do rosto',
        'format' => 'Obrigatório em SHOT/DEL (até 24 caracteres)',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,FACE,SHOT,1,RICK#',
        'desc' => 'salva selfie como ID=1, nome=RICK.',
      ],
      1 => [
        'cmd' => 'EVENTSET,FACE,DOWN,http://servidor/face.zip#',
        'desc' => 'importa pacote de rostos.',
      ],
      2 => [
        'cmd' => 'EVENTSET,FACE,CHECK#',
        'desc' => 'lista rostos cadastrados.',
      ],
      3 => [
        'cmd' => 'EVENTSET,FACE,DEL,1,RICK#',
        'desc' => 'exclui rosto ID=1, nome=RICK.',
      ],
    ],
  ],
  'EXDEVICESW,A#' => [
    'cmd' => 'EXDEVICESW',
    'nome' => 'RFID',
    'desc' => 'Para configurar o uso de RFID no equipamento, envie',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'EXDEVICESW#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1/2',
        'format' => '',
        'default' => '0 Para consulta, envie: EXDEVICESW#66666',
      ],
    ],
    'exemplos' => [
    ],
  ],
  // v4.13.12 — reescrito a partir de docs/JC181_Command_List_V1.0.7_20250811.xlsx,
  // linha D010. A chave já indicava 3 parâmetros (A,T1,T2) mas só "A" tinha
  // desc/format — T1/T2 eram placeholders sem documentação nenhuma, e a
  // planilha real tem 4 campos (exemplo "FATIGUE,ON,6,15,0" confirma).
  // v4.13.15 — JC182 REMOVIDO: a planilha-fonte é exclusiva do JC181
  // ("applicable to JC181 series products", nunca cita JC182); o dono do
  // produto confirmou (26/08/2026) que o JC182 tem bem menos funções que o
  // JC181, apesar do número de modelo maior.
  'FATIGUE,P1,P2,P3,P4#' => [
    'cmd' => 'FATIGUE',
    'nome' => 'Fadiga (direção por tempo excessivo)',
    'desc' => 'Configura o limiar de horas dirigindo sem parar que dispara o evento de fadiga.',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC181',
      1 => 'JC400D',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'FATIGUE#',
    'consulta_modelos' => ['JC181', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Ativação',
        'format' => 'ON / OFF',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Tempo dirigindo sem parar que dispara o evento',
        'format' => '4–12 (horas)',
        'default' => '4',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Tempo mínimo de parada para zerar a contagem',
        'format' => '1–30 (minutos)',
        'default' => '30',
      ],
      3 => [
        'p' => 'P4',
        'desc' => 'Forma de envio do alarme',
        'format' => '0 = GPRS / 1 = SMS+GPRS',
        'default' => '0',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'FATIGUE,ON,6,15,0#',
        'desc' => 'dispara após 6 h dirigindo sem parar por ao menos 15 min.',
      ],
    ],
  ],
  'FILELIST,A#' => [
    'cmd' => 'FILELIST',
    'nome' => 'Lista de gravações do cartão (JIMI)',
    // 🔴 CORRIGIDO na v4.9.38. A descrição anterior dizia que este comando
    // "manda a câmera subir a lista", e ele NÃO faz isso — é a descrição do
    // `FILELIST` nu (A007) colada na entrada errada. O texto oficial da planilha
    // é inequívoco: "Modify the server address to receive the playback video
    // namelist file." Esta forma só GRAVA o endereço; quem pede o upload é a
    // forma sem parâmetro. A confusão custou uma tela que reconfigurava o
    // equipamento a cada consulta de gravações.
    'desc' => 'CONFIGURA o endereço para onde a câmera envia a lista de gravações (grava no equipamento; não pede a lista). Quem pede o upload é o comando FILELIST sem parâmetro.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'URL HTTP que recebe a lista (termine com o IMEI da câmera)',
        'format' => 'http://<servidor>/filelist/<IMEI>',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'FILELIST,http://186.248.143.197/filelist/862798051583785',
        'desc' => 'Pede à câmera a lista de gravações do cartão',
      ],
    ],
  ],
  // ══ Da planilha JC371 V1.0.1 (v4.9.40) ═══════════════════════════════════
  //
  // Cruzamento por COMANDO:ARIDADE contra `docs/JC 371 Command List V1.0.1.xlsx`,
  // feito para responder a uma pergunta que a planilha NÃO responde: se haveria
  // ali o comando de PARAR o playback. Não há — a planilha é toda de
  // configuração e consulta, e o controle de stream do JC371 vive no binário do
  // JT/T 1078 (`37378`), não no proNo 128.
  //
  // O cruzamento achou 18 buracos, em duas naturezas bem diferentes:
  //
  //   • SETE comandos cujo NOME não existia aqui (CHECK, CHECKVIDEO,
  //     STATUSVIDEO, SENSORSET, SHUTDOWNTIME, VIDEORSL_SUB, VIDETIMEZONE);
  //   • ONZE variantes de ARIDADE — o nome já existia, aquela sintaxe não.
  //     São o buraco que comparar só o nome-base NUNCA mostra, o mesmo tipo que
  //     escondeu a forma nua do `FILELIST` por meses (v4.9.27).
  //
  // ⚠️ A variante de aridade divide o nome com uma entrada mais antiga, e por
  // isso NENHUMA delas carrega `consulta`: a regra do cabeçalho é que só a
  // PRIMEIRA sintaxe de um comando traz a forma de perguntar, senão a tela
  // ofereceria dois botões idênticos de "consultar APN".
  //
  // 🔴 Escolher a variante de aridade errada não dá erro — o equipamento aceita
  // o que entende e ignora o resto. É a trava por modelo (`aplicarTrava()`) que
  // impede mandar a sintaxe de três campos do JC371 para uma JC400 que espera
  // dois. Por isso estas entradas nascem presas ao JC371, mesmo quando o nome
  // do comando é universal em outra sintaxe (`TIMER`, `ANGLEREP`, `SERVER`).

  // ── Consultas: as três formas de PERGUNTAR do JC371 ───────────────────────

  'CHECK#' => [
    'cmd' => 'CHECK',
    'nome' => 'Informação abrangente do equipamento',
    //
    // ★ SEGUNDA EXCEÇÃO MANUAL de `universal` — ver o cabeçalho deste arquivo.
    //
    // A planilha que documenta o `CHECK` é a do JC371, e só ela: pela derivação
    // automática (presente em 5+ das 6 páginas da wiki) ele nasceria travado
    // nesse modelo. Mas foi MEDIDO em 20/08/2026 em produção, em três modelos,
    // e os três responderam — o que a derivação não tinha como saber:
    //
    //   JC400AD (864993060429173) VERSION:KMC28_0_0_STD_JM_C261_V1.8.0.9_...
    //   JC400AD (864993060392306) VERSION:KMC28_0_0_STD_JM_C261_V1.8.1.3_...
    //   JC371   (865478070003241) VERSION:C371_0_0_STD_JM_JC371_V1.9.0.2b_...
    //   JC182   (869058070151343) IMEI:...;VERSION:C182_WEBP_VY_1_V1.2.5.2_...
    //
    // O JC181 estava offline e o comando virou fila — não é recusa. JC450 e
    // JC400D não foram alcançados. `universal` aqui vale o que sempre valeu:
    // "a tela não escolhe o equipamento por você", e o comando é de LEITURA —
    // o risco de mandá-lo a um modelo que não o conheça é uma recusa, não um
    // estrago. É o oposto do `SERVER`, onde errar tira a câmera da plataforma.
    //
    // ⚠️ NO JC181 ELE É CARO, e isso está medido — em 16/08/2026, na bateria de
    // consultas da v4.9.25, o `CHECK#` estourou os 30 s do hub e DERRUBOU a
    // sessão JIMI do equipamento: nove respostas boas antes dele, zero depois
    // (`docs/COMANDOS_128_CONSULTA.md` §6). Não é motivo para travá-lo — o
    // efeito é a sessão daquele equipamento, que volta sozinha, e ele responde
    // rápido nos outros modelos —, mas é motivo para não varrer a frota inteira
    // com ele em rajada. Espaçar os disparos, como manda a mesma §6.
    //
    // 🔑 A resposta é o retrato mais completo que o proNo 128 dá de uma câmera,
    // e cada linha dela já respondeu uma pergunta que custou dias aqui:
    //   VERSION  — a MESMA string que o `VERSION#` devolve (conferido nos dois
    //              modelos, 20/08/2026), por isso `firmware_capture()` aceita a
    //              resposta do CHECK e grava a versão sem risco de divergir
    //              na comparação por igualdade que `/firmwares` faz;
    //   UPLOAD   — o endereço para onde a câmera sobe arquivo (é o que falta
    //              conferir na 400D, que aceita o `FILELIST` e nunca sobe);
    //   SERVER   — para onde ela aponta, com a porta (21100 na linha 400,
    //              21122 no JC371/JC182 — divergem por modelo);
    //   TIMEZONE — `-3:00`, o fuso do equipamento. É a confirmação, pela boca
    //              do device, da convenção que o parser do FILELIST assume ao
    //              ler o carimbo dos nomes (`includes/filelist.php`).
    'desc' => 'Retrato completo do equipamento numa resposta só: firmware, IMEI, ICCID, IMSI, servidor e porta, endereço de upload, APN, wifi, volume, LED e FUSO configurado. É a consulta de diagnóstico mais completa do proNo 128 — e não escreve nada.',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
      2 => 'JC181',
      3 => 'JC400D',
      4 => 'JC400AD',
      5 => 'JC450',
    ],
    'universal' => true,
    'template' => false,
    'consulta' => 'CHECK#',
    'consulta_modelos' => ['JC371', 'JC182', 'JC400AD'],
    'consulta_ref' => 'medido+planilha',
    'fonte' => 'planilha JC371 V1.0.1 A003',
    'params' => [],
    'exemplos' => [
      0 => ['cmd' => 'CHECK#', 'desc' => 'firmware, servidor, upload, APN e fuso de uma vez'],
    ],
  ],
  'STATUSVIDEO#' => [
    'cmd' => 'STATUSVIDEO',
    'nome' => 'Estado do módulo de vídeo',
    // MEDIDO em 20/08/2026 na 371_3241:
    //   Network: Connected;GSM: Dial Success;GPS: Not located;ACC: ON;
    //   Battery: 11.91v;Time Zone: UTC-03:00;Time: 2026-08-20 22:53:03;
    //   On video: CHN1 CHN2 CHN3;Camera insertion: CHN1 CHN2 CHN3;
    //   TF: 2.2/119.1G;EMMC: 2.0/97.9G;CPU Temperature: 59.514;Memory: 80/121M
    //
    // 🔑 `On video` × `Camera insertion` são coisas DIFERENTES: uma câmera pode
    // estar conectada e não estar gravando. Quando a barra do playback vier
    // vazia num canal, esta é a pergunta que separa "não gravou" de "não tem
    // câmera" — e nenhuma outra resposta do proNo 128 diz isso.
    'desc' => 'Estado do módulo de vídeo: rede, GSM, GPS, ACC, bateria, fuso e hora do equipamento, canais GRAVANDO, câmeras CONECTADAS, espaço no cartão TF e no eMMC, temperatura da CPU e memória.',
    'categoria' => 'manutencao',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => false,
    'consulta' => 'STATUSVIDEO#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'medido+planilha',
    'fonte' => 'planilha JC371 V1.0.1 A001',
    'params' => [],
    'exemplos' => [
      0 => ['cmd' => 'STATUSVIDEO#', 'desc' => 'canais gravando, espaço no cartão e fuso'],
    ],
  ],
  'CHECKVIDEO#' => [
    'cmd' => 'CHECKVIDEO',
    'nome' => 'Configuração da câmera (servidor, BCD, DMS/ADAS)',
    // MEDIDO em 20/08/2026 na 371_3241, que respondeu:
    //   SERVER,186.248.143.197,21122,,@@BCD,0@@DMSSW,DMS@@DMSSP,ADAS,60,0,...
    // O separador é `@@`, e cada bloco é um comando na sintaxe de escrita — o
    // device devolve a configuração como a lista de comandos que a reproduz.
    //
    // ⚠️ NÃO vale na linha JC400: relatado do campo, e a planilha
    // `JC400 & JC261 Command List V5.0.3` não lista o comando. Fica travado no
    // JC371 de propósito — é o contrário do `CHECK#` logo acima, e a diferença
    // entre os dois é o que a trava por modelo existe para carregar.
    'desc' => 'Lê a configuração de vídeo: servidor e porta, tipo de ID (BCD) e os parâmetros de DMS/ADAS, na forma dos próprios comandos de escrita. Só JC371.',
    'categoria' => 'video',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => false,
    'consulta' => 'CHECKVIDEO#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'medido+planilha',
    'fonte' => 'planilha JC371 V1.0.1 A004',
    'params' => [],
    'exemplos' => [
      0 => ['cmd' => 'CHECKVIDEO#', 'desc' => 'servidor, BCD e a configuração de DMS/ADAS'],
    ],
  ],

  // ── Configuração exclusiva do JC371 ───────────────────────────────────────

  'SENSORSET,A,B,C,D#' => [
    'cmd' => 'SENSORSET',
    'nome' => 'Lógica de detecção de vibração',
    // Duas funções no mesmo comando, e o `C` é o que gera ALARME:
    // A/B decidem quando o GPS acorda para avaliar a ignição (o "ACC por
    // software"); C decide quantas vibrações disparam o alerta de vibração.
    // Fica em `posicao` para ficar ao lado do `ACC`, que é o que ele afeta.
    'desc' => 'Quantas vibrações, em quanto tempo, acordam o GPS para avaliar a ignição (ACC por software) e quantas disparam o alerta de vibração. Padrão de fábrica: SENSORSET,10,3,5,1.',
    'categoria' => 'posicao',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 A020',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Janela de detecção, em segundos', 'format' => '1-300', 'default' => '10'],
      1 => ['p' => 'B', 'desc' => 'Vibrações na janela que acordam o GPS para avaliar a ignição', 'format' => '1-20', 'default' => '3'],
      2 => ['p' => 'C', 'desc' => 'Vibrações na janela que disparam o alerta de vibração', 'format' => '1-20', 'default' => '5'],
      3 => ['p' => 'D', 'desc' => 'Intervalo entre detecções, em segundos', 'format' => '1-3', 'default' => '1'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'SENSORSET,10,3,5,1#', 'desc' => 'o padrão de fábrica (exemplo oficial A020)'],
    ],
  ],
  'SHUTDOWNTIME,A#' => [
    'cmd' => 'SHUTDOWNTIME',
    'nome' => 'Gravação após desligar a ignição',
    // ⚠️ A FAIXA MUDA DE MODELO PARA MODELO: JC371 aceita 10-86400, JC181
    // aceita 1-86400. Duas planilhas, dois mínimos — o campo declara o do
    // JC371, que é o mais restritivo dos dois.
    'desc' => 'Por quantos segundos o equipamento continua gravando depois de a ignição ser desligada. Padrão 10 segundos.',
    'categoria' => 'video',
    'modelos' => [0 => 'JC371', 1 => 'JC181'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 A021 + JC181 V1.0.7 A026',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Segundos de gravação após a ignição desligar', 'format' => '10-86400 (JC181: 1-86400)', 'default' => '10'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'SHUTDOWNTIME,600#', 'desc' => 'segue gravando 10 minutos (exemplo oficial A021)'],
    ],
  ],
  'VIDEORSL_SUB,A,B,C,D,E#' => [
    'cmd' => 'VIDEORSL_SUB',
    'nome' => 'Qualidade do vídeo ao vivo / histórico / evento',
    // ⚠️ É o IRMÃO do `VIDEORSL`, e a diferença importa: o `VIDEORSL` trata da
    // gravação no cartão TF (o que a barra do playback lista), este trata do
    // que SAI da câmera — o stream ao vivo, o histórico e o vídeo de evento.
    // Mexer num não mexe no outro.
    'desc' => 'Resolução, taxa de quadros, bitrate e codec do vídeo que SAI da câmera (ao vivo, histórico e evento), por canal. Não confunda com o VIDEORSL, que é a gravação no cartão TF.',
    'categoria' => 'video',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 B002',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Canal — 1: CH1 (via, frontal), 2: CH2 (USB, interna), 3: CH3 (DMS, motorista)', 'format' => '1/2/3', 'default' => '1'],
      1 => ['p' => 'B', 'desc' => 'Resolução', 'format' => '480/1080', 'default' => '480'],
      2 => ['p' => 'C', 'desc' => 'Quadros por segundo', 'format' => '5-15', 'default' => '15'],
      3 => ['p' => 'D', 'desc' => 'Bitrate em Mbps', 'format' => '0.1-2', 'default' => '0.5'],
      4 => ['p' => 'E', 'desc' => 'Codec — 1: H.264, 2: H.265', 'format' => '1/2', 'default' => '1'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'VIDEORSL_SUB,1,480,15,0.5,1#', 'desc' => 'exemplo oficial (B002)'],
    ],
  ],
  'VIDETIMEZONE,A,B,C#' => [
    'cmd' => 'VIDETIMEZONE',
    'nome' => 'Sincronismo de hora e fuso do vídeo',
    // ⚠️ O NOME ESTÁ ASSIM MESMO NA PLANILHA — `VIDETIMEZONE`, sem o segundo
    // `O` —, e o EXEMPLO da mesma linha escreve `VIDEOTIMEZONE`. A fabricante
    // documenta as duas grafias na MESMA célula. O catálogo já tinha
    // `VIDEOTIMEZONE,W,3,0#` vindo da wiki, que é a grafia do exemplo; esta
    // entrada guarda a do campo Formato. Mandar a grafia que o firmware não
    // conhece é comando aceito e ignorado, sem erro — por isso as duas ficam,
    // cada uma com a sua procedência, em vez de eu escolher no palpite.
    //
    // 🔑 O fuso configurado aqui é o que o equipamento carimba nos nomes dos
    // arquivos do FILELIST (`includes/filelist.php` assume UTC-3). Trocá-lo
    // desloca a barra do playback em silêncio.
    'desc' => 'Modo de sincronismo de hora do módulo de vídeo. AUTO usa a hora da rede (NITZ); E/W fixam o fuso a leste ou a oeste de GMT. ⚠️ É o fuso que a câmera carimba no nome dos vídeos.',
    'categoria' => 'video',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 A006',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'E: leste de GMT, W: oeste de GMT, AUTO: sincronismo pela rede (NITZ)', 'format' => 'E/W/AUTO', 'default' => 'W'],
      1 => ['p' => 'B', 'desc' => 'Horas de deslocamento — só no modo manual', 'format' => '0-12', 'default' => '3'],
      2 => ['p' => 'C', 'desc' => 'Minutos de deslocamento — só no modo manual', 'format' => '0/15/30/45', 'default' => '0'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'VIDETIMEZONE,W,3,0#', 'desc' => 'UTC-3, o fuso do Brasil (exemplo oficial A006)'],
    ],
  ],

  // ── Variantes de ARIDADE: o nome já existia, esta sintaxe não ─────────────

  'KEYFUN,A,B#' => [
    'cmd' => 'KEYFUN',
    'nome' => 'Função do botão — toque curto e toque longo',
    'desc' => 'O que o botão físico faz. A é o toque curto (0,5 s), B é o toque longo (3 s). ⚠️ Duas sintaxes: esta, de dois campos, é a da planilha do JC371; a de três campos vem da wiki.',
    'categoria' => 'outros',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 A009',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Toque curto (0,5 s) — 0: nada, 1: mudo, 2: gravação, 3: SOS', 'format' => '0/1/2/3', 'default' => '1'],
      1 => ['p' => 'B', 'desc' => 'Toque longo (3 s) — 0: nada, 1: mudo, 2: gravação, 3: SOS', 'format' => '0/1/2/3', 'default' => '2'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'KEYFUN,1,2#', 'desc' => 'toque curto muda, toque longo liga/desliga a gravação (A009)'],
    ],
  ],
  'APN,A,B,C,D#' => [
    'cmd' => 'APN',
    'nome' => 'APN do chip, com usuário e senha',
    // ⚠️ Os três últimos campos são OPCIONAIS na planilha, e os exemplos dela
    // mostram a mesma linha com 3, 4, 5 e 13 campos. É por isso que este
    // comando tem três aridades no catálogo — nenhuma está errada.
    'desc' => 'APN do chip com usuário, senha e versão do protocolo IP. Usuário, senha e protocolo são opcionais.',
    'categoria' => 'rede',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 A013',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Nome da APN — pergunte à operadora do chip', 'format' => '', 'default' => 'allcombl.br'],
      1 => ['p' => 'B', 'desc' => 'Usuário da APN (opcional)', 'format' => '', 'default' => 'allcom'],
      2 => ['p' => 'C', 'desc' => 'Senha da APN (opcional)', 'format' => '', 'default' => 'allcom'],
      3 => ['p' => 'D', 'desc' => 'Protocolo (opcional)', 'format' => 'IP/IPv6/IPv4v6', 'default' => ''],
    ],
    'exemplos' => [
      0 => ['cmd' => 'APN,vivo,,,IPV4V6#', 'desc' => 'sem usuário e senha, forçando IPv4v6 (exemplo oficial A013)'],
    ],
  ],
  'SERVER,A,B,C,D,E,F#' => [
    'cmd' => 'SERVER',
    'nome' => 'Servidor principal e reserva, com ID e placa',
    //
    // 🔴 É O COMANDO QUE TIRA A CÂMERA DA PLATAFORMA se o endereço estiver
    // errado — a recuperação é por SMS, em campo. A sintaxe de três campos
    // (universal) já existia; esta acrescenta o servidor RESERVA, o ID do
    // equipamento e a placa, e é a que a planilha do JC371 publica.
    //
    // Medido em 20/08/2026, o `CHECK#` devolve o que está gravado hoje:
    //   JC371/JC182 → SERVER:0,186.248.143.197,21122
    //   JC400AD     → SERVER:0,186.248.143.197,21100
    // A porta MUDA de modelo para modelo. Copiar a de um para o outro é o jeito
    // mais fácil de derrubar uma câmera que estava funcionando.
    'desc' => '⚠️ Aponta o equipamento para um servidor. Endereço errado tira a câmera da plataforma e só se recupera por SMS, em campo. Use NA nos campos que não quiser mudar. A porta varia por modelo — confira antes com o CHECK#.',
    'categoria' => 'rede',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 A022',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Endereço do servidor principal', 'format' => '', 'default' => '186.248.143.197'],
      1 => ['p' => 'B', 'desc' => 'Porta do servidor principal', 'format' => '', 'default' => '21122'],
      2 => ['p' => 'C', 'desc' => 'Endereço do servidor reserva — NA para nenhum', 'format' => '', 'default' => 'NA'],
      3 => ['p' => 'D', 'desc' => 'Porta do servidor reserva — NA para nenhum', 'format' => '', 'default' => 'NA'],
      4 => ['p' => 'E', 'desc' => 'ID do equipamento — NA para não mudar', 'format' => '', 'default' => 'NA'],
      5 => ['p' => 'F', 'desc' => 'Placa do veículo — NA para não mudar', 'format' => '', 'default' => 'NA'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'SERVER,186.248.143.197,21122,NA,NA,NA,NA#', 'desc' => 'só o principal, sem mexer em ID nem placa'],
    ],
  ],
  'BCD,A,B#' => [
    'cmd' => 'BCD',
    'nome' => 'Tipo de ID e versão do protocolo JT/T 808',
    //
    // 🔑 O SEGUNDO CAMPO É A VERSÃO DO PROTOCOLO — 0 é JT/T 808-2011, 1 é
    // 808-2019 —, e é isso que a entrada de um campo só não sabia dizer. A
    // escolha muda o dialeto que a câmera fala com o IoT Hub inteiro, não um
    // detalhe de formatação. A entrada antiga (`BCD,P1#`, da wiki) descrevia o
    // primeiro campo como "JT/T 808-2013", ano que não existe nesta planilha.
    'desc' => 'A: como o ID do equipamento é montado (IMEI em hexadecimal ou os 12 últimos dígitos). B: a VERSÃO do protocolo JT/T 808 que a câmera fala — 0 para 2011, 1 para 2019.',
    'categoria' => 'rede',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 A023',
    'params' => [
      0 => ['p' => 'A', 'desc' => '0: os 14 primeiros dígitos do IMEI em hexadecimal; 1: os 12 últimos dígitos do IMEI', 'format' => '0/1', 'default' => '0'],
      1 => ['p' => 'B', 'desc' => 'Versão do protocolo — 0: JT/T 808-2011, 1: JT/T 808-2019', 'format' => '0/1', 'default' => '0'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'BCD,0,0#', 'desc' => 'IMEI em hexadecimal, protocolo 808-2011 (exemplo oficial A023)'],
    ],
  ],
  'LOG,ALL#' => [
    'cmd' => 'LOG',
    'nome' => 'Subir os logs para o servidor da Jimi',
    // Aridade 1 com valor LITERAL: `ALL` não é placeholder, é a palavra que vai
    // no comando — por isso `template` é false. A entrada `LOG,ALL,A#` (linha
    // JC400) acrescenta um servidor TCP de destino; esta manda para o padrão
    // da fabricante.
    'desc' => 'Manda o equipamento subir os logs internos para o servidor padrão da Jimi. Serve para abrir chamado com o fabricante — os logs não chegam aqui.',
    'categoria' => 'manutencao',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 A025',
    'params' => [],
    'exemplos' => [
      0 => ['cmd' => 'LOG,ALL#', 'desc' => 'sobe tudo para o servidor padrão da Jimi'],
    ],
  ],
  'RECORDAUDIO,A,B#' => [
    'cmd' => 'RECORDAUDIO',
    'nome' => 'Áudio na gravação do cartão TF, por canal',
    'desc' => 'Liga ou desliga o áudio nas gravações do cartão TF, escolhendo o canal. ⚠️ A sintaxe de um campo só (linha JC400) vale para o equipamento inteiro; esta é por canal.',
    'categoria' => 'video',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 B005',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Canal — 1: CH1 (via, frontal), 2: CH2 (USB, interna), 3: CH3 (DMS, motorista)', 'format' => '1/2/3', 'default' => '1'],
      1 => ['p' => 'B', 'desc' => 'ON liga o áudio, OFF deixa mudo', 'format' => 'ON/OFF', 'default' => 'ON'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'RECORDAUDIO,1,OFF#', 'desc' => 'grava o canal da via sem áudio (exemplo oficial B005)'],
    ],
  ],
  'RECORDAUDIO_SUB,A,B#' => [
    'cmd' => 'RECORDAUDIO_SUB',
    'nome' => 'Áudio no vídeo histórico e de evento, por canal',
    // ⚠️ `_SUB` é a memória INTERNA (histórico e evento), não o cartão TF —
    // é o par do `RECORDAUDIO` acima, na mesma divisão que separa `VIDEORSL` de
    // `VIDEORSL_SUB`. Desligar num não desliga no outro.
    'desc' => 'Liga ou desliga o áudio nos vídeos histórico e de evento gravados na memória interna, escolhendo o canal.',
    'categoria' => 'audio',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 B006',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Canal — 1: CH1 (via, frontal), 2: CH2 (USB, interna), 3: CH3 (DMS, motorista)', 'format' => '1/2/3', 'default' => '1'],
      1 => ['p' => 'B', 'desc' => 'ON liga o áudio, OFF deixa mudo', 'format' => 'ON/OFF', 'default' => 'ON'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'RECORDAUDIO_SUB,1,OFF#', 'desc' => 'histórico do canal da via sem áudio (exemplo oficial B006)'],
    ],
  ],
  'RATATION,A,B,C,D#' => [
    'cmd' => 'RATATION',
    'nome' => 'Rotação, espelhamento e resolução da câmera',
    // ⚠️ `RATATION` é erro de grafia da fabricante (seria ROTATION) e está
    // assim no firmware — corrigir aqui faria o comando ser recusado.
    'desc' => 'Gira a imagem em 180°, escolhe o espelhamento e a resolução, por canal. Serve para câmera montada de cabeça para baixo.',
    'categoria' => 'outros',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 B008',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Canal — 1: CH1 (via, frontal), 2: CH2 (USB, interna), 3: CH3 (DMS, motorista)', 'format' => '1/2/3', 'default' => '1'],
      1 => ['p' => 'B', 'desc' => 'Ângulo de rotação em graus', 'format' => '0/180', 'default' => '0'],
      2 => ['p' => 'C', 'desc' => 'Espelhamento — 0: horizontal, 1: vertical', 'format' => '0/1', 'default' => '0'],
      3 => ['p' => 'D', 'desc' => 'Resolução, para câmeras remotas', 'format' => '720P/1080P', 'default' => '1080P'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'RATATION,3,0,1,1080#', 'desc' => 'exemplo oficial (B008)'],
    ],
  ],
  'PICTIMER,A,B,C,D#' => [
    'cmd' => 'PICTIMER',
    'nome' => 'Foto por tempo, com endereço de upload',
    'desc' => 'Tira foto de tempos em tempos e sobe para um endereço HTTP. ⚠️ Sem endereço no último campo, a foto é tirada e NÃO sai da câmera.',
    'categoria' => 'video',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 B009',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Intervalo em minutos, ou OFF para desligar', 'format' => 'OFF ou 1-1440', 'default' => '1440'],
      1 => ['p' => 'B', 'desc' => 'Fotos por intervalo', 'format' => '1-3', 'default' => '1'],
      2 => ['p' => 'C', 'desc' => 'Canal — 1: CH1 (via, frontal), 2: CH2 (USB, interna), 3: CH3 (DMS, motorista)', 'format' => '1/2/3', 'default' => '3'],
      3 => ['p' => 'D', 'desc' => 'URL HTTP que recebe as fotos — em branco, elas não são enviadas', 'format' => '', 'default' => ''],
    ],
    'exemplos' => [
      0 => ['cmd' => 'PICTIMER,1440,1,3,HTTPURL#', 'desc' => 'uma foto por dia do canal do motorista (exemplo oficial B009)'],
    ],
  ],
  'TIMER,A#' => [
    'cmd' => 'TIMER',
    'nome' => 'Intervalo de envio de posição',
    'desc' => 'De quantos em quantos segundos o equipamento manda a posição. ⚠️ A sintaxe de dois campos (universal) separa o intervalo com e sem ignição; esta, da planilha do JC371, tem um valor só.',
    'categoria' => 'posicao',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 C001',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Intervalo em segundos', 'format' => '5-60', 'default' => '10'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'TIMER,20#', 'desc' => 'posição a cada 20 segundos (exemplo oficial C001)'],
    ],
  ],
  'ANGLEREP,A#' => [
    'cmd' => 'ANGLEREP',
    'nome' => 'Envio de posição por mudança de ângulo',
    'desc' => 'Manda posição sempre que o veículo mudar de direção mais do que este ângulo — é o que desenha a curva no mapa entre dois envios por tempo.',
    'categoria' => 'posicao',
    'modelos' => [0 => 'JC371'],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JC371 V1.0.1 C002',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Ângulo em graus', 'format' => '10-180', 'default' => '30'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'ANGLEREP,35#', 'desc' => 'envia a cada 35° de mudança de direção (exemplo oficial C002)'],
    ],
  ],
  'FILTER#' => [
    'cmd' => 'FILTER',
    'nome' => 'Intervalo de filtro de eventos',
    'desc' => 'Intervalo em que eventos repetidos são suprimidos, por tipo de evento.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'FILTER#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'FORMAT,P1#' => [
    'cmd' => 'FORMAT',
    'nome' => 'Formatar cartão SD',
    'desc' => '🔴 DESTRUTIVO. Apaga o cartão. Não desligar nem remover o cartão durante a formatação (~1 min).',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC182',
      1 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  // v4.13.12 — NOVO. GFENCE não existia neste catálogo; adicionado a partir
  // de docs/JC181_Command_List_V1.0.7_20250811.xlsx, linhas D011 (círculo) e
  // D012 (retângulo). v4.13.14 tentou atribuir ao JC182; v4.13.15 corrigiu de
  // volta para JC181 — a planilha é exclusiva do JC181 ("applicable to JC181
  // series products", nunca cita JC182), e o dono do produto confirmou
  // (26/08/2026) que o JC182 tem bem menos funções que o JC181, apesar do
  // número de modelo maior.
  // ⚠️ Cerca eletrônica
  // NO EQUIPAMENTO — é uma função DO FIRMWARE da câmera, não tem relação com
  // a tabela `geofences`/`/geocercas` da aplicação (essas são cercas
  // calculadas no servidor a partir do GPS já recebido).
  // ⚠️ INCERTEZA GENUÍNA nos dois formatos abaixo, sinalizada em vez de
  // escondida (ver CLAUDE.md "doc mente, meça no device"): a célula de
  // FORMATO da planilha descreve um número de campos e os DOIS exemplos reais
  // da própria planilha trazem MAIS UM valor no final do que os campos
  // documentados explicam (sempre "1", nas duas linhas). Não há descrição
  // nenhuma pra esse campo extra — pode ser um terminador, uma flag não
  // documentada, ou erro de transcrição do fabricante. Os exemplos abaixo são
  // LITERAIS da planilha; o último parâmetro fica sinalizado como
  // desconhecido. NÃO enviar em produção sem confirmar em câmera real
  // primeiro (mesma disciplina do `docs/COMANDOS_128_CONSULTA.md`).
  'GFENCE,P1,P2,P3,P4,P5,P6,P7,P8,P9,P10#' => [
    'cmd' => 'GFENCE',
    'nome' => 'Cerca eletrônica (circular)',
    'desc' => 'Configura uma cerca eletrônica circular no equipamento e, opcionalmente, controla a gravação dentro/fora dela.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC181',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => ['p' => 'P1', 'desc' => 'Número da cerca', 'format' => '1 (único valor visto na planilha)', 'default' => '1'],
      1 => ['p' => 'P2', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'OFF'],
      2 => ['p' => 'P3', 'desc' => 'Forma da cerca (fixo nesta variante)', 'format' => '0 = circular', 'default' => '0'],
      3 => ['p' => 'P4', 'desc' => 'Latitude do centro', 'format' => '0 = detecção automática pela posição atual do GPS, ou valor fixo', 'default' => '0'],
      4 => ['p' => 'P5', 'desc' => 'Longitude do centro', 'format' => '0 = detecção automática pela posição atual do GPS, ou valor fixo', 'default' => '0'],
      5 => ['p' => 'P6', 'desc' => 'Raio do círculo', 'format' => '1–9999, unidade 100 m (ex.: 10 = 1000 m)', 'default' => '10'],
      6 => ['p' => 'P7', 'desc' => 'Direção do alarme', 'format' => 'IN = ao entrar / OUT = ao sair / vazio = os dois', 'default' => 'vazio'],
      7 => ['p' => 'P8', 'desc' => 'Forma de envio do alarme', 'format' => '0 = GPRS / 1 = SMS+GPRS', 'default' => '0'],
      8 => ['p' => 'P9', 'desc' => 'Controle de gravação', 'format' => '0 = grava só fora da cerca / 1 = grava só dentro / 255 = não controla', 'default' => '0'],
      9 => ['p' => 'P10', 'desc' => '⚠️ Campo sem descrição na planilha — sempre "1" no único exemplo visto', 'format' => 'desconhecido', 'default' => '1'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'GFENCE,1,ON,0,0,0,10,,0,0,1#', 'desc' => 'exemplo literal da planilha (linha D011) — cerca 1, centro pela posição atual, raio 1000 m, alarme ao entrar e sair, GPRS. NÃO confirmado em câmera real.'],
    ],
  ],
  'GFENCE,P1,P2,P3,P4,P5,P6,P7,P8,P9,P10,P11#' => [
    'cmd' => 'GFENCE',
    'nome' => 'Cerca eletrônica (retangular)',
    'desc' => 'Configura uma cerca eletrônica retangular no equipamento e, opcionalmente, controla a gravação dentro/fora dela.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC181',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => ['p' => 'P1', 'desc' => 'Número da cerca', 'format' => '1 (único valor visto na planilha)', 'default' => '1'],
      1 => ['p' => 'P2', 'desc' => 'Ativação', 'format' => 'ON / OFF', 'default' => 'OFF'],
      2 => ['p' => 'P3', 'desc' => 'Forma da cerca (fixo nesta variante)', 'format' => '1 = retangular', 'default' => '1'],
      3 => ['p' => 'P4', 'desc' => 'Latitude do 1º canto', 'format' => 'graus decimais', 'default' => '—'],
      4 => ['p' => 'P5', 'desc' => 'Longitude do 1º canto', 'format' => 'graus decimais', 'default' => '—'],
      5 => ['p' => 'P6', 'desc' => 'Latitude do 2º canto', 'format' => 'graus decimais', 'default' => '—'],
      6 => ['p' => 'P7', 'desc' => 'Longitude do 2º canto', 'format' => 'graus decimais', 'default' => '—'],
      7 => ['p' => 'P8', 'desc' => 'Direção do alarme', 'format' => 'IN = ao entrar / OUT = ao sair / vazio = os dois', 'default' => 'vazio'],
      8 => ['p' => 'P9', 'desc' => 'Forma de envio do alarme', 'format' => '0 = GPRS / 1 = SMS+GPRS', 'default' => '0'],
      9 => ['p' => 'P10', 'desc' => 'Controle de gravação', 'format' => '0 = grava só fora da cerca / 1 = grava só dentro / 255 = não controla', 'default' => '0'],
      10 => ['p' => 'P11', 'desc' => '⚠️ Campo sem descrição na planilha — sempre "1" no único exemplo visto', 'format' => 'desconhecido', 'default' => '1'],
    ],
    'exemplos' => [
      0 => ['cmd' => 'GFENCE,1,ON,1,23,113,24,114,,0,0,1#', 'desc' => 'exemplo literal da planilha (linha D012) — retângulo entre os cantos (23,113) e (24,114), alarme ao entrar e sair, GPRS. NÃO confirmado em câmera real.'],
    ],
  ],
  'GPSDUP,A#' => [
    'cmd' => 'GPSDUP',
    'nome' => 'Duplicidade de GPS',
    'desc' => 'A: ON/OFF.',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC181',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'GPSDUP#',
    'consulta_modelos' => ['JC181'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'HDS#' => [
    'cmd' => 'HDS',
    'nome' => 'Compartilhamento de Dados do Dispositivo',
    'desc' => '',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'HDS#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'medido',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'HDS,OFF#' => [
    'cmd' => 'HDS',
    'nome' => 'Compartilhamento de Dados do Dispositivo',
    'desc' => '',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'HTTPUPLOADLIMIT#' => [
    'cmd' => 'HTTPUPLOADLIMIT',
    'nome' => 'Tentativas de envio do vídeo do evento',
    'desc' => '',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'HTTPUPLOADLIMIT#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1 - 10',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '1 - 30 (Especifica o intervalo entre cada tentativa em minutos)',
        'format' => '',
        'default' => '5 B = 1 - 30 (Especifica o intervalo ent',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'KEYFUN,P1,P2,P3#' => [
    'cmd' => 'KEYFUN',
    'nome' => 'Função dos botões',
    'desc' => 'Define o que clique curto e clique longo acionam.',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'KEYFUN#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'P2',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'P3',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'LED,A#' => [
    'cmd' => 'LED',
    'nome' => 'LEDs',
    'desc' => 'Para ligar/desligar os LEDs dos equipamentos, envie',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC400D',
      3 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'LED#',
    'consulta_modelos' => ['JC371', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Situação dos LEDs',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'A',
        'desc' => 'ON',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'A',
        'desc' => 'OFF',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'LIGHT,10#' => [
    'cmd' => 'LIGHT',
    'nome' => 'Modo de luz/LED',
    'desc' => '',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC182',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'LIGHT#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'wiki',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'LOGASW,P1#' => [
    'cmd' => 'LOGASW',
    'nome' => 'Recuperação de logs',
    'desc' => 'Habilita a recuperação de logs do módulo de comunicação.',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'LOGASW#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'MILE,P1#' => [
    'cmd' => 'MILE',
    'nome' => 'Unidade de velocidade',
    'desc' => 'P1: 0 = km/h, 1 = mph. Padrão 0. ⚠️ NÃO é o hodômetro — esse é o MILEAGE.',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC182',
      1 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'MILE#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'medido',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'MILEAGE,P1#' => [
    'cmd' => 'MILEAGE',
    'nome' => 'Hodômetro (ajuste manual)',
    'desc' => 'Ajusta manualmente o valor atual do hodômetro.',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'PARAM#' => [
    'cmd' => 'PARAM',
    'nome' => 'Verificar Parâmetros Básicos do Dispositivo',
    'desc' => '',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC181',
      3 => 'JC400D',
      4 => 'JC400AD',
    ],
    'universal' => true,
    'template' => false,
    'consulta' => 'PARAM#',
    'consulta_modelos' => ['JC371', 'JC400AD', 'JC400D', 'JC450'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'PARAM#',
        'desc' => 'Consulta parâmetros básicos do dispositivo.',
      ],
    ],
  ],
  'PICRATE,P1,P2#' => [
    'cmd' => 'PICRATE',
    'nome' => 'Qualidade das fotos',
    'desc' => 'Qualidade e compressão das fotos capturadas.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'P2',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'PICTIMER,P1,P2,P3,P4,P5#' => [
    'cmd' => 'PICTIMER',
    'nome' => 'Captura programada de fotos',
    'desc' => 'Captura automática de fotos por tempo, com repetição por viagem e aviso de voz.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'PICTIMER#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'P2',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'P3',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'P4',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      4 => [
        'p' => 'P5',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'PICTURE#' => [
    'cmd' => 'PICTURE',
    'nome' => 'Parâmetros',
    'desc' => '',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'PICTURE#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'wiki',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'PICTURE,1#' => [
    'cmd' => 'PICTURE',
    'nome' => 'Parâmetros',
    'desc' => '',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'PRESETAPN,ADD,[Nome],[APN],,,,,,[Usuario],,[Senha],,,,,[Protocolo],[Protocolo],[AuthType]#' => [
    'cmd' => 'PRESETAPN',
    'nome' => '1. Definir APN na Lista Prioritária',
    'desc' => '',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC450',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'Consultar lista de APNs ativos',
        'desc' => 'PRESETAPN,QRY#',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'Consultar entrada específica',
        'desc' => 'PRESETAPN,QRY,EmpresaX#',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'Limpar toda a lista manual',
        'desc' => 'PRESETAPN,DEL#',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'Remover APN específica',
        'desc' => 'PRESETAPN,DEL,apn.antiga.com.br#',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'PRESETAPN,ADD,EmpresaX,apn.exemplo.com.br,,,,,,usuario,,senha123,,,,,IP,IP,1#',
        'desc' => '',
      ],
      1 => [
        'cmd' => 'PRESETAPN,ON#',
        'desc' => '',
      ],
      2 => [
        'cmd' => 'REBOOT#',
        'desc' => '',
      ],
    ],
  ],
  'PWDSW,P1#' => [
    'cmd' => 'PWDSW',
    'nome' => 'Senha de comandos',
    'desc' => 'Liga a exigência de senha nos comandos. Para desligar é preciso informar a senha atual.',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'PWDSW#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'medido',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'RAPIDACC,A,B,C#' => [
    'cmd' => 'RAPIDACC',
    'nome' => 'Aceleração Brusca',
    'desc' => 'Para configurar o envio de eventos de aceleração brusca, envie',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC450',
      1 => 'JC400D',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'RAPIDACC#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Upload de vídeo (0 ou 1)',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Sensibilidade (1,2 ou 3)',
        'format' => '',
        'default' => 'OFF Este comando está disponível nas ver',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RAPIDACC,3#',
        'desc' => 'Aceleração Brusca',
      ],
    ],
  ],
  'RAPIDDEC,A,B,C#' => [
    'cmd' => 'RAPIDDEC',
    'nome' => 'Frenagem Brusca',
    'desc' => 'Para configurar o envio de eventos de frenagem brusca, envie',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC450',
      1 => 'JC400D',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'RAPIDDEC#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Upload de vídeo (0 ou 1)',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Sensibilidade (1,2 ou 3)',
        'format' => '',
        'default' => 'OFF Para consultar, envie: RAPIDDEC# Est',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RAPIDDEC,1#',
        'desc' => 'Frenagem Brusca',
      ],
    ],
  ],
  'RAPIDTURN,A,B,C#' => [
    'cmd' => 'RAPIDTURN',
    'nome' => 'Curva Brusca',
    'desc' => 'Para configurar o envio de eventos de curva brusca, envie',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC450',
      1 => 'JC400D',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'RAPIDTURN#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Upload de vídeo (0 ou 1)',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Sensibilidade (1, 2 ou 3)',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RAPIDTURN,2#',
        'desc' => 'Curva Brusca',
      ],
    ],
  ],
  'RATATION,A,B#' => [
    'cmd' => 'RATATION',
    'nome' => 'Rotacionar imagem da câmera',
    'desc' => 'Para configurar a rotação da câmera, envie',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC400D',
      3 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'RATATION#',
    'consulta_modelos' => ['JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'IN/OUT',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0/90/180/270',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RATATION,2,180#',
        'desc' => 'Rotacionar imagem da câmera',
      ],
      1 => [
        'cmd' => 'RATATION,2,90#',
        'desc' => 'Rotacionar imagem da câmera',
      ],
    ],
  ],
  'REBOOT#' => [
    'cmd' => 'REBOOT',
    'nome' => 'Reiniciar',
    'desc' => 'Para reiniciar o equipamento, envie',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC400D',
      4 => 'JC400AD',
    ],
    'universal' => true,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'RECORDAUDIO,A#' => [
    'cmd' => 'RECORDAUDIO',
    'nome' => 'Gravação de áudio',
    'desc' => 'Para consultar se o áudio está ativo, envie',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
      2 => 'JC400D',
      3 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'RECORDAUDIO#',
    'consulta_modelos' => ['JC182', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'A',
        'desc' => '0/1',
        'format' => '',
        'default' => '1 = Ativado Para consultar se o áudio es',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'RECORDAUDIO_SUB,A#' => [
    'cmd' => 'RECORDAUDIO_SUB',
    'nome' => 'Áudio no fluxo secundário',
    'desc' => 'A: 0 desativado, 1 ativado. Padrão 1.',
    'categoria' => 'audio',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'RECORDAUDIO_SUB#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'RECORDSW,A,B#' => [
    'cmd' => 'RECORDSW',
    'nome' => 'Ativar/Desativar gravação',
    'desc' => 'Para ativar/desativar a gravação da câmera, envie',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => true,
    'consulta' => 'RECORDSW#',
    'consulta_modelos' => ['JC182', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1 / 2',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0/1',
        'format' => '',
        'default' => 'ON para os 2 canais Para consultar o sta',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'RECORDSW_SUB,P1,P2#' => [
    'cmd' => 'RECORDSW_SUB',
    'nome' => 'Gravação histórica por canal',
    'desc' => 'Ativa ou desativa a gravação histórica de um canal.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'P2',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'RELAY,P1#' => [
    'cmd' => 'RELAY',
    'nome' => 'Imobilização remota via relé (RELAY)',
    'desc' => 'Controla remotamente o abastecimento de combustível ou o fornecimento de energia do veículo (corte/restauração).',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC181',
      3 => 'JC400D',
      4 => 'JC400AD',
    ],
    'universal' => true,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Estado do relé',
        'format' => '0 / OFF: conectar combustível/energia (restaura) / 1 / ON: desconectar combustível/energia (corta)',
        'default' => '0',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RELAY#',
        'desc' => 'Consulta/estado atual (“combustível cortado!”). Também podem aparecer indicadores ...=1 (cortado) e ...=0 (restaurado).',
      ],
      1 => [
        'cmd' => 'RELAY,1#',
        'desc' => 'Corta combustível/energia. Exemplo do manual indica operação bem‑sucedida.',
      ],
      2 => [
        'cmd' => 'RELAY,0#',
        'desc' => 'Restaura combustível/energia (0/ OFF = conectar).',
      ],
    ],
  ],
  'RESET#' => [
    'cmd' => 'RESET',
    'nome' => 'Reiniciar (RESET)',
    'desc' => '🔴 Reinicia o equipamento. Equivalente a REBOOT# e RESTART#.',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'RESTART#' => [
    'cmd' => 'RESTART',
    'nome' => 'Reiniciar (RESTART)',
    'desc' => '🔴 Reinicia o equipamento. Equivalente a REBOOT# e RESET#.',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'RESTORE#' => [
    'cmd' => 'RESTORE',
    'nome' => 'Restaurar',
    'desc' => 'Para restaurar o equipamento para as configurações de fábrica, envie',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
      2 => 'JC400D',
      3 => 'JC400AD',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'RSERVICE,A#' => [
    'cmd' => 'RSERVICE',
    'nome' => 'Streaming',
    'desc' => 'Para alterar o servidor de streaming do equipamento, envie',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'RSERVICE#',
    'consulta_modelos' => ['JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Endereço do servidor HTTP',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Porta',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  // v4.13.12 — reescrito a partir de docs/JC181_Command_List_V1.0.7_20250811.xlsx,
  // linha D002. A entrada antiga tinha só 2 parâmetros (A=ON/OFF, B=1-3); a
  // planilha real (modificada em V1.0.2/V1.0.5/V1.0.7) tem 5, e a semântica de
  // A mudou: não é mais ON/OFF, é o próprio nível de sensibilidade (0=OFF).
  // v4.13.15 — JC182 REMOVIDO de `modelos` e `consulta_modelos` (o segundo já
  // trazia JC182 de antes desta sessão — mesma classe de inconsistência
  // documentada em CLAUDE.md, um campo desatualizado que o outro não
  // acompanhou). A planilha-fonte é exclusiva do JC181 ("applicable to JC181
  // series products", nunca cita JC182); o dono do produto confirmou
  // (26/08/2026) que o JC182 tem bem menos funções que o JC181, apesar do
  // número de modelo maior — o único código de vibração que ele de fato
  // responde é `EVENTSET,AVD` (dialeto EVENTSET/JT/T, ver mais abaixo).
  'SENALM,P1,P2,P3,P4,P5#' => [
    'cmd' => 'SENALM',
    'nome' => 'Vibração (veículo parado)',
    'desc' => 'Sensibilidade para disparar evento de vibração com o veículo estacionado.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC181',
      1 => 'JC400D',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'SENALM#',
    'consulta_modelos' => ['JC181', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Sensibilidade (0 desativa; quanto maior o número, menos sensível)',
        'format' => '0/1/2/3/4/5',
        'default' => '2',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Número de interrupções por vibração para disparar o alarme',
        'format' => '1–20',
        'default' => '5',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Tempo de detecção',
        'format' => '1–3000 (segundos)',
        'default' => '10',
      ],
      3 => [
        'p' => 'P4',
        'desc' => 'Intervalo mínimo até o próximo alarme (filtro)',
        'format' => '1–3000 (minutos)',
        'default' => '5',
      ],
      4 => [
        'p' => 'P5',
        'desc' => 'Forma de envio do alarme',
        'format' => '0 = GPRS / 1 = SMS+GPRS',
        'default' => '0',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SENALM,2,10,15,5,0#',
        'desc' => 'exemplo da planilha — sensibilidade 2, 10 interrupções, detecção de 15 s, filtro de 5 min, GPRS.',
      ],
    ],
  ],
  'SENDS,5#' => [
    'cmd' => 'SENDS',
    'nome' => 'Tempo para desligar o GPS',
    'desc' => 'Exemplo',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC181',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'SERVER,A,B,C#' => [
    'cmd' => 'SERVER',
    'nome' => 'Servidor',
    'desc' => 'Para configurar o servidor, envie',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => true,
    'consulta' => 'SERVER#',
    'consulta_modelos' => ['JC181', 'JC182', 'JC371', 'JC400AD', 'JC400D', 'JC450'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'IP(0) ou Domínio(1)',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Servidor',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Porta de conexão',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SERVER,gpsdev.tracksolid.com,21100#',
        'desc' => 'Definir IP/domínio e porta do servidor',
      ],
    ],
  ],
  'SF,P1,P2#' => [
    'cmd' => 'SF',
    'nome' => 'Filtro de deriva',
    'desc' => 'Filtro de posição parada; a margem em metros define quando volta a rastrear. Recomendado 100.',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC182',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'SF#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'medido',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'P2',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'SOSALM,A,B#' => [
    'cmd' => 'SOSALM',
    'nome' => 'SOS',
    'desc' => 'Para configurar o envio de alarme SOS, envie',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'SOSALM#',
    'consulta_modelos' => ['JC181', 'JC182'],
    'consulta_ref' => 'medido',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0 - GPRS, 1 - SMS+GPRS, 2 - GPRS+SMS+Ligação, 3 - GPRS+Ligação',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'A',
        'desc' => 'Este parâmetro deve ser mantido valor A',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => 'Este parâmetro deve ser mantido valor A',
        'format' => '',
        'default' => '',
      ],
      4 => [
        'p' => 'A',
        'desc' => '1, 2 ou 3',
        'format' => '',
        'default' => '2 Para adicionar os números na central d',
      ],
    ],
    'exemplos' => [
    ],
  ],
  // v4.13.12 — corrigido cruzando com docs/JC181_Command_List_V1.0.7_20250811.xlsx,
  // linha D003: os campos B e D estavam com a DESCRIÇÃO TROCADA (B dizia
  // "tempo acima da velocidade", que é na verdade o campo D; D dizia "forma
  // de envio", que é na verdade o campo B) — a ORDEM dos 4 parâmetros já
  // estava certa, só o texto de ajuda invertia B com D. Confirmado pelo
  // exemplo oficial "SPEED,ON,0,90,10" (B=0 é forma de envio, não duração).
  // v4.13.15 — JC182 REMOVIDO: chegou a ser adicionado (v4.13.12) por pedido
  // do dono do produto, mas a planilha-fonte é exclusiva do JC181
  // ("applicable to JC181 series products", nunca cita JC182); confirmado
  // pelo dono do produto (26/08/2026) que o JC182 tem bem menos funções que
  // o JC181, apesar do número de modelo maior. Para velocidade, o JC182 usa
  // `EVENTSET,AOSD` (ver includes/ia_config_catalog.php).
  'SPEED,A,B,C,D#' => [
    'cmd' => 'SPEED',
    'nome' => 'Excesso de velocidade',
    'desc' => 'Para configurar o alarme de velocidade excedida, envie',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC450',
      1 => 'JC181',
      2 => 'JC400D',
      3 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'SPEED#',
    'consulta_modelos' => ['JC181', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Forma de envio do alarme',
        'format' => '0 = GPRS / 1 = SMS+GPRS',
        'default' => '0',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Velocidade(1~255km/h)',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => 'Tempo acima da velocidade',
        'format' => '5–600 (segundos)',
        'default' => '20',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SPEED,ON,0,90,10#',
        'desc' => 'dispara acima de 90 km/h mantidos por 10 s, envio por GPRS.',
      ],
    ],
  ],
  // v4.13.12 — parâmetros preenchidos a partir de
  // docs/JC181_Command_List_V1.0.7_20250811.xlsx, linha D004 (a entrada
  // anterior tinha os 5 placeholders sem nenhuma desc/format).
  // v4.13.15 — JC182 REMOVIDO: a planilha-fonte é exclusiva do JC181
  // ("applicable to JC181 series products", nunca cita JC182); confirmado
  // pelo dono do produto (26/08/2026) que o JC182 tem bem menos funções que
  // o JC181, apesar do número de modelo maior.
  'SPEEDCHECK,P1,P2,P3,P4,P5#' => [
    'cmd' => 'SPEEDCHECK',
    'nome' => 'Frenagem brusca (detecção)',
    'desc' => 'Queda de velocidade em N segundos para caracterizar frenagem brusca.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC181',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'SPEEDCHECK#',
    'consulta_modelos' => ['JC181'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Ativação',
        'format' => 'ON / OFF',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Forma de envio do alarme',
        'format' => '0 = GPRS / 1 = SMS+GPRS',
        'default' => '0',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Tempo de detecção',
        'format' => '1–30 (segundos)',
        'default' => '4',
      ],
      3 => [
        'p' => 'P4',
        'desc' => 'Variação de velocidade que caracteriza aceleração brusca',
        'format' => '10–300 (km/h)',
        'default' => '30',
      ],
      4 => [
        'p' => 'P5',
        'desc' => 'Variação de velocidade que caracteriza frenagem brusca',
        'format' => '10–300 (km/h)',
        'default' => '50',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SPEEDCHECK,ON,0,4,30,50#',
        'desc' => 'exemplo da planilha — detecção de 4 s, 30 km/h para aceleração, 50 km/h para frenagem.',
      ],
    ],
  ],
  'SSID#' => [
    'cmd' => 'SSID',
    'nome' => 'SSID',
    'desc' => '',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => false,
    'consulta' => 'SSID#',
    'consulta_modelos' => ['JC182', 'JC371'],
    'consulta_ref' => 'medido',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'STATUS#' => [
    'cmd' => 'STATUS',
    'nome' => 'Status',
    'desc' => 'Para consultar o status do equipamento, enviar',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => false,
    'consulta' => 'STATUS#',
    'consulta_modelos' => ['JC181', 'JC182', 'JC371', 'JC400AD', 'JC400D', 'JC450'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  // v4.13.12 — parâmetros preenchidos a partir de
  // docs/JC181_Command_List_V1.0.7_20250811.xlsx, linha D005 (a entrada
  // anterior tinha os 5 placeholders sem nenhuma desc/format). ⚠️ A própria
  // planilha rotula P3 como "km/h" na coluna de unidade, mas descreve o campo
  // como limiar de ÂNGULO ("Angle threshold value") — provável erro de
  // digitação do fabricante, mantido como o texto da planilha diz (° é o
  // esperado para ângulo, não km/h).
  // v4.13.15 — JC182 REMOVIDO: a planilha-fonte é exclusiva do JC181
  // ("applicable to JC181 series products", nunca cita JC182); confirmado
  // pelo dono do produto (26/08/2026) que o JC182 tem bem menos funções que
  // o JC181, apesar do número de modelo maior.
  'SWERVE,P1,P2,P3,P4,P5#' => [
    'cmd' => 'SWERVE',
    'nome' => 'Curva brusca (detecção)',
    'desc' => 'Tempo de detecção para caracterizar curva brusca.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC181',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'SWERVE#',
    'consulta_modelos' => ['JC181'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Ativação',
        'format' => 'ON / OFF',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Forma de envio do alarme',
        'format' => '0 = GPRS / 1 = SMS+GPRS',
        'default' => '0',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Limiar do ângulo de curva (planilha rotula a unidade como "km/h" — provável erro do fabricante; ver comentário acima)',
        'format' => '10–180',
        'default' => '30',
      ],
      3 => [
        'p' => 'P4',
        'desc' => 'Velocidade mínima para caracterizar curva brusca',
        'format' => '10–300 (km/h)',
        'default' => '60',
      ],
      4 => [
        'p' => 'P5',
        'desc' => 'Tempo de detecção',
        'format' => '1–30 (segundos)',
        'default' => '3',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SWERVE,ON,0,30,30,3#',
        'desc' => 'exemplo da planilha.',
      ],
    ],
  ],
  'TFMODE,P1#' => [
    'cmd' => 'TFMODE',
    'nome' => 'Modo de memória',
    'desc' => '1: cartão TF; 2: somente EMMC (sem alertas de ausência de cartão).',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'TFMODE#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'TIMER,A,B#' => [
    'cmd' => 'TIMER',
    'nome' => 'Posição',
    'desc' => 'Para configurar o tempo de envio de posição, envie',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
      2 => 'JC181',
      3 => 'JC400D',
      4 => 'JC400AD',
    ],
    'universal' => true,
    'template' => true,
    'consulta' => 'TIMER#',
    'consulta_modelos' => ['JC181', 'JC182', 'JC371', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      // ⚠️ A wiki não descreve os campos, e nenhuma planilha da fabricante
      // traz esta sintaxe de dois campos — só a de um (`TIMER,A#`, C001 do
      // JC371, intervalo em segundos). As posições vêm da sintaxe; o
      // significado de cada uma segue sem fonte, e fica em branco.
      0 => ['p' => 'A', 'desc' => '', 'format' => '', 'default' => ''],
      1 => ['p' => 'B', 'desc' => '', 'format' => '', 'default' => ''],
    ],
    'exemplos' => [
    ],
  ],
  'TIMER1,A,B#' => [
    'cmd' => 'TIMER1',
    'nome' => 'Posição com a Ignição desligada',
    'desc' => 'Para configurar o tempo de envio de posição com a Ignição desligada, envie',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'TIMER1#',
    'consulta_modelos' => ['JC181', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      // Mesma situação do `TIMER,A,B#`: só a wiki, sem descrição de campo.
      0 => ['p' => 'A', 'desc' => '', 'format' => '', 'default' => ''],
      1 => ['p' => 'B', 'desc' => '', 'format' => '', 'default' => ''],
    ],
    'exemplos' => [
    ],
  ],
  'TIMESYNC,A#' => [
    'cmd' => 'TIMESYNC',
    'nome' => 'Tempo',
    'desc' => 'Para alterar a forma de sincronização do tempo no equipamento, envie',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC400D',
      3 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'TIMESYNC#',
    'consulta_modelos' => ['JC371', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Método de sincronização',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'A',
        'desc' => 'gps',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'A',
        'desc' => 'network',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'TIMEZONE,A#' => [
    'cmd' => 'TIMEZONE',
    'nome' => 'Fuso horário',
    'desc' => 'Para alterar o fuso horário do equipamento, envie',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC450',
      1 => 'JC400D',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'TIMEZONE#',
    'consulta_modelos' => ['JC181', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Fuso horário(Formato: HH:mm)',
        'format' => '',
        'default' => '-03:00 Exemplo: TIMEZONE#-03:00 Para con',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'TLR,OFF,30,120#' => [
    'cmd' => 'TLR',
    'nome' => 'Time-lapse (intervalo de gravação)',
    'desc' => '',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC182',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'TLR#',
    'consulta_modelos' => ['JC182'],
    'consulta_ref' => 'medido',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'UARTCONFIG,P1,P2,P3,P4#' => [
    'cmd' => 'UARTCONFIG',
    'nome' => 'Configuração da Porta Serial (UARTCONFIG)',
    'desc' => 'Configura a porta serial do dispositivo: habilita/desabilita o protocolo, define o baud rate e controla a alimentação 5 V da porta.',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Número da porta serial',
        'format' => 'TTL2',
        'default' => 'TTL2',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Tipo de protocolo',
        'format' => '0 = OFF (desabilita) • 1 = KD032',
        'default' => '0',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Baud rate',
        'format' => 'Padrões usuais (ex.: 9600, 19200, 115200)',
        'default' => '115200',
      ],
      3 => [
        'p' => 'P4',
        'desc' => 'Alimentação 5 V da porta',
        'format' => 'ON / OFF',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'UARTCONFIG,TTL2#',
        'desc' => 'Consulta a configuração atual da TTL2.',
      ],
      1 => [
        'cmd' => 'UARTCONFIG,TTL2,1,19200,OFF#',
        'desc' => 'Habilita KD032 na TTL2, fixa 19200 e mantém 5 V OFF.',
      ],
    ],
  ],
  'UARTDL,P1,P2,P3#' => [
    'cmd' => 'UARTDL',
    'nome' => 'Pass‑through de Dados pela Serial (UARTDL)',
    'desc' => 'Envia dados crus diretamente ao periférico pela porta serial (modo passthrough), sem interpretação pelo dispositivo principal.',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Porta serial',
        'format' => 'TTL2',
        'default' => '—',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Protocolo',
        'format' => '1 = KD032',
        'default' => '—',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Dados passthrough',
        'format' => 'String de dados a ser enviada ao periférico (ex.: comandos)',
        'default' => '—',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'UARTCONFIG,P1,P2,P3,P4#',
        'desc' => 'Antes de usar, configure a porta com UARTCONFIG,P1,P2,P3,P4# para habilitar o protocolo correto do periférico. Fluxo recomendado: 1) UARTCONFIG,TTL2,1,C,D → 2) UARTDL,TTL2,1,P3#.',
      ],
      1 => [
        'cmd' => 'UARTCONFIG,TTL2,1,UART_ALLDATA,0#',
        'desc' => 'Envia UART_ALLDATA,0 para a unidade KD032/ELD via passthrough.',
      ],
    ],
  ],
  'UPDATE,P1#' => [
    'cmd' => 'UPDATE',
    'nome' => 'Atualizar firmware',
    'desc' => '🔴 Inicia a atualização de firmware baixando o pacote da URL informada. '
            . 'O comando é o mesmo em toda a linha JC — o que muda de um modelo para o outro '
            . 'é só o pacote apontado pela URL. Use a URL cadastrada em /firmwares para o modelo do equipamento.',
    'categoria' => 'manutencao',
    // Exceção MANUAL à derivação por páginas da wiki — ver o cabeçalho deste
    // arquivo. Só a página do JC371 documenta o comando; ele vale para a linha
    // inteira, e travar em JC371 tornava a atualização das outras cinco
    // impossível pela tela.
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'URL do pacote de firmware do MODELO deste equipamento',
        // 🔴 Vírgula e `#` são os separadores do proNo 128: uma URL que os
        // contenha é partida em dois pelo próprio equipamento. `firmware_url_problema()`
        // (includes/firmware.php) recusa as duas antes do envio.
        'format' => 'http://… ou https://… — sem vírgula e sem #',
        'default' => '—',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'UPDATE,http://servidor/firmware/JC400AD_V1.8.1.2_250904.bin#',
        'desc' => 'A URL é POR MODELO. Mandar o pacote de outro modelo não devolve erro de comando — '
                . 'o equipamento baixa e aplica o firmware errado.',
      ],
    ],
  ],
  'UPFILESIZE#' => [
    'cmd' => 'UPFILESIZE',
    'nome' => 'Limite de Tamanho de Arquivo Enviado',
    'desc' => '',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'UPFILESIZE#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'medido',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'UPFILESIZE,1024#' => [
    'cmd' => 'UPFILESIZE',
    'nome' => 'Limite de Tamanho de Arquivo Enviado',
    'desc' => '',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'UPLOAD,A#' => [
    'cmd' => 'UPLOAD',
    'nome' => 'Upload de vídeos',
    'desc' => 'Para alterar o endereço de upload de vídeos da câmera, envie',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC400D',
      3 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'UPLOAD#',
    'consulta_modelos' => ['JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Endereço do servidor HTTP',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Porta',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'UPLOADSW#' => [
    'cmd' => 'UPLOADSW',
    'nome' => 'Upload por tipo de evento',
    'desc' => 'Liga o upload da gravação por tipo de evento.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'UPLOADSW#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'URLTYPE,1#' => [
    'cmd' => 'URLTYPE',
    'nome' => 'Tipo de servidor (HTTP, etc.)',
    'desc' => '',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'URLTYPE#',
    'consulta_modelos' => ['JC182', 'JC450'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'URLTYPE,HTTPS#' => [
    'cmd' => 'URLTYPE',
    'nome' => 'Tipo de servidor (HTTP, etc.)',
    'desc' => '',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'VERSION#' => [
    'cmd' => 'VERSION',
    'nome' => 'Firmware',
    'desc' => '',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => false,
    'consulta' => 'VERSION#',
    'consulta_modelos' => ['JC181', 'JC182', 'JC371', 'JC400AD', 'JC400D', 'JC450'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'VIDEOPARAM,P1,P2#' => [
    'cmd' => 'VIDEOPARAM',
    'nome' => 'Duração dos clipes',
    'desc' => 'Duração dos clipes normais e dos clipes de evento.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'VIDEOPARAM#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'P2',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'VIDEORESOLUTION,A#' => [
    'cmd' => 'VIDEORESOLUTION',
    'nome' => 'VIDEORESOLUTION',
    'desc' => '',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'configuração de resolução',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'A',
        'desc' => '0/1/2',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'VIDEORSL,P1,P2,P3,P4,P5#' => [
    'cmd' => 'VIDEORSL',
    'nome' => 'Gravação no cartão TF',
    'desc' => 'Resolução e taxa de quadros da gravação no TF.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC371',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'P2',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'P3',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'P4',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      4 => [
        'p' => 'P5',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'VIDEOTIMEZONE,W,3,0#' => [
    'cmd' => 'VIDEOTIMEZONE',
    'nome' => 'Fuso horário',
    'desc' => '',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'VIDEOTIMEZONE#',
    'consulta_modelos' => ['JC182', 'JC371'],
    'consulta_ref' => 'medido+wiki',
    'params' => [

    ],
    'exemplos' => [
    ],
  ],
  'VIDEOUPLOAD,hkhttpupload.tracksolidpro.com,443,00 000000000000260205115526010300,1,2#' => [
    'cmd' => 'VIDEOUPLOAD',
    'nome' => 'Solicitar upload do anexo de um alarme JT/T',
    // 🔴 25/08/2026 — MEDIDO contra a Telecom (JC371, 865478070654829): pede
    // o(s) arquivo(s) já capturados pela câmera pro alarmLabel informado —
    // não gera um clipe novo (isso é EVIDEO/HVIDEO, que são comandos JIMI,
    // ver command_catalog.php de EVIDEO/HVIDEO acima; JC371 recusa os dois).
    // Resposta síncrona real: "start upload task;" — bem mais específica que
    // o "ok" genérico de outros ACKs. Formato real medido:
    // "VIDEOUPLOAD,<host storage>,<porta>,<alarmLabel sem vírgula>,1-2-3"
    // (canais dash-joined, não vírgula — o exemplo da chave desta entrada,
    // puxado cru da wiki, usa vírgula e porta 443/host de terceiro; nunca
    // testado nesse formato). Ver includes/alarm_video_request.php
    // (request_alarm_video_jtt()) e includes/occurrence_engine.php
    // (queue_event_video_request()), os dois pontos que hoje montam este
    // comando de verdade — este catálogo não é a fonte deles, é só
    // referência pra tela /comandos.
    'desc' => 'Pede à câmera o upload do(s) arquivo(s) de anexo já capturados para um alarme (identificado pelo alarmLabel), pro storage HTTP informado.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC182',
      1 => 'JC371',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => 'medido',
    'fonte' => 'wiki (JC182) + medido em produção 25/08/2026 (JC371, Telecom)',
    'params' => [
      0 => ['p' => 'A', 'desc' => 'Host do storage que recebe o arquivo', 'format' => '', 'default' => ''],
      1 => ['p' => 'B', 'desc' => 'Porta do storage', 'format' => '', 'default' => ''],
      2 => ['p' => 'C', 'desc' => 'alarmLabel do alarme dono (sem vírgula — 32 hex)', 'format' => '', 'default' => ''],
      3 => ['p' => 'D', 'desc' => 'Canais a pedir', 'format' => '1-2-3 (com hífen, não vírgula)', 'default' => '1-2-3'],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'VIDEOUPLOAD,186.248.143.197,23010,30363534383239260825155916...,1-2-3',
        'desc' => 'medido — resposta real: "start upload task;", 2 arquivos .jpg (um por canal) chegaram via /pushfileupload',
      ],
    ],
  ],
  'VOICESW,A#' => [
    'cmd' => 'VOICESW',
    'nome' => 'Idioma',
    'desc' => 'Para alterar o idioma do aúdio do equipamento, envie',
    'categoria' => 'audio',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC400D',
      3 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'VOICESW#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Idioma desejado',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'VOLUME,A#' => [
    'cmd' => 'VOLUME',
    'nome' => 'Volume',
    'desc' => 'Para configurar o volume do equipamento, envie',
    'categoria' => 'audio',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => true,
    'consulta' => 'VOLUME#',
    'consulta_modelos' => ['JC182', 'JC400AD', 'JC400D'],
    'consulta_ref' => 'medido+wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Intensidade do volume',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'VOLUME,3#',
        'desc' => '🔊 VOLUME',
      ],
    ],
  ],
  'WIFIAP,A#' => [
    'cmd' => 'WIFIAP',
    'nome' => 'HOTSPOT',
    'desc' => 'Para ativar o hotspot da JC400, envie',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
      3 => 'JC181',
      4 => 'JC400D',
      5 => 'JC400AD',
    ],
    'universal' => true,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      // A015 da planilha JC371: a forma completa é `WIFIAP,<estado>,<nome>,
      // <senha>`; esta entrada é a de um campo, que só liga e desliga.
      0 => ['p' => 'A', 'desc' => 'ON liga o ponto de acesso, OFF desliga', 'format' => 'ON/OFF', 'default' => 'ON'],
    ],
    'exemplos' => [
    ],
  ],
  'WIFIAPT,30#' => [
    'cmd' => 'WIFIAPT',
    'nome' => 'Tempo de duração do Wi-Fi',
    'desc' => '',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC182',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => 'WIFIAPT#',
    'consulta_modelos' => ['JC182', 'JC371'],
    'consulta_ref' => 'medido',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],

  // ══════════════════════════════════════════════════════════════════════════
  //  v4.9.27 — comandos da planilha oficial JIMI
  //  "JC400 & JC261 Command List V5.0.3.20230626.xlsx" (docs/)
  //
  //  🔑 JC261 É A NOSSA JC400AD. A planilha nomeia o modelo pelo código de
  //  fábrica, então "Only for JC261 & JC261P" vale para a JC400AD — inclusive
  //  os SETE comandos ADASxx (G009–G015), que são o núcleo do produto e não
  //  existiam aqui. "ALL" nesta planilha é a família JC400/JC261, mapeada para
  //  JC400AD + JC400D; ENCRYPT (228) e FACERECOGNITION (518) ficaram de fora
  //  por serem de outra linha de produto.
  //
  //  `fonte` guarda o código da linha na planilha (A007, G014…) — a mesma
  //  disciplina de procedência do `consulta_ref` e do `doc_ref`.
  //
  //  ⚠️ `consulta` nasce NULL em todas: a planilha não publica forma de
  //  pergunta, e inventar uma seria o palpite que este catálogo evita.
  // ══════════════════════════════════════════════════════════════════════════
  'COREKITSW,A#' => [
    'cmd' => 'COREKITSW',
    'nome' => 'Método de envio de dados (integração)',
    'desc' => 'The device will use Jimi\'s method to upload the data to the Tracksolid Pro server, if you want to use other platforms, you need to ues this command to change to integrated method.',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A001',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1. It refers to the working logic, wherein "0" refers to the integrated version and "1" the distributed version. Note: Before switching the device to the integrated method, you must first do the followings in the st...',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'COREKITSW,0',
        'desc' => 'exemplo oficial (A001)',
      ],
    ],
  ],
  'HTTPUPLOADLIMIT,A,B#' => [
    'cmd' => 'HTTPUPLOADLIMIT',
    'nome' => 'Tentativas de envio do vídeo do evento',
    'desc' => 'It defines the mechanism to deal with such a situation as the platform doesn\'t respond after the device uploads data over HTTP.',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A004',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1–10. It specifies the retry count. Default: 5.',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '1–30 (minutes). It specifies the interval between each retry. Default: 3.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'HTTPUPLOADLIMIT,5,3',
        'desc' => 'exemplo oficial (A004)',
      ],
    ],
  ],
  'FILELIST' => [
    'cmd' => 'FILELIST',
    'nome' => 'Lista de gravações do cartão (JIMI)',
    // Este é o comando que PEDE a lista. O endereço de destino vem da
    // configuração gravada antes por `FILELIST,<url>` (A006) — sem endereço
    // válido o device responde `failed!`, medido em campo.
    'desc' => 'PEDE à câmera que envie a lista de gravações do cartão para o endereço já configurado nela. Não aceita intervalo de datas: envia a lista inteira.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A007',
    'params' => [
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'FILELIST',
        'desc' => 'exemplo oficial (A007)',
      ],
    ],
  ],
  'REPLAYLIST,A#' => [
    'cmd' => 'REPLAYLIST',
    'nome' => 'Push de vídeo histórico p/ RTMP',
    'desc' => 'Let the device to push the playback video streaming to RTMP server, then you can use them to display in your platform.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A008',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'REPLAYLIST,2021_05_31_08_10_45_02.mp4,2021_05_31_08_11_46_02.mp4,2021_05_31_08_12_48_02.mp4',
        'desc' => 'exemplo oficial (A008)',
      ],
    ],
  ],
  'REPLAYLIST,OFF' => [
    'cmd' => 'REPLAYLIST',
    'nome' => 'Push de vídeo histórico p/ RTMP',
    'desc' => 'Stop pushing playback video streaming.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A009',
    'params' => [
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'REPLAYLIST,OFF',
        'desc' => 'exemplo oficial (A009)',
      ],
    ],
  ],
  'HVIDEO,A,B#' => [
    'cmd' => 'HVIDEO',
    // 🔴 25/08/2026 — TESTADO e RECUSADO no JC371 (Telecom, 865478070654829):
    // "Command was not recognized!" — o firmware nem conhece o verbo. É
    // comando JIMI (device_models.protocol='JIMI'), não JT/T; JC371 fala
    // JT/T. Pra pedir upload de anexo JT/T o comando é VIDEOUPLOAD (ver
    // entrada própria neste catálogo) ou 37384/0x9208 no binário — e 37384
    // por sua vez FOI medido aceito ("ok") mas nunca produziu upload real
    // (ver STATUS.md 25/08/2026 e docs/COMANDOS_128_CONSULTA.md §9). NÃO
    // reintroduzir HVIDEO/EVIDEO como fallback pra device JT/T sem medir de
    // novo — os dois já causaram um ciclo de tentativa-e-erro documentado.
    'nome' => 'Enviar vídeo histórico da memória',
    'desc' => 'You can request the device to upload the playback video file which store in memory (which is one minute each file and with low video quality) to the server.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A010',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'The timestamp which including in the video to upload (format: Year_Month_Day_Hour_Minute_Second)',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '1/2 (1=Front camera; 2=Inward camera)',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'HVIDEO,2020_01_01_24_05_06,1',
        'desc' => 'exemplo oficial (A010)',
      ],
    ],
  ],
  'EVIDEO,A,B,C#' => [
    'cmd' => 'EVIDEO',
    // 🔴 25/08/2026 — TESTADO e RECUSADO no JC371 (Telecom, 865478070654829):
    // "Error:Number of parameters errors!" com a forma de 2 parâmetros
    // (sem duração — a que includes/alarm_video_request.php usava). Mesma
    // história do HVIDEO acima: é comando JIMI, JC371 fala JT/T. Ver
    // docs/COMANDOS_128_CONSULTA.md §9.
    'nome' => 'Gerar e enviar trecho do cartão TF',
    'desc' => 'This command is for High video quality which record and stored in TF card with 3 mins for each video file. You can request the device to generate a new short video file with the period you need, and then upload the file to the server.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A011',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'The timestamp to generate pre & post video (Format=Year-Month-Day Hour:Minute:Second)',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '1/2 1=Front camera; 2=Inward camera;',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '10–60 (seconds). It refers to the video length. Default: 15',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVIDEO,2020-06-15 12:12:12,1,30',
        'desc' => 'exemplo oficial (A011)',
      ],
    ],
  ],
  'Video,A,B#' => [
    'cmd' => 'Video',
    'nome' => 'Capturar vídeo (H.264)',
    'desc' => 'Capture the video (H.264) from the device.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A013',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'Video,in,3s',
        'desc' => 'exemplo oficial (A013)',
      ],
    ],
  ],
  // 🔴 A FORMA DO STREAM É `RTMP,ON,<CÂMERA>` — SEM tempo. O `<C>` da planilha
  // (A014) existe, mas só em firmware V4.3+ e não governa a transmissão: o doc
  // oficial de "pull live stream" (docs.jimicloud.com/test/test.html) usa
  // `RTMP,ON,INOUT` e diz que o stream cai sozinho ~20 s depois que o último
  // leitor sai. Quem tem tempo é `Video,<câmera>,<segundos>`, que é CAPTURA de
  // clipe, não streaming — confundir os dois foi engano nosso na v4.9.27,
  // corrigido pelo operador.
  //
  // O device recusa o resto: "RTMP,parameter B error. options:[IN,OUT,INOUT,PIP]".
  'RTMP,A,B#' => [
    'cmd' => 'RTMP',
    'nome' => 'Transmissão ao vivo (RTMP)',
    'desc' => 'Request live streaming. O device faz PUSH para o endereço gravado em RSERVICE.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A014 + docs.jimicloud.com (pull live stream)',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON liga a transmissão; OFF encerra',
        'format' => '',
        'default' => 'ON',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'câmera: IN (cabine), OUT (frontal), INOUT (as duas) ou PIP',
        'format' => '',
        'default' => 'OUT',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RTMP,ON,INOUT',
        'desc' => 'exemplo oficial do doc de live stream — publica CH0 (OUT) e CH1 (IN)',
      ],
      1 => [
        'cmd' => 'RTMP,OFF',
        'desc' => 'encerra a transmissão (medido: device responde RTMP:OK!)',
      ],
    ],
  ],
  'APN,A,B,C,D,E,F,G,H,I,J,K,L,M,N#' => [
    'cmd' => 'APN',
    'nome' => '2 - Configurações',
    'desc' => 'Add and set the APN of the SIM card in detail',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B002',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'APN name',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'APN',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'MCC',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => 'MNC',
        'format' => '',
        'default' => '',
      ],
      4 => [
        'p' => 'E',
        'desc' => 'TYPE',
        'format' => '',
        'default' => '',
      ],
      5 => [
        'p' => 'F',
        'desc' => 'PROXY',
        'format' => '',
        'default' => '',
      ],
      6 => [
        'p' => 'G',
        'desc' => 'PORT',
        'format' => '',
        'default' => '',
      ],
      7 => [
        'p' => 'H',
        'desc' => 'USER',
        'format' => '',
        'default' => '',
      ],
      8 => [
        'p' => 'I',
        'desc' => 'SERVER',
        'format' => '',
        'default' => '',
      ],
      9 => [
        'p' => 'J',
        'desc' => 'PASSWORD',
        'format' => '',
        'default' => '',
      ],
      10 => [
        'p' => 'K',
        'desc' => 'MMSC',
        'format' => '',
        'default' => '',
      ],
      11 => [
        'p' => 'L',
        'desc' => 'MMSPROXY',
        'format' => '',
        'default' => '',
      ],
      12 => [
        'p' => 'M',
        'desc' => 'MMSPORT',
        'format' => '',
        'default' => '',
      ],
      13 => [
        'p' => 'N',
        'desc' => 'NUMERIC When only A, B, C, and D are required to be set for the APN, you can deliver it as a simple parameter; while if more parameters ("E" and these following it) are required to be set, commas (,) should be used to...',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'APN,vivo,vivo,427,06 APN,vivo,vivo,427,06,,,,JIMI,,JIMI,,,',
        'desc' => 'exemplo oficial (B002)',
      ],
    ],
  ],
  'NETWORK,A#' => [
    'cmd' => 'NETWORK',
    'nome' => 'Tipo de rede (LTE)',
    'desc' => 'Select network type',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B003',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '9 LTE first A=11 LTE only',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'NETWORK,11',
        'desc' => 'exemplo oficial (B003)',
      ],
    ],
  ],
  'ROAMING,A#' => [
    'cmd' => 'ROAMING',
    'nome' => 'Roaming',
    'desc' => 'Enable or disable roaming feature.',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B004',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF It is the roaming switch.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ROAMING,ON',
        'desc' => 'exemplo oficial (B004)',
      ],
    ],
  ],
  'WIFIAP,A,B,C#' => [
    'cmd' => 'WIFIAP',
    'nome' => 'HOTSPOT',
    'desc' => 'Turn on/off the WiFi hotspot, AP Mode',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B005',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF WiFi hot-spot switch.',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Hot-spot name, default is IMEI number',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'password, default is last 8 digits of IMEI',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'WIFIAP,ON,ABCD,12345678',
        'desc' => 'exemplo oficial (B005)',
      ],
    ],
  ],
  'SSID,A,B,C#' => [
    'cmd' => 'SSID',
    'nome' => 'SSID',
    'desc' => 'Trun on/off WiFi, Client Mode',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B006',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1/2/3, 0 means off, 1 means WIFI enable during acc on, 2 means WIFI enable all the time, 3 means delete the wifi connection record',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Router\'s name',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Router\'s password Remark: A=ON/OFF @ firmware V4.2.x or above',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SSID,2,JIMI,JIMI@123',
        'desc' => 'exemplo oficial (B006)',
      ],
    ],
  ],
  'BTNAME,A#' => [
    'cmd' => 'BTNAME',
    'nome' => 'Bluetooth',
    'desc' => 'Turn on/off the Bluetooth',
    'categoria' => 'rede',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B007',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF It is a switch to enable the Bluetooth. Only when A is set to "ON" will the device enable the Bluetooth after entering ACC ON mode.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'BTNAME,ON',
        'desc' => 'exemplo oficial (B007)',
      ],
    ],
  ],
  'GTRANS,A,B,C,D#' => [
    'cmd' => 'GTRANS',
    'nome' => 'Coleta e envio de dados do acelerômetro',
    'desc' => 'Collect and upload G-Sensor data',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B013',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1/2 It is a function switch, wherein 0 means the function is off, 1 transparent transmission over TCP, and 2 transparent transmission over HTTP.',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '1/2 It refers to the transmission mode, wherein 1 means timed upload and 2 means re-upload.',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '1–10 (TCP)/10–100 (HTTP) It refers to the sampling rate and the unit is samples per second. The default value 6 samples per second for transmission over TCP and 100 samples per second for transmission over HTTP.',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => '1–60 It refers to the time for timed upload and the unit is second. The time for timed upload over TCP is 2s (unchangeable) and the time for timed upload over HTTP is 10s.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'GTRANS,2,2,100,10',
        'desc' => 'exemplo oficial (B013)',
      ],
    ],
  ],
  'GCALIBRAT' => [
    'cmd' => 'GCALIBRAT',
    'nome' => 'Calibrar acelerômetro',
    'desc' => 'Calibrate the G-Sensor',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B014',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'RANGE,A#' => [
    'cmd' => 'RANGE',
    'nome' => 'Faixa do acelerômetro',
    'desc' => 'Set the G-Sensor application range.',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B015',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '2/4/8/16 It specifies the measuring range of GSENSOR, will effect the crashalm sensitivity',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RANGE,2',
        'desc' => 'exemplo oficial (B015)',
      ],
    ],
  ],
  'LOG,ALL,A#' => [
    'cmd' => 'LOG',
    'nome' => 'Enviar logs do equipamento',
    'desc' => 'Upload logs to Jimi server or specific TCP server',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B019',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'LOG,ALL,http://120.237.87.194:1115/upload',
        'desc' => 'exemplo oficial (B019)',
      ],
    ],
  ],
  'PING,A#' => [
    'cmd' => 'PING',
    'nome' => 'Testar conexão de rede',
    'desc' => 'Check the network connection status.',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B022',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'TCP/HTTP/RTMP, device will ping the server to check the connection',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'PING,HTTP',
        'desc' => 'exemplo oficial (B022)',
      ],
    ],
  ],
  'PASSWORD,<A><B>#' => [
    'cmd' => 'PASSWORD',
    'nome' => 'Alterar senha de comando',
    'desc' => 'Change the password of the command.',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B023',
    'params' => [
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'PASSWORD,666666,123456',
        'desc' => 'exemplo oficial (B023)',
      ],
    ],
  ],
  'FORMAT' => [
    'cmd' => 'FORMAT',
    'nome' => 'Formatar cartão SD',
    'desc' => 'Format the memory card',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 B024',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'CAMERA,A,B#' => [
    'cmd' => 'CAMERA',
    'nome' => 'Espaço do cartão',
    'desc' => 'Set the parameters for normal recording video which will be saved in TF card.',
    'categoria' => 'manutencao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 C004',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'IN/OUT',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0/1/2/3 When OUT, 0 is 1080P 8M; 1 is 720P 4M; 2 is 720*480 2M; 3 is 640*360 0.5M When IN, 0 is 720P 6M; 1 is 720P 3M; 2 is 720*480 2M; 3 is 640*360 0.5M',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'CAMERA,OUT,1',
        'desc' => 'exemplo oficial (C004)',
      ],
    ],
  ],
  'VIDEORESOLUTION_SUB,A#' => [
    'cmd' => 'VIDEORESOLUTION_SUB',
    'nome' => 'Resolução do sub-stream (ao vivo/playback)',
    'desc' => 'Set the parameters for live streaming or playback video',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 C005',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1/2 0=640x360, bitrate 0.5M 1=720x480, bitrate 1M 2=720x480, bitrate 1.5M',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'VIDEORESOLUTION_SUB,1',
        'desc' => 'exemplo oficial (C005)',
      ],
    ],
  ],
  'CAR,A,B,C#' => [
    'cmd' => 'CAR',
    'nome' => 'Conteúdo da marca d’água do vídeo',
    'desc' => 'Customize the content of the video watermark.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 C007',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'CAR,ABCD,12345,EFG67890HIJK',
        'desc' => 'exemplo oficial (C007)',
      ],
    ],
  ],
  'MIRROR,in,A#' => [
    'cmd' => 'MIRROR',
    'nome' => 'Espelhamento da câmera interna',
    'desc' => 'Set the mirroring mode of the backup camera (rear-view)',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 C008',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF Whether to enable the mirroring mode of the backup camera (rear-view)',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'MIRROR,in,OFF',
        'desc' => 'exemplo oficial (C008)',
      ],
    ],
  ],
  'PICTIMER,A,B,C#' => [
    'cmd' => 'PICTIMER',
    'nome' => 'Captura programada de fotos',
    'desc' => 'Enable or disable feature.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 C010',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF. It defines whether to enable timed image taking.',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '30–300 (seconds). It specifies the length of the timer. Default: 300.',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '1–3. It specifies how many times will the device take images during one trigger. Default: 1.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'PICTIMER,ON,300,1',
        'desc' => 'exemplo oficial (C010)',
      ],
    ],
  ],
  'PICTIMERSIZE,A,B#' => [
    'cmd' => 'PICTIMERSIZE',
    'nome' => 'Resolução da foto temporizada',
    'desc' => 'Set the resolution of photos',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 C011',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'IN/OUT; OUT is front camera; IN is inner camera.',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0/1/2, 0 is 1080P, 1 is 720P, 2 is 480P When A is OUT, B can be set to be 0/1/2 When A is IN, B can be 1/2 only',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'PICTIMERSIZE,OUT,1',
        'desc' => 'exemplo oficial (C011)',
      ],
    ],
  ],
  'TIMERPICRAM' => [
    'cmd' => 'TIMERPICRAM',
    'nome' => 'Espaço das fotos temporizadas',
    'desc' => 'Query the size of images in the device that are taken via this feature',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 C013',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'TIMERPICRAM,DEL' => [
    'cmd' => 'TIMERPICRAM',
    'nome' => 'Espaço das fotos temporizadas',
    'desc' => 'Delete images that are taken via this feature from the device',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 C014',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'EVENTGPS,A#' => [
    'cmd' => 'EVENTGPS',
    'nome' => 'Reenvio do pacote de posição do evento',
    'desc' => 'Event-generated location packet re-upload',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 D004',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF It is a function switch. When A is set to "ON", the device will re-upload a location packet every time an event is triggered.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTGPS,OFF',
        'desc' => 'exemplo oficial (D004)',
      ],
    ],
  ],
  'BUFFERCACHEQUERY' => [
    'cmd' => 'BUFFERCACHEQUERY',
    'nome' => 'Reenvio do pacote de alerta do evento',
    'desc' => 'Event-generated alert packet re-upload',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 D005',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'MILEAGE,A,B#' => [
    'cmd' => 'MILEAGE',
    'nome' => 'Hodômetro (ajuste manual)',
    'desc' => 'Enable or disable mileage feature',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 D006',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'current mileage value, default is 0, unit is meter',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'MILEAGE,ON',
        'desc' => 'exemplo oficial (D006)',
      ],
    ],
  ],
  'FILTER,A,B#' => [
    'cmd' => 'FILTER',
    'nome' => 'Intervalo de filtro de eventos',
    'desc' => 'Set the interval of the device to trigger the same type of events',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E001',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Event type SOS / CRASH / VIBRATE / OVERSPEED / RAPIDACC / RAPIDDEC / RAPIDTURN / DRIVE / POWER / VOLTAGELOW / CLOSEEYES / YAWN / DISTRACTION / SMOKING / PHONECALLING / RELAYOFF / RELAYRECOVERY / MISSINGFACE / NOSDCARD...',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '1–60; It defines the time interval to trigger a same-type event after the last one (input a value) Default: 5 Unit: Minute',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'FILTER,CRASH,5',
        'desc' => 'exemplo oficial (E001)',
      ],
    ],
  ],
  'UPLOADSW,A,B#' => [
    'cmd' => 'UPLOADSW',
    'nome' => 'Upload por tipo de evento',
    'desc' => 'Set device to upload event video by auto or not.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E002',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Event type SOS / CRASH / VIBRATE / OVERSPEED / RAPIDACC / RAPIDDEC / RAPIDTURN / DRIVE / POWER / VOLTAGELOW / CLOSEEYES / YAWN / DISTRACTION / SMOKING / PHONECALLING / RELAYOFF / RELAYRECOVERY / MISSINGFACE / NOSDCARD...',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'OFF, ON, 1, 2 It indicates whether to upload event videos automatically or on demand. OFF=Do not upload by auto ON=Upload front / inward camera\'s video both 1=Upload front camera video only 2=Upload imward camera vide...',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'UPLOADSW,CRASH,ON',
        'desc' => 'exemplo oficial (E002)',
      ],
    ],
  ],
  'EXBATALM,A,B#' => [
    'cmd' => 'EXBATALM',
    'nome' => 'Subtensão da bateria do veículo',
    'desc' => 'Set the undervoltage event threshold, this feature will prevent your vehicle\'s battery from draining.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E005',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1; It indicates the vehicle\'s battery type 0=12V 1=24V',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Threshold value Value range for 12V vehicles:90–130, Default: 118 Value range for 24V vehicles: 180–255, Default: 230 wherein 90,180 indicates the undervoltage alert value is 9V.18V, therefore if you set the value to ...',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EXBATALM,0,115',
        'desc' => 'exemplo oficial (E005)',
      ],
    ],
  ],
  'SOS,A,<A>,<B>,<C>#' => [
    'cmd' => 'SOS',
    'nome' => 'Números SOS',
    'desc' => 'Add SOS numbers(s), then if you set report method to 2&3, the device will make the call to this list.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E009',
    'params' => [
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SOS,A,123456789',
        'desc' => 'exemplo oficial (E009)',
      ],
    ],
  ],
  'SOS,D <A>,<B>,<C>#' => [
    'cmd' => 'SOS',
    'nome' => 'Números SOS',
    'desc' => 'Delete SOS number(s) of the list.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E010',
    'params' => [
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SOS,D,123456789',
        'desc' => 'exemplo oficial (E010)',
      ],
    ],
  ],
  'CALL,A#' => [
    'cmd' => 'CALL',
    'nome' => 'Ciclos de ligação do SOS',
    'desc' => 'Set the cycle count of the SOS calls, which the device will call to the SOS list after the event be triggered.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E011',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1/2/3. It specifies the cyclic dialing count; Default: 2',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'CALL,2',
        'desc' => 'exemplo oficial (E011)',
      ],
    ],
  ],
  'SHOCK,A#' => [
    'cmd' => 'SHOCK',
    'nome' => 'Sensibilidade de vibração (detalhada)',
    'desc' => 'Set the sensitivity to trigger a vibration event when the vehicle parking in detail. This command is the same as the SENALM and CRASHALM command, but it is more specific.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E012',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1–255. It specifies the sensitivity range, wherein the lower the value, the more sensitive the vehicle to detect a vibration. How to count the acceleration (x+1)/256*RANGE eg: RANGE=2, SHOCK,40, SENSOR,255 so vibratio...',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SHOCK,10',
        'desc' => 'exemplo oficial (E012)',
      ],
    ],
  ],
  'DEFENSE,A#' => [
    'cmd' => 'DEFENSE',
    'nome' => 'Modo de vigilância (estacionado)',
    'desc' => 'Enable or disable Defense mode/',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E013',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF; Whether to enable the defense mode',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'DEFENSE,ON',
        'desc' => 'exemplo oficial (E013)',
      ],
    ],
  ],
  'DEFENSE_TIME,A#' => [
    'cmd' => 'DEFENSE_TIME',
    'nome' => 'Atraso para entrar em vigilância',
    'desc' => 'Set the period delay for the device to entry defense mode after the ACC OFF. Need to make sure already enable the defense mode.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E014',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1–30; It refers to the delay time; Unit: Minute. Default: 5',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'DEFENSE_TIME,5',
        'desc' => 'exemplo oficial (E014)',
      ],
    ],
  ],
  'SHAKEDELAY,A#' => [
    'cmd' => 'SHAKEDELAY',
    'nome' => 'Janela sem alerta de vibração após ACC ON',
    'desc' => 'It refers to the time during which a vibrating alert won\'t be triggered if the device is ACC ON during that time. It will filtter the normal drive behavior/',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E016',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '10–600 Unit: Seconds',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SHAKEDELAY,60',
        'desc' => 'exemplo oficial (E016)',
      ],
    ],
  ],
  'SENSOR,A#' => [
    'cmd' => 'SENSOR',
    'nome' => 'Sensibilidade de colisão (valor direto)',
    'desc' => 'Set the sensitivity with the value when the CRASHALM not match your requirment.',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E018',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1–255 The lower the value, the more sensitive the device to trigger a collision event/ Default: 150',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SENSOR,255',
        'desc' => 'exemplo oficial (E018)',
      ],
    ],
  ],
  'RAPIDACC,A#' => [
    'cmd' => 'RAPIDACC',
    'nome' => 'Aceleração Brusca',
    'desc' => 'Set the sensitivity level to trigger harsh acceleration event. If you want to have more choice to set the value, you can use command "RAPIDTEST".',
    'categoria' => 'posicao',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E019',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1/2/3; Detect time is 3 second 0-Off, 1-Low 45, 2-Mid 35, 3-High 25, Unit is kmh',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RAPIDAC,2',
        'desc' => 'exemplo oficial (E019)',
      ],
    ],
  ],
  'RAPIDDEC,A#' => [
    'cmd' => 'RAPIDDEC',
    'nome' => 'Frenagem Brusca',
    'desc' => 'Set the sensitivity level to trigger harsh acceleration event. If you want to have more choice to set the value, you can use command "RAPIDTEST".',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E020',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1/2/3; Detect time is 3 second 0-Off, 1-Low 55, 2-Mid 45, 3-High 25, Unit is kmh',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RAPIDDEC,2',
        'desc' => 'exemplo oficial (E020)',
      ],
    ],
  ],
  'RAPIDTURN,A#' => [
    'cmd' => 'RAPIDTURN',
    'nome' => 'Curva Brusca',
    'desc' => 'Set the sensitivity level to trigger harsh acceleration event. If you want to have more choice to set the value, you can use command "RAPIDTEST".',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E021',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1/2/3; Detect time is 3 second 0-Off, 1-Low 60, 2-Mid 40, 3-High 30, Unit is kmh',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RAPIDTURN,2',
        'desc' => 'exemplo oficial (E021)',
      ],
    ],
  ],
  'RAPIDTEST,A,B,C#' => [
    'cmd' => 'RAPIDTEST',
    'nome' => 'Limiares de direção agressiva',
    'desc' => 'Set the threshold to trigger an aggressive driving behavior alert',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E022',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1–255. It specifies the threshold to trigger a harsh acceleration alert, unit is kmh',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '1–255. It specifies the threshold to trigger a harsh braking alert, unit is kmh',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '1–255. It specifies the threshold to trigger a harsh cornering alert, unit is kmh',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RAPIDTEST,30,40,70',
        'desc' => 'exemplo oficial (E022)',
      ],
    ],
  ],
  'NOSDCARDALM,A,B#' => [
    'cmd' => 'NOSDCARDALM',
    'nome' => 'Alarme de erro do cartão de memória',
    'desc' => 'Set the parameters of',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 E023',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF. It specifies whether to enable the feature to trigger an alert when the memory card is inserted or removed.',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0–3. It specifies the alert mode. 0: GPRS, 1: SMS+GPRS, 2: GPRS+SMS+Call, 3: GPRS+Call',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'NOSDCARDALM,ON,2',
        'desc' => 'exemplo oficial (E023)',
      ],
    ],
  ],
  'UART,A,B,C,D,E,F#' => [
    'cmd' => 'UART',
    'nome' => 'Sensor de porta pela UART',
    'desc' => 'Connect the door sensor via the UART, then you can enable or disable this function.',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 F002',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1/2;it defines the trigger condition 0 - disable the function 1 - take close as a trigger 2 - take open as a trigger',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0/1/2;it defines ACC state 0- detecting in any state 1 -detecting only in ACC ON 2- detecting only in ACC OFF',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '1~3600, it defines the Detection interval unit is second,default is 120',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => '1~120, it defines the speed condition, GPS speed 0 is unlimited unit is kmh',
        'format' => '',
        'default' => '',
      ],
      4 => [
        'p' => 'E',
        'desc' => '1/2, it defines the Action after trigger 1 -short video 2 -photo',
        'format' => '',
        'default' => '',
      ],
      5 => [
        'p' => 'F',
        'desc' => '0/1/2, it defines the whether to broadcast voice after trigger 0 is no broadcast 1 is seat belt version 2 is door sensor detection version, while F=0,means door sensor detection without voice prompt 1',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'UART,1,0,30,10,2,0',
        'desc' => 'exemplo oficial (F002)',
      ],
    ],
  ],
  'SPEEDOMETER,A#' => [
    'cmd' => 'SPEEDOMETER',
    'nome' => 'Velocímetro',
    'desc' => 'It is a function switch',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 F003',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF An accessory is required to be connected to use this feature.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SPEEDOMETER,ON',
        'desc' => 'exemplo oficial (F003)',
      ],
    ],
  ],
  'CARDREADER,A#' => [
    'cmd' => 'CARDREADER',
    'nome' => 'Leitor de cartão magnético',
    'desc' => 'It is a function switch',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 F004',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF An accessory is required to be connected to use this feature.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'CARDREADER,ON',
        'desc' => 'exemplo oficial (F004)',
      ],
    ],
  ],
  'DRIVERLEVEL,A,B,C,X#' => [
    'cmd' => 'DRIVERLEVEL',
    'nome' => 'Níveis de permissão do leitor de cartão',
    'desc' => 'Set the permission level for the card reader',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 F005',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'X',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'OILPARAM,A,B,C,D#' => [
    'cmd' => 'OILPARAM',
    'nome' => 'Limiares do sensor de nível de combustível',
    'desc' => 'Set the threshold fuel level at which the sensor will generate an alert',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 F007',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0–60 (min). It refers to the interval to collect fuel level data when the vehicle is ACC OFF and the value "0" indicates the sensor will not collect data.',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0–60 (min). It refers to the interval to collect fuel level data when the vehicle is ACC ON and the value "0" indicates the sensor will not collect data.',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '1–10000. It refers to the difference between the fuel level data collected before and after the vehicle is ACC OFF, at which value a fuel exception alert will be triggered. The accuracy is "0.01".Default: 1000',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => '1–10000. It refers to the difference between the fuel level data collected before and after the vehicle is ACC ON, at which value a fuel exception alert will be triggered. The accuracy is "0.01".Default: 1000 For exam...',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'OILPARAM,1,1,1000,1000',
        'desc' => 'exemplo oficial (F007)',
      ],
    ],
  ],
  'OILIDSET,A,B#' => [
    'cmd' => 'OILIDSET',
    'nome' => 'ID do sensor de combustível',
    'desc' => 'Set the ID of the fuel level sensor.',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 F008',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1. It refers to the fuel level sensor to set. The device supports two fuel level sensors: A and B. 0: Fuel level sensor A; 1: Fuel level sensor B',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0–254. It refers to the ID of the fuel level sensor to set (The IDs for the two fuel level sensors should be set differently) If no parameters are specified in a query command, the device will return the data of the t...',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'OILIDSET,0,01',
        'desc' => 'exemplo oficial (F008)',
      ],
    ],
  ],
  'TEMPCOLLECTINTERVAL,A,B#' => [
    'cmd' => 'TEMPCOLLECTINTERVAL',
    'nome' => 'Intervalo de coleta de temperatura',
    'desc' => 'Set the interval to collect temperature data',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 F009',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'TEMPCOLLECTINTERVAL,1,1',
        'desc' => 'exemplo oficial (F009)',
      ],
    ],
  ],
  'TCALIBRAT#' => [
    'cmd' => 'TCALIBRAT',
    'nome' => 'Formato do dado do sensor de temperatura',
    'desc' => 'Set the format of the data collected by the temperature sensor',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 F010',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'DMSSW,A#' => [
    'cmd' => 'DMSSW',
    'nome' => 'Chave de Funções de IA',
    'desc' => 'Set sub-camera for JC261 series product, if you connect device with JC170, then you need to send command to change it first. Note: After you change the mode, the device will restart 10 seconds later',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G001',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/3 0=AHD version 3=JC170 version',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'DMSSW,3',
        'desc' => 'exemplo oficial (G001)',
      ],
    ],
  ],
  'DMS_CALIB_ABNORMAL,A,B,C#' => [
    'cmd' => 'DMS_CALIB_ABNORMAL',
    'nome' => 'DMS: evento de falha de alinhamento',
    'desc' => 'Alignment exception event',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G007',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1–10. It indicates after how many alignment exceptions will the device generate a relevant alert. If A is set to "0", the feature is disabled.',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0/1 (1: On, 0: Off ); Whether to notify the user via sound upon an alignment exception.',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '0/1, wherein "0" indicates do not upload and "1" indicates upload. It is used to set whether to upload alignment exception messages to the platform.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'DMS_CALIB_ABNORMAL,3,1,0',
        'desc' => 'exemplo oficial (G007)',
      ],
    ],
  ],
  'DMS_SECOND_EVENT,A,B,C,D#' => [
    'cmd' => 'DMS_SECOND_EVENT',
    'nome' => 'DMS: eventos de nível 2 (L2)',
    'desc' => 'Feature switch for level 2 (L2) events',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G008',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1–6; It indicates the type of L2 events to set; 1: Distracted; 2: Eyes closed; 3: Yawning; 4: Calling; 5: Smoking; 6: No face detected.',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0/1–10; It refers to the number of consecutive trigger times of L2 events. 0 indicates the feature is disabled.',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '1–180 Unit: second It indicates the duration to compute the number of L2 events.',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => '0/1–10 Unit: second It indicates how long will the buzzer sound after an L2 event is triggered. 0 indicates the feature is disabled.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'DMS_SECOND_EVENT,2,5,60,3',
        'desc' => 'exemplo oficial (G008)',
      ],
    ],
  ],
  'ADASSW,A#' => [
    'cmd' => 'ADASSW',
    'nome' => 'ADAS: liga/desliga a função',
    'desc' => 'Function switch, Enable or Disable ADAS Function Note: After you enable or disable the function, the device will restart 10 seconds later',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G009',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0/1 0=Disable 1=Enable Need to reboot the device after sending the command.',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ADASSW,1',
        'desc' => 'exemplo oficial (G009)',
      ],
    ],
  ],
  'ADASSEP,A,B#' => [
    'cmd' => 'ADASSEP',
    'nome' => 'ADAS: liga/desliga cada evento',
    'desc' => 'Enable or Disable each ADAS function Note: Please make sure the ADAS funtion is enabled (G009)',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G010',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Type of event, fill in with the code 1=ADAS function, FCW, front car collision 2=ADAS function, HMW, vehicle too close 3=ADAS function, LDW, lane deviation',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '0/1 0=disable 1=enable Default FCW:1,HMW:1,LDW:1',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ADASSEP,2,1',
        'desc' => 'exemplo oficial (G010)',
      ],
    ],
  ],
  'ADASPI,A,B#' => [
    'cmd' => 'ADASPI',
    'nome' => 'ADAS: filtro de alertas repetidos',
    'desc' => 'Set the device to filter alerts for the same type of events Note: Please make sure the ADAS funtion is enabled (G009)',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G011',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Type of event, fill in with the code 1=ADAS function, FCW, front car collision 2=ADAS function, HMW, vehicle too close 3=ADAS function, LDW, lane deviation',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Period，0-3600 Unit: Second Default FCW:60,HMW:60,LDW:60',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ADASPI,2,50',
        'desc' => 'exemplo oficial (G011)',
      ],
    ],
  ],
  'ADASVI,A,B#' => [
    'cmd' => 'ADASVI',
    'nome' => 'ADAS: filtro de avisos sonoros repetidos',
    'desc' => 'Set the device to filter same voice announcements Note: Please make sure the ADAS funtion is enabled (G009)',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G012',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Type of event, fill in with the code 1=ADAS function, FCW, front car collision 2=ADAS function, HMW, vehicle too close 3=ADAS function, LDW, lane deviation',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Period，0-3600 Unit: Second Default FCW:60,HMW:60,LDW:60',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ADASVI,2,50',
        'desc' => 'exemplo oficial (G012)',
      ],
    ],
  ],
  'ADASSP,A,B#' => [
    'cmd' => 'ADASSP',
    'nome' => 'ADAS: velocidade mínima para disparar',
    'desc' => 'Set the speed threshold value which will enable device to trigger the ADAS event after device\'s speed over it. Note: Please make sure the ADAS funtion is enabled (G009)',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G013',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Type of event, fill in with the code 1=ADAS function, FCW, front car collision & HMW, vehicle too close 2=ADAS function, LDW, lane deviation',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'speed, unit :km/h AI events will only be triggered when the vehicle reaches this preset speed value Default: FCW:30,HMW:30,LDW:60',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ADASSP,1,60 ADASSP,2,60',
        'desc' => 'exemplo oficial (G013)',
      ],
    ],
  ],
  'ADASSEN,A,B,C#' => [
    'cmd' => 'ADASSEN',
    'nome' => 'ADAS: sensibilidade por evento',
    'desc' => 'Set the trigger sensitivity of each ADAS event. Note: Please make sure the ADAS funtion is enabled (G009)',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G014',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '1/2/3, event type 1=Lane departure warning 2=Forward collision warning 3=Headway mornitor warning when A=1,',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '-0.3~0.6, defualt=-0.1',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '1 The smaller the value, the more sensitive.A negative value indicates the distance to the compression line, while a positive value indicates the distance to the compression line.There is no limit to the number of dig...',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ADASSEN,1,-0.2,1 ADASSEN,2,2.0 ADASSEN,3,2.5',
        'desc' => 'exemplo oficial (G014)',
      ],
    ],
  ],
  'ADASVSP,A#' => [
    'cmd' => 'ADASVSP',
    'nome' => 'ADAS: velocidade simulada (teste em bancada)',
    'desc' => 'Set the speed to the device to simulate a driving test scenario, which will let you enable to test the ADAS function in office.',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 G015',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '10-120 Unit:km/h',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ADASVSP,60',
        'desc' => 'exemplo oficial (G015)',
      ],
    ],
  ],
  'UPLOADFILE,A#' => [
    'cmd' => 'UPLOADFILE',
    'nome' => 'Enviar vídeos de um tipo de evento',
    'desc' => 'Upload videos of a specific event type (a command to upload video files on demand)',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 H001 (Private)',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'The name of the file to upload For Tracksolidpro',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'UPLOADFILE,EVENT_357730090564767_00000000_2021_01_29_07_28_18_F_05.mp4',
        'desc' => 'exemplo oficial (H001)',
      ],
    ],
  ],
  'WIFIKIT,Get_first_page_info' => [
    'cmd' => 'WIFIKIT',
    'nome' => 'WIFIKIT: informações da página inicial',
    'desc' => 'Get the information of the homepage',
    'categoria' => 'outros',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => false,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 H003 (Private)',
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  'RAPIDSW,A#' => [
    'cmd' => 'RAPIDSW',
    'nome' => 'Lógica de detecção de aceleração brusca',
    'desc' => 'Change the detect logic for RAPID',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 H004 (Private)',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => '0 is old logic A=1 is new logic, all to change the paramer of detect time & angel',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RAPIDSW,1',
        'desc' => 'exemplo oficial (H004)',
      ],
    ],
  ],
  'RAPIDTURN,A,B,C,D#' => [
    'cmd' => 'RAPIDTURN',
    'nome' => 'Curva Brusca',
    'desc' => 'Set the harsh cornering alert',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 H007 (Private)',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF B is detect time, default is 4 second C is speed threshold D is detect angel',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => '',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'RAPIDTURN,ON,3,60,80',
        'desc' => 'exemplo oficial (H007)',
      ],
    ],
  ],
  'ALARMTONE,A,B#' => [
    'cmd' => 'ALARMTONE',
    'nome' => 'Aviso sonoro por tipo de evento',
    'desc' => 'Whether to enable alert tone for a specific event type',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 H008 (Private)',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Event type SOS / CRASH / VIBRATE / OVERSPEED / RAPIDACC / RAPIDDEC / RAPIDTURN / DRIVE / POWER / VOLTAGELOW / CLOSEEYES / YAWN / DISTRACTION / SMOKING / PHONECALLING / RELAYOFF / RELAYRECOVERY / MISSINGFACE / NOSDCARD...',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'ON/OFF; On=Enable OFF=Disable',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ALARMTONE,CRASH,ON',
        'desc' => 'exemplo oficial (H008)',
      ],
    ],
  ],
  // 🔴 CAIXA É SIGNIFICATIVA AQUI. A planilha escreve `Picture,in` e `Video,in,3s`
  // e avisa em texto, nas duas linhas: "the 'P' need uppercase letter and others
  // need Lowercase letters". São os DOIS únicos comandos do proNo 128 assim —
  // todo o resto é maiúsculo. Por isso `cmd` guarda 'Picture'/'Video' com a
  // caixa exata, e nada no caminho de envio pode passar um strtoupper() por
  // cima: `command_response.php` sobe para maiúsculas só para CASAR o rótulo de
  // exibição, nunca para montar o que vai ao equipamento.
  //
  // ⚠️ Não confundir com `PICTURE#`/`PICTURE,1#`, logo acima: aqueles são da
  // wiki do JC371, se chamam "Parâmetros" e são outro comando — mesma base,
  // mesma aridade, significado diferente. Foi o que impediu esta linha de
  // entrar pelo cruzamento automático com a planilha.
  'Picture,A#' => [
    'cmd' => 'Picture',
    'nome' => 'Capturar foto',
    'desc' => 'Capture the images from the device.',
    'categoria' => 'video',
    'modelos' => [
      0 => 'JC400AD',
      1 => 'JC400D',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'fonte' => 'planilha JIMI V5.0.3 A012',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'camera type: in / out / inout — in=inward camera; out=front camera; inout=both camera (minúsculas)',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'Picture,in',
        'desc' => 'exemplo oficial (A012)',
      ],
    ],
  ],
];
