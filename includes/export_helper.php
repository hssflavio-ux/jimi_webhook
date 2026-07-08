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
 */
class PdfWriter
{
    public const MAX_ROWS = 20000;

    private const PAGE_W = 841.89;  // A4 paisagem (pt)
    private const PAGE_H = 595.28;
    private const MARGIN = 36.0;
    private const ROW_H  = 13.0;
    private const FONT_SIZE = 7.5;

    private string $filepath;
    private string $title;
    private string $subtitle;
    private array $headers;
    private array $pages = [];      // content streams finalizados
    private string $buf = '';       // content stream da página corrente
    private float $y;
    private int $rowCount = 0;
    private bool $truncated = false;
    private float $colW;

    /**
     * @param string   $filepath Caminho final do .pdf
     * @param string   $title    Título impresso no topo de cada página
     * @param string[] $headers  Cabeçalhos da tabela
     * @param string   $subtitle Linha de contexto (período, gerado em)
     */
    public function __construct(string $filepath, string $title, array $headers, string $subtitle = '')
    {
        $this->filepath = $filepath;
        $this->title    = $title;
        $this->subtitle = $subtitle;
        $this->headers  = array_values($headers);
        $this->colW     = (self::PAGE_W - 2 * self::MARGIN) / max(1, count($this->headers));
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
            $this->rowText(['… relatório truncado em ' . number_format(self::MAX_ROWS, 0, ',', '.') . ' linhas. Exporte em CSV/Excel para o conjunto completo.'], false);
            return;
        }
        if ($this->y < self::MARGIN + self::ROW_H) {
            $this->pages[] = $this->buf;
            $this->startPage();
        }
        $this->rowText($cells, false);
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

        $kids = [];
        $next = 5;
        foreach ($this->pages as $i => $content) {
            $content .= "\nBT /F1 7 Tf 0.45 0.45 0.45 rg " . (self::PAGE_W - self::MARGIN - 60) . ' ' . (self::MARGIN - 16)
                      . " Td (P\xE1gina " . ($i + 1) . ' de ' . count($this->pages) . ") Tj ET";
            $streamId = $next++;
            $pageId   = $next++;
            $objects[$streamId] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
            $objects[$pageId]   = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . self::PAGE_W . ' ' . self::PAGE_H . "] "
                                . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $streamId 0 R >>";
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
     * Desenha uma linha (cabeçalho ou dados) com fundo/linha divisória.
     *
     * @param array $cells  Conteúdo das colunas
     * @param bool  $header true = estilo cabeçalho (negrito sobre azul)
     */
    private function rowText(array $cells, bool $header): void
    {
        $x0 = self::MARGIN;
        $yTop = $this->y;
        $yText = $yTop - self::ROW_H + 3.5;

        if ($header) {
            // Fundo azul Coinbase
            $this->buf .= "0 0.32 1 rg $x0 " . ($yTop - self::ROW_H) . ' '
                        . (self::PAGE_W - 2 * self::MARGIN) . ' ' . self::ROW_H . " re f\n";
        }

        $font = $header ? '/F2' : '/F1';
        $color = $header ? '1 1 1' : '0.13 0.14 0.16';
        $maxChars = (int)floor($this->colW / (self::FONT_SIZE * 0.52)) - 1;

        foreach (array_values($cells) as $i => $v) {
            $txt = (string)($v ?? '');
            if (mb_strlen($txt) > $maxChars) {
                $txt = mb_substr($txt, 0, max(1, $maxChars - 1)) . '…';
            }
            $x = $x0 + $i * $this->colW + 2;
            $this->buf .= "BT $font " . self::FONT_SIZE . " Tf $color rg "
                        . sprintf('%.2f %.2f', $x, $yText) . ' Td (' . self::esc($txt) . ") Tj ET\n";
        }

        // Linha divisória inferior (cinza-claro)
        $this->buf .= "0.87 0.88 0.9 RG 0.5 w $x0 " . sprintf('%.2f', $yTop - self::ROW_H) . ' m '
                    . sprintf('%.2f', self::PAGE_W - self::MARGIN) . ' ' . sprintf('%.2f', $yTop - self::ROW_H) . " l S\n";

        $this->y -= self::ROW_H;
    }

    /** Converte UTF-8 → CP1252 e escapa caracteres reservados do PDF. */
    private static function esc(string $s): string
    {
        $conv = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        if ($conv === false) {
            $conv = mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $conv);
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
 * @param string   $filepath Caminho final do .pdf
 * @param string   $title    Título do relatório
 * @param string   $subtitle Contexto (período/gerado em)
 * @returns bool
 */
function generate_pdf(array $headers, iterable $rows, string $filepath, string $title, string $subtitle = ''): bool
{
    $w = new PdfWriter($filepath, $title, $headers, $subtitle);
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
