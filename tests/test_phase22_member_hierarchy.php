<?php
/**
 * Phase 22: Member→Project hierarchy — perf, no-drift vs old Project→Member, Excel identity.
 */
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '180');
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
date_default_timezone_set(get_setting('timezone') ?: 'Asia/Kolkata');

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}

$projects = new App\Controllers\Projects();
$ref = new ReflectionClass($projects);
$parent = $ref;
while ($parent) {
    if ($parent->hasProperty('login_user')) {
        $p = $parent->getProperty('login_user');
        $p->setAccessible(true);
        $p->setValue($projects, model('App\Models\Users_model')->get_access_info(66));
        break;
    }
    $parent = $parent->getParentClass();
}

$db = \Config\Database::connect('default', false);
$qlog = [];
\CodeIgniter\Events\Events::on('DBQuery', static function ($q) use (&$qlog) {
    $qlog[] = is_object($q) && method_exists($q, 'getQuery') ? $q->getQuery() : (string)$q;
});

$from = '2026-10-01';
$to = '2026-11-17';

echo "=== PHASE 22 MEMBER HIERARCHY ===\n";

// Static wiring
$view = file_get_contents(FCPATH . 'app/Views/projects/timesheets/all_timesheets.php');
$js = file_get_contents(FCPATH . 'assets/js/timesheet-grouped.js');
check('view wires membersRootUrl', strpos($view, 'timesheet_grouped_root_members') !== false);
check('view wires memberProjectsUrl', strpos($view, 'timesheet_grouped_member_projects') !== false);
check('JS Member→Project hierarchy', strpos($js, 'tg-member-root') !== false && strpos($js, 'tg-project-nested') !== false);
check('JS expandAll never loads all tasks for everyone', strpos($js, 'never auto-load all tasks') !== false || strpos($js, 'Expand only currently visible level') !== false);

// --- PERF Level 1 ---
$qlog = [];
$t0 = microtime(true);
$_POST = array('start_date' => $from, 'end_date' => $to);
ob_start();
$projects->timesheet_grouped_root_members();
$l1json = ob_get_clean();
$l1ms = (microtime(true) - $t0) * 1000;
$l1q = count($qlog);
$l1 = json_decode($l1json, true);
check('L1 success', !empty($l1['success']) && !empty($l1['data']));
echo "PERF L1 q=$l1q ms=" . round($l1ms, 1) . " members=" . count($l1['data'] ?? []) . "\n";
check('L1 query count reasonable (<=6)', $l1q <= 6, "q=$l1q");

// Busiest member by task_count
$busy = null;
foreach (($l1['data'] ?? []) as $m) {
    if ((int)$m['user_id'] <= 0) continue;
    if (!$busy || (int)$m['task_count'] > (int)$busy['task_count']) $busy = $m;
}
check('found busy member', $busy !== null, json_encode($busy));

// --- PERF open member (L2) ---
$qlog = [];
$t0 = microtime(true);
$_POST = array('start_date' => $from, 'end_date' => $to, 'user_id' => (string)$busy['user_id']);
ob_start();
$projects->timesheet_grouped_member_projects();
$l2json = ob_get_clean();
$l2ms = (microtime(true) - $t0) * 1000;
$l2q = count($qlog);
$l2 = json_decode($l2json, true);
check('L2 success', !empty($l2['success']) && !empty($l2['data']));
echo "PERF L2 open member {$busy['user_id']} q=$l2q ms=" . round($l2ms, 1) . " projects=" . count($l2['data'] ?? []) . "\n";
check('L2 chips match expand', (int)$l2['project_count'] === count($l2['data']) && (int)$l2['task_count'] === (int)$busy['task_count'],
    "chip_proj={$l2['project_count']} data=" . count($l2['data']) . " chip_tasks={$l2['task_count']} L1_tasks={$busy['task_count']}");

$biggest = null;
foreach (($l2['data'] ?? []) as $p) {
    if (!$biggest || (int)$p['task_count'] > (int)$biggest['task_count']) $biggest = $p;
}

