<?php
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

$r = $db->query("SELECT setting_name, LENGTH(setting_value) AS len FROM rise_settings WHERE deleted=0 AND setting_name LIKE '%left_menu%' AND setting_value IS NOT NULL AND setting_value <> ''");
while ($row = $r->fetch_assoc()) {
    echo $row['setting_name'] . " len=" . $row['len'] . "\n";
    $val = $db->query("SELECT setting_value FROM rise_settings WHERE setting_name='" . $db->real_escape_string($row['setting_name']) . "'")->fetch_row()[0];
    $data = @unserialize($val);
    if (!is_array($data)) { echo "  (unserialize fail)\n"; continue; }
    foreach ($data as $i => $item) {
        $name = $item['name'] ?? '?';
        $sub = !empty($item['is_sub_menu']) ? ' SUB' : '';
        echo "  [$i]$sub $name\n";
        if ($name === 'team_members' || $name === 'team' || $name === 'employees') {
            // highlight
        }
    }
}
echo "DONE\n";
