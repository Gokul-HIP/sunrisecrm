<?php
/**
 * Phase 18B: award frozen at (first) submission — waiting must not change queue/Approve.
 * In-process route bodies (same as 17D e2e). XAMPP PHP. No live. No schema change.
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications', 'task_timer_visibility']);

$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);

function reload_settings_from_db() {
    $rise = config('Rise');
    global $db;
    foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
        $rise->app_settings_array[$s->setting_name] = $s->setting_value;
    }
}
reload_settings_from_db();
date_default_timezone_set(get_setting('timezone') ?: 'Asia/Kolkata');

$pass = 0; $fail = 0;
$rows = [];
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
        $db->table('settings')->where('setting_name', $name)->update(['setting_value' => (string)$value, 'deleted' => 0]);
    } else {
        $db->table('settings')->insert(['setting_name' => $name, 'setting_value' => (string)$value, 'type' => 'app', 'deleted' => 0]);
    }
    reload_settings_from_db();
}

$tasks_model = model('App\Models\Tasks_model');
$todo = (int)(model('App\Models\Task_status_model')->get_one_where(['key_name'=>'to_do','deleted'=>0])->id ?? 0);
$in_progress = (int)(model('App\Models\Task_status_model')->get_one_where(['key_name'=>'in_progress','deleted'=>0])->id ?? 0);
$in_review = (int) get_in_review_task_status_id();
$changes = (int) get_changes_task_status_id();
$done = (int) get_done_task_status_id();
$assignee = 40;
$creator = 7;
$project_id = 626;

function route_create($title, $deadline_date, $end_time, $created_date = null) {
    global $tasks_model, $todo, $assignee, $creator, $project_id;
    $data = [
        'title' => $title,
        'description' => 'phase18b',
        'project_id' => $project_id,
        'milestone_id' => 0,
        'assigned_to' => $assignee,
        'collaborators' => '',
        'status_id' => $todo,
        'points' => 5,
        'deadline' => $deadline_date,
        'end_time' => $end_time,
        'created_date' => $created_date ?: date('Y-m-d'),
        'created_by' => $creator,
        'deleted' => 0,
    ];
    $id = $tasks_model->ci_save($data);
    return $tasks_model->get_one($id);
}

function route_status($task_id, $status_id, $now = null) {
    global $tasks_model;
    $task_info = $tasks_model->get_details(['id' => $task_id])->getRow();
    if (!$now) $now = get_current_utc_time();
    $data = ['status_id' => $status_id, 'status_changed_at' => $now];
    if ((int)$task_info->status_id !== (int)$status_id) {
        apply_review_stamp_on_status_change($task_info, $status_id, $data, $now);
        apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $now);
    }
    $tasks_model->ci_save($data, $task_id);
    return $tasks_model->get_details(['id' => $task_id])->getRow();
}

function route_timer($task_id, $status) {
    global $project_id, $assignee;
    model('App\Models\Timesheets_model')->process_timer([
        'project_id' => $project_id,
        'user_id' => $assignee,
        'status' => $status,
        'note' => '',
        'task_id' => $task_id,
    ]);
}

function route_request_changes($task_id, $now = null) {
    global $tasks_model, $changes;
    $task_info = $tasks_model->get_one($task_id);
    if (!$now) $now = get_current_utc_time();
    $data = ['status_id' => $changes, 'status_changed_at' => $now];
    apply_review_stamp_on_status_change($task_info, $changes, $data, $now);
    apply_task_points_review_rules_on_status_change($task_info, $changes, $data, $now);
    $tasks_model->ci_save($data, $task_id);
    return $tasks_model->get_details(['id' => $task_id])->getRow();
}

/** Queue value (UI projection). */
function queue_award($task) {
    $p = get_task_projected_award_display($task);
    return (float) $p['award'];
}

/**
 * Approve-path value without mutating: same lateness inputs sync_task_point_award uses
 * after approve sets status_changed_at = now.
 */
