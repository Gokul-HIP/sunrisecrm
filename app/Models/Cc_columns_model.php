<?php

namespace App\Models;

class Cc_columns_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'cc_columns';
        parent::__construct($this->table);
        helper('content_calendar');
        cc_use_utf8mb4($this->db);
    }

    /**
     * Allowed input types.
     */
    function get_input_types() {
        return array("text", "long", "select", "multi", "date", "link", "number", "check", "file");
    }

    function is_option_type($input_type) {
        return $input_type === "select" || $input_type === "multi";
    }

    /**
     * The 4 core columns every calendar has: field_key => [label, input_type, options].
     */
    function get_core_definitions() {
        return array(
            "date" => array("label" => "Date", "input_type" => "date", "options" => array()),
            "type" => array("label" => "Content Type", "input_type" => "select", "options" => array("Design", "Reel", "Story", "Carousel")),
            "title" => array("label" => "Title", "input_type" => "text", "options" => array()),
            "status" => array("label" => "Status", "input_type" => "select", "options" => array("Planned", "In design", "Scheduled", "Posted"))
        );
    }

    function encode_options($options) {
        if (!is_array($options)) {
            $options = array();
        }

        //escaped unicode keeps the JSON ASCII safe
        return json_encode(array_values($options));
    }

    function decode_options($json) {
        if (!$json) {
            return array();
        }

        $list = json_decode($json, true);
        if (!is_array($list)) {
            return array();
        }

        $result = array();
        foreach ($list as $option) {
            if (is_string($option) && $option !== "") {
                $result[] = $option;
            }
        }

        return $result;
    }

    /**
     * Add the decoded options list (options_list) to a column row.
     */
    private function _prepare($row) {
        if ($row) {
            $row->options_list = $this->decode_options($row->options);
            $row->is_core = (int) $row->is_core;
            $row->is_visible = (int) $row->is_visible;
            $row->sort_order = (int) $row->sort_order;
        }

        return $row;
    }

    /**
     * Non-deleted columns of a calendar in the calendar's order.
     *
     * @param int $calendar_id
     * @param bool $only_visible
     * @return array of objects (with options_list)
     */
    function get_for_calendar($calendar_id, $only_visible = false) {
        $builder = $this->db->table($this->table)
                ->where("calendar_id", (int) $calendar_id)
                ->where("deleted", 0);
        if ($only_visible) {
            $builder->where("is_visible", 1);
        }

        $rows = $builder->orderBy("sort_order", "ASC")->orderBy("id", "ASC")->get()->getResult();
        foreach ($rows as $key => $row) {
            $rows[$key] = $this->_prepare($row);
        }

        return $rows;
    }

    /**
     * Options: calendar_id (required), only_visible, id.
     */
    function get_details($options = array()) {
        $calendar_id = (int) get_array_value($options, "calendar_id");
        $columns = $this->get_for_calendar($calendar_id, get_array_value($options, "only_visible") ? true : false);

        $id = (int) get_array_value($options, "id");
        if ($id) {
            foreach ($columns as $column) {
                if ((int) $column->id === $id) {
                    return array($column);
                }
            }

            return array();
        }

        return $columns;
    }

    /**
     * One non-deleted column or null.
     */
    function get_active($id) {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $row = $this->db->table($this->table)->where("id", $id)->where("deleted", 0)->get(1)->getRow();
        return $row ? $this->_prepare($row) : null;
    }

    /**
     * Non-deleted column by field_key of a calendar, or null.
     */
    function get_by_key($calendar_id, $field_key, $include_deleted = false) {
        $builder = $this->db->table($this->table)
                ->where("calendar_id", (int) $calendar_id)
                ->where("field_key", $field_key);
        if (!$include_deleted) {
            $builder->where("deleted", 0);
        }

        $row = $builder->get(1)->getRow();
        return $row ? $this->_prepare($row) : null;
    }

    /**
     * Is there another non-deleted column with this label (case-insensitive) in the calendar?
     */
    function label_exists($calendar_id, $label, $exclude_id = 0) {
        $builder = $this->db->table($this->table)
                ->where("calendar_id", (int) $calendar_id)
                ->where("deleted", 0)
                ->where("LOWER(label) =", mb_strtolower(trim($label), 'UTF-8'));
        if ((int) $exclude_id > 0) {
            $builder->where("id !=", (int) $exclude_id);
        }

        return $builder->countAllResults() > 0;
    }

    /**
     * field_key from the label, unique within the calendar (deleted columns keep their keys).
     */
    function make_unique_key($calendar_id, $label) {
        $base = cc_slug($label);
        $key = $base;
        $i = 2;
        while ($this->get_by_key($calendar_id, $key, true)) {
            $suffix = "_" . $i;
            $key = substr($base, 0, 60 - strlen($suffix)) . $suffix;
            $i++;
        }

        return $key;
    }

    function get_next_sort($calendar_id) {
        $row = $this->db->table($this->table)->selectMax("sort_order", "max_sort")->where("calendar_id", (int) $calendar_id)->where("deleted", 0)->get()->getRow();
        return $row && $row->max_sort !== null ? ((int) $row->max_sort + 1) : 1;
    }

    /**
     * Create the 4 core columns with their default options.
     */
    function seed_core($calendar_id) {
        $sort = 1;
        foreach ($this->get_core_definitions() as $key => $def) {
            $this->db->table($this->table)->insert(array(
                "calendar_id" => (int) $calendar_id,
                "field_key" => $key,
                "label" => $def["label"],
                "input_type" => $def["input_type"],
                "options" => $this->is_option_type($def["input_type"]) ? $this->encode_options($def["options"]) : null,
                "is_core" => 1,
                "is_visible" => 1,
                "sort_order" => $sort,
                "deleted" => 0
            ));
            $sort++;
        }
    }

    /**
     * Copy every non-deleted column (and options) of another calendar into an EMPTY calendar.
     * If the source has no complete set of core columns, the 4 core columns are seeded instead.
     */
    function copy_all($from_calendar_id, $to_calendar_id) {
        $source = $this->get_for_calendar($from_calendar_id);

        $core_keys = array();
        foreach ($source as $column) {
            if ($column->is_core) {
                $core_keys[] = $column->field_key;
            }
        }

        $required = array_keys($this->get_core_definitions());
        if (count(array_intersect($required, $core_keys)) !== count($required)) {
            $this->seed_core($to_calendar_id);
            return;
        }

        $sort = 1;
        foreach ($source as $column) {
            $this->db->table($this->table)->insert(array(
                "calendar_id" => (int) $to_calendar_id,
                "field_key" => $column->field_key,
                "label" => $column->label,
                "input_type" => $column->input_type,
                "options" => $this->is_option_type($column->input_type) ? $this->encode_options($column->options_list) : null,
                "is_core" => $column->is_core,
                "is_visible" => $column->is_core ? 1 : $column->is_visible,
                "sort_order" => $sort,
                "deleted" => 0
            ));
            $sort++;
        }
    }

    /**
     * Add the custom columns of another calendar that this calendar does not have (matched by label).
     * Nothing is removed.
     *
     * @return int number of columns added
     */
    function copy_custom($from_calendar_id, $to_calendar_id) {
        $added = 0;
        foreach ($this->get_for_calendar($from_calendar_id) as $column) {
            if ($column->is_core) {
                continue;
            }

            if ($this->label_exists($to_calendar_id, $column->label)) {
                continue;
            }

            $this->add_column($to_calendar_id, $column->label, $column->input_type, $column->options_list, $column->is_visible);
            $added++;
        }

        return $added;
    }

    /**
     * Add a custom column at the end. If a removed column with the same key and type exists,
     * it is brought back (its stored values are kept), so a re-added "Goal" column stays the Goal column.
     *
     * @return int column id
     */
    function add_column($calendar_id, $label, $input_type, $options_list = array(), $is_visible = 1) {
        $calendar_id = (int) $calendar_id;
        $options = $this->is_option_type($input_type) ? $this->encode_options($options_list) : null;
        $sort = $this->get_next_sort($calendar_id);

        $slug = cc_slug($label);
        $removed = $this->get_by_key($calendar_id, $slug, true);
        if ($removed && (int) $removed->deleted === 1 && !$removed->is_core && $removed->input_type === $input_type) {
            $this->db->table($this->table)->where("id", (int) $removed->id)->update(array(
                "label" => $label,
                "options" => $options,
                "is_visible" => $is_visible ? 1 : 0,
                "sort_order" => $sort,
                "deleted" => 0
            ));
            return (int) $removed->id;
        }

        $this->db->table($this->table)->insert(array(
            "calendar_id" => $calendar_id,
            "field_key" => $this->make_unique_key($calendar_id, $label),
            "label" => $label,
            "input_type" => $input_type,
            "options" => $options,
            "is_core" => 0,
            "is_visible" => $is_visible ? 1 : 0,
            "sort_order" => $sort,
            "deleted" => 0
        ));

        return (int) $this->db->insertID();
    }

    function update_column($id, $data) {
        return $this->db->table($this->table)->where("id", (int) $id)->update($data);
    }

    function update_options($id, $options_list) {
        return $this->update_column($id, array("options" => $this->encode_options($options_list)));
    }

    function soft_delete($id) {
        return $this->update_column($id, array("deleted" => 1));
    }

    /**
     * Save a new order (list of column ids). Ids that do not belong to the calendar are ignored;
     * columns missing from the list keep their relative order at the end.
     */
    function set_order($calendar_id, $ordered_ids) {
        $columns = $this->get_for_calendar($calendar_id);
        $by_id = array();
        foreach ($columns as $column) {
            $by_id[(int) $column->id] = $column;
        }

        $final = array();
        foreach ($ordered_ids as $id) {
            $id = (int) $id;
            if (isset($by_id[$id]) && !in_array($id, $final, true)) {
                $final[] = $id;
            }
        }

        foreach ($columns as $column) {
            if (!in_array((int) $column->id, $final, true)) {
                $final[] = (int) $column->id;
            }
        }

        $sort = 1;
        foreach ($final as $id) {
            if ((int) $by_id[$id]->sort_order !== $sort) {
                $this->update_column($id, array("sort_order" => $sort));
            }
            $sort++;
        }

        return $final;
    }

    /**
     * Move a column one step earlier ("up") or later ("down").
     *
     * @return bool false when it can't move
     */
    function move($calendar_id, $id, $direction) {
        $columns = $this->get_for_calendar($calendar_id);
        $ids = array();
        foreach ($columns as $column) {
            $ids[] = (int) $column->id;
        }

        $index = array_search((int) $id, $ids, true);
        if ($index === false) {
            return false;
        }

        $swap_with = $direction === "up" ? $index - 1 : $index + 1;
        if ($swap_with < 0 || $swap_with >= count($ids)) {
            return false;
        }

        $temp = $ids[$index];
        $ids[$index] = $ids[$swap_with];
        $ids[$swap_with] = $temp;

        $this->set_order($calendar_id, $ids);
        return true;
    }

}
