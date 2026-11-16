#!/usr/bin/env bash
cd /home/gokul/projects/crm_sunrise
pkill -f '_tmp_phase16d_no_phase15' 2>/dev/null || true
pkill -f 'phase16d_no_phase15_probe' 2>/dev/null || true
pkill -f 'phase16d_no_phase15_cron' 2>/dev/null || true
sleep 1
ls -la /tmp/phase16d_p15_stash/ || true
if [ -f /tmp/phase16d_p15_stash/Cron_performance_tracker.php ]; then
  mv -f /tmp/phase16d_p15_stash/Cron_performance_tracker.php app/Libraries/
fi
if [ -f /tmp/phase16d_p15_stash/Request_performance_tracker.php ]; then
  mv -f /tmp/phase16d_p15_stash/Request_performance_tracker.php app/Libraries/
fi
if [ -f /tmp/phase16d_p15_stash/Events.php.bak ]; then
  cp /tmp/phase16d_p15_stash/Events.php.bak app/Config/Events.php
fi
echo "=== tracker files ==="
ls -la app/Libraries/Cron_performance_tracker.php app/Libraries/Request_performance_tracker.php
echo "=== Events hook ==="
grep -n "Request_performance_tracker\|Initialize request" app/Config/Events.php | head -5
echo "=== php processes ==="
ps aux | grep php | grep -v grep | head -20 || true
