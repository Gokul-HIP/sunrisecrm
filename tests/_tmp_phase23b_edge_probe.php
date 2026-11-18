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

echo "=== statuses 4,7,9 ===\n";
$r = $db->query("SELECT id,title,key_name,hide_from_kanban,deleted FROM rise_task_status WHERE id IN (4,7,9) OR key_name IN ('done','in_review','changes','to_do','in_progress')");
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "=== project_id=0 tasks ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND (project_id=0 OR project_id IS NULL)");
echo "count=" . $r->fetch_assoc()['c'] . "\n";

echo "=== missing project ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks t LEFT JOIN rise_projects p ON p.id=t.project_id WHERE t.deleted=0 AND t.project_id>0 AND p.id IS NULL");
echo "count=" . $r->fetch_assoc()['c'] . "\n";

echo "=== deleted project ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id WHERE t.deleted=0 AND p.deleted=1");
echo "count=" . $r->fetch_assoc()['c'] . "\n";

echo "=== FIND_IN_SET null project status drop demo ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks t LEFT JOIN rise_projects p ON p.id=t.project_id WHERE t.deleted=0 AND FIND_IN_SET(p.status,'open')");
echo "with_find_in_set=" . $r->fetch_assoc()['c'] . "\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks t LEFT JOIN rise_projects p ON p.id=t.project_id WHERE t.deleted=0 AND (p.id IS NULL OR p.deleted=0 AND FIND_IN_SET(p.status,'open') OR (t.project_id=0 OR t.project_id IS NULL))");
echo "with_null_safe=" . $r->fetch_assoc()['c'] . "\n";

echo "=== deleted assignee ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks t LEFT JOIN rise_users u ON u.id=t.assigned_to WHERE t.deleted=0 AND t.assigned_to>0 AND (u.id IS NULL OR u.deleted=1)");
echo "count=" . $r->fetch_assoc()['c'] . "\n";
$r = $db->query("SELECT t.id,t.assigned_to,u.deleted FROM rise_tasks t LEFT JOIN rise_users u ON u.id=t.assigned_to WHERE t.deleted=0 AND t.assigned_to>0 AND (u.id IS NULL OR u.deleted=1) LIMIT 5");
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "=== missing/deleted status ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks t LEFT JOIN rise_task_status ts ON ts.id=t.status_id WHERE t.deleted=0 AND (ts.id IS NULL OR ts.deleted=1)");
echo "count=" . $r->fetch_assoc()['c'] . "\n";

echo "=== zero created_date ===\n";
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND (created_date='0000-00-00' OR created_date IS NULL)");
echo "count=" . $r->fetch_assoc()['c'] . "\n";

echo "=== in_review stamped by assignee with most ===\n";
$ir = $db->query("SELECT id FROM rise_task_status WHERE key_name='in_review' AND deleted=0")->fetch_assoc()['id'];
$r = $db->query("SELECT assigned_to, COUNT(*) c FROM rise_tasks WHERE deleted=0 AND status_id=$ir AND submitted_for_review_at IS NOT NULL GROUP BY assigned_to ORDER BY c DESC LIMIT 5");
while ($row = $r->fetch_assoc()) {
    print_r($row);
}

echo "=== show_assigned_tasks_only users ===\n";
$r = $db->query("SELECT id,email,permissions FROM rise_users WHERE deleted=0 AND user_type='staff' AND permissions LIKE '%show_assigned_tasks_only%' LIMIT 20");
while ($row = $r->fetch_assoc()) {
    if (strpos($row['permissions'], 'show_assigned_tasks_only\":\"1\"') !== false || strpos($row['permissions'], '"show_assigned_tasks_only":"1"') !== false) {
        echo "uid={$row['id']} email={$row['email']}\n";
    }
}

echo "=== roles sample ===\n";
$r = $db->query("SELECT id,first_name,last_name,is_admin,permissions FROM rise_users WHERE deleted=0 AND user_type='staff' AND id IN (39,42,66) ");
while ($row = $r->fetch_assoc()) {
    echo "id={$row['id']} admin={$row['is_admin']} perms_has_assigned=" . (strpos($row['permissions'] ?? '', 'show_assigned_tasks_only') !== false ? 'Y' : 'N') . "\n";
}

echo "=== status counts for 4,7,9 tasks ===\n";
foreach ([4, 7, 9] as $sid) {
    $c = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND status_id=$sid")->fetch_assoc()['c'];
    echo "status_$sid=$c\n";
}
