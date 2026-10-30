<?php

namespace App\Models;

class Cron_diagnostic_logs_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'cron_diagnostic_logs';
        parent::__construct($this->table);
    }

    function get_logs($options = array()) {
        $table = $this->db->prefixTable('cron_diagnostic_logs');

        $where = "1=1";
        $level = $this->_get_clean_value($options, "level");
        if ($level && $level !== "all") {
            $level = $this->db->escape($level);
            $where .= " AND $table.level=$level";
        }

        $event_type = $this->_get_clean_value($options, "event_type");
        if ($event_type) {
            $event_type = $this->db->escape($event_type);
            $where .= " AND $table.event_type=$event_type";
        }

        $filter = $this->_get_clean_value($options, "filter");
        if ($filter === "success") {
            $where .= " AND $table.level='success'";
        } else if ($filter === "warning") {
            $where .= " AND $table.level='warning'";
        } else if ($filter === "error") {
            $where .= " AND $table.level='error'";
        } else if ($filter === "recurring") {
            $where .= " AND $table.event_type IN ('recurring_tasks','recurring_preview','recurring_eligibility')";
        } else if ($filter === "clone") {
            $where .= " AND $table.event_type IN ('clone_create','clone_verify','clone_skip')";
        } else if ($filter === "cron_gate") {
            $where .= " AND $table.event_type IN ('cron_gate','hourly_gate','cron_run')";
        } else if ($filter === "database") {
            $where .= " AND $table.event_type IN ('database','db_health')";
        }

        $limit = (int) $this->_get_clean_value($options, "limit");
        if ($limit < 1 || $limit > 500) {
            $limit = 200;
        }

        $sql = "SELECT * FROM $table WHERE $where ORDER BY $table.id DESC LIMIT $limit";
        return $this->db->query($sql);
    }

    function clear_diagnostic_logs() {
        $table = $this->db->prefixTable('cron_diagnostic_logs');
        return $this->db->query("DELETE FROM $table");
    }
}
