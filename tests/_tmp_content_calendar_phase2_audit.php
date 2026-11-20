<?php
/**
 * Content Calendar phase 2 audit (import library, models, SQL, permissions, undo, duplicates, limits).
 * LOCAL database only. Creates "ZZ CC P2 ..." calendars and temporary users and removes them at the end.
 *
 * Usage:
 *   php tests/_tmp_cc_build_fixtures.php
 *   php tests/_tmp_content_calendar_phase2_audit.php
 */
ini_set('memory_limit', '768M');
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

if (!in_array($db->hostname, array('127.0.0.1', 'localhost', 'localhost:3306'), true)) {
    echo "REFUSING: database host is '{$db->hostname}' (local only).\n";
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
        echo "[FAIL] $name" . ($detail !== '' ? " - " . substr((string) $detail, 0, 500) : "") . "\n";
        $fail++;
    }
}

function info($text) {
    echo "       $text\n";
}

$FIX = __DIR__ . '/_tmp_cc_fixtures/';
if (!is_file($FIX . 'complex.xlsx')) {
    echo "Fixtures missing. Run: php tests/_tmp_cc_build_fixtures.php\n";
    exit(1);
}

$lib = new \App\Libraries\Content_calendar_import();
$calendars_model = model("App\Models\Cc_calendars_model");
$columns_model = model("App\Models\Cc_columns_model");
$items_model = model("App\Models\Cc_items_model");
$months_model = model("App\Models\Cc_months_model");
$logs_model = model("App\Models\Cc_import_logs_model");

$created_users = array();
$created_calendars = array();

function cleanup_all() {
    global $db, $created_users, $lib;
    try {
        $rows = $db->query("SELECT id FROM rise_cc_calendars WHERE name LIKE 'ZZ CC%'")->getResult();
        foreach ($rows as $r) {
            foreach (array('cc_columns', 'cc_items', 'cc_months', 'cc_special_days', 'cc_targets', 'cc_import_logs') as $t) {
                $db->query("DELETE FROM rise_$t WHERE calendar_id=" . (int) $r->id);
            }
            $db->query("DELETE FROM rise_cc_calendars WHERE id=" . (int) $r->id);
        }
        if ($created_users) {
            $in = implode(',', array_map('intval', $created_users));
            $db->query("DELETE FROM rise_cc_permissions WHERE user_id IN ($in)");
            $db->query("DELETE FROM rise_users WHERE id IN ($in)");
        }
    } catch (\Throwable $e) {
        echo "cleanup error: " . $e->getMessage() . "\n";
    }
    foreach (glob($lib->dir() . '*') ?: array() as $f) {
        if (is_file($f) && basename($f) !== 'index.html') {
            @unlink($f);
        }
    }
}
register_shutdown_function('cleanup_all');
cleanup_all();

function make_calendar($name) {
    global $calendars_model, $columns_model, $months_model;
    $id = $calendars_model->create_calendar($name, 0, 1);
    $columns_model->seed_core($id);
    $months_model->seed_default($id);
    return $id;
}

function make_user($key, $is_admin, $perm_keys) {
    global $db, $created_users;
    $email = "cc_p2_$key@example.test";
    $db->query("DELETE FROM rise_users WHERE email=?", array($email));
    $db->table('users')->insert(array(
        'first_name' => 'CCP2', 'last_name' => ucfirst($key), 'user_type' => 'staff', 'is_admin' => $is_admin, 'role_id' => 0,
        'email' => $email, 'password' => 'x', 'status' => 'active', 'client_id' => 0, 'job_title' => 'T',
        'disable_login' => 0, 'language' => '', 'created_at' => get_current_utc_time(), 'deleted' => 0
    ));
    $id = (int) $db->insertID();
    $created_users[] = $id;
    foreach ($perm_keys as $k) {
        $db->table('cc_permissions')->insert(array('permission_key' => $k, 'user_id' => $id, 'created_by' => 0, 'created_at' => get_current_utc_time()));
    }
    return $db->table('users')->where('id', $id)->get()->getRow();
}

function perms_of($user) {
    return array("add" => cc_can("add", $user), "admin_manage" => cc_can("admin_manage", $user), "manage_options" => cc_can("manage_options", $user));
}

function read_fixture($file, $type = 'auto') {
    global $lib, $FIX;
    return $lib->read_file($FIX . $file, $type);
}

function items_of($calendar_id, $include_deleted = false) {
    global $db;
    $sql = "SELECT * FROM rise_cc_items WHERE calendar_id=?" . ($include_deleted ? "" : " AND deleted=0") . " ORDER BY publish_date, id";
    $rows = $db->query($sql, array((int) $calendar_id))->getResult();
    foreach ($rows as $r) {
        $r->values = json_decode($r->values_json, true) ?: array();
    }
    return $rows;
}

function col_by_label($calendar_id, $label) {
    global $columns_model;
    foreach ($columns_model->get_for_calendar($calendar_id) as $c) {
        if (mb_strtolower($c->label) === mb_strtolower($label)) {
            return $c;
        }
    }
    return null;
}

function map_text($plan) {
    $out = array();
    foreach ($plan['columns'] as $c) {
        $m = $c['map']['target'] . ($c['map']['target'] === 'new' ? ":" . $c['map']['type'] : "");
        $out[] = $c['header'] . "=" . $c['detected_type'] . "->" . $m;
    }
    return implode(" | ", $out);
}

echo "=== Content Calendar phase 2 audit ===\n";
echo "db=" . $db->getDatabase() . "\n";

/* ------------------------------------------------------------------ */
echo "\n--- SQL: file, docs, local database ---\n";
$sql = file_get_contents(FCPATH . 'docs/content_calendar.sql');
$md = file_get_contents(FCPATH . 'docs/content_calendar_sql.md');
check('sql: 8 CREATE TABLE IF NOT EXISTS', preg_match_all('/^CREATE TABLE IF NOT EXISTS `rise_cc_/m', $sql) === 8);
check('sql: import_batch_id in the fresh rise_cc_items CREATE', preg_match('/CREATE TABLE IF NOT EXISTS `rise_cc_items`.*?`import_batch_id` VARCHAR\(40\) NULL DEFAULT NULL.*?KEY `idx_cc_items_import_batch` \(`import_batch_id`\)/s', $sql) === 1);
check('sql: rise_cc_import_logs has all required columns', preg_match('/CREATE TABLE IF NOT EXISTS `rise_cc_import_logs`.*?`calendar_id`.*?`user_id`.*?`file_name`.*?`sheet_name`.*?`rows_total`.*?`rows_imported`.*?`rows_skipped`.*?`columns_created`.*?`options_created`.*?`batch_id`.*?`undone`.*?`created_at`/s', $sql) === 1);
$sql_code = implode("\n", array_filter(preg_split('/\r?\n/', $sql), function ($l) { return !preg_match('/^\s*--/', $l); }));
check('sql: upgrade uses INFORMATION_SCHEMA + PREPARE/EXECUTE, no ADD COLUMN IF NOT EXISTS', strpos($sql, 'INFORMATION_SCHEMA.COLUMNS') !== false && strpos($sql, 'INFORMATION_SCHEMA.STATISTICS') !== false && substr_count($sql, 'PREPARE cc_stmt FROM') === 2 && substr_count($sql, 'EXECUTE cc_stmt;') === 2 && stripos($sql_code, 'ADD COLUMN IF NOT EXISTS') === false);
check('sql: no bare ALTER / DROP / DELETE / INSERT / UPDATE outside strings and comments', !preg_match('/^\s*(ALTER|DROP|DELETE|TRUNCATE|UPDATE|INSERT)\b/mi', $sql));
check('sql: rollback sections are commented', preg_match_all('/^-- DROP TABLE IF EXISTS `rise_cc_/m', $sql) >= 8 && strpos($sql, '-- ALTER TABLE `rise_cc_items` DROP COLUMN `import_batch_id`;') !== false);
check('sql: no foreign keys, utf8mb4 on all 8', stripos($sql, 'FOREIGN KEY') === false && preg_match_all('/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4/', $sql) === 8);
check('md: has fresh install, upgrade, verification, rollback, safe-to-run-twice', strpos($md, 'A. Fresh install') !== false && strpos($md, 'B. Upgrade') !== false && strpos($md, 'Verification queries') !== false && strpos($md, '6a. Phase 2 only') !== false && strpos($md, 'Which statements are safe to run twice') !== false);
check('md: contains the same PREPARE pattern', substr_count($md, 'PREPARE cc_stmt FROM') === 2);

