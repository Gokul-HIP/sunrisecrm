<?php
/**
 * READ-ONLY performance investigation for my_tasks_list_data SQL.
 * Does not modify application code or database.
 */
$userId = 7;
$limit = 10;
$offset = 0;
foreach ($argv as $arg) {
    if (strpos($arg, '--user-id=') === 0) {
        $userId = max(1, (int) substr($arg, 10));
    }
}

$dbConfigFile = dirname(__DIR__) . '/app/Config/Database.php';
$configText = file_get_contents($dbConfigFile);
preg_match_all('/^[\t ]*public\s+\$default\s*=\s*\[(.*?)\];/ms', $configText, $matches);
$activeConfig = end($matches[1]);
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

function timedQuery(mysqli $db, string $sql): array {
    $start = microtime(true);
    $result = $db->query($sql);
    $ms = round((microtime(true) - $start) * 1000, 2);
    if (!$result) {
        return array('ms' => $ms, 'error' => $db->error, 'rows' => 0);
    }
    $rows = is_bool($result) ? 0 : $result->num_rows;
    if (!is_bool($result)) {
        $result->free();
    }
    return array('ms' => $ms, 'error' => null, 'rows' => $rows);
}

function runExplain(mysqli $db, string $sql, string $label): void {
    echo "\n=== EXPLAIN: {$label} ===\n";
    $explainSql = "EXPLAIN " . $sql;
    $result = $db->query($explainSql);
    if (!$result) {
        echo "ERROR: " . $db->error . "\n";
        return;
    }
    $first = true;
    while ($row = $result->fetch_assoc()) {
        if ($first) {
            echo implode("\t", array_keys($row)) . "\n";
            $first = false;
        }
        echo implode("\t", array_map(function ($v) {
            return str_replace("\t", " ", (string) $v);
        }, $row)) . "\n";
    }
    $result->free();
}

function showIndexes(mysqli $db, string $table): void {
    echo "\n=== SHOW INDEX FROM {$table} ===\n";
    $result = $db->query("SHOW INDEX FROM {$table}");
    if (!$result) {
        echo "ERROR: " . $db->error . "\n";
        return;
    }
    $cols = array('Table', 'Non_unique', 'Key_name', 'Seq_in_index', 'Column_name', 'Cardinality');
    printf("%-20s %-10s %-30s %-5s %-20s %-12s\n", ...$cols);
    while ($row = $result->fetch_assoc()) {
        printf(
            "%-20s %-10s %-30s %-5s %-20s %-12s\n",
            $row['Table'],
            $row['Non_unique'],
            $row['Key_name'],
            $row['Seq_in_index'],
            $row['Column_name'],
            $row['Cardinality'] ?? ''
        );
    }
    $result->free();
}

$subTaskCountsJoin = " LEFT JOIN (SELECT sub_tasks_table.parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} AS sub_tasks_table WHERE sub_tasks_table.deleted = 0 GROUP BY sub_tasks_table.parent_task_id) AS sub_task_counts ON sub_task_counts.parent_task_id = {$tasks}.id";

$notificationJoin = " LEFT JOIN (SELECT {$notifications}.task_id FROM {$notifications} WHERE {$notifications}.deleted = 0 AND {$notifications}.event = 'project_task_commented' AND !FIND_IN_SET('{$userId}', {$notifications}.read_by) AND {$notifications}.user_id != {$userId} GROUP BY {$notifications}.task_id) AS notification_table ON notification_table.task_id = {$tasks}.id";

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

$fromJoinsWhereFull = "
FROM {$tasks}
LEFT JOIN {$users} ON {$users}.id = {$tasks}.assigned_to
LEFT JOIN {$projects} ON {$tasks}.project_id = {$projects}.id
LEFT JOIN {$milestones} ON {$tasks}.milestone_id = {$milestones}.id
LEFT JOIN {$taskStatus} ON {$tasks}.status_id = {$taskStatus}.id
LEFT JOIN {$taskPriority} ON {$tasks}.priority_id = {$taskPriority}.id
LEFT JOIN {$tickets} ON {$tasks}.ticket_id = {$tickets}.id
LEFT JOIN {$projectMembers} ON {$tasks}.project_id = {$projectMembers}.project_id AND {$projectMembers}.deleted = 0 AND {$projectMembers}.user_id = {$userId}
{$subTaskCountsJoin}
{$notificationJoin}
WHERE {$tasks}.deleted = 0
AND FIND_IN_SET({$projects}.status, 'open')
AND {$projectMembers}.user_id = {$userId}";

