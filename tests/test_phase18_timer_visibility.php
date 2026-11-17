<?php
/**
 * Phase 18: running timer visible to creator/admin; list dots; dual timers; 2-day elapsed.
 * Real HTTP routes. Temp passwords restored on shutdown. No live.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$BASE = 'http://127.0.0.1:8080';
$COOKIES = [
    40 => '/tmp/crm18_u40.txt',
    7 => '/tmp/crm18_u7.txt',
    66 => '/tmp/crm18_u66.txt',
    68 => '/tmp/crm18_u68.txt',
];
foreach ($COOKIES as $c) @unlink($c);
$PASS = 'Phase18TimerVis!99';

$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

$users = [40, 7, 66, 68];
$old = [];
foreach ($users as $uid) {
    $old[$uid] = $db->query("SELECT id,email,password,first_name,last_name FROM rise_users WHERE id=$uid")->fetch_assoc();
}
$h = password_hash($PASS, PASSWORD_BCRYPT);
$db->query("UPDATE rise_users SET password='" . $db->real_escape_string($h) . "' WHERE id IN (40,7,66,68)");
$db->query("UPDATE rise_settings SET setting_value='1' WHERE setting_name='users_can_start_multiple_timers_at_a_time'");
register_shutdown_function(function () use ($db, $old) {
    foreach ($old as $uid => $row) {
        $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($row['password']) . "' WHERE id=$uid");
    }
    echo "(passwords restored)\n";
});

function csrf($cookie) {
    if (!is_file($cookie)) return '';
    if (preg_match('/rise_csrf_cookie\s+(\S+)/', file_get_contents($cookie), $m)) return $m[1];
    return '';
}
function http($method, $path, $cookie, $post = null, $follow = true, $timeout = 25) {
    global $BASE;
    $ch = curl_init($BASE . $path);
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
            $post = http_build_query($post);
        }
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        if ($token !== '') $headers[] = 'X-CSRF-TOKEN: ' . $token;
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'errno' => $errno, 'err' => $err, 'body' => substr((string)$raw, $hs)];
}
function login($uid) {
    global $COOKIES, $old, $PASS;
    $c = $COOKIES[$uid];
    http('GET', '/signin', $c, null, true);
    $r = http('POST', '/signin/authenticate', $c, ['email' => $old[$uid]['email'], 'password' => $PASS], false);
    http('GET', '/dashboard', $c, null, true);
    return $r['code'];
}
function task_view($uid, $taskId) {
    global $COOKIES;
    return http('POST', '/index.php/projects/task_view', $COOKIES[$uid], ['id' => $taskId], true, 30);
}
function timer_start($uid, $projectId, $taskId) {
    global $COOKIES;
    return http('POST', "/index.php/projects/timer/$projectId/start", $COOKIES[$uid], [
        'task_id' => $taskId, 'task_timer' => '1', 'note' => '',
    ], false, 30);
}
function timer_stop($uid, $projectId, $taskId) {
    global $COOKIES;
    return http('POST', "/index.php/projects/timer/$projectId/stop", $COOKIES[$uid], [
        'task_id' => $taskId, 'task_timer' => '1', 'note' => 'p18 stop',
    ], false, 30);
}

function assert_has($label, $hay, $needle) {
    $ok = strpos($hay, $needle) !== false;
    echo ($ok ? 'PASS' : 'FAIL') . " $label\n";
    return $ok;
}
function assert_not($label, $hay, $needle) {
    $ok = strpos($hay, $needle) === false;
    echo ($ok ? 'PASS' : 'FAIL') . " $label\n";
    return $ok;
}

$project_id = 626;
$todo = (int)$db->query("SELECT id FROM rise_task_status WHERE key_name='to_do' AND deleted=0")->fetch_row()[0];
$name40 = trim($old[40]['first_name'] . ' ' . $old[40]['last_name']);
$name68 = trim($old[68]['first_name'] . ' ' . $old[68]['last_name']);

echo "=== PHASE 18 TIMER VISIBILITY ===\n";
foreach ($users as $uid) {
    echo "login $uid code=" . login($uid) . "\n";
}

$title = 'P18 timer vis ' . date('H:i:s');
$create = http('POST', '/index.php/projects/save_task', $COOKIES[7], [
    'title' => $title,
    'description' => 'phase18',
    'project_id' => $project_id,
    'assigned_to' => 40,
    'collaborators' => '68',
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
echo "create task_id=$taskId code={$create['code']}\n";
if (!$taskId) { echo "ABORT\n"; exit(1); }

// 1) Assignee starts timer
$start = timer_start(40, $project_id, $taskId);
echo "assignee START code={$start['code']} errno={$start['errno']}\n";
$open = $db->query("SELECT id,user_id,status,start_time FROM rise_project_time WHERE task_id=$taskId AND status='open' AND deleted=0")->fetch_assoc();
echo "open_row user={$open['user_id']} start={$open['start_time']}\n";

// 2) Creator opens task — must see indicator, no stop for assignee's timer (stop is for own only)
$cv = task_view(7, $taskId);
echo "creator task_view code={$cv['code']}\n";
assert_has('creator sees Timer running', $cv['body'], 'Timer running');
assert_has('creator sees assignee name', $cv['body'], htmlspecialchars($name40));
assert_has('creator sees elapsed widget', $cv['body'], 'task-running-timer-elapsed');
assert_has('creator still sees Start timer (not owner)', $cv['body'], 'Start timer');
assert_not('creator does not get Stop timer button', $cv['body'], 'Stop timer');

// 3) Admin opens
$av = task_view(66, $taskId);
echo "admin task_view code={$av['code']}\n";
assert_has('admin sees Timer running', $av['body'], 'Timer running');
assert_has('admin sees assignee name', $av['body'], htmlspecialchars($name40));

// 4) Assignee still has Stop
$ov = task_view(40, $taskId);
echo "owner task_view code={$ov['code']}\n";
assert_has('owner sees Timer running', $ov['body'], 'Timer running');
assert_has('owner keeps Stop timer', $ov['body'], 'Stop timer');

// 5) List indicator (project tasks list as creator)
$list = http('POST', "/index.php/projects/tasks_list_data/$project_id", $COOKIES[7], [], true, 30);
echo "tasks_list_data code={$list['code']}\n";
assert_has('list has running timer dot class', $list['body'], 'task-running-timer-dot');
assert_has('list tooltip has is working on this', $list['body'], 'is working on this, started');

// 6) Stop — indicator gone
$stop = timer_stop(40, $project_id, $taskId);
echo "STOP code={$stop['code']}\n";
$cv2 = task_view(7, $taskId);
assert_not('after stop creator no Timer running', $cv2['body'], 'Timer running');
$list2 = http('POST', "/index.php/projects/tasks_list_data/$project_id", $COOKIES[7], [], true, 30);
assert_not('after stop list no dot for this task name in tooltip with assignee? soft', $list2['body'], $name40 . ' is working on this');

// 7) Dual timers: assignee + collaborator
timer_start(40, $project_id, $taskId);
timer_start(68, $project_id, $taskId);
$dual = task_view(7, $taskId);
$c40 = substr_count($dual['body'], htmlspecialchars($name40));
$c68 = substr_count($dual['body'], htmlspecialchars($name68));
echo "dual name40_count=$c40 name68_count=$c68\n";
assert_has('dual has Timer running', $dual['body'], 'Timer running');
$okDual = ($c40 >= 1 && $c68 >= 1);
echo ($okDual ? 'PASS' : 'FAIL') . " dual timers both names visible\n";
$openN = (int)$db->query("SELECT COUNT(*) FROM rise_project_time WHERE task_id=$taskId AND status='open' AND deleted=0")->fetch_row()[0];
echo "open_count=$openN\n";

// 8) 2-day open timer elapsed format
$db->query("UPDATE rise_project_time SET start_time=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 51 HOUR) WHERE task_id=$taskId AND user_id=40 AND status='open' AND deleted=0 LIMIT 1");
$aged = task_view(7, $taskId);
echo "aged body snip elapsed: ";
if (preg_match_all('/task-running-timer-elapsed[^>]*>([^<]+)</', $aged['body'], $mm)) {
    echo implode(' | ', $mm[1]) . "\n";
} else {
    echo "(none)\n";
}
assert_has('2d style present', $aged['body'], '2d');

// cleanup open timers
$db->query("UPDATE rise_project_time SET status='logged', end_time=UTC_TIMESTAMP() WHERE task_id=$taskId AND status='open'");

echo "DONE\n";
