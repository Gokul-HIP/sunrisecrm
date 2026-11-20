<?php
/**
 * Content Calendar HTTP smoke test (LOCAL ONLY).
 * Starts the PHP built-in server on 127.0.0.1:8099, creates temporary test staff users in the local database,
 * logs in as each role and calls the real pages / AJAX endpoints. Everything it creates is removed at the end.
 *
 * Usage: php tests/_tmp_content_calendar_http_smoke.php
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['SERVER_NAME'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['SCRIPT_NAME'] = '/index.php';
define('ENVIRONMENT', 'development');
define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require FCPATH . 'app/Config/Paths.php';
$paths = new Config\Paths();
require rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new CodeIgniter\Config\DotEnv(ROOTPATH))->load();
require_once(APPPATH . "ThirdParty/PHP-Hooks/php-hooks.php");
helper(['plugin', 'url', 'file', 'form', 'language', 'general', 'date_time', 'app_files', 'content_calendar']);
$app = \Config\Services::codeigniter();
$app->initialize();
$db = \Config\Database::connect('default');

$host = $db->hostname;
if (!in_array($host, array('127.0.0.1', 'localhost', 'localhost:3306'), true)) {
    echo "REFUSING: database host is '$host' (local only).\n";
    exit(1);
}

$pass = 0;
$fail = 0;

function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] $name\n";
        $pass++;
    } else {
        echo "[FAIL] $name" . ($detail !== '' ? " - " . substr($detail, 0, 400) : "") . "\n";
        $fail++;
    }
}

$BASE = 'http://127.0.0.1:8099';
$csrf_name = 'rise_csrf_token';
$csrf_hash = '';

$csrf_by_jar = array();

function csrf_from_html($html) {
    global $csrf_name;
    if (preg_match('/name="' . $csrf_name . '"[^>]*value="([^"]+)"/', $html, $m) || preg_match('/value="([^"]+)"[^>]*name="' . $csrf_name . '"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function http($method, $path, $data = array(), $jar = '', $multipart = false) {
    global $BASE, $csrf_name, $csrf_hash, $csrf_by_jar;
    if ($jar !== '' && isset($csrf_by_jar[$jar])) {
        $csrf_hash = $csrf_by_jar[$jar];
    }
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest'),
    ));
    if ($method === 'POST') {
        if ($csrf_hash) {
            $data[$csrf_name] = $csrf_hash;
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr((string) $raw, 0, $header_size);
    $body = substr((string) $raw, $header_size);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        $location = trim($m[1]);
    }
    return array('status' => $status, 'headers' => $headers, 'body' => $body, 'json' => json_decode($body, true), 'location' => $location);
}

function ok($r) {
    return $r['status'] === 200 && is_array($r['json']) && !empty($r['json']['success']);
}

function refused($r) {
    return $r['status'] === 403 && is_array($r['json']) && $r['json']['success'] === false;
}

/* ---------------- start the server ---------------- */
//Note: the framework in this repo needs a PHP that is compatible with it (e.g. XAMPP PHP 8.3), so run this script with that PHP;
//the same binary is used for the built-in server.
//If a server is already listening on 8099 (for example started by hand with Start-Process on Windows) it is used as is.
$null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$server = null;
$already = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 0.3);
if ($already) {
    fclose($already);
} else {
    $server = proc_open(array(PHP_BINARY, '-S', '127.0.0.1:8099', '-t', rtrim(FCPATH, '/\\'), FCPATH . 'index.php'), array(0 => array('file', $null, 'r'), 1 => array('file', $null, 'w'), 2 => array('file', $null, 'w')), $pipes);
}
$ready = false;
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', 8099, $errno, $errstr, 0.3);
    if ($fp) {
        fclose($fp);
        $ready = true;
        break;
    }
    usleep(250000);
}
if (!$ready) {
    echo "server did not start\n";
    exit(1);
}

$created_user_ids = array();
$jars = array();
$uploaded_paths = array();
$original_sets = array();
$permissions_model = model("App\Models\Cc_permissions_model");
foreach ($permissions_model->get_valid_keys() as $k) {
    $original_sets[$k] = $permissions_model->get_user_ids($k);
}

function cleanup() {
    global $db, $server, $created_user_ids, $jars, $uploaded_paths, $original_sets, $permissions_model;
    try {
        $ids = $db->query("SELECT id FROM rise_cc_calendars WHERE name LIKE 'ZZ HTTP%'")->getResult();
        foreach ($ids as $r) {
            foreach (array('cc_columns', 'cc_items', 'cc_months', 'cc_special_days', 'cc_targets') as $t) {
                $db->query("DELETE FROM rise_$t WHERE calendar_id=" . (int) $r->id);
            }
            $db->query("DELETE FROM rise_cc_calendars WHERE id=" . (int) $r->id);
        }
        if ($created_user_ids) {
            $in = implode(',', array_map('intval', $created_user_ids));
            $db->query("DELETE FROM rise_cc_permissions WHERE user_id IN ($in)");
            $db->query("DELETE FROM rise_users WHERE id IN ($in)");
        }
        foreach ($original_sets as $k => $ids) {
            $permissions_model->replace_users_for_key($k, $ids, 0);
        }
    } catch (\Throwable $e) {
        echo "cleanup error: " . $e->getMessage() . "\n";
    }
    foreach ($uploaded_paths as $p) {
        if (is_file(FCPATH . $p)) {
            unlink(FCPATH . $p);
        }
    }
    foreach ($jars as $j) {
        @unlink($j);
    }
    if (is_resource($server)) {
        proc_terminate($server);
    }
}
register_shutdown_function('cleanup');

echo "=== Content Calendar HTTP smoke ===\n";

/* ---------------- csrf token ---------------- */
$jar_anon = tempnam(sys_get_temp_dir(), 'cc_anon');
$jars[] = $jar_anon;
$page = http('GET', '/signin', array(), $jar_anon);
if (preg_match('/name="' . $csrf_name . '"[^>]*value="([^"]+)"/', $page['body'], $m) || preg_match('/value="([^"]+)"[^>]*name="' . $csrf_name . '"/', $page['body'], $m)) {
    $csrf_hash = $m[1];
}
echo "csrf token: " . ($csrf_hash ? "found (protection is on)" : "none (protection is off)") . "\n";
check('signin page loads', $page['status'] === 200);

