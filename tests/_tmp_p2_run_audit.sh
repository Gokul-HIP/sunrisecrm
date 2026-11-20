#!/bin/bash
cd /home/gokul/projects/crm_sunrise || exit 1
PHP=/mnt/c/xampp/php/php.exe
$PHP -l app/Libraries/Content_calendar_import.php
$PHP tests/_tmp_content_calendar_phase2_audit.php > /tmp/cc_p2_audit.out 2>&1
echo AUDIT_EXIT:$?
echo PASS=$(grep -c '^\[PASS\]' /tmp/cc_p2_audit.out || true)
echo FAIL=$(grep -c '^\[FAIL\]' /tmp/cc_p2_audit.out || true)
echo "--- FAILS ---"
grep '^\[FAIL\]' /tmp/cc_p2_audit.out || true
echo "--- RESULT LINE ---"
grep -E 'RESULT|passed|failed' /tmp/cc_p2_audit.out | tail -5
echo "--- FIXTURE / KEY ---"
grep -E '^--- |^\[PASS\] [0-9]|^\[FAIL\] [0-9]|undo|duplicate|403|ods|\.xls|permission|Needs admin|skipped|Kannada|Posted|multi-sheet|over 5|5001' /tmp/cc_p2_audit.out | head -200
wc -l /tmp/cc_p2_audit.out
