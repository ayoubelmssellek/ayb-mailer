<?php
/**
 * Remote Delivery Analytics API (read-only dashboard queries).
 * Actions: overview | recent
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.DIRECTORY_SEPARATOR.'remote_delivery_lib.php';

function rd_api_out(array $payload, $code = 200)
{
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

$action = isset($_GET['action']) ? strtolower(trim((string)$_GET['action'])) : 'overview';
if ($action === '' && isset($_POST['action'])) $action = strtolower(trim((string)$_POST['action']));
if ($action === '') $action = 'overview';

$days = isset($_GET['days']) ? intval($_GET['days']) : 7;

if ($action === 'overview') {
	rd_api_out(rd_overview($days));
}

if ($action === 'recent') {
	$bounds = rd_range_bounds($days);
	$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
	$per = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 40;
	$offset = ($page - 1) * $per;
	$r = rd_recent_events($bounds['start_ts'], $bounds['end_ts'], $per, $offset);
	rd_api_out(array(
		'ok' => true,
		'days' => $bounds['days'],
		'page' => $page,
		'per_page' => $per,
		'total' => $r['total'],
		'pages' => max(1, intval(ceil($r['total'] / $per))),
		'rows' => $r['rows'],
		'waiting_for_events' => ($r['total'] < 1),
		'empty_message' => 'Waiting for KumoMTA delivery events'
	));
}

rd_api_out(array('ok' => false, 'err' => 'Unknown action'), 400);