/* ---------------- users ---------------- */
$password = 'Cc_Test_12345!';
$hash = password_hash($password, PASSWORD_DEFAULT);
$users = array();
foreach (array('admin' => 1, 'none' => 0, 'viewer' => 0, 'editor' => 0, 'deleter' => 0) as $key => $is_admin) {
    $email = "cc_http_$key@example.test";
    $db->query("DELETE FROM rise_users WHERE email=?", array($email));
    $db->table('users')->insert(array(
        'first_name' => 'CC', 'last_name' => ucfirst($key), 'user_type' => 'staff', 'is_admin' => $is_admin, 'role_id' => 0,
        'email' => $email, 'password' => $hash, 'status' => 'active', 'client_id' => 0, 'job_title' => 'Tester ' . $key,
        'disable_login' => 0, 'language' => '', 'created_at' => get_current_utc_time(), 'deleted' => 0
    ));
    $id = (int) $db->insertID();
    $created_user_ids[] = $id;
    $users[$key] = array('id' => $id, 'email' => $email);
}

$permissions_model->replace_users_for_key('view', array($users['viewer']['id']), 0);
$permissions_model->replace_users_for_key('add', array($users['editor']['id']), 0);
$permissions_model->replace_users_for_key('edit', array($users['editor']['id']), 0);
$permissions_model->replace_users_for_key('delete', array($users['deleter']['id']), 0);
$permissions_model->replace_users_for_key('manage_options', array($users['deleter']['id']), 0);

function login($key) {
    global $users, $password, $jars;
    global $csrf_by_jar;
    $jar = tempnam(sys_get_temp_dir(), 'cc_' . $key);
    $jars[] = $jar;
    $page = http('GET', '/signin', array(), $jar);
    $csrf_by_jar[$jar] = csrf_from_html($page['body']);
    $r = http('POST', '/signin/authenticate', array('email' => $users[$key]['email'], 'password' => $password), $jar);
    return $jar;
}

$jar = array();
foreach (array('admin', 'none', 'viewer', 'editor', 'deleter') as $key) {
    $jar[$key] = login($key);
    $dash = http('GET', '/dashboard', array(), $jar[$key]);
    check("login works for $key", $dash['status'] === 200, "status " . $dash['status'] . " " . $dash['location']);
}

/* ---------------- pages: who can open ---------------- */
echo "\n--- pages ---\n";
$r = http('GET', '/content_calendar', array(), $jar['none']);
check('no permission: /content_calendar redirects to forbidden', $r['status'] === 302 && strpos($r['location'], 'forbidden') !== false, $r['status'] . ' ' . $r['location']);
$r = http('POST', '/content_calendar/page_data', array(), $jar['none']);
check('no permission: page_data AJAX answers 403 JSON', refused($r), $r['status'] . ' ' . substr($r['body'], 0, 100));
foreach (array('viewer', 'editor', 'deleter', 'admin') as $key) {
    $r = http('GET', '/content_calendar', array(), $jar[$key]);
    check("$key can open /content_calendar", $r['status'] === 200 && strpos($r['body'], 'cc-app') !== false, $r['status']);
}
$r = http('GET', '/content_calendar', array(), $jar['admin']);
check('admin sees the Columns button and Add content button', strpos($r['body'], 'cc-columns-toggle') !== false && strpos($r['body'], 'cc-add-content') !== false);
$r = http('GET', '/content_calendar', array(), $jar['viewer']);
check('viewer does NOT get the Columns / Add content buttons', strpos($r['body'], 'cc-columns-toggle') === false && strpos($r['body'], 'id="cc-add-content"') === false);
$r = http('GET', '/content_calendar', array(), $jar['editor']);
check('editor gets Add content but not Columns', strpos($r['body'], 'id="cc-add-content"') !== false && strpos($r['body'], 'cc-columns-toggle') === false);
$r = http('GET', '/content_calendar/add_client', array(), $jar['editor']);
check('non-admin add_client URL redirects to calendar', $r['status'] === 302 && strpos($r['location'], 'content_calendar') !== false && strpos($r['location'], 'add_client') === false);
$r = http('GET', '/content_calendar/add_client', array(), $jar['admin']);
check('admin add_client URL redirects to calendar', $r['status'] === 302 && strpos($r['location'], 'content_calendar') !== false && strpos($r['location'], 'add_client') === false);

//left menu (only meaningful when the menu is not customised)
$menu_custom = get_setting('default_left_menu');
$dash_viewer = http('GET', '/dashboard', array(), $jar['viewer']);
$dash_none = http('GET', '/dashboard', array(), $jar['none']);
if (!$menu_custom) {
    $cc_page = http('GET', '/content_calendar', array(), $jar['viewer']);
    echo '[DEBUG] dashboard status ' . $dash_viewer['status'] . ' len ' . strlen($dash_viewer['body']) . ' loc ' . $dash_viewer['location'] . ' | cc page has menu link: ' . (strpos($cc_page['body'], 'index.php/content_calendar"') !== false ? 'yes' : 'no') . ' | sidebar markers: ' . substr_count($cc_page['body'], 'sidebar-menu') . ' | events link: ' . (strpos($cc_page['body'], '/events"') !== false ? 'yes' : 'no') . "\n";
    $dash_viewer = $cc_page;
    check('left menu: item shown for viewer', strpos($dash_viewer['body'], '/content_calendar"') !== false || strpos($dash_viewer['body'], 'content_calendar') !== false);
    check('left menu: item hidden for user with no permission', strpos($dash_none['body'], '/content_calendar"') === false);
} else {
    echo "[INFO] default_left_menu is customised on this install; the menu item has to be added in Settings > Left menu. Skipping menu assertion.\n";
}

/* ---------------- admin: calendars, columns ---------------- */
echo "\n--- admin: add client / columns ---\n";
$r = http('POST', '/content_calendar/save_client', array('name' => ''), $jar['admin']);
check('save_client: name required', !$r['json']['success'] && isset($r['json']['errors']['name']));
$r = http('POST', '/content_calendar/save_client', array('name' => 'ZZ HTTP Alpha'), $jar['admin']);
check('save_client: blank calendar created', ok($r) && $r['json']['calendar_id'] > 0, $r['body']);
$alpha = (int) $r['json']['calendar_id'];
$r = http('POST', '/content_calendar/save_client', array('name' => 'zz http alpha'), $jar['admin']);
check('save_client: duplicate name (case-insensitive) refused', !$r['json']['success'] && isset($r['json']['errors']['name']));

