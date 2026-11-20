<?php
/* Ensure a complete local admin for browser tests. */
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
require_once APPPATH . 'ThirdParty/PHP-Hooks/php-hooks.php';
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'content_calendar']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default');
if (!in_array($db->hostname, array('127.0.0.1', 'localhost', 'localhost:3306'), true)) {
    echo "REFUSING\n";
    exit(1);
}

$email = 'cc_browser_admin@example.test';
$password = 'Cc_Browser_12345!';
$row = $db->query('SELECT id FROM rise_users WHERE email=?', array($email))->getRow();
if ($row) {
    $db->query('DELETE FROM rise_cc_permissions WHERE user_id=?', array((int) $row->id));
    $db->query('DELETE FROM rise_users WHERE id=?', array((int) $row->id));
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$db->table('users')->insert(array(
    'first_name' => 'CC',
    'last_name' => 'Browser Admin',
    'user_type' => 'staff',
    'is_admin' => 1,
    'role_id' => 0,
    'email' => $email,
    'password' => $hash,
    'status' => 'active',
    'client_id' => 0,
    'job_title' => 'Browser Tester',
    'disable_login' => 0,
    'language' => '',
    'created_at' => get_current_utc_time(),
    'deleted' => 0,
    'image' => '',
    'message_checked_at' => null,
    'client_permissions' => '',
    'requested_account_removal' => 0,
));
echo "created $email / $password id=" . $db->insertID() . "\n";

// also list a calendar with items for UI checks
$cals = $db->query('SELECT id, name FROM rise_cc_calendars WHERE deleted=0 ORDER BY id DESC LIMIT 5')->getResult();
foreach ($cals as $c) {
    echo "cal {$c->id} {$c->name}\n";
}
