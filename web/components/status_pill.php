<?php
/**
 * Componente: Selo de Status/Risco (pill)
 * Uso: Ocorrências, Alarmes, Devices, Motoristas
 *
 * Parâmetros:
 *   $status  — Valor do status (ex: 'aguardando', 'resolvida', 'online', 'alto')
 *   $type    — Tipo do selo: 'status' (status DMS), 'risk' (risco), 'online' (conectividade), 'generic'
 *              Default: 'status'
 *
 * Mapeamentos:
 *   status DMS: aguardando→warning, em_tratativa→info, resolvida→success, descartada→muted
 *   risk:       baixo→info(blue), medio→warning, alto→error
 *   online:     online→success, offline→error
 */
$type = $type ?? 'status';
$label = '';
$class = 'badge';
$style = '';

if ($type === 'status') {
    $map = [
        'aguardando'    => ['Aguardando Tratativa', 'badge-warning'],
        'em_tratativa'  => ['Em Tratativa',         'badge-info'],
        'resolvida'     => ['Resolvida',             'badge-success'],
        'descartada'    => ['Descartada',            'badge'],
    ];
    $item = $map[$status] ?? [ucfirst($status), 'badge'];
} elseif ($type === 'risk') {
    $map = [
        'baixo' => ['Baixo', 'badge-primary'],
        'medio' => ['Médio', 'badge-warning'],
        'alto'  => ['Alto',  'badge-error'],
    ];
    $item = $map[$status] ?? [ucfirst($status), 'badge'];
} elseif ($type === 'online') {
    $map = [
        'online'  => ['Online',  'badge-success'],
        'offline' => ['Offline', 'badge-error'],
        'active'  => ['Ativo',   'badge-success'],
        'inactive'=> ['Inativo', 'badge'],
    ];
    $item = $map[$status] ?? [ucfirst($status), 'badge'];
} else {
    $item = [ucfirst(str_replace('_', ' ', $status)), 'badge'];
}

$label = $item[0];
$class .= ' ' . $item[1];
?>
<span class="<?= $class ?>"><?= htmlspecialchars($label) ?></span>
