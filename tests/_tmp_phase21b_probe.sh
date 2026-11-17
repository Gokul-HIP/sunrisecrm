#!/usr/bin/env bash
set -e
cd /home/gokul/projects/crm_sunrise
EMAIL=$(/mnt/c/xampp/php/php.exe tests/_tmp_phase21b_password.php set | tr -d '\r')
echo "EMAIL=$EMAIL"
COOKIE=/tmp/crm21b.txt
rm -f "$COOKIE"
curl -s -c "$COOKIE" -b "$COOKIE" "http://localhost:8080/index.php/signin" >/tmp/s.html
TOKEN=$(awk '/rise_csrf_cookie/{print $NF}' "$COOKIE" | tail -1)
echo "TOKEN_LEN=${#TOKEN}"
curl -s -c "$COOKIE" -b "$COOKIE" -X POST "http://localhost:8080/index.php/signin/authenticate" \
  --data-urlencode "email=$EMAIL" \
  --data-urlencode "password=Phase21bTestPass!66" \
  --data-urlencode "rise_csrf_token=$TOKEN" \
  -H "X-CSRF-TOKEN: $TOKEN" -o /tmp/auth.html -w "auth=%{http_code}\n"
curl -s -c "$COOKIE" -b "$COOKIE" "http://localhost:8080/index.php/dashboard" -o /tmp/dash.html -w "dash=%{http_code}\n"
echo -n "has_password_form="; grep -c 'name="password"' /tmp/dash.html || true
curl -s -c "$COOKIE" -b "$COOKIE" -o /dev/null -w "css=%{http_code}\n" "http://localhost:8080/assets/css/timesheet-grouped.css"
curl -s -c "$COOKIE" -b "$COOKIE" -o /dev/null -w "js=%{http_code}\n" "http://localhost:8080/assets/js/timesheet-grouped.js"
curl -s -c "$COOKIE" -b "$COOKIE" "http://localhost:8080/index.php/projects/all_timesheets" -o /tmp/ts.html -w "page=%{http_code}\n"
echo -n "setDatePicker_calls="; grep -o 'setDatePicker([^)]*)' /tmp/ts.html | head -5
FROM=$(date -d '30 days ago' +%Y-%m-%d)
TO=$(date +%Y-%m-%d)
TOKEN2=$(awk '/rise_csrf_cookie/{print $NF}' "$COOKIE" | tail -1)
curl -s -c "$COOKIE" -b "$COOKIE" -X POST "http://localhost:8080/index.php/projects/timesheet_grouped_projects" \
  --data-urlencode "start_date=$FROM" \
  --data-urlencode "end_date=$TO" \
  --data-urlencode "rise_csrf_token=$TOKEN2" \
  -H "X-CSRF-TOKEN: $TOKEN2" \
  -H "X-Requested-With: XMLHttpRequest" \
  -o /tmp/api.json -w "api=%{http_code}\n"
head -c 400 /tmp/api.json; echo
php -r '$j=json_decode(file_get_contents("/tmp/api.json"),true); echo "success=".(empty($j["success"])?"0":"1")." rows=".(isset($j["data"])?count($j["data"]):-1)."\n";' 2>/dev/null || /mnt/c/xampp/php/php.exe -r '$j=json_decode(file_get_contents("/tmp/api.json"),true); echo "success=".(empty($j["success"])?"0":"1")." rows=".(isset($j["data"])?count($j["data"]):-1)."\n";'
echo "EMAIL_FOR_BROWSER=$EMAIL"
