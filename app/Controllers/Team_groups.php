<?php

namespace App\Controllers;

class Team_groups extends Security_Controller {

    protected $Team_groups_model;
    protected $Team_group_members_model;

    function __construct() {
        parent::__construct();
        $this->Team_groups_model = model("App\Models\Team_groups_model");
        $this->Team_group_members_model = model("App\Models\Team_group_members_model");
        helper("task_points");
    }

    // Default route redirects to first available group or team_members
    function index() {
        if (!$this->can_view_team_groups()) {
            app_redirect("forbidden");
        }

        $groups = $this->Team_groups_model->get_all_active_groups();
        if (!empty($groups)) {
            app_redirect("team_groups/view/" . $groups[0]->id);
        } else {
            app_redirect("team_members");
        }
    }

    // View team group details
    function view($id = 0) {
        if (!$this->can_view_team_groups()) {
            app_redirect("forbidden");
        }

        $id = (int) $id;
        $group = $this->Team_groups_model->get_details(array("id" => $id))->getRow();
        if (!$group || $group->deleted) {
            show_404();
        }

        $can_manage_groups = $this->can_manage_team_groups();

        // Month filter
        $month_param = $this->request->getGet('month');
        if (!$month_param || !preg_match('/^\d{4}-\d{1,2}$/', $month_param)) {
            $month_param = get_my_local_time("Y-m");
        }

        $parsed_month = parse_task_points_month($month_param);
        $year = $parsed_month["year"];
        $month_num = $parsed_month["month"];
        $month_value = sprintf("%04d-%02d", $year, $month_num);

        $month_options = get_task_points_month_options(24);

        // Fetch members of this group
        $members = $this->Team_group_members_model->get_members_details($id);
        $member_ids = array();
        foreach ($members as $m) {
            $member_ids[] = (int) $m->id;
        }

        $metrics = $this->_calculate_group_metrics($member_ids, $year, $month_num);

        // Build summary card data
        $summary = array(
            "members_count" => count($member_ids),
            "open_tasks" => 0,
            "overdue_tasks" => 0,
            "total_points" => 0.0
        );

        foreach ($member_ids as $uid) {
            if (isset($metrics[$uid])) {
                $summary["open_tasks"] += $metrics[$uid]["open_tasks"];
                $summary["overdue_tasks"] += $metrics[$uid]["overdue_tasks"];
                $summary["total_points"] += $metrics[$uid]["points"];
            }
        }

        $view_data = array(
            "group" => $group,
            "members" => $members,
            "member_ids" => $member_ids,
            "metrics" => $metrics,
            "summary" => $summary,
            "can_manage_groups" => $can_manage_groups,
            "selected_month" => $month_value,
            "month_options" => $month_options,
            "year" => $year,
            "month_num" => $month_num
        );

        return $this->template->rander("team_groups/view", $view_data);
    }

