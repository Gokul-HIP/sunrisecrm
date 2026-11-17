<?php
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);
echo "DB={$cfg['database']} VERSION=" . $db->query("SELECT VERSION()")->fetch_row()[0] . "\n";

foreach (['task_points_review_rules_enabled','task_points_review_rules_from'] as $n) {
    $r = $db->query("SELECT setting_value FROM rise_settings WHERE setting_name='$n' AND deleted=0 LIMIT 1");
    $row = $r ? $r->fetch_assoc() : null;
    echo "$n=" . var_export($row['setting_value'] ?? null, true) . "\n";
}

echo "\n-- exact ids --\n";
$r = $db->query("SELECT id,title,status_id,created_date,points,assigned_to,submitted_for_review_at,first_review_submitted_at,review_changes_count,timer_lock_json,points_rules_locked_at,deadline,end_time FROM rise_tasks WHERE id IN (25876,25877)");
while ($row = $r->fetch_assoc()) { print_r($row); }

echo "\n-- max id / recent in_review today --\n";
$r = $db->query("SELECT MAX(id) m FROM rise_tasks"); echo "max_id=" . $r->fetch_row()[0] . "\n";
$r = $db->query("SELECT id,title,status_id,created_date,points,assigned_to,submitted_for_review_at,first_review_submitted_at,timer_lock_json,points_rules_locked_at FROM rise_tasks WHERE deleted=0 AND created_date>='2026-11-16' AND status_id=10 ORDER BY id DESC LIMIT 20");
while ($row = $r->fetch_assoc()) {
    echo "id={$row['id']} title={$row['title']} points={$row['points']} assignee={$row['assigned_to']} submitted={$row['submitted_for_review_at']} first={$row['first_review_submitted_at']} lock={$row['timer_lock_json']} locked_at={$row['points_rules_locked_at']}\n";
}

echo "\n-- titles matching / like test --\n";
$r = $db->query("SELECT id,title,status_id,created_date FROM rise_tasks WHERE deleted=0 AND id>=25870 ORDER BY id DESC LIMIT 30");
while ($row = $r->fetch_assoc()) {
    echo "id={$row['id']} status={$row['status_id']} created={$row['created_date']} title={$row['title']}\n";
}
