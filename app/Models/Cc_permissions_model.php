<?php

namespace App\Models;

class Cc_permissions_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'cc_permissions';
        parent::__construct($this->table);
        helper('content_calendar');
        cc_use_utf8mb4($this->db);
    }

    function get_valid_keys() {
        return array("view", "add", "edit", "delete", "manage_options", "add_client", "add_column");
    }

    /**
     * Options: permission_key, user_id.
     */
    function get_details($options = array()) {
        $builder = $this->db->table($this->table);

        $permission_key = get_array_value($options, "permission_key");
        if ($permission_key) {
            $builder->where("permission_key", $permission_key);
        }

        $user_id = (int) get_array_value($options, "user_id");
        if ($user_id) {
            $builder->where("user_id", $user_id);
        }

        return $builder->orderBy("id", "ASC")->get();
    }

    /**
     * User ids that have a permission key.
     */
    function get_user_ids($permission_key) {
        $rows = $this->db->table($this->table)->select("user_id")->where("permission_key", $permission_key)->get()->getResult();
        $ids = array();
        foreach ($rows as $row) {
            $ids[] = (int) $row->user_id;
        }

        return $ids;
    }

    /**
     * Keys a user has been given (the implied view is NOT included, see cc_can()).
     */
    function get_keys_for_user($user_id) {
        $rows = $this->db->table($this->table)->select("permission_key")->where("user_id", (int) $user_id)->get()->getResult();
        $keys = array();
        foreach ($rows as $row) {
            $keys[] = $row->permission_key;
        }

        return $keys;
    }

    function user_has($permission_key, $user_id) {
        return $this->db->table($this->table)
                        ->where("permission_key", $permission_key)
                        ->where("user_id", (int) $user_id)
                        ->countAllResults() > 0;
    }

    /**
     * Replace the whole set of users of one permission key, in a transaction.
     *
     * @param string $permission_key
     * @param array $user_ids
     * @param int $created_by
     * @return bool
     */
    function replace_users_for_key($permission_key, $user_ids, $created_by) {
        if (!in_array($permission_key, $this->get_valid_keys(), true)) {
            return false;
        }

        $clean_ids = array();
        if (is_array($user_ids)) {
            foreach ($user_ids as $user_id) {
                $user_id = (int) $user_id;
                if ($user_id > 0 && !in_array($user_id, $clean_ids, true)) {
                    $clean_ids[] = $user_id;
                }
            }
        }

        $this->db->transStart();

        $this->db->table($this->table)->where("permission_key", $permission_key)->delete();

        $now = cc_now();
        foreach ($clean_ids as $user_id) {
            $this->db->table($this->table)->insert(array(
                "permission_key" => $permission_key,
                "user_id" => $user_id,
                "created_by" => (int) $created_by,
                "created_at" => $now
            ));
        }

        $this->db->transComplete();

        return $this->db->transStatus() !== false;
    }

}
