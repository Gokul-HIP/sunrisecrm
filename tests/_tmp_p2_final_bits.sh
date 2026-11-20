#!/bin/bash
set -e
ROOT=/home/gokul/projects/crm_sunrise
php -m | grep -iE 'zip|mbstring|xml|gd|zlib|intl' || true
echo '--- sweep ---'
grep -n sweep_expired "$ROOT/app/Controllers/Content_calendar.php" | head
echo '--- files dir ---'
ls -la "$ROOT/writable/files/content_calendar/"
ls -la "$ROOT/writable/files/content_calendar/imports/"
echo '--- left menu ---'
git -C "$ROOT" diff -- app/Libraries/Left_menu.php | head -80
echo '--- tabs ---'
git -C "$ROOT" diff -- app/Views/settings/tabs.php | head -40
echo '--- commit check ---'
git -C "$ROOT" status --short | grep -E '^[MADRCU]' | head
git -C "$ROOT" log -1 --oneline
echo '--- php -l import ---'
php -l "$ROOT/app/Libraries/Content_calendar_import.php"
php -l "$ROOT/app/Controllers/Content_calendar.php"
