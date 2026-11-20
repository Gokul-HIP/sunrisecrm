<?php
/**
 * Content Calendar audit: SQL file + tables, helper (cc_can logic), models, source assertions.
 * Run after tests/_tmp_run_content_calendar_sql.php. Uses the LOCAL database, inside a transaction that is rolled back.
 *
 * Usage: php tests/_tmp_content_calendar_audit.php
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

$pass = 0;
$fail = 0;

function check($name, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) {
        echo "[PASS] $name\n";
        $pass++;
    } else {
        echo "[FAIL] $name" . ($detail !== '' ? " - $detail" : "") . "\n";
        $fail++;
    }
}

function src($path) {
    return file_get_contents(FCPATH . $path);
}

echo "=== Content Calendar audit ===\n";
echo "db=" . $db->getDatabase() . "\n";

/* ------------------------------------------------------------------ */
echo "\n--- SQL file / docs ---\n";
$sql = src('docs/content_calendar.sql');
$md = src('docs/content_calendar_sql.md');
$tables = array('cc_calendars', 'cc_columns', 'cc_items', 'cc_months', 'cc_special_days', 'cc_targets', 'cc_permissions');

check('sql: 8 CREATE TABLE IF NOT EXISTS (phase 2 adds rise_cc_import_logs)', preg_match_all('/^CREATE TABLE IF NOT EXISTS `rise_cc_/m', $sql) === 8);
check('sql: no ALTER TABLE statement', !preg_match('/^\s*ALTER\s+TABLE/mi', $sql));
check('sql: no active DROP / DELETE / TRUNCATE (rollback only in comments)', !preg_match('/^\s*(DROP|DELETE|TRUNCATE|UPDATE|INSERT)\b/mi', $sql));
check('sql: rollback drops are commented and only rise_cc_ tables', preg_match_all('/^-- DROP TABLE IF EXISTS `rise_cc_/m', $sql) >= 7 && !preg_match('/DROP TABLE[^;]*`rise_(?!cc_)/', $sql));
check('sql: utf8mb4 InnoDB on every table', preg_match_all('/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/', $sql) === 8);
check('sql: unique keys present', strpos($sql, 'UNIQUE KEY `uq_cc_columns_calendar_key` (`calendar_id`, `field_key`)') !== false
        && strpos($sql, 'UNIQUE KEY `uq_cc_months_calendar_ym` (`calendar_id`, `year`, `month`)') !== false
        && strpos($sql, 'UNIQUE KEY `uq_cc_special_days_calendar_date` (`calendar_id`, `special_date`)') !== false
        && strpos($sql, 'UNIQUE KEY `uq_cc_targets_calendar_type` (`calendar_id`, `content_type`)') !== false
        && strpos($sql, 'UNIQUE KEY `uq_cc_permissions_key_user` (`permission_key`, `user_id`)') !== false);
check('sql: item indexes present', strpos($sql, 'KEY `idx_cc_items_calendar_date` (`calendar_id`, `publish_date`)') !== false && strpos($sql, 'KEY `idx_cc_items_calendar_type_status` (`calendar_id`, `content_type`, `status`)') !== false);
check('sql: column index present', strpos($sql, 'KEY `idx_cc_columns_calendar_sort` (`calendar_id`, `sort_order`)') !== false);
check('md: contains the 8 CREATE TABLE statements (+ the import_logs repeat in the upgrade block)', preg_match_all('/^CREATE TABLE IF NOT EXISTS `rise_cc_/m', $md) === 9);
foreach ($tables as $t) {
    $stmt = '';
    if (preg_match('/CREATE TABLE IF NOT EXISTS `rise_' . $t . '` \(.*?\) ENGINE=InnoDB[^;]*;/s', $sql, $m)) {
        $stmt = $m[0];
    }
    check("md has identical statement for rise_$t", $stmt !== '' && strpos($md, $stmt) !== false);
}
foreach (array('what this changes', 'CREATE TABLE IF NOT EXISTS', 'Upgrade for a database', 'Verification queries', 'Rollback (DESTRUCTIVE)', 'safe to run twice') as $heading) {
    check("md section: $heading", stripos($md, $heading) !== false);
}

