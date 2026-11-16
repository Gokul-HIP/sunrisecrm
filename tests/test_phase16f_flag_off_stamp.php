<?php
/**
 * Phase 16F: flag-off must not stamp submitted_for_review_at;
 * lateness must use completed_at (1449be8 behaviour).
 *
 * Avoids Session/TimeTrait by using direct SQL for status transitions
 * that mirror the gated controller logic.
 *
 * Usage: php tests/test_phase16f_flag_off_stamp.php [--db=sunrisecrmnew]
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', '1');

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

$targetDb = 'sunrisecrmnew';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $targetDb = substr($arg, 5);
    }
}
$dbConfig = config('Database');
$dbConfig->default['database'] = $targetDb;
$dbConfig->default['hostname'] = '127.0.0.1';
$db = \Config\Database::connect('default', false);

$riseConfig = config('Rise');
$riseConfig->app_settings_array = array();
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

echo "========================================================\n";
echo "Phase 16F: Flag-off stamp + lateness (completed_at)\n";
echo "DB: " . $db->database . "\n";
echo "========================================================\n\n";

$pass = 0;
$fail = 0;
function assert_test($cond, $name, $details = "") {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($details !== "" ? ": $details" : "") . "\n";
    }
}

// --- Source audit: every stamp assignment must be gated ---
$projects_src = file_get_contents(APPPATH . 'Controllers/Projects.php');
preg_match_all('/\$data\["submitted_for_review_at"\]\s*=/', $projects_src, $stamp_matches, PREG_OFFSET_CAPTURE);
$stamp_count = count($stamp_matches[0]);
echo "Stamp assignment sites in Projects.php: $stamp_count\n";

$ungated = 0;
$stamp_lines = [];
foreach ($stamp_matches[0] as $m) {
    $pos = $m[1];
    $line = substr_count(substr($projects_src, 0, $pos), "\n") + 1;
    $stamp_lines[] = $line;
    $window = substr($projects_src, max(0, $pos - 280), 280);
    if (strpos($window, 'task_review_workflow_enabled') === false) {
        $ungated++;
        echo "  UNGATED near line $line\n";
    } else {
        echo "  gated line $line\n";
    }
}
assert_test($stamp_count >= 5 && $ungated === 0, "all_stamp_paths_gated_by_workflow_flag", "sites=$stamp_count ungated=$ungated");

echo "Stamp paths (flag=1 only):\n";
echo "  - Projects::save_task (edit) -> In review\n";
echo "  - Projects::save_task (create) with In review\n";
echo "  - Projects::save_task_status\n";
echo "  - Projects::update_task_info (status_id)\n";
echo "  - Projects::save_task_sort_and_status (kanban)\n";
echo "  - Projects::batch_update\n";
echo "Lines: " . implode(", ", $stamp_lines) . "\n\n";

// --- Flag off via settings array (no Settings_model->save to avoid session) ---
$riseConfig->app_settings_array["task_review_workflow_enabled"] = "0";
$db->query("UPDATE rise_settings SET setting_value = '0' WHERE setting_name = 'task_review_workflow_enabled' AND deleted = 0");
assert_test((string) get_setting("task_review_workflow_enabled") === "0", "workflow_flag_is_off");

$in_review_id = (int) get_in_review_task_status_id();
$done_id = (int) get_done_task_status_id();
$todo_id = 1;

$db->query("DELETE FROM rise_tasks WHERE title LIKE 'PHASE16F_%'");

$deadline_local = date('Y-m-d', strtotime('-2 days'));
$end_time = '18:00:00';

$db->query(
    "INSERT INTO rise_tasks (title, project_id, created_by, assigned_to, collaborators, points, status_id, deadline, end_time, created_date, deleted, submitted_for_review_at)
     VALUES ('PHASE16F_FLAG_OFF_LATENESS', 626, 7, 40, '68', 10, ?, ?, ?, ?, 0, NULL)",
    [$todo_id, $deadline_local, $end_time, date('Y-m-d')]
);
$task_id = (int) $db->insertID();

/**
 * Mirror Projects.php stamp gate: only stamp when workflow enabled.
 */
