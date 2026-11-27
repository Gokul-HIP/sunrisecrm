<?php

namespace App\Models;

class Team_groups_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'team_groups';
        parent::__construct($this->table);
    }

    /**
     * Check safely if the table exists (without INFORMATION_SCHEMA)
     */
    public function table_exists() {
        try {
            $table_name = $this->db->prefixTable('team_groups');
            $query = $this->db->query("SHOW TABLES LIKE " . $this->db->escape($table_name));
            return $query && $query->getNumRows() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    function get_details($options = array()) {
        if (!$this->table_exists()) {
            return new \CodeIgniter\Database\MySQLi\Result($this->db->connID);
        }

        $team_groups_table = $this->db->prefixTable('team_groups');
        $members_table = $this->db->prefixTable('team_group_members');
        $users_table = $this->db->prefixTable('users');
        $where = "";

        $id = $this->_get_clean_value($options, "id");
        if ($id) {
            $where .= " AND $team_groups_table.id=$id";
        }

        try {
            $sql = "SELECT $team_groups_table.*,
                    (SELECT COUNT(DISTINCT $members_table.user_id)
                     FROM $members_table
                     JOIN $users_table ON $users_table.id = $members_table.user_id
                     WHERE $members_table.group_id = $team_groups_table.id
                       AND $users_table.deleted = 0
                       AND $users_table.status = 'active'
                    ) AS members_count
                    FROM $team_groups_table
                    WHERE $team_groups_table.deleted=0 $where
                    ORDER BY $team_groups_table.title ASC";
            return $this->db->query($sql);
        } catch (\Throwable $e) {
            return new \CodeIgniter\Database\MySQLi\Result($this->db->connID);
        }
    }

    function get_all_active_groups() {
        if (!$this->table_exists()) {
            return array();
        }

        try {
            $team_groups_table = $this->db->prefixTable('team_groups');
            $sql = "SELECT id, title FROM $team_groups_table WHERE deleted=0 ORDER BY title ASC";
            return $this->db->query($sql)->getResult();
        } catch (\Throwable $e) {
            return array();
        }
    }
}
