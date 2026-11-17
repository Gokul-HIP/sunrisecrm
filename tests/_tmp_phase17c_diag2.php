<?php
ini_set('memory_limit', '512M');
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);
$riseConfig = config('Rise');
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

echo "DB=" . $db->getDatabase() . "\n";
echo "enabled=" . var_export(get_setting('task_points_review_rules_enabled'), true) . "\n";
echo "from=" . var_export(get_setting('task_points_review_rules_from'), true) . "\n";
echo "25876/25877 exist? ";
$c = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE id IN (25876,25877)")->getRow()->c;
echo "$c\n";
echo "max_id=" . $db->query("SELECT MAX(id) m FROM rise_tasks")->getRow()->m . "\n";

// Closest matches: unlocked in-review created today, and locked one
$ids = [25785, 25786, 25784];
foreach ($ids as $tid) {
    echo "\n========== TASK $tid ==========\n";
    $row = $db->query("SELECT * FROM rise_tasks WHERE id=$tid")->getRow();
    if (!$row) { echo "MISSING\n"; continue; }
    echo "title={$row->title} status={$row->status_id} created={$row->created_date} points={$row->points}\n";
    echo "deadline={$row->deadline} end={$row->end_time} assignee={$row->assigned_to}\n";
    echo "submitted={$row->submitted_for_review_at}\n";
    echo "first_review=" . var_export($row->first_review_submitted_at, true) . "\n";
    echo "changes={$row->review_changes_count}\n";
    echo "timer_lock_json=" . var_export($row->timer_lock_json, true) . "\n";
    echo "points_rules_locked_at=" . var_export($row->points_rules_locked_at, true) . "\n";

    $from = get_task_points_review_rules_from();
    $from_date = substr($from, 0, 10);
    $created = (string)$row->created_date;
    echo "from_date_used=$from_date created=$created created>=from=" . var_export($created >= $from_date, true) . "\n";
    echo "legacy=" . var_export(is_legacy_in_review_task($row), true) . "\n";
    echo "applicable=" . (is_task_points_review_rules_applicable($row) ? '1' : '0') . "\n";

    $times = $db->query("SELECT id,user_id,status,start_time,end_time,deleted FROM rise_project_time WHERE task_id=$tid")->getResult();
    echo "project_time_rows=" . count($times) . "\n";
    foreach ($times as $t) {
        echo "  time id={$t->id} user={$t->user_id} status={$t->status} start={$t->start_time} end={$t->end_time} del={$t->deleted}\n";
    }

    $uid = (int)$row->assigned_to;
    echo "timer_ok=" . (participant_timer_ok_for_task($row, $uid) ? '1' : '0') . "\n";
    $preview = calculate_task_participant_award_preview($row, '', true);
    echo "preview_applicable=" . ($preview['applicable']?'1':'0') . "\n";
    echo "preview_part=" . json_encode($preview['participants'][$uid] ?? null) . "\n";
    $proj = get_task_projected_award_display($row);
    echo "queue={$proj['queue_label_short']} reason={$proj['reason']} award={$proj['award']}\n";

    // Also simulate list-row object (what tasks_in_review_list_data might pass)
    $list = model('App\Models\Tasks_model')->get_details(['id' => $tid])->getRow();
    if ($list) {
        echo "list_row first_review=" . var_export($list->first_review_submitted_at ?? 'MISSING_PROP', true) . "\n";
        echo "list_row timer_lock=" . var_export($list->timer_lock_json ?? 'MISSING_PROP', true) . "\n";
        echo "list_row locked_at=" . var_export($list->points_rules_locked_at ?? 'MISSING_PROP', true) . "\n";
        echo "list_row changes=" . var_export($list->review_changes_count ?? 'MISSING_PROP', true) . "\n";
        $proj2 = get_task_projected_award_display($list);
        echo "list_queue={$proj2['queue_label_short']} reason={$proj2['reason']}\n";
    }
}

// Check function name alias
echo "\nis_task_review_rules_applicable exists=" . (function_exists('is_task_review_rules_applicable')?'1':'0') . "\n";
echo "is_task_points_review_rules_applicable exists=" . (function_exists('is_task_points_review_rules_applicable')?'1':'0') . "\n";
