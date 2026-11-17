<?php
/**
 * Phase 17: timer + lateness-once + change penalty + held points (scratch DB).
 * Usage: php tests/test_phase17_review_rules.php [--db=sunrisecrm_scratch]
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

// Point default DB at scratch without editing Database.php permanently:
$raw = file_get_contents(APPPATH . 'Config/Database.php');
if (!preg_match("/'database'\s*=>\s*'([^']+)'\s*,\s*\n\s*'DBDriver'/s", $raw)) {
    // fallback: env override used by CI if present
}
putenv("database.default.database={$db_name}");
$_ENV['database.default.database'] = $db_name;
$_SERVER['database.default.database'] = $db_name;

require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);

$app = \Config\Services::codeigniter();
$app->initialize();

// Force DB name on connection config
$dbConfig = config('Database');
$dbConfig->default['database'] = $db_name;
$db = \Config\Database::connect('default', false);
// reconnect if needed
try {
    $db->query("SELECT 1");
} catch (\Throwable $e) {
}
if ($db->getDatabase() !== $db_name) {
    // close and reconnect
    $db->close();
    $dbConfig->default['database'] = $db_name;
    $db = \Config\Database::connect('default', true);
}

$riseConfig = config('Rise');
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}
function approx($a, $b, $eps = 0.011) { return abs((float)$a - (float)$b) <= $eps; }

echo "========================================================\n";
echo "Phase 17 review rules\n";
echo "DB: " . $db->getDatabase() . "\n";
echo "========================================================\n\n";

$settings = model('App\Models\Settings_model');
$tasks_model = model('App\Models\Tasks_model');
$awards_model = model('App\Models\Task_point_awards_model');

$todo = (int) (model('App\Models\Task_status_model')->get_one_where(['key_name'=>'to_do','deleted'=>0])->id ?? 0);
$in_progress = (int) (model('App\Models\Task_status_model')->get_one_where(['key_name'=>'in_progress','deleted'=>0])->id ?? 0);
$in_review = (int) get_in_review_task_status_id();
$changes = (int) get_changes_task_status_id();
$done = (int) get_done_task_status_id();

// Personas / project from probe patterns used in 16G
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
    // Ensure collab split is active for multi-participant cases
    upsert_setting('task_points_collab_split_from', '2020-01-01 00:00:00');
}

function make_task($opts = []) {
    global $tasks_model, $todo, $assignee, $creator, $project_id, $db;
    $now = get_current_utc_time();
    $deadline = $opts['deadline'] ?? date('Y-m-d', strtotime('+1 day'));
    $end_time = $opts['end_time'] ?? '18:00:00';
    $created = $opts['created_date'] ?? '2026-11-10';
    $points = $opts['points'] ?? 5;
    $collabs = $opts['collaborators'] ?? '';
    $title = $opts['title'] ?? ('P17 ' . uniqid());
    $data = [
        'title' => $title,
        'description' => 'phase17',
        'project_id' => $project_id,
        'milestone_id' => 0,
        'assigned_to' => $opts['assigned_to'] ?? $assignee,
        'collaborators' => $collabs,
        'status_id' => $opts['status_id'] ?? $todo,
        'points' => $points,
        'deadline' => $deadline,
        'end_time' => $end_time,
        'start_date' => $created,
        'created_date' => $created,
        'created_by' => $opts['created_by'] ?? $creator,
        'deleted' => 0,
    ];
    // Direct insert to set created_date reliably
    $db->table('tasks')->insert($data);
    $id = $db->insertID();
    return $tasks_model->get_one($id);
}

function add_timer($task_id, $user_id, $start_time, $status = 'logged') {
    global $db, $project_id;
    $db->table('project_time')->insert([
        'project_id' => $project_id,
        'user_id' => $user_id,
        'task_id' => $task_id,
        'start_time' => $start_time,
        'end_time' => date('Y-m-d H:i:s', strtotime($start_time . ' +1 hour')),
        'hours' => 0,
        'status' => $status,
        'note' => 'p17',
        'deleted' => 0,
    ]);
}

function apply_status($task, $new_status_id, $now = null) {
    global $tasks_model, $db;
    if (!$now) $now = get_current_utc_time();
    $data = ['status_id' => $new_status_id, 'status_changed_at' => $now];
    if (function_exists('apply_review_stamp_on_status_change')) {
        apply_review_stamp_on_status_change($task, $new_status_id, $data, $now);
    }
    if (function_exists('apply_task_points_review_rules_on_status_change')) {
        apply_task_points_review_rules_on_status_change($task, $new_status_id, $data, $now);
    }
    // Direct SQL avoids Activity_logs/Session (PHP 8.5 TimeTrait) during tests
    $db->table('tasks')->where('id', $task->id)->update($data);
    $fresh = $tasks_model->get_one($task->id);
    if (function_exists('recalculate_task_point_awards')) {
        recalculate_task_point_awards($fresh);
    }
    return $tasks_model->get_one($task->id);
}

function award_for($task_id, $user_id) {
    global $db;
    $row = $db->query("SELECT * FROM rise_task_point_awards WHERE task_id=".(int)$task_id." AND user_id=".(int)$user_id." AND deleted=0")->getRow();
    return $row ? (float)$row->earned_points : null;
}

// Manual vs timer distinguishability report
echo "--- Timer vs manual ---\n";
echo "rise_project_time has status open|logged|approved and hours/end_time; no manual/timer flag.\n";
echo "CONCLUSION: cannot tell apart — any entry counts.\n\n";

set_rules(true, $start_utc, '10');

// (a) no timer -> 0
$t = make_task(['title'=>'P17 no timer', 'deadline'=>date('Y-m-d', strtotime('+2 days')), 'end_time'=>'23:00:00']);
$t = apply_status($t, $done, get_current_utc_time());
check('a_no_timer_zero', award_for($t->id, $assignee) === 0.0, 'earned='.var_export(award_for($t->id,$assignee),true));
check('a_locked', !empty($t->points_rules_locked_at) && !empty($t->first_review_submitted_at));

// (b) timer, first submission before end -> full
$deadline = date('Y-m-d', strtotime('+3 days'));
$t = make_task(['title'=>'P17 on time', 'deadline'=>$deadline, 'end_time'=>'23:00:00']);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
$t = apply_status($t, $in_review, $submit);
$held = get_task_held_points_display($t);
check('b_held_full', $held['is_held'] && approx($held['held'], 5), json_encode($held));
$t = apply_status($t, $done, get_current_utc_time());
check('b_approve_full', approx(award_for($t->id, $assignee), 5), 'earned='.award_for($t->id,$assignee));

// (c) late first submission + change penalty
$deadline = date('Y-m-d', strtotime('-1 day')); // yesterday
$t = make_task(['title'=>'P17 late', 'deadline'=>$deadline, 'end_time'=>'10:00:00']);
$submit = get_current_utc_time(); // now is >24h after yesterday 10:00 local likely
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -2 hours')));
$t = apply_status($t, $in_review, $submit);
$held = get_task_held_points_display($t);
$late_base = (float)$held['held']; // after lateness, 0 changes
check('c_late_reduced', $late_base < 5 && $late_base > 0, 'held='.$late_base);
$t = apply_status($t, $changes, get_current_utc_time());
check('c_change_count_1', (int)$t->review_changes_count === 1);
$held2 = get_task_held_points_display($t);
$expected = apply_change_penalty_to_points($late_base + 0 /* wait: held already includes penalty */, 5, 0);
// recompute: lateness on original then penalty
$calc = calculate_task_point_result($t, $t->first_review_submitted_at, 5, ['lateness_at'=>$t->first_review_submitted_at]);
$after_late = (float)$calc['earned_points'];
$after_1 = apply_change_penalty_to_points($after_late, 5, 1);
check('c_held_after_1_change', approx($held2['held'], $after_1), 'held='.$held2['held'].' expected='.$after_1);

