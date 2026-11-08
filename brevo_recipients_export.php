<?php
/**
 * One-click CSV export of filtered recipient-level analytics.
 * POST (form or JSON) with the same filters as brevo_recipients.php.
 * Exports ALL matching rows (not just the current page).
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL);
@set_time_limit(300);

require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_analytics_lib.php';

try {
	$in = array();
	if ($_SERVER['REQUEST_METHOD'] === 'POST') {
		$raw = file_get_contents('php://input');
		$j = json_decode($raw, true);
		if (is_array($j)) $in = $j;
		else $in = $_POST;
	} else {
		$in = $_GET;
	}

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
		'account_key_hash' => isset($in['account_key_hash']) ? $in['account_key_hash'] : '',
		'account_index' => isset($in['account_index']) ? $in['account_index'] : ''
	);

	// Preset shortcuts
	$preset = isset($in['preset']) ? (string)$in['preset'] : '';
	if ($preset === 'delivered') $filters['status'] = 'delivered';
	else if ($preset === 'hardBounce') $filters['status'] = 'hardBounce';
	else if ($preset === 'softBounce') $filters['status'] = 'softBounce';
	else if ($preset === 'bounced') $filters['status'] = 'bounced';

	$statusPart = $filters['status'] !== '' && $filters['status'] !== 'all' ? $filters['status'] : 'all';
	$fname = 'brevo_recipients_'.$statusPart.'_'.gmdate('Ymd_His').'.csv';

	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="'.$fname.'"');
	header('Cache-Control: no-store');
	header('Pragma: no-cache');

	ba_export_csv($filters);
} catch (Exception $e) {
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('ok' => false, 'err' => $e->getMessage()));
}
