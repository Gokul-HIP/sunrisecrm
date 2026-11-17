<?php
/**
 * Phase 17C diagnose: tasks 25876 / 25877 — why timer rule didn't apply in queue.
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

$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', false);

$riseConfig = config('Rise');
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted = 0")->getResult() as $s) {
    $riseConfig->app_settings_array[$s->setting_name] = $s->setting_value;
}
date_default_timezone_set($riseConfig->app_settings_array['timezone'] ?? 'Asia/Kolkata');

echo "DB=" . $db->getDatabase() . " VERSION=" . $db->query("SELECT VERSION()")->getRow(0)->{'VERSION()'} . "\n";
echo "\n-- SETTINGS AS APP READS --\n";
echo "task_points_review_rules_enabled=" . var_export(get_setting('task_points_review_rules_enabled'), true) . "\n";
echo "task_points_review_rules_from=" . var_export(get_setting('task_points_review_rules_from'), true) . "\n";
echo "task_points_review_rules_enabled()=" . (task_points_review_rules_enabled() ? '1' : '0') . "\n";
echo "get_task_points_review_rules_from()=" . var_export(get_task_points_review_rules_from(), true) . "\n";
echo "is_valid_from=" . (is_valid_task_points_review_rules_from_date(get_task_points_review_rules_from()) ? '1' : '0') . "\n";
echo "in_review_id=" . get_in_review_task_status_id() . " changes_id=" . get_changes_task_status_id() . " done_id=" . get_done_task_status_id() . "\n";

foreach ([25876, 25877] as $tid) {
    echo "\n========== TASK $tid ==========\n";
    $row = $db->query("SELECT * FROM rise_tasks WHERE id=$tid")->getRow();
    if (!$row) {
        echo "NOT FOUND\n";
        continue;
    }
    echo "title={$row->title}\n";
    echo "created_date={$row->created_date}\n";
    echo "status_id={$row->status_id}\n";
    echo "points={$row->points}\n";
    echo "deadline={$row->deadline} end_time={$row->end_time}\n";
    echo "assigned_to={$row->assigned_to} collaborators={$row->collaborators}\n";
    echo "submitted_for_review_at={$row->submitted_for_review_at}\n";
    echo "first_review_submitted_at=" . var_export($row->first_review_submitted_at, true) . "\n";
    echo "review_changes_count={$row->review_changes_count}\n";
    echo "timer_lock_json=" . var_export($row->timer_lock_json, true) . "\n";
    echo "points_rules_locked_at=" . var_export($row->points_rules_locked_at, true) . "\n";

    $from = get_task_points_review_rules_from();
    $from_date = substr($from, 0, 10);
    $created = (string) $row->created_date;
    echo "\n-- created-on-or-after-from check --\n";
    echo "from_raw=" . var_export($from, true) . "\n";
    echo "from_date_used=" . var_export($from_date, true) . "\n";
    echo "created_column=created_date value=" . var_export($created, true) . "\n";
    echo "created < from_date ? " . var_export($created < $from_date, true) . " (false means passes date gate)\n";
    echo "created >= from_date ? " . var_export($created >= $from_date, true) . "\n";

    $legacy = function_exists('is_legacy_in_review_task') ? is_legacy_in_review_task($row) : null;
    echo "is_legacy_in_review_task=" . var_export($legacy, true) . "\n";
    $applicable = is_task_points_review_rules_applicable($row);
    echo "is_task_points_review_rules_applicable=" . ($applicable ? '1' : '0') . "\n";
    if (!$applicable) {
        echo "WHY_NOT_APPLICABLE:";
        if (!task_points_review_rules_enabled()) echo " flag_off";
        if ($from === '' || !is_valid_task_points_review_rules_from_date($from)) echo " invalid_or_empty_from";
        if ($created === '' || $created === '0000-00-00') echo " empty_created";
        if ($created < $from_date) echo " created_before_from";
        if ($legacy) echo " legacy_in_review";
        echo "\n";
    }

    echo "\n-- rise_project_time --\n";
    $times = $db->query("SELECT id,user_id,task_id,status,start_time,end_time,deleted FROM rise_project_time WHERE task_id=$tid ORDER BY id")->getResult();
    if (!$times) {
        echo "(none)\n";
    } else {
        foreach ($times as $t) {
            echo "id={$t->id} user={$t->user_id} status={$t->status} start={$t->start_time} end={$t->end_time} deleted={$t->deleted}\n";
        }
    }

    $assignee = (int) $row->assigned_to;
    echo "\n-- participant_timer_ok_for_task(assignee=$assignee) --\n";
    if (function_exists('participant_timer_ok_for_task')) {
        echo "timer_ok=" . (participant_timer_ok_for_task($row, $assignee) ? '1' : '0') . "\n";
        $map = get_task_timer_lock_map($row);
        echo "timer_lock_map=" . json_encode($map) . "\n";
    }

    echo "\n-- calculate_task_participant_award_preview --\n";
    $preview = calculate_task_participant_award_preview($row, '', true);
    echo "applicable=" . ($preview['applicable'] ? '1' : '0') . "\n";
    echo "held_total={$preview['held_total']} held_original={$preview['held_original_total']}\n";
    echo "participants=" . json_encode($preview['participants'], JSON_PRETTY_PRINT) . "\n";

    if (function_exists('get_task_projected_award_display')) {
        $proj = get_task_projected_award_display($row);
        echo "\n-- get_task_projected_award_display --\n";
        echo "queue_label_short={$proj['queue_label_short']}\n";
        echo "reason={$proj['reason']}\n";
        echo "rules_applicable=" . ($proj['rules_applicable'] ? '1' : '0') . "\n";
        echo "lateness_only=" . ($proj['lateness_only'] ? '1' : '0') . "\n";
        echo "award={$proj['award']} original={$proj['original']}\n";
    }

    // Which condition made timer rule not apply?
    echo "\n-- TIMER RULE DIAGNOSIS --\n";
    if (!$applicable) {
        echo "CONDITION: rules not applicable => timer check skipped in preview (lateness-only path)\n";
    } else if (empty($row->points_rules_locked_at) || $row->points_rules_locked_at === '0000-00-00 00:00:00') {
        echo "CONDITION: rules applicable but NOT LOCKED (points_rules_locked_at empty)\n";
        echo "  participant_timer_ok falls back to live DB check at as_of=";
        if (!empty($row->first_review_submitted_at) && $row->first_review_submitted_at !== '0000-00-00 00:00:00') {
            echo $row->first_review_submitted_at;
        } else if (!empty($row->submitted_for_review_at)) {
            echo $row->submitted_for_review_at . " (submitted fallback)";
        } else {
            echo "NOW";
        }
        echo "\n";
        $as_of = !empty($row->first_review_submitted_at) && $row->first_review_submitted_at !== '0000-00-00 00:00:00'
            ? $row->first_review_submitted_at
            : (!empty($row->submitted_for_review_at) ? $row->submitted_for_review_at : get_current_utc_time());
        $has = participant_has_task_timer_before($tid, $assignee, $as_of);
        echo "  participant_has_task_timer_before(as_of=$as_of)=" . ($has ? '1' : '0') . "\n";
        $part = $preview['participants'][$assignee] ?? null;
        if ($part) {
            echo "  preview timer_ok={$part['timer_ok']} earned={$part['earned_points']}\n";
            if ((int)$part['timer_ok'] === 1 && !$has) {
                echo "  ANOMALY: preview says timer_ok but live check false\n";
            }
            if ((int)$part['timer_ok'] === 1 && !$times) {
                echo "  ROOT: preview timer_ok=1 with ZERO project_time rows — bug in timer_ok path\n";
            }
        }
    } else {
        $map = get_task_timer_lock_map($row);
        $tok = isset($map[(string)$assignee]) ? (int)$map[(string)$assignee] : 'missing';
        echo "CONDITION: locked; timer_lock_json assignee entry=$tok\n";
        if ($tok === 1) {
            echo "  ROOT: lock recorded timer_ok=1 despite no timer rows (lock-time bug)\n";
        } else if ($tok === 0) {
            echo "  Lock says no timer; if UI shows 5 of 5, projection/display bug\n";
        }
    }
}
echo "\nDONE\n";