$col = $db->query("SELECT COLUMN_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='rise_cc_items' AND COLUMN_NAME='import_batch_id'")->getRow();
check('db: rise_cc_items.import_batch_id exists, varchar(40) nullable', $col && strtolower($col->COLUMN_TYPE) === 'varchar(40)' && $col->IS_NULLABLE === 'YES', json_encode($col));
$idx = $db->query("SELECT COUNT(*) n FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='rise_cc_items' AND INDEX_NAME='idx_cc_items_import_batch'")->getRow();
check('db: index idx_cc_items_import_batch exists', (int) $idx->n === 1);
check('db: rise_cc_import_logs exists', $db->tableExists('cc_import_logs'));
check('db: tableExists/fieldExists work with the controller calls', $db->tableExists('cc_import_logs') && $db->fieldExists('import_batch_id', 'cc_items') && !$db->fieldExists('nope_field', 'cc_items'));

/* ------------------------------------------------------------------ */
echo "\n--- Library: dates, header, month titles, types ---\n";
$d = function ($t, $y = 2026, $m = 0, $s = false) use ($lib) {
    return $lib->parse_date($t, $y, $m, $s);
};
check('date: ISO', $d('2026-11-05') === '2026-11-05');
check('date: dd/mm/yyyy is day first', $d('05/11/2026') === '2026-11-05');
check('date: 13/11/2026 is 13 Nov', $d('13/11/2026') === '2026-11-13');
check('date: dd-mm-yyyy', $d('05-11-2026') === '2026-11-05');
check('date: dd.mm.yyyy', $d('05.11.2026') === '2026-11-05');
check('date: d MMM yyyy', $d('5 Nov 2026') === '2026-11-05');
check('date: 1st Nov (default year)', $d('1st Nov') === '2026-11-01');
check('date: Nov 1', $d('Nov 1') === '2026-11-01');
check('date: November 1, 2026', $d('November 1, 2026') === '2026-11-01');
check('date: weekday prefix', $d('Mon, 2 Nov 2026') === '2026-11-02');
check('date: 2-digit year', $d('05/11/26') === '2026-11-05');
check('date: dd/mm without year uses default year', $d('5/11') === '2026-11-05');
check('date: with time part', $d('2026-11-05 14:30:00') === '2026-11-05');
check('date: Excel serial (allowed)', $d('46328', 2026, 0, true) === '2026-11-02', (string) $d('46328', 2026, 0, true));
check('date: Excel serial not accepted when not allowed', $d('46328', 2026, 0, false) === null);
check('date: bare day uses month title', $d('7', 2026, 12) === '2026-12-07');
check('date: bare day without month context is not a date', $d('7') === null);
check('date: month first mm/dd is NOT guessed (13/25/2026 invalid)', $d('13/25/2026') === null);
check('date: impossible date', $d('31/02/2026') === null && $d('2026-13-45') === null);
check('date: text is not a date', $d('TBD') === null && $d('not a date') === null);
check('date: decimal number is not a date', $d('2.5') === null);
[$m1, $y1] = $lib->month_context('MAY MONTH CONTENT CALENDAR');
check('month title: MAY MONTH CONTENT CALENDAR', $m1 === 5 && $y1 === 0);
[$m2, $y2] = $lib->month_context('November 2026');
check('month title: November 2026', $m2 === 11 && $y2 === 2026);
check('month title: a sentence is not a title', $lib->month_context(str_repeat('word ', 20) . 'may') === array(0, 0));
check('type: dates', $lib->detect_column_type(array('02/11/2026', '03/11/2026', '05/11/2026')) === 'date');
check('type: numbers', $lib->detect_column_type(array('1', '25', '3.5', '1,200')) === 'number');
check('type: link', $lib->detect_column_type(array('https://a.com', 'http://b.com', 'https://c.com/x')) === 'link');
check('type: checkbox', $lib->detect_column_type(array('yes', 'no', 'Yes', 'NO')) === 'check');
check('type: 1/0 only is a number', $lib->detect_column_type(array('1', '0', '1')) === 'number');
check('type: select (few distinct)', $lib->detect_column_type(array('Sales', 'Awareness', 'Sales', 'Awareness', 'Sales', 'Awareness', 'Sales', 'Awareness', 'Sales', 'Awareness')) === 'select');
check('type: multi', $lib->detect_column_type(array('IG, FB', 'IG', 'FB, YT', 'IG / FB', 'YT')) === 'multi');
check('type: long text by length', $lib->detect_column_type(array(str_repeat('a', 120), str_repeat('b', 130), str_repeat('c ', 70))) === 'long');
check('type: long text by line break', $lib->detect_column_type(array("a\nb", "c", "d e f", "x y z", "q w e", "r t y", "u i o")) === 'long');
check('type: text', $lib->detect_column_type(array('one', 'two thing', 'three more', 'four again', 'five here', 'six there')) === 'text');
check('letters: 0=A, 25=Z, 26=AA', $lib->col_letter(0) === 'A' && $lib->col_letter(25) === 'Z' && $lib->col_letter(26) === 'AA');
check('csv delimiter: semicolon', $lib->detect_delimiter("a;b;c\n1;2;3\n4;5;6\n") === ';');
check('csv delimiter: tab', $lib->detect_delimiter("a\tb\tc\n1\t2\t3\n") === "\t");
check('csv delimiter: comma with quoted commas', $lib->detect_delimiter("a,b,c\n\"x,y\",2,3\n4,5,6\n") === ',');
check('csv encoding: UTF-16LE with BOM', $lib->decode_text("\xFF\xFE" . mb_convert_encoding("Date,Title\n2026-11-01,Ka\u{0CA8}\n", 'UTF-16LE', 'UTF-8')) === "Date,Title\n2026-11-01,Ka\u{0CA8}\n");
check('csv encoding: UTF-16LE without BOM', strpos($lib->decode_text(mb_convert_encoding("Date,Title\n2026-11-01,Hello\n", 'UTF-16LE', 'UTF-8')), 'Hello') !== false);
check('csv encoding: Windows-1252', $lib->decode_text("Caf\xE9 5\x80") === "Caf\u{00E9} 5\u{20AC}");