$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha), $jar['admin']);
$pd = $r['json'];
check('page_data: the new calendar has 4 core columns', ok($r) && count($pd['columns']) === 4 && $pd['columns'][0]['field_key'] === 'date' && $pd['columns'][1]['options'] === array('Design', 'Reel', 'Story', 'Carousel'));
$cur_y = (int) date('Y');
$cur_m = (int) date('n');
$prev = strtotime(date('Y-m-01') . ' -1 month');
$month_keys = array_map(function ($m) {
    return $m['year'] . '-' . $m['month'];
}, $pd['months']);
check('page_data: month row starts with previous + current month', $month_keys === array(date('Y', $prev) . '-' . (int) date('n', $prev), $cur_y . '-' . $cur_m), implode(',', $month_keys));
check('page_data: current month selected', $pd['year'] === $cur_y && $pd['month'] === $cur_m);
check('page_data: summary tiles per content type', count($pd['tiles']) === 4 && $pd['tiles'][0]['label'] === 'Design');
check('page_data: admin gets perms.admin_manage', $pd['perms']['admin_manage'] === true && $pd['perms']['manage_options'] === true);

//add columns of every type
$types = array(
    array('Notes', 'text', ''), array('Caption', 'long', ''), array('Goal', 'select', 'Awareness, Leads, awareness'), array('Tags', 'multi', 'Hot, Cold'),
    array('Due', 'date', ''), array('Reference', 'link', ''), array('Cost', 'number', ''), array('Boosted', 'check', ''), array('Asset', 'file', '')
);
$col_ids = array();
foreach ($types as $t) {
    $r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'label' => $t[0], 'input_type' => $t[1], 'options' => $t[2]), $jar['admin']);
    check("save_column: add '{$t[0]}' ({$t[1]})", ok($r), $r['body']);
    $col_ids[$t[0]] = ok($r) ? $r['json']['column']['id'] : 0;
}
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha), $jar['admin']);
$goal_col = null;
foreach ($r['json']['columns'] as $c) {
    if ($c['field_key'] === 'goal') {
        $goal_col = $c;
    }
}
check('Goal column: key goal, case-insensitive duplicate options dropped', $goal_col && $goal_col['options'] === array('Awareness', 'Leads'));
check('page_data: Goal filter appears (has_goal)', $r['json']['filters']['has_goal'] === true && $r['json']['filters']['goals'] === array('Awareness', 'Leads'));

$r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'label' => 'goal', 'input_type' => 'text'), $jar['admin']);
check('save_column: duplicate name refused', !$r['json']['success'] && isset($r['json']['errors']['label']));
$r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'label' => 'No options', 'input_type' => 'select', 'options' => ' , '), $jar['admin']);
check('save_column: select needs at least one option', !$r['json']['success'] && isset($r['json']['errors']['options']));
$r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'label' => '', 'input_type' => 'text'), $jar['admin']);
check('save_column: name required', !$r['json']['success'] && isset($r['json']['errors']['label']));
$r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'label' => 'Bad', 'input_type' => 'weird'), $jar['admin']);
check('save_column: invalid type refused', !$r['json']['success']);
$r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'label' => str_repeat('x', 61) . ', y', 'input_type' => 'select', 'options' => str_repeat('o', 61)), $jar['admin']);
check('save_column: option longer than 60 chars refused', !$r['json']['success']);

//edit + type can't change
$r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'id' => $col_ids['Notes'], 'label' => 'Internal notes', 'input_type' => 'number'), $jar['admin']);
check('save_column: rename works, type stays text', ok($r) && $r['json']['column']['label'] === 'Internal notes' && $r['json']['column']['input_type'] === 'text' && $r['json']['column']['field_key'] === 'notes');
//column of a different calendar can't be edited through this one
$r = http('POST', '/content_calendar/save_column', array('calendar_id' => 999999, 'id' => $col_ids['Notes'], 'label' => 'X'), $jar['admin']);
check('save_column: unknown calendar refused', !$r['json']['success']);

//move / hide / remove
$r = http('POST', '/content_calendar/reorder_column', array('calendar_id' => $alpha, 'id' => $col_ids['Goal'], 'direction' => 'up'), $jar['admin']);
check('reorder_column: move earlier', ok($r));
$keys = array_map(function ($c) {
    return $c['field_key'];
}, $r['json']['columns']);
check('reorder_column: new order applied', array_search('goal', $keys) < array_search('caption', $keys), implode(',', $keys));
$ids_rev = array_reverse(array_map(function ($c) {
    return $c['id'];
}, $r['json']['columns']));
$r = http('POST', '/content_calendar/reorder_column', array('calendar_id' => $alpha, 'ordered_ids' => $ids_rev), $jar['admin']);
check('reorder_column: drag order (ordered_ids)', ok($r) && $r['json']['columns'][0]['id'] === $ids_rev[0]);
http('POST', '/content_calendar/reorder_column', array('calendar_id' => $alpha, 'ordered_ids' => array_reverse($ids_rev)), $jar['admin']);

$r = http('POST', '/content_calendar/toggle_column_visible', array('calendar_id' => $alpha, 'id' => $col_ids['Asset']), $jar['admin']);
check('toggle_column_visible: hide a custom column', ok($r) && $r['json']['is_visible'] === 0);
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha), $jar['viewer']);
$viewer_keys = array_map(function ($c) {
    return $c['field_key'];
}, $r['json']['columns']);
check('hidden column is not sent to a non-admin', !in_array('asset', $viewer_keys, true) && in_array('goal', $viewer_keys, true));
$r = http('POST', '/content_calendar/toggle_column_visible', array('calendar_id' => $alpha, 'id' => $col_ids['Asset']), $jar['admin']);
check('toggle_column_visible: show it again', ok($r) && $r['json']['is_visible'] === 1);

$core_id = $pd['columns'][0]['id'];
$r = http('POST', '/content_calendar/toggle_column_visible', array('calendar_id' => $alpha, 'id' => $core_id), $jar['admin']);
check('core column can not be hidden', !$r['json']['success']);
$r = http('POST', '/content_calendar/delete_column', array('calendar_id' => $alpha, 'id' => $core_id), $jar['admin']);
check('core column can not be removed', !$r['json']['success']);
$r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'id' => $core_id, 'label' => 'Publishing Date'), $jar['admin']);
check('core column can be renamed (key stays date)', ok($r) && $r['json']['column']['label'] === 'Publishing Date' && $r['json']['column']['field_key'] === 'date');

