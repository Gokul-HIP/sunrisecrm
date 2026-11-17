<?php
/**
 * Phase 17C: exercise real Projects status-update endpoint code paths (same bodies as HTTP routes),
 * then read queue projection the same way tasks_in_review_list_data does.
 *
 * Usage: php tests/test_phase17c_endpoints.php
 * DB: sunrisecrmnew (local). Does not touch live.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/index.php/projects/save_task_status/0';
$_SERVER['REQUEST_METHOD'] = 'POST';
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

// TimeTrait patch for activity log during ci_save — always restored
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
echo "Phase 17C endpoint paths (Projects status routes)\n";
echo "DB=" . $db->getDatabase() . "\n";
echo "enabled=" . var_export(get_setting('task_points_review_rules_enabled'), true) . "\n";
echo "from=" . var_export(get_setting('task_points_review_rules_from'), true) . "\n";
echo "========================================================\n\n";

// Ensure rules on with today's from
function upsert_setting($name, $value) {
    global $db, $riseConfig;
    $row = $db->query("SELECT setting_name FROM rise_settings WHERE setting_name=" . $db->escape($name) . " LIMIT 1")->getRow();
    if ($row) {
        $db->table('settings')->where('setting_name', $name)->update(['setting_value' => $value, 'deleted' => 0]);
    } else {
        $db->table('settings')->insert(['setting_name' => $name, 'setting_value' => $value, 'type' => 'app', 'deleted' => 0]);
    }
    $riseConfig->app_settings_array[$name] = $value;
}
upsert_setting('task_points_review_rules_enabled', '1');
upsert_setting('task_points_review_rules_from', '2026-11-16 00:00:00');
upsert_setting('task_review_changes_penalty_percent', '10');
upsert_setting('task_review_workflow_enabled', '1');
upsert_setting('task_points_collab_split_from', '2020-01-01 00:00:00');

$tasks_model = model('App\Models\Tasks_model');
$awards_model = model('App\Models\Task_point_awards_model');
$todo = (int) (model('App\Models\Task_status_model')->get_one_where(['key_name'=>'to_do','deleted'=>0])->id ?? 0);
$in_progress = (int) (model('App\Models\Task_status_model')->get_one_where(['key_name'=>'in_progress','deleted'=>0])->id ?? 0);
$in_review = (int) get_in_review_task_status_id();
$changes = (int) get_changes_task_status_id();
$done = (int) get_done_task_status_id();

$assignee = 40; // sunriseintern01 — acts as assignee updating status
$creator = 7;
$project_id = 626;

/**
 * Exact body of Projects::save_task_status lock/stamp/save (route: projects/save_task_status/{id}).
 * File lines ~3606–3641.
 */
function route_save_task_status($task_id, $status_id) {
    global $tasks_model;
    $task_info = $tasks_model->get_details(['id' => $task_id])->getRow();
    $data = ['status_id' => $status_id];
    if ((int)$task_info->status_id !== (int)$status_id) {
        $data['status_changed_at'] = get_current_utc_time();
        if (function_exists('apply_review_stamp_on_status_change')) {
            apply_review_stamp_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
        }
        if (function_exists('apply_task_points_review_rules_on_status_change')) {
            apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
        }
    }
    $tasks_model->ci_save($data, $task_id);
    return $tasks_model->get_one($task_id);
}

/**
 * Exact body of Projects::update_task_info for status_id (modal dropdown).
 * File lines ~3698–3721.
 */
function route_update_task_info_status($task_id, $status_id) {
    global $tasks_model;
    $task_info = $tasks_model->get_one($task_id);
    $data = ['status_id' => $status_id];
    if ((int)$task_info->status_id !== (int)$status_id) {
        $data['status_changed_at'] = get_current_utc_time();
        if (function_exists('apply_review_stamp_on_status_change')) {
            apply_review_stamp_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
        }
        if (function_exists('apply_task_points_review_rules_on_status_change')) {
            apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
        }
    }
    $tasks_model->ci_save($data, $task_id);
    return $tasks_model->get_one($task_id);
}

/**
 * Exact body of Projects::save_task_sort_and_status (Kanban drag).
 * File lines ~3813–3827.
 */
function route_kanban_status($task_id, $status_id) {
    global $tasks_model;
    $task_info = $tasks_model->get_one($task_id);
    $data = ['status_id' => $status_id, 'sort' => 1];
    if ((int)$task_info->status_id !== (int)$status_id) {
        $data['status_changed_at'] = get_current_utc_time();
        if (function_exists('apply_review_stamp_on_status_change')) {
            apply_review_stamp_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
        }
        if (function_exists('apply_task_points_review_rules_on_status_change')) {
            apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $data['status_changed_at']);
        }
    }
    $tasks_model->ci_save($data, $task_id);
    return $tasks_model->get_one($task_id);
}

