#!/bin/bash
set -e
ROOT=/home/gokul/projects/crm_sunrise
cp "$ROOT/tests/_tmp_cc_fixtures/complex.xlsx" "$ROOT/assets/_tmp_cc_complex.xlsx"
ls -la "$ROOT/assets/_tmp_cc_complex.xlsx"
php "$ROOT/tests/_tmp_p2_db_peek2.php"
php "$ROOT/tests/_tmp_p2_mem_peak.php"