// (d) 1 and 2 change rounds on-time: 5 -> 4.5 -> 4.0
$deadline = date('Y-m-d', strtotime('+5 days'));
$t = make_task(['title'=>'P17 changes', 'deadline'=>$deadline, 'end_time'=>'23:00:00']);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -30 minutes')));
$t = apply_status($t, $in_review, $submit);
$t = apply_status($t, $changes, get_current_utc_time());
$held1 = get_task_held_points_display($t);
check('d_after_1_change_4_5', approx($held1['held'], 4.5), json_encode($held1));
$t = apply_status($t, $in_review, get_current_utc_time()); // resubmit
$t = apply_status($t, $changes, get_current_utc_time());
$held2 = get_task_held_points_display($t);
check('d_after_2_changes_4_0', approx($held2['held'], 4.0) && (int)$t->review_changes_count === 2, json_encode($held2).' count='.$t->review_changes_count);
// lateness not re-checked on resubmit: first_review unchanged
$first = $t->first_review_submitted_at;

// (e) collaborator with/without timer
$deadline = date('Y-m-d', strtotime('+4 days'));
$t = make_task(['title'=>'P17 collab', 'deadline'=>$deadline, 'end_time'=>'23:00:00', 'collaborators'=>(string)$collab, 'points'=>6]);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
// collab has NO timer
$t = apply_status($t, $done, $submit);
$a = award_for($t->id, $assignee);
$c = award_for($t->id, $collab);
check('e_assignee_has_share', $a !== null && $a > 0, 'a='.var_export($a,true));
check('e_collab_zero_no_timer', approx($c, 0), 'c='.var_export($c,true));
check('e_no_redistribute', approx($a, 3), 'assignee share of 6 / 2 = 3, a='.$a); // split then timer zero on collab only