/**
 * Exact body of Projects::save_batch_update status branch.
 * File lines ~4919–4932.
 */
function route_batch_status($task_id, $status_id) {
    global $tasks_model;
    $task_info = $tasks_model->get_one($task_id);
    $now = get_current_utc_time();
    $data = ['status_id' => $status_id];
    if ((int)$task_info->status_id !== (int)$status_id) {
        $data['status_changed_at'] = $now;
        if (function_exists('apply_review_stamp_on_status_change')) {
            apply_review_stamp_on_status_change($task_info, $status_id, $data, $now);
        }
        if (function_exists('apply_task_points_review_rules_on_status_change')) {
            apply_task_points_review_rules_on_status_change($task_info, $status_id, $data, $now);
        }
    }
    $tasks_model->ci_save($data, $task_id);
    return $tasks_model->get_one($task_id);
}

/**
 * Create-as-In-review path from Projects::save_task (create branch + post-save lock).
 * File lines ~3347–3371.
 */
function route_create_as_in_review($opts = []) {
    global $tasks_model, $assignee, $creator, $project_id, $in_review, $todo;
    $now = get_current_utc_time();
    $data = [
        'title' => $opts['title'] ?? 'P17C create in review',
        'description' => '',
        'project_id' => $project_id,
        'milestone_id' => 0,
        'assigned_to' => $opts['assigned_to'] ?? $assignee,
        'collaborators' => $opts['collaborators'] ?? '',
        'status_id' => $in_review,
        'points' => $opts['points'] ?? 5,
        'deadline' => $opts['deadline'] ?? date('Y-m-d', strtotime('+3 days')),
        'end_time' => $opts['end_time'] ?? '23:00:00',
        'created_date' => $opts['created_date'] ?? date('Y-m-d'),
        'created_by' => $creator,
        'deleted' => 0,
        'submitted_for_review_at' => $now,
    ];
    $id = $tasks_model->ci_save($data);
    $created_task = $tasks_model->get_one($id);
    $lock_data = [];
    lock_task_points_review_rules($created_task, $lock_data, $now);
    if (!empty($lock_data)) {
        $tasks_model->ci_save($lock_data, $id);
    }
    return $tasks_model->get_one($id);
}

function make_todo_task($opts = []) {
    global $tasks_model, $todo, $assignee, $creator, $project_id;
    $data = [
        'title' => $opts['title'] ?? 'P17C task',
        'description' => '',
        'project_id' => $project_id,
        'milestone_id' => 0,
        'assigned_to' => $opts['assigned_to'] ?? $assignee,
        'collaborators' => '',
        'status_id' => $todo,
        'points' => $opts['points'] ?? 5,
        'deadline' => $opts['deadline'] ?? date('Y-m-d', strtotime('+3 days')),
        'end_time' => $opts['end_time'] ?? '23:00:00',
        'created_date' => $opts['created_date'] ?? date('Y-m-d'),
        'created_by' => $creator,
        'deleted' => 0,
    ];
    $id = $tasks_model->ci_save($data);
    return $tasks_model->get_one($id);
}

function add_timer($task_id, $user_id, $start_time) {
    global $db, $project_id;
    $db->table('project_time')->insert([
        'project_id' => $project_id,
        'user_id' => $user_id,
        'task_id' => $task_id,
        'start_time' => $start_time,
        'end_time' => date('Y-m-d H:i:s', strtotime($start_time . ' +20 minutes')),
        'status' => 'logged',
        'deleted' => 0,
    ]);
}

/** Queue projection as tasks_in_review_list_data → _make_task_in_review_row does */
function queue_projection($task_id) {
    global $tasks_model;
    // Prefer awaiting-review list shape
    $result = $tasks_model->get_details(['awaiting_review_all' => true]);
    $rows = is_object($result) ? $result->getResult() : [];
    foreach ($rows as $row) {
        if ((int)$row->id === (int)$task_id) {
            return get_task_projected_award_display($row);
        }
    }
    $row = $tasks_model->get_details(['id' => $task_id])->getRow();
    return get_task_projected_award_display($row);
}

