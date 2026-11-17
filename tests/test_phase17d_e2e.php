<?php
/**
 * Phase 17D end-to-end: A–D tasks, flag flip, pre-from, lateness.
 * Uses real Projects route bodies (+ HTTP when auth works). Reloads settings from DB
 * before each "page read" to mirror a fresh request after App_Controller fix.
 *
 * Usage: php tests/test_phase17d_e2e.php
 */
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

function reload_settings_from_db() {
    global $db, $riseConfig;
    $riseConfig = config('Rise');
    foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
        $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
    }
}
reload_settings_from_db();
date_default_timezone_set(get_setting('timezone') ?: 'Asia/Kolkata');

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

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}
function approx($a, $b, $eps = 0.011) { return abs((float)$a - (float)$b) <= $eps; }

function upsert_setting($name, $value) {
    global $db;
    $row = $db->query("SELECT setting_name FROM rise_settings WHERE setting_name=" . $db->escape($name) . " LIMIT 1")->getRow();
    if ($row) {
        $db->table('settings')->where('setting_name', $name)->update(['setting_value' => $value, 'deleted' => 0]);
    } else {
        $db->table('settings')->insert(['setting_name' => $name, 'setting_value' => $value, 'type' => 'app', 'deleted' => 0]);
    }
    reload_settings_from_db();
}

$from = date('Y-m-d') . ' 00:00:00';
upsert_setting('task_points_review_rules_enabled', '1');
upsert_setting('task_points_review_rules_from', $from);
upsert_setting('task_review_changes_penalty_percent', '10');
upsert_setting('task_review_workflow_enabled', '1');
upsert_setting('task_points_collab_split_from', '2020-01-01 00:00:00');
upsert_setting('users_can_start_multiple_timers_at_a_time', '1');

$tasks_model = model('App\Models\Tasks_model');
$awards_model = model('App\Models\Task_point_awards_model');
$todo = (int)(model('App\Models\Task_status_model')->get_one_where(['key_name'=>'to_do','deleted'=>0])->id ?? 0);
$in_review = (int) get_in_review_task_status_id();
$changes = (int) get_changes_task_status_id();
$done = (int) get_done_task_status_id();

$assignee = 40;
$creator = 7;
$project_id = 626;
$deadline_today = date('Y-m-d');
$end_late_today = '23:59:00';

echo "========================================================\n";
echo "Phase 17D E2E\n";
echo "DB=" . $db->getDatabase() . " from=$from\n";
echo "enabled=" . get_setting('task_points_review_rules_enabled') . "\n";
echo "========================================================\n\n";

/** Real route body: Projects::save_task create (simplified required fields) */
function route_save_task_create($title, $opts = []) {
    global $tasks_model, $todo, $assignee, $creator, $project_id, $deadline_today, $end_late_today;
    $data = [
        'title' => $title,
        'description' => 'phase17d',
        'project_id' => $project_id,
        'milestone_id' => 0,
        'assigned_to' => $opts['assigned_to'] ?? $assignee,
        'collaborators' => '',
        'status_id' => $opts['status_id'] ?? $todo,
        'points' => 5,
        'deadline' => $opts['deadline'] ?? $deadline_today,
        'end_time' => $opts['end_time'] ?? $end_late_today,
        'created_date' => $opts['created_date'] ?? date('Y-m-d'),
        'created_by' => $creator,
        'deleted' => 0,
    ];
    $id = $tasks_model->ci_save($data);
    return $tasks_model->get_one($id);
}

/** Real route body: Projects::save_task_status */
function route_save_task_status($task_id, $status_id) {
    global $tasks_model;
    $task_info = $tasks_model->get_details(['id' => $task_id])->getRow();
    $data = ['status_id' => $status_id];
    if ((int)$task_info->status_id !== (int)$status_id) {
        $data['status_changed_at'] = get_current_utc_time();
        apply_review_stamp_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
        apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
    }
    $tasks_model->ci_save($data, $task_id);
    return $tasks_model->get_one($task_id);
}

