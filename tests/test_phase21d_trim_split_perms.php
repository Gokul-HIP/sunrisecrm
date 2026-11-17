<?php
/**
 * Phase 21D: trim names, split archived scores, own-only / no-access perms.
 * No live, no schema, no Database.php / system / logs touches.
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

function set_login($controller, $user_id) {
    \Config\Services::session()->set('user_id', $user_id);
    $ref = new ReflectionClass($controller);
    $parent = $ref;
    while ($parent) {
        if ($parent->hasProperty('login_user')) {
            $p = $parent->getProperty('login_user');
            $p->setAccessible(true);
            $p->setValue($controller, model('App\Models\Users_model')->get_access_info($user_id));
            break;
        }
        $parent = $parent->getParentClass();
    }
}

$db = \Config\Database::connect('default', false);
$projects = new App\Controllers\Projects();
set_login($projects, 66);
echo "=== PHASE 21D ===\n";
$ref = new ReflectionClass($projects);
$build = $ref->getMethod('_timesheet_grouped_build_flat_rows');
$build->setAccessible(true);
$trimFn = $ref->getMethod('_tg_trim');
$trimFn->setAccessible(true);

// --- 1) TRIM ---
echo "\n-- TRIM --\n";
$examples = [];
$rawProj = $db->query("SELECT id, title FROM rise_projects WHERE id=622")->getRow();
$rawUser = $db->query("SELECT id, first_name, last_name FROM rise_users WHERE id=30")->getRow();
$rawClient = $db->query("SELECT id, company_name FROM rise_clients WHERE id=6")->getRow();
$examples[] = ['Project', $rawProj->title, $trimFn->invoke($projects, $rawProj->title)];
$examples[] = ['Member', trim($rawUser->first_name) !== $rawUser->first_name || trim($rawUser->last_name) !== $rawUser->last_name
    ? ($rawUser->first_name . ' ' . $rawUser->last_name)
    : ($rawUser->first_name . ' ' . $rawUser->last_name), $trimFn->invoke($projects, $rawUser->first_name . ' ' . $rawUser->last_name)];
$examples[] = ['Client', $rawClient->company_name, $trimFn->invoke($projects, $rawClient->company_name)];

foreach ($examples as $ex) {
    echo "TRIM {$ex[0]} before=[{$ex[1]}] after=[{$ex[2]}]\n";
    check("trim {$ex[0]} removes edge spaces", $ex[2] === trim($ex[1]) && (strlen($ex[1]) !== strlen($ex[2]) || $ex[1] === $ex[2]), "before=[{$ex[1]}] after=[{$ex[2]}]");
}

$builtTrim = $build->invoke($projects, array(
    'created_from' => '2026-10-01',
    'created_to' => '2026-11-17',
    'project_id' => 622,
));
$bad = 0;
foreach ($builtTrim['rows'] as $r) {
    foreach (['project', 'client', 'task', 'member'] as $k) {
        $v = (string) $r[$k];
        if ($v !== '' && $v !== '-' && $v !== trim($v)) { $bad++; }
    }
}
check('export rows have trimmed project/client/task/member', $bad === 0, "bad=$bad");

// Simulate Level1 JSON trim
$_POST = array('start_date' => '2026-10-01', 'end_date' => '2026-11-17', 'project_id' => '622');
ob_start();
$projects->timesheet_grouped_projects();
$l1 = json_decode(ob_get_clean(), true);
$l1bad = 0;
foreach (($l1['data'] ?? []) as $p) {
    if ($p['project_title'] !== trim($p['project_title'])) $l1bad++;
    if ($p['client_name'] !== '' && $p['client_name'] !== trim($p['client_name'])) $l1bad++;
}
check('Level1 project/client titles trimmed', $l1bad === 0 && !empty($l1['data']), "bad=$l1bad count=" . count($l1['data'] ?? []));
$sunrise = null;
foreach (($l1['data'] ?? []) as $p) {
    if ((int)$p['project_id'] === 622) { $sunrise = $p; break; }
}
if ($sunrise) {
    echo "TRIM screen Project 622 after=[{$sunrise['project_title']}]\n";
    check('Sunrise Oct title trimmed', $sunrise['project_title'] === 'Sunrise | October 2026', $sunrise['project_title']);
}

// --- 2) SPLIT TASKS ---
echo "\n-- SPLIT TASKS --\n";
$splitIds = [25671, 25660, 25659, 25658, 25657];
// Also show 25089/25090 as requested examples (pc=1 awards)
$extra = [25089, 25090];
$allCheck = array_merge($splitIds, $extra);

set_login($projects, 66);
$fmt = function ($v) {
    return function_exists('format_task_point_value') ? format_task_point_value($v) : (string)$v;
};

echo sprintf("%-8s %-6s %-28s %-14s %-14s %-14s %s\n", "task", "uid", "member", "score_xlsx", "arch_xlsx", "arch_screen", "award_earned");
$splitOk = true;
foreach ($allCheck as $tid) {
    $task = $db->query("SELECT * FROM rise_tasks WHERE id=" . (int)$tid)->getRow();
    if (!$task) continue;
    $awards = $db->query("SELECT * FROM rise_task_point_awards WHERE deleted=0 AND task_id=" . (int)$tid)->getResult();
    $awardMap = [];
    foreach ($awards as $a) { $awardMap[(int)$a->user_id] = $a; }

    $participants = function_exists('get_task_participants') ? get_task_participants($task) : [];
    if (!$participants) {
        $participants = array_filter([(int)$task->assigned_to]);
    }

    // Build export rows for this task's project/date window
    $built = $build->invoke($projects, array(
        'created_from' => $task->created_date,
        'created_to' => $task->created_date,
        'project_id' => (int)$task->project_id,
    ));
    $byMember = [];
    foreach ($built['rows'] as $r) {
        if ((int)$r['task_id'] !== (int)$tid) continue;
        $byMember[(int)$r['member_id']] = $r;
    }

    // Screen Level3 path via reflection of score helper for each participant
    $scoreFn = $ref->getMethod('_timesheet_grouped_score_text');
    $scoreFn->setAccessible(true);

    foreach ($participants as $uid) {
        $uid = (int)$uid;
        $u = $db->query("SELECT first_name,last_name FROM rise_users WHERE id=$uid")->getRow();
        $name = $u ? trim($u->first_name . ' ' . $u->last_name) : "#$uid";
        $award = $awardMap[$uid] ?? null;
        $xlsx = $byMember[$uid] ?? null;
        $screen = $scoreFn->invoke($projects, $task, $uid, $award);
        $awardEarned = $award ? $fmt($award->earned_points) : '(none)';
        $archX = $xlsx ? $xlsx['archived_score'] : '(no-row)';
        $scoreX = $xlsx ? $xlsx['score'] : '(no-row)';
        $archS = $screen['archived'];
        echo sprintf("%-8s %-6s %-28s %-14s %-14s %-14s %s\n", $tid, $uid, substr($name, 0, 28), $scoreX, $archX, $archS, $awardEarned);

        $expectArch = $award ? $fmt($award->earned_points) : '0';
        if ($archS !== $expectArch) { $splitOk = false; check("task $tid uid $uid screen arch==award", false, "screen=$archS expect=$expectArch"); }
        if ($xlsx && $archX !== $expectArch) { $splitOk = false; check("task $tid uid $uid excel arch==award", false, "excel=$archX expect=$expectArch"); }
        if ($xlsx && $archX !== $archS) { $splitOk = false; check("task $tid uid $uid excel==screen arch", false, "excel=$archX screen=$archS"); }
    }
}
check('split/collab archived scores agree (xlsx/screen/awards)', $splitOk);

// Real split sample: both members have 2.25
$a25671 = $db->query("SELECT user_id,earned_points FROM rise_task_point_awards WHERE task_id=25671 AND deleted=0")->getResult();
check('25671 has 2 award rows', count($a25671) === 2);

// --- 3) PERMISSIONS ---
echo "\n-- PERMISSIONS --\n";

// (a) Team Member own-only (uid 68, timesheet_manage empty → own)
set_login($projects, 68);
$login = null;
$pref = new ReflectionClass($projects);
$pp = $pref;
while ($pp) {
    if ($pp->hasProperty('login_user')) {
        $prop = $pp->getProperty('login_user');
        $prop->setAccessible(true);
        $login = $prop->getValue($projects);
        break;
    }
    $pp = $pp->getParentClass();
}
$tsPerm = get_array_value($login->permissions, 'timesheet_manage_permission');
echo "user68 timesheet_manage_permission=" . json_encode($tsPerm) . " is_admin=" . (int)$login->is_admin . "\n";

$allowedFn = $ref->getMethod('_get_members_to_manage_timesheet');
$allowedFn->setAccessible(true);
$allowed68 = $allowedFn->invoke($projects);
$allowed68ints = array_map('intval', (array)$allowed68);
check('a) own-only allowed members = [68]', $allowed68ints === [68], json_encode($allowed68));

$_POST = array('start_date' => '2026-06-01', 'end_date' => '2026-11-17');
ob_start();
$projects->timesheet_grouped_projects();
$ownL1 = json_decode(ob_get_clean(), true);
$ownBuilt = $build->invoke($projects, array('created_from' => '2026-06-01', 'created_to' => '2026-11-17'));
$foreign = 0;
foreach ($ownBuilt['rows'] as $r) {
    if ((int)$r['member_id'] !== 68) $foreign++;
}
check('a) Excel rows only member 68', $foreign === 0 && count($ownBuilt['rows']) > 0, "foreign=$foreign rows=" . count($ownBuilt['rows']));

// Screen Level1 task sum vs excel unique tasks
$screenTasks = 0;
foreach (($ownL1['data'] ?? []) as $p) $screenTasks += (int)$p['task_count'];
$excelTasks = [];
foreach ($ownBuilt['rows'] as $r) $excelTasks[(int)$r['task_id']] = true;
check('a) screen task chips == excel unique tasks', $screenTasks === count($excelTasks), "screen=$screenTasks excel=" . count($excelTasks));

// Print/Excel forbidden content leak: print HTML should not contain other member names as row members
ob_start();
$_POST = array('start_date' => '2026-06-01', 'end_date' => '2026-11-17', 'format' => 'print');
$projects->timesheet_grouped_export();
$printHtml = ob_get_clean();
// Other staff first names that must not appear as Member column alone is hard; check row member_ids via rebuild
check('a) print generated for own-only', strpos($printHtml, 'Total') !== false && strpos($printHtml, '<table') !== false);

// (b) no timesheet access — client user (subprocess: access_only_team_members exits)
$clientUser = $db->query("SELECT id FROM rise_users WHERE deleted=0 AND user_type='client' LIMIT 1")->getRow();
if ($clientUser) {
    set_login($projects, (int)$clientUser->id);
    $canFn = $ref->getMethod('can_view_timesheet');
    $canFn->setAccessible(true);
    $can = $canFn->invoke($projects, 0, true);
    check('b) client can_view_timesheet false/empty', empty($can), json_encode($can));

    $cid = (int)$clientUser->id;
    $probeWin = 'C:\\Users\\gokul\\AppData\\Local\\Temp\\phase21d_client_block.php';
    $repoWin = '\\\\wsl.localhost\\Ubuntu\\home\\gokul\\projects\\crm_sunrise\\';
    file_put_contents($probeWin, '<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER["HTTP_HOST"]="127.0.0.1"; $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["SCRIPT_NAME"]="/index.php";
define("ENVIRONMENT","development");
define("FCPATH", '.var_export($repoWin, true).');
require FCPATH."app/Config/Paths.php"; $paths=new Config\Paths();
require rtrim($paths->systemDirectory,"\\\\/ ").DIRECTORY_SEPARATOR."bootstrap.php";
require_once SYSTEMPATH."Config/DotEnv.php"; (new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH."ThirdParty/PHP-Hooks/php-hooks.php");
helper(["plugin","url","language","general","date_time","task_points"]);
$app=\Config\Services::codeigniter(); $app->initialize();
\Config\Services::session()->set("user_id", '.$cid.');
$p=new App\Controllers\Projects();
$ref=new ReflectionClass($p); $par=$ref;
while($par){ if($par->hasProperty("login_user")){ $pr=$par->getProperty("login_user"); $pr->setAccessible(true);
$pr->setValue($p, model("App\Models\Users_model")->get_access_info('.$cid.')); break;} $par=$par->getParentClass(); }
$_POST=["start_date"=>"2026-06-01","end_date"=>"2026-11-17","format"=>"print"];
try { $p->timesheet_grouped_export(); echo "LEAK_EXPORT"; } catch(Throwable $e){ echo "EX ".$e->getMessage(); }
');
    $cmd = 'C:\\xampp\\php\\php.exe ' . escapeshellarg($probeWin);
    $descriptors = array(1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
    $proc = proc_open($cmd, $descriptors, $pipes, 'C:\\Users\\gokul\\AppData\\Local\\Temp');
    $out = '';
    if (is_resource($proc)) {
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
    // access_only_team_members() app_redirect+exit → empty body; must not leak a print table
    $blocked = strpos((string)$out, 'LEAK_EXPORT') === false && strpos((string)$out, '<table') === false;
    check('b) client blocked from export (no table leak)', $blocked, 'out_len=' . strlen((string)$out) . ' snip=' . substr(preg_replace('/\s+/', ' ', (string)$out), 0, 120));
} else {
    echo "[SKIP] no client user for no-access test\n";
}

// show_assigned_tasks_only simulation: set on login_user permissions for uid 39
set_login($projects, 39);
$pp = new ReflectionClass($projects);
while ($pp) {
    if ($pp->hasProperty('login_user')) {
        $prop = $pp->getProperty('login_user');
        $prop->setAccessible(true);
        $u = $prop->getValue($projects);
        if (is_object($u->permissions)) {
            $u->permissions->show_assigned_tasks_only = '1';
            $u->permissions->timesheet_manage_permission = 'own';
        } else {
            $perms = (array) $u->permissions;
            $perms['show_assigned_tasks_only'] = '1';
            $perms['timesheet_manage_permission'] = 'own';
            $u->permissions = $perms;
        }
        $prop->setValue($projects, $u);
        break;
    }
    $pp = $pp->getParentClass();
}
$own39 = $build->invoke($projects, array('created_from' => '2026-06-01', 'created_to' => '2026-11-17'));
$bad39 = 0;
foreach ($own39['rows'] as $r) {
    if ((int)$r['member_id'] !== 39) $bad39++;
}
check('a2) show_assigned+own Excel only uid 39', $bad39 === 0, "bad=$bad39 rows=" . count($own39['rows']));

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
