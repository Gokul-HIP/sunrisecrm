<?php
ini_set('memory_limit', '512M');
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

$projects = new App\Controllers\Projects();
$ref = new ReflectionClass($projects);
$parent = $ref;
while ($parent) {
    if ($parent->hasProperty('login_user')) {
        $p = $parent->getProperty('login_user');
        $p->setAccessible(true);
        $p->setValue($projects, model('App\Models\Users_model')->get_access_info(66));
        break;
    }
    $parent = $parent->getParentClass();
}

$qlog = [];
\CodeIgniter\Events\Events::on('DBQuery', static function ($q) use (&$qlog) {
    $qlog[] = is_object($q) && method_exists($q, 'getQuery') ? $q->getQuery() : (string) $q;
});

$_POST = ['start_date' => '2026-10-01', 'end_date' => '2026-11-17', 'user_id' => '42', 'project_id' => '613'];
ob_start();
$projects->timesheet_grouped_member_tasks();
ob_get_clean();

$kinds = [];
foreach ($qlog as $sql) {
    $k = 'other';
    if (stripos($sql, 'rise_task_point_awards') !== false) $k = 'awards';
    elseif (stripos($sql, 'project_time') !== false) $k = 'project_time';
    elseif (stripos($sql, 'tasks') !== false) $k = 'tasks';
    elseif (stripos($sql, 'users') !== false) $k = 'users';
    elseif (stripos($sql, 'settings') !== false) $k = 'settings';
    $kinds[$k] = ($kinds[$k] ?? 0) + 1;
}
print_r($kinds);
echo "total=" . count($qlog) . "\n";
foreach (array_slice($qlog, 0, 12) as $i => $s) {
    echo ($i + 1) . ": " . substr(preg_replace('/\s+/', ' ', $s), 0, 180) . "\n";
}
