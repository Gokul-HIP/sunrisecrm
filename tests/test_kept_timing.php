<?php
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

echo "======================================================================\n";
echo "TESTING KEPT TASKS: 25352 - 25356\n";
echo "======================================================================\n\n";

// Let's inspect task 25352 before cron
$t = $db->query("SELECT * FROM rise_tasks WHERE id = 25352")->getRow();
echo "Task #25352 Before:\n";
echo "  start_date: {$t->start_date}, deadline: {$t->deadline}, end_time: {$t->end_time}\n";
echo "  next_recurring_date: {$t->next_recurring_date}, cycles: {$t->no_of_cycles_completed}/{$t->no_of_cycles}\n";
echo "  status_id: {$t->status_id}, assigned_to: {$t->assigned_to}, created_by: {$t->created_by}\n\n";

// 1. Run calculate_next_clone with today = 2026-11-11
$cron = new App\Libraries\Cron_job();
$cron->init_runtime();

$refToday = new ReflectionProperty($cron, 'today');
$refToday->setAccessible(true);
$refToday->setValue($cron, '2026-11-11');

$calc11 = $cron->calculate_next_clone($t);
echo "calculate_next_clone with clock at 2026-11-11:\n";
echo "  clone_start_date: {$calc11['clone_start_date']}\n";
echo "  clone_deadline: {$calc11['clone_deadline']}\n";
echo "  is_due_now: " . ($calc11['is_due_now'] ? 'true' : 'false') . "\n";
echo "  skipped_overdue_cycles: {$calc11['skipped_overdue_cycles']}\n";
echo "  no_of_cycles_completed: {$calc11['no_of_cycles_completed']}\n";
echo "  no_of_cycles_completed_after: {$calc11['no_of_cycles_completed_after']}\n";
echo "  next_recurring_date_after: {$calc11['next_recurring_date_after']}\n\n";

// 2. What create_clone_from_recurring_task does on 2026-11-11:
$item11 = $cron->create_clone_from_recurring_task($t);
echo "create_clone_from_recurring_task result on 2026-11-11:\n";
echo "  status: {$item11['status']}\n";
echo "  reason: {$item11['reason']}\n";
echo "  clone_task_id: " . var_export($item11['clone_task_id'], true) . "\n";

// Check parent after run
$tAfter11 = $db->query("SELECT next_recurring_date, no_of_cycles_completed FROM rise_tasks WHERE id = 25352")->getRow();
echo "Parent #25352 in DB after 2026-11-11 run:\n";
echo "  next_recurring_date: {$tAfter11->next_recurring_date}\n";
echo "  no_of_cycles_completed: {$tAfter11->no_of_cycles_completed}\n\n";

// 3. Now test with clock at 2026-11-12
$refToday->setValue($cron, '2026-11-12');
$tFor12 = $db->query("SELECT * FROM rise_tasks WHERE id = 25352")->getRow();
$calc12 = $cron->calculate_next_clone($tFor12);
echo "calculate_next_clone with clock at 2026-11-12:\n";
echo "  clone_start_date: {$calc12['clone_start_date']}\n";
echo "  is_due_now: " . ($calc12['is_due_now'] ? 'true' : 'false') . "\n";

// 4. Now test what happens on 2026-11-16
$refToday->setValue($cron, '2026-11-16');
$tFor16 = $db->query("SELECT * FROM rise_tasks WHERE id = 25352")->getRow();
$calc16 = $cron->calculate_next_clone($tFor16);
echo "\ncalculate_next_clone with clock at 2026-11-16:\n";
echo "  clone_start_date: {$calc16['clone_start_date']}\n";
echo "  clone_deadline: {$calc16['clone_deadline']}\n";
echo "  is_due_now: " . ($calc16['is_due_now'] ? 'true' : 'false') . "\n";

$item16 = $cron->create_clone_from_recurring_task($tFor16);
echo "create_clone_from_recurring_task result on 2026-11-16:\n";
echo "  status: {$item16['status']}\n";
echo "  clone_task_id: {$item16['clone_task_id']}\n";
if ($item16['clone_task_id']) {
    $clone = $db->query("SELECT * FROM rise_tasks WHERE id = {$item16['clone_task_id']}")->getRow();
    echo "  Clone in DB:\n";
    echo "    id: {$clone->id}\n";
    echo "    title: {$clone->title}\n";
    echo "    start_date: {$clone->start_date}\n";
    echo "    deadline: {$clone->deadline}\n";
    echo "    end_time: {$clone->end_time}\n";
    echo "    status_id: {$clone->status_id}\n";
    echo "    assigned_to: {$clone->assigned_to}\n";
    echo "    created_by: {$clone->created_by}\n";
    echo "    recurring: {$clone->recurring}\n";
    echo "    parent_task_id: {$clone->parent_task_id}\n";
    echo "    recurring_task_id: {$clone->recurring_task_id}\n";
    
    $parentAfter16 = $db->query("SELECT next_recurring_date, no_of_cycles_completed FROM rise_tasks WHERE id = 25352")->getRow();
    echo "  Parent after 2026-11-16 clone:\n";
    echo "    next_recurring_date: {$parentAfter16->next_recurring_date}\n";
    echo "    no_of_cycles_completed: {$parentAfter16->no_of_cycles_completed}\n";
}
