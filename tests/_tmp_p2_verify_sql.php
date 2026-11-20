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
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default');
if (!in_array($db->hostname, array('127.0.0.1', 'localhost', 'localhost:3306'), true)) {
    echo "REFUSING: {$db->hostname}\n";
    exit(1);
}

function run_sql_file_ci($db, $path, $label) {
    $sql = file_get_contents($path);
    $lines = preg_split("/\r\n|\n|\r/", $sql);
    $buf = '';
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if ($trim === '' || strpos($trim, '--') === 0) {
            continue;
        }
        $buf .= $line . "\n";
    }
    // Keep PREPARE/EXECUTE/DEALLOCATE as separate statements
    $parts = preg_split('/;\s*\n/', $buf);
    $ok = true;
    $errs = array();
    foreach ($parts as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') {
            continue;
        }
        try {
            $db->query($stmt);
        } catch (Throwable $e) {
            $ok = false;
            $errs[] = $e->getMessage() . ' :: ' . substr($stmt, 0, 80);
        }
    }
    echo ($ok ? 'PASS' : 'FAIL') . ": SQL run $label\n";
    foreach ($errs as $e) {
        echo "  ERR: $e\n";
    }
    return $ok;
}

echo "DB host={$db->hostname} database={$db->database}\n";
$r1 = run_sql_file_ci($db, FCPATH . 'docs/content_calendar.sql', 'first');
$r2 = run_sql_file_ci($db, FCPATH . 'docs/content_calendar.sql', 'second (idempotent)');

$t = $db->query("SHOW TABLES LIKE 'rise_cc_import_logs'")->getResultArray();
echo (count($t) ? 'PASS' : 'FAIL') . ": rise_cc_import_logs exists\n";
$col = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND COLUMN_NAME = 'import_batch_id'")->getRow();
echo ($col ? 'PASS' : 'FAIL') . ': import_batch_id' . ($col ? " {$col->COLUMN_TYPE} null={$col->IS_NULLABLE}" : '') . "\n";
$idx = $db->query("SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rise_cc_items' AND INDEX_NAME = 'idx_cc_items_import_batch' LIMIT 1")->getRow();
echo ($idx ? 'PASS' : 'FAIL') . ": idx_cc_items_import_batch\n";

$ctrl = file_get_contents(FCPATH . 'app/Controllers/Content_calendar.php');
echo (preg_match('/function add_client\(\)\s*\{\s*app_redirect\("content_calendar"\);/', $ctrl) ? 'PASS' : 'FAIL') . ": add_client redirects\n";
echo (!is_file(FCPATH . 'app/Views/content_calendar/add_client.php') ? 'PASS' : 'FAIL') . ": add_client view removed\n";

exit(($r1 && $r2 && count($t) && $col && $idx) ? 0 : 1);
