<?php
/**
 * Phase 16D probe: prove Phase 16 works without Phase 15 classes/tables.
 * Scratch only. Does not touch live.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/index.php/projects/all_tasks';
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);

$app = \Config\Services::codeigniter();
$app->initialize();

$dbConfig = config('Database');
$dbConfig->default['database'] = 'sunrisecrm_scratch';
$dbConfig->default['hostname'] = '127.0.0.1';
$db = \Config\Database::connect('default', false);

$riseConfig = config('Rise');
$riseConfig->app_settings_array = array();
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

echo "DB=" . $db->database . "\n";
echo "Cron_performance_tracker exists? " . (class_exists('\\App\\Libraries\\Cron_performance_tracker') ? 'YES' : 'NO') . "\n";
echo "Request_performance_tracker exists? " . (class_exists('\\App\\Libraries\\Request_performance_tracker') ? 'YES' : 'NO') . "\n";

foreach (array('cron_run_log', 'request_load_minute', 'slow_queries') as $t) {
    $table = $db->prefixTable($t);
    $row = $db->query("SHOW TABLES LIKE " . $db->escape($table))->getRow();
    echo "table $table: " . ($row ? 'EXISTS' : 'ABSENT') . "\n";
}

// Tasks page model path (list + counts) — mirrors Projects::all_tasks_data filter
$tasks_model = model('App\\Models\\Tasks_model');
$list = $tasks_model->get_details(array(
    "specific_user_id" => 40,
    "exclude_review_tasks_for_assignee" => true,
    "limit" => 5
));
$rows = is_array($list) ? ($list["data"] ?? array()) : $list->getResult();
echo "Tasks page query rows: " . count($rows) . "\n";
echo "Badge count (user 7): " . count_tasks_in_review((object) array("id" => 7, "is_admin" => 0)) . "\n";

// Soft-gate path used by Cron_job without invoking IMAP/network jobs.
$cron = new \App\Libraries\Cron_job();
$ref = new ReflectionClass($cron);
$track = $ref->getMethod('track_cron_job');
$track->setAccessible(true);
$val = $track->invoke($cron, 'probe_job', 'manual', function () {
    return 'soft_gate_ok';
});
echo "Cron_job soft track_cron_job=" . $val . "\n";
$cleanup = $cron->clean_performance_and_cron_logs();
echo "clean_performance_and_cron_logs return=" . var_export($cleanup, true) . "\n";

// Reminder helper (same path as /cron/reminders)
$sent = check_and_send_task_deadline_2_hour_reminders(true);
echo "2h reminders sent this run: $sent\n";

echo "PROBE_OK\n";