// --- PERF open project tasks (L3) ---
$qlog = [];
$t0 = microtime(true);
$_POST = array('start_date' => $from, 'end_date' => $to, 'user_id' => (string)$busy['user_id'], 'project_id' => (string)$biggest['project_id']);
ob_start();
$projects->timesheet_grouped_member_tasks();
$l3json = ob_get_clean();
$l3ms = (microtime(true) - $t0) * 1000;
$l3q = count($qlog);
$l3 = json_decode($l3json, true);
check('L3 success', !empty($l3['success']));
echo "PERF L3 open project {$biggest['project_id']} q=$l3q ms=" . round($l3ms, 1) . " tasks=" . count($l3['data'] ?? []) . "\n";
check('L3 task count matches project chip', count($l3['data'] ?? []) === (int)$biggest['task_count'],
    "l3=" . count($l3['data'] ?? []) . " chip={$biggest['task_count']}");

// --- PERF entries ---
$taskWithEntries = null;
foreach (($l3['data'] ?? []) as $t) {
    if ((int)$t['entry_count'] > 0) { $taskWithEntries = $t; break; }
}
if ($taskWithEntries) {
    $qlog = [];
    $t0 = microtime(true);
    $_POST = array('project_id' => (string)$biggest['project_id'], 'user_id' => (string)$busy['user_id'], 'task_id' => (string)$taskWithEntries['task_id']);
    ob_start();
    $projects->timesheet_grouped_time_entries();
    $l4json = ob_get_clean();
    $l4ms = (microtime(true) - $t0) * 1000;
    $l4q = count($qlog);
    $l4 = json_decode($l4json, true);
    check('L4 entries success', !empty($l4['success']));
    echo "PERF L4 entries q=$l4q ms=" . round($l4ms, 1) . " rows=" . count($l4['data'] ?? []) . "\n";
} else {
    echo "[SKIP] no timed entries on biggest project for busy member\n";
}

// --- NO DATA DRIFT: old Project→Member vs new Member→Project ---
echo "\n=== NO-DRIFT (3 members) ===\n";
printf("%-8s %-8s %-10s %-10s %-10s %-10s %-10s %-10s %s\n", "member", "project", "old_tasks", "new_tasks", "old_sec", "new_sec", "old_pts", "new_pts", "match");
$membersSample = [];
foreach (($l1['data'] ?? []) as $m) {
    if ((int)$m['user_id'] > 0) $membersSample[] = $m;
    if (count($membersSample) >= 3) break;
}

$driftOk = true;
foreach ($membersSample as $m) {
    $uid = (int)$m['user_id'];
    // NEW: member → projects
    $_POST = array('start_date' => $from, 'end_date' => $to, 'user_id' => (string)$uid);
    ob_start();
    $projects->timesheet_grouped_member_projects();
    $newProjs = json_decode(ob_get_clean(), true);

    // OLD: for each project in range, open members and find this uid
    $_POST = array('start_date' => $from, 'end_date' => $to);
    ob_start();
    $projects->timesheet_grouped_projects();
    $oldProjects = json_decode(ob_get_clean(), true);

    $oldMap = []; // project_id => [tasks, sec, pts]
    foreach (($oldProjects['data'] ?? []) as $op) {
        $pid = (int)$op['project_id'];
        $_POST = array('start_date' => $from, 'end_date' => $to, 'project_id' => (string)$pid);
        ob_start();
        $projects->timesheet_grouped_members();
        $om = json_decode(ob_get_clean(), true);
        foreach (($om['data'] ?? []) as $row) {
            if ((int)$row['user_id'] === $uid) {
                $oldMap[$pid] = array(
                    'tasks' => (int)$row['task_count'],
                    'sec' => (int)$row['total_seconds'],
                    'pts' => (float)$row['points_earned'],
                );
            }
        }
    }

    $newMap = [];
    foreach (($newProjs['data'] ?? []) as $np) {
        $newMap[(int)$np['project_id']] = array(
            'tasks' => (int)$np['task_count'],
            'sec' => (int)$np['total_seconds'],
            'pts' => (float)$np['points_earned'],
        );
    }

    $allPids = array_unique(array_merge(array_keys($oldMap), array_keys($newMap)));
    foreach ($allPids as $pid) {
        $o = $oldMap[$pid] ?? array('tasks' => 0, 'sec' => 0, 'pts' => 0);
        $n = $newMap[$pid] ?? array('tasks' => 0, 'sec' => 0, 'pts' => 0);
        $match = ($o['tasks'] === $n['tasks'] && $o['sec'] === $n['sec'] && abs($o['pts'] - $n['pts']) < 0.001);
        if (!$match) $driftOk = false;
        printf("%-8s %-8s %-10s %-10s %-10s %-10s %-10s %-10s %s\n",
            $uid, $pid, $o['tasks'], $n['tasks'], $o['sec'], $n['sec'],
            number_format($o['pts'], 2), number_format($n['pts'], 2), $match ? 'MATCH' : 'DIFF');
    }

    // Member totals: sum of new projects == L1
    $sumSec = 0; $sumPts = 0; $sumTasks = 0;
    foreach ($newMap as $n) { $sumSec += $n['sec']; $sumPts += $n['pts']; $sumTasks += $n['tasks']; }
    check("member $uid project sums == L1 time", $sumSec === (int)$m['total_seconds'], "sum=$sumSec L1={$m['total_seconds']}");
    check("member $uid project sums == L1 tasks", $sumTasks === (int)$m['task_count'], "sum=$sumTasks L1={$m['task_count']}");
    check("member $uid project sums ~= L1 pts", abs($sumPts - (float)$m['points_earned']) < 0.01, "sum=$sumPts L1={$m['points_earned']}");
}
check('no drift old vs new for sampled pairs', $driftOk);

