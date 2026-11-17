from pathlib import Path
p = Path("app/Helpers/task_points_helper.php")
t = p.read_text()
old = '        if ($compact && $display["is_reduced"]) {\n            $points_text .= " <span class=\'task-points-percent\'>-" . (int) $display["reduction_percent"] . "%</span>";\n        }'
new = '        if ($compact && $display["is_reduced"] && empty($display["is_held"])) {\n            $points_text .= " <span class=\'task-points-percent\'>-" . (int) $display["reduction_percent"] . "%</span>";\n        }'
if old not in t:
    raise SystemExit('not found')
p.write_text(t.replace(old, new, 1))
print('badge percent skip for held')
