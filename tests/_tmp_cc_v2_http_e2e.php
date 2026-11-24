<?php
/**
 * Content Calendar import v2 - HTTP end-to-end test (LOCAL ONLY).
 *
 * Starts the PHP built-in server (127.0.0.1:8099 or the next free port) with tests/_tmp_cc_v2_router.php, creates two temporary
 * staff users (admin + add-only) in the LOCAL database, logs in over HTTP with CSRF and drives the real import endpoints with
 * tests/_tmp_fixtures/Content_Calender_2026.xlsx. Everything it creates (users, calendars, items, logs, temp files) is removed at the end.
 *
 * Usage:  php tests/_tmp_cc_v2_http_e2e.php
 *         CC_E2E_INI=1 php tests/_tmp_cc_v2_http_e2e.php     (start the server with raised upload / memory / time limits)
 *
 * Results are printed and also written to tests/_tmp_cc_v2_http_e2e_results.txt
 */
ini_set('memory_limit', '1024M');
ini_set('max_execution_time', '0');
set_time_limit(0);
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

/* ---------------------------------------------------------------- hard rule: local database only */
$host = $db->hostname;
if (!in_array($host, array('127.0.0.1', 'localhost', 'localhost:3306'), true)) {
    echo "REFUSING: database host is '$host' (local only).\n";
    exit(1);
}

$report = '';
function out($s) {
    global $report;
    echo $s;
    $report .= $s;
}

$rows = array();   //id, name, status, evidence
function check($id, $name, $cond, $evidence = '') {
    global $rows;
    $evidence = preg_replace('/\s+/', ' ', (string) $evidence);
    if (strlen($evidence) > 420) {
        $evidence = substr($evidence, 0, 420) . '...';
    }
    $rows[] = array($id, $name, $cond ? 'PASS' : 'FAIL', $evidence);
    out(($cond ? '[PASS] ' : '[FAIL] ') . "$id $name" . ($evidence !== '' ? "  -- $evidence" : '') . "\n");
}

function info($id, $name, $evidence = '') {
    global $rows;
    $evidence = preg_replace('/\s+/', ' ', (string) $evidence);
    if (strlen($evidence) > 420) {
        $evidence = substr($evidence, 0, 420) . '...';
    }
    $rows[] = array($id, $name, 'INFO', $evidence);
    out("[INFO] $id $name" . ($evidence !== '' ? "  -- $evidence" : '') . "\n");
}

function mb($bytes) {
    return round($bytes / 1048576, 1) . ' MB';
}

/* ---------------------------------------------------------------- http client */
$csrf_name = 'rise_csrf_token';
$csrf_by_jar = array();
$BASE = '';

function csrf_from_html($html) {
    global $csrf_name;
    if (preg_match('/name="' . $csrf_name . '"[^>]*value="([^"]+)"/', $html, $m) || preg_match('/value="([^"]+)"[^>]*name="' . $csrf_name . '"/', $html, $m)) {
        return $m[1];
    }
    return '';
}

function http_raw($method, $path, $data, $jar, $multipart, $csrf) {
    global $BASE, $csrf_name;
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 900,
        CURLOPT_HTTPHEADER => array('X-Requested-With: XMLHttpRequest'),
    ));
    if ($method === 'POST') {
        if ($csrf) {
            $data[$csrf_name] = $csrf;
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $data : http_build_query($data));
    }
    $t = microtime(true);
    $raw = curl_exec($ch);
    $elapsed = microtime(true) - $t;
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    $headers = substr((string) $raw, 0, $hs);
    $body = substr((string) $raw, $hs);
    $location = '';
    if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) {
        $location = trim($m[1]);
    }
    return array('status' => $status, 'headers' => $headers, 'body' => $body, 'json' => json_decode($body, true), 'location' => $location, 'time' => $elapsed, 'error' => $err);
}

function http($method, $path, $data, $jar, $multipart = false) {
    global $csrf_by_jar;
    $r = http_raw($method, $path, $data, $jar, $multipart, isset($csrf_by_jar[$jar]) ? $csrf_by_jar[$jar] : '');
    //token rejected? get a fresh one from a page and retry once (CSRF failures are not JSON)
    if ($method === 'POST' && $r['status'] === 403 && !is_array($r['json'])) {
        $page = http_raw('GET', '/content_calendar', array(), $jar, false, '');
        $token = csrf_from_html($page['body']);
        if ($token !== '') {
            $csrf_by_jar[$jar] = $token;
            $r = http_raw($method, $path, $data, $jar, $multipart, $token);
        }
    }
    return $r;
}

function ok($r) {
    return $r['status'] === 200 && is_array($r['json']) && !empty($r['json']['success']);
}

function post_payload($path, $payload, $jar) {
    return http('POST', $path, array('payload' => json_encode($payload)), $jar);
}

function snippet($r) {
    return 'status ' . $r['status'] . ' ' . substr(preg_replace('/\s+/', ' ', strip_tags($r['body'])), 0, 200);
}

function q($sql, $params = array()) {
    global $db;
    return $db->query($sql, $params);
}

function q1($sql, $params = array()) {
    $r = q($sql, $params)->getRow();
    return $r;
}

function scalar($sql, $params = array()) {
    $r = q($sql, $params)->getRow();
    if (!$r) {
        return null;
    }
    foreach ($r as $v) {
        return $v;
    }
    return null;
}

/* ---------------------------------------------------------------- baseline (for cleanup) */
$baseline_calendars = array();
foreach (q("SELECT id FROM rise_cc_calendars")->getResult() as $r) {
    $baseline_calendars[(int) $r->id] = true;
}
$baseline_logs_cnt = (int) scalar("SELECT COUNT(*) FROM rise_cc_import_logs");
$imports_dir = WRITEPATH . 'files/content_calendar/imports/';
$baseline_import_files = is_dir($imports_dir) ? array_flip(scandir($imports_dir)) : array();
$logs_dir = WRITEPATH . 'logs/';
$baseline_log_files = array();
foreach (glob($logs_dir . '*') as $f) {
    $baseline_log_files[$f] = filemtime($f) . ':' . filesize($f);
}
$baseline_cal_names = array();
foreach (q("SELECT name FROM rise_cc_calendars WHERE deleted=0")->getResult() as $r) {
    $baseline_cal_names[mb_strtolower(trim($r->name), 'UTF-8')] = true;
}

$is_win = DIRECTORY_SEPARATOR === '\\';
$tmpdir = rtrim(sys_get_temp_dir(), '\\/');
$fixture_src = FCPATH . 'tests/_tmp_fixtures/Content_Calender_2026.xlsx';
if (!is_file($fixture_src)) {
    echo "Fixture missing: $fixture_src\n";
    exit(2);
}
//work on a native temp copy (curl + UNC paths)
$fixture = $tmpdir . DIRECTORY_SEPARATOR . 'Content_Calender_2026_e2e_' . getmypid() . '.xlsx';
copy($fixture_src, $fixture);

//git: on Windows (UNC path to the WSL project) run it inside WSL
function git_cmd($args) {
    $dir = rtrim(FCPATH, '/\\');
    if (DIRECTORY_SEPARATOR === '\\') {
        $linux = preg_replace('#^\\\\\\\\wsl(\.localhost|\$)\\\\[^\\\\]+#i', '', $dir);
        $linux = str_replace('\\', '/', $linux);
        return 'wsl -d Ubuntu -e git -C ' . $linux . ' ' . $args . ' 2>&1';
    }
    return 'git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>&1';
}

out("=== Content Calendar import v2 HTTP E2E (LOCAL ONLY) ===\n");
out("DB host: $host   database: " . $db->database . "   PHP " . PHP_VERSION . "\n");
out("fixture: tests/_tmp_fixtures/Content_Calender_2026.xlsx  (" . filesize($fixture) . " bytes)\n\n");

/* ---------------------------------------------------------------- start the built-in server */
$port = 0;
for ($p = 8099; $p < 8140; $p++) {
    $probe = @stream_socket_server("tcp://127.0.0.1:$p", $en, $es);
    if ($probe) {
        fclose($probe);
        $port = $p;
        break;
    }
}
if (!$port) {
    out("no free port\n");
    exit(1);
}
$BASE = "http://127.0.0.1:$port";
$peaklog = $tmpdir . DIRECTORY_SEPARATOR . 'cc_v2_peaks_' . getmypid() . '.log';
$srv_out = $tmpdir . DIRECTORY_SEPARATOR . 'cc_v2_server.out';
$srv_err = $tmpdir . DIRECTORY_SEPARATOR . 'cc_v2_server.err';
$null = $is_win ? 'NUL' : '/dev/null';
@unlink($peaklog);
$ini_flags = array();
if (getenv('CC_E2E_INI')) {
    $ini_flags = array('-d', 'upload_max_filesize=10M', '-d', 'post_max_size=12M', '-d', 'memory_limit=512M', '-d', 'max_execution_time=120');
}
$cmd = array_merge(array(PHP_BINARY), $ini_flags, array('-S', "127.0.0.1:$port", '-t', rtrim(FCPATH, '/\\'), FCPATH . 'tests/_tmp_cc_v2_router.php'));
$env = getenv();
$env['CC_E2E_PEAKLOG'] = $peaklog;
$server = proc_open($cmd, array(0 => array('file', $null, 'r'), 1 => array('file', $srv_out, 'w'), 2 => array('file', $srv_err, 'w')), $pipes, FCPATH, $env);
$ready = false;
for ($i = 0; $i < 40; $i++) {
    $fp = @fsockopen('127.0.0.1', $port, $en, $es, 0.3);
    if ($fp) {
        fclose($fp);
        $ready = true;
        break;
    }
    usleep(250000);
}
if (!$ready) {
    out("server did not start\n");
    exit(1);
}
out("server: $BASE  flags: " . ($ini_flags ? implode(' ', $ini_flags) : '(none: php.ini defaults)') . "\n\n");

