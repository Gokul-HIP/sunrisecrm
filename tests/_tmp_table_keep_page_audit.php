<?php
/**
 * Audit: Tasks list + In-review keep current page after status/approve reload.
 * Static source checks only (no live DB / browser).
 */
$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function check($label, $ok) {
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASS: $label\n";
    } else {
        $fail++;
        echo "FAIL: $label\n";
    }
}

$appJs = file_get_contents($root . '/assets/js/app.js');
$appAll = file_get_contents($root . '/assets/js/app.all.js');
$review = file_get_contents($root . '/app/Views/projects/tasks/tasks_in_review.php');
$upd = file_get_contents($root . '/app/Views/projects/tasks/update_task_script.php');

check('app.js defines keepPage default', strpos($appJs, 'keepPage: false') !== false);
check('app.js keepPage uses ajax.reload(..., false)', (bool) preg_match('/keepPage[\s\S]*ajax\.reload\([\s\S]*false\)/', $appJs));
check('app.js still resets page when keepPage false (_fnReDraw)', strpos($appJs, '_fnReDraw()') !== false);
check('app.all.js includes keepPage support', strpos($appAll, 'keepPage') !== false && strpos($appAll, 'ajax.reload') !== false);

$approveKeep = substr_count($review, 'appTable({reload: true, keepPage: true})');
check('In-review approve + request-changes use keepPage (2)', $approveKeep >= 2);
check('In-review has no bare reload:true without keepPage', !preg_match('/tasks-in-review-table"\)\.appTable\(\{reload:\s*true\}\)/', $review));

$statusKeep = substr_count($upd, 'appTable({reload: true, keepPage: true})');
check('Task status updater uses keepPage (2 handlers)', $statusKeep >= 2);
check('Task status updater has no bare reload:true', !preg_match('/task-table"\)\.appTable\(\{reload:\s*true\}\)/', $upd));

// Filter reloads should still reset to page 1 (no keepPage)
$filterJs = file_get_contents($root . '/assets/js/app.js');
check('Filter reloadInstance does not force keepPage', (bool) preg_match('/\$instance\.appTable\(\{reload:\s*true,\s*filterParams:/', $filterJs));

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
