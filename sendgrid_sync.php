<?php
/**
 * Local SendGrid API synchronization (polling — not real-time, no webhooks).
 *
 * POST JSON:
 *   { "action":"sync", "days":7 }
 *   { "action":"sync", "start_date":"YYYY-MM-DD", "end_date":"YYYY-MM-DD" }
 *   { "action":"summary", "days":7 }
 *   { "action":"probe" }
 *   { "action":"messages", "page":1, "email":"", "status":"all" }
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL);
@set_time_limit(180);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.DIRECTORY_SEPARATOR.'sendgrid_analytics_lib.php';

function sg_sync_parse_dates(array $in)
{
	$days = isset($in['days']) ? intval($in['days']) : 7;
	if ($days < 1) $days = 1;
	if ($days > 90) $days = 90;
	$start = '';
	$end = '';
	if (!empty($in['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['start_date'])) $start = (string)$in['start_date'];
	if (!empty($in['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['end_date'])) $end = (string)$in['end_date'];
	if ($start === '' || $end === '') {
		$end = gmdate('Y-m-d');
		$start = gmdate('Y-m-d', strtotime('-'.($days - 1).' days'));
	}
	if (strcmp($start, $end) > 0) { $t = $start; $start = $end; $end = $t; }
	return array($start, $end, $days);
}

try {
	$raw = file_get_contents('php://input');
	$in = json_decode($raw !== false ? $raw : '', true);
	if (!is_array($in)) $in = $_POST;
	if (!is_array($in)) $in = array();
	$action = isset($in['action']) ? (string)$in['action'] : 'summary';
	list($start, $end, $days) = sg_sync_parse_dates($in);

	if ($action === 'probe') {
		$probe = sg_sync_all($start, $end);
		echo json_encode(array(
			'ok' => !empty($probe['ok']),
			'action' => 'probe',
			'capabilities' => sg_capabilities(),
			'last_sync_at' => sg_meta_get('last_sync_at'),
			'limitations' => isset($probe['limitations']) ? $probe['limitations'] : array(),
			'note' => 'Probe runs a sync pass and records endpoint availability. Not real-time.'
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	if ($action === 'sync') {
		if (!sendgrid_is_configured()) {
			echo json_encode(array('ok' => false, 'err' => 'SendGrid not configured (SENDGRID_API_KEY)'));
			exit;
		}
		$res = sg_sync_all($start, $end);
		echo json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	if ($action === 'messages') {
		$q = sg_query_messages($in);
		$caps = sg_capabilities();
		$avail = isset($caps['/v3/messages']['available']) ? !empty($caps['/v3/messages']['available']) : null;
		echo json_encode(array(
			'ok' => true,
			'messages_available' => $avail,
			'messages_truncated' => sg_meta_get('messages_truncated_warning', '0') === '1',
			'result' => $q,
			'note' => $avail === false
				? 'Email Activity API unavailable for this account — showing only locally stored rows (if any).'
				: 'Rows are from local DB after API sync (polling).'
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	// default: summary from local DB
	$dash = sg_dashboard_payload($start, $end);
	echo json_encode($dash, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(array('ok' => false, 'err' => 'Sync error'));
	sendgrid_log('error', 'sync endpoint exception', array('type' => get_class($e), 'message' => $e->getMessage()));
}
