<?php
/**
 * Measure get_all_required_settings query count/time for App_Controller constructions
 * and for simulated tasks list / tasks_in_review data paths.
 *
 * Usage:
 *   php tests/_tmp_phase17e_measure_settings.php --mode=current|cached
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$mode = 'current';
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
require __DIR__ . '/../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");

// Patch App_Controller in-memory for --mode=cached by rewriting file temporarily
$acPath = APPPATH . 'Controllers/App_Controller.php';
$acOrig = file_get_contents($acPath);
$acPatched = false;
if ($mode === 'cached') {
    $without = <<<'PHP'
        $login_user_id = $this->Users_model->login_user_id();

        // Always reload settings from DB on every request so flag flips (e.g. task_points_review_rules_enabled)
        // take effect without restarting PHP. Do not cache across requests in long-lived workers.
        $settings = $this->Settings_model->get_all_required_settings($login_user_id)->getResult();
        foreach ($settings as $setting) {
            config('Rise')->app_settings_array[$setting->setting_name] = $setting->setting_value;
        }
PHP;
    $with = <<<'PHP'
        $login_user_id = $this->Users_model->login_user_id();

        //assign settings from database (cached per-user for the current request)
        static $settings_loaded_users = array();
        if (!isset($settings_loaded_users[$login_user_id])) {
            $settings = $this->Settings_model->get_all_required_settings($login_user_id)->getResult();
            foreach ($settings as $setting) {
                config('Rise')->app_settings_array[$setting->setting_name] = $setting->setting_value;
            }
            $settings_loaded_users[$login_user_id] = true;
        }
PHP;
    if (strpos($acOrig, 'Always reload settings from DB') !== false) {
        file_put_contents($acPath, str_replace($without, $with, $acOrig));
        $acPatched = true;
    }
}
register_shutdown_function(function () use ($acPath, $acOrig, $acPatched) {
    if ($acPatched) {
        file_put_contents($acPath, $acOrig);
    }
});

helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);
$app = \Config\Services::codeigniter();
$app->initialize();

// Fake logged-in user for App_Controller constructions that need session
$session = \Config\Services::session();
$session->set('user_id', 66);

$db = \Config\Database::connect('default', false);
$db->query("SELECT 1"); // warm

// Hook query counting
$queries = [];
$db->DBDebug = true;

function count_settings_queries(callable $fn, $label) {
    global $db;
    // Use mysqli general log alternative: wrap via CI's getQueries if available
    $before = method_exists($db, 'getQueries') ? count($db->getQueries() ?: []) : null;
    $t0 = microtime(true);

    // Also count by monkey-patching query method is hard; use INFORMATION via custom counter
    $counter = new class($db) {
        public $count = 0;
        public $settingsMs = 0.0;
        public $settingsCount = 0;
        private $db;
        private $orig;
        public function __construct($db) {
            $this->db = $db;
        }
        public function wrap() {
            // Count SELECT on rise_settings by sampling after each call using last query
        }
    };

    // Simpler: enable query log by capturing via DB->query override through a temporary wrapper class is complex.
    // Use: run get_all_required_settings timing directly + count App_Controller constructions calling it.

    $settingsSqlHits = 0;
    $settingsSqlMs = 0.0;
    $totalMs = 0.0;

    // Instrument Settings_model via wrapping the connection's query count
    $qBefore = 0;
    if (isset($db->queries) && is_array($db->queries)) {
        $qBefore = count($db->queries);
    } else {
        // Force collect
        $db->saveQueries = true;
        if (!isset($db->queries)) {
            // CI4 BaseConnection has protect $queries when DBDebug
        }
    }

    // Use mysqli thread + performance_schema if available, else count by string match after enabling saveQueries
    $ref = new ReflectionClass($db);
    if ($ref->hasProperty('queries')) {
        $p = $ref->getProperty('queries');
        $p->setAccessible(true);
        $existing = $p->getValue($db);
        if (!is_array($existing)) $p->setValue($db, []);
    }
    // Ensure saveQueries
    if ($ref->hasProperty('saveQueries')) {
        $sp = $ref->getProperty('saveQueries');
        $sp->setAccessible(true);
        $sp->setValue($db, true);
    }

    $qStart = is_array($p->getValue($db) ?? null) ? count($p->getValue($db)) : 0;
    $t0 = microtime(true);
    $fn();
    $elapsed = (microtime(true) - $t0) * 1000;
    $all = $p->getValue($db);
    $slice = array_slice($all ?: [], $qStart);
    $settingsQs = [];
    foreach ($slice as $q) {
        $sql = is_array($q) ? ($q['sql'] ?? ($q[0] ?? '')) : (string)$q;
        if (stripos($sql, 'rise_settings') !== false || stripos($sql, 'FROM `rise_settings`') !== false || stripos($sql, 'settings') !== false && stripos($sql, 'setting_name') !== false) {
            $settingsQs[] = $sql;
        }
        // Also match Settings_model SQL without prefix quirks
        if (stripos($sql, 'setting_name') !== false && stripos($sql, 'setting_value') !== false) {
            if (!in_array($sql, $settingsQs, true)) $settingsQs[] = $sql;
        }
    }

    echo "MODE=$label\n";
    echo "  total_queries=" . count($slice) . " elapsed_ms=" . round($elapsed, 2) . "\n";
    echo "  settings_queries=" . count($settingsQs) . "\n";
    foreach ($settingsQs as $i => $sql) {
        echo "  settings_q[$i]=" . substr(preg_replace('/\s+/', ' ', $sql), 0, 160) . "\n";
    }
    return ['total' => count($slice), 'settings' => count($settingsQs), 'ms' => $elapsed];
}

echo "=== MODE=$mode ===\n";

// 1) Multiple App_Controller constructions in one "request" (helpers do this)
$r1 = count_settings_queries(function () {
    for ($i = 0; $i < 5; $i++) {
        new App\Controllers\App_Controller();
    }
}, "$mode App_Controller x5");

// 2) Simulate tasks_in_review list data path
$r2 = count_settings_queries(function () {
    new App\Controllers\App_Controller();
    helper(['task_points']);
    $tm = model('App\Models\Tasks_model');
    $tm->get_details(['awaiting_review_all' => true]);
}, "$mode tasks_in_review list");

// 3) Simulate my tasks list-ish
$r3 = count_settings_queries(function () {
    new App\Controllers\App_Controller();
    $tm = model('App\Models\Tasks_model');
    $tm->get_details([
        'project_status' => 'open',
        'limit' => 20,
        'offset' => 0,
        'show_assigned_tasks_only_user_id' => 66,
    ]);
}, "$mode tasks list sample");

echo "SUMMARY mode=$mode ac_x5_settings={$r1['settings']} review_settings={$r2['settings']} list_settings={$r3['settings']}\n";
echo "SUMMARY mode=$mode ac_x5_ms=" . round($r1['ms'],1) . " review_ms=" . round($r2['ms'],1) . " list_ms=" . round($r3['ms'],1) . "\n";
