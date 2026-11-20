#!/bin/bash
cd /home/gokul/projects/crm_sunrise || exit 1
pkill -f "php -S 127.0.0.1:8099" 2>/dev/null
nohup php -S 0.0.0.0:8099 -t . index.php > /tmp/cc_server.log 2>&1 &
sleep 2
curl -s -o /tmp/cc_signin.html -w "%{http_code}\n" http://127.0.0.1:8099/signin
head -c 1500 /tmp/cc_signin.html
echo
tail -5 /tmp/cc_server.log
ls writable/logs | tail -3
