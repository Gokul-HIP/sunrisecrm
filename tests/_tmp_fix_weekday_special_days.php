<?php
/**
 * One-time CLI script to clean weekday names and trailing weekday suffixes from rise_cc_special_days.
 * Local DB only. Run with: php tests/_tmp_fix_weekday_special_days.php
 */

$mysqli = new mysqli('127.0.0.1', 'root', '', 'sunrisecrmnew');
if ($mysqli->connect_error) {
    fwrite(STDERR, "DB Connection failed: " . $mysqli->connect_error . PHP_EOL);
    exit(1);
}

$weekday_token = '(?:monday|mon|tuesday|tue|tues|wednesday|wed|thursday|thu|thur|thurs|friday|fri|saturday|sat|sunday|sun)\.?';
$exact_weekday_pattern = '/^\s*' . $weekday_token . '\s*$/ui';

function clean_weekday_suffix($text, $weekday_token) {
    $changed = true;
    while ($changed) {
        $before = $text;
        $text = preg_replace('/\s*[\/|,\r\n]\s*' . $weekday_token . '\s*$/ui', '', $text);
        $text = preg_replace('/^\s*' . $weekday_token . '\s*[\/|,\r\n]\s*/ui', '', $text);
        $text = preg_replace('/([\/|,\r\n])\s*' . $weekday_token . '\s*([\/|,\r\n])/ui', '$1', $text);
        $changed = ($before !== $text);
    }
    return trim($text);
}

$res = $mysqli->query("SELECT id, calendar_id, special_date, label FROM rise_cc_special_days");
if (!$res) {
    fwrite(STDERR, "Query failed: " . $mysqli->error . PHP_EOL);
    exit(1);
}

$deleted_count = 0;
$updated_count = 0;

$del_stmt = $mysqli->prepare("DELETE FROM rise_cc_special_days WHERE id = ?");
$upd_stmt = $mysqli->prepare("UPDATE rise_cc_special_days SET label = ? WHERE id = ?");

while ($row = $res->fetch_assoc()) {
    $id = (int) $row['id'];
    $label = trim($row['label']);

    // Pure weekday check
    if (preg_match($exact_weekday_pattern, $label)) {
        $del_stmt->bind_param("i", $id);
        $del_stmt->execute();
        $deleted_count++;
        continue;
    }

    // Compound weekday check
    $cleaned = clean_weekday_suffix($label, $weekday_token);
    if ($cleaned === "" || preg_match($exact_weekday_pattern, $cleaned)) {
        $del_stmt->bind_param("i", $id);
        $del_stmt->execute();
        $deleted_count++;
    } elseif ($cleaned !== $label) {
        $upd_stmt->bind_param("si", $cleaned, $id);
        $upd_stmt->execute();
        $updated_count++;
    }
}

echo "=== Weekday Special Days Cleanup Complete ===" . PHP_EOL;
echo "Deleted rows (pure weekday names): " . $deleted_count . PHP_EOL;
echo "Updated rows (stripped compound weekdays): " . $updated_count . PHP_EOL;
echo "Total rows modified: " . ($deleted_count + $updated_count) . PHP_EOL;

$del_stmt->close();
$upd_stmt->close();
$mysqli->close();
