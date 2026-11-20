<?php
/* Local only: creates / removes a temporary admin and a staff user for the screenshot session.
 * php tests/_tmp_cc_user.php create | remove */
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
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'content_calendar']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default');
if (!in_array($db->hostname, array('127.0.0.1', 'localhost', 'localhost:3306'), true)) {
    echo "REFUSING: not a local database\n";
    exit(1);
}

$mode = isset($argv[1]) ? $argv[1] : 'create';
$emails = array('cc_shot_admin@example.test' => 1, 'cc_shot_staff@example.test' => 0);
foreach (array_keys($emails) as $email) {
    $row = $db->query("SELECT id FROM rise_users WHERE email=?", array($email))->getRow();
    if ($row) {
        $db->query("DELETE FROM rise_cc_permissions WHERE user_id=?", array((int) $row->id));
        $db->query("DELETE FROM rise_users WHERE id=?", array((int) $row->id));
    }
}

if ($mode === 'create') {
    $hash = password_hash('Cc_Shot_12345!', PASSWORD_DEFAULT);
    foreach ($emails as $email => $is_admin) {
        $db->table('users')->insert(array(
            'first_name' => 'CC', 'last_name' => $is_admin ? 'Shot Admin' : 'Shot Staff', 'user_type' => 'staff', 'is_admin' => $is_admin, 'role_id' => 0,
            'email' => $email, 'password' => $hash, 'status' => 'active', 'client_id' => 0, 'job_title' => 'Tester',
            'disable_login' => 0, 'language' => '', 'created_at' => get_current_utc_time(), 'deleted' => 0
        ));
        echo "created $email id=" . $db->insertID() . "\n";
    }
} else {
    echo "removed temp users\n";
}