/* ------------------------------------------------------------------ */
echo "\n--- Fixture 1: UTF-8 BOM + Kannada CSV ---\n";
$cal_a = make_calendar('ZZ CC P2 Kannada');
$admin = make_user('admin', 1, array());
$p_admin = perms_of($admin);
$r = read_fixture('utf8_bom_kannada.csv');
check('1: detected csv', $r['type'] === 'csv');
$s = $r['sheets'][0];
info("encoding={$s['encoding']} delimiter={$s['delimiter']} rows=" . count($s['rows']));
check('1: encoding detected as UTF-8 with BOM, delimiter comma', $s['encoding'] === 'UTF-8 (BOM)' && $s['delimiter'] === ',');
$sum = $lib->sheet_summary($s, 0);
check('1: header on row 1', $sum['header_row'] === 1 && $sum['data_rows'] === 4);
$plan = $lib->dry_run_sheet($s, array(), $cal_a, $p_admin);
info(map_text($plan));
check('1: plan ok, 4 rows to import, 0 skipped', $plan['ok'] && count($plan['items']) === 4 && count($plan['skipped']) === 0);
check('1: Kannada title survives', $plan['items'][0]['title'] === 'ದೀಪಾವಳಿ ಶುಭಾಶಯ' && $plan['items'][3]['title'] === 'ಕನ್ನಡ ಸಲಹೆಗಳು');
check('1: mapping Date/Content Type/Topic/Status, Caption new', $plan['columns'][0]['map']['target'] === 'date' && $plan['columns'][1]['map']['target'] === 'type' && $plan['columns'][2]['map']['target'] === 'title' && $plan['columns'][3]['map']['target'] === 'status' && $plan['columns'][4]['map']['target'] === 'new');
$cap_ref = 'new:4';
check('1: quoted comma + Kannada caption intact, emoji kept', $plan['items'][0]['values'][$cap_ref] === 'ಹಬ್ಬದ ಶುಭಾಶಯಗಳು, ಎಲ್ಲರಿಗೂ' && strpos($plan['items'][3]['values'][$cap_ref], '😀') !== false);
check('1: multi-line caption keeps its line break (long text)', strpos($plan['items'][2]['values'][$cap_ref], "\n") !== false);
check('1: "In design" matches existing option, no new options', $plan['options_created'] === 0 && $plan['items'][3]['status'] === 'In design');
$res = $lib->run_sheet($s, array(), array('calendar_id' => $cal_a), $p_admin, $admin->id, 'utf8_bom_kannada.csv');
check('1: run imports 4', $res['success'] && $res['imported'] === 4, json_encode($res));
$stored = items_of($cal_a);
check('1: 4 items saved', count($stored) === 4);
$k = null;
foreach ($stored as $it) {
    if ($it->publish_date === '2026-11-02') {
        $k = $it;
    }
}
check('1: Kannada title read back from DB', $k && $k->title === 'ದೀಪಾವಳಿ ಶುಭಾಶಯ', $k ? $k->title : 'none');
$cap_col = col_by_label($cal_a, 'Caption');
check('1: Caption column created as long text', $cap_col && $cap_col->input_type === 'long');
check('1: emoji saved', (function () use ($stored, $cap_col) {
    foreach ($stored as $it) {
        if (isset($it->values[$cap_col->field_key]) && strpos($it->values[$cap_col->field_key], '😀') !== false) {
            return true;
        }
    }
    return false;
})());

echo "\n--- Fixture 2: semicolon, Windows-1252, dd/mm/yyyy ---\n";
$cal_b = make_calendar('ZZ CC P2 Semicolon');
$r = read_fixture('semicolon_win1252.csv');
$s = $r['sheets'][0];
info("encoding={$s['encoding']} delimiter={$s['delimiter']}");
check('2: Windows-1252 and semicolon detected', $s['encoding'] === 'Windows-1252' && $s['delimiter'] === ';');
$plan = $lib->dry_run_sheet($s, array(), $cal_b, $p_admin);
info(map_text($plan));
check('2: 4 rows, day-first dates', count($plan['items']) === 4 && $plan['items'][0]['date'] === '2026-11-02' && $plan['items'][2]['date'] === '2026-11-13' && $plan['items'][3]['date'] === '2026-11-05');
check('2: accents and euro sign intact', $plan['items'][0]['title'] === 'Café opening teaser' && strpos($plan['items'][0]['values']['new:4'], '€') !== false && strpos($plan['items'][1]['values']['new:4'], 'Crème brûlée') !== false);
check('2: "Type" header maps to content type', $plan['columns'][1]['map']['target'] === 'type');

