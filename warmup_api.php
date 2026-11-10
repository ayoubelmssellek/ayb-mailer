<?php
/**
 * Warm-up management API (JSON).
 * Actions: status, series, set_state, update, request_increase, approve_increase, reject_increase, emergency_stop
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.DIRECTORY_SEPARATOR.'warmup_lib.php';

$action = '';
if (isset($_GET['action'])) $action = strtolower(trim((string)$_GET['action']));
if ($action === '' && isset($_POST['action'])) $action = strtolower(trim((string)$_POST['action']));
if ($action === '') $action = 'status';

$in = $_POST;
if (empty($in) && $_SERVER['REQUEST_METHOD'] === 'GET') $in = $_GET;
$method = isset($in['method']) ? strtolower(trim((string)$in['method'])) : 'kumomta';
if ($method === '') $method = 'kumomta';

function warmup_api_out(array $payload, $code = 200)
{
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

if ($action === 'status') {
	warmup_api_out(warmup_status($method));
}

if ($action === 'series') {
	$days = isset($in['days']) ? intval($in['days']) : 14;
	warmup_api_out(warmup_series($method, $days));
}

if ($action === 'set_state') {
	$state = isset($in['state']) ? $in['state'] : '';
	$reason = isset($in['reason']) ? $in['reason'] : 'Dashboard state change';
	$plan = warmup_get_plan($method);
	warmup_set_state(intval($plan['id']), $state, $reason, 'operator');
	warmup_api_out(array('ok' => true, 'status' => warmup_status($method)));
}

if ($action === 'update') {
	$fields = array();
	foreach (array('enabled', 'notes', 'require_manual_increase', 'current_daily_cap', 'hourly_cap', 'reason') as $k) {
		if (array_key_exists($k, $in)) $fields[$k] = $in[$k];
	}
	if (isset($in['provider_caps_json'])) {
		$decoded = json_decode((string)$in['provider_caps_json'], true);
		if (is_array($decoded)) $fields['provider_caps'] = $decoded;
	}
	if (isset($in['enabled'])) $fields['enabled'] = ($in['enabled'] === '1' || $in['enabled'] === 1 || $in['enabled'] === true || $in['enabled'] === 'true');
	if (isset($in['require_manual_increase'])) $fields['require_manual_increase'] = ($in['require_manual_increase'] === '1' || $in['require_manual_increase'] === 1 || $in['require_manual_increase'] === true || $in['require_manual_increase'] === 'true');
	$r = warmup_update_plan($method, $fields, 'operator');
	$r['status'] = warmup_status($method);
	warmup_api_out($r);
}

if ($action === 'request_increase') {
	$daily = isset($in['daily']) ? intval($in['daily']) : 0;
	$hourly = isset($in['hourly']) ? intval($in['hourly']) : 0;
	$reason = isset($in['reason']) ? $in['reason'] : 'Increase request';
	$r = warmup_request_increase($method, $daily, $hourly, $reason, 'operator');
	$r['status'] = warmup_status($method);
	warmup_api_out($r);
}

if ($action === 'approve_increase') {
	$id = isset($in['pending_id']) ? intval($in['pending_id']) : 0;
	$r = warmup_approve_increase($id, 'operator');
	$r['status'] = warmup_status($method);
	warmup_api_out($r);
}

if ($action === 'reject_increase') {
	$id = isset($in['pending_id']) ? intval($in['pending_id']) : 0;
	$detail = isset($in['detail']) ? $in['detail'] : '';
	$r = warmup_reject_increase($id, 'operator', $detail);
	$r['status'] = warmup_status($method);
	warmup_api_out($r);
}

if ($action === 'emergency_stop') {
	@file_put_contents(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag', '1');
	$plan = warmup_get_plan($method);
	warmup_set_state(intval($plan['id']), 'stopped', 'Emergency stop from warm-up dashboard', 'operator');
	warmup_api_out(array('ok' => true, 'message' => 'stop_send.flag written; plan set to stopped', 'status' => warmup_status($method)));
}

if ($action === 'clear_emergency') {
	@unlink(__DIR__.DIRECTORY_SEPARATOR.'stop_send.flag');
	warmup_api_out(array('ok' => true, 'message' => 'stop_send.flag cleared', 'status' => warmup_status($method)));
}

warmup_api_out(array('ok' => false, 'err' => 'Unknown action'), 400);
