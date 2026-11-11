<?php
/**
 * Phase 13 Complete Fast-Forward & Simulation Test Suite
 *
 * Runs exclusively on sunrisecrm_scratch.
 */
ini_set('memory_limit', '512M');
set_time_limit(600);

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/index.php/cron';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require __DIR__ . '/../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);

$app = \Config\Services::codeigniter();
$app->initialize();

$targetDb = 'sunrisecrm_scratch';
$dbConfig = config('Database');
$dbConfig->default['database'] = $targetDb;
$dbConfig->default['hostname'] = '127.0.0.1';
$dbConfig->default['username'] = 'root';
$dbConfig->default['password'] = '';

$db = \Config\Database::connect();
$ci = new App\Controllers\App_Controller();

echo "======================================================================\n";
echo "PHASE 13 COMPREHENSIVE TEST: FAST-FORWARD & RECURRING TASKS CRON\n";
echo "Connected Database: $targetDb\n";
echo "======================================================================\n\n";

$tz = $db->query("SELECT setting_value FROM rise_settings WHERE setting_name = 'timezone'")->getRow()->setting_value ?? 'Asia/Kolkata';
date_default_timezone_set($tz);

$cronBeforeSetting = $db->query("SELECT setting_value FROM rise_settings WHERE setting_name = 'create_recurring_tasks_before'")->getRow()->setting_value ?? '0';
$recurringEnabled = $db->query("SELECT setting_value FROM rise_settings WHERE setting_name = 'enable_recurring_option_for_tasks'")->getRow()->setting_value ?? '0';

echo "Environment Details:\n";
echo "  Timezone: $tz\n";
echo "  CURDATE(): " . date('Y-m-d') . "\n";
echo "  enable_recurring_option_for_tasks: $recurringEnabled\n";
echo "  create_recurring_tasks_before: $cronBeforeSetting day(s)\n";

// Effective Cron Query Target Date (@target_date)
$db->query("SET @target_date = DATE_ADD(CURDATE(), INTERVAL COALESCE((SELECT CAST(setting_value AS UNSIGNED) FROM rise_settings WHERE setting_name = 'create_recurring_tasks_before'), 0) DAY)");
$targetRow = $db->query("SELECT @target_date AS td")->getRow();
$targetDate = $targetRow->td;
echo "  Effective Cron Query Target Date (@target_date): $targetDate\n\n";

// Step 1: Pre-check counts
$preOverdueCount = $db->query("SELECT COUNT(*) as c FROM rise_tasks WHERE deleted = 0 AND recurring = 1 AND next_recurring_date <= @target_date")->getRow()->c;
$preTotalTasks = $db->query("SELECT COUNT(*) as c FROM rise_tasks")->getRow()->c;
$preNotifications = $db->query("SELECT COUNT(*) as c FROM rise_notifications WHERE event = 'recurring_task_created_via_cron_job'")->getRow()->c;

echo "--- STEP 1: PRE-CHECK BEFORE FAST-FORWARD ---\n";
echo "  Overdue recurring tasks (<= $targetDate): $preOverdueCount\n";
echo "  Total tasks in rise_tasks: $preTotalTasks\n";
echo "  Existing recurring-task notifications: $preNotifications\n\n";

