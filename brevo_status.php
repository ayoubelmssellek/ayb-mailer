<?php
/**
 * Brevo account health for the dashboard and for Send.
 * Credits come from GET /v3/account. "Under verification" is the API error
 * account_under_validation. Sender active comes from GET /v3/senders.
 * Domain verified / authenticated comes from GET /v3/senders/domains/{domain}.
 */

function brevo_http_get($url, $apiKey)
{
	$out = array('code' => 0, 'json' => null, 'err' => '', 'body' => '');
	if(!function_exists('curl_init') || trim((string)$apiKey) === '')
	{
		$out['err'] = 'curl or API key missing';
		return $out;
	}
	$ch = curl_init($url);
	curl_setopt_array($ch, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => array(
			'accept: application/json',
			'api-key: '.trim((string)$apiKey)
		),
		CURLOPT_TIMEOUT => 20,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_SSL_VERIFYPEER => false
	));
	$body = curl_exec($ch);
	$out['code'] = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	$cerr = curl_error($ch);
	curl_close($ch);
	if($body === false || $cerr !== '')
	{
		$out['err'] = $cerr !== '' ? $cerr : 'curl failed';
		return $out;
	}
	$out['body'] = (string)$body;
	$json = json_decode($out['body'], true);
	$out['json'] = is_array($json) ? $json : null;
	return $out;
}

function brevo_api_error_label($res)
{
	if(!is_array($res) || !is_array($res['json'])) return '';
	$code = isset($res['json']['code']) ? (string)$res['json']['code'] : '';
	$msg = isset($res['json']['message']) ? trim((string)$res['json']['message']) : '';
	if($code === 'account_under_validation')
		return 'Under verification'.($msg !== '' ? ' — '.$msg : '');
	if($res['code'] === 401 || $code === 'unauthorized' || $code === 'invalid_api_key')
		return 'API key rejected'.($msg !== '' ? ' — '.$msg : '');
	return '';
}

/**
 * @return array credits + blocking status for one API key
 */
function brevo_account_health($apiKey, $fromEmail = '')
{
	$out = array(
		'ok' => false,
		'key_preview' => substr((string)$apiKey, 0, 14).'…',
		'credits' => null,
		'plan_type' => '',
		'end_date' => '',
		'email' => '',
		'err' => '',
		'blocking' => false,
		'status_text' => '',
		'relay_enabled' => null,
		'sender_active' => null
	);
	$issues = array();
	$notes = array();

	$acc = brevo_http_get('https://api.brevo.com/v3/account', $apiKey);
	$label = brevo_api_error_label($acc);
	if($label !== '') $issues[] = $label;
	if($acc['err'] !== '')
	{
		$out['err'] = $acc['err'];
	}
	else if($acc['code'] < 200 || $acc['code'] >= 300 || !is_array($acc['json']))
	{
		$msg = is_array($acc['json']) && !empty($acc['json']['message']) ? $acc['json']['message'] : substr($acc['body'], 0, 160);
		$out['err'] = 'HTTP '.$acc['code'].($msg ? ' — '.$msg : '');
		if($acc['code'] === 401) $issues[] = 'API key rejected';
	}
	else
	{
		$json = $acc['json'];
		if(!empty($json['email'])) $out['email'] = $json['email'];
		if(isset($json['relay']['enabled']))
		{
			$out['relay_enabled'] = !empty($json['relay']['enabled']);
			if(!$out['relay_enabled']) $issues[] = 'Transactional email is off';
		}
		$best = null;
		if(!empty($json['plan']) && is_array($json['plan']))
		{
			foreach($json['plan'] as $p)
			{
				if(!is_array($p)) continue;
				$ctype = isset($p['creditsType']) ? $p['creditsType'] : '';
				$ptype = isset($p['type']) ? $p['type'] : '';
				if($ctype !== 'sendLimit' || $ptype === 'sms') continue;
				$cand = array(
					'credits' => isset($p['credits']) ? floatval($p['credits']) : 0,
					'type' => $ptype,
					'end' => !empty($p['endDate']) ? $p['endDate'] : ''
				);
				if($best === null || $ptype === 'free' || $ptype === 'subscription') $best = $cand;
			}
		}
		if($best !== null)
		{
			$out['ok'] = true;
			$out['credits'] = $best['credits'];
			$out['plan_type'] = $best['type'];
			$out['end_date'] = $best['end'];
		}
		else if($out['err'] === '')
		{
			$out['err'] = 'No email sendLimit in account plan';
		}
	}

	$senders = brevo_http_get('https://api.brevo.com/v3/senders', $apiKey);
	$slabel = brevo_api_error_label($senders);
	if($slabel !== '' && !in_array($slabel, $issues, true)) $issues[] = $slabel;
	$fromEmail = strtolower(trim((string)$fromEmail));
	if($senders['code'] >= 200 && $senders['code'] < 300 && is_array($senders['json']) && $fromEmail !== '')
	{
		$found = null;
		$list = isset($senders['json']['senders']) && is_array($senders['json']['senders']) ? $senders['json']['senders'] : array();
		foreach($list as $s)
		{
			if(!is_array($s) || empty($s['email'])) continue;
			if(strtolower(trim($s['email'])) === $fromEmail)
			{
				$found = $s;
				break;
			}
		}
		if($found === null)
			$issues[] = 'From email is not a sender on this account';
		else
		{
			$out['sender_active'] = !empty($found['active']);
			if(!$out['sender_active']) $issues[] = 'Sender is not verified';
		}
	}

	if($fromEmail !== '' && strpos($fromEmail, '@') !== false)
	{
		$domain = substr($fromEmail, strpos($fromEmail, '@') + 1);
		$dom = brevo_http_get('https://api.brevo.com/v3/senders/domains/'.rawurlencode($domain), $apiKey);
		$dlabel = brevo_api_error_label($dom);
		if($dlabel !== '' && !in_array($dlabel, $issues, true)) $issues[] = $dlabel;
		if($dom['code'] >= 200 && $dom['code'] < 300 && is_array($dom['json']))
		{
			if(array_key_exists('verified', $dom['json']) && empty($dom['json']['verified']))
				$notes[] = 'Domain not verified';
			else if(array_key_exists('authenticated', $dom['json']) && empty($dom['json']['authenticated']))
				$notes[] = 'Domain not authenticated';
		}
	}

	$parts = array();
	foreach($issues as $x) if($x !== '' && !in_array($x, $parts, true)) $parts[] = $x;
	foreach($notes as $x) if($x !== '' && !in_array($x, $parts, true)) $parts[] = $x;
	$out['status_text'] = implode(' · ', $parts);
	$out['blocking'] = count($issues) > 0;
	return $out;
}