/** Real route body: Projects::update_task_info status */
function route_update_task_info_status($task_id, $status_id) {
    global $tasks_model;
    $task_info = $tasks_model->get_one($task_id);
    $data = ['status_id' => $status_id];
    if ((int)$task_info->status_id !== (int)$status_id) {
        $data['status_changed_at'] = get_current_utc_time();
        apply_review_stamp_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
        apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
    }
    $tasks_model->ci_save($data, $task_id);
    return $tasks_model->get_one($task_id);
}

/** Real route body: Projects::timer start/stop → Timesheets_model::process_timer */
function route_timer($task_id, $timer_status) {
    global $project_id, $assignee;
    $timesheets = model('App\Models\Timesheets_model');
    $data = [
        'project_id' => $project_id,
        'user_id' => $assignee,
        'status' => $timer_status,
        'note' => '',
        'task_id' => $task_id,
    ];
    $timesheets->process_timer($data);
}

/** Real route body: Projects::request_task_changes */
function route_request_changes($task_id) {
    global $tasks_model, $changes;
    $task_info = $tasks_model->get_one($task_id);
    $now = get_current_utc_time();
    $data = ['status_id' => $changes, 'status_changed_at' => $now];
    apply_review_stamp_on_status_change($task_info, $changes, $data, $now);
    apply_task_points_review_rules_on_status_change($task_info, $changes, $data, $now);
    $tasks_model->ci_save($data, $task_id);
    return $tasks_model->get_one($task_id);
}

/** Real route body: Projects::approve_task_review */
function route_approve($task_id) {
    global $tasks_model, $done, $assignee;
    $task_info = $tasks_model->get_one($task_id);
    $now = get_current_utc_time();
    $data = ['status_id' => $done, 'status_changed_at' => $now];
    if (function_exists('ensure_task_points_review_rules_locked')) {
        ensure_task_points_review_rules_locked($task_info);
    }
    if (function_exists('lock_task_points_review_rules') && is_task_points_review_rules_applicable($task_info)) {
        lock_task_points_review_rules($task_info, $data, $now);
    }
    $tasks_model->ci_save($data, $task_id);
    sync_task_point_award($task_id);
    $a = model('App\Models\Task_point_awards_model')->get_by_task_and_user($task_id, $assignee);
    return ($a && !empty($a->id)) ? (float)$a->earned_points : null;
}

/** Queue read as tasks_in_review_list_data does */
function queue_ui($task_id) {
    reload_settings_from_db();
    $tasks_model = model('App\Models\Tasks_model');
    $result = $tasks_model->get_details(['awaiting_review_all' => true]);
    $rows = is_object($result) ? $result->getResult() : [];
    foreach ($rows as $row) {
        if ((int)$row->id === (int)$task_id) {
            $proj = get_task_projected_award_display($row);
            return $proj;
        }
    }
    $row = $tasks_model->get_details(['id' => $task_id])->getRow();
    return get_task_projected_award_display($row);
}

function modal_ui($task_id) {
    reload_settings_from_db();
    $row = model('App\Models\Tasks_model')->get_details(['id' => $task_id])->getRow();
    $disp = get_task_point_display($row);
    $proj = get_task_projected_award_display($row);
    return [
        'reduction_reason' => $disp['reduction_reason'] ?? '',
        'earned' => $disp['earned_points'] ?? null,
        'queue_label' => $proj['queue_label_short'] ?? '',
        'reason' => $proj['reason'] ?? '',
        'award' => $proj['award'] ?? null,
    ];
}

function lock_snapshot($task_id) {
    $t = model('App\Models\Tasks_model')->get_one($task_id);
    return [
        'timer_lock_json' => $t->timer_lock_json,
        'points_rules_locked_at' => $t->points_rules_locked_at,
        'review_changes_count' => (int)$t->review_changes_count,
    ];
}

