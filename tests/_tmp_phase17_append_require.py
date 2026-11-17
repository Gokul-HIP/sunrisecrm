from pathlib import Path
p = Path("app/Helpers/task_points_helper.php")
text = p.read_text()
needle = 'require_once __DIR__ . "/task_points_review_rules_helper.php";'
if needle not in text:
    text = text.rstrip() + "\n\n" + needle + "\n"
    p.write_text(text)
    print("appended")
else:
    print("already present")
