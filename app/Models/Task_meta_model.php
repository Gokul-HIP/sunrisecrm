<?php

namespace App\Models;

class Task_meta_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'task_meta';
        parent::__construct($this->table);
    }

    function get_by_task_id($task_id) {
        $task_id = (int)$task_id;
        if (!$task_id) {
            return null;
        }

        $table = $this->db->prefixTable('task_meta');
        $row = $this->db->query("SELECT * FROM $table WHERE task_id = $task_id LIMIT 1")->getRow();
        return $row;
    }

    function save_meta($task_id, $data = array()) {
        $task_id = (int)$task_id;
        if (!$task_id) {
            return false;
        }

        $department = trim((string)get_array_value($data, 'department'));
        if (!$department) {
            $existing = $this->get_by_task_id($task_id);
            if ($existing) {
                $table = $this->db->prefixTable('task_meta');
                $this->db->query("DELETE FROM $table WHERE task_id = $task_id");
            }
            return false;
        }

        $sub_department = trim((string)get_array_value($data, 'sub_department'));
        if ($department !== 'Digital Marketing') {
            $sub_department = null;
        }

        $rule_id = get_array_value($data, 'rule_id');
        $rule_id = $rule_id ? (int)$rule_id : null;

        $suggested_points = get_array_value($data, 'suggested_points');
        $suggested_points = ($suggested_points !== null && $suggested_points !== '') ? (int)$suggested_points : null;

        $existing = $this->get_by_task_id($task_id);
        $save_data = array(
            'department' => $department,
            'sub_department' => $sub_department ? $sub_department : null,
            'rule_id' => $rule_id,
            'suggested_points' => $suggested_points
        );

        if ($existing) {
            return $this->ci_save($save_data, $existing->id);
        } else {
            $save_data['task_id'] = $task_id;
            $save_data['created_at'] = get_current_utc_time();
            return $this->ci_save($save_data);
        }
    }
}
