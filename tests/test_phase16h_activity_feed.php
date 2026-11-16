<?php
/**
 * Phase 16H: activity feed hides submitted_for_review_at; Submitted at uses app TZ.
 * Usage: php tests/test_phase16h_activity_feed.php [--db=sunrisecrmnew]
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$db_name = 'sunrisecrmnew';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $db_name = substr($arg, 5);
    }
}

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
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

$pass = 0;
$fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] $name\n";
        $pass++;
    } else {
        echo "[FAIL] $name" . ($detail ? " — $detail" : "") . "\n";
        $fail++;
    }
}

echo "========================================================\n";
echo "Phase 16H: activity feed + Submitted at timezone\n";
echo "DB: $db_name  TZ: " . get_setting('timezone') . "\n";
echo "========================================================\n\n";

// Temporary PHP 8.5 TimeTrait patch for App_Controller/session in get_change_logs — always restored.
$tt = SYSTEMPATH . 'I18n/TimeTrait.php';
$tt_src = file_get_contents($tt);
$old_sig = 'public static function createFromTimestamp(int $timestamp, $timezone = null, ?string $locale = null)';
$new_sig = 'public static function createFromTimestamp(int|float $timestamp, $timezone = null, ?string $locale = null): static';
$tt_patched = false;
if (strpos($tt_src, $old_sig) !== false) {
    file_put_contents($tt, str_replace($old_sig, $new_sig, $tt_src));
    $tt_patched = true;
}
register_shutdown_function(function () use ($tt, $tt_src, $tt_patched) {
    if ($tt_patched) {
        file_put_contents($tt, $tt_src);
    }
});

// --- render filter: old row containing submitted_for_review_at ---
$old_changes = serialize(array(
    "status_id" => array("from" => "2", "to" => "10"),
    "submitted_for_review_at" => array("from" => null, "to" => "2026-11-16 05:54:35"),
    "status_changed_at" => array("from" => null, "to" => "2026-11-16 05:54:35"),
    "points_edited" => array("from" => "0", "to" => "1"),
));
$rendered = get_change_logs_array($old_changes, "task", "updated");
$joined = implode("\n", $rendered);
echo "--- OLD LOG RENDER (status + stamp + internals) ---\n";
echo strip_tags($joined) . "\n\n";

check("old_log_hides_submitted_for_review_at", stripos($joined, "submitted_for_review_at") === false);
check("old_log_hides_status_changed_at", stripos($joined, "status_changed_at") === false);
check("old_log_hides_points_edited", stripos($joined, "points_edited") === false && !preg_match('/points_edited/i', $joined));
check("old_log_keeps_status", stripos($joined, "Status") !== false || stripos($joined, "status") !== false);
check("old_log_no_char_diff_stamp", stripos($joined, "05:54") === false && stripos($joined, "05:5354") === false);

// --- new status-only change ---
$new_changes = serialize(array(
    "status_id" => array("from" => "1", "to" => "3"),
));
$rendered_new = get_change_logs_array($new_changes, "task", "updated");
$joined_new = implode("\n", $rendered_new);
echo "--- NEW STATUS-ONLY LOG RENDER ---\n";
echo strip_tags($joined_new) . "\n\n";
check("new_status_log_shows_status", count($rendered_new) === 1);
check("new_status_log_no_stamp", stripos($joined_new, "submitted_for_review_at") === false);

// --- recording path: Crud_model unsets internals (static check) ---
$crud = file_get_contents(APPPATH . "Models/Crud_model.php");
check("crud_unsets_submitted_for_review_at", strpos($crud, '"submitted_for_review_at"') !== false);
check("crud_unsets_status_changed_at", strpos($crud, '"status_changed_at"') !== false);

// --- unknown column no longer falls through to custom text ---
$unknown = get_change_logs("task", "submitted_for_review_at", array("from" => null, "to" => "2026-11-16 05:54:35"));
check("get_change_logs_returns_false_for_stamp", $unknown === false);

// --- end_time formatting (wall clock, labeled) ---
$end = get_change_logs("task", "end_time", array("from" => "09:30:00", "to" => "17:45:00"));
echo "--- END_TIME RENDER ---\n" . strip_tags((string)$end) . "\n\n";
check("end_time_labeled", is_string($end) && stripos($end, "End time") !== false || (is_string($end) && stripos($end, app_lang("end_time")) !== false));
check("end_time_not_char_diff", is_string($end) && stripos($end, "<del>") !== false);

// --- Submitted at timezone ---
$utc = "2026-11-16 05:54:35";
$local = convert_date_utc_to_local($utc, "Y-m-d H:i:s");
$relative = format_to_relative_time($utc);
echo "--- SUBMITTED AT TZ ---\n";
echo "UTC: $utc\nLocal convert: $local\nformat_to_relative_time: $relative\n\n";
check("timezone_is_asia_kolkata", get_setting("timezone") === "Asia/Kolkata" || stripos((string)get_setting("timezone"), "Kolkata") !== false || stripos((string)get_setting("timezone"), "Calcutta") !== false, "tz=" . get_setting("timezone"));
check("submitted_at_converts_to_local", strpos($local, "11:24") !== false || (int)substr($local, 11, 2) === 11, "local=$local");
check("relative_shows_local_hour", strpos($relative, "11:24") !== false || strpos($relative, "11:24") !== false || preg_match('/11:24/', $relative), "relative=$relative");

// Live DB sample if present
$row = $db->query("SELECT id, changes FROM rise_activity_logs WHERE log_type='task' AND deleted=0 AND changes LIKE '%submitted_for_review_at%' ORDER BY id DESC LIMIT 1")->getRow();
if ($row) {
    $live = get_change_logs_array($row->changes, "task", "updated");
    $live_joined = implode("\n", $live);
    echo "--- LIVE OLD ROW log#{$row->id} RENDER ---\n";
    echo (strip_tags($live_joined) ?: "(empty after filter — status-only or all hidden)") . "\n\n";
    check("live_old_row_hides_stamp", stripos($live_joined, "submitted_for_review_at") === false);
} else {
    echo "(no live stamp rows found)\n";
}

echo "========================================================\n";
echo "PASS=$pass FAIL=$fail\n";
echo "========================================================\n";
exit($fail ? 1 : 0);
