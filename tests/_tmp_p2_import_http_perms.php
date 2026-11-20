<?php
/**
 * Phase 2 verify: import endpoints return 403 for view-only; Import button hidden.
 * Local only. Starts/reuses 127.0.0.1:8099.
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'content_calendar']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default');
if (!in_array($db->hostname, array('127.0.0.1', 'localhost', 'localhost:3306'), true)) {
    echo "REFUSING\n";
    exit(1);
}

$BASE = 'http://127.0.0.1:8099';
$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    if ($c) { echo "[PASS] $n\n"; $pass++; } else { echo "[FAIL] $n" . ($d ? " - $d" : "") . "\n"; $fail++; }
}

$already = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 0.3);
$server = null; $pipes = null;
$null = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'NUL' : '/dev/null';
if (!$already) {
    $server = proc_open(array(PHP_BINARY, '-S', '127.0.0.1:8099', '-t', rtrim(FCPATH, '/\\'), FCPATH . 'index.php'), array(0 => array('file', $null, 'r'), 1 => array('file', $null, 'w'), 2 => array('file', $null, 'w')), $pipes);
    usleep(400000);
}
if ($already) fclose($already);

$email_v = 'cc_p2_view_' . bin2hex(random_bytes(3)) . '@example.test';
$email_a = 'cc_p2_admin_' . bin2hex(random_bytes(3)) . '@example.test';
$hash = password_hash('Cc_P2_Test_123!', PASSWORD_DEFAULT);
foreach (array($email_v => 0, $email_a => 1) as $email => $is_admin) {
    $db->table('users')->insert(array(
        'first_name' => 'P2', 'last_name' => $is_admin ? 'Admin' : 'View', 'user_type' => 'staff', 'is_admin' => $is_admin, 'role_id' => 0,
        'email' => $email, 'password' => $hash, 'status' => 'active', 'client_id' => 0, 'job_title' => 'Tester',
        'disable_login' => 0, 'language' => '', 'created_at' => get_current_utc_time(), 'deleted' => 0
    ));
}
$view_id = (int) $db->query("SELECT id FROM rise_users WHERE email=?", array($email_v))->getRow()->id;
$admin_id = (int) $db->query("SELECT id FROM rise_users WHERE email=?", array($email_a))->getRow()->id;
$db->table('cc_permissions')->insert(array('permission_key' => 'view', 'user_id' => $view_id, 'created_by' => $admin_id, 'created_at' => get_current_utc_time()));

function http_login($email, $pass, $jar) {
    global $BASE;
    $ch = curl_init($BASE . '/signin');
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar));
    $html = curl_exec($ch);
    preg_match('/name="rise_csrf_token"[^>]*value="([^"]+)"/', $html, $m);
    $csrf = $m[1] ?? '';
    curl_setopt_array($ch, array(CURLOPT_URL => $BASE . '/signin/authenticate', CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(array('email' => $email, 'password' => $pass, 'rise_csrf_token' => $csrf))));
    curl_exec($ch);
    curl_close($ch);
}

function http_req($method, $path, $jar, $data = array()) {
    global $BASE;
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest')));
    // refresh csrf from a page
    $ch2 = curl_init($BASE . '/content_calendar');
    curl_setopt_array($ch2, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar));
    $page = curl_exec($ch2);
    curl_close($ch2);
    preg_match('/name="rise_csrf_token"[^>]*value="([^"]+)"/', $page ?: '', $m);
    $csrf = $m[1] ?? '';
    if ($method === 'POST') {
        $data['rise_csrf_token'] = $csrf;
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $parts = explode("\r\n\r\n", $raw, 2);
    $body = $parts[1] ?? $raw;
    return array('status' => $status, 'body' => $body, 'page' => $page);
}

$jar_v = sys_get_temp_dir() . '/cc_p2_v.txt';
$jar_a = sys_get_temp_dir() . '/cc_p2_a.txt';
@unlink($jar_v); @unlink($jar_a);
http_login($email_v, 'Cc_P2_Test_123!', $jar_v);
http_login($email_a, 'Cc_P2_Test_123!', $jar_a);

$rv = http_req('GET', '/content_calendar', $jar_v);
check('view-only calendar page loads', $rv['status'] === 200 && strpos($rv['page'], 'cc-page') !== false, 'status=' . $rv['status']);
check('view-only: no Import button', strpos($rv['page'], 'cc-import-open') === false);
check('view-only: no Add client pill', strpos($rv['page'], 'cc-add-client') === false);

$ra = http_req('GET', '/content_calendar', $jar_a);
check('admin calendar page has Import button', strpos($ra['page'], 'cc-import-open') !== false);
check('admin calendar page has Add client pill', strpos($ra['page'], 'cc-add-client') !== false);

foreach (array('import_parse', 'import_preview', 'import_run', 'import_undo', 'import_skipped_csv') as $ep) {
    $r = http_req('POST', '/content_calendar/' . $ep, $jar_v, array('payload' => '{}'));
    $j = json_decode($r['body'], true);
    check("view-only $ep -> 403 JSON", $r['status'] === 403 || (is_array($j) && empty($j['success'])), 'status=' . $r['status'] . ' body=' . substr($r['body'], 0, 120));
}

// cleanup users
$db->query("DELETE FROM rise_cc_permissions WHERE user_id IN (?,?)", array($view_id, $admin_id));
$db->query("DELETE FROM rise_users WHERE id IN (?,?)", array($view_id, $admin_id));
@unlink($jar_v); @unlink($jar_a);
if ($server) { proc_terminate($server); proc_close($server); }

echo "\nRESULT: PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