$labelsSelect = "(SELECT GROUP_CONCAT({$labels}.title, ', ') FROM {$labels} WHERE FIND_IN_SET({$labels}.id, {$tasks}.labels)) AS labels_list";

$selectCoreWithDerived = "{$tasks}.*, {$taskStatus}.key_name AS status_key_name, {$taskStatus}.title AS status_title, {$taskStatus}.color AS status_color,
CONCAT({$users}.first_name, ' ', {$users}.last_name) AS assigned_to_user, {$users}.image AS assigned_to_avatar, {$users}.user_type,
{$projects}.title AS project_title, {$milestones}.title AS milestone_title,
IF({$tasks}.deadline IS NULL, {$milestones}.due_date, {$tasks}.deadline) AS deadline,
{$tickets}.title AS ticket_title,
NULL AS collaborator_list,
IFNULL(sub_task_counts.sub_task_count, 0) AS sub_task_count,
{$taskPriority}.title AS priority_title, {$taskPriority}.icon AS priority_icon, {$taskPriority}.color AS priority_color,
IF({$tasks}.deadline IS NULL, {$milestones}.title, '') AS deadline_milestone_title,
notification_table.task_id AS unread";

$selectCoreNoNotif = str_replace(
    "notification_table.task_id AS unread",
    "NULL AS unread",
    $selectCoreWithDerived
);
$selectCoreNoSubTask = str_replace(
    "IFNULL(sub_task_counts.sub_task_count, 0) AS sub_task_count,",
    "0 AS sub_task_count,",
    $selectCoreWithDerived
);
$selectCoreNoDerived = "{$tasks}.*, {$taskStatus}.key_name AS status_key_name, {$taskStatus}.title AS status_title, {$taskStatus}.color AS status_color,
CONCAT({$users}.first_name, ' ', {$users}.last_name) AS assigned_to_user, {$users}.image AS assigned_to_avatar, {$users}.user_type,
{$projects}.title AS project_title, {$milestones}.title AS milestone_title,
IF({$tasks}.deadline IS NULL, {$milestones}.due_date, {$tasks}.deadline) AS deadline,
{$tickets}.title AS ticket_title,
NULL AS collaborator_list,
0 AS sub_task_count,
{$taskPriority}.title AS priority_title, {$taskPriority}.icon AS priority_icon, {$taskPriority}.color AS priority_color,
IF({$tasks}.deadline IS NULL, {$milestones}.title, '') AS deadline_milestone_title,
NULL AS unread";

$orderLimit = "ORDER BY {$tasks}.id DESC LIMIT {$limit} OFFSET {$offset}";

