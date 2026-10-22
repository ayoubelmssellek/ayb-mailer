<?php
/**
 * Fetch Brevo transactional stats (+ credits) for each API account.
 * POST JSON: { "text": "#ACCOUNT…", "days": 7 }
 */
@ini_set('display_errors', '0');
@error_reporting(E_ALL);
@set_time_limit(180);
@ini_set('max_execution_time', '180');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bs_fail($err)
{
	echo json_encode(array('ok' => false, 'err' => $err, 'accounts' => array(), 'totals' => null));
	exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST')
	bs_fail('POST only');

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if(!is_array($in)) $in = array();
// Fallback if body empty but form-posted
if(empty($in) && !empty($_POST))
	$in = $_POST;

function bs_parse_accounts($text)
{
	$accounts = array();
	$lines = preg_split("/\\r\\n|\\r|\\n/", (string)$text);
	$cur = null;
	$flush = function() use (&$accounts, &$cur) {
		if($cur !== null && !empty($cur['key']))
		{
			if(empty($cur['limit'])) $cur['limit'] = 300;
			$accounts[] = $cur;
		}
		$cur = null;
	};
	foreach($lines as $line)
	{
		$line = trim($line);
		if($line === '') continue;
		if(preg_match('/^#\s*ACCOUNT\s+(\d+)/i', $line, $m))
		{
			$flush();
			$cur = array('limit' => max(1, intval($m[1])), 'key' => '', 'from' => '', 'name' => '');
			continue;
		}
		if(isset($line[0]) && $line[0] === '#') continue;
		if(preg_match('/^from\s*[:=]\s*(.+)$/i', $line, $m))
		{
			if($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '');
			$cur['from'] = trim($m[1]);
			continue;
		}
		if(preg_match('/^name\s*[:=]\s*(.+)$/i', $line, $m))
		{
			if($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '');
			$cur['name'] = trim($m[1]);
			continue;
		}
		if(preg_match('/^xkeysib-/i', $line) || (strlen($line) > 40 && strpos($line, ' ') === false && strpos($line, '@') === false))
		{
			if($cur === null) $cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '');
			if($cur['key'] !== '')
			{
				$flush();
				$cur = array('limit' => 300, 'key' => '', 'from' => '', 'name' => '');
			}
			$cur['key'] = $line;
		}
	}
	$flush();
	return $accounts;
}

function bs_curl_json($url, $apiKey)
{
	if(!function_exists('curl_init'))
		return array('ok' => false, 'err' => 'PHP curl required', 'http' => 0, 'data' => null);
	$ch = curl_init($url);
	// XAMPP: disable SSL peer verify by default (CA bundle often missing)
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => array('accept: application/json', 'api-key: '.$apiKey),
		CURLOPT_TIMEOUT => 20,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_SSL_VERIFYHOST => 0
	));
	$body = curl_exec($ch);
	$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	$cerr = curl_error($ch);
	curl_close($ch);
	if($body === false || $cerr !== '')
		return array('ok' => false, 'err' => $cerr !== '' ? $cerr : 'curl failed', 'http' => $code, 'data' => null);
	$json = json_decode($body, true);
	if($code < 200 || $code >= 300 || !is_array($json))
	{
		$msg = is_array($json) && !empty($json['message']) ? $json['message'] : substr((string)$body, 0, 160);
		return array('ok' => false, 'err' => 'HTTP '.$code.($msg ? ' — '.$msg : ''), 'http' => $code, 'data' => null);
	}
	return array('ok' => true, 'err' => '', 'http' => $code, 'data' => $json);
}

