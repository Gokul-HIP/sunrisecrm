#!/bin/bash
DUMP='/mnt/c/Users/gokul/Downloads/sunrisecrmnew (3).sql'
echo "DUMP=$DUMP size=$(wc -c < "$DUMP")"

echo ""
echo "=== SETTINGS (phase 17 + review) ==="
grep -n "task_points_review_rules_enabled\|task_points_review_rules_from\|task_review_changes_penalty\|task_review_workflow_enabled" "$DUMP" | head -40

echo ""
echo "=== rise_tasks CREATE / columns ==="
# extract CREATE TABLE for rise_tasks (first 80 lines of block)
awk '/CREATE TABLE.*rise_tasks/,/;/' "$DUMP" | head -120

echo ""
echo "=== task 25879 in dump ==="
grep -n "25879" "$DUMP" | head -30

echo ""
echo "=== phase17 column names present? ==="
for c in review_changes_count first_review_submitted_at timer_lock_json points_rules_locked_at; do
  echo -n "$c: "
  grep -c "$c" "$DUMP" || true
done

echo ""
echo "=== project_time mentions for 25879 ==="
grep -n "25879" "$DUMP" | grep -i "project_time\|INSERT INTO \`rise_project_time\`" | head -20
# also search nearby inserts - extract any line with ,25879, in project_time context
grep -n "INSERT INTO \`rise_project_time\`" "$DUMP" | head -5
