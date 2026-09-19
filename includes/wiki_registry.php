<?php
/**
 * Central de Ajuda — registro de seções (v4.22.0).
 *
 * É DADO, não conteúdo: cada linha diz o que a seção é, de qual tela depende e
 * o que ela exige de quem lê. O texto vive em includes/wiki/sections/<id>.php.
 *
 * Campos (spec §4.1):
 *  id          âncora — preserva todos os ids anteriores à v4.22.0.
 *  title       título exibido (texto puro; o badge é gerado).
 *  level       2 = <h2>, 3 = <h3> dentro de um grupo.
 *  group       chave de wiki_groups() ou null.
 *  sub         recuo no índice lateral (h2 avulso que o índice sempre recuou).
 *  screen      chave da matriz de permissões (grupos_permissao.php) ou null.
 *  handler     arquivo de handlers/ que decide o acesso; null se a seção não é
 *              uma tela. É o que os testes usam para conferir `admin_only`.
 *  admin_only  espelha a linha `require_admin();` do handler. NUNCA o badge
 *              antigo da wiki: "Grupos de Permissão" e as três telas de
 *              configuração carregavam "admin" sem o handler exigir.
 *  actions     ações que a tela exige de fato (require_permission/can); a matriz
 *              tem as mesmas 5 colunas para toda tela, então ela não serve.
 *  extras      recursos restritos DENTRO de tela liberada.
 *  summary     linha do stub bloqueado (obrigatória se há tela ou admin_only).
 *  hidden      sem item de índice nem corpo (tela fora do menu).
 *  badge       texto de badge fixo (ex.: "tela inicial").
 *  dynamic     sem parcial: o corpo é gerado (só "Meu acesso", Etapa 3).
 *
 * 🔴 Tela nova entra em TRÊS lugares: $screenByHandler (handlers/router.php),
 * $screens (handlers/grupos_permissao.php) e AQUI.
 * tests/helpers/wiki_registry.test.php trava.
 */

/** Grupos que ganham um <h2> próprio e reúnem seções de nível 3. */
function wiki_groups(): array {
    return [
        'videos'     => 'Vídeos',
        'relatorios' => 'Relatórios',
        'cadastros'  => 'Cadastros',
        'operacoes'  => 'Operações',
    ];
}

/**
 * Monta uma entrada com os padrões aplicados.
 *
 * @param string $id    Âncora.
 * @param string $title Título.
 * @param array  $o     Campos que diferem do padrão.
 */
function wiki_sec(string $id, string $title, array $o = []): array {
    return $o + [
        'id' => $id, 'title' => $title,
        'level' => 3, 'group' => null, 'sub' => false,
        'screen' => null, 'handler' => null, 'admin_only' => false,
        'actions' => [], 'extras' => [], 'summary' => '',
        'hidden' => false, 'badge' => null, 'dynamic' => false,
    ];
}

