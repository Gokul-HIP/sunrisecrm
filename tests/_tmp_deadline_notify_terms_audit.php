<?php
/**
 * Deadline reminder notify_to_terms must be respected (no forced creator).
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

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}

echo "=== Deadline notify_to_terms audit ===\n";
echo "db=" . $db->getDatabase() . "\n";

$events3 = [
    'project_task_deadline_2_hours_pre_reminder',
    'project_task_deadline_1_hour_pre_reminder',
    'project_task_deadline_30_mins_pre_reminder',
];

// Source: hardcode removed
$src = file_get_contents(APPPATH . 'Models/Notifications_model.php');
check('no forced assigner block for stage reminders in WHERE', strpos($src, 'find task assigner / creator for pre reminders') === false);
check('stage limit uses notify_to_terms for task_creator', strpos($src, 'Respect stored notify_to_terms only') !== false);
$cfg = get_notification_config('project_task_deadline_2_hours_pre_reminder');
check('built-in default omits task_creator', is_array($cfg['notify_to']) && !in_array('task_creator', $cfg['notify_to']) && in_array('task_assignee', $cfg['notify_to']));
$cfgPre = get_notification_config('project_task_deadline_pre_reminder');
check('pre_reminder default unchanged (has project_members)', in_array('project_members', $cfgPre['notify_to'] ?? []));
$cfgOd = get_notification_config('project_task_deadline_overdue_reminder');
check('overdue default unchanged (has project_members)', in_array('project_members', $cfgOd['notify_to'] ?? []));
$cfgDay = get_notification_config('project_task_reminder_on_the_day_of_deadline');
check('on_the_day default unchanged (has project_members)', in_array('project_members', $cfgDay['notify_to'] ?? []));

// Persist original settings; ensure web enabled for tests
$orig = [];
foreach ($events3 as $ev) {
    $row = $db->query("SELECT * FROM rise_notification_settings WHERE event=" . $db->escape($ev))->getRowArray();
    $orig[$ev] = $row;
    // Current expected state without creator
    $db->query("UPDATE rise_notification_settings SET notify_to_terms='task_assignee,task_collaborators', enable_web=1 WHERE event=" . $db->escape($ev));
}
// Ephemeral users + task
$db->query("INSERT INTO rise_users SET first_name='Rem', last_name='Creator', email='rem_c_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=0, enable_web_notification=1, enable_email_notification=0");
$creator = (int)$db->insertID();
$db->query("INSERT INTO rise_users SET first_name='Rem', last_name='Assignee', email='rem_a_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=0, enable_web_notification=1, enable_email_notification=0");
$assignee = (int)$db->insertID();
$db->query("INSERT INTO rise_users SET first_name='Rem', last_name='Collab', email='rem_k_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=0, enable_web_notification=1, enable_email_notification=0");
$collab = (int)$db->insertID();
$ephemeralUsers = [$creator, $assignee, $collab];

$project = $db->query("SELECT id FROM rise_projects WHERE deleted=0 ORDER BY id DESC LIMIT 1")->getRow();
$todo = (int)(model('App\Models\Task_status_model')->get_one_where(['key_name' => 'to_do', 'deleted' => 0])->id ?? 1);
// Project membership required for non-limited events (pre_reminder path uses permission check)
$pmIds = [];
foreach ([$creator, $assignee, $collab] as $uid) {
    $db->query("INSERT INTO rise_project_members SET user_id=$uid, project_id=" . (int)$project->id . ", is_leader=0, deleted=0");
    $pmIds[] = (int)$db->insertID();
}
$db->query("INSERT INTO rise_tasks SET title='Deadline notify terms audit', description='audit', project_id=" . (int)$project->id . ", milestone_id=0, assigned_to=$assignee, collaborators='$collab', status_id=$todo, points=1, deadline='" . date('Y-m-d') . "', end_time='23:59:00', created_date='" . date('Y-m-d') . "', created_by=$creator, deleted=0");
$taskId = (int)$db->insertID();

$nm = model('App\Models\Notifications_model');
$createdNotifIds = [];

function latest_notif($taskId, $event) {
    global $db;
    return $db->query("SELECT id, notify_to, to_user_id FROM rise_notifications WHERE task_id=" . (int)$taskId . " AND event=" . $db->escape($event) . " AND deleted=0 ORDER BY id DESC LIMIT 1")->getRowArray();
}

function ids_csv($csv) {
    $parts = array_filter(array_map('trim', explode(',', (string)$csv)));
    sort($parts);
    return $parts;
}

foreach ($events3 as $ev) {
    $nm->create_notification($ev, "0", [
        'task_id' => $taskId,
        'project_id' => (int)$project->id,
        'description' => 'audit-' . $ev,
    ]);
    $n = latest_notif($taskId, $ev);
    if ($n) {
        $createdNotifIds[] = (int)$n['id'];
    }
    $ids = ids_csv($n['notify_to'] ?? '');
    check("$ev without creator: assignee present", in_array((string)$assignee, $ids), json_encode($ids));
    check("$ev without creator: collab present", in_array((string)$collab, $ids), json_encode($ids));
    check("$ev without creator: creator absent", !in_array((string)$creator, $ids), json_encode($ids));
}

// With task_creator added
foreach ($events3 as $ev) {
    $db->query("UPDATE rise_notification_settings SET notify_to_terms='task_assignee,task_collaborators,task_creator' WHERE event=" . $db->escape($ev));
}
foreach ($events3 as $ev) {
    $nm->create_notification($ev, "0", [
        'task_id' => $taskId,
        'project_id' => (int)$project->id,
        'description' => 'audit-with-creator-' . $ev,
    ]);
    $n = latest_notif($taskId, $ev);
    if ($n) {
        $createdNotifIds[] = (int)$n['id'];
    }
    $ids = ids_csv($n['notify_to'] ?? '');
    check("$ev with creator term: creator included", in_array((string)$creator, $ids), json_encode($ids));
    check("$ev with creator term: assignee included", in_array((string)$assignee, $ids), json_encode($ids));
}

// Restore terms without creator for remaining tests
foreach ($events3 as $ev) {
    $db->query("UPDATE rise_notification_settings SET notify_to_terms='task_assignee,task_collaborators' WHERE event=" . $db->escape($ev));
}

// Creator = assignee → one id, no duplicate
$db->query("UPDATE rise_tasks SET assigned_to=$creator, collaborators='' WHERE id=$taskId");
$nm->create_notification('project_task_deadline_1_hour_pre_reminder', "0", [
    'task_id' => $taskId,
    'project_id' => (int)$project->id,
    'description' => 'audit-self-assign',
]);
$n = latest_notif($taskId, 'project_task_deadline_1_hour_pre_reminder');
if ($n) {
    $createdNotifIds[] = (int)$n['id'];
}
$ids = ids_csv($n['notify_to'] ?? '');
check('creator=assignee: single recipient', count($ids) === 1 && $ids[0] === (string)$creator, json_encode($ids));

// Other deadline events still honor stored terms (no forced creator inject)
$otherEvents = [
    'project_task_deadline_pre_reminder',
    'project_task_reminder_on_the_day_of_deadline',
    'project_task_deadline_overdue_reminder',
];
$otherOrig = [];
foreach ($otherEvents as $ev) {
    $otherOrig[$ev] = $db->query("SELECT notify_to_terms, enable_web FROM rise_notification_settings WHERE event=" . $db->escape($ev))->getRowArray();
    $db->query("UPDATE rise_notification_settings SET notify_to_terms='task_assignee', enable_web=1 WHERE event=" . $db->escape($ev));
}
$db->query("UPDATE rise_tasks SET assigned_to=$assignee, collaborators='$collab', created_by=$creator WHERE id=$taskId");
foreach ($otherEvents as $ev) {
    $nm->create_notification($ev, "0", [
        'task_id' => $taskId,
        'project_id' => (int)$project->id,
        'description' => 'audit-other-' . $ev,
    ]);
    $n = latest_notif($taskId, $ev);
    if ($n) {
        $createdNotifIds[] = (int)$n['id'];
    }
    $ids = ids_csv($n['notify_to'] ?? '');
    check("$ev uses stored task_assignee", in_array((string)$assignee, $ids), json_encode($ids));
    check("$ev does not force creator", !in_array((string)$creator, $ids), json_encode($ids));
}

// Cleanup
register_shutdown_function(function () use ($db, $orig, $otherOrig, $ephemeralUsers, $taskId, $createdNotifIds, $pmIds) {
    foreach ($createdNotifIds as $nid) {
        $db->query('DELETE FROM rise_notifications WHERE id=' . (int)$nid);
    }
    $db->query('DELETE FROM rise_tasks WHERE id=' . (int)$taskId);
    if ($pmIds) {
        $db->query('DELETE FROM rise_project_members WHERE id IN (' . implode(',', array_map('intval', $pmIds)) . ')');
    }
    $ids = implode(',', array_map('intval', $ephemeralUsers));
    if ($ids) {
        $db->query("DELETE FROM rise_users WHERE id IN ($ids)");
    }
    foreach ($orig as $ev => $row) {
        if (!$row) continue;
        $db->query("UPDATE rise_notification_settings SET notify_to_terms=" . $db->escape($row['notify_to_terms'])
            . ", enable_web=" . (int)$row['enable_web']
            . " WHERE event=" . $db->escape($ev));
    }
    foreach ($otherOrig as $ev => $row) {
        if (!$row) continue;
        $db->query("UPDATE rise_notification_settings SET notify_to_terms=" . $db->escape($row['notify_to_terms'])
            . ", enable_web=" . (int)$row['enable_web']
            . " WHERE event=" . $db->escape($ev));
    }
    echo "cleanup_done\n";
});

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
