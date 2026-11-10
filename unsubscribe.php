<?php
/**
 * One-click / browser unsubscribe endpoint for KumoMTA marketing mail.
 * GET/POST with token=… (HMAC). RFC 8058 one-click uses POST List-Unsubscribe=One-Click.
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'suppression_lib.php';

function unsub_wants_json()
{
	$accept = isset($_SERVER['HTTP_ACCEPT']) ? (string)$_SERVER['HTTP_ACCEPT'] : '';
	if (stripos($accept, 'application/json') !== false) return true;
	if (isset($_GET['format']) && $_GET['format'] === 'json') return true;
	return false;
}

function unsub_respond($ok, $message, $email = '')
{
	if (unsub_wants_json()) {
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode(array('ok' => $ok, 'message' => $message, 'email' => $email), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}
	header('Content-Type: text/html; charset=utf-8');
	header('Cache-Control: no-store');
	$title = $ok ? 'Unsubscribed' : 'Unsubscribe';
	$color = $ok ? '#166534' : '#991b1b';
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.htmlspecialchars($title).'</title></head><body style="font-family:Segoe UI,system-ui,sans-serif;background:#f8fafc;margin:0;padding:40px 16px">';
	echo '<div style="max-width:480px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:28px">';
	echo '<h1 style="margin:0 0 12px;font-size:22px;color:'.$color.'">'.htmlspecialchars($title).'</h1>';
	echo '<p style="margin:0;color:#334155;line-height:1.5">'.htmlspecialchars($message).'</p>';
	if ($email !== '') echo '<p style="margin:16px 0 0;color:#64748b;font-size:14px">Address: <code>'.htmlspecialchars($email).'</code></p>';
	echo '</div></body></html>';
	exit;
}

$token = '';
if (isset($_POST['token'])) $token = (string)$_POST['token'];
else if (isset($_GET['token'])) $token = (string)$_GET['token'];

// RFC 8058 body may be List-Unsubscribe=One-Click without repeating token in body when URL already has it
$oneClick = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$raw = isset($_POST['List-Unsubscribe']) ? (string)$_POST['List-Unsubscribe'] : '';
	if ($raw === '' && empty($_POST)) {
		$body = file_get_contents('php://input');
		if (is_string($body) && stripos($body, 'List-Unsubscribe=One-Click') !== false) $oneClick = true;
	}
	if (stripos($raw, 'One-Click') !== false) $oneClick = true;
}

if ($token === '') {
	unsub_respond(false, 'Missing unsubscribe token. Use the link from your email.');
}

$parsed = suppression_unsub_parse_token($token);
if (empty($parsed['ok'])) {
	unsub_respond(false, isset($parsed['err']) ? $parsed['err'] : 'Invalid token');
}

$email = $parsed['email'];
$add = suppression_add($email, 'unsubscribe', 'unsubscribe', $oneClick ? 'One-click List-Unsubscribe' : 'Browser unsubscribe link', 'recipient');
if (empty($add['ok'])) {
	unsub_respond(false, isset($add['err']) ? $add['err'] : 'Could not update preference', $email);
}

unsub_respond(true, 'You have been unsubscribed. You will not receive further marketing mail from this application list.', $email);
