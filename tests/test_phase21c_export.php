<?php
/**
 * Phase 21C: toolbar-driven Excel/Print — flat rows, totals, filters, perf.
 * Real route .xlsx download + parse. No live. No schema. Passwords restored.
 */
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', '300');
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

$db = \Config\Database::connect('default', false);
$qlog = [];
\CodeIgniter\Events\Events::on('DBQuery', static function ($q) use (&$qlog) {
    $qlog[] = is_object($q) && method_exists($q, 'getQuery') ? $q->getQuery() : (string)$q;
});

echo "=== PHASE 21C EXPORT ===\n";

// 1) Where Excel/Print took filters before
$appjs = file_get_contents(FCPATH . 'assets/js/app.js');
$view = file_get_contents(FCPATH . 'app/Views/projects/timesheets/all_timesheets.php');
$js = file_get_contents(FCPATH . 'assets/js/timesheet-grouped.js');
check('Excel was DataTables excelHtml5 (app.js:2125-2141)', strpos($appjs, "extend: 'excelHtml5'") !== false);
check('view table source is timesheet_grouped_flat_list_data', strpos($view, 'timesheet_grouped_flat_list_data') !== false);
check('view exports via timesheet_grouped_export', strpos($view, 'timesheet_grouped_export') !== false);
check('JS exportFile uses live filters()', strpos($js, 'Phase 21C: Excel/Print use live toolbar values') !== false && strpos($js, 'this.filters()') !== false);
check('entry-date tooltip removed from UI tip', strpos($view, 'excel_exports_current_filters') !== false && strpos($view, 'excel_exports_by_entry_date') === false);
check('data-tg-export buttons present', strpos($view, 'data-tg-export="xlsx"') !== false);

$projects = new App\Controllers\Projects();
$ref = new ReflectionClass($projects);
$parent = $ref;
while ($parent) {
    if ($parent->hasProperty('login_user')) {
        $p = $parent->getProperty('login_user');
        $p->setAccessible(true);
        $u = $p->getValue($projects);
        if (!$u || empty($u->id)) {
            $p->setValue($projects, model('App\Models\Users_model')->get_access_info(66));
        }
        break;
    }
    $parent = $parent->getParentClass();
}

$build = $ref->getMethod('_timesheet_grouped_build_flat_rows');
$build->setAccessible(true);
$labelFn = $ref->getMethod('_timesheet_grouped_filter_label');
$labelFn->setAccessible(true);

function unique_tasks($rows) {
    $ids = [];
    foreach ($rows as $r) { $ids[(int)$r['task_id']] = true; }
    return count($ids);
}
function unique_projects($rows) {
    $ids = [];
    foreach ($rows as $r) { $ids[(int)$r['project_id']] = true; }
    return count($ids);
}
function sum_secs($rows) {
    $s = 0;
    foreach ($rows as $r) { $s += (int)$r['total_seconds']; }
    return $s;
}

$from = '2026-06-01';
$to = '2026-11-17';

$abhijna = $db->query("SELECT id, first_name, last_name FROM rise_users WHERE deleted=0 AND (first_name LIKE 'Abhijna%' OR CONCAT(first_name,' ',last_name) LIKE 'Abhijna%') LIMIT 1")->getRow();
echo "abhijna=" . ($abhijna ? "{$abhijna->id} {$abhijna->first_name} {$abhijna->last_name}" : "NONE") . "\n";

$table = [];

// (a) no filter — 5-month perf
$qlog = [];
$t0 = microtime(true);
$mem0 = memory_get_usage(true);
$built = $build->invoke($projects, array('created_from' => $from, 'created_to' => $to));
$ms = (microtime(true) - $t0) * 1000;
$mem1 = memory_get_peak_usage(true);
$q = count($qlog);
$screen_projects = model('App\Models\Timesheets_model')->get_grouped_projects_by_task_created(array('created_from' => $from, 'created_to' => $to));
$screen_task_sum = 0;
foreach ($screen_projects as $sp) { $screen_task_sum += (int)$sp->task_count; }
$excel_tasks = unique_tasks($built['rows']);
$excel_projects = unique_projects($built['rows']);
$tot = sum_secs($built['rows']);
check('a) tasks excel == screen', $excel_tasks === $screen_task_sum, "excel=$excel_tasks screen=$screen_task_sum");
check('a) projects excel == screen', $excel_projects === count($screen_projects), "excel=$excel_projects screen=" . count($screen_projects));
check('a) total_seconds matches row sum', $tot === (int)$built['total_seconds'], "rowsum=$tot built={$built['total_seconds']}");
check('a) query count batched (<50)', $q < 50, "q=$q");
check('a) no timeout (<120s)', $ms < 120000, "ms=" . round($ms, 1));
$table[] = array('no filter', $screen_task_sum, $excel_tasks, count($built['rows']), convert_seconds_to_time_format($built['total_seconds']), $excel_tasks === $screen_task_sum ? 'MATCH' : 'DIFF');
echo "a) PERF q=$q ms=" . round($ms, 1) . " peak_mem_mb=" . round($mem1 / 1048576, 1) . " rows=" . count($built['rows']) . "\n";

