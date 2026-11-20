#!/bin/bash
cd /home/gokul/projects/crm_sunrise || exit 1
# find base url
grep -E 'baseURL|app\.baseURL' .env app/Config/App.php 2>/dev/null | head -10
echo "---"
curl -s -o /dev/null -w "root:%{http_code}\n" http://127.0.0.1/ || true
curl -s -o /dev/null -w "8099:%{http_code}\n" http://127.0.0.1:8099/ || true
curl -s -o /dev/null -w "sunrise:%{http_code}\n" http://127.0.0.1/crm_sunrise/ || true
ls /mnt/c/xampp/htdocs 2>/dev/null | head
# extract key audit details for report
echo "=== AUDIT SNIPPETS ==="
grep -E 'Kannada|Diwali|Posted|add only|options|view only|manage_options|undo:|dup:|403|Import button|locked|NewCo|Special Days|temp file|expired|perf:' /tmp/cc_p2_audit.out | head -80
echo "=== CSS checks (static) ==="
grep -nE 'cc-wrap|max-width|76px|44px|All types|1 post|posts|78716c|minmax\(240px' assets/css/content-calendar.css | head -40
echo "=== JS add client / import ==="
grep -nE 'addClientModal|openImportWizard|focus trap|cc-add-client|confirm.*name|scroll' assets/js/content-calendar.js | head -30
echo "=== index Import button ==="
grep -nE 'cc-import|cc-add-client|Columns|Import' app/Views/content_calendar/index.php | head -30
