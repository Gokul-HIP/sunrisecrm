<?php
/**
 * Production performance diagnosis (read-only).
 *
 * Usage:
 *   php writable/production_perf_diagnosis.php --user-id=7
 *   php writable/production_perf_diagnosis.php --user-id=66
 *
 * Run ON THE PRODUCTION SERVER against the production database.
 * SELECT / EXPLAIN / SHOW only — no writes except SHOW ENGINE INNODB STATUS (read-only).
 */

$userId = 7;
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

$db = new mysqli($host, $username, $password, $database, (int) $port);
if ($db->connect_error) {
    fwrite(STDERR, "Connect failed: {$db->connect_error}\n");
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

function section(string $title): void {
    echo "\n" . str_repeat('=', 72) . "\n";
    echo $title . "\n";
    echo str_repeat('=', 72) . "\n";
}

function runExplain(mysqli $db, string $sql, string $label, bool $tryAnalyze = true): void {
    echo "\n--- {$label} ---\n";

    if ($tryAnalyze) {
        try {
            $analyzeResult = $db->query("EXPLAIN ANALYZE " . $sql);
            if ($analyzeResult) {
                while ($row = $analyzeResult->fetch_assoc()) {
                    echo ($row['EXPLAIN'] ?? json_encode($row)) . "\n";
                }
                $analyzeResult->free();
                return;
            }
        } catch (mysqli_sql_exception $e) {
            echo "(EXPLAIN ANALYZE not supported, falling back)\n";
        }
    }

    try {
        $jsonResult = $db->query("EXPLAIN FORMAT=JSON " . $sql);
        if ($jsonResult) {
            $row = $jsonResult->fetch_assoc();
            $jsonResult->free();
            if ($row) {
                $key = array_key_first($row);
                $decoded = json_decode($row[$key], true);
                echo json_encode($decoded, JSON_PRETTY_PRINT) . "\n";
                return;
            }
        }
    } catch (mysqli_sql_exception $e) {
        echo "(EXPLAIN FORMAT=JSON failed: {$e->getMessage()})\n";
    }

    $result = $db->query("EXPLAIN " . $sql);
    if (!$result) {
        echo "EXPLAIN error: {$db->error}\n";
        return;
    }
    $first = true;
    while ($row = $result->fetch_assoc()) {
        if ($first) {
            echo implode("\t", array_keys($row)) . "\n";
            $first = false;
        }
        echo implode("\t", array_map('strval', $row)) . "\n";
    }
    $result->free();
}

function showIndex(mysqli $db, string $table): void {
    echo "\nSHOW INDEX FROM {$table}\n";
    $result = $db->query("SHOW INDEX FROM {$table}");
    if (!$result) {
        echo "Error: {$db->error}\n";
        return;
    }
    printf("%-22s %-30s %-5s %-18s %-12s\n", 'Key_name', 'Column_name', 'Seq', 'Cardinality', 'Index_type');
    while ($row = $result->fetch_assoc()) {
        printf(
            "%-22s %-30s %-5s %-18s %-12s\n",
            $row['Key_name'],
            $row['Column_name'],
            $row['Seq_in_index'],
            $row['Cardinality'] ?? '',
            $row['Index_type'] ?? ''
        );
    }
    $result->free();
}

function timedScalar(mysqli $db, string $sql): array {
    $start = microtime(true);
    $result = $db->query($sql);
    $ms = round((microtime(true) - $start) * 1000, 2);
    if (!$result) {
        return array('ms' => $ms, 'error' => $db->error);
    }
    $row = $result->fetch_assoc();
    $result->free();
    return array('ms' => $ms, 'value' => $row ? array_values($row)[0] : null);
}

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

$currentMainSql = "SELECT {$tasks}.id, {$tasks}.title, {$tasks}.collaborators, {$labelsSelect}
{$coreFromJoinsWhere}
ORDER BY {$tasks}.id DESC
LIMIT 10";

$countSql = "SELECT COUNT(DISTINCT {$tasks}.id) AS total_rows {$coreFromJoinsWhere}";

$subTaskCountsJoin = " LEFT JOIN (SELECT sub_tasks_table.parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} AS sub_tasks_table WHERE sub_tasks_table.deleted = 0 GROUP BY sub_tasks_table.parent_task_id) AS sub_task_counts ON sub_task_counts.parent_task_id = {$tasks}.id";
$notificationJoin = " LEFT JOIN (SELECT {$notifications}.task_id FROM {$notifications} WHERE {$notifications}.deleted = 0 AND {$notifications}.event = 'project_task_commented' AND !FIND_IN_SET('{$userId}', {$notifications}.read_by) AND {$notifications}.user_id != {$userId} GROUP BY {$notifications}.task_id) AS notification_table ON notification_table.task_id = {$tasks}.id";

$legacyMainSql = "SELECT {$tasks}.id, IFNULL(sub_task_counts.sub_task_count,0) AS sub_task_count, notification_table.task_id AS unread, {$labelsSelect}
FROM {$tasks}
LEFT JOIN {$users} ON {$users}.id = {$tasks}.assigned_to
LEFT JOIN {$projects} ON {$tasks}.project_id = {$projects}.id
LEFT JOIN {$milestones} ON {$tasks}.milestone_id = {$milestones}.id
LEFT JOIN {$taskStatus} ON {$tasks}.status_id = {$taskStatus}.id
LEFT JOIN {$taskPriority} ON {$tasks}.priority_id = {$taskPriority}.id
LEFT JOIN {$tickets} ON {$tasks}.ticket_id = {$tickets}.id
LEFT JOIN {$projectMembers} ON {$tasks}.project_id = {$projectMembers}.project_id AND {$projectMembers}.deleted = 0 AND {$projectMembers}.user_id = {$userId}
{$subTaskCountsJoin}{$notificationJoin}
WHERE {$tasks}.deleted = 0 AND FIND_IN_SET({$projects}.status, 'open') AND {$projectMembers}.user_id = {$userId}
ORDER BY {$tasks}.id DESC LIMIT 10";

$countNotificationsSql = "SELECT COUNT({$notifications}.id) AS total_notifications
FROM {$notifications}
LEFT JOIN {$tasks} ON {$tasks}.id = {$notifications}.task_id
WHERE {$notifications}.deleted = 0
AND FIND_IN_SET({$userId}, {$notifications}.notify_to) != 0
AND FIND_IN_SET({$userId}, {$notifications}.read_by) = 0
AND {$notifications}.created_at > '0'";

$version = $db->query('SELECT VERSION() AS v')->fetch_assoc()['v'];

section('ENVIRONMENT');
echo "MySQL/MariaDB version: {$version}\n";
echo "Database: {$database} @ {$host}:{$port}\n";
echo "user_id={$userId}\n";

section('TABLE ROW COUNTS');
foreach (array(
    'active_tasks' => "SELECT COUNT(*) FROM {$tasks} WHERE deleted=0",
    'project_members_user' => "SELECT COUNT(*) FROM {$projectMembers} WHERE deleted=0 AND user_id={$userId}",
    'notifications_all' => "SELECT COUNT(*) FROM {$notifications} WHERE deleted=0",
    'matching_tasks_count' => $countSql,
) as $label => $sql) {
    $r = timedScalar($db, $sql);
    if (!empty($r['error'])) {
        echo "{$label}: ERROR {$r['error']}\n";
    } else {
        echo sprintf("%-24s %s (query %s ms)\n", $label . ':', $r['value'], $r['ms']);
    }
}

section('SHOW INDEX');
foreach (array($tasks, $projectMembers, $projects, $notifications, $labels) as $table) {
    showIndex($db, $table);
}

section('EXPLAIN: CURRENT main paginated SELECT (post-hydration path)');
runExplain($db, $currentMainSql, 'current main');

section('EXPLAIN: LEGACY main SELECT (with derived JOINs — pre-hydration path)');
runExplain($db, $legacyMainSql, 'legacy main');

section('EXPLAIN: COUNT query');
runExplain($db, $countSql, 'count');

section('EXPLAIN: batch sub_task_count hydration (10 ids sample)');
$idsResult = $db->query("SELECT {$tasks}.id {$coreFromJoinsWhere} ORDER BY {$tasks}.id DESC LIMIT 10");
$ids = array();
while ($row = $idsResult->fetch_assoc()) {
    $ids[] = (int) $row['id'];
}
$idsResult->free();
$idsList = $ids ? implode(',', $ids) : '0';
runExplain($db, "SELECT parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} WHERE deleted=0 AND parent_task_id IN ({$idsList}) GROUP BY parent_task_id", 'batch subtask');

section('EXPLAIN: batch unread hydration (10 ids sample)');
runExplain($db, "SELECT DISTINCT task_id FROM {$notifications} WHERE task_id IN ({$idsList}) AND deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('{$userId}', read_by) AND user_id != {$userId}", 'batch unread');

section('EXPLAIN: LEGACY notification derived table (full build)');
runExplain($db, "SELECT task_id FROM {$notifications} WHERE deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('{$userId}', read_by) AND user_id != {$userId} GROUP BY task_id", 'legacy notification derived');

section('EXPLAIN: LEGACY sub_task_counts derived table (full build)');
runExplain($db, "SELECT parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} WHERE deleted=0 GROUP BY parent_task_id", 'legacy sub_task derived');

section('EXPLAIN: count_notifications SELECT (without UPDATE last_online)');
runExplain($db, $countNotificationsSql, 'count_notifications');

section('TIMING COMPARISON (5 runs each, SELECT only)');
$queries = array(
    'current_main' => $currentMainSql,
    'legacy_main' => $legacyMainSql,
    'count' => $countSql,
    'batch_subtask' => "SELECT parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} WHERE deleted=0 AND parent_task_id IN ({$idsList}) GROUP BY parent_task_id",
    'batch_unread' => "SELECT DISTINCT task_id FROM {$notifications} WHERE task_id IN ({$idsList}) AND deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('{$userId}', read_by) AND user_id != {$userId}",
    'legacy_notif_derived' => "SELECT task_id FROM {$notifications} WHERE deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('{$userId}', read_by) AND user_id != {$userId} GROUP BY task_id",
    'legacy_subtask_derived' => "SELECT parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} WHERE deleted=0 GROUP BY parent_task_id",
    'count_notifications' => $countNotificationsSql,
);
foreach ($queries as $label => $sql) {
    $times = array();
    for ($i = 0; $i < 5; $i++) {
        $times[] = timedScalar($db, $sql)['ms'];
    }
    sort($times);
    echo sprintf("%-26s median=%8.2f ms  min=%8.2f  max=%8.2f\n", $label . ':', $times[2], $times[0], $times[4]);
}

section('SHOW FULL PROCESSLIST (snapshot)');
$pl = $db->query('SHOW FULL PROCESSLIST');
if ($pl) {
    $active = 0;
    while ($row = $pl->fetch_assoc()) {
        if ($row['Command'] !== 'Sleep') {
            $active++;
            echo sprintf(
                "Id=%s User=%s Host=%s db=%s Time=%ss State=%s Info=%s\n",
                $row['Id'],
                $row['User'],
                $row['Host'],
                $row['db'],
                $row['Time'],
                $row['State'],
                substr($row['Info'] ?? '', 0, 200)
            );
        }
    }
    echo "Non-sleep connections shown: {$active}\n";
    $pl->free();
}

section('SHOW ENGINE INNODB STATUS (excerpt: TRANSACTIONS + ROW OPERATIONS)');
$innodb = $db->query('SHOW ENGINE INNODB STATUS');
if ($innodb) {
    $row = $innodb->fetch_assoc();
    $innodb->free();
    $status = $row['Status'] ?? '';
    if (preg_match('/TRANSACTIONS.*?----------\n(.*?)\n\n/s', $status, $m)) {
        echo "TRANSACTIONS:\n" . trim($m[1]) . "\n\n";
    }
    if (preg_match('/ROW OPERATIONS.*?----------\n(.*?)\n\n/s', $status, $m)) {
        echo "ROW OPERATIONS:\n" . trim($m[1]) . "\n";
    }
}

section('PLAN DRIVER ANALYSIS NOTES');
echo "- If CURRENT main EXPLAIN starts from rise_project_members or rise_tasks with Using temporary; Using filesort,\n";
echo "  the optimizer may be sorting a large candidate set before LIMIT 10.\n";
echo "- If LEGACY main EXPLAIN starts from rise_tasks PRIMARY index scan, derived JOINs may have forced a faster plan.\n";
echo "- Batch hydration queries should show type=range/ref on task_id/parent_task_id with <=10 rows examined.\n";
echo "- count_notifications uses FIND_IN_SET on notify_to and read_by — typically full/partial notification scan.\n";

$db->close();
echo "\nDone.\n";