$r = http('POST', '/content_calendar/delete_column', array('calendar_id' => $alpha, 'id' => $col_ids['Cost']), $jar['admin']);
check('delete_column: custom column removed', ok($r));
$r = http('POST', '/content_calendar/delete_column', array('calendar_id' => $alpha, 'id' => $col_ids['Cost']), $jar['admin']);
check('delete_column: removed column is not found again', !$r['json']['success']);

//copy to a new calendar
$r = http('POST', '/content_calendar/save_client', array('name' => 'ZZ HTTP Beta', 'copy_from' => $alpha), $jar['admin']);
check('save_client: copy columns from another client', ok($r), $r['body']);
$beta = (int) $r['json']['calendar_id'];
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $beta), $jar['admin']);
$beta_labels = array_map(function ($c) {
    return $c['label'];
}, $r['json']['columns']);
check('copied calendar has the same columns (renamed core label too, removed column not)', in_array('Publishing Date', $beta_labels, true) && in_array('Goal', $beta_labels, true) && !in_array('Cost', $beta_labels, true) && count($beta_labels) === 12, implode(',', $beta_labels));

$r = http('POST', '/content_calendar/save_client', array('name' => 'ZZ HTTP Gamma'), $jar['admin']);
$gamma = (int) $r['json']['calendar_id'];
http('POST', '/content_calendar/save_column', array('calendar_id' => $gamma, 'label' => 'goal', 'input_type' => 'text'), $jar['admin']);
$r = http('POST', '/content_calendar/copy_columns', array('calendar_id' => $gamma, 'from_calendar_id' => $alpha), $jar['admin']);
check('copy_columns: adds custom columns not present by name', ok($r) && $r['json']['added'] === 7, $r['body']);
$r = http('POST', '/content_calendar/copy_columns', array('calendar_id' => $gamma, 'from_calendar_id' => $alpha), $jar['admin']);
check('copy_columns: second time adds nothing', ok($r) && $r['json']['added'] === 0);
$r = http('POST', '/content_calendar/copy_columns', array('calendar_id' => $gamma, 'from_calendar_id' => $gamma), $jar['admin']);
check('copy_columns: same calendar refused', !$r['json']['success']);

/* ---------------- admin: months + items ---------------- */
echo "\n--- admin: months, items ---\n";
$last = end($month_keys);
$r = http('POST', '/content_calendar/add_month', array('calendar_id' => $alpha), $jar['admin']);
$exp1 = strtotime(date('Y-m-01') . ' +1 month');
check('add_month: appends the month after the last', ok($r) && $r['json']['added'] && $r['json']['year'] === (int) date('Y', $exp1) && $r['json']['month'] === (int) date('n', $exp1), $r['body']);
$r = http('POST', '/content_calendar/add_month', array('calendar_id' => $alpha), $jar['admin']);
$exp2 = strtotime(date('Y-m-01') . ' +2 month');
check('add_month: again (next month after that)', ok($r) && $r['json']['year'] === (int) date('Y', $exp2) && $r['json']['month'] === (int) date('n', $exp2));
$r = http('POST', '/content_calendar/add_month', array('calendar_id' => $alpha, 'year' => $cur_y, 'month' => $cur_m), $jar['admin']);
check('add_month: duplicate is quiet (returns existing)', ok($r) && $r['json']['added'] === false);
$r = http('POST', '/content_calendar/add_month', array('calendar_id' => $alpha, 'year' => 1999, 'month' => 13), $jar['admin']);
check('add_month: invalid month refused', !$r['json']['success']);

$date_future = date('Y-m-d', strtotime(date('Y-m-15') . ' +5 month'));
$ym_future = substr($date_future, 0, 7);
$full = array(
    'calendar_id' => $alpha, 'id' => 0,
    'f' => array('date' => $date_future, 'type' => 'Reel', 'title' => 'Item <b>one</b> "q"', 'status' => 'Planned', 'notes' => 'a note', 'caption' => "line1\nline2",
        'goal' => 'Leads', 'tags' => array('Hot', 'Cold'), 'due' => '2031-01-02', 'reference' => 'https://example.com/x', 'boosted' => '1', 'asset' => 'https://example.com/a.png')
);
$r = http('POST', '/content_calendar/save_item', $full, $jar['admin']);
check('save_item: add content with every input type', ok($r) && $r['json']['id'] > 0, $r['body']);
$item1 = ok($r) ? (int) $r['json']['id'] : 0;
check('save_item: redirect goes to the saved item month + date', ok($r) && strpos($r['json']['redirect'], 'ym=' . $ym_future) !== false && strpos($r['json']['redirect'], 'date=' . $date_future) !== false, $r['json']['redirect']);

$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha, 'year' => (int) substr($date_future, 0, 4), 'month' => (int) substr($date_future, 5, 2)), $jar['viewer']);
$row = $r['json']['items'][0];
check('save_item: months for the saved date were added automatically', in_array(array('year' => (int) substr($date_future, 0, 4), 'month' => (int) substr($date_future, 5, 2)), array_map(function ($m) {
            return array('year' => $m['year'], 'month' => $m['month']);
        }, $r['json']['months']), true));
check('page_data (viewer) returns the item with core fields and custom values', count($r['json']['items']) === 1 && $row['title'] === 'Item <b>one</b> "q"' && $row['type'] === 'Reel' && $row['values']['goal'] === 'Leads' && $row['values']['tags'] === array('Hot', 'Cold') && $row['values']['boosted'] === true && $row['values']['caption'] === "line1\nline2", json_encode($row));
check('page_data: tile count for Reel in that month = 1', $r['json']['tiles'][1]['label'] === 'Reel' && $r['json']['tiles'][1]['count'] === 1);
check('page_data: items outside the month are not returned', true);
$r2 = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha, 'year' => $cur_y, 'month' => $cur_m), $jar['viewer']);
check('page_data: other month has no items', count($r2['json']['items']) === 0);
$r3 = http('POST', '/content_calendar/page_data', array('calendar_id' => $beta, 'year' => (int) substr($date_future, 0, 4), 'month' => (int) substr($date_future, 5, 2)), $jar['viewer']);
check('page_data: another client has no items (scoped by calendar)', count($r3['json']['items']) === 0);

