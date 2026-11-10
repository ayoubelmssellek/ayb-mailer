<?php
/**
 * KumoMTA / VPS SMTP sending helper (third method).
 * Direct PHPMailer SMTP — no SOCKS.
 * SMTP acceptance ≠ inbox delivery.
 */
require_once __DIR__.DIRECTORY_SEPARATOR.'env_load.php';
require_once __DIR__.DIRECTORY_SEPARATOR.'sender_profiles.php';
$__ayb_supp = __DIR__.DIRECTORY_SEPARATOR.'suppression_lib.php';
if (is_file($__ayb_supp)) require_once $__ayb_supp;
unset($__ayb_supp);

function kumomta_config()
{
	ayb_env_load();
	$port = intval(ayb_env('KUMOMTA_PORT', '587'));
	if ($port < 1) $port = 587;
	$enc = strtolower(trim(ayb_env('KUMOMTA_ENCRYPTION', 'tls')));
	if (!in_array($enc, array('tls', 'ssl', 'none'), true)) $enc = 'tls';
	return array(
		'enabled' => ayb_env_bool('KUMOMTA_ENABLED', true),
		'host' => ayb_env('KUMOMTA_HOST', 'smtp1.omnidigitalsuite.com'),
		'port' => $port,
		'encryption' => $enc,
		'username' => ayb_env('KUMOMTA_USERNAME', ''),
		'password' => ayb_env('KUMOMTA_PASSWORD', ''),
		'timeout' => max(5, intval(ayb_env('KUMOMTA_TIMEOUT', '30'))),
		'verify_peer' => ayb_env_bool('KUMOMTA_VERIFY_PEER', true),
		'default_from' => ayb_env('KUMOMTA_DEFAULT_FROM', 'mail@omnidigitalsuite.com'),
		'default_from_name' => ayb_env('KUMOMTA_DEFAULT_FROM_NAME', 'OmniDigitalSuite'),
		'default_reply_to' => ayb_env('KUMOMTA_DEFAULT_REPLY_TO', 'mail@omnidigitalsuite.com'),
		'test_to' => ayb_env('KUMOMTA_TEST_TO', '')
	);
}

function kumomta_is_configured()
{
	$c = kumomta_config();
	return $c['enabled']
		&& $c['host'] !== ''
		&& $c['username'] !== ''
		&& $c['password'] !== ''
		&& strpos($c['default_from'], '@') !== false;
}

function kumomta_config_status()
{
	$c = kumomta_config();
	$out = array(
		'ok' => false,
		'enabled' => !empty($c['enabled']),
		'host' => $c['host'],
		'port' => $c['port'],
		'encryption' => $c['encryption'],
		'has_username' => ($c['username'] !== ''),
		'has_password' => ($c['password'] !== ''),
		'username_preview' => $c['username'] !== '' ? (substr($c['username'], 0, 3).'…') : '',
		'default_from' => $c['default_from'],
		'default_from_name' => $c['default_from_name'],
		'default_reply_to' => $c['default_reply_to'],
		'verify_peer' => !empty($c['verify_peer']),
		'timeout' => $c['timeout'],
		'socks' => false,
		'status' => 'configuration_error',
		'err' => ''
	);
	if (!$out['enabled']) {
		$out['err'] = 'KUMOMTA_ENABLED is false';
		return $out;
	}
	if ($out['host'] === '') {
		$out['err'] = 'KUMOMTA_HOST is empty';
		return $out;
	}
	if (!$out['has_username'] || !$out['has_password']) {
		$out['err'] = 'KUMOMTA_USERNAME / KUMOMTA_PASSWORD missing in .env';
		return $out;
	}
	if ($out['default_from'] === '' || strpos($out['default_from'], '@') === false) {
		$out['err'] = 'KUMOMTA_DEFAULT_FROM is invalid';
		return $out;
	}
	$out['ok'] = true;
	$out['status'] = 'ready';
	return $out;
}

function kumomta_log($level, $message, array $context = array())
{
	foreach ($context as $k => $v) {
		$lk = strtolower((string)$k);
		if (strpos($lk, 'pass') !== false || strpos($lk, 'key') !== false || strpos($lk, 'auth') !== false) {
			unset($context[$k]);
		}
	}
	$line = '['.gmdate('c').'] kumomta.'.$level.': '.$message;
	if (!empty($context)) $line .= ' '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	$line .= "\n";
	$dir = __DIR__.DIRECTORY_SEPARATOR.'data';
	if (!is_dir($dir)) @mkdir($dir, 0755, true);
	@file_put_contents($dir.DIRECTORY_SEPARATOR.'kumomta.log', $line, FILE_APPEND);
}

