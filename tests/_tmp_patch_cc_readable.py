from pathlib import Path
import re

p = Path(__file__).resolve().parents[1] / "app" / "Libraries" / "Content_calendar_import.php"
text = p.read_text()
pat = re.compile(
    r"    private function copy_workbook_to_native_temp\(\$path, \$type\) \{.*?\n    \}\n",
    re.S,
)
m = pat.search(text)
if not m:
    raise SystemExit("helper not found")

new = r"""    private function copy_workbook_to_native_temp($path, $type) {
        // is_readable() is false for some WSL/UNC paths even when copy() works, so only require is_file().
        if (!is_file($path)) {
            return null;
        }
        $ext = preg_replace('/[^a-z0-9]/i', '', (string) $type);
        if ($ext === '') {
            $ext = 'bin';
        }
        $dest = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'cc_imp_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@copy($path, $dest) || !is_file($dest)) {
            return null;
        }
        return $dest;
    }
"""
text2 = text[: m.start()] + new + text[m.end() :]
p.write_text(text2)
print("fixed is_readable check")
