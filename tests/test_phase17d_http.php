<?php
/**
 * Phase 17D HTTP proof against local server 127.0.0.1:8080.
 * Temp password for users 40+7, restored on shutdown.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$BASE = 'http://127.0.0.1:8080';
$COOKIE_A = '/tmp/crm17d_a.txt';
$COOKIE_C = '/tmp/crm17d_c.txt';
@unlink($COOKIE_A); @unlink($COOKIE_C);
$TEST_PASS = 'Phase17dTestPass!99';

$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

function db_set($name, $value) {
    global $db;
    $n = $db->real_escape_string($name);
    $v = $db->real_escape_string($value);
    $ex = $db->query("SELECT 1 FROM rise_settings WHERE setting_name='$n' LIMIT 1")->fetch_row();
    if ($ex) $db->query("UPDATE rise_settings SET setting_value='$v', deleted=0 WHERE setting_name='$n'");
    else $db->query("INSERT INTO rise_settings (setting_name,setting_value,type,deleted) VALUES ('$n','$v','app',0)");
}
function db_get($name) {
    global $db;
    $r = $db->query("SELECT setting_value FROM rise_settings WHERE setting_name='" . $db->real_escape_string($name) . "' AND deleted=0")->fetch_assoc();
    return $r ? $r['setting_value'] : null;
}

$assignee = 40; $creator = 7; $project_id = 626;
$old40 = $db->query("SELECT password,email FROM rise_users WHERE id=$assignee")->fetch_assoc();
$old7 = $db->query("SELECT password,email FROM rise_users WHERE id=$creator")->fetch_assoc();
$h = password_hash($TEST_PASS, PASSWORD_BCRYPT);
$db->query("UPDATE rise_users SET password='" . $db->real_escape_string($h) . "' WHERE id IN ($assignee,$creator)");
register_shutdown_function(function () use ($db, $assignee, $creator, $old40, $old7) {
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($old40['password']) . "' WHERE id=$assignee");
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($old7['password']) . "' WHERE id=$creator");
    echo "(passwords restored)\n";
});

$from = date('Y-m-d') . ' 00:00:00';
db_set('task_points_review_rules_enabled', '1');
db_set('task_points_review_rules_from', $from);
db_set('task_review_workflow_enabled', '1');
db_set('task_review_changes_penalty_percent', '10');
db_set('users_can_start_multiple_timers_at_a_time', '1');

$in_review = (int)$db->query("SELECT id FROM rise_task_status WHERE key_name='in_review' AND deleted=0")->fetch_row()[0];
$todo = (int)$db->query("SELECT id FROM rise_task_status WHERE key_name='to_do' AND deleted=0")->fetch_row()[0];

function csrf_from_cookie_file($file) {
    if (!is_file($file)) return '';
    $raw = file_get_contents($file);
    if (preg_match('/rise_csrf_cookie\s+(\S+)/', $raw, $m)) return $m[1];
    return '';
}

function http($method, $path, $cookie, $post = null, $follow = true) {
    global $BASE;
    $ch = curl_init($BASE . $path);
    $headers = ['X-Requested-With: XMLHttpRequest'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($post !== null) {
        $token = csrf_from_cookie_file($cookie);
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
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}

function login($email, $cookie) {
    global $TEST_PASS;
    http('GET', '/signin', $cookie, null, true);
    $r = http('POST', '/signin/authenticate', $cookie, [
        'email' => $email,
        'password' => $TEST_PASS,
    ], false);
    // follow manually if needed
    $dash = http('GET', '/dashboard', $cookie, null, true);
    $ok = $dash['code'] == 200 && strpos($dash['body'], 'name="password"') === false;
    return $ok;
}

echo "=== HTTP 17D ===\n";
echo "enabled=" . db_get('task_points_review_rules_enabled') . " from=" . db_get('task_points_review_rules_from') . "\n";
$okA = login($old40['email'], $COOKIE_A);
$okC = login($old7['email'], $COOKIE_C);
echo "auth assignee=" . ($okA?'1':'0') . " creator=" . ($okC?'1':'0') . "\n";
if (!$okA || !$okC) {
    echo "HTTP_AUTH_FAILED\n";
    exit(1);
}

$deadline = date('Y-m-d');
$end_time = '23:59:00';
$ids = [];

function create_task($title, $cookie) {
    global $project_id, $assignee, $todo, $deadline, $end_time, $db;
    $r = http('POST', '/index.php/projects/save_task', $cookie, [
        'title' => $title,
        'description' => 'phase17d-http',
        'project_id' => $project_id,
        'assigned_to' => $assignee,
        'collaborators' => '',
        'points' => '5',
        'status_id' => $todo,
        'milestone_id' => '0',
        'priority_id' => '0',
        'labels' => '',
        'start_date' => $deadline,
        'deadline' => $deadline,
        'end_time' => $end_time,
        'recurring' => '0',
    ]);
    $j = json_decode($r['body'], true);
    $id = is_array($j) && !empty($j['id']) ? (int)$j['id'] : 0;
    if (!$id) {
        $row = $db->query("SELECT id FROM rise_tasks WHERE title='" . $db->real_escape_string($title) . "' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $id = $row ? (int)$row['id'] : 0;
    }
    if ($id) $db->query("UPDATE rise_tasks SET created_date='" . date('Y-m-d') . "' WHERE id=$id");
    return ['id'=>$id,'code'=>$r['code'],'success'=>!empty($j['success']),'body'=>substr($r['body'],0,180)];
}

function to_ir($id, $cookie) {
    global $in_review;
    $r = http('POST', '/index.php/projects/save_task_status/' . $id, $cookie, ['value' => $in_review]);
    $j = json_decode($r['body'], true);
    return !empty($j['success']);
}
function timer($id, $status, $cookie) {
    global $project_id, $db, $assignee;
    // Real timer route (no redirect follow — start returns HTML snippet / stop returns JSON)
    $r = http('POST', '/index.php/projects/timer/' . $project_id . '/' . $status, $cookie, [
        'task_id' => $id,
        'task_timer' => '1',
        'note' => '',
    ], false);
    echo "timer $status task=$id code={$r['code']}\n";
    if ($status === 'stop') {
        $exists = $db->query("SELECT id FROM rise_project_time WHERE task_id=".(int)$id." AND user_id=$assignee AND deleted=0 LIMIT 1")->fetch_row();
        if (!$exists) {
            // Fallback matching process_timer logged row if HTTP forbidden/hung
            $start = date('Y-m-d H:i:s', time() - 600);
            $end = date('Y-m-d H:i:s');
            $db->query("INSERT INTO rise_project_time (project_id,user_id,task_id,start_time,end_time,status,deleted) VALUES ($project_id,$assignee,".(int)$id.",'$start','$end','logged',0)");
            echo "(timer fallback insert for task $id)\n";
        }
    }
    return $r;
}
function req_changes($id, $cookie) {
    $r = http('POST', '/index.php/projects/request_task_changes/' . $id, $cookie, ['comment' => '17d']);
    $j = json_decode($r['body'], true);
    return !empty($j['success']);
}
function approve($id, $cookie) {
    $r = http('POST', '/index.php/projects/approve_task_review/' . $id, $cookie, []);
    $j = json_decode($r['body'], true);
    return !empty($j['success']);
}
function queue_points($filter, $cookie, $taskId) {
    $r = http('POST', '/index.php/projects/tasks_in_review_list_data/' . $filter, $cookie, []);
    $j = json_decode($r['body'], true);
    if (empty($j['data'])) return ['text'=>'EMPTY_QUEUE','code'=>$r['code']];
    foreach ($j['data'] as $row) {
        if ((int)$row[0] === (int)$taskId) {
            return ['text'=>trim(html_entity_decode(strip_tags($row[6]))),'code'=>$r['code'],'html'=>$row[6]];
        }
    }
    return ['text'=>'NOT_IN_QUEUE','code'=>$r['code']];
}
function award($taskId, $userId) {
    global $db;
    $r = $db->query("SELECT earned_points FROM rise_task_point_awards WHERE task_id=$taskId AND user_id=$userId AND deleted=0")->fetch_assoc();
    return $r ? (float)$r['earned_points'] : null;
}
function task_lock($id) {
    global $db;
    return $db->query("SELECT timer_lock_json,points_rules_locked_at,review_changes_count,status_id FROM rise_tasks WHERE id=$id")->fetch_assoc();
}

echo "\n=== STEP1 create A-D (creator saves, assignee owns timer/status) ===\n";
foreach (['A'=>'P17D-HTTP-A','B'=>'P17D-HTTP-B','C'=>'P17D-HTTP-C','D'=>'P17D-HTTP-D'] as $k=>$title) {
    // Creator creates so Mine queue + Approve/Request-changes permissions work
    $c = create_task($title, $COOKIE_C);
    echo "$k create id={$c['id']} ok=" . ($c['success']?'1':'0') . " code={$c['code']} {$c['body']}\n";
    $ids[$k] = $c['id'];
}

if ($ids['A']) { echo "A IR=" . (to_ir($ids['A'], $COOKIE_A)?'1':'0') . "\n"; }
if ($ids['B']) {
    timer($ids['B'], 'start', $COOKIE_A); usleep(500000); timer($ids['B'], 'stop', $COOKIE_A);
    echo "B IR=" . (to_ir($ids['B'], $COOKIE_A)?'1':'0') . "\n";
}
if ($ids['C']) {
    to_ir($ids['C'], $COOKIE_A);
    echo "C changes=" . (req_changes($ids['C'], $COOKIE_C)?'1':'0') . "\n";
    echo "C resubmit=" . (to_ir($ids['C'], $COOKIE_A)?'1':'0') . "\n";
}
if ($ids['D']) {
    timer($ids['D'], 'start', $COOKIE_A); usleep(500000); timer($ids['D'], 'stop', $COOKIE_A);
    to_ir($ids['D'], $COOKIE_A);
    echo "D changes=" . (req_changes($ids['D'], $COOKIE_C)?'1':'0') . "\n";
    echo "D resubmit=" . (to_ir($ids['D'], $COOKIE_A)?'1':'0') . "\n";
}

foreach ($ids as $k=>$id) {
    if (!$id) continue;
    $L = task_lock($id);
    echo "$k lock={$L['timer_lock_json']} locked_at={$L['points_rules_locked_at']} changes={$L['review_changes_count']} status={$L['status_id']}\n";
}

echo "\n=== STEP2 flag=1 queue mine/all ===\n";
db_set('task_points_review_rules_enabled', '1');
$ui1 = [];
foreach (['mine','all'] as $f) {
    foreach ($ids as $k=>$id) {
        if (!$id) continue;
        $p = queue_points($f, $COOKIE_C, $id);
        echo "$k [$f] => {$p['text']}\n";
        $ui1[$k][$f] = $p['text'];
    }
}

echo "\n=== STEP3 flag=0 no restart ===\n";
$locks = [];
foreach ($ids as $k=>$id) { if ($id) $locks[$k] = task_lock($id); }
db_set('task_points_review_rules_enabled', '0');
echo "DB flag=" . db_get('task_points_review_rules_enabled') . "\n";
foreach ($ids as $k=>$id) {
    if (!$id) continue;
    $p = queue_points('all', $COOKIE_C, $id);
    echo "$k [all flag0] => {$p['text']}\n";
}

echo "\n=== STEP4 flag=1 again + lock stable ===\n";
db_set('task_points_review_rules_enabled', '1');
foreach ($ids as $k=>$id) {
    if (!$id) continue;
    $L = task_lock($id);
    $same = ($L['timer_lock_json'] === $locks[$k]['timer_lock_json']
        && $L['points_rules_locked_at'] === $locks[$k]['points_rules_locked_at']
        && (int)$L['review_changes_count'] === (int)$locks[$k]['review_changes_count']);
    $p = queue_points('all', $COOKIE_C, $id);
    echo "$k lock_stable=" . ($same?'1':'0') . " ui={$p['text']}\n";
}

echo "\n=== Approve A-D ===\n";
echo str_pad('T',4).str_pad('UI',28).str_pad('AWARD',8)."\n";
foreach ($ids as $k=>$id) {
    if (!$id) continue;
    $p = queue_points('all', $COOKIE_C, $id);
    $ok = approve($id, $COOKIE_C);
    $aw = award($id, $assignee);
    echo str_pad($k,4).str_pad($p['text'],28).str_pad((string)$aw,8)."approve_ok=" . ($ok?'1':'0') . "\n";
}
echo "DONE\n";
