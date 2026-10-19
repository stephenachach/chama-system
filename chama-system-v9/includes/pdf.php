<?php
/**
 * includes/pdf.php - tiny, dependency-free PDF writer.
 *
 * Produces A4 documents with the standard Helvetica fonts: text, rules, boxes
 * and tables with automatic page breaks. No Composer, no vendor folder, works
 * on any PHP install (XAMPP included).
 *
 *   $pdf = new SimplePdf('Member Statement', 'Nairobi Traders Chama - 0712 345 678');
 *   $pdf->heading('Grace Wanjiku', 'M-001 - 0722 000 111');
 *   $pdf->table($cols, $rows);
 *   $pdf->stream('statement.pdf');
 */

class SimplePdf
{
    public $pageW = 595.28;   // A4 portrait, in points
    public $pageH = 841.89;
    public $marginL = 42.0;
    public $marginR = 42.0;
    public $marginT = 40.0;
    public $marginB = 52.0;

    private $pages = [];
    private $buf = '';
    private $y = 0.0;
    private $font = 'F1';
    private $size = 10.0;
    private $title = '';
    private $footerLeft = '';
    private $headerLeft = '';
    private $showFooter = true;

    /** Helvetica / Helvetica-Bold advance widths, per 1000 units (ASCII 32-126). */
    private static $W = [
        'F1' => [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,
                 556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
                 1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,
                 667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
                 333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,
                 556,556,333,500,278,556,500,722,500,500,500,334,260,334,584],
        'F2' => [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,
                 556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
                 975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,
                 667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
                 333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,
                 611,611,389,556,333,611,556,778,556,556,500,389,280,389,584],
    ];

    public function __construct($title = 'Document', $footerLeft = '')
    {
        $this->title = (string)$title;
        $this->footerLeft = (string)$footerLeft;
        $this->addPage();
    }

    /* ---------------------------------------------------------------- layout */

    public function contentWidth()
    {
        return $this->pageW - $this->marginL - $this->marginR;
    }

    public function y()
    {
        return $this->y;
    }

    public function setY($y)
    {
        $this->y = (float)$y;
    }

