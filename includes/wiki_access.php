<?php
/**
 * Central de Ajuda — resolvedor de acesso (v4.22.0).
 *
 * Responde "esta pessoa consegue abrir esta tela?" do mesmo jeito que o
 * HANDLER responde (require_admin() + can(view)), não como o menu mostra: o
 * menu lista Clientes e Usuários em Cadastros para quem tem `can()`, e o
 * handler devolve 403 para quem não é admin.
 *
 * Função pura: recebe o papel e um callable `can`, sem tocar em sessão nem
 * banco — é o que permite testar sem MySQL.
 */

const WIKI_MOTIVO_ADMIN = 'Restrita a administradores.';
const WIKI_MOTIVO_GRUPO = 'Seu grupo de permissão não libera esta tela. Peça ao administrador da sua conta.';

/**
 * @param array    $sec  Seção do registro (ver wiki_sec()).
 * @param string   $role Papel do usuário (`users.role`); vazio = não é admin.
 * @param callable $can  function (string $tela, string $acao): bool — normalmente `can`.
 * @returns array{state:string,reason:?string,allowed:string[],denied:string[]}
 */
function wiki_access(array $sec, string $role, callable $can): array {
    $bloqueada = fn(string $motivo) => [
        'state' => 'bloqueada', 'reason' => $motivo, 'allowed' => [], 'denied' => [],
    ];

    if (!empty($sec['admin_only']) && $role !== 'admin') {
        return $bloqueada(WIKI_MOTIVO_ADMIN);
    }
    $screen = $sec['screen'] ?? null;
    if ($screen !== null && !$can($screen, 'view')) {
        return $bloqueada(WIKI_MOTIVO_GRUPO);
    }

    $allowed = [];
    $denied  = [];
    foreach (($sec['actions'] ?? []) as $acao) {
        if ($screen === null || $can($screen, $acao)) $allowed[] = $acao;
        else $denied[] = $acao;
    }
    return ['state' => 'liberada', 'reason' => null, 'allowed' => $allowed, 'denied' => $denied];
}

/**
 * Resolve o acesso de todas as seções de uma vez. Função pura: não faz consulta
 * nem lê sessão; só chama o `$can` injetado (em produção, o `can()`, que lê as
 * permissões já cacheadas por request).
 *
 * @returns array<string,array> id da seção => resultado de wiki_access()
 */
function wiki_compute_access(array $registry, string $role, callable $can): array {
    $out = [];
    foreach ($registry as $sec) {
        $out[$sec['id']] = wiki_access($sec, $role, $can);
    }
    return $out;
}

/**
 * Perfil para o card de abertura. `role` vem primeiro: um admin com
 * user_type='revendedor' é admin (reseller_scope_ids() o trata como sem
 * restrição).
 *
 * @param array $user Linha de get_jimi_user() (pode ser vazia).
 * @return string admin|revendedor|cliente
 */
function wiki_profile(array $user): string {
    if (($user['role'] ?? '') === 'admin') return 'admin';
    if (($user['user_type'] ?? '') === 'revendedor') return 'revendedor';
    return 'cliente';
}

/**
 * Texto e atalhos do card. As listas têm mais de 5 ids de propósito: o card
 * mostra os 5 primeiros que o usuário PODE abrir, então um bloqueado é
 * substituído pelo próximo. Ids de seção do registro (rótulo = título dela).
 *
 * @return array{label:string,daily:string,shortcuts:string[]}
 */
function wiki_profile_info(string $perfil): array {
    $info = [
        'admin' => [
            'label'     => 'Administrador',
            'daily'     => 'Você cadastra clientes, usuários e equipamentos, define permissões e ajusta as configurações da plataforma.',
            'shortcuts' => ['clientes', 'usuarios', 'grupos-permissao', 'equipamentos', 'comandos', 'firmwares', 'auditoria'],
        ],
        'revendedor' => [
            'label'     => 'Revendedor',
            'daily'     => 'Você acompanha vários clientes, entra na operação de cada um e compara o desempenho entre eles.',
            'shortcuts' => ['resumo', 'rastreamento', 'ocorrencias-dashboard', 'ativos', 'rel-comum', 'rel-status-frota', 'video-aovivo'],
        ],
        'cliente' => [
            'label'     => 'Cliente',
            'daily'     => 'Você acompanha a sua frota, trata as ocorrências dos motoristas e tira relatórios.',
            'shortcuts' => ['rastreamento', 'ocorrencias-dashboard', 'video-aovivo', 'rel-alarmes', 'ativos', 'rel-posicoes', 'motoristas'],
        ],
    ];
    return $info[$perfil] ?? $info['cliente'];
}

