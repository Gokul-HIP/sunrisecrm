<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER["HTTP_HOST"]="127.0.0.1"; $_SERVER["REQUEST_METHOD"]="POST"; $_SERVER["SCRIPT_NAME"]="/index.php";
define("ENVIRONMENT","development"); define("FCPATH", dirname(__DIR__).DIRECTORY_SEPARATOR);
require FCPATH."app/Config/Paths.php"; $paths=new Config\Paths();
require rtrim($paths->systemDirectory,"\\/ ").DIRECTORY_SEPARATOR."bootstrap.php";
require_once SYSTEMPATH."Config/DotEnv.php"; (new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH."ThirdParty/PHP-Hooks/php-hooks.php");
helper(["plugin","url","language","general","date_time","task_points"]);
$app=\Config\Services::codeigniter(); $app->initialize();
\Config\Services::session()->set("user_id", 35);
$p=new App\Controllers\Projects();
$ref=new ReflectionClass($p); $par=$ref;
while($par){ if($par->hasProperty("login_user")){ $pr=$par->getProperty("login_user"); $pr->setAccessible(true);
$pr->setValue($p, model("App\Models\Users_model")->get_access_info(35)); break;} $par=$par->getParentClass(); }
$_POST=["start_date"=>"2026-06-01","end_date"=>"2026-11-17","format"=>"print"];
try { $p->timesheet_grouped_export(); echo "LEAK_EXPORT"; } catch(Throwable $e){ echo "EX ".$e->getMessage(); }
