<?php
/**
 * LOCAL ONLY router for "php -S" used by tests/_tmp_cc_v2_http_e2e.php.
 *  - /__ini  -> the effective PHP settings of the built-in server (JSON)
 *  - every other dynamic request is logged with its peak memory and elapsed time (CC_E2E_PEAKLOG)
 *  - static files are served as is, everything else goes to CodeIgniter's index.php
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/__ini') {
    header('Content-Type: application/json');
    echo json_encode(array(
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'max_input_time' => ini_get('max_input_time'),
        'php_ini' => php_ini_loaded_file(),
        'version' => PHP_VERSION,
        'sapi' => PHP_SAPI
    ));
    return true;
}

$file = dirname(__DIR__) . $path;
if ($path !== '/' && is_file($file) && !preg_match('/\.php$/i', $file)) {
    return false;
}

$cc_t0 = microtime(true);
$cc_log = getenv('CC_E2E_PEAKLOG');
if ($cc_log) {
    register_shutdown_function(function () use ($cc_t0, $cc_log, $path) {
        @file_put_contents($cc_log, sprintf("%s\t%d\t%d\t%.3f\n", $path, memory_get_peak_usage(true), memory_get_peak_usage(false), microtime(true) - $cc_t0), FILE_APPEND);
    });
}

require dirname(__DIR__) . '/index.php';