function approve_via_route($task_id) {
    global $tasks_model, $done;
    $task_info = $tasks_model->get_one($task_id);
    $now = get_current_utc_time();
    $data = ['status_id' => $done, 'status_changed_at' => $now];
    if (function_exists('ensure_task_points_review_rules_locked')) {
        ensure_task_points_review_rules_locked($task_info);
    }
    if (function_exists('lock_task_points_review_rules') && is_task_points_review_rules_applicable($task_info)) {
        lock_task_points_review_rules($task_info, $data, $now);
    }
    // In review → Done keeps stamp (apply_review_stamp no-op clear)
    $tasks_model->ci_save($data, $task_id);
    sync_task_point_award($task_id);
    return $tasks_model->get_one($task_id);
}

function award_for($task_id, $user_id) {
    global $awards_model;
    $a = $awards_model->get_by_task_and_user($task_id, $user_id);
    return ($a && !empty($a->id)) ? (float)$a->earned_points : null;
}

echo "-- Route lock sites (Projects.php) --\n";
echo "save_task (modal save / edit): apply @3341; create-as-In-review lock @3366\n";
echo "save_task_status (Tasks list quick update): apply @3636\n";
echo "update_task_info (task modal status dropdown): apply @3713\n";
echo "save_task_sort_and_status (Kanban drag): apply @3821\n";
echo "save_batch_update (batch): apply @4929\n";
echo "request_task_changes (Changes): apply @7009; Changes→In-review uses save_task_status/update/kanban above\n";
echo "approve_task_review: ensure+lock @6964–6969\n\n";

echo "CASE                        UI         APPROVE    MATCH  LOCK_JSON\n";
echo str_repeat('-', 80) . "\n";

$cases = [];

// 1) no timer then In review via save_task_status → 0
$t = make_todo_task(['title' => 'P17C no timer list']);
$t = route_save_task_status($t->id, $in_review);
check('no_timer_locked', !empty($t->points_rules_locked_at) && $t->timer_lock_json !== null && $t->timer_lock_json !== '', 'lock=' . var_export($t->timer_lock_json, true));
$map = json_decode($t->timer_lock_json ?: '{}', true);
check('no_timer_map_zero', isset($map[(string)$assignee]) && (int)$map[(string)$assignee] === 0, json_encode($map));
$proj = queue_projection($t->id);
$ui = (float)$proj['award'];
approve_via_route($t->id);
$aw = award_for($t->id, $assignee);
echo str_pad('no_timer_in_review', 28) . str_pad((string)$ui, 11) . str_pad((string)$aw, 11) . (approx($ui, $aw) ? 'YES' : 'NO') . "  " . $t->timer_lock_json . "\n";
check('no_timer_ui_eq_approve', approx($ui, $aw) && approx($ui, 0), "ui=$ui aw=$aw");
check('no_timer_queue_label', str_contains($proj['queue_label_short'], 'Will award 0 of 5'), $proj['queue_label_short'] . ' / ' . $proj['reason']);

// 2) timer started+stopped then In review via kanban → full
$t = make_todo_task(['title' => 'P17C with timer kanban']);
$now = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($now . ' -1 hour')));
$t = route_kanban_status($t->id, $in_review);
$proj = queue_projection($t->id);
$ui = (float)$proj['award'];
approve_via_route($t->id);
$aw = award_for($t->id, $assignee);
echo str_pad('timer_then_in_review', 28) . str_pad((string)$ui, 11) . str_pad((string)$aw, 11) . (approx($ui, $aw) ? 'YES' : 'NO') . "  " . $t->timer_lock_json . "\n";
check('timer_ui_eq_approve', approx($ui, $aw) && approx($ui, 5), "ui=$ui aw=$aw lock={$t->timer_lock_json}");

// 3) late via update_task_info (modal) → reduced (100% if >72h)
$t = make_todo_task(['title' => 'P17C late modal', 'deadline' => date('Y-m-d', strtotime('-5 days')), 'end_time' => '12:00:00']);
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime(get_current_utc_time() . ' -1 hour')));
$t = route_update_task_info_status($t->id, $in_review);
$proj = queue_projection($t->id);
$ui = (float)$proj['award'];
approve_via_route($t->id);
$aw = award_for($t->id, $assignee);
echo str_pad('late_gt_72h', 28) . str_pad((string)$ui, 11) . str_pad((string)$aw, 11) . (approx($ui, $aw) ? 'YES' : 'NO') . "  " . $t->timer_lock_json . "\n";
check('late_ui_eq_approve', approx($ui, $aw) && approx($ui, 0), "ui=$ui aw=$aw");

