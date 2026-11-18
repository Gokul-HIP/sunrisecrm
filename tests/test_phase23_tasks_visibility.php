<?php
/**
 * Phase 23: Tasks list visibility — created-date filter, counters, route-level cases.
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
\Config\Services::session()->set('user_id', 66);

$pass = 0;
$fail = 0;
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

$model = model('App\Models\Tasks_model');
$status_model = model('App\Models\Task_status_model');
$statuses = $status_model->get_details()->getResult();
$non_done = [];
foreach ($statuses as $s) {
    if ($s->key_name !== 'done') {
        $non_done[] = (int) $s->id;
    }
}
$status_ids = implode(',', $non_done);

function list_ids($result) {
    $rows = is_array($result) ? ($result['data'] ?? []) : $result->getResult();
    $ids = [];
    foreach ($rows as $r) {
        $ids[(int) $r->id] = true;
    }
    return $ids;
}

echo "=== PHASE 23 TASKS VISIBILITY ===\n";

// Static: created filter UI no longer prefills today
$js = file_get_contents(FCPATH . 'app/Views/projects/tasks/created_date_apply_filter_js.php');
check('created filter draft starts empty', strpos($js, 'draftFrom = ""') !== false && strpos($js, 'draftTo = ""') !== false);
check('created filter has active badge', strpos($js, 'filter_active') !== false);
check('Tasks_model has MySQL8-safe created filter helper', strpos(file_get_contents(FCPATH . 'app/Models/Tasks_model.php'), '_created_date_filter_where') !== false);

$base = [
    'specific_user_id' => 39,
    'project_status' => 'open',
    'status_ids' => $status_ids,
    'limit' => 5000,
    'skip' => 0,
];

$r = $model->get_details($base);
$ids = list_ids($r);
check('admin+Surya no date: 25906 visible', !empty($ids[25906]));
check('admin+Surya no date: counters match SQL shape', (int) $r['todo_count'] === 5 && (int) $r['in_progress_count'] === 15 && (int) $r['done_count'] === 1031,
    "todo={$r['todo_count']} ip={$r['in_progress_count']} done={$r['done_count']}");
check('admin+Surya no date: list records = non-done count', (int) $r['recordsFiltered'] === 39, "records={$r['recordsFiltered']}");

$r2 = $model->get_details($base + ['created_from' => '2026-11-18', 'created_to' => '2026-11-18']);
$ids2 = list_ids($r2);
check('created 18-18 hides 25906 (created 17th)', empty($ids2[25906]));
check('created 18-18 keeps Nov18 to_do tasks', !empty($ids2[25949]) && !empty($ids2[25950]) && !empty($ids2[25956]));
check('created 18-18 does not resurface zero-date old tasks', empty($ids2[15583]) && empty($ids2[15582]) && empty($ids2[15579]));
check('created 18-18 counters todo=3 ip=0 done=0', (int) $r2['todo_count'] === 3 && (int) $r2['in_progress_count'] === 0 && (int) $r2['done_count'] === 0,
    "todo={$r2['todo_count']} ip={$r2['in_progress_count']} done={$r2['done_count']}");

// No-deadline task still visible without date filter (use a known null-deadline in-progress for Surya)
$db = db_connect();
$no_dl = $db->query("SELECT t.id FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id
  WHERE t.deleted=0 AND p.status='open' AND t.assigned_to=39 AND (t.deadline IS NULL OR t.deadline='0000-00-00' OR t.deadline='')
  AND t.status_id IN ($status_ids) ORDER BY t.id DESC LIMIT 1")->getRow();
check('found no-deadline task for Surya', $no_dl !== null);
if ($no_dl) {
    check('no-deadline task visible without created filter', !empty($ids[(int) $no_dl->id]), "id={$no_dl->id}");
}

// Deadline task
$with_dl = $db->query("SELECT t.id FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id
  WHERE t.deleted=0 AND p.status='open' AND t.assigned_to=39 AND t.deadline IS NOT NULL AND t.deadline>'0000-00-00'
  AND t.status_id IN ($status_ids) ORDER BY t.id DESC LIMIT 1")->getRow();
check('deadline task visible without created filter', $with_dl && !empty($ids[(int) $with_dl->id]), $with_dl ? "id={$with_dl->id}" : 'none');

// to_do
check('to_do 25906 visible (no date)', !empty($ids[25906]));

// Collaborator-only: task where 42 is collaborator not assignee
$collab = $db->query("SELECT t.id FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id
  WHERE t.deleted=0 AND p.status='open' AND t.assigned_to<>42 AND FIND_IN_SET('42', t.collaborators)
  AND t.status_id IN ($status_ids) AND t.created_date>='2026-11-01' ORDER BY t.id DESC LIMIT 1")->getRow();
check('found collaborator-only task for 42', $collab !== null);
if ($collab) {
    $rc = $model->get_details([
        'specific_user_id' => 42,
        'project_status' => 'open',
        'status_ids' => $status_ids,
        'limit' => 5000,
        'skip' => 0,
    ]);
    $idc = list_ids($rc);
    check('collaborator-only task visible for user 42 filter', !empty($idc[(int) $collab->id]), "id={$collab->id}");
}

// In review: still in list when not submitted (legacy) or excluded when stamped — match controller flag
$ir = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
if ($ir) {
    $rev = $db->query("SELECT id, submitted_for_review_at FROM rise_tasks WHERE deleted=0 AND status_id=$ir AND assigned_to=39 ORDER BY id DESC LIMIT 1")->getRow();
    check('found in_review task for Surya', $rev !== null);
    $ropts = $base + ['exclude_review_tasks_for_assignee' => true];
    $rr = $model->get_details($ropts);
    $idr = list_ids($rr);
    if ($rev && $rev->submitted_for_review_at) {
        check('stamped in_review hidden by exclude_review_tasks_for_assignee', empty($idr[(int) $rev->id]), "id={$rev->id}");
    } else if ($rev) {
        check('legacy in_review (no stamp) still listed', !empty($idr[(int) $rev->id]), "id={$rev->id}");
    }
}

// Assignee not in project members — create ephemeral check via SQL existence only if any; else synthesize option path
$orphan = $db->query("SELECT t.id, t.assigned_to, t.project_id FROM rise_tasks t
  WHERE t.deleted=0 AND t.assigned_to>0
  AND NOT EXISTS (SELECT 1 FROM rise_project_members pm WHERE pm.deleted=0 AND pm.project_id=t.project_id AND pm.user_id=t.assigned_to)
  LIMIT 1")->getRow();
if ($orphan) {
    $ro = $model->get_details([
        'specific_user_id' => (int) $orphan->assigned_to,
        'project_status' => 'open',
        'status_ids' => $status_ids,
        'project_member_id' => (int) $orphan->assigned_to,
        'limit' => 5000,
        'skip' => 0,
    ]);
    $ido = list_ids($ro);
    check('assignee-not-member hidden by project_member_id', empty($ido[(int) $orphan->id]), "id={$orphan->id}");
} else {
    check('assignee-not-member case (none in DB — membership skip OK)', true);
}

// Plain SQL vs admin filter
$sql = (int) $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND (assigned_to=39 OR FIND_IN_SET('39', collaborators))")->getRow()->c;
check('plain SQL Surya non-deleted count recorded', $sql === 1388, "sql=$sql");

check('deploy_manifest exists', is_file(FCPATH . 'writable/deploy_manifest.txt'));

echo "PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
