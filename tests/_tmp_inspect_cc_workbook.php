<?php
// Temporary inspection script - safe to delete.
// Usage: php tests/_tmp_inspect_cc_workbook.php [file]
ini_set('memory_limit', '1024M');
error_reporting(E_ALL & ~E_DEPRECATED);
$t0 = microtime(true);

require __DIR__ . '/../app/ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;

class CapFilter implements IReadFilter
{
    public function __construct(private int $maxRow = 6000, private int $maxCol = 40) {}
    public function readCell($columnAddress, $row, $worksheetName = '')
    {
        if ($row > $this->maxRow) return false;
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= $this->maxCol;
    }
}

$file = $argv[1] ?? __DIR__ . '/_tmp_fixtures/Content_Calender_2026.xlsx';
if (!is_file($file)) { fwrite(STDERR, "Missing $file\n"); exit(1); }

function trunc($v, int $n = 80): string
{
    if ($v instanceof \DateTimeInterface) $v = $v->format('c');
    $s = is_scalar($v) || $v === null ? (string)$v : json_encode($v);
    $s = str_replace(["\r", "\n", "\t"], ['', '\\n', ' '], $s);
    return mb_strlen($s) > $n ? mb_substr($s, 0, $n) . '…' : $s;
}

/** raw value + (if numeric in date-ish range) a date hint */
function cellDisplay($cell): string
{
    $v = $cell->getValue();
    if ($v === null || $v === '') return '';
    $out = trunc($v);
    if (is_numeric($v) && $v > 30000 && $v < 60000) {
        $fmt = $cell->getStyle()->getNumberFormat()->getFormatCode();
        if (XlDate::isDateTime($cell)) {
            $out .= ' {date:' . XlDate::excelToDateTimeObject($v)->format('Y-m-d') . ' fmt=' . $fmt . '}';
        }
    }
    return $out;
}

$reader = IOFactory::createReader('Xlsx');
$names = $reader->listWorksheetNames($file);
$infos = $reader->listWorksheetInfo($file);
$infoBy = [];
foreach ($infos as $i) $infoBy[$i['worksheetName']] = $i;

echo "=== SHEET COUNT: " . count($names) . " ===\n";
foreach ($names as $i => $n) {
    printf("%2d. [%s]  (len=%d)  rows=%s cols=%s lastCol=%s\n", $i + 1, $n, strlen($n),
        $infoBy[$n]['totalRows'] ?? '?', $infoBy[$n]['totalColumns'] ?? '?', $infoBy[$n]['lastColumnLetter'] ?? '?');
}
echo "\n";

$deepKeys = [
    'metro'   => fn($n) => stripos($n, 'metro') !== false,
    'nano'    => fn($n) => stripos($n, 'nano') !== false,
    'organic' => fn($n) => stripos($n, 'organic') !== false,
    'naati'   => fn($n) => stripos($n, 'naati') !== false,
    'kakubal' => fn($n) => stripos($n, 'kakubal') !== false,
    'spn'     => fn($n) => stripos($n, 'spn') !== false,
    'special' => fn($n) => stripos($n, 'special') !== false,
];

function rowCells($ws, int $r, int $maxCol = 14): array
{
    $out = [];
    for ($c = 1; $c <= $maxCol; $c++) {
        $cell = $ws->getCell([$c, $r]);
        $d = cellDisplay($cell);
        if ($d !== '') $out[\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)] = $d;
    }
    return $out;
}
function fmtRow(array $cells): string
{
    if (!$cells) return '(empty)';
    $p = [];
    foreach ($cells as $k => $v) $p[] = "$k=$v";
    return implode(' | ', $p);
}
function rowHasContent($ws, int $r, int $maxCol = 14): bool
{
    for ($c = 1; $c <= $maxCol; $c++) {
        $v = $ws->getCell([$c, $r])->getValue();
        if ($v !== null && trim((string)$v) !== '') return true;
    }
    return false;
}
function dumpRows($ws, int $from, int $to, int $maxRow, string $label = '')
{
    echo "  -- $label rows $from-$to --\n";
    for ($r = $from; $r <= min($to, $maxRow); $r++) {
        echo "    R$r: " . fmtRow(rowCells($ws, $r)) . "\n";
    }
}
function grepRows($ws, int $maxRow, callable $pred, string $label, int $limit = 40, int $maxCol = 14)
{
    echo "  -- $label --\n";
    $n = 0;
    for ($r = 1; $r <= $maxRow; $r++) {
        $cells = rowCells($ws, $r, $maxCol);
        foreach ($cells as $col => $val) {
            if ($pred($val, $col, $r)) {
                echo "    R$r: " . fmtRow($cells) . "\n";
                if (++$n >= $limit) { echo "    (limit)\n"; return; }
                break;
            }
        }
    }
    if ($n === 0) echo "    (no matches)\n";
}

