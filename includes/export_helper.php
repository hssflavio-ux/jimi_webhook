<?php
/**
 * JIMI Webhook System — Helper de Exportação v4.1.0
 *
 * Geração de arquivos XLSX, PDF e CSV em PHP puro (sem Composer):
 *   - XLSX: Office Open XML mínimo via ZipArchive (nativo), streaming em disco.
 *   - PDF:  writer PDF 1.4 mínimo (Helvetica core fonts, tabela paginada A4 paisagem).
 *   - CSV:  UTF-8 com BOM + separador ';' (compatível com Excel pt-BR).
 *
 * Decisão de arquitetura: o projeto é "pure PHP, no package manager" (CLAUDE.md).
 * PhpSpreadsheet/DomPDF exigiriam Composer no dev e na produção — optou-se por
 * writers mínimos nativos. Limitações aceitas: 1 aba por XLSX, sem fórmulas;
 * PDF tabular simples com truncamento de células longas.
 */

// REPORT_LOGO_PATH mora em functions.php, o ponto único da marca dos
// relatórios. Requerido aqui e não deixado por conta de quem inclui: os
// chamadores são ~12 handlers mais o worker, e o dia em que um deles não
// tiver functions.php carregado o erro é FATAL no meio da exportação.
require_once __DIR__ . '/functions.php';

/**
 * Célula que é um LINK: rótulo visível + URL escondida atrás dele (v4.8.2).
 *
 * Cada formato resolve do seu jeito, porque as capacidades diferem:
 *   XLSX → `=HYPERLINK("url";"MAPA")`, clicável, mostrando só o rótulo
 *   PDF  → texto do rótulo + anotação /Link cobrindo a célula
 *   CSV  → só o rótulo (CSV não tem hyperlink; ver nota em csv_cell())
 *
 * Antes disso a URL crua ia como texto na célula: enchia a coluna, não era
 * clicável em lugar nenhum, e era exatamente o que se via como "o link do
 * mapa não funciona".
 */
final class ExportLink
{
    public string $url;
    public string $label;

    /**
     * @param string $url   Endereço de destino
     * @param string $label Texto exibido na célula
     */
    public function __construct(string $url, string $label = 'MAPA')
    {
        $this->url   = $url;
        $this->label = $label;
    }

    /** @returns string Rótulo — usado onde não há suporte a link. */
    public function __toString(): string { return $this->label; }
}

/**
 * Célula "Mapa" de um export: link do OpenStreetMap no ponto, ou '—'.
 *
 * O OSM público foi escolhido de propósito para os arquivos exportados: o PDF
 * e a planilha circulam por e-mail e chegam a quem não tem conta no sistema.
 * Um link para uma tela nossa exigiria login e morreria na caixa de entrada.
 *
 * @param mixed  $lat   Latitude (0/null/inválida → '—')
 * @param mixed  $lng   Longitude
 * @param string $label Rótulo visível na célula
 * @returns ExportLink|string
 */
function export_map_link($lat, $lng, string $label = 'MAPA')
{
    if ($lat === null || $lng === null || (float)$lat == 0.0 || (float)$lng == 0.0) {
        return '—';
    }
    return new ExportLink(
        sprintf('https://www.openstreetmap.org/?mlat=%s&mlon=%s&zoom=16', $lat, $lng),
        $label
    );
}

/**
 * Writer XLSX incremental (memória constante — linhas vão para arquivo temporário).
 */
class XlsxWriter
{
    private string $filepath;
    private string $tmpSheet;
    /** @var resource */
    private $fp;
    private int $rowNum = 0;
    private array $colWidths = [];

    /**
     * @param string $filepath Caminho final do .xlsx
     * @throws RuntimeException se não conseguir criar o arquivo temporário
     */
    public function __construct(string $filepath)
    {
        $this->filepath = $filepath;
        $this->tmpSheet = tempnam(sys_get_temp_dir(), 'jimi_xlsx_');
        $fp = fopen($this->tmpSheet, 'w');
        if (!$fp) {
            throw new RuntimeException('Não foi possível criar arquivo temporário para XLSX');
        }
        $this->fp = $fp;
    }

    /**
     * Escreve a linha de cabeçalho (estilo: negrito, fundo azul).
     * Deve ser chamada uma única vez, antes de writeRow().
     *
     * @param string[] $headers
     */
    public function writeHeader(array $headers): void
    {
        foreach ($headers as $i => $h) {
            $this->colWidths[$i] = max(12, min(40, mb_strlen((string)$h) + 4));
        }
        $this->rowNum++;
        $cells = '';
        foreach (array_values($headers) as $i => $h) {
            $ref = self::cellRef($i, $this->rowNum);
            $cells .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t xml:space="preserve">'
                    . self::xml((string)$h) . '</t></is></c>';
        }
        fwrite($this->fp, '<row r="' . $this->rowNum . '">' . $cells . '</row>');
    }

