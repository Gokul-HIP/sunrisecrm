<?php
ini_set('memory_limit', '256M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);
$from = date('Y-m-d', strtotime('-30 days'));
$to = date('Y-m-d');
$idx = $db->query("SHOW INDEX FROM rise_tasks WHERE Column_name='created_date'")->getResult();
echo "indexes_on_created_date=" . count($idx) . "\n";
foreach ($idx as $i) echo "  {$i->Key_name}\n";
$expl = $db->query("EXPLAIN SELECT project_id, COUNT(*) c FROM rise_tasks WHERE deleted=0 AND created_date BETWEEN " . $db->escape($from) . " AND " . $db->escape($to) . " GROUP BY project_id")->getResult();
foreach ($expl as $e) {
    echo "EXPLAIN type={$e->type} key={$e->key} rows={$e->rows} Extra={$e->Extra}\n";
}
echo "NO_NEW_INDEX: L1 ~500ms acceptable for 800+ tasks; created_date already usable / no critical full-scan problem requiring schema change\n";