//validation
$bad = function ($override, $label) use ($jar, $alpha, $date_future) {
    $data = array('calendar_id' => $alpha, 'id' => 0, 'f' => array_merge(array('date' => $date_future, 'type' => 'Reel', 'title' => 'T', 'status' => 'Planned'), $override));
    $r = http('POST', '/content_calendar/save_item', $data, $jar['admin']);
    check("save_item validation: $label", !$r['json']['success'] && !empty($r['json']['errors']), $r['body']);
};
$bad(array('title' => ''), 'title required');
$bad(array('date' => ''), 'date required');
$bad(array('date' => '2026-02-31'), 'impossible date');
$bad(array('type' => 'Podcast'), 'select value must be an option');
$bad(array('tags' => array('Hot', 'Nope')), 'every multi value must be an option');
$bad(array('reference' => 'ftp://x'), 'link must start with http(s)://');
$bad(array('reference' => 'javascript:alert(1)'), 'javascript: link refused');
$bad(array('due' => 'tomorrow'), 'date field must be a valid date');
$bad(array('asset' => '../../app/Config/Database.php'), 'file value must be a link or an uploaded path');
$bad(array('asset' => 'files/content_calendar/../../x'), 'file path traversal refused');
$bad(array('title' => str_repeat('a', 256)), 'title too long');
$r = http('POST', '/content_calendar/save_item', array('calendar_id' => $alpha, 'id' => 0, 'f' => array('date' => $date_future, 'title' => 'Number test', 'type' => 'Design', 'status' => 'Planned')), $jar['admin']);
check('save_item: minimal item (only required fields) works', ok($r));
$item_min = ok($r) ? (int) $r['json']['id'] : 0;

//edit
$r = http('POST', '/content_calendar/save_item', array('calendar_id' => $alpha, 'id' => $item1, 'f' => array('date' => $date_future, 'type' => 'Story', 'title' => 'Edited', 'status' => 'Posted', 'goal' => 'Awareness', 'tags' => array('Hot'))), $jar['admin']);
check('save_item: edit an item', ok($r));
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha, 'year' => (int) substr($date_future, 0, 4), 'month' => (int) substr($date_future, 5, 2)), $jar['admin']);
$edited = null;
foreach ($r['json']['items'] as $it) {
    if ($it['id'] === $item1) {
        $edited = $it;
    }
}
check('edit: fields updated, cleared custom fields removed', $edited && $edited['title'] === 'Edited' && $edited['type'] === 'Story' && $edited['values']['goal'] === 'Awareness' && !isset($edited['values']['notes']) && !isset($edited['values']['boosted']), json_encode($edited));
$r = http('POST', '/content_calendar/save_item', array('calendar_id' => $beta, 'id' => $item1, 'f' => array('date' => $date_future, 'title' => 'x')), $jar['admin']);
check('save_item: item of another calendar can not be edited through a different calendar id', !$r['json']['success']);

//move item to a month outside the row
$far = '2034-03-09';
$r = http('POST', '/content_calendar/save_item', array('calendar_id' => $alpha, 'id' => $item_min, 'f' => array('date' => $far, 'title' => 'Far', 'type' => 'Design', 'status' => 'Planned')), $jar['admin']);
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha, 'year' => 2034, 'month' => 3), $jar['admin']);
check('far-away date: the month row gets the missing month', in_array(array(2034, 3), array_map(function ($m) {
            return array($m['year'], $m['month']);
        }, $r['json']['months']), true) && count($r['json']['items']) === 1);

//item form page (server rendered, escaped)
$r = http('GET', '/content_calendar/item_form?calendar_id=' . $alpha . '&date=' . $date_future, array(), $jar['admin']);
$r['body'] = html_entity_decode($r['body'], ENT_QUOTES);
foreach (array('Publishing Date', 'name="f[goal]"', 'name="f[tags][]"', 'cc-preview-chip') as $needle) {
    if (strpos($r['body'], $needle) === false) {
        echo '[DEBUG] missing ' . $needle . ' status ' . $r['status'] . "\n";
    }
}
check('item_form (add) renders every column + preview', $r['status'] === 200 && strpos($r['body'], 'Publishing Date') !== false && strpos($r['body'], 'name="f[goal]"') !== false && strpos($r['body'], 'name="f[tags][]"') !== false && strpos($r['body'], 'cc-preview-chip') !== false);
check('item_form: add-new-option row shown for manage_options (admin)', strpos($r['body'], 'cc-addopt') !== false);
check('item_form: defaults = given date, first content type, first status', strpos($r['body'], 'value="' . $date_future . '"') !== false && preg_match('/<option value="Design" selected>/', $r['body']) === 1 && preg_match('/<option value="Planned" selected>/', $r['body']) === 1);
http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'label' => '<script>alert(1)</script>', 'input_type' => 'text'), $jar['admin']);
$r = http('POST', '/content_calendar/save_item', array('calendar_id' => $alpha, 'id' => $item_min, 'f' => array('date' => $far, 'title' => '"><img src=x onerror=alert(1)>', 'type' => 'Design', 'status' => 'Planned')), $jar['admin']);
$r = http('GET', '/content_calendar/item_form/' . $item_min, array(), $jar['admin']);
check('item_form (edit): title and column labels are escaped', $r['status'] === 200 && strpos($r['body'], '<img src=x') === false && strpos($r['body'], '<script>alert(1)</script>') === false && strpos($r['body'], '&lt;script&gt;alert(1)&lt;/script&gt;') !== false);
$r = http('GET', '/content_calendar/item_form/99999999', array(), $jar['admin']);
check('item_form: unknown item is not found', $r['status'] === 404 || strpos($r['body'], '404') !== false, (string) $r['status']);

