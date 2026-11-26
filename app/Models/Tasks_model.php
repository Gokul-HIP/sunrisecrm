<?php

namespace App\Models;

class Tasks_model extends Crud_model {

    protected $table = null;
    /** @var bool|null Cached existence check of created_at column on tasks table */
    private static $has_created_at_column = null;

    function __construct() {
        $this->table = 'tasks';
        parent::__construct($this->table);
        parent::init_activity_log("task", "title", "project", "project_id");
    }

    /**
     * Check if rise_tasks table has the created_at column (cached per request).
     * Uses CodeIgniter 4 fieldExists (SHOW COLUMNS, no INFORMATION_SCHEMA).
     */
    public function has_created_at_column() {
        if (self::$has_created_at_column === null) {
            self::$has_created_at_column = $this->db->fieldExists('created_at', $this->table);
        }
        return self::$has_created_at_column;
    }

    function ci_save(&$data = array(), $id = 0) {
        $id = (int) $id;
        $is_update = ($id > 0);

        if ($is_update) {
            // Editing an existing task must never change created_at.
            if (array_key_exists("created_at", $data)) {
                unset($data["created_at"]);
            }
        } else {
            // New task creation: write created_at in current UTC time if column exists
            if ($this->has_created_at_column()) {
                if (empty($data["created_at"])) {
                    $data["created_at"] = get_current_utc_time();
                }
            } else {
                if (array_key_exists("created_at", $data)) {
                    unset($data["created_at"]);
                }
            }
        }

        // Check if any fields relevant to points/awards are being modified
        $point_relevant_fields = array("status_id", "deadline", "end_time", "points", "assigned_to", "collaborators", "deleted");
        $has_relevant_changes = false;
        if ($is_update) {
            foreach ($point_relevant_fields as $f) {
                if (array_key_exists($f, $data)) {
                    $has_relevant_changes = true;
                    break;
                }
            }
        }

        $existing = null;
        if ($is_update && $has_relevant_changes) {
            $existing = $this->get_one($id);
        }

        $save_id = parent::ci_save($data, $id);
        if (!$save_id) {
            return $save_id;
        }

        if (!$is_update) {
            $task_after = $this->get_one($save_id);
            if (is_task_status_done($task_after) && function_exists("recalculate_task_point_awards")) {
                recalculate_task_point_awards($task_after, true);
            }
            return $save_id;
        }

        if (isset($data["deleted"]) && $data["deleted"] == 1) {
            $awards_model = model("App\Models\Task_point_awards_model");
            $revoked_list = $awards_model->revoke_all_by_task_id($save_id);
            foreach ($revoked_list as $r) {
                if ($r->user_id) {
                    refresh_user_monthly_task_points($r->user_id, $r->year, $r->month);
                }
            }
            return $save_id;
        }

        if ($has_relevant_changes && function_exists("recalculate_task_point_awards")) {
            $task_after = $this->get_one($save_id);
            $was_done = $existing ? is_task_status_done($existing) : false;
            $is_done = is_task_status_done($task_after);

            if ($was_done && !$is_done) {
                $awards_model = model("App\Models\Task_point_awards_model");
                $revoked_list = $awards_model->revoke_all_by_task_id($save_id);
                foreach ($revoked_list as $r) {
                    if ($r->user_id) {
                        refresh_user_monthly_task_points($r->user_id, $r->year, $r->month);
                    }
                }
            } else if (!$was_done && $is_done) {
                recalculate_task_point_awards($task_after, true);
            } else if ($was_done && $is_done) {
                $deadline_changed = array_key_exists("deadline", $data) && ($existing->deadline != $data["deadline"]);
                $end_time_changed = array_key_exists("end_time", $data) && ($existing->end_time != $data["end_time"]);
                $points_changed = array_key_exists("points", $data) && ((float) $existing->points != (float) $data["points"]);
                $assignee_changed = array_key_exists("assigned_to", $data) && ((int) $existing->assigned_to != (int) $data["assigned_to"]);
                $collab_changed = array_key_exists("collaborators", $data) && ((string) $existing->collaborators !== (string) $data["collaborators"]);

                if ($deadline_changed || $end_time_changed || $points_changed || $assignee_changed || $collab_changed) {
                    recalculate_task_point_awards($task_after, true);
                }
            }
        }

        return $save_id;
    }

    function schema() {
        return array(
            "id" => array(
                "label" => app_lang("id"),
                "type" => "int"
            ),
            "title" => array(
                "label" => app_lang("title"),
                "type" => "text"
            ),
            "description" => array(
                "label" => app_lang("description"),
                "type" => "text"
            ),
            "assigned_to" => array(
                "label" => app_lang("assigned_to"),
                "type" => "foreign_key",
                "linked_model" => model("App\Models\Users_model"),
                "label_fields" => array("first_name", "last_name"),
            ),
            "collaborators" => array(
                "label" => app_lang("collaborators"),
                "type" => "foreign_key",
                "link_type" => "user_group_list",
                "linked_model" => model("App\Models\Users_model"),
                "label_fields" => array("user_group_name"),
            ),
            "milestone_id" => array(
                "label" => app_lang("milestone"),
                "type" => "foreign_key",
                "linked_model" => model("App\Models\Milestones_model"),
                "label_fields" => array("title"),
            ),
            "labels" => array(
                "label" => app_lang("labels"),
                "type" => "foreign_key",
                "link_type" => "label_group_list",
                "linked_model" => model("App\Models\Labels_model"),
                "label_fields" => array("label_group_name"),
            ),
            "status" => array(
                "label" => app_lang("status"),
                "type" => "language_key" //we'are not using this field from 1.9 but don't delete it for existing data.
            ),
            "status_id" => array(
                "label" => app_lang("status"),
                "type" => "foreign_key",
                "linked_model" => model("App\Models\Task_status_model"),
                "label_fields" => array("title"),
            ),
            "start_date" => array(
                "label" => app_lang("start_date"),
                "type" => "date"
            ),
            "deadline" => array(
                "label" => app_lang("deadline"),
                "type" => "date"
            ),
            "end_time" => array(
                "label" => app_lang("end_time"),
                "type" => "time"
            ),
            "project_id" => array(
                "label" => app_lang("project"),
                "type" => "foreign_key"
            ),
            "points" => array(
                "label" => app_lang("points"),
                "type" => "int"
            ),
            "points_edited" => array(
                "label" => app_lang("points"),
                "type" => "int"
            ),
            "deleted" => array(
                "label" => app_lang("deleted"),
                "type" => "int"
            ),
            "sort" => array(
                "label" => app_lang("priority"),
                "type" => "int"
            ),
            "ticket_id" => array(
                "label" => app_lang("ticket"),
                "type" => "foreign_key",
                "linked_model" => model("App\Models\Tickets_model"),
                "label_fields" => array("title"),
            ),
            "no_of_cycles" => array(
                "label" => app_lang("cycles"),
                "type" => "int"
            ),
            "recurring" => array(
                "label" => app_lang("recurring"),
                "type" => "int"
            ),
            "repeat_type" => array(
                "label" => app_lang("repeat_type"),
                "type" => "text"
            ),
            "repeat_every" => array(
                "label" => app_lang("repeat_every"),
                "type" => "int"
            ),
            "priority_id" => array(
                "label" => app_lang("priority"),
                "type" => "foreign_key",
                "linked_model" => model("App\Models\Task_priority_model"),
                "label_fields" => array("title"),
            ),
        );
    }

