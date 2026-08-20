<?php
/**
 * Componente: LISTA SUSPENSA com seleção múltipla (v4.9.38)
 *
 * Substitui o `chips_multiselect.php`, que espalhava dezenas de botões pela
 * tela. O problema dos chips não era estético: com 15 opções eles ocupavam três
 * linhas e empurravam o resto do filtro para baixo, e com 40 (tipos de alarme)
 * exigiam um "+N" para expandir — ou seja, o controle mudava de tamanho
 * conforme o cadastro do cliente, e não se parecia com nenhum outro filtro do
 * sistema. Aqui a altura é fixa e a aparência é a das outras listas suspensas.
 *
 * Mantém o CONTRATO de saída do componente antigo: um `<input type=hidden>` com
 * os valores separados por vírgula, no mesmo parâmetro GET. Nenhuma consulta,
 * nenhum link e nenhum export precisou mudar por causa desta troca.
 *
 * Variáveis esperadas antes do include:
 *   $msel_id       string  Prefixo único no DOM (ex.: 'alarmtypes')
 *   $msel_label    string  Rótulo do campo
 *   $msel_param    string  Nome do parâmetro GET (ex.: 'alarm_types')
 *   $msel_options  array   Lista de VALORES enviados
 *   $msel_selected array   Valores pré-selecionados
 *   $msel_labels   array   OPCIONAL, mapa valor => rótulo exibido
 *   $msel_vazio    string  OPCIONAL, texto de "nada selecionado" (default 'Todos')
 *   $msel_busca    int     OPCIONAL, a partir de quantas opções mostrar a busca (default 8)
 *
 * ⚠️ `$msel_labels` existe porque o valor nem sempre é o texto. Em tipo de
 * alarme o valor É o nome; em veículo o valor tem de ser o `imei` (é por ele
 * que as consultas casam) e o rótulo, a PLACA. Sem o mapa, filtrar por nome
 * faria dois veículos de mesma placa cadastrada se confundirem.
 */
$msel_labels   = $msel_labels   ?? [];
$msel_vazio    = $msel_vazio    ?? 'Todos';
$msel_busca    = $msel_busca    ?? 8;
$msel_selected = array_values(array_filter((array)$msel_selected, fn($v) => $v !== '' && $v !== null));
$_msn = count($msel_selected);
$_msResumo = $_msn === 0
    ? $msel_vazio
    : ($_msn === 1 ? ($msel_labels[$msel_selected[0]] ?? $msel_selected[0]) : $_msn . ' selecionados');
?>
<?php if (empty($GLOBALS['_msel_css_emitted'])): $GLOBALS['_msel_css_emitted'] = true; ?>
<style>
.msel{position:relative;display:inline-block;}
.msel-botao{display:flex;align-items:center;gap:8px;justify-content:space-between;cursor:pointer;
            text-align:left;width:100%;background:var(--canvas);}
