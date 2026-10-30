<?php
/**
 * Rebuild app.min.js from app.js and splice it into app.all.js.
 * Run: php writable/rebuild_app_js_bundle.php
 */

$root = dirname(__DIR__);
$appJs = $root . '/assets/js/app.js';
$appMinJs = $root . '/assets/js/app.min.js';
$appAllJs = $root . '/assets/js/app.all.js';

if (!is_file($appJs) || !is_file($appAllJs)) {
    fwrite(STDERR, "Missing app.js or app.all.js\n");
    exit(1);
}

$terserCmd = 'npx --yes terser "' . $appJs . '" -o "' . $appMinJs . '" -c -m 2>&1';
exec($terserCmd, $terserOutput, $terserCode);

if ($terserCode !== 0 || !is_file($appMinJs) || !filesize($appMinJs)) {
    fwrite(STDERR, "Terser failed, copying app.js as fallback.\n");
    if ($terserOutput) {
        fwrite(STDERR, implode("\n", $terserOutput) . "\n");
    }
    copy($appJs, $appMinJs);
}

$allContent = file_get_contents($appAllJs);
$minContent = file_get_contents($appMinJs);

$marker = '};function delayAction';
$pos = strrpos($allContent, $marker);

if ($pos === false) {
    fwrite(STDERR, "Could not find app.min splice marker in app.all.js\n");
    exit(1);
}

$newAll = substr($allContent, 0, $pos + 2) . $minContent;
file_put_contents($appAllJs, $newAll);

echo "Rebuilt app.min.js and updated app.all.js successfully.\n";
