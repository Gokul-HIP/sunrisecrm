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

$r = $db->query("SELECT id,title,permissions FROM rise_roles WHERE deleted=0");
while ($row = $r->fetch_assoc()) {
    $p = @unserialize($row['permissions']);
    if (!is_array($p)) {
        echo "role {$row['id']} unserialize fail\n";
        continue;
    }
    echo "role {$row['id']} {$row['title']}: show_assigned=" . ($p['show_assigned_tasks_only'] ?? '') .
        " manage_all=" . ($p['can_manage_all_projects'] ?? '') .
        " timesheet=" . ($p['timesheet_manage_permission'] ?? '') . "\n";
}
