<?php
/** Usage: php _tmp_phase21b_password.php set|restore */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$action = $argv[1] ?? 'set';
$TEST_PASS = 'Phase21bTestPass!66';
$UID = 66;
$stateFile = sys_get_temp_dir() . '/crm21b_pass_state.json';

$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

if ($action === 'set') {
    $row = $db->query("SELECT email, password FROM rise_users WHERE id=$UID")->fetch_assoc();
    if (!$row) { fwrite(STDERR, "NO_USER\n"); exit(1); }
    file_put_contents($stateFile, json_encode(['password' => $row['password'], 'email' => $row['email']]));
    $hash = password_hash($TEST_PASS, PASSWORD_BCRYPT);
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($hash) . "' WHERE id=$UID");
    echo $row['email'] . "\n";
    exit(0);
}

if ($action === 'restore') {
    if (!is_file($stateFile)) { echo "NO_STATE\n"; exit(1); }
    $st = json_decode(file_get_contents($stateFile), true);
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($st['password']) . "' WHERE id=$UID");
    @unlink($stateFile);
    echo "restored\n";
    exit(0);
}

echo "usage: set|restore\n";
exit(1);