$created_user_ids = array();
$jars = array();
$extra_cleanup_calendars = array();

function cleanup() {
    global $db, $server, $created_user_ids, $jars, $baseline_calendars, $baseline_import_files, $imports_dir, $cleanup_done, $baseline_logs_cnt;
    if (!empty($cleanup_done)) {
        return;
    }
    $cleanup_done = true;
    try {
        $in_users = $created_user_ids ? implode(',', array_map('intval', $created_user_ids)) : '0';
        $cals = array();
        foreach ($db->query("SELECT id, created_by FROM rise_cc_calendars")->getResult() as $r) {
            if (!isset($baseline_calendars[(int) $r->id]) && in_array((int) $r->created_by, $created_user_ids, true)) {
                $cals[] = (int) $r->id;
            }
        }
        foreach ($cals as $id) {
            foreach (array('cc_columns', 'cc_items', 'cc_months', 'cc_special_days', 'cc_targets', 'cc_import_logs') as $t) {
                $db->query("DELETE FROM rise_$t WHERE calendar_id=" . $id);
            }
            $db->query("DELETE FROM rise_cc_calendars WHERE id=" . $id);
        }
        $db->query("DELETE FROM rise_cc_permissions WHERE user_id IN ($in_users)");
        if ($created_user_ids) {
            $db->query("DELETE FROM rise_users WHERE id IN ($in_users)");
        }
        if (is_dir($imports_dir)) {
            foreach (scandir($imports_dir) as $f) {
                if ($f !== '.' && $f !== '..' && !isset($baseline_import_files[$f]) && is_file($imports_dir . $f)) {
                    @unlink($imports_dir . $f);
                }
            }
        }
        out("cleanup: removed " . count($cals) . " test calendars (" . implode(',', $cals) . "), users " . implode(',', $created_user_ids) . "\n");
    } catch (\Throwable $e) {
        out("cleanup error: " . $e->getMessage() . "\n");
    }
    foreach ($jars as $j) {
        @unlink($j);
    }
    if (!empty($GLOBALS['fixture'])) {
        @unlink($GLOBALS['fixture']);
    }
    if (is_resource($server)) {
        proc_terminate($server);
    }
}
register_shutdown_function('cleanup');

/* ---------------------------------------------------------------- users + login */
$password = 'Cc_E2e_12345!';
$hash = password_hash($password, PASSWORD_DEFAULT);
$users = array();
$tag = substr(bin2hex(random_bytes(3)), 0, 6);
foreach (array('admin' => 1, 'addonly' => 0) as $key => $is_admin) {
    $email = "cc_v2_e2e_{$key}_{$tag}@example.test";
    $db->table('users')->insert(array(
        'first_name' => 'ZZ E2E', 'last_name' => ucfirst($key), 'user_type' => 'staff', 'is_admin' => $is_admin, 'role_id' => 0,
        'email' => $email, 'password' => $hash, 'status' => 'active', 'client_id' => 0, 'job_title' => 'E2E Tester',
        'disable_login' => 0, 'language' => '', 'created_at' => get_current_utc_time(), 'deleted' => 0
    ));
    $id = (int) $db->insertID();
    $created_user_ids[] = $id;
    $users[$key] = array('id' => $id, 'email' => $email);
}
//add-only user: exactly one permission key, "add" (view is implied). No edit / add_client / add_column / manage_options / delete.
$db->table('cc_permissions')->insert(array('permission_key' => 'add', 'user_id' => $users['addonly']['id'], 'created_by' => $users['admin']['id'], 'created_at' => get_current_utc_time()));

function login($key) {
    global $users, $password, $jars, $csrf_by_jar;
    $jar = tempnam(sys_get_temp_dir(), 'ccv2_' . $key);
    $jars[] = $jar;
    $page = http_raw('GET', '/signin', array(), $jar, false, '');
    $csrf_by_jar[$jar] = csrf_from_html($page['body']);
    http('POST', '/signin/authenticate', array('email' => $users[$key]['email'], 'password' => $password), $jar);
    return $jar;
}

$jar = array();
foreach (array('admin', 'addonly') as $key) {
    $jar[$key] = login($key);
    $dash = http('GET', '/content_calendar', array(), $jar[$key]);
    $csrf_by_jar[$jar[$key]] = csrf_from_html($dash['body']) ?: $csrf_by_jar[$jar[$key]];
    check("0.$key", "login as $key over HTTP (CSRF) and open /content_calendar", $dash['status'] === 200 && strpos($dash['body'], 'cc-') !== false, 'status ' . $dash['status'] . ' csrf ' . ($csrf_by_jar[$jar[$key]] ? 'yes' : 'none'));
}
out("\n");

/* ================================================================ PART 10 (early): PHP settings of the built-in server */
$ini_resp = http_raw('GET', '/__ini', array(), $jar['admin'], false, '');
$ini = is_array($ini_resp['json']) ? $ini_resp['json'] : array();
$ini_txt = '';
foreach (array('memory_limit', 'max_execution_time', 'upload_max_filesize', 'post_max_size', 'max_input_time', 'php_ini') as $k) {
    $ini_txt .= $k . '=' . (isset($ini[$k]) ? $ini[$k] : '?') . '; ';
}
function ini_bytes($v) {
    $v = trim((string) $v);
    if ($v === '-1') {
        return PHP_INT_MAX;
    }
    $n = (float) $v;
    $u = strtolower(substr($v, -1));
    if ($u === 'g') {
        $n *= 1073741824;
    } else if ($u === 'm') {
        $n *= 1048576;
    } else if ($u === 'k') {
        $n *= 1024;
    }
    return $n;
}
$file_size = filesize($fixture);
check('10.1', 'built-in server PHP settings read (upload_max_filesize / post_max_size / memory_limit / max_execution_time)', !empty($ini), $ini_txt);
check('10.2', 'upload_max_filesize and post_max_size are large enough for the ' . round($file_size / 1048576, 2) . ' MB fixture', !empty($ini) && ini_bytes($ini['upload_max_filesize']) > $file_size && ini_bytes($ini['post_max_size']) > $file_size + 65536, $ini_txt);
out("\n");

