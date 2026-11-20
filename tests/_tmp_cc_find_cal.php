<?php
ini_set('memory_limit', '256M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTP_HOST']='127.0.0.1'; $_SERVER['SERVER_NAME']='127.0.0.1'; $_SERVER['REQUEST_URI']='/'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/index.php';
define('ENVIRONMENT','development'); define('FCPATH', dirname(__DIR__).DIRECTORY_SEPARATOR);
require FCPATH.'app/Config/Paths.php'; $paths=new Config\Paths();
require rtrim($paths->systemDirectory,'\\/ ').DIRECTORY_SEPARATOR.'bootstrap.php';
require_once SYSTEMPATH.'Config/DotEnv.php'; (new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH.'ThirdParty/PHP-Hooks/php-hooks.php'); helper(['plugin']);
$app=\Config\Services::codeigniter(); $app->initialize();
$db=\Config\Database::connect('default');
$rows=$db->query("SELECT id,name FROM rise_cc_calendars WHERE deleted=0 ORDER BY id DESC LIMIT 10")->getResult();
foreach ($rows as $r) echo $r->id."\t".$r->name."\n";
