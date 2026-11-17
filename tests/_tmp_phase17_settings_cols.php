<?php
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], 'sunrisecrm_scratch');
$r = $db->query('SHOW COLUMNS FROM rise_settings');
while ($row = $r->fetch_assoc()) echo $row['Field'] . "\t" . $row['Type'] . "\n";
