<?php
/**
 * Phase 23: for tasks created since 2026-11-01, check visibility per expected viewer
 * using Tasks_model get_details with the same options as my_tasks_list_data defaults.
 */
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', '300');
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

$db = db_connect();
$model = model('App\Models\Tasks_model');
$users_model = model('App\Models\Users_model');
$status_model = model('App\Models\Task_status_model');

$statuses = $status_model->get_details()->getResult();
$non_done = [];
foreach ($statuses as $s) {
    if ($s->key_name !== 'done') {
        $non_done[] = (int) $s->id;
    }
}
$status_ids = implode(',', $non_done);

$tasks = $db->query("SELECT t.id, t.assigned_to, t.collaborators, t.created_by, t.project_id, t.status_id, t.deadline, t.created_date, ts.key_name
  FROM rise_tasks t
  JOIN rise_task_status ts ON ts.id=t.status_id
  WHERE t.deleted=0 AND t.created_date >= '2026-11-01' AND t.created_date <> '0000-00-00'
  ORDER BY t.id")->getResult();

echo "tasks_since_nov1=" . count($tasks) . "\n";

function participants($task, $db) {
    $ids = [];
    if ((int) $task->assigned_to > 0) {
        $ids[(int) $task->assigned_to] = (int) $task->assigned_to;
    }
    if ((int) $task->created_by > 0) {
        $ids[(int) $task->created_by] = (int) $task->created_by;
    }
    if ($task->collaborators) {
        foreach (explode(',', $task->collaborators) as $c) {
            $c = (int) trim($c);
            if ($c > 0) {
                $ids[$c] = $c;
            }
        }
    }
    $members = $db->query("SELECT user_id FROM rise_project_members WHERE deleted=0 AND project_id=" . (int) $task->project_id)->getResult();
    foreach ($members as $m) {
        $ids[(int) $m->user_id] = (int) $m->user_id;
    }
    return array_values($ids);
}

function can_manage_all($user) {
    if (!$user) {
        return false;
    }
    return !empty($user->is_admin) || get_array_value($user->permissions, 'can_manage_all_projects') == '1';
}

function show_assigned_only_id($user) {
    // Mirror Projects::show_assigned_tasks_only_user_id roughly
    if (!$user || !empty($user->is_admin)) {
        return null;
    }
    $perm = get_array_value($user->permissions, 'show_assigned_tasks_only');
    if ($perm) {
        return (int) $user->id;
    }
    return null;
}

$cache = []; // user_id|created_from|created_to => set of visible ids
$hidden = []; // task_id => [user_id => reason]

function visible_ids_for($user_id, $created_from, $created_to, $status_ids, $model, $users_model, &$cache) {
    $key = $user_id . '|' . $created_from . '|' . $created_to . '|' . $status_ids;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $user = $users_model->get_access_info($user_id);
    if (!$user || empty($user->id)) {
        $cache[$key] = [];
        return $cache[$key];
    }
    $options = [
        'project_status' => 'open',
        'status_ids' => $status_ids,
        'limit' => 10000,
        'skip' => 0,
        'unread_status_user_id' => $user_id,
    ];
    if ($created_from && $created_to) {
        $options['created_from'] = $created_from;
        $options['created_to'] = $created_to;
    }
    if (!can_manage_all($user)) {
        $options['project_member_id'] = $user_id;
    }
    $sao = show_assigned_only_id($user);
    if ($sao) {
        $options['show_assigned_tasks_only_user_id'] = $sao;
    }
    // Non-admin without manage-all sees own project tasks; specific_user_id not set (sees all in projects)
    // For assignee/collaborator check we need the task to appear in THEIR list.
    if (get_setting('task_review_workflow_enabled')) {
        $options['exclude_review_tasks_for_assignee'] = true;
    }

    $result = $model->get_details($options);
    $ids = [];
    $rows = is_array($result) ? ($result['data'] ?? []) : $result->getResult();
    foreach ($rows as $r) {
        $ids[(int) $r->id] = true;
    }
    $cache[$key] = $ids;
    return $ids;
}

function reason_hidden($task, $user_id, $user, $created_from, $created_to, $db) {
    $reasons = [];
    if ($created_from && $created_to) {
        if ($task->created_date < $created_from || $task->created_date > $created_to) {
            $reasons[] = 'created_date_filter';
        }
    }
    if (!can_manage_all($user)) {
        $m = $db->query("SELECT id FROM rise_project_members WHERE deleted=0 AND project_id=" . (int) $task->project_id . " AND user_id=$user_id LIMIT 1")->getRow();
        if (!$m) {
            $reasons[] = 'not_project_member';
        }
    }
    $sao = show_assigned_only_id($user);
    if ($sao) {
        $parts = [];
        if ((int) $task->assigned_to === $sao) {
            $parts[] = 'assignee';
        }
        if ($task->collaborators && in_array($sao, array_map('intval', explode(',', $task->collaborators)), true)) {
            $parts[] = 'collaborator';
        }
        if (!$parts) {
            $reasons[] = 'show_assigned_tasks_only';
        }
    }
    if (get_setting('task_review_workflow_enabled')) {
        $ir = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
        if ($ir && (int) $task->status_id === $ir && !empty($task->submitted_for_review_at)) {
            // exclude_review_tasks_for_assignee hides from everyone on main list
            $reasons[] = 'exclude_review_tasks_for_assignee';
        }
    }
    if ($task->key_name === 'done') {
        $reasons[] = 'default_status_excludes_done';
    }
    if (!$reasons) {
        $reasons[] = 'unknown_or_pagination_or_other';
    }
    return implode(',', $reasons);
}

// Pass 1: no created filter
$checked = 0;
$visible = 0;
$hidden_n = 0;
$by_reason = [];
$focus = [25906 => true];

foreach ($tasks as $task) {
    if ($task->key_name === 'done') {
        continue; // default list excludes done
    }
    $viewers = participants($task, $db);
    // Always include 39 and 42 for 25906
    if ((int) $task->id === 25906) {
        $viewers[] = 39;
        $viewers[] = 42;
        $viewers = array_values(array_unique($viewers));
    }
    foreach ($viewers as $uid) {
        if ($uid <= 0) {
            continue;
        }
        $checked++;
        $ids = visible_ids_for($uid, '', '', $status_ids, $model, $users_model, $cache);
        if (!empty($ids[(int) $task->id])) {
            $visible++;
        } else {
            $hidden_n++;
            $user = $users_model->get_access_info($uid);
            // need submitted_for_review_at
            $full = $db->query("SELECT submitted_for_review_at FROM rise_tasks WHERE id=" . (int) $task->id)->getRow();
            $task->submitted_for_review_at = $full ? $full->submitted_for_review_at : null;
            $reason = reason_hidden($task, $uid, $user, '', '', $db);
            $by_reason[$reason] = ($by_reason[$reason] ?? 0) + 1;
            if (isset($focus[(int) $task->id]) || $hidden_n <= 30) {
                echo "HIDDEN task={$task->id} user=$uid reason=$reason status={$task->key_name} created={$task->created_date}\n";
            }
        }
    }
}

echo "=== NO DATE FILTER ===\n";
echo "pairs_checked=$checked visible=$visible hidden=$hidden_n\n";
echo "hidden_by_reason=" . json_encode($by_reason) . "\n";

// Pass 2: created filter today-today (2026-11-18)
$cache = [];
$checked = 0;
$visible = 0;
$hidden_n = 0;
$by_reason = [];
foreach ($tasks as $task) {
    if ($task->key_name === 'done') {
        continue;
    }
    $viewers = participants($task, $db);
    if ((int) $task->id === 25906) {
        $viewers = array_values(array_unique(array_merge($viewers, [39, 42])));
    }
    foreach ($viewers as $uid) {
        if ($uid <= 0) {
            continue;
        }
        $checked++;
        $ids = visible_ids_for($uid, '2026-11-18', '2026-11-18', $status_ids, $model, $users_model, $cache);
        if (!empty($ids[(int) $task->id])) {
            $visible++;
        } else {
            $hidden_n++;
            $user = $users_model->get_access_info($uid);
            $full = $db->query("SELECT submitted_for_review_at FROM rise_tasks WHERE id=" . (int) $task->id)->getRow();
            $task->submitted_for_review_at = $full ? $full->submitted_for_review_at : null;
            $reason = reason_hidden($task, $uid, $user, '2026-11-18', '2026-11-18', $db);
            $by_reason[$reason] = ($by_reason[$reason] ?? 0) + 1;
        }
    }
}
echo "=== CREATED FILTER 2026-11-18..2026-11-18 ===\n";
echo "pairs_checked=$checked visible=$visible hidden=$hidden_n\n";
echo "hidden_by_reason=" . json_encode($by_reason) . "\n";

// Focus 25906
foreach ([39, 42, 66] as $uid) {
    $ids = visible_ids_for($uid, '', '', $status_ids, $model, $users_model, $cache);
    $ids2 = visible_ids_for($uid, '2026-11-18', '2026-11-18', $status_ids, $model, $users_model, $cache);
    echo "focus25906 user=$uid no_date=" . (!empty($ids[25906]) ? 'Y' : 'N') . " nov18=" . (!empty($ids2[25906]) ? 'Y' : 'N') . "\n";
}

// Admin + specific_user_id 39 compare SQL
$admin_opts = [
    'specific_user_id' => 39,
    'project_status' => 'open',
    'status_ids' => $status_ids,
    'limit' => 10000,
    'skip' => 0,
];
$r = $model->get_details($admin_opts);
echo "admin_surya_no_date todo={$r['todo_count']} ip={$r['in_progress_count']} done={$r['done_count']} records={$r['recordsFiltered']}\n";
$r2 = $model->get_details($admin_opts + ['created_from' => '2026-11-18', 'created_to' => '2026-11-18']);
echo "admin_surya_nov18 todo={$r2['todo_count']} ip={$r2['in_progress_count']} done={$r2['done_count']} records={$r2['recordsFiltered']}\n";

$sql_count = $db->query("SELECT COUNT(*) c FROM rise_tasks t WHERE t.deleted=0 AND (t.assigned_to=39 OR FIND_IN_SET('39', t.collaborators))")->getRow()->c;
echo "sql_plain_surya_all_non_deleted=$sql_count\n";
