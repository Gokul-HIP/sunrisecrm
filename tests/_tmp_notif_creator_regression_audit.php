<?php
/**
 * Regression: creator still gets/sees non-reminder task events after deadline notify_to_terms fix.
 * Reminder forced-creator removal must remain; other events must keep creator/assigner behavior.
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'notifications']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);

$pass = 0; $fail = 0;
$eventResults = [];
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}
function event_result($event, $ok, $note = '') {
    global $eventResults;
    $eventResults[$event] = $ok ? 'PASS' : 'FAIL';
    check("event:$event", $ok, $note);
}
function ids_csv($csv) {
    $parts = array_filter(array_map('trim', explode(',', (string)$csv)));
    sort($parts);
    return $parts;
}
function latest_notif($taskId, $event, $projectId = 0) {
    global $db;
    $sql = "SELECT id, notify_to, to_user_id, event, task_id, project_id FROM rise_notifications WHERE deleted=0 AND event=" . $db->escape($event);
    if ($taskId) {
        $sql .= " AND task_id=" . (int)$taskId;
    } else if ($projectId) {
        $sql .= " AND project_id=" . (int)$projectId;
    }
    $sql .= " ORDER BY id DESC LIMIT 1";
    return $db->query($sql)->getRowArray();
}
function creator_sees($nm, $creatorId, $notifId) {
    // Mirror get_notifications: FIND_IN_SET notify_to + recipient filter
    $data = $nm->get_notifications($creatorId, 0, 50);
    foreach ($data->result as $row) {
        if ((int)$row->id === (int)$notifId) {
            return true;
        }
    }
    return false;
}

echo "=== Creator notification regression audit ===\n";
echo "db=" . $db->getDatabase() . "\n";

$src = file_get_contents(APPPATH . 'Models/Notifications_model.php');

// --- Source contracts: forced creator only removed for the three stage reminders ---
check('no WHERE force-add assigner for stage reminders',
    strpos($src, 'find task assigner / creator for pre reminders') === false
    && strpos($src, 'Stage deadline reminders must respect notify_to_terms') !== false
);
check('stage reminder limiter gates task_creator on notify_to_terms',
    preg_match('/project_task_deadline_2_hours_pre_reminder[\s\S]*?in_array\("task_creator", \$notify_to_terms\)/', $src) === 1
);
check('sent_for_review still hard-limits to creator',
    preg_match('/project_task_sent_for_review"[\s\S]*?_limit_notification_recipients_to_users\([^\)]*\$recipient_ids/', $src) === 1
    && strpos($src, 'project_task_sent_for_review') !== false
);
check('commented/updated/finished still notify assigner when actor is assignee/collab',
    preg_match('/project_task_commented[\s\S]*?_get_task_assigner_id[\s\S]*?_limit_notification_recipients_to_users/', $src) === 1
);
check('display filter still has special branch for commented/updated/finished',
    strpos($src, "'project_task_commented'") !== false
    && strpos($src, 'NULLIF($notifications_table.to_user_id, 0) = $user_id') !== false
);
check('display filter created_by removed only inside deadline branch',
    preg_match('/project_task_deadline_30_mins_pre_reminder[\s\S]{0,400}created_by\s*=\s*\$user_id/', $src) !== 1
);

// --- Fixture ---
$eventsToEnable = [
    'project_task_sent_for_review',
    'project_task_changes_requested',
    'project_task_finished',
    'project_task_commented',
    'project_task_updated',
    'project_task_assigned',
    'project_task_created',
    'project_file_added',
    'project_task_deadline_1_hour_pre_reminder',
];
$origSettings = [];
foreach ($eventsToEnable as $ev) {
    $origSettings[$ev] = $db->query("SELECT notify_to_terms, enable_web FROM rise_notification_settings WHERE event=" . $db->escape($ev))->getRowArray();
    if ($origSettings[$ev]) {
        $db->query("UPDATE rise_notification_settings SET enable_web=1 WHERE event=" . $db->escape($ev));
    }
}

$db->query("INSERT INTO rise_users SET first_name='NReg', last_name='Creator', email='nreg_c_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=0, enable_web_notification=1, enable_email_notification=0");
$creator = (int)$db->insertID();
$db->query("INSERT INTO rise_users SET first_name='NReg', last_name='Assignee', email='nreg_a_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=0, enable_web_notification=1, enable_email_notification=0");
$assignee = (int)$db->insertID();
$db->query("INSERT INTO rise_users SET first_name='NReg', last_name='OtherAdmin', email='nreg_o_" . uniqid() . "@test.local', user_type='staff', is_admin=1, deleted=0, status='active', password='', role_id=0, enable_web_notification=1, enable_email_notification=0");
$otherAdmin = (int)$db->insertID();
$ephemeralUsers = [$creator, $assignee, $otherAdmin];

$project = $db->query("SELECT id FROM rise_projects WHERE deleted=0 ORDER BY id DESC LIMIT 1")->getRow();
$projectId = (int)$project->id;
$todo = (int)(model('App\Models\Task_status_model')->get_one_where(['key_name' => 'to_do', 'deleted' => 0])->id ?? 1);
$done = (int)(model('App\Models\Task_status_model')->get_one_where(['key_name' => 'done', 'deleted' => 0])->id ?? $todo);

$pmIds = [];
foreach ([$creator, $assignee, $otherAdmin] as $uid) {
    $db->query("INSERT INTO rise_project_members SET user_id=$uid, project_id=$projectId, is_leader=0, deleted=0");
    $pmIds[] = (int)$db->insertID();
}

$db->query("INSERT INTO rise_tasks SET title='Notif creator regression', description='audit', project_id=$projectId, milestone_id=0, assigned_to=$assignee, collaborators='', status_id=$todo, points=1, deadline='" . date('Y-m-d') . "', created_date='" . date('Y-m-d') . "', created_by=$creator, deleted=0");
$taskId = (int)$db->insertID();

// Activity log so _get_task_assigner_id resolves to creator
$alTable = $db->prefixTable('activity_logs');
$db->query("INSERT INTO $alTable SET created_at='" . date('Y-m-d H:i:s') . "', created_by=$creator, action='created', log_type='task', log_type_title='Notif creator regression', log_type_id=$taskId, changes='', log_for='project', log_for_id=$projectId, log_for2='', log_for_id2=0, deleted=0");

$nm = model('App\Models\Notifications_model');
$createdNotifIds = [];

function create_and_track($nm, $event, $actorId, $options, &$createdNotifIds, $taskId, $projectId = 0) {
    $nm->create_notification($event, (string)$actorId, $options);
    $n = latest_notif($taskId, $event, $projectId);
    if ($n) {
        $createdNotifIds[] = (int)$n['id'];
    }
    return $n;
}

// 1) In review → creator (settings: task_creator)
$n = create_and_track($nm, 'project_task_sent_for_review', $assignee, [
    'task_id' => $taskId, 'project_id' => $projectId, 'description' => 'reg-review'
], $createdNotifIds, $taskId);
$ids = ids_csv($n['notify_to'] ?? '');
$ok = in_array((string)$creator, $ids) && creator_sees($nm, $creator, $n['id'] ?? 0);
event_result('project_task_sent_for_review', $ok, json_encode(['notify_to' => $ids, 'sees' => $ok]));

// 2) Changes requested → assignee only (settings: task_assignee); creator must NOT get unless assignee
$n = create_and_track($nm, 'project_task_changes_requested', $creator, [
    'task_id' => $taskId, 'project_id' => $projectId, 'description' => 'reg-changes'
], $createdNotifIds, $taskId);
$ids = ids_csv($n['notify_to'] ?? '');
$ok = in_array((string)$assignee, $ids) && !in_array((string)$creator, $ids);
event_result('project_task_changes_requested', $ok, json_encode($ids));

// 3) Approved / finished — assignee completes → assigner/creator gets + sees
$n = create_and_track($nm, 'project_task_finished', $assignee, [
    'task_id' => $taskId, 'project_id' => $projectId, 'description' => 'reg-finished-by-assignee'
], $createdNotifIds, $taskId);
$ids = ids_csv($n['notify_to'] ?? '');
$sees = $n ? creator_sees($nm, $creator, $n['id']) : false;
$ok = in_array((string)$creator, $ids) && $sees;
event_result('project_task_finished (approved/done by assignee)', $ok, json_encode(['notify_to' => $ids, 'to_user_id' => $n['to_user_id'] ?? null, 'sees' => $sees]));

// 3b) Approved by other admin — creator as project_member should be in notify_to (settings: project_members)
$n = create_and_track($nm, 'project_task_finished', $otherAdmin, [
    'task_id' => $taskId, 'project_id' => $projectId, 'description' => 'reg-finished-by-admin'
], $createdNotifIds, $taskId);
$ids = ids_csv($n['notify_to'] ?? '');
$sees = $n ? creator_sees($nm, $creator, $n['id']) : false;
// finished is in display filter requiring to_user_id — when limiter does NOT run (admin not assignee),
// to_user_id may be empty/other; get_notifications requires FIND_IN_SET notify_to AND to_user_id match for finished.
$inNotify = in_array((string)$creator, $ids);
$okAdmin = $inNotify; // recipient list must include creator per project_members
event_result('project_task_finished (approved by other admin → creator in notify_to)', $okAdmin, json_encode(['notify_to' => $ids, 'to_user_id' => $n['to_user_id'] ?? null, 'sees' => $sees]));

// 4) Comment by assignee → creator/assigner gets + sees
$n = create_and_track($nm, 'project_task_commented', $assignee, [
    'task_id' => $taskId, 'project_id' => $projectId, 'description' => 'reg-comment'
], $createdNotifIds, $taskId);
$ids = ids_csv($n['notify_to'] ?? '');
$sees = $n ? creator_sees($nm, $creator, $n['id']) : false;
event_result('project_task_commented', in_array((string)$creator, $ids) && $sees, json_encode(['notify_to' => $ids, 'sees' => $sees]));

// 5) Status / checklist change (project_task_updated) by assignee → creator gets + sees
$n = create_and_track($nm, 'project_task_updated', $assignee, [
    'task_id' => $taskId, 'project_id' => $projectId, 'description' => 'reg-updated-status'
], $createdNotifIds, $taskId);
$ids = ids_csv($n['notify_to'] ?? '');
$sees = $n ? creator_sees($nm, $creator, $n['id']) : false;
event_result('project_task_updated (status/checklist)', in_array((string)$creator, $ids) && $sees, json_encode(['notify_to' => $ids, 'sees' => $sees]));

// 6) Assigned/reassigned — settings task_assignee: assignee gets; creator (not assignee) does not
$n = create_and_track($nm, 'project_task_assigned', $creator, [
    'task_id' => $taskId, 'project_id' => $projectId, 'to_user_id' => $assignee, 'description' => 'reg-assigned'
], $createdNotifIds, $taskId);
$ids = ids_csv($n['notify_to'] ?? '');
$ok = in_array((string)$assignee, $ids) && !in_array((string)$creator, $ids);
event_result('project_task_assigned', $ok, json_encode($ids));

// 7) File added — settings project_members: creator gets + sees (actor = other admin)
$n = create_and_track($nm, 'project_file_added', $otherAdmin, [
    'project_id' => $projectId, 'project_file_id' => 1, 'description' => 'reg-file'
], $createdNotifIds, 0, $projectId);
$ids = ids_csv($n['notify_to'] ?? '');
$sees = $n ? creator_sees($nm, $creator, $n['id']) : false;
event_result('project_file_added', in_array((string)$creator, $ids) && $sees, json_encode(['notify_to' => $ids, 'sees' => $sees]));

// 8) Reminder still excludes creator with current terms
$n = create_and_track($nm, 'project_task_deadline_1_hour_pre_reminder', 0, [
    'task_id' => $taskId, 'project_id' => $projectId, 'description' => 'reg-reminder'
], $createdNotifIds, $taskId);
$ids = ids_csv($n['notify_to'] ?? '');
$ok = in_array((string)$assignee, $ids) && !in_array((string)$creator, $ids);
event_result('project_task_deadline_1_hour_pre_reminder (creator excluded)', $ok, json_encode($ids));

// Visibility specifically: creator must NOT see reminder via get_notifications when not in notify_to
$seesRem = $n ? creator_sees($nm, $creator, $n['id']) : false;
check('creator does not see 1h reminder in get_notifications', !$seesRem);

// Cleanup
register_shutdown_function(function () use ($db, $origSettings, $ephemeralUsers, $taskId, $createdNotifIds, $pmIds, $alTable) {
    foreach ($createdNotifIds as $nid) {
        $db->query('DELETE FROM rise_notifications WHERE id=' . (int)$nid);
    }
    $db->query("DELETE FROM $alTable WHERE log_type='task' AND log_type_id=" . (int)$taskId);
    $db->query('DELETE FROM rise_tasks WHERE id=' . (int)$taskId);
    if ($pmIds) {
        $db->query('DELETE FROM rise_project_members WHERE id IN (' . implode(',', array_map('intval', $pmIds)) . ')');
    }
    $ids = implode(',', array_map('intval', $ephemeralUsers));
    if ($ids) {
        $db->query("DELETE FROM rise_users WHERE id IN ($ids)");
    }
    foreach ($origSettings as $ev => $row) {
        if (!$row) continue;
        $db->query("UPDATE rise_notification_settings SET notify_to_terms=" . $db->escape($row['notify_to_terms'])
            . ", enable_web=" . (int)$row['enable_web']
            . " WHERE event=" . $db->escape($ev));
    }
    echo "cleanup_done\n";
});

echo "\n--- Per-event summary ---\n";
foreach ($eventResults as $ev => $status) {
    echo "$status\t$ev\n";
}
echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
