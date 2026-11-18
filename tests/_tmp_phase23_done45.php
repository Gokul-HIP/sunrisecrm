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

echo "Find filters that yield Done≈45 for user 39\n";

$queries = [
    'open_project_done' => "SELECT COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND ts.key_name='done' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators))",
    'done_created_2026' => "SELECT COUNT(*) c FROM rise_tasks t JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND ts.key_name='done' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND t.created_date>='2026-01-01'",
    'done_created_nov' => "SELECT COUNT(*) c FROM rise_tasks t JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND ts.key_name='done' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND t.created_date>='2026-11-01'",
    'done_created_oct_nov' => "SELECT COUNT(*) c FROM rise_tasks t JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND ts.key_name='done' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND t.created_date BETWEEN '2026-10-01' AND '2026-11-18'",
    'done_deadline_nov' => "SELECT COUNT(*) c FROM rise_tasks t JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND ts.key_name='done' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND t.deadline BETWEEN '2026-11-01' AND '2026-11-18'",
    'done_status_changed_nov' => "SELECT COUNT(*) c FROM rise_tasks t JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND ts.key_name='done' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND DATE(t.status_changed_at) BETWEEN '2026-11-01' AND '2026-11-18'",
    'done_awards_nov2026' => "SELECT COUNT(DISTINCT t.id) c FROM rise_tasks t JOIN rise_task_status ts ON ts.id=t.status_id JOIN rise_task_point_awards a ON a.task_id=t.id AND a.user_id=39 AND a.deleted=0 WHERE t.deleted=0 AND ts.key_name='done' AND a.year=2026 AND a.month=11",
    'done_awards_any' => "SELECT COUNT(DISTINCT t.id) c FROM rise_tasks t JOIN rise_task_status ts ON ts.id=t.status_id JOIN rise_task_point_awards a ON a.task_id=t.id AND a.user_id=39 AND a.deleted=0 WHERE t.deleted=0 AND ts.key_name='done'",
    'zero_created' => "SELECT COUNT(*) c, GROUP_CONCAT(t.id) ids FROM rise_tasks t WHERE t.deleted=0 AND t.assigned_to=39 AND (t.created_date='0000-00-00' OR t.created_date IS NULL OR t.created_date=0)",
    'assigned_only_done_open' => "SELECT COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND ts.key_name='done' AND t.assigned_to=39",
];

foreach ($queries as $name => $sql) {
    $r = $db->query($sql);
    $row = $r->fetch_assoc();
    echo "$name = {$row['c']}" . (isset($row['ids']) ? " ids={$row['ids']}" : "") . "\n";
}

// Month-by-month done created
echo "=== done by created month 2026 ===\n";
$r = $db->query("SELECT DATE_FORMAT(NULLIF(t.created_date,'0000-00-00'), '%Y-%m') ym, COUNT(*) c
  FROM rise_tasks t JOIN rise_task_status ts ON ts.id=t.status_id
  WHERE t.deleted=0 AND ts.key_name='done' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators))
  GROUP BY ym ORDER BY ym");
while ($row = $r->fetch_assoc()) {
    echo ($row['ym'] ?: 'ZERO/NULL') . " = {$row['c']}\n";
}