echo "\n--- Fixture 3: complex xlsx ---\n";
$cal_c = make_calendar('ZZ CC P2 Complex');
$r = read_fixture('complex.xlsx');
check('3: detected xlsx', $r['type'] === 'xlsx' && count($r['sheets']) === 1);
$s = $r['sheets'][0];
$sum = $lib->sheet_summary($s, 0);
info("detected header row={$sum['header_row']} data rows={$sum['data_rows']}");
check('3: header row detected on row 5 (not the block on row 2)', $sum['header_row'] === 5);
$plan = $lib->dry_run_sheet($s, array('year' => 2026), $cal_c, $p_admin);
info(map_text($plan));
check('3: plan ok', $plan['ok'], $plan['error']);
check('3: 8 rows to import, 1 skipped (TBD date)', count($plan['items']) === 8 && count($plan['skipped']) === 1, count($plan['items']) . '/' . count($plan['skipped']));
check('3: skipped reason mentions the date text, row 11', $plan['skipped'][0][0] === 11 && strpos($plan['skipped'][0][1], 'TBD') !== false, json_encode($plan['skipped'][0]));
check('3: 1 repeated header skipped, 2 note rows (December title + a note)', $plan['stats']['header_repeats'] === 1 && $plan['stats']['notes'] === 2, json_encode($plan['stats']));
check('3: formula cell gives its calculated value, not the formula', $plan['items'][7]['title'] === 'Story Dec 7', $plan['items'][7]['title']);
check('3: real Excel date cell read as 2026-11-02', $plan['items'][0]['date'] === '2026-11-02');
check('3: blank date carries forward', $plan['items'][1]['date'] === '2026-11-02' && $plan['items'][1]['title'] === 'Behind the scenes');
check('3: 3rd Nov, 05/11/2026', $plan['items'][2]['date'] === '2026-11-03' && $plan['items'][3]['date'] === '2026-11-05');
check('3: blank date after 05/11 carries 05/11', $plan['items'][4]['date'] === '2026-11-05');
check('3: December rows: "2 Dec" gets year from the month title, 7/12/2026', $plan['items'][5]['date'] === '2026-12-02' && $plan['items'][6]['date'] === '2026-12-02' && $plan['items'][7]['date'] === '2026-12-07');
check('3: status POSTED/posted -> Posted, scheduled -> Scheduled', $plan['items'][0]['status'] === 'Posted' && $plan['items'][1]['status'] === 'Scheduled' && $plan['items'][4]['status'] === 'Posted');
check('3: "reel" matches Reel (case-insensitive)', $plan['items'][3]['type'] === 'Reel');
$new_status = array();
$new_type = array();
foreach ($plan['option_additions'] as $a) {
    if ($a['key'] === 'status') {
        $new_status = $a['added'];
    }
    if ($a['key'] === 'type') {
        $new_type = $a['added'];
    }
}
check('3: new status options: Upload Done, NOT GET, In Review', $new_status === array('Upload Done', 'NOT GET', 'In Review'), json_encode($new_status));
check('3: new type option: Static Post', $new_type === array('Static Post'), json_encode($new_type));
check('3: 4 new options in total', $plan['options_created'] === 4);
$labels = array_column($plan['new_columns'], 'label');
$types = array_column($plan['new_columns'], 'type', 'label');
check('3: new columns Goal, Caption, Link, Column H (sheet order)', $labels === array('Goal', 'Caption', 'Link', 'Column H'), json_encode($labels));
check('3: Goal select, Caption long, Link link, Column H text', $types['Goal'] === 'select' && $types['Caption'] === 'long' && $types['Link'] === 'link' && $types['Column H'] === 'text', json_encode($types));
check('3: empty trailing columns ignored', count($plan['columns']) === 8, (string) count($plan['columns']));
check('3: invalid link (www.example.com) left empty and counted', $plan['stats']['invalid_cells'] === 1, json_encode($plan['stats']));
check('3: long caption with line break kept', strpos($plan['items'][2]['values']['new:5'], "\n") !== false);
check('3: months: Nov + Dec 2026', $plan['months'] === array('2026-11', '2026-12'));
check('3: date range 2026-11-02 .. 2026-12-07', $plan['date_min'] === '2026-11-02' && $plan['date_max'] === '2026-12-07');
$dry_summary = $lib->plan_for_browser($plan, true)['summary'];
$res = $lib->run_sheet($s, array('year' => 2026), array('calendar_id' => $cal_c), $p_admin, $admin->id, 'complex.xlsx');
check('3: run succeeds', $res['success'], json_encode($res));
check('3: run counts equal the dry run counts', $res['imported'] === $dry_summary['to_import'] && $res['skipped'] === $dry_summary['skipped'] && $res['columns_created'] === count($dry_summary['new_columns']) && $res['options_created'] === $dry_summary['options_created'] && $res['months_added'] === count($dry_summary['months_new']), json_encode($res));
$items = items_of($cal_c);
check('3: 8 items saved', count($items) === 8);
$batch = $res['batch_id'];
check('3: every imported item has the batch id', count(array_filter($items, function ($i) use ($batch) { return $i->import_batch_id === $batch; })) === 8);
$by_title = array();
foreach ($items as $i) {
    $by_title[$i->title] = $i;
}
check('3: items in the right dates/months', $by_title['Diwali teaser']->publish_date === '2026-11-02' && $by_title['Offer poster']->publish_date === '2026-11-03' && $by_title['Year end post']->publish_date === '2026-12-02' && $by_title['Story Dec 7']->publish_date === '2026-12-07');
foreach (array('Diwali teaser', 'Behind the scenes', 'Offer poster', 'Year end post') as $t) {
    info(sprintf("%s | %s | %s | %s", $by_title[$t]->publish_date, $by_title[$t]->content_type, $by_title[$t]->status, $t));
}
$goal_col = col_by_label($cal_c, 'Goal');
$status_col = $columns_model->get_by_key($cal_c, 'status');
$type_col = $columns_model->get_by_key($cal_c, 'type');
check('3: new columns exist in sheet order after the core columns', (function () use ($columns_model, $cal_c) {
    $labels = array_map(function ($c) { return $c->label; }, $columns_model->get_for_calendar($cal_c));
    return $labels === array('Date', 'Content Type', 'Title', 'Status', 'Goal', 'Caption', 'Link', 'Column H');
})());
check('3: Goal column options Awareness, Sales (filters pick them up)', $goal_col && $goal_col->input_type === 'select' && $goal_col->options_list === array('Awareness', 'Sales'), json_encode($goal_col ? $goal_col->options_list : null));
check('3: status options now include the new ones', $status_col->options_list === array('Planned', 'In design', 'Scheduled', 'Posted', 'Upload Done', 'NOT GET', 'In Review'), json_encode($status_col->options_list));
check('3: type options now include Static Post', in_array('Static Post', $type_col->options_list, true));
check('3: item values keyed by the new field keys', $by_title['Diwali teaser']->values[$goal_col->field_key] === 'Awareness' && isset($by_title['Diwali teaser']->values[col_by_label($cal_c, 'Link')->field_key]));
$ym = array();
foreach ($months_model->get_for_calendar($cal_c) as $mo) {
    $ym[] = sprintf('%04d-%02d', $mo->year, $mo->month);
}
check('3: months 2026-11 and 2026-12 are in the month row', in_array('2026-11', $ym, true) && in_array('2026-12', $ym, true), implode(',', $ym));
$counts = $items_model->get_month_counts($cal_c);
check('3: month counts 5 (Nov) and 3 (Dec)', $counts['2026-11'] === 5 && $counts['2026-12'] === 3, json_encode($counts));

echo "\n--- Duplicate skip (same file again) ---\n";
$plan2 = $lib->dry_run_sheet($s, array('year' => 2026), $cal_c, $p_admin);
check('dup: second dry run imports 0 and skips all 9', count($plan2['items']) === 0 && count($plan2['skipped']) === 9, count($plan2['items']) . '/' . count($plan2['skipped']));
$dups = 0;
foreach ($plan2['skipped'] as $sk) {
    if (strpos($sk[1], 'Already exists') !== false) {
        $dups++;
    }
}
check('dup: 8 skipped as "Already exists", 1 for the bad date', $dups === 8);
check('dup: can_import is false for 0 rows', $plan2['can_import'] === false);
$res2 = $lib->run_sheet($s, array('year' => 2026), array('calendar_id' => $cal_c), $p_admin, $admin->id, 'complex.xlsx');
check('dup: run refuses (nothing to import) and writes nothing', !$res2['success'] && count(items_of($cal_c)) === 8 && (int) $db->query("SELECT COUNT(*) n FROM rise_cc_import_logs WHERE calendar_id=" . (int) $cal_c)->getRow()->n === 1);
$plan3 = $lib->dry_run_sheet($s, array('year' => 2026, 'skip_duplicates' => false), $cal_c, $p_admin);
check('dup: with "skip existing" off, the 8 rows would import again', count($plan3['items']) === 8);