// Points reconciliation one member one month (Nov 2026)
$uid = (int)$membersSample[0]['user_id'];
$awardsSum = $db->query("SELECT COALESCE(SUM(a.earned_points),0) AS s FROM rise_task_point_awards a
  INNER JOIN rise_tasks t ON t.id=a.task_id AND t.deleted=0
  WHERE a.deleted=0 AND a.user_id=$uid AND a.year=2026 AND a.month=11")->getRow();
$_POST = array('start_date' => '2026-11-01', 'end_date' => '2026-11-17', 'user_id' => (string)$uid);
ob_start();
$projects->timesheet_grouped_root_members();
$nov = json_decode(ob_get_clean(), true);
$novPts = 0;
foreach (($nov['data'] ?? []) as $m) {
    if ((int)$m['user_id'] === $uid) $novPts = (float)$m['points_earned'];
}
// Nov awards may include tasks created outside Nov if awards keyed by completion month — compare created-date filter awards
$createdAwards = $db->query("SELECT COALESCE(SUM(a.earned_points),0) AS s FROM rise_task_point_awards a
  INNER JOIN rise_tasks t ON t.id=a.task_id AND t.deleted=0
  INNER JOIN rise_projects p ON p.id=t.project_id AND p.deleted=0
  WHERE a.deleted=0 AND a.user_id=$uid AND t.created_date BETWEEN '2026-11-01' AND '2026-11-17'")->getRow();
check('points recon member Nov created-date', abs($novPts - (float)$createdAwards->s) < 0.01, "L1=$novPts awards={$createdAwards->s}");

// Excel identity: same builder as before (flat rows)
$build = $ref->getMethod('_timesheet_grouped_build_flat_rows');
$build->setAccessible(true);
$built = $build->invoke($projects, array('created_from' => $from, 'created_to' => $to, 'member_id' => $uid));
check('Excel flat rows still work', count($built['rows']) > 0);
$_POST = array('start_date' => $from, 'end_date' => $to, 'user_id' => (string)$uid, 'format' => 'print');
ob_start();
$projects->timesheet_grouped_export();
$print = ob_get_clean();
check('Print export unchanged path works', strpos($print, '<table') !== false && strpos($print, 'Total') !== false);

// Alphabetical + Unassigned last
$names = [];
$unassignedPos = -1;
foreach (($l1['data'] ?? []) as $i => $m) {
    if ((int)$m['user_id'] === 0) { $unassignedPos = $i; }
    else $names[] = $m['name'];
}
$sorted = $names;
sort($sorted, SORT_STRING | SORT_FLAG_CASE);
check('members alphabetical', $names === $sorted, json_encode(array_slice($names, 0, 5)));
if ($unassignedPos >= 0) {
    check('Unassigned last', $unassignedPos === count($l1['data']) - 1, "pos=$unassignedPos count=" . count($l1['data']));
} else {
    echo "[SKIP] no Unassigned bucket in range\n";
}

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
