<?php
/**
 * SendGridMail — multi-country delivered eligibility (read-only).
 *
 * Positive signal: sg_messages.status = delivered
 * Country: intersection with SendGrid api-lane queue files (not domain suffixes).
 * Consent: unsubscribe / spam complaint / global suppressions always win over historical delivered.
 * mailssenddone / sg_local_sends alone never prove delivery.
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'queue_country.php';

function sgm_email_valid($email)
{
	$email = strtolower(trim((string)$email));
	if ($email === '' || strpos($email, '@') === false) return false;
	// Practical syntax check — not a full RFC parser.
	return (bool)preg_match('/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/i', $email);
}

function sgm_load_unique_file($path, &$meta = null)
{
	$meta = array(
		'file' => basename((string)$path),
		'exists' => is_file($path),
		'raw_lines' => 0,
		'raw_emails' => 0,
		'unique' => 0,
		'duplicates' => 0,
		'invalid' => 0
	);
	$out = array();
	$seen = array();
	if (!$meta['exists']) return $out;
	$fh = @fopen($path, 'r');
	if (!$fh) return $out;
	while (($line = fgets($fh)) !== false) {
		$meta['raw_lines']++;
		$e = strtolower(trim((string)$line));
		if ($e === '') continue;
		if (!sgm_email_valid($e)) {
			$meta['invalid']++;
			continue;
		}
		$meta['raw_emails']++;
		if (isset($seen[$e])) {
			$meta['duplicates']++;
			continue;
		}
		$seen[$e] = true;
		$out[] = $e;
	}
	fclose($fh);
	$meta['unique'] = count($out);
	return $out;
}

/**
 * Discover 2-letter countries from api-lane queue filenames + always include pt.
 * Optional extra codes (e.g. from UI) are merged after normalization.
 *
 * @param string $root
 * @param array $extraCodes
 * @return array list of ['code'=>,'valid_file'=>,'done_file'=>,'has_valid'=>,'has_done'=>]
 */
function sgm_discover_countries($root, array $extraCodes = array())
{
	$root = rtrim((string)$root, "\\/");
	$codes = array('pt' => true);
	foreach ($extraCodes as $c) {
		$cc = queue_country_code($c);
		// queue_country_code falls back to pt for invalid — only accept if raw was 2 letters
		$raw = strtolower(preg_replace('/[^a-z]/', '', (string)$c));
		if (strlen($raw) === 2) $codes[$raw] = true;
	}
	foreach (glob($root.DIRECTORY_SEPARATOR.'validmails_*.txt') ?: array() as $path) {
		$base = basename($path);
		// Skip smtp / kumomta lanes
		if (strpos($base, 'validmails_smtp') === 0) continue;
		if (strpos($base, 'validmails_kumomta') === 0) continue;
		if (preg_match('/^validmails_([a-z]{2})\.txt$/', $base, $m)) {
			$codes[$m[1]] = true;
		}
	}
	foreach (glob($root.DIRECTORY_SEPARATOR.'mailssenddone_*.txt') ?: array() as $path) {
		$base = basename($path);
		if (strpos($base, 'mailssenddone_smtp') === 0) continue;
		if (strpos($base, 'mailssenddone_kumomta') === 0) continue;
		if (preg_match('/^mailssenddone_([a-z]{2})\.txt$/', $base, $m)) {
			$codes[$m[1]] = true;
		}
	}
	$list = array_keys($codes);
	sort($list);
	// Keep pt first for UX
	usort($list, function ($a, $b) {
		if ($a === 'pt') return -1;
		if ($b === 'pt') return 1;
		return strcmp($a, $b);
	});
	$out = array();
	foreach ($list as $cc) {
		$names = queue_country_files('api', $cc);
		$out[] = array(
			'code' => $cc,
			'valid_file' => $names['valid'],
			'done_file' => $names['done'],
			'has_valid' => is_file($root.DIRECTORY_SEPARATOR.$names['valid']),
			'has_done' => is_file($root.DIRECTORY_SEPARATOR.$names['done'])
		);
	}
	return $out;
}

function sgm_country_membership($root, $country)
{
	$country = queue_country_code($country);
	$names = queue_country_files('api', $country);
	$validMeta = array();
	$doneMeta = array();
	$valid = sgm_load_unique_file($root.DIRECTORY_SEPARATOR.$names['valid'], $validMeta);
	$done = sgm_load_unique_file($root.DIRECTORY_SEPARATOR.$names['done'], $doneMeta);
	$set = array();
	foreach ($valid as $e) $set[$e] = true;
	foreach ($done as $e) $set[$e] = true;
	return array(
		'country' => $country,
		'files' => array(
			'valid' => $validMeta,
			'done' => $doneMeta,
			'valid_name' => $names['valid'],
			'done_name' => $names['done']
		),
		'membership' => array_keys($set),
		'in_valid' => array_fill_keys($valid, true),
		'in_done' => array_fill_keys($done, true)
	);
}

