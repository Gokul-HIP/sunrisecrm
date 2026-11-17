<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $r) { $cfg[$r[1]] = $r[2]; }
$port = !empty($cfg['port']) ? (int)$cfg['port'] : 3306;
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database'], $port);
if ($db->connect_errno) { fwrite(STDERR, $db->connect_error); exit(1); }
echo "dbok\n";

echo "=== SPACED NAMES ===\n";
$q = $db->query("SELECT id,title FROM rise_projects WHERE deleted=0 AND (title LIKE ' %' OR title LIKE '% ' OR title REGEXP '  ') LIMIT 8");
while ($r = $q->fetch_assoc()) echo "P{$r['id']}=[{$r['title']}]\n";
$q = $db->query("SELECT id,company_name FROM rise_clients WHERE deleted=0 AND (company_name LIKE ' %' OR company_name LIKE '% ') LIMIT 8");
while ($r = $q->fetch_assoc()) echo "C{$r['id']}=[{$r['company_name']}]\n";
$q = $db->query("SELECT id,title FROM rise_tasks WHERE deleted=0 AND (title LIKE ' %' OR title LIKE '% ') LIMIT 8");
while ($r = $q->fetch_assoc()) echo "T{$r['id']}=[{$r['title']}]\n";
$q = $db->query("SELECT id,first_name,last_name FROM rise_users WHERE deleted=0 AND (first_name LIKE ' %' OR first_name LIKE '% ' OR last_name LIKE ' %' OR last_name LIKE '% ') LIMIT 8");
while ($r = $q->fetch_assoc()) echo "U{$r['id']}=[{$r['first_name']}][{$r['last_name']}]\n";

// Also find titles with pipe and spaces like ' Sunrise | October 2026 '
$q = $db->query("SELECT id,title FROM rise_projects WHERE deleted=0 AND title LIKE '%|%' AND (title LIKE ' %' OR title LIKE '% ' OR title LIKE '% |%' OR title LIKE '%| %') LIMIT 10");
while ($r = $q->fetch_assoc()) echo "PIPE_P{$r['id']}=[{$r['title']}]\n";

echo "=== SPLIT TASKS ===\n";
foreach ([25089, 25090] as $tid) {
    $t = $db->query("SELECT id,title,points,assigned_to,collaborators,status_id FROM rise_tasks WHERE id=$tid")->fetch_assoc();
    echo "task " . json_encode($t) . "\n";
    $a = $db->query("SELECT task_id,user_id,earned_points,original_points,participants_count,is_collaborator,deleted FROM rise_task_point_awards WHERE task_id=$tid");
    while ($r = $a->fetch_assoc()) echo "  award " . json_encode($r) . "\n";
}
$q = $db->query("SELECT a.task_id, COUNT(*) c, MAX(t.title) title, MAX(t.points) points
  FROM rise_task_point_awards a
  INNER JOIN rise_tasks t ON t.id=a.task_id AND t.deleted=0
  WHERE a.deleted=0 AND a.participants_count>1
  GROUP BY a.task_id HAVING c>=2
  ORDER BY a.task_id DESC LIMIT 10");
while ($r = $q->fetch_assoc()) echo "split_task {$r['task_id']} awards={$r['c']} pts={$r['points']} [{$r['title']}]\n";

echo "=== PERM USERS ===\n";
$q = $db->query("SELECT id,email,first_name,last_name,is_admin FROM rise_users WHERE deleted=0 AND user_type='staff' AND is_admin=0 LIMIT 20");
while ($r = $q->fetch_assoc()) {
    $roles = $db->query("SELECT role_id FROM rise_team_member_job_info WHERE user_id={$r['id']} AND deleted=0")->fetch_assoc();
    echo "U{$r['id']} {$r['first_name']} {$r['last_name']} admin={$r['is_admin']} role=" . ($roles['role_id'] ?? '-') . "\n";
}
