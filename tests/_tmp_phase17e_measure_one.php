<?php
/**
 * Isolated one-shot measure (fresh PHP process = fresh statics).
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$mode = 'cached';
$only = 'tasks_list';
$args = $argv ?? [];
// Windows php.exe sometimes drops args; also accept QUERY-like env.
if (getenv('P17E_MODE')) $mode = getenv('P17E_MODE');
if (getenv('P17E_ONLY')) $only = getenv('P17E_ONLY');
foreach ($args as $a) {
    if (str_starts_with((string)$a, '--mode=')) $mode = substr($a, 7);
    if (str_starts_with((string)$a, '--only=')) $only = substr($a, 7);
}
fwrite(STDERR, "argv=" . json_encode($args) . " mode=$mode only=$only\n");

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);

$acPath = FCPATH . 'app/Controllers/App_Controller.php';
$acOrig = file_get_contents($acPath);
$patched = false;

if ($mode === 'uncached') {
    $from = <<<'PHP'
        //assign settings from database (cached per-user for the current request)
        // PHP resets statics each request, so a DB flag flip is visible on the next page load.
        // Cache avoids repeat get_all_required_settings when helpers construct App_Controller again.
        static $settings_loaded_users = array();
        if (!isset($settings_loaded_users[$login_user_id])) {
            $settings = $this->Settings_model->get_all_required_settings($login_user_id)->getResult();
            foreach ($settings as $setting) {
                config('Rise')->app_settings_array[$setting->setting_name] = $setting->setting_value;
            }
            $settings_loaded_users[$login_user_id] = true;
        }
PHP;
    $to = <<<'PHP'
        // TEMP uncached for measurement
        $settings = $this->Settings_model->get_all_required_settings($login_user_id)->getResult();
        foreach ($settings as $setting) {
            config('Rise')->app_settings_array[$setting->setting_name] = $setting->setting_value;
        }
PHP;
    if (strpos($acOrig, $from) === false) {
        fwrite(STDERR, "cache block not found for patch\n");
        exit(2);
    }
    file_put_contents($acPath, str_replace($from, $to, $acOrig));
    $patched = true;
}
register_shutdown_function(function () use ($acPath, $acOrig, $patched) {
    if ($patched) file_put_contents($acPath, $acOrig);
});

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

$GLOBALS['qlog'] = [];
\CodeIgniter\Events\Events::on('DBQuery', static function ($query) {
    $GLOBALS['qlog'][] = is_object($query) && method_exists($query, 'getQuery') ? $query->getQuery() : (string)$query;
});

function is_settings_sql($s) {
    return stripos($s, 'rise_settings') !== false
        || (stripos($s, 'setting_name') !== false && stripos($s, 'setting_value') !== false);
}

$GLOBALS['qlog'] = [];
$t0 = microtime(true);
if ($only === 'ac_x1') {
    new App\Controllers\App_Controller();
} elseif ($only === 'ac_x5') {
    for ($i = 0; $i < 5; $i++) new App\Controllers\App_Controller();
} elseif ($only === 'tasks_in_review') {
    new App\Controllers\App_Controller();
    model('App\Models\Tasks_model')->get_details(['awaiting_review_all' => true]);
} elseif ($only === 'tasks_list') {
    new App\Controllers\App_Controller();
    model('App\Models\Tasks_model')->get_details([
        'limit' => 25,
        'offset' => 0,
        'project_member_id' => 66,
    ]);
} else {
    fwrite(STDERR, "unknown --only\n");
    exit(2);
}
$ms = (microtime(true) - $t0) * 1000;
$settings = 0;
foreach ($GLOBALS['qlog'] as $sql) {
    if (is_settings_sql($sql)) $settings++;
}
echo "$mode $only total_q=" . count($GLOBALS['qlog']) . " settings_q=$settings ms=" . round($ms, 2) . "\n";
