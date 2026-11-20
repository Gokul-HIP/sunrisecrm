<?php
error_reporting(E_ALL & ~E_DEPRECATED);
define('APPPATH', dirname(__DIR__) . '/app/');
require_once APPPATH . 'ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php';
$file = __DIR__ . '/_tmp_cc_fixtures/' . ($argv[1] ?? 'complex.xlsx');
try {
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(ucfirst($argv[2] ?? 'Xlsx'));
    var_dump($reader->canRead($file));
    $reader->setReadEmptyCells(false);
    print_r($reader->listWorksheetInfo($file));
    $book = $reader->load($file);
    echo "loaded sheets: " . $book->getSheetCount() . "\n";
} catch (\Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
