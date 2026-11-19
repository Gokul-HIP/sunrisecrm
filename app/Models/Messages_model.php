<?php

namespace App\Models;

class Messages_model extends Crud_model {

    protected $table = null;

    function __construct() {
        $this->table = 'messages';
        parent::__construct($this->table);
    }

    /*
     * prepare details info of a message
     */

    function get_details($options = array()) {
        $messages_table = $this->db->prefixTable('messages');
        $users_table = $this->db->prefixTable('users');

        $mode = $this->_get_clean_value($options, "mode");

        $where = "";
        $id = $this->_get_clean_value($options, "id");
        if ($id) {
            $where .= " AND $messages_table.id=$id";
        }

        $message_id = $this->_get_clean_value($options, "message_id");
        if ($message_id) {
            $where .= " AND $messages_table.message_id=$message_id";
        }

        $user_id = $this->_get_clean_value($options, "user_id");
        if ($user_id) {
            $where .= " AND ($messages_table.from_user_id=$user_id OR $messages_table.to_user_id=$user_id) ";
        }


        $join_with = "$messages_table.from_user_id";
        $join_another = "$messages_table.to_user_id";
        if ($user_id && $mode === "inbox") {
            $where .= " AND $messages_table.message_id=0 ";
        } else if ($user_id && $mode === "sent_items") {
            $where .= " AND $messages_table.message_id=0 ";
            $join_with = "$messages_table.to_user_id";
            $join_another = "$messages_table.from_user_id";
        }

        $last_message_id = $this->_get_clean_value($options, "last_message_id");
        if ($last_message_id) {
            $where .= " AND $messages_table.id>$last_message_id";
        }


        $top_message_id = $this->_get_clean_value($options, "top_message_id");
        if ($top_message_id) {
            $where .= " AND $messages_table.id<$top_message_id";
        }



        $limit = $this->_get_clean_value($options, "limit");
        $limit = $limit ? $limit : "30";
        $offset = $this->_get_clean_value($options, "offset");
        $offset = $offset ? $offset : "0";

        $sql = "SELECT * FROM (SELECT 0 AS reply_message_id, $messages_table.*, CONCAT($users_table.first_name, ' ', $users_table.last_name) AS user_name, $users_table.image AS user_image, $users_table.user_type, CONCAT(another_user.first_name, ' ', another_user.last_name) AS another_user_name, another_user.id AS another_user_id, another_user.last_online AS another_user_last_online
        FROM $messages_table
        LEFT JOIN $users_table ON $users_table.id=$join_with
        LEFT JOIN $users_table AS another_user ON another_user.id=$join_another
        WHERE $messages_table.deleted=0 $where
        ORDER BY $messages_table.id DESC  LIMIT $offset, $limit) new_message ORDER BY id ASC";

        $query = $this->db->query($sql);

        $data = new \stdClass();
        $data->result = $query->getResult();
        $data->row = $query->getRow();
        $data->found_rows = 0;

        if ($message_id) {
            $data->found_rows = $this->db->query("SELECT COUNT(id) AS found_rows FROM $messages_table WHERE $messages_table.message_id = $message_id")->getRow()->found_rows;
        }

