<?php
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
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
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'content_calendar']);
$app = \Config\Services::codeigniter();
$app->initialize();

$lib = new \App\Libraries\Content_calendar_import();
$fixtures = array(
    'complex.xlsx' => FCPATH . 'tests/_tmp_cc_fixtures/complex.xlsx',
    'rows_5000.csv' => FCPATH . 'tests/_tmp_cc_fixtures/rows_5000.csv',
);
foreach ($fixtures as $name => $path) {
    if (!is_file($path)) {
        echo "MISSING $name\n";
        continue;
    }
    $peak_before = memory_get_peak_usage(true);
    try {
        $result = $lib->read_file($path, 'auto');
        $ok = is_array($result) ? 'ok sheets=' . count($result['sheets'] ?? $result) : 'fail';
        $err = is_array($result) ? '' : (is_string($result) ? $result : json_encode($result));
        if (is_array($result) && isset($result['error'])) {
            $ok = 'err';
            $err = $result['error'];
        }
    } catch (Throwable $e) {
        $ok = 'ex';
        $err = $e->getMessage();
    }
    $peak = memory_get_peak_usage(true);
    echo sprintf("%s size=%d peak_bytes=%d peak_mb=%.1f result=%s %s\n", $name, filesize($path), $peak, $peak / 1048576, $ok, $err);
}
echo 'php_memory_limit=' . ini_get('memory_limit') . "\n";
echo 'upload_max_filesize=' . ini_get('upload_max_filesize') . "\n";
echo 'post_max_size=' . ini_get('post_max_size') . "\n";
