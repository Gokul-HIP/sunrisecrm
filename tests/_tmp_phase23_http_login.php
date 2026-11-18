<?php
/**
 * Phase 23: HTTP my_tasks_list_data as users 39, 42, 66 for task 25906.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$BASE = 'http://127.0.0.1:8080';
$TEST_PASS = 'Phase23TestPass!99';
$USER_IDS = [39, 42, 66];

$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) {
    $cfg[$x[1]] = $x[2];
}
$host = $cfg['hostname'];
$port = isset($cfg['port']) && $cfg['port'] !== '' ? (int) $cfg['port'] : 3306;
if (strpos($host, ':') !== false) {
    [$host, $port] = explode(':', $host, 2);
    $port = (int) $port;
}
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], $port);

$old = [];
foreach ($USER_IDS as $uid) {
    $old[$uid] = $db->query("SELECT id,email,password FROM rise_users WHERE id=$uid")->fetch_assoc();
}
$h = password_hash($TEST_PASS, PASSWORD_BCRYPT);
foreach ($USER_IDS as $uid) {
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($h) . "' WHERE id=$uid");
}
register_shutdown_function(function () use ($db, $old) {
    foreach ($old as $uid => $row) {
        $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($row['password']) . "' WHERE id=$uid");
    }
    echo "passwords_restored\n";
});

function csrf_from_cookie_file($file) {
    if (!is_file($file)) {
        return '';
    }
    if (preg_match('/rise_csrf_cookie\s+(\S+)/', file_get_contents($file), $m)) {
        return $m[1];
    }
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
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($post !== null) {
        $token = csrf_from_cookie_file($cookie);
        if (is_array($post)) {
            if ($token !== '') {
                $post['rise_csrf_token'] = $token;
            }
            $post = http_build_query($post);
        }
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        if ($token !== '') {
            $headers[] = 'X-CSRF-TOKEN: ' . $token;
        }
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
    return http('POST', '/signin/authenticate', $cookie, [
        'email' => $email,
        'password' => $TEST_PASS,
    ], true);
}

function list_data($cookie, $extra = []) {
    $post = array_merge([
        'draw' => 1,
        'start' => 0,
        'length' => 500,
        'status_id' => [1, 2, 4, 10, 11],
        'order' => [['column' => 1, 'dir' => 'desc']],
    ], $extra);
    return http('POST', '/index.php/projects/my_tasks_list_data', $cookie, $post, false);
}

foreach ($USER_IDS as $uid) {
    $cookie = sys_get_temp_dir() . "/crm23_$uid.txt";
    @unlink($cookie);
    $lr = login($old[$uid]['email'], $cookie);
    $ok = (strpos($lr['headers'] . $lr['body'], 'signin') === false) || $lr['code'] == 200;
    echo "login user=$uid code={$lr['code']} body_has_dashboard=" . (strpos($lr['body'], 'dashboard') !== false || strpos($lr['headers'], 'dashboard') !== false ? 'Y' : 'N') . "\n";

    $extras = [[]];
    if ($uid === 66) {
        $extras[] = ['specific_user_id' => 39];
        $extras[] = ['specific_user_id' => 39, 'created_from' => '2026-11-18', 'created_to' => '2026-11-18'];
    }
    $extras[] = ['created_from' => '2026-11-18', 'created_to' => '2026-11-18'];

    foreach ($extras as $i => $ex) {
        $r = list_data($cookie, $ex);
        $j = json_decode($r['body'], true);
        $has = is_array($j) && !empty($j['data']) && (
            strpos(json_encode($j['data']), '25906') !== false
        );
        $label = $ex ? json_encode($ex) : 'default';
        echo "user=$uid filter=$label http={$r['code']} has25906=" . ($has ? 'Y' : 'N')
            . " todo=" . ($j['todo_count'] ?? '?')
            . " done=" . ($j['done_count'] ?? '?')
            . " records=" . ($j['recordsFiltered'] ?? '?')
            . " body0=" . substr(preg_replace('/\s+/', ' ', $r['body']), 0, 80)
            . "\n";
    }
}
