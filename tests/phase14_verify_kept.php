<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=sunrisecrm_scratch', 'root', '');

// Check the 5 kept tasks
$ids = [25352, 25353, 25354, 25355, 25356];
$placeholders = implode(',', $ids);
$stmt = $pdo->query("SELECT id, title, start_date, deadline, end_time, status_id, assigned_to, created_by, recurring, repeat_every, repeat_type, no_of_cycles, no_of_cycles_completed, next_recurring_date FROM rise_tasks WHERE id IN ($placeholders)");
$kept = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Kept tasks found: " . count($kept) . "\n";
foreach ($kept as $t) {
    echo "ID: {$t['id']} | title: {$t['title']} | start: {$t['start_date']} | deadline: {$t['deadline']} | end_time: {$t['end_time']} | status_id: {$t['status_id']} | assigned_to: {$t['assigned_to']} | created_by: {$t['created_by']} | recurring: {$t['recurring']} | every: {$t['repeat_every']} {$t['repeat_type']} | cycles: {$t['no_of_cycles_completed']}/{$t['no_of_cycles']} | next: {$t['next_recurring_date']}\n";
}

// Check total recurring=1 rows
$totalRecurring = $pdo->query("SELECT COUNT(*) FROM rise_tasks WHERE recurring = 1")->fetchColumn();
echo "Total rows with recurring = 1: $totalRecurring\n";

$deletedRecurring = $pdo->query("SELECT COUNT(*) FROM rise_tasks WHERE recurring = 1 AND deleted = 1")->fetchColumn();
echo "Deleted rows with recurring = 1: $deletedRecurring\n";

$activeRecurring = $pdo->query("SELECT COUNT(*) FROM rise_tasks WHERE recurring = 1 AND deleted = 0")->fetchColumn();
echo "Active (deleted=0) rows with recurring = 1: $activeRecurring\n";

// Check older duplicate batches mentioned by user
$batches = [
    '24764-24767, 24769' => [24764, 24765, 24766, 24767, 24769],
    '25222-25226' => [25222, 25223, 25224, 25225, 25226]
];

foreach ($batches as $name => $batchIds) {
    $bPlace = implode(',', $batchIds);
    $bRows = $pdo->query("SELECT id, title, recurring, next_recurring_date FROM rise_tasks WHERE id IN ($bPlace)")->fetchAll(PDO::FETCH_ASSOC);
    echo "Batch $name found: " . count($bRows) . " tasks:\n";
    foreach ($bRows as $r) {
        echo "  #{$r['id']} | recurring: {$r['recurring']} | next: {$r['next_recurring_date']} | title: {$r['title']}\n";
    }
}

// Verify clones
$clonesRecurring = $pdo->query("SELECT COUNT(*) FROM rise_tasks WHERE recurring_task_id > 0 AND recurring = 1")->fetchColumn();
echo "Clones with recurring = 1: $clonesRecurring\n";

$clonesNotRecurring = $pdo->query("SELECT COUNT(*) FROM rise_tasks WHERE recurring_task_id > 0 AND recurring = 0")->fetchColumn();
echo "Clones with recurring = 0: $clonesNotRecurring\n";
