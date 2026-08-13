<?php
/**
 * Parser de parâmetros (v4.9.12) — fixado nas respostas REAIS das câmeras.
 *
 * As três respostas abaixo foram capturadas de equipamentos do homolog em
 * 12/08/2026, com `curl` direto no IoT Hub. Elas são a razão de este parser
 * existir do jeito que existe: a doc oficial descreve outra coisa em três
 * pontos, e um parser escrito por ela falharia **calado** nos três.
 *
 * O que este teste protege não é a formatação — é a leitura das bordas que a
 * doc não descreve e que só apareceram em campo.
 *
 * Uso (não precisa de banco):
 *   php tests/helpers/device_params.test.php
 */

require_once __DIR__ . '/../../includes/device_params.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-58s esperado=%s obtido=%s\n",
        $ok ? 'OK ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

// ── Fixture 1: JC371, 33028 (todos) — 612 bytes ────────────────────────────
$jc371 = '{"paramCount":"87","paramId":"119","channelCount":3,'
 . '"channel_1":"1,0,0,0,0,0,0,0,0,0,0,0","channel_2":"2,0,0,0,0,0,0,0,0,0,0,0",'
 . '"channel_3":"3,0,0,0,0,0,0,0,0,0,0,0","85":"0","86":"600","87":"0","88":"0",'
 . '"89":"3888000","90":"0","91":"0.0","92":"0","16":"cmnet","17":"usr","18":"pwd",'
 . '"19":"189.22.240.43","20":"","21":"","22":"","23":"","24":"21122","25":"0",'
 . '"1":"60","32":"0","128":"0","129":"0","130":"0","131":"","132":0,"41":"60",'
 . '"93":"0","100":"0","2":"0","3":"0","4":"0","5":"0","6":"0","7":"0","33":"0",'
 . '"34":"0","39":"60","40":"0","44":"0","45":"0","46":"300","47":"0","48":"45",'
 . '"49":"0","82":"0","83":"0"}';

echo "── JC371 · 33028 (612 bytes, 46 numéricos + 3 canais) ──\n";
$p = parse_param_content($jc371);
checa('parseia', true, $p['ok']);
checa('paramCount lido do campo REAL (nao totalNum)', 87, $p['param_count']);
checa('total de entradas = 46 numericos + 3 canais', 49, count($p['entradas']));

$porNo = [];
foreach ($p['entradas'] as $e) $porNo[$e['param_no'] . ':' . $e['channel']] = $e;

checa('🔴 canal 3 existe (o bloco que a doc nao descreve)', true, isset($porNo['119:3']));
checa('canal 2 expandido: numero do canal', 2, $porNo['119:2']['json']['canal'] ?? null);
checa('canal 2 expandido: 12 campos nomeados', 12, count($porNo['119:2']['json'] ?? []));
checa('🔴 nenhuma entrada com param_no 0 (meta virou parametro?)', false, isset($porNo['0:0']));
checa('paramId NAO virou parametro numerico', false, isset($porNo['119:0']));
checa('85 (velocidade maxima) = 0 — camera sem limite', '0', $porNo['85:0']['raw'] ?? null);
checa('89 (descanso minimo) preservado', '3888000', $porNo['89:0']['raw'] ?? null);
checa('91 decimal em string nao vira int', '0.0', $porNo['91:0']['raw'] ?? null);
checa('🔴 132 chega como NUMERO e vira string', '0', $porNo['132:0']['raw'] ?? null);
checa('131 vazio e preservado como vazio', '', $porNo['131:0']['raw'] ?? null);
checa('16 (APN) preservado', 'cmnet', $porNo['16:0']['raw'] ?? null);
checa('83 (ultimo do payload) chegou', '0', $porNo['83:0']['raw'] ?? null);

// ── Fixture 2: JC181, 33028 — apenas 6 parâmetros ──────────────────────────
echo "\n── JC181 · 33028 (modelo devolve so 6 — perfil e por MODELO) ──\n";
$jc181 = '{"paramCount":"6","1":"180","19":"189.22.240.43","24":"21122",'
       . '"41":"180","48":"45","128":"15"}';
