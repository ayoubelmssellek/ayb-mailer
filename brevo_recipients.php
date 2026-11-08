<?php
/**
 * Recipient-level analytics API (local SQLite + Brevo transactional data).
 * POST JSON actions:
 *   list | stats | history | subjects | campaigns | sync_status | meta
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL);
@set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_analytics_lib.php';

try {
	$in = ba_require_post_json();
	$action = isset($in['action']) ? (string)$in['action'] : 'list';

	$filters = array(
		'status' => isset($in['status']) ? $in['status'] : 'all',
		'email' => isset($in['email']) ? $in['email'] : '',
		'email_exact' => isset($in['email_exact']) ? $in['email_exact'] : '',
		'subject' => isset($in['subject']) ? $in['subject'] : '',
		'subject_q' => isset($in['subject_q']) ? $in['subject_q'] : '',
		'campaign_id' => isset($in['campaign_id']) ? $in['campaign_id'] : '',
		'campaign_name' => isset($in['campaign_name']) ? $in['campaign_name'] : '',
		'start_date' => isset($in['start_date']) ? $in['start_date'] : '',
		'end_date' => isset($in['end_date']) ? $in['end_date'] : '',
		'account_key_hash' => isset($in['account_key_hash']) ? $in['account_key_hash'] : ''
	);

	if ($action === 'meta') {
		$cfg = ba_config_load();
		echo json_encode(array(
			'ok' => true,
			'provider' => 'transactional',
			'send_endpoint' => 'POST /v3/smtp/email',
			'status_filters' => ba_status_filters_supported(),
			'historical_limits' => array(
				'events_api_max_days' => 90,
				'email_logs_requires_filter' => true,
				'email_logs_max_range_days' => 30,
				'weekly_export_days' => 7,
				'weekly_export_max_jobs_per_7_days' => 20,
				'note' => 'Subjects older than webhook/export coverage may be (unavailable) unless correlatable via messageId + local send records or /v3/smtp/emails.'
			),
			'webhook_token_set' => ($cfg['webhook_token'] !== ''),
			'public_base_url' => $cfg['public_base_url'],
			'last_sync' => ba_last_sync_info()
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	if ($action === 'stats') {
		$counts = ba_status_counts($filters);
		$sync = ba_last_sync_info();
		echo json_encode(array(
			'ok' => true,
			'counts' => $counts,
			'sync' => $sync,
			'filters' => $filters
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	if ($action === 'history') {
		$email = isset($in['email']) ? trim((string)$in['email']) : '';
		if ($email === '' || strpos($email, '@') === false) {
			echo json_encode(array('ok' => false, 'err' => 'Valid email required'));
			exit;
		}
		$history = ba_recipient_history($email, isset($in['limit']) ? intval($in['limit']) : 200);
		echo json_encode(array(
			'ok' => true,
			'email' => strtolower($email),
			'count' => count($history),
			'messages' => $history
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	if ($action === 'subjects') {
		echo json_encode(array(
			'ok' => true,
			'subjects' => ba_list_subjects(isset($in['q']) ? (string)$in['q'] : '', 300)
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	if ($action === 'campaigns') {
		echo json_encode(array(
			'ok' => true,
			'campaigns' => ba_list_campaigns(300)
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	if ($action === 'sync_status') {
		echo json_encode(array('ok' => true, 'sync' => ba_last_sync_info()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	// default: list
	$page = isset($in['page']) ? intval($in['page']) : 1;
	$perPage = isset($in['per_page']) ? intval($in['per_page']) : 50;
	$result = ba_query_messages($filters, $page, $perPage);
	$counts = ba_status_counts($filters);
	echo json_encode(array(
		'ok' => true,
		'filters' => $filters,
		'counts' => $counts,
		'total' => $result['total'],
		'unique_emails' => $result['unique_emails'],
		'page' => $result['page'],
		'per_page' => $result['per_page'],
		'pages' => $result['pages'],
		'rows' => $result['rows'],
		'sync' => ba_last_sync_info()
	), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
	echo json_encode(array('ok' => false, 'err' => $e->getMessage()));
}
