<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $r) { $cfg[$r[1]] = $r[2]; }
$port = !empty($cfg['port']) ? (int)$cfg['port'] : 3306;
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database'], $port);

$ids = [25671, 25660, 25659, 25658, 25657, 25089, 25090];
foreach ($ids as $tid) {
    echo "=== task $tid ===\n";
    $t = $db->query("SELECT id,title,points,assigned_to,collaborators,status_id FROM rise_tasks WHERE id=$tid")->fetch_assoc();
    echo json_encode($t) . "\n";
    $a = $db->query("SELECT user_id,earned_points,original_points,participants_count,is_collaborator,reduction_percent FROM rise_task_point_awards WHERE task_id=$tid AND deleted=0");
    while ($r = $a->fetch_assoc()) {
        $u = $db->query("SELECT first_name,last_name FROM rise_users WHERE id={$r['user_id']}")->fetch_assoc();
        echo "  award uid={$r['user_id']} {$u['first_name']} {$u['last_name']} earned={$r['earned_points']} orig={$r['original_points']} pc={$r['participants_count']} collab={$r['is_collaborator']} red={$r['reduction_percent']}\n";
    }
}

echo "=== Team Member role perms (timesheet/show_assigned) ===\n";
$r = $db->query("SELECT id,title,permissions FROM rise_roles WHERE id=5")->fetch_assoc();
$p = @unserialize($r['permissions']);
foreach (['timesheet_manage_permission','timesheet_manage_permission_specific','show_assigned_tasks_only','can_manage_all_projects','do_not_show_projects','client'] as $k) {
    echo "$k=" . json_encode($p[$k] ?? null) . "\n";
}
echo "=== Manager role ===\n";
$r = $db->query("SELECT permissions FROM rise_roles WHERE id=4")->fetch_assoc();
$p = @unserialize($r['permissions']);
foreach (['timesheet_manage_permission','timesheet_manage_permission_specific','show_assigned_tasks_only','can_manage_all_projects'] as $k) {
    echo "$k=" . json_encode($p[$k] ?? null) . "\n";
}

echo "=== user 68 / 30 access_info style ===\n";
foreach ([30, 68, 47, 39] as $uid) {
    $u = $db->query("SELECT id,role_id,is_admin FROM rise_users WHERE id=$uid")->fetch_assoc();
    $role = $db->query("SELECT permissions FROM rise_roles WHERE id=" . (int)$u['role_id'])->fetch_assoc();
    $p = @unserialize($role['permissions'] ?? 'a:0:{}');
    echo "U$uid role={$u['role_id']} timesheet=" . json_encode($p['timesheet_manage_permission'] ?? null)
        . " show_assigned=" . json_encode($p['show_assigned_tasks_only'] ?? null)
        . " all_projects=" . json_encode($p['can_manage_all_projects'] ?? null) . "\n";
}