/**
 * Load SendGrid message + suppression evidence keyed by email.
 * @return array{messages:array,suppressions:array,truncated:bool,db_ok:bool,err:string}
 */
function sgm_load_sendgrid_evidence($pdo = null)
{
	$out = array(
		'messages' => array(), // email => list of {status, last_event_time}
		'suppressions' => array(), // email => list of types
		'truncated' => false,
		'db_ok' => false,
		'err' => ''
	);
	if ($pdo === null) {
		$lib = __DIR__.DIRECTORY_SEPARATOR.'sendgrid_analytics_lib.php';
		if (!is_file($lib)) {
			$out['err'] = 'sendgrid_analytics_lib.php missing';
			return $out;
		}
		require_once $lib;
		try {
			$pdo = sg_db();
		} catch (Exception $e) {
			$out['err'] = $e->getMessage();
			return $out;
		}
		if (function_exists('sg_meta_get')) {
			$out['truncated'] = sg_meta_get('messages_truncated_warning', '0') === '1';
		}
	}
	$out['db_ok'] = true;
	try {
		$st = $pdo->query('SELECT lower(to_email) AS email, status, last_event_time FROM sg_messages');
		while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
			$email = strtolower(trim((string)$row['email']));
			if ($email === '' || !sgm_email_valid($email)) continue;
			if (!isset($out['messages'][$email])) $out['messages'][$email] = array();
			$out['messages'][$email][] = array(
				'status' => strtolower(trim((string)$row['status'])),
				'last_event_time' => (string)$row['last_event_time']
			);
		}
	} catch (Exception $e) {
		$out['err'] = $e->getMessage();
		return $out;
	}
	try {
		$st = $pdo->query('SELECT lower(email) AS email, lower(suppression_type) AS t FROM sg_suppressions');
		while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
			$email = strtolower(trim((string)$row['email']));
			if ($email === '') continue;
			if (!isset($out['suppressions'][$email])) $out['suppressions'][$email] = array();
			$out['suppressions'][$email][] = (string)$row['t'];
		}
	} catch (Exception $e) {
		// suppressions optional if table missing
	}
	return $out;
}

function sgm_latest_message_status(array $msgs)
{
	if (!$msgs) return array('status' => '', 'last_event_time' => '');
	$best = $msgs[0];
	foreach ($msgs as $m) {
		$t = isset($m['last_event_time']) ? (string)$m['last_event_time'] : '';
		$bt = isset($best['last_event_time']) ? (string)$best['last_event_time'] : '';
		if ($t !== '' && ($bt === '' || strcmp($t, $bt) > 0)) $best = $m;
	}
	return $best;
}

function sgm_has_status(array $msgs, $want)
{
	$want = strtolower((string)$want);
	foreach ($msgs as $m) {
		if (isset($m['status']) && $m['status'] === $want) return true;
	}
	return false;
}

function sgm_delivered_then_negative(array $msgs)
{
	$deliveredTimes = array();
	$negativeTimes = array();
	foreach ($msgs as $m) {
		$st = isset($m['status']) ? $m['status'] : '';
		$t = isset($m['last_event_time']) ? (string)$m['last_event_time'] : '';
		if ($st === 'delivered') $deliveredTimes[] = $t;
		if ($st === 'not_delivered' || $st === 'bounce' || $st === 'blocked' || $st === 'dropped' || $st === 'deferred') {
			$negativeTimes[] = $t;
		}
	}
	if (!$deliveredTimes || !$negativeTimes) return false;
	$maxD = '';
	foreach ($deliveredTimes as $t) if ($t !== '' && ($maxD === '' || strcmp($t, $maxD) > 0)) $maxD = $t;
	foreach ($negativeTimes as $t) {
		if ($t === '') return true; // unknown order → conservative exclude
		if ($maxD === '' || strcmp($t, $maxD) >= 0) return true;
	}
	return false;
}

/**
 * Classify one email. Consent/suppression always overrides historical delivered.
 *
 * @return array{bucket:string,reason:string,eligible:bool,provider_status:string,last_event:string}
 */
