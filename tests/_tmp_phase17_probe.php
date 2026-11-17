<?php
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database']);
if ($db->connect_error) { fwrite(STDERR, $db->connect_error); exit(1); }
echo "DB=" . $db->query("SELECT DATABASE()")->fetch_row()[0] . "\n";
echo "-- rise_tasks created* --\n";
$r = $db->query("SHOW COLUMNS FROM rise_tasks");
while ($row = $r->fetch_assoc()) {
    if (stripos($row['Field'], 'creat') !== false || stripos($row['Field'], 'review') !== false || stripos($row['Field'], 'point') !== false || stripos($row['Field'], 'status') !== false || stripos($row['Field'], 'timer') !== false) {
        echo $row['Field'] . "\t" . $row['Type'] . "\n";
    }
}
echo "-- rise_project_time --\n";
$r = $db->query("SHOW COLUMNS FROM rise_project_time");
while ($row = $r->fetch_assoc()) { echo $row['Field'] . "\t" . $row['Type'] . "\n"; }
echo "-- databases --\n";
$r = $db->query("SHOW DATABASES");
while ($row = $r->fetch_row()) echo $row[0] . "\n";
echo "-- sample statuses on project_time --\n";
$r = $db->query("SELECT status, COUNT(*) c, SUM(hours IS NOT NULL AND hours>0) hours_set, SUM(end_time IS NOT NULL AND end_time!='0000-00-00 00:00:00') has_end FROM rise_project_time WHERE deleted=0 GROUP BY status");
while ($row = $r->fetch_assoc()) print_r($row);
echo "-- tasks since Nov 1 with/without time --\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND created_date >= '2025-11-01'");
// try both years
foreach (['2025-11-01','2026-11-01'] as $d) {
  $r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND created_date >= '$d'");
  $c = $r->fetch_assoc()['c'];
  $r2 = $db->query("SELECT COUNT(DISTINCT t.id) c FROM rise_tasks t JOIN rise_project_time pt ON pt.task_id=t.id AND pt.deleted=0 WHERE t.deleted=0 AND t.created_date >= '$d'");
  $c2 = $r2->fetch_assoc()['c'];
  $r3 = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND created_date >= '$d' AND (project_id IS NULL OR project_id=0)");
  echo "$d total=$c with_time=$c2 no_project=" . $r3->fetch_assoc()['c'] . "\n";
}