function bs_credits_from_account($json)
{
	$out = array('credits' => null, 'plan_type' => '', 'end_date' => '', 'email' => '');
	if(!empty($json['email'])) $out['email'] = $json['email'];
	$best = null;
	if(!empty($json['plan']) && is_array($json['plan']))
	{
		foreach($json['plan'] as $p)
		{
			if(!is_array($p)) continue;
			if((isset($p['creditsType']) ? $p['creditsType'] : '') !== 'sendLimit') continue;
			if((isset($p['type']) ? $p['type'] : '') === 'sms') continue;
			$cand = array(
				'credits' => isset($p['credits']) ? floatval($p['credits']) : 0,
				'type' => isset($p['type']) ? $p['type'] : '',
				'end' => !empty($p['endDate']) ? $p['endDate'] : ''
			);
			if($best === null) $best = $cand;
			else if($cand['type'] === 'free' || $cand['type'] === 'subscription') $best = $cand;
		}
	}
	if($best)
	{
		$out['credits'] = $best['credits'];
		$out['plan_type'] = $best['type'];
		$out['end_date'] = $best['end'];
	}
	return $out;
}

function bs_empty_metrics()
{
	return array(
		'requests' => 0,
		'delivered' => 0,
		'hardBounces' => 0,
		'softBounces' => 0,
		'bounces' => 0,
		'opens' => 0,
		'uniqueOpens' => 0,
		'clicks' => 0,
		'uniqueClicks' => 0,
		'spamReports' => 0,
		'blocked' => 0,
		'invalid' => 0,
		'unsubscribed' => 0,
		'range' => ''
	);
}

function bs_norm_metrics($data)
{
	$m = bs_empty_metrics();
	if(!is_array($data)) return $m;
	foreach($m as $k => $_)
	{
		if(isset($data[$k])) $m[$k] = is_numeric($data[$k]) ? intval($data[$k]) : $data[$k];
	}
	$m['bounces'] = intval($m['hardBounces']) + intval($m['softBounces']);
	return $m;
}

function bs_pct($part, $whole)
{
	$whole = floatval($whole);
	if($whole <= 0) return 0;
	return round(($part / $whole) * 100, 2);
}

function bs_rates($m)
{
	$req = max(1, intval($m['requests']));
	$del = intval($m['delivered']);
	$base = $del > 0 ? $del : $req;
	return array(
		'delivered_pct' => bs_pct($m['delivered'], $req),
		'bounced_pct' => bs_pct($m['bounces'], $req),
		'hard_bounce_pct' => bs_pct($m['hardBounces'], $req),
		'soft_bounce_pct' => bs_pct($m['softBounces'], $req),
		'estimated_openers_pct' => bs_pct($m['uniqueOpens'], $base),
		'trackable_openers_pct' => bs_pct($m['uniqueOpens'], $base),
		'unique_clickers_pct' => bs_pct($m['uniqueClicks'], $base),
		'complaint_pct' => bs_pct($m['spamReports'], $req),
		'blocked_pct' => bs_pct($m['blocked'], $req)
	);
}

function bs_fetch_domains($apiKey)
{
	$res = bs_curl_json('https://api.brevo.com/v3/senders/domains', $apiKey);
	$out = array(
		'ok' => false,
		'err' => '',
		'domains' => array(),
		'authenticated_count' => 0,
		'verified_count' => 0,
		'total' => 0
	);
	if(!$res['ok'])
	{
		$out['err'] = $res['err'];
		return $out;
	}
	$list = array();
	if(!empty($res['data']['domains']) && is_array($res['data']['domains']))
		$list = $res['data']['domains'];
	else if(!empty($res['data']) && is_array($res['data']) && isset($res['data'][0]))
		$list = $res['data'];

	$domains = array();
	$authN = 0;
	$verN = 0;
	foreach($list as $d)
	{
		if(!is_array($d)) continue;
		$name = '';
		if(!empty($d['domain_name'])) $name = $d['domain_name'];
		else if(!empty($d['domain'])) $name = $d['domain'];
		else if(!empty($d['name'])) $name = $d['name'];
		if($name === '') continue;
		$auth = !empty($d['authenticated']);
		$ver = !empty($d['verified']);
		if($auth) $authN++;
		if($ver) $verN++;
		$domains[] = array(
			'domain' => $name,
			'authenticated' => $auth,
			'verified' => $ver,
			'provider' => isset($d['provider']) ? $d['provider'] : ''
		);
	}
	$out['ok'] = true;
	$out['domains'] = $domains;
	$out['authenticated_count'] = $authN;
	$out['verified_count'] = $verN;
	$out['total'] = count($domains);
	return $out;
}

