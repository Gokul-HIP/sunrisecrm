<?php
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname'] ?? '127.0.0.1';
$port = isset($cfg['port']) ? (int)$cfg['port'] : 3306;
if (strpos($host, ':') !== false) {
    [$host, $port] = explode(':', $host, 2);
}
$m = new mysqli($host, $cfg['username'], $cfg['password'], '', (int)$port);
foreach (['sunrisecrmnew', 'sunrisecrm_scratch'] as $d) {
    $m->select_db($d);
    $v = $m->query('SELECT VERSION()')->fetch_row()[0];
    $c = $m->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$d' AND TABLE_NAME='rise_tasks' AND COLUMN_NAME IN ('review_changes_count','first_review_submitted_at','timer_lock_json','points_rules_locked_at')")->fetch_row()[0];
    echo "$d VERSION=$v phase17_cols=$c\n";
}
echo "production_mysql_8.0.46=NOT_RUN (no live)\n";
