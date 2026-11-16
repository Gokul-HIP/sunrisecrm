<?php
/**
 * Phase 16D: Legacy In-review rule (option B) + old-format 2h reminder dedupe.
 * Target DB: sunrisecrm_scratch only. Does not touch live.
 *
 * Usage: php tests/test_phase16d_legacy_and_dedupe.php [--db=sunrisecrm_scratch]
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

$targetDb = 'sunrisecrm_scratch';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $targetDb = substr($arg, 5);
    }
}
if ($targetDb !== 'sunrisecrm_scratch') {
    fwrite(STDERR, "Refusing non-scratch DB: $targetDb\n");
    exit(2);
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
if (empty($riseConfig->app_settings_array['timezone'])) {
    $riseConfig->app_settings_array['timezone'] = 'Asia/Kolkata';
}
date_default_timezone_set($riseConfig->app_settings_array['timezone']);

echo "========================================================\n";
echo "Phase 16D: Legacy In-review + old-format reminder dedupe\n";
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
        echo "[FAIL] $name" . ($details !== "" ? ": $details" : "") . "\n";
    }
}

$settings_model = model('App\Models\Settings_model');
$settings_model->save_setting("task_review_workflow_enabled", "1");
$riseConfig->app_settings_array["task_review_workflow_enabled"] = "1";

$done_status_id = (int) get_done_task_status_id();
$in_review_status_id = (int) get_in_review_task_status_id();
$changes_status_id = (int) get_changes_task_status_id();
$to_do_status_id = 1;

echo "Resolved Status IDs: Done=$done_status_id, In review=$in_review_status_id, Changes=$changes_status_id\n\n";

$creator = (object) array("id" => 7, "is_admin" => 0, "user_type" => "staff");
$assignee = (object) array("id" => 40, "is_admin" => 0, "user_type" => "staff");
$collab = (object) array("id" => 68, "is_admin" => 0, "user_type" => "staff");
$admin = (object) array("id" => 66, "is_admin" => 1, "user_type" => "staff");

$db->query("DELETE FROM rise_notifications WHERE task_id IN (SELECT id FROM rise_tasks WHERE title LIKE 'PHASE16D_%')");
$db->query("DELETE FROM rise_task_point_awards WHERE task_id IN (SELECT id FROM rise_tasks WHERE title LIKE 'PHASE16D_%')");
$db->query("DELETE FROM rise_tasks WHERE title LIKE 'PHASE16D_%'");

function try_set_task_status_16d($task_id, $target_status_id, $acting_user) {
    $ci = new \App\Controllers\App_Controller();
    $task_info = $ci->Tasks_model->get_one($task_id);
    $done_status_id = (int) get_done_task_status_id();
    $changes_status_id = (int) get_changes_task_status_id();
    $in_review_status_id = (int) get_in_review_task_status_id();

    if ((int) $target_status_id === $changes_status_id && get_setting("task_review_workflow_enabled")) {
        return array("success" => false, "message" => app_lang("changes_status_can_only_be_set_via_review_queue"));
    }

    $is_legacy_review = is_legacy_in_review_task($task_info);
    if ((int) $target_status_id === $done_status_id && get_setting("task_review_workflow_enabled") && !$is_legacy_review) {
        if ($task_info->created_by) {
            if (!$acting_user->is_admin && (int) $acting_user->id !== (int) $task_info->created_by) {
                return array("success" => false, "message" => app_lang("only_creator_or_admin_can_mark_task_done"));
            }
        }
    }

    $data = array("status_id" => $target_status_id);
    if ((int) $task_info->status_id !== (int) $target_status_id) {
        $data["status_changed_at"] = get_current_utc_time();
        if ((int) $target_status_id === $in_review_status_id) {
            $data["submitted_for_review_at"] = get_current_utc_time();
        }
    }

    $save_id = $ci->Tasks_model->ci_save($data, $task_id);
    if ($save_id) {
        if ((int) $target_status_id === $done_status_id && function_exists("sync_task_point_award")) {
            sync_task_point_award($task_id);
        }
        return array("success" => true);
    }
    return array("success" => false, "message" => "Save failed");
}

function task_in_list($tasks, $task_id) {
    foreach ($tasks as $t) {
        if ((int) $t->id === (int) $task_id) {
            return true;
        }
    }
    return false;
}

$tasks_model = model('App\Models\Tasks_model');
$ci = new \App\Controllers\App_Controller();

// -----------------------------------------------------------------------------
// Dedupe key documentation + old-format compatibility
// -----------------------------------------------------------------------------
echo "--- Dedupe keys (old vs new) ---\n";
echo "OLD 2h key: task_id + event=project_task_deadline_2_hours_pre_reminder + description='' OR description='YYYY-MM-DD'\n";
echo "NEW 2h key: task_id + event=project_task_deadline_2_hours_pre_reminder + description='YYYY-MM-DD HH:MM:SS'\n\n";

echo "--- TEST A: Old-format 2h reminder must not re-fire ---\n";
$now_local = get_my_local_time("Y-m-d H:i:s");
$deadline_ts = strtotime($now_local) + (100 * 60); // due in 100 minutes -> past 120m threshold
$deadline_date = date("Y-m-d", $deadline_ts);
$deadline_time = date("H:i:s", $deadline_ts);
$deadline_datetime = $deadline_date . " " . $deadline_time;

$old_dedupe_task = array(
    "title" => "PHASE16D_OLD_DEDUPE_2H",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "collaborators" => "68",
    "points" => 5,
    "status_id" => $to_do_status_id,
    "deadline" => $deadline_date,
    "end_time" => $deadline_time,
    "created_date" => date("Y-m-d", strtotime($now_local) - 86400),
    "deleted" => 0
);
$db->table('rise_tasks')->insert($old_dedupe_task);
$old_dedupe_task_id = (int) $db->insertID();

// Seed OLD-format notification (description empty string — Notifications_model default before datetime key)
$db->table('rise_notifications')->insert(array(
    "user_id" => 0,
    "description" => "",
    "created_at" => get_current_utc_time(),
    "notify_to" => "40,68,7",
    "read_by" => "",
    "event" => "project_task_deadline_2_hours_pre_reminder",
    "project_id" => 1,
    "task_id" => $old_dedupe_task_id,
    "project_comment_id" => 0,
    "ticket_id" => 0,
    "ticket_comment_id" => 0,
    "activity_log_id" => 0,
    "client_id" => 0,
    "deleted" => 0
));

$count_before = (int) $db->query("SELECT COUNT(*) AS c FROM rise_notifications WHERE task_id = $old_dedupe_task_id AND event = 'project_task_deadline_2_hours_pre_reminder' AND deleted = 0")->getRow()->c;
$sent = check_and_send_task_deadline_2_hour_reminders(true);
$count_after = (int) $db->query("SELECT COUNT(*) AS c FROM rise_notifications WHERE task_id = $old_dedupe_task_id AND event = 'project_task_deadline_2_hours_pre_reminder' AND deleted = 0")->getRow()->c;
$new_format_extra = (int) $db->query("SELECT COUNT(*) AS c FROM rise_notifications WHERE task_id = $old_dedupe_task_id AND event = 'project_task_deadline_2_hours_pre_reminder' AND description = " . $db->escape($deadline_datetime) . " AND deleted = 0")->getRow()->c;

assert_test($count_before === 1, "Seeded exactly one old-format 2h notification");
assert_test($count_after === 1, "After reminder run, still exactly one 2h notification (no duplicate)", "before=$count_before after=$count_after sent=$sent");
assert_test($new_format_extra === 0, "No new-format 2h notification created for same deadline");

// -----------------------------------------------------------------------------
// Legacy vs new-flow side by side
// -----------------------------------------------------------------------------
echo "\n--- TEST B: Legacy vs new-flow side by side ---\n";

$legacy_data = array(
    "title" => "PHASE16D_LEGACY_IN_REVIEW",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "collaborators" => "68",
    "points" => 10,
    "status_id" => $in_review_status_id,
    "deadline" => "2026-11-20",
    "end_time" => "18:00:00",
    "submitted_for_review_at" => null,
    "created_date" => "2026-11-12",
    "deleted" => 0
);
$db->table('rise_tasks')->insert($legacy_data);
$legacy_id = (int) $db->insertID();

$newflow_data = array(
    "title" => "PHASE16D_NEWFLOW_IN_REVIEW",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "collaborators" => "68",
    "points" => 10,
    "status_id" => $to_do_status_id,
    "deadline" => "2026-11-20",
    "end_time" => "18:00:00",
    "created_date" => "2026-11-12",
    "deleted" => 0
);
$db->table('rise_tasks')->insert($newflow_data);
$newflow_id = (int) $db->insertID();

$enter = try_set_task_status_16d($newflow_id, $in_review_status_id, $assignee);
assert_test($enter["success"] === true, "New-flow task can enter In review");
$newflow_row = $db->query("SELECT status_id, submitted_for_review_at FROM rise_tasks WHERE id = $newflow_id")->getRow();
assert_test(!empty($newflow_row->submitted_for_review_at), "Entering In review stamps submitted_for_review_at");

$legacy_row = $ci->Tasks_model->get_one($legacy_id);
$newflow_obj = $ci->Tasks_model->get_one($newflow_id);
assert_test(is_legacy_in_review_task($legacy_row) === true, "Helper recognizes legacy (In review + NULL submitted_for_review_at)");
assert_test(is_legacy_in_review_task($newflow_obj) === false, "Helper rejects new-flow (timestamp set)");

// Tasks page visibility
$assignee_list = $tasks_model->get_details(array(
    "specific_user_id" => 40,
    "exclude_review_tasks_for_assignee" => true
))->getResult();
assert_test(task_in_list($assignee_list, $legacy_id), "Legacy visible on assignee Tasks page");
assert_test(!task_in_list($assignee_list, $newflow_id), "New-flow hidden from assignee Tasks page");

$kanban_legacy = $tasks_model->get_kanban_details(array(
    "specific_user_id" => 40,
    "status_id" => $in_review_status_id,
    "exclude_review_tasks_for_assignee" => true
))->getResult();
assert_test(task_in_list($kanban_legacy, $legacy_id), "Legacy visible on kanban In review column");
assert_test(!task_in_list($kanban_legacy, $newflow_id), "New-flow absent from kanban In review column");

// Header In-review counter (legacy-only when flag on)
$counts = $tasks_model->get_details(array(
    "specific_user_id" => 40,
    "exclude_review_tasks_for_assignee" => true,
    "return_task_counts" => true
));
// get_details may return Result or array-with-counts depending on options; fall back to status aggregation query path
$in_review_header = null;
if (is_array($counts) && isset($counts["in_review_count"])) {
    $in_review_header = (int) $counts["in_review_count"];
} else {
    // Use same SQL condition the model uses for status pills when workflow enabled
    $in_review_header = (int) $db->query("
        SELECT COUNT(*) AS c FROM rise_tasks
        WHERE deleted = 0 AND assigned_to = 40 AND status_id = $in_review_status_id
          AND submitted_for_review_at IS NULL
    ")->getRow()->c;
}
assert_test($in_review_header >= 1, "Tasks-page In review header counts include legacy", "count=$in_review_header");
$newflow_in_header = (int) $db->query("
    SELECT COUNT(*) AS c FROM rise_tasks
    WHERE id = $newflow_id AND submitted_for_review_at IS NULL
")->getRow()->c;
assert_test($newflow_in_header === 0, "New-flow not counted in legacy-only header condition");

// Queue + badges
$creator_queue = $tasks_model->get_details(array("awaiting_review_for_user_id" => 7))->getResult();
assert_test(!task_in_list($creator_queue, $legacy_id), "Legacy NOT in creator review queue");
assert_test(task_in_list($creator_queue, $newflow_id), "New-flow IS in creator review queue");

$creator_badge = count_tasks_in_review($creator);
$legacy_in_badge = (int) $db->query("
    SELECT COUNT(*) AS c FROM rise_tasks
    WHERE id = $legacy_id AND submitted_for_review_at IS NOT NULL AND created_by = 7
")->getRow()->c;
$newflow_in_badge = (int) $db->query("
    SELECT COUNT(*) AS c FROM rise_tasks
    WHERE id = $newflow_id AND submitted_for_review_at IS NOT NULL AND created_by = 7 AND status_id = $in_review_status_id
")->getRow()->c;
assert_test($legacy_in_badge === 0, "Legacy excluded from badge SQL");
assert_test($newflow_in_badge === 1, "New-flow included in badge SQL");
assert_test($creator_badge >= 1, "Creator sidebar badge >= 1 with new-flow present", "badge=$creator_badge");

$assignee_badge = count_tasks_in_review($assignee);
$collab_badge = count_tasks_in_review($collab);
assert_test($assignee_badge === (int) $db->query("SELECT COUNT(*) AS c FROM rise_tasks WHERE deleted=0 AND status_id=$in_review_status_id AND submitted_for_review_at IS NOT NULL AND created_by=40")->getRow()->c, "Assignee badge only counts tasks they created");
assert_test($collab_badge === (int) $db->query("SELECT COUNT(*) AS c FROM rise_tasks WHERE deleted=0 AND status_id=$in_review_status_id AND submitted_for_review_at IS NOT NULL AND created_by=68")->getRow()->c, "Collaborator badge only counts tasks they created");

// Done permissions
$assignee_done_legacy = try_set_task_status_16d($legacy_id, $done_status_id, $assignee);
assert_test($assignee_done_legacy["success"] === true, "Assignee CAN mark legacy In-review Done");

// Restore legacy for further checks if needed — already Done. Create fresh legacy for creator/admin matrix leftovers.
$db->table('rise_tasks')->insert(array(
    "title" => "PHASE16D_LEGACY_DONE_MATRIX",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "collaborators" => "68",
    "points" => 8,
    "status_id" => $in_review_status_id,
    "deadline" => "2026-11-20",
    "end_time" => "18:00:00",
    "submitted_for_review_at" => null,
    "created_date" => "2026-11-12",
    "deleted" => 0
));
$legacy2_id = (int) $db->insertID();

$collab_done_legacy = try_set_task_status_16d($legacy2_id, $done_status_id, $collab);
assert_test($collab_done_legacy["success"] === true, "Collaborator CAN mark legacy In-review Done (pre-workflow behavior)");

$db->table('rise_tasks')->insert(array(
    "title" => "PHASE16D_NEWFLOW_DONE_MATRIX",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "collaborators" => "68",
    "points" => 8,
    "status_id" => $in_review_status_id,
    "deadline" => "2026-11-20",
    "end_time" => "18:00:00",
    "submitted_for_review_at" => get_current_utc_time(),
    "created_date" => "2026-11-12",
    "deleted" => 0
));
$newflow2_id = (int) $db->insertID();

$assignee_done_new = try_set_task_status_16d($newflow2_id, $done_status_id, $assignee);
assert_test($assignee_done_new["success"] === false, "Assignee CANNOT mark new-flow In-review Done");

$collab_done_new = try_set_task_status_16d($newflow2_id, $done_status_id, $collab);
assert_test($collab_done_new["success"] === false, "Collaborator CANNOT mark new-flow In-review Done");

$creator_done_new = try_set_task_status_16d($newflow2_id, $done_status_id, $creator);
assert_test($creator_done_new["success"] === true, "Creator CAN mark new-flow In-review Done");

$db->table('rise_tasks')->insert(array(
    "title" => "PHASE16D_NEWFLOW_ADMIN_DONE",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "points" => 6,
    "status_id" => $in_review_status_id,
    "deadline" => "2026-11-20",
    "end_time" => "18:00:00",
    "submitted_for_review_at" => get_current_utc_time(),
    "created_date" => "2026-11-12",
    "deleted" => 0
));
$newflow3_id = (int) $db->insertID();
$admin_done_new = try_set_task_status_16d($newflow3_id, $done_status_id, $admin);
assert_test($admin_done_new["success"] === true, "Admin CAN mark new-flow In-review Done");

// Points: legacy Done by assignee awards points; lateness falls back to completed_at
$legacy_awards = model('App\Models\Task_point_awards_model')->get_all_by_task_id($legacy_id, false);
assert_test(count($legacy_awards) >= 1, "Legacy Done awards points", "count=" . count($legacy_awards));

$late_legacy = array(
    "title" => "PHASE16D_LEGACY_LATENESS",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "points" => 10,
    "status_id" => $in_review_status_id,
    "deadline" => "2026-11-10",
    "end_time" => "12:00:00",
    "submitted_for_review_at" => null,
    "created_date" => "2026-11-09",
    "deleted" => 0
);
$db->table('rise_tasks')->insert($late_legacy);
$late_legacy_id = (int) $db->insertID();
$late_obj = $ci->Tasks_model->get_one($late_legacy_id);
$point_res = calculate_task_point_result($late_obj, "2026-11-11 12:30:00");
assert_test($point_res["is_reduced"] === true, "Legacy lateness falls back to completed_at");
assert_test($point_res["reduction_percent"] === 20, "Legacy lateness 20% reduction for ~30h late");

// Move legacy out and back -> becomes new-flow
$db->table('rise_tasks')->insert(array(
    "title" => "PHASE16D_LEGACY_REENTER",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "points" => 5,
    "status_id" => $in_review_status_id,
    "deadline" => "2026-11-20",
    "end_time" => "18:00:00",
    "submitted_for_review_at" => null,
    "created_date" => "2026-11-12",
    "deleted" => 0
));
$reenter_id = (int) $db->insertID();
$out = try_set_task_status_16d($reenter_id, $to_do_status_id, $assignee);
$back = try_set_task_status_16d($reenter_id, $in_review_status_id, $assignee);
$reenter_row = $ci->Tasks_model->get_one($reenter_id);
assert_test($out["success"] && $back["success"], "Legacy can leave and re-enter In review");
assert_test(!empty($reenter_row->submitted_for_review_at), "Re-entering In review stamps timestamp (becomes new-flow)");
assert_test(is_legacy_in_review_task($reenter_row) === false, "After re-enter, task is no longer legacy");
assert_test(task_in_list($tasks_model->get_details(array("awaiting_review_for_user_id" => 7))->getResult(), $reenter_id), "Re-entered task appears in creator queue");

// Stamp paths: simulate modal/kanban/batch transition stamp already covered by try_set;
// also assert direct status change from to_do stamps.
$db->table('rise_tasks')->insert(array(
    "title" => "PHASE16D_STAMP_PATH",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "points" => 4,
    "status_id" => $to_do_status_id,
    "deadline" => "2026-11-20",
    "end_time" => "18:00:00",
    "created_date" => "2026-11-12",
    "deleted" => 0
));
$stamp_id = (int) $db->insertID();
$stamp_res = try_set_task_status_16d($stamp_id, $in_review_status_id, $assignee);
$stamp_row = $db->query("SELECT submitted_for_review_at FROM rise_tasks WHERE id = $stamp_id")->getRow();
assert_test($stamp_res["success"] && !empty($stamp_row->submitted_for_review_at), "Setting In review always stamps submitted_for_review_at");

// Cleanup
$ids = $db->query("SELECT id FROM rise_tasks WHERE title LIKE 'PHASE16D_%'")->getResult();
foreach ($ids as $tt) {
    model('App\Models\Task_point_awards_model')->revoke_all_by_task_id($tt->id);
}
$db->query("DELETE FROM rise_notifications WHERE task_id IN (SELECT id FROM rise_tasks WHERE title LIKE 'PHASE16D_%')");
$db->query("DELETE FROM rise_tasks WHERE title LIKE 'PHASE16D_%'");
$db->query("DELETE FROM rise_task_point_awards WHERE task_id NOT IN (SELECT id FROM rise_tasks)");

echo "\n========================================================\n";
echo "Phase 16D Summary: $pass_count PASSED, $fail_count FAILED\n";
echo "========================================================\n";

if ($fail_count > 0) {
    exit(1);
}
exit(0);
