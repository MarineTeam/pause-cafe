<?php
/**
 * Router for PHP's built-in server only. Not used in production.
 *
 *   php -d extension=php_pdo_sqlite -S localhost:8000 -t public router.php
 *
 * The -t matters: without it the built-in server looks for static files in the
 * project root rather than public/, and every stylesheet 404s.
 */

$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH ) ?: '/';
$file = __DIR__ . '/public' . $path;

// Let the built-in server hand back real files (CSS, images) untouched.
if ( '/' !== $path && is_file( $file ) ) {
	return false;
}

require __DIR__ . '/public/index.php';
