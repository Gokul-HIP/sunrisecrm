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

$r = $db->query("SELECT id,title,LEFT(permissions,200) p FROM rise_roles WHERE deleted=0");
while ($row = $r->fetch_assoc()) {
    echo "role {$row['id']} {$row['title']}: {$row['p']}\n\n";
}

$r = $db->query("SELECT u.id,u.email,u.is_admin,u.role_id FROM rise_users u WHERE u.deleted=0 AND u.user_type='staff' AND (u.is_admin=1 OR u.role_id IN (3,4,5,7)) ORDER BY u.is_admin DESC, u.id LIMIT 30");
while ($row = $r->fetch_assoc()) {
    echo "user {$row['id']} role={$row['role_id']} admin={$row['is_admin']} {$row['email']}\n";
}
