<?php
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

$model = model('App\Models\Tasks_model');
$status_model = model('App\Models\Task_status_model');
$statuses = $status_model->get_details()->getResult();
$non_done = [];
foreach ($statuses as $s) {
    if ($s->key_name !== 'done') {
        $non_done[] = $s->id;
    }
}
$status_ids = implode(',', $non_done);

function summarize($label, $result, $watch = [25906, 25949, 25950, 25956, 15583, 15582, 15579]) {
    if (is_array($result)) {
        $rows = $result['data'] ?? [];
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int) $r->id;
        }
        echo "$label records=" . ($result['recordsFiltered'] ?? count($ids))
            . " todo=" . ($result['todo_count'] ?? '?')
            . " ip=" . ($result['in_progress_count'] ?? '?')
            . " ir=" . ($result['in_review_count'] ?? '?')
            . " done=" . ($result['done_count'] ?? '?')
            . "\n";
        foreach ($watch as $id) {
            echo "  has_$id=" . (in_array($id, $ids, true) ? 'Y' : 'N') . "\n";
        }
        echo "  first_ids=" . implode(',', array_slice($ids, 0, 12)) . "\n";
    } else {
        $rows = $result->getResult();
        $ids = array_map(function ($r) {
            return (int) $r->id;
        }, $rows);
        echo "$label rows=" . count($ids) . "\n";
        foreach ($watch as $id) {
            echo "  has_$id=" . (in_array($id, $ids, true) ? 'Y' : 'N') . "\n";
        }
    }
}

$base = [
    'specific_user_id' => 39,
    'project_status' => 'open',
    'status_ids' => $status_ids,
    'limit' => 500,
    'skip' => 0,
];

echo "=== model A no date ===\n";
summarize('A', $model->get_details($base));

echo "=== model B created Nov18-18 ===\n";
summarize('B', $model->get_details($base + [
    'created_from' => '2026-11-18',
    'created_to' => '2026-11-18',
]));

echo "=== model C created Nov18-18 + exclude_review ===\n";
summarize('C', $model->get_details($base + [
    'created_from' => '2026-11-18',
    'created_to' => '2026-11-18',
    'exclude_review_tasks_for_assignee' => true,
]));

echo "=== model D no status, no date ===\n";
$opt = $base;
unset($opt['status_ids']);
summarize('D', $model->get_details($opt));

echo "=== model E project_member_id=39 ===\n";
summarize('E', $model->get_details($base + ['project_member_id' => 39]));

echo "=== zero-date tasks detail ===\n";
$db = db_connect();
$r = $db->query("SELECT t.id,t.status_id,ts.key_name,t.deadline,t.created_date,t.project_id,p.status project_status,p.deleted pdel
 FROM rise_tasks t
 JOIN rise_task_status ts ON ts.id=t.status_id
 JOIN rise_projects p ON p.id=t.project_id
 WHERE t.id IN (15579,15580,15582,15583,15587)");
foreach ($r->getResult() as $row) {
    echo json_encode($row) . "\n";
}

echo "=== sql_mode / version ===\n";
echo $db->query("SELECT @@sql_mode AS m, VERSION() AS v")->getRow()->m . " | " . $db->query("SELECT VERSION() AS v")->getRow()->v . "\n";
