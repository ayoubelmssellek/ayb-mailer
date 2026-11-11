<?php

function unsub_mask_email($email)
{
	$email = unsub_normalize_email($email);
	$at = strpos($email, '@');
	if ($at === false) return '';
	$local = substr($email, 0, $at);
	$domain = substr($email, $at + 1);
	if ($local === '') return '***@'.$domain;
	return substr($local, 0, 1).'***@'.$domain;
}

function unsub_html_page($title, $bodyHtml)
{
	header('Content-Type: text/html; charset=UTF-8');
	header('Cache-Control: no-store');
	header('X-Robots-Tag: noindex, nofollow');
	echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
	echo '<meta name="robots" content="noindex, nofollow"><title>'.htmlspecialchars($title).'</title>';
	echo '<style>
:root{color-scheme:light}
body{margin:0;font-family:Georgia,"Times New Roman",serif;background:linear-gradient(160deg,#f3f6f8 0%,#e7eef2 45%,#dfe8ec 100%);color:#1f2a30;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
main{width:100%;max-width:34rem;background:rgba(255,255,255,.92);border:1px solid #cfd8dc;padding:2rem;box-shadow:0 10px 30px rgba(31,42,48,.08)}
h1{font-size:1.45rem;margin:0 0 0.75rem}
p{line-height:1.55;margin:0.6rem 0}
button{font:inherit;padding:0.55rem 1.1rem;cursor:pointer}
.ok{color:#1b5e20}.err{color:#8b0000}
</style></head><body><main>'.$bodyHtml.'</main></body></html>';
	exit;
}

function unsub_handle_public_u($token, $method)
{
	$token = unsub_token_normalize_raw($token);
	if ($token === '') {
		unsub_html_page('Invalid link', '<h1 class="err">Invalid link</h1><p>No valid unsubscribe token was provided.</p>');
	}

	if (strtoupper($method) === 'POST') {
		$oneClick = false;
		if (isset($_POST['List-Unsubscribe']) && (string)$_POST['List-Unsubscribe'] === 'One-Click') $oneClick = true;
		$rawBody = file_get_contents('php://input');
		if (!$oneClick && is_string($rawBody) && stripos($rawBody, 'List-Unsubscribe=One-Click') !== false) $oneClick = true;

		$detail = $oneClick ? 'RFC 8058 One-Click' : 'Browser form unsubscribe';
		$consume = unsub_token_consume($token, $detail, 'recipient');
		if (!empty($consume['ok'])) {
			$masked = unsub_mask_email(isset($consume['email']) ? $consume['email'] : '');
			$msg = !empty($consume['already'])
				? '<h1>Already unsubscribed</h1><p>This address is already on our suppression list'.($masked !== '' ? ' ('.$masked.')' : '').'.</p>'
				: '<h1 class="ok">You are unsubscribed</h1><p>You will no longer receive marketing emails'.($masked !== '' ? ' at <strong>'.htmlspecialchars($masked).'</strong>' : '').'.</p>';
			unsub_html_page('Unsubscribed', $msg);
		}
		$code = isset($consume['error']) ? $consume['error'] : 'invalid_token';
		$map = array(
			'token_expired' => 'This unsubscribe link has expired. Please use a newer email or contact the sender.',
			'token_revoked' => 'This unsubscribe link is no longer valid.',
			'invalid_token' => 'This unsubscribe link is invalid.',
			'server_error' => 'A temporary error occurred. Please try again later.',
			'suppress_failed' => 'Could not update preferences. Please try again later.'
		);
		$text = isset($map[$code]) ? $map[$code] : $map['invalid_token'];
		unsub_html_page('Unable to unsubscribe', '<h1 class="err">Unable to unsubscribe</h1><p>'.htmlspecialchars($text).'</p>');
	}

	// GET — confirm only (no mutation)
	$lookup = unsub_token_lookup($token);
	if (!$lookup['ok']) {
		if ($lookup['error'] === 'token_used' && !empty($lookup['row']['email_norm'])) {
			unsub_html_page('Already unsubscribed', '<h1>Already unsubscribed</h1><p>This link was already used.</p>');
		}
		if ($lookup['error'] === 'token_expired' && !empty($lookup['row']['email_norm']) && unsub_suppression_get($lookup['row']['email_norm'])) {
			unsub_html_page('Already unsubscribed', '<h1>Already unsubscribed</h1><p>This address is already unsubscribed.</p>');
		}
		$map = array(
			'token_expired' => 'This unsubscribe link has expired.',
			'token_revoked' => 'This unsubscribe link is no longer valid.',
			'invalid_token' => 'This unsubscribe link is invalid.'
		);
		$err = isset($lookup['error']) ? $lookup['error'] : 'invalid_token';
		$text = isset($map[$err]) ? $map[$err] : $map['invalid_token'];
		unsub_html_page('Invalid link', '<h1 class="err">Invalid link</h1><p>'.htmlspecialchars($text).'</p>');
	}

	$email = $lookup['row']['email_norm'];
	$masked = htmlspecialchars(unsub_mask_email($email));
	$tok = htmlspecialchars($token);
	$body = '<h1>Confirm unsubscribe</h1>';
	$body .= '<p>Stop marketing emails for <strong>'.$masked.'</strong>?</p>';
	$body .= '<form method="post" action=""><input type="hidden" name="t" value="'.$tok.'">';
	$body .= '<p style="margin-top:1.25rem"><button type="submit">Unsubscribe</button></p></form>';
	unsub_html_page('Confirm unsubscribe', $body);
}