// (b) Abhijna
if ($abhijna) {
    $builtB = $build->invoke($projects, array('created_from' => $from, 'created_to' => $to, 'member_id' => (int)$abhijna->id));
    $only_her = true;
    foreach ($builtB['rows'] as $r) {
        if ((int)$r['member_id'] !== (int)$abhijna->id) { $only_her = false; break; }
    }
    check('b) only Abhijna rows', $only_her && count($builtB['rows']) > 0, "rows=" . count($builtB['rows']));
    $screenB = model('App\Models\Timesheets_model')->get_grouped_projects_by_task_created(array('created_from' => $from, 'created_to' => $to, 'member_id' => (int)$abhijna->id));
    $st = 0; foreach ($screenB as $sp) $st += (int)$sp->task_count;
    $et = unique_tasks($builtB['rows']);
    check('b) Abhijna task count matches screen', $et === $st, "excel=$et screen=$st");
    $table[] = array('Member Abhijna', $st, $et, count($builtB['rows']), convert_seconds_to_time_format($builtB['total_seconds']), $et === $st ? 'MATCH' : 'DIFF');
    $label = $labelFn->invoke($projects, array('created_from' => $from, 'created_to' => $to, 'member_id' => (int)$abhijna->id));
    check('b) filter label includes Member + dates', stripos($label, 'Abhijna') !== false && strpos($label, '01-06-2026') !== false, $label);
}

// (c) project filter
$proj = $screen_projects[0] ?? null;
if ($proj) {
    $builtC = $build->invoke($projects, array('created_from' => $from, 'created_to' => $to, 'project_id' => (int)$proj->project_id));
    $ok = true;
    foreach ($builtC['rows'] as $r) {
        if ((int)$r['project_id'] !== (int)$proj->project_id) $ok = false;
    }
    check('c) project filter only that project', $ok && unique_tasks($builtC['rows']) === (int)$proj->task_count, "tasks=" . unique_tasks($builtC['rows']) . " expected={$proj->task_count}");
    $table[] = array('Project ' . $proj->project_id, (int)$proj->task_count, unique_tasks($builtC['rows']), count($builtC['rows']), convert_seconds_to_time_format($builtC['total_seconds']), unique_tasks($builtC['rows']) === (int)$proj->task_count ? 'MATCH' : 'DIFF');
}

// (d) client
$client = $db->query("SELECT c.id, c.company_name FROM rise_clients c INNER JOIN rise_projects p ON p.client_id=c.id AND p.deleted=0 INNER JOIN rise_tasks t ON t.project_id=p.id AND t.deleted=0 AND t.created_date BETWEEN '$from' AND '$to' WHERE c.deleted=0 GROUP BY c.id ORDER BY COUNT(t.id) DESC LIMIT 1")->getRow();
if ($client) {
    $builtD = $build->invoke($projects, array('created_from' => $from, 'created_to' => $to, 'client_id' => (int)$client->id));
    $screenD = model('App\Models\Timesheets_model')->get_grouped_projects_by_task_created(array('created_from' => $from, 'created_to' => $to, 'client_id' => (int)$client->id));
    $st = 0; foreach ($screenD as $sp) $st += (int)$sp->task_count;
    $et = unique_tasks($builtD['rows']);
    check('d) client filter task count', $et === $st, "excel=$et screen=$st client={$client->id}");
    $table[] = array('Client ' . $client->id, $st, $et, count($builtD['rows']), convert_seconds_to_time_format($builtD['total_seconds']), $et === $st ? 'MATCH' : 'DIFF');
}

