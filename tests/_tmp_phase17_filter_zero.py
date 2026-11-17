from pathlib import Path
p = Path("tests/_tmp_phase17_zero_timer_report.php")
t = p.read_text()
old = 'while ($t = $res->fetch_assoc()) {\n    $participants = [];'
new = 'while ($t = $res->fetch_assoc()) {\n    if (preg_match("/^(PHASE16|P17 )/i", $t["title"])) continue;\n    $participants = [];'
if old not in t:
    raise SystemExit('marker missing')
if 'PHASE16|P17' not in t:
    p.write_text(t.replace(old, new, 1))
print('ok')