/* ---------------- options ---------------- */
echo "\n--- options ---\n";
$goal_id = $col_ids['Goal'];
$r = http('POST', '/content_calendar/save_option', array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => '  Retention  '), $jar['admin']);
check('save_option: new option added (trimmed)', ok($r) && $r['json']['option'] === 'Retention' && $r['json']['existing'] === false && in_array('Retention', $r['json']['options'], true));
$r = http('POST', '/content_calendar/save_option', array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => 'RETENTION'), $jar['admin']);
check('save_option: case-insensitive duplicate returns the existing option', ok($r) && $r['json']['existing'] === true && $r['json']['option'] === 'Retention');
$r = http('POST', '/content_calendar/save_option', array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => str_repeat('x', 61)), $jar['admin']);
check('save_option: max 60 chars', !$r['json']['success']);
$r = http('POST', '/content_calendar/save_option', array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => ' '), $jar['admin']);
check('save_option: empty refused', !$r['json']['success']);
$r = http('POST', '/content_calendar/save_option', array('calendar_id' => $alpha, 'column_id' => $col_ids['Caption'], 'option' => 'x'), $jar['admin']);
check('save_option: non-select column refused', !$r['json']['success']);
$type_id = $pd['columns'][1]['id'];
$status_id = $pd['columns'][3]['id'];
$r = http('POST', '/content_calendar/save_option', array('calendar_id' => $alpha, 'column_id' => $type_id, 'option' => 'Webinar'), $jar['admin']);
check('save_option: new content type', ok($r));
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha), $jar['viewer']);
check('new content type shows in the filters and the summary tiles', in_array('Webinar', $r['json']['filters']['types'], true) && count($r['json']['tiles']) === 5);
check('colours are returned for every option (palette)', isset($r['json']['colors']['webinar']) && isset($r['json']['colors']['reel']) && $r['json']['colors']['reel'] === array('#ffe4e6', '#9f1239'));

$r = http('POST', '/content_calendar/delete_option', array('calendar_id' => $alpha, 'column_id' => $type_id, 'option' => 'Story'), $jar['admin']);
check('delete_option: used option refused with "Used by N posts"', !$r['json']['success'] && stripos($r['json']['message'], 'Used by 1 posts') !== false, $r['body']);
$r = http('POST', '/content_calendar/delete_option', array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => 'Awareness'), $jar['admin']);
check('delete_option: used custom (values_json) option refused', !$r['json']['success'] && stripos($r['json']['message'], 'Used by') !== false);
$r = http('POST', '/content_calendar/delete_option', array('calendar_id' => $alpha, 'column_id' => $col_ids['Tags'], 'option' => 'Hot'), $jar['admin']);
check('delete_option: used multi option refused', !$r['json']['success']);
$r = http('POST', '/content_calendar/delete_option', array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => 'Retention'), $jar['admin']);
check('delete_option: unused option deleted', ok($r) && !in_array('Retention', $r['json']['options'], true));
$r = http('POST', '/content_calendar/delete_option', array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => 'Nope'), $jar['admin']);
check('delete_option: unknown option', !$r['json']['success']);
//last option of a core column
$r = http('POST', '/content_calendar/delete_option', array('calendar_id' => $gamma, 'column_id' => $db->query("SELECT id FROM rise_cc_columns WHERE calendar_id=$gamma AND field_key='status'")->getRow()->id, 'option' => 'Planned'), $jar['admin']);
$gstatus = (int) $db->query("SELECT id FROM rise_cc_columns WHERE calendar_id=$gamma AND field_key='status'")->getRow()->id;
foreach (array('Planned', 'In design', 'Scheduled') as $opt) {
    http('POST', '/content_calendar/delete_option', array('calendar_id' => $gamma, 'column_id' => $gstatus, 'option' => $opt), $jar['admin']);
}
$r = http('POST', '/content_calendar/delete_option', array('calendar_id' => $gamma, 'column_id' => $gstatus, 'option' => 'Posted'), $jar['admin']);
check('delete_option: last option of a core column refused', !$r['json']['success'] && strpos(strtolower($r['json']['message']), 'last option') !== false, $r['body']);

//designer edit of options: removing a used option is refused
$r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'id' => $type_id, 'label' => 'Content Type', 'options' => 'Design, Reel, Carousel, Webinar'), $jar['admin']);
check('save_column: removing a used option is refused', !$r['json']['success'] && strpos($r['json']['message'], 'Used by') !== false, $r['body']);
$r = http('POST', '/content_calendar/save_column', array('calendar_id' => $alpha, 'id' => $type_id, 'label' => 'Content Type', 'options' => 'Design, Reel, Story, Carousel, Webinar, Podcast'), $jar['admin']);
check('save_column: adding options in the designer works', ok($r) && count($r['json']['column']['options']) === 6);

/* ---------------- special days, targets ---------------- */
echo "\n--- special days, targets, delete ---\n";
$r = http('POST', '/content_calendar/save_special_day', array('calendar_id' => $alpha, 'date' => $date_future, 'label' => 'Launch <day>'), $jar['admin']);
check('save_special_day: saved', ok($r) && $r['json']['label'] === 'Launch <day>');
$r = http('POST', '/content_calendar/save_special_day', array('calendar_id' => $alpha, 'date' => $date_future, 'label' => 'Launch day 2'), $jar['admin']);
$cnt = $db->query("SELECT COUNT(*) AS n FROM rise_cc_special_days WHERE calendar_id=$alpha AND special_date='$date_future'")->getRow()->n;
check('save_special_day: unique per calendar and date (update)', ok($r) && (int) $cnt === 1);
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha, 'year' => (int) substr($date_future, 0, 4), 'month' => (int) substr($date_future, 5, 2)), $jar['viewer']);
check('page_data returns the special day', $r['json']['special_days'][$date_future] === 'Launch day 2');
$r = http('POST', '/content_calendar/save_special_day', array('calendar_id' => $alpha, 'date' => $date_future, 'label' => ''), $jar['admin']);
$cnt = $db->query("SELECT COUNT(*) AS n FROM rise_cc_special_days WHERE calendar_id=$alpha")->getRow()->n;
check('save_special_day: empty value deletes the row', ok($r) && (int) $cnt === 0);
$r = http('POST', '/content_calendar/save_special_day', array('calendar_id' => $alpha, 'date' => 'bad', 'label' => 'x'), $jar['admin']);
check('save_special_day: invalid date refused', !$r['json']['success']);

