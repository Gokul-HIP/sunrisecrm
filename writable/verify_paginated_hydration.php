<?php
/**
 * Verify paginated hydration matches legacy derived-JOIN results (read-only).
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

$host = explode(':', $hostMatch[1] ?? 'localhost')[0];
$port = 3306;
if (strpos($hostMatch[1] ?? '', ':') !== false) {
    $port = (int) explode(':', $hostMatch[1], 2)[1];
}
$mysqli = new mysqli($host, $userMatch[1] ?? 'root', $passMatch[1] ?? '', $dbMatch[1] ?? 'sunrisecrm', $port);

$tasks = ($prefixMatch[1] ?? 'rise_') . 'tasks';
$users = ($prefixMatch[1] ?? 'rise_') . 'users';
$projects = ($prefixMatch[1] ?? 'rise_') . 'projects';
$milestones = ($prefixMatch[1] ?? 'rise_') . 'milestones';
$projectMembers = ($prefixMatch[1] ?? 'rise_') . 'project_members';
$taskStatus = ($prefixMatch[1] ?? 'rise_') . 'task_status';
$taskPriority = ($prefixMatch[1] ?? 'rise_') . 'task_priority';
$tickets = ($prefixMatch[1] ?? 'rise_') . 'tickets';
$notifications = ($prefixMatch[1] ?? 'rise_') . 'notifications';
$labels = ($prefixMatch[1] ?? 'rise_') . 'labels';

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
WHERE {$tasks}.deleted = 0 AND FIND_IN_SET({$projects}.status, 'open') AND {$projectMembers}.user_id = {$userId}";
$labelsSelect = "(SELECT GROUP_CONCAT({$labels}.id, '--::--', {$labels}.title, '--::--', {$labels}.color, ':--::--:') FROM {$labels} WHERE FIND_IN_SET({$labels}.id, {$tasks}.labels)) AS labels_list";

$legacySql = "SELECT {$tasks}.id, IFNULL(sub_task_counts.sub_task_count, 0) AS sub_task_count, notification_table.task_id AS unread, {$labelsSelect}
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

$newMainSql = "SELECT {$tasks}.id, 0 AS sub_task_count, NULL AS unread, {$labelsSelect} {$coreFromJoinsWhere} ORDER BY {$tasks}.id DESC LIMIT 10";

function fetchRows(mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (!$result) {
        throw new RuntimeException($db->error);
    }
    $rows = array();
    while ($row = $result->fetch_assoc()) {
        $rows[(int) $row['id']] = $row;
    }
    return $rows;
}

$legacy = fetchRows($mysqli, $legacySql);
$new = fetchRows($mysqli, $newMainSql);

$ids = array_keys($new);
$idsList = implode(',', $ids);

$counts = array();
if ($idsList) {
    $r = $mysqli->query("SELECT parent_task_id, COUNT(*) AS sub_task_count FROM {$tasks} WHERE deleted=0 AND parent_task_id IN ({$idsList}) GROUP BY parent_task_id");
    while ($row = $r->fetch_assoc()) {
        $counts[(int) $row['parent_task_id']] = (int) $row['sub_task_count'];
    }
}

$unread = array();
if ($idsList) {
    $r = $mysqli->query("SELECT DISTINCT task_id FROM {$notifications} WHERE task_id IN ({$idsList}) AND deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('{$userId}', read_by) AND user_id != {$userId}");
    while ($row = $r->fetch_assoc()) {
        $unread[(int) $row['task_id']] = $row['task_id'];
    }
}

foreach ($new as $id => &$row) {
    $row['sub_task_count'] = $counts[$id] ?? 0;
    $row['unread'] = $unread[$id] ?? null;
}
unset($row);

$mismatches = 0;
foreach ($legacy as $id => $leg) {
    $hydrated = $new[$id] ?? null;
    if (!$hydrated) {
        echo "MISSING task id {$id} in new result\n";
        $mismatches++;
        continue;
    }
    $legCount = (int) $leg['sub_task_count'];
    $newCount = (int) $hydrated['sub_task_count'];
    $legUnread = $leg['unread'] !== null ? (int) $leg['unread'] : null;
    $newUnread = $hydrated['unread'] !== null ? (int) $hydrated['unread'] : null;
    if ($legCount !== $newCount || $legUnread !== $newUnread || ($leg['labels_list'] ?? '') !== ($hydrated['labels_list'] ?? '')) {
        echo "MISMATCH task {$id}: legacy count={$legCount} new={$newCount}; legacy unread=" . var_export($legUnread, true) . " new=" . var_export($newUnread, true) . "\n";
        $mismatches++;
    }
}

$countRow = $mysqli->query("SELECT COUNT(DISTINCT {$tasks}.id) AS total_rows {$coreFromJoinsWhere}")->fetch_assoc();

echo "user_id={$userId}\n";
echo "legacy_rows=" . count($legacy) . " new_rows=" . count($new) . "\n";
echo "recordsTotal=" . $countRow['total_rows'] . "\n";
echo "mismatches={$mismatches}\n";
echo $mismatches === 0 ? "PASS: sub_task_count, unread, labels_list match legacy derived JOIN path\n" : "FAIL\n";