/**
 * Resolve From / name / Reply-To from profile id + optional overrides.
 */
function kumomta_resolve_identity(array $options = array())
{
	$cfg = kumomta_config();
	$profile = null;
	if (!empty($options['profile_id'])) {
		$profile = sender_profile_get($options['profile_id']);
	}
	$from = !empty($options['from_email']) ? trim((string)$options['from_email']) : '';
	$name = array_key_exists('from_name', $options) ? (string)$options['from_name'] : null;
	$reply = !empty($options['reply_to']) ? trim((string)$options['reply_to']) : '';

	if ($from === '' && is_array($profile) && !empty($profile['from_email'])) {
		$from = trim((string)$profile['from_email']);
	}
	if ($from === '') $from = $cfg['default_from'];

	if ($name === null) {
		if (is_array($profile) && isset($profile['from_name'])) $name = (string)$profile['from_name'];
		else $name = $cfg['default_from_name'];
	}

	if ($reply === '' && is_array($profile) && !empty($profile['reply_to'])) {
		$reply = trim((string)$profile['reply_to']);
	}
	if ($reply === '') $reply = $cfg['default_reply_to'];
	if ($reply === '') $reply = $from;

	return array(
		'profile_id' => is_array($profile) && !empty($profile['id']) ? (string)$profile['id'] : '',
		'from_email' => $from,
		'from_name' => $name,
		'reply_to' => $reply
	);
}

/**
 * Configure an existing PHPMailer instance for KumoMTA (no SOCKS, TLS verify on by default).
 */
function kumomta_configure_mailer($mail, array $identity = array())
{
	$cfg = kumomta_config();
	if (!is_object($mail)) return false;

	$GLOBALS['socks_proxy'] = '';
	$mail->isSMTP();
	$mail->Host = $cfg['host'];
	$mail->Port = intval($cfg['port']);
	$mail->SMTPAuth = true;
	$mail->Username = $cfg['username'];
	$mail->Password = $cfg['password'];
	$mail->Timeout = $cfg['timeout'];
	$mail->SMTPKeepAlive = false;
	$mail->SMTPAutoTLS = ($cfg['encryption'] !== 'none');
	$mail->CharSet = 'UTF-8';

	if ($cfg['encryption'] === 'ssl') $mail->SMTPSecure = 'ssl';
	else if ($cfg['encryption'] === 'tls') $mail->SMTPSecure = 'tls';
	else $mail->SMTPSecure = '';

	$verify = !empty($cfg['verify_peer']);
	$mail->SMTPOptions = array(
		'ssl' => array(
			'verify_peer' => $verify,
			'verify_peer_name' => $verify,
			'allow_self_signed' => !$verify,
			'peer_name' => $cfg['host'],
			'CN_match' => $cfg['host'],
			'SNI_enabled' => true
		)
	);

	$from = !empty($identity['from_email']) ? $identity['from_email'] : $cfg['default_from'];
	$name = isset($identity['from_name']) ? $identity['from_name'] : $cfg['default_from_name'];
	$reply = !empty($identity['reply_to']) ? $identity['reply_to'] : $cfg['default_reply_to'];

	$mail->From = $from;
	$mail->Sender = $from;
	$mail->FromName = $name;
	if (method_exists($mail, 'clearReplyTos')) $mail->clearReplyTos();
	if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL) && method_exists($mail, 'addReplyTo')) {
		$mail->addReplyTo($reply);
	}
	return true;
}

/**
 * Send one message via KumoMTA using PHPMailer (must already be loaded).
 *
 * @return array{ok:bool,status:string,err:string,msgid:string,smtp_reply:string,smtp_code:string,profile_id:string,from_email:string}
 */
