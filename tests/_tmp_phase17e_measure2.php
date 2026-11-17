<?php
/**
 * Measure settings-query cost with cache ON vs OFF (temporary file patch for OFF).
 * Uses CI Events::DBQuery — no system/* edits.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$mode = 'cached';
foreach ($argv as $a) {
    if (str_starts_with($a, '--mode=')) $mode = substr($a, 7);
}

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
    if ($patched) {
        file_put_contents($acPath, $acOrig);
        echo "(App_Controller restored)\n";
    }
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
    $sql = is_object($query) && method_exists($query, 'getQuery') ? $query->getQuery() : (string)$query;
    $GLOBALS['qlog'][] = $sql;
});

function is_settings_sql($sql) {
    $s = (string)$sql;
    return (stripos($s, 'rise_settings') !== false)
        || (stripos($s, 'setting_name') !== false && stripos($s, 'setting_value') !== false)
        || (stripos($s, 'get_all_required_settings') !== false);
}

function measure($label, callable $fn) {
    $GLOBALS['qlog'] = [];
    $t0 = microtime(true);
    $fn();
    $ms = (microtime(true) - $t0) * 1000;
    $slice = $GLOBALS['qlog'];
    $settings = 0;
    foreach ($slice as $sql) {
        if (is_settings_sql($sql)) $settings++;
    }
    echo "$label total_q=" . count($slice) . " settings_q=$settings ms=" . round($ms, 2) . "\n";
}

echo "MODE=$mode\n";

measure("$mode ac_x5", function () {
    for ($i = 0; $i < 5; $i++) new App\Controllers\App_Controller();
});

measure("$mode tasks_in_review", function () {
    new App\Controllers\App_Controller();
    model('App\Models\Tasks_model')->get_details(['awaiting_review_all' => true]);
});

measure("$mode tasks_list", function () {
    new App\Controllers\App_Controller();
    model('App\Models\Tasks_model')->get_details([
        'limit' => 25,
        'offset' => 0,
        'project_member_id' => 66,
    ]);
});
