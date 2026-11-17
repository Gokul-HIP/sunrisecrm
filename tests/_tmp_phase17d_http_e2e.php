<?php
/**
 * Phase 17D end-to-end over HTTP against local php -S (127.0.0.1:8080).
 * Temporarily sets known passwords for users 40 (assignee) and 7 (creator), restores after.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$BASE = 'http://127.0.0.1:8080';
$COOKIE_A = '/tmp/crm17d_assignee.txt';
$COOKIE_C = '/tmp/crm17d_creator.txt';
@unlink($COOKIE_A);
@unlink($COOKIE_C);

$TEST_PASS = 'Phase17dTestPass!99';

// --- DB helpers (mysqli, no CI) ---
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$mysqli = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);
if ($mysqli->connect_error) {
    fwrite(STDERR, "DB connect failed\n");
    exit(1);
}

function db_setting($name) {
    global $mysqli;
    $r = $mysqli->query("SELECT setting_value FROM rise_settings WHERE setting_name='" . $mysqli->real_escape_string($name) . "' AND deleted=0 LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    return $row ? $row['setting_value'] : null;
}
function set_setting($name, $value) {
    global $mysqli;
    $n = $mysqli->real_escape_string($name);
    $v = $mysqli->real_escape_string($value);
    $exists = $mysqli->query("SELECT setting_name FROM rise_settings WHERE setting_name='$n' LIMIT 1")->fetch_assoc();
    if ($exists) {
        $mysqli->query("UPDATE rise_settings SET setting_value='$v', deleted=0 WHERE setting_name='$n'");
    } else {
        $mysqli->query("INSERT INTO rise_settings (setting_name, setting_value, type, deleted) VALUES ('$n', '$v', 'app', 0)");
    }
}
function get_user_hash($id) {
    global $mysqli;
    $r = $mysqli->query("SELECT password FROM rise_users WHERE id=" . (int)$id)->fetch_assoc();
    return $r ? $r['password'] : null;
}
function set_user_hash($id, $hash) {
    global $mysqli;
    $h = $mysqli->real_escape_string($hash);
    $mysqli->query("UPDATE rise_users SET password='$h' WHERE id=" . (int)$id);
}

$assignee_id = 40;
$creator_id = 7;
$admin_id = 66;
$project_id = 626;

$old_hash_40 = get_user_hash($assignee_id);
$old_hash_7 = get_user_hash($creator_id);
$new_hash = password_hash($TEST_PASS, PASSWORD_BCRYPT);
set_user_hash($assignee_id, $new_hash);
set_user_hash($creator_id, $new_hash);

register_shutdown_function(function () use ($assignee_id, $creator_id, $old_hash_40, $old_hash_7) {
    if ($old_hash_40) set_user_hash($assignee_id, $old_hash_40);
    if ($old_hash_7) set_user_hash($creator_id, $old_hash_7);
    echo "\n(passwords restored)\n";
});

// Ensure rules on, from today
$from = date('Y-m-d') . ' 00:00:00';
set_setting('task_points_review_rules_enabled', '1');
set_setting('task_points_review_rules_from', $from);
set_setting('task_review_changes_penalty_percent', '10');
set_setting('task_review_workflow_enabled', '1');
set_setting('task_points_collab_split_from', '2020-01-01 00:00:00');
set_setting('users_can_start_multiple_timers_at_a_time', '1');

echo "=== STEP 0: settings + server ===\n";
echo "enabled=" . db_setting('task_points_review_rules_enabled') . " from=" . db_setting('task_points_review_rules_from') . "\n";
echo "BASE=$BASE\n";

function http($method, $path, $cookieFile, $post = null, $follow = true) {
    global $BASE;
    $ch = curl_init($BASE . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($follow) {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    }
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($post) ? http_build_query($post) : $post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded', 'X-Requested-With: XMLHttpRequest']);
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    return compact('code', 'headers', 'body', 'err');
}

function login($email, $cookieFile) {
    global $TEST_PASS;
    // warm session
    http('GET', '/signin', $cookieFile);
    $r = http('POST', '/signin/authenticate', $cookieFile, [
        'email' => $email,
        'password' => $TEST_PASS,
    ], true);
    $ok = ($r['code'] >= 200 && $r['code'] < 400) && (strpos($r['body'], 'Sign in') === false || strpos($r['headers'], 'dashboard') !== false || strpos($r['body'], 'page-content') !== false || strpos($r['body'], 'Dashboard') !== false || strpos($r['headers'], 'Location:') !== false);
    // Better check: hit a protected page
    $dash = http('GET', '/dashboard', $cookieFile);
    $authed = $dash['code'] == 200 && strpos($dash['body'], 'signin') === false && strpos($dash['body'], 'name="password"') === false;
    return ['login_code' => $r['code'], 'authed' => $authed, 'dash_code' => $dash['code'], 'snippet' => substr(strip_tags($dash['body']), 0, 120)];
}

$assignee_email = $mysqli->query("SELECT email FROM rise_users WHERE id=$assignee_id")->fetch_assoc()['email'];
$creator_email = $mysqli->query("SELECT email FROM rise_users WHERE id=$creator_id")->fetch_assoc()['email'];

echo "\n=== AUTH ===\n";
$la = login($assignee_email, $COOKIE_A);
echo "assignee login authed=" . ($la['authed'] ? '1' : '0') . " dash={$la['dash_code']} login_code={$la['login_code']}\n";
$lc = login($creator_email, $COOKIE_C);
echo "creator login authed=" . ($lc['authed'] ? '1' : '0') . " dash={$lc['dash_code']} login_code={$lc['login_code']}\n";

if (!$la['authed'] || !$lc['authed']) {
    echo "HTTP_AUTH=FAILED — will mark HTTP unverified; dumping signin body snippet\n";
    $s = http('GET', '/signin', $COOKIE_A);
    echo substr($s['body'], 0, 300) . "\n";
}

// Status ids
$in_review = (int)$mysqli->query("SELECT id FROM rise_task_status WHERE key_name='in_review' AND deleted=0")->fetch_assoc()['id'];
$todo = (int)$mysqli->query("SELECT id FROM rise_task_status WHERE key_name='to_do' AND deleted=0")->fetch_assoc()['id'];
$done = (int)$mysqli->query("SELECT id FROM rise_task_status WHERE key_name='done' AND deleted=0")->fetch_assoc()['id'];
$changes = (int)$mysqli->query("SELECT id FROM rise_task_status WHERE (key_name='changes' OR title='Changes') AND deleted=0 LIMIT 1")->fetch_assoc()['id'];
echo "todo=$todo in_review=$in_review changes=$changes done=$done\n";

$deadline = date('Y-m-d');
$end_time = '23:59:00';
$created = date('Y-m-d');

function create_task_http($title, $cookieFile) {
    global $project_id, $assignee_id, $todo, $deadline, $end_time, $mysqli;
    $r = http('POST', '/index.php/projects/save_task', $cookieFile, [
        'title' => $title,
        'description' => 'phase17d',
        'project_id' => $project_id,
        'assigned_to' => $assignee_id,
        'collaborators' => '',
        'points' => 5,
        'status_id' => $todo,
        'milestone_id' => 0,
        'priority_id' => 0,
        'labels' => '',
        'start_date' => '',
        'deadline' => $deadline,
        'end_time' => $end_time,
        'recurring' => 0,
    ]);
    $json = json_decode($r['body'], true);
    $id = 0;
    if (is_array($json) && !empty($json['id'])) {
        $id = (int)$json['id'];
    } else {
        // fallback: latest task by title
        $row = $mysqli->query("SELECT id FROM rise_tasks WHERE title='" . $mysqli->real_escape_string($title) . "' ORDER BY id DESC LIMIT 1")->fetch_assoc();
        $id = $row ? (int)$row['id'] : 0;
    }
    return ['id' => $id, 'code' => $r['code'], 'body' => substr($r['body'], 0, 200), 'success' => !empty($json['success'])];
}

function to_in_review_http($taskId, $cookieFile) {
    global $in_review;
    // save_task_status
    $r = http('POST', '/index.php/projects/save_task_status/' . $taskId, $cookieFile, [
        'value' => $in_review,
    ]);
    $json = json_decode($r['body'], true);
    return ['code' => $r['code'], 'success' => !empty($json['success']), 'body' => substr($r['body'], 0, 200)];
}

function timer_http($taskId, $status, $cookieFile) {
    global $project_id;
    $r = http('POST', '/index.php/projects/timer/' . $project_id . '/' . $status, $cookieFile, [
        'task_id' => $taskId,
        'task_timer' => 1,
        'note' => '',
    ]);
    return ['code' => $r['code'], 'body' => substr($r['body'], 0, 150)];
}

function request_changes_http($taskId, $cookieFile) {
    $r = http('POST', '/index.php/projects/request_task_changes/' . $taskId, $cookieFile, [
        'comment' => 'phase17d change request',
    ]);
    $json = json_decode($r['body'], true);
    return ['code' => $r['code'], 'success' => !empty($json['success']), 'body' => substr($r['body'], 0, 200)];
}

function approve_http($taskId, $cookieFile) {
    $r = http('POST', '/index.php/projects/approve_task_review/' . $taskId, $cookieFile, []);
    $json = json_decode($r['body'], true);
    return ['code' => $r['code'], 'success' => !empty($json['success']), 'body' => substr($r['body'], 0, 200)];
}

function queue_http($filter, $cookieFile) {
    $r = http('POST', '/index.php/projects/tasks_in_review_list_data/' . $filter, $cookieFile, []);
    $json = json_decode($r['body'], true);
    return ['code' => $r['code'], 'json' => $json, 'raw' => substr($r['body'], 0, 300)];
}

function find_queue_points($queueJson, $taskId) {
    if (empty($queueJson['data']) || !is_array($queueJson['data'])) return null;
    foreach ($queueJson['data'] as $row) {
        if ((int)$row[0] === (int)$taskId) {
            $html = $row[6] ?? '';
            $text = trim(html_entity_decode(strip_tags($html)));
            return ['html' => $html, 'text' => $text];
        }
    }
    return null;
}

function task_row($id) {
    global $mysqli;
    return $mysqli->query("SELECT id,points,status_id,created_date,deadline,end_time,submitted_for_review_at,first_review_submitted_at,timer_lock_json,points_rules_locked_at,review_changes_count FROM rise_tasks WHERE id=" . (int)$id)->fetch_assoc();
}

function award_row($taskId, $userId) {
    global $mysqli;
    $r = $mysqli->query("SELECT earned_points,original_points,reduction_reason FROM rise_task_point_awards WHERE task_id=" . (int)$taskId . " AND user_id=" . (int)$userId . " AND deleted=0 LIMIT 1");
    return $r ? $r->fetch_assoc() : null;
}

$HTTP_OK = $la['authed'] && $lc['authed'];
$ids = [];

echo "\n=== STEP 1: create A-D ===\n";
if ($HTTP_OK) {
    foreach (['A' => 'P17D-A no timer', 'B' => 'P17D-B with timer', 'C' => 'P17D-C no timer changes', 'D' => 'P17D-D timer one change'] as $key => $title) {
        $c = create_task_http($title, $COOKIE_A);
        echo "$key create id={$c['id']} success=" . ($c['success']?'1':'0') . " code={$c['code']} body={$c['body']}\n";
        $ids[$key] = $c['id'];
        // force created_date >= from
        if ($c['id']) {
            $mysqli->query("UPDATE rise_tasks SET created_date='" . $mysqli->real_escape_string($created) . "' WHERE id=" . (int)$c['id']);
        }
    }

    // A: no timer → In review
    if (!empty($ids['A'])) {
        $r = to_in_review_http($ids['A'], $COOKIE_A);
        echo "A to_in_review success=" . ($r['success']?'1':'0') . " {$r['body']}\n";
    }
    // B: timer start/stop → In review
    if (!empty($ids['B'])) {
        $s = timer_http($ids['B'], 'start', $COOKIE_A);
        echo "B timer start code={$s['code']} {$s['body']}\n";
        sleep(1);
        $s = timer_http($ids['B'], 'stop', $COOKIE_A);
        echo "B timer stop code={$s['code']} {$s['body']}\n";
        $r = to_in_review_http($ids['B'], $COOKIE_A);
        echo "B to_in_review success=" . ($r['success']?'1':'0') . " {$r['body']}\n";
    }
    // C: no timer → IR → changes → IR
    if (!empty($ids['C'])) {
        $r = to_in_review_http($ids['C'], $COOKIE_A);
        echo "C to_in_review success=" . ($r['success']?'1':'0') . "\n";
        $r = request_changes_http($ids['C'], $COOKIE_C);
        echo "C request_changes success=" . ($r['success']?'1':'0') . " {$r['body']}\n";
        $r = to_in_review_http($ids['C'], $COOKIE_A);
        echo "C resubmit success=" . ($r['success']?'1':'0') . "\n";
    }
    // D: timer → IR → changes → IR
    if (!empty($ids['D'])) {
        timer_http($ids['D'], 'start', $COOKIE_A);
        sleep(1);
        timer_http($ids['D'], 'stop', $COOKIE_A);
        to_in_review_http($ids['D'], $COOKIE_A);
        $r = request_changes_http($ids['D'], $COOKIE_C);
        echo "D request_changes success=" . ($r['success']?'1':'0') . "\n";
        $r = to_in_review_http($ids['D'], $COOKIE_A);
        echo "D resubmit success=" . ($r['success']?'1':'0') . "\n";
    }
} else {
    echo "SKIP HTTP create — auth failed\n";
}

echo "\nTask DB state after step 1:\n";
foreach ($ids as $k => $id) {
    if (!$id) continue;
    $row = task_row($id);
    echo "$k id=$id status={$row['status_id']} lock={$row['timer_lock_json']} locked_at={$row['points_rules_locked_at']} changes={$row['review_changes_count']} first={$row['first_review_submitted_at']}\n";
}

function print_queue_for($ids, $label) {
    global $COOKIE_C, $HTTP_OK;
    echo "\n=== $label ===\n";
    if (!$HTTP_OK) { echo "HTTP unverified\n"; return []; }
    $out = [];
    foreach (['mine', 'all'] as $filter) {
        $q = queue_http($filter, $COOKIE_C);
        echo "queue/$filter code={$q['code']} rows=" . (isset($q['json']['data']) ? count($q['json']['data']) : 0) . "\n";
        foreach ($ids as $k => $id) {
            if (!$id) continue;
            $p = find_queue_points($q['json'], $id);
            $text = $p ? $p['text'] : 'NOT_IN_QUEUE';
            echo "  $k($id) [$filter] => $text\n";
            $out[$k][$filter] = $text;
        }
    }
    return $out;
}

$q1 = print_queue_for($ids, 'STEP 2: flag=1 queue');

echo "\n=== STEP 3: flip flag to 0 (no server restart) ===\n";
set_setting('task_points_review_rules_enabled', '0');
echo "DB enabled now=" . db_setting('task_points_review_rules_enabled') . "\n";
// capture lock fields before
$locks_before = [];
foreach ($ids as $k => $id) {
    if (!$id) continue;
    $row = task_row($id);
    $locks_before[$k] = [
        'timer_lock_json' => $row['timer_lock_json'],
        'points_rules_locked_at' => $row['points_rules_locked_at'],
        'review_changes_count' => $row['review_changes_count'],
    ];
}
$q0 = print_queue_for($ids, 'STEP 3: flag=0 queue (immediate reload)');

echo "\n=== STEP 4: flip flag back to 1 ===\n";
set_setting('task_points_review_rules_enabled', '1');
echo "DB enabled now=" . db_setting('task_points_review_rules_enabled') . "\n";
$q1b = print_queue_for($ids, 'STEP 4: flag=1 again');
echo "Lock field stability:\n";
foreach ($ids as $k => $id) {
    if (!$id) continue;
    $row = task_row($id);
    $same = ($row['timer_lock_json'] === $locks_before[$k]['timer_lock_json']
        && $row['points_rules_locked_at'] === $locks_before[$k]['points_rules_locked_at']
        && (int)$row['review_changes_count'] === (int)$locks_before[$k]['review_changes_count']);
    echo "  $k unchanged=" . ($same ? '1' : '0') . " lock={$row['timer_lock_json']} locked_at={$row['points_rules_locked_at']} changes={$row['review_changes_count']}\n";
}

echo "\n=== Approve values (flag=1) for A-D that are still In review ===\n";
// Clone approach: for each, read projection via CI CLI helper script instead of destroying all —
// Actually approve one copy: use DB award after approve on dedicated clones if needed.
// Here we approve D last after recording UI; for A-D we compute expected from lock and also approve B only as sample?
// User wants UI and Approve for each. Approve moves to Done — do it and show awards.
$approve_vals = [];
foreach (['A','B','C','D'] as $k) {
    if (empty($ids[$k])) continue;
    // read UI again
    $q = queue_http('all', $COOKIE_C);
    $p = find_queue_points($q['json'], $ids[$k]);
    $ui = $p ? $p['text'] : 'n/a';
    $r = approve_http($ids[$k], $COOKIE_C);
    $aw = award_row($ids[$k], $assignee_id);
    $approve_vals[$k] = $aw ? $aw['earned_points'] : null;
    echo "$k UI=$ui approve_http_success=" . ($r['success']?'1':'0') . " award=" . var_export($approve_vals[$k], true) . " reason=" . ($aw['reduction_reason'] ?? '') . "\n";
}

echo "\nHTTP_AUTH=" . ($HTTP_OK ? 'OK' : 'FAILED') . "\n";
echo "IDS=" . json_encode($ids) . "\n";
echo "FROM=$from\n";