    /**
     * Escreve uma linha de dados. Valores numéricos curtos viram células
     * numéricas; IMEIs e demais strings viram inline strings (preserva dígitos).
     *
     * @param array $cells Valores escalares (na ordem das colunas)
     */
    public function writeRow(array $cells): void
    {
        $this->rowNum++;
        $out = '';
        foreach (array_values($cells) as $i => $v) {
            $ref = self::cellRef($i, $this->rowNum);

            // Célula de LINK (v4.8.2): vira =HYPERLINK("url";"rótulo"), que o
            // Excel mostra como o rótulo e abre ao clique. Antes a URL crua ia
            // como texto — poluía a coluna e não era clicável, que é o que o
            // usuário via como "o link não funciona".
            //
            // O <v> ao lado da fórmula é o VALOR EM CACHE, e não é opcional
            // (v4.9.0). A fórmula sozinha só vira texto depois que o programa
            // recalcula a planilha; até lá o leitor mostra a célula **vazia** —
            // era exatamente essa a coluna "Mapa" em branco no Excel, enquanto
            // no PDF (que não depende de recálculo) o link aparecia. Quem abre
            // em visualizador que não recalcula nada — Google Sheets no
            // preview, Numbers, o painel do Windows — via a coluna sumir de vez.
            if ($v instanceof ExportLink) {
                // Aspas duplas dobram dentro de string de fórmula do Excel
                $u = str_replace('"', '""', $v->url);
                $t = str_replace('"', '""', $v->label);
                $out .= '<c r="' . $ref . '" t="str"><f>'
                      . self::xml('HYPERLINK("' . $u . '","' . $t . '")')
                      . '</f><v>' . self::xml($v->label) . '</v></c>';
                continue;
            }

            $s = (string)($v ?? '');
            // Números "de verdade" (evita perder precisão de IMEI com 15+ dígitos)
            if ($s !== '' && is_numeric($s) && strlen($s) < 12 && !preg_match('/^0\d/', $s)) {
                $out .= '<c r="' . $ref . '"><v>' . $s . '</v></c>';
            } elseif ($s !== '') {
                $out .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                      . self::xml($s) . '</t></is></c>';
            }
        }
        fwrite($this->fp, '<row r="' . $this->rowNum . '">' . $out . '</row>');
    }

    /**
     * Monta o pacote .xlsx (zip) e remove o temporário.
     *
     * @returns bool true em sucesso
     */
    public function close(): bool
    {
        fclose($this->fp);

        // Envelope da worksheet: <cols> precisa vir antes de <sheetData>
        $cols = '';
        if ($this->colWidths) {
            $cols = '<cols>';
            foreach ($this->colWidths as $i => $w) {
                $n = $i + 1;
                $cols .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
            }
            $cols .= '</cols>';
        }
        $sheetHead = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . $cols . '<sheetData>';
        $sheetFoot = '</sheetData></worksheet>';

        $finalSheet = tempnam(sys_get_temp_dir(), 'jimi_xlsx_');
        $out = fopen($finalSheet, 'w');
        if (!$out) { @unlink($this->tmpSheet); return false; }
        fwrite($out, $sheetHead);
        $in = fopen($this->tmpSheet, 'r');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fwrite($out, $sheetFoot);
        fclose($out);
        @unlink($this->tmpSheet);

        $zip = new ZipArchive();
        if ($zip->open($this->filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($finalSheet);
            return false;
        }
        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Relatório" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Estilo 1 = cabeçalho: negrito branco sobre azul Coinbase #0052ff
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF0052FF"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
            . '</styleSheet>');
        $zip->addFile($finalSheet, 'xl/worksheets/sheet1.xml');
        $ok = $zip->close();
        @unlink($finalSheet);
        return $ok;
    }

    /**
     * @param int $col Índice 0-based da coluna
     * @param int $row Linha 1-based
     * @returns string Referência A1
     */
    private static function cellRef(int $col, int $row): string
    {
        $letters = '';
        $n = $col;
        do {
            $letters = chr(65 + ($n % 26)) . $letters;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);
        return $letters . $row;
    }

