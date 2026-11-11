<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=sunrisecrm_scratch', 'root', '');
$pm = $pdo->query('SELECT user_id FROM rise_project_members WHERE project_id = 349 AND deleted = 0')->fetchAll(PDO::FETCH_COLUMN);
echo "Project 349 members: " . implode(',', $pm) . "\n";

$ns = $pdo->query("SELECT * FROM rise_notification_settings WHERE event = 'recurring_task_created_via_cron_job'")->fetch(PDO::FETCH_ASSOC);
echo "Current notification settings for recurring_task_created_via_cron_job:\n";
print_r($ns);
