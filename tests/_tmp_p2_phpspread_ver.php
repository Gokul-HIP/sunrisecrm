<?php
$lock = "/home/gokul/projects/crm_sunrise/app/ThirdParty/PHPOffice-PhpSpreadsheet/composer.lock";
if (!is_file($lock)) {
    // try parent path used by RISE
    $lock = dirname(__DIR__) . "/app/ThirdParty/PHPOffice-PhpSpreadsheet/composer.lock";
}
$j = json_decode((string) file_get_contents($lock), true);
foreach (($j["packages"] ?? []) as $p) {
    if (strpos($p["name"], "phpspreadsheet") !== false) {
        echo $p["name"] . " " . $p["version"] . PHP_EOL;
    }
}
echo "TOKEN_TTL=";
require dirname(__DIR__) . "/app/Config/Paths.php";
// peek constant via reflection-free grep fallback
$src = file_get_contents(dirname(__DIR__) . "/app/Libraries/Content_calendar_import.php");
if (preg_match('/TOKEN_TTL\s*=\s*(\d+)/', $src, $m)) {
    echo $m[1] . PHP_EOL;
}
if (preg_match('/MAX_FILE_BYTES\s*=\s*(\d+)/', $src, $m)) {
    echo "MAX_FILE_BYTES=" . $m[1] . PHP_EOL;
}