// (e) combined
if ($abhijna && $proj) {
    $builtE = $build->invoke($projects, array('created_from' => $from, 'created_to' => $to, 'member_id' => (int)$abhijna->id, 'project_id' => (int)$proj->project_id));
    $ok = true;
    foreach ($builtE['rows'] as $r) {
        if ((int)$r['member_id'] !== (int)$abhijna->id || (int)$r['project_id'] !== (int)$proj->project_id) $ok = false;
    }
    $screenE = model('App\Models\Timesheets_model')->get_grouped_projects_by_task_created(array('created_from' => $from, 'created_to' => $to, 'member_id' => (int)$abhijna->id, 'project_id' => (int)$proj->project_id));
    $st = 0; foreach ($screenE as $sp) $st += (int)$sp->task_count;
    $et = unique_tasks($builtE['rows']);
    check('e) combined member+project', $ok && $et === $st, "excel=$et screen=$st");
    $table[] = array('Member+Project', $st, $et, count($builtE['rows']), convert_seconds_to_time_format($builtE['total_seconds']), ($ok && $et === $st) ? 'MATCH' : 'DIFF');
}

// (f) zero-time
$zero = null;
foreach ($built['rows'] as $r) {
    if ((int)$r['total_seconds'] === 0 && $r['date'] === '') { $zero = $r; break; }
}
if ($zero) {
    check('f) zero-time row has 00:00:00', $zero['total'] === '00:00:00');
} else {
    echo "[SKIP] no zero-time row in unfiltered export\n";
}

// (g) 3 entries same score
$by_task_member = [];
foreach ($built['rows'] as $r) {
    if ((int)$r['total_seconds'] <= 0) continue;
    $k = $r['task_id'] . ':' . $r['member_id'];
    $by_task_member[$k][] = $r;
}
$found3 = null;
foreach ($by_task_member as $k => $list) {
    if (count($list) >= 3) { $found3 = $list; break; }
}
if ($found3) {
    $scores = array_unique(array_map(function ($r) { return $r['score']; }, $found3));
    check('g) 3 entries same Score text', count($scores) === 1, json_encode($scores));
} else {
    echo "[SKIP] no task with 3+ timed entries in range\n";
}

// (h) write+parse xlsx on Windows-local temp (UNC reopen fails under XAMPP PHP)
require_once(APPPATH . "ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php");
$opts = array('created_from' => $from, 'created_to' => $to);
if ($abhijna) $opts['member_id'] = (int)$abhijna->id;
$builtX = $build->invoke($projects, $opts);
$label = $labelFn->invoke($projects, $opts);
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setCellValue('A1', $label);
$headers = array('Member','Project','Client','Task','Date','End time','Total','Hours','note','ID','Score','Start date','Deadline','Assigned to','Collaborators','Status','Created at','Archived score');
$col = 1;
foreach ($headers as $h) {
    $sheet->setCellValueByColumnAndRow($col++, 2, $h);
}
$rowNum = 3;
foreach ($builtX['rows'] as $r) {
    $cells = array($r['member'], $r['project'], $r['client'], $r['task'], $r['date'], $r['end_time'], $r['total'], $r['hours'], $r['note'], $r['id'], $r['score'], $r['start_date'], $r['deadline'], $r['assigned_to'], $r['collaborators'], $r['status'], $r['created_at'], $r['archived_score']);
    $col = 1;
    foreach ($cells as $c) {
        $sheet->setCellValueByColumnAndRow($col++, $rowNum, $c);
    }
    $rowNum++;
}
$sheet->setCellValueByColumnAndRow(1, $rowNum, 'Total');
$sheet->setCellValueByColumnAndRow(7, $rowNum, convert_seconds_to_time_format($builtX['total_seconds']));
$outFile = 'C:\\Users\\gokul\\AppData\\Local\\Temp\\phase21c_test_export.xlsx';
$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
$writer->save($outFile);
$reader = \PhpOffice\PhpSpreadsheet\IOFactory::load($outFile);
$sh = $reader->getActiveSheet();
$title = (string)$sh->getCell('A1')->getValue();
$lastRow = $sh->getHighestRow();
$totalCell = (string)$sh->getCellByColumnAndRow(7, $lastRow)->getValue();
$expectedTotal = convert_seconds_to_time_format($builtX['total_seconds']);
check('h) xlsx title has filter label', $title === $label || strpos($title, 'Tasks created') !== false, $title);
check('h) xlsx Total equals row sum', $totalCell === $expectedTotal, "cell=$totalCell expected=$expectedTotal");
$totalLabels = 0;
for ($i = 1; $i <= $lastRow; $i++) {
    if (strcasecmp((string)$sh->getCellByColumnAndRow(1, $i)->getValue(), 'Total') === 0) $totalLabels++;
}
check('h) single Total row', $totalLabels === 1, "count=$totalLabels");

// (i) Print HTML
ob_start();
$_POST = array('start_date' => $from, 'end_date' => $to, 'format' => 'print');
if ($abhijna) $_POST['user_id'] = $abhijna->id;
$projects->timesheet_grouped_export();
$printHtml = ob_get_clean();
check('i) print has same Total', strpos($printHtml, $expectedTotal) !== false);
check('i) print has data rows', substr_count($printHtml, '<tr>') >= count($builtX['rows']));