/* ------------------------------------------------------------------ */
echo "\n--- tables and indexes in the local db ---\n";
foreach ($tables as $t) {
    $exists = $db->query("SHOW TABLES LIKE '" . $db->getPrefix() . $t . "'")->getNumRows() > 0;
    check("table rise_$t exists", $exists);
}

$idx = array();
$rows = $db->query("SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'rise\\_cc\\_%' GROUP BY TABLE_NAME, INDEX_NAME, NON_UNIQUE")->getResult();
foreach ($rows as $r) {
    $idx[$r->TABLE_NAME . '.' . $r->INDEX_NAME] = (int) $r->NON_UNIQUE;
}
foreach (array(
    'rise_cc_columns.uq_cc_columns_calendar_key' => 0,
    'rise_cc_columns.idx_cc_columns_calendar_sort' => 1,
    'rise_cc_items.idx_cc_items_calendar_date' => 1,
    'rise_cc_items.idx_cc_items_calendar_type_status' => 1,
    'rise_cc_months.uq_cc_months_calendar_ym' => 0,
    'rise_cc_special_days.uq_cc_special_days_calendar_date' => 0,
    'rise_cc_targets.uq_cc_targets_calendar_type' => 0,
    'rise_cc_permissions.uq_cc_permissions_key_user' => 0,
) as $name => $non_unique) {
    check("index $name", isset($idx[$name]) && $idx[$name] === $non_unique);
}

$core_cols = $db->query("SHOW COLUMNS FROM rise_cc_items")->getResult();
$names = array();
foreach ($core_cols as $c) {
    $names[] = $c->Field;
}
check('rise_cc_items columns', $names === array('id', 'calendar_id', 'publish_date', 'content_type', 'status', 'title', 'values_json', 'deleted', 'created_by', 'updated_by', 'created_at', 'updated_at', 'import_batch_id'), implode(',', $names));

/* ------------------------------------------------------------------ */
echo "\n--- source assertions ---\n";
$app_controller = src('app/Controllers/App_Controller.php');
check("App_Controller helper() array loads 'content_calendar'", preg_match("/helper\(array\([^;]*'content_calendar'/", $app_controller) === 1);

$left_menu = src('app/Libraries/Left_menu.php');
$events_pos = strpos($left_menu, '$sidebar_menu["events"] = array("name" => "events", "url" => "events", "class" => "calendar");');
$cc_pos = strpos($left_menu, '$sidebar_menu["content_calendar"] = array("name" => "content_calendar", "url" => "content_calendar", "class" => "layout");');
$clients_pos = strpos($left_menu, '$sidebar_menu["clients"]');
check('Left_menu: content_calendar item exists, guarded by cc_can(view)', $cc_pos !== false && strpos($left_menu, "function_exists('cc_can') && cc_can('view')") !== false);
check('Left_menu: content_calendar comes right after the events block (before clients)', $events_pos !== false && $cc_pos > $events_pos && $cc_pos < $clients_pos);

$tabs = src('app/Views/settings/tabs.php');
check('settings tabs: access link for admin', strpos($tabs, 'content_calendar_access') !== false && preg_match('/if \(\$login_user->is_admin\) \{\s*\$settings_menu\["access_permission"\]\[\] = array\("name" => "content_calendar_access"/', $tabs) === 1);

$settings = src('app/Controllers/Settings.php');
check('Settings::content_calendar_access (admin only)', preg_match('/function content_calendar_access\(\) \{\s*\$this->access_only_admin\(\);/', $settings) === 1);
check('Settings::save_content_calendar_access (admin only, replace set)', preg_match('/function save_content_calendar_access\(\) \{\s*\$this->access_only_admin\(\);[\s\S]*replace_users_for_key/', $settings) === 1);
check('settings view exists with 5 permission cards', is_file(FCPATH . 'app/Views/settings/content_calendar_access.php') && substr_count(src('app/Views/settings/content_calendar_access.php'), '"cc_perm_') >= 10);

