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
            "add_column" => cc_can("add_column"),
            "admin_manage" => cc_can("admin_manage")
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
            "date" => $item->publish_date,
            "type" => $item->content_type,
            "status" => $item->status,
            "title" => $item->title,
            "values" => (object) $item->values
        );
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

        $initial_date = $this->_is_valid_date((string) $this->request->getGet("date")) ? (string) $this->request->getGet("date") : "";
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
                "delete_calendar" => get_uri("content_calendar/delete_calendar")
            ),
            "perms" => $this->_permission_flags(),
            "lang" => cc_js_lang(),
            "base_url" => get_file_uri(""),
            "import_limits" => \App\Libraries\Content_calendar_import::limits(),
            "first_day_of_week" => 0,
            "initial" => array(
                "calendar_id" => (int) $this->request->getGet("calendar"),
                "ym" => preg_match('/^\d{4}-\d{2}$/', $initial_ym) ? $initial_ym : "",
                "date" => $initial_date
            )
        );

        $view_data["perms"] = $this->_permission_flags();
        return $this->template->rander("content_calendar/index", $view_data);
    }

    //phase 2: Add client is a popup on the calendar page. The old page URL goes back to the calendar.
    function add_client() {
        app_redirect("content_calendar");
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
            $form_values["date"] = $item->publish_date;
            $form_values["type"] = $item->content_type;
            $form_values["status"] = $item->status;
            $form_values["title"] = $item->title;
        } else {
            $date = (string) $this->request->getGet("date");
            if (!$this->_is_valid_date($date)) {
                $ym = (string) $this->request->getGet("ym");
                $date = preg_match('/^\d{4}-\d{2}$/', $ym) && $this->_is_valid_date($ym . "-01") ? $ym . "-01" : date("Y-m-d");
            }

            $form_values["date"] = $date;
            $form_values["type"] = ($type_column && $type_column->options_list) ? $type_column->options_list[0] : "";
            $form_values["status"] = ($status_column && $status_column->options_list) ? $status_column->options_list[0] : "";
        }

        $view_data["calendar"] = $calendar;
        $view_data["columns"] = $columns;
        $view_data["item"] = $item;
        $view_data["form_values"] = $form_values;
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
            "lang" => cc_js_lang()
        );

        return $this->template->rander("content_calendar/item_form", $view_data);
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
            "today" => date("Y-m-d"),
            "calendars" => $calendars,
            "calendar_id" => 0,
            "columns" => array(),
            "months" => array(),
            "year" => (int) date("Y"),
            "month" => (int) date("n"),
            "items" => array(),
            "special_days" => (object) array(),
            "targets" => (object) array(),
            "tiles" => array(),
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
            $year = (int) date("Y");
            $month = (int) date("n");

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

        $tiles = array();
        if ($type_column) {
            foreach ($type_column->options_list as $option) {
                $tiles[] = array(
                    "label" => $option,
                    "count" => isset($type_counts[$option]) ? $type_counts[$option] : 0,
                    "target" => isset($targets[$option]) ? $targets[$option] : 0
                );
            }
        }

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

        foreach ($columns as $column) {
            $key = $column->field_key;
            $raw = isset($posted[$key]) ? $posted[$key] : null;
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

        if (!isset($errors["date"]) && $core["date"] === "") {
            $errors["date"] = cc_lang("cc_date_required");
        }

        if (!isset($errors["title"]) && $core["title"] === "") {
            $errors["title"] = cc_lang("cc_title_required");
        }

        if (count($errors)) {
            $this->_fail(cc_lang("cc_please_fix_errors"), $errors);
        }

        $now = cc_now();
        $data = array(
            "calendar_id" => $calendar_id,
            "publish_date" => $core["date"],
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
        $this->Cc_months_model->ensure_for_date($calendar_id, $core["date"]);

        $this->_json(array(
            "success" => true,
            "id" => $saved_id,
            "calendar_id" => $calendar_id,
            "date" => $core["date"],
            "redirect" => $this->_month_url($calendar_id, $core["date"]),
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

        $value = trim((string) $raw);

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
        $this->_require_can("admin_manage");

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
        return array(
            "add" => cc_can("add"),
            "admin_manage" => cc_can("admin_manage"),
            "manage_options" => cc_can("manage_options"),
            "add_client" => cc_can("add_client"),
            "add_column" => cc_can("add_column")
        );
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
     * Step 1: upload, check size / type / content, parse once, keep the parsed rows in a temp file under a token.
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
        } else if (empty($perms["add_client"]) && empty($perms["admin_manage"])) {
            $this->_forbidden_json();
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

        //random name in writable/files/content_calendar/imports/, deleted again right after parsing
        $upload_path = $lib->dir() . $lib->new_token() . ".upload";
        if (!move_uploaded_file($_FILES["file"]["tmp_name"], $upload_path)) {
            $this->_fail(cc_lang("cc_imp_upload_failed"));
        }

        try {
            $result = $lib->read_file($upload_path, (string) $this->request->getPost("file_type"));
        } catch (\RuntimeException $e) {
            @unlink($upload_path);
            $this->_fail($e->getMessage());
        } catch (\Throwable $e) {
            @unlink($upload_path);
            log_message('error', '[ERROR] cc import parse: {message}', array('message' => $e->getMessage()));
            $this->_fail(cc_lang("cc_imp_err_corrupt"));
        }

        @unlink($upload_path);

        $sheets = $result["sheets"];
        if ($result["type"] === "csv") {
            $sheets[0]["name"] = mb_substr(pathinfo($file_name, PATHINFO_FILENAME), 0, 120, "UTF-8");
        }

        $calendars = $this->Cc_calendars_model->get_all_active();
        $sheet_infos = array();
        $usable = 0;
        $first_error = "";
        foreach ($sheets as $index => $sheet) {
            $info = $lib->sheet_summary($sheet, $index);
            $info["match_calendar_id"] = $lib->match_calendar($sheet["name"], $calendars);
            if ($info["usable"]) {
                $usable++;
            } else if ($first_error === "") {
                $first_error = $info["error"];
            }

            $sheet_infos[] = $info;
        }

        if (!$usable) {
            $this->_fail($first_error !== "" ? $first_error : cc_lang("cc_imp_err_no_sheets"));
        }

        $token = $lib->store_parsed($this->login_user->id, $calendar_id, $file_name, $result["type"], $sheets);
        if ($token === "") {
            $this->_fail(cc_lang("cc_imp_upload_failed"));
        }

        $calendars_array = array();
        foreach ($calendars as $calendar) {
            $calendars_array[] = array("id" => (int) $calendar->id, "name" => $calendar->name);
        }

        $this->_json(array(
            "success" => true,
            "token" => $token,
            "file_name" => $file_name,
            "type" => $result["type"],
            "type_label" => $lib->type_label($result["type"]),
            "notes" => $result["notes"],
            "sheets" => $sheet_infos,
            "calendars" => $calendars_array,
            "calendar_id" => $calendar_id,
            "perms" => $perms,
            "limits" => \App\Libraries\Content_calendar_import::limits()
        ));
    }

    /**
     * Shared by preview and run: read the posted choices, load the parsed sheets, and resolve each sheet's target.
     * Returns array("lib", "payload", "stored", "config", "perms", "jobs").
     */
    private function _import_resolve() {
        $lib = $this->_import_lib();
        $perms = $this->_import_perms();
        $payload = $this->_import_payload();

        $stored = $lib->load_parsed(isset($payload["token"]) ? $payload["token"] : "", $this->login_user->id);
        if (!$stored) {
            $this->_fail(cc_lang("cc_imp_expired"));
        }

        $options = isset($payload["options"]) && is_array($payload["options"]) ? $payload["options"] : array();
        $sheet_configs = isset($payload["sheets"]) && is_array($payload["sheets"]) ? $payload["sheets"] : array();
        $single = (isset($payload["mode"]) ? $payload["mode"] : "single") !== "multi";

        $jobs = array();
        $seen_new_names = array();
        foreach ($sheet_configs as $sheet_config) {
            if (!is_array($sheet_config) || !isset($sheet_config["index"]) || !isset($stored["sheets"][(int) $sheet_config["index"]])) {
                continue;
            }

            $index = (int) $sheet_config["index"];
            $sheet = $stored["sheets"][$index];
            $summary = $lib->sheet_summary($sheet, $index);
            $config = array_merge($options, array(
                "header_row" => isset($sheet_config["header_row"]) ? $sheet_config["header_row"] : 0,
                "mapping" => isset($sheet_config["mapping"]) && is_array($sheet_config["mapping"]) ? $sheet_config["mapping"] : array()
            ));

            $job = array("index" => $index, "sheet" => $sheet, "summary" => $summary, "config" => $config, "checked" => !empty($sheet_config["checked"]), "error" => "", "error_code" => "", "target" => array(), "calendar_id" => 0, "calendar_name" => "", "is_new" => false);

            $target = isset($sheet_config["target"]) ? (string) $sheet_config["target"] : "";
            if (!$summary["usable"]) {
                $job["error"] = $summary["error"];
            } else if ($target === "new") {
                $name = trim(preg_replace('/\s+/u', " ", isset($sheet_config["new_name"]) ? (string) $sheet_config["new_name"] : $sheet["name"]));
                $job["is_new"] = true;
                $job["calendar_name"] = $name;
                if (empty($perms["add_client"]) && empty($perms["admin_manage"])) {
                    $job["error"] = cc_lang("cc_imp_needs_admin");
                    $job["error_code"] = "needs_admin";
                } else if ($name === "") {
                    $job["error"] = cc_lang("cc_name_required");
                } else if (mb_strlen($name, "UTF-8") > 120) {
                    $job["error"] = cc_lang("cc_name_too_long");
                } else if ($this->Cc_calendars_model->name_exists($name) || isset($seen_new_names[mb_strtolower($name, "UTF-8")])) {
                    $job["error"] = cc_lang("cc_client_exists");
                } else {
                    $seen_new_names[mb_strtolower($name, "UTF-8")] = true;
                    $job["target"] = array("new_name" => $name);
                }
            } else {
                $calendar = $this->Cc_calendars_model->get_active((int) $target);
                if (!$calendar) {
                    $job["error"] = cc_lang("cc_calendar_not_found");
                } else {
                    $job["calendar_id"] = (int) $calendar->id;
                    $job["calendar_name"] = $calendar->name;
                    $job["target"] = array("calendar_id" => (int) $calendar->id);
                }
            }

            //the parsed file belongs to the client that was chosen in step 1
            if ($single && $job["error"] === "" && (int) $stored["calendar_id"] !== $job["calendar_id"]) {
                $job["error"] = cc_lang("cc_imp_expired");
            }

            $jobs[] = $job;
        }

        if (!count($jobs)) {
            $this->_fail(cc_lang("cc_invalid_request"));
        }

        return array("lib" => $lib, "payload" => $payload, "stored" => $stored, "perms" => $perms, "jobs" => $jobs);
    }

    /**
     * Step 2: dry run. Same planning function as import_run, nothing is written.
     */
    function import_preview() {
        $this->_require_post();
        $this->_require_can("add");
        $this->_require_import_schema();

        $r = $this->_import_resolve();
        $lib = $r["lib"];
        $detail = isset($r["payload"]["detail"]) ? (int) $r["payload"]["detail"] : -1;

        $sheets = array();
        foreach ($r["jobs"] as $job) {
            $entry = array(
                "index" => $job["index"], "name" => $job["sheet"]["name"], "checked" => $job["checked"],
                "target_calendar_id" => $job["calendar_id"], "target_name" => $job["calendar_name"], "is_new" => $job["is_new"],
                "ok" => false, "error" => $job["error"], "error_code" => $job["error_code"], "can_import" => false,
                "target_columns" => array()
            );

            //the columns of the target client (for the "Maps to" list). A new client starts with the core columns only.
            if ($job["calendar_id"] > 0) {
                foreach ($this->Cc_columns_model->get_for_calendar($job["calendar_id"]) as $target_column) {
                    if (!$target_column->is_core) {
                        $entry["target_columns"][] = array("key" => $target_column->field_key, "label" => $target_column->label, "input_type" => $target_column->input_type);
                    }
                }
            }

            if ($job["error"] === "" || $job["error_code"] === "needs_admin") {
                //a locked target (needs admin) still shows its numbers, but can't be imported
                $plan = $lib->dry_run_sheet($job["sheet"], $job["config"], $job["calendar_id"], $r["perms"]);
                $entry = array_merge($entry, $lib->plan_for_browser($plan, $job["index"] === $detail));
                $entry["can_import"] = $entry["can_import"] && $job["error"] === "";
                $entry["error"] = $job["error"] !== "" ? $job["error"] : $plan["error"];
            }

            $sheets[] = $entry;
        }

        $this->_json(array("success" => true, "sheets" => $sheets, "perms" => $r["perms"]));
    }

    /**
     * Step 3: apply. One database transaction per client (sheet).
     */
    function import_run() {
        $this->_require_post();
        $this->_require_can("add");
        $this->_require_import_schema();

        $r = $this->_import_resolve();
        $lib = $r["lib"];
        $file_name = $r["stored"]["file_name"];

        $results = array();
        $all_ok = true;
        $ran = 0;
        foreach ($r["jobs"] as $job) {
            if (!$job["checked"]) {
                continue;
            }

            $ran++;
            $block = array("index" => $job["index"], "sheet_name" => $job["sheet"]["name"], "success" => false, "message" => $job["error"], "calendar_name" => $job["calendar_name"]);

            if ($job["error"] === "") {
                $result = $lib->run_sheet($job["sheet"], $job["config"], $job["target"], $r["perms"], $this->login_user->id, $file_name);
                if ($result["success"]) {
                    $skipped_rows = $result["skipped_rows"];
                    unset($result["skipped_rows"]);

                    $header_cells = array();
                    $header_row = isset($result["header_row"]) ? $result["header_row"] : 0;
                    foreach ($job["sheet"]["rows"] as $pair) {
                        if ($pair[0] == $header_row) {
                            $header_cells = $pair[1];
                            break;
                        }
                    }

                    $result["skipped_list"] = array();
                    foreach (array_slice($skipped_rows, 0, \App\Libraries\Content_calendar_import::SKIPPED_SHOWN) as $row) {
                        $result["skipped_list"][] = array("row" => $row[0], "reason" => $row[1]);
                    }

                    $result["skipped_token"] = "";
                    if (count($skipped_rows)) {
                        $result["skipped_token"] = $lib->store_skipped($this->login_user->id, array("header" => $header_cells, "rows" => $skipped_rows));
                    }

                    $result["first_date"] = $result["date_min"];
                    $result["first_ym"] = $result["date_min"] ? substr($result["date_min"], 0, 7) : "";
                    $block = array_merge($block, $result);
                } else {
                    $block["message"] = $result["message"];
                }
            }

            if (!$block["success"]) {
                $all_ok = false;
            }

            $results[] = $block;
        }

        if (!$ran) {
            $this->_fail(cc_lang("cc_invalid_request"));
        }

        //the temp file is deleted after a complete import; after a failure it stays (30 minutes) so the user can retry
        if ($all_ok) {
            $lib->delete_parsed($r["payload"]["token"]);
        }

        $this->_json(array("success" => true, "all_ok" => $all_ok, "results" => $results));
    }

    /**
     * Soft delete the items of one import batch (only those). The importing user or an admin, only once.
     */
    function import_undo() {
        $this->_require_post();
        $this->_require_can("add");
        $this->_require_import_schema();

        $logs_model = model("App\Models\Cc_import_logs_model");
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

}