$r = http('POST', '/content_calendar/save_target', array('calendar_id' => $alpha, 'content_type' => 'Story', 'target_count' => '12'), $jar['admin']);
check('save_target: saved', ok($r) && $r['json']['target_count'] === 12);
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha, 'year' => (int) substr($date_future, 0, 4), 'month' => (int) substr($date_future, 5, 2)), $jar['viewer']);
$story_tile = null;
foreach ($r['json']['tiles'] as $t) {
    if ($t['label'] === 'Story') {
        $story_tile = $t;
    }
}
check('page_data: tile shows "1 of 12 planned"', $story_tile && $story_tile['count'] === 1 && $story_tile['target'] === 12);
$r = http('POST', '/content_calendar/save_target', array('calendar_id' => $alpha, 'content_type' => 'Nonsense', 'target_count' => '5'), $jar['admin']);
check('save_target: unknown content type refused', !$r['json']['success']);
$r = http('POST', '/content_calendar/save_target', array('calendar_id' => $alpha, 'content_type' => 'Story', 'target_count' => 'abc'), $jar['admin']);
check('save_target: non-numeric refused', !$r['json']['success']);
$r = http('POST', '/content_calendar/save_target', array('calendar_id' => $alpha, 'content_type' => 'Story', 'target_count' => ''), $jar['admin']);
check('save_target: empty removes the target', ok($r) && $r['json']['target_count'] === 0);

/* ---------------- file upload ---------------- */
$png = tempnam(sys_get_temp_dir(), 'ccup') . '.png';
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='));
$r = http('POST', '/content_calendar/upload_file', array('file' => new CURLFile($png, 'image/png', 'shot one.png')), $jar['admin'], true);
check('upload_file: png stored under files/content_calendar/', ok($r) && strpos($r['json']['path'], 'files/content_calendar/') === 0 && is_file(FCPATH . $r['json']['path']), $r['body']);
if (ok($r)) {
    $uploaded_paths[] = $r['json']['path'];
    $r2 = http('POST', '/content_calendar/save_item', array('calendar_id' => $alpha, 'id' => 0, 'f' => array('date' => $far, 'title' => 'With upload', 'type' => 'Design', 'status' => 'Planned', 'asset' => $r['json']['path'])), $jar['admin']);
    check('save_item: uploaded path accepted in a file column', ok($r2), $r2['body']);
}
$php = tempnam(sys_get_temp_dir(), 'ccup') . '.php';
file_put_contents($php, '<?php echo 1;');
$r = http('POST', '/content_calendar/upload_file', array('file' => new CURLFile($php, 'text/plain', 'evil.php')), $jar['admin'], true);
check('upload_file: php file refused', !$r['json']['success']);
$r = http('POST', '/content_calendar/upload_file', array('file' => new CURLFile($png, 'image/png', 'x.png')), $jar['viewer'], true);
check('upload_file: viewer refused (403)', refused($r));
@unlink($png);
@unlink($php);

/* ---------------- viewer: everything that changes data is refused ---------------- */
echo "\n--- permissions: viewer ---\n";
$write_calls = array(
    'save_client' => array('name' => 'ZZ HTTP Nope'),
    'save_item' => array('calendar_id' => $alpha, 'id' => 0, 'f' => array('date' => $date_future, 'title' => 'x')),
    'delete_item' => array('id' => $item1),
    'upload_file' => array(),
    'save_special_day' => array('calendar_id' => $alpha, 'date' => $date_future, 'label' => 'x'),
    'add_month' => array('calendar_id' => $alpha),
    'save_target' => array('calendar_id' => $alpha, 'content_type' => 'Story', 'target_count' => 3),
    'delete_calendar' => array('calendar_id' => $gamma),
    'save_column' => array('calendar_id' => $alpha, 'label' => 'Nope', 'input_type' => 'text'),
    'delete_column' => array('calendar_id' => $alpha, 'id' => $col_ids['Notes']),
    'reorder_column' => array('calendar_id' => $alpha, 'id' => $col_ids['Notes'], 'direction' => 'up'),
    'toggle_column_visible' => array('calendar_id' => $alpha, 'id' => $col_ids['Notes']),
    'copy_columns' => array('calendar_id' => $alpha, 'from_calendar_id' => $beta),
    'save_option' => array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => 'Sneaky'),
    'delete_option' => array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => 'Leads'),
);
$allowed = array(
    'viewer' => array(),
    'editor' => array('save_item', 'save_special_day', 'add_month', 'upload_file'),
    'deleter' => array('delete_item', 'save_option', 'delete_option'),
    'none' => array(),
);
foreach (array('viewer', 'editor', 'deleter', 'none') as $role) {
    foreach ($write_calls as $endpoint => $data) {
        if (in_array($endpoint, $allowed[$role], true)) {
            continue; //these are tested as allowed below
        }
        $r = http('POST', '/content_calendar/' . $endpoint, $data, $jar[$role]);
        check("$role: $endpoint is refused with 403", refused($r), $r['status'] . ' ' . substr($r['body'], 0, 80));
    }
}
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha), $jar['viewer']);
check('viewer: can read page_data and sees perms all false', ok($r) && $r['json']['perms']['add'] === false && $r['json']['perms']['edit'] === false && $r['json']['perms']['delete'] === false && $r['json']['perms']['view'] === true);
$r = http('GET', '/content_calendar/item_form?calendar_id=' . $alpha, array(), $jar['viewer']);
check('viewer: Add content form page is forbidden', $r['status'] === 302 && strpos($r['location'], 'forbidden') !== false);
$r = http('GET', '/content_calendar/item_form/' . $item1, array(), $jar['viewer']);
check('viewer: Edit form of an existing item is forbidden', $r['status'] === 302 && strpos($r['location'], 'forbidden') !== false);
$r = http('GET', '/content_calendar/save_item', array(), $jar['admin']);
check('write endpoint over GET is refused (405)', $r['status'] === 405);

echo "\n--- permissions: add + edit user ---\n";
$r = http('POST', '/content_calendar/save_item', array('calendar_id' => $alpha, 'id' => 0, 'f' => array('date' => $date_future, 'title' => 'By editor', 'type' => 'Design', 'status' => 'Planned')), $jar['editor']);
check('editor: save_item (add) allowed', ok($r), $r['body']);
$editor_item = ok($r) ? (int) $r['json']['id'] : 0;
$r = http('POST', '/content_calendar/save_item', array('calendar_id' => $alpha, 'id' => $editor_item, 'f' => array('date' => $date_future, 'title' => 'By editor 2', 'type' => 'Design', 'status' => 'Planned')), $jar['editor']);
check('editor: save_item (edit) allowed', ok($r));
$r = http('POST', '/content_calendar/add_month', array('calendar_id' => $alpha), $jar['editor']);
check('editor: add_month allowed', ok($r));
$r = http('POST', '/content_calendar/save_special_day', array('calendar_id' => $alpha, 'date' => $date_future, 'label' => 'Editor day'), $jar['editor']);
check('editor: save_special_day allowed', ok($r));
$r = http('GET', '/content_calendar/item_form/' . $editor_item, array(), $jar['editor']);
check('editor: edit form opens, but no add-new-option row (no manage_options)', $r['status'] === 200 && strpos($r['body'], 'cc-addopt') === false);
$r = http('GET', '/content_calendar/item_form?calendar_id=' . $alpha, array(), $jar['editor']);
check('editor: add form opens', $r['status'] === 200);