/** @returns array<int,array> Seções na ordem em que aparecem na página. */
function wiki_registry(): array {
    // Relatórios: mesma tela ('relatorios'), cada um com seu handler. `$actions`
    // é ['export'] por padrão; Paradas e Ociosidade não têm exportação no
    // handler, então declaram [] (a faixa "Seu acesso" não pode prometer o que
    // a tela não oferece).
    $rel = fn(string $id, string $title, string $handler, string $summary, array $actions = ['export'])
        => wiki_sec($id, $title, [
            'group' => 'relatorios', 'screen' => 'relatorios', 'handler' => $handler,
            'actions' => $actions, 'summary' => $summary,
        ]);

    return [
        wiki_sec('intro', 'Visão Geral do Sistema', ['level' => 2]),
        wiki_sec('primeiros-passos', 'Primeiros Passos', ['level' => 2]),
        wiki_sec('resumo', 'Resumo', [
            'level' => 2, 'sub' => true, 'badge' => 'tela inicial',
            'screen' => 'resumo', 'handler' => 'resumo.php',
            'summary' => 'Visão executiva da frota: indicadores, mapa de calor e dispositivos desatualizados.',
        ]),
        wiki_sec('painel', 'Painel', [
            'level' => 2, 'sub' => true, 'screen' => 'painel', 'handler' => 'painel.php',
            'summary' => 'Indicadores e gráficos em blocos (widgets) que você escolhe e reordena para o seu usuário.',
        ]),
        wiki_sec('rastreamento', 'Rastreamento', [
            'level' => 2, 'sub' => true, 'screen' => 'rastreamento', 'handler' => 'rastreamento.php',
            'summary' => 'Mapa ao vivo com a última posição de todos os veículos da frota.',
        ]),
        wiki_sec('bi', 'BI — Business Intelligence', [
            'level' => 2, 'sub' => true, 'screen' => 'bi', 'handler' => 'bi.php', 'actions' => [],
            'summary' => 'Análises sob demanda com filtros de cliente, ativos, motoristas, alarmes e período.',
        ]),
        wiki_sec('mapa-risco', 'Mapa de Risco', [
            'level' => 2, 'sub' => true, 'screen' => 'bi', 'handler' => 'mapa_risco.php', 'actions' => ['export'],
            'summary' => 'Onde, quando e com quem os comportamentos de risco (ADAS/DMS) acontecem.',
        ]),
        wiki_sec('ocorrencias-dashboard', 'Dashboard de Ocorrências', [
            'level' => 2, 'sub' => true, 'screen' => 'ocorrencias_dashboard', 'handler' => 'ocorrencias_dashboard.php',
            'summary' => 'Painel operacional das ocorrências de comportamento do motorista e sua tratativa.',
        ]),
        wiki_sec('notificacoes', 'Notificações', ['level' => 2]),

        // ── Vídeos ──
        wiki_sec('video-aovivo', 'Ao Vivo', [
            'group' => 'videos', 'screen' => 'video_aovivo', 'handler' => 'video_aovivo.php',
            'summary' => 'Assistir ao vivo às câmeras dos veículos.',
        ]),
        wiki_sec('video-playback', 'Playback', [
            'group' => 'videos', 'screen' => 'video_playback', 'handler' => 'video_playback.php',
            'summary' => 'Ver as gravações históricas do cartão de memória do equipamento.',
        ]),
        wiki_sec('video-downloads', 'Downloads', [
            'group' => 'videos', 'screen' => 'video_downloads', 'handler' => 'video_downloads.php',
            'summary' => 'Grade dos arquivos de mídia disponíveis para download.',
        ]),

        // ── Relatórios ──
        wiki_sec('rel-comum', 'O que vale para todos os relatórios', [
            'group' => 'relatorios', 'screen' => 'relatorios',
            'summary' => 'Comportamentos iguais em todas as telas de relatório.',
        ]),
        wiki_sec('rel-modelos', 'Modelos salvos', [
            'group' => 'relatorios', 'screen' => 'relatorios',
            'summary' => 'Guardar uma combinação de filtros e reaplicá-la em um clique.',
        ]),
        $rel('rel-posicoes', 'Posições', 'rel_posicoes.php', 'Histórico de posições de um ativo em um período, com trajeto no mapa.'),
        $rel('rel-deslocamento', 'Deslocamento', 'rel_deslocamento.php', 'Deslocamentos do veículo com duração, velocidade máxima e distância.'),
        $rel('rel-desatualizados', 'Desatualizados', 'rel_desatualizados.php', 'Equipamentos que estão há muito tempo sem se comunicar.'),
        $rel('rel-alarmes', 'Alertas Videomonitoramento', 'rel_alarmes.php', 'Histórico dos alarmes das câmeras (tudo que não é evento de condução).'),
        $rel('rel-dirigibilidade', 'Alertas Dirigibilidade', 'rel_dirigibilidade.php', 'Eventos de condução: frenagem, arrancada, curva, velocidade, colisão.'),
        wiki_sec('rel-ocorrencias', 'Ocorrências', [
            'group' => 'relatorios', 'screen' => 'relatorios', 'handler' => 'rel_ocorrencias.php',
            'actions' => ['export'], 'extras' => ['Descarte em massa: só administrador da plataforma'],
            'summary' => 'Histórico de ocorrências com filtros de tipo, situação, risco e motorista.',
        ]),
        $rel('rel-geocercas', 'Geocercas', 'rel_geocercas.php', 'Quando cada veículo entrou e saiu das cercas e quanto tempo ficou dentro.'),
        $rel('rel-status-frota', 'Status da Frota', 'rel_status_frota.php', 'Retrato de agora: veículos em movimento, ociosos, parados e sem comunicação.'),
        $rel('rel-paradas', 'Paradas', 'rel_paradas.php', 'Períodos com a ignição desligada, com início, fim, duração e local.', []),
        $rel('rel-ociosidade', 'Ociosidade', 'rel_ociosidade.php', 'Períodos com o motor ligado e o veículo imóvel.', []),
        $rel('rel-ignicao', 'Ignição', 'rel_ignicao.php', 'Cada vez que a ignição foi ligada ou desligada e por quanto tempo.'),
        $rel('rel-velocidade', 'Excesso de Velocidade', 'rel_velocidade.php', 'Trechos acima do limite configurado, com velocidade máxima e duração.'),
        wiki_sec('agendamentos', 'Agendamentos', [
            'group' => 'relatorios', 'screen' => 'agendamentos', 'handler' => 'agendamentos.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Receber um relatório por e-mail na frequência escolhida.',
        ]),

        // ── Cadastros ──
        wiki_sec('ativos', 'Ativos', [
            'group' => 'cadastros', 'screen' => 'ativos', 'handler' => 'ativos.php',
            'actions' => ['create', 'edit', 'delete', 'export'],
            'summary' => 'Cadastro dos veículos da frota e da câmera instalada em cada um.',
        ]),
        wiki_sec('chips', 'Chips SIM', [
            'group' => 'cadastros', 'screen' => 'chips', 'handler' => 'chips.php',
            'actions' => ['create', 'edit', 'delete', 'export'],
            'summary' => 'Chips SIM usados nos equipamentos: operadora, linha, ICCID e situação.',
        ]),
        wiki_sec('clientes', 'Clientes', [
            'group' => 'cadastros', 'screen' => 'clientes', 'handler' => 'clientes.php', 'admin_only' => true,
            'actions' => ['create', 'edit', 'delete', 'export'],
            'summary' => 'Cadastro dos clientes da plataforma; cada um tem a própria frota isolada.',
        ]),
        wiki_sec('equipamentos', 'Equipamentos', [
            'group' => 'cadastros', 'screen' => 'equipamentos', 'handler' => 'equipamentos.php',
            'actions' => ['create', 'edit', 'export'],
            'summary' => 'Cadastro dos equipamentos (câmeras e rastreadores) e do chip de cada um.',
        ]),
        wiki_sec('geocercas', 'Geocercas', [
            'group' => 'cadastros', 'screen' => 'geocercas', 'handler' => 'geocercas.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Desenhar áreas no mapa e ser avisado quando um veículo entra ou sai.',
        ]),
        wiki_sec('grupos-permissao', 'Grupos de Permissão', [
            'group' => 'cadastros', 'screen' => 'grupos-permissao', 'handler' => 'grupos_permissao.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Define quais telas e ações cada grupo de usuários pode usar.',
        ]),
        wiki_sec('motoristas', 'Motoristas', [
            'group' => 'cadastros', 'screen' => 'motoristas', 'handler' => 'motoristas.php',
            'actions' => ['create', 'edit', 'delete', 'export'],
            'summary' => 'Cadastro de motoristas com CNH e exame toxicológico.',
        ]),
        wiki_sec('manutencoes', 'Manutenção', [
            'group' => 'cadastros', 'screen' => 'manutencoes', 'handler' => 'manutencoes.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Lembretes de manutenção por odômetro, horas ou data, e aviso de vencimento de CNH e exame toxicológico.',
        ]),
        wiki_sec('config-ocorrencias', 'Configuração de Ocorrências', [
            'group' => 'cadastros', 'screen' => 'config-ocorrencias', 'handler' => 'config_ocorrencias.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Perfis de regras que transformam cada tipo de alarme em ocorrência.',
        ]),
        wiki_sec('config-notificacoes', 'Configuração de Notificações', [
            'group' => 'cadastros', 'screen' => 'config-notificacoes', 'handler' => 'config_notificacoes.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Define o que gera aviso, por qual canal e para quem.',
        ]),
        wiki_sec('config-smtp', 'Servidor de E-mail', [
            'group' => 'cadastros', 'screen' => 'config-smtp', 'handler' => 'config_smtp.php',
            'actions' => ['edit', 'delete'],
            'summary' => 'Credenciais do servidor que envia os e-mails do sistema.',
        ]),
        wiki_sec('usuarios', 'Usuários', [
            'group' => 'cadastros', 'screen' => 'usuarios', 'handler' => 'usuarios.php', 'admin_only' => true,
            'actions' => ['create', 'edit', 'export'],
            'summary' => 'Gestão dos usuários do sistema (da sua empresa e dos seus clientes).',
        ]),
        wiki_sec('auditoria', 'Auditoria', [
            'group' => 'cadastros', 'screen' => 'auditoria', 'handler' => 'auditoria.php',
            'actions' => ['export'],
            'summary' => 'Consulta de quem fez o quê no sistema: alterações de cadastro, acessos negados, logins e comandos enviados.',
        ]),

        // ── Operações ──
        wiki_sec('comandos', 'Comandos', [
            'group' => 'operacoes', 'screen' => 'comandos', 'handler' => 'comandos.php',
            'summary' => 'Enviar comandos remotos aos equipamentos e acompanhar a resposta.',
        ]),
        // Fora do menu desde a v4.13.10 ("a tela ainda não está funcional"): a
        // wiki não a promove enquanto o menu a esconde. O parcial fica guardado.
        wiki_sec('parametros', 'Parâmetros', [
            'group' => 'operacoes', 'screen' => 'parametros', 'handler' => 'parametros.php',
            'admin_only' => true, 'hidden' => true,
            'summary' => 'Ler, comparar e alterar a configuração remota das câmeras JT/T.',
        ]),
        wiki_sec('firmwares', 'Firmware', [
            'group' => 'operacoes', 'screen' => 'firmwares', 'handler' => 'firmwares.php', 'admin_only' => true,
            'summary' => 'Ver a versão de firmware de cada câmera e atualizá-la à distância.',
        ]),
        wiki_sec('exportar', 'Exportar', [
            'group' => 'operacoes', 'screen' => 'exportar', 'handler' => 'exportar.php',
            'summary' => 'Fila de geração de relatórios grandes, com download ao concluir.',
        ]),
        wiki_sec('checklist', 'Checklist e Inspeção', [
            'group' => 'operacoes', 'screen' => 'checklist', 'handler' => 'checklist.php',
            'actions' => ['create', 'edit', 'delete'],
            'summary' => 'Checklists de inspeção veicular e seu preenchimento por veículo.',
        ]),
    ];
}
