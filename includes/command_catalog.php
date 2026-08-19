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
 * `modelos` = modelos cuja página documenta o comando. `universal` = presente
 * em >= 5 das 6 páginas; só esses NÃO travam a seleção de equipamentos, por
 * serem o núcleo comum do proNo 128.
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
 * Total: 219 entradas / 143 comandos distintos (14 universais), 67 com consulta.
 * Por categoria: alarme=70, audio=3, ia=36, manutencao=18, outros=22, posicao=22, rede=19, video=29.
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
  'ADAS,CALIBRATION,P1#' => [
    'cmd' => 'ADAS',
    'nome' => 'Calibração de ADAS',
    'desc' => 'Executa a calibração do ADAS conforme o tipo de veículo.',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'ADAS#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Tipo de veículo',
        'format' => 'A = Carro de passeio / B = SUV/Pick-up / C = Caminhão pequeno (cabine reta) / D = Caminhão médio (cabine reta) / E = Caminhão grande (cabine reta) / F = Caminhão médio (cabine longa) / G = Caminhão grande (cabine longa)',
        'default' => 'A',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'ADAS,CALIBRATION,A#',
        'desc' => 'calibra como carro de passeio.',
      ],
      1 => [
        'cmd' => 'ADAS,CALIBRATION#',
        'desc' => 'consulta parâmetros atuais.',
      ],
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
  'COLLIDE,P1,P2,P3,P4#' => [
    'cmd' => 'COLLIDE',
    'nome' => 'Colisão',
    'desc' => 'Colisão do JC181. Abaixo do limiar o evento é tratado como alarme falso e o vídeo fica local, sem envio. Padrão do limiar: 5 km/h.',
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
    ],
    'exemplos' => [
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
  'DMSSP,P1,P2,P3,P4#' => [
    'cmd' => 'DMSSP',
    'nome' => 'Configurar Parâmetros Básicos de IA',
    'desc' => 'Define parâmetros de ativação dos recursos de IA (ADAS e DMS).',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => NULL,
    'consulta_modelos' => [],
    'consulta_ref' => NULL,
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Função de IA',
        'format' => 'ADAS, DMS, AFCW, AHMW, ALDW, APCW, ACEA, ADDW, ADW, ASW, ACPW, ANDD, AMS, ASS, ADA',
        'default' => '–',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Velocidade mínima ou tempo/duração',
        'format' => '10–120 km/h (ADAS/DMS) / 500–10000 ms (colisão)',
        'default' => 'ADAS: 60 km/h / DMS: 30 km/h',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Canal da câmera (ADAS/DMS)',
        'format' => '1 = CH1 / 2 = CH2 / 3 = CH3',
        'default' => 'ADAS: 1 / DMS: 2',
      ],
      3 => [
        'p' => 'P4',
        'desc' => 'Área de detecção (ADAS/DMS)',
        'format' => '0 = Tela inteira / 1 = Esquerda ½ / 2 = Meio ½ / 3 = Direita ½ / 4 = Esquerda ⅔ / 5 = Meio ⅔ / 6 = Direita ⅔',
        'default' => 'ADAS: 0 / DMS: 3',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'DMSSP,ADAS,60,1,0#',
        'desc' => 'ADAS a partir de 60 km/h, câmera CH1, tela inteira.',
      ],
      1 => [
        'cmd' => 'DMSSP,DMS,60,2,3#',
        'desc' => 'DMS a partir de 60 km/h, câmera CH2, área direita ½.',
      ],
      2 => [
        'cmd' => 'DMSSP,DMS,60,2,0:0:500:1000#',
        'desc' => 'DMS ativo em 60 km/h, câmera CH2, área personalizada.',
      ],
      3 => [
        'cmd' => 'DMSSP,ADAS#',
        'desc' => 'consulta configuração atual do ADAS.',
      ],
    ],
  ],
  'DMSSW,P1,P2#' => [
    'cmd' => 'DMSSW',
    'nome' => 'Chave de Funções de IA',
    'desc' => 'Ativa ou desativa funções específicas de IA no dispositivo.Qualquer recurso de IA deve estar habilitado aqui antes de ser usado.',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'DMSSW#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'medido',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Função de IA',
        'format' => '1 = ADAS (Sistema Avançado de Assistência ao Motorista) / 2 = DMS (Sistema de Monitoramento do Motorista) / 3 = FACE (Reconhecimento Facial)',
        'default' => '1 e 2 ativos / 3 inativo',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Estado',
        'format' => '0 = Desativar / 1 = Ativar',
        'default' => 'conforme função',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'DMSSW,1,0#',
        'desc' => 'desativa ADAS.',
      ],
      1 => [
        'cmd' => 'DMSSW,3,1#',
        'desc' => 'ativa reconhecimento facial.',
      ],
      2 => [
        'cmd' => 'DMSSW#',
        'desc' => 'consulta funções habilitadas.',
      ],
    ],
  ],
  'DMSVSP,P1#' => [
    'cmd' => 'DMSVSP',
    'nome' => 'Velocidade Virtual para Simulação',
    'desc' => 'Define uma velocidade simulada para testar algoritmos de IA em ambiente de laboratório.O valor é aplicado apenas uma vez.',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC450',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'DMSVSP#',
    'consulta_modelos' => ['JC371'],
    'consulta_ref' => 'medido',
    'params' => [
      0 => [
        'p' => 'P1',
        'desc' => 'Velocidade simulada',
        'format' => '0–120 km/h',
        'default' => '–',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'DMSVSP,60#',
        'desc' => 'define 60 km/h como velocidade simulada.',
      ],
      1 => [
        'cmd' => 'DMSVSP#',
        'desc' => 'consulta valor atual.',
      ],
    ],
  ],
  'DMS_ALERT_CUSTOM,A,B,C,D,E,F#' => [
    'cmd' => 'DMS_ALERT_CUSTOM',
    'nome' => 'Tempo alerta plataforma',
    'desc' => 'Para alterar o tempo de envio de alertas repetidos em um período de tempo para a plataforma, envie',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'DMS_ALERT_CUSTOM#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Tempo para olhos fechados',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Tempo para bocejo',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Tempo para distração',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => 'Tempo para cigarro',
        'format' => '',
        'default' => '',
      ],
      4 => [
        'p' => 'E',
        'desc' => 'Tempo para uso de celular',
        'format' => '',
        'default' => '',
      ],
      5 => [
        'p' => 'F',
        'desc' => 'Tempo para face não detectada',
        'format' => '',
        'default' => '120 , 120 , 120 , 120 , 120 , 120 Exempl',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'DMS_CONTINUITY,A,B,C,D,E,F#' => [
    'cmd' => 'DMS_CONTINUITY',
    'nome' => 'Defina o tempo para o reconhecimento do evento',
    'desc' => '',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'DMS_CONTINUITY#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Olhos fechados',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Bocejando',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Distração',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => 'Fumando',
        'format' => '',
        'default' => '',
      ],
      4 => [
        'p' => 'E',
        'desc' => 'Uso de celular',
        'format' => '',
        'default' => '',
      ],
      5 => [
        'p' => 'F',
        'desc' => 'Nenhum rosto detectado',
        'format' => '',
        'default' => '3,3,3,3,3,3 Para consulta, envie: DMS_CO',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'DMS_SWITCH,A,B,C#' => [
    'cmd' => 'DMS_SWITCH',
    'nome' => 'Velocidade dos alertas',
    'desc' => 'Para alterar a velocidade de geração dos alertas da DMS, envie',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'DMS_SWITCH#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Ativação/Desativação da DMS(1 = ON, 0 = OFF) PADRÃO = 1',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Sensibilidade(1 = Padrão, 2 = Sensibilidade alta) PADRÃO = 1',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Velocidade de detecção dos eventos (0, 15, 30, 60 ou 90) PADRÃO = 15km/h',
        'format' => '',
        'default' => '',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'DMS_VIRTUAL_SPEED,A#' => [
    'cmd' => 'DMS_VIRTUAL_SPEED',
    'nome' => '4 - Modo teste em bancada',
    'desc' => 'Para simular uma velocidade virtual, envie',
    'categoria' => 'ia',
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
    ],
    'exemplos' => [
    ],
  ],
  'DMS_VOICE_CUSTOM,A,B,C,D,E,F#' => [
    'cmd' => 'DMS_VOICE_CUSTOM',
    'nome' => 'Tempo alerta sonoro',
    'desc' => 'Para alterar o tempo que o mesmo alerta sonoro não seja enviado dentro de um período de tempo, envie',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC400D',
      1 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'DMS_VOICE_CUSTOM#',
    'consulta_modelos' => ['JC400AD', 'JC400D'],
    'consulta_ref' => 'wiki',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'Tempo para olhos fechados',
        'format' => '',
        'default' => '',
      ],
      1 => [
        'p' => 'B',
        'desc' => 'Tempo para bocejo',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Tempo para distração',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => 'Tempo para cigarro',
        'format' => '',
        'default' => '',
      ],
      4 => [
        'p' => 'E',
        'desc' => 'Tempo para uso de celular',
        'format' => '',
        'default' => '',
      ],
      5 => [
        'p' => 'F',
        'desc' => 'Tempo para face não detectada',
        'format' => '',
        'default' => '5 , 5 , 60 , 5 , 5 , 180 Exemplo: DMS_VO',
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
  'EVENTALERT,ACEA,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Envia eventos de fadiga (olhos fechados) para a plataforma e gera avisos de voz.',
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
        'desc' => 'Alerta na plataforma',
        'format' => '0',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF, 1–64800 s',
        'default' => '120',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF, 1–64800 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ACEA,0,30,60#',
        'desc' => 'envia evento a cada 30 s e voz a cada 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ACEA#',
        'desc' => 'consulta configuração atual.',
      ],
    ],
  ],
  'EVENTALERT,ACPW,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Configura envio do evento de uso de celular para a plataforma e alerta de voz.',
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
        'format' => '0 = OFF / 1–64800 s',
        'default' => '120',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0 = OFF / 1–64800 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ACPW,0,120,5#',
        'desc' => 'Envia evento a cada 120 s e voz a cada 5 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ACPW,0,30,60#',
        'desc' => 'Envia evento a cada 30 s e voz a cada 60 s.',
      ],
      2 => [
        'cmd' => 'EVENTALERT,ACPW#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTALERT,ADA,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Configura envio do evento de beber/comer para a plataforma e aviso de voz.',
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
        'format' => '0 = OFF / 1–64800 s',
        'default' => 'OFF',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0 = OFF / 1–64800 s',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ADA,0,30,60#',
        'desc' => 'Envia evento a cada 30 s e voz a cada 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ADA#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTALERT,ADCA,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Define o envio do evento de anomalia de calibração do DMS para a plataforma e o intervalo dos avisos de voz.',
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
        'desc' => 'Alerta na plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio do evento',
        'format' => '0/OFF = não enviar / 1–64800 s',
        'default' => 'OFF',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF = sem voz / 1–64800 s',
        'default' => '1 (voz imediata)',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ADCA,0,30,60#',
        'desc' => 'envia evento a cada 30 s e voz a cada 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ADCA,0,1,OFF#',
        'desc' => 'envio imediato e sem voz.',
      ],
      2 => [
        'cmd' => 'EVENTALERT,ADCA#',
        'desc' => 'consulta configuração atual.',
      ],
    ],
  ],
  'EVENTALERT,ADDW,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Configura envio de eventos de bocejo e alerta de voz.',
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
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF, 1–64800 s',
        'default' => '120',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF, 1–64800 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ADDW,0,30,60#',
        'desc' => 'envia evento a cada 30 s e voz a cada 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ADDW#',
        'desc' => 'consulta configuração atual.',
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
  'EVENTALERT,AFCW,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Enviar para a plataforma e voz',
    'desc' => '.',
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
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF; 1 = imediato; 2–64800 s',
        'default' => '120',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo entre avisos de voz',
        'format' => '0/OFF; 1 = imediato; 2–64800 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AFCW,0,30,60#',
        'desc' => 'Exemplo: EVENTALERT,AFCW,0,30,60#.',
      ],
    ],
  ],
  'EVENTALERT,AFIF,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz (falha)',
    'desc' => 'Define envio e voz para eventos de falha de reconhecimento facial.',
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
        'desc' => 'Alerta na plataforma',
        'format' => '0',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0 = OFF / 1–64800 s',
        'default' => '60',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0 = OFF / 1–64800 s',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AFIF,0,30,60#',
        'desc' => 'Envia evento a cada 30 s e voz a cada 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,AFIF#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTALERT,AFIS,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz (sucesso)',
    'desc' => 'Define envio e voz para eventos de reconhecimento facial bem-sucedido.',
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
        'desc' => 'Alerta na plataforma',
        'format' => '0',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0 = OFF / 1–64800 s',
        'default' => '60',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0 = OFF / 1–64800 s',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AFIS,0,30,60#',
        'desc' => 'Envia evento a cada 30 s e voz a cada 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,AFIS#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTALERT,AFVS,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Configura o envio do evento para a plataforma e o alerta de voz ao motorista.',
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
        'desc' => 'Intervalo de envio do evento',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo entre alertas de voz',
        'format' => '0/OFF = sem voz / 1 = imediato / 2–64800 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AFVS,0,120,5#',
        'desc' => 'envia evento a cada 120 s e alerta de voz a cada 5 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,AFVS,0,60,1#',
        'desc' => 'envia evento a cada 60 s e voz imediata.',
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
  'EVENTALERT,AHMW,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Enviar para a plataforma e voz',
    'desc' => '.',
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
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF; 1 = imediato; 2–64800 s',
        'default' => '120',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo entre avisos de voz',
        'format' => '0/OFF; 1 = imediato; 2–64800 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AHMW,0,30,60#',
        'desc' => 'Exemplo: EVENTALERT,AHMW,0,30,60#.',
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
  'EVENTALERT,ALDW,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Enviar para a plataforma e voz',
    'desc' => 'Define envio e alerta de voz.',
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
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF = não enviar; 1 = imediato; 2–64800 s',
        'default' => '120',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo entre avisos de voz',
        'format' => '0/OFF = sem voz; 1 = imediato; 2–64800 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ALDW,0,30,60#',
        'desc' => 'Exemplo: EVENTALERT,ALDW,0,30,60# → envia a cada 30 s e voz a cada 60 s.',
      ],
    ],
  ],
  'EVENTALERT,AMS,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Configura envio do evento de obstrução da lente e alerta de voz.',
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
        'format' => '0 = OFF / 1–64800 s',
        'default' => '14400',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0 = OFF / 1–64800 s',
        'default' => '14400',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AMS,0,30,60#',
        'desc' => 'Envia evento a cada 30 s e voz a cada 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,AMS#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTALERT,ANDD,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Configura envio do evento de ausência de rosto para a plataforma e alerta de voz.',
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
        'desc' => 'Alerta na plataforma',
        'format' => '0 (fixo)',
        'default' => '0',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Intervalo de envio',
        'format' => '0 = OFF / 1–64800 s',
        'default' => 'OFF',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0 = OFF / 1–64800 s',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ANDD,0,30,60#',
        'desc' => 'Envia evento a cada 30 s e voz a cada 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ANDD#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTALERT,ANWSB,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz – Cinto Solto',
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
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ANWSB,0,30,60#',
        'desc' => 'envia a cada 30 s e voz a cada 60 s.',
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
  'EVENTALERT,APCW,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Enviar para a plataforma e voz',
    'desc' => '.',
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
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF; 1 = imediato; 2–64800 s',
        'default' => '120',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo entre avisos de voz',
        'format' => '0/OFF; 1 = imediato; 2–64800 s',
        'default' => '10',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,APCW,0,30,60#',
        'desc' => 'Exemplo: EVENTALERT,APCW,0,30,60#.',
      ],
    ],
  ],
  'EVENTALERT,ASCE,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Fechamento Sustentado dos Olhos',
    'desc' => 'Configura aviso de voz específico para fechamento sustentado dos olhos.',
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
        'desc' => 'Intervalo de envio',
        'format' => '0/OFF',
        'default' => 'OFF',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0/OFF, 1–64800 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ASCE,0,0,60#',
        'desc' => 'apenas voz a cada 60 s (sem envio à plataforma).',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ASCE#',
        'desc' => 'consulta configuração atual.',
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
  'EVENTALERT,ASS,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz',
    'desc' => 'Configura envio do evento de óculos de sol e alerta de voz.',
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
        'format' => '0 = OFF / 1–64800 s',
        'default' => 'OFF',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo de voz',
        'format' => '0 = OFF / 1–64800 s',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,ASS,0,30,60#',
        'desc' => 'Envia evento a cada 30 s e voz a cada 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTALERT,ASS#',
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
  'EVENTALERT,AWSB,P1,P2,P3#' => [
    'cmd' => 'EVENTALERT',
    'nome' => 'Envio e voz – Cinto Afivelado',
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
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTALERT,AWSB,0,30,60#',
        'desc' => 'envia a cada 30 s e voz a cada 60 s.',
      ],
    ],
  ],
  'EVENTSET,ACD,80#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Colisão',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
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
  'EVENTSET,ACEA,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa a detecção de fadiga por fechamento de olhos. O evento é disparado quando os olhos permanecem fechados por mais de P2 segundos.',
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
        'desc' => 'Sensibilidade',
        'format' => 'OFF / 1 (Alta) / 2 (Média) / 3 (Baixa)',
        'default' => '2',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração mínima dos olhos fechados',
        'format' => '1–255 s',
        'default' => '2',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,ACEA,2,2#',
        'desc' => 'evento se olhos ficarem fechados ≥2 s (sensibilidade média).',
      ],
      1 => [
        'cmd' => 'EVENTSET,ACEA#',
        'desc' => 'consulta configuração atual.',
      ],
    ],
  ],
  'EVENTSET,ACPW,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa/desativa a detecção de uso de celular e define as condições de disparo. O evento é gerado se o uso de celular for detectado por pelo menos P2 segundos, no nível de sensibilidade configurado em P1.',
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
        'format' => 'OFF (desativado) / 1 (Alta sensibilidade) / 2 (Média) / 3 (Baixa) / Nota: quanto maior o valor, menor a exigência de similaridade para detectar celular.',
        'default' => '2',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração mínima de uso do celular para disparar',
        'format' => '1–255 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'EVENTSET,ADA,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa a detecção de ingestão de líquidos/comida pelo motorista.',
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
        'format' => 'OFF / 1 (Alta) / 2 (Média) / 3 (Baixa)',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração mínima da ação',
        'format' => '1–255 s',
        'default' => '5',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,ADA,2,5#',
        'desc' => 'Sensibilidade média, duração 5 s.',
      ],
      1 => [
        'cmd' => 'EVENTSET,ADA#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTSET,ADCA,P1#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa ou desativa a detecção de anomalias na calibração do DMS. Um evento é gerado se a calibração não for concluída dentro do tempo configurado em P1.',
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
        'desc' => 'Tempo limite para calibração',
        'format' => 'OFF (desativado) / 0–64800 s',
        'default' => '60',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,FACE,P1,P2,P3#',
        'desc' => 'Antes de usar, é necessário importar dados de rosto com EVENTSET,FACE,P1,P2,P3#.',
      ],
      1 => [
        'cmd' => 'EVENTSET,ADCA,60#',
        'desc' => 'gera evento se a calibração não terminar em 60 s.',
      ],
      2 => [
        'cmd' => 'EVENTSET,ADCA,OFF#',
        'desc' => 'desativa a detecção de anomalia de calibração.',
      ],
      3 => [
        'cmd' => 'EVENTSET,ADCA#',
        'desc' => 'consulta a configuração atual.',
      ],
    ],
  ],
  'EVENTSET,ADDW,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa a detecção de bocejos. O evento é disparado se o motorista bocejar por mais de P2 segundos.',
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
        'format' => 'OFF / 1 (Alta) / 2 (Média) / 3 (Baixa)',
        'default' => '2',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração mínima do bocejo',
        'format' => '1–255 s',
        'default' => '2',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,ADDW,2,2#',
        'desc' => 'evento se bocejo durar ≥2 s (sensibilidade média).',
      ],
      1 => [
        'cmd' => 'EVENTSET,ADDW#',
        'desc' => 'consulta configuração atual.',
      ],
    ],
  ],
  'EVENTSET,ADW,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Habilita/Desabilita a detecção de distração (olhar para baixo/ao redor) e define as condições de disparo. O evento é gerado se a distração durar pelo menos P2 segundos no nível de sensibilidade P1.',
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
        'format' => 'OFF (desliga) / 1 (Alta) / 2 (Média) / 3 (Baixa). Nota: quanto menor o valor, mais sensível e mais fácil o disparo.',
        'default' => '2',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração mínima de distração para disparar o evento',
        'format' => '1–255 s',
        'default' => '3',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'DMSSP,P1,P2,P3,P4#',
        'desc' => 'Antes de usar, configure os parâmetros básicos de IA com DMSSP,P1,P2,P3,P4# (P1=DMS: velocidade de ativação, fonte de vídeo e área de detecção).',
      ],
      1 => [
        'cmd' => 'EVENTSET,ADW,2,3#',
        'desc' => 'Evento dispara se a distração durar ≥3 s em sensibilidade média.',
      ],
      2 => [
        'cmd' => 'EVENTSET,ADW#',
        'desc' => 'Consulta as configurações atuais.',
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
  'EVENTSET,AFCW,P1#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Ativar detecção',
    'desc' => 'Dispara quando uma colisão é prevista dentro do tempo P1.',
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
        'desc' => 'Sensibilidade (tempo de risco)',
        'format' => 'OFF ou 500–10000 (ms)',
        'default' => '2500',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AFCW,2500#',
        'desc' => 'Exemplo: EVENTSET,AFCW,2500#. Pré‑requisito: configurar DMSSP para ADAS.',
      ],
    ],
  ],
  'EVENTSET,AFIF,P1,P2,P3#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa a detecção de reconhecimento facial comparando rostos com a biblioteca.',
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
        'desc' => 'Sensibilidade (similaridade)',
        'format' => '1–100 (recomendado 40–60)',
        'default' => '40',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração para reconhecimento sem sucesso',
        'format' => '1–255 s',
        'default' => '180',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Intervalo entre tentativas',
        'format' => '0 = só uma vez ao ligar / 1–255 s',
        'default' => '0',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AFIF,50,30,10#',
        'desc' => 'Similaridade 50%, duração 30 s, tentativa a cada 10 s.',
      ],
      1 => [
        'cmd' => 'EVENTSET,AFIF#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTSET,AFVS,P1,P2,P3,P4#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa ou desativa a detecção do movimento do veículo à frente quando seu carro permanece parado no trânsito.',
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
        'desc' => 'Chave de função',
        'format' => 'ON / OFF',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Limite de movimento do veículo da frente (cm)',
        'format' => '100–500 cm',
        'default' => '60',
      ],
      2 => [
        'p' => 'P3',
        'desc' => 'Distância inicial de seguimento (cm)',
        'format' => '500–10000 cm',
        'default' => '600',
      ],
      3 => [
        'p' => 'P4',
        'desc' => 'Tempo parado antes de armar o sistema (s)',
        'format' => '10–300 s',
        'default' => '60',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AFVS,ON,60,600,60#',
        'desc' => 'ativa a função; evento será gerado se o carro da frente mover ≥60 cm, estando a ≤600 cm, após 60 s parado.',
      ],
      1 => [
        'cmd' => 'EVENTSET,AFVS,OFF,100,1000,60#',
        'desc' => 'desativa a função AFVS.',
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
  'EVENTSET,AHMW,P1#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Ativar detecção',
    'desc' => 'Dispara quando a distância para o veículo à frente é pequena e o tempo até colisão ≤ P1.',
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
        'desc' => 'Sensibilidade (limiar de tempo de risco)',
        'format' => 'OFF ou 500–10000 (ms)',
        'default' => '1200',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AHMW,1200#',
        'desc' => 'Exemplo: EVENTSET,AHMW,1200# → evento se o risco for ≤ 1,2 s.',
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
  'EVENTSET,ALDW,P1#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Ativar detecção',
    'desc' => 'Define a sensibilidade; o evento dispara quando a roda cruza a linha da faixa na distância P1.',
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
        'desc' => 'Sensibilidade (distância do cruzamento da roda)',
        'format' => 'OFF ou 10–100 (cm)',
        'default' => 'OFF',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,ALDW,60#',
        'desc' => 'Exemplo: EVENTSET,ALDW,60# → Gera evento ao cruzar ~60 cm da linha.',
      ],
    ],
  ],
  'EVENTSET,AMS,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa a detecção de obstrução da lente. Um evento é gerado se a lente permanecer obstruída por pelo menos P2 segundos no nível de sensibilidade definido em P1.',
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
        'format' => 'OFF (Desliga) / 1 (Alta) / 2 (Média) / 3 (Baixa) / Nota: quanto menor o valor, mais sensível.',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração mínima de obstrução da lente',
        'format' => '1–255 s',
        'default' => '60',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AMS,2,30#',
        'desc' => 'Sensibilidade média, duração 30 s.',
      ],
      1 => [
        'cmd' => 'EVENTSET,AMS#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTSET,ANDD,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa a detecção de ausência de rosto no campo de visão por tempo definido.',
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
        'desc' => 'Sensibilidade',
        'format' => 'OFF / 1 (Alta) / 2 (Média) / 3 (Baixa)',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração mínima sem rosto',
        'format' => '1–3600 s',
        'default' => '60',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,ANDD,2,60#',
        'desc' => 'Sensibilidade média, sem rosto por 60 s.',
      ],
      1 => [
        'cmd' => 'EVENTSET,ANDD#',
        'desc' => 'Consulta configuração atual.',
      ],
    ],
  ],
  'EVENTSET,ANWSB,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção – Cinto Solto',
    'desc' => 'Evento quando o cinto não está colocado.',
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
        'desc' => 'Nível de similaridade',
        'format' => 'OFF / 1 (Alta) / 2 (Média) / 3 (Baixa)',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Tempo mínimo sem cinto',
        'format' => '1–255 s',
        'default' => '60',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,ANWSB,2,30#',
        'desc' => 'sensibilidade média, 30 s.',
      ],
      1 => [
        'cmd' => 'EVENTSET,ANWSB#',
        'desc' => 'consulta atual.',
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
  'EVENTSET,AOSD,30,0#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Excesso de velocidade',
    'desc' => '',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC371',
      1 => 'JC182',
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
  'EVENTSET,APCW,P1#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Ativar detecção',
    'desc' => 'Dispara quando uma colisão com pedestre é prevista dentro do tempo P1.',
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
        'desc' => 'Sensibilidade (tempo de risco)',
        'format' => 'OFF ou 500–10000 (ms)',
        'default' => '5000',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,APCW,5000#',
        'desc' => 'Exemplo: EVENTSET,APCW,5000#. Pré‑requisito: configurar DMSSP para ADAS.',
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
  'EVENTSET,ASS,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção',
    'desc' => 'Ativa a detecção de uso de óculos de sol. O evento é disparado se detectado por pelo menos P2 segundos no nível de sensibilidade P1.',
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
        'format' => 'OFF (Desliga) / 1 (Alta) / 2 (Média) / 3 (Baixa)',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Duração mínima usando óculos',
        'format' => '1–255 s',
        'default' => '60',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,ASS,2,30#',
        'desc' => 'Sensibilidade média, duração 30 s.',
      ],
      1 => [
        'cmd' => 'EVENTSET,ASS#',
        'desc' => 'Consulta configuração atual.',
      ],
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
  'EVENTSET,AWSB,P1,P2#' => [
    'cmd' => 'EVENTSET',
    'nome' => 'Detecção – Cinto Afivelado',
    'desc' => 'Evento quando o cinto está colocado.',
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
        'desc' => 'Nível de similaridade',
        'format' => 'OFF / 1 (Alta) / 2 (Média) / 3 (Baixa)',
        'default' => 'OFF',
      ],
      1 => [
        'p' => 'P2',
        'desc' => 'Tempo mínimo usando cinto',
        'format' => '1–255 s',
        'default' => '1',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'EVENTSET,AWSB,2,5#',
        'desc' => 'ativa com sensibilidade média e 5 s.',
      ],
      1 => [
        'cmd' => 'EVENTSET,AWSB#',
        'desc' => 'consulta atual.',
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
  'FATIGUE,A,T1,T2#' => [
    'cmd' => 'FATIGUE',
    'nome' => 'Fadiga(cansado)',
    'desc' => 'Para configurar o envio do evento de direção fadigado(cansado), envie',
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
        'p' => 'A',
        'desc' => 'ON/OFF',
        'format' => '',
        'default' => 'OFF Para consultar o valor atual, envie:',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'FILELIST,A#' => [
    'cmd' => 'FILELIST',
    'nome' => 'Lista de gravações do cartão (JIMI)',
    'desc' => 'Manda a câmera subir, para a URL informada, um TXT com os nomes das gravações do cartão. Não aceita intervalo de datas: envia a lista inteira.',
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
  'SENALM,A,B#' => [
    'cmd' => 'SENALM',
    'nome' => 'Vibração',
    'desc' => 'Para configurar o envio de eventos de vibração, quando o veículo está parado, envie',
    'categoria' => 'alarme',
    'modelos' => [
      0 => 'JC181',
      1 => 'JC400D',
      2 => 'JC400AD',
    ],
    'universal' => false,
    'template' => true,
    'consulta' => 'SENALM#',
    'consulta_modelos' => ['JC181', 'JC182', 'JC400AD', 'JC400D'],
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
        'desc' => 'Sensibilidade (1,2 ou 3)',
        'format' => '',
        'default' => '2 Padrão: OFF Exemplo de uso:SENALM#6666',
      ],
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'SENALM,ON,1#',
        'desc' => 'Vibração',
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
        'desc' => 'Tempo acima da velocidade(5~600s)',
        'format' => '',
        'default' => '',
      ],
      2 => [
        'p' => 'C',
        'desc' => 'Velocidade(1~255km/h)',
        'format' => '',
        'default' => '',
      ],
      3 => [
        'p' => 'D',
        'desc' => 'Forma de envio:0: GPRS1: SMS+GPRS',
        'format' => '',
        'default' => 'OFF Para consultar o valor atual, envie:',
      ],
    ],
    'exemplos' => [
    ],
  ],
  'SPEEDCHECK,P1,P2,P3,P4,P5#' => [
    'cmd' => 'SPEEDCHECK',
    'nome' => 'Frenagem brusca (detecção)',
    'desc' => 'Queda de velocidade em km/h dentro de N segundos para caracterizar frenagem brusca. Padrão OFF,0,4,30,50.',
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
  'SWERVE,P1,P2,P3,P4,P5#' => [
    'cmd' => 'SWERVE',
    'nome' => 'Curva brusca (detecção)',
    'desc' => 'Tempo de detecção de 1 a 30 s para caracterizar curva brusca. Padrão OFF,0,30,60,3.',
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
    'desc' => '🔴 Inicia atualização de firmware a partir de uma URL.',
    'categoria' => 'manutencao',
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
    'template' => true,
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
    'nome' => 'Configurar upload de mídia',
    'desc' => '',
    'categoria' => 'ia',
    'modelos' => [
      0 => 'JC182',
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
    'desc' => 'Let the device to upload the playback video namelist file to the server.',
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
  'RTMP,A,B,C#' => [
    'cmd' => 'RTMP',
    'nome' => 'Transmissão ao vivo (RTMP)',
    'desc' => 'Request live streaming',
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
    'fonte' => 'planilha JIMI V5.0.3 A014',
    'params' => [
      0 => [
        'p' => 'A',
        'desc' => 'ON/OFF. ON means to enable RTMP streaming; OFF means to stop RTMP streaming. B: IN/OUT/INOUT. IN is inward camera, OUT is front camera, INOUT is both C is the pushing duration, unit is minutes, range is 2 ~ 180, Defau...',
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
        'cmd' => 'RTMP,ON,OUT,30',
        'desc' => 'exemplo oficial (A014)',
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
