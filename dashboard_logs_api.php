<?php
/**
 * Read-only centralized logs API for the dashboard Logs page.
 * Sources are allow-listed; content is redacted before JSON output.
 *
 * Actions: list | sources
 * Query: source, q, severity, page, per_page, country (best-effort)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'log_redact.php';
ayb_env_load();

function dlog_out(array $payload, $code = 200)
{
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function dlog_guess_severity($text)
{
	$t = strtolower((string)$text);
	if (strpos($t, 'emergency') !== false || strpos($t, 'fatal') !== false) return 'error';
	if (preg_match('/\b(fail|error|halt|denied|exception)\b/', $t)) return 'error';
	if (preg_match('/\b(warn|warning|cooldown|pending|skip)\b/', $t)) return 'warn';
	if (preg_match('/\b(ok|accepted|approved|delivered)\b/', $t)) return 'info';
	return 'info';
}

function dlog_parse_ts($line)
{
	// ISO or common log prefixes
	if (preg_match('/^(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $m)) return $m[1];
	if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\]/', $line, $m)) return $m[1];
	return '';
}

function dlog_read_provider_log($basename, $source, $needle, $limit = 500)
{
	$path = __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.$basename;
	$out = array();
	if (!is_file($path)) return $out;
	$lines = @file($path, FILE_IGNORE_NEW_LINES);
	if (!is_array($lines)) return $out;
	$needle = strtolower(trim((string)$needle));
	foreach ($lines as $line) {
		if ($needle !== '' && strpos(strtolower($line), $needle) === false) continue;
		$safe = ayb_log_redact_text($line);
		$out[] = array(
			'ts' => dlog_parse_ts($safe),
			'severity' => dlog_guess_severity($safe),
			'source' => $source,
			'event_type' => 'provider_log',
			'country' => '',
			'mailer' => $source === 'sendgrid' ? 'sendgrid' : 'kumomta',
			'server' => '',
			'campaign' => '',
			'details' => $safe
		);
	}
	// newest first
	return array_reverse($out);
}

function dlog_warmup_decisions($needle, $limit = 200)
{
	$out = array();
	$lib = __DIR__.DIRECTORY_SEPARATOR.'warmup_lib.php';
	if (!is_file($lib)) return $out;
	require_once $lib;
	if (!function_exists('warmup_status')) return $out;
	$st = warmup_status('kumomta');
	$rows = isset($st['decisions']) && is_array($st['decisions']) ? $st['decisions'] : array();
	$needle = strtolower(trim((string)$needle));
	foreach ($rows as $r) {
		$details = ayb_log_redact_text((isset($r['action']) ? $r['action'] : '').' — '.(isset($r['reason']) ? $r['reason'] : ''));
		$blob = strtolower($details.' '.(isset($r['actor']) ? $r['actor'] : ''));
		if ($needle !== '' && strpos($blob, $needle) === false) continue;
		$sev = 'info';
		$act = strtolower(isset($r['action']) ? $r['action'] : '');
		if (strpos($act, 'reject') !== false || strpos($act, 'stop') !== false || strpos($act, 'cooldown') !== false) $sev = 'warn';
		if (strpos($act, 'emergency') !== false) $sev = 'error';
		$out[] = array(
			'ts' => isset($r['created_at']) ? $r['created_at'] : '',
			'severity' => $sev,
			'source' => 'warmup',
			'event_type' => isset($r['action']) ? $r['action'] : 'decision',
			'country' => '',
			'mailer' => 'kumomta',
			'server' => '',
			'campaign' => '',
			'details' => $details.(isset($r['actor']) ? (' · actor '.$r['actor']) : '')
		);
	}
	return $out;
}

function dlog_warmup_events($needle, $limit = 200)
{
	$out = array();
	$lib = __DIR__.DIRECTORY_SEPARATOR.'warmup_lib.php';
	if (!is_file($lib)) return $out;
	require_once $lib;
	if (!function_exists('warmup_get_plan') || !function_exists('warmup_db')) return $out;
	try {
		$plan = warmup_get_plan('kumomta');
		$planId = intval($plan['id']);
		$limit = max(1, min(500, intval($limit)));
		$st = warmup_db()->prepare('SELECT id, email, event_type, created_at FROM warmup_send_events WHERE plan_id=? ORDER BY id DESC LIMIT '.$limit);
		$st->execute(array($planId));
		$needle = strtolower(trim((string)$needle));
		while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
			$email = isset($row['email']) ? (string)$row['email'] : '';
			// Mask local part lightly (not a secret, but avoid full dump noise)
			$masked = $email;
			if (strpos($email, '@') !== false) {
				list($local, $dom) = explode('@', $email, 2);
				$masked = (strlen($local) <= 2 ? '*' : substr($local, 0, 1).'***').'@'.$dom;
			}
			$etype = isset($row['event_type']) ? $row['event_type'] : 'event';
			$details = ayb_log_redact_text($etype.' · '.$masked);
			if ($needle !== '' && strpos(strtolower($details.' '.$etype), $needle) === false) continue;
			$sev = 'info';
			if (strpos($etype, 'fail') !== false || strpos($etype, 'hard') !== false) $sev = 'error';
			else if (strpos($etype, 'soft') !== false || strpos($etype, 'bounce') !== false) $sev = 'warn';
			$out[] = array(
				'ts' => isset($row['created_at']) ? $row['created_at'] : '',
				'severity' => $sev,
				'source' => 'warmup_events',
				'event_type' => $etype,
				'country' => '',
				'mailer' => 'kumomta',
				'server' => '',
				'campaign' => '',
				'details' => $details
			);
		}
	} catch (Exception $e) {
		// ignore — empty set
	}
	return $out;
}

function dlog_campaign_events($needle, $limit = 200)
{
	$out = array();
	$root = __DIR__.DIRECTORY_SEPARATOR.'campaigns';
	if (!is_dir($root)) return $out;
	$dirs = @scandir($root);
	if (!is_array($dirs)) return $out;
	rsort($dirs);
	$needle = strtolower(trim((string)$needle));
	$collected = 0;
	foreach ($dirs as $name) {
		if ($name === '.' || $name === '..') continue;
		$dir = $root.DIRECTORY_SEPARATOR.$name;
		if (!is_dir($dir)) continue;
		$file = $dir.DIRECTORY_SEPARATOR.'events.jsonl';
		if (!is_file($file)) continue;
		$lines = @file($file, FILE_IGNORE_NEW_LINES);
		if (!is_array($lines)) continue;
		for ($i = count($lines) - 1; $i >= 0; $i--) {
			$line = trim($lines[$i]);
			if ($line === '') continue;
			$j = json_decode($line, true);
			if (!is_array($j)) continue;
			$msg = ayb_log_redact_text(isset($j['msg']) ? $j['msg'] : $line);
			$type = isset($j['type']) ? (string)$j['type'] : 'event';
			$blob = strtolower($msg.' '.$type);
			if ($needle !== '' && strpos($blob, $needle) === false) continue;
			$sev = 'info';
			if ($type === 'fail' || $type === 'smtp_block') $sev = 'error';
			else if ($type === 'warn' || $type === 'queue' || $type === 'proxy') $sev = 'warn';
			else if ($type === 'ok') $sev = 'info';
			$ts = '';
			if (!empty($j['t'])) $ts = gmdate('Y-m-d H:i:s', intval($j['t']));
			$out[] = array(
				'ts' => $ts,
				'severity' => $sev,
				'source' => 'campaign',
				'event_type' => $type,
				'country' => '',
				'mailer' => '',
				'server' => '',
				'campaign' => $name,
				'details' => $msg
			);
			$collected++;
			if ($collected >= $limit) return $out;
		}
		// Only latest few campaign dirs
		if (count($out) > 0 && $collected >= min(50, $limit)) break;
	}
	return $out;
}

$action = isset($_GET['action']) ? strtolower(trim((string)$_GET['action'])) : 'list';
if ($action === 'sources') {
	dlog_out(array(
		'ok' => true,
		'sources' => array(
			array('id' => 'all', 'label' => 'All sources'),
			array('id' => 'kumomta', 'label' => 'KumoMTA log', 'persistent' => true),
			array('id' => 'sendgrid', 'label' => 'SendGrid log', 'persistent' => true),
			array('id' => 'warmup', 'label' => 'Warm-up decisions', 'persistent' => true),
			array('id' => 'warmup_events', 'label' => 'Warm-up send events', 'persistent' => true),
			array('id' => 'campaign', 'label' => 'Campaign events (persisted runs)', 'persistent' => true),
		),
		'note' => 'Live dash_log stream during Compose send is ephemeral and is not listed here.'
	));
}

$source = isset($_GET['source']) ? strtolower(trim((string)$_GET['source'])) : 'all';
$q = isset($_GET['q']) ? (string)$_GET['q'] : '';
$severity = isset($_GET['severity']) ? strtolower(trim((string)$_GET['severity'])) : '';
$country = isset($_GET['country']) ? strtolower(trim((string)$_GET['country'])) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = isset($_GET['per_page']) ? max(10, min(100, intval($_GET['per_page']))) : 40;

$rows = array();
if ($source === 'all' || $source === 'kumomta') $rows = array_merge($rows, dlog_read_provider_log('kumomta.log', 'kumomta', $q, 400));
if ($source === 'all' || $source === 'sendgrid') $rows = array_merge($rows, dlog_read_provider_log('sendgrid.log', 'sendgrid', $q, 400));
if ($source === 'all' || $source === 'warmup') $rows = array_merge($rows, dlog_warmup_decisions($q, 200));
if ($source === 'all' || $source === 'warmup_events') $rows = array_merge($rows, dlog_warmup_events($q, 200));
if ($source === 'all' || $source === 'campaign') $rows = array_merge($rows, dlog_campaign_events($q, 200));

if ($severity !== '' && $severity !== 'all') {
	$filtered = array();
	foreach ($rows as $r) {
		if (isset($r['severity']) && $r['severity'] === $severity) $filtered[] = $r;
	}
	$rows = $filtered;
}
if ($country !== '') {
	// Best-effort: only rows that mention the country code in details (queue/country data rarely present)
	$filtered = array();
	$cc = strtolower($country);
	foreach ($rows as $r) {
		$blob = strtolower((isset($r['country']) ? $r['country'] : '').' '.(isset($r['details']) ? $r['details'] : '').' '.(isset($r['campaign']) ? $r['campaign'] : ''));
		if ($r['country'] === $cc || strpos($blob, $cc) !== false) $filtered[] = $r;
	}
	$rows = $filtered;
}

// Sort by ts desc when possible
usort($rows, function ($a, $b) {
	$ta = isset($a['ts']) ? $a['ts'] : '';
	$tb = isset($b['ts']) ? $b['ts'] : '';
	if ($ta === $tb) return 0;
	return ($ta < $tb) ? 1 : -1;
});

$total = count($rows);
$offset = ($page - 1) * $perPage;
$slice = array_slice($rows, $offset, $perPage);
// Final redaction pass
foreach ($slice as &$row) {
	$row = ayb_log_redact_row($row);
	foreach (array('country', 'mailer', 'server', 'campaign') as $k) {
		if (!isset($row[$k]) || $row[$k] === '') $row[$k] = '—';
	}
}
unset($row);

dlog_out(array(
	'ok' => true,
	'source' => $source,
	'q' => $q,
	'severity' => $severity,
	'country' => $country,
	'page' => $page,
	'per_page' => $perPage,
	'total' => $total,
	'pages' => max(1, intval(ceil($total / $perPage))),
	'rows' => $slice,
	'note' => 'Read-only. Secrets redacted server-side. Live Compose dash_log is ephemeral and not included.'
));
