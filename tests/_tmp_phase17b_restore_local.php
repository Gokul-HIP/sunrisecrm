<?php
/**
 * Read-only probe + restore settings on sunrisecrmnew if Phase 17B tests polluted them.
 * Does not touch Database.php. Restores known Phase 17 defaults: flag 0, from ''.
 */
ini_set('memory_limit', '256M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);
echo "DB=" . $db->getDatabase() . "\n";
if ($db->getDatabase() !== 'sunrisecrmnew') {
    fwrite(STDERR, "Refuse restore: not sunrisecrmnew\n");
    exit(1);
}

foreach (['task_points_review_rules_enabled', 'task_points_review_rules_from', 'task_review_changes_penalty_percent'] as $name) {
    $row = $db->query("SELECT setting_value FROM rise_settings WHERE setting_name=" . $db->escape($name) . " LIMIT 1")->getRow();
    echo "BEFORE $name=" . var_export($row ? $row->setting_value : null, true) . "\n";
}

// Restore Phase 17 schema defaults (flag off, empty from, penalty 10)
foreach ([
    'task_points_review_rules_enabled' => '0',
    'task_points_review_rules_from' => '',
    'task_review_changes_penalty_percent' => '10',
] as $name => $value) {
    $exists = $db->query("SELECT setting_name FROM rise_settings WHERE setting_name=" . $db->escape($name) . " LIMIT 1")->getRow();
    if ($exists) {
        $db->table('settings')->where('setting_name', $name)->update(['setting_value' => $value, 'deleted' => 0]);
    } else {
        $db->table('settings')->insert(['setting_name' => $name, 'setting_value' => $value, 'type' => 'app', 'deleted' => 0]);
    }
}

foreach (['task_points_review_rules_enabled', 'task_points_review_rules_from', 'task_review_changes_penalty_percent'] as $name) {
    $row = $db->query("SELECT setting_value FROM rise_settings WHERE setting_name=" . $db->escape($name) . " LIMIT 1")->getRow();
    echo "AFTER $name=" . var_export($row ? $row->setting_value : null, true) . "\n";
}

foreach ([25784, 25702, 23527] as $tid) {
    $row = $db->query("SELECT id,status_id,points,deadline,submitted_for_review_at,first_review_submitted_at,review_changes_count,timer_lock_json,points_rules_locked_at FROM rise_tasks WHERE id=$tid")->getRow();
    echo "TASK $tid status={$row->status_id} points={$row->points} submitted={$row->submitted_for_review_at} first={$row->first_review_submitted_at} changes={$row->review_changes_count} lock={$row->timer_lock_json} locked_at={$row->points_rules_locked_at}\n";
}

// If 25784 was flipped to Done (3) by a mis-aimed test write, restore to In review (10)
$t = $db->query("SELECT id,status_id,submitted_for_review_at FROM rise_tasks WHERE id=25784")->getRow();
if ($t && (int)$t->status_id === 3) {
    $db->table('tasks')->where('id', 25784)->update([
        'status_id' => 10,
        // keep submitted stamp if present
    ]);
    // revoke any award created by mistaken Done
    $db->table('task_point_awards')->where('task_id', 25784)->update(['deleted' => 1]);
    echo "RESTORED 25784 to in_review(10) and revoked awards\n";
}
$t = $db->query("SELECT id,status_id FROM rise_tasks WHERE id=25784")->getRow();
echo "25784 final status={$t->status_id}\n";
echo "OK\n";
