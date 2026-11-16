<?php
/**
 * Render Task info Activity section HTML for screenshot (1902).
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

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

$tt = SYSTEMPATH . 'I18n/TimeTrait.php';
$tt_src = file_get_contents($tt);
$old_sig = 'public static function createFromTimestamp(int $timestamp, $timezone = null, ?string $locale = null)';
$new_sig = 'public static function createFromTimestamp(int|float $timestamp, $timezone = null, ?string $locale = null): static';
$patched = false;
if (strpos($tt_src, $old_sig) !== false) {
    file_put_contents($tt, str_replace($old_sig, $new_sig, $tt_src));
    $patched = true;
}
register_shutdown_function(function () use ($tt, $tt_src, $patched) {
    if ($patched) {
        file_put_contents($tt, $tt_src);
    }
});

$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);
$riseConfig = config('Rise');
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

// Prefer a task whose log historically contained submitted_for_review_at
$row = $db->query("SELECT log_type_id FROM rise_activity_logs WHERE log_type='task' AND deleted=0 AND changes LIKE '%submitted_for_review_at%' ORDER BY id DESC LIMIT 1")->getRow();
$task_id = $row ? (int) $row->log_type_id : 25711;
$task = $db->query("SELECT id, title FROM rise_tasks WHERE id=$task_id")->getRow();

$logs = $db->query("SELECT * FROM rise_activity_logs WHERE log_type='task' AND log_type_id=$task_id AND deleted=0 ORDER BY id DESC LIMIT 12")->getResult();

$items = '';
foreach ($logs as $log) {
    $changes_array = get_change_logs_array($log->changes, $log->log_type, $log->action);
    if ($log->action === "updated" && !count($changes_array)) {
        continue;
    }
    $who = "User #" . (int) $log->created_by;
    $u = $db->query("SELECT first_name, last_name FROM rise_users WHERE id=" . (int)$log->created_by)->getRow();
    if ($u) {
        $who = trim($u->first_name . ' ' . $u->last_name);
    }
    $when = format_to_relative_time($log->created_at);
    $body = '';
    if ($log->action === 'created') {
        $body = '<div class="activity-log-text">Created the task.</div>';
    } else {
        $body = '<ul class="activity-logs-ul">' . implode('', $changes_array) . '</ul>';
    }
    // Guard: never show stamp in screenshot content
    if (stripos($body, 'submitted_for_review_at') !== false) {
        $body = '<div class="text-danger">ERROR: stamp still visible</div>';
    }
    $items .= '<div class="media mb15 p10 border-bottom">'
        . '<div class="media-body">'
        . '<div class="d-flex justify-content-between"><strong>' . htmlspecialchars($who) . '</strong>'
        . '<span class="text-off">' . htmlspecialchars($when) . '</span></div>'
        . $body
        . '</div></div>';
}

$css = '';
foreach (['assets/bootstrap/css/bootstrap.min.css', 'assets/css/app.all.css', 'assets/css/custom-style.css'] as $f) {
    if (is_file(FCPATH . $f)) {
        $css .= file_get_contents(FCPATH . $f) . "\n";
    }
}

$title = $task ? $task->title : ("Task #$task_id");
$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Task info Activity #' . (int)$task_id . '</title>'
    . '<style>' . $css . '</style>'
    . '<style>
body{background:#eef2f7;margin:0;padding:24px;font-family:system-ui,sans-serif}
.modal-shot{background:#fff;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-width:900px;margin:0 auto}
.modal-head{padding:16px 20px;border-bottom:1px solid #e5e7eb}
.modal-head h1{font-size:18px;margin:0}
.box-title{font-weight:700;padding:12px 20px 0;font-size:15px}
.activity{padding:8px 20px 20px}
.activity-logs-ul{margin:6px 0 0;padding-left:18px}
.activity-logs-ul del{color:#b91c1c;text-decoration:line-through;margin-right:4px}
.activity-logs-ul ins{color:#15803d;text-decoration:none;font-weight:600}
.banner{background:#0f766e;color:#fff;padding:10px 14px;border-radius:6px;margin-bottom:16px;font-weight:600;max-width:900px;margin-left:auto;margin-right:auto}
</style></head><body style="width:1902px;max-width:1902px;margin:0 auto;">'
    . '<div class="banner">Task info modal — Activity (submitted_for_review_at hidden)</div>'
    . '<div class="modal-shot"><div class="modal-head"><h1>Task info #' . (int)$task_id . ' — ' . htmlspecialchars($title) . '</h1></div>'
    . '<div class="box-title">Activity</div><div class="activity list-container project-activity-logs-container">'
    . ($items ?: '<div class="p10 text-off">No activity</div>')
    . '</div></div></body></html>';

$out = FCPATH . 'writable/phase16h_activity_1902.html';
file_put_contents($out, $html);
echo "wrote $out\n";
echo "task_id=$task_id\n";
echo "items_len=" . strlen($items) . "\n";
echo "has_stamp=" . (stripos($html, 'submitted_for_review_at') !== false ? 'YES' : 'NO') . "\n";