    function get_details($options = array()) {
        $tasks_table = $this->db->prefixTable('tasks');
        $users_table = $this->db->prefixTable('users');
        $projects = $this->db->prefixTable('projects');
        $milestones_table = $this->db->prefixTable('milestones');
        $project_members_table = $this->db->prefixTable('project_members');
        $task_status_table = $this->db->prefixTable('task_status');
        $ticket_table = $this->db->prefixTable('tickets');
        $task_priority_table = $this->db->prefixTable('task_priority');
        $notifications_table = $this->db->prefixTable("notifications");
        $awards_table = $this->db->prefixTable('task_point_awards');

        $where = "";

        $id = $this->_get_clean_value($options, "id");
        if ($id) {
            $where .= " AND $tasks_table.id=$id";
        }

        $project_id = $this->_get_clean_value($options, "project_id");
        if ($project_id) {
            $where .= " AND $tasks_table.project_id=$project_id";
        }

        $client_id = $this->_get_clean_value($options, "client_id");
        if ($client_id) {
            $where .= " AND $projects.client_id=$client_id";
        }

        $parent_task_id = $this->_get_clean_value($options, "parent_task_id");
        if ($parent_task_id) {
            $where .= " AND $tasks_table.parent_task_id=$parent_task_id";
        }

        $exclude_task_ids = $this->_get_clean_value($options, "exclude_task_ids");
        if ($exclude_task_ids) {
            $where .= " AND $tasks_table.id NOT IN($exclude_task_ids)";
        }

        $status_ids = $this->_get_clean_value($options, "status_ids");
        $status_ids_where = "";
        if ($status_ids) {
            $status_ids_where = " AND FIND_IN_SET($tasks_table.status_id,'$status_ids')";
            $where .= $status_ids_where;
        }

        $task_ids = $this->_get_clean_value($options, "task_ids");
        if ($task_ids) {
            $where .= " AND $tasks_table.ID IN($task_ids)";
        }

        $exclude_status_id = $this->_get_clean_value($options, "exclude_status_id");
        if ($exclude_status_id) {
            $where .= " AND $tasks_table.status_id!=$exclude_status_id ";
        }

        $assigned_to = $this->_get_clean_value($options, "assigned_to");
        if ($assigned_to) {
            $where .= " AND $tasks_table.assigned_to=$assigned_to";
        }

        $priority_id = $this->_get_clean_value($options, "priority_id");
        if ($priority_id) {
            $where .= " AND $tasks_table.priority_id=$priority_id";
        }

        $specific_user_id = $this->_get_clean_value($options, "specific_user_id");
        if ($specific_user_id) {
            $where .= " AND ($tasks_table.assigned_to=$specific_user_id OR FIND_IN_SET('$specific_user_id', $tasks_table.collaborators))";
        }

        $show_assigned_tasks_only_user_id = $this->_get_clean_value($options, "show_assigned_tasks_only_user_id");
        if ($show_assigned_tasks_only_user_id) {
            $where .= " AND ($tasks_table.assigned_to=$show_assigned_tasks_only_user_id OR FIND_IN_SET('$show_assigned_tasks_only_user_id', $tasks_table.collaborators))";
        }

        $project_status = $this->_get_clean_value($options, "project_status");
        if ($project_status) {
            // Phase 23b: LEFT JOIN projects — NULL status (project_id 0 / missing project) must not
            // be dropped by FIND_IN_SET(NULL, 'open'). Keep no-project tasks visible.
            $where .= " AND ($projects.id IS NULL OR FIND_IN_SET($projects.status,'$project_status'))";
        }

        $milestone_id = $this->_get_clean_value($options, "milestone_id");
        if ($milestone_id) {
            $where .= " AND $tasks_table.milestone_id=$milestone_id";
        }

        $task_status_id = $this->_get_clean_value($options, "task_status_id");
        if ($task_status_id) {
            $where .= " AND $tasks_table.status_id=$task_status_id";
        }

        $created_by = $this->_get_clean_value($options, "created_by");
        if ($created_by) {
            $where .= " AND $tasks_table.created_by=$created_by";
        }

        $where .= $this->_build_deadline_where($tasks_table, $milestones_table, $options);

        // From–to filter by task created_date (Phase 23: MySQL 8–safe, ignore zero dates)
        $where .= $this->_created_date_filter_where($tasks_table, $options);

        $exclude_reminder_date = $this->_get_clean_value($options, "exclude_reminder_date");
        if ($exclude_reminder_date) {
            $where .= " AND ($tasks_table.reminder_date !='$exclude_reminder_date') ";
        }

        $ticket_id = $this->_get_clean_value($options, "ticket_id");
        if ($ticket_id) {
            $where .= " AND $ticket_table.ticket_id=$ticket_id";
        }

        $exclude_review_tasks_for_assignee = $this->_get_clean_value($options, "exclude_review_tasks_for_assignee");
        if ($exclude_review_tasks_for_assignee) {
            $in_review_status_id = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
            if ($in_review_status_id) {
                $where .= " AND NOT ($tasks_table.status_id = $in_review_status_id AND $tasks_table.submitted_for_review_at IS NOT NULL)";
            }
        }

        $awaiting_review_for_user_id = $this->_get_clean_value($options, "awaiting_review_for_user_id");
        if ($awaiting_review_for_user_id) {
            $in_review_status_id = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
            if ($in_review_status_id) {
                $where .= " AND $tasks_table.status_id = $in_review_status_id AND $tasks_table.submitted_for_review_at IS NOT NULL AND $tasks_table.created_by = $awaiting_review_for_user_id";
            }
        }

        $awaiting_review_all = $this->_get_clean_value($options, "awaiting_review_all");
        if ($awaiting_review_all) {
            $in_review_status_id = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
            if ($in_review_status_id) {
                $where .= " AND $tasks_table.status_id = $in_review_status_id AND $tasks_table.submitted_for_review_at IS NOT NULL";
            }
        }

        $order = "";
        $sort_by_project = $this->_get_clean_value($options, "sort_by_project");
        if ($sort_by_project) {
            $order = " ORDER BY $tasks_table.project_id ASC";
        }

        $extra_left_join = "";
        $project_member_id = $this->_get_clean_value($options, "project_member_id");
        if ($project_member_id) {
            // Phase 23b: keep project-less tasks (project_id 0/NULL). LEFT JOIN alone +
            // `pm.user_id=X` drops them because the join row is NULL.
            $where .= " AND (IFNULL($tasks_table.project_id,0)=0 OR $project_members_table.user_id=$project_member_id)";
            $extra_left_join = " LEFT JOIN $project_members_table ON $tasks_table.project_id= $project_members_table.project_id AND $project_members_table.deleted=0 AND $project_members_table.user_id=$project_member_id";
        }

        $quick_filter = $this->_get_clean_value($options, "quick_filter");
        if ($quick_filter) {
            $where .= $this->make_quick_filter_query($quick_filter, $tasks_table);
        }

        $unread_status_user_id = $this->_get_clean_value($options, "unread_status_user_id");
        if (!$unread_status_user_id) {
            $unread_status_user_id = 0;
        }


        $select_labels_data_query = $this->get_labels_data_query();

        $limit_offset = "";
        $limit = $this->_get_clean_value($options, "limit");
        if ($limit) {
            $skip = $this->_get_clean_value($options, "skip");
            $offset = $skip ? $skip : 0;
            $limit_offset = " LIMIT $limit OFFSET $offset ";
        }

        $available_order_by_list = array(
            "id" => $tasks_table . ".id",
            "title" => $tasks_table . ".title",
            "start_date" => $tasks_table . ".start_date",
            "deadline" => $tasks_table . ".deadline",
            "milestone" => "milestone_title",
            "assigned_to" => "assigned_to_user",
            "status" => $tasks_table . ".status_id",
            "project" => $projects . ".title",
            "created_date" => $tasks_table . ".created_date",
        );

        $order_by = get_array_value($available_order_by_list, $this->_get_clean_value($options, "order_by"));

        if ($order_by) {
            $order_dir = $this->_get_clean_value($options, "order_dir");
            $order = " ORDER BY $order_by $order_dir ";
        }

        $search_by = get_array_value($options, "search_by");
        if ($search_by) {
            $search_by = $this->db->escapeLikeString($search_by);
            $labels_table = $this->db->prefixTable("labels");

            if (strpos($search_by, '#') !== false) {
                //get sub tasks of this task
                $search_by = substr($search_by, 1);
                $where .= " AND ($tasks_table.id='$search_by' OR $tasks_table.parent_task_id='$search_by')";
            } else {
                $where .= " AND (";
                $where .= " $tasks_table.id LIKE '%$search_by%' ESCAPE '!' ";
                $where .= " OR $tasks_table.title LIKE '%$search_by%' ESCAPE '!' ";
                $where .= " OR $tasks_table.start_date LIKE '%$search_by%' ESCAPE '!' ";
                $where .= " OR $tasks_table.deadline LIKE '%$search_by%' ESCAPE '!' ";
                $where .= " OR $milestones_table.title LIKE '%$search_by%' ESCAPE '!' ";
                $where .= " OR CONCAT($users_table.first_name, ' ', $users_table.last_name) LIKE '%$search_by%' ESCAPE '!' ";
                $where .= " OR $task_status_table.key_name LIKE '%$search_by%' ESCAPE '!' ";
                $where .= " OR $task_status_table.title LIKE '%$search_by%' ESCAPE '!' ";
                $where .= " OR $projects.title LIKE '%$search_by%' ESCAPE '!' ";
                $where .= " OR (SELECT GROUP_CONCAT($labels_table.title, ', ') FROM $labels_table WHERE FIND_IN_SET($labels_table.id, $tasks_table.labels)) LIKE '%$search_by%' ESCAPE '!' ";
                $where .= $this->get_custom_field_search_query($tasks_table, "tasks", $search_by);
                $where .= " )";
            }
        }

        //prepare custom fild binding query
        $custom_fields = get_array_value($options, "custom_fields");
        $custom_field_filter = get_array_value($options, "custom_field_filter");
        $custom_field_query_info = $this->prepare_custom_field_query_string("tasks", $custom_fields, $tasks_table, $custom_field_filter);
        $select_custom_fieds = get_array_value($custom_field_query_info, "select_string");
        $join_custom_fieds = get_array_value($custom_field_query_info, "join_string");
        $custom_fields_where = get_array_value($custom_field_query_info, "where_string");

        $this->db->query('SET SQL_BIG_SELECTS=1');

        $sub_task_counts_join = " LEFT JOIN (SELECT sub_tasks_table.parent_task_id, COUNT(*) AS sub_task_count FROM $tasks_table AS sub_tasks_table WHERE sub_tasks_table.deleted=0 GROUP BY sub_tasks_table.parent_task_id) AS sub_task_counts ON sub_task_counts.parent_task_id=$tasks_table.id";

        $notification_join = " LEFT JOIN (SELECT $notifications_table.task_id FROM $notifications_table WHERE $notifications_table.deleted=0 AND $notifications_table.event='project_task_commented' AND !FIND_IN_SET('$unread_status_user_id', $notifications_table.read_by) AND $notifications_table.user_id!=$unread_status_user_id GROUP BY $notifications_table.task_id) AS notification_table ON notification_table.task_id = $tasks_table.id";

        $scoped_user_id = $specific_user_id ? (int) $specific_user_id : ($show_assigned_tasks_only_user_id ? (int) $show_assigned_tasks_only_user_id : ($assigned_to ? (int) $assigned_to : 0));
        if (!$scoped_user_id) {
            $scoped_user_id = (int) $this->_get_clean_value($options, "user_id");
        }

        if ($scoped_user_id) {
            $awards_join = " LEFT JOIN $awards_table ON $awards_table.task_id=$tasks_table.id AND $awards_table.user_id=$scoped_user_id AND $awards_table.deleted=0";
        } else {
            $awards_join = " LEFT JOIN $awards_table ON $awards_table.task_id=$tasks_table.id AND $awards_table.user_id=COALESCE(NULLIF($tasks_table.assigned_to, 0), 0) AND $awards_table.deleted=0";
        }

        $core_from_joins_where = "
        FROM $tasks_table
        LEFT JOIN $users_table ON $users_table.id= $tasks_table.assigned_to
        LEFT JOIN $users_table AS creators ON creators.id = $tasks_table.created_by
        LEFT JOIN $projects ON $tasks_table.project_id=$projects.id 
        LEFT JOIN $milestones_table ON $tasks_table.milestone_id=$milestones_table.id 
        LEFT JOIN $task_status_table ON $tasks_table.status_id = $task_status_table.id 
        LEFT JOIN $task_priority_table ON $tasks_table.priority_id = $task_priority_table.id 
        LEFT JOIN $ticket_table ON $tasks_table.ticket_id = $ticket_table.id
        $awards_join
        $extra_left_join 
        $join_custom_fieds 
        WHERE $tasks_table.deleted=0 $where $custom_fields_where";

        $use_paginated_optimizations = (bool) $limit;

        // For paginated/server-side lists, hydrate collaborators in PHP for the returned page only.
        // For non-paginated callers, keep the original correlated subquery (return type is a raw query object).
        $collaborator_select = "";
        if ($use_paginated_optimizations) {
            $collaborator_select = "NULL AS collaborator_list,";
            $sub_task_count_select = "0 AS sub_task_count,";
            $unread_select = "NULL AS unread,";
            $data_from_joins_where = $core_from_joins_where;
        } else {
            $collaborator_select = "(SELECT GROUP_CONCAT($users_table.id, '--::--', $users_table.first_name, ' ', $users_table.last_name, '--::--' , IFNULL($users_table.image,''), '--::--', $users_table.user_type) FROM $users_table WHERE $users_table.deleted=0 AND FIND_IN_SET($users_table.id, $tasks_table.collaborators)) AS collaborator_list,";
            $sub_task_count_select = "IFNULL(sub_task_counts.sub_task_count, 0) AS sub_task_count,";
            $unread_select = "notification_table.task_id AS unread,";
            $data_from_joins_where = "
        FROM $tasks_table
        LEFT JOIN $users_table ON $users_table.id= $tasks_table.assigned_to
        LEFT JOIN $users_table AS creators ON creators.id = $tasks_table.created_by
        LEFT JOIN $projects ON $tasks_table.project_id=$projects.id 
        LEFT JOIN $milestones_table ON $tasks_table.milestone_id=$milestones_table.id 
        LEFT JOIN $task_status_table ON $tasks_table.status_id = $task_status_table.id 
        LEFT JOIN $task_priority_table ON $tasks_table.priority_id = $task_priority_table.id 
        LEFT JOIN $ticket_table ON $tasks_table.ticket_id = $ticket_table.id
        $awards_join
        $extra_left_join 
        $join_custom_fieds 
        $sub_task_counts_join   
        $notification_join
        WHERE $tasks_table.deleted=0 $where $custom_fields_where";
        }

        $sql = "SELECT $tasks_table.*, $task_status_table.key_name AS status_key_name, $task_status_table.title AS status_title,  $task_status_table.color AS status_color, CONCAT($users_table.first_name, ' ',$users_table.last_name) AS assigned_to_user, $users_table.image as assigned_to_avatar, $users_table.user_type,
                    CONCAT(creators.first_name, ' ', creators.last_name) AS created_by_user_name, creators.deleted AS created_by_user_deleted,
                    $projects.title AS project_title, $milestones_table.title AS milestone_title, IF($tasks_table.deadline IS NULL, $milestones_table.due_date,$tasks_table.deadline) AS deadline,$ticket_table.title AS ticket_title,
                    $collaborator_select
                    $sub_task_count_select
                    $task_priority_table.title AS priority_title, $task_priority_table.icon AS priority_icon, $task_priority_table.color AS priority_color,
                    IF($tasks_table.deadline IS NULL, $milestones_table.title, '') AS deadline_milestone_title, $unread_select $select_labels_data_query $select_custom_fieds,
                    $awards_table.earned_points AS awarded_points, $awards_table.original_points AS awarded_original_points, $awards_table.reduction_percent AS point_reduction_percent, $awards_table.reduction_reason AS point_reduction_reason, $awards_table.completed_date AS points_completed_date, $awards_table.is_collaborator AS awarded_is_collaborator, $awards_table.participants_count AS awarded_participants_count
        $data_from_joins_where
        $order $limit_offset";

        $raw_query = $this->db->query($sql);

        if ($limit) {
            $rows = $raw_query->getResult();

            if ($use_paginated_optimizations) {
                $this->_attach_collaborator_list_to_tasks($rows);
                $this->_attach_sub_task_counts_to_tasks($rows);
                $this->_attach_unread_status_to_tasks($rows, $unread_status_user_id);
            }

            if (get_array_value($options, "export_all")) {
                $rows = $this->_unique_tasks_by_id($rows);
            }

            if (!$status_ids_where) {
                $in_review_cond = get_setting("task_review_workflow_enabled") 
                    ? "((agg_rows.status_key_name IN ('in_review', 'review') OR LOWER(agg_rows.status_title) IN ('in review', 'review')) AND agg_rows.submitted_for_review_at IS NULL)"
                    : "(agg_rows.status_key_name IN ('in_review', 'review') OR LOWER(agg_rows.status_title) IN ('in review', 'review'))";

                // Combined single-pass query for total rows, task points, and status counts
                $combined_count_sql = "SELECT
                        COUNT(*) AS total_rows,
                        COALESCE(SUM(agg_rows.points), 0) AS total_given_points,
                        COALESCE(SUM(agg_rows.gained_points), 0) AS total_gained_points,
                        COALESCE(SUM(CASE WHEN agg_rows.is_collab = 0 THEN agg_rows.user_overall ELSE 0 END), 0) AS solo_overall_points,
                        COALESCE(SUM(CASE WHEN agg_rows.is_collab = 0 THEN agg_rows.gained_points ELSE 0 END), 0) AS solo_earned_points,
                        COALESCE(SUM(CASE WHEN agg_rows.is_collab = 1 THEN agg_rows.user_overall ELSE 0 END), 0) AS collab_overall_points,
                        COALESCE(SUM(CASE WHEN agg_rows.is_collab = 1 THEN agg_rows.gained_points ELSE 0 END), 0) AS collab_earned_points,
                        COALESCE(SUM(CASE WHEN agg_rows.status_key_name = 'to_do' THEN 1 ELSE 0 END), 0) AS todo_count,
                        COALESCE(SUM(CASE WHEN agg_rows.status_key_name = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress_count,
                        COALESCE(SUM(CASE WHEN $in_review_cond THEN 1 ELSE 0 END), 0) AS in_review_count,
                        COALESCE(SUM(CASE WHEN agg_rows.status_key_name = 'changes' OR LOWER(agg_rows.status_title) = 'changes' THEN 1 ELSE 0 END), 0) AS changes_count,
                        COALESCE(SUM(CASE WHEN agg_rows.status_key_name = 'done' THEN 1 ELSE 0 END), 0) AS done_count
                    FROM (
                        SELECT $tasks_table.id,
                            MAX($tasks_table.points) AS points,
                            MAX(IFNULL($awards_table.earned_points, 0)) AS gained_points,
                            MAX(CASE
                                WHEN IFNULL($awards_table.original_points, 0) > 0 THEN $awards_table.original_points
                                WHEN ($tasks_table.collaborators IS NULL OR $tasks_table.collaborators = '') THEN $tasks_table.points
                                ELSE ROUND($tasks_table.points / (IF($tasks_table.assigned_to > 0, 1, 0) + LENGTH($tasks_table.collaborators) - LENGTH(REPLACE($tasks_table.collaborators, ',', '')) + 1), 2)
                            END) AS user_overall,
                            MAX(CASE
                                WHEN IFNULL($awards_table.participants_count, 1) > 1 OR IFNULL($awards_table.is_collaborator, 0) = 1 OR ($tasks_table.collaborators IS NOT NULL AND $tasks_table.collaborators != '') THEN 1
                                ELSE 0
                            END) AS is_collab,
                            MAX($task_status_table.key_name) AS status_key_name,
                            MAX($task_status_table.title) AS status_title,
                            MAX($tasks_table.submitted_for_review_at) AS submitted_for_review_at
                        $core_from_joins_where
                        GROUP BY $tasks_table.id
                    ) AS agg_rows";
                $counts_row = $this->db->query($combined_count_sql)->getRow();

                return array(
                    "data" => $rows,
                    "recordsTotal" => $counts_row ? (int) $counts_row->total_rows : 0,
                    "recordsFiltered" => $counts_row ? (int) $counts_row->total_rows : 0,
                    "total_given_points" => $counts_row ? (float) $counts_row->total_given_points : 0,
                    "total_gained_points" => $counts_row ? (float) $counts_row->total_gained_points : 0,
                    "solo_overall_points" => $counts_row ? (float) $counts_row->solo_overall_points : 0,
                    "solo_earned_points" => $counts_row ? (float) $counts_row->solo_earned_points : 0,
                    "collab_overall_points" => $counts_row ? (float) $counts_row->collab_overall_points : 0,
                    "collab_earned_points" => $counts_row ? (float) $counts_row->collab_earned_points : 0,
                    "todo_count" => $counts_row ? (int) $counts_row->todo_count : 0,
                    "in_progress_count" => $counts_row ? (int) $counts_row->in_progress_count : 0,
                    "in_review_count" => $counts_row ? (int) $counts_row->in_review_count : 0,
                    "changes_count" => $counts_row ? (int) $counts_row->changes_count : 0,
                    "done_count" => $counts_row ? (int) $counts_row->done_count : 0,
                );
            }

            $count_sql = "SELECT COUNT(*) AS total_rows,
                    COALESCE(SUM(point_rows.points), 0) AS total_given_points,
                    COALESCE(SUM(point_rows.gained_points), 0) AS total_gained_points,
                    COALESCE(SUM(CASE WHEN point_rows.is_collab = 0 THEN point_rows.user_overall ELSE 0 END), 0) AS solo_overall_points,
                    COALESCE(SUM(CASE WHEN point_rows.is_collab = 0 THEN point_rows.gained_points ELSE 0 END), 0) AS solo_earned_points,
                    COALESCE(SUM(CASE WHEN point_rows.is_collab = 1 THEN point_rows.user_overall ELSE 0 END), 0) AS collab_overall_points,
                    COALESCE(SUM(CASE WHEN point_rows.is_collab = 1 THEN point_rows.gained_points ELSE 0 END), 0) AS collab_earned_points
                FROM (
                    SELECT $tasks_table.id,
                        MAX($tasks_table.points) AS points,
                        MAX(IFNULL($awards_table.earned_points, 0)) AS gained_points,
                        MAX(CASE
                            WHEN IFNULL($awards_table.original_points, 0) > 0 THEN $awards_table.original_points
                            WHEN ($tasks_table.collaborators IS NULL OR $tasks_table.collaborators = '') THEN $tasks_table.points
                            ELSE ROUND($tasks_table.points / (IF($tasks_table.assigned_to > 0, 1, 0) + LENGTH($tasks_table.collaborators) - LENGTH(REPLACE($tasks_table.collaborators, ',', '')) + 1), 2)
                        END) AS user_overall,
                        MAX(CASE
                            WHEN IFNULL($awards_table.participants_count, 1) > 1 OR IFNULL($awards_table.is_collaborator, 0) = 1 OR ($tasks_table.collaborators IS NOT NULL AND $tasks_table.collaborators != '') THEN 1
                            ELSE 0
                        END) AS is_collab
                    $core_from_joins_where
                    GROUP BY $tasks_table.id
                ) AS point_rows";
            $total_rows = $this->db->query($count_sql)->getRow();

            $status_from = $core_from_joins_where;
            if ($status_ids_where) {
                $status_from = str_replace($status_ids_where, "", $status_from);
            }
            $status_in_review_cond = get_setting("task_review_workflow_enabled")
                ? "((status_rows.status_key_name IN ('in_review', 'review') OR LOWER(status_rows.status_title) IN ('in review', 'review')) AND status_rows.submitted_for_review_at IS NULL)"
                : "(status_rows.status_key_name IN ('in_review', 'review') OR LOWER(status_rows.status_title) IN ('in review', 'review'))";

            $status_count_sql = "SELECT
                    COALESCE(SUM(CASE WHEN status_rows.status_key_name = 'to_do' THEN 1 ELSE 0 END), 0) AS todo_count,
                    COALESCE(SUM(CASE WHEN status_rows.status_key_name = 'in_progress' THEN 1 ELSE 0 END), 0) AS in_progress_count,
                    COALESCE(SUM(CASE WHEN $status_in_review_cond THEN 1 ELSE 0 END), 0) AS in_review_count,
                    COALESCE(SUM(CASE WHEN status_rows.status_key_name = 'changes' OR LOWER(status_rows.status_title) = 'changes' THEN 1 ELSE 0 END), 0) AS changes_count,
                    COALESCE(SUM(CASE WHEN status_rows.status_key_name = 'done' THEN 1 ELSE 0 END), 0) AS done_count
                FROM (
                    SELECT $tasks_table.id,
                        MAX($task_status_table.key_name) AS status_key_name,
                        MAX($task_status_table.title) AS status_title,
                        MAX($tasks_table.submitted_for_review_at) AS submitted_for_review_at
                    $status_from
                    GROUP BY $tasks_table.id
                ) AS status_rows";
            $status_rows = $this->db->query($status_count_sql)->getRow();

            return array(
                "data" => $rows,
                "recordsTotal" => $total_rows->total_rows,
                "recordsFiltered" => $total_rows->total_rows,
                "total_given_points" => (float) $total_rows->total_given_points,
                "total_gained_points" => (float) $total_rows->total_gained_points,
                "solo_overall_points" => $total_rows ? (float) $total_rows->solo_overall_points : 0,
                "solo_earned_points" => $total_rows ? (float) $total_rows->solo_earned_points : 0,
                "collab_overall_points" => $total_rows ? (float) $total_rows->collab_overall_points : 0,
                "collab_earned_points" => $total_rows ? (float) $total_rows->collab_earned_points : 0,
                "todo_count" => $status_rows ? (int) $status_rows->todo_count : 0,
                "in_progress_count" => $status_rows ? (int) $status_rows->in_progress_count : 0,
                "in_review_count" => $status_rows ? (int) $status_rows->in_review_count : 0,
                "changes_count" => $status_rows ? (int) $status_rows->changes_count : 0,
                "done_count" => $status_rows ? (int) $status_rows->done_count : 0,
            );
        } else {
            return $raw_query;
        }
    }

    /**
     * Keep one row per task id. Joins can otherwise repeat a task in full exports.
     */
    private function _unique_tasks_by_id($tasks) {
        if (!$tasks) {
            return $tasks;
        }

        $unique = array();
        $seen = array();
        foreach ($tasks as $task) {
            if (isset($task->id)) {
                if (isset($seen[$task->id])) {
                    continue;
                }
                $seen[$task->id] = true;
            }
            $unique[] = $task;
        }

        return $unique;
    }

    /**
     * Build deadline WHERE clause for tasks and kanban lists.
     * Fixes:
     * - "in_7_days" / "in_15_days": includes today through target date (inclusive of today).
     * - "custom" single date: exact match on DATE (no empty BETWEEN past/future).
     * - "custom" range (deadline_from, deadline_to or from--to string): BETWEEN from AND to.
     * - Supports both symbolic tokens ("today", "tomorrow", "in_7_days", "in_15_days") and dates.
     */
    private function _build_deadline_where($tasks_table, $milestones_table, $options) {
        $where = "";
        $start_date = $this->_get_clean_value($options, "start_date");
        $deadline = $this->_get_clean_value($options, "deadline");
        $deadline_from = $this->_get_clean_value($options, "deadline_from");
        $deadline_to = $this->_get_clean_value($options, "deadline_to");

        // Custom range explicitly provided via deadline_from and/or deadline_to
        if ($deadline_from && $deadline_to) {
            return " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date BETWEEN '$deadline_from' AND '$deadline_to', $tasks_table.deadline BETWEEN '$deadline_from' AND '$deadline_to')";
        } else if ($deadline_from) {
            return " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date >= '$deadline_from', $tasks_table.deadline >= '$deadline_from')";
        } else if ($deadline_to) {
            return " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date <= '$deadline_to', $tasks_table.deadline <= '$deadline_to')";
        }

        // Combined start_date and deadline range (events/reports)
        if ($start_date && $deadline) {
            $for_events = $this->_get_clean_value($options, "for_events");
            if ($for_events) {
                $deadline_for_events = $this->_get_clean_value($options, "deadline_for_events");
                $start_date_for_events = $this->_get_clean_value($options, "start_date_for_events");

                if ($start_date_for_events && $deadline_for_events) {
                    $where .= " AND ("
                            . "($tasks_table.deadline IS NOT NULL AND $tasks_table.deadline BETWEEN '$start_date' AND '$deadline')"
                            . " OR ($milestones_table.due_date BETWEEN '$start_date' AND '$deadline')"
                            . " OR ($tasks_table.start_date IS NOT NULL AND $tasks_table.start_date BETWEEN '$start_date' AND '$deadline'))";
                } else if ($start_date_for_events) {
                    $where .= " AND ($tasks_table.start_date IS NOT NULL AND $tasks_table.start_date BETWEEN '$start_date' AND '$deadline')";
                } else if ($deadline_for_events) {
                    $where .= " AND (($tasks_table.deadline IS NOT NULL AND $tasks_table.deadline BETWEEN '$start_date' AND '$deadline') OR $milestones_table.due_date BETWEEN '$start_date' AND '$deadline')";
                }
            } else {
                $where .= " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date BETWEEN '$start_date' AND '$deadline', $tasks_table.deadline BETWEEN '$start_date' AND '$deadline')";
            }
            return $where;
        }

        if ($deadline) {
            $now = get_my_local_time("Y-m-d");
            $tomorrow = add_period_to_date($now, 1, "days");
            $in_7_days = add_period_to_date($now, 7, "days");
            $in_15_days = add_period_to_date($now, 15, "days");

            if ($deadline === "expired") {
                $where .= " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date<'$now', $tasks_table.deadline<'$now')";
            } else if ($deadline === "today" || $deadline === $now) {
                $where .= " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date='$now', $tasks_table.deadline='$now')";
            } else if ($deadline === "tomorrow" || $deadline === $tomorrow) {
                $where .= " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date='$tomorrow', $tasks_table.deadline='$tomorrow')";
            } else if ($deadline === "in_7_days" || $deadline === $in_7_days) {
                $where .= " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date BETWEEN '$now' AND '$in_7_days', $tasks_table.deadline BETWEEN '$now' AND '$in_7_days')";
            } else if ($deadline === "in_15_days" || $deadline === $in_15_days) {
                $where .= " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date BETWEEN '$now' AND '$in_15_days', $tasks_table.deadline BETWEEN '$now' AND '$in_15_days')";
            } else if (strpos($deadline, '--') !== false) {
                $parts = explode('--', $deadline);
                $from = trim($parts[0]);
                $to = isset($parts[1]) ? trim($parts[1]) : $from;
                $where .= " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date BETWEEN '$from' AND '$to', $tasks_table.deadline BETWEEN '$from' AND '$to')";
            } else {
                // Exact single custom date
                $where .= " AND IF($tasks_table.deadline IS NULL, $milestones_table.due_date='$deadline', $tasks_table.deadline='$deadline')";
            }
        }
        return $where;
    }

    /**
     * Phase 23: created_date from–to WHERE fragment.
     * - Ignores invalid/zero dates in the filter params (MySQL 8 NO_ZERO_DATE safe).
     * - Excludes task rows with created_date '0000-00-00' / NULL from matching a real range
     *   (zero dates must never satisfy BETWEEN on strict MySQL 8).
     * - Uses DATE() so DATETIME columns (if ever migrated) stay inclusive by calendar day.
     */
    private function _created_date_filter_where($tasks_table, $options) {
        $created_from = $this->_get_clean_value($options, "created_from");
        $created_to = $this->_get_clean_value($options, "created_to");
        $valid = function ($d) {
            $d = trim((string) $d);
            if ($d === "" || $d === "0000-00-00" || $d === "0000-00-00 00:00:00") {
                return false;
            }
            $ts = strtotime(substr($d, 0, 10));
            return $ts !== false;
        };
        if ($created_from && !$valid($created_from)) {
            $created_from = "";
        }
        if ($created_to && !$valid($created_to)) {
            $created_to = "";
        }
        if (!$created_from && !$created_to) {
            return "";
        }

        // DATE() keeps calendar-day inclusivity if the column is ever DATETIME.
        // Zero-date legacy rows (0000-00-00) do not match real ranges (NULL/invalid on MySQL 8).
        if ($created_from && $created_to) {
            return " AND DATE($tasks_table.created_date) BETWEEN '$created_from' AND '$created_to'";
        }
        if ($created_from) {
            return " AND DATE($tasks_table.created_date) >= '$created_from'";
        }
        return " AND DATE($tasks_table.created_date) <= '$created_to'";
    }

    /**
     * Build collaborator_list for paginated task rows without a per-row SQL subquery.
     * Output format matches the legacy GROUP_CONCAT used in get_details().
     */
    private function _attach_collaborator_list_to_tasks($tasks) {
        if (!$tasks) {
            return;
        }

        $user_ids = array();
        foreach ($tasks as $task) {
            if (!empty($task->collaborators)) {
                foreach (explode(",", $task->collaborators) as $user_id) {
                    $user_id = trim($user_id);
                    if ($user_id !== "" && is_numeric($user_id)) {
                        $user_ids[$user_id] = $user_id;
                    }
                }
            }
        }

        if (!$user_ids) {
            foreach ($tasks as $task) {
                $task->collaborator_list = null;
            }
            return;
        }

        $users_table = $this->db->prefixTable('users');
        $users_result = $this->db->query("SELECT id, first_name, last_name, image, user_type FROM $users_table WHERE deleted=0 AND id IN (" . implode(",", $user_ids) . ")")->getResult();

        $users_map = array();
        foreach ($users_result as $user) {
            $users_map[$user->id] = $user->id . '--::--' . $user->first_name . ' ' . $user->last_name . '--::--' . ($user->image ? $user->image : '') . '--::--' . $user->user_type;
        }

        foreach ($tasks as $task) {
            $collaborator_list_parts = array();
            if (!empty($task->collaborators)) {
                foreach (explode(",", $task->collaborators) as $user_id) {
                    $user_id = trim($user_id);
                    if (isset($users_map[$user_id])) {
                        $collaborator_list_parts[] = $users_map[$user_id];
                    }
                }
            }
            $task->collaborator_list = $collaborator_list_parts ? implode(",", $collaborator_list_parts) : null;
        }
    }

    /**
     * Attach sub_task_count for paginated task rows without a full-table derived JOIN.
     */
    private function _attach_sub_task_counts_to_tasks($tasks) {
        if (!$tasks) {
            return;
        }

        foreach ($tasks as $task) {
            $task->sub_task_count = 0;
        }

        $task_ids = array();
        foreach ($tasks as $task) {
            $task_ids[] = (int) $task->id;
        }

        if (!$task_ids) {
            return;
        }

        $tasks_table = $this->db->prefixTable('tasks');
        $ids_list = implode(",", $task_ids);
        $counts_result = $this->db->query("SELECT parent_task_id, COUNT(*) AS sub_task_count FROM $tasks_table WHERE deleted=0 AND parent_task_id IN ($ids_list) GROUP BY parent_task_id")->getResult();

        $counts_map = array();
        foreach ($counts_result as $row) {
            $counts_map[(int) $row->parent_task_id] = (int) $row->sub_task_count;
        }

        foreach ($tasks as $task) {
            if (isset($counts_map[(int) $task->id])) {
                $task->sub_task_count = $counts_map[(int) $task->id];
            }
        }
    }

    /**
     * Attach unread comment indicator for paginated task rows without a full-table notification derived JOIN.
     */
    private function _attach_unread_status_to_tasks($tasks, $user_id) {
        if (!$tasks) {
            return;
        }

        foreach ($tasks as $task) {
            $task->unread = null;
        }

        $user_id = (int) $user_id;
        if (!$user_id) {
            return;
        }

        $task_ids = array();
        foreach ($tasks as $task) {
            $task_ids[] = (int) $task->id;
        }

        if (!$task_ids) {
            return;
        }

        $notifications_table = $this->db->prefixTable('notifications');
        $ids_list = implode(",", $task_ids);
        $unread_result = $this->db->query("SELECT DISTINCT task_id FROM $notifications_table WHERE task_id IN ($ids_list) AND deleted=0 AND event='project_task_commented' AND !FIND_IN_SET('$user_id', read_by) AND user_id != $user_id")->getResult();

        $unread_map = array();
        foreach ($unread_result as $row) {
            $unread_map[(int) $row->task_id] = $row->task_id;
        }

        foreach ($tasks as $task) {
            if (isset($unread_map[(int) $task->id])) {
                $task->unread = $unread_map[(int) $task->id];
            }
        }
    }

    private function make_quick_filter_query($filter, $tasks_table) {
        $project_comments_table = $this->db->prefixTable("project_comments");
        $query = "";

        if ($filter == "recently_meaning") {
            return $query;
        }

        $users_model = model("App\Models\Users_model", false);
        $login_user_id = $users_model->login_user_id();

        $recently_updated_last_time = prepare_last_recently_date_time($login_user_id);

        $project_comments_table_query = "SELECT $project_comments_table.task_id 
                           FROM $project_comments_table 
                           WHERE $project_comments_table.deleted=0 AND $project_comments_table.task_id!=0";
        $project_comments_table_group_by = "GROUP BY $project_comments_table.task_id";

        if ($filter === "recently_updated") {
            $query = " AND ($tasks_table.status_changed_at IS NOT NULL AND $tasks_table.status_changed_at>='$recently_updated_last_time')";
        } else if ($filter === "recently_commented") {
            $query = " AND $tasks_table.id IN($project_comments_table_query AND $project_comments_table.created_at>='$recently_updated_last_time' $project_comments_table_group_by)";
        } else if ($filter === "mentioned_me" && $login_user_id) {
            $mention_string = ":" . $login_user_id . "]";
            $query = " AND $tasks_table.id IN($project_comments_table_query AND $project_comments_table.description LIKE '%$mention_string%' $project_comments_table_group_by)";
        } else if ($filter === "recently_mentioned_me" && $login_user_id) {
            $mention_string = ":" . $login_user_id . "]";
            $query = " AND $tasks_table.id IN($project_comments_table_query AND $project_comments_table.description LIKE '%$mention_string%' AND $project_comments_table.created_at>='$recently_updated_last_time' $project_comments_table_group_by)";
        } else if ($filter === "recurring_tasks") {
            $query = " AND ($tasks_table.recurring=1)";
        } else {
            $query = " AND ($tasks_table.status_changed_at IS NOT NULL AND $tasks_table.status_changed_at>='$recently_updated_last_time' AND $tasks_table.status_id=$filter)";
        }

        return $query;
    }

    function get_kanban_details($options = array()) {
        $tasks_table = $this->db->prefixTable('tasks');
        $users_table = $this->db->prefixTable('users');
        $projects = $this->db->prefixTable('projects');
        $milestones_table = $this->db->prefixTable('milestones');
        $labels_table = $this->db->prefixTable('labels');
        $project_members_table = $this->db->prefixTable('project_members');
        $task_priority_table = $this->db->prefixTable('task_priority');
        $clients_table = $this->db->prefixTable('clients');
        $notifications_table = $this->db->prefixTable("notifications");
        $checklist_items_table = $this->db->prefixTable('checklist_items');
        $awards_table = $this->db->prefixTable('task_point_awards');

        $where = "";

        $id = $this->_get_clean_value($options, "id");
        if ($id) {
            $where .= " AND $tasks_table.id=$id";
        }

        $project_id = $this->_get_clean_value($options, "project_id");
        if ($project_id) {
            $where .= " AND $tasks_table.project_id=$project_id";
        }

        $client_id = $this->_get_clean_value($options, "client_id");
        if ($client_id) {
            $where .= " AND $projects.client_id=$client_id";
        }


        $status_id = $this->_get_clean_value($options, "status_id");
        if ($status_id) {
            $where .= " AND $tasks_table.status_id = $status_id";
        }

        $get_after_max_sort = $this->_get_clean_value($options, "get_after_max_sort");
        if ($get_after_max_sort) {
            $where .= " AND $tasks_table.sort > $get_after_max_sort";
        }


        $project_status = $this->_get_clean_value($options, "project_status");
        if ($project_status) {
            // Phase 23b: same null-safe open-project filter as get_details()
            $where .= " AND ($projects.id IS NULL OR FIND_IN_SET($projects.status,'$project_status'))";
        }

        $assigned_to = $this->_get_clean_value($options, "assigned_to");
        if ($assigned_to) {
            $where .= " AND $tasks_table.assigned_to=$assigned_to";
        }

        $priority_id = $this->_get_clean_value($options, "priority_id");
        if ($priority_id) {
            $where .= " AND $tasks_table.priority_id=$priority_id";
        }

        $specific_user_id = $this->_get_clean_value($options, "specific_user_id");
        if ($specific_user_id) {
            $where .= " AND ($tasks_table.assigned_to=$specific_user_id OR FIND_IN_SET('$specific_user_id', $tasks_table.collaborators))";
        }

        $show_assigned_tasks_only_user_id = $this->_get_clean_value($options, "show_assigned_tasks_only_user_id");
        if ($show_assigned_tasks_only_user_id) {
            $where .= " AND ($tasks_table.assigned_to=$show_assigned_tasks_only_user_id OR FIND_IN_SET('$show_assigned_tasks_only_user_id', $tasks_table.collaborators))";
        }

        $milestone_id = $this->_get_clean_value($options, "milestone_id");
        if ($milestone_id) {
            $where .= " AND $tasks_table.milestone_id=$milestone_id";
        }

        $created_by = $this->_get_clean_value($options, "created_by");
        if ($created_by) {
            $where .= " AND $tasks_table.created_by=$created_by";
        }

        $where .= $this->_build_deadline_where($tasks_table, $milestones_table, $options);

        // From–to filter by task created_date (tasks page / kanban; Phase 23 MySQL 8–safe)
        $where .= $this->_created_date_filter_where($tasks_table, $options);

        $search = get_array_value($options, "search");

        if ($search) {
            if (strpos($search, '#') !== false) {
                //get sub tasks of this task
                $search = $this->db->escapeString($search);
                $search = substr($search, 1);
                $where .= " AND ($tasks_table.id='$search' OR $tasks_table.parent_task_id='$search')";
            } else {
                //normal search
                $search = $this->db->escapeLikeString($search);
                $where .= " AND ($tasks_table.title LIKE '%$search%' ESCAPE '!' OR FIND_IN_SET((SELECT $labels_table.id FROM $labels_table WHERE $labels_table.deleted=0 AND $labels_table.context='task' AND $labels_table.title LIKE '%$search%' ESCAPE '!' LIMIT 1), $tasks_table.labels) OR $tasks_table.id='$search')";
            }
        }

        $limit_sql = "";

        $limit = $this->_get_clean_value($options, "limit");
        if ($limit) {
            $limit_sql = " LIMIT $limit";
        }


        $extra_left_join = "";
        $project_member_id = $this->_get_clean_value($options, "project_member_id");
        if ($project_member_id) {
            // Phase 23b: keep project-less tasks under project_member_id filter (see get_details)
            $where .= " AND (IFNULL($tasks_table.project_id,0)=0 OR $project_members_table.user_id=$project_member_id)";
            $extra_left_join = " LEFT JOIN $project_members_table ON $tasks_table.project_id= $project_members_table.project_id AND $project_members_table.deleted=0 AND $project_members_table.user_id=$project_member_id";
        }

        $quick_filter = $this->_get_clean_value($options, "quick_filter");
        if ($quick_filter) {
            $where .= $this->make_quick_filter_query($quick_filter, $tasks_table);
        }

        $exclude_review_tasks_for_assignee = $this->_get_clean_value($options, "exclude_review_tasks_for_assignee");
        if ($exclude_review_tasks_for_assignee) {
            $in_review_status_id = function_exists('get_in_review_task_status_id') ? (int) get_in_review_task_status_id() : 0;
            if ($in_review_status_id) {
                $where .= " AND NOT ($tasks_table.status_id = $in_review_status_id AND $tasks_table.submitted_for_review_at IS NOT NULL)";
            }
        }


        $custom_field_filter = $this->_get_clean_value($options, "custom_field_filter");
        $custom_field_query_info = $this->prepare_custom_field_query_string("tasks", "", $tasks_table, $custom_field_filter);
        $custom_fields_where = $this->_get_clean_value($custom_field_query_info, "where_string");

        $unread_status_user_id = $this->_get_clean_value($options, "unread_status_user_id");
        if (!$unread_status_user_id) {
            $unread_status_user_id = 0;
        }

        $select_labels_data_query = $this->get_labels_data_query();

        $scoped_user_id = $specific_user_id ? (int) $specific_user_id : ($show_assigned_tasks_only_user_id ? (int) $show_assigned_tasks_only_user_id : ($assigned_to ? (int) $assigned_to : 0));
        if (!$scoped_user_id) {
            $scoped_user_id = (int) $this->_get_clean_value($options, "user_id");
        }
        if ($scoped_user_id) {
            $awards_join = " LEFT JOIN $awards_table ON $awards_table.task_id=$tasks_table.id AND $awards_table.user_id=$scoped_user_id AND $awards_table.deleted=0";
        } else {
            $awards_join = " LEFT JOIN $awards_table ON $awards_table.task_id=$tasks_table.id AND $awards_table.user_id=COALESCE(NULLIF($tasks_table.assigned_to, 0), 0) AND $awards_table.deleted=0";
        }

        if (get_array_value($options, "return_task_counts_only")) {
            $sql = "SELECT COUNT($tasks_table.id) as tasks_count, $tasks_table.status_id
            FROM $tasks_table
            LEFT JOIN $projects ON $tasks_table.project_id=$projects.id 
            LEFT JOIN $milestones_table ON $tasks_table.milestone_id=$milestones_table.id 
            $extra_left_join
            WHERE $tasks_table.deleted=0 $where $custom_fields_where 
            GROUP BY $tasks_table.status_id";
        } else {
            $sql = "SELECT $tasks_table.id, $tasks_table.title, $tasks_table.start_date, $tasks_table.deadline, $tasks_table.end_time, $tasks_table.points, $tasks_table.status_changed_at, $tasks_table.sort, IF($tasks_table.sort!=0, $tasks_table.sort, $tasks_table.id) AS new_sort, $tasks_table.assigned_to, $tasks_table.labels, $tasks_table.status_id, $tasks_table.project_id, CONCAT($users_table.first_name, ' ',$users_table.last_name) AS assigned_to_user, $tasks_table.priority_id, $projects.title AS project_title, (SELECT $clients_table.company_name FROM $clients_table WHERE id=(SELECT $projects.client_id FROM $projects WHERE $projects.id=$tasks_table.project_id)) AS client_name, $projects.project_type AS project_type,
                $task_priority_table.title AS priority_title, $task_priority_table.icon AS priority_icon, $task_priority_table.color AS priority_color,
                $users_table.image as assigned_to_avatar, $tasks_table.parent_task_id, sub_tasks_table.id AS has_sub_tasks, parent_tasks_table.title AS parent_task_title, $notifications_table.id AS unread, 
                (SELECT COUNT($checklist_items_table.id) FROM $checklist_items_table WHERE $checklist_items_table.deleted=0 AND $checklist_items_table.task_id=$tasks_table.id) AS total_checklist,
                (SELECT COUNT($checklist_items_table.id) FROM $checklist_items_table WHERE $checklist_items_table.is_checked=1 AND $checklist_items_table.deleted=0 AND $checklist_items_table.task_id=$tasks_table.id) AS total_checklist_checked,
                COUNT(sub_tasks_table.id) AS total_sub_tasks, completed_sub_tasks_table.total_sub_tasks_done,
                $select_labels_data_query,
                $awards_table.earned_points AS awarded_points, $awards_table.original_points AS awarded_original_points, $awards_table.reduction_percent AS point_reduction_percent, $awards_table.reduction_reason AS point_reduction_reason, $awards_table.completed_date AS points_completed_date, $awards_table.is_collaborator AS awarded_is_collaborator, $awards_table.participants_count AS awarded_participants_count
        FROM $tasks_table
        LEFT JOIN (
            SELECT $tasks_table.id, $tasks_table.parent_task_id, $tasks_table.title
            FROM $tasks_table 
            WHERE $tasks_table.deleted=0 AND $tasks_table.parent_task_id!=0
        ) AS sub_tasks_table ON sub_tasks_table.parent_task_id=$tasks_table.id
        LEFT JOIN (
            SELECT COUNT($tasks_table.id) as total_sub_tasks_done, $tasks_table.parent_task_id
            FROM $tasks_table 
            WHERE $tasks_table.deleted=0 AND $tasks_table.parent_task_id!=0 AND $tasks_table.status_id=3
            GROUP BY $tasks_table.parent_task_id
        ) AS completed_sub_tasks_table ON completed_sub_tasks_table.parent_task_id=$tasks_table.id
        LEFT JOIN (
            SELECT $tasks_table.id, $tasks_table.parent_task_id, $tasks_table.title
            FROM $tasks_table 
            WHERE $tasks_table.deleted=0
        ) AS parent_tasks_table ON parent_tasks_table.id=$tasks_table.parent_task_id
        LEFT JOIN $users_table ON $users_table.id= $tasks_table.assigned_to
        LEFT JOIN $projects ON $tasks_table.project_id=$projects.id 
        LEFT JOIN $task_priority_table ON $tasks_table.priority_id = $task_priority_table.id 
        LEFT JOIN $milestones_table ON $tasks_table.milestone_id=$milestones_table.id 
        LEFT JOIN $notifications_table ON $notifications_table.task_id = $tasks_table.id AND $notifications_table.deleted=0 AND $notifications_table.event='project_task_commented' AND !FIND_IN_SET('$unread_status_user_id', $notifications_table.read_by) AND $notifications_table.user_id!=$unread_status_user_id
        $awards_join
        $extra_left_join
        WHERE $tasks_table.deleted=0 $where $custom_fields_where 
        GROUP BY $tasks_table.id
        ORDER BY $tasks_table.sort ASC $limit_sql";
        }

        return $this->db->query($sql);
    }

    function count_my_open_tasks($user_id) {
        if (!defined('MY_OPEN_TASKS_EXCLUDE_STATUS')) {
            define('MY_OPEN_TASKS_EXCLUDE_STATUS', 'In review');
        }
        $exclude_status_title = trim((string) MY_OPEN_TASKS_EXCLUDE_STATUS);

        $tasks_table = $this->db->prefixTable('tasks');
        $projects_table = $this->db->prefixTable('projects');
        $task_status_table = $this->db->prefixTable('task_status');

        $sql = "SELECT COUNT($tasks_table.id) AS total
        FROM $tasks_table
        LEFT JOIN $task_status_table ON $task_status_table.id = $tasks_table.status_id
        WHERE $tasks_table.deleted=0 
          AND ($tasks_table.assigned_to=$user_id OR FIND_IN_SET('$user_id', $tasks_table.collaborators)) 
          AND $tasks_table.status_id != 3
          AND (LOWER(TRIM($task_status_table.title)) != LOWER(" . $this->db->escape($exclude_status_title) . ") OR $task_status_table.title IS NULL)
          AND $tasks_table.project_id IN(
            SELECT $projects_table.id
            FROM $projects_table
            WHERE $projects_table.deleted=0 AND FIND_IN_SET($projects_table.status,'open')
        )";

        return $this->db->query($sql)->getRow()->total;
    }

    function get_label_suggestions($project_id) {
        $tasks_table = $this->db->prefixTable('tasks');
        $sql = "SELECT GROUP_CONCAT(labels) as label_groups
        FROM $tasks_table
        WHERE $tasks_table.deleted=0 AND $tasks_table.project_id=$project_id";
        return $this->db->query($sql)->getRow()->label_groups;
    }

    function get_my_projects_dropdown_list($user_id = 0) {
        $project_members_table = $this->db->prefixTable('project_members');
        $projects_table = $this->db->prefixTable('projects');

        $where = " AND $project_members_table.user_id=$user_id";

        $sql = "SELECT $project_members_table.project_id, $projects_table.title AS project_title
        FROM $project_members_table
        LEFT JOIN $projects_table ON $projects_table.id= $project_members_table.project_id
        WHERE $project_members_table.deleted=0 AND $projects_table.deleted=0 $where 
        GROUP BY $project_members_table.project_id";
        return $this->db->query($sql);
    }

    function get_task_statistics($options = array()) {
        $tasks_table = $this->db->prefixTable('tasks');
        $task_status_table = $this->db->prefixTable('task_status');
        $task_priority_table = $this->db->prefixTable('task_priority');
        $project_members_table = $this->db->prefixTable('project_members');

        try {
            $this->db->query("SET sql_mode = ''");
        } catch (\Exception $e) {
            
        }
        $where = "";

        $project_id = $this->_get_clean_value($options, "project_id");
        if ($project_id) {
            $where .= " AND $tasks_table.project_id=$project_id";
        }

        $user_id = $this->_get_clean_value($options, "user_id");
        if ($user_id) {
            $where .= " AND $tasks_table.assigned_to=$user_id";
        }

        $show_assigned_tasks_only_user_id = $this->_get_clean_value($options, "show_assigned_tasks_only_user_id");
        if ($show_assigned_tasks_only_user_id) {
            $where .= " AND ($tasks_table.assigned_to=$show_assigned_tasks_only_user_id OR FIND_IN_SET('$show_assigned_tasks_only_user_id', $tasks_table.collaborators))";
        }

        $extra_left_join = "";
        $project_member_id = $this->_get_clean_value($options, "project_member_id");
        if ($project_member_id) {
            $where .= " AND (IFNULL($tasks_table.project_id,0)=0 OR $project_members_table.user_id=$project_member_id)";
            $extra_left_join = " LEFT JOIN $project_members_table ON $tasks_table.project_id= $project_members_table.project_id AND $project_members_table.deleted=0 AND $project_members_table.user_id=$project_member_id";
        }

        $task_statuses = "SELECT COUNT($tasks_table.id) AS total, $tasks_table.status_id, $task_status_table.key_name, $task_status_table.title, $task_status_table.color
        FROM $tasks_table
        LEFT JOIN $task_status_table ON $task_status_table.id = $tasks_table.status_id
        $extra_left_join
        WHERE $tasks_table.deleted=0 $where
        GROUP BY $tasks_table.status_id
        ORDER BY $task_status_table.sort ASC";

        $task_priorities = "SELECT COUNT($tasks_table.id) AS total, $tasks_table.priority_id, $task_priority_table.title, $task_priority_table.icon, $task_priority_table.color
        FROM $tasks_table
        LEFT JOIN $task_priority_table ON $task_priority_table.id = $tasks_table.priority_id
        $extra_left_join
        WHERE $tasks_table.deleted=0 AND $tasks_table.priority_id!=0 AND $tasks_table.status_id!=3 $where
        GROUP BY $tasks_table.priority_id
        ORDER BY $tasks_table.priority_id DESC";

        $info = new \stdClass();
        $info->task_statuses = $this->db->query($task_statuses)->getResult();
        $info->task_priorities = $this->db->query($task_priorities)->getResult();

        return $info;
    }

    function set_task_comments_as_read($task_id, $user_id = 0) {
        $notifications_table = $this->db->prefixTable('notifications');

        $sql = "UPDATE $notifications_table SET $notifications_table.read_by = CONCAT($notifications_table.read_by,',',$user_id)
        WHERE $notifications_table.task_id=$task_id AND FIND_IN_SET($user_id, $notifications_table.read_by) = 0 AND $notifications_table.event='project_task_commented'";
        return $this->db->query($sql);
    }

    function save_reminder_date(&$data = array(), $id = 0) {
        if ($id) {
            $where = array("id" => $id);
            $this->update_where($data, $where);
        }
    }

    //get the recurring tasks which are ready to renew as on a given date
    function get_renewable_tasks($date, $task_id = 0) {
        $tasks_table = $this->db->prefixTable('tasks');
        $date = $this->db->escape($date);
        $task_id = (int) $task_id;

        $sql = "SELECT * FROM $tasks_table
                        WHERE $tasks_table.deleted=0 AND $tasks_table.recurring=1
                        AND $tasks_table.next_recurring_date IS NOT NULL AND $tasks_table.next_recurring_date <= $date
                        AND ($tasks_table.no_of_cycles < 1 OR ($tasks_table.no_of_cycles_completed < $tasks_table.no_of_cycles ))";

        if ($task_id) {
            $sql .= " AND $tasks_table.id=$task_id";
        }

        return $this->db->query($sql);
    }

    function find_clone_for_occurrence($parent_task_id, $start_date) {
        $tasks_table = $this->db->prefixTable('tasks');
        $parent_task_id = (int) $parent_task_id;
        $start_date = $this->db->escape($start_date);

        $sql = "SELECT * FROM $tasks_table
                WHERE $tasks_table.deleted=0
                AND $tasks_table.recurring_task_id=$parent_task_id
                AND $tasks_table.start_date=$start_date
                ORDER BY $tasks_table.id DESC
                LIMIT 1";

        return $this->db->query($sql)->getRow();
    }

    function find_recent_clone($parent_task_id, $seconds = 300) {
        $tasks_table = $this->db->prefixTable('tasks');
        $parent_task_id = (int) $parent_task_id;
        $seconds = (int) $seconds;
        if ($seconds < 1) {
            $seconds = 300;
        }

        $since = date("Y-m-d H:i:s", strtotime(get_current_utc_time()) - $seconds);
        $since = $this->db->escape($since);
        $today = $this->db->escape(get_today_date());

        $sql = "SELECT * FROM $tasks_table
                WHERE $tasks_table.deleted=0
                AND $tasks_table.recurring_task_id=$parent_task_id
                AND (
                    DATE($tasks_table.created_date)=$today
                    OR $tasks_table.created_date >= $since
                )
                ORDER BY $tasks_table.id DESC
                LIMIT 1";

        return $this->db->query($sql)->getRow();
    }

    function is_valid_sql_date($date) {
        $date = substr(trim((string) $date), 0, 10);
        if ($date === "" || $date === "0000-00-00") {
            return false;
        }
        $parsed = \DateTime::createFromFormat("Y-m-d", $date);
        return $parsed && $parsed->format("Y-m-d") === $date;
    }

    function insert_recurring_clone($data) {
        $required = array("title", "project_id", "start_date", "deadline", "parent_task_id", "recurring_task_id", "next_recurring_date");
        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === "" || $data[$field] === null) {
                throw new \RuntimeException("Clone insert blocked: {$field} is required.");
            }
        }

        foreach (array("start_date", "deadline", "next_recurring_date") as $date_field) {
            if (!$this->is_valid_sql_date($data[$date_field])) {
                throw new \RuntimeException("Clone insert blocked: {$date_field} is invalid (" . var_export($data[$date_field], true) . ").");
            }
        }

        if ((int) $data["parent_task_id"] < 1 || (int) $data["recurring_task_id"] < 1) {
            throw new \RuntimeException("Clone insert blocked: parent_task_id and recurring_task_id must be the original task ID.");
        }

        $insert = array(
            "title" => $data["title"],
            "description" => isset($data["description"]) ? $data["description"] : "",
            "project_id" => (int) $data["project_id"],
            "milestone_id" => isset($data["milestone_id"]) ? (int) $data["milestone_id"] : 0,
            "assigned_to" => isset($data["assigned_to"]) ? (int) $data["assigned_to"] : 0,
            "deadline" => $data["deadline"],
            "end_time" => !empty($data["end_time"]) ? $data["end_time"] : null,
            "labels" => isset($data["labels"]) ? $data["labels"] : "",
            "points" => isset($data["points"]) && $data["points"] ? (int) $data["points"] : 1,
            "status" => "to_do",
            "status_id" => isset($data["status_id"]) ? (int) $data["status_id"] : 1,
            "priority_id" => isset($data["priority_id"]) ? (int) $data["priority_id"] : 0,
            "start_date" => $data["start_date"],
            "collaborators" => isset($data["collaborators"]) ? $data["collaborators"] : "",
            "sort" => isset($data["sort"]) ? (int) $data["sort"] : 0,
            "recurring" => 0,
            "repeat_every" => isset($data["repeat_every"]) ? (int) $data["repeat_every"] : 0,
            "repeat_type" => !empty($data["repeat_type"]) ? $data["repeat_type"] : null,
            "no_of_cycles" => isset($data["no_of_cycles"]) ? (int) $data["no_of_cycles"] : 0,
            "recurring_task_id" => (int) $data["recurring_task_id"],
            "no_of_cycles_completed" => 0,
            "created_date" => $this->is_valid_sql_date(get_today_date()) ? get_today_date() : date("Y-m-d"),
            "status_changed_at" => !empty($data["status_changed_at"]) ? $data["status_changed_at"] : get_current_utc_time(),
            "created_by" => isset($data["created_by"]) ? (int) $data["created_by"] : 0,
            "blocking" => isset($data["blocking"]) ? $data["blocking"] : "",
            "blocked_by" => isset($data["blocked_by"]) ? $data["blocked_by"] : "",
            "parent_task_id" => (int) $data["parent_task_id"],
            "next_recurring_date" => $data["next_recurring_date"],
            "reminder_date" => $this->is_valid_sql_date(get_today_date()) ? get_today_date() : date("Y-m-d"),
            "ticket_id" => isset($data["ticket_id"]) ? (int) $data["ticket_id"] : 0,
            "deleted" => 0
        );

        if ($this->has_created_at_column()) {
            $insert["created_at"] = get_current_utc_time();
        }

        if (!$this->db_builder->insert($insert)) {
            $error = $this->db->error();
            $code = isset($error["code"]) ? $error["code"] : "";
            $message = !empty($error["message"]) ? $error["message"] : "Unknown database error during clone insert.";
            throw new \RuntimeException("Clone insert failed. Database error code: {$code}. Database error message: {$message}");
        }

        $clone_id = (int) $this->db->insertID();
        if (!$clone_id) {
            throw new \RuntimeException("Clone insert failed: no new task ID was returned.");
        }

        $verification = $this->verify_inserted_clone($clone_id, (int) $data["parent_task_id"], $insert);
        if (!$verification["ok"]) {
            $this->db_builder->where("id", $clone_id)->delete();
            throw new \RuntimeException("Clone insert rolled back. " . $verification["message"]);
        }

        return array(
            "id" => $clone_id,
            "insert" => $insert,
            "row" => $verification["row"],
            "verification" => $verification
        );
    }

