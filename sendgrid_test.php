<?php
/**
 * Optional live SendGrid test.
 * Runs only when SENDGRID_API_KEY and SENDGRID_TEST_TO are set,
 * and ?confirm=1 (CLI: --confirm) is provided.
 *
 * Usage:
 *   php sendgrid_test.php --confirm
 *   Or browser: sendgrid_test.php?confirm=1
 */
@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.DIRECTORY_SEPARATOR.'sendgrid_mail.php';

$confirm = false;
if (php_sapi_name() === 'cli') {
	global $argv;
	foreach ((array)$argv as $a) if ($a === '--confirm') $confirm = true;
} else {
	$confirm = isset($_GET['confirm']) && $_GET['confirm'] === '1';
}

$cfg = sendgrid_config();
if (!$confirm) {
	echo json_encode(array('ok' => false, 'err' => 'Refusing to send without confirm=1 / --confirm'));
	exit;
}
if ($cfg['api_key'] === '') {
	echo json_encode(array('ok' => false, 'status' => 'configuration_error', 'err' => 'SENDGRID_API_KEY not set'));
	exit;
}
if ($cfg['test_to'] === '' || !filter_var($cfg['test_to'], FILTER_VALIDATE_EMAIL)) {
	echo json_encode(array('ok' => false, 'status' => 'configuration_error', 'err' => 'SENDGRID_TEST_TO not set to a valid email'));
	exit;
}

$r = sendEmail(
	$cfg['test_to'],
	'AYB Mailer SendGrid test',
	'<p>This is a SendGrid REST API test from AYB Mailer.</p>',
	'This is a SendGrid REST API test from AYB Mailer.'
);
echo json_encode(array(
	'ok' => !empty($r['ok']),
	'status' => $r['status'],
	'http' => $r['http'],
	'id' => $r['id'],
	'err' => $r['err'],
	'to' => $cfg['test_to'],
	'note' => 'HTTP 202 means accepted for processing, not inbox delivery guarantee'
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
