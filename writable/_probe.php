<?php
header("Content-Type: text/plain");
ini_set("display_errors", "1");
error_reporting(E_ALL);
ini_set("error_log", __DIR__ . "/logs/php_runtime.log");
echo "ok\n";
echo "mem=" . ini_get("memory_limit") . "\n";
echo "error_log=" . ini_get("error_log") . "\n";
$mysqli = @new mysqli("localhost", "root", "", "sunrisecrm", 3306);
if ($mysqli->connect_error) { echo "db_fail_host: ".$mysqli->connect_error."\n"; }
else { echo "db_ok_host\n"; $mysqli->close(); }
$mysqli2 = @new mysqli("127.0.0.1", "root", "", "sunrisecrm", 3306);
if ($mysqli2->connect_error) { echo "db_fail_ip: ".$mysqli2->connect_error."\n"; }
else { echo "db_ok_ip\n"; $mysqli2->close(); }
# Test hostname:port style used in config
$mysqli3 = @new mysqli("localhost:3306", "root", "", "sunrisecrm");
if ($mysqli3->connect_error) { echo "db_fail_hostport: ".$mysqli3->connect_error."\n"; }
else { echo "db_ok_hostport\n"; $mysqli3->close(); }
