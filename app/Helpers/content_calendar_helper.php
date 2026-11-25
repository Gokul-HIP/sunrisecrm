<?php

/**
 * Content Calendar helpers.
 * Every permission check of the module goes through cc_can(), so a rule can be changed in one place.
 */

if (!function_exists('cc_now')) {

    /**
     * Timestamp used for created_at / updated_at (same pattern as the rest of the repo).
     */
    function cc_now() {
        return get_current_utc_time();
    }

}

if (!function_exists('cc_use_utf8mb4')) {

    /**
     * The app connection is utf8 (3 byte). The cc_* tables are utf8mb4, and content titles often contain emoji,
     * so switch this connection to utf8mb4 once per request. utf8mb4 is a superset, so other queries are not affected.
     */
    function cc_use_utf8mb4($db) {
        static $done = array();

        if (!$db) {
            return;
        }

        if (empty($db->connID)) {
            $db->initialize();
        }

        $key = spl_object_id($db);
        if (!isset($done[$key]) && !empty($db->connID) && is_object($db->connID) && method_exists($db->connID, 'set_charset')) {
            @$db->connID->set_charset('utf8mb4');
            $done[$key] = true;
        }
    }

}

if (!function_exists('cc_english_lang')) {

    /**
     * The English custom_lang.php strings of this module (keys starting with cc_ plus content_calendar*).
     * Used as a fallback for users whose language has no translation of the module yet.
     */
    function cc_english_lang() {
        static $strings = null;

        if ($strings === null) {
            $strings = array();
            $file = APPPATH . "Language/english/custom_lang.php";
            if (is_file($file)) {
                $all = include $file;
                if (is_array($all)) {
                    foreach ($all as $key => $value) {
                        if (strpos($key, "cc_") === 0 || strpos($key, "content_calendar") === 0) {
                            $strings[$key] = $value;
                        }
                    }
                }
            }
        }

        return $strings;
    }

}

if (!function_exists('cc_lang')) {

    /**
     * app_lang() with an English fallback for this module's keys.
     */
    function cc_lang($key) {
        $text = app_lang($key);
        if ($text === "custom_lang.$key" || $text === "default_lang.$key" || $text === $key) {
            $english = cc_english_lang();
            if (isset($english[$key])) {
                return $english[$key];
            }
        }

        return $text;
    }

}

if (!function_exists('cc_js_lang')) {

    /**
     * All cc_* strings for the JavaScript (key => text).
     */
    function cc_js_lang() {
        $result = array();
        foreach (cc_english_lang() as $key => $value) {
            if (strpos($key, "cc_") === 0) {
                $result[$key] = cc_lang($key);
            }
        }

        //a few common strings from the default language file
        foreach (array("cancel", "save", "edit", "delete", "close", "yes", "add") as $key) {
            $result[$key] = app_lang($key);
        }

        return $result;
    }

}

if (!function_exists('cc_permission_keys')) {

    /**
     * Keys that can be assigned to staff users in Settings > Content Calendar access.
     */
    function cc_permission_keys() {
        return array("view", "add", "edit", "delete", "manage_options", "add_client", "add_column");
    }

}

if (!function_exists('cc_can')) {

    /**
     * Check a Content Calendar permission for the logged in user.
     *
     * Keys: view | add | edit | delete | manage_options | add_client | add_column | admin_manage
     *  - admin users always have everything
     *  - any assigned permission key implies view
     *  - admin_manage (delete client calendar, monthly targets) is admin only
     *
     * @param string $key
     * @param object|null $user optional user row (id, is_admin, user_type, status, deleted) instead of the logged in user. Used by tests.
     * @return bool
     */
    function cc_can($key, $user = null) {
        static $cache = array();

        $key = (string) $key;
        $valid_keys = array_merge(cc_permission_keys(), array("admin_manage"));
        if (!in_array($key, $valid_keys, true)) {
            return false;
        }

        if (!$user) {
            $users_model = model("App\Models\Users_model", false);
            $user_id = $users_model->login_user_id();
            if (!$user_id) {
                return false;
            }
            $user_id = (int) $user_id;
            $user = null;
        } else {
            $user_id = (int) (isset($user->id) ? $user->id : 0);
        }

        $cache_key = $user_id . ":" . $key;
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }

        if (!$user) {
            $user = model("App\Models\Users_model", false)->get_one($user_id);
        }

        $result = false;

        if ($user && isset($user->id) && $user->id && $user->user_type === "staff" && (!isset($user->deleted) || !(int) $user->deleted) && (!isset($user->status) || $user->status === "active")) {
            if ((int) $user->is_admin === 1) {
                $result = true;
            } else if ($key === "admin_manage") {
                $result = false;
            } else {
                //non-admin staff: one query per request for all keys of this user
                $user_keys_cache_key = $user_id . ":__keys";
                if (!isset($cache[$user_keys_cache_key])) {
                    $cache[$user_keys_cache_key] = array();
                    try {
                        $permissions_model = model("App\Models\Cc_permissions_model", false);
                        $cache[$user_keys_cache_key] = $permissions_model->get_keys_for_user($user_id);
                    } catch (\Throwable $e) {
                        //table not created yet or db problem: no access
                        log_message('error', '[ERROR] cc_can: {message}', array('message' => $e->getMessage()));
                        $cache[$user_keys_cache_key] = array();
                    }
                }

                $user_keys = $cache[$user_keys_cache_key];
                if ($key === "view") {
                    $result = (bool) array_intersect($user_keys, cc_permission_keys());
                } else {
                    $result = in_array($key, $user_keys, true);
                }
            }
        }

        $cache[$cache_key] = $result;
        return $result;
    }

}

