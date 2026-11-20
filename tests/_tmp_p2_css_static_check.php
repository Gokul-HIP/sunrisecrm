<?php
/**
 * Static CSS/JS/view checks for Phase 2 UI alignment requirements.
 */
$root = dirname(__DIR__);
$css = file_get_contents($root . '/assets/css/content-calendar.css');
$js = file_get_contents($root . '/assets/js/content-calendar.js');
$index = file_get_contents($root . '/app/Views/content_calendar/index.php');
$form = file_get_contents($root . '/app/Views/content_calendar/item_form.php');
$pass = 0; $fail = 0;
function check($n, $c, $d = '') {
    global $pass, $fail;
    if ($c) { echo "[PASS] $n\n"; $pass++; } else { echo "[FAIL] $n" . ($d ? " - $d" : "") . "\n"; $fail++; }
}

check('shared .cc-wrap wrapper defined', strpos($css, '.cc-wrap') !== false);
check('control height 44px variable', strpos($css, '--cc-control: 44px') !== false || strpos($css, '44px') !== false);
check('month pill height 76px', strpos($css, '76px') !== false);
check('Add month dashed color #78716c', stripos($css, '#78716c') !== false);
check('form grid auto-fit minmax(240px', strpos($css, 'minmax(240px') !== false || strpos($css, 'minmax(240px, 1fr)') !== false);
check('index uses .cc-page > .cc-wrap', strpos($index, 'cc-page') !== false && strpos($index, 'cc-wrap') !== false);
check('item_form uses .cc-page > .cc-wrap', strpos($form, 'cc-page') !== false && strpos($form, 'cc-wrap') !== false);
check('add_client view gone', !is_file($root . '/app/Views/content_calendar/add_client.php'));
check('JS has addClientModal', strpos($js, 'addClientModal') !== false);
check('JS has openImportWizard', strpos($js, 'openImportWizard') !== false);
check('JS post grammar helper for posts', preg_match('/\bpost\b|\bposts\b|plural/', $js) && (strpos($js, 'post') !== false));
check('preview caption text in lang or JS', strpos($js, 'Type, status and title drive') !== false || strpos(file_get_contents($root . '/app/Language/english/custom_lang.php'), 'Type, status and title drive') !== false);
check('All types / All goals / All status strings', (strpos($js, 'All types') !== false || strpos(file_get_contents($root . '/app/Language/english/custom_lang.php'), 'All types') !== false));
check('Import button id in index', strpos($index, 'cc-import-open') !== false);
check('Add client id in index', strpos($index, 'cc-add-client') !== false);
check('focus trap / scroll lock in openModal', strpos($js, 'focus') !== false && (strpos($js, 'overflow') !== false || strpos($js, 'scroll') !== false));

echo "\nSTATIC UI RESULT PASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