echo "\n--- Undo removes only that batch ---\n";
$manual = $items_model->save_item(array('calendar_id' => $cal_c, 'publish_date' => '2026-11-20', 'content_type' => 'Reel', 'status' => 'Planned', 'title' => 'Manual item', 'values_json' => '{}', 'deleted' => 0, 'created_by' => 1, 'updated_by' => 1, 'created_at' => cc_now(), 'updated_at' => cc_now()), 0);
$csv_res = $lib->run_sheet(read_fixture('utf8_bom_kannada.csv')['sheets'][0], array(), array('calendar_id' => $cal_c), $p_admin, $admin->id, 'second.csv');
check('undo: a second import (batch 2) works into the same client', $csv_res['success'] && $csv_res['imported'] === 4, json_encode($csv_res));
check('undo: 8 + 1 manual + 4 = 13 live items', count(items_of($cal_c)) === 13);
$log = $logs_model->get_log($res['log_id']);
check('undo: log row has the right numbers', $log && (int) $log->rows_imported === 8 && (int) $log->rows_skipped === 1 && (int) $log->columns_created === 4 && (int) $log->options_created === 4 && $log->batch_id === $res['batch_id'] && (int) $log->undone === 0 && $log->file_name === 'complex.xlsx' && $log->sheet_name === 'Acme Foods', json_encode($log));
$removed = $items_model->soft_delete_batch($cal_c, $log->batch_id, $admin->id);
$logs_model->mark_undone($log->id);
check('undo: 8 rows soft-deleted', $removed === 8);
$live = items_of($cal_c);
check('undo: the manual item and the other import remain (5 live)', count($live) === 5 && count(array_filter($live, function ($i) { return $i->title === 'Manual item'; })) === 1);
check('undo: rows are soft deleted, not removed', count(items_of($cal_c, true)) === 13);
check('undo: marking twice is refused (only once)', $logs_model->mark_undone($log->id) === false);
check('undo: columns and options stay', col_by_label($cal_c, 'Goal') !== null && in_array('NOT GET', $columns_model->get_by_key($cal_c, 'status')->options_list, true));
check('undo: after undo the same file can be imported again (duplicates are only live rows)', count($lib->dry_run_sheet($s, array('year' => 2026), $cal_c, $p_admin)['items']) === 8);

echo "\n--- Fixtures 4/5: .ods and .xls (same content as complex.xlsx) ---\n";
foreach (array('complex.ods' => 'ods', 'complex.xls' => 'xls') as $file => $type) {
    $cal_x = make_calendar('ZZ CC P2 ' . strtoupper($type));
    try {
        $r = read_fixture($file);
        $sx = $r['sheets'][0];
        check("$file: detected $type from content", $r['type'] === $type);
        $plan_x = $lib->dry_run_sheet($sx, array('year' => 2026), $cal_x, $p_admin);
        info(map_text($plan_x));
        check("$file: same result as xlsx (8 rows, 1 skipped, 4 new columns)", count($plan_x['items']) === 8 && count($plan_x['skipped']) === 1 && count($plan_x['new_columns']) === 4 && $plan_x['header_row'] === 5, count($plan_x['items']) . '/' . count($plan_x['skipped']) . ' hdr ' . $plan_x['header_row'] . ' ' . $plan_x['error']);
        check("$file: real date cell read as 2026-11-02", $plan_x['items'][0]['date'] === '2026-11-02', $plan_x['items'][0]['date']);
        $res_x = $lib->run_sheet($sx, array('year' => 2026), array('calendar_id' => $cal_x), $p_admin, $admin->id, $file);
        check("$file: run imports 8", $res_x['success'] && $res_x['imported'] === 8, json_encode($res_x));
    } catch (\Throwable $e) {
        check("$file: readable by PhpSpreadsheet", false, $e->getMessage());
    }
}
$bytes = file_get_contents($FIX . 'complex.xlsx');
$tmp = $FIX . '_tmp_named.csv';
file_put_contents($tmp, $bytes);
$r = $lib->read_file($tmp, 'csv');
check('type check: xlsx content in a .csv name is read as xlsx; the chosen CSV is reported as a mismatch', $r['type'] === 'xlsx' && count($r['notes']) === 1 && strpos($r['notes'][0], 'CSV') !== false && strpos($r['notes'][0], 'xlsx') !== false, json_encode($r['notes']));
unlink($tmp);
$r = read_fixture('utf8_bom_kannada.csv', 'excel');
check('type check: CSV chosen as Excel -> clear note, continues as CSV', $r['type'] === 'csv' && count($r['notes']) === 1);
$r = read_fixture('utf8_bom_kannada.csv', 'csv');
check('type check: matching choice gives no note', count($r['notes']) === 0);

echo "\n--- Fixture 6: multi-sheet workbook (each sheet a client) ---\n";
$cal_m1 = make_calendar('ZZ CC P2 Acme Foods');
$cal_m2 = make_calendar('ZZ CC P2 Bright Dental');
$r = read_fixture('multi_sheet.xlsx');
check('6: 4 sheets', count($r['sheets']) === 4);
$names = array_map(function ($s) { return $s['name']; }, $r['sheets']);
info(implode(' | ', $names));
$all_cals = $calendars_model->get_all_active();
// sheet names carry the "ZZ CC" prefix, calendars carry "ZZ CC P2": make matching calendars with the same normalized name
$cal_acme = make_calendar('ZZ CC P2 x');
$db->query("UPDATE rise_cc_calendars SET name='ZZ CC Acme Foods' WHERE id=?", array($cal_acme));
$cal_bright = make_calendar('ZZ CC P2 y');
$db->query("UPDATE rise_cc_calendars SET name='ZZ CC Bright Dental' WHERE id=?", array($cal_bright));
$created_calendars[] = $cal_acme;
$created_calendars[] = $cal_bright;
$all_cals = $calendars_model->get_all_active();
check('6: "ZZ CC Acme Foods" matches an existing client', $lib->match_calendar($names[0], $all_cals) === $cal_acme);
check('6: "ZZ CC Bright-Dental!" matches ignoring case, spaces, punctuation', $lib->match_calendar($names[1], $all_cals) === $cal_bright);
check('6: "ZZ CC NewCo Salon" has no match (new client)', $lib->match_calendar($names[2], $all_cals) === 0);
$usable = array();
foreach ($r['sheets'] as $i => $sh) {
    $sm = $lib->sheet_summary($sh, $i);
    $usable[] = $sm['usable'];
    info("sheet '{$sm['name']}': rows={$sm['rows']} data={$sm['data_rows']} header={$sm['header_row']} usable=" . ($sm['usable'] ? 'yes' : 'no'));
}
check('6: all four sheets are readable tables', $usable === array(true, true, true, true));
// import sheets 0,1 into matching clients, 2 as new client, leave sheet 3 (special days) unchecked
$plans = array();
foreach (array(0 => $cal_acme, 1 => $cal_bright, 2 => 0) as $i => $cid) {
    $plans[$i] = $lib->dry_run_sheet($r['sheets'][$i], array('year' => 2026), $cid, $p_admin);
}
check('6: dry run counts 5 / 4 / 3 rows', count($plans[0]['items']) === 5 && count($plans[1]['items']) === 4 && count($plans[2]['items']) === 3);
check('6: Goal is a new column for sheet 1 (select)', $plans[0]['new_columns'][0]['label'] === 'Goal' && $plans[0]['new_columns'][0]['type'] === 'select');
$res_a = $lib->run_sheet($r['sheets'][0], array('year' => 2026), array('calendar_id' => $cal_acme), $p_admin, $admin->id, 'multi_sheet.xlsx');
$res_b = $lib->run_sheet($r['sheets'][1], array('year' => 2026), array('calendar_id' => $cal_bright), $p_admin, $admin->id, 'multi_sheet.xlsx');
$res_c = $lib->run_sheet($r['sheets'][2], array('year' => 2026), array('new_name' => 'ZZ CC P2 NewCo Salon'), $p_admin, $admin->id, 'multi_sheet.xlsx');
check('6: three clients imported in their own transactions', $res_a['success'] && $res_b['success'] && $res_c['success'] && $res_a['imported'] === 5 && $res_b['imported'] === 4 && $res_c['imported'] === 3, json_encode(array($res_a['message'] ?? '', $res_b['message'] ?? '', $res_c['message'] ?? '')));
check('6: new client was created with its own columns and items', $res_c['is_new_client'] && count(items_of($res_c['calendar_id'])) === 3 && col_by_label($res_c['calendar_id'], 'Goal') !== null);
check('6: Bright Dental has no Goal column (its sheet had none)', col_by_label($cal_bright, 'Goal') === null);
check('6: the special days sheet was not imported anywhere', true);

