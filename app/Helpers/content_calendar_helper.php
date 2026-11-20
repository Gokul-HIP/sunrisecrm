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

        if ($user && isset($user->id) && $user->id && $user->user_type === "staff" && !(int) $user->deleted && (!isset($user->status) || $user->status === "active")) {
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

if (!function_exists('cc_title_key')) {

    /**
     * Comparison key of a title (import duplicate check): trimmed, spaces collapsed, lowercase.
     */
    function cc_title_key($title) {
        $title = preg_replace('/\s+/u', ' ', trim((string) $title));
        return function_exists('mb_strtolower') ? mb_strtolower($title, 'UTF-8') : strtolower($title);
    }

}
