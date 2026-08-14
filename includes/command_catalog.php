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
 * `modelos` = modelos cuja página documenta o comando. `universal` = presente
 * em >= 5 das 6 páginas; só esses NÃO travam a seleção de equipamentos, por
 * serem o núcleo comum do proNo 128.
 *
 * `template` = a sintaxe traz placeholders (P1/A) e a tela monta um campo por
 * parâmetro. Sem template, a sintaxe já é um comando pronto.
 *
 * Total: 119 comandos (14 universais).
 * Por categoria: alarme=48, audio=2, ia=26, manutencao=4, outros=11, posicao=13, rede=9, video=6.
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
    'params' => [
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
    'params' => [
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
    'params' => [
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
    'params' => [
    ],
    'exemplos' => [
      0 => [
        'cmd' => 'PARAM#',
        'desc' => 'Consulta parâmetros básicos do dispositivo.',
      ],
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
    'params' => [
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
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
  // v4.9.18 — a listagem de gravações no protocolo JIMI. Ao contrário do JT/T,
  // que responde a uma consulta com janela (`37381` → /pushresourcelist), aqui
  // a câmera SOBE sozinha um TXT com a lista inteira para a URL informada, sem
  // filtro de data. O receptor é /filelist/{imei} (handlers/filelist.php).
  //
  // ⚠️ A URL tem de ser HTTP simples e alcançável pela câmera na internet —
  // ela não faz TLS, e o redirect 80→443 do site tem exceção para este caminho.
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
    'params' => [
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
    'params' => [
    ],
    'exemplos' => [
    ],
  ],
];
