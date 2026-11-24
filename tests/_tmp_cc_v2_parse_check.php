<?php
/**
 * Temporary CLI check of the Content Calendar import v2 reader / parser on the real fixture. No database needed.
 *
 *   php tests/_tmp_cc_v2_parse_check.php [--verbose]
 *
 * Prints peak memory, elapsed time and sheet count, asserts key facts, exits non-zero on failure.
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');

define('APPPATH', realpath(__DIR__ . '/../app') . '/');
define('WRITEPATH', sys_get_temp_dir() . '/cc_v2_check_write/');
@mkdir(WRITEPATH, 0755, true);

function helper($name) {
    require_once APPPATH . 'Helpers/' . $name . '_helper.php';
}

function app_lang($key) {
    return $key;
}

function log_message($level, $message, $context = array()) {
    
}

require APPPATH . 'Libraries/Content_calendar_import.php';

$verbose = in_array('--verbose', $argv, true);
$file = __DIR__ . '/_tmp_fixtures/Content_Calender_2026.xlsx';
if (!is_file($file)) {
    fwrite(STDERR, "Fixture missing: $file\n");
    exit(2);
}

$failures = array();
$checks = 0;

function check($label, $ok, $detail = "") {
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  PASS  $label\n";
    } else {
        echo "  FAIL  $label" . ($detail !== "" ? "  -> $detail" : "") . "\n";
        $failures[] = $label;
    }
}

$lib = new \App\Libraries\Content_calendar_import();

$t0 = microtime(true);
$names = $lib->list_sheet_names($file, "xlsx");
$t_names = microtime(true) - $t0;
echo "Sheet names: " . count($names) . " in " . round($t_names * 1000) . " ms, peak " . round(memory_get_peak_usage(true) / 1048576, 1) . " MB\n";

$sheets = array();
$summaries = array();
$per_sheet = array();
foreach ($names as $i => $name) {
    $s0 = microtime(true);
    $sheet = $lib->read_one_sheet($file, $name, "xlsx");
    $summary = $lib->sheet_summary($sheet, $i);
    $per_sheet[$i] = array(microtime(true) - $s0, memory_get_peak_usage(true));
    $sheets[$i] = $sheet;
    $summaries[$i] = $summary;
}

$elapsed = microtime(true) - $t0;
$peak = memory_get_peak_usage(true);
echo "\n";
printf("%-3s %-26s %-22s %5s %4s %-14s %5s %5s %5s %5s %5s\n", "#", "sheet", "client", "rows", "hdr", "status", "items", "undat", "spec", "skip", "swap");
foreach ($summaries as $i => $s) {
    printf("%-3d %-26s %-22s %5d %4d %-14s %5d %5d %5d %5d %5d\n", $i, mb_substr($s["name"], 0, 26), mb_substr($s["client_name"], 0, 22), $s["rows"], $s["header_row"], $s["status"] . ($s["checked"] ? "*" : ""), $s["content_rows"], $s["without_date_rows"], $s["special_day_rows"], $s["skipped_rows"], $s["swap_flags"]);
    if ($verbose) {
        echo "      reason=" . $s["reason"] . " error=" . $s["error"] . " range=" . $s["date_min"] . ".." . $s["date_max"] . " counts=" . json_encode($s["monthly_counts"]) . " t=" . round($per_sheet[$i][0] * 1000) . "ms\n";
    }
}

echo "\nSHEETS: " . count($names) . "   ELAPSED: " . round($elapsed, 2) . " s   PEAK MEMORY: " . round($peak / 1048576, 1) . " MB (real), " . round(memory_get_peak_usage(false) / 1048576, 1) . " MB (used)\n\n";

//------------------------------------------------------------------ assertions
function find_sheet($summaries, $pattern) {
    foreach ($summaries as $i => $s) {
        if (preg_match($pattern, $s["name"])) {
            return $i;
        }
    }

    return -1;
}

echo "Assertions\n";
check("sheet count is the fixture's 32 sheets", count($names) === 32, "got " . count($names));
check("performance: peak memory under 256 MB", $peak < 256 * 1048576, round($peak / 1048576) . " MB");
check("performance: whole read under 120 s", $elapsed < 120, round($elapsed, 1) . " s");

//November special days
$i = find_sheet($summaries, '/november\s*special/i');
check("'November special days' sheet exists", $i >= 0);
if ($i >= 0) {
    check("November special days is NOT a content calendar", empty($summaries[$i]["is_content_calendar"]) && empty($summaries[$i]["checked"]), json_encode(array($summaries[$i]["status"], $summaries[$i]["reason"])));
}

//grouping
$groups = $lib->group_sheets($summaries, array());
$by_client = array();
foreach ($groups as $g) {
    $by_client[$g["key"]] = $g;
}

$metro = find_sheet($summaries, '/^metro\s*design/i');
$metro_video = find_sheet($summaries, '/^metro\s*vid/i');
if ($metro >= 0 && $metro_video >= 0) {
    check("Metro and metro video both detect client 'Metro'", $summaries[$metro]["client_name"] === "Metro" && $summaries[$metro_video]["client_name"] === "Metro", $summaries[$metro]["client_name"] . " / " . $summaries[$metro_video]["client_name"]);
    check("Metro + metro video merge into ONE group", $summaries[$metro]["client_norm"] === $summaries[$metro_video]["client_norm"] && isset($by_client[$summaries[$metro]["client_norm"]]) && count($by_client[$summaries[$metro]["client_norm"]]["sheet_indexes"]) === 2);
} else {
    check("Metro + metro video sheets found", false, "metro=$metro metro_video=$metro_video");
}

//design + video pairs land in one group even when the client cells differ a little
$pair_checks = array(
    "Truscan + Truscan video" => array('/^truscan\s*desgin/i', '/^truscan\s*viideo/i'),
    "Rainbow design + video" => array('/^rainbow\s*desgin/i', '/^rainbow\s*video/i'),
    "Sunrise design + video" => array('/^sunrise\s*design/i', '/^sunrise\s*video/i'),
    "Healthin Pocket + HIP Video" => array('/^healthin\s*pocket/i', '/^hip\s*video/i'),
    "Junior toes design + video" => array('/^juniore?\s*toes\s*design/i', '/^junior\s*toes\s*vid/i'),
);
foreach ($pair_checks as $label => $patterns) {
    $first = find_sheet($summaries, $patterns[0]);
    $second = find_sheet($summaries, $patterns[1]);
    $same = false;
    foreach ($groups as $g) {
        if (in_array($first, $g["sheet_indexes"], true) && in_array($second, $g["sheet_indexes"], true)) {
            $same = true;
        }
    }

    check("group: $label are ONE group", $first >= 0 && $second >= 0 && $same, "first=$first second=$second");
}

echo "\nGroups:\n";
foreach ($groups as $g) {
    echo "  " . str_pad($g["client_name"], 30) . " [" . implode(" | ", $g["sheet_names"]) . "]" . (count($g["client_names"]) > 1 ? " names=" . implode("/", $g["client_names"]) : "") . "\n";
}

$nano = find_sheet($summaries, '/^nano/i');
if ($nano >= 0) {
    $nano_all = array();
    foreach ($summaries as $i => $s) {
        if (preg_match('/^nano/i', $s["name"])) {
            $nano_all[] = $i;
        }
    }

    $client_names = array();
    foreach ($nano_all as $i) {
        $client_names[] = $summaries[$i]["client_name"];
    }

    check("Nano sheets detect client 'Nano Hospitals'", count(array_unique($client_names)) === 1 && $client_names[0] === "Nano Hospitals", json_encode($client_names));
    check("Nano sheets merge into one group", count($nano_all) < 2 || count($by_client[$summaries[$nano_all[0]]["client_norm"]]["sheet_indexes"]) === count($nano_all), count($nano_all) . " sheets");
} else {
    check("Nano sheet found", false);
}

$hip = find_sheet($summaries, '/healthin|hip/i');
check("Healthin Pocket / HIP sheet found", $hip >= 0);

//header rows
$expect_headers = array('/janani/i' => 3, '/healthin\s*pocket/i' => 2, '/dheemahi/i' => 1, '/dpis/i' => 3, '/organic\s*mohan/i' => 1, '/naati/i' => 3);
foreach ($expect_headers as $pattern => $row) {
    $i = find_sheet($summaries, $pattern);
    if ($i < 0) {
        check("sheet $pattern found for header row check", false);
        continue;
    }

    check("header row of '" . $summaries[$i]["name"] . "' is $row", (int) $summaries[$i]["header_row"] === $row, "got " . $summaries[$i]["header_row"]);
}

//Janani header counts
$i = find_sheet($summaries, '/janani/i');
if ($i >= 0) {
    $c = $summaries[$i]["monthly_counts"];
    $flat = strtolower(json_encode($c));
    check("Janani header counts found (Design 15, Reel 2, Stories 62)", strpos($flat, "15") !== false && strpos($flat, "62") !== false, $flat);
}

//Adhoc without date (Nano)
$full_plan = null;
$cfg = $lib->default_config();
$perms = array("add" => true, "edit" => true, "manage_options" => true, "add_clients" => true, "add_columns" => true, "admin_manage" => true);
$columns = array();
function plan_of($lib, $index, $sheets, $cfg, $perms) {
    return $lib->build_group_plan(array(array("index" => $index, "sheet" => $sheets[$index], "config" => $cfg)), $cfg, 0, (function () use ($lib) {
                        $m = new ReflectionMethod($lib, "virtual_core_columns");
                        $m->setAccessible(true);
                        return $m->invoke($lib);
                    })(), $perms);
}

if (isset($nano_all) && count($nano_all)) {
    $found_adhoc = false;
    foreach ($nano_all as $i) {
        $plan = plan_of($lib, $i, $sheets, $cfg, $perms);
        foreach ($plan["items"] as $item) {
            $title = isset($item["title"]) ? $item["title"] : "";
            if ($item["date"] === null && stripos($title . " " . json_encode($item["values"] ?? array()), "adhoc") !== false) {
                $found_adhoc = true;
            }
        }
    }

    check("Nano 'Adhoc content' row becomes a WITHOUT-DATE item", $found_adhoc);
}

//Organic Mohan swap flag
$i = find_sheet($summaries, '/organic\s*mohan/i');
if ($i >= 0) {
    $plan = plan_of($lib, $i, $sheets, $cfg, $perms);
    $examples = isset($plan["swap_flags"]["examples"]) ? $plan["swap_flags"]["examples"] : array();
    $has = strpos(json_encode($examples), "2026-01-07") !== false;
    check("Organic Mohan: swap flag for 2026-01-07 (under JULY)", $plan["ok"] && $plan["swap_flags"]["count"] > 0 && $has, "count=" . $plan["swap_flags"]["count"] . " examples=" . json_encode($examples));
    $fixed = false;
    foreach ($plan["items"] as $item) {
        if ($item["date"] === "2026-07-01") {
            $fixed = true;
            break;
        }
    }

    check("Organic Mohan: swapped date is fixed to 2026-07-01 (fix_swapped_dates default on)", $fixed);
}

//date parser unit checks
$parse = array(
    array("April 1, 2026 — Wednesday", 2026, "2026-04-01"),
    array("April 1, 2026 - Wed", 2026, "2026-04-01"),
    array("13/07 2026", 2026, "2026-07-13"),
    array("2026-07-13", 2026, "2026-07-13"),
    array("13/07/2026", 2026, "2026-07-13"),
    array("5 Jul 2026", 2026, "2026-07-05"),
    array("November", 2026, null),
    array("JULY 2026", 2026, null),
    array("MAY MONTH - 2026", 2026, null)
);
foreach ($parse as $p) {
    $got = $lib->parse_date($p[0], $p[1], 0, false);
    check("parse_date('" . $p[0] . "') = " . ($p[2] === null ? "null" : $p[2]), $got === $p[2], var_export($got, true));
}

$serial = $lib->parse_date("46215", 2026, 0, true);
check("parse_date serial 46215 = a 2026 date", is_string($serial) && substr($serial, 0, 4) === "2026", var_export($serial, true));

//month-only text -> without date + _src_date_text, Janani A="0" -> without date
$month_only_found = false;
foreach ($sheets as $i => $sheet) {
    if (empty($summaries[$i]["checked"])) {
        continue;
    }

    $plan = plan_of($lib, $i, $sheets, $cfg, $perms);
    foreach ($plan["items"] as $item) {
        if ($item["date"] === null && isset($item["values"]["_src_date_text"]) && $lib->month_only_hint($item["values"]["_src_date_text"])) {
            $month_only_found = true;
            break 2;
        }
    }
}

check("a month-name-only date cell (e.g. 'November') became a without-date item with _src_date_text", $month_only_found);

$i = find_sheet($summaries, '/janani/i');
if ($i >= 0) {
    $plan = plan_of($lib, $i, $sheets, $cfg, $perms);
    $undated = 0;
    foreach ($plan["items"] as $item) {
        if ($item["date"] === null) {
            $undated++;
        }
    }

    check("Janani plan is ok and has dated items", $plan["ok"] && count($plan["items"]) > $undated, "ok=" . var_export($plan["ok"], true) . " " . $plan["error"] . " items=" . count($plan["items"]) . " undated=$undated");
}

//group plans: all sheets of one client are ONE plan
$g0 = microtime(true);
$group_fail = array();
$group_report = array();
foreach ($groups as $g) {
    if (!$g["is_content_calendar"]) {
        continue;
    }

    $jobs = array();
    foreach ($g["sheet_indexes"] as $idx) {
        $jobs[] = array("index" => $idx, "sheet" => $sheets[$idx], "config" => $cfg);
    }

    $m = new ReflectionMethod($lib, "virtual_core_columns");
    $m->setAccessible(true);
    $plan = $lib->build_group_plan($jobs, $cfg, 0, $m->invoke($lib), $perms);
    $group_report[$g["client_name"]] = array(count($jobs), $plan["ok"], count($plan["items"]), (int) $plan["counts"]["without_date"], count($plan["special_days_to_write"]), count($plan["skipped"]), count($plan["new_columns"]));
    if (!$plan["ok"]) {
        $group_fail[] = $g["client_name"] . ": " . $plan["error"];
    }
}

echo "\nGroup plans (sheets, ok, items, undated, special days, skipped, new columns):\n";
foreach ($group_report as $client => $r) {
    printf("  %-28s sheets=%d ok=%s items=%d undated=%d special=%d skipped=%d newcols=%d\n", mb_substr($client, 0, 28), $r[0], $r[1] ? "y" : "N", $r[2], $r[3], $r[4], $r[5], $r[6]);
}

check("every content-calendar group builds a plan (" . count($group_report) . " groups, " . round(microtime(true) - $g0, 2) . " s)", !count($group_fail), implode("; ", $group_fail));
check("Metro group has both sheets", isset($group_report["Metro"]) && $group_report["Metro"][0] === 2);
check("Nano Hospitals group has both sheets", isset($group_report["Nano Hospitals"]) && $group_report["Nano Hospitals"][0] === 2);

//mapping remembered by normalized header (what the controller's _header_keys / _mapping_for_sheet do): applies to every sheet of a client
$g_metro = null;
foreach ($groups as $g) {
    if ($g["client_name"] === "Metro") {
        $g_metro = $g;
    }
}

if ($g_metro) {
    $first_cfg = $cfg;
    $layout = $lib->analyze_layout($sheets[$g_metro["sheet_indexes"][0]]["rows"], $first_cfg, "Metro");
    $skip_key = "";
    foreach ($layout["info"] as $i => $col) {
        $key = ($col["has_header"] && $col["raw_norm"] !== "") ? $col["raw_norm"] : "column" . strtolower($col["letter"]);
        if ($col["canon"] !== "date" && $col["canon"] !== "title" && $col["canon"] !== "contenttype" && $col["canon"] !== "status" && $col["canon"] !== "specialday" && $col["has_header"]) {
            $skip_key = $key;
            break;
        }
    }

    $by_header = array($skip_key => array("target" => "skip", "type" => "", "label" => ""));
    $jobs = array();
    foreach ($g_metro["sheet_indexes"] as $idx) {
        $mapping = array();
        $lay = $lib->analyze_layout($sheets[$idx]["rows"], $cfg, "Metro");
        foreach ($lay["info"] as $i => $col) {
            $key = ($col["has_header"] && $col["raw_norm"] !== "") ? $col["raw_norm"] : "column" . strtolower($col["letter"]);
            if (isset($by_header[$key])) {
                $mapping[$i] = $by_header[$key];
            }
        }
        $jobs[] = array("index" => $idx, "sheet" => $sheets[$idx], "config" => array("header_row" => 0, "mapping" => $mapping));
    }

    $m = new ReflectionMethod($lib, "virtual_core_columns");
    $m->setAccessible(true);
    $plan = $lib->build_group_plan($jobs, $cfg, 0, $m->invoke($lib), $perms);
    $browser = $lib->plan_for_browser($plan, true, $g_metro["sheet_indexes"][1]);
    $skipped_ok = $skip_key !== "";
    foreach ($plan["sheets"] as $sp) {
        foreach ($sp["columns"] as $col) {
            $k = $col["header"];
            if ($lib->norm($k) === $skip_key && $col["map"]["target"] !== "skip") {
                $skipped_ok = false;
            }
        }
    }

    check("mapping by header ('$skip_key' -> skip) applies to both sheets of the client", $skipped_ok && $plan["ok"]);
    check("plan_for_browser: detail sheet is the one asked for + has columns and preview", $browser["detail_index"] === $g_metro["sheet_indexes"][1] && count($browser["columns"]) > 0 && count($browser["preview_rows"]) > 0);
    check("plan has value_cleanup list and swap_flags + header_counts for the review screen", is_array($plan["value_cleanup"]) && isset($plan["swap_flags"]["examples"]) && isset($browser["summary"]["header_counts"]));
}

//progressive parse storage round trip
$tmp_copy = WRITEPATH . "files_check.xlsx";
copy($file, $tmp_copy);
try {
    $begin = $lib->begin_parse(1, 0, "Content_Calender_2026.xlsx", $tmp_copy, "auto");
    $step = $lib->parse_next_sheets($begin["token"], 1, 2);
    check("begin_parse lists 32 sheets without reading any", $begin["total"] === 32 && $begin["type"] === "xlsx");
    check("parse_next_sheets(2) reads exactly 2 sheets", count($step["sheets"]) === 2 && $step["parsed"] === 2 && !$step["done"]);
    $loaded = $lib->load_sheet_parsed($begin["token"], 1, 0);
    check("a parsed sheet is stored in a temp token file", is_array($loaded) && !empty($loaded["rows"]));
    check("another user cannot load the token", $lib->load_meta($begin["token"], 2) === null);
    $lib->delete_parsed($begin["token"]);
    check("delete_parsed removes the token", $lib->load_meta($begin["token"], 1) === null);
} catch (\Throwable $e) {
    check("progressive parse round trip", false, $e->getMessage() . " @" . basename($e->getFile()) . ":" . $e->getLine());
}

echo "\nFinal peak memory: " . round(memory_get_peak_usage(true) / 1048576, 1) . " MB; total time " . round(microtime(true) - $t0, 2) . " s; $checks checks, " . count($failures) . " failed\n";
if (count($failures)) {
    echo "FAILED:\n - " . implode("\n - ", $failures) . "\n";
    exit(1);
}

echo "ALL OK\n";
exit(0);
