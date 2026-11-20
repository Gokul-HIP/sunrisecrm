#!/bin/bash
grep -E 'Diwali|right dates|Kannada title read|temp|imports|undo:|dup:|locked|manage_options|view only|add only|options|Posted|Column H|5001|over 5|rollback|HTTP' /tmp/cc_p2_audit.out | head -80
echo '--- imports dir ---'
ls -la /home/gokul/projects/crm_sunrise/writable/files/content_calendar/imports 2>&1 | head -20
echo '--- audit result ---'
tail -5 /tmp/cc_p2_audit.out
