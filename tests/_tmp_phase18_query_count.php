<?php
/**
 * Query count before/after open-timer batch for tasks_list + tasks_in_review.
 * "before" = same list code path with _load_open_timers_for_task_list no-op'd via env flag is not available;
 * instead count project_time open-timer queries in a normal list response (should be 0 or 1).
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$BASE = 'http://127.0.0.1:8080';
$COOKIE = '/tmp/crm18_qc.txt';
@unlink($COOKIE);
$PASS = 'Phase18QC!99';

$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

$user = $db->query("SELECT id,email,password FROM rise_users WHERE id=7")->fetch_assoc();
$old = $user['password'];
$db->query("UPDATE rise_users SET password='" . $db->real_escape_string(password_hash($PASS, PASSWORD_BCRYPT)) . "' WHERE id=7");
register_shutdown_function(function () use ($db, $old) {
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($old) . "' WHERE id=7");
});

function csrf($c) {
    if (!is_file($c)) return '';
    return preg_match('/rise_csrf_cookie\s+(\S+)/', file_get_contents($c), $m) ? $m[1] : '';
}
function http($method, $path, $cookie, $post = null) {
    global $BASE;
    $ch = curl_init($BASE . $path);
    $headers = ['X-Requested-With: XMLHttpRequest'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($post !== null) {
        $token = csrf($cookie);
        if (is_array($post)) {
            if ($token) $post['rise_csrf_token'] = $token;
            $post = http_build_query($post);
        }
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        if ($token) $headers[] = 'X-CSRF-TOKEN: ' . $token;
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr((string)$raw, $hs)];
}

http('GET', '/signin', $COOKIE, null);
http('POST', '/signin/authenticate', $COOKIE, ['email' => $user['email'], 'password' => $PASS]);
http('GET', '/dashboard', $COOKIE, null);

// Measure via general_log-like: use performance_schema if available, else approximate by
// instrumenting with a CLI bootstrap that calls the same model methods.

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
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
\Config\Services::session()->set('user_id', 7);

$qlog = [];
\CodeIgniter\Events\Events::on('DBQuery', static function ($query) use (&$qlog) {
    $qlog[] = is_object($query) && method_exists($query, 'getQuery') ? $query->getQuery() : (string)$query;
});

function count_open_timer_queries($queries) {
    $n = 0;
    foreach ($queries as $sql) {
        if (stripos($sql, 'project_time') !== false && stripos($sql, "status='open'") !== false && stripos($sql, 'task_id IN') !== false) {
            $n++;
        } elseif (stripos($sql, 'project_time') !== false && stripos($sql, 'status') !== false && stripos($sql, 'open') !== false && stripos($sql, 'task_id') !== false) {
            // any open timer fetch
            $n++;
        }
    }
    return $n;
}

$tasksModel = model('App\Models\Tasks_model');
$tsModel = model('App\Models\Timesheets_model');

// Visible task ids for project tasks list (same scope as a typical page)
$ids = [];
$res = $db->query("SELECT id FROM rise_tasks WHERE project_id=626 AND deleted=0 ORDER BY id DESC LIMIT 40");
while ($row = $res->fetch_row()) { $ids[] = (int)$row[0]; }
echo "visible_task_ids=" . count($ids) . "\n";

$qlog = [];
$t0 = microtime(true);
foreach ($ids as $id) {
    $tsModel->get_open_timers_for_task($id);
}
$ms_before = (microtime(true) - $t0) * 1000;
$q_before = count($qlog);
$ot_before = count_open_timer_queries($qlog);
echo "BEFORE_n_plus_1 tasks_list open_timer_q=$ot_before total_q=$q_before ms=" . round($ms_before, 2) . "\n";

$qlog = [];
$t0 = microtime(true);
$tsModel->get_open_timers_for_tasks($ids);
$ms_after = (microtime(true) - $t0) * 1000;
$q_after = count($qlog);
$ot_after = count_open_timer_queries($qlog);
echo "AFTER_batch tasks_list open_timer_q=$ot_after total_q=$q_after ms=" . round($ms_after, 2) . "\n";

// tasks_in_review
$list2 = $tasksModel->get_details(['awaiting_review_all' => true]);
$rows2 = is_object($list2) ? $list2->getResult() : [];
$ids2 = [];
foreach ($rows2 as $r) { $ids2[] = (int)$r->id; }
echo "in_review_task_ids=" . count($ids2) . "\n";

$qlog = [];
$t0 = microtime(true);
foreach ($ids2 as $id) {
    $tsModel->get_open_timers_for_task($id);
}
$ms_b2 = (microtime(true) - $t0) * 1000;
$ot_b2 = count_open_timer_queries($qlog);
echo "BEFORE_n_plus_1 tasks_in_review open_timer_q=$ot_b2 total_q=" . count($qlog) . " ms=" . round($ms_b2, 2) . "\n";

$qlog = [];
$t0 = microtime(true);
if ($ids2) {
    $tsModel->get_open_timers_for_tasks($ids2);
}
$ms_a2 = (microtime(true) - $t0) * 1000;
$ot_a2 = count_open_timer_queries($qlog);
echo "AFTER_batch tasks_in_review open_timer_q=$ot_a2 total_q=" . count($qlog) . " ms=" . round($ms_a2, 2) . "\n";

echo "DONE\n";
