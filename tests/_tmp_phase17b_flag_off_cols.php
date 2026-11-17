<?php
/**
 * Flag-off column safety: prove apply_* / lock do not write new columns when flag=0,
 * and list code sites that name the new columns.
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
putenv('database.default.database=sunrisecrm_scratch');
$_ENV['database.default.database'] = 'sunrisecrm_scratch';
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);
$app = \Config\Services::codeigniter();
$app->initialize();
$dbConfig = config('Database');
$dbConfig->default['database'] = 'sunrisecrm_scratch';
$db = \Config\Database::connect('default', true);
$riseConfig = config('Rise');
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}

// Force flag OFF and empty from
$riseConfig->app_settings_array['task_points_review_rules_enabled'] = '0';
$riseConfig->app_settings_array['task_points_review_rules_from'] = '';

$task = (object)[
    'id' => 1,
    'points' => 5,
    'assigned_to' => 40,
    'collaborators' => '',
    'created_date' => '2026-11-16',
    'status_id' => (int) get_in_review_task_status_id(),
    'submitted_for_review_at' => '2026-11-16 10:00:00',
    'first_review_submitted_at' => null,
    'review_changes_count' => 0,
    'timer_lock_json' => null,
    'points_rules_locked_at' => null,
    'deadline' => '2026-11-20',
    'end_time' => '18:00:00',
];

$data = [];
lock_task_points_review_rules($task, $data, get_current_utc_time());
echo "flag0_lock_writes_keys=" . json_encode(array_keys($data)) . "\n";

$data2 = [];
apply_task_points_review_rules_on_status_change($task, (int) get_done_task_status_id(), $data2, get_current_utc_time());
echo "flag0_apply_writes_keys=" . json_encode(array_keys($data2)) . "\n";

echo "flag0_applicable=" . (is_task_points_review_rules_applicable($task) ? '1' : '0') . "\n";
echo "flag0_held_sum=" . sum_user_held_task_points(40) . "\n";

$cols = ['review_changes_count','first_review_submitted_at','timer_lock_json','points_rules_locked_at'];
echo "\n-- Code sites naming new columns --\n";
$root = APPPATH;
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    if (strpos($path, DIRECTORY_SEPARATOR . 'Language' . DIRECTORY_SEPARATOR) !== false) continue;
    $lines = file($path);
    foreach ($lines as $i => $line) {
        foreach ($cols as $c) {
            if (strpos($line, $c) !== false) {
                $rel = str_replace(ROOTPATH, '', $path);
                echo "$rel:" . ($i+1) . " [$c] " . trim($line) . "\n";
                break;
            }
        }
    }
}

echo "\n-- Tasks_model SELECT style --\n";
$tm = file_get_contents(APPPATH . 'Models/Tasks_model.php');
if (preg_match('/SELECT \$tasks_table\.\*/', $tm)) {
    echo "Tasks_model list uses SELECT tasks.* (no hard dependency on new column names)\n";
}
echo "DONE\n";
