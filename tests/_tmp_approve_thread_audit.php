<?php
/**
 * Approve popup: visibility for assignee + one thread per person.
 * Does not edit messages 60/61. Local only.
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
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);

$pass = 0;
$fail = 0;
$createdTaskIds = [];
$createdMessageIds = [];
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

function reload_settings() {
    global $db;
    $rise = config('Rise');
    foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted=0")->getResult() as $s) {
        $rise->app_settings_array[$s->setting_name] = $s->setting_value;
    }
}
reload_settings();
date_default_timezone_set(get_setting('timezone') ?: 'Asia/Kolkata');

$tasks_model = model('App\Models\Tasks_model');
$messages_model = model('App\Models\Messages_model');
$users_model = model('App\Models\Users_model');
$awards_model = model('App\Models\Task_point_awards_model');

$in_review = (int) get_in_review_task_status_id();
$done = (int) get_done_task_status_id();
$project = $db->query("SELECT id FROM rise_projects WHERE deleted=0 AND FIND_IN_SET('open', status) ORDER BY id DESC LIMIT 1")->getRow();

// Real users from the reported bug
$user30 = $db->query("SELECT id,role_id FROM rise_users WHERE id=30")->getRow();
$user66 = $db->query("SELECT id,role_id,is_admin FROM rise_users WHERE id=66")->getRow();
$otherAssignee = $db->query("SELECT id FROM rise_users WHERE deleted=0 AND status='active' AND user_type='staff' AND id NOT IN (30,66) ORDER BY id ASC LIMIT 1")->getRow();

echo "=== Approve thread/visibility audit ===\n";
echo "db=" . $db->getDatabase() . " project={$project->id}\n";

// ---- Cause A reproduction (with simulated allowed ids like role specific 1-10) ----
$allowed_without_66 = '1,2,3,4,5,6,7,8,9,10';
$chat_old = $messages_model->get_chat_list(['login_user_id' => 30, 'user_ids' => $allowed_without_66])->getResultArray();
// After fix, 60/61 should appear because to_user_id=30
$ids_old = array_map('intval', array_column($chat_old, 'id'));
check('A: chat list shows root 60 for user 30 with specific filter excluding 66', in_array(60, $ids_old), 'ids=' . implode(',', $ids_old));
check('A: chat list shows root 61 for user 30 with specific filter excluding 66', in_array(61, $ids_old), 'ids=' . implode(',', $ids_old));
$unread = (int) $messages_model->count_unread_message(30, $allowed_without_66);
check('A: unread count includes msgs to 30 from 66', $unread >= 2, "unread=$unread");
$inbox = $messages_model->get_list(['user_id' => 30, 'mode' => 'inbox', 'user_ids' => $allowed_without_66])->getResultArray();
$inbox_roots = array_map('intval', array_column($inbox, 'main_message_id'));
// get_list returns main_message_id; also check id
$inbox_ids = array_map('intval', array_column($inbox, 'id'));
$inbox_hit = in_array(60, $inbox_ids) || in_array(60, $inbox_roots) || in_array(61, $inbox_ids) || in_array(61, $inbox_roots);
check('A: Messages inbox lists 60/61 for user 30', $inbox_hit, 'inbox_ids=' . implode(',', $inbox_ids));

$notif60 = $db->query("SELECT id FROM rise_notifications WHERE actual_message_id IN (60,61) AND deleted=0")->getResult();
$ns = $db->query("SELECT enable_web,enable_email FROM rise_notification_settings WHERE event='new_message_sent'")->getRow();
check('A: no new_message_sent notification rows for 60/61 (settings web/email off)', count($notif60) === 0);
echo "A_note: new_message_sent enable_web={$ns->enable_web} enable_email={$ns->enable_email} — bell will not show these\n";

// ---- Simulate approve send (mirrors Projects::_send_approval_message_to_assignee) ----
function make_login($id, $is_admin = false) {
    return (object)['id' => (int)$id, 'is_admin' => $is_admin ? 1 : 0, 'user_type' => 'staff'];
}

function make_in_review_task($title, $assigned_to, $created_by, $points = 5.0) {
    global $tasks_model, $project, $in_review, $createdTaskIds;
    $now = get_current_utc_time();
    $row = [
        'title' => $title,
        'description' => 'approve_thread_audit',
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

function route_approve($task_id, $login, $msg = '', $file_names = null, $file_sizes = null) {
    global $tasks_model, $messages_model, $users_model, $done;

    $task_info = $tasks_model->get_one($task_id);
    if (!$login->is_admin && (int)$login->id !== (int)$task_info->created_by) {
        return ['success' => false, 'message' => 'forbidden'];
    }
    if ($done && (int)$task_info->status_id === $done) {
        return ['success' => true, 'already_approved' => true, 'id' => (int)$task_info->id];
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
        return ['success' => false];
    }
    if (function_exists('sync_task_point_award')) {
        sync_task_point_award($save_id);
    }

    $response = ['success' => true, 'id' => $save_id];
    $has_files = is_array($file_names) && !empty($file_names[0]);
    if ($msg === '' && !$has_files) {
        return $response;
    }

    $assignee_id = (int)$task_info->assigned_to;
    if ($assignee_id <= 0 || $assignee_id === (int)$login->id) {
        $response['message_warning'] = 'skip';
        return $response;
    }
    $assignee = $users_model->get_one($assignee_id);
    if (!$assignee || !(int)$assignee->id || (int)$assignee->deleted === 1) {
        $response['message_warning'] = 'skip';
        return $response;
    }

    if ($has_files) {
        foreach ($file_names as $fname) {
            if ($fname && !is_valid_file_to_upload($fname)) {
                $response['message_warning'] = 'badfile';
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

    $title = function_exists('mb_substr') ? mb_substr($task_info->title, 0, 120) : substr($task_info->title, 0, 120);
    $header = 'Task approved: #' . (int)$task_id . ' ' . $title;
    $safe_typed = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    $body = $header;
    if ($safe_typed !== '') {
        $body .= "\n\n" . $safe_typed;
    }
    $body .= "\n\nTask: " . get_uri('projects/task_view/' . (int)$task_id);

    $root = $messages_model->get_open_thread_between_users($login->id, $assignee_id);
    $parent = ($root && !empty($root->id)) ? (int)$root->id : 0;

    $message_data = [
        'from_user_id' => $login->id,
        'to_user_id' => $assignee_id,
        'message_id' => $parent,
        'subject' => $parent ? '' : 'Task review messages',
        'message' => $body,
        'created_at' => get_current_utc_time(),
        'deleted_by_users' => '',
    ];
    $message_data = clean_data($message_data);
    $message_data['files'] = $files_data;
    $message_data['message_id'] = $parent;

    $mid = $messages_model->ci_save($message_data);
    if ($mid && $parent) {
        $messages_model->clear_deleted_status($parent);
    }
    $response['message_id'] = (int)$mid;
    $response['thread_id'] = $parent ?: (int)$mid;
    return $response;
}

function award_pts($task_id, $uid) {
    global $awards_model;
    $a = $awards_model->get_by_task_and_user($task_id, $uid);
    return ($a && !empty($a->id)) ? (float)$a->earned_points : null;
}

function chat_other_party_ids($login_id, $allowed) {
    global $messages_model;
    $rows = $messages_model->get_chat_list(['login_user_id' => $login_id, 'user_ids' => $allowed])->getResult();
    $others = [];
    foreach ($rows as $r) {
        // other party is not login user — from chat list join: we need another user id
        // get_chat_list doesn't return another_user_id directly; infer from from_user_id vs login
        $other = ((int)$r->from_user_id === (int)$login_id) ? null : (int)$r->from_user_id;
        // better: look up root
        global $db;
        $root = $db->query('SELECT from_user_id,to_user_id FROM rise_messages WHERE id=' . (int)$r->id)->getRow();
        if (!$root) continue;
        $oid = ((int)$root->from_user_id === (int)$login_id) ? (int)$root->to_user_id : (int)$root->from_user_id;
        $others[] = $oid;
    }
    return $others;
}

register_shutdown_function(function () use ($db, &$createdTaskIds, &$createdMessageIds, &$tempFiles) {
    foreach ($createdMessageIds as $mid) {
        $db->query('DELETE FROM rise_messages WHERE id=' . (int)$mid . ' OR message_id=' . (int)$mid);
    }
    // also delete replies we created under existing roots (by tracking ids)
    foreach ($createdMessageIds as $mid) {
        $db->query('DELETE FROM rise_messages WHERE id=' . (int)$mid);
    }
    foreach ($createdTaskIds as $tid) {
        try { $db->query('DELETE FROM rise_task_point_awards WHERE task_id=' . (int)$tid); } catch (Throwable $e) {}
        try { $db->query('DELETE FROM rise_notifications WHERE task_id=' . (int)$tid); } catch (Throwable $e) {}
        $db->query('DELETE FROM rise_tasks WHERE id=' . (int)$tid);
    }
    foreach ($tempFiles as $f) {
        if (is_file($f)) @unlink($f);
    }
    echo "cleanup_done\n";
});

$login66 = make_login(66, true);
$allowed30 = $allowed_without_66; // role reality

// Mark max message before our creates so cleanup can find them
$maxBefore = (int)($db->query('SELECT MAX(id) m FROM rise_messages')->getRow()->m ?? 0);

// Use a FRESH pair of users for clean threading tests to avoid 60/61 pollution:
// create ephemeral staff users
$db->query("INSERT INTO rise_users SET first_name='Appr', last_name='Audit', email='appr_audit_" . uniqid() . "@test.local', user_type='staff', is_admin=1, deleted=0, status='active', password='', role_id=0");
$ephemeralApprover = (int)$db->insertID();
$db->query("INSERT INTO rise_users SET first_name='Asgn', last_name='Audit', email='asgn_audit_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=5");
$ephemeralAssignee = (int)$db->insertID();
$db->query("INSERT INTO rise_users SET first_name='Asgn2', last_name='Audit', email='asgn2_audit_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=5");
$ephemeralAssignee2 = (int)$db->insertID();
$ephemeralUsers = [$ephemeralApprover, $ephemeralAssignee, $ephemeralAssignee2];

$loginEp = make_login($ephemeralApprover, true);

// Baseline points via neither-message approve
$tBase = make_in_review_task('THREAD baseline', $ephemeralAssignee, $ephemeralApprover, 5);
$rBase = route_approve($tBase, $loginEp, '');
$basePts = award_pts($tBase, $ephemeralAssignee);
check('points baseline approve ok', !empty($rBase['success']));

// Two approvals → one conversation
$t1 = make_in_review_task('THREAD first', $ephemeralAssignee, $ephemeralApprover, 5);
$r1 = route_approve($t1, $loginEp, 'first note');
check('first approve+message success', !empty($r1['success']) && !empty($r1['message_id']));
$createdMessageIds[] = (int)$r1['message_id'];
$thread1 = (int)$r1['thread_id'];
$m1 = $db->query('SELECT * FROM rise_messages WHERE id=' . (int)$r1['message_id'])->getRowArray();
check('first is root message_id=0', $m1 && (int)$m1['message_id'] === 0);
check('first subject Task review messages', $m1 && $m1['subject'] === 'Task review messages');
check('first body starts Task approved', $m1 && strpos($m1['message'], 'Task approved: #' . $t1) === 0);

$t2 = make_in_review_task('THREAD second', $ephemeralAssignee, $ephemeralApprover, 5);
$r2 = route_approve($t2, $loginEp, 'second note');
check('second approve+message success', !empty($r2['success']) && !empty($r2['message_id']));
$createdMessageIds[] = (int)$r2['message_id'];
$m2 = $db->query('SELECT * FROM rise_messages WHERE id=' . (int)$r2['message_id'])->getRowArray();
check('second is reply to same thread', $m2 && (int)$m2['message_id'] === $thread1, 'parent=' . ($m2['message_id'] ?? '') . " thread=$thread1");
check('second empty subject', $m2 && $m2['subject'] === '');
check('same thread_id returned', (int)$r2['thread_id'] === $thread1);

// Popup lists person once for assignee
$chatAsgn = $messages_model->get_chat_list(['login_user_id' => $ephemeralAssignee])->getResult();
$rootsWithApprover = 0;
foreach ($chatAsgn as $c) {
    $root = $db->query('SELECT from_user_id,to_user_id FROM rise_messages WHERE id=' . (int)$c->id)->getRow();
    if (!$root) continue;
    $other = ((int)$root->from_user_id === $ephemeralAssignee) ? (int)$root->to_user_id : (int)$root->from_user_id;
    if ($other === $ephemeralApprover) {
        $rootsWithApprover++;
    }
}
check('assignee popup lists approver once', $rootsWithApprover === 1, "count=$rootsWithApprover");

$chatAppr = $messages_model->get_chat_list(['login_user_id' => $ephemeralApprover])->getResult();
$rootsWithAsgn = 0;
foreach ($chatAppr as $c) {
    $root = $db->query('SELECT from_user_id,to_user_id FROM rise_messages WHERE id=' . (int)$c->id)->getRow();
    if (!$root) continue;
    $other = ((int)$root->from_user_id === $ephemeralApprover) ? (int)$root->to_user_id : (int)$root->from_user_id;
    if ($other === $ephemeralAssignee) {
        $rootsWithAsgn++;
    }
}
check('approver popup lists assignee once', $rootsWithAsgn === 1, "count=$rootsWithAsgn");

// Thread has two bubbles
$bubbles = $db->query('SELECT id FROM rise_messages WHERE deleted=0 AND (id=' . $thread1 . ' OR message_id=' . $thread1 . ') ORDER BY id')->getResult();
check('thread has 2 messages', count($bubbles) === 2, 'count=' . count($bubbles));

// Unread for assignee
$un = (int)$messages_model->count_unread_message($ephemeralAssignee, '1,2,3'); // filter excluding both ephemeral
check('assignee unread >= 2 despite narrow allowed list', $un >= 2, "unread=$un");

// Different assignee → separate thread
$t3 = make_in_review_task('THREAD other asgn', $ephemeralAssignee2, $ephemeralApprover, 5);
$r3 = route_approve($t3, $loginEp, 'for other');
check('other assignee message ok', !empty($r3['message_id']));
$createdMessageIds[] = (int)$r3['message_id'];
check('other assignee gets different thread', (int)$r3['thread_id'] !== $thread1, 't3=' . $r3['thread_id'] . " t1=$thread1");

// Deleted thread starts new one
$messages_model->delete_messages_for_user($thread1, $ephemeralAssignee);
$open = $messages_model->get_open_thread_between_users($ephemeralApprover, $ephemeralAssignee);
check('deleted thread not reused', !$open);

$t4 = make_in_review_task('THREAD after delete', $ephemeralAssignee, $ephemeralApprover, 5);
$r4 = route_approve($t4, $loginEp, 'after delete');
check('new root after delete', !empty($r4['message_id']));
$createdMessageIds[] = (int)$r4['message_id'];
$m4 = $db->query('SELECT message_id,subject FROM rise_messages WHERE id=' . (int)$r4['message_id'])->getRow();
check('after delete is new root', $m4 && (int)$m4->message_id === 0 && $m4->subject === 'Task review messages');
$newThread = (int)$r4['thread_id'];
check('new thread != old', $newThread !== $thread1);

// Files on second approval in same (new) thread
$tmpName = 'thread_audit_' . uniqid() . '.pdf';
$tempDir = getcwd() . '/' . get_setting('temp_file_path');
if (!is_dir($tempDir)) @mkdir($tempDir, 0777, true);
$tmpPath = $tempDir . $tmpName;
file_put_contents($tmpPath, "%PDF-1.4\nthread\n");
$tempFiles[] = $tmpPath;
$t5 = make_in_review_task('THREAD with file', $ephemeralAssignee, $ephemeralApprover, 5);
$r5 = route_approve($t5, $loginEp, 'with file', [$tmpName], [(string)filesize($tmpPath)]);
check('file approve ok', !empty($r5['message_id']));
$createdMessageIds[] = (int)$r5['message_id'];
$m5 = $db->query('SELECT message_id,files FROM rise_messages WHERE id=' . (int)$r5['message_id'])->getRow();
check('file msg is reply in new thread', $m5 && (int)$m5->message_id === $newThread, 'parent=' . ($m5->message_id ?? ''));
$files = @unserialize($m5->files ?? '');
check('files present on reply', is_array($files) && count($files) > 0);

// Points unchanged vs baseline
$pts1 = award_pts($t1, $ephemeralAssignee);
$pts5 = award_pts($t5, $ephemeralAssignee);
check('points first == baseline', $pts1 === $basePts, "p1=$pts1 base=$basePts");
check('points with-file == baseline', $pts5 === $basePts, "p5=$pts5 base=$basePts");

// Source: controller uses get_open_thread_between_users
$src = file_get_contents(APPPATH . 'Controllers/Projects.php');
check('controller uses get_open_thread_between_users', strpos($src, 'get_open_thread_between_users') !== false);
check('controller subject Task review messages', strpos($src, 'Task review messages') !== false);

// C: deadline creator — report only (assert source)
$nh = file_get_contents(APPPATH . 'Helpers/notifications_helper.php');
check('C: code default includes task_creator', preg_match('/project_task_deadline_2_hours_pre_reminder.*?task_creator/s', $nh) === 1);
$ds = $db->query("SELECT notify_to_terms FROM rise_notification_settings WHERE event='project_task_deadline_2_hours_pre_reminder'")->getRow();
check('C: setting notify_to_terms includes task_creator', $ds && strpos($ds->notify_to_terms, 'task_creator') !== false, $ds->notify_to_terms ?? '');

// Cleanup ephemeral users at end
register_shutdown_function(function () use ($db, $ephemeralUsers, $maxBefore) {
    // delete any messages created after maxBefore involving ephemeral users
    $ids = implode(',', array_map('intval', $ephemeralUsers));
    if ($ids) {
        $db->query("DELETE FROM rise_messages WHERE id>$maxBefore AND (from_user_id IN ($ids) OR to_user_id IN ($ids))");
        $db->query("DELETE FROM rise_users WHERE id IN ($ids)");
    }
});

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
