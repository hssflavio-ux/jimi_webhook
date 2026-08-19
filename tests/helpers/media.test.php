<?php
/**
 * Helpers de mídia — o que a tela usa para decidir se OFERECE o vídeo.
 *
 * Dois defeitos reais de produção, medidos em 18/08/2026, estão travados aqui:
 *
 *  1. **`file_url` com DOIS arquivos.** A câmera JIMI anuncia as duas câmeras
 *     num campo só, separadas por vírgula:
 *     `EVENT_..._I_56.mp4,EVENT_..._F_55.mp4` (I = interna, F = frontal). São
 *     25 dos 106 alarmes com vídeo. `basename()` sobre a string inteira devolve
 *     `..._I_56.mp4,EVENT_..._F_55.mp4`, que não casa com arquivo nenhum — o
 *     par nunca seria encontrado no disco mesmo estando lá.
 *
 *  2. **Ter `file_url` não é ter o vídeo.** O nome é anunciado pela câmera no
 *     push do alarme; o arquivo sobe depois, por outro caminho, e pode não
 *     chegar. Em produção eram 81 de 106 (76%) apontando para arquivo
 *     inexistente, e o relatório oferecia "Ver Vídeo" nos 106.
 *
 * Uso (não precisa de banco):
 *   php tests/helpers/media.test.php
 */

require_once __DIR__ . '/../../includes/media.php';

$falhas = 0;
$total  = 0;

function checa(string $desc, $esperado, $obtido): void {
    global $falhas, $total;
    $total++;
    $ok = ($esperado === $obtido);
    if (!$ok) $falhas++;
    printf("  %s %-58s esperado=%s obtido=%s\n",
        $ok ? 'OK  ' : 'FALHA', $desc,
        var_export($esperado, true), var_export($obtido, true));
}

// Diretório de mídia controlado, para o teste não depender do servidor.
$tmp = sys_get_temp_dir() . '/bc_media_test_' . getmypid();
@mkdir($tmp, 0777, true);
putenv('VIDEO_MEDIA_DIR=' . $tmp);

$PRESENTE = 'EVENT_862798051583785_00000000_2026_08_18_10_46_42_I_15.mp4';
$AUSENTE  = 'EVENT_864993060392306_00000000_2026_08_18_16_16_57_I_14.ts';
file_put_contents($tmp . '/' . $PRESENTE, 'x');

echo "\n── Lista de arquivos ──\n";
checa('um arquivo vira lista de um', [$PRESENTE], media_file_list($PRESENTE));
checa('dois arquivos separados por vírgula', ['a.mp4', 'b.mp4'], media_file_list('a.mp4,b.mp4'));
checa('espaços em volta são descartados', ['a.mp4', 'b.mp4'], media_file_list(' a.mp4 , b.mp4 '));
checa('vazio não vira item', [], media_file_list(''));
checa('null não vira item', [], media_file_list(null));

echo "\n── Escolha do arquivo (media_pick) ──\n";
checa('escolhe o que EXISTE, mesmo em segundo lugar',
      $PRESENTE, media_pick($AUSENTE . ',' . $PRESENTE));
checa('nenhum existe → devolve o primeiro (para a mensagem citar um nome)',
      'sumiu1.ts', media_pick('sumiu1.ts,sumiu2.ts'));
checa('sem arquivo → string vazia', '', media_pick(''));

echo "\n── Disponibilidade (media_available) ──\n";
checa('🔴 arquivo ausente NÃO é oferecido', false, media_available($AUSENTE));
checa('arquivo presente é oferecido', true, media_available($PRESENTE));
checa('🔴 par com ao menos um presente é oferecido',
      true, media_available($AUSENTE . ',' . $PRESENTE));
checa('par com nenhum presente não é oferecido',
      false, media_available('sumiu1.ts,sumiu2.ts'));
checa('campo vazio não é oferecido', false, media_available(''));
checa('URL absoluta: não há disco local para conferir', true, media_available('http://x/y.mp4'));

echo "\n── URL tocável ──\n";
checa('aponta para a NOSSA origem, com o arquivo que existe',
      '/midia?f=' . rawurlencode($PRESENTE), media_play_url($AUSENTE . ',' . $PRESENTE));
checa('🔴 a vírgula nunca entra na URL',
      false, str_contains(media_play_url($AUSENTE . ',' . $PRESENTE), ','));
checa('URL absoluta passa direto', 'http://x/y.mp4', media_play_url('http://x/y.mp4'));

echo "\n── Tipo e formato ──\n";
checa('.ts é vídeo pela extensão, mesmo com file_type NULL',
      'video', media_kind('a.ts', null));
checa('.ts exige o remux (mpegts.js)', true, media_is_ts('a.ts'));
checa('.mp4 não exige remux', false, media_is_ts('a.mp4'));
checa('🔴 o formato sai do arquivo ESCOLHIDO, não da string inteira',
      false, media_is_ts(media_pick($AUSENTE . ',' . $PRESENTE)));

// Sem o diretório não dá para afirmar ausência: melhor um erro visível no
// player do que esconder vídeo que existe (máquina de dev, volume não montado).
putenv('VIDEO_MEDIA_DIR=' . $tmp . '/nao-existe');
echo "\n── Sem diretório de mídia (dev) ──\n";
checa('não afirma ausência quando não dá para conferir', true, media_available($AUSENTE));

@unlink($tmp . '/' . $PRESENTE);
@rmdir($tmp);

printf("\n%s — %d de %d checagens passaram\n",
    $falhas === 0 ? 'TUDO OK' : "FALHOU ({$falhas})", $total - $falhas, $total);
exit($falhas === 0 ? 0 : 1);
