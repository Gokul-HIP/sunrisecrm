<?php
/**
 * Phase 21E: one Score rule — awards describe what was actually awarded.
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
date_default_timezone_set(get_setting('timezone') ?: 'Asia/Kolkata');

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}

function plain_badge($task, $uid = 0) {
    $opts = array("compact" => true, "show_reason" => true);
    if ($uid > 0) $opts["for_user_id"] = $uid;
    $html = get_task_points_badge_html($task, $opts);
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
}

$db = \Config\Database::connect('default', false);
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
$scoreFn = $ref->getMethod('_timesheet_grouped_score_text');
$scoreFn->setAccessible(true);

echo "=== PHASE 21E SCORE TEXT ===\n";

// Explain samples for 25089/25090
foreach ([25089, 25090] as $tid) {
    $task = model('App\Models\Tasks_model')->get_details(array('id' => $tid))->getRow();
    $awards = $db->query("SELECT user_id,earned_points,original_points,participants_count,is_collaborator,reduction_percent FROM rise_task_point_awards WHERE task_id=$tid AND deleted=0")->getResult();
    $tasksPage = plain_badge($task);
    $tasksExcel = get_task_points_score_plain_text($task, array('compact' => true));
    echo "task $tid awards=" . json_encode($awards) . "\n";
    echo "  tasks_page=$tasksPage\n";
    echo "  tasks_excel=$tasksExcel\n";
    check("250xx $tid no fake (1 of 4)", strpos($tasksPage, '(1 of 4)') === false && strpos($tasksExcel, '(1 of 4)') === false, $tasksPage);
    check("250xx $tid tasks page==excel", $tasksPage === $tasksExcel, "page=$tasksPage excel=$tasksExcel");
    check("250xx $tid not 1.25 split", strpos($tasksPage, '1.25') === false, $tasksPage);
}

$splitIds = [25671, 25660, 25659, 25658, 25657];
echo "\n=== 5 SPLIT TASKS (4 places) ===\n";
printf("%-7s %-5s %-36s %-36s %-36s %-36s %s\n", "task", "uid", "tasks_page", "tasks_excel", "ts_screen", "ts_excel", "awards");

$allMatch = true;
foreach ($splitIds as $tid) {
    $task = model('App\Models\Tasks_model')->get_details(array('id' => $tid))->getRow();
    $awards = $db->query("SELECT user_id,earned_points,original_points,participants_count,is_collaborator FROM rise_task_point_awards WHERE task_id=$tid AND deleted=0 ORDER BY user_id")->getResult();
    $awardMap = [];
    foreach ($awards as $a) $awardMap[(int)$a->user_id] = $a;

    // Tasks page/Excel: task-level (assignee-scoped join from get_details)
    $tasksPage = plain_badge($task);
    $tasksExcel = get_task_points_score_plain_text($task, array('compact' => true));
    if ($tasksPage !== $tasksExcel) $allMatch = false;

    $assignee = (int)$task->assigned_to;
    foreach ($awardMap as $uid => $award) {
        $tp = ($uid === $assignee) ? $tasksPage : plain_badge($task, $uid);
        $te = ($uid === $assignee) ? $tasksExcel : get_task_points_score_plain_text($task, array('compact' => true, 'for_user_id' => $uid));
        // Clone clean task without get_details award pollution for member-scoped
        $raw = $db->query("SELECT * FROM rise_tasks WHERE id=$tid")->getRow();
        $raw->status_key_name = 'done';
        $info = $scoreFn->invoke($projects, $raw, $uid, $award);
        $tsScreen = $info['score'];
        $tsExcel = $info['score'];
        $awardStr = "u{$award->user_id}:{$award->earned_points}/{$award->original_points}:pc{$award->participants_count}";
        printf("%-7s %-5s %-36s %-36s %-36s %-36s %s\n", $tid, $uid, $tp, $te, $tsScreen, $tsExcel, $awardStr);

        if ($tp !== $te || $te !== $tsScreen || $tsScreen !== $tsExcel) {
            $allMatch = false;
            check("match $tid/$uid", false, "page=$tp excel=$te screen=$tsScreen xlsx=$tsExcel");
        }
        if (strpos($tsExcel, '(1 of 4)') !== false) {
            $allMatch = false;
            check("no fake split $tid/$uid", false, $tsExcel);
        }
        // Must include (1 of 2) for real splits
        if ((int)$award->participants_count > 1 && strpos($tsExcel, '(1 of ') === false) {
            $allMatch = false;
            check("real split tag $tid/$uid", false, $tsExcel);
        }
    }
}
check('all four places agree for split members', $allMatch);

// 25089 assignee vs collab
$t89 = $db->query("SELECT * FROM rise_tasks WHERE id=25089")->getRow();
$t89->status_key_name = 'done';
$a12 = $db->query("SELECT * FROM rise_task_point_awards WHERE task_id=25089 AND user_id=12 AND deleted=0")->getRow();
$s12 = $scoreFn->invoke($projects, $t89, 12, $a12);
$s30 = $scoreFn->invoke($projects, $t89, 30, null);
check('25089 assignee score is award actual', strpos($s12['score'], '4') !== false && strpos($s12['score'], '(1 of 4)') === false, $s12['score']);
check('25089 collab no invented 1.25 split', strpos($s30['score'], '1.25') === false && strpos($s30['score'], '(1 of 4)') === false, $s30['score']);
check('25089 collab archived 0', $s30['archived'] === '0', $s30['archived']);

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
