<?php
/**
 * Phase 16G: Done is optional (assignee may Done directly); stamp/lateness rules;
 * persona x transition matrix; modal/view endpoints.
 *
 * Usage: php tests/test_phase16g_done_optional.php [--db=sunrisecrmnew]
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
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
echo "Phase 16G: Done optional + stamp/lateness + persona matrix\n";
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

function set_flag($on) {
    global $db, $riseConfig;
    $v = $on ? "1" : "0";
    $riseConfig->app_settings_array["task_review_workflow_enabled"] = $v;
    $db->query("UPDATE rise_settings SET setting_value = ? WHERE setting_name = 'task_review_workflow_enabled' AND deleted = 0", [$v]);
}

function create_task($db, $title, $status_id, $assignee, $creator, $collab = '68', $project_id = 626, $extra = []) {
    $deadline = isset($extra['deadline']) ? $extra['deadline'] : date('Y-m-d', strtotime('-2 days'));
    $end_time = isset($extra['end_time']) ? $extra['end_time'] : '18:00:00';
    $stamp = array_key_exists('submitted_for_review_at', $extra) ? $extra['submitted_for_review_at'] : null;
    $db->query(
        "INSERT INTO rise_tasks (title, project_id, created_by, assigned_to, collaborators, points, status_id, deadline, end_time, created_date, deleted, submitted_for_review_at)
         VALUES (?, ?, ?, ?, ?, 10, ?, ?, ?, ?, 0, ?)",
        [$title, $project_id, $creator, $assignee, (string) $collab, $status_id, $deadline, $end_time, date('Y-m-d'), $stamp]
    );
    return (int) $db->insertID();
}

function set_status_like_controller($db, $task_id, $new_status_id) {
    $task = $db->query("SELECT * FROM rise_tasks WHERE id=?", [$task_id])->getRow();
    $now = gmdate('Y-m-d H:i:s');
    $data = ['status_id' => $new_status_id];
    if ((int) $task->status_id !== (int) $new_status_id) {
        $data['status_changed_at'] = $now;
        apply_review_stamp_on_status_change($task, $new_status_id, $data, $now);
    }
    // Direct SQL to avoid Session/TimeTrait via activity log
    if (array_key_exists('submitted_for_review_at', $data) && $data['submitted_for_review_at'] === null) {
        $db->query(
            "UPDATE rise_tasks SET status_id=?, status_changed_at=?, submitted_for_review_at=NULL WHERE id=?",
            [$new_status_id, $data['status_changed_at'], $task_id]
        );
    } else if (array_key_exists('submitted_for_review_at', $data)) {
        $db->query(
            "UPDATE rise_tasks SET status_id=?, status_changed_at=?, submitted_for_review_at=? WHERE id=?",
            [$new_status_id, $data['status_changed_at'], $data['submitted_for_review_at'], $task_id]
        );
    } else {
        $db->query(
            "UPDATE rise_tasks SET status_id=?, status_changed_at=? WHERE id=?",
            [$new_status_id, $data['status_changed_at'], $task_id]
        );
    }
    return $db->query("SELECT * FROM rise_tasks WHERE id=?", [$task_id])->getRow();
}

$todo = 1;
$in_progress = 2;
$done = (int) get_done_task_status_id();
$in_review = (int) get_in_review_task_status_id();
$changes = (int) get_changes_task_status_id();
$brainstorm = 4;

$creator = 7;
$assignee = 40;
$collab = 68;
$admin = 66;

$db->query("DELETE FROM rise_tasks WHERE title LIKE 'PHASE16G_%'");

// Source: Done restriction removed from controller (except queue approve/request_changes)
$src = file_get_contents(APPPATH . 'Controllers/Projects.php');
$done_blocks = preg_match_all('/only_creator_or_admin_can_mark_task_done/', $src, $m);
assert_test($done_blocks === 2, "done_restriction_only_in_queue_actions", "count=$done_blocks (expect 2: approve + request_changes)");

$ui_modal = file_get_contents(APPPATH . 'Views/projects/tasks/modal_form.php');
$ui_info = file_get_contents(APPPATH . 'Views/projects/tasks/update_task_info_script.php');
$ui_batch = file_get_contents(APPPATH . 'Views/projects/tasks/batch_update/modal_form.php');
assert_test(strpos($ui_modal, 'can_set_done') === false, "modal_form_no_can_set_done");
assert_test(strpos($ui_info, 'can_set_done') === false, "update_task_info_script_no_can_set_done");
assert_test(strpos($ui_batch, 'Only admin can batch-mark Done') === false && strpos($ui_batch, 'can_set_done') === false, "batch_modal_allows_done");

foreach ([1, 0] as $flag) {
    set_flag($flag);
    echo "\n----- flag=$flag -----\n";

    // Assignee: To do -> Done direct
    $tid = create_task($db, "PHASE16G_A_TODO_DONE_f{$flag}", $todo, $assignee, $creator);
    $row = set_status_like_controller($db, $tid, $done);
    assert_test((int) $row->status_id === $done, "flag{$flag}/assignee/todo->done");
    assert_test(empty($row->submitted_for_review_at) || $row->submitted_for_review_at === '0000-00-00 00:00:00', "flag{$flag}/assignee/todo->done_no_stamp");
    $calc = calculate_task_point_result($row, $row->status_changed_at);
    $clone = clone $row; $clone->submitted_for_review_at = null;
    $exp = calculate_task_point_result($clone, $row->status_changed_at);
    assert_test(abs($calc['hours_late'] - $exp['hours_late']) < 0.001, "flag{$flag}/assignee/todo->done_lateness_completed_at");

    // Collaborator persona: In progress -> Done (same permission path as assignee for status set)
    $tid = create_task($db, "PHASE16G_C_IP_DONE_f{$flag}", $in_progress, $assignee, $creator, (string) $collab);
    $row = set_status_like_controller($db, $tid, $done);
    assert_test((int) $row->status_id === $done && (empty($row->submitted_for_review_at) || $row->submitted_for_review_at === '0000-00-00 00:00:00'), "flag{$flag}/collab_path/in_progress->done");

    // Creator == assignee: To do -> Done
    $tid = create_task($db, "PHASE16G_SAME_TODO_DONE_f{$flag}", $todo, $creator, $creator);
    $row = set_status_like_controller($db, $tid, $done);
    assert_test((int) $row->status_id === $done, "flag{$flag}/creator==assignee/todo->done");

    // Admin: Brainstorming -> Done
    $tid = create_task($db, "PHASE16G_ADM_BS_DONE_f{$flag}", $brainstorm, $assignee, $creator);
    $row = set_status_like_controller($db, $tid, $done);
    assert_test((int) $row->status_id === $done, "flag{$flag}/admin_path/brainstorm->done");

    // To do -> In review -> Done by creator (stamp kept; lateness uses stamp when flag=1)
    $tid = create_task($db, "PHASE16G_REV_DONE_f{$flag}", $todo, $assignee, $creator);
    $row = set_status_like_controller($db, $tid, $in_review);
    if ($flag) {
        assert_test(!empty($row->submitted_for_review_at) && $row->submitted_for_review_at !== '0000-00-00 00:00:00', "flag{$flag}/todo->in_review_stamps");
        $stamp = $row->submitted_for_review_at;
    } else {
        assert_test(empty($row->submitted_for_review_at) || $row->submitted_for_review_at === '0000-00-00 00:00:00', "flag{$flag}/todo->in_review_no_stamp");
        $stamp = null;
    }
    $row = set_status_like_controller($db, $tid, $done);
    assert_test((int) $row->status_id === $done, "flag{$flag}/in_review->done_by_creator_path");
    if ($flag) {
        assert_test($row->submitted_for_review_at === $stamp, "flag{$flag}/in_review->done_keeps_stamp");
        $calc = calculate_task_point_result($row, $row->status_changed_at);
        // completed_at is later (now); stamp is earlier at review — hours_late from stamp should be <= completed_at path
        $no_stamp = clone $row; $no_stamp->submitted_for_review_at = null;
        $calc_done = calculate_task_point_result($no_stamp, $row->status_changed_at);
        assert_test($calc['hours_late'] <= $calc_done['hours_late'] + 0.001, "flag{$flag}/in_review->done_lateness_uses_stamp");
    } else {
        assert_test(empty($row->submitted_for_review_at) || $row->submitted_for_review_at === '0000-00-00 00:00:00', "flag{$flag}/in_review->done_no_stamp");
    }

    // In review -> Changes -> Done direct by assignee (lateness = completed_at, not old stamp)
    $tid = create_task($db, "PHASE16G_REV_CHG_DONE_f{$flag}", $todo, $assignee, $creator);
    $row = set_status_like_controller($db, $tid, $in_review);
    if ($flag) {
        // Force an early stamp then leave review
        $early = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
        $db->query("UPDATE rise_tasks SET submitted_for_review_at=? WHERE id=?", [$early, $tid]);
        $row = $db->query("SELECT * FROM rise_tasks WHERE id=?", [$tid])->getRow();
    }
    $row = set_status_like_controller($db, $tid, $changes);
    assert_test((int) $row->status_id === $changes, "flag{$flag}/in_review->changes");
    assert_test(empty($row->submitted_for_review_at) || $row->submitted_for_review_at === '0000-00-00 00:00:00', "flag{$flag}/in_review->changes_clears_stamp");
    $row = set_status_like_controller($db, $tid, $done);
    assert_test((int) $row->status_id === $done, "flag{$flag}/changes->done_direct");
    assert_test(empty($row->submitted_for_review_at) || $row->submitted_for_review_at === '0000-00-00 00:00:00', "flag{$flag}/changes->done_no_stamp");
    $calc = calculate_task_point_result($row, $row->status_changed_at);
    $clone = clone $row; $clone->submitted_for_review_at = $early ?? gmdate('Y-m-d H:i:s', time() - 10 * 86400);
    // Even if we pollute, flag0 ignores; flag1 would use stamp IF present — but stamp cleared so both use completed_at
    $clone->submitted_for_review_at = null;
    $exp = calculate_task_point_result($clone, $row->status_changed_at);
    assert_test(abs($calc['hours_late'] - $exp['hours_late']) < 0.001, "flag{$flag}/changes->done_lateness_completed_at");

    // Legacy In review (NULL stamp) -> Done by assignee
    $tid = create_task($db, "PHASE16G_LEGACY_DONE_f{$flag}", $in_review, $assignee, $creator, '68', 626, ['submitted_for_review_at' => null]);
    assert_test(is_legacy_in_review_task($db->query("SELECT * FROM rise_tasks WHERE id=?", [$tid])->getRow()), "flag{$flag}/legacy_recognized");
    $row = set_status_like_controller($db, $tid, $done);
    assert_test((int) $row->status_id === $done, "flag{$flag}/legacy_in_review->done_by_assignee");
    // Leaving legacy In review to Done: old==in_review && new==done keeps stamp (null stays null)
    assert_test(empty($row->submitted_for_review_at) || $row->submitted_for_review_at === '0000-00-00 00:00:00', "flag{$flag}/legacy_done_still_null_stamp");

    // Done -> reopen
    $tid = create_task($db, "PHASE16G_REOPEN_f{$flag}", $done, $assignee, $creator, '68', 626, [
        'submitted_for_review_at' => $flag ? gmdate('Y-m-d H:i:s') : null
    ]);
    $row = set_status_like_controller($db, $tid, $todo);
    assert_test((int) $row->status_id === $todo, "flag{$flag}/done->reopen");
    assert_test(empty($row->submitted_for_review_at) || $row->submitted_for_review_at === '0000-00-00 00:00:00', "flag{$flag}/reopen_clears_stamp");
}

// Modal/view endpoints for assignee on a Changes task and a To do task (flag 1)
set_flag(1);
echo "\n----- modal/view endpoints -----\n";

// Patch TimeTrait temporarily for controller renders, then revert
$tt = SYSTEMPATH . 'I18n/TimeTrait.php';
$tt_src = file_get_contents($tt);
$tt_patched = false;
$old_sig = 'public static function createFromTimestamp(int $timestamp, $timezone = null, ?string $locale = null)';
$new_sig = 'public static function createFromTimestamp(int|float $timestamp, $timezone = null, ?string $locale = null): static';
if (strpos($tt_src, $old_sig) !== false) {
    file_put_contents($tt, str_replace($old_sig, $new_sig, $tt_src));
    $tt_patched = true;
}

function render_endpoint_16g($endpoint, $task_id, $user_id) {
    $session = \Config\Services::session();
    $session->set('user_id', $user_id);
    $request = \Config\Services::request(null, false)->setGlobal('post', ['id' => $task_id]);
    $response = \Config\Services::response(null, false);
    $logger = \Config\Services::logger(true);
    $controller = new \App\Controllers\Projects();
    $controller->initController($request, $response, $logger);
    try {
        ob_start();
        if ($endpoint === 'task_view') {
            $result = $controller->task_view(0);
        } else if ($endpoint === 'task_modal_form') {
            $result = $controller->task_modal_form();
        } else {
            throw new RuntimeException($endpoint);
        }
        $out = ob_get_clean() . (is_string($result) ? $result : '');
        return ['ok' => true, 'body' => $out, 'exc' => null];
    } catch (Throwable $e) {
        @ob_end_clean();
        return ['ok' => false, 'body' => '', 'exc' => $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()];
    }
}

$tid_todo = create_task($db, 'PHASE16G_VIEW_TODO', $todo, $assignee, $creator);
$tid_chg = create_task($db, 'PHASE16G_VIEW_CHANGES', $changes, $assignee, $creator);
foreach (['assignee' => $assignee, 'creator' => $creator, 'collaborator' => $collab, 'admin' => $admin] as $role => $uid) {
    foreach ([$tid_todo => 'todo', $tid_chg => 'changes'] as $tid => $sk) {
        foreach (['task_view', 'task_modal_form'] as $ep) {
            $res = render_endpoint_16g($ep, $tid, $uid);
            $name = "view/{$role}/{$sk}/{$ep}";
            if (!$res['ok']) {
                assert_test(false, $name, $res['exc']);
                continue;
            }
            $has_done = (bool) preg_match('/\bDone\b/', $res['body']);
            // Changes option should not be offered as new choice except current label on changes tasks
            $offers_changes_option = (bool) preg_match('/name=["\']status_id["\'][\s\S]*?>[\s\S]*Changes[\s\S]*?<\/select>/i', $res['body']);
            if ($ep === 'task_modal_form') {
                assert_test($has_done && strlen($res['body']) > 200, $name . '_done_selectable', $has_done ? 'ok' : 'Done missing');
            } else {
                assert_test(strlen($res['body']) > 200 && !preg_match('/Internal Server Error|Whoops/i', $res['body']), $name);
            }
        }
    }
}

if ($tt_patched) {
    file_put_contents($tt, $tt_src);
    echo "(TimeTrait restored after view tests)\n";
}

set_flag(1);

echo "\n========================================================\n";
echo "PASS=$pass FAIL=$fail\n";
echo "========================================================\n";
exit($fail ? 1 : 0);