$variants = array(
    'A_current_full' => "SELECT {$selectCoreWithDerived}, {$labelsSelect} {$fromJoinsWhereFull} {$orderLimit}",
    'B_no_labels_subquery' => "SELECT {$selectCoreWithDerived}, NULL AS labels_list {$fromJoinsWhereFull} {$orderLimit}",
    'C_no_notification_join' => "SELECT {$selectCoreNoNotif}, {$labelsSelect}
FROM {$tasks}
LEFT JOIN {$users} ON {$users}.id = {$tasks}.assigned_to
LEFT JOIN {$projects} ON {$tasks}.project_id = {$projects}.id
LEFT JOIN {$milestones} ON {$tasks}.milestone_id = {$milestones}.id
LEFT JOIN {$taskStatus} ON {$tasks}.status_id = {$taskStatus}.id
LEFT JOIN {$taskPriority} ON {$tasks}.priority_id = {$taskPriority}.id
LEFT JOIN {$tickets} ON {$tasks}.ticket_id = {$tickets}.id
LEFT JOIN {$projectMembers} ON {$tasks}.project_id = {$projectMembers}.project_id AND {$projectMembers}.deleted = 0 AND {$projectMembers}.user_id = {$userId}
{$subTaskCountsJoin}
WHERE {$tasks}.deleted = 0 AND FIND_IN_SET({$projects}.status, 'open') AND {$projectMembers}.user_id = {$userId}
{$orderLimit}",
    'D_no_sub_task_counts' => "SELECT {$selectCoreNoSubTask}, {$labelsSelect}
FROM {$tasks}
LEFT JOIN {$users} ON {$users}.id = {$tasks}.assigned_to
LEFT JOIN {$projects} ON {$tasks}.project_id = {$projects}.id
LEFT JOIN {$milestones} ON {$tasks}.milestone_id = {$milestones}.id
LEFT JOIN {$taskStatus} ON {$tasks}.status_id = {$taskStatus}.id
LEFT JOIN {$taskPriority} ON {$tasks}.priority_id = {$taskPriority}.id
LEFT JOIN {$tickets} ON {$tasks}.ticket_id = {$tickets}.id
LEFT JOIN {$projectMembers} ON {$tasks}.project_id = {$projectMembers}.project_id AND {$projectMembers}.deleted = 0 AND {$projectMembers}.user_id = {$userId}
{$notificationJoin}
WHERE {$tasks}.deleted = 0 AND FIND_IN_SET({$projects}.status, 'open') AND {$projectMembers}.user_id = {$userId}
{$orderLimit}",
    'E_core_only_no_derived' => "SELECT {$tasks}.id, {$tasks}.title, {$projects}.title AS project_title
FROM {$tasks}
LEFT JOIN {$projects} ON {$tasks}.project_id = {$projects}.id
LEFT JOIN {$projectMembers} ON {$tasks}.project_id = {$projectMembers}.project_id AND {$projectMembers}.deleted = 0 AND {$projectMembers}.user_id = {$userId}
WHERE {$tasks}.deleted = 0 AND FIND_IN_SET({$projects}.status, 'open') AND {$projectMembers}.user_id = {$userId}
{$orderLimit}",
    'F_no_find_in_set_status' => "SELECT {$selectCoreWithDerived}, {$labelsSelect}
FROM {$tasks}
LEFT JOIN {$users} ON {$users}.id = {$tasks}.assigned_to
LEFT JOIN {$projects} ON {$tasks}.project_id = {$projects}.id
LEFT JOIN {$milestones} ON {$tasks}.milestone_id = {$milestones}.id
LEFT JOIN {$taskStatus} ON {$tasks}.status_id = {$taskStatus}.id
LEFT JOIN {$taskPriority} ON {$tasks}.priority_id = {$taskPriority}.id
LEFT JOIN {$tickets} ON {$tasks}.ticket_id = {$tickets}.id
LEFT JOIN {$projectMembers} ON {$tasks}.project_id = {$projectMembers}.project_id AND {$projectMembers}.deleted = 0 AND {$projectMembers}.user_id = {$userId}
{$subTaskCountsJoin}
{$notificationJoin}
WHERE {$tasks}.deleted = 0 AND {$projects}.status = 'open' AND {$projectMembers}.user_id = {$userId}
{$orderLimit}",
    'G_no_both_derived' => "SELECT {$selectCoreNoDerived}, NULL AS labels_list
FROM {$tasks}
LEFT JOIN {$users} ON {$users}.id = {$tasks}.assigned_to
LEFT JOIN {$projects} ON {$tasks}.project_id = {$projects}.id
LEFT JOIN {$milestones} ON {$tasks}.milestone_id = {$milestones}.id
LEFT JOIN {$taskStatus} ON {$tasks}.status_id = {$taskStatus}.id
LEFT JOIN {$taskPriority} ON {$tasks}.priority_id = {$taskPriority}.id
LEFT JOIN {$tickets} ON {$tasks}.ticket_id = {$tickets}.id
LEFT JOIN {$projectMembers} ON {$tasks}.project_id = {$projectMembers}.project_id AND {$projectMembers}.deleted = 0 AND {$projectMembers}.user_id = {$userId}
WHERE {$tasks}.deleted = 0 AND FIND_IN_SET({$projects}.status, 'open') AND {$projectMembers}.user_id = {$userId}
{$orderLimit}",
);

$countSql = "SELECT COUNT(DISTINCT {$tasks}.id) AS total_rows {$coreFromJoinsWhere}";

