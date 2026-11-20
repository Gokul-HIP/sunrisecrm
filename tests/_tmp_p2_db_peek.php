<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'sunrisecrmnew');
$r = $mysqli->query('SELECT id,name,deleted FROM rise_cc_calendars ORDER BY id');
while ($row = $r->fetch_assoc()) {
    echo $row['id'] . ' |' . $row['deleted'] . '| ' . $row['name'] . PHP_EOL;
}
$r = $mysqli->query('SELECT date, COUNT(*) c FROM rise_cc_items WHERE calendar_id=25 AND deleted=0 GROUP BY date HAVING c>=2 LIMIT 10');
while ($row = $r->fetch_assoc()) {
    echo 'items ' . $row['date'] . ' ' . $row['c'] . PHP_EOL;
}
$r = $mysqli->query('SELECT date,label FROM rise_cc_special_days WHERE calendar_id=25 AND deleted=0 LIMIT 10');
while ($row = $r->fetch_assoc()) {
    echo 'special ' . $row['date'] . ' ' . $row['label'] . PHP_EOL;
}
