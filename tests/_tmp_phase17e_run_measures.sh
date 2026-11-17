#!/usr/bin/env bash
set -euo pipefail
cd /home/gokul/projects/crm_sunrise
PHP=/mnt/c/xampp/php/php.exe
for mode in cached uncached; do
  for only in ac_x1 ac_x5 tasks_list tasks_in_review; do
    "$PHP" tests/_tmp_phase17e_measure_one.php "--mode=${mode}" "--only=${only}"
  done
done
echo "--- App_Controller cache block ---"
sed -n '103,116p' app/Controllers/App_Controller.php
