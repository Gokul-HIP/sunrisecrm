<?php

namespace App\Controllers;

/**
 * Content Calendar: one calendar per client, every client has its own columns.
 * Staff only. Every endpoint checks its permission on the server with cc_can().
 */
class Content_calendar extends Security_Controller {

    private $Cc_calendars_model;
    private $Cc_columns_model;
    private $Cc_items_model;
    private $Cc_months_model;
    private $Cc_special_days_model;
    private $Cc_targets_model;
    private $Cc_permissions_model;

    function __construct() {
        parent::__construct();

        helper('content_calendar');

        $this->Cc_calendars_model = model("App\Models\Cc_calendars_model");
        $this->Cc_columns_model = model("App\Models\Cc_columns_model");
        $this->Cc_items_model = model("App\Models\Cc_items_model");
        $this->Cc_months_model = model("App\Models\Cc_months_model");
        $this->Cc_special_days_model = model("App\Models\Cc_special_days_model");
        $this->Cc_targets_model = model("App\Models\Cc_targets_model");
        $this->Cc_permissions_model = model("App\Models\Cc_permissions_model");
    }

    /* ------------------------------------------------------------------ */
    /* helpers                                                             */
    /* ------------------------------------------------------------------ */

    //send a JSON response and stop
    private function _json($data, $status = 200) {
        if ($status !== 200) {
            http_response_code($status);
        }

        if (is_array($data) && function_exists('csrf_token') && function_exists('csrf_hash')) {
            $data["csrf_token"] = csrf_token();
            $data["csrf_hash"] = csrf_hash();
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit();
    }

    private function _fail($message, $errors = array(), $status = 200) {
        $data = array("success" => false, "message" => $message);
        if ($errors) {
            $data["errors"] = $errors;
        }

        $this->_json($data, $status);
    }

    //AJAX: refuse with a 403 JSON response
    private function _forbidden_json() {
        $this->_json(array("success" => false, "message" => cc_lang("cc_forbidden")), 403);
    }

    //AJAX: the user must have this permission
    private function _require_can($key) {
        if (!cc_can($key)) {
            $this->_forbidden_json();
        }
    }

    //AJAX: any of these permissions
    private function _require_can_any($keys) {
        foreach ($keys as $key) {
            if (cc_can($key)) {
                return true;
            }
        }

        $this->_forbidden_json();
    }

    //AJAX writes must be POST (CSRF protected)
    private function _require_post() {
        if (strtolower($this->request->getMethod()) !== "post") {
            $this->_json(array("success" => false, "message" => cc_lang("cc_invalid_request")), 405);
        }
    }

    //page: the user must have this permission, otherwise the standard forbidden page
    private function _require_page_can($key) {
        if (!cc_can($key)) {
            app_redirect("forbidden");
        }
    }

    private function _input($name) {
        $value = $this->request->getPostGet($name);
        return is_array($value) ? $value : trim((string) $value);
    }

    private function _get_calendar_or_fail($calendar_id) {
        $calendar = $this->Cc_calendars_model->get_active($calendar_id);
        if (!$calendar) {
            $this->_fail(cc_lang("cc_calendar_not_found"));
        }

        return $calendar;
    }

    //a non-deleted column that belongs to the calendar
    private function _get_column_or_fail($calendar_id, $column_id) {
        $column = $this->Cc_columns_model->get_active($column_id);
        if (!$column || (int) $column->calendar_id !== (int) $calendar_id) {
            $this->_fail(cc_lang("cc_column_not_found"));
        }

        return $column;
    }

    private function _permission_flags() {
        return array(
            "view" => cc_can("view"),
            "add" => cc_can("add"),
            "edit" => cc_can("edit"),
            "delete" => cc_can("delete"),
            "manage_options" => cc_can("manage_options"),
            "add_client" => cc_can("add_client"),
            "delete_client" => cc_can("delete_client"),
            "add_column" => cc_can("add_column"),
            "admin_manage" => cc_can("admin_manage"),
            "can_see_tasks" => ($this->login_user->is_admin || $this->login_user->user_type === "staff" || get_setting("client_can_view_tasks"))
        );
    }

    private function _is_valid_date($date) {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        $d = \DateTime::createFromFormat("Y-m-d", $date);
        return $d && $d->format("Y-m-d") === $date;
    }

    private function _is_valid_year_month($year, $month) {
        return $year >= 2000 && $year <= 2100 && $month >= 1 && $month <= 12;
    }

    private function _str_len($text) {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function _column_to_array($column) {
        return array(
            "id" => (int) $column->id,
            "field_key" => $column->field_key,
            "label" => $column->label,
            "input_type" => $column->input_type,
            "options" => $column->options_list,
            "is_core" => (int) $column->is_core,
            "is_visible" => (int) $column->is_visible,
            "sort_order" => (int) $column->sort_order
        );
    }

    private function _item_to_array($item) {
        return array(
            "id" => (int) $item->id,
            "date" => $item->publish_date ? $item->publish_date : null,
            "type" => $item->content_type,
            "status" => $item->status,
            "title" => $item->title,
            "task_id" => !empty($item->task_id) ? (int) $item->task_id : null,
            "src_date_text" => (isset($item->values["_src_date_text"]) && is_scalar($item->values["_src_date_text"])) ? (string) $item->values["_src_date_text"] : "",
            "values" => (object) $item->values
        );
    }

    //"1", "on", "true" => true
    private function _flag($name) {
        $value = strtolower(trim((string) $this->request->getPost($name)));
        return in_array($value, array("1", "on", "true", "yes"), true);
    }

    //colours of every option name: lowercase name => [bg, fg]
    private function _colors_for($columns, $extra_names = array()) {
        $names = $extra_names;
        foreach ($columns as $column) {
            if ($column->options_list) {
                $names = array_merge($names, $column->options_list);
            }
        }

        $colors = array();
        foreach ($names as $name) {
            $name = (string) $name;
            if ($name === "") {
                continue;
            }

            $colors[mb_strtolower($name, 'UTF-8')] = cc_chip_colors($name);
        }

        return (object) $colors;
    }

    private function _get_option_column($columns, $field_key) {
        foreach ($columns as $column) {
            if ($column->field_key === $field_key) {
                return $column;
            }
        }

        return null;
    }

    //"a, b, c" => ["a","b","c"] (trim, no blanks, no case-insensitive duplicates). Returns array or a string error.
    private function _parse_options($text) {
        if (is_array($text)) {
            $parts = $text;
        } else {
            $parts = explode(",", (string) $text);
        }

        $result = array();
        $seen = array();
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === "") {
                continue;
            }

            if ($this->_str_len($part) > 60) {
                return cc_lang("cc_option_too_long");
            }

            $lower = mb_strtolower($part, 'UTF-8');
            if (isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;
            $result[] = $part;
        }

        return $result;
    }

    private function _month_url($calendar_id, $date) {
        $query = "calendar=" . (int) $calendar_id;
        if ($date) {
            $query .= "&ym=" . substr($date, 0, 7) . "&date=" . $date;
        }

        return get_uri("content_calendar?" . $query);
    }

    /* ------------------------------------------------------------------ */
    /* pages                                                               */
    /* ------------------------------------------------------------------ */

    function index() {
        $this->_require_page_can("view");

        $initial_calendar_id = (int) $this->request->getGet("calendar");
        $initial_date = $this->_is_valid_date((string) $this->request->getGet("date")) ? (string) $this->request->getGet("date") : "";
        $initial_item_id = (int) $this->request->getGet("item");

        if ($initial_item_id > 0 && (!$initial_calendar_id || !$initial_date)) {
            $item_row = $this->Cc_items_model->get_active($initial_item_id);
            if ($item_row) {
                if (!$initial_calendar_id) {
                    $initial_calendar_id = (int) $item_row->calendar_id;
                }
                if (!$initial_date && $item_row->publish_date) {
                    $initial_date = $item_row->publish_date;
                }
            }
        }

        $initial_ym = (string) $this->request->getGet("ym");
        if ($initial_date && !preg_match('/^\d{4}-\d{2}$/', $initial_ym)) {
            $initial_ym = substr($initial_date, 0, 7);
        }

        $view_data["cc_config"] = array(
            "urls" => array(
                "page_data" => get_uri("content_calendar/page_data"),
                "item_form" => get_uri("content_calendar/item_form"),
                "save_client" => get_uri("content_calendar/save_client"),
                "import_parse" => get_uri("content_calendar/import_parse"),
                "import_parse_next" => get_uri("content_calendar/import_parse_next"),
                "import_preview" => get_uri("content_calendar/import_preview"),
                "import_run" => get_uri("content_calendar/import_run"),
                "import_undo" => get_uri("content_calendar/import_undo"),
                "import_skipped_csv" => get_uri("content_calendar/import_skipped_csv"),
                "save_special_day" => get_uri("content_calendar/save_special_day"),
                "add_month" => get_uri("content_calendar/add_month"),
                "save_column" => get_uri("content_calendar/save_column"),
                "delete_column" => get_uri("content_calendar/delete_column"),
                "reorder_column" => get_uri("content_calendar/reorder_column"),
                "toggle_column_visible" => get_uri("content_calendar/toggle_column_visible"),
                "copy_columns" => get_uri("content_calendar/copy_columns"),
                "save_target" => get_uri("content_calendar/save_target"),
                "delete_item" => get_uri("content_calendar/delete_item"),
                "set_status" => get_uri("content_calendar/set_status"),
                "item_data" => get_uri("content_calendar/item_data"),
                "undated_list" => get_uri("content_calendar/undated_list"),
                "undated_assign" => get_uri("content_calendar/undated_assign"),
                "undated_assign_bulk" => get_uri("content_calendar/undated_assign_bulk"),
                "delete_calendar" => get_uri("content_calendar/delete_calendar"),
                "task_view_base" => get_uri("projects/task_view/"),
                "save_item" => get_uri("content_calendar/save_item"),
                "csrf_token" => get_uri("content_calendar/csrf_token")
            ),
            "perms" => $this->_permission_flags(),
            "lang" => cc_js_lang(),
            "base_url" => get_file_uri(""),
            "import_limits" => \App\Libraries\Content_calendar_import::limits(),
            "first_day_of_week" => 0,
            "initial" => array(
                "calendar_id" => $initial_calendar_id,
                "ym" => preg_match('/^\d{4}-\d{2}$/', $initial_ym) ? $initial_ym : "",
                "date" => $initial_date,
                "item_id" => $initial_item_id
            )
        );

        $view_data["perms"] = $this->_permission_flags();
        return $this->template->rander("content_calendar/index", $view_data);
    }

    //phase 2: Add client is a popup on the calendar page. The old page URL goes back to the calendar.
    function add_client() {
        app_redirect("content_calendar");
    }

    //return a fresh CSRF token for the active session
    function csrf_token() {
        $this->_require_can("view");
        $this->_json(array(
            "success" => true,
            "csrf_token" => csrf_token(),
            "csrf_hash" => csrf_hash()
        ));
    }

    function save_client() {
        $this->_require_post();
        $this->_require_can("add_client");

        $name = trim((string) $this->request->getPost("name"));
        if ($name === "") {
            $this->_fail(cc_lang("cc_name_required"), array("name" => cc_lang("cc_name_required")));
        }

        if ($this->_str_len($name) > 120) {
            $this->_fail(cc_lang("cc_name_too_long"), array("name" => cc_lang("cc_name_too_long")));
        }

        if ($this->Cc_calendars_model->name_exists($name)) {
            $this->_fail(cc_lang("cc_client_exists"), array("name" => cc_lang("cc_client_exists")));
        }

        $source_id = (int) $this->request->getPost("copy_from");
        if ($source_id && !$this->Cc_calendars_model->get_active($source_id)) {
            $this->_fail(cc_lang("cc_calendar_not_found"));
        }

        $db = db_connect('default');
        $db->transStart();

        $client_id = $this->Cc_calendars_model->find_client_id_by_name($name);
        $calendar_id = $this->Cc_calendars_model->create_calendar($name, $client_id, $this->login_user->id);

        if ($source_id) {
            $this->Cc_columns_model->copy_all($source_id, $calendar_id);
        } else {
            $this->Cc_columns_model->seed_core($calendar_id);
        }

        $this->Cc_months_model->seed_default($calendar_id);

        $db->transComplete();

        if ($db->transStatus() === false || !$calendar_id) {
            $this->_fail(cc_lang("cc_error"));
        }

        $this->_json(array(
            "success" => true,
            "calendar_id" => $calendar_id,
            "redirect" => get_uri("content_calendar?calendar=" . $calendar_id),
            "message" => cc_lang("cc_calendar_created")
        ));
    }

    function item_form($id = 0) {
        $id = (int) $id;
        $item = null;

        if ($id) {
            $this->_require_page_can("edit");
            $item = $this->Cc_items_model->get_active($id);
            if (!$item) {
                show_404();
            }

            $calendar_id = (int) $item->calendar_id;
        } else {
            $this->_require_page_can("add");
            $calendar_id = (int) $this->request->getGet("calendar_id");
        }

        $calendar = $this->Cc_calendars_model->get_active($calendar_id);
        if (!$calendar) {
            show_404();
        }

        $all_columns = $this->Cc_columns_model->get_for_calendar($calendar_id, true);

        //the date field comes first, the others keep the client's order
        $columns = array();
        foreach ($all_columns as $column) {
            if ($column->field_key === "date") {
                array_unshift($columns, $column);
            } else {
                $columns[] = $column;
            }
        }

        $type_column = $this->_get_option_column($all_columns, "type");
        $status_column = $this->_get_option_column($all_columns, "status");

        //values to show in the form, keyed by field_key
        $form_values = array();
        if ($item) {
            $form_values = $item->values;
            $form_values["date"] = $item->publish_date ? $item->publish_date : "";
            $form_values["type"] = $item->content_type;
            $form_values["status"] = $item->status;
            $form_values["title"] = $item->title;
        } else {
            $date = (string) $this->request->getGet("date");
            if (!$this->_is_valid_date($date)) {
                $ym = (string) $this->request->getGet("ym");
                $date = preg_match('/^\d{4}-\d{2}$/', $ym) && $this->_is_valid_date($ym . "-01") ? $ym . "-01" : cc_today_date();
            }

            $form_values["date"] = $date;
            $form_values["type"] = ($type_column && $type_column->options_list) ? $type_column->options_list[0] : "";
            $form_values["status"] = ($status_column && $status_column->options_list) ? $status_column->options_list[0] : "";
        }

        $view_data["calendar"] = $calendar;
        $view_data["columns"] = $columns;
        $view_data["item"] = $item;
        $view_data["form_values"] = $form_values;
        $view_data["no_date"] = ($item && !$item->publish_date) ? true : false;
        $view_data["can_manage_options"] = cc_can("manage_options");
        $view_data["back_url"] = $this->_month_url($calendar_id, $form_values["date"]);
        $view_data["colors"] = $this->_colors_for($all_columns);
        $view_data["cc_config"] = array(
            "calendar_id" => $calendar_id,
            "item_id" => $id,
            "save_url" => get_uri("content_calendar/save_item"),
            "save_option_url" => get_uri("content_calendar/save_option"),
            "delete_option_url" => get_uri("content_calendar/delete_option"),
            "upload_url" => get_uri("content_calendar/upload_file"),
            "back_url" => $this->_month_url($calendar_id, $form_values["date"]),
            "can_manage_options" => $view_data["can_manage_options"],
            "base_url" => get_file_uri(""),
            "colors" => $view_data["colors"],
            "csrf_url" => get_uri("content_calendar/csrf_token"),
            "lang" => cc_js_lang()
        );

        return $this->template->rander("content_calendar/item_form", $view_data);
    }

    /**
     * Summary tiles: at most 6. Types with a target first, then the most used types of the month
     * (then the order of the options list). When there are more than 6 types, the 6th tile is "Others"
     * with the combined count of the rest. Empty content types never make a tile.
     */
    private function _build_tiles($options, $type_counts, $targets) {
        $max_tiles = 6;

        $candidates = array();
        $position = 0;
        $seen = array();
        foreach ($options as $option) {
            $option = (string) $option;
            if ($option === "" || isset($seen[$option])) {
                continue;
            }

            $seen[$option] = true;
            $candidates[] = array("label" => $option, "count" => isset($type_counts[$option]) ? (int) $type_counts[$option] : 0, "target" => isset($targets[$option]) ? (int) $targets[$option] : 0, "pos" => $position++);
        }

        //a type that is used but no longer in the options list still counts
        foreach ($type_counts as $type => $count) {
            $type = (string) $type;
            if ($type === "" || isset($seen[$type])) {
                continue;
            }

            $seen[$type] = true;
            $candidates[] = array("label" => $type, "count" => (int) $count, "target" => 0, "pos" => $position++);
        }

        usort($candidates, function ($a, $b) {
            $a_target = $a["target"] > 0 ? 1 : 0;
            $b_target = $b["target"] > 0 ? 1 : 0;
            if ($a_target !== $b_target) {
                return $b_target - $a_target;
            }

            if ($a["count"] !== $b["count"]) {
                return $b["count"] - $a["count"];
            }

            return $a["pos"] - $b["pos"];
        });

        $others = 0;
        $has_others = false;
        if (count($candidates) > $max_tiles) {
            foreach (array_slice($candidates, $max_tiles - 1) as $rest) {
                $others += $rest["count"];
            }

            $candidates = array_slice($candidates, 0, $max_tiles - 1);
            $has_others = true;
        }

        $tiles = array();
        foreach ($candidates as $one) {
            $tiles[] = array("label" => $one["label"], "count" => $one["count"], "target" => $one["target"]);
        }

        if ($has_others) {
            $tiles[] = array("label" => cc_lang("cc_tile_others"), "count" => $others, "target" => 0, "is_others" => true);
        }

        return $tiles;
    }

    /* ------------------------------------------------------------------ */
    /* AJAX: page data                                                     */
    /* ------------------------------------------------------------------ */

    function page_data() {
        $this->_require_can("view");

        $is_admin_manager = cc_can("admin_manage");
        $perms = $this->_permission_flags();
        $can_manage_columns = !empty($perms["add_column"]) || $is_admin_manager;

        $calendars_rows = $this->Cc_calendars_model->get_all_active();
        $calendars = array();
        foreach ($calendars_rows as $calendar) {
            $calendars[] = array("id" => (int) $calendar->id, "name" => $calendar->name);
        }

        $response = array(
            "success" => true,
            "perms" => $perms,
            "today" => cc_today_date(),
            "calendars" => $calendars,
            "calendar_id" => 0,
            "columns" => array(),
            "months" => array(),
            "year" => cc_today_year(),
            "month" => cc_today_month(),
            "items" => array(),
            "special_days" => (object) array(),
            "targets" => (object) array(),
            "tiles" => array(),
            "undated_count" => 0,
            "filters" => array("types" => array(), "goals" => array(), "statuses" => array(), "has_goal" => false),
            "colors" => (object) array()
        );

        //Add client popup: core columns + every client's columns for the "start with" preview
        if (!empty($perms["add_client"]) || $is_admin_manager) {
            $blank_columns = array();
            foreach ($this->Cc_columns_model->get_core_definitions() as $key => $def) {
                $blank_columns[] = array("label" => $def["label"], "input_type" => $def["input_type"], "is_core" => 1, "options" => $def["options"]);
            }

            $sources = array();
            foreach ($calendars_rows as $calendar) {
                $source_columns = array();
                foreach ($this->Cc_columns_model->get_for_calendar($calendar->id) as $column) {
                    $source_columns[] = array("label" => $column->label, "input_type" => $column->input_type, "is_core" => (int) $column->is_core, "options" => $column->options_list);
                }

                $sources[(int) $calendar->id] = $source_columns;
            }

            $response["blank_columns"] = $blank_columns;
            $response["client_sources"] = (object) $sources;
        }

        if (!count($calendars)) {
            $this->_json($response);
        }

        $calendar_id = (int) $this->_input("calendar_id");
        $calendar = $calendar_id ? $this->Cc_calendars_model->get_active($calendar_id) : null;
        if (!$calendar) {
            $calendar = $calendars_rows[0];
        }

        $calendar_id = (int) $calendar->id;

        //which month?
        $year = (int) $this->_input("year");
        $month = (int) $this->_input("month");
        if (!$this->_is_valid_year_month($year, $month)) {
            $year = cc_today_year();
            $month = cc_today_month();

            if (!$this->Cc_months_model->exists($calendar_id, $year, $month)) {
                if ($perms["add"]) {
                    $this->Cc_months_model->ensure($calendar_id, $year, $month);
                } else {
                    //a viewer can't add months: show the latest month of the row
                    $months_existing = $this->Cc_months_model->get_for_calendar($calendar_id);
                    if (count($months_existing)) {
                        $last = end($months_existing);
                        $year = $last->year;
                        $month = $last->month;
                    }
                }
            }
        }

        $columns = $this->Cc_columns_model->get_for_calendar($calendar_id, !$can_manage_columns);
        $all_visible_columns = $this->Cc_columns_model->get_for_calendar($calendar_id, true);

        $columns_array = array();
        foreach ($columns as $column) {
            $columns_array[] = $this->_column_to_array($column);
        }

        //months with the number of posts
        $counts = $this->Cc_items_model->get_month_counts($calendar_id);
        $months = array();
        foreach ($this->Cc_months_model->get_for_calendar($calendar_id) as $row) {
            $key = sprintf("%04d-%02d", $row->year, $row->month);
            $months[] = array("year" => $row->year, "month" => $row->month, "count" => isset($counts[$key]) ? $counts[$key] : 0);
        }

        //items of this month
        $items = array();
        $type_names = array();
        foreach ($this->Cc_items_model->get_details(array("calendar_id" => $calendar_id, "year" => $year, "month" => $month)) as $item) {
            $items[] = $this->_item_to_array($item);
            $type_names[] = $item->content_type;
            $type_names[] = $item->status;
        }

        //summary tiles and filters
        $type_column = $this->_get_option_column($all_visible_columns, "type");
        $status_column = $this->_get_option_column($all_visible_columns, "status");
        $goal_column = $this->_get_option_column($all_visible_columns, "goal");

        $type_counts = $this->Cc_items_model->get_type_counts($calendar_id, $year, $month);
        $targets = $this->Cc_targets_model->get_for_calendar($calendar_id);

        $tiles = $this->_build_tiles($type_column ? $type_column->options_list : array(), $type_counts, $targets);

        $has_goal = ($goal_column && $this->Cc_columns_model->is_option_type($goal_column->input_type));

        $response["calendar_id"] = $calendar_id;
        $response["columns"] = $columns_array;
        $response["months"] = $months;
        $response["year"] = $year;
        $response["month"] = $month;
        $response["items"] = $items;
        $response["special_days"] = (object) $this->Cc_special_days_model->get_for_month($calendar_id, $year, $month);
        $response["targets"] = (object) $targets;
        $response["tiles"] = $tiles;
        $response["undated_count"] = $this->Cc_items_model->count_undated($calendar_id);
        $response["filters"] = array(
            "types" => $type_column ? $type_column->options_list : array(),
            "statuses" => $status_column ? $status_column->options_list : array(),
            "goals" => $has_goal ? $goal_column->options_list : array(),
            "has_goal" => $has_goal
        );
        $response["colors"] = $this->_colors_for($all_visible_columns, $type_names);

        $this->_json($response);
    }

    /* ------------------------------------------------------------------ */
    /* AJAX: items                                                         */
    /* ------------------------------------------------------------------ */

    function save_item() {
        $this->_require_post();

        $id = (int) $this->request->getPost("id");
        $this->_require_can($id ? "edit" : "add");

        $calendar_id = (int) $this->request->getPost("calendar_id");
        $existing = null;
        if ($id) {
            $existing = $this->Cc_items_model->get_active($id);
            if (!$existing) {
                $this->_fail(cc_lang("cc_item_not_found"));
            }

            //the item must belong to the posted calendar
            if ($calendar_id && (int) $existing->calendar_id !== $calendar_id) {
                $this->_fail(cc_lang("cc_item_not_found"));
            }

            $calendar_id = (int) $existing->calendar_id;
        }

        $this->_get_calendar_or_fail($calendar_id);

        $columns = $this->Cc_columns_model->get_for_calendar($calendar_id, true);
        $posted = $this->request->getPost("f");
        if (!is_array($posted)) {
            $posted = array();
        }

        $errors = array();
        $core = array("date" => "", "type" => "", "status" => "", "title" => "");
        $values = $existing ? $existing->values : array();

        //"No date yet": only counts when no date is posted (a date always wins and clears the flag)
        $posted_date = isset($posted["date"]) && !is_array($posted["date"]) ? trim((string) $posted["date"]) : "";
        $no_date = $this->_flag("no_date") && $posted_date === "";

        foreach ($columns as $column) {
            $key = $column->field_key;
            $raw = isset($posted[$key]) ? $posted[$key] : null;

            if ($no_date && $column->is_core && $key === "date") {
                $core["date"] = "";
                continue;
            }

            $result = $this->_validate_field_value($column, $raw);

            if (isset($result["error"])) {
                $errors[$key] = $result["error"];
                continue;
            }

            if ($column->is_core) {
                $core[$key] = $result["value"];
            } else {
                $value = $result["value"];
                if ($value === "" || $value === null || $value === false || (is_array($value) && !count($value))) {
                    unset($values[$key]);
                } else {
                    $values[$key] = $value;
                }
            }
        }

        if (!$no_date && !isset($errors["date"]) && $core["date"] === "") {
            $errors["date"] = cc_lang("cc_date_required");
        }

        if (!isset($errors["title"]) && $core["title"] === "") {
            $errors["title"] = cc_lang("cc_title_required");
        }

        if (count($errors)) {
            $this->_fail(cc_lang("cc_please_fix_errors"), $errors);
        }

        //a date was given to an undated item: the "From sheet" text is not needed any more
        if ($core["date"] !== "" && $existing && !$existing->publish_date) {
            unset($values["_src_date_text"]);
        }

        $now = cc_now();
        $data = array(
            "calendar_id" => $calendar_id,
            "publish_date" => $core["date"] === "" ? null : $core["date"],
            "content_type" => $core["type"],
            "status" => $core["status"],
            "title" => $core["title"],
            "values_json" => $this->Cc_items_model->encode_values($values),
            "updated_by" => (int) $this->login_user->id,
            "updated_at" => $now
        );

        if (!$id) {
            $data["deleted"] = 0;
            $data["created_by"] = (int) $this->login_user->id;
            $data["created_at"] = $now;
        }

        $saved_id = $this->Cc_items_model->save_item($data, $id);

        //show the month of this post in the month row
        if ($core["date"] !== "") {
            $this->Cc_months_model->ensure_for_date($calendar_id, $core["date"]);
        }

        $this->_json(array(
            "success" => true,
            "id" => $saved_id,
            "calendar_id" => $calendar_id,
            "date" => $core["date"] === "" ? null : $core["date"],
            "redirect" => $this->_month_url($calendar_id, $core["date"]),
            "message" => cc_lang("cc_saved")
        ));
    }

    /**
     * AJAX: change status directly (from status pill popover).
     * POST: id (item id) or item_id, status (new status value).
     */
    function set_status() {
        $this->_require_post();
        $this->_require_can("edit");

        $id = (int) $this->request->getPost("id");
        if (!$id) {
            $id = (int) $this->request->getPost("item_id");
        }

        $item = $this->Cc_items_model->get_active($id);
        if (!$item) {
            $this->_fail(cc_lang("cc_item_not_found"), array(), 404);
            return;
        }

        $calendar_id = (int) $item->calendar_id;
        $this->_get_calendar_or_fail($calendar_id);

        $posted_status = (string) $this->request->getPost("status");
        $status = trim(str_replace(array("\r\n", "\r"), "\n", $posted_status));

        // Find status column
        $columns = $this->Cc_columns_model->get_for_calendar($calendar_id, true);
        $status_col = null;
        foreach ($columns as $col) {
            if ($col->field_key === "status") {
                $status_col = $col;
                break;
            }
        }

        if ($status_col) {
            if ($status_col->input_type === "select" || $status_col->input_type === "multi") {
                if ($status === "") {
                    // Empty string allowed
                } else if (!in_array($status, $status_col->options_list, true)) {
                    $this->_fail(cc_lang("cc_invalid_option") ?: "Invalid status option", array("status" => cc_lang("cc_invalid_option")), 400);
                    return;
                }
            } else {
                if ($this->_str_len($status) > 60) {
                    $this->_fail(cc_lang("cc_option_too_long") ?: "Status too long", array("status" => cc_lang("cc_option_too_long")), 400);
                    return;
                }
            }
        } else {
            if ($this->_str_len($status) > 60) {
                $this->_fail(cc_lang("cc_option_too_long") ?: "Status too long", array("status" => cc_lang("cc_option_too_long")), 400);
                return;
            }
        }

        $now = cc_now();
        $data = array(
            "status" => $status,
            "updated_by" => (int) $this->login_user->id,
            "updated_at" => $now
        );

        $this->Cc_items_model->save_item($data, $id);

        $updated_item = $this->Cc_items_model->get_active($id);

        $this->_json(array(
            "success" => true,
            "id" => $id,
            "calendar_id" => $calendar_id,
            "status" => $status,
            "item" => $updated_item ? $this->_item_to_array($updated_item) : null,
            "message" => cc_lang("cc_saved")
        ));
    }

    /**
     * Validate one posted value against its column.
     *
     * @return array with "value" or "error"
     */
    private function _validate_field_value($column, $raw) {
        $type = $column->input_type;
        $options = $column->options_list;

        if ($type === "multi") {
            $list = is_array($raw) ? $raw : (($raw === null || $raw === "") ? array() : array($raw));
            $clean = array();
            foreach ($list as $one) {
                $one = trim((string) $one);
                if ($one === "") {
                    continue;
                }

                if (!in_array($one, $options, true)) {
                    return array("error" => cc_lang("cc_invalid_option"));
                }

                if (!in_array($one, $clean, true)) {
                    $clean[] = $one;
                }
            }

            return array("value" => $clean);
        }

        if ($type === "check") {
            $checked = ($raw === "1" || $raw === 1 || $raw === true || $raw === "on");
            return array("value" => $checked ? true : false);
        }

        if (is_array($raw)) {
            return array("error" => cc_lang("cc_invalid_value"));
        }

        $value = str_replace(array("\r\n", "\r"), "\n", (string) $raw);
        $value = trim($value);

        if ($type === "select") {
            if ($value !== "" && !in_array($value, $options, true)) {
                return array("error" => cc_lang("cc_invalid_option"));
            }

            return array("value" => $value);
        }

        if ($value === "") {
            return array("value" => "");
        }

        if ($type === "date") {
            if (!$this->_is_valid_date($value)) {
                return array("error" => cc_lang("cc_invalid_date"));
            }

            return array("value" => $value);
        }

        if ($type === "number") {
            if (!is_numeric($value) || strlen($value) > 30) {
                return array("error" => cc_lang("cc_invalid_number"));
            }

            return array("value" => $value);
        }

        if ($type === "link") {
            if (!preg_match('#^https?://#i', $value) || $this->_str_len($value) > 1000) {
                return array("error" => cc_lang("cc_invalid_link"));
            }

            return array("value" => $value);
        }

        if ($type === "file") {
            $is_url = preg_match('#^https?://#i', $value);
            $is_uploaded = (strpos($value, "files/content_calendar/") === 0 && strpos($value, "..") === false);
            if ((!$is_url && !$is_uploaded) || $this->_str_len($value) > 500) {
                return array("error" => cc_lang("cc_invalid_file"));
            }

            return array("value" => $value);
        }

        //text, long, and the core title
        if ($column->is_core && $column->field_key === "title") {
            $value = trim(preg_replace('/\s+/u', ' ', $value));
        }

        $max = $type === "long" ? 10000 : ($column->field_key === "title" && $column->is_core ? 255 : 500);
        if ($this->_str_len($value) > $max) {
            return array("error" => cc_lang("cc_value_too_long"));
        }

        return array("value" => $value);
    }

    function delete_item() {
        $this->_require_post();
        $this->_require_can("delete");

        $item = $this->Cc_items_model->get_active((int) $this->request->getPost("id"));
        if (!$item) {
            $this->_fail(cc_lang("cc_item_not_found"));
        }

        $calendar_id = (int) $this->request->getPost("calendar_id");
        if ($calendar_id && $calendar_id !== (int) $item->calendar_id) {
            $this->_fail(cc_lang("cc_item_not_found"));
        }

        $this->Cc_items_model->soft_delete_item($item->id, $this->login_user->id);
        $this->_json(array("success" => true, "message" => cc_lang("cc_deleted")));
    }

    /**
     * AJAX: Single item data for View popup.
     */
    function item_data($id = 0) {
        $this->_require_can("view");
        $id = (int) $id;
        if (!$id) {
            $id = (int) $this->request->getPostGet("id");
        }
        $item = $this->Cc_items_model->get_active($id);
        if (!$item) {
            $this->_fail(cc_lang("cc_item_not_found"), array(), 404);
            return;
        }

        $calendar = $this->_get_calendar_or_fail($item->calendar_id);
        $all_columns = $this->Cc_columns_model->get_for_calendar($calendar->id, true);

        $this->_json(array(
            "success" => true,
            "item" => $this->_item_to_array($item),
            "columns" => array_map(array($this, "_column_to_array"), $all_columns)
        ));
    }

    /* ------------------------------------------------------------------ */
    /* AJAX: "Without date" (items with no publish date)                   */
    /* ------------------------------------------------------------------ */

    /**
     * Undated items of one calendar, 50 at a time. Posts: calendar_id, q (title search), offset.
     */
    function undated_list() {
        $this->_require_post();
        $this->_require_can("view");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $q = trim((string) $this->request->getPost("q"));
        if ($this->_str_len($q) > 120) {
            $q = mb_substr($q, 0, 120, "UTF-8");
        }

        $limit = 50;
        $offset = max(0, (int) $this->request->getPost("offset"));

        $items = array();
        $names = array();
        foreach ($this->Cc_items_model->get_undated($calendar->id, array("q" => $q, "limit" => $limit, "offset" => $offset)) as $item) {
            $items[] = $this->_item_to_array($item);
            $names[] = $item->content_type;
            $names[] = $item->status;
        }

        $all_columns = $this->Cc_columns_model->get_for_calendar($calendar->id, true);

        $this->_json(array(
            "success" => true,
            "calendar_id" => (int) $calendar->id,
            "items" => $items,
            "total" => $this->Cc_items_model->count_undated($calendar->id, $q),
            "undated_count" => $this->Cc_items_model->count_undated($calendar->id),
            "offset" => $offset,
            "limit" => $limit,
            "colors" => $this->_colors_for($all_columns, $names)
        ));
    }

    /**
     * Give a date to undated items (shared by undated_assign and undated_assign_bulk).
     * Items that are not undated (or belong to another calendar) are skipped.
     */
    private function _undated_assign_run($ids) {
        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));