    function verify_inserted_clone($clone_id, $parent_id, $expected = array()) {
        $tasks_table = $this->db->prefixTable("tasks");
        $clone_id = (int) $clone_id;
        $parent_id = (int) $parent_id;
        $row = $this->db->query("SELECT * FROM $tasks_table WHERE $tasks_table.id=$clone_id LIMIT 1")->getRow();

        $checks = array();
        $ok = true;

        $add = function ($label, $pass, $detail) use (&$checks, &$ok) {
            $checks[] = array("label" => $label, "ok" => $pass, "detail" => $detail);
            if (!$pass) {
                $ok = false;
            }
        };

        $add("clone ID exists", $row && $row->id, $row ? "Clone #{$row->id}" : "No row");
        if (!$row) {
            return array("ok" => false, "message" => "No clone was created.", "checks" => $checks, "row" => null);
        }

        $add("clone ID differs from parent", (int) $row->id !== $parent_id, "clone={$row->id} parent={$parent_id}");
        $add("parent_task_id equals parent.id", (int) $row->parent_task_id === $parent_id, "parent_task_id={$row->parent_task_id}");
        $add("recurring_task_id equals parent.id", (int) $row->recurring_task_id === $parent_id, "recurring_task_id={$row->recurring_task_id}");
        $add("start_date is valid", $this->is_valid_sql_date($row->start_date), "start_date=" . var_export($row->start_date, true));
        $add("deadline is valid", $this->is_valid_sql_date($row->deadline), "deadline=" . var_export($row->deadline, true));
        $add("next_recurring_date is valid", $this->is_valid_sql_date($row->next_recurring_date), "next_recurring_date=" . var_export($row->next_recurring_date, true));
        $add("clone is not deleted", (int) $row->deleted === 0, "deleted={$row->deleted}");

        if (!empty($expected["start_date"])) {
            $add("start_date matches calculated value", (string) $row->start_date === (string) $expected["start_date"], "expected {$expected["start_date"]}");
        }
        if (!empty($expected["deadline"])) {
            $add("deadline matches calculated value", (string) $row->deadline === (string) $expected["deadline"], "expected {$expected["deadline"]}");
        }

        $message = $ok
            ? "Clone created and linked correctly."
            : "Clone verification failed: " . implode("; ", array_map(function ($check) {
                return $check["ok"] ? null : $check["label"] . " (" . $check["detail"] . ")";
            }, array_filter($checks, function ($check) {
                return !$check["ok"];
            })));

        return array(
            "ok" => $ok,
            "message" => $message,
            "checks" => $checks,
            "row" => $row
        );
    }

