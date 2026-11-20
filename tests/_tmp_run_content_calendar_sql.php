<?php
/**
 * Runs docs/content_calendar.sql against the LOCAL database (credentials from app/Config/Database.php,
 * same bootstrap as the other _tmp tests). Runs the file twice to prove it is safe to repeat.
 *
 * Usage: php tests/_tmp_run_content_calendar_sql.php
 * Never run this against the live server.
 */
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);

$host = $db->hostname;
if (!in_array($host, array('127.0.0.1', 'localhost', 'localhost:3306'), true)) {
    echo "REFUSING: database host is '$host' (local only).\n";
    exit(1);
}

$sql_file = FCPATH . 'docs/content_calendar.sql';
if (!is_file($sql_file)) {
    echo "Missing $sql_file\n";
    exit(1);
}

echo "db=" . $db->getDatabase() . " host=$host\n";
$raw = file_get_contents($sql_file);

// strip "-- ..." comment lines, then split on ";" at line end
$lines = preg_split('/\r?\n/', $raw);
$clean = array();
foreach ($lines as $line) {
    if (preg_match('/^\s*--/', $line)) {
        continue;
    }
    $clean[] = $line;
}
$statements = array();
foreach (preg_split('/;\s*(\r?\n|$)/', implode("\n", $clean)) as $stmt) {
    $stmt = trim($stmt);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }
}

echo "statements: " . count($statements) . "\n";

for ($run = 1; $run <= 2; $run++) {
    $ok = 0;
    foreach ($statements as $stmt) {
        try {
            $db->query($stmt);
            $ok++;
        } catch (\Throwable $e) {
            echo "[FAIL run $run] " . $e->getMessage() . "\n" . substr($stmt, 0, 120) . "\n";
            exit(1);
        }
    }
    echo "run $run: $ok statements OK\n";
}

$tables = $db->query("SHOW TABLES LIKE 'rise\\_cc\\_%'")->getResultArray();
echo "tables:\n";
foreach ($tables as $t) {
    echo "  " . array_values($t)[0] . "\n";
}
echo "done\n";
