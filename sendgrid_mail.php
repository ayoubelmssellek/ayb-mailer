<?php
/**
 * Centralized Twilio SendGrid Email API (REST v3) service.
 * Endpoint: https://api.sendgrid.com/v3/mail/send
 * Auth: Bearer API key from SENDGRID_API_KEY (never hardcode).
 *
 * HTTP 202 = accepted for processing (not inbox delivery).
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';
$__ayb_autoload = __DIR__.DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR.'autoload.php';
if (is_file($__ayb_autoload)) require_once $__ayb_autoload;
unset($__ayb_autoload);

function sendgrid_config()
{
	ayb_env_load();
	return array(
		'api_key' => ayb_env('SENDGRID_API_KEY', ''),
		'from_email' => ayb_env('SENDGRID_FROM_EMAIL', 'notify@omnidigitalsuite.com'),
		'from_name' => ayb_env('SENDGRID_FROM_NAME', 'OmniDigitalSuite'),
		'reply_to' => ayb_env('SENDGRID_REPLY_TO', 'notify@omnidigitalsuite.com'),
		'api_url' => ayb_env('SENDGRID_API_URL', 'https://api.sendgrid.com/v3/mail/send'),
		'enabled' => ayb_env_bool('SENDGRID_ENABLED', true),
		'test_to' => ayb_env('SENDGRID_TEST_TO', '')
	);
}

function sendgrid_is_configured()
{
	$c = sendgrid_config();
	return $c['enabled'] && $c['api_key'] !== '' && strpos($c['from_email'], '@') !== false;
}

function sendgrid_config_status()
{
	$c = sendgrid_config();
	$out = array(
		'ok' => false,
		'enabled' => !empty($c['enabled']),
		'has_api_key' => ($c['api_key'] !== ''),
		'key_preview' => $c['api_key'] !== '' ? (substr($c['api_key'], 0, 6).'…') : '',
		'from_email' => $c['from_email'],
		'from_name' => $c['from_name'],
		'reply_to' => $c['reply_to'],
		'api_url' => $c['api_url'],
		'status' => 'configuration_error',
		'err' => ''
	);
	if (!$out['enabled']) {
		$out['err'] = 'SENDGRID_ENABLED is false';
		return $out;
	}
	if (!$out['has_api_key']) {
		$out['err'] = 'SENDGRID_API_KEY is empty — set it in .env';
		return $out;
	}
	if ($out['from_email'] === '' || strpos($out['from_email'], '@') === false) {
		$out['err'] = 'SENDGRID_FROM_EMAIL is invalid';
		return $out;
	}
	$out['ok'] = true;
	$out['status'] = 'ready';
	return $out;
}

function sendgrid_log($level, $message, array $context = array())
{
	// Never log API keys or Authorization headers
	foreach ($context as $k => $v) {
		if (stripos((string)$k, 'key') !== false || stripos((string)$k, 'auth') !== false || stripos((string)$k, 'authorization') !== false) {
			unset($context[$k]);
		}
	}
	$line = '['.gmdate('c').'] sendgrid.'.$level.': '.$message;
	if (!empty($context)) $line .= ' '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$line .= "\n";
	$dir = __DIR__.DIRECTORY_SEPARATOR.'data';
	if (!is_dir($dir)) @mkdir($dir, 0755, true);
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'sendgrid.log', $line, FILE_APPEND);
}

/**
 * @param string $recipient
 * @param string $subject
 * @param string|null $htmlContent
 * @param string|null $textContent
 * @param array $options from_email, from_name, reply_to, cc, bcc, attachments[{content,filename,type,disposition}]
 * @return array{ok:bool,status:string,http:int,id:string,err:string}
 */
