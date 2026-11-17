<?php
/**
 * Prove task_points_review_rules_helper.php loads via task_points helper on a clean request
 * with no app/Config change (only the normal helper() list from App_Controller).
 */
ini_set('memory_limit', '256M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require __DIR__ . '/../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");

// Exact helper list from App_Controller.php (no Config change; review rules helper is pulled in by task_points)
helper(['url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);

$app = \Config\Services::codeigniter();
$app->initialize();

$tp = APPPATH . 'Helpers/task_points_helper.php';
$lines = file($tp);
$line_no = 0;
$line_txt = '';
foreach ($lines as $i => $line) {
    if (strpos($line, 'task_points_review_rules_helper.php') !== false) {
        $line_no = $i + 1;
        $line_txt = trim($line);
        break;
    }
}

echo "LOAD_SITE=app/Helpers/task_points_helper.php:$line_no\n";
echo "LOAD_STMT=$line_txt\n";
echo "APP_CONTROLLER_HELPERS=app/Controllers/App_Controller.php:92 helper(array(...'task_points'...))\n";
echo "function_exists is_task_points_review_rules_applicable=" . (function_exists('is_task_points_review_rules_applicable') ? '1' : '0') . "\n";
echo "function_exists lock_task_points_review_rules=" . (function_exists('lock_task_points_review_rules') ? '1' : '0') . "\n";
echo "function_exists get_task_projected_award_display=" . (function_exists('get_task_projected_award_display') ? '1' : '0') . "\n";
echo "file_exists review_rules_helper=" . (file_exists(APPPATH . 'Helpers/task_points_review_rules_helper.php') ? '1' : '0') . "\n";
echo "OK\n";
