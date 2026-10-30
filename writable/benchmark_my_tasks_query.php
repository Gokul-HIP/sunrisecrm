<?php
/**
 * Benchmark my_tasks_list_data paginated SQL path (read-only).
 *
 * Usage:
 *   php writable/benchmark_my_tasks_query.php
 *   php writable/benchmark_my_tasks_query.php --iterations=5 --user-id=7
 *
 * Mirrors paginated Tasks_model::get_details() query order:
 *   1) Main paginated SELECT
 *   2) Collaborator hydration (batch users query if needed)
 *   3) Sub-task-count hydration
 *   4) Unread-notification hydration
 *   5) Count query
 *
 * Safe on production: SELECT only (+ read collaborator user rows), no writes.
 */

$iterations = 3;
$userId = 1;
$limit = 10;
$offset = 0;

foreach ($argv as $arg) {
    if (strpos($arg, '--iterations=') === 0) {
        $iterations = max(1, (int) substr($arg, 13));
    } elseif (strpos($arg, '--user-id=') === 0) {
        $userId = max(1, (int) substr($arg, 10));
    }
}

$dbConfigFile = dirname(__DIR__) . '/app/Config/Database.php';
if (!is_readable($dbConfigFile)) {
    fwrite(STDERR, "Cannot read Database.php\n");
    exit(1);
}

$configText = file_get_contents($dbConfigFile);
$activeConfig = '';
if (preg_match_all('/^[\t ]*public\s+\$default\s*=\s*\[(.*?)\];/ms', $configText, $matches)) {
    $activeConfig = end($matches[1]);
}
if (!$activeConfig) {
    fwrite(STDERR, "Could not parse active Database.php default config\n");
    exit(1);
}
preg_match("/'hostname'\s*=>\s*'([^']+)'/", $activeConfig, $hostMatch);
preg_match("/'username'\s*=>\s*'([^']+)'/", $activeConfig, $userMatch);
preg_match("/'password'\s*=>\s*'([^']*)'/", $activeConfig, $passMatch);
preg_match("/'database'\s*=>\s*'([^']+)'/", $activeConfig, $dbMatch);
preg_match("/'DBPrefix'\s*=>\s*'([^']*)'/", $activeConfig, $prefixMatch);

$hostname = $hostMatch[1] ?? 'localhost';
$username = $userMatch[1] ?? 'root';
$password = $passMatch[1] ?? '';
$database = $dbMatch[1] ?? 'sunrisecrm';
$prefix = $prefixMatch[1] ?? 'rise_';

if (strpos($hostname, ':') !== false) {
    list($host, $port) = explode(':', $hostname, 2);
} else {
    $host = $hostname;
    $port = 3306;
}

$mysqli = new mysqli($host, $username, $password, $database, (int) $port);
if ($mysqli->connect_error) {
    fwrite(STDERR, "DB connect failed: " . $mysqli->connect_error . "\n");
    exit(1);
}

$tasks = $prefix . 'tasks';
$users = $prefix . 'users';
$projects = $prefix . 'projects';
$milestones = $prefix . 'milestones';
$projectMembers = $prefix . 'project_members';
$taskStatus = $prefix . 'task_status';
$taskPriority = $prefix . 'task_priority';
$tickets = $prefix . 'tickets';
$notifications = $prefix . 'notifications';
$labels = $prefix . 'labels';

$coreFromJoinsWhere = "
FROM {$tasks}
LEFT JOIN {$users} ON {$users}.id = {$tasks}.assigned_to
LEFT JOIN {$projects} ON {$tasks}.project_id = {$projects}.id
LEFT JOIN {$milestones} ON {$tasks}.milestone_id = {$milestones}.id
LEFT JOIN {$taskStatus} ON {$tasks}.status_id = {$taskStatus}.id
LEFT JOIN {$taskPriority} ON {$tasks}.priority_id = {$taskPriority}.id
LEFT JOIN {$tickets} ON {$tasks}.ticket_id = {$tickets}.id
LEFT JOIN {$projectMembers} ON {$tasks}.project_id = {$projectMembers}.project_id AND {$projectMembers}.deleted = 0 AND {$projectMembers}.user_id = {$userId}
WHERE {$tasks}.deleted = 0
AND FIND_IN_SET({$projects}.status, 'open')
AND {$projectMembers}.user_id = {$userId}";

$labelsSelect = "(SELECT GROUP_CONCAT({$labels}.id, '--::--', {$labels}.title, '--::--', {$labels}.color, ':--::--:') FROM {$labels} WHERE FIND_IN_SET({$labels}.id, {$tasks}.labels)) AS labels_list";

