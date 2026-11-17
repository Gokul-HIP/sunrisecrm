<?php
/**
 * Phase 19: empty Team parent + employees-only; hide_team_members_list.
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
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'widget', 'activity_logs', 'currency', 'task_points', 'notifications', 'task_timer_visibility']);
$app = \Config\Services::codeigniter();
$app->initialize();

function names_from_html($html) {
    if (!preg_match('/id="sidebar-menu"(.*)/s', $html, $m)) return [];
    preg_match_all('/<span(?: class="menu-text[^"]*")?>([^<]+)<\/span>/', $m[1], $sm);
    return array_map(function ($t) { return trim(html_entity_decode($t)); }, $sm[1] ?? []);
}

function set_setting($n, $v) {
    $rise = config('Rise');
    $rise->app_settings_array[$n] = $v;
}

\Config\Services::session()->set('user_id', 40);
$menu = new App\Libraries\Left_menu();

// Snapshot module flags
$mods = ['module_attendance', 'module_leave', 'module_timeline', 'module_announcement'];
$saved = [];
foreach ($mods as $m) $saved[$m] = get_setting($m);

echo "=== permission matrix ===\n";

// 1) Employees only: disable attendance/leave/timeline/announcement
foreach ($mods as $m) set_setting($m, '0');
$html = $menu->rander_left_menu(false, 'default');
$labels = names_from_html($html);
echo "employees_only: " . implode(' | ', array_filter($labels, function ($l) {
    return in_array($l, ['Employees', 'Team', 'Time cards', 'Leave', 'Timeline', 'Announcements'], true);
})) . "\n";
echo (in_array('Employees', $labels, true) && !in_array('Team', $labels, true) ? "PASS" : "FAIL") . " employees only, no Team\n";

// 2) Restore modules; hide employees via permission on login_user
foreach ($mods as $m) set_setting($m, $saved[$m]);
$ref = new ReflectionClass($menu);
$prop = $ref->getProperty('ci');
$prop->setAccessible(true);
$ci = $prop->getValue($menu);
$ci->login_user->permissions['hide_team_members_list'] = '1';
$html = $menu->rander_left_menu(false, 'default');
$labels = names_from_html($html);
$hasE = in_array('Employees', $labels, true);
$hasT = in_array('Team', $labels, true);
echo "hide_team_members_list: Employees=" . ($hasE?1:0) . " Team=" . ($hasT?1:0) . "\n";
echo (!$hasE && $hasT ? "PASS" : "FAIL") . " hide list => no Employees, Team remains\n";

// 3) Custom menu normalize still works
$ci->login_user->permissions['hide_team_members_list'] = '0';
$html = $menu->rander_left_menu(false, 'default');
$labels = names_from_html($html);
$ie = array_search('Employees', $labels, true);
$it = array_search('Team', $labels, true);
echo "custom/default render E=$ie T=$it\n";
echo ($ie !== false && $it !== false && $ie < $it ? "PASS" : "FAIL") . " Employees above Team with saved default_left_menu\n";

echo "DONE\n";
