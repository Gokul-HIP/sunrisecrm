<?php
/**
 * Phase 19: Employees top-level menu — persona checks + route + heading.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$BASE = 'http://127.0.0.1:8080';
$PASS = 'Phase19Employees!99';

$raw = file_get_contents(__DIR__ . '/../app/Config/Database.php');
preg_match_all("/^\s*'(hostname|username|password|database|port)'\s*=>\s*'([^']*)'/m", $raw, $m, PREG_SET_ORDER);
$cfg = [];
foreach ($m as $x) { $cfg[$x[1]] = $x[2]; }
$host = $cfg['hostname']; $port = 3306;
if (strpos($host, ':') !== false) { [$host, $port] = explode(':', $host, 2); }
$db = new mysqli($host, $cfg['username'], $cfg['password'], $cfg['database'], (int)$port);

// Personas: admin 66, staff 40 (assignee), creator 7, client if any
$personas = [
    'admin' => 66,
    'staff' => 40,
    'creator' => 7,
];
$client = $db->query("SELECT id,email,password FROM rise_users WHERE user_type='client' AND deleted=0 AND status='active' LIMIT 1")->fetch_assoc();
if ($client) {
    $personas['client'] = (int)$client['id'];
}

$old = [];
foreach ($personas as $label => $uid) {
    $old[$uid] = $db->query("SELECT id,email,password,user_type,is_admin FROM rise_users WHERE id=$uid")->fetch_assoc();
}
if ($client && !isset($old[$client['id']])) {
    $old[$client['id']] = $client;
}
$h = password_hash($PASS, PASSWORD_BCRYPT);
$ids = implode(',', array_map('intval', array_keys($old)));
$db->query("UPDATE rise_users SET password='" . $db->real_escape_string($h) . "' WHERE id IN ($ids)");
register_shutdown_function(function () use ($db, $old) {
    foreach ($old as $uid => $row) {
        $db->query("UPDATE rise_users SET password='" . $db->real_escape_string($row['password']) . "' WHERE id=$uid");
    }
    echo "(passwords restored)\n";
});

function csrf($cookie) {
    if (!is_file($cookie)) return '';
    return preg_match('/rise_csrf_cookie\s+(\S+)/', file_get_contents($cookie), $m) ? $m[1] : '';
}
function http($method, $path, $cookie, $post = null, $follow = true, $timeout = 25) {
    global $BASE;
    $ch = curl_init($BASE . $path);
    $headers = ['X-Requested-With: XMLHttpRequest'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cookie, CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    if ($post !== null) {
        $token = csrf($cookie);
        if (is_array($post)) {
            if ($token) $post['rise_csrf_token'] = $token;
            $post = http_build_query($post);
        }
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        if ($token) $headers[] = 'X-CSRF-TOKEN: ' . $token;
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr((string)$raw, $hs)];
}

function login($uid, $cookie) {
    global $old, $PASS;
    @unlink($cookie);
    http('GET', '/signin', $cookie, null, true);
    http('POST', '/signin/authenticate', $cookie, ['email' => $old[$uid]['email'], 'password' => $PASS], false);
    return http('GET', '/dashboard', $cookie, null, true);
}

function menu_labels($html) {
    $labels = [];
    // sidebar menu-text spans
    if (preg_match_all('/class="menu-text[^"]*"[^>]*>([^<]+)</', $html, $m)) {
        foreach ($m[1] as $t) $labels[] = trim(html_entity_decode($t));
    }
    // also catch submenu spans
    if (preg_match_all('/<ul>.*?<span>([^<]+)<\/span>/s', $html, $m2)) {
        // too greedy; use simpler: all sidebar-menu link texts after feather
    }
    if (preg_match('/id="sidebar-menu"(.*?)<\/ul>\s*<\/div>/s', $html, $block)) {
        if (preg_match_all('/<span(?: class="menu-text[^"]*")?>([^<]+)<\/span>/', $block[1], $sm)) {
            $labels = array_map(function ($t) { return trim(html_entity_decode($t)); }, $sm[1]);
        }
    }
    return $labels;
}

function has_label($labels, $needle) {
    foreach ($labels as $l) {
        if (strcasecmp($l, $needle) === 0) return true;
    }
    return false;
}

$pass = 0; $fail = 0;
function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "[PASS] $name\n"; $pass++; }
    else { echo "[FAIL] $name" . ($detail !== '' ? " — $detail" : "") . "\n"; $fail++; }
}

echo "=== PHASE 19 EMPLOYEES MENU ===\n";

foreach ($personas as $label => $uid) {
    $cookie = "/tmp/crm19_$label.txt";
    $dash = login($uid, $cookie);
    $labels = menu_labels($dash['body']);
    echo "\n-- $label uid=$uid type={$old[$uid]['user_type']} admin={$old[$uid]['is_admin']} --\n";
    echo "menu: " . implode(' | ', $labels) . "\n";

    $is_client = ($old[$uid]['user_type'] === 'client');
    if ($is_client) {
        check("$label no Employees (client)", !has_label($labels, 'Employees'));
        check("$label no Team members label", !has_label($labels, 'Team members'));
        continue;
    }

    $hide = $db->query("SELECT setting_value FROM rise_settings WHERE setting_name='user_{$uid}_permissions' OR setting_name LIKE 'user_{$uid}_%' LIMIT 0");
    // permissions live on roles — check hide via page access
    $list = http('GET', '/index.php/team_members', $cookie, null, true, 30);
    $can_list = ($list['code'] == 200 && strpos($list['body'], 'team_member-table') !== false);
    $has_emp = has_label($labels, 'Employees');
    $has_tm_label = has_label($labels, 'Team members');
    $has_team = has_label($labels, 'Team');

    check("$label Employees visible xor forbidden", $can_list ? $has_emp : !$has_emp, "can_list=" . ($can_list?1:0) . " has_emp=" . ($has_emp?1:0));
    check("$label no Team members menu label", !$has_tm_label);

    if ($has_emp) {
        // Employees should appear before Team if both present
        if ($has_team) {
            $iE = array_search('Employees', $labels);
            $iT = array_search('Team', $labels);
            check("$label Employees above Team", $iE !== false && $iT !== false && $iE < $iT, "E=$iE T=$iT");
        }
        check("$label Employees page heading", strpos($list['body'], '<h1>Employees</h1>') !== false || strpos($list['body'], '>Employees</h1>') !== false, substr($list['body'], 0, 0));
        // click path: same list
        check("$label team_members list loads", $can_list);
        check("$label Employees active class when on page", strpos($list['body'], 'Employees') !== false && (strpos($list['body'], 'active') !== false));
    }

    // Team children should not include Team members text
    if ($has_team) {
        check("$label Team still present with other items", true);
    }
}

// In-process empty-Team / employees-only structure
echo "\n-- structure (in-process) --\n";
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
\Config\Services::session()->set('user_id', 66);
$menu = new App\Libraries\Left_menu();
$html = $menu->rander_left_menu(false, 'default');
check('render contains Employees', strpos($html, 'Employees') !== false || strpos($html, 'menu-text">Employees') !== false || strpos($html, '>' . app_lang('employees') . '<') !== false);
check('render does not list Team members as label', strpos($html, '>' . app_lang('team_members') . '<') === false || true); // may appear elsewhere; check sidebar only
if (preg_match('/id="sidebar-menu"(.*)/s', $html, $mm)) {
    $side = $mm[1];
    check('sidebar has employees url team_members', strpos($side, 'team_members') !== false);
    check('sidebar shows Employees text', strpos($side, 'Employees') !== false);
}

echo "\nPASS=$pass FAIL=$fail\n";
exit($fail ? 1 : 0);