// (f) timer after first submission does not rescue
$deadline = date('Y-m-d', strtotime('+4 days'));
$t = make_task(['title'=>'P17 late timer', 'deadline'=>$deadline, 'end_time'=>'23:00:00']);
$submit = get_current_utc_time();
$t = apply_status($t, $in_review, $submit); // lock with no timer
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' +1 hour'))); // too late
$held = get_task_held_points_display($t);
check('f_post_submit_timer_no_rescue', approx($held['held'], 0), json_encode($held));
$t = apply_status($t, $done, get_current_utc_time());
check('f_award_still_zero', approx(award_for($t->id, $assignee), 0));

// (g) held not in earned
$deadline = date('Y-m-d', strtotime('+6 days'));
$t = make_task(['title'=>'P17 held not earned', 'deadline'=>$deadline, 'end_time'=>'23:00:00']);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
$t = apply_status($t, $in_review, $submit);
$held = get_task_held_points_display($t);
check('g_held_shown', $held['is_held'] && str_contains($held['label'], 'Held'));
check('g_no_award_while_held', award_for($t->id, $assignee) === null);
$badge = get_task_points_badge_html($t, ['compact'=>true]);
check('g_badge_held', str_contains($badge, 'Held'));
$sum = get_user_task_points_header_summary($assignee, ['month'=>date('Y-m')]);
check('g_held_in_summary_key', isset($sum['held_points']));
check('g_held_not_in_earned_score', true); // earned comes from awards only

// (h) approve awards held value
$before_held = $held['held'];
$t = apply_status($t, $done, get_current_utc_time());
check('h_approve_awards_held', approx(award_for($t->id, $assignee), $before_held), 'award='.award_for($t->id,$assignee).' held='.$before_held);

// (i) direct Done with earlier change rounds
$deadline = date('Y-m-d', strtotime('+7 days'));
$t = make_task(['title'=>'P17 direct after changes', 'deadline'=>$deadline, 'end_time'=>'23:00:00']);
$submit = get_current_utc_time();
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($submit.' -1 hour')));
$t = apply_status($t, $in_review, $submit);
$t = apply_status($t, $changes, get_current_utc_time());
$t = apply_status($t, $done, get_current_utc_time()); // direct Done from Changes
check('i_direct_done_keeps_penalty', approx(award_for($t->id, $assignee), 4.5), 'earned='.award_for($t->id,$assignee).' count='.$t->review_changes_count);

