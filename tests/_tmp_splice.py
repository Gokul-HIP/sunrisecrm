import io

path = "/home/gokul/projects/crm_sunrise/assets/js/content-calendar.js"
with io.open(path, "r", encoding="utf-8", newline="") as f:
    text = f.read()

crlf = "\r\n" in text
lines = text.replace("\r\n", "\n").split("\n")

def find(pred, start=0):
    for i in range(start, len(lines)):
        if pred(lines[i]):
            return i
    raise Exception("marker not found")

# 1) modal section: from the comment line before "/* modal" to "CC.confirm = confirmModal;"
m = find(lambda l: l.strip().startswith("/* modal"))
start = m - 1
assert lines[start].strip().startswith("/* ----")
end = find(lambda l: l.strip() == "CC.confirm = confirmModal;", m)

with io.open("/home/gokul/projects/crm_sunrise/tests/_tmp_js_part1.js", "r", encoding="utf-8") as f:
    part1 = f.read().rstrip("\n").split("\n")

lines[start:end + 1] = part1

# 2) add client page section
a = find(lambda l: l.strip().startswith("/* ADD CLIENT PAGE"))
astart = a - 1
assert lines[astart].strip().startswith("/* ====")
# end: the line "/* ---" that precedes "$(function () {"
f2 = find(lambda l: l.strip() == "$(function () {", a)
aend = f2 - 1
while not lines[aend].strip().startswith("/* ----"):
    aend -= 1
del lines[astart:aend]

out = "\n".join(lines)
if crlf:
    out = out.replace("\n", "\r\n")
with io.open(path, "w", encoding="utf-8", newline="") as f:
    f.write(out)
print("done", crlf, start, end, astart, aend)
