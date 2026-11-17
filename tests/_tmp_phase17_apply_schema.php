<?php
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }

$dbName = $argv[1] ?? 'sunrisecrm_scratch';
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $dbName);
if ($db->connect_error) { fwrite(STDERR, $db->connect_error . "\n"); exit(1); }
$db->query("SET SESSION sql_mode=''");
$sql = file_get_contents(__DIR__ . '/../database/phase17_schema.sql');
// strip comments lines starting with --
$lines = preg_split("/\r\n|\n|\r/", $sql);
$buf = '';
foreach ($lines as $line) {
    $trim = ltrim($line);
    if ($trim === '' || str_starts_with($trim, '--')) continue;
    $buf .= $line . "\n";
}
if (!$db->multi_query($buf)) {
    fwrite(STDERR, "multi_query fail: " . $db->error . "\n");
    exit(1);
}
do {
    if ($res = $db->store_result()) { $res->free(); }
    if ($db->errno) {
        fwrite(STDERR, "err: " . $db->error . "\n");
    }
} while ($db->more_results() && $db->next_result());

echo "Applied phase17 schema to $dbName\n";
foreach (['review_changes_count','first_review_submitted_at','timer_lock_json','points_rules_locked_at'] as $c) {
    $r = $db->query("SHOW COLUMNS FROM rise_tasks LIKE '$c'");
    echo "$c: " . ($r && $r->num_rows ? "OK" : "MISSING") . "\n";
}
$r = $db->query("SELECT setting_name, setting_value FROM rise_settings WHERE setting_name LIKE 'task_points_review%' OR setting_name LIKE 'task_review_changes%'");
while ($row = $r->fetch_assoc()) echo $row['setting_name'] . "=" . $row['setting_value'] . "\n";
