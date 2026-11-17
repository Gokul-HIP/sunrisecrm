from pathlib import Path

# 1) After every apply_review_stamp_on_status_change(...); add rules applicator
proj = Path("app/Controllers/Projects.php")
text = proj.read_text()
needle = "apply_review_stamp_on_status_change("
count = 0
out = []
i = 0
while True:
    j = text.find(needle, i)
    if j < 0:
        out.append(text[i:])
        break
    out.append(text[i:j])
    # find end of this statement (semicolon after matching paren)
    k = j + len(needle)
    depth = 1
    while k < len(text) and depth:
        if text[k] == '(':
            depth += 1
        elif text[k] == ')':
            depth -= 1
        k += 1
    while k < len(text) and text[k] in ' \t':
        k += 1
    if k < len(text) and text[k] == ';':
        k += 1
    call = text[j:k]
    # Extract args between first ( and last )
    args = call[call.find('(')+1:call.rfind(')')]
    out.append(call)
    extra = (
        "\n                if (function_exists('apply_task_points_review_rules_on_status_change')) {\n"
        f"                    apply_task_points_review_rules_on_status_change({args});\n"
        "                }"
    )
    # Avoid double-wiring
    peek = text[k:k+120]
    if "apply_task_points_review_rules_on_status_change" not in peek:
        out.append(extra)
        count += 1
    i = k

new_text = ''.join(out)
proj.write_text(new_text)
print(f"wired {count} Projects hooks")

# 2) Crud_model hidden fields
crud = Path("app/Models/Crud_model.php")
ct = crud.read_text()
old = 'foreach (array("status_changed_at", "submitted_for_review_at", "points_edited", "activity_log_id") as $internal_field) {'
new = 'foreach (array("status_changed_at", "submitted_for_review_at", "points_edited", "activity_log_id", "first_review_submitted_at", "review_changes_count", "timer_lock_json", "points_rules_locked_at") as $internal_field) {'
if old not in ct:
    raise SystemExit('crud hidden list not found')
crud.write_text(ct.replace(old, new, 1))
print('crud hidden fields updated')

# 3) activity helper hidden list
act = Path("app/Helpers/activity_logs_helper.php")
at = act.read_text()
old = '''        return array(
            "submitted_for_review_at",
            "status_changed_at",
            "points_edited",
            "activity_log_id",
        );'''
new = '''        return array(
            "submitted_for_review_at",
            "status_changed_at",
            "points_edited",
            "activity_log_id",
            "first_review_submitted_at",
            "review_changes_count",
            "timer_lock_json",
            "points_rules_locked_at",
        );'''
if old not in at:
    raise SystemExit('activity hidden list not found')
act.write_text(at.replace(old, new, 1))
print('activity hidden fields updated')
