<?php
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
// Prefer the active (uncommented) local $default block values
preg_match_all("/^\s*'hostname'\s*=>\s*'([^']+)'/m", $raw, $hm);
preg_match_all("/^\s*'username'\s*=>\s*'([^']+)'/m", $raw, $um);
preg_match_all("/^\s*'password'\s*=>\s*'([^']*)'/m", $raw, $pm);
preg_match_all("/^\s*'database'\s*=>\s*'([^']+)'/m", $raw, $dm);
$host = end($hm[1]) ?: '127.0.0.1';
$user = end($um[1]) ?: 'hip_wsl';
$pass = end($pm[1]) ?: '';
$name = end($dm[1]) ?: 'sunrisecrmnew';

$mysqli = @new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_error) {
    fwrite(STDERR, "DB fail for $user@$host/$name: {$mysqli->connect_error}\n");
    exit(1);
}

echo "DB=$name\n";
$r = $mysqli->query("SELECT id, action, changes, created_at FROM rise_activity_logs WHERE log_type='task' AND log_type_id=23527 AND deleted=0 ORDER BY id DESC LIMIT 8");
while ($row = $r->fetch_assoc()) {
    echo "log#{$row['id']} action={$row['action']} at={$row['created_at']}\n";
    $c = @unserialize($row['changes']);
    if (is_array($c)) {
        foreach ($c as $k => $v) {
            $from = is_array($v) ? ($v['from'] ?? '') : '';
            $to = is_array($v) ? ($v['to'] ?? '') : '';
            echo "  $k: [$from] -> [$to]\n";
        }
    } else {
        echo "  (no changes array)\n";
    }
}

echo "\n-- recent task logs with submitted_for_review_at --\n";
$r = $mysqli->query("SELECT id, log_type_id, changes FROM rise_activity_logs WHERE log_type='task' AND deleted=0 AND changes LIKE '%submitted_for_review_at%' ORDER BY id DESC LIMIT 5");
while ($row = $r->fetch_assoc()) {
    echo "log#{$row['id']} task#{$row['log_type_id']}\n";
    $c = @unserialize($row['changes']);
    if (is_array($c)) {
        echo "  keys: " . implode(',', array_keys($c)) . "\n";
        if (isset($c['submitted_for_review_at'])) {
            echo "  stamp from=" . var_export($c['submitted_for_review_at']['from'] ?? null, true) . " to=" . var_export($c['submitted_for_review_at']['to'] ?? null, true) . "\n";
        }
    }
}

echo "\n-- task 23527 --\n";
$r = $mysqli->query("SELECT id, status_id, submitted_for_review_at, status_changed_at FROM rise_tasks WHERE id=23527");
print_r($r->fetch_assoc());

echo "\n-- distinct keys in recent task activity changes (sample 200) --\n";
$r = $mysqli->query("SELECT changes FROM rise_activity_logs WHERE log_type='task' AND deleted=0 AND action='updated' AND changes!='' ORDER BY id DESC LIMIT 200");
$keys = [];
while ($row = $r->fetch_assoc()) {
    $c = @unserialize($row['changes']);
    if (is_array($c)) {
        foreach (array_keys($c) as $k) {
            $keys[$k] = ($keys[$k] ?? 0) + 1;
        }
    }
}
arsort($keys);
foreach ($keys as $k => $n) {
    echo "$k\t$n\n";
}
