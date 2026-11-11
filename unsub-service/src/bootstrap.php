<?php
/**
 * Hostinger unsubscribe service bootstrap.
 */
function unsub_cfg()
{
	static $cfg = null;
	if (is_array($cfg)) return $cfg;
	$path = dirname(__DIR__).DIRECTORY_SEPARATOR.'config.php';
	if (!is_file($path)) {
		http_response_code(500);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('ok' => false, 'err' => 'config.php missing'));
		exit;
	}
	$cfg = require $path;
	if (!is_array($cfg)) {
		http_response_code(500);
		header('Content-Type: application/json; charset=utf-8');
		echo json_encode(array('ok' => false, 'err' => 'invalid config'));
		exit;
	}
	return $cfg;
}

function unsub_db()
{
	static $pdo = null;
	if ($pdo instanceof PDO) return $pdo;
	$cfg = unsub_cfg();
	$pdo = new PDO($cfg['db_dsn'], $cfg['db_user'], $cfg['db_pass'], array(
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	));
	return $pdo;
}

function unsub_normalize_email($email)
{
	$email = strtolower(trim((string)$email));
	return $email;
}

function unsub_valid_reason($reason)
{
	$reason = strtolower(trim((string)$reason));
	$ok = array('unsubscribe', 'complaint', 'hard_bounce', 'soft_bounce', 'manual', 'invalid', 'block', 'exclude');
	return in_array($reason, $ok, true) ? $reason : 'manual';
}

function unsub_request_id()
{
	try {
		return bin2hex(random_bytes(16));
	} catch (Exception $e) {
		return uniqid('req_', true);
	}
}

function unsub_json($payload, $code = 200)
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function unsub_read_json_body()
{
	$raw = file_get_contents('php://input');
	if (!is_string($raw) || trim($raw) === '') return array();
	$j = json_decode($raw, true);
	return is_array($j) ? $j : array();
}

function unsub_bearer_token()
{
	$h = '';
	if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = (string)$_SERVER['HTTP_AUTHORIZATION'];
	else if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $h = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
	if (stripos($h, 'Bearer ') === 0) return trim(substr($h, 7));
	return '';
}

function unsub_require_api_key()
{
	$cfg = unsub_cfg();
	$got = unsub_bearer_token();
	$expect = isset($cfg['api_key']) ? (string)$cfg['api_key'] : '';
	if ($expect === '' || $got === '' || !hash_equals($expect, $got)) {
		unsub_json(array('ok' => false, 'err' => 'unauthorized'), 401);
	}
}

function unsub_require_admin_key()
{
	$cfg = unsub_cfg();
	$got = unsub_bearer_token();
	$expect = isset($cfg['admin_api_key']) ? (string)$cfg['admin_api_key'] : '';
	if ($expect === '' || $got === '' || !hash_equals($expect, $got)) {
		unsub_json(array('ok' => false, 'err' => 'unauthorized_admin'), 401);
	}
}

function unsub_audit($emailNorm, $action, $reason = '', $source = '', $detail = '', $actor = 'system', $requestId = '')
{
	$st = unsub_db()->prepare('INSERT INTO suppression_audit(email_norm, action, reason, source, detail, actor, request_id) VALUES(?,?,?,?,?,?,?)');
	$st->execute(array(
		$emailNorm,
		substr((string)$action, 0, 40),
		substr((string)$reason, 0, 40),
		substr((string)$source, 0, 80),
		substr((string)$detail, 0, 500),
		substr((string)$actor, 0, 120),
		substr((string)$requestId, 0, 64)
	));
}

function unsub_public_base()
{
	$cfg = unsub_cfg();
	return rtrim((string)$cfg['public_base_url'], '/');
}

require_once __DIR__.DIRECTORY_SEPARATOR.'suppressions.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'tokens.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'http_public.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'http_api.php';
