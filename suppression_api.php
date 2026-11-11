<?php
/**
 * Global suppression management API (JSON).
 * Actions: list, add, remove, soft_bounce, import_sendgrid, counts, audit
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.DIRECTORY_SEPARATOR.'suppression_lib.php';

$action = '';
if (isset($_GET['action'])) $action = strtolower(trim((string)$_GET['action']));
if ($action === '' && isset($_POST['action'])) $action = strtolower(trim((string)$_POST['action']));
if ($action === '') $action = 'list';

$in = $_POST;
if (empty($in) && $_SERVER['REQUEST_METHOD'] === 'GET') $in = $_GET;

function supp_api_out(array $payload, $code = 200)
{
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

if ($action === 'counts') {
	$cfg = suppression_config();
	$meta = function_exists('unsub_client_meta_get') ? unsub_client_meta_get() : array();
	supp_api_out(array('ok' => true, 'counts' => suppression_counts(), 'config' => array(
		'enabled' => !empty($cfg['enabled']),
		'soft_max' => $cfg['soft_max'],
		'soft_days' => $cfg['soft_days'],
		'has_unsub_base' => $cfg['unsub_base_url'] !== '',
		'service_enabled' => !empty($cfg['service_enabled']),
		'service_ready' => function_exists('unsub_client_ready') ? unsub_client_ready() : false,
		'cache_age_seconds' => function_exists('unsub_client_cache_age_seconds') ? unsub_client_cache_age_seconds() : null,
		'last_pull_ok_at' => isset($meta['last_pull_ok_at']) ? $meta['last_pull_ok_at'] : 0
	)));
}

if ($action === 'sync_pull') {
	$r = function_exists('suppression_sync_from_service') ? suppression_sync_from_service() : array('ok' => false, 'err' => 'unavailable');
	supp_api_out($r);
}

if ($action === 'list') {
	supp_api_out(suppression_query(array(
		'q' => isset($in['q']) ? $in['q'] : '',
		'reason' => isset($in['reason']) ? $in['reason'] : 'all',
		'page' => isset($in['page']) ? $in['page'] : 1,
		'per_page' => isset($in['per_page']) ? $in['per_page'] : 50
	)));
}

if ($action === 'audit') {
	supp_api_out(suppression_audit_query(array(
		'email' => isset($in['email']) ? $in['email'] : '',
		'page' => isset($in['page']) ? $in['page'] : 1,
		'per_page' => isset($in['per_page']) ? $in['per_page'] : 50
	)));
}

if ($action === 'add') {
	$email = isset($in['email']) ? $in['email'] : '';
	$reason = isset($in['reason']) ? $in['reason'] : 'manual';
	$detail = isset($in['detail']) ? $in['detail'] : '';
	$r = suppression_add($email, $reason, 'manual', $detail, 'operator');
	supp_api_out(array('ok' => !empty($r['ok']), 'changed' => !empty($r['changed']), 'err' => isset($r['err']) ? $r['err'] : '', 'row' => suppression_get($email)));
}

if ($action === 'remove') {
	$email = isset($in['email']) ? $in['email'] : '';
	$detail = isset($in['detail']) ? $in['detail'] : 'UI remove';
	$r = suppression_remove($email, 'operator', $detail);
	supp_api_out(array('ok' => !empty($r['ok']), 'err' => isset($r['err']) ? $r['err'] : ''));
}

if ($action === 'soft_bounce') {
	$email = isset($in['email']) ? $in['email'] : '';
	$detail = isset($in['detail']) ? $in['detail'] : '';
	$method = isset($in['source_method']) ? $in['source_method'] : 'manual';
	$r = suppression_record_soft_bounce($email, $method, $detail);
	supp_api_out($r);
}

if ($action === 'import_sendgrid') {
	$r = suppression_import_sendgrid_local();
	supp_api_out($r);
}

if ($action === 'check') {
	$email = isset($in['email']) ? $in['email'] : '';
	$lane = isset($in['lane']) ? $in['lane'] : '';
	$country = isset($in['country']) ? $in['country'] : 'pt';
	$b = suppression_is_blocked($email, array('lane' => $lane, 'country' => $country));
	supp_api_out(array('ok' => true, 'email' => suppression_normalize_email($email), 'blocked' => !empty($b['blocked']), 'reason' => $b['reason'], 'source' => $b['source'], 'detail' => $b['detail']));
}

supp_api_out(array('ok' => false, 'err' => 'Unknown action'), 400);