function bs_local_campaign()
{
	$dir = __DIR__;
	$validPath = $dir.DIRECTORY_SEPARATOR.'validmails.txt';
	$donePath = $dir.DIRECTORY_SEPARATOR.'mailssenddone.txt';
	$load = function($path) {
		if(!is_file($path)) return array();
		$seen = array();
		$out = array();
		foreach(@file($path, FILE_IGNORE_NEW_LINES) ?: array() as $line)
		{
			$e = strtolower(trim($line));
			if($e === '' || strpos($e, '@') === false) continue;
			if(isset($seen[$e])) continue;
			$seen[$e] = true;
			$out[] = $e;
		}
		return $out;
	};
	$valid = $load($validPath);
	$doneList = $load($donePath);
	$doneSet = array();
	foreach($doneList as $e) $doneSet[$e] = true;
	$pending = 0;
	foreach($valid as $e) if(!isset($doneSet[$e])) $pending++;
	return array(
		'name' => 'Local campaign queue',
		'valid' => count($valid),
		'done' => count($doneList),
		'pending' => $pending,
		'progress_pct' => count($valid) ? round((count($doneList) / max(1, count($valid))) * 100, 2) : 0
	);
}

$days = isset($in['days']) ? intval($in['days']) : 7;
if($days < 1) $days = 7;
if($days > 90) $days = 90;