$p2 = parse_param_content($jc181);
checa('parseia', true, $p2['ok']);
checa('paramCount bate com as chaves neste modelo', 6, $p2['param_count']);
checa('6 entradas', 6, count($p2['entradas']));
checa('sem bloco de canal', 0, count(array_filter($p2['entradas'], fn($e) => $e['channel'] > 0)));

// ── Fixture 3: 33030 (especificos) ─────────────────────────────────────────
echo "\n── JC371 · 33030 (pedido de 44, 45 e 85) ──\n";
$esp = '{"paramCount":"3","85":"0","44":"0","45":"0"}';
$p3 = parse_param_content($esp);
checa('parseia', true, $p3['ok']);
checa('paramCount = 3 e bate', 3, $p3['param_count']);
checa('3 entradas', 3, count($p3['entradas']));

// ── Bordas que NAO podem derrubar a leitura ────────────────────────────────
echo "\n── Bordas ──\n";
$trunc = substr($jc371, 0, 250);   // exatamente o que a v4.9.11 consertou
$p4 = parse_param_content($trunc);
checa('🔴 payload truncado em 250 e RECUSADO, nao meio-lido', false, $p4['ok']);
checa('truncado explica o motivo', true, str_contains((string)$p4['erro'], 'JSON inválido'));

$p5 = parse_param_content('');
checa('conteudo vazio nao estoura', false, $p5['ok']);

$p6 = parse_param_content('{"paramCount":"1","999":"abc"}');
checa('parametro fora do catalogo e LIDO (nao some)', 1, count($p6['entradas']));
checa('  e mantem o valor cru', 'abc', $p6['entradas'][0]['raw']);

$p7 = parse_param_content('{"channel_1":"1,2,5,25,30,500,0,3,25,30,400,7"}');
checa('bloco de canal sem paramId assume 119', 119, $p7['entradas'][0]['param_no']);
checa('  resolucao real-time expandida (5 = 720P)', 5, $p7['entradas'][0]['json']['rt_resolucao']);
checa('  osd = 7', 7, $p7['entradas'][0]['json']['osd']);

$p8 = parse_param_content('{"channel_1":"1,2,5"}');
checa('CSV curto nao derruba: le o que veio', 3, count($p8['entradas'][0]['json']));

// ── Formatacao e rotulos (sem banco: catalogo simulado) ────────────────────
echo "\n── Rótulos e formatação ──\n";
$cat = [
    85 => ['name_pt' => 'Velocidade Máxima', 'unit' => 'km/h', 'value_kind' => 'int',
           'enum_json' => null, 'is_secret' => 0],
    89 => ['name_pt' => 'Descanso Mínimo', 'unit' => 's', 'value_kind' => 'int',
           'enum_json' => null, 'is_secret' => 0],
    32 => ['name_pt' => 'Estratégia de Reporte', 'unit' => null, 'value_kind' => 'enum',
           'enum_json' => '{"0":"Por tempo","1":"Por distância"}', 'is_secret' => 0],
    18 => ['name_pt' => 'Senha do APN', 'unit' => null, 'value_kind' => 'text',
           'enum_json' => null, 'is_secret' => 1],
];
checa('rotulo do catalogo', 'Velocidade Máxima', param_label($cat, 85));
checa('🔴 fora do catalogo mostra o NUMERO, nao inventa nome', 'Parâmetro 128', param_label($cat, 128));
checa('int com unidade', '110 km/h', param_format($cat, 85, '110'));
checa('segundos grandes ganham leitura humana', '3888000 s (45d)', param_format($cat, 89, '3888000'));
checa('enum traduzido', 'Por tempo (0)', param_format($cat, 32, '0'));
checa('enum fora do mapa devolve o cru', '7', param_format($cat, 32, '7'));
checa('🔴 credencial mascarada por omissao', '••••••', param_format($cat, 18, 'pwd'));
checa('  e revelada ao admin', 'pwd', param_format($cat, 18, 'pwd', true));
checa('valor vazio vira travessao', '—', param_format($cat, 85, ''));
checa('osd decodificado', 'Data e hora, Placa, Canal', param_osd_labels(7));
checa('osd zero e explicito', 'nenhuma legenda', param_osd_labels(0));
checa('resumo de video', '720P · ABR · 30 fps · 500 kbps',
      param_video_resumo(['rt_resolucao' => 5, 'rt_encoding' => 2, 'rt_fps' => 30, 'rt_bitrate' => 500]));

