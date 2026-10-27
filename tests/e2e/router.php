<?php
/**
 * Роутер для вбудованого PHP-сервера (php -S): імітує mod_rewrite WordPress.
 * Запуск: php -S 127.0.0.1:PORT -t <wp-core> router.php
 */
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( $root . $path ) ) {
	return false;
}
if ( is_dir( $root . $path ) && is_file( $root . rtrim( $path, '/' ) . '/index.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php';
	require $root . rtrim( $path, '/' ) . '/index.php';
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require $root . '/index.php';