    private static function xml(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Writer PDF 1.4 mínimo — tabela paginada em A4 paisagem com fontes core
 * (Helvetica / Helvetica-Bold, WinAnsiEncoding). Sem dependências.
 *
 * Limite de segurança: MAX_ROWS linhas; acima disso o relatório é truncado
 * com aviso na última linha (PDF não é o formato adequado para dumps grandes).
 *
 * Larguras de coluna e quebra de linha (v4.8.3). Até a v4.8.2 as colunas eram
 * TODAS da mesma largura e a célula que não coubesse era cortada com "…". Numa
 * grade de oito colunas isso dá ~96 pt por coluna, e o endereço geocodificado
 * — o campo mais longo de todos os relatórios — saía sempre pela metade
 * ("Avenida Presidente Juscelino Kub…"). Agora cada relatório declara PESOS de
 * coluna (o endereço leva 3-4x o de uma coluna comum) e o texto que ainda não
 * couber QUEBRA em até MAX_LINES linhas, em vez de ser cortado.
 */
class PdfWriter
{
    public const MAX_ROWS = 20000;

    private const PAGE_W = 841.89;  // A4 paisagem (pt)
    private const PAGE_H = 595.28;
    private const MARGIN = 36.0;
    private const LINE_H = 9.0;     // altura de UMA linha de texto
    private const ROW_PAD = 4.0;    // respiro vertical da célula
    private const ROW_H  = 13.0;    // linha de conteúdo simples (LINE_H + ROW_PAD)
    private const FONT_SIZE = 7.5;
    /** Teto de quebras por célula — acima disso a última linha recebe "…". */
    private const MAX_LINES = 4;

    private string $filepath;
    private string $title;
    private string $subtitle;

    /** Anotações /Link acumuladas: ['page'=>idx, 'rect'=>[x0,y0,x1,y1], 'uri'=>...]. */
    private array $annots = [];

    /** JPEG do logo pronto para embutir (null = sem logo). */
    private static ?string $logoJpeg = null;
    private static int $logoW = 0;
    private static int $logoH = 0;
    private static bool $logoTried = false;

    /**
     * Prepara o logo como JPEG para embutir no PDF.
     *
     * ⚠️ Converte para **JPEG** de propósito, em vez de embutir o PNG. Num PDF,
     * JPEG entra como stream `/DCTDecode` — os bytes vão direto, sem
     * recodificação. PNG exigiria decodificar, remontar como `/FlateDecode` e
     * ainda tratar o canal alfa com `/SMask`, o que multiplicaria o tamanho
     * deste writer artesanal por ganho nenhum num logo de cabeçalho.
     *
     * O asset é o `logo-report.png` (arte sem o descritor, fundo branco
     * sólido), separado do lockup do login: aqui a marca sai a 26 pt no canto
     * do cabeçalho, tamanho em que o descritor não se leria de qualquer forma.
     *
     * Achata sobre BRANCO antes de codificar. Com este asset opaco o achatamento
     * é inócuo — fica como guarda porque JPEG não tem canal alfa: no dia em que
     * a arte for trocada por uma com transparência, sem isto o fundo sairia
     * PRETO no PDF, e a falha só apareceria no documento gerado.
     *
     * Redimensiona para 360 px porque o original tem ~1780 px e ocupa ~100 pt no
     * PDF; embutir o arquivo cheio inflaria cada relatório em centenas de KB.
     *
     * Falha em silêncio se faltar GD ou o arquivo: perder o logo é aceitável,
     * derrubar a exportação não é.
     *
     * @returns void
     */
    private static function prepareLogo(): void
    {
        if (self::$logoTried) return;
        self::$logoTried = true;

        $path = REPORT_LOGO_PATH;
        if (!is_file($path) || !function_exists('imagecreatefrompng')) return;

        try {
            $src = @imagecreatefrompng($path);
            if (!$src) return;

            $w = imagesx($src);
            $h = imagesy($src);
            $tw = 360;
            $th = max(1, (int)round($h * $tw / max(1, $w)));

            $dst = imagecreatetruecolor($tw, $th);
            imagefilledrectangle($dst, 0, 0, $tw, $th, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);

            ob_start();
            imagejpeg($dst, null, 88);
            $jpg = (string)ob_get_clean();

            imagedestroy($src);
            imagedestroy($dst);

            if ($jpg !== '') {
                self::$logoJpeg = $jpg;
                self::$logoW = $tw;
                self::$logoH = $th;
            }
        } catch (\Throwable $e) {
            self::$logoJpeg = null;
        }
    }
    private array $headers;
    private array $pages = [];      // content streams finalizados
    private string $buf = '';       // content stream da página corrente
    private float $y;
    private int $rowCount = 0;
    private bool $truncated = false;
    /** @var float[] Largura de cada coluna, em pt. */
    private array $colW = [];
    /** @var float[] Borda esquerda de cada coluna, em pt. */
    private array $colX = [];

    /**
     * @param string   $filepath   Caminho final do .pdf
     * @param string   $title      Título impresso no topo de cada página
     * @param string[] $headers    Cabeçalhos da tabela
     * @param string   $subtitle   Linha de contexto (período, gerado em)
     * @param float[]  $colWeights Peso relativo de cada coluna (vazio = todas iguais).
     *                             Ex.: [1, 1, 1.4, 3.6, …] dá à 4ª coluna 3,6x a
     *                             largura da 1ª. Colunas não declaradas valem 1.
     */
    public function __construct(string $filepath, string $title, array $headers, string $subtitle = '', array $colWeights = [])
    {
        $this->filepath = $filepath;
        $this->title    = $title;
        $this->subtitle = $subtitle;
        $this->headers  = array_values($headers);

        $n      = max(1, count($this->headers));
        $avail  = self::PAGE_W - 2 * self::MARGIN;
        $pesos  = [];
        for ($i = 0; $i < $n; $i++) {
            $p = (float)($colWeights[$i] ?? 1.0);
            $pesos[$i] = $p > 0 ? $p : 1.0;
        }
        $soma = array_sum($pesos);
        $x = self::MARGIN;
        foreach ($pesos as $i => $p) {
            $this->colW[$i] = $avail * $p / $soma;
            $this->colX[$i] = $x;
            $x += $this->colW[$i];
        }

        $this->startPage();
    }

    /** @returns bool true quando o limite de linhas foi atingido */
    public function isFull(): bool
    {
        return $this->truncated;
    }

    /**
     * Escreve uma linha da tabela (pagina automaticamente).
     *
     * @param array $cells Valores escalares na ordem das colunas
     */
    public function writeRow(array $cells): void
    {
        if ($this->truncated) return;
        if (++$this->rowCount > self::MAX_ROWS) {
            $this->truncated = true;
            $this->rowText(['… relatório truncado em ' . number_format(self::MAX_ROWS, 0, ',', '.') . ' linhas. Exporte em CSV/Excel para o conjunto completo.'], false, true);
            return;
        }
        // A altura da linha agora VARIA (endereço pode ocupar 2-4 linhas), então
        // a quebra de página precisa medir antes de decidir — o teste antigo
        // contra a altura fixa deixaria a última linha vazar a margem.
        $lay = $this->layout($cells, false, false);
        if ($this->y - $lay['h'] < self::MARGIN) {
            $this->pages[] = $this->buf;
            $this->startPage();
        }
        $this->rowText($cells, false, false, $lay);
    }

    /**
     * Finaliza e grava o PDF.
     *
     * @returns bool true em sucesso
     */
    public function close(): bool
    {
        $this->pages[] = $this->buf;

        $objects = [];
        $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
        // obj 2 (Pages) montado após conhecermos os ids das páginas
        $objects[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
        $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

        // XObject do logo (v4.8.0). Ocupa o id 5 quando existe, e as páginas
        // passam a partir do 6 — por isso $next depende dele.
        $imgId = null;
        $next  = 5;
        if (self::$logoJpeg !== null) {
            $imgId = $next++;
            $objects[$imgId] = "<< /Type /XObject /Subtype /Image /Width " . self::$logoW
                             . " /Height " . self::$logoH
                             . " /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode"
                             . " /Length " . strlen(self::$logoJpeg) . " >>\nstream\n"
                             . self::$logoJpeg . "\nendstream";
        }
        $resImg = $imgId !== null ? " /XObject << /Im1 $imgId 0 R >>" : '';

        $kids = [];
        foreach ($this->pages as $i => $content) {
            $content .= "\nBT /F1 7 Tf 0.45 0.45 0.45 rg " . (self::PAGE_W - self::MARGIN - 60) . ' ' . (self::MARGIN - 16)
                      . " Td (P\xE1gina " . ($i + 1) . ' de ' . count($this->pages) . ") Tj ET";
            $streamId = $next++;
            $pageId   = $next++;
            $objects[$streamId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";

            // Anotações /Link desta página (v4.8.2). Cada uma é um objeto
            // próprio; a página as referencia em /Annots. Sem isto o "MAPA"
            // azul seria enfeite: o PDF não deriva link de texto sublinhado.
            $annotRefs = [];
            foreach ($this->annots as $a) {
                if ($a['page'] !== $i) continue;
                $aid = $next++;
                $objects[$aid] = sprintf(
                    "<< /Type /Annot /Subtype /Link /Rect [%.2f %.2f %.2f %.2f] /Border [0 0 0] "
                    . "/A << /Type /Action /S /URI /URI (%s) >> >>",
                    $a['rect'][0], $a['rect'][1], $a['rect'][2], $a['rect'][3],
                    self::esc($a['uri'])
                );
                $annotRefs[] = "$aid 0 R";
            }
            $annots = $annotRefs ? ' /Annots [' . implode(' ', $annotRefs) . ']' : '';

            $objects[$pageId]   = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PAGE_W . ' ' . self::PAGE_H . "] "
                                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >>$resImg >>$annots /Contents $streamId 0 R >>";
            $kids[] = "$pageId 0 R";
        }
        $objects[2] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($kids) . " >>";

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxId; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n$xrefPos\n%%EOF";

        return file_put_contents($this->filepath, $pdf) !== false;
    }

    /** Abre nova página: título, subtítulo e linha de cabeçalho da tabela. */
    private function startPage(): void
    {
        $this->buf = '';
        $this->y = self::PAGE_H - self::MARGIN;

        // Marca no topo direito (v4.8.0), na mesma linha do título. Só entra se
        // o JPEG do logo foi preparado; sem ele o cabeçalho segue como era.
        self::prepareLogo();
        if (self::$logoJpeg !== null) {
            // 26 pt numa página A4 paisagem (842 pt de largura) dá ~96 pt de
            // marca — legível ao lado de um título de 12 pt sem competir com
            // ele. Ficou em 18 pt enquanto o asset ainda tinha a moldura
            // transparente em volta, quando a arte ocupava 26% da altura e a
            // marca saía como um risco no canto.
            $h = 26.0;
            $w = $h * (self::$logoW / max(1, self::$logoH));
            $x = self::PAGE_W - self::MARGIN - $w;
            // `cm` é a matriz de posicionamento: a escala É o tamanho final.
            // q/Q isolam a transformação para não afetar o texto seguinte.
            $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q\n",
                                  $w, $h, $x, $this->y - $h);
        }

        // Título (Helvetica-Bold 12) + subtítulo (Helvetica 8, cinza)
        $this->buf .= "BT /F2 12 Tf 0.04 0.04 0.05 rg " . self::MARGIN . ' ' . ($this->y - 12)
                    . ' Td (' . self::esc($this->title) . ") Tj ET\n";
        if ($this->subtitle !== '') {
            $this->buf .= "BT /F1 8 Tf 0.36 0.38 0.43 rg " . self::MARGIN . ' ' . ($this->y - 26)
                        . ' Td (' . self::esc($this->subtitle) . ") Tj ET\n";
        }
        $this->y -= 44;
        $this->rowText($this->headers, true);
    }

    /**
     * Quebra as células de uma linha na largura de cada coluna e devolve o
     * traçado pronto — as linhas de texto e a altura total.
     *
     * Separado de rowText() porque a quebra de página precisa da ALTURA antes
     * de a linha ser desenhada, e refazer a quebra duas vezes por linha custa
     * caro num relatório de 10 mil linhas.
     *
     * @param array $cells   Conteúdo das colunas
     * @param bool  $header  true = fonte negrito (métrica um pouco mais larga)
     * @param bool  $spanAll true = célula única ocupando a página inteira
     * @returns array{lines: array<int, string[]>, h: float}
     */
    private function layout(array $cells, bool $header, bool $spanAll): array
    {
        $ultima = count($this->colW) - 1;
        $lines = [];
        $n = 1;
        foreach (array_values($cells) as $i => $v) {
            $txt  = self::toCp1252($v instanceof ExportLink ? $v->label : (string)($v ?? ''));
            $larg = $spanAll
                ? self::PAGE_W - 2 * self::MARGIN
                : (float)($this->colW[$i] ?? $this->colW[$ultima] ?? self::PAGE_W);
            $lines[$i] = self::wrapText($txt, $larg - 4.0, $header);
            $n = max($n, count($lines[$i]));
        }
        return ['lines' => $lines, 'h' => $n * self::LINE_H + self::ROW_PAD];
    }

    /**
     * Desenha uma linha (cabeçalho ou dados) com fundo/linha divisória.
     *
     * @param array      $cells   Conteúdo das colunas
     * @param bool       $header  true = estilo cabeçalho (negrito sobre azul)
     * @param bool       $spanAll true = célula única ocupando a página inteira
     * @param array|null $lay     Traçado já calculado por layout() (evita refazê-lo)
     */
    private function rowText(array $cells, bool $header, bool $spanAll = false, ?array $lay = null): void
    {
        $vals = array_values($cells);
        $lay  = $lay ?? $this->layout($cells, $header, $spanAll);
        $rowH = $lay['h'];

        $x0 = self::MARGIN;
        $yTop = $this->y;

        if ($header) {
            // Fundo azul Coinbase
            $this->buf .= "0 0.32 1 rg $x0 " . sprintf('%.2f', $yTop - $rowH) . ' '
                        . (self::PAGE_W - 2 * self::MARGIN) . ' ' . sprintf('%.2f', $rowH) . " re f\n";
        }

        $font = $header ? '/F2' : '/F1';
        $color = $header ? '1 1 1' : '0.13 0.14 0.16';

        foreach ($lay['lines'] as $i => $linhas) {
            $ehLink = ($vals[$i] ?? null) instanceof ExportLink;
            // Link: azul + sublinhado, e uma anotação /Link cobrindo a célula.
            // Sem a anotação o PDF teria um texto azul decorativo que não abre
            // nada — pior do que texto normal, porque promete clique.
            $cor = $ehLink ? '0 0.32 1' : $color;
            $x = ($spanAll ? self::MARGIN : (float)($this->colX[$i] ?? self::MARGIN)) + 2.0;

            foreach ($linhas as $k => $ln) {
                if ($ln === '') continue;
                $yText = $yTop - ($k + 1) * self::LINE_H - 1.0;
                $this->buf .= "BT $font " . self::FONT_SIZE . " Tf $cor rg "
                            . sprintf('%.2f %.2f', $x, $yText) . ' Td (' . self::escRaw($ln) . ") Tj ET\n";

                if ($ehLink && $k === 0) {
                    $larg = self::textWidth($ln, false);
                    $this->buf .= sprintf("%s RG 0.4 w %.2f %.2f m %.2f %.2f l S\n",
                        $cor, $x, $yText - 1.2, $x + $larg, $yText - 1.2);
                    // Guardada por PÁGINA: a anotação vive no objeto da página, e o
                    // buffer atual ainda não sabe em qual página vai parar.
                    $this->annots[] = [
                        'page' => count($this->pages),   // índice da página em construção
                        'rect' => [$x - 1, $yText - 2.5, $x + $larg + 1, $yText + self::FONT_SIZE],
                        'uri'  => $vals[$i]->url,
                    ];
                }
            }
        }

        // Linha divisória inferior (cinza-claro)
        $this->buf .= "0.87 0.88 0.9 RG 0.5 w $x0 " . sprintf('%.2f', $yTop - $rowH) . ' m '
                    . sprintf('%.2f', self::PAGE_W - self::MARGIN) . ' ' . sprintf('%.2f', $yTop - $rowH) . " l S\n";

        $this->y -= $rowH;
    }

    /**
     * Quebra um texto (já em CP1252) para caber numa largura, em até
     * MAX_LINES linhas; o que passar disso vira "…" na última.
     *
     * @param string $cp    Texto em CP1252 (1 byte = 1 caractere)
     * @param float  $maxW  Largura útil da célula, em pt
     * @param bool   $bold  true = medir com a métrica do Helvetica-Bold
     * @returns string[] Linhas (sempre ao menos uma)
     */
    private static function wrapText(string $cp, float $maxW, bool $bold): array
    {
        if ($cp === '' || $maxW <= 0) return [$cp];
        if (self::textWidth($cp, $bold) <= $maxW) return [$cp];

        $out = [];
        $cur = '';
        foreach (explode(' ', $cp) as $palavra) {
            $tenta = $cur === '' ? $palavra : "$cur $palavra";
            if (self::textWidth($tenta, $bold) <= $maxW) { $cur = $tenta; continue; }

            if ($cur !== '') { $out[] = $cur; $cur = ''; }
            // Palavra sozinha maior que a coluna (URL, log): parte no caractere
            while ($palavra !== '' && self::textWidth($palavra, $bold) > $maxW) {
                $corte = 1;
                while ($corte < strlen($palavra)
                       && self::textWidth(substr($palavra, 0, $corte + 1), $bold) <= $maxW) {
                    $corte++;
                }
                $out[] = substr($palavra, 0, $corte);
                $palavra = substr($palavra, $corte);
            }
            $cur = $palavra;
        }
        if ($cur !== '') $out[] = $cur;

        if (count($out) > self::MAX_LINES) {
            $out = array_slice($out, 0, self::MAX_LINES);
            $out[self::MAX_LINES - 1] = self::ellipsize($out[self::MAX_LINES - 1], $maxW, $bold);
        }
        return $out ?: [''];
    }

    /**
     * Encurta um texto CP1252 até caber com "…" no fim.
     *
     * @param string $cp   Texto em CP1252
     * @param float  $maxW Largura útil, em pt
     * @param bool   $bold Métrica negrito
     * @returns string
     */
    private static function ellipsize(string $cp, float $maxW, bool $bold): string
    {
        $ell = self::toCp1252('…');
        while ($cp !== '' && self::textWidth($cp . $ell, $bold) > $maxW) {
            $cp = substr($cp, 0, -1);
        }
        return $cp . $ell;
    }

    /**
     * Largura de um texto CP1252 na FONT_SIZE corrente, em pt.
     *
     * Usa as métricas reais do Helvetica (AFM, unidades de 1/1000 em). A conta
     * antiga era "nº de caracteres × 0,52 em", que erra por mais de 20% em
     * texto com muitos "i"/"l" (222) ou muitos "M"/"W" (833/944) — o bastante
     * para o endereço vazar a coluna vizinha ou ser cortado cedo demais.
     *
     * @param string $cp   Texto em CP1252
     * @param bool   $bold true = Helvetica-Bold (≈8% mais largo, na média)
     * @returns float Largura em pt
     */
    private static function textWidth(string $cp, bool $bold): float
    {
        $w = self::widths();
        $u = 0;
        $len = strlen($cp);
        for ($i = 0; $i < $len; $i++) {
            $u += $w[ord($cp[$i])];
        }
        return $u / 1000.0 * self::FONT_SIZE * ($bold ? 1.08 : 1.0);
    }

    /** @var int[]|null Largura por byte CP1252, em 1/1000 em. */
    private static ?array $wTable = null;

    /**
     * Tabela de larguras do Helvetica, indexada pelo byte CP1252.
     *
     * @returns int[] 256 posições (556 = largura média, usada no que não for mapeado)
     */
    private static function widths(): array
    {
        if (self::$wTable !== null) return self::$wTable;

        $w = array_fill(0, 256, 556);
        // ASCII imprimível, 32..127 (AFM do Helvetica)
        $ascii = [
            278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278, // 32..47   ! " # $ % & ' ( ) * + , - . /
            556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556, // 48..63   0-9 : ; < = > ?
            1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778, // 64..79  @ A-O
            667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,  // 80..95  P-Z [ \ ] ^ _
            333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,  // 96..111 ` a-o
            556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,  // 112..127 p-z { | } ~
        ];
        foreach ($ascii as $i => $v) $w[32 + $i] = $v;

        // WinAnsi: pontuação tipográfica que de fato aparece nos relatórios
        $w[133] = 1000; $w[145] = 222; $w[146] = 222; $w[147] = 333;
        $w[148] = 333;  $w[150] = 556; $w[151] = 1000; $w[160] = 278;

        // Latin-1 acentuado — em Helvetica a letra acentuada tem a largura da base
        foreach ([192,193,194,195,196,197] as $c) $w[$c] = 667;   // À Á Â Ã Ä Å
        $w[198] = 1000; $w[199] = 722;                             // Æ Ç
        foreach ([200,201,202,203] as $c) $w[$c] = 667;            // È É Ê Ë
        foreach ([204,205,206,207] as $c) $w[$c] = 278;            // Ì Í Î Ï
        $w[209] = 722; $w[221] = 667;                              // Ñ Ý
        foreach ([210,211,212,213,214] as $c) $w[$c] = 778;        // Ò Ó Ô Õ Ö
        foreach ([217,218,219,220] as $c) $w[$c] = 722;            // Ù Ú Û Ü
        foreach ([224,225,226,227,228,229] as $c) $w[$c] = 556;    // à á â ã ä å
        $w[230] = 889; $w[231] = 500;                              // æ ç
        foreach ([232,233,234,235] as $c) $w[$c] = 556;            // è é ê ë
        foreach ([236,237,238,239] as $c) $w[$c] = 278;            // ì í î ï
        $w[241] = 556;                                             // ñ
        foreach ([242,243,244,245,246] as $c) $w[$c] = 556;        // ò ó ô õ ö
        foreach ([249,250,251,252] as $c) $w[$c] = 556;            // ù ú û ü
        $w[253] = 500; $w[255] = 500;                              // ý ÿ
        $w[170] = 370; $w[176] = 400; $w[186] = 365;               // ª ° º

        return self::$wTable = $w;
    }

    /** Converte UTF-8 → CP1252 (a codificação das fontes core do PDF). */
    private static function toCp1252(string $s): string
    {
        $conv = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        if ($conv === false) {
            $conv = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        }
        return $conv;
    }

    /** Escapa os caracteres reservados de uma string literal do PDF. */
    private static function escRaw(string $cp): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $cp);
    }

    /** Converte UTF-8 → CP1252 e escapa caracteres reservados do PDF. */
    private static function esc(string $s): string
    {
        return self::escRaw(self::toCp1252($s));
    }
}

/**
 * Gera um XLSX a partir de headers + linhas.
 *
 * @param string[] $headers
 * @param iterable $rows     Arrays de valores escalares
 * @param string   $filepath Caminho final do .xlsx
 * @returns bool
 */
function generate_xlsx(array $headers, iterable $rows, string $filepath): bool
{
    try {
        $w = new XlsxWriter($filepath);
        $w->writeHeader($headers);
        foreach ($rows as $row) {
            $w->writeRow($row);
        }
        return $w->close();
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Gera um PDF tabular a partir de headers + linhas.
 *
 * @param string[] $headers
 * @param iterable $rows
 * @param string   $filepath   Caminho final do .pdf
 * @param string   $title      Título do relatório
 * @param string   $subtitle   Contexto (período/gerado em)
 * @param float[]  $colWeights Peso relativo das colunas (vazio = todas iguais)
 * @returns bool
 */
function generate_pdf(array $headers, iterable $rows, string $filepath, string $title, string $subtitle = '', array $colWeights = []): bool
{
    $w = new PdfWriter($filepath, $title, $headers, $subtitle, $colWeights);
    foreach ($rows as $row) {
        $w->writeRow($row);
        if ($w->isFull()) break;
    }
    return $w->close();
}

/**
 * MIME type de cada formato de exportação suportado.
 *
 * @param string $format csv|xlsx|pdf
 * @returns string
 */
function export_mime_type(string $format): string
{
    return match ($format) {
        'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf'   => 'application/pdf',
        default => 'text/csv; charset=utf-8',
    };
}

/**
 * Limite de linhas do export síncrono dos relatórios (acima disso, usar a fila /exportar).
 */
const SYNC_EXPORT_MAX_ROWS = 10000;

/**
 * Gera um export síncrono (xlsx|pdf|csv) e envia ao browser, encerrando a request.
 *
 * Usado pelos botões "Exportar Excel/PDF" dos relatórios (padrão YUV §9.2):
 * a MESMA query da grade (sem paginação) alimenta o arquivo. O chamador deve
 * limitar as linhas a SYNC_EXPORT_MAX_ROWS.
 *
 * @param string   $format   xlsx|pdf|csv
 * @param string   $basename Nome-base do arquivo (sem extensão)
 * @param string[] $headers  Cabeçalhos das colunas
 * @param iterable $rows     Linhas (arrays de escalares, mesma ordem dos headers)
 * @param string   $title      Título (PDF)
 * @param string   $subtitle   Subtítulo/contexto (PDF)
 * @param float[]  $colWeights Peso relativo das colunas no PDF (vazio = iguais).
 *                             Só o PDF usa: XLSX e CSV têm largura própria.
 * @returns void  Nunca retorna — emite o arquivo e dá exit
 */
function stream_export(string $format, string $basename, array $headers, iterable $rows, string $title = '', string $subtitle = '', array $colWeights = []): void
{
    $format = in_array($format, ['xlsx', 'pdf', 'csv'], true) ? $format : 'csv';
    $safe   = preg_replace('/[^A-Za-z0-9_\-]/', '_', $basename) ?: 'relatorio';
    $file   = $safe . '_' . date('Ymd_His') . '.' . $format;

    if (ob_get_level()) ob_end_clean();

    if ($format === 'csv') {
        header('Content-Type: ' . export_mime_type('csv'));
        header('Content-Disposition: attachment; filename="' . $file . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 (Excel pt-BR)
        fputcsv($out, $headers, ';');
        foreach ($rows as $row) {
            // CSV não tem hyperlink: a célula de link vira a URL, de propósito.
            // Escrever só "MAPA" aqui deixaria a coluna inútil — em XLSX e PDF
            // o rótulo esconde o endereço porque ali ele fica CLICÁVEL; num
            // arquivo de texto puro, esconder seria perder o dado.
            foreach ($row as $k => $v) {
                if ($v instanceof ExportLink) $row[$k] = $v->url;
            }
            fputcsv($out, $row, ';');
        }
        fclose($out);
        exit;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'exp_');
    $ok  = $format === 'xlsx'
        ? generate_xlsx($headers, $rows, $tmp)
        : generate_pdf($headers, $rows, $tmp, $title ?: $safe, $subtitle, $colWeights);

    if (!$ok || !is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Falha ao gerar o arquivo de exportação.';
        exit;
    }

    header('Content-Type: ' . export_mime_type($format));
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}
