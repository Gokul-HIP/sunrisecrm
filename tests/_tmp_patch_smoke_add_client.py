from pathlib import Path
p = Path(__file__).resolve().parents[1] / "tests" / "_tmp_content_calendar_http_smoke.php"
t = p.read_text()
old = """$r = http('GET', '/content_calendar/add_client', array(), $jar['editor']);
check('non-admin add_client page is forbidden', $r['status'] === 302 && strpos($r['location'], 'forbidden') !== false);
$r = http('GET', '/content_calendar/add_client', array(), $jar['admin']);
check('admin add_client page loads', $r['status'] === 200 && strpos($r['body'], 'cc-client-form') !== false);"""
new = """$r = http('GET', '/content_calendar/add_client', array(), $jar['editor']);
check('non-admin add_client URL redirects to calendar', $r['status'] === 302 && strpos($r['location'], 'content_calendar') !== false && strpos($r['location'], 'add_client') === false);
$r = http('GET', '/content_calendar/add_client', array(), $jar['admin']);
check('admin add_client URL redirects to calendar', $r['status'] === 302 && strpos($r['location'], 'content_calendar') !== false && strpos($r['location'], 'add_client') === false);"""
if old not in t:
    raise SystemExit("pattern not found")
p.write_text(t.replace(old, new, 1))
print("ok")
