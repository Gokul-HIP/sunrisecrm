<?php
/**
 * Phase 20: timesheet Details task columns — batch query, export parity, edge cases.
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications', 'task_timer_visibility']);

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
function strip_cell($html) {
    $t = html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $t));
}

$db = \Config\Database::connect('default', false);
$mysqli = null;
$raw = file_get_contents(FCPATH . 'app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$mysqli = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

echo "=== PHASE 20 TIMESHEET TASK COLUMNS ===\n";

// Build controller context
$controller = new App\Controllers\Projects();
$ref = new ReflectionClass($controller);
// Security_Controller needs login_user — set via session already; construct may load it
$loginProp = null;
foreach (['login_user'] as $p) {
    if ($ref->hasProperty($p) || $ref->getParentClass()->hasProperty($p)) {
        // access via public after init
    }
}

// Use route-like: call private methods via reflection after forcing login_user
$ciUser = $db->query("SELECT * FROM rise_users WHERE id=66")->getRow();
if (!$ciUser) { echo "NO_ADMIN\n"; exit(1); }

// Re-init Projects properly through request simulation is heavy; call models + copy row builder logic via public timesheet path.

$end = date('Y-m-d');
$start_month = date('Y-m-d', strtotime('-30 days'));
$start_day = $end;

$qlog = [];
\CodeIgniter\Events\Events::on('DBQuery', static function ($q) use (&$qlog) {
    $qlog[] = is_object($q) && method_exists($q, 'getQuery') ? $q->getQuery() : (string)$q;
});

function fetch_timesheet_rows($start, $end) {
    $projects = new App\Controllers\Projects();
    // Inject login user if needed
    $r = new ReflectionClass($projects);
    $parent = $r;
    while ($parent) {
        if ($parent->hasProperty('login_user')) {
            $p = $parent->getProperty('login_user');
            $p->setAccessible(true);
            $u = $p->getValue($projects);
            if (!$u || empty($u->id)) {
                $u = model('App\Models\Users_model')->get_access_info(66);
                if (!$u || empty($u->id)) {
                    $u = model('App\Models\Users_model')->get_one(66);
                }
                $p->setValue($projects, $u);
            }
            break;
        }
        $parent = $parent->getParentClass();
    }

    $custom_fields = model('App\Models\Custom_fields_model')->get_available_fields_for_table("timesheets", true, "staff");
    $options = array(
        "status" => "none_open",
        "start_date" => $start,
        "end_date" => $end,
        "custom_fields" => $custom_fields,
    );
    $result = model('App\Models\Timesheets_model')->get_details($options);
    $list = is_object($result) ? $result->getResult() : array();

    $m = $r->getMethod('_load_tasks_for_timesheet_rows');
    $m->setAccessible(true);
    $m->invoke($projects, $list);

    $make = $r->getMethod('_make_timesheet_row');
    $make->setAccessible(true);
    $rows = array();
    foreach ($list as $data) {
        $rows[] = array('raw' => $data, 'cells' => $make->invoke($projects, $data, $custom_fields));
    }
    return array($projects, $list, $rows);
}

// --- Query counts: N+1 vs batch for 31-day ---
list($proj, $list31, $rows31) = fetch_timesheet_rows($start_month, $end);
$ids = array();
foreach ($list31 as $r) {
    if (!empty($r->task_id)) $ids[(int)$r->task_id] = (int)$r->task_id;
}
echo "timesheet_rows_31d=" . count($list31) . " unique_tasks=" . count($ids) . "\n";

$qlog = [];
$t0 = microtime(true);
foreach ($ids as $id) {
    model('App\Models\Tasks_model')->get_details(['id' => $id])->getRow();
}
$ms_n1 = (microtime(true) - $t0) * 1000;
$q_n1 = count($qlog);
echo "BEFORE_n_plus_1 task_fetches q=$q_n1 ms=" . round($ms_n1, 2) . "\n";

$qlog = [];
$t0 = microtime(true);
if ($ids) {
    model('App\Models\Tasks_model')->get_details(['task_ids' => implode(',', $ids)])->getResult();
}
$ms_b = (microtime(true) - $t0) * 1000;
$q_b = count($qlog);
echo "AFTER_batch task_fetches q=$q_b ms=" . round($ms_b, 2) . "\n";
check('batch uses fewer-or-equal queries than N+1', $q_b <= max(1, $q_n1) && ($q_n1 === 0 || $q_b < $q_n1 || count($ids) <= 1), "n1=$q_n1 batch=$q_b");

// Details tab path query: one load_tasks call
$qlog = [];
$t0 = microtime(true);
fetch_timesheet_rows($start_month, $end);
$ms_page = (microtime(true) - $t0) * 1000;
$task_q = 0;
foreach ($qlog as $sql) {
    if (stripos($sql, 'rise_tasks') !== false && stripos($sql, 'IN(') !== false) $task_q++;
}
echo "PAGE_31d total_q=" . count($qlog) . " task_IN_q≈$task_q ms=" . round($ms_page, 2) . "\n";

// --- OLD vs NEW: columns 0-10 identical structure (existing) ---
// "OLD" = cells that exist without phase20 extras: reconstruct expected from raw
list(, , $rows_day) = fetch_timesheet_rows($start_day, $end);
$sample = array_slice($rows_day, 0, 20);
$old_new_ok = true;
foreach ($sample as $item) {
    $cells = $item['cells'];
    // Existing block length before extras: 11 + custom + 1 options; extras are 9 cols inserted before custom
    // Member..Note = indexes 0-10
    if (count($cells) < 20) { $old_new_ok = false; break; }
}
check('rows have existing + 9 new task cols', count($sample) === 0 || count($sample[0]['cells']) >= 20);

// Compare first 8 Excel-visible conceptual columns: 0,1,2,3,5,7,8,9
// Build "old-style" row from same raw without extras and compare
$projects = new App\Controllers\Projects();
$r = new ReflectionClass($projects);
$parent = $r;
while ($parent) {
    if ($parent->hasProperty('login_user')) {
        $p = $parent->getProperty('login_user');
        $p->setAccessible(true);
        $u = $p->getValue($projects);
        if (!$u || empty($u->id)) {
            $p->setValue($projects, model('App\Models\Users_model')->get_one(66));
        }
        break;
    }
    $parent = $parent->getParentClass();
}
$cf = model('App\Models\Custom_fields_model')->get_available_fields_for_table("timesheets", true, "staff");

function old_style_core_cells($data) {
    // Mirror pre-Phase-20 core 0-10 without calling new code path for extras verification
    $image_url = get_avatar($data->logged_by_avatar);
    $user = "<span class='avatar avatar-xs mr10'><img src='$image_url' alt=''></span> $data->logged_by_user";
    $start_time = $data->start_time;
    $end_time = $data->end_time;
    $project_title = anchor(get_uri("projects/view/" . $data->project_id), $data->project_title);
    $task_title = "-";
    if (!empty($data->task_id) && !empty($data->task_title)) {
        $task_title = modal_anchor(get_uri("projects/task_view"), $data->task_title, array("title" => app_lang('task_info') . " #$data->task_id", "data-post-id" => $data->task_id, "data-modal-lg" => "1"));
    }
    $client_name = "-";
    if ($data->timesheet_client_company_name) {
        $client_name = anchor(get_uri("clients/view/" . $data->timesheet_client_id), $data->timesheet_client_company_name);
    }
    $duration = convert_seconds_to_time_format($data->hours ? (round(($data->hours * 60), 0) * 60) : (abs(strtotime($end_time) - strtotime($start_time))));
    return array(
        strip_cell(get_team_member_profile_link($data->user_id, $user)),
        strip_cell($project_title),
        strip_cell($client_name),
        strip_cell($task_title),
        strip_cell(($data->hours || get_setting("users_can_input_only_total_hours_instead_of_period")) ? format_to_date($data->start_time) : format_to_datetime($data->start_time)),
        strip_cell($data->hours ? format_to_date($data->end_time) : format_to_datetime($data->end_time)),
        strip_cell($duration),
        strip_cell(to_decimal_format(convert_time_string_to_decimal($duration))),
    );
}

$excel_indexes = [0, 1, 2, 3, 5, 7, 8, 9]; // 8 export cols matching "1 to 8"
$mismatch = 0;
foreach ($rows_day as $item) {
    $old = old_style_core_cells($item['raw']);
    $new = [];
    foreach ($excel_indexes as $i) {
        $new[] = strip_cell($item['cells'][$i]);
    }
    if ($old !== $new) {
        $mismatch++;
        if ($mismatch <= 3) {
            echo "DIFF row timesheet_id={$item['raw']->id}\n  OLD=" . json_encode($old) . "\n  NEW=" . json_encode($new) . "\n";
        }
    }
}
check('OLD vs NEW excel cols 1-8 identical (all day rows)', $mismatch === 0, "mismatches=$mismatch");

// Total/summation protection: only column 8 is time; score cols 12 and 19 must not be numeric-summed in config
$view = file_get_contents(FCPATH . 'app/Views/projects/timesheets/all_timesheets.php');
check('summation still only column 8', preg_match("/summation:\s*\[\s*\{\s*column:\s*8/", $view) === 1);
check('score cols not in summation', strpos($view, 'column: 12') === false && strpos($view, 'column: 19') === false);

// --- Compare new cols vs Tasks helpers for same tasks ---
echo "\n=== Score/Status/Created/Archived vs Tasks ===\n";
printf("%-8s %-20s %-20s %-12s %-12s %s\n", "task", "score_ts", "score_tasks", "status", "created", "archived");
$compared = 0;
$tasks_cache = [];
foreach ($rows31 as $item) {
    $tid = (int)($item['raw']->task_id ?? 0);
    if (!$tid || isset($tasks_cache[$tid])) continue;
    $task = model('App\Models\Tasks_model')->get_details(['id' => $tid])->getRow();
    if (!$task) continue;
    $tasks_cache[$tid] = true;
    $score_ts = strip_cell($item['cells'][12]);
    $score_task = strip_cell(get_task_points_badge_html($task, ['compact' => true, 'show_reason' => true]));
    $status_ts = strip_cell($item['cells'][17]);
    $status_task = $task->status_key_name ? app_lang($task->status_key_name) : $task->status_title;
    $created_ts = strip_cell($item['cells'][18]);
    $created_task = is_date_exists($task->created_date) ? format_to_date($task->created_date, false) : "-";
    $arch_ts = strip_cell($item['cells'][19]);
    $arch_task = format_task_point_value(get_task_gained_points_value($task));
    $ok = ($score_ts === $score_task && $status_ts === $status_task && $created_ts === $created_task && $arch_ts === (string)$arch_task);
    printf("%-8s %-20s %-20s %-12s %-12s %s %s\n", $tid, substr($score_ts,0,18), substr($score_task,0,18), $status_ts, $created_ts, $arch_ts, $ok ? 'MATCH' : 'DIFF');
    check("task $tid score/status/created/archived match Tasks", $ok, "ts=[$score_ts|$status_ts|$created_ts|$arch_ts] task=[$score_task|$status_task|$created_task|$arch_task]");
    $compared++;
    if ($compared >= 8) break;
}

// Edge: no-task rows blank extras
$blank_ok = true; $found_blank = false;
foreach ($rows31 as $item) {
    if (!empty($item['raw']->task_id)) continue;
    $found_blank = true;
    for ($i = 11; $i <= 19; $i++) {
        if (strip_cell($item['cells'][$i]) !== '-') { $blank_ok = false; }
    }
    break;
}
if ($found_blank) {
    check('project-level (no task) extras blank', $blank_ok);
} else {
    echo "[SKIP] no project-level timesheet in 31d range\n";
}

// Task with 3+ time entries: score repeated, same value
$counts = [];
foreach ($rows31 as $item) {
    $tid = (int)($item['raw']->task_id ?? 0);
    if ($tid) $counts[$tid] = ($counts[$tid] ?? 0) + 1;
}
$multi = null;
foreach ($counts as $tid => $c) {
    if ($c >= 3) { $multi = $tid; break; }
}
if ($multi) {
    $scores = [];
    foreach ($rows31 as $item) {
        if ((int)$item['raw']->task_id === $multi) $scores[] = strip_cell($item['cells'][12]);
    }
    check("task $multi with 3+ entries repeats same Score", count(array_unique($scores)) === 1, json_encode($scores));
} else {
    echo "[SKIP] no task with 3+ time entries in range\n";
}

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
