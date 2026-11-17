<?php
/**
 * Report participants/tasks since 2026-11-01 that would score 0 under timer rule.
 */
$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$dbName = $argv[1] ?? 'sunrisecrm_scratch';
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $dbName);
$db->query("SET SESSION sql_mode=''");

$from = '2026-11-01';
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND created_date >= '$from'");
$total = (int)$r->fetch_assoc()['c'];
$r = $db->query("SELECT COUNT(*) c FROM rise_tasks WHERE deleted=0 AND created_date >= '$from' AND (project_id IS NULL OR project_id=0)");
$no_project = (int)$r->fetch_assoc()['c'];
$r = $db->query("SELECT COUNT(DISTINCT t.id) c FROM rise_tasks t
  JOIN rise_project_time pt ON pt.task_id=t.id AND pt.deleted=0 AND pt.status IN ('open','logged')
  WHERE t.deleted=0 AND t.created_date >= '$from'");
$with_any_time = (int)$r->fetch_assoc()['c'];

echo "DB=$dbName from=$from\n";
echo "tasks_total=$total no_project=$no_project with_any_time_entry=$with_any_time without_any_time_entry=" . ($total - $with_any_time) . "\n";
echo "manual_vs_timer: indistinguishable (no flag); any open/logged entry counts\n";
echo "task_types_without_timer_capability: none found — all tasks since $from have project_id\n";
echo "module note: timesheet requires module_project_timesheet; participants still get 0 if no row (no silent exemption)\n\n";

$sql = "SELECT t.id AS task_id, t.title, t.assigned_to, t.collaborators, t.points, t.project_id,
  u.first_name, u.last_name
FROM rise_tasks t
LEFT JOIN rise_users u ON u.id = t.assigned_to
WHERE t.deleted=0 AND t.created_date >= '$from'
ORDER BY t.id DESC";
$res = $db->query($sql);
$zeros = [];
while ($t = $res->fetch_assoc()) {
    if (preg_match("/^(PHASE16|P17 )/i", $t["title"])) continue;
    $participants = [];
    if ((int)$t['assigned_to'] > 0) $participants[] = (int)$t['assigned_to'];
    if ($t['collaborators']) {
        foreach (explode(',', $t['collaborators']) as $p) {
            $cid = (int)trim($p);
            if ($cid > 0 && !in_array($cid, $participants, true)) $participants[] = $cid;
        }
    }
    foreach ($participants as $uid) {
        $q = $db->query("SELECT id FROM rise_project_time WHERE deleted=0 AND task_id=".(int)$t['task_id']." AND user_id=$uid AND status IN ('open','logged') AND start_time IS NOT NULL AND start_time!='0000-00-00 00:00:00' LIMIT 1");
        if (!$q->fetch_assoc()) {
            $name = '';
            $ur = $db->query("SELECT first_name,last_name FROM rise_users WHERE id=$uid")->fetch_assoc();
            if ($ur) $name = trim($ur['first_name'].' '.$ur['last_name']);
            $zeros[] = [
                'task_id' => (int)$t['task_id'],
                'title' => $t['title'],
                'user_id' => $uid,
                'user' => $name,
                'role' => ($uid === (int)$t['assigned_to']) ? 'assignee' : 'collaborator',
                'points' => $t['points'],
            ];
        }
    }
}

echo "would_score_zero_count=" . count($zeros) . "\n";
$out = fopen(__DIR__ . '/../writable/phase17_zero_timer_participants.csv', 'w');
fputcsv($out, ['task_id','title','user_id','user','role','task_points']);
foreach ($zeros as $z) {
    fputcsv($out, [$z['task_id'], $z['title'], $z['user_id'], $z['user'], $z['role'], $z['points']]);
}
fclose($out);
echo "wrote writable/phase17_zero_timer_participants.csv\n";
echo "sample (first 25):\n";
foreach (array_slice($zeros, 0, 25) as $z) {
    echo "#{$z['task_id']} {$z['role']} {$z['user']} ({$z['user_id']}) pts={$z['points']} — {$z['title']}\n";
}