if (!function_exists('cc_slug')) {

    /**
     * Make a field_key from a column label: lowercase, a-z 0-9 and underscore only.
     *
     * @param string $label
     * @return string
     */
    function cc_slug($label) {
        $label = trim((string) $label);
        if (function_exists('mb_strtolower')) {
            $label = mb_strtolower($label, 'UTF-8');
        } else {
            $label = strtolower($label);
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
            if ($converted !== false && $converted !== "") {
                $label = $converted;
            }
        }

        $slug = preg_replace('/[^a-z0-9]+/', '_', $label);
        $slug = trim($slug, '_');
        if ($slug === "") {
            $slug = "field";
        }

        return substr($slug, 0, 60);
    }

}

if (!function_exists('cc_chip_palette')) {

    /**
     * The fixed colour palette (bg, fg).
     */
    function cc_chip_palette() {
        return array(
            "indigo" => array("#e0e7ff", "#3730a3"),
            "rose" => array("#ffe4e6", "#9f1239"),
            "emerald" => array("#d1fae5", "#065f46"),
            "amber" => array("#fef3c7", "#92400e"),
            "grey" => array("#f3f4f6", "#374151"),
            "fuchsia" => array("#fae8ff", "#86198f"),
            "cyan" => array("#cffafe", "#155e75"),
            "orange" => array("#ffedd5", "#9a3412")
        );
    }

}

if (!function_exists('cc_chip_colors')) {

    /**
     * Chip colours for an option name. Known names have fixed colours,
     * every other name gets a colour from the palette by a stable hash of its name.
     *
     * @param string $name
     * @return array [background, foreground]
     */
    function cc_chip_colors($name) {
        $palette = cc_chip_palette();
        $name = trim((string) $name);
        $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);

        $fixed = array(
            "design" => "indigo",
            "reel" => "rose",
            "story" => "emerald",
            "carousel" => "amber",
            "planned" => "grey",
            "in design" => "indigo",
            "scheduled" => "amber",
            "posted" => "emerald"
        );

        if (isset($fixed[$lower])) {
            return $palette[$fixed[$lower]];
        }

        $names = array_keys($palette);
        $index = sprintf("%u", crc32($lower)) % count($names);
        return $palette[$names[$index]];
    }

}

if (!function_exists('cc_pill_style')) {

    /**
     * Generates an inline CSS style string for a pill with guaranteed WCAG >= 4.5:1 contrast.
     *
     * @param string $name Option name or text
     * @param string|null $custom_bg Optional background hex color
     * @param string|null $custom_fg Optional text hex color
     * @return string CSS style attribute string
     */
    function cc_pill_style($name, $custom_bg = null, $custom_fg = null) {
        $name = trim((string)$name);
        if ($name === "" && empty($custom_bg)) {
            return "background: #f3f4f6; color: #374151; font-weight: 600; font-size: 11px; padding: 2px 8px; border-radius: 999px; line-height: 1.4; display: inline-flex; align-items: center;";
        }

        $bg = $custom_bg;
        $fg = $custom_fg;
        if (empty($bg) || empty($fg)) {
            $pair = cc_chip_colors($name);
            if (empty($bg)) $bg = $pair[0];
            if (empty($fg)) $fg = $pair[1];
        }

        // Contrast check
        $calc_contrast = function($hex1, $hex2) {
            $to_lin = function($c) {
                $c = $c / 255.0;
                return ($c <= 0.04045) ? ($c / 12.92) : pow(($c + 0.055) / 1.055, 2.4);
            };
            $lum = function($hex) use ($to_lin) {
                $hex = ltrim($hex, '#');
                if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                $r = hexdec(substr($hex, 0, 2));
                $g = hexdec(substr($hex, 2, 2));
                $b = hexdec(substr($hex, 4, 2));
                return 0.2126 * $to_lin($r) + 0.7152 * $to_lin($g) + 0.0722 * $to_lin($b);
            };
            $l1 = $lum($hex1);
            $l2 = $lum($hex2);
            return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
        };

        try {
            $contrast = $calc_contrast($bg, $fg);
            if ($contrast < 4.5) {
                $dark_c = $calc_contrast($bg, '#0f172a');
                $light_c = $calc_contrast($bg, '#ffffff');
                $fg = ($dark_c >= 4.5 && $dark_c >= $light_c) ? '#0f172a' : (($light_c >= 4.5) ? '#ffffff' : (($dark_c >= 4.5) ? '#0f172a' : (($dark_c > $light_c) ? '#0f172a' : '#ffffff')));
            }
        } catch (\Throwable $e) {
            $fg = '#1e293b';
        }

        return "background: " . esc($bg) . "; color: " . esc($fg) . "; font-weight: 600; font-size: 11px; padding: 2px 8px; border-radius: 999px; line-height: 1.4; display: inline-flex; align-items: center;";
    }

}