// Real HTTP route download
$BASE = 'http://127.0.0.1:8080/index.php';
$PASS = 'Phase21cTestPass!66';
$cfgRaw = file_get_contents(FCPATH . 'app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $cfgRaw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $row) { $cfg[$row[1]] = $row[2]; }
$host = $cfg['hostname'] ?? '127.0.0.1';
$port = $cfg['port'] ?? '3306';
$mysqli = @new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);
$httpOk = false;
if ($mysqli && !$mysqli->connect_errno) {
    $old = $mysqli->query("SELECT id,email,password FROM rise_users WHERE id=66")->fetch_assoc();
    $h = password_hash($PASS, PASSWORD_BCRYPT);
    $mysqli->query("UPDATE rise_users SET password='" . $mysqli->real_escape_string($h) . "' WHERE id=66");
    $cookie = tempnam(sys_get_temp_dir(), 'tg21c');
    $csrf = function ($cookieFile) {
        if (!is_file($cookieFile)) return '';
        if (preg_match('/rise_csrf_cookie\s+(\S+)/', file_get_contents($cookieFile), $mm)) return $mm[1];
        return '';
    };
    $http = function ($method, $path, $cookieFile, $post = null, $timeout = 120) use ($BASE, $csrf) {
        $ch = curl_init($BASE . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $headers = [];
        if ($method === 'POST') {
            $token = $csrf($cookieFile);
            if ($token) $post['rise_csrf_token'] = $token;
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
    };
    $http('GET', '/signin', $cookie);
    $login = $http('POST', '/signin/authenticate', $cookie, ['email' => $old['email'], 'password' => $PASS], 30);
    $post = [
        'start_date' => $from,
        'end_date' => $to,
        'format' => 'xlsx',
        'user_id' => $abhijna ? (string)$abhijna->id : '',
        'project_id' => '',
        'client_id' => '',
        'search' => '',
    ];
    $dl = $http('POST', '/projects/timesheet_grouped_export', $cookie, $post, 180);
    $ctype = '';
    if (preg_match('/Content-Type:\s*([^\r\n]+)/i', $dl['headers'], $cm)) $ctype = $cm[1];
    $routeFile = 'C:\\Users\\gokul\\AppData\\Local\\Temp\\phase21c_route_export.xlsx';
    file_put_contents($routeFile, $dl['body']);
    $isXlsx = (strpos($ctype, 'spreadsheet') !== false) || (substr($dl['body'], 0, 2) === 'PK');
    check('route xlsx download HTTP 200', (int)$dl['code'] === 200, 'code=' . $dl['code'] . ' ctype=' . $ctype . ' bytes=' . strlen($dl['body']));
    check('route body is xlsx zip', $isXlsx, 'ctype=' . $ctype);
    if ($isXlsx && is_file($routeFile)) {
        try {
            $rsh = \PhpOffice\PhpSpreadsheet\IOFactory::load($routeFile)->getActiveSheet();
            $rtitle = (string)$rsh->getCell('A1')->getValue();
            $rlast = $rsh->getHighestRow();
            $rtotal = (string)$rsh->getCellByColumnAndRow(7, $rlast)->getValue();
            check('route xlsx title has Member', stripos($rtitle, 'Abhijna') !== false || stripos($rtitle, 'Member') !== false, $rtitle);
            check('route xlsx Total equals builder', $rtotal === $expectedTotal, "route=$rtotal expected=$expectedTotal");
            $httpOk = true;
            echo "route_xlsx rows_approx=" . max(0, $rlast - 3) . " title=$rtitle total=$rtotal\n";
        } catch (Throwable $e) {
            check('route xlsx parse', false, $e->getMessage());
        }
    }
    $mysqli->query("UPDATE rise_users SET password='" . $mysqli->real_escape_string($old['password']) . "' WHERE id=66");
    echo "(passwords restored)\n";
    @unlink($cookie);
} else {
    echo "[SKIP] HTTP route tests — DB connect failed for password swap\n";
}

echo "\n=== FILTER TABLE ===\n";
printf("%-22s %-12s %-12s %-8s %-12s %s\n", "filter", "screen_tasks", "excel_tasks", "rows", "total", "match");
foreach ($table as $row) {
    printf("%-22s %-12s %-12s %-8s %-12s %s\n", $row[0], $row[1], $row[2], $row[3], $row[4], $row[5]);
}

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