// ── cmdContent: as tres formas sao DIFERENTES ──────────────────────────────
echo "\n── cmdContent (§3.1: a implementação antiga errou nas três) ──\n";
checa('33028 vai VAZIO', '', build_param_cmd_content(33028, []));
checa('33030 vira objeto de valores vazios', '{"44":"","45":""}',
      build_param_cmd_content(33030, [44, 45]));
checa('33030 aceita mapa tambem', '{"85":""}',
      build_param_cmd_content(33030, ['85' => '']));
checa('33027 vira mapa no=>valor', '{"1":"60","85":"110"}',
      build_param_cmd_content(33027, [1 => 60, 85 => '110']));
checa('33027 sem parametro nao vira comando vazio perigoso', '{}',
      build_param_cmd_content(33027, []));

// ── Moda: a regra que decide o que e "fora do padrao" (v4.9.13) ────────────
echo "\n── Padrão da frota (param_moda) ──\n";
checa('maioria clara', '110', param_moda(['a'=>'110','b'=>'110','c'=>'90'])[0]);
checa('  e conta quantos', 2, param_moda(['a'=>'110','b'=>'110','c'=>'90'])[1]);
checa('🔴 EMPATE nao elege vencedor', null, param_moda(['a'=>'110','b'=>'90'])[0]);
checa('  empate a 2x2 tambem', null, param_moda(['a'=>'1','b'=>'1','c'=>'2','d'=>'2'])[0]);
checa('unanimidade', '0', param_moda(['a'=>'0','b'=>'0','c'=>'0'])[0]);
checa('lista vazia nao estoura', null, param_moda([])[0]);
checa('um valor so', '5', param_moda(['a'=>'5'])[0]);
checa('numero e string sao o MESMO valor', '60', param_moda(['a'=>60,'b'=>'60','c'=>'90'])[0]);

// ── Regressao: o catalogo tem de trazer TODAS as colunas (v4.9.14) ─────────
//
// 🔴 Este bloco existe por causa de um incidente real em 12/08/2026: o
// `param_catalog()` selecionava colunas uma a uma e a coluna `is_network` --
// que marca os parametros cuja escrita errada tira a camera da plataforma --
// nao entrou na lista. A trava de confirmacao virou decoracao e um parametro
// `19` (Servidor Principal) foi escrito numa camera REAL sem confirmacao.
//
// Precisa de banco; sem ele, PULA em vez de passar por vacuidade.
echo "\n── Catálogo: colunas de guarda presentes (precisa de banco) ──\n";
$colunasGuarda = ['writable', 'is_secret', 'is_network', 'doc_ref', 'value_kind', 'grupo'];
try {
    require_once __DIR__ . '/../../config/database.php';
    $dbT = Database::getInstance()->getConnection();
    $cat = param_catalog($dbT);
    if (!$cat) {
        echo "  PULADO: catálogo vazio (migração v4.9.12 não aplicada neste banco)\n";
    } else {
        $linha = $cat[19] ?? reset($cat);
        foreach ($colunasGuarda as $col) {
            checa("coluna '$col' chega ao código", true, array_key_exists($col, $linha));
        }
        if (isset($cat[19])) {
            checa('🔴 19 (Servidor Principal) marcado como is_network', 1, (int)$cat[19]['is_network']);
            checa('🔴 128 (medido, sem doc) NAO e gravavel', 0, (int)($cat[128]['writable'] ?? -1));
        }
    }
} catch (Throwable $e) {
    echo "  PULADO: sem banco (" . $e->getMessage() . ")\n";
}

echo $falhas ? "\n$falhas falha(s) de $total\n" : "\nTodos os $total casos passaram\n";
exit($falhas ? 1 : 0);
