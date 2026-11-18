<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$raw = file_get_contents(dirname(__DIR__) . '/app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $r) {
    $cfg[$r[1]] = $r[2];
}
$port = isset($cfg['port']) && $cfg['port'] !== '' ? (int) $cfg['port'] : 3306;
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database'], $port);
if ($db->connect_error) {
    fwrite(STDERR, "connect fail\n");
    exit(1);
}

echo "=== created_date column ===\n";
$r = $db->query("SHOW COLUMNS FROM rise_tasks LIKE 'created_date'");
print_r($r->fetch_assoc());

echo "=== sample tasks ===\n";
$r = $db->query("SELECT id,assigned_to,collaborators,project_id,status_id,deadline,created_date,deleted FROM rise_tasks WHERE id IN (25906,25949,25950,25956,15583,15582,15579)");
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "=== Surya 39 status counts (open projects) ===\n";
$sql = "SELECT ts.key_name, COUNT(*) c
        FROM rise_tasks t
        JOIN rise_task_status ts ON ts.id=t.status_id
        JOIN rise_projects p ON p.id=t.project_id
        WHERE t.deleted=0 AND p.deleted=0 AND p.status='open'
          AND (t.assigned_to=39 OR FIND_IN_SET('39', t.collaborators))
        GROUP BY ts.key_name";
$r = $db->query($sql);
while ($row = $r->fetch_assoc()) {
    echo $row['key_name'] . '=' . $row['c'] . "\n";
}

echo "=== Surya all non-deleted (any project) ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND (assigned_to=39 OR FIND_IN_SET('39', collaborators))");
echo 'all=' . $r->fetch_assoc()['c'] . "\n";

echo "=== created_date BETWEEN datetime trap Nov 18-18 ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND created_date BETWEEN '2026-11-18' AND '2026-11-18'");
echo 'between_same_day=' . $r->fetch_assoc()['c'] . "\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND DATE(created_date)='2026-11-18'");
echo 'date_equals=' . $r->fetch_assoc()['c'] . "\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND created_date >= '2026-11-18' AND created_date < '2026-11-19'");
echo 'half_open=' . $r->fetch_assoc()['c'] . "\n";

echo "=== Surya with created BETWEEN 2026-11-18 and 2026-11-18 + open project ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id
  WHERE t.deleted=0 AND p.deleted=0 AND p.status='open'
  AND (t.assigned_to=39 OR FIND_IN_SET('39', t.collaborators))
  AND t.created_date BETWEEN '2026-11-18' AND '2026-11-18'");
echo 'surya_nov18_between=' . $r->fetch_assoc()['c'] . "\n";

echo "=== Surya default list statuses (exclude done) no date ===\n";
$r = $db->query("SELECT t.id, ts.key_name, t.deadline, t.created_date
  FROM rise_tasks t
  JOIN rise_task_status ts ON ts.id=t.status_id
  JOIN rise_projects p ON p.id=t.project_id
  WHERE t.deleted=0 AND p.deleted=0 AND p.status='open'
  AND (t.assigned_to=39 OR FIND_IN_SET('39', t.collaborators))
  AND ts.key_name != 'done'
  ORDER BY t.id DESC LIMIT 20");
while ($row = $r->fetch_assoc()) {
    echo "{$row['id']} {$row['key_name']} dl={$row['deadline']} cr={$row['created_date']}\n";
}

echo "=== max id ===\n";
$r = $db->query("SELECT MAX(id) m FROM rise_tasks");
echo 'max=' . $r->fetch_assoc()['m'] . "\n";

echo "=== tasks since 2026-11-01 count ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND created_date >= '2026-11-01'");
echo 'since_nov1=' . $r->fetch_assoc()['c'] . "\n";
