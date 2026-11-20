#!/bin/bash
cd /home/gokul/projects/crm_sunrise || exit 1
PHP=/mnt/c/xampp/php/php.exe
# http smoke starts its own server on 8099
$PHP tests/_tmp_content_calendar_http_smoke.php > /tmp/cc_http_smoke.out 2>&1
echo HTTP_EXIT:$?
echo PASS=$(grep -c '^\[PASS\]' /tmp/cc_http_smoke.out || true)
echo FAIL=$(grep -c '^\[FAIL\]' /tmp/cc_http_smoke.out || true)
grep '^\[FAIL\]' /tmp/cc_http_smoke.out || true
grep -E 'RESULT|passed|failed|Import|403|add_client|Add client|view only|content_calendar|Events|Tasks|Messages' /tmp/cc_http_smoke.out | head -80
tail -40 /tmp/cc_http_smoke.out
