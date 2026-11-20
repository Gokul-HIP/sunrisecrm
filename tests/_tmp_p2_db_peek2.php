<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'sunrisecrmnew');
echo "=== columns items ===\n";
$r = $mysqli->query('SHOW COLUMNS FROM rise_cc_items');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
echo "=== special ===\n";
$r = $mysqli->query('SHOW COLUMNS FROM rise_cc_special_days');
while ($row = $r->fetch_assoc()) {
    echo $row['Field'] . "\n";
}
$dateCol = 'post_date';
$r = $mysqli->query('SHOW COLUMNS FROM rise_cc_items LIKE "%date%"');
$cols = array();
while ($row = $r->fetch_assoc()) {
    $cols[] = $row['Field'];
}
echo "date-like: " . implode(',', $cols) . "\n";
if ($cols) {
    $dateCol = $cols[0];
    $r = $mysqli->query("SELECT `$dateCol` d, COUNT(*) c FROM rise_cc_items WHERE calendar_id=25 AND deleted=0 GROUP BY `$dateCol` HAVING c>=2 LIMIT 10");
    while ($row = $r->fetch_assoc()) {
        echo "items {$row['d']} {$row['c']}\n";
    }
}
$r = $mysqli->query('SELECT * FROM rise_cc_special_days WHERE calendar_id=25 AND deleted=0 LIMIT 5');
while ($row = $r->fetch_assoc()) {
    echo 'special ' . json_encode($row) . "\n";
}