$summary = [];
foreach ($names as $name) {
    $info = $infoBy[$name] ?? [];
    $totalRows = $info['totalRows'] ?? 0;
    echo str_repeat('=', 100) . "\n";
    echo "SHEET: [$name]  totalRows(listWorksheetInfo)=$totalRows  totalCols=" . ($info['totalColumns'] ?? '?') . "\n";

    $r = IOFactory::createReader('Xlsx');
    $r->setReadDataOnly(true);   // per task; date detection via value heuristics
    $r->setLoadSheetsOnly([$name]);
    $r->setReadFilter(new CapFilter(6000, 40));
    try {
        $ss = $r->load($file);
    } catch (\Throwable $e) {
        echo "  LOAD ERROR: " . $e->getMessage() . "\n";
        continue;
    }
    $ws = $ss->getSheet(0);
    $maxRow = min((int)$ws->getHighestRow(), 6000);
    echo "  loaded highestRow=$maxRow highestCol=" . $ws->getHighestColumn() . "\n";

    // first 5 non-empty rows
    $found = 0;
    for ($row = 1; $row <= $maxRow && $found < 5; $row++) {
        if (!rowHasContent($ws, $row)) continue;
        $found++;
        echo "  first#$found R$row: " . fmtRow(rowCells($ws, $row)) . "\n";
    }
    if ($found === 0) echo "  (no non-empty rows in A-N)\n";

    // client name
    $client = null;
    for ($row = 1; $row <= 2; $row++) {
        for ($c = 1; $c <= 14; $c++) {
            $v = $ws->getCell([$c, $row])->getValue();
            if (is_string($v) && stripos($v, 'client name') !== false) {
                $client = "R$row C$c: " . trunc($v);
                break 2;
            }
        }
    }
    echo "  clientNameCell: " . ($client ?? 'NOT FOUND in A1:N2') . "\n";

    // content calendar header detection rows 1-5 (scan to col 40)
    $hdrHits = [];
    for ($row = 1; $row <= 5; $row++) {
        for ($c = 1; $c <= 40; $c++) {
            $v = $ws->getCell([$c, $row])->getValue();
            if (is_string($v) && preg_match('/^\s*(date|publishing\s*date|post(ing)?\s*date|week)\b/i', $v)) {
                $hdrHits[] = "R$row C$c=" . trunc($v, 40);
            }
        }
    }
    $looksCC = count($hdrHits) > 0;
    echo "  looksLikeContentCalendar: " . ($looksCC ? 'YES' : 'NO') . ($hdrHits ? '  hits: ' . implode('; ', $hdrHits) : '') . "\n";
    $summary[] = [$name, $totalRows, $client, $looksCC];

    // deep dives
    $nl = strtolower($name);
    if ($deepKeys['metro']($name)) {
        echo "  >>> DEEP: Metro Design\n";
        dumpRows($ws, 1, 5, $maxRow, 'header');
        grepRows($ws, $maxRow, fn($v) => preg_match('/april\s*1\b|april\s*fool|1\s*apr|apr\s*1\b/i', $v), 'April 1 / April Fools matches');
        grepRows($ws, $maxRow, fn($v) => preg_match('/2026-04-01/', $v), 'serial/date 2026-04-01 matches');
    }
    if ($deepKeys['nano']($name)) {
        echo "  >>> DEEP: Nano Desgin\n";
        dumpRows($ws, 1, 5, $maxRow, 'header');
        dumpRows($ws, 38, 42, $maxRow, 'rows 38-42');
        grepRows($ws, $maxRow, fn($v) => stripos($v, 'adhoc') !== false || stripos($v, 'ad hoc') !== false || stripos($v, 'ad-hoc') !== false, 'Adhoc matches');
    }
    if ($deepKeys['organic']($name)) {
        echo "  >>> DEEP: Organic Mohan\n";
        dumpRows($ws, 1, 5, $maxRow, 'header');
        grepRows($ws, $maxRow, fn($v) => preg_match('/\bjuly\b|\bjul\b/i', $v), 'JULY section matches');
        grepRows($ws, $maxRow, fn($v) => preg_match('/2026-01-07|2026-07-01|\b0?1[\/\-.]0?7[\/\-.](20)?26\b|\b0?7[\/\-.]0?1[\/\-.](20)?26\b|7\s*(jan|january)|1\s*(jul|july)|jan(uary)?\s*7|jul(y)?\s*1\b/i', $v), 'rows with 2026-01-07 / 7 Jan / 1 Jul / day-month swap candidates');
        // all date-looking cells where day<=12 and ambiguous in col A..C, show first 30
        grepRows($ws, $maxRow, fn($v, $col) => preg_match('/\{date:2026-(01-07|07-01)/', $v), 'date-typed cells 2026-01-07 or 2026-07-01');
    }
    if ($deepKeys['naati']($name)) {
        echo "  >>> DEEP: naati fresh\n";
        dumpRows($ws, 1, 5, $maxRow, 'header');
        grepRows($ws, $maxRow, fn($v) => preg_match('/nov(ember)?\b/i', $v) || preg_match('/\{date:2026-11/', $v), 'November matches');
    }
    if ($deepKeys['kakubal']($name)) {
        echo "  >>> DEEP: Kakubal Design\n";
        dumpRows($ws, 1, 5, $maxRow, 'header');
        grepRows($ws, $maxRow, fn($v) => preg_match('/design\s*0?1\b/i', $v), 'Design 01 matches');
    }
    if ($deepKeys['spn']($name)) {
        echo "  >>> DEEP: SPN\n";
        dumpRows($ws, 1, 6, $maxRow, 'headers');
        grepRows($ws, $maxRow, fn($v) => preg_match('/^(date|publishing date|week|day|month)\b/i', $v), 'header-like cells');
    }
    if ($deepKeys['special']($name)) {
        echo "  >>> DEEP: special days sheet\n";
        dumpRows($ws, 1, 8, $maxRow, 'first rows');
        $dateCols = [];
        for ($row = 1; $row <= min($maxRow, 15); $row++) {
            for ($c = 1; $c <= 40; $c++) {
                $v = $ws->getCell([$c, $row])->getValue();
                if (is_string($v) && preg_match('/\bdate\b/i', $v)) $dateCols[] = "R$row C$c=" . trunc($v, 40);
            }
        }
        echo "  'date' header-ish cells in rows 1-15: " . ($dateCols ? implode('; ', $dateCols) : 'NONE (no date column)') . "\n";
        // numeric date-like values?
        $numDates = 0;
        for ($row = 1; $row <= $maxRow; $row++) for ($c = 1; $c <= 40; $c++) {
            $v = $ws->getCell([$c, $row])->getValue();
            if (is_numeric($v) && $v > 40000 && $v < 50000) $numDates++;
        }
        echo "  numeric serial-date-like values (40000-50000) in sheet: $numDates\n";
    }

    $ss->disconnectWorksheets();
    unset($ss, $ws, $r);
    gc_collect_cycles();
}

echo str_repeat('=', 100) . "\n";
echo "SUMMARY TABLE\n";
foreach ($summary as [$n, $tr, $cl, $cc]) {
    printf("  %-40s rows=%-6s CC=%-3s client=%s\n", "[$n]", $tr, $cc ? 'Y' : 'N', $cl ?? '-');
}
printf("\nPeak memory: %.1f MB\nElapsed: %.2f s\n", memory_get_peak_usage(true) / 1048576, microtime(true) - $t0);
