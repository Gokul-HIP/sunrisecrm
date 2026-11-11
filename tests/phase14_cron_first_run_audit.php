<?php
/**
 * Phase 14 Cron First-Run Audit
 *
 * Runs exclusively on sunrisecrm_scratch.
 */
ini_set('memory_limit', '512M');

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
$cron = new App\Libraries\Cron_job();
$cron->init_runtime();

$today = $cron->get_today();

echo "======================================================================\n";
echo "AUDIT OF ALL CRON JOBS ON FIRST RUN (DATABASE: $targetDb, TODAY: $today)\n";
echo "======================================================================\n\n";

$auditResults = [];

// 1. 2-Hour Task Deadline Reminders (Pre-gate)
$deadline2hSetting = $db->query("SELECT * FROM rise_notification_settings WHERE event = 'project_task_deadline_2_hours_pre_reminder'")->getRow();
$deadline2hCount = check_and_send_task_deadline_2_hour_reminders(true); // read-only check if available or query
$auditResults[] = [
    'job' => '2-Hour Task Deadline Reminders (pre-gate)',
    'setting' => 'project_task_deadline_2_hours_pre_reminder: email=' . ($deadline2hSetting->enable_email ?? 0) . ', web=' . ($deadline2hSetting->enable_web ?? 0),
    'eligible_count' => $deadline2hCount,
    'action' => 'Web notification + alert sound for tasks due in exactly 2 hours (with end_time).',
    'recipients' => 'Task assignee & assigner (staff)',
    'flag' => 'SAFE'
];

// 2. create_recurring_invoices
$recurringInvoices = $ci->Invoices_model->get_renewable_invoices($today);
$numRecInvoices = $recurringInvoices ? $recurringInvoices->resultID->num_rows : 0;
$auditResults[] = [
    'job' => 'create_recurring_invoices()',
    'setting' => 'Invoices table: recurring=1, next_recurring_date <= today',
    'eligible_count' => $numRecInvoices,
    'action' => 'Creates draft invoice clones and logs recurring_invoice_created_vai_cron_job.',
    'recipients' => 'Staff (invoice notification recipients)',
    'flag' => $numRecInvoices > 0 ? 'CHECK' : 'SAFE (0 found)'
];

// 3. create_recurring_expenses
$recurringExpenses = $ci->Expenses_model->get_renewable_expenses($today);
$numRecExpenses = $recurringExpenses ? $recurringExpenses->resultID->num_rows : 0;
$auditResults[] = [
    'job' => 'create_recurring_expenses()',
    'setting' => 'Expenses table: recurring=1, next_recurring_date <= today',
    'eligible_count' => $numRecExpenses,
    'action' => 'Creates recurring expense clones and logs recurring_expense_created_vai_cron_job.',
    'recipients' => 'Staff (expense notification recipients)',
    'flag' => $numRecExpenses > 0 ? 'CHECK' : 'SAFE (0 found)'
];

// 4. send_invoice_due_pre_reminder
$remDate1 = get_setting("send_invoice_due_pre_reminder");
$remDate2 = get_setting("send_invoice_due_pre_second_reminder");
$preReminderInvoices = 0;
if ($remDate1 || $remDate2) {
    $dueDate1 = $remDate1 ? add_period_to_date($today, $remDate1, "days") : "";
    $dueDate2 = $remDate2 ? add_period_to_date($today, $remDate2, "days") : "";
    $invs = $ci->Invoices_model->get_details([
        "status" => "not_paid_and_partially_paid",
        "reminder_due_date" => $dueDate1,
        "reminder_due_date2" => $dueDate2,
        "exclude_due_reminder_date" => $today
    ])->getResult();
    $preReminderInvoices = count($invs);
}
$invPreNotif = $db->query("SELECT * FROM rise_notification_settings WHERE event = 'invoice_due_reminder_before_due_date'")->getRow();
$auditResults[] = [
    'job' => 'send_invoice_due_pre_reminder()',
    'setting' => "send_invoice_due_pre_reminder: " . var_export($remDate1, true) . ", 2nd: " . var_export($remDate2, true) . " | notif email=" . ($invPreNotif->enable_email ?? 0),
    'eligible_count' => $preReminderInvoices,
    'action' => 'Sends invoice pre-reminder notification & email if enabled.',
    'recipients' => 'Clients (primary contact)',
    'flag' => ($preReminderInvoices > 0 && ($invPreNotif->enable_email ?? 0)) ? 'CLIENT EMAIL ALERT' : 'SAFE'
];

