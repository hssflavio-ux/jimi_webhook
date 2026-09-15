<?php
/**
 * JIMI Webhook System — Alertas Dirigibilidade v4.21.0
 * Rota: /relatorios/dirigibilidade
 *
 * Eventos de condução (arrancada, freada, curva, excesso de velocidade,
 * colisão, capotamento, impacto/inclinação — `alarm_types.is_driving = 1`),
 * de câmera E de rastreador, sem nenhuma função de vídeo.
 *
 * ⚠️ É a MESMA grade de Alertas Videomonitoramento, num modo: copiar as 650
 * linhas de rel_alarmes.php criaria duas telas que divergem na primeira
 * correção. Aqui só se escolhe o modo.
 */

$ALARM_REPORT_MODE = 'driving';
require __DIR__ . '/rel_alarmes.php';