$helper = src('app/Helpers/content_calendar_helper.php');
foreach (array('cc_can', 'cc_slug', 'cc_chip_colors', 'cc_now') as $fn) {
    check("helper defines $fn()", strpos($helper, "function $fn(") !== false);
}
foreach (array('view', 'add', 'edit', 'delete', 'manage_options', 'admin_manage') as $k) {
    check("helper knows permission key '$k'", strpos($helper, '"' . $k . '"') !== false);
}
check('helper: cc_now uses get_current_utc_time()', preg_match('/function cc_now\(\) \{\s*return get_current_utc_time\(\);/', $helper) === 1);

$ctl = src('app/Controllers/Content_calendar.php');
check('controller extends Security_Controller', strpos($ctl, 'class Content_calendar extends Security_Controller') !== false);

$endpoint_rules = array(
    'index' => '_require_page_can("view")',
    'save_client' => '_require_can("admin_manage")',
    'page_data' => '_require_can("view")',
    'save_special_day' => '_require_can("edit")',
    'add_month' => '_require_can("add")',
    'save_column' => '_require_can("admin_manage")',
    'delete_column' => '_require_can("admin_manage")',
    'reorder_column' => '_require_can("admin_manage")',
    'toggle_column_visible' => '_require_can("admin_manage")',
    'copy_columns' => '_require_can("admin_manage")',
    'save_option' => '_require_can("manage_options")',
    'delete_option' => '_require_can("manage_options")',
    'save_target' => '_require_can("admin_manage")',
    'delete_item' => '_require_can("delete")',
    'delete_calendar' => '_require_can("admin_manage")',
    'upload_file' => '_require_can_any(array("add", "edit"))',
    'save_item' => '_require_can($id ? "edit" : "add")',
    'item_form' => '_require_page_can("edit")',
);
foreach ($endpoint_rules as $method => $rule) {
    $body = '';
    if (preg_match('/function ' . $method . '\([^)]*\) \{([\s\S]*?)\n    \}\n/', $ctl, $m)) {
        $body = $m[1];
    }
    check("endpoint $method() exists and checks permission on the server: $rule", $body !== '' && strpos($body, $rule) !== false);
}

$write_endpoints = array('save_client', 'save_item', 'delete_item', 'upload_file', 'save_special_day', 'add_month', 'save_target', 'delete_calendar', 'save_column', 'delete_column', 'reorder_column', 'toggle_column_visible', 'copy_columns', 'save_option', 'delete_option');
foreach ($write_endpoints as $method) {
    preg_match('/function ' . $method . '\([^)]*\) \{([\s\S]*?)\n    \}\n/', $ctl, $m);
    check("write endpoint $method() requires POST", isset($m[1]) && strpos($m[1], '$this->_require_post();') !== false);
}
check('forbidden AJAX answers 403 JSON', preg_match('/function _forbidden_json\(\) \{[\s\S]*?, 403\)/', $ctl) === 1);
check('forbidden page uses app_redirect(forbidden)', strpos($ctl, 'app_redirect("forbidden")') !== false);
check('controller uses cc_now() for timestamps', substr_count($ctl, 'cc_now()') >= 1 && strpos($ctl, 'date("Y-m-d H:i:s")') === false);
check('item_form view escapes with esc()', substr_count(src('app/Views/content_calendar/item_form.php'), 'esc(') > 20);

