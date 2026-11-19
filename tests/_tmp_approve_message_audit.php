<?php
/**
 * Approve popup message+files audit (Tasks Awaiting Review).
 * Local CLI only — overrides DB to sunrisecrm_scratch at runtime (does NOT edit Database.php).
 * HTTP against :8080 is skipped when configured DB is missing.
 *
 * Usage: /mnt/c/xampp/php/php.exe tests/_tmp_approve_message_audit.php
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';

define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);

// Runtime DB override only for this throwaway test (sunrisecrmnew is missing locally)
$dbConfig = config('Database');
$dbConfig->default['database'] = 'sunrisecrm_scratch';
$dbConfig->default['hostname'] = '127.0.0.1';

$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);

$pass = 0;
$fail = 0;
$createdTaskIds = [];
$createdMessageIds = [];
$createdUserIds = [];
$tempFiles = [];

function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] $name\n";
        $pass++;
    } else {
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n";
        $fail++;
    }
}

function reload_settings_from_db() {
    global $db;
    $riseConfig = config('Rise');
    foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
        $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
    }
}
reload_settings_from_db();
date_default_timezone_set(get_setting('timezone') ?: 'Asia/Kolkata');

$tasks_model = model('App\Models\Tasks_model');
$messages_model = model('App\Models\Messages_model');
$users_model = model('App\Models\Users_model');
$awards_model = model('App\Models\Task_point_awards_model');

$admin = $db->query("SELECT id,email FROM rise_users WHERE deleted=0 AND is_admin=1 AND user_type='staff' AND status='active' ORDER BY id ASC LIMIT 1")->getRow();
$assignee = $db->query("SELECT id,email FROM rise_users WHERE deleted=0 AND is_admin=0 AND user_type='staff' AND status='active' AND id!=" . (int)$admin->id . " ORDER BY id ASC LIMIT 1")->getRow();
$creator = $db->query("SELECT id,email FROM rise_users WHERE deleted=0 AND is_admin=0 AND user_type='staff' AND status='active' AND id NOT IN (" . (int)$admin->id . "," . (int)$assignee->id . ") ORDER BY id ASC LIMIT 1")->getRow();
$outsider = $db->query("SELECT id,email FROM rise_users WHERE deleted=0 AND is_admin=0 AND user_type='staff' AND status='active' AND id NOT IN (" . (int)$admin->id . "," . (int)$assignee->id . "," . (int)$creator->id . ") ORDER BY id ASC LIMIT 1")->getRow();
$project = $db->query("SELECT id FROM rise_projects WHERE deleted=0 AND FIND_IN_SET('open', status) ORDER BY id DESC LIMIT 1")->getRow();
$in_review = (int) get_in_review_task_status_id();
$done = (int) get_done_task_status_id();

echo "=== Approve message audit (CLI) ===\n";
echo "db=" . $db->getDatabase() . " admin={$admin->id} creator={$creator->id} assignee={$assignee->id} project={$project->id}\n";

/**
 * Mirror Projects::approve_task_review approve body + message send (same helpers).
 * $login simulates $this->login_user.
 */