function sendEmail($recipient, $subject, $htmlContent = null, $textContent = null, array $options = array())
{
	$out = array(
		'ok' => false,
		'status' => 'failed',
		'http' => 0,
		'id' => '',
		'err' => ''
	);

	$cfg = sendgrid_config();
	if (!$cfg['enabled']) {
		$out['status'] = 'configuration_error';
		$out['err'] = 'SendGrid disabled (SENDGRID_ENABLED)';
		sendgrid_log('error', $out['err']);
		return $out;
	}
	if ($cfg['api_key'] === '') {
		$out['status'] = 'configuration_error';
		$out['err'] = 'Missing SENDGRID_API_KEY';
		sendgrid_log('error', $out['err']);
		return $out;
	}

	$to = trim((string)$recipient);
	$subject = trim((string)$subject);
	if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
		$out['status'] = 'failed';
		$out['err'] = 'Invalid recipient email';
		return $out;
	}
	if ($subject === '') {
		$out['status'] = 'failed';
		$out['err'] = 'Subject is required';
		return $out;
	}
	$html = $htmlContent !== null ? (string)$htmlContent : '';
	$text = $textContent !== null ? (string)$textContent : '';
	if (trim($html) === '' && trim($text) === '') {
		$out['status'] = 'failed';
		$out['err'] = 'Message content is required';
		return $out;
	}

	$fromEmail = !empty($options['from_email']) ? trim((string)$options['from_email']) : $cfg['from_email'];
	$fromName = array_key_exists('from_name', $options) ? (string)$options['from_name'] : $cfg['from_name'];
	$replyTo = !empty($options['reply_to']) ? trim((string)$options['reply_to']) : $cfg['reply_to'];
	if ($fromEmail === '' || strpos($fromEmail, '@') === false) {
		$out['status'] = 'configuration_error';
		$out['err'] = 'Invalid from email';
		return $out;
	}

	// Prefer official SDK when available
	if (class_exists('SendGrid\\Mail\\Mail') && class_exists('SendGrid')) {
		return sendgrid_send_via_sdk($cfg, $to, $subject, $html, $text, $fromEmail, $fromName, $replyTo, $options);
	}

	// Fallback: direct REST (same endpoint) if SDK not loaded
	return sendgrid_send_via_curl($cfg, $to, $subject, $html, $text, $fromEmail, $fromName, $replyTo, $options);
}

function sendgrid_send_via_sdk(array $cfg, $to, $subject, $html, $text, $fromEmail, $fromName, $replyTo, array $options)
{
	$out = array('ok' => false, 'status' => 'failed', 'http' => 0, 'id' => '', 'err' => '');
	try {
		$email = new \SendGrid\Mail\Mail();
		$email->setFrom($fromEmail, $fromName !== '' ? $fromName : null);
		$email->setSubject($subject);
		$email->addTo($to);
		if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
			$email->setReplyTo($replyTo);
		}
		if (trim($text) !== '') $email->addContent('text/plain', $text);
		if (trim($html) !== '') $email->addContent('text/html', $html);
		if (empty($html) && empty($text) === false) {
			// already handled
		}
		if (!empty($options['cc']) && is_array($options['cc'])) {
			foreach ($options['cc'] as $cc) {
				$cc = trim((string)$cc);
				if (filter_var($cc, FILTER_VALIDATE_EMAIL)) $email->addCc($cc);
			}
		}
		if (!empty($options['bcc']) && is_array($options['bcc'])) {
			foreach ($options['bcc'] as $bcc) {
				$bcc = trim((string)$bcc);
				if (filter_var($bcc, FILTER_VALIDATE_EMAIL)) $email->addBcc($bcc);
			}
		}
		if (!empty($options['attachments']) && is_array($options['attachments'])) {
			foreach ($options['attachments'] as $att) {
				if (!is_array($att) || empty($att['content']) || empty($att['filename'])) continue;
				$type = isset($att['type']) ? $att['type'] : 'application/octet-stream';
				$disp = isset($att['disposition']) ? $att['disposition'] : 'attachment';
				$email->addAttachment($att['content'], $type, $att['filename'], $disp);
			}
		}

		$sg = new \SendGrid($cfg['api_key']);
		$response = $sg->send($email);
		$code = intval($response->statusCode());
		$out['http'] = $code;
		$headers = $response->headers();
		if (is_array($headers)) {
			foreach ($headers as $h) {
				if (stripos($h, 'X-Message-Id:') === 0) {
					$out['id'] = trim(substr($h, strlen('X-Message-Id:')));
				}
			}
		}
		$body = (string)$response->body();
		return sendgrid_normalize_http_result($out, $code, $body);
	} catch (Exception $e) {
		$out['status'] = 'failed';
		$out['err'] = 'SendGrid SDK error';
		sendgrid_log('error', 'SDK exception', array('type' => get_class($e), 'message' => $e->getMessage()));
		return $out;
	}
}

