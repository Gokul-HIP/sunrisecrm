<?php
/**
 * Temporarily set/restore password for browser login (user 66). Local only.
 * Usage: php _tmp_phase22_password.php set|restore
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$action = isset($argv[1]) ? $argv[1] : 'set';
$TEST_PASS = 'Phase22TestPass!66';
$backup = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phase22_pw_backup.json';

$raw = file_get_contents(dirname(__DIR__) . '/app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $r) {
    $cfg[$r[1]] = $r[2];
}
$port = isset($cfg['port']) && $cfg['port'] !== '' ? (int) $cfg['port'] : 3306;
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database'], $port);
if ($db->connect_error) {
    fwrite(STDERR, "DB connect failed\n");
    exit(1);
}

$row = $db->query("SELECT id, email, password FROM rise_users WHERE id=66")->fetch_assoc();
if (!$row) {
    fwrite(STDERR, "user 66 missing\n");
    exit(1);
}

if ($action === 'set') {
    file_put_contents($backup, json_encode($row));
    $hash = password_hash($TEST_PASS, PASSWORD_BCRYPT);
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($hash) . "' WHERE id=66");
    echo $row['email'] . "\n";
} else if ($action === 'restore') {
    if (!is_file($backup)) {
        fwrite(STDERR, "no backup\n");
        exit(1);
    }
    $old = json_decode(file_get_contents($backup), true);
    $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($old['password']) . "' WHERE id=66");
    @unlink($backup);
    echo "restored\n";
} else {
    fwrite(STDERR, "use set|restore\n");
    exit(1);
}
