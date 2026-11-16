<?php
/**
 * Build static HTML of assignee status dropdowns (modal + quick/kanban-style) with Done enabled.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED);

$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/index.php';

define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require __DIR__ . '/../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications']);

$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);
$riseConfig = config('Rise');
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
$riseConfig->app_settings_array['task_review_workflow_enabled'] = '1';
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

$changes_id = (int) get_changes_task_status_id();
$statuses = model('App\Models\Task_status_model')->get_details(array("exclude_status_ids" => (string) $changes_id))->getResult();

$task_status = array();
foreach ($statuses as $status) {
    if ((int) $status->id === $changes_id || $status->key_name === 'changes') {
        continue;
    }
    $task_status[$status->id] = $status->key_name ? app_lang($status->key_name) : $status->title;
}

$quick = array();
foreach ($statuses as $status) {
    if ((int) $status->id === $changes_id || $status->key_name === 'changes') {
        continue;
    }
    $quick[] = array('id' => $status->id, 'text' => $status->key_name ? app_lang($status->key_name) : $status->title);
}

$css = '';
foreach (['assets/bootstrap/css/bootstrap.min.css', 'assets/css/app.all.css', 'assets/css/custom-style.css'] as $f) {
    if (is_file(FCPATH . $f)) {
        $css .= file_get_contents(FCPATH . $f) . "\n";
    }
}

$modal_dd = form_dropdown('status_id', $task_status, 1, "class='form-control' id='modal-status'");
$kanban_list = '';
foreach ($quick as $opt) {
    $kanban_list .= '<li class="list-group-item' . ($opt['text'] === 'Done' ? ' list-group-item-success fw-bold' : '') . '">' . htmlspecialchars($opt['text']) . ' <small class="text-muted">#' . (int)$opt['id'] . '</small></li>';
}

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Assignee status dropdowns — Done enabled</title>'
    . '<style>' . $css . '</style>'
    . '<style>
body{background:#eef2f7;margin:0;padding:24px;font-family:system-ui,sans-serif}
.card-shot{background:#fff;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:20px;margin-bottom:20px}
h1{font-size:20px;margin:0 0 8px}
.banner{background:#198754;color:#fff;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-weight:600}
label{font-weight:600;display:block;margin-bottom:6px}
select.form-control{max-width:320px}
.list-group{max-width:320px}
.list-group-item-success{outline:2px solid #198754}
</style></head><body>'
    . '<div class="banner">Assignee status controls — Done is selectable (workflow on)</div>'
    . '<div class="card-shot"><h1>Edit task modal — status dropdown</h1>'
    . '<label for="modal-status">Status</label>' . $modal_dd
    . '<p class="mt-3 mb-0 text-muted">Options: ' . htmlspecialchars(implode(', ', array_values($task_status))) . '</p></div>'
    . '<div class="card-shot"><h1>Kanban / quick status menu</h1>'
    . '<ul class="list-group">' . $kanban_list . '</ul></div>'
    . '</body></html>';

foreach ([1902, 390] as $w) {
    $out = str_replace('<body>', '<body style="width:' . $w . 'px;max-width:' . $w . 'px;margin:0 auto;">', $html);
    $path = FCPATH . "writable/phase16g_done_dropdown_{$w}.html";
    file_put_contents($path, $out);
    echo "wrote $path\n";
}
echo "Done in modal options: " . (isset($task_status[3]) || in_array('Done', $task_status, true) ? 'YES' : 'check') . "\n";
echo "Options: " . implode(' | ', array_values($task_status)) . "\n";
