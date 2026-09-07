<?php
/**
 * JIMI Webhook System — Vídeo Downloads v4.0.0
 * Rota: /video/downloads
 *
 * Fila de extração device→servidor. Mostra arquivos com status de download:
 * solicitado → disponivel (pushfileupload fecha) → download funcionando.
 *
 * Grade: Nome, Identificador, Equipamento, Modelo, Canal, Requisitado em, Status.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/media.php';     // media_canal_do_nome()
require_once __DIR__ . '/../includes/filelist.php';  // filelist_ts_do_nome_utc()
require_login();

$db = Database::getInstance()->getConnection();
$customerId = get_customer_id();

// Mídia servida por /midia (nossa origem, com login e escopo de cliente) em
// vez do FILE_STORAGE_URL público da porta 23010 — ver handlers/midia.php.
$fileStorageUrl = '/midia?f=';

// ── Escopo multi-tenant (v4.9.23) ──────────────────────────────────────────
//
// 🔴 O filtro anterior era `if ($customerId)`: com a sessão SEM cliente no
// contexto — que é o estado do admin de plataforma antes de escolher um — a
// cláusula simplesmente não entrava e a fila aparecia com os arquivos de
// TODOS os clientes, sem que a tela dissesse isso em lugar nenhum. Agora o
// escopo é explícito: `report_customer_scope()` decide, e quando o resultado é
// "todos" a tela declara de quem é cada arquivo.
$user        = get_jimi_user();
$isAdmin     = ($user['role'] ?? '') === 'admin' || ($user['user_type'] ?? '') === 'revendedor';
$filtroCust  = $_GET['customer_id'] ?? null;
$scopeCust   = report_customer_scope($filtroCust, $isAdmin, $customerId);
$customers   = $isAdmin ? report_customer_options($db) : [];
$mostrarCliente = ($scopeCust === null);

// Equipamentos oferecidos no filtro, já no escopo resolvido.
$devStmt = $db->prepare("
    SELECT d.imei, COALESCE(NULLIF(d.device_name,''), d.imei) AS device_name
    FROM devices d
    WHERE d.is_active = 1 " . ($scopeCust !== null ? ' AND d.customer_id = :cid' : '') . "
    ORDER BY d.device_name
");
$devStmt->execute($scopeCust !== null ? [':cid' => $scopeCust] : []);
$devicesFiltro = $devStmt->fetchAll(PDO::FETCH_ASSOC);

$selStatus = $_GET['status'] ?? '';

/**
 * Um equipamento escolhido faz placa, IMEI, modelo e cliente pararem de variar.
 *
 * ⚠️ Repetir em toda linha o que o usuário ACABOU de escolher no filtro não é
 * informação: é ruído que empurra as colunas úteis — status e download — para
 * fora da tela. Com um equipamento selecionado, essas quatro colunas viram um
 * subtítulo, dito uma vez.
 */
$umEquipamento = null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

// Vários equipamentos, lista separada por vírgula no mesmo parâmetro `imei`.
// Conferidos contra a lista visível: o parâmetro da URL não alcança
// equipamento fora do escopo.
$imeisVisiveis = array_column($devicesFiltro, 'imei');
$filtroImeis   = array_values(array_intersect(
    array_filter(array_map('trim', explode(',', (string)($_GET['imei'] ?? '')))),
    $imeisVisiveis
));

if (count($filtroImeis) === 1) {
    foreach ($devicesFiltro as $dv) {
        if ($dv['imei'] === $filtroImeis[0]) { $umEquipamento = $dv; break; }
    }
}