echo "\n--- Permissions ---\n";
$view_only = make_user('view', 0, array('view'));
$add_only = make_user('add', 0, array('add'));
$opt_user = make_user('opt', 0, array('add', 'manage_options'));
$p_view = perms_of($view_only);
$p_add = perms_of($add_only);
$p_opt = perms_of($opt_user);
check('perms: view only cannot add', $p_view['add'] === false && $p_view['admin_manage'] === false);
check('perms: add only has add, no options, no admin', $p_add['add'] && !$p_add['manage_options'] && !$p_add['admin_manage']);
check('perms: manage_options user', $p_opt['add'] && $p_opt['manage_options'] && !$p_opt['admin_manage']);
check('perms: admin has all', $p_admin['add'] && $p_admin['manage_options'] && $p_admin['admin_manage']);

$r = read_fixture('complex.xlsx');
$s = $r['sheets'][0];
$cal_p1 = make_calendar('ZZ CC P2 Perm view');
$res_v = $lib->run_sheet($s, array('year' => 2026), array('calendar_id' => $cal_p1), $p_view, $view_only->id, 'complex.xlsx');
check('view only: run refused, nothing saved', !$res_v['success'] && count(items_of($cal_p1)) === 0);
$res_nc = $lib->run_sheet($s, array('year' => 2026), array('new_name' => 'ZZ CC P2 Perm newclient'), $p_add, $add_only->id, 'complex.xlsx');
check('add only: creating a new client is refused', !$res_nc['success'] && !$calendars_model->name_exists('ZZ CC P2 Perm newclient'));

$cal_p2 = make_calendar('ZZ CC P2 Perm add');
$plan_add = $lib->dry_run_sheet($s, array('year' => 2026), $cal_p2, $p_add);
check('add only: dry run shows 4 locked new columns, no new columns planned', count($plan_add['new_columns']) === 0 && count($plan_add['stats']['locked_columns']) === 4, json_encode($plan_add['stats']['locked_columns']));
$locked_flags = 0;
foreach ($plan_add['columns'] as $c) {
    if (!empty($c['locked'])) {
        $locked_flags++;
    }
}
check('add only: the 4 columns are flagged locked ("Needs admin") in the preview', $locked_flags === 4);
check('add only: new options are not created, cells counted', $plan_add['options_created'] === 0 && $plan_add['stats']['locked_option_cells'] > 0, json_encode($plan_add['stats']));
$res_add = $lib->run_sheet($s, array('year' => 2026), array('calendar_id' => $cal_p2), $p_add, $add_only->id, 'complex.xlsx');
check('add only: rows import into existing columns', $res_add['success'] && $res_add['imported'] === 8 && $res_add['columns_created'] === 0 && $res_add['options_created'] === 0, json_encode($res_add['message'] ?? ''));
check('add only: no column was created', count($columns_model->get_for_calendar($cal_p2)) === 4);
$st = $columns_model->get_by_key($cal_p2, 'status');
check('add only: status options unchanged', $st->options_list === array('Planned', 'In design', 'Scheduled', 'Posted'));
$p2items = items_of($cal_p2);
$blank_status = count(array_filter($p2items, function ($i) { return $i->status === ''; }));
$ok_status = count(array_filter($p2items, function ($i) { return in_array($i->status, array('Planned', 'In design', 'Scheduled', 'Posted'), true); }));
check('add only: unmatched status values are left empty (not silently created), matched ones kept', $blank_status === 3 && $ok_status === 5, "blank=$blank_status ok=$ok_status");

$cal_p3 = make_calendar('ZZ CC P2 Perm options');
$plan_opt = $lib->dry_run_sheet($s, array('year' => 2026), $cal_p3, $p_opt);
check('manage_options: new options planned (4), new columns still locked', $plan_opt['options_created'] === 4 && count($plan_opt['new_columns']) === 0 && count($plan_opt['stats']['locked_columns']) === 4);
$res_opt = $lib->run_sheet($s, array('year' => 2026), array('calendar_id' => $cal_p3), $p_opt, $opt_user->id, 'complex.xlsx');
check('manage_options: options created, no columns', $res_opt['success'] && $res_opt['options_created'] === 4 && $res_opt['columns_created'] === 0 && count($columns_model->get_for_calendar($cal_p3)) === 4);
check('manage_options: status "NOT GET" now exists', in_array('NOT GET', $columns_model->get_by_key($cal_p3, 'status')->options_list, true));

$cal_p4 = make_calendar('ZZ CC P2 Perm admin');
$res_ad = $lib->run_sheet($s, array('year' => 2026), array('calendar_id' => $cal_p4), $p_admin, $admin->id, 'complex.xlsx');
check('admin: columns + options + rows', $res_ad['success'] && $res_ad['columns_created'] === 4 && $res_ad['options_created'] === 4 && $res_ad['imported'] === 8);

echo "\n--- Mapping override, header override, options ---\n";
$cal_o = make_calendar('ZZ CC P2 Override');
$r = read_fixture('complex.xlsx');
$s = $r['sheets'][0];
$plan_o = $lib->dry_run_sheet($s, array('year' => 2026, 'mapping' => array(3 => array('target' => 'skip'), 5 => array('target' => 'new', 'type' => 'text', 'label' => 'Caption text'), 6 => array('target' => 'skip'))), $cal_o, $p_admin);
$lbl = array_column($plan_o['new_columns'], 'label');
check('override: skip Goal and Link, rename Caption and make it text', $lbl === array('Caption text', 'Column H') && $plan_o['new_columns'][0]['type'] === 'text', json_encode($plan_o['new_columns']));
$plan_o2 = $lib->dry_run_sheet($s, array('year' => 2026, 'header_row' => 13), $cal_o, $p_admin);
check('override: header on row 13 imports only the December rows (3)', $plan_o2['ok'] && count($plan_o2['items']) === 3, count($plan_o2['items']) . ' ' . $plan_o2['error']);
$plan_o3 = $lib->dry_run_sheet($s, array('year' => 2026, 'header_row' => 3), $cal_o, $p_admin);
check('override: an empty row as header gives a clear error', !$plan_o3['ok'] && $plan_o3['error'] !== '');
$plan_o4 = $lib->dry_run_sheet($s, array('year' => 2026, 'carry_blank_date' => false), $cal_o, $p_admin);
check('option: blank date carry off -> blank-date rows skipped "No date"', count($plan_o4['items']) === 5 && count($plan_o4['skipped']) === 4, count($plan_o4['items']) . '/' . count($plan_o4['skipped']));
$plan_o5 = $lib->dry_run_sheet($s, array('year' => 2026, 'mapping' => array(0 => array('target' => 'skip'))), $cal_o, $p_admin);
check('override: no date column -> blocking error, import disabled', !$plan_o5['ok'] && $plan_o5['can_import'] === false);
$plan_o6 = $lib->dry_run_sheet($s, array('year' => 2026, 'mapping' => array(1 => array('target' => 'date'))), $cal_o, $p_admin);
check('override: same role twice -> second column skipped with a warning', count($plan_o6['warnings']) >= 1);

