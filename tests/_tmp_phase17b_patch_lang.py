from pathlib import Path

p = Path("/home/gokul/projects/crm_sunrise/app/Language/english/default_lang.php")
text = p.read_text()
old = (
    '$lang["held_points"] = "Held points";\n'
    "\n"
    '$lang["task_points_no_timer_zero"] = "No timer entry before review submission — 0 points.";\n'
    "\n"
    '$lang["task_points_change_penalty_applied"] = "Change penalty: %s round(s).";\n'
)
new = (
    '$lang["held_points"] = "Held points";\n'
    '$lang["held_award_points"] = "Held %s of %s";\n'
    '$lang["will_award_points"] = "Will award %s of %s";\n'
    '$lang["on_approval_will_award"] = "On approval will award %s of %s";\n'
    '$lang["approve_award_confirm"] = "Approving will award %s of %s points to %s. Continue?";\n'
    '$lang["task_points_reason_overdue_72h"] = "Overdue more than 72h";\n'
    '$lang["task_points_reason_overdue_48h"] = "Overdue 24–48h";\n'
    '$lang["task_points_reason_overdue_24h"] = "Overdue within 24h";\n'
    '$lang["task_points_reason_no_timer"] = "No timer";\n'
    '$lang["task_points_reason_change_penalty"] = "%d change request(s): -%s";\n'
    '$lang["task_points_split_between"] = "split between %s";\n'
    "\n"
    '$lang["task_points_no_timer_zero"] = "No timer entry before review submission — 0 points.";\n'
    "\n"
    '$lang["task_points_change_penalty_applied"] = "Change penalty: %s round(s).";\n'
)
if old not in text:
    raise SystemExit("old block not found")
p.write_text(text.replace(old, new, 1))
print("lang ok")
