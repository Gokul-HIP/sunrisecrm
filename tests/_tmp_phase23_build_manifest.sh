#!/usr/bin/env bash
set -euo pipefail
cd /home/gokul/projects/crm_sunrise
OUT=writable/deploy_manifest.txt
TMP=$(mktemp)
# Phase 16 (In review) through Phase 22 tip
git diff --name-only 'db28efe^..HEAD' > "$TMP"
# Also include created-date filter / task UI files that predate that range but are part of Tasks page parity
EXTRA=(
  app/Views/projects/tasks/created_date_apply_filter_js.php
  app/Views/projects/tasks/my_tasks.php
  app/Views/projects/tasks/task_status_counts.php
  app/Views/projects/tasks/monthly_points_widget_js.php
  app/Views/projects/tasks/monthly_points_widget.php
  app/Models/Tasks_model.php
  app/Controllers/Security_Controller.php
  assets/js/tasks-ui.js
  assets/css/tasks-ui.css
)
for f in "${EXTRA[@]}"; do
  if [[ -f "$f" ]]; then
    echo "$f" >> "$TMP"
  fi
done
sort -u "$TMP" | while read -r f; do
  case "$f" in
    tests/*|database/*|writable/*|system/*|app/Config/Database.php) continue ;;
  esac
  if [[ -f "$f" ]]; then
    echo "$f"
  fi
done | sort -u > "${TMP}.files"

: > "$OUT"
while read -r f; do
  hash=$(sha256sum "$f" | awk '{print $1}')
  printf '%s  %s\n' "$hash" "$f" >> "$OUT"
done < "${TMP}.files"
wc -l "$OUT"
rm -f "$TMP" "${TMP}.files"