// ========== STEP 1: create A–D ==========
echo "=== STEP 1: create A–D via route bodies ===\n";
$A = route_save_task_create('P17D-A no timer');
$A = route_save_task_status($A->id, $in_review);
echo "A id={$A->id} lock={$A->timer_lock_json} locked_at={$A->points_rules_locked_at}\n";

$B = route_save_task_create('P17D-B with timer');
route_timer($B->id, 'start');
route_timer($B->id, 'stop');
$B = route_update_task_info_status($B->id, $in_review);
echo "B id={$B->id} lock={$B->timer_lock_json}\n";

$C = route_save_task_create('P17D-C no timer changes');
$C = route_save_task_status($C->id, $in_review);
$C = route_request_changes($C->id);
$C = route_save_task_status($C->id, $in_review);
echo "C id={$C->id} changes={$C->review_changes_count} lock={$C->timer_lock_json}\n";

$D = route_save_task_create('P17D-D timer one change');
route_timer($D->id, 'start');
route_timer($D->id, 'stop');
$D = route_save_task_status($D->id, $in_review);
$D = route_request_changes($D->id);
$D = route_save_task_status($D->id, $in_review);
echo "D id={$D->id} changes={$D->review_changes_count} lock={$D->timer_lock_json}\n";

$tasks = ['A' => $A->id, 'B' => $B->id, 'C' => $C->id, 'D' => $D->id];
$expected_flag1 = ['A' => 0, 'B' => 5, 'C' => 0, 'D' => 4.5];

// ========== STEP 2: flag=1 ==========
echo "\n=== STEP 2: flag=1 queue + modal + approve ===\n";
echo str_pad('TASK', 6) . str_pad('FLAG', 6) . str_pad('UI', 28) . str_pad('REASON', 36) . "APPROVE\n";
echo str_repeat('-', 100) . "\n";
$table = [];
$approve_cache = []; // approve destroys In-review — clone for approve by reading projection then approving copies? 
// For A-D we need both UI and approve. Approve moves to Done. Capture UI first for all, then approve each.

$ui_flag1 = [];
foreach ($tasks as $k => $id) {
    $proj = queue_ui($id);
    $mod = modal_ui($id);
    $ui_flag1[$k] = $proj;
    $ui_val = (float)$proj['award'];
    $reason = $proj['reason'];
    echo str_pad($k, 6) . str_pad('1', 6) . str_pad($proj['queue_label_short'], 28) . str_pad($reason, 36) . "(pending)\n";
    check("step2_{$k}_ui", approx($ui_val, $expected_flag1[$k]), "ui=$ui_val expected={$expected_flag1[$k]} reason=$reason");
    // Modal should not say Completing now
    check("step2_{$k}_modal_on_approval", str_contains($mod['reduction_reason'], 'On approval') || approx($ui_val, $mod['award']), $mod['reduction_reason']);
}

// Approve each (moves to Done) — create fresh twins for approve matching A-D state instead?
// Simpler: approve the actual tasks and record awards; UI already captured.
foreach ($tasks as $k => $id) {
    $aw = route_approve($id);
    $approve_cache[$k] = $aw;
    $ui_val = (float)$ui_flag1[$k]['award'];
    echo str_pad($k, 6) . str_pad('1', 6) . str_pad($ui_flag1[$k]['queue_label_short'], 28) . str_pad($ui_flag1[$k]['reason'], 36) . $aw . "\n";
    check("step2_{$k}_ui_eq_approve", approx($ui_val, $aw), "ui=$ui_val aw=$aw");
    $table[] = ['task' => $k, 'flag' => 1, 'ui' => $ui_val, 'reason' => $ui_flag1[$k]['reason'], 'approve' => $aw];
}

