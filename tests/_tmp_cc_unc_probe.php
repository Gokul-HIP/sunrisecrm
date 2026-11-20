<?php
require dirname(__DIR__) . '/app/ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php';
$p = dirname(__DIR__) . '/tests/_tmp_cc_fixtures/complex.xlsx';
$r = PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
echo 'direct canRead=' . ($r->canRead($p) ? 'yes' : 'no') . "\n";
$t = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_t.xlsx';
copy($p, $t);
echo 'temp=' . $t . ' canRead=' . ($r->canRead($t) ? 'yes' : 'no') . "\n";
@unlink($t);

// Also smoke the library method if bootstrap is heavy — just check method exists
$src = file_get_contents(dirname(__DIR__) . '/app/Libraries/Content_calendar_import.php');
echo (strpos($src, 'copy_workbook_to_native_temp') !== false ? 'PASS' : 'FAIL') . ": UNC fallback present\n";
