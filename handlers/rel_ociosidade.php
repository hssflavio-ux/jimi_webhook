<?php
/**
 * JIMI Webhook System — Relatório de Ociosidade v4.6.0
 * Rota: /relatorios/ociosidade
 *
 * Recorte de device_state_segments em `state = 'ocioso'` — motor ligado com o
 * veículo imóvel (velocidade <= STOP_SPEED_KMH, 3 km/h).
 *
 * É o relatório de combustível queimado sem deslocamento: caminhão em fila de
 * carga com o motor ligado, ônibus aguardando em ponto final, veículo com
 * ar-condicionado ligado na espera. O limiar de 3 km/h existe para que a
 * deriva de GPS (veículo imóvel reportando 1–2 km/h) não seja lida como
 * movimento — ver includes/fleet_state.php.
 *
 * Os dados vêm do scripts/state_builder.php (cron a cada 15 min).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/report_segments.php';

render_segment_report([
    'state'     => 'ocioso',
    'title'     => 'Relatório de Ociosidade',
    'route'     => 'rel_ociosidade',
    'path'      => '/relatorios/ociosidade',
    'slug'      => 'relatorio_ociosidade',
    'unit'      => 'períodos ociosos',
    'emptyText' => 'Nenhum período de ociosidade no período',
    'help'      => 'Ociosidade = ignição ligada com velocidade de até ' . STOP_SPEED_KMH . ' km/h.',
    'showDist'  => false,
]);
