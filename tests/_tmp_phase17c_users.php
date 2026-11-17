<?php
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);
$r = $db->query("SELECT id,email,user_type,is_admin,password FROM rise_users WHERE deleted=0 AND id IN (7,40,66,68)");
while ($row = $r->fetch_assoc()) {
    echo "id={$row['id']} email={$row['email']} type={$row['user_type']} admin={$row['is_admin']} hash_prefix=" . substr($row['password'], 0, 30) . "\n";
}