$dataSql = "SELECT {$tasks}.*, {$taskStatus}.key_name AS status_key_name, {$taskStatus}.title AS status_title, {$taskStatus}.color AS status_color,
CONCAT({$users}.first_name, ' ', {$users}.last_name) AS assigned_to_user, {$users}.image AS assigned_to_avatar, {$users}.user_type,
{$projects}.title AS project_title, {$milestones}.title AS milestone_title,
IF({$tasks}.deadline IS NULL, {$milestones}.due_date, {$tasks}.deadline) AS deadline,
{$tickets}.title AS ticket_title,
NULL AS collaborator_list,
0 AS sub_task_count,
{$taskPriority}.title AS priority_title, {$taskPriority}.icon AS priority_icon, {$taskPriority}.color AS priority_color,
IF({$tasks}.deadline IS NULL, {$milestones}.title, '') AS deadline_milestone_title,
NULL AS unread,
{$labelsSelect}
{$coreFromJoinsWhere}
ORDER BY {$tasks}.id DESC
LIMIT {$limit} OFFSET {$offset}";

$countSql = "SELECT COUNT(DISTINCT {$tasks}.id) AS total_rows {$coreFromJoinsWhere}";

function timedQuery(mysqli $db, string $sql, bool $fetchMainRows = false): array {
    $start = microtime(true);
    $result = $db->query($sql);
    $ms = round((microtime(true) - $start) * 1000, 2);
    if (!$result) {
        return array('ms' => $ms, 'error' => $db->error, 'rows' => 0, 'data' => null, 'scalar' => null);
    }

    $data = null;
    $scalar = null;
    $rows = $result->num_rows;

    if (stripos($sql, 'COUNT(') !== false && stripos($sql, 'AS total_rows') !== false) {
        $row = $result->fetch_assoc();
        $scalar = $row ? (int) $row['total_rows'] : 0;
        $rows = 1;
    } elseif ($fetchMainRows) {
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $rows = count($data);
    }

    $result->free();
    return array('ms' => $ms, 'error' => null, 'rows' => $rows, 'data' => $data, 'scalar' => $scalar);
}

function hydrateCollaborators(mysqli $db, array $taskRows, string $usersTable): array {
    $start = microtime(true);
    $userIds = array();
    foreach ($taskRows as $row) {
        if (!empty($row['collaborators'])) {
            foreach (explode(',', $row['collaborators']) as $uid) {
                $uid = trim($uid);
                if ($uid !== '' && is_numeric($uid)) {
                    $userIds[$uid] = $uid;
                }
            }
        }
    }

    if (!$userIds) {
        return array('ms' => round((microtime(true) - $start) * 1000, 2), 'queries' => 0);
    }

    $idsList = implode(',', $userIds);
    $result = $db->query("SELECT id, first_name, last_name, image, user_type FROM {$usersTable} WHERE deleted=0 AND id IN ({$idsList})");
    if ($result) {
        $result->free();
    }

    return array('ms' => round((microtime(true) - $start) * 1000, 2), 'queries' => 1);
}

function runPaginatedPath(mysqli $db, string $dataSql, string $countSql, int $userId, string $tasks, string $notifications, string $users): array {
    $main = timedQuery($db, $dataSql, true);
    if ($main['error']) {
        return array('error' => $main['error']);
    }

    $collab = hydrateCollaborators($db, $main['data'] ?: array(), $users);

    $subTaskMs = 0;
    $unreadMs = 0;
    $taskIds = array();
    foreach ($main['data'] ?: array() as $row) {
        $taskIds[] = (int) $row['id'];
    }

    if ($taskIds) {
        $idsList = implode(',', $taskIds);
        $subTask = timedQuery($db, "SELECT parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} WHERE deleted=0 AND parent_task_id IN ({$idsList}) GROUP BY parent_task_id");
        if ($subTask['error']) {
            return array('error' => $subTask['error']);
        }
        $subTaskMs = $subTask['ms'];

        $unread = timedQuery($db, "SELECT DISTINCT task_id FROM {$notifications} WHERE task_id IN ({$idsList}) AND deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('{$userId}', read_by) AND user_id != {$userId}");
        if ($unread['error']) {
            return array('error' => $unread['error']);
        }
        $unreadMs = $unread['ms'];
    }

    $count = timedQuery($db, $countSql);
    if ($count['error']) {
        return array('error' => $count['error']);
    }

    $dataTotal = $main['ms'] + $collab['ms'] + $subTaskMs + $unreadMs;

    return array(
        'main_ms' => $main['ms'],
        'collab_ms' => $collab['ms'],
        'sub_task_ms' => $subTaskMs,
        'unread_ms' => $unreadMs,
        'data_total_ms' => $dataTotal,
        'count_ms' => $count['ms'],
        'combined_ms' => $dataTotal + $count['ms'],
        'page_rows' => count($taskIds),
        'matching_tasks' => $count['scalar'],
        'collab_queries' => $collab['queries'],
    );
}

