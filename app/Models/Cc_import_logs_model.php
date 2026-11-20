<?php

namespace App\Models;

/**
 * One row per import into one client calendar (Content Calendar import, phase 2).
 */
class Cc_import_logs_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'cc_import_logs';
        parent::__construct($this->table);
        helper('content_calendar');
        cc_use_utf8mb4($this->db);
    }

    /**
     * Options: id, calendar_id, user_id, batch_id.
     */
    function get_details($options = array()) {
        $builder = $this->db->table($this->table);

        $id = (int) get_array_value($options, "id");
        if ($id) {
            $builder->where("id", $id);
        }

        $calendar_id = (int) get_array_value($options, "calendar_id");
        if ($calendar_id) {
            $builder->where("calendar_id", $calendar_id);
        }

        $user_id = (int) get_array_value($options, "user_id");
        if ($user_id) {
            $builder->where("user_id", $user_id);
        }

        $batch_id = get_array_value($options, "batch_id");
        if ($batch_id) {
            $builder->where("batch_id", $batch_id);
        }

        return $builder->orderBy("id", "DESC")->get();
    }

    /**
     * One log row or null.
     */
    function get_log($id) {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $row = $this->db->table($this->table)->where("id", $id)->get(1)->getRow();
        return $row ? $row : null;
    }

    /**
     * Insert a log row. $data must already be validated.
     *
     * @return int log id
     */
    function create_log($data) {
        $this->db->table($this->table)->insert($data);
        return (int) $this->db->insertID();
    }

    /**
     * Mark an import as undone, only if it is not undone yet.
     *
     * @return bool true if this call changed the row
     */
    function mark_undone($id) {
        $this->db->table($this->table)->where("id", (int) $id)->where("undone", 0)->update(array("undone" => 1));
        return $this->db->affectedRows() > 0;
    }

}