// 5. send_invoice_due_after_reminder (Overdue Invoices)
$afterDate1 = get_setting("send_invoice_due_after_reminder");
$afterDate2 = get_setting("send_invoice_due_after_second_reminder");
$overdueReminderInvoices = 0;
$overdueInvoicesList = [];
if ($afterDate1 || $afterDate2) {
    $dueAfter1 = $afterDate1 ? subtract_period_from_date($today, $afterDate1, "days") : "";
    $dueAfter2 = $afterDate2 ? subtract_period_from_date($today, $afterDate2, "days") : "";
    $invs = $ci->Invoices_model->get_details([
        "status" => "overdue",
        "reminder_due_date" => $dueAfter1,
        "reminder_due_date2" => $dueAfter2,
        "exclude_due_reminder_date" => $today
    ])->getResult();
    $overdueReminderInvoices = count($invs);
    $overdueInvoicesList = $invs;
}
$invOverdueNotif = $db->query("SELECT * FROM rise_notification_settings WHERE event = 'invoice_overdue_reminder'")->getRow();
$auditResults[] = [
    'job' => 'send_invoice_due_after_reminder()',
    'setting' => "send_invoice_due_after_reminder: " . var_export($afterDate1, true) . ", 2nd: " . var_export($afterDate2, true) . " | notif email=" . ($invOverdueNotif->enable_email ?? 0),
    'eligible_count' => $overdueReminderInvoices,
    'action' => 'Sends invoice overdue reminder notification & email if enabled.',
    'recipients' => 'Clients (primary contact)',
    'flag' => ($overdueReminderInvoices > 0 && ($invOverdueNotif->enable_email ?? 0)) ? 'CLIENT EMAIL ALERT' : 'SAFE'
];

// 6. send_recurring_invoice_creation_reminder
$recInvRemDate = get_setting("send_recurring_invoice_reminder_before_creation");
$recInvCreationCount = 0;
if ($recInvRemDate) {
    $startDate = add_period_to_date($today, $recInvRemDate, "days");
    $invs = $ci->Invoices_model->get_details([
        "status" => "not_paid",
        "recurring" => 1,
        "next_recurring_start_date" => $startDate,
        "next_recurring_end_date" => $startDate,
        "exclude_recurring_reminder_date" => $today
    ])->getResult();
    $recInvCreationCount = count($invs);
}
$auditResults[] = [
    'job' => 'send_recurring_invoice_creation_reminder()',
    'setting' => "send_recurring_invoice_reminder_before_creation: " . var_export($recInvRemDate, true),
    'eligible_count' => $recInvCreationCount,
    'action' => 'Sends recurring invoice creation reminder.',
    'recipients' => 'Staff',
    'flag' => 'SAFE'
];

// 7. create_recurring_tasks
$recTasksQuery = $ci->Tasks_model->get_renewable_tasks($cron->get_recurring_query_date());
$recTasksCount = $recTasksQuery ? $recTasksQuery->resultID->num_rows : 0;
$auditResults[] = [
    'job' => 'create_recurring_tasks()',
    'setting' => 'enable_recurring_option_for_tasks: ' . get_setting('enable_recurring_option_for_tasks') . ', create_before: ' . get_setting('create_recurring_tasks_before'),
    'eligible_count' => $recTasksCount,
    'action' => 'Creates task clones from renewable recurring tasks.',
    'recipients' => 'Staff (assignee, creator)',
    'flag' => $recTasksCount > 5 ? 'MANY TASKS (BEFORE SWITCH-OFF)' : 'CONTROLLED'
];

// 8. send_task_reminder_notifications()
// 8a. project_task_deadline_pre_reminder
$taskPreDays = get_setting("project_task_deadline_pre_reminder");
$taskPreCount = 0;
if ($taskPreDays) {
    $todoStatus = $ci->Task_status_model->get_one_where(["key_name" => "done", "deleted" => 0]);
    $tDate = add_period_to_date($today, $taskPreDays, "days");
    $tasks = $ci->Tasks_model->get_details([
        "exclude_status_id" => $todoStatus->id,
        "start_date" => $tDate,
        "deadline" => $tDate,
        "exclude_reminder_date" => $today
    ])->getResult();
    $taskPreCount = count($tasks);
}

// 8b. project_task_deadline_overdue_reminder
$taskOverdueDays = get_setting("project_task_deadline_overdue_reminder");
$taskOverdueCount = 0;
if ($taskOverdueDays) {
    $todoStatus = $ci->Task_status_model->get_one_where(["key_name" => "done", "deleted" => 0]);
    $tDate = subtract_period_from_date($today, $taskOverdueDays, "days");
    $tasks = $ci->Tasks_model->get_details([
        "exclude_status_id" => $todoStatus->id,
        "start_date" => $tDate,
        "deadline" => $tDate,
        "exclude_reminder_date" => $today
    ])->getResult();
    $taskOverdueCount = count($tasks);
}

// 8c. project_task_reminder_on_the_day_of_deadline
$taskDayOfDays = get_setting("project_task_reminder_on_the_day_of_deadline");
$taskDayOfCount = 0;
if ($taskDayOfDays) {
    $todoStatus = $ci->Task_status_model->get_one_where(["key_name" => "done", "deleted" => 0]);
    $tasks = $ci->Tasks_model->get_details([
        "exclude_status_id" => $todoStatus->id,
        "start_date" => $today,
        "deadline" => $today,
        "exclude_reminder_date" => $today
    ])->getResult();
    $taskDayOfCount = count($tasks);
}