    /**
     * Efficiently calculate counts and points with grouped queries for all members at once.
     */
    private function _calculate_group_metrics($member_ids, $year, $month_num) {
        $metrics = array();
        foreach ($member_ids as $uid) {
            $metrics[$uid] = array(
                "open_tasks" => 0,
                "overdue_tasks" => 0,
                "completed_tasks" => 0,
                "points" => 0.0,
                "status_code" => "absent",
                "status_text" => app_lang("absent"),
                "status_class" => "bg-secondary"
            );
        }

        if (empty($member_ids)) {
            return $metrics;
        }

        $db = db_connect('default');
        $id_list = implode(",", $member_ids);
        $today = get_today_date();

        $tasks_table = $db->prefixTable('tasks');
        $projects_table = $db->prefixTable('projects');
        $task_status_table = $db->prefixTable('task_status');
        $awards_table = $db->prefixTable('task_point_awards');
        $attendance_table = $db->prefixTable('attendance');
        $leaves_table = $db->prefixTable('leave_applications');

        // 1. Grouped query for Open Tasks and Overdue Tasks
        // Excludes "In review" matching count_my_open_tasks
        $exclude_status_title = 'In review';
        $open_sql = "SELECT 
                        $tasks_table.assigned_to AS user_id,
                        COUNT($tasks_table.id) AS open_count,
                        SUM(CASE WHEN $tasks_table.deadline IS NOT NULL AND $tasks_table.deadline < '$today' THEN 1 ELSE 0 END) AS overdue_count
                     FROM $tasks_table
                     LEFT JOIN $task_status_table ON $task_status_table.id = $tasks_table.status_id
                     WHERE $tasks_table.deleted = 0
                       AND $tasks_table.assigned_to IN ($id_list)
                       AND $tasks_table.status_id != 3
                       AND (LOWER(TRIM($task_status_table.title)) != LOWER(" . $db->escape($exclude_status_title) . ") OR $task_status_table.title IS NULL)
                       AND $tasks_table.project_id IN (
                           SELECT $projects_table.id 
                           FROM $projects_table 
                           WHERE $projects_table.deleted = 0 AND FIND_IN_SET($projects_table.status, 'open')
                       )
                     GROUP BY $tasks_table.assigned_to";
        try {
            $open_res = $db->query($open_sql)->getResult();
            foreach ($open_res as $row) {
                $uid = (int) $row->user_id;
                if (isset($metrics[$uid])) {
                    $metrics[$uid]["open_tasks"] = (int) $row->open_count;
                    $metrics[$uid]["overdue_tasks"] = (int) $row->overdue_count;
                }
            }
        } catch (\Throwable $e) {}

        // 2. Grouped query for Completed tasks in the selected month
        $month_start = sprintf("%04d-%02d-01 00:00:00", $year, $month_num);
        $month_end = date("Y-m-t 23:59:59", strtotime($month_start));
        $completed_sql = "SELECT 
                            $tasks_table.assigned_to AS user_id,
                            COUNT($tasks_table.id) AS completed_count
                          FROM $tasks_table
                          WHERE $tasks_table.deleted = 0
                            AND $tasks_table.assigned_to IN ($id_list)
                            AND $tasks_table.status_id = 3
                            AND $tasks_table.status_changed_at >= '$month_start'
                            AND $tasks_table.status_changed_at <= '$month_end'
                          GROUP BY $tasks_table.assigned_to";
        try {
            $completed_res = $db->query($completed_sql)->getResult();
            foreach ($completed_res as $row) {
                $uid = (int) $row->user_id;
                if (isset($metrics[$uid])) {
                    $metrics[$uid]["completed_tasks"] = (int) $row->completed_count;
                }
            }
        } catch (\Throwable $e) {}

        // 3. Grouped query for Points earned in the selected month
        // Uses rise_task_point_awards to match get_user_monthly_task_points exactly
        $points_sql = "SELECT 
                         user_id,
                         COALESCE(SUM(earned_points), 0) AS total_points
                       FROM $awards_table
                       WHERE deleted = 0
                         AND user_id IN ($id_list)
                         AND year = $year
                         AND month = $month_num
                       GROUP BY user_id";
        try {
            $points_res = $db->query($points_sql)->getResult();
            foreach ($points_res as $row) {
                $uid = (int) $row->user_id;
                if (isset($metrics[$uid])) {
                    $metrics[$uid]["points"] = (float) $row->total_points;
                }
            }
        } catch (\Throwable $e) {}

        // 4. Grouped query for Today's Attendance (Clocked in)
        $clocked_in_sql = "SELECT DISTINCT user_id 
                           FROM $attendance_table 
                           WHERE deleted = 0 
                             AND status = 'incomplete' 
                             AND user_id IN ($id_list)";
        try {
            $clocked_res = $db->query($clocked_in_sql)->getResult();
            foreach ($clocked_res as $row) {
                $uid = (int) $row->user_id;
                if (isset($metrics[$uid])) {
                    $metrics[$uid]["status_code"] = "clocked_in";
                    $metrics[$uid]["status_text"] = app_lang("clocked_in");
                    $metrics[$uid]["status_class"] = "bg-success";
                }
            }
        } catch (\Throwable $e) {}

        // 5. Grouped query for Today's Leaves (On leave)
        $leave_sql = "SELECT DISTINCT applicant_id AS user_id 
                      FROM $leaves_table 
                      WHERE deleted = 0 
                        AND status = 'approved' 
                        AND '$today' BETWEEN start_date AND end_date 
                        AND applicant_id IN ($id_list)";
        try {
            $leave_res = $db->query($leave_sql)->getResult();
            foreach ($leave_res as $row) {
                $uid = (int) $row->user_id;
                if (isset($metrics[$uid]) && $metrics[$uid]["status_code"] !== "clocked_in") {
                    $metrics[$uid]["status_code"] = "on_leave";
                    $metrics[$uid]["status_text"] = app_lang("on_leave");
                    $metrics[$uid]["status_class"] = "bg-warning";
                }
            }
        } catch (\Throwable $e) {}

        return $metrics;
    }