function stats(array $values): array {
    sort($values);
    return array(
        'min' => min($values),
        'max' => max($values),
        'avg' => array_sum($values) / count($values),
        'median' => $values[(int) floor((count($values) - 1) / 2)],
    );
}

$version = $mysqli->query('SELECT VERSION() AS v')->fetch_assoc()['v'] ?? 'unknown';

echo "Benchmark: my_tasks_list_data paginated path\n";
echo "Database: {$database} @ {$host}:{$port} (MySQL {$version})\n";
echo "user_id={$userId}, limit={$limit}, iterations={$iterations}\n";
echo str_repeat('=', 72) . "\n";

$sample = runPaginatedPath($mysqli, $dataSql, $countSql, $userId, $tasks, $notifications, $users);
if (!empty($sample['error'])) {
    fwrite(STDERR, "SQL error: {$sample['error']}\n");
    exit(1);
}

echo sprintf("Sample run breakdown:\n");
echo sprintf("  Main SQL:                 %8.2f ms\n", $sample['main_ms']);
echo sprintf("  Collaborator hydration:   %8.2f ms (%d queries)\n", $sample['collab_ms'], $sample['collab_queries']);
echo sprintf("  Subtask hydration:        %8.2f ms\n", $sample['sub_task_ms']);
echo sprintf("  Unread hydration:         %8.2f ms\n", $sample['unread_ms']);
echo sprintf("  Data total:               %8.2f ms\n", $sample['data_total_ms']);
echo sprintf("  Count SQL:                %8.2f ms\n", $sample['count_ms']);
echo sprintf("  Combined:                 %8.2f ms\n", $sample['combined_ms']);
echo sprintf("  Actual matching tasks:    %d\n", $sample['matching_tasks']);
echo sprintf("  Paginated rows returned:  %d\n", $sample['page_rows']);
echo str_repeat('-', 72) . "\n";

$mainTimes = array();
$collabTimes = array();
$subTaskTimes = array();
$unreadTimes = array();
$dataTimes = array();
$countTimes = array();
$combinedTimes = array();
$matchingTasks = null;

for ($i = 1; $i <= $iterations; $i++) {
    $run = runPaginatedPath($mysqli, $dataSql, $countSql, $userId, $tasks, $notifications, $users);
    if (!empty($run['error'])) {
        fwrite(STDERR, "Run {$i} failed: {$run['error']}\n");
        exit(1);
    }

    $mainTimes[] = $run['main_ms'];
    $collabTimes[] = $run['collab_ms'];
    $subTaskTimes[] = $run['sub_task_ms'];
    $unreadTimes[] = $run['unread_ms'];
    $dataTimes[] = $run['data_total_ms'];
    $countTimes[] = $run['count_ms'];
    $combinedTimes[] = $run['combined_ms'];
    $matchingTasks = $run['matching_tasks'];

    echo sprintf(
        "Run %d: main=%8.2f collab=%6.2f sub=%6.2f unread=%6.2f data=%8.2f count=%8.2f combined=%8.2f\n",
        $i,
        $run['main_ms'],
        $run['collab_ms'],
        $run['sub_task_ms'],
        $run['unread_ms'],
        $run['data_total_ms'],
        $run['count_ms'],
        $run['combined_ms']
    );
}

$mainStats = stats($mainTimes);
$collabStats = stats($collabTimes);
$subStats = stats($subTaskTimes);
$unreadStats = stats($unreadTimes);
$dataStats = stats($dataTimes);
$countStats = stats($countTimes);
$combinedStats = stats($combinedTimes);

echo str_repeat('=', 72) . "\n";
echo "MEDIANS\n";
echo sprintf("  Main SQL:                 %8.2f ms\n", $mainStats['median']);
echo sprintf("  Collaborator hydration:   %8.2f ms\n", $collabStats['median']);
echo sprintf("  Subtask hydration:        %8.2f ms\n", $subStats['median']);
echo sprintf("  Unread hydration:         %8.2f ms\n", $unreadStats['median']);
echo sprintf("  Data total:               %8.2f ms\n", $dataStats['median']);
echo sprintf("  Count SQL:                %8.2f ms\n", $countStats['median']);
echo sprintf("  Combined:                 %8.2f ms\n", $combinedStats['median']);
echo sprintf("  Actual matching tasks:    %d\n", $matchingTasks);
echo "\nSQL queries per paginated get_details() path: 3-4 SELECTs (main + 0-1 collab + subtask + unread) + 1 count.\n";
echo "Run writable/production_perf_diagnosis.php on the PRODUCTION server for EXPLAIN/INDEX analysis.\n";

$mysqli->close();
