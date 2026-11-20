<?php
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'content_calendar']);
$app = \Config\Services::codeigniter();
$app->initialize();

$path = FCPATH . 'tests/_tmp_cc_fixtures/complex.xlsx';
echo "path=$path\n";
echo "is_file=" . (is_file($path) ? '1' : '0') . " is_readable=" . (is_readable($path) ? '1' : '0') . " size=" . (is_file($path) ? filesize($path) : 0) . "\n";

$dest = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'cc_dbg.xlsx';
$copied = @copy($path, $dest);
echo "copy_to=$dest ok=" . ($copied ? '1' : '0') . " err=" . (error_get_last()['message'] ?? '') . "\n";

require_once APPPATH . 'ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php';
$r = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
echo "canRead_src=" . ($r->canRead($path) ? 'yes' : 'no') . " canRead_dest=" . ($copied && $r->canRead($dest) ? 'yes' : 'no') . "\n";

$lib = new \App\Libraries\Content_calendar_import();
try {
    $result = $lib->read_file($path, 'auto');
    echo "PASS read_file type=" . ($result['detected_type'] ?? '?') . " sheets=" . count($result['sheets'] ?? array()) . "\n";
} catch (Throwable $e) {
    echo "FAIL read_file: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n";
}
@unlink($dest);
