<?php
/**
 * Soft-delete leftover Content Calendar clients from local browser/E2E tests.
 * Local DB only. Does not touch live.
 */
$mysqli = new mysqli("127.0.0.1", "hip_wsl", "HipWsl_ChangeThis123!", "sunrisecrmnew");
if ($mysqli->connect_error) {
    fwrite(STDERR, "connect failed\n");
    exit(1);
}

$names = array(
    "Nano Hospitals", "Organic Mohan", "SPN", "Kakubal academy",
    "Janani", "Metro", "Healthin Pocket", "Truscan", "JCI",
    "MaAx Super Speciality Hospital", "Juniore toes", "Mathru Vatsalya",
    "Naati Fresh", "One click academy", "Rainbow Skin & Hair Clinic",
    "Sunrise Digital", "Dheemahi", "DPIS", "TSRS"
);

$r = $mysqli->query("SELECT id, name FROM rise_cc_calendars WHERE deleted=0 ORDER BY id");
while ($row = $r->fetch_assoc()) {
    $id = (int) $row["id"];
    $name = $row["name"];
    $match = in_array($name, $names, true) || preg_match('/^CC[-_ ]?V2/i', $name) || preg_match('/^Test /i', $name);
    echo $id . "\t" . $name . ($match ? "\tCLEAN?" : "") . "\n";
}

if (!in_array("--yes", $argv, true)) {
    echo "Re-run with --yes to soft-delete matching leftover test clients listed above with CLEAN?\n";
    exit(0);
}

foreach ($names as $name) {
    $stmt = $mysqli->prepare("UPDATE rise_cc_calendars SET deleted=1 WHERE deleted=0 AND name=?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    if ($stmt->affected_rows) {
        echo "soft-deleted name=$name rows=" . $stmt->affected_rows . "\n";
    }
}

echo "done\n";