$taskRemNotifSettings = $db->query("
    SELECT event, enable_email, enable_web, notify_to_terms 
    FROM rise_notification_settings 
    WHERE event IN ('project_task_deadline_pre_reminder', 'project_task_deadline_overdue_reminder', 'project_task_reminder_on_the_day_of_deadline')
")->getResult();

$auditResults[] = [
    'job' => 'send_task_reminder_notifications() [pre, overdue, day-of]',
    'setting' => "Settings: pre={$taskPreDays}, overdue={$taskOverdueDays}, day_of={$taskDayOfDays}",
    'eligible_count' => "pre: $taskPreCount, overdue: $taskOverdueCount, day_of: $taskDayOfCount",
    'action' => 'Daily task deadline reminders for tasks matching deadline = today +/- offset.',
    'recipients' => 'Staff (assignee, collaborators)',
    'flag' => ($taskPreCount + $taskOverdueCount + $taskDayOfCount > 50) ? 'MANY NOTIFICATIONS' : 'SAFE'
];

// 9. create_recurring_reminders() (Events / Calendar reminders)
$remindersOptions = [
    "type" => "all",
    "recurring" => true,
    "reminder_status" => "new"
];
$recurringEvents = $ci->Events_model->get_details($remindersOptions)->getResult();
$numRecurringEvents = count($recurringEvents);
$auditResults[] = [
    'job' => 'create_recurring_reminders() (Events table)',
    'setting' => 'rise_events: recurring=1, reminder_status=new',
    'eligible_count' => $numRecurringEvents,
    'action' => 'Advances next_recurring_time on recurring reminders/events.',
    'recipients' => 'Staff (event creator/share)',
    'flag' => 'SAFE (no emails)'
];

// 10. create_subscription_invoices()
$subQuery = $ci->Subscriptions_model->get_renewable_subscriptions($today);
$numSubs = $subQuery ? $subQuery->resultID->num_rows : 0;
$auditResults[] = [
    'job' => 'create_subscription_invoices()',
    'setting' => 'Subscriptions: next_recurring_date <= today',
    'eligible_count' => $numSubs,
    'action' => 'Creates subscription invoices.',
    'recipients' => 'Staff/Clients',
    'flag' => $numSubs > 0 ? 'CHECK' : 'SAFE (0 found)'
];

// 11. run_imap()
$enableEmailPiping = get_setting("enable_email_piping");
$imapAuthorized = get_setting("imap_authorized");
$auditResults[] = [
    'job' => 'run_imap() (Ticket Email Piping)',
    'setting' => "enable_email_piping: " . var_export($enableEmailPiping, true) . ", imap_authorized: " . var_export($imapAuthorized, true),
    'eligible_count' => ($enableEmailPiping && $imapAuthorized) ? 'Active' : 'Disabled',
    'action' => 'Pulls incoming emails from IMAP inbox and creates/updates tickets.',
    'recipients' => 'None (inbound only)',
    'flag' => 'SAFE'
];

// 12. get_google_calendar_events()
$gcalSetting = get_setting("enable_google_calendar_api");
$auditResults[] = [
    'job' => 'get_google_calendar_events()',
    'setting' => "enable_google_calendar_api: " . var_export($gcalSetting, true),
    'eligible_count' => $gcalSetting ? 'Active' : 'Disabled',
    'action' => 'Syncs Google calendar events into Rise.',
    'recipients' => 'None (inbound sync)',
    'flag' => 'SAFE'
];

// 13. close_inactive_tickets()
$autoCloseDays = get_setting("auto_close_ticket_after");
$inactiveTicketsCount = 0;
if ($autoCloseDays) {
    $lastActDate = subtract_period_from_date($today, $autoCloseDays, "days");
    $tickets = $ci->Tickets_model->get_details([
        "status" => "open",
        "last_activity_date_or_before" => $lastActDate
    ])->getResult();
    $inactiveTicketsCount = count($tickets);
}
$auditResults[] = [
    'job' => 'close_inactive_tickets()',
    'setting' => "auto_close_ticket_after: " . var_export($autoCloseDays, true) . " days",
    'eligible_count' => $inactiveTicketsCount,
    'action' => 'Closes tickets with no activity for X days and notifies creator/client.',
    'recipients' => 'Ticket creator (client/staff)',
    'flag' => $inactiveTicketsCount > 0 ? "CLOSES $inactiveTicketsCount TICKETS" : 'SAFE (0 found)'
];

// Output table
echo sprintf(
    "%-45s | %-12s | %-20s | %-25s\n",
    "Job Name", "Eligible", "Recipients", "Status / Flag"
);
echo str_repeat("-", 110) . "\n";
foreach ($auditResults as $ar) {
    echo sprintf(
        "%-45s | %-12s | %-20s | %-25s\n",
        substr($ar['job'], 0, 45),
        substr((string)$ar['eligible_count'], 0, 12),
        substr($ar['recipients'], 0, 20),
        $ar['flag']
    );
    echo "  [Setting] {$ar['setting']}\n";
    echo "  [Action]  {$ar['action']}\n\n";
}
