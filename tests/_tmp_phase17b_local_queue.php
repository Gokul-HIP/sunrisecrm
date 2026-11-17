<?php
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);
$riseConfig = config('Rise');
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

echo "DB=" . $db->getDatabase() . " VERSION=" . $db->query("SELECT VERSION()")->getRow(0)->{'VERSION()'} . "\n";
echo "rules_enabled=" . get_setting('task_points_review_rules_enabled') . " from=" . var_export(get_setting('task_points_review_rules_from'), true) . "\n";
echo "in_review_id=" . get_in_review_task_status_id() . " changes_id=" . get_changes_task_status_id() . " done_id=" . get_done_task_status_id() . "\n";

foreach ($db->query("SELECT id, key_name, title FROM rise_task_status WHERE deleted=0")->getResult() as $s) {
    echo "status {$s->id}={$s->key_name}/{$s->title}\n";
}

foreach ([25784, 25702, 23527] as $tid) {
    $row = $db->query("SELECT * FROM rise_tasks WHERE id=$tid")->getRow();
    if (!$row) { echo "TASK $tid MISSING\n"; continue; }
    $u = $db->query("SELECT CONCAT(first_name,' ',last_name) AS n FROM rise_users WHERE id=".(int)$row->assigned_to)->getRow();
    $row->assigned_to_user = $u ? $u->n : '';
    $proj = get_task_projected_award_display($row);
    $preview = calculate_task_participant_award_preview($row, '', true);
    $uid = (int)$row->assigned_to;
    $part = $preview['participants'][$uid] ?? null;
    echo "\n=== TASK $tid ===\n";
    echo "title={$row->title}\n";
    echo "status_id={$row->status_id} points={$row->points} deadline={$row->deadline} end_time={$row->end_time}\n";
    echo "created={$row->created_date} submitted={$row->submitted_for_review_at} first_review={$row->first_review_submitted_at}\n";
    echo "changes={$row->review_changes_count} timer_lock={$row->timer_lock_json} locked_at={$row->points_rules_locked_at}\n";
    echo "applicable=" . (is_task_points_review_rules_applicable($row)?'1':'0') . "\n";
    echo "part=" . json_encode($part) . "\n";
    echo "queue_short={$proj['queue_label_short']}\n";
    echo "queue={$proj['queue_label']}\n";
    echo "reason={$proj['reason']}\n";
    echo "approval={$proj['approval_label']}\n";
    echo "confirm_needed=" . ($proj['confirm_needed']?'1':'0') . "\n";
}

// TimeTrait clean?
$tt = file_get_contents(SYSTEMPATH . 'I18n/TimeTrait.php');
$clean = strpos($tt, 'createFromTimestamp(int $timestamp, $timezone = null, ?string $locale = null)') !== false
    && strpos($tt, 'int|float $timestamp') === false;
echo "\nTimeTrait_clean=" . ($clean ? '1' : '0') . "\n";
