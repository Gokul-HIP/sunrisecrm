from pathlib import Path
p = Path("app/Controllers/Projects.php")
t = p.read_text()
old = '''        $result["total_earned_score"] = $total_earned_score;
        $result["total_earned_score_label"] = $header_summary["score_label"];

        $result["total_given_points"] = $given;
'''
new = '''        $result["total_earned_score"] = $total_earned_score;
        $result["total_earned_score_label"] = $header_summary["score_label"];
        $result["held_points"] = isset($header_summary["held_points"]) ? (float) $header_summary["held_points"] : 0;
        $result["held_points_label"] = isset($header_summary["held_points_label"]) ? $header_summary["held_points_label"] : "0";

        $result["total_given_points"] = $given;
'''
if old not in t:
    raise SystemExit("not found")
if '$result["held_points"]' not in t:
    p.write_text(t.replace(old, new, 1))
    print("list summary held patched")
else:
    print("already")