.msel-botao .txt{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.msel-botao .seta{flex:0 0 auto;font-size:9px;color:var(--muted);}
.msel-painel{position:absolute;z-index:40;top:calc(100% + 4px);left:0;min-width:100%;
             background:var(--canvas);border:1px solid var(--hairline);border-radius:var(--radius-sm);
             box-shadow:0 8px 26px rgba(0,0,0,.14);padding:6px;}
.msel-painel[hidden]{display:none;}
.msel-busca{width:100%;margin-bottom:6px;}
.msel-lista{max-height:230px;overflow-y:auto;}
.msel-item{display:flex;align-items:center;gap:7px;padding:4px 6px;border-radius:4px;
           font-size:12px;color:var(--ink);cursor:pointer;white-space:nowrap;}
.msel-item:hover{background:var(--canvas-soft);}
.msel-item input{margin:0;cursor:pointer;}
.msel-acoes{display:flex;gap:6px;padding:4px 2px 6px;border-bottom:1px solid var(--hairline-soft);margin-bottom:4px;}
.msel-acoes button{font-size:11px;padding:2px 8px;background:none;border:1px solid var(--hairline);
                   border-radius:100px;color:var(--muted);cursor:pointer;}
.msel-acoes button:hover{color:var(--primary);border-color:var(--primary);}
.msel-nada{font-size:11px;color:var(--muted);padding:6px;}
</style>
<script>
/**
 * Sincroniza o hidden e o resumo do botão a partir das caixas marcadas.
 * O hidden é a ÚNICA saída do componente — é ele que o formulário envia.
 */
function mselSync(id) {
    var raiz = document.getElementById('msel-' + id);
    var marcados = Array.prototype.slice.call(raiz.querySelectorAll('.msel-item input:checked'));
    raiz.querySelector('.msel-hidden').value = marcados.map(function (c) { return c.value; }).join(',');
    var txt = raiz.querySelector('.msel-botao .txt');
    if (!marcados.length)      txt.textContent = raiz.dataset.vazio;
    else if (marcados.length === 1) txt.textContent = marcados[0].dataset.rotulo;
    else                       txt.textContent = marcados.length + ' selecionados';
}
function mselAbrir(id, forcar) {
    var raiz = document.getElementById('msel-' + id);
    var painel = raiz.querySelector('.msel-painel');
    var abrir = (forcar !== undefined) ? forcar : painel.hidden;
    // Um painel por vez: dois abertos se sobrepõem e escondem um ao outro.
    document.querySelectorAll('.msel-painel').forEach(function (p) { p.hidden = true; });
    document.querySelectorAll('.msel-botao').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    painel.hidden = !abrir;
    raiz.querySelector('.msel-botao').setAttribute('aria-expanded', abrir ? 'true' : 'false');
    if (abrir) { var b = raiz.querySelector('.msel-busca'); if (b) b.focus(); }
}
function mselTodos(id, marcar) {
    var raiz = document.getElementById('msel-' + id);
    raiz.querySelectorAll('.msel-item').forEach(function (it) {
        if (it.style.display === 'none') return;      // respeita a busca em curso
        it.querySelector('input').checked = marcar;
    });
    mselSync(id);
}
function mselBuscar(id, termo) {
    var raiz = document.getElementById('msel-' + id);
    var t = (termo || '').toLowerCase();
    var achou = 0;
    raiz.querySelectorAll('.msel-item').forEach(function (it) {
        var bate = it.dataset.busca.indexOf(t) > -1;
        it.style.display = bate ? '' : 'none';
        if (bate) achou++;
    });
    raiz.querySelector('.msel-nada').style.display = achou ? 'none' : 'block';
}
document.addEventListener('click', function (ev) {
    if (ev.target.closest && ev.target.closest('.msel')) return;
    document.querySelectorAll('.msel-painel').forEach(function (p) { p.hidden = true; });
    document.querySelectorAll('.msel-botao').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
});
document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Escape') return;
    document.querySelectorAll('.msel-painel').forEach(function (p) { p.hidden = true; });
});
</script>
<?php endif; ?>
<div class="msel" id="msel-<?= htmlspecialchars($msel_id) ?>" data-vazio="<?= htmlspecialchars($msel_vazio) ?>">
    <label class="filtro-rotulo" for="msel-bt-<?= htmlspecialchars($msel_id) ?>"><?= htmlspecialchars($msel_label) ?></label>
    <button type="button" class="filtro-campo msel-botao" id="msel-bt-<?= htmlspecialchars($msel_id) ?>"
            aria-haspopup="listbox" aria-expanded="false"
            onclick="mselAbrir('<?= htmlspecialchars($msel_id) ?>')">
        <span class="txt"><?= htmlspecialchars($_msResumo) ?></span><span class="seta">&#9660;</span>
    </button>
    <div class="msel-painel" hidden role="listbox" aria-multiselectable="true">
        <?php if (count($msel_options) >= $msel_busca): ?>
        <input type="text" class="filtro-campo msel-busca" placeholder="Buscar…"
               oninput="mselBuscar('<?= htmlspecialchars($msel_id) ?>', this.value)">
        <?php endif; ?>
        <div class="msel-acoes">
            <button type="button" onclick="mselTodos('<?= htmlspecialchars($msel_id) ?>', true)">Marcar todos</button>
            <button type="button" onclick="mselTodos('<?= htmlspecialchars($msel_id) ?>', false)">Limpar</button>
        </div>
        <div class="msel-lista">
            <?php foreach ($msel_options as $_opt): ?>
            <?php $_rot = (string)($msel_labels[$_opt] ?? $_opt); ?>
            <label class="msel-item" data-busca="<?= htmlspecialchars(mb_strtolower($_rot)) ?>">
                <input type="checkbox" value="<?= htmlspecialchars((string)$_opt) ?>"
                       data-rotulo="<?= htmlspecialchars($_rot) ?>"
                       <?= in_array($_opt, $msel_selected, true) ? 'checked' : '' ?>
                       onchange="mselSync('<?= htmlspecialchars($msel_id) ?>')">
                <span><?= htmlspecialchars($_rot) ?></span>
            </label>
            <?php endforeach; ?>
            <div class="msel-nada" style="display:none;">Nada encontrado.</div>
        </div>
    </div>
    <?php /* A saída do componente: mesmo parâmetro e mesmo formato do antigo,
             para que consulta, link e export não precisem saber que mudou. */ ?>
    <input type="hidden" class="msel-hidden" name="<?= htmlspecialchars($msel_param) ?>"
           value="<?= htmlspecialchars(implode(',', $msel_selected)) ?>">
</div>