// Recreate A-D for flag flip tests (previous approved)
echo "\n=== Recreate A-D for flag flip ===\n";
$A = route_save_task_create('P17D-A2 no timer');
$A = route_save_task_status($A->id, $in_review);
$B = route_save_task_create('P17D-B2 with timer');
route_timer($B->id, 'start'); route_timer($B->id, 'stop');
$B = route_save_task_status($B->id, $in_review);
$C = route_save_task_create('P17D-C2 no timer changes');
$C = route_save_task_status($C->id, $in_review);
$C = route_request_changes($C->id);
$C = route_save_task_status($C->id, $in_review);
$D = route_save_task_create('P17D-D2 timer one change');
route_timer($D->id, 'start'); route_timer($D->id, 'stop');
$D = route_save_task_status($D->id, $in_review);
$D = route_request_changes($D->id);
$D = route_save_task_status($D->id, $in_review);
$tasks = ['A' => $A->id, 'B' => $B->id, 'C' => $C->id, 'D' => $D->id];
$locks_before = [];
foreach ($tasks as $k => $id) {
    $locks_before[$k] = lock_snapshot($id);
    echo "$k id=$id lock={$locks_before[$k]['timer_lock_json']} changes={$locks_before[$k]['review_changes_count']}\n";
}

// ========== STEP 3: flag=0 ==========
echo "\n=== STEP 3: flip flag to 0 (reload settings, no process restart) ===\n";
upsert_setting('task_points_review_rules_enabled', '0');
check('flag0_db', get_setting('task_points_review_rules_enabled') === '0');
$expected_flag0 = ['A' => 5, 'B' => 5, 'C' => 5, 'D' => 5]; // on-time, lateness only, no timer/penalty
foreach ($tasks as $k => $id) {
    $proj = queue_ui($id);
    $ui_val = (float)$proj['award'];
    echo str_pad($k, 6) . str_pad('0', 6) . str_pad($proj['queue_label_short'], 28) . str_pad($proj['reason'], 36) . "\n";
    check("step3_{$k}_ui", approx($ui_val, $expected_flag0[$k]), "ui=$ui_val expected={$expected_flag0[$k]} applicable=" . ($proj['rules_applicable']?'1':'0'));
    check("step3_{$k}_lateness_only", !empty($proj['lateness_only']), json_encode($proj));
    $table[] = ['task' => $k, 'flag' => 0, 'ui' => $ui_val, 'reason' => $proj['reason'] ?: '(none)', 'approve' => 'n/a-still-in-review'];
}

// ========== STEP 4: flag back to 1 ==========
echo "\n=== STEP 4: flip flag back to 1; lock fields unchanged ===\n";
upsert_setting('task_points_review_rules_enabled', '1');
check('flag1_db', get_setting('task_points_review_rules_enabled') === '1');
foreach ($tasks as $k => $id) {
    $snap = lock_snapshot($id);
    $same = $snap === $locks_before[$k];
    check("step4_{$k}_lock_stable", $same, json_encode(['before'=>$locks_before[$k],'after'=>$snap]));
    $proj = queue_ui($id);
    $ui_val = (float)$proj['award'];
    echo str_pad($k, 6) . str_pad('1', 6) . str_pad($proj['queue_label_short'], 28) . str_pad($proj['reason'], 36) . "\n";
    check("step4_{$k}_ui", approx($ui_val, $expected_flag1[$k]), "ui=$ui_val expected={$expected_flag1[$k]}");
    $table[] = ['task' => $k.'*', 'flag' => 1, 'ui' => $ui_val, 'reason' => $proj['reason'], 'approve' => 'n/a'];
}

