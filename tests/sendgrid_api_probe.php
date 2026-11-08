<?php
/**
 * Probe which SendGrid read APIs are available for this account.
 * Never prints the API key.
 * Run: php tests/sendgrid_api_probe.php
 */
require_once dirname(__DIR__).DIRECTORY_SEPARATOR.'sendgrid_mail.php';

function probe($path, array $qs = array())
{
	$cfg = sendgrid_config();
	$url = 'https://api.sendgrid.com'.$path;
	if ($qs) $url .= '?'.http_build_query($qs);
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_HTTPHEADER => array('Authorization: Bearer '.$cfg['api_key'], 'Accept: application/json'),
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => 30,
		CURLOPT_SSL_VERIFYPEER => true
	));
	$body = curl_exec($ch);
	$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	$err = curl_error($ch);
	curl_close($ch);
	$snippet = is_string($body) ? substr(preg_replace('/\s+/', ' ', $body), 0, 160) : '';
	return array('http' => $code, 'err' => $err, 'snippet' => $snippet);
}

$cfg = sendgrid_config();
if ($cfg['api_key'] === '') {
	echo json_encode(array('ok' => false, 'err' => 'SENDGRID_API_KEY empty'));
	exit(1);
}

$start = gmdate('Y-m-d', strtotime('-7 days'));
$end = gmdate('Y-m-d');
$out = array(
	'ok' => true,
	'note' => 'API key never printed',
	'from' => $cfg['from_email'],
	'probes' => array(
		'stats' => probe('/v3/stats', array('start_date' => $start, 'end_date' => $end, 'aggregated_by' => 'day')),
		'messages' => probe('/v3/messages', array('limit' => 10, 'query' => 'last_event_time BETWEEN TIMESTAMP "'.$start.'T00:00:00Z" AND TIMESTAMP "'.$end.'T23:59:59Z"')),
		'bounces' => probe('/v3/suppression/bounces', array('limit' => 10)),
		'spam_reports' => probe('/v3/suppression/spam_reports', array('limit' => 10)),
		'blocks' => probe('/v3/suppression/blocks', array('limit' => 10)),
		'invalid_emails' => probe('/v3/suppression/invalid_emails', array('limit' => 10)),
		'unsubscribes' => probe('/v3/suppression/unsubscribes', array('limit' => 10)),
		'asm_groups' => probe('/v3/asm/groups'),
		'user_credits' => probe('/v3/user/credits'),
		'user_account' => probe('/v3/user/account')
	)
);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
