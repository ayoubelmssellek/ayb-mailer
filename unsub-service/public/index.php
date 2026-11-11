<?php
/**
 * Public front controller for AYB Unsubscribe Service (Hostinger).
 * Point the domain document root here (or copy contents into public_html).
 */
require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'bootstrap.php';

$uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
$path = parse_url($uri, PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$method = isset($_SERVER['REQUEST_METHOD']) ? (string)$_SERVER['REQUEST_METHOD'] : 'GET';

// Strip subdirectory prefix if app is not at domain root (optional)
$scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string)$_SERVER['SCRIPT_NAME']) : '';
$base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
if ($base !== '' && $base !== '/' && strpos($path, $base) === 0) {
	$path = substr($path, strlen($base));
	if ($path === '' || $path[0] !== '/') $path = '/'.$path;
}

// Health shortcuts
if ($path === '/' || $path === '/health' || $path === '/api/v1/health') {
	if ($path === '/') {
		header('Content-Type: text/plain; charset=utf-8');
		echo 'ayb-unsub';
		exit;
	}
	unsub_handle_api($method, '/api/v1/health');
}

// Public unsubscribe: /u/{token}
if (preg_match('#^/u/([A-Za-z0-9\-_]+)/?$#', $path, $m)) {
	unsub_handle_public_u($m[1], $method);
}

// Internal API
if (strpos($path, '/api/') === 0) {
	unsub_handle_api($method, $path);
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => false, 'err' => 'not_found'));
