#!/bin/bash
cd /home/gokul/projects/crm_sunrise || exit 1
for f in app/Controllers/Content_calendar.php app/Libraries/Content_calendar_import.php app/Models/Cc_import_logs_model.php app/Models/Cc_items_model.php app/Helpers/content_calendar_helper.php app/Language/english/custom_lang.php app/Views/content_calendar/index.php app/Views/content_calendar/item_form.php tests/_tmp_content_calendar_phase2_audit.php tests/_tmp_cc_build_fixtures.php tests/_tmp_content_calendar_audit.php; do
  php -l "$f"
done
