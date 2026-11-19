<?php
/**
 * Source + static contract audit for Approve modal Dropzone re-init.
 * Browser scenarios are verified separately against localhost:8080.
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}

$view = file_get_contents(dirname(__DIR__) . '/app/Views/projects/tasks/tasks_in_review.php');
$helper = file_get_contents(dirname(__DIR__) . '/assets/js/general_helper.js');

echo "=== Approve Dropzone re-init audit ===\n";

check('no Dropzone init on document.ready alone',
    strpos($view, 'attachDropzoneWithForm("#approve-task-dropzone"') !== false
    && preg_match('/shown\.bs\.modal[\s\S]*initApproveDropzone|initApproveDropzone[\s\S]*shown\.bs\.modal/', $view)
);

check('init on shown.bs.modal', strpos($view, 'shown.bs.modal') !== false && strpos($view, 'initApproveDropzone') !== false);
check('destroy on hidden.bs.modal', strpos($view, 'hidden.bs.modal') !== false && strpos($view, 'destroyApproveDropzone') !== false);
check('destroy calls Dropzone.destroy', strpos($view, 'dz.destroy') !== false || strpos($view, 'approveDropzone.destroy') !== false);
check('teardown on hide.bs.modal (early)', strpos($view, 'hide.bs.modal') !== false);
check('init retries if attach throws', strpos($view, 'Retry once after a hard markup restore') !== false);
check('destroy removes file_names/file_sizes', strpos($view, "file_names[]") !== false && strpos($view, 'destroyApproveDropzone') !== false);
check('destroy restores preview markup', strpos($view, 'approveDropzonePreviewHtml') !== false);
check('upload button re-enabled in reset', strpos($view, 'upload-file-button").prop("disabled", false') !== false || strpos($view, ".upload-file-button\").prop(\"disabled\", false") !== false || preg_match('/upload-file-button[^\n]*disabled[^\n]*false/', $view));
check('still uses Messages upload endpoints', strpos($view, 'messages/upload_file') !== false && strpos($view, 'messages/validate_message_file') !== false);
check('attachDropzoneWithForm still removes template once per attach', strpos($helper, 'previewNode.parentNode.removeChild(previewNode)') !== false);

// Ensure we no longer keep a single page-load instance without shown handler
$readyBlock = '';
if (preg_match('/\$\(document\)\.ready\(function\s*\(\)\s*\{([\s\S]*)\}\);\s*<\/script>/', $view, $m)) {
    $readyBlock = $m[1];
}
$initOnlyOnReady = preg_match('/attachDropzoneWithForm\([^\)]*approve-task-dropzone[^\)]*\)\s*;/', $readyBlock)
    && strpos($readyBlock, 'shown.bs.modal') === false;
check('not only one-shot init without shown handler', !$initOnlyOnReady);

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
