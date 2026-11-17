<?php
/**
 * Optional: rewrite serialized default_left_menu / user_*_left_menu so Settings UI
 * shows Employees above Team. Runtime Left_menu already normalizes without this.
 * Idempotent. No live.
 */
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

function normalize_employees_menu($items) {
    if (!is_array($items)) return $items;
    $has_employees = false;
    $had_team_members = false;
    $out = [];
    foreach ($items as $item) {
        $name = $item['name'] ?? '';
        if ($name === 'employees') {
            $has_employees = true;
            unset($item['is_sub_menu']);
            $out[] = $item;
            continue;
        }
        if ($name === 'team_members') {
            $had_team_members = true;
            continue;
        }
        $out[] = $item;
    }
    if (!$has_employees && !$had_team_members) return $items;
    if ($has_employees && !$had_team_members) return $out;
    $employees = ['name' => 'employees'];
    $final = [];
    $inserted = false;
    foreach ($out as $item) {
        if (!$inserted && ($item['name'] ?? '') === 'team' && empty($item['is_sub_menu'])) {
            $final[] = $employees;
            $inserted = true;
        }
        $final[] = $item;
    }
    if (!$inserted) $final[] = $employees;
    return $final;
}

$r = $db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted=0 AND setting_name LIKE '%left_menu%' AND setting_value IS NOT NULL AND setting_value <> ''");
$updated = 0;
while ($row = $r->fetch_assoc()) {
    $data = @unserialize($row['setting_value']);
    if (!is_array($data)) {
        echo "SKIP {$row['setting_name']} (not array)\n";
        continue;
    }
    $new = normalize_employees_menu($data);
    if ($new === $data) {
        echo "OK {$row['setting_name']} (already normalized or no team_members)\n";
        continue;
    }
    $ser = serialize($new);
    $db->query("UPDATE rise_settings SET setting_value='" . $db->real_escape_string($ser) . "' WHERE setting_name='" . $db->real_escape_string($row['setting_name']) . "'");
    echo "UPDATED {$row['setting_name']}\n";
    $updated++;
}
echo "updated_count=$updated\n";
