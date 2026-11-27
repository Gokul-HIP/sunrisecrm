<?php

namespace App\Controllers;

class Task_rules extends Security_Controller {

    protected $Task_rules_model;

    function __construct() {
        parent::__construct();
        helper('task_rules');
        $this->Task_rules_model = model('App\Models\Task_rules_model');
    }

    function index() {
        $this->access_only_admin();
        return $this->template->rander("task_rules/index");
    }

    function list_data() {
        $this->access_only_admin();

        $options = array(
            "department" => $this->request->getPost('department'),
            "search" => $this->request->getPost('search')
        );

        $list_data = $this->Task_rules_model->get_details($options)->getResult();
        $result = array();
        foreach ($list_data as $data) {
            $result[] = $this->_make_row($data);
        }
        echo json_encode(array("data" => $result));
    }

    private function _row_data($id) {
        $options = array("id" => $id);
        $data = $this->Task_rules_model->get_details($options)->getRow();
        return $this->_make_row($data);
    }

    private function _make_row($data) {
        $edit = modal_anchor(get_uri("task_rules/modal_form"), "<i data-feather='edit' class='icon-16'></i>", array("class" => "edit", "title" => app_lang('edit_task_rule'), "data-post-id" => $data->id));
        $delete = js_anchor("<i data-feather='x' class='icon-16'></i>", array('title' => app_lang('delete_task_rule'), "class" => "delete", "data-id" => $data->id, "data-action-url" => get_uri("task_rules/delete"), "data-action" => "delete-confirmation"));

        $pts = "-";
        if ($data->points_min !== null && $data->points_max !== null) {
            $pts = ($data->points_min == $data->points_max) ? $data->points_min : ($data->points_min . " - " . $data->points_max);
        } else if ($data->points_min !== null) {
            $pts = $data->points_min;
        }

        $mins = "-";
        if ($data->minutes_min !== null && $data->minutes_max !== null) {
            $minStr = task_rules_format_time($data->minutes_min);
            $maxStr = task_rules_format_time($data->minutes_max);
            $mins = ($data->minutes_min == $data->minutes_max) ? $maxStr : ($minStr . " - " . $maxStr);
        } else if ($data->minutes_max !== null) {
            $mins = task_rules_format_time($data->minutes_max);
        }

        return array(
            esc($data->department),
            esc($data->sub_department ? $data->sub_department : "-"),
            esc($data->group_name ? $data->group_name : "-"),
            "<strong>" . esc($data->task) . "</strong>",
            esc($data->keywords ? $data->keywords : "-"),
            $pts,
            $mins,
            $edit . $delete
        );
    }

    function modal_form() {
        $this->access_only_admin();

        $id = $this->request->getPost('id');
        $view_data['model_info'] = $this->Task_rules_model->get_one($id);
        return $this->template->view('task_rules/modal_form', $view_data);
    }

    function save() {
        $this->access_only_admin();

        $id = $this->request->getPost('id');
        $department = trim((string)$this->request->getPost('department'));
        $sub_department = trim((string)$this->request->getPost('sub_department'));
        $group_name = trim((string)$this->request->getPost('group_name'));
        $task = trim((string)$this->request->getPost('task'));
        $keywords = trim((string)$this->request->getPost('keywords'));

        $points_min = $this->request->getPost('points_min');
        $points_max = $this->request->getPost('points_max');
        $minutes_min = $this->request->getPost('minutes_min');
        $minutes_max = $this->request->getPost('minutes_max');

        if (!$department) {
            echo json_encode(array("success" => false, 'message' => "Department is required"));
            return false;
        }

        if (!$task) {
            echo json_encode(array("success" => false, 'message' => "Task is required"));
            return false;
        }

        $points_min = ($points_min !== "" && $points_min !== null) ? (int)$points_min : null;
        $points_max = ($points_max !== "" && $points_max !== null) ? (int)$points_max : null;
        $minutes_min = ($minutes_min !== "" && $minutes_min !== null) ? (int)$minutes_min : null;
        $minutes_max = ($minutes_max !== "" && $minutes_max !== null) ? (int)$minutes_max : null;

        if ($points_min !== null && $points_max !== null && $points_min > $points_max) {
            echo json_encode(array("success" => false, 'message' => "Points MIN cannot be greater than Points MAX"));
            return false;
        }

        if ($minutes_min !== null && $minutes_min < 0) {
            echo json_encode(array("success" => false, 'message' => "Minutes MIN must be 0 or greater"));
            return false;
        }

        if ($minutes_max !== null && $minutes_max < 0) {
            echo json_encode(array("success" => false, 'message' => "Minutes MAX must be 0 or greater"));
            return false;
        }

        if ($minutes_min !== null && $minutes_max !== null && $minutes_min > $minutes_max) {
            echo json_encode(array("success" => false, 'message' => "Minutes MIN cannot be greater than Minutes MAX"));
            return false;
        }

        if ($department !== 'Digital Marketing') {
            $sub_department = null;
        }

        $now = get_current_utc_time();
        $data = array(
            "department" => $department,
            "sub_department" => $sub_department ? $sub_department : null,
            "group_name" => $group_name ? $group_name : null,
            "task" => $task,
            "keywords" => $keywords ? $keywords : null,
            "points_min" => $points_min,
            "points_max" => $points_max,
            "minutes_min" => $minutes_min,
            "minutes_max" => $minutes_max,
            "updated_at" => $now
        );

        if (!$id) {
            $data["created_by"] = $this->login_user->id;
            $data["created_at"] = $now;
        }

        $save_id = $this->Task_rules_model->ci_save($data, $id);
        if ($save_id) {
            echo json_encode(array("success" => true, "data" => $this->_row_data($save_id), 'id' => $save_id, 'message' => app_lang('record_saved')));
        } else {
            echo json_encode(array("success" => false, 'message' => app_lang('error_occurred')));
        }
    }

    function delete() {
        $this->access_only_admin();

        $id = $this->request->getPost('id');
        if ($this->Task_rules_model->delete($id)) {
            echo json_encode(array("success" => true, 'message' => app_lang('record_deleted')));
        } else {
            echo json_encode(array("success" => false, 'message' => app_lang('record_cannot_be_deleted')));
        }
    }

    function match() {
        if ($this->login_user->user_type !== "staff") {
            echo json_encode(array("success" => false, "message" => app_lang("forbidden")));
            return false;
        }

        $department = $this->request->getPost('department');
        $sub_department = $this->request->getPost('sub_department');
        $title = $this->request->getPost('title');
        $content_type = $this->request->getPost('content_type');
        $content_text = $this->request->getPost('content_text');
        $simulate_time = $this->request->getPost('simulate_time');

        $result = task_rules_match($department, $sub_department, $title, $content_type, $content_text, $simulate_time);

        echo json_encode(array_merge(array("success" => true), $result));
    }
}
