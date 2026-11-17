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

// Simulate tasks_in_review_list_data query (awaiting_review_all)
$tasks_model = model('App\Models\Tasks_model');
$result = $tasks_model->get_details(['awaiting_review_all' => true]);
$rows = is_object($result) ? $result->getResult() : (is_array($result) ? ($result['data'] ?? []) : []);
echo "awaiting_review count=" . count($rows) . "\n";
foreach ($rows as $data) {
    if (!in_array((int)$data->id, [25784, 25785, 25786], true)) continue;
    echo "\n-- queue row id={$data->id} --\n";
    echo "props: created_date=" . var_export($data->created_date ?? null, true) . "\n";
    echo "  assigned_to=" . var_export($data->assigned_to ?? null, true) . "\n";
    echo "  points=" . var_export($data->points ?? null, true) . "\n";
    echo "  status_id=" . var_export($data->status_id ?? null, true) . "\n";
    echo "  submitted=" . var_export($data->submitted_for_review_at ?? null, true) . "\n";
    echo "  first_review=" . var_export(property_exists($data, 'first_review_submitted_at') ? $data->first_review_submitted_at : 'NO_PROP', true) . "\n";
    echo "  timer_lock=" . var_export(property_exists($data, 'timer_lock_json') ? $data->timer_lock_json : 'NO_PROP', true) . "\n";
    echo "  locked_at=" . var_export(property_exists($data, 'points_rules_locked_at') ? $data->points_rules_locked_at : 'NO_PROP', true) . "\n";
    echo "  changes=" . var_export(property_exists($data, 'review_changes_count') ? $data->review_changes_count : 'NO_PROP', true) . "\n";
    echo "  applicable=" . (is_task_points_review_rules_applicable($data) ? '1' : '0') . "\n";
    $preview = calculate_task_participant_award_preview($data, '', true);
    echo "  preview=" . json_encode($preview['participants']) . "\n";
    $proj = get_task_projected_award_display($data);
    echo "  queue_label={$proj['queue_label_short']} reason={$proj['reason']} award={$proj['award']}\n";
}

// Reproduce: create on-time task, move to In review via same code path as save_task_status, check lock + projection
echo "\n========== REPRO: save path lock ==========\n";
$assignee = 40; $creator = 7; $project_id = 626;
$todo = (int)(model('App\Models\Task_status_model')->get_one_where(['key_name'=>'to_do','deleted'=>0])->id ?? 0);
$in_review = (int) get_in_review_task_status_id();
$deadline = date('Y-m-d', strtotime('+2 days'));
$now = get_current_utc_time();

// TimeTrait patch for ci_save
$tt = SYSTEMPATH . 'I18n/TimeTrait.php';
$tt_src = file_get_contents($tt);
$old_sig = 'public static function createFromTimestamp(int $timestamp, $timezone = null, ?string $locale = null)';
$new_sig = 'public static function createFromTimestamp(int|float $timestamp, $timezone = null, ?string $locale = null): static';
$tt_patched = false;
if (strpos($tt_src, $old_sig) !== false) {
    file_put_contents($tt, str_replace($old_sig, $new_sig, $tt_src));
    $tt_patched = true;
}
register_shutdown_function(function () use ($tt, $tt_src, $tt_patched) {
    if ($tt_patched) file_put_contents($tt, $tt_src);
});

$id = $tasks_model->ci_save([
    'title' => 'P17C repro no timer',
    'description' => '',
    'project_id' => $project_id,
    'milestone_id' => 0,
    'assigned_to' => $assignee,
    'collaborators' => '',
    'status_id' => $todo,
    'points' => 5,
    'deadline' => $deadline,
    'end_time' => '23:00:00',
    'created_date' => date('Y-m-d'),
    'created_by' => $creator,
    'deleted' => 0,
]);
echo "created id=$id\n";
$task_info = $tasks_model->get_one($id);
echo "before: applicable=" . (is_task_points_review_rules_applicable($task_info)?'1':'0') . " status={$task_info->status_id}\n";

$data = ['status_id' => $in_review, 'status_changed_at' => $now];
apply_review_stamp_on_status_change($task_info, $in_review, $data, $now);
echo "after stamp data keys=" . json_encode(array_keys($data)) . " submitted_in_data=" . ($data['submitted_for_review_at'] ?? 'none') . "\n";
echo "task_info still status={$task_info->status_id} submitted=" . var_export($task_info->submitted_for_review_at ?? null, true) . "\n";
echo "applicable before lock call=" . (is_task_points_review_rules_applicable($task_info)?'1':'0') . "\n";
apply_task_points_review_rules_on_status_change($task_info, $in_review, $data, $now);
echo "after rules data=" . json_encode($data) . "\n";
$tasks_model->ci_save($data, $id);
$saved = $tasks_model->get_one($id);
echo "saved first=" . var_export($saved->first_review_submitted_at, true) . " lock=" . var_export($saved->timer_lock_json, true) . " locked_at=" . var_export($saved->points_rules_locked_at, true) . "\n";
$proj = get_task_projected_award_display($saved);
echo "queue={$proj['queue_label_short']} reason={$proj['reason']}\n";

// Also test: what if we only stamp but NOT call apply rules (broken route)
$id2 = $tasks_model->ci_save([
    'title' => 'P17C unlocked ontime',
    'description' => '',
    'project_id' => $project_id,
    'milestone_id' => 0,
    'assigned_to' => $assignee,
    'collaborators' => '',
    'status_id' => $todo,
    'points' => 5,
    'deadline' => $deadline,
    'end_time' => '23:00:00',
    'created_date' => date('Y-m-d'),
    'created_by' => $creator,
    'deleted' => 0,
]);
$t2 = $tasks_model->get_one($id2);
$data2 = ['status_id' => $in_review, 'status_changed_at' => $now];
apply_review_stamp_on_status_change($t2, $in_review, $data2, $now);
// intentionally skip apply_task_points_review_rules
$tasks_model->ci_save($data2, $id2);
$saved2 = $tasks_model->get_one($id2);
echo "\nunlocked path: first=" . var_export($saved2->first_review_submitted_at, true) . " lock=" . var_export($saved2->timer_lock_json, true) . "\n";
$proj2 = get_task_projected_award_display($saved2);
echo "unlocked queue={$proj2['queue_label_short']} reason={$proj2['reason']} applicable=" . ($proj2['rules_applicable']?'1':'0') . "\n";
