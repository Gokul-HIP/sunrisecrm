<?php
error_reporting(E_ALL & ~E_DEPRECATED);
require __DIR__ . '/../app/ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php';
class F implements PhpOffice\PhpSpreadsheet\Reader\IReadFilter { function readCell($c, $r, $w = '') { return $r <= 120; } }
$r = PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
$r->setReadDataOnly(true); $r->setLoadSheetsOnly(['SPN']); $r->setReadFilter(new F);
$ws = $r->load(__DIR__ . '/_tmp_fixtures/Content_Calender_2026.xlsx')->getSheet(0);
foreach ([30, 31, 32, 33, 34, 35, 36, 37, 38, 39, 40, 41, 106, 107, 108, 109, 110, 111] as $n) {
    echo "R$n: ";
    for ($c = 1; $c <= 8; $c++) {
        $v = $ws->getCell([$c, $n])->getValue();
        if ($v !== null && trim((string)$v) !== '') echo chr(64 + $c) . '=' . mb_substr(str_replace("\n", '\\n', (string)$v), 0, 40) . ' | ';
    }
    echo "\n";
}
