<?php
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database']);
echo "VERSION=" . $db->query("SELECT VERSION()")->fetch_row()[0] . "\n";
echo "DB=" . $cfg['database'] . "\n";
foreach ([25784, 25702, 23527] as $id) {
    $r = $db->query("SELECT id,title,points,status_id,deadline,end_time,assigned_to,collaborators,created_date,submitted_for_review_at,first_review_submitted_at,review_changes_count,timer_lock_json,points_rules_locked_at,status_changed_at FROM rise_tasks WHERE id=$id");
    $row = $r ? $r->fetch_assoc() : null;
    echo "\nTASK $id\n";
    print_r($row);
}
echo "\n-- require line --\n";
$helper = file_get_contents(__DIR__ . '/../app/Helpers/task_points_helper.php');
if (preg_match('/require_once.*task_points_review_rules_helper.*/', $helper, $mm)) {
    // find line number
    $lines = explode("\n", $helper);
    foreach ($lines as $i => $line) {
        if (strpos($line, 'task_points_review_rules_helper') !== false) {
            echo "task_points_helper.php:" . ($i + 1) . " " . trim($line) . "\n";
        }
    }
}
echo "\n-- App_Controller helpers --\n";
$ac = file_get_contents(__DIR__ . '/../app/Controllers/App_Controller.php');
if (preg_match('/helper\(array\([^;]+;/', $ac, $mm)) {
    echo substr($mm[0], 0, 300) . "\n";
}
$lines = explode("\n", $ac);
foreach ($lines as $i => $line) {
    if (strpos($line, "helper(array") !== false) {
        echo "App_Controller.php:" . ($i + 1) . "\n";
    }
}
