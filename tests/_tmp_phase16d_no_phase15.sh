#!/usr/bin/env bash
set -euo pipefail
cd /home/gokul/projects/crm_sunrise

LOGDIR=writable/logs
TODAY=$(date +%Y-%m-%d)
LOGFILE=$LOGDIR/log-$TODAY.log
MARK=$(date +%s)
mkdir -p "$LOGDIR"
echo "PHASE16D_P15_PROBE_START_$MARK" >> "$LOGFILE"

mkdir -p /tmp/phase16d_p15_stash
mv -f app/Libraries/Cron_performance_tracker.php /tmp/phase16d_p15_stash/ || true
mv -f app/Libraries/Request_performance_tracker.php /tmp/phase16d_p15_stash/ || true
cp app/Config/Events.php /tmp/phase16d_p15_stash/Events.php.bak

python3 - <<'PY'
from pathlib import Path
import re
p = Path("app/Config/Events.php")
text = p.read_text()
text2, n = re.subn(
    r"\n\s*// Initialize request performance tracking\n\s*if \(class_exists\('\\\\App\\\\Libraries\\\\Request_performance_tracker'\)\) \{\n\s*\\\\App\\\\Libraries\\\\Request_performance_tracker::init\(\);\n\s*\}\n",
    "\n",
    text,
)
if n == 0:
    text2, n = re.subn(
        r"// Initialize request performance tracking.*?Request_performance_tracker::init\(\);\s*\}\n",
        "",
        text,
        flags=re.S,
    )
p.write_text(text2)
print(f"Events.php Phase15 hook removed: {n}")
PY

php -r '
$m = new mysqli("127.0.0.1", "hip_wsl", "HipWsl_ChangeThis123!", "sunrisecrm_scratch");
if ($m->connect_error) { fwrite(STDERR, $m->connect_error."\n"); exit(1); }
$m->query("DROP TABLE IF EXISTS rise_cron_run_log, rise_request_load_minute, rise_slow_queries");
foreach (["rise_cron_run_log","rise_request_load_minute","rise_slow_queries"] as $t) {
  $r = $m->query("SHOW TABLES LIKE '\''".$t."'\''");
  echo $t.": ".(($r && $r->num_rows) ? "EXISTS" : "ABSENT")."\n";
}
'
echo "Phase 15 tables dropped on scratch (see above)"

RESTORE() {
  mv -f /tmp/phase16d_p15_stash/Cron_performance_tracker.php app/Libraries/ 2>/dev/null || true
  mv -f /tmp/phase16d_p15_stash/Request_performance_tracker.php app/Libraries/ 2>/dev/null || true
  if [ -f /tmp/phase16d_p15_stash/Events.php.bak ]; then
    cp /tmp/phase16d_p15_stash/Events.php.bak app/Config/Events.php
  fi
  echo "RESTORED Phase 15 files + Events.php"
}
trap RESTORE EXIT

echo "==== PROBE (no Phase 15) ===="
php tests/_tmp_phase16d_no_phase15_probe.php

echo "==== CLI cron + reminders + tasks ===="
php tests/_tmp_phase16d_no_phase15_cron_cli.php

echo "==== HTTP smoke on local :8081 (class load; DB is app default, not live) ===="
curl -sS -o /tmp/phase16d_tasks.html -w "tasks_http=%{http_code}\n" "http://127.0.0.1:8081/index.php/projects/all_tasks" || echo "tasks_http=FAIL"
curl -sS -o /tmp/phase16d_cron.txt -w "cron_http=%{http_code}\n" "http://127.0.0.1:8081/index.php/cron" || echo "cron_http=FAIL"
curl -sS -o /tmp/phase16d_reminders.txt -w "reminders_http=%{http_code}\n" "http://127.0.0.1:8081/index.php/cron/reminders" || echo "reminders_http=FAIL"
# Show any Class not found / fatal from responses
for f in /tmp/phase16d_tasks.html /tmp/phase16d_cron.txt /tmp/phase16d_reminders.txt; do
  if grep -Eiq 'Class .+ not found|Fatal error|Cron_performance_tracker|Request_performance_tracker' "$f" 2>/dev/null; then
    echo "FATAL_IN $(basename $f):"
    grep -Ei 'Class .+ not found|Fatal error|Cron_performance_tracker|Request_performance_tracker' "$f" | head -5
  else
    echo "$(basename $f): no class-not-found / fatal markers"
  fi
done

echo "==== LOG AFTER MARK ===="
if [[ -f "$LOGFILE" ]]; then
  awk -v m="PHASE16D_P15_PROBE_START_$MARK" '$0 ~ m {p=1} p' "$LOGFILE" | tail -100
else
  echo "(no log file $LOGFILE)"
fi

test -f app/Libraries/Cron_performance_tracker.php
test -f app/Libraries/Request_performance_tracker.php
grep -n "Request_performance_tracker" app/Config/Events.php | head -5
echo "DONE"