echo "MySQL version: ";
$v = $mysqli->query('SELECT VERSION() AS v')->fetch_assoc();
echo $v['v'] . "\n";
echo "Investigation user_id={$userId}, limit={$limit}\n";
echo str_repeat('=', 70) . "\n";

foreach (array($tasks, $notifications, $projectMembers, $labels, $projects) as $t) {
    showIndexes($mysqli, $t);
}

echo "\n=== TABLE STATS ===\n";
$statsQueries = array(
    'active_tasks' => "SELECT COUNT(*) c FROM {$tasks} WHERE deleted=0",
    'notifications_all' => "SELECT COUNT(*) c FROM {$notifications} WHERE deleted=0",
    'notifications_event' => "SELECT COUNT(*) c FROM {$notifications} WHERE deleted=0 AND event='project_task_commented'",
    'notifications_unread_derived' => "SELECT COUNT(*) c FROM (SELECT task_id FROM {$notifications} WHERE deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('{$userId}', read_by) AND user_id != {$userId} GROUP BY task_id) x",
    'pm_user7' => "SELECT COUNT(*) c FROM {$projectMembers} WHERE deleted=0 AND user_id={$userId}",
    'count_query_result' => $countSql,
);
foreach ($statsQueries as $label => $sql) {
    $r = $mysqli->query($sql);
    if (!$r) {
        echo "{$label}: ERROR {$mysqli->error}\n";
        continue;
    }
    $row = $r->fetch_row();
    echo "{$label}: {$row[0]}\n";
    $r->free();
}

echo "\n=== TIMING COMPARISON (3 runs each) ===\n";
foreach ($variants as $name => $sql) {
    $times = array();
    for ($i = 0; $i < 3; $i++) {
        $t = timedQuery($mysqli, $sql);
        if ($t['error']) {
            echo "{$name}: ERROR {$t['error']}\n";
            continue 2;
        }
        $times[] = $t['ms'];
    }
    sort($times);
    $median = $times[1];
    echo sprintf("%-28s median=%8.2f ms (min=%.2f max=%.2f)\n", $name, $median, min($times), max($times));
}

$countTimes = array();
for ($i = 0; $i < 3; $i++) {
    $countTimes[] = timedQuery($mysqli, $countSql)['ms'];
}
sort($countTimes);
echo sprintf("%-28s median=%8.2f ms\n", 'count_query', $countTimes[1]);

echo "\n=== ISOLATED DERIVED TABLE BUILDS ===\n";
$derivedQueries = array(
    'sub_task_counts_derived' => "SELECT COUNT(*) FROM (SELECT parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} WHERE deleted=0 GROUP BY parent_task_id) x",
    'notification_derived' => "SELECT COUNT(*) FROM (SELECT task_id FROM {$notifications} WHERE deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('{$userId}', read_by) AND user_id != {$userId} GROUP BY task_id) x",
);
foreach ($derivedQueries as $label => $sql) {
    $times = array();
    for ($i = 0; $i < 3; $i++) {
        $times[] = timedQuery($mysqli, $sql)['ms'];
    }
    sort($times);
    echo sprintf("%-28s median=%8.2f ms\n", $label, $times[1]);
}

// EXPLAIN on key variants (SELECT only)
runExplain($mysqli, $variants['A_current_full'], 'A current full data query');
runExplain($mysqli, $variants['E_core_only_no_derived'], 'E core filter only (no derived joins)');
runExplain($mysqli, $variants['C_no_notification_join'], 'C without notification derived join');
runExplain($mysqli, $variants['D_no_sub_task_counts'], 'D without sub_task_counts join');
runExplain($mysqli, $variants['G_no_both_derived'], 'G no derived joins, display joins only');
runExplain($mysqli, $countSql, 'count query');

$notifDerivedSql = "SELECT task_id FROM {$notifications} WHERE deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('{$userId}', read_by) AND user_id != {$userId} GROUP BY task_id";
runExplain($mysqli, $notifDerivedSql, 'notification derived table (full build)');

$subTaskDerivedSql = "SELECT parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} WHERE deleted=0 GROUP BY parent_task_id";
runExplain($mysqli, $subTaskDerivedSql, 'sub_task_counts derived table (full build)');

$mysqli->close();
echo "\nDone.\n";