function apply_status_like_controller($db, $task_id, $new_status_id) {
    $task = $db->query("SELECT * FROM rise_tasks WHERE id=?", [$task_id])->getRow();
    $in_review_id = (int) get_in_review_task_status_id();
    $now = gmdate('Y-m-d H:i:s');
    $status_changed_at = null;
    $submitted = null;
    if ((int) $task->status_id !== (int) $new_status_id) {
        $status_changed_at = $now;
        if ((int) $new_status_id === $in_review_id && get_setting("task_review_workflow_enabled")) {
            $submitted = $now;
        }
    }
    if ($status_changed_at && $submitted) {
        $db->query(
            "UPDATE rise_tasks SET status_id=?, status_changed_at=?, submitted_for_review_at=? WHERE id=?",
            [$new_status_id, $status_changed_at, $submitted, $task_id]
        );
    } else if ($status_changed_at) {
        $db->query(
            "UPDATE rise_tasks SET status_id=?, status_changed_at=? WHERE id=?",
            [$new_status_id, $status_changed_at, $task_id]
        );
    }
    return $now;
}

apply_status_like_controller($db, $task_id, $in_review_id);
$row_after_review = $db->query("SELECT status_id, submitted_for_review_at, status_changed_at FROM rise_tasks WHERE id=?", [$task_id])->getRow();
assert_test((int) $row_after_review->status_id === $in_review_id, "flag0_assignee_set_in_review");
assert_test(
    $row_after_review->submitted_for_review_at === null || $row_after_review->submitted_for_review_at === '' || $row_after_review->submitted_for_review_at === '0000-00-00 00:00:00',
    "flag0_no_submitted_for_review_at_stamp",
    "got=" . var_export($row_after_review->submitted_for_review_at, true)
);

apply_status_like_controller($db, $task_id, $done_id);
$row_after_done = $db->query("SELECT * FROM rise_tasks WHERE id=?", [$task_id])->getRow();
assert_test((int) $row_after_done->status_id === $done_id, "flag0_assignee_set_done");
assert_test(
    $row_after_done->submitted_for_review_at === null || $row_after_done->submitted_for_review_at === '' || $row_after_done->submitted_for_review_at === '0000-00-00 00:00:00',
    "flag0_still_no_stamp_after_done"
);

$completed_at = $row_after_done->status_changed_at;
$actual = calculate_task_point_result($row_after_done, $completed_at);

$clone = clone $row_after_done;
$clone->submitted_for_review_at = null;
$expected = calculate_task_point_result($clone, $completed_at);

assert_test(
    abs((float) $actual['hours_late'] - (float) $expected['hours_late']) < 0.0001
    && (float) $actual['earned_points'] === (float) $expected['earned_points']
    && (int) $actual['reduction_percent'] === (int) $expected['reduction_percent'],
    "flag0_lateness_identical_to_completed_at_behaviour",
    "actual_hours={$actual['hours_late']} expected_hours={$expected['hours_late']} earned={$actual['earned_points']}/{$expected['earned_points']}"
);

// Polluted stamp (prod #23527/#25604 case): flag off must still use completed_at
$polluted = clone $row_after_done;
$polluted->submitted_for_review_at = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
$with_pollute = calculate_task_point_result($polluted, $completed_at);
assert_test(
    abs((float) $with_pollute['hours_late'] - (float) $expected['hours_late']) < 0.0001
    && (float) $with_pollute['earned_points'] === (float) $expected['earned_points'],
    "flag0_ignores_existing_stamp_for_lateness",
    "hours={$with_pollute['hours_late']} vs {$expected['hours_late']}"
);

// Flag ON: submitted_for_review_at is used (on-time submit vs late completed_at)
$riseConfig->app_settings_array["task_review_workflow_enabled"] = "1";
$db->query("UPDATE rise_settings SET setting_value = '1' WHERE setting_name = 'task_review_workflow_enabled' AND deleted = 0");
$polluted2 = clone $row_after_done;
// submitted 1h before deadline (UTC), completed_at is ~now (late) — different outcomes
$deadline_utc_ts = strtotime($deadline_local . ' ' . $end_time . ' UTC');
$polluted2->submitted_for_review_at = gmdate('Y-m-d H:i:s', $deadline_utc_ts - 3600);
$flag_on = calculate_task_point_result($polluted2, $completed_at);
assert_test(
    (float) $flag_on['hours_late'] < (float) $expected['hours_late'],
    "flag1_prefers_submitted_for_review_at",
    "flag_on_hours={$flag_on['hours_late']} completed_at_hours={$expected['hours_late']}"
);

// Restore workflow on
$riseConfig->app_settings_array["task_review_workflow_enabled"] = "1";
$db->query("UPDATE rise_settings SET setting_value = '1' WHERE setting_name = 'task_review_workflow_enabled' AND deleted = 0");

echo "\n========================================================\n";
echo "PASS=$pass FAIL=$fail\n";
echo "========================================================\n";
exit($fail ? 1 : 0);
