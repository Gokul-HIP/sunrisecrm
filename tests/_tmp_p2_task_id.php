<?php
$mysqli = new mysqli('127.0.0.1', 'root', '', 'sunrisecrmnew');
$r = $mysqli->query('SELECT id FROM rise_tasks WHERE deleted=0 ORDER BY id DESC LIMIT 1');
$row = $r->fetch_assoc();
echo $row ? $row['id'] : 'none';
echo PHP_EOL;