        return $data;
    }

    /*
     * prepare inbox/sent items list (preview = latest message in thread)
     */

    function get_list($options = array()) {
        $messages_table = $this->db->prefixTable('messages');
        $users_table = $this->db->prefixTable('users');

        $mode = $this->_get_clean_value($options, "mode");
        $user_id = (int) $this->_get_clean_value($options, "user_id");

        if ($user_id && $mode === "inbox") {
            $where_user = "to_user_id";
            $other_party_expr = "IF(latest.from_user_id=$user_id, latest.to_user_id, latest.from_user_id)";
        } else if ($user_id && $mode === "sent_items") {
            $where_user = "from_user_id";
            $other_party_expr = "IF(latest.from_user_id=$user_id, latest.to_user_id, latest.from_user_id)";
        } else {
            $where_user = "to_user_id";
            $other_party_expr = "latest.from_user_id";
        }

        $where = "";
        $user_ids = $this->_get_clean_value($options, "user_ids");
        if ($user_ids && $mode !== "inbox") {
            // Sent items: keep restricting the other party (recipient) to allowed users.
            $where .= " AND seed.to_user_id IN($user_ids)";
        }

        $limit_sql = "";
        $is_notification = $this->_get_clean_value($options, "is_notification");
        if ($is_notification) {
            $limit_sql = " LIMIT 10 ";
        }

        $this->db->query("SET sql_mode = ''");

        // Find threads from messages in this mode, then join the true latest message in each thread.
        $sql = "SELECT
                    threads.main_message_id,
                    threads.main_message_id AS id,
                    root.subject AS reply_subject,
                    root.subject AS subject,
                    latest.id AS last_message_row_id,
                    latest.message AS last_message,
                    latest.files AS files,
                    latest.from_user_id AS last_from_user_id,
                    latest.to_user_id AS last_to_user_id,
                    latest.created_at AS created_at,
                    latest.status AS status,
                    $other_party_expr AS other_user_id,
                    CONCAT($users_table.first_name, ' ', $users_table.last_name) AS user_name,
                    $users_table.image AS user_image,
                    $users_table.last_online
                FROM (
                    SELECT IF(seed.message_id=0, seed.id, seed.message_id) AS main_message_id
                    FROM $messages_table seed
                    WHERE seed.deleted=0
                      AND seed.$where_user=$user_id
                      AND FIND_IN_SET($user_id, seed.deleted_by_users)=0
                      $where
                    GROUP BY main_message_id
                ) threads
                INNER JOIN $messages_table root ON root.id=threads.main_message_id AND root.deleted=0
                INNER JOIN (
                    SELECT IF(m.message_id=0, m.id, m.message_id) AS main_message_id, MAX(m.id) AS last_id
                    FROM $messages_table m
                    WHERE m.deleted=0
                    GROUP BY main_message_id
                ) latest_map ON latest_map.main_message_id=threads.main_message_id
                INNER JOIN $messages_table latest ON latest.id=latest_map.last_id
                LEFT JOIN $users_table ON $users_table.id=$other_party_expr
                WHERE FIND_IN_SET($user_id, root.deleted_by_users)=0
                ORDER BY latest.created_at DESC
                $limit_sql";

        return $this->db->query($sql);
    }

    function get_chat_list($options = array()) {

        $messages_table = $this->db->prefixTable('messages');
        $users_table = $this->db->prefixTable('users');

        $login_user_id = (int) $this->_get_clean_value($options, "login_user_id");

        $where = "";
        $user_id = $this->_get_clean_value($options, "user_id");
        if ($user_id) {
            $where .= " AND (root.to_user_id=$user_id OR root.from_user_id=$user_id) ";
        }

        $user_ids = $this->_get_clean_value($options, "user_ids");
        if ($user_ids) {
            // Always include threads sent TO me even if the sender is outside message_permission.
            $where .= " AND (root.to_user_id IN($user_ids) OR root.from_user_id IN($user_ids) OR root.to_user_id=$login_user_id)";
        }

        $this->db->query("SET sql_mode = ''");

        $sql = "SELECT
                    root.id,
                    root.subject,
                    root.from_user_id,
                    latest.created_at AS message_time,
                    latest.message AS last_message,
                    latest.files AS last_message_files,
                    latest.from_user_id AS last_from_user_id,
                    latest.to_user_id AS last_to_user_id,
                    IF(EXISTS(
                        SELECT 1 FROM $messages_table u
                        WHERE u.deleted=0 AND u.status='unread' AND u.to_user_id=$login_user_id
                          AND (u.id=root.id OR u.message_id=root.id)
                    ), 'unread', 'read') AS status,
                    CONCAT($users_table.first_name, ' ', $users_table.last_name) AS user_name,
                    $users_table.image AS user_image,
                    $users_table.last_online
                FROM $messages_table root
                INNER JOIN (
                    SELECT IF(m.message_id=0, m.id, m.message_id) AS main_message_id, MAX(m.id) AS last_id
                    FROM $messages_table m
                    WHERE m.deleted=0
                    GROUP BY main_message_id
                ) latest_map ON latest_map.main_message_id=root.id
                INNER JOIN $messages_table latest ON latest.id=latest_map.last_id
                LEFT JOIN $users_table ON ($users_table.id=root.from_user_id OR $users_table.id=root.to_user_id)
                    AND $users_table.id != $login_user_id
                WHERE root.deleted=0 AND root.message_id=0 $where
                  AND FIND_IN_SET($login_user_id, root.deleted_by_users)=0
                  AND (root.from_user_id=$login_user_id OR root.to_user_id=$login_user_id)
                GROUP BY root.id
                ORDER BY message_time DESC
                LIMIT 0, 30";

        return $this->db->query($sql);
    }

    /**
     * Light query for desktop toasts: newest unread messages to this user.
     */
    function get_unread_desktop_payloads($user_id, $last_checked_at = "0", $active_message_id = 0, $limit = 3) {
        $messages_table = $this->db->prefixTable('messages');
        $users_table = $this->db->prefixTable('users');
        $user_id = (int) $user_id;
        $active_message_id = (int) $active_message_id;
        $limit = max(1, min(5, (int) $limit));
        if (!$last_checked_at || $last_checked_at === "0") {
            $last_checked_at = "1970-01-01 00:00:00";
        }
        // Allow only a safe datetime-like string
        if (!preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', (string) $last_checked_at)) {
            $last_checked_at = "1970-01-01 00:00:00";
        }

        $where = "";
        if ($active_message_id) {
            $where .= " AND $messages_table.id!=$active_message_id AND $messages_table.message_id!=$active_message_id";
        }

        $sql = "SELECT $messages_table.id, $messages_table.message, $messages_table.files, $messages_table.created_at,
                       CONCAT($users_table.first_name, ' ', $users_table.last_name) AS user_name,
                       $users_table.image AS user_image
                FROM $messages_table
                INNER JOIN $users_table ON $users_table.id=$messages_table.from_user_id
                WHERE $messages_table.deleted=0
                  AND $messages_table.status='unread'
                  AND $messages_table.to_user_id=$user_id
                  AND timestamp($messages_table.created_at)>timestamp(" . $this->db->escape($last_checked_at) . ")
                  $where
                ORDER BY $messages_table.id DESC
                LIMIT $limit";

        return $this->db->query($sql)->getResult();
    }

    function count_notifications($user_id, $last_message_checke_at = "0", $active_message_id = 0, $user_ids = "") {
        $messages_table = $this->db->prefixTable('messages');

        $where = "";
        if ($active_message_id) {
            $where = " AND $messages_table.message_id!=$active_message_id";
        }

        if ($user_ids) {
            // Unread TO me always counts; do not require the sender to be in message_permission.
            $where .= " AND ($messages_table.from_user_id IN($user_ids) OR $messages_table.to_user_id=$user_id) ";
        }

        $sql = "SELECT COUNT($messages_table.id) AS total_notifications
        FROM $messages_table
        WHERE $messages_table.deleted=0 AND $messages_table.status='unread'  AND $messages_table.to_user_id = $user_id
        AND timestamp($messages_table.created_at)>timestamp('$last_message_checke_at') $where
        ORDER BY timestamp($messages_table.created_at) DESC";

        $result = $this->db->query($sql);
        if ($result->resultID->num_rows) {
            return $result->getRow()->total_notifications;
        }
    }

    /* update message ustats */

    function set_message_status_as_read($message_id, $user_id = 0) {
        $messages_table = $this->db->prefixTable('messages');
        $sql = "UPDATE $messages_table SET status='read' WHERE $messages_table.to_user_id=$user_id AND ($messages_table.message_id=$message_id OR $messages_table.id=$message_id)";
        return $this->db->query($sql);
    }

    function count_unread_message($user_id = 0, $user_ids = "") {
        $messages_table = $this->db->prefixTable('messages');

        $where = "";
        if ($user_ids) {
            // Envelope badge: count all unread addressed to me (Approve messages included).
            $where .= " AND ($messages_table.from_user_id IN($user_ids) OR $messages_table.to_user_id=$user_id) ";
        }

        $sql = "SELECT COUNT($messages_table.id) as total
        FROM $messages_table
        WHERE $messages_table.deleted=0 AND $messages_table.status='unread'  AND $messages_table.to_user_id = $user_id $where";
        return $this->db->query($sql)->getRow()->total;
    }

    /**
     * Find an open root thread between two users (either direction).
     * Excludes roots soft-deleted by either party.
     *
     * @return object|null root message row
     */
    function get_open_thread_between_users($user_a, $user_b) {
        $messages_table = $this->db->prefixTable('messages');
        $user_a = (int) $user_a;
        $user_b = (int) $user_b;
        if ($user_a <= 0 || $user_b <= 0) {
            return null;
        }

        $sql = "SELECT $messages_table.*
            FROM $messages_table
            WHERE $messages_table.deleted=0
              AND $messages_table.message_id=0
              AND (
                    ($messages_table.from_user_id=$user_a AND $messages_table.to_user_id=$user_b)
                 OR ($messages_table.from_user_id=$user_b AND $messages_table.to_user_id=$user_a)
              )
              AND FIND_IN_SET($user_a, $messages_table.deleted_by_users)=0
              AND FIND_IN_SET($user_b, $messages_table.deleted_by_users)=0
            ORDER BY $messages_table.id DESC
            LIMIT 1";

        return $this->db->query($sql)->getRow();
    }

    function delete_messages_for_user($message_id = 0, $user_id = 0) {
        $messages_table = $this->db->prefixTable('messages');

        $sql = "UPDATE $messages_table SET $messages_table.deleted_by_users = CONCAT($messages_table.deleted_by_users,',',$user_id)
        WHERE $messages_table.id=$message_id OR $messages_table.message_id=$message_id";
        return $this->db->query($sql);
    }

    function clear_deleted_status($message_id = 0) {
        $messages_table = $this->db->prefixTable('messages');

        $sql = "UPDATE $messages_table SET $messages_table.deleted_by_users = ''
        WHERE $messages_table.id=$message_id OR $messages_table.message_id=$message_id";
        return $this->db->query($sql);
    }

    function get_users_for_messaging($options = array()) {
        $users_table = $this->db->prefixTable('users');
        $clients_table = $this->db->prefixTable('clients');

        $where = "";

        $login_user_id = $this->_get_clean_value($options, "login_user_id");

        $all_members = $this->_get_clean_value($options, "all_members");
        $specific_members = $this->_get_clean_value($options, "specific_members");
        $member_to_clients = $this->_get_clean_value($options, "member_to_clients");
        if ($all_members) {
            if ($member_to_clients) {
                $where .= " AND ($users_table.user_type='staff' OR $users_table.user_type='client')";
            } else {
                $where .= " AND $users_table.user_type='staff'";
            }
        } else if ($specific_members) {
            if (is_array($specific_members) && count($specific_members)) {
                $specific_members = join(",", $specific_members);
            } else {
                $specific_members = '0';
            }

            if ($member_to_clients) {
                $where .= " AND ($users_table.id IN($specific_members) OR $users_table.user_type='client')";
            } else {
                $where .= " AND $users_table.id IN($specific_members)";
            }
        }

        if ($member_to_clients && !$all_members && !$specific_members) {
            $where .= " AND $users_table.user_type='client'";
        }

        $client_to_members = $this->_get_clean_value($options, "client_to_members");
        $client_id = $this->_get_clean_value($options, "client_id");
        if ($client_to_members) {
            if ($client_id) {
                $where .= " AND (FIND_IN_SET($users_table.id, '$client_to_members') OR $users_table.client_id=$client_id)";
            } else {
                $where .= " AND FIND_IN_SET($users_table.id, '$client_to_members')";
            }
        }

        if ($client_id && !$client_to_members) {
            $where .= " AND $users_table.client_id=$client_id";
        }

        $sql = "SELECT $users_table.id, $users_table.client_id, $users_table.user_type, $users_table.first_name, $users_table.last_name, $clients_table.company_name,
            $users_table.image,  $users_table.job_title, $users_table.last_online
        FROM $users_table
        LEFT JOIN $clients_table ON $clients_table.id = $users_table.client_id AND $clients_table.deleted=0
        WHERE $users_table.deleted=0 AND $users_table.status='active' AND $users_table.id!=$login_user_id $where
        ORDER BY $users_table.user_type, $users_table.first_name ASC";

        return $this->db->query($sql);
    }

}
