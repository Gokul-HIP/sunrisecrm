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
        //run_id (optional) groups the logs of one import run; empty means "no run"
        if (array_key_exists("run_id", $data)) {
            $run_id = trim((string) $data["run_id"]);
            $data["run_id"] = $run_id === "" ? null : substr($run_id, 0, 40);
        }

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

    /* ---------------------------------------------------------------- */
    /* run_id: one import run can write several logs (one per sheet)     */
    /* needs the run_id column from docs/content_calendar_import_v2_alter.sql */
    /* ---------------------------------------------------------------- */

    /**
     * All log rows of one run (oldest first).
     */
    function get_logs_by_run($run_id) {
        $run_id = trim((string) $run_id);
        if ($run_id === "") {
            return array();
        }

        return $this->db->table($this->table)->where("run_id", $run_id)->orderBy("id", "ASC")->get()->getResult();
    }

    /**
     * Batch ids of a run. By default only the logs that are not undone yet.
     */
    function get_batch_ids_by_run($run_id, $only_active = true) {
        $ids = array();
        foreach ($this->get_logs_by_run($run_id) as $log) {
            if ($only_active && (int) $log->undone === 1) {
                continue;
            }

            $ids[] = $log->batch_id;
        }

        return $ids;
    }

    /**
     * Mark every not-yet-undone log of the run as undone.
     *
     * @return int number of rows changed
     */
    function mark_run_undone($run_id) {
        $run_id = trim((string) $run_id);
        if ($run_id === "") {
            return 0;
        }

        $this->db->table($this->table)->where("run_id", $run_id)->where("undone", 0)->update(array("undone" => 1));
        return (int) $this->db->affectedRows();
    }

}
