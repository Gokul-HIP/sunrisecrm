<?php
/**
 * Static HTML shots: review queue + Tasks score cell with Held points (1902/390).
 */
ini_set('memory_limit', '256M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTP_HOST']='127.0.0.1'; $_SERVER['SERVER_NAME']='127.0.0.1';
$_SERVER['REQUEST_URI']='/'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['SCRIPT_NAME']='/index.php';
define('ENVIRONMENT','development');
define('FCPATH', dirname(__DIR__).DIRECTORY_SEPARATOR);
require __DIR__.'/../app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory,'\\/ ').DIRECTORY_SEPARATOR.'bootstrap.php';
require_once SYSTEMPATH.'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH."ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin','url','file','form','language','general','date_time','app_files','widget','activity_logs','currency','task_points','notifications']);

$config = config('Database');
$config->default['database'] = 'sunrisecrm_scratch';
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default', true);
$rise = config('Rise');
foreach ($db->query("SELECT setting_name, setting_value FROM rise_settings WHERE deleted=0")->getResult() as $s) {
    $rise->app_settings_array[$s->setting_name] = $s->setting_value;
}
$rise->app_settings_array['task_points_review_rules_enabled'] = '1';
$rise->app_settings_array['task_points_review_rules_from'] = '2026-11-01 00:00:00';
date_default_timezone_set($rise->app_settings_array['timezone'] ?? 'Asia/Kolkata');

// Find a held task (In review / Changes with rules fields)
$in_review = (int) get_in_review_task_status_id();
$changes = (int) get_changes_task_status_id();
$row = $db->query("SELECT * FROM rise_tasks WHERE deleted=0 AND status_id IN ($in_review,$changes) AND first_review_submitted_at IS NOT NULL ORDER BY id DESC LIMIT 1")->getRow();
if (!$row) {
    // fabricate display object
    $row = (object)[
        'id'=>99999,'title'=>'Demo held task','points'=>5,'status_id'=>$in_review,
        'assigned_to'=>40,'collaborators'=>'','created_date'=>'2026-11-10',
        'deadline'=>date('Y-m-d', strtotime('+3 days')),'end_time'=>'23:00:00',
        'review_changes_count'=>1,'first_review_submitted_at'=>get_current_utc_time(),
        'timer_lock_json'=>'{"40":1}','points_rules_locked_at'=>get_current_utc_time(),
        'submitted_for_review_at'=>get_current_utc_time(),
    ];
}
$held = get_task_held_points_display($row);
$badge = get_task_points_badge_html($row, ['compact'=>true,'show_reason'=>false]);
$queue_points = $held['is_held'] ? htmlspecialchars($held['label']) : format_task_point_value($row->points);

$html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Phase 17 Held points</title>
<style>
body{margin:0;padding:24px;background:#e8eef5;font-family:system-ui,sans-serif}
.banner{background:#0f766e;color:#fff;padding:10px 14px;border-radius:6px;font-weight:600;margin-bottom:16px}
.card{background:#fff;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.1);padding:16px 20px;margin-bottom:16px}
h1{font-size:18px;margin:0 0 12px}
table{width:100%;border-collapse:collapse}
th,td{padding:8px 10px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:14px}
th{background:#f8fafc}
.badge{display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #f59e0b;border-radius:4px;padding:2px 8px;font-weight:600}
.summary{display:flex;gap:24px;flex-wrap:wrap}
.summary div{min-width:100px}
.summary .label{font-size:12px;color:#64748b}
.summary .val{font-size:22px;font-weight:700}
</style></head><body>
<div class="banner">Phase 17 — Held points (rules on)</div>
<div class="card"><h1>Review queue — Points column</h1>
<table><thead><tr><th>ID</th><th>Title</th><th>Submitted at</th><th>Points</th></tr></thead>
<tbody><tr><td>#'.(int)$row->id.'</td><td>'.htmlspecialchars($row->title).'</td><td>'.htmlspecialchars(format_to_relative_time($row->first_review_submitted_at ?? get_current_utc_time())).'</td><td><span class="badge">'.$queue_points.'</span></td></tr></tbody></table>
</div>
<div class="card"><h1>Tasks page — score cell</h1>
<p>Task #'.(int)$row->id.': '.$badge.'</p>
</div>
<div class="card"><h1>Monthly summary — Held points</h1>
<div class="summary">
<div><div class="label">Earned score</div><div class="val">12</div></div>
<div><div class="label">Solo</div><div class="val">8 / 20</div></div>
<div><div class="label">Collab</div><div class="val">4 / 10</div></div>
<div><div class="label">Held points</div><div class="val" style="color:#b45309">'.htmlspecialchars($held['held_label'] ?: '4.5').'</div></div>
</div>
<p class="text-muted" style="color:#64748b;margin-top:8px">Held is never added to earned.</p>
</div>
</body></html>';

foreach ([1902, 390] as $w) {
    $out = str_replace('<body>', '<body style="width:'.$w.'px;max-width:'.$w.'px;margin:0 auto;">', $html);
    file_put_contents(FCPATH."writable/phase17_held_{$w}.html", $out);
    echo "wrote writable/phase17_held_{$w}.html held=".json_encode($held)."\n";
}
