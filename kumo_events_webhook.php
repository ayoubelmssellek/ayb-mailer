<?php
/**
 * Authenticated ingest for KumoMTA log-hook webhooks (Windows VPS side).
 *
 * Inactive until KUMO_EVENTS_WEBHOOK_TOKEN is set in .env.
 * Does not modify sending. Does not call Ubuntu VPS.
 *
 * Auth: Authorization: Bearer <token>  OR  X-AYB-Kumo-Token: <token>
 * Body: single JSON log record, or {"events":[...]}, or a JSON array.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once __DIR__.DIRECTORY_SEPARATOR.'remote_delivery_lib.php';

function kumo_wh_out(array $payload, $code = 200)
{
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
	$cfg = rd_config();
	kumo_wh_out(array(
		'ok' => true,
		'service' => 'kumo_events_webhook',
		'ingest_enabled' => !empty($cfg['ingest_enabled']),
		'note' => empty($cfg['ingest_enabled'])
			? 'Inactive — configure KUMO_EVENTS_WEBHOOK_TOKEN then point KumoMTA log_hooks at this URL'
			: 'Ready to receive POST JSON events (Delivery/Bounce/TransientFailure/Expiration)'
	));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	kumo_wh_out(array('ok' => false, 'err' => 'Method not allowed'), 405);
}

$cfg = rd_config();
if (empty($cfg['ingest_enabled'])) {
	kumo_wh_out(array(
		'ok' => false,
		'err' => 'Ingest inactive: KUMO_EVENTS_WEBHOOK_TOKEN not configured',
		'waiting_for_events' => true
	), 503);
}

$provided = '';
$hdrAuth = isset($_SERVER['HTTP_AUTHORIZATION']) ? (string)$_SERVER['HTTP_AUTHORIZATION'] : '';
if ($hdrAuth === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
	$hdrAuth = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
}
if (stripos($hdrAuth, 'Bearer ') === 0) {
	$provided = trim(substr($hdrAuth, 7));
}
if ($provided === '' && isset($_SERVER['HTTP_X_AYB_KUMO_TOKEN'])) {
	$provided = trim((string)$_SERVER['HTTP_X_AYB_KUMO_TOKEN']);
}
if ($provided === '' || !hash_equals($cfg['token'], $provided)) {
	kumo_wh_out(array('ok' => false, 'err' => 'Unauthorized'), 401);
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
	kumo_wh_out(array('ok' => false, 'err' => 'Empty body'), 400);
}
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
	kumo_wh_out(array('ok' => false, 'err' => 'Invalid JSON'), 400);
}

$items = array();
if (isset($decoded['events']) && is_array($decoded['events'])) {
	$items = $decoded['events'];
} else if (isset($decoded['type']) || isset($decoded['event_type']) || isset($decoded['log_record'])) {
	$items = array($decoded);
} else if (array_keys($decoded) === range(0, count($decoded) - 1)) {
	$items = $decoded;
} else {
	// Single associative object without type still try
	$items = array($decoded);
}

if (count($items) > 500) {
	kumo_wh_out(array('ok' => false, 'err' => 'Batch too large (max 500)'), 413);
}

$result = rd_ingest_batch($items);
$code = !empty($result['ok']) ? 200 : 207;
kumo_wh_out(array_merge(array('service' => 'kumo_events_webhook'), $result), $code);