/* ================================================================ PART 1: upload + progressive parse */
out("--- PART 1: import_parse + import_parse_next ---\n");
$upload = function ($jar_one, $extra = array()) use ($fixture) {
    return http('POST', '/content_calendar/import_parse', array_merge(array('file' => new CURLFile($fixture, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content_Calender_2026.xlsx'), 'calendar_id' => 0, 'file_type' => ''), $extra), $jar_one, true);
};

$parse_all = function ($jar_one) use ($upload) {
    $res = array('ok' => false, 'steps' => array(), 'final' => null, 'begin' => null, 'time_begin' => 0, 'time_total' => 0, 'error' => '');
    $t0 = microtime(true);
    $r = $upload($jar_one);
    $res['time_begin'] = $r['time'];
    $res['begin'] = $r;
    if (!ok($r)) {
        $res['error'] = snippet($r);
        $res['time_total'] = microtime(true) - $t0;
        return $res;
    }
    $token = $r['json']['token'];
    $res['token'] = $token;
    for ($i = 0; $i < 60; $i++) {
        $s = http('POST', '/content_calendar/import_parse_next', array('token' => $token, 'batch' => 2), $jar_one);
        $res['steps'][] = array('time' => $s['time'], 'parsed' => ok($s) ? $s['json']['parsed'] : -1, 'done' => ok($s) ? $s['json']['done'] : false, 'status' => $s['status']);
        if (!ok($s)) {
            $res['error'] = snippet($s);
            break;
        }
        if (!empty($s['json']['done'])) {
            $res['final'] = $s['json'];
            $res['ok'] = true;
            break;
        }
    }
    $res['time_total'] = microtime(true) - $t0;
    return $res;
};

$P = $parse_all($jar['admin']);
$begin = $P['begin']['json'];
check('1.1', 'import_parse accepts the 1.8 MB upload and lists sheets', ok($P['begin']) && (int) $begin['total'] === 32 && $begin['type'] === 'xlsx', ok($P['begin']) ? ('total=' . $begin['total'] . ' type=' . $begin['type'] . ' time=' . round($P['time_begin'], 2) . 's') : $P['error']);
check('1.2', 'import_parse_next loops until done', $P['ok'], $P['ok'] ? (count($P['steps']) . ' calls, parsed=' . $P['final']['parsed'] . '/' . $P['final']['total']) : $P['error']);
$final = $P['final'];
$sheets = $P['ok'] ? $final['sheets'] : array();
$groups = $P['ok'] ? $final['groups'] : array();
if (!$P['ok']) {
    out("Cannot continue without a parsed file.\n");
    exit(1);
}
$token = $P['token'];
$step_times = array_map(function ($s) {
    return round($s['time'], 2);
}, $P['steps']);
out('time to read: begin ' . round($P['time_begin'], 2) . 's + ' . count($P['steps']) . ' parse_next calls [' . implode(', ', $step_times) . ']s = total ' . round($P['time_total'], 2) . "s\n");
//controller does not return peak_memory (the library does, the controller drops it): measured server-side by the router instead
out("peak_memory key present in controller responses: " . (isset($final['peak_memory']) ? 'yes' : 'no (read from the server-side router log below)') . "\n");

check('1.3', 'sheet count = 32 in the final response', count($sheets) === 32, 'sheets=' . count($sheets) . ' groups=' . count($groups));

//review table
out("\nReview table (sheets):\n");
out(sprintf("%-3s %-24s %-22s %-8s %4s %-13s %5s %5s %5s %5s %5s %-23s\n", '#', 'sheet', 'client', 'source', 'hdr', 'status', 'items', 'undat', 'spec', 'skip', 'swap', 'dates'));
$sheet_by_index = array();
foreach ($sheets as $s) {
    $sheet_by_index[$s['index']] = $s;
    out(sprintf("%-3d %-24s %-22s %-8s %4d %-13s %5d %5d %5d %5d %5d %-23s\n", $s['index'], mb_substr(trim($s['name']), 0, 24), mb_substr($s['client_name'], 0, 22), isset($s['client_source']) ? $s['client_source'] : '', $s['header_row'], $s['status'] . ($s['checked'] ? '*' : ''), $s['content_rows'], $s['without_date_rows'], $s['special_day_rows'], $s['skipped_rows'], $s['swap_flags'], $s['date_min'] . '..' . $s['date_max']));
}
out("\nReview table (client groups):\n");
foreach ($groups as $g) {
    out(sprintf("  %-32s checked=%d cal=%d match=%d  [%s]\n", $g['client_name'], $g['checked'] ? 1 : 0, $g['is_content_calendar'] ? 1 : 0, $g['match_calendar_id'], implode(' | ', array_map('trim', $g['sheet_names']))));
}
out("\n");

function sheet_idx($sheets, $re) {
    foreach ($sheets as $s) {
        if (preg_match($re, trim($s['name']))) {
            return (int) $s['index'];
        }
    }
    return -1;
}

function group_of($groups, $idx) {
    foreach ($groups as $g) {
        if (in_array($idx, $g['sheet_indexes'], true)) {
            return $g;
        }
    }
    return null;
}

//1.4 November special days
$nov = sheet_idx($sheets, '/^november\s*special/i');
$nov_g = $nov >= 0 ? group_of($groups, $nov) : null;
check('1.4', 'November special days: not_calendar and unchecked', $nov >= 0 && $sheet_by_index[$nov]['status'] === 'not_calendar' && !$sheet_by_index[$nov]['checked'] && empty($sheet_by_index[$nov]['is_content_calendar']) && $nov_g && !$nov_g['checked'], $nov >= 0 ? ('status=' . $sheet_by_index[$nov]['status'] . ' checked=' . var_export($sheet_by_index[$nov]['checked'], true) . ' group.checked=' . var_export($nov_g ? $nov_g['checked'] : null, true)) : 'sheet not found');

//1.5 Metro
$m1 = sheet_idx($sheets, '/^metro\s*design/i');
$m2 = sheet_idx($sheets, '/^metro\s*video/i');
$gm = $m1 >= 0 ? group_of($groups, $m1) : null;
$metro_groups = 0;
foreach ($groups as $g) {
    if (in_array($m1, $g['sheet_indexes'], true) || in_array($m2, $g['sheet_indexes'], true)) {
        $metro_groups++;
    }
}
check('1.5', 'Metro Design + metro video -> ONE client "Metro"', $gm && in_array($m2, $gm['sheet_indexes'], true) && $gm['client_name'] === 'Metro' && $metro_groups === 1, $gm ? ('client=' . $gm['client_name'] . ' sheets=' . implode('|', array_map('trim', $gm['sheet_names'])) . ' groups_touching=' . $metro_groups) : 'not found');

//1.6 Nano
$n1 = sheet_idx($sheets, '/^nano\s*des/i');
$n2 = sheet_idx($sheets, '/^nano\s*video/i');
$gn = $n1 >= 0 ? group_of($groups, $n1) : null;
check('1.6', 'Nano Desgin + Nano video -> "Nano Hospitals"', $gn && in_array($n2, $gn['sheet_indexes'], true) && $gn['client_name'] === 'Nano Hospitals', $gn ? ('client=' . $gn['client_name'] . ' sheets=' . implode('|', array_map('trim', $gn['sheet_names']))) : 'not found');

//1.7 HIP
$h1 = sheet_idx($sheets, '/^healthin\s*pocket/i');
$h2 = sheet_idx($sheets, '/^hip\s*video/i');
$gh = $h1 >= 0 ? group_of($groups, $h1) : null;
check('1.7', 'Healthin Pocket + HIP Video together', $gh && in_array($h2, $gh['sheet_indexes'], true), $gh ? ('client=' . $gh['client_name'] . ' sheets=' . implode('|', array_map('trim', $gh['sheet_names']))) : 'not found');

//1.8 names from the sheet name
foreach (array('Dheemahi' => '/^dheemahi/i', 'Organic Mohan' => '/^organic\s*mohan/i', 'DPIS' => '/^dpis/i') as $expect => $re) {
    $i = sheet_idx($sheets, $re);
    $g = $i >= 0 ? group_of($groups, $i) : null;
    check('1.8 ' . $expect, "client name '$expect' taken from the sheet name", $i >= 0 && $sheet_by_index[$i]['client_name'] === $expect && $g && $g['client_name'] === $expect, $i >= 0 ? ("sheet='" . trim($sheet_by_index[$i]['name']) . "' client='" . $sheet_by_index[$i]['client_name'] . "' source=" . (isset($sheet_by_index[$i]['client_source']) ? $sheet_by_index[$i]['client_source'] : '?') . " group='" . ($g ? $g['client_name'] : '-') . "'") : 'sheet not found');
}
out("\n");

/* ---------------------------------------------------------------- group helpers */
$ready_groups = array();
foreach ($groups as $g) {
    if (!empty($g['is_content_calendar']) && !empty($g['checked'])) {
        $ready_groups[] = $g;
    }
}
out('ready groups (content calendar + checked): ' . count($ready_groups) . ' of ' . count($groups) . "\n");

//the new-client name: the group name, or "ZZ E2E <name>" when a calendar of that name already exists locally
function target_name($g) {
    global $baseline_cal_names;
    $n = trim(preg_replace('/\s+/u', ' ', $g['client_name']));
    if (isset($baseline_cal_names[mb_strtolower($n, 'UTF-8')])) {
        return 'ZZ E2E ' . $n;
    }
    return $n;
}

function payload_group($g, $target, $new_name, $merges = array()) {
    return array(
        'key' => $g['key'], 'checked' => true, 'client_name' => $g['client_name'], 'target' => $target, 'new_name' => $new_name,
        'sheet_indexes' => $g['sheet_indexes'], 'header_rows' => (object) array(), 'mapping_by_header' => (object) array(),
        'value_merges' => $merges ? $merges : (object) array(), 'leave_as_text' => array()
    );
}

$options = array('year' => 2026, 'carry_blank_date' => false, 'import_without_date' => true, 'skip_duplicates' => true, 'fix_swapped_dates' => true, 'blank_status' => 'Planned', 'set_targets_from_counts' => false);

function preview_payload($token, $groups_payload, $options, $detail_key = '', $detail_index = '') {
    return array('token' => $token, 'options' => $options, 'groups' => $groups_payload, 'detail_group' => $detail_key, 'detail_index' => $detail_index);
}

function find_entry($resp, $key) {
    if (!is_array($resp) || empty($resp['groups'])) {
        return null;
    }
    foreach ($resp['groups'] as $e) {
        if ($e['key'] === $key) {
            return $e;
        }
    }
    return null;
}

/* ================================================================ PART 2: preview options */
out("--- PART 2: import_preview ---\n");
$all_payload_groups = array();
foreach ($ready_groups as $g) {
    $all_payload_groups[] = payload_group($g, 'new', target_name($g));
}
$t = microtime(true);
$pr_all = post_payload('/content_calendar/import_preview', preview_payload($token, $all_payload_groups, $options), $jar['admin']);
$pr_all_time = microtime(true) - $t;
check('2.0', 'import_preview of ALL ready groups (dry run, nothing written)', ok($pr_all) && count($pr_all['json']['groups']) === count($ready_groups), ok($pr_all) ? (count($pr_all['json']['groups']) . ' groups in ' . round($pr_all_time, 2) . 's') : snippet($pr_all));

$preview_summary = array();
$merge_index = array();   //group key => target_key => from => to (accepted)
$all_suggestions = array();
if (ok($pr_all)) {
    foreach ($pr_all['json']['groups'] as $e) {
        $preview_summary[$e['key']] = $e;
        foreach ($e['merge_suggestions'] as $s) {
            $all_suggestions[] = array($e['client_name'], $s);
            if (!empty($s['accept'])) {
                $merge_index[$e['key']][$s['target_key']][$s['from']] = $s['to'];
            }
        }
    }
}

//detail previews
$detail = function ($g, $idx) use ($token, $options, $jar) {
    $r = post_payload('/content_calendar/import_preview', preview_payload($token, array(payload_group($g, 'new', target_name($g))), $options, $g['key'], $idx), $jar['admin']);
    return ok($r) ? find_entry($r['json'], $g['key']) : null;
};

$em = $gm ? $detail($gm, $m1) : null;
$en = $gn ? $detail($gn, $n1) : null;
$sheet_hdr = function ($entry) {
    $o = array();
    if ($entry) {
        foreach ($entry['sheets'] as $s) {
            $o[trim($s['name'])] = (int) $s['header_row'];
        }
    }
    return $o;
};
$hm = $sheet_hdr($em);
$hn = $sheet_hdr($en);
check('2.1', 'Metro preview: header_row 3 on both sheets', $em && count($hm) === 2 && count(array_unique($hm)) === 1 && reset($hm) === 3, json_encode($hm) . ($em ? ' to_import=' . $em['summary']['to_import'] . ' undated=' . $em['summary']['without_date'] . ' special=' . $em['summary']['special_days'] : ''));
check('2.2', 'Nano preview: header_row 2 on both sheets', $en && count($hn) === 2 && count(array_unique($hn)) === 1 && reset($hn) === 2, json_encode($hn) . ($en ? ' to_import=' . $en['summary']['to_import'] . ' undated=' . $en['summary']['without_date'] . ' special=' . $en['summary']['special_days'] : ''));

//value merges for Awareness / Carousel / Posted
$found_to = array();
foreach ($all_suggestions as $pair) {
    $to = $pair[1]['to'];
    foreach (array('Awareness', 'Carousel', 'Posted') as $want) {
        if (strcasecmp($to, $want) === 0) {
            $found_to[$want][] = $pair[0] . ': "' . $pair[1]['from'] . '"->"' . $to . '" (' . $pair[1]['target_key'] . ', ' . $pair[1]['count_from'] . '+' . $pair[1]['count_to'] . ', accept=' . ($pair[1]['accept'] ? 'y' : 'n') . ')';
        }
    }
}
check('2.3a', 'value merge suggestion -> Awareness (Metro)', !empty($found_to['Awareness']), isset($found_to['Awareness']) ? implode(' ; ', array_slice($found_to['Awareness'], 0, 3)) : 'none');
check('2.3b', 'value merge suggestion -> Carousel (Nano)', !empty($found_to['Carousel']), isset($found_to['Carousel']) ? implode(' ; ', array_slice($found_to['Carousel'], 0, 3)) : 'none');
check('2.3c', 'value merge suggestion -> Posted (some client)', !empty($found_to['Posted']), isset($found_to['Posted']) ? implode(' ; ', array_slice($found_to['Posted'], 0, 3)) : 'none in ' . count($all_suggestions) . ' suggestions across all groups (targets: ' . implode(',', array_unique(array_map(function ($p) {
                    return $p[1]['target_key'];
                }, $all_suggestions))) . ')');
//the merge machinery on Metro Design ALONE (in the 2-sheet group its Goal column becomes free text because of the video sheet)
if ($gm) {
    $solo = $gm;
    $solo['sheet_indexes'] = array($m1);
    $rs = post_payload('/content_calendar/import_preview', preview_payload($token, array(payload_group($solo, 'new', target_name($gm))), $options, $gm['key'], $m1), $jar['admin']);
    $es_solo = ok($rs) ? find_entry($rs['json'], $gm['key']) : null;
    $solo_hits = array();
    if ($es_solo) {
        foreach ($es_solo['merge_suggestions'] as $s) {
            if (strcasecmp($s['to'], 'Awareness') === 0) {
                $solo_hits[] = '"' . $s['from'] . '"->"' . $s['to'] . '" (' . $s['target_key'] . ' accept=' . ($s['accept'] ? 'y' : 'n') . ')';
            }
        }
    }
    check('2.3a2', 'merge suggestion -> Awareness appears when Metro Design is previewed alone', count($solo_hits) > 0, count($solo_hits) ? implode(' ; ', array_slice($solo_hits, 0, 3)) : 'none');
    $metro_goal_cols = array();
    if ($em) {
        foreach ($em['sheets'] as $s) {
            $metro_goal_cols[] = trim($s['name']);
        }
    }
}
$sug_per = array();
foreach ($all_suggestions as $pair) {
    $sug_per[$pair[0]] = (isset($sug_per[$pair[0]]) ? $sug_per[$pair[0]] : 0) + 1;
}
info('2.3d', 'merge suggestions per client', json_encode($sug_per));

//Organic Mohan swap flags
$gom = ($i = sheet_idx($sheets, '/^organic\s*mohan/i')) >= 0 ? group_of($groups, $i) : null;
$eom = $gom ? $detail($gom, $i) : null;
$swap = $eom ? $eom['summary']['swap_flags'] : null;
$swap_ok = $swap && $swap['count'] >= 1 && strpos(json_encode($swap['examples']), '2026-01-07') !== false && strpos(json_encode($swap['examples']), '2026-07-01') !== false && !empty($swap['fixed']);
check('2.4', 'Organic Mohan: swap flag 2026-01-07 -> 2026-07-01 (fix on)', $swap_ok, $swap ? json_encode($swap) : 'no preview');

//naati without date
$gna = ($i = sheet_idx($sheets, '/^naati/i')) >= 0 ? group_of($groups, $i) : null;
$ena = $gna ? $detail($gna, $i) : null;
check('2.5', 'naati fresh: rows without a date are planned as undated (header_row 3)', $ena && $ena['summary']['without_date'] >= 1 && (int) $ena['header_row'] === 3, $ena ? ('without_date=' . $ena['summary']['without_date'] . ' to_import=' . $ena['summary']['to_import'] . ' special=' . $ena['summary']['special_days'] . ' hdr=' . $ena['header_row']) : 'no preview');

//Kakubal Design 01 from sheet (planned numbers; item level is verified in DB after the run)
$gk = ($i = sheet_idx($sheets, '/^kakubal\s*design/i')) >= 0 ? group_of($groups, $i) : null;
$ek = $gk ? $detail($gk, $i) : null;
check('2.6', 'Kakubal: undated rows planned (text like "Design 01" is kept from the sheet; DB-verified in 3.x)', $ek && $ek['summary']['without_date'] >= 20, $ek ? ('without_date=' . $ek['summary']['without_date'] . ' to_import=' . $ek['summary']['to_import'] . ' skipped=' . $ek['summary']['skipped'] . ' ' . json_encode(array_slice($ek['skipped'], 0, 1), JSON_UNESCAPED_UNICODE)) : 'no preview');

//SPN week skip / MAY MONTH section
$gs = ($i = sheet_idx($sheets, '/^spn$/i')) >= 0 ? group_of($groups, $i) : null;
$es = $gs ? $detail($gs, $i) : null;
$week_cols = array();
if ($es && !empty($es['columns'])) {
    foreach ($es['columns'] as $c) {
        if (preg_match('/^(week|day|days|weekday)/i', trim($c['header']))) {
            $week_cols[] = $c['letter'] . ':' . $c['header'] . ' weekday_only=' . var_export($c['weekday_only'], true) . ' map=' . $c['map']['target'];
        }
    }
}
$week_skipped = $es && !empty($es['columns']) ? count(array_filter($es['columns'], function ($c) {
            return preg_match('/^(week|day|days|weekday)/i', trim($c['header'])) && $c['map']['target'] === 'skip';
        })) : 0;
check('2.7a', 'SPN: Week column mapped to skip by default', $es && count($week_cols) && $week_skipped === count($week_cols), $es ? ('week-like columns: ' . ($week_cols ? implode(' | ', $week_cols) : 'none') . ' headers=' . implode(',', array_map(function ($c) {
                    return $c['letter'] . '=' . trim($c['header']) . '->' . $c['map']['target'];
                }, array_slice($es['columns'], 0, 8)))) : 'no preview');
check('2.7b', 'SPN: plan ok, month section titles are not rows', $es && $es['ok'] && $es['summary']['to_import'] > 0, $es ? ('to_import=' . $es['summary']['to_import'] . ' undated=' . $es['summary']['without_date'] . ' skipped=' . $es['summary']['skipped'] . ' special=' . $es['summary']['special_days']) : 'no preview');
out("\n");

/* ================================================================ PART 3: import_run as admin */
out("--- PART 3: import_run (admin, all ready groups) ---\n");
$run_groups = array();
foreach ($ready_groups as $g) {
    $run_groups[] = payload_group($g, 'new', target_name($g), isset($merge_index[$g['key']]) ? $merge_index[$g['key']] : array());
}
$run_id = bin2hex(random_bytes(20));
$run_payload = array('token' => $token, 'run_id' => $run_id, 'options' => $options, 'groups' => $run_groups);
$t = microtime(true);
$run = post_payload('/content_calendar/import_run', $run_payload, $jar['admin']);
$run_time = microtime(true) - $t;
check('3.0', 'import_run completes', ok($run), ok($run) ? ('all_ok=' . var_export($run['json']['all_ok'], true) . ' run_id=' . $run['json']['run_id'] . ' time=' . round($run_time, 2) . 's totals=' . json_encode($run['json']['totals'])) : snippet($run));
$results = ok($run) ? $run['json']['results'] : array();
$cal_by_name = array();    //client_name (group) => calendar_id
$cal_by_key = array();
$imported_total = 0;
out("\nPer client:\n");
out(sprintf("%-30s %-3s %6s %6s %7s %5s %5s %6s %7s %5s  %s\n", 'client', 'ok', 'import', 'undat', 'special', 'newc', 'newo', 'months', 'skipped', 'plan', 'message'));
$mismatch = array();
foreach ($results as $b) {
    $planned = isset($preview_summary[$b['key']]) ? (int) $preview_summary[$b['key']]['summary']['to_import'] : -1;
    if (!empty($b['success'])) {
        $cal_by_name[$b['client_name']] = (int) $b['calendar_id'];
        $cal_by_key[$b['key']] = (int) $b['calendar_id'];
        $imported_total += (int) $b['imported'];
        if ($planned !== (int) $b['imported']) {
            $mismatch[] = $b['client_name'] . ' plan=' . $planned . ' run=' . $b['imported'];
        }
    }
    out(sprintf("%-30s %-3s %6s %6s %7s %5s %5s %6s %7s %5s  %s\n", mb_substr($b['client_name'], 0, 30), !empty($b['success']) ? 'y' : 'N', isset($b['imported']) ? $b['imported'] : '-', isset($b['without_date']) ? $b['without_date'] : '-', isset($b['special_days_written']) ? $b['special_days_written'] : '-', isset($b['columns_created']) ? $b['columns_created'] : '-', isset($b['options_created']) ? $b['options_created'] : '-', isset($b['months_added']) ? $b['months_added'] : '-', isset($b['skipped']) ? $b['skipped'] : '-', $planned, !empty($b['success']) ? '' : $b['message']));
}
$failed_groups = array_filter($results, function ($b) {
    return empty($b['success']);
});
check('3.1', 'every ready group imported successfully', ok($run) && !count($failed_groups), count($failed_groups) ? implode('; ', array_map(function ($b) {
                    return $b['client_name'] . ': ' . $b['message'];
                }, $failed_groups)) : (count($results) . ' groups OK, ' . $imported_total . ' items'));
check('3.2', 'import_run "imported" equals the import_preview "to_import" for every group (same plan)', !count($mismatch), $mismatch ? implode('; ', $mismatch) : 'all equal');

//DB cross-check of totals
$run_batches = array();
$logs_rows = q("SELECT calendar_id, batch_id, rows_imported, undone FROM rise_cc_import_logs WHERE run_id=?", array($run_id))->getResult();
foreach ($logs_rows as $l) {
    $run_batches[] = $l->batch_id;
}
$db_items = $run_batches ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE deleted=0 AND import_batch_id IN ('" . implode("','", $run_batches) . "')") : 0;
$db_undated_total = $run_batches ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE deleted=0 AND publish_date IS NULL AND import_batch_id IN ('" . implode("','", $run_batches) . "')") : 0;
check('3.3', 'DB: items of the run batches = sum of imported; one log per sheet shares the run_id', $db_items === $imported_total && count($logs_rows) > 0, "db_items=$db_items sum_imported=$imported_total logs=" . count($logs_rows) . " undated=$db_undated_total");

//spot checks
$cal = function ($name) use ($cal_by_name) {
    return isset($cal_by_name[$name]) ? $cal_by_name[$name] : 0;
};
$metro_id = $cal('Metro');
$nano_id = $cal('Nano Hospitals');
$s1 = $metro_id ? q1("SELECT COUNT(*) AS n, MIN(title) AS t FROM rise_cc_items WHERE calendar_id=? AND publish_date='2026-04-01' AND deleted=0", array($metro_id)) : null;
check('3.S1', 'spot: Metro has item(s) on 2026-04-01 (first content day)', $s1 && (int) $s1->n >= 1, $s1 ? ('count=' . $s1->n . " e.g. '" . $s1->t . "'") : 'no Metro calendar');
$s1b = $metro_id ? q1("SELECT label FROM rise_cc_special_days sd JOIN rise_cc_calendars c ON c.id=sd.calendar_id WHERE sd.calendar_id=? AND sd.special_date='2026-04-01'", array($metro_id)) : null;
$s1b = $metro_id ? q1("SELECT label FROM rise_cc_special_days WHERE calendar_id=? AND special_date='2026-04-01'", array($metro_id)) : null;
check('3.S2', "spot: Metro special day 2026-04-01 = \"April Fools' Day\"", $s1b && stripos($s1b->label, 'April Fools') !== false, $s1b ? $s1b->label : 'none');
$s2 = $nano_id ? q1("SELECT COUNT(*) AS n FROM rise_cc_items WHERE calendar_id=? AND publish_date IS NULL AND deleted=0 AND title LIKE 'Adhoc deisgn content tribute%'", array($nano_id)) : null;
$s2b = $nano_id ? q1("SELECT COUNT(*) AS n FROM rise_cc_items WHERE calendar_id=? AND publish_date IS NULL AND deleted=0 AND values_json LIKE '%Adhoc%'", array($nano_id)) : null;
check('3.S3', 'spot: Nano Hospitals Adhoc content row is UNDATED', $s2 && (int) $s2->n === 1 && $s2b && (int) $s2b->n >= 3, $s2 ? ('titled-adhoc undated=' . $s2->n . ' rows with Adhoc value undated=' . $s2b->n) : 'no Nano calendar');
$om = $cal('Organic Mohan');
$s3a = $om ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE calendar_id=? AND publish_date='2026-07-01' AND deleted=0", array($om)) : -1;
$s3b = $om ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE calendar_id=? AND publish_date='2026-01-07' AND deleted=0", array($om)) : -1;
check('3.S4', 'spot: Organic Mohan swapped date fixed (2026-07-01 present, 2026-01-07 absent)', $s3a >= 1 && $s3b === 0, "07-01=$s3a, 01-07=$s3b");
$nf = $cal('Naati Fresh');
$s4 = $nf ? q1("SELECT COUNT(*) AS n, SUM(publish_date IS NULL) AS u FROM rise_cc_items WHERE calendar_id=? AND deleted=0", array($nf)) : null;
check('3.S5', 'spot: Naati Fresh = 16 items of which 2 without date', $s4 && (int) $s4->n === 16 && (int) $s4->u === 2, $s4 ? ('items=' . $s4->n . ' undated=' . $s4->u) : 'no calendar');
$ka = $cal('Kakubal academy');
$s5 = $ka ? q1("SELECT COUNT(*) AS n FROM rise_cc_items WHERE calendar_id=? AND publish_date IS NULL AND deleted=0 AND values_json LIKE '%_src_date_text%Design 01%'", array($ka)) : null;
check('3.S6', 'spot: Kakubal undated item keeps "Design 01" from the sheet (_src_date_text)', $s5 && (int) $s5->n >= 1, $s5 ? ('items with _src_date_text "Design 01": ' . $s5->n) : 'no calendar');
$hp = $cal('Healthin Pocket');
$s6 = $hp ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE calendar_id=? AND deleted=0", array($hp)) : -1;
check('3.S7', 'spot: Healthin Pocket has both sheets (37 + 39 = 76 items)', $s6 === 76, "items=$s6");
$sp = $cal('SPN');
$s7 = $sp ? q1("SELECT COUNT(*) AS n, SUM(title LIKE '%MAY MONTH%' OR title LIKE '%MONTH CONTENT%') AS m FROM rise_cc_items WHERE calendar_id=? AND deleted=0", array($sp)) : null;
check('3.S8', 'spot: SPN 221 items and no "MAY MONTH" section row imported as content', $s7 && (int) $s7->n === 221 && (int) $s7->m === 0, $s7 ? ('items=' . $s7->n . ' month-title items=' . $s7->m) : 'no calendar');
$s8 = $metro_id ? q1("SELECT input_type, options FROM rise_cc_columns WHERE calendar_id=? AND field_key='goal' AND deleted=0", array($metro_id)) : null;
$goal_opts = $s8 ? json_decode($s8->options, true) : null;
if ($s8 && $s8->input_type === 'select') {
    check('3.S9', 'spot: accepted value merge applied (Metro Goal options contain Awareness, not "awarenss"/"awarnes")', is_array($goal_opts) && in_array('Awareness', $goal_opts, true) && !in_array('awarenss', $goal_opts, true) && !in_array('awarnes', $goal_opts, true), 'options=' . json_encode($goal_opts));
} else {
    $goal_vals = array();
    try {
        $goal_vals = $metro_id ? q("SELECT JSON_UNQUOTE(JSON_EXTRACT(values_json,'$.goal')) AS g, COUNT(*) AS n FROM rise_cc_items WHERE calendar_id=? AND deleted=0 AND values_json IS NOT NULL GROUP BY g ORDER BY n DESC LIMIT 6", array($metro_id))->getResult() : array();
    } catch (\Throwable $e) {
        $goal_vals = array();
    }
    info('3.S9', 'Metro Goal column is ' . ($s8 ? $s8->input_type : 'missing') . ' in the 2-sheet group (no option merges possible)', 'top values: ' . implode(', ', array_map(function ($r) {
                        return $r->g . ' x' . $r->n;
                    }, $goal_vals)));
}
//SPN: what became of the Week column (title / custom column)
$spn_titles = $sp ? q("SELECT title, COUNT(*) AS n FROM rise_cc_items WHERE calendar_id=? AND deleted=0 GROUP BY title ORDER BY n DESC LIMIT 4", array($sp))->getResult() : array();
$spn_weekday_titles = $sp ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE calendar_id=? AND deleted=0 AND title REGEXP '^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)[a-z]*$'", array($sp)) : -1;
$spn_week_col = $sp ? q1("SELECT label, input_type FROM rise_cc_columns WHERE calendar_id=? AND field_key='week' AND deleted=0", array($sp)) : null;
check('3.S8b', 'spot: SPN Week column was skipped (no weekday-name titles, no "Week" custom column)', $sp && $spn_weekday_titles === 0 && !$spn_week_col, "items titled with a weekday name=$spn_weekday_titles of 221; Week custom column=" . ($spn_week_col ? $spn_week_col->label . '/' . $spn_week_col->input_type : 'none') . '; top titles: ' . implode(', ', array_map(function ($r) {
                    return $r->title . ' x' . $r->n;
                }, $spn_titles)));
$s9 = $nano_id ? q1("SELECT options FROM rise_cc_columns WHERE calendar_id=? AND field_key='type'", array($nano_id)) : null;
$type_opts = $s9 ? json_decode($s9->options, true) : null;
check('3.S10', 'spot: accepted merge applied (Nano Content Type has Carousel, not "carousal"/"carousle")', is_array($type_opts) && in_array('Carousel', $type_opts, true) && !in_array('carousal', $type_opts, true) && !in_array('carousle', $type_opts, true), $type_opts ? ('options=' . json_encode($type_opts)) : 'none');
out("\n");

/* ================================================================ PART 5 (run now, before part 4 edits): re-upload + import again -> duplicates */
out("--- PART 5: re-upload same file and import again (state straight after part 3) ---\n");
$count_before = (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE deleted=0 AND calendar_id IN (" . implode(',', array_values($cal_by_key)) . ")");
$P2 = $parse_all($jar['admin']);
check('5.1', 'second upload parses again (new token)', $P2['ok'] && $P2['token'] !== $token, $P2['ok'] ? ('total ' . round($P2['time_total'], 2) . 's token differs') : $P2['error']);
$dup_new_total = 0;
$dup_msgs = array();
if ($P2['ok']) {
    $dup_groups = array();
    foreach ($P2['final']['groups'] as $g) {
        if (!empty($g['is_content_calendar']) && !empty($g['checked']) && isset($cal_by_key[$g['key']])) {
            $dup_groups[] = payload_group($g, (string) $cal_by_key[$g['key']], $g['client_name'], isset($merge_index[$g['key']]) ? $merge_index[$g['key']] : array());
        }
    }
    $r2 = post_payload('/content_calendar/import_run', array('token' => $P2['token'], 'options' => $options, 'groups' => $dup_groups), $jar['admin']);
    $dup_run = $r2;
    if (ok($r2)) {
        foreach ($r2['json']['results'] as $b) {
            $dup_new_total += !empty($b['success']) ? (int) $b['imported'] : 0;
            if (empty($b['success']) || (int) $b['imported'] > 0) {
                $dup_msgs[] = $b['client_name'] . ': ' . (!empty($b['success']) ? 'imported ' . $b['imported'] : $b['message']);
            }
        }
    }
    $count_after = (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE deleted=0 AND calendar_id IN (" . implode(',', array_values($cal_by_key)) . ")");
    $dup_reasons = array();
    if (ok($r2)) {
        foreach ($r2['json']['results'] as $b) {
            $dup_reasons[trim(!empty($b['success']) ? 'success' : $b['message'])] = (isset($dup_reasons[trim(!empty($b['success']) ? 'success' : $b['message'])]) ? $dup_reasons[trim(!empty($b['success']) ? 'success' : $b['message'])] : 0) + 1;
        }
    }
    check('5.2', 'import again -> 0 new items (duplicates skipped)', ok($r2) && $dup_new_total === 0 && $count_after === $count_before, ok($r2) ? ("new_items=$dup_new_total db_items before=$count_before after=$count_after; outcomes=" . json_encode($dup_reasons) . ' totals=' . json_encode($r2['json']['totals'])) : snippet($r2));
}
out("\n");

/* ================================================================ PART 4: undated items */
out("--- PART 4: undated list / assign / bulk assign / edit / delete (Nano Hospitals) ---\n");
if ($nano_id) {
    $ul = http('POST', '/content_calendar/undated_list', array('calendar_id' => $nano_id), $jar['admin']);
    $und0 = ok($ul) ? (int) $ul['json']['undated_count'] : -1;
    $db_und0 = (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE calendar_id=? AND deleted=0 AND publish_date IS NULL", array($nano_id));
    check('4.1', 'undated_list for Nano Hospitals', ok($ul) && $und0 === $db_und0 && $und0 > 5 && count($ul['json']['items']) > 0, ok($ul) ? ('undated_count=' . $und0 . ' db=' . $db_und0 . ' total=' . $ul['json']['total'] . ' page_items=' . count($ul['json']['items']) . ' (expected 35 planned)') : snippet($ul));
    $ids = ok($ul) ? array_map(function ($it) {
                return $it['id'];
            }, $ul['json']['items']) : array();
    $assign_date1 = '2026-12-20';
    $assign_date2 = '2026-12-21';
    $pd_before = http('POST', '/content_calendar/page_data', array('calendar_id' => $nano_id, 'year' => 2026, 'month' => 12), $jar['admin']);
    $items_before = ok($pd_before) ? count($pd_before['json']['items']) : -1;
    $tiles_sum = function ($pd) {
        $n = 0;
        foreach ($pd['json']['tiles'] as $t) {
            $n += (int) $t['count'];
        }
        return $n;
    };
    $tiles_before = ok($pd_before) ? $tiles_sum($pd_before) : -1;
    $month_count = function ($pd, $y, $m) {
        foreach ($pd['json']['months'] as $row) {
            if ((int) $row['year'] === $y && (int) $row['month'] === $m) {
                return (int) $row['count'];
            }
        }
        return -1;
    };
    $mc_before = ok($pd_before) ? $month_count($pd_before, 2026, 12) : -1;

    $one = $ids[0];
    $a1 = http('POST', '/content_calendar/undated_assign', array('calendar_id' => $nano_id, 'item_id' => $one, 'publish_date' => $assign_date1), $jar['admin']);
    check('4.2', 'undated_assign one item -> dated, undated_count - 1', ok($a1) && $a1['json']['assigned_count'] === 1 && (int) $a1['json']['undated_count'] === $und0 - 1, ok($a1) ? ('assigned=' . json_encode($a1['json']['assigned']) . ' undated_count=' . $a1['json']['undated_count'] . ' (was ' . $und0 . ') months=' . count($a1['json']['months'])) : snippet($a1));
    $bulk = array($ids[1], $ids[2], $ids[3]);
    $a2 = http('POST', '/content_calendar/undated_assign_bulk', array('calendar_id' => $nano_id, 'ids' => $bulk, 'publish_date' => $assign_date2), $jar['admin']);
    check('4.3', 'undated_assign_bulk three items -> undated_count - 3 more', ok($a2) && $a2['json']['assigned_count'] === 3 && (int) $a2['json']['undated_count'] === $und0 - 4, ok($a2) ? ('assigned=' . json_encode($a2['json']['assigned']) . ' undated_count=' . $a2['json']['undated_count'] . ' skipped=' . $a2['json']['skipped_count']) : snippet($a2));
    $pd_after = http('POST', '/content_calendar/page_data', array('calendar_id' => $nano_id, 'year' => 2026, 'month' => 12), $jar['admin']);
    $assigned_ids = array_merge(array($one), $bulk);
    $present = 0;
    if (ok($pd_after)) {
        foreach ($pd_after['json']['items'] as $it) {
            if (in_array($it['id'], $assigned_ids, true)) {
                $present++;
            }
        }
    }
    $mc_after = ok($pd_after) ? $month_count($pd_after, 2026, 12) : -1;
    check('4.4', 'page_data (2026-12) refreshes: assigned items listed, tiles/month count/undated_count updated', ok($pd_after) && $present === 4 && count($pd_after['json']['items']) === $items_before + 4 && $tiles_sum($pd_after) >= $tiles_before + 0 && $mc_after === $mc_before + 4 && (int) $pd_after['json']['undated_count'] === $und0 - 4, ok($pd_after) ? ("items $items_before->" . count($pd_after['json']['items']) . " assigned_present=$present tiles_sum $tiles_before->" . $tiles_sum($pd_after) . " month_count $mc_before->$mc_after undated_count=" . $pd_after['json']['undated_count']) : snippet($pd_after));

    //edit one undated item (keeps it undated)
    $ul2 = http('POST', '/content_calendar/undated_list', array('calendar_id' => $nano_id), $jar['admin']);
    $edit_item = ok($ul2) ? $ul2['json']['items'][0] : null;
    $edit_ok = false;
    $edit_ev = 'no item';
    if ($edit_item) {
        $new_title = 'E2E edited undated title';
        $se = http('POST', '/content_calendar/save_item', array('calendar_id' => $nano_id, 'id' => $edit_item['id'], 'no_date' => '1', 'f' => array('date' => '', 'type' => $edit_item['type'], 'status' => $edit_item['status'], 'title' => $new_title)), $jar['admin']);
        $row = q1("SELECT title, publish_date FROM rise_cc_items WHERE id=?", array($edit_item['id']));
        $ul3 = http('POST', '/content_calendar/undated_list', array('calendar_id' => $nano_id), $jar['admin']);
        $edit_ok = ok($se) && $row && $row->title === $new_title && $row->publish_date === null && ok($ul3) && (int) $ul3['json']['undated_count'] === $und0 - 4;
        $edit_ev = ok($se) ? ("id=" . $edit_item['id'] . " title='" . $row->title . "' publish_date=" . var_export($row->publish_date, true) . ' undated_count=' . (ok($ul3) ? $ul3['json']['undated_count'] : '?')) : snippet($se);
    }
    check('4.5', 'save_item edits an undated item (stays undated, undated_count unchanged)', $edit_ok, $edit_ev);

    //delete one undated item
    $del_item = ok($ul2) && count($ul2['json']['items']) > 1 ? $ul2['json']['items'][1] : null;
    $del_ok = false;
    $del_ev = 'no item';
    if ($del_item) {
        $dr = http('POST', '/content_calendar/delete_item', array('id' => $del_item['id'], 'calendar_id' => $nano_id), $jar['admin']);
        $row = q1("SELECT deleted FROM rise_cc_items WHERE id=?", array($del_item['id']));
        $ul4 = http('POST', '/content_calendar/undated_list', array('calendar_id' => $nano_id), $jar['admin']);
        $pd4 = http('POST', '/content_calendar/page_data', array('calendar_id' => $nano_id, 'year' => 2026, 'month' => 12), $jar['admin']);
        $del_ok = ok($dr) && $row && (int) $row->deleted === 1 && ok($ul4) && (int) $ul4['json']['undated_count'] === $und0 - 5 && ok($pd4) && (int) $pd4['json']['undated_count'] === $und0 - 5;
        $del_ev = ok($dr) ? ('id=' . $del_item['id'] . ' deleted=' . $row->deleted . ' undated_count list=' . (ok($ul4) ? $ul4['json']['undated_count'] : '?') . ' page_data=' . (ok($pd4) ? $pd4['json']['undated_count'] : '?') . " (expected " . ($und0 - 5) . ')') : snippet($dr);
    }
    check('4.6', 'delete_item removes an undated item, undated_count - 1 in list and page_data', $del_ok, $del_ev);
    $nano_deleted_by_test = $del_ok ? 1 : 0;
} else {
    check('4.0', 'Nano Hospitals calendar exists for part 4', false, 'not imported');
    $nano_deleted_by_test = 0;
}
out("\n");

/* ================================================================ PART 6: undo */
out("--- PART 6: import_undo (run_id) ---\n");
$calendars_before_undo = array_values($cal_by_key);
$cols_before = array();
foreach ($calendars_before_undo as $cid) {
    $cols_before[$cid] = (int) scalar("SELECT COUNT(*) FROM rise_cc_columns WHERE calendar_id=? AND deleted=0", array($cid));
}
$cols_total_before = array_sum($cols_before);
$active_before = $run_batches ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE deleted=0 AND import_batch_id IN ('" . implode("','", $run_batches) . "')") : 0;
$ud = http('POST', '/content_calendar/import_undo', array('run_id' => $run_id), $jar['admin']);
$active_after = $run_batches ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE deleted=0 AND import_batch_id IN ('" . implode("','", $run_batches) . "')") : -1;
$undated_active_after = $run_batches ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE deleted=0 AND publish_date IS NULL AND import_batch_id IN ('" . implode("','", $run_batches) . "')") : -1;
$undone_logs = (int) scalar("SELECT COUNT(*) FROM rise_cc_import_logs WHERE run_id=? AND undone=1", array($run_id));
$cols_after_total = (int) scalar("SELECT COUNT(*) FROM rise_cc_columns WHERE deleted=0 AND calendar_id IN (" . implode(',', $calendars_before_undo) . ")");
$cals_alive = (int) scalar("SELECT COUNT(*) FROM rise_cc_calendars WHERE deleted=0 AND id IN (" . implode(',', $calendars_before_undo) . ")");
check('6.1', 'import_undo(run_id) soft-deletes every item of the run (incl. undated)', ok($ud) && $active_after === 0 && $undated_active_after === 0 && (int) $ud['json']['removed'] === $active_before && $undone_logs === count($logs_rows), ok($ud) ? ('removed=' . $ud['json']['removed'] . ' (active before=' . $active_before . ' = imported ' . $imported_total . ' minus ' . $nano_deleted_by_test . ' deleted in part 4) logs_undone=' . $undone_logs . '/' . count($logs_rows) . ' active_after=' . $active_after . ' undated_after=' . $undated_active_after) : snippet($ud));
check('6.2', 'after undo: clients and columns remain', $cals_alive === count($calendars_before_undo) && $cols_after_total === $cols_total_before && $cols_total_before > count($calendars_before_undo) * 4, "calendars alive $cals_alive/" . count($calendars_before_undo) . " columns $cols_total_before->$cols_after_total");
$ud2 = http('POST', '/content_calendar/import_undo', array('run_id' => $run_id), $jar['admin']);
check('6.3', 'second undo of the same run is refused', is_array($ud2['json']) && empty($ud2['json']['success']), is_array($ud2['json']) ? $ud2['json']['message'] : snippet($ud2));
out("\n");

/* ================================================================ PART 7: add-only user */
out("--- PART 7: add-only user (add only: no add_client / add_column / manage_options / edit) ---\n");
$blank = http('POST', '/content_calendar/save_client', array('name' => 'ZZ E2E AddOnly Target'), $jar['admin']);
$blank_id = ok($blank) ? (int) $blank['json']['calendar_id'] : 0;
$blank_cols = $blank_id ? (int) scalar("SELECT COUNT(*) FROM rise_cc_columns WHERE calendar_id=? AND deleted=0", array($blank_id)) : -1;
$opts_before = array();
foreach (q("SELECT field_key, options FROM rise_cc_columns WHERE calendar_id=? AND is_core=1", array($blank_id))->getResult() as $c) {
    $opts_before[$c->field_key] = $c->options;
}
check('7.0', 'admin created a blank target calendar for the add-only user (core columns only)', $blank_id > 0 && $blank_cols === 4, "calendar_id=$blank_id columns=$blank_cols");
$P3 = $parse_all($jar['addonly']);
check('7.1', 'add-only user can upload + parse; perms in response show no add_client/add_column/manage_options/edit', $P3['ok'] && empty($P3['final']['perms']['add_client']) && empty($P3['final']['perms']['add_column']) && empty($P3['final']['perms']['manage_options']) && empty($P3['final']['perms']['edit']) && !empty($P3['final']['perms']['add']), $P3['ok'] ? json_encode($P3['final']['perms']) : $P3['error']);
if ($P3['ok']) {
    $g3 = null;
    foreach ($P3['final']['groups'] as $g) {
        if ($g['client_name'] === 'Naati Fresh') {
            $g3 = $g;
        }
    }
    $lock_idx = $g3['sheet_indexes'][0];
    //a) preview for a NEW client: locked
    $name_new_a = 'ZZ E2E AddOnly New ' . $tag;
    $pvn = post_payload('/content_calendar/import_preview', preview_payload($P3['token'], array(payload_group($g3, 'new', $name_new_a)), $options, $g3['key'], $lock_idx), $jar['addonly']);
    $en3 = ok($pvn) ? find_entry($pvn['json'], $g3['key']) : null;
    check('7.2', 'review: "new client" is locked for add-only (needs_add_client, can_import=false)', $en3 && $en3['error_code'] === 'needs_add_client' && !$en3['can_import'], $en3 ? ('error_code=' . $en3['error_code'] . ' can_import=' . var_export($en3['can_import'], true) . ' error=' . $en3['error']) : snippet($pvn));
    //b) preview into the blank existing calendar: locked columns / options / special days
    $pve = post_payload('/content_calendar/import_preview', preview_payload($P3['token'], array(payload_group($g3, (string) $blank_id, $g3['client_name'])), $options, $g3['key'], $lock_idx), $jar['addonly']);
    $ee3 = ok($pve) ? find_entry($pve['json'], $g3['key']) : null;
    $sm = $ee3 ? $ee3['summary'] : null;
    check('7.3', 'review into an existing client shows locked columns / options / special days', $ee3 && $ee3['can_import'] && ((int) $sm['locked_columns'] > 0 || count($sm['new_columns']) === 0) && !empty($sm['special_days_locked']), $sm ? ('locked_columns=' . json_encode($sm['locked_columns']) . ' locked_option_cells=' . json_encode($sm['locked_option_cells']) . ' special_days_locked=' . var_export($sm['special_days_locked'], true) . ' new_columns=' . count($sm['new_columns']) . ' new_options=' . count($sm['new_options']) . ' to_import=' . $sm['to_import']) : snippet($pve));
    //c) run with target new -> refused, no calendar created
    $runn = post_payload('/content_calendar/import_run', array('token' => $P3['token'], 'options' => $options, 'groups' => array(payload_group($g3, 'new', $name_new_a))), $jar['addonly']);
    $created_new = (int) scalar("SELECT COUNT(*) FROM rise_cc_calendars WHERE name=?", array($name_new_a));
    $rn = ok($runn) ? $runn['json']['results'][0] : null;
    check('7.4', 'run with "new client": nothing created (no calendar)', ok($runn) && $rn && empty($rn['success']) && $created_new === 0, $rn ? ('success=' . var_export($rn['success'], true) . ' message=' . $rn['message'] . " calendars named that=$created_new") : snippet($runn));
    //d) run into the blank calendar: rows are imported, but no columns / options / special days
    $special_before = (int) scalar("SELECT COUNT(*) FROM rise_cc_special_days WHERE calendar_id=?", array($blank_id));
    $rune = post_payload('/content_calendar/import_run', array('token' => $P3['token'], 'options' => $options, 'groups' => array(payload_group($g3, (string) $blank_id, $g3['client_name']))), $jar['addonly']);
    $re = ok($rune) ? $rune['json']['results'][0] : null;
    $cols_now = (int) scalar("SELECT COUNT(*) FROM rise_cc_columns WHERE calendar_id=? AND deleted=0", array($blank_id));
    $special_now = (int) scalar("SELECT COUNT(*) FROM rise_cc_special_days WHERE calendar_id=?", array($blank_id));
    $opts_now = array();
    foreach (q("SELECT field_key, options FROM rise_cc_columns WHERE calendar_id=? AND is_core=1", array($blank_id))->getResult() as $c) {
        $opts_now[$c->field_key] = $c->options;
    }
    $items_now = (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE calendar_id=? AND deleted=0", array($blank_id));
    $client_calendars_after = (int) scalar("SELECT COUNT(*) FROM rise_cc_calendars WHERE deleted=0");
    check('7.5', 'add-only import: no new columns, options or special days', $re && !empty($re['success']) && (int) $re['columns_created'] === 0 && (int) $re['options_created'] === 0 && (int) $re['special_days_written'] === 0 && $cols_now === $blank_cols && $special_now === $special_before && $opts_now === $opts_before, $re ? ('imported=' . $re['imported'] . ' columns_created=' . $re['columns_created'] . ' options_created=' . $re['options_created'] . ' special_days_written=' . $re['special_days_written'] . ' special_days_locked=' . var_export($re['special_days_locked'], true) . " | DB columns $blank_cols->$cols_now special_days $special_before->$special_now core options unchanged=" . var_export($opts_now === $opts_before, true) . " items=$items_now") : snippet($rune));
    //e) the add-only user cannot undo someone else's run (already undone) / and cannot see admin tokens
    $udx = http('POST', '/content_calendar/import_undo', array('run_id' => $run_id), $jar['addonly']);
    check('7.6', 'add-only user cannot undo the admin run (403)', $udx['status'] === 403 || (is_array($udx['json']) && empty($udx['json']['success'])), 'status ' . $udx['status'] . ' ' . (is_array($udx['json']) ? $udx['json']['message'] : ''));
}
out("\n");

/* ================================================================ PART 8: create-client path */
out("--- PART 8: create-client path (admin) ---\n");
$unique = 'ZZ E2E New Client ' . $tag;
$P4 = $parse_all($jar['admin']);
if ($P4['ok']) {
    $g4 = null;
    foreach ($P4['final']['groups'] as $g) {
        if ($g['client_name'] === 'Dheemahi') {
            $g4 = $g;
        }
    }
    check('8.0', 'unique client name does not exist before', (int) scalar("SELECT COUNT(*) FROM rise_cc_calendars WHERE deleted=0 AND LOWER(name)=?", array(mb_strtolower($unique))) === 0, $unique);
    $r8 = post_payload('/content_calendar/import_run', array('token' => $P4['token'], 'options' => $options, 'groups' => array(payload_group($g4, 'new', $unique))), $jar['admin']);
    $b8 = ok($r8) ? $r8['json']['results'][0] : null;
    $cal8 = $b8 && !empty($b8['success']) ? (int) $b8['calendar_id'] : 0;
    $row8 = $cal8 ? q1("SELECT name, deleted FROM rise_cc_calendars WHERE id=?", array($cal8)) : null;
    $cols8 = $cal8 ? q("SELECT field_key, label, is_core FROM rise_cc_columns WHERE calendar_id=? AND deleted=0 ORDER BY sort_order", array($cal8))->getResult() : array();
    $items8 = $cal8 ? (int) scalar("SELECT COUNT(*) FROM rise_cc_items WHERE calendar_id=? AND deleted=0", array($cal8)) : 0;
    $months8 = $cal8 ? (int) scalar("SELECT COUNT(*) FROM rise_cc_months WHERE calendar_id=?", array($cal8)) : 0;
    check('8.1', 'new client calendar created by the import, with columns, months and items', $b8 && !empty($b8['success']) && !empty($b8['is_new_client']) && $row8 && $row8->name === $unique && count($cols8) >= 5 && $items8 === 10, $b8 ? ('calendar_id=' . $cal8 . " name='" . ($row8 ? $row8->name : '') . "' columns=" . implode(',', array_map(function ($c) {
                            return $c->field_key . ($c->is_core ? '*' : '');
                        }, $cols8)) . " items=$items8 months=$months8 columns_created=" . $b8['columns_created']) : snippet($r8));
    if ($cal8) {
        $pd8 = http('POST', '/content_calendar/page_data', array('calendar_id' => $cal8), $jar['admin']);
        check('8.2', 'page_data of the new calendar returns the columns', ok($pd8) && count($pd8['json']['columns']) === count($cols8), ok($pd8) ? ('columns in page_data=' . count($pd8['json']['columns'])) : snippet($pd8));
    }
    //the same name again -> refused
    $P4b = $parse_all($jar['admin']);
    if ($P4b['ok']) {
        $g4b = null;
        foreach ($P4b['final']['groups'] as $g) {
            if ($g['client_name'] === 'Dheemahi') {
                $g4b = $g;
            }
        }
        $r8b = post_payload('/content_calendar/import_run', array('token' => $P4b['token'], 'options' => $options, 'groups' => array(payload_group($g4b, 'new', strtolower($unique)))), $jar['admin']);
        $b8b = ok($r8b) ? $r8b['json']['results'][0] : null;
        check('8.3', 'creating a client with an existing name (case-insensitive) is refused', $b8b && empty($b8b['success']), $b8b ? $b8b['message'] : snippet($r8b));
    }
} else {
    check('8.0', 'parse for part 8', false, $P4['error']);
}
out("\n");

/* ================================================================ PART 9: lint + pages */
out("--- PART 9: php -l on changed files + page smoke ---\n");
$changed = array();
$git = shell_exec(git_cmd('status --porcelain'));
foreach (explode("\n", (string) $git) as $line) {
    $line = rtrim($line);
    if ($line === '' || strlen($line) < 4) {
        continue;
    }
    $f = trim(substr($line, 3));
    $f = trim($f, '"');
    if (substr($line, 0, 2) === ' D' || substr($line, 0, 2) === 'D ') {
        continue;
    }
    if (substr($f, -1) === '/') {
        foreach (glob(FCPATH . $f . '*.php') as $g) {
            $changed[] = substr($g, strlen(FCPATH));
        }
    } else if (preg_match('/\.php$/i', $f)) {
        $changed[] = $f;
    }
}
$changed = array_values(array_unique($changed));
sort($changed);
$lint_fail = array();
foreach ($changed as $f) {
    $o = array();
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg(FCPATH . $f) . ' 2>&1', $o, $code);
    if ($code !== 0) {
        $lint_fail[] = $f . ': ' . implode(' ', $o);
    }
}
check('9.1', 'php -l on every changed/new PHP file (' . count($changed) . ')', !count($lint_fail) && count($changed) > 0, count($lint_fail) ? implode(' ; ', $lint_fail) : implode(', ', $changed));
//protected paths untouched
$prot = trim((string) shell_exec(git_cmd('status --porcelain -- app/Config/Database.php system app/Controllers/Tasks.php app/Controllers/Events.php app/Controllers/Timesheets.php app/Controllers/Messages.php app/Models/Tasks_model.php app/Models/Events_model.php app/Models/Timesheets_model.php app/Models/Messages_model.php')));
check('9.2', 'Database.php, system/*, Tasks/Events/Timesheets/Messages are not modified (git status)', $prot === '', $prot === '' ? 'clean' : $prot);
//RISE has no /tasks or /timesheets controller: tasks live in Projects::all_tasks, timesheets in Projects::all_timesheets
$pages = array(
    'tasks' => array('/tasks', '/projects/all_tasks'), 'events' => array('/events'), 'timesheets' => array('/timesheets', '/projects/all_timesheets'),
    'messages' => array('/messages', '/messages/inbox'), 'settings' => array('/settings', '/settings/general'), 'content_calendar' => array('/content_calendar')
);
foreach ($pages as $label => $candidates) {
    $trace = array();
    $final_status = 0;
    foreach ($candidates as $path) {
        $r = http('GET', $path, array(), $jar['admin']);
        $step = $path . ' -> ' . $r['status'];
        $hops = 0;
        while (in_array($r['status'], array(301, 302, 303), true) && $r['location'] !== '' && $hops < 3) {
            $next = parse_url($r['location'], PHP_URL_PATH) ?: $path;
            $next = preg_replace('#^/index\.php#', '', $next);
            $r = http('GET', $next, array(), $jar['admin']);
            $step .= ' -> ' . $next . ' ' . $r['status'];
            $hops++;
        }
        $trace[] = $step . ' (' . round($r['time'], 2) . 's, ' . strlen($r['body']) . ' bytes)';
        $final_status = $r['status'];
        if ($final_status === 200) {
            break;
        }
    }
    check('9.3 ' . $label, "GET $label as admin = 200 (after following redirects)", $final_status === 200, implode(' | ', $trace));
}
out("\n");

/* ================================================================ server-side peak memory */
out("--- server-side memory / time per request (router log) ---\n");
$peaks = array();
if (is_file($peaklog)) {
    foreach (file($peaklog, FILE_IGNORE_NEW_LINES) as $line) {
        $p = explode("\t", $line);
        if (count($p) === 4) {
            $k = $p[0];
            if (!isset($peaks[$k])) {
                $peaks[$k] = array('n' => 0, 'real' => 0, 'used' => 0, 'max_t' => 0, 'sum_t' => 0);
            }
            $peaks[$k]['n']++;
            $peaks[$k]['real'] = max($peaks[$k]['real'], (int) $p[1]);
            $peaks[$k]['used'] = max($peaks[$k]['used'], (int) $p[2]);
            $peaks[$k]['max_t'] = max($peaks[$k]['max_t'], (float) $p[3]);
            $peaks[$k]['sum_t'] += (float) $p[3];
        }
    }
}
$overall_real = 0;
foreach ($peaks as $k => $v) {
    if (strpos($k, '/content_calendar/') === 0) {
        out(sprintf("  %-40s calls=%-3d peak(real)=%-9s peak(used)=%-9s max_time=%.2fs total=%.2fs\n", $k, $v['n'], mb($v['real']), mb($v['used']), $v['max_t'], $v['sum_t']));
    }
    $overall_real = max($overall_real, $v['real']);
}
$parse_peak = isset($peaks['/content_calendar/import_parse_next']) ? $peaks['/content_calendar/import_parse_next']['real'] : 0;
$run_peak = isset($peaks['/content_calendar/import_run']) ? $peaks['/content_calendar/import_run']['real'] : 0;
$prev_peak = isset($peaks['/content_calendar/import_preview']) ? $peaks['/content_calendar/import_preview']['real'] : 0;
info('1.9', 'peak memory (server, real) parse_next / preview / run / overall', mb($parse_peak) . ' / ' . mb($prev_peak) . ' / ' . mb($run_peak) . ' / ' . mb($overall_real));
out("\n");

/* ================================================================ summary */
$pass = 0;
$fail = 0;
foreach ($rows as $r) {
    if ($r[2] === 'PASS') {
        $pass++;
    } else if ($r[2] === 'FAIL') {
        $fail++;
    }
}
out("=== RESULT TABLE ===\n");
foreach ($rows as $r) {
    out(sprintf("%-6s %-9s %s%s\n", $r[2], $r[0], $r[1], $r[3] !== '' ? "\n         evidence: " . $r[3] : ''));
}
out("\nTIMES: parse total " . round($P['time_total'], 2) . 's (begin ' . round($P['time_begin'], 2) . 's), preview-all ' . round($pr_all_time, 2) . 's, run-all ' . round($run_time, 2) . "s\n");
out("PEAK MEMORY (server, real): parse_next " . mb($parse_peak) . ', preview ' . mb($prev_peak) . ', run ' . mb($run_peak) . ', overall ' . mb($overall_real) . "\n");
out("PHP settings (built-in server): $ini_txt\n");
out("\nRESULT: $pass passed, $fail failed\n");

@unlink($peaklog);
cleanup();
file_put_contents(FCPATH . 'tests/_tmp_cc_v2_http_e2e_results.txt', $report);
exit($fail ? 1 : 0);