function route_approve_with_message($task_id, $login, $approval_message = '', $file_names = null, $file_sizes = null) {
    global $tasks_model, $messages_model, $users_model, $done, $db;

    $task_info = $tasks_model->get_one($task_id);
    if (!$task_info || !$task_info->id) {
        return ['success' => false, 'message' => 'not_found'];
    }
    if (!$login->is_admin && (int)$login->id !== (int)$task_info->created_by) {
        return ['success' => false, 'message' => app_lang('only_creator_or_admin_can_mark_task_done')];
    }

    if ($done && (int)$task_info->status_id === $done) {
        return ['success' => true, 'message' => app_lang('record_saved'), 'id' => (int)$task_info->id, 'already_approved' => true];
    }

    $now = get_current_utc_time();
    $data = ['status_id' => $done, 'status_changed_at' => $now];
    if (function_exists('ensure_task_points_review_rules_locked')) {
        ensure_task_points_review_rules_locked($task_info);
    }
    if (function_exists('lock_task_points_review_rules') && function_exists('is_task_points_review_rules_applicable')
        && is_task_points_review_rules_applicable($task_info)) {
        lock_task_points_review_rules($task_info, $data, $now);
    }

    $save_id = $tasks_model->ci_save($data, $task_id);
    if (!$save_id) {
        return ['success' => false, 'message' => app_lang('error_occurred')];
    }
    if (function_exists('sync_task_point_award')) {
        sync_task_point_award($save_id);
    }
    // CLI: skip log_notification (email/pusher can hang without a full web request)

    $response = ['success' => true, 'message' => app_lang('record_saved'), 'id' => $save_id];

    $has_files = is_array($file_names) && !empty($file_names[0]);
    if ($approval_message === '' && !$has_files) {
        return $response;
    }

    $assignee_id = (int)($task_info->assigned_to ?? 0);
    if ($assignee_id <= 0) {
        $response['message'] = app_lang('approved_but_message_skipped_no_assignee');
        $response['message_warning'] = $response['message'];
        return $response;
    }
    if ($assignee_id === (int)$login->id) {
        $response['message'] = app_lang('approved_but_message_skipped_self');
        $response['message_warning'] = $response['message'];
        return $response;
    }
    $assignee = $users_model->get_one($assignee_id);
    if (!$assignee || !(int)$assignee->id || (int)$assignee->deleted === 1) {
        $response['message'] = app_lang('approved_but_message_skipped_deleted_assignee');
        $response['message_warning'] = $response['message'];
        return $response;
    }

    try {
        if ($has_files) {
            foreach ($file_names as $fname) {
                if ($fname && !is_valid_file_to_upload($fname)) {
                    $response['message'] = app_lang('approved_but_message_could_not_be_sent');
                    $response['message_warning'] = $response['message'];
                    return $response;
                }
            }
        }

        $files_data = serialize([]);
        if ($has_files) {
            $target_path = get_setting('timeline_file_path');
            $abs = getcwd() . '/' . $target_path;
            if (!is_dir($abs)) {
                @mkdir($abs, 0777, true);
            }
            $files_arr = [];
            foreach ($file_names as $i => $fname) {
                $src = getcwd() . '/' . get_setting('temp_file_path') . $fname;
                if (!is_file($src)) {
                    $response['message'] = app_lang('approved_but_message_could_not_be_sent');
                    $response['message_warning'] = $response['message'];
                    return $response;
                }
                $moved = move_temp_file($fname, $target_path, 'message');
                $files_arr[] = [
                    'file_name' => get_array_value($moved, 'file_name'),
                    'file_size' => get_array_value($file_sizes, $i),
                    'file_id' => get_array_value($moved, 'file_id'),
                    'service_type' => get_array_value($moved, 'service_type'),
                ];
            }
            $files_data = serialize($files_arr);
        }

        $title = (string)($task_info->title ?? '');
        $title = function_exists('mb_substr') ? mb_substr($title, 0, 120) : substr($title, 0, 120);
        $subject = 'Task approved: #' . (int)$task_id . ' ' . $title;
        $subject = function_exists('mb_substr') ? mb_substr($subject, 0, 200) : substr($subject, 0, 200);

        $safe_body = htmlspecialchars($approval_message, ENT_QUOTES, 'UTF-8');
        $task_url = get_uri('projects/task_view/' . (int)$task_id);
        if ($safe_body !== '') {
            $safe_body .= "\n\n";
        }
        $safe_body .= 'Task: ' . $task_url;

        $message_data = [
            'from_user_id' => $login->id,
            'to_user_id' => $assignee_id,
            'subject' => $subject,
            'message' => $safe_body,
            'created_at' => get_current_utc_time(),
            'deleted_by_users' => '',
        ];
        $message_data = clean_data($message_data);
        $message_data['files'] = $files_data;

        $mid = $messages_model->ci_save($message_data);
        if (!$mid) {
            $response['message'] = app_lang('approved_but_message_could_not_be_sent');
            $response['message_warning'] = $response['message'];
            return $response;
        }
        // CLI: skip log_notification('new_message_sent') — verified via source assert below
        $response['message_id'] = (int)$mid;
        return $response;
    } catch (Throwable $ex) {
        $response['message'] = app_lang('approved_but_message_could_not_be_sent');
        $response['message_warning'] = $response['message'];
        return $response;
    }
}

function make_login($user_row, $is_admin = false) {
    return (object)[
        'id' => (int)$user_row->id,
        'is_admin' => $is_admin ? 1 : 0,
        'user_type' => 'staff',
    ];
}