        $date = trim((string) $this->request->getPost("publish_date"));
        if ($date === "") {
            $date = trim((string) $this->request->getPost("date"));
        }

        if ($date === "") {
            $this->_fail(cc_lang("cc_pick_a_date"), array("date" => cc_lang("cc_pick_a_date")));
        }

        if (!$this->_is_valid_date($date)) {
            $this->_fail(cc_lang("cc_invalid_date"), array("date" => cc_lang("cc_invalid_date")));
        }

        $year = (int) substr($date, 0, 4);
        if (!$this->_is_valid_year_month($year, (int) substr($date, 5, 2))) {
            $this->_fail(cc_lang("cc_invalid_date"), array("date" => cc_lang("cc_invalid_date")));
        }

        $clean_ids = array();
        foreach ((array) $ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean_ids, true)) {
                $clean_ids[] = $id;
            }
        }

        if (!count($clean_ids)) {
            $this->_fail(cc_lang("cc_item_not_found"));
        }

        if (count($clean_ids) > 500) {
            $this->_fail(cc_lang("cc_invalid_request"));
        }

        $assigned = array();
        foreach ($clean_ids as $id) {
            $item = $this->Cc_items_model->get_active($id);
            if (!$item || (int) $item->calendar_id !== (int) $calendar->id || $item->publish_date) {
                continue;
            }

            if ($this->Cc_items_model->assign_date($id, $date, $this->login_user->id, true)) {
                $assigned[] = $id;
            }
        }

        $added_month = false;
        if (count($assigned)) {
            $added_month = $this->Cc_months_model->ensure_for_date($calendar->id, $date);
        }

        $counts = $this->Cc_items_model->get_month_counts($calendar->id);
        $months = array();
        foreach ($this->Cc_months_model->get_for_calendar($calendar->id) as $row) {
            $key = sprintf("%04d-%02d", $row->year, $row->month);
            $months[] = array("year" => $row->year, "month" => $row->month, "count" => isset($counts[$key]) ? $counts[$key] : 0);
        }

        $this->_json(array(
            "success" => true,
            "calendar_id" => (int) $calendar->id,
            "date" => $date,
            "assigned" => $assigned,
            "assigned_count" => count($assigned),
            "skipped_count" => count($clean_ids) - count($assigned),
            "added_month" => $added_month ? true : false,
            "months" => $months,
            "undated_count" => $this->Cc_items_model->count_undated($calendar->id),
            "message" => count($assigned) ? sprintf(cc_lang("cc_undated_moved"), $date) : cc_lang("cc_undated_none_moved")
        ));
    }

    /**
     * Posts: calendar_id, publish_date (or date), item_id (one) or item_ids[] (several).
     */
    function undated_assign() {
        $this->_require_post();
        $this->_require_can("edit");

        $ids = array();
        $one = (int) $this->request->getPost("item_id");
        if ($one) {
            $ids[] = $one;
        }

        $many = $this->request->getPost("item_ids");
        if (is_array($many)) {
            $ids = array_merge($ids, $many);
        }

        $this->_undated_assign_run($ids);
    }

    /**
     * Posts: calendar_id, publish_date (or date), ids[].
     */
    function undated_assign_bulk() {
        $this->_require_post();
        $this->_require_can("edit");

        $ids = $this->request->getPost("ids");
        $this->_undated_assign_run(is_array($ids) ? $ids : array());
    }

    function upload_file() {
        $this->_require_post();
        $this->_require_can_any(array("add", "edit"));

        if (empty($_FILES["file"]) || !is_uploaded_file($_FILES["file"]["tmp_name"]) || (int) $_FILES["file"]["error"] !== 0) {
            $this->_fail(cc_lang("cc_upload_failed"));
        }

        $original = basename($_FILES["file"]["name"]);
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $blocked = array("php", "php3", "php4", "php5", "php7", "php8", "phtml", "phar", "pl", "py", "cgi", "sh", "exe", "bat", "htaccess", "html", "htm", "js", "svg");
        if (!$extension || in_array($extension, $blocked, true) || !is_valid_file_to_upload($original)) {
            $this->_fail(cc_lang("cc_file_type_not_allowed"));
        }

        $safe_name = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($original, PATHINFO_FILENAME));
        $safe_name = substr($safe_name, 0, 60);
        if ($safe_name === "") {
            $safe_name = "file";
        }

        $folder = FCPATH . "files/content_calendar/";
        if (!is_dir($folder) && !mkdir($folder, 0755, true)) {
            $this->_fail(cc_lang("cc_upload_failed"));
        }

        $file_name = uniqid() . "_" . $safe_name . "." . $extension;
        if (!move_uploaded_file($_FILES["file"]["tmp_name"], $folder . $file_name)) {
            $this->_fail(cc_lang("cc_upload_failed"));
        }

        $this->_json(array("success" => true, "path" => "files/content_calendar/" . $file_name, "name" => $original));
    }

    /* ------------------------------------------------------------------ */
    /* AJAX: special days, months, targets                                 */
    /* ------------------------------------------------------------------ */

    function save_special_day() {
        $this->_require_post();
        $this->_require_can("edit");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $date = trim((string) $this->request->getPost("date"));
        if (!$this->_is_valid_date($date)) {
            $this->_fail(cc_lang("cc_invalid_date"));
        }

        $label = trim((string) $this->request->getPost("label"));
        if ($this->_str_len($label) > 120) {
            $this->_fail(cc_lang("cc_value_too_long"));
        }

        $saved = $this->Cc_special_days_model->save_label($calendar->id, $date, $label);
        $this->_json(array("success" => true, "date" => $date, "label" => $saved));
    }

    function add_month() {
        $this->_require_post();
        $this->_require_can("add");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));

        $year = (int) $this->request->getPost("year");
        $month = (int) $this->request->getPost("month");
        if ($year || $month) {
            if (!$this->_is_valid_year_month($year, $month)) {
                $this->_fail(cc_lang("cc_invalid_month"));
            }

            $added = $this->Cc_months_model->ensure($calendar->id, $year, $month);
            $result = array("year" => $year, "month" => $month, "added" => $added);
        } else {
            $row = $this->Cc_months_model->add_next($calendar->id);
            $result = array("year" => $row->year, "month" => $row->month, "added" => $row->added);
        }

        $this->_json(array_merge(array("success" => true), $result));
    }

    function save_target() {
        $this->_require_post();
        $this->_require_can("admin_manage");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $content_type = trim((string) $this->request->getPost("content_type"));

        $type_column = $this->Cc_columns_model->get_by_key($calendar->id, "type");
        if (!$type_column || !in_array($content_type, $type_column->options_list, true)) {
            $this->_fail(cc_lang("cc_invalid_option"));
        }

        $target = trim((string) $this->request->getPost("target_count"));
        if ($target !== "" && (!ctype_digit($target) || (int) $target > 100000)) {
            $this->_fail(cc_lang("cc_invalid_number"));
        }

        $saved = $this->Cc_targets_model->save_target($calendar->id, $content_type, (int) $target);
        $this->_json(array("success" => true, "content_type" => $content_type, "target_count" => $saved));
    }

    /* ------------------------------------------------------------------ */
    /* AJAX: calendars and the column designer (admin only)                */
    /* ------------------------------------------------------------------ */

    function delete_calendar() {
        $this->_require_post();
        $this->_require_can("delete_client");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $this->Cc_calendars_model->soft_delete($calendar->id);
        $this->_json(array("success" => true, "message" => cc_lang("cc_deleted")));
    }

    function save_column() {
        $this->_require_post();
        $this->_require_can("add_column");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $column_id = (int) $this->request->getPost("id");
        $label = trim((string) $this->request->getPost("label"));

        $column = null;
        if ($column_id) {
            $column = $this->_get_column_or_fail($calendar->id, $column_id);
        }

        if ($label === "") {
            $this->_fail(cc_lang("cc_column_name_required"), array("label" => cc_lang("cc_column_name_required")));
        }

        if ($this->_str_len($label) > 120) {
            $this->_fail(cc_lang("cc_name_too_long"), array("label" => cc_lang("cc_name_too_long")));
        }

        if ($this->Cc_columns_model->label_exists($calendar->id, $label, $column_id)) {
            $this->_fail(cc_lang("cc_column_name_exists"), array("label" => cc_lang("cc_column_name_exists")));
        }

        //the type can't be changed after creation
        $input_type = $column ? $column->input_type : trim((string) $this->request->getPost("input_type"));
        if (!in_array($input_type, $this->Cc_columns_model->get_input_types(), true)) {
            $this->_fail(cc_lang("cc_invalid_input_type"), array("input_type" => cc_lang("cc_invalid_input_type")));
        }

        $options = array();
        if ($this->Cc_columns_model->is_option_type($input_type)) {
            $options = $this->_parse_options($this->request->getPost("options"));
            if (!is_array($options)) {
                $this->_fail($options, array("options" => $options));
            }

            if (!count($options)) {
                $this->_fail(cc_lang("cc_options_required"), array("options" => cc_lang("cc_options_required")));
            }
        }

        if (!$column) {
            $new_id = $this->Cc_columns_model->add_column($calendar->id, $label, $input_type, $options);
            $saved = $this->Cc_columns_model->get_active($new_id);
            $this->_json(array("success" => true, "column" => $this->_column_to_array($saved), "message" => cc_lang("cc_saved")));
        }

        //edit: a removed option that posts still use is refused
        if ($this->Cc_columns_model->is_option_type($input_type)) {
            foreach ($column->options_list as $old_option) {
                if (in_array($old_option, $options, true)) {
                    continue;
                }

                $used = $this->Cc_items_model->count_option_usage($calendar->id, $column->field_key, $old_option);
                if ($used > 0) {
                    $message = sprintf(cc_lang("cc_option_used_by_posts"), $used) . ": " . $old_option;
                    $this->_fail($message, array("options" => $message));
                }
            }
        }

        $update = array("label" => $label);
        if ($this->Cc_columns_model->is_option_type($input_type)) {
            $update["options"] = $this->Cc_columns_model->encode_options($options);
        }

        $this->Cc_columns_model->update_column($column->id, $update);
        $saved = $this->Cc_columns_model->get_active($column->id);
        $this->_json(array("success" => true, "column" => $this->_column_to_array($saved), "message" => cc_lang("cc_saved")));
    }

    function delete_column() {
        $this->_require_post();
        $this->_require_can("add_column");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $column = $this->_get_column_or_fail($calendar->id, (int) $this->request->getPost("id"));

        if ($column->is_core) {
            $this->_fail(cc_lang("cc_core_cannot_remove"));
        }

        //soft delete: the stored values stay in values_json
        $this->Cc_columns_model->soft_delete($column->id);
        $this->_json(array("success" => true, "message" => cc_lang("cc_deleted")));
    }

    function reorder_column() {
        $this->_require_post();
        $this->_require_can("add_column");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));

        $ordered_ids = $this->request->getPost("ordered_ids");
        if (is_array($ordered_ids) && count($ordered_ids)) {
            $this->Cc_columns_model->set_order($calendar->id, $ordered_ids);
        } else {
            $column = $this->_get_column_or_fail($calendar->id, (int) $this->request->getPost("id"));
            $direction = $this->request->getPost("direction") === "up" ? "up" : "down";
            $this->Cc_columns_model->move($calendar->id, $column->id, $direction);
        }

        $columns = array();
        foreach ($this->Cc_columns_model->get_for_calendar($calendar->id) as $column) {
            $columns[] = $this->_column_to_array($column);
        }

        $this->_json(array("success" => true, "columns" => $columns));
    }

    function toggle_column_visible() {
        $this->_require_post();
        $this->_require_can("add_column");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $column = $this->_get_column_or_fail($calendar->id, (int) $this->request->getPost("id"));

        if ($column->is_core) {
            $this->_fail(cc_lang("cc_core_cannot_hide"));
        }

        $visible = $column->is_visible ? 0 : 1;
        $this->Cc_columns_model->update_column($column->id, array("is_visible" => $visible));
        $this->_json(array("success" => true, "id" => (int) $column->id, "is_visible" => $visible));
    }

    function copy_columns() {
        $this->_require_post();
        $this->_require_can("add_column");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $source = $this->_get_calendar_or_fail((int) $this->request->getPost("from_calendar_id"));
        if ((int) $source->id === (int) $calendar->id) {
            $this->_fail(cc_lang("cc_pick_another_client"));
        }

        $added = $this->Cc_columns_model->copy_custom($source->id, $calendar->id);
        $this->_json(array("success" => true, "added" => $added, "message" => sprintf(cc_lang("cc_columns_added"), $added)));
    }

    /* ------------------------------------------------------------------ */
    /* AJAX: options of select / multi columns (manage_options)            */
    /* ------------------------------------------------------------------ */

    function save_option() {
        $this->_require_post();
        $this->_require_can("manage_options");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $column = $this->_get_column_or_fail($calendar->id, (int) $this->request->getPost("column_id"));

        if (!$this->Cc_columns_model->is_option_type($column->input_type)) {
            $this->_fail(cc_lang("cc_not_select_column"));
        }

        $option = trim((string) $this->request->getPost("option"));
        if ($option === "") {
            $this->_fail(cc_lang("cc_option_required"));
        }

        if ($this->_str_len($option) > 60) {
            $this->_fail(cc_lang("cc_option_too_long"));
        }

        //case-insensitive duplicate: select the existing one
        $options = $column->options_list;
        foreach ($options as $existing) {
            if (mb_strtolower($existing, 'UTF-8') === mb_strtolower($option, 'UTF-8')) {
                $this->_json(array("success" => true, "existing" => true, "option" => $existing, "options" => $options, "colors" => $this->_colors_for(array($column))));
            }
        }

        $options[] = $option;
        $this->Cc_columns_model->update_options($column->id, $options);

        $column->options_list = $options;
        $this->_json(array("success" => true, "existing" => false, "option" => $option, "options" => $options, "colors" => $this->_colors_for(array($column))));
    }

    function delete_option() {
        $this->_require_post();
        $this->_require_can("manage_options");

        $calendar = $this->_get_calendar_or_fail((int) $this->request->getPost("calendar_id"));
        $column = $this->_get_column_or_fail($calendar->id, (int) $this->request->getPost("column_id"));

        if (!$this->Cc_columns_model->is_option_type($column->input_type)) {
            $this->_fail(cc_lang("cc_not_select_column"));
        }

        $option = trim((string) $this->request->getPost("option"));
        $options = $column->options_list;
        if (!in_array($option, $options, true)) {
            $this->_fail(cc_lang("cc_option_not_found"));
        }

        $used = $this->Cc_items_model->count_option_usage($calendar->id, $column->field_key, $option);
        if ($used > 0) {
            $this->_fail(sprintf(cc_lang("cc_option_used_by_posts"), $used));
        }

        //the last option of Content Type / Status can't be removed
        if ($column->is_core && count($options) <= 1) {
            $this->_fail(cc_lang("cc_last_option"));
        }

        $remaining = array();
        foreach ($options as $one) {
            if ($one !== $option) {
                $remaining[] = $one;
            }
        }

        $this->Cc_columns_model->update_options($column->id, $remaining);
        $this->_json(array("success" => true, "options" => $remaining));
    }

    /* ------------------------------------------------------------------ */
    /* AJAX: import (phase 2). add = rows, admin = new columns / clients,  */
    /* manage_options or admin = new options                               */
    /* ------------------------------------------------------------------ */

    private function _import_lib() {
        return new \App\Libraries\Content_calendar_import();
    }

    private function _import_perms() {
        $add_client = cc_can("add_client");
        $add_column = cc_can("add_column");
        return array(
            "add" => cc_can("add"),
            "edit" => cc_can("edit"),
            "admin_manage" => cc_can("admin_manage"),
            "manage_options" => cc_can("manage_options"),
            "add_client" => $add_client,
            "add_clients" => $add_client,
            "add_column" => $add_column,
            "add_columns" => $add_column
        );
    }

    //may the user create a client during an import
    private function _can_create_clients($perms) {
        return !empty($perms["add_client"]) || !empty($perms["add_clients"]) || !empty($perms["admin_manage"]);
    }

    private function _calendars_for_js($calendars) {
        $list = array();
        foreach ($calendars as $calendar) {
            $list[] = array("id" => (int) $calendar->id, "name" => $calendar->name);
        }

        return $list;
    }

    //import needs the phase 2 table and column (docs/content_calendar.sql)
    private function _require_import_schema() {
        $db = db_connect("default");
        if (!$db->tableExists("cc_import_logs") || !$db->fieldExists("import_batch_id", "cc_items")) {
            $this->_fail(cc_lang("cc_imp_schema_missing"));
        }
    }

    private function _import_payload() {
        $raw = $this->request->getPost("payload");
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            $this->_fail(cc_lang("cc_invalid_request"));
        }

        return $payload;
    }

    private function _clean_file_name($name) {
        $name = basename(str_replace("\\", "/", (string) $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', "", $name);
        return mb_substr($name, 0, 255, "UTF-8");
    }

    /**
     * Step 1: upload, check size / type, keep the file under a token and list the sheet names. No sheet is read here:
     * import_parse_next reads them one by one (so a 32 sheet workbook never has to fit in one request).
     */
    function import_parse() {
        $this->_require_post();
        $this->_require_can("add");
        $this->_require_import_schema();

        $lib = $this->_import_lib();
        $lib->sweep_expired();
        $perms = $this->_import_perms();

        $calendar_id = (int) $this->request->getPost("calendar_id");
        if ($calendar_id) {
            $this->_get_calendar_or_fail($calendar_id);
        }

        if (empty($_FILES["file"]) || !isset($_FILES["file"]["error"])) {
            $this->_fail(cc_lang("cc_imp_no_file"));
        }

        $error_code = (int) $_FILES["file"]["error"];
        if ($error_code === UPLOAD_ERR_INI_SIZE || $error_code === UPLOAD_ERR_FORM_SIZE) {
            $this->_fail(sprintf(cc_lang("cc_imp_err_size"), round(\App\Libraries\Content_calendar_import::MAX_FILE_BYTES / 1048576)));
        }

        if ($error_code !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES["file"]["tmp_name"])) {
            $this->_fail(cc_lang("cc_imp_upload_failed"));
        }

        $file_name = $this->_clean_file_name($_FILES["file"]["name"]);
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if (!in_array($extension, \App\Libraries\Content_calendar_import::allowed_extensions(), true)) {
            $this->_fail(cc_lang("cc_imp_err_ext"));
        }

        //random name in writable/files/content_calendar/imports/; begin_parse keeps it as token.upload until the last sheet is read
        $upload_path = $lib->dir() . $lib->new_token() . ".upload";
        if (!move_uploaded_file($_FILES["file"]["tmp_name"], $upload_path)) {
            $this->_fail(cc_lang("cc_imp_upload_failed"));
        }

        try {
            $begin = $lib->begin_parse($this->login_user->id, $calendar_id, $file_name, $upload_path, (string) $this->request->getPost("file_type"));
        } catch (\RuntimeException $e) {
            @unlink($upload_path);
            $this->_fail($e->getMessage());
        } catch (\Throwable $e) {
            @unlink($upload_path);
            log_message('error', '[ERROR] cc import parse: {message}', array('message' => $e->getMessage()));
            $this->_fail(cc_lang("cc_imp_err_corrupt"));
        }

        $this->_json(array(
            "success" => true,
            "token" => $begin["token"],
            "file_name" => $file_name,
            "type" => $begin["type"],
            "type_label" => $lib->type_label($begin["type"]),
            "notes" => $begin["notes"],
            "total" => $begin["total"],
            "sheet_names" => $begin["sheet_names"],
            "calendars" => $this->_calendars_for_js($this->Cc_calendars_model->get_all_active()),
            "calendar_id" => $calendar_id,
            "perms" => $perms,
            "limits" => \App\Libraries\Content_calendar_import::limits()
        ));
    }

    /**
     * Step 1b (called until "done"): read the next sheet(s) of the uploaded file.
     * Returns the progress and the summaries of the sheets read by this call. When done it also returns every
     * sheet summary ("sheets"), the client groups ("groups", same client = one group) and the calendars.
     */
    function import_parse_next() {
        $this->_require_post();
        $this->_require_can("add");
        $this->_require_import_schema();

        $lib = $this->_import_lib();
        $token = (string) $this->request->getPost("token");
        $batch = (int) $this->request->getPost("batch");
        $batch = $batch >= 1 ? min(5, $batch) : 2;

        try {
            $step = $lib->parse_next_sheets($token, $this->login_user->id, $batch);
        } catch (\RuntimeException $e) {
            $this->_fail($e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', '[ERROR] cc import parse next: {message}', array('message' => $e->getMessage()));
            $this->_fail(cc_lang("cc_imp_err_corrupt"));
        }

        $out = array(
            "success" => true,
            "token" => $token,
            "done" => (bool) $step["done"],
            "parsed" => (int) $step["parsed"],
            "total" => (int) $step["total"],
            "sheets" => $step["sheets"]
        );

        if ($step["done"]) {
            $calendars = $this->Cc_calendars_model->get_all_active();
            $meta = $lib->load_meta($token, $this->login_user->id);
            $summaries = array();
            $usable = 0;
            $first_error = "";
            if ($meta) {
                foreach (array_keys($meta["sheet_names"]) as $index) {
                    $sheet = $lib->load_sheet_parsed($token, $this->login_user->id, $index);
                    if ($sheet === null) {
                        continue;
                    }

                    $summary = isset($sheet["summary"]) ? $sheet["summary"] : $lib->sheet_summary($sheet, $index);
                    unset($sheet);
                    $summary["match_calendar_id"] = $lib->match_calendar($summary["client_name"], $calendars);
                    if (!empty($summary["usable"])) {
                        $usable++;
                    } else if ($first_error === "") {
                        $first_error = $summary["error"];
                    }

                    $summaries[] = $summary;
                }
            }

            if (!$usable) {
                $lib->delete_parsed($token);
                $this->_fail($first_error !== "" ? $first_error : cc_lang("cc_imp_err_no_sheets"));
            }

            $out["sheets"] = $summaries;
            $out["groups"] = $lib->group_sheets($summaries, $calendars);
            $out["calendars"] = $this->_calendars_for_js($calendars);
            $out["perms"] = $this->_import_perms();
        }

        $this->_json($out);
    }

    /* ---------- import: resolve the posted choices (shared by preview and run) ---------- */

    //the global options of the payload, in the names that the library knows
    private function _import_options($payload, $perms) {
        $in = isset($payload["options"]) && is_array($payload["options"]) ? $payload["options"] : array();
        $options = array();
        foreach (array("year", "carry_blank_date", "skip_duplicates", "import_without_date", "fix_swapped_dates", "special_ignore", "set_targets_from_counts", "leave_as_text") as $key) {
            if (array_key_exists($key, $in)) {
                $options[$key] = $in[$key];
            }
        }

        //blank status: "Planned" (or any status name) or "" = leave empty
        if (isset($in["blank_status"]) && is_string($in["blank_status"])) {
            $options["default_status"] = trim($in["blank_status"]);
        } else if (isset($in["default_status"]) && is_string($in["default_status"])) {
            $options["default_status"] = trim($in["default_status"]);
        }

        $merges = isset($in["value_merges"]) && is_array($in["value_merges"]) ? $in["value_merges"] : (isset($in["merges"]) && is_array($in["merges"]) ? $in["merges"] : array());
        $options["merges"] = $merges;

        //targets from the header counts are an admin setting
        if (empty($perms["admin_manage"])) {
            $options["set_targets_from_counts"] = false;
        }

        return $options;
    }

    /**
     * Column key of every column of a sheet for the mapping that is remembered per client: normalized header text
     * (letters and digits only), or "column<letter>" when there is no header.
     */
    private function _header_keys($lib, $sheet, $header_row) {
        $cfg = $lib->default_config();
        $cfg["header_row"] = (int) $header_row;
        $layout = $lib->analyze_layout($sheet["rows"], $cfg, isset($sheet["name"]) ? $sheet["name"] : "");
        $keys = array();
        if (empty($layout["ok"])) {
            return $keys;
        }

        foreach ($layout["info"] as $i => $col) {
            $keys[(int) $i] = ($col["has_header"] && $col["raw_norm"] !== "") ? $col["raw_norm"] : "column" . strtolower($col["letter"]);
        }

        return $keys;
    }

    //mapping by column index of ONE sheet from the mapping by normalized header of the client
    private function _mapping_for_sheet($lib, $sheet, $header_row, $by_header) {
        if (!count($by_header)) {
            return array();
        }

        $mapping = array();
        foreach ($this->_header_keys($lib, $sheet, $header_row) as $i => $key) {
            if (isset($by_header[$key]) && is_array($by_header[$key])) {
                $mapping[$i] = $by_header[$key];
            }
        }

        return $mapping;
    }

    /**
     * Read the posted choices and resolve the target of every client group. Sheets are NOT loaded here (see _import_group_jobs),
     * so many groups never have to be in memory together.
     * Returns array("lib", "payload", "token", "meta", "perms", "options", "groups", "run_id").
     * A group never creates a client silently: "new" needs the add client permission, otherwise it gets the error "needs_add_client".
     */
    private function _import_resolve() {
        $lib = $this->_import_lib();
        $perms = $this->_import_perms();
        $payload = $this->_import_payload();

        $token = isset($payload["token"]) ? (string) $payload["token"] : "";
        $meta = $lib->load_meta($token, $this->login_user->id);
        if (!$meta) {
            $this->_fail(cc_lang("cc_imp_expired"));
        }

        if (empty($meta["complete"])) {
            $this->_fail(cc_lang("cc_imp_parse_incomplete"));
        }

        $options = $this->_import_options($payload, $perms);
        $groups_in = isset($payload["groups"]) && is_array($payload["groups"]) ? $payload["groups"] : array();
        $can_create = $this->_can_create_clients($perms);

        $groups = array();
        $used_indexes = array();
        $seen_new_names = array();
        foreach ($groups_in as $g) {
            if (!is_array($g) || !isset($g["sheet_indexes"]) || !is_array($g["sheet_indexes"])) {
                continue;
            }

            $indexes = array();
            foreach ($g["sheet_indexes"] as $i) {
                $i = (int) $i;
                if (isset($meta["sheet_names"][$i]) && !empty($meta["parsed"][$i]) && !isset($used_indexes[$i])) {
                    $indexes[] = $i;
                    $used_indexes[$i] = true;
                }
            }

            if (!count($indexes)) {
                continue;
            }

            $client_name = trim(preg_replace('/\s+/u', " ", isset($g["client_name"]) ? (string) $g["client_name"] : ""));
            $header_rows = array();
            if (isset($g["header_rows"]) && is_array($g["header_rows"])) {
                foreach ($g["header_rows"] as $i => $row) {
                    if ((int) $row >= 1) {
                        $header_rows[(int) $i] = (int) $row;
                    }
                }
            }

            $by_header = array();
            if (isset($g["mapping_by_header"]) && is_array($g["mapping_by_header"])) {
                foreach ($g["mapping_by_header"] as $key => $map) {
                    if (is_string($key) && is_array($map) && count($by_header) < 200) {
                        $by_header[$key] = $map;
                    }
                }
            }

            //options of this client = the global options + its own value merges / "keep as text" choices
            $group_options = $options;
            if (isset($g["value_merges"]) && is_array($g["value_merges"])) {
                foreach ($g["value_merges"] as $target_key => $list) {
                    if (is_string($target_key) && is_array($list)) {
                        foreach ($list as $from => $to) {
                            $group_options["merges"][$target_key][$from] = $to;
                        }
                    }
                }
            }

            if (isset($g["leave_as_text"]) && is_array($g["leave_as_text"])) {
                $group_options["leave_as_text"] = $g["leave_as_text"];
            }

            $group = array(
                "key" => isset($g["key"]) ? mb_substr((string) $g["key"], 0, 120, "UTF-8") : "g" . count($groups),
                "checked" => !empty($g["checked"]),
                "client_name" => $client_name,
                "indexes" => $indexes,
                "header_rows" => $header_rows,
                "mapping_by_header" => $by_header,
                "options" => $group_options,
                "error" => "", "error_code" => "", "target" => array(), "calendar_id" => 0, "calendar_name" => "", "is_new" => false
            );

            $target = isset($g["target"]) ? (string) $g["target"] : "";
            if ($target === "new") {
                $name = trim(preg_replace('/\s+/u', " ", isset($g["new_name"]) && trim((string) $g["new_name"]) !== "" ? (string) $g["new_name"] : $client_name));
                $group["is_new"] = true;
                $group["calendar_name"] = $name;
                if (!$can_create) {
                    $group["error"] = cc_lang("cc_imp_needs_add_clients");
                    $group["error_code"] = "needs_add_client";
                } else if ($name === "") {
                    $group["error"] = cc_lang("cc_name_required");
                } else if (mb_strlen($name, "UTF-8") > 120) {
                    $group["error"] = cc_lang("cc_name_too_long");
                } else if ($this->Cc_calendars_model->name_exists($name) || isset($seen_new_names[mb_strtolower($name, "UTF-8")])) {
                    $group["error"] = cc_lang("cc_client_exists");
                } else {
                    $seen_new_names[mb_strtolower($name, "UTF-8")] = true;
                    $group["target"] = array("new_name" => $name);
                }
            } else {
                $calendar = (int) $target > 0 ? $this->Cc_calendars_model->get_active((int) $target) : null;
                if (!$calendar) {
                    $group["error"] = cc_lang("cc_calendar_not_found");
                } else {
                    $group["calendar_id"] = (int) $calendar->id;
                    $group["calendar_name"] = $calendar->name;
                    $group["target"] = array("calendar_id" => (int) $calendar->id);
                }
            }

            $groups[] = $group;
        }

        if (!count($groups)) {
            $this->_fail(cc_lang("cc_invalid_request"));
        }

        $run_id = isset($payload["run_id"]) && $lib->valid_run_id($payload["run_id"]) ? $payload["run_id"] : $lib->new_run_id();
        return array("lib" => $lib, "payload" => $payload, "token" => $token, "meta" => $meta, "perms" => $perms, "options" => $options, "groups" => $groups, "run_id" => $run_id);
    }

    //load the sheets of one group from the temp files and build the library jobs (index, sheet, config)
    private function _import_group_jobs($r, $group) {
        $lib = $r["lib"];
        $jobs = array();
        foreach ($group["indexes"] as $index) {
            $sheet = $lib->load_sheet_parsed($r["token"], $this->login_user->id, $index);
            if ($sheet === null || (isset($sheet["summary"]["usable"]) && !$sheet["summary"]["usable"])) {
                continue;
            }

            $header_row = isset($group["header_rows"][$index]) ? $group["header_rows"][$index] : 0;
            $jobs[] = array(
                "index" => $index,
                "sheet" => $sheet,
                "config" => array("header_row" => $header_row, "mapping" => $this->_mapping_for_sheet($lib, $sheet, $header_row, $group["mapping_by_header"]))
            );
        }

        return $jobs;
    }

    /**
     * Step 2: dry run of every checked client group (plus the group that is open for review). Same planning function
     * as import_run, nothing is written. Groups are sent back in the order of the payload.
     */
    function import_preview() {
        $this->_require_post();
        $this->_require_can("add");
        $this->_require_import_schema();

        $r = $this->_import_resolve();
        $lib = $r["lib"];
        $detail_group = isset($r["payload"]["detail_group"]) ? (string) $r["payload"]["detail_group"] : "";
        $detail_index = isset($r["payload"]["detail_index"]) && $r["payload"]["detail_index"] !== "" ? (int) $r["payload"]["detail_index"] : null;

        $groups = array();
        foreach ($r["groups"] as $group) {
            $is_detail = $detail_group !== "" && $group["key"] === $detail_group;
            if (!$group["checked"] && !$is_detail) {
                continue;
            }

            $entry = array(
                "key" => $group["key"], "client_name" => $group["client_name"], "checked" => $group["checked"],
                "calendar_id" => $group["calendar_id"], "calendar_name" => $group["calendar_name"], "is_new" => $group["is_new"],
                "ok" => false, "error" => $group["error"], "error_code" => $group["error_code"], "can_import" => false,
                "target_columns" => array(), "merge_suggestions" => array(), "many_options" => array()
            );

            //the columns of the target client (for the "Maps to" list). A new client starts with the core columns only.
            if ($is_detail && $group["calendar_id"] > 0) {
                foreach ($this->Cc_columns_model->get_for_calendar($group["calendar_id"]) as $target_column) {
                    if (!$target_column->is_core) {
                        $entry["target_columns"][] = array("key" => $target_column->field_key, "label" => $target_column->label, "input_type" => $target_column->input_type);
                    }
                }
            }

            if ($group["error"] === "" || $group["error_code"] === "needs_add_client") {
                //a target that needs a permission still shows its numbers, but can't be imported
                $jobs = $this->_import_group_jobs($r, $group);
                if (!count($jobs)) {
                    $entry["error"] = cc_lang("cc_imp_sheet_no_table");
                } else {
                    $plan = $lib->dry_run_group($jobs, $group["options"], $group["calendar_id"], $r["perms"]);
                    $browser = $lib->plan_for_browser($plan, $is_detail, $is_detail ? $detail_index : null);

                    //value merges that the user can accept / reject, and select columns with many new options
                    foreach ($plan["value_cleanup"] as $cleanup) {
                        foreach ($cleanup["suggestions"] as $suggestion) {
                            if (count($entry["merge_suggestions"]) >= 150) {
                                break;
                            }

                            $entry["merge_suggestions"][] = array(
                                "target_key" => $cleanup["key"], "label" => $cleanup["label"], "from" => $suggestion["from"], "from_key" => $suggestion["from_key"],
                                "to" => $suggestion["to"], "count_from" => $suggestion["count_from"], "count_to" => $suggestion["count_to"],
                                "reason" => $suggestion["reason"], "accept" => (bool) $suggestion["accept"]
                            );
                        }

                        if (!empty($cleanup["over_limit"])) {
                            $entry["many_options"][] = array("key" => $cleanup["key"], "label" => $cleanup["label"], "new_distinct" => (int) $cleanup["new_distinct"], "as_text" => (bool) $cleanup["as_text"]);
                        }
                    }

                    unset($browser["value_cleanup"]);

                    //column keys of the open sheet: the browser remembers a mapping per client by these
                    if ($is_detail && isset($browser["columns"]) && isset($browser["detail_index"])) {
                        foreach ($jobs as $job) {
                            if ((int) $job["index"] === (int) $browser["detail_index"]) {
                                $header_row = 0;
                                foreach ($browser["sheets"] as $one) {
                                    if ((int) $one["index"] === (int) $job["index"]) {
                                        $header_row = (int) $one["header_row"];
                                    }
                                }

                                $keys = $this->_header_keys($lib, $job["sheet"], $header_row);
                                foreach ($browser["columns"] as $i => $column) {
                                    $browser["columns"][$i]["key"] = isset($keys[$column["index"]]) ? $keys[$column["index"]] : "column" . strtolower($column["letter"]);
                                }
                            }
                        }
                    }

                    $entry = array_merge($entry, $browser);
                    $entry["can_import"] = $browser["can_import"] && $group["error"] === "";
                    $entry["error"] = $group["error"] !== "" ? $group["error"] : $plan["error"];
                    $entry["key"] = $group["key"];
                }

                unset($jobs, $plan, $browser);
            }

            $groups[] = $entry;
        }

        $this->_json(array("success" => true, "groups" => $groups, "perms" => $r["perms"]));
    }

    /**
     * Step 3: apply. One database transaction per client group (all its sheets), one run id for the whole import.
     * The plan is built again for every group: nothing is trusted from the preview.
     */
    function import_run() {
        $this->_require_post();
        $this->_require_can("add");
        $this->_require_import_schema();

        $r = $this->_import_resolve();
        $lib = $r["lib"];
        $file_name = $r["meta"]["file_name"];

        $results = array();
        $all_ok = true;
        $ran = 0;
        $has_run_id = false;
        $totals = array("clients" => 0, "clients_created" => 0, "failed" => 0, "imported" => 0, "without_date" => 0, "special_days_written" => 0, "skipped" => 0, "columns_created" => 0, "options_created" => 0, "months_added" => 0);

        foreach ($r["groups"] as $group) {
            if (!$group["checked"]) {
                continue;
            }

            $ran++;
            $block = array(
                "key" => $group["key"], "client_name" => $group["client_name"], "success" => false, "message" => $group["error"],
                "calendar_id" => $group["calendar_id"], "calendar_name" => $group["calendar_name"], "sheet_names" => array()
            );

            if ($group["error"] === "") {
                $jobs = $this->_import_group_jobs($r, $group);
                if (!count($jobs)) {
                    $block["message"] = cc_lang("cc_imp_sheet_no_table");
                } else {
                    $result = $lib->run_group($jobs, $group["options"], $group["target"], $r["perms"], $this->login_user->id, $file_name, $r["run_id"]);
                    if ($result["success"]) {
                        $skipped_rows = $result["skipped_rows"];
                        unset($result["skipped_rows"]);

                        $header_cells = array();
                        $header_row = isset($result["header_row"]) ? $result["header_row"] : 0;
                        foreach ($jobs[0]["sheet"]["rows"] as $pair) {
                            if ($pair[0] == $header_row) {
                                $header_cells = $pair[1];
                                break;
                            }
                        }

                        $result["skipped_list"] = array();
                        foreach (array_slice($skipped_rows, 0, \App\Libraries\Content_calendar_import::SKIPPED_SHOWN) as $row) {
                            $result["skipped_list"][] = array("row" => $row[0], "reason" => $row[1], "sheet" => isset($row[3]) ? $row[3] : "");
                        }

                        $result["skipped_token"] = "";
                        if (count($skipped_rows)) {
                            $result["skipped_token"] = $lib->store_skipped($this->login_user->id, array("header" => $header_cells, "rows" => $skipped_rows));
                        }

                        $result["first_date"] = $result["date_min"];
                        $result["first_ym"] = $result["date_min"] ? substr($result["date_min"], 0, 7) : "";
                        foreach ($jobs as $job) {
                            $block["sheet_names"][] = trim($job["sheet"]["name"]);
                        }

                        $block = array_merge($block, $result);
                        if (!empty($result["run_id"])) {
                            $has_run_id = true;
                        }

                        $totals["clients"]++;
                        $totals["clients_created"] += !empty($result["is_new_client"]) ? 1 : 0;
                        foreach (array("imported", "without_date", "special_days_written", "skipped", "columns_created", "options_created", "months_added") as $total_key) {
                            $totals[$total_key] += (int) $result[$total_key];
                        }
                    } else {
                        $block["message"] = $result["message"];
                    }
                }

                unset($jobs, $result);
            }

            if (!$block["success"]) {
                $all_ok = false;
                $totals["failed"]++;
            }

            $results[] = $block;
        }

        if (!$ran) {
            $this->_fail(cc_lang("cc_invalid_request"));
        }

        //the temp files are deleted after a complete import; after a failure they stay (30 minutes) so the user can retry the failed clients
        if ($all_ok) {
            $lib->delete_parsed($r["token"]);
        }

        $this->_json(array("success" => true, "all_ok" => $all_ok, "run_id" => $has_run_id ? $r["run_id"] : "", "results" => $results, "totals" => $totals));
    }

    /**
     * Soft delete the items of one import batch (only those). The importing user or an admin, only once.
     */
    function import_undo() {
        $this->_require_post();
        $this->_require_can("add");
        $this->_require_import_schema();

        $logs_model = model("App\Models\Cc_import_logs_model");

        //one import run can have several logs (one per sheet): undo them all together
        $run_id = trim((string) $this->request->getPost("run_id"));
        if ($run_id !== "") {
            $this->_import_undo_run($logs_model, $run_id);
        }

        $log = $logs_model->get_log((int) $this->request->getPost("log_id"));
        if (!$log) {
            $this->_fail(cc_lang("cc_imp_log_not_found"));
        }

        if ((int) $log->user_id !== (int) $this->login_user->id && !cc_can("admin_manage")) {
            $this->_forbidden_json();
        }

        if ((int) $log->undone === 1) {
            $this->_fail(cc_lang("cc_imp_already_undone"));
        }

        $db = db_connect("default");
        $db->transBegin();
        try {
            //mark first: only one request can win, so the items are never removed twice
            if (!$logs_model->mark_undone($log->id)) {
                $db->transRollback();
                $this->_fail(cc_lang("cc_imp_already_undone"));
            }

            $removed = $this->Cc_items_model->soft_delete_batch($log->calendar_id, $log->batch_id, $this->login_user->id);
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[ERROR] cc import undo: {message}', array('message' => $e->getMessage()));
            $this->_fail(cc_lang("cc_error"));
        }

        $this->_json(array("success" => true, "removed" => (int) $removed, "calendar_id" => (int) $log->calendar_id, "message" => sprintf(cc_lang("cc_imp_undone"), (int) $removed)));
    }

    //undo every log of one run (needs the run_id column of docs/content_calendar_import_v2_alter.sql)
    private function _import_undo_run($logs_model, $run_id) {
        $db = db_connect("default");
        if (!$db->fieldExists("run_id", "cc_import_logs")) {
            $this->_fail(cc_lang("cc_imp_schema_missing"));
        }

        $logs = $logs_model->get_logs_by_run($run_id);
        if (!count($logs)) {
            $this->_fail(cc_lang("cc_imp_log_not_found"));
        }

        $is_admin = cc_can("admin_manage");
        $active = array();
        foreach ($logs as $log) {
            if ((int) $log->user_id !== (int) $this->login_user->id && !$is_admin) {
                $this->_forbidden_json();
            }

            if ((int) $log->undone !== 1) {
                $active[] = $log;
            }
        }

        if (!count($active)) {
            $this->_fail(cc_lang("cc_imp_already_undone"));
        }

        $removed = 0;
        $calendar_ids = array();
        $db->transBegin();
        try {
            foreach ($active as $log) {
                //mark first: only one request can win a log, so items are never removed twice
                if (!$logs_model->mark_undone($log->id)) {
                    continue;
                }

                $removed += $this->Cc_items_model->soft_delete_batch($log->calendar_id, $log->batch_id, $this->login_user->id);
                $calendar_ids[(int) $log->calendar_id] = true;
            }

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[ERROR] cc import undo run: {message}', array('message' => $e->getMessage()));
            $this->_fail(cc_lang("cc_error"));
        }

        $ids = array_keys($calendar_ids);
        $this->_json(array(
            "success" => true,
            "removed" => (int) $removed,
            "logs" => count($active),
            "run_id" => $run_id,
            "calendar_id" => count($ids) ? (int) $ids[0] : 0,
            "calendar_ids" => $ids,
            "message" => sprintf(cc_lang("cc_imp_undone"), (int) $removed)
        ));
    }

    /**
     * Download the skipped rows of an import as CSV (token from import_run, only for the same user, 30 minutes).
     */
    function import_skipped_csv() {
        $this->_require_can("add");

        $lib = $this->_import_lib();
        $data = $lib->load_skipped((string) $this->request->getGet("token"), $this->login_user->id);
        if (!$data) {
            show_404();
        }

        $csv = $lib->skipped_csv($data["rows"], $data["header"]);
        header("Content-Type: text/csv; charset=utf-8");
        header('Content-Disposition: attachment; filename="content-calendar-skipped-rows.csv"');
        header("X-Content-Type-Options: nosniff");
        echo $csv;
        exit();
    }

    /* ------------------------------------------------------------------ */
    /* task picker endpoints (Add task form integration)                 */
    /* ------------------------------------------------------------------ */

    /**
     * List Content Calendar clients accessible to the user.
     */
    function task_picker_clients() {
        $this->_require_can("view");

        $calendars = $this->Cc_calendars_model->get_all_active();
        $clients = array();
        foreach ($calendars as $c) {
            $clients[] = array(
                "id" => (int) $c->id,
                "name" => $c->name,
                "client_id" => (int) $c->client_id
            );
        }

        $this->_json(array("success" => true, "clients" => $clients));
    }

    /**
     * List months for a calendar: current month and future months only.
     * Built from client's existing months plus next 2 months.
     */
    function task_picker_months() {
        $this->_require_can("view");

        $calendar_id = (int) $this->_input("calendar_id");
        if (!$calendar_id) {
            $calendar_id = (int) $this->_input("id");
        }
        $calendar = $this->_get_calendar_or_fail($calendar_id);

        $cur_year = cc_today_year();
        $cur_month = cc_today_month();
        $cur_ym = sprintf("%04d-%02d", $cur_year, $cur_month);

        $month_counts = $this->Cc_items_model->get_month_counts($calendar_id);
        $candidate_map = array();

        // 1. Current month + next 2 months
        for ($i = 0; $i <= 2; $i++) {
            $dt = new \DateTime(sprintf("%04d-%02d-01", $cur_year, $cur_month));
            if ($i > 0) {
                $dt->modify("+$i month");
            }
            $ym = $dt->format("Y-m");
            $candidate_map[$ym] = array(
                "ym" => $ym,
                "year" => (int) $dt->format("Y"),
                "month" => (int) $dt->format("n"),
                "label" => $dt->format("F Y"),
                "count" => isset($month_counts[$ym]) ? (int) $month_counts[$ym] : 0,
                "is_current" => ($ym === $cur_ym)
            );
        }

        // 2. Existing months from items if >= cur_ym
        foreach ($month_counts as $ym => $count) {
            if ($ym >= $cur_ym && !isset($candidate_map[$ym])) {
                $dt = new \DateTime($ym . "-01");
                $candidate_map[$ym] = array(
                    "ym" => $ym,
                    "year" => (int) $dt->format("Y"),
                    "month" => (int) $dt->format("n"),
                    "label" => $dt->format("F Y"),
                    "count" => (int) $count,
                    "is_current" => ($ym === $cur_ym)
                );
            }
        }

        // 3. Registered months from rise_cc_months
        $registered = $this->Cc_months_model->get_for_calendar($calendar_id);
        foreach ($registered as $rm) {
            $ym = sprintf("%04d-%02d", $rm->year, $rm->month);
            if ($ym >= $cur_ym && !isset($candidate_map[$ym])) {
                $dt = new \DateTime($ym . "-01");
                $candidate_map[$ym] = array(
                    "ym" => $ym,
                    "year" => (int) $dt->format("Y"),
                    "month" => (int) $dt->format("n"),
                    "label" => $dt->format("F Y"),
                    "count" => isset($month_counts[$ym]) ? (int) $month_counts[$ym] : 0,
                    "is_current" => ($ym === $cur_ym)
                );
            }
        }

        ksort($candidate_map);

        $this->_json(array(
            "success" => true,
            "current_ym" => $cur_ym,
            "months" => array_values($candidate_map)
        ));
    }

    /**
     * List dates that have content for a chosen calendar and month.
     */
    function task_picker_dates() {
        $this->_require_can("view");

        $calendar_id = (int) $this->_input("calendar_id");
        if (!$calendar_id) {
            $calendar_id = (int) $this->_input("id");
        }
        $calendar = $this->_get_calendar_or_fail($calendar_id);

        $month = trim((string) $this->_input("month"));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $this->_fail("Invalid month");
        }

        $today = cc_today_date();
        $db = db_connect('default');
        cc_use_utf8mb4($db);

        $query = $db->query("SELECT publish_date, COUNT(*) as cnt 
                             FROM rise_cc_items 
                             WHERE calendar_id = ? 
                               AND publish_date LIKE ? 
                               AND deleted = 0 
                             GROUP BY publish_date 
                             ORDER BY publish_date ASC", array($calendar_id, $month . '-%'));
        $rows = $query->getResult();

        $dates = array();
        foreach ($rows as $r) {
            $d = $r->publish_date;
            $dayNum = (int) substr($d, 8, 2);
            $dates[] = array(
                "date" => $d,
                "day" => $dayNum,
                "count" => (int) $r->cnt,
                "is_today" => ($d === $today),
                "is_past" => ($d < $today)
            );
        }

        $this->_json(array(
            "success" => true,
            "today" => $today,
            "month" => $month,
            "dates" => $dates
        ));
    }

    /**
     * List items for a chosen calendar and date, along with project suggestion.
     */
    function task_picker_items() {
        $this->_require_can("view");

        $calendar_id = (int) $this->_input("calendar_id");
        if (!$calendar_id) {
            $calendar_id = (int) $this->_input("id");
        }
        $calendar = $this->_get_calendar_or_fail($calendar_id);

        $date = trim((string) $this->_input("date"));
        if (!$this->_is_valid_date($date)) {
            $this->_fail("Invalid date");
        }

        $db = db_connect('default');
        cc_use_utf8mb4($db);

        $columns = $this->Cc_columns_model->get_for_calendar($calendar_id);
        $query = $db->query("SELECT * FROM rise_cc_items WHERE calendar_id = ? AND publish_date = ? AND deleted = 0 ORDER BY id ASC", array($calendar_id, $date));
        $raw_items = $query->getResult();

        // Check active tasks for existing task_id
        $task_ids = array();
        foreach ($raw_items as $item) {
            if (!empty($item->task_id)) {
                $task_ids[] = (int) $item->task_id;
            }
        }

        $active_task_ids = array();
        if (!empty($task_ids)) {
            $t_query = $db->query("SELECT id FROM rise_tasks WHERE id IN ? AND deleted = 0", array($task_ids));
            foreach ($t_query->getResult() as $t_row) {
                $active_task_ids[(int) $t_row->id] = true;
            }
        }

        $items_list = array();

        foreach ($raw_items as $item) {
            $item->values = $this->Cc_items_model->decode_values($item->values_json);
            $has_active_task = (!empty($item->task_id) && isset($active_task_ids[(int) $item->task_id]));
            $task_id = $has_active_task ? (int) $item->task_id : null;

            // Build rich-text HTML and plain text descriptions
            $desc_info = cc_format_content_description_html($calendar, $item, $columns);
            $description_html = $desc_info["html"];
            $description = $desc_info["text"];

            $type_str = cc_get_item_display_type($item, $calendar);
            $status_str = (string) $item->status;
            $type_colors = cc_chip_colors($type_str);
            $status_colors = cc_chip_colors($status_str);
            $type_color = $type_colors[0];
            $status_color = $status_colors[0];
            $type_style = cc_pill_style($type_str);
            $status_style = cc_pill_style($status_str);

            $items_list[] = array(
                "id" => (int) $item->id,
                "title" => $item->title,
                "type" => $type_str,
                "type_color" => $type_color,
                "type_style" => $type_style,
                "status" => $status_str,
                "status_color" => $status_color,
                "status_style" => $status_style,
                "has_task" => $has_active_task,
                "task_id" => $task_id,
                "description" => $description,
                "description_html" => $description_html
            );
        }

        helper('content_calendar');
        $project_suggestion = cc_match_project_suggestion($calendar, $date, $this->login_user);

        $this->_json(array(
            "success" => true,
            "date" => $date,
            "items" => $items_list,
            "project_suggestion" => $project_suggestion
        ));
    }

    /**
     * Set content item status from task details.
     * Accessible by: Admin, users with CC edit, task assignee, task collaborators.
     * Syncs directly to the content item without altering RISE task status.
     */
    function task_set_status() {
        $this->_require_post();

        $task_id = (int) $this->request->getPost("task_id");
        $status = trim(str_replace(array("\r\n", "\r"), "\n", (string) $this->request->getPost("status")));

        if ($task_id < 1) {
            $this->_fail("Invalid task ID", array(), 400);
            return;
        }

        $task = model("App\Models\Tasks_model")->get_one($task_id);
        if (!$task || !$task->id || !empty($task->deleted)) {
            $this->_fail("Task not found", array(), 404);
            return;
        }

        // Verify task is linked to an item through rise_cc_task_links
        $db = db_connect('default');
        $q_link = $db->query("SELECT * FROM rise_cc_task_links WHERE task_id = ?", array($task_id));
        $link = ($q_link && is_object($q_link)) ? $q_link->getRow() : null;
        if (!$link || empty($link->item_id)) {
            $this->_fail("Task is not linked to a content item", array(), 400);
            return;
        }

        $item_id = (int) $link->item_id;
        $item = $this->Cc_items_model->get_active($item_id);
        if (!$item) {
            $this->_fail("Content item not found", array(), 404);
            return;
        }

        // Permissions: Admin, CC edit, Assignee, Collaborator
        $user = $this->login_user;
        $user_id = $user ? (int)$user->id : 0;
        $is_admin = !empty($user->is_admin);
        $has_cc_edit = function_exists('cc_can') && cc_can('edit', $user);
        $is_assignee = ((int)$task->assigned_to === $user_id);
        $collabs = array_filter(explode(',', (string)$task->collaborators));
        $is_collab = in_array((string)$user_id, $collabs, true);

        if (!($is_admin || $has_cc_edit || $is_assignee || $is_collab)) {
            $this->response->setStatusCode(403);
            echo json_encode(array("success" => false, "message" => "Forbidden"));
            return;
        }

        // Validate status against that calendar's Status options
        $calendar_id = (int) $item->calendar_id;
        $columns = $this->Cc_columns_model->get_for_calendar($calendar_id, true);
        $status_col = null;
        foreach ($columns as $c) {
            if ($c->field_key === "status") {
                $status_col = $c;
                break;
            }
        }

        if ($status_col) {
            if ($status_col->input_type === "select" || $status_col->input_type === "multi") {
                if ($status === "") {
                    // Empty option allowed
                } else if (!in_array($status, $status_col->options_list, true)) {
                    $this->_fail("Invalid status option", array(), 400);
                    return;
                }
            } else {
                if ($this->_str_len($status) > 60) {
                    $this->_fail("Status too long", array(), 400);
                    return;
                }
            }
        } else {
            if ($this->_str_len($status) > 60) {
                $this->_fail("Status too long", array(), 400);
                return;
            }
        }

        // Update ONLY the status of that item
        $now = cc_now();
        $data = array(
            "status" => $status,
            "updated_by" => $user_id,
            "updated_at" => $now
        );
        $this->Cc_items_model->save_item($data, $item_id);

        $colors = cc_chip_colors($status);
        $style = cc_pill_style($status);

        $this->_json(array(
            "success" => true,
            "task_id" => $task_id,
            "item_id" => $item_id,
            "status" => $status,
            "color" => $colors[0],
            "bg_color" => $colors[0],
            "text_color" => $colors[1],
            "style" => $style,
            "message" => cc_lang("cc_saved") ?: "Saved"
        ));
    }

}