// 4) one Changes round via request_task_changes path then back to In review → -10%
$t = make_todo_task(['title' => 'P17C one change']);
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime(get_current_utc_time() . ' -1 hour')));
$t = route_save_task_status($t->id, $in_review);
// request_task_changes body
$task_info = $t;
$now = get_current_utc_time();
$data = ['status_id' => $changes, 'status_changed_at' => $now];
apply_review_stamp_on_status_change($task_info, $changes, $data, $now);
apply_task_points_review_rules_on_status_change($task_info, $changes, $data, $now);
$tasks_model->ci_save($data, $t->id);
$t = route_save_task_status($t->id, $in_review); // resubmit
check('one_change_count', (int)$t->review_changes_count === 1, 'count=' . $t->review_changes_count);
$proj = queue_projection($t->id);
$ui = (float)$proj['award'];
approve_via_route($t->id);
$aw = award_for($t->id, $assignee);
echo str_pad('one_change', 28) . str_pad((string)$ui, 11) . str_pad((string)$aw, 11) . (approx($ui, $aw) ? 'YES' : 'NO') . "  changes={$t->review_changes_count}\n";
check('one_change_ui_eq_approve', approx($ui, $aw) && approx($ui, 4.5), "ui=$ui aw=$aw");

// 5) two Changes rounds
$t = make_todo_task(['title' => 'P17C two changes']);
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime(get_current_utc_time() . ' -1 hour')));
$t = route_batch_status($t->id, $in_review);
for ($i = 0; $i < 2; $i++) {
    $task_info = $tasks_model->get_one($t->id);
    $now = get_current_utc_time();
    $data = ['status_id' => $changes, 'status_changed_at' => $now];
    apply_review_stamp_on_status_change($task_info, $changes, $data, $now);
    apply_task_points_review_rules_on_status_change($task_info, $changes, $data, $now);
    $tasks_model->ci_save($data, $t->id);
    $t = route_save_task_status($t->id, $in_review);
}
check('two_change_count', (int)$t->review_changes_count === 2, 'count=' . $t->review_changes_count);
$proj = queue_projection($t->id);
$ui = (float)$proj['award'];
approve_via_route($t->id);
$aw = award_for($t->id, $assignee);
echo str_pad('two_changes', 28) . str_pad((string)$ui, 11) . str_pad((string)$aw, 11) . (approx($ui, $aw) ? 'YES' : 'NO') . "  changes={$t->review_changes_count}\n";
check('two_changes_ui_eq_approve', approx($ui, $aw) && approx($ui, 4.0), "ui=$ui aw=$aw");

// 6) create-as-In-review with no timer
$t = route_create_as_in_review(['title' => 'P17C create IR no timer']);
check('create_ir_locked', !empty($t->points_rules_locked_at), 'locked_at=' . var_export($t->points_rules_locked_at, true));
$proj = queue_projection($t->id);
$ui = (float)$proj['award'];
echo str_pad('create_in_review_no_timer', 28) . str_pad((string)$ui, 11) . str_pad('n/a', 11) . '  ' . $t->timer_lock_json . " / " . $proj['reason'] . "\n";
check('create_ir_queue_zero', approx($ui, 0) && str_contains($proj['reason'], 'No timer'), $proj['queue_label_short'] . ' ' . $proj['reason']);

// 7) lazy lock for pre-existing unlocked in-review (25785 if present)
$row = $db->query("SELECT id FROM rise_tasks WHERE id=25785")->getRow();
if ($row) {
    $before = $tasks_model->get_one(25785);
    echo "\n-- Lazy lock on display for 25785 (was locked_at=" . var_export($before->points_rules_locked_at, true) . ") --\n";
    $proj = queue_projection(25785);
    $after = $tasks_model->get_one(25785);
    echo "after locked_at=" . var_export($after->points_rules_locked_at, true) . " lock=" . var_export($after->timer_lock_json, true) . "\n";
    echo "queue={$proj['queue_label_short']} reason={$proj['reason']}\n";
    check('lazy_lock_25785', !empty($after->points_rules_locked_at), 'still unlocked');
    check('lazy_lock_25785_zero_or_reason', approx($proj['award'], 0) || str_contains($proj['reason'], 'No timer') || str_contains($proj['reason'], 'Overdue'), $proj['reason']);
}

echo "\n-- 25876/25877 --\n";
$c = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE id IN (25876,25877)")->getRow()->c;
echo "found=$c (max_id=" . $db->query("SELECT MAX(id) m FROM rise_tasks")->getRow()->m . ")\n";

echo "\n========================================================\n";
echo "PASS=$pass FAIL=$fail\n";
echo "========================================================\n";
exit($fail ? 1 : 0);