$where = 'WHERE 1=1';
$params = [];
if ($scopeCust !== null) {
    // Fase 2 do fluxo chip→câmera→veículo: dono GRAVADO no arquivo
    // (snapshot do momento), não o dono ATUAL da câmera — senão trocar a
    // câmera de cliente reatribuiria a fila de downloads inteira.
    $where .= ' AND mf.customer_id = :cid';
    $params[':cid'] = $scopeCust;
}
if ($filtroImeis) {
    $ph = [];
    foreach ($filtroImeis as $i => $im) { $ph[] = ":im$i"; $params[":im$i"] = $im; }
    $where .= ' AND mf.imei IN (' . implode(',', $ph) . ')';
}
// ── Estados que o OPERADOR tem, não os que a tabela guarda (v4.9.37) ───────
//
// `download_status` descreve o caminho DEVICE → SERVIDOR. Quem opera precisa de
// um terceiro estado, que é de outro eixo — SERVIDOR → PESSOA: **eu já baixei
// este?**. Sem ele, uma fila com dezenas de vídeos prontos não distingue o que
// já foi levado do que ninguém tocou, e o mesmo arquivo é baixado duas vezes.
if ($selStatus === 'pronto') {
    $where .= " AND mf.download_status = 'disponivel' AND mf.downloaded_at IS NULL";
} elseif ($selStatus === 'baixado') {
    $where .= " AND mf.downloaded_at IS NOT NULL";
} elseif ($selStatus === 'pendente') {
    $where .= " AND (mf.download_status = 'solicitado' OR mf.download_status IS NULL)";
} elseif ($selStatus === 'erro') {
    $where .= " AND mf.download_status = 'erro'";
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM media_files mf LEFT JOIN devices d ON d.imei = mf.imei $where");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRows / $perPage));
$offset = ($page - 1) * $perPage;

$filesStmt = $db->prepare("
    SELECT mf.id, mf.imei, mf.file_name, mf.file_url, mf.file_type, mf.file_size,
           mf.event_time, mf.created_at, mf.channel, mf.download_status,
           mf.downloaded_at, mf.download_count,
           COALESCE(NULLIF(d.device_name, ''), mf.imei) AS device_name,
           COALESCE(dm.model_name, '—') as model_name,
           COALESCE(cu.name, '—') AS customer_name
    FROM media_files mf
    LEFT JOIN devices d ON d.imei = mf.imei
    LEFT JOIN device_models dm ON d.device_model_id = dm.id
    LEFT JOIN customers cu ON cu.id = d.customer_id
    $where
    ORDER BY mf.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$filesStmt->execute($params);
$files = $filesStmt->fetchAll();

// ── Exportação (XLSX / PDF / CSV), sensível aos MESMOS filtros ─────────────
//
// Consulta própria, SEM o LIMIT da paginação: quem pede relatório quer o
// recorte inteiro, não os 25 que couberam na tela. Teto explícito para o
// arquivo não virar um dump da tabela inteira, declarado no subtítulo quando
// atingido — arquivo cortado sem aviso passa por completo.
const DOWNLOADS_EXPORT_TETO = 5000;
$export = strtolower(trim($_GET['export'] ?? ''));
if (in_array($export, ['xlsx', 'pdf', 'csv'], true)) {
    require_once __DIR__ . '/../includes/export_helper.php';

    $expStmt = $db->prepare("
        SELECT mf.file_name, mf.imei, mf.file_type, mf.file_size, mf.channel,
               mf.created_at, mf.event_time, mf.download_status, mf.downloaded_at,
               COALESCE(NULLIF(d.device_name, ''), mf.imei) AS device_name,
               COALESCE(dm.model_name, '—') AS model_name,
               COALESCE(cu.name, '—') AS customer_name
        FROM media_files mf
        LEFT JOIN devices d ON d.imei = mf.imei
        LEFT JOIN device_models dm ON d.device_model_id = dm.id
        LEFT JOIN customers cu ON cu.id = d.customer_id
        $where
        ORDER BY mf.created_at DESC
        LIMIT " . DOWNLOADS_EXPORT_TETO);
    $expStmt->execute($params);

    // O export fala a MESMA língua da tela: os três estados do operador, não os
    // do enum. Um relatório que diga "Disponível" onde a tela diz "Já baixado"
    // é uma divergência que ninguém percebe até alguém comparar os dois.
    $rotStatus = [
        'pronto'   => 'Pronto para baixar', 'baixado' => 'Já baixado',
        'pendente' => 'Pendente na câmera', 'erro'    => 'Erro',
    ];
    $rotuloDoArquivo = function ($r) {
        if (!empty($r['downloaded_at'])) return 'Já baixado';
        $st = $r['download_status'] ?? null;
        if ($st === 'disponivel') return 'Pronto para baixar';
        if ($st === 'erro')       return 'Erro';
        return 'Pendente na câmera';
    };
    $expRows = [];
    foreach ($expStmt as $r) {
        $expRows[] = [
            fmt_brt($r['created_at'] ?? $r['event_time'], 'd/m/Y H:i:s'),
            $r['customer_name'],
            $r['device_name'],
            $r['imei'],
            $r['model_name'],
            ($cx = $r['channel'] ?: media_canal_do_nome((string)$r['file_name'])) ? 'CH' . (int)$cx : '—',
            $r['file_name'] ?: '—',
            ($ini = filelist_ts_do_nome_utc((string)$r['file_name']) ?: $r['event_time'])
                ? fmt_brt($ini, 'd/m/Y H:i:s') : '—',
            $r['file_size'] ? number_format($r['file_size'] / 1024 / 1024, 1, ',', '.') : '—',
            $rotuloDoArquivo($r),
            !empty($r['downloaded_at']) ? fmt_brt($r['downloaded_at'], 'd/m/Y H:i:s') : '—',
        ];
    }

    $rotCliente = 'Todos os clientes';
    if ($scopeCust !== null) {
        foreach ($customers as $c) if ((int)$c['id'] === (int)$scopeCust) $rotCliente = 'Cliente: ' . $c['name'];
        if ($rotCliente === 'Todos os clientes') $rotCliente = 'Cliente ' . (int)$scopeCust;
    }
    $subtitulo = $rotCliente
        . '  |  ' . ($filtroImeis ? count($filtroImeis) . ' equipamento(s)' : 'Todos os equipamentos')
        . '  |  ' . ($selStatus !== '' ? 'Status: ' . ($rotStatus[$selStatus] ?? $selStatus) : 'Todos os status')
        . '  |  ' . $totalRows . ' arquivo(s)'
        . (count($expRows) >= DOWNLOADS_EXPORT_TETO ? '  |  ATENÇÃO: cortado nos ' . DOWNLOADS_EXPORT_TETO . ' mais recentes' : '');

    stream_export($export, 'downloads_video',
        ['Requisitado em', 'Cliente', 'Placa', 'IMEI', 'Modelo', 'Canal',
         'Arquivo', 'Início do vídeo', 'Tamanho (MB)', 'Status', 'Baixado em'],
        $expRows, 'Downloads de Vídeo', $subtitulo,
        // Nome do arquivo é a coluna longa (`EVENT_<imei>_..._I_15.mp4`); as
        // demais são curtas e de largura previsível.
        [1.3, 1.2, 1.0, 1.4, 0.9, 0.5, 3.4, 0.6, 0.8, 1.1, 1.2]);
}

$page_title = 'Vídeo Downloads';
$current_route = 'video_downloads';
require_once __DIR__ . '/../web/layout_base.php';
?>

<div class="flex-between mb-16">
    <div>
        <h2 style="font-size:18px;font-weight:600;color:var(--ink);">Downloads de Vídeo</h2>
        <p class="text-muted" style="font-size:12px;margin-top:4px;">
            Fila de extração device → servidor. O status atualiza quando o dispositivo envia o arquivo.
        </p>
    </div>
</div>

<?php
/* Os filtros viraram um form GET. Antes o status recarregava por
   `location.href='?status='+…`, o que APAGAVA qualquer outro parâmetro —
   com cliente e equipamentos na URL, trocar o status jogaria os dois fora. */
$qsExport = function (string $fmt) use ($scopeCust, $filtroImeis, $selStatus): string {
    return '?' . http_build_query(array_filter([
        'customer_id' => $scopeCust !== null ? (int)$scopeCust : '',
        'imei'        => implode(',', $filtroImeis),
        'status'      => $selStatus,
        'export'      => $fmt,
    ], fn($v) => $v !== '' && $v !== null));
};
?>
<form method="get" class="flex" style="gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end;">
    <?php if ($isAdmin): ?>
    <div>
        <label class="filtro-rotulo" for="dl-cust">Cliente</label>
        <select id="dl-cust" name="customer_id" class="filtro-campo" style="min-width:160px">
            <option value="">Todos os clientes</option>
            <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (string)$scopeCust === (string)$c['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php /* 🔴 LISTA SUSPENSA, e não a nuvem de chips que estava aqui. A
             pergunta desta tela é "o que EU tenho para baixar deste
             equipamento?" — uma escolha, feita uma vez. Os 15 chips ocupavam
             três linhas, quebravam o layout e ofereciam multi-seleção que
             ninguém pediu; e com vários equipamentos a grade precisa repetir
             placa/IMEI/modelo em toda linha só para dizer de quem é o arquivo. */ ?>
    <div>
        <label class="filtro-rotulo" for="dl-imei">Placa</label>
        <select id="dl-imei" name="imei" class="filtro-campo" style="min-width:200px" title="Placa do veículo">
            <option value="">Todas as placas</option>
            <?php foreach ($devicesFiltro as $dv): ?>
            <option value="<?= htmlspecialchars($dv['imei']) ?>" <?= (count($filtroImeis) === 1 && $filtroImeis[0] === $dv['imei']) ? 'selected' : '' ?>>
                <?= htmlspecialchars(placa_do_device($dv['device_name'], $dv['imei'])) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="filtro-rotulo" for="dl-status">Status</label>
        <select id="dl-status" name="status" class="filtro-campo">
            <option value="">Todos</option>
            <option value="pronto"   <?= $selStatus==='pronto'?'selected':'' ?>>Pronto para baixar</option>
            <option value="baixado"  <?= $selStatus==='baixado'?'selected':'' ?>>Já baixado</option>
            <option value="pendente" <?= $selStatus==='pendente'?'selected':'' ?>>Pendente na câmera</option>
            <option value="erro"     <?= $selStatus==='erro'?'selected':'' ?>>Erro</option>
        </select>
    </div>
    <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
</form>

<?php if ($umEquipamento): ?>
<div style="font-size:12px;color:var(--muted);margin-bottom:10px;">
    Vídeos de <strong style="color:var(--ink)"><?= htmlspecialchars($umEquipamento['device_name']) ?></strong>
    <span class="text-mono" style="font-size:11px;">(<?= htmlspecialchars($umEquipamento['imei']) ?>)</span>
    que já estão no storage.
</div>
<?php endif; ?>

<div class="flex" style="gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center;">
    <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($qsExport('xlsx')) ?>">Exportar Excel</a>
    <a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($qsExport('pdf')) ?>">Exportar PDF</a>
    <span style="font-size:11px;color:var(--muted);"><?= (int)$totalRows ?> arquivo(s) no filtro atual</span>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <?php /* 🔴 ORDEM E LARGURA (v4.17.16). "Arquivo" era a PRIMEIRA
                     coluna, com `max-width:200px` e reticências — e o nome
                     precisa de **894 px** (medido). O que sobrava na tela era
                     `865478070654829_303635343832…`: o IMEI, que já tem coluna
                     própria, mais o começo de um blob igual em todas as linhas.
                     Ou seja, a coluna mostrava só o que se repete e escondia
                     exatamente o que distingue uma linha da outra.
                     Agora ela é a ÚLTIMA das colunas de dados — depois das
                     curtas, que empacotam à esquerda —, quebra em vez de cortar,
                     e fica colada na ação que a usa. */ ?>
            <tr>
                <?php if (!$umEquipamento): ?>
                <?php if ($mostrarCliente): ?><th class="dl-curta">Cliente</th><?php endif; ?>
                <th class="dl-curta">Placa</th>
                <th class="dl-curta">IMEI</th>
                <th class="dl-curta">Modelo</th>
                <?php endif; ?>
                <th class="dl-canal">Canal</th>
                <?php /* 🔴 A tela dizia QUANDO foi pedido e nunca QUANDO é o
                         vídeo — que é a informação com que se procura uma
                         gravação. Sai do carimbo do NOME, que é o instante da
                         gravação; `created_at` é o do pedido, e os dois podem
                         estar a dias de distância quando se extrai algo antigo. */ ?>
                <th class="dl-data">Início do vídeo</th>
                <th class="dl-data">Requisitado em</th>
                <th class="dl-nome">Arquivo</th>
                <th class="dl-curta">Status</th>
                <th class="dl-acao" style="text-align:center;">Download</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($files)): ?>
            <tr><td colspan="<?= $umEquipamento ? 6 : ($mostrarCliente ? 10 : 9) ?>" style="text-align:center;padding:32px;color:var(--muted);">
                <?= $umEquipamento ? 'Nenhum vídeo deste equipamento no storage ainda.' : 'Nenhum arquivo encontrado' ?>
                <?php if ($umEquipamento): ?>
                <div style="font-size:11px;margin-top:6px;">Peça um trecho em <a href="/video/playback?imei=<?= htmlspecialchars($umEquipamento['imei']) ?>">Playback</a> — ele aparece aqui quando a câmera terminar de enviar.</div>
                <?php endif; ?>
            </td></tr>
            <?php else: ?>
            <?php foreach ($files as $f):
                $ds = $f['download_status'] ?? null;
                $isError     = $ds === 'erro';
                $isAvailable = $ds === 'disponivel';
                $isRequested = $ds === 'solicitado' || $ds === null;
                $jaBaixado   = !empty($f['downloaded_at']);
            ?>
            <tr>
                <?php if (!$umEquipamento): ?>
                <?php if ($mostrarCliente): ?>
                <td class="dl-curta" style="font-size:11px;"><?= htmlspecialchars($f['customer_name']) ?></td>
                <?php endif; ?>
                <td class="dl-curta"><span class="text-mono"><?= htmlspecialchars($f['device_name']) ?></span></td>
                <td class="dl-curta"><span class="text-mono" style="font-size:11px;color:var(--muted);"><?= htmlspecialchars($f['imei']) ?></span></td>
                <td class="dl-curta"><?= htmlspecialchars($f['model_name']) ?></td>
                <?php endif; ?>
                <?php
                    // Canal derivado do nome quando a linha é antiga: até a
                    // v4.9.39 a coluna nunca era gravada, e as linhas de então
                    // continuam com NULL. O dado está no nome — `_F_`/`_I_`.
                    $canalF = $f['channel'] ?: media_canal_do_nome((string)$f['file_name']);
                    $inicio = filelist_ts_do_nome_utc((string)$f['file_name']) ?: $f['event_time'];
                ?>
                <td class="dl-canal"><?= $canalF ? 'CH' . (int)$canalF : '—' ?></td>
                <td class="dl-data text-mono"><?= $inicio ? fmt_brt($inicio) : '—' ?></td>
                <td class="dl-data text-mono"><?= fmt_brt($f['created_at'] ?? $f['event_time']) ?></td>
                <?php
                    // ── O NOME, INTEIRO ────────────────────────────────────
                    //
                    // 🔴 SÃO DOIS ARQUIVOS QUANDO A CÂMERA É JIMI, e a tela
                    // imprimia a string CRUA: `..._I_40.mp4,..._F_39.mp4`, 119
                    // caracteres num campo de 200 px. A coluna Download já
                    // separa os dois em botões — `media_file_list()` é o ponto
                    // único disso (includes/media.php) — e a coluna do nome
                    // ignorava a separação, colando os dois num blob que não é
                    // o nome de arquivo nenhum.
                    $nomes = media_file_list($f['file_name']);
                    if (!$nomes) $nomes = ['—'];
                ?>
                <td class="dl-nome" title="<?= htmlspecialchars((string)($f['file_name'] ?? '')) ?>">
                    <?php foreach ($nomes as $nm): ?>
                    <?php
                        // O prefixo `(EVENT_)<imei>_` se repete em TODA linha e
                        // já é a coluna IMEI ao lado: fica cinza, para que o
                        // olho caia direto no que distingue este arquivo. Nada
                        // é escondido — o nome inteiro continua na tela.
                        $pref = '';
                        $resto = $nm;
                        if (preg_match('/^(EVENT_)?' . preg_quote((string)$f['imei'], '/') . '_/', $nm, $mm)) {
                            $pref  = $mm[0];
                            $resto = substr($nm, strlen($pref));
                        }
                        $chNm = count($nomes) > 1 ? media_canal_do_nome($nm) : null;
                    ?>
                    <div class="dl-arq">
                        <?php if ($chNm): ?><span class="dl-arq-ch">CH<?= (int)$chNm ?></span><?php endif; ?>
                        <span class="dl-arq-txt"><?php if ($pref !== ''): ?><span class="dl-arq-pref"><?= htmlspecialchars($pref) ?></span><?php endif; ?><?= htmlspecialchars($resto) ?></span>
                    </div>
                    <?php endforeach; ?>
                </td>
                <td class="dl-curta">
                    <?php if ($jaBaixado): ?>
                    <span class="badge badge-success" title="Baixado em <?= fmt_brt($f['downloaded_at']) ?><?= (int)($f['download_count'] ?? 0) > 1 ? ' · ' . (int)$f['download_count'] . 'x' : '' ?>">Já baixado</span>
                    <?php elseif ($isAvailable): ?>
                    <span class="badge badge-success" style="opacity:.75">Pronto</span>
                    <?php elseif ($isError): ?>
                    <span class="badge badge-error">Erro</span>
                    <?php else: ?>
                    <span class="badge badge-warning"><span class="spinner-inline"></span> Pendente na câmera</span>
                    <?php endif; ?>
                </td>
                <td class="dl-acao" style="text-align:center;">
                    <?php if ($isAvailable):
                        // 🔴 `mf.file_url` pode trazer MAIS DE UM arquivo, vírgula-
                        // separados — a JIMI anuncia frontal e interna no MESMO
                        // campo (includes/media.php, media_file_list()). Montar o
                        // link colando o campo CRU direto na URL do /midia (como
                        // esta tela fazia) produz um `f=` que não casa com arquivo
                        // NENHUM no disco: midia.php devolve 404 em texto/html sem
                        // Content-Disposition, e o botão "Baixar" (atributo
                        // `download`, sem nome fixo) salva essa página de erro como
                        // um arquivo ".html" no lugar do vídeo — sintoma relatado:
                        // "o arquivo baixado é um html". Mesmo ponto único que
                        // ativo_detalhe.php e rel_alarmes.php já usam para isto: um
                        // link por arquivo REAL da lista, não pela string inteira.
                        $arquivosReais = array_values(array_filter(
                            media_file_list($f['file_url']), 'media_available'
                        ));
                    ?>
                    <?php if ($arquivosReais): foreach ($arquivosReais as $i => $nomeArq):
                        $canalArq = media_canal_do_nome($nomeArq);
                        $rotuloArq = ($jaBaixado ? 'Baixar de novo' : 'Baixar')
                            . ($canalArq ? ' CH' . $canalArq : (count($arquivosReais) > 1 ? ' ' . ($i + 1) : ''));
                    ?>
                    <?php /* ⚠️ `&dl=1` é o que carimba `downloaded_at`. O player
                             usa a MESMA URL sem esse marcador, para que assistir
                             não vire "baixado" — ver handlers/midia.php. */ ?>
                    <a href="<?= htmlspecialchars($fileStorageUrl . rawurlencode(basename($nomeArq))) ?>&dl=1"
                       class="btn <?= $jaBaixado ? 'btn-outline' : 'btn-primary' ?> btn-sm"
                       style="padding:4px 12px;font-size:12px;<?= $i > 0 ? 'margin-left:4px;' : '' ?>"
                       target="_blank" download><?= htmlspecialchars($rotuloArq) ?></a>
                    <?php endforeach; else: ?>
                    <span class="badge badge-error" style="font-size:11px;" title="A câmera anunciou este arquivo mas ele não está no disco do servidor.">Arquivo ausente</span>
                    <?php endif; ?>
                    <?php elseif ($isRequested): ?>
                    <span class="badge" style="font-size:11px;" title="A câmera ainda não terminou de enviar">Aguardando câmera</span>
                    <?php else: ?>
                    <span class="badge badge-error" style="font-size:11px;">Falhou</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?= report_pagination($page, $totalPages, $totalRows, 'arquivos') ?>

<style>
.spinner-inline{display:inline-block;width:10px;height:10px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;margin-right:4px;vertical-align:middle;}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Largura das colunas (v4.17.16) ──────────────────────────────────────
   🔴 O NOME DO ARQUIVO NÃO CABE EM UMA LINHA, e não é questão de apertar as
   outras colunas: medido nesta tela, ele precisa de **894 px** — são 57
   caracteres no caso simples e **119** quando a câmera JIMI anuncia frontal e
   interna no mesmo campo. Cortar com reticências escondia justamente a parte
   que muda de linha para linha (o prefixo visível era o IMEI, que tem coluna
   própria). A saída é QUEBRAR, não truncar: `break-all` porque o nome não tem
   espaço nenhum onde quebrar, e `max-width` para que ele não engula a tabela.

   As demais colunas ganham `nowrap` e largura declarada: sem isso o navegador
   reparte a folga igualmente e a coluna que precisa dela é a única que não a
   recebe. O `.table-wrap` já rola na horizontal, então o pior caso é rolagem,
   nunca texto cortado. */
.dl-nome{min-width:280px;max-width:400px;white-space:normal;word-break:break-all;line-height:1.4;}
.dl-curta{white-space:nowrap;}
.dl-canal{white-space:nowrap;width:1%;}
.dl-data{white-space:nowrap;width:1%;font-size:12px;}
.dl-acao{white-space:nowrap;}
/* Padding menor só aqui: são 10 colunas, e 16 px de cada lado em cada uma
   custam 320 px — a largura que falta ao nome do arquivo. */
.table-wrap thead th, .table-wrap tbody td{padding-left:12px;padding-right:12px;}

/* Um arquivo REAL por linha. A JIMI manda os dois num campo só, e a coluna
   Download já os separa em dois botões — o nome tem de contar a mesma
   história, senão a linha diz "um arquivo" e a ação oferece dois. */
.dl-arq{display:flex;align-items:baseline;gap:6px;}
.dl-arq + .dl-arq{margin-top:5px;padding-top:5px;border-top:1px dashed var(--hairline);}
.dl-arq-ch{flex:0 0 auto;font-size:9.5px;font-weight:600;letter-spacing:.3px;color:var(--muted);
           background:var(--canvas-soft);border:1px solid var(--hairline);border-radius:100px;padding:1px 6px;}
.dl-arq-txt{font-family:"JetBrains Mono",monospace;font-size:11.5px;color:var(--ink);min-width:0;}
/* O prefixo `(EVENT_)<imei>_` é o mesmo em toda linha e já é a coluna ao lado:
   cinza, para o olho cair no que distingue. Continua legível e selecionável —
   esconder parte do nome seria repetir o defeito, só que mais discreto. */
.dl-arq-pref{color:var(--muted-soft);}
</style>

<?php require_once __DIR__ . '/../web/layout_base_close.php'; ?>
