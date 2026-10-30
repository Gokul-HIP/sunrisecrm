<?php

namespace App\Models;

class Task_point_awards_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'task_point_awards';
        parent::__construct($this->table);
    }

    function get_by_task_id($task_id) {
        $task_id = (int) $task_id;
        if (!$task_id) {
            return null;
        }

        $row = $this->get_one_where(array("task_id" => $task_id, "deleted" => 0));
        return ($row && $row->id) ? $row : null;
    }

    function get_any_by_task_id($task_id) {
        $task_id = (int) $task_id;
        if (!$task_id) {
            return null;
        }

        $row = $this->get_one_where(array("task_id" => $task_id));
        return ($row && $row->id) ? $row : null;
    }

    function upsert_award($data, $task_id) {
        $existing = $this->get_any_by_task_id($task_id);
        if ($existing && $existing->id) {
            return $this->ci_save($data, $existing->id);
        }

        return $this->ci_save($data);
    }

    function revoke_by_task_id($task_id) {
        $existing = $this->get_any_by_task_id($task_id);
        if (!$existing || !$existing->id || $existing->deleted) {
            return $existing;
        }

        $save_data = array("deleted" => 1);
        $this->ci_save($save_data, $existing->id);
        return $existing;
    }

    function get_monthly_sum($user_id, $year, $month) {
        $table = $this->db->prefixTable('task_point_awards');
        $user_id = (int) $user_id;
        $year = (int) $year;
        $month = (int) $month;

        $sql = "SELECT COALESCE(SUM($table.earned_points), 0) AS total_points
                FROM $table
                WHERE $table.deleted=0 AND $table.user_id=$user_id AND $table.year=$year AND $table.month=$month";

        $row = $this->db->query($sql)->getRow();
        return $row ? (float) $row->total_points : 0;
    }
}
