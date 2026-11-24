<?php

namespace App\Models;

class Cc_items_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'cc_items';
        parent::__construct($this->table);
        helper('content_calendar');
        cc_use_utf8mb4($this->db);
    }

    /**
     * Decode values_json to an array.
     */
    function decode_values($json) {
        if (!$json) {
            return array();
        }

        $values = json_decode($json, true);
        return is_array($values) ? $values : array();
    }

    function encode_values($values) {
        if (!is_array($values) || !count($values)) {
            return "{}";
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE);
    }

    /**
     * One non-deleted item (values_json decoded into ->values) or null.
     */
    function get_active($id) {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $row = $this->db->table($this->table)->where("id", $id)->where("deleted", 0)->get(1)->getRow();
        if ($row) {
            $row->values = $this->decode_values($row->values_json);
        }

        return $row ? $row : null;
    }

    /**
     * Items of ONE calendar and ONE month, sorted by date.
     * Options: calendar_id (required), year, month (required), publish_date (a single day).
     *
     * @return array of objects (with ->values)
     */
    function get_details($options = array()) {
        $calendar_id = (int) get_array_value($options, "calendar_id");
        $year = (int) get_array_value($options, "year");
        $month = (int) get_array_value($options, "month");
        if (!$calendar_id || !$year || $month < 1 || $month > 12) {
            return array();
        }

        $from = sprintf("%04d-%02d-01", $year, $month);
        $to = date("Y-m-t", strtotime($from));

        $builder = $this->db->table($this->table)
                ->where("calendar_id", $calendar_id)
                ->where("deleted", 0)
                ->where("publish_date IS NOT NULL", null, false)
                ->where("publish_date >=", $from)
                ->where("publish_date <=", $to);

        $publish_date = get_array_value($options, "publish_date");
        if ($publish_date) {
            $builder->where("publish_date", $publish_date);
        }

        $rows = $builder->orderBy("publish_date", "ASC")->orderBy("id", "ASC")->get()->getResult();
        foreach ($rows as $key => $row) {
            $row->values = $this->decode_values($row->values_json);
            $rows[$key] = $row;
        }

        return $rows;
    }

    /**
     * Number of items per month for a calendar: ["2026-11" => 5, ...].
     */
    function get_month_counts($calendar_id) {
        $rows = $this->db->query("SELECT YEAR(publish_date) AS y, MONTH(publish_date) AS m, COUNT(*) AS n FROM " . $this->table . " WHERE calendar_id=? AND deleted=0 AND publish_date IS NOT NULL GROUP BY YEAR(publish_date), MONTH(publish_date)", array((int) $calendar_id))->getResult();
        $counts = array();
        foreach ($rows as $row) {
            $counts[sprintf("%04d-%02d", $row->y, $row->m)] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * Items of a month per content type: ["Reel" => 3, ...] (for the summary tiles).
     */
    function get_type_counts($calendar_id, $year, $month) {
        $from = sprintf("%04d-%02d-01", (int) $year, (int) $month);
        $to = date("Y-m-t", strtotime($from));

        $rows = $this->db->query("SELECT content_type, COUNT(*) AS n FROM " . $this->table . " WHERE calendar_id=? AND deleted=0 AND publish_date IS NOT NULL AND publish_date>=? AND publish_date<=? GROUP BY content_type", array((int) $calendar_id, $from, $to))->getResult();
        $counts = array();
        foreach ($rows as $row) {
            $counts[$row->content_type] = (int) $row->n;
        }

        return $counts;
    }

    /**
     * How many non-deleted items of the calendar use this option value in a column?
     * Core "type" / "status" use the real columns, every other column is searched in values_json.
     * Comparison is exact (same as the select validation).
     */
    function count_option_usage($calendar_id, $field_key, $option) {
        $calendar_id = (int) $calendar_id;

        if ($field_key === "type" || $field_key === "status") {
            $field = $field_key === "type" ? "content_type" : "status";
            //BINARY: exact comparison, the options list is case-sensitive
            $row = $this->db->query("SELECT COUNT(*) AS n FROM " . $this->table . " WHERE calendar_id=? AND deleted=0 AND BINARY $field=BINARY ?", array($calendar_id, $option))->getRow();
            return $row ? (int) $row->n : 0;
        }

        //values_json can't be compared exactly in SQL on both MySQL and MariaDB, so pre-filter with LIKE and verify in PHP
        //(field_key is a slug, so "_" as a LIKE wildcard only widens the pre-filter)
        $like = '%"' . $field_key . '"%';
        $rows = $this->db->query("SELECT values_json FROM " . $this->table . " WHERE calendar_id=? AND deleted=0 AND values_json LIKE ?", array($calendar_id, $like))->getResult();

        $count = 0;
        foreach ($rows as $row) {
            $values = $this->decode_values($row->values_json);
            $value = isset($values[$field_key]) ? $values[$field_key] : null;
            if (is_array($value)) {
                if (in_array($option, $value, true)) {
                    $count++;
                }
            } else if ($value !== null && (string) $value === (string) $option) {
                $count++;
            }
        }

        return $count;
    }

    function soft_delete_item($id, $user_id) {
        return $this->db->table($this->table)->where("id", (int) $id)->update(array(
                    "deleted" => 1,
                    "updated_by" => (int) $user_id,
                    "updated_at" => cc_now()
        ));
    }

    /**
     * Insert or update an item row. $data must already be validated.
     *
     * @return int item id
     */
    function save_item($data, $id = 0) {
        $id = (int) $id;
        if ($id) {
            $this->db->table($this->table)->where("id", $id)->update($data);
            return $id;
        }

        $this->db->table($this->table)->insert($data);
        return (int) $this->db->insertID();
    }

    /* ---------------------------------------------------------------- */
    /* import (phase 2)                                                  */
    /* ---------------------------------------------------------------- */

    /**
     * Insert many rows at once (the import uses batches of 200). Rows must already be validated.
     */
    function insert_batch_rows($rows) {
        if (!count($rows)) {
            return 0;
        }

        return $this->db->table($this->table)->insertBatch($rows);
    }

    /**
     * Keys of the non-deleted items of a calendar, used to skip duplicates:
     * "YYYY-MM-DD|lowercase title" => true. One query for the whole import.
     */
    function get_existing_keys($calendar_id) {
        $rows = $this->db->query("SELECT publish_date, title FROM " . $this->table . " WHERE calendar_id=? AND deleted=0", array((int) $calendar_id))->getResult();
        $keys = array();
        foreach ($rows as $row) {
            //undated items use "null|title" (the import can check it the same way)
            $date = $row->publish_date ? $row->publish_date : "null";
            $keys[$date . "|" . cc_title_key($row->title)] = true;
        }

        return $keys;
    }

    /**
     * Soft delete the items of several import batches (one import run).
     *
     * @return int number of items removed
     */
    function soft_delete_batches($calendar_id, $batch_ids, $user_id) {
        if (!is_array($batch_ids) || !count($batch_ids)) {
            return 0;
        }

        $this->db->table($this->table)
                ->where("calendar_id", (int) $calendar_id)
                ->whereIn("import_batch_id", array_values($batch_ids))
                ->where("deleted", 0)
                ->update(array(
                    "deleted" => 1,
                    "updated_by" => (int) $user_id,
                    "updated_at" => cc_now()
        ));

        return (int) $this->db->affectedRows();
    }

    /* ---------------------------------------------------------------- */
    /* items without a date ("Without date")                             */
    /* ---------------------------------------------------------------- */

    private function _undated_builder($calendar_id, $q = "") {
        $builder = $this->db->table($this->table)
                ->where("calendar_id", (int) $calendar_id)
                ->where("deleted", 0)
                ->where("publish_date IS NULL", null, false);

        $q = trim((string) $q);
        if ($q !== "") {
            $builder->like("title", $q);
        }

        return $builder;
    }

    /**
     * Undated items of a calendar, newest first. Options: q (title search), limit (default 50), offset.
     *
     * @return array of objects (with ->values)
     */
    function get_undated($calendar_id, $options = array()) {
        $limit = (int) get_array_value($options, "limit");
        if ($limit < 1 || $limit > 200) {
            $limit = 50;
        }

        $offset = max(0, (int) get_array_value($options, "offset"));

        $rows = $this->_undated_builder($calendar_id, get_array_value($options, "q"))
                        ->orderBy("id", "ASC")->limit($limit, $offset)->get()->getResult();
        foreach ($rows as $key => $row) {
            $row->values = $this->decode_values($row->values_json);
            $rows[$key] = $row;
        }

        return $rows;
    }

    /**
     * Number of undated items (optionally filtered by a title search).
     */
    function count_undated($calendar_id, $q = "") {
        return (int) $this->_undated_builder($calendar_id, $q)->countAllResults();
    }

    /**
     * Give an undated or dated item a date. Optionally drops the "From sheet" text.
     */
    function assign_date($id, $ymd, $user_id, $clear_src_text = false) {
        $item = $this->get_active($id);
        if (!$item) {
            return false;
        }

        $data = array(
            "publish_date" => $ymd,
            "updated_by" => (int) $user_id,
            "updated_at" => cc_now()
        );

        if ($clear_src_text && isset($item->values["_src_date_text"])) {
            $values = $item->values;
            unset($values["_src_date_text"]);
            $data["values_json"] = $this->encode_values($values);
        }

        $this->db->table($this->table)->where("id", (int) $id)->update($data);
        return true;
    }

    /**
     * Soft delete the non-deleted items of one import batch (and only those).
     *
     * @return int number of items removed
     */
    function soft_delete_batch($calendar_id, $batch_id, $user_id) {
        $this->db->table($this->table)
                ->where("calendar_id", (int) $calendar_id)
                ->where("import_batch_id", $batch_id)
                ->where("deleted", 0)
                ->update(array(
                    "deleted" => 1,
                    "updated_by" => (int) $user_id,
                    "updated_at" => cc_now()
        ));

        return (int) $this->db->affectedRows();
    }

    /**
     * Live (non-deleted) items of an import batch.
     */
    function count_batch($calendar_id, $batch_id) {
        return $this->db->table($this->table)
                        ->where("calendar_id", (int) $calendar_id)
                        ->where("import_batch_id", $batch_id)
                        ->where("deleted", 0)
                        ->countAllResults();
    }

}