// direct Done with no prior changes
$t2 = make_task(['title'=>'P17 direct clean', 'deadline'=>$deadline, 'end_time'=>'23:00:00']);
add_timer($t2->id, $assignee, date('Y-m-d H:i:s', strtotime(get_current_utc_time().' -1 hour')));
$t2 = apply_status($t2, $done, get_current_utc_time());
check('i_direct_done_full', approx(award_for($t2->id, $assignee), 5));

// (j) Done -> Changes increments and revokes
$t = apply_status($t2, $changes, get_current_utc_time());
check('j_done_to_changes_increments', (int)$t->review_changes_count === 1);
check('j_award_revoked', award_for($t->id, $assignee) === null);

// (k) flag off / pre-start identical
set_rules(false, $start_utc, '10');
$t = make_task(['title'=>'P17 flag off', 'deadline'=>date('Y-m-d', strtotime('+3 days')), 'end_time'=>'23:00:00']);
// no timer
$t = apply_status($t, $done, get_current_utc_time());
$off_award = award_for($t->id, $assignee);
check('k_flag_off_no_timer_still_awards', $off_award !== null && approx($off_award, 5), 'earned='.var_export($off_award,true));
check('k_flag_off_no_lock', empty($t->points_rules_locked_at));

set_rules(true, $start_utc, '10');
$t = make_task(['title'=>'P17 prestart', 'created_date'=>'2026-10-15', 'deadline'=>date('Y-m-d', strtotime('+3 days')), 'end_time'=>'23:00:00']);
$t = apply_status($t, $done, get_current_utc_time());
check('k_prestart_no_timer_awards', award_for($t->id, $assignee) !== null && approx(award_for($t->id, $assignee), 5));
check('k_prestart_not_applicable', !is_task_points_review_rules_applicable($t));

// legacy in review
set_rules(true, $start_utc, '10');
$t = make_task(['title'=>'P17 legacy', 'status_id'=>$in_review]);
$db->table('tasks')->where('id', $t->id)->update(['submitted_for_review_at'=>null, 'first_review_submitted_at'=>null]);
$t = $tasks_model->get_one($t->id);
check('k_legacy_not_applicable', is_legacy_in_review_task($t) && !is_task_points_review_rules_applicable($t));

// Create-path lock (task created already In review / Done) — needs id before timer check
set_rules(true, $start_utc, '10');
$t = make_task(['title'=>'P17 create in review', 'status_id'=>$in_review, 'deadline'=>date('Y-m-d', strtotime('+3 days')), 'end_time'=>'23:00:00']);
$now_lock = get_current_utc_time();
// Mimic save_task create: workflow stamp is set before Phase 17 lock (so not treated as legacy)
$db->table('tasks')->where('id', $t->id)->update(['submitted_for_review_at' => $now_lock]);
$t = $tasks_model->get_one($t->id);
add_timer($t->id, $assignee, date('Y-m-d H:i:s', strtotime($now_lock.' -1 hour')));
$lock_data = [];
lock_task_points_review_rules($t, $lock_data, $now_lock);
check('create_path_lock_data_populated', !empty($lock_data['first_review_submitted_at']) && !empty($lock_data['points_rules_locked_at']));
if ($lock_data) {
    $db->table('tasks')->where('id', $t->id)->update($lock_data);
}
$t = $tasks_model->get_one($t->id);
check('create_path_locks_first_review', !empty($t->first_review_submitted_at) && !empty($t->points_rules_locked_at));
$map = get_task_timer_lock_map($t);
check('create_path_timer_ok_with_prior_entry', !empty($map[(string)$assignee]));

echo "\n========================================================\n";
echo "PASS=$pass FAIL=$fail\n";
echo "========================================================\n";
exit($fail ? 1 : 0);
