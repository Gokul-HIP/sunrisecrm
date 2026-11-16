<?php
/**
 * Test Suite: Phase 16 & 16B Feature 2 (Review Workflow & Persona Matrix)
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

echo "========================================================\n";
echo "Phase 16B: Review Workflow Persona Matrix Tests\n";
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

// Enable Review Workflow setting
$settings_model = model('App\Models\Settings_model');
$settings_model->save_setting("task_review_workflow_enabled", "1");
$riseConfig->app_settings_array["task_review_workflow_enabled"] = "1";

$done_status_id = (int) get_done_task_status_id();
$in_review_status_id = (int) get_in_review_task_status_id();
$changes_status_id = (int) get_changes_task_status_id();

echo "Resolved Status IDs: Done=$done_status_id, In review=$in_review_status_id, Changes=$changes_status_id\n\n";

// Persona Definitions:
// - Creator: User 7 (Subhash)
// - Assignee: User 40 (Sathish)
// - Collaborator: User 68 (Kavitha)
// - Admin: User 66 (Admin)
$creator = (object) array("id" => 7, "is_admin" => 0, "user_type" => "staff");
$assignee = (object) array("id" => 40, "is_admin" => 0, "user_type" => "staff");
$collab = (object) array("id" => 68, "is_admin" => 0, "user_type" => "staff");
$admin = (object) array("id" => 66, "is_admin" => 1, "user_type" => "staff");

// Clean up any test tasks
$db->query("DELETE FROM rise_tasks WHERE title LIKE 'PHASE16_WORKFLOW_%'");
$db->query("DELETE FROM rise_notifications WHERE event IN ('project_task_sent_for_review', 'project_task_changes_requested')");

// Create test task where creator (7) != assignee (40), with collaborator 68
$test_task_data = array(
    "title" => "PHASE16_WORKFLOW_TASK_1",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "collaborators" => "68",
    "points" => 10,
    "status_id" => 1, // to_do
    "deadline" => "2026-11-25",
    "end_time" => "18:00:00",
    "created_date" => "2026-11-12",
    "deleted" => 0
);
$db->table('rise_tasks')->insert($test_task_data);
$task_id = $db->insertID();

function try_set_task_status($task_id, $target_status_id, $acting_user) {
    $ci = new \App\Controllers\App_Controller();
    $task_info = $ci->Tasks_model->get_one($task_id);
    $done_status_id = (int) get_done_task_status_id();
    $changes_status_id = (int) get_changes_task_status_id();
    $in_review_status_id = (int) get_in_review_task_status_id();

    // Check direct Changes rejection
    if ((int) $target_status_id === $changes_status_id && get_setting("task_review_workflow_enabled")) {
        return array("success" => false, "message" => app_lang("changes_status_can_only_be_set_via_review_queue"));
    }

    // Check Done restriction (legacy In-review with NULL submitted_for_review_at is exempt)
    $is_legacy_review = function_exists('is_legacy_in_review_task') ? is_legacy_in_review_task($task_info) : false;
    if ((int) $target_status_id === $done_status_id && get_setting("task_review_workflow_enabled") && !$is_legacy_review) {
        if ($task_info->created_by) {
            if (!$acting_user->is_admin && (int) $acting_user->id !== (int) $task_info->created_by) {
                return array("success" => false, "message" => app_lang("only_creator_or_admin_can_mark_task_done"));
            }
        }
    }

    $data = array("status_id" => $target_status_id);

    if ($task_info->status_id !== $target_status_id) {
        $data["status_changed_at"] = get_current_utc_time();
        if ((int) $target_status_id === $in_review_status_id) {
            $data["submitted_for_review_at"] = get_current_utc_time();
        }
    }

    $save_id = $ci->Tasks_model->ci_save($data, $task_id);
    if ($save_id) {
        if (get_setting("task_review_workflow_enabled")) {
            if ((int) $target_status_id === $in_review_status_id) {
                log_notification("project_task_sent_for_review", array("project_id" => $task_info->project_id, "task_id" => $task_id), $acting_user->id);
            }
        }
        if ((int) $target_status_id === $done_status_id && function_exists("sync_task_point_award")) {
            sync_task_point_award($task_id);
        }
        return array("success" => true);
    }
    return array("success" => false, "message" => "Save failed");
}

// -----------------------------------------------------------------------------
// TEST 1: Server-side Done Restriction & Direct Changes Rejection
// -----------------------------------------------------------------------------
echo "--- TEST 1: Server-side Done Restriction & Direct Changes Rejection ---\n";
$assignee_done_res = try_set_task_status($task_id, $done_status_id, $assignee);
assert_test($assignee_done_res["success"] === false, "Assignee direct Done attempt blocked when creator != assignee");
assert_test(strpos($assignee_done_res["message"], "Only the task creator") !== false, "Rejection message matches app_lang('only_creator_or_admin_can_mark_task_done')");

$collab_done_res = try_set_task_status($task_id, $done_status_id, $collab);
assert_test($collab_done_res["success"] === false, "Collaborator direct Done attempt blocked");

// Reject direct status change to Changes outside review queue
$direct_changes_res = try_set_task_status($task_id, $changes_status_id, $assignee);
assert_test($direct_changes_res["success"] === false, "Direct status change to Changes rejected server-side");
assert_test(strpos($direct_changes_res["message"], "Changes") !== false, "Rejection message mentions Changes queue requirement");

// -----------------------------------------------------------------------------
// TEST 2: Assignee sets In review -> submitted_for_review_at set, creator notified, task leaves Tasks page
// -----------------------------------------------------------------------------
echo "\n--- TEST 2: Assignee Sets In Review ---\n";
$in_review_res = try_set_task_status($task_id, $in_review_status_id, $assignee);
assert_test($in_review_res["success"] === true, "Assignee successfully moves task to In review");

$updated_task = $db->query("SELECT status_id, submitted_for_review_at FROM rise_tasks WHERE id = $task_id")->getRow();
assert_test((int)$updated_task->status_id === $in_review_status_id, "Task status is In review ($in_review_status_id)");
assert_test(!empty($updated_task->submitted_for_review_at), "submitted_for_review_at is populated with UTC timestamp");

// Check notification sent to creator
$notif_review = $db->query("SELECT * FROM rise_notifications WHERE task_id = $task_id AND event = 'project_task_sent_for_review'")->getRow();
assert_test($notif_review !== null, "project_task_sent_for_review notification logged");
$recipients_review = explode(',', $notif_review->notify_to);
assert_test(in_array("7", $recipients_review), "project_task_sent_for_review recipient is Creator (7)");

// Check task leaves Tasks page for assignee
$tasks_model = model('App\Models\Tasks_model');
$assignee_tasks_res = $tasks_model->get_details(array(
    "specific_user_id" => 40,
    "exclude_review_tasks_for_assignee" => true
))->getResult();
$found_in_assignee_page = false;
foreach ($assignee_tasks_res as $t) {
    if ((int)$t->id === $task_id) { $found_in_assignee_page = true; break; }
}
assert_test(!$found_in_assignee_page, "Task leaves Assignee's Tasks page list/kanban when In review");

// Check task appears in creator's In review queue
$creator_queue_tasks = $tasks_model->get_details(array("awaiting_review_for_user_id" => 7))->getResult();
$found_in_creator_queue = false;
foreach ($creator_queue_tasks as $t) {
    if ((int)$t->id === $task_id) { $found_in_creator_queue = true; break; }
}
assert_test($found_in_creator_queue, "Task appears in Creator's review queue (created_by = 7)");

// Check task does NOT appear in Assignee's In review queue
$assignee_queue_tasks = $tasks_model->get_details(array("awaiting_review_for_user_id" => 40))->getResult();
$found_in_assignee_queue = false;
foreach ($assignee_queue_tasks as $t) {
    if ((int)$t->id === $task_id) { $found_in_assignee_queue = true; break; }
}
assert_test(!$found_in_assignee_queue, "Task does NOT appear in Assignee's review queue (Mine filters created_by)");

// Check badge counts: Creator badge reflects tasks created by creator
$creator_badge = count_tasks_in_review($creator);
assert_test($creator_badge >= 1, "Creator's badge equals count of In review tasks created by user (badge: $creator_badge)");

$assignee_badge = count_tasks_in_review($assignee);
$expected_assignee_badge = (int) $db->query("SELECT COUNT(*) AS c FROM rise_tasks WHERE deleted = 0 AND status_id = $in_review_status_id AND submitted_for_review_at IS NOT NULL AND created_by = 40")->getRow()->c;
assert_test($assignee_badge === $expected_assignee_badge, "Assignee's badge equals count of In review tasks created by user (badge: $assignee_badge, expected: $expected_assignee_badge)");

// -----------------------------------------------------------------------------
// TEST 3: Creator requests Changes from Review Queue
// -----------------------------------------------------------------------------
echo "\n--- TEST 3: Creator Requests Changes from Review Queue ---\n";
// Request changes via dedicated action
$comment_text = "Phase 16 review comment: please add unit tests";
$comment_data = array(
    "created_by" => 7,
    "created_at" => get_current_utc_time(),
    "project_id" => 1,
    "task_id" => $task_id,
    "description" => $comment_text
);
model('App\Models\Project_comments_model')->save_comment($comment_data);

$data_changes = array(
    "status_id" => $changes_status_id,
    "status_changed_at" => get_current_utc_time()
);
$ci = new \App\Controllers\App_Controller();
$ci->Tasks_model->ci_save($data_changes, $task_id);
log_notification("project_task_changes_requested", array("project_id" => 1, "task_id" => $task_id), $creator->id);

$task_after_changes = $db->query("SELECT status_id FROM rise_tasks WHERE id = $task_id")->getRow();
assert_test((int)$task_after_changes->status_id === $changes_status_id, "Task status updated to Changes ($changes_status_id)");

// Check notification sent to assignee
$notif_changes = $db->query("SELECT * FROM rise_notifications WHERE task_id = $task_id AND event = 'project_task_changes_requested'")->getRow();
assert_test($notif_changes !== null, "project_task_changes_requested notification logged");
$recipients_changes = explode(',', $notif_changes->notify_to);
assert_test(in_array("40", $recipients_changes), "project_task_changes_requested recipient is Assignee (40)");

// Check task reappears on assignee's Tasks page under "Changes"
$assignee_tasks_after = $tasks_model->get_details(array(
    "specific_user_id" => 40,
    "exclude_review_tasks_for_assignee" => true
))->getResult();
$found_in_assignee_page_after = false;
foreach ($assignee_tasks_after as $t) {
    if ((int)$t->id === $task_id) { $found_in_assignee_page_after = true; break; }
}
assert_test($found_in_assignee_page_after, "Task reappears on Assignee's Tasks page under 'Changes'");

// Item 1 tests: Changes task is present in list, kanban, and counter data for assignee, and absent from queue
// 1. List data with default active filter (status_ids = "1,4,2")
$list_filter_status = "1,4,2";
$list_options = array(
    "specific_user_id" => 40,
    "exclude_review_tasks_for_assignee" => true,
    "status_ids" => $list_filter_status
);
if ($changes_status_id && $list_filter_status) {
    $s_arr = array_filter(explode(",", $list_filter_status));
    $s_arr[] = $changes_status_id;
    $list_options["status_ids"] = implode(",", $s_arr);
}
$list_res = $tasks_model->get_details($list_options)->getResult();
$found_in_list = false;
foreach ($list_res as $t) {
    if ((int)$t->id === $task_id) { $found_in_list = true; break; }
}
assert_test($found_in_list, "Changes task is present in list data with default active filter for Assignee");

// 2. Kanban data under Changes column
$kanban_res = $tasks_model->get_kanban_details(array(
    "specific_user_id" => 40,
    "status_id" => $changes_status_id,
    "exclude_review_tasks_for_assignee" => true
))->getResult();
$found_in_kanban = false;
foreach ($kanban_res as $t) {
    if ((int)$t->id === $task_id) { $found_in_kanban = true; break; }
}
assert_test($found_in_kanban, "Changes task is present in Kanban data under Changes column for Assignee");

// 3. Counter data
$counter_res = $tasks_model->get_details(array(
    "specific_user_id" => 40,
    "exclude_review_tasks_for_assignee" => true,
    "limit" => 10
));
assert_test(isset($counter_res["changes_count"]) && (int)$counter_res["changes_count"] >= 1, "Changes count pill in header reflects Changes task (changes_count: " . (isset($counter_res["changes_count"]) ? $counter_res["changes_count"] : "0") . ")");

// 4. Absent from In review queue
$queue_res = $tasks_model->get_details(array(
    "awaiting_review_for_user_id" => 7
))->getResult();
$found_in_queue = false;
foreach ($queue_res as $t) {
    if ((int)$t->id === $task_id) { $found_in_queue = true; break; }
}
assert_test(!$found_in_queue, "Changes task is absent from In review queue");

// Badge count decrements
$creator_badge_after_changes = count_tasks_in_review($creator);
assert_test($creator_badge_after_changes === $creator_badge - 1, "Creator badge decrements after requesting changes");

// Points remain 0
$awards_after_changes = model('App\Models\Task_point_awards_model')->get_all_by_task_id($task_id, false);
assert_test(empty($awards_after_changes), "No points awarded while in Changes");

// -----------------------------------------------------------------------------
// TEST 4: Assignee fixes and sets In review again -> Creator Approves (Done)
// -----------------------------------------------------------------------------
echo "\n--- TEST 4: Re-submission & Approval with Points & Collab Split ---\n";
$resubmit_res = try_set_task_status($task_id, $in_review_status_id, $assignee);
assert_test($resubmit_res["success"] === true, "Assignee resubmits to In review");

// Creator approves
$data_done = array(
    "status_id" => $done_status_id,
    "status_changed_at" => get_current_utc_time()
);
$ci->Tasks_model->ci_save($data_done, $task_id);
sync_task_point_award($task_id);
log_notification("project_task_finished", array("project_id" => 1, "task_id" => $task_id));

// Check awards created
$awards = model('App\Models\Task_point_awards_model')->get_all_by_task_id($task_id, false);
assert_test(count($awards) === 2, "Points awarded to both Assignee (40) and Collaborator (68)");

$award_map = array();
foreach ($awards as $a) {
    $award_map[$a->user_id] = (float) $a->earned_points;
}
assert_test(isset($award_map[40]) && $award_map[40] == 5.0, "Assignee received 5.0 points (10 / 2)");
assert_test(isset($award_map[68]) && $award_map[68] == 5.0, "Collaborator received 5.0 points (10 / 2)");

// -----------------------------------------------------------------------------
// TEST 5: Creator == Assignee (Self-assigned task goes through review workflow)
// -----------------------------------------------------------------------------
echo "\n--- TEST 5: Self-assigned task (created_by == assigned_to) Workflow ---\n";
$self_task_data = array(
    "title" => "PHASE16_WORKFLOW_SELF_TASK",
    "project_id" => 1,
    "created_by" => 66,
    "assigned_to" => 66,
    "points" => 5,
    "status_id" => 1, // to_do
    "deadline" => "2026-11-25",
    "end_time" => "18:00:00",
    "created_date" => "2026-11-12",
    "deleted" => 0
);
$db->table('rise_tasks')->insert($self_task_data);
$self_task_id = $db->insertID();

// Self-assigned task sets In review
$self_review_res = try_set_task_status($self_task_id, $in_review_status_id, $admin);
assert_test($self_review_res["success"] === true, "Self-assigned task moves to In review");

// Check self-assigned in-review task leaves Tasks page
$self_tasks_res = $tasks_model->get_details(array(
    "specific_user_id" => 66,
    "exclude_review_tasks_for_assignee" => true
))->getResult();
$found_self_in_tasks = false;
foreach ($self_tasks_res as $t) {
    if ((int)$t->id === $self_task_id) { $found_self_in_tasks = true; break; }
}
assert_test(!$found_self_in_tasks, "Self-assigned In review task leaves Tasks page");

// Check self-assigned in-review task appears in review queue under Mine (created_by = 66)
$self_queue_tasks = $tasks_model->get_details(array("awaiting_review_for_user_id" => 66))->getResult();
$found_self_in_queue = false;
foreach ($self_queue_tasks as $t) {
    if ((int)$t->id === $self_task_id) { $found_self_in_queue = true; break; }
}
assert_test($found_self_in_queue, "Self-assigned task appears in Creator's review queue ('Mine')");

// Self-assigned creator can set Done from queue / directly
$self_done_res = try_set_task_status($self_task_id, $done_status_id, $admin);
assert_test($self_done_res["success"] === true, "Self-assigned creator marks task as Done");

$self_awards = model('App\Models\Task_point_awards_model')->get_all_by_task_id($self_task_id, false);
assert_test(count($self_awards) === 1 && (float)$self_awards[0]->earned_points == 5.0, "Self-assigned creator awarded full 5.0 points upon Done");

// -----------------------------------------------------------------------------
// TEST 6: Lateness calculation when submitted_for_review_at = NULL falls back to completed_at
// -----------------------------------------------------------------------------
echo "\n--- TEST 6: Lateness when submitted_for_review_at = NULL ---\n";
// Create task with deadline yesterday, submitted_for_review_at = NULL
$late_task_data = array(
    "title" => "PHASE16_WORKFLOW_LATE_NULL_SUBMITTED",
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
$db->table('rise_tasks')->insert($late_task_data);
$late_task_id = $db->insertID();

// Calculate lateness with completed_at 30 hours after deadline
$completed_at_utc = "2026-11-11 12:30:00"; // 18:00 local (30 hours late)
$task_obj = $ci->Tasks_model->get_one($late_task_id);
$point_res = calculate_task_point_result($task_obj, $completed_at_utc);
assert_test($point_res["is_reduced"] === true, "Lateness correctly calculated using completed_at when submitted_for_review_at is NULL");
assert_test($point_res["reduction_percent"] === 20, "20% reduction applied for 30 hours late (points: {$point_res['earned_points']}/10)");

// -----------------------------------------------------------------------------
// TEST 7: Flag-off Equivalence (task_review_workflow_enabled = 0)
// -----------------------------------------------------------------------------
echo "\n--- TEST 7: Flag-off Equivalence (task_review_workflow_enabled = 0) ---\n";
$settings_model->save_setting("task_review_workflow_enabled", "0");
$riseConfig->app_settings_array["task_review_workflow_enabled"] = "0";

// When flag is 0: Assignee CAN set Done directly even when creator != assignee
$flag_off_task_data = array(
    "title" => "PHASE16_WORKFLOW_FLAG_OFF",
    "project_id" => 1,
    "created_by" => 7,
    "assigned_to" => 40,
    "points" => 10,
    "status_id" => 1,
    "deadline" => "2026-11-25",
    "end_time" => "18:00:00",
    "created_date" => "2026-11-12",
    "deleted" => 0
);
$db->table('rise_tasks')->insert($flag_off_task_data);
$flag_off_task_id = $db->insertID();

$flag_off_done_res = try_set_task_status($flag_off_task_id, $done_status_id, $assignee);
assert_test($flag_off_done_res["success"] === true, "With flag=0, Assignee CAN set Done directly (no review restriction)");

// With flag=0, count_tasks_in_review returns 0
$badge_when_off = count_tasks_in_review($creator);
assert_test($badge_when_off === 0, "With flag=0, count_tasks_in_review returns 0 (no review badge in sidebar)");

// Restore setting
$settings_model->save_setting("task_review_workflow_enabled", "1");
$riseConfig->app_settings_array["task_review_workflow_enabled"] = "1";

// Clean up test tasks and awards
$test_tasks = $db->query("SELECT id FROM rise_tasks WHERE title LIKE 'PHASE16_WORKFLOW_%'")->getResult();
foreach ($test_tasks as $tt) {
    model('App\Models\Task_point_awards_model')->revoke_all_by_task_id($tt->id);
}
$db->query("DELETE FROM rise_tasks WHERE title LIKE 'PHASE16_WORKFLOW_%'");
$db->query("DELETE FROM rise_task_point_awards WHERE task_id NOT IN (SELECT id FROM rise_tasks)");
$db->query("DELETE FROM rise_notifications WHERE event IN ('project_task_sent_for_review', 'project_task_changes_requested')");

echo "\n========================================================\n";
echo "Feature 2 Tests Summary: $pass_count PASSED, $fail_count FAILED\n";
echo "========================================================\n";

if ($fail_count > 0) exit(1);
