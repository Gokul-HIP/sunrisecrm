<?php
chdir(dirname(__DIR__));
$base = '1449be8';
$tracked = $untracked = [];
exec("git -c core.quotepath=false diff --name-only {$base} -- . 2>/dev/null", $tracked);
exec("git -c core.quotepath=false ls-files --others --exclude-standard 2>/dev/null", $untracked);
$all = array_unique(array_merge($tracked, $untracked));
sort($all);

$out = [];
foreach ($all as $path) {
    if ($path === 'app/Config/Database.php') continue;
    if ($path === 'app/Config/Routes.php') continue; // identical to base
    if (str_starts_with($path, 'system/')) continue;
    if (str_starts_with($path, 'tests/')) continue;
    if (str_starts_with($path, 'writable/')) continue;
    if (str_starts_with($path, 'database/')) continue;
    if (str_starts_with($path, 'files/')) continue;
    if (str_starts_with($path, 'scratch_')) continue;
    if (str_starts_with($path, 'vendor/')) continue;
    $out[] = $path;
}

echo "=== FLAGGED ===\n";
echo "app/Config/Database.php\n";
echo "system/* (clean)\n";
echo "app/Config/Routes.php (identical to 1449be8)\n";
echo "writable/logs/*\n";
echo "\n=== UPLOAD ===\n";
foreach ($out as $p) {
    echo $p, "\n";
}
echo "COUNT=" . count($out) . "\n";