//every cc_ language key used in code exists in the English custom_lang.php
$lang = include FCPATH . 'app/Language/english/custom_lang.php';
$used = array();
foreach (array('app/Controllers/Content_calendar.php', 'app/Controllers/Settings.php', 'app/Views/content_calendar/index.php', 'app/Views/content_calendar/item_form.php', 'app/Views/settings/content_calendar_access.php', 'assets/js/content-calendar.js') as $file) {
    preg_match_all('/["\'](cc_[a-z0-9_]+)["\']/', src($file), $m);
    foreach ($m[1] as $key) {
        if ($key === 'cc_config' || $key === 'cc_import_logs' || $key === 'cc_items') { //table names, not language keys
            continue; //a view variable name, not a language key
        }
        $used[$key] = true;
    }
}
//keys used inside variable-based lookups: TYPE_LABELS and cc_perm_* in the access view are written as strings, so they are covered above
$missing = array();
foreach (array_keys($used) as $key) {
    if (!isset($lang[$key])) {
        $missing[] = $key;
    }
}
check('all cc_* language keys used by code exist in english/custom_lang.php (' . count($used) . ' keys)', !count($missing), implode(', ', $missing));

foreach (array('assets/css/content-calendar.css', 'assets/js/content-calendar.js', 'app/Views/content_calendar/index.php', 'app/Views/content_calendar/item_form.php') as $file) {
    check("file exists: $file", is_file(FCPATH . $file));
}
$css = src('assets/css/content-calendar.css');
check('css theme colours (#faf6f0, #e7e0d5, #b45309)', strpos($css, '#faf6f0') !== false && strpos($css, '#e7e0d5') !== false && strpos($css, '#b45309') !== false);
check('index view includes css and js', strpos(src('app/Views/content_calendar/index.php'), 'assets/css/content-calendar.css') !== false && strpos(src('app/Views/content_calendar/index.php'), 'assets/js/content-calendar.js') !== false);

