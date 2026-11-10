<?php
/**
 * Phase 5 analytics overview aggregator.
 * Uses existing per-method stores only — no unified message_events DB (Phase 2 skipped).
 *
 * Actions: overview | logs | queues
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'queue_country.php';
ayb_env_load();

$action = isset($_GET['action']) ? strtolower(trim((string)$_GET['action'])) : 'overview';
if ($action === '' && isset($_POST['action'])) $action = strtolower(trim((string)$_POST['action']));
if ($action === '') $action = 'overview';

function ao_out(array $payload, $code = 200)
{
	http_response_code($code);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

function ao_count_emails($path)
{
	if (!is_file($path)) return array('file' => basename($path), 'exists' => false, 'count' => 0);
	$n = 0;
	$fh = @fopen($path, 'r');
	if (!$fh) return array('file' => basename($path), 'exists' => true, 'count' => 0);
	while (($line = fgets($fh)) !== false) {
		$e = strtolower(trim($line));
		if ($e !== '' && strpos($e, '@') !== false) $n++;
	}
	fclose($fh);
	return array('file' => basename($path), 'exists' => true, 'count' => $n);
}

function ao_queue_snapshot($country = 'pt')
{
	$out = array();
	foreach (array('api' => 'sendgrid', 'smtp' => 'normal_smtp', 'kumomta' => 'kumomta') as $lane => $label) {
		$names = queue_country_files($lane, $country);
		$valid = ao_count_emails(__DIR__.DIRECTORY_SEPARATOR.$names['valid']);
		$done = ao_count_emails(__DIR__.DIRECTORY_SEPARATOR.$names['done']);
		$pending = max(0, intval($valid['count']) - intval($done['count']));
		$progPath = __DIR__.DIRECTORY_SEPARATOR.$names['prog'];
		$prog = null;
		if (is_file($progPath)) {
			$raw = @file_get_contents($progPath);
			$j = json_decode((string)$raw, true);
			if (is_array($j)) $prog = $j;
		}
		$out[$lane] = array(
			'method_label' => $label,
			'valid' => $valid,
			'done' => $done,
			'pending_approx' => $pending,
			'progress' => $prog
		);
	}
	return $out;
}

function ao_tail_log($path, $needle = '', $limit = 80)
{
	$limit = max(1, min(300, intval($limit)));
	$rows = array();
	if (!is_file($path)) {
		return array('ok' => true, 'file' => basename($path), 'exists' => false, 'rows' => array());
	}
	$lines = @file($path, FILE_IGNORE_NEW_LINES);
	if (!is_array($lines)) $lines = array();
	$needle = strtolower(trim((string)$needle));
	$filtered = array();
	foreach ($lines as $line) {
		if ($needle !== '' && strpos(strtolower($line), $needle) === false) continue;
		$filtered[] = $line;
	}
	$slice = array_slice($filtered, -$limit);
	foreach ($slice as $line) {
		$rows[] = array('line' => $line);
	}
	return array(
		'ok' => true,
		'file' => basename($path),
		'exists' => true,
		'total_matched' => count($filtered),
		'rows' => array_reverse($rows)
	);
}

function ao_socks_status()
{
	$okFile = __DIR__.DIRECTORY_SEPARATOR.'socks5_ok.txt';
	$count = 0;
	if (is_file($okFile)) {
		foreach (@file($okFile, FILE_IGNORE_NEW_LINES) ?: array() as $line) {
			if (trim($line) !== '') $count++;
		}
	}
	return array(
		'ok_list_exists' => is_file($okFile),
		'ok_proxies' => $count,
		'note' => 'Normal SMTP may use SOCKS; KumoMTA and SendGrid never use SOCKS'
	);
}

function ao_sendgrid_summary($days = 7)
{
	$days = max(1, min(90, intval($days)));
	$lib = __DIR__.DIRECTORY_SEPARATOR.'sendgrid_analytics_lib.php';
	$mail = __DIR__.DIRECTORY_SEPARATOR.'sendgrid_mail.php';
	$out = array(
		'configured' => false,
		'status' => null,
		'metrics' => null,
		'metric_kind' => 'sendgrid_delivered_and_engagement',
		'note' => 'SendGrid “delivered” is provider-confirmed delivery when synced — not comparable to SMTP accept'
	);
	if (is_file($mail)) {
		require_once $mail;
		if (function_exists('sendgrid_config_status')) $out['status'] = sendgrid_config_status();
		$out['configured'] = !empty($out['status']['ok']);
	}
	if (is_file($lib)) {
		require_once $lib;
		if (function_exists('sg_stats_sum')) {
			$end = gmdate('Y-m-d');
			$start = gmdate('Y-m-d', time() - ($days - 1) * 86400);
			$out['metrics'] = sg_stats_sum($start, $end);
			$out['range'] = array('start' => $start, 'end' => $end, 'days' => $days);
			if (function_exists('sg_suppression_counts')) $out['sg_suppression_counts'] = sg_suppression_counts();
			if (function_exists('sg_meta_get')) {
				$out['last_sync_at'] = sg_meta_get('last_sync_at', '');
				$out['last_sync_status'] = sg_meta_get('last_sync_status', '');
			}
		}
	}
	return $out;
}

function ao_kumo_summary()
{
	$out = array(
		'status' => null,
		'metric_kind' => 'smtp_accepted_not_delivered',
		'note' => 'KumoMTA counters are SMTP acceptance / warm-up outcomes — Accepted ≠ Delivered',
		'log_tail' => null,
		'warmup' => null,
		'providers_today' => array(),
		'series' => array()
	);
	$lib = __DIR__.DIRECTORY_SEPARATOR.'kumomta_mail.php';
	if (is_file($lib)) {
		require_once $lib;
		if (function_exists('kumomta_config_status')) $out['status'] = kumomta_config_status();
	}
	$log = __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'kumomta.log';
	$out['log_tail'] = ao_tail_log($log, '', 15);
	$wu = __DIR__.DIRECTORY_SEPARATOR.'warmup_lib.php';
	if (is_file($wu)) {
		require_once $wu;
		if (function_exists('warmup_status')) {
			$st = warmup_status('kumomta');
			$out['warmup'] = array(
				'global_enabled' => !empty($st['global_enabled']),
				'plan' => isset($st['plan']) ? $st['plan'] : null,
				'today' => isset($st['today']) ? $st['today'] : null,
				'remaining' => isset($st['remaining']) ? $st['remaining'] : null,
				'metrics' => isset($st['metrics']) ? $st['metrics'] : null
			);
			$out['providers_today'] = isset($st['providers_today']) ? $st['providers_today'] : array();
		}
		if (function_exists('warmup_series')) {
			$ser = warmup_series('kumomta', 14);
			$out['series'] = isset($ser['days']) ? $ser['days'] : array();
		}
	}
	return $out;
}

function ao_profiles()
{
	$f = __DIR__.DIRECTORY_SEPARATOR.'sender_profiles.php';
	if (!is_file($f)) return array('ok' => false, 'profiles' => array());
	require_once $f;
	if (function_exists('sender_profile_public_list')) return sender_profile_public_list();
	return array('ok' => false, 'profiles' => array());
}

function ao_suppressions()
{
	$f = __DIR__.DIRECTORY_SEPARATOR.'suppression_lib.php';
	if (!is_file($f)) return array('counts' => array('total' => 0));
	require_once $f;
	return array('counts' => function_exists('suppression_counts') ? suppression_counts() : array('total' => 0));
}

function ao_method_comparison($days = 7)
{
	$sg = ao_sendgrid_summary($days);
	$kumo = ao_kumo_summary();
	$sgM = isset($sg['metrics']) && is_array($sg['metrics']) ? $sg['metrics'] : array();
	$wuToday = isset($kumo['warmup']['today']) && is_array($kumo['warmup']['today']) ? $kumo['warmup']['today'] : array();
	// Aggregate kumo series for period
	$kAccepted = 0;
	$kFailed = 0;
	$kHard = 0;
	$kSoft = 0;
	foreach (isset($kumo['series']) ? $kumo['series'] : array() as $d) {
		$kAccepted += intval(isset($d['accepted']) ? $d['accepted'] : 0);
		$kFailed += intval(isset($d['failed']) ? $d['failed'] : 0);
		$kHard += intval(isset($d['hard_bounce']) ? $d['hard_bounce'] : 0);
		$kSoft += intval(isset($d['soft_bounce']) ? $d['soft_bounce'] : 0);
	}
	return array(
		'disclaimer' => 'Do not equate metrics across methods. SendGrid “delivered” ≠ Kumo/Normal “accepted/OK”.',
		'days' => intval($days),
		'methods' => array(
			array(
				'method' => 'sendgrid',
				'label' => 'Twilio SendGrid API',
				'primary_metric' => 'delivered',
				'primary_value' => intval(isset($sgM['delivered']) ? $sgM['delivered'] : 0),
				'secondary' => array(
					'requests' => intval(isset($sgM['requests']) ? $sgM['requests'] : 0),
					'bounces' => intval(isset($sgM['bounces']) ? $sgM['bounces'] : 0),
					'opens' => intval(isset($sgM['opens']) ? $sgM['opens'] : 0),
					'clicks' => intval(isset($sgM['clicks']) ? $sgM['clicks'] : 0)
				),
				'vocabulary' => 'Provider delivery + engagement (when synced)'
			),
			array(
				'method' => 'kumomta',
				'label' => 'VPS / KumoMTA',
				'primary_metric' => 'accepted',
				'primary_value' => $kAccepted,
				'secondary' => array(
					'failed' => $kFailed,
					'hard_bounce' => $kHard,
					'soft_bounce' => $kSoft,
					'today_accepted' => intval(isset($wuToday['accepted']) ? $wuToday['accepted'] : 0)
				),
				'vocabulary' => 'SMTP acceptance (not inbox delivery)'
			),
			array(
				'method' => 'normal_smtp',
				'label' => 'Normal SMTP ± SOCKS',
				'primary_metric' => 'queue_done',
				'primary_value' => null,
				'secondary' => array('note' => 'Use Recipients lane / done-file counts; no delivery confirmation'),
				'vocabulary' => 'Local send OK / accept only — not delivered'
			)
		)
	);
}

function ao_throughput($series)
{
	$out = array('days' => array(), 'avg_accepted_per_day' => 0, 'peak_accepted' => 0);
	$sum = 0;
	$n = 0;
	foreach ($series as $d) {
		$acc = intval(isset($d['accepted']) ? $d['accepted'] : 0);
		$out['days'][] = array('day' => isset($d['day']) ? $d['day'] : '', 'accepted' => $acc, 'failed' => intval(isset($d['failed']) ? $d['failed'] : 0));
		$sum += $acc;
		$n++;
		if ($acc > $out['peak_accepted']) $out['peak_accepted'] = $acc;
	}
	$out['avg_accepted_per_day'] = $n > 0 ? round($sum / $n, 2) : 0;
	$out['note'] = 'Local warm-up accepted throughput — not VPS CPU/RAM. Native Kumo server metrics require VPS access.';
	return $out;
}

if ($action === 'logs') {
	$q = isset($_GET['q']) ? $_GET['q'] : (isset($_POST['q']) ? $_POST['q'] : '');
	$source = isset($_GET['source']) ? strtolower(trim((string)$_GET['source'])) : 'kumomta';
	$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 80;
	if ($source === 'sendgrid') {
		$path = __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'sendgrid.log';
	} else {
		$source = 'kumomta';
		$path = __DIR__.DIRECTORY_SEPARATOR.'data'.DIRECTORY_SEPARATOR.'kumomta.log';
	}
	$r = ao_tail_log($path, $q, $limit);
	$r['source'] = $source;
	$r['note'] = 'Provider log search only — not a unified message ledger (Phase 2 skipped)';
	ao_out($r);
}

if ($action === 'queues') {
	$country = isset($_GET['country']) ? $_GET['country'] : 'pt';
	ao_out(array('ok' => true, 'country' => queue_country_code($country), 'lanes' => ao_queue_snapshot($country)));
}

// overview
$days = isset($_GET['days']) ? intval($_GET['days']) : 7;
$country = isset($_GET['country']) ? $_GET['country'] : 'pt';
$sg = ao_sendgrid_summary($days);
$kumo = ao_kumo_summary();
$comp = ao_method_comparison($days);
$queues = ao_queue_snapshot($country);
// fill normal_smtp primary from queues
foreach ($comp['methods'] as &$m) {
	if ($m['method'] === 'normal_smtp') {
		$m['primary_value'] = isset($queues['smtp']['done']['count']) ? intval($queues['smtp']['done']['count']) : 0;
		$m['secondary']['valid'] = isset($queues['smtp']['valid']['count']) ? intval($queues['smtp']['valid']['count']) : 0;
		$m['secondary']['pending_approx'] = isset($queues['smtp']['pending_approx']) ? intval($queues['smtp']['pending_approx']) : 0;
	}
	if ($m['method'] === 'sendgrid') {
		$m['secondary']['queue_done'] = isset($queues['api']['done']['count']) ? intval($queues['api']['done']['count']) : 0;
		$m['secondary']['queue_pending_approx'] = isset($queues['api']['pending_approx']) ? intval($queues['api']['pending_approx']) : 0;
	}
	if ($m['method'] === 'kumomta') {
		$m['secondary']['queue_done'] = isset($queues['kumomta']['done']['count']) ? intval($queues['kumomta']['done']['count']) : 0;
		$m['secondary']['queue_pending_approx'] = isset($queues['kumomta']['pending_approx']) ? intval($queues['kumomta']['pending_approx']) : 0;
	}
}
unset($m);

ao_out(array(
	'ok' => true,
	'generated_at' => gmdate('c'),
	'vocabulary' => array(
		'accepted' => 'SMTP/API accepted the message for processing — not proof of inbox delivery',
		'delivered' => 'Provider-confirmed delivery (SendGrid sync only when available)',
		'ok_smtp' => 'Local PHPMailer Send() success for Normal/SOCKS — not delivered'
	),
	'methods_status' => array(
		'sendgrid' => array(
			'ready' => !empty($sg['configured']),
			'status' => isset($sg['status']['status']) ? $sg['status']['status'] : 'unknown',
			'err' => isset($sg['status']['err']) ? $sg['status']['err'] : '',
			'from' => isset($sg['status']['from_email']) ? $sg['status']['from_email'] : ''
		),
		'kumomta' => array(
			'ready' => !empty($kumo['status']['ok']),
			'status' => isset($kumo['status']['status']) ? $kumo['status']['status'] : 'unknown',
			'err' => isset($kumo['status']['err']) ? $kumo['status']['err'] : '',
			'host' => isset($kumo['status']['host']) ? $kumo['status']['host'] : '',
			'port' => isset($kumo['status']['port']) ? $kumo['status']['port'] : '',
			'verify_peer' => !empty($kumo['status']['verify_peer'])
		),
		'normal_smtp' => ao_socks_status()
	),
	'sendgrid' => $sg,
	'kumomta' => $kumo,
	'profiles' => ao_profiles(),
	'suppressions' => ao_suppressions(),
	'queues' => $queues,
	'comparison' => $comp,
	'providers' => isset($kumo['providers_today']) ? $kumo['providers_today'] : array(),
	'throughput' => ao_throughput(isset($kumo['series']) ? $kumo['series'] : array()),
	'links' => array(
		'warmup_page' => 'warmup',
		'suppression_docs' => 'docs/SUPPRESSION.md',
		'warmup_docs' => 'docs/WARMUP.md',
		'kumo_docs' => 'docs/KUMOMTA_INTEGRATION.md'
	)
));