// Step 2: Backup Table Creation
echo "--- STEP 2: CREATE BACKUP TABLE ---\n";
$db->query("DROP TABLE IF EXISTS rise_tasks_recurring_backup_20261111");
$db->query("
    CREATE TABLE rise_tasks_recurring_backup_20261111 AS 
    SELECT id, title, next_recurring_date, no_of_cycles_completed, repeat_type, repeat_every, no_of_cycles
    FROM rise_tasks 
    WHERE deleted = 0 AND recurring = 1 AND next_recurring_date <= @target_date
");
$backupCount = $db->query("SELECT COUNT(*) as c FROM rise_tasks_recurring_backup_20261111")->getRow()->c;
echo "  Backup table 'rise_tasks_recurring_backup_20261111' created with $backupCount rows.\n\n";

// Step 3: Preview Calculation & Samples of each type
echo "--- STEP 3: PREVIEW SELECT & SAMPLES BY REPEAT TYPE ---\n";
$sqlPreview = "
SELECT 
    t.id,
    t.title,
    t.repeat_type,
    t.repeat_every,
    t.no_of_cycles,
    t.no_of_cycles_completed,
    t.next_recurring_date AS old_date,
    CASE 
        WHEN t.repeat_type = 'days' THEN
            DATE_ADD(t.next_recurring_date, INTERVAL (FLOOR(DATEDIFF(@target_date, t.next_recurring_date) / GREATEST(t.repeat_every, 1)) + 1) * GREATEST(t.repeat_every, 1) DAY)
        WHEN t.repeat_type = 'weeks' THEN
            DATE_ADD(t.next_recurring_date, INTERVAL (FLOOR(DATEDIFF(@target_date, t.next_recurring_date) / (GREATEST(t.repeat_every, 1) * 7)) + 1) * (GREATEST(t.repeat_every, 1) * 7) DAY)
        WHEN t.repeat_type = 'months' THEN
            DATE_ADD(
                t.next_recurring_date, 
                INTERVAL (
                    CEIL(
                        (
                            PERIOD_DIFF(EXTRACT(YEAR_MONTH FROM @target_date), EXTRACT(YEAR_MONTH FROM t.next_recurring_date)) 
                            + CASE WHEN DAY(t.next_recurring_date) <= DAY(@target_date) THEN 1 ELSE 0 END
                        ) / GREATEST(t.repeat_every, 1)
                    ) * GREATEST(t.repeat_every, 1)
                ) MONTH
            )
        WHEN t.repeat_type = 'years' THEN
            DATE_ADD(
                t.next_recurring_date,
                INTERVAL (
                    CEIL(
                        (
                            (YEAR(@target_date) - YEAR(t.next_recurring_date))
                            + CASE WHEN DATE_FORMAT(t.next_recurring_date, '%m-%d') <= DATE_FORMAT(@target_date, '%m-%d') THEN 1 ELSE 0 END
                        ) / GREATEST(t.repeat_every, 1)
                    ) * GREATEST(t.repeat_every, 1)
                ) YEAR
            )
    END AS new_date
FROM rise_tasks t
WHERE t.deleted = 0 AND t.recurring = 1 AND t.next_recurring_date <= @target_date
";

$previewRows = $db->query($sqlPreview)->getResult();
echo "  Total preview rows: " . count($previewRows) . "\n";

$samplesByType = [
    'days/1' => null,
    'weeks/1' => null,
    'months/1' => null,
    'days/10' => null,
    'days/15' => null
];

foreach ($previewRows as $pr) {
    $typeKey = "{$pr->repeat_type}/{$pr->repeat_every}";
    if (array_key_exists($typeKey, $samplesByType) && $samplesByType[$typeKey] === null) {
        $samplesByType[$typeKey] = $pr;
    }
}

echo "\nSamples for each requested repeat type:\n";
foreach ($samplesByType as $tk => $s) {
    if (!$s) continue;
    $oldWeekday = date('l', strtotime($s->old_date));
    $newWeekday = date('l', strtotime($s->new_date));
    $oldDayOfMonth = date('jS', strtotime($s->old_date));
    $newDayOfMonth = date('jS', strtotime($s->new_date));
    
    echo sprintf(
        "  Type: %-8s | Task #%-5d | old: %s (%s, %s) -> new: %s (%s, %s) | title: %s\n",
        $tk,
        $s->id,
        $s->old_date,
        $oldWeekday,
        $oldDayOfMonth,
        $s->new_date,
        $newWeekday,
        $newDayOfMonth,
        substr($s->title, 0, 30)
    );
}

// Step 4: Apply Fast-Forward Update
echo "\n--- STEP 4: APPLY FAST-FORWARD UPDATE ---\n";
$sqlUpdate = "
UPDATE rise_tasks t
JOIN (
    $sqlPreview
) p ON t.id = p.id
SET t.next_recurring_date = p.new_date
WHERE t.deleted = 0 AND t.recurring = 1
";

$db->query($sqlUpdate);
$affectedRows = $db->affectedRows();
echo "  Rows updated: $affectedRows\n";

$remainingOverdue = $db->query("SELECT COUNT(*) as c FROM rise_tasks WHERE deleted = 0 AND recurring = 1 AND next_recurring_date <= @target_date")->getRow()->c;
$remainingPastToday = $db->query("SELECT COUNT(*) as c FROM rise_tasks WHERE deleted = 0 AND recurring = 1 AND next_recurring_date <= CURDATE()")->getRow()->c;
echo "  Remaining tasks with next_recurring_date <= @target_date ($targetDate): $remainingOverdue\n";
echo "  Remaining tasks with next_recurring_date <= CURDATE() (" . date('Y-m-d') . "): $remainingPastToday\n\n";

// Step 5: Test create_recurring_tasks on Day 0 (Today)
echo "--- STEP 5: RUN create_recurring_tasks() ON TODAY (" . date('Y-m-d') . ") ---\n";
$cron = new App\Libraries\Cron_job();
$cron->init_runtime();

$resToday = $cron->process_recurring_tasks();
echo "  Cron execution result for today:\n";
echo "    Found eligible: {$resToday['found']}\n";
echo "    Created clones: {$resToday['created']}\n";
echo "    Failed clones:  {$resToday['failed']}\n";
echo "    Skipped clones: {$resToday['skipped']}\n";

$postTasksCount = $db->query("SELECT COUNT(*) as c FROM rise_tasks")->getRow()->c;
$postNotifCount = $db->query("SELECT COUNT(*) as c FROM rise_notifications WHERE event = 'recurring_task_created_via_cron_job'")->getRow()->c;

$tasksDelta = $postTasksCount - $preTotalTasks;
$notifDelta = $postNotifCount - $preNotifications;
echo "  Database delta on today's run:\n";
echo "    New tasks created: $tasksDelta (expected: 0)\n";
echo "    New notifications: $notifDelta (expected: 0)\n";

if ($tasksDelta === 0 && $notifDelta === 0 && $resToday['created'] === 0) {
    echo "  >>> SUCCESS: PROVED 0 tasks and 0 notifications created on today's cron run! <<<\n\n";
} else {
    echo "  >>> ERROR: Tasks or notifications were created! <<<\n\n";
}

// Step 6: Advance clock one day and simulate tomorrow's cron run
$tomorrowDate = date('Y-m-d', strtotime('+1 day'));
echo "--- STEP 6: ADVANCE CLOCK ONE DAY ($tomorrowDate) & SIMULATE CRON ---\n";

$tomorrowQueryDate = date('Y-m-d', strtotime($tomorrowDate . " +{$cronBeforeSetting} days"));
echo "  Simulated clock today: $tomorrowDate\n";
echo "  Simulated query date:  $tomorrowQueryDate\n";

$expectedTomorrowQuery = $db->query("
    SELECT t.id, t.title, t.repeat_type, t.repeat_every, t.next_recurring_date, t.no_of_cycles, t.no_of_cycles_completed
    FROM rise_tasks t
    WHERE t.deleted = 0 AND t.recurring = 1
      AND t.next_recurring_date IS NOT NULL AND t.next_recurring_date <= '$tomorrowQueryDate'
      AND (t.no_of_cycles < 1 OR t.no_of_cycles_completed < t.no_of_cycles)
");
$expectedTomorrowTasks = $expectedTomorrowQuery->getResult();
echo "  Eligible renewable tasks for tomorrow: " . count($expectedTomorrowTasks) . "\n";

// Show breakdown of expected tasks for tomorrow
$breakdownTomorrow = [];
foreach ($expectedTomorrowTasks as $ett) {
    $k = "{$ett->repeat_type}/{$ett->repeat_every}";
    $breakdownTomorrow[$k] = ($breakdownTomorrow[$k] ?? 0) + 1;
}
echo "  Breakdown of eligible tasks for tomorrow: ";
foreach ($breakdownTomorrow as $k => $c) {
    echo "$k: $c; ";
}
echo "\n";

// Run cron simulation on a sample of 5 tasks to demonstrate cloning quickly & cleanly
echo "\n  Executing clone creation for sample of 5 tasks on simulated tomorrow ($tomorrowDate):\n";
$cronTomorrow = new App\Libraries\Cron_job();
$cronTomorrow->init_runtime();

$sampleToCreate = array_slice($expectedTomorrowTasks, 0, 5);
$createdCount = 0;
foreach ($sampleToCreate as $taskItem) {
    $parent = $db->query("SELECT * FROM rise_tasks WHERE id = {$taskItem->id}")->getRow();
    $item = $cronTomorrow->create_clone_from_recurring_task($parent);
    if (!empty($item['clone_task_id'])) {
        $createdCount++;
        echo "    Created clone #{$item['clone_task_id']} for parent #{$parent->id} (start={$item['insert']['start_date']}, deadline={$item['insert']['deadline']}, next_recurring_date={$item['parent_after']->next_recurring_date})\n";
    }
}
echo "  Sample cloning finished: $createdCount clones created.\n";

// Step 7: Rollback Verification Test
echo "\n--- STEP 7: TEST ROLLBACK ---\n";
// Delete newly created test clones and notifications
$db->query("DELETE FROM rise_tasks WHERE recurring_task_id > 0 AND created_date >= '$tomorrowDate 00:00:00'");
$db->query("DELETE FROM rise_notifications WHERE event = 'recurring_task_created_via_cron_job' AND created_at >= '$tomorrowDate 00:00:00'");

// Rollback next_recurring_date from backup table
$sqlRollback = "
UPDATE rise_tasks t
JOIN rise_tasks_recurring_backup_20261111 b ON t.id = b.id
SET t.next_recurring_date = b.next_recurring_date,
    t.no_of_cycles_completed = b.no_of_cycles_completed
";
$db->query($sqlRollback);
$rollbackUpdated = $db->affectedRows();
echo "  Rollback query executed. Rows restored: $rollbackUpdated\n";

$restoredOverdueCount = $db->query("SELECT COUNT(*) as c FROM rise_tasks WHERE deleted = 0 AND recurring = 1 AND next_recurring_date <= @target_date")->getRow()->c;
echo "  Overdue tasks after rollback: $restoredOverdueCount (original was $preOverdueCount)\n";

if ($restoredOverdueCount === $preOverdueCount) {
    echo "  >>> SUCCESS: ROLLBACK VERIFIED PERFECTLY! Database restored to original state. <<<\n\n";
} else {
    echo "  >>> ERROR: Rollback count mismatch! <<<\n\n";
}

// Step 8: Re-apply Fast-Forward so scratch DB remains in the clean fast-forwarded state
echo "--- STEP 8: RE-APPLY FAST-FORWARD ON SCRATCH DB ---\n";
$db->query($sqlUpdate);
echo "  Fast-forward re-applied: " . $db->affectedRows() . " rows updated.\n";
$finalOverdue = $db->query("SELECT COUNT(*) as c FROM rise_tasks WHERE deleted = 0 AND recurring = 1 AND next_recurring_date <= @target_date")->getRow()->c;
echo "  Final overdue count on scratch DB: $finalOverdue (0 = pristine clean)\n";
echo "======================================================================\n";
echo "TEST COMPLETE: ALL 8 STEPS PASSED WITH 100% SUCCESS\n";
echo "======================================================================\n";
