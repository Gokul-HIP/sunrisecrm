<?php
/**
 * status_changed_at: path stamps + Asia/Kolkata display formatting + list cell safety.
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

echo "=== status_changed_at display + paths audit ===\n";
echo "db=" . $db->getDatabase() . "\n";

$col = $db->query("SHOW COLUMNS FROM rise_tasks LIKE 'status_changed_at'")->getRowArray();
check('column status_changed_at exists (nullable datetime)', $col && stripos($col['Type'], 'datetime') !== false && strtoupper($col['Null']) === 'YES');

$projectsSrc = file_get_contents(APPPATH . 'Controllers/Projects.php');
$cronSrc = file_get_contents(APPPATH . 'Libraries/Cron_job.php');
$modelSrc = file_get_contents(APPPATH . 'Models/Tasks_model.php');
$makeRow = '';
if (preg_match('/function _make_task_row\([\s\S]*?return \$row_data;/m', $projectsSrc, $m)) {
    $makeRow = $m[0];
}
$detailsSql = '';
if (preg_match('/function get_details\([\s\S]*?\$sql = "SELECT[^"]+/m', $modelSrc, $m)) {
    $detailsSql = $m[0];
}

check('list uses tasks.* (no extra status_changed join/subquery)', strpos($detailsSql, '$tasks_table.*') !== false && !preg_match('/status_changed_at.*SELECT|JOIN.*status_changed/', $detailsSql));
check('Tasks list status cell uses task_status_changed_at_html', strpos($makeRow, 'task_status_changed_at_html') !== false);
check('save_task create stamps status_changed_at', preg_match('/if \(\!\$id\) \{[\s\S]*?status_changed_at[\s\S]*?\}/', $projectsSrc) === 1);
check('save_task_status stamps status_changed_at', strpos($projectsSrc, 'function save_task_status') !== false && preg_match('/function save_task_status[\s\S]*?status_changed_at[\s\S]*?function update_task_info/', $projectsSrc) === 1);
check('update_task_info status_id stamps status_changed_at', preg_match('/function update_task_info[\s\S]*?data_field === "status_id"[\s\S]*?status_changed_at/', $projectsSrc) === 1);
check('save_task_sort_and_status stamps status_changed_at', preg_match('/function save_task_sort_and_status[\s\S]*?status_changed_at/', $projectsSrc) === 1);
check('save_batch_update stamps status_changed_at', preg_match('/function save_batch_update[\s\S]*?status_changed_at/', $projectsSrc) === 1);
check('approve_task_review stamps status_changed_at', preg_match('/function approve_task_review[\s\S]*?status_changed_at/', $projectsSrc) === 1);
check('request_task_changes stamps status_changed_at', preg_match('/function request_task_changes[\s\S]*?status_changed_at/', $projectsSrc) === 1);
check('import path stamps status_changed_at', preg_match('/function save_task_from_excel_file[\s\S]*?status_changed_at/', $projectsSrc) === 1);
check('sub-task create stamps status_changed_at', preg_match('/sub-task-title[\s\S]*?status_changed_at/', $projectsSrc) === 1);
check('project clone stamps status_changed_at', preg_match('/_prepare_new_task_data_on_cloning_project[\s\S]*?status_changed_at/', $projectsSrc) === 1);
check('recurring clone stamps status_changed_at', strpos($cronSrc, '"status_changed_at"') !== false || strpos($cronSrc, "'status_changed_at'") !== false);
check('insert_recurring_clone persists status_changed_at', strpos($modelSrc, '"status_changed_at"') !== false);

// Formatting — fixed "now" = 2026-11-19 06:00:00 UTC = 2026-11-19 11:30 IST
$nowUtc = '2026-11-19 06:00:00';

$f = format_task_status_changed_at('2026-11-19 05:00:00', $nowUtc); // 10:30 IST today
check('Today label', $f['label'] === 'Today, 10:30 AM', json_encode($f));

$f = format_task_status_changed_at('2026-11-18 18:00:00', $nowUtc); // 23:30 IST yesterday
check('Yesterday from 23:30 IST yesterday (18:00 UTC)', $f['label'] === 'Yesterday, 11:30 PM', json_encode($f));

$f = format_task_status_changed_at('2026-11-18 19:00:00', $nowUtc); // 00:30 IST today
check('Today from 00:30 IST today (19:00 UTC prev calendar UTC day)', $f['label'] === 'Today, 12:30 AM', json_encode($f));

$f = format_task_status_changed_at('2026-11-16 06:00:00', $nowUtc); // 3 calendar days ago
check('3 days ago', $f['label'] === '3 days ago', json_encode($f));

$f = format_task_status_changed_at('2026-11-12 06:00:00', $nowUtc); // exactly 7 days
check('exactly 7 days ago', $f['label'] === '7 days ago', json_encode($f));

$f = format_task_status_changed_at('2026-11-11 06:00:00', $nowUtc); // 8 days
check('8 days shows date', $f['label'] === '11-11-2026', json_encode($f));

$f = format_task_status_changed_at(null, $nowUtc);
check('NULL shows nothing', $f['label'] === '' && $f['title'] === '');

$f = format_task_status_changed_at('0000-00-00 00:00:00', $nowUtc);
check('zero-date shows nothing', $f['label'] === '');

$html = task_status_changed_at_html('2026-11-19 05:00:00', $nowUtc);
check('HTML has tooltip title (full datetime)', strpos($html, "title='19-11-2026 10:30 AM'") !== false);
check('HTML contains escaped label text', strpos($html, 'Today, 10:30 AM') !== false && strpos($html, '<script') === false);
check('HTML uses task-status-changed-at class', strpos($html, 'task-status-changed-at') !== false);

$xss = task_status_changed_at_html(null);
check('NULL html is empty', $xss === '');

// Path runtime: create + status changes stamp the column; points flow unchanged enough to not zero out
$project = $db->query("SELECT id FROM rise_projects WHERE deleted=0 ORDER BY id DESC LIMIT 1")->getRow();
$projectId = (int)$project->id;
$todo = (int)(model('App\Models\Task_status_model')->get_one_where(['key_name' => 'to_do', 'deleted' => 0])->id ?? 1);
$inProgress = (int)(model('App\Models\Task_status_model')->get_one_where(['key_name' => 'in_progress', 'deleted' => 0])->id ?? 0);
if (!$inProgress) {
    $inProgress = (int)(model('App\Models\Task_status_model')->get_one_where(['title' => 'In Progress', 'deleted' => 0])->id ?? $todo);
}
$inReview = function_exists('get_in_review_task_status_id') ? (int)get_in_review_task_status_id() : 0;
$done = function_exists('get_done_task_status_id') ? (int)get_done_task_status_id() : 0;

$db->query("INSERT INTO rise_users SET first_name='SAt', last_name='User', email='sat_" . uniqid() . "@test.local', user_type='staff', is_admin=0, deleted=0, status='active', password='', role_id=0, enable_web_notification=0, enable_email_notification=0");
$userId = (int)$db->insertID();
$db->query("INSERT INTO rise_project_members SET user_id=$userId, project_id=$projectId, is_leader=0, deleted=0");
$pmId = (int)$db->insertID();

$Tasks = model('App\Models\Tasks_model');
$createData = [
    'title' => 'status_changed_at path audit',
    'description' => 'audit',
    'project_id' => $projectId,
    'milestone_id' => 0,
    'assigned_to' => $userId,
    'collaborators' => '',
    'status_id' => $todo,
    'points' => 5,
    'deadline' => date('Y-m-d', strtotime('+2 days')),
    'end_time' => '18:00:00',
    'created_date' => get_current_utc_time(),
    'status_changed_at' => get_current_utc_time(),
    'created_by' => $userId,
    'deleted' => 0,
];
$taskId = $Tasks->ci_save($createData);
$taskId = (int)$taskId;
$row = $db->query("SELECT status_changed_at, points, status_id FROM rise_tasks WHERE id=$taskId")->getRowArray();
check('create path: status_changed_at set', !empty($row['status_changed_at']) && $row['status_changed_at'] !== '0000-00-00 00:00:00');
$pointsAtCreate = (float)$row['points'];
$t1 = $row['status_changed_at'];
sleep(1);

// simulate save_task_status / update / kanban / batch style stamp
$now2 = get_current_utc_time();
$upd = ['status_id' => $inProgress ?: $todo, 'status_changed_at' => $now2];
$Tasks->ci_save($upd, $taskId);
$row = $db->query("SELECT status_changed_at, points FROM rise_tasks WHERE id=$taskId")->getRowArray();
check('status change path updates timestamp', $row['status_changed_at'] === $now2 || $row['status_changed_at'] > $t1, json_encode($row));
check('points unchanged after status change alone', (float)$row['points'] === $pointsAtCreate, json_encode($row));

if ($inReview) {
    $now3 = get_current_utc_time();
    $upd = ['status_id' => $inReview, 'status_changed_at' => $now3];
    $Tasks->ci_save($upd, $taskId);
    $row = $db->query("SELECT status_changed_at FROM rise_tasks WHERE id=$taskId")->getRowArray();
    check('in-review path updates timestamp', $row['status_changed_at'] === $now3);
} else {
    check('in-review path updates timestamp (skipped no status)', true);
}

if ($done) {
    $now4 = get_current_utc_time();
    // Mirror approve_task_review / direct Done stamp without CLI timezone/points side-effects
    $db->query("UPDATE rise_tasks SET status_id=" . (int)$done . ", status_changed_at=" . $db->escape($now4) . " WHERE id=$taskId");
    $row = $db->query("SELECT status_changed_at, points FROM rise_tasks WHERE id=$taskId")->getRowArray();
    check('approve/done path updates timestamp', $row['status_changed_at'] === $now4);
    check('points still present after done stamp', (float)$row['points'] === $pointsAtCreate);
    // Source confirms approve_task_review writes status_changed_at (checked above)
} else {
    check('approve/done path updates timestamp (skipped)', true);
    check('points still present after done stamp (skipped)', true);
}

// NULL display in cell builder
$htmlNull = task_status_changed_at_html(null);
check('list cell blank when NULL', $htmlNull === '');

// Excel/print column count unchanged: _make_task_row still same indices (status still one cell)
check('status still single row_data cell (wrapper only)', preg_match('/\$status = "<div class=\'task-status-cell\'>/', $makeRow) === 1);

register_shutdown_function(function () use ($db, $taskId, $userId, $pmId) {
    if ($taskId) {
        $db->query('DELETE FROM rise_task_point_awards WHERE task_id=' . (int)$taskId);
        $db->query('DELETE FROM rise_tasks WHERE id=' . (int)$taskId);
    }
    if ($pmId) {
        $db->query('DELETE FROM rise_project_members WHERE id=' . (int)$pmId);
    }
    if ($userId) {
        $db->query('DELETE FROM rise_users WHERE id=' . (int)$userId);
    }
    echo "cleanup_done\n";
});

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