function sendgrid_send_via_curl(array $cfg, $to, $subject, $html, $text, $fromEmail, $fromName, $replyTo, array $options)
{
	$out = array('ok' => false, 'status' => 'failed', 'http' => 0, 'id' => '', 'err' => '');
	if (!function_exists('curl_init')) {
		$out['status'] = 'configuration_error';
		$out['err'] = 'PHP curl required';
		return $out;
	}

	$personalization = array('to' => array(array('email' => $to)));
	if (!empty($options['cc']) && is_array($options['cc'])) {
		$ccs = array();
		foreach ($options['cc'] as $cc) {
			$cc = trim((string)$cc);
			if (filter_var($cc, FILTER_VALIDATE_EMAIL)) $ccs[] = array('email' => $cc);
		}
		if ($ccs) $personalization['cc'] = $ccs;
	}
	if (!empty($options['bcc']) && is_array($options['bcc'])) {
		$bccs = array();
		foreach ($options['bcc'] as $bcc) {
			$bcc = trim((string)$bcc);
			if (filter_var($bcc, FILTER_VALIDATE_EMAIL)) $bccs[] = array('email' => $bcc);
		}
		if ($bccs) $personalization['bcc'] = $bccs;
	}

	$content = array();
	if (trim($text) !== '') $content[] = array('type' => 'text/plain', 'value' => $text);
	if (trim($html) !== '') $content[] = array('type' => 'text/html', 'value' => $html);
	if (!$content) $content[] = array('type' => 'text/plain', 'value' => ' ');

	$payload = array(
		'personalizations' => array($personalization),
		'from' => array('email' => $fromEmail, 'name' => $fromName),
		'subject' => $subject,
		'content' => $content
	);
	if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
		$payload['reply_to'] = array('email' => $replyTo);
	}
	if (!empty($options['attachments']) && is_array($options['attachments'])) {
		$atts = array();
		foreach ($options['attachments'] as $att) {
			if (!is_array($att) || empty($att['content']) || empty($att['filename'])) continue;
			$atts[] = array(
				'content' => $att['content'],
				'type' => isset($att['type']) ? $att['type'] : 'application/octet-stream',
				'filename' => $att['filename'],
				'disposition' => isset($att['disposition']) ? $att['disposition'] : 'attachment'
			);
		}
		if ($atts) $payload['attachments'] = $atts;
	}

	$ch = curl_init($cfg['api_url']);
	curl_setopt_array($ch, array(
		CURLOPT_POST => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER => true,
		CURLOPT_HTTPHEADER => array(
			'Authorization: Bearer '.$cfg['api_key'],
			'Content-Type: application/json'
		),
		CURLOPT_POSTFIELDS => json_encode($payload),
		CURLOPT_TIMEOUT => 60,
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_SSL_VERIFYPEER => true
	));
	$raw = curl_exec($ch);
	$code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
	$headerSize = intval(curl_getinfo($ch, CURLINFO_HEADER_SIZE));
	$cerr = curl_error($ch);
	curl_close($ch);
	if ($raw === false || $cerr !== '') {
		$out['err'] = 'curl failed';
		sendgrid_log('error', 'curl transport error', array('detail' => $cerr));
		return $out;
	}
	$hdr = substr($raw, 0, $headerSize);
	$body = substr($raw, $headerSize);
	$out['http'] = $code;
	if (preg_match('/^X-Message-Id:\s*(.+)$/mi', $hdr, $m)) $out['id'] = trim($m[1]);
	return sendgrid_normalize_http_result($out, $code, $body);
}