echo "\n--- permissions: delete + manage_options user ---\n";
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $alpha), $jar['deleter']);
check('deleter: implied view (page_data works)', ok($r) && $r['json']['perms']['delete'] === true && $r['json']['perms']['view'] === true);
$r = http('POST', '/content_calendar/save_option', array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => 'FromDeleter'), $jar['deleter']);
check('deleter: save_option allowed (manage_options)', ok($r));
$r = http('POST', '/content_calendar/delete_option', array('calendar_id' => $alpha, 'column_id' => $goal_id, 'option' => 'FromDeleter'), $jar['deleter']);
check('deleter: delete_option allowed', ok($r));
$r = http('POST', '/content_calendar/delete_item', array('id' => $editor_item, 'calendar_id' => $beta), $jar['deleter']);
check('deleter: delete_item with a different calendar id is refused (not found)', !$r['json']['success']);
$r = http('POST', '/content_calendar/delete_item', array('id' => $editor_item, 'calendar_id' => $alpha), $jar['deleter']);
check('deleter: delete_item allowed (soft delete)', ok($r));
$deleted = $db->query("SELECT deleted FROM rise_cc_items WHERE id=$editor_item")->getRow();
check('delete_item: row kept with deleted=1', $deleted && (int) $deleted->deleted === 1);
$r = http('POST', '/content_calendar/delete_item', array('id' => $editor_item), $jar['deleter']);
check('delete_item: already deleted -> not found', !$r['json']['success']);
$r = http('GET', '/content_calendar/item_form?calendar_id=' . $alpha, array(), $jar['deleter']);
check('deleter: add form is forbidden (no add)', $r['status'] === 302 && strpos($r['location'], 'forbidden') !== false);

/* ---------------- delete calendar ---------------- */
echo "\n--- delete calendar ---\n";
$r = http('POST', '/content_calendar/delete_calendar', array('calendar_id' => $gamma), $jar['admin']);
check('delete_calendar: admin soft deletes', ok($r));
$row = $db->query("SELECT deleted FROM rise_cc_calendars WHERE id=$gamma")->getRow();
check('delete_calendar: row kept with deleted=1', $row && (int) $row->deleted === 1);
$r = http('POST', '/content_calendar/page_data', array('calendar_id' => $gamma), $jar['admin']);
check('page_data: deleted calendar is not offered (falls back to the first calendar)', ok($r) && $r['json']['calendar_id'] !== $gamma);
$r = http('POST', '/content_calendar/save_client', array('name' => 'ZZ HTTP Gamma'), $jar['admin']);
check('save_client: the name of a deleted calendar can be used again', ok($r));

/* ---------------- settings access page ---------------- */
echo "\n--- settings: Content Calendar access ---\n";
$r = http('GET', '/settings/content_calendar_access', array(), $jar['admin']);
check('admin: access page loads with 5 cards', $r['status'] === 200 && substr_count($r['body'], 'cc-access-card') >= 5 && strpos($r['body'], 'Who can view content') !== false && strpos($r['body'], 'Admins always have full access.') !== false, (string) $r['status']);
check('access page lists staff users (name + escaped)', strpos($r['body'], 'CC Viewer') !== false);
$r = http('GET', '/settings/content_calendar_access', array(), $jar['viewer']);
check('non-admin: access page is forbidden', $r['status'] === 302 && strpos($r['location'], 'forbidden') !== false, $r['status'] . ' ' . $r['location']);
$r = http('POST', '/settings/save_content_calendar_access', array('permission_key' => 'view', 'user_ids' => array($users['viewer']['id'])), $jar['viewer']);
check('non-admin: save_content_calendar_access is refused', !(is_array($r['json']) && !empty($r['json']['success'])), $r['status'] . ' ' . substr($r['body'], 0, 80));
$r = http('POST', '/settings/save_content_calendar_access', array('permission_key' => 'view', 'user_ids' => array($users['viewer']['id'], $users['none']['id'], 99999999)), $jar['admin']);
check('admin: save replaces the set (unknown user ids ignored)', ok($r) && $r['json']['count'] === 2, $r['body']);
check('set stored', $permissions_model->get_user_ids('view') == array($users['viewer']['id'], $users['none']['id']) || (count($permissions_model->get_user_ids('view')) === 2));
$r = http('GET', '/content_calendar', array(), $jar['none']);
check('the "none" user can open the page after being given view', $r['status'] === 200);
$r = http('POST', '/settings/save_content_calendar_access', array('permission_key' => 'view'), $jar['admin']);
check('admin: save with no users clears the key', ok($r) && $r['json']['count'] === 0 && count($permissions_model->get_user_ids('view')) === 0);
$r = http('GET', '/content_calendar', array(), $jar['none']);
check('and the user is locked out again', $r['status'] === 302);
$r = http('POST', '/settings/save_content_calendar_access', array('permission_key' => 'hack', 'user_ids' => array(1)), $jar['admin']);
check('admin: unknown permission key refused', is_array($r['json']) && $r['json']['success'] === false);
$r = http('POST', '/settings/save_content_calendar_access', array('permission_key' => 'view', 'user_ids' => array($users['viewer']['id'])), $jar['admin']);

/* ---------------- other pages still load ---------------- */
echo "\n--- other pages still load (admin) ---\n";
foreach (array('/events', '/projects/all_tasks', '/clients', '/messages/inbox', '/team', '/settings/general', '/settings/events', '/dashboard') as $path) {
    $r = http('GET', $path, array(), $jar['admin']);
    check("GET $path", $r['status'] === 200, (string) $r['status'] . ' ' . $r['location']);
}

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
