<?php

namespace App\Models;

class Cc_calendars_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'cc_calendars';
        parent::__construct($this->table);
        helper('content_calendar');
        cc_use_utf8mb4($this->db);
    }

    /**
     * Options: id (one calendar), name (exact, case-insensitive).
     * Returns a result object with getResult() / getRow() like the other RISE models.
     */
    function get_details($options = array()) {
        $builder = $this->db->table($this->table)->where("deleted", 0);

        $id = (int) get_array_value($options, "id");
        if ($id) {
            $builder->where("id", $id);
        }

        $name = get_array_value($options, "name");
        if ($name) {
            $builder->where("LOWER(name) =", mb_strtolower(trim($name), 'UTF-8'));
        }

        return $builder->orderBy("sort_order", "ASC")->orderBy("id", "ASC")->get();
    }

    /**
     * All non-deleted calendars in tab order.
     *
     * @return array of objects
     */
    function get_all_active() {
        return $this->db->table($this->table)
                        ->where("deleted", 0)
                        ->orderBy("sort_order", "ASC")
                        ->orderBy("id", "ASC")
                        ->get()->getResult();
    }

    /**
     * One non-deleted calendar or null.
     */
    function get_active($id) {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $row = $this->db->table($this->table)->where("id", $id)->where("deleted", 0)->get(1)->getRow();
        return $row ? $row : null;
    }

    /**
     * Is there another non-deleted calendar with this name (case-insensitive)?
     */
    function name_exists($name, $exclude_id = 0) {
        $builder = $this->db->table($this->table)
                ->where("deleted", 0)
                ->where("LOWER(name) =", mb_strtolower(trim($name), 'UTF-8'));
        if ((int) $exclude_id > 0) {
            $builder->where("id !=", (int) $exclude_id);
        }

        return $builder->countAllResults() > 0;
    }

    function get_next_sort_order() {
        $row = $this->db->table($this->table)->selectMax("sort_order", "max_sort")->where("deleted", 0)->get()->getRow();
        return $row && $row->max_sort !== null ? ((int) $row->max_sort + 1) : 1;
    }

    /**
     * Find a CRM client whose company name equals the calendar name (optional link).
     *
     * @return int client id or 0
     */
    function find_client_id_by_name($name) {
        $clients_table = $this->db->prefixTable("clients");
        $row = $this->db->query("SELECT id FROM $clients_table WHERE deleted=0 AND LOWER(company_name)=? ORDER BY id ASC LIMIT 1", array(mb_strtolower(trim($name), 'UTF-8')))->getRow();
        return $row ? (int) $row->id : 0;
    }

    /**
     * Soft delete a calendar. Its columns / items stay in the database.
     */
    function soft_delete($id) {
        return $this->db->table($this->table)->where("id", (int) $id)->update(array("deleted" => 1));
    }

    /**
     * Create a calendar row.
     *
     * @return int new id
     */
    function create_calendar($name, $client_id, $created_by) {
        $data = array(
            "name" => $name,
            "client_id" => $client_id ? (int) $client_id : null,
            "sort_order" => $this->get_next_sort_order(),
            "deleted" => 0,
            "created_by" => (int) $created_by,
            "created_at" => cc_now()
        );

        $this->db->table($this->table)->insert($data);
        return (int) $this->db->insertID();
    }

}
