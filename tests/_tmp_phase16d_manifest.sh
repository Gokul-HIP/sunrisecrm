#!/usr/bin/env bash
cd /home/gokul/projects/crm_sunrise
echo "=== restored trackers ==="
ls -la app/Libraries/Cron_performance_tracker.php app/Libraries/Request_performance_tracker.php
grep -n Request_performance_tracker app/Config/Events.php | head -3

echo "=== shared file stats vs 1449be8 ==="
git diff 1449be8 --stat -- \
  app/Libraries/Cron_job.php \
  app/Controllers/Cron.php \
  app/Controllers/Notifications.php \
  app/Helpers/notifications_helper.php \
  app/Config/Events.php

echo "=== all changed/new names (excl writable/Database/system) ==="
git status --porcelain | grep -vE 'writable/|app/Config/Database.php|^.. system/' 

echo "=== untracked phase16 ==="
git ls-files --others --exclude-standard | grep -E 'phase16|tasks_in_review|test_phase16' || true