if (!function_exists('cc_get_item_display_type')) {

    /**
     * Determines the display content type of an item:
     * - Returns the core Content type if not empty;
     * - Otherwise returns the value of the text column of that calendar whose key
     *   starts with content_type or name matches /content\s*type/i (preferring the core one);
     * - Otherwise empty string.
     *
     * @param object|array $item
     * @param object|array|int|null $calendar
     * @param string|null &$matched_col_key Optional out-param for the column field_key that provided the type
     * @return string
     */
    function cc_get_item_display_type($item, $calendar = null, &$matched_col_key = null) {
        $matched_col_key = null;
        if (!$item) {
            return "";
        }

        // 1. Core Content type if not empty
        $core_type = "";
        if (is_object($item)) {
            $core_type = !empty($item->type) ? (string)$item->type : (!empty($item->content_type) ? (string)$item->content_type : "");
        } else if (is_array($item)) {
            $core_type = !empty($item['type']) ? (string)$item['type'] : (!empty($item['content_type']) ? (string)$item['content_type'] : "");
        }
        $core_type = trim($core_type);
        if ($core_type !== "") {
            $matched_col_key = "type";
            return $core_type;
        }

        // 2. Extract values array
        $values = array();
        if (is_object($item)) {
            if (isset($item->values) && is_array($item->values)) {
                $values = $item->values;
            } else if (!empty($item->values_json)) {
                $values = json_decode($item->values_json, true) ?: array();
            }
        } else if (is_array($item)) {
            if (isset($item['values']) && is_array($item['values'])) {
                $values = $item['values'];
            } else if (!empty($item['values_json'])) {
                $values = json_decode($item['values_json'], true) ?: array();
            }
        }

        if (empty($values)) {
            return "";
        }

        // 3. Resolve calendar_id
        $calendar_id = 0;
        if (is_numeric($calendar) && (int)$calendar > 0) {
            $calendar_id = (int)$calendar;
        } else if (is_object($calendar) && !empty($calendar->id)) {
            $calendar_id = (int)$calendar->id;
        } else if (is_array($calendar) && !empty($calendar['id'])) {
            $calendar_id = (int)$calendar['id'];
        } else if (is_object($item) && !empty($item->calendar_id)) {
            $calendar_id = (int)$item->calendar_id;
        } else if (is_array($item) && !empty($item['calendar_id'])) {
            $calendar_id = (int)$item['calendar_id'];
        }

        if ($calendar_id < 1) {
            foreach ($values as $k => $v) {
                if (preg_match('/^content_type/i', $k) || preg_match('/content\s*type/i', $k)) {
                    $val = trim(is_array($v) ? implode(", ", $v) : (string)$v);
                    if ($val !== "") {
                        $matched_col_key = $k;
                        return $val;
                    }
                }
            }
            return "";
        }

        // Fetch calendar columns
        try {
            $columns = model("App\Models\Cc_columns_model")->get_for_calendar($calendar_id, true);
        } catch (\Throwable $e) {
            $columns = array();
        }

        // First pass: core column (if any text/other)
        foreach ($columns as $col) {
            $k = $col->field_key;
            $lbl = !empty($col->label) ? $col->label : (!empty($col->name) ? $col->name : $k);
            if (!empty($col->is_core) && (preg_match('/^content_type/i', $k) || preg_match('/content\s*type/i', $lbl))) {
                if (isset($values[$k])) {
                    $val = trim(is_array($values[$k]) ? implode(", ", $values[$k]) : (string)$values[$k]);
                    if ($val !== "") {
                        $matched_col_key = $k;
                        return $val;
                    }
                }
            }
        }

        // Second pass: non-core columns
        foreach ($columns as $col) {
            $k = $col->field_key;
            $lbl = !empty($col->label) ? $col->label : (!empty($col->name) ? $col->name : $k);
            if (empty($col->is_core) && (preg_match('/^content_type/i', $k) || preg_match('/content\s*type/i', $lbl))) {
                if (isset($values[$k])) {
                    $val = trim(is_array($values[$k]) ? implode(", ", $values[$k]) : (string)$values[$k]);
                    if ($val !== "") {
                        $matched_col_key = $k;
                        return $val;
                    }
                }
            }
        }

        return "";
    }

}

if (!function_exists('cc_title_key')) {

    /**
     * Comparison key of a title (import duplicate check): trimmed, spaces collapsed, lowercase.
     */
    function cc_title_key($title) {
        $title = preg_replace('/\s+/u', ' ', trim((string) $title));
        return function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    }

}

if (!function_exists('cc_has_task_column')) {

    function cc_has_task_column() {
        static $exists = null;
        if ($exists === null) {
            $db = db_connect('default');
            $res = $db->query("SHOW COLUMNS FROM `rise_cc_items` LIKE 'task_id'");
            $exists = ($res && $res->getNumRows() > 0);
        }
        return $exists;
    }

}

