<?php

namespace App\Models;

class Cc_months_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'cc_months';
        parent::__construct($this->table);
        helper('content_calendar');
        cc_use_utf8mb4($this->db);
    }

    /**
     * Months of a calendar, oldest first: array of objects (year, month).
     */
    function get_for_calendar($calendar_id) {
        $rows = $this->db->table($this->table)
                        ->where("calendar_id", (int) $calendar_id)
                        ->orderBy("year", "ASC")
                        ->orderBy("month", "ASC")
                        ->get()->getResult();
        foreach ($rows as $key => $row) {
            $rows[$key]->year = (int) $row->year;
            $rows[$key]->month = (int) $row->month;
        }

        return $rows;
    }

    function get_details($options = array()) {
        return $this->get_for_calendar((int) get_array_value($options, "calendar_id"));
    }

    function exists($calendar_id, $year, $month) {
        return $this->db->table($this->table)
                        ->where("calendar_id", (int) $calendar_id)
                        ->where("year", (int) $year)
                        ->where("month", (int) $month)
                        ->countAllResults() > 0;
    }

    /**
     * Add a month if it's missing (duplicates are ignored quietly).
     *
     * @return bool true if a new row was added
     */
    function ensure($calendar_id, $year, $month) {
        $year = (int) $year;
        $month = (int) $month;
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            return false;
        }

        if ($this->exists($calendar_id, $year, $month)) {
            return false;
        }

        $this->db->query("INSERT IGNORE INTO " . $this->table . " (calendar_id, `year`, `month`) VALUES (?, ?, ?)", array((int) $calendar_id, $year, $month));
        return $this->db->affectedRows() > 0;
    }

    /**
     * Seed the previous month and the current month (new calendar).
     */
    function seed_default($calendar_id) {
        $year = function_exists('cc_today_year') ? cc_today_year() : (int) date("Y");
        $month = function_exists('cc_today_month') ? cc_today_month() : (int) date("n");
        $dt = new \DateTime(sprintf("%04d-%02d-01", $year, $month));
        $dt->modify("-1 month");
        $this->ensure($calendar_id, (int) $dt->format("Y"), (int) $dt->format("n"));
        $this->ensure($calendar_id, $year, $month);
    }

    /**
     * Make sure every month between two dates exists (used when a post is saved).
     */
    function ensure_for_date($calendar_id, $date) {
        $time = strtotime($date);
        if (!$time) {
            return false;
        }

        return $this->ensure($calendar_id, (int) date("Y", $time), (int) date("n", $time));
    }

    /**
     * Append the month after the last one (Nov -> Dec -> Jan ...).
     * With no months at all it starts at the current month.
     *
     * @return object (year, month, added)
     */
    function add_next($calendar_id) {
        $months = $this->get_for_calendar($calendar_id);

        if (count($months)) {
            $last = end($months);
            $next = strtotime(sprintf("%04d-%02d-01", $last->year, $last->month) . " +1 month");
            $year = (int) date("Y", $next);
            $month = (int) date("n", $next);
        } else {
            $year = function_exists('cc_today_year') ? cc_today_year() : (int) date("Y");
            $month = function_exists('cc_today_month') ? cc_today_month() : (int) date("n");
        }

        $added = $this->ensure($calendar_id, $year, $month);

        $result = new \stdClass();
        $result->year = $year;
        $result->month = $month;
        $result->added = $added;
        return $result;
    }

}