    function get_all_dependency_for_this_task($task_id, $type) {
        $tasks_table = $this->db->prefixTable('tasks');

        $where = "";
        if ($type == "blocked_by") {
            $where = "AND $tasks_table.blocking LIKE '%$task_id%'";
        } else {
            $where = "AND $tasks_table.blocked_by LIKE '%$task_id%'";
        }

        $sql = "SELECT GROUP_CONCAT($tasks_table.id) AS dependency_task_ids FROM $tasks_table WHERE $tasks_table.deleted=0 AND $tasks_table.id!=$task_id $where";

        return $this->db->query($sql)->getRow()->dependency_task_ids;
    }

    function update_custom_data(&$data = array(), $id = 0) {
        if ($id) {
            $where = array("id" => $id);
            $this->update_where($data, $where);

            return $id;
        }
    }

    function get_search_suggestion($search = "", $options = array()) {
        $tasks_table = $this->db->prefixTable('tasks');

        $where = "";

        $show_assigned_tasks_only_user_id = $this->_get_clean_value($options, "show_assigned_tasks_only_user_id");
        if ($show_assigned_tasks_only_user_id) {
            $where .= " AND ($tasks_table.assigned_to=$show_assigned_tasks_only_user_id OR FIND_IN_SET('$show_assigned_tasks_only_user_id', $tasks_table.collaborators))";
        }

        if ($search) {
            $search = $this->db->escapeLikeString($search);
        }

        $sql = "SELECT $tasks_table.id, $tasks_table.title
        FROM $tasks_table  
        WHERE $tasks_table.deleted=0 AND ($tasks_table.title LIKE '%$search%' ESCAPE '!' OR $tasks_table.id LIKE '%$search%' ESCAPE '!') $where
        ORDER BY $tasks_table.title ASC
        LIMIT 0, 10";

        return $this->db->query($sql);
    }