    // Modal form to create or edit group
    function modal_form($id = 0) {
        if (!$this->can_manage_team_groups()) {
            app_redirect("forbidden");
        }

        $id = (int) $id;
        $model_info = $this->Team_groups_model->get_one($id);

        $selected_members = array();
        if ($id) {
            $selected_members = $this->Team_group_members_model->get_member_ids($id);
        }

        // Active staff members for selection
        $staff_members = $this->Users_model->get_all_where(array(
            "deleted" => 0,
            "user_type" => "staff",
            "status" => "active"
        ), 1000000, 0, "first_name")->getResult();

        $view_data = array(
            "model_info" => $model_info,
            "staff_members" => $staff_members,
            "selected_members" => $selected_members
        );

        return $this->template->view('team_groups/modal_form', $view_data);
    }

    // Save group and sync members
    function save() {
        if (!$this->can_manage_team_groups()) {
            app_redirect("forbidden");
        }

        $this->validate_submitted_data(array(
            "title" => "required"
        ));

        $id = (int) $this->request->getPost('id');
        $title = trim($this->request->getPost('title'));
        $members = $this->request->getPost('members');
        if (!is_array($members)) {
            $members = array();
        }

        $data = array(
            "title" => $title
        );

        if (!$id) {
            $data["created_by"] = $this->login_user->id;
            $data["created_at"] = get_current_utc_time();
            $data["deleted"] = 0;
        }

        $save_id = $this->Team_groups_model->ci_save($data, $id);
        if ($save_id) {
            $group_id = $id ? $id : $save_id;
            $this->Team_group_members_model->save_members($group_id, $members);

            echo json_encode(array(
                "success" => true,
                "id" => $group_id,
                "title" => $title,
                "view_url" => get_uri("team_groups/view/" . $group_id),
                "is_new" => ($id ? false : true),
                "message" => ($id ? app_lang('group_updated') : app_lang('group_created'))
            ));
        } else {
            echo json_encode(array("success" => false, "message" => app_lang('error_occurred')));
        }
    }

    // Soft delete group
    function delete() {
        if (!$this->can_manage_team_groups()) {
            app_redirect("forbidden");
        }

        $this->validate_submitted_data(array(
            "id" => "required|numeric"
        ));

        $id = (int) $this->request->getPost('id');
        if ($this->Team_groups_model->delete($id)) {
            echo json_encode(array("success" => true, "id" => $id, "message" => app_lang('group_deleted')));
        } else {
            echo json_encode(array("success" => false, "message" => app_lang('record_cannot_be_deleted')));
        }
    }

    // Remove member from group
    function remove_member() {
        if (!$this->can_manage_team_groups()) {
            app_redirect("forbidden");
        }

        $this->validate_submitted_data(array(
            "group_id" => "required|numeric",
            "user_id" => "required|numeric"
        ));

        $group_id = (int) $this->request->getPost('group_id');
        $user_id = (int) $this->request->getPost('user_id');

        if ($this->Team_group_members_model->remove_member($group_id, $user_id)) {
            echo json_encode(array("success" => true, "message" => app_lang('member_removed_from_group')));
        } else {
            echo json_encode(array("success" => false, "message" => app_lang('error_occurred')));
        }
    }
}
