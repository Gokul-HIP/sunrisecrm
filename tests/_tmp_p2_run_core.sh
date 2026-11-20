#!/bin/bash
cd /home/gokul/projects/crm_sunrise || exit 1
PHP=/mnt/c/xampp/php/php.exe
echo "=== UNC probe ==="
$PHP tests/_tmp_cc_unc_probe.php
echo "=== SQL via mysql client ==="
# get credentials without printing password
DBNAME=$($PHP -r 'define("ENVIRONMENT","development"); define("FCPATH",getcwd().DIRECTORY_SEPARATOR); require "app/Config/Paths.php"; $p=new Config\Paths(); require rtrim($p->systemDirectory,"\\/ ")."/bootstrap.php"; require_once SYSTEMPATH."Config/DotEnv.php"; (new CodeIgniter\Config\DotEnv(ROOTPATH))->load(); $db=Config\Database::connect("default"); echo $db->database,"\n",$db->hostname,"\n",$db->username,"\n",$db->password,"\n",$db->port?:3306;')
# parse lines
mapfile -t DB <<< "$DBNAME"
echo "database=${DB[0]} host=${DB[1]} user=${DB[2]}"
MYSQL=/mnt/c/xampp/mysql/bin/mysql.exe
SQL=docs/content_calendar.sql
echo "--- SQL first ---"
"$MYSQL" -h "${DB[1]}" -P "${DB[4]}" -u "${DB[2]}" -p"${DB[3]}" "${DB[0]}" < "$SQL" && echo PASS: SQL first || echo FAIL: SQL first
echo "--- SQL second ---"
"$MYSQL" -h "${DB[1]}" -P "${DB[4]}" -u "${DB[2]}" -p"${DB[3]}" "${DB[0]}" < "$SQL" && echo PASS: SQL second || echo FAIL: SQL second
echo "--- schema check ---"
"$MYSQL" -h "${DB[1]}" -P "${DB[4]}" -u "${DB[2]}" -p"${DB[3]}" "${DB[0]}" -e "
SHOW TABLES LIKE 'rise_cc_import_logs';
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rise_cc_items' AND COLUMN_NAME='import_batch_id';
SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rise_cc_items' AND INDEX_NAME='idx_cc_items_import_batch';
"
echo "=== phase2 audit ==="
$PHP tests/_tmp_content_calendar_phase2_audit.php > /tmp/cc_p2_audit.out 2>&1
EC=$?
tail -100 /tmp/cc_p2_audit.out
echo AUDIT_EXIT:$EC
grep -c '^\[PASS\]' /tmp/cc_p2_audit.out
grep -c '^\[FAIL\]' /tmp/cc_p2_audit.out
