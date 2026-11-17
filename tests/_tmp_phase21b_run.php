<?php
/**
 * Phase 21B: set temp password for admin 66, run Playwright, restore password.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$BASE = 'http://localhost:8080';
$TEST_PASS = 'Phase21bTestPass!66';
$UID = 66;

$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);
$row = $db->query("SELECT email, password FROM rise_users WHERE id=$UID")->fetch_assoc();
if (!$row) { echo "NO_USER\n"; exit(1); }
$oldPass = $row['password'];
$email = $row['email'];
$hash = password_hash($TEST_PASS, PASSWORD_BCRYPT);
$db->query("UPDATE rise_users SET password='" . $db->real_escape_string($hash) . "' WHERE id=$UID");
echo "temp_password_set user=$UID email=$email\n";

$restored = false;
$restore = function () use ($db, $UID, $oldPass, &$restored) {
    if ($restored) return;
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($oldPass) . "' WHERE id=$UID");
    $restored = true;
    echo "(password restored)\n";
};
register_shutdown_function($restore);

// Also probe API with curl after login
$cookie = sys_get_temp_dir() . '/crm21b_cookie.txt';
@unlink($cookie);
function http($method, $path, $cookie, $post = null) {
    global $BASE;
    $ch = curl_init($BASE . $path);
    $headers = ['X-Requested-With: XMLHttpRequest'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
    ]);
    if ($post !== null) {
        $raw = @file_get_contents($cookie);
        $token = '';
        if ($raw && preg_match('/rise_csrf_cookie\s+(\S+)/', $raw, $m)) $token = $m[1];
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
    return ['code' => $code, 'body' => substr($raw, $hs)];
}

http('GET', '/index.php/signin', $cookie);
$auth = http('POST', '/index.php/signin/authenticate', $cookie, [
    'email' => $email,
    'password' => $TEST_PASS,
]);
$dash = http('GET', '/index.php/dashboard', $cookie);
$logged = $dash['code'] == 200 && strpos($dash['body'], 'name="password"') === false;
echo "curl_login=" . ($logged ? '1' : '0') . " dash_code={$dash['code']}\n";

$css = http('GET', '/assets/css/timesheet-grouped.css', $cookie);
$js = http('GET', '/assets/js/timesheet-grouped.js', $cookie);
echo "asset_css={$css['code']} asset_js={$js['code']}\n";

$page = http('GET', '/index.php/projects/all_timesheets', $cookie);
echo "page_code={$page['code']} has_tg_wrap=" . (strpos($page['body'], 'timesheet-grouped-root') !== false ? '1' : '0') . "\n";
echo "page_has_setDatePicker_jquery_bug=" . (strpos($page['body'], "setDatePicker(\$(this))") !== false ? '1' : '0') . "\n";
echo "page_has_selector_datepicker=" . (strpos($page['body'], "setDatePicker(\"[name='tg_start_date']") !== false || strpos($page['body'], 'setDatePicker("[name=\'tg_start_date\']') !== false || strpos($page['body'], "setDatePicker(\"[name='tg_start_date'], [name='tg_end_date']\")") !== false ? '1' : '0') . "\n";

$from = date('Y-m-d', strtotime('-30 days'));
$to = date('Y-m-d');
$api = http('POST', '/index.php/projects/timesheet_grouped_projects', $cookie, [
    'start_date' => $from,
    'end_date' => $to,
]);
echo "api_code={$api['code']} api_body_start=" . substr($api['body'], 0, 200) . "\n";
$json = json_decode($api['body'], true);
echo "api_success=" . (!empty($json['success']) ? '1' : '0') . " api_rows=" . (isset($json['data']) ? count($json['data']) : -1) . "\n";

// Run Playwright via Windows node if available
$outDir = __DIR__ . '/../writable/phase21b_screenshots';
if (!is_dir($outDir)) mkdir($outDir, 0777, true);
file_put_contents($outDir . '/api_probe.json', json_encode([
    'api_code' => $api['code'],
    'api' => $json,
    'css' => $css['code'],
    'js' => $js['code'],
], JSON_PRETTY_PRINT));

$envEmail = escapeshellarg($email);
$envPass = escapeshellarg($TEST_PASS);
$envOut = escapeshellarg($outDir);
$script = __DIR__ . '/_tmp_phase21b_browser.mjs';

// Prefer Windows node + existing playwright install
$winShot = 'C:\\Users\\gokul\\AppData\\Local\\Temp\\phase21shot';
$cmd = "cd /mnt/c/Users/gokul/AppData/Local/Temp/phase21shot && CRM_BASE='$BASE' CRM_EMAIL=$email CRM_PASS='$TEST_PASS' CRM_OUT='/home/gokul/projects/crm_sunrise/writable/phase21b_screenshots' node /home/gokul/projects/crm_sunrise/tests/_tmp_phase21b_browser.mjs";
// Windows path via powershell
$ps = 'Set-Location "C:\\Users\\gokul\\AppData\\Local\\Temp\\phase21shot"; '
    . '$env:CRM_BASE="http://localhost:8080"; '
    . '$env:CRM_EMAIL=' . escapeshellarg($email) . '; '
    . '$env:CRM_PASS=' . escapeshellarg($TEST_PASS) . '; '
    . '$env:CRM_OUT="\\\\wsl.localhost\\Ubuntu\\home\\gokul\\projects\\crm_sunrise\\writable\\phase21b_screenshots"; '
    . 'Copy-Item "\\\\wsl.localhost\\Ubuntu\\home\\gokul\\projects\\crm_sunrise\\tests\\_tmp_phase21b_browser.mjs" ".\\_tmp_phase21b_browser.mjs" -Force; '
    . 'node .\\_tmp_phase21b_browser.mjs';

echo "running_playwright...\n";
passthru('powershell.exe -NoProfile -Command ' . escapeshellarg($ps), $code);
echo "playwright_exit=$code\n";

$restore();
exit($code ? 1 : 0);