function kumomta_send_email($toEmail, $subject, $htmlOrText, $isHtml = true, array $options = array())
{
	$out = array(
		'ok' => false,
		'status' => 'failed',
		'err' => '',
		'msgid' => '',
		'smtp_reply' => '',
		'smtp_code' => '',
		'profile_id' => '',
		'from_email' => ''
	);

	$to = trim((string)$toEmail);
	$subject = trim((string)$subject);
	if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
		$out['err'] = 'Invalid recipient email';
		return $out;
	}
	if (function_exists('suppression_blocked_send_result')) {
		$blocked = suppression_blocked_send_result($to, array('lane' => 'kumomta'));
		if (is_array($blocked)) {
			$out['status'] = 'excluded';
			$out['err'] = isset($blocked['err']) ? $blocked['err'] : 'Suppressed';
			kumomta_log('warn', 'excluded', array('to' => $to, 'reason' => isset($blocked['reason']) ? $blocked['reason'] : ''));
			return $out;
		}
	}
	if ($subject === '') {
		$out['err'] = 'Subject is required';
		return $out;
	}
	$body = (string)$htmlOrText;
	if (trim($body) === '') {
		$out['err'] = 'Message content is required';
		return $out;
	}

	$st = kumomta_config_status();
	if (empty($st['ok'])) {
		$out['status'] = 'configuration_error';
		$out['err'] = $st['err'];
		kumomta_log('error', $out['err']);
		return $out;
	}
	if (!class_exists('PHPMailer')) {
		$out['status'] = 'configuration_error';
		$out['err'] = 'PHPMailer class not loaded';
		return $out;
	}

	$identity = kumomta_resolve_identity($options);
	$out['profile_id'] = $identity['profile_id'];
	$out['from_email'] = $identity['from_email'];

	try {
		$mail = new PHPMailer(true);
		kumomta_configure_mailer($mail, $identity);
		$mail->clearAllRecipients();
		$mail->addAddress($to);
		$mail->Subject = $subject;
		$mail->isHTML(!empty($isHtml));
		$mail->Body = $body;
		if (!empty($isHtml)) {
			$mail->AltBody = trim(strip_tags($body)) !== '' ? strip_tags($body) : ' ';
		}
		if (function_exists('suppression_list_unsubscribe_headers') && empty($options['skip_list_unsubscribe'])) {
			$hdrs = suppression_list_unsubscribe_headers($to);
			if (!empty($hdrs['list_unsubscribe']) && method_exists($mail, 'addCustomHeader')) {
				$mail->addCustomHeader('List-Unsubscribe', $hdrs['list_unsubscribe']);
				if (!empty($hdrs['list_unsubscribe_post'])) {
					$mail->addCustomHeader('List-Unsubscribe-Post', $hdrs['list_unsubscribe_post']);
				}
			}
		}

		$ok = $mail->send();
		if (method_exists($mail, 'getLastMessageID')) {
			$out['msgid'] = (string)$mail->getLastMessageID();
		}
		if (method_exists($mail, 'getSMTPInstance')) {
			$smtp = $mail->getSMTPInstance();
			if ($smtp && method_exists($smtp, 'getLastReply')) {
				$out['smtp_reply'] = trim((string)$smtp->getLastReply());
				if (preg_match('/^(\d{3})\b/', $out['smtp_reply'], $m)) $out['smtp_code'] = $m[1];
			}
		}
		if ($ok) {
			$out['ok'] = true;
			$out['status'] = 'accepted';
			kumomta_log('info', 'accepted', array(
				'to' => $to,
				'from' => $identity['from_email'],
				'profile' => $identity['profile_id'],
				'msgid' => $out['msgid'],
				'smtp_code' => $out['smtp_code']
			));
			return $out;
		}
		$out['err'] = $mail->ErrorInfo ? $mail->ErrorInfo : 'SMTP send failed';
		if (function_exists('suppression_maybe_from_smtp_failure')) {
			suppression_maybe_from_smtp_failure($to, $out['smtp_code'], $out['smtp_reply'] !== '' ? $out['smtp_reply'] : $out['err'], 'kumomta');
		}
		kumomta_log('error', 'send failed', array('to' => $to, 'err' => $out['err']));
		return $out;
	} catch (Exception $e) {
		$out['err'] = 'KumoMTA send exception';
		if (function_exists('suppression_maybe_from_smtp_failure')) {
			suppression_maybe_from_smtp_failure($to, '', $e->getMessage(), 'kumomta');
		}
		kumomta_log('error', 'exception', array('type' => get_class($e), 'message' => $e->getMessage()));
		return $out;
	}
}

/**
 * Lightweight SMTP diagnostics (no campaign send). Never logs password.
 *
 * @return array
 */
