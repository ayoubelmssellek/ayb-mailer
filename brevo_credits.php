<?php
/**
 * POST JSON:
 *   { "text": "#ACCOUNT 300\nxkeysib-...\n..." }  → all accounts
 *   { "key": "xkeysib-..." }                     → one account
 *   { "keys": ["xkeysib-..."] }
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.DIRECTORY_SEPARATOR.'brevo_status.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST')
{
	echo json_encode(array('ok' => false, 'err' => 'POST only'));
	exit;
}

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if(!is_array($in)) $in = array();

function brevo_parse_api_accounts_for_credits($text)
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

function brevo_fetch_email_credits($apiKey)
{
	$out = array(
		'ok' => false,
		'key_preview' => substr($apiKey, 0, 14).'…',
		'credits' => null,
		'plan_type' => '',
		'end_date' => '',
		'email' => '',
		'err' => ''
	);
	if(!function_exists('curl_init'))
	{
		$out['err'] = 'PHP curl required';
		return $out;
	}
	$ch = curl_init('https://api.brevo.com/v3/account');
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => array(
			'accept: application/json',
			'api-key: '.$apiKey
		),
		CURLOPT_TIMEOUT => 25,
		CURLOPT_CONNECTTIMEOUT => 12
	));
	$body = curl_exec($ch);
	$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	$cerr = curl_error($ch);
	curl_close($ch);
	if($body === false || $cerr !== '')
	{
		$out['err'] = $cerr !== '' ? $cerr : 'curl failed';
		return $out;
	}
	$json = json_decode($body, true);
	if($code < 200 || $code >= 300 || !is_array($json))
	{
		$msg = is_array($json) && !empty($json['message']) ? $json['message'] : substr((string)$body, 0, 160);
		$out['err'] = 'HTTP '.$code.($msg ? ' — '.$msg : '');
		return $out;
	}
	if(!empty($json['email'])) $out['email'] = $json['email'];
	$best = null;
	if(!empty($json['plan']) && is_array($json['plan']))
	{
		foreach($json['plan'] as $p)
		{
			if(!is_array($p)) continue;
			$ctype = isset($p['creditsType']) ? $p['creditsType'] : '';
			$ptype = isset($p['type']) ? $p['type'] : '';
			if($ctype !== 'sendLimit') continue;
			if($ptype === 'sms') continue;
			$cand = array(
				'credits' => isset($p['credits']) ? floatval($p['credits']) : 0,
				'type' => $ptype,
				'end' => !empty($p['endDate']) ? $p['endDate'] : ''
			);
			if($best === null) $best = $cand;
			else if($ptype === 'free' || $ptype === 'subscription') $best = $cand;
		}
	}
	if($best === null)
	{
		$out['err'] = 'No email sendLimit in account plan';
		return $out;
	}
	$out['ok'] = true;
	$out['credits'] = $best['credits'];
	$out['plan_type'] = $best['type'];
	$out['end_date'] = $best['end'];
	return $out;
}

$parsed = array();
if(!empty($in['key']))
{
	$parsed[] = array('limit' => 300, 'key' => trim((string)$in['key']), 'from' => isset($in['from']) ? trim((string)$in['from']) : '', 'name' => '');
}
else if(!empty($in['keys']) && is_array($in['keys']))
{
	foreach($in['keys'] as $k)
	{
		$k = trim((string)$k);
		if($k !== '') $parsed[] = array('limit' => 300, 'key' => $k, 'from' => '', 'name' => '');
	}
}
else
{
	$text = isset($in['text']) ? (string)$in['text'] : '';
	if($text === '' && !empty($_POST['text'])) $text = (string)$_POST['text'];
	$parsed = brevo_parse_api_accounts_for_credits($text);
}

if(empty($parsed))
{
	echo json_encode(array('ok' => false, 'err' => 'No API keys found', 'accounts' => array(), 'total_left' => 0));
	exit;
}

$accounts = array();
$total = 0;
foreach($parsed as $i => $acc)
{
	$row = brevo_account_health($acc['key'], isset($acc['from']) ? $acc['from'] : '');
	$row['index'] = $i + 1;
	$row['limit'] = isset($acc['limit']) ? intval($acc['limit']) : 300;
	$row['from'] = isset($acc['from']) ? $acc['from'] : '';
	if($row['ok'] && $row['credits'] !== null && empty($row['blocking'])) $total += floatval($row['credits']);
	$accounts[] = $row;
}

echo json_encode(array(
	'ok' => true,
	'total_left' => $total,
	'account_count' => count($accounts),
	'accounts' => $accounts
));
