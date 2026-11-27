<?php

namespace App\Models;

class Team_group_members_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'team_group_members';
        parent::__construct($this->table);
    }

    public function table_exists() {
        try {
            $table_name = $this->db->prefixTable('team_group_members');
            $query = $this->db->query("SHOW TABLES LIKE " . $this->db->escape($table_name));
            return $query && $query->getNumRows() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    function get_member_ids($group_id) {
        if (!$this->table_exists()) {
            return array();
        }

        $group_id = (int) $group_id;
        if (!$group_id) {
            return array();
        }

        try {
            $table = $this->db->prefixTable('team_group_members');
            $users_table = $this->db->prefixTable('users');
            $sql = "SELECT $table.user_id 
                    FROM $table 
                    JOIN $users_table ON $users_table.id = $table.user_id 
                    WHERE $table.group_id = $group_id 
                      AND $users_table.deleted = 0 
                      AND $users_table.status = 'active'";
            $result = $this->db->query($sql)->getResult();
            $ids = array();
            foreach ($result as $row) {
                $ids[] = (int) $row->user_id;
            }
            return $ids;
        } catch (\Throwable $e) {
            return array();
        }
    }

    function save_members($group_id, $user_ids = array()) {
        if (!$this->table_exists()) {
            return false;
        }

        $group_id = (int) $group_id;
        if (!$group_id) {
            return false;
        }

        $table = $this->db->prefixTable('team_group_members');

        try {
            // Delete existing members for this group
            $this->db->query("DELETE FROM $table WHERE group_id = $group_id");

            if (!empty($user_ids)) {
                $values = array();
                foreach ($user_ids as $uid) {
                    $uid = (int) $uid;
                    if ($uid > 0) {
                        $values[] = "($group_id, $uid)";
                    }
                }
                if (!empty($values)) {
                    $sql = "INSERT IGNORE INTO $table (group_id, user_id) VALUES " . implode(",", $values);
                    $this->db->query($sql);
                }
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    function remove_member($group_id, $user_id) {
        if (!$this->table_exists()) {
            return false;
        }

        $group_id = (int) $group_id;
        $user_id = (int) $user_id;
        if (!$group_id || !$user_id) {
            return false;
        }

        try {
            $table = $this->db->prefixTable('team_group_members');
            return $this->db->query("DELETE FROM $table WHERE group_id = $group_id AND user_id = $user_id");
        } catch (\Throwable $e) {
            return false;
        }
    }

    function get_members_details($group_id) {
        if (!$this->table_exists()) {
            return array();
        }

        $group_id = (int) $group_id;
        if (!$group_id) {
            return array();
        }

        try {
            $table = $this->db->prefixTable('team_group_members');
            $users_table = $this->db->prefixTable('users');
            $sql = "SELECT $users_table.id, $users_table.first_name, $users_table.last_name, $users_table.image, $users_table.job_title
                    FROM $table
                    JOIN $users_table ON $users_table.id = $table.user_id
                    WHERE $table.group_id = $group_id
                      AND $users_table.deleted = 0
                      AND $users_table.status = 'active'
                    ORDER BY $users_table.first_name ASC, $users_table.last_name ASC";
            return $this->db->query($sql)->getResult();
        } catch (\Throwable $e) {
            return array();
        }
    }
}
