<?php
/**
 * Restore sunrisecrm_scratch from a fresh copy of sunrisecrmnew (no mysqldump required).
 * Usage: php tests/_tmp_phase17_restore_scratch.php
 */
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }

$src = 'sunrisecrmnew';
$dst = 'sunrisecrm_scratch';
$host = $cfg['hostname']; $user = $cfg['username']; $pass = $cfg['password'];

$admin = new mysqli($host, $user, $pass);
if ($admin->connect_error) { fwrite(STDERR, $admin->connect_error . "\n"); exit(1); }
$admin->query("SET SESSION sql_mode=''");
$admin->query("DROP DATABASE IF EXISTS `$dst`");
$admin->query("CREATE DATABASE `$dst` CHARACTER SET utf8 COLLATE utf8_general_ci");

$srcDb = new mysqli($host, $user, $pass, $src);
$dstDb = new mysqli($host, $user, $pass, $dst);
$srcDb->query("SET SESSION sql_mode=''");
$dstDb->query("SET SESSION sql_mode=''");

$tables = [];
$r = $srcDb->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
while ($row = $r->fetch_row()) { $tables[] = $row[0]; }
echo "Copying " . count($tables) . " tables $src -> $dst\n";

foreach ($tables as $i => $table) {
    $create = $srcDb->query("SHOW CREATE TABLE `$table`")->fetch_assoc();
    $sql = $create['Create Table'];
    if (!$dstDb->query($sql)) {
        fwrite(STDERR, "CREATE fail $table: " . $dstDb->error . "\n");
        exit(1);
    }
    // Disable keys for speed
    $dstDb->query("ALTER TABLE `$table` DISABLE KEYS");
    $srcDb->real_query("SELECT * FROM `$table`");
    $result = $srcDb->use_result();
    $cols = null;
    $batch = [];
    $batchSize = 200;
    $n = 0;
    while ($row = $result->fetch_assoc()) {
        if ($cols === null) {
            $cols = array_keys($row);
            $colList = '`' . implode('`,`', $cols) . '`';
        }
        $vals = [];
        foreach ($cols as $c) {
            $v = $row[$c];
            if ($v === null) $vals[] = 'NULL';
            else $vals[] = "'" . $dstDb->real_escape_string($v) . "'";
        }
        $batch[] = '(' . implode(',', $vals) . ')';
        if (count($batch) >= $batchSize) {
            $dstDb->query("INSERT INTO `$table` ($colList) VALUES " . implode(',', $batch));
            $batch = [];
        }
        $n++;
    }
    $result->free();
    if ($batch) {
        $dstDb->query("INSERT INTO `$table` ($colList) VALUES " . implode(',', $batch));
    }
    $dstDb->query("ALTER TABLE `$table` ENABLE KEYS");
    if (($i + 1) % 20 === 0 || $i === count($tables) - 1) {
        echo "  " . ($i + 1) . "/" . count($tables) . " ($table rows≈$n)\n";
    }
}

echo "DONE scratch restore\n";