function sgm_classify_email($email, array $evidence, array $opts = array())
{
	$email = strtolower(trim((string)$email));
	$result = array(
		'bucket' => 'excluded_unknown',
		'reason' => 'no_delivery_evidence',
		'eligible' => false,
		'provider_status' => '',
		'last_event' => ''
	);
	if (!sgm_email_valid($email)) {
		$result['reason'] = 'invalid_syntax';
		return $result;
	}

	$msgs = isset($evidence['messages'][$email]) ? $evidence['messages'][$email] : array();
	$supps = isset($evidence['suppressions'][$email]) ? $evidence['suppressions'][$email] : array();
	$latest = sgm_latest_message_status($msgs);
	$result['provider_status'] = isset($latest['status']) ? $latest['status'] : '';
	$result['last_event'] = isset($latest['last_event_time']) ? $latest['last_event_time'] : '';

	// 1) Global suppressions / consent — never overridden by historical delivered
	if (!empty($opts['check_global_suppression'])) {
		if (!function_exists('suppression_is_blocked')) {
			$suppLib = __DIR__.DIRECTORY_SEPARATOR.'suppression_lib.php';
			if (is_file($suppLib)) require_once $suppLib;
		}
		if (function_exists('suppression_is_blocked')) {
			$gate = suppression_is_blocked($email, array(
				'lane' => isset($opts['lane']) ? $opts['lane'] : 'api',
				'country' => isset($opts['country']) ? $opts['country'] : 'pt'
			));
			if (!empty($gate['blocked'])) {
				$reason = isset($gate['reason']) ? (string)$gate['reason'] : 'suppressed';
				if ($reason === 'unsubscribe' || $reason === 'complaint') {
					$result['bucket'] = 'excluded_unknown';
					$result['reason'] = 'consent_'.$reason;
					return $result;
				}
				if ($reason === 'hard_bounce' || $reason === 'soft_bounce') {
					$result['bucket'] = 'bounced';
					$result['reason'] = 'global_'.$reason;
					return $result;
				}
				if ($reason === 'block' || $reason === 'invalid') {
					$result['bucket'] = 'blocked_dropped';
					$result['reason'] = 'global_'.$reason;
					return $result;
				}
				$result['bucket'] = 'excluded_unknown';
				$result['reason'] = 'global_'.$reason;
				return $result;
			}
		}
	}

	// 2) SendGrid suppression lists — consent/spam first
	$suppSet = array();
	foreach ($supps as $t) $suppSet[$t] = true;
	if (isset($suppSet['unsubscribe']) || isset($suppSet['spamreport'])) {
		$result['bucket'] = 'excluded_unknown';
		$result['reason'] = isset($suppSet['unsubscribe']) ? 'sg_unsubscribe' : 'sg_spamreport';
		return $result;
	}
	if (isset($suppSet['bounce'])) {
		$result['bucket'] = 'bounced';
		$result['reason'] = 'sg_bounce';
		return $result;
	}
	if (isset($suppSet['block']) || isset($suppSet['invalid'])) {
		$result['bucket'] = 'blocked_dropped';
		$result['reason'] = isset($suppSet['block']) ? 'sg_block' : 'sg_invalid';
		return $result;
	}

	// 3) Message history
	if (!$msgs) {
		$result['reason'] = 'no_delivery_evidence';
		return $result;
	}

	$hasDelivered = sgm_has_status($msgs, 'delivered');
	if ($hasDelivered && sgm_delivered_then_negative($msgs)) {
		$result['bucket'] = 'excluded_unknown';
		$result['reason'] = 'conflicting_events';
		return $result;
	}

	$latestStatus = isset($latest['status']) ? $latest['status'] : '';
	if ($latestStatus === 'not_delivered' || $latestStatus === 'bounce') {
		$result['bucket'] = 'bounced';
		$result['reason'] = 'status_'.$latestStatus;
		return $result;
	}
	if ($latestStatus === 'blocked' || $latestStatus === 'dropped') {
		$result['bucket'] = 'blocked_dropped';
		$result['reason'] = 'status_'.$latestStatus;
		return $result;
	}
	if ($latestStatus === 'deferred' || $latestStatus === 'processed' || $latestStatus === '') {
		if (!$hasDelivered) {
			$result['bucket'] = 'excluded_unknown';
			$result['reason'] = $latestStatus === '' ? 'unknown_status' : 'status_'.$latestStatus;
			return $result;
		}
	}

	if ($hasDelivered && $latestStatus === 'delivered') {
		$result['bucket'] = 'delivered_eligible';
		$result['reason'] = 'sg_delivered';
		$result['eligible'] = true;
		return $result;
	}
	if ($hasDelivered && ($latestStatus === 'delivered' || $latestStatus === 'processed' || $latestStatus === '')) {
		// delivered exists and latest is not a negative — still require latest not unknown empty with only processed?
		// Conservative: only eligible when latest is delivered (or all statuses delivered).
		$allDelivered = true;
		foreach ($msgs as $m) {
			if (!isset($m['status']) || $m['status'] !== 'delivered') { $allDelivered = false; break; }
		}
		if ($allDelivered || $latestStatus === 'delivered') {
			$result['bucket'] = 'delivered_eligible';
			$result['reason'] = 'sg_delivered';
			$result['eligible'] = true;
			return $result;
		}
	}

	$result['bucket'] = 'excluded_unknown';
	$result['reason'] = $latestStatus !== '' ? 'status_'.$latestStatus : 'unknown_status';
	return $result;
}

