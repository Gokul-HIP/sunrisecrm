<?php

namespace App\Models;

class Task_rules_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'task_rules';
        parent::__construct($this->table);
    }

    function get_details($options = array()) {
        $task_rules_table = $this->db->prefixTable('task_rules');

        $where = "";
        $id = get_array_value($options, "id");
        if ($id) {
            $id = (int)$id;
            $where .= " AND $task_rules_table.id=$id";
        }

        $department = get_array_value($options, "department");
        if ($department) {
            $department = $this->db->escape($department);
            $where .= " AND $task_rules_table.department=$department";
        }

        $sub_department = get_array_value($options, "sub_department");
        if ($sub_department !== null && $sub_department !== '') {
            $sub_department = $this->db->escape($sub_department);
            $where .= " AND $task_rules_table.sub_department=$sub_department";
        }

        $search = get_array_value($options, "search");
        if ($search) {
            $search = $this->db->escapeLikeString($search);
            $where .= " AND ($task_rules_table.task LIKE '%$search%' ESCAPE '!' OR $task_rules_table.keywords LIKE '%$search%' ESCAPE '!' OR $task_rules_table.group_name LIKE '%$search%' ESCAPE '!')";
        }

        $sql = "SELECT $task_rules_table.*
                FROM $task_rules_table
                WHERE $task_rules_table.deleted=0 $where
                ORDER BY $task_rules_table.department ASC, $task_rules_table.sub_department ASC, $task_rules_table.task ASC";

        return $this->db->query($sql);
    }
}