function sendgrid_normalize_http_result(array $out, $code, $body)
{
	$out['http'] = intval($code);
	if ($code === 202) {
		$out['ok'] = true;
		$out['status'] = 'accepted';
		$out['err'] = '';
		sendgrid_log('info', 'accepted', array('http' => 202, 'id' => $out['id']));
		return $out;
	}
	$msg = '';
	$j = json_decode((string)$body, true);
	if (is_array($j)) {
		if (!empty($j['errors'][0]['message'])) $msg = $j['errors'][0]['message'];
		else if (!empty($j['message'])) $msg = $j['message'];
	}
	if ($msg === '') $msg = substr(preg_replace('/\s+/', ' ', (string)$body), 0, 180);

	if ($code === 401) {
		$out['status'] = 'configuration_error';
		$out['err'] = 'Invalid or missing API key (HTTP 401)';
	} else if ($code === 403) {
		$out['status'] = 'configuration_error';
		$out['err'] = 'Forbidden — check sender auth / permissions (HTTP 403)';
	} else if ($code === 429) {
		$out['status'] = 'failed';
		$out['err'] = 'Rate limit exceeded (HTTP 429)';
	} else if ($code >= 500) {
		$out['status'] = 'failed';
		$out['err'] = 'SendGrid server error (HTTP '.$code.')';
	} else if ($code === 400) {
		$out['status'] = 'failed';
		$out['err'] = 'Invalid request (HTTP 400)'.($msg !== '' ? ' — '.$msg : '');
	} else {
		$out['status'] = 'failed';
		$out['err'] = 'HTTP '.$code.($msg !== '' ? ' — '.$msg : '');
	}
	sendgrid_log('error', 'send failed', array('http' => $code, 'err' => $out['err']));
	return $out;
}

/**
 * Convenience wrapper matching the old mailer send call shape.
 */
function sendgrid_api_send_email($toEmail, $fromEmail, $fromName, $subject, $htmlOrText, $isHtml, $replyTo = '')
{
	$html = $isHtml ? (string)$htmlOrText : null;
	$text = $isHtml ? null : (string)$htmlOrText;
	// Prefer verified SendGrid from-address from env; allow override only if it matches configured domain sender
	$cfg = sendgrid_config();
	$useFrom = $cfg['from_email'];
	$useName = ($fromName !== '' ? $fromName : $cfg['from_name']);
	$useReply = ($replyTo !== '' ? $replyTo : $cfg['reply_to']);
	// If caller passes the verified identity, keep it
	if (strtolower(trim((string)$fromEmail)) === strtolower($cfg['from_email'])) {
		$useFrom = $cfg['from_email'];
	}
	$r = sendEmail($toEmail, $subject, $html, $text, array(
		'from_email' => $useFrom,
		'from_name' => $useName,
		'reply_to' => $useReply
	));
	if (!empty($r['ok'])) {
		// Best-effort local send ledger for analytics (no secrets)
		$lib = __DIR__.DIRECTORY_SEPARATOR.'sendgrid_analytics_lib.php';
		if (is_file($lib)) {
			require_once $lib;
			if (function_exists('sg_record_local_send')) {
				sg_record_local_send($toEmail, $subject, isset($r['id']) ? $r['id'] : '', $useFrom, isset($r['http']) ? $r['http'] : 202);
			}
		}
	}
	return array(
		'ok' => !empty($r['ok']),
		'err' => isset($r['err']) ? $r['err'] : '',
		'id' => isset($r['id']) ? $r['id'] : '',
		'http' => isset($r['http']) ? $r['http'] : 0,
		'status' => isset($r['status']) ? $r['status'] : 'failed'
	);
}