$startDate = '';
$endDate = '';
if(!empty($in['startDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['startDate']))
	$startDate = (string)$in['startDate'];
if(!empty($in['endDate']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['endDate']))
	$endDate = (string)$in['endDate'];
if($startDate !== '' && $endDate === '') $endDate = $startDate;
if($endDate !== '' && $startDate === '') $startDate = $endDate;
if($startDate !== '' && $endDate !== '' && strcmp($startDate, $endDate) > 0)
{
	$tmp = $startDate; $startDate = $endDate; $endDate = $tmp;
}

// "Today" / days=1 must be a calendar date — Brevo days=1 can behave like a rolling window
$todayLocal = date('Y-m-d');
if($startDate === '' && $endDate === '' && $days === 1)
{
	$startDate = $todayLocal;
	$endDate = $todayLocal;
}

// Brevo: max 90-day window for date range
if($startDate !== '' && $endDate !== '')
{
	$ts1 = strtotime($startDate.' 00:00:00');
	$ts2 = strtotime($endDate.' 00:00:00');
	if($ts1 && $ts2 && (($ts2 - $ts1) / 86400) > 90)
		bs_fail('Date range cannot exceed 90 days');
}

$useDates = ($startDate !== '' && $endDate !== '');
$singleDay = ($useDates && $startDate === $endDate);
if($useDates)
{
	// Never send days= together with dates (Brevo: incompatible)
	$statQuery = 'startDate='.rawurlencode($startDate).'&endDate='.rawurlencode($endDate);
	$periodLabel = $singleDay ? ('Calendar day '.$startDate) : ($startDate.' → '.$endDate);
	$days = $singleDay ? 1 : (intval(round((strtotime($endDate) - strtotime($startDate)) / 86400)) + 1);
}
else
{
	$statQuery = 'days='.$days;
	$periodLabel = 'Last '.$days.' day(s)';
}

// Daily /reports window — docs: max 30 days; never mix days= with startDate/endDate
if($useDates)
{
	$dailyStart = $singleDay ? date('Y-m-d', strtotime($startDate.' -1 day')) : $startDate;
	$dailyEnd = $endDate;
}
else
{
	// "Last N days including today" → inclusive calendar span (N days)
	$n = max(1, min(30, $days));
	$dailyEnd = $todayLocal;
	$dailyStart = date('Y-m-d', strtotime('-'.($n - 1).' days', strtotime($dailyEnd)));
}
// Hard clamp to 30-day max required by GET /smtp/statistics/reports
$dailySpan = intval(round((strtotime($dailyEnd) - strtotime($dailyStart)) / 86400)) + 1;
if($dailySpan > 30)
{
	$dailyStart = date('Y-m-d', strtotime($dailyEnd.' -29 days'));
	$dailySpan = 30;
}
$dailyQuery = 'startDate='.rawurlencode($dailyStart).'&endDate='.rawurlencode($dailyEnd).'&limit=30&sort=desc';

$parsed = array();
if(!empty($in['key']))
	$parsed[] = array('limit' => 300, 'key' => trim((string)$in['key']), 'from' => '', 'name' => '');
else
{
	$text = isset($in['text']) ? (string)$in['text'] : '';
	$parsed = bs_parse_accounts($text);
}

if(empty($parsed))
{
	echo json_encode(array('ok' => false, 'err' => 'No API keys found', 'accounts' => array(), 'totals' => null));
	exit;
}

$accounts = array();
$totals = bs_empty_metrics();
$totalCredits = 0;
$dailyByDate = array(); // date => summed metrics across accounts (Brevo /reports)

foreach($parsed as $i => $acc)
{
	$key = $acc['key'];
	$row = array(
		'ok' => false,
		'index' => $i + 1,
		'limit' => isset($acc['limit']) ? intval($acc['limit']) : 300,
		'from' => isset($acc['from']) ? $acc['from'] : '',
		'name' => isset($acc['name']) ? $acc['name'] : '',
		'key_preview' => substr($key, 0, 14).'…',
		'email' => '',
		'credits' => null,
		'plan_type' => '',
		'end_date' => '',
		'metrics' => bs_empty_metrics(),
		'rates' => bs_rates(bs_empty_metrics()),
		'brevo_range' => '',
		'domains' => array(),
		'domains_ok' => false,
		'domains_err' => '',
		'domain_authenticated_count' => 0,
		'domain_verified_count' => 0,
		'domain_total' => 0,
		'err' => '',
		'source' => 'brevo_api'
	);

	$accRes = bs_curl_json('https://api.brevo.com/v3/account', $key);
	if(!$accRes['ok'])
	{
		$row['err'] = $accRes['err'];
		$accounts[] = $row;
		continue;
	}
	$cred = bs_credits_from_account($accRes['data']);
	$row['email'] = $cred['email'];
	$row['credits'] = $cred['credits'];
	$row['plan_type'] = $cred['plan_type'];
	$row['end_date'] = $cred['end_date'];
	if($cred['credits'] !== null) $totalCredits += floatval($cred['credits']);

	// Domain auth status (DKIM / authenticated)
	$dom = bs_fetch_domains($key);
	$row['domains_ok'] = !empty($dom['ok']);
	$row['domains_err'] = isset($dom['err']) ? $dom['err'] : '';
	$row['domains'] = isset($dom['domains']) ? $dom['domains'] : array();
	$row['domain_authenticated_count'] = isset($dom['authenticated_count']) ? intval($dom['authenticated_count']) : 0;
	$row['domain_verified_count'] = isset($dom['verified_count']) ? intval($dom['verified_count']) : 0;
	$row['domain_total'] = isset($dom['total']) ? intval($dom['total']) : 0;

	// Per-day reports — GET /v3/smtp/statistics/reports (docs: calendar days, max 30)
	$repRes = bs_curl_json('https://api.brevo.com/v3/smtp/statistics/reports?'.$dailyQuery, $key);
	$dayMap = array();
	$reportsOk = !empty($repRes['ok']);
	if($reportsOk && !empty($repRes['data']['reports']) && is_array($repRes['data']['reports']))
	{
		foreach($repRes['data']['reports'] as $rep)
		{
			if(!is_array($rep) || empty($rep['date'])) continue;
			$d = (string)$rep['date'];
			$nm = bs_norm_metrics($rep);
			$nm['range'] = $d;
			$dayMap[$d] = $nm;
			if(!isset($dailyByDate[$d])) $dailyByDate[$d] = bs_empty_metrics();
			foreach(array('requests','delivered','hardBounces','softBounces','opens','uniqueOpens','clicks','uniqueClicks','spamReports','blocked','invalid','unsubscribed') as $k)
				$dailyByDate[$d][$k] = intval($dailyByDate[$d][$k]) + intval($nm[$k]);
			$dailyByDate[$d]['bounces'] = intval($dailyByDate[$d]['hardBounces']) + intval($dailyByDate[$d]['softBounces']);
			$dailyByDate[$d]['range'] = $d;
		}
	}

	// Single calendar day: trust /reports only (missing day ⇒ 0, do not widen via aggregated)
	$m = null;
	if($singleDay && $reportsOk)
	{
		$m = isset($dayMap[$startDate]) ? $dayMap[$startDate] : bs_empty_metrics();
		$m['range'] = $startDate;
		$row['brevo_range'] = $startDate;
		$row['metrics_source'] = 'brevo_reports_day';
	}
	else
	{
		// Multi-day / fallback: GET /v3/smtp/statistics/aggregatedReport (docs: max 90 days)
		$statRes = bs_curl_json('https://api.brevo.com/v3/smtp/statistics/aggregatedReport?'.$statQuery, $key);
		if(!$statRes['ok'])
		{
			$row['err'] = $statRes['err'];
			$row['ok'] = $row['domains_ok'];
			$accounts[] = $row;
			continue;
		}
		$m = bs_norm_metrics($statRes['data']);
		$row['brevo_range'] = isset($m['range']) ? (string)$m['range'] : $periodLabel;
		$row['metrics_source'] = 'brevo_aggregatedReport';
	}

	$row['metrics'] = $m;
	$row['rates'] = bs_rates($m);
	$row['ok'] = true;
	$row['daily'] = $dayMap;

	foreach(array('requests','delivered','hardBounces','softBounces','opens','uniqueOpens','clicks','uniqueClicks','spamReports','blocked','invalid','unsubscribed') as $k)
		$totals[$k] = intval($totals[$k]) + intval($m[$k]);
	$totals['bounces'] = intval($totals['hardBounces']) + intval($totals['softBounces']);
	if(!empty($m['range'])) $totals['range'] = $m['range'];

	$accounts[] = $row;
}

$totals['bounces'] = intval($totals['hardBounces']) + intval($totals['softBounces']);

// Sort daily keys ascending for UI
ksort($dailyByDate);
$dailyList = array();
foreach($dailyByDate as $d => $m)
{
	$dailyList[] = array(
		'date' => $d,
		'requests' => intval($m['requests']),
		'delivered' => intval($m['delivered']),
		'bounces' => intval($m['bounces']),
		'blocked' => intval($m['blocked']),
		'is_selected' => ($singleDay && $d === $startDate)
	);
}

echo json_encode(array(
	'ok' => true,
	'source' => 'brevo_api',
	'note' => 'KPIs come from Brevo API only. Local campaign block is separate (mailssenddone.txt all-time).',
	'days' => $days,
	'startDate' => $useDates ? $startDate : '',
	'endDate' => $useDates ? $endDate : '',
	'period' => $periodLabel,
	'api_query' => $useDates ? ('aggregatedReport?'.$statQuery) : ('aggregatedReport?'.$statQuery),
	'daily_query' => 'reports?'.$dailyQuery,
	'daily' => $dailyList,
	'account_count' => count($accounts),
	'total_credits' => $totalCredits,
	'totals' => $totals,
	'total_rates' => bs_rates($totals),
	'accounts' => $accounts,
	'local_campaign' => bs_local_campaign(),
	'fetched_at' => date('c')
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
