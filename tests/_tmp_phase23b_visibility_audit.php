<?php
/**
 * Phase 23b: entitlement vs Tasks list / Kanban (no optional filters).
 * Throwaway — local only.
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

$pass = 0;
$fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] $name\n";
        $pass++;
    } else {
        echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n";
        $fail++;
    }
}

echo "=== DROP CONDITIONS (get_details / list_data path) ===\n";
echo "Tasks_model.php:480  WHERE tasks.deleted=0\n";
echo "Tasks_model.php:280-283  project_status open via FIND_IN_SET (null-safe after Phase 23b)\n";
echo "Tasks_model.php:243-248  status_ids FIND_IN_SET (only when UI posts statuses)\n";
echo "Tasks_model.php:270-273  specific_user_id assignee|collaborator\n";
echo "Tasks_model.php:275-278  show_assigned_tasks_only_user_id assignee|collaborator\n";
echo "Tasks_model.php:372-376  project_member_id + LEFT JOIN project_members\n";
echo "Tasks_model.php:341-346  exclude_review: NOT (status=in_review AND submitted_for_review_at IS NOT NULL)\n";
echo "Tasks_model.php:328-329  created_from/to via _created_date_filter_where\n";
echo "Tasks_model.php:295-326  deadline / start_date range filters\n";
echo "Tasks_model.php:233-236  parent_task_id (only when set)\n";
echo "Tasks_model.php:391-397  LIMIT/OFFSET pagination\n";
echo "Tasks_model.php:469-476  JOINs are LEFT (users, projects, milestones, status, priority, ticket, awards)\n";
echo "Projects.php:5506  list always project_status=open\n";
echo "Projects.php:5526-5528  list sets exclude_review when workflow on\n";
echo "Projects.php:4155-4157  Kanban NO LONGER sets exclude_review (Phase 23b)\n";
echo "Search get_search_suggestion:1426-1444  no exclude_review; only deleted=0 + optional own-only\n\n";

$db = db_connect();
$model = model('App\Models\Tasks_model');
$users_model = model('App\Models\Users_model');
$ir = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
$workflow = (string) get_setting('task_review_workflow_enabled') === '1';

function entitled_sql($db, $user_id, $mode, $exclude_review, $ir) {
    // mode: admin | project | own_only — mirrors intentional API filters (not creator-inclusive)
    $sql = "SELECT t.id FROM rise_tasks t
            LEFT JOIN rise_projects p ON p.id = t.project_id
            WHERE t.deleted = 0
              AND (p.id IS NULL OR FIND_IN_SET(p.status,'open'))";

    if ($mode === 'project') {
        $sql .= " AND (
            (t.project_id = 0 OR t.project_id IS NULL)
            OR EXISTS (SELECT 1 FROM rise_project_members pm WHERE pm.deleted=0 AND pm.project_id=t.project_id AND pm.user_id=$user_id)
        )";
    } else if ($mode === 'own_only') {
        // When both project_member_id and show_assigned are set (controller for non-admin own-only),
        // both apply. Entitlement here matches the stricter own_only assignee/collaborator rule;
        // project membership is also applied in list_opts for the API call.
        $sql .= " AND (t.assigned_to=$user_id OR FIND_IN_SET('$user_id', t.collaborators))";
        $sql .= " AND (
            (t.project_id = 0 OR t.project_id IS NULL)
            OR EXISTS (SELECT 1 FROM rise_project_members pm WHERE pm.deleted=0 AND pm.project_id=t.project_id AND pm.user_id=$user_id)
        )";
    }

    if ($exclude_review && $ir) {
        $sql .= " AND NOT (t.status_id=$ir AND t.submitted_for_review_at IS NOT NULL)";
    }
    return $sql;
}

function fetch_ids($db, $sql) {
    $ids = [];
    foreach ($db->query($sql)->getResult() as $r) {
        $ids[(int) $r->id] = true;
    }
    return $ids;
}

function api_list_ids($model, $options) {
    $options = array_merge([
        'project_status' => 'open',
        'limit' => 50000,
        'skip' => 0,
        'order_by' => 'id',
        'order_dir' => 'DESC',
    ], $options);
    $result = $model->get_details($options);
    $rows = is_array($result) ? ($result['data'] ?? []) : $result->getResult();
    $ids = [];
    foreach ($rows as $r) {
        $ids[(int) $r->id] = true;
    }
    $counts = is_array($result) ? [
        'todo' => (int) ($result['todo_count'] ?? 0),
        'ip' => (int) ($result['in_progress_count'] ?? 0),
        'ir' => (int) ($result['in_review_count'] ?? 0),
        'ch' => (int) ($result['changes_count'] ?? 0),
        'done' => (int) ($result['done_count'] ?? 0),
        'records' => (int) ($result['recordsFiltered'] ?? count($ids)),
    ] : null;
    return [$ids, $counts, $rows];
}

function api_kanban_ids($model, $options) {
    $options = array_merge([
        'project_status' => 'open',
    ], $options);
    // all columns: no status_id filter
    $result = $model->get_kanban_details($options)->getResult();
    $ids = [];
    foreach ($result as $r) {
        $ids[(int) $r->id] = true;
    }
    return $ids;
}

function reason_for_miss($db, $task_id, $user_id, $mode, $exclude_review, $ir) {
    $t = $db->query("SELECT t.*, p.status AS pstatus, p.deleted AS pdel, p.id AS pid
        FROM rise_tasks t LEFT JOIN rise_projects p ON p.id=t.project_id WHERE t.id=$task_id")->getRow();
    if (!$t) {
        return 'not_found';
    }
    $reasons = [];
    if ((int) $t->deleted === 1) {
        $reasons[] = 'task_deleted';
    }
    if ($t->pid && $t->pstatus && strpos(',' . $t->pstatus . ',', ',open,') === false && $t->pstatus !== 'open') {
        $reasons[] = 'project_not_open:' . $t->pstatus;
    }
    if ($mode === 'project') {
        $m = $db->query("SELECT id FROM rise_project_members WHERE deleted=0 AND project_id=" . (int) $t->project_id . " AND user_id=$user_id")->getRow();
        if (!$m) {
            $reasons[] = 'not_project_member';
        }
    }
    if ($mode === 'own_only') {
        $ok = ((int) $t->assigned_to === $user_id);
        if (!$ok && $t->collaborators) {
            $ok = in_array($user_id, array_map('intval', explode(',', $t->collaborators)), true);
        }
        if (!$ok) {
            $reasons[] = 'not_assignee_or_collaborator';
        }
    }
    if ($exclude_review && $ir && (int) $t->status_id === $ir && $t->submitted_for_review_at) {
        $reasons[] = 'exclude_review_stamped_in_review';
    }
    if (!$reasons) {
        $reasons[] = 'unexplained';
    }
    return implode(',', $reasons);
}

// --- Create ephemeral edge-case tasks (cleanup at end) ---
$created = [];
$open_project = $db->query("SELECT id FROM rise_projects WHERE deleted=0 AND status='open' ORDER BY id DESC LIMIT 1")->getRow();
$open_pid = $open_project ? (int) $open_project->id : 0;
$deleted_user = $db->query("SELECT id FROM rise_users WHERE deleted=1 ORDER BY id LIMIT 1")->getRow();
$du = $deleted_user ? (int) $deleted_user->id : 0;
$status_todo = (int) $db->query("SELECT id FROM rise_task_status WHERE key_name='to_do' AND deleted=0")->getRow()->id;

function insert_task($db, $data, &$created) {
    $cols = [];
    $vals = [];
    foreach ($data as $k => $v) {
        $cols[] = $k;
        if ($v === null) {
            $vals[] = "NULL";
        } else {
            $vals[] = "'" . $db->escapeString((string) $v) . "'";
        }
    }
    $db->query("INSERT INTO rise_tasks (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")");
    $id = (int) $db->insertID();
    $created[] = $id;
    return $id;
}

$edge = [];
if ($open_pid && $du && $status_todo) {
    $edge['deleted_assignee'] = insert_task($db, [
        'title' => 'PHASE23B deleted assignee',
        'description' => '',
        'project_id' => $open_pid,
        'milestone_id' => 0,
        'assigned_to' => $du,
        'deadline' => null,
        'labels' => '',
        'points' => 1,
        'status_id' => $status_todo,
        'status_changed_at' => null,
        'priority_id' => 0,
        'collaborators' => '',
        'created_date' => date('Y-m-d'),
        'created_by' => 66,
        'deleted' => 0,
        'sort' => 0,
        'parent_task_id' => 0,
    ], $created);
}
if ($open_pid && $status_todo) {
    $edge['no_project'] = insert_task($db, [
        'title' => 'PHASE23B project_id 0',
        'description' => '',
        'project_id' => 0,
        'milestone_id' => 0,
        'assigned_to' => 39,
        'deadline' => null,
        'labels' => '',
        'points' => 1,
        'status_id' => $status_todo,
        'priority_id' => 0,
        'collaborators' => '',
        'created_date' => date('Y-m-d'),
        'created_by' => 66,
        'deleted' => 0,
        'sort' => 0,
        'parent_task_id' => 0,
    ], $created);
    $edge['zero_created'] = insert_task($db, [
        'title' => 'PHASE23B zero created_date',
        'description' => '',
        'project_id' => $open_pid,
        'milestone_id' => 0,
        'assigned_to' => 39,
        'deadline' => '2020-01-01',
        'labels' => '',
        'points' => 1,
        'status_id' => $status_todo,
        'priority_id' => 0,
        'collaborators' => '',
        'created_date' => '0000-00-00',
        'created_by' => 66,
        'deleted' => 0,
        'sort' => 0,
        'parent_task_id' => 0,
    ], $created);
    $parent = insert_task($db, [
        'title' => 'PHASE23B parent',
        'description' => '',
        'project_id' => $open_pid,
        'milestone_id' => 0,
        'assigned_to' => 39,
        'deadline' => null,
        'labels' => '',
        'points' => 1,
        'status_id' => $status_todo,
        'priority_id' => 0,
        'collaborators' => '',
        'created_date' => date('Y-m-d'),
        'created_by' => 66,
        'deleted' => 0,
        'sort' => 0,
        'parent_task_id' => 0,
    ], $created);
    $edge['child'] = insert_task($db, [
        'title' => 'PHASE23B child',
        'description' => '',
        'project_id' => $open_pid,
        'milestone_id' => 0,
        'assigned_to' => 39,
        'deadline' => null,
        'labels' => '',
        'points' => 1,
        'status_id' => $status_todo,
        'priority_id' => 0,
        'collaborators' => '',
        'created_date' => date('Y-m-d'),
        'created_by' => 66,
        'deleted' => 0,
        'sort' => 0,
        'parent_task_id' => $parent,
    ], $created);
    $edge['collab_only'] = insert_task($db, [
        'title' => 'PHASE23B collab-only for 42',
        'description' => '',
        'project_id' => $open_pid,
        'milestone_id' => 0,
        'assigned_to' => 39,
        'deadline' => null,
        'labels' => '',
        'points' => 1,
        'status_id' => $status_todo,
        'priority_id' => 0,
        'collaborators' => '42',
        'created_date' => date('Y-m-d'),
        'created_by' => 66,
        'deleted' => 0,
        'sort' => 0,
        'parent_task_id' => 0,
    ], $created);
}

echo "edge_tasks=" . json_encode($edge) . "\n\n";

$scenarios = [
    ['label' => 'admin_66', 'user' => 66, 'mode' => 'admin', 'list_opts' => [], 'exclude_review_list' => $workflow],
    ['label' => 'team_39_project', 'user' => 39, 'mode' => 'project', 'list_opts' => ['project_member_id' => 39], 'exclude_review_list' => $workflow],
    ['label' => 'sim_own_only_39', 'user' => 39, 'mode' => 'own_only', 'list_opts' => ['show_assigned_tasks_only_user_id' => 39, 'project_member_id' => 39], 'exclude_review_list' => $workflow],
    ['label' => 'manager_42_manage_all', 'user' => 42, 'mode' => 'admin', 'list_opts' => [], 'exclude_review_list' => $workflow], // role has manage_all
];

$unexplained = [];

foreach ($scenarios as $sc) {
    echo "=== SCENARIO {$sc['label']} ===\n";
    $entitled_list = fetch_ids($db, entitled_sql($db, $sc['user'], $sc['mode'], $sc['exclude_review_list'], $ir));
    $entitled_kanban = fetch_ids($db, entitled_sql($db, $sc['user'], $sc['mode'], false, $ir)); // kanban keeps review

    $list_opts = $sc['list_opts'];
    if ($sc['exclude_review_list']) {
        $list_opts['exclude_review_tasks_for_assignee'] = true;
    }
    list($list_ids, $counts) = api_list_ids($model, $list_opts);
    $kanban_opts = $sc['list_opts']; // no exclude_review
    unset($kanban_opts['exclude_review_tasks_for_assignee']);
    $kanban_ids = api_kanban_ids($model, $kanban_opts);

    $miss_list = array_diff_key($entitled_list, $list_ids);
    $extra_list = array_diff_key($list_ids, $entitled_list);
    $miss_kanban = array_diff_key($entitled_kanban, $kanban_ids);
    $extra_kanban = array_diff_key($kanban_ids, $entitled_kanban);

    echo "entitled_list=" . count($entitled_list) . " api_list=" . count($list_ids) . " miss=" . count($miss_list) . " extra=" . count($extra_list) . "\n";
    echo "entitled_kanban=" . count($entitled_kanban) . " api_kanban=" . count($kanban_ids) . " miss=" . count($miss_kanban) . " extra=" . count($extra_kanban) . "\n";
    if ($counts) {
        $sum = $counts['todo'] + $counts['ip'] + $counts['ir'] + $counts['ch'] + $counts['done'];
        echo "counters todo={$counts['todo']} ip={$counts['ip']} ir={$counts['ir']} ch={$counts['ch']} done={$counts['done']} sum=$sum records={$counts['records']}\n";
        // Row count for list without status filter should equal records; named counters may miss custom key_name statuses
        $other = $counts['records'] - $sum;
        echo "counter_gap_other_or_null_key=$other\n";
        check("{$sc['label']} counter sum <= records", $sum <= $counts['records'] + 0, "sum=$sum records={$counts['records']}");
    }

    $show_miss = 0;
    foreach ($miss_list as $id => $_) {
        $reason = reason_for_miss($db, $id, $sc['user'], $sc['mode'], $sc['exclude_review_list'], $ir);
        if ($reason === 'unexplained' || strpos($reason, 'unexplained') !== false) {
            $unexplained[] = "list:{$sc['label']}:$id:$reason";
        }
        if ($show_miss < 15) {
            echo "MISS_LIST id=$id reason=$reason\n";
            $show_miss++;
        }
    }
    if (count($miss_list) > 15) {
        echo "MISS_LIST ... +" . (count($miss_list) - 15) . " more\n";
    }
    $show_miss = 0;
    foreach ($miss_kanban as $id => $_) {
        $reason = reason_for_miss($db, $id, $sc['user'], $sc['mode'], false, $ir);
        if (strpos($reason, 'unexplained') !== false) {
            $unexplained[] = "kanban:{$sc['label']}:$id:$reason";
        }
        if ($show_miss < 15) {
            echo "MISS_KANBAN id=$id reason=$reason\n";
            $show_miss++;
        }
    }

    $unexp_list = 0;
    foreach (array_keys($miss_list) as $mid) {
        if (strpos(reason_for_miss($db, $mid, $sc['user'], $sc['mode'], $sc['exclude_review_list'], $ir), 'unexplained') !== false) {
            $unexp_list++;
        }
    }
    $unexp_kanban = 0;
    foreach (array_keys($miss_kanban) as $mid) {
        if (strpos(reason_for_miss($db, $mid, $sc['user'], $sc['mode'], false, $ir), 'unexplained') !== false) {
            $unexp_kanban++;
        }
    }
    check("{$sc['label']} list unexplained misses=0", $unexp_list === 0, "unexplained=$unexp_list miss_total=" . count($miss_list));
    check("{$sc['label']} kanban unexplained misses=0", $unexp_kanban === 0, "unexplained=$unexp_kanban miss_total=" . count($miss_kanban));

    // Focus IDs
    foreach ([25906, 25950, 25956] as $fid) {
        $in_e = !empty($entitled_list[$fid]);
        $in_l = !empty($list_ids[$fid]);
        $in_k = !empty($kanban_ids[$fid]);
        echo "focus $fid entitled=" . ($in_e ? 'Y' : 'N') . " list=" . ($in_l ? 'Y' : 'N') . " kanban=" . ($in_k ? 'Y' : 'N') . "\n";
        if ($in_e) {
            check("{$sc['label']} focus $fid on list", $in_l);
            check("{$sc['label']} focus $fid on kanban", $in_k);
        }
    }
}

// (a) deleted assignee
echo "\n=== (a) DELETED ASSIGNEE ===\n";
if (!empty($edge['deleted_assignee'])) {
    $tid = $edge['deleted_assignee'];
    list($ids) = api_list_ids($model, ['exclude_review_tasks_for_assignee' => $workflow]);
    $k = api_kanban_ids($model, []);
    check('admin list shows task with deleted assignee', !empty($ids[$tid]), "id=$tid");
    check('admin kanban shows task with deleted assignee', !empty($k[$tid]), "id=$tid");
    echo "users JOIN is LEFT — no users.deleted=0 predicate in get_details (Tasks_model.php:471)\n";
} else {
    check('deleted assignee fixture created', false, 'skipped');
}

// (b) statuses 4,7,9
echo "\n=== (b) STATUSES 4 / 7 / 9 ===\n";
foreach ([4, 7, 9] as $sid) {
    $st = $db->query("SELECT id,title,key_name,deleted,hide_from_kanban FROM rise_task_status WHERE id=$sid")->getRow();
    $open_c = (int) $db->query("SELECT COUNT(*) c FROM rise_tasks t LEFT JOIN rise_projects p ON p.id=t.project_id WHERE t.deleted=0 AND t.status_id=$sid AND (p.id IS NULL OR FIND_IN_SET(p.status,'open'))")->getRow()->c;
    list($ids, $counts, $rows) = api_list_ids($model, []);
    $k = api_kanban_ids($model, ['status_id' => $sid]);
    $in_list = 0;
    foreach ($rows as $r) {
        if ((int) $r->status_id === $sid) {
            $in_list++;
        }
    }
    echo "status $sid title={$st->title} key={$st->key_name} deleted={$st->deleted} open_tasks=$open_c in_admin_list=$in_list kanban_col=" . count($k) . "\n";
    if ($open_c > 0) {
        check("status $sid open tasks appear in unfiltered list", $in_list === $open_c, "list=$in_list open=$open_c");
        check("status $sid open tasks appear in kanban column", count($k) === $open_c, "kanban=" . count($k));
    } else {
        check("status $sid has no open-project tasks (closed-only / none)", true);
    }
}
echo "Counters key off status.key_name (Tasks_model.php:559-563); empty key_name (Brainstorming/completed) is NOT To do/Done — gap shows as other.\n";
echo "Deleted statuses 7/9 excluded from Kanban column list (Task_status_model.php:35 deleted=0).\n";

// (c) In review stamped
echo "\n=== (c) IN REVIEW STAMPED ===\n";
$top = $db->query("SELECT assigned_to, COUNT(*) c FROM rise_tasks WHERE deleted=0 AND status_id=$ir AND submitted_for_review_at IS NOT NULL GROUP BY assigned_to ORDER BY c DESC LIMIT 1")->getRow();
if ($top) {
    $uid = (int) $top->assigned_to;
    $n = (int) $top->c;
    echo "assignee=$uid stamped_in_review=$n\n";
    list($ids) = api_list_ids($model, [
        'project_member_id' => $uid,
        'show_assigned_tasks_only_user_id' => $uid,
        'exclude_review_tasks_for_assignee' => true,
    ]);
    $stamped = fetch_ids($db, "SELECT id FROM rise_tasks WHERE deleted=0 AND status_id=$ir AND submitted_for_review_at IS NOT NULL AND assigned_to=$uid");
    $hidden = 0;
    foreach ($stamped as $id => $_) {
        if (empty($ids[$id])) {
            $hidden++;
        }
    }
    check('stamped in_review hidden from assignee Tasks list', $hidden === count($stamped), "hidden=$hidden total=" . count($stamped));

    $k = api_kanban_ids($model, [
        'project_member_id' => $uid,
        'show_assigned_tasks_only_user_id' => $uid,
        // no exclude_review
    ]);
    $shown_k = 0;
    foreach ($stamped as $id => $_) {
        if (!empty($k[$id])) {
            $shown_k++;
        }
    }
    check('stamped in_review visible on Kanban for assignee', $shown_k === count($stamped), "shown=$shown_k total=" . count($stamped));

    // creators' review queue
    $creators = $db->query("SELECT DISTINCT created_by FROM rise_tasks WHERE deleted=0 AND status_id=$ir AND submitted_for_review_at IS NOT NULL AND assigned_to=$uid AND created_by>0 LIMIT 5")->getResult();
    $queue_ok = 0;
    foreach ($creators as $cr) {
        $cid = (int) $cr->created_by;
        $q = $model->get_details([
            'awaiting_review_for_user_id' => $cid,
            'limit' => 50000,
            'skip' => 0,
        ]);
        $qids = [];
        $qrows = is_array($q) ? ($q['data'] ?? []) : $q->getResult();
        foreach ($qrows as $r) {
            $qids[(int) $r->id] = true;
        }
        $need = fetch_ids($db, "SELECT id FROM rise_tasks WHERE deleted=0 AND status_id=$ir AND submitted_for_review_at IS NOT NULL AND assigned_to=$uid AND created_by=$cid");
        $miss = array_diff_key($need, $qids);
        if (!$miss) {
            $queue_ok++;
        }
        echo "creator_queue user=$cid need=" . count($need) . " miss=" . count($miss) . "\n";
    }
    check('creators review queue contains their stamped tasks', $queue_ok === count($creators), "ok=$queue_ok creators=" . count($creators));

    // search
    $sample = array_key_first($stamped);
    if ($sample) {
        $sug = $model->get_search_suggestion((string) $sample, ['show_assigned_tasks_only_user_id' => $uid])->getResult();
        $found = false;
        foreach ($sug as $s) {
            if ((int) $s->id === (int) $sample) {
                $found = true;
            }
        }
        check('task search finds stamped in_review by id', $found, "id=$sample");
    }
}

// Edge fixtures visibility for admin
echo "\n=== EDGE FIXTURES (admin list/kanban) ===\n";
list($aids) = api_list_ids($model, ['exclude_review_tasks_for_assignee' => $workflow]);
$ak = api_kanban_ids($model, []);
foreach ($edge as $name => $tid) {
    check("edge $name on list", !empty($aids[$tid]), "id=$tid");
    check("edge $name on kanban", !empty($ak[$tid]), "id=$tid");
}

// Creator own-only proposal (no apply)
echo "\n=== CREATOR + show_assigned_tasks_only (proposal only) ===\n";
echo "Filter today (Tasks_model.php:275-278):\n";
echo "  AND (assigned_to=USER OR FIND_IN_SET(USER, collaborators))\n";
echo "Create cases for own-only user:\n";
echo "  (a) assigned to self → VISIBLE list+kanban+counters\n";
echo "  (b) unassigned (assigned_to=0) → HIDDEN (not assignee/collaborator)\n";
echo "  (c) assigned to someone else → HIDDEN even if created_by=self\n";
echo "Proposed change (DO NOT APPLY): extend own-only to\n";
echo "  AND (assigned_to=USER OR FIND_IN_SET(USER, collaborators) OR created_by=USER)\n";
echo "  in get_details, get_kanban_details, and get_search_suggestion.\n";

// Cleanup
foreach ($created as $id) {
    $db->query("DELETE FROM rise_tasks WHERE id=$id");
}
echo "\ncleaned_ephemeral=" . count($created) . "\n";
echo "unexplained=" . json_encode($unexplained) . "\n";
echo "PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