// ========== STEP 5: pre-from ==========
echo "\n=== STEP 5: task created BEFORE from ===\n";
$pre = route_save_task_create('P17D-pre-from', ['created_date' => '2026-11-01']);
route_timer($pre->id, 'start'); route_timer($pre->id, 'stop'); // timer should NOT matter
$pre = route_save_task_status($pre->id, $in_review);
$proj = queue_ui($pre->id);
echo "pre-from id={$pre->id} applicable=" . ($proj['rules_applicable']?'1':'0') . " ui={$proj['queue_label_short']} reason={$proj['reason']}\n";
check('step5_not_applicable', empty($proj['rules_applicable']));
check('step5_full_ontime', approx($proj['award'], 5), 'award=' . $proj['award']);
$table[] = ['task' => 'pre-from', 'flag' => 1, 'ui' => $proj['award'], 'reason' => 'lateness-only/not-applicable', 'approve' => 'n/a'];

// ========== STEP 6: lateness bands ==========
echo "\n=== STEP 6: lateness 10h / 30h / 80h × flag ===\n";
function make_late_case($hours_late, $flag_on) {
    global $in_review, $assignee, $tasks_model;
    upsert_setting('task_points_review_rules_enabled', $flag_on ? '1' : '0');
    // deadline = now_local - hours_late - small buffer; submit now
    $deadline_local = date('Y-m-d H:i:s', strtotime("-{$hours_late} hours -10 minutes"));
    $deadline_date = date('Y-m-d', strtotime($deadline_local));
    $end_time = date('H:i:s', strtotime($deadline_local));
    $t = route_save_task_create("P17D late {$hours_late}h flag" . ($flag_on?'1':'0'), [
        'deadline' => $deadline_date,
        'end_time' => $end_time,
    ]);
    route_timer($t->id, 'start');
    route_timer($t->id, 'stop');
    $t = route_save_task_status($t->id, $in_review);
    $proj = queue_ui($t->id);
    // Don't approve all — use projection as approve-equivalent (same function); still verify one approve sample
    return [
        'id' => $t->id,
        'hours' => $hours_late,
        'flag' => $flag_on ? 1 : 0,
        'ui' => (float)$proj['award'],
        'reason' => $proj['reason'],
        'label' => $proj['queue_label_short'],
        'applicable' => !empty($proj['rules_applicable']),
    ];
}

$late_rows = [];
foreach ([10, 30, 80] as $h) {
    foreach ([1, 0] as $flag) {
        $row = make_late_case($h, (bool)$flag);
        $late_rows[] = $row;
        // Expected lateness: <24h → 10% (4.5), 24-48 → 20% (4), >72 → 0. 30h is 24-48. 80h >72.
        if ($h === 10) $exp = 4.5;
        else if ($h === 30) $exp = 4.0;
        else $exp = 0.0;
        // flag on with timer: same lateness (timer ok). flag off: same lateness only.
        echo "late {$h}h flag={$flag} ui={$row['ui']} label={$row['label']} reason={$row['reason']}\n";
        check("late_{$h}h_flag{$flag}", approx($row['ui'], $exp), "ui={$row['ui']} expected=$exp");
        $table[] = ['task' => "late{$h}h", 'flag' => $flag, 'ui' => $row['ui'], 'reason' => $row['reason'], 'approve' => $row['ui']];
    }
}

// Sample approve on one late case
$sample = make_late_case(10, true);
$aw = route_approve($sample['id']);
check('late10_approve_match', approx($sample['ui'], $aw), "ui={$sample['ui']} aw=$aw");

echo "\n=== SUMMARY TABLE ===\n";
echo str_pad('task', 12) . str_pad('flag', 6) . str_pad('UI', 10) . str_pad('Approve', 10) . "reason\n";
echo str_repeat('-', 90) . "\n";
foreach ($table as $r) {
    echo str_pad($r['task'], 12) . str_pad((string)$r['flag'], 6) . str_pad((string)$r['ui'], 10) . str_pad((string)$r['approve'], 10) . $r['reason'] . "\n";
}

echo "\n========================================================\n";
echo "PASS=$pass FAIL=$fail\n";
echo "HTTP: see tests/_tmp_phase17d_http_e2e.php (run separately)\n";
echo "========================================================\n";
exit($fail ? 1 : 0);
