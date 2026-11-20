<?php

namespace App\Models;

class Cc_special_days_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'cc_special_days';
        parent::__construct($this->table);
        helper('content_calendar');
        cc_use_utf8mb4($this->db);
    }

    /**
     * Special days of one month: ["2026-11-14" => "Children's Day", ...].
     */
    function get_for_month($calendar_id, $year, $month) {
        $from = sprintf("%04d-%02d-01", (int) $year, (int) $month);
        $to = date("Y-m-t", strtotime($from));

        $rows = $this->db->table($this->table)
                        ->where("calendar_id", (int) $calendar_id)
                        ->where("special_date >=", $from)
                        ->where("special_date <=", $to)
                        ->get()->getResult();

        $days = array();
        foreach ($rows as $row) {
            $days[$row->special_date] = $row->label;
        }

        return $days;
    }

    function get_details($options = array()) {
        $builder = $this->db->table($this->table)->where("calendar_id", (int) get_array_value($options, "calendar_id"));
        $date = get_array_value($options, "special_date");
        if ($date) {
            $builder->where("special_date", $date);
        }

        return $builder->orderBy("special_date", "ASC")->get()->getResult();
    }

    /**
     * Save the label of a date. An empty label deletes the row. Unique per calendar and date.
     *
     * @return string the saved label ("" when deleted)
     */
    function save_label($calendar_id, $date, $label) {
        $calendar_id = (int) $calendar_id;
        $label = trim((string) $label);

        if ($label === "") {
            $this->db->table($this->table)->where("calendar_id", $calendar_id)->where("special_date", $date)->delete();
            return "";
        }

        $this->db->query("INSERT INTO " . $this->table . " (calendar_id, special_date, label) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE label=VALUES(label)", array($calendar_id, $date, $label));
        return $label;
    }

}