    function get_all_tasks_where_have_dependency($project_id) {
        $tasks_table = $this->db->prefixTable('tasks');

        $sql = "SELECT $tasks_table.id, $tasks_table.blocked_by, $tasks_table.blocking
        FROM $tasks_table  
        WHERE $tasks_table.deleted=0 AND $tasks_table.project_id=$project_id AND ($tasks_table.blocked_by!='' OR $tasks_table.blocking!='')";

        return $this->db->query($sql);
    }

    function save_gantt_task_date($data, $task_id) {
        parent::disable_log_activity();
        return $this->ci_save($data, $task_id);
    }

    function count_sub_task_status($options = array()) {
        $tasks_table = $this->db->prefixTable('tasks');

        $where = "";

        $parent_task_id = $this->_get_clean_value($options, "parent_task_id");
        if ($parent_task_id) {
            $where .= " AND $tasks_table.parent_task_id=$parent_task_id";
        }

        $status_id = $this->_get_clean_value($options, "status_id");
        if ($status_id) {
            $where .= " AND $tasks_table.status_id=$status_id";
        }

        $sql = "SELECT COUNT($tasks_table.id) AS total
        FROM $tasks_table
        WHERE $tasks_table.deleted=0 $where";
        return $this->db->query($sql)->getRow()->total;
    }

