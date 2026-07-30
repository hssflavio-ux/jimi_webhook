<?php
/**
 * JIMI Webhook System — Relatório de Paradas v4.6.0
 * Rota: /relatorios/paradas
 *
 * Recorte de device_state_segments em `state = 'parado'` — ignição desligada.
 *
 * Não confundir com Ociosidade (`/relatorios/ociosidade`): lá o motor está
 * ligado com o veículo imóvel, aqui o veículo está com a ignição desligada. As
 * duas telas somam tempos diferentes e respondem perguntas diferentes (a
 * primeira é consumo desperdiçado, a segunda é veículo fora de operação).
 *
 * Os dados vêm do scripts/state_builder.php (cron a cada 15 min).
 */

require_once __DIR__ . '/../includes/auth.php';
require_login();

require_once __DIR__ . '/../includes/report_segments.php';

render_segment_report([
    'state'     => 'parado',
    'title'     => 'Relatório de Paradas',
    'route'     => 'rel_paradas',
    'path'      => '/relatorios/paradas',
    'slug'      => 'relatorio_paradas',
    'unit'      => 'paradas',
    'emptyText' => 'Nenhuma parada no período',
    'help'      => 'Parada = ignição desligada (acc = 0).',
    'showDist'  => false,
]);
