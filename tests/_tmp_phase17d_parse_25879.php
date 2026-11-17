<?php
$dump = '/mnt/c/Users/gokul/Downloads/sunrisecrmnew (3).sql';
$fh = fopen($dump, 'r');
$taskLine = null;
while (($line = fgets($fh)) !== false) {
    if (strpos($line, "(25879, 'testing timer") !== false || preg_match('/^\(25879,/', trim($line))) {
        if (strpos($line, 'testing timer') !== false) {
            $taskLine = trim($line);
            break;
        }
    }
}
fclose($fh);

echo "TASK_LINE=$taskLine\n\n";

// Column order from CREATE
$cols = [
    'id','title','description','project_id','milestone_id','assigned_to','deadline','end_time','labels','points',
    'status','status_id','priority_id','start_date','collaborators','sort','recurring','repeat_every','repeat_type',
    'no_of_cycles','recurring_task_id','no_of_cycles_completed','created_date','created_by','blocking','blocked_by',
    'parent_task_id','next_recurring_date','reminder_date','ticket_id','status_changed_at','submitted_for_review_at',
    'deleted','points_edited','review_changes_count','first_review_submitted_at','timer_lock_json','points_rules_locked_at'
];

// crude parse of values
$s = rtrim($taskLine, ",;");
$s = trim($s, "()");
// split respecting quotes
$vals = [];
$cur = '';
$in = false;
for ($i=0;$i<strlen($s);$i++) {
    $ch = $s[$i];
    if ($ch === "'" && ($i===0 || $s[$i-1] !== '\\')) { $in = !$in; $cur .= $ch; continue; }
    if ($ch === ',' && !$in) { $vals[] = trim($cur); $cur=''; continue; }
    $cur .= $ch;
}
if ($cur !== '') $vals[] = trim($cur);

echo "parsed_count=" . count($vals) . " expected=" . count($cols) . "\n";
foreach ($cols as $i => $c) {
    $v = $vals[$i] ?? 'MISSING';
    if (in_array($c, ['id','title','points','status_id','assigned_to','deadline','end_time','created_date','submitted_for_review_at','status_changed_at','review_changes_count','first_review_submitted_at','timer_lock_json','points_rules_locked_at'], true)) {
        echo "$c = $v\n";
    }
}

$from = '2026-11-19';
$created = trim($vals[22] ?? '', "'");
echo "\nfrom_date=$from created_date=$created created<from=" . var_export($created < $from, true) . "\n";
echo "RULES_APPLICABLE=" . (($created >= $from) ? 'YES' : 'NO — created before from') . "\n";

// timers for task_id 25879
$fh = fopen($dump, 'r');
$inPt = false;
$timers = [];
while (($line = fgets($fh)) !== false) {
    if (strpos($line, 'INSERT INTO `rise_project_time`') !== false) $inPt = true;
    if ($inPt) {
        // match ,25879, as task_id (9th value) — look for pattern ending near task_id
        if (preg_match('/\((\d+),\s*(\d+),\s*(\d+),\s*\'([^\']+)\',\s*\'([^\']*)\',\s*[^,]*,\s*\'([^\']+)\',\s*\'[^\']*\',\s*25879,\s*(\d+)\)/', $line, $m)) {
            $timers[] = $m;
        } elseif (preg_match('/,\s*25879,\s*[01]\)/', $line) && preg_match('/^\((\d+),/', trim($line))) {
            // looser: lines containing ,25879,0) or ,25879,1)
            if (substr_count($line, '25879') === 1 || preg_match('/,\s*25879,\s*[01]\)\s*,?$/', trim($line))) {
                $timers[] = [trim($line)];
            }
        }
        if (strpos($line, 'CREATE TABLE') !== false && strpos($line, 'project_time') === false) $inPt = false;
    }
}
fclose($fh);
echo "\ntimer_row_hits=" . count($timers) . "\n";
foreach (array_slice($timers, 0, 10) as $t) {
    echo (is_array($t) ? json_encode($t) : $t) . "\n";
}

// also check 25876 25877
foreach ([25876,25877,25879] as $tid) {
    $cmd = 'grep -c "((' . $tid . ', \'testing\\|((' . $tid . ', " ' . escapeshellarg($dump) . ' 2>/dev/null';
}
echo "\n=== other ids ===\n";
passthru('grep -n "(25876,\|(25877,\|(25879," ' . escapeshellarg($dump) . ' | grep -E "rise_tasks|testing" | head -20');