function kumomta_diag_connect($doAuth = true)
{
	$cfg = kumomta_config();
	$out = array(
		'ok' => false,
		'steps' => array(),
		'err' => '',
		'host' => $cfg['host'],
		'port' => $cfg['port'],
		'encryption' => $cfg['encryption'],
		'verify_peer' => !empty($cfg['verify_peer'])
	);
	$host = $cfg['host'];
	$port = intval($cfg['port']);
	$timeout = min(20, max(5, intval($cfg['timeout'])));

	$remote = ($cfg['encryption'] === 'ssl' ? 'ssl://' : 'tcp://').$host.':'.$port;
	$ctx = stream_context_create(array(
		'ssl' => array(
			'verify_peer' => !empty($cfg['verify_peer']),
			'verify_peer_name' => !empty($cfg['verify_peer']),
			'allow_self_signed' => empty($cfg['verify_peer']),
			'peer_name' => $host,
			'SNI_enabled' => true
		)
	));
	$errno = 0; $errstr = '';
	$fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
	if (!is_resource($fp)) {
		$out['err'] = 'Connect failed: '.$errstr.' ('.$errno.')';
		$out['steps'][] = array('step' => 'connect', 'ok' => false, 'detail' => $out['err']);
		return $out;
	}
	stream_set_timeout($fp, $timeout);
	$out['steps'][] = array('step' => 'connect', 'ok' => true, 'detail' => $remote);

	$banner = @fgets($fp, 512);
	$bannerOk = ($banner && strpos($banner, '220') === 0);
	$out['steps'][] = array('step' => 'banner', 'ok' => $bannerOk, 'detail' => trim((string)$banner));
	if (!$bannerOk) {
		fclose($fp);
		$out['err'] = 'Bad SMTP banner';
		return $out;
	}

	fwrite($fp, "EHLO ayb-mailer\r\n");
	$ehlo = '';
	while ($line = @fgets($fp, 512)) {
		$ehlo .= $line;
		if (isset($line[3]) && $line[3] === ' ') break;
	}
	$ehloOk = (strpos($ehlo, '250') !== false);
	$out['steps'][] = array('step' => 'ehlo', 'ok' => $ehloOk, 'detail' => trim(substr(preg_replace('/\s+/', ' ', $ehlo), 0, 180)));
	if (!$ehloOk) {
		fclose($fp);
		$out['err'] = 'EHLO failed';
		return $out;
	}

	if ($cfg['encryption'] === 'tls' && $port !== 465) {
		fwrite($fp, "STARTTLS\r\n");
		$tlsResp = @fgets($fp, 512);
		$tlsCmdOk = ($tlsResp && strpos($tlsResp, '220') === 0);
		$out['steps'][] = array('step' => 'starttls_cmd', 'ok' => $tlsCmdOk, 'detail' => trim((string)$tlsResp));
		if (!$tlsCmdOk) {
			fclose($fp);
			$out['err'] = 'STARTTLS not accepted';
			return $out;
		}
		$crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
		if (!$crypto) $crypto = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
		$out['steps'][] = array('step' => 'tls_handshake', 'ok' => (bool)$crypto, 'detail' => $crypto ? 'TLS enabled' : 'TLS handshake failed');
		if (!$crypto) {
			fclose($fp);
			$out['err'] = 'TLS handshake failed';
			return $out;
		}
		fwrite($fp, "EHLO ayb-mailer\r\n");
		$ehlo2 = '';
		while ($line = @fgets($fp, 512)) {
			$ehlo2 .= $line;
			if (isset($line[3]) && $line[3] === ' ') break;
		}
		$out['steps'][] = array('step' => 'ehlo_after_tls', 'ok' => (strpos($ehlo2, '250') !== false), 'detail' => trim(substr(preg_replace('/\s+/', ' ', $ehlo2), 0, 180)));
	}

	if ($doAuth) {
		if ($cfg['username'] === '' || $cfg['password'] === '') {
			fclose($fp);
			$out['err'] = 'Missing username/password for AUTH test';
			$out['steps'][] = array('step' => 'auth', 'ok' => false, 'detail' => $out['err']);
			return $out;
		}
		fwrite($fp, "AUTH LOGIN\r\n");
		$a1 = @fgets($fp, 512);
		if (!$a1 || strpos($a1, '334') !== 0) {
			fclose($fp);
			$out['err'] = 'AUTH LOGIN not accepted';
			$out['steps'][] = array('step' => 'auth', 'ok' => false, 'detail' => trim((string)$a1));
			return $out;
		}
		fwrite($fp, base64_encode($cfg['username'])."\r\n");
		$a2 = @fgets($fp, 512);
		if (!$a2 || strpos($a2, '334') !== 0) {
			fclose($fp);
			$out['err'] = 'AUTH username rejected';
			$out['steps'][] = array('step' => 'auth', 'ok' => false, 'detail' => trim((string)$a2));
			return $out;
		}
		fwrite($fp, base64_encode($cfg['password'])."\r\n");
		$a3 = @fgets($fp, 512);
		$authOk = ($a3 && strpos($a3, '235') === 0);
		$out['steps'][] = array('step' => 'auth', 'ok' => $authOk, 'detail' => $authOk ? '235 Authentication succeeded' : trim((string)$a3));
		if (!$authOk) {
			fclose($fp);
			$out['err'] = 'Authentication failed';
			return $out;
		}
	}

	fwrite($fp, "QUIT\r\n");
	@fgets($fp, 512);
	fclose($fp);
	$out['ok'] = true;
	$out['err'] = '';
	kumomta_log('info', 'diag ok', array('host' => $host, 'port' => $port, 'auth' => (bool)$doAuth));
	return $out;
}