function approve_award($task) {
    global $assignee;
    $completed_at = get_current_utc_time(); // approve always stamps status_changed_at = now
    if (function_exists('is_task_points_review_rules_applicable') && is_task_points_review_rules_applicable($task)) {
        $preview = calculate_task_participant_award_preview($task, $completed_at, false);
        if (isset($preview['participants'][$assignee])) {
            return (float) $preview['participants'][$assignee]['earned_points'];
        }
        return (float) $preview['held_total'];
    }
    $calc = calculate_task_point_result($task, $completed_at, isset($task->points) ? $task->points : 5);
    return (float) $calc['earned_points'];
}

function lateness_source($task) {
    if (function_exists('is_task_points_review_rules_applicable') && is_task_points_review_rules_applicable($task)) {
        if (!empty($task->first_review_submitted_at) && $task->first_review_submitted_at !== '0000-00-00 00:00:00') {
            return 'first_review_submitted_at=' . $task->first_review_submitted_at;
        }
        return 'first_review_fallback_submitted_for_review_at=' . ($task->submitted_for_review_at ?? '');
    }
    if (get_setting('task_review_workflow_enabled') && !empty($task->submitted_for_review_at) && $task->submitted_for_review_at !== '0000-00-00 00:00:00') {
        return 'submitted_for_review_at=' . $task->submitted_for_review_at;
    }
    return 'completed_at_or_now';
}

/**
 * Simulate waiting N days: shift deadline + submitted_for_review_at + first_review_submitted_at
 * by the same interval so relative lateness is preserved if freeze works; if code used "now",
 * approve_award would still drift vs queue — we compare both to baseline.
 */
