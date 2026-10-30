<?php

namespace App\Models;

class Task_point_monthly_totals_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'task_point_monthly_totals';
        parent::__construct($this->table);
    }

    function get_for_user_month($user_id, $year, $month) {
        $row = $this->get_one_where(array(
            "user_id" => (int) $user_id,
            "year" => (int) $year,
            "month" => (int) $month
        ));

        return ($row && $row->id) ? $row : null;
    }

    function upsert_total($user_id, $year, $month, $total_points) {
        $data = array(
            "user_id" => (int) $user_id,
            "year" => (int) $year,
            "month" => (int) $month,
            "total_points" => (float) $total_points,
            "updated_at" => get_current_utc_time(),
            "deleted" => 0
        );

        $existing = $this->get_for_user_month($user_id, $year, $month);
        if ($existing && $existing->id) {
            return $this->ci_save($data, $existing->id);
        }

        return $this->ci_save($data);
    }
}
