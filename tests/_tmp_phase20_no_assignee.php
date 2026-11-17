<?php
ini_set('memory_limit', '256M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);

$app = \Config\Services::codeigniter();
$app->initialize();
\Config\Services::session()->set('user_id', 66);
date_default_timezone_set(get_setting('timezone') ?: 'Asia/Kolkata');

function strip_cell($html) {
    $t = html_entity_decode(strip_tags((string)$html), ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\s+/', ' ', $t));
}

$projects = new App\Controllers\Projects();
$r = new ReflectionClass($projects);
$parent = $r;
while ($parent) {
    if ($parent->hasProperty('login_user')) {
        $p = $parent->getProperty('login_user');
        $p->setAccessible(true);
        $u = $p->getValue($projects);
        if (!$u || empty($u->id)) {
            $p->setValue($projects, model('App\Models\Users_model')->get_access_info(66));
        }
        break;
    }
    $parent = $parent->getParentClass();
}

$db = \Config\Database::connect('default', false);
$task = $db->query("SELECT id FROM rise_tasks WHERE deleted=0 AND (assigned_to IS NULL OR assigned_to=0) ORDER BY id DESC LIMIT 1")->getRow();
if (!$task) {
    echo "NO_TASK\n";
    exit(0);
}
$row = model('App\Models\Tasks_model')->get_details(['id' => $task->id])->getRow();
if (!$row) {
    echo "NO_DETAIL\n";
    exit(0);
}
$prop = $r->getProperty('timesheet_tasks_by_id');
$prop->setAccessible(true);
$prop->setValue($projects, [(int)$row->id => $row]);
$extra = $r->getMethod('_make_timesheet_task_extra_columns');
$extra->setAccessible(true);
$cells = $extra->invoke($projects, (object)['task_id' => $row->id]);
$assigned = strip_cell($cells[4]);
echo "task={$row->id} assigned_cell=[$assigned]\n";
echo ($assigned === '-' ? "PASS no-assignee dash\n" : "FAIL expected dash\n");

// Recurring clone if any
$rec = $db->query("SELECT id FROM rise_tasks WHERE deleted=0 AND recurring_task_id>0 ORDER BY id DESC LIMIT 1")->getRow();
if ($rec) {
    $row2 = model('App\Models\Tasks_model')->get_details(['id' => $rec->id])->getRow();
    if ($row2) {
        $prop->setValue($projects, [(int)$row2->id => $row2]);
        $cells2 = $extra->invoke($projects, (object)['task_id' => $row2->id]);
        echo "recurring_clone={$row2->id} score=" . strip_cell($cells2[1]) . " status=" . strip_cell($cells2[6]) . "\n";
        echo "PASS recurring clone builds\n";
    }
} else {
    echo "SKIP no recurring clone in DB\n";
}