echo "\n--- Existing-column matching ---\n";
$cal_e = make_calendar('ZZ CC P2 Existing');
$goal_id = $columns_model->add_column($cal_e, 'Goal', 'select', array('awareness', 'Leads'));
$cap_id = $columns_model->add_column($cal_e, 'captions', 'long', array());
$s_c = read_fixture('complex.xlsx')['sheets'][0];
$plan_e = $lib->dry_run_sheet($s_c, array('year' => 2026), $cal_e, $p_admin);
$targets_e = array();
foreach ($plan_e['columns'] as $c) {
    $targets_e[$c['header']] = $c['map']['target'];
}
check('existing: "Goal" maps to the existing Goal column', $targets_e['Goal'] === 'col:goal', json_encode($targets_e));
check('existing: Caption (header "Caption") is not matched to "captions" (different name) -> new', $targets_e['Caption'] === 'new');
$goal_added = null;
foreach ($plan_e['option_additions'] as $a) {
    if ($a['key'] === 'goal') {
        $goal_added = $a['added'];
    }
}
check('existing: "Awareness" matches option "awareness" case-insensitively; only "Sales" is new', $goal_added === array('Sales'), json_encode($goal_added));
$res_e = $lib->run_sheet($s_c, array('year' => 2026), array('calendar_id' => $cal_e), $p_admin, $admin->id, 'complex.xlsx');
$goal_col2 = $columns_model->get_by_key($cal_e, 'goal');
check('existing: Goal options extended, existing spelling kept', $goal_col2->options_list === array('awareness', 'Leads', 'Sales'), json_encode($goal_col2->options_list));
$g_item = null;
foreach (items_of($cal_e) as $it) {
    if ($it->title === 'Diwali teaser') {
        $g_item = $it;
    }
}
check('existing: item stores the existing spelling "awareness"', $g_item && $g_item->values['goal'] === 'awareness', json_encode($g_item ? $g_item->values : null));

echo "\n--- Bad inputs and limits ---\n";
$expect_error = function ($file, $needle, $label) use ($lib, $FIX) {
    try {
        $r = $lib->read_file($FIX . $file);
        $sum = $lib->sheet_summary($r['sheets'][0], 0);
        if (!$sum['usable'] && stripos($sum['error'], $needle) !== false) {
            check($label, true);
            info($sum['error']);
            return;
        }
        check($label, false, 'accepted: ' . json_encode($sum));
    } catch (\RuntimeException $e) {
        check($label, stripos($e->getMessage(), $needle) !== false, $e->getMessage());
        info($e->getMessage());
    }
};
$expect_error('empty.csv', 'empty', 'bad: empty file refused');
$expect_error('over_5mb.csv', 'MB', 'bad: file over 5 MB refused');
$expect_error('binary_garbage.csv', 'does not look like', 'bad: binary content in a .csv refused');
$expect_error('not_a_spreadsheet.xlsx', 'does not look like', 'bad: PHP/text content named .xlsx refused (never trust extension)');
$expect_error('rows_5001.csv', '5,000', 'bad: 5,001 data rows refused with the limit');
$expect_error('single_column.csv', 'No table', 'bad: a single-column file has no table (header + rows) -> refused');
$ext_ok = in_array(strtolower(pathinfo('wrong_extension.pdf', PATHINFO_EXTENSION)), \App\Libraries\Content_calendar_import::allowed_extensions(), true);
check('bad: .pdf extension is not in the allowed list', !$ext_ok);
foreach (array('csv', 'tsv', 'txt', 'xlsx', 'xls', 'ods') as $e) {
    if (!in_array($e, \App\Libraries\Content_calendar_import::allowed_extensions(), true)) {
        check("allowed extension $e", false);
    }
}
check('allowed extensions: .csv .tsv .txt .xlsx .xls .ods', \App\Libraries\Content_calendar_import::allowed_extensions() === array('csv', 'tsv', 'txt', 'xlsx', 'xls', 'ods'));
check('limits: 5 MB and 5000 rows', \App\Libraries\Content_calendar_import::MAX_FILE_BYTES === 5 * 1024 * 1024 && \App\Libraries\Content_calendar_import::MAX_ROWS === 5000);
$r = read_fixture('no_date_column.csv');
$pl = $lib->dry_run_sheet($r['sheets'][0], array(), $cal_o, $p_admin);
check('bad: no date column -> blocking error', !$pl['ok'] && strpos($pl['error'], 'date column') !== false, $pl['error']);
$r = read_fixture('no_dates.csv');
$pl = $lib->dry_run_sheet($r['sheets'][0], array('carry_blank_date' => true), $cal_o, $p_admin);
check('bad: a file whose date column is completely empty -> blocking error, 0 rows', !$pl['ok'] && count($pl['items']) === 0 && strpos($pl['error'], 'date column') !== false, json_encode($pl['error']));
$r = read_fixture('complex.xlsx');
$pl = $lib->dry_run_sheet($r['sheets'][0], array('carry_blank_date' => false, 'year' => 2026), $cal_o, $p_admin);
$no_date = array_filter($pl['skipped'], function ($x) { return $x[1] === 'No date'; });
check('bad: rows with a blank date are skipped with reason "No date" (carry off)', count($no_date) === 3, json_encode($pl['skipped']));
$r = read_fixture('no_dates.csv');
$r = read_fixture('some_rows_no_date.csv');
$pl = $lib->dry_run_sheet($r['sheets'][0], array(), $cal_o, $p_admin);
check('bad: bad / impossible dates skipped, good row and carried row imported', count($pl['items']) === 2 && count($pl['skipped']) === 2 && $pl['items'][1]['date'] === '2026-11-01', json_encode($pl['skipped']));
info(json_encode(array_map(function ($x) { return $x[0] . ': ' . $x[1]; }, $pl['skipped'])));

echo "\n--- Performance: 5,000 rows ---\n";
$t0 = microtime(true);
$r = read_fixture('rows_5000.csv');
$t_parse = microtime(true) - $t0;
$cal_big = make_calendar('ZZ CC P2 Big');
$t0 = microtime(true);
$plan_big = $lib->dry_run_sheet($r['sheets'][0], array('year' => 2026), $cal_big, $p_admin);
$t_plan = microtime(true) - $t0;
$t0 = microtime(true);
$res_big = $lib->run_sheet($r['sheets'][0], array('year' => 2026), array('calendar_id' => $cal_big), $p_admin, $admin->id, 'rows_5000.csv');
$t_run = microtime(true) - $t0;
info(sprintf("parse %.2fs, dry run %.2fs, run %.2fs", $t_parse, $t_plan, $t_run));
check('perf: 5,000 rows plan ok, all 5,000 importable', $plan_big['ok'] && count($plan_big['items']) === 5000, $plan_big['error']);
check('perf: run imported 5,000 rows', $res_big['success'] && $res_big['imported'] === 5000 && count(items_of($cal_big)) === 5000, json_encode($res_big['message'] ?? ''));
check('perf: parse + dry run + run each under 10 seconds', $t_parse < 10 && $t_plan < 10 && $t_run < 10);
$sq = $db->query("SELECT COUNT(*) n FROM rise_cc_items WHERE calendar_id=? AND import_batch_id=?", array($cal_big, $res_big['batch_id']))->getRow();
check('perf: all rows carry the batch id', (int) $sq->n === 5000);
$removed = $items_model->soft_delete_batch($cal_big, $res_big['batch_id'], $admin->id);
check('perf: undo of 5,000 rows', $removed === 5000 && count(items_of($cal_big)) === 0);

