<?php
/**
 * Simulate /index.php/cron and /cron/reminders without Phase 15.
 * Scratch DB only.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/index.php/cron';
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

echo "class Cron_performance_tracker? " . (class_exists('\\App\\Libraries\\Cron_performance_tracker') ? 'YES' : 'NO') . "\n";
echo "class Request_performance_tracker? " . (class_exists('\\App\\Libraries\\Request_performance_tracker') ? 'YES' : 'NO') . "\n";

try {
    // Equivalent to /cron/reminders pre-gate without Cron_performance_tracker
    $sent = check_task_deadline_2_hour_reminders_throttled(0);
    echo "/cron/reminders path ok, sent=" . (int) $sent . "\n";
} catch (Throwable $e) {
    echo "/cron/reminders FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    // Prove Cron.php class loads and reminders pre-gate method exists without Phase 15
    $cronCtrl = new \App\Controllers\Cron();
    $ref = new ReflectionClass($cronCtrl);
    $m = $ref->getMethod('check_task_deadline_2_hour_reminders_pre_gate');
    $m->setAccessible(true);
    $sent2 = $m->invoke($cronCtrl);
    echo "/index.php/cron pre_gate reminders ok, sent=" . (int) $sent2 . "\n";

    $job = new \App\Libraries\Cron_job();
    $refJob = new ReflectionClass($job);
    $hourly = $refJob->getMethod('call_hourly_jobs');
    $hourly->setAccessible(true);
    // Force closed hourly gate by setting last_hourly_job_time to now — avoids IMAP/network
    model('App\Models\Settings_model')->save_setting('last_hourly_job_time', (string) time());
    $job->init_runtime(array("diagnostic" => true));
    $r = $hourly->invoke($job, array("diagnostic" => true));
    echo "/index.php/cron hourly_gate path ok, open=" . (!empty($r['hourly_gate_open']) ? '1' : '0') . "\n";
} catch (Throwable $e) {
    echo "/index.php/cron FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $details = model('App\Models\Tasks_model')->get_details(array(
        "specific_user_id" => 40,
        "exclude_review_tasks_for_assignee" => true,
        "limit" => 3
    ));
    $tasks = is_array($details) ? (isset($details['data']) ? $details['data'] : $details) : $details->getResult();
    if (is_object($tasks) && method_exists($tasks, 'getResult')) {
        $tasks = $tasks->getResult();
    }
    echo "Tasks page load ok, sample_rows=" . count($tasks) . "\n";
} catch (Throwable $e) {
    echo "Tasks page FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

echo "NO_PHASE15_CRON_OK\n";
