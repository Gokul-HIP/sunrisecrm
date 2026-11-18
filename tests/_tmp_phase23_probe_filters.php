<?php
/**
 * Phase 23: reproduce Tasks list filters for Surya (39) and task 25906 visibility.
 */
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
date_default_timezone_set(get_setting('timezone') ?: 'Asia/Kolkata');

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

function run_list($projects, $post) {
    if (!isset($post['length'])) {
        $post['length'] = 200;
        $post['start'] = 0;
        $post['draw'] = 1;
    }
    $_POST = $post;
    $_REQUEST = $post;
    \Config\Services::request(null, false)->setGlobal('post', $post);
    // Re-bind request onto controller if needed
    $ref = new ReflectionClass($projects);
    $parent = $ref;
    while ($parent) {
        if ($parent->hasProperty('request')) {
            $p = $parent->getProperty('request');
            $p->setAccessible(true);
            $p->setValue($projects, \Config\Services::request());
            break;
        }
        $parent = $parent->getParentClass();
    }
    ob_start();
    $projects->my_tasks_list_data();
    $json = ob_get_clean();
    return json_decode($json, true);
}

function ids_from($result) {
    $ids = [];
    foreach (($result['data'] ?? []) as $row) {
        // _make_task_row returns array of cell HTML; id is usually in col 1 as link text
        $blob = is_array($row) ? implode(' ', array_map('strval', $row)) : (string) $row;
        if (preg_match_all('/#(\d+)/', $blob, $m)) {
            foreach ($m[1] as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }
        if (preg_match_all('/\b(15\d{3}|25\d{3})\b/', $blob, $m2)) {
            foreach ($m2[1] as $id) {
                $ids[(int) $id] = (int) $id;
            }
        }
    }
    return $ids;
}

$status_model = model('App\Models\Task_status_model');
$statuses = $status_model->get_details()->getResult();
$non_done = [];
foreach ($statuses as $s) {
    if ($s->key_name !== 'done') {
        $non_done[] = (string) $s->id;
    }
}

echo "=== A: admin + Surya, default statuses, NO date ===\n";
$r = run_list($projects, [
    'specific_user_id' => '39',
    'status_id' => $non_done,
    'length' => 500,
    'start' => 0,
    'draw' => 1,
]);
echo "todo={$r['todo_count']} in_progress={$r['in_progress_count']} in_review={$r['in_review_count']} done={$r['done_count']} records={$r['recordsFiltered']}\n";
$ids = ids_from($r);
echo "has_25906=" . (isset($ids[25906]) ? 1 : 0) . " has_15583=" . (isset($ids[15583]) ? 1 : 0) . " sample_ids=" . implode(',', array_slice(array_values($ids), 0, 15)) . "\n";

echo "=== B: admin + Surya + created 2026-11-18..2026-11-18 ===\n";
$r = run_list($projects, [
    'specific_user_id' => '39',
    'status_id' => $non_done,
    'created_from' => '2026-11-18',
    'created_to' => '2026-11-18',
    'length' => 500,
    'start' => 0,
    'draw' => 1,
]);
echo "todo={$r['todo_count']} in_progress={$r['in_progress_count']} in_review={$r['in_review_count']} done={$r['done_count']} records={$r['recordsFiltered']}\n";
$ids = ids_from($r);
echo "has_25906=" . (isset($ids[25906]) ? 1 : 0) . " has_25949=" . (isset($ids[25949]) ? 1 : 0) . " has_15583=" . (isset($ids[15583]) ? 1 : 0) . " ids=" . implode(',', array_values($ids)) . "\n";

echo "=== C: admin + Surya + created 2026-11-18..2026-11-18, NO status filter ===\n";
$r = run_list($projects, [
    'specific_user_id' => '39',
    'created_from' => '2026-11-18',
    'created_to' => '2026-11-18',
    'length' => 500,
    'start' => 0,
    'draw' => 1,
]);
echo "todo={$r['todo_count']} in_progress={$r['in_progress_count']} done={$r['done_count']} records={$r['recordsFiltered']}\n";

echo "=== D: zero-date tasks under various comparisons ===\n";
$db = \Config\Database::connect();
foreach ([
    "created_date = '0000-00-00'",
    "created_date = 0",
    "created_date IS NULL",
    "created_date BETWEEN '2026-11-18' AND '2026-11-18'",
    "created_date <= '2026-11-18'",
    "CAST(created_date AS CHAR) = '0000-00-00'",
] as $cond) {
    $q = $db->query("SELECT COUNT(*) AS c FROM rise_tasks WHERE deleted=0 AND assigned_to=39 AND $cond");
    echo "$cond => " . $q->getRow()->c . "\n";
}

echo "=== E: sql_mode ===\n";
echo $db->query("SELECT @@sql_mode AS m")->getRow()->m . "\n";
echo "version=" . $db->query("SELECT VERSION() AS v")->getRow()->v . "\n";
