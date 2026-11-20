#!/bin/bash
cd /home/gokul/projects/crm_sunrise
echo "=== FILES ==="
ls -la docs/content_calendar.sql docs/content_calendar_sql.md 2>&1
ls -la app/Libraries/Content_calendar_import.php app/Models/Cc_import_logs_model.php 2>&1
ls app/Views/content_calendar/
echo "=== CONTROLLER METHODS ==="
grep -nE 'function (import_|add_client|save_client)' app/Controllers/Content_calendar.php
echo "=== SQL MARKERS ==="
grep -nE 'import_batch|cc_import_logs|PREPARE|INFORMATION_SCHEMA' docs/content_calendar.sql | head -50
echo "=== JS IMPORT / ADD CLIENT ==="
grep -nE 'import|addClient|Add client|openImport' assets/js/content-calendar.js | head -40
echo "=== CSS WRAPPER ==="
grep -nE 'cc-wrap|cc-page|44px|76px|All types|posts' assets/css/content-calendar.css | head -40
echo "=== EXISTING TESTS ==="
ls -la tests/_tmp_content_calendar* tests/_tmp_cc_* 2>&1 | head -40
echo "=== LINE COUNTS ==="
wc -l assets/js/content-calendar.js assets/css/content-calendar.css app/Controllers/Content_calendar.php
