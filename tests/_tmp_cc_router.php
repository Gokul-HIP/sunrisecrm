<?php
// Local only: router for "php -S" so static files (css, js, fixtures) are served, everything else goes to CodeIgniter.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = dirname(__DIR__) . $path;
if ($path !== '/' && is_file($file) && !preg_match('/\.php$/i', $file)) {
    return false;
}
require dirname(__DIR__) . '/index.php';
