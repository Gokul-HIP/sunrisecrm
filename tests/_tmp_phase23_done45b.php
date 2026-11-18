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

$queries = [
    'open+created_nov' => "SELECT ts.key_name, COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND t.created_date>='2026-11-01' GROUP BY ts.key_name",
    'open+created_nov18' => "SELECT ts.key_name, COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND t.created_date BETWEEN '2026-11-18' AND '2026-11-18' GROUP BY ts.key_name",
    'open+created_2025-11' => "SELECT COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND ts.key_name='done' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND t.created_date BETWEEN '2025-11-01' AND '2025-11-30'",
    'open+created_2026-09' => "SELECT COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND ts.key_name='done' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND t.created_date BETWEEN '2026-09-01' AND '2026-09-30'",
    'assigned_only_open_all_status' => "SELECT ts.key_name, COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND t.assigned_to=39 GROUP BY ts.key_name",
    'member_projects_only' => "SELECT ts.key_name, COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators)) AND t.project_id IN (SELECT project_id FROM rise_project_members WHERE user_id=39 AND deleted=0) GROUP BY ts.key_name",
];

foreach ($queries as $name => $sql) {
    echo "=== $name ===\n";
    $r = $db->query($sql);
    while ($row = $r->fetch_assoc()) {
        if (isset($row['key_name'])) {
            echo "{$row['key_name']}={$row['c']}\n";
        } else {
            echo "c={$row['c']}\n";
        }
    }
}

// brute near 45
echo "=== months near 45 done open ===\n";
$r = $db->query("SELECT DATE_FORMAT(NULLIF(t.created_date,'0000-00-00'), '%Y-%m') ym, COUNT(*) c
  FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id JOIN rise_task_status ts ON ts.id=t.status_id
  WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND ts.key_name='done'
    AND (t.assigned_to=39 OR FIND_IN_SET('39',t.collaborators))
  GROUP BY ym HAVING c BETWEEN 40 AND 55 ORDER BY ym");
while ($row = $r->fetch_assoc()) {
    echo ($row['ym'] ?: 'ZERO') . " = {$row['c']}\n";
}
