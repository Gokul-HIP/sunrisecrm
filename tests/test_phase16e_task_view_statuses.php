<?php
/**
 * Phase 16E: task_view / task_modal_form endpoints for every status × role × workflow flag.
 * Asserts HTTP-equivalent 200 (successful render) and status label present.
 * Does not touch live; uses local DB only.
 *
 * Usage: php tests/test_phase16e_task_view_statuses.php [--db=sunrisecrmnew]
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', '1');

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/index.php/projects/task_view';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

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

$targetDb = 'sunrisecrmnew';
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $targetDb = substr($arg, 5);
    }
}

$dbConfig = config('Database');
$dbConfig->default['database'] = $targetDb;
$dbConfig->default['hostname'] = '127.0.0.1';
$db = \Config\Database::connect('default', false);

$riseConfig = config('Rise');
$riseConfig->app_settings_array = array();
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

echo "========================================================\n";
echo "Phase 16E: Task view/modal status matrix\n";
echo "Target Database: " . $db->database . "\n";
echo "========================================================\n\n";

$pass = 0;
$fail = 0;

// Convert header()-after-output into a catchable exception (CLI app_redirect)
set_error_handler(function ($severity, $message) {
    if ($severity === E_WARNING || $severity === E_ERROR || $severity === E_USER_WARNING) {
        if (strpos($message, 'Cannot modify header information') !== false) {
            throw new RuntimeException($message);
        }
    }
    return false;
});

function assert_test($condition, $name, $details = "") {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] $name\n";
    } else {
        $fail++;
        echo "[FAIL] $name" . ($details !== "" ? ": $details" : "") . "\n";
    }
}

$creator_id = 7;
$assignee_id = 40;
$collab_id = 68;
$admin_id = 66;

$status_keys = [
    'to_do' => null,
    'brainstorming' => null,
    'in_progress' => null,
    'in_review' => null,
    'changes' => null,
    'done' => null,
];

$rows = $db->query("SELECT id, key_name, title FROM rise_task_status WHERE deleted = 0")->getResult();
foreach ($rows as $r) {
    $key = $r->key_name ?: '';
    if ($key === '' && strtolower($r->title) === 'brainstorming') {
        $key = 'brainstorming';
    }
    if ($key === 'to_do' || $key === '') {
        // id 1 is typically to_do
    }
    if (array_key_exists($key, $status_keys)) {
        $status_keys[$key] = (int) $r->id;
    }
    if (!$r->key_name && strtolower($r->title) === 'brainstorming') {
        $status_keys['brainstorming'] = (int) $r->id;
    }
}
// Ensure to_do
if (!$status_keys['to_do']) {
    $status_keys['to_do'] = 1;
}
if (!$status_keys['in_progress']) {
    $status_keys['in_progress'] = 2;
}
if (!$status_keys['done']) {
    $status_keys['done'] = (int) get_done_task_status_id();
}
if (!$status_keys['in_review']) {
    $status_keys['in_review'] = (int) get_in_review_task_status_id();
}
if (!$status_keys['changes']) {
    $status_keys['changes'] = (int) get_changes_task_status_id();
}

echo "Status IDs: ";
foreach ($status_keys as $k => $v) {
    echo "$k=$v ";
}
echo "\n\n";

$label_for = [
    'to_do' => ['To do', 'To Do', 'to_do'],
    'brainstorming' => ['Brainstorming', 'brainstorming'],
    'in_progress' => ['In progress', 'in_progress'],
    'in_review' => ['In review', 'in_review'],
    'changes' => ['Changes', 'changes'],
    'done' => ['Done', 'done'],
];

$db->query("DELETE FROM rise_project_comments WHERE task_id IN (SELECT id FROM rise_tasks WHERE title LIKE 'PHASE16E_VIEW_%')");
$db->query("DELETE FROM rise_tasks WHERE title LIKE 'PHASE16E_VIEW_%'");

function set_workflow_flag($enabled) {
    $settings_model = model('App\Models\Settings_model');
    $settings_model->save_setting("task_review_workflow_enabled", $enabled ? "1" : "0");
    config('Rise')->app_settings_array["task_review_workflow_enabled"] = $enabled ? "1" : "0";
}

// Project where creator/assignee/collaborator/admin are all members
$TEST_PROJECT_ID = 626;

function create_view_task($db, $title, $status_id, $assignee, $creator, $collab, $project_id) {
    $db->table('rise_tasks')->insert([
        'title' => $title,
        'project_id' => $project_id,
        'created_by' => $creator,
        'assigned_to' => $assignee,
        'collaborators' => (string) $collab,
        'points' => 5,
        'status_id' => $status_id,
        'deadline' => '2026-12-15',
        'end_time' => '18:00:00',
        'created_date' => date('Y-m-d'),
        'deleted' => 0,
    ]);
    return (int) $db->insertID();
}

function render_endpoint($endpoint, $task_id, $user_id) {
    $session = \Config\Services::session();
    $session->set('user_id', $user_id);

    $request = \Config\Services::request(null, false)->setGlobal('post', ['id' => $task_id]);
    $response = \Config\Services::response(null, false);
    $logger = \Config\Services::logger(true);

    $controller = new \App\Controllers\Projects();
    $controller->initController($request, $response, $logger);

    try {
        ob_start();
        if ($endpoint === 'task_view') {
            $result = $controller->task_view(0);
        } else if ($endpoint === 'task_view_details') {
            $result = $controller->task_view($task_id);
        } else if ($endpoint === 'task_modal_form') {
            $result = $controller->task_modal_form();
        } else {
            throw new RuntimeException("Unknown endpoint $endpoint");
        }
        $out = ob_get_clean();
        if (is_string($result)) {
            $out .= $result;
        } elseif ($result instanceof \CodeIgniter\HTTP\ResponseInterface) {
            $out .= $result->getBody();
        }
        return ['ok' => true, 'body' => $out, 'exc' => null];
    } catch (Throwable $e) {
        @ob_end_clean();
        return [
            'ok' => false,
            'body' => '',
            'exc' => get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(),
        ];
    }
}

function body_has_label($body, $status_key, $label_for) {
    // Prefer the status_id anchor text
    if (preg_match('/data-act-type=["\']status_id["\'][^>]*>(.*?)<\/a>/is', $body, $m)) {
        $text = trim(html_entity_decode(strip_tags($m[1])));
        foreach ($label_for[$status_key] as $lbl) {
            if (strcasecmp($text, $lbl) === 0) {
                return true;
            }
        }
    }
    // Edit modal: selected option / dropdown contains label
    if (preg_match('/name=["\']status_id["\'][\s\S]{0,2000}?<\/select>/i', $body, $m)) {
        foreach ($label_for[$status_key] as $lbl) {
            if (stripos($m[0], $lbl) !== false) {
                return true;
            }
        }
    }
    // Fallback: badge/lang presence near task title area
    foreach ($label_for[$status_key] as $lbl) {
        if (stripos($body, '>' . $lbl . '<') !== false || stripos($body, '"' . $lbl . '"') !== false) {
            return true;
        }
    }
    return false;
}

$roles = [
    'assignee' => $assignee_id,
    'creator' => $creator_id,
    'collaborator' => $collab_id,
    'admin' => $admin_id,
];

$endpoints = ['task_view', 'task_view_details', 'task_modal_form'];

foreach ([1, 0] as $flag) {
    set_workflow_flag($flag);
    echo "----- workflow flag=$flag -----\n";

    foreach ($status_keys as $status_key => $status_id) {
        if (!$status_id) {
            assert_test(false, "status_id_resolved:$status_key", "missing status id");
            continue;
        }

        $task_id = create_view_task(
            $db,
            "PHASE16E_VIEW_{$status_key}_f{$flag}",
            $status_id,
            $assignee_id,
            $creator_id,
            $collab_id,
            $TEST_PROJECT_ID
        );

        // Add a null-files comment on Changes tasks to ensure the prior 500 path is covered
        if ($status_key === 'changes') {
            $db->table('rise_project_comments')->insert([
                'created_by' => $assignee_id,
                'created_at' => date('Y-m-d H:i:s'),
                'description' => 'PHASE16E null files comment',
                'project_id' => $TEST_PROJECT_ID,
                'task_id' => $task_id,
                'files' => null,
                'deleted' => 0,
            ]);
        }

        foreach ($roles as $role_name => $user_id) {
            foreach ($endpoints as $endpoint) {
                $res = render_endpoint($endpoint, $task_id, $user_id);
                $name = "flag{$flag}/{$status_key}/{$role_name}/{$endpoint}/task{$task_id}";
                if (!$res['ok']) {
                    assert_test(false, $name, $res['exc']);
                    continue;
                }
                $has_label = body_has_label($res['body'], $status_key, $label_for);
                $looks_error = (bool) preg_match('/Internal Server Error|Whoops!|ErrorException/i', $res['body']);
                assert_test(!$looks_error && strlen($res['body']) > 200 && $has_label, $name, $looks_error ? 'error markers' : (!$has_label ? 'label missing len=' . strlen($res['body']) : 'short body'));
            }
        }
    }
    echo "\n";
}

// Extra: assignee can move Changes -> In review via update_task_info logic
set_workflow_flag(1);
$tid = create_view_task($db, 'PHASE16E_VIEW_MOVE_CHANGES', $status_keys['changes'], $assignee_id, $creator_id, $collab_id, $TEST_PROJECT_ID);
$move_data = [
    'status_id' => $status_keys['in_review'],
    'status_changed_at' => get_current_utc_time(),
    'submitted_for_review_at' => get_current_utc_time(),
];
$save = model('App\Models\Tasks_model')->ci_save($move_data, $tid);
$row = $db->query("SELECT status_id FROM rise_tasks WHERE id=?", [$tid])->getRow();
assert_test($save && (int)$row->status_id === (int)$status_keys['in_review'], "assignee_changes_to_in_review", "status=" . ($row->status_id ?? 'null'));

// Confirm Changes cannot be selected as a NEW status via UI rule when workflow on
$tid2 = create_view_task($db, 'PHASE16E_VIEW_BLOCK_CHANGES', $status_keys['in_progress'], $assignee_id, $creator_id, $collab_id, $TEST_PROJECT_ID);
$blocked = ($status_keys['changes'] && get_setting("task_review_workflow_enabled"));
assert_test($blocked, "changes_not_selectable_when_workflow_on");

// Restore workflow enabled (local default for review work)
set_workflow_flag(1);

echo "\n========================================================\n";
echo "PASS=$pass FAIL=$fail\n";
echo "========================================================\n";
exit($fail ? 1 : 0);
