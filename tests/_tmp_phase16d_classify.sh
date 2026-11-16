#!/usr/bin/env bash
cd /home/gokul/projects/crm_sunrise
for f in \
  app/Config/Routes.php \
  app/Helpers/general_helper.php \
  app/Views/cron/dashboard.php \
  app/Views/projects/comments/comment_list.php \
  app/Views/includes/left_menu.php \
  assets/js/app.js \
  tests/check_task_points_invariant.php
do
  echo "==== $f ===="
  git diff 1449be8 --stat -- "$f"
  git diff 1449be8 -- "$f" | head -40
done
