<?php
/**
 * Clean HTTP timer start/stop against local 127.0.0.1:8080.
 * Temp password for user 40; restored on shutdown.
 */
ini_set('memory_limit', '256M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$BASE = 'http://127.0.0.1:8080';
$COOKIE = '/tmp/crm17e_timer.txt';
@unlink($COOKIE);
$PASS = 'Phase17eTimerPass!99';

$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

$user = $db->query("SELECT id,email,password FROM rise_users WHERE id=40")->fetch_assoc();
$oldHash = $user['password'];
$db->query("UPDATE rise_users SET password='" . $db->real_escape_string(password_hash($PASS, PASSWORD_BCRYPT)) . "' WHERE id=40");
register_shutdown_function(function () use ($db, $oldHash) {
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($oldHash) . "' WHERE id=40");
    echo "(password restored)\n";
});

function csrf($cookie) {
    if (!is_file($cookie)) return '';
    if (preg_match('/rise_csrf_cookie\s+(\S+)/', file_get_contents($cookie), $m)) return $m[1];
    return '';
}

function http($method, $url, $cookie, $post = null, $follow = false, $timeout = 15) {
    $ch = curl_init($url);
    $headers = ['X-Requested-With: XMLHttpRequest'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($post !== null) {
        $token = csrf($cookie);
        if (is_array($post)) {
            if ($token !== '') $post['rise_csrf_token'] = $token;
            $body = http_build_query($post);
        } else {
            $body = $post;
        }
        if ($token !== '') $headers[] = 'X-CSRF-TOKEN: ' . $token;
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $t0 = microtime(true);
    $raw = curl_exec($ch);
    $ms = (microtime(true) - $t0) * 1000;
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $errno = curl_errno($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'code' => $code,
        'errno' => $errno,
        'err' => $err,
        'ms' => round($ms, 1),
        'headers' => substr((string)$raw, 0, $hs),
        'body' => substr((string)$raw, $hs),
    ];
}

echo "=== TIMER HTTP CLEAN RUN ===\n";
$ping = http('GET', "$BASE/signin", $COOKIE, null, true, 10);
echo "server_signin code={$ping['code']} errno={$ping['errno']} err={$ping['err']} ms={$ping['ms']}\n";
if ($ping['code'] == 0) {
    echo "SERVER_DOWN code=0 means curl failed to connect/complete (errno={$ping['errno']}: {$ping['err']})\n";
    exit(1);
}

$login = http('POST', "$BASE/signin/authenticate", $COOKIE, [
    'email' => $user['email'],
    'password' => $PASS,
], false, 15);
echo "login code={$login['code']} loc=" . (preg_match('/Location:\s*(\S+)/i', $login['headers'], $m) ? $m[1] : '') . "\n";
$dash = http('GET', "$BASE/dashboard", $COOKIE, null, true, 15);
echo "dash code={$dash['code']} authed=" . (($dash['code']==200 && strpos($dash['body'], 'name="password"')===false)?'1':'0') . "\n";

$project_id = 626;
$todo = (int)$db->query("SELECT id FROM rise_task_status WHERE key_name='to_do' AND deleted=0")->fetch_row()[0];
$title = 'P17E timer clean ' . date('H:i:s');
$create = http('POST', "$BASE/index.php/projects/save_task", $COOKIE, [
    'title' => $title,
    'description' => 'timer e2e',
    'project_id' => $project_id,
    'assigned_to' => 40,
    'collaborators' => '',
    'points' => '5',
    'status_id' => $todo,
    'milestone_id' => '0',
    'priority_id' => '0',
    'labels' => '',
    'start_date' => date('Y-m-d'),
    'deadline' => date('Y-m-d'),
    'end_time' => '23:59:00',
    'recurring' => '0',
], false, 30);
$j = json_decode($create['body'], true);
$taskId = is_array($j) && !empty($j['id']) ? (int)$j['id'] : 0;
if (!$taskId) {
    $row = $db->query("SELECT id FROM rise_tasks WHERE title='" . $db->real_escape_string($title) . "' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $taskId = $row ? (int)$row['id'] : 0;
}
echo "create code={$create['code']} task_id=$taskId success=" . (!empty($j['success'])?'1':'0') . "\n";
if (!$taskId) {
    echo "CREATE_FAILED body=" . substr($create['body'], 0, 200) . "\n";
    exit(1);
}

echo "\n-- before timer rows --\n";
$r = $db->query("SELECT id,user_id,task_id,status,start_time,end_time,deleted FROM rise_project_time WHERE task_id=$taskId ORDER BY id");
while ($row = $r->fetch_assoc()) print_r($row);

$start = http('POST', "$BASE/index.php/projects/timer/$project_id/start", $COOKIE, [
    'task_id' => $taskId,
    'task_timer' => '1',
    'note' => '',
], false, 15);
echo "\nSTART code={$start['code']} errno={$start['errno']} err={$start['err']} ms={$start['ms']}\n";
echo "START body_snip=" . substr(preg_replace('/\s+/', ' ', $start['body']), 0, 180) . "\n";

echo "\n-- after START --\n";
$r = $db->query("SELECT id,user_id,task_id,status,start_time,end_time,deleted FROM rise_project_time WHERE task_id=$taskId ORDER BY id");
$n = 0;
while ($row = $r->fetch_assoc()) { print_r($row); $n++; }
echo "row_count=$n\n";

usleep(300000);

$stop = http('POST', "$BASE/index.php/projects/timer/$project_id/stop", $COOKIE, [
    'task_id' => $taskId,
    'task_timer' => '1',
    'note' => '17e stop',
], false, 15);
echo "\nSTOP code={$stop['code']} errno={$stop['errno']} err={$stop['err']} ms={$stop['ms']}\n";
echo "STOP body_snip=" . substr(preg_replace('/\s+/', ' ', $stop['body']), 0, 180) . "\n";

echo "\n-- after STOP --\n";
$r = $db->query("SELECT id,user_id,task_id,status,start_time,end_time,deleted FROM rise_project_time WHERE task_id=$taskId ORDER BY id");
$n = 0;
while ($row = $r->fetch_assoc()) { print_r($row); $n++; }
echo "row_count=$n\n";

echo "\nEXPLAIN code=0: curl returns HTTP code 0 when the transfer fails (timeout, connection reset, DNS, etc), not when the server sends an HTTP status. errno/err above name the failure.\n";
echo "DONE\n";