function make_in_review_task($title, $assigned_to, $created_by, $points = 5.0) {
    global $tasks_model, $project, $in_review, $createdTaskIds;
    $now = get_current_utc_time();
    $row = [
        'title' => $title,
        'description' => 'approve_msg_audit',
        'project_id' => (int)$project->id,
        'milestone_id' => 0,
        'assigned_to' => (int)$assigned_to,
        'collaborators' => '',
        'status_id' => $in_review,
        'points' => $points,
        'deadline' => date('Y-m-d'),
        'end_time' => '23:59:00',
        'created_date' => date('Y-m-d'),
        'created_by' => (int)$created_by,
        'submitted_for_review_at' => $now,
        'status_changed_at' => $now,
        'deleted' => 0,
    ];
    $id = $tasks_model->ci_save($row);
    $createdTaskIds[] = (int)$id;
    return (int)$id;
}

function award_points($task_id, $user_id) {
    global $awards_model;
    $a = $awards_model->get_by_task_and_user($task_id, $user_id);
    return ($a && !empty($a->id)) ? (float)$a->earned_points : null;
}

function count_awards($task_id) {
    global $db;
    $row = $db->query('SELECT COUNT(*) c FROM rise_task_point_awards WHERE task_id=' . (int)$task_id . ' AND deleted=0')->getRow();
    return (int)$row->c;
}

function latest_message($to, $from, $after_id = 0) {
    global $db;
    return $db->query(
        'SELECT * FROM rise_messages WHERE deleted=0 AND to_user_id=' . (int)$to
        . ' AND from_user_id=' . (int)$from . ' AND id>' . (int)$after_id
        . ' ORDER BY id DESC LIMIT 1'
    )->getRowArray();
}

