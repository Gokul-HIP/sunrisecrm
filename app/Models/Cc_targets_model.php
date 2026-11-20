<?php

namespace App\Models;

class Cc_targets_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'cc_targets';
        parent::__construct($this->table);
        helper('content_calendar');
        cc_use_utf8mb4($this->db);
    }

    /**
     * Targets of a calendar: ["Reel" => 12, ...].
     */
    function get_for_calendar($calendar_id) {
        $rows = $this->db->table($this->table)->where("calendar_id", (int) $calendar_id)->get()->getResult();
        $targets = array();
        foreach ($rows as $row) {
            $targets[$row->content_type] = (int) $row->target_count;
        }

        return $targets;
    }

    function get_details($options = array()) {
        $builder = $this->db->table($this->table)->where("calendar_id", (int) get_array_value($options, "calendar_id"));
        $content_type = get_array_value($options, "content_type");
        if ($content_type) {
            $builder->where("content_type", $content_type);
        }

        return $builder->orderBy("content_type", "ASC")->get()->getResult();
    }

    /**
     * Save a target. 0 removes the target (the tile then shows "this month" with no bar).
     */
    function save_target($calendar_id, $content_type, $target_count) {
        $calendar_id = (int) $calendar_id;
        $target_count = (int) $target_count;

        if ($target_count < 1) {
            $this->db->table($this->table)->where("calendar_id", $calendar_id)->where("content_type", $content_type)->delete();
            return 0;
        }

        $this->db->query("INSERT INTO " . $this->table . " (calendar_id, content_type, target_count) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE target_count=VALUES(target_count)", array($calendar_id, $content_type, $target_count));
        return $target_count;
    }

}