/* ------------------------------------------------------------------ */
echo "\n--- helper functions ---\n";
check("cc_slug('Goal') == 'goal'", cc_slug('Goal') === 'goal');
check("cc_slug('Topic / Title') == 'topic_title'", cc_slug('Topic / Title') === 'topic_title');
check("cc_slug('  AI feed Topic ') == 'ai_feed_topic'", cc_slug('  AI feed Topic ') === 'ai_feed_topic');
check("cc_slug('???') falls back to 'field'", cc_slug('???') === 'field');
check('cc_chip_colors Design = indigo', cc_chip_colors('Design') === array('#e0e7ff', '#3730a3'));
check('cc_chip_colors Reel = rose', cc_chip_colors('reel') === array('#ffe4e6', '#9f1239'));
check('cc_chip_colors Story = emerald', cc_chip_colors('Story') === array('#d1fae5', '#065f46'));
check('cc_chip_colors Carousel = amber', cc_chip_colors('Carousel') === array('#fef3c7', '#92400e'));
check('cc_chip_colors Planned = grey', cc_chip_colors('Planned') === array('#f3f4f6', '#374151'));
check('cc_chip_colors In design = indigo', cc_chip_colors('In design') === array('#e0e7ff', '#3730a3'));
check('cc_chip_colors Scheduled = amber', cc_chip_colors('Scheduled') === array('#fef3c7', '#92400e'));
check('cc_chip_colors Posted = emerald', cc_chip_colors('Posted') === array('#d1fae5', '#065f46'));
check('cc_chip_colors unknown name is stable and from the palette', cc_chip_colors('Webinar') === cc_chip_colors('webinar') && in_array(cc_chip_colors('Webinar'), array_values(cc_chip_palette()), true));
check('cc_now() format', (bool) preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', cc_now()));

/* ------------------------------------------------------------------ */
echo "\n--- cc_can() logic + models (inside a transaction, rolled back) ---\n";
$db->transBegin();

$permissions = model("App\Models\Cc_permissions_model");
$calendars = model("App\Models\Cc_calendars_model");
$columns = model("App\Models\Cc_columns_model");
$items = model("App\Models\Cc_items_model");
$months = model("App\Models\Cc_months_model");
$special = model("App\Models\Cc_special_days_model");
$targets = model("App\Models\Cc_targets_model");

function mock_user($id, $is_admin = 0, $type = 'staff', $deleted = 0, $status = 'active') {
    return (object) array('id' => $id, 'is_admin' => $is_admin, 'user_type' => $type, 'deleted' => $deleted, 'status' => $status);
}

$u_none = mock_user(990001);
$u_view = mock_user(990002);
$u_add = mock_user(990003);
$u_edit = mock_user(990004);
$u_del = mock_user(990005);
$u_opt = mock_user(990006);
$u_admin = mock_user(990007, 1);
$u_client = mock_user(990008, 0, 'client');
$u_gone = mock_user(990009, 0, 'staff', 1);
$u_inactive = mock_user(990010, 0, 'staff', 0, 'inactive');

check('replace_users_for_key(view) returns true', $permissions->replace_users_for_key('view', array(990002), 1) === true);
$permissions->replace_users_for_key('add', array(990003, 990003, '990003'), 1);
$permissions->replace_users_for_key('edit', array(990004), 1);
$permissions->replace_users_for_key('delete', array(990005), 1);
$permissions->replace_users_for_key('manage_options', array(990006), 1);
$permissions->replace_users_for_key('view', array(990002, 990010, 990009, 990008), 1);
check('replace_users_for_key dedupes ids', count($permissions->get_user_ids('add')) === 1);
check('replace_users_for_key rejects an unknown key', $permissions->replace_users_for_key('hack', array(1), 1) === false);
check('user_has() true / false', $permissions->user_has('edit', 990004) && !$permissions->user_has('edit', 990003));

$all_keys = array('view', 'add', 'edit', 'delete', 'manage_options', 'admin_manage');
$expect = function ($user, $allowed) use ($all_keys) {
    $got = array();
    foreach ($all_keys as $k) {
        if (cc_can($k, $user)) {
            $got[] = $k;
        }
    }
    return $got === $allowed ? true : implode(',', $got);
};

$r = $expect($u_none, array());
check('user with nothing: no access at all', $r === true, (string) $r);
$r = $expect($u_view, array('view'));
check('view only: view', $r === true, (string) $r);
$r = $expect($u_add, array('view', 'add'));
check('add: add + implied view', $r === true, (string) $r);
$r = $expect($u_edit, array('view', 'edit'));
check('edit: edit + implied view', $r === true, (string) $r);
$r = $expect($u_del, array('view', 'delete'));
check('delete: delete + implied view', $r === true, (string) $r);
$r = $expect($u_opt, array('view', 'manage_options'));
check('manage_options: manage_options + implied view', $r === true, (string) $r);
$r = $expect($u_admin, $all_keys);
check('admin: everything, including admin_manage, without being in a list', $r === true, (string) $r);
$r = $expect($u_client, array());
check('client user type: nothing, even when listed', $r === true, (string) $r);
$r = $expect($u_gone, array());
check('deleted user: nothing', $r === true, (string) $r);
$r = $expect($u_inactive, array());
check('inactive user: nothing', $r === true, (string) $r);
check('unknown key: false', cc_can('anything', $u_admin) === false);
check('admin_manage is never given by a list', !cc_can('admin_manage', $u_opt));

//removing permissions takes effect for a new user id (cache is per request, per user)
$permissions->replace_users_for_key('view', array(), 1);
check('replace with empty set clears the key', count($permissions->get_user_ids('view')) === 0);

//---- calendars + columns ----
$cal_id = $calendars->create_calendar('ZZ Audit Client', 0, 1);
check('create_calendar returns id', $cal_id > 0);
check('name_exists is case-insensitive', $calendars->name_exists('zz audit CLIENT') && !$calendars->name_exists('zz audit client', $cal_id));
$columns->seed_core($cal_id);
$cols = $columns->get_for_calendar($cal_id);
check('seed_core creates 4 core columns in order date,type,title,status', array_map(function ($c) {
            return $c->field_key;
        }, $cols) === array('date', 'type', 'title', 'status'));
$type_col = $columns->get_by_key($cal_id, 'type');
$status_col = $columns->get_by_key($cal_id, 'status');
check('default Content Type options', $type_col->options_list === array('Design', 'Reel', 'Story', 'Carousel'));
check('default Status options', $status_col->options_list === array('Planned', 'In design', 'Scheduled', 'Posted'));

$goal_id = $columns->add_column($cal_id, 'Goal', 'select', array('Awareness', 'Leads'));
$goal = $columns->get_active($goal_id);
check('custom column Goal gets field_key goal', $goal->field_key === 'goal' && !$goal->is_core);
$notes_id = $columns->add_column($cal_id, 'Notes', 'long');
$tags_id = $columns->add_column($cal_id, 'Tags', 'multi', array('a', 'b'));
check('label_exists (case-insensitive)', $columns->label_exists($cal_id, 'goal') && !$columns->label_exists($cal_id, 'goal', $goal_id));
check('make_unique_key never repeats a key', $columns->make_unique_key($cal_id, 'Title') === 'title_2');

$columns->soft_delete($notes_id);
check('soft deleted column is not listed', count($columns->get_for_calendar($cal_id)) === 6);
$re_id = $columns->add_column($cal_id, 'Notes', 'long');
check('re-adding a removed column brings it back (same id, same key)', $re_id === $notes_id && $columns->get_active($re_id)->field_key === 'notes');

check('move() earlier', $columns->move($cal_id, $goal_id, 'up') === true);
$order = array_map(function ($c) {
    return $c->field_key;
}, $columns->get_for_calendar($cal_id));
check('move() changed the order', array_search('goal', $order) === 3, implode(',', $order));
check('move() first column earlier is refused', $columns->move($cal_id, $columns->get_by_key($cal_id, 'date')->id, 'up') === false);
$columns->set_order($cal_id, array_reverse(array_map(function ($c) {
            return (int) $c->id;
        }, $columns->get_for_calendar($cal_id))));
check('set_order reverses', $columns->get_for_calendar($cal_id)[0]->field_key !== 'date');

//copy columns to another calendar
$cal2 = $calendars->create_calendar('ZZ Audit Client Two', 0, 1);
$columns->copy_all($cal_id, $cal2);
check('copy_all copies all columns incl. options (not items)', count($columns->get_for_calendar($cal2)) === 7 && $columns->get_by_key($cal2, 'goal')->options_list === array('Awareness', 'Leads'));
$cal3 = $calendars->create_calendar('ZZ Audit Client Three', 0, 1);
$columns->seed_core($cal3);
$columns->add_column($cal3, 'notes', 'text');
check('copy_custom adds only missing custom columns (matched by name)', $columns->copy_custom($cal_id, $cal3) === 2 && count($columns->get_for_calendar($cal3)) === 7);
check('copy_custom is repeatable (adds nothing the second time)', $columns->copy_custom($cal_id, $cal3) === 0);

//---- months ----
$months->seed_default($cal_id);
$m = $months->get_for_calendar($cal_id);
check('seed_default: previous + current month', count($m) === 2 && (int) $m[1]->year === (int) date('Y') && (int) $m[1]->month === (int) date('n'));
check('ensure() is quiet on duplicates', $months->ensure($cal_id, $m[1]->year, $m[1]->month) === false && count($months->get_for_calendar($cal_id)) === 2);
$next = $months->add_next($cal_id);
$expected_next = strtotime(date('Y-m-01') . ' +1 month');
check('add_next appends the month after the last', $next->added && $next->year === (int) date('Y', $expected_next) && $next->month === (int) date('n', $expected_next));
$next2 = $months->add_next($cal_id);
$expected_next2 = strtotime(date('Y-m-01') . ' +2 month');
check('add_next again (Nov -> Dec -> Jan)', $next2->year === (int) date('Y', $expected_next2) && $next2->month === (int) date('n', $expected_next2));
$months->ensure_for_date($cal_id, '2031-05-17');
check('ensure_for_date adds a missing month', $months->exists($cal_id, 2031, 5));

//---- items ----
$now = cc_now();
$base = array('calendar_id' => $cal_id, 'deleted' => 0, 'created_by' => 1, 'updated_by' => 1, 'created_at' => $now, 'updated_at' => $now);
$i1 = $items->save_item($base + array('publish_date' => '2031-05-17', 'content_type' => 'Reel', 'status' => 'Planned', 'title' => 'Emoji title 🎉', 'values_json' => $items->encode_values(array('goal' => 'Leads', 'tags' => array('a', 'b')))));
$i2 = $items->save_item($base + array('publish_date' => '2031-05-17', 'content_type' => 'Design', 'status' => 'Posted', 'title' => 'Second', 'values_json' => $items->encode_values(array())));
$i3 = $items->save_item($base + array('publish_date' => '2031-06-01', 'content_type' => 'Reel', 'status' => 'Planned', 'title' => 'June', 'values_json' => '{}'));
$month_items = $items->get_details(array('calendar_id' => $cal_id, 'year' => 2031, 'month' => 5));
check('get_details is scoped to one calendar and one month', count($month_items) === 2 && $month_items[0]->values['goal'] === 'Leads');
check('emoji survives (utf8mb4 connection)', strpos($month_items[0]->title, '🎉') !== false);
check('get_details with another calendar returns nothing', count($items->get_details(array('calendar_id' => $cal2, 'year' => 2031, 'month' => 5))) === 0);
$counts = $items->get_month_counts($cal_id);
check('get_month_counts', $counts['2031-05'] === 2 && $counts['2031-06'] === 1);
$tc = $items->get_type_counts($cal_id, 2031, 5);
check('get_type_counts', $tc['Reel'] === 1 && $tc['Design'] === 1);
check('count_option_usage: core type', $items->count_option_usage($cal_id, 'type', 'Reel') === 2 && $items->count_option_usage($cal_id, 'type', 'Story') === 0);
check('count_option_usage: core status', $items->count_option_usage($cal_id, 'status', 'Posted') === 1);
check('count_option_usage: custom select in values_json', $items->count_option_usage($cal_id, 'goal', 'Leads') === 1 && $items->count_option_usage($cal_id, 'goal', 'Awareness') === 0);
check('count_option_usage: custom multi in values_json', $items->count_option_usage($cal_id, 'tags', 'a') === 1 && $items->count_option_usage($cal_id, 'tags', 'c') === 0);
$items->soft_delete_item($i2, 1);
check('soft deleted item is not counted / listed', $items->count_option_usage($cal_id, 'status', 'Posted') === 0 && count($items->get_details(array('calendar_id' => $cal_id, 'year' => 2031, 'month' => 5))) === 1 && $items->get_active($i2) === null);

//---- special days / targets ----
check('special day save + update', $special->save_label($cal_id, '2031-05-17', 'Launch') === 'Launch' && $special->save_label($cal_id, '2031-05-17', 'Launch day') === 'Launch day');
check('special day is unique per calendar and date', count($special->get_details(array('calendar_id' => $cal_id, 'special_date' => '2031-05-17'))) === 1);
$sd = $special->get_for_month($cal_id, 2031, 5);
check('special days of the month', $sd['2031-05-17'] === 'Launch day');
check('empty special day label deletes the row', $special->save_label($cal_id, '2031-05-17', '  ') === '' && count($special->get_for_month($cal_id, 2031, 5)) === 0);
check('target save / update / remove', $targets->save_target($cal_id, 'Reel', 12) === 12 && $targets->save_target($cal_id, 'Reel', 8) === 8 && $targets->get_for_calendar($cal_id)['Reel'] === 8 && $targets->save_target($cal_id, 'Reel', 0) === 0 && count($targets->get_for_calendar($cal_id)) === 0);

//---- calendar soft delete ----
$calendars->soft_delete($cal2);
check('soft deleted calendar is hidden', $calendars->get_active($cal2) === null && !$calendars->name_exists('ZZ Audit Client Two'));

$db->transRollback();
$left = $db->query("SELECT COUNT(*) AS n FROM rise_cc_calendars WHERE name LIKE 'ZZ Audit%'")->getRow()->n;
check('transaction rolled back, no test rows left', (int) $left === 0);

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
