<?php
/**
 * Router script for PHP built-in server.
 * Usage: php -S 0.0.0.0:8000 -t public router.php
 *
 * Serves real static files from /public; otherwise hands off to public/index.php.
 */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . '/public' . $uri;
if ($uri !== '/' && is_file($file)) {
    return false;
}
require __DIR__ . '/public/index.php';
