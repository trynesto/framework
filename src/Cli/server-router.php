<?php

declare(strict_types=1);

/**
 * Router script for `php -S` (used by `php nesto start`): serves existing static files directly,
 * everything else goes through the front controller (index.php in the document root).
 */
$docroot = $_SERVER['DOCUMENT_ROOT'] ?? getcwd();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = rtrim($docroot, '/') . $path;

if ($path !== '/' && is_file($file) && ! str_ends_with($file, '.php')) {
    return false;
}

$front = rtrim($docroot, '/') . '/index.php';
if (! is_file($front)) {
    http_response_code(500);
    echo "No index.php found in $docroot";

    return true;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $front;
require $front;