/**
 * Build classified dataset for one country (api lane only).
 */
function sgm_build_country_dataset($root, $country, array $evidence = null, array $opts = array())
{
	$country = queue_country_code($country);
	$mem = sgm_country_membership($root, $country);
	if ($evidence === null) $evidence = sgm_load_sendgrid_evidence();

	$opts = array_merge(array(
		'check_global_suppression' => true,
		'lane' => 'api',
		'country' => $country
	), $opts);

	$stats = array(
		'delivered_eligible' => 0,
		'bounced' => 0,
		'blocked_dropped' => 0,
		'excluded_unknown' => 0,
		'membership_unique' => count($mem['membership'])
	);
	$reasonCounts = array();
	$rows = array();

	foreach ($mem['membership'] as $email) {
		$cls = sgm_classify_email($email, $evidence, $opts);
		$bucket = $cls['bucket'];
		if (!isset($stats[$bucket])) $stats[$bucket] = 0;
		$stats[$bucket]++;
		$reason = $cls['reason'];
		if (!isset($reasonCounts[$reason])) $reasonCounts[$reason] = 0;
		$reasonCounts[$reason]++;

		$rows[] = array(
			'email' => $email,
			'country' => $country,
			'lane' => 'api',
			'bucket' => $bucket,
			'reason' => $reason,
			'eligible' => !empty($cls['eligible']),
			'provider_status' => $cls['provider_status'],
			'last_event' => $cls['last_event'],
			'in_valid' => !empty($mem['in_valid'][$email]),
			'in_done' => !empty($mem['in_done'][$email])
		);
	}

	$notes = array();
	$notes[] = 'Eligible = sg_messages.status delivered after consent/suppression checks. mailssenddone alone is not delivery.';
	$notes[] = 'Country from api-lane queue files only — not email domain suffixes. SMTP/Kumo lanes excluded.';
	$notes[] = 'Unsubscribe, spam complaint, and global suppressions always override historical delivered.';
	if (!$mem['files']['valid']['exists'] && !$mem['files']['done']['exists']) {
		$notes[] = 'WARNING: No api queue files for country '.$country.' — membership empty; cannot attribute delivered recipients to this country.';
	}
	if (empty($evidence['db_ok'])) {
		$notes[] = 'WARNING: SendGrid analytics DB unavailable — '.$evidence['err'];
	}
	if (!empty($evidence['truncated'])) {
		$notes[] = 'WARNING: Email Activity sync reported truncation (≤1000/query) — delivered set may be incomplete.';
	}

	return array(
		'ok' => true,
		'country' => $country,
		'lane' => 'api',
		'method' => 'sendgrid',
		'files' => $mem['files'],
		'stats' => $stats,
		'reason_counts' => $reasonCounts,
		'notes' => $notes,
		'evidence' => array(
			'db_ok' => !empty($evidence['db_ok']),
			'truncated' => !empty($evidence['truncated']),
			'err' => isset($evidence['err']) ? $evidence['err'] : ''
		),
		'delivery_source' => 'sg_messages.status=delivered (SendGrid Email Activity sync) excluding suppressions/conflicts',
		'rows' => $rows
	);
}

function sgm_filter_rows(array $rows, $q, $bucket)
{
	$q = strtolower(trim((string)$q));
	$bucket = strtolower(trim((string)$bucket));
	if ($bucket === '' || $bucket === 'all') $bucket = 'all';
	if ($bucket === 'eligible' || $bucket === 'delivered') $bucket = 'delivered_eligible';
	$out = array();
	foreach ($rows as $row) {
		if ($bucket !== 'all' && $row['bucket'] !== $bucket) continue;
		if ($q !== '' && strpos($row['email'], $q) === false) continue;
		$out[] = $row;
	}
	return $out;
}

function sgm_eligible_emails(array $rows)
{
	$out = array();
	$seen = array();
	foreach ($rows as $row) {
		if (empty($row['eligible'])) continue;
		$e = strtolower(trim((string)$row['email']));
		if ($e === '' || isset($seen[$e]) || !sgm_email_valid($e)) continue;
		$seen[$e] = true;
		$out[] = $e;
	}
	return $out;
}

function sgm_txt_body(array $emails)
{
	return implode("\n", $emails).(count($emails) ? "\n" : '');
}
