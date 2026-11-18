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

foreach ([4, 7, 9] as $sid) {
    $c = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND status_id=$sid")->fetch_assoc()['c'];
    $open = $db->query("SELECT COUNT(*) c FROM rise_tasks t JOIN rise_projects p ON p.id=t.project_id WHERE t.deleted=0 AND p.deleted=0 AND p.status='open' AND t.status_id=$sid")->fetch_assoc()['c'];
    echo "status_$sid tasks=$c open_project=$open\n";
}

echo "=== roles via rise_roles ===\n";
$r = $db->query("SHOW TABLES LIKE 'rise_roles'");
echo "roles_table=" . ($r->num_rows ? 'Y' : 'N') . "\n";
$cols = $db->query("SHOW COLUMNS FROM rise_users")->fetch_all(MYSQLI_ASSOC);
foreach ($cols as $c) {
    if (stripos($c['Field'], 'role') !== false || stripos($c['Field'], 'perm') !== false) {
        echo "user_col={$c['Field']}\n";
    }
}

// find show_assigned via role
$r = $db->query("SELECT u.id,u.email,u.role_id,r.permissions FROM rise_users u LEFT JOIN rise_roles r ON r.id=u.role_id WHERE u.deleted=0 AND u.user_type='staff' LIMIT 200");
$own = $proj = $admin = [];
while ($row = $r->fetch_assoc()) {
    $p = $row['permissions'] ?? '';
    if ((int) $row['id'] === 66 || strpos($p, 'can_manage_all_projects":"1') !== false) {
        $admin[] = $row['id'];
    }
    if (strpos($p, 'show_assigned_tasks_only":"1') !== false) {
        $own[] = $row['id'] . ':' . $row['email'];
    }
}
echo "own_only_sample=" . implode(',', array_slice($own, 0, 10)) . "\n";
echo "admin_like_count=" . count($admin) . "\n";

// permissions live on role
$r = $db->query("SELECT id,title,permissions FROM rise_roles WHERE deleted=0");
while ($row = $r->fetch_assoc()) {
    $p = $row['permissions'];
    $flags = [];
    if (strpos($p, 'show_assigned_tasks_only":"1') !== false) {
        $flags[] = 'own_only';
    }
    if (strpos($p, 'can_manage_all_projects":"1') !== false) {
        $flags[] = 'manage_all';
    }
    echo "role {$row['id']} {$row['title']} " . implode(',', $flags) . "\n";
}
