<?php
/**
 * Ensure at least 2 tasks are in review for local Approve Dropzone repro. Restores nothing destructive beyond status.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$raw = file_get_contents(dirname(__DIR__) . '/app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $r) {
    $cfg[$r[1]] = $r[2];
}
$port = isset($cfg['port']) && $cfg['port'] !== '' ? (int) $cfg['port'] : 3306;
$db = new mysqli($cfg['hostname'], $cfg['username'], $cfg['password'], $cfg['database'], $port);
if ($db->connect_error) {
    fwrite(STDERR, "DB connect failed\n");
    exit(1);
}

$review = $db->query("SELECT id FROM rise_task_status WHERE key_name='to_review' AND deleted=0 LIMIT 1")->fetch_assoc();
if (!$review) {
    // fallback: title like
    $review = $db->query("SELECT id FROM rise_task_status WHERE deleted=0 AND (title LIKE '%review%' OR key_name LIKE '%review%') LIMIT 1")->fetch_assoc();
}
if (!$review) {
    fwrite(STDERR, "no review status\n");
    exit(1);
}
$reviewId = (int)$review['id'];

$existing = $db->query("SELECT id, title, assigned_to, created_by FROM rise_tasks WHERE deleted=0 AND status_id=$reviewId ORDER BY id DESC LIMIT 10");
$rows = [];
while ($r = $existing->fetch_assoc()) {
    $rows[] = $r;
}
echo "review_status_id=$reviewId count=" . count($rows) . "\n";
foreach ($rows as $r) {
    echo "task {$r['id']} assignee={$r['assigned_to']} creator={$r['created_by']} {$r['title']}\n";
}

if (count($rows) >= 2) {
    echo "ready\n";
    exit(0);
}

// Put two recent tasks into review (prefer different assignees if possible)
$need = 2 - count($rows);
$candidates = $db->query("SELECT id, title, assigned_to, created_by, status_id FROM rise_tasks WHERE deleted=0 AND status_id<>$reviewId AND assigned_to>0 ORDER BY id DESC LIMIT 20");
$moved = [];
while ($need > 0 && ($c = $candidates->fetch_assoc())) {
    // skip already in our list
    $db->query("UPDATE rise_tasks SET status_id=$reviewId, submitted_for_review_at=NOW() WHERE id=" . (int)$c['id']);
    $moved[] = (int)$c['id'];
    $need--;
    echo "moved_to_review {$c['id']}\n";
}
file_put_contents(sys_get_temp_dir() . '/approve_dz_moved_tasks.json', json_encode($moved));
echo "ready\n";
