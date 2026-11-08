<?php
/**
 * Historical sync of Brevo transactional recipient/event data.
 * POST JSON: { "text": "#ACCOUNT…", "days": 90, "startDate":"", "endDate":"", "weekly_export": true, "public_base_url":"" }
 *
 * Coverage (transactional):
 * - GET /v3/smtp/statistics/events — max 90 days, paginated
 * - Subject enrichment via GET /v3/smtp/emails (requires messageId/email/templateId; max 1 month per request)
 * - Optional POST /v3/webhooks/export (past 7 days only; max 20 jobs / 7 days)
 *
 * Data older than 90 days, or subjects not present in events/webhooks/local sends, cannot be recovered from Brevo.
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL);
@set_time_limit(600);
@ini_set('max_execution_time', '600');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_analytics_lib.php';

try {
	$in = ba_require_post_json();

	$text = isset($in['text']) ? (string)$in['text'] : '';
	$accounts = array();
	if (!empty($in['key'])) {
		$accounts[] = array('key' => trim((string)$in['key']), 'from' => '', 'name' => '', 'limit' => 300);
	} else {
		$accounts = ba_parse_accounts($text);
	}
	if (empty($accounts)) {
		echo json_encode(array('ok' => false, 'err' => 'No API keys found'));
		exit;
	}

	$days = isset($in['days']) ? intval($in['days']) : 90;
	if ($days < 1) $days = 1;
	if ($days > 90) $days = 90;

	$startDate = '';
	$endDate = '';
	if (!empty($in['startDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['startDate'])) $startDate = (string)$in['startDate'];
	if (!empty($in['endDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['endDate'])) $endDate = (string)$in['endDate'];
	if ($startDate === '' || $endDate === '') {
		$endDate = gmdate('Y-m-d');
		$startDate = gmdate('Y-m-d', strtotime('-'.($days - 1).' days'));
	}
	if (strcmp($startDate, $endDate) > 0) {
		$tmp = $startDate; $startDate = $endDate; $endDate = $tmp;
	}
	$span = intval(round((strtotime($endDate) - strtotime($startDate)) / 86400)) + 1;
	if ($span > 90) {
		echo json_encode(array('ok' => false, 'err' => 'Date range cannot exceed 90 days (Brevo events API limit)'));
		exit;
	}

	$wantWeekly = !empty($in['weekly_export']);
	$cfg = ba_config_load();
	if (!empty($in['public_base_url'])) {
		$cfg['public_base_url'] = rtrim((string)$in['public_base_url'], '/');
		ba_config_save($cfg);
	}

	$pdo = ba_db();
	$accountResults = array();
	$totals = array('retrieved' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0);

	foreach ($accounts as $i => $acc) {
		$key = $acc['key'];
		$hash = ba_key_hash($key);
		$pdo->prepare("INSERT INTO ba_sync_runs (account_key_hash, sync_type, status, start_date, end_date, message)
			VALUES (?,?,?,?,?,?)")->execute(array($hash, 'events_90d', 'running', $startDate, $endDate, 'Syncing transactional events'));
		$runId = intval($pdo->lastInsertId());

		$stats = ba_sync_events_for_account($key, $startDate, $endDate, $runId);
		$weekly = null;

		if ($wantWeekly) {
			$base = $cfg['public_base_url'];
			if ($base !== '' && preg_match('#^https://#i', $base)) {
				$notify = $base.'/brevo_export_callback.php?token='.rawurlencode($cfg['webhook_token']).'&akh='.rawurlencode($hash);
				$ex = ba_create_weekly_export($key, $notify);
				if ($ex['ok'] && !empty($ex['data']['processId'])) {
					$pid = (string)$ex['data']['processId'];
					$pdo->prepare("INSERT OR IGNORE INTO ba_export_jobs (account_key_hash, process_id, export_type, status, notify_url)
						VALUES (?,?,?,?,?)")->execute(array($hash, $pid, 'transactional', 'queued', $notify));
					$weekly = array('ok' => true, 'processId' => $pid, 'notifyURL' => $notify);
					// Poll briefly for early completion (non-blocking long wait)
					for ($p = 0; $p < 3; $p++) {
						usleep(800000);
						$pr = ba_get_process($key, $pid);
						if ($pr['ok'] && !empty($pr['data']['status'])) {
							$st = strtolower((string)$pr['data']['status']);
							$pdo->prepare("UPDATE ba_export_jobs SET status=?, export_url=?, updated_at=datetime('now') WHERE process_id=? AND account_key_hash=?")
								->execute(array($st, isset($pr['data']['export_url']) ? $pr['data']['export_url'] : '', $pid, $hash));
							if ($st === 'completed' && !empty($pr['data']['export_url'])) {
								$dest = BA_DATA_DIR.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'weekly_'.$pid.'.bin';
								$dl = ba_http_download($pr['data']['export_url'], $dest);
								if ($dl['ok']) {
									$imp = ba_import_export_file($key, $dest, true);
									$stats['retrieved'] += $imp['retrieved'];
									$stats['imported'] += $imp['imported'];
									$stats['skipped'] += $imp['skipped'];
									$stats['failed'] += $imp['failed'];
									$pdo->prepare("UPDATE ba_export_jobs SET imported_count=?, status='imported', updated_at=datetime('now') WHERE process_id=?")
										->execute(array($imp['imported'], $pid));
									$weekly['imported'] = $imp;
									@unlink($dest);
								}
								break;
							}
							if (in_array($st, array('completed', 'failed', 'error'), true)) break;
						}
					}
				} else {
					$weekly = array('ok' => false, 'err' => isset($ex['err']) ? $ex['err'] : 'export create failed');
				}
			} else {
				$weekly = array('ok' => false, 'err' => 'weekly_export skipped — set a public HTTPS public_base_url first');
			}
		}

		$status = ($stats['err'] === '') ? 'ok' : 'partial';
		$pdo->prepare("UPDATE ba_sync_runs SET status=?, total_retrieved=?, new_imported=?, existing_skipped=?, failed=?, message=?, finished_at=datetime('now') WHERE id=?")
			->execute(array(
				$status,
				$stats['retrieved'],
				$stats['imported'],
				$stats['skipped'],
				$stats['failed'],
				$stats['err'] !== '' ? $stats['err'] : 'OK',
				$runId
			));

		$totals['retrieved'] += $stats['retrieved'];
		$totals['imported'] += $stats['imported'];
		$totals['skipped'] += $stats['skipped'];
		$totals['failed'] += $stats['failed'];

		$accountResults[] = array(
			'index' => $i + 1,
			'key_preview' => ba_key_preview($key),
			'from' => isset($acc['from']) ? $acc['from'] : '',
			'stats' => $stats,
			'weekly_export' => $weekly,
			'sync_run_id' => $runId
		);
	}

	$cfg['last_sync'] = array(
		'at' => ba_utc_now(),
		'start_date' => $startDate,
		'end_date' => $endDate,
		'totals' => $totals
	);
	ba_config_save($cfg);

	echo json_encode(array(
		'ok' => true,
		'provider' => 'transactional',
		'date_range' => array('start' => $startDate, 'end' => $endDate, 'days' => $span),
		'totals' => $totals,
		'accounts' => $accountResults,
		'limitations' => array(
			'events_api' => 'GET /v3/smtp/statistics/events covers at most 90 days.',
			'subjects' => 'Events may omit subject; enrichment uses /v3/smtp/emails (needs messageId/email/templateId) and local send records / webhooks / weekly export CSV.',
			'weekly_export' => 'POST /v3/webhooks/export covers only the past 7 days; max 20 jobs per 7-day period; requires public HTTPS notify URL.',
			'older_than_90_days' => 'Recipient-level events older than 90 days cannot be retrieved from this API.',
			'marketing' => 'This mailer sends via POST /v3/smtp/email (transactional). Marketing campaign recipient export APIs are not applicable to these sends.'
		),
		'sync' => ba_last_sync_info()
	), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
	echo json_encode(array('ok' => false, 'err' => $e->getMessage()));
}