    function delete_task_and_sub_items($task_id) {
        $tasks_table = $this->db->prefixTable('tasks');
        $project_comments_table = $this->db->prefixTable("project_comments");
        $checklist_items_table = $this->db->prefixTable("checklist_items");

        //get task comment files info to delete the files from directory 
        $task_comment_files_sql = "SELECT * FROM $project_comments_table WHERE $project_comments_table.deleted=0 AND $project_comments_table.task_id=$task_id; ";
        $task_comments = $this->db->query($task_comment_files_sql)->getResult();

        //delete comments
        $delete_comments_sql = "UPDATE $project_comments_table SET $project_comments_table.deleted=1 WHERE $project_comments_table.task_id=$task_id; ";
        $this->db->query($delete_comments_sql);

        //delete task
        $delete_task_sql = "UPDATE $tasks_table SET $tasks_table.deleted=1 WHERE $tasks_table.id=$task_id; ";
        $this->db->query($delete_task_sql);

        if (function_exists("sync_task_point_award")) {
            try {
                $revoked = model("App\Models\Task_point_awards_model")->revoke_by_task_id($task_id);
                if ($revoked && $revoked->user_id && function_exists("refresh_user_monthly_task_points")) {
                    refresh_user_monthly_task_points($revoked->user_id, $revoked->year, $revoked->month);
                }
            } catch (\Exception $ex) {
                log_message('error', '[ERROR] task point award delete {exception}', ['exception' => $ex]);
            }
        }

        //delete checklists 
        $delete_checklists_sql = "UPDATE $checklist_items_table SET $checklist_items_table.deleted=1 WHERE $checklist_items_table.task_id=$task_id; ";
        $this->db->query($delete_checklists_sql);

        //delete the task comment files from directory
        $comment_file_path = get_setting("timeline_file_path");

        foreach ($task_comments as $comment_info) {
            if ($comment_info->files && $comment_info->files != "a:0:{}") {
                $files = unserialize($comment_info->files);
                foreach ($files as $file) {
                    delete_app_files($comment_file_path, array($file));
                }
            }
        }

        return true;
    }
    
    function get_next_sort_value($project_id=0, $status_id=1) {
        $tasks_table = $this->db->prefixTable('tasks');

        $sql = "SELECT $tasks_table.sort
        FROM $tasks_table
        WHERE $tasks_table.deleted=0 AND $tasks_table.project_id = $project_id AND $tasks_table.status_id = $status_id
        ORDER BY $tasks_table.sort DESC LIMIT 1";
        
        $row = $this->db->query($sql)->getRow();
        
        if($row){
            return $row->sort + 1;
        }else{
            return 1000; //could be any positive value
        }
    }
    

}