function shift_wait_days($task_id, $days) {
    global $db, $tasks_model;
    $days = (int) $days;
    $db->query("UPDATE rise_tasks SET
        deadline = DATE_ADD(deadline, INTERVAL $days DAY),
        submitted_for_review_at = IF(submitted_for_review_at IS NULL OR submitted_for_review_at='0000-00-00 00:00:00', submitted_for_review_at, DATE_ADD(submitted_for_review_at, INTERVAL $days DAY)),
        first_review_submitted_at = IF(first_review_submitted_at IS NULL OR first_review_submitted_at='0000-00-00 00:00:00', first_review_submitted_at, DATE_ADD(first_review_submitted_at, INTERVAL $days DAY))
        WHERE id=" . (int)$task_id);
    return $tasks_model->get_details(['id' => $task_id])->getRow();
}

function record_row($scenario, $task, $expected = null) {
    global $rows;
    $q = queue_award($task);
    $a = approve_award($task);
    $match = approx($q, $a);
    $exp_ok = $expected === null ? true : approx($q, $expected);
    $rows[] = [
        'scenario' => $scenario,
        'queue' => $q,
        'approve' => $a,
        'match' => $match ? 'MATCH' : 'MISMATCH',
        'expected' => $expected,
        'exp_ok' => $exp_ok,
        'src' => lateness_source($task),
    ];
    check($scenario . ' queue==approve', $match, "q=$q a=$a");
    if ($expected !== null) {
        check($scenario . " queue==$expected", $exp_ok, "q=$q");
    }
    return [$q, $a];
}

echo "========================================================\n";
echo "Phase 18B award freeze\n";
echo "========================================================\n";

$from = date('Y-m-d') . ' 00:00:00';
upsert_setting('task_review_workflow_enabled', '1');
upsert_setting('task_review_changes_penalty_percent', '10');
upsert_setting('users_can_start_multiple_timers_at_a_time', '1');
upsert_setting('task_points_collab_split_from', '2020-01-01 00:00:00');

$tz = new DateTimeZone(get_setting('timezone') ?: 'Asia/Kolkata');
$nowLocal = new DateTime('now', $tz);

function local_deadline_in_hours($hours) {
    global $tz;
    $d = new DateTime('now', $tz);
    $d->modify('+' . (int)$hours . ' hours');
    return [$d->format('Y-m-d'), $d->format('H:i:s')];
}

function run_ontime_wait_matrix($flag) {
    global $in_review, $tasks_model;
    upsert_setting('task_points_review_rules_enabled', (string)$flag);
    upsert_setting('task_points_review_rules_from', $flag ? (date('Y-m-d') . ' 00:00:00') : '2099-01-01 00:00:00');

    list($dl, $et) = local_deadline_in_hours(1);
    $task = route_create("P18B ontime flag$flag " . date('H:i:s'), $dl, $et);
    route_timer($task->id, 'start');
    usleep(200000);
    route_timer($task->id, 'stop');
    $task = route_status($task->id, $in_review); // on time (deadline +1h)

    echo "\n--- flag=$flag ON TIME submit id={$task->id} deadline=$dl $et ---\n";
    echo "stamps submitted={$task->submitted_for_review_at} first_review={$task->first_review_submitted_at}\n";

    list($q0, $a0) = record_row("flag$flag ontime wait0d", $task, 5.0);
    $baseline = $q0;

    foreach ([1, 3, 10] as $days) {
        // cumulative from original: reset by re-adding only the step delta from previous
        // easier: reload original stamps from baseline wait0 then add $days from a stored copy
    }

    // Store originals then apply absolute waits from submission baseline
    $base = $tasks_model->get_one($task->id);
    $base_dl = $base->deadline;
    $base_sub = $base->submitted_for_review_at;
    $base_fr = $base->first_review_submitted_at;

    foreach ([1, 3, 10] as $days) {
        global $db;
        $db->query("UPDATE rise_tasks SET
            deadline = DATE_ADD(" . $db->escape($base_dl) . ", INTERVAL $days DAY),
            submitted_for_review_at = " . ($base_sub ? ("DATE_ADD(" . $db->escape($base_sub) . ", INTERVAL $days DAY)") : "NULL") . ",
            first_review_submitted_at = " . ($base_fr && $base_fr !== '0000-00-00 00:00:00' ? ("DATE_ADD(" . $db->escape($base_fr) . ", INTERVAL $days DAY)") : "NULL") . "
            WHERE id=" . (int)$task->id);
        $t = $tasks_model->get_details(['id' => $task->id])->getRow();
        record_row("flag$flag ontime wait{$days}d", $t, $baseline);
    }
}

function run_late_wait_matrix($flag, $hours_late, $expected) {
    global $in_review, $tasks_model, $db;
    $tz = new DateTimeZone(get_setting('timezone') ?: 'Asia/Kolkata');
    upsert_setting('task_points_review_rules_enabled', (string)$flag);
    upsert_setting('task_points_review_rules_from', $flag ? (date('Y-m-d') . ' 00:00:00') : '2099-01-01 00:00:00');

    // Deadline in the past by $hours_late relative to "now", then submit now
    $dlDt = new DateTime('now', $tz);
    $dlDt->modify('-' . (int)$hours_late . ' hours');
    $task = route_create("P18B late{$hours_late}h flag$flag " . date('H:i:s'), $dlDt->format('Y-m-d'), $dlDt->format('H:i:s'));
    route_timer($task->id, 'start');
    usleep(150000);
    route_timer($task->id, 'stop');
    $task = route_status($task->id, $in_review);

    echo "\n--- flag=$flag LATE {$hours_late}h id={$task->id} expect=$expected ---\n";
    list($q0,) = record_row("flag$flag late{$hours_late}h wait0d", $task, $expected);
    $base = $tasks_model->get_one($task->id);
    $base_dl = $base->deadline;
    $base_sub = $base->submitted_for_review_at;
    $base_fr = $base->first_review_submitted_at;

    foreach ([3, 10] as $days) {
        $db->query("UPDATE rise_tasks SET
            deadline = DATE_ADD(" . $db->escape($base_dl) . ", INTERVAL $days DAY),
            submitted_for_review_at = " . ($base_sub ? ("DATE_ADD(" . $db->escape($base_sub) . ", INTERVAL $days DAY)") : "NULL") . ",
            first_review_submitted_at = " . ($base_fr && $base_fr !== '0000-00-00 00:00:00' ? ("DATE_ADD(" . $db->escape($base_fr) . ", INTERVAL $days DAY)") : "NULL") . "
            WHERE id=" . (int)$task->id);
        $t = $tasks_model->get_details(['id' => $task->id])->getRow();
        record_row("flag$flag late{$hours_late}h wait{$days}d", $t, $q0);
    }
}

// 1 + 2
run_ontime_wait_matrix(1);
run_ontime_wait_matrix(0);
run_late_wait_matrix(1, 10, 4.5);
run_late_wait_matrix(0, 10, 4.5);
run_late_wait_matrix(1, 80, 0.0);
run_late_wait_matrix(0, 80, 0.0);

// 3) Changes + resubmit 3d after deadline
echo "\n--- Changes / resubmit scenarios ---\n";
foreach ([1, 0] as $flag) {
    upsert_setting('task_points_review_rules_enabled', (string)$flag);
    upsert_setting('task_points_review_rules_from', $flag ? (date('Y-m-d') . ' 00:00:00') : '2099-01-01 00:00:00');

    list($dl, $et) = local_deadline_in_hours(1);
    $task = route_create("P18B changes flag$flag " . date('H:i:s'), $dl, $et);
    route_timer($task->id, 'start');
    usleep(150000);
    route_timer($task->id, 'stop');
    $task = route_status($task->id, $in_review); // on time
    $first_review = $task->first_review_submitted_at;
    $first_sub = $task->submitted_for_review_at;
    echo "flag=$flag first_review=$first_review submitted=$first_sub\n";

    $task = route_request_changes($task->id);
    echo "after changes: first_review={$task->first_review_submitted_at} submitted=" . var_export($task->submitted_for_review_at, true) . " changes={$task->review_changes_count}\n";

    // Resubmit 3 days after deadline
    $dlLocal = new DateTime($dl . ' ' . $et, $tz);
    $resubmitLocal = clone $dlLocal;
    $resubmitLocal->modify('+3 days');
    $resubmitLocal->setTimezone(new DateTimeZone('UTC'));
    $resubmitUtc = $resubmitLocal->format('Y-m-d H:i:s');
    $task = route_status($task->id, $in_review, $resubmitUtc);
    echo "after resubmit@$resubmitUtc: first_review={$task->first_review_submitted_at} submitted={$task->submitted_for_review_at} changes={$task->review_changes_count}\n";
    echo "lateness_reads: " . lateness_source($task) . "\n";

    // Flag1: penalty only (10% of 5 = 0.5) => 4.5, no lateness
    // Flag0: lateness from new submitted_for_review_at (3d late) => 0 points typically (72h+)
    $expect = ($flag === 1) ? 4.5 : 0.0;
    record_row("flag$flag changes_resubmit_3d_after_deadline", $task, $expect);
}

echo "\n=== TABLE ===\n";
printf("%-48s %8s %8s %10s %8s\n", "scenario", "queue", "Approve", "MATCH", "expect");
foreach ($rows as $r) {
    printf("%-48s %8s %8s %10s %8s\n",
        $r['scenario'],
        format_task_point_value($r['queue']),
        format_task_point_value($r['approve']),
        $r['match'],
        $r['expected'] === null ? '-' : format_task_point_value($r['expected'])
    );
}

echo "\n=== APPROVE LATENESS SOURCE (code) ===\n";
echo "Projects::approve_task_review sets status_changed_at = get_current_utc_time() at Projects.php:7029-7033\n";
echo "sync_task_point_award uses status_changed_at as completed_at at task_points_helper.php:626-628\n";
echo "When rules apply: calculate_task_participant_award_preview overrides with first_review_submitted_at at task_points_review_rules_helper.php:328-335\n";
echo "When rules off: calculate_task_point_result uses submitted_for_review_at (workflow on) at task_points_helper.php:310-316 — NOT completed_at/now\n";
echo "Fallback to now only if no stamp: task_points_helper.php:320-325\n";

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
