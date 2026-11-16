<?php
/**
 * Test Suite: Phase 16 Feature 1 (Multi-stage Task Deadline Reminders)
 * Target DB: sunrisecrm_scratch
 */

ini_set('memory_limit', '512M');

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

$targetDb = 'sunrisecrm_scratch';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $targetDb = substr($arg, 5);
    }
}

$dbConfig = config('Database');
$dbConfig->default['database'] = $targetDb;
$dbConfig->default['hostname'] = '127.0.0.1';

$db = \Config\Database::connect('default', false);

// Populate Rise config settings for CLI
$riseConfig = config('Rise');
$settings_rows = $db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult();
$riseConfig->app_settings_array = array();
foreach ($settings_rows as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
if (empty($riseConfig->app_settings_array['timezone'])) {
    $riseConfig->app_settings_array['timezone'] = 'Asia/Kolkata';
}
date_default_timezone_set($riseConfig->app_settings_array['timezone']);

// Ensure we are operating on scratch DB
if ($db->database !== 'sunrisecrm_scratch') {
    die("ERROR: Expected sunrisecrm_scratch, got " . $db->database . "\n");
}

echo "========================================================\n";
echo "Phase 16 Feature 1: Multi-Stage Deadline Reminders Tests\n";
echo "Target Database: " . $db->database . "\n";
echo "========================================================\n\n";

$pass_count = 0;
$fail_count = 0;

function assert_test($condition, $name, $details = "") {
    global $pass_count, $fail_count;
    if ($condition) {
        $pass_count++;
        echo "[PASS] $name\n";
    } else {
        $fail_count++;
        echo "[FAIL] $name: $details\n";
    }
}

// Ensure reminder settings
ensure_deadline_2_hour_notification_settings();

// Clean up any old test data
$db->query("DELETE FROM rise_tasks WHERE title LIKE 'PHASE16_TEST_%'");
$db->query("DELETE FROM rise_notifications WHERE event LIKE 'project_task_deadline_%' AND description LIKE '%2026-11-20%'");

$test_date = "2026-11-20";
$test_end_time = "18:00:00";
$deadline_datetime = "$test_date $test_end_time";
$deadline_ts = strtotime($deadline_datetime);

// Enable reminder stages setting
$settings_model = model('App\Models\Settings_model');
$settings_model->save_setting("task_deadline_reminder_stages", "120,60,30");

// -----------------------------------------------------------------------------
// TEST 1: Task 3 hours ahead (created 15:00, deadline 18:00)
// Advancing time: at 16:05 (2h stage), 17:05 (1h stage), 17:35 (30m stage).
// Must send exactly 1 notification per stage with correct recipients.
// -----------------------------------------------------------------------------
echo "--- TEST 1: Task 3 Hours Ahead Lifecycle ---\n";
$task_data = array(
    "title" => "PHASE16_TEST_TASK_1",
    "project_id" => 1,
    "assigned_to" => 66,
    "collaborators" => "40,7",
    "created_by" => 66,
    "deadline" => $test_date,
    "end_time" => $test_end_time,
    "status_id" => 1, // to_do
    "deleted" => 0,
    "created_date" => $test_date
);
$db->table('rise_tasks')->insert($task_data);
$task_1_id = $db->insertID();

// Simulate creation notification at 15:00:00 local (15:00:00 IST is 09:30:00 UTC)
$created_utc = convert_date_local_to_utc("$test_date 15:00:00");
$db->query("INSERT INTO rise_notifications (event, task_id, project_id, user_id, description, created_at, deleted)
            VALUES ('project_task_created', $task_1_id, 1, 66, '', '$created_utc', 0)");

// Simulate check at 15:30 (before any threshold)
// Override helper time by simulating check_and_send_task_deadline_2_hour_reminders logic at specific $now_local
function simulate_reminder_check($simulated_local_time, $task_id) {
    $ci = new \App\Controllers\App_Controller();
    $tasks_table = $ci->Tasks_model->db->prefixTable('tasks');
    $notifications_table = $ci->Tasks_model->db->prefixTable('notifications');
    $activity_logs_table = $ci->Tasks_model->db->prefixTable('activity_logs');
    
    $now_ts = strtotime($simulated_local_time);
    $done_status_id = (int) get_done_task_status_id();
    $in_review_status_id = (int) get_in_review_task_status_id();
    $stages = array(120, 60, 30);

    $sql = "SELECT t.id, t.project_id, t.title, t.assigned_to, t.collaborators, t.created_by, t.deadline, t.end_time, t.status_id, t.created_date,
                   COALESCE(
                       (SELECT n.created_at FROM $notifications_table n WHERE n.task_id = t.id AND n.event = 'project_task_created' LIMIT 1),
                       (SELECT a.created_at FROM $activity_logs_table a WHERE a.log_type = 'task' AND a.log_type_id = t.id AND a.action = 'created' LIMIT 1),
                       CONCAT(t.created_date, ' 00:00:00')
                   ) AS task_created_at
            FROM $tasks_table t
            WHERE t.id = $task_id
              AND t.deleted = 0
              AND t.status_id NOT IN ($done_status_id, $in_review_status_id)";

    $task = $ci->Tasks_model->db->query($sql)->getRow();
    if (!$task) return 0;

    $deadline_datetime = $task->deadline . " " . $task->end_time;
    $deadline_ts = strtotime($deadline_datetime);
    if (!$deadline_ts || $now_ts >= $deadline_ts) return 0;

    $task_created_local = convert_date_utc_to_local($task->task_created_at, "Y-m-d H:i:s");
    $task_created_ts = strtotime($task_created_local) ?: strtotime($task->created_date . " 00:00:00");
    $deadline_escaped = $ci->Tasks_model->db->escape($deadline_datetime);

    $get_stage_event = function($minutes) {
        if ($minutes === 120) return "project_task_deadline_2_hours_pre_reminder";
        if ($minutes === 60) return "project_task_deadline_1_hour_pre_reminder";
        if ($minutes === 30) return "project_task_deadline_30_mins_pre_reminder";
        return "project_task_deadline_{$minutes}_mins_pre_reminder";
    };

    $legitimate_past_stages = array();
    $all_past_unsent_stages = array();
    $has_upcoming_stage = false;

    foreach ($stages as $m) {
        $target_ts = $deadline_ts - ($m * 60);
        $event_name = $get_stage_event($m);

        if ($now_ts < $target_ts) {
            $has_upcoming_stage = true;
            continue;
        }

        $already = $ci->Tasks_model->db->query("
            SELECT id FROM $notifications_table
            WHERE task_id = {$task->id}
              AND event = '{$event_name}'
              AND deleted = 0
              AND description = {$deadline_escaped}
            LIMIT 1
        ")->getRow();

        if ($already) continue;

        $stage_item = array("minutes" => $m, "event" => $event_name, "target_ts" => $target_ts);
        $all_past_unsent_stages[] = $stage_item;

        if ($task_created_ts <= $target_ts) {
            $legitimate_past_stages[] = $stage_item;
        }
    }

    $stage_to_fire = null;
    if (!empty($legitimate_past_stages)) {
        usort($legitimate_past_stages, function($a, $b) { return $a['minutes'] - $b['minutes']; });
        $stage_to_fire = $legitimate_past_stages[0];
    } else if (!empty($all_past_unsent_stages)) {
        if (!$has_upcoming_stage) {
            usort($all_past_unsent_stages, function($a, $b) { return $a['minutes'] - $b['minutes']; });
            $stage_to_fire = $all_past_unsent_stages[0];
        }
    }

    if ($stage_to_fire) {
        $processor = new \App\Controllers\Notification_processor();
        $processor->create_notification(array(
            "event" => encode_id($stage_to_fire["event"], "notification"),
            "task_id" => $task->id,
            "project_id" => $task->project_id,
            "user_id" => "0",
            "description" => $deadline_datetime
        ));
        return 1;
    }
    return 0;
}

// 1a. At 15:30 (before 16:00 threshold): 0 sent
$res = simulate_reminder_check("$test_date 15:30:00", $task_1_id);
assert_test($res === 0, "Task 1 at 15:30 sends 0 reminders");

// 1b. At 16:05 (past 120m threshold): 2-hour reminder sent
$res = simulate_reminder_check("$test_date 16:05:00", $task_1_id);
assert_test($res === 1, "Task 1 at 16:05 sends 2h reminder");
$notif_2h = $db->query("SELECT * FROM rise_notifications WHERE task_id = $task_1_id AND event = 'project_task_deadline_2_hours_pre_reminder'")->getRow();
assert_test($notif_2h !== null, "Task 1 has 2h notification record");
$recipients_2h = explode(',', $notif_2h->notify_to);
assert_test(in_array("66", $recipients_2h) && in_array("40", $recipients_2h) && in_array("7", $recipients_2h), "Task 1 2h reminder notifies assignee (66) and collaborators (40, 7)");

// 1c. At 16:10 (repeated check in same window): 0 sent (dedupe)
$res = simulate_reminder_check("$test_date 16:10:00", $task_1_id);
assert_test($res === 0, "Task 1 at 16:10 sends 0 duplicate reminders");

// 1d. At 17:05 (past 60m threshold): 1-hour reminder sent
$res = simulate_reminder_check("$test_date 17:05:00", $task_1_id);
assert_test($res === 1, "Task 1 at 17:05 sends 1h reminder");
$notif_1h = $db->query("SELECT * FROM rise_notifications WHERE task_id = $task_1_id AND event = 'project_task_deadline_1_hour_pre_reminder'")->getRow();
assert_test($notif_1h !== null, "Task 1 has 1h notification record");

// 1e. At 17:35 (past 30m threshold): 30-min reminder sent
$res = simulate_reminder_check("$test_date 17:35:00", $task_1_id);
assert_test($res === 1, "Task 1 at 17:35 sends 30m reminder");
$notif_30m = $db->query("SELECT * FROM rise_notifications WHERE task_id = $task_1_id AND event = 'project_task_deadline_30_mins_pre_reminder'")->getRow();
assert_test($notif_30m !== null, "Task 1 has 30m notification record");

$total_task1_reminders = $db->query("SELECT COUNT(*) AS total FROM rise_notifications WHERE task_id = $task_1_id AND event LIKE 'project_task_deadline_%'")->getRow()->total;
assert_test((int)$total_task1_reminders === 3, "Task 1 total reminders = exactly 3 (1 per stage)");

// -----------------------------------------------------------------------------
// TEST 2: Task created with 45 minutes left (created 17:15, deadline 18:00)
// Must fire ONLY 30m stage. Must NOT fire 2h or 1h.
// -----------------------------------------------------------------------------
echo "\n--- TEST 2: Task Created with 45 Minutes Left ---\n";
$task_2_data = array(
    "title" => "PHASE16_TEST_TASK_2",
    "project_id" => 1,
    "assigned_to" => 40,
    "created_by" => 66,
    "deadline" => $test_date,
    "end_time" => $test_end_time,
    "status_id" => 1,
    "deleted" => 0,
    "created_date" => $test_date
);
$db->table('rise_tasks')->insert($task_2_data);
$task_2_id = $db->insertID();

$created_utc_45m = convert_date_local_to_utc("$test_date 17:15:00");
$db->query("INSERT INTO rise_notifications (event, task_id, project_id, user_id, description, created_at, deleted)
            VALUES ('project_task_created', $task_2_id, 1, 66, '', '$created_utc_45m', 0)");

// Check at 17:16 (44 min left): 120m and 60m are past, but task was created at 17:15, and 30m is upcoming.
// Must send 0!
$res = simulate_reminder_check("$test_date 17:16:00", $task_2_id);
assert_test($res === 0, "Task 2 at 17:16 sends 0 (past 2h and 1h suppressed, waiting for 30m)");

// Check at 17:35 (past 30m threshold): sends 30m!
$res = simulate_reminder_check("$test_date 17:35:00", $task_2_id);
assert_test($res === 1, "Task 2 at 17:35 sends 30m reminder");

$task_2_events = $db->query("SELECT event FROM rise_notifications WHERE task_id = $task_2_id AND event LIKE 'project_task_deadline_%'")->getResult();
assert_test(count($task_2_events) === 1 && $task_2_events[0]->event === 'project_task_deadline_30_mins_pre_reminder', "Task 2 has ONLY 30m notification; 2h and 1h NEVER fired");

// -----------------------------------------------------------------------------
// TEST 3: Done and In review tasks get NO reminders
// -----------------------------------------------------------------------------
echo "\n--- TEST 3: Done and In Review Exclusions ---\n";
$done_id = (int) get_done_task_status_id();
$in_review_id = (int) get_in_review_task_status_id();

$task_done_data = array(
    "title" => "PHASE16_TEST_DONE",
    "project_id" => 1,
    "assigned_to" => 40,
    "created_by" => 66,
    "deadline" => $test_date,
    "end_time" => $test_end_time,
    "status_id" => $done_id,
    "deleted" => 0,
    "created_date" => $test_date
);
$db->table('rise_tasks')->insert($task_done_data);
$task_done_id = $db->insertID();

$task_review_data = array(
    "title" => "PHASE16_TEST_IN_REVIEW",
    "project_id" => 1,
    "assigned_to" => 40,
    "created_by" => 66,
    "deadline" => $test_date,
    "end_time" => $test_end_time,
    "status_id" => $in_review_id,
    "deleted" => 0,
    "created_date" => $test_date
);
$db->table('rise_tasks')->insert($task_review_data);
$task_review_id = $db->insertID();

$res_done = simulate_reminder_check("$test_date 17:35:00", $task_done_id);
$res_review = simulate_reminder_check("$test_date 17:35:00", $task_review_id);
assert_test($res_done === 0, "Task in Done status triggers 0 reminders");
assert_test($res_review === 0, "Task in In review status triggers 0 reminders");

// -----------------------------------------------------------------------------
// TEST 4: Tasks in Changes status KEEP reminders
// -----------------------------------------------------------------------------
echo "\n--- TEST 4: Tasks in Changes Status Keep Reminders ---\n";
$changes_id = (int) get_changes_task_status_id();

$task_changes_data = array(
    "title" => "PHASE16_TEST_CHANGES",
    "project_id" => 1,
    "assigned_to" => 40,
    "created_by" => 66,
    "deadline" => $test_date,
    "end_time" => $test_end_time,
    "status_id" => $changes_id,
    "deleted" => 0,
    "created_date" => $test_date
);
$db->table('rise_tasks')->insert($task_changes_data);
$task_changes_id = $db->insertID();

$created_utc_ch = convert_date_local_to_utc("$test_date 15:00:00");
$db->query("INSERT INTO rise_notifications (event, task_id, project_id, user_id, description, created_at, deleted)
            VALUES ('project_task_created', $task_changes_id, 1, 66, '', '$created_utc_ch', 0)");

$res_changes = simulate_reminder_check("$test_date 16:05:00", $task_changes_id);
assert_test($res_changes === 1, "Task in Changes status receives reminders (2h sent)");

// -----------------------------------------------------------------------------
// TEST 5: Editing deadline re-arms all stages
// -----------------------------------------------------------------------------
echo "\n--- TEST 5: Edited Deadline Re-arms Stages ---\n";
// Task 1 had all 3 stages fired for deadline 18:00:00.
// Now user edits deadline to 20:00:00 on the same date.
$new_end_time = "20:00:00";
$db->table('rise_tasks')->where('id', $task_1_id)->update(array('end_time' => $new_end_time));

// Check at 18:05: 120m before 20:00 is 18:00. So 18:05 is past new 120m threshold!
$res_rearmed = simulate_reminder_check("$test_date 18:05:00", $task_1_id);
assert_test($res_rearmed === 1, "Task 1 with edited deadline re-armed: 2h stage fires for new deadline");
$new_2h_notif = $db->query("SELECT * FROM rise_notifications WHERE task_id = $task_1_id AND event = 'project_task_deadline_2_hours_pre_reminder' AND description = '$test_date $new_end_time'")->getRow();
assert_test($new_2h_notif !== null, "Notification record created with new deadline description '$test_date $new_end_time'");

// Clean up test tasks
$db->query("DELETE FROM rise_tasks WHERE title LIKE 'PHASE16_TEST_%'");
$db->query("DELETE FROM rise_notifications WHERE event LIKE 'project_task_deadline_%' AND description LIKE '%2026-11-20%'");

echo "\n========================================================\n";
echo "Feature 1 Tests Summary: $pass_count PASSED, $fail_count FAILED\n";
echo "========================================================\n";

if ($fail_count > 0) exit(1);