if (!function_exists('cc_get_task_linked_item')) {

    function cc_get_task_linked_item($task_id) {
        $task_id = (int) $task_id;
        if ($task_id < 1 || !cc_has_task_column()) {
            return null;
        }

        try {
            $db = db_connect('default');
            $query = $db->query("SELECT i.id, i.calendar_id, i.title, i.publish_date, c.name as calendar_name
                                 FROM rise_cc_items i
                                 LEFT JOIN rise_cc_calendars c ON c.id = i.calendar_id
                                 WHERE i.task_id = ? AND i.deleted = 0
                                 LIMIT 1", array($task_id));
            $row = ($query && is_object($query)) ? $query->getRow() : null;
            if (!$row) {
                return null;
            }

            $ts = $row->publish_date ? strtotime($row->publish_date) : 0;
            $formatted_date = $ts ? date("j M Y", $ts) : "No date";
            $link_url = get_uri("content_calendar?calendar=" . $row->calendar_id . "&date=" . $row->publish_date . "&item=" . $row->id);

            return (object) array(
                "item_id" => (int) $row->id,
                "calendar_id" => (int) $row->calendar_id,
                "calendar_name" => $row->calendar_name,
                "title" => $row->title,
                "date" => $row->publish_date,
                "formatted_date" => $formatted_date,
                "link_url" => $link_url
            );
        } catch (\Throwable $e) {
            log_message('error', 'cc_get_task_linked_item error: ' . $e->getMessage());
            return null;
        }
    }

}

if (!function_exists('cc_match_project_suggestion')) {

    function cc_match_project_suggestion($calendar, $publish_date, $user = null) {
        if (!$calendar || !$publish_date) {
            return array("status" => "none", "project_id" => null, "project_title" => null, "message" => "");
        }

        $cal_name = is_object($calendar) ? $calendar->name : (isset($calendar["name"]) ? $calendar["name"] : "");
        $cal_client_id = is_object($calendar) ? (int)$calendar->client_id : (isset($calendar["client_id"]) ? (int)$calendar["client_id"] : 0);

        $ts = strtotime($publish_date);
        if (!$ts) {
            return array("status" => "none", "project_id" => null, "project_title" => null, "message" => "");
        }

        $year = (string) date('Y', $ts);
        $monthFull = date('F', $ts); // e.g. "November"
        $monthShort = date('M', $ts); // e.g. "Nov"
        $monthLowerFull = strtolower($monthFull);
        $monthLowerShort = strtolower($monthShort);

        $projects_model = model("App\Models\Projects_model");
        $project_options = array("status" => "open");
        if ($user) {
            $user_type = isset($user->user_type) ? $user->user_type : "staff";
            if ($user_type === "staff") {
                $can_manage = (!empty($user->is_admin) || (isset($user->permissions) && get_array_value($user->permissions, "can_manage_all_projects") == "1"));
                if (!$can_manage && !empty($user->id)) {
                    $project_options["user_id"] = $user->id;
                }
            } else if ($user_type === "client" && !empty($user->client_id)) {
                $project_options["client_id"] = $user->client_id;
            }
        }

        $projects_result = $projects_model->get_details($project_options);
        $open_projects = is_object($projects_result) && method_exists($projects_result, 'getResult') ? $projects_result->getResult() : (is_array($projects_result) ? $projects_result : array());

        // 1. If calendar has CRM client id, prefer projects of that client
        $matches = array();
        if ($cal_client_id > 0) {
            foreach ($open_projects as $p) {
                if ((int) $p->client_id === $cal_client_id) {
                    $normTitle = strtolower($p->title);
                    if (strpos($normTitle, $year) !== false &&
                        (strpos($normTitle, $monthLowerFull) !== false || strpos($normTitle, $monthLowerShort) !== false)) {
                        $matches[] = $p;
                    }
                }
            }
        }

        // 2. Otherwise use the name rule
        if (empty($matches)) {
            $normCal = preg_replace('/[^a-z0-9]/', '', strtolower($cal_name));
            if ($normCal) {
                foreach ($open_projects as $p) {
                    if (strpos($p->title, '|') === false) {
                        continue;
                    }
                    list($partBefore, $partAfter) = explode('|', $p->title, 2);
                    $normProjClient = preg_replace('/[^a-z0-9]/', '', strtolower($partBefore));
                    if (!$normProjClient) {
                        continue;
                    }

                    $clientMatches = ($normCal === $normProjClient)
                        || (strpos($normCal, $normProjClient) === 0)
                        || (strpos($normProjClient, $normCal) === 0);

                    if (!$clientMatches) {
                        continue;
                    }

                    $normAfter = strtolower($partAfter);
                    $monthMatches = (strpos($normAfter, $year) !== false) &&
                        (strpos($normAfter, $monthLowerFull) !== false || strpos($normAfter, $monthLowerShort) !== false);

                    if ($monthMatches) {
                        $matches[] = $p;
                    }
                }
            }
        }

        $cnt = count($matches);
        if ($cnt === 1) {
            $p = $matches[0];
            return array(
                "status" => "single",
                "project_id" => (int) $p->id,
                "project_title" => $p->title,
                "message" => sprintf(cc_lang("cc_suggested_from"), $p->title)
            );
        } else if ($cnt === 0) {
            return array(
                "status" => "none",
                "project_id" => null,
                "project_title" => null,
                "message" => sprintf(cc_lang("cc_no_project_hint"), $monthFull . " " . $year)
            );
        } else {
            return array(
                "status" => "multiple",
                "project_id" => null,
                "project_title" => null,
                "message" => sprintf(cc_lang("cc_multiple_projects_hint"), $cnt)
            );
        }
    }

}

if (!function_exists('cc_validate_task_link')) {

    function cc_validate_task_link($item_id, $duplicate_confirmed = false, $user = null) {
        if (!cc_can("view", $user)) {
            return cc_lang("cc_forbidden");
        }

        $item_id = (int) $item_id;
        if ($item_id < 1) {
            return cc_lang("cc_content_item_required");
        }

        $items_model = model("App\Models\Cc_items_model");
        $item = $items_model->get_active($item_id);
        if (!$item) {
            return cc_lang("cc_item_not_found");
        }

        $cal_model = model("App\Models\Cc_calendars_model");
        $calendar = $cal_model->get_active($item->calendar_id);
        if (!$calendar) {
            return cc_lang("cc_calendar_not_found");
        }

        // Duplicate check
        if (cc_has_task_column() && !empty($item->task_id)) {
            $db = db_connect('default');
            $active_task = $db->query("SELECT id FROM rise_tasks WHERE id = ? AND deleted = 0", array((int) $item->task_id))->getRow();
            if ($active_task && !$duplicate_confirmed) {
                return sprintf(cc_lang("cc_task_exists_warning"), $item->task_id);
            }
        }

        return null;
    }

}

if (!function_exists('cc_has_task_links_table')) {

    function cc_has_task_links_table() {
        static $has_table = null;
        if ($has_table !== null) {
            return $has_table;
        }
        try {
            $db = db_connect('default');
            $res = $db->query("SHOW TABLES LIKE 'rise_cc_task_links'");
            $has_table = ($res && $res->getNumRows() > 0);
        } catch (\Throwable $e) {
            $has_table = false;
        }
        return $has_table;
    }

}

if (!function_exists('cc_get_all_content_types')) {

    function cc_get_all_content_types() {
        static $cached_types = null;
        if ($cached_types !== null) {
            return $cached_types;
        }
        try {
            $db = db_connect('default');
            cc_use_utf8mb4($db);
            $sql = "SELECT c.options 
                    FROM rise_cc_columns c
                    JOIN rise_cc_calendars cal ON cal.id = c.calendar_id AND cal.deleted = 0
                    WHERE c.deleted = 0 
                      AND (c.field_key = 'type' OR c.label LIKE '%Content Type%' OR c.label LIKE '%Type%')
                      AND c.input_type IN ('select', 'multi')
                      AND c.options IS NOT NULL AND c.options != ''";
            $query = $db->query($sql);
            $rows = $query ? $query->getResult() : array();
            $groups = array();
            foreach ($rows as $row) {
                $opts = json_decode($row->options, true);
                if (!is_array($opts)) continue;
                foreach ($opts as $opt) {
                    $opt = trim((string)$opt);
                    if ($opt === '') continue;
                    $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', $opt));
                    if ($norm === '') continue;
                    if (!isset($groups[$norm])) $groups[$norm] = array();
                    if (!isset($groups[$norm][$opt])) $groups[$norm][$opt] = 0;
                    $groups[$norm][$opt]++;
                }
            }
            $types = array();
            foreach ($groups as $norm => $spellings) {
                arsort($spellings);
                $types[] = key($spellings);
            }
            natcasesort($types);
            $cached_types = array_values($types);
        } catch (\Throwable $e) {
            $cached_types = array();
        }
        return $cached_types;
    }

}

if (!function_exists('cc_get_task_link_info')) {

    function cc_get_task_link_info($task_id) {
        $task_id = (int)$task_id;
        if ($task_id < 1) {
            return null;
        }

        try {
            $db = db_connect('default');

            // Verify task exists and is not deleted
            $q_task = $db->query("SELECT id, project_id, deleted, assigned_to, collaborators FROM rise_tasks WHERE id = ?", array($task_id));
            $task_row = ($q_task && is_object($q_task)) ? $q_task->getRow() : null;
            if (!$task_row || (int)$task_row->deleted !== 0) {
                return null;
            }

            // 1. Check rise_cc_task_links
            if (cc_has_task_links_table()) {
                $q_link = $db->query("SELECT * FROM rise_cc_task_links WHERE task_id = ?", array($task_id));
                $link_row = ($q_link && is_object($q_link)) ? $q_link->getRow() : null;
                if ($link_row) {
                    if (!empty($link_row->item_id)) {
                        $item = model("App\Models\Cc_items_model")->get_active((int)$link_row->item_id);
                        if ($item) {
                            $cal = model("App\Models\Cc_calendars_model")->get_active($item->calendar_id);
                            if ($cal) {
                                $type_str = !empty($link_row->content_type) ? (string)$link_row->content_type : cc_get_item_display_type($item, $cal);
                                $type_color_info = cc_chip_colors($type_str);
                                $type_pill_style = cc_pill_style($type_str);

                                $status_str = (string)($item->status ?? '');
                                $status_color_info = cc_chip_colors($status_str);
                                $status_pill_style = cc_pill_style($status_str);

                                $status_options = array();
                                try {
                                    $cols = model("App\Models\Cc_columns_model")->get_for_calendar($cal->id, true);
                                    if (is_array($cols)) {
                                        foreach ($cols as $c) {
                                            if (isset($c->field_key) && $c->field_key === "status" && !empty($c->options_list) && is_array($c->options_list)) {
                                                $status_options = $c->options_list;
                                                break;
                                            }
                                        }
                                    }
                                } catch (\Throwable $e) {}

                                $formatted_date = (string)($item->publish_date ?? '');
                                if ($formatted_date && strtotime($formatted_date)) {
                                    $formatted_date = date("j M Y", strtotime($formatted_date));
                                }
                                return (object) array(
                                    "task_id" => $task_id,
                                    "is_linked" => true,
                                    "is_manual" => false,
                                    "item_id" => (int)$item->id,
                                    "content_type" => $type_str,
                                    "type_color" => $type_color_info[0] ?? '#6b7280',
                                    "type_pill_style" => $type_pill_style,
                                    "status" => $status_str,
                                    "status_color" => $status_color_info[0] ?? '#9ca3af',
                                    "status_pill_style" => $status_pill_style,
                                    "status_options" => $status_options,
                                    "calendar_id" => (int)$cal->id,
                                    "calendar_name" => (string)($cal->name ?? ''),
                                    "publish_date" => (string)($item->publish_date ?? ''),
                                    "formatted_date" => $formatted_date,
                                    "title" => (string)($item->title ?? ''),
                                    "link_url" => get_uri("content_calendar?calendar=" . (int)$cal->id . "&date=" . urlencode((string)($item->publish_date ?? '')) . "&item=" . (int)$item->id)
                                );
                            }
                        }
                        // Linked item or calendar was deleted or missing -> render nothing
                        return null;
                    } else if (!empty($link_row->content_type)) {
                        $type_str = (string)$link_row->content_type;
                        $type_color_info = cc_chip_colors($type_str);
                        $type_pill_style = cc_pill_style($type_str);
                        return (object) array(
                            "task_id" => $task_id,
                            "is_linked" => false,
                            "is_manual" => true,
                            "content_type" => $type_str,
                            "type_color" => $type_color_info[0] ?? '#6b7280',
                            "type_pill_style" => $type_pill_style
                        );
                    }
                    return null;
                }
            }

            // 2. Fallback to rise_cc_items.task_id if rise_cc_task_links row not present
            if (cc_has_task_column()) {
                $q_item = $db->query("SELECT id, calendar_id, publish_date, title, content_type, status, values_json FROM rise_cc_items WHERE task_id = ? AND deleted = 0", array($task_id));
                $item_row = ($q_item && is_object($q_item)) ? $q_item->getRow() : null;
                if ($item_row) {
                    $cal = model("App\Models\Cc_calendars_model")->get_active($item_row->calendar_id);
                    if ($cal) {
                        $type_str = cc_get_item_display_type($item_row, $cal);
                        $type_color_info = cc_chip_colors($type_str);
                        $type_pill_style = cc_pill_style($type_str);

                        $status_str = (string)($item_row->status ?? '');
                        $status_color_info = cc_chip_colors($status_str);
                        $status_pill_style = cc_pill_style($status_str);

                        $status_options = array();
                        try {
                            $cols = model("App\Models\Cc_columns_model")->get_for_calendar($cal->id, true);
                            if (is_array($cols)) {
                                foreach ($cols as $c) {
                                    if (isset($c->field_key) && $c->field_key === "status" && !empty($c->options_list) && is_array($c->options_list)) {
                                        $status_options = $c->options_list;
                                        break;
                                    }
                                }
                            }
                        } catch (\Throwable $e) {}

                        $formatted_date = (string)($item_row->publish_date ?? '');
                        if ($formatted_date && strtotime($formatted_date)) {
                            $formatted_date = date("j M Y", strtotime($formatted_date));
                        }
                        return (object) array(
                            "task_id" => $task_id,
                            "is_linked" => true,
                            "is_manual" => false,
                            "item_id" => (int)$item_row->id,
                            "content_type" => $type_str,
                            "type_color" => $type_color_info[0] ?? '#6b7280',
                            "type_pill_style" => $type_pill_style,
                            "status" => $status_str,
                            "status_color" => $status_color_info[0] ?? '#9ca3af',
                            "status_pill_style" => $status_pill_style,
                            "status_options" => $status_options,
                            "calendar_id" => (int)$cal->id,
                            "calendar_name" => (string)($cal->name ?? ''),
                            "publish_date" => (string)($item_row->publish_date ?? ''),
                            "formatted_date" => $formatted_date,
                            "title" => (string)($item_row->title ?? ''),
                            "link_url" => get_uri("content_calendar?calendar=" . (int)$cal->id . "&date=" . urlencode((string)($item_row->publish_date ?? '')) . "&item=" . (int)$item_row->id)
                        );
                    }
                }
            }

            return null;
        } catch (\Throwable $e) {
            log_message('error', 'cc_get_task_link_info error: ' . $e->getMessage());
            return null;
        }
    }

}

if (!function_exists('cc_save_manual_task_type')) {

    function cc_save_manual_task_type($task_id, $content_type, $user = null) {
        $task_id = (int)$task_id;
        $content_type = trim((string)$content_type);
        if ($task_id < 1) {
            return array("success" => false, "message" => "Invalid task ID");
        }

        if (cc_has_task_links_table()) {
            try {
                $db = db_connect('default');
                if ($content_type === '') {
                    // Empty option chosen -> delete link row if manual (item_id IS NULL or 0)
                    $db->query("DELETE FROM rise_cc_task_links WHERE task_id = ? AND (item_id IS NULL OR item_id = 0)", array($task_id));
                    return array("success" => true);
                }

                $valid_types = cc_get_all_content_types();
                if (!in_array($content_type, $valid_types, true)) {
                    return array("success" => false, "message" => "Content type not in valid list");
                }

                $user_id = $user && !empty($user->id) ? (int)$user->id : 1;
                $db->query("INSERT INTO rise_cc_task_links (task_id, item_id, content_type, created_by, created_at)
                            VALUES (?, NULL, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE content_type = VALUES(content_type)",
                           array($task_id, $content_type, $user_id, date("Y-m-d H:i:s")));
                return array("success" => true);
            } catch (\Throwable $e) {
                return array("success" => false, "message" => $e->getMessage());
            }
        }
        return array("success" => true);
    }

}

if (!function_exists('cc_format_content_description_html')) {

    function cc_format_content_description_html($calendar, $item, $columns) {
        $deep_link = get_uri("content_calendar?calendar=" . $calendar->id . "&date=" . $item->publish_date . "&item=" . $item->id);

        $html_parts = array();
        $text_parts = array();

        // 1. Content link
        $link_escaped = esc($deep_link);
        $html_parts[] = "<p><strong>Content link:</strong> <a href=\"{$link_escaped}\" target=\"_blank\" rel=\"noopener noreferrer\">{$link_escaped}</a></p>";
        $text_parts[] = "Content link: " . $deep_link;

        // 2. Client
        $client_name = esc($calendar->name);
        $html_parts[] = "<p><strong>Client:</strong> {$client_name}</p>";
        $text_parts[] = "Client: " . $calendar->name;

        // 3. Date
        $formatted_date = $item->publish_date;
        if ($item->publish_date && strtotime($item->publish_date)) {
            $formatted_date = date("j M Y", strtotime($item->publish_date));
        }
        $date_escaped = esc($formatted_date);
        $html_parts[] = "<p><strong>Date:</strong> {$date_escaped}</p>";
        $text_parts[] = "Date: " . $formatted_date;

        // 4. Columns in client column order (skipping title, date, status, and any content-type column as type is in top block)
        $values = is_array($item->values ?? null) ? $item->values : (isset($item->values_json) ? json_decode($item->values_json, true) : array());
        if (!is_array($values)) $values = array();

        $seen_cols = array();
        foreach ($columns as $col) {
            $k = $col->field_key;
            $label = !empty($col->label) ? $col->label : (!empty($col->name) ? $col->name : $col->field_key);

            if ($k === "title" || $k === "date" || $k === "type" || $k === "status") {
                continue;
            }
            if (preg_match('/^content_type/i', $k) || preg_match('/content\s*type/i', $label)) {
                continue;
            }

            $norm_key = strtolower(trim($k));
            if (isset($seen_cols[$norm_key])) {
                continue;
            }
            $seen_cols[$norm_key] = true;

            $raw_val = "";
            if (isset($values[$k])) {
                $v = $values[$k];
                if (is_array($v)) {
                    $raw_val = implode(", ", $v);
                } else {
                    $raw_val = (string) $v;
                }
            }

            $trimmed = trim($raw_val);
            if ($trimmed === "") {
                continue;
            }

            $label = !empty($col->label) ? $col->label : (!empty($col->name) ? $col->name : $col->field_key);
            $label_escaped = esc($label);

            $text_parts[] = $label . ": " . $raw_val;

            $val_escaped = esc($raw_val);
            $val_escaped = preg_replace('/\*\*(.*?)\*\*/u', '<strong>$1</strong>', $val_escaped);

            if ($col->input_type === 'link' || preg_match('/^https?:\/\//i', $trimmed)) {
                $url_escaped = esc($trimmed);
                $val_html = "<a href=\"{$url_escaped}\" target=\"_blank\" rel=\"noopener noreferrer\">{$val_escaped}</a>";
            } else {
                $val_html = nl2br($val_escaped);
            }

            if (strpos($raw_val, "\n") !== false) {
                $html_parts[] = "<p><strong>{$label_escaped}:</strong><br>{$val_html}</p>";
            } else {
                $html_parts[] = "<p><strong>{$label_escaped}:</strong> {$val_html}</p>";
            }
        }

        return array(
            "html" => implode("\n", $html_parts),
            "text" => implode("\n\n", $text_parts)
        );
    }

}

if (!function_exists('cc_link_task_to_item')) {

    function cc_link_task_to_item($task_id, $item_id, $user = null) {
        $task_id = (int) $task_id;
        $item_id = (int) $item_id;
        if ($task_id < 1 || $item_id < 1) {
            return array("success" => false, "message" => "Invalid task or item ID");
        }

        $items_model = model("App\Models\Cc_items_model");
        $item = $items_model->get_active($item_id);
        if (!$item) {
            return array("success" => false, "message" => "Content item not found");
        }

        $cal_model = model("App\Models\Cc_calendars_model");
        $calendar = $cal_model->get_active($item->calendar_id);
        if (!$calendar) {
            return array("success" => false, "message" => "Content calendar not found");
        }

        $values = is_array($item->values ?? null) ? $item->values : (isset($item->values_json) ? json_decode($item->values_json, true) : array());

        // 1. Update task_id in rise_cc_items
        if (cc_has_task_column()) {
            $items_model->save_item(array("task_id" => $task_id), $item_id);
        }

        // 2. Insert or replace in rise_cc_task_links
        if (cc_has_task_links_table()) {
            try {
                $db = db_connect('default');
                $user_id = $user && !empty($user->id) ? (int)$user->id : 1;
                $content_type = !empty($item->type) ? (string)$item->type : (!empty($item->content_type) ? (string)$item->content_type : null);
                $db->query("INSERT INTO rise_cc_task_links (task_id, item_id, content_type, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE item_id = VALUES(item_id), content_type = VALUES(content_type)",
                           array($task_id, $item_id, $content_type, $user_id, date("Y-m-d H:i:s")));
            } catch (\Throwable $e) {
                log_message('error', '[ERROR] cc_link_task_to_item task_links insert: ' . $e->getMessage());
            }
        }

        // 3. Add first comment on task
        // "Content milestone: <date> • <client> • <content type> • Goal: <goal> • Open content: <link>"
        try {
            $parts = array();

            if (!empty($item->publish_date)) {
                $ts = strtotime($item->publish_date);
                $parts[] = $ts ? date("j M Y", $ts) : $item->publish_date;
            }

            if (!empty($calendar->name)) {
                $parts[] = $calendar->name;
            }

            $type_val = !empty($item->type) ? (string)$item->type : (!empty($item->content_type) ? (string)$item->content_type : "");
            if ($type_val !== "") {
                $parts[] = $type_val;
            }

            // Goal
            $goal = "";
            if (isset($values["goal"])) {
                $g = $values["goal"];
                $goal = is_array($g) ? implode(", ", $g) : (string)$g;
            }
            if (!empty($goal)) {
                $parts[] = "Goal: " . $goal;
            }

            $link_url = get_uri("content_calendar?calendar=" . $calendar->id . "&date=" . $item->publish_date . "&item=" . $item->id);
            $parts[] = "Open content: <a href='" . esc($link_url) . "' target='_blank' rel='noopener noreferrer'>" . esc($link_url) . "</a>";

            $comment_text = "Content milestone: " . implode(" • ", $parts);

            $tasks_model = model("App\Models\Tasks_model");
            $task_info = $tasks_model->get_one($task_id);

            $comments_model = model("App\Models\Project_comments_model");
            $comment_data = array(
                "created_by" => $user && !empty($user->id) ? (int)$user->id : 1,
                "created_at" => get_current_utc_time(),
                "project_id" => $task_info ? (int)$task_info->project_id : 0,
                "task_id" => $task_id,
                "description" => $comment_text,
                "deleted" => 0
            );

            $save_res = $comments_model->save_comment($comment_data);
            if (!$save_res) {
                $db = db_connect('default');
                $db->query("INSERT INTO rise_project_comments (created_by, created_at, project_id, task_id, description, deleted) VALUES (?, ?, ?, ?, ?, 0)",
                    array($comment_data["created_by"], $comment_data["created_at"], $comment_data["project_id"], $task_id, $comment_text));
            }

            return array("success" => true, "message" => "Linked successfully");
        } catch (\Throwable $e) {
            log_message('error', '[ERROR] cc_link_task_to_item comment: ' . $e->getMessage());
            return array("success" => false, "message" => $e->getMessage());
        }
    }
}

if (!function_exists('cc_get_today_special_days')) {
    /**
     * Get distinct individual special day items for today (or a specific date) in Asia/Kolkata timezone.
     * Extracts all distinct special days across calendars, splitting combined labels (e.g. "Doctors Day / CA Day")
     * and deduplicating them cleanly.
     * Uses index idx_cc_special_days_date on rise_cc_special_days.
     * Static cached per request.
     *
     * @param string|null $custom_date Optional Y-m-d date
     * @return array List of string labels
     */
    function cc_get_today_special_days($custom_date = null) {
        static $cached_days = array();
        $target_date = $custom_date ? $custom_date : (new \DateTime("now", new \DateTimeZone("Asia/Kolkata")))->format("Y-m-d");
        if (isset($cached_days[$target_date])) {
            return $cached_days[$target_date];
        }

        $db = db_connect('default');
        $sd_table = $db->prefixTable('cc_special_days');
        $cal_table = $db->prefixTable('cc_calendars');

        $sql = "SELECT DISTINCT d.label 
                FROM `$sd_table` d 
                JOIN `$cal_table` c ON c.id = d.calendar_id 
                WHERE d.special_date = ? AND c.deleted = 0";

        try {
            $query = $db->query($sql, array($target_date));
            $rows = $query ? $query->getResult() : array();
        } catch (\Throwable $e) {
            $cached_days[$target_date] = array();
            return $cached_days[$target_date];
        }

        $weekdays = array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun');
        $junk = array('regular design', 'regualr design', 'video', 'doc video', 'offer', 'special day', 'parent awareness', 'video or packacge');
        $distinct = array();

        foreach ($rows as $row) {
            $raw_label = isset($row->label) ? (string) $row->label : "";
            if (trim($raw_label) === "") {
                continue;
            }

            // Split by slash, newline, semicolon, double pipe, or " & "
            $parts = preg_split('/[\/;\r\n]+|\|\||\s+&\s+/', $raw_label);
            foreach ($parts as $part) {
                // Also split if there's a comma followed by a capital letter or Kannada letter
                $subparts = preg_split('/,\s*(?=[A-Z\p{L}])/u', $part);
                foreach ($subparts as $sub) {
                    // Strip leading numbering/dates like "16 October – " or "1 – " or "16 - " or "ಆಗಸ್ಟ್ 21 — "
                    $clean = preg_replace('/^\s*(\d{1,2}\s+[a-zA-Z]+\s*[\-–—]|\d{1,2}\s*[\-–—]|[^\s]+\s*\d{1,2}\s*[\-–—])\s*/u', '', $sub);
                    // Strip trailing annotations like (May 5), (video...), (Doc), (Kannada), (Doc video)
                    $clean = preg_replace('/\s*\((video|packacge|doc|kannada)[^)]*\)/iu', '', $clean);
                    $clean = preg_replace('/\s*\(Doc\s*video\)/iu', '', $clean);
                    $clean = trim(preg_replace('/\s+/', ' ', $clean));
                    $clean = trim($clean, ",.-–—/ ");

                    if ($clean === "" || mb_strlen($clean) < 3) {
                        continue;
                    }

                    $lower = strtolower($clean);
                    if (in_array($lower, $weekdays, true) || in_array($lower, $junk, true)) {
                        continue;
                    }

                    // Normalize key for deduplication (strip punctuation/apostrophes)
                    $norm_key = preg_replace('/[^\p{L}\p{N}]/u', '', $lower);
                    if ($norm_key === "") {
                        $norm_key = $lower;
                    }
                    if (!isset($distinct[$norm_key])) {
                        $distinct[$norm_key] = $clean;
                    }
                }
            }
        }

        $cached_days[$target_date] = array_values($distinct);
        return $cached_days[$target_date];
    }
}