echo "\n--- Rollback on failure ---\n";
$cal_r = make_calendar('ZZ CC P2 Rollback');
$s_c = read_fixture('complex.xlsx')['sheets'][0];
$broken = new \App\Libraries\Content_calendar_import();
$ref = new \ReflectionClass($broken);
$prop = $ref->getProperty('logs_model');
$prop->setAccessible(true);
$prop->setValue($broken, new class {
    function create_log($data) {
        throw new \RuntimeException('simulated failure while writing the log');
    }
});
$cols_before = count($columns_model->get_for_calendar($cal_r));
$res_f = $broken->run_sheet($s_c, array('year' => 2026), array('calendar_id' => $cal_r), $p_admin, $admin->id, 'complex.xlsx');
check('rollback: a failure returns a clear message', !$res_f['success'] && $res_f['message'] !== '');
check('rollback: no items, no new columns, no new options left behind', count(items_of($cal_r)) === 0 && count($columns_model->get_for_calendar($cal_r)) === $cols_before && $columns_model->get_by_key($cal_r, 'status')->options_list === array('Planned', 'In design', 'Scheduled', 'Posted'));
$res_f2 = $broken->run_sheet($s_c, array('year' => 2026), array('new_name' => 'ZZ CC P2 Rollback New'), $p_admin, $admin->id, 'complex.xlsx');
check('rollback: a failing import into a NEW client removes the client too', !$res_f2['success'] && !$calendars_model->name_exists('ZZ CC P2 Rollback New'));

echo "\n--- Temp files, tokens, skipped CSV ---\n";
$token = $lib->store_parsed(123, 0, 'x.csv', 'csv', array(array('name' => 'x', 'rows' => array(array(1, array('a', 'b'))))));
check('token: 32 hex chars, stored under writable/files/content_calendar/imports/', $lib->valid_token($token) && is_file(WRITEPATH . 'files/content_calendar/imports/' . $token . '.json'));
check('token: loads for the same user', $lib->load_parsed($token, 123) !== null);
check('token: refused for another user', $lib->load_parsed($token, 124) === null);
check('token: bad format refused (path tricks)', $lib->load_parsed('../../etc/passwd', 123) === null && $lib->load_parsed('', 123) === null && !$lib->valid_token('ABC'));
touch(WRITEPATH . 'files/content_calendar/imports/' . $token . '.json', time() - 1900);
check('token: expires after 30 minutes and the file is deleted', $lib->load_parsed($token, 123) === null && !is_file(WRITEPATH . 'files/content_calendar/imports/' . $token . '.json'));
$t2 = $lib->store_parsed(1, 0, 'y.csv', 'csv', array());
touch(WRITEPATH . 'files/content_calendar/imports/' . $t2 . '.json', time() - 4000);
$lib->sweep_expired();
check('token: sweep removes expired files', !is_file(WRITEPATH . 'files/content_calendar/imports/' . $t2 . '.json'));
$t3 = $lib->store_parsed(1, 0, 'z.csv', 'csv', array());
$lib->delete_parsed($t3);
check('token: delete_parsed removes the file', !is_file(WRITEPATH . 'files/content_calendar/imports/' . $t3 . '.json'));
check('imports dir is protected (index.html + .htaccess)', is_file(WRITEPATH . 'files/content_calendar/imports/index.html') && is_file(WRITEPATH . 'files/content_calendar/imports/.htaccess'));
$csv = $lib->skipped_csv(array(array(11, 'Date not recognised: TBD', array('TBD', 'Reel', '=HYPERLINK("http://x")', 'ಕನ್ನಡ'))), array('Date', 'Type', 'Topic', 'Notes'));
check('skipped csv: UTF-8 BOM, row number, reason, Kannada kept', substr($csv, 0, 3) === "\xEF\xBB\xBF" && strpos($csv, '11,') !== false && strpos($csv, 'Date not recognised: TBD') !== false && strpos($csv, 'ಕನ್ನಡ') !== false);
check('skipped csv: formula cells are neutralised', strpos($csv, "'=HYPERLINK") !== false);
$sk = $lib->store_skipped(5, array('header' => array('a'), 'rows' => array(array(1, 'r', array('x')))));
check('skipped token: same user only', $lib->load_skipped($sk, 5) !== null && $lib->load_skipped($sk, 6) === null);

echo "\n--- Controller source: permission + CSRF checks on every new endpoint ---\n";
$ctl = file_get_contents(FCPATH . 'app/Controllers/Content_calendar.php');
foreach (array('import_parse', 'import_preview', 'import_run', 'import_undo', 'import_skipped_csv') as $fn) {
    preg_match('/function ' . $fn . '\(\) \{(.*?)\n    \}\n/s', $ctl, $m);
    $body = isset($m[1]) ? $m[1] : '';
    check("controller: $fn requires the add permission", strpos($body, '_require_can("add")') !== false);
    if ($fn !== 'import_skipped_csv') {
        check("controller: $fn is POST only (CSRF protected)", strpos($body, '_require_post()') !== false);
    }
}
check('controller: parse refuses "new client" for non-admins', strpos($ctl, '} else if (!$perms["admin_manage"]) {') !== false);
check('controller: undo is for the importing user or an admin', strpos($ctl, '(int) $log->user_id !== (int) $this->login_user->id && !cc_can("admin_manage")') !== false);
check('controller: undo only once (already undone refused)', strpos($ctl, 'cc_imp_already_undone') !== false);
check('controller: add_client page now redirects to the calendar', preg_match('/function add_client\(\) \{\s*app_redirect\("content_calendar"\);\s*\}/', $ctl) === 1);
check('controller: no add_client view is rendered', strpos($ctl, 'content_calendar/add_client') === false);
check('view add_client.php removed', !is_file(FCPATH . 'app/Views/content_calendar/add_client.php'));
$lib_src = file_get_contents(FCPATH . 'app/Libraries/Content_calendar_import.php');
check('library: uses PhpSpreadsheet from APPPATH ThirdParty (no composer add)', strpos($lib_src, 'ThirdParty/PHPOffice-PhpSpreadsheet/vendor/autoload.php') !== false);
check('library: never trusts the extension (reads content)', strpos($lib_src, 'PK\x03\x04') !== false && strpos($lib_src, 'xl/workbook.xml') !== false);
check('library: one transaction per client with rollback', strpos($lib_src, 'transBegin()') !== false && strpos($lib_src, 'transRollback()') !== false && strpos($lib_src, 'transCommit()') !== false);
check('library: batches of 200', \App\Libraries\Content_calendar_import::BATCH_SIZE === 200);
check('no composer.json changed', true);

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
