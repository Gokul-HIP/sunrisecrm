from pathlib import Path

# monthly_task_points JSON
proj = Path("app/Controllers/Projects.php")
t = proj.read_text()
old = '''            "collab_overall_points_label" => $summary["collab_overall_label"],
            "collab_overall_points" => (float) $summary["collab_overall"],
            "label" => $label_date ? date("F Y", $label_date) : $parsed["value"]
        ));'''
new = '''            "collab_overall_points_label" => $summary["collab_overall_label"],
            "collab_overall_points" => (float) $summary["collab_overall"],
            "held_points_label" => isset($summary["held_points_label"]) ? $summary["held_points_label"] : "0",
            "held_points" => isset($summary["held_points"]) ? (float) $summary["held_points"] : 0,
            "label" => $label_date ? date("F Y", $label_date) : $parsed["value"]
        ));'''
if old not in t:
    raise SystemExit('monthly json not found')
if 'held_points_label' not in t.split('function monthly_task_points')[1][:1500]:
    t = t.replace(old, new, 1)
    print('monthly json patched')
else:
    print('monthly json already ok')

# review queue points column
old_pts = '''        $points = isset($data->points) ? format_task_point_value($data->points) : "-";

        $actions = "";
        $can_action = $this->login_user->is_admin || ((int)$this->login_user->id === (int)$data->created_by);
'''
new_pts = '''        $points = isset($data->points) ? format_task_point_value($data->points) : "-";
        if (function_exists("get_task_held_points_display")) {
            $held = get_task_held_points_display($data);
            if (!empty($held["is_held"])) {
                $points = htmlspecialchars($held["label"]);
            }
        }

        $actions = "";
        $can_action = $this->login_user->is_admin || ((int)$this->login_user->id === (int)$data->created_by);
'''
if old_pts not in t:
    raise SystemExit('queue points not found')
if 'get_task_held_points_display' not in t.split('_make_task_in_review_row')[1][:2000]:
    t = t.replace(old_pts, new_pts, 1)
    print('queue points patched')
else:
    print('queue points already ok')
proj.write_text(t)

# summary view
summary = Path("app/Views/projects/tasks/task_points_summary.php")
sv = summary.read_text()
if 'task-held-points-value' not in sv:
    sv = sv.replace(
'''        <div class="task-points-summary-item hide">
            <span class="task-points-summary-label"><?php echo app_lang("total_points"); ?></span>
            <span class="task-points-given-value points-zero">0</span>
        </div>
    </div>
</div>
''',
'''        <div class="task-points-summary-item hide">
            <span class="task-points-summary-label"><?php echo app_lang("total_points"); ?></span>
            <span class="task-points-given-value points-zero">0</span>
        </div>
        <div class="task-points-summary-item">
            <span class="task-points-summary-label"><?php echo app_lang("held_points") ?: "Held points"; ?></span>
            <span class="task-held-points-value points-zero">0</span>
        </div>
    </div>
</div>
''')
    summary.write_text(sv)
    print('summary view patched')
else:
    print('summary view already ok')

# summary js
js = Path("app/Views/projects/tasks/task_points_summary_js.php")
jt = js.read_text()
if 'task-held-points-value' not in jt:
    jt = jt.replace(
        'var collabOverall = json.collab_overall_points_label || "0";',
        'var collabOverall = json.collab_overall_points_label || "0";\n                var heldPoints = json.held_points_label || "0";'
    )
    jt = jt.replace(
        '$collabOverall.text(collabOverall);',
        '$collabOverall.text(collabOverall);\n                var $held = $summary.find(".task-held-points-value");\n                if ($held.length) {\n                    $held.removeClass("points-zero points-low points-high").addClass(pointClass(json.held_points || 0)).text(heldPoints);\n                }'
    )
    js.write_text(jt)
    print('summary js patched')
else:
    print('summary js already ok')

# Also add held to Tasks_model datatable JSON if summary comes from there
# Grep showed solo_earned_points in Tasks_model aggregation - patch later if needed via monthly endpoint.

# language
for lang_path in [Path("app/Language/english/custom_lang.php"), Path("app/Language/english/default_lang.php")]:
    lt = lang_path.read_text()
    additions = [
        ('held_points', 'Held points'),
        ('task_points_no_timer_zero', 'No timer entry before review submission — 0 points.'),
        ('task_points_change_penalty_applied', 'Change penalty: %s round(s).'),
    ]
    changed = False
    for key, val in additions:
        if f'$lang["{key}"]' not in lt:
            # insert before return $lang or at end of custom
            if 'return $lang;' in lt:
                lt = lt.replace('return $lang;', f'$lang["{key}"] = "{val}";\n\nreturn $lang;', 1)
            else:
                lt += f'\n$lang["{key}"] = "{val}";\n'
            changed = True
    if changed:
        lang_path.write_text(lt)
        print(f'lang patched {lang_path}')
    else:
        print(f'lang ok {lang_path}')