    public function addPage()
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
        }
        $this->buf = '';
        $this->y = $this->marginT;
    }

    /** Start a new page only if the next $height points would not fit. */
    public function need($height)
    {
        if ($this->y + $height > $this->pageH - $this->marginB) {
            $this->addPage();
            return true;
        }
        return false;
    }

    public function gap($points = 8)
    {
        $this->y += (float)$points;
    }

    /* ------------------------------------------------------------------ text */

    public function strWidth($str, $size = null, $bold = null)
    {
        $size = $size === null ? $this->size : (float)$size;
        $fam  = ($bold === null ? $this->font : ($bold ? 'F2' : 'F1'));
        $tbl  = self::$W[$fam] ?? self::$W['F1'];
        $str  = $this->latin((string)$str);
        $w = 0;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $c = ord($str[$i]);
            $w += ($c >= 32 && $c <= 126) ? $tbl[$c - 32] : 556;
        }
        return $w * $size / 1000.0;
    }

    /**
     * Absolute text placement. $x/$y are in points from the top-left corner.
     * opts: size, bold, italic, align (left|center|right), color [r,g,b], width
     */
    public function textAt($x, $y, $str, $opts = [])
    {
        $size  = isset($opts['size']) ? (float)$opts['size'] : $this->size;
        $bold  = !empty($opts['bold']);
        $ital  = !empty($opts['italic']);
        $fam   = $bold ? 'F2' : ($ital ? 'F3' : 'F1');
        $align = $opts['align'] ?? 'left';
        $str   = $this->latin((string)$str);

        if ($align === 'right' && isset($opts['width'])) {
            $x = $x + (float)$opts['width'] - $this->strWidth($str, $size, $bold);
        } elseif ($align === 'center' && isset($opts['width'])) {
            $x = $x + ((float)$opts['width'] - $this->strWidth($str, $size, $bold)) / 2;
        } elseif ($align === 'right') {
            $x = $x - $this->strWidth($str, $size, $bold);
        } elseif ($align === 'center') {
            $x = $x - $this->strWidth($str, $size, $bold) / 2;
        }

        if (!empty($opts['color'])) {
            $c = $opts['color'];
            $this->buf .= sprintf("%.3F %.3F %.3F rg\n", $c[0], $c[1], $c[2]);
        }
        $yp = $this->pageH - $y;
        $this->buf .= sprintf(
            "BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
            $fam, $size, $x, $yp, $this->esc($str)
        );
        $this->buf .= "0 0 0 rg\n";
        return $this;
    }

    /** Flow text at the current cursor; advances the cursor. */
    public function text($str, $opts = [])
    {
        $size = isset($opts['size']) ? (float)$opts['size'] : $this->size;
        $lead = isset($opts['lead']) ? (float)$opts['lead'] : $size * 1.35;
        $x = $opts['x'] ?? $this->marginL;
        if (isset($opts['align']) && in_array($opts['align'], ['center', 'right'], true) && !isset($opts['width'])) {
            $opts['width'] = $this->contentWidth();
        }
        $this->need($lead);
        $this->textAt($x, $this->y + $size, $str, $opts);
        $this->y += $lead;
        return $this;
    }

    /** Word-wrapped flow text. Returns the number of lines drawn. */
    public function paragraph($str, $opts = [])
    {
        $maxW = isset($opts['width']) ? (float)$opts['width'] : $this->contentWidth();
        $size = isset($opts['size']) ? (float)$opts['size'] : $this->size;
        $bold = !empty($opts['bold']);
        $lines = $this->wrap($str, $maxW, $size, $bold);
        foreach ($lines as $line) {
            $this->text($line, $opts);
        }
        return count($lines);
    }

    public function wrap($str, $maxW, $size = null, $bold = null)
    {
        $words = preg_split('/\s+/', trim((string)$str)) ?: [];
        $lines = [];
        $cur = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : $cur . ' ' . $w;
            if ($this->strWidth($try, $size, $bold) > $maxW && $cur !== '') {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines ?: [''];
    }

    /** Truncate with an ellipsis so a cell never overflows its column. */
    public function fit($str, $maxW, $size = null, $bold = null)
    {
        $str = (string)$str;
        if ($this->strWidth($str, $size, $bold) <= $maxW) {
            return $str;
        }
        while (strlen($str) > 1 && $this->strWidth($str . '...', $size, $bold) > $maxW) {
            $str = substr($str, 0, -1);
        }
        return rtrim($str) . '...';
    }

    /* -------------------------------------------------------------- graphics */

    public function lineAt($x1, $y1, $x2, $y2, $width = 0.6, $color = [0.55, 0.58, 0.62])
    {
        $this->buf .= sprintf(
            "%.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S\n",
            $width, $color[0], $color[1], $color[2],
            $x1, $this->pageH - $y1, $x2, $this->pageH - $y2
        );
        $this->buf .= "0 0 0 RG\n";
        return $this;
    }

    public function rule($opts = [])
    {
        $w = isset($opts['width']) ? (float)$opts['width'] : 0.7;
        $x1 = $opts['x'] ?? $this->marginL;
        $x2 = $x1 + (isset($opts['length']) ? (float)$opts['length'] : $this->contentWidth());
        $this->lineAt($x1, $this->y, $x2, $this->y, $w, $opts['color'] ?? [0.55, 0.58, 0.62]);
        $this->y += (float)($opts['gap'] ?? 6);
        return $this;
    }

    public function rect($x, $y, $w, $h, $fill = null, $stroke = null, $lineWidth = 0.6)
    {
        $op = '';
        if ($fill !== null) {
            $op .= sprintf("%.3F %.3F %.3F rg ", $fill[0], $fill[1], $fill[2]);
        }
        if ($stroke !== null) {
            $op .= sprintf("%.3F %.3F %.3F RG %.2F w ", $stroke[0], $stroke[1], $stroke[2], $lineWidth);
        }
        $mode = ($fill !== null && $stroke !== null) ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->buf .= sprintf(
            "%s%.2F %.2F %.2F %.2F re %s\n",
            $op, $x, $this->pageH - $y - $h, $w, $h, $mode
        );
        $this->buf .= "0 0 0 rg 0 0 0 RG\n";
        return $this;
    }

    /* ------------------------------------------------------------ components */

    /** Document title block: big title, subtitle line, right-aligned meta. */
    public function heading($title, $subtitle = '', $meta = [])
    {
        $this->text($title, ['size' => 17, 'bold' => true]);
        if ($subtitle !== '') {
            $this->text($subtitle, ['size' => 10, 'color' => [0.35, 0.38, 0.42]]);
        }
        $my = $this->marginT;
        foreach ($meta as $label => $value) {
            $this->textAt($this->pageW - $this->marginR, $my + 9, $label . ': ', [
                'size' => 8.5, 'align' => 'right', 'color' => [0.45, 0.48, 0.52],
                'width' => $this->strWidth($value, 9.5, true) + $this->strWidth($label . ': ', 8.5),
            ]);
            $this->textAt($this->pageW - $this->marginR, $my + 9, $value, ['size' => 9.5, 'bold' => true, 'align' => 'right']);
            $my += 13;
        }
        if ($this->y < $my + 12) {
            $this->y = $my + 12;
        }
        $this->rule(['width' => 1.1, 'color' => [0.13, 0.35, 0.30], 'gap' => 10]);
        return $this;
    }

    /** Section caption. */
    public function section($label)
    {
        $this->need(34);
        $this->gap(6);
        $this->text(strtoupper($label), ['size' => 9.5, 'bold' => true, 'color' => [0.13, 0.35, 0.30]]);
        $this->gap(2);
        return $this;
    }

    /** Label / value line (used for member details and totals). */
    public function kv($label, $value, $opts = [])
    {
        $size = $opts['size'] ?? 10;
        $this->need($size * 1.6);
        $this->textAt($this->marginL, $this->y + $size, $label, ['size' => $size, 'color' => [0.4, 0.43, 0.47]]);
        $this->textAt($this->marginL + ($opts['labelWidth'] ?? 150), $this->y + $size, (string)$value, [
            'size' => $size, 'bold' => !empty($opts['bold']),
        ]);
        $this->y += $size * 1.5;
        return $this;
    }

    /**
     * Table with a repeating header, zebra rows and automatic page breaks.
     *
     * $cols = [['label'=>'Date','w'=>70,'align'=>'left'], ...]
     * $rows = [['cells'=>['01 Jan','500.00'], 'bold'=>false, 'fill'=>null], ...]
     */
    public function table(array $cols, array $rows, array $opts = [])
    {
        $size    = $opts['size'] ?? 9.5;
        $padY    = $opts['padding'] ?? 5.0;
        $rowH    = $size * 1.3 + $padY * 2;
        $zebra   = $opts['zebra'] ?? true;
        $x0      = $opts['x'] ?? $this->marginL;
        $headBg  = $opts['headerFill'] ?? [0.90, 0.93, 0.92];
        $altBg   = $opts['altFill'] ?? [0.968, 0.976, 0.974];
        $lineCol = [0.80, 0.83, 0.85];

        // Fit the table to the usable page width: never overflow, and by
        // default stretch so every table runs edge to edge of the content.
        $avail = $this->contentWidth() - ($x0 - $this->marginL);
        $sum = 0.0;
        foreach ($cols as $c) {
            $sum += max(1.0, (float)$c['w']);
        }
        $scale = ($opts['stretch'] ?? true) ? $avail / $sum : min(1.0, $avail / $sum);
        if (abs($scale - 1.0) > 0.001) {
            foreach ($cols as &$c) {
                $c['w'] = max(1.0, (float)$c['w']) * $scale;
            }
            unset($c);
        }

        $drawHeader = function () use ($cols, $x0, $size, $rowH, $headBg, $padY) {
            $this->need($rowH + 4);
            $top = $this->y;
            $total = 0.0;
            foreach ($cols as $c) {
                $total += (float)$c['w'];
            }
            $this->rect($x0, $top, $total, $rowH, $headBg);
            $cx = $x0;
            foreach ($cols as $c) {
                $this->textAt($cx + 5, $top + $size + $padY - 1, $this->fit($c['label'] ?? '', (float)$c['w'] - 10, $size, true), [
                    'size' => $size, 'bold' => true, 'align' => $c['align'] ?? 'left', 'width' => (float)$c['w'] - 10,
                ]);
                $cx += (float)$c['w'];
            }
            $this->y = $top + $rowH;
            $this->lineAt($x0, $this->y, $x0 + $total, $this->y, 0.8, [0.13, 0.35, 0.30]);
        };

        if (!$rows) {
            $drawHeader();
            $this->need(30);
            $this->text($opts['empty'] ?? 'Nothing to show for this period.', [
                'size' => $size, 'color' => [0.45, 0.48, 0.52], 'align' => 'center',
            ]);
            $this->gap(4);
            return $this;
        }

        $drawHeader();
        $i = 0;
        $total = 0.0;
        foreach ($cols as $c) {
            $total += (float)$c['w'];
        }
        foreach ($rows as $row) {
            $cells = $row['cells'] ?? $row;
            if ($this->need($rowH)) {
                $drawHeader();
            }
            $top = $this->y;
            if (!empty($row['fill'])) {
                $this->rect($x0, $top, $total, $rowH, $row['fill']);
            } elseif ($zebra && $i % 2 === 1) {
                $this->rect($x0, $top, $total, $rowH, $altBg);
            }
            $cx = $x0;
            foreach ($cols as $k => $c) {
                $val = isset($cells[$k]) ? (string)$cells[$k] : '';
                $bold = !empty($row['bold']) || !empty($c['bold']);
                $this->textAt($cx + 5, $top + $size + $padY - 1, $this->fit($val, (float)$c['w'] - 10, $size, $bold), [
                    'size' => $size, 'bold' => $bold,
                    'align' => $c['align'] ?? 'left', 'width' => (float)$c['w'] - 10,
                    'color' => $row['color'] ?? null,
                ]);
                $cx += (float)$c['w'];
            }
            $this->y = $top + $rowH;
            $this->lineAt($x0, $this->y, $x0 + $total, $this->y, 0.4, $lineCol);
            $i++;
        }
        $this->gap(4);
        return $this;
    }

    /** Right-aligned summary block: [['label'=>'Total contributed','value'=>'KSh 12,500.00'], ...] */
    public function summary(array $items, $opts = [])
    {
        $size = $opts['size'] ?? 10;
        $boxW = $opts['width'] ?? 250.0;
        $x = $this->pageW - $this->marginR - $boxW;
        $rowH = $size * 1.7;
        $h = count($items) * $rowH + 12;
        $this->need($h + 8);
        $top = $this->y;
        $this->rect($x, $top, $boxW, $h, [0.968, 0.976, 0.974], [0.78, 0.83, 0.82], 0.6);
        $yy = $top + 6;
        foreach ($items as $item) {
            $emph = !empty($item['emphasis']);
            $this->textAt($x + 8, $yy + $size, $item['label'], [
                'size' => $size, 'bold' => $emph, 'color' => $emph ? [0.10, 0.12, 0.14] : [0.40, 0.43, 0.47],
            ]);
            $this->textAt($x + $boxW - 8, $yy + $size, (string)$item['value'], [
                'size' => $size + ($emph ? 0.5 : 0), 'bold' => true, 'align' => 'right',
                'color' => $item['color'] ?? [0.10, 0.12, 0.14],
            ]);
            $yy += $rowH;
        }
        $this->y = $top + $h + 8;
        return $this;
    }

    public function setFooter($text)
    {
        $this->footerLeft = (string)$text;
        return $this;
    }

    public function hideFooter()
    {
        $this->showFooter = false;
        return $this;
    }

    /* ---------------------------------------------------------------- output */

    public function render()
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
            $this->buf = '';
        }
        $n = count($this->pages);
        if ($n === 0) {
            $this->pages = [''];
            $n = 1;
        }

        $footH = $this->pageH - $this->marginB + 18;
        for ($p = 0; $p < $n; $p++) {
            if (!$this->showFooter) {
                break;
            }
            $extra = '';
            $extra .= sprintf("%.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S\n",
                0.5, 0.82, 0.85, 0.84, $this->marginL, $this->pageH - $footH,
                $this->pageW - $this->marginR, $this->pageH - $footH);
            if ($this->footerLeft !== '') {
                $extra .= sprintf("BT /F1 8 Tf %.2F %.2F Td (%s) Tj ET\n",
                    $this->marginL, $this->pageH - ($footH + 12), $this->esc($this->latin($this->footerLeft)));
            }
            $extra .= sprintf("BT /F1 8 Tf %.2F %.2F Td (%s) Tj ET\n",
                $this->pageW - $this->marginR - $this->strWidth('Page ' . ($p + 1) . ' of ' . $n, 8),
                $this->pageH - ($footH + 12), 'Page ' . ($p + 1) . ' of ' . $n);
            $extra .= "0 0 0 RG\n";
            $this->pages[$p] .= $extra;
        }

        $total = 6 + 2 * $n;
        $objs = [];
        $streams = [];
        $kids = [];
        for ($i = 0; $i < $n; $i++) {
            $kids[] = (7 + 2 * $i) . ' 0 R';
        }
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d /MediaBox [0 0 %.2F %.2F] >>',
            implode(' ', $kids), $n, $this->pageW, $this->pageH);
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objs[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
        $objs[6] = sprintf('<< /Title (%s) /Producer (Chama System) /Creator (Chama System) /CreationDate (D:%s) >>',
            $this->esc($this->latin($this->title)), date('YmdHis'));

        for ($i = 0; $i < $n; $i++) {
            $pageObj = 7 + 2 * $i;
            $objs[$pageObj] = sprintf('<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents %d 0 R >>',
                $pageObj + 1);
            $streams[$pageObj + 1] = $this->pages[$i];
        }

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        for ($i = 1; $i <= $total; $i++) {
            $offsets[$i] = strlen($pdf);
            if (isset($streams[$i])) {
                $pdf .= $i . " 0 obj\n<< /Length " . strlen($streams[$i]) . " >>\nstream\n" . $streams[$i] . "\nendstream\nendobj\n";
            } else {
                $pdf .= $i . " 0 obj\n" . $objs[$i] . "\nendobj\n";
            }
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . ($total + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $total; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($total + 1) . " /Root 1 0 R /Info 6 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $pdf;
    }

    public function save($path)
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return file_put_contents($path, $this->render()) !== false;
    }

    /** Send as a download (inline by default so browsers can preview it). */
    public function stream($filename, $download = false)
    {
        $data = $this->render();
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename);
        if (!headers_sent()) {
            header('Content-Type: application/pdf');
            header('Content-Length: ' . strlen($data));
            header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=0, must-revalidate');
        }
        echo $data;
        exit;
    }

    /* -------------------------------------------------------------- internal */

    /** Convert to CP1252 (what WinAnsiEncoding expects), dropping what cannot map. */
    private function latin($str)
    {
        $str = (string)$str;
        if (!preg_match('//u', $str)) {          // not valid UTF-8: assume already latin
            return $this->cleanBytes($str);
        }
        $out = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $str);
        if ($out === false) {
            $out = preg_replace('/[^\x20-\x7E]/', '', $str);
        }
        return $this->cleanBytes($out);
    }

    private function cleanBytes($str)
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', (string)$str);
    }

    private function esc($str)
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string)$str);
    }
}

/** Small helpers so pages do not repeat number formatting. */
if (!function_exists('pdf_money')) {
    function pdf_money($amount)
    {
        return 'KSh ' . number_format((float)$amount, 2);
    }
}
if (!function_exists('pdf_date')) {
    function pdf_date($date, $withTime = false)
    {
        if (!$date || $date === '0000-00-00') {
            return '-';
        }
        $ts = strtotime((string)$date);
        if (!$ts) {
            return (string)$date;
        }
        return date($withTime ? 'd M Y H:i' : 'd M Y', $ts);
    }
}
