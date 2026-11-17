<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $r) { $cfg[$r[1]] = $r[2]; }
$port = !empty($cfg['port']) ? (int)$cfg['port'] : 3306;
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database'], $port);

echo "=== Sunrise projects ===\n";
$q = $db->query("SELECT id,title FROM rise_projects WHERE deleted=0 AND title LIKE '%Sunrise%' LIMIT 20");
while ($r = $q->fetch_assoc()) echo "P{$r['id']}=[{$r['title']}]\n";

echo "=== Real split awards (participants_count>1, not PHASE) ===\n";
$q = $db->query("SELECT a.task_id, COUNT(*) c, MAX(t.title) title, MAX(t.points) points, MAX(t.assigned_to) assigned_to, MAX(t.collaborators) collaborators, MAX(t.status_id) status_id
  FROM rise_task_point_awards a
  INNER JOIN rise_tasks t ON t.id=a.task_id AND t.deleted=0
  WHERE a.deleted=0 AND a.participants_count>1 AND t.title NOT LIKE 'PHASE%'
  GROUP BY a.task_id HAVING c>=2
  ORDER BY a.task_id DESC LIMIT 15");
while ($r = $q->fetch_assoc()) echo "split {$r['task_id']} c={$r['c']} pts={$r['points']} status={$r['status_id']} a={$r['assigned_to']} collab={$r['collaborators']} [{$r['title']}]\n";

echo "=== Awards for 25089-style: collab tasks with any awards ===\n";
$q = $db->query("SELECT t.id, t.points, t.assigned_to, t.collaborators, t.status_id,
  (SELECT COUNT(*) FROM rise_task_point_awards a WHERE a.task_id=t.id AND a.deleted=0) award_count,
  (SELECT GROUP_CONCAT(CONCAT(a.user_id,':',a.earned_points,':',a.original_points,':',a.participants_count) SEPARATOR '|') FROM rise_task_point_awards a WHERE a.task_id=t.id AND a.deleted=0) awards
  FROM rise_tasks t
  WHERE t.deleted=0 AND t.status_id=3 AND t.collaborators IS NOT NULL AND t.collaborators!=''
    AND t.id IN (25089,25090,25091,25092,25093,25094,25095)
  ORDER BY t.id");
while ($r = $q->fetch_assoc()) echo json_encode($r) . "\n";

echo "=== 5 done collab tasks with awards around those ids ===\n";
$q = $db->query("SELECT t.id, t.points, t.assigned_to, t.collaborators,
  (SELECT GROUP_CONCAT(CONCAT(a.user_id,'=',a.earned_points,'/',a.original_points,' pc=',a.participants_count) SEPARATOR '; ')
   FROM rise_task_point_awards a WHERE a.task_id=t.id AND a.deleted=0) awards
  FROM rise_tasks t
  WHERE t.deleted=0 AND t.status_id=3 AND FIND_IN_SET(30, t.collaborators)
    AND EXISTS (SELECT 1 FROM rise_task_point_awards a WHERE a.task_id=t.id AND a.deleted=0)
  ORDER BY t.id DESC LIMIT 10");
while ($r = $q->fetch_assoc()) echo json_encode($r) . "\n";

echo "=== roles / timesheet perms ===\n";
$q = $db->query("SHOW COLUMNS FROM rise_roles");
while ($r = $q->fetch_assoc()) echo $r['Field'] . " ";
echo "\n";
$q = $db->query("SELECT id,title,permissions FROM rise_roles WHERE deleted=0 LIMIT 15");
while ($r = $q->fetch_assoc()) {
    $p = $r['permissions'];
    $has = (strpos($p, 'timesheet') !== false || strpos($p, 'show_assigned') !== false);
    if ($has || true) {
        echo "role {$r['id']} {$r['title']} :: " . substr(preg_replace('/\s+/', ' ', $p), 0, 200) . "\n";
    }
}
echo "=== users with role ===\n";
$q = $db->query("SELECT u.id,u.first_name,u.last_name,u.role_id,r.title FROM rise_users u LEFT JOIN rise_roles r ON r.id=u.role_id WHERE u.deleted=0 AND u.user_type='staff' AND u.is_admin=0 LIMIT 25");
while ($r = $q->fetch_assoc()) echo "U{$r['id']} {$r['first_name']} {$r['last_name']} role={$r['role_id']} {$r['title']}\n";
