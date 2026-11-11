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

        $table = $this->db->prefixTable('task_point_awards');
        $sql = "SELECT * FROM $table WHERE task_id=$task_id AND deleted=0 ORDER BY is_collaborator ASC, id ASC LIMIT 1";
        $row = $this->db->query($sql)->getRow();
        return ($row && $row->id) ? $row : null;
    }

    function get_any_by_task_id($task_id) {
        $task_id = (int) $task_id;
        if (!$task_id) {
            return null;
        }

        $table = $this->db->prefixTable('task_point_awards');
        $sql = "SELECT * FROM $table WHERE task_id=$task_id ORDER BY is_collaborator ASC, id ASC LIMIT 1";
        $row = $this->db->query($sql)->getRow();
        return ($row && $row->id) ? $row : null;
    }

    function get_by_task_and_user($task_id, $user_id, $include_deleted = false) {
        $task_id = (int) $task_id;
        $user_id = (int) $user_id;
        if (!$task_id || !$user_id) {
            return null;
        }

        $where = array("task_id" => $task_id, "user_id" => $user_id);
        if (!$include_deleted) {
            $where["deleted"] = 0;
        }

        $row = $this->get_one_where($where);
        return ($row && $row->id) ? $row : null;
    }

    function get_all_by_task_id($task_id, $include_deleted = false) {
        $task_id = (int) $task_id;
        if (!$task_id) {
            return array();
        }

        $table = $this->db->prefixTable('task_point_awards');
        $where_deleted = $include_deleted ? "" : " AND deleted=0";
        $sql = "SELECT * FROM $table WHERE task_id=$task_id $where_deleted ORDER BY is_collaborator ASC, id ASC";
        return $this->db->query($sql)->getResult();
    }

    function upsert_award_for_user($data, $task_id, $user_id) {
        $task_id = (int) $task_id;
        $user_id = (int) $user_id;
        if (!$task_id || !$user_id) {
            return false;
        }

        $existing = $this->get_by_task_and_user($task_id, $user_id, true);
        if ($existing && $existing->id) {
            return $this->ci_save($data, $existing->id);
        }

        return $this->ci_save($data);
    }

    function upsert_award($data, $task_id) {
        $user_id = isset($data["user_id"]) ? (int) $data["user_id"] : 0;
        if ($user_id) {
            return $this->upsert_award_for_user($data, $task_id, $user_id);
        }

        $existing = $this->get_any_by_task_id($task_id);
        if ($existing && $existing->id) {
            return $this->ci_save($data, $existing->id);
        }

        return $this->ci_save($data);
    }

    function revoke_for_user($task_id, $user_id) {
        $existing = $this->get_by_task_and_user($task_id, $user_id, false);
        if (!$existing || !$existing->id) {
            return null;
        }

        $save_data = array("deleted" => 1);
        $this->ci_save($save_data, $existing->id);
        return $existing;
    }

    function revoke_all_by_task_id($task_id) {
        $task_id = (int) $task_id;
        if (!$task_id) {
            return array();
        }

        $active_awards = $this->get_all_by_task_id($task_id, false);
        if (!$active_awards) {
            return array();
        }

        $table = $this->db->prefixTable('task_point_awards');
        $this->db->query("UPDATE $table SET deleted=1 WHERE task_id=$task_id AND deleted=0");
        return $active_awards;
    }

    function revoke_by_task_id($task_id) {
        $revoked_all = $this->revoke_all_by_task_id($task_id);
        return !empty($revoked_all) ? $revoked_all[0] : null;
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
