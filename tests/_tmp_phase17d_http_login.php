<?php
/** Probe HTTP login + CSRF against 127.0.0.1:8080 */
$BASE = 'http://127.0.0.1:8080';
$COOKIE = '/tmp/crm17d_http.txt';
@unlink($COOKIE);

$rawDb = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $rawDb, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

$uid = 40;
$email = $db->query("SELECT email,password FROM rise_users WHERE id=$uid")->fetch_assoc();
$old = $email['password'];
$pass = 'Phase17dTestPass!99';
$hash = password_hash($pass, PASSWORD_BCRYPT);
$db->query("UPDATE rise_users SET password='" . $db->real_escape_string($hash) . "' WHERE id=$uid");

function req($method, $url, $cookie, $post = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hs), 'body' => substr($raw, $hs)];
}

$r = req('GET', "$BASE/signin", $COOKIE);
preg_match('/rise_csrf_cookie=([^;]+)/', $r['headers'], $cm);
$token = isset($cm[1]) ? urldecode($cm[1]) : '';
if (preg_match('/name="rise_csrf_token"\s+value="([^"]+)"/', $r['body'], $tm)) {
    $token = $tm[1];
}
echo "csrf_token_len=" . strlen($token) . "\n";

$r = req('POST', "$BASE/signin/authenticate", $COOKIE, [
    'email' => $email['email'],
    'password' => $pass,
    'rise_csrf_token' => $token,
]);
echo "login_code={$r['code']}\n";
echo "login_loc=" . (preg_match('/Location:\s*(.+)/i', $r['headers'], $lm) ? trim($lm[1]) : '') . "\n";

// refresh csrf from cookie file
$cookieRaw = file_get_contents($COOKIE);
preg_match('/rise_csrf_cookie\s+(\S+)/', $cookieRaw, $cm2);
$token2 = isset($cm2[1]) ? $cm2[1] : $token;

$r = req('GET', "$BASE/dashboard", $COOKIE);
echo "dash_code={$r['code']} has_password_form=" . (strpos($r['body'], 'name="password"') !== false ? '1' : '0') . "\n";
echo "dash_loc=" . (preg_match('/Location:\s*(.+)/i', $r['headers'], $lm) ? trim($lm[1]) : '') . "\n";

// restore
$db->query("UPDATE rise_users SET password='" . $db->real_escape_string($old) . "' WHERE id=$uid");
echo "password_restored\n";
