<?php
/**
 * Historical sync — ALL configured Brevo transactional accounts.
 *
 * Batch API (recommended for 10 accounts / large histories):
 *   POST { action:"start", text:"#ACCOUNT…", days:90 }
 *   POST { action:"step", text:"#ACCOUNT…", account_index:1, days:90, resume_offset:0, ... }
 *   POST { action:"probe", text:"#ACCOUNT…", days:90 }
 *   POST { action:"summary" }
 *
 * Legacy one-shot still accepted without action (processes all accounts sequentially).
 *
 * Does NOT require webhooks or a public HTTPS URL.
 * Weekly export is optional and skipped unless public_base_url is provided.
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL);
@set_time_limit(180);
@ini_set('max_execution_time', '180');
@ini_set('memory_limit', '512M');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_analytics_lib.php';

function ba_sync_parse_in($in)
{
	$text = isset($in['text']) ? (string)$in['text'] : '';
	$accounts = array();
	if (!empty($in['key'])) {
		$accounts[] = array('key' => trim((string)$in['key']), 'from' => isset($in['from']) ? trim((string)$in['from']) : '', 'name' => '', 'limit' => 300);
	} else if (!empty($in['keys']) && is_array($in['keys'])) {
		foreach ($in['keys'] as $k) {
			$k = trim((string)$k);
			if ($k !== '') $accounts[] = array('key' => $k, 'from' => '', 'name' => '', 'limit' => 300);
		}
	} else {
		$accounts = ba_parse_accounts($text);
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
	return array($accounts, $startDate, $endDate, $span, $days);
}

function ba_sync_public_account_row($i, $acc)
{
	$from = isset($acc['from']) ? $acc['from'] : '';
	$label = 'Acct '.($i + 1);
	if ($from !== '') $label .= ' · '.$from;
	return array(
		'index' => $i + 1,
		'label' => $label,
		'from' => $from,
		'key_preview' => ba_key_preview($acc['key'])
	);
}

try {
	$in = ba_require_post_json();
	$action = isset($in['action']) ? (string)$in['action'] : '';

	if ($action === 'summary') {
		$counts = ba_status_counts(array());
		echo json_encode(array(
			'ok' => true,
			'counts' => $counts,
			'accounts' => ba_list_accounts_meta(),
			'subjects_unavailable' => ba_subject_unavailable_count(),
			'sync' => ba_last_sync_info()
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	list($accounts, $startDate, $endDate, $span, $days) = ba_sync_parse_in($in);
	if ($span > 90) {
		echo json_encode(array('ok' => false, 'err' => 'Date range cannot exceed 90 days (Brevo events API limit)'));
		exit;
	}
	if (empty($accounts) && $action !== 'summary') {
		echo json_encode(array('ok' => false, 'err' => 'No API keys found — paste the same #ACCOUNT blocks used by aggregate analytics'));
		exit;
	}

	// ----- START: register job + return account list (no heavy API work) -----
	if ($action === 'start') {
		$pdo = ba_db();
		$token = bin2hex(random_bytes(12));
		$pubAccounts = array();
		foreach ($accounts as $i => $acc) {
			$pubAccounts[] = ba_sync_public_account_row($i, $acc);
			ba_upsert_account_meta(ba_key_hash($acc['key']), $i + 1, $pubAccounts[$i]['label'], $acc['from'], ba_key_preview($acc['key']));
		}
		$pdo->prepare("INSERT INTO ba_sync_jobs (job_token, status, start_date, end_date, account_count, current_account_index, accounts_json, progress_json, totals_json)
			VALUES (?,?,?,?,?,?,?,?,?)")->execute(array(
			$token, 'running', $startDate, $endDate, count($accounts), 0,
			json_encode($pubAccounts),
			json_encode(array()),
			json_encode(array('retrieved' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0))
		));
		echo json_encode(array(
			'ok' => true,
			'action' => 'start',
			'job_token' => $token,
			'account_count' => count($accounts),
			'accounts' => $pubAccounts,
			'date_range' => array('start' => $startDate, 'end' => $endDate, 'days' => $span),
			'note' => 'Call action=step for each account_index 1..N. Historical sync does not require webhooks.'
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	// ----- PROBE: verify events API returns data for each account (lightweight) -----
	if ($action === 'probe') {
		$results = array();
		foreach ($accounts as $i => $acc) {
			$row = ba_sync_public_account_row($i, $acc);
			$probe = ba_probe_account_events($acc['key'], $startDate, $endDate);
			$row['probe'] = $probe;
			$results[] = $row;
		}
		echo json_encode(array(
			'ok' => true,
			'action' => 'probe',
			'date_range' => array('start' => $startDate, 'end' => $endDate, 'days' => $span),
			'accounts' => $results
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	// ----- STEP: sync ONE account (optionally resume pagination) -----
	if ($action === 'step' || $action === 'sync_one') {
		$idx = isset($in['account_index']) ? intval($in['account_index']) : 0; // 1-based
		if ($idx < 1 || $idx > count($accounts)) {
			echo json_encode(array('ok' => false, 'err' => 'account_index out of range (1..'.count($accounts).')'));
			exit;
		}
		$acc = $accounts[$idx - 1];
		$key = $acc['key'];
		$hash = ba_key_hash($key);
		$label = ba_sync_public_account_row($idx - 1, $acc)['label'];
		$pdo = ba_db();

		$resumeOffset = isset($in['resume_offset']) ? max(0, intval($in['resume_offset'])) : 0;
		$maxPages = isset($in['max_pages']) ? max(1, min(80, intval($in['max_pages']))) : 20;
		$retrievedSoFar = isset($in['retrieved_so_far']) ? intval($in['retrieved_so_far']) : 0;
		$importedSoFar = isset($in['imported_so_far']) ? intval($in['imported_so_far']) : 0;
		$skippedSoFar = isset($in['skipped_so_far']) ? intval($in['skipped_so_far']) : 0;
		$failedSoFar = isset($in['failed_so_far']) ? intval($in['failed_so_far']) : 0;

		$runId = 0;
		if ($resumeOffset === 0) {
			$pdo->prepare("INSERT INTO ba_sync_runs (account_key_hash, account_index, account_label, sync_type, status, start_date, end_date, message)
				VALUES (?,?,?,?,?,?,?,?)")->execute(array(
				$hash, $idx, $label, 'events_batch', 'running', $startDate, $endDate, 'Syncing account '.$idx
			));
			$runId = intval($pdo->lastInsertId());
		} else if (!empty($in['sync_run_id'])) {
			$runId = intval($in['sync_run_id']);
		}

		$enrichLimit = !empty($in['skip_enrich']) ? 0 : 400;
		$stats = ba_sync_events_for_account($key, $startDate, $endDate, $runId, array(
			'account_index' => $idx,
			'account_label' => $label,
			'from' => isset($acc['from']) ? $acc['from'] : '',
			'resume_offset' => $resumeOffset,
			'max_pages' => $maxPages,
			'retrieved_so_far' => $retrievedSoFar,
			'imported_so_far' => $importedSoFar,
			'skipped_so_far' => $skippedSoFar,
			'failed_so_far' => $failedSoFar,
			'enrich_limit' => $enrichLimit
		));

		$status = 'ok';
		if ($stats['err'] !== '') $status = 'error';
		else if (!$stats['done']) $status = 'partial';

		if ($runId > 0 && ($stats['done'] || $stats['err'] !== '')) {
			$pdo->prepare("UPDATE ba_sync_runs SET status=?, total_retrieved=?, new_imported=?, existing_skipped=?, failed=?, resume_offset=?, message=?, finished_at=datetime('now') WHERE id=?")
				->execute(array(
					$status,
					$stats['retrieved'],
					$stats['imported'],
					$stats['skipped'],
					$stats['failed'],
					$stats['resume_offset'],
					$stats['err'] !== '' ? $stats['err'] : ($stats['done'] ? 'OK' : 'More pages remaining'),
					$runId
				));
		}

		// Optional weekly export only when public HTTPS URL is explicitly provided
		$weekly = null;
		if ($stats['done'] && $stats['err'] === '' && !empty($in['weekly_export']) && !empty($in['public_base_url']) && preg_match('#^https://#i', (string)$in['public_base_url'])) {
			$cfg = ba_config_load();
			$cfg['public_base_url'] = rtrim((string)$in['public_base_url'], '/');
			ba_config_save($cfg);
			$notify = $cfg['public_base_url'].'/brevo_export_callback.php?token='.rawurlencode($cfg['webhook_token']).'&akh='.rawurlencode($hash);
			$ex = ba_create_weekly_export($key, $notify);
			if ($ex['ok'] && !empty($ex['data']['processId'])) {
				$pid = (string)$ex['data']['processId'];
				$pdo->prepare("INSERT OR IGNORE INTO ba_export_jobs (account_key_hash, process_id, export_type, status, notify_url) VALUES (?,?,?,?,?)")
					->execute(array($hash, $pid, 'transactional', 'queued', $notify));
				$weekly = array('ok' => true, 'processId' => $pid);
				for ($p = 0; $p < 8; $p++) {
					usleep(700000);
					$pr = ba_get_process($key, $pid);
					if (!$pr['ok'] || empty($pr['data']['status'])) continue;
					$st = strtolower((string)$pr['data']['status']);
					if ($st === 'completed' && !empty($pr['data']['export_url'])) {
						$dest = BA_DATA_DIR.DIRECTORY_SEPARATOR.'tmp'.DIRECTORY_SEPARATOR.'weekly_'.$pid.'.bin';
						$dl = ba_http_download($pr['data']['export_url'], $dest);
						if ($dl['ok']) {
							$imp = ba_import_export_file($key, $dest, true);
							$stats['retrieved'] += $imp['retrieved'];
							$stats['imported'] += $imp['imported'];
							$stats['skipped'] += $imp['skipped'];
							$stats['failed'] += $imp['failed'];
							$weekly['imported'] = $imp;
							@unlink($dest);
						}
						break;
					}
					if (in_array($st, array('failed', 'error'), true)) {
						$weekly['ok'] = false;
						$weekly['err'] = 'process '.$st;
						break;
					}
				}
			} else {
				$weekly = array('ok' => false, 'err' => isset($ex['err']) ? $ex['err'] : 'export create failed');
			}
		}

		$cfg = ba_config_load();
		if ($stats['done'] || $stats['err'] !== '') {
			// Update rolling last_sync totals when an account finishes
			$prev = isset($cfg['last_sync']['totals']) && is_array($cfg['last_sync']['totals']) ? $cfg['last_sync']['totals'] : array('retrieved'=>0,'imported'=>0,'skipped'=>0,'failed'=>0);
			if ($resumeOffset === 0) {
				// add this account's final totals when finishing from start; for multi-step, client tracks
			}
			$cfg['last_sync'] = array(
				'at' => ba_utc_now(),
				'start_date' => $startDate,
				'end_date' => $endDate,
				'last_account_index' => $idx,
				'last_account_label' => $label,
				'last_account_stats' => array(
					'retrieved' => $stats['retrieved'],
					'imported' => $stats['imported'],
					'skipped' => $stats['skipped'],
					'failed' => $stats['failed'],
					'err' => $stats['err']
				)
			);
			ba_config_save($cfg);
		}

		echo json_encode(array(
			'ok' => ($stats['err'] === ''),
			'action' => 'step',
			'account_index' => $idx,
			'account_label' => $label,
			'key_preview' => ba_key_preview($key),
			'from' => isset($acc['from']) ? $acc['from'] : '',
			'date_range' => array('start' => $startDate, 'end' => $endDate, 'days' => $span),
			'stats' => $stats,
			'sync_run_id' => $runId,
			'continue' => ($stats['err'] === '' && empty($stats['done'])),
			'resume_offset' => $stats['resume_offset'],
			'weekly_export' => $weekly,
			'counts' => ba_status_counts(array()),
			'subjects_unavailable' => ba_subject_unavailable_count()
		), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		exit;
	}

	// ----- LEGACY: sync all accounts in one request (kept for compatibility; may timeout) -----
	$pdo = ba_db();
	$accountResults = array();
	$totals = array('retrieved' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0);
	$okN = 0;
	$failN = 0;

	foreach ($accounts as $i => $acc) {
		$key = $acc['key'];
		$hash = ba_key_hash($key);
		$label = ba_sync_public_account_row($i, $acc)['label'];
		$pdo->prepare("INSERT INTO ba_sync_runs (account_key_hash, account_index, account_label, sync_type, status, start_date, end_date, message)
			VALUES (?,?,?,?,?,?,?,?)")->execute(array($hash, $i + 1, $label, 'events_90d', 'running', $startDate, $endDate, 'Syncing'));
		$runId = intval($pdo->lastInsertId());

		$stats = ba_sync_events_for_account($key, $startDate, $endDate, $runId, array(
			'account_index' => $i + 1,
			'account_label' => $label,
			'from' => isset($acc['from']) ? $acc['from'] : '',
			'max_pages' => 80,
			'enrich_limit' => 300
		));

		$status = ($stats['err'] === '' && !empty($stats['done'])) ? 'ok' : (($stats['err'] !== '') ? 'error' : 'partial');
		if ($status === 'ok') $okN++; else $failN++;
		$pdo->prepare("UPDATE ba_sync_runs SET status=?, total_retrieved=?, new_imported=?, existing_skipped=?, failed=?, message=?, finished_at=datetime('now') WHERE id=?")
			->execute(array($status, $stats['retrieved'], $stats['imported'], $stats['skipped'], $stats['failed'], $stats['err'] !== '' ? $stats['err'] : 'OK', $runId));

		$totals['retrieved'] += $stats['retrieved'];
		$totals['imported'] += $stats['imported'];
		$totals['skipped'] += $stats['skipped'];
		$totals['failed'] += $stats['failed'];

		$accountResults[] = array(
			'index' => $i + 1,
			'label' => $label,
			'key_preview' => ba_key_preview($key),
			'from' => isset($acc['from']) ? $acc['from'] : '',
			'stats' => $stats,
			'sync_run_id' => $runId,
			'ok' => ($stats['err'] === '')
		);
	}

	$cfg = ba_config_load();
	$cfg['last_sync'] = array(
		'at' => ba_utc_now(),
		'start_date' => $startDate,
		'end_date' => $endDate,
		'totals' => $totals,
		'accounts_ok' => $okN,
		'accounts_failed' => $failN
	);
	ba_config_save($cfg);

	echo json_encode(array(
		'ok' => true,
		'provider' => 'transactional',
		'date_range' => array('start' => $startDate, 'end' => $endDate, 'days' => $span),
		'totals' => $totals,
		'accounts_ok' => $okN,
		'accounts_failed' => $failN,
		'accounts' => $accountResults,
		'counts' => ba_status_counts(array()),
		'subjects_unavailable' => ba_subject_unavailable_count(),
		'limitations' => array(
			'events_api' => 'GET /v3/smtp/statistics/events covers at most 90 days.',
			'subjects' => 'Subjects come from event payload, /v3/smtp/emails correlation, local sends, or weekly export CSV.',
			'weekly_export' => 'Optional. Requires public HTTPS notify URL. Not required for 90-day events sync.',
			'aggregate_vs_recipient' => 'Aggregate KPIs (aggregatedReport) are not the same as recipient event rows. Counts may differ.'
		),
		'sync' => ba_last_sync_info(),
		'hint' => 'Prefer action=start + action=step per account to avoid PHP timeouts with 10 accounts.'
	), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
	echo json_encode(array('ok' => false, 'err' => $e->getMessage()));
}
