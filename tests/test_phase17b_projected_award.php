<?php
/**
 * Phase 17B: empty/invalid from-date safety + projected award UI vs approve.
 * Usage: php tests/test_phase17b_projected_award.php [--db=sunrisecrm_scratch]
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$db_name = 'sunrisecrm_scratch';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $db_name = substr($arg, 5);
    }
}

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

putenv("database.default.database={$db_name}");
$_ENV['database.default.database'] = $db_name;
$_SERVER['database.default.database'] = $db_name;

require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);

$app = \Config\Services::codeigniter();
$app->initialize();

$dbConfig = config('Database');
$dbConfig->default['database'] = $db_name;
$db = \Config\Database::connect('default', false);
try { $db->query("SELECT 1"); } catch (\Throwable $e) {}
if ($db->getDatabase() !== $db_name) {
    $db->close();
    $dbConfig->default['database'] = $db_name;
    $db = \Config\Database::connect('default', true);
}

$riseConfig = config('Rise');
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

// Temporary PHP 8.5 TimeTrait patch for activity-log/session during ci_save — always restored.
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
    if ($tt_patched) {
        file_put_contents($tt, $tt_src);
    }
});

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}
function approx($a, $b, $eps = 0.011) { return abs((float)$a - (float)$b) <= $eps; }

echo "========================================================\n";
echo "Phase 17B projected award + from-date safety\n";
echo "DB: " . $db->getDatabase() . "\n";
echo "========================================================\n\n";

// --- Helper loading proof ---
echo "-- HELPER LOAD --\n";
$helper_file = APPPATH . 'Helpers/task_points_helper.php';
$lines = file($helper_file);
$load_line = 0;
foreach ($lines as $i => $line) {
    if (strpos($line, 'task_points_review_rules_helper.php') !== false) {
        $load_line = $i + 1;
        echo "require at task_points_helper.php:$load_line => " . trim($line) . "\n";
        break;
    }
}
check('helper_require_line', $load_line > 0, "line=$load_line");
check('helper_function_loaded', function_exists('is_task_points_review_rules_applicable'));
check('helper_projected_loaded', function_exists('get_task_projected_award_display'));
check('helper_valid_from_loaded', function_exists('is_valid_task_points_review_rules_from_date'));

$tasks_model = model('App\Models\Tasks_model');
$awards_model = model('App\Models\Task_point_awards_model');

$todo = (int) (model('App\Models\Task_status_model')->get_one_where(['key_name'=>'to_do','deleted'=>0])->id ?? 0);
$in_progress = (int) (model('App\Models\Task_status_model')->get_one_where(['key_name'=>'in_progress','deleted'=>0])->id ?? 0);
$in_review = (int) get_in_review_task_status_id();
$changes = (int) get_changes_task_status_id();
$done = (int) get_done_task_status_id();

$assignee = 40; $creator = 7; $collab = 68; $admin = 66;
$project_id = 626;
$start_utc = '2026-11-01 00:00:00';

function upsert_setting($name, $value) {
    global $db, $riseConfig;
    $row = $db->query("SELECT setting_name FROM rise_settings WHERE setting_name=" . $db->escape($name) . " LIMIT 1")->getRow();
    if ($row) {
        $db->table('settings')->where('setting_name', $name)->update(['setting_value' => $value, 'deleted' => 0]);
    } else {
        $db->table('settings')->insert(['setting_name' => $name, 'setting_value' => $value, 'type' => 'app', 'deleted' => 0]);
    }
    $riseConfig = config('Rise');
    $riseConfig->app_settings_array[$name] = $value;
}

function set_rules($on, $from = '2026-11-01 00:00:00', $penalty = '10') {
    upsert_setting('task_points_review_rules_enabled', $on ? '1' : '0');
    upsert_setting('task_points_review_rules_from', $from);
    upsert_setting('task_review_changes_penalty_percent', $penalty);
    upsert_setting('task_review_workflow_enabled', '1');
    upsert_setting('task_points_collab_split_from', '2020-01-01 00:00:00');
}

function make_task($opts = []) {
    global $tasks_model, $todo, $assignee, $creator, $project_id, $db;
    $now = get_current_utc_time();
    $deadline = $opts['deadline'] ?? date('Y-m-d', strtotime('+1 day'));
    $end_time = $opts['end_time'] ?? '18:00:00';
    $data = [
        'title' => $opts['title'] ?? 'P17B task',
        'description' => '',
        'project_id' => $project_id,
        'milestone_id' => 0,
        'assigned_to' => $opts['assigned_to'] ?? $assignee,
        'collaborators' => $opts['collaborators'] ?? '',
        'status_id' => $opts['status_id'] ?? $todo,
        'points' => $opts['points'] ?? 5,
        'deadline' => $deadline,
        'end_time' => $end_time,
        'created_date' => $opts['created_date'] ?? date('Y-m-d'),
        'created_by' => $creator,
        'deleted' => 0,
        'review_changes_count' => $opts['review_changes_count'] ?? 0,
    ];
    $id = $tasks_model->ci_save($data);
    return $tasks_model->get_one($id);
}

function apply_status($task, $status_id, $now = null) {
    global $tasks_model, $db;
    if (!$now) $now = get_current_utc_time();
    $data = ['status_id' => $status_id, 'status_changed_at' => $now];
    if (function_exists('apply_review_stamp_on_status_change')) {
        apply_review_stamp_on_status_change($task, $status_id, $data, $now);
    }
    if (function_exists('apply_task_points_review_rules_on_status_change')) {
        apply_task_points_review_rules_on_status_change($task, $status_id, $data, $now);
    }
    $db->table('tasks')->where('id', $task->id)->update($data);
    $t = $tasks_model->get_one($task->id);
    if (is_task_status_done($t) && function_exists('sync_task_point_award')) {
        sync_task_point_award($t->id);
    }
    return $tasks_model->get_one($task->id);
}

function add_timer($task_id, $user_id, $start_time) {
    global $db;
    $db->table('project_time')->insert([
        'project_id' => 626,
        'user_id' => $user_id,
        'task_id' => $task_id,
        'start_time' => $start_time,
        'end_time' => date('Y-m-d H:i:s', strtotime($start_time . ' +30 minutes')),
        'status' => 'logged',
        'deleted' => 0,
    ]);
}

function award_for($task_id, $user_id) {
    global $awards_model;
    $a = $awards_model->get_by_task_and_user($task_id, $user_id);
    return ($a && !empty($a->id)) ? (float) $a->earned_points : null;
}

// ========== 1. DEFAULT SAFETY ==========
echo "\n-- 1. DEFAULT SAFETY (empty/invalid from => NO tasks) --\n";
$probe = (object)['created_date' => '2026-11-16', 'status_id' => $in_review, 'submitted_for_review_at' => '2026-11-16 10:00:00'];

set_rules(true, '', '10');
check('empty_from_not_applicable', !is_task_points_review_rules_applicable($probe));
check('empty_from_invalid_helper', !is_valid_task_points_review_rules_from_date(''));

set_rules(true, 'banana', '10');
check('invalid_from_banana', !is_task_points_review_rules_applicable($probe));
check('invalid_from_banana_helper', !is_valid_task_points_review_rules_from_date('banana'));

set_rules(true, '0000-00-00', '10');
check('invalid_from_zeros', !is_task_points_review_rules_applicable($probe));

set_rules(true, '2020-13-40', '10');
check('invalid_from_bad_ymd', !is_task_points_review_rules_applicable($probe));

set_rules(true, $start_utc, '10');
check('valid_from_applies', is_task_points_review_rules_applicable($probe));

// ========== 7. UI vs APPROVE CASES ==========
echo "\n-- 7. UI projection vs approve award --\n";
echo str_pad("CASE", 28) . str_pad("UI", 10) . str_pad("APPROVE", 10) . "MATCH\n";
echo str_repeat("-", 60) . "\n";

$cases = [];

function run_case($key, $task_before_approve, $expected_ui = null) {
    global $assignee, $cases, $done;
    $proj = get_task_projected_award_display($task_before_approve, $assignee);
    $ui = (float) $proj['award'];
    $t = apply_status($task_before_approve, $done, get_current_utc_time());
    $aw = award_for($t->id, $assignee);
    $match = approx($ui, $aw);
    $cases[] = compact('key', 'ui', 'aw', 'match', 'proj');
    echo str_pad($key, 28) . str_pad((string)$ui, 10) . str_pad((string)$aw, 10) . ($match ? "YES" : "NO") . "\n";
    check("case_{$key}_ui_eq_approve", $match, "ui=$ui approve=$aw");
    if ($expected_ui !== null) {
        check("case_{$key}_expected", approx($ui, $expected_ui), "ui=$ui expected=$expected_ui");
    }
    return [$ui, $aw, $proj];
}

set_rules(true, $start_utc, '10');

// late > 72h
$deadline = date('Y-m-d', strtotime('-5 days'));
$t = make_task(['title'=>'P17B late72', 'deadline'=>$deadline, 'end_time'=>'12:00:00', 'points'=>5]);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
$t = apply_status($t, $in_review, $submit);
run_case('late_more_than_72h', $t, 0);

// on time with timer
$deadline = date('Y-m-d', strtotime('+3 days'));
$t = make_task(['title'=>'P17B ontime timer', 'deadline'=>$deadline, 'end_time'=>'23:00:00', 'points'=>5]);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
$t = apply_status($t, $in_review, $submit);
run_case('on_time_with_timer', $t, 5);

// on time with no timer
$deadline = date('Y-m-d', strtotime('+4 days'));
$t = make_task(['title'=>'P17B ontime no timer', 'deadline'=>$deadline, 'end_time'=>'23:00:00', 'points'=>5]);
$submit = get_current_utc_time();
$t = apply_status($t, $in_review, $submit);
run_case('on_time_no_timer', $t, 0);

// 1 change
$deadline = date('Y-m-d', strtotime('+5 days'));
$t = make_task(['title'=>'P17B 1 change', 'deadline'=>$deadline, 'end_time'=>'23:00:00', 'points'=>5]);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
$t = apply_status($t, $in_review, $submit);
$t = apply_status($t, $changes, get_current_utc_time());
$t = apply_status($t, $in_review, get_current_utc_time());
run_case('one_change', $t, 4.5);

// 2 changes
$deadline = date('Y-m-d', strtotime('+6 days'));
$t = make_task(['title'=>'P17B 2 changes', 'deadline'=>$deadline, 'end_time'=>'23:00:00', 'points'=>5]);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
$t = apply_status($t, $in_review, $submit);
$t = apply_status($t, $changes, get_current_utc_time());
$t = apply_status($t, $in_review, get_current_utc_time());
$t = apply_status($t, $changes, get_current_utc_time());
$t = apply_status($t, $in_review, get_current_utc_time());
run_case('two_changes', $t, 4.0);

// split task
$deadline = date('Y-m-d', strtotime('+7 days'));
$t = make_task(['title'=>'P17B split', 'deadline'=>$deadline, 'end_time'=>'23:00:00', 'points'=>6, 'collaborators'=>(string)$collab]);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
add_timer($t->id, $collab, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
$t = apply_status($t, $in_review, $submit);
$proj = get_task_projected_award_display($t, $assignee);
check('split_shows_shares', !empty($proj['split_label']) || $proj['participants_count'] > 1, json_encode($proj['participant_shares']));
$ui_a = (float)$proj['award'];
$ui_c = isset($proj['participant_shares'][$collab]) ? (float)$proj['participant_shares'][$collab]['award'] : null;
$t = apply_status($t, $done, get_current_utc_time());
$aw_a = award_for($t->id, $assignee);
$aw_c = award_for($t->id, $collab);
echo str_pad('split_task', 28) . str_pad((string)$ui_a, 10) . str_pad((string)$aw_a, 10) . (approx($ui_a,$aw_a)?'YES':'NO') . " (collab ui=$ui_c aw=$aw_c)\n";
check('case_split_assignee_match', approx($ui_a, $aw_a));
check('case_split_collab_match', approx($ui_c, $aw_c));

// flag off = lateness only
set_rules(false, $start_utc, '10');
$deadline = date('Y-m-d', strtotime('-5 days'));
$t = make_task(['title'=>'P17B flag off late', 'deadline'=>$deadline, 'end_time'=>'12:00:00', 'points'=>5]);
$submit = get_current_utc_time();
// no timer — flag off should still award 0 for >72h lateness only (not timer)
$t = apply_status($t, $in_review, $submit);
$proj = get_task_projected_award_display($t, $assignee);
check('flag_off_lateness_only', !empty($proj['lateness_only']) && empty($proj['rules_applicable']));
check('flag_off_queue_will_award', str_contains($proj['queue_label_short'], 'Will award'));
run_case('flag_off_late72', $t, 0);

// on time flag off, no timer — should still get full (no timer rule)
set_rules(false, $start_utc, '10');
$deadline = date('Y-m-d', strtotime('+8 days'));
$t = make_task(['title'=>'P17B flag off ontime', 'deadline'=>$deadline, 'end_time'=>'23:00:00', 'points'=>5]);
$t = apply_status($t, $in_review, get_current_utc_time());
run_case('flag_off_ontime_no_timer', $t, 5);

// modal wording for In review
set_rules(true, $start_utc, '10');
$deadline = date('Y-m-d', strtotime('-5 days'));
$t = make_task(['title'=>'P17B modal wording', 'deadline'=>$deadline, 'end_time'=>'12:00:00']);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
$t = apply_status($t, $in_review, $submit);
$disp = get_task_point_display($t);
check('modal_on_approval_wording', str_contains($disp['reduction_reason'], 'On approval will award'), $disp['reduction_reason']);
check('modal_not_completing_now', !str_contains($disp['reduction_reason'], 'Completing now'));

// ========== Local queue display for 25784 / 25702 / 23527 (production DB copy = sunrisecrmnew) ==========
echo "\n(Local queue for 25784/25702/23527: run tests/_tmp_phase17b_local_queue.php read-only on sunrisecrmnew — not from this scratch writer.)\n";

echo "\n========================================================\n";
echo "PASS=$pass FAIL=$fail\n";
echo "========================================================\n";
exit($fail ? 1 : 0);