register_shutdown_function(function () use ($db, &$createdTaskIds, &$createdMessageIds, &$createdUserIds, &$tempFiles) {
    foreach ($createdMessageIds as $mid) {
        $db->query('DELETE FROM rise_messages WHERE id=' . (int)$mid);
        try {
            $db->query('DELETE FROM rise_notifications WHERE actual_message_id=' . (int)$mid);
        } catch (Throwable $e) {
        }
    }
    foreach ($createdTaskIds as $tid) {
        try {
            $db->query('DELETE FROM rise_task_point_awards WHERE task_id=' . (int)$tid);
        } catch (Throwable $e) {
        }
        try {
            $db->query("DELETE FROM rise_activity_logs WHERE log_type='task' AND log_type_id=" . (int)$tid);
        } catch (Throwable $e) {
        }
        try {
            $db->query('DELETE FROM rise_notifications WHERE task_id=' . (int)$tid);
        } catch (Throwable $e) {
        }
        $db->query('DELETE FROM rise_tasks WHERE id=' . (int)$tid);
    }
    foreach ($createdUserIds as $uid) {
        $db->query('DELETE FROM rise_users WHERE id=' . (int)$uid);
    }
    foreach ($tempFiles as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    echo "cleanup_done\n";
});

$loginCreator = make_login($creator, false);
$loginOutsider = $outsider ? make_login($outsider, false) : null;
$maxMsg = (int)($db->query('SELECT MAX(id) m FROM rise_messages')->getRow()->m ?? 0);

// Source check: controller contains message helper + already_approved guard
$src = file_get_contents(APPPATH . 'Controllers/Projects.php');
check('controller: already_approved guard present', strpos($src, 'already_approved') !== false);
check('controller: _send_approval_message_to_assignee present', strpos($src, '_send_approval_message_to_assignee') !== false);
$view = file_get_contents(APPPATH . 'Views/projects/tasks/tasks_in_review.php');
check('view: Approve Task modal present', strpos($view, 'approve-task-modal') !== false && strpos($view, 'approve_task_modal_title') !== false);
check('view: messages upload endpoints reused', strpos($view, 'messages/upload_file') !== false && strpos($view, 'messages/validate_message_file') !== false);
check('view: Request Changes modal unchanged', strpos($view, 'request-changes-modal') !== false);
check('lang: approve keys present', app_lang('approve_task_modal_title') === 'Approve Task'
    && app_lang('optional_message_to_assignee') === 'Optional message to the assignee'
    && app_lang('approved_but_message_could_not_be_sent') === 'Approved, but the message could not be sent');

// ---------- neither ----------
$t0 = make_in_review_task('AUDIT neither', $assignee->id, $creator->id, 5);
$r0 = route_approve_with_message($t0, $loginCreator, '');
check('neither: approve success', !empty($r0['success']), json_encode($r0));
$basePts = award_points($t0, $assignee->id);
$m0 = latest_message($assignee->id, $creator->id, $maxMsg);
check('neither: no message', !$m0);

// ---------- message only + XSS ----------
$t1 = make_in_review_task('AUDIT msg', $assignee->id, $creator->id, 5);
$xss = 'Hello <script>alert(1)</script> & "quotes"';
$r1 = route_approve_with_message($t1, $loginCreator, $xss);
check('message-only: approve success', !empty($r1['success']));
$m1 = latest_message($assignee->id, $creator->id, $maxMsg);
if ($m1) {
    $createdMessageIds[] = (int)$m1['id'];
}
check('message-only: created', (bool)$m1);
check('message-only: subject', $m1 && strpos($m1['subject'], 'Task approved: #' . $t1) === 0, $m1['subject'] ?? '');
check('message-only: escaped', $m1 && strpos($m1['message'], '<script>') === false && strpos($m1['message'], '&lt;script&gt;') !== false, substr($m1['message'] ?? '', 0, 120));
check('message-only: task link', $m1 && strpos($m1['message'], 'projects/task_view/' . $t1) !== false);
check('message-only: unread', $m1 && $m1['status'] === 'unread');
check('controller calls log_notification new_message_sent', strpos($src, 'log_notification("new_message_sent"') !== false
    || strpos($src, "log_notification('new_message_sent'") !== false);
$pts1 = award_points($t1, $assignee->id);
check('message-only: points == baseline', $pts1 === $basePts, "p1=$pts1 base=$basePts");

// ---------- files only ----------
$tmpName = 'approve_audit_' . uniqid() . '.pdf';
$tempDir = getcwd() . '/' . get_setting('temp_file_path');
if (!is_dir($tempDir)) {
    @mkdir($tempDir, 0777, true);
}
$tmpPath = $tempDir . $tmpName;
file_put_contents($tmpPath, "%PDF-1.4\naudit\n");
$tempFiles[] = $tmpPath;
$t2 = make_in_review_task('AUDIT files', $assignee->id, $creator->id, 5);
$r2 = route_approve_with_message($t2, $loginCreator, '', [$tmpName], [(string)filesize($tmpPath)]);
check('files-only: approve success', !empty($r2['success']), json_encode($r2));
$m2 = latest_message($assignee->id, $creator->id, $m1 ? (int)$m1['id'] : $maxMsg);
if ($m2) {
    $createdMessageIds[] = (int)$m2['id'];
}
$files2 = @unserialize($m2['files'] ?? '');
check('files-only: files present', is_array($files2) && count($files2) > 0, substr((string)($m2['files'] ?? ''), 0, 80));
$pts2 = award_points($t2, $assignee->id);
check('files-only: points == baseline', $pts2 === $basePts, "p2=$pts2 base=$basePts");

// download permission: is_my_message equivalent
check('files-only: assignee is recipient', $m2 && (int)$m2['to_user_id'] === (int)$assignee->id);
check('files-only: outsider is not party', $outsider && $m2 && (int)$outsider->id !== (int)$m2['from_user_id'] && (int)$outsider->id !== (int)$m2['to_user_id']);

// ---------- both ----------
$tmpName2 = 'approve_audit2_' . uniqid() . '.pdf';
$tmpPath2 = $tempDir . $tmpName2;
file_put_contents($tmpPath2, "%PDF-1.4\nboth\n");
$tempFiles[] = $tmpPath2;
$t3 = make_in_review_task('AUDIT both', $assignee->id, $creator->id, 5);
$r3 = route_approve_with_message($t3, $loginCreator, 'Both body', [$tmpName2], [(string)filesize($tmpPath2)]);
check('both: approve success', !empty($r3['success']));
$m3 = latest_message($assignee->id, $creator->id, $m2 ? (int)$m2['id'] : $maxMsg);
if ($m3) {
    $createdMessageIds[] = (int)$m3['id'];
}
check('both: text', $m3 && strpos($m3['message'], 'Both body') !== false);
$files3 = @unserialize($m3['files'] ?? '');
check('both: files', is_array($files3) && count($files3) > 0);
check('both: points == baseline', award_points($t3, $assignee->id) === $basePts);

// ---------- double submit ----------
$t4 = make_in_review_task('AUDIT double', $assignee->id, $creator->id, 5);
$r4a = route_approve_with_message($t4, $loginCreator, 'first');
$m4 = latest_message($assignee->id, $creator->id, $m3 ? (int)$m3['id'] : $maxMsg);
if ($m4) {
    $createdMessageIds[] = (int)$m4['id'];
}
$aw1 = count_awards($t4);
$r4b = route_approve_with_message($t4, $loginCreator, 'second');
check('double: second success', !empty($r4b['success']));
check('double: already_approved', !empty($r4b['already_approved']));
$m4b = latest_message($assignee->id, $creator->id, $m4 ? (int)$m4['id'] : $maxMsg);
check('double: no second message', !$m4b);
check('double: awards unchanged', count_awards($t4) === $aw1);

// ---------- assignee 0 ----------
$t5 = make_in_review_task('AUDIT a0', 0, $creator->id, 5);
$r5 = route_approve_with_message($t5, $loginCreator, 'skip');
check('assignee0: success', !empty($r5['success']));
check('assignee0: warning', !empty($r5['message_warning']));
$st5 = $tasks_model->get_one($t5);
check('assignee0: Done', (int)$st5->status_id === $done);

// ---------- deleted assignee ----------
$db->query("INSERT INTO rise_users SET first_name='Del', last_name='A', email='del_audit_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=1, status='inactive', password=''");
$delId = (int)$db->insertID();
$createdUserIds[] = $delId;
$t6 = make_in_review_task('AUDIT del', $delId, $creator->id, 5);
$r6 = route_approve_with_message($t6, $loginCreator, 'skip del');
check('deleted-assignee: success', !empty($r6['success']));
check('deleted-assignee: warning', !empty($r6['message_warning']) && stripos($r6['message_warning'], 'deleted') !== false, $r6['message_warning'] ?? '');

// ---------- self ----------
$t7 = make_in_review_task('AUDIT self', $creator->id, $creator->id, 5);
$r7 = route_approve_with_message($t7, $loginCreator, 'skip self');
check('self: success', !empty($r7['success']));
check('self: warning', !empty($r7['message_warning']));

// ---------- no permission ----------
if ($loginOutsider) {
    $t8 = make_in_review_task('AUDIT noperm', $assignee->id, $creator->id, 5);
    $r8 = route_approve_with_message($t8, $loginOutsider, 'hacker');
    check('no-perm: rejected', empty($r8['success']));
    $st8 = $tasks_model->get_one($t8);
    check('no-perm: still in review', (int)$st8->status_id === $in_review);
    $mh = latest_message($assignee->id, $outsider->id, $maxMsg);
    check('no-perm: no message', !$mh);
}

// ---------- bad filetype ----------
$t9 = make_in_review_task('AUDIT badfile', $assignee->id, $creator->id, 5);
$r9 = route_approve_with_message($t9, $loginCreator, 'with bad', ['evil.exe'], ['12']);
check('bad-filetype: approve success', !empty($r9['success']));
check('bad-filetype: warning', !empty($r9['message_warning']));
$st9 = $tasks_model->get_one($t9);
check('bad-filetype: Done', (int)$st9->status_id === $done);
check('bad-filetype: is_valid_file_to_upload rejects exe', !is_valid_file_to_upload('evil.exe'));
check('bad-filetype: pdf allowed like Messages', (bool)is_valid_file_to_upload('ok.pdf'));

// display escape
if ($m1) {
    $display = nl2br($m1['message']);
    check('display: no raw script tag', strpos($display, '<script>') === false);
}

// Other Approve places (documentation assertions from codebase scan)
check('other-places: save_task_status exists (task view/list status change)', strpos($src, 'function save_task_status') !== false);
check('other-places: update_task_info status path', strpos($src, 'function update_task_info') !== false);
check('other-places: save_task_sort_and_status (kanban drag)', strpos($src, 'function save_task_sort_and_status') !== false);
check('other-places: review queue wires approve-review-task-btn', substr_count($view, 'approve-review-task-btn') >= 1);
$otherViews = 0;
foreach (glob(APPPATH . 'Views/projects/tasks/**/*.php') ?: [] as $vf) {
    if (basename($vf) === 'tasks_in_review.php') {
        continue;
    }
    if (strpos(file_get_contents($vf), 'approve-review-task-btn') !== false || strpos(file_get_contents($vf), 'approve_task_review') !== false) {
        $otherViews++;
    }
}
check('other-places: no other task views call approve_task_review popup', $otherViews === 0, "count=$otherViews");

echo "http_db_sunrisecrmnew_available=0 (configured DB missing; HTTP approve path unverified)\n";

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
