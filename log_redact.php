<?php
/**
 * Shared credential redaction for dashboard log APIs (read-only presentation).
 * Never expose API keys, SMTP passwords, tokens, or auth headers to the browser.
 */

function ayb_log_redact_text($text)
{
	$s = (string)$text;
	if ($s === '') return $s;

	// Authorization / Bearer / Basic headers (full remainder of value)
	$s = preg_replace('/(Authorization\s*[:=]\s*)(\S.+)$/im', '$1[REDACTED]', $s);
	$s = preg_replace('/(Bearer\s+)[A-Za-z0-9\-_\.=]+/i', 'Bearer [REDACTED]', $s);
	$s = preg_replace('/(Basic\s+)[A-Za-z0-9\+\/=]+/i', 'Basic [REDACTED]', $s);

	// Common secret key patterns
	$patterns = array(
		'/(api[_-]?key\s*[:=]\s*)([^\s&,;]+)/i',
		'/(admin[_-]?key\s*[:=]\s*)([^\s&,;]+)/i',
		'/(access[_-]?token\s*[:=]\s*)([^\s&,;]+)/i',
		'/(refresh[_-]?token\s*[:=]\s*)([^\s&,;]+)/i',
		'/(unsubscribe[_-]?token\s*[:=]\s*)([^\s&,;]+)/i',
		'/(smtp[_-]?pass(?:word)?\s*[:=]\s*)([^\s&,;]+)/i',
		'/(password\s*[:=]\s*)([^\s&,;]+)/i',
		'/(passwd\s*[:=]\s*)([^\s&,;]+)/i',
		'/(secret\s*[:=]\s*)([^\s&,;]+)/i',
		'/(db[_-]?(?:pass|password)\s*[:=]\s*)([^\s&,;]+)/i',
		'/(KUMOMTA_PASSWORD\s*[:=]\s*)([^\s&,;]+)/i',
		'/(SENDGRID_API_KEY\s*[:=]\s*)([^\s&,;]+)/i',
		'/(SG\.)[A-Za-z0-9_\-\.]{10,}/',
	);
	foreach ($patterns as $re) {
		$s = preg_replace($re, '$1[REDACTED]', $s);
	}

	// login:password style (keep login, redact password when looks like credential pair)
	$s = preg_replace('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}):([^\s,;]{3,})/i', '$1:[REDACTED]', $s);

	// Opaque token-ish query params
	$s = preg_replace('/([?&](?:token|key|password|passwd|secret|auth)=)([^&\s]+)/i', '$1[REDACTED]', $s);

	return $s;
}

function ayb_log_redact_row(array $row)
{
	foreach ($row as $k => $v) {
		if (is_string($v)) $row[$k] = ayb_log_redact_text($v);
		else if (is_array($v)) $row[$k] = ayb_log_redact_row($v);
	}
	return $row;
}
